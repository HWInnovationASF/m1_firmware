<?php
	require_once __DIR__ . '/session_bootstrap.php';
	require_once __DIR__ . '/session_guard.php';


	$data_auth = file_get_contents("./userauth.json");
	// echo cnee($data_auth);
	$d_auth = cned($data_auth);

	// echo $d_auth;
	// echo gettype($d_auth);
	$uname = isset($_POST['txtUsername']) ? $_POST['txtUsername']:"";
	$auth_arr = json_decode($d_auth,true);
	$pass = array_key_exists($uname,$auth_arr) ? $auth_arr[$uname]['password'] : "";
	$utype = array_key_exists($uname,$auth_arr) ? $auth_arr[$uname]['type'] : "";

	if($utype !== "" && password_verify((string)($_POST['txtPassword'] ?? ""), (string)$pass)){
		session_regenerate_id(true);
		$_SESSION['username'] = $uname;
		$_SESSION['user_type'] = $utype;
		$_SESSION['timestart'] = time();
		$kicked = mdb_login_success($uname, $utype);
		if ($kicked) $_SESSION['mdb_note'] = ['device'=>(string)($kicked['device']??''),'ip'=>(string)($kicked['ip']??''),'at'=>(string)($kicked['since']??'')];
		session_write_close();
		// Open the dashboard template selected for this device after login.
		// The energy route resolves config/dashboard_template.json (Energy,
		// Chiller, or Template 1-6) instead of forcing the default monitor view.
		header("Location: index.php?page=energy");
		exit;
	}else{
		echo '<script>alert("Password Incorrect!");document.location.href = "./authentication-login.php";</script>';
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
?>
