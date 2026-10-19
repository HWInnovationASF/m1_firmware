<?php
require_once __DIR__ . '/session_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['username']) || !in_array(strtolower($_SESSION['user_type'] ?? ''), ['admin', 'superadmin'], true)) {
    http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}
$enabled = filter_input(INPUT_POST, 'enabled', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($enabled === null) {
    http_response_code(400); echo json_encode(['error' => 'Invalid enabled value']); exit;
}
$filePath = __DIR__ . '/config/automation_control.json';
$config = json_decode((string)@file_get_contents($filePath), true);
if (!is_array($config)) {
    http_response_code(500); echo json_encode(['error' => 'Automation config is invalid']); exit;
}
$config['enabled'] = $enabled;
$config['updated_at'] = date('c');
$config['updated_by'] = (string)($_SESSION['username'] ?? '');
$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$tempPath = $filePath . '.tmp.' . getmypid();
if ($encoded === false || file_put_contents($tempPath, $encoded, LOCK_EX) === false || !rename($tempPath, $filePath)) {
    @unlink($tempPath); http_response_code(500); echo json_encode(['error' => 'Unable to save automation config']); exit;
}
@chmod($filePath, 0664);
echo json_encode(['success' => true, 'enabled' => $enabled], JSON_UNESCAPED_UNICODE);
