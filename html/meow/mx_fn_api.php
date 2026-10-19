<?php
/**
 * mx_fn_api.php — unified mx_fn endpoint
 *
 * รวม endpoint เดิม: mx_fn_change_password.php, mx_fn_get_wifi_status.php,
 * mx_fn_scan_wifi.php, mx_fn_user_list.php, mx_fn_user_create.php,
 * mx_fn_user_edit.php, mx_fn_user_delete.php, mx_fn_write_cmd.php,
 * mx_fn_write_network.php, mx_function_writefile.php
 *
 * เรียกด้วย ?action=<name> โดย input อื่น ๆ (GET/POST) เหมือน endpoint เดิมทุกอย่าง
 *   change_password, wifi_status, wifi_scan,
 *   user_list, user_create, user_edit, user_delete,
 *   write_cmd, write_network, write_config
 */

require_once __DIR__ . '/session_bootstrap.php';
include('mx_function.php'); // write_file(), cnee(), cned()

// ---------- shared helpers ----------

function sess_username() { return isset($_SESSION['username']) ? $_SESSION['username'] : ''; }
function sess_usertype() { return isset($_SESSION['user_type']) ? strtolower($_SESSION['user_type']) : ''; }
function sess_is_admin() { $t = sess_usertype(); return $t === 'admin' || $t === 'superadmin'; }

function require_login() {
    if (sess_username() === '') {
        http_response_code(403);
        echo "Unauthorized";
        exit;
    }
}

function require_admin() {
    if (sess_username() === '' || !sess_is_admin()) {
        http_response_code(403);
        echo "Unauthorized";
        exit;
    }
}

function auth_read() {
    $data_auth = file_get_contents('./userauth.json');
    $arr = $data_auth !== '' ? json_decode(cned($data_auth), true) : array();
    return is_array($arr) ? $arr : array();
}

function auth_write($auth_arr) {
    file_put_contents('./userauth.json', cnee(json_encode($auth_arr)));
}

function cidrToNetmask($prefix) {
    $mask = (0xffffffff << (32 - $prefix)) & 0xffffffff;
    return long2ip($mask);
}

function netmaskToCidr($netmask) {
    $long = ip2long($netmask);
    if ($long === false) return 24;
    return substr_count(str_pad(decbin($long), 32, '0', STR_PAD_LEFT), '1');
}

// ---------- actions: account ----------

function act_change_password() {
    require_login();
    $session_username = sess_username();

    $current_password = isset($_POST['current_password']) ? $_POST['current_password'] : '';
    $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : '';

    if ($current_password === '' || $new_password === '') {
        http_response_code(400);
        echo "Current and new password are required";
        return;
    }
    if (strlen($new_password) < 4) {
        http_response_code(400);
        echo "New password must be at least 4 characters";
        return;
    }

    $auth_arr = auth_read();

    // The target is always the logged-in user's own account, never taken from client input.
    if (!array_key_exists($session_username, $auth_arr)) {
        http_response_code(404);
        echo "Account not found";
        return;
    }

    $stored_hash = isset($auth_arr[$session_username]['password']) ? $auth_arr[$session_username]['password'] : '';
    if (!password_verify($current_password, $stored_hash)) {
        http_response_code(403);
        echo "Current password is incorrect";
        return;
    }

    $auth_arr[$session_username]['password'] = password_hash($new_password, PASSWORD_DEFAULT);
    auth_write($auth_arr);
    echo "Password changed successfully";
}

// ---------- actions: WiFi / network ----------

function act_wifi_scan() {
    if (sess_username() === '') {
        http_response_code(403);
        echo json_encode(array());
        return;
    }

    shell_exec("sudo nmcli device wifi rescan ifname wlan0 2>/dev/null");
    $op = shell_exec("sudo nmcli -t -f ssid dev wifi list ifname wlan0 2>/dev/null");
    $bbb = explode("\n", trim($op));
    $wf_arr = array();
    foreach ($bbb as $wfName) {
        $wfName = trim($wfName);
        if ($wfName != "" && $wfName != "--" && !in_array($wfName, $wf_arr)) {
            $wf_arr[] = $wfName;
        }
    }

    header('Content-Type: application/json');
    echo json_encode($wf_arr);
}

function act_wifi_status() {
    if (sess_username() === '') {
        http_response_code(403);
        echo json_encode(array());
        return;
    }

    shell_exec("sudo nmcli device wifi rescan ifname wlan0 2>/dev/null");
    $op = shell_exec("sudo nmcli -t -f ssid dev wifi list ifname wlan0 2>/dev/null");
    $bbb = explode("\n", trim($op));
    $wf_arr = array();
    foreach ($bbb as $wfName) {
        $wfName = trim($wfName);
        if ($wfName != "" && $wfName != "--") {
            $wf_arr[$wfName] = $wfName;
        }
    }

    // Currently-connected WiFi SSID and its saved password (if any).
    $connected_ssid = trim(shell_exec("iwgetid -r wlan0 2>/dev/null"));
    if ($connected_ssid !== '' && !isset($wf_arr[$connected_ssid])) {
        $wf_arr[$connected_ssid] = $connected_ssid;
    }

    $connected_password = '';
    if ($connected_ssid !== '') {
        $connected_profile = trim(shell_exec("nmcli -t -f GENERAL.CONNECTION dev show wlan0 2>/dev/null | cut -d: -f2"));
        if ($connected_profile !== '' && $connected_profile !== '--') {
            $profile_esc = escapeshellarg($connected_profile);
            $connected_password = trim(shell_exec("sudo nmcli -s -g 802-11-wireless-security.psk connection show $profile_esc 2>/dev/null"));
        }
    }

    $cidr = trim(shell_exec("ip -o -f inet addr show wlan0 2>/dev/null | awk '{print \$4}'"));
    $ip = '';
    $netmask = '';
    if ($cidr !== '' && strpos($cidr, '/') !== false) {
        list($ip, $prefix) = explode('/', $cidr);
        $netmask = cidrToNetmask((int)$prefix);
    }
    $gateway = trim(shell_exec("ip route | awk '/default/ && /wlan0/ {print \$3}'"));
    $primary_dns = trim(shell_exec("nmcli dev show wlan0 2>/dev/null | grep -E 'IP4.DNS\[1\]:' | awk '{print \$2}'"));
    $secoundary_dns = trim(shell_exec("nmcli dev show wlan0 2>/dev/null | grep -E 'IP4.DNS\[2\]:' | awk '{print \$2}'"));

    header('Content-Type: application/json');
    echo json_encode(array(
        'ssid_list' => array_values($wf_arr),
        'connected_ssid' => $connected_ssid,
        'connected_password' => $connected_password,
        'ip' => $ip,
        'netmask' => $netmask,
        'gateway' => $gateway,
        'primary_dns' => $primary_dns,
        'secoundary_dns' => $secoundary_dns,
    ));
}

function act_write_network() {
    require_admin();

    $data_raw = isset($_POST['dataE']) ? $_POST['dataE'] : (isset($_GET['dataE']) ? $_GET['dataE'] : "");
    $network_arr = json_decode($data_raw, true);
    if (!is_array($network_arr)) {
        http_response_code(400);
        echo "Invalid data";
        return;
    }

    $file_path = "./data/mcf_network.json";
    if (file_put_contents($file_path, json_encode($network_arr, JSON_UNESCAPED_SLASHES))) {
        echo "Data successfully saved to $file_path";
        chmod($file_path, 0777);

        $type_interface = isset($network_arr['type_interface']) ? $network_arr['type_interface'] : '';

        if ($type_interface == "LAN") {
            $connection = "Wired connection 1";

            // Strip any "/xx" suffix the user may have typed by hand, since we append our own prefix below.
            $ip_raw = isset($network_arr['ip_address']) ? preg_replace('/\/\d+$/', '', trim($network_arr['ip_address'])) : '';
            $gateway_raw = isset($network_arr['gateway']) ? trim($network_arr['gateway']) : '';

            if (!filter_var($ip_raw, FILTER_VALIDATE_IP) || !filter_var($gateway_raw, FILTER_VALIDATE_IP)) {
                http_response_code(400);
                echo "\nInvalid IP address or gateway";
                return;
            }

            $prefix_len = isset($network_arr['subnet']) && trim($network_arr['subnet']) !== ''
                ? netmaskToCidr(trim($network_arr['subnet']))
                : 24;

            $dns_list = array_filter(array(
                isset($network_arr['primary_dns']) ? trim($network_arr['primary_dns']) : '',
                isset($network_arr['secoundary_dns']) ? trim($network_arr['secoundary_dns']) : '',
            ), function ($d) { return $d !== ''; });

            $ip_address = escapeshellarg($ip_raw);
            $gateway = escapeshellarg($gateway_raw);
            $dns = escapeshellarg(implode(',', $dns_list));

            $lan_result  = shell_exec('sudo nmcli c mod "Wired connection 1" ipv4.addresses ' . $ip_address . '/' . $prefix_len . ',192.168.4.1/24 ipv4.method manual 2>&1');
            $lan_result .= shell_exec('sudo nmcli con mod "Wired connection 1" ipv4.gateway ' . $gateway . ' 2>&1');
            if (!empty($dns_list)) {
                $lan_result .= shell_exec('sudo nmcli con mod "Wired connection 1" ipv4.dns ' . $dns . ' 2>&1');
            }
            $lan_result .= shell_exec("sudo nmcli connection up \"$connection\" 2>&1");
            echo "\n" . $lan_result;
        } elseif ($type_interface == "WiFi") {
            $wifi_ssid = isset($network_arr['ssid']) ? trim($network_arr['ssid']) : '';
            $wifi_password = isset($network_arr['password']) ? $network_arr['password'] : '';

            if ($wifi_ssid !== '') {
                $ssid_esc = escapeshellarg($wifi_ssid);
                if ($wifi_password !== '') {
                    $password_esc = escapeshellarg($wifi_password);
                    $wifi_result = shell_exec("sudo nmcli dev wifi connect $ssid_esc password $password_esc ifname wlan0 2>&1");
                } else {
                    $wifi_result = shell_exec("sudo nmcli dev wifi connect $ssid_esc ifname wlan0 2>&1");
                }
                echo "\n" . $wifi_result;
            } else {
                http_response_code(400);
                echo "\nSSID is required to connect to WiFi";
            }
        }
    }
}

// ---------- actions: user management ----------

function act_user_list() {
    if (sess_username() === '' || !sess_is_admin()) {
        http_response_code(403);
        echo json_encode(array());
        return;
    }
    $session_username = sess_username();
    $session_usertype = sess_usertype();

    $auth_arr = auth_read();
    $permission_path=__DIR__.'/config/user_permissions.json';
    $permission_all=is_file($permission_path)?json_decode(file_get_contents($permission_path),true):array();
    if(!is_array($permission_all))$permission_all=array();

    $result = array();
    foreach ($auth_arr as $uname => $info) {
        $utype = isset($info['type']) ? $info['type'] : '';
        // Admins can see customer and admin accounts (including their own), but not superadmin accounts.
        if ($session_usertype === 'admin' && strtolower($utype) === 'superadmin') continue;
        $is_permission_custom=isset($permission_all[$uname])&&is_array($permission_all[$uname]);
        $user_permission=strtolower($utype)==='superadmin'
            ? array('displays'=>array('*'),'functions'=>array('*'))
            : ($is_permission_custom?$permission_all[$uname]:array('displays'=>array('dashboard'),'functions'=>array('report')));
        if(strtolower($utype)==='admin')$user_permission['displays']=array('*');
        $result[] = array(
            'username' => $uname,
            'type' => $utype,
            'is_self' => ($uname === $session_username),
            'permissions' => $user_permission,
            'permissions_custom' => $is_permission_custom,
        );
    }

    // Show the logged-in user's own account first.
    usort($result, function ($a, $b) {
        return $b['is_self'] <=> $a['is_self'];
    });

    header('Content-Type: application/json');
    echo json_encode($result);
}

function act_user_create() {
    require_admin();
    $session_usertype = sess_usertype();

    $new_username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $new_password = isset($_POST['password']) ? $_POST['password'] : '';
    $new_password_confirm = isset($_POST['password_confirm']) ? $_POST['password_confirm'] : '';
    $new_type = isset($_POST['type']) ? strtolower(trim($_POST['type'])) : '';

    // superadmin can create any role; admin is restricted to creating customer accounts only.
    $allowed_types = $session_usertype === 'superadmin'
        ? array('superadmin', 'admin', 'customer')
        : array('customer');

    if ($new_username === '' || !preg_match('/^[A-Za-z0-9_]{3,32}$/', $new_username)) {
        http_response_code(400);
        echo "Invalid username: use 3-32 letters, numbers, or underscore only";
        return;
    }
    if (strlen($new_password) < 4) {
        http_response_code(400);
        echo "Password must be at least 4 characters";
        return;
    }
    if (!hash_equals($new_password, $new_password_confirm)) {
        http_response_code(400);
        echo "Passwords do not match";
        return;
    }
    if (!in_array($new_type, $allowed_types, true)) {
        http_response_code(403);
        echo "You are not allowed to create this role";
        return;
    }

    $auth_arr = auth_read();

    if (array_key_exists($new_username, $auth_arr)) {
        http_response_code(409);
        echo "Username already exists";
        return;
    }

    $auth_arr[$new_username] = array(
        'password' => password_hash($new_password, PASSWORD_DEFAULT),
        'type' => $new_type,
    );
    auth_write($auth_arr);
    echo "User created successfully";
}

function act_user_edit() {
    require_admin();
    $session_username = sess_username();
    $session_usertype = sess_usertype();

    $target_username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $new_password = isset($_POST['password']) ? $_POST['password'] : '';
    $new_type = isset($_POST['type']) ? strtolower(trim($_POST['type'])) : '';

    if ($target_username === '') {
        http_response_code(400);
        echo "Missing username";
        return;
    }

    $auth_arr = auth_read();

    if (!array_key_exists($target_username, $auth_arr)) {
        http_response_code(404);
        echo "User not found";
        return;
    }

    $target_type = strtolower(isset($auth_arr[$target_username]['type']) ? $auth_arr[$target_username]['type'] : '');

    // Admins may edit customer and admin accounts (but not superadmin), and may only ever
    // set a role on customer accounts — an admin account's role can never be changed by an admin.
    $allowed_types = $session_usertype === 'superadmin'
        ? array('superadmin', 'admin', 'customer')
        : array('customer');

    if ($session_usertype === 'admin' && $target_type === 'superadmin') {
        http_response_code(403);
        echo "Admins cannot edit superadmin accounts";
        return;
    }
    if ($session_usertype === 'admin' && $new_type !== '' && $target_type !== 'customer') {
        http_response_code(403);
        echo "Admins cannot change the role of this account";
        return;
    }
    if ($new_type !== '' && !in_array($new_type, $allowed_types, true)) {
        http_response_code(403);
        echo "You are not allowed to set this role";
        return;
    }
    // Prevent locking yourself out by changing your own role.
    if ($new_type !== '' && $new_type !== $target_type && $target_username === $session_username) {
        http_response_code(400);
        echo "You cannot change your own role";
        return;
    }
    if ($new_password !== '' && strlen($new_password) < 4) {
        http_response_code(400);
        echo "Password must be at least 4 characters";
        return;
    }
    if ($new_type === '' && $new_password === '') {
        http_response_code(400);
        echo "Nothing to update";
        return;
    }

    if ($new_type !== '') {
        $auth_arr[$target_username]['type'] = $new_type;
    }
    if ($new_password !== '') {
        $auth_arr[$target_username]['password'] = password_hash($new_password, PASSWORD_DEFAULT);
    }
    auth_write($auth_arr);
    echo "User updated successfully";
}

function act_user_delete() {
    require_admin();
    $session_username = sess_username();
    $session_usertype = sess_usertype();

    $target_username = isset($_POST['username']) ? trim($_POST['username']) : '';

    if ($target_username === '') {
        http_response_code(400);
        echo "Missing username";
        return;
    }
    if ($target_username === $session_username) {
        http_response_code(400);
        echo "You cannot delete your own account";
        return;
    }

    $auth_arr = auth_read();

    if (!array_key_exists($target_username, $auth_arr)) {
        http_response_code(404);
        echo "User not found";
        return;
    }

    $target_type = strtolower(isset($auth_arr[$target_username]['type']) ? $auth_arr[$target_username]['type'] : '');

    // Admins may only delete customer accounts; superadmin may delete anyone but themselves.
    if ($session_usertype === 'admin' && $target_type !== 'customer') {
        http_response_code(403);
        echo "Admins can only delete customer accounts";
        return;
    }

    unset($auth_arr[$target_username]);
    auth_write($auth_arr);
    echo "User deleted successfully";
}

// ---------- actions: system ----------

function act_write_cmd() {
    // เดิมไม่มี auth — เพิ่ม guard ระดับ admin ให้ตรงกับเมนู Management (Restart/Reboot)
    // ที่แสดงเฉพาะ admin/superadmin เพื่อปิดช่องเรียกรันคำสั่งจากภายนอก
    require_admin();
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(array('success'=>false,'error'=>'POST required'));
        return;
    }
    $operation = trim((string)($_POST['operation'] ?? ''));
    if ($operation === 'restart_service') {
        $output = array(); $code = 1;
        exec('/usr/bin/sudo -n /usr/bin/systemctl restart py_multi.service 2>&1', $output, $code);
        if ($code !== 0) {
            http_response_code(500);
            echo json_encode(array('success'=>false,'error'=>trim(implode("\n",$output)) ?: 'Unable to restart py_multi.service'));
            return;
        }
        echo json_encode(array('success'=>true,'operation'=>$operation,'message'=>'py_multi.service restarted'));
        return;
    }
    if ($operation === 'reboot_system') {
        exec("nohup /bin/sh -c 'sleep 2; /usr/bin/sudo -n /sbin/reboot' >/dev/null 2>&1 &", $unused, $code);
        if ($code !== 0) {
            http_response_code(500);
            echo json_encode(array('success'=>false,'error'=>'Unable to schedule reboot'));
            return;
        }
        echo json_encode(array('success'=>true,'operation'=>$operation,'message'=>'System reboot scheduled'));
        return;
    }
    http_response_code(400);
    echo json_encode(array('success'=>false,'error'=>'Unsupported system operation'));
}

function act_write_config() {
    require_admin();

    $data_raw = isset($_POST['dataE']) ? $_POST['dataE'] : (isset($_GET['dataE']) ? $_GET['dataE'] : "");
    $data_arr = json_decode($data_raw, true);
    if (!is_array($data_arr)) {
        http_response_code(400);
        echo "Invalid data";
        return;
    }

    $file_path = "./data/mcf_default.json";

    if (file_put_contents($file_path, json_encode($data_arr, JSON_UNESCAPED_SLASHES))) {
        echo "Data successfully saved to $file_path";
        chmod($file_path, 0777);
    }
}

function act_automation_config() {
    require_admin();
    $file_path = './config/automation_control.json';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Content-Type: application/json; charset=utf-8');
        echo file_exists($file_path) ? file_get_contents($file_path) : '{}';
        return;
    }

    $raw = isset($_POST['dataE']) ? $_POST['dataE'] : '';
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        http_response_code(400);
        echo 'Invalid JSON';
        return;
    }

    $dvl = json_decode(file_get_contents('./config/dvl.json'), true);
    $detail = json_decode(file_get_contents('./config/detail_rgl.json'), true);
    $rgl = json_decode(file_get_contents('./config/rgl.json'), true);
    if (!is_array($dvl)) $dvl = array();
    if (!is_array($detail)) $detail = array();
    if (!is_array($rgl)) $rgl = array();

    $main_meter = isset($input['main_meter']) ? (string)$input['main_meter'] : '';
    if ($main_meter === '' || !isset($dvl[$main_meter])) {
        http_response_code(400);
        echo 'Main meter is invalid';
        return;
    }
    $main_model = $dvl[$main_meter]['model'] ?? '';
    if (($detail[$main_model]['type'] ?? '') !== 'meter') {
        http_response_code(400);
        echo 'Main meter must be a meter device';
        return;
    }

    $allowed_roles = array('monitor', 'solar_inverter', 'battery', 'ev');
    $devices = array();
    foreach (($input['devices'] ?? array()) as $id => $item) {
        if (!isset($dvl[$id]) || !is_array($item)) continue;
        $role = isset($item['role']) ? (string)$item['role'] : 'monitor';
        if (!in_array($role, $allowed_roles, true)) $role = 'monitor';
        $lower_kw = $role === 'battery' ? -100000 : 0;
        $min_kw = max($lower_kw, min(100000, (float)($item['min_kw'] ?? 0)));
        $max_kw = max($min_kw, min(100000, (float)($item['max_kw'] ?? 0)));
        $model = $dvl[$id]['model'] ?? '';
        $model_names = array();
        $model_map = $rgl[$model] ?? array();
        $banks = (isset($model_map['holding']) || isset($model_map['input'])) ? $model_map : array($model_map);
        foreach ($banks as $bank) {
            if (!is_array($bank)) continue;
            foreach ($bank as $info) {
                if (is_array($info) && isset($info['name'])) $model_names[] = (string)$info['name'];
            }
        }
        $min_ref = isset($item['min_ref']) && in_array((string)$item['min_ref'], $model_names, true) ? (string)$item['min_ref'] : '';
        $max_ref = isset($item['max_ref']) && in_array((string)$item['max_ref'], $model_names, true) ? (string)$item['max_ref'] : '';
        if ($role === 'battery') {
            if ($min_ref === '' && in_array('p_max_cha', $model_names, true)) $min_ref = 'p_max_cha';
            if ($max_ref === '' && in_array('p_max_dis', $model_names, true)) $max_ref = 'p_max_dis';
        }
        $devices[$id] = array(
            'role' => $role,
            'control_enabled' => !empty($item['control_enabled']) && $role !== 'monitor',
            'min_kw' => $min_kw,
            'max_kw' => $max_kw,
            'min_ref' => $min_ref,
            'max_ref' => $max_ref,
            'priority' => max(1, min(999, (int)($item['priority'] ?? 100)))
        );
    }

    $time_pattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
    $legacy_schedule = is_array($input['schedule'] ?? null) ? $input['schedule'] : array();
    $input_schedules = is_array($input['schedules'] ?? null) ? $input['schedules'] : array();
    $normalise_schedule = function ($schedule, $default_start, $default_end) use ($time_pattern) {
        if (!is_array($schedule)) $schedule = array();
        $start = (isset($schedule['start']) && preg_match($time_pattern, $schedule['start'])) ? $schedule['start'] : $default_start;
        $end = (isset($schedule['end']) && preg_match($time_pattern, $schedule['end'])) ? $schedule['end'] : $default_end;
        return array(
            'enabled' => !empty($schedule['enabled']),
            'start' => $start,
            'end' => $end,
            'weekend_enabled' => !empty($schedule['weekend_enabled'])
        );
    };
    $solar_schedule = $normalise_schedule($input_schedules['solar_inverter'] ?? $legacy_schedule, '07:30', '17:30');
    $solar_schedule['weekend_enabled'] = true;
    $battery_schedule = $normalise_schedule($input_schedules['battery'] ?? $legacy_schedule, '00:00', '23:59');
    $soc_limits = is_array($input['battery_soc_limits'] ?? null) ? $input['battery_soc_limits'] : array();
    $soc_min = max(0, min(100, (float)($soc_limits['min'] ?? 10)));
    $soc_max = max($soc_min, min(100, (float)($soc_limits['max'] ?? 95)));
    $system_limit = is_array($input['system_power_limit'] ?? null) ? $input['system_power_limit'] : array();
    $system_limit_kw = max(0, min(100000, (float)($system_limit['max_kw'] ?? 0)));
    $system_limit_delay = max(0, min(30, (int)($system_limit['delay_sec'] ?? 1)));
    $q_control = is_array($input['q_control'] ?? null) ? $input['q_control'] : array();
    $strategy = is_array($input['battery_strategy'] ?? null) ? $input['battery_strategy'] : array();
    $strategy_time = function ($key, $default) use ($strategy, $time_pattern) {
        return isset($strategy[$key]) && preg_match($time_pattern, (string)$strategy[$key])
            ? (string)$strategy[$key] : $default;
    };
    $holiday_dates = array();
    $allowed_years = array((int)date('Y'), (int)date('Y') + 1);
    foreach (($strategy['holiday_dates'] ?? array()) as $holiday) {
        $holiday = trim((string)$holiday);
        $parsed = DateTime::createFromFormat('!Y-m-d', $holiday);
        if ($parsed && $parsed->format('Y-m-d') === $holiday
            && in_array((int)$parsed->format('Y'), $allowed_years, true)) {
            $holiday_dates[$holiday] = true;
        }
    }
    $min_l_kw = max(0, min(100000, (float)($strategy['min_l_kw'] ?? 10)));
    $min_h_kw = max($min_l_kw, min(100000, (float)($strategy['min_h_kw'] ?? 40)));
    $max_l_kw = max($min_h_kw, min(100000, (float)($strategy['max_l_kw'] ?? 470)));
    $max_h_kw = max($max_l_kw, min(100000, (float)($strategy['max_h_kw'] ?? 500)));
    $day_main_min = max(0, min(100000, (float)($strategy['day_main_min_kw'] ?? 40)));
    $day_main_max = max($day_main_min, min(100000, (float)($strategy['day_main_max_kw'] ?? 70)));
    $day_main_avg = max($day_main_min, min($day_main_max, (float)($strategy['day_main_avg_kw'] ?? 55)));

    $output = array(
        'enabled' => !empty($input['enabled']),
        'simulation_log_enabled' => !empty($input['simulation_log_enabled']),
        'main_meter' => $main_meter,
        'import_threshold_kw' => max(0, min(100000, (float)($input['import_threshold_kw'] ?? 10))),
        'export_threshold_kw' => min(0, max(-100000, (float)($input['export_threshold_kw'] ?? -10))),
        'control_interval_sec' => max(0, min(3600, (int)($input['control_interval_sec'] ?? 0))),
        'system_power_limit' => array(
            'enabled' => !empty($system_limit['enabled']) && $system_limit_kw > 0,
            'max_kw' => $system_limit_kw,
            'delay_sec' => $system_limit_delay
        ),
        'battery_soc_limits' => array(
            'min' => $soc_min,
            'max' => $soc_max
        ),
        'q_control' => array(
            'enabled' => !isset($q_control['enabled']) || !empty($q_control['enabled']),
            'use_q_comp' => !isset($q_control['use_q_comp']) || !empty($q_control['use_q_comp']),
            'qt_main_max' => max(0, min(100000, (float)($q_control['qt_main_max'] ?? 300))),
            'qt_main_min' => max(0, min(100000, (float)($q_control['qt_main_min'] ?? 270))),
            'min_soc' => max(0, min(100, (float)($q_control['min_soc'] ?? 20))),
            'battery_max_kvar' => max(0, min(100000, (float)($q_control['battery_max_kvar'] ?? 100)))
        ),
        'battery_strategy' => array(
            'enabled' => !isset($strategy['enabled']) || !empty($strategy['enabled']),
            'capacity_kwh' => max(0.1, min(100000, (float)($strategy['capacity_kwh'] ?? 240))),
            'max_charge_kw' => max(0, min(100000, (float)($strategy['max_charge_kw'] ?? 100))),
            'off_peak_start' => $strategy_time('off_peak_start', '06:00'),
            'off_peak_duration_hours' => max(0.1, min(24, (float)($strategy['off_peak_duration_hours'] ?? 3))),
            'dynamic_charge_target_soc' => max($soc_min, min($soc_max, (float)($strategy['dynamic_charge_target_soc'] ?? 95))),
            'charge_efficiency_percent' => max(1, min(100, (float)($strategy['charge_efficiency_percent'] ?? 90))),
            'charge_step_kw' => max(0.1, min(100000, (float)($strategy['charge_step_kw'] ?? 10))),
            'discharge_step_kw' => max(0.1, min(100000, (float)($strategy['discharge_step_kw'] ?? 50))),
            'normal_max_step_kw' => max(0.1, min(100000, (float)($strategy['normal_max_step_kw'] ?? 50))),
            'on_peak_start' => $strategy_time('on_peak_start', '09:00'),
            'on_peak_end' => $strategy_time('on_peak_end', '22:00'),
            'day_control_start' => $strategy_time('day_control_start', '09:00'),
            'day_control_end' => $strategy_time('day_control_end', '11:30'),
            'peak_window_end' => $strategy_time('peak_window_end', '18:00'),
            'day_min_soc' => max($soc_min, min(100, (float)($strategy['day_min_soc'] ?? 80))),
            'day_grid_trigger_kw' => max(0, min(100000, (float)($strategy['day_grid_trigger_kw'] ?? 50))),
            'day_main_min_kw' => $day_main_min,
            'day_main_max_kw' => $day_main_max,
            'day_main_avg_kw' => $day_main_avg,
            'day_discharge_kw' => max(0, min(100000, (float)($strategy['day_discharge_kw'] ?? 20))),
            'min_l_kw' => $min_l_kw,
            'min_h_kw' => $min_h_kw,
            'max_l_kw' => $max_l_kw,
            'max_h_kw' => $max_h_kw,
            'peak_reserve_soc' => max($soc_min, min(100, (float)($strategy['peak_reserve_soc'] ?? 50))),
            'discharge_start' => $strategy_time('discharge_start', '19:00'),
            'discharge_duration_hours' => max(0.1, min(24, (float)($strategy['discharge_duration_hours'] ?? 3))),
            'scheduled_discharge_min_soc' => max(0, min(100, (float)($strategy['scheduled_discharge_min_soc'] ?? 10))),
            'scheduled_discharge_kw' => max(0, min(100000, (float)($strategy['scheduled_discharge_kw'] ?? 50))),
            'off_day_min_soc' => max($soc_min, min(100, (float)($strategy['off_day_min_soc'] ?? 85))),
            'off_day_main_min_kw' => max(0, min(100000, (float)($strategy['off_day_main_min_kw'] ?? 10))),
            'off_day_main_max_kw' => max(0, min(100000, (float)($strategy['off_day_main_max_kw'] ?? 40))),
            'off_day_main_avg_kw' => max(0, min(100000, (float)($strategy['off_day_main_avg_kw'] ?? 25))),
            'off_day_discharge_kw' => max(0, min(100000, (float)($strategy['off_day_discharge_kw'] ?? 0))),
            'holiday_dates' => array_keys($holiday_dates)
        ),
        'schedules' => array(
            'solar_inverter' => $solar_schedule,
            'battery' => $battery_schedule
        ),
        'devices' => $devices,
        'updated_at' => date('c'),
        'updated_by' => sess_username()
    );

    $tmp = $file_path . '.tmp';
    $encoded = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($tmp, $encoded, LOCK_EX) === false || !rename($tmp, $file_path)) {
        @unlink($tmp);
        http_response_code(500);
        echo 'Unable to save automation config';
        return;
    }
    @chmod($file_path, 0664);
    header('Content-Type: application/json; charset=utf-8');
    echo $encoded;
}

function act_battery_pmax() {
    require_admin();
    header('Content-Type: application/json; charset=utf-8');

    $device_id = isset($_POST['device_id']) ? trim((string)$_POST['device_id']) : '';
    $p_max = isset($_POST['p_max']) && is_numeric($_POST['p_max']) ? (float)$_POST['p_max'] : null;
    if ($device_id === '' || $p_max === null || !is_finite($p_max)) {
        http_response_code(400);
        echo json_encode(array('error' => 'Invalid device or p_max'));
        return;
    }

    $dvl = json_decode(@file_get_contents('./config/dvl.json'), true);
    $detail = json_decode(@file_get_contents('./config/detail_rgl.json'), true);
    $rgl = json_decode(@file_get_contents('./config/rgl.json'), true);
    if (!isset($dvl[$device_id])) {
        http_response_code(404);
        echo json_encode(array('error' => 'Battery device not found'));
        return;
    }
    $model = $dvl[$device_id]['model'] ?? '';
    if (($detail[$model]['type'] ?? '') !== 'battery') {
        http_response_code(400);
        echo json_encode(array('error' => 'Selected device is not a Battery'));
        return;
    }
    $holding = $rgl[$model]['holding'] ?? array();
    $writable = false;
    foreach ($holding as $info) {
        if (is_array($info) && ($info['name'] ?? '') === 'p_max') {
            $writable = true;
            break;
        }
    }
    if (!$writable) {
        http_response_code(400);
        echo json_encode(array('error' => 'Battery does not support holding.p_max'));
        return;
    }

    $automation = json_decode(@file_get_contents('./config/automation_control.json'), true);
    $control = is_array($automation) ? ($automation['devices'][$device_id] ?? array()) : array();
    $min_kw = isset($control['min_kw']) ? (float)$control['min_kw'] : -100000;
    $max_kw = isset($control['max_kw']) ? (float)$control['max_kw'] : 100000;
    if ($p_max < $min_kw || $p_max > $max_kw) {
        http_response_code(400);
        echo json_encode(array('error' => "p_max must be between {$min_kw} and {$max_kw} kW"));
        return;
    }

    $cmd_id = 'E' . round(microtime(true) * 1000);
    $command = array(
        'cmd' => array(
            $device_id => array(
                'p_max' => array('write' => $p_max)
            )
        )
    );

    // The web server may update cma.json but cannot create a temporary file
    // in /home/mdbcare/python. Lock and update the existing shared file so the
    // command reaches Process_CMA without requiring directory write access.
    $command_path = '/home/mdbcare/python/cma.json';
    $handle = @fopen($command_path, 'c+');
    $queued = false;
    if ($handle !== false && @flock($handle, LOCK_EX)) {
        rewind($handle);
        $current = json_decode((string)stream_get_contents($handle), true);
        if (!is_array($current)) $current = array();
        $current[$cmd_id] = $command;
        $encoded = json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded !== false) {
            rewind($handle);
            if (@ftruncate($handle, 0) && @fwrite($handle, $encoded) === strlen($encoded)) {
                @fflush($handle);
                $queued = true;
            }
        }
        @flock($handle, LOCK_UN);
    }
    if ($handle !== false) @fclose($handle);
    if (!$queued) {
        http_response_code(500);
        echo json_encode(array('error' => 'Unable to queue Battery command'));
        return;
    }
    echo json_encode(array(
        'queued' => true,
        'cmd_id' => $cmd_id,
        'device_id' => $device_id,
        'p_max' => $p_max
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function act_device_parameter_write() {
    require_admin();
    header('Content-Type: application/json; charset=utf-8');
    $device_id = trim((string)($_POST['device_id'] ?? ''));
    $parameter = trim((string)($_POST['parameter'] ?? ''));
    $value = isset($_POST['value']) && is_numeric($_POST['value']) ? (float)$_POST['value'] : null;
    if ($device_id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $parameter) || $value === null || !is_finite($value)) {
        http_response_code(400);
        echo json_encode(array('error' => 'Invalid device, parameter or value'));
        return;
    }
    $dvl = json_decode(@file_get_contents('./config/dvl.json'), true);
    $rgl = json_decode(@file_get_contents('./config/rgl.json'), true);
    if (!isset($dvl[$device_id])) {
        http_response_code(404);
        echo json_encode(array('error' => 'Device not found'));
        return;
    }
    $model = $dvl[$device_id]['model'] ?? '';
    $holding = $rgl[$model]['holding'] ?? array();
    $writable = false;
    foreach ($holding as $info) {
        if (is_array($info) && ($info['name'] ?? '') === $parameter) {
            $writable = true;
            break;
        }
    }
    if (!$writable) {
        http_response_code(400);
        echo json_encode(array('error' => $parameter . ' is not available in holding register'));
        return;
    }
    $command_path = '/home/mdbcare/python/cma.json';
    $current = json_decode(@file_get_contents($command_path), true);
    if (!is_array($current)) $current = array();
    $cmd_id = 'E' . round(microtime(true) * 1000);
    $current[$cmd_id] = array('cmd' => array(
        $device_id => array($parameter => array('write' => $value))
    ));
    $tmp = $command_path . '.web.tmp';
    $encoded = json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($tmp, $encoded, LOCK_EX) === false || !@rename($tmp, $command_path)) {
        @unlink($tmp);
        http_response_code(500);
        echo json_encode(array('error' => 'Unable to queue device command'));
        return;
    }
    @chmod($command_path, 0777);
    echo json_encode(array('queued' => true, 'cmd_id' => $cmd_id, 'device_id' => $device_id, 'parameter' => $parameter, 'value' => $value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function act_parameter_list() {
    require_admin();
    $path = './config/parameter_list.json';
    $input = json_decode($_POST['dataE'] ?? '', true);
    if (!is_array($input)) {
        http_response_code(400);
        echo 'Invalid Parameter List';
        return;
    }
    $current = json_decode(@file_get_contents($path), true);
    if (!is_array($current)) $current = array();
    $current_by_parameter = array();
    $max_id = 0;
    $max_order = 0;
    foreach ($current as $row) {
        if (!is_array($row) || !isset($row['Parameter'])) continue;
        $current_by_parameter[(string)$row['Parameter']] = $row;
        $max_id = max($max_id, (int)($row['ID'] ?? 0));
        $max_order = max($max_order, (int)($row['Order'] ?? 0));
    }
    $updates = array();
    foreach ($input as $parameter => $settings) {
        if (is_int($parameter) && is_array($settings) && isset($settings['Parameter'])) {
            $parameter = (string)$settings['Parameter'];
            $settings = isset($settings['InfoData']) && is_array($settings['InfoData']) ? $settings['InfoData'] : array();
        }
        if (!is_array($settings) || !preg_match('/^[A-Za-z0-9_-]+$/', (string)$parameter)) continue;
        $display_name = trim((string)($settings['NameShort'] ?? $settings['DisplayName'] ?? $settings['display_name'] ?? $parameter));
        $full_name = trim((string)($settings['Name'] ?? $settings['full_name'] ?? $display_name));
        $description = trim((string)($settings['Description'] ?? $settings['description'] ?? ''));
        $category = trim((string)($settings['Category'] ?? $settings['category'] ?? ''));
        $unit = trim((string)($settings['Unit'] ?? $settings['unit'] ?? ''));
        $icon = trim((string)($settings['Icon'] ?? $settings['icon'] ?? ''));
        $row = $current_by_parameter[$parameter] ?? array(
            'ID' => ++$max_id,
            'Order' => ++$max_order,
            'Parameter' => $parameter,
            'Info' => '{}',
            'InfoData' => array()
        );
        if (!isset($row['InfoData']) || !is_array($row['InfoData'])) $row['InfoData'] = array();
        $row['Parameter'] = $parameter;
        $row['InfoData']['Name'] = substr($full_name !== '' ? $full_name : $parameter, 0, 360);
        $row['InfoData']['NameShort'] = substr($display_name !== '' ? $display_name : $parameter, 0, 240);
        $row['InfoData']['Unit'] = substr($unit, 0, 80);
        if ($icon !== '' || array_key_exists('Icon', $row['InfoData'])) $row['InfoData']['Icon'] = substr($icon, 0, 500);
        if ($category !== '' || array_key_exists('Category', $row['InfoData'])) $row['InfoData']['Category'] = substr($category, 0, 120);
        if ($description !== '' || array_key_exists('Description', $row['InfoData'])) $row['InfoData']['Description'] = substr($description, 0, 1000);
        $updates[$parameter] = $row;
    }
    $replace = isset($_POST['replace']) && (string)$_POST['replace'] === '1';
    if ($replace) {
        $current_by_parameter = $updates;
    } else {
        foreach ($updates as $parameter => $row) $current_by_parameter[$parameter] = $row;
    }
    $output = array_values($current_by_parameter);
    usort($output, function ($a, $b) {
        $order_compare = ((int)($a['Order'] ?? 0)) <=> ((int)($b['Order'] ?? 0));
        return $order_compare !== 0 ? $order_compare : strcmp((string)($a['Parameter'] ?? ''), (string)($b['Parameter'] ?? ''));
    });
    foreach ($output as $index => &$row) $row['ID'] = $index + 1;
    unset($row);
    $encoded = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $encoded, LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        http_response_code(500);
        echo 'Unable to save Parameter List';
        return;
    }
    @chmod($path, 0664);
    header('Content-Type: application/json; charset=utf-8');
    echo $encoded;
}

function act_command_list_save() {
    require_admin();
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode($_POST['dataE'] ?? '', true);
    if (!is_array($input)) {
        http_response_code(400); echo json_encode(array('error' => 'Invalid Command List')); return;
    }
    $protocols = array('modbus_tcp', 'modbus_rtu', 'bacnet');
    $functions = array(
        'modbus_tcp' => array('read_holding_tcp', 'read_input_tcp', 'write_single_tcp', 'write_coil_tcp'),
        'modbus_rtu' => array('read_holding_rtu', 'read_input_rtu', 'recv_holding_rtu', 'write_single_rtu', 'write_coil_rtu'),
        'bacnet' => array('analogInput', 'analogOutput', 'analogValue', 'binaryInput', 'binaryOutput', 'binaryValue', 'multiStateInput', 'multiStateOutput', 'multiStateValue')
    );
    $types = array('Float32','Float32 inverse','4Q FP PF','Int Sign16','Int Unsign16','Int16 Binary','Long Unsign32','Long Unsign32 inverse','Long Sign32','Long Unsign64','Long Sign64');
    $output = array(); $seen = array();
    foreach ($input as $index => $row) {
        if (!is_array($row)) continue;
        $command = trim((string)($row['cmdid'] ?? $row['Command'] ?? ''));
        if ($command === '') {
            do {
                $command = 'C' . sprintf('%014d', (int)floor(microtime(true) * 10000));
                if (isset($seen[$command])) usleep(100);
            } while (isset($seen[$command]));
        }
        $protocol = strtolower(trim((string)($row['Protocol'] ?? '')));
        $function = trim((string)($row['Function'] ?? ''));
        $target = trim((string)($row['Target'] ?? ''));
        if (!preg_match('/^C[0-9]{14}$/', $command) || isset($seen[$command])) {
            http_response_code(400); echo json_encode(array('error' => 'Invalid or duplicate cmdid: '.$command)); return;
        }
        if (!in_array($protocol, $protocols, true) || !in_array($function, $functions[$protocol], true) || $target === '') {
            http_response_code(400); echo json_encode(array('error' => 'Invalid protocol, function, or target for '.$command)); return;
        }
        $ip_address = trim((string)($row['IpAddress'] ?? ''));
        $port = $row['Port'] ?? '';
        if ($protocol === 'modbus_tcp' && (filter_var($ip_address, FILTER_VALIDATE_IP) === false || filter_var($port, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1,'max_range'=>65535))) === false)) {
            http_response_code(400); echo json_encode(array('error' => 'Invalid IP Address or Port for '.$command)); return;
        }
        $unit_id = $row['UnitId'] ?? 1;
        $serial_port = trim((string)($row['SerialPort'] ?? ''));
        $baud_rate = $row['BaudRate'] ?? 9600; $parity = strtolower((string)($row['Parity'] ?? 'none'));
        $stop_bits = $row['StopBits'] ?? 1; $slave_id = $row['SlaveId'] ?? 1;
        $bacnet_ip = trim((string)($row['BacnetIp'] ?? '')); $bacnet_port = $row['BacnetPort'] ?? 47808;
        $device_instance = $row['DeviceInstance'] ?? '';
        if ($protocol === 'modbus_tcp' && filter_var($unit_id,FILTER_VALIDATE_INT,array('options'=>array('min_range'=>0,'max_range'=>255))) === false) {
            http_response_code(400); echo json_encode(array('error'=>'Invalid Modbus Unit ID for '.$command)); return;
        }
        if ($protocol === 'modbus_rtu' && ($serial_port==='' || !in_array((int)$baud_rate,array(9600,19200,38400,57600,115200),true) || !in_array($parity,array('none','even','odd'),true) || !in_array((int)$stop_bits,array(1,2),true) || filter_var($slave_id,FILTER_VALIDATE_INT,array('options'=>array('min_range'=>1,'max_range'=>247)))===false)) {
            http_response_code(400); echo json_encode(array('error'=>'Invalid Modbus RTU settings for '.$command)); return;
        }
        if ($protocol === 'bacnet' && (filter_var($bacnet_ip,FILTER_VALIDATE_IP)===false || filter_var($bacnet_port,FILTER_VALIDATE_INT,array('options'=>array('min_range'=>1,'max_range'=>65535)))===false || filter_var($device_instance,FILTER_VALIDATE_INT,array('options'=>array('min_range'=>0,'max_range'=>4194303)))===false)) {
            http_response_code(400); echo json_encode(array('error'=>'Invalid BACnet settings for '.$command)); return;
        }
        $type = (string)($row['DataType'] ?? 'Int Unsign16');
        $legacy_types = array('Int16'=>'Int Sign16','UInt16'=>'Int Unsign16','Int32'=>'Long Sign32','UInt32'=>'Long Unsign32','Boolean'=>'Int16 Binary');
        if (isset($legacy_types[$type])) $type = $legacy_types[$type];
        if (!in_array($type, $types, true)) {
            http_response_code(400); echo json_encode(array('error'=>'Unsupported Data Type for '.$command)); return;
        }
        $schedule_enabled = ($row['ScheduleEnabled'] ?? false) === true;
        $schedule_mode = strtolower((string)($row['ScheduleMode'] ?? 'once'));
        $schedule_date = trim((string)($row['ScheduleDate'] ?? ''));
        $schedule_time = trim((string)($row['ScheduleTime'] ?? '00:00:00'));
        $duration_value = (int)($row['DurationValue'] ?? 60); $duration_unit = strtolower((string)($row['DurationUnit'] ?? 'seconds'));
        $repeat_value = (int)($row['RepeatValue'] ?? 1); $repeat_unit = strtolower((string)($row['RepeatUnit'] ?? 'minutes'));
        $week_days = array_values(array_unique(array_map('intval',(array)($row['WeekDays'] ?? array())))); sort($week_days);
        $duration_limits = array('seconds'=>60,'minutes'=>60,'hours'=>24);
        $duration_invalid = !isset($duration_limits[$duration_unit]) || $duration_value < 0 || $duration_value > ($duration_limits[$duration_unit] ?? 0);
        if ($schedule_enabled && $duration_invalid) {
            $limit = $duration_limits[$duration_unit] ?? 0;
            http_response_code(400); echo json_encode(array('error'=>'Run duration must be between 0 and '.$limit.' '.$duration_unit)); return;
        }
        if ($schedule_enabled && (!in_array($schedule_mode,array('once','daily','weekly'),true) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/',$schedule_time) || $duration_invalid || $repeat_value<1 || !in_array($repeat_unit,array('seconds','minutes','hours','days'),true) || ($schedule_mode==='once' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$schedule_date)) || ($schedule_mode==='weekly' && !$week_days))) {
            http_response_code(400); echo json_encode(array('error'=>'Invalid Schedule settings for '.$command)); return;
        }
        foreach ($week_days as $day) if ($day<0 || $day>6) { http_response_code(400); echo json_encode(array('error'=>'Invalid weekday for '.$command)); return; }
        $value = $row['Value'] ?? '';
        $min = $row['Min'] ?? ''; $max = $row['Max'] ?? '';
        if (($value !== '' && !is_numeric($value)) || $min !== '' && !is_numeric($min) || $max !== '' && !is_numeric($max) || $min !== '' && $max !== '' && (float)$min > (float)$max) {
            http_response_code(400); echo json_encode(array('error' => 'Invalid value range for '.$command)); return;
        }
        $seen[$command] = true;
        $output[] = array('ID'=>count($output)+1,'cmdid'=>$command,'Name'=>substr(trim((string)($row['Name'] ?? $command)),0,160),'Protocol'=>$protocol,'Function'=>$function,'IpAddress'=>$protocol==='modbus_tcp'?$ip_address:'','Port'=>$protocol==='modbus_tcp'?(int)$port:'','UnitId'=>$protocol==='modbus_tcp'?(int)$unit_id:'','SerialPort'=>$protocol==='modbus_rtu'?$serial_port:'','BaudRate'=>$protocol==='modbus_rtu'?(int)$baud_rate:'','Parity'=>$protocol==='modbus_rtu'?$parity:'','StopBits'=>$protocol==='modbus_rtu'?(int)$stop_bits:'','SlaveId'=>$protocol==='modbus_rtu'?(int)$slave_id:'','BacnetIp'=>$protocol==='bacnet'?$bacnet_ip:'','BacnetPort'=>$protocol==='bacnet'?(int)$bacnet_port:'','DeviceInstance'=>$protocol==='bacnet'?(int)$device_instance:'','Target'=>substr($target,0,120),'Value'=>$value===''?'':(float)$value,'DataType'=>$type,'Min'=>$min===''?'':(float)$min,'Max'=>$max===''?'':(float)$max,'Unit'=>substr(trim((string)($row['Unit'] ?? '')),0,40),'Description'=>substr(trim((string)($row['Description'] ?? '')),0,500),'ScheduleEnabled'=>$schedule_enabled,'ScheduleMode'=>$schedule_mode,'ScheduleDate'=>$schedule_mode==='once'?$schedule_date:'','ScheduleTime'=>$schedule_time,'DurationValue'=>$duration_value,'DurationUnit'=>$duration_unit,'RepeatValue'=>$repeat_value,'RepeatUnit'=>$repeat_unit,'WeekDays'=>$schedule_mode==='weekly'?$week_days:array(),'Enabled'=>($row['Enabled'] ?? true)!==false);
    }
    $path = './config/command_list.json'; $tmp = $path.'.tmp';
    $encoded = json_encode($output, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if (file_put_contents($tmp,$encoded,LOCK_EX)===false || !@rename($tmp,$path)) {
        @unlink($tmp); http_response_code(500); echo json_encode(array('error'=>'Unable to save Command List')); return;
    }
    @chmod($path,0664); echo $encoded;
}

function act_serial_ports() {
    require_admin();
    header('Content-Type: application/json; charset=utf-8');
    $patterns = array('/dev/ttyUSB*','/dev/ttyACM*','/dev/ttyAMA*','/dev/ttyS*','/dev/serial/by-id/*');
    $ports = array();
    foreach ($patterns as $pattern) {
        foreach ((array)glob($pattern) as $path) {
            if (!file_exists($path)) continue;
            $real = realpath($path) ?: $path;
            $key = $real;
            if (isset($ports[$key])) continue;
            $ports[$key] = array('path'=>$path,'device'=>$real,'readable'=>is_readable($path),'writable'=>is_writable($path));
        }
    }
    uasort($ports,function($a,$b){return strcmp($a['path'],$b['path']);});
    echo json_encode(array_values($ports),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}

function act_command_manual_run() {
    require_admin();
    header('Content-Type: application/json; charset=utf-8');
    $cmdid = trim((string)($_POST['cmdid'] ?? ''));
    if (!preg_match('/^C[0-9]{14}$/', $cmdid)) {
        http_response_code(400); echo json_encode(array('error'=>'Invalid cmdid')); return;
    }
    $items = json_decode(@file_get_contents('./config/command_list.json'), true);
    if (!is_array($items)) $items = array();
    $command = null;
    foreach ($items as $item) {
        if (is_array($item) && (string)($item['cmdid'] ?? '') === $cmdid) { $command = $item; break; }
    }
    if (!$command) { http_response_code(404); echo json_encode(array('error'=>'Command not found')); return; }
    if (($command['Enabled'] ?? true) === false) { http_response_code(409); echo json_encode(array('error'=>'Command is disabled')); return; }
    if (strpos(strtolower((string)($command['Function'] ?? '')), 'write_') === 0 && ($command['Value'] ?? '') === '') {
        http_response_code(400); echo json_encode(array('error'=>'Value is required for write command')); return;
    }
    $queue = './config/command_queue';
    if (!is_dir($queue) && !@mkdir($queue, 0770, true)) { http_response_code(500); echo json_encode(array('error'=>'Unable to create local command queue')); return; }
    $run_id = 'run_'.date('YmdHis').'_'.bin2hex(random_bytes(5));
    $command['_run_id'] = $run_id;
    $command['_queued_at'] = date('Y-m-d H:i:s');
    $file = $queue.'/'.$cmdid.'_'.$run_id.'.json';
    $encoded = json_encode($command, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($file, $encoded, LOCK_EX) === false) { http_response_code(500); echo json_encode(array('error'=>'Unable to queue command')); return; }
    @chmod($file, 0660);
    echo json_encode(array('success'=>true,'queued'=>true,'cmdid'=>$cmdid,'run_id'=>$run_id));
}

function act_command_manual_result() {
    require_admin();
    header('Content-Type: application/json; charset=utf-8');
    $run_id = trim((string)($_GET['run_id'] ?? ''));
    if (!preg_match('/^run_[A-Za-z0-9_-]{10,80}$/', $run_id)) {
        http_response_code(400); echo json_encode(array('error'=>'Invalid run id')); return;
    }
    $path = './config/command_results/'.$run_id.'.json';
    if (!is_file($path)) { echo json_encode(array('pending'=>true,'run_id'=>$run_id)); return; }
    $result = json_decode(@file_get_contents($path), true);
    if (!is_array($result)) { http_response_code(500); echo json_encode(array('error'=>'Invalid local feedback')); return; }
    @unlink($path);
    echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

// ---------- dispatch ----------

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'change_password': act_change_password(); break;
    case 'wifi_scan':       act_wifi_scan();       break;
    case 'wifi_status':     act_wifi_status();     break;
    case 'write_network':   act_write_network();   break;
    case 'user_list':       act_user_list();       break;
    case 'user_create':     act_user_create();     break;
    case 'user_edit':       act_user_edit();       break;
    case 'user_delete':     act_user_delete();     break;
    case 'write_cmd':       act_write_cmd();       break;
    case 'write_config':    act_write_config();    break;
    case 'automation_config': act_automation_config(); break;
    case 'battery_pmax': act_battery_pmax(); break;
    case 'device_parameter_write': act_device_parameter_write(); break;
    case 'parameter_list': act_parameter_list(); break;
    case 'command_list_save': act_command_list_save(); break;
    case 'command_manual_run': act_command_manual_run(); break;
    case 'command_manual_result': act_command_manual_result(); break;
    case 'serial_ports': act_serial_ports(); break;
    default:
        http_response_code(400);
        echo "unknown action";
}
?>
