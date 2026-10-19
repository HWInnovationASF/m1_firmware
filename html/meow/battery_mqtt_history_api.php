<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Bangkok');

$logDirectory = '/home/mdbcare/python/battery_control_logs';
if (isset($_GET['list_dates'])) {
    $availableDates = [];
    $logFiles = array_merge(
        glob($logDirectory . '/battery_control_*.jsonl') ?: [],
        glob($logDirectory . '/battery_control_*.jsonl.gz') ?: []
    );
    foreach ($logFiles as $logFile) {
        if (preg_match('/battery_control_(\d{4}-\d{2}-\d{2})\.jsonl(?:\.gz)?$/', basename($logFile), $match)
            && filesize($logFile) > 0) {
            $availableDates[] = $match[1];
        }
    }
    rsort($availableDates);
    echo json_encode(['dates' => array_values(array_unique($availableDates))], JSON_UNESCAPED_UNICODE);
    exit;
}

$date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date']);
    exit;
}
$devices = json_decode(@file_get_contents('./config/dvl.json'), true) ?: [];
$automationConfig = json_decode(@file_get_contents('./config/automation_control.json'), true) ?: [];
$plainPath = $logDirectory . '/battery_control_' . $date . '.jsonl';
$archivePath = $plainPath . '.gz';
$path = is_file($plainPath) ? $plainPath : (is_file($archivePath) ? $archivePath : '');
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = max(5, min(500, (int)($_GET['page_size'] ?? 5)));
$sortBy = (string)($_GET['sort_by'] ?? 'datetime');
$sortDirection = strtolower((string)($_GET['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$allowedSorts = ['datetime', 'source', 'device', 'parameter', 'requested', 'actual', 'success', 'reason'];
if (!in_array($sortBy, $allowedSorts, true)) $sortBy = 'datetime';
$entries = [];
$allRows = [];
$latestRow = null;
if ($path !== '') {
    $lines = substr($path, -3) === '.gz'
        ? (gzfile($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
        : (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    foreach (array_reverse($lines) as $line) {
        $row = json_decode($line, true);
        if (!is_array($row)) continue;
        $allRows[] = $row;
    }
    $latestRow = $allRows[0] ?? null;
    usort($allRows, function ($left, $right) use ($sortBy, $sortDirection, $devices) {
        $field = $sortBy;
        if ($sortBy === 'device') {
            $leftId = (string)($left['device_id'] ?? '');
            $rightId = (string)($right['device_id'] ?? '');
            $leftValue = $devices[$leftId]['name'] ?? $leftId;
            $rightValue = $devices[$rightId]['name'] ?? $rightId;
        } else {
            $leftValue = $left[$field] ?? null;
            $rightValue = $right[$field] ?? null;
        }
        if (($leftValue === null || $leftValue === '') && ($rightValue !== null && $rightValue !== '')) return 1;
        if (($rightValue === null || $rightValue === '') && ($leftValue !== null && $leftValue !== '')) return -1;
        if ($leftValue === $rightValue) $result = strcmp((string)($left['datetime'] ?? ''), (string)($right['datetime'] ?? ''));
        elseif (is_numeric($leftValue) && is_numeric($rightValue)) $result = (float)$leftValue <=> (float)$rightValue;
        else $result = strnatcasecmp((string)$leftValue, (string)$rightValue);
        return $sortDirection === 'asc' ? $result : -$result;
    });
    $offset = ($page - 1) * $pageSize;
    foreach (array_slice($allRows, $offset, $pageSize) as $row) {
        $deviceId = (string)($row['device_id'] ?? '');
        $entries[] = [
            'cmd_id' => (string)($row['cmd_id'] ?? ''),
            'timestamp' => (int)($row['timestamp'] ?? 0),
            'datetime' => (string)($row['datetime'] ?? ''),
            'device_id' => $deviceId,
            'device_name' => $devices[$deviceId]['name'] ?? $deviceId,
            'device_role' => (string)(
                ($row['context']['role'] ?? null)
                ?: ($automationConfig['devices'][$deviceId]['role'] ?? '')
            ),
            'parameter' => (string)($row['parameter'] ?? ''),
            'action' => 'write',
            'requested' => $row['requested'] ?? null,
            'actual' => $row['actual'] ?? null,
            'success' => isset($row['success']) ? (bool)$row['success'] : null,
            'source' => (string)($row['source'] ?? ''),
            'reason' => (string)($row['reason'] ?? ''),
            'context' => is_array($row['context'] ?? null) && $row['context']
                ? $row['context'] : (object)[]
        ];
    }
}
$total = count($allRows);
$success = count(array_filter($allRows, fn($entry) => isset($entry['success']) && $entry['success'] === true));
$failed = count(array_filter($allRows, fn($entry) => isset($entry['success']) && $entry['success'] === false));
$latest = null;
if (is_array($latestRow)) {
    $latestDeviceId = (string)($latestRow['device_id'] ?? '');
    $latest = [
        'cmd_id' => (string)($latestRow['cmd_id'] ?? ''),
        'datetime' => (string)($latestRow['datetime'] ?? ''),
        'device_id' => $latestDeviceId,
        'device_name' => $devices[$latestDeviceId]['name'] ?? $latestDeviceId,
        'device_role' => (string)(
            ($latestRow['context']['role'] ?? null)
            ?: ($automationConfig['devices'][$latestDeviceId]['role'] ?? '')
        ),
        'parameter' => (string)($latestRow['parameter'] ?? ''),
        'action' => 'write',
        'requested' => $latestRow['requested'] ?? null
    ];
}
echo json_encode([
    'date' => $date,
    'storage' => $path !== '' && substr($path, -3) === '.gz' ? 'compressed' : 'plain',
    'summary' => [
        'total_24h' => $total,
        'success_24h' => $success,
        'failed_24h' => $failed,
        'latest' => $latest
    ],
    'pagination' => [
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int)ceil($total / $pageSize)),
        'total_entries' => $total,
        'sort_by' => $sortBy,
        'sort_dir' => $sortDirection
    ],
    'entries' => $entries
], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
