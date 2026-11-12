<?php
// chat_fetch.php  — shared by admin (requests.php) and guest (portal.php)
// Returns JSON array of messages for a room.
// ?mark_read=admin  → marks admin messages as read (called by guest side)
// Admin callers automatically mark guest messages as read.

require_once __DIR__ . '/guest_session.php';
include('../dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json');

$room     = trim($_GET['room'] ?? '');
$markRead = $_GET['mark_read'] ?? '';

if (!$room) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

// ── Determine caller: admin session or guest session ──────────────────────────
$isAdmin = !empty($_SESSION['username']);
$isGuest = !empty($_SESSION['guest_token'])
        && isset($_SESSION['room_number'])
        && $_SESSION['room_number'] === $room;

if (!$isAdmin && !$isGuest) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

$roomEsc = $conn->real_escape_string($room);

// IMPORTANT: Mark-as-read operations are intentionally removed from this endpoint.
// Marking messages as read should only happen when the user explicitly views them in the UI,
// not when fetching chat history. This ensures notification badges remain accurate:
// - Admins see unread count until they actually open the chat in requests.php
// - Guests see unread count until they actually open the chat in portal.php
// 
// To maintain backwards compatibility while improving accuracy:
// - The fetch endpoint now only retrieves messages
// - Mark-as-read logic has been moved to dedicated endpoints (chat_mark_read.php)
// - This prevents premature flattening of the notification count
//
// NOTE: If you need the old behavior, implement explicit mark-as-read endpoints
// that are only called when the user actually opens the chat UI, not on page load.

// ── Old mark-as-read logic (DEPRECATED - see note above) ──────────────────────
// if ($isAdmin) {
//     $conn->query(
//         "UPDATE chat_messages
//          SET    is_read = 1
//          WHERE  room_number = '$roomEsc'
//            AND  sender      = 'guest'
//            AND  is_read     = 0"
//     );
// }
// if ($isGuest && $markRead === 'admin') {
//     $conn->query(
//         "UPDATE chat_messages
//          SET    is_read = 1
//          WHERE  room_number = '$roomEsc'
//            AND  sender      = 'admin'
//            AND  is_read     = 0"
//     );
// }

// ── Fetch chat_room_status so guest UI can show closed-chat indicator ─────────
$statusRow = $conn->query(
    "SELECT status FROM chat_room_status WHERE room_number = '" . $roomEsc . "' LIMIT 1"
)->fetch_assoc();
$chatStatus = $statusRow['status'] ?? 'new';

// ── Fetch messages — scoped to this guest's customer_id only ─────────────────
$customerId = (int)($_SESSION['customer_id'] ?? 0);
if ($customerId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['messages' => [], 'chat_status' => $chatStatus]);
    exit;
}

$stmt = $conn->prepare(
    "SELECT sender,
            message,
            COALESCE(attachment_path, '') AS attachment_path,
            COALESCE(attachment_name, '') AS attachment_name,
            COALESCE(attachment_type, '') AS attachment_type,
            is_read,
            sent_at
     FROM   chat_messages
     WHERE  room_number = ?
       AND  customer_id = ?
       AND  archived_at IS NULL
     ORDER  BY sent_at ASC"
);
$stmt->bind_param('si', $room, $customerId);
$stmt->execute();
$result = $stmt->get_result();

$msgs = [];
while ($row = $result->fetch_assoc()) {
    $msgs[] = $row;
}
$stmt->close();

$unreadStmt = $conn->prepare(
    "SELECT COUNT(*) AS unread_count
     FROM chat_messages
     WHERE room_number = ?
       AND customer_id = ?
       AND sender = 'admin'
       AND is_read = 0
       AND archived_at IS NULL"
);
$unreadStmt->bind_param('si', $room, $customerId);
$unreadStmt->execute();
$unreadCount = (int)($unreadStmt->get_result()->fetch_assoc()['unread_count'] ?? 0);
$unreadStmt->close();

header('Content-Type: application/json');
echo json_encode([
    'messages' => $msgs,
    'chat_status' => $chatStatus,
    'unread_admin_count' => $unreadCount,
]);
