<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");

date_default_timezone_set("Asia/Bangkok");

function dlog_is_readable_file(string $file): bool {
    return preg_match('/_log5_\d{12}\.txt(?:\.gz)?$/', $file) === 1;
}

function dlog_read_file(string $path) {
    $content = @file_get_contents($path);
    if ($content === false) return false;
    return str_ends_with($path, '.gz') ? @gzdecode($content) : $content;
}

function dlog_file_timestamp(string $file): ?int {
    if (!preg_match('/_log5_(\d{12})\.txt(?:\.gz)?$/', $file, $match)) return null;
    $date = DateTime::createFromFormat('!YmdHi', $match[1], new DateTimeZone('Asia/Bangkok'));
    return $date instanceof DateTime ? $date->getTimestamp() : null;
}

function dlog_file_in_range(string $file, ?int $startTs, ?int $endTs): bool {
    $fileTs = dlog_file_timestamp($file);
    if ($fileTs === null) return false;
    // One log5 file normally covers five minutes.  Keep a small allowance for
    // delayed writes while excluding files that cannot contain the requested range.
    if ($startTs !== null && ($fileTs + 600) < $startTs) return false;
    if ($endTs !== null && ($fileTs - 600) > $endTs) return false;
    return true;
}

function dlog_each_line(string $path, callable $reader): bool {
    $gzip = str_ends_with($path, '.gz');
    $handle = $gzip ? @gzopen($path, 'rb') : @fopen($path, 'rb');
    if ($handle === false) return false;
    try {
        while (($line = $gzip ? gzgets($handle) : fgets($handle)) !== false) {
            if ($reader($line) === false) break;
        }
    } finally {
        $gzip ? gzclose($handle) : fclose($handle);
    }
    return true;
}

function dlog_request_timestamp(string $epochKey, string $dateKey): ?int {
    $epoch = $_GET[$epochKey] ?? $_POST[$epochKey] ?? null;
    if ($epoch !== null && filter_var($epoch, FILTER_VALIDATE_INT) !== false) {
        return (int)$epoch;
    }
    $dateText = trim((string)($_GET[$dateKey] ?? $_POST[$dateKey] ?? ''));
    if ($dateText === '') return null;
    $timestamp = strtotime($dateText);
    return $timestamp === false ? null : $timestamp;
}

function dlog_model_unit_map($registerMap): array {
    $units = [];
    if (!is_array($registerMap)) return $units;
    $banks = (isset($registerMap['holding']) || isset($registerMap['input']))
        ? [$registerMap['holding'] ?? [], $registerMap['input'] ?? []]
        : [$registerMap];
    foreach ($banks as $bank) {
        foreach (is_array($bank) ? $bank : [] as $register) {
            if (!is_array($register)) continue;
            $name = (string)($register['name'] ?? '');
            if ($name !== '' && !array_key_exists($name, $units)) {
                $units[$name] = (string)($register['unit'] ?? '');
            }
        }
    }
    return $units;
}

/**
 * -------------------------
 * 1. SAFE INPUT
 * -------------------------
 */
$mode    = $_GET['mode'] ?? $_POST['mode'] ?? null;
$meterSN = $_GET['meterSN'] ?? $_POST['meterSN'] ?? '';
$paraStr = $_GET['para'] ?? $_POST['para'] ?? '';

if (!in_array($mode, ["stockchart", "batterysummary", "batterysoc"], true)) {
    echo json_encode(["error" => "invalid mode"]);
    exit;
}

/**
 * -------------------------
 * 2. LOAD CONFIG
 * -------------------------
 */
$data_all = json_decode(file_get_contents('../data/mcf_default.json'), true);
$dvl_arr  = json_decode(file_get_contents('../config/dvl.json'), true);
$model_arr = json_decode(file_get_contents('../config/rgl.json'), true);
$detail_arr = json_decode(file_get_contents('../config/detail_rgl.json'), true);
$parameter_list = json_decode(@file_get_contents('../config/parameter_list.json'), true);
$parameter_names = [];
$parameter_units = [];
foreach (is_array($parameter_list) ? $parameter_list : [] as $parameter_item) {
    $parameter_code = (string)($parameter_item['Parameter'] ?? '');
    $parameter_name = (string)($parameter_item['InfoData']['Name'] ?? '');
    $parameter_unit = trim((string)($parameter_item['InfoData']['Unit'] ?? ''));
    if ($parameter_code !== '' && $parameter_name !== '') {
        $parameter_names[$parameter_code] = $parameter_name;
    }
    if ($parameter_code !== '' && $parameter_unit !== '') {
        $parameter_units[$parameter_code] = $parameter_unit;
    }
}

/**
 * -------------------------
 * 3. HANDLE MULTI meterSN
 * -------------------------
 */
$meterSNs = array_filter(array_map('trim', explode(',', $meterSN)));
$para_arr = array_filter(array_map('trim', explode(',', $paraStr)));

$rootpath = "/home/mdbcare";
$deviceSN_f = $data_all['deviceSN'] ?? "";

$dir = "{$rootpath}/python/dlog/{$deviceSN_f}";

if (!is_dir($dir)) {
    echo json_encode(["error" => "directory not found"]);
    exit;
}

/**
 * -------------------------
 * 4. SCAN FILES (ONLY ONCE)
 * -------------------------
 */
$files = scandir($dir, SCANDIR_SORT_DESCENDING);
$rangeStartTs = dlog_request_timestamp('start_ts', 'start');
$rangeEndTs = dlog_request_timestamp('end_ts', 'end');
if ($rangeStartTs !== null && $rangeEndTs !== null && $rangeStartTs > $rangeEndTs) {
    http_response_code(400);
    echo json_encode(['error' => 'start must be before end']);
    exit;
}
$rangeFiles = array_values(array_filter(
    $files,
    fn($file) => dlog_is_readable_file($file) && dlog_file_in_range($file, $rangeStartTs, $rangeEndTs)
));

if ($mode === "batterysoc") {
    $sn = $meterSNs[0] ?? '';
    $model = $dvl_arr[$sn]['model'] ?? null;
    if (!$model || ($detail_arr[$model]['type'] ?? '') !== 'battery') {
        echo json_encode(['error' => 'battery device required']);
        exit;
    }

    $startTs = $rangeStartTs;
    $endTs = $rangeEndTs;
    $buckets = [];

    foreach ($rangeFiles as $file) {
        if (
            $file === '.' || $file === '..' ||
            !dlog_is_readable_file($file)
        ) continue;

        $content = dlog_read_file($dir . '/' . $file);
        if (!$content) continue;

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '|')) continue;

            [$ts, $jsonLine] = explode('|', $line, 2);
            $ts = (int)$ts;
            if (($startTs !== null && $ts < $startTs) || ($endTs !== null && $ts > $endTs)) continue;

            $json = json_decode($jsonLine, true);
            $reading = $json['data'][$sn] ?? null;
            if (!is_array($reading) || !isset($reading['soc'], $reading['cell_v_max'])) continue;

            $minKey = array_key_exists('cell_v_min', $reading)
                ? 'cell_v_min'
                : (array_key_exists('cel_v_min', $reading) ? 'cel_v_min' : null);
            if ($minKey === null) continue;

            $soc = (float)$reading['soc'];
            $cellMax = (float)$reading['cell_v_max'];
            $cellMin = (float)$reading[$minKey];
            if ($soc < 0 || $soc > 100 || $cellMax <= 0 || $cellMin <= 0) continue;

            $socBucket = number_format(round($soc * 10) / 10, 1, '.', '');
            $deltaMv = max(0, $cellMax - $cellMin) * 1000;
            if (!isset($buckets[$socBucket])) {
                $buckets[$socBucket] = [
                    'soc' => (float)$socBucket,
                    'count' => 0,
                    'minSum' => 0,
                    'maxSum' => 0,
                    'deltaSum' => 0,
                    'minLowest' => $cellMin,
                    'maxHighest' => $cellMax,
                    'deltaBest' => $deltaMv,
                    'deltaWorst' => $deltaMv
                ];
            }

            $bucket = &$buckets[$socBucket];
            $bucket['count']++;
            $bucket['minSum'] += $cellMin;
            $bucket['maxSum'] += $cellMax;
            $bucket['deltaSum'] += $deltaMv;
            $bucket['minLowest'] = min($bucket['minLowest'], $cellMin);
            $bucket['maxHighest'] = max($bucket['maxHighest'], $cellMax);
            $bucket['deltaBest'] = min($bucket['deltaBest'], $deltaMv);
            $bucket['deltaWorst'] = max($bucket['deltaWorst'], $deltaMv);
            unset($bucket);
        }
    }

    $rows = [];
    foreach ($buckets as $bucket) {
        $count = max(1, $bucket['count']);
        $deltaAvg = $bucket['deltaSum'] / $count;
        if ($deltaAvg <= 10) {
            [$level, $color] = ['ดีเยี่ยม', '#16a34a'];
        } elseif ($deltaAvg <= 20) {
            [$level, $color] = ['ดี', '#65a30d'];
        } elseif ($deltaAvg <= 30) {
            [$level, $color] = ['ปกติ', '#eab308'];
        } elseif ($deltaAvg <= 50) {
            [$level, $color] = ['เริ่มไม่สมดุล', '#f97316'];
        } elseif ($deltaAvg <= 100) {
            [$level, $color] = ['ควรตรวจสอบ', '#dc2626'];
        } else {
            [$level, $color] = ['ผิดปกติ', '#7f1d1d'];
        }

        $rows[] = [
            'soc' => $bucket['soc'],
            'count' => $bucket['count'],
            'cellVMinAvg' => round($bucket['minSum'] / $count, 4),
            'cellVMaxAvg' => round($bucket['maxSum'] / $count, 4),
            'cellVMinLowest' => round($bucket['minLowest'], 4),
            'cellVMaxHighest' => round($bucket['maxHighest'], 4),
            'deltaAvg' => round($deltaAvg, 1),
            'deltaBest' => round($bucket['deltaBest'], 1),
            'deltaWorst' => round($bucket['deltaWorst'], 1),
            'level' => $level,
            'color' => $color
        ];
    }
    usort($rows, fn($a, $b) => $b['soc'] <=> $a['soc']);

    echo json_encode([
        'device' => $sn,
        'start' => $startTs,
        'end' => $endTs,
        'rows' => $rows
    ], JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mode === "batterysummary") {
    $startTs = $rangeStartTs;
    $endTs = $rangeEndTs;
    $summaries = [];

    foreach ($meterSNs as $sn) {
        $model = $dvl_arr[$sn]['model'] ?? null;
        if (!$model || ($detail_arr[$model]['type'] ?? '') !== 'battery') continue;

        $best = null;
        $chargeRecommendation = null;
        $dischargeRecommendation = null;
        $chargeFallback = null;
        $dischargeFallback = null;
        $sampleCount = 0;

        foreach ($rangeFiles as $file) {
            if (
                $file === '.' || $file === '..' ||
                !dlog_is_readable_file($file)
            ) continue;

            $content = dlog_read_file($dir . '/' . $file);
            if (!$content) continue;

            foreach (explode("\n", $content) as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, '|')) continue;

                [$ts, $jsonLine] = explode('|', $line, 2);
                $ts = (int)$ts;
                if (($startTs !== null && $ts < $startTs) || ($endTs !== null && $ts > $endTs)) continue;

                $json = json_decode($jsonLine, true);
                $reading = $json['data'][$sn] ?? null;
                if (!is_array($reading) || !isset($reading['cell_v_max'], $reading['soc'])) continue;

                $minKey = array_key_exists('cell_v_min', $reading)
                    ? 'cell_v_min'
                    : (array_key_exists('cel_v_min', $reading) ? 'cel_v_min' : null);
                if ($minKey === null) continue;

                $cellMax = (float)$reading['cell_v_max'];
                $cellMin = (float)$reading[$minKey];
                $soc = (float)$reading['soc'];
                $power = isset($reading['p_t']) ? (float)$reading['p_t'] : null;
                if ($cellMax <= 0 || $cellMin <= 0 || $soc < 0 || $soc > 100) continue;

                $deltaMv = round(max(0, $cellMax - $cellMin) * 1000, 1);
                $sampleCount++;

                if ($best === null || $deltaMv < $best['deltaMv'] || ($deltaMv == $best['deltaMv'] && $ts > $best['timestamp'])) {
                    $best = [
                        'device' => $sn,
                        'timestamp' => $ts,
                        'soc' => $soc,
                        'deltaMv' => $deltaMv,
                        'cellMax' => $cellMax,
                        'cellMin' => $cellMin
                    ];
                }

                $modePoint = [
                    'timestamp' => $ts,
                    'soc' => $soc,
                    'deltaMv' => $deltaMv,
                    'cellMax' => $cellMax,
                    'cellMin' => $cellMin,
                    'power' => $power
                ];

                if ($power !== null && $power < -0.01) {
                    if ($chargeFallback === null || $deltaMv < $chargeFallback['deltaMv']) {
                        $chargeFallback = $modePoint;
                    }
                    if (
                        $deltaMv <= 20 &&
                        ($chargeRecommendation === null ||
                         $soc > $chargeRecommendation['soc'] ||
                         ($soc == $chargeRecommendation['soc'] && $deltaMv < $chargeRecommendation['deltaMv']))
                    ) {
                        $chargeRecommendation = $modePoint;
                    }
                } elseif ($power !== null && $power > 0.01) {
                    if ($dischargeFallback === null || $deltaMv < $dischargeFallback['deltaMv']) {
                        $dischargeFallback = $modePoint;
                    }
                    if (
                        $deltaMv <= 20 &&
                        ($dischargeRecommendation === null ||
                         $soc < $dischargeRecommendation['soc'] ||
                         ($soc == $dischargeRecommendation['soc'] && $deltaMv < $dischargeRecommendation['deltaMv']))
                    ) {
                        $dischargeRecommendation = $modePoint;
                    }
                }
            }
        }

        if ($best !== null) {
            if ($best['deltaMv'] <= 10) {
                [$best['level'], $best['advice'], $best['color']] = ['ดีเยี่ยม', 'เซลล์สมดุลมาก', '#16a34a'];
            } elseif ($best['deltaMv'] <= 20) {
                [$best['level'], $best['advice'], $best['color']] = ['ดี', 'เซลล์มีความสมดุลดี', '#65a30d'];
            } elseif ($best['deltaMv'] <= 30) {
                [$best['level'], $best['advice'], $best['color']] = ['ปกติ', 'ควรติดตามแนวโน้ม', '#eab308'];
            } elseif ($best['deltaMv'] <= 50) {
                [$best['level'], $best['advice'], $best['color']] = ['เริ่มไม่สมดุล', 'ควรเฝ้าระวังและตรวจสอบ', '#f97316'];
            } elseif ($best['deltaMv'] <= 100) {
                [$best['level'], $best['advice'], $best['color']] = ['ควรตรวจสอบ', 'ควรตรวจสอบและทำ Balancing', '#dc2626'];
            } else {
                [$best['level'], $best['advice'], $best['color']] = ['ผิดปกติ', 'ควรลดกำลัง Charge/Discharge และตรวจสอบ', '#7f1d1d'];
            }
            $best['sampleCount'] = $sampleCount;
            $chargeRecommendation = $chargeRecommendation ?? $chargeFallback;
            $dischargeRecommendation = $dischargeRecommendation ?? $dischargeFallback;

            $recommendationPairs = [
                'charge' => $chargeRecommendation,
                'discharge' => $dischargeRecommendation
            ];
            foreach ($recommendationPairs as $recommendationMode => &$recommendation) {
                if ($recommendation === null) continue;
                if ($recommendation['deltaMv'] <= 10) {
                    [$recommendation['level'], $recommendation['color']] = ['ดีเยี่ยม', '#16a34a'];
                } elseif ($recommendation['deltaMv'] <= 20) {
                    [$recommendation['level'], $recommendation['color']] = ['ดี', '#65a30d'];
                } elseif ($recommendation['deltaMv'] <= 30) {
                    [$recommendation['level'], $recommendation['color']] = ['ปกติ', '#eab308'];
                } elseif ($recommendation['deltaMv'] <= 50) {
                    [$recommendation['level'], $recommendation['color']] = ['เริ่มไม่สมดุล', '#f97316'];
                } elseif ($recommendation['deltaMv'] <= 100) {
                    [$recommendation['level'], $recommendation['color']] = ['ควรตรวจสอบ', '#dc2626'];
                } else {
                    [$recommendation['level'], $recommendation['color']] = ['ผิดปกติ', '#7f1d1d'];
                }
                $recommendation['mode'] = $recommendationMode;
            }
            unset($recommendation);

            $best['recommendations'] = $recommendationPairs;
            $summaries[$sn] = $best;
        }
    }

    echo json_encode(['summaries' => $summaries], JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * -------------------------
 * 5. BUILD RESPONSE
 * -------------------------
 */
$colors = ['#FF0000','#0000FF','#00AA00','#FFA500','#800080'];

$series = [];
$definitions = [];
$results = [];
$pointLimit = 999999;

foreach ($meterSNs as $kx => $sn) {
    if (!isset($dvl_arr[$sn])) continue;
    $model = $dvl_arr[$sn]['model'] ?? null;
    if (!$model || !isset($model_arr[$model])) continue;
    $unitMap = dlog_model_unit_map($model_arr[$model]);
    foreach ($para_arr as $idx => $parameter) {
        $key = $sn . "\x1f" . $parameter;
        $definitions[$key] = [
            'meter' => $sn,
            'parameter' => $parameter,
            'meter_index' => $kx,
            'parameter_index' => $idx,
            'unit' => $parameter_units[$parameter] ?? ($unitMap[$parameter] ?? '')
        ];
        $results[$key] = [];
    }
}

// Read every selected TXT/TXT.GZ file only once, then distribute each reading
// to the requested series.  GZIP is streamed and never expanded on disk.
foreach ($rangeFiles as $file) {
    $path = $dir . '/' . $file;
    dlog_each_line($path, function ($line) use (
        &$results, $definitions, $meterSNs, $para_arr,
        $rangeStartTs, $rangeEndTs, $pointLimit
    ) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, '|')) return true;
        [$timestampText, $jsonLine] = explode('|', $line, 2);
        $timestamp = (int)$timestampText;
        if (($rangeStartTs !== null && $timestamp < $rangeStartTs) ||
            ($rangeEndTs !== null && $timestamp > $rangeEndTs)) return true;
        $json = json_decode($jsonLine, true);
        $data = is_array($json) ? ($json['data'] ?? null) : null;
        if (!is_array($data)) return true;
        foreach ($meterSNs as $sn) {
            $reading = $data[$sn] ?? null;
            if (!is_array($reading)) continue;
            foreach ($para_arr as $parameter) {
                $key = $sn . "\x1f" . $parameter;
                if (!isset($definitions[$key]) || !isset($reading[$parameter])) continue;
                if (count($results[$key]) >= $pointLimit) continue;
                $results[$key][] = [$timestamp * 1000, (float)$reading[$parameter]];
            }
        }
        return true;
    });
}

foreach ($definitions as $key => $definition) {
    $result = $results[$key];
    if (empty($result)) continue;
    usort($result, fn($a, $b) => $a[0] <=> $b[0]);
    $sn = $definition['meter'];
    $parameter = $definition['parameter'];
    $colorIndex = ($definition['meter_index'] * max(count($para_arr), 1)) + $definition['parameter_index'];
    $displayDevice = trim((string)($dvl_arr[$sn]['name'] ?? '')) ?: $sn;
    $displayParameter = $parameter_names[$parameter] ?? $parameter;
    $series[] = [
        'name' => "{$displayDevice} - {$displayParameter}",
        'color' => $colors[$colorIndex % count($colors)],
        'unit' => $definition['unit'],
        'data' => $result
    ];
}

echo json_encode($series, JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
exit;
