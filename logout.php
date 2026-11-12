<?php
// logout.php
// Central staff logout handler. Guest sessions use a separate cookie.

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

// Log the logout BEFORE clearing the session (actor info lives in it)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/log_action.php';
if (!empty($_SESSION['username'])) {
	log_event(marle_db_connect(), 'logout', 'user', (int)($_SESSION['user_id'] ?? 0), 'success', 'User logged out');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
	$params = session_get_cookie_params();
	setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
}

session_destroy();

header('Location: login.php?logout=success', true, 303);
exit();
