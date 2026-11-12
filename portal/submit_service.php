<?php
// ============================================================
//  portal/submit_service.php
//  Guest AJAX endpoint — submit a single service request.
//  Called by services.php (+) button handler.
// ============================================================
require_once __DIR__ . '/guest_session.php';
require_once __DIR__ . '/../includes/log_action.php';

header('Content-Type: application/json');

if (empty($_SESSION['guest_token'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session not found. Please log in again.']);
    exit();
}

include('../dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json');

$customerId = (int)($_SESSION['customer_id'] ?? 0);
$roomNumber = $_SESSION['room_number'] ?? '';

// Validate token, guest identity, and active stay together.
$chk = $conn->prepare(
        "SELECT gt.id FROM guest_tokens gt
         INNER JOIN customerstbl c ON c.id = gt.customer_id
         WHERE gt.token = ? AND gt.expires_at > NOW()
             AND c.id = ? AND c.room_no = ? AND c.status = 'checked-in'
         LIMIT 1"
);
$chk->bind_param('sis', $_SESSION['guest_token'], $customerId, $roomNumber);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    $chk->close();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your session has expired.']);
    exit();
}
$chk->close();

$serviceName     = trim($_POST['service_name']     ?? '');
$serviceCategory = trim($_POST['service_category'] ?? '');
$quantity        = max(1, (int)($_POST['quantity']    ?? 1));
$unitPrice       = max(0.00, (float)($_POST['unit_price'] ?? 0));

if (!$customerId || !$roomNumber) {
    echo json_encode(['success' => false, 'message' => 'Guest session data missing. Please refresh.']);
    exit();
}
if (empty($serviceName)) {
    echo json_encode(['success' => false, 'message' => 'Service name is required.']);
    exit();
}
if ($quantity < 1 || $quantity > 20) {
    echo json_encode(['success' => false, 'message' => 'Quantity must be between 1 and 20.']);
    exit();
}

$totalAmount = $unitPrice * $quantity;
$message     = 'Service Request: ' . $serviceName .
               ($quantity > 1 ? ' (x' . $quantity . ')' : '');

$conn->begin_transaction();
try {
    // Insert into requests (hub table)
    $rStmt = $conn->prepare(
        "INSERT INTO requests (customer_id, room_number, type, message, status)
         VALUES (?, ?, 'service', ?, 'pending')"
    );
    $rStmt->bind_param('iss', $customerId, $roomNumber, $message);
    $rStmt->execute();
    $requestId = $conn->insert_id;
    $rStmt->close();

    // Insert service detail row
    $sStmt = $conn->prepare(
        "INSERT INTO service_requests
             (request_id, service_name, service_category, quantity, unit_price, total_amount)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $sStmt->bind_param('sssidd', $requestId, $serviceName, $serviceCategory, $quantity, $unitPrice, $totalAmount);
    $sStmt->execute();
    $sStmt->close();

    $conn->commit();

    // ── Notify staff of service request (non-critical) ────────────────────────
    try {
        $notifMsg = "Room {$roomNumber}: service request — {$serviceName}";
        $nStmt    = $conn->prepare(
            "INSERT INTO notifications (type, reference_id, room_number, message)
             VALUES ('service', ?, ?, ?)"
        );
        $nStmt->bind_param('iss', $requestId, $roomNumber, $notifMsg);
        $nStmt->execute();
        $nStmt->close();
    } catch (Exception $e) { /* non-critical — main request already committed */ }

    $displayTotal = $unitPrice > 0
    
        ? '₱' . number_format($totalAmount, 2)
        : 'Free';

    log_event($conn, 'portal_service_request', 'service_request', null, 'success', 'Room ' . ($_SESSION['room_number'] ?? '?') . ' — ' . $serviceName);
    echo json_encode([
        'success' => true,
        'message' => '"' . $serviceName . '" requested! (' . $displayTotal . ') ' .
                     'The front desk will confirm shortly.'
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Could not submit request. Please try again.']);
}