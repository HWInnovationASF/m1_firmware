<?php
// Live site energy-flow overview. Included by index.php?page=energy and uses the
// same configuration variables as the other monitor pages.
$efAutomationPath = './config/automation_control.json';
$efAutomationConfig = file_exists($efAutomationPath) ? json_decode(file_get_contents($efAutomationPath), true) : [];
if (!is_array($efAutomationConfig)) $efAutomationConfig = [];
$efTouConfig = json_decode(@file_get_contents('/home/mdbcare/python/tou_schedule.json'), true);
if (!is_array($efTouConfig)) $efTouConfig = [];
$efTouHolidayDates = [];
foreach ([(string)date('Y'), (string)((int)date('Y') + 1)] as $efTouYear) {
    $efTouYearHolidays = $efTouConfig[$efTouYear]['holidays'] ?? [];
    if (is_array($efTouYearHolidays)) {
        foreach ($efTouYearHolidays as $efTouHoliday) {
            if (is_string($efTouHoliday) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $efTouHoliday)) {
                $efTouHolidayDates[$efTouHoliday] = true;
            }
        }
    }
}
$efTouHolidayDates = array_keys($efTouHolidayDates);
sort($efTouHolidayDates);
$efParameterPath = './config/parameter_list.json';
$efParameterList = file_exists($efParameterPath) ? json_decode(file_get_contents($efParameterPath), true) : [];
if (!is_array($efParameterList)) $efParameterList = [];
$efParameterMap = [];
foreach ($efParameterList as $efParameterKey => $efParameterRow) {
    if (is_array($efParameterRow) && isset($efParameterRow['Parameter'])) {
        $efInfo = isset($efParameterRow['InfoData']) && is_array($efParameterRow['InfoData']) ? $efParameterRow['InfoData'] : [];
        $efParameterMap[(string)$efParameterRow['Parameter']] = [
            'display_name' => $efInfo['NameShort'] ?? $efInfo['DisplayName'] ?? $efInfo['Name'] ?? $efParameterRow['Parameter'],
            'full_name' => $efInfo['Name'] ?? $efParameterRow['Parameter'],
            'unit' => $efInfo['Unit'] ?? ''
        ];
    } elseif (is_string($efParameterKey) && is_array($efParameterRow)) {
        $efParameterMap[$efParameterKey] = $efParameterRow;
    }
}
$efCanEditAutomation = in_array(strtolower($_SESSION['user_type'] ?? ''), ['admin', 'superadmin'], true);
?>
<div class="ef-wrap">
  <div class="ef-head">
    <div>
      <h3 class="ef-title">Energy Flow</h3>
      <div class="ef-sub">ภาพรวมการไหลของพลังงานแบบเรียลไทม์</div>
    </div>
    <div class="ef-head-actions">
      <div class="ef-updated" id="efUpdated"><span class="ef-live-dot"></span> กำลังโหลด…</div>
      <a class="ef-automation-btn is-loading" id="efAutomationStatus" href="index.php?page=automation-config" title="เปิดหน้า Automation Config"><i class="fa-solid fa-gears"></i><span>Automation Config · กำลังตรวจสอบ…</span></a>
      <button type="button" class="ef-settings-btn" id="efSettingsBtn"><i class="fa-solid fa-sliders"></i> ตั้งค่า Diagram</button>
    </div>
  </div>

  <div class="ef-alert" id="efAlert" style="display:none;"></div>

  <div class="ef-settings" id="efSettings" style="display:none;">
    <div class="ef-settings-head">
      <div><strong>Energy Diagram Settings</strong><small>กำหนดแหล่งข้อมูลและวิธีคำนวณการไหลของพลังงาน</small></div>
      <button type="button" id="efSettingsClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="ef-settings-grid">
      <label>
        <span>Main Meter</span>
        <select id="efMainMeter"></select>
        <small>อุปกรณ์ที่ใช้เป็นค่าหลักของระบบ</small>
      </label>
      <label>
        <span>รูปแบบพลังงาน</span>
        <select id="efEnergyMode">
          <option value="grid">Main Meter = Grid Import / Export</option>
          <option value="load">Main Meter = Facility Load</option>
        </select>
        <small id="efModeHint">คำนวณ Load จาก Solar + Grid + Battery</small>
      </label>
      <label>
        <span>ทิศทางค่าของ Main Meter</span>
        <select id="efMeterSign">
          <option value="import">ค่าบวก = Import / Consumption</option>
          <option value="export">ค่าบวก = Export / Generation</option>
        </select>
      </label>
      <label>
        <span>ทิศทางค่าของ Battery</span>
        <select id="efBatterySign">
          <option value="discharge">ค่าบวก = Discharge</option>
          <option value="charge">ค่าบวก = Charge</option>
        </select>
      </label>
    </div>
    <div class="ef-settings-foot">
      <span>การตั้งค่าจะถูกบันทึกในเบราว์เซอร์เครื่องนี้</span>
      <div>
        <button type="button" class="ef-btn-reset" id="efSettingsReset">คืนค่าเริ่มต้น</button>
        <button type="button" class="ef-btn-save" id="efSettingsSave"><i class="fa-solid fa-check"></i> บันทึก</button>
      </div>
    </div>
  </div>

  <div class="ef-board">
    <div class="ef-node ef-solar" id="efSolar">
      <button type="button" class="ef-node-config" data-ac-section="solar" title="แก้ไข Solar Automation"><i class="fa-solid fa-pen"></i></button>
      <div class="ef-icon"><i class="fa-solid fa-solar-panel"></i></div>
      <div class="ef-node-copy">
        <div class="ef-label">Solar PV</div>
        <div class="ef-value"><span id="efSolarValue">—</span><small>kW</small></div>
        <div class="ef-meta" id="efSolarMeta">— / — inverters online</div>
        <div class="ef-node-insight"><small>Solar contribution</small><strong id="efSolarShare">—</strong></div>
      </div>
    </div>

    <div class="ef-line ef-line-solar" id="efLineSolar" data-flow="PV → Bus"><span></span></div>

    <div class="ef-node ef-grid" id="efGrid">
      <div class="ef-icon">
        <span class="ef-grid-symbol">
          <i class="mdi mdi-transmission-tower"></i>
          <i class="fa-solid fa-bolt"></i>
        </span>
      </div>
      <div class="ef-node-copy">
        <div class="ef-label" id="efMeterLabel">Main Meter / Grid</div>
        <div class="ef-value"><span id="efGridValue">—</span><small>kW</small></div>
        <div class="ef-grid-reactive" id="efGridReactive" title="Q_t ที่อ่านได้ / Peak Maximum (ค่าที่ตั้งไว้ × 0.6197)"><span>Q_t</span><strong><b id="efGridQt">—</b> / <b id="efGridQPeak">—</b> kVar</strong></div>
        <div class="ef-grid-reactive" id="efGridPowerFactor" title=""><span>Power Factor</span><strong><b id="efGridPf">—</b></strong></div>
        <div class="ef-meta" id="efGridMeta">รอข้อมูล</div>
        <div class="ef-node-insight"><small id="efGridInsightLabel">Grid dependency</small><strong id="efGridInsight">—</strong></div>
        <button type="button" class="ef-node-config" data-ac-section="general" title="แก้ไข Main Meter Automation"><i class="fa-solid fa-pen"></i></button>
      </div>
    </div>

    <div class="ef-line ef-line-grid" id="efLineGrid" data-flow="Import →"><span></span></div>

    <div class="ef-hub">
      <div class="ef-hub-ring"></div>
      <div class="ef-hub-orbit" aria-hidden="true"><span></span><span></span><span></span></div>
      <i class="fa-solid fa-bolt"></i>
      <strong>ENERGY BUS</strong>
      <small id="efBalance">กำลังคำนวณ</small>
    </div>

    <div class="ef-line ef-line-load" id="efLineLoad" data-flow="Bus → Load"><span></span></div>

    <div class="ef-node ef-battery" id="efBattery">
      <div class="ef-icon"><i class="fa-solid fa-battery-half"></i></div>
      <div class="ef-node-copy">
        <div class="ef-label">Battery</div>
        <div class="ef-value"><span id="efBatteryValue">—</span><small>kW</small></div>
        <div class="ef-battery-qmax" id="efBatteryQMaxRow" title="">Q_max <strong id="efBatteryQMax">—</strong><small>kvar</small></div>
        <div class="ef-soc"><span id="efSocBar"></span></div>
        <div class="ef-meta" id="efBatteryMeta">SOC —</div>
        <div class="ef-node-insight"><small>Battery power</small><strong id="efBatterySummary">—</strong></div>
        <button type="button" class="ef-node-config" data-ac-section="battery" title="แก้ไข Battery Automation"><i class="fa-solid fa-pen"></i></button>
      </div>
    </div>

    <div class="ef-line ef-line-battery" id="efLineBattery" data-flow="Standby"><span></span></div>

    <div class="ef-node ef-load" id="efLoad">
      <div class="ef-icon"><i class="fa-solid fa-industry"></i></div>
      <div class="ef-node-copy">
        <div class="ef-label" id="efLoadLabel">Total Consumption</div>
        <div class="ef-value"><span id="efLoadValue">—</span><small>kW</small></div>
        <div class="ef-meta" id="efLoadMeta">รวมการใช้พลังงานและการชาร์จ Battery</div>
        <button type="button" class="ef-node-config" data-ac-section="limit" title="แก้ไข Peak Limit"><i class="fa-solid fa-pen"></i></button>
      </div>
    </div>
  </div>

  <div class="ef-mqtt-control">
    <div class="ef-panel-head">
      <div><h5><i class="fa-solid fa-clock-rotate-left"></i> Control Log</h5><small>คำสั่งควบคุมอุปกรณ์รายวันจาก Automation, MQTT และหน้า Energy พร้อมค่าขณะสั่ง</small></div>
      <div class="ef-log-head-tools"><div class="ef-log-date-picker"><input id="efMqttDate" type="date"><button id="efLogCalendarButton" type="button" title="ดูวันที่มีข้อมูล" aria-label="เปิดปฏิทินวันที่มีข้อมูล"><i class="fa-solid fa-calendar-days"></i></button><div id="efLogCalendar" class="ef-log-calendar" hidden><div class="ef-log-calendar-head"><button type="button" data-log-month="-1" aria-label="เดือนก่อนหน้า">&#8249;</button><strong id="efLogCalendarLabel"></strong><button type="button" data-log-month="1" aria-label="เดือนถัดไป">&#8250;</button></div><div class="ef-log-calendar-week"><span>อา.</span><span>จ.</span><span>อ.</span><span>พ.</span><span>พฤ.</span><span>ศ.</span><span>ส.</span></div><div id="efLogCalendarGrid" class="ef-log-calendar-grid"></div><small><i></i> วันที่มีข้อมูล Battery Control Log</small></div></div><span id="efMqttUpdated">รอข้อมูล</span></div>
    </div>
    <div class="ef-mqtt-summary">
      <div><small>คำสั่งล่าสุด</small><strong id="efMqttLatest">—</strong><span id="efMqttLatestMeta">ยังไม่มีคำสั่ง</span></div>
      <div class="success"><small>สำเร็จในวันที่เลือก</small><strong id="efMqttSuccess">0</strong></div>
      <div class="failed"><small>ไม่สำเร็จในวันที่เลือก</small><strong id="efMqttFailed">0</strong></div>
      <div><small>คำสั่งทั้งหมดในวันที่เลือก</small><strong id="efMqttTotal">0</strong></div>
    </div>
    <div class="ef-mqtt-table-wrap">
      <table class="ef-mqtt-table"><thead><tr><th><button class="ef-table-sort active desc" type="button" data-sort-table="control" data-sort-key="datetime">เวลา <i class="fa-solid fa-arrow-down"></i></button></th><th><button class="ef-table-sort" type="button" data-sort-table="control" data-sort-key="source">แหล่งที่มา <i class="fa-solid fa-sort"></i></button></th><th><button class="ef-table-sort" type="button" data-sort-table="control" data-sort-key="device">อุปกรณ์ <i class="fa-solid fa-sort"></i></button></th><th><button class="ef-table-sort" type="button" data-sort-table="control" data-sort-key="parameter">คำสั่ง <i class="fa-solid fa-sort"></i></button></th><th>ค่าก่อนสั่ง / กำลังขณะสั่ง</th><th><button class="ef-table-sort" type="button" data-sort-table="control" data-sort-key="requested">ค่าที่สั่ง <i class="fa-solid fa-sort"></i></button></th><th><button class="ef-table-sort" type="button" data-sort-table="control" data-sort-key="actual">ค่าหลังเขียน <i class="fa-solid fa-sort"></i></button></th><th><button class="ef-table-sort" type="button" data-sort-table="control" data-sort-key="success">ผลลัพธ์ <i class="fa-solid fa-sort"></i></button></th><th><button class="ef-table-sort" type="button" data-sort-table="control" data-sort-key="reason">สาเหตุ <i class="fa-solid fa-sort"></i></button></th></tr></thead><tbody id="efMqttRows"><tr><td colspan="9">ยังไม่มีคำสั่งควบคุมในวันนี้</td></tr></tbody></table>
    </div>
    <div class="ef-log-pagination"><span id="efLogPageInfo">หน้า 1</span><div class="ef-log-pagination-actions"><label class="ef-log-row-limit" title="จำนวนแถวที่แสดงต่อหน้า"><span>แสดง</span><select id="efLogPageSize" aria-label="จำนวนแถวต่อหน้า"><option value="5" selected>5 แถว</option><option value="10">10 แถว</option><option value="25">25 แถว</option><option value="50">50 แถว</option><option value="100">100 แถว</option><option value="200">200 แถว</option><option value="500">500 แถว</option></select></label><div class="ef-log-page-buttons"><button type="button" id="efLogPrev"><i class="fa-solid fa-chevron-left"></i> ก่อนหน้า</button><button type="button" id="efLogNext">ถัดไป <i class="fa-solid fa-chevron-right"></i></button></div></div></div>
  </div>

  <div class="ef-action-report">
    <div class="ef-panel-head">
      <div><h5><i class="fa-solid fa-table-list"></i> Battery Action Log</h5><small>ค่า Battery ขณะมีการเขียนคำสั่ง p_max สำเร็จ · เลือกดูย้อนหลังได้ตามวันที่</small></div>
      <div class="ef-action-report-tools"><div class="ef-log-date-picker"><input id="efActionLogDate" type="date"><button id="efActionCalendarButton" type="button" title="ดูวันที่มีข้อมูล" aria-label="เปิดปฏิทินวันที่มีข้อมูล"><i class="fa-solid fa-calendar-days"></i></button><div id="efActionCalendar" class="ef-log-calendar" hidden><div class="ef-log-calendar-head"><button type="button" data-action-month="-1" aria-label="เดือนก่อนหน้า">&#8249;</button><strong id="efActionCalendarLabel"></strong><button type="button" data-action-month="1" aria-label="เดือนถัดไป">&#8250;</button></div><div class="ef-log-calendar-week"><span>อา.</span><span>จ.</span><span>อ.</span><span>พ.</span><span>พฤ.</span><span>ศ.</span><span>ส.</span></div><div id="efActionCalendarGrid" class="ef-log-calendar-grid"></div><small><i></i> วันที่มีข้อมูล Battery Action Log</small></div></div><span id="efActionLogUpdated">รอข้อมูล</span><button type="button" id="efBatteryReportExport"><i class="fa-solid fa-file-arrow-down"></i> Export Report</button></div>
    </div>
    <div class="ef-action-report-summary">
      <div class="ef-action-summary-group charge">
        <button type="button" class="ef-action-summary-total" data-action-direction="charge" data-action-reason=""><span><i class="fa-solid fa-battery-half"></i> Charge</span><strong id="efActionChargeTotal">0.000 kWh</strong><small>พลังงานชาร์จทั้งหมด</small></button>
        <div class="ef-action-summary-breakdown">
          <button type="button" class="off-peak" data-action-direction="charge" data-action-reason="off peak"><small>Off Peak</small><strong id="efActionChargeOffPeak">0.000 kWh</strong></button>
          <button type="button" class="solar" data-action-direction="charge" data-action-reason="solar"><small>Solar</small><strong id="efActionChargeSolar">0.000 kWh</strong></button>
        </div>
      </div>
      <div class="ef-action-summary-group discharge">
        <button type="button" class="ef-action-summary-total" data-action-direction="discharge" data-action-reason=""><span><i class="fa-solid fa-bolt"></i> Discharge</span><strong id="efActionDischargeTotal">0.000 kWh</strong><small>พลังงานที่จ่ายออกทั้งหมด</small></button>
        <div class="ef-action-summary-breakdown">
          <button type="button" class="on-peak" data-action-direction="discharge" data-action-reason="on peak"><small>On Peak</small><strong id="efActionDischargeOnPeak">0.000 kWh</strong></button>
          <button type="button" class="off-peak" data-action-direction="discharge" data-action-reason="off peak"><small>Off Peak</small><strong id="efActionDischargeOffPeak">0.000 kWh</strong></button>
        </div>
      </div>
    </div>
    <div class="ef-action-report-table-wrap">
      <table class="ef-action-report-table"><thead><tr><th><button class="ef-table-sort active desc" type="button" data-sort-table="action" data-sort-key="datetime">Date / Time <i class="fa-solid fa-arrow-down"></i></button></th><th><button class="ef-table-sort" type="button" data-sort-table="action" data-sort-key="p_max">p_max (kW) <i class="fa-solid fa-sort"></i></button></th><th><button class="ef-table-sort" type="button" data-sort-table="action" data-sort-key="e_p_cha">e_p_cha (kWh) <i class="fa-solid fa-sort"></i></button></th><th><button class="ef-table-sort" type="button" data-sort-table="action" data-sort-key="e_p_dis">e_p_dis (kWh) <i class="fa-solid fa-sort"></i></button></th><th><button class="ef-table-sort" type="button" data-sort-table="action" data-sort-key="reason">Reason <i class="fa-solid fa-sort"></i></button></th></tr></thead><tbody id="efActionLogRows"><tr><td colspan="5">ยังไม่มีข้อมูลในวันที่เลือก</td></tr></tbody></table>
    </div>
    <div class="ef-log-pagination"><span id="efActionLogPageInfo">หน้า 1</span><div class="ef-log-pagination-actions"><label class="ef-log-row-limit" title="จำนวนแถวที่แสดงต่อหน้า"><span>แสดง</span><select id="efActionLogPageSize" aria-label="จำนวนแถวต่อหน้า"><option value="5" selected>5 แถว</option><option value="10">10 แถว</option><option value="25">25 แถว</option><option value="50">50 แถว</option><option value="100">100 แถว</option><option value="200">200 แถว</option><option value="500">500 แถว</option></select></label><div class="ef-log-page-buttons"><button type="button" id="efActionLogPrev"><i class="fa-solid fa-chevron-left"></i> ก่อนหน้า</button><button type="button" id="efActionLogNext">ถัดไป <i class="fa-solid fa-chevron-right"></i></button></div></div></div>
    <small class="ef-action-report-note">Report ที่ Export จะมีข้อมูลครบทั้งหมดของวันที่เลือก</small>
  </div>

  <div class="ef-device-panel">
    <div class="ef-panel-head">
      <div><h5>Solar Inverters</h5><small>กำลังผลิตและสถานะรายเครื่อง</small></div>
      <span id="efInverterTotal">0 devices</span>
    </div>
    <div class="ef-device-grid" id="efInverters"></div>
  </div>

  <div class="ef-energy-history">
    <div class="ef-energy-donut-panel">
      <div class="ef-energy-donut-head">
        <div><strong><i class="fa-solid fa-chart-pie"></i> สรุปพลังงาน</strong><small id="efConsumptionSummaryStatus">กำลังคำนวณข้อมูลพลังงาน…</small></div>
        <label><span>ช่วงเวลา</span><select id="efEnergyDonutPeriod"><option value="today">วันนี้</option><option value="month">เดือนนี้</option><option value="year">ปีนี้</option></select></label>
      </div>
      <div class="ef-energy-donut-body">
        <div class="ef-energy-donut-chart-wrap">
          <div class="ef-energy-donut-chart" id="efEnergyDonutChart"></div>
          <div class="ef-energy-donut-center-total"><small>พลังงานรวม</small><strong id="efEnergyDonutTotal">— kWh</strong></div>
          <div class="ef-energy-donut-selected" id="efEnergyDonutSelected" style="display:none"></div>
        </div>
        <div class="ef-energy-donut-values">
          <div class="consumption"><i></i><span>พลังงานที่ใช้จริง</span><strong id="efDonutConsumption">— kWh</strong></div>
          <div class="solar"><i></i><span>พลังงานที่ Solar ผลิตได้</span><strong id="efDonutSolar">— kWh</strong></div>
          <div class="charge"><i></i><span>พลังงานที่เก็บเข้าแบตเตอรี่</span><strong id="efDonutCharge">— kWh</strong></div>
          <div class="discharge"><i></i><span>พลังงานที่จ่ายออกจากแบตเตอรี่</span><strong id="efDonutDischarge">— kWh</strong></div>
        </div>
      </div>
    </div>
    <div class="ef-history-panel">
    <div class="ef-history-head">
      <div><h5><i class="fa-solid fa-chart-line"></i> <span id="efHistoryTitle">Active Power Summary</span></h5><small id="efHistoryRange">สรุปกำลังไฟฟ้า Grid, Solar และ Battery</small></div>
      <div class="ef-history-controls">
        <label><span>ข้อมูล</span><select id="efHistoryMetric"><option value="power">Active Power</option><option value="energy">Active Energy</option></select></label>
        <label><span>ช่วงเวลา</span><select id="efHistoryPeriod"><option value="day">รายวัน</option><option value="month">รายเดือน</option><option value="year">รายปี</option></select></label>
        <label id="efHistoryIntervalWrap"><span>ความละเอียด</span><select id="efHistoryInterval"><option value="1">1 นาที</option><option value="5">5 นาที</option><option value="10">10 นาที</option><option value="15">15 นาที</option><option value="60" selected>1 ชั่วโมง</option></select></label>
        <div id="efHistoryDateWrap" class="ef-history-control-field"><span>เลือกวันที่</span><div class="ef-history-date-picker"><input type="text" id="efHistoryDate" inputmode="none" readonly aria-label="วันที่ที่เลือก"><button type="button" id="efHistoryDateButton" aria-label="เปิดปฏิทิน"><i class="fa-regular fa-calendar-days"></i></button><div class="ef-history-calendar" id="efHistoryCalendar" hidden><div class="ef-history-calendar-head"><button type="button" data-history-month="-1" aria-label="เดือนก่อนหน้า">&#8249;</button><strong id="efHistoryCalendarLabel"></strong><button type="button" data-history-month="1" aria-label="เดือนถัดไป">&#8250;</button></div><div class="ef-history-calendar-week"><span>อา.</span><span>จ.</span><span>อ.</span><span>พ.</span><span>พฤ.</span><span>ศ.</span><span>ส.</span></div><div class="ef-history-calendar-grid" id="efHistoryCalendarGrid"></div></div></div></div>
        <label id="efHistoryMonthWrap" style="display:none"><span>เลือกเดือน</span><input type="month" id="efHistoryMonth"></label>
        <label id="efHistoryYearWrap" style="display:none"><span>เลือกปี</span><input type="number" id="efHistoryYear" min="2000" step="1"></label>
        <input type="hidden" id="efHistoryType" value="bar">
      </div>
    </div>
    <div class="ef-history-stats">
      <div class="ef-history-stat-action" id="efHistoryMinCard" role="button" tabindex="0" title="แสดงจุดค่าต่ำสุดบนกราฟ"><small id="efHistoryMinLabel">กำลังต่ำสุด (Min)</small><strong id="efHistoryMin">— kW</strong></div>
      <div class="ef-history-stat-action" id="efHistoryMaxCard" role="button" tabindex="0" title="แสดงจุดค่าสูงสุดบนกราฟ"><small id="efHistoryMaxLabel">กำลังสูงสุด (Max)</small><strong id="efHistoryMax">— kW</strong></div>
      <div><small id="efHistoryAvgLabel">กำลังเฉลี่ย (Avg)</small><strong id="efHistoryAvg">— kW</strong></div>
      <div id="efHistorySolarTotalCard" style="display:none"><small>พลังงานที่ผลิตได้ (Solar)</small><strong id="efHistorySolarTotal">— kWh</strong></div>
      <div id="efHistoryBatteryChargeCard" style="display:none"><small>พลังงานที่เก็บเข้าแบตเตอรี่</small><strong id="efHistoryBatteryCharge">— kWh</strong></div>
      <div id="efHistoryBatteryDischargeCard" style="display:none"><small>พลังงานที่จ่ายออกจากแบตเตอรี่</small><strong id="efHistoryBatteryDischarge">— kWh</strong></div>
    </div>
    <div class="ef-history-chart" id="efHistoryChart"></div>
    <div class="ef-history-empty" id="efHistoryEmpty" style="display:none;">ไม่พบข้อมูลพลังงานในช่วงเวลานี้</div>
    </div>
  </div>

  <div class="ef-ac-modal" id="efAcModal" aria-hidden="true">
    <div class="ef-ac-backdrop" data-ac-close></div>
    <div class="ef-ac-dialog" role="dialog" aria-modal="true" aria-labelledby="efAcTitle">
      <div class="ef-ac-head">
        <div><strong id="efAcTitle">Automation Config</strong><small id="efAcSubtitle"></small></div>
        <button type="button" data-ac-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <div class="ef-ac-body" id="efAcBody"></div>
      <div class="ef-ac-foot">
        <span id="efAcStatus"></span>
        <div><button type="button" class="ef-ac-cancel" data-ac-close>Cancel</button><button type="button" class="ef-ac-save" id="efAcSave"><i class="fa-solid fa-floppy-disk"></i> Save</button></div>
      </div>
    </div>
  </div>
</div>

<style>
  .ef-mqtt-control{container-type:inline-size}.ef-log-pagination{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-top:12px;padding-top:11px;border-top:1px solid #e8eef4;color:#687b8f;font-size:.7rem}.ef-log-pagination>#efLogPageInfo{min-width:0;line-height:1.45}.ef-log-pagination-actions,.ef-log-page-buttons{display:flex;align-items:center;gap:7px;flex:none}.ef-log-pagination button{min-height:35px;padding:6px 11px;border:1px solid #d4deea;border-radius:8px;background:#fff;color:#3978b7;font-size:.7rem;font-weight:700;cursor:pointer}.ef-log-pagination button:hover:not(:disabled){border-color:#1769d2;background:#eef5fd}.ef-log-pagination button:disabled{opacity:.4;cursor:not-allowed}
  .ef-mqtt-table-wrap{max-height:none}
  @container(max-width:600px){.ef-log-pagination{align-items:stretch;flex-direction:column;gap:9px}.ef-log-pagination-actions{justify-content:space-between;width:100%}}
  @container(max-width:390px){.ef-log-pagination-actions{align-items:stretch;flex-direction:column}.ef-log-row-limit{align-self:flex-start}.ef-log-page-buttons{justify-content:flex-end;width:100%}}
  <?php if (!$efCanEditAutomation): ?>
  .ef-automation-btn,.ef-node-config,.ef-device-config{display:none!important}
  <?php endif; ?>
  .ef-wrap{--ink:#17243a;--muted:#718096;--line:#d8e2ee;--blue:#74045f;--solar:#f5a623;--green:#25a56a;--violet:#7457d9;--load:#0b9ed9;--battery:#25a56a;display:flex;flex-direction:column;padding:16px 20px 42px;color:var(--ink)}
  .ef-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:18px}
  .ef-title{font-weight:750;margin:0}.ef-sub,.ef-updated{color:var(--muted);font-size:.88rem}.ef-live-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#f5a623;margin-right:5px}.ef-live-dot.ok{background:#24a768;box-shadow:0 0 0 4px rgba(36,167,104,.12)}
  .ef-head-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.ef-settings-btn,.ef-automation-btn{border:1px solid #cdd9e8;background:#fff;color:#26527f;border-radius:9px;padding:7px 11px;font-size:.82rem;font-weight:650;cursor:pointer;text-decoration:none}.ef-settings-btn:hover{border-color:#1769d2;color:#1769d2;background:#f5f9ff}.ef-automation-btn{border-color:#cde7db;background:#effaf5;color:#168958}.ef-automation-btn:hover{border-color:#25a56a;background:#e4f7ee;color:#0e7448;text-decoration:none}
  .ef-automation-btn{display:flex;align-items:center;gap:7px;transition:.2s}.ef-automation-btn.is-active{border-color:#a7ddc1;background:#eaf8f1;color:#168958}.ef-automation-btn.is-active i{animation:efAutomationSpin 3s linear infinite}.ef-automation-btn.is-disabled{border-color:#edc1c1;background:#fff1f1;color:#bd4141}.ef-automation-btn.is-stopped{border-color:#f0d39b;background:#fff7e5;color:#9a6800}.ef-automation-btn.is-loading{border-color:#d6dee8;background:#f7f9fb;color:#67798d;opacity:.75}@keyframes efAutomationSpin{to{transform:rotate(360deg)}}
  .ef-alert{background:#fff3cd;border:1px solid #ffe69c;color:#7a5a00;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:.86rem}
  .ef-settings{background:#fff;border:1px solid #dce5f0;border-radius:14px;padding:17px;margin-bottom:16px;box-shadow:0 10px 28px rgba(31,55,89,.08)}.ef-settings-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:15px}.ef-settings-head strong{display:block}.ef-settings-head small{display:block;color:var(--muted);margin-top:2px}.ef-settings-head button{border:0;background:transparent;color:var(--muted);cursor:pointer;font-size:1rem}.ef-settings-grid{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:13px 16px}.ef-settings-grid label>span{display:block;font-size:.78rem;font-weight:700;color:#3b506a;margin-bottom:5px}.ef-settings-grid select{width:100%;min-height:40px}.ef-settings-grid label>small{display:block;color:var(--muted);font-size:.69rem;margin-top:4px}.ef-settings-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;border-top:1px solid #edf1f6;margin-top:15px;padding-top:13px;color:var(--muted);font-size:.72rem}.ef-settings-foot>div{display:flex;gap:8px}.ef-btn-reset,.ef-btn-save{border-radius:8px;padding:7px 12px;font-size:.78rem;font-weight:650;cursor:pointer}.ef-btn-reset{border:1px solid #d8e0e9;background:#fff;color:#5c6d80}.ef-btn-save{border:1px solid #1769d2;background:#1769d2;color:#fff}
  .ef-board{position:relative;display:grid;grid-template-columns:minmax(180px,1fr) 110px minmax(190px,.9fr) 110px minmax(180px,1fr);grid-template-rows:1fr 1fr;grid-template-areas:"solar sl hub ll load" "grid gl hub bl battery";align-items:center;min-height:410px;padding:28px;background:radial-gradient(circle at center,rgba(23,105,210,.07),transparent 37%),#f7f9fc;border:1px solid #e4eaf1;border-radius:18px;box-shadow:0 10px 28px rgba(31,55,89,.06)}
  .ef-node{display:flex;align-items:center;gap:13px;background:#fff;border:1px solid #e2e9f2;border-radius:15px;padding:17px;box-shadow:0 8px 22px rgba(31,55,89,.07);min-width:0;position:relative;z-index:2}
  .ef-node-copy{min-width:0;padding-right:18px}
  .ef-solar{grid-area:solar}.ef-grid{grid-area:grid}.ef-battery{grid-area:battery}.ef-load{grid-area:load}.ef-node.offline{filter:grayscale(.7);opacity:.65}
  .ef-icon{width:48px;height:48px;flex:0 0 48px;border-radius:14px;display:grid;place-items:center;font-size:1.3rem}.ef-solar .ef-icon{background:#fff3d8;color:#e39200}.ef-grid .ef-icon{background:#f5e7f2;color:var(--blue)}.ef-battery .ef-icon{background:#e3f6ed;color:var(--battery)}.ef-load .ef-icon{background:#e3f5fc;color:var(--load)}
  .ef-grid-symbol{position:relative;width:34px;height:34px;display:grid;place-items:center}.ef-grid-symbol .mdi{font-size:30px;line-height:1;color:#74045f}.ef-grid-symbol .fa-bolt{position:absolute;right:-3px;bottom:-2px;width:17px;height:17px;border:2px solid #fff;border-radius:50%;display:grid;place-items:center;background:#c7911b;color:#fff;font-size:8px;box-shadow:0 2px 5px rgba(91,72,15,.24)}
  .ef-label{font-size:.78rem;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);font-weight:700}.ef-value{font-size:1.75rem;line-height:1.15;font-weight:750;font-variant-numeric:tabular-nums;white-space:nowrap}.ef-value small{font-size:.78rem;margin-left:4px;color:var(--muted)}.ef-meta{font-size:.75rem;color:var(--muted);margin-top:3px}.ef-node-config,.ef-device-config{position:absolute;right:8px;top:8px;width:27px;height:27px;border:1px solid #d5e2ef;border-radius:8px;background:#f7faff;color:#4d6b89;display:grid;place-items:center;font-size:.68rem;cursor:pointer;z-index:4}.ef-node-config:hover,.ef-device-config:hover{border-color:#1769d2;color:#1769d2;background:#eef5ff}
  .ef-battery-qmax{display:flex;align-items:baseline;gap:4px;margin-top:3px;color:#168958;font-size:.68rem;font-weight:650;white-space:nowrap}.ef-battery-qmax strong{font-size:.78rem;font-variant-numeric:tabular-nums}.ef-battery-qmax small{color:var(--muted);font-size:.62rem;font-weight:600}
  .ef-grid-reactive{display:flex;align-items:baseline;justify-content:space-between;gap:5px;margin-top:3px;color:#74045f;font-size:.58rem;font-weight:650;white-space:nowrap}.ef-grid-reactive span{overflow:hidden;text-overflow:ellipsis}.ef-grid-reactive strong{flex:none;font-size:.62rem;font-variant-numeric:tabular-nums}.ef-grid-reactive b{font-size:.74rem}
  .ef-soc{width:100%;height:5px;background:#eceff4;border-radius:5px;margin-top:6px;overflow:hidden}.ef-soc span{display:block;width:0;height:100%;background:linear-gradient(90deg,#e25f5f,#f2b84b 40%,#31aa70);transition:width .4s}
  .ef-hub{--ef-hub-a:#1266d2;--ef-hub-b:#06468f;--ef-hub-c:#073f84;grid-area:hub;justify-self:center;width:150px;height:150px;border-radius:50%;background:radial-gradient(circle at 34% 25%,rgba(91,190,255,.95) 0,rgba(18,102,210,.92) 27%,#073f84 72%),#0752a8;color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;position:relative;z-index:2;border:1px solid rgba(255,255,255,.74);box-shadow:inset 0 0 0 5px rgba(255,255,255,.08),inset 0 -18px 30px rgba(0,28,72,.3),0 0 0 7px rgba(23,105,210,.09),0 16px 38px rgba(7,69,143,.34);animation:efHubBreathe 3.2s ease-in-out infinite}.ef-hub::before{content:"";position:absolute;inset:12px;border:1px solid rgba(255,255,255,.2);border-radius:50%;background:linear-gradient(135deg,rgba(255,255,255,.18),transparent 38%,rgba(105,213,255,.12) 67%,transparent 73%);background-size:220% 220%;animation:efHubShimmer 4s ease-in-out infinite;pointer-events:none}.ef-hub::after{content:"";position:absolute;width:7px;height:7px;right:19px;top:28px;border-radius:50%;background:#7fe6ff;box-shadow:0 0 0 4px rgba(127,230,255,.13),0 0 12px #7fe6ff;animation:efHubStatus 2.2s ease-in-out infinite}.ef-hub i{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;font-size:1.35rem;margin-bottom:8px;color:#fff0a8;background:linear-gradient(145deg,rgba(255,227,119,.25),rgba(255,255,255,.08));border:1px solid rgba(255,238,166,.32);box-shadow:0 7px 18px rgba(0,28,70,.24),inset 0 1px rgba(255,255,255,.28);text-shadow:0 0 11px rgba(255,224,91,.8);animation:efHubBolt 2.4s ease-in-out infinite}.ef-hub strong{font-size:.76rem;letter-spacing:.16em;text-shadow:0 2px 6px rgba(0,28,70,.35)}.ef-hub small{font-size:.67rem;color:#fff;opacity:.82;margin-top:5px}.ef-hub-ring{position:absolute;inset:-13px;border:1px solid rgba(23,105,210,.23);border-radius:50%;box-shadow:0 0 0 5px rgba(23,105,210,.035);animation:efHubRing 3.2s ease-in-out infinite;pointer-events:none}.ef-hub-ring::before{content:"";position:absolute;inset:-2px;border-radius:50%;background:conic-gradient(from 0deg,transparent 0 18%,var(--ef-hub-a) 24%,transparent 31% 58%,var(--ef-hub-b) 65%,transparent 72%);padding:2px;-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude;animation:efHubOrbit 5s linear infinite;filter:drop-shadow(0 0 5px var(--ef-hub-a))}.ef-hub-orbit{position:absolute;inset:-20px;border-radius:50%;pointer-events:none}.ef-hub-orbit span{position:absolute;inset:0;border-radius:50%;animation:efEnergyParticle 3.6s linear infinite}.ef-hub-orbit span::before{content:"";position:absolute;left:50%;top:-1px;width:15px;height:6px;border-radius:8px;background:linear-gradient(90deg,transparent,var(--ef-hub-a) 55%,#fff);box-shadow:0 0 7px var(--ef-hub-a),0 0 14px var(--ef-hub-a);transform:translateX(-50%)}.ef-hub-orbit span:nth-child(2){animation-duration:4.8s;animation-delay:-2.1s;animation-direction:reverse}.ef-hub-orbit span:nth-child(2)::before{width:11px;background:linear-gradient(90deg,transparent,var(--ef-hub-b) 60%,#fff);box-shadow:0 0 7px var(--ef-hub-b),0 0 14px var(--ef-hub-b)}.ef-hub-orbit span:nth-child(3){inset:7px;animation-duration:2.9s;animation-delay:-1.4s}.ef-hub-orbit span:nth-child(3)::before{width:7px;height:5px;background:var(--ef-hub-c);box-shadow:0 0 8px var(--ef-hub-c)}
  .ef-line{height:7px;background:linear-gradient(180deg,#e6edf5,#cfdbe8);position:absolute;border:1px solid rgba(95,122,151,.12);border-radius:20px;z-index:1;transform-origin:0 50%;box-shadow:inset 0 1px 2px rgba(55,78,104,.14),0 2px 8px rgba(46,72,103,.08)}.ef-line span{position:absolute;inset:-4px -2px;border-radius:20px;background:radial-gradient(circle at center,#fff 0 2px,currentColor 2.5px 5px,transparent 5.5px) 0 center/28px 15px repeat-x;animation:efFlow .8s linear infinite;filter:drop-shadow(0 0 3px currentColor)}.ef-line::after{content:"";position:absolute;right:-3px;top:50%;transform:translate(62%,-50%);width:0;height:0;border-top:9px solid transparent;border-bottom:9px solid transparent;border-left:14px solid currentColor;filter:drop-shadow(0 2px 2px rgba(38,61,87,.2))}.ef-line::before{content:attr(data-flow);position:absolute;left:50%;top:-32px;transform:translateX(-50%) rotate(var(--ef-counter-angle,0deg));background:linear-gradient(180deg,#fff,#f6f9fc);border:1px solid currentColor;color:currentColor;border-radius:15px;padding:3px 9px;font-size:.65rem;font-weight:750;letter-spacing:.01em;white-space:nowrap;box-shadow:0 4px 11px rgba(31,55,89,.1)}.ef-line.reverse span{animation-direction:reverse}.ef-line.reverse::after{right:auto;left:-3px;transform:translate(-62%,-50%);border-left:0;border-right:14px solid currentColor}.ef-line.idle span{display:none}.ef-line.idle::after{border-left-color:#aab5c2;border-right-color:#aab5c2;opacity:.55}.ef-line.idle::before{color:#7c8998;border-color:#cbd3dc;background:#f7f9fb;box-shadow:none}.ef-line-solar{color:var(--solar)}.ef-line-grid{color:var(--blue)}.ef-line-load{color:var(--load)}.ef-line-battery{color:var(--battery)}
  .ef-flow-svg{position:absolute;inset:0;width:100%;height:100%;z-index:1;overflow:visible;pointer-events:none}.ef-svg-track{fill:none;stroke:#d8e2ed;stroke-width:8;stroke-linecap:round;stroke-linejoin:round}.ef-svg-flow{fill:none;stroke:currentColor;stroke-width:5;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:3 13;animation:efSvgFlow 1.35s linear infinite;filter:drop-shadow(0 0 2.5px currentColor);will-change:stroke-dashoffset}.ef-svg-flow.reverse{animation-direction:reverse}.ef-svg-flow.idle{display:none}.ef-color-layer{animation-name:efColorFlow;stroke-dasharray:3 var(--ef-color-gap);stroke-dashoffset:var(--ef-start-offset)}.ef-line{width:1px!important;height:1px!important;background:none!important;border:0!important;box-shadow:none!important;transform:none!important;z-index:3}.ef-line span,.ef-line::after{display:none!important}.ef-line::before{top:-21px;transform:translate(-50%,-50%);z-index:4}
  @keyframes efSvgFlow{to{stroke-dashoffset:-32}}
  @keyframes efColorFlow{from{stroke-dashoffset:var(--ef-start-offset)}to{stroke-dashoffset:calc(var(--ef-start-offset) - var(--ef-color-cycle))}}
  @keyframes efFlow{to{transform:translateX(32px)}}
  @keyframes efHubRing{0%,100%{transform:scale(.985);opacity:.65}50%{transform:scale(1.025);opacity:1}}
  @keyframes efHubStatus{0%,100%{opacity:.55;transform:scale(.85)}50%{opacity:1;transform:scale(1.12)}}
  @keyframes efHubOrbit{to{transform:rotate(360deg)}}
  @keyframes efEnergyParticle{to{transform:rotate(360deg)}}
  @keyframes efHubShimmer{0%,100%{background-position:10% 15%}50%{background-position:90% 85%}}
  @keyframes efHubBolt{0%,100%{transform:translateY(0);text-shadow:0 0 8px rgba(255,224,91,.65)}50%{transform:translateY(-3px);text-shadow:0 0 17px rgba(255,224,91,1)}}
  @keyframes efHubBreathe{0%,100%{box-shadow:inset 0 0 0 5px rgba(255,255,255,.08),inset 0 -18px 30px rgba(0,28,72,.3),0 0 0 7px rgba(23,105,210,.09),0 16px 38px rgba(7,69,143,.3)}50%{box-shadow:inset 0 0 0 5px rgba(255,255,255,.11),inset 0 -18px 30px rgba(0,28,72,.25),0 0 0 10px rgba(76,193,255,.11),0 18px 44px rgba(7,93,184,.42)}}
  @media(prefers-reduced-motion:reduce){.ef-hub,.ef-hub::before,.ef-hub::after,.ef-hub i,.ef-hub-ring,.ef-hub-ring::before,.ef-hub-orbit span{animation:none!important}}
  .ef-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:14px}.ef-summary>div{display:grid;grid-template-columns:auto 1fr;grid-template-rows:auto auto;column-gap:11px;background:#fff;border:1px solid #e4eaf1;border-radius:12px;padding:13px 15px}.ef-summary-icon{grid-row:1/3;width:36px;height:36px;border-radius:10px;display:grid;place-items:center}.ef-summary-icon.solar{background:#fff3d8;color:#e39200}.ef-summary-icon.clean{background:#e3f6ed;color:#168958}.ef-summary-icon.state{background:#f5e7f2;color:#74045f}.ef-summary-icon .ef-grid-symbol{width:27px;height:27px}.ef-summary-icon .ef-grid-symbol .mdi{font-size:25px}.ef-summary-icon .ef-grid-symbol .fa-bolt{right:-2px;bottom:-2px;width:14px;height:14px;border-width:1.5px;font-size:6px}.ef-summary small{color:var(--muted)}.ef-summary strong{font-size:1rem}
  .ef-node-insight{display:flex;align-items:center;justify-content:space-between;gap:7px;margin-top:8px;padding:2px 0}.ef-node-insight small{overflow:hidden;font-size:.57rem;font-weight:650;line-height:1.15;white-space:nowrap;text-overflow:ellipsis}.ef-node-insight strong{flex:none;font-size:.7rem}.ef-solar .ef-node-insight small,.ef-solar .ef-node-insight strong{color:#b56b00}.ef-grid .ef-node-insight small,.ef-grid .ef-node-insight strong{color:#74045f}.ef-battery .ef-node-insight small,.ef-battery .ef-node-insight strong{color:#168958}
  .ef-mqtt-control{margin-top:18px;background:#fff;border:1px solid #e4eaf1;border-radius:15px;padding:18px}.ef-mqtt-control .ef-panel-head h5 i{color:#25a56a;margin-right:5px}.ef-log-head-tools{display:flex;align-items:center;gap:9px}.ef-log-head-tools input{min-height:35px;border:1px solid #d4deea;border-radius:8px;padding:5px 8px;color:#38536f}.ef-mqtt-summary{display:grid;grid-template-columns:2fr repeat(3,1fr);gap:10px}.ef-mqtt-summary>div{border:1px solid #e4eaf1;border-radius:10px;background:#f8fafc;padding:10px 12px}.ef-mqtt-summary small,.ef-mqtt-summary strong,.ef-mqtt-summary span{display:block}.ef-mqtt-summary small,.ef-mqtt-summary span{color:var(--muted);font-size:.67rem}.ef-mqtt-summary strong{font-size:1rem;margin:2px 0}.ef-mqtt-summary .success strong{color:#168958}.ef-mqtt-summary .failed strong{color:#d34b4b}.ef-mqtt-table-wrap{margin-top:12px;height:auto;max-height:none;overflow-x:auto;overflow-y:visible;border:1px solid #e5ebf2;border-radius:10px}.ef-mqtt-table{width:100%;border-collapse:collapse;font-size:.72rem;white-space:nowrap}.ef-mqtt-table th{position:sticky;top:0;background:#eef4fa;color:#52677d;text-align:left;padding:8px 10px}.ef-mqtt-table td{padding:8px 10px;border-top:1px solid #edf1f5}.ef-mqtt-table td[colspan]{text-align:center;color:var(--muted);padding:25px}.ef-mqtt-result{display:inline-block;padding:2px 7px;border-radius:9px;font-weight:700}.ef-mqtt-result.ok{color:#168958;background:#e5f7ee}.ef-mqtt-result.fail{color:#c53c3c;background:#fdeaea}.ef-mqtt-result.wait{color:#9a6a00;background:#fff4d8}
  .ef-log-row-limit{display:flex;align-items:center;gap:7px;min-height:35px;padding:3px 4px 3px 10px;border:1px solid #d4deea;border-radius:9px;background:#f8fafc;color:#718397}.ef-log-row-limit>span{font-size:.66rem;font-weight:700;white-space:nowrap}.ef-log-row-limit select{min-width:82px;min-height:27px;padding:3px 24px 3px 8px;border:1px solid #cbdbea;border-radius:7px;background:#fff;color:#315b7e;font-size:.7rem;font-weight:800;outline:0;cursor:pointer}.ef-log-row-limit:focus-within{border-color:#9bbfe1;box-shadow:0 0 0 3px rgba(23,105,210,.08)}.ef-log-row-limit select:focus{border-color:#1769d2}
  .ef-mqtt-table th:last-child,.ef-mqtt-table td:last-child{min-width:290px;white-space:normal;line-height:1.4}.ef-reason-line{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;color:#314b65}.ef-reason-toggle{display:grid;place-items:center;flex:0 0 25px;width:25px;height:25px;margin-top:-3px;padding:0;border:0;background:transparent;color:#3978b7;font-size:.78rem;cursor:pointer;transition:.18s}.ef-reason-toggle:hover,.ef-reason-toggle[aria-expanded="true"]{background:transparent;color:#1769d2;transform:scale(1.12)}.ef-reason-reference{display:block;margin-top:6px;padding:6px 8px;border-left:3px solid #4d88c4;border-radius:0 6px 6px 0;background:#f2f7fc;color:#61788f;font-size:.64rem}.ef-reason-reference[hidden]{display:none}.ef-reason-reference i{margin-right:3px;color:#4d88c4}
  .ef-log-date-picker{position:relative;display:flex}.ef-log-date-picker>input{appearance:none;-webkit-appearance:none;border-radius:8px 0 0 8px}.ef-log-date-picker>input::-webkit-calendar-picker-indicator{display:none;-webkit-appearance:none}.ef-log-date-picker>button{display:grid;place-items:center;width:36px;border:1px solid #d4deea;border-left:0;border-radius:0 8px 8px 0;background:#f5f9fd;color:#3978b7;cursor:pointer}.ef-log-date-picker>button:hover{background:#e8f2fd;color:#1769d2}.ef-log-calendar{position:absolute;z-index:20;right:0;top:calc(100% + 7px);width:300px;padding:12px;border:1px solid #d7e2ed;border-radius:11px;background:#fff;box-shadow:0 14px 36px rgba(25,48,73,.2)}.ef-log-calendar[hidden]{display:none}.ef-log-calendar-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}.ef-log-calendar-head>button{display:grid;place-items:center;width:29px;height:29px;border:1px solid #d6e1ec;border-radius:7px;background:#fff;color:#3978b7;font-size:1rem;cursor:pointer}.ef-log-calendar-head strong{color:#294e70;font-size:.8rem}.ef-log-calendar-week,.ef-log-calendar-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px}.ef-log-calendar-week span{text-align:center;color:#8696a6;font-size:.6rem;font-weight:700}.ef-log-calendar-grid button{position:relative;min-height:34px;border:1px solid transparent;border-radius:7px;background:transparent;color:#8b99a7;font-size:.68rem;cursor:default}.ef-log-calendar-grid button.outside{opacity:.35}.ef-log-calendar-grid button.has-data{border-color:#b9dfca;background:#edf9f2;color:#16784c;font-weight:800;cursor:pointer}.ef-log-calendar-grid button.has-data::after{content:'';position:absolute;left:50%;bottom:3px;width:4px;height:4px;border-radius:50%;background:#25a56a;transform:translateX(-50%)}.ef-log-calendar-grid button.selected{border-color:#1769d2;background:#e8f2fd;color:#155da8}.ef-log-calendar-grid button.today{box-shadow:inset 0 0 0 1px #9dbce0}.ef-log-calendar>small{display:flex;align-items:center;gap:5px;margin-top:9px;color:#718397;font-size:.62rem}.ef-log-calendar>small i{width:7px;height:7px;border-radius:50%;background:#25a56a}@media(max-width:560px){.ef-log-calendar{position:fixed;left:14px;right:14px;top:50%;width:auto;transform:translateY(-50%)}}
  .ef-log-calendar-jump{display:grid;grid-template-columns:minmax(105px,1fr) 76px;gap:6px;flex:1;margin:0 7px}.ef-log-calendar-jump select{width:100%;min-height:29px;padding:3px 6px;border:1px solid #d6e1ec;border-radius:7px;background:#fff;color:#294e70;font-size:.68rem;font-weight:700;outline:0}.ef-log-calendar-jump select:focus{border-color:#1769d2;box-shadow:0 0 0 2px rgba(23,105,210,.1)}
  .ef-log-report-actions{display:flex;justify-content:flex-end;margin:-2px 0 10px}.ef-log-report-actions button{display:inline-flex;align-items:center;gap:7px;min-height:35px;padding:7px 12px;border:1px solid #b9dfca;border-radius:9px;background:#edf9f2;color:#16784c;font-size:.7rem;font-weight:800;cursor:pointer}.ef-log-report-actions button:hover{border-color:#7fc7a0;background:#def4e8}.ef-log-report-actions button i{font-size:.76rem}
  .ef-action-report{display:none!important}
  .ef-action-report-summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.ef-action-summary-group{overflow:hidden;border:1px solid #e1e9f1;border-radius:12px;background:#f8fafc}.ef-action-summary-total,.ef-action-summary-breakdown>button{position:relative;width:100%;border:0;text-align:left;cursor:pointer}.ef-action-summary-total{display:grid;grid-template-columns:1fr auto;align-items:center;gap:3px 12px;padding:12px 14px;background:#fff}.ef-action-summary-total>span{font-size:.73rem;font-weight:800}.ef-action-summary-total>span i{width:17px;margin-right:4px}.ef-action-summary-total>strong{grid-column:2;grid-row:1/3;margin:0;font-size:1.08rem}.ef-action-summary-total>small{grid-column:1}.ef-action-summary-group.charge .ef-action-summary-total>span,.ef-action-summary-group.charge .ef-action-summary-total>strong{color:#168958}.ef-action-summary-group.discharge .ef-action-summary-total>span,.ef-action-summary-group.discharge .ef-action-summary-total>strong{color:#d34b4b}.ef-action-summary-breakdown{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1px;background:#e1e9f1;border-top:1px solid #e1e9f1}.ef-action-summary-breakdown>button{padding:10px 13px;background:#f8fafc}.ef-action-summary-total:hover,.ef-action-summary-breakdown>button:hover{background:#f1f6fa}.ef-action-summary-total.active,.ef-action-summary-breakdown>button.active{box-shadow:inset 0 0 0 2px #1769d2;background:#edf5ff}.ef-action-summary-total.active::after,.ef-action-summary-breakdown>button.active::after{content:'กำลังกรอง';position:absolute;right:9px;bottom:6px;color:#1769d2;font-size:.52rem;font-weight:800}
  .ef-table-sort{display:inline-flex;align-items:center;gap:5px;padding:0;border:0;background:transparent;color:inherit;font:inherit;font-weight:700;white-space:nowrap;cursor:pointer}.ef-table-sort i{width:10px;color:#9aabba;font-size:.58rem}.ef-table-sort:hover,.ef-table-sort.active{color:#1769d2}.ef-table-sort.active i{color:#1769d2}.ef-table-sort:focus-visible{outline:2px solid rgba(23,105,210,.3);outline-offset:3px;border-radius:3px}
  .ef-mqtt-control{order:100}
  .ef-device-panel{order:99}
  .ef-device-panel{margin-top:18px;background:#fff;border:1px solid #e4eaf1;border-radius:15px;padding:18px}.ef-panel-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}.ef-panel-head h5{margin:0;font-weight:700}.ef-panel-head small{color:var(--muted)}.ef-panel-head>span{font-size:.78rem;background:#eef3f9;color:#49627e;padding:5px 10px;border-radius:20px}.ef-device-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:9px}.ef-device{position:relative;border:1px solid #e7ecf3;border-radius:10px;padding:12px;padding-right:43px;display:flex;justify-content:space-between;gap:8px}.ef-device.offline{background:#f8f9fb;opacity:.65}.ef-device-name{font-weight:650;font-size:.83rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ef-device-sn{font-size:.69rem;color:var(--muted)}.ef-device-power{text-align:right;font-weight:750;white-space:nowrap}.ef-device-power small{display:block;color:var(--muted);font-size:.66rem;font-weight:500}.ef-device-pmax{margin-top:4px;padding-top:4px;border-top:1px solid #edf1f5;color:#b66b00!important;font-weight:700!important}
  .ef-energy-history{margin-top:18px}.ef-history-panel{position:relative;background:#fff;border:1px solid #dbe4ee;border-radius:15px;padding:18px;box-shadow:0 8px 22px rgba(31,55,89,.06);overflow:hidden}.ef-history-panel::before{content:'';position:absolute;left:0;right:0;top:0;height:4px;background:linear-gradient(90deg,#74045f 0 33.33%,#f5a623 33.33% 66.66%,#25a56a 66.66%)}.ef-history-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}.ef-history-head h5{margin:0;font-weight:700}.ef-history-head h5 i{color:#1769d2;margin-right:5px}.ef-history-head small{color:var(--muted)}.ef-history-controls{display:flex;gap:9px}.ef-history-controls label>span{display:block;font-size:.64rem;font-weight:700;color:#65788c;margin-bottom:3px}.ef-history-controls select,.ef-history-controls input{min-width:125px;min-height:36px;border:1px solid #d4deea;border-radius:7px;padding:5px 8px;background:#fff;color:#38536f}.ef-history-stats{display:flex;gap:10px;margin-top:12px;padding:10px;border-radius:12px;background:#f8fafc;border:1px solid #edf1f5}.ef-history-stats>div{min-width:135px;background:#fff;border:1px solid #e2e9f1;border-radius:10px;padding:8px 11px}.ef-history-stats small,.ef-history-stats strong{display:block}.ef-history-stats small{color:var(--muted);font-size:.66rem}.ef-history-stats strong{font-size:1rem;margin-top:2px}.ef-history-chart{position:relative;min-height:350px;margin-top:12px;padding:8px;border:1px solid #edf1f5;border-radius:12px;background:#fff}.ef-history-chart.is-loading::before{content:'';position:absolute;inset:0;z-index:20;border-radius:10px;background:rgba(255,255,255,.82);backdrop-filter:blur(1px)}.ef-history-chart.is-loading::after{content:'กำลังโหลดข้อมูล…';position:absolute;left:50%;top:50%;z-index:21;transform:translate(-50%,-50%);padding-top:44px;color:#1769d2;font-size:.8rem;font-weight:750;white-space:nowrap;background:radial-gradient(circle at 50% 14px,transparent 10px,#1769d2 11px 13px,transparent 14px);animation:efLoadingPulse 1s ease-in-out infinite}.ef-history-empty{text-align:center;color:var(--muted);padding:55px 12px}@keyframes efLoadingPulse{0%,100%{opacity:.55}50%{opacity:1}}
  .ef-history-stat-action{cursor:pointer;transition:border-color .18s,box-shadow .18s,transform .18s}.ef-history-stat-action:hover,.ef-history-stat-action:focus{outline:none;border-color:#79aee8;box-shadow:0 4px 13px rgba(23,105,210,.14);transform:translateY(-1px)}.ef-history-stat-action::after{content:'คลิกดูจุด';display:block;margin-top:3px;color:#1769d2;font-size:.58rem;font-weight:700}
  .ef-history-stats{flex-wrap:wrap}.ef-history-stats>div{flex:1 1 155px;min-width:0}.ef-history-stats small{overflow-wrap:anywhere}
  #efHistorySolarTotalCard{border-color:#f0d7a3;background:#fffaf0}#efHistorySolarTotalCard strong{color:#a96300}
  #efHistoryBatteryChargeCard{border-color:#bde6d2;background:#f3fcf7}#efHistoryBatteryChargeCard strong{color:#168958}#efHistoryBatteryDischargeCard{border-color:#cbdcf2;background:#f5f9ff}#efHistoryBatteryDischargeCard strong{color:#1769d2}
  .ef-consumption-summary{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:18px;padding:15px 17px;border:1px solid #dce8f6;border-radius:13px;background:linear-gradient(135deg,#f7fbff,#eef6ff)}.ef-consumption-summary-title span,.ef-consumption-summary-title small{display:block}.ef-consumption-summary-title span{font-weight:800;color:#174f8e}.ef-consumption-summary-title span i{color:#f5a623;margin-right:6px}.ef-consumption-summary-title small{margin-top:3px;color:var(--muted)}.ef-consumption-summary-grid{display:grid;grid-template-columns:repeat(3,minmax(135px,1fr));gap:9px}.ef-consumption-summary-grid>div{padding:9px 13px;border-radius:9px;background:#fff;border:1px solid #dfe9f4}.ef-consumption-summary-grid small,.ef-consumption-summary-grid strong{display:block}.ef-consumption-summary-grid small{font-size:.68rem;color:var(--muted)}.ef-consumption-summary-grid strong{margin-top:2px;color:#143f70;font-size:1.05rem}
  .ef-solar-summary{border-color:#f4ddb0;background:linear-gradient(135deg,#fffdf7,#fff6df)}.ef-solar-summary .ef-consumption-summary-title span,.ef-solar-summary .ef-consumption-summary-grid strong{color:#9a5a00}.ef-solar-summary .ef-consumption-summary-grid>div{border-color:#f1dfbd}
  .ef-energy-donut-values .discharge i{background:#7457d9}
  .ef-energy-history{display:flex;flex-direction:column}.ef-history-panel{order:1}.ef-energy-donut-panel{order:2;margin-top:18px;margin-bottom:0}
  .ef-energy-donut-panel{overflow:hidden}.ef-energy-donut-body{grid-template-columns:300px minmax(0,1fr);gap:18px}.ef-energy-donut-chart{width:100%;min-width:0;min-height:270px}.ef-energy-donut-values{grid-template-columns:repeat(2,minmax(0,1fr));align-content:center}.ef-energy-donut-values>div{grid-template-columns:12px minmax(0,1fr);grid-template-rows:auto auto;min-width:0;min-height:82px}.ef-energy-donut-values>div>i{grid-row:1/3}.ef-energy-donut-values span{min-width:0;line-height:1.3;overflow-wrap:anywhere}.ef-energy-donut-values strong{grid-column:2;min-width:0;font-size:1rem;white-space:nowrap}
  .ef-energy-donut-values{grid-template-columns:repeat(2,minmax(0,1fr))}
  .ef-energy-donut-panel{position:relative}.ef-energy-donut-panel::before{content:'';position:absolute;left:0;right:0;top:0;height:4px;background:linear-gradient(90deg,#1769d2 0 25%,#f5a623 25% 50%,#25a56a 50% 75%,#7457d9 75%);border-radius:15px 15px 0 0}
  .ef-energy-donut-panel{position:relative;overflow:hidden;margin-top:18px;margin-bottom:0;padding:18px;background:#fff;border:1px solid #dbe4ee;border-radius:15px;box-shadow:0 8px 22px rgba(31,55,89,.06)}.ef-energy-donut-head{display:flex;align-items:flex-start;justify-content:space-between;gap:15px}.ef-energy-donut-head>div{min-width:0}.ef-energy-donut-head strong,.ef-energy-donut-head small{display:block}.ef-energy-donut-head strong{color:#174f8e;font-size:1rem;font-weight:750}.ef-energy-donut-head strong i{margin-right:6px}.ef-energy-donut-head small{margin-top:3px;color:var(--muted);font-size:.7rem}.ef-energy-donut-head label{flex:none}.ef-energy-donut-head label span{display:block;margin-bottom:3px;color:#65788c;font-size:.64rem;font-weight:700}.ef-energy-donut-head select{min-width:125px;min-height:36px;padding:5px 9px;border:1px solid #d4deea;border-radius:7px;background:#fff;color:#38536f}.ef-energy-donut-body{display:grid;grid-template-columns:300px minmax(0,1fr);align-items:center;gap:18px;margin-top:10px}.ef-energy-donut-chart{width:100%;min-width:0;min-height:270px}.ef-energy-donut-values{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-content:center;gap:10px}.ef-energy-donut-values>div{display:grid;grid-template-columns:12px minmax(0,1fr);grid-template-rows:auto auto;align-items:center;gap:4px 10px;min-width:0;min-height:82px;padding:12px 14px;border:1px solid #e5ebf2;border-radius:11px;background:#f9fbfd}.ef-energy-donut-values i{grid-row:1/3;width:10px;height:10px;border-radius:50%}.ef-energy-donut-values span{min-width:0;color:#52677d;font-size:.76rem;line-height:1.3;overflow-wrap:anywhere}.ef-energy-donut-values strong{grid-column:2;min-width:0;color:#173f6b;font-size:1rem;white-space:nowrap}.ef-energy-donut-values .consumption i{background:#1769d2}.ef-energy-donut-values .solar i{background:#f5a623}.ef-energy-donut-values .charge i{background:#25a56a}.ef-energy-donut-values .discharge i{background:#7457d9}
  .ef-energy-donut-chart-wrap{min-width:0;text-align:center}.ef-energy-donut-selected{display:inline-flex;align-items:center;gap:8px;max-width:100%;margin:-6px auto 8px;padding:7px 11px;border:1px solid currentColor;border-radius:9px;background:#fff;font-size:.72rem;font-weight:700;box-shadow:0 4px 12px rgba(31,55,89,.08)}.ef-energy-donut-selected i{width:9px;height:9px;flex:none;border-radius:50%}.ef-energy-donut-selected b{font-size:.8rem;white-space:nowrap}
  .ef-energy-donut-chart-wrap{position:relative}.ef-energy-donut-center-total{pointer-events:none;position:absolute;z-index:3;left:50%;top:142px;width:145px;transform:translate(-50%,-50%);text-align:center}.ef-energy-donut-center-total small,.ef-energy-donut-center-total strong{display:block}.ef-energy-donut-center-total small{color:#718096;font-size:.66rem;font-weight:650}.ef-energy-donut-center-total strong{margin-top:3px;color:#173f6b;font-size:.86rem;line-height:1.15;white-space:nowrap}
  .ef-chart-tooltip{min-width:190px;padding:9px 11px;background:#fff;color:#38536f;font-size:.72rem;box-shadow:0 5px 18px rgba(24,45,70,.14)}.ef-chart-tooltip>strong{display:block;margin-bottom:6px;color:#263f58}.ef-chart-tooltip-row{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:2px 0}.ef-chart-tooltip-row i{width:8px;height:8px;border-radius:50%;margin-right:6px}.ef-chart-tooltip-row span{display:flex;align-items:center}.ef-chart-tooltip-total{margin-top:5px;padding-top:6px;border-top:1px solid #dfe7ef;color:#1769d2;font-weight:800}
  .ef-ac-modal{position:fixed;inset:0;z-index:1200;display:none}.ef-ac-modal.open{display:block}.ef-ac-backdrop{position:absolute;inset:0;background:rgba(15,31,52,.48);backdrop-filter:blur(2px)}.ef-ac-dialog{position:relative;width:min(620px,calc(100vw - 28px));max-height:calc(100vh - 40px);overflow:auto;margin:20px auto;background:#fff;border-radius:16px;box-shadow:0 24px 70px rgba(15,31,52,.28)}.ef-ac-head,.ef-ac-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 18px}.ef-ac-head{border-bottom:1px solid #e7edf4}.ef-ac-head strong,.ef-ac-head small{display:block}.ef-ac-head small{color:var(--muted);font-size:.72rem;margin-top:2px}.ef-ac-head>button{border:0;background:#f1f4f8;width:31px;height:31px;border-radius:8px;color:#60748a}.ef-ac-body{padding:18px}.ef-ac-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.ef-ac-grid label>span,.ef-ac-check-title{display:block;color:#52677d;font-size:.72rem;font-weight:700;margin-bottom:5px}.ef-ac-grid input,.ef-ac-grid select,.ef-ac-grid textarea{width:100%;min-height:40px;border:1px solid #d4deea;border-radius:8px;padding:7px 9px}.ef-ac-grid textarea{resize:vertical}.ef-ac-check{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid #e0e7ef;border-radius:9px;font-size:.78rem}.ef-ac-check input{width:auto;min-height:auto}.ef-ac-wide{grid-column:1/-1}.ef-ac-foot{border-top:1px solid #e7edf4}.ef-ac-foot>span{font-size:.72rem;color:#718096}.ef-ac-foot>div{display:flex;gap:8px}.ef-ac-cancel,.ef-ac-save{border-radius:8px;padding:8px 13px;font-weight:650}.ef-ac-cancel{border:1px solid #d4deea;background:#fff;color:#617489}.ef-ac-save{border:1px solid #1769d2;background:#1769d2;color:#fff}.ef-ac-save:disabled{opacity:.55}
  .ef-ac-grid .ef-ac-reference{min-height:34px;margin-bottom:6px;border-color:#b8cde4;background:#f4f8fd;color:#315b87;font-size:.72rem}.ef-ac-grid :disabled{cursor:not-allowed;opacity:.7}
  .ef-ac-help{display:block;margin-top:6px;color:#687b8f;font-size:.67rem;line-height:1.45}.ef-ac-help i{margin-right:3px;color:#25a56a}.ef-time-estimate{padding:6px 8px;border-radius:7px;background:#f1f7fd;color:#41698f}.ef-time-estimate i{color:#1769d2}.ef-time-estimate b{color:#145ca8}
  .ef-peak-average{display:flex;align-items:center;gap:8px;padding:9px 11px;border-radius:9px;background:#f1f7fd;color:#41698f;font-size:.68rem}.ef-peak-average i{color:#1769d2}.ef-peak-average b{color:#145ca8;font-size:.75rem}
  .ef-ac-info{position:relative;display:inline-grid;place-items:center;width:20px;height:20px;margin-left:4px;padding:0;border:0;border-radius:50%;background:transparent;color:#2f7bc8;font-size:.78rem;line-height:1;cursor:help;vertical-align:middle;transition:color .15s,background .15s,transform .15s}.ef-ac-info:hover,.ef-ac-info:focus{outline:none;color:#1259a2;background:#e8f2fd;transform:scale(1.08)}.ef-ac-info::after{content:attr(data-ac-info-text);pointer-events:none;position:absolute;z-index:1300;left:50%;bottom:calc(100% + 8px);width:260px;max-width:calc(100vw - 64px);box-sizing:border-box;padding:9px 11px;border-radius:8px;background:#17324f;color:#fff;font-size:.68rem;font-weight:500;line-height:1.45;text-align:left;white-space:normal;box-shadow:0 8px 22px rgba(15,31,52,.25);opacity:0;visibility:hidden;transform:translate(-50%,5px);transition:opacity .15s,transform .15s,visibility .15s}.ef-ac-info::before{content:'';pointer-events:none;position:absolute;z-index:1301;left:50%;bottom:calc(100% + 3px);border:5px solid transparent;border-top-color:#17324f;opacity:0;visibility:hidden;transform:translateX(-50%)}.ef-ac-info.tip-left::after{left:0;transform:translate(0,5px)}.ef-ac-info.tip-left::before{left:9px}.ef-ac-info.tip-right::after{left:auto;right:0;transform:translate(0,5px)}.ef-ac-info.tip-right::before{left:auto;right:9px;transform:none}.ef-ac-info.tip-below::after{top:calc(100% + 8px);bottom:auto}.ef-ac-info.tip-below::before{top:calc(100% + 3px);bottom:auto;border-top-color:transparent;border-bottom-color:#17324f}.ef-ac-info:hover::after,.ef-ac-info:focus::after,.ef-ac-info:hover::before,.ef-ac-info:focus::before{opacity:1;visibility:visible}.ef-ac-info:hover::after,.ef-ac-info:focus::after{transform:translate(-50%,0)}.ef-ac-info.tip-left:hover::after,.ef-ac-info.tip-left:focus::after,.ef-ac-info.tip-right:hover::after,.ef-ac-info.tip-right:focus::after{transform:translate(0,0)}.ef-ac-info:hover::before,.ef-ac-info:focus::before{transform:translateX(-50%)}.ef-ac-info.tip-right:hover::before,.ef-ac-info.tip-right:focus::before{transform:none}
  .ef-tou-calendar{padding:12px;border:1px solid #dce5ef;border-radius:11px;background:#f8fafc}.ef-tou-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}.ef-tou-head strong{color:#294e70;font-size:.86rem}.ef-tou-nav{display:grid;place-items:center;width:31px;height:31px;border:1px solid #d5e1ed;border-radius:8px;background:#fff;color:#3c668b;font:700 1rem/1 Arial,sans-serif;cursor:pointer}.ef-tou-week,.ef-tou-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:5px}.ef-tou-week span{text-align:center;color:#8494a5;font-size:.64rem;font-weight:700;padding:3px}.ef-tou-day{position:relative;min-height:48px;border:1px solid #e1e8f0;border-radius:8px;background:#fff;color:#405a72;font-size:.72rem;cursor:pointer}.ef-tou-day:hover{border-color:#76a9df;background:#f3f8fe}.ef-tou-day.outside{opacity:.32}.ef-tou-day.today{box-shadow:inset 0 0 0 1px #1769d2}.ef-tou-day.selected{border-color:#2a9c69;background:#eaf8f1;color:#176c48;font-weight:800}.ef-tou-day.pending{border-color:#e2a52b;background:#fff8e7;color:#8c620a}.ef-tou-mark{position:absolute;right:5px;bottom:4px;font:700 .7rem/1 Arial,sans-serif}.ef-tou-day.selected .ef-tou-mark{color:#20a467}.ef-tou-day.pending .ef-tou-mark{color:#df9a13}.ef-tou-legend{display:flex;flex-wrap:wrap;gap:12px;margin-top:10px;color:#687b8f;font-size:.67rem}.ef-tou-legend span{display:flex;align-items:center;gap:5px}.ef-tou-legend-mark{font:700 .75rem/1 Arial,sans-serif}.ef-tou-legend-mark.saved{color:#20a467}.ef-tou-legend-mark.pending{color:#df9a13}
  .ef-strategy-box{position:relative;margin-top:14px;padding:12px;border:1px solid #dbe4eb;border-radius:11px;background:#fbfcfd}
  .ef-strategy-toggle{position:relative;display:flex;align-items:center;gap:11px;padding:11px 12px;border:1px solid #d6e5dd;border-radius:11px;background:#fff;cursor:default;transition:.2s}.ef-strategy-toggle:hover{border-color:#8fc9aa;box-shadow:0 4px 12px rgba(22,137,88,.08)}.ef-toggle-control{display:flex;flex:0 0 46px;margin:0;cursor:pointer}.ef-toggle-control>input{position:absolute;width:1px!important;height:1px;min-height:0!important;opacity:0;pointer-events:none}.ef-toggle-track{position:relative;flex:0 0 46px;width:46px;height:25px;border-radius:20px;background:#b8c2cc;box-shadow:inset 0 1px 3px rgba(24,43,61,.22);transition:.22s}.ef-toggle-track i{position:absolute;left:3px;top:3px;width:19px;height:19px;border-radius:50%;background:#fff;box-shadow:0 2px 5px rgba(23,40,58,.3);transition:.22s}.ef-toggle-copy{min-width:0;flex:1}.ef-toggle-copy b,.ef-toggle-copy small{display:block}.ef-toggle-copy b{color:#304b3e;font-size:.76rem}.ef-toggle-copy small{margin-top:2px;color:#788b81;font-size:.65rem}.ef-toggle-state{min-width:29px;color:#85928c;font-size:.64rem;text-align:right}.ef-toggle-state::after{content:'OFF'}.ef-toggle-control>input:checked+.ef-toggle-track{background:#25a56a;box-shadow:0 0 0 3px rgba(37,165,106,.12),inset 0 1px 3px rgba(10,93,55,.18)}.ef-toggle-control>input:checked+.ef-toggle-track i{transform:translateX(21px)}.ef-strategy-toggle:has(.ef-toggle-control>input:checked) .ef-toggle-state{color:#168958}.ef-strategy-toggle:has(.ef-toggle-control>input:checked) .ef-toggle-state::after{content:'ON'}.ef-toggle-control>input:focus-visible+.ef-toggle-track{outline:3px solid rgba(23,105,210,.2);outline-offset:2px}.ef-strategy-toggle:has(input:disabled){opacity:.65}.ef-strategy-toggle:has(input:disabled) .ef-toggle-control{cursor:not-allowed}
  .ef-toggle-control>.ef-toggle-track{display:block;margin:0}
  .ef-strategy-main-toggle{padding:0 0 11px;border:0;border-bottom:1px solid #e3e9ee;border-radius:0;background:transparent}.ef-strategy-main-toggle:hover{border-color:#e3e9ee;box-shadow:none}.ef-strategy-fields{padding-top:12px;transition:opacity .2s}.ef-strategy-fields.is-hidden{display:none}.ef-strategy-box:has(.ef-strategy-fields.is-hidden) .ef-strategy-main-toggle{padding-bottom:0;border-bottom:0}
  .ef-device-control-toggle.ef-control-disabled .ef-toggle-copy b{color:#77838e}
  .ef-strategy-main-toggle,.ef-device-control-toggle{padding:11px 12px;border:1px solid #d6e5dd;border-radius:11px;background:#fff}.ef-strategy-main-toggle:hover,.ef-device-control-toggle:hover{border-color:#8fc9aa;box-shadow:0 4px 12px rgba(22,137,88,.08)}
  .ef-ac-check{position:relative;gap:11px;padding:11px 12px;border-color:#d6e5dd;border-radius:11px;background:#fff;cursor:default;transition:.2s}.ef-ac-check:hover{border-color:#8fc9aa;box-shadow:0 4px 12px rgba(22,137,88,.08)}.ef-ac-check>input[type=checkbox]{appearance:none;-webkit-appearance:none;position:relative;flex:0 0 46px;width:46px;height:25px;min-height:25px;border:0;border-radius:20px;padding:0;background:#b8c2cc;box-shadow:inset 0 1px 3px rgba(24,43,61,.22);cursor:pointer;transition:.22s}.ef-ac-check>input[type=checkbox]::after{content:'';position:absolute;left:3px;top:3px;width:19px;height:19px;border-radius:50%;background:#fff;box-shadow:0 2px 5px rgba(23,40,58,.3);transition:.22s}.ef-ac-check>input[type=checkbox]:checked{background:#25a56a;box-shadow:0 0 0 3px rgba(37,165,106,.12),inset 0 1px 3px rgba(10,93,55,.18)}.ef-ac-check>input[type=checkbox]:checked::after{transform:translateX(21px)}.ef-ac-check>input[type=checkbox]:focus-visible{outline:3px solid rgba(23,105,210,.2);outline-offset:2px}.ef-ac-check>span{min-width:0;flex:1;margin:0!important}.ef-ac-check>span b,.ef-ac-check>span small{display:block}.ef-ac-check>span b{color:#304b3e;font-size:.76rem}.ef-ac-check>span small{margin-top:2px;color:#788b81;font-size:.65rem}.ef-ac-check::after{content:'OFF';min-width:29px;color:#85928c;font-size:.64rem;font-weight:700;text-align:right}.ef-ac-check:has(input:checked)::after{content:'ON';color:#168958}
  .ef-device-control-zone{padding:0;border:0;border-radius:0;background:transparent}.ef-device-control-fields{padding-top:12px}.ef-device-control-fields.is-hidden{display:none}.ef-device-control-zone:has(.ef-device-control-fields.is-hidden) .ef-device-control-toggle{padding-bottom:0;border-bottom:0}
  .ef-solar-setup{display:grid;gap:14px}.ef-solar-step{padding:14px;border:1px solid #dce7f1;border-radius:12px;background:#f8fbfe}.ef-solar-step-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:11px}.ef-solar-step-title{display:flex;align-items:center;gap:9px;min-width:0}.ef-solar-step-title>i{display:grid;place-items:center;flex:0 0 31px;width:31px;height:31px;border-radius:9px;background:#fff2cf;color:#e49a00}.ef-solar-step-title b,.ef-solar-step-title small{display:block}.ef-solar-step-title b{color:#294e70;font-size:.8rem}.ef-solar-step-title small{margin-top:2px;color:#718397;font-size:.65rem}.ef-solar-everyday{flex:none;padding:5px 9px;border-radius:20px;background:#e8f7ef;color:#168958;font-size:.65rem;font-weight:800}.ef-solar-time-row{display:grid;grid-template-columns:1fr auto 1fr;align-items:end;gap:10px;margin-top:12px}.ef-solar-time-row label>span{display:block;margin-bottom:5px;color:#52677d;font-size:.7rem;font-weight:700}.ef-solar-time-row select{width:100%;min-height:42px;border:1px solid #cbd9e7;border-radius:9px;padding:7px 10px;background:#fff;color:#294e70;font-weight:700}.ef-solar-time-arrow{display:grid;place-items:center;width:29px;height:42px;color:#7a91a8}.ef-solar-time-note{display:flex;align-items:center;gap:6px;margin-top:9px;color:#667d93;font-size:.66rem}.ef-solar-device-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 10px}.ef-solar-device-head>div b,.ef-solar-device-head>div small{display:block}.ef-solar-device-head>div b{color:#294e70;font-size:.82rem}.ef-solar-device-head>div small{margin-top:2px;color:#7a8da1;font-size:.65rem}.ef-solar-device-count{padding:4px 8px;border-radius:20px;background:#eaf3ff;color:#1769d2;font-size:.64rem;font-weight:800}.ef-solar-device-card{margin-top:9px!important}.ef-solar-device-card>.ef-ac-check-title{display:flex;align-items:center;gap:7px;margin-bottom:9px;color:#315b87}.ef-solar-device-card>.ef-ac-check-title::before{content:'\f1ad';font-family:'Font Awesome 6 Free';font-weight:900;color:#e6a114}@media(max-width:560px){.ef-solar-time-row{grid-template-columns:1fr}.ef-solar-time-arrow{width:100%;height:18px;transform:rotate(90deg)}}
  .ef-solar-device-heading{display:flex;align-items:center;gap:10px;min-width:0}.ef-solar-device-heading>i{display:grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border-radius:9px;background:#fff2cf;color:#e49a00;font-size:.9rem}.ef-solar-device-heading>div b,.ef-solar-device-heading>div small{display:block}.ef-solar-device-heading>div b{color:#294e70;font-size:.82rem}.ef-solar-device-heading>div small{margin-top:2px;color:#7a8da1;font-size:.65rem}.ef-solar-device-count{flex:none}.ef-solar-device-card{padding:11px!important;border-color:#d8e5f0!important;box-shadow:0 3px 10px rgba(31,55,89,.035)}.ef-solar-device-card>.ef-ac-check-title::before{display:none}.ef-solar-device-card>.ef-ac-check-title>i{display:grid;place-items:center;flex:0 0 29px;width:29px;height:29px;border-radius:8px;background:#fff5d9;color:#e6a114}.ef-solar-device-card>.ef-ac-check-title>span{min-width:0}.ef-solar-device-card>.ef-ac-check-title b,.ef-solar-device-card>.ef-ac-check-title small{display:block}.ef-solar-device-card>.ef-ac-check-title b{overflow:hidden;color:#294e70;font-size:.76rem;text-overflow:ellipsis;white-space:nowrap}.ef-solar-device-card>.ef-ac-check-title small{margin-top:2px;color:#7c8fa2;font-size:.63rem;font-weight:650}@media(max-width:560px){.ef-solar-device-head{align-items:flex-start}}
  .ef-battery-setup{display:grid;gap:14px}.ef-battery-step{padding:14px;border:1px solid #dce7f1;border-radius:12px;background:#f8fbfe}.ef-battery-step-head{display:flex;align-items:center;gap:10px;margin-bottom:12px}.ef-battery-step-head>i{display:grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border-radius:9px;background:#e9f8f0;color:#1b9a62}.ef-battery-step-head b,.ef-battery-step-head small{display:block}.ef-battery-step-head b{color:#294e70;font-size:.82rem}.ef-battery-step-head small{margin-top:2px;color:#718397;font-size:.65rem}.ef-battery-soc-grid label{padding:11px;border:1px solid #dbe6f0;border-radius:10px;background:#fff}.ef-battery-soc-grid input{font-size:.9rem;font-weight:750;color:#176c48}.ef-battery-setup>.ef-strategy-box{margin-top:0;padding:14px;background:#f8fbfe}.ef-battery-group-title{grid-column:1/-1;display:flex;align-items:center;gap:7px;margin-top:5px;padding:7px 9px;border-radius:8px;background:#edf4fb;color:#315b87;font-size:.69rem;font-weight:800}.ef-battery-group-title:first-child{margin-top:0}.ef-battery-device-section .ef-battery-picker{margin-top:0}.ef-battery-device-section>.ef-battery-config-panel{padding:11px;border-color:#d8e5f0;box-shadow:0 3px 10px rgba(31,55,89,.035)}.ef-battery-device-title{display:flex!important;align-items:center;gap:9px}.ef-battery-device-title>i{display:grid;place-items:center;flex:0 0 29px;width:29px;height:29px;border-radius:8px;background:#e9f8f0;color:#1b9a62}.ef-battery-device-title span{min-width:0}.ef-battery-device-title b,.ef-battery-device-title small{display:block}.ef-battery-device-title b{overflow:hidden;color:#294e70;font-size:.76rem;text-overflow:ellipsis;white-space:nowrap}.ef-battery-device-title small{margin-top:2px;color:#7c8fa2;font-size:.63rem;font-weight:650}
  .ef-battery-picker{margin-top:14px;padding:11px 12px;border:1px solid #d8e4ef;border-radius:11px;background:#f5f9fd}.ef-battery-picker label{display:flex;align-items:center;justify-content:space-between;gap:12px}.ef-battery-picker label>span{color:#49627e;font-size:.72rem;font-weight:700}.ef-battery-picker select{min-width:250px;min-height:38px;border:1px solid #bfd0e1;border-radius:8px;padding:6px 9px;background:#fff;color:#274865}.ef-battery-config-panel{margin-top:12px;padding:12px;border:1px solid #e1e8f0;border-radius:11px;background:#fff}.ef-battery-config-panel[hidden]{display:none}
  .ef-ref-summary{min-height:40px;display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 8px 7px 11px;border:1px solid #d4deea;border-radius:8px;background:#f8fafc}.ef-ref-summary b{overflow:hidden;color:#38536f;font-size:.73rem;font-weight:650;text-overflow:ellipsis;white-space:nowrap}.ef-ref-summary button{flex:0 0 29px;width:29px;height:29px;border:1px solid #c8d8e8;border-radius:7px;background:#fff;color:#1769d2;cursor:pointer}.ef-ref-summary button:hover{border-color:#1769d2;background:#eaf3ff}.ef-ref-panel{display:none;margin-top:6px;padding:8px;border:1px solid #c9daeb;border-radius:8px;background:#f4f8fd}.ef-ref-field.editing .ef-ref-panel{display:block}.ef-ref-field.editing .ef-ref-summary{border-color:#1769d2;box-shadow:0 0 0 2px rgba(23,105,210,.09)}.ef-ref-write{display:flex;gap:6px;margin-top:6px}.ef-ref-write input{flex:1}.ef-ref-write button{border:0;border-radius:7px;padding:6px 10px;background:#168958;color:#fff;font-size:.7rem;font-weight:700}.ef-ref-write button:disabled{opacity:.5}.ef-ref-write-status{display:block;min-height:16px;margin-top:4px;color:#617489;font-size:.65rem}.ef-ref-write-status.success{color:#168958}.ef-ref-write-status.error{color:#c53c3c}
  .ef-pmax-control{display:grid;grid-template-columns:1fr 115px auto;align-items:center;gap:9px;margin:6px 0 12px;padding:11px 12px;border:1px solid #bfe5d1;border-radius:10px;background:linear-gradient(135deg,#effaf5,#f8fffb)}.ef-pmax-control>div>b,.ef-pmax-control>div>small{display:block}.ef-pmax-control>div>b{color:#157a4d;font-size:.78rem}.ef-pmax-control>div>small{color:#698075;font-size:.66rem;margin-top:3px}.ef-pmax-control label{display:flex;align-items:center}.ef-pmax-control label input{width:82px;min-height:36px;border:1px solid #abd7bf;border-radius:8px 0 0 8px;padding:6px 8px}.ef-pmax-control label span{height:36px;display:grid;place-items:center;padding:0 7px;border:1px solid #abd7bf;border-left:0;border-radius:0 8px 8px 0;background:#fff;color:#587266;font-size:.7rem}.ef-pmax-control button{min-height:36px;border:0;border-radius:8px;padding:7px 11px;background:#168958;color:#fff;font-size:.72rem;font-weight:700}.ef-pmax-control button:disabled{opacity:.55}.ef-pmax-status{grid-column:1/-1;color:#617489;font-size:.67rem}.ef-pmax-status.success{color:#168958}.ef-pmax-status.error{color:#c53c3c}
  @media(max-width:900px){.ef-board{grid-template-columns:1fr 64px 130px 64px 1fr;padding:18px}.ef-node{padding:13px}.ef-icon{display:none}.ef-value{font-size:1.4rem}.ef-hub{width:120px;height:120px}}
  @media(max-width:900px){.ef-mqtt-summary{grid-template-columns:1fr 1fr}}
  @media(max-width:650px){.ef-action-report{padding:13px}.ef-action-report>.ef-panel-head{align-items:flex-start;flex-direction:column;gap:9px}.ef-action-report-tools{justify-content:space-between;width:100%}.ef-action-report-tools>.ef-log-date-picker{flex:1;min-width:170px}.ef-action-report-tools>.ef-log-date-picker>input{width:100%;min-width:0}.ef-action-report-summary{grid-template-columns:1fr 1fr}.ef-action-report-note{text-align:left}}
  @media(max-width:650px){.ef-action-report-summary{grid-template-columns:1fr}.ef-action-summary-total>strong{font-size:.96rem}.ef-action-summary-breakdown>button{min-height:58px}}
  @media(max-width:650px){.ef-wrap{padding:10px 4px 30px}.ef-head-actions{align-items:flex-end;flex-direction:column}.ef-settings-grid,.ef-ac-grid{grid-template-columns:1fr}.ef-ac-wide{grid-column:auto}.ef-settings-foot{align-items:flex-start;flex-direction:column}.ef-board{grid-template-columns:1fr 34px 94px 34px 1fr;min-height:350px;padding:12px 8px}.ef-node{padding:10px 8px;border-radius:11px}.ef-label{font-size:.62rem}.ef-value{font-size:1.1rem}.ef-battery-qmax{gap:3px;font-size:.58rem}.ef-battery-qmax strong{font-size:.66rem}.ef-battery-qmax small{font-size:.55rem}.ef-grid-reactive{display:block;font-size:.5rem}.ef-grid-reactive strong{display:block;margin-top:1px;font-size:.54rem}.ef-grid-reactive b{font-size:.64rem}.ef-meta{font-size:.62rem}.ef-node-config{right:3px;top:3px;width:23px;height:23px}.ef-hub{width:88px;height:88px}.ef-hub strong{font-size:.55rem}.ef-hub small{display:none}.ef-hub i{font-size:1.2rem;margin-bottom:3px}.ef-line::before{font-size:.52rem;top:-24px;padding:1px 4px}.ef-line::after{border-top-width:6px;border-bottom-width:6px;border-left-width:9px}.ef-line.reverse::after{border-right-width:9px}.ef-summary{grid-template-columns:1fr}.ef-mqtt-control,.ef-device-panel,.ef-history-panel{padding:13px}.ef-mqtt-summary{grid-template-columns:1fr 1fr}.ef-device-grid{grid-template-columns:1fr}.ef-history-controls{width:100%}.ef-history-controls label{flex:1}.ef-history-controls select,.ef-history-controls input{width:100%;min-width:0}.ef-history-stats>div{min-width:0;flex:1}}
  @media(max-width:650px){.ef-mqtt-control>.ef-panel-head{align-items:stretch;flex-direction:column;gap:10px;margin-bottom:12px}.ef-mqtt-control>.ef-panel-head>div:first-child{min-width:0}.ef-mqtt-control>.ef-panel-head h5{font-size:.88rem}.ef-mqtt-control>.ef-panel-head small{display:block;margin-top:3px;font-size:.65rem;line-height:1.4}.ef-log-head-tools{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:center;width:100%;gap:8px;padding-top:9px;border-top:1px solid #e8eef4}.ef-log-date-picker{width:100%;min-width:0}.ef-log-date-picker>input{width:100%;min-width:0}.ef-log-head-tools>#efMqttUpdated{grid-column:1/-1;justify-self:end;padding:4px 7px;border-radius:7px;background:#f2f6fa;white-space:nowrap;font-size:.62rem}}
  @media(max-width:380px){.ef-log-head-tools{grid-template-columns:1fr}.ef-log-head-tools>#efMqttUpdated{justify-self:start}.ef-mqtt-summary{grid-template-columns:1fr}}
  @media(max-width:650px){.ef-consumption-summary{align-items:stretch;flex-direction:column}.ef-consumption-summary-grid{grid-template-columns:1fr}.ef-consumption-summary-grid>div{display:flex;align-items:center;justify-content:space-between}.ef-consumption-summary-grid strong{margin-top:0}}
  @media(max-width:800px){.ef-energy-donut-body{grid-template-columns:1fr}.ef-energy-donut-chart{max-width:360px;margin:0 auto}.ef-energy-donut-values{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:650px){.ef-energy-donut-panel{padding:13px}.ef-energy-donut-head{align-items:stretch;flex-direction:column}.ef-energy-donut-head select{width:100%}.ef-energy-donut-body{gap:4px}.ef-energy-donut-values{grid-template-columns:1fr}.ef-energy-donut-values>div{grid-template-columns:12px minmax(0,1fr)}.ef-energy-donut-values strong{grid-column:2}.ef-energy-donut-chart{min-height:240px}}
  @media(max-width:650px){.ef-pmax-control{grid-template-columns:1fr auto}.ef-pmax-control>div{grid-column:1/-1}.ef-pmax-status{grid-column:1/-1}}
  @media(max-width:650px){.ef-battery-picker label{align-items:stretch;flex-direction:column}.ef-battery-picker select{width:100%;min-width:0}}
  @media(max-width:650px){
    .ef-device-panel{width:100%;max-width:100%;min-width:0;box-sizing:border-box;overflow:hidden}
    .ef-device-panel>.ef-panel-head{min-width:0;gap:8px}
    .ef-device-panel>.ef-panel-head>div{min-width:0}
    .ef-device-panel>.ef-panel-head>span{flex:none;max-width:42%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .ef-device-grid{width:100%;min-width:0;grid-template-columns:minmax(0,1fr)}
    .ef-device{display:grid;grid-template-columns:minmax(0,1fr) auto;width:100%;max-width:100%;min-width:0;box-sizing:border-box;padding:11px 42px 11px 11px;overflow:hidden}
    .ef-device>div{min-width:0;max-width:100%;overflow:hidden}
    .ef-device-name,.ef-device-sn{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .ef-device-power{min-width:0;max-width:118px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .ef-device-power small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  }
  @media(max-width:380px){
    .ef-device{grid-template-columns:minmax(0,1fr);gap:7px}
    .ef-device-power{max-width:100%;text-align:left}
  }

  /* Responsive Energy page header */
  .ef-head{display:grid;grid-template-columns:minmax(240px,1fr) auto;align-items:center;gap:14px 22px;width:100%;margin-bottom:18px}
  .ef-head>div:first-child{min-width:0}.ef-head .ef-title{overflow-wrap:anywhere}.ef-head .ef-sub{margin-top:3px;line-height:1.4}
  .ef-head-actions{display:flex;align-items:center;justify-content:flex-end;gap:9px;min-width:0;max-width:100%}
  .ef-head-actions>*{box-sizing:border-box;min-height:36px}.ef-head-actions .ef-updated{display:flex;align-items:center;min-width:0;padding:6px 9px;border-radius:9px;background:#f5f7fa;line-height:1.25;white-space:nowrap}
  .ef-head-actions .ef-automation-btn,.ef-head-actions .ef-settings-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:36px;line-height:1.2;white-space:nowrap}
  @media(max-width:1120px){.ef-head{grid-template-columns:minmax(210px,.7fr) minmax(430px,1.3fr)}.ef-head-actions{flex-wrap:wrap}.ef-head-actions .ef-updated{flex:1 1 100%;justify-content:flex-end}.ef-head-actions .ef-automation-btn,.ef-head-actions .ef-settings-btn{flex:1 1 auto}}
  @media(max-width:760px){.ef-head{grid-template-columns:minmax(0,1fr);align-items:stretch;gap:12px;margin-bottom:14px;padding:12px;border:1px solid #e1e8f0;border-radius:13px;background:#fff;box-shadow:0 6px 18px rgba(31,55,89,.05)}.ef-head-actions{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);width:100%;gap:8px}.ef-head-actions .ef-updated{grid-column:1/-1;justify-content:flex-start;width:100%;white-space:normal}.ef-head-actions .ef-automation-btn,.ef-head-actions .ef-settings-btn{width:100%;min-width:0;white-space:normal;text-align:center}.ef-head-actions .ef-automation-btn span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}}
  @media(max-width:460px){.ef-head{padding:11px}.ef-head-actions{grid-template-columns:minmax(0,1fr)}.ef-head-actions .ef-updated{grid-column:auto}.ef-head-actions .ef-automation-btn,.ef-head-actions .ef-settings-btn{justify-content:flex-start;padding:9px 11px;text-align:left}.ef-head-actions .ef-automation-btn span{white-space:normal}.ef-head .ef-title{font-size:1rem}.ef-head .ef-sub{font-size:.72rem}}

  /* Responsive Active Power / Energy Summary header */
  .ef-history-head{display:grid;grid-template-columns:minmax(210px,.7fr) minmax(520px,1.3fr);align-items:end;gap:14px 20px;padding-bottom:13px;border-bottom:1px solid #e8eef4}
  .ef-history-head>div:first-child{min-width:0}.ef-history-head h5{display:flex;align-items:center;min-width:0;line-height:1.3}.ef-history-head h5 span{min-width:0;overflow-wrap:anywhere}.ef-history-head>div:first-child>small{display:block;margin-top:4px;line-height:1.4}
  .ef-history-controls{display:grid;grid-template-columns:repeat(4,minmax(110px,150px));align-items:end;justify-content:end;justify-self:end;gap:8px;box-sizing:border-box;width:fit-content;max-width:100%;min-width:0;padding:0;border:0;background:transparent}
  .ef-history-controls label{display:block;box-sizing:border-box;width:100%;min-width:0;max-width:150px;margin:0;overflow:hidden}.ef-history-controls label>span{display:block;overflow:hidden;color:#60758b;text-overflow:ellipsis;white-space:nowrap}.ef-history-controls select,.ef-history-controls input{display:block;box-sizing:border-box;inline-size:100%;width:100%;min-inline-size:0;min-width:0;max-width:100%;height:38px;min-height:38px;padding:6px 28px 6px 8px;overflow:hidden;border-color:#cfdbe7;background-color:#fff;text-overflow:ellipsis;white-space:nowrap}.ef-history-controls input{padding-right:7px}.ef-history-controls input[type="date"],.ef-history-controls input[type="month"],.ef-history-controls input[type="number"]{-webkit-appearance:none;appearance:none;min-inline-size:0}
  .ef-history-controls input[type="date"],.ef-history-controls input[type="month"]{padding:6px 36px 6px 10px;border:1px solid #cfdbe7;border-radius:7px;background-color:#fff;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='%233976b4' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='17' rx='2'/%3E%3Cline x1='16' y1='2' x2='16' y2='6'/%3E%3Cline x1='8' y1='2' x2='8' y2='6'/%3E%3Cline x1='3' y1='10' x2='21' y2='10'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;background-size:17px;color:#38536f;font-family:inherit;font-weight:600;cursor:pointer}.ef-history-controls input[type="date"]::-webkit-calendar-picker-indicator,.ef-history-controls input[type="month"]::-webkit-calendar-picker-indicator{width:24px;height:24px;margin-right:-29px;opacity:0;cursor:pointer}.ef-history-controls input[type="date"]:hover,.ef-history-controls input[type="month"]:hover{border-color:#8eb4dc;background-color:#fbfdff}.ef-history-controls input[type="date"]:focus,.ef-history-controls input[type="month"]:focus{outline:0;border-color:#1769d2;box-shadow:0 0 0 3px rgba(23,105,210,.12)}
  .ef-history-date-picker{position:relative;display:grid;grid-template-columns:minmax(0,1fr) 38px;width:100%;min-width:0}.ef-history-date-picker>#efHistoryDate{height:38px;min-height:38px;padding:6px 9px;border:1px solid #cfdbe7;border-right:0;border-radius:7px 0 0 7px;background:#fff;color:#38536f;font-size:.75rem;font-weight:650;cursor:pointer}.ef-history-date-picker>#efHistoryDateButton{display:grid;place-items:center;width:38px;height:38px;padding:0;border:1px solid #cfdbe7;border-radius:0 7px 7px 0;background:#f5f9fd;color:#3976b4;cursor:pointer}.ef-history-date-picker>#efHistoryDateButton:hover{background:#e8f2fd;color:#1769d2}.ef-history-calendar{position:absolute;z-index:50;right:0;top:calc(100% + 7px);width:286px;box-sizing:border-box;padding:12px;border:1px solid #d7e2ed;border-radius:12px;background:#fff;box-shadow:0 15px 38px rgba(25,48,73,.22)}.ef-history-calendar[hidden]{display:none}.ef-history-calendar-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:9px}.ef-history-calendar-head>button{display:grid;place-items:center;width:30px;height:30px;padding:0;border:1px solid #d6e1ec;border-radius:8px;background:#fff;color:#3976b4;font-size:1rem;cursor:pointer}.ef-history-calendar-head>strong{color:#294e70;font-size:.8rem}.ef-history-calendar-week,.ef-history-calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px}.ef-history-calendar-week span{text-align:center;color:#8494a5;font-size:.59rem;font-weight:750}.ef-history-calendar-grid button{position:relative;min-width:0;min-height:34px;padding:0;border:1px solid transparent;border-radius:7px;background:transparent;color:#405a72;font-size:.67rem;cursor:pointer}.ef-history-calendar-grid button:hover:not(:disabled){border-color:#76a9df;background:#f3f8fe}.ef-history-calendar-grid button.outside{opacity:.35}.ef-history-calendar-grid button.today{box-shadow:inset 0 0 0 1px #8fb6de}.ef-history-calendar-grid button.selected{border-color:#25a56a;background:#eaf8f1;color:#176c48;font-weight:800}.ef-history-calendar-grid button:disabled{color:#c3cbd3;cursor:not-allowed}.ef-history-calendar-grid button.selected::after{content:'';position:absolute;left:50%;bottom:3px;width:4px;height:4px;border-radius:50%;background:#25a56a;transform:translateX(-50%)}
  .ef-history-panel{overflow:visible}.ef-history-control-field{display:block;box-sizing:border-box;width:100%;min-width:0;max-width:150px;margin:0;overflow:visible}.ef-history-control-field>span{display:block;margin-bottom:3px;overflow:hidden;color:#60758b;font-size:.64rem;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.ef-history-date-picker{z-index:60}.ef-history-calendar,.ef-history-calendar *{pointer-events:auto}
  .ef-history-stats{display:flex;flex-wrap:nowrap;overflow-x:auto;overflow-y:hidden;gap:9px;padding:10px 10px 12px;scroll-snap-type:x proximity;scrollbar-width:thin;scrollbar-color:#a8bdd3 #eef3f7;-webkit-overflow-scrolling:touch}.ef-history-stats::-webkit-scrollbar{height:7px}.ef-history-stats::-webkit-scrollbar-track{border-radius:8px;background:#eef3f7}.ef-history-stats::-webkit-scrollbar-thumb{border-radius:8px;background:#a8bdd3}.ef-history-stats>div{flex:0 0 clamp(160px,22vw,220px);width:auto;min-width:0;scroll-snap-align:start}
  @media(max-width:1150px){.ef-history-head{grid-template-columns:minmax(0,1fr);align-items:stretch}.ef-history-controls{grid-template-columns:repeat(4,minmax(0,1fr));justify-content:stretch;justify-self:stretch;width:100%}.ef-history-controls label{max-width:none}}
  @media(max-width:760px){.ef-history-head{min-width:0;gap:12px;padding:0 0 12px}.ef-history-controls{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;width:100%;max-width:100%;padding:0;overflow:visible}.ef-history-controls label,.ef-history-control-field{width:100%;min-width:0;max-width:none}.ef-history-controls select,.ef-history-controls input{width:100%;min-width:0;max-width:100%;height:40px;min-height:40px;font-size:16px}.ef-history-stats{margin-right:-13px;padding-right:13px}.ef-history-stats>div{flex-basis:175px}}
  @media(max-width:460px){.ef-history-controls{grid-template-columns:repeat(2,minmax(0,1fr));width:100%;gap:6px;padding:0}.ef-history-controls label>span,.ef-history-control-field>span{margin-bottom:2px;font-size:.56rem}.ef-history-controls select,.ef-history-controls input{height:34px;min-height:34px;padding:4px 23px 4px 7px;border-radius:6px;font-size:.72rem}.ef-history-controls input{padding-right:6px}.ef-history-date-picker{grid-template-columns:minmax(0,1fr) 34px}.ef-history-date-picker>#efHistoryDate{height:34px;min-height:34px;padding:4px 6px;font-size:.7rem}.ef-history-date-picker>#efHistoryDateButton{width:34px;height:34px}.ef-history-head h5{font-size:.9rem}.ef-history-head>div:first-child>small{font-size:.65rem}.ef-history-stats>div{flex-basis:82vw;max-width:230px}.ef-history-chart{min-height:280px;padding:4px}}
  @media(max-width:460px){.ef-history-calendar{position:fixed;left:12px;right:12px;top:50%;width:auto;max-width:340px;margin:auto;transform:translateY(-50%)}}

  /* Energy dashboard dark theme */
  html[data-gmode=dark] .ef-wrap{--ink:#e5edf7;--muted:#91a4ba;--line:#2a3b52;--blue:#d68ac7;color:var(--ink)}
  html[data-gmode=dark] .ef-title,html[data-gmode=dark] .ef-panel-head h5{color:#f2f6fb}
  html[data-gmode=dark] .ef-board{background:radial-gradient(circle at center,rgba(43,123,218,.13),transparent 42%),#0e1928;border-color:#293b52;box-shadow:0 12px 34px rgba(0,0,0,.28)}
  html[data-gmode=dark] .ef-node,html[data-gmode=dark] .ef-summary>div,html[data-gmode=dark] .ef-settings,html[data-gmode=dark] .ef-mqtt-control,html[data-gmode=dark] .ef-device-panel,html[data-gmode=dark] .ef-history-panel,html[data-gmode=dark] .ef-energy-donut-panel{background:#142235;border-color:#2a3c54;color:#e5edf7;box-shadow:0 8px 24px rgba(0,0,0,.22)}
  html[data-gmode=dark] .ef-mqtt-summary>div,html[data-gmode=dark] .ef-history-stats,html[data-gmode=dark] .ef-history-stats>div,html[data-gmode=dark] .ef-energy-donut-values>div,html[data-gmode=dark] .ef-action-summary-group,html[data-gmode=dark] .ef-action-summary-total,html[data-gmode=dark] .ef-action-summary-breakdown>button,html[data-gmode=dark] .ef-strategy-box,html[data-gmode=dark] .ef-solar-step,html[data-gmode=dark] .ef-battery-step,html[data-gmode=dark] .ef-battery-picker,html[data-gmode=dark] .ef-battery-config-panel,html[data-gmode=dark] .ef-ref-summary,html[data-gmode=dark] .ef-ref-panel,html[data-gmode=dark] .ef-tou-calendar,html[data-gmode=dark] .ef-tou-day,html[data-gmode=dark] .ef-strategy-toggle,html[data-gmode=dark] .ef-ac-check{background:#18283d;border-color:#30445e;color:#dce7f4}
  html[data-gmode=dark] .ef-history-chart{background:#101c2c;border-color:#2a3d55}
  html[data-gmode=dark] .ef-history-chart.is-loading::before{background:rgba(16,28,44,.86)}
  html[data-gmode=dark] .ef-mqtt-table-wrap{border-color:#2b3e56}html[data-gmode=dark] .ef-mqtt-table th{background:#1d3048;color:#c6d5e6}html[data-gmode=dark] .ef-mqtt-table td{border-top-color:#293b52;color:#d5e1ee}
  html[data-gmode=dark] .ef-device{border-color:#2b3e56;background:#16263a;color:#dce7f4}html[data-gmode=dark] .ef-device.offline{background:#111d2c}
  html[data-gmode=dark] .ef-svg-track{stroke:#31445d}html[data-gmode=dark] .ef-line::before{background:#17283d;border-color:currentColor;color:currentColor;box-shadow:0 4px 12px rgba(0,0,0,.28)}html[data-gmode=dark] .ef-line.idle::before{background:#172536;color:#9aacbf;border-color:#45566a}
  html[data-gmode=dark] .ef-settings-btn,html[data-gmode=dark] .ef-btn-reset,html[data-gmode=dark] .ef-log-pagination button,html[data-gmode=dark] .ef-log-calendar-head>button,html[data-gmode=dark] .ef-history-calendar-head>button,html[data-gmode=dark] .ef-tou-nav,html[data-gmode=dark] .ef-ref-summary button{background:#1c3049;border-color:#3a516d;color:#9bc8ff}
  html[data-gmode=dark] .ef-settings-btn:hover,html[data-gmode=dark] .ef-log-pagination button:hover:not(:disabled),html[data-gmode=dark] .ef-history-calendar-head>button:hover,html[data-gmode=dark] .ef-tou-nav:hover{background:#274463;border-color:#67a7ec;color:#fff}
  html[data-gmode=dark] .ef-wrap input,html[data-gmode=dark] .ef-wrap select,html[data-gmode=dark] .ef-wrap textarea{background-color:#101d2d!important;border-color:#3a506b!important;color:#e2ebf5!important;color-scheme:dark}
  html[data-gmode=dark] .ef-wrap input::placeholder,html[data-gmode=dark] .ef-wrap textarea::placeholder{color:#7f93aa}
  html[data-gmode=dark] .ef-wrap input:focus,html[data-gmode=dark] .ef-wrap select:focus,html[data-gmode=dark] .ef-wrap textarea:focus{border-color:#62a6ef!important;box-shadow:0 0 0 3px rgba(98,166,239,.14)!important}
  html[data-gmode=dark] .ef-history-calendar,html[data-gmode=dark] .ef-log-calendar{background:#142235;border-color:#344a65;box-shadow:0 16px 40px rgba(0,0,0,.42)}
  html[data-gmode=dark] .ef-history-calendar-head>strong,html[data-gmode=dark] .ef-log-calendar-head strong,html[data-gmode=dark] .ef-tou-head strong{color:#e5edf7}
  html[data-gmode=dark] .ef-history-calendar-grid button,html[data-gmode=dark] .ef-log-calendar-grid button{color:#b7c7d9}
  html[data-gmode=dark] .ef-history-calendar-grid button:hover:not(:disabled),html[data-gmode=dark] .ef-log-calendar-grid button.has-data{background:#213b55;border-color:#4f8dc9;color:#e8f4ff}
  html[data-gmode=dark] .ef-history-calendar-grid button.selected,html[data-gmode=dark] .ef-log-calendar-grid button.selected,html[data-gmode=dark] .ef-tou-day.selected{background:#174836;border-color:#38a875;color:#c8f7e1}
  html[data-gmode=dark] .ef-history-calendar-grid button:disabled{color:#536477}
  html[data-gmode=dark] .ef-consumption-summary{background:linear-gradient(135deg,#15283e,#122238);border-color:#2d4866}html[data-gmode=dark] .ef-consumption-summary-title span{color:#9bc8ff}html[data-gmode=dark] .ef-consumption-summary-grid>div{background:#182b42;border-color:#304a67}html[data-gmode=dark] .ef-consumption-summary-grid strong{color:#dcecff}
  html[data-gmode=dark] .ef-solar-summary{background:linear-gradient(135deg,#292719,#211f18);border-color:#5a4b25}html[data-gmode=dark] .ef-solar-summary .ef-consumption-summary-title span,html[data-gmode=dark] .ef-solar-summary .ef-consumption-summary-grid strong{color:#ffd27a}html[data-gmode=dark] .ef-solar-summary .ef-consumption-summary-grid>div{background:#27251b;border-color:#594a28}
  html[data-gmode=dark] .ef-energy-donut-head strong,html[data-gmode=dark] .ef-energy-donut-values strong{color:#dcecff}html[data-gmode=dark] .ef-energy-donut-values span{color:#aebfd1}
  html[data-gmode=dark] .ef-reason-line{color:#c7d5e4}html[data-gmode=dark] .ef-reason-reference{background:#1b3048;color:#aebfd1;border-left-color:#5d9ddd}
  html[data-gmode=dark] .ef-pmax-control{background:linear-gradient(135deg,#153226,#142a23);border-color:#2e694d}html[data-gmode=dark] .ef-pmax-control>div>b{color:#78d9a9}html[data-gmode=dark] .ef-pmax-control>div>small{color:#9bb8aa}
  html[data-gmode=dark] .ef-solar-step-title b,html[data-gmode=dark] .ef-solar-device-head b,html[data-gmode=dark] .ef-solar-device-heading b,html[data-gmode=dark] .ef-battery-step-head b,html[data-gmode=dark] .ef-battery-device-title b,html[data-gmode=dark] .ef-ref-summary b,html[data-gmode=dark] .ef-toggle-copy b,html[data-gmode=dark] .ef-ac-check>span b{color:#dce7f4}
  html[data-gmode=dark] .ef-head-actions .ef-updated{background:#17263a;color:#9fb1c5}
  @media(max-width:760px){html[data-gmode=dark] .ef-head{background:#142235;border-color:#2a3c54;box-shadow:0 6px 20px rgba(0,0,0,.22)}}
</style>

<script src="./assets/libs/apexcharts/dist/apexcharts.min.js"></script>
<script>
(function(){
  const efChartDark = () => document.documentElement.getAttribute('data-gmode') === 'dark';
  const efChartText = () => efChartDark() ? '#b9c8d9' : '#52677d';
  const efChartGrid = () => efChartDark() ? '#2b3d54' : '#e8edf3';
  const devices = <?php echo ($dvl_txt !== '' ? $dvl_txt : '{}'); ?>;
  const modelDetails = <?php echo (isset($detail_rgl_txt) && $detail_rgl_txt !== '' ? $detail_rgl_txt : '{}'); ?>;
  const registerMaps = <?php echo ($rgl_txt !== '' ? $rgl_txt : '{}'); ?>;
  const parameterList = <?php echo json_encode($efParameterMap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  const canEditAutomation = <?php echo $efCanEditAutomation ? 'true' : 'false'; ?>;
  let automationConfig = <?php echo json_encode($efAutomationConfig,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  const parameterFullName = code => {
    const meta = parameterList[code] || {};
    return String(meta.full_name || meta.display_name || code);
  };
  document.getElementById('efBatteryQMaxRow').title = parameterFullName('q_max');
  document.getElementById('efGridPowerFactor').title = parameterFullName('pf_t');
  const touHolidayDates = <?php echo json_encode($efTouHolidayDates,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  const OFFLINE_SEC = 180;
  const SETTINGS_KEY = 'mdbiot_energy_diagram_settings_v1';
  const meterIds = Object.keys(devices).filter(id => {
    const model = devices[id] ? devices[id].model : '';
    return model && modelDetails[model] && modelDetails[model].type === 'meter';
  });
  const defaults = {mainMeter:meterIds[0] || '',energyMode:'grid',meterSign:'import',batterySign:'discharge'};
  // ค่ากลางจาก Template Config; ค่าที่ผู้ใช้บันทึกไว้ใน browser ยังมีลำดับความสำคัญสูงกว่า
  const siteDefaults = <?php $efSite = json_decode((string)@file_get_contents(__DIR__ . '/config/dashboard_template.json'), true); echo json_encode(is_array($efSite['settings']['energy_diagram'] ?? null) ? $efSite['settings']['energy_diagram'] : new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>;
  if (siteDefaults.main_meter && devices[siteDefaults.main_meter]) defaults.mainMeter = siteDefaults.main_meter;
  if (siteDefaults.energy_mode) defaults.energyMode = siteDefaults.energy_mode;
  if (siteDefaults.meter_sign) defaults.meterSign = siteDefaults.meter_sign;
  if (siteDefaults.battery_sign) defaults.batterySign = siteDefaults.battery_sign;
  let settings = loadSettings();
  let lastPayload = null;
  let loading = false;
  let automationStatusLoading=false;
  function loadAutomationStatus(){if(automationStatusLoading)return;automationStatusLoading=true;fetch('./automation_status_api.php',{cache:'no-store'}).then(async response=>{const data=await response.json();if(!response.ok||data.error)throw Error(data.error||('HTTP '+response.status));const el=document.getElementById('efAutomationStatus');el.className='ef-automation-btn '+(data.active?'is-active':(data.enabled?'is-stopped':'is-disabled'));el.querySelector('span').textContent=data.active?'Automation Config · กำลังทำงาน':(data.enabled?'Automation Config · Service หยุด':'Automation Config · ปิดอยู่');el.title=data.active?'ระบบ Automation กำลังทำงาน · คลิกเพื่อเปิด Config':(data.enabled?'เปิด Automation ไว้ แต่ py_multi.service ไม่ทำงาน':'Automation ปิดอยู่ · คลิกเพื่อเปิด Config');}).catch(()=>{const el=document.getElementById('efAutomationStatus');el.className='ef-automation-btn is-stopped';el.querySelector('span').textContent='Automation Config · ไม่ทราบสถานะ';}).finally(()=>automationStatusLoading=false);}
  let historyChart = null;

  function loadSettings(){
    try {
      const saved = JSON.parse(localStorage.getItem(SETTINGS_KEY) || '{}');
      return Object.assign({},defaults,saved);
    } catch(e) {
      return Object.assign({},defaults);
    }
  }
  function validSettings(value){
    const result = Object.assign({},defaults,value || {});
    if (!devices[result.mainMeter]) result.mainMeter = defaults.mainMeter;
    if (!['grid','load'].includes(result.energyMode)) result.energyMode = defaults.energyMode;
    if (!['import','export'].includes(result.meterSign)) result.meterSign = defaults.meterSign;
    if (!['discharge','charge'].includes(result.batterySign)) result.batterySign = defaults.batterySign;
    return result;
  }
  settings = validSettings(settings);

  const typeOf = id => {
    const model = devices[id] ? devices[id].model : '';
    return model && modelDetails[model] ? modelDetails[model].type : 'unknown';
  };
  const nameOf = id => devices[id] && devices[id].name ? devices[id].name : id;
  const number = value => value !== '' && value !== null && value !== undefined && Number.isFinite(Number(value)) ? Number(value) : null;
  const fmt = value => value === null ? '—' : Math.abs(value).toLocaleString(undefined,{minimumFractionDigits:1,maximumFractionDigits:2});
  const esc = value => String(value).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const offline = reading => {
    if (!reading) return true;
    const ts = number(reading.dt);
    const hasError = reading.error !== null && reading.error !== undefined && reading.error !== '' && String(reading.error) !== '0';
    return ts === null || ts <= 0 || Math.floor(Date.now()/1000)-ts > OFFLINE_SEC || hasError;
  };
  const setFlow = (id, value, reverseWhenNegative, forwardLabel, reverseLabel, idleLabel='Standby') => {
    const el = document.getElementById(id);
    const idle = value === null || Math.abs(value) < .01;
    const reverse = !!reverseWhenNegative && value !== null && value < 0;
    el.classList.toggle('idle', idle);
    el.classList.toggle('reverse', reverse);
    el.dataset.flow = idle ? idleLabel : (reverse ? reverseLabel : forwardLabel);
    const path = document.getElementById(id+'Path');
    if(path){
      path.classList.toggle('idle',idle);
      path.classList.toggle('reverse',reverse);
    }
    document.querySelectorAll('[data-flow-parent="'+id+'"]').forEach(layer=>{
      layer.classList.toggle('idle',idle);
      layer.classList.toggle('reverse',reverse);
    });
  };
  const setOffline = (id, state) => document.getElementById(id).classList.toggle('offline',state);

  const acModal=document.getElementById('efAcModal');
  const acBody=document.getElementById('efAcBody');
  const acTitle=document.getElementById('efAcTitle');
  const acSubtitle=document.getElementById('efAcSubtitle');
  const acStatus=document.getElementById('efAcStatus');
  let activeAcEditor=null;
  let selectedBatteryAutomationId='';
  const acNumber=(value,fallback=0)=>Number.isFinite(Number(value))?Number(value):fallback;
  const acEstimatedEnd=(start,duration)=>{
    const match=String(start||'').match(/^(\d{2}):(\d{2})$/),hours=Number(duration);
    if(!match||!Number.isFinite(hours)||hours<=0)return null;
    const startMinutes=Number(match[1])*60+Number(match[2]);
    if(startMinutes<0||startMinutes>=1440)return null;
    const total=startMinutes+Math.round(hours*60),dayOffset=Math.floor(total/1440),clock=total%1440;
    return {time:String(Math.floor(clock/60)).padStart(2,'0')+':'+String(clock%60).padStart(2,'0'),dayOffset};
  };
  const acDurationBetween=(start,end)=>{
    const startMatch=String(start||'').match(/^(\d{2}):(\d{2})$/),endMatch=String(end||'').match(/^(\d{2}):(\d{2})$/);
    if(!startMatch||!endMatch)return null;
    const startMinutes=Number(startMatch[1])*60+Number(startMatch[2]),endMinutes=Number(endMatch[1])*60+Number(endMatch[2]);
    if(startMinutes>=1440||endMinutes>=1440)return null;
    return ((endMinutes-startMinutes+1440)%1440||1440)/60;
  };
  function renderEfOffPeakDuration(){
    const output=document.getElementById('efAcOffPeakEstimate'),duration=document.getElementById('efAcOffDuration');
    if(!output||!duration)return;
    const value=Number(duration.value);
    output.innerHTML='<i class="fa-solid fa-clock"></i> '+(Number.isFinite(value)&&value>0?'Duration <b>'+Number(value.toFixed(2))+' ชั่วโมง</b>':'Duration —');
  }
  function updateEfOffPeakEndFromDuration(){
    const start=document.getElementById('efAcOffStart'),end=document.getElementById('efAcOffEnd'),duration=document.getElementById('efAcOffDuration');
    if(!start||!end||!duration)return;
    const estimate=acEstimatedEnd(start.value,duration.value);
    if(estimate)end.value=estimate.time;
    renderEfOffPeakDuration();
  }
  function updateEfOffPeakDurationFromEnd(){
    const start=document.getElementById('efAcOffStart'),end=document.getElementById('efAcOffEnd'),duration=document.getElementById('efAcOffDuration');
    if(!start||!end||!duration)return;
    const value=acDurationBetween(start.value,end.value);
    if(value!==null)duration.value=Number(value.toFixed(4));
    renderEfOffPeakDuration();
  }
  function renderEfDischargeDuration(){
    const output=document.getElementById('efAcDischargeEstimate'),duration=document.getElementById('efAcDischargeDuration');
    if(!output||!duration)return;
    const value=Number(duration.value);
    output.innerHTML='<i class="fa-solid fa-clock"></i> '+(Number.isFinite(value)&&value>0?'Duration <b>'+Number(value.toFixed(2))+' ชั่วโมง</b>':'Duration —');
  }
  function updateEfDischargeEndFromDuration(){
    const start=document.getElementById('efAcDischargeStart'),end=document.getElementById('efAcDischargeEnd'),duration=document.getElementById('efAcDischargeDuration');
    if(!start||!end||!duration)return;
    const estimate=acEstimatedEnd(start.value,duration.value);
    if(estimate)end.value=estimate.time;
    renderEfDischargeDuration();
  }
  function updateEfDischargeDurationFromEnd(){
    const start=document.getElementById('efAcDischargeStart'),end=document.getElementById('efAcDischargeEnd'),duration=document.getElementById('efAcDischargeDuration');
    if(!start||!end||!duration)return;
    const value=acDurationBetween(start.value,end.value);
    if(value!==null)duration.value=Number(value.toFixed(4));
    renderEfDischargeDuration();
  }
  const acChecked=value=>value?' checked':'';
  const batteryAutomationInfo={
    efAcSocMin:['Min SoC','ค่าต่ำสุดของแบตเตอรี่ เมื่อ SoC ถึงค่านี้ Automation จะไม่อนุญาตให้จ่ายพลังงานออกเพิ่ม'],
    efAcSocMax:['Max SoC','ค่าสูงสุดของแบตเตอรี่ เมื่อ SoC ถึงค่านี้ Automation จะหยุดเก็บพลังงานเข้าแบตเตอรี่'],
    efAcCapacity:['Battery Capacity','ความจุพลังงานรวมของแบตเตอรี่ หน่วย kWh ใช้คำนวณกำลัง Charge และ Discharge ตามเวลาที่เหลือ'],
    efAcStrategyMaxCharge:['Maximum Charge','เพดานกำลังเก็บพลังงานเข้าแบตเตอรี่ของ Strategy หน่วย kW และยังถูกจำกัดด้วย Min kW ของอุปกรณ์'],
    efAcOffStart:['Off-Peak Start','เวลาเริ่มเก็บพลังงานเข้าแบตเตอรี่ในช่วง Off-Peak รูปแบบ 24 ชั่วโมง'],
    efAcOffEnd:['Off-Peak End','เวลาสิ้นสุดช่วง Off-Peak รูปแบบ 24 ชั่วโมง ระบบจะคำนวณ Duration และบันทึกด้วยตัวแปรเดิมโดยอัตโนมัติ'],
    efAcChargeStep:['Charge Step','ขนาดกำลังที่เพิ่มในการเก็บพลังงานเข้าแต่ละรอบ เพื่อให้การปรับไม่กระชาก หน่วย kW'],
    efAcDischargeStep:['Discharge Step','ขนาดกำลังที่เพิ่มในการจ่ายพลังงานออกแต่ละรอบ หน่วย kW'],
    efAcOnStart:['On-Peak Start','เวลาเริ่มช่วง On-Peak ที่ใช้เงื่อนไขควบคุมโหลดและ Peak Shaving'],
    efAcOnEnd:['On-Peak End','เวลาสิ้นสุดช่วง On-Peak รูปแบบ 24 ชั่วโมง'],
    efAcMinL:['Maintain Main Power From','ใช้ทุกช่วงเวลา หาก Main Meter ต่ำกว่าค่านี้ ระบบจะเพิ่มกำลังชาร์จเพื่อประคองกำลังจาก Main'],
    efAcMinH:['Stop Battery Charging At','ใช้ทุกช่วงเวลา เมื่อ Main Meter ถึงค่านี้ ระบบจะหยุดชาร์จแบตเตอรี่'],
    efAcMaxL:['Peak Minimum','เมื่อ Main ต่ำกว่าค่านี้ ระบบจะค่อย ๆ ลดกำลัง Discharge'],
    efAcMaxH:['Peak Maximum','เมื่อ Main เกินค่านี้ ระบบจะเพิ่ม Discharge เพื่อลด Peak ลงสู่ค่า Average ที่คำนวณอัตโนมัติ'],
    efAcPeakReserveSoc:['Peak Reserve SoC','หาก SoC ต่ำกว่าค่านี้ ระบบจะไม่ล็อกกำลัง Discharge ใน Deadband และจะจ่ายไฟเฉพาะเมื่อ Main เกิน Peak Maximum'],
    efAcDischargeStart:['Scheduled Discharge Start','เวลาเริ่มจ่ายพลังงานออกตามแผน เพื่อใช้พลังงานสำรองก่อนสิ้นสุดช่วง On-Peak'],
    efAcDischargeEnd:['Scheduled Discharge End','เวลาสิ้นสุดการจ่ายพลังงานตามแผน รูปแบบ 24 ชั่วโมง ระบบจะคำนวณ Duration และบันทึกด้วยตัวแปรเดิมโดยอัตโนมัติ'],
    efAcRole:['Role','กำหนดหน้าที่ของอุปกรณ์ ต้องเลือก Battery เพื่อใช้เงื่อนไข SoC และ Charge/Discharge'],
    efAcPriority:['Priority','ลำดับการควบคุม ค่าน้อยจะถูกพิจารณาก่อนเมื่อมีอุปกรณ์หลายเครื่อง'],
    efAcMinKw:['Min kW','ขอบเขตต่ำสุดของ p_max ฝั่งเก็บพลังงานเข้า ค่าติดลบหมายถึง Charge'],
    efAcMaxKw:['Max kW','ขอบเขตสูงสุดของ p_max ฝั่งจ่ายพลังงานออก ค่าบวกหมายถึง Discharge']
  };
  function addBatteryAutomationInfo(root){
    Object.entries(batteryAutomationInfo).forEach(([id])=>{
      root.querySelectorAll('[id="'+id+'"]').forEach(input=>{
        let title=input.closest('label')?.querySelector(':scope > span');
        if(!title||title.querySelector('[data-ac-info]')) return;
        title.insertAdjacentHTML('beforeend',' <button type="button" class="ef-ac-info" data-ac-info="'+id+'" data-ac-info-text="'+esc(batteryAutomationInfo[id][1])+'" aria-label="ข้อมูล '+esc(batteryAutomationInfo[id][0])+'"><i class="fa-solid fa-circle-info"></i></button>');
      });
    });
    const touTitle=root.querySelector('#efTouCalendar')?.previousElementSibling;
    if(touTitle&&!touTitle.querySelector('[data-ac-info]')) touTitle.insertAdjacentHTML('beforeend',' <button type="button" class="ef-ac-info" data-ac-info="tou" data-ac-info-text="ใช้กำหนดวัน On-Peak และวันหยุด เพื่อนำไปใช้ร่วมกับเงื่อนไขควบคุม Battery" aria-label="ข้อมูล TOU Schedule"><i class="fa-solid fa-circle-info"></i></button>');
    root.querySelectorAll('.ef-ac-info').forEach(button=>{
      const placeTooltip=()=>{
        const dialog=button.closest('.ef-ac-dialog');
        if(!dialog) return;
        const buttonRect=button.getBoundingClientRect();
        const dialogRect=dialog.getBoundingClientRect();
        const tooltipWidth=Math.min(260,Math.max(0,dialogRect.width-36));
        const center=buttonRect.left+(buttonRect.width/2);
        button.classList.toggle('tip-left',center-(tooltipWidth/2)<dialogRect.left+12);
        button.classList.toggle('tip-right',center+(tooltipWidth/2)>dialogRect.right-12);
        button.classList.toggle('tip-below',buttonRect.top-dialogRect.top<100);
      };
      button.addEventListener('mouseenter',placeTooltip);
      button.addEventListener('focus',placeTooltip);
    });
  }
  function toggleEditor(id,checked,title,help,disabled=''){
    return '<div class="ef-strategy-toggle ef-ac-wide"><label class="ef-toggle-control"><input id="'+esc(id)+'" type="checkbox"'+acChecked(checked)+disabled+'><span class="ef-toggle-track"><i></i></span></label><span class="ef-toggle-copy"><b>'+esc(title)+'</b><small>'+esc(help)+'</small></span><strong class="ef-toggle-state"></strong></div>';
  }
  function acTimeOptions(selected){
    const values=[];
    for(let hour=0;hour<24;hour++){
      for(let minute=0;minute<60;minute+=5){
        values.push(String(hour).padStart(2,'0')+':'+String(minute).padStart(2,'0'));
      }
    }
    if(/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(selected||'')&&!values.includes(selected)) values.push(selected);
    values.sort();
    return values.map(value=>'<option value="'+value+'"'+(value===selected?' selected':'')+'>'+value+'</option>').join('');
  }
  function ensureAutomationConfig(){
    if(!automationConfig||Array.isArray(automationConfig)) automationConfig={};
    if(!automationConfig.devices||Array.isArray(automationConfig.devices)) automationConfig.devices={};
    if(!automationConfig.schedules||Array.isArray(automationConfig.schedules)) automationConfig.schedules={};
    if(!automationConfig.battery_soc_limits||Array.isArray(automationConfig.battery_soc_limits)) automationConfig.battery_soc_limits={min:10,max:95};
    if(!automationConfig.system_power_limit||Array.isArray(automationConfig.system_power_limit)) automationConfig.system_power_limit={enabled:false,max_kw:0,delay_sec:20};
    if(!automationConfig.battery_strategy||Array.isArray(automationConfig.battery_strategy)) automationConfig.battery_strategy={enabled:true};
  }
  function scheduleEditor(role,title){
    const schedule=automationConfig.schedules[role]||automationConfig.schedule||{};
    return '<div class="ef-solar-step"><div class="ef-solar-step-head"><div class="ef-solar-step-title"><i class="fa-solid fa-clock"></i><div><b>ตั้งเวลาการทำงาน</b><small>กำหนดช่วงเวลาที่อนุญาตให้ Automation ควบคุม Solar</small></div></div><span class="ef-solar-everyday"><i class="fa-solid fa-calendar-check"></i> ทุกวัน</span></div>'+
      '<label class="ef-ac-check ef-ac-wide"><input id="efAcScheduleEnabled" type="checkbox"'+acChecked(schedule.enabled)+'><span><b>เปิดควบคุม Solar ตามเวลา</b><small>เมื่อปิด ระบบจะไม่ใช้ตารางเวลานี้ควบคุม Inverter</small></span></label>'+
      '<div class="ef-solar-time-row"><label><span>เวลาเริ่มทำงาน</span><select id="efAcStart">'+acTimeOptions(schedule.start||'07:30')+'</select></label><span class="ef-solar-time-arrow"><i class="fa-solid fa-arrow-right"></i></span><label><span>เวลาสิ้นสุด</span><select id="efAcEnd">'+acTimeOptions(schedule.end||'17:30')+'</select></label></div>'+
      '<small class="ef-solar-time-note"><i class="fa-solid fa-circle-info"></i> ใช้รูปแบบเวลา 24 ชั่วโมง และมีผลทุกวันรวมวันเสาร์–อาทิตย์</small></div>';
  }
  function modelReferenceOptions(deviceId,selected){
    const model=devices[deviceId]?.model||'';
    const map=registerMaps[model]||{};
    const names=[];
    const banks=(map.holding||map.input)?Object.values(map):[map];
    banks.forEach(bank=>Object.values(bank||{}).forEach(info=>{
      if(info&&info.name&&!names.includes(info.name)) names.push(info.name);
    }));
    return '<option value="">Manual value</option>'+names.sort().map(name=>{
      const meta=parameterList[name]||{};
      const label=meta.full_name||meta.display_name||name;
      return '<option value="'+esc(name)+'"'+(name===selected?' selected':'')+'>'+esc(label)+(label!==name?' ('+esc(name)+')':'')+'</option>';
    }
    ).join('');
  }
  function deviceEditor(deviceId){
    const saved=automationConfig.devices[deviceId]||{};
    const deviceType=typeOf(deviceId);
    const defaultRole=deviceType==='battery'?'battery':(deviceType==='inverter'?'solar_inverter':(deviceType==='ev'?'ev':'monitor'));
    const effectiveRole=saved.role||defaultRole;
    const model=devices[deviceId]?.model||'';
    const modelMap=registerMaps[model]||{};
    const modelNames=[];
    (modelMap.holding||modelMap.input?Object.values(modelMap):[modelMap]).forEach(bank=>Object.values(bank||{}).forEach(info=>{if(info?.name)modelNames.push(info.name);}));
    const minRef=saved.min_ref!==undefined?saved.min_ref:(defaultRole==='battery'&&modelNames.includes('p_max_cha')?'p_max_cha':'');
    const maxRef=saved.max_ref!==undefined?saved.max_ref:(defaultRole==='battery'&&modelNames.includes('p_max_dis')?'p_max_dis':'');
    const minValue=acNumber(saved.min_kw,0);
    const maxValue=acNumber(saved.max_kw,0);
    const disabled=canEditAutomation?'':' disabled';
    return '<div class="ef-ac-grid"><div class="ef-device-control-zone ef-ac-wide">'+
      '<div class="ef-strategy-toggle ef-device-control-toggle ef-ac-wide"><label class="ef-toggle-control"><input id="efAcControlEnabled" type="checkbox"'+acChecked(saved.control_enabled)+disabled+'><span class="ef-toggle-track"><i></i></span></label><span class="ef-toggle-copy"><b>Enable control</b><small>อนุญาตให้ Automation สั่งอุปกรณ์นี้</small></span><strong class="ef-toggle-state"></strong></div>'+
      '<div class="ef-ac-grid ef-device-control-fields ef-ac-wide'+(saved.control_enabled?'':' is-hidden')+'">'+
      '<label><span>Role</span><select id="efAcRole"'+disabled+'><option value="solar_inverter"'+(effectiveRole==='solar_inverter'?' selected':'')+'>Solar Inverter</option><option value="battery"'+(effectiveRole==='battery'?' selected':'')+'>Battery</option><option value="ev"'+(effectiveRole==='ev'?' selected':'')+'>EV Charger</option><option value="monitor"'+(effectiveRole==='monitor'?' selected':'')+'>Monitor only</option></select></label>'+
      '<label><span>Priority</span><input id="efAcPriority" type="number" min="1" max="999" value="'+acNumber(saved.priority,100)+'"'+disabled+'></label>'+
      '<label class="ef-ref-field"><span>Min kW</span><div class="ef-ref-summary"><b data-ref-summary>'+(minRef?esc(minRef)+' · ':'Manual · ')+minValue+' kW</b><button type="button" data-ref-edit title="แก้ไข Min kW"'+disabled+'><i class="fa-solid fa-pen"></i></button></div><div class="ef-ref-panel"><select id="efAcMinRef" class="ef-ac-reference"'+disabled+'>'+modelReferenceOptions(deviceId,minRef)+'</select><div class="ef-ref-write"><input id="efAcMinKw" type="number" step="0.1" value="'+minValue+'"'+disabled+'><button type="button" data-ref-send'+disabled+'><i class="fa-solid fa-paper-plane"></i> Apply</button></div><small class="ef-ref-write-status" data-ref-status></small></div></label>'+
      '<label class="ef-ref-field"><span>Max kW</span><div class="ef-ref-summary"><b data-ref-summary>'+(maxRef?esc(maxRef)+' · ':'Manual · ')+maxValue+' kW</b><button type="button" data-ref-edit title="แก้ไข Max kW"'+disabled+'><i class="fa-solid fa-pen"></i></button></div><div class="ef-ref-panel"><select id="efAcMaxRef" class="ef-ac-reference"'+disabled+'>'+modelReferenceOptions(deviceId,maxRef)+'</select><div class="ef-ref-write"><input id="efAcMaxKw" type="number" step="0.1" value="'+maxValue+'"'+disabled+'><button type="button" data-ref-send'+disabled+'><i class="fa-solid fa-paper-plane"></i> Apply</button></div><small class="ef-ref-write-status" data-ref-status></small></div></label></div></div></div>'+
      '<input type="hidden" id="efAcDefaultRole" value="'+defaultRole+'">';
  }
  function batteryPmaxEditor(deviceId){
    const reading=lastPayload&&lastPayload.data?lastPayload.data[deviceId]||{}:{};
    const current=number(reading.p_max);
    const control=automationConfig.devices[deviceId]||{};
    const initial=current!==null?current:acNumber(control.max_kw,0);
    return '<div class="ef-pmax-control" data-pmax-device="'+esc(deviceId)+'">'+
      '<div><b><i class="fa-solid fa-bolt"></i> Manual p_max</b><small>Current: <strong data-pmax-current>'+fmt(current)+'</strong> kW</small></div>'+
      '<label><input data-pmax-input type="number" step="0.1" min="'+acNumber(control.min_kw,-100000)+'" max="'+acNumber(control.max_kw,100000)+'" value="'+initial+'"><span>kW</span></label>'+
      '<button type="button" data-pmax-send'+(canEditAutomation?'':' disabled')+'><i class="fa-solid fa-paper-plane"></i> Apply</button>'+
      '<small class="ef-pmax-status" data-pmax-status></small>'+
    '</div>';
  }
  function syncReferenceInputs(root,deviceId,force=false){
    const reading=lastPayload&&lastPayload.data?lastPayload.data[deviceId]||{}:{};
    const saved=automationConfig.devices?.[deviceId]||{};
    [['efAcMinRef','efAcMinKw','min'],['efAcMaxRef','efAcMaxKw','max']].forEach(pair=>{
      const select=root.querySelector('#'+pair[0]);
      const input=root.querySelector('#'+pair[1]);
      if(!select||!input) return;
      const configuredValue=acNumber(saved[pair[2]+'_kw'],0);
      if(!select.value){
        input.value=configuredValue;
        input.title='ใช้ค่าที่บันทึกใน Automation Config = '+configuredValue+' kW';
        const manualSummary=select.closest('.ef-ref-field')?.querySelector('[data-ref-summary]');
        if(manualSummary) manualSummary.textContent='Manual · '+configuredValue+' kW';
        return;
      }
      const referenced=number(reading[select.value]);
      const displayValue=referenced!==null?referenced:configuredValue;
      input.value=displayValue;
      input.title=referenced===null?'ยังไม่มีค่าจาก '+select.value+' · ใช้ค่าจาก Config '+configuredValue+' kW':'ค่าปัจจุบันจาก '+select.value+' = '+displayValue+' kW';
      const summary=select.closest('.ef-ref-field')?.querySelector('[data-ref-summary]');
      if(summary) summary.textContent=(select.value||'Manual')+' · '+input.value+' kW';
    });
  }
  function syncRoleControl(root){
    const role=root.querySelector('#efAcRole');
    const enabled=root.querySelector('#efAcControlEnabled');
    if(!role||!enabled)return;
    const isMonitor=role.value==='monitor';
    if(isMonitor)enabled.checked=false;
    enabled.disabled=isMonitor||!canEditAutomation;
    enabled.closest('.ef-device-control-toggle')?.classList.toggle('ef-control-disabled',isMonitor);
    syncDeviceControl(root);
  }
  function syncDeviceControl(root){
    const enabled=root.querySelector('#efAcControlEnabled');
    const fields=root.querySelector('.ef-device-control-fields');
    if(fields&&enabled) fields.classList.toggle('is-hidden',!enabled.checked);
  }
  function openAutomationEditor(section,deviceId=''){
    ensureAutomationConfig();
    activeAcEditor={section,deviceId};
    acStatus.textContent='';
    if(deviceId){
      acTitle.textContent='Device Automation';
      acSubtitle.textContent=nameOf(deviceId)+' · '+deviceId;
      acBody.innerHTML=deviceEditor(deviceId);
      document.getElementById('efAcRole').value=automationConfig.devices[deviceId]?.role||document.getElementById('efAcDefaultRole').value;
      syncRoleControl(acBody);
      syncReferenceInputs(acBody,deviceId);
    }else if(section==='solar'){
      const solarIds=Object.keys(devices).filter(id=>typeOf(id)==='inverter');
      acTitle.textContent='Solar Automation';
      acSubtitle.textContent='ตั้งเวลาและเลือก Inverter ที่อนุญาตให้ระบบควบคุม';
      acBody.innerHTML='<div class="ef-solar-setup">'+scheduleEditor('solar_inverter','Solar Schedule')+
        '<div class="ef-solar-step"><div class="ef-solar-device-head"><div class="ef-solar-device-heading"><i class="fa-solid fa-solar-panel"></i><div><b>Inverter ที่ควบคุม</b><small>เปิด Enable control เฉพาะเครื่องที่ต้องการให้ Automation สั่งงาน</small></div></div><span class="ef-solar-device-count">'+solarIds.length+' เครื่อง</span></div>'+
        (solarIds.length?solarIds.map(id=>'<div class="ef-battery-config-panel ef-solar-device-card"><div class="ef-ac-check-title"><i class="fa-solid fa-solar-panel"></i><span><b>'+esc(nameOf(id))+'</b><small>'+esc(id)+'</small></span></div><div data-solar-device="'+esc(id)+'">'+deviceEditor(id)+'</div></div>').join(''):'<div class="ef-ac-help">ไม่พบอุปกรณ์ Solar Inverter</div>')+'</div></div>';
      acBody.querySelectorAll('[data-solar-device]').forEach(root=>{syncRoleControl(root);syncReferenceInputs(root,root.dataset.solarDevice);});
    }else if(section==='battery'){
      const limits=automationConfig.battery_soc_limits;
      const strategy=automationConfig.battery_strategy;
      const offPeakEnd=(acEstimatedEnd(strategy.off_peak_start||'06:00',acNumber(strategy.off_peak_duration_hours,3))||{time:'09:00'}).time;
      const dischargeEnd=(acEstimatedEnd(strategy.discharge_start||'19:00',acNumber(strategy.discharge_duration_hours,3))||{time:'22:00'}).time;
      const strategyHolidayDates=Array.isArray(strategy.holiday_dates)?strategy.holiday_dates:[];
      const holidayDateList=[...new Set([...touHolidayDates,...strategyHolidayDates])].sort();
      const holidayDates=holidayDateList.join(',');
      const batteryIds=Object.keys(devices).filter(id=>typeOf(id)==='battery');
      if(!batteryIds.includes(selectedBatteryAutomationId)) selectedBatteryAutomationId=batteryIds[0]||'';
      acTitle.textContent='Battery Automation';
      acSubtitle.textContent='ตั้งค่าระดับพลังงาน เวลา และกำลัง Charge/Discharge';
      acBody.innerHTML=
        '<div class="ef-battery-setup"><div class="ef-battery-step"><div class="ef-battery-step-head"><i class="fa-solid fa-battery-half"></i><div><b>กำหนดช่วง SoC</b><small>ขอบเขตความปลอดภัยสำหรับการชาร์จและจ่ายพลังงาน</small></div></div><div class="ef-ac-grid ef-battery-soc-grid">'+
        '<label><span>SoC ต่ำสุด (%)</span><input id="efAcSocMin" type="number" min="0" max="100" value="'+acNumber(limits.min,10)+'"><small class="ef-ac-help"><i class="fa-solid fa-shield-halved"></i> ไม่จ่ายพลังงานเมื่อถึงค่านี้</small></label>'+
        '<label><span>SoC สูงสุด (%)</span><input id="efAcSocMax" type="number" min="0" max="100" value="'+acNumber(limits.max,95)+'"><small class="ef-ac-help"><i class="fa-solid fa-shield-halved"></i> ไม่เก็บพลังงานเพิ่มเมื่อถึงค่านี้</small></label>'+
        '</div></div>'+
        '<div class="ef-strategy-box"><div class="ef-strategy-toggle ef-strategy-main-toggle"><label class="ef-toggle-control" title="เปิด/ปิด Battery Load Strategy"><input id="efAcStrategyEnabled" type="checkbox"'+acChecked(strategy.enabled!==false)+'><span class="ef-toggle-track"><i></i></span></label><span class="ef-toggle-copy"><b>Battery Load Strategy</b><small>ปรับ Charge / Discharge อัตโนมัติตามเวลาและ Main Meter</small></span><strong class="ef-toggle-state"></strong></div>'+
        '<div class="ef-ac-grid ef-strategy-fields'+(strategy.enabled===false?' is-hidden':'')+'">'+
        '<div class="ef-battery-group-title"><i class="fa-solid fa-battery-full"></i> ข้อมูลและขีดจำกัดแบตเตอรี่</div>'+
        '<label><span>Battery Capacity (kWh)</span><input id="efAcCapacity" type="number" min="0.1" step="0.1" value="'+acNumber(strategy.capacity_kwh,240)+'"></label><label><span>Maximum Charge (kW)</span><input id="efAcStrategyMaxCharge" type="number" min="0" step="0.1" value="'+acNumber(strategy.max_charge_kw,100)+'"></label>'+
        '<div class="ef-battery-group-title"><i class="fa-solid fa-moon"></i> การชาร์จช่วง Off-Peak</div>'+
        '<label><span>Off-Peak Start (24h)</span><select id="efAcOffStart">'+acTimeOptions(strategy.off_peak_start||'06:00')+'</select></label><label><span>Off-Peak End (24h)</span><select id="efAcOffEnd">'+acTimeOptions(offPeakEnd)+'</select><input id="efAcOffDuration" type="hidden" value="'+acNumber(strategy.off_peak_duration_hours,3)+'"><small id="efAcOffPeakEstimate" class="ef-ac-help ef-time-estimate"><i class="fa-solid fa-clock"></i> Duration —</small></label>'+
        '<label><span>Dynamic Charge Target SoC (%)</span><input id="efAcDynamicChargeTargetSoc" type="number" min="0" max="100" step="1" value="'+acNumber(strategy.dynamic_charge_target_soc,85)+'"></label><label><span>Charge Efficiency (%)</span><input id="efAcChargeEfficiency" type="number" min="1" max="100" step="0.1" value="'+acNumber(strategy.charge_efficiency_percent,90)+'"></label>'+
        '<label><span>Dynamic Charge Round Step (kW)</span><input id="efAcChargeStep" type="number" min="0.1" step="0.1" value="'+acNumber(strategy.charge_step_kw,10)+'"></label><label><span>Discharge Step (kW)</span><input id="efAcDischargeStep" type="number" min="0.1" step="0.1" value="'+acNumber(strategy.discharge_step_kw,10)+'"></label>'+
        '<div class="ef-battery-group-title"><i class="fa-solid fa-arrows-rotate"></i> การควบคุมตลอดเวลา</div>'+
        '<label><span>Maintain Main Power From (kW)</span><input id="efAcMinL" type="number" step="0.1" value="'+acNumber(strategy.min_l_kw,10)+'"><small class="ef-ac-help">ทำงานทุกเวลา: ต่ำกว่าค่านี้จะเพิ่มกำลังชาร์จ</small></label><label><span>Stop Battery Charging At (kW)</span><input id="efAcMinH" type="number" step="0.1" value="'+acNumber(strategy.min_h_kw,30)+'"><small class="ef-ac-help">ทำงานทุกเวลา: ถึงค่านี้จะหยุดชาร์จแบตเตอรี่</small></label>'+
        '<div class="ef-battery-group-title"><i class="fa-solid fa-bolt"></i> การควบคุมช่วง On-Peak</div>'+
        '<label><span>On-Peak Start (24h)</span><select id="efAcOnStart">'+acTimeOptions(strategy.on_peak_start||'09:00')+'</select></label><label><span>On-Peak End (24h)</span><select id="efAcOnEnd">'+acTimeOptions(strategy.on_peak_end||'22:00')+'</select></label>'+
        '<label><span>Daytime Control Start (24h)</span><select id="efAcDayStart">'+acTimeOptions(strategy.day_control_start||'09:00')+'</select></label><label><span>Daytime Control End (24h)</span><select id="efAcDayEnd">'+acTimeOptions(strategy.day_control_end||'18:00')+'</select></label>'+
        '<label><span>Daytime Minimum SoC (%)</span><input id="efAcDayMinSoc" type="number" min="0" max="100" value="'+acNumber(strategy.day_min_soc,60)+'"></label><label><span>Daytime Grid Trigger (kW)</span><input id="efAcDayGridTrigger" type="number" min="0" step="0.1" value="'+acNumber(strategy.day_grid_trigger_kw,50)+'"></label>'+
        '<label><span>Daytime Main Minimum (kW)</span><input id="efAcDayMainMin" type="number" min="0" step="0.1" value="'+acNumber(strategy.day_main_min_kw,40)+'"></label><label><span>Daytime Main Maximum (kW)</span><input id="efAcDayMainMax" type="number" min="0" step="0.1" value="'+acNumber(strategy.day_main_max_kw,70)+'"></label>'+
        '<label><span>Daytime Main Target (kW)</span><input id="efAcDayMainAvg" type="number" min="0" step="0.1" value="'+acNumber(strategy.day_main_avg_kw,55)+'"></label><label><span>Daytime Discharge Limit (kW)</span><input id="efAcDayDischargeKw" type="number" min="0" step="0.1" value="'+acNumber(strategy.day_discharge_kw,100)+'"></label>'+
        '<label><span>Peak Minimum (kW)</span><input id="efAcMaxL" type="number" step="0.1" value="'+acNumber(strategy.max_l_kw,470)+'"><small class="ef-ac-help">Main ต่ำกว่าค่านี้จะค่อย ๆ ลดกำลัง Discharge</small></label><label><span>Peak Maximum (kW)</span><input id="efAcMaxH" type="number" step="0.1" value="'+acNumber(strategy.max_h_kw,500)+'"><small class="ef-ac-help">Main เกินค่านี้จะเพิ่ม Discharge เพื่อลด Peak</small></label>'+
        '<label><span>Peak Reserve SoC (%)</span><input id="efAcPeakReserveSoc" type="number" min="0" max="100" step="1" value="'+acNumber(strategy.peak_reserve_soc,50)+'"><small class="ef-ac-help">ต่ำกว่านี้จะไม่ล็อก Deadband และจ่ายเมื่อ Main เกิน Maximum เท่านั้น</small></label>'+
        '<div class="ef-ac-wide ef-peak-average"><i class="fa-solid fa-calculator"></i><span>Peak Average = (Minimum + Maximum) ÷ 2 = <b id="efAcPeakAverageValue">'+acNumber((acNumber(strategy.max_l_kw,470)+acNumber(strategy.max_h_kw,500))/2,485)+'</b> kW</span></div>'+
        '<label><span>Scheduled Discharge Start (24h)</span><select id="efAcDischargeStart">'+acTimeOptions(strategy.discharge_start||'19:00')+'</select></label><label><span>Scheduled Discharge End (24h)</span><select id="efAcDischargeEnd">'+acTimeOptions(dischargeEnd)+'</select><input id="efAcDischargeDuration" type="hidden" value="'+acNumber(strategy.discharge_duration_hours,3)+'"><small id="efAcDischargeEstimate" class="ef-ac-help ef-time-estimate"><i class="fa-solid fa-clock"></i> Duration —</small></label>'+
        '<label><span>Scheduled Discharge Minimum SoC (%)</span><input id="efAcScheduledDischargeMinSoc" type="number" min="0" max="100" step="1" value="'+acNumber(strategy.scheduled_discharge_min_soc,10)+'"><small class="ef-ac-help">เริ่มจ่ายตามตารางเมื่อ SoC สูงกว่าค่านี้</small></label><label><span>Scheduled Discharge Target (kW)</span><input id="efAcScheduledDischargeKw" type="number" min="0" step="0.1" value="'+acNumber(strategy.scheduled_discharge_kw,50)+'"><small class="ef-ac-help">เพิ่มตาม Discharge Step จนถึงค่านี้ โดยไม่เกิน Max kW</small></label>'+
        '<div class="ef-battery-group-title"><i class="fa-solid fa-calendar-day"></i> การควบคุมวันเสาร์–อาทิตย์ / Off-Day</div>'+
        '<label><span>Off-Day Minimum SoC (%)</span><input id="efAcOffDayMinSoc" type="number" min="0" max="100" value="'+acNumber(strategy.off_day_min_soc,85)+'"></label><label><span>Off-Day Discharge Limit (kW)</span><input id="efAcOffDayDischargeKw" type="number" min="0" step="0.1" value="'+acNumber(strategy.off_day_discharge_kw,0)+'"></label>'+
        '<label><span>Off-Day Main Minimum (kW)</span><input id="efAcOffDayMainMin" type="number" min="0" step="0.1" value="'+acNumber(strategy.off_day_main_min_kw,10)+'"></label><label><span>Off-Day Main Maximum (kW)</span><input id="efAcOffDayMainMax" type="number" min="0" step="0.1" value="'+acNumber(strategy.off_day_main_max_kw,40)+'"></label>'+
        '<label><span>Off-Day Main Target (kW)</span><input id="efAcOffDayMainAvg" type="number" min="0" step="0.1" value="'+acNumber(strategy.off_day_main_avg_kw,25)+'"></label>'+
        '<div class="ef-ac-wide"><span class="ef-ac-check-title">TOU Schedule</span><div id="efTouCalendar" class="ef-tou-calendar" data-saved-dates="'+esc(holidayDates)+'"><div class="ef-tou-head"><button class="ef-tou-nav" type="button" data-tou-month="-1" aria-label="เดือนก่อนหน้า">&#8249;</button><strong id="efTouMonthLabel"></strong><button class="ef-tou-nav" type="button" data-tou-month="1" aria-label="เดือนถัดไป">&#8250;</button></div><div class="ef-tou-week"><span>อา.</span><span>จ.</span><span>อ.</span><span>พ.</span><span>พฤ.</span><span>ศ.</span><span>ส.</span></div><div id="efTouCalendarGrid" class="ef-tou-grid"></div><input id="efAcHolidayDates" type="hidden" value="'+esc(holidayDates)+'"><div class="ef-tou-legend"><span><b class="ef-tou-legend-mark saved">✓</b> บันทึกแล้ว</span><span><b class="ef-tou-legend-mark pending">●</b> รอบันทึก</span></div><small class="ef-ac-help">คลิกวันที่บนปฏิทินเพื่อเพิ่มหรือลบ TOU Holiday</small></div></div></div></div>'+
        '<div class="ef-battery-step ef-battery-device-section"><div class="ef-battery-step-head"><i class="fa-solid fa-sliders"></i><div><b>Battery ที่ควบคุม</b><small>เลือกเครื่อง แล้วกำหนดสิทธิ์และช่วงกำลังที่ Automation ใช้งานได้</small></div></div><div class="ef-battery-picker"><label><span>เลือกอุปกรณ์</span><select id="efAcBatterySelect">'+batteryIds.map(id=>'<option value="'+esc(id)+'"'+(id===selectedBatteryAutomationId?' selected':'')+'>'+esc(nameOf(id))+' · '+esc(id)+'</option>').join('')+'</select></label></div>'+
        batteryIds.map(id=>'<div class="ef-battery-config-panel" data-battery-panel="'+esc(id)+'"'+(id===selectedBatteryAutomationId?'':' hidden')+'><div class="ef-ac-check-title ef-battery-device-title"><i class="fa-solid fa-battery-half"></i><span><b>'+esc(nameOf(id))+'</b><small>'+esc(id)+'</small></span></div>'+batteryPmaxEditor(id)+'<div data-battery-device="'+esc(id)+'">'+deviceEditor(id)+'</div></div>').join('')+'</div></div>';
      acBody.querySelectorAll('[data-battery-device]').forEach(root=>{syncRoleControl(root);syncReferenceInputs(root,root.dataset.batteryDevice);});
      initTouCalendar();
      addBatteryAutomationInfo(acBody);
      updateEfOffPeakEndFromDuration();
      document.getElementById('efAcOffStart')?.addEventListener('change',updateEfOffPeakEndFromDuration);
      document.getElementById('efAcOffEnd')?.addEventListener('change',updateEfOffPeakDurationFromEnd);
      updateEfDischargeEndFromDuration();
      document.getElementById('efAcDischargeStart')?.addEventListener('change',updateEfDischargeEndFromDuration);
      document.getElementById('efAcDischargeEnd')?.addEventListener('change',updateEfDischargeDurationFromEnd);
      const updatePeakAverage=()=>{
        const minimum=acNumber(document.getElementById('efAcMaxL')?.value,NaN);
        const maximum=acNumber(document.getElementById('efAcMaxH')?.value,NaN);
        const output=document.getElementById('efAcPeakAverageValue');
        if(output)output.textContent=Number.isFinite(minimum)&&Number.isFinite(maximum)&&maximum>=minimum?Number(((minimum+maximum)/2).toFixed(2)):'—';
      };
      ['input','change'].forEach(eventName=>['efAcMaxL','efAcMaxH'].forEach(id=>document.getElementById(id)?.addEventListener(eventName,updatePeakAverage)));
    }else if(section==='limit'){
      const limit=automationConfig.system_power_limit;
      acTitle.textContent='Peak Limit';
      acSubtitle.textContent='จำกัดกำลังไฟฟ้ารวมของระบบ';
      acBody.innerHTML='<div class="ef-ac-grid">'+toggleEditor('efAcLimitEnabled',limit.enabled,'Enable Peak Limit','ควบคุมกำลังรวมไม่ให้เกินค่าที่กำหนด')+'<label><span>Maximum Total Consumption (kW)</span><input id="efAcLimitKw" type="number" min="0" step="0.1" value="'+acNumber(limit.max_kw,0)+'"></label><label><span>Peak response delay (0 = Immediate)</span><input id="efAcLimitDelay" type="number" min="0" max="30" step="1" value="'+acNumber(limit.delay_sec,1)+'"></label></div>';
    }else{
      acTitle.textContent='Main Meter Automation';
      acSubtitle.textContent='แหล่งอ้างอิงและ Threshold';
      acBody.innerHTML='<div class="ef-ac-grid">'+toggleEditor('efAcEnabled',automationConfig.enabled,'Enable Automation','เปิดใช้งานระบบควบคุมอัตโนมัติ')+
        '<label class="ef-ac-wide"><span>Main Meter</span><select id="efAcMainMeter">'+meterIds.map(id=>'<option value="'+esc(id)+'">'+esc(nameOf(id))+' · '+esc(id)+'</option>').join('')+'</select></label>'+
        '<label><span>Import threshold (kW)</span><input id="efAcImport" type="number" min="0" step="0.1" value="'+acNumber(automationConfig.import_threshold_kw,10)+'"></label>'+
        '<label><span>Export threshold (kW)</span><input id="efAcExport" type="number" max="0" step="0.1" value="'+acNumber(automationConfig.export_threshold_kw,-10)+'"></label>'+
        '<label class="ef-ac-wide"><span>Control interval (0 = Immediate)</span><input id="efAcInterval" type="number" min="0" max="3600" value="'+acNumber(automationConfig.control_interval_sec,0)+'"></label></div>';
      document.getElementById('efAcMainMeter').value=automationConfig.main_meter||meterIds[0]||'';
    }
    acModal.classList.add('open');
    acModal.setAttribute('aria-hidden','false');
    document.getElementById('efAcSave').disabled=!canEditAutomation;
    if(!canEditAutomation){
      acBody.querySelectorAll('input,select,button').forEach(element=>element.disabled=true);
      acStatus.textContent='อ่านได้อย่างเดียว · ต้องใช้สิทธิ์ Admin หรือ Superadmin เพื่อแก้ไข';
    }
  }
  function closeAutomationEditor(){
    acModal.classList.remove('open');
    acModal.setAttribute('aria-hidden','true');
    activeAcEditor=null;
  }
  function readDeviceEditor(root,deviceId){
    const find=id=>root.querySelector('#'+id);
    automationConfig.devices[deviceId]={
      role:find('efAcRole').value,
      control_enabled:find('efAcControlEnabled').checked,
      min_kw:acNumber(find('efAcMinKw').value),
      max_kw:acNumber(find('efAcMaxKw').value),
      min_ref:find('efAcMinRef').value,
      max_ref:find('efAcMaxRef').value,
      priority:acNumber(find('efAcPriority').value,100)
    };
  }
  function collectAutomationEditor(){
    const {section,deviceId}=activeAcEditor;
    if(deviceId){
      readDeviceEditor(acBody,deviceId);
    }else if(section==='solar'||section==='battery'){
      if(section==='solar'){
        automationConfig.schedules.solar_inverter={
          enabled:document.getElementById('efAcScheduleEnabled').checked,
          start:document.getElementById('efAcStart').value,
          end:document.getElementById('efAcEnd').value,
          weekend_enabled:true
        };
        acBody.querySelectorAll('[data-solar-device]').forEach(root=>readDeviceEditor(root,root.dataset.solarDevice));
      }
      if(section==='battery'){
        automationConfig.battery_soc_limits={min:acNumber(document.getElementById('efAcSocMin').value,10),max:acNumber(document.getElementById('efAcSocMax').value,95)};
        automationConfig.battery_strategy={
          enabled:document.getElementById('efAcStrategyEnabled').checked,
          capacity_kwh:acNumber(document.getElementById('efAcCapacity').value,240),
          max_charge_kw:acNumber(document.getElementById('efAcStrategyMaxCharge').value,100),
          off_peak_start:document.getElementById('efAcOffStart').value,
          off_peak_duration_hours:acNumber(document.getElementById('efAcOffDuration').value,3),
          dynamic_charge_target_soc:acNumber(document.getElementById('efAcDynamicChargeTargetSoc').value,85),
          charge_efficiency_percent:acNumber(document.getElementById('efAcChargeEfficiency').value,90),
          charge_step_kw:acNumber(document.getElementById('efAcChargeStep').value,10),
          discharge_step_kw:acNumber(document.getElementById('efAcDischargeStep').value,10),
          on_peak_start:document.getElementById('efAcOnStart').value,
          on_peak_end:document.getElementById('efAcOnEnd').value,
          day_control_start:document.getElementById('efAcDayStart').value,
          day_control_end:document.getElementById('efAcDayEnd').value,
          day_min_soc:acNumber(document.getElementById('efAcDayMinSoc').value,60),
          day_grid_trigger_kw:acNumber(document.getElementById('efAcDayGridTrigger').value,50),
          day_main_min_kw:acNumber(document.getElementById('efAcDayMainMin').value,40),
          day_main_max_kw:acNumber(document.getElementById('efAcDayMainMax').value,70),
          day_main_avg_kw:acNumber(document.getElementById('efAcDayMainAvg').value,55),
          day_discharge_kw:acNumber(document.getElementById('efAcDayDischargeKw').value,100),
          min_l_kw:acNumber(document.getElementById('efAcMinL').value,10),
          min_h_kw:acNumber(document.getElementById('efAcMinH').value,30),
          max_l_kw:acNumber(document.getElementById('efAcMaxL').value,470),
          max_h_kw:acNumber(document.getElementById('efAcMaxH').value,500),
          peak_reserve_soc:acNumber(document.getElementById('efAcPeakReserveSoc').value,50),
          discharge_start:document.getElementById('efAcDischargeStart').value,
          discharge_duration_hours:acNumber(document.getElementById('efAcDischargeDuration').value,3),
          scheduled_discharge_min_soc:acNumber(document.getElementById('efAcScheduledDischargeMinSoc').value,10),
          scheduled_discharge_kw:acNumber(document.getElementById('efAcScheduledDischargeKw').value,50),
          off_day_min_soc:acNumber(document.getElementById('efAcOffDayMinSoc').value,85),
          off_day_main_min_kw:acNumber(document.getElementById('efAcOffDayMainMin').value,10),
          off_day_main_max_kw:acNumber(document.getElementById('efAcOffDayMainMax').value,40),
          off_day_main_avg_kw:acNumber(document.getElementById('efAcOffDayMainAvg').value,25),
          off_day_discharge_kw:acNumber(document.getElementById('efAcOffDayDischargeKw').value,0),
          holiday_dates:document.getElementById('efAcHolidayDates').value.split(',').map(value=>value.trim()).filter(Boolean)
        };
        acBody.querySelectorAll('[data-battery-device]').forEach(root=>readDeviceEditor(root,root.dataset.batteryDevice));
      }
    }else if(section==='limit'){
      automationConfig.system_power_limit={enabled:document.getElementById('efAcLimitEnabled').checked,max_kw:acNumber(document.getElementById('efAcLimitKw').value),delay_sec:Math.max(0,Math.min(30,acNumber(document.getElementById('efAcLimitDelay').value,1)))};
    }else{
      automationConfig.enabled=document.getElementById('efAcEnabled').checked;
      automationConfig.main_meter=document.getElementById('efAcMainMeter').value;
      automationConfig.import_threshold_kw=acNumber(document.getElementById('efAcImport').value,10);
      automationConfig.export_threshold_kw=acNumber(document.getElementById('efAcExport').value,-10);
      automationConfig.control_interval_sec=acNumber(document.getElementById('efAcInterval').value);
    }
  }
  let touCalendarMonth=null;
  function touDateKey(date){
    return date.getFullYear()+'-'+String(date.getMonth()+1).padStart(2,'0')+'-'+String(date.getDate()).padStart(2,'0');
  }
  function initTouCalendar(){
    const calendar=document.getElementById('efTouCalendar');
    if(!calendar)return;
    const saved=(calendar.dataset.savedDates||'').split(',').filter(Boolean).sort();
    const initial=saved.find(date=>date.slice(0,7)===touDateKey(new Date()).slice(0,7))||saved[0];
    const parts=initial?initial.split('-').map(Number):[];
    touCalendarMonth=parts.length===3?new Date(parts[0],parts[1]-1,1):new Date(new Date().getFullYear(),new Date().getMonth(),1);
    renderTouCalendar();
  }
  function renderTouCalendar(){
    const calendar=document.getElementById('efTouCalendar');
    const grid=document.getElementById('efTouCalendarGrid');
    const label=document.getElementById('efTouMonthLabel');
    const store=document.getElementById('efAcHolidayDates');
    if(!calendar||!grid||!label||!store||!touCalendarMonth)return;
    const selected=new Set(store.value.split(',').filter(Boolean));
    const saved=new Set((calendar.dataset.savedDates||'').split(',').filter(Boolean));
    label.textContent=new Intl.DateTimeFormat('th-TH',{month:'long',year:'numeric'}).format(touCalendarMonth);
    grid.replaceChildren();
    const first=new Date(touCalendarMonth.getFullYear(),touCalendarMonth.getMonth(),1);
    const start=new Date(first);
    start.setDate(1-first.getDay());
    const today=touDateKey(new Date());
    for(let index=0;index<42;index++){
      const date=new Date(start);
      date.setDate(start.getDate()+index);
      const key=touDateKey(date);
      const button=document.createElement('button');
      button.type='button';
      button.className='ef-tou-day';
      button.dataset.touDay=key;
      button.textContent=date.getDate();
      if(date.getMonth()!==touCalendarMonth.getMonth())button.classList.add('outside');
      if(key===today)button.classList.add('today');
      if(selected.has(key)){
        button.classList.add(saved.has(key)?'selected':'pending');
        const mark=document.createElement('span');
        mark.className='ef-tou-mark';
        mark.textContent=saved.has(key)?'✓':'●';
        button.appendChild(mark);
      }
      grid.appendChild(button);
    }
  }
  function toggleTouDate(date){
    const store=document.getElementById('efAcHolidayDates');
    if(!store)return;
    const dates=new Set(store.value.split(',').filter(Boolean));
    dates.has(date)?dates.delete(date):dates.add(date);
    store.value=[...dates].sort().join(',');
    renderTouCalendar();
  }
  document.addEventListener('click',event=>{
    const touMonth=event.target.closest('[data-tou-month]');
    if(touMonth){
      event.preventDefault();
      touCalendarMonth.setMonth(touCalendarMonth.getMonth()+Number(touMonth.dataset.touMonth));
      renderTouCalendar();
      return;
    }
    const touDay=event.target.closest('[data-tou-day]');
    if(touDay){
      event.preventDefault();
      toggleTouDate(touDay.dataset.touDay);
      return;
    }
    const referenceEdit=event.target.closest('[data-ref-edit]');
    if(referenceEdit){
      event.preventDefault();
      referenceEdit.closest('.ef-ref-field').classList.toggle('editing');
      return;
    }
    const referenceSend=event.target.closest('[data-ref-send]');
    if(referenceSend){
      event.preventDefault();
      const field=referenceSend.closest('.ef-ref-field');
      const root=referenceSend.closest('[data-battery-device],[data-solar-device]')||acBody;
      const deviceId=root.dataset.batteryDevice||root.dataset.solarDevice||activeAcEditor?.deviceId||'';
      const parameter=field.querySelector('.ef-ac-reference')?.value||'';
      const input=field.querySelector('input[type="number"]');
      const status=field.querySelector('[data-ref-status]');
      const value=Number(input?.value);
      const deviceValue=value;
      if(!deviceId||!parameter){
        status.textContent='กรุณาเลือก Parameter ที่ต้องการเขียน';
        status.className='ef-ref-write-status error';
        return;
      }
      if(!Number.isFinite(value)){
        status.textContent='กรุณากรอกค่าที่ถูกต้อง';
        status.className='ef-ref-write-status error';
        return;
      }
      referenceSend.disabled=true;
      status.textContent='กำลังส่งคำสั่ง '+parameter+'...';
      status.className='ef-ref-write-status';
      fetch('./mx_fn_api.php?action=device_parameter_write',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'device_id='+encodeURIComponent(deviceId)+'&parameter='+encodeURIComponent(parameter)+'&value='+encodeURIComponent(deviceValue)
      }).then(async response=>{
        const text=await response.text();
        let payload={};
        try{payload=JSON.parse(text);}catch(e){payload={error:text};}
        if(!response.ok||payload.error)throw new Error(payload.error||('HTTP '+response.status));
        return payload;
      }).then(payload=>{
        status.textContent='ส่งแล้ว · '+parameter+' = '+deviceValue+' · '+payload.cmd_id;
        status.className='ef-ref-write-status success';
        setTimeout(loadBatteryMqttHistory,1200);
      }).catch(error=>{
        status.textContent='ส่งไม่สำเร็จ: '+error.message;
        status.className='ef-ref-write-status error';
      }).finally(()=>referenceSend.disabled=!canEditAutomation);
      return;
    }
    const pmaxButton=event.target.closest('[data-pmax-send]');
    if(pmaxButton){
      event.preventDefault();
      const root=pmaxButton.closest('[data-pmax-device]');
      const input=root.querySelector('[data-pmax-input]');
      const status=root.querySelector('[data-pmax-status]');
      const deviceId=root.dataset.pmaxDevice;
      const value=Number(input.value);
      if(!Number.isFinite(value)||value<Number(input.min)||value>Number(input.max)){
        status.textContent='กรุณากรอกค่าระหว่าง '+input.min+' ถึง '+input.max+' kW';
        status.className='ef-pmax-status error';
        return;
      }
      pmaxButton.disabled=true;
      status.textContent='กำลังส่งคำสั่ง...';
      status.className='ef-pmax-status';
      fetch('./mx_fn_api.php?action=battery_pmax',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'device_id='+encodeURIComponent(deviceId)+'&p_max='+encodeURIComponent(value)
      }).then(async response=>{
        const text=await response.text();
        let payload={};
        try{payload=JSON.parse(text);}catch(e){payload={error:text};}
        if(!response.ok||payload.error) throw new Error(payload.error||('HTTP '+response.status));
        return payload;
      }).then(payload=>{
        status.textContent='ส่งแล้ว · '+payload.cmd_id;
        status.className='ef-pmax-status success';
        root.querySelector('[data-pmax-current]').textContent=fmt(value);
        setTimeout(loadBatteryMqttHistory,1200);
      }).catch(error=>{
        status.textContent='ส่งไม่สำเร็จ: '+error.message;
        status.className='ef-pmax-status error';
      }).finally(()=>pmaxButton.disabled=false);
      return;
    }
    const trigger=event.target.closest('[data-ac-section],[data-ac-device]');
    if(trigger){
      event.preventDefault();
      const section=trigger.dataset.acSection||'device';
      const deviceId=trigger.dataset.acDevice||'';
      fetch('./mx_fn_api.php?action=automation_config',{cache:'no-store'})
        .then(async response=>{
          const text=await response.text();
          if(!response.ok)throw new Error(text);
          return JSON.parse(text);
        })
        .then(latest=>{if(latest&&typeof latest==='object'&&!Array.isArray(latest))automationConfig=latest;})
        .catch(error=>console.warn('Automation config refresh failed:',error))
        .finally(()=>openAutomationEditor(section,deviceId));
    }
    if(event.target.closest('[data-ac-close]')) closeAutomationEditor();
  });
  document.addEventListener('change',event=>{
    if(event.target.matches('#efAcControlEnabled')){
      const root=event.target.closest('[data-battery-device],[data-solar-device]')||acBody;
      syncDeviceControl(root);
      return;
    }
    if(event.target.matches('#efAcRole')){
      const root=event.target.closest('[data-battery-device],[data-solar-device]')||acBody;
      syncRoleControl(root);
      const deviceId=root.dataset.batteryDevice||root.dataset.solarDevice||activeAcEditor?.deviceId||'';
      if(deviceId)syncReferenceInputs(root,deviceId,true);
      return;
    }
    if(!event.target.matches('.ef-ac-reference')) return;
    const root=event.target.closest('[data-battery-device],[data-solar-device]')||acBody;
    const deviceId=root.dataset.batteryDevice||root.dataset.solarDevice||activeAcEditor?.deviceId||'';
    if(deviceId) syncReferenceInputs(root,deviceId,true);
  });
  document.addEventListener('input',event=>{
    if(!event.target.matches('#efAcMinKw,#efAcMaxKw')) return;
    const field=event.target.closest('.ef-ref-field');
    const reference=field?.querySelector('.ef-ac-reference')?.value||'Manual';
    const summary=field?.querySelector('[data-ref-summary]');
    if(summary) summary.textContent=reference+' · '+event.target.value+' kW';
  });
  document.addEventListener('change',event=>{
    if(event.target.id==='efAcStrategyEnabled'){
      const fields=event.target.closest('.ef-strategy-box')?.querySelector('.ef-strategy-fields');
      if(fields) fields.classList.toggle('is-hidden',!event.target.checked);
      return;
    }
    if(event.target.id==='efAcBatterySelect'){
      selectedBatteryAutomationId=event.target.value;
      acBody.querySelectorAll('[data-battery-panel]').forEach(panel=>{
        panel.hidden=panel.dataset.batteryPanel!==selectedBatteryAutomationId;
      });
    }
  });
  document.getElementById('efAcSave').addEventListener('click',()=>{
    if(!activeAcEditor) return;
    collectAutomationEditor();
    const button=document.getElementById('efAcSave');
    button.disabled=true;
    acStatus.textContent='กำลังบันทึก...';
    fetch('./mx_fn_api.php?action=automation_config',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'dataE='+encodeURIComponent(JSON.stringify(automationConfig))
    }).then(async response=>{
      const text=await response.text();
      if(!response.ok) throw new Error(text);
      return JSON.parse(text);
    }).then(saved=>{
      automationConfig=saved;
      acStatus.textContent='บันทึกเรียบร้อย';
      loadEnergyHistory(true);
      setTimeout(closeAutomationEditor,650);
    }).catch(error=>{
      acStatus.textContent='บันทึกไม่สำเร็จ: '+error.message;
    }).finally(()=>button.disabled=false);
  });
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&acModal.classList.contains('open'))closeAutomationEditor();});

  function applyFlowColors(lineId,colors){
    const path=document.getElementById(lineId+'Path');
    const svg=document.getElementById('efFlowSvg');
    if(!path||!svg) return;
    const unique=[...new Set(colors.filter(Boolean))];
    const signature=unique.join('|');
    if(path.dataset.colorSignature===signature) return;
    path.dataset.colorSignature=signature;
    svg.querySelectorAll('[data-flow-parent="'+lineId+'"]').forEach(layer=>layer.remove());
    if(!unique.length){
      path.style.stroke='';
      return;
    }
    if(unique.length===1){
      path.style.stroke=unique[0];
      return;
    }
    const ns='http://www.w3.org/2000/svg';
    path.style.stroke='transparent';
    const spacing=16;
    const cycle=spacing*unique.length;
    unique.forEach((color,index)=>{
      const layer=document.createElementNS(ns,'path');
      layer.setAttribute('d',path.getAttribute('d'));
      layer.setAttribute('class','ef-svg-flow ef-color-layer'+(path.classList.contains('reverse')?' reverse':'')+(path.classList.contains('idle')?' idle':''));
      // Inline style must override .ef-svg-flow { stroke: currentColor }.
      layer.style.stroke=color;
      layer.style.color=color;
      layer.dataset.flowParent=lineId;
      layer.style.setProperty('--ef-color-gap',(cycle-3)+'px');
      layer.style.setProperty('--ef-start-offset',(-spacing*index)+'px');
      layer.style.setProperty('--ef-color-cycle',cycle+'px');
      svg.appendChild(layer);
    });
  }

  // Draw each connector directly from the edge of its device card to the edge
  // of the circular hub. This keeps every line aimed at .ef-hub at any viewport.
  function positionEnergyLines(){
    const board = document.querySelector('.ef-board');
    const hub = document.querySelector('.ef-hub');
    if (!board || !hub) return;
    const ns='http://www.w3.org/2000/svg';
    let svg=document.getElementById('efFlowSvg');
    if(!svg){
      svg=document.createElementNS(ns,'svg');
      svg.id='efFlowSvg';
      svg.setAttribute('class','ef-flow-svg');
      svg.setAttribute('aria-hidden','true');
      board.insertBefore(svg,board.firstChild);
    }
    const boardRect = board.getBoundingClientRect();
    svg.setAttribute('viewBox','0 0 '+boardRect.width+' '+boardRect.height);
    const hubRect = hub.getBoundingClientRect();
    const hubCenter = {
      x: hubRect.left - boardRect.left + hubRect.width / 2,
      y: hubRect.top - boardRect.top + hubRect.height / 2
    };
    const cardGeometry = el => {
      const r = el.getBoundingClientRect();
      return {
        center:{x:r.left-boardRect.left+r.width/2,y:r.top-boardRect.top+r.height/2},
        halfW:r.width/2, halfH:r.height/2
      };
    };
    const place = (lineId, nodeId, nodeToHub) => {
      const line=document.getElementById(lineId);
      const node=document.getElementById(nodeId);
      if(!line||!node) return;
      const card=cardGeometry(node);
      const leftSide=card.center.x<hubCenter.x;
      const hubRadius=hubRect.width/2;
      const portOffset=(card.center.y<hubCenter.y?-1:1)*hubRect.height*.24;
      const portX=Math.sqrt(Math.max(0,hubRadius*hubRadius-portOffset*portOffset));
      const hubPort={
        x:hubCenter.x+(leftSide?-portX:portX),
        y:hubCenter.y+portOffset
      };
      const start=nodeToHub
        ? {x:leftSide?card.center.x+card.halfW:card.center.x-card.halfW,y:card.center.y}
        : hubPort;
      const end=nodeToHub
        ? hubPort
        : {x:leftSide?card.center.x+card.halfW:card.center.x-card.halfW,y:card.center.y};
      const horizontal=Math.max(24,Math.abs(end.x-start.x)*.64);
      let d;
      if(nodeToHub){
        const bendX=start.x+(leftSide?horizontal:-horizontal);
        d=`M ${start.x} ${start.y} H ${bendX} C ${bendX+(leftSide?28:-28)} ${start.y}, ${end.x} ${end.y-(end.y-start.y)*.3}, ${end.x} ${end.y}`;
      }else{
        const bendX=end.x+(leftSide?horizontal:-horizontal);
        d=`M ${start.x} ${start.y} C ${start.x} ${start.y+(end.y-start.y)*.7}, ${bendX+(leftSide?28:-28)} ${end.y}, ${bendX} ${end.y} H ${end.x}`;
      }
      let track=document.getElementById(lineId+'Track');
      let flow=document.getElementById(lineId+'Path');
      if(!track){
        track=document.createElementNS(ns,'path');
        track.id=lineId+'Track';
        track.setAttribute('class','ef-svg-track');
        svg.appendChild(track);
      }
      if(!flow){
        flow=document.createElementNS(ns,'path');
        flow.id=lineId+'Path';
        flow.setAttribute('class','ef-svg-flow');
        flow.style.color=getComputedStyle(line).color;
        svg.appendChild(flow);
      }
      track.setAttribute('d',d);
      flow.setAttribute('d',d);
      svg.querySelectorAll('[data-flow-parent="'+lineId+'"]').forEach(layer=>layer.setAttribute('d',d));
      flow.classList.toggle('idle',line.classList.contains('idle'));
      flow.classList.toggle('reverse',line.classList.contains('reverse'));
      const length=flow.getTotalLength();
      // Keep labels nearer their device cards and away from the central hub.
      const mid=flow.getPointAtLength(length*(nodeToHub ? .40 : .60));
      line.style.left=mid.x+'px';
      line.style.top=mid.y+'px';
    };
    place('efLineSolar','efSolar',true);
    place('efLineGrid','efGrid',true);
    place('efLineLoad','efLoad',false);
    place('efLineBattery','efBattery',false);
  }
  requestAnimationFrame(()=>requestAnimationFrame(positionEnergyLines));
  window.addEventListener('resize',positionEnergyLines);
  if (window.ResizeObserver) new ResizeObserver(positionEnergyLines).observe(document.querySelector('.ef-board'));

  const settingsPanel = document.getElementById('efSettings');
  const mainMeterEl = document.getElementById('efMainMeter');
  const modeEl = document.getElementById('efEnergyMode');
  const meterSignEl = document.getElementById('efMeterSign');
  const batterySignEl = document.getElementById('efBatterySign');
  const meterChoices = Object.keys(devices).filter(id => typeOf(id)==='meter');
  // Keep all configured devices available as a fallback in case a custom model
  // has not yet been assigned the "meter" type in detail_rgl.json.
  const selectableMeters = meterChoices.length ? meterChoices : Object.keys(devices);
  mainMeterEl.innerHTML = selectableMeters.map(id => '<option value="'+esc(id)+'">'+esc(nameOf(id))+' · '+esc(id)+'</option>').join('');

  function fillSettingsForm(){
    mainMeterEl.value = settings.mainMeter;
    modeEl.value = settings.energyMode;
    meterSignEl.value = settings.meterSign;
    batterySignEl.value = settings.batterySign;
    updateModeHint();
  }
  function updateModeHint(){
    document.getElementById('efModeHint').textContent = modeEl.value==='grid'
      ? 'คำนวณ Load จาก Solar + Grid + Battery'
      : 'ใช้ค่าจาก Main Meter เป็น Facility Load และคำนวณ Grid';
  }
  document.getElementById('efSettingsBtn').onclick=()=>{fillSettingsForm();settingsPanel.style.display='';};
  document.getElementById('efSettingsClose').onclick=()=>{settingsPanel.style.display='none';};
  modeEl.onchange=updateModeHint;
  document.getElementById('efSettingsReset').onclick=()=>{
    settings=Object.assign({},defaults);
    localStorage.removeItem(SETTINGS_KEY);
    fillSettingsForm();
    if(lastPayload) render(lastPayload);
    loadEnergyHistory();
    loadConsumptionSummary();
  };
  document.getElementById('efSettingsSave').onclick=()=>{
    settings=validSettings({
      mainMeter:mainMeterEl.value,
      energyMode:modeEl.value,
      meterSign:meterSignEl.value,
      batterySign:batterySignEl.value
    });
    localStorage.setItem(SETTINGS_KEY,JSON.stringify(settings));
    settingsPanel.style.display='none';
    if(lastPayload) render(lastPayload);
    loadEnergyHistory();
    loadConsumptionSummary();
  };
  fillSettingsForm();

  const historyPeriod=document.getElementById('efHistoryPeriod');
  const historyMetric=document.getElementById('efHistoryMetric');
  const historyType=document.getElementById('efHistoryType');
  const historyInterval=document.getElementById('efHistoryInterval');
  const historyIntervalWrap=document.getElementById('efHistoryIntervalWrap');
  const historyDate=document.getElementById('efHistoryDate');
  const historyDateWrap=document.getElementById('efHistoryDateWrap');
  const historyMonth=document.getElementById('efHistoryMonth');
  const historyMonthWrap=document.getElementById('efHistoryMonthWrap');
  const historyYear=document.getElementById('efHistoryYear');
  const historyYearWrap=document.getElementById('efHistoryYearWrap');
  let historyLoading=false;
  let historyRenderedType=null;
  let historyRenderedView='';
  let historyRenderedLabels='';
  let historyPayloadCache=null;
  let historyPayloadCacheKey='';
  let historyConsumptionValues=[];
  let historyExtremePoints={min:null,max:null};
  const normaliseEnergyUnit=unit=>String(unit).toLowerCase()==='kwh'?'kWh':String(unit);
  let historyUnit=normaliseEnergyUnit(parameterList.p_t?.unit||'kW');
  const todayLocal=()=>new Date().toLocaleDateString('en-CA');
  historyDate.max=todayLocal();
  historyDate.value=todayLocal();
  historyMonth.max=todayLocal().slice(0,7);
  historyMonth.value=todayLocal().slice(0,7);
  historyYear.max=String(new Date().getFullYear());
  historyYear.value=String(new Date().getFullYear());
  try{
    const savedHistory=JSON.parse(localStorage.getItem('mdbiot_energy_history_settings')||'{}');
    if(['day','month','year'].includes(savedHistory.period)) historyPeriod.value=savedHistory.period;
    if(['power','energy'].includes(savedHistory.metric)) historyMetric.value=savedHistory.metric;
    if(['1','5','10','15','60'].includes(String(savedHistory.interval||''))) historyInterval.value=String(savedHistory.interval);
    historyType.value='bar';
    if(/^\d{4}-\d{2}-\d{2}$/.test(savedHistory.date||'') && savedHistory.date<=todayLocal()) historyDate.value=savedHistory.date;
    if(/^\d{4}-\d{2}$/.test(savedHistory.month||'') && savedHistory.month<=todayLocal().slice(0,7)) historyMonth.value=savedHistory.month;
    if(/^\d{4}$/.test(String(savedHistory.year||'')) && Number(savedHistory.year)>=2000 && Number(savedHistory.year)<=new Date().getFullYear()) historyYear.value=String(savedHistory.year);
  }catch(e){}
  const historyCalendar=document.getElementById('efHistoryCalendar');
  const historyCalendarGrid=document.getElementById('efHistoryCalendarGrid');
  const historyDateParts=historyDate.value.split('-').map(Number);
  let historyCalendarMonth=new Date(historyDateParts[0],historyDateParts[1]-1,1);
  const historyCalendarKey=date=>date.getFullYear()+'-'+String(date.getMonth()+1).padStart(2,'0')+'-'+String(date.getDate()).padStart(2,'0');
  function renderHistoryCalendar(){
    document.getElementById('efHistoryCalendarLabel').textContent=new Intl.DateTimeFormat('th-TH',{month:'long',year:'numeric'}).format(historyCalendarMonth);
    const first=new Date(historyCalendarMonth.getFullYear(),historyCalendarMonth.getMonth(),1);
    const start=new Date(first);start.setDate(1-first.getDay());
    const today=todayLocal();historyCalendarGrid.replaceChildren();
    for(let index=0;index<42;index++){
      const date=new Date(start);date.setDate(start.getDate()+index);
      const key=historyCalendarKey(date),button=document.createElement('button');
      button.type='button';button.textContent=date.getDate();button.dataset.historyDate=key;
      if(date.getMonth()!==historyCalendarMonth.getMonth())button.classList.add('outside');
      if(key===today)button.classList.add('today');
      if(key===historyDate.value)button.classList.add('selected');
      if(key>today)button.disabled=true;
      historyCalendarGrid.appendChild(button);
    }
  }
  function openHistoryCalendar(){const parts=historyDate.value.split('-').map(Number);historyCalendarMonth=new Date(parts[0],parts[1]-1,1);renderHistoryCalendar();historyCalendar.hidden=false;}
  document.getElementById('efHistoryDateButton').addEventListener('click',event=>{event.preventDefault();event.stopPropagation();historyCalendar.hidden?openHistoryCalendar():historyCalendar.hidden=true;});
  historyDate.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();openHistoryCalendar();});
  historyCalendar.addEventListener('click',event=>{event.preventDefault();event.stopPropagation();const monthButton=event.target.closest('[data-history-month]');if(monthButton){historyCalendarMonth.setMonth(historyCalendarMonth.getMonth()+Number(monthButton.dataset.historyMonth));renderHistoryCalendar();return;}const dayButton=event.target.closest('[data-history-date]:not(:disabled)');if(!dayButton)return;historyDate.value=dayButton.dataset.historyDate;historyCalendar.hidden=true;historyDate.dispatchEvent(new Event('change',{bubbles:true}));});
  document.addEventListener('click',event=>{if(!event.target.closest('.ef-history-date-picker'))historyCalendar.hidden=true;});
  function syncHistoryDate(){
    historyDateWrap.style.display=historyPeriod.value==='day'?'':'none';
    historyMonthWrap.style.display=historyPeriod.value==='month'?'':'none';
    historyYearWrap.style.display=historyPeriod.value==='year'?'':'none';
    historyIntervalWrap.style.display=historyPeriod.value==='day'?'':'none';
  }
  function syncHistoryMetric(){
    const energy=historyMetric.value==='energy';
    historyUnit=normaliseEnergyUnit(energy?(parameterList.e_p?.unit||'kWh'):(parameterList.p_t?.unit||'kW'));
    document.getElementById('efHistoryTitle').textContent=energy?'Active Energy Summary':'Active Power Summary';
    document.getElementById('efHistoryMinLabel').textContent=(energy?'พลังงาน':'กำลัง')+'ต่ำสุด (Min)';
    document.getElementById('efHistoryMaxLabel').textContent=(energy?'พลังงาน':'กำลัง')+'สูงสุด (Max)';
    document.getElementById('efHistoryAvgLabel').textContent=energy?'พลังงานที่ใช้ทั้งหมด (Total)':'กำลังเฉลี่ย (Avg)';
    document.getElementById('efHistorySolarTotalCard').style.display=energy?'':'none';
    document.getElementById('efHistoryBatteryChargeCard').style.display=energy?'':'none';
    document.getElementById('efHistoryBatteryDischargeCard').style.display=energy?'':'none';
    ['efHistoryMin','efHistoryMax','efHistoryAvg','efHistorySolarTotal','efHistoryBatteryCharge','efHistoryBatteryDischarge'].forEach(id=>document.getElementById(id).textContent='— '+historyUnit);
  }
  syncHistoryDate();
  syncHistoryMetric();
  function historyPower(value){
    return Number(value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})+' '+historyUnit;
  }
  function showHistoryExtreme(type){
    const point=historyExtremePoints[type];
    if(!historyChart||!point) return;
    try{historyChart.removeAnnotation('ef-history-extreme');}catch(e){}
    historyChart.addXaxisAnnotation({
      id:'ef-history-extreme',
      x:point.label,
      borderColor:type==='min'?'#1769d2':'#e34b4b',
      strokeDashArray:3,
      label:{
        orientation:'horizontal',
        position:'top',
        offsetY:-2,
        borderColor:type==='min'?'#1769d2':'#e34b4b',
        style:{background:type==='min'?'#1769d2':'#e34b4b',color:'#fff',fontSize:'11px',fontWeight:700},
        text:(type==='min'?'Min ':'Max ')+historyPower(point.value)+' · '+point.label
      }
    });
    document.getElementById('efHistoryChart').scrollIntoView({behavior:'smooth',block:'center'});
  }
  ['min','max'].forEach(type=>{
    const card=document.getElementById(type==='min'?'efHistoryMinCard':'efHistoryMaxCard');
    card.addEventListener('click',()=>showHistoryExtreme(type));
    card.addEventListener('keydown',event=>{
      if(event.key==='Enter'||event.key===' '){event.preventDefault();showHistoryExtreme(type);}
    });
  });
  let consumptionSummaryLoading=false;
  let liveEnergySummary=null;
  let energyDonutChart=null;
  let energyDonutSelectedIndex=null;
  const energyDonutPeriod=document.getElementById('efEnergyDonutPeriod');
  energyDonutPeriod.value=localStorage.getItem('mdbiot_energy_donut_period')||'today';
  const summaryEnergyUnit=()=>normaliseEnergyUnit(parameterList.e_p?.unit||'kWh');
  const displaySummaryEnergy=value=>Number(value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})+' '+summaryEnergyUnit();
  const energyDonutLabels=['พลังงานที่ใช้จริง','Solar ผลิตได้','เก็บเข้าแบตเตอรี่','จ่ายออกจากแบตเตอรี่'];
  const energyDonutColors=['#1769d2','#f5a623','#25a56a','#7457d9'];
  function showEnergyDonutSelection(index,values){
    if(index<0||index>=energyDonutLabels.length) return;
    energyDonutSelectedIndex=index;
    const selected=document.getElementById('efEnergyDonutSelected');
    selected.style.display='inline-flex';
    selected.style.color=energyDonutColors[index];
    selected.innerHTML='<i style="background:'+energyDonutColors[index]+'"></i><span>'+energyDonutLabels[index]+'</span><b>'+displaySummaryEnergy(values[index]||0)+'</b>';
  }
  function renderLiveEnergySummary(){
    if(!liveEnergySummary) return;
    const period=energyDonutPeriod.value;
    const values=[liveEnergySummary.consumption[period],liveEnergySummary.solar[period],liveEnergySummary.charge[period],liveEnergySummary.discharge[period]].map(value=>Math.max(0,Number(value)||0));
    document.getElementById('efDonutConsumption').textContent=displaySummaryEnergy(values[0]);
    document.getElementById('efDonutSolar').textContent=displaySummaryEnergy(values[1]);
    document.getElementById('efDonutCharge').textContent=displaySummaryEnergy(values[2]);
    document.getElementById('efDonutDischarge').textContent=displaySummaryEnergy(values[3]);
    document.getElementById('efEnergyDonutTotal').textContent=displaySummaryEnergy(values.reduce((sum,value)=>sum+value,0));
    if(energyDonutSelectedIndex!==null) showEnergyDonutSelection(energyDonutSelectedIndex,values);
    if(typeof ApexCharts==='undefined') return;
    if(energyDonutChart){energyDonutChart.updateSeries(values,false);return;}
    energyDonutChart=new ApexCharts(document.getElementById('efEnergyDonutChart'),{
      chart:{type:'donut',height:285,fontFamily:'inherit',background:'transparent',foreColor:efChartText(),animations:{enabled:true},events:{dataPointSelection:(event,chartContext,config)=>showEnergyDonutSelection(config.dataPointIndex,config.w.config.series)}},
      theme:{mode:efChartDark()?'dark':'light'},
      series:values,
      labels:energyDonutLabels,
      colors:energyDonutColors,
      stroke:{colors:[efChartDark()?'#142235':'#fff'],width:3},
      legend:{show:false},
      dataLabels:{enabled:false},
      plotOptions:{pie:{expandOnClick:true,donut:{size:'68%',labels:{show:false}}}},
      tooltip:{y:{formatter:value=>displaySummaryEnergy(value)}},
      noData:{text:'ไม่มีข้อมูลพลังงาน'}
    });
    energyDonutChart.render();
  }
  energyDonutPeriod.addEventListener('change',()=>{
    localStorage.setItem('mdbiot_energy_donut_period',energyDonutPeriod.value);
    energyDonutSelectedIndex=null;
    document.getElementById('efEnergyDonutSelected').style.display='none';
    if(energyDonutChart){energyDonutChart.destroy();energyDonutChart=null;}
    renderLiveEnergySummary();
  });
  function updateLiveEnergySummary(consumptionPower,solarPower,batteryPower){
    if(!liveEnergySummary) return;
    const now=Date.now();
    const dateKey=todayLocal(),monthKey=dateKey.slice(0,7),yearKey=dateKey.slice(0,4);
    if(liveEnergySummary.dateKey!==dateKey) liveEnergySummary.consumption.today=liveEnergySummary.solar.today=liveEnergySummary.charge.today=liveEnergySummary.discharge.today=0;
    if(liveEnergySummary.monthKey!==monthKey) liveEnergySummary.consumption.month=liveEnergySummary.solar.month=liveEnergySummary.charge.month=liveEnergySummary.discharge.month=0;
    if(liveEnergySummary.yearKey!==yearKey) liveEnergySummary.consumption.year=liveEnergySummary.solar.year=liveEnergySummary.charge.year=liveEnergySummary.discharge.year=0;
    const seconds=(now-liveEnergySummary.timestamp)/1000;
    if(seconds>0&&seconds<=15&&liveEnergySummary.previousConsumption!==null&&liveEnergySummary.previousSolar!==null&&liveEnergySummary.previousCharge!==null&&liveEnergySummary.previousDischarge!==null){
      const consumptionKwh=((liveEnergySummary.previousConsumption+Math.max(0,Number(consumptionPower)||0))/2)*(seconds/3600);
      const solarKwh=((liveEnergySummary.previousSolar+Math.max(0,Number(solarPower)||0))/2)*(seconds/3600);
      const chargePower=Math.max(0,-(Number(batteryPower)||0));
      const chargeKwh=((liveEnergySummary.previousCharge+chargePower)/2)*(seconds/3600);
      const dischargePower=Math.max(0,Number(batteryPower)||0);
      const dischargeKwh=((liveEnergySummary.previousDischarge+dischargePower)/2)*(seconds/3600);
      ['today','month','year'].forEach(key=>{
        liveEnergySummary.consumption[key]+=consumptionKwh;
        liveEnergySummary.solar[key]+=solarKwh;
        liveEnergySummary.charge[key]+=chargeKwh;
        liveEnergySummary.discharge[key]+=dischargeKwh;
      });
    }
    liveEnergySummary.timestamp=now;
    liveEnergySummary.previousConsumption=consumptionPower===null?null:Math.max(0,Number(consumptionPower)||0);
    liveEnergySummary.previousSolar=solarPower===null?null:Math.max(0,Number(solarPower)||0);
    liveEnergySummary.previousCharge=batteryPower===null?null:Math.max(0,-(Number(batteryPower)||0));
    liveEnergySummary.previousDischarge=batteryPower===null?null:Math.max(0,Number(batteryPower)||0);
    liveEnergySummary.dateKey=dateKey;liveEnergySummary.monthKey=monthKey;liveEnergySummary.yearKey=yearKey;
    renderLiveEnergySummary();
  }
  function loadConsumptionSummary(silent=false){
    if(consumptionSummaryLoading||document.hidden) return;
    const meter=settings.mainMeter;
    const status=document.getElementById('efConsumptionSummaryStatus');
    if(!meter){
      status.textContent='กรุณาเลือก Main Meter';
      return;
    }
    consumptionSummaryLoading=true;
    if(!silent) status.textContent='กำลังคำนวณจากข้อมูลการใช้พลังงานจริง…';
    const url='./energy_usage_api.php?meterSN='+encodeURIComponent(meter)+'&period=year&metric=energy&interval=60&sign='+encodeURIComponent(settings.meterSign)+'&batterySign='+encodeURIComponent(settings.batterySign);
    fetch(url,{cache:'no-store'}).then(async response=>{
      const payload=await response.json();
      if(!response.ok||payload.error) throw new Error(payload.error||('HTTP '+response.status));
      return payload;
    }).then(payload=>{
      const summary=payload.consumption_summary_kwh||{};
      const solarSummary=payload.solar_summary_kwh||{};
      const chargeSummary=payload.battery_charge_summary_kwh||{};
      const dischargeSummary=payload.battery_discharge_summary_kwh||{};
      const dateKey=todayLocal();
      liveEnergySummary={
        consumption:{today:Number(summary.today)||0,month:Number(summary.month)||0,year:Number(summary.year)||0},
        solar:{today:Number(solarSummary.today)||0,month:Number(solarSummary.month)||0,year:Number(solarSummary.year)||0},
        charge:{today:Number(chargeSummary.today)||0,month:Number(chargeSummary.month)||0,year:Number(chargeSummary.year)||0},
        discharge:{today:Number(dischargeSummary.today)||0,month:Number(dischargeSummary.month)||0,year:Number(dischargeSummary.year)||0},
        timestamp:Date.now(),previousConsumption:null,previousSolar:null,previousCharge:null,previousDischarge:null,
        dateKey,monthKey:dateKey.slice(0,7),yearKey:dateKey.slice(0,4)
      };
      renderLiveEnergySummary();
      status.textContent='อัปเดตล่าสุด '+new Date().toLocaleTimeString('th-TH',{hour12:false});
    }).catch(error=>{
      status.textContent='โหลดข้อมูลสรุปไม่สำเร็จ: '+error.message;
    }).finally(()=>consumptionSummaryLoading=false);
  }
  function historyAnnotations(){
    const annotations=[{y:0,borderColor:'#8a98a8',strokeDashArray:0}];
    if(historyMetric.value==='energy') return annotations;
    const limit=Number(automationConfig?.system_power_limit?.max_kw);
    if(Number.isFinite(limit)&&limit>0){
      annotations.push({
        y:limit,
        borderColor:'#e34b4b',
        strokeDashArray:6,
        borderWidth:2,
        label:{
          borderColor:'#e34b4b',
          position:'right',
          offsetX:-8,
          style:{background:'#e34b4b',color:'#fff',fontSize:'11px',fontWeight:700},
          text:'Peak Limit '+historyPower(limit)
        }
      });
    }
    return annotations;
  }
  function loadEnergyHistory(silent=false){
    if(historyLoading) return;
    historyLoading=true;
    const historyChartElement=document.getElementById('efHistoryChart');
    historyChartElement.style.display='';
    if(!silent) historyChartElement.classList.add('is-loading');
    const meter=settings.mainMeter;
    const period=historyPeriod.value;
    const metric=historyMetric.value;
    const interval=historyInterval.value;
    const chartType=historyType.value;
    const selectedDate=historyDate.value||todayLocal();
    const selectedMonth=historyMonth.value||todayLocal().slice(0,7);
    const selectedYear=historyYear.value||String(new Date().getFullYear());
    const requestKey=[meter,period,metric,interval,selectedDate,selectedMonth,selectedYear,settings.meterSign,settings.batterySign].join('|');
    const useTail=silent&&period==='day'&&selectedDate===todayLocal()&&historyPayloadCache&&historyPayloadCacheKey===requestKey;
    localStorage.setItem('mdbiot_energy_history_settings',JSON.stringify({period,metric,interval,type:chartType,date:selectedDate,month:selectedMonth,year:selectedYear}));
    if(!silent) document.getElementById('efHistoryRange').textContent='กำลังโหลดข้อมูล '+(meter||'Main Meter')+'...';
    if(!meter||typeof ApexCharts==='undefined'){
      document.getElementById('efHistoryEmpty').style.display='';
      historyChartElement.classList.remove('is-loading');
      historyLoading=false;
      return;
    }
    const url='./energy_usage_api.php?meterSN='+encodeURIComponent(meter)+'&period='+encodeURIComponent(period)+'&metric='+encodeURIComponent(metric)+'&interval='+encodeURIComponent(interval)+'&date='+encodeURIComponent(selectedDate)+'&month='+encodeURIComponent(selectedMonth)+'&year='+encodeURIComponent(selectedYear)+'&sign='+encodeURIComponent(settings.meterSign)+'&batterySign='+encodeURIComponent(settings.batterySign)+(useTail?'&tail=1':'');
    fetch(url,{cache:'no-store'}).then(async response=>{
      const payload=await response.json();
      if(!response.ok||payload.error) throw new Error(payload.error||('HTTP '+response.status));
      return payload;
    }).then(payload=>{
      if(useTail&&payload.labels?.length){
        const label=payload.labels[0];
        let index=historyPayloadCache.labels.indexOf(label);
        if(index<0){
          index=historyPayloadCache.labels.length;
          historyPayloadCache.labels.push(label);
          Object.values(historyPayloadCache.series||{}).forEach(series=>series.values.push(null));
        }
        Object.entries(payload.series||{}).forEach(([name,series])=>{
          if(!historyPayloadCache.series[name]) historyPayloadCache.series[name]={values:Array(historyPayloadCache.labels.length).fill(null),average_kw:0};
          while(historyPayloadCache.series[name].values.length<historyPayloadCache.labels.length) historyPayloadCache.series[name].values.push(null);
          historyPayloadCache.series[name].values[index]=series.values?.[0]??null;
        });
        historyPayloadCache.sample_count=Math.max(Number(historyPayloadCache.sample_count)||0,Number(payload.sample_count)||0);
        historyPayloadCache.interval_count=(Number(historyPayloadCache.interval_count)||0)+(Number(payload.interval_count)||0);
        payload=historyPayloadCache;
      }else{
        historyPayloadCache=payload;
        historyPayloadCacheKey=requestKey;
      }
      const sourceValues=['solar','grid','battery'].map(source=>payload.series?.[source]?.values||[]);
      const actualConsumptionValues=payload.series?.consumption?.values;
      const combinedValues=payload.labels.map((label,index)=>{
        const actual=actualConsumptionValues?.[index];
        if(actual!==null && actual!==undefined && Number.isFinite(Number(actual))) return Number(actual);
        const values=sourceValues.map(values=>values[index]).filter(value=>value!==null && value!==undefined && Number.isFinite(Number(value)));
        return values.length ? Math.max(0,values.reduce((sum,value)=>sum+Number(value),0)) : null;
      });
      historyConsumptionValues=combinedValues;
      const validValues=combinedValues.filter(value=>value!==null);
      const total=validValues.reduce((sum,value)=>sum+value,0);
      const average=validValues.length ? total/validValues.length : 0;
      const solarTotal=sourceValues[0].reduce((sum,value)=>value!==null&&value!==undefined&&Number.isFinite(Number(value))?sum+Number(value):sum,0);
      const chargeValues=payload.series?.battery_charge?.values||[];
      const dischargeValues=payload.series?.battery_discharge?.values||[];
      const sumEnergyValues=values=>values.reduce((sum,value)=>value!==null&&value!==undefined&&Number.isFinite(Number(value))?sum+Number(value):sum,0);
      const minValue=validValues.length?Math.min(...validValues):null;
      const maxValue=validValues.length?Math.max(...validValues):null;
      historyExtremePoints={
        min:minValue===null?null:{value:minValue,index:combinedValues.indexOf(minValue),label:payload.labels[combinedValues.indexOf(minValue)]},
        max:maxValue===null?null:{value:maxValue,index:combinedValues.indexOf(maxValue),label:payload.labels[combinedValues.indexOf(maxValue)]}
      };
      document.getElementById('efHistoryMin').textContent=minValue!==null?historyPower(minValue):'— '+historyUnit;
      document.getElementById('efHistoryMax').textContent=maxValue!==null?historyPower(maxValue):'— '+historyUnit;
      document.getElementById('efHistoryAvg').textContent=validValues.length?historyPower(metric==='energy'?total:average):'— '+historyUnit;
      document.getElementById('efHistorySolarTotal').textContent=metric==='energy'&&sourceValues[0].some(value=>value!==null&&value!==undefined)?historyPower(solarTotal):'— '+historyUnit;
      document.getElementById('efHistoryBatteryCharge').textContent=metric==='energy'&&chargeValues.some(value=>value!==null&&value!==undefined)?historyPower(sumEnergyValues(chargeValues)):'— '+historyUnit;
      document.getElementById('efHistoryBatteryDischarge').textContent=metric==='energy'&&dischargeValues.some(value=>value!==null&&value!==undefined)?historyPower(sumEnergyValues(dischargeValues)):'— '+historyUnit;
      const intervalText=interval==='1'?'1 นาที':interval==='5'?'5 นาที':interval==='10'?'10 นาที':interval==='15'?'15 นาที':'1 ชั่วโมง';
      document.getElementById('efHistoryRange').textContent=(period==='year'?'รายปี '+selectedYear+' (มกราคม–ธันวาคม)':period==='month'?'รายเดือน '+selectedMonth+' (สรุปแต่ละวัน)':'รายวัน '+selectedDate+' (ทุก '+intervalText+')')+' · '+nameOf(meter)+' · อัปเดต '+new Date().toLocaleTimeString('th-TH',{hour12:false});
      const hasData=Number(payload.sample_count||0)>0;
      document.getElementById('efHistoryEmpty').style.display=hasData?'none':'';
      document.getElementById('efHistoryChart').style.display=hasData?'':'none';
      if(!hasData) return;
      const emptyValues=payload.labels.map(()=>null);
      const chartSeries=[
        {name:'Grid',data:payload.series?.grid?.values||emptyValues},
        {name:'Solar',data:payload.series?.solar?.values||emptyValues},
        {name:'Battery',data:payload.series?.battery?.values||emptyValues}
      ];
      const viewKey=[meter,period,metric,interval,selectedDate,selectedMonth,selectedYear].join('|');
      const labelsKey=JSON.stringify(payload.labels);
      if(historyChart && historyRenderedType===chartType){
        if(!silent||historyRenderedView!==viewKey||historyRenderedLabels!==labelsKey){
          historyChart.updateOptions({
            xaxis:{categories:payload.labels},
            yaxis:{title:{text:(metric==='energy'?'Active Energy':'Active Power')+' ('+historyUnit+')'},labels:{formatter:value=>Number(value).toLocaleString(undefined,{maximumFractionDigits:1})}},
            annotations:{yaxis:historyAnnotations()}
          },false,false);
        }
        historyChart.updateSeries(chartSeries,false);
        historyRenderedView=viewKey;
        historyRenderedLabels=labelsKey;
        return;
      }
      if(historyChart){historyChart.destroy();historyChart=null;}
      historyRenderedType=chartType;
      historyRenderedView=viewKey;
      historyRenderedLabels=labelsKey;
      historyChart=new ApexCharts(document.getElementById('efHistoryChart'),{
        chart:{
          type:chartType,
          height:350,
          stacked:chartType==='bar',
          stackType:'normal',
          toolbar:{show:true},
          fontFamily:'inherit',
          background:'transparent',
          foreColor:efChartText(),
          animations:{enabled:true}
        },
        theme:{mode:efChartDark()?'dark':'light'},
        series:chartSeries,
        colors:['#74045f','#f5a623','#25a56a'],
        plotOptions:{bar:{
          borderRadius:3,
          borderRadiusApplication:'end',
          borderRadiusWhenStacked:'last',
          columnWidth:'68%'
        }},
        stroke:{curve:'smooth',width:chartType==='line'?3:0},
        markers:{size:chartType==='line'?2:0},
        fill:{opacity:chartType==='line'?.16:.88,type:chartType==='line'?'gradient':'solid',gradient:{shadeIntensity:.2,opacityFrom:.32,opacityTo:.05}},
        dataLabels:{enabled:false},
        legend:{position:'bottom',horizontalAlign:'center'},
        grid:{borderColor:efChartGrid(),strokeDashArray:4},
        annotations:{yaxis:historyAnnotations()},
        xaxis:{categories:payload.labels,labels:{rotate:period==='day'?-45:0,hideOverlappingLabels:true}},
        yaxis:{title:{text:(metric==='energy'?'Active Energy':'Active Power')+' ('+historyUnit+')'},labels:{formatter:value=>Number(value).toLocaleString(undefined,{maximumFractionDigits:1})}},
        tooltip:{shared:true,intersect:false,custom:({series,dataPointIndex,w})=>{
          const colors=['#74045f','#f5a623','#25a56a'];
          const rows=['Grid','Solar','Battery'].map((name,index)=>{
            const value=series[index]?.[dataPointIndex];
            const text=value===null||value===undefined?'—':Number(value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})+' '+historyUnit;
            return '<div class="ef-chart-tooltip-row"><span><i style="background:'+colors[index]+'"></i>'+name+'</span><b>'+text+'</b></div>';
          }).join('');
          const consumption=historyConsumptionValues[dataPointIndex];
          const totalText=consumption===null||consumption===undefined?'—':Number(consumption).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})+' '+historyUnit;
          return '<div class="ef-chart-tooltip"><strong>'+String(w.globals.labels[dataPointIndex]||'')+'</strong>'+rows+'<div class="ef-chart-tooltip-row ef-chart-tooltip-total"><span>'+(historyMetric.value==='energy'?'Total Energy':'Total Consumption')+'</span><b>'+totalText+'</b></div></div>';
        }},
        noData:{text:'ไม่พบข้อมูล'}
      });
      historyChart.render();
    }).catch(error=>{
      if(historyChart){historyChart.destroy();historyChart=null;}
      document.getElementById('efHistoryChart').style.display='none';
      const empty=document.getElementById('efHistoryEmpty');
      empty.style.display='';
      empty.textContent='โหลดข้อมูลไม่สำเร็จ: '+error.message;
      document.getElementById('efHistoryMin').textContent='— '+historyUnit;
      document.getElementById('efHistoryMax').textContent='— '+historyUnit;
      document.getElementById('efHistoryAvg').textContent='— '+historyUnit;
      document.getElementById('efHistorySolarTotal').textContent='— '+historyUnit;
      document.getElementById('efHistoryBatteryCharge').textContent='— '+historyUnit;
      document.getElementById('efHistoryBatteryDischarge').textContent='— '+historyUnit;
      historyExtremePoints={min:null,max:null};
    }).finally(()=>{historyLoading=false;historyChartElement.classList.remove('is-loading');});
  }
  historyPeriod.addEventListener('change',()=>{syncHistoryDate();loadEnergyHistory();});
  historyMetric.addEventListener('change',()=>{syncHistoryMetric();loadEnergyHistory();});
  historyDate.addEventListener('change',loadEnergyHistory);
  historyMonth.addEventListener('change',loadEnergyHistory);
  historyYear.addEventListener('change',()=>{
    const currentYear=new Date().getFullYear();
    historyYear.value=String(Math.max(2000,Math.min(currentYear,Number(historyYear.value)||currentYear)));
    loadEnergyHistory();
  });
  historyInterval.addEventListener('change',loadEnergyHistory);
  historyType.addEventListener('change',loadEnergyHistory);

  let mqttHistoryLoading=false;
  let mqttLogPage=1;
  let mqttLogPageSize=5;
  let mqttLogSort='datetime';
  let mqttLogSortDirection='desc';
  const mqttLogViewKey='mdbiot_battery_log_view_v1';
  const mqttDate=document.getElementById('efMqttDate');
  const mqttPageSize=document.getElementById('efLogPageSize');
  const mqttToday=new Date().toLocaleDateString('en-CA');
  mqttDate.value=mqttToday;
  try{const saved=JSON.parse(localStorage.getItem(mqttLogViewKey)||'{}');if(/^\d{4}-\d{2}-\d{2}$/.test(saved.date||'')&&saved.date<=mqttToday)mqttDate.value=saved.date;if(Number.isInteger(Number(saved.page))&&Number(saved.page)>0)mqttLogPage=Number(saved.page);if([5,10,25,50,100,200,500].includes(Number(saved.pageSize)))mqttLogPageSize=Number(saved.pageSize);if(['datetime','source','device','parameter','requested','actual','success','reason'].includes(saved.sort))mqttLogSort=saved.sort;if(['asc','desc'].includes(saved.sortDirection))mqttLogSortDirection=saved.sortDirection;}catch(error){}
  mqttPageSize.value=String(mqttLogPageSize);
  const saveMqttLogView=()=>{try{localStorage.setItem(mqttLogViewKey,JSON.stringify({date:mqttDate.value,page:mqttLogPage,pageSize:mqttLogPageSize,sort:mqttLogSort,sortDirection:mqttLogSortDirection}));}catch(error){}};
  const updateLogSortButtons=(table,key,direction)=>document.querySelectorAll('.ef-table-sort[data-sort-table="'+table+'"]').forEach(button=>{const active=button.dataset.sortKey===key;button.classList.toggle('active',active);button.classList.toggle('asc',active&&direction==='asc');button.classList.toggle('desc',active&&direction==='desc');const icon=button.querySelector('i');if(icon)icon.className='fa-solid '+(active?(direction==='asc'?'fa-arrow-up':'fa-arrow-down'):'fa-sort');button.title=active?(direction==='asc'?'เรียงจากน้อยไปมาก':'เรียงจากมากไปน้อย'):'คลิกเพื่อเรียงจากน้อยไปมาก';});
  updateLogSortButtons('control',mqttLogSort,mqttLogSortDirection);
  const logCalendar=document.getElementById('efLogCalendar');
  const logCalendarGrid=document.getElementById('efLogCalendarGrid');
  const logCalendarLabel=document.getElementById('efLogCalendarLabel');
  const logCalendarJump=document.createElement('div');
  logCalendarJump.className='ef-log-calendar-jump';
  const logCalendarMonthSelect=document.createElement('select');
  const logCalendarYearSelect=document.createElement('select');
  logCalendarMonthSelect.setAttribute('aria-label','เลือกเดือน');
  logCalendarYearSelect.setAttribute('aria-label','เลือกปี');
  ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'].forEach((name,index)=>logCalendarMonthSelect.add(new Option(name,String(index))));
  const logCalendarCurrentYear=new Date().getFullYear();
  for(let year=logCalendarCurrentYear-15;year<=logCalendarCurrentYear+1;year++)logCalendarYearSelect.add(new Option(String(year),String(year)));
  logCalendarJump.append(logCalendarMonthSelect,logCalendarYearSelect);
  logCalendarLabel.hidden=true;
  logCalendarLabel.parentNode.insertBefore(logCalendarJump,logCalendarLabel);
  let logAvailableDates=new Set();
  const initialLogDateParts=mqttDate.value.split('-').map(Number);
  let logCalendarMonth=new Date(initialLogDateParts[0],initialLogDateParts[1]-1,1);
  const logDateKey=date=>date.getFullYear()+'-'+String(date.getMonth()+1).padStart(2,'0')+'-'+String(date.getDate()).padStart(2,'0');
  function renderLogCalendar(){
    document.getElementById('efLogCalendarLabel').textContent=new Intl.DateTimeFormat('th-TH',{month:'long',year:'numeric'}).format(logCalendarMonth);
    logCalendarMonthSelect.value=String(logCalendarMonth.getMonth());
    logCalendarYearSelect.value=String(logCalendarMonth.getFullYear());
    const first=new Date(logCalendarMonth.getFullYear(),logCalendarMonth.getMonth(),1);
    const start=new Date(first);start.setDate(1-first.getDay());
    const today=logDateKey(new Date());
    logCalendarGrid.replaceChildren();
    for(let index=0;index<42;index++){
      const date=new Date(start);date.setDate(start.getDate()+index);
      const key=logDateKey(date);
      const button=document.createElement('button');
      button.type='button';button.textContent=date.getDate();button.dataset.logDate=key;
      if(date.getMonth()!==logCalendarMonth.getMonth())button.classList.add('outside');
      if(key===today)button.classList.add('today');
      if(key===mqttDate.value)button.classList.add('selected');
      if(logAvailableDates.has(key)){button.classList.add('has-data');button.title='มีข้อมูล Battery Control Log';}
      else{button.disabled=true;button.title='ไม่มีข้อมูล';}
      logCalendarGrid.appendChild(button);
    }
  }
  const jumpLogCalendar=()=>{logCalendarMonth=new Date(Number(logCalendarYearSelect.value),Number(logCalendarMonthSelect.value),1);renderLogCalendar();};
  logCalendarMonthSelect.addEventListener('change',jumpLogCalendar);
  logCalendarYearSelect.addEventListener('change',jumpLogCalendar);
  function loadLogAvailableDates(){
    return fetch('./battery_mqtt_history_api.php?list_dates=1',{cache:'no-store'}).then(response=>response.json()).then(payload=>{
      logAvailableDates=new Set(Array.isArray(payload.dates)?payload.dates:[]);
      renderLogCalendar();
    }).catch(()=>renderLogCalendar());
  }
  const mqttValue=value=>{
    if(value===null||value===undefined) return '—';
    if(typeof value==='object') return JSON.stringify(value);
    return String(value);
  };
  const batteryCommandContext=context=>{
    if(!context||typeof context!=='object')return '';
    const values=[];
    if(context.state)values.push('State '+String(context.state));
    if(context.main_power!==null&&context.main_power!==undefined)values.push('Main '+mqttValue(context.main_power)+' kW');
    if(context.main_reactive_power!==null&&context.main_reactive_power!==undefined)values.push('Main Q '+mqttValue(context.main_reactive_power)+' kvar');
    if(context.battery_power!==null&&context.battery_power!==undefined)values.push('Battery '+mqttValue(context.battery_power)+' kW');
    if(context.device_reactive_power!==null&&context.device_reactive_power!==undefined)values.push('Battery Q '+mqttValue(context.device_reactive_power)+' kvar');
    if(context.current_p_max!==null&&context.current_p_max!==undefined)values.push('p_max เดิม '+mqttValue(context.current_p_max)+' kW');
    if(context.soc!==null&&context.soc!==undefined)values.push('SoC '+mqttValue(context.soc)+'%');
    return values.join(' · ');
  };
  const controlValueSummary=context=>{
    if(!context||typeof context!=='object')return '—';
    const values=[];
    const isReactive=context.parameter==='q_max';
    const unit=context.unit||(isReactive?'kvar':'kW');
    const current=context.current_value!==undefined?context.current_value:context.current_p_max;
    if(current!==null&&current!==undefined)values.push('เดิม '+mqttValue(current)+' '+unit);
    const power=isReactive
      ? context.device_reactive_power
      : (context.device_power!==undefined?context.device_power:context.battery_power);
    if(power!==null&&power!==undefined)values.push((isReactive?'Battery Q ':'กำลัง ')+mqttValue(power)+' '+unit);
    if(isReactive&&context.main_reactive_power!==null&&context.main_reactive_power!==undefined)values.push('Main Q '+mqttValue(context.main_reactive_power)+' kvar');
    if(context.soc!==null&&context.soc!==undefined)values.push('SoC '+mqttValue(context.soc)+'%');
    return values.length?values.map(esc).join('<br>'):'—';
  };
  const controlCommandValue=(entry,key)=>{
    const value=mqttValue(entry[key]);
    const unit=entry.context&&entry.context.unit?entry.context.unit:(entry.parameter==='q_max'?'kvar':'kW');
    return value==='—'?value:value+' '+unit;
  };
  const friendlyBatteryReason=(reason,source)=>{
    const text=String(reason||'').trim();
    if(!text)return 'ไม่มีรายละเอียดสาเหตุ';
    let match;
    if(text==='Command received through CMA') return source==='energy_web'?'ผู้ใช้สั่งค่าจากหน้า Energy':'ได้รับคำสั่งควบคุมจาก MQTT';
    if((match=text.match(/^Max SoC ([\d.]+)%: stop Off-Peak charge$/i))) return 'SoC ถึง '+match[1]+'% จึงหยุดชาร์จช่วง Off-Peak';
    if((match=text.match(/^Off-Peak ramp charge ([\d.]+)\/([\d.]+) kW$/i))) return 'เพิ่มกำลังชาร์จช่วง Off-Peak เป็น '+match[1]+' kW (สูงสุด '+match[2]+' kW)';
    if((match=text.match(/^Peak shaving above ([\d.]+) kW$/i))) return 'โหลดสูงกว่า '+match[1]+' kW แบตเตอรี่จึงช่วยจ่ายเพื่อลด Peak';
    if((match=text.match(/^Fast Peak response: Total Consumption ([\d.]+) kW exceeds Limit ([\d.]+) kW by ([\d.]+) kW$/i))) return 'โหลดเกิน Peak '+match[3]+' kW จึงปรับ Battery ทันทีเพื่อลด Peak';
    if((match=text.match(/^Scheduled discharge ramp ([\d.]+)\/([\d.]+) kW \(step ([\d.]+) kW, ([\d.]+) kWh over ([\d.]+) h(?:, previous ([-\d.]+) kW, main ([-\d.]+) kW, SoC ([\d.]+)%, Min SoC ([\d.]+)%)?\)$/i))){
      const detail=match[6]!==undefined
        ? ' ขณะออกคำสั่ง Main Meter มีกำลังไฟ '+match[7]+' kW, Battery มี p_max เดิม '+match[6]+' kW และ SoC '+match[8]+'% (Min SoC '+match[9]+'%)'
        : ' ขณะนั้นเหลือพลังงานประมาณ '+match[4]+' kWh และเหลือเวลา '+match[5]+' ชั่วโมง';
      return 'อยู่ในช่วงจ่ายพลังงานตามตาราง จึงเพิ่ม p_max ทีละ '+match[3]+' kW เป็น '+match[1]+' kW โดยจำกัดไม่ให้เกิน Max kW ที่ตั้งไว้ '+match[2]+' kW.'+detail;
    }
    if((match=text.match(/^Scheduled discharge: ([\d.]+) kWh over ([\d.]+) h$/i))) return 'จ่ายพลังงานตามตาราง เหลือ '+match[1]+' kWh ภายใน '+match[2]+' ชั่วโมง';
    if((match=text.match(/^Reduce discharge below ([\d.]+) kW$/i))) return 'โหลดต่ำกว่า '+match[1]+' kW จึงลดกำลังที่แบตเตอรี่จ่ายออก';
    if((match=text.match(/^All-time Main support below ([\d.]+) kW \(main ([\d.-]+) kW, target charge ([\d.]+) kW\)$/i))) return 'Main Meter อยู่ที่ '+match[2]+' kW ต่ำกว่าค่าประคอง '+match[1]+' kW จึงสั่งชาร์จ '+match[3]+' kW';
    if((match=text.match(/^All-time charge stop at ([\d.]+) kW \(main ([\d.-]+) kW\)$/i))) return 'Main Meter อยู่ที่ '+match[2]+' kW ถึงค่าหยุดชาร์จ '+match[1]+' kW จึงหยุดชาร์จแบตเตอรี่';
    if((match=text.match(/^Min SoC ([\d.]+)%: stop discharging$/i))) return 'SoC ลดถึง '+match[1]+'% จึงหยุดจ่ายพลังงานออกจากแบตเตอรี่';
    if(text==='Battery strategy hold current p_max') return 'ยังไม่เข้าเงื่อนไขใหม่ จึงคงค่ากำลังแบตเตอรี่ล่าสุด';
    if(text==='Battery Load Strategy disabled: hold latest p_max') return 'ปิด Battery Load Strategy จึงคงค่ากำลังล่าสุด';
    if(text.startsWith('Main export ')) return 'มีพลังงานส่งออกจาก Main Meter จึงนำมาชาร์จแบตเตอรี่ก่อนลดกำลัง Solar';
    if(text.startsWith('Total Consumption ')&&text.includes('below Peak')) return 'โหลดรวมยังต่ำกว่า Peak Limit จึงใช้กำลังที่เหลือชาร์จแบตเตอรี่';
    if(text==='main-meter balance'||text==='balance') return 'ปรับกำลังเพื่อรักษาสมดุลตามค่าของ Main Meter';
    if(text==='Total Consumption Limit') return 'ลดกำลังเพื่อไม่ให้โหลดรวมเกิน Peak Limit';
    if(text==='Consumption headroom') return 'เพิ่มกำลังได้ เนื่องจากโหลดรวมยังไม่ถึง Peak Limit';
    return text;
  };
  const batteryReasonReference=(reason,source)=>{
    const text=String(reason||'').trim();
    if(text==='Command received through CMA') return source==='energy_web'?'หน้า Energy':'คำสั่ง MQTT';
    if(/^Max SoC |^Min SoC /i.test(text)) return 'ค่า SoC ของ Battery และ SoC Limit ที่ตั้งไว้';
    if(/^Off-Peak ramp charge /i.test(text)) return 'ค่า SoC, p_max และช่วงเวลา Off-Peak';
    if(/^Scheduled discharge(?: ramp|:)/i.test(text)) return 'Discharge Step, กำลัง Discharge สูงสุด, TOU Schedule และค่า SoC ของ Battery';
    if(/^Peak shaving above |^Reduce discharge below |^All-time Main support below |^All-time charge stop at /i.test(text)) return 'Active Power ของ Main Meter และค่า Threshold ใน Battery Load Strategy';
    if(text==='Battery strategy hold current p_max') return 'ค่า p_max ล่าสุดและเงื่อนไข Battery Load Strategy';
    if(text==='Battery Load Strategy disabled: hold latest p_max') return 'สถานะ Battery Load Strategy และค่า p_max ล่าสุด';
    if(text.startsWith('Main export ')) return 'Active Power ของ Main Meter, SoC และกำลังชาร์จที่ Battery รองรับ';
    if(text.startsWith('Total Consumption ')&&text.includes('below Peak')) return 'Total Consumption ของ Main Meter และ Peak Limit';
    if(text==='main-meter balance'||text==='balance') return 'Active Power และ Import/Export Threshold ของ Main Meter';
    if(text==='Total Consumption Limit'||text==='Consumption headroom') return 'Total Consumption ของ Main Meter และ Peak Limit';
    return source==='automation'?'เงื่อนไข Battery Automation':(source==='mqtt'?'คำสั่ง MQTT':(source==='energy_web'?'หน้า Energy':'ข้อมูลคำสั่งที่บันทึกไว้'));
  };
  function loadBatteryMqttHistory(){
    if(mqttHistoryLoading) return;
    mqttHistoryLoading=true;
    fetch('./battery_mqtt_history_api.php?date='+encodeURIComponent(mqttDate.value)+'&page='+mqttLogPage+'&page_size='+mqttLogPageSize+'&sort_by='+encodeURIComponent(mqttLogSort)+'&sort_dir='+mqttLogSortDirection,{cache:'no-store'}).then(async response=>{
      const payload=await response.json();
      if(!response.ok||payload.error) throw new Error(payload.error||('HTTP '+response.status));
      return payload;
    }).then(payload=>{
      const summary=payload.summary||{};
      const latest=summary.latest||null;
      document.getElementById('efMqttTotal').textContent=Number(summary.total_24h||0).toLocaleString();
      document.getElementById('efMqttSuccess').textContent=Number(summary.success_24h||0).toLocaleString();
      document.getElementById('efMqttFailed').textContent=Number(summary.failed_24h||0).toLocaleString();
      document.getElementById('efMqttLatest').textContent=latest
        ? latest.parameter+' '+String(latest.action||'').toUpperCase()+' = '+mqttValue(latest.requested)
        : '—';
      document.getElementById('efMqttLatestMeta').textContent=latest
        ? latest.device_name+' · '+latest.datetime+' · '+latest.cmd_id
        : 'ยังไม่มีคำสั่ง';
      document.getElementById('efMqttUpdated').textContent='อัปเดต '+new Date().toLocaleTimeString('th-TH',{hour12:false});
      const entries=Array.isArray(payload.entries)?payload.entries:[];
      const pagination=payload.pagination||{};
      const totalPages=Math.max(1,Number(pagination.total_pages||1));
      mqttLogPage=Math.min(Math.max(1,Number(pagination.page||mqttLogPage)),totalPages);
      saveMqttLogView();
      const totalEntries=Number(pagination.total_entries||0);
      const firstEntry=totalEntries?((mqttLogPage-1)*mqttLogPageSize)+1:0;
      const lastEntry=totalEntries?firstEntry+entries.length-1:0;
      document.getElementById('efLogPageInfo').textContent='แสดง '+firstEntry+'–'+lastEntry+' จาก '+totalEntries.toLocaleString()+' คำสั่ง · หน้า '+mqttLogPage+' / '+totalPages;
      const logPrev=document.getElementById('efLogPrev');
      const logNext=document.getElementById('efLogNext');
      logPrev.disabled=mqttLogPage<=1;
      logNext.disabled=mqttLogPage>=totalPages;
      logPrev.hidden=totalPages<=1||mqttLogPage<=1;
      logNext.hidden=totalPages<=1||mqttLogPage>=totalPages;
      document.getElementById('efMqttRows').innerHTML=entries.length?entries.map(entry=>{
        const state=entry.source==='automation_simulation'?['wait','จำลอง']:entry.success===true?['ok','สำเร็จ']:entry.success===false?['fail','ไม่สำเร็จ']:['wait','ไม่ทราบผล'];
        const source={automation:'Automation',automation_simulation:'Automation (Simulation)',mqtt:'MQTT',energy_web:'Energy page'}[entry.source]||entry.source||'—';
        const contextText=batteryCommandContext(entry.context);
        const deviceMeta=[entry.device_id,entry.device_role].filter(Boolean).join(' · ');
        return '<tr><td>'+esc(entry.datetime||'—')+'</td><td><b>'+esc(source)+'</b></td><td><b>'+esc(entry.device_name||entry.device_id)+'</b><br><small>'+esc(deviceMeta)+'</small></td><td>'+esc(entry.parameter)+' · '+esc(String(entry.action||'').toUpperCase())+'</td><td>'+controlValueSummary(entry.context)+'</td><td><b>'+esc(controlCommandValue(entry,'requested'))+'</b></td><td><b>'+esc(controlCommandValue(entry,'actual'))+'</b></td><td><span class="ef-mqtt-result '+state[0]+'">'+state[1]+'</span></td><td><div class="ef-reason-line"><span>'+esc(friendlyBatteryReason(entry.reason,entry.source))+'</span><button type="button" class="ef-reason-toggle" aria-expanded="false" aria-label="แสดงข้อมูลอ้างอิง" title="ดูข้อมูลอ้างอิง"><i class="fa-solid fa-circle-info"></i></button></div><small class="ef-reason-reference" hidden><i class="fa-solid fa-link"></i> อ้างอิงจาก: '+esc(batteryReasonReference(entry.reason,entry.source))+(contextText?'<br><i class="fa-solid fa-gauge-high"></i> เงื่อนไขขณะสั่ง: '+esc(contextText):'')+'</small></td></tr>';
      }).join(''):'<tr><td colspan="9">ยังไม่มีคำสั่งควบคุมในวันที่เลือก</td></tr>';
    }).catch(error=>{
      document.getElementById('efMqttUpdated').textContent='โหลดไม่สำเร็จ';
      document.getElementById('efMqttRows').innerHTML='<tr><td colspan="9">'+esc(error.message)+'</td></tr>';
    }).finally(()=>mqttHistoryLoading=false);
  }
  let batteryActionLoading=false;
  let batteryActionPage=1;
  let batteryActionPageSize=5;
  let batteryActionSort='datetime';
  let batteryActionSortDirection='desc';
  let batteryActionReason='';
  let batteryActionDirection='';
  const actionLogViewKey='mdbiot_battery_action_log_view_v1';
  const actionDate=document.getElementById('efActionLogDate');
  const actionCalendar=document.getElementById('efActionCalendar');
  const actionCalendarGrid=document.getElementById('efActionCalendarGrid');
  const actionCalendarLabel=document.getElementById('efActionCalendarLabel');
  const actionCalendarJump=document.createElement('div');
  const actionCalendarMonthSelect=document.createElement('select');
  const actionCalendarYearSelect=document.createElement('select');
  actionDate.value=mqttToday;
  try{const saved=JSON.parse(localStorage.getItem(actionLogViewKey)||'{}');if(/^\d{4}-\d{2}-\d{2}$/.test(saved.date||'')&&saved.date<=mqttToday)actionDate.value=saved.date;if(Number.isInteger(Number(saved.page))&&Number(saved.page)>0)batteryActionPage=Number(saved.page);if([5,10,25,50,100,200,500].includes(Number(saved.pageSize)))batteryActionPageSize=Number(saved.pageSize);if(['datetime','p_max','e_p_cha','e_p_dis','reason'].includes(saved.sort))batteryActionSort=saved.sort;if(['asc','desc'].includes(saved.sortDirection))batteryActionSortDirection=saved.sortDirection;if(['','on peak','off peak','solar'].includes(saved.reason))batteryActionReason=saved.reason;if(['','charge','discharge'].includes(saved.direction))batteryActionDirection=saved.direction;}catch(error){}
  document.getElementById('efActionLogPageSize').value=String(batteryActionPageSize);
  const saveActionLogView=()=>{try{localStorage.setItem(actionLogViewKey,JSON.stringify({date:actionDate.value,page:batteryActionPage,pageSize:batteryActionPageSize,sort:batteryActionSort,sortDirection:batteryActionSortDirection,reason:batteryActionReason,direction:batteryActionDirection}));}catch(error){}};
  const updateActionReasonButtons=()=>document.querySelectorAll('[data-action-direction]').forEach(button=>{const active=button.dataset.actionDirection===batteryActionDirection&&button.dataset.actionReason===batteryActionReason;button.classList.toggle('active',active);button.setAttribute('aria-pressed',String(active));});
  updateActionReasonButtons();
  updateLogSortButtons('action',batteryActionSort,batteryActionSortDirection);
  actionCalendarJump.className='ef-log-calendar-jump';
  actionCalendarMonthSelect.setAttribute('aria-label','เลือกเดือน');
  actionCalendarYearSelect.setAttribute('aria-label','เลือกปี');
  ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'].forEach((name,index)=>actionCalendarMonthSelect.add(new Option(name,String(index))));
  for(let year=logCalendarCurrentYear-15;year<=logCalendarCurrentYear+1;year++)actionCalendarYearSelect.add(new Option(String(year),String(year)));
  actionCalendarJump.append(actionCalendarMonthSelect,actionCalendarYearSelect);
  actionCalendarLabel.hidden=true;
  actionCalendarLabel.parentNode.insertBefore(actionCalendarJump,actionCalendarLabel);
  let actionAvailableDates=new Set();
  const initialActionDateParts=actionDate.value.split('-').map(Number);
  let actionCalendarMonth=new Date(initialActionDateParts[0],initialActionDateParts[1]-1,1);
  function renderActionCalendar(){
    actionCalendarLabel.textContent=new Intl.DateTimeFormat('th-TH',{month:'long',year:'numeric'}).format(actionCalendarMonth);
    actionCalendarMonthSelect.value=String(actionCalendarMonth.getMonth());
    actionCalendarYearSelect.value=String(actionCalendarMonth.getFullYear());
    const first=new Date(actionCalendarMonth.getFullYear(),actionCalendarMonth.getMonth(),1);
    const start=new Date(first);start.setDate(1-first.getDay());
    const today=logDateKey(new Date());
    actionCalendarGrid.replaceChildren();
    for(let index=0;index<42;index++){
      const date=new Date(start);date.setDate(start.getDate()+index);
      const key=logDateKey(date);
      const button=document.createElement('button');
      button.type='button';button.textContent=date.getDate();button.dataset.actionDate=key;
      if(date.getMonth()!==actionCalendarMonth.getMonth())button.classList.add('outside');
      if(key===today)button.classList.add('today');
      if(key===actionDate.value)button.classList.add('selected');
      if(actionAvailableDates.has(key)){button.classList.add('has-data');button.title='มีข้อมูล Battery Action Log';}
      else{button.disabled=true;button.title='ไม่มีข้อมูล';}
      actionCalendarGrid.appendChild(button);
    }
  }
  const jumpActionCalendar=()=>{actionCalendarMonth=new Date(Number(actionCalendarYearSelect.value),Number(actionCalendarMonthSelect.value),1);renderActionCalendar();};
  actionCalendarMonthSelect.addEventListener('change',jumpActionCalendar);
  actionCalendarYearSelect.addEventListener('change',jumpActionCalendar);
  function loadActionAvailableDates(){
    return Promise.resolve();
    return fetch('./battery_action_report.php?list_dates=1',{cache:'no-store'}).then(response=>response.json()).then(payload=>{
      actionAvailableDates=new Set(Array.isArray(payload.dates)?payload.dates:[]);
      renderActionCalendar();
    }).catch(()=>renderActionCalendar());
  }
  function loadBatteryActionLog(){
    return;
    if(batteryActionLoading)return;
    batteryActionLoading=true;
    const date=actionDate.value||mqttToday;
    fetch('./battery_action_report.php?from='+encodeURIComponent(date)+'&to='+encodeURIComponent(date)+'&page='+batteryActionPage+'&page_size='+batteryActionPageSize+'&sort_by='+encodeURIComponent(batteryActionSort)+'&sort_dir='+batteryActionSortDirection+'&reason='+encodeURIComponent(batteryActionReason)+'&direction='+encodeURIComponent(batteryActionDirection),{cache:'no-store'}).then(async response=>{
      const payload=await response.json();
      if(!response.ok||payload.error)throw new Error(payload.error||('HTTP '+response.status));
      return payload;
    }).then(payload=>{
      const summary=payload.summary||{};
      const energyText=value=>Number(value||0).toLocaleString('en-US',{minimumFractionDigits:3,maximumFractionDigits:3})+' kWh';
      document.getElementById('efActionChargeTotal').textContent=energyText(Number(summary.charge_off_peak||0)+Number(summary.charge_solar||0));
      document.getElementById('efActionChargeOffPeak').textContent=energyText(summary.charge_off_peak);
      document.getElementById('efActionChargeSolar').textContent=energyText(summary.charge_solar);
      document.getElementById('efActionDischargeTotal').textContent=energyText(Number(summary.discharge_on_peak||0)+Number(summary.discharge_off_peak||0));
      document.getElementById('efActionDischargeOnPeak').textContent=energyText(summary.discharge_on_peak);
      document.getElementById('efActionDischargeOffPeak').textContent=energyText(summary.discharge_off_peak);
      document.getElementById('efActionLogUpdated').textContent='อัปเดต '+new Date().toLocaleTimeString('th-TH',{hour12:false});
      const pagination=payload.pagination||{};
      const totalPages=Math.max(1,Number(pagination.total_pages||1));
      batteryActionPage=Math.min(Math.max(1,Number(pagination.page||batteryActionPage)),totalPages);
      const totalEntries=Number(pagination.total_entries||0);
      const rows=Array.isArray(payload.rows)?payload.rows:[];
      saveActionLogView();
      const firstEntry=totalEntries?((batteryActionPage-1)*batteryActionPageSize)+1:0;
      const lastEntry=totalEntries?firstEntry+rows.length-1:0;
      document.getElementById('efActionLogPageInfo').textContent='แสดง '+firstEntry+'–'+lastEntry+' จาก '+totalEntries.toLocaleString()+' คำสั่ง · หน้า '+batteryActionPage+' / '+totalPages;
      const previousButton=document.getElementById('efActionLogPrev');
      const nextButton=document.getElementById('efActionLogNext');
      previousButton.disabled=batteryActionPage<=1;
      nextButton.disabled=batteryActionPage>=totalPages;
      previousButton.hidden=totalPages<=1||batteryActionPage<=1;
      nextButton.hidden=totalPages<=1||batteryActionPage>=totalPages;
      const number=value=>value===null||value===''||!Number.isFinite(Number(value))?'—':Number(value).toLocaleString(undefined,{minimumFractionDigits:1,maximumFractionDigits:3});
      document.getElementById('efActionLogRows').innerHTML=rows.length?rows.map(row=>{
        const reason=String(row.reason||'off peak');
        const reasonClass=reason.replace(' ','-');
        return '<tr><td>'+esc(row.datetime||'—')+'</td><td><b>'+number(row.p_max)+'</b></td><td>'+number(row.e_p_cha)+'</td><td>'+number(row.e_p_dis)+'</td><td><span class="ef-action-reason '+reasonClass+'">'+esc(reason)+'</span></td></tr>';
      }).join(''):'<tr><td colspan="5">ยังไม่มีข้อมูลในวันที่เลือก</td></tr>';
    }).catch(error=>{
      document.getElementById('efActionLogUpdated').textContent='โหลดไม่สำเร็จ';
      document.getElementById('efActionLogRows').innerHTML='<tr><td colspan="5">'+esc(error.message)+'</td></tr>';
    }).finally(()=>batteryActionLoading=false);
  }
  mqttDate.addEventListener('change',()=>{mqttLogPage=1;saveMqttLogView();loadBatteryMqttHistory();});
  document.getElementById('efBatteryReportExport').addEventListener('click',async()=>{
    const date=actionDate.value||mqttToday;
    const reasonLabel=(batteryActionDirection?batteryActionDirection+' · ':'')+(batteryActionReason||'ทั้งหมด');
    let confirmed=false;
    if(window.Swal){
      const answer=await Swal.fire({icon:'question',title:'ยืนยัน Export Report?',text:'ส่งออก Battery Action Log วันที่ '+date+' · Reason: '+reasonLabel+' เป็นไฟล์ CSV',showCancelButton:true,confirmButtonText:'Export',cancelButtonText:'ยกเลิก',confirmButtonColor:'#168958',reverseButtons:true});
      confirmed=answer.isConfirmed;
    }else confirmed=window.confirm('ยืนยัน Export Report วันที่ '+date+' · Reason: '+reasonLabel+' หรือไม่?');
    if(!confirmed)return;
    window.location.href='./battery_action_report.php?from='+encodeURIComponent(date)+'&to='+encodeURIComponent(date)+'&format=csv&reason='+encodeURIComponent(batteryActionReason)+'&direction='+encodeURIComponent(batteryActionDirection);
  });
  document.querySelectorAll('[data-action-direction]').forEach(button=>button.addEventListener('click',()=>{const selectedReason=button.dataset.actionReason||'';const selectedDirection=button.dataset.actionDirection||'';const isCurrent=batteryActionReason===selectedReason&&batteryActionDirection===selectedDirection;batteryActionReason=isCurrent?'':selectedReason;batteryActionDirection=isCurrent?'':selectedDirection;batteryActionPage=1;updateActionReasonButtons();saveActionLogView();loadBatteryActionLog();}));
  document.getElementById('efActionLogPageSize').addEventListener('change',event=>{batteryActionPageSize=[5,10,25,50,100,200,500].includes(Number(event.target.value))?Number(event.target.value):5;batteryActionPage=1;saveActionLogView();loadBatteryActionLog();});
  document.getElementById('efActionLogPrev').addEventListener('click',()=>{if(batteryActionPage>1){batteryActionPage--;saveActionLogView();loadBatteryActionLog();}});
  document.getElementById('efActionLogNext').addEventListener('click',()=>{batteryActionPage++;saveActionLogView();loadBatteryActionLog();});
  document.querySelectorAll('.ef-table-sort').forEach(button=>button.addEventListener('click',()=>{
    const table=button.dataset.sortTable;
    const key=button.dataset.sortKey;
    if(table==='control'){
      mqttLogSortDirection=mqttLogSort===key?(mqttLogSortDirection==='asc'?'desc':'asc'):'asc';
      mqttLogSort=key;mqttLogPage=1;updateLogSortButtons('control',mqttLogSort,mqttLogSortDirection);saveMqttLogView();loadBatteryMqttHistory();
    }else{
      batteryActionSortDirection=batteryActionSort===key?(batteryActionSortDirection==='asc'?'desc':'asc'):'asc';
      batteryActionSort=key;batteryActionPage=1;updateLogSortButtons('action',batteryActionSort,batteryActionSortDirection);saveActionLogView();loadBatteryActionLog();
    }
  }));
  actionDate.addEventListener('change',()=>{
    batteryActionPage=1;
    const parts=actionDate.value.split('-').map(Number);
    if(parts.length===3)actionCalendarMonth=new Date(parts[0],parts[1]-1,1);
    saveActionLogView();renderActionCalendar();loadBatteryActionLog();
  });
  document.getElementById('efActionCalendarButton').addEventListener('click',event=>{event.stopPropagation();actionCalendar.hidden=!actionCalendar.hidden;if(!actionCalendar.hidden)loadActionAvailableDates();});
  actionCalendar.addEventListener('click',event=>{
    event.stopPropagation();
    const monthButton=event.target.closest('[data-action-month]');
    if(monthButton){actionCalendarMonth.setMonth(actionCalendarMonth.getMonth()+Number(monthButton.dataset.actionMonth));renderActionCalendar();return;}
    const dayButton=event.target.closest('[data-action-date].has-data');
    if(!dayButton)return;
    actionDate.value=dayButton.dataset.actionDate;
    batteryActionPage=1;
    saveActionLogView();
    actionCalendar.hidden=true;
    loadBatteryActionLog();
  });
  mqttPageSize.addEventListener('change',()=>{mqttLogPageSize=[5,10,25,50,100,200,500].includes(Number(mqttPageSize.value))?Number(mqttPageSize.value):5;mqttLogPage=1;saveMqttLogView();loadBatteryMqttHistory();});
  document.getElementById('efLogPrev').addEventListener('click',()=>{if(mqttLogPage>1){mqttLogPage--;saveMqttLogView();loadBatteryMqttHistory();}});
  document.getElementById('efLogNext').addEventListener('click',()=>{mqttLogPage++;saveMqttLogView();loadBatteryMqttHistory();});
  mqttDate.addEventListener('change',()=>{
    const parts=mqttDate.value.split('-').map(Number);
    if(parts.length===3)logCalendarMonth=new Date(parts[0],parts[1]-1,1);
    renderLogCalendar();
  });
  document.getElementById('efLogCalendarButton').addEventListener('click',event=>{
    event.stopPropagation();
    logCalendar.hidden=!logCalendar.hidden;
    if(!logCalendar.hidden)loadLogAvailableDates();
  });
  logCalendar.addEventListener('click',event=>{
    event.stopPropagation();
    const monthButton=event.target.closest('[data-log-month]');
    if(monthButton){logCalendarMonth.setMonth(logCalendarMonth.getMonth()+Number(monthButton.dataset.logMonth));renderLogCalendar();return;}
    const dayButton=event.target.closest('[data-log-date].has-data');
    if(!dayButton)return;
    mqttDate.value=dayButton.dataset.logDate;
    mqttLogPage=1;
    saveMqttLogView();
    logCalendar.hidden=true;
    loadBatteryMqttHistory();
  });
  document.addEventListener('click',event=>{if(!event.target.closest('.ef-log-date-picker')){logCalendar.hidden=true;actionCalendar.hidden=true;}});
  document.getElementById('efMqttRows').addEventListener('click',event=>{
    const button=event.target.closest('.ef-reason-toggle');
    if(!button)return;
    const reference=button.closest('td')?.querySelector('.ef-reason-reference');
    const expanded=button.getAttribute('aria-expanded')==='true';
    button.setAttribute('aria-expanded',String(!expanded));
    if(reference)reference.hidden=expanded;
  });

  function render(payload){
    lastPayload = payload;
    const data = payload && payload.data ? payload.data : {};
    const configuredIds = Object.keys(devices);
    const inverterIds = configuredIds.filter(id => typeOf(id)==='inverter');
    const batteryIds = configuredIds.filter(id => typeOf(id)==='battery');

    let solar = 0, solarHasValue = false, inverterOnline = 0;
    inverterIds.forEach(id => {
      const reading = data[id];
      const isOff = offline(reading);
      if (!isOff) inverterOnline++;
      const value = reading ? number(reading.p_out !== undefined ? reading.p_out : reading.p_t) : null;
      // Do not include the last stale value after an inverter goes offline.
      if (!isOff && value !== null){ solar += value; solarHasValue = true; }
    });
    const solarValue = solarHasValue ? solar : null;

    const meterReading = settings.mainMeter ? data[settings.mainMeter] : null;
    const rawMeterValue = meterReading ? number(meterReading.p_t) : null;
    const mainReactivePower = meterReading ? number(meterReading.q_t) : null;
    const mainPowerFactor = meterReading ? number(meterReading.pf_t) : null;
    const peakMaximum = number(automationConfig?.battery_strategy?.max_h_kw);
    const reactivePeakMaximum = peakMaximum === null ? null : peakMaximum * 0.6197;
    const normalizedMeterValue = rawMeterValue === null ? null : rawMeterValue * (settings.meterSign==='export' ? -1 : 1);
    const batteryReading = batteryIds.length ? data[batteryIds[0]] : null;
    const rawBatteryValue = batteryReading ? number(batteryReading.p_t) : null;
    const batteryQMax = batteryReading ? number(batteryReading.q_max) : null;
    // Internally, positive battery power always means discharge into the bus.
    const batteryValue = rawBatteryValue === null ? null : rawBatteryValue * (settings.batterySign==='charge' ? -1 : 1);
    const soc = batteryReading ? number(batteryReading.soc) : null;
    let gridValue = null, loadValue = null;
    const batteryChargeValue = Math.max(0,-(batteryValue || 0));
    if (settings.energyMode === 'grid') {
      gridValue = normalizedMeterValue;
      // A source without live data is excluded from the balance instead of
      // making every percentage unavailable (common after Solar shuts down).
      // Battery charge is consumption too, so include its positive magnitude
      // in Total Consumption while keeping the Battery node signed.
      loadValue = gridValue !== null
        ? (solarValue === null ? 0 : solarValue) + gridValue + Math.max(0,batteryValue || 0) + batteryChargeValue
        : null;
    } else {
      // In Load mode the selected meter is the facility load. Show charging
      // on top of it, but calculate Grid from the original meter value so the
      // charge amount is not counted twice.
      loadValue = normalizedMeterValue === null ? null : normalizedMeterValue + batteryChargeValue;
      gridValue = normalizedMeterValue !== null
        ? normalizedMeterValue - (solarValue === null ? 0 : solarValue) - (batteryValue || 0)
        : null;
    }

    updateLiveEnergySummary(loadValue,solarValue,batteryValue);
    document.getElementById('efSolarValue').textContent = fmt(solarValue);
    document.getElementById('efGridValue').textContent = fmt(gridValue);
    document.getElementById('efGridQt').textContent = fmt(mainReactivePower);
    document.getElementById('efGridQPeak').textContent = fmt(reactivePeakMaximum);
    document.getElementById('efGridPf').textContent = mainPowerFactor === null ? '—' : mainPowerFactor.toFixed(3);
    document.getElementById('efBatteryValue').textContent = fmt(batteryValue);
    document.getElementById('efBatteryQMax').textContent = fmt(batteryQMax);
    document.getElementById('efLoadValue').textContent = fmt(loadValue);
    document.getElementById('efSolarMeta').textContent = inverterOnline+' / '+inverterIds.length+' inverters online';
    document.getElementById('efMeterLabel').textContent = settings.energyMode==='grid' ? 'Main Meter / Grid' : 'Grid (Calculated)';
    document.getElementById('efLoadLabel').textContent = settings.energyMode==='grid' ? 'Total Consumption' : 'Facility Load';
    document.getElementById('efLoadMeta').textContent = settings.energyMode==='grid'
      ? 'รวมการใช้พลังงานและการชาร์จ Battery'
      : 'ค่าจาก Main Meter ที่เลือก';
    document.getElementById('efGridMeta').textContent = gridValue === null ? 'ไม่มีข้อมูล' : (gridValue >= 0 ? '' : 'ส่งพลังงานกลับ');
    document.getElementById('efBatteryMeta').textContent = 'SOC '+(soc === null ? '—' : Math.max(0,Math.min(100,soc)).toFixed(1)+'%')+(batteryValue === null ? '' : batteryValue > 0 ? ' · Discharging' : batteryValue < 0 ? ' · Charging' : ' · Standby');
    document.getElementById('efSocBar').style.width = (soc === null ? 0 : Math.max(0,Math.min(100,soc)))+'%';
    document.getElementById('efBalance').textContent = loadValue === null ? 'ข้อมูลไม่ครบ' : 'Balanced';

    setOffline('efSolar', inverterOnline===0);
    setOffline('efGrid', offline(meterReading));
    setOffline('efBattery', offline(batteryReading));
    setFlow('efLineSolar',solarValue,false,'PV → Bus','PV → Bus','No solar');
    setFlow('efLineGrid',gridValue,true,'Import →','← Export','No grid flow');
    // The battery is drawn to the right of the bus. Positive internal power
    // means discharge, so its animation must travel right-to-left.
    setFlow('efLineBattery',batteryValue === null ? null : -batteryValue,true,'Charge →','← Discharge','Standby');
    setFlow('efLineLoad',loadValue,false,'Bus → Load','Bus → Load','No load');

    // Show where output energy comes from. Multiple active sources are rendered
    // as colored sections on the same SVG path.
    positionEnergyLines();
    const loadSources=[];
    if(solarValue!==null && solarValue>.01) loadSources.push({name:'Solar',color:'#f5a623'});
    if(gridValue!==null && gridValue>.01) loadSources.push({name:'Grid',color:'#74045f'});
    if(batteryValue!==null && batteryValue>.01) loadSources.push({name:'Battery',color:'#25a56a'});
    const hub=document.querySelector('.ef-hub');
    const hubPalette=loadSources.length
      ? loadSources.map(source=>source.color)
      : ['#1266d2','#06468f','#073f84'];
    const hubFallback={
      '#f5a623':'#b85d00',
      '#74045f':'#430038',
      '#25a56a':'#0b6543'
    };
    hub.style.setProperty('--ef-hub-a',hubPalette[0]);
    hub.style.setProperty('--ef-hub-b',hubPalette[1] || hubFallback[hubPalette[0]] || hubPalette[0]);
    hub.style.setProperty('--ef-hub-c',hubPalette[2] || hubPalette[0]);
    applyFlowColors('efLineLoad',loadSources.map(source=>source.color));
    if(loadSources.length){
      document.getElementById('efLineLoad').dataset.flow=loadSources.map(source=>source.name).join(' + ')+' → Load';
    }
    applyFlowColors('efLineSolar',['#f5a623']);
    applyFlowColors('efLineGrid',gridValue!==null && gridValue<-.01
      ? loadSources.filter(source=>source.name!=='Grid').map(source=>source.color)
      : ['#74045f']);
    applyFlowColors('efLineBattery',batteryValue!==null && batteryValue<-.01
      ? loadSources.filter(source=>source.name!=='Battery').map(source=>source.color)
      : ['#25a56a']);

    // Contribution across the whole system: compare solar with every source
    // currently supplying the bus. Charging/export are sinks, not sources.
    const totalSupply =
        Math.max(0,solarValue || 0)
      + Math.max(0,gridValue || 0)
      + Math.max(0,batteryValue || 0);
    const share = totalSupply > 0
      ? Math.max(0,Math.min(100,Math.max(0,solarValue || 0)/totalSupply*100))
      : null;
    document.getElementById('efSolarShare').textContent = share === null ? '—' : share.toFixed(1)+'%';
    const batteryShare = batteryValue !== null && totalSupply > 0
      ? Math.max(0,Math.min(100,Math.max(0,batteryValue)/totalSupply*100))
      : null;
    document.getElementById('efBatterySummary').textContent = batteryShare === null
      ? '—'
      : batteryShare.toFixed(1)+'%';
    const gridInsightLabel=document.getElementById('efGridInsightLabel');
    const gridInsight=document.getElementById('efGridInsight');
    if(gridValue===null || loadValue===null || loadValue<=0){
      gridInsightLabel.textContent='Grid dependency';
      gridInsight.textContent='—';
      gridInsight.style.color='#74045f';
    }else if(gridValue<0){
      gridInsightLabel.textContent='Export to grid';
      gridInsight.textContent=fmt(gridValue)+' kW';
      gridInsight.style.color='#74045f';
    }else{
      const gridShare=Math.max(0,Math.min(100,gridValue/loadValue*100));
      gridInsightLabel.textContent='Grid dependency';
      gridInsight.textContent=gridShare.toFixed(1)+'%';
      gridInsight.style.color='#74045f';
    }

    document.getElementById('efInverterTotal').textContent = inverterIds.length+' devices';
    document.getElementById('efInverters').innerHTML = inverterIds.map(id => {
      const reading=data[id], isOff=offline(reading);
      const value=!isOff && reading ? number(reading.p_out !== undefined ? reading.p_out : reading.p_t) : null;
      const pMax=reading ? number(reading.p_max) : null;
      return '<div class="ef-device'+(isOff?' offline':'')+'"><button type="button" class="ef-device-config" data-ac-device="'+esc(id)+'" title="Edit Automation"><i class="fa-solid fa-pen"></i></button><div style="min-width:0"><div class="ef-device-name" title="'+esc(nameOf(id))+'">'+esc(nameOf(id))+'</div><div class="ef-device-sn">'+esc(id)+'</div></div><div class="ef-device-power">'+fmt(value)+' kW<small>Output · '+(isOff?'offline':'online')+'</small><small class="ef-device-pmax">p_max set: '+(pMax===null?'—':fmt(pMax)+' kW')+'</small></div></div>';
    }).join('');

    const updated = document.getElementById('efUpdated');
    updated.innerHTML = '<span class="ef-live-dot ok"></span> อัปเดตล่าสุด: '+esc(payload && payload.dt ? payload.dt : '—');
    const missing = [];
    if (rawMeterValue===null) missing.push('Main Meter: '+(settings.mainMeter || 'not selected'));
    // All Solar devices being offline is an expected nighttime state.
    // Warn only when an inverter is online but its power value is unavailable.
    if (inverterOnline > 0 && !solarHasValue) missing.push('Solar PV');
    if (batteryValue===null) missing.push('Battery');
    const alert = document.getElementById('efAlert');
    alert.style.display = missing.length ? '' : 'none';
    alert.textContent = missing.length ? 'ข้อมูลบางส่วนยังไม่พร้อม: '+missing.join(', ') : '';
  }

  function load(){
    if (loading||document.hidden) return;
    loading = true;
    fetch('./api_mdbiotx.php',{cache:'no-store'}).then(r=>{
      if(!r.ok) throw new Error('HTTP '+r.status);
      return r.json();
    }).then(render).catch(()=>{
      document.getElementById('efUpdated').innerHTML='<span class="ef-live-dot"></span> โหลดข้อมูลไม่สำเร็จ';
    }).finally(()=>{loading=false;});
  }
  load();
  loadAutomationStatus();
  loadEnergyHistory();
  loadConsumptionSummary();
  loadBatteryMqttHistory();
  loadBatteryActionLog();
  loadActionAvailableDates();
  loadLogAvailableDates();
  setInterval(load,5000);
  setInterval(()=>{if(!document.hidden)loadAutomationStatus();},10000);
  setInterval(()=>{
    if(document.hidden)return;
    if(historyPeriod.value==='day'&&historyDate.value===todayLocal()) loadEnergyHistory(true);
  },30000);
  setInterval(()=>{if(!document.hidden)loadBatteryMqttHistory();},15000);
  setInterval(()=>{if(!document.hidden)loadBatteryActionLog();},15000);
  setInterval(()=>{if(!document.hidden)loadConsumptionSummary(true);},30000);
  window.addEventListener('gmodechange',()=>{
    if(energyDonutChart){energyDonutChart.destroy();energyDonutChart=null;}
    if(historyChart){historyChart.destroy();historyChart=null;}
    renderLiveEnergySummary();
    loadEnergyHistory(true);
  });
  document.addEventListener('visibilitychange',()=>{
    if(!document.hidden){
      load();
      loadAutomationStatus();
      loadEnergyHistory(true);
      loadConsumptionSummary(true);
      loadBatteryMqttHistory();
      loadBatteryActionLog();
    }
  });
})();
</script>
