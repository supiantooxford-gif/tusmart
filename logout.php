<?php
/**
 * Keluar dari aplikasi.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/init.php';

if (is_login()) {
    log_catat('keluar', '');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
}
session_destroy();
header('Location: login.php');
exit;
