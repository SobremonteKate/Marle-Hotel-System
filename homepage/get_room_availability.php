<?php
// ============================================================
//  homepage/get_room_availability.php
//
//  MODE 1 (default): badge poll for home.php hourly badges
//  Response: { "success": true, "rooms": { "Room Type": { "available": bool, "available_at": string|null } } }
//
//  MODE 2 (?mode=full): full per-room availability for customerRC.php table
//  Response: { "success": true, "rooms": [ { room_number, room_type, status, occupied_until, next_available } ] }
// ============================================================
header('Content-Type: application/json');
include('../dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json');
// The hotel's date, for the two "today" decisions below (see includes/server_clock.php).
// The rows this file reasons about are selected with the database's CURDATE(), so the
// date they are compared against has to come from the same clock — PHP's timezone runs
// six hours behind it on this box.
require_once __DIR__ . '/../includes/server_clock.php';
$hotelToday = marle_hotel_date(0);

$mode = trim($_GET['mode'] ?? '');

// ============================================================
//  MODE 1 — original badge poll (unchanged)
// ============================================================
if ($mode !== 'full') {
    $roomTypes = ['Without Garage', 'With Garage', 'Suite', 'Family'];

    $stmt = $conn->prepare(
        "SELECT room_type,
                check_out_time,
                (TIME(NOW()) < check_out_time) AS still_active
         FROM   bookings
         WHERE  booking_type    = 'hourly'
           AND  status          = 'approved'
           AND  hourly_released = 0
           AND  check_in        = CURDATE()
         ORDER BY check_out_time ASC"
    );
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $active = [];
    foreach ($rows as $row) {
        $rt = $row['room_type'];
        if (!isset($active[$rt])) {
            $active[$rt] = $row;
        }
    }

    $result = [];
    foreach ($roomTypes as $rt) {
        if (!isset($active[$rt])) {
            $result[$rt] = ['available' => true, 'available_at' => null];
        } else {
            $entry      = $active[$rt];
            $checkoutTs = strtotime($entry['check_out_time']);
            if ((int)$entry['still_active'] === 1) {
                $result[$rt] = [
                    'available'    => false,
                    'available_at' => date('g:i A', $checkoutTs),
                ];
            } else {
                $result[$rt] = [
                    'available'    => false,
                    'available_at' => 'Available Now',
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'rooms' => $result]);
    exit();
}

// ============================================================
//  MODE 2 — full per-room availability for customerRC table
// ============================================================

// ── Step A: Get all rooms + their buffer_minutes ──────────────────────────────
// Rooms 4–16 are "Without Garage" and may not all be in roomstbl,
// so we build the master list the same way check_availability.php does.

$allRooms = []; // room_number (string) => [ room_type, buffer_minutes ]

$roomStmt = $conn->prepare(
    "SELECT room_number, rooms AS room_type, max_persons, maintenance_status
     FROM roomstbl
     WHERE room_number IS NOT NULL
     ORDER BY CAST(room_number AS UNSIGNED)"
);
$roomStmt->execute();
$dbRooms = $roomStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$roomStmt->close();

foreach ($dbRooms as $r) {
    $allRooms[$r['room_number']] = [
        'room_type'          => $r['room_type'],
        'max_persons'        => (int)$r['max_persons'],
        'maintenance_status' => (int)($r['maintenance_status'] ?? 0),
    ];
}

// Fill in Without Garage rooms 4–16 that aren't in roomstbl
// Get max_persons for Without Garage from roomstbl
$wgMaxPersons = 2; // fallback default
$wgStmt = $conn->prepare(
    "SELECT max_persons FROM roomstbl
     WHERE rooms = 'Without Garage'
     LIMIT 1"
);
$wgStmt->execute();
$wgRow = $wgStmt->get_result()->fetch_assoc();
$wgStmt->close();
if ($wgRow) {
    $wgMaxPersons = (int)$wgRow['max_persons'];
}

for ($i = 4; $i <= 16; $i++) {
    $rn = (string)$i;
    if (!isset($allRooms[$rn])) {
        $allRooms[$rn] = [
            'room_type'   => 'Without Garage',
            'max_persons' => $wgMaxPersons,
        ];
    }
}

// Sort by room number numerically
uksort($allRooms, fn($a, $b) => (int)$a - (int)$b);

// ── Step B: Get checked-in guests from customerstbl ───────────────────────────
// room_no, date_to (checkout date for nightly)
$checkedIn = []; // room_no => date_to
$ciStmt = $conn->prepare(
    "SELECT room_no, date_to
     FROM customerstbl
     WHERE status = 'checked-in'
       AND room_no != ''"
);
$ciStmt->execute();
$ciRows = $ciStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$ciStmt->close();
foreach ($ciRows as $row) {
    $checkedIn[$row['room_no']] = $row['date_to'];
}

// ── Step C: Get active hourly bookings from bookings ─────────────────────────
// Only today's unreleased hourly bookings that are still active
$hourlyActive = []; // room_number => check_out_time (TIME string)
$hStmt = $conn->prepare(
    "SELECT room_number, check_out_time
     FROM bookings
     WHERE booking_type    = 'hourly'
       AND status          = 'approved'
       AND hourly_released = 0
       AND check_in        = CURDATE()
       AND TIME(NOW())     < check_out_time
       AND room_number IS NOT NULL
     ORDER BY check_out_time ASC"
);
$hStmt->execute();
$hRows = $hStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$hStmt->close();
foreach ($hRows as $row) {
    // Keep earliest checkout per room (already ordered ASC)
    if (!isset($hourlyActive[$row['room_number']])) {
        $hourlyActive[$row['room_number']] = $row['check_out_time'];
    }
}

// ── Step D: Get approved nightly bookings (today and future) ──────────────────
// Nightly bookings block the room for the entire day (from check_in to check_out)
$nightlyBooked = []; // room_number => ['check_in' => date, 'check_out' => date]
$nbStmt = $conn->prepare(
    "SELECT room_number, check_in, check_out
     FROM bookings
     WHERE booking_type = 'daily'
       AND status       IN ('pending', 'approved')
       AND check_out    >= CURDATE()
       AND room_number IS NOT NULL
     ORDER BY check_in ASC"
);
$nbStmt->execute();
$nbRows = $nbStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$nbStmt->close();
foreach ($nbRows as $row) {
    if (!isset($nightlyBooked[$row['room_number']])) {
        $nightlyBooked[$row['room_number']] = [
            'check_in'  => $row['check_in'],
            'check_out' => $row['check_out']
        ];
    }
}

// ── Step E: Get active guest_tokens ──────────────────────────────────────────
// A token is active when expires_at is still in the future
$activeTokenRooms = []; // room_number => true
$gtStmt = $conn->prepare(
    "SELECT DISTINCT room_number
     FROM guest_tokens
     WHERE expires_at > NOW()
       AND room_number IS NOT NULL
       AND room_number != ''"
);
$gtStmt->execute();
$gtResult = $gtStmt->get_result();
if ($gtResult) {
    while ($row = $gtResult->fetch_assoc()) {
        $activeTokenRooms[$row['room_number']] = true;
    }
}
$gtStmt->close();

// ── Step F: Build result ──────────────────────────────────────────────────────
$result = [];

$bufferMinutes = 30; // default buffer time in minutes

foreach ($allRooms as $roomNumber => $info) {
    $roomType  = $info['room_type'];
    $status    = 'Available';
    $occupiedUntil  = null;
    $nextAvailable  = null;

    // ── Priority 0: maintenance ──────────────────────────────────────────
    if (!empty($info['maintenance_status'])) {
        $status = 'Maintenance';
    } elseif (isset($checkedIn[$roomNumber])) {
    $status       = 'Checked In';
    $checkoutDate = $checkedIn[$roomNumber]; // may be DATETIME or DATE
    // Normalize: strip time component then use noon as checkout estimate
    $dateOnly    = substr($checkoutDate, 0, 10);           // "YYYY-MM-DD"
    $checkoutTs  = strtotime($dateOnly . ' 12:00:00');
    $nextTs      = $checkoutTs + ($bufferMinutes * 60);
    $occupiedUntil = date('M j, Y', $checkoutTs);
    $nextAvailable = date('M j, Y g:i A', $nextTs);

    } elseif (isset($hourlyActive[$roomNumber])) {
        // Active hourly booking
        $checkoutTime  = $hourlyActive[$roomNumber];
        // The booking was selected on the hotel's today (CURDATE()), so its checkout
        // moment is the hotel's today at that time — not PHP's date, which is a day
        // behind between midnight and 06:00 and would put "next available" in the past.
        $checkoutTs    = strtotime($hotelToday . ' ' . $checkoutTime);
        $nextTs        = $checkoutTs + ($bufferMinutes * 60);
        $status        = 'Occupied until ' . date('g:i A', $checkoutTs);
        $occupiedUntil = date('g:i A', $checkoutTs);
        $nextAvailable = date('g:i A', $nextTs);

    } elseif (isset($nightlyBooked[$roomNumber])) {
        // Nightly booking (today or future) - room is blocked for entire stay
        $bookingInfo   = $nightlyBooked[$roomNumber];
        $checkInDate   = $bookingInfo['check_in'];
        $checkOutDate  = $bookingInfo['check_out'];
        
        // Check if this is a today booking or future booking
        $todayStr = $hotelToday;
        if ($checkInDate <= $todayStr && $checkOutDate >= $todayStr) {
            // Booking includes today - room is blocked for entire day
            $status = 'Booked';
            $checkoutTs = strtotime($checkOutDate . ' 12:00:00');
            $nextTs = $checkoutTs + ($bufferMinutes * 60);
            $nextAvailable = date('M j, Y g:i A', $nextTs);
        } else {
            // Future booking
            $status = 'Booked';
            $checkoutTs = strtotime($checkOutDate . ' 12:00:00');
            $nextTs = $checkoutTs + ($bufferMinutes * 60);
            $nextAvailable = date('M j, Y g:i A', $nextTs);
        }
    }

    // If guest_token is active and room isn't already marked, confirm Checked In
    if (isset($activeTokenRooms[$roomNumber]) && $status === 'Available') {
        $status = 'Checked In';
    }

    $result[] = [
        'room_number'    => $roomNumber,
        'room_type'      => $roomType,
        'max_persons'    => $info['max_persons'] ?? null,
        'status'         => $status,
        'occupied_until' => $occupiedUntil,
        'next_available' => $nextAvailable,
    ];
}

echo json_encode(['success' => true, 'rooms' => $result]);