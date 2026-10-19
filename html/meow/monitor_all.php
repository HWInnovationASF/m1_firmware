<?php
// ============================================================================
// monitor_all.php — "All Readings" overview page.
// Shows every value read from every device, grouped into sections by the
// device's model type (config/detail_rgl.json). Included by index.php
// (page=alldata), so it inherits $dvl_txt / $rgl_txt / $param_txt / $detail_rgl_txt.
// Live data comes from api_mdbiotx.php (same source as the dashboard).
// Usability: search box, type filter chips, online-only toggle, and a large
// "headline" value per card so the important reading is visible at a glance.
// ============================================================================
?>
<div class="allrd-wrap">
  <div class="allrd-head">
    <div>
      <h3 class="allrd-title"><span><i class="fa-solid fa-table-cells-large"></i></span> All Readings</h3>
      <div class="allrd-sub">ค่าที่อ่านได้ทั้งหมด แบ่งตามชนิดและอุปกรณ์</div>
    </div>
    <div class="allrd-updated"><i class="fa-solid fa-arrows-rotate"></i><span id="allrdUpdated">กำลังโหลด…</span></div>
  </div>

  <!-- summary cards -->
  <div class="allrd-summary" id="allrdSummary"></div>

  <!-- toolbar: search + type filter + online toggle -->
  <div class="allrd-toolbar">
    <div class="allrd-searchbox">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" id="allrdSearch" placeholder="ค้นหาอุปกรณ์ (ชื่อ / SN / รุ่น)…" autocomplete="off">
      <button type="button" id="allrdClear" title="ล้าง" style="display:none;"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="allrd-chips" id="allrdChips"></div>
    <label class="allrd-toggle"><input type="checkbox" id="allrdOnline"> เฉพาะออนไลน์</label>
  </div>

  <!-- grouped device sections -->
  <div id="allrdSections"></div>
  <div id="allrdEmpty" class="allrd-empty" style="display:none;">
    <i class="ti ti-database-off"></i>
    <div id="allrdEmptyMsg">ยังไม่มีข้อมูลจากอุปกรณ์</div>
  </div>
</div>
<div class="allrd-modal" id="allrdTotalModal" hidden>
  <div class="allrd-modal-card" role="dialog" aria-modal="true" aria-labelledby="allrdTotalTitle">
    <div class="allrd-modal-head"><div><i class="fa-solid fa-calculator"></i><span><b id="allrdTotalTitle">ตั้งค่าช่องสรุปรวม</b><small>เลือก Parameter ที่ต้องการนำค่าของทุกอุปกรณ์มารวมกัน</small></span></div><button type="button" id="allrdTotalClose" aria-label="ปิด">&times;</button></div>
    <div class="allrd-modal-body"><label><span>Parameter</span><select id="allrdTotalParameter"></select></label><label><span>Calculation</span><select id="allrdTotalCalculation"><option value="sum">Sum · ผลรวม</option><option value="avg">AVG · ค่าเฉลี่ย</option></select></label><label><span>Display name <small>(ไม่บังคับ)</small></span><input id="allrdTotalLabel" type="text" maxlength="80" placeholder="ใช้ชื่อเริ่มต้นจาก Parameter"></label><div class="allrd-display-preview"><small>ชื่อที่จะแสดง</small><strong id="allrdTotalPreview">—</strong></div></div>
    <div class="allrd-modal-foot"><button type="button" id="allrdTotalCancel">ยกเลิก</button><button type="button" id="allrdTotalSave"><i class="fa-solid fa-floppy-disk"></i> บันทึก</button></div>
  </div>
</div>

<style>
  .allrd-wrap{ padding:16px 20px 40px; }
  .allrd-head{ display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
  .allrd-title{ margin:0; font-weight:700; }
  .allrd-sub{ color:#6b7a90; font-size:.9rem; margin-top:2px; }
  .allrd-updated{ color:#6b7a90; font-size:.85rem; white-space:nowrap; }

  .allrd-summary{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:18px; }
  .allrd-stat{ background:#fff; border:1px solid #e6ecf3; border-radius:12px; padding:14px 16px; }
  .allrd-stat .lbl{ color:#6b7a90; font-size:.8rem; }
  .allrd-stat .val{ font-size:1.6rem; font-weight:700; line-height:1.2; margin-top:4px; }
  .allrd-stat .val small{ font-size:.85rem; font-weight:600; color:#6b7a90; }

  .allrd-toolbar{ display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:22px; }
  .allrd-searchbox{ display:flex; align-items:center; gap:8px; background:#fff; border:1px solid #d8e0ea; border-radius:10px; padding:8px 12px; min-width:240px; flex:1 1 240px; max-width:360px; }
  .allrd-searchbox i{ color:#8a97a8; }
  .allrd-searchbox input{ border:0; outline:0; flex:1; font-size:.92rem; background:transparent; }
  .allrd-searchbox button{ border:0; background:transparent; color:#8a97a8; cursor:pointer; padding:0; }
  .allrd-chips{ display:flex; gap:6px; flex-wrap:wrap; }
  .allrd-chip{ border:1px solid #d8e0ea; background:#fff; color:#33506e; font-size:.82rem; font-weight:600; padding:6px 13px; border-radius:20px; cursor:pointer; text-transform:capitalize; }
  .allrd-chip:hover{ border-color:#0a4c9e; }
  .allrd-chip.active{ background:#0a4c9e; border-color:#0a4c9e; color:#fff; }
  .allrd-chip .n{ opacity:.7; margin-left:4px; font-weight:700; }
  .allrd-toggle{ display:flex; align-items:center; gap:6px; font-size:.85rem; color:#33506e; cursor:pointer; white-space:nowrap; margin-left:auto; }

  .allrd-group{ margin-bottom:26px; }
  .allrd-group-head{ display:flex; align-items:center; gap:10px; margin-bottom:12px; }
  .allrd-group-head .gtitle{ font-weight:700; font-size:1.05rem; text-transform:capitalize; }
  .allrd-group-head .gcount{ background:#eef2f8; color:#33506e; font-size:.75rem; font-weight:700; padding:2px 9px; border-radius:20px; }
  .allrd-group-line{ flex:1; height:1px; background:#e6ecf3; }

  .allrd-cards{ display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
  .allrd-card{ background:#fff; border:1px solid #e6ecf3; border-radius:12px; padding:14px 16px; border-top:4px solid #0a4c9e; }
  .allrd-card.offline{ border-top-color:#c2ccd8; opacity:.72; }
  .allrd-card-head{ display:flex; align-items:flex-start; justify-content:space-between; gap:8px; margin-bottom:10px; }
  .allrd-dev-name{ font-weight:700; font-size:1rem; }
  .allrd-dev-sn{ color:#6b7a90; font-size:.78rem; margin-top:1px; }
  .allrd-badge{ font-size:.68rem; font-weight:700; padding:2px 8px; border-radius:6px; background:#e3f4ec; color:#1f7a52; white-space:nowrap; }
  .allrd-badge.off{ background:#f3e6e6; color:#a33; }

  .allrd-headline{ background:#f5f8fc; border-radius:10px; padding:10px 12px; margin-bottom:10px; }
  .allrd-headline .hl-lbl{ color:#6b7a90; font-size:.78rem; }
  .allrd-headline .hl-val{ font-size:1.7rem; font-weight:700; line-height:1.15; font-variant-numeric:tabular-nums; }
  .allrd-headline .hl-val small{ font-size:.9rem; font-weight:600; color:#6b7a90; margin-left:4px; }

  .allrd-more{ border:0; background:transparent; color:#0a4c9e; font-size:.82rem; font-weight:600; cursor:pointer; padding:4px 0 0; display:inline-flex; align-items:center; gap:4px; }
  .allrd-rows{ display:flex; flex-direction:column; gap:6px; margin-top:8px; }
  .allrd-rows.collapsed{ display:none; }
  .allrd-row{ display:flex; justify-content:space-between; align-items:baseline; gap:10px; font-size:.9rem; border-bottom:1px dashed #eef2f6; padding-bottom:5px; }
  .allrd-row:last-child{ border-bottom:0; padding-bottom:0; }
  .allrd-row .k{ color:#556; }
  .allrd-row .v{ font-weight:700; font-variant-numeric:tabular-nums; }
  .allrd-row .v small{ font-weight:600; color:#6b7a90; margin-left:3px; }
  .allrd-dev-time{ color:#8a97a8; font-size:.75rem; margin-top:10px; text-align:right; }

  @media (max-width:600px){ .allrd-cards{ grid-template-columns:1fr; } .allrd-toggle{ margin-left:0; } }
  /* Refined monitoring workspace */
  .allrd-wrap{max-width:1500px;margin:auto;padding:8px 4px 40px;color:#26394e}
  .allrd-head{align-items:center;margin-bottom:14px;padding:17px 19px;border:1px solid #e0e9f2;border-radius:16px;background:linear-gradient(120deg,#fff,#f6faff);box-shadow:0 8px 24px rgba(31,55,89,.055)}
  .allrd-title{display:flex;align-items:center;gap:10px;color:#263f59;font-size:1.18rem}.allrd-title>span{display:grid;place-items:center;width:36px;height:36px;border-radius:10px;background:#e8f2ff;color:#1769d2;font-size:.9rem}.allrd-sub{margin:4px 0 0 46px;color:#718398;font-size:.76rem}
  .allrd-updated{display:flex;align-items:center;gap:7px;padding:7px 10px;border:1px solid #dce7f1;border-radius:9px;background:#fff;color:#647b91;font-size:.72rem}.allrd-updated i{color:#25a56a}
  .allrd-summary{grid-template-columns:minmax(260px,1.15fr) minmax(220px,1fr);gap:10px;margin-bottom:12px}.allrd-stat{position:relative;overflow:hidden;padding:13px 15px;border-color:#e0e8f1;border-radius:12px;box-shadow:0 5px 16px rgba(31,55,89,.035)}.allrd-stat:before{position:absolute;top:0;bottom:0;left:0;width:3px;background:#1769d2;content:""}.allrd-stat:nth-child(2):before{background:#e6a321}.allrd-stat .lbl{font-size:.68rem;font-weight:700}.allrd-stat .val{margin-top:3px;color:#294760;font-size:1.35rem}
  .allrd-device-stat{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;column-gap:18px}.allrd-device-stat>.lbl{grid-column:1/-1}.allrd-device-total{display:flex;align-items:baseline;gap:5px;margin-top:3px;min-width:0}.allrd-device-total strong{color:#168958;font-size:1.45rem;line-height:1}.allrd-device-total span{color:#526b82;font-size:.78rem;font-weight:700}.allrd-device-total em{margin-left:3px;padding:2px 6px;border-radius:8px;background:#e6f7ef;color:#168958;font-size:.55rem;font-style:normal;font-weight:800}.allrd-device-down{display:flex;align-items:center;gap:5px;padding:6px 9px;border-radius:9px;background:#fff1f1;color:#b54848}.allrd-device-down i{font-size:.68rem}.allrd-device-down span{font-size:.62rem;font-weight:700}.allrd-device-down strong{font-size:.9rem}.allrd-device-down small{font-size:.56rem}
  .allrd-toolbar{position:sticky;top:0;z-index:12;gap:10px;margin-bottom:18px;padding:10px;border:1px solid #dde7f0;border-radius:13px;background:rgba(248,251,254,.96);box-shadow:0 7px 20px rgba(31,55,89,.07);backdrop-filter:blur(8px)}
  .allrd-searchbox{min-height:40px;max-width:420px;padding:7px 11px;border-color:#cfdce8;border-radius:9px}.allrd-searchbox:focus-within{border-color:#1769d2;box-shadow:0 0 0 3px rgba(23,105,210,.09)}.allrd-searchbox input{min-width:0;font-size:.8rem}.allrd-searchbox i{color:#1769d2}.allrd-searchbox button{display:grid;place-items:center;width:25px;height:25px;border-radius:6px}.allrd-searchbox button:hover{background:#edf3f9;color:#315f89}
  .allrd-chip{padding:6px 11px;border-color:#d6e1eb;border-radius:8px;font-size:.7rem}.allrd-chip:hover{border-color:#8eb4d9;background:#f2f7fc}.allrd-chip.active{border-color:#1769d2;background:#1769d2}.allrd-toggle{gap:8px;margin-left:auto;padding:5px 8px;color:#526b82;font-size:.72rem;font-weight:700}.allrd-toggle input{appearance:none;position:relative;width:38px;height:21px;border-radius:20px;background:#bdc8d2}.allrd-toggle input:after{position:absolute;top:3px;left:3px;width:15px;height:15px;border-radius:50%;background:#fff;box-shadow:0 1px 4px #789;content:""}.allrd-toggle input:checked{background:#25a56a}.allrd-toggle input:checked:after{left:20px}
  .allrd-group{margin-bottom:22px}.allrd-group-head{margin-bottom:10px}.allrd-group-head .gtitle{color:#31536f;font-size:.88rem}.allrd-group-head .gcount{background:#eaf2fa;color:#3974aa;font-size:.65rem}.allrd-cards{grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:11px}
  .allrd-dashboard-link{display:inline-flex;align-items:center;gap:7px;flex:none;padding:7px 10px;border:1px solid #dfbd68;border-radius:8px;background:#fff8e8;color:#976500;font-size:.68rem;font-weight:800;text-decoration:none}.allrd-dashboard-link:hover{border-color:#dca626;background:#fff1cf;color:#795100}.allrd-dashboard-link i:last-child{font-size:.56rem}
  .allrd-card{display:flex;flex-direction:column;min-width:0;padding:13px 14px;border-color:#dfe8f1;border-top-width:3px;border-radius:12px;box-shadow:0 6px 18px rgba(31,55,89,.045)}.allrd-card:hover{border-color:#bfd3e7;box-shadow:0 10px 24px rgba(31,55,89,.08)}.allrd-card.offline{background:#fafbfc;opacity:.75}.allrd-card-head{align-items:center;margin-bottom:9px;padding-bottom:9px;border-bottom:1px solid #edf1f5}.allrd-dev-name{overflow:hidden;color:#294760;font-size:.86rem;text-overflow:ellipsis;white-space:nowrap}.allrd-dev-sn{font-size:.66rem}.allrd-badge{position:relative;padding:3px 8px 3px 16px;border-radius:12px;font-size:.58rem;text-transform:uppercase}.allrd-badge:before{position:absolute;top:50%;left:7px;width:5px;height:5px;border-radius:50%;background:currentColor;content:"";transform:translateY(-50%)}
  .allrd-headline{display:flex;align-items:end;justify-content:space-between;gap:10px;margin-bottom:8px;padding:10px 11px;border:1px solid #e0eaf3;background:linear-gradient(135deg,#f5f9fd,#edf5fb)}.allrd-headline .hl-lbl{max-width:55%;font-size:.66rem}.allrd-headline .hl-val{color:#1769b0;font-size:1.35rem;text-align:right}.allrd-headline .hl-val small{font-size:.7rem}.allrd-preview,.allrd-rows{display:flex;flex-direction:column;gap:0;margin:0}.allrd-row{min-height:29px;padding:5px 2px;border-bottom:1px solid #eef2f6;font-size:.73rem}.allrd-row .k{overflow:hidden;color:#61758a;text-overflow:ellipsis;white-space:nowrap}.allrd-row .v{color:#294760;white-space:nowrap}.allrd-row .v small{font-size:.62rem}.allrd-more{align-self:flex-start;margin-top:7px;padding:5px 7px;border-radius:7px;background:#eef5fc;color:#1769b0;font-size:.68rem}.allrd-more:hover{background:#e1effc}.allrd-dev-time{margin-top:auto;padding-top:9px;font-size:.62rem}
  .allrd-empty{padding:50px 20px;border:1px dashed #cfdbe7;border-radius:14px;background:#fafcfe;color:#718398;text-align:center}.allrd-empty i{display:block;margin-bottom:9px;color:#9ab0c4;font-size:1.8rem}
  @media(max-width:900px){.allrd-summary{grid-template-columns:1fr 1fr}.allrd-toolbar{position:static}.allrd-searchbox{max-width:none}.allrd-chips{order:3;width:100%}}
  @media(max-width:600px){.allrd-wrap{padding:4px 0 28px}.allrd-head{align-items:flex-start;padding:14px;flex-direction:column}.allrd-sub{margin-left:0}.allrd-updated{align-self:stretch}.allrd-summary{grid-template-columns:1fr;gap:7px}.allrd-stat{padding:11px 12px}.allrd-stat .val{font-size:1.12rem}.allrd-toolbar{align-items:stretch;padding:8px}.allrd-searchbox{flex-basis:100%;width:100%}.allrd-chips{flex-wrap:nowrap;overflow-x:auto;padding-bottom:3px;scrollbar-width:none}.allrd-chips::-webkit-scrollbar{display:none}.allrd-toggle{margin-left:0}.allrd-cards{grid-template-columns:1fr}}
  .allrd-stat.configurable{padding-right:45px}.allrd-stat-edit{position:absolute;top:50%;right:10px;display:grid;place-items:center;width:29px;height:29px;border:1px solid #cbdcec;border-radius:8px;background:#f5f9fd;color:#1769d2;transform:translateY(-50%);cursor:pointer}.allrd-stat-edit:hover{border-color:#1769d2;background:#e8f2fd}
  .allrd-stat .lbl em{display:inline-block;margin-left:6px;padding:2px 6px;border-radius:8px;background:#e7f1ff;color:#1769d2;font-size:.55rem;font-style:normal;font-weight:800;vertical-align:1px}
  .allrd-modal[hidden]{display:none}.allrd-modal{position:fixed;z-index:1800;inset:0;display:grid;place-items:center;padding:16px;background:rgba(13,31,49,.58)}.allrd-modal-card{width:min(470px,100%);overflow:hidden;border:1px solid #c9dbe9;border-radius:15px;background:#fff;box-shadow:0 24px 65px rgba(12,37,60,.32)}.allrd-modal-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #dce7f0;background:#edf4fb}.allrd-modal-head>div{display:flex;align-items:center;gap:10px}.allrd-modal-head>div>i{display:grid;place-items:center;width:34px;height:34px;border-radius:9px;background:#dceafa;color:#1769d2}.allrd-modal-head b,.allrd-modal-head small{display:block}.allrd-modal-head b{color:#294760;font-size:.88rem}.allrd-modal-head small{margin-top:2px;color:#718398;font-size:.65rem}.allrd-modal-head>button{width:29px;height:29px;border:0;border-radius:7px;background:#fff;color:#6c8093;font-size:1.15rem}.allrd-modal-body{display:grid;gap:12px;padding:16px}.allrd-modal-body label>span{display:block;margin-bottom:5px;color:#526b82;font-size:.7rem;font-weight:750}.allrd-modal-body label>span small{font-weight:500}.allrd-modal-body select,.allrd-modal-body input{width:100%;min-height:40px;padding:7px 9px;border:1px solid #cedce8;border-radius:8px;background:#fff;color:#304b61;outline:0}.allrd-modal-body select:focus,.allrd-modal-body input:focus{border-color:#1769d2;box-shadow:0 0 0 3px rgba(23,105,210,.09)}.allrd-display-preview{padding:9px 11px;border:1px solid #dce7f0;border-radius:9px;background:#f7fafc}.allrd-display-preview small,.allrd-display-preview strong{display:block}.allrd-display-preview small{color:#7b8c9d;font-size:.62rem}.allrd-display-preview strong{margin-top:2px;color:#1769b0;font-size:.8rem}.allrd-modal-foot{display:flex;justify-content:flex-end;gap:8px;padding:11px 16px;border-top:1px solid #e2eaf1;background:#fafcfe}.allrd-modal-foot button{min-height:36px;padding:7px 13px;border:1px solid #d1dce6;border-radius:8px;background:#fff;color:#536b80;font-size:.72rem;font-weight:750}.allrd-modal-foot #allrdTotalSave{border-color:#1769d2;background:#1769d2;color:#fff}
</style>

<script>
(function(){
  const deviceArr = <?php echo ($dvl_txt !== '' ? $dvl_txt : '{}'); ?>;
  const detailRgl = <?php echo (isset($detail_rgl_txt) && $detail_rgl_txt !== '' ? $detail_rgl_txt : '{}'); ?>;
  const paramList = <?php echo (isset($param_txt) && $param_txt !== '' ? $param_txt : '[]'); ?>;

  const paramMap = {};
  (Array.isArray(paramList) ? paramList : []).forEach(p => {
    if (p && p.Parameter) paramMap[p.Parameter] = {
      name: (p.InfoData && p.InfoData.Name) ? p.InfoData.Name : p.Parameter,
      unit: (p.InfoData && p.InfoData.Unit) ? p.InfoData.Unit : '',
      order: (p.Order !== undefined && p.Order !== null) ? p.Order : 9999
    };
  });
  const pName  = c => (paramMap[c] && paramMap[c].name) ? paramMap[c].name : c;
  const pUnit  = c => (paramMap[c] && paramMap[c].unit) ? paramMap[c].unit : '';
  const pOrder = c => (paramMap[c] && paramMap[c].order !== undefined) ? paramMap[c].order : 9999;

  const devType  = id => { const d=deviceArr[id]||{},m=d.model||null; return (m && detailRgl[m] && detailRgl[m].type) ? detailRgl[m].type : (String(d.mode||'').toLowerCase()==='bacnet' ? 'bacnet' : 'unknown'); };
  const devName  = id => (deviceArr[id] && deviceArr[id].name) ? deviceArr[id].name : id;
  const devModel = id => (deviceArr[id] && deviceArr[id].model) ? deviceArr[id].model : '';

  const TYPE_PRIORITY = ['meter','inverter'];
  const typeRank = t => { if (t === 'unknown' || !t) return TYPE_PRIORITY.length + 1; const i = TYPE_PRIORITY.indexOf(t); return i !== -1 ? i : TYPE_PRIORITY.length; };

  // headline metric preferences per type; falls back to first numeric value
  const HEADLINE = { meter:['p_t','e_p','v_an'], inverter:['p_out','p_t','p_max'], sensor:['temp1','hm'], battery:['soc','p_t'] };
  const HIDE_KEYS = new Set(['dt','error']);
  const OFFLINE_SEC = 180;

  const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const isNum = v => v !== '' && v !== null && v !== undefined && !isNaN(Number(v));
  function fmtVal(v){ if (isNum(v)){ const n = Number(v); return Number.isInteger(n) ? n.toLocaleString() : n.toLocaleString(undefined,{maximumFractionDigits:3}); } return esc(v); }
  function ago(ts){ if (!ts) return ''; const d = Math.floor(Date.now()/1000) - Number(ts); if (d < 0) return 'เมื่อสักครู่'; if (d < 60) return d+' วินาทีที่แล้ว'; if (d < 3600) return Math.floor(d/60)+' นาทีที่แล้ว'; if (d < 86400) return Math.floor(d/3600)+' ชม.ที่แล้ว'; return Math.floor(d/86400)+' วันที่แล้ว'; }

  function pickHeadline(type, vals){
    const prefs = HEADLINE[type] || [];
    for (const k of prefs) if (vals[k] !== undefined && isNum(vals[k])) return k;
    for (const k of Object.keys(vals)) if (!HIDE_KEYS.has(k) && isNum(vals[k])) return k;
    return null;
  }

  // ----- UI state -----
  let latest = {};        // last payload.data
  const filterStateKey = 'mdbiot_alldata_filter_v1';
  let activeType = 'all';
  let query = '';
  let onlineOnly = false;
  try { const saved=JSON.parse(localStorage.getItem(filterStateKey)||'{}'); if(typeof saved.type==='string'&&saved.type)activeType=saved.type; onlineOnly=saved.onlineOnly===true; } catch(error){}
  const saveFilterState=()=>{try{localStorage.setItem(filterStateKey,JSON.stringify({type:activeType,onlineOnly}));}catch(error){}};
  const expanded = {};    // deviceId -> true when full list shown
  const totalConfigKey = 'mdbiot_alldata_total_v1';
  let totalParameter = 'p_t', totalDisplayName = '', totalCalculation = 'sum';
  try { const saved=JSON.parse(localStorage.getItem(totalConfigKey)||'{}'); if(saved.parameter)totalParameter=String(saved.parameter); if(saved.displayName)totalDisplayName=String(saved.displayName); if(['sum','avg'].includes(saved.calculation))totalCalculation=saved.calculation; } catch(error){}

  const searchEl = document.getElementById('allrdSearch');
  const clearEl  = document.getElementById('allrdClear');
  const onlineEl = document.getElementById('allrdOnline');
  onlineEl.checked=onlineOnly;

  searchEl.addEventListener('input', () => { query = searchEl.value.trim().toLowerCase(); clearEl.style.display = query ? '' : 'none'; draw(); });
  clearEl.addEventListener('click', () => { searchEl.value=''; query=''; clearEl.style.display='none'; draw(); });
  onlineEl.addEventListener('change', () => { onlineOnly = onlineEl.checked; saveFilterState(); draw(); });
  const totalModal=document.getElementById('allrdTotalModal');
  const totalSelect=document.getElementById('allrdTotalParameter');
  const totalCalculationSelect=document.getElementById('allrdTotalCalculation');
  const totalLabel=document.getElementById('allrdTotalLabel');
  const totalPreview=document.getElementById('allrdTotalPreview');
  const defaultTotalLabel=()=>(pName(totalSelect.value)||totalSelect.value);
  const syncTotalPreview=()=>{totalPreview.textContent=totalLabel.value.trim()||defaultTotalLabel();};
  const closeTotalModal=()=>totalModal.hidden=true;
  document.getElementById('allrdSummary').addEventListener('click',event=>{
    if(!event.target.closest('#allrdTotalEdit'))return;
    const keys=new Set(Object.values(latest).flatMap(values=>values&&typeof values==='object'?Object.keys(values):[]));
    keys.delete('dt');keys.delete('error');keys.add(totalParameter);
    totalSelect.innerHTML=[...keys].sort((a,b)=>pOrder(a)-pOrder(b)||pName(a).localeCompare(pName(b))).map(key=>'<option value="'+esc(key)+'">'+esc(pName(key))+' ['+esc(key)+']'+(pUnit(key)?' · '+esc(pUnit(key)):'')+'</option>').join('');
    totalSelect.value=totalParameter;totalCalculationSelect.value=totalCalculation;totalLabel.value=totalDisplayName;syncTotalPreview();totalModal.hidden=false;totalSelect.focus();
  });
  totalSelect.addEventListener('change',syncTotalPreview);totalCalculationSelect.addEventListener('change',syncTotalPreview);totalLabel.addEventListener('input',syncTotalPreview);
  document.getElementById('allrdTotalClose').addEventListener('click',closeTotalModal);
  document.getElementById('allrdTotalCancel').addEventListener('click',closeTotalModal);
  totalModal.addEventListener('click',event=>{if(event.target===totalModal)closeTotalModal();});
  document.getElementById('allrdTotalSave').addEventListener('click',()=>{totalParameter=totalSelect.value||'p_t';totalCalculation=totalCalculationSelect.value==='avg'?'avg':'sum';totalDisplayName=totalLabel.value.trim();try{localStorage.setItem(totalConfigKey,JSON.stringify({parameter:totalParameter,calculation:totalCalculation,displayName:totalDisplayName}));}catch(error){}closeTotalModal();draw();});

  // delegate "show more/less" clicks
  document.getElementById('allrdSections').addEventListener('click', e => {
    const btn = e.target.closest('.allrd-more'); if (!btn) return;
    const id = btn.dataset.id; expanded[id] = !expanded[id]; draw();
  });

  function matchQuery(id){
    if (!query) return true;
    return (id+' '+devName(id)+' '+devModel(id)).toLowerCase().includes(query);
  }

  function render(payload){
    latest = (payload && payload.data) ? payload.data : {};
    document.getElementById('allrdUpdated').textContent = 'อัปเดตล่าสุด: ' + (payload && payload.dt ? payload.dt : '-');
    draw();
  }

  function draw(){
    // Include every configured device, even when the latest API payload has no
    // reading for it. Payload-only devices are retained for forward compatibility.
    const ids = Array.from(new Set([...Object.keys(deviceArr), ...Object.keys(latest)]));
    const nowSec = Math.floor(Date.now()/1000);
    const hasError = id => {
      const value = latest[id] ? latest[id].error : null;
      return value !== null && value !== undefined && value !== '' && String(value) !== '0';
    };
    const isOffline = id => {
      const ts = latest[id] && latest[id].dt ? Number(latest[id].dt) : 0;
      return !Number.isFinite(ts) || ts <= 0 || (nowSec - ts > OFFLINE_SEC) || hasError(id);
    };

    // summary (whole fleet, not filtered)
    let onlineCount = 0, offlineCount = 0, sumPower = 0, totalValueCount = 0;
    ids.forEach(id => {
      isOffline(id) ? offlineCount++ : onlineCount++;
      const p = latest[id] ? latest[id][totalParameter] : null;
      if (isNum(p)){ sumPower += Number(p); totalValueCount++; }
    });
    document.getElementById('allrdSummary').innerHTML =
        deviceStat(onlineCount, ids.length, offlineCount)
      + stat(totalDisplayName||pName(totalParameter), (totalCalculation==='avg'&&totalValueCount?sumPower/totalValueCount:sumPower).toLocaleString(undefined,{maximumFractionDigits:3}), pUnit(totalParameter), true);

    // type chips (count per type across fleet)
    const typeCount = {};
    ids.forEach(id => { const t = devType(id); typeCount[t] = (typeCount[t]||0)+1; });
    const chipTypes = Object.keys(typeCount).sort((a,b)=>{ const r=typeRank(a)-typeRank(b); return r!==0?r:a.localeCompare(b); });
    if (activeType !== 'all' && !typeCount[activeType]) { activeType = 'all'; saveFilterState(); }
    let chipHtml = chip('all','ทั้งหมด',ids.length);
    chipTypes.forEach(t => chipHtml += chip(t,t,typeCount[t]));
    document.getElementById('allrdChips').innerHTML = chipHtml;
    document.querySelectorAll('.allrd-chip').forEach(c => c.onclick = () => { activeType = c.dataset.type; saveFilterState(); draw(); });

    // apply filters
    let shown = ids.filter(id => (activeType==='all' || devType(id)===activeType) && matchQuery(id) && (!onlineOnly || !isOffline(id)));

    const sectionsEl = document.getElementById('allrdSections');
    const emptyEl = document.getElementById('allrdEmpty');
    if (!shown.length){
      sectionsEl.innerHTML = '';
      emptyEl.style.display = '';
      document.getElementById('allrdEmptyMsg').textContent = ids.length ? 'ไม่พบอุปกรณ์ที่ตรงกับตัวกรอง' : 'ยังไม่มีข้อมูลจากอุปกรณ์';
      return;
    }
    emptyEl.style.display = 'none';

    // group shown ids by type
    const groups = {};
    shown.forEach(id => { const t = devType(id); (groups[t]=groups[t]||[]).push(id); });
    const orderedTypes = Object.keys(groups).sort((a,b)=>{ const r=typeRank(a)-typeRank(b); return r!==0?r:a.localeCompare(b); });

    let html = '';
    orderedTypes.forEach(type => {
      const list = groups[type].slice().sort((a,b)=>devName(a).localeCompare(devName(b)));
      const dashboardLink=type==='inverter'?'<a class="allrd-dashboard-link" href="index.php?page=solar-performance"><i class="fa-solid fa-chart-line"></i><span>Solar Monitoring</span><i class="fa-solid fa-arrow-right"></i></a>':'';
      html += '<div class="allrd-group"><div class="allrd-group-head"><span class="gtitle">'+esc(type)+'</span>'
            + '<span class="gcount">'+list.length+'</span><span class="allrd-group-line"></span>'+dashboardLink+'</div><div class="allrd-cards">';

      list.forEach(id => {
        const vals = latest[id] || {};
        const ts = vals.dt ? Number(vals.dt) : 0;
        const off = isOffline(id);
        const keys = Object.keys(vals).filter(k => !HIDE_KEYS.has(k)).sort((a,b)=>pOrder(a)-pOrder(b));
        const hlKey = pickHeadline(type, vals);

        let headline = '';
        if (hlKey){
          headline = '<div class="allrd-headline"><div class="hl-lbl">'+esc(pName(hlKey))+'</div>'
                   + '<div class="hl-val">'+fmtVal(vals[hlKey])+(pUnit(hlKey)?' <small>'+esc(pUnit(hlKey))+'</small>':'')+'</div></div>';
        }

        const restKeys = keys.filter(k => k !== hlKey);
        const isOpen = !!expanded[id];
        const previewKeys = restKeys.slice(0,3);
        const detailKeys = restKeys.slice(3);
        let previewRows = previewKeys.map(k =>
            '<div class="allrd-row"><span class="k">'+esc(pName(k))+'</span><span class="v">'
          + fmtVal(vals[k])+(pUnit(k)?' <small>'+esc(pUnit(k))+'</small>':'')+'</span></div>').join('');
        let rows = detailKeys.map(k =>
            '<div class="allrd-row"><span class="k">'+esc(pName(k))+'</span><span class="v">'
          + fmtVal(vals[k])+(pUnit(k)?' <small>'+esc(pUnit(k))+'</small>':'')+'</span></div>').join('');
        if (!keys.length) headline = '<div class="allrd-headline"><div class="hl-lbl">สถานะ</div><div class="hl-val" style="font-size:1rem;">ไม่มีค่า</div></div>';

        let moreBtn = '';
        if (detailKeys.length){
          moreBtn = '<button class="allrd-more" data-id="'+esc(id)+'"><i class="fa-solid fa-chevron-'+(isOpen?'up':'down')+'"></i>'
                  + (isOpen ? 'ซ่อนรายละเอียด' : 'ดูเพิ่มเติม '+detailKeys.length+' ค่า')+'</button>';
        }

        html += '<div class="allrd-card'+(off?' offline':'')+'">'
              + '<div class="allrd-card-head"><div><div class="allrd-dev-name">'+esc(devName(id))+'</div>'
              + '<div class="allrd-dev-sn">'+esc(id)+(devModel(id)?' · '+esc(devModel(id)):'')+'</div></div>'
              + '<span class="allrd-badge'+(off?' off':'')+'">'+(off?'offline':'online')+'</span></div>'
              + headline + '<div class="allrd-preview">'+previewRows+'</div>' + moreBtn
              + '<div class="allrd-rows'+(isOpen?'':' collapsed')+'">'+rows+'</div>'
              + '<div class="allrd-dev-time">'+(ts?ago(ts):'')+'</div></div>';
      });
      html += '</div></div>';
    });
    sectionsEl.innerHTML = html;
  }

  function stat(lbl,val,unit,configurable=false){ return '<div class="allrd-stat'+(configurable?' configurable':'')+'"><div class="lbl">'+esc(lbl)+(configurable?'<em>'+(totalCalculation==='avg'?'AVG':'SUM')+'</em>':'')+'</div><div class="val">'+val+(unit?' <small>'+esc(unit)+'</small>':'')+'</div>'+(configurable?'<button type="button" class="allrd-stat-edit" id="allrdTotalEdit" title="ตั้งค่าข้อมูลสรุปรวม"><i class="fa-solid fa-pen"></i></button>':'')+'</div>'; }
  function deviceStat(online,total,down){ return '<div class="allrd-stat allrd-device-stat"><div class="lbl">สรุปอุปกรณ์</div><div class="allrd-device-total"><strong>'+online+'</strong><span>/ '+total+' เครื่อง</span><em>Online</em></div><div class="allrd-device-down"><i class="fa-solid fa-arrow-down"></i><span>Down</span><strong>'+down+'</strong><small>เครื่อง</small></div></div>'; }
  function chip(type,label,n){ return '<button class="allrd-chip'+(activeType===type?' active':'')+'" data-type="'+esc(type)+'">'+esc(label)+'<span class="n">'+n+'</span></button>'; }

  let loading = false;
  function load(){
    if (loading) return;
    loading = true;
    fetch('./api_mdbiotx.php?_=' + Date.now(), {cache:'no-store'}).then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    }).then(render)
      .catch(()=>{ document.getElementById('allrdUpdated').textContent = 'โหลดข้อมูลไม่สำเร็จ'; })
      .finally(()=>{ loading = false; });
  }
  load();
  setInterval(load, 2000);
})();
</script>
