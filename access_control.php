<?php

if (session_status() === PHP_SESSION_NONE) {
    // Harden the staff session cookie: JS-inaccessible, CSRF-mitigating.
    // 'secure' adapts automatically once the site is served over HTTPS —
    // including reverse-proxied TLS such as ngrok, where Apache itself
    // only sees plain HTTP and the real scheme arrives in X-Forwarded-Proto.
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

// Seed the per-session CSRF token so every guarded page/endpoint can rely on it.
// Rendering pages print it into forms; mutating AJAX endpoints verify it.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

const ROLE_CAPABILITIES = [
    'superadmin' => [
        'view_operations',
        'approve_requests',
        'collect_payments',
        'view_transactions',
        'view_audit_log',
        'manage_users',
        'manage_catalog',
        'generate_reports',
        'request_financial_adjustment',
        'approve_financial_adjustment',
        // Reservation deposits are money: only a manager verifies the guest's
        // proof of payment before a booking can be approved.
        'verify_deposits',
        // Cancelling a held booking forfeits money under the 50% policy, so it
        // is a manager/owner action (Requests → Bookings → Cancel booking). The
        // front desk can still deny an unpaid request — that costs the guest
        // nothing — but cannot cancel one that carries a deposit.
        'cancel_bookings',
        // Re-dating, re-rooming or re-quoting a held booking moves the total and
        // therefore the deposit and the forfeiture, so amending is a manager/
        // owner action too (Requests → Bookings → Amend booking).
        'amend_bookings',
        // Owner-only: a manager must not be able to export the whole database
        // (it holds password hashes and the audit trail). The manager's
        // Backup Database shortcut was removed 2026-09-24.
        'backup_database',
    ],
    'admin' => [
        'view_operations',
        'approve_requests',
        'collect_payments',
        'view_transactions',
        // NOTE: the System Log (view_audit_log) is owner-only. Managers keep
        // their actions logged, they just cannot read the audit trail.
        'manage_users',
        'manage_catalog',
        'generate_reports',
        'request_financial_adjustment',
        'verify_deposits',
        'cancel_bookings',
        'amend_bookings',
    ],
    'employee' => [
        'view_operations',
        'approve_requests',
        'collect_payments',
        'view_transactions',
    ],
];

const SESSION_IDLE_TIMEOUT = 1800;
const SESSION_ABSOLUTE_TIMEOUT = 28800;

function enforce_session_lifetime(): void
{
    $now = time();
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    $loggedInAt = (int)($_SESSION['logged_in_at'] ?? 0);

    if (($lastActivity > 0 && $now - $lastActivity > SESSION_IDLE_TIMEOUT)
        || ($loggedInAt > 0 && $now - $loggedInAt > SESSION_ABSOLUTE_TIMEOUT)) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        http_response_code(401);
        header('Location: ../login.php?expired=1');
        exit();
    }

    $_SESSION['last_activity'] = $now;
}

function current_role(): ?string
{
    $role = $_SESSION['usertype'] ?? null;
    return is_string($role) ? $role : null;
}

function has_capability(string $capability): bool
{
    $role = current_role();
    return $role !== null
        && in_array($capability, ROLE_CAPABILITIES[$role] ?? [], true);
}

function require_capability(string $capability, string $failureLocation = '../404.php'): void
{
    if (!has_capability($capability)) {
        http_response_code(403);
        header('Location: ' . $failureLocation);
        exit();
    }
}

/**
 * Force-logout helper for deactivated accounts. Mirrors enforce_session_lifetime()
 * teardown: destroy the session, clear the cookie, bounce to login with a message.
 */
function force_logout_deactivated(): void
{
    // Leave a short-lived marker so the *next* click can explain why the session
    // ended. Without it a kicked user just falls through to the generic 404 page
    // (their old session may already have been killed by a background poll).
    if (!headers_sent()) {
        setcookie('marle_deactivated', '1', time() + 120, '/');
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    http_response_code(401);
    header('Location: ../login.php?deactivated=1');
    exit();
}

function require_role(string $role, string $failureLocation = '../404.php'): void
{
    enforce_session_lifetime();

    if (current_role() !== $role) {
        // A session killed by deactivation explains itself instead of showing 404.
        if (!empty($_COOKIE['marle_deactivated'])) {
            if (!headers_sent()) {
                setcookie('marle_deactivated', '', time() - 3600, '/');
            }
            http_response_code(401);
            header('Location: ../login.php?deactivated=1');
            exit();
        }

        http_response_code(403);
        header('Location: ' . $failureLocation);
        exit();
    }

    // ── Instant-logout enforcement for deactivated accounts ─────────────────
    // A fired employee must lose access on their very next click, so the
    // account status is re-verified against the database on every request.
    require_once __DIR__ . '/config.php';

    $GLOBALS['__marle_status_conn'] = $GLOBALS['__marle_status_conn']
        ?? (function_exists('mysqli_connect') ? @marle_db_connect() : false);

    $statusConn = $GLOBALS['__marle_status_conn'];
    if ($statusConn instanceof mysqli) {
        $statusStmt = @mysqli_prepare($statusConn, "SELECT status FROM userstbl WHERE id = ? LIMIT 1");
        if ($statusStmt) {
            $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
            @mysqli_stmt_bind_param($statusStmt, 'i', $sessionUserId);
            @mysqli_stmt_execute($statusStmt);
            $statusRes = @mysqli_stmt_get_result($statusStmt);
            $statusRow = $statusRes ? @mysqli_fetch_assoc($statusRes) : null;
            @mysqli_stmt_close($statusStmt);

            if ($statusRow === null || ($statusRow['status'] ?? 'active') !== 'active') {
                force_logout_deactivated();
            }
        }
    }
}
