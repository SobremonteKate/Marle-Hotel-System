<?php
session_start();
header('Content-Type: application/json');

include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../superadmin/auth.php');
require_once __DIR__ . '/../includes/log_action.php';

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}

$action = trim($_POST['action'] ?? '');
$bookingId = (int)($_POST['booking_id'] ?? 0);

if ($bookingId < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID.']);
    exit();
}

if ($action === 'archive') {
    $check = $conn->prepare("SELECT status FROM bookings WHERE id = ? LIMIT 1");
    $check->bind_param('i', $bookingId);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit();
    }
    if ($row['status'] === 'pending') {
        echo json_encode(['success' => false, 'message' => 'Pending bookings cannot be archived.']);
        exit();
    }

    $stmt = $conn->prepare(
        "UPDATE bookings
         SET is_archived = 1,
             archived_at = NOW()
         WHERE id = ?
           AND status != 'pending'"
    );
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        log_event($conn, 'booking_archived', 'booking', $bookingId, 'success');
    }
    echo json_encode([
        'success' => $affected > 0,
        'message' => $affected > 0 ? 'Booking archived successfully.' : 'Could not archive booking.',
    ]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
