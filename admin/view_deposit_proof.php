<?php
// ============================================================
//  admin/view_deposit_proof.php
//  Serves a guest's uploaded deposit receipt to signed-in staff.
//
//  GET: booking_id
//  Capability: collect_payments (all three staff roles handle guest money)
//
//  Why this file exists: a GCash/bank receipt is the guest's own financial
//  document, so it must never be a public URL. uploads/booking_proof/.htaccess
//  refuses direct requests; this endpoint is the only way in, and it checks the
//  session, the capability and the stored path before streaming the file.
// ============================================================
require_once __DIR__ . '/../dbconnect.php';

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');
include('../admin/auth_admin.php');
require_once __DIR__ . '/../includes/log_action.php';

if (!has_capability('collect_payments')) {
    log_event($conn, 'deposit_proof_denied', 'booking', (int)($_GET['booking_id'] ?? 0), 'denied', 'missing collect_payments capability');
    http_response_code(403);
    exit('Not authorized.');
}

$bookingId = (int)($_GET['booking_id'] ?? 0);
if ($bookingId <= 0) {
    http_response_code(400);
    exit('Missing booking.');
}

$stmt = $conn->prepare('SELECT deposit_proof_path FROM bookings WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $bookingId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$path = trim((string)($row['deposit_proof_path'] ?? ''));

// Only ever serve out of the proof directory. The value comes from our own
// upload code, but a hand-edited row must not be able to reach another file.
$prefix = 'uploads/booking_proof/';
$baseDir = realpath(__DIR__ . '/../uploads/booking_proof');

if ($row === null || $path === '' || strpos($path, $prefix) !== 0
    || strpos($path, '..') !== false || $baseDir === false) {
    http_response_code(404);
    exit('No receipt is on file for this booking.');
}

$abs = realpath(__DIR__ . '/../' . $path);
if ($abs === false || strpos($abs, $baseDir) !== 0 || !is_file($abs)) {
    http_response_code(404);
    exit('No receipt is on file for this booking.');
}

// Serve with the type we detect, never a type from the database or a filename:
// an uploaded file must never be able to talk the browser into rendering HTML.
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string)$finfo->file($abs);
if (!isset($allowed[$mime])) {
    http_response_code(415);
    exit('Unsupported receipt file.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; object-src \'self\'');
header('Cache-Control: private, no-store');
header('Content-Disposition: inline; filename="deposit-booking-' . $bookingId . '.' . $allowed[$mime] . '"');
readfile($abs);
