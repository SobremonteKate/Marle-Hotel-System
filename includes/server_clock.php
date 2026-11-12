<?php
// ============================================================
//  includes/server_clock.php — the browser's clock is not the hotel's clock
//
//  THE BUG THIS FILE EXISTS TO KILL
//  ---------------------------------
//  Every countdown in this app (the Occupied Rooms cards on GuestTokens.php, the
//  guest portal's "session expires in") was computed from the *viewing device's*
//  own clock — `Date.now()` — and compared against an absolute expiry that the
//  server had written into the page (`UNIX_TIMESTAMP(expires_at)`).
//
//  That works perfectly while the page is open on the very machine that runs the
//  server, which is exactly how it was tested. The moment the same page is opened
//  from a phone, a tablet or a reception PC whose clock or timezone is even
//  slightly wrong, the arithmetic inverts: a room the database still reports as
//  occupied renders as "Expired", the card turns red — while the page header
//  still counts it in "OCCUPIED ROOMS 2". One database row, two different answers.
//
//  On the guest portal the same inversion is not cosmetic: the footer script
//  treats `left <= 0` as a real checkout, calls auto_checkout.php and force-checks
//  the guest out of a stay that is still running (see portal/includes/footer.php,
//  and the server-side gate in auto_checkout.php added alongside this file).
//
//  THE FIX
//  -------
//  Stop asking the device what time it is. This file emits one small script as
//  early as possible in the document that pins the server's clock at render time
//  and then measures *elapsed* time with performance.now() — a monotonic counter
//  that no clock skew, timezone offset or mid-session NTP step can move.
//  MarleClock.nowMs() is therefore the server's clock, continued in the browser,
//  and a countdown built from it reads the same on every device in the world.
//
//  USAGE
//  -----
//    PHP : require_once __DIR__ . '/../includes/server_clock.php';
//          marle_server_clock_assets();                  // once, before </head>
//          $secsLeft = marle_secs_left($expiresUnix);    // value *now*, per the server
//    HTML: data-left="<the servers own seconds remaining>"  // render-time seconds left
//          (written as a PHP short-echo of $secsLeft; this comment cannot spell that
//           out or PHP would end its own comment at the closing tag)
//    JS  : MarleClock.secsLeft(leftAtRender)             // seconds remaining this instant
//          MarleClock.formatClockMs(ms)                  // HH:MM in the HOTEL's timezone
//
//  `data-expires` (the absolute unix expiry) is kept on those elements for
//  backwards compatibility and debugging; nothing should compute expiry from it
//  by hand any more — `data-left` is the server's own answer and the clock only
//  counts it down.
// ============================================================

if (!function_exists('marle_server_clock_ms')) {

    /**
     * The server's wall clock in milliseconds since the epoch.
     * This is the anchor handed to the browser.
     */
    function marle_server_clock_ms(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * The hotel's own UTC offset in minutes, so the browser can print hotel-local
     * time instead of the timezone the guest's phone happens to be set to.
     *
     * This deliberately asks the DATABASE first, because the database's wall clock
     * is the one this app actually displays: guest_tokens.expires_at, date_to,
     * cancellations and every "Issued by / Expires" line are written with MySQL's
     * NOW() and printed back as-is. PHP's own date.timezone is a separate setting
     * and may disagree with it — on the XAMPP box that hosts this hotel, PHP runs
     * Europe/Berlin while MySQL and Windows run UTC+8, six hours apart. A preview
     * built from PHP's offset would then promise the guest a checkout time six
     * hours earlier than the expiry the same card shows.
     *
     * TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) reads the session's offset and
     * needs no timezone tables loaded, so it works with time_zone = SYSTEM.
     */
    function marle_server_clock_offset_minutes(): int
    {
        static $minutes = null;
        if ($minutes !== null) {
            return $minutes;
        }

        $conn = $GLOBALS['conn'] ?? null;
        if ($conn instanceof mysqli) {
            $res = @$conn->query('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS off');
            if ($res && ($row = $res->fetch_assoc()) && $row['off'] !== null) {
                $minutes = (int) $row['off'];
                return $minutes;
            }
        }

        // No database on this request (a CLI script, a test) — PHP's own setting is
        // the best available answer.
        $minutes = (int) round((new DateTime('now'))->getOffset() / 60);
        return $minutes;
    }

    /**
     * Seconds left on an expiry, judged by the server. Pages should render this
     * next to the element rather than letting the browser subtract clocks.
     */
    function marle_secs_left($expiresUnix): int
    {
        return max(0, (int) $expiresUnix - time());
    }

    /**
     * A calendar date (YYYY-MM-DD) on the clock that actually stamps this data:
     * the database's. Everything the app writes with MySQL's NOW() — chat stamps,
     * review dates, archived_at, expiry times — is the database's wall clock, so
     * "today" for a filter or a label must come from the same place. PHP's own
     * date.timezone is not that clock (see marle_server_clock_offset_minutes()).
     */
    function marle_hotel_date(int $offsetDays = 0, ?mysqli $conn = null): string
    {
        $conn = $conn ?? ($GLOBALS['conn'] ?? null);
        if ($conn instanceof mysqli) {
            $sql = $offsetDays === 0
                ? 'SELECT CURDATE() AS d'
                : 'SELECT DATE_ADD(CURDATE(), INTERVAL ' . (int) $offsetDays . ' DAY) AS d';
            $res = @$conn->query($sql);
            if ($res && ($row = $res->fetch_assoc()) && !empty($row['d'])) {
                return (string) $row['d'];
            }
        }

        // No database on this request (a CLI script, a test): PHP's own clock is the
        // only one left. These are the render-time fallbacks the pages store as
        // window.MARLE_HOTEL_TODAY / _WEEK_AGO.
        return date('Y-m-d', strtotime(($offsetDays >= 0 ? '+' : '') . $offsetDays . ' days'));
    }

    /**
     * "Now" on the clock that stamps this database: MySQL's, not PHP's.
     *
     * Every date and time this app reasons about is written by the database —
     * NOW(), CURDATE(), expiry stamps, "today" — so a timestamp written from PHP
     * has to come from the same clock or the two disagree on the same row. On the
     * machine that runs this hotel they do: php.ini sets date.timezone to
     * Europe/Berlin while MySQL runs UTC+8, six hours apart, so a row stamped with
     * date() is six hours behind the row it sits on (measured on a live booking:
     * created_at 22:45:53, terms_accepted_at 16:45:53 — +360 minutes).
     *
     * Use this wherever a stamp is stored or shown as the hotel's own time; use
     * marle_hotel_date() for a calendar date. Both fall back to PHP's clock when
     * there is no database on the request (CLI scripts, tests) rather than failing.
     * Cached per request: a page can call it as often as it likes.
     */
    function marle_db_now(?mysqli $conn = null): string
    {
        static $now = null;
        if ($now !== null) {
            return $now;
        }

        $conn = $conn ?? ($GLOBALS['conn'] ?? null);
        if ($conn instanceof mysqli) {
            $res = @$conn->query('SELECT NOW() AS n');
            if ($res && ($row = $res->fetch_assoc()) && !empty($row['n'])) {
                return $now = (string) $row['n'];
            }
        }

        // No database on this request (a CLI script, a test) — PHP's own clock is the
        // only one left.
        return $now = date('Y-m-d H:i:s');
    }

    /**
     * Emit the anchor script. Idempotent — a page assembled from several partials
     * can call this more than once and only the first call prints.
     */
    function marle_server_clock_assets(): void
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;

        $anchorMs  = marle_server_clock_ms();
        $tzMinutes = marle_server_clock_offset_minutes();
        ?>
<script id="marle-server-clock">
/* includes/server_clock.php — the hotel's clock, not the device's. See that file's header. */
(function () {
    'use strict';

    var anchorServerMs = <?= $anchorMs ?>;
    var tzOffsetMin    = <?= $tzMinutes ?>;

    // performance.now() only moves forward, at real-time speed, and is unaffected
    // by the device's wall clock. Date.now() is only the fallback for a browser
    // too old to expose performance.now() — on a device whose clock is wrong it is
    // precisely the value we must not trust.
    var perfAnchor = (window.performance && typeof window.performance.now === 'function')
        ? window.performance.now()
        : null;
    var wallAnchor = Date.now();

    var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    // The hotel's date when this page was rendered, straight from the database
    // (marle_hotel_date()). Displays that need "today" prefer MarleClock below and
    // fall back to these, so even a page whose clock asset somehow went missing
    // still never asks the viewing device what day it is.
    window.MARLE_HOTEL_TODAY    = '<?= marle_hotel_date(0) ?>';
    window.MARLE_HOTEL_WEEK_AGO = '<?= marle_hotel_date(-7) ?>';

    /**
     * A DATETIME/DATE as written by MySQL, e.g. "2026-10-12 22:30:00". Every stamp
     * this app renders comes from the database, so its parts are the hotel's own
     * wall clock and are used exactly as written — no Date parsing, no timezone,
     * no device clock. Returns null when the string is not a stamp.
     */
    function wallParts(str) {
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/.exec(String(str == null ? '' : str));
        if (!m) { return null; }
        return {
            iso : m[1] + '-' + m[2] + '-' + m[3],
            y   : +m[1], mo : +m[2], d : +m[3],
            h   : +(m[4] || 0), mi : +(m[5] || 0), s : +(m[6] || 0)
        };
    }

    function isoDate(ms) {
        var shifted = new Date(Number(ms) + tzOffsetMin * 60000);
        return shifted.getUTCFullYear() + '-' + pad2(shifted.getUTCMonth() + 1) + '-' + pad2(shifted.getUTCDate());
    }

    function elapsedMs() {
        return perfAnchor !== null
            ? (window.performance.now() - perfAnchor)
            : (Date.now() - wallAnchor);
    }

    function pad2(n) { return (n < 10 ? '0' : '') + n; }

    window.MarleClock = {
        anchorServerMs : anchorServerMs,
        tzOffsetMin    : tzOffsetMin,

        /** Milliseconds elapsed since this page was rendered. */
        elapsedMs : elapsedMs,

        /** The server's clock, continued in this browser. */
        nowMs : function () { return anchorServerMs + elapsedMs(); },

        /** The server's clock in whole seconds. */
        nowSecs : function () { return Math.floor((anchorServerMs + elapsedMs()) / 1000); },

        /**
         * How far this device's clock differs from the hotel's (ms, positive =
         * device is ahead). Purely diagnostic: when a desk reports "the times are
         * wrong on this PC", this says by how much.
         */
        deviceDriftMs : function () { return Date.now() - (anchorServerMs + elapsedMs()); },

        /**
         * Seconds remaining, given the value the server rendered next to the
         * element. Counts down from the server's own number, so the device clock
         * is never consulted — a phone ten hours ahead still sees the truth.
         */
        secsLeft : function (secsLeftAtRender) {
            return Math.max(0, Math.floor((Number(secsLeftAtRender) || 0) - elapsedMs() / 1000));
        },

        /**
         * 'H:MM:SS AM/PM' in the hotel's timezone — the room board's "Last updated"
         * stamp and the dashboard / guest-record refresh labels, which used to print
         * whatever time the viewing PC's own clock happened to be showing.
         */
        formatClockSecs : function (ms) {
            var shifted = new Date(Number(ms) + tzOffsetMin * 60000);
            var h = shifted.getUTCHours();
            var h12 = h % 12;
            if (h12 === 0) { h12 = 12; }
            return h12 + ':' + pad2(shifted.getUTCMinutes()) + ':' + pad2(shifted.getUTCSeconds())
                 + ' ' + (h >= 12 ? 'PM' : 'AM');
        },

        /** HH:MM am/pm in the HOTEL's timezone, whatever the device is set to. */
        formatClockMs : function (ms) {
            var shifted = new Date(Number(ms) + tzOffsetMin * 60000);
            var h = shifted.getUTCHours();
            var ap = h >= 12 ? 'pm' : 'am';
            var h12 = h % 12;
            if (h12 === 0) { h12 = 12; }
            return h12 + ':' + pad2(shifted.getUTCMinutes()) + ' ' + ap;
        },

        /** Whole-day number in the hotel's timezone — compare two to spot "+1d". */
        dayIndex : function (ms) {
            return Math.floor((Number(ms) + tzOffsetMin * 60000) / 86400000);
        },

        /**
         * The hotel's current date as a Date at LOCAL NOON, for FullCalendar's `now`
         * option — the day it highlights as "today" and the day its "today" button
         * jumps to. Without it FullCalendar asks the viewing device, so a phone in
         * another timezone or with a skewed clock highlights the wrong day.
         *
         * Noon, and built from local parts, on purpose: the value never goes through
         * string parsing ("2026-10-12" is parsed as UTC midnight by the language and
         * would land on the 11th west of Greenwich), and noon is far enough from both
         * midnight boundaries that no timezone can shift the day it represents.
         */
        todayDate : function () {
            var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate(anchorServerMs + elapsedMs()));
            return m ? new Date(+m[1], +m[2] - 1, +m[3], 12) : new Date();
        },

        /** The hotel's date, offsetDays from now, as YYYY-MM-DD. */
        dayIso : function (offsetDays) {
            // Note: the anchor is used directly rather than through this.nowMs(), so the
            // helper still works when it is pulled off the object (and cannot silently
            // reference a global that does not exist — which is exactly how this broke
            // the first time).
            return isoDate(anchorServerMs + elapsedMs() + (offsetDays || 0) * 86400000);
        },

        /** Split a MySQL DATETIME/DATE into its parts (null if it is not one). */
        wallParts : wallParts,

        /** '2:45 PM' read straight off the stamp — it is already hotel wall time. */
        formatWallTime : function (str) {
            var p = wallParts(str);
            if (!p) { return ''; }
            var h12 = p.h % 12;
            if (h12 === 0) { h12 = 12; }
            return h12 + ':' + pad2(p.mi) + ' ' + (p.h >= 12 ? 'PM' : 'AM');
        },

        /** 'Oct 12, 2026', or 'Oct 12' when monthDayOnly is true. */
        formatWallDate : function (str, monthDayOnly) {
            var p = wallParts(str);
            if (!p) { return ''; }
            return MONTHS[p.mo - 1] + ' ' + p.d + (monthDayOnly ? '' : ', ' + p.y);
        },

        /**
         * A hotel stamp as a real instant, for "3h ago" arithmetic. The device's
         * timezone is never consulted — a stamp read on a phone in another zone used
         * to shift by hours, which is how "3h ago" could become "yesterday".
         * Returns NaN when the string is not a stamp.
         */
        parseWall : function (str) {
            var p = wallParts(str);
            if (!p) { return NaN; }
            return Date.UTC(p.y, p.mo - 1, p.d, p.h, p.mi, p.s) - tzOffsetMin * 60000;
        }
    };
})();
</script>
        <?php
    }
}
