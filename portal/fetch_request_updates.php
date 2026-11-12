<?php
// Guest-scoped request statuses and front-desk replies.
require_once __DIR__ . '/guest_session.php';

header('Content-Type: application/json');

if (empty($_SESSION['guest_token']) || empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/../dbconnect.php';
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json');

$tokenStmt = $conn->prepare(
    'SELECT 1
     FROM guest_tokens gt
     JOIN customerstbl c ON c.room_no = gt.room_number
                          AND c.id = ?
                          AND c.status = \'checked-in\'
     WHERE gt.token = ? AND gt.expires_at > NOW()
     LIMIT 1'
);
$customerId = (int)$_SESSION['customer_id'];
$tokenStmt->bind_param('is', $customerId, $_SESSION['guest_token']);
$tokenStmt->execute();
$tokenStmt->store_result();
if ($tokenStmt->num_rows === 0) {
    $tokenStmt->close();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token expired']);
    exit();
}
$tokenStmt->close();

$stmt = $conn->prepare(
    'SELECT r.id, r.type, r.status, r.denial_reason, r.created_at, r.updated_at,
            rr.message AS latest_reply, rr.sent_at AS latest_reply_at
     FROM requests r
     LEFT JOIN request_replies rr
       ON rr.id = (
           SELECT reply.id
           FROM request_replies reply
           WHERE reply.request_id = r.id AND reply.sender = \'admin\'
           ORDER BY reply.sent_at DESC, reply.id DESC
           LIMIT 1
       )
     WHERE r.customer_id = ? AND r.is_archived = 0
       AND (r.status <> \'pending\' OR rr.id IS NOT NULL)
     ORDER BY COALESCE(rr.sent_at, r.updated_at) DESC, r.id DESC'
);
$stmt->bind_param('i', $customerId);
$stmt->execute();
$result = $stmt->get_result();
$updates = [];
while ($row = $result->fetch_assoc()) {
    $updates[] = $row;
}
$stmt->close();

foreach ($updates as &$update) {
    $update['id'] = (int)$update['id'];
    $update['message'] = $update['latest_reply'] ?? '';
}
unset($update);

echo json_encode(['success' => true, 'updates' => $updates]);
