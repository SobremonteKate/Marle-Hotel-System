<?php
// admin/check_unpaid_items.php
// Returns count of unpaid items for a room (extensions + services + food)

session_start();
include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../admin/auth_admin.php');

header('Content-Type: application/json');

$roomNumber = trim($_GET['room_number'] ?? '');

if ($roomNumber === '') {
    echo json_encode(['success' => false, 'unpaid_count' => 0]);
    exit();
}

$unpaidCount = 0;

// ── Count unpaid extensions ────────────────────────────────
$extStmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt
     FROM extension_history eh
     JOIN extension_requests er ON er.id = eh.extension_req_id
     JOIN requests r ON r.id = er.request_id
     WHERE r.room_number = ?
       AND r.status = 'approved'
       AND r.is_archived = 0
       AND eh.payment_status = 'unpaid'"
);
$extStmt->bind_param('s', $roomNumber);
$extStmt->execute();
$extResult = $extStmt->get_result()->fetch_assoc();
$unpaidCount += $extResult['cnt'] ?? 0;
$extStmt->close();

// ── Count unpaid services ──────────────────────────────────
$svcStmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt
     FROM service_requests sr
     JOIN requests r ON r.id = sr.request_id
     WHERE r.room_number = ?
       AND r.status = 'approved'
       AND r.is_archived = 0
       AND sr.payment_status = 'unpaid'"
);
$svcStmt->bind_param('s', $roomNumber);
$svcStmt->execute();
$svcResult = $svcStmt->get_result()->fetch_assoc();
$unpaidCount += $svcResult['cnt'] ?? 0;
$svcStmt->close();

// ── Count unpaid food orders ───────────────────────────────
$foodStmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt
     FROM food_order_items foi
     JOIN requests r ON r.id = foi.request_id
     WHERE r.room_number = ?
       AND r.status = 'approved'
       AND r.is_archived = 0
       AND foi.payment_status = 'unpaid'"
);
$foodStmt->bind_param('s', $roomNumber);
$foodStmt->execute();
$foodResult = $foodStmt->get_result()->fetch_assoc();
$unpaidCount += $foodResult['cnt'] ?? 0;
$foodStmt->close();

echo json_encode(['success' => true, 'unpaid_count' => $unpaidCount]);
