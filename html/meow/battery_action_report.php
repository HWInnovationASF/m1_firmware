<?php
require_once __DIR__ . '/session_bootstrap.php';
date_default_timezone_set('Asia/Bangkok');

if (empty($_SESSION['username'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$logDirectory = '/home/mdbcare/python/battery_control_logs';
if (isset($_GET['list_dates'])) {
    $dates = [];
    $files = array_merge(
        glob($logDirectory . '/battery_action_*.txt') ?: [],
        glob($logDirectory . '/battery_action_*.txt.gz') ?: []
    );
    foreach ($files as $file) {
        if (preg_match('/battery_action_(\d{4}-\d{2}-\d{2})\.txt(?:\.gz)?$/', basename($file), $match)
            && filesize($file) > 0) $dates[] = $match[1];
    }
    rsort($dates);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['dates' => array_values(array_unique($dates))], JSON_UNESCAPED_UNICODE);
    exit;
}
$today = new DateTimeImmutable('today');
$fromText = (string)($_GET['from'] ?? $_GET['date'] ?? $today->format('Y-m-d'));
$toText = (string)($_GET['to'] ?? $_GET['date'] ?? $fromText);
$format = strtolower((string)($_GET['format'] ?? 'json'));

function reportDate(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) return null;
    return $date->format('Y-m-d') === $value ? $date : null;
}

$from = reportDate($fromText);
$to = reportDate($toText);
if (!$from || !$to || $from > $to || $from->diff($to)->days > 89 || $to > $today) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Invalid date range. Maximum range is 90 days and future dates are not allowed.']);
    exit;
}

function reportRowsFromFile(string $path): array
{
    $rows = [];
    $compressed = str_ends_with($path, '.gz');
    $handle = $compressed ? @gzopen($path, 'rb') : @fopen($path, 'rb');
    if (!$handle) return $rows;
    try {
        while (($line = $compressed ? gzgets($handle) : fgets($handle)) !== false) {
            $fields = str_getcsv(rtrim($line, "\r\n"), "\t");
            if (($fields[0] ?? '') === 'datetime' || count($fields) < 5) continue;
            $rows[] = [
                'datetime' => (string)$fields[0],
                'p_max' => $fields[1] === '' ? null : (float)$fields[1],
                'e_p_cha' => $fields[2] === '' ? null : (float)$fields[2],
                'e_p_dis' => $fields[3] === '' ? null : (float)$fields[3],
                'reason' => in_array($fields[4], ['on peak', 'off peak', 'solar'], true) ? $fields[4] : 'off peak'
            ];
        }
    } finally {
        $compressed ? gzclose($handle) : fclose($handle);
    }
    return $rows;
}

function energyReportRows(array $rows): array
{
    $reportRows = [];
    $activeCategory = null;
    $previousValue = null;

    foreach ($rows as $row) {
        $category = null;
        $counterValue = null;
        if ($row['p_max'] !== null && $row['p_max'] < 0
            && in_array($row['reason'], ['off peak', 'solar'], true)) {
            $category = 'charge_' . str_replace(' ', '_', $row['reason']);
            $counterValue = $row['e_p_cha'];
        } elseif ($row['p_max'] !== null && $row['p_max'] > 0
            && in_array($row['reason'], ['on peak', 'off peak'], true)) {
            $category = 'discharge_' . str_replace(' ', '_', $row['reason']);
            $counterValue = $row['e_p_dis'];
        }

        // A category change starts a new measurement segment. The first row is
        // only a baseline because energy across two reasons cannot be split safely.
        if ($category === null || $category !== $activeCategory) {
            $activeCategory = $category;
            $previousValue = $counterValue;
            continue;
        }
        // Keep the last valid baseline when a log row has no cumulative value.
        if ($counterValue === null) continue;
        if ($previousValue === null || $counterValue < $previousValue) {
            $previousValue = $counterValue;
            continue;
        }

        $delta = round($counterValue - $previousValue, 3);
        $previousValue = $counterValue;
        $reportRow = [
            'datetime' => $row['datetime'],
            'reason' => $row['reason'],
            'charge_off_peak' => null,
            'charge_solar' => null,
            'discharge_on_peak' => null,
            'discharge_off_peak' => null
        ];
        $reportRow[$category] = $delta;
        $reportRows[] = $reportRow;
    }

    return $reportRows;
}

function reportEnergyValue($value): string
{
    return $value === null ? '' : number_format((float)$value, 3, '.', '');
}

$rows = [];
for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
    $base = $logDirectory . '/battery_action_' . $day->format('Y-m-d') . '.txt';
    $path = is_file($base) ? $base : (is_file($base . '.gz') ? $base . '.gz' : '');
    if ($path !== '') $rows = array_merge($rows, reportRowsFromFile($path));
}
usort($rows, fn($a, $b) => strcmp($a['datetime'], $b['datetime']));
$energyRows = energyReportRows($rows);

$reasonCounts = ['on peak' => 0, 'off peak' => 0, 'solar' => 0];
$pMaxValues = [];
$chargeAdded = 0.0;
$dischargeAdded = 0.0;
$previousCharge = null;
$previousDischarge = null;
foreach ($rows as $row) {
    $reasonCounts[$row['reason']]++;
    if ($row['p_max'] !== null) $pMaxValues[] = $row['p_max'];
    if ($row['e_p_cha'] !== null) {
        if ($previousCharge !== null && $row['e_p_cha'] >= $previousCharge) $chargeAdded += $row['e_p_cha'] - $previousCharge;
        $previousCharge = $row['e_p_cha'];
    }
    if ($row['e_p_dis'] !== null) {
        if ($previousDischarge !== null && $row['e_p_dis'] >= $previousDischarge) $dischargeAdded += $row['e_p_dis'] - $previousDischarge;
        $previousDischarge = $row['e_p_dis'];
    }
}

$energyTotals = [
    'charge_off_peak' => 0.0,
    'charge_solar' => 0.0,
    'discharge_on_peak' => 0.0,
    'discharge_off_peak' => 0.0
];
foreach ($energyRows as $energyRow) {
    foreach ($energyTotals as $key => $_) {
        if ($energyRow[$key] !== null) $energyTotals[$key] += $energyRow[$key];
    }
}

$summary = [
    'commands' => count($rows),
    'on_peak' => $reasonCounts['on peak'],
    'off_peak' => $reasonCounts['off peak'],
    'solar' => $reasonCounts['solar'],
    'p_max_min' => $pMaxValues ? min($pMaxValues) : null,
    'p_max_max' => $pMaxValues ? max($pMaxValues) : null,
    'p_max_avg' => $pMaxValues ? array_sum($pMaxValues) / count($pMaxValues) : null,
    'charge_energy_added' => round($chargeAdded, 3),
    'discharge_energy_added' => round($dischargeAdded, 3),
    'charge_off_peak' => round($energyTotals['charge_off_peak'], 3),
    'charge_solar' => round($energyTotals['charge_solar'], 3),
    'discharge_on_peak' => round($energyTotals['discharge_on_peak'], 3),
    'discharge_off_peak' => round($energyTotals['discharge_off_peak'], 3)
];
$reasonFilter = strtolower(trim((string)($_GET['reason'] ?? '')));
if (!in_array($reasonFilter, ['on peak', 'off peak', 'solar'], true)) $reasonFilter = '';
$directionFilter = strtolower(trim((string)($_GET['direction'] ?? '')));
if (!in_array($directionFilter, ['charge', 'discharge'], true)) $directionFilter = '';
if ($reasonFilter !== '') {
    $rows = array_values(array_filter($rows, fn($row) => $row['reason'] === $reasonFilter));
    $energyRows = array_values(array_filter($energyRows, fn($row) => $row['reason'] === $reasonFilter));
}
if ($directionFilter !== '') {
    $rows = array_values(array_filter($rows, function ($row) use ($directionFilter) {
        if ($row['p_max'] === null) return false;
        return $directionFilter === 'charge' ? $row['p_max'] < 0 : $row['p_max'] > 0;
    }));
    $energyRows = array_values(array_filter($energyRows, function ($row) use ($directionFilter) {
        if ($directionFilter === 'charge') {
            return $row['charge_off_peak'] !== null || $row['charge_solar'] !== null;
        }
        return $row['discharge_on_peak'] !== null || $row['discharge_off_peak'] !== null;
    }));
}
$filteredCommands = count($rows);

if ($format === 'csv') {
    $filename = 'battery_energy_report_' . $from->format('Ymd') . '_' . $to->format('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Battery Energy Report']);
    fputcsv($output, ['From', $from->format('Y-m-d'), 'To', $to->format('Y-m-d')]);
    fputcsv($output, ['Direction Filter', $directionFilter === '' ? 'All' : $directionFilter, 'Reason Filter', $reasonFilter === '' ? 'All' : $reasonFilter, 'Exported Rows', count($energyRows)]);
    fputcsv($output, ['All Commands', $summary['commands'], 'On Peak', $summary['on_peak'], 'Off Peak', $summary['off_peak'], 'Solar', $summary['solar']]);
    fputcsv($output, []);
    fputcsv($output, ['Date / Time', 'Charge (kWh)', '', 'Discharge (kWh)', '']);
    fputcsv($output, ['', 'Off Peak', 'Solar', 'On Peak', 'Off Peak']);
    $totals = [
        'charge_off_peak' => 0.0,
        'charge_solar' => 0.0,
        'discharge_on_peak' => 0.0,
        'discharge_off_peak' => 0.0
    ];
    foreach ($energyRows as $row) {
        foreach ($totals as $key => $_) {
            if ($row[$key] !== null) $totals[$key] += $row[$key];
        }
        fputcsv($output, [
            $row['datetime'],
            reportEnergyValue($row['charge_off_peak']),
            reportEnergyValue($row['charge_solar']),
            reportEnergyValue($row['discharge_on_peak']),
            reportEnergyValue($row['discharge_off_peak'])
        ]);
    }
    fputcsv($output, [
        'Total',
        reportEnergyValue($totals['charge_off_peak']),
        reportEnergyValue($totals['charge_solar']),
        reportEnergyValue($totals['discharge_on_peak']),
        reportEnergyValue($totals['discharge_off_peak'])
    ]);
    fclose($output);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$jsonRows = $rows;
$pagination = null;
if (isset($_GET['page_size'])) {
    $sortBy = (string)($_GET['sort_by'] ?? 'datetime');
    $sortDirection = strtolower((string)($_GET['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
    if (!in_array($sortBy, ['datetime', 'p_max', 'e_p_cha', 'e_p_dis', 'reason'], true)) $sortBy = 'datetime';
    $sortedRows = $rows;
    usort($sortedRows, function ($left, $right) use ($sortBy, $sortDirection) {
        $leftValue = $left[$sortBy] ?? null;
        $rightValue = $right[$sortBy] ?? null;
        if (($leftValue === null || $leftValue === '') && ($rightValue !== null && $rightValue !== '')) return 1;
        if (($rightValue === null || $rightValue === '') && ($leftValue !== null && $leftValue !== '')) return -1;
        if ($leftValue === $rightValue) $result = strcmp($left['datetime'], $right['datetime']);
        elseif (is_numeric($leftValue) && is_numeric($rightValue)) $result = (float)$leftValue <=> (float)$rightValue;
        else $result = strnatcasecmp((string)$leftValue, (string)$rightValue);
        return $sortDirection === 'asc' ? $result : -$result;
    });
    $pageSize = max(5, min(500, (int)$_GET['page_size']));
    $totalEntries = count($rows);
    $totalPages = max(1, (int)ceil($totalEntries / $pageSize));
    $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
    $jsonRows = array_slice($sortedRows, ($page - 1) * $pageSize, $pageSize);
    $pagination = [
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => $totalPages,
        'total_entries' => $totalEntries,
        'sort_by' => $sortBy,
        'sort_dir' => $sortDirection
    ];
} else {
    $limit = max(0, min(500, (int)($_GET['limit'] ?? 0)));
    if ($limit > 0) $jsonRows = array_reverse(array_slice($rows, -$limit));
}
echo json_encode([
    'from' => $from->format('Y-m-d'),
    'to' => $to->format('Y-m-d'),
    'reason_filter' => $reasonFilter,
    'direction_filter' => $directionFilter,
    'filtered_commands' => $filteredCommands,
    'summary' => $summary,
    'pagination' => $pagination,
    'rows' => $jsonRows
], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
