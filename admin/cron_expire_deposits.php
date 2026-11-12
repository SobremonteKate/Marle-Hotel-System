<?php
// ============================================================
//  admin/cron_expire_deposits.php
//  Releases bookings whose reservation deposit never arrived.
//
//  An unpaid booking blocks its room for everyone else, so it is only held for
//  the configured window (Settings → Reservation Deposit → deposit_hold_hours,
//  default 24). Past that, the booking is cancelled as the hotel, the room goes
//  back to the pool and the guest is emailed — nothing is ever charged.
//
//  Safe to call repeatedly (idempotent): the UPDATE re-checks that the booking
//  is still `pending` with an unpaid/rejected deposit, so a booking a manager
//  verified between two runs is never cancelled.
//
//  CLI (the local/documented way to run maintenance):
//    C:\xampp\php\php.exe C:\xampp\htdocs\MarleHotel\admin\cron_expire_deposits.php
//  Options:  --dry-run            report what would be released, write nothing
//            --hours=N            use N hours instead of the configured window
//            --booking-id=N       release only that booking
//
//  HTTP (external scheduler, cron-job.org — same secret gate as cron_archive):
//    https://YOURDOMAIN/admin/cron_expire_deposits.php?key=THE_SECRET
//    …&dry_run=1 &hours=N &booking_id=N
//
//  Schedule this hourly (or every 15 minutes): a daily run would let a room sit
//  held for up to a day past the window it advertises to guests.
// ============================================================

require_once __DIR__ . '/../config.php';

$configuredSecret = getenv('MARLE_CRON_SECRET') ?: MARLE_CRON_SECRET;
$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    if (!$configuredSecret) {
        http_response_code(503);
        exit('Maintenance secret is not configured.');
    }

    $key = trim($_GET['key'] ?? '');
    if (!$key || !hash_equals($configuredSecret, $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

require_once __DIR__ . '/../dbconnect.php';
require_once __DIR__ . '/../includes/server_clock.php';   // marle_db_now(): the hotel's clock, not PHP's

if (!($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.' . PHP_EOL);
}

require_once __DIR__ . '/../includes/booking_expiry.php';

// ── Options (CLI flags win; HTTP accepts the same names as query params) ──────
$opts = $isCli ? getopt('', ['dry-run', 'hours::', 'booking-id::']) : [];
$dryRun = (bool)($isCli ? isset($opts['dry-run']) : !empty($_GET['dry_run']));

$rawHours = $isCli ? ($opts['hours'] ?? null) : ($_GET['hours'] ?? null);
$hours = (is_numeric($rawHours) && (int)$rawHours > 0) ? (int)$rawHours : null;

$rawBookingId = $isCli ? ($opts['booking-id'] ?? null) : ($_GET['booking_id'] ?? null);
$onlyBookingId = (is_numeric($rawBookingId) && (int)$rawBookingId > 0) ? (int)$rawBookingId : null;

$result = marle_expire_unpaid_bookings($conn, $onlyBookingId, $hours, $dryRun);
// The hotel's clock, not PHP's (includes/server_clock.php): this line sits beside the
// database times the sweep itself wrote.
$stamp  = marle_db_now($conn);

printf(
    "[%s] Deposit hold window: %d hour(s)%s%s\n",
    $stamp,
    $result['hours'],
    $onlyBookingId !== null ? ' · booking #' . $onlyBookingId . ' only' : '',
    $dryRun ? ' · DRY RUN (nothing written)' : ''
);

foreach ($result['released'] as $releasedId) {
    printf("[%s] %s booking #%d — deposit not received within %d hour(s)\n",
        $stamp,
        $dryRun ? 'would release' : 'released',
        $releasedId,
        $result['hours']);
}

foreach ($result['skipped'] as $skippedId) {
    printf("[%s] booking #%d changed while the sweep ran — left alone\n", $stamp, $skippedId);
}

printf(
    "[%s] Deposit expiry complete: %d booking(s) %s.\n",
    $stamp,
    count($result['released']),
    $dryRun ? 'would be released' : 'released'
);
