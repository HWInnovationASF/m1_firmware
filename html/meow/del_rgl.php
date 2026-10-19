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

    $model = isset($_GET['model']) ? $_GET['model'] : '';
    $register = isset($_GET['register']) ? $_GET['register'] : '';


    // $rgl_txt = file_get_contents('./rgl.json');
    $rgl_txt = file_get_contents('./config/rgl.json');
    $rgl_arr = json_decode($rgl_txt,true);

    // echo $model;
    // echo json_encode($cml_arr[$model]);
    if(!array_key_exists($model,$rgl_arr)){
        die;
    }

    if($register != ''){
        $fn_read = isset($_REQUEST['fn_read']) ? $_REQUEST['fn_read'] : 'read_holding';
        $bank = stripos($fn_read, 'input') !== false ? 'input' : 'holding';
        unset($rgl_arr[$model][$bank][$register]);
        // echo json_encode($rgl_arr,256);
        echo "del register";

    }
    if($register == ''){
        unset($rgl_arr[$model]);
        // echo json_encode($rgl_arr,256);
        echo "del model";

    }

    // echo json_encode($rgl_arr,256);


    // die;
    if(isset($rgl_arr)){
        // write_file('./rgl.json',json_encode($rgl_arr,256),"w");
        write_file('./config/rgl.json',json_encode($rgl_arr,256),"w");
    }
?>
