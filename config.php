<?php
// ============================================================
//  config.php — single source of truth for credentials & secrets
//
//  Priority order:
//    1. Real environment variables (VPS, ngrok box with setx, CI)
//    2. The constants below (edit these ONCE on the hosting
//       provider — InfinityFree, Hostinger, etc. — and nowhere else)
//
//  Local XAMPP defaults are the checked-in values; a stock install
//  needs no edits. Only change these constants on the host.
// ============================================================

// ── The hotel's clock: Asia/Manila ───────────────────────────
// This hotel is in the Philippines, and every time the app shows — a booking's
// check-in, a token's expiry, "today" on the calendar, the stamp on an email —
// must be Philippine time. That is not the same as the clock of the computer
// the code happens to run on: this XAMPP box ships PHP set to Europe/Berlin
// while Windows and MySQL run UTC+8, so a PHP `date()` call and the database's
// NOW() disagreed by six hours on the same request.
//
// Three places have to agree, and all three start here:
//   1. PHP's default time zone — set below, before any include can call date().
//   2. Every database session — marle_db_pin_time_zone() writes the matching
//      UTC offset onto each connection, so MySQL's NOW()/CURDATE() are hotel
//      time even on a host whose system clock is UTC.
//   3. The request layer for files that never include this one — .user.ini and
//      .htaccess repeat `date.timezone = Asia/Manila` for the same reason.
//
// Existing rows are NOT rewritten: they were stamped in hotel wall-clock time
// already, and pinning the session to +08:00 keeps new rows in the same frame.
// Asia/Manila has no daylight saving, so the offset is +08:00 all year.
if (!defined('MARLE_HOTEL_TZ')) define('MARLE_HOTEL_TZ', 'Asia/Manila');
date_default_timezone_set(MARLE_HOTEL_TZ);

// ── Database ─────────────────────────────────────────────────
// InfinityFree: host is like "sqlXXX.infinityfree.com", user/db are
// "ifX_XXXX_dbname" style — copy them from the vPanel MySQL page.
// Hostinger: host is "localhost"; user/db are "uXXXXXXXX_name" style — copy
// them from hPanel → Databases → MySQL Databases.
if (!defined('MARLE_DB_HOST')) define('MARLE_DB_HOST', getenv('MARLE_DB_HOST') ?: 'localhost'); // fine on InfinityFree + ngrok (DB host is MySQL hostname, not web host)
if (!defined('MARLE_DB_USER')) define('MARLE_DB_USER', getenv('MARLE_DB_USER') ?: 'root');
if (!defined('MARLE_DB_PASS')) define('MARLE_DB_PASS', getenv('MARLE_DB_PASS') !== false ? getenv('MARLE_DB_PASS') : '');
if (!defined('MARLE_DB_NAME')) define('MARLE_DB_NAME', getenv('MARLE_DB_NAME') ?: 'marlehoteldb');

// ── Secrets (empty by default = feature stays off, as today) ──
// MARLE_CRON_SECRET: lets an external scheduler (e.g. cron-job.org) trigger
// admin/cron_archive.php over HTTP as  .../admin/cron_archive.php?key=<secret>.
// Generate one with:  php -r "echo bin2hex(random_bytes(24));"
// then paste it between the quotes below ON THE HOST (keep local empty if you
// don't run the maintenance trigger locally).
if (!defined('MARLE_CRON_SECRET')) define('MARLE_CRON_SECRET', getenv('MARLE_CRON_SECRET') ?: '');
if (!defined('GROQ_API_KEY'))      define('GROQ_API_KEY', getenv('GROQ_API_KEY') ?: '');

// ── Outbound email (HTTP API — required on InfinityFree, which blocks mail()) ─
// Marle Hotel uses Brevo:  MARLE_EMAIL_PROVIDER = "brevo", key from
// Brevo → SMTP & API → API Keys, and MARLE_EMAIL_FROM set to a sender that is
// VERIFIED in Brevo (Senders, Domains & Dedicated IPs). Sending "from" a
// gmail.com address fails Gmail's DMARC policy, so prefer
// "Marle Hotel <noreply@your-hotel-domain>" once the domain's DKIM/SPF records
// are added — the code is identical either way.
// Other supported providers: "resend" | "mailgun" (Mailgun also needs
// MARLE_EMAIL_DOMAIN). Leave empty to disable email: booking flows keep
// working, they just send nothing.
// Marle Hotel's provider is Brevo.
if (!defined('MARLE_EMAIL_PROVIDER')) define('MARLE_EMAIL_PROVIDER', getenv('MARLE_EMAIL_PROVIDER') ?: 'brevo');
if (!defined('MARLE_EMAIL_API_KEY'))  define('MARLE_EMAIL_API_KEY',  getenv('MARLE_EMAIL_API_KEY')  ?: '');
if (!defined('MARLE_EMAIL_FROM'))     define('MARLE_EMAIL_FROM',     getenv('MARLE_EMAIL_FROM')     ?: '');
if (!defined('MARLE_EMAIL_DOMAIN'))   define('MARLE_EMAIL_DOMAIN',   getenv('MARLE_EMAIL_DOMAIN')   ?: '');

// Hotel inbox that receives "new booking" / "cancellation" staff alerts, and
// the Reply-To on guest mail. It must be an inbox a person actually reads:
// a noreply@ address here silently swallows every alert (and usually bounces).
if (!defined('MARLE_EMAIL_NOTIFY_TO')) define('MARLE_EMAIL_NOTIFY_TO', getenv('MARLE_EMAIL_NOTIFY_TO') ?: '');

// Booking-form email rule: syntax + a real mail server (MX/A) + no throwaway
// domains. Turn this OFF only on a machine whose DNS resolver is broken —
// otherwise every genuinely real address would be rejected.
if (!defined('MARLE_EMAIL_REQUIRE_DNS_CHECK')) define('MARLE_EMAIL_REQUIRE_DNS_CHECK', true);

// Optional absolute site root for links inside emails (e.g.
// "https://marlehotel.com"). Leave empty to auto-detect the host.
if (!defined('MARLE_BASE_URL')) define('MARLE_BASE_URL', getenv('MARLE_BASE_URL') ?: '');

// ── Login lockout (brute-force protection on staff sign-in) ───
// login.php counts consecutive wrong-password attempts per account. When
// MARLE_LOGIN_MAX_ATTEMPTS is reached the account is locked for
// MARLE_LOGIN_LOCK_MINUTES; the lock expires on its own (no manual unlock),
// and any successful sign-in clears the counter.
// Needs database/phase24_login_lockout.sql applied to userstbl.
if (!defined('MARLE_LOGIN_MAX_ATTEMPTS')) define('MARLE_LOGIN_MAX_ATTEMPTS', 3);
if (!defined('MARLE_LOGIN_LOCK_MINUTES')) define('MARLE_LOGIN_LOCK_MINUTES', 15);

// ── Shared connection helper (used by all dbconnect files) ───
// Guarded so a stray double include can never fatal the app.
if (!function_exists('marle_db_connect')) {
    function marle_db_connect()
    {
        $conn = mysqli_connect(MARLE_DB_HOST, MARLE_DB_USER, MARLE_DB_PASS, MARLE_DB_NAME);

        if (!$conn) {
            error_log('DB connection failed: ' . mysqli_connect_error());
            return $conn;
        }

        // ├─ Character set: the tables are utf8mb4, so the connection must be too.
        // Without this, mysqli takes the server's default client charset (cp850 on
        // a Windows XAMPP box), and every non-ASCII character a person types is
        // converted on the way in and out: an em dash becomes "?", and a guest
        // name like "Muñoz" or "Peña" is stored mangled. It also broke the audit
        // trail indirectly — a snapshot containing a byte that is not valid UTF-8
        // made json_encode() return false, which the JSON column then rejected, so
        // the audit row was silently dropped (log_event never throws).
        if (!mysqli_set_charset($conn, 'utf8mb4')) {
            error_log('DB charset: could not set utf8mb4 — ' . mysqli_error($conn));
        }

        // ├─ Clock: the session's NOW() must be the hotel's wall clock, not whatever
        // the database host's system time zone happens to be. A numeric offset is
        // used on purpose — it needs no timezone tables loaded, so it works on a
        // shared MySQL where `SET time_zone = 'Asia/Manila'` would fail with
        // "Unknown or incorrect time zone".
        marle_db_pin_time_zone($conn);

        return $conn;
    }
}

if (!function_exists('marle_hotel_tz_offset')) {
    /**
     * The hotel's current UTC offset as MySQL wants it, e.g. "+08:00".
     * Used for every connection (here and calendar/dbconnect.php).
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
     * page into a 500. The failure mode is the old behaviour (the host's clock),
     * which timezone_check.php reports.
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
            }
        } catch (Throwable $e) {
            // PHP 8.1+ mysqli reports errors by throwing; same one-line log.
            error_log('DB time zone: could not set ' . $offset . ' — ' . $e->getMessage());
        }
    }
}
