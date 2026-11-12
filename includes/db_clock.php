<?php
/**
 * db_clock.php — the hotel's clock, guaranteed, on every connection.
 *
 * WHY THIS FILE EXISTS
 *   config.php already sets date_default_timezone_set('Asia/Manila') and pins
 *   each database session to +08:00 (marle_db_pin_time_zone()). That works — on
 *   the machine the code was written on. It did not work on the live host, and
 *   the reason is embarrassing and simple: config.php is the ONE file that is
 *   never uploaded on an update, because it holds the hosting credentials and is
 *   edited by hand in hPanel. The clock fix landed inside that file, on
 *   2026-10-20; every update shipped since then excluded it on purpose. So the
 *   live site kept running the pre-October config.php, whose connection never
 *   pinned a time zone, on a host whose MySQL runs on UTC.
 *
 *   The symptom, measured on the live portal at 23:24 Philippine time: a chat
 *   message stamped 03:24 PM — exactly eight hours behind (UTC instead of
 *   +08:00). The same skew applied to every DATETIME the database wrote for
 *   itself (chat_messages.sent_at, guest_tokens.created_at), and to every PHP
 *   date() the host's own time zone produced.
 *
 *   The lesson is not "remember to upload config.php". It is that the clock must
 *   not depend on a file that is deliberately never updated. So the clock now
 *   also lives in this file, which DOES ship: every connection file requires it
 *   after config.php, and it pins the session itself. When config.php is current
 *   it has already done the work and every definition below is skipped; when
 *   config.php is old, this file supplies exactly what is missing. Either way the
 *   connection ends up at +08:00.
 *
 * WHAT IT GUARANTEES
 *   1. MARLE_HOTEL_TZ exists ("Asia/Manila").
 *   2. PHP's default zone is the hotel's — so date() on a request that never got
 *      the value from php.ini, .user.ini or .htaccess still prints Manila time.
 *   3. marle_hotel_tz_offset() and marle_db_pin_time_zone() exist, so a stale
 *      config.php cannot leave calendar/dbconnect.php calling an undefined
 *      function (a fatal, not a wrong time).
 *
 * USAGE — in a connection file, after config.php and after connecting:
 *     require_once __DIR__ . '/includes/db_clock.php';
 *     if ($conn instanceof mysqli) { marle_db_pin_time_zone($conn); }
 *
 * Every definition is guarded: this file is safe to require from several
 * includes, in any order, and never overrides a current config.php.
 */

if (!defined('MARLE_HOTEL_TZ')) {
    define('MARLE_HOTEL_TZ', 'Asia/Manila');
}

// PHP's own clock. Idempotent and legal from anywhere (PHP_INI_ALL); the
// project's clock rule allows this call only with the hotel's own zone, which is
// exactly what is passed here.
date_default_timezone_set(MARLE_HOTEL_TZ);

if (!function_exists('marle_hotel_tz_offset')) {
    /**
     * The hotel's current UTC offset as MySQL wants it, e.g. "+08:00".
     * A numeric offset is used on purpose: it needs no timezone tables loaded,
     * so it works on a shared MySQL where SET time_zone = 'Asia/Manila' fails
     * with "Unknown or incorrect time zone".
     */
    function marle_hotel_tz_offset(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(MARLE_HOTEL_TZ)))->format('P');
    }
}

if (!function_exists('marle_db_pin_time_zone')) {
    /**
     * Point one database session at the hotel's clock. Idempotent and logged,
     * never fatal: a host that refuses the statement would otherwise turn every
     * page into a 500. The failure mode is then the old behaviour (the host's
     * own zone), which is logged rather than silently accepted.
     */
    function marle_db_pin_time_zone($conn): void
    {
        if (!($conn instanceof mysqli)) {
            return;
        }

        $offset = marle_hotel_tz_offset();

        try {
            if (!mysqli_query($conn, "SET time_zone = '" . $offset . "'")) {
                error_log('DB time zone: could not set ' . $offset . ' — ' . mysqli_error($conn));
                return;
            }

            // Prove it took. SET time_zone on a shared MySQL is the kind of thing
            // that answers "OK" and does nothing; reading the session's own offset
            // back is the only evidence that the stamps this request writes will
            // be hotel time.
            $res = @mysqli_query($conn, 'SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS off');
            $row = $res ? mysqli_fetch_assoc($res) : null;
            if ($row && isset($row['off'])) {
                $want = (int) round((new DateTimeImmutable('now', new DateTimeZone(MARLE_HOTEL_TZ)))->getOffset() / 60);
                if ((int) $row['off'] !== $want) {
                    error_log('DB time zone: session is at ' . $row['off'] . ' minutes, expected ' . $want);
                }
            }
        } catch (Throwable $e) {
            // PHP 8.1+ mysqli reports errors by throwing; same one-line log.
            error_log('DB time zone: could not set ' . $offset . ' — ' . $e->getMessage());
        }
    }
}
