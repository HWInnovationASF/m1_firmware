<?php
$today=date("Y-m-d");
$now=date("Y-m-d H:i:s");
$date_U=date("U");
// $dataE = isset($_GET['dataE']) ? $_GET['dataE']:"";
// $mode = isset($_POST['mode']) ? $_POST['mode']:"";
// $msg = isset($_POST['msg']) ? $_POST['msg']:"";
// $mode = isset($_GET['mode']) ? $_GET['mode']:"";


if($_GET){
	foreach($_GET as $k=>$v){
		if(isset($v) && $v<>"undefined")$$k=$v;
	}
}
write_file("log.txt","=".json_encode($_GET,256)."=".json_encode($_POST,256)."=mode=$mode=\r\n","w");
if($_POST){
	foreach($_POST as $k=>$v){
		//echo "====$k==".isset($v)."=$v=\r\n<br>";
		if(isset($v) && $v<>"undefined")$$k=$v;
	}
	//echo "====$search_text====\r\n<br>";
}
// write_file("log.txt","=2=mode=$mode=\r\n","a");

// die;
// $mode = isset($_GET['mode']) ? $_GET['mode']:"";
// $msg = isset($_GET['msg']) ? $_GET['msg']:"";
// echo cnee($dataE);
// echo json_encode(array("val" => cnee($msg)));
// echo $msg;

if($mode == "getenc"){
	echo cnee($msg);
}
elseif($mode == "getdnc"){
	echo cned($msg);
}
function cnee($cne){
	$cryptKey='inno2024';
	$b=base64_encode($cne);
	$c=openssl_encrypt($b,"AES-128-CBC",$cryptKey);
	$d=base64_encode($c);
	return $d;
}
function cned($cne){
	$cryptKey='inno2024';
	$d=base64_decode($cne);
	$a=openssl_decrypt($d,"AES-128-CBC",$cryptKey);
	$b=base64_decode($a);
	return $b;
}

$start_cne=cnee('{"ts":"'.date("U").'"}');

function write_file($src,$file_text,$mode_write="w"){	
	if($mode_write=="a"){
		file_put_contents($src, $file_text, FILE_APPEND);
	}else{
		file_put_contents($src, $file_text);
	}
	chmod($src, 0777);
}

// $data_va = $_GET['WSData'];

// echo $mode;

// die;
// $json_arr = array();


$device_time=date("YmdHis");

if($mode=="WSDevice"){
	$WSData_arr=array();
	$WSData_arr=json_decode($WSData,1);
	// echo $WSData;

	// {"mode":"status","device_SN":"M117358983035741","data":{"PM113022":{"v_an":"233.056","p_t":"3.616","e_p":"192799.843"},"PM113102":{"v_an":"233.034","p_t":"12.018","e_p":"731373.032"},"PM113101":{"v_an":"232.793","p_t":"11.642","e_p":"1283680.639"},"PM113006":{"v_an":"226.936","p_t":"52.278","e_p":"1266278.699"},"PM113009":{"v_an":"232.692","p_t":"64.403","e_p":"1077708.717"},"PM114018":{"p_t":"96.7","e_p":"5242211.06"},"PM111031":{"v_an":"232.2","p_t":"100.93","e_p":"3767134.0"},"PM111033":{"v_an":"232.336","p_t":"6.511","e_p":"711035.813"},"PM112041":{"v_an":"233.314","p_t":"37.56","e_p":"1176508.517"},"PM210001":{"v_an":"12941.754","v_bn":"12983.999","v_cn":"13002.165","v_ab":"22421.644","v_bc":"22508.648","v_ca":"22495.288","i_a":"12.168","i_b":"11.935","i_c":"11.673","i_avg":"11.925","p_t":"450.807","q_t":"108.61","s_t":"464.212","pf_t":"0.971","f":"49.988","e_p":"702125.824","e_q":"10026.309","e_s":"784681.318"}},"ip":"172.16.110.140","ts":"1742466892","dt":"2025-03-20 17:34:52"}

	// $json_arr 
	$ttt = isset($_GET['WSData']) ? $_GET['WSData']:$WSData;
	$json_arr = json_decode($ttt,true);
	$device_SN=$WSData_arr['device_SN'];
	$device_type=$WSData_arr['device_type'];
	$sub_type=substr($device_SN,0,2);
	// echo json_encode($json_arr);
	// die;
	
	if($json_arr['mode'] == "status" || $json_arr['mode'] == "reply" ){
		// $data_va = isset($_POST['WSData']) ? $_POST['WSData']:"";


		$json_arr['cne']=$start_cne;
		$json_arr['ts']=time();

		$now_sec=intval(substr($now,strlen($now)-2));
		$ms_minus=0;
		if($sub_type<>"PM" && $sub_type<>"M1"){
			$last_digit=intval(substr($device_SN,strlen($device_SN)-2));
			
			$sec_send=26+intval($last_digit/3);
			//$sec_send=50;
			if($now_sec<$sec_send-3 || $now_sec>$sec_send-3 || 1){
				$ms_minus=$now_sec-$sec_send;
			}else{
				$ms_minus=0;
			}
		}else{
			$last_digit=intval(substr($device_SN,strlen($device_SN)-1));
			$sec_send=$last_digit+2;
			//$sec_send=50;
			if($now_sec<$sec_send-3 || $now_sec>$sec_send-3 || 1){
				$ms_minus=$now_sec-$sec_send;
			}else{
				$ms_minus=0;
			}
		}
		$json_arr['device_time']=$device_time;
		$json_arr['sub_type']=$sub_type;
		$json_arr['sec_send']=$sec_send;
		if($ms_minus>45)$ms_minus=60-$ms_minus;
		if($WSData_arr['mode']=="status"){
			$json_arr['ms_minus']=$ms_minus;
			$json_arr['sec_count']=60-$ms_minus;
		}

		if($sub_type=="M1" || $sub_type=="PM" && $WSData_arr['mode']=="status"){
			// $json_arr3=get_device_config($device_SN);
			$json_arr['device_config']=$json_arr3;
		}

		header('Content-Type: application/json; charset=utf-8');
		//echo file_get_contents("textfile/M117228241200962_wss.txt");
		// echo json_encode($json_arr,256);
		echo json_encode($json_arr);

		write_file("./textfile/log_WSDevice_".$device_SN.".txt",json_encode($json_arr));
	}

	die;
}



?>