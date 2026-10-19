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

    $cmdid = isset($_GET['cmdid']) ? $_GET['cmdid'] : '';
    // $cml_txt = file_get_contents('./cml.json');
    $cml_txt = file_get_contents('./config/cml.json');
    $cml_arr = json_decode($cml_txt,true);

// echo json_encode($cml_arr[$cmdid]);
    unset($cml_arr[$cmdid]);

    echo json_encode($cml_arr,256);


    // write_file('./cml.json',json_encode($cml_arr,256),"w");
    write_file('./config/cml.json',json_encode($cml_arr,256),"w");
?>