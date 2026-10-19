<?php
$plRgl = json_decode($rgl_txt ?? '{}', true) ?: [];
$plPath = './config/parameter_list.json';
$plSaved = file_exists($plPath) ? json_decode(file_get_contents($plPath), true) : [];
if (!is_array($plSaved)) $plSaved = [];
$plSavedMap = [];
foreach ($plSaved as $plKey => $plItem) {
    if (is_array($plItem) && isset($plItem['Parameter'])) {
        $plInfo = isset($plItem['InfoData']) && is_array($plItem['InfoData']) ? $plItem['InfoData'] : [];
        $plSavedMap[(string)$plItem['Parameter']] = [
            'id' => $plItem['ID'] ?? 0,
            'order' => $plItem['Order'] ?? 0,
            'display_name' => $plInfo['NameShort'] ?? $plInfo['DisplayName'] ?? $plInfo['Name'] ?? $plItem['Parameter'],
            'full_name' => $plInfo['Name'] ?? $plItem['Parameter'],
            'unit' => $plInfo['Unit'] ?? '',
            'icon' => $plInfo['Icon'] ?? '',
            'category' => $plInfo['Category'] ?? '',
            'description' => $plInfo['Description'] ?? ''
        ];
    } elseif (is_string($plKey) && is_array($plItem)) {
        $plSavedMap[$plKey] = $plItem;
    }
}
$plCanEdit = in_array(strtolower($_SESSION['user_type'] ?? ''), ['admin', 'superadmin'], true);
?>
<div class="pl-wrap">
  <div class="pl-head">
    <div>
      <h3><i class="fa-solid fa-tags"></i> Parameter List</h3>
      <p>จัดการชื่อแสดงผล หน่วย หมวดหมู่ และคำอธิบายของ Parameter ที่ใช้ร่วมกันทั้งระบบ</p>
    </div>
    <div class="pl-actions"><button id="plAdd" type="button"<?php echo $plCanEdit ? '' : ' disabled'; ?>><i class="fa-solid fa-plus"></i> Add Parameter</button><button id="plSave" type="button"<?php echo $plCanEdit ? '' : ' disabled'; ?>><i class="fa-solid fa-floppy-disk"></i> Save</button></div>
  </div>
  <?php if (!$plCanEdit): ?><div class="pl-notice"><i class="fa-solid fa-lock"></i> อ่านได้อย่างเดียว — ต้องใช้สิทธิ์ Admin หรือ Superadmin เพื่อแก้ไข</div><?php endif; ?>
  <div class="pl-tools">
    <label><span><i class="fa-solid fa-magnifying-glass"></i> Search</span><input id="plSearch" type="search" placeholder="ค้นหา Parameter, Name, Model หรือ Category"></label>
    <label><span><i class="fa-solid fa-layer-group"></i> Category</span><select id="plCategory"><option value="">All categories</option></select></label>
    <span id="plCount"></span>
  </div>
  <div class="pl-table-wrap">
    <table class="pl-table">
      <colgroup><col class="pl-col-id"><col class="pl-col-key"><col class="pl-col-short"><col class="pl-col-name"><col class="pl-col-category"><col class="pl-col-description"><col class="pl-col-model"><col class="pl-col-unit"><col class="pl-col-actions"></colgroup>
      <thead><tr><th>ID</th><th>Parameter key</th><th>NameShort</th><th>Name</th><th>Category</th><th>Description</th><th>Used in rgl.json</th><th>Unit</th><th>Actions</th></tr></thead>
      <tbody id="plRows"></tbody>
    </table>
  </div>
  <div class="pl-foot"><span id="plStatus">Parameter key มาจากค่า name ใน rgl.json และไม่สามารถแก้ไขจากหน้านี้ได้</span></div>
</div>
<button id="plFloatingSave" class="pl-floating-save" type="button"<?php echo $plCanEdit ? '' : ' disabled'; ?>><i class="fa-solid fa-floppy-disk"></i><span>Save Parameter List</span></button>
<div id="plModal" class="pl-modal" hidden><div class="pl-dialog">
  <div class="pl-dialog-head"><h4>Add Parameter</h4><button id="plClose" type="button">&times;</button></div>
  <div class="pl-form">
    <label><span>Parameter</span><input id="plNewParameter" placeholder="ตัวอักษรอังกฤษ ตัวเลข - _"></label>
    <label><span>Name</span><input id="plNewName" placeholder="ชื่อเต็ม"></label>
    <label><span>NameShort</span><input id="plNewNameShort" placeholder="ชื่อสำหรับแสดงผล"></label>
    <label><span>Unit</span><input id="plNewUnit" placeholder="kW, V, A..."></label>
    <label class="pl-form-wide"><span>Icon</span><input id="plNewIcon" placeholder="assets/img/icon/..."></label>
  </div>
  <div id="plAddError" class="pl-error"></div>
  <div class="pl-dialog-foot"><button id="plCancel" type="button">Cancel</button><button id="plConfirmAdd" type="button">Add</button></div>
</div></div>
<script>
(()=>{
  const maps=<?php echo json_encode($plRgl,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  let saved=<?php echo json_encode($plSavedMap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
  const canEdit=<?php echo $plCanEdit?'true':'false'; ?>;
  const search=document.getElementById('plSearch'), category=document.getElementById('plCategory'), rows=document.getElementById('plRows');
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const title=v=>String(v).replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
  const standardCategories=['Active Power','Apparent Power','Current','Energy','Environment','Frequency','Harmonics','Motor','Power Factor','Reactive Power','Safety & Status','Voltage','Other'];
  const categorySelectOptions=current=>{
    const value=String(current||'');
    const options=[...new Set([...standardCategories,...Object.values(saved).map(item=>item?.category).filter(Boolean),...(value?[value]:[])])].sort();
    return '<option value="">Select category...</option>'+options.map(option=>'<option value="'+esc(option)+'"'+(option===value?' selected':'')+'>'+esc(option)+'</option>').join('');
  };
  const parameters={};
  Object.entries(maps).forEach(([model,map])=>{
    const banks=(map?.holding||map?.input)?Object.entries(map):[['default',map]];
    banks.forEach(([fn,registers])=>Object.entries(registers||{}).forEach(([address,info])=>{
      if(!info?.name)return;
      const item=parameters[info.name]||(parameters[info.name]={key:info.name,models:new Set(),functions:new Set(),units:new Set(),types:new Set(),count:0});
      item.models.add(model); item.functions.add(fn); item.count++;
      if(info.unit)item.units.add(info.unit);
      if(info.type)item.types.add(info.type);
    }));
  });
  Object.keys(saved).forEach(key=>{
    if(!parameters[key])parameters[key]={key,models:new Set(),functions:new Set(),units:new Set(saved[key]?.unit?[saved[key].unit]:[]),types:new Set(),count:0};
  });
  function capture(){
    rows.querySelectorAll('tr[data-parameter]').forEach(tr=>{
      saved[tr.dataset.parameter]={
        id:Number(tr.dataset.id)||0,
        order:Number(tr.dataset.order)||0,
        display_name:tr.querySelector('[data-field="display_name"]').value.trim(),
        full_name:tr.querySelector('[data-field="full_name"]').value.trim(),
        unit:tr.dataset.unit||'',
        icon:saved[tr.dataset.parameter]?.icon||'',
        category:tr.querySelector('[data-field="category"]').value.trim(),
        description:tr.querySelector('[data-field="description"]').value.trim()
      };
    });
  }
  function categoryOptions(){
    const selected=category.value;
    const values=[...new Set(Object.values(saved).map(v=>v?.category).filter(Boolean))].sort();
    category.innerHTML='<option value="">All categories</option>'+values.map(v=>'<option value="'+esc(v)+'">'+esc(v)+'</option>').join('');
    if(values.includes(selected))category.value=selected;
  }
  function render(keep=false){
    if(keep)capture();
    const q=search.value.trim().toLowerCase(), cat=category.value;
    const list=Object.values(parameters).filter(item=>{
      const cfg=saved[item.key]||{}, hay=[item.key,cfg.display_name,cfg.full_name,cfg.category,cfg.description,...item.models].join(' ').toLowerCase();
      return (!q||hay.includes(q))&&(!cat||cfg.category===cat);
    }).sort((a,b)=>(Number(saved[a.key]?.order)||999999)-(Number(saved[b.key]?.order)||999999)||a.key.localeCompare(b.key));
    rows.innerHTML=list.map(item=>{
      const cfg=saved[item.key]||{}, fallback=title(item.key), disabled=canEdit?'':' disabled';
      const models=[...item.models].sort();
      const unit=cfg.unit||[...item.units].sort().join(', ');
      return '<tr data-parameter="'+esc(item.key)+'" data-id="'+esc(cfg.id||'')+'" data-order="'+esc(cfg.order||'')+'" data-unit="'+esc(unit)+'">'+
        '<td class="pl-id-cell"><span>'+(cfg.id||'—')+'</span></td>'+
        '<td class="pl-key-cell"><code>'+esc(item.key)+'</code></td>'+
        '<td class="pl-input-cell"><input data-field="display_name" aria-label="NameShort of '+esc(item.key)+'" value="'+esc(cfg.display_name||fallback)+'"'+disabled+'></td>'+
        '<td class="pl-input-cell"><input data-field="full_name" aria-label="Name of '+esc(item.key)+'" value="'+esc(cfg.full_name||cfg.display_name||fallback)+'"'+disabled+'></td>'+
        '<td class="pl-input-cell"><select data-field="category" aria-label="Category of '+esc(item.key)+'"'+disabled+'>'+categorySelectOptions(cfg.category||'')+'</select></td>'+
        '<td class="pl-description-cell"><textarea data-field="description" aria-label="Description of '+esc(item.key)+'" rows="2" placeholder="รายละเอียดการใช้งาน Parameter"'+disabled+'>'+esc(cfg.description||'')+'</textarea></td>'+
        '<td class="pl-model-cell"><span class="pl-models" data-pl-tooltip="'+esc(models.join(', ')||'ยังไม่ได้ Map กับ Model')+'"><i class="fa-solid fa-microchip"></i><span>'+esc(models.slice(0,2).join(', ')||'Not mapped')+'</span>'+(models.length>2?'<b class="pl-more-models" data-pl-tooltip="มี Model เพิ่มเติมอีก '+(models.length-2)+' รายการ">+'+(models.length-2)+'</b>':'')+'</span></td>'+
        '<td class="pl-unit-cell"><span>'+esc(unit||'—')+'</span></td>'+
        '<td class="pl-row-actions">'+(canEdit?'<button type="button" data-action="save" aria-label="Save '+esc(item.key)+'" data-pl-tooltip="Save this parameter"><i class="fa-solid fa-floppy-disk"></i></button><button type="button" data-action="edit" aria-label="Edit '+esc(item.key)+'" data-pl-tooltip="Edit parameter"><i class="fa-solid fa-pen"></i></button><button type="button" data-action="remove" aria-label="Remove '+esc(item.key)+'" data-pl-tooltip="'+(item.count?'Cannot remove: used in rgl.json':'Remove parameter')+'"'+(item.count?' disabled':'')+'><i class="fa-solid fa-trash"></i></button>':'—')+'</td></tr>';
    }).join('');
    document.getElementById('plCount').textContent=list.length+' / '+Object.keys(parameters).length+' parameters';
  }
  let searchTimer=0;
  search.addEventListener('input',()=>{
    clearTimeout(searchTimer);
    searchTimer=setTimeout(()=>render(true),140);
  });
  category.addEventListener('change',()=>render(true));
  function saveParameterList(){
    capture();
    const buttons=[document.getElementById('plSave'),document.getElementById('plFloatingSave')],status=document.getElementById('plStatus');
    buttons.forEach(button=>button.disabled=true);status.textContent='กำลังบันทึก...';
    fetch('./mx_fn_api.php?action=parameter_list',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'replace=1&dataE='+encodeURIComponent(JSON.stringify(saved))})
      .then(async response=>{const text=await response.text();if(!response.ok)throw Error(text);return JSON.parse(text);})
      .then(data=>{
        saved={};
        (data||[]).forEach(row=>{if(row?.Parameter){const info=row.InfoData||{};saved[row.Parameter]={id:row.ID||0,order:row.Order||0,display_name:info.NameShort||info.DisplayName||info.Name||row.Parameter,full_name:info.Name||row.Parameter,unit:info.Unit||'',icon:info.Icon||'',category:info.Category||'',description:info.Description||''};}});
        categoryOptions();render();status.textContent='บันทึก parameter_list.json เรียบร้อย';
      })
      .catch(error=>status.textContent='บันทึกไม่สำเร็จ: '+error.message)
      .finally(()=>buttons.forEach(button=>button.disabled=!canEdit));
  }
  function confirmSave(){
    capture();
    const count=Object.keys(saved).length;
    if(window.Swal){
      Swal.fire({title:'ยืนยันการบันทึก Parameter List?',text:'ระบบจะบันทึก '+count+' Parameters และจัดเรียง ID ใหม่',icon:'question',showCancelButton:true,confirmButtonText:'Save',cancelButtonText:'Cancel'})
        .then(result=>{if(result.isConfirmed)saveParameterList();});
    }else if(window.confirm('ยืนยันการบันทึก Parameter List '+count+' Parameters?'))saveParameterList();
  }
  document.getElementById('plSave').addEventListener('click',confirmSave);
  document.getElementById('plFloatingSave').addEventListener('click',confirmSave);
  const modal=document.getElementById('plModal'),newParameter=document.getElementById('plNewParameter');
  const closeModal=()=>{modal.hidden=true;document.getElementById('plAddError').textContent='';};
  document.getElementById('plAdd').addEventListener('click',()=>{modal.hidden=false;newParameter.focus();});
  document.getElementById('plClose').addEventListener('click',closeModal);
  document.getElementById('plCancel').addEventListener('click',closeModal);
  modal.addEventListener('click',event=>{if(event.target===modal)closeModal();});
  newParameter.addEventListener('input',()=>newParameter.value=newParameter.value.replace(/[^A-Za-z0-9_-]/g,''));
  document.getElementById('plConfirmAdd').addEventListener('click',()=>{
    capture();
    const key=newParameter.value.trim(),error=document.getElementById('plAddError');
    if(!key){error.textContent='กรุณากรอก Parameter';return;}
    if(saved[key]||parameters[key]){error.textContent='Parameter นี้มีอยู่แล้ว';return;}
    const maxOrder=Math.max(0,...Object.values(saved).map(v=>Number(v.order)||0));
    saved[key]={id:0,order:maxOrder+1,full_name:document.getElementById('plNewName').value.trim()||key,display_name:document.getElementById('plNewNameShort').value.trim()||document.getElementById('plNewName').value.trim()||key,unit:document.getElementById('plNewUnit').value.trim(),icon:document.getElementById('plNewIcon').value.trim(),category:'',description:''};
    parameters[key]={key,models:new Set(),functions:new Set(),units:new Set(saved[key].unit?[saved[key].unit]:[]),types:new Set(),count:0};
    ['plNewParameter','plNewName','plNewNameShort','plNewUnit','plNewIcon'].forEach(id=>document.getElementById(id).value='');
    search.value='';category.value='';closeModal();render();
    document.getElementById('plStatus').textContent='เพิ่ม Parameter แล้ว กรุณากด Save เพื่อบันทึก';
  });
  categoryOptions();
  render();
  rows.addEventListener('click',event=>{
    const button=event.target.closest('button[data-action]');
    if(!button||button.disabled)return;
    const tr=button.closest('tr[data-parameter]'),key=tr?.dataset.parameter;
    if(!tr||!key)return;
    if(button.dataset.action==='save'){
      capture();
      button.disabled=true;
      const status=document.getElementById('plStatus');
      status.textContent='กำลังบันทึก '+key+'...';
      fetch('./mx_fn_api.php?action=parameter_list',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'dataE='+encodeURIComponent(JSON.stringify({[key]:saved[key]}))
      }).then(async response=>{
        const text=await response.text();
        if(!response.ok)throw Error(text);
        return JSON.parse(text);
      }).then(data=>{
        const row=(data||[]).find(item=>item?.Parameter===key);
        if(row){
          tr.dataset.id=String(row.ID||0);
          tr.dataset.order=String(row.Order||0);
          const idCell=tr.querySelector('.pl-id-cell span');
          if(idCell)idCell.textContent=row.ID||'—';
        }
        tr.classList.add('pl-saved');
        setTimeout(()=>tr.classList.remove('pl-saved'),900);
        status.textContent='บันทึก '+key+' เรียบร้อย';
      }).catch(error=>{
        status.textContent='บันทึก '+key+' ไม่สำเร็จ: '+error.message;
      }).finally(()=>button.disabled=false);
      return;
    }
    if(button.dataset.action==='edit'){
      const input=tr.querySelector('[data-field="display_name"]');
      tr.classList.add('pl-editing');input?.focus();input?.select();
      setTimeout(()=>tr.classList.remove('pl-editing'),700);
      return;
    }
    const remove=()=>{delete saved[key];delete parameters[key];render();categoryOptions();document.getElementById('plStatus').textContent='Removed '+key+' — click Save to confirm';};
    if(window.Swal)Swal.fire({title:'Remove parameter?',text:key,icon:'warning',showCancelButton:true,confirmButtonText:'Remove',cancelButtonText:'Cancel',confirmButtonColor:'#d94b4b'}).then(result=>{if(result.isConfirmed)remove();});
    else if(window.confirm('Remove '+key+'?'))remove();
  });
  const plTooltip=document.createElement('div');
  plTooltip.className='pl-tooltip';
  plTooltip.setAttribute('role','tooltip');
  document.body.appendChild(plTooltip);
  let activeTooltipTarget=null;
  const positionTooltip=target=>{
    const gap=9,targetRect=target.getBoundingClientRect(),rect=plTooltip.getBoundingClientRect();
    let left=targetRect.left+(targetRect.width-rect.width)/2,top=targetRect.bottom+gap;
    if(left+rect.width>window.innerWidth-8)left=window.innerWidth-rect.width-8;
    if(top+rect.height>window.innerHeight-8)top=targetRect.top-rect.height-gap;
    plTooltip.style.left=Math.max(8,left)+'px';plTooltip.style.top=Math.max(8,top)+'px';
  };
  document.addEventListener('pointerover',event=>{const target=event.target.closest('[data-pl-tooltip]');if(!target||target===activeTooltipTarget)return;activeTooltipTarget=target;plTooltip.textContent=target.dataset.plTooltip;plTooltip.classList.add('show');positionTooltip(target);});
  document.addEventListener('pointerout',event=>{const target=event.target.closest('[data-pl-tooltip]');if(target&&target===activeTooltipTarget&&!target.contains(event.relatedTarget)){activeTooltipTarget=null;plTooltip.classList.remove('show');}});
  const floatingSave=document.getElementById('plFloatingSave');
  if(floatingSave.parentNode!==document.body)document.body.appendChild(floatingSave);
  const tableWrap=document.querySelector('.pl-table-wrap');
  let scrollFrame=0;
  function updateFloatingSave(){
    const top=Math.max(window.scrollY||document.documentElement.scrollTop||0,tableWrap?.scrollTop||0);
    floatingSave.classList.toggle('show',top>180);
    activeTooltipTarget=null;plTooltip.classList.remove('show');
    scrollFrame=0;
  }
  const queueScrollUpdate=()=>{if(!scrollFrame)scrollFrame=requestAnimationFrame(updateFloatingSave);};
  window.addEventListener('scroll',queueScrollUpdate,{passive:true});
  tableWrap?.addEventListener('scroll',queueScrollUpdate,{passive:true});
  updateFloatingSave();
})();
</script>
<style>
.pl-wrap{max-width:1500px;margin:0 auto;padding:4px 2px 35px;color:#26394e}.pl-head{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:13px}.pl-head h3{margin:0}.pl-head h3 i{color:#1769d2}.pl-head p{margin:3px 0;color:#718096;font-size:.8rem}.pl-actions{display:flex;gap:8px}.pl-head button{border:0;border-radius:9px;background:#1769d2;color:#fff;padding:9px 15px;font-weight:700}.pl-head button:disabled{opacity:.5}.pl-notice{margin-bottom:12px;padding:10px 13px;border:1px solid #f0d38a;border-radius:9px;background:#fff8df;color:#795b13;font-size:.75rem}.pl-tools{display:flex;align-items:end;gap:12px;padding:13px;margin-bottom:11px;border:1px solid #dfe7f0;border-radius:12px;background:#fff}.pl-tools label:first-child{min-width:340px}.pl-tools label span{display:block;margin-bottom:4px;color:#60748a;font-size:.7rem;font-weight:700}.pl-tools select,.pl-tools input{width:100%;min-height:39px;border:1px solid #d4deea;border-radius:8px;padding:7px 9px}.pl-tools>span{margin-left:auto;color:#718096;font-size:.72rem}.pl-table-wrap{max-height:68vh;overflow:auto;border:1px solid #dfe7f0;border-radius:12px;background:#fff}.pl-table{width:100%;min-width:1250px;border-collapse:separate;border-spacing:0;font-size:.75rem}.pl-table th{position:sticky;top:0;z-index:2;padding:10px;background:#eaf2fb;color:#49627e;text-align:left}.pl-table td{padding:8px 10px;border-top:1px solid #edf1f5;vertical-align:top}.pl-table tr:hover td{background:#f7faff}.pl-table input,.pl-table textarea{width:100%;min-width:145px;border:1px solid #d6e0ea;border-radius:7px;padding:6px 8px;resize:vertical}.pl-table textarea{min-width:220px}.pl-table code{display:block;color:#1769d2;font-weight:700}.pl-table small{display:block;margin-top:3px;color:#8292a5}.pl-models{display:inline-block;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.pl-foot{padding:10px;color:#718096;font-size:.72rem}.pl-floating-save{position:fixed;left:50%;bottom:24px;z-index:1500;display:flex;align-items:center;gap:9px;padding:13px 26px;border:0;border-radius:999px;background:linear-gradient(135deg,#064a99,#0a63c9);color:#fff;font-weight:700;box-shadow:0 10px 26px rgba(6,74,153,.38);cursor:pointer;transform:translateX(-50%) translateY(20px);opacity:0;pointer-events:none;transition:.22s}.pl-floating-save.show{transform:translateX(-50%);opacity:1;pointer-events:auto}.pl-floating-save.show:hover{transform:translateX(-50%) translateY(-2px);filter:brightness(1.05)}.pl-floating-save:disabled{opacity:.45}.pl-modal[hidden]{display:none}.pl-modal{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(15,31,50,.55)}.pl-dialog{width:min(600px,100%);padding:18px;border-radius:15px;background:#fff;box-shadow:0 22px 55px rgba(0,0,0,.25)}.pl-dialog-head,.pl-dialog-foot{display:flex;align-items:center;justify-content:space-between;gap:10px}.pl-dialog-head h4{margin:0}.pl-dialog-head button{border:0;background:none;font-size:1.7rem;color:#7a8795}.pl-form{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:18px 0}.pl-form label span{display:block;margin-bottom:5px;font-size:.72rem;font-weight:700;color:#60748a}.pl-form input{width:100%;border:1px solid #d4deea;border-radius:8px;padding:9px}.pl-form-wide{grid-column:1/-1}.pl-dialog-foot{justify-content:flex-end}.pl-dialog-foot button{border:0;border-radius:8px;padding:9px 18px;font-weight:700}.pl-dialog-foot button:last-child{background:#1769d2;color:#fff}.pl-error{min-height:20px;color:#d13c3c;font-size:.75rem}@media(max-width:700px){.pl-head,.pl-tools{align-items:stretch;flex-direction:column}.pl-actions{width:100%}.pl-actions button{flex:1}.pl-tools label:first-child{min-width:0}.pl-tools>span{margin-left:0}.pl-form{grid-template-columns:1fr}.pl-floating-save{bottom:14px;padding:12px 20px}}
/* Modern Parameter List */
.pl-wrap{max-width:1500px;padding:4px 2px 42px;color:#1e3148;font-family:Inter,"Segoe UI",Arial,sans-serif}.pl-head{position:relative;overflow:hidden;margin-bottom:16px;padding:22px 24px;border-radius:17px;color:#fff;background:linear-gradient(125deg,#0758b7 0%,#1479df 68%,#26a7e8 100%);box-shadow:0 11px 28px rgba(11,91,177,.18)}.pl-head:after{content:"";position:absolute;right:-55px;bottom:-90px;width:215px;height:215px;border:34px solid rgba(255,255,255,.09);border-radius:50%}.pl-head>div{z-index:1}.pl-head h3{font-size:22px;font-weight:800}.pl-head h3 i{display:inline-grid;place-items:center;width:39px;height:39px;margin-right:8px;border:1px solid rgba(255,255,255,.25);border-radius:11px;background:rgba(255,255,255,.15);color:#fff;font-size:16px;vertical-align:middle}.pl-head p{max-width:760px;margin:7px 0 0;color:rgba(255,255,255,.82);font-size:12px}.pl-actions{z-index:2}.pl-head button{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:42px;padding:9px 16px;border:1px solid rgba(255,255,255,.32);border-radius:10px;background:rgba(255,255,255,.14);color:#fff;box-shadow:none;cursor:pointer}.pl-head button:hover{background:rgba(255,255,255,.23);transform:translateY(-1px)}.pl-head #plSave{border-color:#73e2ac;background:#168f58}.pl-head #plSave:hover{background:#117a4b}.pl-notice{padding:11px 14px;border-color:#f0d28a;border-radius:11px}.pl-tools{gap:14px;margin-bottom:14px;padding:15px 17px;border-color:#dce6f0;border-radius:14px;box-shadow:0 4px 14px rgba(32,61,96,.045)}.pl-tools label{min-width:190px}.pl-tools label:first-child{flex:1;min-width:320px}.pl-tools label span{margin-bottom:6px;color:#49627d;font-size:11px}.pl-tools label span i{margin-right:5px;color:#1672cf}.pl-tools select,.pl-tools input{min-height:44px;padding:9px 12px;border:1.5px solid #bfccda;border-radius:10px;background:#fff;color:#233a54;font-size:13px;outline:0;transition:.18s}.pl-tools select:hover,.pl-tools input:hover{border-color:#8fa7bf}.pl-tools select:focus,.pl-tools input:focus{border-color:#1672cf;box-shadow:0 0 0 3px rgba(22,114,207,.11)}.pl-tools>span{align-self:center;padding:6px 10px;border-radius:999px;background:#edf4fb;color:#4d6987;font-weight:700;white-space:nowrap}.pl-table-wrap{max-height:69vh;border-color:#d9e4ef;border-radius:14px;box-shadow:0 5px 18px rgba(32,61,96,.055)}.pl-table{font-size:12px}.pl-table th{padding:12px 11px;border-bottom:1px solid #cad9e8;background:#e8f2fc;color:#315a84;font-size:10px;font-weight:800;letter-spacing:.35px;text-transform:uppercase}.pl-table td{padding:10px 11px;border-top:1px solid #edf2f7}.pl-table tbody tr:nth-child(even) td{background:#fcfdff}.pl-table tr:hover td{background:#f2f8fe}.pl-table input,.pl-table textarea{min-height:39px;padding:8px 9px;border:1.5px solid #c7d3df;border-radius:8px;background:#fff;color:#263c54;font-size:12px;outline:0;transition:.16s}.pl-table textarea{min-height:58px}.pl-table input:hover,.pl-table textarea:hover{border-color:#91a8bf}.pl-table input:focus,.pl-table textarea:focus{border-color:#1672cf;box-shadow:0 0 0 3px rgba(22,114,207,.1)}.pl-table input:disabled,.pl-table textarea:disabled{border-color:#e2e8ef;background:#f3f6f9;color:#708196}.pl-table code{display:inline-block;padding:4px 7px;border-radius:6px;background:#eaf3ff;color:#1268c7;font-size:11px}.pl-models{padding:4px 7px;border:1px solid #dbe5ef;border-radius:6px;background:#f7fafd;color:#557089}.pl-foot{padding:11px 4px}.pl-floating-save{background:linear-gradient(135deg,#1268c7,#0879df)}.pl-dialog{padding:0;overflow:hidden;border:1px solid #dbe5ef;border-radius:17px}.pl-dialog-head{padding:17px 19px;border-bottom:1px solid #e7edf4;background:#f8fbfe}.pl-dialog-head h4{color:#24425f;font-size:17px;font-weight:800}.pl-form{margin:0;padding:18px 19px}.pl-form input{min-height:43px;border:1.5px solid #c4d1de;border-radius:9px;outline:0}.pl-form input:focus{border-color:#1672cf;box-shadow:0 0 0 3px rgba(22,114,207,.1)}.pl-error{padding:0 19px}.pl-dialog-foot{padding:14px 19px;border-top:1px solid #e7edf4;background:#fafcfe}.pl-dialog-foot button:first-child{border:1px solid #d4dee8;background:#fff;color:#60748a}.pl-dialog-foot button:last-child{background:linear-gradient(135deg,#1268c7,#0879df)}
/* Professional table layout */
.pl-table{min-width:1420px;table-layout:fixed}.pl-col-id{width:68px}.pl-col-key{width:190px}.pl-col-short{width:170px}.pl-col-name{width:230px}.pl-col-category{width:155px}.pl-col-description{width:285px}.pl-col-model{width:210px}.pl-col-unit{width:90px}.pl-table th,.pl-table td{vertical-align:middle}.pl-table th:first-child,.pl-table td:first-child{position:sticky;left:0;z-index:3}.pl-table th:nth-child(2),.pl-table td:nth-child(2){position:sticky;left:68px;z-index:3;box-shadow:7px 0 10px -10px rgba(24,54,84,.75)}.pl-table th:first-child,.pl-table th:nth-child(2){z-index:5;background:#dcecfb}.pl-table td:first-child,.pl-table td:nth-child(2){background:#fff}.pl-table tbody tr:nth-child(even) td:first-child,.pl-table tbody tr:nth-child(even) td:nth-child(2){background:#f8fbfe}.pl-table tbody tr:hover td:first-child,.pl-table tbody tr:hover td:nth-child(2){background:#eef6fd}.pl-id-cell{text-align:center}.pl-id-cell>span{display:inline-grid;place-items:center;min-width:30px;height:30px;padding:0 7px;border:1px solid #cfe0ef;border-radius:8px;background:#f1f7fc;color:#315d87;font-size:11px;font-weight:800}.pl-key-cell code{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.pl-key-cell small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.pl-input-cell input{min-width:0!important}.pl-description-cell textarea{min-width:0!important;line-height:1.45}.pl-model-cell{text-align:left}.pl-models{display:inline-flex;align-items:center;gap:6px;max-width:100%;padding:6px 8px}.pl-models>span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.pl-models i{flex:none;color:#6986a3;font-size:10px}.pl-more-models{flex:none;padding:0;border:0;background:transparent;color:#dc7b00;font-size:11px;font-weight:900}.pl-more-models:hover{color:#a95e00}.pl-unit-cell{text-align:center}.pl-unit-cell>span{display:inline-block;min-width:42px;padding:5px 8px;border:1px solid #cce7d9;border-radius:7px;background:#edf9f3;color:#168958;font-size:11px;font-weight:800}.pl-table tbody tr{height:72px}.pl-table input::placeholder,.pl-table textarea::placeholder{color:#a6b2bf}
.pl-more-models{color:#168958!important}.pl-more-models:hover{color:#0d6f45!important}
/* Comfortable table typography */
.pl-table{font-family:"Segoe UI",Tahoma,Arial,sans-serif;font-size:13px;line-height:1.5}.pl-table th{padding:13px 11px;background:#edf4fa;color:#45617d;font-size:11.5px;font-weight:700;letter-spacing:0;text-transform:none;white-space:nowrap}.pl-table th:first-child,.pl-table th:nth-child(2){background:#e5f0f9}.pl-table td{padding:11px;border-top-color:#e8eef4;color:#40546a;font-weight:400}.pl-table tbody tr{height:auto;min-height:72px}.pl-table tbody tr:nth-child(even) td{background:#fafcfe}.pl-table tr:hover td{background:#f3f8fc}.pl-table td:first-child,.pl-table td:nth-child(2){background:#fff}.pl-table tbody tr:nth-child(even) td:first-child,.pl-table tbody tr:nth-child(even) td:nth-child(2){background:#fafcfe}.pl-table tbody tr:hover td:first-child,.pl-table tbody tr:hover td:nth-child(2){background:#f3f8fc}.pl-table input,.pl-table textarea{font-family:inherit;font-size:13px;font-weight:400;line-height:1.45;color:#354a60}.pl-table textarea{min-height:62px}.pl-table code{font-family:"Cascadia Mono",Consolas,monospace;font-size:11.5px;font-weight:700}.pl-table small{margin-top:5px;color:#8795a5;font-size:10.5px;font-weight:400;line-height:1.35}.pl-id-cell>span{color:#496883;font-size:11px;font-weight:700}.pl-models{color:#60758b;font-size:11px;font-weight:500}.pl-unit-cell>span{font-size:11px;font-weight:700}
.pl-table select{width:100%;min-width:0;min-height:39px;padding:8px 30px 8px 9px;border:1.5px solid #c7d3df;border-radius:8px;background-color:#fff;color:#354a60;font-family:inherit;font-size:12px;outline:0;cursor:pointer}.pl-table select:hover{border-color:#91a8bf}.pl-table select:focus{border-color:#1672cf;box-shadow:0 0 0 3px rgba(22,114,207,.1)}.pl-table select:disabled{border-color:#e2e8ef;background:#f3f6f9;color:#708196;cursor:not-allowed}
/* Natural editor table */
.pl-table-wrap{background:#fff}.pl-table{min-width:1220px;table-layout:auto}.pl-col-id{width:58px}.pl-col-key{width:165px}.pl-col-short{width:155px}.pl-col-name{width:210px}.pl-col-category{width:170px}.pl-col-description{width:260px}.pl-col-model{width:185px}.pl-col-unit{width:76px}.pl-table th{padding:13px 12px;border-bottom:2px solid #d9e5f0;background:#f6f9fc;color:#49627a}.pl-table th:first-child,.pl-table th:nth-child(2){position:sticky;left:auto;z-index:2;background:#f6f9fc}.pl-table td{padding:12px;border-top:1px solid #e9eef3;background:#fff!important}.pl-table td:first-child,.pl-table td:nth-child(2){position:static;box-shadow:none;background:#fff!important}.pl-table tbody tr:nth-child(even) td{background:#fcfdfe!important}.pl-table tbody tr:hover td{background:#f7fafc!important}.pl-table tbody tr{height:auto}.pl-id-cell{text-align:center}.pl-id-cell>span{display:inline;color:#718398;background:transparent;border:0;padding:0;font-size:12px;font-weight:600}.pl-table code{padding:0;background:transparent;color:#1769c2;font-size:12px}.pl-key-cell small{white-space:normal}.pl-table input,.pl-table textarea,.pl-table select{border:1px solid #d9e1e9;border-radius:7px;background:#fbfcfd;color:#34495e;box-shadow:none}.pl-table input{min-height:38px}.pl-table textarea{min-height:56px;resize:vertical}.pl-table input:hover,.pl-table textarea:hover,.pl-table select:hover{border-color:#aebdcc;background:#fff}.pl-table input:focus,.pl-table textarea:focus,.pl-table select:focus{border-color:#3984ca;background:#fff;box-shadow:0 0 0 2px rgba(57,132,202,.1)}.pl-table input:disabled,.pl-table textarea:disabled,.pl-table select:disabled{border-color:transparent;background:transparent;color:#586b7e;opacity:1}.pl-models{display:inline-flex;max-width:100%;padding:0;border:0;background:transparent;color:#61768b}.pl-models i{color:#8295a7}.pl-unit-cell>span{min-width:0;padding:0;border:0;background:transparent;color:#168958;font-size:12px}.pl-more-models{margin-left:2px}

/* Modern native selects and floating tooltips */
.pl-tools select,.pl-table select{-webkit-appearance:none;appearance:none;padding-right:36px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1.5 6 6.5l5-5' fill='none' stroke='%235f7892' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;background-size:12px 8px;cursor:pointer}.pl-tools select{background-color:#fff}.pl-table select{background-color:#fbfcfd}.pl-tools select:disabled,.pl-table select:disabled{background-image:none;cursor:not-allowed}.pl-tooltip{position:fixed;z-index:10050;max-width:min(360px,calc(100vw - 20px));padding:9px 11px;border:1px solid rgba(255,255,255,.12);border-radius:9px;background:#1d3148;color:#fff;font-family:Inter,"Segoe UI",Arial,sans-serif;font-size:11px;font-weight:500;line-height:1.45;letter-spacing:.05px;box-shadow:0 10px 28px rgba(19,39,61,.25);opacity:0;visibility:hidden;transform:translateY(4px);pointer-events:none;transition:opacity .12s ease,transform .12s ease,visibility .12s ease}.pl-tooltip.show{opacity:1;visibility:visible;transform:translateY(0)}
.pl-col-actions{width:122px}.pl-row-actions{white-space:nowrap;text-align:center}.pl-row-actions button{display:inline-grid;place-items:center;width:30px;height:30px;margin:0 2px;padding:0;border:0;border-radius:7px;background:#edf5fd;color:#2473be;cursor:pointer}.pl-row-actions button[data-action="remove"]{background:#fff0f0;color:#cf4747}.pl-row-actions button:hover{filter:brightness(.96)}.pl-row-actions button:disabled{background:#f2f4f6;color:#aeb7c1;cursor:not-allowed;filter:none}.pl-table tr.pl-editing td{background:#eef7ff!important}
/* Keep this data-heavy page responsive: no decorative motion or remote UI assets. */
.pl-wrap *,.pl-modal *,.pl-floating-save,.pl-tooltip{animation:none!important;transition:none!important}.pl-head button:hover,.pl-floating-save.show,.pl-floating-save.show:hover,.pl-tooltip,.pl-tooltip.show{transform:none}.pl-tooltip{font-family:"Segoe UI",Tahoma,Arial,sans-serif}
.pl-row-actions button[data-action="save"]{background:#e8f3ff;color:#1672cf}.pl-row-actions button[data-action="edit"]{background:#fff5d9;color:#d28a00}.pl-table tr.pl-saved td{background:#eaf8f1!important}
@media(max-width:700px){.pl-head{align-items:stretch;padding:18px}.pl-head:after{display:none}.pl-head h3{font-size:19px}.pl-actions{margin-top:4px}.pl-tools{padding:13px}.pl-tools label,.pl-tools label:first-child{min-width:0}.pl-tools>span{align-self:flex-start}.pl-table-wrap{border-radius:11px}.pl-form{padding:15px}.pl-dialog-foot{padding:12px 15px}}
</style>
