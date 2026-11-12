<?php
// ============================================================
//  update_room_status.php — Room Availability Board: Update
//  Accepts POST: room_number, status, maintenance_note
//  Updates maintenance_status + maintenance_note in roomstbl.
// ============================================================
require_once __DIR__ . '/../dbconnect.php';

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../csrf.php';
require_once __DIR__ . '/../includes/log_action.php';

// CSRF: the room-status write is a mutating action and must carry a token.
// (JSON 403 rather than the helper's plain-text exit, so the page's fetch
// handlers can still parse the response.)
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

header('Content-Type: application/json');

if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$roomNumber      = trim($_POST['room_number']      ?? '');
$newStatus       = trim($_POST['status']           ?? '');
$maintenanceNote = trim($_POST['maintenance_note'] ?? '');

// Validate
if ($roomNumber === '') {
    echo json_encode(['success' => false, 'message' => 'Room number is required.']);
    exit;
}

$allowed = ['available', 'occupied', 'booked', 'maintenance', 'cancelled', 'force_release'];
if (!in_array($newStatus, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status value.']);
    exit;
}

// Handle cancellation: update the active approved booking for this room to 'cancelled'
if ($newStatus === 'cancelled') {
    $cancelStmt = $conn->prepare(
        "UPDATE bookings SET status = 'cancelled', updated_at = NOW()
         WHERE room_number = ? AND status = 'approved'
         ORDER BY check_in DESC LIMIT 1"
    );
    $cancelStmt->bind_param('s', $roomNumber);
    $ok = $cancelStmt->execute();
    $affected = $cancelStmt->affected_rows;
    $cancelStmt->close();
    if ($ok && $affected > 0) {
        log_event($conn, 'room_booking_cancelled', 'room', null, 'success', 'Room ' . $roomNumber . ' booking cancelled from room board');
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No approved booking found to cancel.']);
    }
    exit;
}

// Handle force-release: end the active stay and revoke its portal token(s) so
// the room genuinely becomes available again. Manual equivalent of the
// automatic release that happens on token expiry/revoke, for guests who left
// without using the portal or stale rows holding the room occupied.
if ($newStatus === 'force_release') {
    $conn->begin_transaction();
    try {
        // 1. Check out every stay still marked checked-in in this room.
        $custStmt = $conn->prepare(
            "UPDATE customerstbl
             SET    status         = 'checked-out',
                    checked_out_at = COALESCE(checked_out_at, NOW())
             WHERE  room_no = ?
               AND  status  = 'checked-in'"
        );
        $custStmt->bind_param('s', $roomNumber);
        $custStmt->execute();
        $releasedStays = $custStmt->affected_rows;
        $custStmt->close();

        // 2. Archive then delete any live portal token for the room.
        $tokStmt = $conn->prepare(
            "SELECT id, token, room_number, customer_id,
                    hours_of_stay, created_at, expires_at, created_by
             FROM   guest_tokens
             WHERE  room_number = ? AND expires_at > NOW()"
        );
        $tokStmt->bind_param('s', $roomNumber);
        $tokStmt->execute();
        $liveTokens = $tokStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $tokStmt->close();

        $histStmt = $conn->prepare(
            "INSERT INTO guest_tokens_history
                 (original_id, token, room_number, customer_id,
                  hours_of_stay, issued_at, expires_at, moved_at, reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'superseded', ?)"
        );
        $delStmt = $conn->prepare("DELETE FROM guest_tokens WHERE id = ?");
        foreach ($liveTokens as $tokRow) {
            $histStmt->bind_param(
                'issiisss',
                $tokRow['id'],
                $tokRow['token'],
                $tokRow['room_number'],
                $tokRow['customer_id'],
                $tokRow['hours_of_stay'],
                $tokRow['created_at'],
                $tokRow['expires_at'],
                $tokRow['created_by']
            );
            $histStmt->execute();
            $delStmt->bind_param('i', $tokRow['id']);
            $delStmt->execute();
        }
        $histStmt->close();
        $delStmt->close();
        $revokedTokens = count($liveTokens);

        // 3. The room is available now — make sure no maintenance flag blocks it.
        $clearStmt = $conn->prepare(
            "UPDATE roomstbl SET maintenance_status = 0, maintenance_note = NULL WHERE room_number = ?"
        );
        $clearStmt->bind_param('s', $roomNumber);
        $clearStmt->execute();
        $clearStmt->close();

        $conn->commit();

        log_event(
            $conn,
            'room_force_released',
            'room',
            null,
            'success',
            'Room ' . $roomNumber . ' force-released (' . $releasedStays . ' stay(s) checked out, ' . $revokedTokens . ' token(s) revoked)'
        );
        echo json_encode([
            'success' => true,
            'message' => 'Room ' . $roomNumber . ' released — ' . $releasedStays
                       . ' stay(s) checked out, ' . $revokedTokens . ' token(s) revoked.',
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[update_room_status] force_release failed for room ' . $roomNumber . ': ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Force release failed. Please try again.']);
    }
    exit;
}

// Only maintenance_status and maintenance_note are managed here.
// The other statuses (occupied, booked, available) are derived from live data
// in get_room_board.php — this endpoint only controls the maintenance flag.
$isMaint = ($newStatus === 'maintenance') ? 1 : 0;
$noteVal = $isMaint ? $maintenanceNote : null;

// Check the room exists in roomstbl
$checkStmt = $conn->prepare("SELECT id FROM roomstbl WHERE room_number = ? LIMIT 1");
$checkStmt->bind_param('s', $roomNumber);
$checkStmt->execute();
$checkRow = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if (!$checkRow) {
    echo json_encode(['success' => false, 'message' => 'Room not found.']);
    exit;
}

$updStmt = $conn->prepare(
    "UPDATE roomstbl
     SET maintenance_status = ?, maintenance_note = ?
     WHERE room_number = ?"
);
$updStmt->bind_param('iss', $isMaint, $noteVal, $roomNumber);
$ok = $updStmt->execute();
$updStmt->close();

if ($ok) {
    log_event($conn, 'room_status_updated', 'room', null, 'success', 'Room ' . $roomNumber . ' → ' . $newStatus . ($isMaint && $noteVal ? ' (' . $noteVal . ')' : ''));
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database update failed.']);
}
