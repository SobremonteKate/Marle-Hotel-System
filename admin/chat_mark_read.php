<?php
session_start();
include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../csrf.php');
require_csrf_token();

$room = trim($_POST['room'] ?? '');
if (!$room || empty($_SESSION['username'])) {
    echo json_encode(['success' => false]);
    exit;
}

$roomEsc = $conn->real_escape_string($room);

$conn->query(
    "UPDATE chat_messages
     SET    is_read = 1, read_at = NOW()
     WHERE  room_number = '$roomEsc'
       AND  sender      = 'guest'
       AND  is_read     = 0"
);

echo json_encode(['success' => true]);
