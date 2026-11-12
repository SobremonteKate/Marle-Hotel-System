<?php
// ============================================================
//  homepage/release_hourly_rooms.php
//  1. Releases hourly bookings whose check_out_time has passed.
//  2. Runs the token sweep (expire guests, archive stale checkouts).
//  Safe to call repeatedly (idempotent).
//  Called by JS poll on home.php every 60 seconds.
// ============================================================
header('Content-Type: application/json');
include('../dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json');
require_once __DIR__ . '/../admin/includes/token_sweep.php';
require_once __DIR__ . '/../includes/server_clock.php';   // marle_db_now(): the hotel's clock

// ── Original: release expired hourly bookings ─────────────────────────────────
$stmt = $conn->prepare(
    "UPDATE bookings
     SET    hourly_released = 1
     WHERE  booking_type    = 'hourly'
       AND  hourly_released = 0
       AND  status          = 'approved'
       AND  check_in        = CURDATE()
       AND  check_out_time  <= TIME(NOW())"
);
$stmt->execute();
$released = $stmt->affected_rows;
$stmt->close();

// ── Token sweep: auto-checkout expired tokens, archive stale customers ─────────
$sweep = run_token_sweep($conn);

// ── Log ───────────────────────────────────────────────────────────────────────
// The hotel's clock, not PHP's (includes/server_clock.php): the sweep these lines report
// on stamped its own rows with the database's clock.
$sweepNow = marle_db_now($conn);
$logLine = $sweepNow
         . " — released {$released} hourly booking(s)"
         . ", checked_out {$sweep['checked_out_count']} token(s)"
         . ", archived {$sweep['archived_count']} customer(s)\n";
@file_put_contents(__DIR__ . '/release_log.txt', $logLine, FILE_APPEND | LOCK_EX);

echo json_encode([
    'success'           => true,
    'released'          => $released,
    'checked_out_count' => $sweep['checked_out_count'],
    'archived_count'    => $sweep['archived_count'],
    'ts'                => $sweepNow,
]);