<?php
// ============================================================
//  admin/fetch_archived_chats.php
//  Returns archived chat conversations grouped by room.
//
//  GET params:
//    room      — filter by room number (optional)
//    date_from — filter archived_at >= date (optional)
//    date_to   — filter archived_at <= date (optional)
//    page      — int >= 1 (default: 1)
//    per_page  — int (default: 20)
// ============================================================
session_start();
header('Content-Type: application/json');

include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../employee/auth.php');

$room      = trim($_GET['room']      ?? '');
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to']   ?? '');
$page      = max(1, (int)($_GET['page']     ?? 1));
$perPage   = max(1, (int)($_GET['per_page'] ?? 50));
$offset    = ($page - 1) * $perPage;

// ── Build WHERE clauses ──────────────────────────────────────
$where  = "WHERE cm.archived_at IS NOT NULL";
$params = [];
$types  = '';

if ($room !== '') {
    $where .= " AND cm.room_number = ?";
    $params[] = $room;
    $types   .= 's';
}
if ($dateFrom && strtotime($dateFrom) !== false) {
    $where .= " AND cm.archived_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
    $types   .= 's';
}
if ($dateTo && strtotime($dateTo) !== false) {
    $where .= " AND cm.archived_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
    $types   .= 's';
}

// ── Get conversation list (grouped by room) ──────────────────
$countSql = "SELECT COUNT(DISTINCT cm.room_number) FROM chat_messages cm $where";
$dataSql  = "SELECT cm.room_number,
                    COUNT(*)                             AS message_count,
                    MIN(cm.sent_at)                      AS first_message,
                    MAX(cm.sent_at)                      AS last_message,
                    MIN(cm.archived_at)                  AS archived_at,
                    SUM(cm.sender = 'guest')             AS guest_msgs,
                    SUM(cm.sender = 'admin')             AS admin_msgs
             FROM chat_messages cm
             $where
             GROUP BY cm.room_number
             ORDER BY MAX(cm.archived_at) DESC
             LIMIT ? OFFSET ?";

// Count
$countStmt = $conn->prepare($countSql);
if ($types) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countStmt->bind_result($total);
$countStmt->fetch();
$countStmt->close();

// Data
$dataParams = array_merge($params, [$perPage, $offset]);
$dataTypes  = $types . 'ii';

$dataStmt = $conn->prepare($dataSql);
$dataStmt->bind_param($dataTypes, ...$dataParams);
$dataStmt->execute();
$rooms = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dataStmt->close();

// Format archived_at for display consistency
foreach ($rooms as &$room) {
    if (!empty($room['archived_at'])) {
        $ts = strtotime($room['archived_at']);
        if ($ts !== false) $room['archived_at'] = date('M j, Y g:ia', $ts);
    }
    if (!empty($room['first_message'])) {
        $t = strtotime($room['first_message']);
        if ($t !== false) $room['first_message'] = date('M j, Y g:ia', $t);
    }
    if (!empty($room['last_message'])) {
        $t = strtotime($room['last_message']);
        if ($t !== false) $room['last_message'] = date('M j, Y g:ia', $t);
    }
}

echo json_encode([
    'success' => true,
    'rooms'   => $rooms,
    'total'   => (int)$total,
    'page'    => $page,
]);
