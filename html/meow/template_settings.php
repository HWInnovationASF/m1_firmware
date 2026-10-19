<?php
// หน้าตั้งค่ารายเทมเพลต (index.php?page=template-settings&t=<id>) — เข้าจากปุ่ม "ตั้งค่า" ในหน้า Template config
//   energy_diagram : มิเตอร์หลัก / โหมดพลังงาน / ทิศค่า (ค่ากลางของทุกเครื่อง)
//   chiller_diagram: Main/Plant PM, เครื่องที่เป็น Chiller + PM ของแต่ละเครื่อง, PM ของ CHWP, โซน Load (เดิมตั้งในการ์ด/ในหน้า diagram)
//   template2-5    : กลุ่มมิเตอร์ (MDB1-4, ACH) ที่แต่ละเทมเพลตแสดง/รวมค่า
//   template6      : ซ่อนอุปกรณ์ / ซ่อนอุปกรณ์ออฟไลน์ ใน MDB List
// บันทึกผ่าน dashboard_template_api.php (action=template_settings) ลง config/dashboard_template.json
if (!in_array(strtolower((string)($_SESSION['user_type'] ?? '')), array('admin', 'superadmin'), true)) {
    echo '<div class="alert alert-warning">หน้าตั้งค่าเทมเพลตสำหรับผู้ดูแลระบบ (admin/superadmin) เท่านั้น</div>';
    return;
}
$tsId = (string)($_GET['t'] ?? '');
$tsConfig = json_decode((string)@file_get_contents(__DIR__ . '/config/dashboard_template.json'), true);
if (!is_array($tsConfig)) $tsConfig = array();
$tsSaved = is_array($tsConfig['settings'][$tsId] ?? null) ? $tsConfig['settings'][$tsId] : array();
$tsDefaultLabels = array('energy_diagram' => 'Energy Diagram', 'chiller_diagram' => 'Chiller Diagram', 'template2' => 'Template 2', 'template3' => 'Template 3', 'template4' => 'Template 4', 'template5' => 'Template 5', 'template6' => 'Template 6');
if (!isset($tsDefaultLabels[$tsId])) {
    echo '<div class="alert alert-warning">ไม่พบหน้าตั้งค่าของเทมเพลตนี้ <a href="?page=template-config">กลับ Template config</a></div>';
    return;
}
$tsLabel = trim(strip_tags((string)($tsConfig['labels'][$tsId] ?? ''))) ?: $tsDefaultLabels[$tsId];
$tsGroupNames = array('mdb1' => 'MDB1 (รวมเป็น Sum MDB1)', 'mdb1_use' => 'MDB1 Use', 'mdb2' => 'MDB2', 'mdb3' => 'MDB3', 'mdb4' => 'MDB4', 'ach' => 'ACH (แสดงร่วมกับ MDB1)');
$tsGroupsOf = array('template2' => array('mdb1', 'mdb1_use', 'mdb2', 'mdb3', 'mdb4'), 'template3' => array('mdb1', 'mdb2'), 'template4' => array('mdb1', 'mdb2'), 'template5' => array('mdb1', 'ach', 'mdb2'));
$tsGroupDefaults = require __DIR__ . '/template_group_defaults.php';

// อุปกรณ์ทั้งหมด: id => [ชื่อ, ประเภทรุ่น(abbr/type)]
$tsModels = json_decode((string)($detail_rgl_txt ?? ''), true);
if (!is_array($tsModels)) $tsModels = array();
$tsDevices = array();
foreach ((is_array($dvl_arr ?? null) ? $dvl_arr : array()) as $id => $dev) {
    if (!is_array($dev)) continue;
    $model = (string)($dev['model'] ?? '');
    $tsDevices[(string)$id] = array((string)($dev['name'] ?? $id), strtoupper((string)($tsModels[$model]['abbr'] ?? '')), (string)($tsModels[$model]['type'] ?? ''));
}
$tsMeters = array_filter($tsDevices, function ($d) { return $d[2] === 'meter'; }) ?: $tsDevices;

// ค่าที่ใช้อยู่ตอนนี้ของแต่ละกลุ่ม (ตั้งไว้ หรือค่าเริ่มต้น)
$tsGroupState = array();
foreach ($tsGroupsOf[$tsId] ?? array() as $g) {
    $set = is_array($tsSaved['groups'][$g] ?? null);
    $tsGroupState[$g] = array('set' => $set, 'devices' => $set ? $tsSaved['groups'][$g] : $tsGroupDefaults[$g]);
}
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?>
<style>
:root{--tm-ok-bg:#e3f5ea;--tm-ok:#168958;--tm-bad-bg:#fde8e6;--tm-bad:#b42318;--tm-warn-bg:#fff6e0;--tm-warn:#8a5a00;--tm-input:#fff;--tm-input-line:#cddbea;--tm-row-line:#edf1f5;--tm-chip-line:#dce7f1;--tm-btn:#245b87;--tm-on:#1769d2}
html[data-gmode=dark]{--tm-ok-bg:#173525;--tm-ok:#5fd394;--tm-bad-bg:#3d1d1b;--tm-bad:#ff8a80;--tm-warn-bg:#3a2f12;--tm-warn:#f0c060;--tm-input:#0e1726;--tm-input-line:#2c3e57;--tm-row-line:#1f2c3f;--tm-chip-line:#2c3e57;--tm-btn:#86bdf7;--tm-on:#2f7fe0}
/* SweetAlert (หน้าต่างแก้ไข/นำเข้า) ทั้งเว็บยังเป็นพื้นขาวในโหมดมืด: ปรับเฉพาะในหน้านี้ */
html[data-gmode=dark] .swal2-popup{background:var(--th-surface);color:var(--th-text)}
html[data-gmode=dark] .swal2-input,html[data-gmode=dark] .swal2-select{background:var(--tm-input);border-color:var(--tm-input-line);color:var(--th-text)}
html[data-gmode=dark] .swal2-validation-message{background:var(--th-tint);color:var(--th-text)}
html[data-gmode=dark] input[type=date]::-webkit-calendar-picker-indicator{filter:invert(.8)}
html[data-gmode=dark] .lidm .btn-light,html[data-gmode=dark] .tsx .btn-light{background:var(--th-tint);border-color:var(--th-ring);color:var(--th-text)}
.tsx{max-width:1000px;margin:auto;color:var(--th-text)}.tsx-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}.tsx-head h3{margin:0;color:var(--th-navy)}.tsx-head p{margin:4px 0 0;color:var(--th-text2);font-size:.78rem}
.tsx-card{padding:16px 18px;margin-bottom:14px;border:1px solid var(--th-line);border-radius:14px;background:var(--th-surface)}.tsx-card h5{margin:0 0 4px;font-size:.92rem;color:var(--th-navy)}.tsx-sub{margin:0 0 12px;color:var(--th-text2);font-size:.72rem}
.tsx-form{display:grid;grid-template-columns:1fr 1fr;gap:12px}.tsx-form label span{display:block;margin-bottom:5px;color:var(--th-text2);font-size:.72rem;font-weight:700}
.tsx-form select,.tsx-filter,.tsx-zone{border:1px solid var(--tm-input-line);border-radius:8px;background:var(--tm-input);color:var(--th-text)}.tsx-form select{width:100%;padding:8px 10px;font-size:.8rem}
.tsx-checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:6px}.tsx-check{display:flex;align-items:center;gap:8px;padding:7px 9px;border:1px solid var(--tm-row-line);border-radius:8px;font-size:.74rem;cursor:pointer;color:var(--th-text)}
.tsx-check input{width:16px;height:16px}.tsx-check small{margin-left:auto;color:var(--th-text3)}.tsx-check.missing{background:var(--tm-warn-bg)}
.tsx-badge{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:10px;font-size:.6rem;font-weight:700;vertical-align:middle}.tsx-badge.set{background:var(--tm-ok-bg);color:var(--tm-ok)}.tsx-badge.def{background:var(--th-tint);color:var(--th-text2)}
.tsx-filter{width:100%;max-width:260px;padding:6px 9px;margin-bottom:8px;font-size:.75rem}.tsx-links a{margin-right:8px}.tsx-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.tsx-chips{display:flex;flex-wrap:wrap;gap:6px}.tsx-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border:1px solid var(--tm-chip-line);border-radius:16px;font-size:.72rem;cursor:pointer;color:var(--th-text)}.tsx-chip small{color:var(--th-text3)}.tsx-chip input{width:14px;height:14px}.tsx-chip.is-used{opacity:.45;cursor:not-allowed}
.tsx-row{padding:10px 0;border-top:1px solid var(--tm-row-line)}.tsx-row-head{display:flex;align-items:center;gap:8px;margin-bottom:7px;font-size:.78rem;flex-wrap:wrap}.tsx-row-head small{color:var(--th-text3)}.tsx-zone{margin-left:auto;padding:5px 8px;font-size:.74rem;min-width:160px}
.tsx-pager{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:8px;font-size:.72rem;color:var(--th-text2)}.tsx-card.tsx-rows+.tsx-pager{margin:-8px 0 14px;padding:0 4px}
.tsx-pages{display:inline-flex;gap:4px;flex-wrap:wrap}.tsx-pages button{min-width:28px;padding:3px 8px;border:1px solid var(--tm-input-line);border-radius:7px;background:var(--tm-input);color:var(--tm-btn);font-size:.7rem}.tsx-pages button.on{background:var(--tm-on);border-color:var(--tm-on);color:#fff;font-weight:700}.tsx-pages button:disabled{opacity:.4}.tsx-pages span{padding:3px 2px;color:var(--th-text3)}
.tsx-toggle{display:flex;align-items:center;gap:8px;font-size:.8rem;margin-bottom:12px}.tsx-toggle input{width:17px;height:17px}
@media(max-width:700px){.tsx-form{grid-template-columns:1fr}}
</style>
<div class="tsx" id="tsx" data-template="<?php echo $h($tsId); ?>">
  <div class="tsx-head">
    <div><h3><i class="fa-solid fa-sliders"></i> ตั้งค่า · <?php echo $h($tsLabel); ?></h3><p>ค่าที่บันทึกมีผลกับทุกเครื่องที่เปิดหน้านี้ (โหลดหน้า Dashboard ใหม่เพื่อเห็นผล)</p></div>
    <a class="btn btn-light" href="?page=template-config"><i class="fa-solid fa-arrow-left"></i> กลับ Template config</a>
  </div>

<?php if ($tsId === 'energy_diagram') {
    $cur = function ($k, $def) use ($tsSaved) { return (string)($tsSaved[$k] ?? $def); }; ?>
  <div class="tsx-card">
    <h5>ค่าเริ่มต้นของแผนภาพพลังงาน</h5>
    <p class="tsx-sub">ใช้กับทุกเครื่อง — เครื่องที่เคยกดบันทึกในแผงตั้งค่าของหน้า Energy Diagram เองจะใช้ค่าของเครื่องนั้นจนกว่าจะกด "รีเซ็ต" ในแผงนั้น</p>
    <div class="tsx-form">
      <label><span>Main Meter</span><select data-k="main_meter"><option value="">— มิเตอร์ตัวแรก (ค่าเริ่มต้น) —</option>
        <?php foreach ($tsMeters as $id => $d) { ?><option value="<?php echo $h($id); ?>" <?php echo $cur('main_meter', '') === (string)$id ? 'selected' : ''; ?>><?php echo $h($d[0] . ' · ' . $id); ?></option><?php } ?></select></label>
      <label><span>โหมดคำนวณพลังงาน</span><select data-k="energy_mode">
        <option value="grid" <?php echo $cur('energy_mode', 'grid') === 'grid' ? 'selected' : ''; ?>>Grid — คำนวณ Load จาก Solar + Grid + Battery</option>
        <option value="load" <?php echo $cur('energy_mode', 'grid') === 'load' ? 'selected' : ''; ?>>Load — Main Meter คือ Facility Load แล้วคำนวณ Grid</option></select></label>
      <label><span>ทิศค่ามิเตอร์ (ค่าบวก)</span><select data-k="meter_sign">
        <option value="import" <?php echo $cur('meter_sign', 'import') === 'import' ? 'selected' : ''; ?>>Import (ซื้อไฟเข้า = บวก)</option>
        <option value="export" <?php echo $cur('meter_sign', 'import') === 'export' ? 'selected' : ''; ?>>Export (ขายไฟออก = บวก)</option></select></label>
      <label><span>ทิศค่าแบตเตอรี่ (ค่าบวก)</span><select data-k="battery_sign">
        <option value="discharge" <?php echo $cur('battery_sign', 'discharge') === 'discharge' ? 'selected' : ''; ?>>Discharge (จ่ายไฟ = บวก)</option>
        <option value="charge" <?php echo $cur('battery_sign', 'discharge') === 'charge' ? 'selected' : ''; ?>>Charge (ชาร์จ = บวก)</option></select></label>
    </div>
  </div>
  <div class="tsx-card tsx-links"><h5>ตั้งค่าที่เกี่ยวข้อง</h5><p class="tsx-sub">หน้าตั้งค่าอื่นที่แผนภาพพลังงานใช้ข้อมูลร่วมกัน</p>
    <a class="btn btn-outline-primary btn-sm" href="?page=automation-config"><i class="fa-solid fa-robot"></i> Automation Config</a>
    <a class="btn btn-outline-primary btn-sm" href="?page=solar-performance-config"><i class="fa-solid fa-solar-panel"></i> Solar DC Input Config</a>
    <a class="btn btn-outline-primary btn-sm" href="?page=parameter-list"><i class="fa-solid fa-list"></i> Parameter List</a>
  </div>

<?php } elseif ($tsId === 'chiller_diagram') {
    // ตัวเลือกอุปกรณ์แบบเดียวกับ chiller_diagram.php
    $byAbbr = function ($abbr) use ($tsDevices) { return array_filter($tsDevices, function ($d) use ($abbr) { return $d[1] === $abbr; }); };
    $pms = $byAbbr('PM'); $acs = $byAbbr('AC');
    $chwps = $loads = $zoneAcs = array();
    foreach ((is_array($dvl_arr ?? null) ? $dvl_arr : array()) as $id => $dev) {
        if (!is_array($dev)) continue;
        $id = (string)$id; $hay = strtolower($id . ' ' . ($dev['name'] ?? '') . ' ' . ($dev['model'] ?? ''));
        if (preg_match('/chwp|chw.?pump|chilled.?water.?pump/', $hay)) $chwps[$id] = $tsDevices[$id];
        if (preg_match('/btu|load/', $hay)) $loads[$id] = $tsDevices[$id];
        if (preg_match('/^AC(?!H)/i', $id)) $zoneAcs[$id] = $tsDevices[$id];
    }
    $flat = function ($v) { $out = array(); if (!is_array($v)) $v = $v === '' || $v === null ? array() : array($v); array_walk_recursive($v, function ($x) use (&$out) { if ((string)$x !== '' && !in_array((string)$x, $out, true)) $out[] = (string)$x; }); return $out; };
    $mainSel = $flat($tsSaved['main_power_meters'] ?? ($tsSaved['main_power_meter'] ?? array()));
    $plantSel = $flat($tsSaved['plant_power_meters'] ?? array());
    $chillerSel = is_array($tsSaved['chiller_devices'] ?? null) ? $tsSaved['chiller_devices'] : array_keys($acs);
    $chillerMeters = is_array($tsSaved['chiller_meters'] ?? null) ? $tsSaved['chiller_meters'] : array();
    $chwpMeters = is_array($tsSaved['chwp_power_meters'] ?? null) ? $tsSaved['chwp_power_meters'] : array();
    $loadSel = is_array($tsSaved['load_devices'] ?? null) ? $tsSaved['load_devices'] : array_keys($loads);
    $zones = is_array($tsSaved['load_zones'] ?? null) ? $tsSaved['load_zones'] : array();
    // ชิปเลือกหลายตัว ; $excl = ชื่อกลุ่มที่ห้ามเลือกซ้ำข้ามแถว
    $chips = function ($choices, $selected, $attrs, $excl = '') use ($h) {
        if (!$choices) return '<span class="tsx-sub">ไม่พบอุปกรณ์</span>';
        $out = '';
        foreach ($choices as $id => $d) $out .= '<label class="tsx-chip"><input type="checkbox" value="' . $h($id) . '" ' . $attrs . ($excl ? ' data-excl="' . $h($excl) . '"' : '') . (in_array((string)$id, array_map('strval', (array)$selected), true) ? ' checked' : '') . '>' . $h($d[0]) . ' <small>' . $h($id) . '</small></label>';
        return $out;
    }; ?>
  <div class="tsx-card" data-chiller>
    <h5>Main Power Meter</h5><p class="tsx-sub">มิเตอร์ไฟหลักของอาคาร (แสดงเป็น Main Meter ในแผนผัง)</p>
    <div class="tsx-chips" data-list="main_power_meters"><?php echo $chips($pms, $mainSel, ''); ?></div>
  </div>
  <div class="tsx-card">
    <h5>Plant Power Meter</h5><p class="tsx-sub">มิเตอร์ที่รวมเป็นกำลังไฟของ Chiller Plant</p>
    <div class="tsx-chips" data-list="plant_power_meters"><?php echo $chips($pms, $plantSel, ''); ?></div>
  </div>
  <div class="tsx-card">
    <h5>Chiller และมิเตอร์ของแต่ละเครื่อง</h5><p class="tsx-sub">ติ๊กเครื่อง AC ที่เป็น Chiller แล้วเลือก PM ที่วัดไฟของเครื่องนั้น (PM หนึ่งตัวใช้กับ Chiller ได้เครื่องเดียว)</p>
    <?php if (!$acs) echo '<span class="tsx-sub">ไม่พบอุปกรณ์ประเภท AC</span>'; foreach ($acs as $id => $d) { ?>
    <div class="tsx-row" data-chiller-row="<?php echo $h($id); ?>">
      <label class="tsx-row-head"><input type="checkbox" data-use <?php echo in_array((string)$id, $chillerSel, true) ? 'checked' : ''; ?>> <b><?php echo $h($d[0]); ?></b> <small><?php echo $h($id); ?></small></label>
      <div class="tsx-chips"><?php echo $chips($pms, $chillerMeters[$id] ?? array(), '', 'chiller_pm'); ?></div>
    </div>
    <?php } ?>
  </div>
  <div class="tsx-card">
    <h5>มิเตอร์ของปั๊ม CHWP</h5><p class="tsx-sub">อุปกรณ์ที่ชื่อมีคำว่า CHWP / chilled water pump</p>
    <?php if (!$chwps) echo '<span class="tsx-sub">ไม่พบอุปกรณ์ CHWP</span>'; foreach ($chwps as $id => $d) { ?>
    <div class="tsx-row" data-chwp-row="<?php echo $h($id); ?>"><div class="tsx-row-head"><b><?php echo $h($d[0]); ?></b> <small><?php echo $h($id); ?></small></div>
      <div class="tsx-chips"><?php echo $chips($pms, $chwpMeters[$id] ?? array(), ''); ?></div></div>
    <?php } ?>
  </div>
  <div class="tsx-card">
    <h5>Cooling Load / BTU และโซน</h5><p class="tsx-sub">ติ๊กมิเตอร์ Load ที่ใช้ ตั้งชื่อโซน แล้วเลือกเครื่อง AC ในโซน (AC หนึ่งเครื่องอยู่ได้โซนเดียว ; ไม่ใส่ชื่อโซน = ไม่จัดโซน)</p>
    <?php if (!$loads) echo '<span class="tsx-sub">ไม่พบอุปกรณ์ Load / BTU</span>'; foreach ($loads as $id => $d) { $z = is_array($zones[$id] ?? null) ? $zones[$id] : array(); ?>
    <div class="tsx-row" data-load-row="<?php echo $h($id); ?>">
      <label class="tsx-row-head"><input type="checkbox" data-use <?php echo in_array((string)$id, $loadSel, true) ? 'checked' : ''; ?>> <b><?php echo $h($d[0]); ?></b> <small><?php echo $h($id); ?></small>
        <input class="tsx-zone" data-zone-name maxlength="60" placeholder="ชื่อโซน" value="<?php echo $h($z['name'] ?? ''); ?>"></label>
      <div class="tsx-chips"><?php echo $chips($zoneAcs, $z['devices'] ?? array(), '', 'zone_ac'); ?></div>
    </div>
    <?php } ?>
  </div>
  <div class="tsx-card tsx-links"><h5>หมายเหตุ</h5><p class="tsx-sub" style="margin:0">หน้า Chiller Diagram ยังมีปุ่มแก้ค่าเหล่านี้ในตัวเหมือนเดิม ทั้งสองที่บันทึกลงไฟล์เดียวกัน</p></div>

<?php } elseif (isset($tsGroupsOf[$tsId])) {
    foreach ($tsGroupState as $g => $st) {
        $extra = array_diff($st['devices'], array_keys($tsDevices)); ?>
  <div class="tsx-card" data-group="<?php echo $h($g); ?>">
    <h5><?php echo $h($tsGroupNames[$g]); ?><?php echo $st['set'] ? '<span class="tsx-badge set">ตั้งค่าแล้ว</span>' : '<span class="tsx-badge def">ค่าเริ่มต้น</span>'; ?></h5>
    <p class="tsx-sub">เลือกอุปกรณ์ที่แสดงในกลุ่มนี้<?php echo $g === 'mdb1_use' || $g === 'ach' ? '' : ' (ค่า p_t ของกลุ่มถูกนำมารวมเป็นยอดของกลุ่ม)'; ?></p>
    <input class="tsx-filter" placeholder="กรองชื่อ/รหัส..." oninput="tsxFilter(this)">
    <div class="tsx-checks">
      <?php foreach ($tsDevices as $id => $d) { ?><label class="tsx-check"><input type="checkbox" value="<?php echo $h($id); ?>" <?php echo in_array((string)$id, $st['devices'], true) ? 'checked' : ''; ?>><span><?php echo $h($d[0]); ?></span><small><?php echo $h($id); ?></small></label><?php } ?>
      <?php foreach ($extra as $id) { ?><label class="tsx-check missing" title="ไม่พบอุปกรณ์นี้ในระบบ"><input type="checkbox" value="<?php echo $h($id); ?>" checked><span>ไม่พบในอุปกรณ์</span><small><?php echo $h($id); ?></small></label><?php } ?>
    </div>
  </div>
<?php }
} elseif ($tsId === 'template6') {
    $hidden = is_array($tsSaved['hidden_devices'] ?? null) ? $tsSaved['hidden_devices'] : array(); ?>
  <div class="tsx-card">
    <h5>การแสดงรายการอุปกรณ์</h5>
    <label class="tsx-toggle"><input type="checkbox" id="tsxHideOffline" <?php echo ($tsSaved['hide_offline'] ?? false) === true ? 'checked' : ''; ?>> ซ่อนอุปกรณ์ที่ออฟไลน์ (ไม่ได้ส่งข้อมูล)</label>
    <p class="tsx-sub">ติ๊กอุปกรณ์ที่ต้องการ<b>ซ่อน</b>จาก MDB List</p>
    <input class="tsx-filter" placeholder="กรองชื่อ/รหัส..." oninput="tsxFilter(this)">
    <div class="tsx-checks" data-hidden>
      <?php foreach ($tsDevices as $id => $d) { ?><label class="tsx-check"><input type="checkbox" value="<?php echo $h($id); ?>" <?php echo in_array((string)$id, $hidden, true) ? 'checked' : ''; ?>><span><?php echo $h($d[0]); ?></span><small><?php echo $h($id); ?></small></label><?php } ?>
    </div>
  </div>
<?php } ?>

  <div class="tsx-actions">
    <button type="button" class="btn btn-light" onclick="tsxSave(true)"><i class="fa-solid fa-rotate-left"></i> คืนค่าเริ่มต้น</button>
    <button type="button" class="btn btn-primary" id="tsxSaveBtn" onclick="tsxSave(false)"><i class="fa-solid fa-floppy-disk"></i> บันทึก</button>
  </div>
</div>
<script>
// ---------- แบ่งหน้า 10 รายการ: รายการอุปกรณ์ (.tsx-checks) และแถว Chiller/CHWP/Load (.tsx-row) ----------
// รายการที่อยู่หน้าอื่นแค่ถูกซ่อน (ยังอยู่ในฟอร์ม) จึงบันทึกค่าที่ติ๊กไว้ครบทุกหน้า
const TSX_PAGE_SIZE = 10;
function tsxPage(box) {
  const items = [...box.querySelectorAll(box._itemSel)], shown = items.filter(el => !el.dataset.filtered);
  const pages = Math.max(1, Math.ceil(shown.length / TSX_PAGE_SIZE));
  box._page = Math.max(0, Math.min(box._page || 0, pages - 1));
  const p = box._page, from = p * TSX_PAGE_SIZE;
  items.forEach(el => { el.style.display = 'none'; });
  shown.slice(from, from + TSX_PAGE_SIZE).forEach(el => { el.style.display = ''; });
  const btn = (to, label, on) => `<button type="button" data-to="${to}"${on ? ' class="on"' : ''}${to < 0 || to >= pages ? ' disabled' : ''}>${label}</button>`;
  let nums = '';
  const a = Math.max(0, Math.min(p - 2, pages - 5)), b = Math.min(pages - 1, a + 4);
  if (a > 0) nums += btn(0, 1) + (a > 1 ? '<span>…</span>' : '');
  for (let n = a; n <= b; n++) nums += btn(n, n + 1, n === p);
  if (b < pages - 1) nums += (b < pages - 2 ? '<span>…</span>' : '') + btn(pages - 1, pages);
  box._pager.innerHTML = shown.length > TSX_PAGE_SIZE
    ? `<span>${from + 1}-${Math.min(from + TSX_PAGE_SIZE, shown.length)} จาก ${shown.length}</span><span class="tsx-pages">${btn(p - 1, '‹')}${nums}${btn(p + 1, '›')}</span>`
    : '';
}
function tsxPagerInit(box, itemSel) {
  if (!box || box._pager) return;
  box._itemSel = itemSel;
  box._pager = document.createElement('div'); box._pager.className = 'tsx-pager';
  box.after(box._pager);
  box._pager.addEventListener('click', e => { const b = e.target.closest('button[data-to]'); if (b && !b.disabled) { box._page = +b.dataset.to; tsxPage(box); } });
  tsxPage(box);
}
function tsxFilter(input) {
  const q = input.value.trim().toLowerCase(), box = input.nextElementSibling;
  box.querySelectorAll('.tsx-check').forEach(el => { if (!q || el.textContent.toLowerCase().includes(q)) delete el.dataset.filtered; else el.dataset.filtered = '1'; });
  box._page = 0; tsxPage(box);
}
document.querySelectorAll('#tsx .tsx-checks').forEach(box => tsxPagerInit(box, '.tsx-check'));
document.querySelectorAll('#tsx .tsx-card').forEach(card => { if (card.querySelector(':scope > .tsx-row')) { card.classList.add('tsx-rows'); tsxPagerInit(card, ':scope > .tsx-row'); } });
function tsxCollect() {
  const root = document.getElementById('tsx'), id = root.dataset.template;
  const checked = el => Array.from(el.querySelectorAll('input[type=checkbox]:checked')).map(i => i.value);
  if (id === 'energy_diagram') {
    const s = {}; root.querySelectorAll('select[data-k]').forEach(sel => { if (sel.value) s[sel.dataset.k] = sel.value; }); return s;
  }
  if (id === 'chiller_diagram') {
    const s = {chiller_devices: [], chiller_meters: {}, chwp_power_meters: {}, load_devices: [], load_zones: {}};
    root.querySelectorAll('[data-list]').forEach(el => { s[el.dataset.list] = checked(el); });
    root.querySelectorAll('[data-chiller-row]').forEach(row => {
      const id = row.dataset.chillerRow, pms = checked(row.querySelector('.tsx-chips'));
      if (row.querySelector('[data-use]').checked) s.chiller_devices.push(id);
      if (pms.length) s.chiller_meters[id] = pms;
    });
    root.querySelectorAll('[data-chwp-row]').forEach(row => { const pms = checked(row); if (pms.length) s.chwp_power_meters[row.dataset.chwpRow] = pms; });
    root.querySelectorAll('[data-load-row]').forEach(row => {
      const id = row.dataset.loadRow, name = row.querySelector('[data-zone-name]').value.trim();
      if (row.querySelector('[data-use]').checked) s.load_devices.push(id);
      if (name) s.load_zones[id] = {name, devices: checked(row.querySelector('.tsx-chips'))};
    });
    return s;
  }
  if (id === 'template6') return {hide_offline: document.getElementById('tsxHideOffline').checked, hidden_devices: checked(root.querySelector('[data-hidden]'))};
  const groups = {}; root.querySelectorAll('[data-group]').forEach(card => { groups[card.dataset.group] = checked(card.querySelector('.tsx-checks')); });
  return {groups};
}
// ตัวเลือกที่ห้ามซ้ำข้ามแถว (PM ของ Chiller, AC ในโซน): ปิดตัวที่แถวอื่นเลือกแล้ว
function tsxExclusive() {
  const used = {};
  document.querySelectorAll('#tsx input[data-excl]:checked').forEach(i => { (used[i.dataset.excl] = used[i.dataset.excl] || new Map()).set(i.value, i); });
  document.querySelectorAll('#tsx input[data-excl]').forEach(i => {
    const owner = used[i.dataset.excl] && used[i.dataset.excl].get(i.value);
    const taken = !!owner && owner !== i;
    i.disabled = taken; i.closest('.tsx-chip').classList.toggle('is-used', taken);
  });
}
document.addEventListener('change', e => { if (e.target.matches('#tsx input[data-excl]')) tsxExclusive(); });
tsxExclusive();
async function tsxSave(reset) {
  if (reset) {
    const ok = await Swal.fire({icon: 'warning', title: 'คืนค่าเริ่มต้น?', text: 'ลบค่าตั้งของเทมเพลตนี้ทั้งหมด', showCancelButton: true, confirmButtonText: 'คืนค่า', cancelButtonText: 'ยกเลิก'});
    if (!ok.isConfirmed) return;
  }
  const btn = document.getElementById('tsxSaveBtn'); btn.disabled = true;
  try {
    const r = await fetch('dashboard_template_api.php?_=' + Date.now(), {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({action: 'template_settings', template: document.getElementById('tsx').dataset.template, settings: reset ? {} : tsxCollect()})});
    const j = await r.json().catch(() => ({message: 'HTTP ' + r.status}));
    if (!r.ok || j.status !== 'ok') throw new Error(j.message || ('HTTP ' + r.status));
    await Swal.fire({icon: 'success', title: reset ? 'คืนค่าเริ่มต้นแล้ว' : 'บันทึกแล้ว', timer: 1200, showConfirmButton: false});
    location.reload();
  } catch (e) { Swal.fire('บันทึกไม่สำเร็จ', e.message, 'error'); btn.disabled = false; }
}
</script>
