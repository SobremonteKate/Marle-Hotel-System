<?php
// ============================================================
//  homepage/cancel_booking.php
//  Backend for the "Cancel a booking" modal on the public homepage.
//
//  Ownership is proved the same way the status page proves it: the guest's
//  booking email plus the 64-hex private status token issued with the booking.
//  Two modes:
//
//    mode=lookup → what would be cancelled and what it costs (read-only)
//    mode=cancel → performs it through includes/booking_cancel.php, the same
//                  code path the status page uses
//
//  POST only, JSON out. A booking id on its own is never enough, and a booking
//  that is already closed (cancelled/denied/completed) or whose check-in has
//  passed is refused rather than quietly written over.
// ============================================================
include('../dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json');
require_once __DIR__ . '/../includes/booking_deposit.php';
require_once __DIR__ . '/../includes/booking_cancel.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!function_exists('marle_cancel_reply')) {
    /** One exit point for the modal: JSON body + status code, then stop. */
    function marle_cancel_reply(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    marle_cancel_reply([
        'success' => false,
        'message' => 'Cancellations have to be submitted from the website.',
    ], 405);
}

$mode      = (string)($_POST['mode'] ?? 'lookup');
$email     = trim((string)($_POST['email'] ?? ''));
$token     = trim((string)($_POST['status_token'] ?? ''));
$bookingId = (int)($_POST['booking_id'] ?? 0);

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    marle_cancel_reply([
        'success' => false,
        'message' => 'Enter the email address you booked with and the status token from your confirmation email.',
    ]);
}

if (!in_array($mode, ['lookup', 'cancel'], true)) {
    marle_cancel_reply(['success' => false, 'message' => 'Unknown request.'], 400);
}

// ── Ownership: the token opens exactly one booking, never a list ───────────
$booking = marle_load_guest_booking($conn, $email, $token, $bookingId);

if (!$booking) {
    marle_cancel_reply([
        'success' => false,
        'message' => 'We could not find a booking for that email and status token. '
                   . 'Check the token in your confirmation email — it is 64 characters long.',
    ]);
}

$status    = (string)($booking['status'] ?? '');
$isOpen    = in_array($status, ['pending', 'approved'], true);
$canCancel = marle_booking_can_be_cancelled($booking);
$preview   = marle_cancellation_preview($booking);

$blockedReason = '';
if (!$isOpen) {
    $blockedReason = 'This booking is already ' . $status . ', so there is nothing to cancel here. '
                   . 'Call 0917 000 0000 if you need anything changed.';
} elseif (!$canCancel['allowed']) {
    $blockedReason = $canCancel['reason'];
}

if ($mode === 'lookup') {
    marle_cancel_reply([
        'success' => true,
        'booking' => [
            'id'             => (int)$booking['id'],
            'guest_name'     => (string)$booking['guest_name'],
            'room_type'      => (string)$booking['room_type'],
            'room_number'    => (string)($booking['room_number'] ?? ''),
            'check_in'       => (string)$booking['check_in'],
            'check_out'      => (string)($booking['check_out'] ?? ''),
            'persons'        => (int)($booking['persons'] ?? 0),
            'booking_type'   => (string)($booking['booking_type'] ?? 'daily'),
            'hours_requested'=> (int)($booking['hours_requested'] ?? 0),
            'status'         => $status,
        ],
        'can_cancel' => ($blockedReason === ''),
        'reason'     => $blockedReason,
        'preview'    => [
            'percent' => (int)$preview['percent'],
            'total'   => marle_peso($preview['total']),
            'fee'     => marle_peso($preview['fee']),
            'deposit' => marle_peso($preview['deposit']),
            'balance' => marle_peso($preview['balance']),
            // Raw numbers too, so the page can decide without parsing peso text.
            'total_amount'   => $preview['total'],
            'fee_amount'     => $preview['fee'],
            'balance_amount' => $preview['balance'],
            'policy'  => $preview['policy'],
        ],
    ]);
}

// ── mode=cancel ───────────────────────────────────────────────────────────
if ($blockedReason !== '') {
    marle_cancel_reply(['success' => false, 'message' => $blockedReason]);
}

$result = marle_cancel_booking(
    $conn,
    $booking,
    (string)($_POST['cancel_reason'] ?? 'not specified'),
    (string)($_POST['cancel_reason_detail'] ?? ''),
    'guest'
);

if (!$result['ok']) {
    marle_cancel_reply(['success' => false, 'message' => $result['error']]);
}

marle_cancel_reply([
    'success'    => true,
    'message'    => $result['message'],
    'booking_id' => (int)$booking['id'],
    'fee'        => marle_peso($result['fee']),
    'total'      => marle_peso($result['total']),
    'balance'    => marle_peso($result['balance']),
]);
