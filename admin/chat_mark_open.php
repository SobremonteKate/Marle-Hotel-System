<?php
// chat_mark_open.php
// Called when admin clicks a chat for the first time.
// Upserts chat_room_status: 'new' → 'in_progress', records first_opened_at.
// Also marks all guest messages in the room as read.

include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../admin/auth_admin.php');
include('../csrf.php');
require_csrf_token();

header('Content-Type: application/json');

$room = trim($_POST['room'] ?? '');
if (!$room) {
    echo json_encode(['success' => false]);
    exit;
}

$roomEsc = $conn->real_escape_string($room);

// Upsert: only escalate new → in_progress; leave complete alone
$conn->query(
    "INSERT INTO chat_room_status (room_number, status, first_opened_at)
     VALUES ('$roomEsc', 'in_progress', NOW())
     ON DUPLICATE KEY UPDATE
         first_opened_at = COALESCE(first_opened_at, NOW()),
         status          = IF(status = 'new', 'in_progress', status)"
);

// Mark all unread guest messages in this room as read (active session only)
$conn->query(
    "UPDATE chat_messages
     SET    is_read = 1
     WHERE  room_number = '$roomEsc'
       AND  sender      = 'guest'
       AND  is_read     = 0
       AND  archived_at IS NULL"
);

$row = $conn->query(
    "SELECT status FROM chat_room_status WHERE room_number = '$roomEsc' LIMIT 1"
)->fetch_assoc();

echo json_encode(['success' => true, 'status' => $row['status'] ?? 'in_progress']);