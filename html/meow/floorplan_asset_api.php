<?php
require_once __DIR__ . '/session_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$role = strtolower((string)($_SESSION['user_type'] ?? ''));
if (empty($_SESSION['username']) || !in_array($role, ['admin','superadmin'], true)) {
    http_response_code(403); echo json_encode(['status'=>'error','message'=>'Permission denied']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['background'])) {
    http_response_code(405); echo json_encode(['status'=>'error','message'=>'Upload required']); exit;
}
$file = $_FILES['background'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) < 1 || $file['size'] > 10 * 1024 * 1024) {
    http_response_code(422); echo json_encode(['status'=>'error','message'=>'ไฟล์ต้องมีขนาดไม่เกิน 10 MB']); exit;
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
$types = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
if (!isset($types[$mime])) {
    http_response_code(422); echo json_encode(['status'=>'error','message'=>'รองรับเฉพาะ PNG, JPG และ WEBP']); exit;
}
$dir = __DIR__ . '/assets/images/floorplans';
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    http_response_code(500); echo json_encode(['status'=>'error','message'=>'Cannot create floorplan directory']); exit;
}
$planKey = strtolower((string)($_POST['plan_key'] ?? 'floorplan'));
$planKey = preg_replace('/[^a-z0-9_-]/', '', $planKey);
if ($planKey === '') $planKey = 'floorplan';
$name = $planKey . '-custom-' . date('YmdHis') . '-' . bin2hex(random_bytes(2)) . '.' . $types[$mime];
if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
    http_response_code(500); echo json_encode(['status'=>'error','message'=>'Cannot save background']); exit;
}
@chmod($dir . '/' . $name, 0664);
echo json_encode(['status'=>'ok','url'=>'assets/images/floorplans/'.$name], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
