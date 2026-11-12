<?php
// ============================================================
//  superadmin/walkin_rooms.php
//  Which rooms are free for a walk-in stay, and what it costs.
//
//  GET: room_type, check_in, check_out, booking_type (daily|hourly),
//       check_in_time, check_out_time
//
//  Read-only: answers the calendar's Walk-in booking form. The same rules run
//  again when the booking is saved (includes/walkin_booking.php), so this is
//  only a convenience — it can never be the thing that decides.
// ============================================================
header('Content-Type: application/json');
include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');

include('../superadmin/auth_admin.php');
require_once __DIR__ . '/../includes/walkin_booking.php';
require_once __DIR__ . '/../includes/server_clock.php';

$roomType = trim((string)($_GET['room_type'] ?? ''));
$checkIn  = trim((string)($_GET['check_in'] ?? ''));
$checkOut = trim((string)($_GET['check_out'] ?? ''));
$stayType = ((string)($_GET['booking_type'] ?? 'daily')) === 'hourly' ? 'hourly' : 'daily';
$inTime   = trim((string)($_GET['check_in_time'] ?? '14:00'));
$outTime  = trim((string)($_GET['check_out_time'] ?? '12:00'));

$isDate = static function (string $value): bool {
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
if (!$isDate($checkIn) || ($stayType === 'daily' && !$isDate($checkOut))) {
    echo json_encode(['success' => false, 'message' => 'Choose valid dates first.']);
    exit();
}
if ($stayType === 'daily' && $checkOut <= $checkIn) {
    echo json_encode(['success' => false, 'message' => 'Departure has to be after arrival.']);
    exit();
}
if ($stayType === 'hourly') {
    $checkOut = $checkIn;
    if (strlen($inTime) === 5)  $inTime  .= ':00';
    if (strlen($outTime) === 5) $outTime .= ':00';
    if ($inTime >= $outTime) {
        echo json_encode(['success' => false, 'message' => 'Departure has to be later than arrival.']);
        exit();
    }
}

if (!in_array($roomType, marle_walkin_room_types($conn), true)) {
    echo json_encode(['success' => true, 'rooms' => [], 'quote' => null]);
    exit();
}

$hours = $stayType === 'hourly'
    ? (int)ceil((strtotime($checkOut . ' ' . $outTime) - strtotime($checkIn . ' ' . $inTime)) / 3600)
    : 0;

$rooms = marle_walkin_available_rooms($conn, $roomType, $checkIn, $checkOut, $stayType, $inTime, $outTime);
$quote = marle_walkin_quote($conn, [
    'room_type'       => $roomType,
    'booking_type'    => $stayType,
    'check_in'        => $checkIn,
    'check_out'       => $checkOut,
    'hours_requested' => $hours,
]);

echo json_encode([
    'success' => true,
    'rooms'   => $rooms,
    'quote'   => $quote,
]);
