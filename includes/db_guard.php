<?php
/**
 * db_guard.php — what the public sees when the database is not there.
 *
 * WHY
 *   A hotel with a dead database still has guests at the desk and people on the
 *   website. Nobody can prevent MySQL falling over; what is preventable is the
 *   guest being shown a blank 500, or a stack trace, or — worse, because it
 *   reads as truth — "no bookings found". On 2026-10-13 the booking-status page
 *   answered 20 uncaught fatals in three minutes.
 *
 * HOW IT IS USED
 *   One line, immediately after the connection is available:
 *
 *     require_once __DIR__ . '/../includes/db_guard.php';
 *     marle_db_guard($conn, 'page');   // or 'json' in an endpoint
 *
 *   The staff console (admin/ · employee/ · superadmin/) passes the third
 *   argument, because what a *guest* needs to read and what the person on shift
 *   needs to read are not the same paragraph:
 *
 *     marle_db_guard($conn, 'page', 'staff');   // a screen
 *     marle_db_guard($conn, 'json', 'staff');   // an AJAX endpoint
 *
 *   Two audiences, one implementation: the guest copy must not promise that a
 *   half-finished booking was kept, and the staff copy must say so — a staff
 *   member who is told nothing was saved knows to re-enter it, and one who is
 *   not told will assume the opposite.
 *
 *   `marle_db_connect()` (config.php) RETURNS false when it cannot connect, so
 *   $conn is not always a mysqli and every unguarded `$conn->prepare()` after a
 *   failed connection is an uncaught Error. Callers may also branch themselves
 *   on marle_db_available($conn) when a page can still show something useful.
 *
 * WHAT A GUEST GETS
 *   503 Service Unavailable, `Retry-After: 60`, and — for a page — a small
 *   self-contained paragraph naming the hotel's phone number, or a JSON body
 *   with `success/ok = false` and a readable `error` for an endpoint. The page
 *   is deliberately self-contained: no stylesheet, no font, no JavaScript, no
 *   session, nothing that could itself fail while everything else is failing.
 *
 * WHAT STAFF GET
 *   The same 503 and `Retry-After`, a page written for someone on shift instead
 *   of a guest: what is down, that this screen saved nothing, that guests are
 *   seeing a matching message, and that a reload is the way back. It deliberately
 *   does NOT name a phone number (the front desk *is* the phone number) and does
 *   not reach for the staff stylesheet, the sidebar or the bell — all of which
 *   need the database or a session to render.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *   - No error_log() call. config.php already logs "DB connection failed" once
 *     per request; a second line per request, thousands of them during a real
 *     outage, is noise, and the log-hygiene check would rightly flag it.
 *   - No technical detail in the output: no mysqli message, no table name, no
 *     file path. The guest is told what happened to *them* and what to do.
 *   - It never redirects or retries on its own; the client may.
 */

if (!function_exists('marle_db_available')) {
    /** True only when we hold a live mysqli connection. */
    function marle_db_available($conn): bool
    {
        return $conn instanceof mysqli;
    }
}

if (!function_exists('marle_db_guard_base')) {
    /**
     * The app's URL path without a trailing slash ('' at a domain root), so the
     * links on the degraded page work under http://localhost/MarleHotel/ and at
     * a domain root alike. Kept local on purpose: a page shown while everything
     * else is broken must not depend on another include being loadable.
     */
    function marle_db_guard_base(): string
    {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $base   = rtrim(dirname($dir), '/.');
        return ($base === '/' || $base === '\\') ? '' : $base;
    }
}

if (!function_exists('marle_db_degraded_page')) {
    /** The guest-facing 503 page, then exit. Self-contained by design. */
    function marle_db_degraded_page(): void
    {
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 60');
            header('Content-Type: text/html; charset=utf-8');
        }
        $base = marle_db_guard_base();
        $home = htmlspecialchars($base . '/homepage/home.php', ENT_QUOTES);
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Marle Hotel — temporarily unavailable</title>
<style>
    :root { color-scheme: light; }
    body { margin:0; padding:0; background:#faf8f5; color:#3d3020;
           font-family: "DM Sans", -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
    .wrap { max-width: 640px; margin: 0 auto; padding: 56px 22px 64px; }
    .brand { font-family: Georgia, "Times New Roman", serif; letter-spacing:.18em;
             font-size: 13px; text-transform: uppercase; color:#936126; }
    h1 { font-family: Georgia, "Times New Roman", serif; font-size: 27px; line-height:1.25;
         margin: 14px 0 10px; font-weight: 600; }
    p { font-size: 15.5px; line-height: 1.6; margin: 0 0 14px; }
    .card { background:#fff; border:1px solid #f0d9bb; border-radius:14px; padding: 20px 22px; }
    .phone { font-weight: 700; color:#933310; white-space: nowrap; }
    ul { margin: 0 0 14px 18px; padding:0; font-size: 15px; line-height:1.6; }
    a.btn { display:inline-block; margin-top:4px; padding: 10px 18px; border-radius: 10px;
            background:#933310; color:#fff; text-decoration:none; font-size: 14.5px; }
    a.plain { color:#936126; }
    .note { font-size: 13px; color:#8c8279; margin-top: 18px; }
</style>
</head>
<body>
<div class="wrap">
    <div class="brand">Marle Hotel &amp; Restaurant</div>
    <h1>Our system is temporarily unavailable</h1>
    <div class="card">
        <p>We can't reach our booking system at the moment, so we can't show you
           live availability or an existing booking right now.</p>
        <p><strong>Nothing is lost.</strong> Your booking and your status token are
           safe, and will be exactly as you left them once the system is back —
           usually within a few minutes.</p>
        <ul>
            <li>Wait a moment and reload this page.</li>
            <li>Already at the hotel? The front desk can help you directly.</li>
            <li>Need us now? Call <span class="phone">0917 000 0000</span>.</li>
        </ul>
        <p class="mb"><a class="btn" href="<?= $home ?>">Try the homepage again</a></p>
    </div>
    <p class="note">Error 503 — service unavailable. This page is shown instead of a
       technical error so you always know where you stand.</p>
</div>
</body>
</html>
<?php
        exit;
    }
}

if (!function_exists('marle_db_degraded_staff_page')) {
    /**
     * The staff console's 503, then exit. Self-contained for the same reason the
     * guest page is: the sidebar, the bell, the stylesheet and the language
     * switch all need the session or the database to render, and every one of
     * them would fail here — usually with a second error on top of this one.
     */
    function marle_db_degraded_staff_page(): void
    {
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 60');
            header('Content-Type: text/html; charset=utf-8');
        }

        // "Reload this screen" is the screen that was actually requested, taken
        // from SCRIPT_NAME — a value the server sets, not the client. The query
        // string is dropped: it is client input, and none of these screens need
        // it to come back up (adminDB.php re-reads ?months on its own).
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $here   = htmlspecialchars($script !== '' ? $script : '/', ENT_QUOTES);
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Marle Hotel — staff system temporarily unavailable</title>
<style>
    :root { color-scheme: light; }
    body { margin:0; background:#f4efe9; color:#3d3020;
           font-family: "DM Sans", -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
    .bar { background:#933310; color:#fff; padding: 10px 22px;
           font-family: Georgia, "Times New Roman", serif; font-size: 13px;
           letter-spacing:.18em; text-transform: uppercase; }
    .wrap { max-width: 680px; margin: 0 auto; padding: 34px 22px 56px; }
    h1 { font-family: Georgia, "Times New Roman", serif; font-size: 26px; line-height:1.25;
         margin: 10px 0 6px; font-weight: 600; }
    .who { font-size: 12.5px; letter-spacing:.08em; text-transform:uppercase; color:#8c8279; }
    p { font-size: 15.5px; line-height: 1.6; margin: 0 0 14px; }
    .card { background:#fff; border:1px solid #f0d9bb; border-left:5px solid #ffb60a;
            border-radius:14px; padding: 20px 22px; margin-top: 16px; }
    .card h2 { font-size: 15.5px; margin: 0 0 8px; font-weight: 700; }
    ul { margin: 0 0 14px 18px; padding:0; font-size: 15px; line-height:1.6; }
    li { margin-bottom: 7px; }
    .keep { background:#fdf6e8; border:1px solid #f0d9bb; border-radius:10px;
            padding: 12px 14px; font-size: 14.5px; line-height:1.55; margin: 0 0 16px; }
    a.btn { display:inline-block; padding: 10px 18px; border-radius: 10px;
            background:#933310; color:#fff; text-decoration:none; font-size: 14.5px; }
    .note { font-size: 13px; color:#8c8279; margin-top: 20px; }
    code { background:#f1ece6; border-radius:5px; padding:1px 5px; font-size:13px; }
</style>
</head>
<body>
<div class="bar">Marle Hotel &amp; Restaurant — staff console</div>
<div class="wrap">
    <div class="who">Error 503 — service unavailable</div>
    <h1>No screen can load: the hotel's database is not reachable</h1>
    <p>The staff system cannot reach the database, so this screen — and every other
       one — cannot show live data. This is the same outage guests are seeing on the
       website; it is not something wrong with your account or this device.</p>

    <div class="card">
        <h2>Nothing was saved</h2>
        <p class="keep">Whatever this screen was doing stopped <strong>before</strong> it
           reached the database, so nothing here is half-recorded and no booking,
           payment or request has been changed by it. Anything that was typed in and
           not yet saved has to be entered again once the system is back.</p>
        <ul>
            <li>Wait a moment and <a href="<?= $here ?>">reload this screen</a>.</li>
            <li>While this is showing, guests cannot be looked up, checked in, billed or
                messaged from the system. Record what you take outside it and enter it
                when the system is back.</li>
            <li>Reloads will keep answering this page until the database is reachable
                again — it is not a retry that sometimes works.</li>
            <li>If it lasts more than a few minutes, tell the owner.</li>
        </ul>
        <p><a class="btn" href="<?= $here ?>">Try this screen again</a></p>
    </div>

    <p class="note">Your sign-in is still valid: this page is shown instead of a
       technical error, and it reloads the screen you asked for —
       <code><?= $here ?></code>. Nothing needs to be re-entered except what was in
       that form.</p>
</div>
</body>
</html>
<?php
        exit;
    }
}

if (!function_exists('marle_db_degraded_json')) {
    /** The endpoint-facing 503: JSON a client can still parse, then exit. */
    function marle_db_degraded_json(string $audience = 'guest'): void
    {
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 60');
            header('Content-Type: application/json; charset=utf-8');
        }
        // "nothing was saved" is what makes the staff message actionable: a
        // failed POST here is indistinguishable from a slow one in the browser,
        // and a staff member who assumes it landed will not enter it again.
        $error = $audience === 'staff'
            ? 'The hotel system is temporarily unavailable — nothing was saved. Please try again in a moment.'
            : 'The hotel system is temporarily unavailable. Please try again in a moment.';
        echo json_encode([
            'success'     => false,
            'ok'          => false,
            'error'       => $error,
            'retry_after' => 60,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('marle_db_guard')) {
    /**
     * Stop here, gracefully, when there is no database.
     *
     * @param mixed  $conn      the value dbconnect.php produced
     * @param string $mode      'page' (HTML, the default) or 'json'
     * @param string $audience  'guest' (the default) or 'staff'
     */
    function marle_db_guard($conn, string $mode = 'page', string $audience = 'guest'): void
    {
        if (marle_db_available($conn)) {
            return;
        }
        if ($mode === 'json') {
            marle_db_degraded_json($audience);
        }
        if ($audience === 'staff') {
            marle_db_degraded_staff_page();
        }
        marle_db_degraded_page();
    }
}
