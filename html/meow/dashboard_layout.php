<?php
$layoutTemplate = isset($displayTemplateActive) ? (string)$displayTemplateActive : 'energy_diagram';
$layoutCanEdit = strtolower((string)($_SESSION['user_type'] ?? '')) === 'superadmin';
?>
<style>
  .dl-toolbar{position:fixed;right:22px;top:82px;z-index:1040;display:flex;align-items:center;gap:7px;padding:7px;border:1px solid var(--bs-border-color,#d8e3ed);border-radius:12px;background:var(--bs-body-bg,#fff);box-shadow:0 8px 24px rgba(23,50,75,.14)}
  .dl-toolbar button{display:inline-flex;align-items:center;gap:6px;min-height:34px;padding:6px 10px;border:1px solid #cad8e5;border-radius:8px;background:#fff;color:#31506d;font-size:.7rem;font-weight:750;cursor:pointer}
  .dl-toolbar button.primary,.dl-toolbar button.active{border-color:#1769d2;background:#1769d2;color:#fff}.dl-toolbar button.danger{color:#bd3f48}.dl-toolbar button[hidden]{display:none}.dl-toolbar-status{max-width:150px;color:#6e8194;font-size:.65rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .dl-canvas.dl-editing{min-height:420px;border:1px dashed #6aa5df;border-radius:12px;background-image:linear-gradient(rgba(23,105,210,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(23,105,210,.05) 1px,transparent 1px);background-size:20px 20px}
  .dl-canvas.dl-free{position:relative!important;display:block!important;overflow:visible!important}
  .dl-canvas.dl-free>.dl-item{position:absolute!important;margin:0!important;max-width:none!important;min-width:40px;min-height:20px;overflow:visible}
  .dl-item.dl-selected{outline:2px solid #1769d2;outline-offset:2px}.dl-item-handle{display:none;position:absolute;left:8px;top:8px;z-index:100;width:29px;height:29px;align-items:center;justify-content:center;border:1px solid #8bb8e5;border-radius:8px;background:#1769d2;color:#fff;box-shadow:0 4px 12px rgba(10,50,95,.25);cursor:grab;touch-action:none}.dl-editing>.dl-item>.dl-item-handle{display:flex}.dl-editing>.dl-item{cursor:move;touch-action:none}.dl-editing>.dl-item *{user-select:none}.dl-editing>.dl-item input,.dl-editing>.dl-item button,.dl-editing>.dl-item a,.dl-editing>.dl-item select,.dl-editing>.dl-item textarea,.dl-editing>.dl-item iframe{pointer-events:none}
  .dl-resize-handle{display:none;position:absolute;z-index:101;width:11px;height:11px;border:1px solid #fff;border-radius:50%;background:#1769d2;box-shadow:0 1px 5px rgba(0,0,0,.35);touch-action:none}.dl-selected>.dl-resize-handle{display:block}.dl-resize-nw{left:-6px;top:-6px;cursor:nwse-resize}.dl-resize-n{left:50%;top:-6px;transform:translateX(-50%);cursor:ns-resize}.dl-resize-ne{right:-6px;top:-6px;cursor:nesw-resize}.dl-resize-e{right:-6px;top:50%;transform:translateY(-50%);cursor:ew-resize}.dl-resize-se{right:-6px;bottom:-6px;cursor:nwse-resize}.dl-resize-s{left:50%;bottom:-6px;transform:translateX(-50%);cursor:ns-resize}.dl-resize-sw{left:-6px;bottom:-6px;cursor:nesw-resize}.dl-resize-w{left:-6px;top:50%;transform:translateY(-50%);cursor:ew-resize}
  .dl-guide{display:none;position:absolute;z-index:9998;pointer-events:none;background:#ff3b8d;box-shadow:0 0 0 1px rgba(255,255,255,.7),0 0 8px rgba(255,59,141,.65)}.dl-guide.show{display:block}.dl-guide-x{top:-20000px;bottom:-20000px;width:1px}.dl-guide-y{left:-20000px;right:-20000px;height:1px}.dl-position-badge{display:none;position:absolute;z-index:9999;padding:5px 8px;border-radius:7px;background:#15283d;color:#fff;font:700 11px/1.35 system-ui,sans-serif;box-shadow:0 4px 12px rgba(0,0,0,.25);pointer-events:none;white-space:nowrap}.dl-position-badge.show{display:block}.dl-symmetry-note{color:#72dcff}
  .dl-toolbar button:disabled{opacity:.38;cursor:not-allowed}.dl-editing.dl-text-mode>.dl-item{cursor:default}.dl-editing.dl-text-mode>.dl-item *{pointer-events:auto;cursor:text}.dl-editing.dl-text-mode>.dl-item>.dl-item-handle{pointer-events:none;opacity:.35}.dl-text-target{outline:2px dashed #f59e0b!important;outline-offset:2px;background:rgba(245,158,11,.1)!important}
  .dl-custom-widget{display:flex!important;align-items:center;justify-content:center;padding:10px;text-align:center}.dl-custom-widget.dl-shape-circle{border-radius:50%!important}.dl-custom-widget.dl-shape-line{min-height:4px!important;height:4px;padding:0!important}.dl-custom-widget.dl-shape-transparent{background:transparent!important}.dl-widget-text{max-width:100%;overflow-wrap:anywhere}.dl-widget-value-label,.dl-widget-value-number,.dl-widget-value-meta{display:block}.dl-widget-value-label{font-size:.72em;opacity:.78}.dl-widget-value-number{font-size:1.55em;font-weight:800}.dl-widget-value-meta{font-size:.65em;opacity:.68}
  @media(max-width:700px){.dl-toolbar{right:10px;top:72px}.dl-toolbar-status{display:none}.dl-toolbar button{padding:6px 8px}.dl-toolbar button span{display:none}}
  html[data-bs-theme="dark"] .dl-toolbar{background:#15263a;border-color:#30465d}.dl-toolbar button{background:#1c3046;color:#dcecff;border-color:#3a536c}html:not([data-bs-theme="dark"]) .dl-toolbar button{background:#fff;color:#31506d}
</style>
<?php if ($layoutCanEdit): ?>
<div class="dl-toolbar" id="dlToolbar">
  <button type="button" id="dlUndo" hidden disabled><i class="fa-solid fa-clock-rotate-left"></i><span>ย้อนกลับ</span></button>
  <button type="button" id="dlText" hidden><i class="fa-solid fa-font"></i><span>แก้ข้อความ</span></button>
  <button type="button" id="dlFontDown" hidden title="ลดขนาดตัวอักษร"><i class="fa-solid fa-minus"></i><span>Font</span></button>
  <button type="button" id="dlFontUp" hidden title="เพิ่มขนาดตัวอักษร"><i class="fa-solid fa-plus"></i><span>Font</span></button>
  <button type="button" id="dlLayerDown" hidden title="ส่งไปด้านหลัง"><i class="fa-solid fa-layer-group"></i><span>ลง</span></button>
  <button type="button" id="dlLayerUp" hidden title="นำมาด้านหน้า"><i class="fa-solid fa-layer-group"></i><span>ขึ้น</span></button>
  <button type="button" id="dlSnap" hidden class="active" title="เปิดหรือปิด Smart Snap"><i class="fa-solid fa-magnet"></i><span>Snap</span></button>
  <button type="button" id="dlAdd" hidden title="เพิ่มรูปทรง ข้อความ หรือค่าจาก Device"><i class="fa-solid fa-plus"></i><span>เพิ่ม</span></button>
  <button type="button" id="dlWidgetEdit" hidden title="แก้ไข Widget ที่เลือก"><i class="fa-solid fa-pen"></i><span>Widget</span></button>
  <button type="button" id="dlWidgetDelete" hidden class="danger" title="ลบ Widget ที่เลือก"><i class="fa-solid fa-trash"></i><span>ลบ</span></button>
  <button type="button" id="dlClear" hidden class="danger" title="ลบทุกอย่างจนเป็นหน้าเปล่า"><i class="fa-solid fa-eraser"></i><span>ล้างหน้า</span></button>
  <button type="button" id="dlEdit"><i class="fa-solid fa-up-down-left-right"></i><span>จัดตำแหน่ง</span></button>
  <button type="button" class="primary" id="dlSave" hidden><i class="fa-solid fa-floppy-disk"></i><span>บันทึก</span></button>
  <button type="button" id="dlCancel" hidden><i class="fa-solid fa-xmark"></i><span>ยกเลิก</span></button>
  <button type="button" class="danger" id="dlReset"><i class="fa-solid fa-rotate-left"></i><span>คืนค่า</span></button>
  <span class="dl-toolbar-status" id="dlStatus"></span>
</div>
<?php endif; ?>
<script>
(() => {
  const template = <?php echo json_encode($layoutTemplate); ?>;
  const canEdit = <?php echo $layoutCanEdit ? 'true' : 'false'; ?>;
  const api = 'dashboard_layout_api.php?template=' + encodeURIComponent(template);
  let groups = [], items = [], savedLayout = null, editing = false, drag = null, resizing = null, textMode = false, snapEnabled = true, history = [], textOverrides = {}, originalTexts = {}, fontOverrides = {}, originalFonts = {}, widgetConfigs = {}, hiddenItems = new Set(), originalDisplays = {};
  const status = document.getElementById('dlStatus');
  const setStatus = text => { if (status) status.textContent = text; };
  const clone = value => JSON.parse(JSON.stringify(value));
  function updateUndoButton(){const button=document.getElementById('dlUndo');if(button)button.disabled=!history.length;}
  function pushHistory(){history.push(clone(currentLayout()));if(history.length>50)history.shift();updateUndoButton();}

  function addGroup(id, root, candidates) {
    const groupItems = [...new Set(candidates || [])].filter(item => item && item.parentElement === root && !item.classList.contains('dl-helper'));
    if (!root || !groupItems.length) return;
    let group = groups.find(entry => entry.root === root);
    if (!group) { group={id,root,items:[]};groups.push(group);root.classList.add('dl-canvas'); }
    const newItems=groupItems.filter(item=>!group.items.includes(item));
    newItems.forEach((item, index) => {
      group.items.push(item);
      item.classList.add('dl-item');
      const base = item.id || [...item.classList].find(name => !['dl-item','row','col-12'].includes(name)) || `item-${index + 1}`;
      item.dataset.layoutId = item.dataset.layoutFixedId || `${id}.${String(base).replace(/[^a-zA-Z0-9_.-]/g,'-')}.${group.items.length}`;
      item.dataset.layoutGroup = id;
      if(!(item.dataset.layoutId in originalDisplays))originalDisplays[item.dataset.layoutId]=item.style.display;
      item.addEventListener('pointerdown', beginDrag);
      if (!item.querySelector(':scope > .dl-item-handle')) {
        const handle = document.createElement('button');
        handle.type = 'button'; handle.className = 'dl-item-handle'; handle.title = 'ลากเพื่อย้าย'; handle.innerHTML = '<i class="fa-solid fa-grip"></i>';
        item.insertBefore(handle, item.firstChild);
        handle.addEventListener('pointerdown', beginDrag);
      }
      if (!item.querySelector(':scope > .dl-resize-handle')) ['nw','n','ne','e','se','s','sw','w'].forEach(side=>{const handle=document.createElement('i');handle.className=`dl-helper dl-resize-handle dl-resize-${side}`;handle.dataset.resize=side;handle.addEventListener('pointerdown',beginResize);item.appendChild(handle);});
    });
  }
  function discover() {
    if (template === 'energy_diagram') {
      const main=document.querySelector('.ef-wrap');
      if(main)addGroup('main',main,[...main.children].filter(el=>!el.matches('.ef-alert,.ef-settings,.ef-ac-modal')));
      const board=document.querySelector('.ef-board');if(board)addGroup('energy-board',board,[...board.children]);
      document.querySelectorAll('.ef-device-grid').forEach((grid,index)=>addGroup(`device-grid-${index+1}`,grid,[...grid.children]));
    } else if (template === 'chiller_diagram') {
      const main=document.querySelector('.cd-wrap');if(main)addGroup('main',main,[...main.children].filter(el=>!el.matches('.cd-config-modal')));
      const overview=document.querySelector('.cd-overview');if(overview)addGroup('overview',overview,[...overview.children]);
      const flow=document.querySelector('.cd-flow');if(flow)addGroup('flow',flow,[...flow.children]);
      document.querySelectorAll('.cd-list').forEach((list,index)=>addGroup(`device-list-${index+1}`,list,[...list.children]));
    } else if (template === 'template6') {
      const body=document.querySelector('.body');if(body)addGroup('main',body,[...body.children].filter(el=>!el.matches('script,style')));
      const row=document.querySelector('.body > .row');if(row)addGroup('dashboard-row',row,[...row.children].filter(el=>el.matches('[class*="col-"]')));
    } else {
      const containers=[...document.querySelectorAll('.device-show-container')],main=containers[0]?.parentElement;
      if(main)addGroup('main',main,[...main.children].filter(el=>!el.matches('script,style')));
      containers.forEach((container,index)=>addGroup(`devices-${index+1}`,container,[...container.children].filter(el=>el.classList.contains('device-show-rows'))));
    }
    items=groups.flatMap(group=>group.items);
    return groups.length > 0 && items.length > 0;
  }
  function snapshotDefault() {
    const result={groups:{}};
    groups.forEach(group=>{const rr=group.root.getBoundingClientRect(),records={};group.items.forEach(item=>{const r=item.getBoundingClientRect();records[item.dataset.layoutId]={x:(r.left-rr.left)/Math.max(rr.width,1)*100,y:r.top-rr.top,w:r.width/Math.max(rr.width,1)*100,h:r.height,z:parseInt(item.style.zIndex,10)||0};});result.groups[group.id]={canvasHeight:Math.max(group.root.scrollHeight,...group.items.map(item=>item.offsetTop+item.offsetHeight),80),items:records};});
    result.texts=clone(textOverrides);result.fonts=clone(fontOverrides);result.widgets=Object.values(widgetConfigs).map(clone);result.hidden=[...hiddenItems];return result;
  }
  function elementPath(item,element){const path=[];let node=element;while(node&&node!==item){const parent=node.parentElement;if(!parent)return null;path.unshift([...parent.children].indexOf(node));node=parent;}return node===item?path:null;}
  function resolveTextElement(key){const split=key.indexOf('::');if(split<0)return null;const item=items.find(entry=>entry.dataset.layoutId===key.slice(0,split));if(!item)return null;let node=item;const path=key.slice(split+2);if(path!=='')for(const index of path.split('.').map(Number)){node=node?.children[index];if(!node)return null;}return node;}
  function readDirectText(element){const direct=[...element.childNodes].filter(node=>node.nodeType===Node.TEXT_NODE).map(node=>node.nodeValue).join('').trim();return direct||(!element.children.length?element.textContent.trim():'');}
  function writeDirectText(element,value){const nodes=[...element.childNodes].filter(node=>node.nodeType===Node.TEXT_NODE);if(nodes.length){nodes[0].nodeValue=value;nodes.slice(1).forEach(node=>node.nodeValue='');}else if(!element.children.length)element.textContent=value;else element.insertBefore(document.createTextNode(value),element.firstChild);}
  function applyTexts(texts){Object.entries(originalTexts).forEach(([key,value])=>{const element=resolveTextElement(key);if(element)writeDirectText(element,value);});textOverrides=clone(texts||{});Object.entries(textOverrides).forEach(([key,value])=>{const element=resolveTextElement(key);if(!element)return;if(!(key in originalTexts))originalTexts[key]=readDirectText(element);writeDirectText(element,value);});}
  function applyFonts(fonts){Object.entries(originalFonts).forEach(([key,value])=>{const element=resolveTextElement(key);if(element)element.style.fontSize=value;});fontOverrides=clone(fonts||{});Object.entries(fontOverrides).forEach(([key,value])=>{const element=resolveTextElement(key);if(!element)return;if(!(key in originalFonts))originalFonts[key]=element.style.fontSize;element.style.fontSize=`${value}px`;});}
  function selectedItem(){return items.find(item=>item.classList.contains('dl-selected'))||null;}
  function adjustFont(delta){const item=selectedItem();if(!item){setStatus('เลือกกรอบก่อนปรับตัวอักษร');return;}pushHistory();const targets=[item,...item.querySelectorAll('*')].filter(element=>!element.classList.contains('dl-helper')&&readDirectText(element));targets.forEach(element=>{const path=elementPath(item,element);if(!path)return;const key=`${item.dataset.layoutId}::${path.join('.')}`;if(!(key in originalFonts))originalFonts[key]=element.style.fontSize;const size=Math.max(8,Math.min(72,parseFloat(getComputedStyle(element).fontSize)+delta));fontOverrides[key]=Math.round(size*10)/10;element.style.fontSize=`${fontOverrides[key]}px`;});setStatus(`ขนาดตัวอักษร ${delta>0?'เพิ่ม':'ลด'}แล้ว`);}
  function changeLayer(delta){const item=selectedItem();if(!item){setStatus('เลือกกรอบก่อนปรับ Layer');return;}pushHistory();const current=parseInt(item.style.zIndex||getComputedStyle(item).zIndex,10)||0;item.style.zIndex=String(Math.max(-100,Math.min(1000,current+delta)));setStatus(delta>0?'นำกรอบมาด้านหน้าแล้ว':'ส่งกรอบไปด้านหลังแล้ว');}
  function widgetPrompt(existing={}){const type=(prompt('ประเภท Widget: shape, text หรือ value',existing.type||'shape')||'').trim().toLowerCase();if(!['shape','text','value'].includes(type))return null;const config={shape:'rectangle',text:'Text',device:'',parameter:'',unit:'',background:'#1769d2',borderColor:'#1769d2',color:'#ffffff',borderWidth:2,...existing,type,id:existing.id||`widget-${Date.now().toString(36)}`};if(type==='shape'){config.shape=(prompt('รูปทรง: rectangle, circle, line หรือ transparent',existing.shape||'rectangle')||'rectangle').trim().toLowerCase();config.background=prompt('สีพื้นหลัง',existing.background||'#1769d2')||'#1769d2';config.borderColor=prompt('สีเส้นขอบ',existing.borderColor||'#1769d2')||'#1769d2';config.borderWidth=parseInt(prompt('ความหนาเส้นขอบ (px)',existing.borderWidth??2),10)||0;}else if(type==='text'){config.text=prompt('ข้อความที่ต้องการแสดง',existing.text||'ข้อความใหม่')??existing.text??'ข้อความใหม่';config.color=prompt('สีตัวอักษร',existing.color||'#ffffff')||'#ffffff';config.background=prompt('สีพื้นหลัง หรือ transparent',existing.background||'transparent')||'transparent';}else{config.text=prompt('ชื่อที่แสดง',existing.text||'Device Value')??'Device Value';config.device=prompt('Device S/N เช่น PM171008',existing.device||'')??'';config.parameter=prompt('Parameter เช่น p_t, v_an, e_p',existing.parameter||'p_t')??'';config.unit=prompt('หน่วย เช่น kW, V, kWh',existing.unit||'')??'';config.color=prompt('สีตัวอักษร',existing.color||'#ffffff')||'#ffffff';config.background=prompt('สีพื้นหลัง',existing.background||'#173a60')||'#173a60';}return config;}
  function renderWidget(item,config){const selected=item.classList.contains('dl-selected');item.className=`dl-custom-widget dl-item dl-shape-${config.shape||'rectangle'}${selected?' dl-selected':''}`;item.dataset.widgetId=config.id;item.dataset.layoutFixedId=`main.${config.id}`;item.style.background=config.background||'transparent';item.style.border=`${config.borderWidth??2}px solid ${config.borderColor||'transparent'}`;item.style.color=config.color||'#fff';let content=item.querySelector(':scope > .dl-widget-content');if(!content){content=document.createElement('div');content.className='dl-widget-content';item.insertBefore(content,item.querySelector('.dl-resize-handle'));}content.replaceChildren();if(config.type==='text'){const span=document.createElement('span');span.className='dl-widget-text';span.textContent=config.text||'Text';content.appendChild(span);}else if(config.type==='value'){const label=document.createElement('span'),value=document.createElement('strong'),meta=document.createElement('small');label.className='dl-widget-value-label';value.className='dl-widget-value-number';meta.className='dl-widget-value-meta';label.textContent=config.text||config.parameter||'Device Value';value.textContent='—';value.dataset.liveValue='1';meta.textContent=`${config.device||'—'} · ${config.parameter||'—'}${config.unit?' · '+config.unit:''}`;content.append(label,value,meta);}}
  function createWidget(config){const group=groups[0];if(!group)return null;let item=document.querySelector(`.dl-custom-widget[data-widget-id="${CSS.escape(config.id)}"]`);if(!item){item=document.createElement('div');item.id=`dl-${config.id}`;group.root.appendChild(item);item.dataset.layoutFixedId=`main.${config.id}`;addGroup(group.id,group.root,[item]);items=groups.flatMap(entry=>entry.items);}widgetConfigs[config.id]=clone(config);renderWidget(item,config);return item;}
  function applyWidgets(widgets){const incoming={};(widgets||[]).forEach(config=>{if(config?.id){incoming[config.id]=config;createWidget(config);}});Object.keys(widgetConfigs).forEach(id=>{if(incoming[id])return;const item=document.querySelector(`.dl-custom-widget[data-widget-id="${CSS.escape(id)}"]`);if(item){groups.forEach(group=>group.items=group.items.filter(entry=>entry!==item));item.remove();}delete widgetConfigs[id];});widgetConfigs=clone(incoming);items=groups.flatMap(group=>group.items);}
  function applyHidden(hidden){items.forEach(item=>item.style.display=originalDisplays[item.dataset.layoutId]??'');hiddenItems=new Set(hidden||[]);items.forEach(item=>{if(hiddenItems.has(item.dataset.layoutId))item.style.display='none';});}
  function addWidget(){const config=widgetPrompt();if(!config)return;pushHistory();const item=createWidget(config);if(!item)return;item.style.left='10%';item.style.top='80px';item.style.width=config.shape==='line'?'30%':'220px';item.style.height=config.shape==='line'?'4px':'120px';items.forEach(entry=>entry.classList.toggle('dl-selected',entry===item));setStatus('เพิ่ม Widget แล้ว — ลากและปรับขนาดได้');}
  function editWidget(){const item=selectedItem(),id=item?.dataset.widgetId;if(!id||!widgetConfigs[id]){setStatus('เลือก Custom Widget ที่ต้องการแก้ไข');return;}const config=widgetPrompt(widgetConfigs[id]);if(!config)return;pushHistory();widgetConfigs[id]=config;renderWidget(item,config);setStatus('แก้ไข Widget แล้ว');}
  async function deleteWidget(){const item=selectedItem();if(!item){setStatus('เลือกกรอบหรือ Widget ที่ต้องการลบ');return;}if(!confirm('ลบรายการที่เลือกออกจาก Template นี้?'))return;pushHistory();const id=item.dataset.widgetId;if(id&&widgetConfigs[id]){groups.forEach(group=>group.items=group.items.filter(entry=>entry!==item));delete widgetConfigs[id];item.remove();items=groups.flatMap(group=>group.items);}else{hiddenItems.add(item.dataset.layoutId);item.classList.remove('dl-selected');item.style.display='none';}try{const data=await request({action:'save',layout:currentLayout()});savedLayout=data.layout;setStatus('ลบและบันทึกแล้ว');}catch(error){setStatus(`ลบแล้ว แต่บันทึกไม่สำเร็จ: ${error.message}`);}}
  async function clearPage(){if(!confirm('ลบทุกอย่างในหน้า Template นี้จนเป็นหน้าเปล่า?'))return;pushHistory();document.querySelectorAll('.dl-custom-widget[data-widget-id]').forEach(item=>{const id=item.dataset.widgetId;groups.forEach(group=>group.items=group.items.filter(entry=>entry!==item));delete widgetConfigs[id];item.remove();});items=groups.flatMap(group=>group.items);const main=groups.find(group=>group.id==='main')||groups[0];(main?.items||items).forEach(item=>{if(!item.classList.contains('dl-custom-widget')){hiddenItems.add(item.dataset.layoutId);item.classList.remove('dl-selected');item.style.display='none';}});try{const data=await request({action:'save',layout:currentLayout()});savedLayout=data.layout;setStatus('ล้างหน้าและบันทึกแล้ว');}catch(error){setStatus(`ล้างหน้าแล้ว แต่บันทึกไม่สำเร็จ: ${error.message}`);}}
  async function refreshWidgetValues(){if(!Object.values(widgetConfigs).some(config=>config.type==='value'))return;try{const response=await fetch(`api_mdbiotx.php?_=${Date.now()}`,{cache:'no-store'}),payload=await response.json(),data=payload.data||{};document.querySelectorAll('.dl-custom-widget[data-widget-id]').forEach(item=>{const config=widgetConfigs[item.dataset.widgetId];if(!config||config.type!=='value')return;const raw=data?.[config.device]?.[config.parameter],target=item.querySelector('[data-live-value]');if(target)target.textContent=raw===undefined||raw===null?'—':`${raw}${config.unit?' '+config.unit:''}`;});}catch(_){}}
  function apply(layout) {
    if (!layout?.groups) return;
    applyWidgets(layout.widgets||[]);
    groups.forEach(group=>{const saved=layout.groups[group.id];if(!saved)return;group.root.classList.add('dl-free');group.root.style.height=`${saved.canvasHeight||400}px`;group.items.forEach(item=>{const p=saved.items?.[item.dataset.layoutId];if(!p)return;item.style.left=`${p.x}%`;item.style.top=`${p.y}px`;item.style.width=`${p.w}%`;item.style.height=`${p.h}px`;item.style.zIndex=String(p.z||0);});});applyTexts(layout.texts||{});applyFonts(layout.fonts||{});applyHidden(layout.hidden||[]);
  }
  function clearLayout() {
    groups.forEach(group=>{group.root.classList.remove('dl-free','dl-editing');group.root.style.height='';});
    items.forEach(item => { item.style.left='';item.style.top='';item.style.width='';item.style.height='';item.classList.remove('dl-selected'); });
    applyTexts({});applyFonts({});applyHidden([]);applyWidgets([]);
  }
  function currentLayout() {
    const result={groups:{}};groups.forEach(group=>{const rr=group.root.getBoundingClientRect(),records={};let bottom=80;group.items.forEach(item=>{const r=item.getBoundingClientRect(),y=r.top-rr.top;records[item.dataset.layoutId]={x:(r.left-rr.left)/Math.max(rr.width,1)*100,y,w:r.width/Math.max(rr.width,1)*100,h:r.height,z:parseInt(item.style.zIndex,10)||0};bottom=Math.max(bottom,y+r.height+20)});result.groups[group.id]={canvasHeight:Math.ceil(bottom),items:records};});result.texts=clone(textOverrides);result.fonts=clone(fontOverrides);result.widgets=Object.values(widgetConfigs).map(clone);result.hidden=[...hiddenItems];return result;
  }
  function enterEdit() {
    if(editing)return; editing=true;
    const defaults=snapshotDefault();groups.forEach(group=>{if(!group.root.classList.contains('dl-free')){const one={groups:{[group.id]:defaults.groups[group.id]},texts:textOverrides,fonts:fontOverrides,widgets:Object.values(widgetConfigs),hidden:[...hiddenItems]};apply(one);}group.root.classList.add('dl-editing');});
    history=[];updateUndoButton();document.getElementById('dlEdit').hidden=true;['dlSave','dlUndo','dlText','dlFontDown','dlFontUp','dlLayerDown','dlLayerUp','dlSnap','dlAdd','dlWidgetEdit','dlWidgetDelete','dlClear','dlCancel'].forEach(id=>document.getElementById(id).hidden=false);setStatus('เลือกกรอบเพื่อใช้เครื่องมือ Dashboard');
  }
  function setTextMode(enabled){textMode=enabled;groups.forEach(group=>group.root.classList.toggle('dl-text-mode',enabled));document.getElementById('dlText')?.classList.toggle('active',enabled);setStatus(enabled?'คลิกข้อความที่ต้องการแก้ไข':'ลากหรือปรับขนาดได้');}
  function leaveEdit(){editing=false;setTextMode(false);groups.forEach(group=>group.root.classList.remove('dl-editing'));items.forEach(i=>i.classList.remove('dl-selected'));document.getElementById('dlEdit').hidden=false;['dlSave','dlUndo','dlText','dlFontDown','dlFontUp','dlLayerDown','dlLayerUp','dlSnap','dlAdd','dlWidgetEdit','dlWidgetDelete','dlClear','dlCancel'].forEach(id=>document.getElementById(id).hidden=true);history=[];}
  function beginDrag(event) {
    if(!editing||textMode)return;
    const item=event.currentTarget.classList.contains('dl-item-handle')?event.currentTarget.parentElement:event.currentTarget;
    const ir=item.getBoundingClientRect();
    if(event.currentTarget===item&&event.clientX>ir.right-22&&event.clientY>ir.bottom-22){pushHistory();return;}
    event.preventDefault();event.stopPropagation();
    const group=groups.find(entry=>entry.id===item.dataset.layoutGroup),root=group.root,rr=root.getBoundingClientRect();
    items.forEach(i=>i.classList.toggle('dl-selected',i===item));
    let guideX=root.querySelector(':scope > .dl-guide-x'),guideY=root.querySelector(':scope > .dl-guide-y'),badge=root.querySelector(':scope > .dl-position-badge');
    if(!guideX){guideX=document.createElement('i');guideX.className='dl-helper dl-guide dl-guide-x';root.appendChild(guideX);guideY=document.createElement('i');guideY.className='dl-helper dl-guide dl-guide-y';root.appendChild(guideY);badge=document.createElement('span');badge.className='dl-helper dl-position-badge';root.appendChild(badge);}
    pushHistory();drag={item,root,group,guideX,guideY,badge,pointerId:event.pointerId,startX:event.clientX,startY:event.clientY,left:ir.left-rr.left,top:ir.top-rr.top,rootWidth:rr.width,rootHeight:rr.height};
    event.currentTarget.setPointerCapture(event.pointerId);
  }
  function beginResize(event){if(!editing||textMode)return;event.preventDefault();event.stopPropagation();const handle=event.currentTarget,item=handle.parentElement,group=groups.find(entry=>entry.id===item.dataset.layoutGroup),root=group.root,rr=root.getBoundingClientRect(),ir=item.getBoundingClientRect();items.forEach(entry=>entry.classList.toggle('dl-selected',entry===item));pushHistory();resizing={item,root,side:handle.dataset.resize,pointerId:event.pointerId,startX:event.clientX,startY:event.clientY,left:ir.left-rr.left,top:ir.top-rr.top,width:ir.width,height:ir.height,rootWidth:rr.width};handle.setPointerCapture(event.pointerId);}
  function moveDrag(event) {
    if(resizing&&event.pointerId===resizing.pointerId){const dx=event.clientX-resizing.startX,dy=event.clientY-resizing.startY,side=resizing.side;let left=resizing.left,top=resizing.top,width=resizing.width,height=resizing.height;if(side.includes('e'))width=Math.max(40,resizing.width+dx);if(side.includes('s'))height=Math.max(20,resizing.height+dy);if(side.includes('w')){width=Math.max(40,resizing.width-dx);left=resizing.left+(resizing.width-width);}if(side.includes('n')){height=Math.max(20,resizing.height-dy);top=resizing.top+(resizing.height-height);}resizing.item.style.left=`${left/Math.max(resizing.rootWidth,1)*100}%`;resizing.item.style.top=`${top}px`;resizing.item.style.width=`${width/Math.max(resizing.rootWidth,1)*100}%`;resizing.item.style.height=`${height}px`;resizing.root.style.height=`${Math.max(resizing.root.offsetHeight,top+height+20)}px`;setStatus(`ขนาด ${Math.round(width)} × ${Math.round(height)} px`);return;}
    if(!drag||event.pointerId!==drag.pointerId)return;
    let left=drag.left+event.clientX-drag.startX,top=drag.top+event.clientY-drag.startY;
    const width=drag.item.offsetWidth,height=drag.item.offsetHeight,tolerance=snapEnabled?7:-1;
    const rootRect=drag.root.getBoundingClientRect();
    const xTargets=[],yTargets=[];
    drag.group.items.filter(item=>item!==drag.item).forEach(item=>{const r=item.getBoundingClientRect(),x=r.left-rootRect.left,y=r.top-rootRect.top;xTargets.push({v:x,type:'edge'},{v:x+r.width/2,type:'center'},{v:x+r.width,type:'edge'});yTargets.push({v:y,type:'edge'},{v:y+r.height/2,type:'center'},{v:y+r.height,type:'edge'});});
    const centerDx=drag.rootWidth/2-(left+width/2),centerDy=drag.rootHeight/2-(top+height/2);
    let snapX=Math.abs(centerDx)<=tolerance?{d:centerDx,v:drag.rootWidth/2,type:'canvas-center'}:null;
    let snapY=Math.abs(centerDy)<=tolerance?{d:centerDy,v:drag.rootHeight/2,type:'canvas-center'}:null;
    for(const target of xTargets){for(const anchor of [left,left+width/2,left+width]){const d=target.v-anchor;if(Math.abs(d)<=tolerance&&(!snapX||Math.abs(d)<Math.abs(snapX.d)))snapX={d,v:target.v,type:target.type};}}
    for(const target of yTargets){for(const anchor of [top,top+height/2,top+height]){const d=target.v-anchor;if(Math.abs(d)<=tolerance&&(!snapY||Math.abs(d)<Math.abs(snapY.d)))snapY={d,v:target.v,type:target.type};}}
    if(snapX)left+=snapX.d;if(snapY)top+=snapY.d;
    drag.item.style.left=`${left/Math.max(drag.rootWidth,1)*100}%`;drag.item.style.top=`${top}px`;
    drag.guideX.classList.toggle('show',!!snapX);drag.guideY.classList.toggle('show',!!snapY);
    if(snapX)drag.guideX.style.left=`${snapX.v}px`;if(snapY)drag.guideY.style.top=`${snapY.v}px`;
    const notes=[];if(snapX?.type==='canvas-center')notes.push('กึ่งกลางแนวตั้ง');if(snapY?.type==='canvas-center')notes.push('กึ่งกลางแนวนอน');if(snapX&&!notes.includes('กึ่งกลางแนวตั้ง')&&snapX.type!=='canvas-center')notes.push('ตรงแนว X');if(snapY&&!notes.includes('กึ่งกลางแนวนอน')&&snapY.type!=='canvas-center')notes.push('ตรงแนว Y');
    drag.badge.innerHTML=`X ${Math.round(left)} &nbsp; Y ${Math.round(top)}${notes.length?`<br><span class="dl-symmetry-note">${notes.join(' · ')}</span>`:''}`;drag.badge.style.left=`${left+12}px`;drag.badge.style.top=`${top-38}px`;drag.badge.classList.add('show');
    drag.root.style.height=`${Math.max(drag.root.offsetHeight,drag.item.offsetTop+drag.item.offsetHeight+20)}px`;
  }
  function endDrag(event){if(resizing&&event.pointerId===resizing.pointerId)resizing=null;if(drag&&event.pointerId===drag.pointerId){drag.guideX.classList.remove('show');drag.guideY.classList.remove('show');drag.badge.classList.remove('show');drag=null;}}
  function editText(event){
    if(!editing||!textMode)return;
    const item=event.target.closest('.dl-item');if(!item)return;
    const element=event.target;if(element.classList.contains('dl-item-handle')||element.matches('input,select,textarea,iframe,script,style'))return;
    const path=elementPath(item,element);if(!path)return;
    const current=readDirectText(element);if(!current&&element.children.length)return;
    event.preventDefault();event.stopPropagation();element.classList.add('dl-text-target');
    const next=prompt('แก้ไขข้อความ',current);element.classList.remove('dl-text-target');
    if(next===null||next===current)return;
    pushHistory();const key=`${item.dataset.layoutId}::${path.join('.')}`;if(!(key in originalTexts))originalTexts[key]=current;textOverrides[key]=next.trim();writeDirectText(element,textOverrides[key]);setStatus('แก้ข้อความแล้ว — กดบันทึกเพื่อยืนยัน');
  }
  async function request(body) {const response=await fetch(api,{method:body?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:body?{'Content-Type':'application/json'}:{},body:body?JSON.stringify(body):undefined});const data=await response.json();if(!response.ok||data.status!=='ok')throw new Error(data.message||'Request failed');return data;}
  async function init() {
    if(!discover())return;
    try{const data=await request();savedLayout=data.layout;if(savedLayout)apply(savedLayout);}catch(error){setStatus(error.message);}
    refreshWidgetValues();setInterval(refreshWidgetValues,2000);
    if(!canEdit)return;
    document.getElementById('dlEdit').addEventListener('click',enterEdit);
    document.getElementById('dlUndo').addEventListener('click',async()=>{const previous=history.pop();if(!previous)return;clearLayout();apply(previous);groups.forEach(group=>group.root.classList.add('dl-editing'));if(textMode)groups.forEach(group=>group.root.classList.add('dl-text-mode'));updateUndoButton();setStatus('ย้อนกลับ 1 ขั้นแล้ว');try{const data=await request({action:'save',layout:currentLayout()});savedLayout=data.layout;}catch(error){setStatus(`ย้อนกลับแล้ว แต่บันทึกไม่สำเร็จ: ${error.message}`);}});
    document.getElementById('dlText').addEventListener('click',()=>setTextMode(!textMode));
    document.getElementById('dlFontDown').addEventListener('click',()=>adjustFont(-1));
    document.getElementById('dlFontUp').addEventListener('click',()=>adjustFont(1));
    document.getElementById('dlLayerDown').addEventListener('click',()=>changeLayer(-1));
    document.getElementById('dlLayerUp').addEventListener('click',()=>changeLayer(1));
    document.getElementById('dlSnap').addEventListener('click',event=>{snapEnabled=!snapEnabled;event.currentTarget.classList.toggle('active',snapEnabled);setStatus(snapEnabled?'เปิด Smart Snap แล้ว':'ปิด Smart Snap แล้ว');});
    document.getElementById('dlAdd').addEventListener('click',addWidget);
    document.getElementById('dlWidgetEdit').addEventListener('click',editWidget);
    document.getElementById('dlWidgetDelete').addEventListener('click',deleteWidget);
    document.getElementById('dlClear').addEventListener('click',clearPage);
    document.getElementById('dlCancel').addEventListener('click',()=>{clearLayout();if(savedLayout)apply(savedLayout);leaveEdit();setStatus('ยกเลิกแล้ว')});
    document.getElementById('dlSave').addEventListener('click',async()=>{try{setStatus('กำลังบันทึก...');const layout=currentLayout();const data=await request({action:'save',layout});savedLayout=data.layout;apply(savedLayout);leaveEdit();setStatus('บันทึกแล้ว');}catch(error){setStatus(error.message);}});
    document.getElementById('dlReset').addEventListener('click',async()=>{if(!confirm('คืนค่าการจัดวางเริ่มต้นของ Template นี้?'))return;try{await request({action:'reset'});savedLayout=null;clearLayout();leaveEdit();setStatus('คืนค่าเริ่มต้นแล้ว');}catch(error){setStatus(error.message);}});
    document.addEventListener('pointermove',moveDrag);document.addEventListener('pointerup',endDrag);document.addEventListener('pointercancel',endDrag);
    document.addEventListener('click',editText,true);
    groups.forEach(group=>group.root.addEventListener('mouseup',()=>{if(editing)group.root.style.height=`${currentLayout().groups[group.id].canvasHeight}px`;}));
    let refreshTimer=0;
    new MutationObserver(()=>{clearTimeout(refreshTimer);refreshTimer=setTimeout(()=>{const oldCount=items.length;discover();if(items.length>oldCount){if(savedLayout)apply(savedLayout);if(editing)groups.forEach(group=>group.root.classList.add('dl-editing'));}},120);}).observe(document.body,{childList:true,subtree:true});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
</script>
