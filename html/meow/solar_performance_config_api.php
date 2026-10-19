<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
$path = __DIR__ . '/config/solar_performance.json';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $data = is_file($path) ? json_decode(file_get_contents($path), true) : [];
    echo json_encode(is_array($data) ? $data : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
if (!in_array(strtolower($_SESSION['user_type'] ?? ''), ['admin','superadmin'], true)) {
    http_response_code(403); echo json_encode(['error'=>'Permission denied']); exit;
}
$data = json_decode($_POST['dataE'] ?? '', true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['error'=>'Invalid data']); exit; }
$clean=[];
foreach($data as $id=>$rows){
    if(!preg_match('/^[A-Za-z0-9_-]+$/',(string)$id)||!is_array($rows))continue;
    $used=[];
    foreach($rows as $row){$parameters=$row['parameters']??[$row['parameter']??''];if(!is_array($parameters))$parameters=[$parameters];$valid=[];foreach($parameters as $parameter){$p=(string)$parameter;if(!preg_match('/^in_dca_\d+$/i',$p)||isset($used[$p]))continue;$used[$p]=1;$valid[]=$p;}if(!$valid)continue;$clean[$id][]=['channel'=>mb_substr(trim((string)($row['channel']??'')),0,60),'parameters'=>$valid];}
}
$output=$clean?:new stdClass();$json=json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$tmp=$path.'.tmp.'.getmypid();
if($json===false||file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$path)){@unlink($tmp);http_response_code(500);echo json_encode(['error'=>'Save failed']);exit;}
echo json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
