<?php 
    // require 'con_db.php';

    // $sql = "SELECT * FROM ".$tb." WHERE ".$cond;
    // $res = $conn->query($sql);
    // $res_arr = array();
    // while($row = $res->fetch_assoc()){
    //     // print_r($row);
    //     $res_arr[] = $row;
    // }
    // echo json_encode($res_arr);
    function write_file($src,$file_text,$mode_write="w"){	
        if($mode_write=="a"){
            file_put_contents($src, $file_text, FILE_APPEND);
        }else{
            file_put_contents($src, $file_text);
        }
        chmod($src, 0777);
    }

    // $cmdid = "";
    $microtime = microtime(true);
    $milliseconds = $microtime * 10000;
    // C172 846 136 827 87


    $meter_SN = isset($_GET['meter_SN']) ? $_GET['meter_SN'] : '';
    // $ws_daa = isset($_POST['WSData']) ? $_POST['WSData'] : '';
    $data_arr = array();
    $ws_daa = file_get_contents("php://input");
    $ws_arr = json_decode($ws_daa,true);
    // echo json_encode($ws_arr);
    $cmdid = isset($ws_arr['cmdid']) ? $ws_arr['cmdid'] : 'C'.$milliseconds;
    $fun = $ws_arr['fn'];
    // echo $cmdid;
    // ,"model":"pm5350","mode":"tcp","ipaddress":"172.16.21.111","port":"502","deviceID":"32"
    // unset($ws_arr["meter_SN"]);
    $data_arr[$cmdid][$fun][$meter_SN] = $ws_arr['data'];
    // echo json_encode($data_arr);
    // die;

    // $data_arr[$meter_SN] = arrray("name"=>$ws_arr['name']);
    // echo  json_encode($data_arr);
    // $cml_txt = file_get_contents('./config/cml.json');
    $cml_txt = file_get_contents('./config/cml.json');
    $cml_arr = $cml_txt != "" ? json_decode($cml_txt,true) : array();
    // if(array_key_exists($cmdid,$cml_arr)){
    //     $cml_arr[$cmdid] = $data_arr[$cmdid];
    // }

    $cml_arr[$cmdid] = $data_arr[$cmdid];


    // // unset($dvl_arr[$meter_SN]);
    // echo json_encode($dvl_arr,256);
    // echo json_encode($cml_arr);
    
    // die;
    // write_file('./config/cml.json',json_encode($cml_arr,256),"w");
    write_file('./config/cml.json',json_encode($cml_arr,256),"w");
?>