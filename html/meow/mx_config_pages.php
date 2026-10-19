<?php
/**
 * mx_config_pages.php — unified page-render functions
 *
 * รวม endpoint เดิม: mx_config_network.php, mx_config_communication.php,
 * mx_config_device.php, mx_configurator.php
 *
 * เรียกจาก index.php ตาม $pg ด้วยฟังก์ชัน:
 *   render_config_network(), render_config_communication(),
 *   render_config_device(), render_configurator()
 */
if (!function_exists("cidrToNetmask")) {
function cidrToNetmask($prefix) {
    $mask = (0xffffffff << (32 - $prefix)) & 0xffffffff;
    return long2ip($mask);
}
}
if (!function_exists("get_iface_network_info")) {
// $iface is always one of the hardcoded interface names below (eth0/wlan0), never user input.
function get_iface_network_info($iface) {
    $cidr = trim(shell_exec("ip -o -f inet addr show $iface 2>/dev/null | awk '{print \$4}'"));
    $ip = '';
    $netmask = '';
    if ($cidr !== '' && strpos($cidr, '/') !== false) {
        list($ip, $prefix) = explode('/', $cidr);
        $netmask = cidrToNetmask((int)$prefix);
    }
    $gateway = trim(shell_exec("ip route | awk '/default/ && /$iface/ {print \$3}'"));
    $primary_dns = trim(shell_exec("nmcli dev show $iface 2>/dev/null | grep -E 'IP4.DNS\[1\]:' | awk '{print \$2}'"));
    $secoundary_dns = trim(shell_exec("nmcli dev show $iface 2>/dev/null | grep -E 'IP4.DNS\[2\]:' | awk '{print \$2}'"));
    return array(
        'ip' => $ip,
        'netmask' => $netmask,
        'gateway' => $gateway,
        'primary_dns' => $primary_dns,
        'secoundary_dns' => $secoundary_dns,
    );
}
}
function render_config_network() {
    $mcf_network = file_get_contents('./data/mcf_network.json');
    $mcf_networ_arr = json_decode($mcf_network,true);
$net_info = array(
    'LAN'  => get_iface_network_info('eth0'),
    'WiFi' => array('ip' => '', 'netmask' => '', 'gateway' => '', 'primary_dns' => '', 'secoundary_dns' => ''),
);

// Fields shown on initial page load default to the LAN (eth0) values, matching the default selected option.
$ip = $net_info['LAN']['ip'];
$netmask = $net_info['LAN']['netmask'];
$gateway = $net_info['LAN']['gateway'];
$primary_dns = $net_info['LAN']['primary_dns'];
$secoundary_dns = $net_info['LAN']['secoundary_dns'];
?>
<div class="network-page">
    <div class="network-hero">
        <div class="network-hero-icon"><i class="fa-solid fa-network-wired"></i></div>
        <div><h1>Network Configuration</h1><p>Configure the gateway connection and IP addressing.</p></div>
        <span class="network-hero-badge"><i class="fa-solid fa-circle"></i> Network settings</span>
    </div>

    <div class="network-grid">
        <section class="network-card network-interface-card">
            <div class="network-card-head"><span class="network-card-icon"><i class="fa-solid fa-shuffle"></i></span><div><h2>Connection Interface</h2><p>Select how this gateway connects to the network</p></div></div>
            <label class="network-field"><span>Interface</span><select id="select-iface" class="form-select" onchange="selectIface()"><option value="LAN">LAN · Ethernet</option><option value="WiFi">WiFi · Wireless</option><option value="GSM">GSM · Mobile network</option></select></label>
            <div class="network-interface-hint"><i class="fa-solid fa-circle-info"></i><span>Changing the interface may temporarily interrupt the connection.</span></div>
        </section>

        <section class="network-card network-wifi-card select-status hide">
            <div class="network-card-head"><span class="network-card-icon"><i class="fa-solid fa-wifi"></i></span><div><h2>WiFi Connection</h2><p>Select an access point and enter its password</p></div></div>
            <label class="network-field"><span>Available network</span><div class="network-scan-row"><select class="select-st form-select" id="select-state"><option value="">Select a network...</option></select><button type="button" class="network-rescan-btn" id="rescan-wifi" onclick="rescanWifi()"><i class="fa-solid fa-rotate"></i><span>Rescan</span></button></div></label>
            <label class="network-field"><span>WiFi password</span><div class="network-password"><input class="form-control" type="password" id="select-password" autocomplete="new-password"><button type="button" class="toggle-wifi-password" title="Show or hide password" aria-label="Show or hide password"><i class="mdi mdi-eye-outline"></i></button></div></label>
        </section>

        <section class="network-card network-address-card">
            <div class="network-card-head"><span class="network-card-icon"><i class="fa-solid fa-location-crosshairs"></i></span><div><h2>IPv4 Address</h2><p>Local address and default route</p></div></div>
            <div class="network-fields-2">
                <label class="network-field"><span>IP address</span><input type="text" class="form-control" id="ip_address" placeholder="192.168.4.1" value="<?php echo htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');?>"></label>
                <label class="network-field"><span>Subnet mask</span><input type="text" class="form-control" id="ip_subnet" placeholder="255.255.255.0" value="<?php echo htmlspecialchars($netmask, ENT_QUOTES, 'UTF-8');?>"></label>
                <label class="network-field network-field-full"><span>Default gateway</span><input type="text" class="form-control" id="gateway" placeholder="192.168.4.1" value="<?php echo htmlspecialchars($gateway, ENT_QUOTES, 'UTF-8');?>"></label>
            </div>
        </section>

        <section class="network-card network-dns-card">
            <div class="network-card-head"><span class="network-card-icon"><i class="fa-solid fa-globe"></i></span><div><h2>DNS Settings</h2><p>Name resolution servers for this interface</p></div></div>
            <div class="network-fields-2">
                <label class="network-field"><span>Primary DNS</span><input type="text" class="form-control" id="dns_server" placeholder="8.8.8.8" value="<?php echo htmlspecialchars($primary_dns, ENT_QUOTES, 'UTF-8');?>"></label>
                <label class="network-field"><span>Secondary DNS</span><input type="text" class="form-control" id="dns_search" placeholder="8.8.4.4" value="<?php echo htmlspecialchars($secoundary_dns, ENT_QUOTES, 'UTF-8');?>"></label>
            </div>
        </section>
    </div>

    <div class="network-action-bar"><span><i class="fa-solid fa-triangle-exclamation"></i> Verify the values before applying to avoid losing access.</span><button type="button" class="network-save-btn" onclick="save_interface()"><i class="fa-solid fa-floppy-disk"></i> Save network</button></div>
</div>

<script>
    $('.toggle-wifi-password').click(function(){
        $(this).children().toggleClass('mdi-eye-outline mdi-eye-off-outline');
        let input = $(this).prev();
        input.attr('type', input.attr('type') === 'password' ? 'text' : 'password');
    });

    let netInfoByIface = <?php echo json_encode($net_info); ?>;

    function updateNetworkFields(){
        let selectIface = $('#select-iface').val();
        let info = netInfoByIface[selectIface] ? netInfoByIface[selectIface] : netInfoByIface['LAN'];
        $('#ip_address').val(info.ip);
        $('#ip_subnet').val(info.netmask);
        $('#gateway').val(info.gateway);
        $('#dns_server').val(info.primary_dns);
        $('#dns_search').val(info.secoundary_dns);
    }

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
                    type: 'POST',
                    url: "./mx_fn_api.php?action=write_network",
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

    function rescanWifi(){
        let btn = $('#rescan-wifi');
        let select = $('#select-state');
        let currentValue = select.val();
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i><span>Scanning...</span>');

        $.ajax({
            type: 'GET',
            url: './mx_fn_api.php?action=wifi_scan',
            dataType: 'json',
            success: function(ssidList){
                select.empty();
                select.append('<option value="">Select a network...</option>');
                ssidList.forEach(function(ssid){
                    select.append($('<option></option>').attr('value', ssid).text(ssid));
                });
                if(ssidList.includes(currentValue)){
                    select.val(currentValue);
                }
            },
            error: function(xhr, status, error){
                console.error('Error:', error);
                Swal.fire("Scan failed", "", "error");
            },
            complete: function(){
                btn.prop('disabled', false).html('<i class="fa-solid fa-rotate"></i><span>Rescan</span>');
            }
        });
    }

    let wifiStatusLoaded = false;

    function loadWifiStatus(){
        let btn = $('#rescan-wifi');
        let select = $('#select-state');
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i><span>Scanning...</span>');

        $.ajax({
            type: 'GET',
            url: './mx_fn_api.php?action=wifi_status',
            dataType: 'json',
            success: function(status){
                select.empty();
                select.append('<option value="">Select a network...</option>');
                status.ssid_list.forEach(function(ssid){
                    select.append($('<option></option>').attr('value', ssid).text(ssid));
                });
                if(status.connected_ssid){
                    select.val(status.connected_ssid);
                }
                $('#select-password').val(status.connected_password);

                netInfoByIface['WiFi'] = {
                    ip: status.ip,
                    netmask: status.netmask,
                    gateway: status.gateway,
                    primary_dns: status.primary_dns,
                    secoundary_dns: status.secoundary_dns
                };
                updateNetworkFields();
                wifiStatusLoaded = true;
            },
            error: function(xhr, status, error){
                console.error('Error:', error);
                Swal.fire("Failed to load WiFi status", "", "error");
            },
            complete: function(){
                btn.prop('disabled', false).html('<i class="fa-solid fa-rotate"></i><span>Rescan</span>');
            }
        });
    }

    function selectIface(){
        let selectIface = $('#select-iface').val();
        console.log(selectIface);
        if(selectIface == "WiFi"){
            $('.select-status').addClass('show');
            $('.select-status').removeClass('hide');
            if(!wifiStatusLoaded){
                loadWifiStatus();
                return;
            }
        }
        else{
            $('.select-status').addClass('hide');
            $('.select-status').removeClass('show');
        }
        updateNetworkFields();
    }

</script>

<style>
    .network-page{max-width:1180px;margin:0 auto;padding:4px 2px 28px;color:#17233d}.network-page .hide{display:none!important}.network-page .show{display:block}
    .network-hero{position:relative;display:flex;align-items:center;gap:16px;overflow:hidden;margin-bottom:18px;padding:24px 28px;border-radius:18px;color:#fff;background:linear-gradient(125deg,#0758b7 0%,#1479df 62%,#26a7e8 100%);box-shadow:0 12px 30px rgba(11,91,177,.2)}.network-hero:after{content:"";position:absolute;right:-55px;bottom:-85px;width:220px;height:220px;border:36px solid rgba(255,255,255,.09);border-radius:50%}.network-hero-icon{display:grid;place-items:center;flex:0 0 54px;width:54px;height:54px;border:1px solid rgba(255,255,255,.25);border-radius:15px;background:rgba(255,255,255,.15);font-size:24px}.network-hero h1{margin:0 0 4px;font-size:25px;font-weight:800;letter-spacing:-.3px}.network-hero p{margin:0;color:rgba(255,255,255,.83);font-size:14px}.network-hero-badge{z-index:1;margin-left:auto;padding:8px 12px;border:1px solid rgba(255,255,255,.25);border-radius:999px;background:rgba(0,34,78,.2);font-size:12px;font-weight:700;white-space:nowrap}.network-hero-badge i{margin-right:6px;color:#78f0ba;font-size:8px}
    .network-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start}.network-card{min-width:0;padding:21px;border:1px solid #dce6f0;border-radius:16px;background:#fff;box-shadow:0 5px 18px rgba(32,61,96,.06)}.network-interface-card{display:grid;grid-column:span 2;grid-template-columns:minmax(280px,.8fr) minmax(320px,1.2fr);gap:0 18px;align-items:end}.network-interface-card .network-card-head{grid-column:1/-1}.network-interface-card .network-interface-hint{min-height:46px;margin:0}.network-wifi-card{grid-column:span 2}.network-wifi-card.show{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 16px}.network-wifi-card .network-card-head{grid-column:1/-1}.network-card-head{display:flex;align-items:center;gap:12px;margin-bottom:19px}.network-card-head h2{margin:0 0 2px;color:#17233d;font-size:16px;font-weight:800}.network-card-head p{margin:0;color:#7b8799;font-size:12px}.network-card-icon{display:grid;place-items:center;flex:0 0 40px;width:40px;height:40px;border-radius:11px;background:#eaf3ff;color:#1268c7;font-size:17px}.network-wifi-card .network-card-icon{background:#f0ebff;color:#7250d6}.network-address-card .network-card-icon{background:#e9f8f1;color:#169b61}.network-dns-card .network-card-icon{background:#fff3df;color:#e38a00}
    .network-field{display:flex;min-width:0;flex-direction:column;gap:8px;margin:0;color:#2f4058;font-size:12px;font-weight:800}.network-field>span:after{content:"Input";display:inline-block;margin-left:7px;padding:2px 6px;border-radius:5px;background:#edf3f9;color:#8090a4;font-size:8px;font-weight:800;letter-spacing:.4px;text-transform:uppercase;vertical-align:1px}.network-page .form-control,.network-page .form-select{width:100%;height:46px;padding:10px 13px;border:1.5px solid #bdcad9;border-radius:10px;background-color:#fff;color:#17233d;font-size:14px;font-weight:600;text-align:left;box-shadow:inset 0 1px 2px rgba(26,49,76,.04),0 1px 2px rgba(26,49,76,.03);transition:border-color .18s,box-shadow .18s,background .18s}.network-page .form-control::placeholder{color:#a4afbd;font-weight:500}.network-page .form-control:hover,.network-page .form-select:hover{border-color:#8ea6bf;background:#fbfdff}.network-page .form-control:focus,.network-page .form-select:focus{border-color:#1672cf;background:#fff;box-shadow:0 0 0 3px rgba(22,114,207,.13)}.network-fields-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.network-field-full{grid-column:span 2}.network-interface-hint{display:flex;align-items:center;gap:9px;padding:10px 12px;border:1px solid #dce8f3;border-radius:10px;background:#f4f8fd;color:#68778d;font-size:11px;line-height:1.45}.network-interface-hint i{color:#2580d9}
    .network-scan-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px}.network-rescan-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:0 15px;border:1px solid #cdd9e7;border-radius:10px;background:#fff;color:#176bc3;font-size:13px;font-weight:700;cursor:pointer}.network-rescan-btn:hover{background:#eaf3ff}.network-rescan-btn:disabled{opacity:.65;cursor:wait}.network-password{position:relative}.network-password input{padding-right:45px}.toggle-wifi-password{position:absolute;z-index:2;top:50%;right:5px;display:grid;place-items:center;width:34px;height:34px;padding:0;transform:translateY(-50%);border:0;border-radius:8px;background:transparent;color:#68778d;font-size:19px;cursor:pointer}.toggle-wifi-password:hover{background:#eaf3ff;color:#1268c7}
    .network-action-bar{position:sticky;z-index:5;bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:18px;margin-top:18px;padding:13px 15px 13px 19px;border:1px solid #dce5ef;border-radius:15px;background:rgba(255,255,255,.94);box-shadow:0 10px 28px rgba(24,49,81,.13);backdrop-filter:blur(8px)}.network-action-bar>span{color:#6d7a8f;font-size:12px}.network-action-bar>span i{margin-right:5px;color:#e49a1a}.network-save-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-width:160px;padding:11px 18px;border:0;border-radius:10px;color:#fff;background:linear-gradient(135deg,#1268c7,#0879df);font-size:14px;font-weight:800;box-shadow:0 6px 14px rgba(18,104,199,.22);cursor:pointer}.network-save-btn:hover{filter:brightness(1.05);transform:translateY(-1px)}
    @media(max-width:767px){.network-page{padding-bottom:18px}.network-hero{align-items:flex-start;padding:19px}.network-hero-icon{flex-basis:45px;width:45px;height:45px}.network-hero h1{font-size:20px}.network-hero p{font-size:12px}.network-hero-badge{display:none}.network-grid{grid-template-columns:1fr}.network-card,.network-interface-card,.network-wifi-card,.network-wifi-card.show{display:block;grid-column:span 1;padding:17px}.network-interface-card .network-interface-hint{margin-top:12px}.network-wifi-card .network-field+.network-field{margin-top:15px}.network-fields-2{grid-template-columns:1fr}.network-field-full{grid-column:span 1}.network-action-bar{bottom:5px;align-items:stretch;flex-direction:column;padding:12px}.network-action-bar>span{text-align:center}.network-save-btn{width:100%}}
    @media(min-width:768px) and (max-width:900px){.network-interface-card{grid-template-columns:1fr}.network-interface-card .network-interface-hint{margin-top:12px}.network-wifi-card.show{grid-template-columns:1fr}.network-wifi-card .network-field+.network-field{margin-top:15px}}
</style><?php }

function render_config_communication() {
$data_all = file_get_contents('./data/mcf_default.json');
// $data_all = file_get_contents('../data/mcf_default.json');
$data_all = $data_all != "" ? json_decode($data_all,true) : array();
// {"devcieSN":"M117210144775061","mx_count":5,"hb_count":60,"wss":"wss://smart.asefa.co.th:5501","ftp_host":"172.16.0.65","ftp_port":2002,"ftp_u":"meowftp","ftp_p":"Asefa@2o23"}
$deviceSN_f = isset($data_all['deviceSN']) ? $data_all['deviceSN']: "";
$mx_count_f = isset($data_all['mx_count']) ? intval($data_all['mx_count']): 10;
$cml_send_f = isset($data_all['cml_send']) ? intval($data_all['cml_send']): 60;
$hb_count_f = isset($data_all['hb_count']) ? intval($data_all['hb_count']): 60;
$wss_f      = isset($data_all['wss']) ? $data_all['wss']: "";
$ftp_host_f = isset($data_all['ftp_host']) ? $data_all['ftp_host']: "";
$ftp_port_f = isset($data_all['ftp_port']) ? intval($data_all['ftp_port']): 21;
$ftp_u_f    = isset($data_all['ftp_u']) ? $data_all['ftp_u']: "";
$ftp_p_f    = isset($data_all['ftp_p']) ? $data_all['ftp_p']: "";
$token_key_f = isset($data_all['token_key']) ? $data_all['token_key']: "";
// echo $deviceSN_f;

$httpServer_f = isset($data_all['httpServer']) ? $data_all['httpServer']: "http://api.mdbiot.com/vx_mdbiot_api.php";
$httpServer_parts = parse_url($httpServer_f);
$http_scheme_f = (isset($httpServer_parts['scheme']) && strtolower($httpServer_parts['scheme']) == 'https') ? 'https' : 'http';
$http_host_f = isset($httpServer_parts['host']) ? $httpServer_parts['host'] : '';
if (isset($httpServer_parts['port'])) $http_host_f .= ':' . $httpServer_parts['port'];
$http_path_f = isset($httpServer_parts['path']) ? $httpServer_parts['path'] : '';
if (isset($httpServer_parts['query'])) $http_path_f .= '?' . $httpServer_parts['query'];
?>
<div class="comm-page">
    <input type="hidden" id="wss_server" value="<?php echo htmlspecialchars($wss_f, ENT_QUOTES, 'UTF-8');?>">

    <div class="comm-hero">
        <div class="comm-hero-icon"><i class="fa-solid fa-tower-broadcast"></i></div>
        <div>
            <h1>Communication Settings</h1>
            <p>Manage device identity, server connections and data transfer intervals.</p>
        </div>
        <span class="comm-hero-badge"><i class="fa-solid fa-circle"></i> M1 Gateway</span>
    </div>

    <div class="comm-grid">
        <section class="comm-card comm-card-device">
            <div class="comm-card-head">
                <span class="comm-card-icon"><i class="fa-solid fa-microchip"></i></span>
                <div><h2>Device Identity</h2><p>Unique identity of this gateway</p></div>
            </div>
            <label class="comm-field">
                <span>Device S/N</span>
                <input type="text" class="form-control" id="device_sn" placeholder="M112345678901234" value="<?php echo htmlspecialchars($deviceSN_f, ENT_QUOTES, 'UTF-8');?>">
                <small><i class="fa-solid fa-shield-halved"></i> Admin and Superadmin can change this value.</small>
            </label>
            <label class="comm-field comm-token-field">
                <span>Token Key</span>
                <div class="comm-password"><input type="password" class="form-control" id="token_key" autocomplete="new-password" placeholder="Enter token key" value="<?php echo htmlspecialchars($token_key_f, ENT_QUOTES, 'UTF-8');?>"><button type="button" class="toggle-token-key" title="Show or hide token key" aria-label="Show or hide token key"><i class="mdi mdi-eye-outline"></i></button></div>
                <small><i class="fa-solid fa-shield-halved"></i> Reserved for operation limit authorization.</small>
            </label>
        </section>

        <section class="comm-card comm-card-http">
            <div class="comm-card-head">
                <span class="comm-card-icon"><i class="fa-solid fa-cloud-arrow-up"></i></span>
                <div><h2>Cloud / HTTP API</h2><p>Destination for sending device data</p></div>
            </div>
            <div class="comm-http-grid">
                <label class="comm-field"><span>Protocol</span><select class="form-select" id="httpProtocol"><option value="http" <?php echo $http_scheme_f == 'http' ? 'selected' : '';?>>HTTP</option><option value="https" <?php echo $http_scheme_f == 'https' ? 'selected' : '';?>>HTTPS</option></select></label>
                <label class="comm-field"><span>Host</span><input type="text" class="form-control" id="httpHost" placeholder="api.mdbiot.com" value="<?php echo htmlspecialchars($http_host_f, ENT_QUOTES, 'UTF-8');?>"></label>
                <label class="comm-field comm-http-path"><span>Endpoint path</span><input type="text" class="form-control" id="httpPath" placeholder="/vx_mdbiot_api.php" value="<?php echo htmlspecialchars($http_path_f, ENT_QUOTES, 'UTF-8');?>"></label>
            </div>
        </section>

        <section class="comm-card comm-card-mqtt">
            <div class="comm-card-head">
                <span class="comm-card-icon"><i class="fa-solid fa-tower-cell"></i></span>
                <div><h2>MQTT Broker</h2><p>Real-time messaging connection</p></div>
            </div>
            <div class="comm-fields-2">
                <label class="comm-field"><span>Broker address</span><input type="text" class="form-control" id="mqttBroker" placeholder="mqtt.mdbiot.com" value="<?php echo htmlspecialchars(isset($data_all['mqttBroker']) ? $data_all['mqttBroker'] : 'mqtt.mdbiot.com', ENT_QUOTES, 'UTF-8');?>"></label>
                <label class="comm-field"><span>Port</span><input type="number" class="form-control" id="mqttPort" min="1" max="65535" placeholder="1883" value="<?php echo isset($data_all['mqttPort']) ? intval($data_all['mqttPort']) : 1883;?>"></label>
            </div>
        </section>

        <section class="comm-card comm-card-timing">
            <div class="comm-card-head">
                <span class="comm-card-icon"><i class="fa-solid fa-stopwatch"></i></span>
                <div><h2>Data Timing</h2><p>Control reporting and retry intervals</p></div>
            </div>
            <div class="comm-fields-3">
                <label class="comm-field"><span>Heartbeat</span><div class="comm-input-suffix"><input type="number" class="form-control" id="hb_count" min="1" value="<?php echo $hb_count_f;?>"><b>sec</b></div></label>
                <label class="comm-field"><span>Send Log</span><div class="comm-input-suffix"><input type="number" class="form-control" id="cml_send" min="1" value="<?php echo intval($cml_send_f);?>"><b>sec</b></div></label>
                <label class="comm-field"><span>CML Count</span><div class="comm-input-suffix"><input type="number" class="form-control" id="cml_count" min="1" value="<?php echo intval($mx_count_f);?>"><b>times</b></div></label>
            </div>
        </section>

        <section class="comm-card comm-card-ftp">
            <div class="comm-card-head">
                <span class="comm-card-icon"><i class="fa-solid fa-folder-open"></i></span>
                <div><h2>FTP Server</h2><p>File transfer destination and credentials</p></div>
            </div>
            <div class="comm-fields-2">
                <label class="comm-field"><span>Host</span><input type="text" class="form-control" id="ftp_host" placeholder="172.16.0.65" value="<?php echo htmlspecialchars($ftp_host_f, ENT_QUOTES, 'UTF-8');?>"></label>
                <label class="comm-field"><span>Port</span><input type="number" class="form-control" id="ftp_port" min="1" max="65535" placeholder="21" value="<?php echo $ftp_port_f;?>"></label>
                <label class="comm-field"><span>Username</span><input type="text" class="form-control" id="ftp_username" autocomplete="username" placeholder="Username" value="<?php echo htmlspecialchars($ftp_u_f, ENT_QUOTES, 'UTF-8');?>"></label>
                <label class="comm-field"><span>Password</span><div class="comm-password"><input type="password" class="form-control" name="txtPassword" autocomplete="new-password" id="ftp_password" value="<?php echo htmlspecialchars($ftp_p_f, ENT_QUOTES, 'UTF-8');?>" minlength="2" placeholder="Password"><button type="button" class="toggle-ftp-password" title="Show or hide password" aria-label="Show or hide password"><i class="mdi mdi-eye-outline"></i></button></div></label>
            </div>
        </section>
    </div>

    <div class="comm-action-bar">
        <span><i class="fa-solid fa-circle-info"></i> Changes take effect after saving.</span>
        <button type="button" class="comm-save-btn" onclick="save_communication()"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
    </div>
</div>


<script>
    $(document).ready(function () {
        let user_type = "<?php echo $_SESSION['user_type'];?>";
        let inputField = document.getElementById("device_sn");
        let value = inputField.value;
        // Configuration fields are editable by Admin and Superadmin.
        if(user_type == "superadmin" || user_type == "admin"){
            inputField.removeAttribute("disabled");
        }else {
            inputField.setAttribute("disabled", true);
        }

    });

    $('.toggle-ftp-password').click(function(){
        $(this).children().toggleClass('mdi-eye-outline mdi-eye-off-outline');
        let input = $(this).prev();
        input.attr('type', input.attr('type') === 'password' ? 'text' : 'password');
    });

    $('.toggle-token-key').click(function(){
        $(this).children().toggleClass('mdi-eye-outline mdi-eye-off-outline');
        let input = $(this).prev();
        input.attr('type', input.attr('type') === 'password' ? 'text' : 'password');
    });

    function writeTextFile(filepath, output) {
        let txtFile = new File(filepath);
        txtFile.open("w"); //
        txtFile.writeln(output);
        txtFile.close();
    }

    function build_http_server_url(){
        let httpProtocol = String($('#httpProtocol').val());
        let httpHost = String($('#httpHost').val()).trim();
        let httpPath = String($('#httpPath').val()).trim();
        if(httpPath && httpPath.charAt(0) !== '/'){
            httpPath = '/' + httpPath;
        }
        return httpProtocol + '://' + httpHost + httpPath;
    }

    function save_communication(){
        let httpServerCombined = build_http_server_url();

        let data_arr = {deviceSN:String($('#device_sn').val()),mx_count:parseInt($('#cml_count').val()),cml_send:parseInt($('#cml_send').val()),hb_count:parseInt($('#hb_count').val() !== undefined ? $('#hb_count').val():60),wss:String($('#wss_server').val()),token_key:String($('#token_key').val()),mqttBroker:String($('#mqttBroker').val()),mqttPort:parseInt($('#mqttPort').val()),httpServer:httpServerCombined,ftp_host:String($('#ftp_host').val()),ftp_port:parseInt($('#ftp_port').val()),ftp_u:String($('#ftp_username').val()),ftp_p:String($('#ftp_password').val())};
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
                    type: 'POST',
                    url: "./mx_fn_api.php?action=write_config",
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
</script>

<style>
    .comm-page{max-width:1180px;margin:0 auto;padding:4px 2px 28px;color:#17233d}
    .comm-hero{position:relative;display:flex;align-items:center;gap:16px;overflow:hidden;margin-bottom:18px;padding:24px 28px;border-radius:18px;color:#fff;background:linear-gradient(125deg,#0758b7 0%,#1479df 62%,#26a7e8 100%);box-shadow:0 12px 30px rgba(11,91,177,.2)}
    .comm-hero:after{content:"";position:absolute;right:-55px;bottom:-85px;width:220px;height:220px;border:36px solid rgba(255,255,255,.09);border-radius:50%}
    .comm-hero-icon{display:grid;place-items:center;flex:0 0 54px;width:54px;height:54px;border:1px solid rgba(255,255,255,.25);border-radius:15px;background:rgba(255,255,255,.15);font-size:24px}
    .comm-hero h1{margin:0 0 4px;font-size:25px;font-weight:800;letter-spacing:-.3px}.comm-hero p{margin:0;color:rgba(255,255,255,.83);font-size:14px}
    .comm-hero-badge{z-index:1;margin-left:auto;padding:8px 12px;border:1px solid rgba(255,255,255,.25);border-radius:999px;background:rgba(0,34,78,.2);font-size:12px;font-weight:700;white-space:nowrap}.comm-hero-badge i{margin-right:6px;color:#78f0ba;font-size:8px}
    .comm-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start}.comm-card{min-width:0;padding:21px;border:1px solid #e0e8f2;border-radius:16px;background:#fff;box-shadow:0 5px 18px rgba(32,61,96,.06)}
    .comm-card-device{display:grid;grid-column:span 2;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 18px}.comm-card-device .comm-card-head{grid-column:1/-1}.comm-card-http,.comm-card-ftp{grid-column:span 2}.comm-card-head{display:flex;align-items:center;gap:12px;margin-bottom:19px}.comm-card-head h2{margin:0 0 2px;color:#17233d;font-size:16px;font-weight:800}.comm-card-head p{margin:0;color:#7b8799;font-size:12px}
    .comm-card-icon{display:grid;place-items:center;flex:0 0 40px;width:40px;height:40px;border-radius:11px;background:#eaf3ff;color:#1268c7;font-size:17px}.comm-card-http .comm-card-icon{background:#e9f8f1;color:#169b61}.comm-card-mqtt .comm-card-icon{background:#f0ebff;color:#7250d6}.comm-card-timing .comm-card-icon{background:#fff3df;color:#e38a00}.comm-card-ftp .comm-card-icon{background:#e8f7fb;color:#168ca8}
    .comm-field{display:flex;min-width:0;flex-direction:column;gap:7px;margin:0;color:#35425a;font-size:12px;font-weight:700}.comm-field small{color:#8a95a7;font-size:11px;font-weight:500}.comm-field small i{margin-right:4px}.comm-page .form-control,.comm-page .form-select{width:100%;height:43px;border:1px solid #d8e1ec;border-radius:10px;background-color:#f9fbfd;color:#17233d;font-size:14px;text-align:left;box-shadow:none;transition:border-color .18s,box-shadow .18s,background .18s}.comm-page .form-control:focus,.comm-page .form-select:focus{border-color:#2580d9;background:#fff;box-shadow:0 0 0 3px rgba(37,128,217,.12)}.comm-page .form-control:disabled{color:#66748a;background:#edf1f5;cursor:not-allowed}
    .comm-http-grid{display:grid;grid-template-columns:120px minmax(220px,1fr) minmax(240px,1.2fr);gap:12px}.comm-fields-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.comm-fields-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
    .comm-input-suffix{position:relative}.comm-input-suffix input{padding-right:54px}.comm-input-suffix b{position:absolute;top:50%;right:12px;transform:translateY(-50%);color:#8491a4;font-size:11px;font-weight:700}
    .comm-token-field{margin-top:0}.comm-password{position:relative}.comm-password input{padding-right:45px}.toggle-ftp-password,.toggle-token-key{position:absolute;z-index:2;top:50%;right:5px;display:grid;place-items:center;width:34px;height:34px;padding:0;transform:translateY(-50%);border:0;border-radius:8px;background:transparent;color:#68778d;font-size:19px;cursor:pointer}.toggle-ftp-password:hover,.toggle-token-key:hover{background:#eaf3ff;color:#1268c7}
    .comm-action-bar{position:sticky;z-index:5;bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:18px;margin-top:18px;padding:13px 15px 13px 19px;border:1px solid #dce5ef;border-radius:15px;background:rgba(255,255,255,.94);box-shadow:0 10px 28px rgba(24,49,81,.13);backdrop-filter:blur(8px)}.comm-action-bar>span{color:#6d7a8f;font-size:12px}.comm-action-bar>span i{margin-right:5px;color:#2580d9}.comm-save-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-width:155px;padding:11px 18px;border:0;border-radius:10px;color:#fff;background:linear-gradient(135deg,#1268c7,#0879df);font-size:14px;font-weight:800;box-shadow:0 6px 14px rgba(18,104,199,.22);cursor:pointer}.comm-save-btn:hover{filter:brightness(1.05);transform:translateY(-1px)}
    @media(max-width:767px){.comm-page{padding-bottom:18px}.comm-hero{align-items:flex-start;padding:19px}.comm-hero-icon{flex-basis:45px;width:45px;height:45px}.comm-hero h1{font-size:20px}.comm-hero p{font-size:12px}.comm-hero-badge{display:none}.comm-grid{grid-template-columns:1fr}.comm-card,.comm-card-device,.comm-card-http,.comm-card-ftp{display:block;grid-column:span 1;padding:17px}.comm-card-device .comm-field+.comm-field{margin-top:15px}.comm-http-grid,.comm-fields-2,.comm-fields-3{grid-template-columns:1fr}.comm-action-bar{bottom:5px;align-items:stretch;flex-direction:column;padding:12px}.comm-action-bar>span{text-align:center}.comm-save-btn{width:100%}}
    @media(min-width:768px) and (max-width:991px){.comm-http-grid{grid-template-columns:110px 1fr}.comm-http-path{grid-column:span 2}}
</style>
<?php }

function render_config_device() {
    $rglFtpCanUpload = in_array(strtolower($_SESSION['user_type'] ?? ''), array('admin', 'superadmin'), true);
    if ($rglFtpCanUpload && empty($_SESSION['rgl_api_csrf'])) {
        $_SESSION['rgl_api_csrf'] = bin2hex(random_bytes(32));
    }
    $rglFtpCsrf = $rglFtpCanUpload ? (string)$_SESSION['rgl_api_csrf'] : '';
    // self-contained: ไม่พึ่งพา $rgl_arr/$dvl_arr/$cml_arr จาก index.php อีกต่อไป
    $rgl_txt = file_get_contents('./config/rgl.json');
    $dvl_txt = file_get_contents('./config/dvl.json');
    $cml_txt = file_get_contents('./config/cml.json');
    $rgl_arr = $rgl_txt != "" ? json_decode($rgl_txt,true) : array();
    $dvl_arr = $dvl_txt != "" ? json_decode($dvl_txt,true) : array();
    $cml_arr = $cml_txt != "" ? json_decode($cml_txt,true) : array();
    $rgl_bank = (isset($_GET['bank']) && $_GET['bank'] === 'input') ? 'input' : 'holding';
    // Per-model classification (type/abbr) mapped by rgl.json key. rgl.json is
    // left untouched; this lives in a separate file that can be edited from the UI.
    $detail_txt = file_exists('./config/detail_rgl.json') ? file_get_contents('./config/detail_rgl.json') : '';
    $detail_arr = $detail_txt != "" ? json_decode($detail_txt,true) : array();
    if (!is_array($detail_arr)) $detail_arr = array();
    // Build the Type choices from types that are already assigned to models.
    // New types are added through the selector and then become reusable here.
    $model_types = array();
    foreach ($detail_arr as $detail_row) {
        if (!is_array($detail_row)) continue;
        $saved_type = trim((string)($detail_row['type'] ?? ''));
        $saved_abbr = trim((string)($detail_row['abbr'] ?? ''));
        if ($saved_type !== '' && $saved_abbr !== '' && !isset($model_types[$saved_type])) {
            $model_types[$saved_type] = $saved_abbr;
        }
    }
    uksort($model_types, 'strnatcasecmp');
    $meter_model_type_options = array();
    foreach (array_keys($rgl_arr) as $existing_model_name) {
        $existing_detail = isset($detail_arr[$existing_model_name]) && is_array($detail_arr[$existing_model_name])
            ? $detail_arr[$existing_model_name] : array();
        $meter_model_type_options[] = array(
            'name' => $existing_model_name,
            'type' => trim((string)($existing_detail['type'] ?? '')),
            'abbr' => trim((string)($existing_detail['abbr'] ?? '')),
        );
    }
    // Parameter suggestions for the RGL "Name" combobox (config/parameter_list.json)
    $param_txt = file_exists('./config/parameter_list.json') ? file_get_contents('./config/parameter_list.json') : '';
    $param_raw = $param_txt != "" ? json_decode($param_txt, true) : array();
    $param_list = array();
    if (is_array($param_raw)) {
        foreach ($param_raw as $prow) {
            if (!is_array($prow) || empty($prow['Parameter'])) continue;
            $param_list[] = array(
                'p'    => $prow['Parameter'],
                'name' => isset($prow['InfoData']['Name']) ? $prow['InfoData']['Name'] : '',
                'unit' => isset($prow['InfoData']['Unit']) ? $prow['InfoData']['Unit'] : '',
            );
        }
    }
    // Parameter -> friendly display name (for the register table)
    $param_name_map = array();
    foreach ($param_list as $pl) { if ($pl['name'] !== '') $param_name_map[$pl['p']] = $pl['name']; }

    // Per-model register list (sorted) for the CML parameter picker + meterSN->model map
    $cml_models = array();
    foreach ($rgl_arr as $mname => $mregs) {
        $regs = $mregs;
        $has_bacnet_object_banks = false;
        if (preg_match('/^bac(?:_|$)/i', (string)$mname) && is_array($regs)) {
            foreach ($regs as $object_type => $object_regs) {
                if (!is_numeric($object_type) && is_array($object_regs)) {
                    foreach (array_keys($object_regs) as $object_instance) {
                        if (is_numeric($object_instance)) { $has_bacnet_object_banks = true; break 2; }
                    }
                }
            }
        }
        if ($has_bacnet_object_banks) {
            $object_rows = array();
            foreach ($regs as $object_type => $object_regs) {
                if (!is_array($object_regs)) continue;
                $rows = array();
                foreach ($object_regs as $raddr => $rv) {
                    if (!is_numeric($raddr) || !is_array($rv)) continue;
                    $code = isset($rv['name']) ? $rv['name'] : '';
                    $rows[] = array(
                        'reg'=>(int)$raddr, 'code'=>$code,
                        'name'=>($code !== '' && isset($param_name_map[$code])) ? $param_name_map[$code] : $code,
                        'type'=>isset($rv['type']) ? $rv['type'] : ''
                    );
                }
                usort($rows, function($a, $b){ return $a['reg'] - $b['reg']; });
                $object_rows[(string)$object_type] = $rows;
            }
            $cml_models[$mname] = $object_rows;
            continue;
        }
        if (is_array($regs) && (isset($regs['holding']) || isset($regs['input']))) {
            $bank_rows = array();
            foreach (array('holding', 'input') as $bank_name) {
                $bank_regs = isset($regs[$bank_name]) && is_array($regs[$bank_name]) ? $regs[$bank_name] : array();
                $rows = array();
                foreach ($bank_regs as $raddr => $rv) {
                    if (!is_numeric($raddr) || !is_array($rv)) continue;
                    $code = isset($rv['name']) ? $rv['name'] : '';
                    $rows[] = array(
                        'reg'=>(int)$raddr, 'code'=>$code,
                        'name'=>($code !== '' && isset($param_name_map[$code])) ? $param_name_map[$code] : $code,
                        'type'=>isset($rv['type']) ? $rv['type'] : ''
                    );
                }
                usort($rows, function($a, $b){ return $a['reg'] - $b['reg']; });
                $bank_rows[$bank_name] = $rows;
            }
            $cml_models[$mname] = $bank_rows;
            continue;
        }
        if (!is_array($regs)) continue;
        $rows = array();
        foreach ($regs as $raddr => $rv) {
            if (!is_numeric($raddr)) continue;
            $code = isset($rv['name']) ? $rv['name'] : '';
            $rows[] = array(
                'reg'  => (int)$raddr,
                'code' => $code,
                'name' => ($code !== '' && isset($param_name_map[$code])) ? $param_name_map[$code] : $code,
                'type' => isset($rv['type']) ? $rv['type'] : '',
            );
        }
        usort($rows, function($a, $b){ return $a['reg'] - $b['reg']; });
        $cml_models[$mname] = $rows;
    }
    $cml_dev_model = array();
    $cml_dev_protocol = array();
    $cml_model_devices = array();
    $cml_device_command_count = array();
    foreach ($cml_arr as $command_data) {
        if (!is_array($command_data)) continue;
        foreach ($command_data as $command_devices) {
            if (!is_array($command_devices)) continue;
            foreach ($command_devices as $command_sn => $_command_range) {
                $cml_device_command_count[$command_sn] = ($cml_device_command_count[$command_sn] ?? 0) + 1;
            }
        }
    }
    foreach ($dvl_arr as $sn => $dv) {
        $cml_dev_model[$sn] = isset($dv['model']) ? $dv['model'] : '';
        $cml_dev_protocol[$sn] = strtolower((string)($dv['mode'] ?? ''));
        $device_model = $cml_dev_model[$sn];
        if ($device_model !== '') {
            if (!isset($cml_model_devices[$device_model])) $cml_model_devices[$device_model] = array();
            $cml_model_devices[$device_model][] = array(
                'sn' => (string)$sn,
                'name' => (string)($dv['name'] ?? $sn),
                'mode' => $cml_dev_protocol[$sn],
                'command_count' => (int)($cml_device_command_count[$sn] ?? 0),
            );
        }
    }
    require_once __DIR__ . '/session_bootstrap.php';
    // echo $_SESSION['user_type'];
    // ===========================================
    // require './con_db.php';

    $data_all = file_get_contents('./data/mcf_default.json');
    // $data_all = file_get_contents('../data/mcf_default.json');
    $data_all = $data_all != "" ? json_decode($data_all,true) : array();
    $deviceSN_f = isset($data_all['deviceSN']) ? $data_all['deviceSN']: "";


?>
<!-- <div>
    <div><div>Advance setting</div></div>
    <div style="display:flex;">
        <div>Serial :</div>
        <div>
            <?php if(($_SESSION['user_type'] ?? '') == "superadmin"){?>
            <input type="text" id="device_sn" value="<?php echo $deviceSN_f; ?>" placeholder="Ex.:M112345678901234" >
            <?php }?>
        
        </div>
    </div>
    <div><button class="btn btn-primary" onclick="save_main()">save</button></div>
</div> -->


<?php 
    // $rgl_txt = file_get_contents('./rgl.json');
    // $dvl_txt = file_get_contents('./dvl.json');
    // $cml_txt = file_get_contents('./cml.json');
    // $rgl_arr = json_decode($rgl_txt,true);
    // $dvl_arr = json_decode($dvl_txt,true);
    // $cml_arr = json_decode($cml_txt,true);
    // echo json_encode(array_keys($rgl_arr));
    
    // echo $dvl_txt;
    $page_list = isset($_GET['pg']) ? $_GET['pg']:'meter';
    $meterSN = isset($_GET['meterSN']) ? $_GET['meterSN']:'';
    $modelName = isset($_GET['modelName']) ? $_GET['modelName']:'';
    $is_bac_model = preg_match('/bac/i', (string)$modelName) === 1;
    $rgl_function = '';
    if ($is_bac_model && isset($rgl_arr[$modelName]) && is_array($rgl_arr[$modelName])) {
        $requested_function = isset($_GET['fn']) ? (string)$_GET['fn'] : '';
        $rgl_function = ($requested_function !== '' && isset($rgl_arr[$modelName][$requested_function]))
            ? $requested_function : (string)(array_key_first($rgl_arr[$modelName]) ?? 'analogInput');
    }

    $deviceBreadcrumbs = array();
    $manageDeviceUrl = 'index.php?page=device&pg=meter';
    if ($page_list === 'meter') {
        $deviceBreadcrumbs[] = array('label' => 'Manage Device', 'icon' => 'fa-microchip', 'url' => null);
    } else {
        $deviceBreadcrumbs[] = array('label' => 'Manage Device', 'icon' => 'fa-microchip', 'url' => $manageDeviceUrl);
    }
    if ($page_list === 'sensor') {
        if ($meterSN !== '') {
            $deviceBreadcrumbs[] = array(
                'label' => $meterSN,
                'icon' => 'fa-gauge-high',
                'url' => 'index.php?page=device&pg=sensor&meterSN=' . rawurlencode($meterSN)
            );
        }
        $deviceBreadcrumbs[] = array('label' => 'Command List', 'icon' => 'fa-list-check', 'url' => null);
    } elseif ($page_list === 'modellist') {
        $deviceBreadcrumbs[] = array('label' => 'Model List', 'icon' => 'fa-list', 'url' => null);
    } elseif ($page_list === 'model') {
        $deviceBreadcrumbs[] = array('label' => 'Model List', 'icon' => 'fa-list', 'url' => 'index.php?page=device&pg=modellist');
        $deviceBreadcrumbs[] = array(
            'label' => $modelName !== '' ? $modelName : 'Select Model',
            'icon' => 'fa-pen-to-square',
            'url' => null
        );
    } elseif ($page_list === 'cmlall') {
        $deviceBreadcrumbs[] = array('label' => 'CML List', 'icon' => 'fa-layer-group', 'url' => null);
    }

?>

<div style="display:flex;flex-direction: column;width:100%;padding: 5px 15px 5px 15px;">
    <!-- <div style="display:flex;">
        <m_breadcrumbs class="btn btn-outline-dark" onclick="window.location.href='index.php?page=device&pg=meter'">Meter</m_breadcrumbs>
        <m_breadcrumbs class="btn btn-outline-dark" onclick="window.location.href='index.php?page=device&pg=sensor'">Sensor</m_breadcrumbs>
    </div> -->

    <div class="card">
        <div class="card-header" style="display:flex;justify-content: space-between;">
            <div style="display:flex;align-items: center;">
                <?php foreach($deviceBreadcrumbs as $breadcrumbIndex => $breadcrumb){
                    $breadcrumbUrl = $breadcrumb['url'];
                    $isCurrentBreadcrumb = $breadcrumbUrl === null;
                ?>
                <m_breadcrumbs
                    class="<?php echo $isCurrentBreadcrumb ? 'is-current' : 'is-link'; ?>"
                    <?php if($isCurrentBreadcrumb){ ?>aria-current="page"<?php }else{ ?>
                    role="link" tabindex="0"
                    data-href="<?php echo htmlspecialchars($breadcrumbUrl, ENT_QUOTES, 'UTF-8'); ?>"
                    onclick="window.location.href=this.dataset.href"
                    onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
                    <?php } ?>
                ><i class="fa-solid <?php echo htmlspecialchars($breadcrumb['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>&nbsp;<?php echo htmlspecialchars($breadcrumb['label'], ENT_QUOTES, 'UTF-8'); ?></m_breadcrumbs>
                <?php } ?>
            </div>
            <div style="display:flex;align-items: center;">
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </span>
                    <input type="text" class="form-control" id="myInput" oninput="rememberDeviceSearch()" placeholder="Search for names.." title="Type in a name" autocomplete="off">
                    <button type="button" class="search-clear-btn" id="clearDeviceSearch" onclick="clearDeviceSearch()" aria-label="Clear search" title="Clear search">&times;</button>
                </div>
                &nbsp;

                <?php
                    if($page_list == "model" || $page_list == "modellist"){?>
                    <button class="btn btn-success" onclick="add_model()" style="text-wrap:nowrap;">+new model</button>
                <?php    }elseif($page_list == "sensor"){ ?>
                    <button class="btn btn-success" style="text-wrap:nowrap;" title="เพิ่ม Command List (CML) — คำสั่งอ่านค่า register"><i class="fa-solid fa-list-check"></i> new CML</button>
                <?php }
                else{?>
                <button class="btn btn-primary" onclick="window.location.href='index.php?page=device&pg=model&meterSN=<?php echo $meterSN;?>'" style="text-wrap:nowrap;">meter model</button>
                <?php } ?>
            </div>
        </div>
        <div class="card-body hide-scrollbar" style="">
        <?php if($page_list == "meter"){?>
        
            <!-- <button class="btn btn-primary" onclick="update_dvl_2server('dvl.json')">Upload DVL all</button> -->
            <div style="display:flex;justify-content: flex-end;margin-bottom:5px;">
                <div><button class="btn btn-danger" id="bulkDeleteDevices" type="button" disabled onclick="deleteSelectedDevices()"><i class="fa-regular fa-trash-can"></i> Delete selected (<span id="bulkDeleteCount">0</span>)</button></div>
                &nbsp;
                <div ><button class="btn btn-dark "  onclick="import_m1('')" ><i class="fa-solid fa-file-export"></i> Import Config</button></div>
                &nbsp;
                <div ><button class="btn btn-dark"  onclick="export_m1('dvl.json')" ><i class="fa-solid fa-file-export"></i> Export Config</button></div>
                &nbsp;
                <div ><button class="btn btn-info"  onclick="window.location.href='index.php?page=device&pg=cmlall'" >CML All </button></div>
                &nbsp;
                <div ><button class="btn btn-success" onclick="add_device()">new Device </button></div>
            </div>
            <div class="table-scroll">
            <table id="customers" style="border-radius:8px;">
                <tr>
                    <th style="width:42px;text-align:center;"><input type="checkbox" id="selectAllDevices" aria-label="Select all devices"></th>
                    <th>No.</th>
                    <th>S/N</th>
                    <th>Name</th>
                    <th>Model</th>
                    <th>Mode</th>
                    <th>Meter ID</th>
                    <th>detail</th>
                </tr>
                <?php 
                $i_m=0;
                ksort($dvl_arr);
                foreach($dvl_arr as $k=>$v){
                    $i_m++;
                ?>
                <tr>
                    <td style="text-align:center;"><input type="checkbox" class="device-delete-check" value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8');?>" aria-label="Select <?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8');?>"></td>
                    <td><?php echo $i_m;?></td>
                    <td><?php echo $k;?></td>
                    <td><?php echo $dvl_arr[$k]['name'];?></td>
                    <td>
                        <span class="cell-badge badge-model"><?php echo $dvl_arr[$k]['model'];?></span>
                        <?php
                            $row_model = $dvl_arr[$k]['model'];
                            $row_abbr  = isset($detail_arr[$row_model]['abbr']) ? $detail_arr[$row_model]['abbr'] : '';
                            $row_type  = isset($detail_arr[$row_model]['type']) ? $detail_arr[$row_model]['type'] : '';
                            if($row_abbr !== ''){
                        ?>
                        <span class="cell-badge badge-type" title="<?php echo htmlspecialchars($row_type);?>"><?php echo $row_abbr;?></span>
                        <?php } ?>
                    </td>
                    <td><span class="cell-badge badge-mode badge-mode-<?php echo strtolower($dvl_arr[$k]['mode']);?>"><?php echo $dvl_arr[$k]['mode'];?></span></td>
                    <td><span class="cell-badge badge-id"><?php echo $dvl_arr[$k]['deviceID'];?></span></td>
                    <td>
                        <div style="display:flex;justify-content: center;">
                            <button class="btn btn-dark" id="sendsensor" onclick="window.location.href='index.php?page=device&pg=sensor&meterSN=<?php echo $k;?>'" title="Command List (CML) — รายการคำสั่งอ่านค่า register ของมิเตอร์นี้ · กดเพื่อดู/จัดการ CML"><i class="fa-solid fa-list-check"></i></button> &nbsp;
                            <button class="btn btn-info" id="sendinfo" onclick='show_cml("<?php echo $k;?>",<?php echo json_encode($dvl_arr[$k]);?>)' title="ดูข้อมูลมิเตอร์และ Command List"><i class="fa-regular fa-circle-info"></i></button> &nbsp;
                            <!-- <button class="btn btn-info" id="sendinfo" onclick='check_info(<?php echo json_encode($dvl_arr[$k]);?>)' ><i class="fa-solid fa-circle-info"></i></button> &nbsp; -->
                            <button class="btn btn-warning" onclick='check_edit(<?php echo json_encode($dvl_arr[$k]);?>,"<?php echo $k?>")' title="แก้ไขการตั้งค่ามิเตอร์ (Config Meter)"><i class="fa-regular fa-pen-to-square"></i></button> &nbsp;
                            <button class="btn btn-danger" onclick="updateFile('<?php echo $k?>')" title="ลบมิเตอร์นี้"><i class="fa-regular fa-trash-can"></i></button>
                        </div>
                    </td>
                </tr>
                <?php } ?>
            </table>
            </div>
            <?php }elseif($page_list == "sensor"){
                // Guard: opening pg=sensor without a valid meterSN must not raise warnings
                $meter_model = isset($dvl_arr[$meterSN]['model']) ? $dvl_arr[$meterSN]['model'] : '';
                $meter_rgl   = ($meter_model !== '' && isset($rgl_arr[$meter_model])) ? $rgl_arr[$meter_model] : null;
                $sensor_is_bacnet = strtolower((string)($dvl_arr[$meterSN]['mode'] ?? '')) === 'bacnet';
                // Count CMLs bound to this meter (for empty-state)
                $sensor_cml_count = 0;
                foreach($cml_arr as $sk=>$sv){
                    $sfn = array_keys($sv)[0];
                    if(isset($sv[$sfn]) && is_array($sv[$sfn]) && array_key_exists($meterSN, $sv[$sfn])) $sensor_cml_count++;
                }
            ?>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <?php if($meterSN !== ''){ ?><span class="cell-badge badge-id"><?php echo $meterSN;?></span><?php } ?>
                    <?php if($meter_model !== ''){ ?><span class="cell-badge badge-model"><?php echo $meter_model;?></span><?php } ?>
                    <?php
                        $sensor_abbr = isset($detail_arr[$meter_model]['abbr']) ? $detail_arr[$meter_model]['abbr'] : '';
                        $sensor_type = isset($detail_arr[$meter_model]['type']) ? $detail_arr[$meter_model]['type'] : '';
                        if($meter_model !== '' && $sensor_abbr !== ''){
                    ?>
                    <span class="cell-badge badge-type" title="<?php echo htmlspecialchars($sensor_type);?>"><?php echo $sensor_abbr;?></span>
                    <?php } ?>
                    <span class="cell-badge badge-mode"><?php echo $sensor_cml_count;?> CML</span>
                </div>
                <div style="display:flex;justify-content: flex-end;">
                    <div><button class="btn btn-danger" id="bulkDeleteCml" type="button" disabled onclick="deleteSelectedCml('<?php echo htmlspecialchars($meterSN, ENT_QUOTES, 'UTF-8');?>')"><i class="fa-regular fa-trash-can"></i> Delete selected (<span id="bulkDeleteCmlCount">0</span>)</button></div>
                    &nbsp;
                    <?php if($meter_model !== ''){ ?>
                    <div ><button class="btn btn-info" onclick='info_meter("<?php echo $meter_model;?>",<?php echo json_encode($meter_rgl);?>)'><i class="fa-solid fa-circle-info"></i> Info <?php echo $meter_model;?></button></div>
                    &nbsp;
                    <?php } ?>
                    <div ><button class="btn btn-success" onclick="add_cml('<?php echo $meterSN;?>')" title="เพิ่ม Command List (CML) — คำสั่งอ่านค่า register ให้มิเตอร์นี้"><i class="fa-solid fa-list-check"></i> add CML</button></div>
                </div>
            </div>

            <div class="table-scroll">
            <table id="customers" style="border-radius:8px;">
                <tr>
                    <th style="width:42px;text-align:center;"><input type="checkbox" id="selectAllCml" aria-label="Select all command lists"></th>
                    <th>CMLID</th>
                    <th>Mode</th>
                    <th><?php echo $sensor_is_bacnet ? 'BACnet Objects' : 'Register start - end';?></th>
                    <th>Act</th>
                </tr>
                <?php
                $bacnetObjectTypes = [
                    'read_holding_tcp',
                    'read_input_tcp',
                    'read_holding_rtu',
                    'read_input_rtu',
                    'recv_holding_rtu',
                    'analogInput',
                    'analogOutput',
                    'analogValue',
                    'binaryInput',
                    'binaryOutput',
                    'binaryValue',
                    'multiStateInput',
                    'multiStateOutput',
                    'multiStateValue'
                ];
                // meter's model registers -> to list which parameters each command covers
                $sensor_model = isset($dvl_arr[$meterSN]['model']) ? $dvl_arr[$meterSN]['model'] : '';
                $sensor_regs  = isset($rgl_arr[$sensor_model]) ? $rgl_arr[$sensor_model] : array();
                if(!is_array($sensor_regs)) $sensor_regs = array();
                // ksort($rgl_arr[$dvl_arr[$meterSN]['model']]);
                // foreach($rgl_arr[$dvl_arr[$meterSN]['model']] as $k=>$v){
                
                foreach($cml_arr as $k=>$v){
                    if(array_key_exists($meterSN,$v[array_keys($v)[0]])){
                    ?>
                    <tr>
                        <?php
                            $cmd_mode = (string)array_keys($v)[0];
                            $cmd_values = array_values($v[$cmd_mode][$meterSN]);
                            $cmd_is_bacnet = strtolower((string)($dvl_arr[$meterSN]['mode'] ?? '')) === 'bacnet';
                            $cmd_s = isset($cmd_values[0]) ? (int)$cmd_values[0] : 0;
                            $cmd_e = isset($cmd_values[1]) ? (int)$cmd_values[1] : $cmd_s;
                            $cmd_mode_lower = strtolower($cmd_mode);
                            if(strpos($cmd_mode_lower, 'read_holding') === 0) $cmd_mode_color = 'holding';
                            elseif(strpos($cmd_mode_lower, 'read_input') === 0) $cmd_mode_color = 'input';
                            elseif(strpos($cmd_mode_lower, 'recv_') === 0) $cmd_mode_color = 'receive';
                            elseif(strpos($cmd_mode_lower, 'analog') === 0) $cmd_mode_color = 'analog';
                            elseif(strpos($cmd_mode_lower, 'binary') === 0) $cmd_mode_color = 'binary';
                            elseif(strpos($cmd_mode_lower, 'multistate') === 0) $cmd_mode_color = 'multistate';
                            else $cmd_mode_color = 'other';
                            $cmd_transport = substr($cmd_mode_lower, -4) === '_tcp' ? 'tcp' : (substr($cmd_mode_lower, -4) === '_rtu' ? 'rtu' : 'bacnet');
                            $command_sensor_regs = $sensor_regs;
                            if($cmd_is_bacnet && isset($sensor_regs[$cmd_mode]) && is_array($sensor_regs[$cmd_mode])) $command_sensor_regs = $sensor_regs[$cmd_mode];
                            $covered_detail = array();
                            foreach($command_sensor_regs as $raddr=>$rv){
                                if(!is_numeric($raddr)) continue;
                                $is_covered = $cmd_is_bacnet ? in_array((int)$raddr, array_map('intval', $cmd_values), true) : ($raddr >= $cmd_s && $raddr < $cmd_e);
                                if($is_covered){
                                    $rcode = isset($rv['name']) ? $rv['name'] : '';
                                    $rdisp = ($rcode !== '' && isset($param_name_map[$rcode])) ? $param_name_map[$rcode] : $rcode;
                                    $covered_detail[] = array(
                                        'name' => $rdisp !== '' ? $rdisp : ('reg '.$raddr),
                                        'code' => $rcode,
                                        'reg'  => (int)$raddr,
                                        'type' => isset($rv['type']) ? $rv['type'] : '',
                                    );
                                }
                            }
                        ?>
                        <td style="text-align:center;"><input type="checkbox" class="cml-delete-check" value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8');?>" aria-label="Select <?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8');?>"></td>
                        <td><span class="cell-badge badge-id"><?php echo $k;?></span></td>
                        <td><span class="cell-badge badge-mode badge-cml-mode badge-cml-<?php echo $cmd_mode_color;?> badge-cml-<?php echo $cmd_transport;?>"><?php echo htmlspecialchars($cmd_mode, ENT_QUOTES, 'UTF-8');?></span></td>
                        <td><?php if($cmd_is_bacnet){$cmd_preview=array_slice($cmd_values,0,5);?><span class="cell-badge badge-id" title="<?php echo htmlspecialchars(implode(', ',$cmd_values),ENT_QUOTES,'UTF-8');?>"><?php echo count($cmd_values);?> objects · <?php echo htmlspecialchars(implode(', ',$cmd_preview),ENT_QUOTES,'UTF-8');?><?php echo count($cmd_values)>5?' …':'';?></span><?php }else{?><span class="cell-badge badge-id"><?php echo $cmd_s;?> – <?php echo $cmd_e;?></span><?php }?></td>
                        <td>
                            <div style="display:flex;justify-content:center;gap:6px;">
                                <button class="btn btn-warning" onclick='show_cml_params("<?php echo $k;?>","<?php echo $meterSN;?>","<?php echo array_keys($v)[0];?>",<?php echo htmlspecialchars(json_encode($cmd_values),ENT_QUOTES,'UTF-8');?>)' title="แก้ไข Function และรายการ Parameter"><i class="fa-regular fa-pen-to-square"></i>&nbsp;แก้ไข</button>
                                <button class="btn btn-danger" onclick="del_cml('<?php echo $meterSN;?>','<?php echo $k?>','<?php echo array_keys($v)[0];?>')" title="ลบ CML นี้"><i class="fa-regular fa-trash-can"></i></button>
                            </div>
                        </td>
                        <!-- <td><?php echo json_encode($v[array_keys($v)[0]]);?></td> -->
                    </tr>
                    <?php
                    }
                }
                ?>
            </table>
            <?php if($sensor_cml_count === 0){ ?>
            <div class="model-empty">
                <i class="fa-regular fa-folder-open"></i>
                <p>มิเตอร์นี้ยังไม่มี CML — กด <b>+ add CML</b> เพื่อเพิ่ม</p>
            </div>
            <?php } ?>
            </div>
            <?php }elseif($page_list == "model"){ ?>
                <?php
                    // Count registers of the selected model (bac models nest one level deeper)
                    $reg_count = 0;
                    if($modelName !== '' && isset($rgl_arr[$modelName]) && is_array($rgl_arr[$modelName])){
                        $tmp_regs = $rgl_arr[$modelName];
                        if(isset($tmp_regs['holding']) || isset($tmp_regs['input'])) {
                            $tmp_regs = isset($tmp_regs[$rgl_bank]) && is_array($tmp_regs[$rgl_bank]) ? $tmp_regs[$rgl_bank] : array();
                        }
                        if($is_bac_model && !empty($tmp_regs)){
                            $tmp_first = array_values($tmp_regs)[0];
                            // Older BAC models are flat register maps; newer ones are
                            // grouped by BACnet object type.
                            if(is_array($tmp_first) && !array_key_exists('name', $tmp_first)) {
                                $tmp_regs = isset($tmp_regs[$rgl_function]) && is_array($tmp_regs[$rgl_function])
                                    ? $tmp_regs[$rgl_function] : array();
                            }
                        }
                        $reg_count = is_array($tmp_regs) ? count($tmp_regs) : 0;
                    }
                ?>
                <div class="model-config-toolbar">
                    <div class="model-config-fields">
                        <div class="model-control model-control--model">
                        <div class="model-label-row">
                            <label class="model-picker-label" for="selectModel"><i class="fa-solid fa-microchip"></i> Meter model</label>
                            <?php if($modelName !== ''){ ?>
                            <button type="button" class="model-name-edit-icon" onclick="rename_model('<?php echo htmlspecialchars($modelName, ENT_QUOTES);?>')" title="แก้ไขชื่อ Model" aria-label="แก้ไขชื่อ Model <?php echo htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8');?>"><i class="fa-regular fa-pen-to-square"></i></button>
                            <?php } ?>
                        </div>
                        <select class="form-select" name="model" id="selectModel" onchange="select_model()">
                            <?php
                                $cur_pre = ($modelName !== '' && isset($detail_arr[$modelName]['abbr']) && $detail_arr[$modelName]['abbr'] !== '') ? '('.$detail_arr[$modelName]['abbr'].') ' : '';
                            ?>
                            <option value="<?php echo $modelName != "" ? $modelName:'';?>"><?php echo $modelName != "" ? $cur_pre.$modelName : '— select model —';?></option>
                            <?php foreach($rgl_arr as $k=>$v){
                                $opt_pre = (isset($detail_arr[$k]['abbr']) && $detail_arr[$k]['abbr'] !== '') ? '('.$detail_arr[$k]['abbr'].') ' : '';
                            ?>
                            <option value="<?php echo $k;?>" <?php echo $modelName == $k ? "selected" : ""; ?>><?php echo $opt_pre.$k;?></option>
                            <?php } ?>
                        </select>
                        </div>
                        <?php if($modelName !== ''){ ?>
                        <?php if(!preg_match('/bac/', $modelName)){ ?>
                        <div class="model-control model-control--bank">
                        <label class="model-picker-label"><i class="fa-solid fa-layer-group"></i> Register bank</label>
                        <select class="form-select" id="rglBank" onchange="select_rgl_bank()">
                            <option value="holding" <?php echo $rgl_bank === 'holding' ? 'selected' : ''; ?>>Holding Register (FC03)</option>
                            <option value="input" <?php echo $rgl_bank === 'input' ? 'selected' : ''; ?>>Input Register (FC04)</option>
                        </select>
                        </div>
                        <?php } elseif(isset($rgl_arr[$modelName]) && is_array($rgl_arr[$modelName])) { ?>
                        <div class="model-control model-control--bank">
                        <label class="model-picker-label"><i class="fa-solid fa-layer-group"></i> BACnet object type</label>
                        <select class="form-select" id="rglFunction" onchange="select_rgl_function()">
                            <?php foreach(array_keys($rgl_arr[$modelName]) as $object_type){ ?>
                            <option value="<?php echo htmlspecialchars((string)$object_type, ENT_QUOTES, 'UTF-8');?>" <?php echo $rgl_function === (string)$object_type ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$object_type, ENT_QUOTES, 'UTF-8');?></option>
                            <?php } ?>
                        </select>
                        </div>
                        <?php } ?>
                        <div class="model-control model-control--type">
                        <?php
                            $cur_type = isset($detail_arr[$modelName]['type']) ? $detail_arr[$modelName]['type'] : '';
                        ?>
                        <label class="model-picker-label"><i class="fa-solid fa-tag"></i> Type</label>
                        <select class="form-select" id="modelType" data-current="<?php echo htmlspecialchars($cur_type, ENT_QUOTES, 'UTF-8');?>" onchange="save_model_type('<?php echo htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8');?>')">
                            <option value="">— ระบุประเภท —</option>
                            <?php foreach($model_types as $tname=>$tabbr){ ?>
                            <option value="<?php echo htmlspecialchars($tname, ENT_QUOTES, 'UTF-8');?>" <?php echo $cur_type === $tname ? 'selected' : ''; ?>><?php echo htmlspecialchars($tname.' ('.$tabbr.')', ENT_QUOTES, 'UTF-8');?></option>
                            <?php } ?>
                            <option value="__add__">＋ เพิ่มประเภทใหม่...</option>
                        </select>
                        </div>
                        <div class="model-status-box">
                        <span class="cell-badge badge-model"><i class="fa-solid fa-list-ol"></i>&nbsp;<?php echo $reg_count;?> registers</span>
                        </div>
                        <?php } ?>
                    </div>
                    <div class="model-config-actions">
                        <?php if(($_SESSION['username'] ?? '') == "dev"){ ?>
                        <div ><button class="btn btn-primary" onclick="update2server('rgl.json')"><i class="fa-solid fa-cloud-arrow-up"></i> Upload all</button></div>
                        <?php }?>
                        <?php if($modelName !== '' && $reg_count > 0){ ?>
                        <div><button class="btn btn-dark" onclick="save_all_rgl('<?php echo $modelName;?>')"><i class="fa-solid fa-floppy-disk"></i> Save</button></div>
                        <div><button class="btn btn-danger" id="delSelectedRglBtn" onclick="del_rgl_selected('<?php echo $modelName;?>')"><i class="fa-regular fa-trash-can"></i> Remove</button></div>
                        <?php } ?>
                        <div ><button class="btn btn-success" onclick="add_rgl('<?php echo $modelName;?>')"><i class="fa-solid fa-plus"></i> add RGL</button></div>
                    </div>
                </div>
                <div class="table-scroll">
                    <table id="customers" style="border-radius:8px;">
                            <tr>
                                <th><input type="checkbox" id="selectAllRgl" onchange="toggleAllRgl(this)" title="เลือกทั้งหมด"></th>
                                <th>No.</th>
                                <th>Register</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Unit</th>
                                <th>Decimal</th>
                                <th>Multiply</th>
                                <th>Edit</th>
                            </tr>
                            <?php 
                            $i_c=0;
                            // echo $modelName;
                            // echo json_encode($rgl_arr);
                            if (isset($rgl_arr[$modelName])) {
                                // echo "Key does not exist or value is null";

                                if($is_bac_model){
                                    $bac_first = !empty($rgl_arr[$modelName]) ? array_values($rgl_arr[$modelName])[0] : array();
                                    if(is_array($bac_first) && !array_key_exists('name', $bac_first)) {
                                        $rgl_arr[$modelName] = isset($rgl_arr[$modelName][$rgl_function]) && is_array($rgl_arr[$modelName][$rgl_function])
                                            ? $rgl_arr[$modelName][$rgl_function] : array();
                                    }

                                }
                                elseif(isset($rgl_arr[$modelName]['holding']) || isset($rgl_arr[$modelName]['input'])){
                                    $rgl_arr[$modelName] = isset($rgl_arr[$modelName][$rgl_bank]) && is_array($rgl_arr[$modelName][$rgl_bank])
                                        ? $rgl_arr[$modelName][$rgl_bank] : array();
                                }
                                // ksort(array_keys($rgl_arr[$modelName]));
                                if(array_key_exists($modelName,$rgl_arr)){
                                    ksort($rgl_arr[$modelName]);
                                }
                                foreach($rgl_arr[$modelName] as $k=>$v){
                                    $i_c++;
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" class="rgl-del-check" value="<?php echo $k;?>"></td>
                                        <td><?php echo $i_c;?></td>
                                        <!-- <td><input type="text" class="form-control" id="model-register-<?php echo $modelName;?>-<?php echo $k;?>" value="<?php echo $k;?>" disabled> </td> -->
                                        <td><span class="cell-badge badge-id"><?php echo $k;?></span></td>
                                        <td>
                                            <?php
                                                $reg_name = is_array($v) ? (string)($v['name'] ?? '') : '';
                                                $reg_friendly = ($reg_name !== '' && isset($param_name_map[$reg_name])) ? $param_name_map[$reg_name] : '';
                                            ?>
                                            <?php if($reg_friendly !== ''){ ?>
                                            <div style="font-size:11.5px;color:#94a3b8;margin-bottom:2px;"><?php echo htmlspecialchars($reg_friendly);?></div>
                                            <?php } ?>
                                            <input type="text" class="form-control" id="model-name-<?php echo $modelName;?>-<?php echo $k;?>" value="<?php echo htmlspecialchars($reg_name, ENT_QUOTES);?>" style="min-width:120px;font-family:'SFMono-Regular',Consolas,monospace;">
                                        </td>
                                        <td>
                                            <select  class="form-select" name="" id="model-type-<?php echo $modelName;?>-<?php echo $k;?>" style="min-width:100px;text-align:center;">
                                                <option value="<?php echo htmlspecialchars((string)($v['type'] ?? ''), ENT_QUOTES);?>"><?php echo htmlspecialchars((string)($v['type'] ?? '—'));?></option>
                                                <option value="float32">float32</option>
                                                <option value="float32 inverse">float32 inverse</option>
                                                <option value="int sign16">int sign16</option>
                                                <option value="int unsign16">int unsign16</option>
                                                <option value="int16 binary">int16 binary</option>
                                                <option value="long unsign32">long unsign32</option>
                                                <option value="long unsign32 inverse">long unsign32 inverse</option>
                                                <option value="long sign32">long sign32</option>
                                                <option value="long unsign64">long unsign64</option>
                                                <option value="long sign64">long sign64</option>
                                            </select>
                                        </td>
                                        <td><input type="text" class="form-control" id="model-unit-<?php echo $modelName;?>-<?php echo $k;?>" value="<?php echo htmlspecialchars((string)($v['unit'] ?? ''), ENT_QUOTES);?>" style="min-width:100px;text-align:center;"> </td>
                                        <td><input type="text" class="form-control" id="model-decimal-<?php echo $modelName;?>-<?php echo $k;?>" value="<?php echo htmlspecialchars((string)($v['decimal'] ?? ''), ENT_QUOTES);?>" style="min-width:100px;text-align:center;"> </td>
                                        <td><input type="text" class="form-control" id="model-multiply-<?php echo $modelName;?>-<?php echo $k;?>" value="<?php echo htmlspecialchars((string)($v['multiply'] ?? 1), ENT_QUOTES);?>" style="min-width:100px;text-align:center;"> </td>
                                        <td>
                                            <div style="display:flex;">
                                                <button class="btn btn-primary" onclick="save_rgl('<?php echo $modelName;?>','<?php echo $k;?>')" title="บันทึก register นี้"><i class="fa-solid fa-floppy-disk"></i></button>
                                                &nbsp
                                                <button class="btn btn-danger" onclick="del_rgl('<?php echo $modelName;?>','<?php echo $k;?>')" title="ลบ register นี้"><i class="fa-regular fa-trash-can"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                        </table>
                        <?php if($modelName === ''){ ?>
                        <div class="model-empty">
                            <i class="fa-solid fa-arrow-up-long"></i>
                            <p>เลือกโมเดลด้านบน หรือกด <b>+ new model</b> เพื่อเริ่มสร้างโมเดลใหม่</p>
                        </div>
                        <?php }elseif($reg_count === 0){ ?>
                        <div class="model-empty">
                            <i class="fa-regular fa-folder-open"></i>
                            <p>โมเดล <b><?php echo htmlspecialchars($modelName);?></b> ยังไม่มี register — กด <b>+ add RGL</b> เพื่อเพิ่ม</p>
                        </div>
                        <?php } ?>
                </div>
                <?php if($modelName !== '' && $reg_count > 0){ ?>
                <button class="fab-save" id="fabSaveAll" onclick="save_all_rgl('<?php echo $modelName;?>')" title="Save registers">
                    <i class="fa-solid fa-floppy-disk"></i><span>Save</span>
                </button>
                <?php } ?>
            <?php } elseif($page_list == "modellist"){ ?>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;flex-wrap:wrap;gap:8px;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span class="cell-badge badge-model"><i class="fa-solid fa-microchip"></i>&nbsp;<?php echo count($rgl_arr);?> Models</span>
                        <label class="model-picker-label"><i class="fa-solid fa-filter"></i> Type</label>
                        <select class="form-select" id="modelTypeFilter" onchange="filterModelType()">
                            <option value="">ทั้งหมด</option>
                            <?php foreach($model_types as $tn=>$ta){ ?>
                            <option value="<?php echo $tn;?>"><?php echo $tn.' ('.$ta.')';?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;">
                        <?php if($rglFtpCanUpload){ ?>
                        <div><button class="btn btn-primary" id="rglFtpUploadButton" type="button" onclick="uploadRglToFtp()" title="ส่ง config/rgl.json ไปให้ MDBIoT API ตรวจสอบและอัปเดต"><i class="fa-solid fa-cloud-arrow-up"></i> ส่ง rgl.json ไป API</button></div>
                        <?php } ?>
                        <div ><button class="btn btn-success" onclick="add_model()"><i class="fa-solid fa-plus"></i> new model</button></div>
                    </div>
                </div>
                <div class="table-scroll">
                    <table id="customers" style="border-radius:8px;">
                        <tr>
                            <th>No.</th>
                            <th>Model</th>
                            <th>Type</th>
                            <th>Registers</th>
                            <th>Act</th>
                        </tr>
                        <?php
                        $i_ml = 0;
                        ksort($rgl_arr);
                        foreach($rgl_arr as $ml_name=>$ml_regs){
                            $i_ml++;
                            // register count (bac models nest one level deeper)
                            $ml_r = $ml_regs;
                            if(is_array($ml_r) && (isset($ml_r['holding']) || isset($ml_r['input']))){
                                $ml_rc = count(isset($ml_r['holding']) && is_array($ml_r['holding']) ? $ml_r['holding'] : array())
                                    + count(isset($ml_r['input']) && is_array($ml_r['input']) ? $ml_r['input'] : array());
                            } else {
                            if(preg_match('/bac/',$ml_name) && is_array($ml_r) && !empty($ml_r)){ $ml_r = array_values($ml_r)[0]; }
                            $ml_rc = is_array($ml_r) ? count($ml_r) : 0;
                            }
                            $ml_abbr = isset($detail_arr[$ml_name]['abbr']) ? $detail_arr[$ml_name]['abbr'] : '';
                            $ml_type = isset($detail_arr[$ml_name]['type']) ? $detail_arr[$ml_name]['type'] : '';
                        ?>
                        <tr data-type="<?php echo htmlspecialchars($ml_type, ENT_QUOTES);?>">
                            <td><?php echo $i_ml;?></td>
                            <td><span class="cell-badge badge-model"><?php echo htmlspecialchars($ml_name);?></span></td>
                            <td>
                                <?php if($ml_abbr !== ''){ ?>
                                <span class="cell-badge badge-type" title="<?php echo htmlspecialchars($ml_type);?>"><?php echo htmlspecialchars($ml_abbr);?></span>
                                <?php }else{ ?>
                                <span style="color:#94a3b8;">—</span>
                                <?php } ?>
                            </td>
                            <td><span class="cell-badge badge-id"><?php echo $ml_rc;?></span></td>
                            <td>
                                <div style="display:flex;justify-content:center;">
                                    <button class="btn btn-warning" onclick="window.location.href='index.php?page=device&pg=model&modelName=<?php echo urlencode($ml_name);?>'" title="แก้ไข model นี้ (register/type)"><i class="fa-regular fa-pen-to-square"></i></button>&nbsp;
                                    <button class="btn btn-danger" onclick="del_model('<?php echo htmlspecialchars($ml_name, ENT_QUOTES);?>')" title="ลบ model นี้"><i class="fa-regular fa-trash-can"></i></button>
                                </div>
                            </td>
                        </tr>
                        <?php } ?>
                    </table>
                    <?php if(empty($rgl_arr)){ ?>
                    <div class="model-empty">
                        <i class="fa-regular fa-folder-open"></i>
                        <p>ยังไม่มี model — กด <b>+ new model</b> เพื่อเริ่มสร้าง</p>
                    </div>
                    <?php } ?>
                </div>
            <?php } elseif($page_list == "cmlall"){ ?>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;flex-wrap:wrap;gap:8px;">
                    <span class="cell-badge badge-model"><i class="fa-solid fa-layer-group"></i>&nbsp;<?php echo count($cml_arr);?> CML</span>
                    <div style="display:flex;justify-content: flex-end;">
                        <div ><button class="btn btn-info" onclick="window.location.href='index.php?page=device&pg=meter'"><i class="fa-solid fa-list"></i> Meter List</button></div>
                        &nbsp;
                        <div ><button class="btn btn-danger" onclick="clear_cml()"><i class="fa-solid fa-trash-can"></i> Clear All</button></div>
                    </div>
                </div>
                <div class="table-scroll">
                    <table id="customers" style="border-radius:8px;">
                        <tr>
                            <th>No.</th>
                            <th>CMD ID</th>
                            <th>Model</th>
                            <th>Function</th>
                            <th>Meter S/N</th>
                            <th>Register start - end</th>
                            <th>Act</th>
                        </tr>
                        <?php 
                        $i_c=0;
                        foreach($cml_arr as $k=>$v){
                            $i_c++;
                            ?>
                        <tr>
                            <td><?php echo $i_c;?></td>
                            <td><span class="cell-badge badge-id"><?php echo $k;?></span></td>
                            <td>
                                <?php $cml_model = $dvl_arr[array_keys($v[array_keys($v)[0]])[0]]['model'] ?? ''; ?>
                                <span class="cell-badge badge-model"><?php echo $cml_model;?></span>
                                <?php
                                    $cml_abbr = isset($detail_arr[$cml_model]['abbr']) ? $detail_arr[$cml_model]['abbr'] : '';
                                    $cml_type = isset($detail_arr[$cml_model]['type']) ? $detail_arr[$cml_model]['type'] : '';
                                    if($cml_abbr !== ''){
                                ?>
                                <span class="cell-badge badge-type" title="<?php echo htmlspecialchars($cml_type);?>"><?php echo $cml_abbr;?></span>
                                <?php } ?>
                            </td>

                            <td>
                                <select class="form-select" name="" id="val-fn-<?php echo $k?>">
                                    <option value="" selected>-</option>
                                    <!-- <option value="<?php echo array_keys($v)[0];?>" selected><?php echo array_keys($v)[0];?></option>
                                    <option value="read_holding_tcp">read_holding_tcp</option>
                                    <option value="read_holding_rtu">read_holding_rtu</option> -->


                                    <?php if(array_keys($v)[0] == "read_holding_tcp"){?>
                                    <option value="<?php echo array_keys($v)[0];?>" selected><?php echo array_keys($v)[0];?></option>
                                    <?php }else{ ?>
                                    <option value="read_holding_tcp">read_holding_tcp</option>
                                    <?php } ?>

                                    <?php if(array_keys($v)[0] == "read_input_tcp"){?>
                                    <option value="<?php echo array_keys($v)[0];?>" selected><?php echo array_keys($v)[0];?></option>
                                    <?php }else{ ?>
                                    <option value="read_input_tcp">read_input_tcp</option>
                                    <?php } ?>


                                    <?php if(array_keys($v)[0] == "read_holding_rtu"){?>
                                    <option value="<?php echo array_keys($v)[0];?>" selected><?php echo array_keys($v)[0];?></option>
                                    <?php }else{ ?>
                                    <option value="read_holding_rtu">read_holding_rtu</option>
                                    <?php } ?>

                                    <?php if(array_keys($v)[0] == "read_input_rtu"){?>
                                    <option value="<?php echo array_keys($v)[0];?>" selected><?php echo array_keys($v)[0];?></option>
                                    <?php }else{ ?>
                                    <option value="read_input_rtu">read_input_rtu</option>
                                    <?php } ?>

                                    <?php if(array_keys($v)[0] == "recv_holding_rtu"){?>
                                    <option value="<?php echo array_keys($v)[0];?>" selected><?php echo array_keys($v)[0];?></option>
                                    <?php }else{ ?>
                                    <option value="recv_holding_rtu">recv_holding_rtu</option>
                                    <?php } ?>

                                </select>
                            </td>
                            <td>
                                <input type="text" class="form-control" name="" id="val-meterSN-<?php echo $k?>" value="<?php echo array_keys($v[array_keys($v)[0]])[0];?>">
                                
                            </td>
                            <td>
                                <?php 
                                // echo 'model :'.$dvl_arr[$meterSN]['model']."\n";
                                // echo json_encode($v[array_keys($v)[0]][$meterSN]);
                                // echo $k."==".array_keys($v)[0]."==".json_encode($v[array_keys($v)[0]]);
                                // echo json_encode($v[array_keys($v)[0]][array_keys($v[array_keys($v)[0]])[0]][0]);
                                /*
                                foreach($rgl_arr[$dvl_arr[$meterSN]['model']] as $k2=>$v2){

                                    if($k2 >= $v[array_keys($v)[0]][$meterSN][0] && $k2 <= $v[array_keys($v)[0]][$meterSN][1] ){
                                ?>
                                <div><label><input type="checkbox" name="items" value="<?php echo $k2;?>" checked> <?php echo '['.$k2.']'.$v2['name'];?></label></div>
                                <?php
                                    }else{
                                    ?>
                                <div><label><input type="checkbox" name="items" value="<?php echo $k2;?>" > <?php echo '['.$k2.']'.$v2['name'];?></label></div>
                                <?php }} */?>
                                <div class="rng-group">
                                    <span class="rng-label">Start</span>
                                    <input type="text" class="form-control" id="val-start-<?php echo $k?>" value="<?php echo $v[array_keys($v)[0]][array_keys($v[array_keys($v)[0]])[0]][0];?>" placeholder="start">
                                    <span class="rng-label">End</span>
                                    <input type="text" class="form-control" id="val-end-<?php echo $k?>" value="<?php echo $v[array_keys($v)[0]][array_keys($v[array_keys($v)[0]])[0]][1];?>" placeholder="end">
                                </div>
                            </td>
                            <td>
                                <div style="display:flex;">
                                    <button class="btn btn-dark" onclick="save_cml('<?php echo array_keys($v[array_keys($v)[0]])[0];?>','<?php echo $k?>','<?php echo array_keys($v)[0];?>')" title="บันทึก CML นี้"><i class="fa-solid fa-floppy-disk"></i></button>&nbsp;
                                    <button class="btn btn-dark" onclick="del_cml('<?php echo array_keys($v[array_keys($v)[0]])[0];?>','<?php echo $k?>','<?php echo array_keys($v)[0];?>')" title="ลบ CML นี้"><i class="fa-regular fa-trash-can"></i></button>
                                </div>
                            </td>
                        </tr>
                        <?php } ?>

                    </table>
                    <?php if(empty($cml_arr)){ ?>
                    <div class="model-empty">
                        <i class="fa-regular fa-folder-open"></i>
                        <p>ยังไม่มี CML ในระบบ — เพิ่มได้จากหน้า <b>Device</b> (ปุ่ม +CML)</p>
                    </div>
                    <?php } ?>
                    </div>
            <?php } ?>
        </div>
    </div>

    
</div>


<script>
function syncDeviceSelection() {
    const boxes = Array.from(document.querySelectorAll('.device-delete-check'));
    const selected = boxes.filter(box => box.checked);
    const selectAll = document.getElementById('selectAllDevices');
    const button = document.getElementById('bulkDeleteDevices');
    const count = document.getElementById('bulkDeleteCount');
    if (count) count.textContent = selected.length;
    if (button) button.disabled = selected.length === 0;
    if (selectAll) {
        selectAll.checked = boxes.length > 0 && selected.length === boxes.length;
        selectAll.indeterminate = selected.length > 0 && selected.length < boxes.length;
    }
}

document.querySelectorAll('.device-delete-check').forEach(box => box.addEventListener('change', syncDeviceSelection));
document.getElementById('selectAllDevices')?.addEventListener('change', event => {
    document.querySelectorAll('.device-delete-check').forEach(box => { box.checked = event.target.checked; });
    syncDeviceSelection();
});

async function deleteSelectedDevices() {
    const selected = Array.from(document.querySelectorAll('.device-delete-check:checked')).map(box => box.value);
    if (!selected.length) return;
    const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const confirmation = await Swal.fire({
        title: `Delete ${selected.length} devices?`,
        html: '<div style="max-height:180px;overflow:auto;text-align:left;font-size:.8rem">' + selected.map(id => '<div>• '+escapeHtml(id)+'</div>').join('') + '</div><small style="display:block;margin-top:10px">Command List entries belonging to these devices will also be removed.</small>',
        icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Delete selected', cancelButtonText: 'Cancel', confirmButtonColor: '#d33'
    });
    if (!confirmation.isConfirmed) return;
    Swal.fire({title:'Deleting devices…', allowOutsideClick:false, showConfirmButton:false, didOpen:()=>Swal.showLoading()});
    try {
        const response = await fetch('./fn_api.php?action=device_del_bulk', {
            method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({meter_SN:selected})
        });
        const result = await response.json();
        if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Delete failed');
        await Swal.fire({icon:'success', title:'Deleted', text:`Removed ${result.deleted_count} devices and ${result.deleted_commands} command entries.`, timer:1400, showConfirmButton:false});
        location.reload();
    } catch (error) {
        Swal.fire({icon:'error', title:'Delete failed', text:error.message});
    }
}

function syncCmlSelection() {
    const boxes = Array.from(document.querySelectorAll('.cml-delete-check'));
    const selected = boxes.filter(box => box.checked);
    const selectAll = document.getElementById('selectAllCml');
    const button = document.getElementById('bulkDeleteCml');
    const count = document.getElementById('bulkDeleteCmlCount');
    if (count) count.textContent = selected.length;
    if (button) button.disabled = selected.length === 0;
    if (selectAll) {
        selectAll.checked = boxes.length > 0 && selected.length === boxes.length;
        selectAll.indeterminate = selected.length > 0 && selected.length < boxes.length;
    }
}

document.querySelectorAll('.cml-delete-check').forEach(box => box.addEventListener('change', syncCmlSelection));
document.getElementById('selectAllCml')?.addEventListener('change', event => {
    document.querySelectorAll('.cml-delete-check').forEach(box => { box.checked = event.target.checked; });
    syncCmlSelection();
});

async function deleteSelectedCml(meterSN) {
    const selected = Array.from(document.querySelectorAll('.cml-delete-check:checked')).map(box => box.value);
    if (!selected.length) return;
    const confirmation = await Swal.fire({
        title: `Delete ${selected.length} command lists?`,
        text: 'Only commands belonging to '+meterSN+' will be removed.',
        icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Delete selected', cancelButtonText: 'Cancel', confirmButtonColor: '#d33'
    });
    if (!confirmation.isConfirmed) return;
    try {
        const response = await fetch('./fn_api.php?action=cml_del_bulk', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body:JSON.stringify({meter_SN:meterSN, cmdids:selected})
        });
        const result = await response.json();
        if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Delete failed');
        await Swal.fire({icon:'success', title:'Deleted', text:`Removed ${result.deleted_count} command lists.`, timer:1200, showConfirmButton:false});
        location.reload();
    } catch (error) {
        Swal.fire({icon:'error', title:'Delete failed', text:error.message});
    }
}

const tasks = document.querySelectorAll(".task");

// Load saved state
tasks.forEach((task, index) => {
    task.checked = localStorage.getItem("task" + index) === "true";
    task.addEventListener("change", () => {
        localStorage.setItem("task" + index, task.checked);
    });
});

let allChecked = false;
function selectAll() {
    const tasks = document.querySelectorAll(".task");
    allChecked = !allChecked;

    tasks.forEach(task => task.checked = allChecked);

    document.getElementById("toggleButton").textContent = allChecked ? "✖ Uncheck All" : "✔ Select All";
}

function check_info(data) {
    // Swal.fire(JSON.stringify(data));
    // console.log(JSON.stringify(data));
    console.log(data);
    Swal.fire({
        title: "<strong>"+data.name+"</strong>",
        // icon: "info",
        html: `
            <div style="display:flex;justify-content:center;flex-direction: column;">
                <div style="display:flex;"><p>Model :</p><p>${data.model}</p></div>
                <div style="display:flex;"><p>Mode :</p><p>${data.mode}</p></div>
                <div style="display:flex;"><p>IP :</p><p>${data.ipaddress}</p></div>
                <div style="display:flex;"><p>ID :</p><p>${data.deviceID}</p>
            </div>
        `,
        showCloseButton: true,
        showCancelButton: false,
        focusConfirm: false,
        confirmButtonText: `
            <i class="fa fa-thumbs-up"></i> OK
        `,
        cancelButtonAriaLabel: "Thumbs down"
    });
    
}

function show_cml(metersn,data) {
    (async () => {
        console.log(data);
        let cml_txt = '<?php echo json_encode($cml_arr,256);?>'
        let cml_arr;
        if(cml_txt){ cml_arr = JSON.parse(cml_txt); } 
        // console.log(cml_arr);
        let rowCount = 0;
        let output_html = '<div class="info-scroll"><table class="styled-table" style="font-size:13px;">';
        output_html += '<tr>';
        output_html += '<th>CMD ID</th>';
        output_html += '<th>Function</th>';
        output_html += '<th style="text-wrap:nowrap;">Register [start, end]</th>';
        output_html += '</tr>';

        Object.keys(cml_arr).forEach(key => {
            if(metersn in Object.values(cml_arr[key])[0]){
                rowCount++;
                output_html += `<tr >
                <td style="text-wrap:nowrap;">${key}</td>
                <td style="text-wrap:nowrap;">${Object.keys(cml_arr[key])[0]}</td>
                <td style="text-wrap:nowrap;">${Object.values(cml_arr[key])[0][metersn]}</td>
                </tr>`;
            }
        });
        output_html += '</table></div>';
        if(rowCount === 0){
            output_html = '<div class="info-empty"><i class="fa-regular fa-folder-open"></i><p>มิเตอร์นี้ยังไม่มี CML</p></div>';
        }

        const infoRow = (label, val) =>
            `<div class="info-row"><span class="info-key">${label}</span><span class="info-val">${(val ?? '') !== '' ? val : '-'}</span></div>`;

        const { value: formValues } = await Swal.fire({
            title: `<div style="font-size:20px;font-weight:700;color:#064a99;">${metersn}</div>
                    <div style="font-size:13px;color:#64748b;font-weight:500;margin-top:2px;">command list</div>`,
            width: 640,
            background: "#fff",
            html:
                `<div class="info-card">
                    ${infoRow('Model', data.model)}
                    ${infoRow('Mode', data.mode)}
                    ${infoRow('IP', data.ipaddress)}
                    ${infoRow('ID', data.deviceID)}
                </div>`+
                output_html
                ,
            showCloseButton: true,
            focusConfirm: false,
            confirmButtonText: "OK",
            customClass: { popup: "swal-info-popup" }
        })

    })()

}


// document.getElementById('table_body').innerHTML = data_html;
function toggleGroup(groupName) {

    const rows = document.querySelectorAll(`.group-${groupName}`);

    rows.forEach(row => {
        row.classList.toggle('hidden-row');
    });
}

function info_meter(model,data) {
    let data_html;
    const bacnetObjectTypes = [
        'analogInput',
        'analogOutput',
        'analogValue',
        'binaryInput',
        'binaryOutput',
        'binaryValue',
        'multiStateInput',
        'multiStateOutput',
        'multiStateValue'
    ];
    data_html = '';
    Object.keys(data).forEach(key => {
        const functionRegisters = data[key];
        const isRegisterFunction =
            functionRegisters &&
            typeof functionRegisters === 'object' &&
            !Array.isArray(functionRegisters) &&
            !Object.prototype.hasOwnProperty.call(functionRegisters, 'name');

        // Modbus banks (input/holding) and BACnet object types are Function headers.
        // Their child keys are the actual register addresses.
        if (bacnetObjectTypes.includes(key) || isRegisterFunction) {
            data_html +=
            `<tr class="group-header" onclick="toggleGroup('${key}')">
                <td colspan="4">
                    <div class="header-flex">
                        <span class="function-title">
                            <i class="fa-solid fa-layer-group"></i>
                            <small>FUNCTION</small>
                            <b>${key}</b>
                        </span>

                        <i id="icon-${key}"
                        class="fa-solid fa-chevron-down function-chevron">
                        </i>
                    </div>
                </td>
            </tr>`;
            Object.keys(functionRegisters).forEach(address => {
                const register = functionRegisters[address] || {};
             data_html += `
                <tr  class="group-${key}">
                    <td>${address}</td>
                    <td>${register.name ?? '-'}</td>
                    <td>${typeof register.type === 'string' ? register.type : (register.type?.type ?? '-')}</td>
                    <td>${register.unit ?? '-'}</td>
                </tr>`;
            });
        }else{
            const register = data[key] || {};
            data_html += `<tr><td>${key}</td><td>${register.name ?? '-'}</td><td>${typeof register.type === 'string' ? register.type : (register.type?.type ?? '-')}</td><td>${register.unit ?? '-'}</td></tr>`;

        }
        
    });

    Swal.fire({
        title: `<div style="font-size:20px;font-weight:700;color:#064a99;">${model}</div>
                <div style="font-size:13px;color:#64748b;font-weight:500;margin-top:2px;">register map</div>`,
        width: 720,
        html: `
            <div class="info-scroll">
            <table class="styled-table" style="">
                <tr>
                    <th>Address</th>
                    <th>Name</th>
                    <th>Data type</th>
                    <th>Unit</th>
                </tr>
                ${data_html}
            </table>
            </div>
        `,
        showCloseButton: true,
        showCancelButton: true,
        focusConfirm: false,
        confirmButtonText: "OK",
        cancelButtonText: '<i class="fa-regular fa-pen-to-square"></i> Edit Register Map',
        customClass: { popup: "swal-info-popup" }
    }).then(result => {
        if (result.dismiss === Swal.DismissReason.cancel) {
            window.location.href =
                'index.php?page=device&pg=model&modelName=' + encodeURIComponent(model);
        }
    });

}

function add_device(meterSN) {

    (async () => {
        let select_option="";
        select_option += '<div class="cfg-field"><label class="cfg-label">Model</label><select class="form-select" id="model_">';
        <?php foreach($rgl_arr as $k=>$v){?>
            select_option += '<option value="<?php echo $k;?>" ><?php echo $k;?></option>';
        <?php } ?>
        select_option+='</select></div>'

        let baudRates = [300,600,1200,2400,4800,9600,19200,38400,57600,115200,230400];
        let baud_options = baudRates.map(function(b){ return '<option value="'+b+'"'+(b===9600?' selected':'')+'>'+b+'</option>'; }).join('');

        let input_mode = "";
        input_mode += '<div id="add-mb-rtu" class="cfg-section" style="display:none;"><h5>RTU</h5><div class="cfg-grid">';
        input_mode += '<div class="cfg-field"><label class="cfg-label">Baudrate</label><select class="form-select" id="baurdrate_">'+baud_options+'</select></div>';
        input_mode += '<div class="cfg-field"><label class="cfg-label">Parity</label><select class="form-select" id="parity_"><option value="">-</option><option value="none">none</option><option value="even">even</option></select></div>';
        input_mode += '</div></div>';

        input_mode += '<div id="add-mb-tcp" class="cfg-section" style="display:none;"><h5>TCP</h5><div class="cfg-grid">';
        input_mode += '<div class="cfg-field"><label class="cfg-label">IP Address</label><input type="text" class="form-control" id="ipaddress_" value="192.168.0.100"></div>';
        input_mode += '<div class="cfg-field"><label class="cfg-label">Port</label><input type="text" class="form-control" id="port_" value="502"></div>';
        input_mode += '</div></div>';

        input_mode += '<div id="add-mb-udp" class="cfg-section" style="display:none;"><h5>UDP</h5><div class="cfg-grid">';
        input_mode += '<div class="cfg-field"><label class="cfg-label">IP Address</label><input type="text" class="form-control" id="ipaddress_" value="192.168.0.100"></div>';
        input_mode += '<div class="cfg-field"><label class="cfg-label">Port</label><input type="text" class="form-control" id="port_" value="47808"></div>';
        input_mode += '</div></div>';

        input_mode += '<div id="add-mb-bacnet" class="cfg-section" style="display:none;"><h5>BACNET</h5><div class="cfg-grid">';
        input_mode += '<div class="cfg-field"><label class="cfg-label">BACnet/IP Address</label><input type="text" class="form-control" id="bacnet_ipaddress_" value="192.168.0.100"></div>';
        input_mode += '<div class="cfg-field"><label class="cfg-label">BACnet Port</label><input type="number" min="1" max="65535" step="1" class="form-control" id="bacnet_port_" value="47808"></div>';
        input_mode += '</div></div>';

        const { value: formValues } = await Swal.fire({
        title: 'Config Meter',
        background: "#fff ",
        html:
            '<div class="cfg-form">'+
            '<div class="cfg-field"><label class="cfg-label">Serial number</label><input type="text" class="form-control" id="sn_" value=""></div>'+
            '<div class="cfg-field"><label class="cfg-label">Name</label><input type="text" class="form-control" id="name_" value=""></div>'+
            select_option+
            '<div class="cfg-field"><label class="cfg-label">Mode</label><select class="form-select" id="mode_" onchange="add_selectMode()">'+
            '<option value="" >-</option>'+
            '<option value="rtu" >rtu</option>'+
            '<option value="tcp" >tcp</option>'+
            '<option value="udp" >udp</option>'+
            '<option value="bacnet" >BACNET</option>'+
            '</select></div>'+
            input_mode+
            '<div class="cfg-field"><label class="cfg-label">Device ID</label><input type="text" class="form-control" id="deviceID_" value="1"></div>'+
            '</div>'
            ,
        didOpen: () => { window.mselEnhanceIn(Swal.getHtmlContainer()); },
        focusConfirm: true,
        preConfirm: () => {
            let res_arr = new Object();
            res_arr.meter_SN = String(document.getElementById('sn_').value);
            res_arr.name = String(document.getElementById('name_').value);
            res_arr.deviceID = String(document.getElementById('deviceID_').value);
            res_arr.mode = String(document.getElementById('mode_').value);
            res_arr.model = String(document.getElementById('model_').value);
            if(res_arr.mode == "rtu"){
                res_arr.baurdrate = String(document.getElementById('baurdrate_').value);
                res_arr.parity = String(document.getElementById('parity_').value);
            }
            if(res_arr.mode == "tcp"){
                res_arr.ipaddress = String(document.getElementById('ipaddress_').value);
                res_arr.port = String(document.getElementById('port_').value);
            }
            if(res_arr.mode == "bacnet"){
                res_arr.ipaddress = String(document.getElementById('bacnet_ipaddress_').value);
                const bacnetPort = Number(document.getElementById('bacnet_port_').value);
                if (!Number.isInteger(bacnetPort) || bacnetPort < 1 || bacnetPort > 65535) {
                    Swal.showValidationMessage('BACnet Port ต้องเป็นเลขตั้งแต่ 1 ถึง 65535');
                    return false;
                }
                res_arr.port = String(bacnetPort);
            }
            // console.log(res_arr);
            


            return res_arr;
        }
        })

        if(formValues){
            // Swal.fire(JSON.stringify(formValues))
            Swal.fire({
                title: "Json{}",
                width: 600,
                text:JSON.stringify(formValues),
                padding: "3em",
                color: "#716add",
                background: "#fff url(../asset/img/trees.png)",
                backdrop: `
                    rgba(0,0,123,0.4)
                    url("../asset/img/nyan-cat.gif")
                    left top
                    no-repeat
                `,
                showDenyButton: true,
                // showCancelButton: true,
                confirmButtonText: 'Yes',
                denyButtonText: 'No',
                customClass: {
                    actions: 'my-actions',
                    cancelButton: 'order-1 right-gap',
                    confirmButton: 'order-2',
                    denyButton: 'order-3',
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // statement ajax save to DB
                    // Swal.fire('Saved!', '', 'success')
                    console.log(formValues);
                    
                    let timerInterval;
                    
                    $.ajax({  
                        method: 'post',
                        url: "./fn_api.php?action=device_add&meter_SN="+formValues.meter_SN,
                        data: JSON.stringify(formValues),
                        success: function(response) {
                            console.log(response);
                            Swal.fire({
                                title: "Auto close alert!",
                                html: "I will close in <b></b> milliseconds.",
                                timer: 1000,
                                timerProgressBar: true,
                                didOpen: () => {
                                    Swal.showLoading();
                                    const timer = Swal.getPopup().querySelector("b");
                                    timerInterval = setInterval(() => {
                                    timer.textContent = `${Swal.getTimerLeft()}`;
                                    }, 100);
                                },
                                willClose: () => {
                                    clearInterval(timerInterval);
                                    location.reload('refresh:1');
                                }
                                }).then((result) => {
                                /* Read more about handling dismissals below */
                                if (result.dismiss === Swal.DismissReason.timer) {
                                    // console.log("I was closed by the timer");
                                }
                            });
                            // setTimeout( function() { location.reload('refresh:1'); }, 200);
                        }
                    });

                    
                } else if (result.isDenied) {
                    // Swal.fire('Changes are not saved', '', 'info')
                }
            });
        }
    })()
}

function cmlParamsForMode(modelParams, mode){
    if(Array.isArray(modelParams)) return modelParams;
    if(modelParams && Array.isArray(modelParams[mode])) return modelParams[mode];
    if(String(mode).indexOf('input') >= 0 && Array.isArray(modelParams.input)) return modelParams.input;
    if(Array.isArray(modelParams.holding)) return modelParams.holding;
    return [];
}

// BACnet stores an explicit object-instance list, while Modbus stores the
// traditional [start,end] range. Keep both shapes intact when editing.
function show_cml_params(cmlid, meterSN, fn, currentData){
    function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    var model  = (window.CML_DEV_MODEL || {})[meterSN] || '';
    var modelParams = (window.CML_MODELS || {})[model] || [];
    var isBacnet = String((window.CML_DEV_PROTOCOL || {})[meterSN] || '').toLowerCase() === 'bacnet';
    var params = cmlParamsForMode(modelParams, fn);
    var savedValues = Array.isArray(currentData) ? currentData.map(function(v){ return parseInt(v,10); }).filter(Number.isInteger) : [];
    var curStart = savedValues.length ? savedValues[0] : 0;
    var curEnd = savedValues.length > 1 ? savedValues[1] : curStart + 1;
    if(!Number.isInteger(curStart)) curStart = 0;
    if(!Number.isInteger(curEnd) || curEnd <= curStart) curEnd = curStart + 1;
    var checked = {};
    function selectCurrent(){
        checked = {};
        params.forEach(function(p){
            var selected = isBacnet ? savedValues.indexOf(Number(p.reg)) >= 0 : (p.reg >= curStart && p.reg < curEnd);
            if(selected) checked[String(p.reg)] = {reg:p.reg,width:cmlRegWidth(p.type)};
        });
    }
    selectCurrent();
    var fnList = ['read_holding_rtu','read_input_rtu','read_holding_tcp','read_input_tcp','recv_holding_rtu','analogInput','analogOutput','analogValue','binaryInput','binaryOutput','binaryValue','multiStateInput','multiStateOutput','multiStateValue'];
    if(fn && fnList.indexOf(fn) < 0){ fnList.unshift(fn); }
    var fnOpts = fnList.map(function(f){ return '<option value="'+f+'"'+(f===fn?' selected':'')+'>'+f+'</option>'; }).join('');

    (async () => {
        const { value: res } = await Swal.fire({
            title: '<div style="font-size:19px;font-weight:700;color:#064a99;">แก้ไขค่าที่อ่าน</div>' +
                   '<div style="font-size:13px;color:#64748b;font-weight:500;margin-top:2px;">' + esc(cmlid) + '</div>',
            width: 600,
            background: "#fff",
            customClass: { popup: 'cml-edit-popup', htmlContainer: 'cml-edit-content' },
             html: '<div class="cfg-form" style="text-align:left;">'+
                   '<div class="cfg-field"><label class="cfg-label">Mode / Function</label><select class="form-select" id="cmle-fn">'+fnOpts+'</select></div>'+ 
                  (isBacnet ? '<small class="cfg-hint">BACnet แสดงและบันทึกเฉพาะ Object ที่เลือกจริง ไม่รวม Object ที่อยู่ระหว่างช่วง</small>' :
                   '<div class="cfg-grid cml-range-grid">'+
                     '<div class="cfg-field"><label class="cfg-label">Register start</label><input type="number" min="0" max="65535" step="1" class="form-control" id="cmle-start" value="'+curStart+'"></div>'+ 
                     '<div class="cfg-field"><label class="cfg-label">Register end</label><input type="number" min="1" max="65536" step="1" class="form-control" id="cmle-end" value="'+curEnd+'"></div>'+ 
                   '</div>'+ 
                  '<small class="cfg-hint">End เป็นตำแหน่งถัดจาก Register ตัวสุดท้าย · จำนวนที่อ่าน = End − Start (สูงสุด 120)</small>')+ 
                  '<div class="cfg-field cml-parameter-field"><label class="cfg-label">Parameters</label>'+
                  '<input type="text" class="form-control" id="cmle-search" placeholder="ค้นหาชื่อ / register" autocomplete="off">'+
                  '<div class="cml-param-bar"><label class="cml-selectall"><input type="checkbox" id="cmle-all"> เลือกทั้งหมด</label><span class="cml-count" id="cmle-count"></span></div>'+
                  '<div class="cml-param-list" id="cmle-list"></div>'+
                  '</div>'+
                  '<div class="cml-preview" id="cmle-preview"></div>'+
                  '</div>',
            showCancelButton: true, confirmButtonText: 'บันทึก', cancelButtonText: 'ปิด', focusConfirm: false,
            didOpen: () => {
                window.mselEnhanceIn(Swal.getHtmlContainer());
                var listEl = document.getElementById('cmle-list'), prevEl = document.getElementById('cmle-preview'),
                    searchEl = document.getElementById('cmle-search'), allEl = document.getElementById('cmle-all'), countEl = document.getElementById('cmle-count'),
                     fnEl = document.getElementById('cmle-fn'), startEl = document.getElementById('cmle-start'), endEl = document.getElementById('cmle-end');
                function selected(){ var o = []; Object.keys(checked).forEach(function(k){ if(checked[k]) o.push(checked[k]); }); return o.sort(function(a,b){return a.reg-b.reg;}); }
                function saveChecked(){ listEl.querySelectorAll('input[type=checkbox]').forEach(function(cb){ var r = cb.getAttribute('data-reg'); checked[r] = cb.checked ? {reg: parseInt(r,10), width: parseInt(cb.getAttribute('data-width'),10)} : null; }); }
                function renderList(){ var q = (searchEl.value||'').toLowerCase(); listEl.innerHTML=''; params.forEach(function(p){ var label = p.name||p.code||('reg '+p.reg); if(q && label.toLowerCase().indexOf(q)<0 && String(p.reg).indexOf(q)<0 && String(p.code||'').toLowerCase().indexOf(q)<0) return; var w = cmlRegWidth(p.type); var ck = checked[String(p.reg)] ? 'checked':''; var row=document.createElement('label'); row.className='cml-param'; row.innerHTML='<input type="checkbox" data-reg="'+p.reg+'" data-width="'+w+'" '+ck+'><span class="cml-param-name">'+esc(label)+'</span><span class="cml-param-meta">'+p.reg+' · '+esc(p.type||'-')+' · '+w+' reg</span>'; listEl.appendChild(row); }); if(!listEl.children.length){ listEl.innerHTML='<div class="cml-param-empty">ไม่พบ Parameter สำหรับ Mode นี้ · สามารถกรอกช่วง Register เองได้</div>'; } }
                function syncAll(){ var b = listEl.querySelectorAll('input[type=checkbox]'), on=0; b.forEach(function(c){ if(c.checked) on++; }); allEl.checked=(b.length>0 && on===b.length); allEl.indeterminate=(on>0 && on<b.length); countEl.textContent='เลือก '+selected().length+' / '+params.length; }
                function previewRange(){ if(isBacnet){var ids=selected().map(function(p){return p.reg;});prevEl.innerHTML='<div class="cml-preview-head">BACnet Objects: '+ids.length+'</div><div class="cml-preview-cmd">'+(ids.length?ids.join(', '):'ยังไม่เลือก Object')+'</div>';return;} var s=parseInt(startEl.value,10),e=parseInt(endEl.value,10),span=e-s; var warning=!Number.isInteger(s)||!Number.isInteger(e)?'กรอก Start และ End เป็นจำนวนเต็ม':(s<0||e<=s?'End ต้องมากกว่า Start':(span>120?'เกิน 120 register — ต้องแยก Command':'')); prevEl.innerHTML='<div class="cml-preview-head">ช่วง Register: '+(Number.isInteger(s)?s:'—')+'–'+(Number.isInteger(e)?e:'—')+' <span class="cml-preview-len">('+(Number.isInteger(span)?span:'—')+' regs)</span></div>'+(warning?'<div class="cml-range-warning"><i class="fa-solid fa-triangle-exclamation"></i> '+warning+'</div>':'<div class="cml-range-ok"><i class="fa-solid fa-circle-check"></i> พร้อมบันทึก</div>'); }
                function refreshFromSelection(){ saveChecked(); syncAll(); var sel=selected(); if(!isBacnet&&sel.length){ var mn=Infinity,mx=-Infinity; sel.forEach(function(p){ if(p.reg<mn)mn=p.reg; var e=p.reg+p.width; if(e>mx)mx=e; }); startEl.value=mn; endEl.value=mx; } previewRange(); }
                renderList(); syncAll(); previewRange();
                listEl.addEventListener('change', refreshFromSelection);
                searchEl.addEventListener('input', function(){ saveChecked(); renderList(); syncAll(); });
                allEl.addEventListener('change', function(){ listEl.querySelectorAll('input[type=checkbox]').forEach(function(c){ c.checked = allEl.checked; }); refreshFromSelection(); });
                if(startEl)startEl.addEventListener('input', previewRange);
                if(endEl)endEl.addEventListener('input', previewRange);
                fnEl.addEventListener('change', function(){ params=cmlParamsForMode(modelParams,fnEl.value); if(!isBacnet){curStart=parseInt(startEl.value,10);curEnd=parseInt(endEl.value,10);} selectCurrent(); renderList(); syncAll(); previewRange(); });
            },
            preConfirm: () => {
                var newFn = document.getElementById('cmle-fn').value;
                if(isBacnet){document.querySelectorAll('#cmle-list input[type=checkbox]').forEach(function(cb){var reg=cb.getAttribute('data-reg');checked[reg]=cb.checked?{reg:parseInt(reg,10),width:1}:null;});var objects=Object.keys(checked).filter(function(key){return checked[key];}).map(function(key){return parseInt(key,10);}).filter(Number.isInteger).sort(function(a,b){return a-b;});if(!objects.length){Swal.showValidationMessage('เลือก BACnet Object อย่างน้อย 1 รายการ');return false;}return {data:objects,fn:newFn};}
                var start = Number(document.getElementById('cmle-start').value);
                var end = Number(document.getElementById('cmle-end').value);
                if(!newFn){ Swal.showValidationMessage('เลือก Function'); return false; }
                if(!Number.isInteger(start) || !Number.isInteger(end)){ Swal.showValidationMessage('Start และ End ต้องเป็นจำนวนเต็ม'); return false; }
                if(start < 0 || start > 65535){ Swal.showValidationMessage('Start ต้องอยู่ระหว่าง 0–65535'); return false; }
                if(end <= start || end > 65536){ Swal.showValidationMessage('End ต้องมากกว่า Start และไม่เกิน 65536'); return false; }
                if(end - start > 120){ Swal.showValidationMessage('ช่วงอ่านต้องไม่เกิน 120 register — ลดช่วงหรือแยก Command'); return false; }
                return { data:[start,end], fn:newFn };
            }
        });
        if(res){
            $.ajax({
                method: 'post',
                url: "./fn_api.php?action=cml_add&meter_SN=" + encodeURIComponent(meterSN),
                data: JSON.stringify({ cmdid:cmlid,fn:res.fn,data:res.data }),
                dataType: 'json',
                success: function(response){
                    if(!response || response.status !== 'ok'){
                        Swal.fire('บันทึกไม่สำเร็จ', (response && response.message) || 'Server ไม่ยืนยันการบันทึก', 'error');
                        return;
                    }
                    Swal.fire({ icon:'success', title:'บันทึก CML แล้ว', text:response.fn+' · '+response.data.length+(isBacnet?' objects':' values'), timer:1100, showConfirmButton:false }).then(function(){ location.reload(); });
                },
                error: function(xhr){ var message=(xhr.responseJSON&&xhr.responseJSON.message)||'ไม่สามารถบันทึก cml.json ได้'; Swal.fire('บันทึกไม่สำเร็จ',message,'error'); }
            });
        }
    })();
}

// meterSN -> model, and model -> [{reg,code,name,type}] for the CML parameter picker
window.CML_MODELS = <?php echo json_encode($cml_models, JSON_UNESCAPED_UNICODE); ?>;
window.CML_DEV_MODEL = <?php echo json_encode($cml_dev_model, JSON_UNESCAPED_UNICODE); ?>;
window.CML_DEV_PROTOCOL = <?php echo json_encode($cml_dev_protocol, JSON_UNESCAPED_UNICODE); ?>;
window.CML_MODEL_DEVICES = <?php echo json_encode($cml_model_devices, JSON_UNESCAPED_UNICODE); ?>;

// Modbus register width by data type: int16 = 1, float32/long32 = 2, long64 = 4
function cmlRegWidth(type){
    var t = String(type || '').toLowerCase();
    if(t.indexOf('64') >= 0) return 4;
    if(t.indexOf('32') >= 0) return 2;
    return 1;
}
// Group selected Modbus registers into commands. Only adjacent/overlapping
// register spans may share a command; a gap always starts another command.
// A span over 120 registers is also split to respect the Modbus limit.
function cmlBuildCommands(sel, isBacnet){
    sel = sel.slice().sort(function(a,b){ return a.reg - b.reg; });
    // BACnet has no contiguous register-range read. Keep each selected object
    // as its own CML command, including gaps such as 1,2,4.
    if(isBacnet){
        return sel.map(function(p){ return {start:p.reg, end:p.reg, count:1, bacnet:true}; });
    }
    var cmds = [], cur = null;
    sel.forEach(function(p){
        // end is exclusive (last register + width) to match the runner:
        // it reads length = end - start registers, i.e. start..end-1.
        var ps = p.reg, pe = p.reg + p.width;
        if(cur === null){ cur = {start: ps, end: pe, count: 1}; }
        else if(ps <= cur.end && pe - cur.start <= 120){ if(pe > cur.end) cur.end = pe; cur.count++; }
        else { cmds.push(cur); cur = {start: ps, end: pe, count: 1}; }
    });
    if(cur) cmds.push(cur);
    return cmds;
}

// Build the checkbox list + live command preview inside the add-CML dialog
window.cmlCurrentCommands = [];
window.cmlSetupParamPicker = function(params, isBacnet){
    var listEl = document.getElementById('cml-param-list');
    var prevEl = document.getElementById('cml-preview');
    var searchEl = document.getElementById('cml-param-search');
    var selAllEl = document.getElementById('cml-select-all');
    var countEl = document.getElementById('cml-count');
    if(!listEl || !prevEl) return;
    function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    var checked = {}; // reg -> {reg,width} kept across search re-renders

    function saveChecked(){
        listEl.querySelectorAll('input[type=checkbox]').forEach(function(cb){
            var reg = cb.getAttribute('data-reg');
            checked[reg] = cb.checked ? {reg: parseInt(reg,10), width: parseInt(cb.getAttribute('data-width'),10)} : null;
        });
    }
    function selected(){
        var out = []; Object.keys(checked).forEach(function(k){ if(checked[k]) out.push(checked[k]); }); return out;
    }
    function renderList(){
        var q = (searchEl && searchEl.value || '').toLowerCase();
        listEl.innerHTML = '';
        params.forEach(function(p, idx){
            var label = p.name || p.code || ('reg ' + p.reg);
            if(q && label.toLowerCase().indexOf(q) < 0 && String(p.reg).indexOf(q) < 0 && String(p.code||'').toLowerCase().indexOf(q) < 0) return;
            var w = cmlRegWidth(p.type);
            var isChecked = checked[String(p.reg)] ? 'checked' : '';
            var row = document.createElement('label');
            row.className = 'cml-param';
            row.innerHTML = '<input type="checkbox" data-reg="' + p.reg + '" data-width="' + w + '" ' + isChecked + '>' +
                '<span class="cml-param-name">' + esc(label) + '</span>' +
                '<span class="cml-param-meta">' + p.reg + ' · ' + esc(p.type || '-') + ' · ' + w + ' reg</span>';
            listEl.appendChild(row);
        });
    }
    function syncSelectAll(){
        var boxes = listEl.querySelectorAll('input[type=checkbox]');
        var total = boxes.length, on = 0;
        boxes.forEach(function(cb){ if(cb.checked) on++; });
        if(selAllEl){
            selAllEl.checked = (total > 0 && on === total);
            selAllEl.indeterminate = (on > 0 && on < total);
        }
        if(countEl){ countEl.textContent = 'เลือก ' + selected().length + ' / ' + params.length; }
    }
    function refresh(){
        saveChecked();
        syncSelectAll();
        var sel = selected();
        var cmds = cmlBuildCommands(sel, isBacnet);
        window.cmlCurrentCommands = cmds;
        if(!sel.length){ prevEl.innerHTML = '<span class="cml-preview-empty">ยังไม่เลือกพารามิเตอร์</span>'; return; }
        var html = '<div class="cml-preview-head"><i class="fa-solid fa-list-check"></i> จะสร้าง ' + cmds.length + ' command (' + sel.length + ' พารามิเตอร์)</div>';
        cmds.forEach(function(c, i){
            html += '<div class="cml-preview-cmd"><b>Command ' + (i+1) + '</b> · register ' + c.start + '–' + c.end +
                    ' <span class="cml-preview-len">(' + (c.end - c.start) + ' regs · ' + c.count + ' param)</span></div>';
        });
        prevEl.innerHTML = html;
    }

    renderList();
    listEl.addEventListener('change', refresh);
    if(searchEl){ searchEl.addEventListener('input', function(){ saveChecked(); renderList(); syncSelectAll(); }); }
    if(selAllEl){ selAllEl.addEventListener('change', function(){
        // check/uncheck every currently-visible (filtered) row
        listEl.querySelectorAll('input[type=checkbox]').forEach(function(cb){ cb.checked = selAllEl.checked; });
        refresh();
    }); }
    refresh();
};

function add_cml(meterSN){
    (async () => {
        var model  = (window.CML_DEV_MODEL || {})[meterSN] || '';
        var isBacnet = ((window.CML_DEV_PROTOCOL || {})[meterSN] || '').toLowerCase() === 'bacnet';
        var configuredSources = ((window.CML_MODEL_DEVICES || {})[model] || []).filter(function(device){
            return device.sn !== meterSN && Number(device.command_count || 0) > 0 &&
                String(device.mode || '').toLowerCase() === ((window.CML_DEV_PROTOCOL || {})[meterSN] || '').toLowerCase();
        });
        var sourceDeviceHtml = '';
        if(configuredSources.length){
            var sourceOptions = configuredSources.map(function(device){
                var label = String(device.sn)+' - '+String(device.name)+' ('+Number(device.command_count)+' commands)';
                return '<option value="'+String(device.sn).replace(/[&<>"']/g,'')+'">'+label.replace(/[&<>"']/g,'')+'</option>';
            }).join('');
            sourceDeviceHtml = '<div class="cml-copy-source"><label class="cfg-label">Copy commands from configured device</label>'+
                '<select class="form-select" id="cml-source-device"><option value="">Create command manually</option>'+sourceOptions+'</select>'+
                '<small>Select a configured device with the same Model and Mode to copy all of its commands.</small></div>';
        }
        var modelParams = (window.CML_MODELS || {})[model] || [];
        var isBanked = !Array.isArray(modelParams);
        var defaultFn = isBacnet ? Object.keys(modelParams).find(function(key){return Array.isArray(modelParams[key])&&modelParams[key].length;}) || 'analogInput' : 'read_holding_rtu';
        var params = cmlParamsForMode(modelParams, defaultFn);
        var hasParams = Array.isArray(modelParams)
            ? modelParams.length > 0
            : Object.keys(modelParams).some(function(key){return Array.isArray(modelParams[key])&&modelParams[key].length>0;});
        var fnOpts =
            '<option value="read_holding_rtu">read_holding_rtu</option>'+
            '<option value="read_input_rtu">read_input_rtu</option>'+
            '<option value="read_holding_tcp">read_holding_tcp</option>'+
            '<option value="read_input_tcp">read_input_tcp</option>'+
            '<option value="recv_holding_rtu">recv_holding_rtu</option>'+
            '<option value="analogInput">analogInput</option>'+
            '<option value="analogOutput">analogOutput</option>'+
            '<option value="analogValue">analogValue</option>'+ 
            '<option value="binaryInput">binaryInput</option>'+ 
            '<option value="binaryOutput">binaryOutput</option>'+ 
            '<option value="binaryValue">binaryValue</option>'+ 
            '<option value="multiStateInput">multiStateInput</option>'+
            '<option value="multiStateOutput">multiStateOutput</option>'+
            '<option value="multiStateValue">multiStateValue</option>';

        var body;
        if(hasParams){
            body = '<div class="cfg-form" style="text-align:left;">'+
                '<div class="cfg-field"><label class="cfg-label">Function</label><select class="form-select" id="mode_cml">'+fnOpts+'</select></div>'+
                sourceDeviceHtml+
                '<div class="cfg-field"><label class="cfg-label">Parameters — '+ (model||'-') +'</label>'+
                    '<input type="text" class="form-control" id="cml-param-search" placeholder="ค้นหาชื่อ / register" autocomplete="off">'+
                    '<div class="cml-param-bar"><label class="cml-selectall"><input type="checkbox" id="cml-select-all"> เลือกทั้งหมด</label><span class="cml-count" id="cml-count"></span></div>'+
                    '<div class="cml-param-list" id="cml-param-list"></div>'+
                '</div>'+
                '<div class="cml-preview" id="cml-preview"></div>'+
                '</div>';
        } else {
            // model has no registers in rgl.json -> fall back to manual start/end
            body = '<div class="cfg-form" style="text-align:left;">'+
                '<div class="cfg-field"><label class="cfg-label">Function</label><select class="form-select" id="mode_cml">'+fnOpts+'</select></div>'+
                sourceDeviceHtml+
                '<div class="cml-preview">โมเดล "'+ (model||'-') +'" ยังไม่มี register ในระบบ — กรอกช่วงเอง</div>'+
                '<div class="cfg-grid">'+
                    '<div class="cfg-field"><label class="cfg-label">Start</label><input type="text" class="form-control" id="start_cml" value="0"></div>'+
                    '<div class="cfg-field"><label class="cfg-label">End</label><input type="text" class="form-control" id="end_cml" value="1"></div>'+
                '</div>'+
                '</div>';
        }

        const { value: formValues } = await Swal.fire({
            title: 'Add Command List',
            width: 640,
            background: "#fff",
            html: body,
            showCancelButton: true,
            confirmButtonText: 'Add',
            cancelButtonText: 'Cancel',
            focusConfirm: false,
            didOpen: () => {
                window.mselEnhanceIn(Swal.getHtmlContainer());
                if(hasParams){
                    const fnEl = document.getElementById('mode_cml');
                    if(isBacnet && Array.from(fnEl.options).some(function(option){return option.value===defaultFn;})) fnEl.value=defaultFn;
                    const loadBank = function(){
                        params = cmlParamsForMode(modelParams,fnEl.value);
                        ['cml-param-list','cml-param-search','cml-select-all'].forEach(function(id){
                            const oldEl = document.getElementById(id);
                            if(oldEl) oldEl.replaceWith(oldEl.cloneNode(true));
                        });
                        window.cmlSetupParamPicker(params, isBacnet);
                    };
                    fnEl.addEventListener('change', loadBank);
                    loadBank();
                }
            },
            preConfirm: () => {
                var fn = document.getElementById('mode_cml').value;
                var sourceDevice = document.getElementById('cml-source-device');
                if(sourceDevice && sourceDevice.value){
                    return { meter_SN: meterSN, clone_from: sourceDevice.value };
                }
                if(hasParams){
                    var cmds = window.cmlCurrentCommands || [];
                    if(!cmds.length){ Swal.showValidationMessage('เลือกอย่างน้อย 1 พารามิเตอร์'); return false; }
                    return { meter_SN: meterSN, fn: fn, commands: cmds.map(function(c){ return [c.start, c.end]; }) };
                } else {
                    var s = parseInt(document.getElementById('start_cml').value, 10);
                    var e = parseInt(document.getElementById('end_cml').value, 10);
                    if(isNaN(s) || isNaN(e)){ Swal.showValidationMessage('กรอก Start / End'); return false; }
                    return { meter_SN: meterSN, fn: fn, commands: [[s, e]] };
                }
            }
        });

        if(formValues){
            $.ajax({
                method: 'post',
                url: "./fn_api.php?action=cml_add_multi&meter_SN=" + encodeURIComponent(formValues.meter_SN),
                data: JSON.stringify(formValues),
                success: function(response){
                    console.log(response);
                    Swal.fire({ icon: "success", title: "เพิ่ม CML แล้ว", timer: 900, showConfirmButton: false })
                        .then(function(){ location.reload('refresh:1'); });
                },
                error: function(xhr){ console.log(xhr); Swal.fire("เพิ่มไม่สำเร็จ","","error"); }
            });
        }
    })()
}

function check_edit(data,keys) {
    // Swal.fire(JSON.stringify(data));
    // console.log(JSON.stringify(data));
    let names = data.name;
    let baurdrate = data.baurdrate != undefined ? data.baurdrate:9600;
    let parity = data.parity != undefined ? data.parity:"none";
    let ipaddress = (data.ipaddress != undefined && data.ipaddress !== "") ? data.ipaddress : "192.168.0.100";
    let fn_mode = data.mode != undefined ? data.mode:"rtu";
    let port_tcp = (data.port != undefined && data.port !== "") ? data.port:(String(fn_mode).toLowerCase() === "bacnet" ? 47808 : 502);
    let deviceID = data.deviceID != undefined ? data.deviceID:1;
    let input_mode ="";
    let baudRates = [300,600,1200,2400,4800,9600,19200,38400,57600,115200,230400];
    let baud_options = baudRates.map(function(b){ return '<option value="'+b+'"'+(String(baurdrate) === String(b) ? ' selected' : '')+'>'+b+'</option>'; }).join('');
    // keep a non-standard stored value selectable instead of silently losing it
    if(baurdrate !== '' && baurdrate != undefined && baudRates.indexOf(Number(baurdrate)) === -1){
        baud_options = '<option value="'+baurdrate+'" selected>'+baurdrate+'</option>' + baud_options;
    }



    // if(ipaddress == "" && baurdrate){fn_mode="rtu";}
    // if(ipaddress){fn_mode="tcp";}

    let select_option = '<div class="cfg-field"><label class="cfg-label">Model</label><select class="form-select" id="model_'+keys+'">';
    <?php foreach($rgl_arr as $k=>$v){?>
        if(data.model == '<?php echo $k;?>'){
            select_option += '<option value="<?php echo $k;?>" selected><?php echo $k;?></option>';
        }else{
            select_option += '<option value="<?php echo $k;?>" ><?php echo $k;?></option>';
        }
    <?php } ?>
    select_option+='</select></div>'
    let status_display_tcp = "";
    let status_display_rtu = "";
    let mode = (fn_mode || 'rtu').toLowerCase();
    let select_mode = `
        <div class="cfg-field">
            <label class="cfg-label">Mode</label>
            <select class="form-select" id="mode_${keys}" onchange="selectMode('${keys}')">
                <option value="">-</option>
                <option value="rtu" ${mode === 'rtu' ? 'selected' : ''}>RTU</option>
                <option value="tcp" ${mode === 'tcp' ? 'selected' : ''}>TCP</option>
                <option value="udp" ${mode === 'udp' ? 'selected' : ''}>UDP</option>
                <option value="bacnet" ${mode === 'bacnet' ? 'selected' : ''}>BACNET</option>
            </select>
        </div>`;
    

        input_mode += `
            <div id="mb-rtu_${keys}" class="cfg-section">
                <h5>RTU</h5>
                <div class="cfg-grid">
                    <div class="cfg-field">
                        <label class="cfg-label">Baudrate</label>
                        <select class="form-select" id="baurdrate_${keys}">${baud_options}</select>
                    </div>
                    <div class="cfg-field">
                        <label class="cfg-label">Parity</label>
                        <select class="form-select" id="parity_${keys}">
                            <option value="">-</option>
                            <option value="none" ${parity?.toLowerCase() === 'none' ? 'selected' : ''}>None</option>
                            <option value="even" ${parity?.toLowerCase() === 'even' ? 'selected' : ''}>Even</option>
                            <option value="odd" ${parity?.toLowerCase() === 'odd' ? 'selected' : ''}>Odd</option>
                        </select>
                    </div>
                </div>
            </div>`;

        input_mode += `
            <div id="mb-tcp_${keys}" class="cfg-section">
                <h5>TCP</h5>
                <div class="cfg-grid">
                    <div class="cfg-field">
                        <label class="cfg-label">IP Address</label>
                        <input type="text" class="form-control" id="ipaddress_tcp_${keys}" value="${ipaddress}">
                    </div>
                    <div class="cfg-field">
                        <label class="cfg-label">Port</label>
                        <input type="text" class="form-control" id="port_tcp_${keys}" value="${port_tcp}">
                    </div>
                </div>
            </div>`;

        input_mode += `
            <div id="mb-udp_${keys}" class="cfg-section">
                <h5>UDP</h5>
                <div class="cfg-grid">
                    <div class="cfg-field">
                        <label class="cfg-label">IP Address</label>
                        <input type="text" class="form-control" id="ipaddress_udp_${keys}" value="${ipaddress}">
                    </div>
                    <div class="cfg-field">
                        <label class="cfg-label">Port</label>
                        <input type="text" class="form-control" id="port_udp_${keys}" value="${port_tcp}">
                    </div>
                </div>
            </div>`;

        input_mode += `
            <div id="mb-bacnet_${keys}" class="cfg-section">
                <h5>BACNET</h5>
                <div class="cfg-grid">
                    <div class="cfg-field">
                        <label class="cfg-label">BACnet/IP Address</label>
                        <input type="text" class="form-control" id="ipaddress_bacnet_${keys}" value="${ipaddress}">
                    </div>
                    <div class="cfg-field">
                        <label class="cfg-label">BACnet Port</label>
                        <input type="number" min="1" max="65535" step="1" class="form-control" id="port_bacnet_${keys}" value="${port_tcp}">
                    </div>
                </div>
            </div>`;

    // console.log(data,keys);
    (async () => {
        const { value: formValues } = await Swal.fire({
        title: 'Config Meter',
        background: "#fff ",
        html:
            '<div class="cfg-form">'+
            '<div class="cfg-field"><label class="cfg-label">Serial number</label><input type="text" class="form-control" id="sn_'+keys+'" value="'+keys+'"></div>'+
            '<div class="cfg-field"><label class="cfg-label">Name</label><input type="text" class="form-control" id="name_'+keys+'" value="'+names+'"></div>'+
            select_option+
            select_mode+
            input_mode+
            '<div class="cfg-field"><label class="cfg-label">Device ID</label><input type="text" class="form-control" id="deviceID_'+keys+'" value="'+deviceID+'"></div>'+
            '</div>'
            ,
        didOpen: () => {
            window.mselEnhanceIn(Swal.getHtmlContainer());
            selectMode(keys);
        },
        focusConfirm: true,
        preConfirm: () => {
            // et update_all = {name:String(m_name),devtype:String(m_modeltype),mainconSN:String(m_mainConfig),modelID:String(m_modelID),comtype:String(m_comtype),ipaddress:String(m_ipaddress),maddress:String(m_maddress),mainconID:parseInt(m_mainconID),company_id:parseInt(m_companyID),priority:parseInt(m_priority),baudrate:String(m_baudrate),deleted:0,deviceStatus:'N'};
            // serialnumber:String(devSN),
            let res_arr = new Object();
            res_arr.meter_SN = String(document.getElementById('sn_'+keys).value);
            res_arr.name = String(document.getElementById('name_'+keys).value);
            res_arr.model = String(document.getElementById('model_'+keys).value);
            res_arr.mode = String(document.getElementById('mode_'+keys).value);
            if(res_arr.mode == "rtu"){
                res_arr.baurdrate = String(document.getElementById('baurdrate_'+keys).value);
                res_arr.parity = String(document.getElementById('parity_'+keys).value);
            }
            if(res_arr.mode == "tcp"){
                // form renders these inputs as ipaddress_tcp_/port_tcp_ (see input_mode above)
                res_arr.ipaddress = String(document.getElementById('ipaddress_tcp_'+keys).value);
                res_arr.port = String(document.getElementById('port_tcp_'+keys).value);
            }
            if(res_arr.mode === "udp"){
                // res_arr.ipaddress = $('#ipaddress_udp_' + keys).val();
                // res_arr.port = $('#port_udp_' + keys).val();
                res_arr.ipaddress = String(document.getElementById('ipaddress_udp_'+keys).value);
                res_arr.port = String(document.getElementById('port_udp_'+keys).value);
            }
            if(res_arr.mode === "bacnet"){
                res_arr.ipaddress = String(document.getElementById('ipaddress_bacnet_'+keys).value);
                const bacnetPort = Number(document.getElementById('port_bacnet_'+keys).value);
                if (!Number.isInteger(bacnetPort) || bacnetPort < 1 || bacnetPort > 65535) {
                    Swal.showValidationMessage('BACnet Port ต้องเป็นเลขตั้งแต่ 1 ถึง 65535');
                    return false;
                }
                res_arr.port = String(bacnetPort);
            }
            res_arr.deviceID = String(document.getElementById('deviceID_'+keys).value);
            // return {meter_SN:String(document.getElementById('sn_'+keys).value),name:String(document.getElementById('name_'+keys).value),model:String(document.getElementById('model_'+keys).value),mode:String(document.getElementById('mode_'+keys).value),deleted:0}
            // console.log(res_arr);
            return res_arr;
        }
        })

        if(formValues){
            // Swal.fire(JSON.stringify(formValues))
            Swal.fire({
                title: "Json{}",
                width: 600,
                text:JSON.stringify(formValues),
                padding: "3em",
                color: "#716add",
                background: "#fff url(../asset/img/trees.png)",
                backdrop: `
                    rgba(0,0,123,0.4)
                    url("../asset/img/nyan-cat.gif")
                    left top
                    no-repeat
                `,
                showDenyButton: true,
                // showCancelButton: true,
                confirmButtonText: 'Yes',
                denyButtonText: 'No',
                customClass: {
                    actions: 'my-actions',
                    cancelButton: 'order-1 right-gap',
                    confirmButton: 'order-2',
                    denyButton: 'order-3',
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // statement ajax save to DB
                    // Swal.fire('Saved!', '', 'success')
                    // console.log(formValues);
                    
                    let timerInterval;
                    
                    $.ajax({  
                        method: 'post',
                        url: "./fn_api.php?action=device_add&meter_SN="+keys,
                        data: JSON.stringify(formValues),
                        success: function(response) {
                            console.log(response);
                            Swal.fire({
                                title: "Auto close alert!",
                                html: "I will close in <b></b> milliseconds.",
                                timer: 1000,
                                timerProgressBar: true,
                                didOpen: () => {
                                    Swal.showLoading();
                                    const timer = Swal.getPopup().querySelector("b");
                                    timerInterval = setInterval(() => {
                                    timer.textContent = `${Swal.getTimerLeft()}`;
                                    }, 100);
                                },
                                willClose: () => {
                                    clearInterval(timerInterval);
                                    location.reload('refresh:1');
                                }
                                }).then((result) => {
                                /* Read more about handling dismissals below */
                                if (result.dismiss === Swal.DismissReason.timer) {
                                    // console.log("I was closed by the timer");
                                }
                            });
                            // setTimeout( function() { location.reload('refresh:1'); }, 200);
                        }
                    });

                    
                } else if (result.isDenied) {
                    // Swal.fire('Changes are not saved', '', 'info')
                }
            });
        }
    })()

    
    
}

function updateFile(keys) {
    // console.log(keys);
    // $.ajax({  
    //     type: 'GET',
    //     url: "./fn_api.php?action=device_del",
    //     data: 'meter_SN='+keys,
    //     success: function(response) {
    //         console.log(response);
    //         setTimeout( function() { location.reload('refresh:1'); }, 200);
    //     }
    // });
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: "btn btn-danger swal-danger",
            cancelButton: "btn btn-secondary swal-cancel"
        },
        buttonsStyling: false
        });
        swalWithBootstrapButtons.fire({
        title: "Are you sure?",
        text: "You won't be able to revert this!",
        icon: "warning",
        showCancelButton: true,
        cancelButtonText: "No, cancel!",
        confirmButtonText: "Yes, delete it!",
        reverseButtons: false
        }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({  
                type: 'GET',
                url: "./fn_api.php?action=device_del",
                data: 'meter_SN='+keys,
                success: function(response) {
                    console.log(response);
                    swalWithBootstrapButtons.fire({
                        title: "Deleted!",
                        text: "Your file has been deleted.",
                        icon: "success",
                        timer: 500,
                        timerProgressBar: true,
                        didOpen: () => {
                            Swal.showLoading();
                            const timer = Swal.getPopup().querySelector("b");
                            timerInterval = setInterval(() => {
                                timer.textContent = `${Swal.getTimerLeft()}`;
                            }, 1000);
                        },
                        willClose: () => {
                            clearInterval(timerInterval);
                            location.reload('refresh:1');
                        }

                    });
                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
            
        } else if (
            /* Read more about handling dismissals below */
            result.dismiss === Swal.DismissReason.cancel
        ) {
            swalWithBootstrapButtons.fire({
            title: "Cancelled",
            text: "Your imaginary file is safe :)",
            icon: "error"
            });
        }
    });
}

function select_model(){
    let model_name = $('#selectModel').val();
    console.log(model_name);
    window.location.href='index.php?page=device&pg=model&modelName='+model_name;
}

/* ============================================================
   Custom modern dropdown enhancer.
   Wraps every native <select> on the device page with a styled
   button + floating panel, while keeping the native <select> in
   the DOM (hidden) so all existing .val()/onchange code works.
   ============================================================ */
(function(){
    var CARET = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m2 5 6 6 6-6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var openPanel = null;

    function closeOpen(){
        if(openPanel){
            openPanel.panel.classList.remove('show');
            openPanel.wrap.classList.remove('open');
            openPanel = null;
        }
    }

    function enhance(select){
        if(select.dataset.mselDone) return;
        select.dataset.mselDone = '1';

        var wrap = document.createElement('div');
        var sizeClass = select.id === 'selectModel' ? ' msel--model'
            : (select.id === 'rglBank' ? ' msel--bank'
            : (select.id === 'modelType' ? ' msel--type' : ''));
        wrap.className = 'msel' + sizeClass;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'msel-btn';
        var label = document.createElement('span');
        label.className = 'msel-label';
        var caret = document.createElement('span');
        caret.className = 'msel-caret';
        caret.innerHTML = CARET;
        btn.appendChild(label);
        btn.appendChild(caret);

        var panel = document.createElement('div');
        panel.className = 'msel-panel';
        document.body.appendChild(panel); // escape card/table overflow

        function syncLabel(){
            var o = select.options[select.selectedIndex];
            label.textContent = o ? o.textContent : '';
        }

        function buildOptions(){
            panel.innerHTML = '';
            Array.prototype.forEach.call(select.options, function(opt, i){
                var row = document.createElement('div');
                row.className = 'msel-option' + (i === select.selectedIndex ? ' selected' : '');
                row.textContent = opt.textContent;
                row.addEventListener('click', function(e){
                    e.stopPropagation();
                    select.selectedIndex = i;
                    syncLabel();
                    closeOpen();
                    // fire change so inline onchange handlers run (select_model, etc.)
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                });
                panel.appendChild(row);
            });
        }

        function place(){
            var r = btn.getBoundingClientRect();
            panel.style.minWidth = r.width + 'px';
            panel.style.left = r.left + 'px';
            panel.style.top = (r.bottom + 5) + 'px';
            // flip above if not enough room below
            var ph = panel.offsetHeight;
            if(r.bottom + 5 + ph > window.innerHeight && r.top - 5 - ph > 0){
                panel.style.top = (r.top - 5 - ph) + 'px';
            }
        }

        function open(){
            closeOpen();
            buildOptions();
            wrap.classList.add('open');
            panel.classList.add('show');
            openPanel = { panel: panel, wrap: wrap };
            place();
            // keep selected option in view
            var sel = panel.querySelector('.msel-option.selected');
            if(sel) sel.scrollIntoView({ block: 'nearest' });
        }

        btn.addEventListener('click', function(e){
            e.stopPropagation();
            if(openPanel && openPanel.panel === panel){ closeOpen(); }
            else { open(); }
        });
        // clicks inside the panel shouldn't bubble to the global close handler
        panel.addEventListener('click', function(e){ e.stopPropagation(); });

        // mount: move the native select inside wrap (hidden), add button
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        wrap.appendChild(btn);
        syncLabel();
    }

    // Reveal the floating "Save All" only after the user scrolls down a bit.
    var FAB_SCROLL_THRESHOLD = 180;
    function updateFabVisibility(ev){
        var fab = document.getElementById('fabSaveAll');
        if(!fab) return;
        // Ignore scrolls happening inside a dropdown panel's own list
        if(ev && ev.target && ev.target.closest && ev.target.closest('.msel-panel')) return;
        var st;
        if(ev && ev.target && ev.target !== document && ev.target.scrollTop != null){
            st = ev.target.scrollTop;                 // an inner scroll container
        } else {
            st = window.scrollY || document.documentElement.scrollTop || 0;
        }
        fab.classList.toggle('show', st > FAB_SCROLL_THRESHOLD);
    }

    function initAll(){
        document.querySelectorAll('#customers .form-select, #selectModel, #modelType, #modelTypeFilter, #rglBank').forEach(enhance);
        // Float "Save All" relative to the viewport: move it out of .card, whose
        // lingering animation transform would otherwise trap position:fixed to the card.
        var fab = document.getElementById('fabSaveAll');
        if(fab && fab.parentNode !== document.body){ document.body.appendChild(fab); }
        // capture=true so scrolls inside any container (e.g. .card-body) are caught
        window.addEventListener('scroll', updateFabVisibility, true);
        updateFabVisibility();
    }

    // Fixed-position panels don't follow scroll/resize — close them instead.
    // (Ignore scrolls that happen *inside* the panel, e.g. its own option list.)
    document.addEventListener('click', closeOpen);
    window.addEventListener('scroll', function(e){
        if(e.target && e.target.closest && e.target.closest('.msel-panel')) return;
        closeOpen();
    }, true);
    window.addEventListener('resize', closeOpen);

    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    // Enhance selects that appear later than page load (e.g. inside SweetAlert
    // modals). Call from a dialog's didOpen so its <select>s get the same
    // .msel widget as the tables. The native <select> stays (hidden) so
    // .val()/preConfirm and inline onchange handlers keep working.
    window.mselEnhanceIn = function(root){
        if(!root) return;
        root.querySelectorAll('select.form-select, select.form-control').forEach(enhance);
    };
})();

function selectMode(key){

    const mode = $('#mode_' + key).val();

    $('#mb-rtu_' + key).hide();
    $('#mb-tcp_' + key).hide();
    $('#mb-udp_' + key).hide();
    $('#mb-bacnet_' + key).hide();

    switch(mode){
        case 'rtu':
            $('#mb-rtu_' + key).show();
            break;

        case 'tcp':
            $('#mb-tcp_' + key).show();
            break;

        case 'udp':
            $('#mb-udp_' + key).show();
            break;

        case 'bacnet':
            $('#mb-bacnet_' + key).show();
            break;
    }
}

function add_selectMode(){
    
    // let mode = $('#mode_').val();
    // if(mode == 'rtu'){
    //     document.getElementById("add-mb-tcp").style.display = "none";
    //     document.getElementById("add-mb-rtu").style.display = "";
    // }
    // if(mode == 'tcp'){
    //     document.getElementById("add-mb-rtu").style.display = "none";
    //     document.getElementById("add-mb-tcp").style.display = "";
    // }
    // if(mode == 'tcp'){
    //     document.getElementById("add-mb-rtu").style.display = "none";
    //     document.getElementById("add-mb-tcp").style.display = "";
    // }
    // if(mode == ''){
    //     document.getElementById("add-mb-rtu").style.display = "none";
    //     document.getElementById("add-mb-tcp").style.display = "none";
    // }
    const mode = $('#mode_').val();

    $("#add-mb-tcp").hide();
    $("#add-mb-rtu").hide();
    $("#add-mb-udp").hide();
    $("#add-mb-bacnet").hide();

    switch(mode){
        case 'rtu':
            $('#add-mb-rtu').show();
            break;

        case 'tcp':
            $('#add-mb-tcp').show();
            break;

        case 'udp':
            $('#add-mb-udp').show();
            break;

        case 'bacnet':
            $('#add-mb-bacnet').show();
            break;
    }
    
}

function save_cml(meterSN,cmdid,fn){
    let val_start = $('#val-start-'+cmdid).val();
    let val_end = $('#val-end-'+cmdid).val();
    let val_fn = $('#val-fn-'+cmdid).val();
    const startNumber = Number(val_start);
    const endNumber = Number(val_end);
    if(!val_fn || !Number.isInteger(startNumber) || !Number.isInteger(endNumber) || startNumber < 0 || endNumber > 65535 || startNumber > endNumber){
        Swal.fire('ข้อมูลไม่ถูกต้อง','กรุณาเลือก MODE และกำหนด Register เป็นจำนวนเต็ม 0–65535 โดย Start ต้องไม่มากกว่า End','error');
        return;
    }
    console.log(cmdid,val_fn,'[',val_start,',',val_end,']');
    let res_arr = new Object();
    res_arr.cmdid = cmdid;
    res_arr.fn = val_fn;
    res_arr.data = [startNumber,endNumber];
    // res_arr[cmdid] = {functions:[val_start,val_end]};
    console.log(meterSN,res_arr);
    

    Swal.fire({
        title: "Do you want to save the changes?",
        showDenyButton: false,
        showCancelButton: true,
        confirmButtonText: "Save",
        denyButtonText: `Don't save`
    }).then((result) => {
        /* Read more about isConfirmed, isDenied below */
        if (result.isConfirmed) {
            let timerInterval;
            let formValues;

            $.ajax({  
                method: 'post',
                url: "./fn_api.php?action=cml_add&meter_SN="+meterSN,
                data: JSON.stringify(res_arr),
                dataType: 'json',
                success: function(response) {
                    console.log(response);
                    if(!response || response.status !== 'ok'){
                        Swal.fire('บันทึกไม่สำเร็จ',response && response.message ? response.message : 'ระบบไม่สามารถบันทึก CML ได้','error');
                        return;
                    }
                    Swal.fire({
                        title: "Auto close alert!",
                        html: "I will close in <b></b> milliseconds.",
                        timer: 1000,
                        timerProgressBar: true,
                        didOpen: () => {
                            Swal.showLoading();
                            const timer = Swal.getPopup().querySelector("b");
                            timerInterval = setInterval(() => {
                            timer.textContent = `${Swal.getTimerLeft()}`;
                            }, 100);
                        },
                        willClose: () => {
                            clearInterval(timerInterval);
                            location.reload('refresh:1');
                        }
                        }).then((result) => {
                        /* Read more about handling dismissals below */
                        if (result.dismiss === Swal.DismissReason.timer) {
                            // console.log("I was closed by the timer");
                        }
                    });
                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                },
                error: function(xhr) {
                    const message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'ไม่สามารถเชื่อมต่อ API ได้';
                    Swal.fire('บันทึกไม่สำเร็จ',message,'error');
                }
            });
        } else if (result.isDenied) {
            // Swal.fire("Changes are not saved", "", "info");
        }
    });
}

function del_cml(meterSN,cmdid,fn){
    console.log(meterSN,cmdid,fn);
    let timerInterval;
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: "btn btn-danger swal-danger",
            cancelButton: "btn btn-secondary swal-cancel"
        },
        buttonsStyling: false
        });
        swalWithBootstrapButtons.fire({
        title: "Are you sure?",
        text: "You won't be able to revert this!",
        icon: "warning",
        showCancelButton: true,
        cancelButtonText: "No, cancel!",
        confirmButtonText: "Yes, delete it!",
        reverseButtons: false
        }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({  
                type: 'GET',
                url: "./fn_api.php?action=cml_del",
                data: 'cmdid='+cmdid,
                success: function(response) {
                    console.log(response);
                    // swalWithBootstrapButtons.fire({
                    //     title: "Deleted!",
                    //     text: "Your file has been deleted.",
                    //     icon: "success",
                    //     timer: 1500,
                    //     timerProgressBar: true,
                    //     didOpen: () => {
                    //         Swal.showLoading();
                    //         const timer = Swal.getPopup().querySelector("b");
                    //         timerInterval = setInterval(() => {
                    //         timer.textContent = `${Swal.getTimerLeft()}`;
                    //         }, 100);
                    //     },
                    //     willClose: () => {
                    //         clearInterval(timerInterval);
                    //         // location.reload('refresh:1');
                    //     }

                    // });
                    
                    Swal.fire({
                        position: "center",
                        icon: "success",
                        title: "Your work has been saved",
                        showConfirmButton: false,
                        timer: 1500,
                        willClose: () => {
                            location.reload('refresh:1');
                        }
                    });

                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
            
        } else if (
            /* Read more about handling dismissals below */
            result.dismiss === Swal.DismissReason.cancel
        ) {
            swalWithBootstrapButtons.fire({
            title: "Cancelled",
            text: "Your imaginary file is safe :)",
            icon: "error"
            });
        }
    });
}

window.RGL_BANK = <?php echo json_encode($rgl_bank); ?>;
window.RGL_FUNCTION = <?php echo json_encode($rgl_function); ?>;
function select_rgl_bank(){
    const bank = document.getElementById('rglBank').value;
    const url = new URL(window.location.href);
    url.searchParams.set('bank', bank);
    window.location.href = url.toString();
}

function select_rgl_function(){
    const fn = document.getElementById('rglFunction').value;
    const url = new URL(window.location.href);
    url.searchParams.set('fn', fn);
    window.location.href = url.toString();
}

function current_rgl_function(model){
    return /bac/i.test(model) ? window.RGL_FUNCTION : 'read_' + window.RGL_BANK;
}

function save_rgl(model,register){
    let name = $('#model-name-'+model+'-'+register).val();
    let type = $('#model-type-'+model+'-'+register).val();
    let unit = $('#model-unit-'+model+'-'+register).val();
    let decimal = $('#model-decimal-'+model+'-'+register).val();
    let multiply = $('#model-multiply-'+model+'-'+register).val();
    console.log(model,register,name,type,unit);
    let res_arr = new Object();
    let res_arr2 = new Object();
    res_arr.name = name;
    res_arr.type = type;
    res_arr.unit = unit;
    res_arr.decimal = decimal != undefined ? decimal : 3;
    res_arr.multiply = multiply != undefined ? multiply : 1;
    const fnRead = current_rgl_function(model);
    if(/bac/i.test(model)) {
        res_arr2[fnRead] = {};
        res_arr2[fnRead][register] = res_arr;
    } else {
        res_arr2[register] = res_arr;
    }

    Swal.fire({
        title: "Do you want to save the changes?",
        showDenyButton: false,
        showCancelButton: true,
        confirmButtonText: "Save",
        denyButtonText: `Don't save`
    }).then((result) => {
        /* Read more about isConfirmed, isDenied below */
        if (result.isConfirmed) {
            let timerInterval;
            let formValues;

            $.ajax({  
                method: 'post',
                url: "./fn_api.php?action=rgl_add&model="+encodeURIComponent(model)+'&register='+encodeURIComponent(register)+'&fn_read='+encodeURIComponent(fnRead),
                data: JSON.stringify(res_arr2),
                success: function(response) {
                    console.log(response);
                    Swal.fire({
                        title: "Auto close alert!",
                        html: "I will close in <b></b> milliseconds.",
                        timer: 1000,
                        timerProgressBar: true,
                        didOpen: () => {
                            Swal.showLoading();
                            const timer = Swal.getPopup().querySelector("b");
                            timerInterval = setInterval(() => {
                            timer.textContent = `${Swal.getTimerLeft()}`;
                            }, 100);
                        },
                        willClose: () => {
                            clearInterval(timerInterval);
                            // location.reload('refresh:1');
                        }
                        }).then((result) => {
                        /* Read more about handling dismissals below */
                        if (result.dismiss === Swal.DismissReason.timer) {
                            // console.log("I was closed by the timer");
                        }
                    });
                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
        } else if (result.isDenied) {
            // Swal.fire("Changes are not saved", "", "info");
        }
    });

}

async function save_model_type(model){
    let type = $('#modelType').val();
    let abbr = '';
    if(type === '__add__'){
        const meterModels = <?php echo json_encode($meter_model_type_options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const escapeOption = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
        const modelOptions = meterModels.map(item => {
            const status = item.type ? ' — '+(item.abbr || item.type) : ' — ยังไม่มีประเภท';
            return '<option value="'+escapeOption(item.name)+'">'+escapeOption(item.name+status)+'</option>';
        }).join('');
        const result = await Swal.fire({
            title: 'เพิ่มประเภทใหม่',
            html: '<select id="newModelTypeName" class="swal2-select" style="display:flex;width:80%;margin:1em auto"><option value="">— เลือก Meter model —</option>'+modelOptions+'</select>' +
                  '<input id="newModelTypeAbbr" class="swal2-input" maxlength="8" placeholder="ตัวย่อ เช่น PU">',
            showCancelButton: true,
            confirmButtonText: 'เพิ่มและบันทึก',
            cancelButtonText: 'ยกเลิก',
            focusConfirm: false,
            preConfirm: () => {
                const name = document.getElementById('newModelTypeName').value.trim();
                const shortName = document.getElementById('newModelTypeAbbr').value.trim().toUpperCase();
                if(!name || !shortName){ Swal.showValidationMessage('กรุณาระบุชื่อประเภทและตัวย่อ'); return false; }
                return {type:name, abbr:shortName};
            }
        });
        if(!result.isConfirmed){ $('#modelType').val($('#modelType').data('current') || ''); return; }
        type = result.value.type;
        abbr = result.value.abbr;
    }
    $.ajax({
        method: 'get',
        dataType: 'json',
        url: "./fn_api.php?action=detail_set&model="+encodeURIComponent(model)+"&type="+encodeURIComponent(type)+"&abbr="+encodeURIComponent(abbr),
        success: function(resp){
            if(resp.status !== 'ok') { Swal.fire(resp.message || "บันทึกไม่สำเร็จ", "", "error"); return; }
            Swal.fire({ icon: "success", title: (type ? "บันทึกประเภทแล้ว" : "ล้างประเภทแล้ว"), timer: 1000, showConfirmButton: false });
            $('#modelType').data('current', type);
            if(abbr) setTimeout(function(){ location.reload(); }, 900);
        },
        error: function(xhr){
            console.log(xhr);
            Swal.fire("บันทึกไม่สำเร็จ","","error");
        }
    });
}

function save_all_rgl(model){
    // Collect every register row currently rendered for this model.
    // Match by the known id prefix so model names containing '-' stay safe.
    let prefix = 'model-name-'+model+'-';
    let payload = {};
    document.querySelectorAll('#customers input[id^="model-name-"]').forEach(function(el){
        if(!el.id.startsWith(prefix)) return;
        let register = el.id.slice(prefix.length);
        let decimal  = $('#model-decimal-'+model+'-'+register).val();
        let multiply = $('#model-multiply-'+model+'-'+register).val();
        payload[register] = {
            name:     $('#model-name-'+model+'-'+register).val(),
            type:     $('#model-type-'+model+'-'+register).val(),
            unit:     $('#model-unit-'+model+'-'+register).val(),
            decimal:  (decimal  !== undefined && decimal  !== '') ? decimal  : 3,
            multiply: (multiply !== undefined && multiply !== '') ? multiply : 1
        };
    });
    let count = Object.keys(payload).length;
    if(count === 0){ Swal.fire("No registers to save","","info"); return; }

    Swal.fire({
        title: "Save all "+count+" registers?",
        showCancelButton: true,
        confirmButtonText: "Save all"
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                method: 'post',
                url: "./fn_api.php?action=rgl_add_all&model="+encodeURIComponent(model)+'&fn_read='+encodeURIComponent(current_rgl_function(model)),
                data: JSON.stringify(payload),
                success: function(response) {
                    console.log(response);
                    Swal.fire({
                        title: "Saved "+count+" registers",
                        icon: "success",
                        timer: 1200,
                        showConfirmButton: false
                    });
                },
                error: function(xhr) {
                    console.log(xhr);
                    Swal.fire("Save failed","","error");
                }
            });
        }
    });
}

function rename_model(model){
    Swal.fire({
        title: "เปลี่ยนชื่อ model",
        input: "text",
        inputValue: model,
        inputLabel: "ชื่อ model ใหม่",
        showCancelButton: true,
        confirmButtonText: "บันทึก",
        cancelButtonText: "ยกเลิก",
        inputValidator: (value) => {
            if (!value || !value.trim()) return "กรุณากรอกชื่อ model";
        }
    }).then((result) => {
        if (!result.isConfirmed) return;
        const newModel = result.value.trim();
        if (newModel === model) return;

        $.ajax({
            type: 'GET',
            url: "./fn_api.php?action=rgl_model_rename",
            data: 'model=' + encodeURIComponent(model) + '&newModel=' + encodeURIComponent(newModel),
            success: function(response) {
                console.log(response);
                let res;
                try { res = JSON.parse(response); } catch(e) { res = null; }

                if (!res || res.status === 'error') {
                    Swal.fire("เปลี่ยนชื่อไม่สำเร็จ", (res && res.message) || "", "error");
                    return;
                }

                let msg = "เปลี่ยนชื่อ model แล้ว";
                if (res.devicesUpdated > 0) {
                    msg += ` (อัปเดตอุปกรณ์ ${res.devicesUpdated} ตัวที่ใช้ model นี้ด้วย)`;
                }

                Swal.fire({
                    icon: "success",
                    title: msg,
                    showConfirmButton: false,
                    timer: 1800,
                    willClose: () => {
                        window.location.href = 'index.php?page=device&pg=model&modelName=' + encodeURIComponent(res.model);
                    }
                });
            },
            error: function(xhr) {
                console.log(xhr);
                Swal.fire("เปลี่ยนชื่อไม่สำเร็จ", "", "error");
            }
        });
    });
}

function toggleAllRgl(source){
    document.querySelectorAll('.rgl-del-check').forEach(cb => cb.checked = source.checked);
}

function del_rgl_selected(model){
    const registers = [...document.querySelectorAll('.rgl-del-check:checked')].map(cb => cb.value);

    if(registers.length === 0){
        Swal.fire({
            icon: "info",
            title: "ยังไม่ได้เลือก",
            text: "กรุณาติ๊ก parameter ที่ต้องการลบก่อน",
            timer: 1800,
            showConfirmButton: false
        });
        return;
    }

    Swal.fire({
        title: "Are you sure?",
        text: `จะลบ ${registers.length} parameter — คุณจะย้อนกลับไม่ได้!`,
        icon: "warning",
        showCancelButton: true,
        cancelButtonText: "No, cancel!",
        confirmButtonText: "Yes, delete it!",
        customClass: {
            confirmButton: "btn btn-danger swal-danger",
            cancelButton: "btn btn-secondary swal-cancel"
        },
        buttonsStyling: false
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                type: 'GET',
                url: "./fn_api.php?action=rgl_del_bulk",
                data: 'model=' + encodeURIComponent(model) + '&registers=' + registers.join(',') + '&fn_read=' + encodeURIComponent(current_rgl_function(model)),
                success: function(response) {
                    console.log(response);
                    Swal.fire({
                        position: "center",
                        icon: "success",
                        title: "Your work has been saved",
                        showConfirmButton: false,
                        timer: 1500,
                        willClose: () => {
                            location.reload('refresh:1');
                        }
                    });
                }
            });
        }
    });
}

function del_rgl(model,register){
    console.log(model,register);
    let timerInterval;
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: "btn btn-danger swal-danger",
            cancelButton: "btn btn-secondary swal-cancel"
        },
        buttonsStyling: false
        });
        swalWithBootstrapButtons.fire({
        title: "Are you sure?",
        text: "You won't be able to revert this!",
        icon: "warning",
        showCancelButton: true,
        cancelButtonText: "No, cancel!",
        confirmButtonText: "Yes, delete it!",
        reverseButtons: false
        }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({  
                type: 'GET',
                url: "./fn_api.php?action=rgl_del",
                data: 'model='+encodeURIComponent(model)+'&register='+encodeURIComponent(register)+'&fn_read='+encodeURIComponent(current_rgl_function(model)),
                success: function(response) {
                    console.log(response);
                    // swalWithBootstrapButtons.fire({
                    //     title: "Deleted!",
                    //     text: "Your file has been deleted.",
                    //     icon: "success",
                    //     timer: 1500,
                    //     timerProgressBar: true,
                    //     didOpen: () => {
                    //         Swal.showLoading();
                    //         const timer = Swal.getPopup().querySelector("b");
                    //         timerInterval = setInterval(() => {
                    //         timer.textContent = `${Swal.getTimerLeft()}`;
                    //         }, 100);
                    //     },
                    //     willClose: () => {
                    //         clearInterval(timerInterval);
                    //         // location.reload('refresh:1');
                    //     }

                    // });
                    
                    Swal.fire({
                        position: "center",
                        icon: "success",
                        title: "Your work has been saved",
                        showConfirmButton: false,
                        timer: 1500,
                        willClose: () => {
                            location.reload('refresh:1');
                        }
                    });

                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
            
        } else if (
            /* Read more about handling dismissals below */
            result.dismiss === Swal.DismissReason.cancel
        ) {
            swalWithBootstrapButtons.fire({
            title: "Cancelled",
            text: "Your imaginary file is safe :)",
            showConfirmButton: false,
            timer: 500,
            icon: "error"
            });
        }
    });
}

function add_rgl2(model){
    console.log(model);
}
// Searchable combobox for the RGL "Name" field.
//  - type to filter, click to pick (stores Parameter, e.g. "e_p")
//  - shows "Name  code · unit" in a themed floating panel (reuses .msel-panel)
//  - clear (x) button; picking one auto-fills the Unit field
//  - free typing still allowed (custom name)
window.attachNameCombo = function(input, params, unitEl){
    if(!input || input.dataset.comboDone) return;
    input.dataset.comboDone = '1';

    var wrap = document.createElement('div');
    wrap.className = 'mcombo';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.className = 'mcombo-clear hidden';
    clearBtn.innerHTML = '&times;';
    clearBtn.title = 'ล้าง';
    wrap.appendChild(clearBtn);

    var caret = document.createElement('span');
    caret.className = 'mcombo-caret';
    caret.innerHTML = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m2 5 6 6 6-6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    wrap.appendChild(caret);

    var panel = document.createElement('div');
    panel.className = 'msel-panel';
    document.body.appendChild(panel);
    var open = false;
    var wasOpen = false; // panel state captured at mousedown, so a click can toggle

    function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function toggleClear(){ clearBtn.classList.toggle('hidden', !input.value); }
    function fillUnit(){
        var hit = params.find(function(o){ return o.p === input.value; });
        if(hit && unitEl && hit.unit){ unitEl.value = hit.unit; }
    }
    function build(showAll){
        // showAll = list everything (used on focus) so you can pick a different
        // option even when the field already holds a value; typing filters.
        var q = showAll ? '' : input.value.trim().toLowerCase();
        var list = params.filter(function(o){
            if(!o.p) return false;
            if(!q) return true;
            return o.p.toLowerCase().indexOf(q) >= 0 || (o.name && o.name.toLowerCase().indexOf(q) >= 0);
        });
        panel.innerHTML = '';
        if(list.length === 0){
            var e = document.createElement('div'); e.className = 'msel-empty';
            e.textContent = 'ไม่พบในรายการ — พิมพ์เพื่อใช้ค่าเอง';
            panel.appendChild(e); return;
        }
        list.slice(0, 200).forEach(function(o){
            var row = document.createElement('div');
            row.className = 'msel-option' + (o.p === input.value ? ' selected' : '');
            row.innerHTML = esc(o.name ? o.name : o.p) + '<span class="mcombo-code">' + esc(o.p) + (o.unit ? ' · ' + esc(o.unit) : '') + '</span>';
            row.addEventListener('mousedown', function(ev){
                ev.preventDefault(); // keep focus so blur doesn't close before we act
                input.value = o.p;
                if(unitEl && o.unit){ unitEl.value = o.unit; }
                toggleClear();
                close();
            });
            panel.appendChild(row);
        });
    }
    function place(){
        var r = input.getBoundingClientRect();
        panel.style.minWidth = r.width + 'px';
        panel.style.left = r.left + 'px';
        panel.style.top = (r.bottom + 5) + 'px';
        var ph = panel.offsetHeight;
        if(r.bottom + 5 + ph > window.innerHeight && r.top - 5 - ph > 0){
            panel.style.top = (r.top - 5 - ph) + 'px';
        }
    }
    function openPanel(showAll){ build(showAll); panel.classList.add('show'); open = true; place(); }
    function close(){ panel.classList.remove('show'); open = false; }

    // focus: show ALL options (and highlight the current text) so another one is easy to pick
    input.addEventListener('focus', function(){ input.select(); openPanel(true); });
    // click while already open -> close (toggle). wasOpen is captured before focus fires.
    input.addEventListener('mousedown', function(){ wasOpen = open; });
    input.addEventListener('click', function(){ if(wasOpen){ close(); } else if(!open){ input.select(); openPanel(true); } });
    input.addEventListener('input', function(){ toggleClear(); fillUnit(); if(open){ build(false); place(); } else { openPanel(false); } });
    input.addEventListener('blur', function(){ setTimeout(close, 150); });
    clearBtn.addEventListener('mousedown', function(ev){ ev.preventDefault(); input.value = ''; toggleClear(); input.focus(); openPanel(true); });
    caret.addEventListener('mousedown', function(ev){ ev.preventDefault(); if(open){ close(); } else { input.focus(); } });
    window.addEventListener('scroll', function(ev){
        if(!open) return;
        if(ev.target && ev.target.closest && ev.target.closest('.msel-panel')) return;
        close();
    }, true);
    window.addEventListener('resize', function(){ if(open) close(); });

    toggleClear();
};

function add_rgl(model){

    // Parameter combobox data from parameter_list.json:
    //  - store  o.p   (Parameter, e.g. "e_p") into rgl.json name
    //  - show   "Name (Parameter)"  in the suggestion list
    //  - selecting one auto-fills Unit
    const RGL_PARAMS = <?php echo json_encode($param_list, JSON_UNESCAPED_UNICODE); ?>;

    let input_mode = "";
    let input_mode2 = "";
    input_mode += '<select class="form-select" id="rgl-type">'+
    '<option value="int sign16">int sign16</option>'+
    '<option value="int unsign16">int unsign16</option>'+
    '<option value="float32">float32</option>'+
    '<option value="float32 inverse">float32 inverse</option>'+
    '<option value="int16 binary">int16 binary</option>'+
    '<option value="long unsign32">long unsign32</option>'+
    '<option value="long unsign32 inverse">long unsign32 inverse</option>'+
    '<option value="long sign32">long sign32</option>'+
    '<option value="long unsign64">long unsign64</option>'+
    '<option value="long sign64">long sign64</option>'+
    '</select>';

    if(/bac/i.test(model)){
        const bacnetObjectTypes = [
            'analogInput',
            'analogOutput',
            'analogValue',
            'binaryInput',
            'binaryOutput',
            'binaryValue',
            'multiStateInput',
            'multiStateOutput',
            'multiStateValue'
        ];
        input_mode2 += '<select class="form-select" id="rgl-fn">';
        bacnetObjectTypes.forEach(function(item) {
            input_mode2 += `<option value="${item}">${item}</option>`;
        });

        input_mode2 += '</select>';
    } else {
        input_mode2 =
            '<select class="form-select" id="rgl-fn">'+
            '<option value="read_holding" '+(window.RGL_BANK==='holding'?'selected':'')+'>Holding Register (FC03)</option>'+
            '<option value="read_input" '+(window.RGL_BANK==='input'?'selected':'')+'>Input Register (FC04)</option>'+
            '</select>';
    }
    if(model){
        (async () => {
            let selectedFn = 'read_' + window.RGL_BANK;
            const draftKey = 'mdb-rgl-draft:' + model;
            const draftFields = ['rgl-name','rgl-register','rgl-type','rgl-fn','rgl-unit','rgl-decimal','rgl-multiply'];
            function readDraft(){
                try { return JSON.parse(localStorage.getItem(draftKey) || '{}'); }
                catch(e) { return {}; }
            }
            function saveDraft(){
                const draft = {};
                draftFields.forEach(function(id){
                    const el = document.getElementById(id);
                    if(el) draft[id] = el.value;
                });
                try { localStorage.setItem(draftKey, JSON.stringify(draft)); } catch(e) {}
            }
            function clearDraft(){
                try { localStorage.removeItem(draftKey); } catch(e) {}
            }

            const rglDialogResult = await Swal.fire({
            title: 'Add Register (RGL)',
            background: "#fff",
            html:
                '<div class="cfg-form">'+
                '<div class="cfg-field"><label class="cfg-label">Name</label>'+
                    '<input type="text" class="form-control" id="rgl-name" value="" placeholder="English, 0-9, - และ _ เท่านั้น" autocomplete="off" spellcheck="false" pattern="[A-Za-z0-9_-]+">'+
                    '<div class="cfg-hint rgl-name-hint">อนุญาตเฉพาะภาษาอังกฤษ ตัวเลข เครื่องหมาย - และ _</div>'+
                '</div>'+
                '<div class="cfg-grid">'+
                    '<div class="cfg-field"><label class="cfg-label">Register</label><input type="text" class="form-control" id="rgl-register" value=""></div>'+
                    '<div class="cfg-field"><label class="cfg-label">Type</label>'+input_mode+'</div>'+
                '</div>'+
                (input_mode2 ? '<div class="cfg-field"><label class="cfg-label">Function</label>'+input_mode2+'</div>' : '')+
                '<div class="cfg-field"><label class="cfg-label">Unit</label><input type="text" class="form-control" id="rgl-unit" value=""></div>'+
                '<div class="cfg-grid">'+
                    '<div class="cfg-field"><label class="cfg-label">Decimal</label><input type="text" class="form-control" id="rgl-decimal" value="3"><div class="cfg-hint" id="rgl-decimal-hint"></div></div>'+
                    '<div class="cfg-field"><label class="cfg-label">Multiply</label><input type="text" class="form-control" id="rgl-multiply" value="1"><div class="cfg-hint" id="rgl-multiply-hint"></div></div>'+
                '</div>'+
                '</div>'
                ,
            showCancelButton: true,
            confirmButtonText: 'Add',
            cancelButtonText: 'Cancel',
            didOpen: () => {
                window.mselEnhanceIn(Swal.getHtmlContainer());
                // Name: searchable combobox (type / select / clear) that auto-fills Unit
                let unitEl = document.getElementById('rgl-unit');
                let nameEl = document.getElementById('rgl-name');
                window.attachNameCombo(nameEl, RGL_PARAMS, unitEl);
                nameEl.addEventListener('input', function(){
                    const clean = nameEl.value.replace(/[^A-Za-z0-9_-]/g, '');
                    if(nameEl.value !== clean) nameEl.value = clean;
                });
                // Live examples so users understand Decimal / Multiply
                let decEl = document.getElementById('rgl-decimal');
                let decH  = document.getElementById('rgl-decimal-hint');
                let mulEl = document.getElementById('rgl-multiply');
                let mulH  = document.getElementById('rgl-multiply-hint');
                function upDec(){
                    let d = parseInt(decEl.value, 10);
                    decH.textContent = (isNaN(d) || d < 0)
                        ? 'จำนวนตำแหน่งทศนิยม'
                        : d + ' ตำแหน่ง · ตัวอย่าง ' + (d === 0 ? '123' : '123.' + '0'.repeat(d));
                }
                function upMul(){
                    let m = parseFloat(mulEl.value);
                    mulH.textContent = isNaN(m)
                        ? 'ตัวคูณค่าที่อ่านได้'
                        : '× ' + m + ' · เช่น อ่าน 1000 → ' + (1000 * m);
                }
                if(decEl && decH){ decEl.addEventListener('input', upDec); upDec(); }
                if(mulEl && mulH){ mulEl.addEventListener('input', upMul); upMul(); }
                const draft = readDraft();
                draftFields.forEach(function(id){
                    const el = document.getElementById(id);
                    if(el && Object.prototype.hasOwnProperty.call(draft, id)) {
                        el.value = draft[id];
                        el.dispatchEvent(new Event('change', {bubbles:true}));
                    }
                    if(el) {
                        el.addEventListener('input', saveDraft);
                        el.addEventListener('change', saveDraft);
                    }
                });
                if(decEl && decH) upDec();
                if(mulEl && mulH) upMul();
            },
            focusConfirm: false,
            preConfirm: () => {
                let res_arr = new Object();
                let res_arr2 = new Object();
                let res_arr3 = new Object();
                res_arr.name = String(document.getElementById('rgl-name').value);
                if(!/^[A-Za-z0-9_-]+$/.test(res_arr.name)){
                    Swal.showValidationMessage('Name ต้องเป็นภาษาอังกฤษ ตัวเลข - หรือ _ เท่านั้น');
                    return false;
                }
                res_arr3.register = String(document.getElementById('rgl-register').value);
                res_arr.type = String(document.getElementById('rgl-type').value);
                res_arr.unit = String(document.getElementById('rgl-unit').value);
                res_arr.decimal = String(document.getElementById('rgl-decimal').value);
                res_arr.multiply = String(document.getElementById('rgl-multiply').value);
                selectedFn = String(document.getElementById('rgl-fn').value);
                saveDraft();
                // console.log(res_arr);
                if(!res_arr3.register.trim()){
                    Swal.showValidationMessage('Register is required');
                    return false;
                }
                if(/bac/i.test(model)){
                    let bac_fn = String(document.getElementById('rgl-fn').value);
                    // console.log(`fn Bac == ${bac_fn}`);
                    res_arr2[bac_fn] ??= {};
                    res_arr2[bac_fn][res_arr3.register] ??= {};
                    res_arr2[bac_fn][res_arr3.register] = res_arr;

                }else{
                    res_arr2[res_arr3.register] = res_arr;

                }
                


                return res_arr2;
            }
            })
            const formValues = rglDialogResult.value;
            if(rglDialogResult.dismiss === Swal.DismissReason.cancel) {
                clearDraft();
            }

            if(formValues){
                // Swal.fire(JSON.stringify(formValues))
                let key_formV = String(Object.keys(formValues)[0] || '');
                if(/bac/i.test(model)){
                    const fnKey = String(Object.keys(formValues)[0] || selectedFn);
                    key_formV = String(Object.keys(formValues[fnKey] || {})[0] || '');
                }
                $.ajax({
                            method: 'post',
                            url: "./fn_api.php?action=rgl_add&model="+encodeURIComponent(model)+'&register='+encodeURIComponent(key_formV)+'&fn_read='+encodeURIComponent(selectedFn),
                            data: JSON.stringify(formValues),
                            success: function(response) {
                                console.log(response);
                                let apiResult = response;
                                if(typeof response === 'string') {
                                    try { apiResult = JSON.parse(response); } catch(e) {}
                                }
                                if(apiResult && apiResult.status === 'error') {
                                    Swal.fire('เพิ่ม Register ไม่สำเร็จ', apiResult.message || 'Unknown error', 'error');
                                    return;
                                }
                                clearDraft();
                                Swal.fire({
                                    icon: "success",
                                    title: "เพิ่ม Register แล้ว",
                                    html: "ล้างค่าที่กรอกแล้ว กำลังรีเฟรชข้อมูล...",
                                    timer: 500,
                                    timerProgressBar: true,
                                    willClose: () => {
                                        location.reload('refresh:1');
                                    }
                                });
                            },
                            error: function(xhr) {
                                let message = 'HTTP ' + xhr.status;
                                try {
                                    const err = JSON.parse(xhr.responseText);
                                    if(err.message) message = err.message;
                                } catch(e) {}
                                Swal.fire('เพิ่ม Register ไม่สำเร็จ', message, 'error');
                            }
                });
            }
        })()
    }else{
        alert("model is not found!!")
    }
}

function add_model(){
    (async () => {
        let type_options = '<option value="">— ระบุประเภท —</option>';
<?php foreach($model_types as $tname=>$tabbr){ ?>
        type_options += '<option value="<?php echo $tname;?>"><?php echo $tname.' ('.$tabbr.')';?></option>';
<?php } ?>
        const { value: formValues } = await Swal.fire({
            title: 'New Model',
            background: "#fff",
            html:
                '<div class="cfg-form">'+
                '<div class="cfg-field"><label class="cfg-label">Model Name</label><input type="text" class="form-control" id="model-name" value=""></div>'+
                '<div class="cfg-field"><label class="cfg-label">Type</label><select class="form-select" id="model-type">'+type_options+'</select></div>'+
                '</div>',
            showCancelButton: true,
            confirmButtonText: 'Create',
            cancelButtonText: 'Cancel',
            didOpen: () => { window.mselEnhanceIn(Swal.getHtmlContainer()); },
            focusConfirm: false,
            preConfirm: () => {
                let name = String(document.getElementById('model-name').value).trim();
                let type = String(document.getElementById('model-type').value);
                if(!name){ Swal.showValidationMessage('กรุณากรอกชื่อ Model'); return false; }
                return { model_Nname: name, type: type };
            }
        });

        if(formValues){
            // 1) create the model entry in rgl.json (empty) — keeps rgl.json format
            // 2) record its type in detail_rgl.json — then open the new model page
            $.ajax({
                method: 'get',
                url: "./fn_api.php?action=rgl_model_add&model="+encodeURIComponent(formValues.model_Nname),
                success: function(){
                    $.ajax({
                        method: 'get',
                        url: "./fn_api.php?action=detail_set&model="+encodeURIComponent(formValues.model_Nname)+"&type="+encodeURIComponent(formValues.type),
                        complete: function(){
                            location.replace("./index.php?page=device&pg=model&modelName="+encodeURIComponent(formValues.model_Nname));
                        }
                    });
                },
                error: function(xhr){ console.log(xhr); Swal.fire("สร้างไม่สำเร็จ","","error"); }
            });
        }
    })()
}

function del_model(model){
    let timerInterval;
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: "btn btn-danger swal-danger",
            cancelButton: "btn btn-secondary swal-cancel"
        },
        buttonsStyling: false
        });
        swalWithBootstrapButtons.fire({
            title: "Are you sure?",
            text: "You won't be able to revert this!",
            icon: "warning",
            showCancelButton: true,
            cancelButtonText: "No, cancel!",
            confirmButtonText: "Yes, delete it!",
            reverseButtons: false
        }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({  
                type: 'GET',
                url: "./fn_api.php?action=rgl_del",
                data: 'model='+model,
                success: function(response) {
                    console.log(response);
                    
                    Swal.fire({
                        position: "center",
                        icon: "success",
                        title: "Your work has been saved",
                        showConfirmButton: false,
                        timer: 1500,
                        willClose: () => {
                            // back to the model list after deleting a model
                            window.location.href='index.php?page=device&pg=modellist';
                        }
                    });

                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
            
        } else if (
            /* Read more about handling dismissals below */
            result.dismiss === Swal.DismissReason.cancel
        ) {
            swalWithBootstrapButtons.fire({
                title: "Cancelled",
                text: "Your imaginary file is safe :)",
                showConfirmButton: false,
                timer: 500,
                icon: "error"
            });
        }
    });
}

function import_m1(fname) {
    // console.log(fname);
    Swal.fire({
        title: 'Upload JSON Files',
        html: '<input type="file" id="fileInput" class="form-control" multiple accept=".json">',
        showCancelButton: true,
        confirmButtonText: 'Upload',
        preConfirm: () => {
            const files = document.getElementById('fileInput').files;
            if (files.length === 0) {
            Swal.showValidationMessage('Please select at least one JSON file.');
            return false;
            }

            const formData = new FormData();
            for (let i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
            }

            return fetch('upload.php', {
            method: 'POST',
            body: formData
            })
            .then(res => res.json())
            .catch(err => {
            Swal.showValidationMessage(`Upload error: ${err}`);
            });
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // Swal.fire('Success!', 'Files uploaded/merged successfully.', 'success');
            Swal.fire({
                title: "Success!",
                text: "Your action was completed successfully.",
                icon: "success",
                confirmButtonText: "OK"
            }).then((result) => {
                if (result.isConfirmed) {
                    location.reload(); // Reloads the current page
                }
            });
        }
    });


    

}

function export_m1(fname) {
    console.log('==========');
    
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: "btn btn-success swal-confirm",
            cancelButton: "btn btn-danger swal-cancel"
        },
        buttonsStyling: false
    });
    swalWithBootstrapButtons.fire({
        title: "Are you sure?",
        text: "Export this!",
        icon: "warning",
        showCancelButton: true,
        cancelButtonText: "No",
        confirmButtonText: "Yes",
        reverseButtons: false
    }).then((result) => {
    if (result.isConfirmed) {
        // window.location.href = 'mx_export.php';
        Swal.fire({
            title: "Auto close alert!",
            html: "I will close in <b></b> milliseconds.",
            timer: 1000,
            timerProgressBar: true,
            didOpen: () => {
                Swal.showLoading();
                const timer = Swal.getPopup().querySelector("b");
                timerInterval = setInterval(() => {
                timer.textContent = `${Swal.getTimerLeft()}`;
                }, 100);
            },
            willClose: () => {
                clearInterval(timerInterval);
                location.reload('refresh:1');
            }
            }).then((result) => {
            /* Read more about handling dismissals below */
            if (result.dismiss === Swal.DismissReason.timer) {
                // console.log("I was closed by the timer");
                window.location.href = 'mx_export.php';
            }
        });
    } else if (
        /* Read more about handling dismissals below */
        result.dismiss === Swal.DismissReason.cancel
    ) {
        swalWithBootstrapButtons.fire({
        title: "Cancelled",
        text: "Your imaginary file is safe :)",
        showConfirmButton: false,
        timer: 500,
        icon: "error"
        });
    }
});

    
}

// function update_dvl_2server(fname){
//     console.log(fname);
    
//     let timerInterval;
//     const swalWithBootstrapButtons = Swal.mixin({
//         customClass: {
//             confirmButton: "btn btn-success swal-confirm",
//             cancelButton: "btn btn-danger swal-cancel"
//         },
//         buttonsStyling: false
//         });
//         swalWithBootstrapButtons.fire({
//         title: "Are you sure?",
//         text: "You won't be upload this!",
//         icon: "warning",
//         showCancelButton: true,
//         cancelButtonText: "No",
//         confirmButtonText: "Yes",
//         reverseButtons: false
//         }).then((result) => {
//         if (result.isConfirmed) {
//             $.ajax({  
//                 type: 'POST',
//                 url: "./mx_update_dvl.php",
//                 data: '',
//                 success: function(response) {
//                     console.log(response);
                    
//                     Swal.fire({
//                         position: "center",
//                         icon: "success",
//                         title: "Your work has been saved",
//                         showConfirmButton: false,
//                         timer: 1500,
//                         willClose: () => {
//                             // location.reload('refresh:1');
//                             // window.location.href='index.php?page=device&pg=model&modelName=';
//                         }
//                     });

//                     // setTimeout( function() { location.reload('refresh:1'); }, 200);
//                 }
//             });
            
//         } else if (
//             /* Read more about handling dismissals below */
//             result.dismiss === Swal.DismissReason.cancel
//         ) {
//             swalWithBootstrapButtons.fire({
//             title: "Cancelled",
//             text: "Your imaginary file is safe :)",
//             showConfirmButton: false,
//             timer: 500,
//             icon: "error"
//             });
//         }
//     });
    
// }

function uploadRglToFtp(){
    const button = document.getElementById('rglFtpUploadButton');
    if(!button) return;
    const csrfToken = <?php echo json_encode($rglFtpCsrf, JSON_UNESCAPED_SLASHES); ?>;
    Swal.fire({
        title: 'ส่ง rgl.json ไปยัง API?',
        html: 'API จะเปรียบเทียบ SHA-256 และอัปเดตเฉพาะเมื่อข้อมูลเปลี่ยน',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<i class="fa-solid fa-cloud-arrow-up"></i> ส่งไฟล์',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true
    }).then(function(result){
        if(!result.isConfirmed) return;
        button.disabled = true;
        Swal.fire({
            title: 'กำลังส่งไฟล์...',
            text: 'กรุณารอสักครู่',
            allowOutsideClick: false,
            didOpen: function(){ Swal.showLoading(); }
        });
        fetch('./rgl_api_upload_api.php', {
            method: 'POST',
            headers: {'X-CSRF-Token': csrfToken, 'Accept': 'application/json'},
            cache: 'no-store'
        }).then(async function(response){
            const payload = await response.json().catch(function(){ return {}; });
            if(!response.ok || !payload.success){
                throw new Error(payload.error || ('HTTP ' + response.status));
            }
            return payload;
        }).then(function(payload){
            const size = Number(payload.bytes || 0).toLocaleString();
            const isCurrent = payload.status === 'up_to_date';
            Swal.fire({
                icon: 'success',
                title: isCurrent ? 'ข้อมูลเป็นเวอร์ชันล่าสุดแล้ว' : 'อัปเดต rgl.json สำเร็จ',
                html: '<b>' + String(payload.destination || '/ftp/dconfig/config/rgl.json') + '</b><br>Revision ' + Number(payload.revision || 0) + ' · ' + size + ' bytes'
            });
        }).catch(function(error){
            Swal.fire({icon: 'error', title: 'ส่งไฟล์ไม่สำเร็จ', text: error.message});
        }).finally(function(){
            button.disabled = false;
        });
    });
}

function update2server(fname){
    console.log(fname);
    
    let timerInterval;
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: "btn btn-success swal-confirm",
            cancelButton: "btn btn-danger swal-cancel"
        },
        buttonsStyling: false
        });
        swalWithBootstrapButtons.fire({
        title: "Are you sure?",
        text: "You won't be upload this!",
        icon: "warning",
        showCancelButton: true,
        cancelButtonText: "No",
        confirmButtonText: "Yes",
        reverseButtons: false
        }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({  
                type: 'POST',
                url: "./mx_update_config2server.php",
                data: '',
                success: function(response) {
                    console.log(response);
                    
                    Swal.fire({
                        position: "center",
                        icon: "success",
                        title: "Your work has been saved",
                        showConfirmButton: false,
                        timer: 1500,
                        willClose: () => {
                            // location.reload('refresh:1');
                            // window.location.href='index.php?page=device&pg=model&modelName=';
                        }
                    });

                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
            
        } else if (
            /* Read more about handling dismissals below */
            result.dismiss === Swal.DismissReason.cancel
        ) {
            swalWithBootstrapButtons.fire({
            title: "Cancelled",
            text: "Your imaginary file is safe :)",
            showConfirmButton: false,
            timer: 500,
            icon: "error"
            });
        }
    });
    
}

function syncModel(fname){
    console.log(fname);
    
    // let timerInterval;
    // const swalWithBootstrapButtons = Swal.mixin({
    //     customClass: {
    //         confirmButton: "btn btn-success swal-confirm",
    //         cancelButton: "btn btn-danger swal-cancel"
    //     },
    //     buttonsStyling: false
    //     });
    //     swalWithBootstrapButtons.fire({
    //     title: "Are you sure?",
    //     text: "You won't be upload this!",
    //     icon: "warning",
    //     showCancelButton: true,
    //     cancelButtonText: "No",
    //     confirmButtonText: "Yes",
    //     reverseButtons: false
    //     }).then((result) => {
    //     if (result.isConfirmed) {
    //         $.ajax({  
    //             type: 'POST',
    //             url: "./mx_update_config2server.php",
    //             data: '',
    //             success: function(response) {
    //                 console.log(response);
                    
    //                 Swal.fire({
    //                     position: "center",
    //                     icon: "success",
    //                     title: "Your work has been saved",
    //                     showConfirmButton: false,
    //                     timer: 1500,
    //                     willClose: () => {
    //                         // location.reload('refresh:1');
    //                         // window.location.href='index.php?page=device&pg=model&modelName=';
    //                     }
    //                 });

    //                 // setTimeout( function() { location.reload('refresh:1'); }, 200);
    //             }
    //         });
            
    //     } else if (
    //         /* Read more about handling dismissals below */
    //         result.dismiss === Swal.DismissReason.cancel
    //     ) {
    //         swalWithBootstrapButtons.fire({
    //         title: "Cancelled",
    //         text: "Your imaginary file is safe :)",
    //         showConfirmButton: false,
    //         timer: 500,
    //         icon: "error"
    //         });
    //     }
    // });
    
}

function clear_cml(){
    let timerInterval;
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: "btn btn-danger swal-danger",
            cancelButton: "btn btn-secondary swal-cancel"
        },
        buttonsStyling: false
        });
        swalWithBootstrapButtons.fire({
        title: "Are you sure?",
        text: "You won't be able to revert this!",
        icon: "warning",
        showCancelButton: true,
        cancelButtonText: "No, cancel!",
        confirmButtonText: "Yes, delete it!",
        reverseButtons: false
        }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({  
                type: 'GET',
                url: "./clear_cml.php",
                data: '',
                success: function(response) {
                    console.log(response);
                    
                    Swal.fire({
                        position: "center",
                        icon: "success",
                        title: "Your work has been saved",
                        showConfirmButton: false,
                        timer: 1500,
                        willClose: () => {
                            location.reload('refresh:1');
                            // window.location.href='index.php?page=device&pg=model&modelName=';
                        }
                    });

                    // setTimeout( function() { location.reload('refresh:1'); }, 200);
                }
            });
            
        } else if (
            /* Read more about handling dismissals below */
            result.dismiss === Swal.DismissReason.cancel
        ) {
            swalWithBootstrapButtons.fire({
            title: "Cancelled",
            text: "Your imaginary file is safe :)",
            showConfirmButton: false,
            timer: 500,
            icon: "error"
            });
        }
    });
}

// Model List: filter rows by Type (composes with the name search box)
function filterModelType() {
    var tf = document.getElementById('modelTypeFilter');
    var t = tf ? tf.value : '';
    var si = document.getElementById('myInput');
    var q = si ? si.value.toUpperCase() : '';
    var rows = document.querySelectorAll('#customers tr');
    rows.forEach(function(tr){
        if(tr.getElementsByTagName('th').length) return; // skip header row
        var dt = tr.getAttribute('data-type') || '';
        var okType = !t || dt === t;
        var okText = !q || (tr.textContent || tr.innerText).toUpperCase().indexOf(q) > -1;
        tr.style.display = (okType && okText) ? '' : 'none';
    });
}

function myFunction_search() {
  // On the Model List page, use the combined Type + text filter instead
  if(document.getElementById('modelTypeFilter')){ filterModelType(); return; }
  var input, filter, table, tr, td, i, txtValue;
  input = document.getElementById("myInput");
  filter = input.value.toUpperCase();
  table = document.getElementById("customers");
  tr = table.getElementsByTagName("tr");
  for (i = 0; i < tr.length; i++) {
    // console.log(tr[i].getElementsByTagName("td").length);
    try {
        td = tr[i].getElementsByTagName("td")[0];
        td1 = tr[i].getElementsByTagName("td")[1];
        td2 = tr[i].getElementsByTagName("td")[2];
        td3 = tr[i].getElementsByTagName("td")[3];
        td4 = tr[i].getElementsByTagName("td")[4];
        console.log();
        

        if (td && td1 && td2) {
        txtValue = td.textContent || td.innerText;
        txtValue1 = td1.textContent || td1.innerText;
        txtValue2 = td2.textContent || td2.innerText;
        txtValue3 = td3.textContent || td3.innerText;
        txtValue4 = td4.textContent || td4.innerText;
        if ((txtValue.toUpperCase().indexOf(filter) > -1) || (txtValue1.toUpperCase().indexOf(filter) > -1) || (txtValue2.toUpperCase().indexOf(filter) > -1) || (txtValue3.toUpperCase().indexOf(filter) > -1) || (txtValue4.toUpperCase().indexOf(filter) > -1)) {
            tr[i].style.display = "";
        } else {
            tr[i].style.display = "none";
        }
        }
    }
    catch(err) {
        // document.getElementById("demo").innerHTML = err.message;
        console.log("err:",err);
        
    }

    // td1 = tr[i].getElementsByTagName("td")[1];
    // if (td1) {
    //   txtValue = td1.textContent || td1.innerText;
    //   if (txtValue.toUpperCase().indexOf(filter) > -1) {
    //     tr[i].style.display = "";
    //   } else {
    //     tr[i].style.display = "none";
    //   }
    // }   
  }
}

// Remember the search separately for each Device URL and restore it after a
// refresh. The clear button resets both the field and the filtered table.
(function setupRememberedDeviceSearch(){
    var input = document.getElementById('myInput');
    if(!input || !window.sessionStorage) return;
    var key = 'mdb-device-search:' + location.pathname + location.search;
    var saved = sessionStorage.getItem(key) || '';
    input.value = saved;
    updateDeviceSearchClearButton();
    if(saved) myFunction_search();
})();

function updateDeviceSearchClearButton(){
    var input = document.getElementById('myInput');
    var button = document.getElementById('clearDeviceSearch');
    if(button) button.classList.toggle('is-visible', !!(input && input.value));
}

function rememberDeviceSearch(){
    var input = document.getElementById('myInput');
    if(!input) return;
    if(window.sessionStorage){
        var key = 'mdb-device-search:' + location.pathname + location.search;
        sessionStorage.setItem(key, input.value);
    }
    updateDeviceSearchClearButton();
    myFunction_search();
}

function clearDeviceSearch(){
    var input = document.getElementById('myInput');
    if(!input) return;
    input.value = '';
    rememberDeviceSearch();
    input.focus();
}

// $( document ).ready(function() {
//     console.log( "ready!" );
// });
</script>

<style>
#customers {
  font-family: "Segoe UI", "Noto Sans Thai", Tahoma, Arial, sans-serif;
  border-collapse: separate;
  border-spacing: 0;
  width: 100%;
  background: #ffffff;
  border-radius: 14px;
  /* NOTE: no overflow here — an overflow!=visible would make the table its
     own scroll container and trap the sticky <th>. Corner clipping is done
     by .table-scroll (which has overflow:auto + border-radius). */
  box-shadow: 0 6px 24px rgba(6, 74, 153, 0.07);
}

#customers td, #customers th {
  padding: 12px 14px;
  text-align: center;
  vertical-align: middle;
}

#customers th {
  background: linear-gradient(135deg, #064a99 0%, #0a63c9 100%);
  color: #ffffff;
  font-weight: 600;
  font-size: 12.5px;
  letter-spacing: 0.4px;
  text-transform: uppercase;
  white-space: nowrap;
  position: sticky;
  top: 0;
  z-index: 2;
}

#customers td {
  border-bottom: 1px solid #eef1f6;
  color: #2b3a52;
  font-size: 14px;
}

#customers tr:nth-child(even) { background-color: #f7fafe; }

#customers tbody tr:last-child td { border-bottom: 0; }

#customers tr:hover { background-color: #e9f2ff; transition: background-color 0.15s ease; }

/* ============================================================
   SweetAlert2 — themed + tidy layout (blue / rounded / balanced)
   ============================================================ */
/* --- Popup shell: comfortable padding, rounded, soft shadow --- */
.swal2-popup {
    padding: 1.8em 2em 1.6em !important;
    border-radius: 18px !important;
    font-family: "Segoe UI", "Noto Sans Thai", Tahoma, Arial, sans-serif !important;
    border: 1px solid #e8edf5 !important;
    box-shadow: 0 18px 48px rgba(6, 74, 153, 0.22) !important;
}
/* --- Title & body: clear vertical rhythm --- */
.swal2-title {
    color: #064a99 !important;
    font-weight: 700 !important;
    font-size: 20px !important;
    letter-spacing: .2px !important;
    padding: 0 !important;
    margin: 0 0 .35em !important;
    line-height: 1.35 !important;
}
.swal2-html-container {
    color: #475569 !important;
    font-size: 14.5px !important;
    margin: .25em 0 0 !important;
    line-height: 1.5 !important;
}
.swal2-icon { margin: .4em auto 1em !important; }

/* --- Actions row: even gap, tidy spacing, no per-button margins --- */
.swal2-actions {
    gap: 12px !important;
    margin-top: 1.6em !important;
    flex-wrap: wrap !important;
}
/* --- Buttons: equal width so confirm/cancel stay balanced.
       Covers plain Swal.fire (.swal2-styled) AND the mixin, whose
       buttonsStyling:false gives .btn.swal-confirm / .btn.swal-cancel --- */
.swal2-styled,
.swal-confirm,
.swal-cancel,
.swal-danger {
    min-width: 120px !important;
    padding: 10px 22px !important;
    margin: 0 !important;
    border-radius: 10px !important;
    font-weight: 600 !important;
}
.swal2-styled { box-shadow: 0 6px 16px rgba(6, 74, 153, 0.12) !important; }
/* Confirm — theme blue (covers both plain Swal.fire and the .swal-confirm mixin) */
.swal2-styled.swal2-confirm,
.swal-confirm {
    background: linear-gradient(135deg, #064a99, #0a63c9) !important;
    color: #fff !important;
}
.swal2-styled.swal2-confirm:focus {
    box-shadow: 0 0 0 3px rgba(10, 99, 201, 0.35) !important;
}
/* Cancel — theme slate grey */
.swal2-styled.swal2-cancel,
.swal-cancel {
    background: #64748b !important;
    color: #fff !important;
}
/* Destructive confirm (delete dialogs) — theme danger red */
.swal-danger {
    background: linear-gradient(135deg, #dc2626, #ef4444) !important;
    color: #fff !important;
}
.swal2-styled.swal2-cancel:focus {
    box-shadow: 0 0 0 3px rgba(100, 116, 139, 0.35) !important;
}
/* Auto-close timer bar in theme blue instead of the default grey */
.swal2-timer-progress-bar {
    background: linear-gradient(135deg, #064a99, #0a63c9) !important;
}

/* ============================================================
   Config Meter form (inside SweetAlert) — tidy, symmetric layout
   ============================================================ */
.cfg-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
    text-align: left;
    margin-top: 6px;
}
.cfg-field { display: flex; flex-direction: column; gap: 5px; }
.cfg-hint { font-size: 11.5px; color: #64748b; margin-top: -1px; padding-left: 2px; line-height: 1.4; }
/* --- CML parameter picker (add CML) --- */
.cml-param-bar {
    display: flex; align-items: center; justify-content: space-between;
    margin-top: 6px; padding: 0 2px;
}
.cml-apply-model {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 14px;
    border: 1px solid #bfd7f4;
    border-radius: 11px;
    background: #eef6ff;
    color: #173b67;
    cursor: pointer;
}
.cml-apply-model input { width: 17px; height: 17px; margin-top: 2px; accent-color: #0a63c9; }
.cml-apply-model span { display: flex; flex-direction: column; gap: 2px; text-align: left; }
.cml-apply-model b { font-size: 13px; }
.cml-apply-model small { color: #607b9d; font-size: 11px; }
.cml-copy-source {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 12px 14px;
    border: 1px solid #bfd7f4;
    border-radius: 11px;
    background: #eef6ff;
    text-align: left;
}
.cml-copy-source small { color: #607b9d; font-size: 11px; }
.cml-selectall {
    display: flex; align-items: center; gap: 7px;
    font-size: 13px; font-weight: 600; color: #334862; cursor: pointer;
}
.cml-selectall input { width: 16px; height: 16px; accent-color: #0a63c9; }
.cml-count { font-size: 12px; color: #64748b; }
.cml-param-list {
    max-height: 240px; overflow-y: auto;
    border: 1px solid #e3ecf9; border-radius: 10px;
    background: #fbfdff; padding: 6px; margin-top: 6px;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior: contain;
    touch-action: pan-y;
    scrollbar-gutter: stable;
}
.cml-param {
    display: flex; align-items: center; gap: 10px;
    padding: 7px 9px; border-radius: 8px; cursor: pointer; font-size: 13.5px;
    touch-action: pan-y;
    -webkit-user-select: none;
    user-select: none;
}
.cml-param:hover { background: #eef4ff; }
.cml-param input[type=checkbox] { width: 16px; height: 16px; accent-color: #0a63c9; flex-shrink: 0; }
.cml-param-name { font-weight: 600; color: #1f2b3d; flex: 1; }
.cml-param-meta { color: #94a3b8; font-family: "SFMono-Regular", Consolas, monospace; font-size: 11.5px; white-space: nowrap; }
.cml-preview {
    margin-top: 10px; padding: 10px 12px; font-size: 13px;
    background: linear-gradient(135deg, #f4f8ff, #eef4fc);
    border: 1px solid #e3ecf9; border-radius: 12px;
}
.cml-preview-head { font-weight: 700; color: #064a99; margin-bottom: 6px; }
.cml-preview-cmd { color: #2b3a52; padding: 2px 0; }
.cml-preview-len { color: #64748b; }
.cml-preview-empty { color: #94a3b8; }
.cml-param-empty { padding: 18px 12px; color: #7b8da1; font-size: 12.5px; text-align: center; }
.cml-range-grid { margin-top: 1px; }
.cml-range-ok { color: #168958; font-size: 12px; font-weight: 700; }
.cml-range-warning { color: #c24141; font-size: 12px; font-weight: 700; }
.cfg-label {
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    padding-left: 2px;
}
.cfg-form .form-control,
.cfg-form .form-select {
    width: 100%;
    border: 1px solid #cdddf3;
    border-radius: 10px;
    background-color: #fbfdff;
    padding: 9px 12px;
    font-size: 14px;
    transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
}
.cfg-form .form-control:focus,
.cfg-form .form-select:focus {
    border-color: #0a63c9;
    box-shadow: 0 0 0 3px rgba(10, 99, 201, 0.15);
    background-color: #fff;
    outline: none;
}
/* --- Searchable combobox (RGL Name): type / select / clear, themed panel.
       Reuses .msel-panel / .msel-option so it looks like the app's dropdowns. --- */
.mcombo { position: relative; width: 100%; }
.mcombo .form-control {
    background-color: #eef4fc;
    font-weight: 600;
    color: #1f2b3d;
    padding-right: 54px;
}
.mcombo .form-control:hover { border-color: #7fb0ee; }
.mcombo .form-control:focus { background-color: #fff; }
.mcombo-caret {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    display: flex; color: #0a63c9; pointer-events: none;
}
.mcombo-clear {
    position: absolute; right: 32px; top: 50%; transform: translateY(-50%);
    width: 18px; height: 18px; padding: 0; border: none; border-radius: 50%;
    background: #dbe7fb; color: #334862; font-size: 13px; line-height: 1; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background-color .12s ease;
}
.mcombo-clear:hover { background: #c7d8f5; }
.mcombo-clear.hidden { display: none; }
.msel-option .mcombo-code { color: #94a3b8; font-family: "SFMono-Regular", Consolas, monospace; font-size: 12px; margin-left: 8px; }
.msel-empty { padding: 10px 12px; color: #94a3b8; font-size: 13px; }
/* Two equal columns → fields stay symmetric */
.cfg-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
/* Framed section per mode (RTU / TCP / UDP) */
.cfg-section {
    border: 1px solid #e3ecf9;
    border-radius: 12px;
    padding: 12px 14px;
    background: linear-gradient(135deg, #f6faff, #eef4fc);
}
.cfg-section h5 {
    margin: 0 0 10px;
    font-size: 12.5px;
    font-weight: 700;
    color: #064a99;
    text-transform: uppercase;
    letter-spacing: .5px;
}
/* --- Responsive: SweetAlert dialogs (Config Meter / Add RGL / …) --- */
@media (max-width: 560px) {
    /* stack the 2-column pairs so fields aren't cramped on phones */
    .cfg-grid { grid-template-columns: 1fr; }
    .swal2-popup { width: 94vw !important; padding: 1.4em 1.2em 1.2em !important; }
    .swal2-title { font-size: 18px !important; }
    .cfg-form { gap: 10px; }
    /* full-width buttons that wrap cleanly */
    .swal2-actions { width: 100%; }
    .swal2-styled, .swal-confirm, .swal-cancel, .swal-danger { flex: 1 1 auto; min-width: 0 !important; }
    .cml-edit-popup { width: 96vw !important; max-height: 96dvh !important; padding: 1em .9em .85em !important; overflow: hidden !important; }
    .cml-edit-popup .swal2-title { flex: 0 0 auto; }
    .cml-edit-content { min-height: 0; overflow: hidden !important; }
    .cml-edit-content .cfg-form { max-height: calc(96dvh - 170px); min-height: 0; overflow: hidden; }
    .cml-edit-content .cml-parameter-field { flex: 1 1 auto; min-height: 0; overflow: hidden; }
    .cml-edit-content .cml-param-list { min-height: 120px; max-height: none; flex: 1 1 auto; overflow-y: scroll !important; }
    .cml-edit-popup .swal2-actions { flex: 0 0 auto; margin-top: .8em !important; }
}
#customers tr:hover input, #customers tr:hover select {
  background-color: white;
}
.form-control:focus {
  background-color: white;   /* background when clicked/focused */
  outline: none;             /* optional: remove blue outline */
}
.form-select:focus {
  background-color: white;   /* background when clicked/focused */
  outline: none;             /* optional: remove blue outline */
}

m_breadcrumbs{
    cursor:pointer;
    font-weight:600;
    font-size:15px;
    color:#334862;
    padding:4px 2px;
    transition:color .15s ease;
    display:inline-flex;
    align-items:center;
    white-space:nowrap;
}
m_breadcrumbs:hover {
  color:#064a99;
}
m_breadcrumbs:focus-visible {
  color:#064a99;
  outline:2px solid #93c5fd;
  outline-offset:3px;
  border-radius:4px;
}
m_breadcrumbs.is-current {
  cursor:default;
  color:#064a99;
  font-weight:700;
  max-width:min(420px, 42vw);
  overflow:hidden;
  text-overflow:ellipsis;
}



.styled-table {
  border-collapse: separate;
  border-spacing: 0;
  margin: 8px 0;
  font-size: 14px;
  font-family: "Segoe UI", "Noto Sans Thai", Tahoma, Arial, sans-serif;
  width: 100%;
  background: #fff;
  border-radius: 12px;
  /* No overflow here — it would trap the sticky <th>. .info-scroll clips the
     rounded corners and is the scroll container the header sticks within. */
  box-shadow: 0 4px 16px rgba(6, 74, 153, 0.06);
}

.styled-table th {
  background: linear-gradient(135deg, #064a99 0%, #0a63c9 100%);
  color: #fff;
  font-weight: 600;
  font-size: 12.5px;
  letter-spacing: .4px;
  text-transform: uppercase;
  text-align: left;
  padding: 12px 14px;
  position: sticky;
  top: 0;
  z-index: 1;
}

.styled-table td {
  padding: 10px 14px;
  border-bottom: 1px solid #eef1f6;
  color: #2b3a52;
  text-align: left;
}

.styled-table tr:last-child td { border-bottom: 0; }
.styled-table tbody tr:nth-child(even),
.styled-table tr:nth-child(even) { background-color: #f7fafe; }
.styled-table tbody tr:hover,
.styled-table tr:hover { background-color: #e9f2ff; }

/* address column → monospace for readability */
.styled-table td:first-child { font-family: "SFMono-Regular", Consolas, monospace; color: #475569; }

/* info popup: table scroll area + wider modal */
.swal-info-popup { border-radius: 16px !important; }
.info-scroll { max-height: 60vh; overflow-y: auto; border-radius: 12px; }
.info-scroll::-webkit-scrollbar { width: 9px; }
.info-scroll::-webkit-scrollbar-thumb { background: #cdd9ea; border-radius: 8px; border: 2px solid #fff; }

/* info popup: device detail card */
.info-card {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px 16px;
    background: linear-gradient(135deg, #f4f8ff, #eef4fc);
    border: 1px solid #e3ecf9;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 14px;
    text-align: left;
}
.info-row { display: flex; align-items: center; gap: 8px; }
.info-key {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .3px;
    min-width: 46px;
}
.info-val { font-size: 14px; font-weight: 600; color: #1f2b3d; word-break: break-all; }
.info-empty { text-align: center; padding: 28px 16px; color: #7b8aa1; }
.info-empty i { font-size: 30px; color: #b7c8e3; display: block; margin-bottom: 10px; }
.info-empty p { margin: 0; font-size: 14px; }


/* hide scroll bar */
.hide-scrollbar {
  overflow: auto;            /* or scroll */
  scrollbar-width: none;     /* Firefox */
  -ms-overflow-style: none;  /* IE and Edge */
}

.hide-scrollbar::-webkit-scrollbar {
  display: none;             /* Chrome, Safari, Opera */
}

.hide-scrollbar {
  overflow: auto;            /* or scroll */
  scrollbar-width: none;     /* Firefox */
  -ms-overflow-style: none;  /* IE and Edge */
}

.hide-scrollbar::-webkit-scrollbar {
  display: none;             /* Chrome, Safari, Opera */
}




.group-header {
    cursor: pointer;
}
.styled-table .group-header td {
    padding: 11px 14px;
    color: #61420d;
    background: linear-gradient(90deg, #fff3cf 0%, #fff9e9 100%);
    border-top: 2px solid #f1c453;
    border-bottom: 1px solid #efd995;
    box-shadow: inset 4px 0 0 #e5a817;
}
.styled-table .group-header:hover td {
    background: linear-gradient(90deg, #ffe9a7 0%, #fff5d5 100%);
}
.function-title {
    display: flex;
    align-items: center;
    gap: 8px;
}
.function-title > i {
    color: #d29100;
    font-size: 14px;
}
.function-title small {
    padding: 2px 6px;
    border-radius: 4px;
    color: #fff;
    background: #d29100;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .5px;
}
.function-title b {
    color: #68480c;
    font-size: 13px;
}
.function-chevron {
    display: grid;
    place-items: center;
    width: 25px;
    height: 25px;
    border: 1px solid #e7c66c;
    border-radius: 50%;
    color: #9a6900;
    background: #fff;
}

.hidden-row {
    display: none;
}
.header-flex {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* ============================================================
   Modern device page (scoped: loaded only on page=device)
   ============================================================ */

/* --- Card shell --- */
.card {
    border: 1px solid #e8edf5;
    border-radius: 18px;
    box-shadow: 0 10px 30px rgba(6, 74, 153, 0.06);
    overflow: hidden;
}
.card-header {
    background: #ffffff;
    border-bottom: 1px solid #eef1f6;
    padding: 16px 20px;
    gap: 12px;
    flex-wrap: wrap;
}
.card-header > div:first-child {
    min-width: 0;
    overflow-x: auto;
    scrollbar-width: thin;
}
.card-body {
    padding: 20px;
}

/* --- Breadcrumb bar --- */
.card-header m_breadcrumbs:not(:first-child)::before {
    content: "\203A";
    margin: 0 8px 0 2px;
    color: #b7c2d2;
    font-weight: 700;
}

/* --- Search box --- */
#myInput {
    border: 1px solid #e2e8f0;
    border-radius: 0;
    min-width: 200px;
}
.search-clear-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    padding: 0;
    border: 1px solid #e2e8f0;
    border-left: 0;
    border-radius: 0 10px 10px 0;
    background: #fff;
    color: #8795a8;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    visibility: hidden;
}
.search-clear-btn.is-visible { visibility: visible; }
.search-clear-btn:hover { background: #f1f5fa; color: #d04444; }
.input-group .input-group-text {
    background: #f4f7fc;
    border: 1px solid #e2e8f0;
    border-right: 0;
    border-radius: 10px 0 0 10px;
    color: #7b8aa1;
}
.input-group:focus-within .input-group-text,
.input-group:focus-within #myInput,
.input-group:focus-within .search-clear-btn {
    border-color: #0a63c9;
}

/* --- Buttons: modern, soft, with hover lift --- */
.btn {
    border-radius: 10px;
    font-weight: 600;
    font-size: 14px;
    padding: 8px 16px;
    border: none;
    transition: transform .12s ease, box-shadow .15s ease, filter .15s ease;
}
.btn:hover {
    transform: translateY(-1px);
    filter: brightness(1.03);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
}
.btn:active { transform: translateY(0); }
.btn-primary { background: linear-gradient(135deg, #064a99, #0a63c9); color:#fff; }
.btn-success { background: linear-gradient(135deg, #16a34a, #22c55e); color:#fff; }
.btn-info    { background: linear-gradient(135deg, #0ea5e9, #38bdf8); color:#fff; }
.btn-warning { background: linear-gradient(135deg, #f59e0b, #fbbf24); color:#fff; }
.btn-danger  { background: linear-gradient(135deg, #dc2626, #ef4444); color:#fff; }
.btn-dark    { background: #334862; color:#fff; }

/* Compact icon-only action buttons inside table rows */
#customers td .btn {
    padding: 7px 10px;
    min-width: 38px;
    border-radius: 9px;
}
#customers td .btn i { font-size: 14px; }

/* --- Form controls in tables --- */
#customers .form-control,
#customers .form-select {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 13.5px;
    padding: 7px 10px;
    background-color: #fbfdff;
    transition: border-color .15s ease, box-shadow .15s ease;
}
#customers .form-control:focus,
#customers .form-select:focus {
    border-color: #0a63c9;
    box-shadow: 0 0 0 3px rgba(10, 99, 201, 0.12);
    background-color: #fff;
}

/* ============================================================
   Custom modern dropdown (.msel) — replaces native <select> UI.
   The native <select> stays in the DOM (hidden) so existing
   .val()/onchange logic keeps working; a floating panel is used
   for the styled UI.
   ============================================================ */
.msel { position: relative; display: block; width: 100%; min-width: 0; }
.msel--model { flex: 0 1 310px; width: 310px; max-width: 100%; }
.msel--bank { flex: 0 1 230px; width: 230px; max-width: 100%; }
.msel--type { flex: 0 1 165px; width: 165px; max-width: 100%; }
.msel > select { display: none !important; }

.msel-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    border: 1px solid #cdddf3;
    border-radius: 10px;
    background: #eef4fc;
    padding: 8px 12px;
    font-size: 13.5px;
    font-weight: 600;
    color: #1f2b3d;
    cursor: pointer;
    text-align: left;
    transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
}
.msel-btn:hover { border-color: #7fb0ee; background: #e2edfb; }
.msel.open .msel-btn {
    border-color: #0a63c9;
    box-shadow: 0 0 0 3px rgba(10, 99, 201, .15);
    background: #fff;
}
.msel-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.msel-caret {
    flex-shrink: 0;
    display: flex;
    color: #0a63c9;
    transition: transform .2s ease;
}
.msel.open .msel-caret { transform: rotate(180deg); }

/* Floating panel is appended to <body> and positioned with fixed
   coords so card/table overflow never clips it. */
.msel-panel {
    position: fixed;
    z-index: 3000;
    min-width: 160px;
    max-height: 288px;
    overflow-y: auto;
    background: #ffffff;
    border: 1px solid #e6edf7;
    border-radius: 12px;
    box-shadow: 0 14px 38px rgba(6, 74, 153, .20);
    padding: 6px;
    opacity: 0;
    transform: translateY(-8px) scale(.98);
    transform-origin: top center;
    pointer-events: none;
    transition: opacity .16s ease, transform .16s ease;
}
.msel-panel.show { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }

.msel-option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 9px 12px;
    border-radius: 8px;
    font-size: 13.5px;
    color: #2b3a52;
    cursor: pointer;
    white-space: nowrap;
    transition: background-color .12s ease, color .12s ease;
}
.msel-option:hover { background: #eef4ff; color: #064a99; }
.msel-option.selected { background: #e7f0ff; color: #064a99; font-weight: 700; }
.msel-option.selected::after { content: "\2713"; font-weight: 700; margin-left: 8px; }

.msel-panel::-webkit-scrollbar { width: 9px; }
.msel-panel::-webkit-scrollbar-track { background: transparent; }
.msel-panel::-webkit-scrollbar-thumb { background: #cdd9ea; border-radius: 8px; border: 2px solid #fff; }
.msel-panel::-webkit-scrollbar-thumb:hover { background: #a9c0dc; }

/* --- Badges for Model / Mode / IDs --- */
.cell-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12.5px;
    font-weight: 600;
    line-height: 1.4;
    white-space: nowrap;
}
.badge-model { background: #e7f0ff; color: #064a99; }
.badge-type  { background: #f3e8ff; color: #7c3aed; font-weight: 700; }
.badge-id    { background: #eef2f7; color: #475569; font-family: "SFMono-Regular", Consolas, monospace; }
.badge-mode  { background: #eef2f7; color: #475569; text-transform: uppercase; letter-spacing:.3px; }
.badge-mode-rtu { background: #e0f2fe; color: #0369a1; }
.badge-mode-tcp { background: #dcfce7; color: #15803d; }
.badge-mode-udp { background: #fef3c7; color: #b45309; }
.badge-cml-mode { position: relative; padding-left: 24px; border: 1px solid transparent; text-transform: none; font-family: "SFMono-Regular", Consolas, monospace; }
.badge-cml-mode::before { content: ''; position: absolute; left: 10px; top: 50%; width: 7px; height: 7px; border-radius: 50%; background: currentColor; transform: translateY(-50%); box-shadow: 0 0 0 3px rgba(255,255,255,.65); }
.badge-cml-holding { background: #e8f1ff; border-color: #bfd6ff; color: #185fbb; }
.badge-cml-input { background: #e5f8ef; border-color: #afe7cc; color: #16845a; }
.badge-cml-receive { background: #fff1dc; border-color: #f5d09b; color: #ac6509; }
.badge-cml-analog { background: #f1eaff; border-color: #d7c4ff; color: #7242bd; }
.badge-cml-binary { background: #ffe9ef; border-color: #f7bdcd; color: #bd3f64; }
.badge-cml-multistate { background: #e5f7fa; border-color: #b5e3ea; color: #17798a; }
.badge-cml-other { background: #eef2f7; border-color: #d8e0e9; color: #566678; }
.badge-cml-tcp { box-shadow: inset 3px 0 #22a06b; }
.badge-cml-rtu { box-shadow: inset 3px 0 #2787d9; }
.badge-cml-bacnet { box-shadow: inset 3px 0 #8b5bd1; }

/* --- Model selector / toolbar --- */
.model-config-toolbar {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: end;
    gap: 12px;
    margin-bottom: 12px;
    padding: 12px;
    border: 1px solid #e4ebf4;
    border-radius: 12px;
    background: #f8fbff;
}
.model-config-fields {
    display: grid;
    grid-template-columns: minmax(220px, 2fr) minmax(190px, 1.35fr) minmax(140px, 1fr) auto;
    align-items: end;
    gap: 10px;
    min-width: 0;
}
.model-control {
    display: grid;
    grid-template-rows: auto 40px;
    gap: 5px;
    min-width: 0;
}
.model-control .msel,
.model-control > select { width: 100%; max-width: none; }
.model-control .msel-btn { height: 40px; }
.model-label-row {
    display: flex;
    align-items: center;
    gap: 7px;
    min-width: 0;
}
.model-name-edit-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 27px;
    height: 27px;
    flex: 0 0 27px;
    padding: 0;
    border: 0;
    border-radius: 7px;
    color: #64748b;
    background: transparent;
    cursor: pointer;
    transition: color .15s ease, background-color .15s ease, transform .15s ease;
}
.model-name-edit-icon:hover {
    color: #064a99;
    background: #e7f0ff;
    transform: translateY(-1px);
}
.model-name-edit-icon:focus-visible {
    color: #064a99;
    background: #e7f0ff;
    outline: 2px solid #93c5fd;
    outline-offset: 2px;
}
.model-status-box {
    height: 40px;
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.model-status-box .btn {
    height: 40px;
    min-width: 40px;
    padding: 7px 12px;
}
.model-config-actions {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
}
.model-config-actions > div { display: block; }
.model-config-actions .btn { min-width: 120px; min-height: 40px; white-space: nowrap; }
@media (max-width: 1100px) {
    .model-config-toolbar { grid-template-columns: 1fr; }
    .model-config-actions { grid-template-columns: repeat(4, minmax(110px, 1fr)); }
}
@media (max-width: 800px) {
    .model-config-fields { grid-template-columns: minmax(200px, 1.5fr) minmax(180px, 1fr); }
    .model-status-box { justify-content: flex-start; }
    .model-config-actions { grid-template-columns: repeat(2, minmax(110px, 1fr)); }
}
.model-picker-label {
    font-weight: 700;
    color: #064a99;
    font-size: 14px;
    margin: 0;
    white-space: nowrap;
}
#selectModel {
    max-width: 340px;
    min-width: 200px;
    border: 1px solid #cdddf3;
    border-radius: 10px;
    font-weight: 600;
    color: #1f2b3d;
    background-color: #fff;
}
#rglBank { width: 230px; max-width: 100%; }
#modelType { width: 165px; max-width: 100%; }

/* --- Empty state --- */
.model-empty {
    text-align: center;
    padding: 40px 20px;
    color: #7b8aa1;
}
.model-empty i {
    font-size: 34px;
    color: #b7c8e3;
    margin-bottom: 12px;
    display: block;
}
.model-empty p { margin: 0; font-size: 15px; }
.model-empty b { color: #334862; }

/* --- Floating "Save All" (model page): always reachable while scrolling --- */
.fab-save {
    position: fixed;
    left: 50%;
    bottom: 24px;
    /* Hidden until scrolled down a bit; .show reveals it */
    transform: translateX(-50%) translateY(20px);
    opacity: 0;
    pointer-events: none;
    z-index: 1500;
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 13px 26px;
    border: none;
    border-radius: 999px;
    background: linear-gradient(135deg, #064a99, #0a63c9);
    color: #fff;
    font-weight: 700;
    font-size: 14.5px;
    cursor: pointer;
    box-shadow: 0 10px 26px rgba(6, 74, 153, 0.38);
    transition: transform .22s ease, opacity .22s ease, box-shadow .15s ease, filter .15s ease;
}
.fab-save.show { transform: translateX(-50%) translateY(0); opacity: 1; pointer-events: auto; }
.fab-save.show:hover { transform: translateX(-50%) translateY(-2px); filter: brightness(1.05); box-shadow: 0 14px 32px rgba(6, 74, 153, .46); }
.fab-save.show:active { transform: translateX(-50%); }
.fab-save i { font-size: 16px; }
@media (max-width: 640px) {
    .fab-save { bottom: 14px; padding: 12px 20px; font-size: 13.5px; }
}

/* --- Table scroll container: scrolls both axes so the sticky header
       (#customers th) stays visible while browsing rows, and the toolbar
       above stays put because only this box scrolls. --- */
.table-scroll {
    overflow: auto;
    width: 100%;
    max-width: 100%;
    max-height: calc(100vh - 300px);
    min-height: 240px;
    border-radius: 14px;
}
@media (max-width: 640px) {
    .model-config-toolbar { grid-template-columns: 1fr; padding: 10px; }
    .model-config-fields { grid-template-columns: 1fr; align-items: stretch; }
    .model-config-actions { grid-template-columns: 1fr 1fr; }
    .model-config-actions .btn { width: 100%; }
    .model-status-box { justify-content: space-between; }
    .msel--model, .msel--bank, .msel--type { flex: 1 1 100%; width: 100%; }
    .table-scroll { max-height: calc(100vh - 230px); }
}

/* --- Register start/end range group (sensor + cmlall) --- */
.rng-group {
    display: flex;
    align-items: center;
    gap: 6px;
    justify-content: center;
}
.rng-label {
    font-size: 11.5px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .3px;
    white-space: nowrap;
}
.rng-group .form-control {
    max-width: 90px;
    text-align: center;
}

/* --- Fade-in on load --- */
.card { animation: deviceFadeIn .3s ease both; }
@keyframes deviceFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* --- Responsive --- */
@media (max-width: 640px) {
    .card-header { padding: 12px; }
    .card-body { padding: 12px; }
    #customers td, #customers th { padding: 9px 8px; font-size: 13px; }
    .btn { padding: 7px 12px; font-size: 13px; }
    /* toolbars: let selects shrink/flex instead of overflowing */
    .model-picker-label { font-size: 13px; }
    #selectModel { min-width: 140px; }
    .msel--model { max-width: 100%; }
    #modelType, .msel { min-width: 130px; }
}

</style><?php }

function render_configurator() {
?>
<div>
    <div>
        <div>Device ID</div>
        <div><input type="text"></div>
    </div>
    <div>
        <div>Cloud Base URL</div>
        <div><input type="text"></div>
    </div>
</div><?php }
?>
