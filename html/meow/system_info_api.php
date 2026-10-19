<?php
require_once __DIR__ . '/session_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['username']) || !in_array(strtolower($_SESSION['user_type'] ?? ''), ['admin', 'superadmin'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

function read_cpu_times() {
    $line = strtok((string)@file_get_contents('/proc/stat'), "\n");
    $parts = preg_split('/\s+/', trim((string)$line));
    array_shift($parts);
    $values = array_map('floatval', $parts);
    $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
    return ['idle' => $idle, 'total' => array_sum($values)];
}
function read_meminfo() {
    $result = [];
    foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([^:]+):\s+(\d+)/', $line, $match)) $result[$match[1]] = (float)$match[2] * 1024;
    }
    return $result;
}
function format_os_release() {
    $values = [];
    foreach (file('/etc/os-release', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $values[$key] = trim($value, "\"'");
    }
    return $values['PRETTY_NAME'] ?? php_uname('s');
}

$cpuStart = read_cpu_times();
usleep(120000);
$cpuEnd = read_cpu_times();
$totalDelta = max(1, $cpuEnd['total'] - $cpuStart['total']);
$idleDelta = max(0, $cpuEnd['idle'] - $cpuStart['idle']);
$cpuPercent = max(0, min(100, (1 - ($idleDelta / $totalDelta)) * 100));
$cpuCoreCount = preg_match_all('/^processor\s*:/m', (string)@file_get_contents('/proc/cpuinfo'));

$memory = read_meminfo();
$memoryTotal = $memory['MemTotal'] ?? 0;
$memoryAvailable = $memory['MemAvailable'] ?? ($memory['MemFree'] ?? 0);
$memoryUsed = max(0, $memoryTotal - $memoryAvailable);
$diskTotal = (float)@disk_total_space('/');
$diskFree = (float)@disk_free_space('/');
$diskUsed = max(0, $diskTotal - $diskFree);
$uptimeParts = preg_split('/\s+/', trim((string)@file_get_contents('/proc/uptime')));
$temperature = null;
foreach (glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $temperatureFile) {
    $raw = (float)trim((string)@file_get_contents($temperatureFile));
    if ($raw > 0) { $temperature = $raw > 1000 ? $raw / 1000 : $raw; break; }
}
$serviceOutput = [];
$serviceCode = 1;
@exec('/bin/systemctl is-active py_multi.service 2>/dev/null', $serviceOutput, $serviceCode);
$model = trim(str_replace("\0", '', (string)@file_get_contents('/proc/device-tree/model')));
$automationConfig = json_decode((string)@file_get_contents(__DIR__ . '/config/automation_control.json'), true);
$automationEnabled = is_array($automationConfig) && !empty($automationConfig['enabled']);

echo json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'cpu' => [
        'percent' => round($cpuPercent, 1),
        'cores' => max(1, (int)$cpuCoreCount),
        'load' => array_map(fn($value) => round((float)$value, 2), sys_getloadavg() ?: [0, 0, 0]),
        'temperature' => $temperature === null ? null : round($temperature, 1)
    ],
    'memory' => [
        'total' => $memoryTotal,
        'used' => $memoryUsed,
        'available' => $memoryAvailable,
        'percent' => $memoryTotal > 0 ? round($memoryUsed / $memoryTotal * 100, 1) : 0
    ],
    'disk' => [
        'total' => $diskTotal,
        'used' => $diskUsed,
        'free' => $diskFree,
        'percent' => $diskTotal > 0 ? round($diskUsed / $diskTotal * 100, 1) : 0
    ],
    'system' => [
        'hostname' => gethostname(),
        'model' => $model ?: '—',
        'os' => format_os_release(),
        'kernel' => php_uname('r'),
        'architecture' => php_uname('m'),
        'uptime_seconds' => (int)($uptimeParts[0] ?? 0)
    ],
    'services' => [
        'py_multi' => $serviceCode === 0 && trim(implode('', $serviceOutput)) === 'active' ? 'active' : 'inactive',
        'automation_enabled' => $automationEnabled
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
