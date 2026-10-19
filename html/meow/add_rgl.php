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


    $model = isset($_GET['model']) ? $_GET['model'] : '';
    $register = isset($_GET['register']) ? $_GET['register'] : '';
    // $ws_daa = isset($_POST['WSData']) ? $_POST['WSData'] : '';
    $data_arr = array();
    $ws_daa = file_get_contents("php://input");
    $ws_arr = json_decode($ws_daa,true);
    // echo json_encode($ws_arr);

    $data_arr[$model] = $ws_arr;
    // echo json_encode($data_arr);

    // $rgl_txt = file_get_contents('./config/rgl.json');
    $rgl_txt = file_get_contents('./config/rgl.json');
    $rgl_arr = $rgl_txt != "" ? json_decode($rgl_txt,true) : array();
    // if(array_key_exists($cmdid,$cml_arr)){
    //     $cml_arr[$cmdid] = $data_arr[$cmdid];
    // }

    $fn_read = isset($_GET['fn_read']) ? $_GET['fn_read'] : 'read_holding';
    $bank = stripos($fn_read, 'input') !== false ? 'input' : 'holding';
    $rgl_arr[$model][$bank][$register] = $ws_arr[$bank][$register] ?? ($ws_arr[$register] ?? null);
    // echo json_encode($rgl_arr);
    // // unset($dvl_arr[$meter_SN]);
    // echo json_encode($dvl_arr,256);
    echo json_encode($rgl_arr);
    
    // die;
    if(isset($rgl_arr)){
        // write_file('./config/rgl.json',json_encode($rgl_arr,256),"w");
        write_file('./config/rgl.json',json_encode($rgl_arr,256),"w");
    }
?>
