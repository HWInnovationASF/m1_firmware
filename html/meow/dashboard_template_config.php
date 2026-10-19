<?php
$templateConfigPath = __DIR__ . '/config/dashboard_template.json';
$templateConfig = json_decode((string)@file_get_contents($templateConfigPath), true);
$activeTemplate = is_array($templateConfig) ? ($templateConfig['active'] ?? 'energy_diagram') : 'energy_diagram';
$savedTemplateLabels = is_array($templateConfig) && isset($templateConfig['labels']) && is_array($templateConfig['labels']) ? $templateConfig['labels'] : array();
$templateChoices = array(
    'energy_diagram' => array('Energy Diagram','แผนภาพพลังงาน Solar, Grid, Load และ Battery','fa-bolt'),
    'chiller_diagram' => array('Chiller Diagram','แผนผังและค่าระบบ Chiller แบบเรียลไทม์','fa-snowflake'),
    'template1' => array('Template 1','Dashboard Template 1','fa-table-columns'),
    'template2' => array('Template 2','Dashboard Template 2','fa-table-columns'),
    'template3' => array('Template 3','Dashboard Template 3','fa-table-columns'),
    'template4' => array('Template 4','Dashboard Template 4','fa-table-columns'),
    'template5' => array('Template 5','Dashboard Template 5','fa-table-columns'),
    'template6' => array('Template 6','หน้ารูปแบบที่ 6','fa-table-columns'),
);
// ประเภทของ template: แต่ละประเภทมีปุ่มตั้งค่าของตัวเอง (special/M1 Gate = จัดการทะเบียน)
$templateTypes = array(
    'energy' => array('Energy', 'แสดงผลพลังงานและมิเตอร์ไฟฟ้า', 'fa-bolt'),
    'building' => array('Building', 'ระบบอาคารและอุปกรณ์ทั่วไป', 'fa-building'),
    'special' => array('Special', 'งานเฉพาะทาง เช่น ระบบไม้กั้น LPR', 'fa-star'),
);
$templateTypeOf = array('energy_diagram'=>'energy','template6'=>'energy','chiller_diagram'=>'building','template2'=>'building','template3'=>'building','template4'=>'building','template5'=>'building','template1'=>'special');
$templateSettingLinks = array();
foreach (array('energy_diagram' => 'มิเตอร์หลัก / โหมดพลังงาน', 'chiller_diagram' => 'มิเตอร์ / Chiller / โซน Load', 'template2' => 'กลุ่มมิเตอร์', 'template3' => 'กลุ่มมิเตอร์', 'template4' => 'กลุ่มมิเตอร์', 'template5' => 'กลุ่มมิเตอร์', 'template6' => 'อุปกรณ์ที่แสดง') as $tsLinkId => $tsLinkText)
    $templateSettingLinks[$tsLinkId] = array('?page=template-settings&t=' . $tsLinkId, 'fa-sliders', $tsLinkText);
?>
<style>
.dtc-filter{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;padding:10px 12px;border:1px solid var(--th-line);border-radius:12px;background:var(--th-surface)}.dtc-filter label{margin:0;color:var(--th-text2);font-size:.74rem;font-weight:750}.dtc-filter select{min-width:190px;padding:7px 10px;border:1px solid var(--th-ring);border-radius:8px;background:var(--th-surface);color:var(--th-text);font-size:.8rem}.dtc-filter small{color:var(--th-text2);font-size:.72rem}.dtc-filter .dtc-using{margin-left:auto;color:#168958;font-size:.72rem;font-weight:700}.dtc-card[hidden]{display:none}
html[data-gmode=dark] .dtc-filter .dtc-using{color:#5fd394}html[data-gmode=dark] .dtc-filter select{background:#0e1726}
.dtc-type-badge{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:10px;background:var(--th-tint);color:var(--th-text2);font-size:.6rem;font-weight:700;vertical-align:middle}a.dtc-meter-button{text-decoration:none}
.dtc{max-width:1100px;margin:auto}.dtc-head,.dtc-actions{display:flex;align-items:center;justify-content:space-between;gap:12px}.dtc-head{margin-bottom:16px}.dtc-head h3{margin:0}.dtc-head p{margin:4px 0 0;color:#718096;font-size:.78rem}.dtc-actions{justify-content:flex-end;flex-wrap:wrap}.dtc-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.dtc-card{position:relative;display:block;padding:17px;border:2px solid #e0e7ef;border-radius:14px;background:#fff;cursor:pointer;transition:.15s}.dtc-card:hover{border-color:#91bdeb;transform:translateY(-1px)}.dtc-card:has(.dtc-radio:checked){border-color:#1769d2;background:#f3f8ff;box-shadow:0 7px 20px rgba(23,105,210,.12)}.dtc-radio{position:absolute;right:14px;top:14px}.dtc-icon{display:grid;place-items:center;width:42px;height:42px;border-radius:11px;background:#e9f3ff;color:#1769d2}.dtc-card b,.dtc-card small{display:block}.dtc-card b{margin-top:12px}.dtc-card small{margin-top:4px;color:#718096;line-height:1.45}.dtc-name{display:block;width:100%;margin-top:11px;padding:8px 10px;border:1px solid #cddbea;border-radius:8px;background:#fff;font-size:.78rem}.dtc-current{display:inline-flex;margin-top:10px;padding:3px 7px;border-radius:12px;background:#e8f7ef;color:#168958;font-size:.63rem;font-weight:800}.dtc-import{display:none}@media(max-width:800px){.dtc-head{align-items:flex-start;flex-direction:column}.dtc-grid{grid-template-columns:1fr 1fr}}@media(max-width:520px){.dtc-grid{grid-template-columns:1fr}.dtc-actions{width:100%}.dtc-actions .btn{flex:1}}
</style>
<style>.dtc-setting{display:block;margin-top:11px;padding-top:10px;border-top:1px solid #dce7f1}.dtc-setting span{display:block;margin:8px 0 5px;color:#52677d;font-size:.66rem;font-weight:750}.dtc-meter-button{display:flex;align-items:center;justify-content:space-between;width:100%;padding:9px 10px;border:1px solid #bcd1e5;border-radius:8px;background:#f7fbff;color:#245b87;font-size:.7rem;font-weight:700}.dtc-meter-button i{margin-right:6px}.dtc-meter-button b{margin:0;padding:2px 7px;border-radius:10px;background:#1769d2;color:#fff;font-size:.6rem}.dtc-modal[hidden]{display:none}.dtc-modal{position:fixed;inset:0;z-index:10050;display:grid;place-items:center;padding:18px;background:rgba(8,20,35,.55)}.dtc-dialog{width:min(520px,100%);max-height:80vh;overflow:hidden;border-radius:14px;background:#fff;box-shadow:0 22px 60px rgba(0,0,0,.28)}.dtc-dialog-head,.dtc-dialog-foot{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #e1e8ef}.dtc-dialog-head h4{margin:0;font-size:.9rem}.dtc-dialog-head button{border:0;background:transparent;color:#6c7c8c;font-size:1.2rem}.dtc-checks{max-height:52vh;overflow:auto;padding:10px 16px}.dtc-check{display:flex!important;align-items:center;gap:10px;margin:0!important;padding:10px 7px;border-bottom:1px solid #edf1f5;color:#314b63!important;font-size:.72rem!important}.dtc-check input{width:17px;height:17px}.dtc-check small{margin-left:auto;color:#8292a3}.dtc-dialog-foot{justify-content:flex-end;gap:8px;border-top:1px solid #e1e8ef;border-bottom:0}.dtc-dialog-foot button{padding:8px 14px;border:0;border-radius:8px;font-size:.72rem;font-weight:750}.dtc-cancel{background:#edf1f5;color:#536678}.dtc-apply{background:#1769d2;color:#fff}</style>
<style>.dtc-check.is-used{background:#f3f5f7;color:#8a98a6!important}.dtc-check.is-used input{cursor:not-allowed}.dtc-check.is-used small{color:#c16a28;font-weight:700}.dtc-power-meter{width:100%;margin:0 0 6px;padding:8px 9px;border:1px solid #cddbea;border-radius:8px;background:#fff;color:#304b61;font-size:.68rem}</style>
<div class="dtc">
  <div class="dtc-head"><div><h3><i class="fa-solid fa-layer-group"></i> Display Template</h3><p>เลือกไฟล์ที่จะแสดงในตำแหน่งเมนู Energy Diagram หรือ Import/Export การตั้งค่า</p></div><div class="dtc-actions"><button type="button" class="btn btn-dark" onclick="document.getElementById('dtcImport').click()"><i class="fa-solid fa-file-import"></i> Import</button><a class="btn btn-info" href="dashboard_template_api.php?action=export"><i class="fa-solid fa-file-export"></i> Export</a><button type="button" id="dtcSaveButton" class="btn btn-primary" onclick="saveDashboardTemplate(this,event)"><i class="fa-solid fa-floppy-disk"></i> Save</button><input class="dtc-import" id="dtcImport" type="file" accept="application/json,.json" onchange="importDashboardTemplate(this)"></div></div>
  <?php $typeCount = array_count_values($templateTypeOf); $activeType = $templateTypeOf[$activeTemplate] ?? 'energy'; ?>
  <div class="dtc-filter">
    <label for="dtcType"><i class="fa-solid fa-filter"></i> ประเภท Template</label>
    <select id="dtcType" data-active-type="<?php echo htmlspecialchars($activeType);?>">
      <?php foreach($templateTypes as $typeId=>$type){ ?><option value="<?php echo htmlspecialchars($typeId);?>" data-desc="<?php echo htmlspecialchars($type[1]);?>"><?php echo htmlspecialchars($type[0]) . ' (' . (int)($typeCount[$typeId] ?? 0) . ')';?></option><?php } ?>
      <option value="all" data-desc="แสดงทุกประเภท">ทั้งหมด (<?php echo count($templateTypeOf);?>)</option>
    </select>
    <small id="dtcTypeDesc"></small>
    <span class="dtc-using"><i class="fa-solid fa-circle-check"></i> กำลังใช้งาน: <?php echo htmlspecialchars((string)($savedTemplateLabels[$activeTemplate] ?? $templateChoices[$activeTemplate][0] ?? $activeTemplate));?> (<?php echo htmlspecialchars($templateTypes[$activeType][0]);?>)</span>
  </div>
  <div class="dtc-grid">
  <?php foreach($templateTypes as $typeId=>$type){ foreach(array_keys($templateTypeOf, $typeId, true) as $id){ $choice = $templateChoices[$id]; ?>
    <label class="dtc-card" data-type="<?php echo htmlspecialchars($typeId);?>">
      <input class="dtc-radio" type="radio" name="dashboardTemplate" value="<?php echo htmlspecialchars($id);?>" <?php echo $activeTemplate===$id?'checked':'';?>>
      <span class="dtc-icon"><i class="fa-solid <?php echo $choice[2];?>"></i></span>
      <b><?php echo htmlspecialchars($choice[0]);?><span class="dtc-type-badge"><?php echo htmlspecialchars($templateTypes[$typeId][0]);?></span></b><small><?php echo htmlspecialchars($choice[1]);?></small>
      <input class="dtc-name" type="text" data-template-name="<?php echo htmlspecialchars($id);?>" maxlength="60" value="<?php echo htmlspecialchars((string)($savedTemplateLabels[$id] ?? $choice[0]), ENT_QUOTES, 'UTF-8');?>" onclick="event.stopPropagation()" onkeydown="event.stopPropagation()">
      <?php if(isset($templateSettingLinks[$id])){ $link = $templateSettingLinks[$id]; ?>
      <span class="dtc-setting"><span>ตั้งค่า</span>
        <a class="dtc-meter-button" href="<?php echo htmlspecialchars($link[0]);?>" onclick="event.stopPropagation()"><span><i class="fa-solid <?php echo $link[1];?>"></i> <?php echo htmlspecialchars($link[2]);?></span><i class="fa-solid fa-chevron-right"></i></a>
      </span>
      <?php } ?>
      <?php if($activeTemplate===$id){?><span class="dtc-current">กำลังใช้งาน</span><?php }?>
    </label>
  <?php } } ?>
  </div>
</div>
<script>
// เลือกประเภทเพื่อแสดงเฉพาะการ์ดของประเภทนั้น ; เริ่มที่ประเภทของ template ที่ใช้งานอยู่ (จำค่าที่เลือกล่าสุดไว้ในเบราว์เซอร์)
(() => {
  const sel = document.getElementById('dtcType'), KEY = 'mdb_dtc_type';
  let saved = null; try { saved = localStorage.getItem(KEY); } catch (e) {}
  sel.value = saved && sel.querySelector('option[value="' + saved + '"]') ? saved : sel.dataset.activeType;
  const apply = () => {
    document.querySelectorAll('.dtc-card[data-type]').forEach(card => { card.hidden = sel.value !== 'all' && card.dataset.type !== sel.value; });
    document.getElementById('dtcTypeDesc').textContent = sel.selectedOptions[0]?.dataset.desc || '';
  };
  sel.addEventListener('change', () => { try { localStorage.setItem(KEY, sel.value); } catch (e) {} apply(); });
  apply();
})();
</script>
<script>
async function templateRequest(options){const response=await fetch('dashboard_template_api.php?_='+Date.now(),{credentials:'same-origin',cache:'no-store',...options});const data=await response.json();if(!response.ok||data.status!=='ok')throw new Error(data.message||'Save failed');return data}
async function saveDashboardTemplate(button,event){event?.preventDefault();event?.stopPropagation();const selected=document.querySelector('input[name="dashboardTemplate"]:checked');if(!selected)return;const labels={};document.querySelectorAll('[data-template-name]').forEach(input=>{labels[input.dataset.templateName]=input.value.trim()});const oldHtml=button?.innerHTML;if(button){button.disabled=true;button.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Saving...'}try{await templateRequest({method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({active:selected.value,labels})});await Swal.fire({icon:'success',title:'บันทึก Template แล้ว',timer:1300,showConfirmButton:false});location.reload()}catch(error){if(button){button.disabled=false;button.innerHTML=oldHtml}Swal.fire('บันทึกไม่สำเร็จ',error.message,'error')}}
async function importDashboardTemplate(input){if(!input.files.length)return;const form=new FormData();form.append('template_file',input.files[0]);try{await templateRequest({method:'POST',body:form});await Swal.fire({icon:'success',title:'Import สำเร็จ',timer:1200,showConfirmButton:false});location.reload()}catch(error){Swal.fire('Import ไม่สำเร็จ',error.message,'error')}finally{input.value=''}}
</script>
