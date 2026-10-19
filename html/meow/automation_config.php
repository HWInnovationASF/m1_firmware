<?php
$acDevices = json_decode($dvl_txt ?? '{}', true) ?: [];
$acDetail = json_decode($detail_rgl_txt ?? '{}', true) ?: [];
$acPath = './config/automation_control.json';
$acConfig = file_exists($acPath) ? json_decode(file_get_contents($acPath), true) : [];
if (!is_array($acConfig)) $acConfig = [];
$acTouConfig = json_decode(@file_get_contents('/home/mdbcare/python/tou_schedule.json'), true);
if (!is_array($acTouConfig)) $acTouConfig = [];
$acTouHolidayDates = [];
foreach ([(string)date('Y'), (string)((int)date('Y') + 1)] as $acTouYear) {
    $acTouYearHolidays = $acTouConfig[$acTouYear]['holidays'] ?? [];
    if (!is_array($acTouYearHolidays)) continue;
    foreach ($acTouYearHolidays as $acTouHoliday) {
        if (is_string($acTouHoliday) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $acTouHoliday)) {
            $acTouHolidayDates[$acTouHoliday] = true;
        }
    }
}
$acTouHolidayDates = array_keys($acTouHolidayDates);
sort($acTouHolidayDates);
$acCanEdit = in_array(strtolower($_SESSION['user_type'] ?? ''), ['admin', 'superadmin'], true);
$acSelectedDevice = isset($_GET['device']) ? (string)$_GET['device'] : '';
if (!isset($acDevices[$acSelectedDevice])) $acSelectedDevice = '';
$acSelectedSection = isset($_GET['section']) ? (string)$_GET['section'] : '';
if (!in_array($acSelectedSection, ['general', 'solar', 'battery', 'limit', 'devices'], true)) $acSelectedSection = '';
$acMeters = [];
foreach ($acDevices as $id => $device) {
    $model = $device['model'] ?? '';
    if (($acDetail[$model]['type'] ?? '') === 'meter') $acMeters[$id] = $device['name'] ?? $id;
}
?>
<link rel="stylesheet" href="./assets/jquery-datetimepicker/2.5.20/jquery.datetimepicker.min.css">
<div class="ac-wrap">
  <div class="ac-head">
    <div><h3><i class="fa-solid fa-sliders"></i> Automation Control Config</h3><p>กำหนดหน้าที่และสิทธิ์ควบคุมของอุปกรณ์ในระบบ</p></div>
    <label class="ac-master" id="acMaster"><input type="checkbox" id="acEnabled"> <span><b>Automation <strong id="acMasterBadge">...</strong></b><small id="acMasterText">กำลังโหลดสถานะ...</small></span></label>
  </div>

  <div class="ac-card ac-general" id="acGeneralSection">
    <h5>Control reference</h5>
    <label class="ac-feature-toggle ac-simulation-toggle"><input type="checkbox" id="acSimulationLog"><span><b>Simulation Log (บล็อก Modbus Write)</b><small>ทำงานตาม Config, Schedule และสิทธิ์ควบคุมจริงทุกข้อ แต่บันทึก WOULD WRITE แทนการเขียนค่าไปยังอุปกรณ์</small></span></label>
    <div class="ac-grid">
      <label><span>Main Meter</span><select id="acMainMeter"><?php foreach($acMeters as $id=>$name): ?><option value="<?php echo htmlspecialchars($id,ENT_QUOTES); ?>"><?php echo htmlspecialchars("$name [$id]",ENT_QUOTES); ?></option><?php endforeach; ?></select></label>
      <label><span>Import threshold</span><div class="ac-unit"><input id="acImport" type="number" min="0" step="0.1"><b>kW</b></div></label>
      <label><span>Export threshold</span><div class="ac-unit"><input id="acExport" type="number" max="0" step="0.1"><b>kW</b></div></label>
      <label><span>Control interval (0 = Immediate)</span><div class="ac-unit"><input id="acInterval" type="number" min="0" max="3600"><b>sec</b></div></label>
      <label class="ac-system-limit"><span>Peak Limit ของโหลดรวม</span><div class="ac-limit-row"><label class="ac-inline"><input id="acSystemLimitEnabled" type="checkbox"> Enable</label><div class="ac-unit"><input id="acSystemLimitKw" type="number" min="0" max="100000" step="0.1" placeholder="เช่น 300"><b>kW</b></div></div><small>กรอกกำลังโหลดรวมสูงสุดที่ยอมรับได้ เช่น 300 kW เมื่อโหลดเกินค่านี้ ระบบจะนำพลังงานสำรองจาก Battery มาช่วยจ่ายเพื่อลด Peak</small></label>
      <label><span>Peak response delay</span><div class="ac-unit"><input id="acSystemLimitDelay" type="number" min="0" max="30" step="1"><b>sec</b></div><small>ตั้ง 0 เพื่อสั่งลด Peak ทันที หรือเพิ่มเวลาเพื่อกรองค่ากระชาก</small></label>
    </div>
  </div>

  <div class="ac-schedule-columns">
    <div class="ac-card ac-schedule-card ac-solar-schedule" id="acSolarSection">
      <div class="ac-section-head"><div class="ac-title-icon solar"><i class="fa-solid fa-solar-panel"></i><div><h5>Solar Automation</h5><small>กำหนดช่วงเวลาที่อนุญาตให้ Automation ควบคุม Solar Inverter</small></div></div><span class="ac-everyday"><i class="fa-solid fa-calendar-check"></i> ทุกวัน</span></div>
      <label class="ac-feature-toggle"><input type="checkbox" id="acSolarScheduleEnabled"><span><b>เปิดควบคุม Solar ตามเวลา</b><small>เมื่อปิด ระบบจะไม่ใช้ตารางเวลานี้ควบคุม Inverter</small></span></label>
      <div class="ac-solar-time-row">
        <label><span>เวลาเริ่มทำงาน</span><input id="acSolarStart" class="ac-time24" type="text" inputmode="numeric" maxlength="5" pattern="(?:[01]\d|2[0-3]):[0-5]\d" placeholder="HH:mm" required></label>
        <i class="fa-solid fa-arrow-right"></i>
        <label><span>เวลาสิ้นสุด</span><input id="acSolarEnd" class="ac-time24" type="text" inputmode="numeric" maxlength="5" pattern="(?:[01]\d|2[0-3]):[0-5]\d" placeholder="HH:mm" required></label>
      </div>
      <small class="ac-time-note"><i class="fa-solid fa-circle-info"></i> ใช้รูปแบบเวลา 24 ชั่วโมง และมีผลทุกวันรวมวันเสาร์–อาทิตย์</small>
    </div>
    <div class="ac-card ac-schedule-card ac-battery-schedule" id="acBatterySection">
      <div class="ac-section-head"><div><h5><i class="fa-solid fa-battery-half"></i> Battery Schedule</h5><small>ช่วงเวลาชาร์จและ Discharge ของ Battery</small></div><label><input type="checkbox" id="acBatteryScheduleEnabled"> ใช้ตารางเวลา</label></div>
      <div class="ac-grid ac-schedule">
        <label><span>Start (24h)</span><input id="acBatteryStart" class="ac-time24" type="text" inputmode="numeric" maxlength="5" pattern="(?:[01]\d|2[0-3]):[0-5]\d" placeholder="HH:mm" required></label>
        <label><span>End (24h)</span><input id="acBatteryEnd" class="ac-time24" type="text" inputmode="numeric" maxlength="5" pattern="(?:[01]\d|2[0-3]):[0-5]\d" placeholder="HH:mm" required></label>
        <label><span>Min SoC</span><div class="ac-unit"><input id="acBatterySocMinLegacy" type="number" min="0" max="100" step="1"><b>%</b></div></label>
        <label><span>Max SoC</span><div class="ac-unit"><input id="acBatterySocMaxLegacy" type="number" min="0" max="100" step="1"><b>%</b></div></label>
        <label class="ac-inline"><input type="checkbox" id="acBatteryWeekend"> อนุญาตวันเสาร์–อาทิตย์</label>
      </div>
    </div>
  </div>

  <div class="ac-card ac-battery-strategy" id="acBatteryStrategySection">
    <div class="ac-section-head">
      <div class="ac-title-icon battery"><i class="fa-solid fa-battery-half"></i><div><h5>Battery Automation</h5><small>ตั้งค่าระดับพลังงาน เวลา และกำลัง Charge/Discharge</small></div></div>
      <label class="ac-switch-row"><span>Enable Strategy</span><label class="ac-switch"><input type="checkbox" id="acStrategyEnabled"><span></span></label></label>
    </div>
    <div class="ac-grid" id="acStrategyFields">
      <div class="ac-group-title"><i class="fa-solid fa-shield-halved"></i> กำหนดช่วง SoC</div>
      <label class="ac-soc-field"><span>SoC ต่ำสุด (%)</span><input id="acBatterySocMin" type="number" min="0" max="100" step="1"><small>ไม่จ่ายพลังงานเมื่อถึงค่านี้</small></label>
      <label class="ac-soc-field"><span>SoC สูงสุด (%)</span><input id="acBatterySocMax" type="number" min="0" max="100" step="1"><small>ไม่เก็บพลังงานเพิ่มเมื่อถึงค่านี้</small></label>
      <div class="ac-group-title"><i class="fa-solid fa-battery-full"></i> ข้อมูลและขีดจำกัดแบตเตอรี่</div>
      <label><span>Battery Capacity (kWh)</span><input id="acCapacity" type="number" min="0.1" step="0.1"></label>
      <label><span>Maximum Charge (kW)</span><input id="acStrategyMaxCharge" type="number" min="0" step="0.1"></label>
      <div class="ac-group-title"><i class="fa-solid fa-moon"></i> การชาร์จช่วง Off-Peak</div>
      <label><span>Off-Peak Start (24h)</span><input id="acOffStart" class="ac-time24" type="text" maxlength="5" pattern="(?:[01]\d|2[0-3]):[0-5]\d" required></label>
      <label><span>Off-Peak End (24h)</span><input id="acOffEnd" class="ac-time24" type="text" maxlength="5" pattern="(?:[01]\d|2[0-3]):[0-5]\d" required><input id="acOffDuration" type="hidden"><small id="acOffPeakEstimate" class="ac-time-estimate"><i class="fa-solid fa-clock"></i> Duration —</small></label>
      <label><span>Dynamic Charge Target SoC (%)</span><input id="acDynamicChargeTargetSoc" type="number" min="0" max="100" step="1"></label>
      <label><span>Charge Efficiency (%)</span><input id="acChargeEfficiency" type="number" min="1" max="100" step="0.1"></label>
      <label><span>Dynamic Charge Round Step (kW)</span><input id="acChargeStep" type="number" min="0.1" step="0.1"><small>ปัดกำลังชาร์จขึ้นเป็นช่วง เช่น 10 kW</small></label>
      <label><span>Discharge Step (kW)</span><input id="acDischargeStep" type="number" min="0.1" step="0.1"></label>
      <div class="ac-group-title"><i class="fa-solid fa-arrows-rotate"></i> การควบคุมตลอดเวลา</div>
      <label><span>Maintain Main Power From (kW)</span><input id="acMinL" type="number" step="0.1"><small>ทำงานทุกเวลา: หาก Main ต่ำกว่าค่านี้ ระบบจะเพิ่มกำลังชาร์จ</small></label>
      <label><span>Stop Battery Charging At (kW)</span><input id="acMinH" type="number" step="0.1"><small>ทำงานทุกเวลา: เมื่อ Main ถึงค่านี้ ระบบจะหยุดชาร์จแบตเตอรี่</small></label>
      <div class="ac-group-title"><i class="fa-solid fa-bolt"></i> การควบคุมช่วง On-Peak</div>
      <label><span>On-Peak Start (24h)</span><input id="acOnStart" class="ac-time24" type="text" maxlength="5" pattern="(?:[01]\d|2[0-3]):[0-5]\d" required></label>
      <label><span>On-Peak End (24h)</span><input id="acOnEnd" class="ac-time24" type="text" maxlength="5" pattern="(?:[01]\d|2[0-3]):[0-5]\d" required></label>
      <label><span>Daytime Control Start (24h)</span><input id="acDayStart" class="ac-time24" type="text" maxlength="5" pattern="(?:[01]\d|2[0-3]):[0-5]\d" required></label>
      <label><span>Daytime Control End (24h)</span><input id="acDayEnd" class="ac-time24" type="text" maxlength="5" pattern="(?:[01]\d|2[0-3]):[0-5]\d" required></label>
      <label><span>Daytime Minimum SoC (%)</span><input id="acDayMinSoc" type="number" min="0" max="100" step="1"></label>
      <label><span>Daytime Grid Trigger (kW)</span><input id="acDayGridTrigger" type="number" min="0" step="0.1"></label>
      <label><span>Daytime Main Minimum (kW)</span><input id="acDayMainMin" type="number" min="0" step="0.1"></label>
      <label><span>Daytime Main Maximum (kW)</span><input id="acDayMainMax" type="number" min="0" step="0.1"></label>
      <label><span>Daytime Main Target (kW)</span><input id="acDayMainAvg" type="number" min="0" step="0.1"></label>
      <label><span>Daytime Discharge Limit (kW)</span><input id="acDayDischargeKw" type="number" min="0" step="0.1"></label>
      <label><span>Peak Minimum (kW)</span><input id="acMaxL" type="number" step="0.1"><small>เมื่อ Main ต่ำกว่าค่านี้ ระบบจะค่อย ๆ ลดกำลัง Discharge</small></label>
      <label><span>Peak Maximum (kW)</span><input id="acMaxH" type="number" step="0.1"><small>เมื่อ Main เกินค่านี้ ระบบจะเพิ่ม Discharge เพื่อลด Peak ลงสู่ค่ากลาง</small></label>
      <label><span>Peak Reserve SoC (%)</span><input id="acPeakReserveSoc" type="number" min="0" max="100" step="1"><small>หาก SoC ต่ำกว่าค่านี้ จะไม่ล็อก Deadband และจ่ายไฟเฉพาะเมื่อ Main เกิน Peak Maximum</small></label>
      <div class="ac-peak-average"><i class="fa-solid fa-calculator"></i><span>Peak Average คำนวณอัตโนมัติจาก (Minimum + Maximum) ÷ 2 = <b id="acPeakAverageValue">—</b> kW</span></div>
      <label><span>Scheduled Discharge Start (24h)</span><input id="acDischargeStart" class="ac-time24" type="text" maxlength="5" pattern="(?:[01]\d|2[0-3]):[0-5]\d" required></label>
      <label><span>Scheduled Discharge End (24h)</span><input id="acDischargeEnd" class="ac-time24" type="text" maxlength="5" pattern="(?:[01]\d|2[0-3]):[0-5]\d" required><input id="acDischargeDuration" type="hidden"><small id="acDischargeEstimate" class="ac-time-estimate"><i class="fa-solid fa-clock"></i> Duration —</small></label>
      <label><span>Scheduled Discharge Minimum SoC (%)</span><input id="acScheduledDischargeMinSoc" type="number" min="0" max="100" step="1"><small>เริ่มจ่ายตามตารางเมื่อ SoC สูงกว่าค่านี้ โดยยังคงเคารพ Min SoC หลัก</small></label>
      <label><span>Scheduled Discharge Target (kW)</span><input id="acScheduledDischargeKw" type="number" min="0" step="0.1"><small>เพิ่มกำลังตาม Discharge Step จนถึงค่านี้ แต่ไม่เกิน Max kW ของ Battery</small></label>
      <div class="ac-group-title"><i class="fa-solid fa-calendar-day"></i> การควบคุมวันเสาร์–อาทิตย์ / Off-Day</div>
      <label><span>Off-Day Minimum SoC (%)</span><input id="acOffDayMinSoc" type="number" min="0" max="100" step="1"></label>
      <label><span>Off-Day Main Minimum (kW)</span><input id="acOffDayMainMin" type="number" min="0" step="0.1"></label>
      <label><span>Off-Day Main Maximum (kW)</span><input id="acOffDayMainMax" type="number" min="0" step="0.1"></label>
      <label><span>Off-Day Main Target (kW)</span><input id="acOffDayMainAvg" type="number" min="0" step="0.1"></label>
      <label><span>Off-Day Discharge Limit (kW)</span><input id="acOffDayDischargeKw" type="number" min="0" step="0.1"></label>
      <div class="ac-strategy-holidays"><span class="ac-field-title">TOU Schedule</span><div class="ac-tou-calendar" id="acTouCalendar"><div class="ac-tou-head"><button type="button" data-ac-tou-month="-1" aria-label="เดือนก่อนหน้า">&#8249;</button><strong id="acTouMonthLabel"></strong><button type="button" data-ac-tou-month="1" aria-label="เดือนถัดไป">&#8250;</button></div><div class="ac-tou-week"><span>อา.</span><span>จ.</span><span>อ.</span><span>พ.</span><span>พฤ.</span><span>ศ.</span><span>ส.</span></div><div class="ac-tou-grid" id="acTouGrid"></div><input id="acHolidayDates" type="hidden"><div class="ac-tou-legend"><span><b>✓</b> บันทึกแล้ว</span><span><b>●</b> รอบันทึก</span></div><small>ซิงค์วันหยุดจาก MQTT และหน้า Energy · คลิกวันที่เพื่อเพิ่มหรือลบ</small></div></div>
    </div>
  </div>

  <div class="ac-card" id="acDevicesSection">
    <div class="ac-section-head"><div class="ac-title-icon devices"><i class="fa-solid fa-microchip"></i><div><h5>อุปกรณ์ที่ Automation ควบคุม</h5><small>เปิด Control เฉพาะอุปกรณ์ที่ต้องการอนุญาตให้ระบบสั่งงาน</small></div></div><input id="acSearch" type="search" placeholder="ค้นหาชื่อหรือรหัสอุปกรณ์..."></div>
    <div class="ac-role-legend"><span class="solar">Solar Inverter</span><span class="battery">Battery</span><span class="ev">EV</span><span class="monitor">Monitor only</span></div>
    <div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>Device</th><th>Model / Type</th><th>Role</th><th>Control</th><th>Min kW</th><th>Max kW</th><th>Priority</th></tr></thead><tbody id="acDeviceRows"></tbody></table></div>
  </div>

  <div class="ac-foot"><span id="acStatus">ยังไม่มีการเปลี่ยนแปลง</span><button id="acSave" type="button"><i class="fa-solid fa-floppy-disk"></i> Save configuration</button></div>
</div>

<script src="./assets/jquery-datetimepicker/2.5.20/jquery.datetimepicker.full.min.js"></script>
<script>
(function(){
  const devices=<?php echo json_encode($acDevices,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  const details=<?php echo json_encode($acDetail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  let config=<?php echo json_encode($acConfig,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  const mqttTouHolidayDates=<?php echo json_encode($acTouHolidayDates,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  const selectedDevice=<?php echo json_encode($acSelectedDevice,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  const selectedSection=<?php echo json_encode($acSelectedSection,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  const canEdit=<?php echo $acCanEdit ? 'true' : 'false'; ?>;
  const roleFromType=type=>type==='inverter'?'solar_inverter':(type==='battery'?'battery':(type==='ev'?'ev':'monitor'));
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const typeOf=id=>details[devices[id]?.model]?.type||'unknown';
  const rows=document.getElementById('acDeviceRows');
  const acEnabled=document.getElementById('acEnabled');
  const acMaster=document.getElementById('acMaster');
  let acMasterSaving=false;
  function updateAcMaster(enabled){enabled=!!enabled;acEnabled.checked=enabled;acMaster.classList.toggle('is-on',enabled);acMaster.classList.toggle('is-off',!enabled);document.getElementById('acMasterBadge').textContent=enabled?'ON':'OFF';document.getElementById('acMasterText').textContent=enabled?'กำลังทำงาน — ระบบสามารถควบคุมอุปกรณ์อัตโนมัติ':'หยุดทำงานแล้ว — ระบบจะไม่ส่งคำสั่งอัตโนมัติ';}
  let acTouMonth=new Date(new Date().getFullYear(),new Date().getMonth(),1);
  let acTouSavedDates=new Set();
  const acTouKey=date=>date.getFullYear()+'-'+String(date.getMonth()+1).padStart(2,'0')+'-'+String(date.getDate()).padStart(2,'0');

  function renderAcTouCalendar(){
    const grid=document.getElementById('acTouGrid');
    const store=document.getElementById('acHolidayDates');
    if(!grid||!store)return;
    const selected=new Set(store.value.split(',').filter(Boolean));
    document.getElementById('acTouMonthLabel').textContent=new Intl.DateTimeFormat('th-TH',{month:'long',year:'numeric'}).format(acTouMonth);
    grid.replaceChildren();
    const first=new Date(acTouMonth.getFullYear(),acTouMonth.getMonth(),1);
    const start=new Date(first);start.setDate(1-first.getDay());
    const today=acTouKey(new Date());
    for(let index=0;index<42;index++){
      const date=new Date(start);date.setDate(start.getDate()+index);
      const key=acTouKey(date);
      const button=document.createElement('button');
      button.type='button';button.dataset.acTouDay=key;button.textContent=date.getDate();
      if(date.getMonth()!==acTouMonth.getMonth())button.classList.add('outside');
      if(key===today)button.classList.add('today');
      if(selected.has(key)){
        button.classList.add(acTouSavedDates.has(key)?'saved':'pending');
        const mark=document.createElement('b');mark.textContent=acTouSavedDates.has(key)?'✓':'●';button.appendChild(mark);
      }
      grid.appendChild(button);
    }
  }

  function captureRows(){
    if(!config.devices||Array.isArray(config.devices))config.devices={};
    rows.querySelectorAll('tr').forEach(tr=>{const previous=config.devices[tr.dataset.device]||{};config.devices[tr.dataset.device]={role:tr.querySelector('.ac-role').value,control_enabled:tr.querySelector('.ac-control').checked,min_kw:Number(tr.querySelector('.ac-min').value||0),max_kw:Number(tr.querySelector('.ac-max').value||0),min_ref:previous.min_ref||'',max_ref:previous.max_ref||'',priority:Number(tr.querySelector('.ac-priority').value||100)};});
  }
  function renderRows(preserve=true){
    if(preserve)captureRows();
    const q=document.getElementById('acSearch').value.toLowerCase();
    rows.innerHTML=Object.keys(devices).filter(id=>id!==document.getElementById('acMainMeter').value).filter(id=>(id+' '+(devices[id].name||'')+' '+(devices[id].model||'')).toLowerCase().includes(q)).map(id=>{
      const saved=config.devices?.[id]||{};const type=typeOf(id);const role=saved.role||roleFromType(type);const control=!!saved.control_enabled;
      return `<tr data-device="${esc(id)}"><td><b>${esc(devices[id].name||id)}</b><small>${esc(id)}</small></td><td>${esc(devices[id].model||'—')}<small>${esc(type)}</small></td><td><select class="ac-role"><option value="monitor">Monitor only</option><option value="solar_inverter">Solar Inverter</option><option value="battery">Battery</option><option value="ev">EV Charger</option></select></td><td><label class="ac-switch"><input class="ac-control" type="checkbox" ${control?'checked':''}><span></span></label></td><td><input class="ac-min" type="number" min="-100000" max="100000" step=".1" value="${Number(saved.min_kw??0)}"></td><td><input class="ac-max" type="number" min="-100000" max="100000" step=".1" value="${Number(saved.max_kw??0)}"></td><td><input class="ac-priority" type="number" min="1" max="999" value="${Number(saved.priority??100)}"></td></tr>`;
    }).join('');
    rows.querySelectorAll('tr').forEach(tr=>{const id=tr.dataset.device;const role=config.devices?.[id]?.role||roleFromType(typeOf(id));tr.querySelector('.ac-role').value=role;syncRow(tr);});
  }
  function syncRow(tr){
    const role=tr.querySelector('.ac-role').value;
    const active=role!=='monitor';
    const battery=role==='battery';
    const minInput=tr.querySelector('.ac-min');
    const maxInput=tr.querySelector('.ac-max');
    minInput.min=battery?'-100000':'0';
    maxInput.min=battery?'-100000':'0';
    minInput.max='100000';
    maxInput.max='100000';
    if(!battery){
      if(Number(minInput.value)<0)minInput.value='0';
      if(Number(maxInput.value)<0)maxInput.value='0';
    }
    tr.querySelector('.ac-control').disabled=!active;
    if(!active)tr.querySelector('.ac-control').checked=false;
    tr.classList.toggle('controllable',active&&tr.querySelector('.ac-control').checked);
  }
  function loadForm(){
    if(!config.devices||Array.isArray(config.devices))config.devices={};
    const legacy=config.schedule||{};
    const solar=config.schedules?.solar_inverter||legacy;
    const battery=config.schedules?.battery||legacy;
    updateAcMaster(config.enabled);
    document.getElementById('acSimulationLog').checked=!!config.simulation_log_enabled;
    document.getElementById('acMainMeter').value=config.main_meter||Object.keys(devices).find(id=>typeOf(id)==='meter')||'';
    document.getElementById('acImport').value=config.import_threshold_kw??10;
    document.getElementById('acExport').value=config.export_threshold_kw??-10;
    document.getElementById('acInterval').value=config.control_interval_sec??0;
    document.getElementById('acSystemLimitEnabled').checked=!!config.system_power_limit?.enabled;
    document.getElementById('acSystemLimitKw').value=config.system_power_limit?.max_kw??0;
    document.getElementById('acSystemLimitDelay').value=config.system_power_limit?.delay_sec??1;
    document.getElementById('acSolarScheduleEnabled').checked=!!solar.enabled;
    document.getElementById('acSolarStart').value=solar.start||'07:30';
    document.getElementById('acSolarEnd').value=solar.end||'17:30';
    document.getElementById('acBatteryScheduleEnabled').checked=!!battery.enabled;
    document.getElementById('acBatteryStart').value=battery.start||'00:00';
    document.getElementById('acBatteryEnd').value=battery.end||'23:59';
    document.getElementById('acBatterySocMin').value=config.battery_soc_limits?.min??10;
    document.getElementById('acBatterySocMax').value=config.battery_soc_limits?.max??95;
    document.getElementById('acBatteryWeekend').checked=!!battery.weekend_enabled;
    const strategy=config.battery_strategy||{};
    document.getElementById('acStrategyEnabled').checked=strategy.enabled!==false;
    document.getElementById('acCapacity').value=strategy.capacity_kwh??240;
    document.getElementById('acStrategyMaxCharge').value=strategy.max_charge_kw??100;
    document.getElementById('acOffStart').value=strategy.off_peak_start||'06:00';
    document.getElementById('acOffDuration').value=strategy.off_peak_duration_hours??3;
    updateOffPeakEndFromDuration();
    document.getElementById('acDynamicChargeTargetSoc').value=strategy.dynamic_charge_target_soc??85;
    document.getElementById('acChargeEfficiency').value=strategy.charge_efficiency_percent??90;
    document.getElementById('acChargeStep').value=strategy.charge_step_kw??10;
    document.getElementById('acDischargeStep').value=strategy.discharge_step_kw??10;
    document.getElementById('acOnStart').value=strategy.on_peak_start||'09:00';
    document.getElementById('acOnEnd').value=strategy.on_peak_end||'22:00';
    document.getElementById('acDayStart').value=strategy.day_control_start||'09:00';
    document.getElementById('acDayEnd').value=strategy.day_control_end||'18:00';
    document.getElementById('acDayMinSoc').value=strategy.day_min_soc??60;
    document.getElementById('acDayGridTrigger').value=strategy.day_grid_trigger_kw??50;
    document.getElementById('acDayMainMin').value=strategy.day_main_min_kw??40;
    document.getElementById('acDayMainMax').value=strategy.day_main_max_kw??70;
    document.getElementById('acDayMainAvg').value=strategy.day_main_avg_kw??55;
    document.getElementById('acDayDischargeKw').value=strategy.day_discharge_kw??100;
    document.getElementById('acMinL').value=strategy.min_l_kw??10;
    document.getElementById('acMinH').value=strategy.min_h_kw??30;
    document.getElementById('acMaxL').value=strategy.max_l_kw??470;
    document.getElementById('acMaxH').value=strategy.max_h_kw??500;
    document.getElementById('acPeakReserveSoc').value=strategy.peak_reserve_soc??50;
    updatePeakAverage();
    document.getElementById('acDischargeStart').value=strategy.discharge_start||'19:00';
    document.getElementById('acDischargeDuration').value=strategy.discharge_duration_hours??3;
    document.getElementById('acScheduledDischargeMinSoc').value=strategy.scheduled_discharge_min_soc??10;
    document.getElementById('acScheduledDischargeKw').value=strategy.scheduled_discharge_kw??50;
    document.getElementById('acOffDayMinSoc').value=strategy.off_day_min_soc??85;
    document.getElementById('acOffDayMainMin').value=strategy.off_day_main_min_kw??10;
    document.getElementById('acOffDayMainMax').value=strategy.off_day_main_max_kw??40;
    document.getElementById('acOffDayMainAvg').value=strategy.off_day_main_avg_kw??25;
    document.getElementById('acOffDayDischargeKw').value=strategy.off_day_discharge_kw??0;
    updateDischargeEndFromDuration();
    const strategyHolidayDates=Array.isArray(strategy.holiday_dates)?strategy.holiday_dates:[];
    const holidayDates=[...new Set([...mqttTouHolidayDates,...strategyHolidayDates])].sort();
    document.getElementById('acHolidayDates').value=holidayDates.join(',');
    acTouSavedDates=new Set(holidayDates);
    if(holidayDates[0]){
      const parts=holidayDates[0].split('-').map(Number);
      if(parts.length===3)acTouMonth=new Date(parts[0],parts[1]-1,1);
    }
    renderAcTouCalendar();
    syncStrategyFields();
    renderRows(false);
    if(selectedDevice){
      document.getElementById('acSearch').value=selectedDevice;
      renderRows();
      const selectedRow=[...rows.querySelectorAll('tr')].find(row=>row.dataset.device===selectedDevice);
      if(selectedRow){
        selectedRow.classList.add('ac-selected');
        setTimeout(()=>selectedRow.scrollIntoView({behavior:'smooth',block:'center'}),100);
      }
    }else if(selectedSection){
      const sectionMap={
        general:'acGeneralSection',
        solar:'acSolarSection',
        battery:'acBatteryStrategySection',
        limit:'acGeneralSection',
        devices:'acDevicesSection'
      };
      const selectedCard=document.getElementById(sectionMap[selectedSection]);
      if(selectedCard){
        selectedCard.classList.add('ac-section-selected');
        if(selectedSection==='limit'){
          document.querySelector('.ac-system-limit')?.classList.add('ac-field-selected');
        }
        setTimeout(()=>selectedCard.scrollIntoView({behavior:'smooth',block:'center'}),100);
      }
    }
  }
  function collect(){
    captureRows();
    return {
      enabled:document.getElementById('acEnabled').checked,
      simulation_log_enabled:document.getElementById('acSimulationLog').checked,
      main_meter:document.getElementById('acMainMeter').value,
      import_threshold_kw:Number(document.getElementById('acImport').value),
      export_threshold_kw:Number(document.getElementById('acExport').value),
      control_interval_sec:Number(document.getElementById('acInterval').value),
      system_power_limit:{enabled:document.getElementById('acSystemLimitEnabled').checked,max_kw:Number(document.getElementById('acSystemLimitKw').value),delay_sec:Number(document.getElementById('acSystemLimitDelay').value||0)},
      battery_soc_limits:{min:Number(document.getElementById('acBatterySocMin').value),max:Number(document.getElementById('acBatterySocMax').value)},
      q_control:config.q_control||{enabled:true,use_q_comp:true,qt_main_max:300,qt_main_min:270,min_soc:20,battery_max_kvar:100},
      battery_strategy:{
        enabled:document.getElementById('acStrategyEnabled').checked,
        capacity_kwh:Number(document.getElementById('acCapacity').value),
        max_charge_kw:Number(document.getElementById('acStrategyMaxCharge').value),
        off_peak_start:document.getElementById('acOffStart').value,
        off_peak_duration_hours:Number(document.getElementById('acOffDuration').value),
        dynamic_charge_target_soc:Number(document.getElementById('acDynamicChargeTargetSoc').value),
        charge_efficiency_percent:Number(document.getElementById('acChargeEfficiency').value),
        charge_step_kw:Number(document.getElementById('acChargeStep').value),
        discharge_step_kw:Number(document.getElementById('acDischargeStep').value),
        normal_max_step_kw:Number(config.battery_strategy?.normal_max_step_kw??50),
        on_peak_start:document.getElementById('acOnStart').value,
        on_peak_end:document.getElementById('acOnEnd').value,
        day_control_start:document.getElementById('acDayStart').value,
        day_control_end:document.getElementById('acDayEnd').value,
        peak_window_end:config.battery_strategy?.peak_window_end||'18:00',
        day_min_soc:Number(document.getElementById('acDayMinSoc').value),
        day_grid_trigger_kw:Number(document.getElementById('acDayGridTrigger').value),
        day_main_min_kw:Number(document.getElementById('acDayMainMin').value),
        day_main_max_kw:Number(document.getElementById('acDayMainMax').value),
        day_main_avg_kw:Number(document.getElementById('acDayMainAvg').value),
        day_discharge_kw:Number(document.getElementById('acDayDischargeKw').value),
        min_l_kw:Number(document.getElementById('acMinL').value),
        min_h_kw:Number(document.getElementById('acMinH').value),
        max_l_kw:Number(document.getElementById('acMaxL').value),
        max_h_kw:Number(document.getElementById('acMaxH').value),
        peak_reserve_soc:Number(document.getElementById('acPeakReserveSoc').value),
        discharge_start:document.getElementById('acDischargeStart').value,
        discharge_duration_hours:Number(document.getElementById('acDischargeDuration').value),
        scheduled_discharge_min_soc:Number(document.getElementById('acScheduledDischargeMinSoc').value),
        scheduled_discharge_kw:Number(document.getElementById('acScheduledDischargeKw').value),
        off_day_min_soc:Number(document.getElementById('acOffDayMinSoc').value),
        off_day_main_min_kw:Number(document.getElementById('acOffDayMainMin').value),
        off_day_main_max_kw:Number(document.getElementById('acOffDayMainMax').value),
        off_day_main_avg_kw:Number(document.getElementById('acOffDayMainAvg').value),
        off_day_discharge_kw:Number(document.getElementById('acOffDayDischargeKw').value),
        holiday_dates:document.getElementById('acHolidayDates').value.split(/[\s,]+/).map(value=>value.trim()).filter(Boolean)
      },
      schedules:{solar_inverter:{enabled:document.getElementById('acSolarScheduleEnabled').checked,start:document.getElementById('acSolarStart').value,end:document.getElementById('acSolarEnd').value,weekend_enabled:true},battery:{enabled:document.getElementById('acBatteryScheduleEnabled').checked,start:document.getElementById('acBatteryStart').value,end:document.getElementById('acBatteryEnd').value,weekend_enabled:document.getElementById('acBatteryWeekend').checked}},
      devices:config.devices
    };
  }
  function syncStrategyFields(){
    document.getElementById('acStrategyFields').classList.toggle('is-hidden',!document.getElementById('acStrategyEnabled').checked);
  }
  function estimatedEndTime(start,duration){
    const match=String(start||'').match(/^(\d{2}):(\d{2})$/),hours=Number(duration);
    if(!match||!Number.isFinite(hours)||hours<=0)return null;
    const startMinutes=Number(match[1])*60+Number(match[2]);
    if(startMinutes<0||startMinutes>=1440)return null;
    const total=startMinutes+Math.round(hours*60),dayOffset=Math.floor(total/1440),clock=total%1440;
    return {time:String(Math.floor(clock/60)).padStart(2,'0')+':'+String(clock%60).padStart(2,'0'),dayOffset};
  }
  function durationBetweenTimes(start,end){
    const startMatch=String(start||'').match(/^(\d{2}):(\d{2})$/),endMatch=String(end||'').match(/^(\d{2}):(\d{2})$/);
    if(!startMatch||!endMatch)return null;
    const startMinutes=Number(startMatch[1])*60+Number(startMatch[2]),endMinutes=Number(endMatch[1])*60+Number(endMatch[2]);
    if(startMinutes>=1440||endMinutes>=1440)return null;
    const minutes=(endMinutes-startMinutes+1440)%1440||1440;
    return minutes/60;
  }
  function renderOffPeakDuration(){
    const output=document.getElementById('acOffPeakEstimate');
    if(!output)return;
    const duration=Number(document.getElementById('acOffDuration').value);
    output.innerHTML='<i class="fa-solid fa-clock"></i> '+(Number.isFinite(duration)&&duration>0?'Duration <b>'+Number(duration.toFixed(2))+' ชั่วโมง</b>':'Duration —');
  }
  function updateOffPeakEndFromDuration(){
    const estimate=estimatedEndTime(document.getElementById('acOffStart').value,document.getElementById('acOffDuration').value);
    if(estimate)document.getElementById('acOffEnd').value=estimate.time;
    renderOffPeakDuration();
  }
  function updateOffPeakDurationFromEnd(){
    const duration=durationBetweenTimes(document.getElementById('acOffStart').value,document.getElementById('acOffEnd').value);
    if(duration!==null)document.getElementById('acOffDuration').value=Number(duration.toFixed(4));
    renderOffPeakDuration();
  }
  function renderDischargeDuration(){
    const output=document.getElementById('acDischargeEstimate');
    if(!output)return;
    const duration=Number(document.getElementById('acDischargeDuration').value);
    output.innerHTML='<i class="fa-solid fa-clock"></i> '+(Number.isFinite(duration)&&duration>0?'Duration <b>'+Number(duration.toFixed(2))+' ชั่วโมง</b>':'Duration —');
  }
  function updateDischargeEndFromDuration(){
    const estimate=estimatedEndTime(document.getElementById('acDischargeStart').value,document.getElementById('acDischargeDuration').value);
    if(estimate)document.getElementById('acDischargeEnd').value=estimate.time;
    renderDischargeDuration();
  }
  function updateDischargeDurationFromEnd(){
    const duration=durationBetweenTimes(document.getElementById('acDischargeStart').value,document.getElementById('acDischargeEnd').value);
    if(duration!==null)document.getElementById('acDischargeDuration').value=Number(duration.toFixed(4));
    renderDischargeDuration();
  }
  function updatePeakAverage(){
    const minimum=Number(document.getElementById('acMaxL').value);
    const maximum=Number(document.getElementById('acMaxH').value);
    const output=document.getElementById('acPeakAverageValue');
    if(output)output.textContent=Number.isFinite(minimum)&&Number.isFinite(maximum)&&maximum>=minimum?Number(((minimum+maximum)/2).toFixed(2)):'—';
  }
  ['input','change'].forEach(eventName=>{
    document.getElementById('acOffStart').addEventListener(eventName,updateOffPeakEndFromDuration);
    document.getElementById('acOffEnd').addEventListener(eventName,updateOffPeakDurationFromEnd);
    document.getElementById('acDischargeStart').addEventListener(eventName,updateDischargeEndFromDuration);
    document.getElementById('acDischargeEnd').addEventListener(eventName,updateDischargeDurationFromEnd);
    document.getElementById('acMaxL').addEventListener(eventName,updatePeakAverage);
    document.getElementById('acMaxH').addEventListener(eventName,updatePeakAverage);
  });
  document.querySelectorAll('.ac-time24').forEach(input=>{
    input.addEventListener('input',()=>{
      const digits=input.value.replace(/\D/g,'').slice(0,4);
      input.value=digits.length>2?digits.slice(0,2)+':'+digits.slice(2):digits;
    });
  });
  if(window.jQuery&&jQuery.fn.datetimepicker){
    jQuery('.ac-time24').datetimepicker({
      datepicker:false,
      timepicker:true,
      format:'H:i',
      formatTime:'H:i',
      step:5,
      hours12:false,
      closeOnTimeSelect:true,
      scrollInput:false
    });
  }
  document.querySelectorAll('.ac-solar-time-row .ac-time24').forEach(input=>{
    if(window.jQuery&&jQuery.fn.datetimepicker)jQuery(input).datetimepicker('destroy');
    const editor=document.createElement('div');editor.className='ac-clock-editor';
    input.parentNode.insertBefore(editor,input);editor.appendChild(input);
    const trigger=document.createElement('button');trigger.type='button';trigger.className='ac-clock-button';trigger.title='เลือกเวลา';trigger.innerHTML='<i class="fa-solid fa-clock"></i>';editor.appendChild(trigger);
    const picker=document.createElement('div');picker.className='ac-clock-picker';picker.hidden=true;
    picker.innerHTML='<div class="ac-clock-title"><b><i class="fa-solid fa-clock"></i><span>เลือกเวลาแบบ 24 ชั่วโมง</span></b><button type="button" class="ac-clock-now"><i class="fa-solid fa-clock-rotate-left"></i> ตอนนี้</button><button type="button" class="ac-clock-close" aria-label="ปิด">&times;</button></div><div class="ac-clock-labels"><span><i class="fa-solid fa-clock"></i> ชั่วโมง</span><span><i class="fa-solid fa-hourglass-half"></i> นาที</span></div><div class="ac-clock-wheels"></div><button class="ac-clock-done" type="button"><i class="fa-solid fa-circle-check"></i><span>ใช้เวลานี้</span></button>';
    editor.appendChild(picker);
    const wheels=picker.querySelector('.ac-clock-wheels');
    const make=(name,max)=>{const select=document.createElement('select');select.size=5;select.setAttribute('aria-label',name);for(let n=0;n<=max;n++){const value=String(n).padStart(2,'0');select.add(new Option(value,value));}return select;};
    const hour=make('ชั่วโมง',23),minute=make('นาที',59);wheels.append(hour,minute);
    const sync=()=>{const match=String(input.value||'00:00').match(/^(\d{2}):(\d{2})/);hour.value=match?match[1]:'00';minute.value=match?match[2]:'00';};
    const apply=()=>{input.value=hour.value+':'+minute.value;input.dispatchEvent(new Event('change',{bubbles:true}));};
    const close=()=>picker.hidden=true;
    trigger.addEventListener('click',()=>{document.querySelectorAll('.ac-clock-picker').forEach(other=>{if(other!==picker)other.hidden=true;});sync();picker.hidden=!picker.hidden;if(!picker.hidden)hour.focus();});
    [hour,minute].forEach(select=>select.addEventListener('change',apply));
    picker.querySelector('.ac-clock-done').addEventListener('click',()=>{apply();close();input.focus();});
    picker.querySelector('.ac-clock-close').addEventListener('click',close);
    picker.querySelector('.ac-clock-now').addEventListener('click',()=>{const now=new Date();hour.value=String(now.getHours()).padStart(2,'0');minute.value=String(now.getMinutes()).padStart(2,'0');apply();});
    document.addEventListener('pointerdown',event=>{if(!editor.contains(event.target))close();});sync();
  });
  document.getElementById('acSave').addEventListener('click',e=>{
    const invalid=[...document.querySelectorAll('.ac-time24')].find(input=>!input.checkValidity());
    if(invalid){
      e.stopImmediatePropagation();
      invalid.focus();
      invalid.reportValidity();
    }
  },true);
  rows.addEventListener('change',e=>{const tr=e.target.closest('tr');if(tr)syncRow(tr);document.getElementById('acStatus').textContent='มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก';});
  document.getElementById('acSearch').addEventListener('input',renderRows);document.getElementById('acMainMeter').addEventListener('change',renderRows);
  document.getElementById('acStrategyEnabled').addEventListener('change',syncStrategyFields);
  acEnabled.addEventListener('change',()=>{
    if(acMasterSaving||!canEdit)return;
    const requested=acEnabled.checked,previous=!requested;
    acMasterSaving=true;acEnabled.disabled=true;document.getElementById('acMasterText').textContent='กำลังบันทึกสถานะ...';
    fetch('./automation_toggle_api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:'enabled='+(requested?'1':'0')}).then(async response=>{const data=await response.json();if(!response.ok||!data.success)throw Error(data.error||('HTTP '+response.status));config.enabled=!!data.enabled;updateAcMaster(data.enabled);document.getElementById('acStatus').textContent=(data.enabled?'เปิด':'ปิด')+' Automation แล้ว · '+new Date().toLocaleString('th-TH');if(window.Swal)Swal.fire({icon:data.enabled?'success':'info',title:data.enabled?'เปิด Automation แล้ว':'ปิด Automation แล้ว',text:data.enabled?'ระบบเริ่มควบคุมอัตโนมัติ':'ระบบหยุดส่งคำสั่งควบคุมอัตโนมัติแล้ว',timer:1800,showConfirmButton:false});}).catch(error=>{updateAcMaster(previous);if(window.Swal)Swal.fire('บันทึกไม่สำเร็จ',error.message,'error');else alert('บันทึกไม่สำเร็จ: '+error.message);}).finally(()=>{acMasterSaving=false;acEnabled.disabled=false;});
  });
  document.getElementById('acTouCalendar').addEventListener('click',event=>{
    const monthButton=event.target.closest('[data-ac-tou-month]');
    if(monthButton){acTouMonth.setMonth(acTouMonth.getMonth()+Number(monthButton.dataset.acTouMonth));renderAcTouCalendar();return;}
    const dayButton=event.target.closest('[data-ac-tou-day]');
    if(!dayButton)return;
    const store=document.getElementById('acHolidayDates');
    const dates=new Set(store.value.split(',').filter(Boolean));
    dates.has(dayButton.dataset.acTouDay)?dates.delete(dayButton.dataset.acTouDay):dates.add(dayButton.dataset.acTouDay);
    store.value=[...dates].sort().join(',');
    renderAcTouCalendar();
  });
  document.getElementById('acSave').addEventListener('click',()=>{const payload=collect();if(payload.enabled&&!confirm('ยืนยันเปิด Automation และอนุญาตให้อุปกรณ์ที่เลือกถูกควบคุม?'))return;const btn=document.getElementById('acSave');btn.disabled=true;fetch('./mx_fn_api.php?action=automation_config',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'dataE='+encodeURIComponent(JSON.stringify(payload))}).then(async r=>{const text=await r.text();if(!r.ok)throw Error(text);return JSON.parse(text)}).then(saved=>{config=saved;document.getElementById('acStatus').textContent='บันทึกแล้ว '+new Date().toLocaleString('th-TH');loadForm();if(window.Swal)Swal.fire({icon:'success',title:'บันทึก Automation Config แล้ว',timer:1400,showConfirmButton:false});}).catch(e=>{document.getElementById('acStatus').textContent='บันทึกไม่สำเร็จ: '+e.message;if(window.Swal)Swal.fire('บันทึกไม่สำเร็จ',e.message,'error');}).finally(()=>btn.disabled=false);});
  loadForm();
  if(!canEdit){
    document.querySelectorAll('.ac-wrap input,.ac-wrap select,.ac-wrap button').forEach(element=>element.disabled=true);
    document.getElementById('acStatus').textContent='อ่านได้อย่างเดียว · ต้องใช้สิทธิ์ Admin หรือ Superadmin เพื่อแก้ไข';
  }
}());
</script>
<style>
.ac-master{transition:border-color .2s,background .2s,box-shadow .2s}.ac-master.is-on{border-color:#9edbbd!important;background:#f1fbf6;box-shadow:0 5px 15px rgba(37,165,106,.1)}.ac-master.is-off{border-color:#efb5b5!important;background:#fff4f4;box-shadow:0 5px 15px rgba(197,60,60,.08)}.ac-master.is-on span>b{color:#168958}.ac-master.is-off span>b{color:#c53c3c}.ac-master span>b>strong{display:inline-block;margin-left:5px;padding:2px 7px;border-radius:10px;background:#eef1f4;color:#6f7d8c;font-size:.58rem;vertical-align:1px}.ac-master.is-on span>b>strong{background:#daf3e6;color:#168958}.ac-master.is-off span>b>strong{background:#fbdede;color:#c53c3c}.ac-master.is-off small{color:#b35656}
.ac-wrap{max-width:1450px;margin:0 auto;padding:4px 2px 36px;color:#26394e}.ac-head{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:14px}.ac-head h3{margin:0;font-weight:750}.ac-head h3 i{color:#1769d2}.ac-head p{margin:3px 0;color:#718096;font-size:.82rem}.ac-master{display:flex;align-items:center;gap:9px;padding:10px 13px;border:1px solid #dce5ef;border-radius:12px;background:#fff}.ac-master input{width:19px;height:19px}.ac-master span,.ac-master small{display:block}.ac-master small{color:#8794a3;font-size:.65rem}.ac-card{background:#fff;border:1px solid #e1e8f0;border-radius:14px;padding:16px;margin-bottom:12px;box-shadow:0 7px 22px rgba(31,55,89,.05)}.ac-card h5{margin:0 0 12px}.ac-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:11px}.ac-grid label>span{display:block;font-size:.72rem;font-weight:700;color:#53677e;margin-bottom:5px}.ac-grid select,.ac-grid input,.ac-section-head input,.ac-table input,.ac-table select{width:100%;min-height:38px;border:1px solid #d4deea;border-radius:8px;padding:6px 8px;background:#fff}.ac-unit{display:flex}.ac-unit input{border-radius:8px 0 0 8px}.ac-unit b{display:grid;place-items:center;padding:0 9px;border:1px solid #d4deea;border-left:0;border-radius:0 8px 8px 0;background:#f5f8fb;color:#718096;font-size:.7rem}.ac-section-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}.ac-section-head h5{margin:0}.ac-section-head small{color:#718096}.ac-section-head>input{max-width:250px}.ac-section-head label,.ac-inline{font-size:.75rem}.ac-section-head label input,.ac-inline input{width:auto;min-height:auto}.ac-role-legend{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:9px}.ac-role-legend span{font-size:.67rem;padding:3px 8px;border-radius:10px;background:#eef2f6}.ac-role-legend .solar{color:#c47a00;background:#fff4d8}.ac-role-legend .battery{color:#168958;background:#e6f7ef}.ac-role-legend .ev{color:#1769d2;background:#e7f1ff}.ac-table-wrap{max-height:580px;overflow:auto;border:1px solid #e4eaf1;border-radius:10px}.ac-table{width:100%;border-collapse:separate;border-spacing:0;font-size:.75rem}.ac-table th{position:sticky;top:0;background:#eef4fb;padding:9px;z-index:2}.ac-table td{padding:8px;border-bottom:1px solid #edf1f5}.ac-table td:first-child b,.ac-table td:first-child small,.ac-table td:nth-child(2) small{display:block}.ac-table small{color:#8290a0}.ac-table tr.controllable{background:#f7fcf9}.ac-switch input{display:none}.ac-switch span{display:block;width:38px;height:21px;border-radius:14px;background:#cbd5e1;position:relative}.ac-switch span:after{content:'';position:absolute;width:15px;height:15px;left:3px;top:3px;border-radius:50%;background:#fff;transition:.2s}.ac-switch input:checked+span{background:#25a56a}.ac-switch input:checked+span:after{left:20px}.ac-switch input:disabled+span{opacity:.4}.ac-foot{position:sticky;bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 14px;background:rgba(255,255,255,.96);border:1px solid #dce5ef;border-radius:12px;box-shadow:0 12px 30px rgba(31,55,89,.15)}.ac-foot span{font-size:.75rem;color:#718096}.ac-foot button{border:0;border-radius:8px;background:#1769d2;color:#fff;padding:9px 14px;font-weight:700}@media(max-width:900px){.ac-grid{grid-template-columns:1fr 1fr}.ac-table{min-width:850px}}@media(max-width:560px){.ac-head{align-items:flex-start;flex-direction:column}.ac-grid{grid-template-columns:1fr}.ac-section-head{align-items:flex-start;flex-direction:column}.ac-foot{align-items:stretch;flex-direction:column}}
.ac-schedule-columns{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.ac-schedule-card .ac-grid{grid-template-columns:1fr 1fr 1.2fr}
.ac-solar-schedule{border-top:3px solid #f2ad22}
.ac-battery-schedule{border-top:3px solid #25a56a}
.ac-solar-schedule h5 i{color:#e59a00}
.ac-battery-schedule h5 i{color:#25a56a}
.ac-table tr.ac-selected td{background:#fff8df!important;border-top:1px solid #f3c65d;border-bottom:1px solid #f3c65d}.ac-table tr.ac-selected td:first-child{box-shadow:inset 4px 0 #f2ad22}
.ac-card.ac-section-selected{border-color:#f2ad22;box-shadow:0 0 0 3px rgba(242,173,34,.14),0 10px 28px rgba(31,55,89,.08)}.ac-field-selected{background:#fff8df!important;border-color:#f2ad22!important}
.ac-system-limit{grid-column:span 2;padding:10px;border:1px solid #d7e3f0;border-radius:10px;background:#f8fbff}.ac-system-limit>small{display:block;margin-top:5px;color:#7b8a9b;font-size:.68rem}.ac-limit-row{display:grid;grid-template-columns:auto 1fr;align-items:center;gap:10px}.ac-limit-row>.ac-inline{white-space:nowrap}.ac-limit-row>.ac-unit{min-width:160px}
@media(max-width:900px){.ac-schedule-columns{grid-template-columns:1fr}.ac-schedule-card .ac-grid{grid-template-columns:1fr 1fr 1.2fr}}
@media(max-width:560px){.ac-schedule-card .ac-grid{grid-template-columns:1fr}.ac-system-limit{grid-column:span 1}.ac-limit-row{grid-template-columns:1fr}}
.ac-battery-schedule{display:none}
.ac-schedule-columns{grid-template-columns:1fr}
.ac-battery-strategy{border-top:3px solid #25a56a}.ac-battery-strategy h5 i{color:#25a56a}.ac-switch-row{display:flex;align-items:center;gap:9px}.ac-strategy-holidays{grid-column:1/-1}.ac-grid.is-hidden{display:none}
.ac-grid textarea{width:100%;border:1px solid #d4deea;border-radius:8px;padding:8px;background:#fff;resize:vertical}.ac-grid label>small{display:block;margin-top:5px;color:#7b8a9b;font-size:.66rem}
.ac-grid label>.ac-time-estimate{padding:6px 8px;border-radius:7px;background:#f1f7fd;color:#41698f}.ac-time-estimate i{margin-right:4px;color:#1769d2}.ac-time-estimate b{color:#145ca8}
.ac-peak-average{grid-column:1/-1;display:flex;align-items:center;gap:8px;padding:9px 11px;border-radius:9px;background:#f1f7fd;color:#41698f;font-size:.68rem}.ac-peak-average i{color:#1769d2}.ac-peak-average b{color:#145ca8;font-size:.75rem}
.ac-title-icon{display:flex;align-items:center;gap:10px;min-width:0}.ac-title-icon>i{display:grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border-radius:9px}.ac-title-icon.solar>i{background:#fff2cf;color:#e49a00}.ac-title-icon.battery>i{background:#e9f8f0;color:#1b9a62}.ac-title-icon.devices>i{background:#eaf3ff;color:#1769d2}.ac-title-icon h5,.ac-title-icon small{display:block}.ac-everyday{flex:none;padding:5px 9px;border-radius:20px;background:#e8f7ef;color:#168958;font-size:.65rem;font-weight:800}.ac-feature-toggle{display:flex;align-items:center;gap:11px;padding:11px 12px;border:1px solid #d6e5dd;border-radius:11px;background:#fff;cursor:pointer}.ac-feature-toggle>input{appearance:none;-webkit-appearance:none;position:relative;flex:0 0 46px;width:46px;height:25px;border:0;border-radius:20px;background:#b8c2cc;box-shadow:inset 0 1px 3px rgba(24,43,61,.22);cursor:pointer;transition:.22s}.ac-feature-toggle>input::after{content:'';position:absolute;left:3px;top:3px;width:19px;height:19px;border-radius:50%;background:#fff;box-shadow:0 2px 5px rgba(23,40,58,.3);transition:.22s}.ac-feature-toggle>input:checked{background:#25a56a}.ac-feature-toggle>input:checked::after{transform:translateX(21px)}.ac-feature-toggle>span{min-width:0}.ac-feature-toggle b,.ac-feature-toggle small{display:block}.ac-feature-toggle b{color:#304b3e;font-size:.76rem}.ac-feature-toggle small{margin-top:2px;color:#788b81;font-size:.65rem}.ac-solar-time-row{display:grid;grid-template-columns:1fr auto 1fr;align-items:end;gap:10px;margin-top:12px}.ac-solar-time-row label>span{display:block;margin-bottom:5px;color:#52677d;font-size:.72rem;font-weight:700}.ac-solar-time-row>i{display:grid;place-items:center;width:29px;height:40px;color:#7a91a8}.ac-time-note{display:flex;align-items:center;gap:6px;margin-top:9px;color:#667d93;font-size:.66rem}.ac-group-title{grid-column:1/-1;display:flex;align-items:center;gap:7px;margin-top:5px;padding:8px 10px;border-radius:8px;background:#edf4fb;color:#315b87;font-size:.7rem;font-weight:800}.ac-group-title:first-child{margin-top:0}.ac-soc-field{padding:11px;border:1px solid #dbe6f0;border-radius:10px;background:#f8fbfe}.ac-soc-field input{color:#176c48;font-weight:750}
.ac-wrap{max-width:1120px}.ac-head{padding:16px 18px;border:1px solid #e1e8f0;border-radius:16px;background:#fff;box-shadow:0 8px 22px rgba(31,55,89,.05)}.ac-head h3{font-size:1.15rem}.ac-card{padding:18px;border-radius:16px}.ac-card>h5,.ac-section-head h5{font-size:.92rem;color:#304b61}.ac-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.ac-grid label>span,.ac-field-title{display:block;margin-bottom:5px;color:#52677d;font-size:.72rem;font-weight:700}.ac-grid select,.ac-grid input{min-height:40px}.ac-general{border-top:3px solid #1769d2}.ac-system-limit{border-left:3px solid #e34b4b;background:#fffafa}.ac-section-head{padding-bottom:11px;border-bottom:1px solid #e7edf4}
.ac-master>input,.ac-section-head>label>input,.ac-limit-row>.ac-inline>input{appearance:none;-webkit-appearance:none;position:relative;width:46px!important;height:25px!important;min-height:25px!important;border:0;border-radius:20px;background:#b8c2cc;box-shadow:inset 0 1px 3px rgba(24,43,61,.22);cursor:pointer;transition:.22s}.ac-master>input::after,.ac-section-head>label>input::after,.ac-limit-row>.ac-inline>input::after{content:'';position:absolute;left:3px;top:3px;width:19px;height:19px;border-radius:50%;background:#fff;box-shadow:0 2px 5px rgba(23,40,58,.3);transition:.22s}.ac-master>input:checked,.ac-section-head>label>input:checked,.ac-limit-row>.ac-inline>input:checked{background:#25a56a;box-shadow:0 0 0 3px rgba(37,165,106,.12)}.ac-master>input:checked::after,.ac-section-head>label>input:checked::after,.ac-limit-row>.ac-inline>input:checked::after{transform:translateX(21px)}.ac-master{min-width:250px;border-color:#d6e5dd}.ac-section-head>label,.ac-limit-row>.ac-inline{display:flex;align-items:center;gap:8px;color:#52677d;font-weight:700}
.ac-switch span{width:46px;height:25px}.ac-switch span:after{width:19px;height:19px}.ac-switch input:checked+span:after{left:24px}.ac-switch-row{padding:8px 10px;border:1px solid #d6e5dd;border-radius:10px;background:#f8fcfa}.ac-battery-strategy .ac-section-head{align-items:center}
.ac-tou-calendar{padding:12px;border:1px solid #dce5ef;border-radius:11px;background:#f8fafc}.ac-tou-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}.ac-tou-head button{display:grid;place-items:center;width:31px;height:31px;border:1px solid #d5e1ed;border-radius:8px;background:#fff;color:#3c668b;font:700 1rem/1 Arial;cursor:pointer}.ac-tou-head strong{color:#294e70;font-size:.86rem}.ac-tou-week,.ac-tou-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:5px}.ac-tou-week span{text-align:center;color:#8494a5;font-size:.64rem;font-weight:700;padding:3px}.ac-tou-grid button{position:relative;min-height:48px;border:1px solid #e1e8f0;border-radius:8px;background:#fff;color:#405a72;font-size:.72rem;cursor:pointer}.ac-tou-grid button:hover{border-color:#76a9df;background:#f3f8fe}.ac-tou-grid button.outside{opacity:.32}.ac-tou-grid button.today{box-shadow:inset 0 0 0 1px #1769d2}.ac-tou-grid button.saved{border-color:#2a9c69;background:#eaf8f1;color:#176c48;font-weight:800}.ac-tou-grid button.pending{border-color:#e2a52b;background:#fff8e7;color:#8c620a}.ac-tou-grid button b{position:absolute;right:5px;bottom:4px}.ac-tou-grid button.saved b{color:#20a467}.ac-tou-grid button.pending b{color:#df9a13}.ac-tou-legend{display:flex;gap:13px;margin-top:10px;color:#687b8f;font-size:.67rem}.ac-tou-legend span:first-child b{color:#20a467}.ac-tou-legend span:last-child b{color:#df9a13}.ac-tou-calendar>small{display:block;margin-top:7px;color:#687b8f;font-size:.67rem}
@media(max-width:700px){.ac-grid{grid-template-columns:1fr}.ac-strategy-holidays{grid-column:auto}.ac-master{width:100%;min-width:0}.ac-solar-time-row{grid-template-columns:1fr}.ac-solar-time-row>i{width:100%;height:18px;transform:rotate(90deg)}.ac-everyday{align-self:flex-start}}
.ac-clock-editor{position:relative}.ac-clock-editor>.ac-time24{width:100%;min-height:40px;padding:6px 44px 6px 10px;border:1px solid #c8d9e8;border-radius:8px;background:linear-gradient(135deg,#fff,#f4f9fd);color:#304b61;font-family:Consolas,"SFMono-Regular",monospace;font-size:.86rem;font-weight:800;letter-spacing:1.5px}.ac-clock-button{position:absolute;top:4px;right:4px;display:grid;place-items:center;width:33px;height:32px;border:1px solid #bfd6ea;border-radius:7px;background:#edf4fb;color:#1769d2}.ac-clock-picker[hidden]{display:none}.ac-clock-picker{position:absolute;right:0;top:46px;z-index:40;width:min(330px,calc(100vw - 48px));padding:0;overflow:hidden;border:1px solid #c8d9e8;border-radius:14px;background:#fff;box-shadow:0 18px 42px rgba(25,67,103,.2);color:#304b61}.ac-clock-title{display:flex;align-items:center;gap:7px;padding:11px 12px;border-bottom:1px solid #d8e5f0;background:#edf4fb;color:#245b87}.ac-clock-title b{display:flex;align-items:center;gap:7px;min-width:0;margin-right:auto;font-size:.75rem;white-space:nowrap}.ac-clock-title b>i{display:grid;place-items:center;width:25px;height:25px;border-radius:7px;background:#dceafa;color:#1769d2}.ac-clock-title button{border:1px solid #bfd6ea;border-radius:7px;background:#fff;color:#1769d2}.ac-clock-now{height:27px;padding:0 8px;font-size:.65rem;font-weight:700;white-space:nowrap}.ac-clock-close{width:27px;height:27px;color:#667f94!important;font-size:1.1rem}.ac-clock-labels,.ac-clock-wheels{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.ac-clock-labels{padding:10px 12px 4px}.ac-clock-labels span{display:flex;align-items:center;justify-content:center;gap:5px;color:#667f94;font-size:.62rem;font-weight:700}.ac-clock-labels span i{color:#1769d2}.ac-clock-wheels{padding:0 12px}.ac-clock-wheels select{height:108px;padding:0;border:1px solid #c8d9e8;border-radius:9px;background:#fbfdff;color:#304b61;font-family:Consolas,"SFMono-Regular",monospace;font-size:1rem;font-weight:800;text-align:center;scrollbar-width:none;-ms-overflow-style:none}.ac-clock-wheels select::-webkit-scrollbar,.ac-clock-picker::-webkit-scrollbar{display:none;width:0;height:0}.ac-clock-wheels select:focus{border-color:#1769d2;outline:0;box-shadow:0 0 0 3px rgba(23,105,210,.1)}.ac-clock-wheels option{padding:5px;background:#fbfdff;color:#405a72}.ac-clock-wheels option:checked{background:#1769d2;color:#fff}.ac-clock-done{display:flex;align-items:center;justify-content:center;gap:7px;width:calc(100% - 24px);margin:11px 12px 12px;padding:10px;border:0;border-radius:9px;background:#1769d2;color:#fff;font-size:.75rem;font-weight:800;box-shadow:0 6px 15px rgba(23,105,210,.18)}
@media(max-width:700px){.ac-clock-picker{right:0;top:auto;bottom:46px;width:min(310px,calc(100vw - 40px))}.ac-clock-wheels select{height:96px}.ac-clock-title b span{display:none}}
.ac-simulation-toggle{margin-bottom:12px;border-color:#b9d6f5;background:#f5faff}.ac-simulation-toggle b{color:#1769d2}.ac-simulation-toggle>input:checked{background:#1769d2}
</style>
