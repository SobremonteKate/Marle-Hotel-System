<?php
// ============================================================
//  superadmin/email_test.php — EMAIL CHECK (owner only)
//
//  WHY THIS PAGE EXISTS
//    "We did not receive the email" has four very different causes, and from
//    the outside they all look the same: the mailer is not configured, the API
//    key is wrong, the server cannot reach the provider, or the provider
//    accepted the message and the recipient's mailbox filed it somewhere.
//    Without this page the only evidence was a line in Apache's error_log,
//    which shared hosting makes hard to read and which is empty when the
//    request never left the server.
//
//  WHAT IT DOES
//    1. Shows the configuration the app ACTUALLY resolved (env vars beat
//       config.php), with the key masked — never printed.
//    2. "Check the connection" asks the provider whether the key works, which
//       sender is verified and how many credits are left. Nothing is sent.
//    3. "Send a test email" sends one real message through the same
//       marle_send_email() the bookings use, and prints the provider's own
//       answer: HTTP status, message id, or its error text.
//
//  It is also runnable from the command line, which is what makes it usable on
//  a host with no shell or when the staff login is the thing that is broken:
//
//      php superadmin/email_test.php
//      php superadmin/email_test.php --to=owner@example.com
//
//  Owner (superadmin) only — the same capability as the System Log, because a
//  test message proves who owns the sending account.
// ============================================================

$marleEmailIsCli = (PHP_SAPI === 'cli');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/send_email.php';
require_once __DIR__ . '/../includes/server_clock.php';   // the hotel's clock, not PHP's

if ($marleEmailIsCli) {
    // ── Command line: no session, no database, no HTML ──────────────────────
    $cliTo = '';
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if (strpos($arg, '--to=') === 0) {
            $cliTo = trim(substr($arg, 5));
        }
    }

    $config = marle_email_config();
    echo "Provider : ", ($config['provider'] !== '' ? $config['provider'] : '(none)'),
        '  (from ', $config['sources']['provider'] ?? 'unknown', ')', PHP_EOL;
    echo "From     : ", ($config['from'] !== '' ? $config['from'] : '(none)'),
        '  (from ', $config['sources']['from'] ?? 'unknown', ')', PHP_EOL;
    echo "Key      : ", marle_email_mask_key($config['api_key']),
        '  (from ', $config['sources']['api_key'] ?? 'unknown', ')', PHP_EOL;
    echo "Config   : ", (realpath(__DIR__ . '/../config.php') ?: '?'), PHP_EOL;
    echo "Hotel    : ", marle_email_notify_to(), PHP_EOL;
    echo "cURL     : ", (function_exists('curl_init') ? 'available' : 'MISSING'), PHP_EOL;
    echo "Config   : ", ($config['configured'] ? 'usable' : 'NOT usable — ' . $config['problem']), PHP_EOL;

    echo PHP_EOL, "Connection check (nothing sent):", PHP_EOL;
    $handshake = marle_email_handshake();
    foreach ($handshake['checks'] as $check) {
        printf("  [%s] %-32s %s%s", $check['ok'] ? 'PASS' : 'FAIL', $check['name'], $check['detail'], PHP_EOL);
    }
    if ($handshake['checks'] === []) {
        echo "  [FAIL] ", $handshake['problem'] ?: 'no checks ran', PHP_EOL;
    }

    if ($cliTo === '') {
        echo PHP_EOL, "No test message sent. Add --to=you@example.com to send one.", PHP_EOL;
        exit(0);
    }

    $body = marle_email_template(
        'Email test',
        '#933310',
        ['This message came from <strong>superadmin/email_test.php</strong>. If you can read it, the site can send mail.'],
        ['Sent at' => marle_db_now()],
        null,
        'No booking was created or changed by this test.'
    );
    $result = [];
    $sent = marle_send_email($cliTo, '', 'Marle Hotel — email test', $body, '', null, $result);
    echo PHP_EOL, 'Test message: ', ($sent ? 'ACCEPTED by the provider' : 'FAILED'), PHP_EOL;
    echo '  HTTP status : ', $result['status'] ?: '(none)', PHP_EOL;
    if ($result['message_id'] !== '') { echo '  Message id  : ', $result['message_id'], PHP_EOL; }
    if ($result['detail'] !== '')     { echo '  Detail      : ', $result['detail'], PHP_EOL; }
    if ($result['error'] !== '')      { echo '  Transport   : ', $result['error'], PHP_EOL; }
    echo '  Took        : ', $result['ms'], ' ms', PHP_EOL;
    exit($sent ? 0 : 1);
}

include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.systemlog');

include('../superadmin/auth.php');
require_capability('view_audit_log');
require_once __DIR__ . '/../includes/log_action.php';
include('../csrf.php');

$marleNavActive = 'systemlog';

// ── Mask a secret so it can be described without being disclosed ────────────
function marle_email_mask_key(string $key): string
{
    if ($key === '') {
        return '(empty)';
    }
    $tail = substr($key, -4);
    $head = substr($key, 0, 6);
    return $head . str_repeat('…', 6) . $tail . '  (' . strlen($key) . ' characters)';
}

$action   = '';
$testTo   = marle_email_notify_to();
$sendResult = null;
$handshake  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'check') {
        $handshake = marle_email_handshake();
        log_event($conn, 'email_check', 'system', null, $handshake['ok'] ? 'success' : 'failure',
            'Email connection check from the owner screen');
    } elseif ($action === 'send') {
        $testTo = trim((string)($_POST['to'] ?? ''));
        $body = marle_email_template(
            'Email test',
            '#933310',
            ['This message came from the <strong>Email check</strong> screen in the admin panel. If you can read it, the site can send mail.'],
            ['Sent at' => marle_db_now($conn), 'Sent to' => $testTo],
            null,
            'No booking was created or changed by this test.'
        );
        $sendResult = [];
        $sent = marle_send_email($testTo, '', 'Marle Hotel — email test', $body, '', null, $sendResult);
        log_event($conn, 'email_test_sent', 'system', null, $sent ? 'success' : 'failure',
            'Test email to ' . $testTo . ' — HTTP ' . $sendResult['status']);
    }
}

$config  = marle_email_config();
$notify  = marle_email_notify_to();
$curlOk  = function_exists('curl_init');

// ── Warnings: the mistakes that make a working key deliver nothing ──────────
$warnings = [];
$configPath  = realpath(__DIR__ . '/../config.php') ?: (__DIR__ . '/../config.php');
$configStamp = '';
if (($mtime = @filemtime($configPath)) !== false) {
    // Printed in the hotel's own time, because the point of this line is a
    // comparison: "I saved config.php at 18:55, so a stamp saying 12:55 means the
    // page read a different file." PHP's date() would answer in the server's
    // timezone (Europe/Berlin on the XAMPP box), six hours away from the clock
    // the File Manager shows.
    $offset  = marle_server_clock_offset_minutes();
    $stampDt = new DateTime('@' . $mtime);
    $stampDt->setTimezone(new DateTimeZone(sprintf('%+03d:%02d', intdiv($offset, 60), abs($offset % 60))));
    $configStamp = $stampDt->format('Y-m-d H:i');
}

if (!$config['configured']) {
    $warnings[] = $config['problem'];
    if (($config['sources']['provider'] ?? '') === 'not set') {
        $warnings[] = 'The config.php named below does not define MARLE_EMAIL_PROVIDER at all, so it is an older copy — not the file your email lines were added to. A zip extracted with "keep existing files" does exactly this (it protects the database password), and the only symptom is this screen. Add the four email lines to the file shown below, or upload the current config.php and put the database password back.';
    }
}

$fromEmail = $config['from'];
if (preg_match('/<([^>]+)>\s*$/', $fromEmail, $m)) {
    $fromEmail = trim($m[1]);
}
$fromDomain = strtolower((string)substr((string)strrchr('@' . $fromEmail, '@'), 1));

if ($fromEmail !== '' && in_array($fromDomain, ['gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com'], true)) {
    $warnings[] = 'The sender is a free mailbox (' . $fromEmail . '). Gmail, Yahoo and Outlook publish DMARC rules that reject or spam mail sent "from" their addresses by another server — send from noreply@marlehotel.site instead, which is verified in Brevo.';
}

if (preg_match('/^(no-?reply|do-?not-?reply|donotreply)@/i', $notify)) {
    $warnings[] = 'MARLE_EMAIL_NOTIFY_TO is ' . $notify . ' — a noreply address is not an inbox. New-booking and cancellation alerts sent there are never read (and usually bounce). Use the real hotel inbox, e.g. frontdesk@marlehotel.example.';
}
if ($notify !== '' && strcasecmp($notify, $fromEmail) === 0) {
    $warnings[] = 'MARLE_EMAIL_NOTIFY_TO is the same address the mail is sent FROM. Alerts then go to the sending mailbox, not to a person.';
}
if (!$curlOk) {
    $warnings[] = 'PHP cURL is disabled on this server. The mailer uses it for every provider, so no email can leave until the extension is enabled.';
}
if (defined('MARLE_BASE_URL') && MARLE_BASE_URL !== '' && strpos(MARLE_BASE_URL, 'https://') !== 0) {
    $warnings[] = 'MARLE_BASE_URL is not https:// — links inside guest emails will point at the wrong scheme.';
}
if ($config['configured'] && strpos($config['provider'], 'brevo') === 0 && $fromEmail !== '' && $fromDomain !== '') {
    // Not fatal: a sender can be verified on an unrelated domain. Noted only
    // when the sender domain looks like a placeholder.
    if (strpos($fromDomain, 'yourdomain') !== false) {
        $warnings[] = 'The sender still uses the placeholder domain "yourdomain.com".';
    }
}
?>
<!DOCTYPE html>
<html lang="<?= ui_html_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-4Q6Gf2aSP4eDXB8Miphtr37CMZZQ5oXLH2yaXMJ2w8e2ZtHTl7GptT4jmndRuHDT" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
    <link rel="stylesheet" href="../css/staff-footer.css?v=20261021">
    <?php require __DIR__ . '/../includes/staff_icons.php'; ?>
    <title>Email check — Marle Hotel</title>
    <style>
        body { background-color: #f2ece9; overflow-x: hidden; }
        @media (min-width: 768px) {
            .content { margin-left: var(--sidebar-w); margin-top: 1.5rem; }
        }
        .panel {
            background: #fff; border-radius: 20px; padding: 24px;
            width: min(calc(100% - var(--sidebar-w) - 40px), 1000px);
            margin: 20px 20px 20px calc(var(--sidebar-w) + max(20px, (100% - var(--sidebar-w) - 1000px) / 2));
        }
        @media (max-width: 767px) { .panel { margin: 12px; width: calc(100% - 24px); } }
        .panel h4 { color: #933310; font-weight: 700; }
        .kv { font-size: 0.86rem; }
        .kv th { color: #6c757d; font-weight: 500; width: 34%; }
        .kv td { font-family: ui-monospace, Consolas, monospace; word-break: break-all; }
        .btn-brand { background: #933310; color: #fff; border-radius: 12px; border: 1px solid #933310; }
        .btn-brand:hover { background: transparent; color: #933310; }
        .btn-outline-brand { color: #933310; border: 1px solid #933310; border-radius: 12px; }
        .btn-outline-brand:hover { background: #933310; color: #fff; }
        /* Touch floor: the test address field and the connection button were
           38px and 36px. */
        .btn-outline-brand, .form-control { min-height: 40px; }
        .check-row { display: flex; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f0e9e4; font-size: 0.88rem; }
        .check-row:last-child { border-bottom: 0; }
        .check-name { min-width: 210px; font-weight: 600; color: #352414; }
        .verdict { border-radius: 14px; padding: 16px 18px; }
        .verdict-ok { background: #eef7ee; border: 1px solid #cfe6cf; }
        .verdict-bad { background: #fdf0ef; border: 1px solid #f3cfcb; }
        code.inline { background: #f7f3ef; padding: 2px 6px; border-radius: 6px; }
    </style>
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>
<body class="staff-page staff-superadmin staff-log-page">

<?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

<?php
    $staffPageTitle  = 'Email check';
    $staffPageNote   = 'Owner-only diagnostics for the outbound email provider.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['Email check', null]];
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

<div class="panel">

    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
        <div>
            <h4 class="mb-1"><i class="bi bi-envelope-paper me-2"></i>Can this site send email?</h4>
            <p class="text-muted mb-0" style="font-size:0.9rem;">
                Booking confirmations, cancellation notices and hotel alerts all leave through
                <code class="inline">marle_send_email()</code>. This screen shows what the app resolved,
                then proves the provider accepts it — without creating or changing a booking.
            </p>
        </div>
    </div>

    <?php if ($warnings): ?>
        <div class="alert alert-warning" role="alert">
            <strong class="d-block mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Fix these first</strong>
            <ul class="mb-0" style="font-size:0.88rem;">
                <?php foreach ($warnings as $warning): ?>
                    <li><?= htmlspecialchars($warning) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <table class="table kv align-middle mb-4">
        <tbody>
            <tr><th>Provider</th><td><?= htmlspecialchars($config['provider'] !== '' ? $config['provider'] : '(none selected)') ?>
                <span class="text-muted" style="font-family:inherit;"> — from <?= htmlspecialchars($config['sources']['provider'] ?? 'unknown') ?></span></td></tr>
            <tr><th>API key</th><td><?= htmlspecialchars(marle_email_mask_key($config['api_key'])) ?>
                <span class="text-muted" style="font-family:inherit;"> — from <?= htmlspecialchars($config['sources']['api_key'] ?? 'unknown') ?></span></td></tr>
            <tr><th>From (what guests see)</th><td><?= htmlspecialchars($config['from'] !== '' ? $config['from'] : '(not set)') ?>
                <span class="text-muted" style="font-family:inherit;"> — from <?= htmlspecialchars($config['sources']['from'] ?? 'unknown') ?></span></td></tr>
            <tr><th>config.php read</th><td><?= htmlspecialchars($configPath) ?><br>
                <span class="text-muted" style="font-family:inherit;">last changed <?= $configStamp !== '' ? htmlspecialchars($configStamp) : 'unknown' ?></span></td></tr>
            <tr><th>Hotel inbox (alerts + replies)</th><td><?= htmlspecialchars($notify) ?></td></tr>
            <tr><th>Links in emails</th><td><?= htmlspecialchars(marle_base_url()) ?></td></tr>
            <tr><th>PHP cURL</th><td><?= $curlOk ? 'available' : 'MISSING — no provider can be reached' ?></td></tr>
            <tr><th>Status</th><td>
                <?php if ($config['configured']): ?>
                    <span class="badge bg-success">Configured</span>
                <?php else: ?>
                    <span class="badge bg-danger">Not configured</span> <?= htmlspecialchars($config['problem']) ?>
                <?php endif; ?>
            </td></tr>
        </tbody>
    </table>

    <div class="row g-3">
        <div class="col-md-5">
            <div class="border rounded-4 p-3 h-100">
                <h6 class="fw-bold mb-1">1. Check the connection</h6>
                <p class="text-muted mb-3" style="font-size:0.84rem;">
                    Asks the provider whether the key works, whether the From address is verified,
                    and how many messages are left today. Sends nothing.
                </p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="check">
                    <button type="submit" class="btn btn-outline-brand w-100">
                        <i class="bi bi-plug me-1"></i>Check the connection
                    </button>
                </form>
            </div>
        </div>

        <div class="col-md-7">
            <div class="border rounded-4 p-3 h-100">
                <h6 class="fw-bold mb-1">2. Send one real test email</h6>
                <p class="text-muted mb-3" style="font-size:0.84rem;">
                    Goes through the same code path as a booking confirmation. Use an address you can
                    open right now — and check its spam folder too.
                </p>
                <form method="POST" class="d-flex gap-2 flex-wrap">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="send">
                    <input type="email" name="to" class="form-control" style="max-width:340px;"
                        value="<?= htmlspecialchars($testTo) ?>" required>
                    <button type="submit" class="btn btn-brand">
                        <i class="bi bi-send me-1"></i>Send test
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php if ($handshake !== null): ?>
        <div class="mt-4">
            <h6 class="fw-bold mb-2">Connection check — result</h6>
            <div class="verdict <?= $handshake['ok'] ? 'verdict-ok' : 'verdict-bad' ?>">
                <?php if ($handshake['checks'] === []): ?>
                    <strong>Nothing could be checked.</strong>
                    <?= htmlspecialchars($handshake['problem'] ?: 'The configuration is incomplete.') ?>
                <?php else: ?>
                    <?php foreach ($handshake['checks'] as $check): ?>
                        <div class="check-row">
                            <span class="check-name">
                                <?= $check['ok'] ? '<i class="bi bi-check-circle-fill text-success me-1"></i>' : '<i class="bi bi-x-circle-fill text-danger me-1"></i>' ?>
                                <?= htmlspecialchars($check['name']) ?>
                            </span>
                            <span><?= htmlspecialchars($check['detail']) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($sendResult !== null): ?>
        <div class="mt-4">
            <h6 class="fw-bold mb-2">Test email — result</h6>
            <div class="verdict <?= $sendResult['ok'] ? 'verdict-ok' : 'verdict-bad' ?>">
                <p class="mb-2" style="font-size:0.95rem;">
                    <?php if ($sendResult['ok']): ?>
                        <strong><i class="bi bi-check-circle-fill text-success me-1"></i>The provider accepted the message.</strong>
                        It left this server at <?= htmlspecialchars(substr(marle_db_now($conn), 11)) ?> and is on its way to
                        <strong><?= htmlspecialchars($testTo) ?></strong>.
                        <br><span style="font-size:0.84rem;">Accepted is not the same as delivered: Brevo answers
                        <em>before</em> the receiving mailbox has spoken, so a bad sender or a hard bounce is only
                        visible in its logs (see below).</span>
                    <?php else: ?>
                        <strong><i class="bi bi-x-circle-fill text-danger me-1"></i>The message was not accepted.</strong>
                        Nothing was delivered, so nothing is waiting in a spam folder.
                    <?php endif; ?>
                </p>

                <table class="table kv mb-2">
                    <tbody>
                        <tr><th>HTTP status</th><td><?= (int)$sendResult['status'] ?: '(no response)' ?></td></tr>
                        <?php if ($sendResult['message_id'] !== ''): ?>
                            <tr><th>Provider message id</th><td><?= htmlspecialchars($sendResult['message_id']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($sendResult['detail'] !== ''): ?>
                            <tr><th>Provider says</th><td><?= htmlspecialchars($sendResult['detail']) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($sendResult['error'] !== ''): ?>
                            <tr><th>Transport error</th><td><?= htmlspecialchars($sendResult['error']) ?></td></tr>
                        <?php endif; ?>
                        <tr><th>Took</th><td><?= (int)$sendResult['ms'] ?> ms</td></tr>
                    </tbody>
                </table>

                <?php if ($sendResult['ok']): ?>
                    <p class="mb-0" style="font-size:0.84rem;">
                        If it is not in the inbox within a couple of minutes, look in <strong>Spam</strong>, then in
                        Brevo → <em>Transactional</em> → <em>Logs</em>, which shows whether the receiving mailbox
                        accepted it, deferred it, bounced it — or whether Brevo itself refused it (for example
                        <em>“the sender you used is not valid”</em>, which is what an unverified From address looks
                        like). The connection check above catches that case before you send.
                    </p>
                <?php else: ?>
                    <p class="mb-0" style="font-size:0.84rem;">
                        <strong>401 / 403</strong> means the API key is wrong or was revoked — create a new one in
                        Brevo → SMTP &amp; API → API Keys and paste it into <code class="inline">config.php</code>.
                        <strong>Status 0</strong> means this server could not reach the provider at all.
                        <strong>400</strong> is usually the sender or the recipient — read the provider's message above.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <p class="text-muted mt-4 mb-0" style="font-size:0.8rem;">
        The key is shown masked on purpose — this screen never prints a secret. Results are recorded in the
        System Log. The same checks run from a terminal with
        <code class="inline">php superadmin/email_test.php --to=you@example.com</code>.
    </p>
</div>

<?php $marleFooterTerms = true; require __DIR__ . '/../includes/staff_footer.php'; ?>

<!-- #logoutConfirmModal is required by includes/staff_sidebar.php -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Logout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center"><p class="fs-5">Are you sure you want to log out?</p></div>
            <div class="modal-footer">
                <button type="button" id="noBtn" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="?action=logout" class="btn" id="yesBtn" style="background:#ffb60a;color:#fff;">Yes</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-j1CDi7MgGQ12Z7Qab0qlWQ/Qqz24Gc6BM0thvEMVjHnfYGF0rmFCozFSxQBxwHKO" crossorigin="anonymous"></script>
</body>
</html>
