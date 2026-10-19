<?php
require_once __DIR__ . '/session_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (empty($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$config = json_decode((string)@file_get_contents(__DIR__ . '/config/automation_control.json'), true);
$enabled = is_array($config) && !empty($config['enabled']);
$serviceOutput = [];
$serviceCode = 1;
@exec('/bin/systemctl is-active py_multi.service 2>/dev/null', $serviceOutput, $serviceCode);
$serviceActive = $serviceCode === 0 && trim(implode('', $serviceOutput)) === 'active';
echo json_encode([
    'enabled' => $enabled,
    'service_active' => $serviceActive,
    'active' => $enabled && $serviceActive,
    'timestamp' => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
