<?php
// ============================================================
//  admin/fetch_available_rooms.php
//  Returns available rooms for a booking's room type and dates.
//
//  GET params:
//    booking_id — int
// ============================================================
session_start();
header('Content-Type: application/json');

include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../admin/auth_admin.php');

$bookingId = (int)($_GET['booking_id'] ?? 0);
if ($bookingId < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID.']);
    exit();
}

$stmt = $conn->prepare("SELECT room_type, check_in, check_out FROM bookings WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $bookingId);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'Booking not found.']);
    exit();
}

$roomType = $booking['room_type'];

// Get booked/checked-in rooms for the date range
// Check both daily and hourly bookings
$bookedRooms = [];

$bookedStmt = $conn->prepare(
    "SELECT room_number FROM bookings
     WHERE status = 'approved' AND room_number IS NOT NULL
       AND (
           -- Daily bookings: date range overlap
           (booking_type = 'daily' AND check_in < ? AND check_out > ?)
           OR
           -- Hourly bookings: check for time overlap
           (booking_type = 'hourly' AND check_in < ? AND check_out > ?)
       )"
);
$bookedStmt->bind_param('ssss', $booking['check_out'], $booking['check_in'], $booking['check_out'], $booking['check_in']);
$bookedStmt->execute();
while ($row = $bookedStmt->get_result()->fetch_assoc()) {
    $bookedRooms[] = $row['room_number'];
}
$bookedStmt->close();

$checkedInStmt = $conn->prepare(
    "SELECT room_no FROM customerstbl
     WHERE status = 'checked-in' AND room_no != ''
       AND DATE(date_from) <= ? AND DATE(date_to) >= ?"
);
$checkedInStmt->bind_param('ss', $booking['check_out'], $booking['check_in']);
$checkedInStmt->execute();
while ($row = $checkedInStmt->get_result()->fetch_assoc()) {
    $bookedRooms[] = $row['room_no'];
}
$checkedInStmt->close();

$bookedRooms = array_unique($bookedRooms);

$rooms = [];

if ($roomType === 'Without Garage') {
    $mpStmt = $conn->prepare("SELECT max_persons FROM roomstbl WHERE rooms = 'Without Garage' LIMIT 1");
    $mpStmt->execute();
    $mpRow = $mpStmt->get_result()->fetch_assoc();
    $mpStmt->close();
    $wgMaxPersons = $mpRow ? $mpRow['max_persons'] : null;

    // Add rooms 4-16 that aren't booked
    for ($i = 4; $i <= 16; $i++) {
        $roomNum = (string)$i;
        if (!in_array($roomNum, $bookedRooms)) {
            $rooms[] = [
                'room_number'   => $roomNum,
                'room_type'     => 'Without Garage',
                'rate_per_hour' => null,
                'max_persons'   => $wgMaxPersons,
            ];
        }
    }
} else {
    // For other room types, get from roomstbl
    $typeStmt = $conn->prepare(
        "SELECT room_number, rooms AS room_type, rate_per_hour, max_persons
         FROM roomstbl
         WHERE rooms = ? AND room_number IS NOT NULL
         ORDER BY CAST(room_number AS UNSIGNED)"
    );
    $typeStmt->bind_param('s', $roomType);
    $typeStmt->execute();
    $typeRooms = $typeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $typeStmt->close();
    
    foreach ($typeRooms as $room) {
        if (!in_array($room['room_number'], $bookedRooms)) {
            $rooms[] = $room;
        }
    }
}

echo json_encode(['success' => true, 'rooms' => $rooms]);