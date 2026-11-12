<?php
// admin/refresh_room_requests.php
// Refreshes request data for a specific room after payment/status changes
// Returns HTML fragments for each tab to update in real-time

session_start();
include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../superadmin/auth.php');

header('Content-Type: application/json');

$roomNumber = trim($_GET['room_number'] ?? '');

if ($roomNumber === '') {
    echo json_encode(['success' => false]);
    exit();
}

// ── Extensions ────────────────────────────────────────────
$extResult = $conn->query(
    "SELECT r.id, r.customer_id, r.room_number, r.message,
            r.status, r.denial_reason,
            r.created_at,
            er.id           AS ext_id,
            er.hours_requested,
            er.room_type,
            er.rate_per_hour,
            er.total_amount,
            er.payment_status,
            er.special_request,
            eh.id           AS history_id,
            eh.payment_status AS hist_payment_status
     FROM requests r
     JOIN extension_requests er ON er.request_id = r.id
     LEFT JOIN extension_history eh ON eh.extension_req_id = er.id
     WHERE r.room_number = '$roomNumber'
       AND r.type = 'extend'
       AND r.is_archived = 0
     ORDER BY
         r.created_at DESC,
         r.id DESC"
);
$extAll = [];
if ($extResult) {
    while ($row = $extResult->fetch_assoc()) {
        $extAll[] = $row;
    }
}

// ── Services ───────────────────────────────────────────────
$svcResult = $conn->query(
    "SELECT r.id, r.customer_id, r.room_number, r.message,
            r.status, r.denial_reason, r.created_at,
            sr.id           AS svc_id,
            sr.service_name,
            sr.service_category,
            sr.quantity,
            sr.unit_price,
            sr.total_amount,
            sr.payment_status
     FROM requests r
     JOIN service_requests sr ON sr.request_id = r.id
     WHERE r.room_number = '$roomNumber'
       AND r.type = 'service'
       AND r.is_archived = 0
     ORDER BY
         r.created_at DESC,
         r.id DESC"
);
$svcAll = [];
if ($svcResult) {
    while ($row = $svcResult->fetch_assoc()) {
        $svcAll[] = $row;
    }
}

// ── Food Orders ────────────────────────────────────────────
$foodResult = $conn->query(
    "SELECT r.id, r.customer_id, r.room_number, r.message,
            r.status, r.denial_reason, r.created_at,
            SUM(foi.total_amount)               AS total_amount,
            MAX(foi.payment_status)             AS payment_status,
            GROUP_CONCAT(
                foi.item_name, ' x', foi.quantity
                ORDER BY foi.id SEPARATOR ' | '
            )                                   AS item_summary,
            GROUP_CONCAT(
                DISTINCT NULLIF(foi.special_request, '')
                ORDER BY foi.id SEPARATOR ' / '
            )                                   AS special_requests
     FROM requests r
     JOIN food_order_items foi ON foi.request_id = r.id
     WHERE r.room_number = '$roomNumber'
       AND r.type = 'food'
       AND r.is_archived = 0
     GROUP BY r.id, r.customer_id, r.room_number, r.message,
              r.status, r.denial_reason, r.created_at
     ORDER BY
         r.created_at DESC,
         r.id DESC"
);
$foodAll = [];
if ($foodResult) {
    while ($row = $foodResult->fetch_assoc()) {
        $foodAll[] = $row;
    }
}

echo json_encode([
    'success' => true,
    'extensions' => $extAll,
    'services' => $svcAll,
    'food_orders' => $foodAll
]);
