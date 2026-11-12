<?php
// ============================================================
//  portal/submit_food_order.php
//  Guest AJAX endpoint — submit a food order (multiple items).
//  Called by menu.php Confirm Request button.
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

if (!$customerId || !$roomNumber) {
    echo json_encode(['success' => false, 'message' => 'Guest session data missing. Please refresh.']);
    exit();
}

// Decode items JSON
$rawItems = trim($_POST['items'] ?? '');
if (empty($rawItems)) {
    echo json_encode(['success' => false, 'message' => 'No items in order.']);
    exit();
}

$items = json_decode($rawItems, true);
if (!is_array($items) || count($items) === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order data.']);
    exit();
}

// Validate each item
foreach ($items as $item) {
    $qty = (int)($item['qty'] ?? 0);
    if (empty($item['name']) || $qty < 1 || $qty > 20) {
        echo json_encode(['success' => false, 'message' => 'Invalid item data. Please try again.']);
        exit();
    }
}

// Build hub message summary
$itemNames   = array_map(fn($i) => $i['name'] . ' x' . (int)$i['qty'], $items);
$message     = 'Food Order: ' . implode(', ', $itemNames);

$conn->begin_transaction();
try {
    // Insert into requests (hub table)
    $rStmt = $conn->prepare(
        "INSERT INTO requests (customer_id, room_number, type, message, status)
         VALUES (?, ?, 'food', ?, 'pending')"
    );
    $rStmt->bind_param('iss', $customerId, $roomNumber, $message);
    $rStmt->execute();
    $requestId = $conn->insert_id;
    $rStmt->close();

    // Insert one food_order_items row per item
    $iStmt = $conn->prepare(
        "INSERT INTO food_order_items
             (request_id, item_name, item_category, quantity, unit_price, total_amount, special_request)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($items as $item) {
        $name           = trim($item['name']);
        $category       = trim($item['category'] ?? '');
        $qty            = max(1, (int)$item['qty']);
        $unitPrice      = max(0.00, (float)($item['price'] ?? 0));
        $totalAmount    = $unitPrice * $qty;
        $specialRequest = trim($item['specialRequest'] ?? '') ?: null;

        $iStmt->bind_param(
            'issidds',
            $requestId, $name, $category, $qty, $unitPrice, $totalAmount, $specialRequest
        );
        $iStmt->execute();
    }
    $iStmt->close();

    // ── D2 fix (2026-09-26): persist the 16% service fee ──────────────────
    // The ordering popup quotes subtotal + 16% service fee, but historically
    // only the item subtotal was stored, so the bill and the ledger disagreed
    // with the quote. The fee is now saved as one explicit 'fee' line on the
    // order, so the stored order, the guest bill summary, the staff Food tab,
    // the approval reply and the transaction ledger all derive from the same
    // fee-inclusive rows. Denied orders never reach the bill or the ledger
    // (amount 0.00), so a denied order's fee row is inert.
    $subStmt = $conn->prepare(
        "SELECT COALESCE(SUM(total_amount), 0) AS subtotal
         FROM food_order_items
         WHERE request_id = ? AND item_category <> 'fee'"
    );
    $subStmt->bind_param('i', $requestId);
    $subStmt->execute();
    $foodSubtotal = (float)($subStmt->get_result()->fetch_assoc()['subtotal'] ?? 0);
    $subStmt->close();

    $serviceFee = round($foodSubtotal * 0.16, 2);
    if ($serviceFee > 0) {
        $feeStmt = $conn->prepare(
            "INSERT INTO food_order_items
                 (request_id, item_name, item_category, quantity, unit_price, total_amount, special_request)
             VALUES (?, 'Service Fee (16%)', 'fee', 1, ?, ?, NULL)"
        );
        $feeStmt->bind_param('idd', $requestId, $serviceFee, $serviceFee);
        $feeStmt->execute();
        $feeStmt->close();
    }

    $conn->commit();

    // ── Notify staff of food order (non-critical) ─────────────────────────────
    try {
        $notifMsg = "Room {$roomNumber}: food order — " . implode(', ', $itemNames);
        if (mb_strlen($notifMsg) > 200) $notifMsg = mb_strimwidth($notifMsg, 0, 197, '...');
        $nStmt = $conn->prepare(
            "INSERT INTO notifications (type, reference_id, room_number, message)
             VALUES ('food', ?, ?, ?)"
        );
        $nStmt->bind_param('iss', $requestId, $roomNumber, $notifMsg);
        $nStmt->execute();
        $nStmt->close();
    } catch (Exception $e) { /* non-critical — main request already committed */ }

    $itemCount = count($items);
    log_event($conn, 'portal_food_order', 'food_order', null, 'success', 'Room ' . ($_SESSION['room_number'] ?? '?') . ' — ' . $itemCount . ' item(s)');
    echo json_encode([
        'success' => true,
        'message' => 'Your order of ' . $itemCount . ' item' . ($itemCount !== 1 ? 's' : '') .
                     ' has been sent! The front desk will confirm shortly.'
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Could not submit order. Please try again.']);
}