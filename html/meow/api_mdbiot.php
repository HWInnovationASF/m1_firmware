<?php
// echo file_get_contents("/home/pi/Desktop/python/log_action/M117181619197999/M117181619197999_log5_202502131405.txt");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Expose-Headers: Content-Length, X-JSON");
header("Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: *");
date_default_timezone_set("Asia/Bangkok");

$data_all = file_get_contents('./data/mcf_default.json');
// $data_all = file_get_contents('../data/mcf_default.json');
$data_all = json_decode($data_all,true);
$rootpath = "/home/mdbcare";
// {"devcieSN":"M117210144775061","mx_count":5,"hb_count":60,"wss":"wss://smart.asefa.co.th:5501","ftp_host":"172.16.0.65","ftp_port":2002,"ftp_u":"meowftp","ftp_p":"Asefa@2o23"}
$deviceSN_f = isset($data_all['devcieSN']) ? $data_all['devcieSN']: "";
// echo $deviceSN_f;

$dir = "{$rootpath}/python/log_action/{$deviceSN_f}/";
$a = scandir($dir); //search file in list

// Sort in descending order
// $a = scandir($dir,1);
// echo $dir;

$data_txt = "";
foreach($a as $k=>$v){
    if($v != "." && $v != ".."){
        if(strpos($v,".txt") &&  strpos($v,"_log5_")){
            // echo $k."==".$v."<br>\n";
            $data_txt .= file_get_contents("{$rootpath}/python/log_action/{$deviceSN_f}/{$v}");
        }
    }
}
$last_data = (explode("\n",$data_txt));
$data_res = "";
$data_res_arr = array();
$data_res_arr['device_SN'] = $deviceSN_f;

// print_r($data_txt);
// die;

$ts = 0;
foreach($last_data as $k=>$v){
    // $data_res = (explode("|",$v))[1];
    if($v != ""){
        // echo $k."==".explode("|",$v)[1]."\n";
        $ts = explode("|",$v)[0];
        $data_res = explode("|",$v)[1];
        if(json_decode($data_res,true)['method'] == "res"){
            // echo json_decode($data_res,true)['method'];
            $data_res_dec = json_decode($data_res,true)['data'];

            foreach($data_res_dec['data'] as $k_measure=>$v_measure){
                if(isset($data_res_dec['data'][$k_measure])){
                    $data_res_arr['data'][$data_res_dec['meter_SN']][$k_measure] = $data_res_dec['data'][$k_measure];
                    $data_res_arr['data'][$data_res_dec['meter_SN']]['dt'] = $ts;
                    // $data_res_arr['dt_dev'][$data_res_dec['meter_SN']] = $ts;
                }
            }
            // if(isset($data_res_dec['data']['v_an'])) $data_res_arr['data'][$data_res_dec['meter_SN']]['v_an'] = $data_res_dec['data']['v_an'];
            // if(isset($data_res_dec['data']['i_n'])) $data_res_arr['data'][$data_res_dec['meter_SN']]['i_n'] = $data_res_dec['data']['i_n'];
            // if(isset($data_res_dec['data']['p_t'])) $data_res_arr['data'][$data_res_dec['meter_SN']]['p_t'] = $data_res_dec['data']['p_t'];
            // if(isset($data_res_dec['data']['e_p'])) $data_res_arr['data'][$data_res_dec['meter_SN']]['e_p'] = $data_res_dec['data']['e_p'];

            // if(isset($data_res_dec['data']['RL1'])) $data_res_arr['data'][$data_res_dec['meter_SN']]['RL1'] = $data_res_dec['data']['RL1'];
            // echo $ts."==".json_encode($data_res_dec['data'])."==".$data_res."<br>";
        }
        if(json_decode($data_res,true)['method'] == "rec"){
            // echo json_decode($data_res,true)['method'];

            // 1739529372|{"method": "rec", "data": {"data": {"PM000004": {"p_t": "6.700", "e_p": "552229.750"}, "PM000011": {"p_t": "6.600", "e_p": "98781.742"}, "PM000006": {"p_t": "8.025", "e_p": "605116.250"}, "PM000007": {"p_t": "6.383", "e_p": "186841.031"}, "PM000005": {"p_t": "9.781", "e_p": "4396.040"}, "PM000008": {"p_t": "18.749", "e_p": "7709.215"}, "PM001091": {"p_t": "0.000", "e_p": "75094.977"}, "PM002092": {"p_t": "0.000", "e_p": "37540.977"}}}}


            $data_res_dec = json_decode($data_res,true)['data'];
            // foreach($data_res_dec as $k_meter=>$v_meter){
            //     // echo $k_measure;
            //     if(isset($data_res_dec[$k_meter])){
            //         foreach($data_res_dec[$k_meter] as $k_measure=>$v_measure){

            //             // echo json_encode($data_res_dec[$k_meter]);
            //             $data_res_arr['data'][$data_res_dec[$k_meter]][$k_measure] = $data_res_dec[$k_meter][$k_measure];
            //             // $data_res_arr['data'][]
            //         }
            //     }
            // }
            // echo json_encode($data_res_dec) ;

            foreach($data_res_dec as $k=>$v){
                foreach($data_res_dec[$k] as $k_measure=>$v_measure){
                    if(isset($data_res_dec[$k][$k_measure])){
                        // echo json_encode($data_res_dec[$k][$k_measure]);
                        $data_res_arr['data'][$k][$k_measure] = $data_res_dec[$k][$k_measure];
                        $data_res_arr['data'][$data_res_dec['meter_SN']]['dt'] = $ts;

                    }
                }
                // if(isset($data_res_dec[$k]['v_an'])) $data_res_arr['data'][$k]['v_an'] = $data_res_dec[$k]['v_an'];
                // if(isset($data_res_dec[$k]['i_n'])) $data_res_arr['data'][$k]['i_n'] = $data_res_dec[$k]['i_n'];
                // if(isset($data_res_dec[$k]['p_t'])) $data_res_arr['data'][$k]['p_t'] = $data_res_dec[$k]['p_t'];
                // if(isset($data_res_dec[$k]['e_p'])) $data_res_arr['data'][$k]['e_p'] = $data_res_dec[$k]['e_p'];
                // if(isset($data_res_dec[$k]['RL1'])) $data_res_arr['data'][$k]['RL1'] = $data_res_dec[$k]['RL1'];

            }
            // if(isset($data_res_dec['data']['p_t'])) $data_res_arr['data'][$data_res_dec['meter_SN']]['p_t'] = $data_res_dec['data']['p_t'];
            // if(isset($data_res_dec['data']['e_p'])) $data_res_arr['data'][$data_res_dec['meter_SN']]['e_p'] = $data_res_dec['data']['e_p'];
            // echo $ts."==".json_encode($data_res_dec['data'])."==".$data_res."<br>";
        }
        $data_res_arr['ts'] = $ts;
        $data_res_arr['dt'] = date("Y-m-d H:i:s",$ts);
        
    }
}

echo json_encode($data_res_arr);
?>