<?php
// ============================================================
//  guest/process_extend.php  —  Guest: Submit Extension Request
//  Called via AJAX POST from extend.php
// ============================================================
require_once __DIR__ . '/guest_session.php';
require_once __DIR__ . '/../includes/log_action.php';
header('Content-Type: application/json');

// ── Auth guard ────────────────────────────────────────────
if (empty($_SESSION['guest_token']) || empty($_SESSION['customer_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
    exit();
}

include('../dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json');

$customerId  = (int)$_SESSION['customer_id'];
$roomNumber  = $_SESSION['room_number'] ?? '';

$stayStmt = $conn->prepare(
        "SELECT gt.id FROM guest_tokens gt
         INNER JOIN customerstbl c ON c.id = gt.customer_id
         WHERE gt.token = ? AND gt.expires_at > NOW()
             AND c.id = ? AND c.room_no = ? AND c.status = 'checked-in'
         LIMIT 1"
);
$stayStmt->bind_param('sis', $_SESSION['guest_token'], $customerId, $roomNumber);
$stayStmt->execute();
$stayStmt->store_result();
if ($stayStmt->num_rows === 0) {
        $stayStmt->close();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Your guest stay is no longer active.']);
        exit();
}
$stayStmt->close();

$hoursReq    = (int)($_POST['hours'] ?? 0);
$specialReq  = trim($_POST['special_request'] ?? '');

// ── Validate hours ────────────────────────────────────────
if ($hoursReq < 1 || $hoursReq > 24) {
    echo json_encode(['success' => false, 'message' => 'Please select between 1 and 24 hours.']);
    exit();
}

if (empty($roomNumber)) {
    echo json_encode(['success' => false, 'message' => 'Room number could not be determined.']);
    exit();
}

// ── Lookup room type from roomstbl ────────────────────────
$rtStmt = $conn->prepare(
    "SELECT rooms FROM roomstbl WHERE room_number = ? LIMIT 1"
);
$rtStmt->bind_param('s', $roomNumber);
$rtStmt->execute();
$rtRow = $rtStmt->get_result()->fetch_assoc();
$rtStmt->close();

$roomType = $rtRow['rooms'] ?? 'With Garage'; // safe fallback

// ── Rate map ──────────────────────────────────────────────
$rateMap = [
    'Family'        => 200,
    'Suite'         => 200,
    'With Garage'   => 150,
    'Without Garage'=> 150,
];
$ratePerHour = $rateMap[$roomType] ?? 150;
$totalAmount = $hoursReq * $ratePerHour;

// ── Block duplicate pending extension ─────────────────────
$dupCheck = $conn->prepare(
    "SELECT r.id FROM requests r
     JOIN extension_requests er ON er.request_id = r.id
     WHERE r.customer_id = ?
       AND r.type = 'extend'
       AND r.status = 'pending'
     LIMIT 1"
);
$dupCheck->bind_param('i', $customerId);
$dupCheck->execute();
$dupCheck->store_result();
$hasPending = ($dupCheck->num_rows > 0);
$dupCheck->close();

if ($hasPending) {
    echo json_encode([
        'success' => false,
        'message' => 'You already have a pending extension request. Please wait for the front desk to respond.'
    ]);
    exit();
}

// ── Insert inside transaction ─────────────────────────────
$conn->begin_transaction();

try {
    // 1. Insert into requests
    $msg   = "Extend Stay Request - {$hoursReq} Hour" . ($hoursReq > 1 ? 's' : '');
    $stmt1 = $conn->prepare(
        "INSERT INTO requests (customer_id, room_number, type, message, status)
         VALUES (?, ?, 'extend', ?, 'pending')"
    );
    $stmt1->bind_param('iss', $customerId, $roomNumber, $msg);
    $stmt1->execute();
    $requestId = (int)$conn->insert_id;
    $stmt1->close();

    // 2. Insert into extension_requests
    $stmt2 = $conn->prepare(
        "INSERT INTO extension_requests
             (request_id, customer_id, room_number, room_type,
              hours_requested, rate_per_hour, total_amount,
              payment_method, payment_status, special_request)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'front_desk', 'unpaid', ?)"
    );
    $stmt2->bind_param(
        'iiissids',
        $requestId, $customerId, $roomNumber, $roomType,
        $hoursReq, $ratePerHour, $totalAmount, $specialReq
    );
    $stmt2->execute();
    $stmt2->close();

    $conn->commit();

    // ── Notify staff of extension request (non-critical) ─────────────────────
    try {
        $notifMsg = "Room {$roomNumber}: extension request — {$hoursReq} hour" . ($hoursReq > 1 ? 's' : '');
        $nStmt    = $conn->prepare(
            "INSERT INTO notifications (type, reference_id, room_number, message)
             VALUES ('extend', ?, ?, ?)"
        );
        $nStmt->bind_param('iss', $requestId, $roomNumber, $notifMsg);
        $nStmt->execute();
        $nStmt->close();
    } catch (Exception $e) { /* non-critical — main request already committed */ }

    log_event($conn, 'portal_extension_request', 'extension_request', $requestId ?? null, 'success', 'Room ' . $roomNumber . ' — ' . $hoursReq . 'h');
    echo json_encode([
        'success'      => true,
        'message'      => 'Your extension request has been sent! The front desk will confirm shortly.',
        'total_amount' => $totalAmount,
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
}