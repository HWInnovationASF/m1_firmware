"""Compare pure production engine against the supplied PHP with stub transports."""
import argparse
from copy import deepcopy
import json
from pathlib import Path
import random
import subprocess
import sys
import tempfile
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'python'))
from ems_policy import evaluate, php_round


def main(reference):
    source = Path(reference).read_text(encoding='utf-8-sig')
    helpers = source[source.index('function parse_hour_to_decimal'):source.index('//$config_file')]
    function = source[source.index('function find_pt_bess'):source.index('// Main input mapping')]
    oracle = '''<?php
date_default_timezone_set('Asia/Bangkok');
function is_holiday($date) { return $GLOBALS['payload']['calendar']['holiday']; }
function is_offpeak($date) { return $GLOBALS['payload']['calendar']['offpeak']; }
function send_mqtt($device, $sn, $parameter, $value) { $GLOBALS['sent'][]=[$sn,$parameter,$value]; }
function read_url($url) { $GLOBALS['ev_sent'][]=$url; }
function write_file(...$args) {}
function write_file_json(...$args) {}
''' + helpers + function + '''
$payload=json_decode(stream_get_contents(STDIN),true);
$EMS_DRY_RUN=0; $write_log=false; $sent=[]; $ev_sent=[];
ob_start(); $result=find_pt_bess($payload['input'],$payload['config']); ob_end_clean();
echo json_encode(['result'=>$result,'sent'=>$sent,'ev_sent'=>$ev_sent]);
'''
    base = {
        'input': {'pt_main': 100, 'qt_main': 0, 'pt_bess': 10, 'qt_bess': 0,
                  'bess_soc': 60, 'ev_charger_total': 0, 'ev_charger_limit': 0,
                  'TS': 1793678400, 'device_SN': 'TEST',
                  'inv': {'p_max': {'A': 50, 'B': 100}, 'p_out': {'A': 45, 'B': 70}}},
        'config': {'INV_RATED': {'A': 100, 'B': 150},
                   'BESS': {'allow_control': True, 'kwh': 240, 'p_max_cha': -100,
                            'p_max_dis': 100, 'min_soc': 10, 'max_soc': 95},
                   'TOU': {'on': [{'b_hour': 0, 'e_hour': 24, 'min_soc': 10, 'pt_bess': '+d'}],
                           'off': [{'b_hour': 0, 'e_hour': 24, 'max_soc': 95, 'pt_bess': '-d'}]},
                   'Q_CONTROL': {'enable': True, 'use_q_comp': True, 'qt_main_max': 360, 'qt_main_min': 330},
                   'EMS_Strategy': {'anti_backflow': {'enable': True, 'trigger_low_grid_kw': 10, 'deadzone': 30, 'emergency_curtail_kw': -10},
                                    'peak_shaving': {'enable': True, 'trigger_high_grid_kw': 600, 'deadzone': 30}}},
        'calendar': {'holiday': False, 'offpeak': False},
    }
    cases = []
    for field, values in {'pt_main': [-50,-10,0,10,25,40,570,585,600,650,900],
                          'pt_bess': [-100,-2.9,0,2.9,100],
                          'bess_soc': [0,10,94,95,100,101],
                          'qt_main': [330,360,361,500]}.items():
        for value in values:
            item = deepcopy(base); item['input'][field] = value; cases.append(item)
    rng = random.Random(20261103)
    for _ in range(160):
        item = deepcopy(base)
        item['input'].update(pt_main=rng.uniform(-120,800), pt_bess=rng.uniform(-100,100),
                             bess_soc=rng.choice([5,10,50,94,95,99]), qt_main=rng.uniform(0,500),
                             qt_bess=rng.uniform(-100,100), ev_charger_total=rng.choice([0,60,150]),
                             ev_charger_limit=rng.choice([0,60,100]))
        item['calendar']['offpeak'] = rng.choice([True,False])
        item['calendar']['holiday'] = rng.choice([True,False])
        item['config']['BESS']['allow_control'] = rng.choice([True,False])
        item['config']['TOU']['on'][0]['pt_bess'] = rng.choice(['+d','-d',0,-25,35])
        item['input']['inv']['p_max']['A'] = rng.choice([0,10,50,100])
        item['input']['inv']['p_out']['A'] = rng.choice([0,20,55,100])
        cases.append(item)
    with tempfile.TemporaryDirectory(prefix='m1-ems-parity-') as tmp:
        path = Path(tmp) / 'oracle.php'; path.write_text(oracle, encoding='utf-8')
        for index, case in enumerate(cases):
            p = subprocess.run(['php',str(path)], input=json.dumps(case), text=True,
                               encoding='utf-8', capture_output=True, check=True)
            expected = json.loads(p.stdout)
            if expected['result']['cmd_inv_list'] == []:
                expected['result']['cmd_inv_list'] = {}
            actual = evaluate(case)['result']
            for key, value in expected['result'].items():
                if key not in ('dry_run','cmd_pt_bess'):
                    assert actual[key] == value, (index,key,actual[key],value)
            sent = []
            if actual['cmd_pt_bess'] is not None:
                sent.append(['IN130001','p_max',php_round(actual['cmd_pt_bess'])])
            if actual['cmd_qt_bess'] is not None:
                sent.append(['IN130001','q_max',actual['cmd_qt_bess']])
            sent.extend([sn,'p_max',val] for sn,val in actual['cmd_inv_list'].items())
            assert sent == expected['sent'], (index,sent,expected['sent'])
    print(f'PASS: {len(cases)} PHP parity cases, including captured outgoing commands')


if __name__ == '__main__':
    parser=argparse.ArgumentParser(); parser.add_argument('--reference',default=str(Path(__file__).with_name('fixtures') / 'ems_reference_20261103.php'))
    main(parser.parse_args().reference)
