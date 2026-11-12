<?php
// Guest portal session bootstrap. The separate cookie prevents staff logout
// from destroying the active guest session in the same browser profile.
if (session_status() === PHP_SESSION_NONE) {
    session_name('MARLE_GUEST_SESSION');
    // 'secure' adapts to HTTPS — including reverse-proxied TLS such as
    // ngrok, where the real scheme arrives in X-Forwarded-Proto.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
