<?php 

function write_file($src,$file_text,$mode_write="w"){	
	if($mode_write=="a"){
		file_put_contents($src, $file_text, FILE_APPEND);
	}else{
		file_put_contents($src, $file_text);
	}
	chmod($src, 0777);
}

if (!function_exists('cnee')) {
	function cnee($cne){
		$cryptKey='inno2024';
		$b=base64_encode($cne);
		$c=openssl_encrypt($b,"AES-128-CBC",$cryptKey);
		$d=base64_encode($c);
		return $d;
	}
}
if (!function_exists('cned')) {
	function cned($cne){
		$cryptKey='inno2024';
		$d=base64_decode($cne);
		$a=openssl_decrypt($d,"AES-128-CBC",$cryptKey);
		$b=base64_decode($a);
		return $b;
	}
}

?>