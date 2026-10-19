<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['username']) || !in_array(strtolower($_SESSION['user_type'] ?? ''), ['admin', 'superadmin'], true)) {
    http_response_code(403);
    echo 'Access denied';
    return;
}
date_default_timezone_set('Asia/Bangkok');

$bchLogDirectory = '/home/mdbcare/python/battery_control_logs';
$bchConfig = json_decode(@file_get_contents('./config/automation_control.json'), true) ?: [];
$bchDevices = json_decode(@file_get_contents('./config/dvl.json'), true) ?: [];
$bchBatteryIds = [];
foreach (($bchConfig['devices'] ?? []) as $bchDeviceId => $bchControl) {
    if (is_array($bchControl) && strtolower((string)($bchControl['role'] ?? '')) === 'battery') {
        $bchBatteryIds[(string)$bchDeviceId] = true;
    }
}

$bchDates = [];
foreach (array_merge(
    glob($bchLogDirectory . '/battery_control_*.jsonl') ?: [],
    glob($bchLogDirectory . '/battery_control_*.jsonl.gz') ?: []
) as $bchFile) {
    if (preg_match('/battery_control_(\d{4}-\d{2}-\d{2})\.jsonl(?:\.gz)?$/', basename($bchFile), $bchMatch)) {
        $bchDates[$bchMatch[1]] = true;
    }
}
$bchDates = array_keys($bchDates);
rsort($bchDates);
$bchDate = (string)($_GET['date'] ?? ($bchDates[0] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bchDate)) $bchDate = date('Y-m-d');
$bchWindow = (int)($_GET['window'] ?? 300);
if (!in_array($bchWindow, [30, 60, 120, 300, 600], true)) $bchWindow = 300;
$bchPageSize = (int)($_GET['page_size'] ?? 5);
if (!in_array($bchPageSize, [5, 10, 25, 50, 100, 200, 500], true)) $bchPageSize = 5;
$bchView = strtolower((string)($_GET['view'] ?? 'matched'));
if (!in_array($bchView, ['matched', 'all', 'unmatched'], true)) $bchView = 'matched';
$bchSortBy = strtolower((string)($_GET['sort_by'] ?? 'time'));
if (!in_array($bchSortBy, ['time', 'battery', 'automation', 'mqtt', 'difference', 'gap', 'status'], true)) $bchSortBy = 'time';
$bchSortDir = strtolower((string)($_GET['sort_dir'] ?? 'desc'));
if (!in_array($bchSortDir, ['asc', 'desc'], true)) $bchSortDir = 'desc';

$bchPlainPath = $bchLogDirectory . '/battery_control_' . $bchDate . '.jsonl';
$bchArchivePath = $bchPlainPath . '.gz';
$bchPath = is_file($bchPlainPath) ? $bchPlainPath : (is_file($bchArchivePath) ? $bchArchivePath : '');
$bchLines = [];
if ($bchPath !== '') {
    $bchLines = substr($bchPath, -3) === '.gz'
        ? (gzfile($bchPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
        : (file($bchPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
}

$bchAutomation = [];
$bchMqtt = [];
foreach ($bchLines as $bchLine) {
    $bchRow = json_decode($bchLine, true);
    if (!is_array($bchRow)) continue;
    $bchDeviceId = (string)($bchRow['device_id'] ?? '');
    $bchSource = strtolower((string)($bchRow['source'] ?? ''));
    if (!isset($bchBatteryIds[$bchDeviceId]) || (string)($bchRow['parameter'] ?? '') !== 'p_max') continue;
    if (!is_numeric($bchRow['requested'] ?? null)) continue;
    $bchRow['_ts'] = (int)($bchRow['timestamp'] ?? strtotime((string)($bchRow['datetime'] ?? '')) ?: 0);
    $bchRow['_value'] = round((float)$bchRow['requested'], 3);
    if (in_array($bchSource, ['automation', 'automation_simulation'], true)) {
        $bchAutomation[] = $bchRow;
    } elseif ($bchSource === 'mqtt') {
        $bchMqtt[] = $bchRow;
    }
}
usort($bchAutomation, fn($a, $b) => $a['_ts'] <=> $b['_ts']);
usort($bchMqtt, fn($a, $b) => $a['_ts'] <=> $b['_ts']);

$bchUsedMqtt = [];
$bchPairs = [];
foreach ($bchAutomation as $bchAutoIndex => $bchAuto) {
    $bchBestIndex = null;
    $bchBestGap = $bchWindow + 1;
    foreach ($bchMqtt as $bchMqttIndex => $bchMqttRow) {
        if (isset($bchUsedMqtt[$bchMqttIndex]) || $bchMqttRow['device_id'] !== $bchAuto['device_id']) continue;
        $bchGap = abs($bchMqttRow['_ts'] - $bchAuto['_ts']);
        if ($bchGap <= $bchWindow && $bchGap < $bchBestGap) {
            $bchBestIndex = $bchMqttIndex;
            $bchBestGap = $bchGap;
        }
    }
    $bchMatchedMqtt = $bchBestIndex === null ? null : $bchMqtt[$bchBestIndex];
    if ($bchBestIndex !== null) $bchUsedMqtt[$bchBestIndex] = true;
    $bchPairs[] = ['automation' => $bchAuto, 'mqtt' => $bchMatchedMqtt, 'gap' => $bchMatchedMqtt ? $bchBestGap : null];
}
foreach ($bchMqtt as $bchMqttIndex => $bchMqttRow) {
    if (!isset($bchUsedMqtt[$bchMqttIndex])) {
        $bchPairs[] = ['automation' => null, 'mqtt' => $bchMqttRow, 'gap' => null];
    }
}

$bchDirection = function ($bchValue) {
    if (!is_numeric($bchValue) || abs((float)$bchValue) <= 0.1) return 'stop';
    return (float)$bchValue < 0 ? 'charge' : 'discharge';
};
$bchMatched = 0;
$bchExact = 0;
$bchClose = 0;
$bchModerate = 0;
$bchDifferent = 0;
$bchDirectionSame = 0;
$bchAbsoluteTotal = 0.0;
foreach ($bchPairs as &$bchPair) {
    if (!$bchPair['automation'] || !$bchPair['mqtt']) {
        $bchPair['status'] = 'unmatched';
        $bchPair['difference'] = null;
        continue;
    }
    $bchMatched++;
    $bchDifference = abs($bchPair['automation']['_value'] - $bchPair['mqtt']['_value']);
    $bchPair['difference'] = round($bchDifference, 3);
    $bchAbsoluteTotal += $bchDifference;
    if ($bchDifference <= 0.1) {
        $bchExact++;
        $bchPair['status'] = 'exact';
    } elseif ($bchDifference <= 5.0) {
        $bchClose++;
        $bchPair['status'] = 'close';
    } elseif ($bchDifference <= 15.0) {
        $bchModerate++;
        $bchPair['status'] = 'moderate';
    } else {
        $bchDifferent++;
        $bchPair['status'] = 'different';
    }
    if ($bchDirection($bchPair['automation']['_value']) === $bchDirection($bchPair['mqtt']['_value'])) $bchDirectionSame++;
}
unset($bchPair);
usort($bchPairs, function ($a, $b) {
    $aTs = max((int)($a['automation']['_ts'] ?? 0), (int)($a['mqtt']['_ts'] ?? 0));
    $bTs = max((int)($b['automation']['_ts'] ?? 0), (int)($b['mqtt']['_ts'] ?? 0));
    return $bTs <=> $aTs;
});

$bchSimilarity = $bchMatched ? (($bchExact + $bchClose) / $bchMatched * 100) : null;
$bchDirectionRate = $bchMatched ? ($bchDirectionSame / $bchMatched * 100) : null;
$bchMae = $bchMatched ? ($bchAbsoluteTotal / $bchMatched) : null;
$bchDisplayPairs = array_values(array_filter($bchPairs, function ($pair) use ($bchView) {
    $matched = !empty($pair['automation']) && !empty($pair['mqtt']);
    if ($bchView === 'matched') return $matched;
    if ($bchView === 'unmatched') return !$matched;
    return true;
}));
usort($bchDisplayPairs, function ($a, $b) use ($bchSortBy, $bchSortDir, $bchDevices) {
    $sortValue = function ($pair) use ($bchSortBy, $bchDevices) {
        $auto = $pair['automation'] ?? null;
        $mqtt = $pair['mqtt'] ?? null;
        $deviceId = (string)($auto['device_id'] ?? ($mqtt['device_id'] ?? ''));
        switch ($bchSortBy) {
            case 'battery': return strtolower((string)($bchDevices[$deviceId]['name'] ?? $deviceId));
            case 'automation': return isset($auto['_value']) ? (float)$auto['_value'] : null;
            case 'mqtt': return isset($mqtt['_value']) ? (float)$mqtt['_value'] : null;
            case 'difference': return isset($pair['difference']) ? (float)$pair['difference'] : null;
            case 'gap': return isset($pair['gap']) ? (int)$pair['gap'] : null;
            case 'status': return ['exact'=>1, 'close'=>2, 'moderate'=>3, 'different'=>4, 'unmatched'=>5][$pair['status'] ?? 'unmatched'];
            default: return max((int)($auto['_ts'] ?? 0), (int)($mqtt['_ts'] ?? 0));
        }
    };
    $aValue = $sortValue($a);
    $bValue = $sortValue($b);
    if ($aValue === null || $bValue === null) {
        if ($aValue === $bValue) return 0;
        return $aValue === null ? 1 : -1;
    }
    $result = is_numeric($aValue) && is_numeric($bValue)
        ? ($aValue <=> $bValue)
        : strnatcasecmp((string)$aValue, (string)$bValue);
    if ($result === 0) {
        $aTs = max((int)($a['automation']['_ts'] ?? 0), (int)($a['mqtt']['_ts'] ?? 0));
        $bTs = max((int)($b['automation']['_ts'] ?? 0), (int)($b['mqtt']['_ts'] ?? 0));
        $result = $aTs <=> $bTs;
    }
    return $bchSortDir === 'asc' ? $result : -$result;
});
$bchTotalPages = max(1, (int)ceil(count($bchDisplayPairs) / $bchPageSize));
$bchPage = max(1, min($bchTotalPages, (int)($_GET['log_page'] ?? 1)));
$bchRows = array_slice($bchDisplayPairs, ($bchPage - 1) * $bchPageSize, $bchPageSize);
$bchTotalRows = count($bchDisplayPairs);
$bchFirstRow = $bchTotalRows ? (($bchPage - 1) * $bchPageSize) + 1 : 0;
$bchLastRow = $bchTotalRows ? min($bchTotalRows, $bchFirstRow + count($bchRows) - 1) : 0;
$bchNumber = fn($value, $decimals = 1) => is_numeric($value) ? number_format((float)$value, $decimals) : '—';
$bchUrl = function ($page) use ($bchDate, $bchWindow, $bchPageSize, $bchView, $bchSortBy, $bchSortDir) {
    return 'index.php?page=battery-command-history&date=' . rawurlencode($bchDate)
        . '&window=' . $bchWindow . '&page_size=' . $bchPageSize
        . '&view=' . rawurlencode($bchView) . '&sort_by=' . rawurlencode($bchSortBy)
        . '&sort_dir=' . rawurlencode($bchSortDir) . '&log_page=' . max(1, (int)$page);
};
$bchSortHeader = function ($key, $label) use ($bchDate, $bchWindow, $bchPageSize, $bchView, $bchSortBy, $bchSortDir) {
    $active = $bchSortBy === $key;
    $nextDir = $active && $bchSortDir === 'asc' ? 'desc' : 'asc';
    $icon = !$active ? 'fa-sort' : ($bchSortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
    $url = 'index.php?page=battery-command-history&date=' . rawurlencode($bchDate)
        . '&window=' . $bchWindow . '&page_size=' . $bchPageSize . '&view=' . rawurlencode($bchView)
        . '&sort_by=' . rawurlencode($key) . '&sort_dir=' . $nextDir . '&log_page=1';
    return '<a class="bch-sort' . ($active ? ' active' : '') . '" href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
        . htmlspecialchars($label) . ' <i class="fa-solid ' . $icon . '"></i></a>';
};
$bchContext = function ($row) use ($bchNumber) {
    $context = is_array($row['context'] ?? null) ? $row['context'] : [];
    $parts = [];
    if (!empty($context['state'])) $parts[] = (string)$context['state'];
    if (isset($context['main_power'])) $parts[] = 'Main ' . $bchNumber($context['main_power']) . ' kW';
    if (isset($context['battery_power'])) $parts[] = 'Battery ' . $bchNumber($context['battery_power']) . ' kW';
    if (isset($context['soc'])) $parts[] = 'SoC ' . $bchNumber($context['soc']) . '%';
    return $parts ? implode(' · ', $parts) : 'ไม่มีข้อมูลเงื่อนไขใน Log รุ่นเดิม';
};
$bchValueHtml = function ($row) use ($bchNumber, $bchDirection) {
    if (!$row) return '<span class="bch-none">ไม่มีคำสั่งจับคู่</span>';
    $direction = $bchDirection($row['_value']);
    $labels = ['charge' => 'Charge', 'discharge' => 'Discharge', 'stop' => 'Stop'];
    return '<strong>' . $bchNumber($row['_value']) . ' kW</strong><small class="' . $direction . '">' . $labels[$direction] . '</small>';
};
?>
<div class="bch-wrap">
  <div class="bch-head">
    <div class="bch-title"><i class="fa-solid fa-code-compare"></i><div><h3>Battery Command Comparison</h3><p>History เปรียบเทียบการควบคุม Battery ระหว่าง Automation กับ MQTT</p></div></div>
    <a href="index.php?page=automation-simulation-log"><i class="fa-solid fa-file-waveform"></i> Simulation Log</a>
  </div>

  <form class="bch-controls" method="get">
    <input type="hidden" name="page" value="battery-command-history">
    <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($bchSortBy, ENT_QUOTES); ?>">
    <input type="hidden" name="sort_dir" value="<?php echo htmlspecialchars($bchSortDir, ENT_QUOTES); ?>">
    <label><span>วันที่</span><select name="date" onchange="this.form.submit()"><?php foreach ($bchDates ?: [$bchDate] as $date): ?><option value="<?php echo htmlspecialchars($date); ?>" <?php echo $date === $bchDate ? 'selected' : ''; ?>><?php echo htmlspecialchars($date); ?></option><?php endforeach; ?></select></label>
    <label><span>ช่วงเวลาจับคู่</span><select name="window" onchange="this.form.submit()"><?php foreach ([30=>'30 วินาที',60=>'1 นาที',120=>'2 นาที',300=>'5 นาที',600=>'10 นาที'] as $value=>$label): ?><option value="<?php echo $value; ?>" <?php echo $value === $bchWindow ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
    <label><span>แสดงผล</span><select name="view" onchange="this.form.submit()"><option value="matched" <?php echo $bchView === 'matched' ? 'selected' : ''; ?>>จับคู่แล้ว</option><option value="unmatched" <?php echo $bchView === 'unmatched' ? 'selected' : ''; ?>>ไม่มีคู่</option><option value="all" <?php echo $bchView === 'all' ? 'selected' : ''; ?>>ทั้งหมด</option></select></label>
  </form>

  <div class="bch-stats">
    <div><span>จับคู่ได้</span><b><?php echo number_format($bchMatched); ?></b><small>จาก Automation <?php echo number_format(count($bchAutomation)); ?> / MQTT <?php echo number_format(count($bchMqtt)); ?></small></div>
    <div class="score"><span>ใกล้เคียงไม่เกิน 5 kW</span><b><?php echo $bchSimilarity === null ? '—' : $bchNumber($bchSimilarity) . '%'; ?></b><small>เท่ากัน <?php echo $bchExact; ?> · ใกล้เคียง <?php echo $bchClose; ?></small></div>
    <div><span>คลาดเคลื่อนเฉลี่ย</span><b><?php echo $bchMae === null ? '—' : $bchNumber($bchMae) . ' kW'; ?></b><small>Mean Absolute Error</small></div>
    <div><span>ทิศทางตรงกัน</span><b><?php echo $bchDirectionRate === null ? '—' : $bchNumber($bchDirectionRate) . '%'; ?></b><small>Charge / Discharge / Stop</small></div>
  </div>

  <div class="bch-distribution">
    <div class="exact" style="width:<?php echo $bchMatched ? ($bchExact / $bchMatched * 100) : 0; ?>%" title="เท่ากัน"></div>
    <div class="close" style="width:<?php echo $bchMatched ? ($bchClose / $bchMatched * 100) : 0; ?>%" title="ใกล้เคียง"></div>
    <div class="moderate" style="width:<?php echo $bchMatched ? ($bchModerate / $bchMatched * 100) : 0; ?>%" title="ต่าง 5–15 kW"></div>
    <div class="different" style="width:<?php echo $bchMatched ? ($bchDifferent / $bchMatched * 100) : 0; ?>%" title="ต่างเกิน 15 kW"></div>
  </div>
  <div class="bch-legend"><span class="exact">≤ 0.1 kW เท่ากัน</span><span class="close">≤ 5 kW ใกล้เคียง</span><span class="moderate">5–15 kW ต่างเล็กน้อย</span><span class="different">&gt; 15 kW ต่างมาก</span></div>

  <div class="bch-panel">
    <?php if (!$bchRows): ?>
      <div class="bch-empty"><i class="fa-solid fa-clock-rotate-left"></i><b>ไม่พบข้อมูลตามตัวกรอง</b><span>ลองเปลี่ยนวันที่ ช่วงเวลาจับคู่ หรือรูปแบบการแสดงผล</span></div>
    <?php else: ?>
      <div class="bch-table-wrap"><table><thead><tr><th><?php echo $bchSortHeader('time', 'เวลา'); ?></th><th><?php echo $bchSortHeader('battery', 'Battery'); ?></th><th><?php echo $bchSortHeader('automation', 'Automation'); ?></th><th><?php echo $bchSortHeader('mqtt', 'MQTT'); ?></th><th><?php echo $bchSortHeader('difference', 'ผลต่าง'); ?></th><th><?php echo $bchSortHeader('gap', 'ห่างกัน'); ?></th><th>เงื่อนไขขณะสั่ง</th><th><?php echo $bchSortHeader('status', 'ผลเปรียบเทียบ'); ?></th></tr></thead><tbody>
      <?php foreach ($bchRows as $pair):
        $auto = $pair['automation']; $mqtt = $pair['mqtt'];
        $time = $auto['datetime'] ?? ($mqtt['datetime'] ?? '—');
        $deviceId = $auto['device_id'] ?? ($mqtt['device_id'] ?? '—');
        $statusLabels = ['exact'=>'เท่ากัน','close'=>'ใกล้เคียง','moderate'=>'ต่างเล็กน้อย','different'=>'ต่างมาก','unmatched'=>'ไม่มีคู่เปรียบเทียบ'];
      ?>
        <tr>
          <td><b><?php echo htmlspecialchars(substr($time, 11)); ?></b><small><?php echo htmlspecialchars(substr($time, 0, 10)); ?></small></td>
          <td><b><?php echo htmlspecialchars($bchDevices[$deviceId]['name'] ?? $deviceId); ?></b><small><?php echo htmlspecialchars($deviceId); ?></small></td>
          <td><?php echo $bchValueHtml($auto); ?><?php if ($auto): ?><small><?php echo ($auto['source'] ?? '') === 'automation_simulation' ? 'Simulation' : 'Executed'; ?></small><?php endif; ?></td>
          <td><?php echo $bchValueHtml($mqtt); ?><?php if ($mqtt && isset($mqtt['actual'])): ?><small>หลังเขียน <?php echo $bchNumber($mqtt['actual']); ?> kW</small><?php endif; ?></td>
          <td><b><?php echo $pair['difference'] === null ? '—' : $bchNumber($pair['difference']) . ' kW'; ?></b></td>
          <td><?php echo $pair['gap'] === null ? '—' : number_format($pair['gap']) . ' วินาที'; ?></td>
          <td><div class="bch-context"><small>Automation</small><span title="<?php echo htmlspecialchars((string)($auto['reason'] ?? ''), ENT_QUOTES); ?>"><?php echo htmlspecialchars($auto ? $bchContext($auto) : '—'); ?></span><small>MQTT</small><span><?php echo htmlspecialchars($mqtt ? $bchContext($mqtt) : '—'); ?></span></div></td>
          <td><span class="bch-result <?php echo $pair['status']; ?>"><?php echo $statusLabels[$pair['status']]; ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <div class="bch-pagination">
        <span>แสดง <?php echo number_format($bchFirstRow); ?>–<?php echo number_format($bchLastRow); ?> จาก <?php echo number_format($bchTotalRows); ?> รายการ · หน้า <?php echo $bchPage; ?> / <?php echo $bchTotalPages; ?></span>
        <div class="bch-pagination-actions">
          <form class="bch-row-limit" method="get" title="จำนวนแถวที่แสดงต่อหน้า">
            <input type="hidden" name="page" value="battery-command-history">
            <input type="hidden" name="date" value="<?php echo htmlspecialchars($bchDate, ENT_QUOTES); ?>">
            <input type="hidden" name="window" value="<?php echo $bchWindow; ?>">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($bchView, ENT_QUOTES); ?>">
            <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($bchSortBy, ENT_QUOTES); ?>">
            <input type="hidden" name="sort_dir" value="<?php echo htmlspecialchars($bchSortDir, ENT_QUOTES); ?>">
            <span>แสดง</span><select name="page_size" onchange="this.form.submit()" aria-label="จำนวนแถวต่อหน้า"><?php foreach ([5,10,25,50,100,200,500] as $value): ?><option value="<?php echo $value; ?>" <?php echo $value === $bchPageSize ? 'selected' : ''; ?>><?php echo $value; ?> แถว</option><?php endforeach; ?></select>
          </form>
          <div class="bch-page-buttons">
            <a class="<?php echo $bchPage <= 1 ? 'disabled' : ''; ?>" href="<?php echo $bchPage <= 1 ? '#' : htmlspecialchars($bchUrl($bchPage - 1), ENT_QUOTES); ?>"><i class="fa-solid fa-chevron-left"></i> ก่อนหน้า</a>
            <a class="<?php echo $bchPage >= $bchTotalPages ? 'disabled' : ''; ?>" href="<?php echo $bchPage >= $bchTotalPages ? '#' : htmlspecialchars($bchUrl($bchPage + 1), ENT_QUOTES); ?>">ถัดไป <i class="fa-solid fa-chevron-right"></i></a>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
  <p class="bch-note"><i class="fa-solid fa-circle-info"></i> ระบบจับคู่ตาม Battery เครื่องเดียวกันและเวลาที่ใกล้ที่สุดภายในช่วงที่เลือก ค่า “ใกล้เคียง” หมายถึงผลต่างไม่เกิน 5 kW</p>
</div>
<style>
.bch-wrap{max-width:1320px;margin:0 auto;padding:4px 2px 35px;color:#26394e}.bch-head{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:16px 18px;border:1px solid #dfe7f0;border-radius:15px;background:#fff;box-shadow:0 7px 22px rgba(31,55,89,.05)}.bch-title{display:flex;align-items:center;gap:12px}.bch-title>i{display:grid;place-items:center;width:43px;height:43px;border-radius:12px;background:#eaf3ff;color:#1769d2}.bch-title h3{margin:0;font-size:1.05rem}.bch-title p{margin:3px 0 0;color:#718397;font-size:.71rem}.bch-head>a{display:inline-flex;align-items:center;gap:6px;padding:8px 11px;border:1px solid #cadced;border-radius:9px;color:#326faa;text-decoration:none;font-size:.68rem;font-weight:800}.bch-controls{display:flex;justify-content:flex-end;gap:8px;margin:12px 0}.bch-controls label span{display:block;margin-bottom:3px;color:#718397;font-size:.61rem;font-weight:750}.bch-controls select{height:37px;min-width:125px;padding:4px 28px 4px 9px;border:1px solid #cedbe8;border-radius:9px;background:#fff;color:#35536d;font-size:.7rem}.bch-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}.bch-stats>div{padding:13px 14px;border:1px solid #dfe7f0;border-radius:12px;background:#fff}.bch-stats span,.bch-stats b,.bch-stats small{display:block}.bch-stats span{color:#718397;font-size:.65rem}.bch-stats b{margin:3px 0;color:#315b7e;font-size:1.16rem}.bch-stats small{color:#8a98a7;font-size:.6rem}.bch-stats .score b{color:#168958}.bch-distribution{display:flex;height:8px;margin-top:11px;overflow:hidden;border-radius:8px;background:#e9eef3}.bch-distribution>div{min-width:0}.bch-distribution .exact,.bch-legend .exact:before{background:#20a467}.bch-distribution .close,.bch-legend .close:before{background:#55a5e5}.bch-distribution .moderate,.bch-legend .moderate:before{background:#e6a222}.bch-distribution .different,.bch-legend .different:before{background:#d94d4d}.bch-legend{display:flex;justify-content:flex-end;gap:13px;margin:7px 2px 12px;color:#708397;font-size:.61rem}.bch-legend span{display:inline-flex;align-items:center;gap:5px}.bch-legend span:before{content:'';width:7px;height:7px;border-radius:50%}.bch-panel{overflow:hidden;border:1px solid #dfe7f0;border-radius:14px;background:#fff;box-shadow:0 7px 21px rgba(31,55,89,.04)}.bch-table-wrap{overflow-x:auto}.bch-table-wrap table{width:100%;min-width:1050px;border-collapse:collapse;table-layout:fixed;font-size:.69rem}.bch-table-wrap th{padding:9px 10px;background:#edf4fb;color:#4b657e;text-align:left;white-space:nowrap}.bch-table-wrap td{padding:9px 10px;border-top:1px solid #edf1f5;vertical-align:middle}.bch-table-wrap td>b,.bch-table-wrap td>small,.bch-table-wrap td>strong{display:block}.bch-table-wrap td>small{color:#8797a6;font-size:.59rem}.bch-table-wrap th:nth-child(1){width:11%}.bch-table-wrap th:nth-child(2){width:13%}.bch-table-wrap th:nth-child(3),.bch-table-wrap th:nth-child(4){width:12%}.bch-table-wrap th:nth-child(5),.bch-table-wrap th:nth-child(6){width:8%}.bch-table-wrap th:nth-child(7){width:26%}.bch-table-wrap th:nth-child(8){width:10%}.bch-table-wrap .charge{color:#168958}.bch-table-wrap .discharge{color:#d27c00}.bch-table-wrap .stop{color:#718397}.bch-context{display:grid;grid-template-columns:68px minmax(0,1fr);gap:3px 6px;line-height:1.35}.bch-context small{color:#8293a3;font-size:.57rem;font-weight:800}.bch-context span{overflow:hidden;text-overflow:ellipsis}.bch-none{color:#9aa7b4}.bch-result{display:inline-flex;padding:4px 7px;border-radius:12px;font-size:.58rem;font-weight:850;white-space:nowrap}.bch-result.exact{background:#e5f7ee;color:#168958}.bch-result.close{background:#e8f3fd;color:#287bbd}.bch-result.moderate{background:#fff3dc;color:#ae7000}.bch-result.different{background:#fdeaea;color:#c43d3d}.bch-result.unmatched{background:#eef2f5;color:#758493}.bch-pagination{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 13px;color:#718397;font-size:.67rem}.bch-pages{display:flex;align-items:center;gap:5px}.bch-pagination a{display:inline-grid;place-items:center;min-width:30px;height:30px;box-sizing:border-box;padding:0 7px;border:1px solid #d4dfeb;border-radius:8px;color:#326b9f;text-decoration:none}.bch-pagination a.current{border-color:#1769d2;background:#1769d2;color:#fff;font-weight:850;pointer-events:none}.bch-pagination a.disabled{opacity:.35;pointer-events:none}.bch-empty{display:flex;min-height:270px;align-items:center;justify-content:center;flex-direction:column;color:#8292a1}.bch-empty i{font-size:2rem;color:#b4c2cf}.bch-empty b{margin-top:9px;color:#51677c}.bch-empty span{margin-top:3px;font-size:.68rem}.bch-note{margin:9px 3px 0;color:#718397;font-size:.63rem}.bch-note i{margin-right:4px;color:#3978b7}@media(max-width:800px){.bch-head{align-items:flex-start;flex-direction:column}.bch-controls{display:grid;grid-template-columns:1fr 1fr}.bch-controls label:last-child{grid-column:1/-1}.bch-controls select{width:100%}.bch-stats{grid-template-columns:1fr 1fr}.bch-legend{justify-content:flex-start;flex-wrap:wrap}.bch-pagination{align-items:flex-start;flex-direction:column}.bch-pages{width:100%;overflow-x:auto;padding-bottom:2px}}@media(max-width:480px){.bch-stats{grid-template-columns:1fr}.bch-title p{font-size:.64rem}}
.bch-pagination-actions,.bch-page-buttons{display:flex;align-items:center;gap:7px;flex:none}.bch-row-limit{display:flex;align-items:center;gap:7px;min-height:35px;padding:3px 4px 3px 10px;border:1px solid #d4deea;border-radius:9px;background:#f8fafc}.bch-row-limit>span{font-size:.66rem;font-weight:700;white-space:nowrap}.bch-row-limit select{min-width:82px;min-height:27px;padding:3px 24px 3px 8px;border:1px solid #cbdbea;border-radius:7px;background:#fff;color:#315b7e;font-size:.7rem;font-weight:800;outline:0;cursor:pointer}.bch-row-limit:focus-within{border-color:#9bbfe1;box-shadow:0 0 0 3px rgba(23,105,210,.08)}.bch-page-buttons a{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-width:auto;min-height:35px;height:auto;box-sizing:border-box;padding:6px 11px;border:1px solid #d4deea;border-radius:8px;background:#fff;color:#3978b7;font-size:.7rem;font-weight:700;text-decoration:none}.bch-page-buttons a:hover:not(.disabled){border-color:#1769d2;background:#eef5fd}.bch-page-buttons a.disabled{opacity:.4;pointer-events:none}@media(max-width:600px){.bch-pagination{align-items:stretch;flex-direction:column;gap:9px}.bch-pagination-actions{justify-content:space-between;width:100%}}@media(max-width:390px){.bch-pagination-actions{align-items:stretch;flex-direction:column}.bch-row-limit{align-self:flex-start}.bch-page-buttons{justify-content:flex-end;width:100%}}
.bch-sort{display:inline-flex;align-items:center;gap:5px;color:#4b657e;text-decoration:none}.bch-sort i{color:#9aabbb;font-size:.58rem}.bch-sort.active{color:#1769d2}.bch-sort.active i{color:#1769d2}.bch-sort:hover{color:#1769d2}
</style>
