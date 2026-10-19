<?php
	require_once __DIR__ . '/session_bootstrap.php';
	require_once __DIR__ . '/session_guard.php';
	mdb_logout((string)($_SESSION['username'] ?? ''));
	$_SESSION = [];
	session_destroy();
	header("location:index.php");
?>
