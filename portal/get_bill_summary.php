<?php
require_once __DIR__ . '/guest_session.php';
if (empty($_SESSION['guest_token'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false]);
    exit;
}

include('../dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json');

header('Content-Type: application/json');

$cid = intval($_SESSION['customer_id'] ?? 0);
if ($cid <= 0) {
    echo json_encode(['success' => false]);
    exit;
}

$sql = "SELECT
    (SELECT COALESCE(SUM(amount), 0)
     FROM transaction_history
     WHERE customer_id = ? AND transaction_type = 'check_in'
       AND status = 'unpaid' AND archived_at IS NULL)          AS checkin_total,

    (SELECT COALESCE(SUM(er.total_amount), 0)
     FROM extension_requests er
     INNER JOIN requests r ON r.id = er.request_id
     WHERE er.customer_id = ? AND er.payment_status = 'unpaid'
       AND r.status != 'denied')                               AS extension_total,

    (SELECT COALESCE(SUM(sr.total_amount), 0)
     FROM service_requests sr
     INNER JOIN requests r ON r.id = sr.request_id
     WHERE r.customer_id = ? AND sr.payment_status = 'unpaid'
       AND r.is_archived = 0 AND r.status != 'denied')         AS service_total,

    (SELECT COALESCE(SUM(foi.total_amount), 0)
     FROM food_order_items foi
     INNER JOIN requests r ON r.id = foi.request_id
     WHERE r.customer_id = ? AND foi.payment_status = 'unpaid'
       AND r.is_archived = 0 AND r.status != 'denied')         AS food_total";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false]);
    exit;
}

$stmt->bind_param('iiii', $cid, $cid, $cid, $cid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false]);
    exit;
}

$checkin   = (float)$row['checkin_total'];
$extension = (float)$row['extension_total'];
$service   = (float)$row['service_total'];
$food      = (float)$row['food_total'];
$total     = $checkin + $extension + $service + $food;

echo json_encode([
    'success'   => true,
    'checkin'   => $checkin,
    'extension' => $extension,
    'service'   => $service,
    'food'      => $food,
    'total'     => $total,
]);