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

    $meter_SN = isset($_GET['meter_SN']) ? $_GET['meter_SN'] : '';
    // $ws_daa = isset($_POST['WSData']) ? $_POST['WSData'] : '';
    $data_arr = array();
    $ws_daa = file_get_contents("php://input");
    $ws_arr = json_decode($ws_daa,true);
    // ,"model":"pm5350","mode":"tcp","ipaddress":"172.16.21.111","port":"502","deviceID":"32"
    unset($ws_arr["meter_SN"]);
    $data_arr[$meter_SN] = $ws_arr;
    // $data_arr[$meter_SN] = arrray("name"=>$ws_arr['name']);
    // echo  json_encode($data_arr);
    // $dvl_txt = file_get_contents('./config/dvl.json');
    $dvl_txt = file_get_contents('./config/dvl.json');
    $dvl_arr = $dvl_txt != "" ? json_decode($dvl_txt,true) : array();
    $dvl_arr[$meter_SN] = $data_arr[$meter_SN];
    // // unset($dvl_arr[$meter_SN]);
    // echo json_encode($dvl_arr,256);
    
    // write_file('./config/dvl.json',json_encode($dvl_arr,256),"w");
    write_file('./config/dvl.json',json_encode($dvl_arr,256),"w");
?>