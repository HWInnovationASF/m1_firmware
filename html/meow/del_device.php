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
    $dvl_txt = file_get_contents('./dvl.json');
    $dvl_arr = json_decode($dvl_txt,true);
    unset($dvl_arr[$meter_SN]);
    // echo json_encode($dvl_arr,256);
    write_file('./config/dvl.json',json_encode($dvl_arr,256),"w");

    $cml_txt = file_get_contents('./config/cml.json');
    $cml_arr = json_decode($cml_txt,true);

    $found = false;
    // echo $meter_SN;

    foreach ($cml_arr as $cmd_id => $cml_data) {
        foreach ($cml_data as $read_type => $registers) {
            // echo $cmd_id;
            if (isset($registers[$meter_SN])) {
                echo "Found $meter_SN in device $cmd_id under $read_type\n";
                $found = true;
                unset($cml_arr[$cmd_id]);
            }
        }
    }
    // echo json_encode($cml_arr,256);
    write_file('./config/cml.json',json_encode($cml_arr,256),"w");
    if (!$found) {
        echo "$meter_SN not found in any device";
    }
?>