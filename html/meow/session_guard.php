<?php
// Enforce one active PHP session per username.
if (!defined('MDB_STATE_DIR')) define('MDB_STATE_DIR', __DIR__ . '/data/.session_guard');

function mdb_guard_prepare(): bool {
    if (!is_dir(MDB_STATE_DIR)) @mkdir(MDB_STATE_DIR, 0770, true);
    if (!is_dir(MDB_STATE_DIR . '/active')) @mkdir(MDB_STATE_DIR . '/active', 0770, true);
    return is_dir(MDB_STATE_DIR . '/active') && is_writable(MDB_STATE_DIR . '/active');
}

function mdb_valid_sid(string $sid): bool { return (bool)preg_match('/^[A-Za-z0-9,-]{16,128}$/', $sid); }
function mdb_client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

function mdb_device_id(bool $create = true): string {
    $id = (string)($_COOKIE['mdb_did'] ?? '');
    if (preg_match('/^[a-f0-9]{16}$/', $id)) return $id;
    if (!$create) return '';
    $id = bin2hex(random_bytes(8));
    if (!headers_sent()) setcookie('mdb_did', $id, ['expires'=>time()+157680000,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);
    $_COOKIE['mdb_did'] = $id;
    return $id;
}

function mdb_device_info(): string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $os = preg_match('/Windows NT 10\.0/', $ua) ? 'Windows 10/11' : (stripos($ua, 'Android') !== false ? 'Android' : (stripos($ua, 'iPhone') !== false ? 'iPhone' : (stripos($ua, 'Mac OS X') !== false ? 'macOS' : 'Desktop')));
    if (preg_match('#Edg(?:e|A|iOS)?/([\d]+)#', $ua, $m)) $browser = 'Edge '.$m[1];
    elseif (preg_match('#(?:Chrome|CriOS)/([\d]+)#', $ua, $m)) $browser = 'Chrome '.$m[1];
    elseif (preg_match('#(?:Firefox|FxiOS)/([\d]+)#', $ua, $m)) $browser = 'Firefox '.$m[1];
    elseif (preg_match('#Version/([\d]+).*Safari#', $ua, $m)) $browser = 'Safari '.$m[1];
    else $browser = 'Browser';
    return $os . ' · ' . $browser;
}

function mdb_drop_session(string $sid): void {
    if (!mdb_valid_sid($sid)) return;
    $path = (string)session_save_path();
    if (($at = strrpos($path, ';')) !== false) $path = substr($path, $at + 1);
    if ($path === '') $path = '/var/lib/php/sessions';
    @unlink(rtrim($path, '/') . '/sess_' . $sid);
}

function mdb_login_success(string $user, string $type): ?array {
    if (!mdb_guard_prepare()) return null;
    $sid = session_id(); $did = mdb_device_id(); $kicked = null;
    $current = ['user'=>$user,'sid'=>$sid,'did'=>$did,'device'=>mdb_device_info(),'ip'=>mdb_client_ip(),'since'=>date('c')];
    $file = MDB_STATE_DIR . '/active/' . sha1($user) . '.json';
    $fh = @fopen($file, 'c+');
    if (!$fh) return null;
    if (flock($fh, LOCK_EX)) {
        $old = json_decode((string)stream_get_contents($fh), true);
        if (is_array($old) && !empty($old['sid']) && $old['sid'] !== $sid) {
            mdb_drop_session((string)$old['sid']);
            $kicked = $old;
            if (mdb_valid_sid((string)$old['sid'])) @file_put_contents(MDB_STATE_DIR . '/active/kicked_' . sha1((string)$old['sid']) . '.json', json_encode(['user'=>$user,'device'=>$current['device'],'ip'=>$current['ip'],'at'=>$current['since']], JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($current, JSON_UNESCAPED_UNICODE)); fflush($fh); flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $kicked;
}

function mdb_logout(string $user): void {
    if ($user === '' || !mdb_guard_prepare()) return;
    $file = MDB_STATE_DIR . '/active/' . sha1($user) . '.json';
    $current = json_decode((string)@file_get_contents($file), true);
    if (is_array($current) && ($current['sid'] ?? '') === session_id()) @unlink($file);
}

function mdb_read_kick_cookie(): ?array {
    $raw = (string)($_COOKIE['mdb_kicked'] ?? '');
    if ($raw === '' || strlen($raw) > 800) return null;
    $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
    $info = $decoded === false ? null : json_decode($decoded, true);
    return is_array($info) ? $info : null;
}

function mdb_clear_kick_cookie(): void { if (!headers_sent()) setcookie('mdb_kicked', '', ['expires'=>1,'path'=>'/','samesite'=>'Lax']); }

function mdb_take_kick_notice(): ?array {
    $sid = (string)($_COOKIE[session_name()] ?? '');
    if (mdb_valid_sid($sid)) {
        $file = MDB_STATE_DIR . '/active/kicked_' . sha1($sid) . '.json';
        $info = json_decode((string)@file_get_contents($file), true);
        if (is_array($info)) {
            @unlink($file);
            if (!headers_sent()) setcookie('mdb_kicked', rtrim(strtr(base64_encode(json_encode($info, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '='), ['expires'=>time()+600,'path'=>'/','samesite'=>'Lax']);
            return $info;
        }
    }
    return mdb_read_kick_cookie();
}
