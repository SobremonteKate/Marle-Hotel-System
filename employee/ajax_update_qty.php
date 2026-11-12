<?php
session_start();
header('Content-Type: application/json');
include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('auth.php');
require_once __DIR__ . '/../includes/log_action.php';

$id = (int)($_POST['id'] ?? 0);
$delta = (int)($_POST['delta'] ?? 0);
if ($id < 1 || !in_array($delta, [-1, 1], true)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid quantity update.']);
    exit();
}

// CSRF: quantity writes are mutating actions and must carry a token.
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token.']);
    exit();
}

$stmt = $conn->prepare('UPDATE inventorytbl SET quantity = GREATEST(0, quantity + ?) WHERE id = ?');
$stmt->bind_param('ii', $delta, $id);
$stmt->execute();
$stmt->close();

$stmt = $conn->prepare('SELECT quantity FROM inventorytbl WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row) {
    log_event($conn, 'inventory_qty_changed', 'inventory_item', $id, 'success', ($delta > 0 ? '+' : '') . $delta . ' → new qty ' . (int)$row['quantity']);
    echo json_encode(['ok' => true, 'quantity' => (int)$row['quantity']]);
} else {
    echo json_encode(['ok' => false, 'message' => 'Inventory item not found.']);
}
