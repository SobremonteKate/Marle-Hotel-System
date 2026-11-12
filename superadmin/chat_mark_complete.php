<?php
// chat_mark_complete.php
// Called when admin clicks "Mark Complete".
// Upserts chat_room_status → status = 'complete'.

include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../superadmin/auth.php');
require_once __DIR__ . '/../includes/log_action.php';
include('../csrf.php');
require_csrf_token();

header('Content-Type: application/json');

$room = trim($_POST['room'] ?? '');
if (!$room) {
    echo json_encode(['success' => false, 'error' => 'No room specified.']);
    exit;
}

$roomEsc = $conn->real_escape_string($room);

$conn->query(
    "INSERT INTO chat_room_status (room_number, status, first_opened_at, completed_at)
     VALUES ('$roomEsc', 'complete', NOW(), NOW())
     ON DUPLICATE KEY UPDATE
         status          = 'complete',
         completed_at    = NOW(),
         first_opened_at = COALESCE(first_opened_at, NOW())"
);

if ($conn->errno) {
    echo json_encode(['success' => false, 'error' => $conn->error]);
    exit;
}

log_event($conn, 'chat_marked_complete', 'chat_room_status', null, 'success', 'Room ' . $room);
echo json_encode(['success' => true]);