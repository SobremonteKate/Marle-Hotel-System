<?php
// ============================================================
//  admin/process_extend_action.php
//  Admin: Approve / Deny / Mark Paid on extension requests
//  Called via AJAX POST from requests.php
// ============================================================
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

$action    = trim($_POST['action']     ?? '');
$requestId = (int)($_POST['request_id'] ?? 0);
$adminUser = $_SESSION['username']     ?? 'admin';

if ($requestId < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
    exit();
}

// ── Fetch the request + extension detail ──────────────────
$fetch = $conn->prepare(
    "SELECT r.id,
            r.customer_id,
            r.room_number,
            r.status        AS req_status,
            er.id           AS ext_id,
            er.hours_requested,
            er.room_type,
            er.rate_per_hour,
            er.total_amount,
            er.payment_status
     FROM requests r
     JOIN extension_requests er ON er.request_id = r.id
     WHERE r.id = ?
       AND r.type = 'extend'
     LIMIT 1"
);
$fetch->bind_param('i', $requestId);
$fetch->execute();
$row = $fetch->get_result()->fetch_assoc();
$fetch->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Extension request not found.']);
    exit();
}

// ════════════════════════════════════════════════════════════
//  APPROVE
// ════════════════════════════════════════════════════════════
if ($action === 'approve') {

    if ($row['req_status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'This request is no longer pending.']);
        exit();
    }

    $hours      = (int)$row['hours_requested'];
    $customerId = (int)$row['customer_id'];
    $roomNumber = $row['room_number'];

    $conn->begin_transaction();
    try {

        // 1. Mark request approved
        $u1 = $conn->prepare("UPDATE requests SET status = 'approved' WHERE id = ?");
        $u1->bind_param('i', $requestId);
        $u1->execute();
        $u1->close();

        // 2. Stamp extension_requests
        $u2 = $conn->prepare(
            "UPDATE extension_requests
             SET approved_at = NOW(), approved_by = ?
             WHERE request_id = ?"
        );
        $u2->bind_param('si', $adminUser, $requestId);
        $u2->execute();
        $u2->close();

        // 3. Push date_to forward in customerstbl
        //    date_to is a DATE column — add hours as fractional days via INTERVAL
        $u3 = $conn->prepare(
            "UPDATE customerstbl
             SET date_to = DATE_ADD(date_to, INTERVAL ? HOUR),
                 hours   = CAST(CAST(hours AS UNSIGNED) + ? AS CHAR)
             WHERE id = ?"
        );
        $u3->bind_param('iii', $hours, $hours, $customerId);
        $u3->execute();
        $u3->close();

        // 4. Push expires_at forward in guest_tokens
        $u4 = $conn->prepare(
            "UPDATE guest_tokens
             SET expires_at    = DATE_ADD(expires_at, INTERVAL ? HOUR),
                 hours_of_stay = hours_of_stay + ?
             WHERE room_number = ?
               AND expires_at > NOW()
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $u4->bind_param('iis', $hours, $hours, $roomNumber);
        $u4->execute();
        $u4->close();

        // 5. Push check_out_time forward in bookings (hourly)
        //    Keeps home.php badge poll + get_room_availability.php in sync
        $u5 = $conn->prepare(
            "UPDATE bookings
             SET check_out_time = ADDTIME(check_out_time, MAKETIME(?, 0, 0))
             WHERE room_number     = ?
               AND booking_type   = 'hourly'
               AND status         = 'approved'
               AND hourly_released = 0
               AND check_in       = CURDATE()
             LIMIT 1"
        );
        $u5->bind_param('is', $hours, $roomNumber);
        $u5->execute();
        $u5->close();

        // 6. Push calendartbl.end forward via calendar_event_id FK
        //    Keeps FullCalendar display in sync with the new checkout time
        $u6 = $conn->prepare(
            "UPDATE calendartbl c
             JOIN bookings b ON b.calendar_event_id = c.id
             SET c.end = DATE_ADD(c.end, INTERVAL ? HOUR)
             WHERE b.room_number      = ?
               AND b.booking_type    = 'hourly'
               AND b.status          = 'approved'
               AND b.hourly_released = 0
               AND b.check_in        = CURDATE()"
        );
        $u6->bind_param('is', $hours, $roomNumber);
        $u6->execute();
        $u6->close();

        // 7. Get the fresh new date_to for the history log
        $newChkStmt = $conn->prepare(
            "SELECT date_to FROM customerstbl WHERE id = ? LIMIT 1"
        );
        $newChkStmt->bind_param('i', $customerId);
        $newChkStmt->execute();
        $newCheckout = $newChkStmt->get_result()->fetch_assoc()['date_to'];
        $newChkStmt->close();

        // 8. Write the immutable ledger row
        $ins = $conn->prepare(
            "INSERT INTO extension_history
                 (extension_req_id, customer_id, room_number, room_type,
                  hours_added, rate_per_hour, total_amount,
                  payment_method, payment_status, new_checkout, approved_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'front_desk', 'unpaid', ?, ?)"
        );
        $ins->bind_param(
            'iiisiddss',
            $row['ext_id'], $customerId, $roomNumber, $row['room_type'],
            $hours, $row['rate_per_hour'], $row['total_amount'],
            $newCheckout, $adminUser
        );
        $ins->execute();
        // Capture the inserted extension_history id for ledger linking
        $extHistoryId = (int)$conn->insert_id;
        $ins->close();

        // 7a. Also create a transaction_history row so this extension
        //      appears in the Transactions ledger (unpaid by default)
        $roomType = $row['room_type'] ?? null;
        $notes = 'Extension: ' . $hours . ' hour(s) — Front desk';
        $thStmt = $conn->prepare(
            "INSERT INTO transaction_history
                 (transaction_type, customer_id, room_number, room_type,
                  amount, status, reference_id, notes)
             VALUES ('extension', ?, ?, ?, ?, 'unpaid', ?, ?)"
        );
        $thStmt->bind_param(
            'issdis',
            $customerId, $roomNumber, $roomType,
            $row['total_amount'], $extHistoryId, $notes
        );
        $thStmt->execute();
        $thStmt->close();

        // 9. Auto-reply to the guest
        $autoMsg = "Your stay has been extended by {$hours} hour" . ($hours > 1 ? 's' : '') .
                   ". Thank you for choosing our hotel!";
        $rep = $conn->prepare(
            "INSERT INTO request_replies (request_id, sender, message)
             VALUES (?, 'admin', ?)"
        );
        $rep->bind_param('is', $requestId, $autoMsg);
        $rep->execute();
        $rep->close();

        $conn->commit();
        log_event($conn, 'extension_approved', 'extension_request', $requestId, 'success', $row['hours_requested'] . 'h for room ' . ($row['room_number'] ?? '?'));
        echo json_encode(['success' => true, 'message' => 'Extension approved. Guest session has been extended.']);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }

// ════════════════════════════════════════════════════════════
//  DENY
// ════════════════════════════════════════════════════════════
} elseif ($action === 'deny') {

    $reason = trim($_POST['reason'] ?? '');
    if (empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'A denial reason is required.']);
        exit();
    }

    if ($row['req_status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'This request is no longer pending.']);
        exit();
    }

    $conn->begin_transaction();
    try {
        $u1 = $conn->prepare(
            "UPDATE requests SET status = 'denied', denial_reason = ? WHERE id = ?"
        );
        $u1->bind_param('si', $reason, $requestId);
        $u1->execute();
        $u1->close();

        $u2 = $conn->prepare(
            "UPDATE extension_requests
             SET denied_at = NOW(), denied_by = ?
             WHERE request_id = ?"
        );
        $u2->bind_param('si', $adminUser, $requestId);
        $u2->execute();
        $u2->close();

        // Auto-reply with denial reason
        $denyMsg = "We're sorry, your extension request was not approved. Reason: {$reason}";
        $rep = $conn->prepare(
            "INSERT INTO request_replies (request_id, sender, message)
             VALUES (?, 'admin', ?)"
        );
        $rep->bind_param('is', $requestId, $denyMsg);
        $rep->execute();
        $rep->close();

        $conn->commit();
        log_event($conn, 'extension_denied', 'extension_request', $requestId, 'success', $reason);
        echo json_encode(['success' => true, 'message' => 'Extension request denied.']);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }

// ════════════════════════════════════════════════════════════
//  MARK PAID
// ════════════════════════════════════════════════════════════
} elseif ($action === 'mark_paid') {

    $histId = (int)($_POST['history_id'] ?? 0);
    
    // If history_id not provided or zero, fetch it from the database
    if ($histId < 1) {
        $histFetch = $conn->prepare(
            "SELECT eh.id FROM extension_history eh
             JOIN extension_requests er ON er.id = eh.extension_req_id
             WHERE er.request_id = ? LIMIT 1"
        );
        $histFetch->bind_param('i', $requestId);
        $histFetch->execute();
        $histRow = $histFetch->get_result()->fetch_assoc();
        $histFetch->close();
        
        if (!$histRow) {
            echo json_encode(['success' => false, 'message' => 'No payment record found. Request may not be approved yet.']);
            exit();
        }
        $histId = $histRow['id'];
    }

    $conn->begin_transaction();
    try {
        // Update history ledger
        $u1 = $conn->prepare(
            "UPDATE extension_history SET payment_status = 'paid' WHERE id = ?"
        );
        $u1->bind_param('i', $histId);
        $u1->execute();
        $u1->close();

        // Mirror on extension_requests
        $u2 = $conn->prepare(
            "UPDATE extension_requests er
             JOIN extension_history eh ON eh.extension_req_id = er.id
             SET er.payment_status = 'paid'
             WHERE eh.id = ?"
        );
        $u2->bind_param('i', $histId);
        $u2->execute();
        $u2->close();

        // Mirror on transaction_history so the Transactions ledger stays in sync
        $u3 = $conn->prepare(
            "UPDATE transaction_history
             SET status = 'paid'
             WHERE transaction_type = 'extension'
               AND reference_id = ?
               AND status = 'unpaid'"
        );
        $u3->bind_param('i', $histId);
        $u3->execute();
        $u3->close();

        $conn->commit();
        log_event($conn, 'extension_payment_marked_paid', 'extension_request', $requestId, 'success', 'Marked paid at front desk');
        echo json_encode(['success' => true, 'message' => 'Payment marked as received.']);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
