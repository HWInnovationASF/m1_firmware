<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Bangkok');

$period = $_GET['period'] ?? 'day';
$metric = ($_GET['metric'] ?? 'power') === 'energy' ? 'energy' : 'power';
$tailOnly = ($_GET['tail'] ?? '') === '1';
$intervalMinutes = (int)($_GET['interval'] ?? 60);
$allowedIntervals = [1, 5, 10, 15, 60];
if (!in_array($intervalMinutes, $allowedIntervals, true)) $intervalMinutes = 60;
$meter = trim((string)($_GET['meterSN'] ?? ''));
$sign = ($_GET['sign'] ?? 'import') === 'export' ? -1 : 1;
if (!in_array($period, ['day', 'month', 'year'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid period']);
    exit;
}

$devices = json_decode(@file_get_contents('./config/dvl.json'), true) ?: [];
$details = json_decode(@file_get_contents('./config/detail_rgl.json'), true) ?: [];
$mainConfig = json_decode(@file_get_contents('./data/mcf_default.json'), true) ?: [];
if ($meter === '' || !isset($devices[$meter])) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid meter']);
    exit;
}

$clockNow = new DateTimeImmutable('now');
$now = $clockNow;
if ($period === 'day') {
    $requestedDate = trim((string)($_GET['date'] ?? $clockNow->format('Y-m-d')));
    $requestedDay = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$requestedDay || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $requestedDay->format('Y-m-d') !== $requestedDate || $requestedDay > $clockNow->setTime(0, 0)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid date']);
        exit;
    }
    $now = $requestedDate === $clockNow->format('Y-m-d')
        ? $clockNow
        : $requestedDay->setTime(23, 59, 59);
    $elapsedMinutes = ((int)$now->format('G') * 60) + (int)$now->format('i');
    if ($tailOnly && $requestedDate === $clockNow->format('Y-m-d')) {
        $bucketStartMinutes = (int)floor($elapsedMinutes / $intervalMinutes) * $intervalMinutes;
        $start = $requestedDay->modify('+' . $bucketStartMinutes . ' minutes');
        $bucketCount = 1;
        $bucketKey = fn(int $ts): int => 0;
        $labels = [sprintf('%02d:%02d', intdiv($bucketStartMinutes, 60), $bucketStartMinutes % 60)];
    } else {
        $start = $requestedDay->setTime(0, 0);
        $bucketCount = min((int)(1440 / $intervalMinutes), (int)floor($elapsedMinutes / $intervalMinutes) + 1);
        $bucketKey = fn(int $ts): int => (int)floor((((int)date('G', $ts) * 60) + (int)date('i', $ts)) / $intervalMinutes);
        $labels = array_map(function ($index) use ($intervalMinutes) {
            $minutes = $index * $intervalMinutes;
            return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }, range(0, $bucketCount - 1));
    }
} elseif ($period === 'month') {
    $requestedMonth = trim((string)($_GET['month'] ?? $clockNow->format('Y-m')));
    $requestedMonthDate = DateTimeImmutable::createFromFormat('!Y-m', $requestedMonth);
    $monthErrors = DateTimeImmutable::getLastErrors();
    if (!$requestedMonthDate || ($monthErrors !== false && ($monthErrors['warning_count'] > 0 || $monthErrors['error_count'] > 0))
        || $requestedMonthDate->format('Y-m') !== $requestedMonth
        || $requestedMonthDate > $clockNow->modify('first day of this month')->setTime(0, 0)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid month']);
        exit;
    }
    $start = $requestedMonthDate->setTime(0, 0);
    $now = $requestedMonth === $clockNow->format('Y-m')
        ? $clockNow
        : $requestedMonthDate->modify('last day of this month')->setTime(23, 59, 59);
    $bucketCount = (int)$now->format('j');
    $bucketKey = fn(int $ts): int => (int)date('j', $ts) - 1;
    $labels = array_map(fn($day) => sprintf('%02d/%s', $day, $requestedMonthDate->format('m')), range(1, $bucketCount));
} else {
    $requestedYear = trim((string)($_GET['year'] ?? $clockNow->format('Y')));
    if (!preg_match('/^\d{4}$/', $requestedYear)
        || (int)$requestedYear < 2000
        || (int)$requestedYear > (int)$clockNow->format('Y')) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid year']);
        exit;
    }
    $requestedYearDate = new DateTimeImmutable($requestedYear . '-01-01 00:00:00');
    $start = $requestedYearDate;
    $now = $requestedYear === $clockNow->format('Y')
        ? $clockNow
        : $requestedYearDate->setDate((int)$requestedYear, 12, 31)->setTime(23, 59, 59);
    $bucketCount = 12;
    $bucketKey = fn(int $ts): int => (int)date('n', $ts) - 1;
    $labels = array_map(fn($month) => sprintf('%02d/%s', $month, substr($requestedYear, -2)), range(1, 12));
}

$startTs = $start->getTimestamp();
$endTs = $now->getTimestamp();
$sourceNames = ['solar', 'grid', 'battery', 'battery_charge', 'battery_discharge', 'consumption'];
$energy = [];
foreach ($sourceNames as $source) $energy[$source] = array_fill(0, $bucketCount, 0.0);
$durationHours = array_fill(0, $bucketCount, 0.0);
$summaryEnergy = ['today' => 0.0, 'month' => 0.0, 'year' => 0.0];
$solarSummaryEnergy = ['today' => 0.0, 'month' => 0.0, 'year' => 0.0];
$batteryChargeSummaryEnergy = ['today' => 0.0, 'month' => 0.0, 'year' => 0.0];
$batteryDischargeSummaryEnergy = ['today' => 0.0, 'month' => 0.0, 'year' => 0.0];
$summaryStarts = [
    'today' => $clockNow->setTime(0, 0)->getTimestamp(),
    'month' => $clockNow->modify('first day of this month')->setTime(0, 0)->getTimestamp(),
    'year' => $clockNow->modify('first day of January')->setTime(0, 0)->getTimestamp()
];
$samples = [];
$solarDevices = [];
$batteryDevices = [];
foreach ($devices as $deviceId => $device) {
    $type = $details[$device['model'] ?? '']['type'] ?? '';
    if ($type === 'inverter') $solarDevices[] = $deviceId;
    if ($type === 'battery') $batteryDevices[] = $deviceId;
}
$solarDeviceEnergy = array_fill_keys($solarDevices, 0.0);
$batterySign = ($_GET['batterySign'] ?? 'discharge') === 'charge' ? -1 : 1;
$deviceSN = $mainConfig['deviceSN'] ?? '';
$logDir = "/home/mdbcare/python/dlog/{$deviceSN}";

if (is_dir($logDir)) {
    foreach (scandir($logDir) ?: [] as $file) {
        if (!preg_match('/_log5_\d{12}\.txt(?:\.gz)?$/', $file)) continue;
        if (preg_match('/_log5_(\d{12})/', $file, $match)) {
            $fileTs = DateTimeImmutable::createFromFormat('YmdHi', $match[1]);
            if ($fileTs) {
                $candidateTs = $fileTs->getTimestamp();
                if ($candidateTs < $startTs - 900 || $candidateTs > $endTs + 900) continue;
            }
        }
        $compressed = str_ends_with($file, '.gz');
        $handle = $compressed
            ? @gzopen($logDir . '/' . $file, 'rb')
            : @fopen($logDir . '/' . $file, 'r');
        if (!$handle) continue;
        while (($line = $compressed ? gzgets($handle) : fgets($handle)) !== false) {
            $separator = strpos($line, '|');
            if ($separator === false) continue;
            $ts = (int)substr($line, 0, $separator);
            if ($ts < $startTs - 900 || $ts > $endTs) continue;
            $payload = json_decode(substr($line, $separator + 1), true);
            $readings = $payload['data'] ?? [];
            $gridValue = $readings[$meter]['p_t'] ?? null;
            if (!is_numeric($gridValue)) continue;
            $solarPower = 0.0;
            $solarDevicePower = array_fill_keys($solarDevices, 0.0);
            foreach ($solarDevices as $deviceId) {
                $reading = $readings[$deviceId] ?? null;
                if (!is_array($reading)) continue;
                $value = $reading['p_out'] ?? $reading['p_t'] ?? null;
                if (is_numeric($value)) {
                    $devicePower = max(0.0, (float)$value);
                    $solarPower += $devicePower;
                    $solarDevicePower[$deviceId] = $devicePower;
                }
            }
            $batteryPower = 0.0;
            foreach ($batteryDevices as $deviceId) {
                $value = $readings[$deviceId]['p_t'] ?? null;
                if (is_numeric($value)) $batteryPower += (float)$value * $batterySign;
            }
            $gridPower = (float)$gridValue * $sign;
            // Match the live Energy Flow: charging is also consumption.
            // Battery remains signed in its own series, while consumption
            // includes the positive magnitude of charge or discharge.
            $actualConsumption = max(0.0, $solarPower + $gridPower + abs($batteryPower));
            $samples[$ts] = [
                'solar' => $solarPower,
                'grid' => $gridPower,
                'battery' => $batteryPower,
                'battery_charge' => max(0.0, -$batteryPower),
                'battery_discharge' => max(0.0, $batteryPower),
                'consumption' => $actualConsumption,
                'solar_devices' => $solarDevicePower
            ];
        }
        $compressed ? gzclose($handle) : fclose($handle);
    }
}

ksort($samples, SORT_NUMERIC);
$previousTs = null;
$previousPower = null;
$acceptedIntervals = 0;
foreach ($samples as $ts => $power) {
    $ts = (int)$ts;
    if ($previousTs !== null) {
        $seconds = $ts - $previousTs;
        // Do not invent consumption across missing log intervals.
        if ($seconds > 0 && $seconds <= 900 && $ts >= $startTs) {
            $index = $bucketKey($ts);
            if ($index >= 0 && $index < $bucketCount) {
                foreach ($sourceNames as $source) {
                    $energy[$source][$index] += (($previousPower[$source] + $power[$source]) / 2) * ($seconds / 3600);
                }
                foreach ($solarDevices as $deviceId) {
                    $solarDeviceEnergy[$deviceId] += ((($previousPower['solar_devices'][$deviceId] ?? 0.0) + ($power['solar_devices'][$deviceId] ?? 0.0)) / 2) * ($seconds / 3600);
                }
                if ($period === 'year') {
                    $averageConsumption = ($previousPower['consumption'] + $power['consumption']) / 2;
                    $averageSolar = ($previousPower['solar'] + $power['solar']) / 2;
                    $averageBatteryCharge = (max(0.0, -$previousPower['battery']) + max(0.0, -$power['battery'])) / 2;
                    $averageBatteryDischarge = (max(0.0, $previousPower['battery']) + max(0.0, $power['battery'])) / 2;
                    foreach ($summaryStarts as $summaryKey => $summaryStart) {
                        $overlapSeconds = max(0, min($ts, $endTs) - max($previousTs, $summaryStart));
                        $summaryEnergy[$summaryKey] += $averageConsumption * ($overlapSeconds / 3600);
                        $solarSummaryEnergy[$summaryKey] += $averageSolar * ($overlapSeconds / 3600);
                        $batteryChargeSummaryEnergy[$summaryKey] += $averageBatteryCharge * ($overlapSeconds / 3600);
                        $batteryDischargeSummaryEnergy[$summaryKey] += $averageBatteryDischarge * ($overlapSeconds / 3600);
                    }
                }
                $durationHours[$index] += $seconds / 3600;
                $acceptedIntervals++;
            }
        }
    }
    $previousTs = $ts;
    $previousPower = $power;
}

$series = [];
foreach ($sourceNames as $source) {
    $values = [];
    foreach ($energy[$source] as $index => $value) {
        $values[] = $durationHours[$index] > 0
            ? round($metric === 'energy' ? $value : $value / $durationHours[$index], 2)
            : null;
    }
    $totalDuration = array_sum($durationHours);
    $series[$source] = [
        'values' => $values,
        'average_kw' => $totalDuration > 0 ? round(array_sum($energy[$source]) / $totalDuration, 2) : 0
    ];
}
$sourceBucketCount = count($labels);
$maxPlotPoints = 1500;
$plotStep = max(1, (int)ceil($sourceBucketCount / $maxPlotPoints));
if ($plotStep > 1) {
    $indices = range(0, $sourceBucketCount - 1, $plotStep);
    if (end($indices) !== $sourceBucketCount - 1) $indices[] = $sourceBucketCount - 1;
    $labels = array_map(fn($index) => $labels[$index], $indices);
    foreach ($sourceNames as $source) {
        $series[$source]['values'] = array_map(
            fn($index) => $series[$source]['values'][$index],
            $indices
        );
    }
}
$overallAverage = $series['consumption']['average_kw'];
echo json_encode([
    'period' => $period,
    'year' => $period === 'year' ? $requestedYear : null,
    'metric' => $metric,
    'interval_minutes' => $period === 'day' ? $intervalMinutes : null,
    'source_bucket_count' => $sourceBucketCount,
    'plot_step' => $plotStep,
    'meter' => $meter,
    'start' => $startTs,
    'end' => $endTs,
    'labels' => $labels,
    'series' => $series,
    'consumption_summary_kwh' => array_map(fn($value) => round($value, 3), $summaryEnergy),
    'solar_summary_kwh' => array_map(fn($value) => round($value, 3), $solarSummaryEnergy),
    'solar_devices_kwh' => array_map(fn($value) => round($value, 3), $solarDeviceEnergy),
    'battery_charge_summary_kwh' => array_map(fn($value) => round($value, 3), $batteryChargeSummaryEnergy),
    'battery_discharge_summary_kwh' => array_map(fn($value) => round($value, 3), $batteryDischargeSummaryEnergy),
    'average_kw' => round($overallAverage, 2),
    'sample_count' => count($samples),
    'interval_count' => $acceptedIntervals
], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
