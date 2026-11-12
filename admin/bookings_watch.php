<?php
// ============================================================
//  admin/bookings_watch.php — read-only fingerprint of the Bookings tab.
//
//  Requests → Bookings is rendered once per page load, so a guest booking,
//  a receipt upload, a guest cancellation or an automatic hold release used to
//  be invisible until someone pressed F5. The page polls this endpoint and
//  refreshes itself when the fingerprint moves (includes/booking_watch.php).
//
//  GET only. Returns no guest data — just a signature plus counts — so it is
//  safe to poll from an idle tab.
// ============================================================
include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../admin/auth.php');
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
