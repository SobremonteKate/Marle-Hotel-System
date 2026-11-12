<?php
// ============================================================
//  admin/process_cancel_booking.php
//  Cancel a held booking from Requests → Bookings.
//
//  POST (JSON out):
//    mode=preview → booking_id, csrf_token
//        What cancelling would cost, read-only. Same numbers the guest modal
//        quotes, plus the refund owed when the deposit exceeds the fee.
//    mode=cancel  → booking_id, cancel_reason, [cancel_reason_detail], csrf_token
//        Performs it.
//
//  Capability: cancel_bookings (manager + owner). The employee copy of this
//  file exists so the front desk gets a clear refusal instead of a 404 — the
//  same shape as process_deposit_action.php.
//
//  The forfeiture, the audit row and both emails come from
//  includes/booking_cancel.php, exactly as they do for the guest's own modal,
//  so the three front doors can never disagree about the money.
// ============================================================
header('Content-Type: application/json');
header('Cache-Control: no-store');

// A manager must never be told "could not reach the server" after the forfeiture
// has already been committed. An uncaught Error (a disabled cURL function, a
// table missing from an imported database, a retired function) used to answer
// with a bare 500 and an empty body, which this page can only render as
// "Could not reach the server. Please try again." — advice that is wrong twice
// over, because the booking may already be cancelled. Every uncaught throwable
// is now logged with its file and line and answered in the JSON shape the page
// already understands, so the reason appears on screen.
set_exception_handler(static function (Throwable $e): void {
    error_log('process_cancel_booking: ' . $e->getMessage() . ' — ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'The cancellation could not be completed: ' . $e->getMessage(),
    ]);
});

include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../admin/auth.php');
require_once __DIR__ . '/../includes/booking_cancel.php';
require_once __DIR__ . '/../includes/booking_deposit.php';   // marle_fetch_booking()
require_once __DIR__ . '/../includes/log_action.php';
require_once __DIR__ . '/../csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

if (!has_capability('cancel_bookings')) {
    log_event(
        $conn,
        'booking_cancel_denied',
        'booking',
        (int)($_POST['booking_id'] ?? 0),
        'denied',
        'missing cancel_bookings capability'
    );
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Only a manager (admin or owner) can cancel a booking. '
                   . 'Ask a manager, or deny the request instead if nothing has been paid.',
    ]);
    exit();
}

$csrfToken = (string)($_POST['csrf_token'] ?? '');
if (!hash_equals(csrf_token(), $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit();
}

$mode      = (string)($_POST['mode'] ?? 'preview');
$bookingId = (int)($_POST['booking_id'] ?? 0);

if (!in_array($mode, ['preview', 'cancel'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unknown request.']);
    exit();
}
if ($bookingId < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID.']);
    exit();
}

$booking = marle_fetch_booking($conn, $bookingId);
if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'Booking not found.']);
    exit();
}

$status    = (string)($booking['status'] ?? '');
$isOpen    = in_array($status, ['pending', 'approved'], true);
$canCancel = marle_booking_can_be_cancelled($booking, false);   // staff wording
$preview   = marle_cancellation_preview($booking);
$refund    = max(0, round($preview['deposit'] - $preview['fee'], 2));

$blockedReason = '';
if (!$isOpen) {
    $blockedReason = 'This booking is already ' . $status . ', so there is nothing to cancel.';
} elseif (!$canCancel['allowed']) {
    $blockedReason = $canCancel['reason'];
}

if ($mode === 'preview') {
    echo json_encode([
        'success'    => true,
        'booking'    => [
            'id'              => (int)$booking['id'],
            'guest_name'      => (string)$booking['guest_name'],
            'guest_email'     => (string)$booking['guest_email'],
            'guest_phone'     => (string)($booking['guest_phone'] ?? ''),
            'room_type'       => (string)$booking['room_type'],
            'room_number'     => (string)($booking['room_number'] ?? ''),
            'check_in'        => (string)$booking['check_in'],
            'check_out'       => (string)($booking['check_out'] ?? ''),
            'persons'         => (int)($booking['persons'] ?? 0),
            'booking_type'    => (string)($booking['booking_type'] ?? 'daily'),
            'hours_requested' => (int)($booking['hours_requested'] ?? 0),
            'status'          => $status,
            'deposit_status'  => (string)($booking['deposit_status'] ?? 'unpaid'),
        ],
        'can_cancel' => ($blockedReason === ''),
        'reason'     => $blockedReason,
        'preview'    => [
            'percent'        => (int)$preview['percent'],
            'policy'         => $preview['policy'],
            'notice_hours'   => (int)$preview['notice_hours'],
            'total'          => marle_peso($preview['total']),
            'fee'            => marle_peso($preview['fee']),
            'deposit'        => marle_peso($preview['deposit']),
            'balance'        => marle_peso($preview['balance']),
            // Deposit larger than the fee: the difference belongs to the guest.
            'refund'         => marle_peso($refund),
            // Raw numbers too, so the page never parses peso text.
            'total_amount'   => $preview['total'],
            'fee_amount'     => $preview['fee'],
            'deposit_amount' => $preview['deposit'],
            'balance_amount' => $preview['balance'],
            'refund_amount'  => $refund,
        ],
    ]);
    exit();
}

// ── mode=cancel ───────────────────────────────────────────────────────────
if ($blockedReason !== '') {
    echo json_encode(['success' => false, 'message' => $blockedReason]);
    exit();
}

$reasonChoice = trim((string)($_POST['cancel_reason'] ?? ''));
$detail       = trim((string)($_POST['cancel_reason_detail'] ?? ''));

// The guest may cancel without saying why; a staff cancellation is recorded
// against the front desk, so it does not go through unexplained.
if ($reasonChoice === '') {
    echo json_encode(['success' => false, 'message' => 'Give a reason for the cancellation.']);
    exit();
}

$username = (string)($_SESSION['username'] ?? 'unknown');
$role     = (string)(current_role() ?? 'staff');

$result = marle_cancel_booking(
    $conn,
    $booking,
    $reasonChoice,
    $detail,
    'staff:' . $username,               // cancelled_by  (who acted)
    'Staff (' . $role . ')',            // audit-trail wording, names the role
    'Cancelled by the front desk'       // guest-facing wording, no account name
);

if (!$result['ok']) {
    echo json_encode(['success' => false, 'message' => $result['error']]);
    exit();
}

// The helper's message is written for the guest (it is what the guest email
// says). The toast the front desk sees is built here instead.
$message = 'Booking #' . $bookingId . ' cancelled. '
    . marle_peso($result['fee']) . ' of the ' . marle_peso($result['total'])
    . ' total is retained under the ' . (int)MARLE_CANCELLATION_FORFEIT_RATE . '% policy';
if ($result['balance'] > 0) {
    $message .= ' — ' . marle_peso($result['balance']) . ' still to collect at the desk.';
} elseif ($refund > 0) {
    $message .= ' — ' . marle_peso($refund) . ' is refundable to the guest.';
} else {
    $message .= ' — covered in full by the deposit on record.';
}
$message .= $result['notified']
    ? ' The room is free again and the guest has been emailed.'
    : ' The room is free again, but the email could not be sent — open Email check to see why.';

echo json_encode([
    'success'    => true,
    'message'    => $message,
    'booking_id' => $bookingId,
    'fee'        => marle_peso($result['fee']),
    'total'      => marle_peso($result['total']),
    'balance'    => marle_peso($result['balance']),
    'refund'     => marle_peso($refund),
    'cancelled_by' => 'staff:' . $username,
]);
