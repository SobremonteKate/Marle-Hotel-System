<?php
include "../admin/auth.php";
include "../dbconnect.php";

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');

$events = [];

// Manual calendar events (not linked to bookings)
$manualSql = "
    SELECT c.id, c.title, c.description, c.start, c.end, c.color
    FROM calendartbl c
    LEFT JOIN bookings b ON b.calendar_event_id = c.id
    WHERE b.id IS NULL
";
$res = $conn->query($manualSql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $events[] = [
            'id'              => $row['id'],
            'title'           => $row['title'],
            'start'           => $row['start'],
            'end'             => $row['end'],
            'description'     => $row['description'],
            'backgroundColor' => $row['color'],
            'borderColor'     => $row['color'],
            'extendedProps'   => [
                'source'      => 'manual',
                'description' => $row['description'],
            ],
        ];
    }
}

// Booking events — join bookings for rich data
$bookingSql = "
    SELECT c.id, c.title, c.start, c.end, c.color,
           b.room_type, b.room_number, b.persons,
           b.guest_name, b.booking_type, b.check_in_time, b.check_out_time
    FROM calendartbl c
    INNER JOIN bookings b ON b.calendar_event_id = c.id
    WHERE b.status = 'approved'
";
$res2 = $conn->query($bookingSql);
if ($res2) {
    while ($row = $res2->fetch_assoc()) {
        $tip = $row['room_type'];
        if ($row['room_number']) $tip .= ' · Room ' . $row['room_number'];
        $tip .= ' · ' . $row['persons'] . ' guest' . ($row['persons'] > 1 ? 's' : '');
        if ($row['booking_type'] === 'hourly' && $row['check_in_time']) {
            $tip .= ' · ' . date('g:i A', strtotime($row['check_in_time']))
                  . '–' . date('g:i A', strtotime($row['check_out_time']));
        }
        $events[] = [
            'id'              => $row['id'],
            'title'           => $row['title'],
            'start'           => $row['start'],
            'end'             => $row['end'],
            'backgroundColor' => $row['color'],
            'borderColor'     => $row['color'],
            'editable'        => false,
            'extendedProps'   => [
                'source'      => 'booking',
                'tooltip'     => $tip,
                'room_type'   => $row['room_type'],
                'room_number' => $row['room_number'],
                'persons'     => $row['persons'],
                'guest_name'  => $row['guest_name'],
                'booking_type'=> $row['booking_type'],
            ],
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($events);
