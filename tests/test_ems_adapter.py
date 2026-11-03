"""Exercise configuration mapping and the actual simulation write boundary."""
import ast
from copy import deepcopy
from datetime import datetime
import json
from pathlib import Path
import re
import sys
import unittest
import tempfile
from unittest.mock import Mock, patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'python'))
from automation_config import normalize_automation_config
from ems_policy import build_payload, apply_policy
import ems_policy


class AdapterTests(unittest.TestCase):
    def setUp(self):
        self.site = {
            'Site_Info': {'meter_main': 'MAIN', 'device_main': 'TEST'},
            'M1_Runtime': {'automation_enabled': True, 'simulation_only': True},
            'EMS_Config': {'BESS': {'allow_control': True}, 'EV': {'sid': 'TEST', 'allow_control': True}},
            'EMS_Strategy': {'peak_shaving': {'enable': True, 'trigger_high_grid_kw': 600, 'deadzone': 30}},
            'Device_Control': {'BAT': {'role': 'battery', 'control_enabled': True}},
            'Inverter': {'device': {}}, 'Holidays': ['2026-11-04'],
        }
        self.config = normalize_automation_config(self.site)
        self.config['_dry_run'] = True
        self.data = {'MAIN': {'p_t': 900, 'pf_t': .98}, 'BAT': {'p_t': 10, 'soc': 60, 'p_max': 10}}
        self.ev = {'total': 150, 'totalLimit_kW': 150}

    def test_calendar_and_permissions(self):
        with patch('ems_policy.load_tou_schedule', return_value={}):
            payload, _ = build_payload(self.data, self.config, datetime(2026,11,4,12), self.ev)
            self.assertEqual(payload['calendar'], {'holiday': True, 'offpeak': True})
            self.config['devices']['BAT']['control_enabled'] = False
            payload, _ = build_payload(self.data, self.config, datetime(2026,11,3,12), self.ev)
            self.assertFalse(payload['config']['BESS']['allow_control'])

    def test_missing_telemetry_is_not_zero(self):
        del self.data['MAIN']['p_t']
        with self.assertRaises(KeyError):
            build_payload(self.data, self.config, ev=self.ev)

    def test_simulation_ev_and_register_gate(self):
        transport = Mock()
        with patch('ems_policy.publish_output'), patch('ems_policy.ev_reading', return_value=self.ev), patch('ems_policy.load_tou_schedule', return_value={}), patch('builtins.print'), patch.dict(sys.modules, {'requests': Mock(get=Mock(side_effect=AssertionError('Live EV call'))) }):
            result = apply_policy(self.data, self.config, transport, {}, datetime(2026,11,3,12))
        self.assertEqual(result['cmd_ev_action'], 'limit')
        self.assertTrue(result['dry_run'])
        transport.assert_not_called()

    def test_real_write_limit_simulation_boundary(self):
        # Load the actual nested write function without hardware-dependent imports.
        source = Path(__file__).resolve().parents[1] / 'python' / 'py_function_custom1.py'
        tree = ast.parse(source.read_text(encoding='utf-8-sig'))
        fn = next(node for node in ast.walk(tree) if isinstance(node, ast.FunctionDef) and node.name == 'write_limit')
        device = Mock()
        device.main_m1_ini.tcpconfig.side_effect = AssertionError('Live Modbus call')
        ns = {'_automation_last_commands': {}, 'dry_run': True, 'self': device, 're': re,
              'dvl_arr': {'BAT': {}}, 'p_arr': self.data, 'devices_config': self.config['devices'],
              'main_meter': 'MAIN', 'p_main': 900}
        exec(compile(ast.Module(body=[fn], type_ignores=[]), '<actual-write-limit>', 'exec'), ns)
        with patch('builtins.print'):
            self.assertTrue(ns['write_limit']('BAT', (1, {'multiply': 1}), 100, 10, force=True))
        device.main_m1_ini.tcpconfig.assert_not_called()

    def test_live_output_contains_only_successful_m1_commands(self):
        output = {'trace': '[FINAL] source=TOU', 'result': {
            'target_pt_bess': 40, 'target_qt_bess': 0, 'target_pt_max': 100,
            'target_ev_limit': None, 'cmd_pt_bess': 40, 'cmd_qt_bess': None,
            'cmd_pt_max': None, 'cmd_ev_action': None, 'cmd_ev_limit': None,
            'cmd_inv_list': {}, 'control_source': 'TOU', 'bess_allow_control': True,
            'recovery_phase': 'NONE', 'solar_data_ok': True, 'dry_run': False,
        }}
        payload = {'input': {'TS': 1, 'inv': {'p_out': {}, 'p_max': {}}}}
        proposed = [{'source':'m1_automation','device':'BAT','role':'battery','parameter':'p_max','value':40}]
        actual = [dict(proposed[0], status='success')]
        with tempfile.TemporaryDirectory() as tmp, patch.object(ems_policy, 'STATUS_FILE', Path(tmp) / 'live.json'):
            ems_policy.publish_output(payload, output, False, True, proposed, actual, [])
            saved = json.loads(ems_policy.STATUS_FILE.read_text(encoding='utf-8'))
        self.assertEqual(saved['source'], 'm1_automation')
        self.assertEqual(saved['actual_commands'], actual)
        self.assertEqual(saved['result']['cmd_pt_bess'], 40)
        self.assertNotIn('mqtt', json.dumps(saved['actual_commands']).lower())


if __name__ == '__main__':
    unittest.main()
