<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['username']) || !in_array(strtolower($_SESSION['user_type'] ?? ''), ['admin', 'superadmin'], true)) {
    http_response_code(403);
    echo 'Access denied';
    return;
}
$aslLogDirectory = '/home/mdbcare/python/automation_simulation_logs';
$aslConfig = json_decode(@file_get_contents('./config/automation_control.json'), true);
if (!is_array($aslConfig)) $aslConfig = [];
$aslBatteryDeviceIds = [];
foreach (($aslConfig['devices'] ?? []) as $aslDeviceId => $aslDeviceConfig) {
    if (is_array($aslDeviceConfig) && strtolower((string)($aslDeviceConfig['role'] ?? '')) === 'battery') {
        $aslBatteryDeviceIds[(string)$aslDeviceId] = true;
    }
}
$aslDates = [];
foreach (array_merge(
    glob($aslLogDirectory . '/automation_simulation_*.txt') ?: [],
    glob($aslLogDirectory . '/automation_simulation_*.txt.gz') ?: []
) as $aslFile) {
    if (preg_match('/automation_simulation_(\d{4}-\d{2}-\d{2})\.txt(?:\.gz)?$/', basename($aslFile), $aslMatch)) {
        $aslDates[$aslMatch[1]] = true;
    }
}
$aslDates = array_keys($aslDates);
rsort($aslDates);
$aslSelectedDate = isset($_GET['date']) ? (string)$_GET['date'] : ($aslDates[0] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $aslSelectedDate)) $aslSelectedDate = date('Y-m-d');
$aslPlainPath = $aslLogDirectory . '/automation_simulation_' . $aslSelectedDate . '.txt';
$aslGzipPath = $aslPlainPath . '.gz';
$aslContent = '';
if (is_file($aslPlainPath)) {
    $aslContent = (string)@file_get_contents($aslPlainPath);
} elseif (is_file($aslGzipPath)) {
    $aslCompressed = @file_get_contents($aslGzipPath);
    $aslContent = $aslCompressed === false ? '' : (string)@gzdecode($aslCompressed);
}

$aslRecords = [];
foreach (preg_split('/^={20,}\R/m', $aslContent) ?: [] as $aslBlock) {
    $aslBlock = trim($aslBlock);
    if ($aslBlock === '') continue;
    if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/m', $aslBlock, $aslTimeMatch)) continue;
    preg_match('/^State:\s*\[([^\]]+)\]/m', $aslBlock, $aslStateMatch);
    preg_match('/Input:\s*(\{.*?\})\s*Calculation \/ Proposed command:/s', $aslBlock, $aslInputMatch);
    preg_match('/Calculation \/ Proposed command:\s*(.*)$/s', $aslBlock, $aslCalculationMatch);
    $aslInput = isset($aslInputMatch[1]) ? json_decode($aslInputMatch[1], true) : [];
    if (!is_array($aslInput)) $aslInput = [];
    $aslCalculation = trim($aslCalculationMatch[1] ?? '');
    preg_match_all('/^\[SIMULATION\] WOULD WRITE\s+(.+)$/m', $aslCalculation, $aslCommandMatches);
    $aslCommands = $aslCommandMatches[1] ?? [];
    $aslBatteryCommands = array_values(array_filter($aslCommands, function ($aslCommand) use ($aslBatteryDeviceIds) {
        return preg_match('/^(\S+)\s+/', trim((string)$aslCommand), $aslDeviceMatch)
            && isset($aslBatteryDeviceIds[$aslDeviceMatch[1]]);
    }));
    $aslBattery = [];
    if (!empty($aslInput['battery']) && is_array($aslInput['battery'])) {
        $aslBattery = reset($aslInput['battery']);
        if (!is_array($aslBattery)) $aslBattery = [];
    }
    $aslRecords[] = [
        'datetime' => $aslTimeMatch[1],
        'state' => $aslStateMatch[1] ?? 'UNKNOWN',
        'main' => $aslInput['main_meter']['p_t'] ?? null,
        'battery_power' => $aslBattery['p_t'] ?? null,
        'battery_p_max' => $aslBattery['p_max'] ?? null,
        'soc' => $aslBattery['soc'] ?? null,
        'commands' => $aslCommands,
        'battery_commands' => $aslBatteryCommands,
        'input' => $aslInput,
        'calculation' => $aslCalculation
    ];
}
usort($aslRecords, function ($a, $b) { return strcmp($b['datetime'], $a['datetime']); });
$aslCounts = ['P1_ANTI_BACKFLOW' => 0, 'P2_PEAK_SHAVING' => 0, 'P3_BASE_TOU' => 0, 'OTHER' => 0];
$aslCommandCount = 0;
foreach ($aslRecords as $aslRecord) {
    $aslKey = array_key_exists($aslRecord['state'], $aslCounts) ? $aslRecord['state'] : 'OTHER';
    $aslCounts[$aslKey]++;
    $aslCommandCount += count($aslRecord['battery_commands']);
}
$aslPageSize = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10;
if (!in_array($aslPageSize, [10, 25, 50], true)) $aslPageSize = 10;
$aslTotalPages = max(1, (int)ceil(count($aslRecords) / $aslPageSize));
$aslPage = max(1, min($aslTotalPages, (int)($_GET['log_page'] ?? 1)));
$aslRows = array_slice($aslRecords, ($aslPage - 1) * $aslPageSize, $aslPageSize);
$aslLatestSimulation = $aslRecords[0] ?? null;
$aslSimulationCommands = [];
if (is_array($aslLatestSimulation)) {
    foreach ($aslLatestSimulation['battery_commands'] as $aslCommandText) {
        if (preg_match('/^(\S+)\s+(\w+)=(-?\d+(?:\.\d+)?)\s*kW;\s*current=([^;]+);\s*reason=(.*)$/s', trim($aslCommandText), $aslParsedCommand)) {
            if (!isset($aslBatteryDeviceIds[$aslParsedCommand[1]])) continue;
            $aslKey = $aslParsedCommand[1] . '|' . $aslParsedCommand[2];
            $aslSimulationCommands[$aslKey] = [
                'device_id' => $aslParsedCommand[1],
                'parameter' => $aslParsedCommand[2],
                'value' => (float)$aslParsedCommand[3],
                'current' => trim($aslParsedCommand[4]),
                'reason' => trim($aslParsedCommand[5])
            ];
        }
    }
}
$aslMqttHistoryPath = '/home/mdbcare/python/mqtt_command_history.json';
$aslMqttHistory = json_decode(@file_get_contents($aslMqttHistoryPath), true);
if (!is_array($aslMqttHistory)) $aslMqttHistory = [];
$aslLatestMqtt = null;
for ($aslMqttIndex = count($aslMqttHistory) - 1; $aslMqttIndex >= 0; $aslMqttIndex--) {
    $aslCandidate = $aslMqttHistory[$aslMqttIndex];
    if (!is_array($aslCandidate) || !is_array($aslCandidate['command'] ?? null)) continue;
    $aslHasComparableCommand = false;
    foreach ($aslCandidate['command'] as $aslDeviceId => $aslParameters) {
        if (isset($aslBatteryDeviceIds[(string)$aslDeviceId]) && is_array($aslParameters) && array_key_exists('p_max', $aslParameters)) {
            $aslHasComparableCommand = true;
            break;
        }
    }
    if ($aslHasComparableCommand) {
        $aslLatestMqtt = $aslCandidate;
        break;
    }
}
$aslMqttCommands = [];
if (is_array($aslLatestMqtt)) {
    foreach ($aslLatestMqtt['command'] as $aslDeviceId => $aslParameters) {
        if (!isset($aslBatteryDeviceIds[(string)$aslDeviceId]) || !is_array($aslParameters) || !array_key_exists('p_max', $aslParameters)) continue;
        $aslOperation = $aslParameters['p_max'];
        $aslValue = is_array($aslOperation) ? ($aslOperation['write'] ?? null) : $aslOperation;
        $aslResponse = $aslLatestMqtt['response'][$aslDeviceId]['p_max'] ?? null;
        $aslResult = $aslLatestMqtt['result'][$aslDeviceId] ?? null;
        $aslMqttCommands[$aslDeviceId . '|p_max'] = [
            'device_id' => (string)$aslDeviceId,
            'parameter' => 'p_max',
            'value' => is_numeric($aslValue) ? (float)$aslValue : $aslValue,
            'actual' => $aslResponse,
            'success' => $aslResult === null ? null : (bool)$aslResult,
            'cmd_id' => (string)($aslLatestMqtt['cmd_id'] ?? '')
        ];
    }
}
$aslComparisonKeys = array_values(array_unique(array_merge(array_keys($aslSimulationCommands), array_keys($aslMqttCommands))));
$aslComparisonRows = [];
foreach ($aslComparisonKeys as $aslComparisonKey) {
    $aslSim = $aslSimulationCommands[$aslComparisonKey] ?? null;
    $aslMqtt = $aslMqttCommands[$aslComparisonKey] ?? null;
    $aslDelta = is_array($aslSim) && is_array($aslMqtt) && is_numeric($aslMqtt['value'])
        ? round((float)$aslSim['value'] - (float)$aslMqtt['value'], 1)
        : null;
    $aslComparisonRows[] = [
        'device_id' => $aslSim['device_id'] ?? ($aslMqtt['device_id'] ?? '—'),
        'parameter' => $aslSim['parameter'] ?? ($aslMqtt['parameter'] ?? '—'),
        'simulation' => $aslSim,
        'mqtt' => $aslMqtt,
        'delta' => $aslDelta,
        'status' => $aslSim && $aslMqtt && $aslDelta !== null
            ? (abs($aslDelta) <= 0.1 ? 'same' : 'different')
            : ($aslSim ? 'automation_only' : 'mqtt_only')
    ];
}
$aslNumber = function ($value, $decimals = 1) {
    return is_numeric($value) ? number_format((float)$value, $decimals) : '—';
};
$aslUrl = function ($page) use ($aslSelectedDate, $aslPageSize) {
    return 'index.php?page=automation-simulation-log&date=' . rawurlencode($aslSelectedDate)
        . '&page_size=' . $aslPageSize . '&log_page=' . max(1, (int)$page);
};
?>
<div class="asl-wrap">
  <div class="asl-head">
    <div class="asl-title"><i class="fa-solid fa-file-waveform"></i><div><h3>Automation Simulation Log</h3><p>แสดงผลรอบล่าสุดของ Automation และเขียนทับทุกครั้ง โดยไม่สะสมข้อมูลย้อนหลัง</p></div></div>
    <div class="asl-statuses">
      <span class="asl-status <?php echo !empty($aslConfig['enabled']) ? 'active' : 'safe'; ?>"><i class="fa-solid <?php echo !empty($aslConfig['enabled']) ? 'fa-bolt' : 'fa-power-off'; ?>"></i> Automation <?php echo !empty($aslConfig['enabled']) ? 'ON' : 'OFF'; ?></span>
      <span class="asl-status <?php echo !empty($aslConfig['simulation_log_enabled']) ? 'simulation' : 'muted'; ?>"><i class="fa-solid fa-shield-halved"></i> Modbus Write <?php echo !empty($aslConfig['simulation_log_enabled']) ? 'BLOCKED' : 'ENABLED'; ?></span>
    </div>
  </div>

  <div class="asl-toolbar">
    <span class="asl-live"><i></i><span>Auto update</span><b id="aslLastUpdated">--:--:--</b></span>
    <label><span>วันที่มี Log</span><select id="aslDate"><?php foreach ($aslDates as $aslDate): ?><option value="<?php echo htmlspecialchars($aslDate, ENT_QUOTES); ?>" <?php echo $aslDate === $aslSelectedDate ? 'selected' : ''; ?>><?php echo htmlspecialchars($aslDate); ?></option><?php endforeach; ?></select></label>
    <label><span>แสดงต่อหน้า</span><select id="aslPageSize"><?php foreach ([10,25,50] as $aslSize): ?><option value="<?php echo $aslSize; ?>" <?php echo $aslSize === $aslPageSize ? 'selected' : ''; ?>><?php echo $aslSize; ?> รายการ</option><?php endforeach; ?></select></label>
    <button type="button" id="aslRefresh"><i class="fa-solid fa-rotate"></i> อัปเดตล่าสุด</button>
  </div>

  <div class="asl-summary">
    <div><span>รอบคำนวณ</span><b><?php echo number_format(count($aslRecords)); ?></b></div>
    <div class="p1"><span>STATE 1</span><b><?php echo number_format($aslCounts['P1_ANTI_BACKFLOW']); ?></b></div>
    <div class="p2"><span>STATE 2</span><b><?php echo number_format($aslCounts['P2_PEAK_SHAVING']); ?></b></div>
    <div class="p3"><span>STATE 3</span><b><?php echo number_format($aslCounts['P3_BASE_TOU']); ?></b></div>
    <div class="cmd"><span>คำสั่ง Battery</span><b><?php echo number_format($aslCommandCount); ?></b></div>
  </div>

  <div class="asl-compare">
    <div class="asl-compare-head">
      <div><h4><i class="fa-solid fa-car-battery"></i> Battery Automation vs MQTT</h4><p>เปรียบเทียบคำสั่ง p_max ล่าสุดเฉพาะอุปกรณ์ประเภท Battery</p></div>
      <div class="asl-compare-times">
        <span><i class="fa-solid fa-bolt"></i> Automation <b><?php echo htmlspecialchars($aslLatestSimulation['datetime'] ?? '—'); ?></b></span>
        <span><i class="fa-solid fa-tower-broadcast"></i> MQTT <b><?php echo htmlspecialchars($aslLatestMqtt['datetime'] ?? '—'); ?></b></span>
      </div>
    </div>
    <?php if (!$aslComparisonRows): ?>
      <div class="asl-compare-empty"><i class="fa-solid fa-scale-balanced"></i><span>ยังไม่มีคำสั่ง Automation หรือ MQTT สำหรับเปรียบเทียบ</span></div>
    <?php else: ?>
      <div class="asl-compare-table-wrap"><table class="asl-compare-table"><thead><tr><th>Device / Parameter</th><th>Automation</th><th>MQTT</th><th>Difference</th><th>ผลเปรียบเทียบ</th></tr></thead><tbody>
      <?php foreach ($aslComparisonRows as $aslCompare): ?>
        <tr>
          <td><b><?php echo htmlspecialchars($aslCompare['device_id']); ?></b><small><?php echo htmlspecialchars($aslCompare['parameter']); ?></small></td>
          <td><?php if ($aslCompare['simulation']): ?><strong><?php echo $aslNumber($aslCompare['simulation']['value']); ?> kW</strong><small>ค่าปัจจุบัน <?php echo htmlspecialchars($aslCompare['simulation']['current']); ?> kW</small><em title="<?php echo htmlspecialchars($aslCompare['simulation']['reason'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($aslCompare['simulation']['reason']); ?></em><?php else: ?><span class="asl-none">ไม่มีคำสั่งในรอบล่าสุด</span><?php endif; ?></td>
          <td><?php if ($aslCompare['mqtt']): ?><strong><?php echo $aslNumber($aslCompare['mqtt']['value']); ?> kW</strong><small>ค่าหลังเขียน <?php echo $aslNumber($aslCompare['mqtt']['actual']); ?> kW</small><?php if ($aslCompare['mqtt']['cmd_id'] !== ''): ?><small>Command ID: <?php echo htmlspecialchars($aslCompare['mqtt']['cmd_id']); ?></small><?php endif; ?><span class="asl-mqtt-result <?php echo $aslCompare['mqtt']['success'] === true ? 'ok' : ($aslCompare['mqtt']['success'] === false ? 'fail' : 'unknown'); ?>"><?php echo $aslCompare['mqtt']['success'] === true ? 'สำเร็จ' : ($aslCompare['mqtt']['success'] === false ? 'ไม่สำเร็จ' : 'ไม่ทราบผล'); ?></span><?php else: ?><span class="asl-none">ไม่มีคำสั่งเดียวกัน</span><?php endif; ?></td>
          <td><?php echo $aslCompare['delta'] === null ? '<span class="asl-none">—</span>' : '<strong class="asl-delta">' . ($aslCompare['delta'] > 0 ? '+' : '') . $aslNumber($aslCompare['delta']) . ' kW</strong>'; ?></td>
          <td><span class="asl-compare-status <?php echo $aslCompare['status']; ?>"><?php echo ['same'=>'ค่าเท่ากัน','different'=>'ค่าต่างกัน','automation_only'=>'เฉพาะ Automation','mqtt_only'=>'Automation คงค่า'][$aslCompare['status']]; ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>

  <div class="asl-panel">
    <?php if (!$aslRows): ?>
      <div class="asl-empty"><i class="fa-regular fa-folder-open"></i><b>ไม่พบข้อมูล Simulation Log</b><span>เลือกวันที่อื่น หรือตรวจสอบว่า Simulation Log เปิดอยู่</span></div>
    <?php else: ?>
      <div class="asl-table-wrap"><table class="asl-table"><colgroup><col class="asl-col-time"><col class="asl-col-state"><col class="asl-col-main"><col class="asl-col-battery"><col class="asl-col-soc"><col class="asl-col-command"><col class="asl-col-detail"></colgroup><thead><tr><th>Date / Time</th><th>State</th><th>Main</th><th>Battery</th><th>SoC</th><th>Proposed command</th><th></th></tr></thead><tbody>
      <?php foreach ($aslRows as $aslIndex => $aslRecord):
        $aslStateClass = strpos($aslRecord['state'], 'P1_') === 0 ? 'p1' : (strpos($aslRecord['state'], 'P2_') === 0 ? 'p2' : 'p3');
        $aslDetailId = 'asl-detail-' . substr(md5($aslRecord['datetime'] . '|' . $aslRecord['calculation']), 0, 16);
      ?>
        <tr>
          <td><b><?php echo htmlspecialchars(substr($aslRecord['datetime'], 11)); ?></b><small><?php echo htmlspecialchars(substr($aslRecord['datetime'], 0, 10)); ?></small></td>
          <td><span class="asl-state <?php echo $aslStateClass; ?>"><?php echo htmlspecialchars($aslRecord['state']); ?></span></td>
          <td><?php echo $aslNumber($aslRecord['main']); ?> <small>kW</small></td>
          <td title="Battery power / p_max"><b><?php echo $aslNumber($aslRecord['battery_power']); ?> / <?php echo $aslNumber($aslRecord['battery_p_max']); ?></b><small>kW · p_t / p_max</small></td>
          <td><?php echo $aslNumber($aslRecord['soc']); ?> <small>%</small></td>
          <td><?php if ($aslRecord['battery_commands']): ?><span class="asl-change"><i class="fa-solid fa-arrow-trend-up"></i> มีการเปลี่ยนแปลง</span><?php else: ?><span class="asl-hold"><i class="fa-solid fa-pause"></i> คงค่าเดิม</span><?php endif; ?></td>
          <td><button class="asl-detail-button" type="button" data-asl-detail="<?php echo $aslDetailId; ?>" title="ดู Input และการคำนวณ"><i class="fa-solid fa-circle-info"></i></button></td>
        </tr>
        <tr class="asl-detail-row" id="<?php echo $aslDetailId; ?>" hidden><td colspan="7"><div class="asl-detail-grid"><section><h5><i class="fa-solid fa-arrow-right-to-bracket"></i> Input</h5><pre><?php echo htmlspecialchars(json_encode($aslRecord['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre></section><section><h5><i class="fa-solid fa-calculator"></i> Calculation / Proposed command</h5><pre><?php echo htmlspecialchars($aslRecord['calculation']); ?></pre></section></div></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <div class="asl-pagination"><span>หน้า <?php echo $aslPage; ?> / <?php echo $aslTotalPages; ?></span><div><a class="<?php echo $aslPage <= 1 ? 'disabled' : ''; ?>" href="<?php echo $aslPage <= 1 ? '#' : htmlspecialchars($aslUrl($aslPage - 1), ENT_QUOTES); ?>"><i class="fa-solid fa-chevron-left"></i> ก่อนหน้า</a><a class="<?php echo $aslPage >= $aslTotalPages ? 'disabled' : ''; ?>" href="<?php echo $aslPage >= $aslTotalPages ? '#' : htmlspecialchars($aslUrl($aslPage + 1), ENT_QUOTES); ?>">ถัดไป <i class="fa-solid fa-chevron-right"></i></a></div></div>
    <?php endif; ?>
  </div>
</div>
<script>
(function(){
  const go=(date,size)=>{location.href='index.php?page=automation-simulation-log&date='+encodeURIComponent(date)+'&page_size='+encodeURIComponent(size)};
  const date=document.getElementById('aslDate'),size=document.getElementById('aslPageSize');
  date?.addEventListener('change',()=>go(date.value,size.value));
  size?.addEventListener('change',()=>go(date.value,size.value));
  let refreshing=false;
  const bindDetails=()=>document.querySelectorAll('[data-asl-detail]').forEach(button=>{
    if(button.dataset.aslBound)return;button.dataset.aslBound='1';
    button.addEventListener('click',()=>{const row=document.getElementById(button.dataset.aslDetail);if(!row)return;row.hidden=!row.hidden;button.classList.toggle('active',!row.hidden)});
  });
  const stamp=()=>{const target=document.getElementById('aslLastUpdated');if(target)target.textContent=new Date().toLocaleTimeString('th-TH',{hour12:false})};
  const refresh=async()=>{
    if(refreshing||document.hidden)return;
    refreshing=true;
    const button=document.getElementById('aslRefresh');button?.classList.add('loading');
    try{
      const open=new Set([...document.querySelectorAll('.asl-detail-row:not([hidden])')].map(row=>row.id));
      const url='automation_simulation_log.php?date='+encodeURIComponent(date?.value||'')+'&page_size='+encodeURIComponent(size?.value||10)+'&log_page=<?php echo $aslPage; ?>&t='+Date.now();
      const response=await fetch(url,{cache:'no-store',credentials:'same-origin'});
      if(!response.ok)throw new Error('HTTP '+response.status);
      const html=await response.text(),doc=new DOMParser().parseFromString(html,'text/html');
      ['.asl-statuses','.asl-summary','.asl-compare','.asl-panel'].forEach(selector=>{const current=document.querySelector(selector),next=doc.querySelector(selector);if(current&&next&&current.innerHTML!==next.innerHTML)current.innerHTML=next.innerHTML});
      open.forEach(id=>{const row=document.getElementById(id),control=document.querySelector('[data-asl-detail="'+id+'"]');if(row){row.hidden=false;control?.classList.add('active')}});
      document.querySelector('.asl-live')?.classList.remove('error');bindDetails();stamp();
    }catch(error){const live=document.querySelector('.asl-live');live?.classList.add('error')}
    finally{button?.classList.remove('loading');refreshing=false}
  };
  document.getElementById('aslRefresh')?.addEventListener('click',refresh);
  bindDetails();stamp();setInterval(refresh,5000);
}());
</script>
<style>
.asl-wrap{max-width:1280px;margin:0 auto;padding:4px 2px 34px;color:#26394e}.asl-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:17px 19px;margin-bottom:12px;border:1px solid #dfe7f0;border-radius:16px;background:#fff;box-shadow:0 8px 24px rgba(31,55,89,.05)}.asl-title{display:flex;align-items:center;gap:12px}.asl-title>i{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;background:#eaf3ff;color:#1769d2;font-size:1.05rem}.asl-title h3{margin:0;font-size:1.08rem}.asl-title p{margin:3px 0 0;color:#718096;font-size:.74rem}.asl-statuses{display:flex;gap:7px;flex-wrap:wrap}.asl-status{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:20px;font-size:.68rem;font-weight:800}.asl-status.safe{background:#e8f7ef;color:#168958}.asl-status.danger{background:#fdeaea;color:#c53c3c}.asl-status.simulation{background:#e8f1ff;color:#1769d2}.asl-status.muted{background:#eef2f5;color:#758493}.asl-toolbar{display:flex;align-items:end;justify-content:flex-end;gap:9px;margin-bottom:12px}.asl-toolbar label span{display:block;margin:0 0 4px;color:#667b91;font-size:.65rem;font-weight:700}.asl-toolbar select,.asl-toolbar button{height:38px;border:1px solid #d2deea;border-radius:9px;background:#fff;color:#354f68;padding:0 10px;font-size:.73rem}.asl-toolbar button{display:flex;align-items:center;gap:6px;background:#1769d2;color:#fff;border-color:#1769d2;font-weight:750}.asl-summary{display:grid;grid-template-columns:repeat(5,1fr);gap:9px;margin-bottom:12px}.asl-summary>div{padding:13px 14px;border:1px solid #e0e7ef;border-radius:12px;background:#fff;box-shadow:0 5px 15px rgba(31,55,89,.035)}.asl-summary span,.asl-summary b{display:block}.asl-summary span{color:#77899a;font-size:.66rem}.asl-summary b{margin-top:3px;font-size:1.05rem}.asl-summary .p1 b{color:#d24747}.asl-summary .p2 b{color:#dd8b00}.asl-summary .p3 b{color:#1769d2}.asl-summary .cmd b{color:#7a4bd1}.asl-panel{overflow:hidden;border:1px solid #dfe7f0;border-radius:14px;background:#fff;box-shadow:0 8px 22px rgba(31,55,89,.045)}.asl-table-wrap{overflow-x:auto}.asl-table{width:100%;border-collapse:collapse;font-size:.72rem}.asl-table th{padding:10px 11px;background:#edf4fb;color:#47637d;text-align:left;white-space:nowrap}.asl-table td{padding:10px 11px;border-bottom:1px solid #edf1f5;vertical-align:middle}.asl-table td>b,.asl-table td>small{display:block}.asl-table td>small{color:#8a98a6}.asl-state{display:inline-flex;padding:4px 8px;border-radius:12px;font-size:.61rem;font-weight:850;white-space:nowrap}.asl-state.p1{background:#fdeaea;color:#c53c3c}.asl-state.p2{background:#fff3d8;color:#b87500}.asl-state.p3{background:#e8f1ff;color:#1769d2}.asl-command{display:flex;gap:6px;align-items:flex-start;margin:2px 0;color:#7045bd;font-size:.66rem}.asl-command i{margin-top:2px}.asl-change{display:inline-flex;align-items:center;gap:5px;color:#1769d2;font-weight:800}.asl-hold{display:inline-flex;align-items:center;gap:5px;color:#7d8b98}.asl-detail-button{border:0;background:transparent;color:#1769d2;font-size:.85rem}.asl-detail-button.active{color:#7a4bd1}.asl-detail-row td{padding:0!important;background:#f8fafc}.asl-detail-grid{display:grid;grid-template-columns:1fr 1.35fr;gap:10px;padding:12px}.asl-detail-grid section{min-width:0;padding:11px;border:1px solid #dfe7f0;border-radius:10px;background:#fff}.asl-detail-grid h5{margin:0 0 8px;color:#375b7b;font-size:.72rem}.asl-detail-grid h5 i{margin-right:5px;color:#1769d2}.asl-detail-grid pre{max-height:420px;margin:0;overflow:auto;white-space:pre-wrap;word-break:break-word;color:#40566c;font:500 .66rem/1.55 Consolas,monospace}.asl-pagination{display:flex;align-items:center;justify-content:space-between;padding:11px 13px;color:#718096;font-size:.69rem}.asl-pagination div{display:flex;gap:6px}.asl-pagination a{display:inline-flex;align-items:center;gap:5px;padding:6px 9px;border:1px solid #d3deea;border-radius:8px;color:#35658e;text-decoration:none}.asl-pagination a.disabled{opacity:.4;pointer-events:none}.asl-empty{display:flex;min-height:260px;align-items:center;justify-content:center;flex-direction:column;color:#8190a0}.asl-empty i{font-size:2rem;color:#b5c2cf}.asl-empty b{margin-top:10px;color:#516579}.asl-empty span{margin-top:3px;font-size:.7rem}@media(max-width:800px){.asl-head{align-items:flex-start;flex-direction:column}.asl-summary{grid-template-columns:repeat(2,1fr)}.asl-detail-grid{grid-template-columns:1fr}.asl-toolbar{justify-content:stretch;flex-wrap:wrap}.asl-toolbar label{flex:1}.asl-toolbar select{width:100%}}@media(max-width:480px){.asl-summary{grid-template-columns:1fr 1fr}.asl-title p{font-size:.67rem}}
.asl-status.safe{background:#eef2f5;color:#758493}.asl-status.active{background:#e8f7ef;color:#168958}.asl-status.muted{background:#fff1e4;color:#b45f16}
.asl-live{display:inline-flex;align-items:center;gap:6px;height:38px;margin-right:auto;color:#54718b;font-size:.66rem;white-space:nowrap}.asl-live>i{width:8px;height:8px;border-radius:50%;background:#20a467;box-shadow:0 0 0 4px rgba(32,164,103,.12)}.asl-live b{color:#168958;font-variant-numeric:tabular-nums}.asl-live.error>i{background:#db4949;box-shadow:0 0 0 4px rgba(219,73,73,.12)}.asl-live.error b{color:#b53838}.asl-toolbar button.loading i{animation:asl-spin .8s linear infinite}@keyframes asl-spin{to{transform:rotate(360deg)}}
.asl-compare{margin-bottom:12px;overflow:hidden;border:1px solid #dce6f0;border-radius:14px;background:#fff;box-shadow:0 7px 20px rgba(31,55,89,.04)}.asl-compare-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 15px;border-bottom:1px solid #e7edf4;background:linear-gradient(135deg,#f8fbff,#fff)}.asl-compare-head h4{margin:0;color:#294e70;font-size:.86rem}.asl-compare-head h4 i{margin-right:6px;color:#6e4dc7}.asl-compare-head p{margin:3px 0 0;color:#7a8da0;font-size:.65rem}.asl-compare-times{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}.asl-compare-times span{display:inline-flex;align-items:center;gap:5px;padding:5px 8px;border-radius:14px;background:#edf4fb;color:#56728d;font-size:.61rem}.asl-compare-times span:last-child{background:#f1ebfc;color:#7049b7}.asl-compare-times b{font-variant-numeric:tabular-nums}.asl-compare-table-wrap{overflow-x:auto}.asl-compare-table{width:100%;border-collapse:collapse;font-size:.7rem}.asl-compare-table th{padding:9px 11px;background:#f2f6fa;color:#577087;text-align:left;white-space:nowrap}.asl-compare-table td{padding:10px 11px;border-top:1px solid #edf1f5;vertical-align:middle}.asl-compare-table td>b,.asl-compare-table td>small,.asl-compare-table td>em{display:block}.asl-compare-table td>small{color:#8898a8}.asl-compare-table td>em{max-width:420px;margin-top:3px;overflow:hidden;color:#708294;font-size:.61rem;font-style:normal;text-overflow:ellipsis;white-space:nowrap}.asl-none{color:#9aa7b4}.asl-delta{color:#c66e08}.asl-mqtt-result{display:inline-flex;margin-top:3px;padding:2px 6px;border-radius:10px;font-size:.58rem;font-weight:800}.asl-mqtt-result.ok{background:#e8f7ef;color:#168958}.asl-mqtt-result.fail{background:#fdeaea;color:#c53c3c}.asl-mqtt-result.unknown{background:#eef2f5;color:#758493}.asl-compare-status{display:inline-flex;padding:4px 7px;border-radius:12px;font-size:.59rem;font-weight:850;white-space:nowrap}.asl-compare-status.same{background:#e8f7ef;color:#168958}.asl-compare-status.different{background:#fff1df;color:#b66a0a}.asl-compare-status.automation_only{background:#e8f1ff;color:#1769d2}.asl-compare-status.mqtt_only{background:#f0ecf9;color:#7049b7}.asl-compare-empty{display:flex;align-items:center;justify-content:center;gap:8px;min-height:76px;color:#7c8fa1;font-size:.7rem}
@media(max-width:800px){.asl-live{width:100%;order:-1}.asl-toolbar button{flex:1;justify-content:center}}
@media(max-width:800px){.asl-compare-head{align-items:flex-start;flex-direction:column}.asl-compare-times{justify-content:flex-start}.asl-compare-table{min-width:760px}}
.asl-table{table-layout:fixed}.asl-table .asl-col-time{width:15%}.asl-table .asl-col-state{width:17%}.asl-table .asl-col-main{width:11%}.asl-table .asl-col-battery{width:18%}.asl-table .asl-col-soc{width:8%}.asl-table .asl-col-command{width:25%}.asl-table .asl-col-detail{width:6%}.asl-table tbody>tr:not(.asl-detail-row)>td{overflow:hidden;text-overflow:ellipsis}.asl-table td:nth-child(3),.asl-table td:nth-child(4),.asl-table td:nth-child(5){font-variant-numeric:tabular-nums;white-space:nowrap}@media(max-width:800px){.asl-table{min-width:850px}}
</style>
