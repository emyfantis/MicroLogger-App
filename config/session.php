<?php
// Session Configuration and Management

// Proxy-aware HTTPS (also works behind reverse proxies)
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

if (session_status() === PHP_SESSION_NONE) {
    session_name('MICAPPSESSID'); // Custom session name 
    // MUST be called before session_start
    if (PHP_VERSION_ID >= 70300) {
        // PHP 7.3+ (accepts options as array)
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',        // set your domain if needed (e.g. .example.com)
            'secure'   => $is_https, // only send cookie over HTTPS
            'httponly' => true,
            // 'samesite' => 'Lax',   // (optional) enable SameSite if required
        ]);
    } else {
        // PHP ≤ 7.2 (only positional args)
        session_set_cookie_params(
            0,        // lifetime
            '/',      // path
            '',       // domain (or your own)
            $is_https,
            true
        );
        // For SameSite on 7.2 you must use ini_set or manual setcookie after session_start.
        // ini_set('session.cookie_samesite', 'Lax');
    }

    session_start([
        'use_strict_mode' => true,
        'gc_maxlifetime'  => 1800,
    ]);

    if (empty($_SESSION['login_at']))   $_SESSION['login_at']   = date('Y-m-d H:i:s');
    if (empty($_SESSION['started_at'])) $_SESSION['started_at'] = time();
}

// Idle timeout (sliding window)
$timeout = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $timeout)) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    // If the app is not in the web root, prefer a relative URL here
    header('Location: /index.php?expired=1');
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();


// Generate CSRF token for the session
require_once __DIR__ . '/csrf.php';
CSRF::generateToken();
