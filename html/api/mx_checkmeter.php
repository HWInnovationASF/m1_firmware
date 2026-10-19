<?php
// echo file_get_contents("/home/pi/Desktop/python/log_action/M117181619197999/M117181619197999_log5_202502131405.txt");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Expose-Headers: Content-Length, X-JSON");
header("Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: *");
date_default_timezone_set("Asia/Bangkok");
shell_exec("python3 /home/mdbcare/python/zread.py");

$data_all = file_get_contents('/home/mdbcare/python/zztest.json');
// $data_all = file_get_contents('/var/www/html/ftp/zztest.json');
$data_all = json_decode($data_all,true);

echo json_encode($data_all,256);



die;
$rootpath = "/home/mdbcare";
// {"devcieSN":"M117210144775061","mx_count":5,"hb_count":60,"wss":"wss://smart.asefa.co.th:5501","ftp_host":"172.16.0.65","ftp_port":2002,"ftp_u":"meowftp","ftp_p":"Asefa@2o23"}
$deviceSN_f = isset($data_all['devcieSN']) ? $data_all['devcieSN']: "";
// echo $deviceSN_f;

$dir = "{$rootpath}/python/dlog/{$deviceSN_f}/";
// $dir = "{$rootpath}/python/log_action/{$deviceSN_f}/";
$a = scandir($dir); //search file in list

// Sort in descending order
// $a = scandir($dir,1);
// echo $a;

// print_r($a);
$date_log5 = date("YmdHi",floor(date("U") / 300) * 300 );
$name_file = $deviceSN_f."_log5_".$date_log5.".txt";
// echo $name_file;
// die;

$data_txt = "";
// foreach($a as $k=>$v){
//     if($v != "." && $v != ".."){
//         if(strpos($v,".txt") &&  strpos($v,"_log5_")){
//             // echo $k."==".$v."<br>\n";
//             $data_txt = file_get_contents("{$rootpath}/python/dlog/{$deviceSN_f}/{$v}");
//         }
//     }
// }
// /ftp/dlog/M117181619197799
$data_txt = file_get_contents("{$rootpath}/python/dlog/{$deviceSN_f}/{$name_file}");
$last_data = (explode("\n",$data_txt));
$data_res = "";
$data_res_arr = array();
$data_res_arr['device_SN'] = $deviceSN_f;

// echo $data_txt;
// die;
$m_arr = array();
$m_arr2 = array();
$m_fail_arr = array();
$ts = 0;
foreach($last_data as $k=>$v){
    if($v != ""){
        $ts = explode("|",$v)[0];
        $data_res = (explode("|",$v))[1];
        $data_res_arr = json_decode($data_res,true);
        // echo count($data_res_arr)."\r\n";
        // echo json_encode($data_res_arr,256);
        // die;
        // echo "\n=================<br/>";
        // echo json_encode($data_res_arr['data']);
        // echo count($data_res_arr['data']);
        foreach($data_res_arr['data'] as $meterSN=>$val){
            // echo $meterSN;
            if(preg_match('/PM/',$meterSN)){
                $m_arr[$date_log5][$meterSN]['count'] = count($val);
                $m_arr[$date_log5][$meterSN]['p_t'] = $val['p_t'];
            }

        }
        // echo $data_res_arr['SI1'];
        // foreach($data_res_arr['data'] as $k2=$v2){
            // echo $k2;
        //     // $m_arr[$k] = $v['p_t'];
        // }
    }
}
// echo json_encode($m_arr,256);

// die;
$m_arr['count'] = count($m_arr[$date_log5]);
print_r($m_arr);
// echo json_encode($data_res_arr);
?>