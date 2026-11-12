<?php
// ============================================================
//  admin/process_food_action.php
//  Admin-only AJAX endpoint for food order management.
//  Called by requests.php Food tab.
// ============================================================
include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../superadmin/auth.php');
require_once __DIR__ . '/../includes/log_action.php';

header('Content-Type: application/json');

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}

$action    = $_POST['action']     ?? '';
$requestId = (int)($_POST['request_id'] ?? 0);
$reason    = trim($_POST['reason'] ?? '');

if (!$requestId) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
    exit();
}

// ── Helper: fetch food order + request row ────────────────────
function fetchFoodRow(mysqli $conn, int $requestId): ?array
{
    $stmt = $conn->prepare(
        "SELECT r.id AS request_id, r.customer_id, r.room_number, r.status,
                MIN(foi.id)                          AS food_item_id,
                SUM(foi.total_amount)                AS total_amount,
                GROUP_CONCAT(foi.item_name
                    ORDER BY foi.id
                    SEPARATOR ', ')                  AS item_names,
                MAX(foi.payment_status)              AS payment_status
         FROM requests r
         JOIN food_order_items foi ON foi.request_id = r.id
         WHERE r.id = ? AND r.type = 'food'
         GROUP BY r.id, r.customer_id, r.room_number, r.status
         LIMIT 1"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ── Helper: get room type ─────────────────────────────────────
function getFoodRoomType(mysqli $conn, string $roomNumber): ?string
{
    $stmt = $conn->prepare("SELECT rooms FROM roomstbl WHERE room_number = ? LIMIT 1");
    $stmt->bind_param('s', $roomNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['rooms'] ?? null;
}

// ── Helper: add request_reply ─────────────────────────────────
function addFoodReply(mysqli $conn, int $requestId, string $message): void
{
    $stmt = $conn->prepare(
        "INSERT INTO request_replies (request_id, sender, message) VALUES (?, 'admin', ?)"
    );
    $stmt->bind_param('is', $requestId, $message);
    $stmt->execute();
    $stmt->close();
}

switch ($action) {

    // ── APPROVE ───────────────────────────────────────────────
    case 'approve':
        $food = fetchFoodRow($conn, $requestId);
        if (!$food) {
            echo json_encode(['success' => false, 'message' => 'Food order not found.']);
            exit();
        }
        if ($food['status'] !== 'pending') {
            echo json_encode(['success' => false, 'message' => 'This request has already been processed.']);
            exit();
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "UPDATE requests SET status='approved', updated_at=NOW()
                 WHERE id=? AND type='food'"
            );
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $stmt->close();

            addFoodReply($conn, $requestId,
                'Your food order (' . $food['item_names'] . ') has been approved. ' .
                'It will be prepared shortly. Payment of ₱' .
                number_format((float)$food['total_amount'], 2) .
                ' is due at the front desk.'
            );

            $roomType = getFoodRoomType($conn, $food['room_number']);
            $thStmt = $conn->prepare(
                "INSERT INTO transaction_history
                     (transaction_type, customer_id, room_number, room_type,
                      amount, status, reference_id, notes)
                 VALUES ('food', ?, ?, ?, ?, 'unpaid', ?, ?)"
            );
            $notes = 'Food Order: ' . $food['item_names'];
            $thStmt->bind_param(
                'issdis',
                $food['customer_id'], $food['room_number'], $roomType,
                $food['total_amount'], $food['food_item_id'], $notes
            );
            $thStmt->execute();
            $thStmt->close();

            $conn->commit();
            log_event($conn, 'food_order_approved', 'food_order', $requestId, 'success');
            echo json_encode(['success' => true, 'message' => 'Food order approved.']);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
        }
        break;

    // ── DENY ─────────────────────────────────────────────────
    case 'deny':
        if (empty($reason)) {
            echo json_encode(['success' => false, 'message' => 'A denial reason is required.']);
            exit();
        }

        $food = fetchFoodRow($conn, $requestId);
        if (!$food) {
            echo json_encode(['success' => false, 'message' => 'Food order not found.']);
            exit();
        }
        if ($food['status'] !== 'pending') {
            echo json_encode(['success' => false, 'message' => 'This request has already been processed.']);
            exit();
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "UPDATE requests SET status='denied', denial_reason=?, updated_at=NOW()
                 WHERE id=? AND type='food'"
            );
            $stmt->bind_param('si', $reason, $requestId);
            $stmt->execute();
            $stmt->close();

            $replyMsg = "We're sorry, your food order (" . $food['item_names'] .
                        ") was not approved. Reason: " . $reason;
            addFoodReply($conn, $requestId, $replyMsg);

            $roomType = getFoodRoomType($conn, $food['room_number']);
            $thStmt = $conn->prepare(
                "INSERT INTO transaction_history
                     (transaction_type, customer_id, room_number, room_type,
                      amount, status, denial_reason, reference_id, notes)
                 VALUES ('food', ?, ?, ?, 0.00, 'denied', ?, ?, ?)"
            );
            $notes = 'Food order denied: ' . $food['item_names'];
            $thStmt->bind_param(
                'isssis',
                $food['customer_id'], $food['room_number'], $roomType,
                $reason, $food['food_item_id'], $notes
            );
            $thStmt->execute();
            $thStmt->close();

            $conn->commit();
            log_event($conn, 'food_order_denied', 'food_order', $requestId, 'success', $reason);
            echo json_encode(['success' => true, 'message' => 'Food order denied.']);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
        }
        break;

    // ── MARK PAID ────────────────────────────────────────────
    case 'mark_paid':
        $food = fetchFoodRow($conn, $requestId);
        if (!$food) {
            echo json_encode(['success' => false, 'message' => 'Food order not found.']);
            exit();
        }

        $conn->begin_transaction();
        try {
            // Update all food_order_items rows for this request
            $stmt = $conn->prepare(
                "UPDATE food_order_items SET payment_status='paid' WHERE request_id=?"
            );
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $stmt->close();

            // Update transaction_history status
            $thStmt = $conn->prepare(
                "UPDATE transaction_history
                 SET status = 'paid'
                 WHERE transaction_type = 'food'
                   AND reference_id = ?
                   AND status = 'unpaid'"
            );
            $thStmt->bind_param('i', $food['food_item_id']);
            $thStmt->execute();
            $thStmt->close();

            $conn->commit();
            log_event($conn, 'food_payment_marked_paid', 'food_order', $requestId, 'success', 'Marked paid at front desk');
            echo json_encode(['success' => true, 'message' => 'Food order marked as paid at front desk.']);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
