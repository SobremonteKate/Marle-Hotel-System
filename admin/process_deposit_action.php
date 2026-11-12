<?php
// ============================================================
//  admin/process_deposit_action.php
//  Manager decision on a guest's reservation deposit.
//
//  POST: booking_id, deposit_action (verify|reject), [reason], csrf_token
//  Capability: verify_deposits (admin + superadmin only — the front desk
//  cannot release a booking without the manager checking the payment).
// ============================================================
header('Content-Type: application/json');
include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../admin/auth_admin.php');
require_once __DIR__ . '/../includes/log_action.php';
require_once __DIR__ . '/../includes/booking_deposit.php';
require_once __DIR__ . '/../includes/booking_notifications.php';
require_once __DIR__ . '/../csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

if (!has_capability('verify_deposits')) {
    log_event($conn, 'deposit_verify_denied', 'booking', (int)($_POST['booking_id'] ?? 0), 'denied', 'missing verify_deposits capability');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only a manager (admin or owner) can verify reservation deposits.']);
    exit();
}

$csrfToken = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals(csrf_token(), $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit();
}

$bookingId = (int)($_POST['booking_id'] ?? 0);
$depositAction = (string)($_POST['deposit_action'] ?? '');
$reason = trim((string)($_POST['reason'] ?? ''));

if ($bookingId < 1 || !in_array($depositAction, ['verify', 'reject'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid deposit action.']);
    exit();
}

$booking = marle_fetch_booking($conn, $bookingId);
if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'Booking not found.']);
    exit();
}

$approve = ($depositAction === 'verify');
$username = (string)($_SESSION['username'] ?? '');

$conn->begin_transaction();
try {
    // Stamp the expected deposit amount the first time a decision is made, so
    // the retained-forfeiture maths later never depends on today's rate.
    if ($booking['deposit_amount'] === null) {
        $total = marle_booking_total($booking);
        $amount = marle_deposit_amount($conn, $total);
        $rate = marle_deposit_rate($conn);
        $stamp = $conn->prepare('UPDATE bookings SET deposit_amount = ?, deposit_rate = ? WHERE id = ?');
        $stamp->bind_param('ddi', $amount, $rate, $bookingId);
        $stamp->execute();
        $stamp->close();
    }

    $result = marle_deposit_verify($conn, $bookingId, $username, $approve, $reason);
    if (!$result['ok']) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $result['error']]);
        exit();
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('process_deposit_action: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not save the deposit decision. Please try again.']);
    exit();
}

log_event(
    $conn,
    $approve ? 'deposit_verified' : 'deposit_rejected',
    'booking',
    $bookingId,
    'success',
    'Room ' . (string)($booking['room_number'] ?? '?') . ' — ' . (string)$booking['guest_name']
        . ($approve ? ' · deposit verified' : ' · rejected: ' . $reason)
);

// Tell the guest either way (failures only log).
marle_notify_deposit_verified($conn, $bookingId, $approve);

echo json_encode([
    'success' => true,
    'message' => $approve
        ? 'Deposit verified. The booking can now be approved and the guest has been notified.'
        : 'Proof rejected. The guest has been asked to upload a clear copy.',
]);
