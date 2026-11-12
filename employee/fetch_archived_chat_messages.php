<?php
// ============================================================
//  admin/fetch_archived_chat_messages.php
//  Returns archived chat messages for a specific room.
//
//  GET params:
//    room — room number (required)
// ============================================================
session_start();
header('Content-Type: application/json');

include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../employee/auth.php');

$room = trim($_GET['room'] ?? '');
if ($room === '') {
    echo json_encode(['success' => false, 'message' => 'Room number required.']);
    exit();
}

$stmt = $conn->prepare(
    "SELECT id, room_number, customer_id, sender, message,
            attachment_path, attachment_name, attachment_type,
            sent_at, archived_at, is_read
     FROM chat_messages
     WHERE room_number = ?
       AND archived_at IS NOT NULL
     ORDER BY sent_at ASC"
);
$stmt->bind_param('s', $room);
$stmt->execute();
$messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode([
    'success'  => true,
    'messages' => $messages,
]);
