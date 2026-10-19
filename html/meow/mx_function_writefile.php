<?php
require_once __DIR__.'/session_bootstrap.php';
include('mx_function.php');
$userType=strtolower((string)($_SESSION['user_type']??''));
if(empty($_SESSION['username'])||!in_array($userType,array('admin','superadmin'),true)){http_response_code(403);echo 'Admin or Superadmin permission required';exit;}
// print_r( $_GET['dataE']);
$data_arr = isset($_GET['dataE']) ? json_decode($_GET['dataE'],true):array();
if(!is_array($data_arr)){http_response_code(400);echo 'Invalid data';exit;}
// echo json_decode($data_arr,true);
// echo str_replace("\\","",json_encode(json_decode($data_arr,true)));
// die;
$file_path = "./data/mcf_default.json";
if (file_put_contents($file_path, json_encode($data_arr,JSON_UNESCAPED_SLASHES),LOCK_EX)!==false) {
    echo "Data successfully saved to $file_path";
    chmod($file_path,0777);
}
?>
