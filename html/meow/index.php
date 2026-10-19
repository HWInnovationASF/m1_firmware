<?php
    require_once __DIR__ . '/session_bootstrap.php';

    // Count overdue PM tasks (used by tab title, bell badge and sidebar badge)
    date_default_timezone_set("Asia/Bangkok");
    $pm_overdue_tasks = array();
    foreach (glob('./log_device/task_*_pm.txt') as $pm_file) {
        foreach (file($pm_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $pm_line) {
            $pm_row = json_decode($pm_line, true);
            if (!is_array($pm_row)) continue;
            if (($pm_row['task_status'] ?? '') === 'completed') continue;
            $pm_schedule_ts = !empty($pm_row['schedule']) ? strtotime($pm_row['schedule']) : false;
            if ($pm_schedule_ts === false) continue;
            $pm_alert_ts = $pm_schedule_ts - (intval($pm_row['alertBefore'] ?? 0) * 60);
            if (time() >= $pm_alert_ts) $pm_overdue_tasks[] = $pm_row;
        }
    }
    $pm_overdue_count = !empty($_SESSION['username']) ? count($pm_overdue_tasks) : 0;
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $pm_overdue_count > 0 ? "(" . $pm_overdue_count . ") " : ""; ?>MDBIoT</title>
  <link rel="shortcut icon" type="image/png" href="./assets/images/logos/favicon.png" />
  <?php if ($pm_overdue_count > 0): ?>
  <script>
    // Draw the overdue count as a red badge on the favicon (browser tab icon)
    (function () {
      var count = <?php echo intval($pm_overdue_count); ?>;
      var img = new Image();
      img.src = './assets/images/logos/favicon.png';
      img.onload = function () {
        var size = 32;
        var canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, size, size);
        // red circle at bottom-right
        ctx.beginPath();
        ctx.arc(size - 9, size - 9, 9, 0, Math.PI * 2);
        ctx.fillStyle = '#dc3545';
        ctx.fill();
        // count number
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 12px Arial';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(count > 99 ? '99+' : String(count), size - 9, size - 8);
        var link = document.querySelector("link[rel='shortcut icon']");
        link.type = 'image/png';
        link.href = canvas.toDataURL('image/png');
      };
    })();
  </script>
  <?php endif; ?>
  <link rel="stylesheet" href="./assets/css/styles.min.css" />

  
  <script src="./assets/js/jquery-3.6.0.slim.js"></script>
  <script src="./assets/js/jquery.min.js"></script>
  <script src="./assets/sweetalert/sweetalert2.all.min.js"></script>

  <link href="./assets/fontawesome-free-7.0.0-web/css/fontawesome.css" rel="stylesheet" />
  <link href="./assets/fontawesome-free-7.0.0-web/css/brands.css" rel="stylesheet" />
  <link href="./assets/fontawesome-free-7.0.0-web/css/solid.css" rel="stylesheet" />
  <!-- <link href="./assets/fontawesome-free-7.0.0-web/css/sharp-thin.css" rel="stylesheet" /> -->
  <!-- <link href="./assets/fontawesome-free-7.0.0-web/css/sharp-duotone-thin.css" rel="stylesheet" /> -->

  <link href="./assets/ajax/libs/MaterialDesign-Webfont/css/materialdesignicons.css" rel="stylesheet" />

  <!-- Theme native <select> dropdowns app-wide to match the device UI.
       Loaded after the vendor stylesheet so these win; scoped to select so it
       never touches the device .msel widget (which hides its native select). -->
  <style>
    select,
    select.form-select,
    select.form-control {
        border: 1px solid #cdddf3 !important;
        border-radius: 10px !important;
        color: #1f2b3d;
        background-color: #eef4fc;
        font-weight: 500;
        transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
    }
    select:hover,
    select.form-select:hover,
    select.form-control:hover {
        border-color: #7fb0ee !important;
        background-color: #e2edfb;
    }
    select:focus,
    select.form-select:focus,
    select.form-control:focus {
        border-color: #0a63c9 !important;
        box-shadow: 0 0 0 3px rgba(10, 99, 201, 0.15) !important;
        background-color: #fff;
        outline: none;
    }
    .sidebar-child-indicator {
        margin-left: auto;
        color: #8093a8;
        font-size: 11px;
        transition: transform .18s ease, color .18s ease;
    }
    .sidebar-link.has-arrow[aria-expanded="true"] .sidebar-child-indicator,
    .sidebar-link.has-arrow.active .sidebar-child-indicator {
        color: #0a63c9;
        transform: rotate(90deg);
    }
    .manage-devices-toggle.has-arrow::after {
        display: none !important;
        content: none !important;
    }
    .scroll-sidebar {
        scrollbar-width: thin;
        scrollbar-color: #9bbce0 transparent;
        scrollbar-gutter: stable;
    }
    .scroll-sidebar::-webkit-scrollbar {
        width: 7px;
    }
    .scroll-sidebar::-webkit-scrollbar-track {
        margin: 8px 0;
        background: transparent;
    }
    .scroll-sidebar::-webkit-scrollbar-thumb {
        border: 2px solid transparent;
        border-radius: 999px;
        background: #9bbce0;
        background-clip: padding-box;
    }
    .scroll-sidebar::-webkit-scrollbar-thumb:hover {
        background: #4f8fce;
        background-clip: padding-box;
    }
    /* Shared navigation appearance, collapsible desktop rail, and light/dark mode. */
    :root{--th-brand:#064a99;--th-brand-l:#0a63c9;--th-navy:#0b2a52;--th-tint:#f0f5fc;--th-tint2:#dce9f9;--th-ring:#bcd3ee;--th-line:#dfe6f0;--th-bg2:#e8eef6;--th-surface:#fff;--th-text:#33445c;--th-text2:#6b7c93;--th-text3:#8393a9;--th-hdr1:#043a78;--th-hdr2:#064a99;--th-hdr3:#0a63c9}
    .gd-rail-btn{display:none;position:absolute;top:50%;left:100%;margin-top:-32px;z-index:5;width:22px;height:64px;padding:0;border:1px solid var(--th-ring);border-left:0;border-radius:0 16px 16px 0;background:var(--th-tint);color:var(--th-brand-l);align-items:center;justify-content:center;cursor:pointer;box-shadow:3px 0 14px rgba(15,31,51,.16);transition:width .18s,color .18s,background .18s,opacity .2s}
    .gd-rail-btn:hover,.gd-rail-btn:focus-visible{width:26px;color:#fff;background:var(--th-brand-l);border-color:var(--th-brand-l);outline:none}.gd-rail-btn svg{transition:transform .25s}
    #rail-tip{position:fixed;display:none;z-index:2200;padding:6px 12px;border-radius:999px;background:var(--th-navy);color:#fff;font-size:.82rem;font-weight:500;white-space:nowrap;pointer-events:none;box-shadow:0 6px 18px rgba(15,31,51,.28)}
    #rail-tip::before{content:"";position:absolute;left:-4px;top:50%;width:8px;height:8px;margin-top:-4px;background:var(--th-navy);transform:rotate(45deg)}
    .left-sidebar{background:var(--th-surface);border-right:1px solid var(--th-line);box-shadow:2px 0 18px rgba(11,42,82,.04)}
    .left-sidebar .brand-logo{background:linear-gradient(120deg,var(--th-hdr1),var(--th-hdr2) 58%,var(--th-hdr3))!important;transition:background .25s}
    #sidebarnav .nav-small-cap{font-size:.64rem;font-weight:700;letter-spacing:.9px}
    #sidebarnav .sidebar-link{border-radius:10px;font-size:14.5px;font-weight:500;padding-top:9px;padding-bottom:9px;transition:background-color .15s,color .15s,box-shadow .15s}
    #sidebarnav .first-level .sidebar-link{font-size:12.5px;padding-top:6px;padding-bottom:6px}
    /* Light mode: neutral blue-gray surfaces with clear separation between layers. */
    html:not([data-gmode=dark]){--bs-body-bg:#eef3f8;--bs-body-color:#26384d;--bs-border-color:#d8e1ec;--bs-secondary-bg:#f7f9fc;--bs-tertiary-bg:#e8eef6;--bs-link-color:#075aaa}
    html:not([data-gmode=dark]) body,html:not([data-gmode=dark]) .page-wrapper,html:not([data-gmode=dark]) .body-wrapper{background:#eef3f8;color:#26384d}
    html:not([data-gmode=dark]) .app-header{background:rgba(255,255,255,.97);border-bottom:1px solid #d8e1ec;box-shadow:0 3px 14px rgba(35,63,92,.06)}
    html:not([data-gmode=dark]) .app-header .nav-link{color:#52677e}html:not([data-gmode=dark]) .app-header .nav-link:hover{color:#075aaa;background:#edf4fc;border-radius:9px}
    html:not([data-gmode=dark]) .app-header .navbar-nav p{color:#24384e}
    html:not([data-gmode=dark]) .left-sidebar{background:#fbfcfe;border-right-color:#d8e1ec;box-shadow:3px 0 18px rgba(35,63,92,.055)}
    /* Light navigation contrast matrix: normal / hover / active / submenu active. */
    html:not([data-gmode=dark]) #sidebarnav .nav-small-cap{color:#64748b}
    html:not([data-gmode=dark]) #sidebarnav .sidebar-link{background:transparent!important;color:#334155!important;box-shadow:none!important}
    html:not([data-gmode=dark]) #sidebarnav .sidebar-link i,html:not([data-gmode=dark]) #sidebarnav .sidebar-link .ti{color:#64748b!important}
    html:not([data-gmode=dark]) #sidebarnav .sidebar-link:hover,html:not([data-gmode=dark]) #sidebarnav .sidebar-link:focus-visible{background:#e2eaf4!important;color:#0b477c!important}
    html:not([data-gmode=dark]) #sidebarnav .sidebar-link:hover i,html:not([data-gmode=dark]) #sidebarnav .sidebar-link:hover .ti,html:not([data-gmode=dark]) #sidebarnav .sidebar-link:focus-visible i{color:#0b5aa6!important}
    html:not([data-gmode=dark]) #sidebarnav>.sidebar-item.selected>.sidebar-link,html:not([data-gmode=dark]) #sidebarnav>.sidebar-item.selected>.sidebar-link:hover,html:not([data-gmode=dark]) #sidebarnav>.sidebar-item.selected>.sidebar-link:focus-visible,html:not([data-gmode=dark]) #sidebarnav>.sidebar-item>.sidebar-link.active,html:not([data-gmode=dark]) #sidebarnav>.sidebar-item>.sidebar-link.active:hover{background:#1e56b3!important;color:#fff!important;box-shadow:0 4px 12px rgba(30,86,179,.25)!important}
    html:not([data-gmode=dark]) #sidebarnav>.sidebar-item.selected>.sidebar-link *,html:not([data-gmode=dark]) #sidebarnav>.sidebar-item>.sidebar-link.active *{color:#fff!important;fill:currentColor!important;stroke:currentColor!important}
    html:not([data-gmode=dark]) #sidebarnav>.sidebar-item.selected>.sidebar-link.has-arrow::after,html:not([data-gmode=dark]) #sidebarnav>.sidebar-item>.sidebar-link.active.has-arrow::after{border-color:#fff!important}
    html:not([data-gmode=dark]) #sidebarnav .first-level .sidebar-link.active,html:not([data-gmode=dark]) #sidebarnav .first-level .sidebar-link.active:hover{background:#d9e9fb!important;color:#123f73!important;box-shadow:inset 3px 0 #2563b9!important}
    html:not([data-gmode=dark]) #sidebarnav .first-level .sidebar-link.active *{color:#123f73!important}
    html:not([data-gmode=dark]) .card{--bs-card-bg:#fff;--bs-card-border-color:#dce4ee;--bs-card-title-color:#23384f;--bs-card-subtitle-color:#6b7d91;--bs-card-cap-bg:#f7f9fc;border-color:#dce4ee;box-shadow:0 5px 18px rgba(36,61,88,.055)}
    html:not([data-gmode=dark]) .dropdown-menu,html:not([data-gmode=dark]) .modal-content{background:#fff;border-color:#d8e1ec;box-shadow:0 12px 30px rgba(27,49,74,.12)}
    html:not([data-gmode=dark]) .dropdown-item{color:#334b63}html:not([data-gmode=dark]) .dropdown-item:hover,html:not([data-gmode=dark]) .dropdown-item:focus{background:#edf4fc;color:#075aaa}
    html:not([data-gmode=dark]) select,html:not([data-gmode=dark]) .form-control,html:not([data-gmode=dark]) .form-select{background-color:#fff;color:#26384d;border-color:#cbd8e6}
    html:not([data-gmode=dark]) select:hover,html:not([data-gmode=dark]) .form-control:hover,html:not([data-gmode=dark]) .form-select:hover{border-color:#8eacca;background-color:#fafdff}
    html:not([data-gmode=dark]) select:focus,html:not([data-gmode=dark]) .form-control:focus,html:not([data-gmode=dark]) .form-select:focus{border-color:#0a63c9;background-color:#fff;box-shadow:0 0 0 .2rem rgba(10,99,201,.13)}
    html:not([data-gmode=dark]) .gd-mode-pick{background:#fbfcfe;border-top-color:#d8e1ec}
    html:not([data-gmode=dark]) .gd-mode-seg{background:#e9f0f8;border-color:#d2deeb}
    html:not([data-gmode=dark]) .gd-mode-seg button.is-on{background:#075aaa;color:#fff;box-shadow:0 3px 9px rgba(7,90,170,.24)}
    html[data-gmode=dark]{color-scheme:dark;--th-brand:#5aa2ee;--th-brand-l:#86bdf7;--th-navy:#e6eef9;--th-tint:#1a2a42;--th-tint2:#23395a;--th-ring:#36527c;--th-line:#25344a;--th-bg2:#0a111d;--th-surface:#121d2e;--th-text:#cbd7e8;--th-text2:#8ea2bd;--th-text3:#7186a3;--th-hdr1:#0a2447;--th-hdr2:#0f3a73;--th-hdr3:#1a5aa8;--bs-body-bg:#0a111d;--bs-body-color:#dbe5f2;--bs-border-color:#25344a;--bs-secondary-bg:#121d2e;--bs-tertiary-bg:#162338;--bs-link-color:#86bdf7}
    html[data-gmode=dark] body,html[data-gmode=dark] .page-wrapper,html[data-gmode=dark] .body-wrapper{background:var(--th-bg2)}
    html[data-gmode=dark] .app-header{background:var(--th-surface);border-bottom:1px solid var(--th-line)}html[data-gmode=dark] .app-header .nav-link{color:#b6c4d8}html[data-gmode=dark] .app-header .navbar-nav p{color:#e6eef9}
    html[data-gmode=dark] .card{--bs-card-bg:#121d2e;--bs-card-border-color:#25344a;--bs-card-title-color:#e6eef9;--bs-card-subtitle-color:#8ea2bd;--bs-card-cap-bg:#162338}
    html[data-gmode=dark] .dropdown-menu,html[data-gmode=dark] .modal-content{background:#121d2e;border-color:#25344a}html[data-gmode=dark] .dropdown-item{color:#cbd7e8}
    /* Dark navigation contrast matrix: brighter text, visible hover, saturated active. */
    html[data-gmode=dark] #sidebarnav .nav-small-cap{color:#91a3ba}
    html[data-gmode=dark] #sidebarnav .sidebar-link{background:transparent!important;color:#cbd5e1!important;box-shadow:none!important}
    html[data-gmode=dark] #sidebarnav .sidebar-link i,html[data-gmode=dark] #sidebarnav .sidebar-link .ti{color:#91a3ba!important}
    html[data-gmode=dark] #sidebarnav .sidebar-link:hover,html[data-gmode=dark] #sidebarnav .sidebar-link:focus-visible{background:#203552!important;color:#f8fafc!important}
    html[data-gmode=dark] #sidebarnav .sidebar-link:hover i,html[data-gmode=dark] #sidebarnav .sidebar-link:hover .ti,html[data-gmode=dark] #sidebarnav .sidebar-link:focus-visible i{color:#93c5fd!important}
    html[data-gmode=dark] #sidebarnav>.sidebar-item.selected>.sidebar-link,html[data-gmode=dark] #sidebarnav>.sidebar-item.selected>.sidebar-link:hover,html[data-gmode=dark] #sidebarnav>.sidebar-item.selected>.sidebar-link:focus-visible,html[data-gmode=dark] #sidebarnav>.sidebar-item>.sidebar-link.active,html[data-gmode=dark] #sidebarnav>.sidebar-item>.sidebar-link.active:hover{background:#2563c5!important;color:#fff!important;box-shadow:0 4px 14px rgba(0,0,0,.32)!important}
    html[data-gmode=dark] #sidebarnav>.sidebar-item.selected>.sidebar-link *,html[data-gmode=dark] #sidebarnav>.sidebar-item>.sidebar-link.active *{color:#fff!important;fill:currentColor!important;stroke:currentColor!important}
    html[data-gmode=dark] #sidebarnav>.sidebar-item.selected>.sidebar-link.has-arrow::after,html[data-gmode=dark] #sidebarnav>.sidebar-item>.sidebar-link.active.has-arrow::after{border-color:#fff!important}
    html[data-gmode=dark] #sidebarnav .first-level .sidebar-link.active,html[data-gmode=dark] #sidebarnav .first-level .sidebar-link.active:hover{background:#243d60!important;color:#bfdbfe!important;box-shadow:inset 3px 0 #60a5fa!important}
    html[data-gmode=dark] #sidebarnav .first-level .sidebar-link.active *{color:#bfdbfe!important}
    .left-sidebar .scroll-sidebar{padding-bottom:72px}.gd-mode-pick{position:absolute;left:0;right:0;bottom:0;display:flex;align-items:center;gap:10px;padding:12px 16px 14px 20px;background:var(--th-surface);border-top:1px solid var(--th-line);z-index:3}
    .gd-mp-label{font-size:.68rem;font-weight:700;letter-spacing:.9px;color:var(--th-text3);text-transform:uppercase;margin-right:auto}.gd-mode-seg{display:inline-flex;padding:3px;border-radius:999px;background:var(--th-tint);border:1px solid var(--th-line)}
    .gd-mode-seg button{display:inline-flex;align-items:center;gap:5px;border:0;background:transparent;color:var(--th-text2);padding:5px 12px;border-radius:999px;font-size:.78rem;font-weight:600;cursor:pointer;transition:background .15s,color .15s,transform .15s}.gd-mode-seg button svg{flex:none;width:20px;height:20px}.gd-mode-seg button[data-m=light] svg{color:#e8a200}.gd-mode-seg button[data-m=dark] svg{color:#7d93b8}.gd-mode-seg button:hover{color:var(--th-brand);transform:translateY(-1px)}.gd-mode-seg button.is-on{background:var(--th-brand);color:#fff;box-shadow:0 2px 8px rgba(0,0,0,.2)}.gd-mode-seg button.is-on[data-m=light] svg{color:#ffd54a}.gd-mode-seg button.is-on[data-m=dark] svg{color:#cfe3ff}html[data-gmode=dark] .gd-mode-seg button.is-on{background:#2a62a8}
    @media (min-width:1200px){
      .gd-rail-btn{display:flex}.left-sidebar{transition:width .26s}.body-wrapper{transition:margin-left .26s}.app-header{transition:width .26s}
      #main-wrapper.gd-rail .left-sidebar{width:76px}#main-wrapper.gd-rail[data-sidebartype=full] .body-wrapper{margin-left:76px}#main-wrapper.gd-rail[data-header-position=fixed] .app-header{width:calc(100% - 76px)}#main-wrapper.gd-rail .gd-rail-btn svg{transform:rotate(180deg)}
      #main-wrapper.gd-rail .gd-rail-btn{opacity:0;pointer-events:none}#main-wrapper.gd-rail .left-sidebar:hover .gd-rail-btn,#main-wrapper.gd-rail .gd-rail-btn:focus-visible{opacity:1;pointer-events:auto}
      #main-wrapper.gd-rail .brand-logo{padding:0;justify-content:center!important}#main-wrapper.gd-rail .brand-logo a div{display:none}#main-wrapper.gd-rail .brand-logo img{margin:0!important}
      #main-wrapper.gd-rail .scroll-sidebar{padding:0 12px}#main-wrapper.gd-rail .hide-menu,#main-wrapper.gd-rail .sidebar-child-indicator,#main-wrapper.gd-rail .sidebar-link .badge,#main-wrapper.gd-rail .first-level{display:none!important}#main-wrapper.gd-rail .sidebar-link.has-arrow::after{display:none!important}
      #main-wrapper.gd-rail .sidebar-nav .sidebar-link{justify-content:center!important;gap:0;padding:12px 0;margin:3px 0}#main-wrapper.gd-rail .sidebar-nav .sidebar-link .d-flex{gap:0!important;justify-content:center}#main-wrapper.gd-rail .sidebar-nav .sidebar-link i{font-size:19px;margin:0}
      #main-wrapper.gd-rail .nav-small-cap{height:1px;margin:14px;padding:0;overflow:hidden;line-height:0;background:#d5dfec}#main-wrapper.gd-rail .nav-small-cap>*{display:none!important}
      #main-wrapper.gd-rail .gd-mode-pick{justify-content:center;padding:10px 0 14px}#main-wrapper.gd-rail .gd-mp-label{display:none}#main-wrapper.gd-rail .gd-mode-seg{padding:0;border:0;background:transparent}#main-wrapper.gd-rail .gd-mode-seg button:not(.is-on){display:none}#main-wrapper.gd-rail .gd-mode-seg button span{display:none}#main-wrapper.gd-rail .gd-mode-seg button.is-on{padding:9px;border-radius:50%}#main-wrapper.gd-rail .gd-mode-seg button.is-on svg{width:24px;height:24px}
    }
  </style>

</head>

<body>
  <?php 
    // include("con_db.php");
    // include("z_function.php");
    // include("./mx_function.php");

    // Store data 
    $rgl_txt = file_get_contents('./config/rgl.json');
    $dvl_txt = file_get_contents('./config/dvl.json');
    $cml_txt = file_get_contents('./config/cml.json');
    // Parameter master list (code -> full display Name / Order) for the MDB List tree
    $param_txt = file_exists('./config/parameter_list.json') ? file_get_contents('./config/parameter_list.json') : '[]';
    // Model detail (model -> { type, abbr }) used to sort the MDB List tree by model type
    $detail_rgl_txt = file_exists('./config/detail_rgl.json') ? file_get_contents('./config/detail_rgl.json') : '{}';

    // $rgl_txt = file_get_contents('../rgl.json');
    // $dvl_txt = file_get_contents('../dvl.json');
    // $cml_txt = file_get_contents('../cml.json');
    $rgl_arr = $rgl_txt != "" ? json_decode($rgl_txt,true) : array();
    // Compatibility view for pages that only need parameter metadata.
    // The configurator reads the raw banked RGL directly; other pages receive a
    // flat view containing both banks (input collisions use an "input:" key).
    foreach ($rgl_arr as $rgl_model_name => $rgl_model_regs) {
        if (!is_array($rgl_model_regs) || (!isset($rgl_model_regs['holding']) && !isset($rgl_model_regs['input']))) continue;
        $flat_regs = isset($rgl_model_regs['holding']) && is_array($rgl_model_regs['holding'])
            ? $rgl_model_regs['holding'] : array();
        $input_regs = isset($rgl_model_regs['input']) && is_array($rgl_model_regs['input'])
            ? $rgl_model_regs['input'] : array();
        foreach ($input_regs as $reg => $info) {
            $flat_regs[array_key_exists($reg, $flat_regs) ? 'input:'.$reg : $reg] = $info;
        }
        $rgl_arr[$rgl_model_name] = $flat_regs;
    }
    $rgl_txt = json_encode($rgl_arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $dvl_arr = $dvl_txt != "" ? json_decode($dvl_txt,true) : array();
    $cml_arr = $cml_txt != "" ? json_decode($cml_txt,true) : array();

    // #######################################################

    // $pg = isset($_GET['pg'])?$_GET['pg']:'';
    // include("login.php");
    // $_SESSION['timestart'] = time();
    // echo $_SESSION['username'];
    // echo $_SESSION['user_type'];
    // echo time() - intval($_SESSION['timestart']);
    // if($_SESSION['username']){
if(!empty($_SESSION['username'])){
    $pg = isset($_GET['page']) ? $_GET['page']: "";
    $displayTemplateConfig = json_decode((string)@file_get_contents(__DIR__.'/config/dashboard_template.json'), true);
    $displayTemplateActive = is_array($displayTemplateConfig) ? ($displayTemplateConfig['active'] ?? 'energy_diagram') : 'energy_diagram';
    $displayTemplateLabels = array('energy_diagram'=>'Energy Diagram','chiller_diagram'=>'Chiller Diagram','template1'=>'Template 1','template2'=>'Template 2','template3'=>'Template 3','template4'=>'Template 4','template5'=>'Template 5','template6'=>'Template 6');
    if(is_array($displayTemplateConfig) && isset($displayTemplateConfig['labels']) && is_array($displayTemplateConfig['labels'])) foreach($displayTemplateLabels as $templateId=>$defaultLabel){ $customLabel=trim(strip_tags((string)($displayTemplateConfig['labels'][$templateId] ?? ''))); if($customLabel!=='') $displayTemplateLabels[$templateId]=mb_substr($customLabel,0,60,'UTF-8'); }
    if(!isset($displayTemplateLabels[$displayTemplateActive])) $displayTemplateActive = 'energy_diagram';
    $displayTemplateLabel = $displayTemplateLabels[$displayTemplateActive];
    require_once __DIR__.'/user_permission.php';
    $is_admin_user = in_array(strtolower($_SESSION['user_type'] ?? ''), array('admin', 'superadmin'), true);
    $admin_only_pages = array(
        'network', 'communication', 'device', 'configurator', 'parameter-list', 'command-list', 'template-config', 'template-settings',
        'automation-config', 'automation-simulation-log', 'battery-command-history', 'battery-soc', 'maintenance', 'schedule', 'ev', 'register', 'about-system', 'firmware-update', 'solar-performance-config'
    );
    $permissionDisplayMap=['dashboard'=>'dashboard','dashboardx'=>'dashboard','alldata'=>'alldata','solar-performance'=>'solar-performance','energy'=>'energy','device'=>'device','network'=>'network','communication'=>'communication','about-system'=>'about-system'];
    $permissionFunctionMap=['automation-config'=>'automation','automation-simulation-log'=>'automation','battery-command-history'=>'automation','command-list'=>'manual_command','parameter-list'=>'device_config','configurator'=>'device_config','solar-performance-config'=>'device_config','battery-soc'=>'report','maintenance'=>'report','schedule'=>'report'];
    if(strtolower($_SESSION['user_type'] ?? '')!=='superadmin'&&(isset($permissionDisplayMap[$pg])||isset($permissionFunctionMap[$pg]))){$allowed=(isset($permissionDisplayMap[$pg])&&mdb_can_display($permissionDisplayMap[$pg]))||(isset($permissionFunctionMap[$pg])&&mdb_can_function($permissionFunctionMap[$pg]));if(!$allowed)$pg='access-denied';}
    $sidebarPermission=mdb_user_permissions();
?>
<style>
.sidebar-link.permission-locked{cursor:not-allowed!important;opacity:.48;filter:grayscale(.45);pointer-events:auto}.sidebar-link.permission-locked:hover{background:transparent!important;color:inherit!important}.sidebar-permission-lock{display:inline-grid;place-items:center;margin-left:auto;color:#8c9aa7;font-size:.68rem}.sidebar-link.permission-locked .sidebar-permission-lock{color:#a35f5f}
</style>
<script>
document.addEventListener('DOMContentLoaded',()=>{const role=<?=json_encode(strtolower($_SESSION['user_type']??''))?>;if(role==='superadmin')return;const permission=<?=json_encode($sidebarPermission,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,displays=new Set(permission.displays||[]),functions=new Set(permission.functions||[]),displayMap={'dashboard':'dashboard','dashboardx':'dashboard','alldata':'alldata','solar-performance':'solar-performance','energy':'energy','device':'device','network':'network','communication':'communication','about-system':'about-system'},functionMap={'automation-config':'automation','automation-simulation-log':'automation','battery-command-history':'automation','command-list':'manual_command','parameter-list':'device_config','configurator':'device_config','solar-performance-config':'device_config','battery-soc':'report','maintenance':'report','schedule':'report'};document.querySelectorAll('.left-sidebar .sidebar-link[data-page]').forEach(link=>{const page=link.dataset.page,display=displayMap[page],fn=functionMap[page],allowed=display?(displays.has('*')||displays.has(display)):fn?(functions.has('*')||functions.has(fn)):true;if(allowed)return;link.classList.add('permission-locked');link.setAttribute('aria-disabled','true');link.setAttribute('tabindex','-1');link.removeAttribute('onclick');link.removeAttribute('href');link.title='ไม่มีสิทธิ์เข้าถึง';const lock=document.createElement('i');lock.className='fa-solid fa-lock sidebar-permission-lock';lock.setAttribute('aria-hidden','true');link.appendChild(lock);link.addEventListener('click',event=>{event.preventDefault();event.stopImmediatePropagation()})})});
</script>

  <!--  Body Wrapper -->
  <div class="page-wrapper" id="main-wrapper" data-layout="vertical" data-navbarbg="skin6" data-sidebartype="full"
    data-sidebar-position="fixed" data-header-position="fixed">
    <script>try{if(localStorage.getItem('mdb_sidebar_rail')==='1')document.getElementById('main-wrapper').classList.add('gd-rail');if(localStorage.getItem('mdb_gmode')==='dark'){document.documentElement.setAttribute('data-gmode','dark');document.documentElement.setAttribute('data-bs-theme','dark');}}catch(e){}</script>
    <!-- Sidebar Start -->
    <aside class="left-sidebar" style="top:0px;">
      <button type="button" class="gd-rail-btn" id="gdRailBtn" aria-label="พับหรือขยายเมนู" title="พับ/ขยายเมนู"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M15 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
      <!-- Sidebar scroll-->
      <div>
        <div class="brand-logo d-flex align-items-center justify-content-between" style="background-color:#064a99;">
          <a class="d-flex justify-content-center" href="#">
            <img src="assets/images/logos/cat.gif" alt=""  width="50" style="border-radius:50%;margin-right:8px;">
            <div style="color:#ffffff;font-size:2rem;font-weight:700;">MDBIoT</div>
          </a>
          <div class="close-btn d-xl-none d-block sidebartoggler cursor-pointer" id="sidebarCollapse">
            <i class="ti ti-x fs-6"></i>
          </div>
        </div>
        <!-- Sidebar navigation-->
        <nav class="sidebar-nav scroll-sidebar" data-simplebar="">
          <ul id="sidebarnav">
            <li class="nav-small-cap">
              <iconify-icon icon="solar:menu-dots-linear" class="nav-small-cap-icon fs-4"></iconify-icon>
              <span class="hide-menu">Home</span>
            </li>
            <li class="sidebar-item">
              <a class="sidebar-link" href="#" data-page="dashboard" onclick="linkpage('dashboard')" aria-expanded="false">
                <i class="fa-solid fa-tv"></i>
                <span class="hide-menu">Monitor</span>
              </a>
            </li>
            <li class="sidebar-item">
              <a class="sidebar-link" href="#" data-page="alldata" onclick="linkpage('alldata')" aria-expanded="false">
                <i class="fa-solid fa-list-ul"></i>
                <span class="hide-menu">All Readings</span>
              </a>
            </li>
            <li class="sidebar-item">
              <?php if($displayTemplateActive === 'energy_diagram'){ ?>
              <a class="sidebar-link justify-content-between has-arrow" href="javascript:void(0)" aria-expanded="false">
                <div class="d-flex align-items-center gap-3"><span class="d-flex"><i class="fa-solid fa-diagram-project"></i></span><span class="hide-menu"><?php echo htmlspecialchars($displayTemplateLabel, ENT_QUOTES, 'UTF-8');?></span></div>
              </a>
              <ul aria-expanded="false" class="collapse first-level">
                <li class="sidebar-item"><a class="sidebar-link justify-content-between" href="#" data-page="energy" onclick="linkpage('energy')"><div class="d-flex align-items-center gap-3"><div class="round-16 d-flex align-items-center justify-content-center"><i class="ti ti-circle"></i></div><span class="hide-menu">View Template</span></div></a></li>
                <?php if(in_array(strtolower($_SESSION['user_type'] ?? ''), array('admin','superadmin'), true)){ ?>
                <li class="sidebar-item"><a class="sidebar-link justify-content-between" href="#" data-page="automation-config" onclick="linkpage('automation-config')"><div class="d-flex align-items-center gap-3"><div class="round-16 d-flex align-items-center justify-content-center"><i class="ti ti-circle"></i></div><span class="hide-menu">Automation Config</span></div></a></li>
                <li class="sidebar-item"><a class="sidebar-link justify-content-between" href="#" data-page="automation-simulation-log" onclick="linkpage('automation-simulation-log')"><div class="d-flex align-items-center gap-3"><div class="round-16 d-flex align-items-center justify-content-center"><i class="ti ti-circle"></i></div><span class="hide-menu">Simulation Log</span></div></a></li>
                <li class="sidebar-item"><a class="sidebar-link justify-content-between" href="#" data-page="battery-command-history" onclick="linkpage('battery-command-history')"><div class="d-flex align-items-center gap-3"><div class="round-16 d-flex align-items-center justify-content-center"><i class="ti ti-circle"></i></div><span class="hide-menu">Battery Compare</span></div></a></li>
                <?php } ?>
              </ul>
              <?php }else{ ?>
              <a class="sidebar-link" href="#" data-page="energy" onclick="linkpage('energy')" aria-expanded="false">
                <i class="fa-solid fa-diagram-project"></i>
                <span class="hide-menu"><?php echo htmlspecialchars($displayTemplateLabel, ENT_QUOTES, 'UTF-8');?></span>
              </a>
              <?php } ?>
            </li>
            <!-- ---------------------------------- -->
            <!-- Dashboard -->
            <!-- ---------------------------------- -->
            <!-- <li class="sidebar-item">
              <a class="sidebar-link justify-content-between"  
                href="#" aria-expanded="false">
                <div class="d-flex align-items-center gap-3">
                  <span class="d-flex">
                    <i class="ti ti-aperture"></i>
                  </span>
                  <span class="hide-menu">Analytical</span>
                </div>
                
              </a>
            </li> -->
            <?php 
            // echo $_SESSION['user_type'];
            if(strtolower($_SESSION['user_type']) == "superadmin" || strtolower($_SESSION['user_type']) == "admin"){
            ?>
            <li>
              <span class="sidebar-divider lg"></span>
            </li>
            <li class="nav-small-cap">
              <iconify-icon icon="solar:menu-dots-linear" class="nav-small-cap-icon fs-4"></iconify-icon>
              <span class="hide-menu">Tools</span>
            </li>
            <li class="sidebar-item">
              <a class="sidebar-link justify-content-between has-arrow" href="javascript:void(0)" aria-expanded="false">
                <div class="d-flex align-items-center gap-3">
                  <span class="d-flex">
                    <i class="ti ti-settings"></i>
                  </span>
                  <span class="hide-menu">Settings</span>
                </div>
                
              </a>
              <ul aria-expanded="false" class="collapse first-level">
                <li class="sidebar-item">
                  <a class="sidebar-link justify-content-between" href="#" data-page="template-config" onclick="linkpage('template-config')">
                    <div class="d-flex align-items-center gap-3"><div class="round-16 d-flex align-items-center justify-content-center"><i class="ti ti-circle"></i></div><span class="hide-menu">Template Config</span></div>
                  </a>
                </li>
                <li class="sidebar-item">
                  <a class="sidebar-link justify-content-between" href="#" data-page="communication" onclick="linkpage('communication')" >
                    <div class="d-flex align-items-center gap-3">
                      <div class="round-16 d-flex align-items-center justify-content-center">
                        <i class="ti ti-circle"></i>
                      </div>
                      <span class="hide-menu">System & Configuration</span>
                    </div>
                    
                  </a>
                </li>
                <li class="sidebar-item">
                  <a class="sidebar-link justify-content-between"
                    href="#" data-page="network" onclick="linkpage('network')">
                    <div class="d-flex align-items-center gap-3">
                      <div class="round-16 d-flex align-items-center justify-content-center">
                        <i class="ti ti-circle"></i>
                      </div>
                      <span class="hide-menu">Network & Interface</span>
                    </div>
                    
                  </a>
                </li>
                <li class="sidebar-item">
                  <a class="sidebar-link justify-content-between has-arrow manage-devices-toggle"
                    href="javascript:void(0)" aria-expanded="false">
                    <div class="d-flex align-items-center gap-3">
                      <div class="round-16 d-flex align-items-center justify-content-center">
                        <i class="ti ti-circle"></i>
                      </div>
                      <span class="hide-menu">Manage Devices</span>
                    </div>
                    <i class="fa-solid fa-chevron-right sidebar-child-indicator" aria-hidden="true"></i>
                  </a>
                  <ul aria-expanded="false" class="collapse first-level" style="padding-left:20px;">
                    <li class="sidebar-item">
                      <a class="sidebar-link justify-content-between"
                        href="#" data-page="device" data-pg="meter" onclick="linkpage('device')">
                        <div class="d-flex align-items-center gap-3">
                          <div class="round-16 d-flex align-items-center justify-content-center">
                            <i class="ti ti-circle"></i>
                          </div>
                          <span class="hide-menu">Device</span>
                        </div>

                      </a>
                    </li>
                    <li class="sidebar-item">
                      <a class="sidebar-link justify-content-between"
                        href="#" data-page="device" data-pg="model" onclick="window.location.href='index.php?page=device&pg=modellist'">
                        <div class="d-flex align-items-center gap-3">
                          <div class="round-16 d-flex align-items-center justify-content-center">
                            <i class="ti ti-circle"></i>
                          </div>
                          <span class="hide-menu">Model</span>
                        </div>

                      </a>
                    </li>
                    <li class="sidebar-item">
                      <a class="sidebar-link justify-content-between"
                        href="#" data-page="parameter-list" onclick="linkpage('parameter-list')">
                        <div class="d-flex align-items-center gap-3">
                          <div class="round-16 d-flex align-items-center justify-content-center">
                            <i class="ti ti-circle"></i>
                          </div>
                          <span class="hide-menu">Parameter List</span>
                        </div>
                      </a>
                    </li>
                    <li class="sidebar-item">
                      <a class="sidebar-link justify-content-between"
                        href="#" data-page="command-list" onclick="linkpage('command-list')">
                        <div class="d-flex align-items-center gap-3">
                          <div class="round-16 d-flex align-items-center justify-content-center">
                            <i class="ti ti-circle"></i>
                          </div>
                          <span class="hide-menu">Command List</span>
                        </div>
                      </a>
                    </li>
                  </ul>
                </li>
                <li class="sidebar-item">
                  <a class="sidebar-link justify-content-between"
                    href="#" data-page="schedule" onclick="linkpage('schedule')">
                    <div class="d-flex align-items-center gap-3">
                      <div class="round-16 d-flex align-items-center justify-content-center">
                        <i class="ti ti-circle"></i>
                      </div>
                      <span class="hide-menu">Schedule PM</span>
                    </div>

                  </a>
                </li>
                <!-- <li class="sidebar-item">
                  <a class="sidebar-link justify-content-between"  
                    href="#"  onclick="linkpage('dashboard')">
                    <div class="d-flex align-items-center gap-3">
                      <div class="round-16 d-flex align-items-center justify-content-center">
                        <i class="ti ti-circle"></i>
                      </div>
                      <span class="hide-menu">Dashboard</span>
                    </div>
                    
                  </a>
                </li> -->
              </ul>
            </li>

            <li class="sidebar-item">
              <a class="sidebar-link justify-content-between has-arrow" href="javascript:void(0)" aria-expanded="false">
                <div class="d-flex align-items-center gap-3">
                  <span class="d-flex">
                    <i class="ti ti-server-cog"></i>
                  </span>
                  <span class="hide-menu">Management</span>
                </div>
                
              </a>
              <ul aria-expanded="false" class="collapse first-level">
                <li class="sidebar-item">
                  <a class="sidebar-link justify-content-between" href="#" data-page="battery-soc" onclick="linkpage('battery-soc')">
                    <div class="d-flex align-items-center gap-3">
                      <div class="round-16 d-flex align-items-center justify-content-center">
                        <i class="ti ti-circle"></i>
                      </div>
                      <span class="hide-menu">Battery SOC Table</span>
                    </div>
                  </a>
                </li>
                <li class="sidebar-item">
                  <a class="sidebar-link justify-content-between" href="#" data-page="maintenance" onclick="linkpage('maintenance')">
                    <div class="d-flex align-items-center gap-3">
                      <div class="round-16 d-flex align-items-center justify-content-center">
                        <i class="ti ti-circle"></i>
                      </div>
                      <span class="hide-menu">Maintenance</span>
                    </div>
                    <?php if ($pm_overdue_count > 0): ?>
                    <span class="hide-menu badge rounded-pill bg-danger"><?php echo $pm_overdue_count > 99 ? '99+' : $pm_overdue_count; ?></span>
                    <?php endif; ?>
                  </a>
                </li>
                
                <!-- <li class="sidebar-item">
                  <a class="sidebar-link justify-content-between" href="#" onclick="send_cmd('pm2 start /home/mdbcare/python/py_main.py')" >
                    <div class="d-flex align-items-center gap-3">
                      <div class="round-16 d-flex align-items-center justify-content-center">
                        <i class="ti ti-circle"></i>
                      </div>
                      <span class="hide-menu">Start</span>
                    </div>
                    
                  </a>
                </li> -->

                <li class="sidebar-item">
                  <a class="sidebar-link justify-content-between" href="#" onclick="event.preventDefault();systemControl('restart_service')" >
                    <div class="d-flex align-items-center gap-3">
                      <div class="round-16 d-flex align-items-center justify-content-center">
                        <i class="ti ti-circle"></i>
                      </div>
                      <span class="hide-menu">Restart</span>
                    </div>
                    
                  </a>
                </li>

                <li class="sidebar-item">
                  <a class="sidebar-link justify-content-between" href="#" onclick="event.preventDefault();systemControl('reboot_system')" >
                    <div class="d-flex align-items-center gap-3">
                      <div class="round-16 d-flex align-items-center justify-content-center">
                        <i class="ti ti-circle"></i>
                      </div>
                      <span class="hide-menu">Reboot</span>
                    </div>
                    
                  </a>
                </li>
                
              </ul>
            </li>

            <li class="sidebar-item">
              <a class="sidebar-link justify-content-between has-arrow" href="javascript:void(0)" aria-expanded="false">
                <div class="d-flex align-items-center gap-3">
                  <span class="d-flex"><i class="fa-solid fa-circle-info"></i></span>
                  <span class="hide-menu">About System</span>
                </div>
              </a>
              <ul aria-expanded="false" class="collapse first-level">
                <li class="sidebar-item">
                  <a class="sidebar-link justify-content-between" href="#" data-page="about-system" onclick="linkpage('about-system')">
                    <div class="d-flex align-items-center gap-3"><div class="round-16 d-flex align-items-center justify-content-center"><i class="ti ti-device-desktop-analytics"></i></div><span class="hide-menu">System Info</span></div>
                  </a>
                </li>
                <li class="sidebar-item">
                  <a class="sidebar-link justify-content-between" href="#" data-page="firmware-update" onclick="linkpage('firmware-update')">
                    <div class="d-flex align-items-center gap-3"><div class="round-16 d-flex align-items-center justify-content-center"><i class="ti ti-refresh"></i></div><span class="hide-menu">Update Firmware</span></div>
                  </a>
                </li>
              </ul>
            </li>

            <li>
              <span class="sidebar-divider lg"></span>
            </li>
            <li class="nav-small-cap">
              <iconify-icon icon="solar:menu-dots-linear" class="nav-small-cap-icon fs-4"></iconify-icon>
              <span class="hide-menu">Management</span>
            </li>
            <li class="sidebar-item">
              <a class="sidebar-link justify-content-between has-arrow" href="javascript:void(0)" aria-expanded="false">
                <div class="d-flex align-items-center gap-3">
                  <span class="d-flex">
                    <i class="ti ti-users"></i>
                  </span>
                  <span class="hide-menu">User Management</span>
                </div>

              </a>
              <ul aria-expanded="false" class="collapse first-level">
                <li class="sidebar-item">
                  <a class="sidebar-link justify-content-between" href="#" data-page="register" onclick="linkpage('register')">
                    <div class="d-flex align-items-center gap-3">
                      <div class="round-16 d-flex align-items-center justify-content-center">
                        <i class="ti ti-circle"></i>
                      </div>
                      <span class="hide-menu">Add User</span>
                    </div>

                  </a>
                </li>
              </ul>
            </li>

            <?php } ?>


            <!-- ///////////////////// -->

            <!-- <li>
              <span class="sidebar-divider lg"></span>
            </li>
            <li class="nav-small-cap">
              <iconify-icon icon="solar:menu-dots-linear" class="nav-small-cap-icon fs-4"></iconify-icon>
              <span class="hide-menu">Apps</span>
            </li>
            <li class="sidebar-item">
              <a class="sidebar-link justify-content-between"  
                href="#"
                aria-expanded="false">
                <div class="d-flex align-items-center gap-3">
                  <span class="d-flex">
                    <i class="ti ti-user-circle"></i>
                  </span>
                  <span class="hide-menu">User Profile</span>
                </div>
                
              </a>
            </li> -->
            <!-- <li class="sidebar-item">
              <a class="sidebar-link justify-content-between"  
                href="#" aria-expanded="false">
                <div class="d-flex align-items-center gap-3">
                  <span class="d-flex">
                    <i class="ti ti-calendar"></i>
                  </span>
                  <span class="hide-menu">Calendar</span>
                </div>
                
              </a>
            </li> -->

<!--             
            <li>
              <span class="sidebar-divider lg"></span>
            </li>
            <li class="nav-small-cap">
              <iconify-icon icon="solar:menu-dots-linear" class="nav-small-cap-icon fs-4"></iconify-icon>
              <span class="hide-menu">Auth</span>
            </li>

            <li class="sidebar-item">
              <a class="sidebar-link" href="./authentication-register.php" aria-expanded="false">
                <i class="ti ti-user-plus"></i>
                <span class="hide-menu">Register</span>
              </a>
            </li>
            <li class="sidebar-item">
              <a class="sidebar-link justify-content-between"  
                href="#"
                aria-expanded="false">
                <div class="d-flex align-items-center gap-3">
                  <span class="d-flex">
                    <i class="ti ti-alert-circle"></i>
                  </span>
                  <span class="hide-menu">Error</span>
                </div>
                
              </a>
            </li>
            <li class="sidebar-item">
              <a class="sidebar-link justify-content-between"  
                href="#"
                aria-expanded="false">
                <div class="d-flex align-items-center gap-3">
                  <span class="d-flex">
                    <i class="ti ti-settings"></i>
                  </span>
                  <span class="hide-menu">Maintenance</span>
                </div>
                
              </a>
            </li> -->

          </ul>
        </nav>
        <!-- End Sidebar navigation -->
      </div>
      <!-- End Sidebar scroll-->
      <div class="gd-mode-pick" id="gdModePick" role="group" aria-label="โหมดแสดงผล">
        <span class="gd-mp-label">โหมด</span>
        <div class="gd-mode-seg">
          <button type="button" data-m="light" title="โหมดสว่าง" aria-label="โหมดสว่าง"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4.2" fill="currentColor"/><path d="M12 2.5v2.6M12 18.9v2.6M2.5 12h2.6M18.9 12h2.6M5.3 5.3l1.9 1.9M16.8 16.8l1.9 1.9M18.7 5.3l-1.9 1.9M7.2 16.8l-1.9 1.9"/></svg><span>สว่าง</span></button>
          <button type="button" data-m="dark" title="โหมดมืด" aria-label="โหมดมืด"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="currentColor"><path d="M20.4 14.6A8.6 8.6 0 0 1 9.4 3.6a.7.7 0 0 0-.9-.9A9.8 9.8 0 1 0 21.3 15.5a.7.7 0 0 0-.9-.9z"/></svg><span>มืด</span></button>
        </div>
      </div>
    </aside>
    <!--  Sidebar End -->
    <!--  Main wrapper -->
    <div class="body-wrapper">
      <!--  Header Start -->
      <header class="app-header" style="top:0px;">
        <nav class="navbar navbar-expand-lg navbar-light">
          <ul class="navbar-nav">
            <li class="nav-item d-block d-xl-none">
              <a class="nav-link sidebartoggler " id="headerCollapse" href="javascript:void(0)">
                <i class="ti ti-menu-2"></i>
              </a>
            </li>
            <li class="nav-item dropdown">
              <a class="nav-link " href="javascript:void(0)" id="drop1" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="ti ti-bell"></i>
                <?php if ($pm_overdue_count > 0): ?>
                <div class="notification bg-danger rounded-circle d-flex align-items-center justify-content-center text-white"
                  style="width:17px;height:17px;font-size:10px;font-weight:600;top:16px;right:2px;">
                  <?php echo $pm_overdue_count > 99 ? '99+' : $pm_overdue_count; ?>
                </div>
                <?php endif; ?>
              </a>
              <div class="dropdown-menu dropdown-menu-animate-up" aria-labelledby="drop1">
                <div class="message-body">
                  <?php if ($pm_overdue_count > 0): ?>
                    <?php foreach ($pm_overdue_tasks as $pm_ov): ?>
                    <a href="#" class="dropdown-item"
                      onclick="gotoPmTask('<?php echo htmlspecialchars($pm_ov['deviceSN'] ?? '', ENT_QUOTES); ?>','<?php echo htmlspecialchars($pm_ov['createdAt'] ?? '', ENT_QUOTES); ?>')">
                      <i class="fa-solid fa-triangle-exclamation text-danger me-2"></i>
                      <?php echo htmlspecialchars(($pm_ov['deviceSN'] ?? '') . ' : ' . ($pm_ov['title'] ?? '')); ?>
                    </a>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <a href="javascript:void(0)" class="dropdown-item">No overdue tasks</a>
                  <?php endif; ?>
                </div>
              </div>
            </li>
          </ul>
          <div class="navbar-collapse justify-content-end px-0" id="navbarNav">
            <ul class="navbar-nav flex-row ms-auto align-items-center justify-content-end">
               
              <li class="nav-item dropdown" style="display:flex;align-items: center;">
                <p class="mb-0" style="text-align:center;font-size:18px;font-weight:700;">
                  <?php echo $_SESSION['username'];?>
                </p>
                <a class="nav-link " href="javascript:void(0)" id="drop2" data-bs-toggle="dropdown"
                  aria-expanded="false">
                  <img src="./assets/images/profile/user-3.jpg" alt="" width="35" height="35" class="rounded-circle">
                </a>
                <div class="dropdown-menu dropdown-menu-end dropdown-menu-animate-up" aria-labelledby="drop2">
                  <div class="message-body">
                    <a href="javascript:void(0)" class="d-flex align-items-center gap-2 dropdown-item" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                      <i class="ti ti-lock fs-6"></i>
                      <p class="mb-0 fs-3">Change Password</p>
                    </a>
                    <!-- <a href="javascript:void(0)" class="d-flex align-items-center gap-2 dropdown-item">
                      <i class="ti ti-mail fs-6"></i>
                      <p class="mb-0 fs-3">My Account</p>
                    </a> -->
                    <!-- <a href="javascript:void(0)" class="d-flex align-items-center gap-2 dropdown-item">
                      <i class="ti ti-list-check fs-6"></i>
                      <p class="mb-0 fs-3">My Task</p>
                    </a> -->
                    <a href="./logout.php" class="btn btn-outline-primary mx-3 mt-2 d-block">Logout</a>
                  </div>
                </div>
              </li>
            </ul>
          </div>
        </nav>
      </header>
      <!--  Header End -->

      <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <form id="changePasswordForm">
              <div class="modal-header">
                <h5 class="modal-title">Change Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <div class="mb-3">
                  <label for="current_password" class="form-label">Current password</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" class="form-control" id="current_password" placeholder="Current password" required>
                  </div>
                </div>
                <div class="mb-3">
                  <label for="new_password_self" class="form-label">New password</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" class="form-control" id="new_password_self" placeholder="New password" required minlength="4">
                  </div>
                </div>
                <div class="mb-3">
                  <label for="new_password_self_confirm" class="form-label">Confirm new password</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" class="form-control" id="new_password_self_confirm" placeholder="Confirm new password" required minlength="4">
                  </div>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="cancelChangePassword()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save changes</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <div class="body-wrapper-inner">
        <div class="container-fluid" style="padding-top:100px;max-width:1600px;padding-left:12px;padding-right:12px;">
          <div class="row">
            <div class="col-g-6">
              <?php
              // echo $pg;
                if($pg === 'access-denied'){
                    http_response_code(403);
                    echo '<div style="display:flex;min-height:55vh;align-items:center;justify-content:center;padding:24px"><div style="max-width:430px;width:100%;padding:30px;border:1px solid #e0e7ef;border-radius:16px;background:#fff;text-align:center;box-shadow:0 8px 25px rgba(31,55,89,.07)"><i class="fa-solid fa-lock" style="display:grid;width:54px;height:54px;margin:0 auto 13px;place-items:center;border-radius:50%;background:#f5eaea;color:#b45656;font-size:1.25rem"></i><h4 style="margin:0;color:#29445c">ไม่มีสิทธิ์เข้าถึง</h4><p style="margin:7px 0 0;color:#77899a;font-size:.78rem">กรุณาติดต่อ Superadmin เพื่อเปิดสิทธิ์ Display หรือ Function นี้</p></div></div>';
                }
                elseif(in_array($pg, array("network","communication","device","configurator"), true)){
                    require_once("mx_config_pages.php");
                }
                if($pg == "network"){
                    render_config_network();
                }
                if($pg == "communication"){
                    render_config_communication();
                }
                elseif($pg == "device"){
                    render_config_device();
                }
                elseif($pg == "configurator"){
                    render_configurator();
                }
                elseif($pg == "dashboard"){
                    include("monitorx.php");
                }
                elseif($pg == "dashboardx"){
                    include("monitorx.php");
                }
                elseif($pg == "alldata"){
                     include("monitor_all.php");
                }
                elseif($pg == "solar-performance"){
                     include("solar_performance.php");
                }
                elseif($pg == "solar-performance-config"){
                     include("solar_performance_config.php");
                }
                elseif($pg == "energy"){
                    if($displayTemplateActive === 'energy_diagram') include("energy_diagram.php");
                    elseif($displayTemplateActive === 'chiller_diagram') include("chiller_diagram.php");
                    else { $dashboard_template_uid = preg_match('/^template([1-6])$/', $displayTemplateActive, $displayTemplateMatch) ? (int)$displayTemplateMatch[1] : 6; include("monitorx.php"); }
                    include("dashboard_layout.php");
                }
                elseif($pg == "template-config"){
                    include("dashboard_template_config.php");
                }
                elseif($pg == "template-settings"){
                    include("template_settings.php");
                }
                elseif($pg == "about-system"){
                    include("system_info.php");
                }
                elseif($pg == "firmware-update"){
                    include("firmware_update.php");
                }
                elseif($pg == "battery-soc"){
                    if(in_array(strtolower($_SESSION['user_type'] ?? ''), array('admin','superadmin'), true)){
                        include("battery_soc_table.php");
                    }else{
                        include("monitorx.php");
                    }
                }
                elseif($pg == "automation-config"){
                    if(in_array(strtolower($_SESSION['user_type'] ?? ''), array('admin','superadmin'), true)){
                        include("automation_config.php");
                    }else{
                        include("monitorx.php");
                    }
                }
                elseif($pg == "automation-simulation-log"){
                    if(in_array(strtolower($_SESSION['user_type'] ?? ''), array('admin','superadmin'), true)){
                        include("automation_simulation_log.php");
                    }else{
                        include("monitorx.php");
                    }
                }
                elseif($pg == "battery-command-history"){
                    if(in_array(strtolower($_SESSION['user_type'] ?? ''), array('admin','superadmin'), true)){
                        include("battery_command_history.php");
                    }else{
                        include("monitorx.php");
                    }
                }
                elseif($pg == "parameter-list"){
                    include("parameter_list.php");
                }
                elseif($pg == "command-list"){
                    include("command_list.php");
                }
                elseif($pg == "maintenance"){
                    if(in_array(strtolower($_SESSION['user_type'] ?? ''), array('admin','superadmin'), true)){
                        include("monitor_pm.php");
                    }else{
                        include("monitorx.php");
                    }
                }
                elseif($pg == "schedule"){
                    include("monitor_pm.php");
                }
                elseif($pg == "ev"){
                    include("mx_config_ev.php");
                }
                elseif($pg == "register"){
                    include("mx_user_management.php");
                }
                else{
                    // include("monitor.php");
                    // include("mx_config_communication.php");
                }
                // include("mx_config_communication.php");
              ?>
            </div>
          </div>
          <!-- <div class="py-6 px-6 text-center">
            <p class="mb-0 fs-4">Design and Developed by <a href="#"
                class="pe-1 text-primary text-decoration-underline">Wrappixel.com</a> Distributed by <a href="https://themewagon.com" target="_blank" >ThemeWagon</a></p>
          </div> -->
        </div>
      </div>
    </div>
  </div>


  <script src="./assets/libs/jquery/dist/jquery.min.js"></script>
  <script src="./assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/sidebarmenu.js"></script>
  <script src="./assets/js/app.min.js"></script>
  <!-- <script src="./assets/libs/apexcharts/dist/apexcharts.min.js"></script> -->
  <!-- <script src="./assets/libs/simplebar/dist/simplebar.js"></script> -->
  <!-- <script src="./assets/js/dashboard.js"></script> -->
  <!-- solar icons -->
  <!-- <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script> -->

  <script>
    function linkpage(pg){
        window.location.replace("./index.php?page="+pg);
    }

    // From a notification: preselect that task (monitor_pm.php restores the
    // selection from these localStorage keys), then go to the maintenance page.
    function gotoPmTask(deviceSN, createdAt){
        localStorage.setItem('pmSelectedTask', deviceSN + '|' + createdAt);
        localStorage.setItem('pmDetailMobileOpen', '1'); // open the detail popup on mobile too
        linkpage('maintenance');
    }

    // Close the mobile sidebar (show-sidebar on #main-wrapper) when clicking/tapping
    // outside of it. The togglers are excluded so their own toggle handler in
    // app.min.js doesn't fire together with this and reopen/close twice.
    document.addEventListener('click', function(e){
        var wrapper = document.getElementById('main-wrapper');
        if(!wrapper || !wrapper.classList.contains('show-sidebar')) return;
        if(e.target.closest('.left-sidebar')) return;
        if(e.target.closest('.sidebartoggler')) return;
        wrapper.classList.remove('show-sidebar');
    });

    // Close any open header dropdown (notification bell, user profile) when
    // clicking outside of it. Uses the Bootstrap API when available so its
    // internal state stays in sync; falls back to removing .show directly.
    document.addEventListener('click', function(e){
        if(e.target.closest('.nav-item.dropdown')) return;
        document.querySelectorAll('.app-header .dropdown-menu.show').forEach(function(menu){
            var toggle = menu.closest('.nav-item.dropdown')
                ? menu.closest('.nav-item.dropdown').querySelector('[data-bs-toggle="dropdown"]')
                : null;
            if(toggle && window.bootstrap && bootstrap.Dropdown){
                var dd = bootstrap.Dropdown.getInstance(toggle);
                if(dd){ dd.hide(); return; }
            }
            menu.classList.remove('show');
            if(toggle){
                toggle.classList.remove('show');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    });

    // linkpage() above does a full page reload, so the sidebar's open/active state set by
    // sidebarmenu.js during the previous page view is gone. Restore it here based on the page
    // the server actually rendered: if it's a submenu item, re-trigger a real click on its
    // parent has-arrow toggle so the exact same handler in sidebarmenu.js opens it (and closes
    // any other open group, since that handler already wipes every other "active"/"in" first).
    //
    // This can't use 'DOMContentLoaded': by the time this script (near the end of body) runs,
    // that event has typically already fired, so the listener would never trigger. sidebarmenu.js
    // above hits the same situation — jQuery's $(fn) falls back to window.setTimeout(fn) when the
    // document is already ready, which is what actually attaches the click handlers we rely on
    // here. A same-delay setTimeout(fn, 0) right after it was NOT reliably ordered after it in
    // testing, so this uses a small explicit delay to guarantee it runs strictly later.
    setTimeout(function(){
        var currentPage = "<?php echo htmlspecialchars($pg, ENT_QUOTES); ?>";
        var currentPg   = "<?php echo htmlspecialchars(isset($_GET['pg']) ? $_GET['pg'] : '', ENT_QUOTES); ?>";
        if(!currentPage) return;

        // Find the active leaf link. Several links can share the same data-page
        // (e.g. Device and Model both live under page=device); disambiguate with data-pg.
        var candidates = document.querySelectorAll('#sidebarnav a[data-page="' + currentPage + '"]');
        if(!candidates.length) return;

        var activeLink = null;
        if(candidates.length === 1){
            activeLink = candidates[0];
        } else {
            // The Model link (data-pg="model") owns pg=model AND pg=modellist; every
            // other pg (meter/sensor/cmlall/empty) belongs to the Device link.
            var wantPg = currentPg || 'meter';
            var isModelPg = (wantPg === 'model' || wantPg === 'modellist');
            candidates.forEach(function(a){
                var dp = a.getAttribute('data-pg');
                var linkIsModel = (dp === 'model' || dp === 'modellist');
                if(!activeLink && (isModelPg === linkIsModel)) activeLink = a;
            });
            if(!activeLink) activeLink = candidates[0];
        }
        if(!activeLink) return;

        // Walk up every enclosing submenu so nested groups (Settings > Manage Devices >
        // Device/Model) all open, then highlight the leaf. Manipulate classes directly
        // instead of .click() so we don't fight sidebarmenu.js's one-level-only handler.
        var ul = activeLink.closest('ul.first-level');
        while(ul){
            ul.classList.add('in');
            var li = ul.parentElement;
            var toggleLink = ul.previousElementSibling;
            if(toggleLink && toggleLink.classList.contains('has-arrow')) toggleLink.classList.add('active');
            if(li) li.classList.add('selected');
            ul = li ? li.closest('ul.first-level') : null;
        }
        activeLink.classList.add('active');
    }, 50);

    function systemControl(operation){
        const reboot=operation==='reboot_system';
        Swal.fire({
            icon:'warning',
            title:reboot?'Reboot เครื่อง M1?':'Restart โปรแกรมควบคุม?',
            text:reboot?'หน้าเว็บและการควบคุมจะหยุดชั่วคราวระหว่างเปิดเครื่องใหม่':'บริการ py_multi จะหยุดและเริ่มใหม่ ใช้เวลาประมาณไม่กี่วินาที',
            showCancelButton:true,
            confirmButtonColor:reboot?'#c0392b':'#1769d2',
            confirmButtonText:reboot?'Reboot':'Restart',
            cancelButtonText:'ยกเลิก',
            showLoaderOnConfirm:true,
            preConfirm:()=>fetch('./mx_fn_api.php?action=write_cmd',{
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                body:new URLSearchParams({operation:operation})
            }).then(async response=>{
                const data=await response.json().catch(()=>({error:'Invalid server response'}));
                if(!response.ok||!data.success)throw new Error(data.error||('HTTP '+response.status));
                return data;
            }).catch(error=>{Swal.showValidationMessage(error.message);return false}),
            allowOutsideClick:()=>!Swal.isLoading()
        }).then(result=>{
            if(!result.isConfirmed||!result.value)return;
            Swal.fire({icon:'success',title:reboot?'กำลัง Reboot เครื่อง':'Restart สำเร็จ',text:result.value.message||'',showConfirmButton:!reboot,timer:reboot?3500:undefined});
        });
    }

    function cancelChangePassword(){
        document.getElementById('changePasswordForm').reset();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('changePasswordModal')).hide();
    }

    $('#changePasswordForm').on('submit', function(e){
        e.preventDefault();

        const currentPassword = $('#current_password').val();
        const newPassword = $('#new_password_self').val();
        const newPasswordConfirm = $('#new_password_self_confirm').val();

        if(newPassword !== newPasswordConfirm){
            Swal.fire({ icon: "error", title: "New passwords do not match" });
            return;
        }

        $.ajax({
            type: 'POST',
            url: './mx_fn_api.php?action=change_password',
            data: { current_password: currentPassword, new_password: newPassword },
            success: function(response){
                bootstrap.Modal.getOrCreateInstance(document.getElementById('changePasswordModal')).hide();
                document.getElementById('changePasswordForm').reset();
                Swal.fire({ icon: "success", title: "Password changed", showConfirmButton: false, timer: 1200 });
            },
            error: function(xhr){
                Swal.fire({ icon: "error", title: "Failed", text: xhr.responseText || "Could not change password" });
            }
        });
    });

    // Keep the user's place when this page is refreshed (including reloads
    // triggered after saving a device). Each URL gets its own position.
    (function preservePageFocusOnRefresh(){
        if(!window.sessionStorage) return;
        const storageKey = 'mdb-page-position:' + location.pathname + location.search;
        const navigation = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
        const isReload = navigation ? navigation.type === 'reload' : performance.navigation && performance.navigation.type === 1;
        let lastFocusedId = '';
        let saveTimer = 0;
        const scrollContainerSelector = '.table-scroll, .card-body.hide-scrollbar';

        if('scrollRestoration' in history) history.scrollRestoration = 'manual';

        function positionSnapshot(){
            const active = document.activeElement;
            if(active && active.id) lastFocusedId = active.id;
            const containers = [];
            document.querySelectorAll(scrollContainerSelector).forEach(function(element, index){
                containers.push({index:index, top:element.scrollTop || 0, left:element.scrollLeft || 0});
            });
            return {
                x: window.scrollX || 0,
                y: window.scrollY || document.documentElement.scrollTop || 0,
                focusId: lastFocusedId,
                containers: containers
            };
        }

        function writePosition(){
            sessionStorage.setItem(storageKey, JSON.stringify(positionSnapshot()));
        }

        function savePosition(){
            clearTimeout(saveTimer);
            saveTimer = setTimeout(writePosition, 50);
        }

        window.addEventListener('scroll', savePosition, {passive:true});
        // The Device tables scroll inside .table-scroll instead of the window.
        document.addEventListener('scroll', function(event){
            if(event.target && event.target.matches && event.target.matches(scrollContainerSelector)) savePosition();
        }, {capture:true, passive:true});
        document.addEventListener('focusin', function(event){
            if(event.target && event.target.id) lastFocusedId = event.target.id;
            savePosition();
        });
        window.addEventListener('pagehide', function(){
            clearTimeout(saveTimer);
            writePosition();
        });

        if(!isReload) return;
        let saved = null;
        try { saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null'); } catch(error) {}
        if(!saved) return;

        window.addEventListener('load', function(){
            requestAnimationFrame(function(){
                window.scrollTo(Number(saved.x) || 0, Number(saved.y) || 0);
                if(Array.isArray(saved.containers)){
                    const currentContainers = document.querySelectorAll(scrollContainerSelector);
                    saved.containers.forEach(function(position){
                        const container = currentContainers[position.index];
                        if(container){
                            container.scrollTop = Number(position.top) || 0;
                            container.scrollLeft = Number(position.left) || 0;
                        }
                    });
                }
                if(saved.focusId){
                    const target = document.getElementById(saved.focusId);
                    if(target && typeof target.focus === 'function'){
                        try { target.focus({preventScroll:true}); } catch(error) { target.focus(); }
                    }
                }
            });
        }, {once:true});
    })();
<?php } else { include("./authentication-login.php"); }?>


</script>
<script>
// Desktop sidebar rail. State is kept across navigation and icon labels remain accessible as tooltips.
(function(){
  var KEY='mdb_sidebar_rail',w=document.getElementById('main-wrapper'),btn=document.getElementById('gdRailBtn'),tip;
  if(!w||!btn)return;
  function desktop(){return window.innerWidth>=1200}function hideTip(){if(tip)tip.style.display='none'}
  function setRail(on,save){w.classList.toggle('gd-rail',on);btn.setAttribute('aria-pressed',on?'true':'false');btn.setAttribute('aria-label',on?'ขยายเมนู':'พับเมนู');hideTip();if(save)try{localStorage.setItem(KEY,on?'1':'0')}catch(e){}}
  var saved=false;try{saved=localStorage.getItem(KEY)==='1'}catch(e){}setRail(saved,false);
  btn.addEventListener('click',function(){setRail(!w.classList.contains('gd-rail'),true)});
  document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('.left-sidebar .sidebar-link.has-arrow');if(a&&w.classList.contains('gd-rail')&&desktop())setRail(false,true)},true);
  var side=document.querySelector('.left-sidebar');if(side){
    side.addEventListener('mouseover',function(e){if(!desktop()||!w.classList.contains('gd-rail'))return;var a=e.target.closest('.sidebar-link'),m=a&&a.querySelector('.hide-menu');if(!m)return;if(!tip){tip=document.createElement('div');tip.id='rail-tip';tip.setAttribute('role','tooltip');document.body.appendChild(tip)}tip.textContent=m.textContent.replace(/\s+/g,' ').trim();tip.style.display='block';var r=a.getBoundingClientRect();tip.style.left=(r.right+12)+'px';tip.style.top=(r.top+r.height/2-tip.offsetHeight/2)+'px'});
    side.addEventListener('mouseout',function(e){if(!e.relatedTarget||!e.relatedTarget.closest||!e.relatedTarget.closest('.sidebar-link'))hideTip()});side.addEventListener('scroll',hideTip,true);
  }window.addEventListener('resize',hideTip);
})();
// Light/dark mode shared by every page rendered inside the main wrapper.
(function(){
  var KEY='mdb_gmode',box=document.getElementById('gdModePick'),w=document.getElementById('main-wrapper');if(!box)return;var buttons=box.querySelectorAll('button');
  function current(){return document.documentElement.getAttribute('data-gmode')==='dark'?'dark':'light'}
  function mark(){var mode=current();buttons.forEach(function(b){var on=b.dataset.m===mode,next=mode==='dark'?'สว่าง':'มืด';b.classList.toggle('is-on',on);b.setAttribute('aria-pressed',on?'true':'false');b.title=on?'โหมด'+(mode==='dark'?'มืด':'สว่าง')+' — เมื่อพับเมนู กดเพื่อเปลี่ยนเป็นโหมด'+next:'เปลี่ยนเป็นโหมด'+(b.dataset.m==='dark'?'มืด':'สว่าง')})}
  function apply(mode){var h=document.documentElement;if(mode==='dark'){h.setAttribute('data-gmode','dark');h.setAttribute('data-bs-theme','dark')}else{h.removeAttribute('data-gmode');h.setAttribute('data-bs-theme','light')}try{localStorage.setItem(KEY,mode)}catch(e){}mark();window.dispatchEvent(new Event('gmodechange'))}
  box.addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;var rail=w&&w.classList.contains('gd-rail')&&window.innerWidth>=1200;apply(rail?(current()==='dark'?'light':'dark'):b.dataset.m)});mark();
})();
</script>
</body>
<?php if(!empty($_SESSION['username'])): ?><script src="./assets/js/session-watch.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/session-watch.js'); ?>"></script><?php endif; ?>
</html>
