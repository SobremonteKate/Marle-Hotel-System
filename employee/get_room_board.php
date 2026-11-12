<?php
// ============================================================
//  get_room_board.php — Room Availability Board: JSON endpoint
//  Returns JSON array of all rooms with live status info.
// ============================================================
require_once __DIR__ . '/../dbconnect.php';

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

if (!$conn) {
    http_response_code(500);
    echo json_encode(['message' => 'Database connection failed.']);
    exit;
}

// Auto-release stays whose token expired (or was revoked) before reporting
// occupancy, so the board never shows a stale 'occupied' room.
require_once __DIR__ . '/includes/token_sweep.php';
run_token_sweep($conn);

$rooms = [];

// ── 1. Fetch all rooms from roomstbl ─────────────────────────────────────────
$roomsStmt = $conn->prepare(
    "SELECT room_number, rooms, maintenance_status, maintenance_note
     FROM roomstbl
     ORDER BY CAST(room_number AS UNSIGNED) ASC"
);
$roomsStmt->execute();
$roomsResult = $roomsStmt->get_result();
$roomMap = [];
while ($row = $roomsResult->fetch_assoc()) {
    $roomMap[$row['room_number']] = $row;
}
$roomsStmt->close();

// ── 2. Build room list (rooms 1–16) ──────────────────────────────────────────
for ($rn = 1; $rn <= 16; $rn++) {
    $rnStr = (string)$rn;

    if (isset($roomMap[$rnStr])) {
        $roomType        = $roomMap[$rnStr]['rooms'];
        $isMaint         = (bool)($roomMap[$rnStr]['maintenance_status'] ?? 0);
        $maintNote       = $roomMap[$rnStr]['maintenance_note'] ?? null;
    } else {
        // Fallback: room not in roomstbl yet
        $roomType  = 'Without Garage';
        $isMaint   = false;
        $maintNote = null;
    }

    // ── Priority 1: maintenance ───────────────────────────────────────────────
    if ($isMaint) {
        $rooms[] = [
            'room_number'      => $rnStr,
            'room_type'        => $roomType,
            'status'           => 'maintenance',
            'available_at'     => null,
            'booking_info'     => null,
            'cancelled_info'   => null,
            'maintenance_note' => $maintNote,
        ];
        continue;
    }

    // ── Priority 2: occupied — active customerstbl row ────────────────────────
    $custStmt = $conn->prepare(
        "SELECT id, date_from, date_to,
                ROUND(TIMESTAMPDIFF(MINUTE, date_from, date_to) / 60, 1) AS hours_staying
         FROM customerstbl
         WHERE room_no = ?
           AND status  = 'checked-in'
           AND date_to > NOW()
         ORDER BY date_to DESC
         LIMIT 1"
    );
    $custStmt->bind_param('s', $rnStr);
    $custStmt->execute();
    $custRow = $custStmt->get_result()->fetch_assoc();
    $custStmt->close();

    // ── Priority 2b: occupied — active guest_tokens row ───────────────────────
    $tokenStmt = $conn->prepare(
        "SELECT expires_at
         FROM guest_tokens
         WHERE room_number = ?
           AND expires_at  > NOW()
         ORDER BY expires_at DESC
         LIMIT 1"
    );
    $tokenStmt->bind_param('s', $rnStr);
    $tokenStmt->execute();
    $tokenRow = $tokenStmt->get_result()->fetch_assoc();
    $tokenStmt->close();

   if ($custRow || $tokenRow) {
        // Pick the later of the two expiry times if both exist
        $availAt = null;
        if ($custRow && $tokenRow) {
            $availAt = ($custRow['date_to'] >= $tokenRow['expires_at'])
                ? $custRow['date_to']
                : $tokenRow['expires_at'];
        } elseif ($custRow) {
            $availAt = $custRow['date_to'];
        } else {
            $availAt = $tokenRow['expires_at'];
        }

        $rooms[] = [
            'room_number'      => $rnStr,
            'room_type'        => $roomType,
            'status'           => 'occupied',
            'available_at'     => $availAt,
            'hours_staying'    => $custRow['hours_staying'] ?? null,
            'booking_info'     => null,
            'cancelled_info'   => null,
            'maintenance_note' => null,
        ];
        continue;
    }

    // ── Priority 3: booked — approved booking ─────────────────────────────────
    $bookStmt = $conn->prepare(
        "SELECT id, guest_name, booking_type, check_in, check_out,
                check_in_time, check_out_time
         FROM bookings
         WHERE status      = 'approved'
           AND room_number = ?
           AND (
               (booking_type != 'hourly'
                AND check_in  <= CURDATE()
                AND check_out >  CURDATE())
             OR
               (booking_type = 'hourly'
                AND hourly_released = 0
                AND check_in = CURDATE()
                AND check_in_time  <= TIME(NOW())
                AND check_out_time >  TIME(NOW()))
           )
         ORDER BY check_in ASC
         LIMIT 1"
    );
    $bookStmt->bind_param('s', $rnStr);
    $bookStmt->execute();
    $bookRow = $bookStmt->get_result()->fetch_assoc();
    $bookStmt->close();

    if ($bookRow) {
        $bookInfo = [
            'id'             => $bookRow['id'],
            'guest_name'     => $bookRow['guest_name'],
            'booking_type'   => $bookRow['booking_type'],
            'check_in'       => $bookRow['check_in'],
            'check_out'      => $bookRow['check_out'],
            'check_in_time'  => $bookRow['check_in_time'],
            'check_out_time' => $bookRow['check_out_time'],
        ];

        $rooms[] = [
            'room_number'      => $rnStr,
            'room_type'        => $roomType,
            'status'           => 'booked',
            'available_at'     => null,
            'booking_info'     => $bookInfo,
            'cancelled_info'   => null,
            'maintenance_note' => null,
        ];
        continue;
    }

    // Priority 4: cancelled - current cancelled booking
    $cancelStmt = $conn->prepare(
        "SELECT id, guest_name, booking_type, check_in, check_out,
                check_in_time, check_out_time
         FROM bookings
         WHERE status      = 'cancelled'
           AND room_number = ?
           AND (
               (booking_type != 'hourly'
                AND check_in  <= CURDATE()
                AND check_out >  CURDATE())
             OR
               (booking_type = 'hourly'
                AND check_in = CURDATE()
                AND check_in_time  <= TIME(NOW())
                AND check_out_time >  TIME(NOW()))
           )
         ORDER BY updated_at DESC, check_in DESC
         LIMIT 1"
    );
    $cancelStmt->bind_param('s', $rnStr);
    $cancelStmt->execute();
    $cancelRow = $cancelStmt->get_result()->fetch_assoc();
    $cancelStmt->close();

    if ($cancelRow) {
        $cancelInfo = [
            'id'             => $cancelRow['id'],
            'guest_name'     => $cancelRow['guest_name'],
            'booking_type'   => $cancelRow['booking_type'],
            'check_in'       => $cancelRow['check_in'],
            'check_out'      => $cancelRow['check_out'],
            'check_in_time'  => $cancelRow['check_in_time'],
            'check_out_time' => $cancelRow['check_out_time'],
        ];

        $rooms[] = [
            'room_number'      => $rnStr,
            'room_type'        => $roomType,
            'status'           => 'cancelled',
            'available_at'     => null,
            'booking_info'     => null,
            'cancelled_info'   => $cancelInfo,
            'maintenance_note' => null,
        ];
        continue;
    }

    // Priority 5: available
    $rooms[] = [
        'room_number'      => $rnStr,
        'room_type'        => $roomType,
        'status'           => 'available',
        'available_at'     => null,
        'booking_info'     => null,
        'cancelled_info'   => null,
        'maintenance_note' => null,
    ];
}

echo json_encode($rooms, JSON_UNESCAPED_UNICODE);
