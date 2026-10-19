<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/session_guard.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!empty($_SESSION['username'])) {
    $result = ['s'=>'ok'];
    if (!empty($_SESSION['mdb_note'])) { $result['note'] = $_SESSION['mdb_note']; unset($_SESSION['mdb_note']); }
    session_write_close();
    echo json_encode($result, JSON_UNESCAPED_UNICODE); exit;
}
session_write_close();
$info = mdb_take_kick_notice();
echo json_encode($info ? ['s'=>'kicked','info'=>$info] : ['s'=>'out'], JSON_UNESCAPED_UNICODE);
