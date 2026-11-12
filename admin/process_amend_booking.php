<?php
// ============================================================
//  admin/process_amend_booking.php
//  Amend a held booking from Requests → Bookings.
//
//  POST (JSON out):
//    mode=preview → booking_id, csrf_token
//        The booking as it stands, its current re-quote (deltas of 0) and the
//        rooms of its type that are free for its own dates.
//    mode=quote   → booking_id, [check_in, check_out, room_number, persons,
//                               check_in_time, hours_requested], csrf_token
//        The re-quote for the proposed values: new total, deposit difference,
//        new forfeiture, and whether the room is actually free.
//    mode=amend   → same as quote
//        Performs it — re-checks availability under an advisory lock, moves the
//        booking (and its calendar event), emails the guest.
//
//  Capability: amend_bookings (manager + owner). The employee copy exists so the
//  front desk gets a clear refusal instead of a 404 — the same shape as
//  process_cancel_booking.php and process_deposit_action.php.
//
//  All the money and availability logic lives in includes/booking_amend.php, so
//  the modal's re-quote and the write can never disagree.
// ============================================================
header('Content-Type: application/json');
header('Cache-Control: no-store');
include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../admin/auth.php');
require_once __DIR__ . '/../includes/booking_amend.php';
require_once __DIR__ . '/../includes/booking_deposit.php';   // marle_fetch_booking() + money helpers
require_once __DIR__ . '/../includes/log_action.php';
require_once __DIR__ . '/../csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

if (!has_capability('amend_bookings')) {
    log_event(
        $conn,
        'booking_amend_denied',
        'booking',
        (int)($_POST['booking_id'] ?? 0),
        'denied',
        'missing amend_bookings capability'
    );
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Only a manager (admin or owner) can change a booked stay. '
                   . 'Ask a manager, or deny the request and take a fresh booking instead.',
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

if (!in_array($mode, ['preview', 'quote', 'amend'], true)) {
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

$guard = marle_booking_amend_guard($booking);
$isOpen = in_array((string)($booking['status'] ?? ''), ['pending', 'approved'], true);

// ── Build the proposal the quote is computed from ──────────────────────────
if ($mode === 'preview') {
    $input = [
        'check_in'        => (string)($booking['check_in'] ?? ''),
        'check_out'       => (string)($booking['check_out'] ?? ''),
        'room_number'     => (string)($booking['room_number'] ?? ''),
        'persons'         => (int)($booking['persons'] ?? 1),
        'check_in_time'   => (string)($booking['check_in_time'] ?? ''),
        'hours_requested' => (int)($booking['hours_requested'] ?? 0),
    ];
} else {
    $input = [
        'check_in'        => (string)($_POST['check_in'] ?? ''),
        'check_out'       => (string)($_POST['check_out'] ?? ''),
        'room_number'     => (string)($_POST['room_number'] ?? ''),
        'persons'         => (int)($_POST['persons'] ?? 0),
        'check_in_time'   => (string)($_POST['check_in_time'] ?? ''),
        'hours_requested' => (int)($_POST['hours_requested'] ?? 0),
    ];
}

$proposal = marle_booking_proposal($conn, $booking, $input);
$quote    = marle_booking_amend_quote($conn, $booking, $proposal);

if ($mode === 'amend') {
    if (!$guard['allowed']) {
        echo json_encode(['success' => false, 'message' => $guard['reason']]);
        exit();
    }
    if (empty($proposal['ok'])) {
        echo json_encode([
            'success' => false,
            'message' => implode(' ', (array)$proposal['errors']) ?: 'That change is not valid.',
            'errors'  => (array)$proposal['errors'],
        ]);
        exit();
    }

    $role     = (string)(current_role() ?? 'staff');
    $username = (string)($_SESSION['username'] ?? 'unknown');

    $result = marle_booking_amend(
        $conn,
        $booking,
        $proposal,
        $quote,
        'Staff (' . $role . ')'          // audit-trail wording, names the role
    );

    if (!$result['ok']) {
        echo json_encode(['success' => false, 'message' => $result['error']]);
        exit();
    }

    echo json_encode([
        'success'    => true,
        'message'    => $result['message'],
        'booking_id' => $bookingId,
        'quote'      => $quote,
    ]);
    exit();
}

// ── preview / quote ────────────────────────────────────────────────────────
$form = [
    'booking_type'    => $proposal['booking_type'],
    'check_in'        => $proposal['check_in'],
    'check_out'       => $proposal['check_out'],
    'room_number'     => $proposal['room_number'],
    'persons'         => $proposal['persons'],
    'check_in_time'   => $proposal['check_in_time'] !== null ? substr((string)$proposal['check_in_time'], 0, 5) : '',
    'hours_requested' => $proposal['hours_requested'],
];

echo json_encode([
    'success'   => true,
    'booking'   => [
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
        'check_in_time'   => (string)($booking['check_in_time'] ?? ''),
        'hours_requested' => (int)($booking['hours_requested'] ?? 0),
        'status'          => (string)$booking['status'],
        'deposit_status'  => (string)($booking['deposit_status'] ?? 'unpaid'),
        'deposit_amount'  => $booking['deposit_amount'] !== null ? (float)$booking['deposit_amount'] : null,
    ],
    'can_amend' => $guard['allowed'],
    'reason'    => $guard['allowed'] ? '' : $guard['reason'],
    'form'      => $form,
    'quote'     => $quote,
    'rooms'     => $quote['rooms'],
]);
