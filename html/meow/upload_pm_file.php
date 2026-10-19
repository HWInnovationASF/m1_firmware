<?php
    header('Content-Type: application/json');
    require_once __DIR__ . '/session_bootstrap.php';
    date_default_timezone_set("Asia/Bangkok");

    if (empty($_SESSION['username'])) {
        echo json_encode(array("status"=>"error","message"=>"Not logged in"));
        exit;
    }
    $user_type = isset($_SESSION['user_type']) ? strtolower((string)$_SESSION['user_type']) : '';
    if ($user_type !== 'admin' && $user_type !== 'superadmin') {
        http_response_code(403);
        echo json_encode(array("status"=>"error","message"=>"Admin or Superadmin permission required"));
        exit;
    }

    $target_dir = "./log_device/pm_files/";
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
        chmod($target_dir, 0777);
    }

    $allowed_ext = array('jpg','jpeg','png','gif','webp','bmp','pdf','doc','docx','xls','xlsx','csv','txt','zip');
    $max_size = 10 * 1024 * 1024; // 10MB per file

    if (!isset($_FILES['files'])) {
        echo json_encode(array("status"=>"error","message"=>"No files uploaded"));
        exit;
    }

    $saved = array();
    $errors = array();
    foreach ($_FILES['files']['name'] as $i => $name) {
        $orig_name = basename($name);
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = $orig_name.": upload error";
            continue;
        }
        if ($_FILES['files']['size'][$i] > $max_size) {
            $errors[] = $orig_name.": exceeds 10MB limit";
            continue;
        }
        $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext)) {
            $errors[] = $orig_name.": file type not allowed";
            continue;
        }
        $base = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($orig_name, PATHINFO_FILENAME));
        $stored_name = date("Ymd_His")."_".uniqid()."_".$base.".".$ext;
        if (move_uploaded_file($_FILES['files']['tmp_name'][$i], $target_dir.$stored_name)) {
            chmod($target_dir.$stored_name, 0666);
            $saved[] = array("name"=>$orig_name, "path"=>"./log_device/pm_files/".$stored_name);
        } else {
            $errors[] = $orig_name.": failed to save";
        }
    }

    echo json_encode(array(
        "status" => count($saved) > 0 || count($errors) == 0 ? "success" : "error",
        "files" => $saved,
        "errors" => $errors
    ), 256);
?>
