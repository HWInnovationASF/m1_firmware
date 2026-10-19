<?php
require_once __DIR__ . '/session_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function rgl_api_reply(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rgl_api_reply(405, ['success' => false, 'error' => 'Method not allowed']);
}

$userType = strtolower((string)($_SESSION['user_type'] ?? ''));
if (empty($_SESSION['username']) || !in_array($userType, ['admin', 'superadmin'], true)) {
    rgl_api_reply(403, ['success' => false, 'error' => 'ไม่มีสิทธิ์ส่งไฟล์ rgl.json']);
}

$csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$expectedCsrf = (string)($_SESSION['rgl_api_csrf'] ?? '');
if ($csrf === '' || $expectedCsrf === '' || !hash_equals($expectedCsrf, $csrf)) {
    rgl_api_reply(403, ['success' => false, 'error' => 'คำขอหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่']);
}

if (!function_exists('curl_init')) {
    rgl_api_reply(500, ['success' => false, 'error' => 'PHP cURL extension ยังไม่ได้ติดตั้ง']);
}

$source = __DIR__ . '/config/rgl.json';
$configPath = __DIR__ . '/data/mcf_default.json';
$statePath = __DIR__ . '/data/rgl_api_state.json';
$apiUrl = 'https://api.mdbiot.com/ftp/dconfig/vx_api.php';

$sourceText = @file_get_contents($source);
if ($sourceText === false || !is_array(json_decode($sourceText, true))) {
    rgl_api_reply(500, ['success' => false, 'error' => 'ไม่พบ rgl.json หรือ JSON ไม่สมบูรณ์']);
}

$config = json_decode((string)@file_get_contents($configPath), true);
if (!is_array($config)) {
    rgl_api_reply(500, ['success' => false, 'error' => 'อ่านการตั้งค่าระบบไม่สำเร็จ']);
}

$deviceSn = trim((string)($config['deviceSN'] ?? ''));
$state = json_decode((string)@file_get_contents($statePath), true);
$expectedRevision = is_array($state) ? max(0, (int)($state['revision'] ?? 0)) : 0;

$localHash = hash('sha256', $sourceText);
$postFields = [
    'action' => 'publish_rgl',
    'device_sn' => $deviceSn,
    'sha256' => $localHash,
    'expected_revision' => $expectedRevision,
    'modified_at' => gmdate('c', (int)filemtime($source)),
    'rgl_file' => new CURLFile($source, 'application/json', 'rgl.json')
];

$curl = curl_init($apiUrl);
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Expect:']
]);
$responseText = curl_exec($curl);
$curlError = curl_error($curl);
$httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($responseText === false) {
    rgl_api_reply(502, ['success' => false, 'error' => 'เชื่อมต่อ API ไม่สำเร็จ: ' . $curlError]);
}
$response = json_decode($responseText, true);
if (!is_array($response)) {
    rgl_api_reply(502, ['success' => false, 'error' => 'API ตอบกลับไม่ใช่ JSON', 'http_code' => $httpCode]);
}
if ($httpCode === 409 || (($response['status'] ?? '') === 'conflict')) {
    rgl_api_reply(409, [
        'success' => false,
        'status' => 'conflict',
        'error' => 'ไฟล์กลางถูกอัปเดตจากเครื่องอื่นแล้ว (Revision ' . (int)($response['current_revision'] ?? 0) . ') จึงยกเลิกเพื่อป้องกันการเขียนทับ',
        'current_revision' => (int)($response['current_revision'] ?? 0),
        'current_sha256' => (string)($response['current_sha256'] ?? '')
    ]);
}
if ($httpCode < 200 || $httpCode >= 300 || empty($response['success'])) {
    rgl_api_reply(502, ['success' => false, 'error' => (string)($response['error'] ?? ('API HTTP ' . $httpCode)), 'http_code' => $httpCode]);
}

$newState = [
    'revision' => max(0, (int)($response['revision'] ?? $expectedRevision)),
    'sha256' => (string)($response['sha256'] ?? $localHash),
    'status' => (string)($response['status'] ?? 'updated'),
    'synced_at' => date('c')
];
$encodedState = json_encode($newState, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$temporaryState = $statePath . '.tmp.' . getmypid();
if ($encodedState === false || file_put_contents($temporaryState, $encodedState, LOCK_EX) === false || !rename($temporaryState, $statePath)) {
    @unlink($temporaryState);
    rgl_api_reply(500, ['success' => false, 'error' => 'API รับไฟล์แล้ว แต่บันทึก revision ในเครื่องไม่สำเร็จ']);
}
@chmod($statePath, 0664);

rgl_api_reply(200, [
    'success' => true,
    'status' => (string)($response['status'] ?? 'updated'),
    'revision' => (int)($response['revision'] ?? $expectedRevision),
    'destination' => (string)($response['destination'] ?? '/ftp/dconfig/config/rgl.json'),
    'sha256' => (string)($response['sha256'] ?? $localHash),
    'bytes' => (int)($response['bytes'] ?? filesize($source)),
    'message' => (string)($response['message'] ?? '')
]);
