<?php
// $mcf_network = file_get_contents('../data/mcf_network.json');
$mcf_network = file_get_contents('./data/mcf_network.json');
$mcf_networ_arr = json_decode($mcf_network,true);
// echo $mcf_networ_arr;
// $output = shell_exec("ip -4 -o addr show eth0 scope global | awk '{print $4}'");
// $ip = explode("\n",trim($output)); // e.g. "192.168.1.100/24"
// $ip1 = $ip[0];
// $ip2 = $ip[1];
// echo "IP Address: $ip[0]";

$cidr = trim(shell_exec("ip -o -f inet addr show eth0 | awk '{print $4}'"));
list($ip, $prefix) = explode('/', $cidr);

// Convert CIDR prefix to dotted decimal netmask
function cidrToNetmask($prefix) {
    $mask = (0xffffffff << (32 - $prefix)) & 0xffffffff;
    return long2ip($mask);
}

$netmask = cidrToNetmask((int)$prefix);
$gateway = trim(shell_exec("ip route | awk '/default/ && /eth0/ {print \$3}'"));
// $primary_dns = trim(shell_exec("grep nameserver /etc/resolv.conf | awk '{print \$2}'"));
$primary_dns = trim(shell_exec("nmcli dev show | grep -E 'IP4.DNS\[1\]:|IP4.DNS\[2\]:' | awk '{print $2}' | head -n 1"));
$secoundary_dns = trim(shell_exec("grep search /etc/resolv.conf | awk '{print \$2}'"));
// echo "IP: $ip\n";
// echo "Netmask: $netmask\n";
// echo "Gateway: $gateway\n";
// echo "DNS Server: $primary_dns\n";
// echo "DNS search: $secoundary_dns\n";

?>

<div style="border:1px solid #ddd;border-radius:8px;padding:15px;background:#FFF;padding-left:10vw;padding-right:10vw;">
    <div><div style="font-size:22px;font-weight:700;text-align:center;margin-bottom:5px;">Network Configuration</div></div>
    
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">Interface:</div>
        <div class="input-group">
            <select name="" id="select-iface" class="form-select" onchange="selectIface()" style="text-align:center;">
                <option value="LAN">LAN</option>
                <option value="WiFi">WiFi</option>
                <option value="GSM">GSM</option>
            </select>
        </div>
    </div>
    <div style="" >
        <div>
            <?php 
                // echo shell_exec("sudo iwlist wlan1 scan | egrep 'ESSID|Frequency");
                // $op11 = shell_exec("nmcli -t -f SSID dev wifi list | sort | uniq");
                // echo $op11;


                $op = shell_exec("iwlist wlan0 scan | egrep 'ESSID'");
                // if(!isset($op)){ echo "<script type='text/javascript'>alert('$message');</script>";}
                // echo "<pre>$op</pre>";
                $aaa = str_replace("ESSID","",$op);
                $bbb = explode(":",$aaa);
                $bbb = str_replace("\"","",$bbb);
                $bbb = str_replace("\n","",$bbb);
                $wf_arr = array();
                foreach($bbb as $k=>$wfName){
                    if($wfName != ""){
                        // echo $k."==".$wfName."==".count($wfName)."<br>";
                        $wf_arr[$wfName] = $bbb[$k];
                    }
                }
                unset($wf_arr['']);

                // echo json_encode($wf_arr);
            ?>
        </div>
        <!-- <script src="http://192.168.0.9/meow/assets/selectize.js-master/dist/js/selectize.min.js" ></script>
        <link rel="stylesheet" href="http://192.168.0.9/meow/assets/selectize.js-master/dist/css/selectize.bootstrap3.css" /> -->
        
        <!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/selectize.js/0.12.6/js/standalone/selectize.min.js" integrity="sha256-+C0A5Ilqmu4QcSPxrlGpaZxJ04VjsRjKu+G82kl5UJk=" crossorigin="anonymous"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/selectize.js/0.12.6/css/selectize.bootstrap3.min.css" integrity="sha256-ze/OEYGcFbPRmvCnrSeKbRTtjG4vGLHXgOqsyLFTRjg=" crossorigin="anonymous" /> -->
        
        <!-- <div class="select-status hide" style="margin-bottom:5px;margin-top:5px;">
            <div>Scan WiFi:</div>
            <select class="select-st form-select" id="select-state" style="width:200px;" placeholder="Pick a state...">
                <option value="">Select a state...</option>
                <?php
                foreach($wf_arr as $kk=>$wff){
                    if($kk != ''){
                        echo "<option>".$wff."</option>";
                    }
                }
                ?>
            </select>
        </div> -->

    </div>

    <div class="select-status hide" style="">
        <!-- <div style="margin-right:5px;">
            <div>username:</div>
            <input class="form-control" type="text" name="" id="select-username"  >
        </div> -->
        <div>password:</div>
        <input class="form-control" type="password" name="" id="select-password"  >
    </div>


    
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">IP:</div>
        <div class="input-group">
            <input type="text" class="form-control" id="ip_address" style="text-align:center" placeholder="Ex.:192.168.4.1/24" value="<?php echo $ip;?>">
        </div>
    </div>
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">Subnet:</div>
        <div class="input-group">
            <input type="text" class="form-control" id="ip_subnet" style="text-align:center" placeholder="Ex.:255.255.255.0" value="<?php echo $netmask;?>">
        </div>
    </div>
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">Gateway:</div>
        <div class="input-group">
            <input type="text" class="form-control" id="gateway" style="text-align:center" placeholder="Ex.:192.168.4.1" value="<?php echo $gateway;?>">
        </div>
    </div>
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">DNS Server:</div>
        <div class="input-group">
            <input type="text" class="form-control" id="dns_server" style="text-align:center" placeholder="Ex.:8.8.8.8" value="<?php echo $primary_dns;?>">
        </div>
    </div>
    <div style="display:flex;align-items: center;justify-content: space-between;margin-bottom:5px;">
        <div style="font-size:16px;font-weight:400;width:50%;">DNS Search:</div>
        <div class="input-group">
            <input type="text" class="form-control" id="dns_search" style="text-align:center" placeholder="Ex.:8.8.8.8" value="<?php echo $secoundary_dns;?>">
        </div>
    </div>

    <div><button class="btn btn-primary" onclick="save_interface()" style="width:100%;">save</button></div>
</div>

<script>
    function save_interface(){
        let select_iface = $('#select-iface').val();
        let ip_address = $('#ip_address').val();
        let ip_subnet = $('#ip_subnet').val();
        let gateway = $('#gateway').val();
        let dns_server = $('#dns_server').val();
        let dns_search = $('#dns_search').val();

        console.log(ip_address,gateway,dns_server,dns_search);
        // let ip_subnet_arr = ip_subnet.split(".");
        // let netmask = 0;
        // // console.log(ip_subnet_arr);
        // ip_subnet_arr.forEach((item) => {
        //     // console.log(item);
        //     if(item == '255'){
        //         netmask += 8;

        //     }
        // });
        // console.log(netmask,Math.pow(2, 2));
        
        
        
        // json_str = '{"ip_address":"' + ip_addr + '","subnet":"' + subnet + '","gateway":"' + gateway + '","primary_dns":"' + dns1 + '","secoundary_dns":"' + dns2 + '"}'
        let data_arr = [];
        data_arr = {type_interface:select_iface,ip_address:String(ip_address),subnet:String(ip_subnet),gateway:String(gateway),primary_dns:String(dns_server),secoundary_dns:String(dns_search)};
        if($('#select-state').val() != ""){
            data_arr['ssid'] =  $('#select-state').val() != null ? $('#select-state').val() : '';
        }
        if($('#select-password').val() != ""){
            data_arr['password'] =  $('#select-password').val() != null ? $('#select-password').val() : '';
        }
        console.log(data_arr);
        
        Swal.fire({
            title: "Do you want to save the changes?",
            // showDenyButton: true,
            showCancelButton: true,
            confirmButtonText: "Save",
            // denyButtonText: `Don't save`
        }).then((result) => {
            /* Read more about isConfirmed, isDenied below */
            if (result.isConfirmed) {
                $.ajax({
                    type: 'GET',
                    url: "./mx_fn_write_network.php",
                    data: {
                        dataE: JSON.stringify(data_arr)
                    },
                    success: function(response) {
                        console.log(response);
                        // Swal.fire("Saved!", "", "success");
                        Swal.fire({
                            position: "center",
                            icon: "success",
                            title: "Your work has been saved",
                            showConfirmButton: false,
                            timer: 1500
                        });

                    },
                    error: function(xhr, status, error) {
                        console.error('Error:', error);
                        Swal.fire("Changes are not saved", "", "info");
                    }
                });
            } else if (result.isDenied) {
                Swal.fire("Changes are not saved", "", "info");
            } else {
                Swal.fire("Changes are not saved", "", "info");
            }
        });
    }

    //  $(document).ready(function () {
    //     $('.select-st').selectize({
    //         sortField: 'text'
    //     });
    // });

    function selectIface(){
        let selectIface = $('#select-iface').val();
        console.log(selectIface);
        if(selectIface == "WiFi"){
            $('.select-status').addClass('show');
            $('.select-status').removeClass('hide');
        }
        else{
            $('.select-status').addClass('hide');
            $('.select-status').removeClass('show');
        }
    }

</script>

<style>
    .hide{
        display:none;
    }
    .show{
        display:flex;
        justify-content: space-between;
    }
    input[type=number]{
        text-align:center;
    }
</style>