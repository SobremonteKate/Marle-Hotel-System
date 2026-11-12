<?php
// chat_fetch.php  — shared by admin (requests.php) and guest (portal.php)
// Returns JSON array of messages for a room.
// ?mark_read=admin  → marks admin messages as read (called by guest side)
// Admin callers automatically mark guest messages as read.

session_start();
include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');

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
// if ($isAdmin && $markRead === 'guest') {
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

// ── Look up the active guest's customer_id for this room ─────────────────────
// Only one token can exist per room at a time. If none exists, the room is
// empty — return an empty array so no ghost messages are shown.
$gtStmt = $conn->prepare(
    "SELECT customer_id FROM guest_tokens
     WHERE room_number = ? AND expires_at > NOW()
     LIMIT 1"
);
$gtStmt->bind_param('s', $room);
$gtStmt->execute();
$gtRow = $gtStmt->get_result()->fetch_assoc();
$gtStmt->close();

if (!$gtRow || !$gtRow['customer_id']) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}
$activeCustomerId = (int)$gtRow['customer_id'];

// ── Fetch messages — scoped to the active guest only ─────────────────────────
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
$stmt->bind_param('si', $room, $activeCustomerId);
$stmt->execute();
$result = $stmt->get_result();

$msgs = [];
while ($row = $result->fetch_assoc()) {
    $msgs[] = $row;
}
$stmt->close();

header('Content-Type: application/json');
echo json_encode($msgs);