<?php
// ============================================================
//  portal/fetch_service_requests.php  —  Fetch service requests
// ============================================================
require_once __DIR__ . '/guest_session.php';

header('Content-Type: application/json');

if (empty($_SESSION['guest_token']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

include('../dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json');

// Validate token
$chk = $conn->prepare(
    "SELECT expires_at FROM guest_tokens WHERE token = ? AND expires_at > NOW() LIMIT 1"
);
$chk->bind_param('s', $_SESSION['guest_token']);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    $chk->close();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token expired']);
    exit();
}
$chk->close();

$customerId = (int)$_SESSION['customer_id'];

// Fetch updated service requests
$svcRequests = [];
$svcStmt = $conn->prepare(
    "SELECT r.id, r.status, r.denial_reason, r.created_at,
            sr.service_name, sr.service_category, sr.quantity,
            sr.unit_price, sr.total_amount, sr.payment_status
     FROM requests r
     JOIN service_requests sr ON sr.request_id = r.id
     WHERE r.customer_id = ? AND r.type = 'service'
     ORDER BY r.created_at DESC"
);
$svcStmt->bind_param('i', $customerId);
$svcStmt->execute();
$svcResult = $svcStmt->get_result();
while ($row = $svcResult->fetch_assoc()) {
    // Format date for JavaScript
    $row['created_at_formatted'] = date('M j, g:ia', strtotime($row['created_at']));
    $row['total_amount'] = number_format((float)$row['total_amount'], 2);
    $svcRequests[] = $row;
}
$svcStmt->close();

echo json_encode([
    'success' => true,
    'requests' => $svcRequests
]);
?>
