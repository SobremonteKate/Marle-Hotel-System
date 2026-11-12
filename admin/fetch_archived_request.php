<?php
// ============================================================
//  admin/fetch_archived_requests.php
//  Returns paginated archived requests for the Archive tab.
//
//  GET params:
//    type     — all | extend | service | food  (default: all)
//    room     — filter by room_number (optional)
//    page     — int >= 1                       (default: 1)
//    per_page — int                            (default: 20)
// ============================================================
session_start();
header('Content-Type: application/json');

include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../admin/auth_admin.php');

$type    = trim($_GET['type'] ?? 'all');
$room    = trim($_GET['room'] ?? '');
$page    = max(1, (int)($_GET['page']     ?? 1));
$perPage = max(1, (int)($_GET['per_page'] ?? 20));
$offset  = ($page - 1) * $perPage;

$dateFromStr = trim($_GET['date_from'] ?? '');
$dateToStr   = trim($_GET['date_to']   ?? '');

// ── Build date filter clause ──────────────────────────────
$dateFilter = '';
if ($dateFromStr && strtotime($dateFromStr) !== false) {
    $dateFilter .= " AND r.archived_at >= '" . $conn->real_escape_string($dateFromStr) . " 00:00:00'";
}
if ($dateToStr && strtotime($dateToStr) !== false) {
    // End of day for "to" date (23:59:59)
    $dateFilter .= " AND r.archived_at < '" . $conn->real_escape_string($dateToStr) . " 23:59:59'";
}

// ── Build room filter clause ──────────────────────────────
$roomFilter = '';
if ($room !== '') {
    $roomFilter = " AND r.room_number = '" . $conn->real_escape_string($room) . "'";
}

$allowedTypes = ['all', 'extend', 'service', 'food'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'all';
}

// ── Build SELECT + FROM + WHERE + extra columns per type ────

if ($type === 'all') {

    // For "all" we return only the base columns — no type-specific joins
    // because mixing aggregate and non-aggregate across three tables in one
    // query is messy and the JS only needs the base cols for rendering.
    $countSql = "SELECT COUNT(*) FROM requests r WHERE r.is_archived = 1" . $dateFilter . $roomFilter;
    $dataSql  = "SELECT r.id, r.room_number, r.type, r.status,
                        r.created_at, r.archived_at
                 FROM   requests r
                 WHERE  r.is_archived = 1" . $dateFilter . $roomFilter . "
                 ORDER  BY r.archived_at DESC, r.id DESC
                 LIMIT  ? OFFSET ?";

    $countStmt = $conn->prepare($countSql);
    $countStmt->execute();
    $countStmt->bind_result($total);
    $countStmt->fetch();
    $countStmt->close();

    $dataStmt = $conn->prepare($dataSql);
    $dataStmt->bind_param('ii', $perPage, $offset);
    $dataStmt->execute();
    $rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

} elseif ($type === 'extend') {

    $countSql = "SELECT COUNT(DISTINCT r.id)
                 FROM   requests r
                 JOIN   extension_requests er ON er.request_id = r.id
                 WHERE  r.is_archived = 1 AND r.type = 'extend'" . $dateFilter . $roomFilter;

    $dataSql  = "SELECT r.id, r.room_number, r.type, r.status,
                        r.created_at, r.archived_at,
                        er.hours_requested,
                        er.total_amount,
                        eh.payment_status AS hist_payment_status
                 FROM   requests r
                 JOIN   extension_requests er  ON er.request_id      = r.id
                 LEFT   JOIN extension_history eh ON eh.extension_req_id = er.id
                 WHERE  r.is_archived = 1 AND r.type = 'extend'" . $dateFilter . $roomFilter . "
                 ORDER  BY r.archived_at DESC, r.id DESC
                 LIMIT  ? OFFSET ?";

    $countStmt = $conn->prepare($countSql);
    $countStmt->execute();
    $countStmt->bind_result($total);
    $countStmt->fetch();
    $countStmt->close();

    $dataStmt = $conn->prepare($dataSql);
    $dataStmt->bind_param('ii', $perPage, $offset);
    $dataStmt->execute();
    $rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

} elseif ($type === 'service') {

    $countSql = "SELECT COUNT(*)
                 FROM   requests r
                 JOIN   service_requests sr ON sr.request_id = r.id
                 WHERE  r.is_archived = 1 AND r.type = 'service'" . $dateFilter . $roomFilter;

    $dataSql  = "SELECT r.id, r.room_number, r.type, r.status,
                        r.created_at, r.archived_at,
                        sr.service_name,
                        sr.total_amount,
                        sr.payment_status
                 FROM   requests r
                 JOIN   service_requests sr ON sr.request_id = r.id
                 WHERE  r.is_archived = 1 AND r.type = 'service'" . $dateFilter . $roomFilter . "
                 ORDER  BY r.archived_at DESC, r.id DESC
                 LIMIT  ? OFFSET ?";

    $countStmt = $conn->prepare($countSql);
    $countStmt->execute();
    $countStmt->bind_result($total);
    $countStmt->fetch();
    $countStmt->close();

    $dataStmt = $conn->prepare($dataSql);
    $dataStmt->bind_param('ii', $perPage, $offset);
    $dataStmt->execute();
    $rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

} else {
    // food
    $countSql = "SELECT COUNT(DISTINCT r.id)
                 FROM   requests r
                 JOIN   food_order_items foi ON foi.request_id = r.id
                 WHERE  r.is_archived = 1 AND r.type = 'food'" . $dateFilter . $roomFilter;

    $dataSql  = "SELECT r.id, r.room_number, r.type, r.status,
                        r.created_at, r.archived_at,
                        SUM(foi.total_amount)    AS total_amount,
                        MAX(foi.payment_status)  AS payment_status
                 FROM   requests r
                 JOIN   food_order_items foi ON foi.request_id = r.id
                 WHERE  r.is_archived = 1 AND r.type = 'food'" . $dateFilter . $roomFilter . "
                 GROUP  BY r.id
                 ORDER  BY r.archived_at DESC, r.id DESC
                 LIMIT  ? OFFSET ?";

    $countStmt = $conn->prepare($countSql);
    $countStmt->execute();
    $countStmt->bind_result($total);
    $countStmt->fetch();
    $countStmt->close();

    $dataStmt = $conn->prepare($dataSql);
    $dataStmt->bind_param('ii', $perPage, $offset);
    $dataStmt->execute();
    $rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();
    $dataStmt->close();
}

// Normalize date strings to a human-readable format so the Archive tab shows accurate timestamps
foreach ($rows as &$r) {
    if (!empty($r['created_at'])) {
        $ts = strtotime($r['created_at']);
        if ($ts !== false) $r['created_at'] = date('M j, Y g:ia', $ts);
    }
    if (!empty($r['archived_at'])) {
        $ts = strtotime($r['archived_at']);
        if ($ts !== false) $r['archived_at'] = date('M j, Y g:ia', $ts);
    }
}

echo json_encode([
    'success'  => true,
    'requests' => $rows,
    'total'    => (int)$total,
    'page'     => $page,
    'per_page' => $perPage,
]);