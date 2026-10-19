<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
date_default_timezone_set('Asia/Bangkok');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$result = array(
    'device_SN' => '',
    'ts' => 0,
    'dt' => null,
    'data' => array()
);

$configPath = __DIR__ . '/data/mcf_default.json';
$configText = is_file($configPath) ? file_get_contents($configPath) : false;
$config = $configText !== false ? json_decode($configText, true) : null;
$deviceSN = is_array($config) && isset($config['deviceSN']) ? (string)$config['deviceSN'] : '';
$result['device_SN'] = $deviceSN;

if ($deviceSN === '') {
    http_response_code(500);
    $result['error'] = 'deviceSN is not configured';
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$logDir = "/home/mdbcare/python/log_action/{$deviceSN}";
$logFiles = glob($logDir . '/*_log5_*.txt');
if ($logFiles === false) {
    $logFiles = array();
}

usort($logFiles, function ($a, $b) {
    return strnatcmp(basename($b), basename($a));
});

// A new log is created every five minutes. During the first polling cycle that
// file does not yet contain every device, so merge it with the preceding file.
// Process the older file first, allowing newer readings to overwrite it.
$filesToRead = array_reverse(array_slice($logFiles, 0, 2));
$readableFileCount = 0;

foreach ($filesToRead as $logFile) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        continue;
    }
    $readableFileCount++;

    foreach ($lines as $line) {
        $parts = explode('|', $line, 2);
        if (count($parts) !== 2 || !is_numeric($parts[0])) {
            continue;
        }

        $timestamp = (int)$parts[0];
        $message = json_decode($parts[1], true);
        if (!is_array($message) || !isset($message['method']) || !isset($message['data']) || !is_array($message['data'])) {
            continue;
        }

        if ($message['method'] === 'res') {
            $payload = $message['data'];
            $meterSN = isset($payload['meter_SN']) ? (string)$payload['meter_SN'] : '';
            $readings = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : null;
            if ($meterSN !== '' && $readings !== null) {
                $result['data'][$meterSN] = array_merge($result['data'][$meterSN] ?? array(), $readings);
                $result['data'][$meterSN]['dt'] = $timestamp;
            }
        } elseif ($message['method'] === 'rec') {
            $payload = $message['data'];
            $meters = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
            foreach ($meters as $meterSN => $readings) {
                if (!is_array($readings)) {
                    continue;
                }
                $meterSN = (string)$meterSN;
                $result['data'][$meterSN] = array_merge($result['data'][$meterSN] ?? array(), $readings);
                $result['data'][$meterSN]['dt'] = $timestamp;
            }
        }

        if ($timestamp > $result['ts']) {
            $result['ts'] = $timestamp;
        }
    }
}

if ($result['ts'] > 0) {
    $result['dt'] = date('Y-m-d H:i:s', $result['ts']);
}

// BACnet is collected in one dedicated process and therefore does not emit the
// per-register Modbus action-log records parsed above. Merge its latest atomic
// snapshot so All Readings exposes both protocols through the same response.
$bacnetCachePath = __DIR__ . '/data/bacnet_latest.json';
$bacnetCacheText = is_file($bacnetCachePath) ? @file_get_contents($bacnetCachePath) : false;
$bacnetCache = $bacnetCacheText !== false ? json_decode($bacnetCacheText, true) : null;
if (is_array($bacnetCache) && isset($bacnetCache['data']) && is_array($bacnetCache['data'])) {
    $bacnetTimestamp = isset($bacnetCache['ts']) && is_numeric($bacnetCache['ts'])
        ? (int)$bacnetCache['ts'] : 0;
    foreach ($bacnetCache['data'] as $meterSN => $readings) {
        if (!is_array($readings)) {
            continue;
        }
        $meterSN = (string)$meterSN;
        $result['data'][$meterSN] = array_merge($result['data'][$meterSN] ?? array(), $readings);
        $result['data'][$meterSN]['dt'] = $bacnetTimestamp;
    }
    if ($bacnetTimestamp > $result['ts']) {
        $result['ts'] = $bacnetTimestamp;
        $result['dt'] = date('Y-m-d H:i:s', $bacnetTimestamp);
    }
}

// q_max is a writable Battery setpoint and is not part of the normal CML
// polling groups. Merge the last successful Automation command so realtime
// pages can display the active setpoint alongside the measured Battery data.
$automationStatePath = '/home/mdbcare/python/automation_last_commands.json';
$automationStateText = is_file($automationStatePath) ? @file_get_contents($automationStatePath) : false;
$automationState = $automationStateText !== false ? json_decode($automationStateText, true) : null;
if (is_array($automationState)) {
    foreach ($automationState as $commandKey => $commandValue) {
        if (!is_string($commandKey) || !str_ends_with($commandKey, ':q_max') || !is_numeric($commandValue)) {
            continue;
        }
        $meterSN = substr($commandKey, 0, -strlen(':q_max'));
        if ($meterSN !== '' && isset($result['data'][$meterSN])) {
            $result['data'][$meterSN]['q_max'] = (float)$commandValue;
        }
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
?>
