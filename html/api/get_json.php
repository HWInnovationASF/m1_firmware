<?php
$data_get = file_get_contents('/var/www/html/data/rt_data.json');
$data_arr = json_decode($data_get,true);
$dateTime = $data_arr['DT'];

// echo json_encode()
echo $data_get;
?>