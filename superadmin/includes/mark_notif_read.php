<?php
// ============================================================
//  admin/includes/mark_notif_read.php
//  Marks notification(s) as read.
//  POST params:
//    id  — int — mark a single notification as read
//    all — '1' — mark all unread notifications as read
// ============================================================
header('Content-Type: application/json');
session_start();

if (empty($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

include(__DIR__ . '/../../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');

$id  = (int)($_POST['id']  ?? 0);
$all = ($_POST['all'] ?? '') === '1';
$type = trim($_POST['type'] ?? '');
$refId = (int)($_POST['reference_id'] ?? 0);

if ($id > 0) {
    $stmt = $conn->prepare(
        "UPDATE notifications SET is_read = 1 WHERE id = ? AND is_read = 0"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
} elseif ($type !== '' && $refId > 0) {
    // Mark by type + reference_id (used for auto-marking resolved items)
    $stmt = $conn->prepare(
        "UPDATE notifications SET is_read = 1 WHERE type = ? AND reference_id = ? AND is_read = 0"
    );
    $stmt->bind_param('si', $type, $refId);
    $stmt->execute();
    $stmt->close();
} elseif ($all) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
}

echo json_encode(['success' => true]);