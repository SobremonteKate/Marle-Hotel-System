<?php
// Canonical daily archive and retention maintenance job.
// CLI: C:\xampp\php\php.exe C:\xampp\htdocs\MarleHotel\admin\cron_archive.php

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

$log = [];

runMaintenanceQuery(
    $conn,
    'Cancelled approved booking no-shows after one-hour grace period',
    "UPDATE bookings b
     SET b.status = 'cancelled',
         b.cancelled_at = NOW(),
         b.cancelled_by = 'system',
         b.cancellation_reason = 'No-show after one-hour grace period',
         b.updated_at = NOW()
     WHERE b.status = 'approved'
       AND ((b.booking_type = 'daily' AND TIMESTAMP(b.check_in, '15:00:00') <= NOW())
            OR (b.booking_type = 'hourly' AND TIMESTAMP(b.check_in, b.check_in_time) + INTERVAL 1 HOUR <= NOW()))
       AND NOT EXISTS (
           SELECT 1 FROM customerstbl c
                     WHERE c.status = 'checked-in'
                         AND CONVERT(c.room_no USING utf8mb4) COLLATE utf8mb4_unicode_ci =
                                 CONVERT(b.room_number USING utf8mb4) COLLATE utf8mb4_unicode_ci
             AND DATE(c.date_from) <= b.check_in
             AND DATE(c.date_to) >= b.check_in
       )",
    $log
);

runMaintenanceQuery(
    $conn,
    'Removed calendar events for cancelled no-show bookings',
    "DELETE ce FROM calendartbl ce
     JOIN bookings b ON b.calendar_event_id = ce.id
     WHERE b.status = 'cancelled'
       AND b.cancelled_by = 'system'
       AND b.cancellation_reason = 'No-show after one-hour grace period'",
    $log
);

function runMaintenanceQuery(mysqli $conn, string $label, string $sql, array &$log): void
{
    if (!$conn->query($sql)) {
        $log[] = $label . ' failed: ' . $conn->error;
        return;
    }

    $log[] = $label . ': ' . $conn->affected_rows . ' row(s)';
}

function maintenanceColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

runMaintenanceQuery(
    $conn,
    'Soft-archived old requests',
    "UPDATE requests
     SET is_archived = 1, archived_at = NOW()
     WHERE is_archived = 0
       AND status IN ('approved', 'denied', 'completed')
       AND created_at < NOW() - INTERVAL 1 DAY",
    $log
);

runMaintenanceQuery(
    $conn,
    'Hard-deleted requests archived for 60+ days',
    "DELETE FROM requests
     WHERE is_archived = 1
       AND archived_at < NOW() - INTERVAL 60 DAY",
    $log
);

runMaintenanceQuery(
    $conn,
    'Soft-archived old chat messages',
    "UPDATE chat_messages
     SET archived_at = NOW()
     WHERE archived_at IS NULL
       AND sent_at < NOW() - INTERVAL 1 DAY",
    $log
);

runMaintenanceQuery(
    $conn,
    'Hard-deleted chat messages archived for 60+ days',
    "DELETE FROM chat_messages
     WHERE archived_at IS NOT NULL
       AND archived_at < NOW() - INTERVAL 60 DAY",
    $log
);

if (maintenanceColumnExists($conn, 'bookings', 'is_archived')
    && maintenanceColumnExists($conn, 'bookings', 'archived_at')) {
    runMaintenanceQuery(
        $conn,
        'Soft-archived old bookings',
        "UPDATE bookings
         SET is_archived = 1, archived_at = NOW()
         WHERE is_archived = 0
           AND status IN ('approved', 'denied', 'cancelled')
           AND check_out < CURDATE() - INTERVAL 1 DAY",
        $log
    );

    runMaintenanceQuery(
        $conn,
        'Hard-deleted bookings archived for 60+ days',
        "DELETE FROM bookings
         WHERE is_archived = 1
           AND archived_at < NOW() - INTERVAL 60 DAY",
        $log
    );
} else {
    $log[] = 'Booking retention skipped: archive columns are not installed.';
}

runMaintenanceQuery(
    $conn,
    'Removed orphan extension history rows',
    "DELETE eh
     FROM extension_history eh
     LEFT JOIN extension_requests er ON er.id = eh.extension_req_id
     WHERE er.id IS NULL",
    $log
);

// The hotel's clock, not PHP's (includes/server_clock.php): these lines are read
// beside the database's own timestamps in the same log.
$timestamp = marle_db_now($conn);
foreach ($log as $line) {
    echo "[{$timestamp}] {$line}" . PHP_EOL;
}
echo "[{$timestamp}] Maintenance complete." . PHP_EOL;
