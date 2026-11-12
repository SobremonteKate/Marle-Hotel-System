<?php
// ============================================================
//  homepage/check_availability.php
//  Checks room availability for given dates and room type.
//
//  GET params:
//    check_in  — date (Y-m-d)
//    check_out — date (Y-m-d)
//    room_type — string (optional, filters by type)
//    persons   — int    (optional, hides rooms that cannot sleep the party)
// ============================================================
header('Content-Type: application/json');
include('../dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json');
// The hotel's clock, for the past-date gate below (see includes/server_clock.php):
// it has to agree with the min dates the booking form shows and with the same gate
// in submit_booking.php, not with this server's PHP timezone.
require_once __DIR__ . '/../includes/server_clock.php';

$checkIn  = trim($_GET['check_in']  ?? '');
$checkOut = trim($_GET['check_out'] ?? '');
$roomType = trim($_GET['room_type'] ?? '');
// Optional: how many are travelling. 0 means "do not filter", so the desk still
// works if an older page (or a bookmark) asks without it.
$persons  = (int)($_GET['persons'] ?? 0);
if ($persons < 0) {
    $persons = 0;
}

if (!$checkIn || !$checkOut) {
    echo json_encode(['success' => false, 'message' => 'Check-in and check-out dates are required.']);
    exit();
}

if (strtotime($checkIn) === false || strtotime($checkOut) === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
    exit();
}

if (strtotime($checkOut) <= strtotime($checkIn)) {
    echo json_encode(['success' => false, 'message' => 'Check-out must be after check-in.']);
    exit();
}

// The hotel's calendar day, not PHP's: on this box PHP runs Europe/Berlin while MySQL
// runs UTC+8, so between midnight and 06:00 a PHP-date gate compared against
// yesterday's date and let a search through for a day the hotel had already finished
// with. marle_hotel_date() asks the database (CURDATE()) — the same clock the statuses
// this endpoint returns are built with.
$today = marle_hotel_date(0);
if ($checkIn < $today) {
    echo json_encode(['success' => false, 'message' => 'Check-in date cannot be in the past.']);
    exit();
}

// Get all rooms (optionally filtered by type)
$roomSql = "SELECT id, rooms AS room_type, room_number, rate_per_hour, max_persons, maintenance_status
            FROM roomstbl WHERE room_number IS NOT NULL";
$roomParams = [];
$roomTypes  = '';

if ($roomType !== '') {
    $roomSql .= " AND rooms = ?";
    $roomParams[] = $roomType;
    $roomTypes   .= 's';
}

$roomSql .= " ORDER BY rooms, CAST(room_number AS UNSIGNED)";

$stmt = $conn->prepare($roomSql);
if ($roomTypes) {
    $stmt->bind_param($roomTypes, ...$roomParams);
}
$stmt->execute();
$allRooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Add missing rooms 4-16 as "Without Garage" if required
if ($roomType === '' || $roomType === 'Without Garage') {
    // Fetch ALL room numbers from DB (not just the type-filtered set)
    // to avoid adding synthetic "Without Garage" entries for rooms that
    // already exist under a different type (e.g. rooms 8–9 are "With Garage").
    $allRoomNumbersStmt = $conn->prepare(
        "SELECT room_number FROM roomstbl WHERE room_number IS NOT NULL"
    );
    $allRoomNumbersStmt->execute();
    $allRoomNumbersResult = $allRoomNumbersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $allRoomNumbersStmt->close();
    $existingRoomNumbers = array_map(fn($r) => $r['room_number'], $allRoomNumbersResult);

    // Get Without Garage max_persons for synthetic rooms
    $wgMaxPersons = 2;
    foreach ($allRooms as $r) {
        if ($r['room_type'] === 'Without Garage') {
            $wgMaxPersons = (int)$r['max_persons'];
            break;
        }
    }

    for ($i = 4; $i <= 16; $i++) {
        if (!in_array((string)$i, $existingRoomNumbers)) {
            $allRooms[] = [
                'id'             => null,
                'room_type'      => 'Without Garage',
                'room_number'    => (string)$i,
                'rate_per_hour'  => null,
                'max_persons'    => $wgMaxPersons,
            ];
        }
    }
    usort($allRooms, fn($a, $b) => (int)$a['room_number'] - (int)$b['room_number']);
}

// Get rooms that are booked (approved bookings overlapping the dates)
// This includes both daily bookings (date range overlap) and hourly bookings (exact date match)
$bookedStmt = $conn->prepare(
    "SELECT DISTINCT room_number FROM bookings
     WHERE status = 'approved'
       AND room_number IS NOT NULL
        AND (
            -- Check for time overlap on both booking types
            (booking_type = 'daily' AND check_in < ? AND check_out > ?)
            OR
            (booking_type = 'hourly' AND check_in < ? AND check_out > ?)
        )"
);
$bookedStmt->bind_param('ssss', $checkOut, $checkIn, $checkOut, $checkIn);
$bookedStmt->execute();
$bookedResult = $bookedStmt->get_result();
$bookedRooms = [];
while ($row = $bookedResult->fetch_assoc()) {
    $bookedRooms[] = $row['room_number'];
}
$bookedStmt->close();

// Also check currently checked-in guests whose stay overlaps
$checkedInStmt = $conn->prepare(
    "SELECT room_no FROM customerstbl
     WHERE status = 'checked-in'
       AND room_no != ''
       AND DATE(date_from) <= ? AND DATE(date_to) >= ?"
);
$checkedInStmt->bind_param('ss', $checkOut, $checkIn);
$checkedInStmt->execute();
$checkedInResult = $checkedInStmt->get_result();
while ($row = $checkedInResult->fetch_assoc()) {
    $bookedRooms[] = $row['room_no'];
}
$checkedInStmt->close();

$bookedRooms = array_unique($bookedRooms);

// Build availability result grouped by room type
$available = [];
$unavailable = [];
$tooSmall = [];

foreach ($allRooms as $room) {
    $info = [
        'room_number'   => $room['room_number'],
        'room_type'     => $room['room_type'],
        'rate_per_hour' => (float)$room['rate_per_hour'],
        'max_persons'   => (int)$room['max_persons'],
    ];
    // Capacity is decided before anything else: a room that cannot sleep the
    // party is no use to them whether it is free, booked or under repair, and
    // offering it would only waste a step. Reported separately so the page can
    // say why the list is shorter than the house.
    if ($persons > 0 && $info['max_persons'] > 0 && $info['max_persons'] < $persons) {
        $tooSmall[] = $info;
        continue;
    }
    if (!empty($room['maintenance_status'])) {
        $info['status'] = 'Maintenance';
        $info['next_available'] = null;
        $unavailable[] = $info;
    } elseif (in_array($room['room_number'], $bookedRooms)) {
        // Find the earliest checkout for this room to compute next_available
        $checkoutDate = null;

        // Check customerstbl first (checked-in guests)
        $coStmt = $conn->prepare(
            "SELECT date_to FROM customerstbl
             WHERE status = 'checked-in'
               AND room_no = ?
             ORDER BY date_to DESC
             LIMIT 1"
        );
        $coStmt->bind_param('s', $room['room_number']);
        $coStmt->execute();
        $coRow = $coStmt->get_result()->fetch_assoc();
        $coStmt->close();
        if ($coRow) $checkoutDate = $coRow['date_to'];

        // Fall back to bookings table (check both daily and hourly)
        if (!$checkoutDate) {
            $boStmt = $conn->prepare(
                "SELECT check_out FROM bookings
                 WHERE status = 'approved'
                   AND room_number = ?
                   AND (
                       (booking_type = 'daily' AND check_in < ? AND check_out > ?)
                       OR (booking_type = 'hourly' AND check_in < ? AND check_out > ?)
                   )
                 ORDER BY check_out ASC
                 LIMIT 1"
            );
            $boStmt->bind_param('sssss', $room['room_number'], $checkOut, $checkIn, $checkOut, $checkIn);
            $boStmt->execute();
            $boRow = $boStmt->get_result()->fetch_assoc();
            $boStmt->close();
            if ($boRow) $checkoutDate = $boRow['check_out'];
        }

        if ($checkoutDate) {
            $buffer        = (int)($room['buffer_minutes'] ?? 30);
            $dateOnly   = substr($checkoutDate, 0, 10);
            $checkoutTs = strtotime($dateOnly . ' 12:00:00');
            $nextTs        = $checkoutTs + ($buffer * 60);
            $info['next_available'] = date('M j, Y g:i A', $nextTs);
        } else {
            $info['next_available'] = null;
        }

        $unavailable[] = $info;
    } else {
        $available[] = $info;
    }
}

echo json_encode([
    'success'     => true,
    'available'   => $available,
    'unavailable' => $unavailable,
    'too_small'   => $tooSmall,
    'check_in'    => $checkIn,
    'check_out'   => $checkOut,
    'persons'     => $persons,
    'note'        => 'next_available includes cleaning buffer',
]);