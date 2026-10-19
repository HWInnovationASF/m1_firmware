<?php
// Keep authenticated sessions for 30 days and renew the cookie while the
// application is actively used.
const MDB_SESSION_LIFETIME = 2592000;

ini_set('session.gc_maxlifetime', (string)MDB_SESSION_LIFETIME);
ini_set('session.cookie_lifetime', (string)MDB_SESSION_LIFETIME);
ini_set('session.use_strict_mode', '1');

if (session_status() === PHP_SESSION_NONE) {
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'lifetime' => MDB_SESSION_LIFETIME,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Sliding expiration: every valid request extends the login by 30 days.
    if (session_id() !== '') {
        setcookie(session_name(), session_id(), [
            'expires' => time() + MDB_SESSION_LIFETIME,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
