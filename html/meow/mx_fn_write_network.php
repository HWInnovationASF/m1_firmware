<?php
include('mx_function.php');
// print_r( $_GET['dataE']);
$data_arr = isset($_GET['dataE']) ? $_GET['dataE']:"";
// echo json_decode($data_arr,true);
// echo str_replace("\\","",json_encode(json_decode($data_arr,true)));
// echo $data_arr ;
// die;
$file_path = "./data/mcf_network.json";
if (file_put_contents($file_path, str_replace("\\","",json_encode(json_decode($data_arr,true))))) {
    echo "Data successfully saved to $file_path";
    chmod($file_path,0777);
    // $aaa = shell_exec("iwlist wlan0 scan | egrep 'ESSID'");
    // echo $aaa;
// 'sudo nmcli c mod "Wired connection 1" ipv4.addresses ' + request.json["ip_address"] + '/24 ipv4.method manual'
// 'sudo nmcli con mod "Wired connection 1" ipv4.gateway ' + request.json["gateway"]
// 'sudo nmcli con mod "Wired connection 1" ipv4.dns "' + request.json["primary_dns"] + ',' + request.json["secoundary_dns"] + '"'
    // echo $data_arr['ip_address'];
    if(isset($data_arr)){
        $network_arr = json_decode($data_arr,true);
        echo $network_arr['type_interface'];
        if($network_arr['type_interface'] == "LAN"){
            // echo 'sudo nmcli c mod "Wired connection 1" ipv4.addresses '.$network_arr['ip_address'].'/24 ipv4.method manual';
            // echo 'sudo nmcli con mod "Wired connection 1" ipv4.gateway '.$network_arr['gateway'];
            // echo 'sudo nmcli con mod "Wired connection 1" ipv4.dns "'.$network_arr['primary_dns'].','.$network_arr['secoundary_dns'].'"';
            // sudo nmcli con mod "Wired connection 1" ipv4.dns "192.168.0.1,8.8.8.8"
            $connection = "Wired connection 1";
            shell_exec('sudo nmcli c mod "Wired connection 1" ipv4.addresses '.$network_arr['ip_address'].'/24,192.168.4.1/24 ipv4.method manual');
            shell_exec('sudo nmcli con mod "Wired connection 1" ipv4.gateway '.$network_arr['gateway']);
            shell_exec('sudo nmcli con mod "Wired connection 1" ipv4.dns "'.$network_arr['primary_dns'].','.$network_arr['secoundary_dns'].'"');
            shell_exec("sudo nmcli connection up \"$connection\"");

            // alert("config success");
        }
    }else{
        // alert("config success");
    }
}
?>