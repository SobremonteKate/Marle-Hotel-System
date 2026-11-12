<?php
// ============================================================
//  admin/process_walkin_booking.php
//  Save a guest who walked in and paid the deposit in cash.
//
//  POST: guest_name, guest_phone, [guest_email], room_type, room_number,
//        persons, booking_type, check_in, check_out, [check_in_time],
//        [check_out_time], [special_requests], csrf_token
//
//  Capability: verify_deposits — the clerk is recording money as received, the
//  same permission that lets a manager verify any other reservation deposit.
//  The booking is written as pending with the cash deposit already verified; a
//  manager still approves it, which is what puts it on the calendar.
//
//  The guest (when an address was given) and the hotel inbox are emailed from
//  marle_notify_walkin_created(); email_status in the response reports what
//  happened, so the desk never has to guess whether the guest got anything.
// ============================================================
header('Content-Type: application/json');

// The guest email and the hotel alert are both sent before this answers (each
// up to 15s when the provider is unreachable). Without headroom the request
// would be cut off mid-flight on a host with a 30s limit, and the desk would be
// told "nothing was saved" for a booking that IS saved. Ignored where the host
// forbids it — the response is still correct, just slower.
@set_time_limit(90);

include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');

include('../admin/auth_admin.php');
require_once __DIR__ . '/../includes/log_action.php';
require_once __DIR__ . '/../includes/walkin_booking.php';
include('../csrf.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

if (!has_capability('verify_deposits')) {
    log_event($conn, 'walkin_booking_denied', 'booking', 0, 'denied', 'missing verify_deposits capability');
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Only a manager (admin or owner) can record a walk-in with cash at the desk.',
    ]);
    exit();
}

if (!hash_equals(csrf_token(), (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit();
}

$result = marle_walkin_create($conn, $_POST, (string)($_SESSION['username'] ?? ''));

echo json_encode([
    'success'     => $result['ok'],
    'message'     => $result['ok'] ? $result['message'] : $result['error'],
    'booking_id'  => $result['id'],
    'email_status' => $result['email_status'] ?? 'none',
    'guest_email'  => $result['guest_email'] ?? '',
    'receipt_status' => $result['receipt_status'] ?? 'none',
    'requests_url' => '../admin/requests.php',
]);
