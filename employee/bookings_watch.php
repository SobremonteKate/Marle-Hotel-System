<?php
// ============================================================
//  employee/bookings_watch.php — read-only fingerprint of the Bookings tab.
//
//  Front Desk copy: the Requests → Bookings tab polls this so a new booking or
//  a guest's receipt shows up without pressing F5. Employees cannot verify
//  deposits (no verify_deposits capability), so what they usually see arrive
//  here is the guest's upload waiting for a manager.
//
//  GET only. Returns no guest data — just a signature plus counts.
// ============================================================
include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../employee/auth.php');
require_once __DIR__ . '/../includes/booking_watch.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store, private');

$rows    = marle_bookings_active($conn);
$pending = 0;
foreach ($rows as $row) {
    if (($row['status'] ?? '') === 'pending') {
        $pending++;
    }
}

echo json_encode([
    'success'   => true,
    'signature' => marle_bookings_signature($rows),
    'total'     => count($rows),
    'pending'   => $pending,
], JSON_UNESCAPED_SLASHES);
