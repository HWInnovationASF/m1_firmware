<?php
require_once __DIR__ . '/session_bootstrap.php';
header('X-Content-Type-Options: nosniff');

$userType = strtolower((string)($_SESSION['user_type'] ?? ''));
if (empty($_SESSION['username']) || !in_array($userType, array('admin', 'superadmin'), true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('status'=>'error','message'=>'Permission denied'));
    exit;
}

$configPath = __DIR__ . '/config/dashboard_template.json';
$allowed = array('energy_diagram','chiller_diagram','template1','template2','template3','template4','template5','template6');

function template_clean_labels($raw, $allowed) {
    $labels = array();
    if (!is_array($raw)) return $labels;
    foreach ($allowed as $id) {
        $label = trim(strip_tags((string)($raw[$id] ?? '')));
        if ($label !== '') $labels[$id] = mb_substr($label, 0, 60, 'UTF-8');
    }
    return $labels;
}

function template_clean_settings($raw) {
    $map = array();
    $usedMeters = array();
    $source = $raw['chiller_diagram']['chiller_meters'] ?? array();
    if (is_array($source)) foreach ($source as $chiller=>$meters) {
        $chiller = mb_substr(trim((string)$chiller), 0, 100, 'UTF-8');
        if (!is_array($meters)) $meters = array($meters);
        $cleanMeters = array();
        foreach ($meters as $meter) {
            $meter = mb_substr(trim((string)$meter), 0, 100, 'UTF-8');
            if ($meter !== '' && !isset($usedMeters[$meter]) && !in_array($meter, $cleanMeters, true)) {
                $cleanMeters[] = $meter;
                $usedMeters[$meter] = true;
            }
        }
        if ($chiller !== '' && $cleanMeters) $map[$chiller] = $cleanMeters;
    }
    $powerMap = array();
    $powerSource = $raw['chiller_diagram']['plant_power_meters'] ?? array();
    if (is_array($powerSource)) array_walk_recursive($powerSource, function($meter) use (&$powerMap) {
        $meter = mb_substr(trim((string)$meter), 0, 100, 'UTF-8');
        if ($meter !== '' && !in_array($meter, $powerMap, true)) $powerMap[] = $meter;
    });
    $chwpPowerMeters = array();
    $chwpPowerSource = $raw['chiller_diagram']['chwp_power_meters'] ?? array();
    if (is_array($chwpPowerSource)) foreach ($chwpPowerSource as $chwp=>$meters) {
        $chwp = mb_substr(trim((string)$chwp), 0, 100, 'UTF-8');
        if ($chwp === '') continue;
        if (!is_array($meters)) $meters = array($meters);
        $cleanMeters = array();
        foreach ($meters as $meter) {
            $meter = mb_substr(trim((string)$meter), 0, 100, 'UTF-8');
            if ($meter !== '' && !in_array($meter, $cleanMeters, true)) $cleanMeters[] = $meter;
        }
        if ($cleanMeters) $chwpPowerMeters[$chwp] = $cleanMeters;
    }
    $mainPowerMeters = array();
    $mainSource = $raw['chiller_diagram']['main_power_meters'] ?? ($raw['chiller_diagram']['main_power_meter'] ?? array());
    if (!is_array($mainSource)) $mainSource = array($mainSource);
    array_walk_recursive($mainSource, function($meter) use (&$mainPowerMeters) {
        $meter = mb_substr(trim((string)$meter), 0, 100, 'UTF-8');
        if ($meter !== '' && !in_array($meter, $mainPowerMeters, true)) $mainPowerMeters[] = $meter;
    });
    $chillerDevices = array();
    $deviceSource = $raw['chiller_diagram']['chiller_devices'] ?? array();
    if (!is_array($deviceSource)) $deviceSource = array($deviceSource);
    foreach ($deviceSource as $device) {$device=mb_substr(trim((string)$device),0,100,'UTF-8');if($device!==''&&!in_array($device,$chillerDevices,true))$chillerDevices[]=$device;}
    $loadDevices = array();
    $loadSource = $raw['chiller_diagram']['load_devices'] ?? array();
    if (!is_array($loadSource)) $loadSource = array($loadSource);
    foreach ($loadSource as $device) {$device=mb_substr(trim((string)$device),0,100,'UTF-8');if($device!==''&&!in_array($device,$loadDevices,true))$loadDevices[]=$device;}
    $loadZones = array();
    $usedAcDevices = array();
    $zoneSource = $raw['chiller_diagram']['load_zones'] ?? array();
    if (is_array($zoneSource)) foreach (array_slice($zoneSource, 0, 50, true) as $meter=>$zone) {
        if (!is_array($zone)) continue;
        $meter = mb_substr(trim((string)$meter), 0, 100, 'UTF-8');
        $name = mb_substr(trim(strip_tags((string)($zone['name'] ?? ''))), 0, 60, 'UTF-8');
        $devices = $zone['devices'] ?? array();
        if (!is_array($devices)) $devices = array($devices);
        $cleanDevices = array();
        foreach ($devices as $device) {
            $device = mb_substr(trim((string)$device), 0, 100, 'UTF-8');
            if ($device !== '' && !isset($usedAcDevices[$device])) {
                $cleanDevices[] = $device;
                $usedAcDevices[$device] = true;
            }
        }
        if ($meter !== '' && $name !== '') $loadZones[$meter] = array('name'=>$name, 'devices'=>$cleanDevices);
    }
    $floorplanPoints = array();
    $pointSource = $raw['chiller_diagram']['floorplan_points'] ?? array();
    if (is_array($pointSource)) foreach (array_slice($pointSource, 0, 20, true) as $planId=>$points) {
        $planId = preg_replace('/[^a-z0-9_-]/i', '', (string)$planId);
        if ($planId === '' || !is_array($points)) continue;
        $cleanPoints = array();
        foreach (array_slice($points, 0, 100, true) as $pointId=>$device) {
            $pointId = preg_replace('/[^a-z0-9_-]/i', '', (string)$pointId);
            $device = mb_substr(trim((string)$device), 0, 100, 'UTF-8');
            if ($pointId !== '' && $device !== '') $cleanPoints[$pointId] = $device;
        }
        $floorplanPoints[$planId] = $cleanPoints;
    }
    $floorplanRemarks = array();
    $remarkSource = $raw['chiller_diagram']['floorplan_remarks'] ?? array();
    if (is_array($remarkSource)) foreach (array_slice($remarkSource, 0, 20, true) as $planId=>$remarks) {
        $planId = preg_replace('/[^a-z0-9_-]/i', '', (string)$planId);
        if ($planId === '' || !is_array($remarks)) continue;
        $cleanRemarks = array();
        foreach (array_slice($remarks, 0, 100, true) as $pointId=>$remark) {
            $pointId = preg_replace('/[^a-z0-9_-]/i', '', (string)$pointId);
            $remark = mb_substr(trim(strip_tags((string)$remark)), 0, 100, 'UTF-8');
            if ($pointId !== '' && $remark !== '') $cleanRemarks[$pointId] = $remark;
        }
        $floorplanRemarks[$planId] = $cleanRemarks;
    }
    $floorplanLayouts = array();
    $layoutSource = $raw['chiller_diagram']['floorplan_layouts'] ?? array();
    if (is_array($layoutSource)) foreach (array_slice($layoutSource, 0, 20, true) as $planId=>$layout) {
        $planId = preg_replace('/[^a-z0-9_-]/i', '', (string)$planId);
        if ($planId === '' || !is_array($layout)) continue;
        $background = (string)($layout['background'] ?? '');
        if (!preg_match('#^assets/images/floorplans/[a-z0-9_.-]+\.(?:png|jpe?g|webp)$#i', $background)) $background = '';
        $cleanPositions = array();
        foreach (array_slice(is_array($layout['positions'] ?? null) ? $layout['positions'] : array(), 0, 100) as $position) {
            if (!is_array($position) || count($position) < 2 || !is_numeric($position[0]) || !is_numeric($position[1])) continue;
            $cleanPositions[] = array(round(max(0, min(100, (float)$position[0])), 3), round(max(0, min(100, (float)$position[1])), 3));
        }
        $floorplanLayouts[$planId] = array('background'=>$background, 'positions'=>$cleanPositions);
    }
    return array_merge(template_clean_extra_settings($raw), array('chiller_diagram'=>array('chiller_meters'=>$map,'main_power_meters'=>$mainPowerMeters,'plant_power_meters'=>$powerMap,'chwp_power_meters'=>$chwpPowerMeters,'chiller_devices'=>$chillerDevices,'load_devices'=>$loadDevices,'load_zones'=>$loadZones,'floorplan_points'=>$floorplanPoints,'floorplan_remarks'=>$floorplanRemarks,'floorplan_layouts'=>$floorplanLayouts)));
}

// ---------- ค่าตั้งของ template อื่น (หน้า index.php?page=template-settings) ----------
// กลุ่มมิเตอร์ของ Template 2-5 (เดิมเขียนตายตัวใน monitorx.php) ; ชื่อกลุ่มต้องตรงกับที่ template นั้นใช้
const TEMPLATE_GROUPS = array(
    'template2' => array('mdb1', 'mdb1_use', 'mdb2', 'mdb3', 'mdb4'),
    'template3' => array('mdb1', 'mdb2'),
    'template4' => array('mdb1', 'mdb2'),
    'template5' => array('mdb1', 'ach', 'mdb2'),
);

function template_clean_device_list($raw) {
    $out = array();
    if (!is_array($raw)) return $out;
    foreach ($raw as $device) {
        $device = mb_substr(trim((string)$device), 0, 100, 'UTF-8');
        if ($device !== '' && !in_array($device, $out, true)) $out[] = $device;
        if (count($out) >= 200) break;
    }
    return $out;
}

// คืนเฉพาะ template ที่มีค่าใน $raw (template ที่ไม่ได้ส่งมา = ไม่มีคีย์ -> ใช้ค่าเริ่มต้นของหน้า)
function template_clean_extra_settings($raw) {
    $out = array();
    if (!is_array($raw)) return $out;
    $energy = $raw['energy_diagram'] ?? null;
    if (is_array($energy)) {
        $clean = array();
        $meter = mb_substr(trim((string)($energy['main_meter'] ?? '')), 0, 100, 'UTF-8');
        if ($meter !== '') $clean['main_meter'] = $meter;
        foreach (array('energy_mode' => array('grid', 'load'), 'meter_sign' => array('import', 'export'), 'battery_sign' => array('discharge', 'charge')) as $key => $choices)
            if (in_array($energy[$key] ?? null, $choices, true)) $clean[$key] = $energy[$key];
        if ($clean) $out['energy_diagram'] = $clean;
    }
    foreach (TEMPLATE_GROUPS as $id => $groups) {
        if (!is_array($raw[$id]['groups'] ?? null)) continue;
        $clean = array();
        foreach ($groups as $group) if (array_key_exists($group, $raw[$id]['groups'])) $clean[$group] = template_clean_device_list($raw[$id]['groups'][$group]);
        if ($clean) $out[$id] = array('groups' => $clean);
    }
    $list = $raw['template6'] ?? null;
    if (is_array($list)) $out['template6'] = array('hidden_devices' => template_clean_device_list($list['hidden_devices'] ?? array()), 'hide_offline' => ($list['hide_offline'] ?? false) === true);
    return $out;
}

// รายชื่อ chiller ที่ตัวเก็บข้อมูลใช้ (config/zx_cdn.json บรรทัดละเครื่อง) ต้องตรงกับ chiller_devices เสมอ
function template_write_zx_cdn($configPath, $settings) {
    $devices = $settings['chiller_diagram']['chiller_devices'] ?? array();
    $path = dirname($configPath) . '/zx_cdn.json';
    $text = $devices ? implode("\n", $devices) . "\n" : '';
    return file_put_contents($path . '.tmp', $text, LOCK_EX) !== false && rename($path . '.tmp', $path);
}

function template_write_config($path, $active, $labels, $settings) {
    $temporary = $path . '.tmp';
    $payload = json_encode(array('active'=>$active,'labels'=>$labels,'settings'=>$settings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($temporary, $payload, LOCK_EX) === false || !rename($temporary, $path)) return false;
    return true;
}

if (($_GET['action'] ?? '') === 'export') {
    $current = json_decode((string)@file_get_contents($configPath), true);
    $active = is_array($current) && in_array(($current['active'] ?? ''), $allowed, true) ? $current['active'] : 'energy_diagram';
    $labels = template_clean_labels(is_array($current) ? ($current['labels'] ?? array()) : array(), $allowed);
    $settings = template_clean_settings(is_array($current) ? ($current['settings'] ?? array()) : array());
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="dashboard-template-' . date('Ymd-His') . '.json"');
    echo json_encode(array('format'=>'mdbiot-dashboard-template','version'=>11,'active'=>$active,'labels'=>$labels,'settings'=>$settings), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('status'=>'error','message'=>'Method not allowed'));
    exit;
}

$active = '';
$labels = array();
$settings = array();
$jsonInput = isset($_FILES['template_file']) ? null : json_decode((string)file_get_contents('php://input'), true);
if (is_array($jsonInput) && ($jsonInput['action'] ?? '') === 'template_settings') {
    // บันทึกค่าตั้งของ template เดียว (ไม่แตะ active/labels/ค่าของ template อื่น)
    $tid = (string)($jsonInput['template'] ?? '');
    header('Content-Type: application/json; charset=utf-8');
    if (!in_array($tid, $allowed, true) || $tid === 'template1') {
        http_response_code(422);
        echo json_encode(array('status'=>'error','message'=>'Template นี้ไม่มีค่าตั้งในหน้านี้'));
        exit;
    }
    $current = json_decode((string)@file_get_contents($configPath), true);
    if (!is_array($current)) $current = array();
    $currentSettings = is_array($current['settings'] ?? null) ? $current['settings'] : array();
    $currentSettings[$tid] = is_array($jsonInput['settings'] ?? null) ? $jsonInput['settings'] : array();
    $settings = template_clean_settings($currentSettings);
    $active = in_array(($current['active'] ?? ''), $allowed, true) ? $current['active'] : 'energy_diagram';
    if (!template_write_config($configPath, $active, template_clean_labels($current['labels'] ?? array(), $allowed), $settings)) {
        http_response_code(500);
        echo json_encode(array('status'=>'error','message'=>'Cannot save template config'));
        exit;
    }
    if ($tid === 'chiller_diagram' && !template_write_zx_cdn($configPath, $settings)) {
        http_response_code(500);
        echo json_encode(array('status'=>'error','message'=>'Dashboard saved but cannot write zx_cdn.json'));
        exit;
    }
    echo json_encode(array('status'=>'ok','template'=>$tid,'settings'=>$settings[$tid] ?? new stdClass()), JSON_UNESCAPED_UNICODE);
    exit;
}
if (isset($_FILES['template_file'])) {
    if ($_FILES['template_file']['error'] !== UPLOAD_ERR_OK || $_FILES['template_file']['size'] > 65536) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('status'=>'error','message'=>'Invalid template file'));
        exit;
    }
    $import = json_decode((string)file_get_contents($_FILES['template_file']['tmp_name']), true);
    if (!is_array($import) || ($import['format'] ?? '') !== 'mdbiot-dashboard-template') {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('status'=>'error','message'=>'Unsupported template file'));
        exit;
    }
    $active = (string)($import['active'] ?? '');
    $labels = template_clean_labels($import['labels'] ?? array(), $allowed);
    $settings = template_clean_settings($import['settings'] ?? array());
} else {
    $input = $jsonInput;
    $active = is_array($input) ? (string)($input['active'] ?? '') : (string)($_POST['active'] ?? '');
    $labels = template_clean_labels(is_array($input) ? ($input['labels'] ?? array()) : array(), $allowed);
    $rawSettings = is_array($input) && is_array($input['settings'] ?? null) ? $input['settings'] : array();
    $currentConfig = json_decode((string)@file_get_contents($configPath), true);
    $currentChiller = is_array($currentConfig) ? ($currentConfig['settings']['chiller_diagram'] ?? array()) : array();
    if (!isset($rawSettings['chiller_diagram']) || !is_array($rawSettings['chiller_diagram'])) $rawSettings['chiller_diagram'] = array();
    foreach (array('chiller_meters','main_power_meters','plant_power_meters','chwp_power_meters','chiller_devices','load_devices','load_zones','floorplan_points','floorplan_remarks','floorplan_layouts') as $key) if (!array_key_exists($key, $rawSettings['chiller_diagram']) && array_key_exists($key, $currentChiller)) $rawSettings['chiller_diagram'][$key] = $currentChiller[$key];
    // ค่าตั้งของ template อื่นที่หน้านี้ไม่ได้ส่งมา: คงค่าเดิม (ไม่ให้ปุ่ม Save ของหน้า Template config ลบทิ้ง)
    if (is_array($currentConfig['settings'] ?? null)) foreach ($currentConfig['settings'] as $key => $value) if ($key !== 'chiller_diagram' && !array_key_exists($key, $rawSettings)) $rawSettings[$key] = $value;
    $settings = template_clean_settings($rawSettings);
}

header('Content-Type: application/json; charset=utf-8');
if (!in_array($active, $allowed, true)) {
    http_response_code(422);
    echo json_encode(array('status'=>'error','message'=>'Unknown template'));
    exit;
}
if (!template_write_config($configPath, $active, $labels, $settings)) {
    http_response_code(500);
    echo json_encode(array('status'=>'error','message'=>'Cannot save template config'));
    exit;
}
if (!template_write_zx_cdn($configPath, $settings)) {
    http_response_code(500);
    echo json_encode(array('status'=>'error','message'=>'Dashboard saved but cannot write zx_cdn.json'));
    exit;
}
echo json_encode(array('status'=>'ok','active'=>$active,'labels'=>$labels,'settings'=>$settings));
?>
