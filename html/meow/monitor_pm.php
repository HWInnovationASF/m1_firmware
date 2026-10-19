<?php
require_once __DIR__ . '/session_bootstrap.php';
date_default_timezone_set("Asia/Bangkok");
$pm_username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$pm_usertype = isset($_SESSION['user_type']) ? strtolower($_SESSION['user_type']) : '';
$pm_is_admin = ($pm_usertype == 'admin' || $pm_usertype == 'superadmin');

$data_all = file_get_contents('./data/mcf_default.json');
$data_all = json_decode($data_all,true);
$deviceSN_f = isset($data_all['deviceSN']) ? $data_all['deviceSN']: "";

$pm_tasks = array();
foreach (glob('./log_device/task_*_pm.txt') as $pm_file) {
    foreach (file($pm_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $pm_line) {
        $pm_row = json_decode($pm_line, true);
        if (is_array($pm_row)) $pm_tasks[] = $pm_row;
    }
}
usort($pm_tasks, function($a, $b){
    return strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? '');
});
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>PM Task Monitor</title>
<link rel="stylesheet" href="./assets/jquery-datetimepicker/2.5.20/jquery.datetimepicker.min.css">
<style>
    * { box-sizing: border-box; }
    .body {
        margin: 0;
        font-family: Arial, Helvetica, sans-serif;
        background: #fff;
        padding:12px;
        border-radius:8px;
    }
    .pm-topbar {
        display: flex;
        justify-content: flex-end;
        padding: 15px 15px 0 15px;
    }
    .btn-create-task {
        background: #1e5fbf;
        color: #fff;
        border: none;
        border-radius: 4px;
        padding: 10px 18px;
        font-weight: bold;
        font-size: 13px;
        letter-spacing: .5px;
        cursor: pointer;
    }
    .btn-create-task:hover {
        background: #17509e;
    }
    .pm-wrapper {
        display: flex;
        padding: 15px;
        gap: 15px;
        height: calc(80vh - 70px);
    }
    .pm-list-panel {
        width: 380px;
        min-width: 380px;
        border: 1px solid #ccc;
        border-radius: 4px;
        display: flex;
        flex-direction: column;
    }
    .pm-filter {
        position: relative;
        border-bottom: 1px solid #ccc;
    }
    .pm-filter select {
        width: 100%;
        border: none;
        padding: 12px 10px;
        font-size: 13px;
        font-weight: bold;
        letter-spacing: .5px;
        appearance: none;
        -webkit-appearance: none;
        background: #fff;
        cursor: pointer;
    }
    .pm-filter::after {
        content: "\25BE";
        position: absolute;
        right: 12px;
        top: 12px;
        pointer-events: none;
        color: #333;
    }
    .pm-list-scroll {
        flex: 1;
        overflow-y: auto;
        position: relative;
    }
    .pm-scroll-btn {
        display: flex;
        justify-content: center;
        padding: 4px 0;
        color: #999;
        font-size: 12px;
        cursor: pointer;
    }
    .pm-task-item {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 12px 12px;
        border-bottom: 1px solid #eee;
        cursor: pointer;
    }
    .pm-task-item:hover,
    .pm-task-item.active {
        background: #f5f8ff;
    }
    .pm-task-icon {
        width: 32px;
        height: 32px;
        flex-shrink: 0;
        color: #6c757d;
    }
    .pm-task-icon.pm-icon-blue {
        color: #1e88e5;
    }
    .pm-task-icon.pm-icon-red {
        color: #e53935;
    }
    .pm-task-icon.pm-icon-green {
        color: #43a047;
    }
    .pm-task-body {
        flex: 1;
        min-width: 0;
    }
    .pm-task-title {
        font-weight: bold;
        font-size: 13px;
        margin-bottom: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .pm-task-creator {
        font-size: 12px;
        color: #333;
    }
    .pm-task-creator b { font-weight: bold; }
    .pm-task-time {
        text-align: right;
        font-size: 12px;
        color: #333;
        white-space: nowrap;
    }
    .pm-detail-panel {
        flex: 1;
        border: 1px solid #ccc;
        border-radius: 4px;
        overflow-y: auto;
    }
    .pm-detail-empty {
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .pm-no-task {
        color: #ccc;
        font-size: 32px;
        font-weight: bold;
        font-family: Georgia, 'Times New Roman', serif;
    }
    .pm-detail-content {
        padding: 20px;
    }
    .pm-detail-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-bottom: 14px;
        border-bottom: 1px solid #eee;
        margin-bottom: 16px;
    }
    .pm-detail-header-title {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #1e5fbf;
        font-weight: bold;
        font-size: 16px;
    }
    .pm-detail-header-title svg {
        width: 26px;
        height: 26px;
        color: #6c757d;
        flex-shrink: 0;
    }
    .pm-detail-actions {
        display: flex;
        gap: 18px;
    }
    .pm-detail-actions button {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 12px;
        font-weight: bold;
        letter-spacing: .5px;
        color: #333;
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .pm-detail-actions .pm-delete-btn {
        color: #c0392b;
    }
    .pm-detail-actions svg {
        width: 14px;
        height: 14px;
    }
    .pm-detail-desc {
        padding-bottom: 16px;
        border-bottom: 1px solid #eee;
        margin-bottom: 16px;
        font-size: 14px;
        color: #333;
    }
    .pm-detail-meta-label {
        color: #1e5fbf;
        font-weight: bold;
        font-size: 13px;
        margin-bottom: 4px;
    }
    .pm-detail-meta-row {
        display: flex;
        justify-content: space-between;
        font-size: 13px;
        color: #333;
        margin-bottom: 12px;
    }
    .pm-complete-btn {
        background: #1e8e3e;
        color: #fff;
        border: none;
        border-radius: 4px;
        padding: 6px 14px;
        font-weight: bold;
        font-size: 12px;
        cursor: pointer;
    }
    .pm-complete-btn:hover {
        background: #166b2e;
    }
    .pm-inprogress-btn {
        background: #1e88e5; /* same blue as pending status icon */
        color: #fff;
        border: none;
        border-radius: 4px;
        padding: 6px 14px;
        font-weight: bold;
        font-size: 12px;
        cursor: not-allowed;
        opacity: .85;
    }
    .pm-detail-images {
        display: flex;
        gap: 16px;
        flex-wrap: nowrap;              /* single horizontal row */
        overflow-x: auto;               /* slide sideways when it gets long */
        padding-bottom: 8px;
        scroll-snap-type: x proximity;  /* gently snap items into place */
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;                    /* Firefox */
        scrollbar-color: #c3cad6 transparent;
    }
    .pm-detail-images > * {
        flex-shrink: 0;                 /* keep every item at full size */
        scroll-snap-align: start;
    }
    /* slim rounded scrollbar (Chrome/Edge/Safari) */
    .pm-detail-images::-webkit-scrollbar {
        height: 8px;
    }
    .pm-detail-images::-webkit-scrollbar-track {
        background: #f0f2f5;
        border-radius: 4px;
    }
    .pm-detail-images::-webkit-scrollbar-thumb {
        background: #c3cad6;
        border-radius: 4px;
    }
    .pm-detail-images::-webkit-scrollbar-thumb:hover {
        background: #1e5fbf;
    }
    .pm-detail-images img {
        width: 150px;
        height: 150px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid #eee;
        transition: transform .15s;
    }
    .pm-detail-images a:hover img {
        transform: scale(1.03);
    }
    .pm-file-drop {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 18px 12px;
        border: 2px dashed #b9c4d4;
        border-radius: 6px;
        background: #f8fafd;
        color: #7a8699;
        font-size: 12px;
        cursor: pointer;
        text-align: center;
        transition: border-color .15s, background .15s;
    }
    .pm-file-drop:hover,
    .pm-file-drop.dragging {
        border-color: #1e5fbf;
        background: #f0f5fc;
        color: #1e5fbf;
    }
    .pm-attach-placeholder {
        width: 150px;
        height: 150px;
        border: 2px dashed #d0d4d8;
        border-radius: 6px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 6px;
        color: #c4c8cc;
        font-size: 12px;
        background: #fafbfc;
    }
    .pm-attach-file {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #f4f6f8;
        border: 1px solid #e0e4e8;
        border-radius: 4px;
        padding: 8px 12px;
        font-size: 13px;
        color: #1e5fbf;
        text-decoration: none;
        max-width: 100%;
        word-break: break-all;
    }
    .pm-attach-file:hover {
        background: #e8eef5;
    }
    .pm-attach-list {
        display: flex;
        flex-direction: column;
        gap: 4px;
        margin-top: 6px;
        font-size: 12px;
    }
    .pm-attach-list .pm-attach-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        background: #f4f6f8;
        border-radius: 4px;
        padding: 4px 8px;
        word-break: break-all;
    }
    .pm-attach-list .pm-attach-remove {
        background: none;
        border: none;
        color: #c0392b;
        cursor: pointer;
        font-weight: bold;
        font-size: 14px;
        line-height: 1;
    }
    .pm-modal-header.pm-header-green {
        background: #1e8e3e;
    }
    .pm-btn-green {
        background: #1e8e3e !important;
    }
    .pm-btn-green:hover {
        background: #166b2e !important;
    }
    .pm-file-preview {
        display: flex;
        flex-wrap: nowrap;              /* single horizontal row */
        gap: 10px;
        margin-top: 8px;
        overflow-x: auto;               /* slide sideways when it gets long */
        padding: 8px 2px 8px 2px;       /* room for the x badge + scrollbar */
        scroll-snap-type: x proximity;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;                    /* Firefox */
        scrollbar-color: #c3cad6 transparent;
    }
    .pm-file-preview:empty {
        display: none;                  /* no blank strip when nothing is selected */
    }
    .pm-file-preview::-webkit-scrollbar {
        height: 6px;
    }
    .pm-file-preview::-webkit-scrollbar-track {
        background: #f0f2f5;
        border-radius: 3px;
    }
    .pm-file-preview::-webkit-scrollbar-thumb {
        background: #c3cad6;
        border-radius: 3px;
    }
    .pm-file-preview::-webkit-scrollbar-thumb:hover {
        background: #1e5fbf;
    }
    .pm-file-preview-item {
        position: relative;
        display: flex;
        align-items: center;
        flex-shrink: 0;                 /* keep items at full size */
        scroll-snap-align: start;
    }
    .pm-file-preview-item img {
        width: 70px;
        height: 70px;
        object-fit: cover;
        border-radius: 4px;
        border: 1px solid #e0e4e8;
    }
    .pm-file-preview-name {
        background: #f4f6f8;
        border: 1px solid #e0e4e8;
        border-radius: 4px;
        padding: 6px 20px 6px 8px;
        font-size: 12px;
        max-width: 160px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .pm-file-preview-remove {
        position: absolute;
        top: -6px;
        right: -6px;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        background: #c0392b;
        color: #fff;
        border: none;
        cursor: pointer;
        font-size: 12px;
        line-height: 1;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .pm-complete-note {
        background: #f4faf5;
        border: 1px solid #d4ecd9;
        border-radius: 4px;
        padding: 8px 12px;
        font-size: 13px;
        color: #333;
        margin-bottom: 12px;
        white-space: pre-wrap;
        word-break: break-word;
    }
    .pm-lightbox {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,.82);
        align-items: center;
        justify-content: center;
        padding: 24px;
        z-index: 1300;
        cursor: zoom-out;
    }
    .pm-lightbox.show {
        display: flex;
    }
    .pm-lightbox img {
        max-width: 92vw;
        max-height: 90vh;
        border-radius: 6px;
        box-shadow: 0 8px 32px rgba(0,0,0,.5);
    }
    .pm-lightbox-close {
        position: absolute;
        top: 12px;
        right: 20px;
        background: none;
        border: none;
        color: #fff;
        font-size: 36px;
        line-height: 1;
        cursor: pointer;
    }
    .pm-zoom-img {
        cursor: zoom-in;
    }
    .pm-modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,.45);
        align-items: center;
        justify-content: center;
        z-index: 1100;
    }
    .pm-modal-overlay.show {
        display: flex;
    }
    .pm-modal {
        background: #fff;
        width: 420px;
        max-width: 92vw;
        border-radius: 6px;
        box-shadow: 0 4px 20px rgba(0,0,0,.2);
    }
    .pm-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 18px;
        background: #1e5fbf;
        color: #fff;
        border-radius: 6px 6px 0 0;
    }
    .pm-modal-header h3 {
        margin: 0;
        font-size: 15px;
        letter-spacing: .5px;
    }
    .pm-modal-close {
        background: none;
        border: none;
        color: #fff;
        font-size: 20px;
        line-height: 1;
        cursor: pointer;
    }
    .pm-modal-body {
        padding: 18px;
    }
    .pm-field {
        margin-bottom: 14px;
    }
    .pm-field label {
        display: block;
        font-size: 12px;
        font-weight: bold;
        margin-bottom: 5px;
        color: #333;
    }
    .pm-field input,
    .pm-field textarea,
    .pm-field select {
        width: 100%;
        border: 1px solid #ccc;
        border-radius: 4px;
        padding: 8px 10px;
        font-size: 13px;
        font-family: inherit;
    }
    .pm-field textarea {
        resize: vertical;
        min-height: 70px;
    }
    .pm-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 12px 18px;
        border-top: 1px solid #eee;
    }
    .btn-cancel {
        background: #eee;
        color: #333;
        border: none;
        border-radius: 4px;
        padding: 9px 16px;
        font-weight: bold;
        font-size: 13px;
        cursor: pointer;
    }
    .btn-save {
        background: #1e5fbf;
        color: #fff;
        border: none;
        border-radius: 4px;
        padding: 9px 16px;
        font-weight: bold;
        font-size: 13px;
        cursor: pointer;
    }
    .btn-save:hover {
        background: #17509e;
    }
    .xdsoft_ok_wrap {
        display: flex;
        justify-content: flex-end;
        padding: 6px 8px;
        border-top: 1px solid #e6e6e6;
        background: #fff;
        clear: both;
    }
    .xdsoft_ok_button {
        background: #1e5fbf;
        color: #fff;
        border: none;
        border-radius: 4px;
        padding: 6px 18px;
        font-weight: bold;
        font-size: 12px;
        cursor: pointer;
    }
    .xdsoft_ok_button:hover {
        background: #17509e;
    }

    .pm-detail-back-btn {
        display: none;
        background: none;
        border: none;
        cursor: pointer;
        font-size: 13px;
        font-weight: bold;
        color: #1e5fbf;
        align-items: center;
        gap: 6px;
        padding: 0;
        margin-bottom: 14px;
    }

    @media (max-width: 768px) {
        .pm-wrapper {
            flex-direction: column;
            height: auto;
        }
        .pm-list-panel {
            width: 100%;
            min-width: 0;
            max-height: none;
        }
        .pm-detail-panel {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1001;
            border: none;
            border-radius: 0;
            overflow-y: auto;
            background: #fff;
        }
        .pm-detail-panel.show-mobile {
            display: block;
        }
        .pm-detail-back-btn {
            display: inline-flex;
        }
        .pm-detail-header {
            flex-wrap: wrap;
            gap: 10px;
        }
        .pm-detail-meta-row {
            flex-wrap: wrap;
            gap: 4px;
        }
        .pm-modal {
            width: 100%;
        }
    }
</style>
</head>
<body>

<div class="body">
    <div class="pm-topbar">
        <button type="button" class="btn-create-task" id="btnCreateTask">CREATE NEW TASK</button>
    </div>

    <div class="pm-wrapper">

        <div class="pm-list-panel">
            <div class="pm-filter">
                <select id="mdbFilter">
                    <option value=""><?php echo htmlspecialchars($deviceSN_f != '' ? $deviceSN_f : 'ALL MDB'); ?></option>
                </select>
            </div>

            <div class="pm-list-scroll">
                <!-- <div class="pm-scroll-btn">&#9650;</div> -->

                <?php if (empty($pm_tasks)): ?>
                    <div style="padding:20px;text-align:center;color:#999;font-size:13px;">No Task</div>
                <?php else: ?>
                    <?php foreach ($pm_tasks as $pm_i => $pm_t):
                        $pm_dt = DateTime::createFromFormat('Y-m-d H:i:s', $pm_t['createdAt'] ?? '');
                        $pm_time = $pm_dt ? $pm_dt->format('H:i') : '';
                        $pm_date = $pm_dt ? $pm_dt->format('d/m/y') : '';
                        $pm_heading = ($pm_t['deviceSN'] ?? '') . ' : ' . ($pm_t['title'] ?? '');

                        if (($pm_t['task_status'] ?? '') === 'completed') {
                            $pm_icon_class = 'pm-icon-green';
                        } else {
                            $pm_schedule_ts = !empty($pm_t['schedule']) ? strtotime($pm_t['schedule']) : false;
                            if ($pm_schedule_ts !== false) {
                                $pm_alert_ts = $pm_schedule_ts - (intval($pm_t['alertBefore'] ?? 0) * 60);
                                $pm_icon_class = (time() >= $pm_alert_ts) ? 'pm-icon-red' : 'pm-icon-blue';
                            } else {
                                $pm_icon_class = 'pm-icon-blue';
                            }
                        }
                    ?>
                    <div class="pm-task-item<?php echo $pm_i == 0 ? ' active' : ''; ?>"
                        data-title="<?php echo htmlspecialchars($pm_heading); ?>"
                        data-tasktitle="<?php echo htmlspecialchars($pm_t['title'] ?? ''); ?>"
                        data-description="<?php echo htmlspecialchars($pm_t['description'] ?? ''); ?>"
                        data-devicesn="<?php echo htmlspecialchars($pm_t['deviceSN'] ?? ''); ?>"
                        data-creator="<?php echo htmlspecialchars($pm_t['createdBy'] ?? ''); ?>"
                        data-schedule="<?php echo htmlspecialchars($pm_t['schedule'] ?? ''); ?>"
                        data-alertbefore="<?php echo htmlspecialchars($pm_t['alertBefore'] ?? ''); ?>"
                        data-createdat="<?php echo htmlspecialchars($pm_t['createdAt'] ?? ''); ?>"
                        data-taskstatus="<?php echo htmlspecialchars($pm_t['task_status'] ?? 'pending'); ?>"
                        data-completedby="<?php echo htmlspecialchars($pm_t['completedBy'] ?? ''); ?>"
                        data-completedat="<?php echo htmlspecialchars($pm_t['completedAt'] ?? ''); ?>"
                        data-attachments="<?php echo htmlspecialchars(json_encode($pm_t['attachments'] ?? array(), 256)); ?>"
                        data-completedesc="<?php echo htmlspecialchars($pm_t['complete_description'] ?? ''); ?>"
                        data-completeattachments="<?php echo htmlspecialchars(json_encode($pm_t['complete_attachments'] ?? array(), 256)); ?>"
                        data-time="<?php echo htmlspecialchars($pm_time); ?>"
                        data-date="<?php echo htmlspecialchars($pm_date); ?>">
                        <svg class="pm-task-icon <?php echo $pm_icon_class; ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <rect x="6" y="3" width="12" height="18" rx="1.5"/>
                            <rect x="9" y="2" width="6" height="3" rx="1"/>
                            <line x1="9" y1="9" x2="15" y2="9"/>
                            <line x1="9" y1="13" x2="15" y2="13"/>
                        </svg>
                        <div class="pm-task-body">
                            <div class="pm-task-title"><?php echo htmlspecialchars($pm_heading); ?></div>
                            <div class="pm-task-creator"><b>Created by</b> : <?php echo htmlspecialchars($pm_t['createdBy'] ?? ''); ?></div>
                        </div>
                        <div class="pm-task-time">
                            <div><?php echo htmlspecialchars($pm_time); ?></div>
                            <div><?php echo htmlspecialchars($pm_date); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- <div class="pm-scroll-btn">&#9660;</div> -->
            </div>
        </div>

        <div class="pm-detail-panel" id="pmDetailPanel">
            <div class="pm-detail-empty"><div class="pm-no-task">No Task Selected</div></div>
        </div>

    </div>
</div>

<div class="pm-modal-overlay" id="pmModalOverlay">
    <div class="pm-modal">
        <div class="pm-modal-header">
            <h3 id="pmModalTitle">CREATE NEW TASK</h3>
            <button type="button" class="pm-modal-close" id="pmModalClose">&times;</button>
        </div>
        <div class="pm-modal-body">
            <form id="pmTaskForm">
                <div class="pm-field">
                    <label for="taskTitle">Title</label>
                    <input type="text" id="taskTitle" name="taskTitle" placeholder="Task title">
                </div>
                <!-- Hidden: auto-filled from session -->
                <div class="pm-field" style="display:none;">
                    <label for="taskCreatedBy">Created by</label>
                    <input type="text" id="taskCreatedBy" name="taskCreatedBy" readonly value="<?php echo htmlspecialchars($pm_username); ?>">
                </div>
                <div class="pm-field">
                    <label for="taskDescription">Description</label>
                    <textarea id="taskDescription" name="taskDescription" placeholder="Task description"></textarea>
                </div>
                <!-- Hidden: auto-selected from device config -->
                <div class="pm-field" style="display:none;">
                    <label for="taskDeviceSN">Device SN</label>
                    <select id="taskDeviceSN" name="taskDeviceSN">
                        <option value="">Select device</option>
                        <?php if ($deviceSN_f != ''): ?>
                            <option value="<?php echo htmlspecialchars($deviceSN_f); ?>" selected><?php echo htmlspecialchars($deviceSN_f); ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="pm-field">
                    <label for="taskSchedule">Schedule Date/Time</label>
                    <input type="text" id="taskSchedule" name="taskSchedule" autocomplete="off" placeholder="YYYY-MM-DD HH:mm">
                </div>
                <div class="pm-field">
                    <label for="taskAlertBefore">Alert Before</label>
                    <select id="taskAlertBefore" name="taskAlertBefore">
                        <option value="0">None</option>
                        <option value="5">5 minutes</option>
                        <option value="15">15 minutes</option>
                        <option value="30">30 minutes</option>
                        <option value="45">45 minutes</option>
                        <option value="60">1 hour</option>
                        <option value="240">4 hour</option>
                        <option value="360">6 hour</option>
                        <option value="720">12 hour</option>
                        <option value="1440">24 hour</option>
                        <option value="10080">1 week</option>
                        <option value="43200">1 month</option>
                    </select>
                </div>
                <!-- Hidden when creating (always "pending"); shown only when editing -->
                <div class="pm-field" id="taskStatusField" style="display:none;">
                    <label for="taskStatus">Task Status</label>
                    <select id="taskStatus" name="taskStatus">
                        <option value="pending">Pending</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
                <div class="pm-field">
                    <label for="taskFiles">Attachments (images / files)</label>
                    <input type="file" id="taskFiles" name="taskFiles[]" multiple style="display:none;"
                        accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip">
                    <div class="pm-file-drop" id="taskFilesDrop">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                            <rect x="3" y="5" width="18" height="14" rx="2"/>
                            <circle cx="8.5" cy="10" r="1.5"/>
                            <path d="M21 15l-5-5-9 9"/>
                        </svg>
                        <span>คลิกเพื่อเลือกภาพ / ไฟล์แนบ</span>
                    </div>
                    <div id="taskFilesPreview" class="pm-file-preview"></div>
                    <div id="taskFilesList" class="pm-file-preview"></div>
                </div>
            </form>
        </div>
        <div class="pm-modal-footer">
            <button type="button" class="btn-cancel" id="pmModalCancel">Cancel</button>
            <button type="button" class="btn-save" id="pmModalSave">Save</button>
        </div>
    </div>
</div>

<div class="pm-lightbox" id="pmLightbox">
    <button type="button" class="pm-lightbox-close">&times;</button>
    <img id="pmLightboxImg" src="" alt="">
</div>

<div class="pm-modal-overlay" id="pmCompleteOverlay">
    <div class="pm-modal">
        <div class="pm-modal-header pm-header-green">
            <h3>CONFIRM COMPLETE</h3>
            <button type="button" class="pm-modal-close" id="pmCompleteClose">&times;</button>
        </div>
        <div class="pm-modal-body">
            <div class="pm-field">
                <label for="completeDescription">Description</label>
                <textarea id="completeDescription" placeholder="What was done?"></textarea>
            </div>
            <div class="pm-field">
                <label for="completeFiles">Attachments (images / files)</label>
                <input type="file" id="completeFiles" multiple style="display:none;"
                    accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip">
                <div class="pm-file-drop" id="completeFilesDrop">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                        <rect x="3" y="5" width="18" height="14" rx="2"/>
                        <circle cx="8.5" cy="10" r="1.5"/>
                        <path d="M21 15l-5-5-9 9"/>
                    </svg>
                    <span>คลิกเพื่อเลือกภาพ / ไฟล์แนบ</span>
                </div>
                <div id="completeFilesPreview" class="pm-file-preview"></div>
                <div id="completeFilesList" class="pm-file-preview"></div>
            </div>
        </div>
        <div class="pm-modal-footer">
            <button type="button" class="btn-cancel" id="pmCompleteCancel">Cancel</button>
            <button type="button" class="btn-save pm-btn-green" id="pmCompleteConfirm">&#10003; Confirm Complete</button>
        </div>
    </div>
</div>

<script src="./assets/js/jquery.min.js"></script>
<script src="./assets/jquery-datetimepicker/2.5.20/jquery.datetimepicker.full.min.js"></script>
<script>
    // Load SweetAlert2 only if index.php hasn't already loaded it
    if (typeof Swal === 'undefined') {
        document.write('<script src="./assets/sweetalert/sweetalert2.all.min.js"><\/script>');
    }
</script>
<script>
    // Capture this page's own jQuery instance (with datetimepicker attached) in a
    // closure. index.php loads jQuery again further down for Bootstrap, which
    // resets window.$/window.jQuery and would otherwise break the picker.
    (function(pj){
        pj.datetimepicker.setLocale('en');

        function pad2(n){ return n < 10 ? '0' + n : '' + n; }
        function fmtYmdHi(d){
            return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
                + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
        }

        pj('#taskSchedule').datetimepicker({
            format: 'Y-m-d H:i',
            step: 5,
            closeOnDateSelect: false,
            onGenerate: function(){
                if(!pj(this).find('.xdsoft_ok_button').length){
                    pj('<div class="xdsoft_ok_wrap"><button type="button" class="xdsoft_ok_button">OK</button></div>')
                        .appendTo(this)
                        .find('.xdsoft_ok_button')
                        .on('mousedown click', function(e){
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            var $input = pj('#taskSchedule');
                            var selected = $input.datetimepicker('getValue');
                            if(selected){
                                $input.val(fmtYmdHi(selected));
                            }
                            $input.datetimepicker('hide');
                        });
                }
            }
        });
    })(jQuery);

    var pmDetailPanel = document.getElementById('pmDetailPanel');
    var pmCurrentUsername = <?php echo json_encode($pm_username); ?>;
    var pmIsAdmin = <?php echo $pm_is_admin ? 'true' : 'false'; ?>;

    function pmText(str){
        var span = document.createElement('span');
        span.textContent = str || '';
        return span.innerHTML;
    }

    // ----- SweetAlert2 helpers -----
    function pmSwalError(title, msg){
        Swal.fire({
            icon: 'error',
            title: title,
            text: msg || '',
            confirmButtonColor: '#1e5fbf'
        });
    }
    function pmSwalWarn(title, msg){
        Swal.fire({
            icon: 'warning',
            title: title,
            text: msg || '',
            confirmButtonColor: '#1e5fbf'
        });
    }
    // Brief success toast, then run next (e.g. reload)
    function pmSwalSuccess(title, next){
        Swal.fire({
            icon: 'success',
            title: title,
            timer: 1100,
            showConfirmButton: false
        }).then(function(){ if(next) next(); });
    }

    function renderTaskDetail(item){
        var d = item.dataset;
        var when = pmText((d.date || '') + ' ' + (d.time || ''));
        var canEdit = pmIsAdmin;
        var actionsHtml =
            (canEdit ? '<button type="button" class="pm-edit-btn">&#9998; EDIT</button>' : '') +
            (pmIsAdmin ? '<button type="button" class="pm-delete-btn">&#128465; DELETE</button>' : '');

        var isCompleted = d.taskstatus === 'completed';
        // Task is "due" once now >= schedule - alertBefore (same rule as the red list icon)
        var isDue = false;
        if (d.schedule) {
            var pmSchedTs = new Date(d.schedule.replace(' ', 'T')).getTime();
            if (!isNaN(pmSchedTs)) {
                isDue = Date.now() >= pmSchedTs - (parseInt(d.alertbefore || '0', 10) * 60000);
            }
        }
        var completeByHtml = isCompleted
            ? '<div class="pm-detail-meta-row"><span>' + pmText(d.completedby) + '</span><span>' + pmText(d.completedat) + '</span></div>'
            : isDue && pmIsAdmin
                ? '<div class="pm-detail-meta-row"><button type="button" class="pm-complete-btn">&#10003; CONFIRM COMPLETE</button></div>'
                : '<div class="pm-detail-meta-row"><button type="button" class="pm-inprogress-btn" disabled>&#8987; IN PROGRESS</button></div>';

        // Placeholder frame shown while a section has no image/file yet
        var pmAttachPlaceholder =
            '<div class="pm-attach-placeholder">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="36" height="36">' +
                    '<rect x="3" y="5" width="18" height="14" rx="2"/>' +
                    '<circle cx="8.5" cy="10" r="1.5"/>' +
                    '<path d="M21 15l-5-5-9 9"/>' +
                '</svg>' +
                '<span>No image</span>' +
            '</div>';

        // Create-side attachments (from CREATE/EDIT form): images as thumbnails, other files as download links
        var attachHtml = pmBuildAttachHtml(d.attachments);

        // Complete-side info (entered via the CONFIRM COMPLETE popup) — shown right after the Complete By row
        var completeExtraHtml = '';
        if (isCompleted && d.completedesc) {
            completeExtraHtml += '<div class="pm-complete-note">' + pmText(d.completedesc) + '</div>';
        }
        var completeAttachHtml = isCompleted ? pmBuildAttachHtml(d.completeattachments) : '';
        completeExtraHtml += '<div class="pm-detail-images" style="margin-bottom:12px;">' +
            (completeAttachHtml || pmAttachPlaceholder) + '</div>';

        pmDetailPanel.innerHTML =
            '<div class="pm-detail-content">' +
                '<button type="button" class="pm-detail-back-btn" id="pmDetailBack">&#8592; Back to list</button>' +
                '<div class="pm-detail-header">' +
                    '<div class="pm-detail-header-title">' +
                        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">' +
                            '<rect x="6" y="3" width="12" height="18" rx="1.5"/>' +
                            '<rect x="9" y="2" width="6" height="3" rx="1"/>' +
                            '<line x1="9" y1="9" x2="15" y2="9"/>' +
                            '<line x1="9" y1="13" x2="15" y2="13"/>' +
                        '</svg>' +
                        '<span>Task: ' + pmText(d.tasktitle) + '</span>' +
                    '</div>' +
                    '<div class="pm-detail-actions">' + actionsHtml + '</div>' +
                '</div>' +
                '<div class="pm-detail-desc">' + pmText(d.description) + '</div>' +
                '<div class="pm-detail-meta-label">Create By</div>' +
                '<div class="pm-detail-meta-row"><span>' + pmText(d.creator) + '</span><span>' + when + '</span></div>' +
                '<div class="pm-detail-images" style="margin-bottom:12px;">' + (attachHtml || pmAttachPlaceholder) + '</div>' +
                '<div class="pm-detail-meta-label">Complete By</div>' +
                completeByHtml +
                completeExtraHtml +
            '</div>';
    }

    // Build attachment HTML from a JSON dataset string ([{name, path}, ...])
    function pmBuildAttachHtml(jsonStr){
        var atts = [];
        try { atts = JSON.parse(jsonStr || '[]'); } catch(e){}
        if (!Array.isArray(atts)) atts = [];
        var html = '';
        atts.forEach(function(a){
            if (!a || typeof a.path !== 'string' || a.path.indexOf('./log_device/pm_files/') !== 0) return;
            var safePath = encodeURI(a.path);
            var isImg = /\.(jpe?g|png|gif|webp|bmp)$/i.test(a.path);
            if (isImg) {
                // Click opens the enlarged image in a lightbox popup (see pmOpenLightbox)
                html += '<a href="' + safePath + '" class="pm-zoom-img" data-src="' + safePath + '" title="' + pmText(a.name) + '">' +
                    '<img src="' + safePath + '" alt="' + pmText(a.name) + '"></a>';
            } else {
                html += '<a class="pm-attach-file" href="' + safePath + '" target="_blank" download="' + pmText(a.name) + '">' +
                    '&#128206; ' + pmText(a.name) + '</a>';
            }
        });
        return html;
    }

    // ----- Image lightbox -----
    var pmLightbox = document.getElementById('pmLightbox');
    var pmLightboxImg = document.getElementById('pmLightboxImg');

    function pmOpenLightbox(src){
        pmLightboxImg.src = src;
        pmLightbox.classList.add('show');
    }
    function pmCloseLightbox(){
        pmLightbox.classList.remove('show');
        pmLightboxImg.src = '';
    }
    pmLightbox.addEventListener('click', pmCloseLightbox);
    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape' && pmLightbox.classList.contains('show')) pmCloseLightbox();
    });

    var pmCurrentItem = null;
    var PM_SELECTED_KEY = 'pmSelectedTask';
    var PM_DETAIL_OPEN_KEY = 'pmDetailMobileOpen';

    document.querySelectorAll('.pm-task-item').forEach(function(item){
        item.addEventListener('click', function(){
            document.querySelectorAll('.pm-task-item').forEach(function(i){ i.classList.remove('active'); });
            item.classList.add('active');
            pmCurrentItem = item;
            // Remember the selection so it survives a page refresh
            localStorage.setItem(PM_SELECTED_KEY, (item.dataset.devicesn || '') + '|' + (item.dataset.createdat || ''));
            renderTaskDetail(item);
            // On mobile this is a full-screen popup (see .pm-detail-panel.show-mobile in CSS);
            // on desktop the class has no visual effect since the panel is already inline.
            pmDetailPanel.classList.add('show-mobile');
            localStorage.setItem(PM_DETAIL_OPEN_KEY, '1');
        });
    });

    // Restore the last-selected task after a refresh; fall back to the
    // server-rendered default (first item) if it no longer exists.
    var pmSavedKey = localStorage.getItem(PM_SELECTED_KEY);
    if(pmSavedKey){
        document.querySelectorAll('.pm-task-item').forEach(function(item){
            var key = (item.dataset.devicesn || '') + '|' + (item.dataset.createdat || '');
            if(key === pmSavedKey){
                document.querySelectorAll('.pm-task-item').forEach(function(i){ i.classList.remove('active'); });
                item.classList.add('active');
            }
        });
    }

    var pmInitialActive = document.querySelector('.pm-task-item.active');
    if(pmInitialActive){
        pmCurrentItem = pmInitialActive;
        renderTaskDetail(pmInitialActive);
        // Make sure the restored item is visible in the scrolling list
        pmInitialActive.scrollIntoView({ block: 'nearest' });
        // Restore the mobile detail popup if it was open before the refresh
        if(localStorage.getItem(PM_DETAIL_OPEN_KEY) === '1'){
            pmDetailPanel.classList.add('show-mobile');
        }
    }

    var pmModalOverlay = document.getElementById('pmModalOverlay');
    var pmTaskForm = document.getElementById('pmTaskForm');
    var pmModalTitle = document.getElementById('pmModalTitle');
    var PM_DRAFT_KEY = 'pmTaskDraft';
    var pmEditContext = null;

    function savePmDraft(){
        if(pmEditContext) return; // don't let an in-progress edit pollute the "create" draft
        var draft = {
            taskTitle: document.getElementById('taskTitle').value,
            taskDescription: document.getElementById('taskDescription').value,
            taskDeviceSN: document.getElementById('taskDeviceSN').value,
            taskSchedule: document.getElementById('taskSchedule').value,
            taskAlertBefore: document.getElementById('taskAlertBefore').value,
            taskStatus: document.getElementById('taskStatus').value
        };
        sessionStorage.setItem(PM_DRAFT_KEY, JSON.stringify(draft));
    }

    function restorePmDraft(){
        var raw = sessionStorage.getItem(PM_DRAFT_KEY);
        if(!raw) return;
        try {
            var draft = JSON.parse(raw);
            Object.keys(draft).forEach(function(id){
                var el = document.getElementById(id);
                if(el) el.value = draft[id];
            });
        } catch(e){}
    }

    function clearPmDraft(){
        sessionStorage.removeItem(PM_DRAFT_KEY);
    }

    pmTaskForm.addEventListener('input', savePmDraft);
    pmTaskForm.addEventListener('change', savePmDraft);

    // Attachments kept from the task being edited (each: {name, path}).
    // Newly picked files live in the #taskFiles input until Save uploads them.
    var pmExistingAttachments = [];

    // Render an existing-attachment array ({name, path}) into a container:
    // image thumbnails / file-name chips, each with a red x that removes it.
    function pmRenderExistingList(list, containerId, rerender){
        var listEl = document.getElementById(containerId);
        listEl.innerHTML = '';
        list.forEach(function(a, idx){
            var item = document.createElement('div');
            item.className = 'pm-file-preview-item';
            var isImg = typeof a.path === 'string' && /\.(jpe?g|png|gif|webp|bmp)$/i.test(a.path);
            if(isImg){
                // Existing image attachment: show its thumbnail (same look as new-file previews)
                var img = document.createElement('img');
                img.src = a.path;
                img.title = a.name || '';
                img.style.cursor = 'zoom-in';
                img.addEventListener('click', function(){ pmOpenLightbox(img.src); });
                item.appendChild(img);
            }else{
                var nameSpan = document.createElement('span');
                nameSpan.className = 'pm-file-preview-name';
                nameSpan.textContent = a.name || a.path;
                nameSpan.title = a.name || '';
                item.appendChild(nameSpan);
            }
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'pm-file-preview-remove';
            removeBtn.innerHTML = '&times;';
            removeBtn.title = 'Remove attachment';
            removeBtn.addEventListener('click', function(){
                list.splice(idx, 1);
                rerender();
            });
            item.appendChild(removeBtn);
            listEl.appendChild(item);
        });
    }

    function renderAttachList(){
        pmRenderExistingList(pmExistingAttachments, 'taskFilesList', renderAttachList);
    }

    function openPmModal(){
        pmEditContext = null;
        pmModalTitle.textContent = 'CREATE NEW TASK';
        pmExistingAttachments = [];
        document.getElementById('taskFiles').value = '';
        renderTaskFilesPreview();
        renderAttachList();
        restorePmDraft();
        // Creating: status is always "pending" — hide the field
        document.getElementById('taskStatusField').style.display = 'none';
        document.getElementById('taskStatus').value = 'pending';
        pmModalOverlay.classList.add('show');
    }

    function openEditModal(item){
        var d = item.dataset;
        pmEditContext = {
            origDeviceSN: d.devicesn || '',
            origCreatedAt: d.createdat || ''
        };
        pmModalTitle.textContent = 'EDIT TASK';
        document.getElementById('taskTitle').value = d.tasktitle || '';
        document.getElementById('taskDescription').value = d.description || '';
        document.getElementById('taskDeviceSN').value = d.devicesn || '';
        document.getElementById('taskSchedule').value = d.schedule || '';
        document.getElementById('taskAlertBefore').value = d.alertbefore || '0';
        document.getElementById('taskCreatedBy').value = d.creator || '';
        document.getElementById('taskStatus').value = d.taskstatus || 'pending';
        // Only admins may change the status when editing
        document.getElementById('taskStatusField').style.display = pmIsAdmin ? '' : 'none';
        try { pmExistingAttachments = JSON.parse(d.attachments || '[]'); } catch(e){ pmExistingAttachments = []; }
        if (!Array.isArray(pmExistingAttachments)) pmExistingAttachments = [];
        document.getElementById('taskFiles').value = '';
        renderTaskFilesPreview();
        renderAttachList();
        pmModalOverlay.classList.add('show');
    }

    function closePmModal(){
        pmModalOverlay.classList.remove('show');
        pmEditContext = null;
        pmModalTitle.textContent = 'CREATE NEW TASK';
        // Keep whatever the user typed so far (saved as a draft) in case they
        // closed the popup by accident — only cleared after a successful Save.
    }

    function clearTaskFormFields(){
        document.getElementById('taskTitle').value = '';
        document.getElementById('taskDescription').value = '';
        document.getElementById('taskDeviceSN').value = '';
        document.getElementById('taskSchedule').value = '';
        document.getElementById('taskAlertBefore').value = '0';
        document.getElementById('taskStatus').value = 'pending';
        document.getElementById('taskFiles').value = '';
        renderTaskFilesPreview();
        pmExistingAttachments = [];
        renderAttachList();
    }

    // ----- Selected-file previews (before upload) -----
    // Shows image thumbnails / file-name chips for files picked in a file input,
    // each with a red x to deselect it (rebuilds input.files via DataTransfer).
    function pmSetupFilePreview(inputId, previewId){
        var input = document.getElementById(inputId);
        var preview = document.getElementById(previewId);
        function render(){
            preview.querySelectorAll('img[data-objurl]').forEach(function(im){
                URL.revokeObjectURL(im.src);
            });
            preview.innerHTML = '';
            Array.prototype.forEach.call(input.files, function(f, idx){
                var item = document.createElement('div');
                item.className = 'pm-file-preview-item';
                if(/^image\//.test(f.type)){
                    var img = document.createElement('img');
                    img.src = URL.createObjectURL(f);
                    img.setAttribute('data-objurl', '1');
                    img.title = f.name;
                    img.style.cursor = 'zoom-in';
                    img.addEventListener('click', function(){ pmOpenLightbox(img.src); });
                    item.appendChild(img);
                }else{
                    var span = document.createElement('span');
                    span.className = 'pm-file-preview-name';
                    span.textContent = f.name;
                    span.title = f.name;
                    item.appendChild(span);
                }
                var rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'pm-file-preview-remove';
                rm.innerHTML = '&times;';
                rm.title = 'Remove ' + f.name;
                rm.addEventListener('click', function(){
                    var dt = new DataTransfer();
                    Array.prototype.forEach.call(input.files, function(f2, i2){
                        if(i2 !== idx) dt.items.add(f2);
                    });
                    input.files = dt.files;
                    render();
                });
                item.appendChild(rm);
                preview.appendChild(item);
            });
        }
        input.addEventListener('change', render);
        return render;
    }

    var renderTaskFilesPreview = pmSetupFilePreview('taskFiles', 'taskFilesPreview');
    var renderCompleteFilesPreview = pmSetupFilePreview('completeFiles', 'completeFilesPreview');

    // Clickable dashed frame that opens the (hidden) file input.
    // Also accepts files dropped onto the frame.
    function pmSetupFileDrop(dropId, inputId, rerender){
        var drop = document.getElementById(dropId);
        var input = document.getElementById(inputId);
        drop.addEventListener('click', function(){ input.click(); });
        drop.addEventListener('dragover', function(e){
            e.preventDefault();
            drop.classList.add('dragging');
        });
        drop.addEventListener('dragleave', function(){ drop.classList.remove('dragging'); });
        drop.addEventListener('drop', function(e){
            e.preventDefault();
            drop.classList.remove('dragging');
            if(!e.dataTransfer.files.length) return;
            var dt = new DataTransfer();
            Array.prototype.forEach.call(input.files, function(f){ dt.items.add(f); });
            Array.prototype.forEach.call(e.dataTransfer.files, function(f){ dt.items.add(f); });
            input.files = dt.files;
            rerender();
        });
    }

    pmSetupFileDrop('taskFilesDrop', 'taskFiles', renderTaskFilesPreview);
    pmSetupFileDrop('completeFilesDrop', 'completeFiles', renderCompleteFilesPreview);

    // ----- CONFIRM COMPLETE popup -----
    var pmCompleteOverlay = document.getElementById('pmCompleteOverlay');

    // Completion attachments already saved on the task (relevant when a task
    // was completed before, reopened, and is being completed again).
    var pmCompleteExistingAttachments = [];

    function renderCompleteAttachList(){
        pmRenderExistingList(pmCompleteExistingAttachments, 'completeFilesList', renderCompleteAttachList);
    }

    function openCompleteModal(){
        var d = pmCurrentItem ? pmCurrentItem.dataset : {};
        document.getElementById('completeDescription').value = d.completedesc || '';
        document.getElementById('completeFiles').value = '';
        renderCompleteFilesPreview();
        try { pmCompleteExistingAttachments = JSON.parse(d.completeattachments || '[]'); } catch(e){ pmCompleteExistingAttachments = []; }
        if (!Array.isArray(pmCompleteExistingAttachments)) pmCompleteExistingAttachments = [];
        renderCompleteAttachList();
        pmCompleteOverlay.classList.add('show');
    }

    function closeCompleteModal(){
        pmCompleteOverlay.classList.remove('show');
    }

    document.getElementById('pmCompleteClose').addEventListener('click', closeCompleteModal);
    document.getElementById('pmCompleteCancel').addEventListener('click', closeCompleteModal);
    pmCompleteOverlay.addEventListener('click', function(e){
        if(e.target === pmCompleteOverlay) closeCompleteModal();
    });

    document.getElementById('pmCompleteConfirm').addEventListener('click', function(){
        if(!pmCurrentItem) return;
        var confirmBtn = this;
        var d = pmCurrentItem.dataset;

        function submitComplete(attachments){
            fetch('./fn_api.php?action=pm_complete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    deviceSN: d.devicesn,
                    createdAt: d.createdat,
                    description: document.getElementById('completeDescription').value,
                    attachments: attachments
                })
            })
            .then(function(res){ return res.json(); })
            .then(function(res){
                if(res.status == 'success'){
                    pmSwalSuccess('บันทึกเสร็จงานแล้ว', function(){ location.reload(); });
                }else{
                    pmSwalError('ยืนยันไม่สำเร็จ', res.message || 'unknown error');
                    confirmBtn.disabled = false;
                }
            })
            .catch(function(err){
                console.error(err);
                pmSwalError('ยืนยันไม่สำเร็จ', 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                confirmBtn.disabled = false;
            });
        }

        confirmBtn.disabled = true;
        var fileInput = document.getElementById('completeFiles');
        if(fileInput.files.length > 0){
            var fd = new FormData();
            for(var fi = 0; fi < fileInput.files.length; fi++){
                fd.append('files[]', fileInput.files[fi]);
            }
            fetch('./upload_pm_file.php', { method: 'POST', body: fd })
            .then(function(res){ return res.json(); })
            .then(function(res){
                if(res.status != 'success'){
                    pmSwalError('อัปโหลดไฟล์ไม่สำเร็จ', (res.errors && res.errors.join(', ')) || res.message || 'unknown error');
                    confirmBtn.disabled = false;
                    return;
                }
                if(res.errors && res.errors.length){
                    pmSwalWarn('บางไฟล์ไม่ถูกอัปโหลด', res.errors.join(', '));
                }
                submitComplete(pmCompleteExistingAttachments.concat(res.files || []));
            })
            .catch(function(err){
                console.error(err);
                pmSwalError('อัปโหลดไฟล์ไม่สำเร็จ', 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                confirmBtn.disabled = false;
            });
        }else{
            submitComplete(pmCompleteExistingAttachments.slice());
        }
    });

    document.getElementById('btnCreateTask').addEventListener('click', openPmModal);
    if(!pmIsAdmin) document.getElementById('btnCreateTask').style.display='none';
    document.getElementById('pmModalClose').addEventListener('click', closePmModal);
    document.getElementById('pmModalCancel').addEventListener('click', function(){
        clearPmDraft();
        clearTaskFormFields();
        closePmModal();
    });
    pmModalOverlay.addEventListener('click', function(e){
        if(e.target === pmModalOverlay) closePmModal();
    });

    pmDetailPanel.addEventListener('click', function(e){
        // Image attachment: enlarge in a lightbox instead of opening a new tab
        var zoomLink = e.target.closest('.pm-zoom-img');
        if(zoomLink){
            e.preventDefault();
            pmOpenLightbox(zoomLink.getAttribute('data-src'));
            return;
        }

        var backBtn = e.target.closest('.pm-detail-back-btn');
        if(backBtn){
            pmDetailPanel.classList.remove('show-mobile');
            localStorage.removeItem(PM_DETAIL_OPEN_KEY);
            // Deselect the task in the list (and forget it for the next refresh)
            document.querySelectorAll('.pm-task-item').forEach(function(i){ i.classList.remove('active'); });
            pmCurrentItem = null;
            localStorage.removeItem(PM_SELECTED_KEY);
            pmDetailPanel.innerHTML = '<div class="pm-detail-empty"><div class="pm-no-task">No Task Selected</div></div>';
            return;
        }

        var editBtn = e.target.closest('.pm-edit-btn');
        if(editBtn && pmCurrentItem){
            openEditModal(pmCurrentItem);
        }

        var completeBtn = e.target.closest('.pm-complete-btn');
        if(completeBtn && pmCurrentItem){
            openCompleteModal();
        }

        var deleteBtn = e.target.closest('.pm-delete-btn');
        if(deleteBtn && pmCurrentItem){
            Swal.fire({
                icon: 'warning',
                title: 'ลบงานนี้?',
                text: 'ลบแล้วไม่สามารถกู้คืนได้',
                showCancelButton: true,
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#c0392b',
                cancelButtonColor: '#6c757d'
            }).then(function(result){
                if(!result.isConfirmed) return;
                var d = pmCurrentItem.dataset;
                deleteBtn.disabled = true;
                fetch('./fn_api.php?action=pm_delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ deviceSN: d.devicesn, createdAt: d.createdat })
                })
                .then(function(res){ return res.json(); })
                .then(function(res){
                    if(res.status == 'success'){
                        pmSwalSuccess('ลบงานแล้ว', function(){ location.reload(); });
                    }else{
                        pmSwalError('ลบไม่สำเร็จ', res.message || 'unknown error');
                        deleteBtn.disabled = false;
                    }
                })
                .catch(function(err){
                    console.error(err);
                    pmSwalError('ลบไม่สำเร็จ', 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                    deleteBtn.disabled = false;
                });
            });
        }
    });

    document.getElementById('pmModalSave').addEventListener('click', function(){
        var saveBtn = this;
        var task = {
            title: document.getElementById('taskTitle').value,
            description: document.getElementById('taskDescription').value,
            deviceSN: document.getElementById('taskDeviceSN').value,
            schedule: document.getElementById('taskSchedule').value,
            alertBefore: document.getElementById('taskAlertBefore').value,
            createdBy: document.getElementById('taskCreatedBy').value,
            task_status: document.getElementById('taskStatus').value
        };

        if(task.deviceSN == ''){
            pmSwalWarn('อุปกรณ์ยังไม่ได้ตั้งค่า', 'กรุณาตั้งค่า Device SN ของอุปกรณ์ก่อนสร้างงาน');
            return;
        }

        if(pmEditContext){
            task.origDeviceSN = pmEditContext.origDeviceSN;
            task.origCreatedAt = pmEditContext.origCreatedAt;
        }

        function submitTask(attachments){
            task.attachments = attachments;
            fetch('./fn_api.php?action=pm_add', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(task)
            })
            .then(function(res){ return res.json(); })
            .then(function(res){
                if(res.status == 'success'){
                    clearPmDraft();
                    clearTaskFormFields();
                    closePmModal();
                    pmSwalSuccess('บันทึกงานแล้ว', function(){ location.reload(); });
                }else{
                    pmSwalError('บันทึกไม่สำเร็จ', res.message || 'unknown error');
                    saveBtn.disabled = false;
                }
            })
            .catch(function(err){
                console.error(err);
                pmSwalError('บันทึกไม่สำเร็จ', 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                saveBtn.disabled = false;
            });
        }

        saveBtn.disabled = true;
        var fileInput = document.getElementById('taskFiles');
        if(fileInput.files.length > 0){
            // Upload new files first, then save the task with the full attachment list
            var fd = new FormData();
            for(var fi = 0; fi < fileInput.files.length; fi++){
                fd.append('files[]', fileInput.files[fi]);
            }
            fetch('./upload_pm_file.php', { method: 'POST', body: fd })
            .then(function(res){ return res.json(); })
            .then(function(res){
                if(res.status != 'success'){
                    pmSwalError('อัปโหลดไฟล์ไม่สำเร็จ', (res.errors && res.errors.join(', ')) || res.message || 'unknown error');
                    saveBtn.disabled = false;
                    return;
                }
                if(res.errors && res.errors.length){
                    pmSwalWarn('บางไฟล์ไม่ถูกอัปโหลด', res.errors.join(', '));
                }
                submitTask(pmExistingAttachments.concat(res.files || []));
            })
            .catch(function(err){
                console.error(err);
                pmSwalError('อัปโหลดไฟล์ไม่สำเร็จ', 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                saveBtn.disabled = false;
            });
        }else{
            submitTask(pmExistingAttachments);
        }
    });
</script>

</body>
</html>
