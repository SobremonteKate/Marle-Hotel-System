<?php
// TODO: Employee limited permissions need deeper checks on this page/action.
// ============================================================
//  admin/process_mark_all_paid.php
//  One-click payment collection for every unpaid transaction in
//  a room. The transaction ledger is the source of truth, then
//  request tables are mirrored so requests.php tabs stay in sync.
// ============================================================
session_start();
header('Content-Type: application/json');

include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../employee/auth.php');
require_once __DIR__ . '/../includes/log_action.php';
include('../csrf.php');

require_capability('collect_payments');
require_csrf_token();

$roomNumber = trim($_POST['room_number'] ?? '');
$amountReceivedInput = trim((string)($_POST['amount_received'] ?? ''));
$amountReceived = round((float)$amountReceivedInput, 2);

if ($roomNumber === '') {
    echo json_encode(['success' => false, 'message' => 'Room number is required.']);
    exit();
}
if ($amountReceivedInput === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $amountReceivedInput) || $amountReceived <= 0) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid amount received greater than zero.']);
    exit();
}

$extCount     = 0;
$svcCount     = 0;
$foodCount    = 0;
$checkinCount = 0;
$totalAmount  = 0.0;
$collectedTransactionIds = [];

$conn->begin_transaction();
try {
    $dueStmt = $conn->prepare(
                "SELECT amount
         FROM transaction_history
         WHERE TRIM(room_number) = ?
           AND status = 'unpaid'
           AND archived_at IS NULL
         AND (
             -- check_in rows have NO EXISTS guard on purpose: tokens are routinely
             -- deleted by the expiry sweep / admin revoke (archived to
             -- guest_tokens_history) while the charge is still unpaid. The guest
             -- stayed, so the charge stays collectable.
             (transaction_type = 'check_in')
             OR (transaction_type = 'extension' AND EXISTS (SELECT 1 FROM extension_history WHERE extension_history.id = transaction_history.reference_id))
             OR (transaction_type = 'service' AND EXISTS (SELECT 1 FROM service_requests WHERE service_requests.id = transaction_history.reference_id))
             OR (transaction_type = 'food' AND EXISTS (SELECT 1 FROM food_order_items WHERE food_order_items.id = transaction_history.reference_id))
         )
         FOR UPDATE"
    );
    $dueStmt->bind_param('s', $roomNumber);
    $dueStmt->execute();
        $dueRows = $dueStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dueStmt->close();

        $expectedItemCount = count($dueRows);
        $expectedTotal = round(array_sum(array_map(static fn(array $row): float => (float)$row['amount'], $dueRows)), 2);
    if ($expectedItemCount === 0) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => "No unpaid items found for Room {$roomNumber}.",
        ]);
        exit();
    }
    if ($amountReceived < $expectedTotal) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => 'Amount received is less than the total due of PHP ' . number_format($expectedTotal, 2) . '.',
            'total_amount' => $expectedTotal,
        ]);
        exit();
    }

    // Extensions: pay unpaid ledger rows, then mirror to extension tables.
    $extStmt = $conn->prepare(
        "SELECT th.id AS th_id,
                th.reference_id AS eh_id,
                eh.extension_req_id AS er_id,
                COALESCE(th.amount, eh.total_amount, 0) AS amount
         FROM transaction_history th
         JOIN extension_history eh ON eh.id = th.reference_id
         WHERE th.transaction_type = 'extension'
           AND TRIM(th.room_number) = ?
           AND th.status = 'unpaid'
                     AND th.archived_at IS NULL
                 FOR UPDATE"
    );
    $extStmt->bind_param('s', $roomNumber);
    $extStmt->execute();
    $extRows = $extStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $extStmt->close();

    if (!empty($extRows)) {
        $updTh = $conn->prepare("UPDATE transaction_history SET status = 'paid' WHERE id = ?");
        $updEh = $conn->prepare("UPDATE extension_history SET payment_status = 'paid' WHERE id = ?");
        $updEr = $conn->prepare("UPDATE extension_requests SET payment_status = 'paid' WHERE id = ?");

        foreach ($extRows as $row) {
            $collectedTransactionIds[] = (int)$row['th_id'];
            $updTh->bind_param('i', $row['th_id']);
            $updTh->execute();
            $updEh->bind_param('i', $row['eh_id']);
            $updEh->execute();
            $updEr->bind_param('i', $row['er_id']);
            $updEr->execute();

            $extCount++;
            $totalAmount += (float)($row['amount'] ?? 0.0);
        }

        $updTh->close();
        $updEh->close();
        $updEr->close();
    }

    // Services: pay unpaid ledger rows, then mirror to service_requests.
    $svcStmt = $conn->prepare(
        "SELECT th.id AS th_id,
                th.reference_id AS sr_id,
                COALESCE(th.amount, sr.total_amount, 0) AS amount
         FROM transaction_history th
         JOIN service_requests sr ON sr.id = th.reference_id
         WHERE th.transaction_type = 'service'
           AND TRIM(th.room_number) = ?
           AND th.status = 'unpaid'
                     AND th.archived_at IS NULL
                 FOR UPDATE"
    );
    $svcStmt->bind_param('s', $roomNumber);
    $svcStmt->execute();
    $svcRows = $svcStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $svcStmt->close();

    if (!empty($svcRows)) {
        $updTh = $conn->prepare("UPDATE transaction_history SET status = 'paid' WHERE id = ?");
        $updSr = $conn->prepare("UPDATE service_requests SET payment_status = 'paid' WHERE id = ?");

        foreach ($svcRows as $row) {
            $collectedTransactionIds[] = (int)$row['th_id'];
            $updTh->bind_param('i', $row['th_id']);
            $updTh->execute();
            $updSr->bind_param('i', $row['sr_id']);
            $updSr->execute();

            $svcCount++;
            $totalAmount += (float)($row['amount'] ?? 0.0);
        }

        $updTh->close();
        $updSr->close();
    }

    // Food: transaction_history references the first item in the order.
    // Pay the ledger row and every food_order_items row for that request.
    $foodStmt = $conn->prepare(
        "SELECT th.id AS th_id,
                th.reference_id AS foi_id,
                foi.request_id,
                COALESCE(th.amount, foi.total_amount, 0) AS amount
         FROM transaction_history th
         JOIN food_order_items foi ON foi.id = th.reference_id
         WHERE th.transaction_type = 'food'
           AND TRIM(th.room_number) = ?
           AND th.status = 'unpaid'
                     AND th.archived_at IS NULL
                 FOR UPDATE"
    );
    $foodStmt->bind_param('s', $roomNumber);
    $foodStmt->execute();
    $foodRows = $foodStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $foodStmt->close();

    if (!empty($foodRows)) {
        $updTh  = $conn->prepare("UPDATE transaction_history SET status = 'paid' WHERE id = ?");
        $updFoi = $conn->prepare("UPDATE food_order_items SET payment_status = 'paid' WHERE request_id = ?");

        foreach ($foodRows as $row) {
            $collectedTransactionIds[] = (int)$row['th_id'];
            $updTh->bind_param('i', $row['th_id']);
            $updTh->execute();
            $updFoi->bind_param('i', $row['request_id']);
            $updFoi->execute();

            $foodCount++;
            $totalAmount += (float)($row['amount'] ?? 0.0);
        }

        $updTh->close();
        $updFoi->close();
    }

    // Check-ins live only in transaction_history.
    $checkinStmt = $conn->prepare(
        "SELECT id AS th_id, reference_id, COALESCE(amount, 0) AS amount
         FROM transaction_history
         WHERE transaction_type = 'check_in'
           AND TRIM(room_number) = ?
           AND status = 'unpaid'
                     AND archived_at IS NULL
                 FOR UPDATE"
    );
    $checkinStmt->bind_param('s', $roomNumber);
    $checkinStmt->execute();
    $checkinRows = $checkinStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $checkinStmt->close();

    $checkinWarning = false;
    if (!empty($checkinRows)) {
        $updTh = $conn->prepare("UPDATE transaction_history SET status = 'paid' WHERE id = ?");
        // The token is looked for in guest_tokens *and* guest_tokens_history.
        // Deleting a token is a normal step in its lifecycle — the expiry sweep,
        // an admin revoke and a token re-issue all archive the row into
        // guest_tokens_history (original_id) *before* removing it from
        // guest_tokens, and the due-query above is written around exactly that.
        // Asking only the live table therefore logged "references missing
        // guest_tokens" for a state the app treats as expected, which is how a
        // routine collection comes to read as a data fault in the error log.
        // The warning is now kept for what it describes: a ledger row pointing
        // at a token that exists in neither table.
        $verifyStmt = $conn->prepare(
            "SELECT 'live' AS found FROM guest_tokens WHERE id = ?
             UNION ALL
             SELECT 'archive' AS found FROM guest_tokens_history WHERE original_id = ?"
        );

        foreach ($checkinRows as $row) {
            $collectedTransactionIds[] = (int)$row['th_id'];
            $referenceId = (int)$row['reference_id'];
            $verifyStmt->bind_param('ii', $referenceId, $referenceId);
            $verifyStmt->execute();
            $found = array_column($verifyStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'found');

            // No live token: the guest's session has ended and the token has
            // been archived. That is the normal case the success message already
            // flags as an FYI suffix, so it says nothing here.
            if (!in_array('live', $found, true)) {
                $checkinWarning = true;
            }
            // In neither table: the charge has no guest session behind it
            // anywhere, which is the one case worth a line in the error log.
            if (!in_array('live', $found, true) && !in_array('archive', $found, true)) {
                error_log("WARNING [process_mark_all_paid.php] transaction_history id={$row['th_id']} references guest_tokens id={$referenceId} for room {$roomNumber}, which is in neither guest_tokens nor guest_tokens_history");
            }

            $updTh->bind_param('i', $row['th_id']);
            $updTh->execute();

            $checkinCount++;
            $totalAmount += (float)($row['amount'] ?? 0.0);
        }

        $verifyStmt->close();
        $updTh->close();
    }

    if ($extCount === 0 && $svcCount === 0 && $foodCount === 0 && $checkinCount === 0) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => "No unpaid items found for Room {$roomNumber}.",
        ]);
        exit();
    }

    if ($amountReceived < $totalAmount) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => 'Amount received is less than the total due of PHP ' . number_format($totalAmount, 2) . '.',
            'total_amount' => round($totalAmount, 2),
        ]);
        exit();
    }

    $changeAmount = round($amountReceived - $totalAmount, 2);
    $collector = trim((string)($_SESSION['username'] ?? 'staff'));
    $collectionStmt = $conn->prepare(
        'INSERT INTO transaction_collections
            (room_number, total_due, amount_received, change_amount, collected_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $collectionStmt->bind_param('sddds', $roomNumber, $totalAmount, $amountReceived, $changeAmount, $collector);
    $collectionStmt->execute();
    $collectionId = $collectionStmt->insert_id;
    $collectionStmt->close();

    $itemStmt = $conn->prepare(
        'INSERT INTO transaction_collection_items (collection_id, transaction_id, amount)
         SELECT ?, id, amount FROM transaction_history WHERE id = ?'
    );
    foreach ($collectedTransactionIds as $transactionId) {
        $itemStmt->bind_param('ii', $collectionId, $transactionId);
        $itemStmt->execute();
    }
    $itemStmt->close();

    $conn->commit();

    $parts = [];
    if ($extCount > 0) {
        $parts[] = "{$extCount} extension" . ($extCount > 1 ? 's' : '');
    }
    if ($svcCount > 0) {
        $parts[] = "{$svcCount} service" . ($svcCount > 1 ? 's' : '');
    }
    if ($foodCount > 0) {
        $parts[] = "{$foodCount} food order" . ($foodCount > 1 ? 's' : '');
    }
    if ($checkinCount > 0) {
        $parts[] = "{$checkinCount} check-in" . ($checkinCount > 1 ? 's' : '');
    }

    $message = 'Payment collected: ' . implode(', ', $parts) . ' marked as paid.';
    if ($checkinWarning) {
        $message .= ' Includes a check-in charge whose guest session has already ended (token archived).';
    }

    log_event($conn, 'payment_collected', 'collection', $collectionId, 'success', 'Room ' . ($roomNumber ?? '?') . ' — ' . $message, ['after' => ['total' => round($totalAmount, 2), 'received' => $amountReceived, 'change' => $changeAmount]]);

    echo json_encode([
        'success' => true,
        'ext' => $extCount,
        'svc' => $svcCount,
        'food' => $foodCount,
        'checkin' => $checkinCount,
        'total_amount' => round($totalAmount, 2),
        'amount_received' => $amountReceived,
        'change_amount' => $changeAmount,
        'collection_id' => $collectionId,
        'message' => $message,
    ]);
} catch (Exception $e) {
    $conn->rollback();
    error_log('process_mark_all_paid.php failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Transaction failed. Please try again.']);
}
