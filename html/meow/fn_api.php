<?php
/**
 * fn_api.php — unified CRUD endpoint
 *
 * รวม endpoint เดิม: add_device.php, del_device.php, add_cml.php, del_cml.php,
 * add_rgl.php, del_rgl.php, add_pm_task.php, delete_pm_task.php, complete_pm_task.php
 *
 * เรียกด้วย ?action=<name> โดย input อื่น ๆ (GET params + JSON body) เหมือน endpoint เดิมทุกอย่าง
 *   device_add, device_del, cml_add, cml_del, rgl_add, rgl_del,
 *   pm_add, pm_delete, pm_complete
 */

require_once __DIR__ . '/session_bootstrap.php';
date_default_timezone_set("Asia/Bangkok");

// ---------- shared helpers ----------

function write_file($src, $file_text, $mode_write = "w") {
    if ($mode_write == "a") {
        $written = file_put_contents($src, $file_text, FILE_APPEND);
    } else {
        $written = file_put_contents($src, $file_text);
    }
    if ($written === false) return false;
    @chmod($src, 0777);
    return true;
}

function read_json_file($src) {
    $txt = file_exists($src) ? file_get_contents($src) : "";
    $arr = $txt != "" ? json_decode($txt, true) : array();
    return is_array($arr) ? $arr : array();
}

function body_json() {
    $arr = json_decode(file_get_contents("php://input"), true);
    return is_array($arr) ? $arr : array();
}

function require_pm_admin() {
    $type = isset($_SESSION['user_type']) ? strtolower((string)$_SESSION['user_type']) : '';
    if ($type !== 'admin' && $type !== 'superadmin') {
        http_response_code(403);
        echo json_encode(array("status" => "error", "message" => "Admin or Superadmin permission required"));
        exit;
    }
}

// sanitize attachment list — only files stored by upload_pm_file.php
function pm_clean_attachments($raw) {
    $out = array();
    if (is_array($raw)) {
        foreach ($raw as $att) {
            if (!is_array($att)) continue;
            $p = isset($att['path']) ? $att['path'] : '';
            if (strpos($p, './log_device/pm_files/') !== 0) continue;
            if (strpos($p, '..') !== false) continue;
            $out[] = array("name" => isset($att['name']) ? $att['name'] : basename($p), "path" => $p);
        }
    }
    return $out;
}

// ---------- actions: device (config/dvl.json) ----------

function act_device_add() {
    header('Content-Type: application/json; charset=utf-8');
    $meter_SN = isset($_GET['meter_SN']) ? $_GET['meter_SN'] : '';
    $ws_arr = body_json();
    unset($ws_arr["meter_SN"]);

    if (strtolower(isset($ws_arr['mode']) ? (string)$ws_arr['mode'] : '') === 'bacnet') {
        $raw_port = isset($ws_arr['port']) && $ws_arr['port'] !== '' ? $ws_arr['port'] : 47808;
        $port = filter_var($raw_port, FILTER_VALIDATE_INT, array(
            'options' => array('min_range' => 1, 'max_range' => 65535)
        ));
        if ($port === false) {
            http_response_code(422);
            echo json_encode(array('status' => 'error', 'message' => 'BACnet port must be between 1 and 65535'));
            return;
        }
        $ws_arr['port'] = (string)$port;
    }

    $dvl_arr = read_json_file('./config/dvl.json');
    $dvl_arr[$meter_SN] = $ws_arr;
    if (!write_file('./config/dvl.json', json_encode($dvl_arr, 256), "w")) {
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'message' => 'Unable to save device configuration'));
        return;
    }
    echo json_encode(array('status' => 'ok', 'meter_SN' => $meter_SN, 'port' => isset($ws_arr['port']) ? $ws_arr['port'] : null));
}

function act_device_del() {
    $meter_SN = isset($_GET['meter_SN']) ? $_GET['meter_SN'] : '';
    $dvl_arr = read_json_file('./config/dvl.json');
    unset($dvl_arr[$meter_SN]);
    write_file('./config/dvl.json', json_encode($dvl_arr, 256), "w");

    // ลบ CML ทุกตัวที่อ้างถึง device นี้ด้วย
    $cml_arr = read_json_file('./config/cml.json');
    $found = false;
    foreach ($cml_arr as $cmd_id => $cml_data) {
        foreach ($cml_data as $read_type => $registers) {
            if (isset($registers[$meter_SN])) {
                echo "Found $meter_SN in device $cmd_id under $read_type\n";
                $found = true;
                unset($cml_arr[$cmd_id]);
            }
        }
    }
    write_file('./config/cml.json', json_encode($cml_arr, 256), "w");
    if (!$found) {
        echo "$meter_SN not found in any device";
    }
}

function act_device_del_bulk() {
    header('Content-Type: application/json; charset=utf-8');
    $input = body_json();
    $requested = isset($input['meter_SN']) && is_array($input['meter_SN']) ? $input['meter_SN'] : array();
    $meterSNs = array_values(array_unique(array_filter(array_map(function ($value) {
        $value = trim((string)$value);
        return preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $value) ? $value : '';
    }, $requested))));
    if (!$meterSNs) {
        http_response_code(422);
        echo json_encode(array('status'=>'error', 'message'=>'No valid devices selected'));
        return;
    }
    $selected = array_fill_keys($meterSNs, true);
    $dvl_arr = read_json_file('./config/dvl.json');
    $deleted = array();
    foreach ($meterSNs as $meterSN) {
        if (array_key_exists($meterSN, $dvl_arr)) { unset($dvl_arr[$meterSN]); $deleted[] = $meterSN; }
    }
    $cml_arr = read_json_file('./config/cml.json');
    $deletedCommands = 0;
    foreach ($cml_arr as $cmd_id => $cml_data) {
        if (!is_array($cml_data)) continue;
        foreach ($cml_data as $registers) {
            if (is_array($registers) && array_intersect_key($registers, $selected)) {
                unset($cml_arr[$cmd_id]); $deletedCommands++; break;
            }
        }
    }
    if (!write_file('./config/dvl.json', json_encode($dvl_arr, JSON_UNESCAPED_UNICODE), "w")
        || !write_file('./config/cml.json', json_encode($cml_arr, JSON_UNESCAPED_UNICODE), "w")) {
        http_response_code(500);
        echo json_encode(array('status'=>'error', 'message'=>'Unable to update configuration'));
        return;
    }
    echo json_encode(array('status'=>'ok', 'deleted'=>$deleted, 'deleted_count'=>count($deleted), 'deleted_commands'=>$deletedCommands), JSON_UNESCAPED_UNICODE);
}

// ---------- actions: CML (config/cml.json) ----------

function act_cml_add() {
    header('Content-Type: application/json; charset=utf-8');
    $meter_SN = isset($_GET['meter_SN']) ? $_GET['meter_SN'] : '';
    $ws_arr = body_json();
    $milliseconds = microtime(true) * 10000;
    $cmdid = isset($ws_arr['cmdid']) ? $ws_arr['cmdid'] : 'C' . $milliseconds;
    $fun = isset($ws_arr['fn']) ? $ws_arr['fn'] : '';
    $allowed_functions = array(
        'read_holding_tcp', 'read_input_tcp', 'read_holding_rtu', 'read_input_rtu',
        'recv_holding_rtu', 'analogInput', 'analogOutput', 'analogValue',
        'binaryInput', 'binaryOutput', 'binaryValue',
        'multiStateInput', 'multiStateOutput', 'multiStateValue'
    );
    $data = isset($ws_arr['data']) && is_array($ws_arr['data']) ? $ws_arr['data'] : array();
    $devices = read_json_file('./config/dvl.json');
    if (!isset($devices[$meter_SN])) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'message' => 'Device not found'));
        return;
    }
    $isBacnet = strtolower((string)($devices[$meter_SN]['mode'] ?? '')) === 'bacnet';
    $cleanData = array();
    foreach ($data as $item) {
        if (filter_var($item, FILTER_VALIDATE_INT) === false) continue;
        $item = (int)$item;
        if (!in_array($item, $cleanData, true)) $cleanData[] = $item;
    }
    $start = $data[0] ?? null;
    $end = $data[1] ?? null;
    $invalid = $meter_SN === '' || $cmdid === '' || !in_array($fun, $allowed_functions, true);
    if ($isBacnet) {
        $invalid = $invalid || !$cleanData || min($cleanData) < 0 || max($cleanData) > 4194303;
    } else {
        $invalid = $invalid || filter_var($start, FILTER_VALIDATE_INT) === false
            || filter_var($end, FILTER_VALIDATE_INT) === false
            || (int)$start < 0 || (int)$start > 65535 || (int)$end <= (int)$start
            || (int)$end > 65536 || ((int)$end - (int)$start) > 120;
        $cleanData = array((int)$start, (int)$end);
    }
    if ($invalid) {
        http_response_code(422);
        echo json_encode(array('status' => 'error', 'message' => $isBacnet ? 'BACnet Object list is invalid' : 'ช่วง Register ต้องอยู่ระหว่าง 0–65536, End มากกว่า Start และอ่านไม่เกิน 120 Registers'));
        return;
    }
    $cml_arr = read_json_file('./config/cml.json');
    $cml_arr[$cmdid] = array($fun => array($meter_SN => $cleanData));
    if (!write_file('./config/cml.json', json_encode($cml_arr, 256), "w")) {
        http_response_code(500);
        echo json_encode(array('status' => 'error', 'message' => 'Unable to write CML configuration'));
        return;
    }
    echo json_encode(array(
        'status' => 'ok', 'cmdid' => (string)$cmdid, 'meter_SN' => $meter_SN,
        'fn' => $fun, 'data' => $cleanData
    ));
}

// Create several CML commands at once (parameter picker on the sensor page).
// body = { fn: "...", commands: [[start,end], ...] }; each gets a unique id.
function act_cml_add_multi() {
    $meter_SN = isset($_GET['meter_SN']) ? $_GET['meter_SN'] : '';
    $ws = body_json();
    $fun = isset($ws['fn']) ? $ws['fn'] : '';
    $cmds = (isset($ws['commands']) && is_array($ws['commands'])) ? $ws['commands'] : array();
    $devices = read_json_file('./config/dvl.json');
    if ($meter_SN === '' || !isset($devices[$meter_SN])) {
        http_response_code(404);
        echo json_encode(array('status'=>'error', 'message'=>'Device not found'));
        return;
    }

    $sourceModel = (string)($devices[$meter_SN]['model'] ?? '');
    $sourceMode = strtolower((string)($devices[$meter_SN]['mode'] ?? ''));
    $requested = isset($ws['meter_SNs']) && is_array($ws['meter_SNs']) ? $ws['meter_SNs'] : array($meter_SN);
    $requested[] = $meter_SN;
    $targets = array();
    foreach (array_values(array_unique(array_map('strval', $requested))) as $target) {
        if (!isset($devices[$target])) continue;
        if ((string)($devices[$target]['model'] ?? '') !== $sourceModel) continue;
        if (strtolower((string)($devices[$target]['mode'] ?? '')) !== $sourceMode) continue;
        $targets[] = $target;
    }

    $cml_arr = read_json_file('./config/cml.json');
    $base = (int)round(microtime(true) * 10000);

    // Copy the complete CML set from one already-configured device to the
    // current device. Source and target must use the same model and protocol.
    $cloneFrom = trim((string)($ws['clone_from'] ?? ''));
    if ($cloneFrom !== '') {
        if (!isset($devices[$cloneFrom])
            || (string)($devices[$cloneFrom]['model'] ?? '') !== $sourceModel
            || strtolower((string)($devices[$cloneFrom]['mode'] ?? '')) !== $sourceMode) {
            http_response_code(422);
            echo json_encode(array('status'=>'error', 'message'=>'Source device must use the same Model and Mode'));
            return;
        }

        $createdClone = 0;
        $skippedClone = 0;
        $sequence = 0;
        $sourceCommands = $cml_arr;
        foreach ($sourceCommands as $sourceCommand) {
            if (!is_array($sourceCommand)) continue;
            foreach ($sourceCommand as $sourceFunction => $sourceDevices) {
                if (!is_array($sourceDevices) || !isset($sourceDevices[$cloneFrom]) || !is_array($sourceDevices[$cloneFrom])) continue;
                $sourceRange = array_values($sourceDevices[$cloneFrom]);
                if (($sourceMode === 'bacnet' && count($sourceRange) < 1) || ($sourceMode !== 'bacnet' && count($sourceRange) < 2)) continue;
                $sourceRange = array_values(array_map('intval', $sourceRange));
                $exists = false;
                foreach ($cml_arr as $targetCommand) {
                    $saved = $targetCommand[$sourceFunction][$meter_SN] ?? null;
                    if (is_array($saved) && array_values(array_map('intval', $saved)) === $sourceRange) {
                        $exists = true;
                        break;
                    }
                }
                if ($exists) { $skippedClone++; continue; }
                do { $cloneId = 'C' . ($base + $sequence++); } while (isset($cml_arr[$cloneId]));
                $cml_arr[$cloneId] = array($sourceFunction => array($meter_SN => $sourceRange));
                $createdClone++;
            }
        }
        if (!write_file('./config/cml.json', json_encode($cml_arr, 256), "w")) {
            http_response_code(500);
            echo json_encode(array('status'=>'error', 'message'=>'Unable to write CML configuration'));
            return;
        }
        echo json_encode(array(
            'status'=>'ok', 'meter_SN'=>$meter_SN, 'clone_from'=>$cloneFrom,
            'model'=>$sourceModel, 'created'=>$createdClone, 'skipped_existing'=>$skippedClone
        ));
        return;
    }

    $created = 0;
    $assigned = 0;
    $skipped = 0;
    foreach ($cmds as $i => $rng) {
        if (!is_array($rng) || count($rng) < 2) continue;
        $start = (int)$rng[0];
        $end = (int)$rng[1];
        if ($start < 0 || $end < $start || $end > 65536) continue;
        if ($sourceMode !== 'bacnet' && ($end <= $start || ($end - $start) > 120)) continue;

        $commandTargets = array();
        foreach ($targets as $target) {
            $exists = false;
            foreach ($cml_arr as $existing) {
                $saved = $existing[$fun][$target] ?? null;
                if (is_array($saved) && count($saved) >= 2
                    && (int)$saved[0] === $start && (int)$saved[1] === $end) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) { $skipped++; continue; }
            $commandTargets[$target] = array($start, $end);
        }
        if (!$commandTargets) continue;
        $cmdid = 'C' . ($base + $i);
        $cml_arr[$cmdid] = array($fun => $commandTargets);
        $created++;
        $assigned += count($commandTargets);
    }
    if (!write_file('./config/cml.json', json_encode($cml_arr, 256), "w")) {
        http_response_code(500);
        echo json_encode(array('status'=>'error', 'message'=>'Unable to write CML configuration'));
        return;
    }
    echo json_encode(array(
        "status" => "ok", "meter_SN" => $meter_SN, "model" => $sourceModel,
        "target_devices" => count($targets), "created" => $created,
        "assigned" => $assigned, "skipped_existing" => $skipped
    ));
}

function act_cml_del() {
    $cmdid = isset($_GET['cmdid']) ? $_GET['cmdid'] : '';
    $cml_arr = read_json_file('./config/cml.json');
    unset($cml_arr[$cmdid]);
    echo json_encode($cml_arr, 256);
    write_file('./config/cml.json', json_encode($cml_arr, 256), "w");
}

function act_cml_del_bulk() {
    header('Content-Type: application/json; charset=utf-8');
    $ws = body_json();
    $meter_SN = trim((string)($ws['meter_SN'] ?? ''));
    $requested = isset($ws['cmdids']) && is_array($ws['cmdids']) ? $ws['cmdids'] : array();
    $cmdids = array_values(array_unique(array_filter(array_map(function ($value) {
        $value = trim((string)$value);
        return preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $value) ? $value : '';
    }, $requested))));
    if ($meter_SN === '' || !$cmdids) {
        http_response_code(422);
        echo json_encode(array('status'=>'error', 'message'=>'Meter and command selection are required'));
        return;
    }

    $cml_arr = read_json_file('./config/cml.json');
    $deleted = array();
    foreach ($cmdids as $cmdid) {
        if (!isset($cml_arr[$cmdid]) || !is_array($cml_arr[$cmdid])) continue;
        $removedFromCommand = false;
        foreach ($cml_arr[$cmdid] as $function => &$commandDevices) {
            if (is_array($commandDevices) && array_key_exists($meter_SN, $commandDevices)) {
                unset($commandDevices[$meter_SN]);
                $removedFromCommand = true;
            }
            if (is_array($commandDevices) && !$commandDevices) unset($cml_arr[$cmdid][$function]);
        }
        unset($commandDevices);
        if (!$cml_arr[$cmdid]) unset($cml_arr[$cmdid]);
        if ($removedFromCommand) $deleted[] = $cmdid;
    }
    if (!write_file('./config/cml.json', json_encode($cml_arr, 256), "w")) {
        http_response_code(500);
        echo json_encode(array('status'=>'error', 'message'=>'Unable to write CML configuration'));
        return;
    }
    echo json_encode(array('status'=>'ok', 'meter_SN'=>$meter_SN, 'deleted'=>$deleted, 'deleted_count'=>count($deleted)));
}

// ---------- actions: register list (config/rgl.json) ----------

function rgl_bank_from_function($fn_read) {
    return stripos((string)$fn_read, 'input') !== false ? 'input' : 'holding';
}

function rgl_valid_parameter_name($name) {
    return is_string($name) && preg_match('/^[A-Za-z0-9_-]+$/', $name);
}

function rgl_normalize_value($value) {
    if (!is_array($value)) return $value;
    if (array_key_exists('multiply', $value) && is_numeric($value['multiply'])) {
        $value['multiply'] = (float)$value['multiply'];
    }
    if (array_key_exists('decimal', $value) && is_numeric($value['decimal'])) {
        $value['decimal'] = (int)$value['decimal'];
    }
    return $value;
}

function rgl_bac_function($fn_read) {
    $types = array(
        'analoginput' => 'analogInput',
        'analogoutput' => 'analogOutput',
        'analogvalue' => 'analogValue',
        'binaryinput' => 'binaryInput',
        'binaryoutput' => 'binaryOutput',
        'binaryvalue' => 'binaryValue',
        'multistateinput' => 'multiStateInput',
        'multistateoutput' => 'multiStateOutput',
        'multistatevalue' => 'multiStateValue',
    );
    $key = strtolower(trim((string)$fn_read));
    return isset($types[$key]) ? $types[$key] : trim((string)$fn_read);
}

function act_rgl_add() {
    $model = isset($_GET['model']) ? $_GET['model'] : '';
    $register = isset($_GET['register']) ? $_GET['register'] : '';
    $fn_read = isset($_GET['fn_read']) ? $_GET['fn_read'] : '';
    $ws_arr = body_json();
    $rgl_arr = read_json_file('./config/rgl.json');
    if (preg_match('/bac/i', $model)) {
        $fn_read = rgl_bac_function($fn_read);
        $value = $ws_arr[$fn_read][$register] ?? null;
        if ($value === null) {
            foreach ($ws_arr as $requestFn => $requestRegisters) {
                if (rgl_bac_function($requestFn) === $fn_read && is_array($requestRegisters)) {
                    $value = $requestRegisters[$register] ?? null;
                    break;
                }
            }
        }
        if (!is_array($value) || !rgl_valid_parameter_name($value['name'] ?? '')) {
            http_response_code(422);
            echo json_encode(array("status"=>"error","message"=>"invalid parameter name"));
            return;
        }
        $value = rgl_normalize_value($value);
        $rgl_arr[$model][$fn_read][$register] = $value;
    } else {
        $bank = rgl_bank_from_function($fn_read);
        $value = $ws_arr[$bank][$register] ?? ($ws_arr[$register] ?? null);
        if (!is_array($value) || !rgl_valid_parameter_name($value['name'] ?? '')) {
            http_response_code(422);
            echo json_encode(array("status"=>"error","message"=>"invalid parameter name"));
            return;
        }
        $value = rgl_normalize_value($value);
        $rgl_arr[$model][$bank][$register] = $value;
    }
    if (!write_file('./config/rgl.json', json_encode($rgl_arr, 256), "w")) {
        http_response_code(500);
        echo json_encode(array("status"=>"error","message"=>"cannot write rgl.json"));
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array("status"=>"ok","model"=>$model,"bank"=>(preg_match('/bac/i', $model) ? $fn_read : rgl_bank_from_function($fn_read)),"register"=>$register));
}

// Model classification type -> abbreviation (single source of truth)
function model_type_abbr() {
    return array(
        'meter'    => 'PM',
        'copro'    => 'S1',
        'inverter' => 'IN',
        'chiller'  => 'AC',
        'battery'  => 'BE',
        'sensor'   => 'SI',
        'control'  => 'RL',
    );
}

// Create an empty model entry in rgl.json (keeps existing format). No-op if it
// already exists so registers are never wiped. Used by "+new model".
function act_rgl_model_add() {
    $model = isset($_GET['model']) ? $_GET['model'] : '';
    if ($model === '') { echo json_encode(array("status"=>"error","message"=>"missing model")); return; }
    $rgl = read_json_file('./config/rgl.json');
    $created = false;
    if (!array_key_exists($model, $rgl)) {
        $rgl[$model] = new stdClass(); // empty object {} — a model with no registers yet
        $created = true;
    }
    write_file('./config/rgl.json', json_encode($rgl, 256), "w");
    echo json_encode(array("status"=>"ok","model"=>$model,"created"=>$created));
}

// Rename a model: renames the key in rgl.json, carries the type/abbr entry
// in detail_rgl.json over to the new key, and repoints every device in
// dvl.json that referenced the old model name.
function act_rgl_model_rename() {
    $model = isset($_GET['model']) ? $_GET['model'] : '';
    $new_model = isset($_GET['newModel']) ? trim($_GET['newModel']) : '';

    if ($model === '' || $new_model === '') {
        echo json_encode(array("status" => "error", "message" => "model and newModel are required"));
        return;
    }
    if ($model === $new_model) {
        echo json_encode(array("status" => "ok", "model" => $new_model, "unchanged" => true, "devicesUpdated" => 0));
        return;
    }

    $rgl_arr = read_json_file('./config/rgl.json');
    if (!array_key_exists($model, $rgl_arr)) {
        echo json_encode(array("status" => "error", "message" => "model not found"));
        return;
    }
    if (array_key_exists($new_model, $rgl_arr)) {
        echo json_encode(array("status" => "error", "message" => "newModel already exists"));
        return;
    }

    // rename the key while preserving the original ordering/position
    $renamed = array();
    foreach ($rgl_arr as $k => $v) {
        $renamed[$k === $model ? $new_model : $k] = $v;
    }
    write_file('./config/rgl.json', json_encode($renamed, 256), "w");

    $detail = read_json_file('./config/detail_rgl.json');
    if (array_key_exists($model, $detail)) {
        $detail[$new_model] = $detail[$model];
        unset($detail[$model]);
        write_file('./config/detail_rgl.json', json_encode($detail, 256), "w");
    }

    $dvl_arr = read_json_file('./config/dvl.json');
    $updated_devices = 0;
    foreach ($dvl_arr as $sn => $dev) {
        if (isset($dev['model']) && $dev['model'] === $model) {
            $dvl_arr[$sn]['model'] = $new_model;
            $updated_devices++;
        }
    }
    if ($updated_devices > 0) {
        write_file('./config/dvl.json', json_encode($dvl_arr, 256), "w");
    }

    echo json_encode(array(
        "status" => "ok",
        "model" => $new_model,
        "oldModel" => $model,
        "devicesUpdated" => $updated_devices
    ));
}

// Set/clear a model's type in detail_rgl.json (keyed by rgl.json model name).
// rgl.json is never touched. Creates detail_rgl.json / the entry if missing.
function act_detail_set() {
    $model = isset($_GET['model']) ? $_GET['model'] : '';
    $type  = isset($_GET['type'])  ? trim($_GET['type'])  : '';
    $abbr  = isset($_GET['abbr'])  ? strtoupper(trim($_GET['abbr'])) : '';
    if ($model === '') { echo json_encode(array("status"=>"error","message"=>"missing model")); return; }
    $map = model_type_abbr();
    $detail = read_json_file('./config/detail_rgl.json');
    // Reuse every type that is already assigned to an existing model,
    // including user-defined types that are not in the built-in defaults.
    foreach ($detail as $detail_row) {
        if (!is_array($detail_row)) continue;
        $saved_type = trim((string)($detail_row['type'] ?? ''));
        $saved_abbr = trim((string)($detail_row['abbr'] ?? ''));
        if ($saved_type !== '' && $saved_abbr !== '') $map[$saved_type] = $saved_abbr;
    }
    if ($type !== '' && !isset($map[$type])) {
        if (!preg_match('/^[\p{L}\p{N}_ -]{1,40}$/u', $type) || !preg_match('/^[A-Z0-9_-]{1,8}$/', $abbr)) {
            echo json_encode(array("status"=>"error","message"=>"invalid type or abbreviation")); return;
        }
        $map[$type] = $abbr;
    }
    if ($type === '') {
        unset($detail[$model]);
    } else {
        $detail[$model] = array("type"=>$type, "abbr"=>$map[$type]);
    }
    write_file('./config/detail_rgl.json', json_encode($detail, 256), "w");
    echo json_encode(array("status"=>"ok","model"=>$model,"type"=>$type,"abbr"=>($type!==''?$map[$type]:""),"removed"=>$type===''));
}

function act_rgl_add_all() {
    // Bulk save: body = { "<register>": {name,type,unit,decimal,multiply}, ... }
    // Single read-modify-write so many rows don't race on rgl.json.
    $model = isset($_GET['model']) ? $_GET['model'] : '';
    $fn_read = isset($_GET['fn_read']) ? $_GET['fn_read'] : '';
    $ws_arr = body_json();
    if (!is_array($ws_arr)) $ws_arr = array();
    $rgl_arr = read_json_file('./config/rgl.json');
    foreach ($ws_arr as $register => $val) {
        if (!is_array($val) || !rgl_valid_parameter_name($val['name'] ?? '')) {
            http_response_code(422);
            echo json_encode(array("status"=>"error","message"=>"invalid parameter name","register"=>$register));
            return;
        }
    }
    $saved = 0;
    foreach ($ws_arr as $register => $val) {
        if (preg_match('/bac/i', $model)) {
            $fn_read = rgl_bac_function($fn_read);
            $val = rgl_normalize_value($val);
            $rgl_arr[$model][$fn_read][$register] = $val;
        } else {
            $bank = rgl_bank_from_function($fn_read);
            $rgl_arr[$model][$bank][$register] = $val;
        }
        $saved++;
    }
    write_file('./config/rgl.json', json_encode($rgl_arr, 256), "w");
    echo json_encode(array("status" => "ok", "model" => $model, "saved" => $saved));
}

function act_rgl_del() {
    $model = isset($_GET['model']) ? $_GET['model'] : '';
    $register = isset($_GET['register']) ? $_GET['register'] : '';
    $fn_read = isset($_GET['fn_read']) ? $_GET['fn_read'] : '';
    $rgl_arr = read_json_file('./config/rgl.json');
    if (!array_key_exists($model, $rgl_arr)) {
        die;
    }
    if ($register != '') {
        if (preg_match('/bac/i', $model)) {
            $fn_read = rgl_bac_function($fn_read);
            unset($rgl_arr[$model][$fn_read][$register]);
        } else {
            $bank = rgl_bank_from_function($fn_read);
            unset($rgl_arr[$model][$bank][$register]);
        }
        echo "del register";
    }
    if ($register == '') {
        unset($rgl_arr[$model]);
        echo "del model";
    }
    write_file('./config/rgl.json', json_encode($rgl_arr, 256), "w");
}

function act_rgl_del_bulk() {
    $model = isset($_GET['model']) ? $_GET['model'] : '';
    $registers_raw = isset($_GET['registers']) ? $_GET['registers'] : '';
    $fn_read = isset($_GET['fn_read']) ? $_GET['fn_read'] : '';
    $rgl_arr = read_json_file('./config/rgl.json');
    if (!array_key_exists($model, $rgl_arr)) {
        die;
    }
    $registers = array_filter(array_map('trim', explode(',', $registers_raw)), fn($r) => $r !== '');
    $deleted = 0;
    $bank = preg_match('/bac/i', $model) ? rgl_bac_function($fn_read) : rgl_bank_from_function($fn_read);
    foreach ($registers as $register) {
        if (isset($rgl_arr[$model][$bank]) && array_key_exists($register, $rgl_arr[$model][$bank])) {
            unset($rgl_arr[$model][$bank][$register]);
            $deleted++;
        }
    }
    write_file('./config/rgl.json', json_encode($rgl_arr, 256), "w");
    echo json_encode(array("status" => "ok", "model" => $model, "deleted" => $deleted));
}

// ---------- actions: PM tasks (log_device/task_*_pm.txt) ----------

function act_pm_add() {
    require_pm_admin();
    $session_username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
    $session_usertype = isset($_SESSION['user_type']) ? strtolower($_SESSION['user_type']) : '';
    $session_is_admin = ($session_usertype == 'admin' || $session_usertype == 'superadmin');

    $post_arr = body_json();
    if (!$post_arr) $post_arr = $_POST;

    $device_SN = isset($post_arr['deviceSN']) ? $post_arr['deviceSN'] : '';
    $device_SN = preg_replace('/[^A-Za-z0-9_\-]/', '', $device_SN);

    if ($device_SN == '') {
        echo json_encode(array("status" => "error", "message" => "deviceSN is required"));
        return;
    }

    $orig_device_SN = isset($post_arr['origDeviceSN']) ? $post_arr['origDeviceSN'] : '';
    $orig_device_SN = preg_replace('/[^A-Za-z0-9_\-]/', '', $orig_device_SN);
    $orig_created_at = isset($post_arr['origCreatedAt']) ? $post_arr['origCreatedAt'] : '';

    $is_edit = ($orig_device_SN != '' && $orig_created_at != '');

    $submitted_status = isset($post_arr['task_status']) ? $post_arr['task_status'] : 'pending';
    if (!in_array($submitted_status, array('pending', 'completed'))) $submitted_status = 'pending';

    $task_arr = array(
        "title" => isset($post_arr['title']) ? $post_arr['title'] : '',
        "description" => isset($post_arr['description']) ? $post_arr['description'] : '',
        "deviceSN" => $device_SN,
        "schedule" => isset($post_arr['schedule']) ? $post_arr['schedule'] : '',
        "alertBefore" => isset($post_arr['alertBefore']) ? $post_arr['alertBefore'] : '',
        "createdBy" => isset($post_arr['createdBy']) ? $post_arr['createdBy'] : '',
        "createdAt" => $is_edit ? $orig_created_at : date("Y-m-d H:i:s"),
        "task_status" => $submitted_status
    );

    $task_arr["attachments"] = pm_clean_attachments($post_arr['attachments'] ?? null);

    if ($is_edit) {
        $orig_file_path = "./log_device/task_" . $orig_device_SN . "_pm.txt";
        $orig_creator = null;
        $orig_record = null;
        $orig_lines = file_exists($orig_file_path)
            ? file($orig_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            : array();
        foreach ($orig_lines as $orig_line) {
            $orig_row = json_decode($orig_line, true);
            if (is_array($orig_row) && ($orig_row['createdAt'] ?? '') === $orig_created_at) {
                $orig_creator = $orig_row['createdBy'] ?? '';
                $orig_record = $orig_row;
                break;
            }
        }

        $can_edit = $session_is_admin || ($orig_creator !== null && $orig_creator === $session_username);
        if (!$can_edit) {
            echo json_encode(array("status" => "error", "message" => "You don't have permission to edit this task"));
            return;
        }

        $was_completed = is_array($orig_record) && ($orig_record['task_status'] ?? '') === 'completed';
        if ($submitted_status === 'completed') {
            $task_arr['completedBy'] = $was_completed ? ($orig_record['completedBy'] ?? $session_username) : $session_username;
            $task_arr['completedAt'] = $was_completed ? ($orig_record['completedAt'] ?? date("Y-m-d H:i:s")) : date("Y-m-d H:i:s");
            // keep completion note/files entered via the complete popup
            if ($was_completed) {
                if (isset($orig_record['complete_description'])) $task_arr['complete_description'] = $orig_record['complete_description'];
                if (isset($orig_record['complete_attachments'])) $task_arr['complete_attachments'] = $orig_record['complete_attachments'];
            }
        }
        // submitted_status === 'pending' leaves completedBy/completedAt unset (reopened)

        $task_arr["updatedAt"] = date("Y-m-d H:i:s");

        $kept_lines = array();
        foreach ($orig_lines as $orig_line) {
            $orig_row = json_decode($orig_line, true);
            if (is_array($orig_row) && ($orig_row['createdAt'] ?? '') === $orig_created_at) {
                continue; // drop the old version of this task
            }
            $kept_lines[] = $orig_line;
        }
        $kept_text = count($kept_lines) > 0 ? implode("\n", $kept_lines) . "\n" : "";
        write_file($orig_file_path, $kept_text, "w");
    } else {
        if ($submitted_status === 'completed') {
            $task_arr['completedBy'] = $session_username != '' ? $session_username : $task_arr['createdBy'];
            $task_arr['completedAt'] = date("Y-m-d H:i:s");
        }
    }

    $file_path = "./log_device/task_" . $device_SN . "_pm.txt";
    write_file($file_path, json_encode($task_arr, 256) . "\n", "a");

    echo json_encode(array("status" => "success", "data" => $task_arr));
}

function act_pm_delete() {
    require_pm_admin();
    $session_usertype = isset($_SESSION['user_type']) ? strtolower($_SESSION['user_type']) : '';
    $session_is_admin = ($session_usertype == 'admin' || $session_usertype == 'superadmin');

    if (!$session_is_admin) {
        echo json_encode(array("status" => "error", "message" => "You don't have permission to delete this task"));
        return;
    }

    $post_arr = body_json();
    if (!$post_arr) $post_arr = $_POST;

    $device_SN = isset($post_arr['deviceSN']) ? $post_arr['deviceSN'] : '';
    $device_SN = preg_replace('/[^A-Za-z0-9_\-]/', '', $device_SN);
    $created_at = isset($post_arr['createdAt']) ? $post_arr['createdAt'] : '';

    if ($device_SN == '' || $created_at == '') {
        echo json_encode(array("status" => "error", "message" => "deviceSN and createdAt are required"));
        return;
    }

    $file_path = "./log_device/task_" . $device_SN . "_pm.txt";
    if (!file_exists($file_path)) {
        echo json_encode(array("status" => "error", "message" => "Task not found"));
        return;
    }

    $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $found = false;
    $kept_lines = array();
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && ($row['createdAt'] ?? '') === $created_at) {
            $found = true;
            continue;
        }
        $kept_lines[] = $line;
    }

    if (!$found) {
        echo json_encode(array("status" => "error", "message" => "Task not found"));
        return;
    }

    $text = count($kept_lines) > 0 ? implode("\n", $kept_lines) . "\n" : "";
    write_file($file_path, $text, "w");

    echo json_encode(array("status" => "success"));
}

function act_pm_complete() {
    require_pm_admin();
    $session_username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
    if ($session_username == '') {
        echo json_encode(array("status" => "error", "message" => "You must be logged in"));
        return;
    }

    $post_arr = body_json();
    if (!$post_arr) $post_arr = $_POST;

    $device_SN = isset($post_arr['deviceSN']) ? $post_arr['deviceSN'] : '';
    $device_SN = preg_replace('/[^A-Za-z0-9_\-]/', '', $device_SN);
    $created_at = isset($post_arr['createdAt']) ? $post_arr['createdAt'] : '';

    if ($device_SN == '' || $created_at == '') {
        echo json_encode(array("status" => "error", "message" => "deviceSN and createdAt are required"));
        return;
    }

    $complete_desc = isset($post_arr['description']) ? $post_arr['description'] : '';
    $complete_atts = pm_clean_attachments($post_arr['attachments'] ?? null);

    $file_path = "./log_device/task_" . $device_SN . "_pm.txt";
    if (!file_exists($file_path)) {
        echo json_encode(array("status" => "error", "message" => "Task not found"));
        return;
    }

    $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $updated_arr = null;
    $new_lines = array();
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && ($row['createdAt'] ?? '') === $created_at) {
            $row['task_status'] = 'completed';
            $row['completedBy'] = $session_username;
            $row['completedAt'] = date("Y-m-d H:i:s");
            $row['complete_description'] = $complete_desc;
            $row['complete_attachments'] = $complete_atts;
            $updated_arr = $row;
            $new_lines[] = json_encode($row, 256);
        } else {
            $new_lines[] = $line;
        }
    }

    if ($updated_arr === null) {
        echo json_encode(array("status" => "error", "message" => "Task not found"));
        return;
    }

    $text = count($new_lines) > 0 ? implode("\n", $new_lines) . "\n" : "";
    write_file($file_path, $text, "w");

    echo json_encode(array("status" => "success", "data" => $updated_arr));
}

// ---------- dispatch ----------

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'device_add':  act_device_add();  break;
    case 'device_del':  act_device_del();  break;
    case 'device_del_bulk': act_device_del_bulk(); break;
    case 'cml_add':     act_cml_add();     break;
    case 'cml_add_multi': act_cml_add_multi(); break;
    case 'cml_del':     act_cml_del();     break;
    case 'cml_del_bulk': act_cml_del_bulk(); break;
    case 'rgl_add':     act_rgl_add();     break;
    case 'rgl_add_all': act_rgl_add_all(); break;
    case 'detail_set':  act_detail_set();  break;
    case 'rgl_model_add': act_rgl_model_add(); break;
    case 'rgl_model_rename': act_rgl_model_rename(); break;
    case 'rgl_del':     act_rgl_del();     break;
    case 'rgl_del_bulk': act_rgl_del_bulk(); break;
    case 'pm_add':      act_pm_add();      break;
    case 'pm_delete':   act_pm_delete();   break;
    case 'pm_complete': act_pm_complete(); break;
    default:
        echo json_encode(array("status" => "error", "message" => "unknown action"));
}
?>
