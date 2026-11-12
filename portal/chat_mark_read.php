<?php
require_once __DIR__ . '/guest_session.php';
include('../dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json');

$room = trim($_POST['room'] ?? '');
if (!$room || empty($_SESSION['guest_token']) || $_SESSION['room_number'] !== $room) {
    echo json_encode(['success' => false]);
    exit;
}

$roomEsc = $conn->real_escape_string($room);
$customerId = (int)($_SESSION['customer_id'] ?? 0);

$conn->query(
    "UPDATE chat_messages
     SET    is_read = 1, read_at = NOW()
     WHERE  room_number = '$roomEsc'
             AND  customer_id = $customerId
       AND  sender      = 'admin'
       AND  is_read     = 0"
);

echo json_encode(['success' => true]);
