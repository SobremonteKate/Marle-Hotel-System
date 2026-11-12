<?php
// TODO: Admin role should only archive, not update/delete.
// TODO: Admin role should not be allowed to edit here.
// ============================================================
//  admin/process_service_action.php
//  Admin-only AJAX endpoint for service request management.
//  Called by requests.php Services tab.
// ============================================================
include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../admin/auth.php');
require_once __DIR__ . '/../includes/log_action.php';
require_once __DIR__ . '/../includes/server_clock.php';   // marle_db_now(): the log shares the database's clock

// Ensure endpoint always returns clean JSON (avoid accidental HTML output)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_error.log');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
if (!ob_get_level()) ob_start();

// Simple file logger for debugging (appends to admin/debug_process_service_action.log)
function debug_log($msg)
{
    $fn = __DIR__ . '/debug_process_service_action.log';
    // The database's clock, not PHP's (includes/server_clock.php). These lines are read
    // next to rows this endpoint just wrote, and on this host PHP runs six hours behind
    // the database — so a date('c') prefix dated the cause apart from its effect and sent
    // every later reader of the log looking in the wrong place. marle_db_now() is cached
    // per request, so this costs one query no matter how many lines a request logs.
    $line = '[' . marle_db_now() . '] ' . (is_string($msg) ? $msg : json_encode($msg)) . "\n";
    @file_put_contents($fn, $line, FILE_APPEND | LOCK_EX);
}

// Convert PHP warnings/notices to exceptions so they are handled centrally
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Capture fatal shutdown errors and log them for diagnosis
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        debug_log('Shutdown error: ' . json_encode($err));
    }
});

/**
 * Send JSON response and terminate.
 * Clears any prior output to avoid corrupting JSON.
 */
function return_json(array $data, int $status = 200): void
{
    if (ob_get_length() !== false) ob_clean();
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

set_exception_handler(function ($e) {
    // Log exception details for debugging, but return a safe message to client
    try {
        debug_log('Uncaught exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        if ($e->getTraceAsString()) debug_log('Trace: ' . $e->getTraceAsString());
    } catch (Throwable $ignore) {
        // ignore logging failures
    }
    return_json(['success' => false, 'message' => 'Server error. Please try again.'], 500);
});

header('Content-Type: application/json');

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    return_json(['success' => false, 'message' => 'Invalid request.'], 403);
}

$action    = $_POST['action']     ?? '';
$requestId = (int)($_POST['request_id'] ?? 0);
$reason    = trim($_POST['reason'] ?? '');
$adminUser = $_SESSION['username'] ?? 'admin';

if (!$requestId) {
    return_json(['success' => false, 'message' => 'Invalid request ID.'], 400);
}

// ── Helper: fetch service + request row ───────────────────────
function fetchServiceRow(mysqli $conn, int $requestId): ?array
{
    $stmt = $conn->prepare(
        "SELECT r.id AS request_id, r.customer_id, r.room_number, r.status,
                sr.id AS svc_id, sr.service_name, sr.total_amount,
                sr.payment_status
         FROM requests r
         JOIN service_requests sr ON sr.request_id = r.id
         WHERE r.id = ? AND r.type = 'service'
         LIMIT 1"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ── Helper: get room type ─────────────────────────────────────
function getRoomType(mysqli $conn, string $roomNumber): ?string
{
    $stmt = $conn->prepare("SELECT rooms FROM roomstbl WHERE room_number = ? LIMIT 1");
    $stmt->bind_param('s', $roomNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['rooms'] ?? null;
}

// ── Helper: add request_reply ─────────────────────────────────
function addReply(mysqli $conn, int $requestId, string $message): void
{
    $stmt = $conn->prepare(
        "INSERT INTO request_replies (request_id, sender, message) VALUES (?, 'admin', ?)"
    );
    $stmt->bind_param('is', $requestId, $message);
    $stmt->execute();
    $stmt->close();
}

switch ($action) {

    // ── APPROVE ───────────────────────────────────────────────
    case 'approve':
        $svc = fetchServiceRow($conn, $requestId);

        if (!$svc) {
            return_json(['success' => false, 'message' => 'Service request not found.'], 404);
        }
        if ($svc['status'] !== 'pending') {
            return_json(['success' => false, 'message' => 'This request has already been processed.'], 409);
        }

        $conn->begin_transaction();
        try {
            // Update requests status
            $stmt = $conn->prepare(
                "UPDATE requests SET status='approved', updated_at=NOW()
                 WHERE id=? AND type='service'"
            );
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $stmt->close();

            // Admin reply
            addReply($conn, $requestId,
                'Your service request for "' . $svc['service_name'] . '" has been approved. ' .
                'It will be processed shortly. Payment of ₱' .
                number_format((float)$svc['total_amount'], 2) .
                ' is due at the front desk.'
            );

            // Log to transaction_history
            $roomType = getRoomType($conn, $svc['room_number']);
            $thStmt = $conn->prepare(
                "INSERT INTO transaction_history
                     (transaction_type, customer_id, room_number, room_type,
                      amount, status, reference_id, notes)
                 VALUES ('service', ?, ?, ?, ?, 'unpaid', ?, ?)"
            );
            $notes = 'Service: ' . $svc['service_name'];
            $thStmt->bind_param(
                'issdis',
                $svc['customer_id'], $svc['room_number'], $roomType,
                $svc['total_amount'], $svc['svc_id'], $notes
            );
            $thStmt->execute();
            $thStmt->close();

            $conn->commit();
            log_event($conn, 'service_request_approved', 'service_request', $requestId, 'success');
            return_json(['success' => true, 'message' => 'Service request approved.'], 200);

        } catch (Exception $e) {
            $conn->rollback();
            return_json(['success' => false, 'message' => 'Database error. Please try again.'], 500);
        }
        break;

    // ── DENY ─────────────────────────────────────────────────
    case 'deny':
        if (empty($reason)) {
            return_json(['success' => false, 'message' => 'A denial reason is required.'], 400);
        }

        $svc = fetchServiceRow($conn, $requestId);
        if (!$svc) {
            return_json(['success' => false, 'message' => 'Service request not found.'], 404);
        }
        if ($svc['status'] !== 'pending') {
            return_json(['success' => false, 'message' => 'This request has already been processed.'], 409);
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "UPDATE requests
                 SET status='denied', denial_reason=?, updated_at=NOW()
                 WHERE id=? AND type='service'"
            );
            $stmt->bind_param('si', $reason, $requestId);
            $stmt->execute();
            $stmt->close();

            $replyMsg = "We're sorry, your service request for \"" . $svc['service_name'] .
                        "\" was not approved. Reason: " . $reason;
            addReply($conn, $requestId, $replyMsg);

            // Log denial to transaction_history (amount = 0, status = denied)
            $roomType = getRoomType($conn, $svc['room_number']);
            $thStmt = $conn->prepare(
                "INSERT INTO transaction_history
                     (transaction_type, customer_id, room_number, room_type,
                      amount, status, denial_reason, reference_id, notes)
                 VALUES ('service', ?, ?, ?, 0.00, 'denied', ?, ?, ?)"
            );
            $notes = 'Service denied: ' . $svc['service_name'];
            $thStmt->bind_param(
                'isssis',
                $svc['customer_id'], $svc['room_number'], $roomType,
                $reason, $svc['svc_id'], $notes
            );
            $thStmt->execute();
            $thStmt->close();

            $conn->commit();
            log_event($conn, 'service_request_denied', 'service_request', $requestId, 'success', $reason ?? null);
            return_json(['success' => true, 'message' => 'Service request denied.'], 200);

        } catch (Exception $e) {
            $conn->rollback();
            return_json(['success' => false, 'message' => 'Database error. Please try again.'], 500);
        }
        break;

    // ── MARK PAID ────────────────────────────────────────────
    case 'mark_paid':
        $svc = fetchServiceRow($conn, $requestId);
        if (!$svc) {
            return_json(['success' => false, 'message' => 'Service request not found.'], 404);
        }

        $conn->begin_transaction();
        try {
            // Update service_requests payment
            $stmt = $conn->prepare(
                "UPDATE service_requests SET payment_status='paid' WHERE request_id=?"
            );
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $stmt->close();

            // Update transaction_history status
            $thStmt = $conn->prepare(
                "UPDATE transaction_history
                 SET status = 'paid'
                 WHERE transaction_type = 'service'
                   AND reference_id = ?
                   AND status = 'unpaid'"
            );
            $thStmt->bind_param('i', $svc['svc_id']);
            $thStmt->execute();
            $thStmt->close();

            $conn->commit();
            log_event($conn, 'service_payment_marked_paid', 'service_request', $requestId, 'success', 'Marked paid at front desk');
            return_json(['success' => true, 'message' => 'Service marked as paid at front desk.'], 200);

        } catch (Exception $e) {
            $conn->rollback();
            return_json(['success' => false, 'message' => 'Database error. Please try again.'], 500);
        }
        break;

    default:
        return_json(['success' => false, 'message' => 'Unknown action.'], 400);
}
