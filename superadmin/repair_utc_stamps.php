<?php
// ============================================================
//  superadmin/repair_utc_stamps.php — the eight hours the host lost
//
//  WHY THIS EXISTS
//    Until 2026-10-29 the live host ran with no time zone set at all: the
//    deployed config.php predated the clock fix, its .user.ini had no
//    date.timezone, and Hostinger's MySQL runs on UTC. So every DATETIME the
//    database wrote for itself — and every stamp PHP wrote with date() — is
//    exactly 480 minutes behind Philippine time. A chat message sent at 23:24
//    was stored as 15:24. includes/db_clock.php now pins every connection to
//    +08:00, which fixes everything written from now on, but a DATETIME column
//    keeps the bytes it was given: the old rows stay eight hours early until
//    someone shifts them. This page is that someone.
//
//  WHAT IT TOUCHES — AND WHAT IT REFUSES TO TOUCH
//    Only DATETIME columns that hold a *server-written stamp* are shifted
//    (chat stamps, token issued/expiry, deposit and cancellation stamps,
//    archive stamps, collection stamps). Three groups are deliberately left
//    alone, and the reason matters more than the list:
//
//    1. TIMESTAMP columns. MySQL stores those as a UTC instant and converts
//       them for the session, so while the host was on UTC they recorded the
//       *correct* instant; the moment the session was pinned they started
//       reading back as Manila time on their own. Shifting them would break the
//       one thing that was already right — bookings.created_at, requests.created_at,
//       notifications.created_at, request_replies.sent_at, audit_log and the rest.
//
//    2. Stay dates and business times — calendartbl.start/end,
//       customerstbl.date_from/date_to. Those are typed by a person ("check in
//       the 14th"), stored literally, and were never on the server's clock.
//
//    3. userstbl.locked_until / last_failed_at. These are transient security
//       state: a lock written eight hours early has already expired, and adding
//       eight hours would re-lock an account that is open right now.
//
//    The *_date_fix_* and *_backup_* tables and audit_log are the record of
//    earlier repairs; they are evidence, and evidence is not edited.
//
//  HOW IT IS SAFE
//    · Dry run by default. Nothing is written until you press Apply, and the
//      dry run lists every affected column with its row count and samples.
//    · It refuses to run at all unless this request's own database session is
//      pinned to +08:00 — shifting rows while the host is still on UTC would
//      make the data worse, not better.
//    · It only touches rows between two explicit timestamps: the day the site
//      went live (anything older was imported from the staging database and is
//      already Manila time) and the moment the clock fix was uploaded (anything
//      newer is already correct).
//    · Every old value is copied into a backup table inside the same
//      transaction as the update, and Undo puts them back.
//    · A second Apply would shift the same rows twice, so an overlapping run is
//      reported and has to be acknowledged explicitly.
//
//  RUNNING IT
//    Browser: sign in as the owner and open  superadmin/repair_utc_stamps.php
//    Shell  : php superadmin/repair_utc_stamps.php --from=2026-10-18 --until=2026-10-29
//             php superadmin/repair_utc_stamps.php --apply --from=… --until=…
//             php superadmin/repair_utc_stamps.php --undo=stamp_repair_bk_…
//
//    Delete this file once the repair is done. It is a one-off, not a screen.
// ============================================================

$marleRepairIsCli = (PHP_SAPI === 'cli');

if (!$marleRepairIsCli) {
    require_once __DIR__ . '/auth.php';            // superadmin only
    require_once __DIR__ . '/../csrf.php';
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db_guard.php';
require_once __DIR__ . '/../includes/log_action.php';
require_once __DIR__ . '/../includes/server_clock.php';   // marle_db_now(): the clock the stamps are in

/** The host's lost offset, in minutes. Asia/Manila is UTC+8 all year. */
$MARLE_SHIFT_MINUTES = 480;

/**
 * Every column this repair is allowed to move: table, primary key, column, and
 * the sentence that says why it is a stamp rather than a business date.
 */
$marleStampColumns = [
    ['chat_messages', 'id', 'sent_at', 'the time on every chat line'],
    ['chat_messages', 'id', 'read_at', 'when the desk read it'],
    ['chat_messages', 'id', 'archived_at', 'when the thread was archived'],
    ['chat_room_status', 'room_number', 'first_opened_at', 'when the desk opened the room'],
    ['chat_room_status', 'room_number', 'completed_at', 'when the room chat was closed'],
    ['guest_tokens', 'id', 'created_at', 'when a portal token was issued'],
    ['guest_tokens', 'id', 'expires_at', 'the portal session deadline'],
    ['guest_tokens_history', 'id', 'issued_at', 'a retired token\'s issuing time'],
    ['guest_tokens_history', 'id', 'expires_at', 'a retired token\'s deadline'],
    ['guest_tokens_history', 'id', 'moved_at', 'when the token was retired'],
    ['bookings', 'id', 'terms_accepted_at', 'when the guest accepted the policy'],
    ['bookings', 'id', 'deposit_submitted_at', 'when the proof was uploaded'],
    ['bookings', 'id', 'deposit_verified_at', 'when the desk confirmed the deposit'],
    ['bookings', 'id', 'cancelled_at', 'when the booking was cancelled'],
    ['bookings', 'id', 'archived_at', 'when the booking was archived'],
    ['customerstbl', 'id', 'checked_out_at', 'when the guest checked out'],
    ['customerstbl', 'id', 'discount_applied_at', 'when the discount was applied'],
    ['requests', 'id', 'archived_at', 'when the request was archived'],
    ['extension_history', 'id', 'archived_at', 'when the extension was archived'],
    ['financial_adjustments', 'id', 'approved_at', 'when the adjustment was approved'],
    ['financial_adjustments', 'id', 'applied_at', 'when it was applied to the bill'],
    ['transaction_collections', 'id', 'collected_at', 'when the money was collected'],
];

/** Columns the page names explicitly, so the reason for leaving them is visible. */
$marleLeftAlone = [
    'TIMESTAMP columns' => 'bookings.created_at, requests.created_at, notifications.created_at, request_replies.sent_at, audit_log.created_at and every other TIMESTAMP — they stored a UTC instant all along and corrected themselves when the session was pinned.',
    'Stay dates' => 'calendartbl.start/end, customerstbl.date_from/date_to, archivetbl.date_from/date_to — typed by a person, never on the server\'s clock.',
    'Login state' => 'userstbl.locked_until, last_failed_at — transient; a lock written eight hours early has already expired, and shifting it would re-lock an open account.',
    'Repair records' => 'the *_date_fix_* and *_backup_* tables and audit_log — evidence of earlier repairs.',
    'Recomputed caches' => 'review_sentiment_cache.analyzed_at, review_sentiment_results.processed_at — rebuilt on demand, never shown as a date.',
];

// ── Small helpers ───────────────────────────────────────────────────────────

function marle_repair_stamp(?string $value): string
{
    return is_string($value) && $value !== '' ? $value : '—';
}

function marle_repair_shift(?string $value, int $minutes): string
{
    if (!is_string($value) || $value === '') {
        return '—';
    }
    try {
        // Parsed and formatted in the hotel's zone on purpose: the value is a wall
        // clock with no offset of its own, so the shift is pure arithmetic on it.
        $dt = new DateTimeImmutable($value, new DateTimeZone(MARLE_HOTEL_TZ));
    } catch (Throwable $e) {
        return $value;
    }
    return $dt->modify(($minutes >= 0 ? '+' : '') . $minutes . ' minutes')->format('Y-m-d H:i:s');
}

/** The offset this request's own session is actually on, in minutes. */
function marle_repair_session_offset(mysqli $conn): ?int
{
    $res = @$conn->query('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS off');
    $row = $res ? $res->fetch_assoc() : null;
    return ($row && $row['off'] !== null) ? (int) $row['off'] : null;
}

function marle_repair_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();
    return $n > 0;
}

function marle_repair_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT DATA_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row !== null;
}

/**
 * What would change, per column: how many rows sit in the window, and what the
 * first few of them read before and after. Read-only.
 */
function marle_repair_plan(mysqli $conn, string $from, string $until, array $columns, int $shift): array
{
    $plan = [];
    foreach ($columns as [$table, $pk, $column, $why]) {
        $entry = [
            'table' => $table, 'pk' => $pk, 'column' => $column, 'why' => $why,
            'count' => 0, 'samples' => [], 'skipped' => null,
        ];

        if (!marle_repair_table_exists($conn, $table) || !marle_repair_column_exists($conn, $table, $column)) {
            $entry['skipped'] = 'not in this database';
            $plan[] = $entry;
            continue;
        }

        $sql = "SELECT `$pk` AS pk, `$column` AS v FROM `$table`
                 WHERE `$column` IS NOT NULL AND `$column` BETWEEN ? AND ?
                 ORDER BY `$column` ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $entry['skipped'] = 'could not read: ' . $conn->error;
            $plan[] = $entry;
            continue;
        }
        $stmt->bind_param('ss', $from, $until);
        $stmt->execute();
        $res = $stmt->get_result();
        $total = 0;
        while ($row = $res->fetch_assoc()) {
            $total++;
            if (count($entry['samples']) < 3) {
                $entry['samples'][] = [
                    'pk'      => (string) $row['pk'],
                    'before'  => (string) $row['v'],
                    'after'   => marle_repair_shift($row['v'], $shift),
                ];
            }
        }
        $stmt->close();
        $entry['count'] = $total;
        $plan[] = $entry;
    }
    return $plan;
}

/** Earlier runs whose window overlaps this one — a second Apply would shift twice. */
function marle_repair_previous_runs(mysqli $conn, string $from, string $until): array
{
    if (!marle_repair_table_exists($conn, 'stamp_repair_runs')) {
        return [];
    }
    $stmt = $conn->prepare(
        'SELECT backup_table, window_from, window_until, rows_changed, ran_at
           FROM stamp_repair_runs
          WHERE window_from <= ? AND window_until >= ?
          ORDER BY id DESC'
    );
    $stmt->bind_param('ss', $until, $from);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Shift every affected row by $shift minutes, with the old values kept.
 * DDL runs first on purpose: CREATE TABLE commits implicitly in MySQL, so it
 * cannot sit inside the transaction that has to stay atomic.
 */
function marle_repair_apply(mysqli $conn, string $from, string $until, array $plan, int $shift): array
{
    // Named from the database's clock, like everything else here.
    $nowDb   = marle_db_now($conn);
    $stamp   = str_replace(['-', ' ', ':'], ['', '_', ''], $nowDb);   // 20261029_000802
    $backup  = 'stamp_repair_bk_' . $stamp;
    $changed = [];
    $errors  = [];

    $conn->query(
        "CREATE TABLE IF NOT EXISTS stamp_repair_runs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            window_from DATETIME NOT NULL,
            window_until DATETIME NOT NULL,
            backup_table VARCHAR(64) NOT NULL,
            rows_changed INT UNSIGNED NOT NULL DEFAULT 0,
            ran_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $ok = $conn->query(
        "CREATE TABLE `$backup` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tbl VARCHAR(64) NOT NULL,
            pk_col VARCHAR(64) NOT NULL,
            pk_val VARCHAR(64) NOT NULL,
            col VARCHAR(64) NOT NULL,
            old_value DATETIME NOT NULL,
            restored TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY tbl_col (tbl, col)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if (!$ok) {
        return ['error' => 'could not create the backup table: ' . $conn->error];
    }

    $conn->begin_transaction();
    try {
        foreach ($plan as $entry) {
            if (!empty($entry['skipped']) || $entry['count'] === 0) {
                continue;
            }
            $table  = $entry['table'];
            $pk     = $entry['pk'];
            $column = $entry['column'];

            $copy = $conn->prepare(
                "INSERT INTO `$backup` (tbl, pk_col, pk_val, col, old_value)
                 SELECT ?, ?, CAST(`$pk` AS CHAR), ?, `$column`
                   FROM `$table`
                  WHERE `$column` IS NOT NULL AND `$column` BETWEEN ? AND ?"
            );
            $copy->bind_param('sssss', $table, $pk, $column, $from, $until);
            if (!$copy->execute()) {
                throw new RuntimeException("backup of $table.$column failed: " . $copy->error);
            }
            $saved = $copy->affected_rows;
            $copy->close();

            $upd = $conn->prepare(
                "UPDATE `$table`
                    SET `$column` = DATE_ADD(`$column`, INTERVAL ? MINUTE)
                  WHERE `$column` IS NOT NULL AND `$column` BETWEEN ? AND ?"
            );
            $upd->bind_param('iss', $shift, $from, $until);
            if (!$upd->execute()) {
                throw new RuntimeException("update of $table.$column failed: " . $upd->error);
            }
            $moved = $upd->affected_rows;
            $upd->close();

            $changed[] = ['table' => $table, 'column' => $column, 'saved' => $saved, 'moved' => $moved];
        }

        $total = 0;
        foreach ($changed as $c) {
            $total += $c['moved'];
        }

        $log = $conn->prepare(
            'INSERT INTO stamp_repair_runs (window_from, window_until, backup_table, rows_changed, ran_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $log->bind_param('sssis', $from, $until, $backup, $total, $nowDb);
        $log->execute();
        $log->close();

        $conn->commit();

        log_event($conn, 'stamp_repair_applied', 'system', null, 'success',
            'Shifted ' . $total . ' stamp(s) +' . $shift . ' minutes; backup ' . $backup);

        return ['backup' => $backup, 'changed' => $changed, 'total' => $total];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['error' => $e->getMessage()];
    }
}

/** Put every saved value back. Rows already restored are skipped, so it is repeatable. */
function marle_repair_undo(mysqli $conn, string $backup): array
{
    if (!preg_match('/^stamp_repair_bk_[0-9_]{1,32}$/', $backup)) {
        return ['error' => 'that is not a repair backup table'];
    }
    if (!marle_repair_table_exists($conn, $backup)) {
        return ['error' => 'no such backup table: ' . $backup];
    }

    $pairs = $conn->query("SELECT DISTINCT tbl, pk_col, col FROM `$backup` WHERE restored = 0")->fetch_all(MYSQLI_ASSOC);
    if (!$pairs) {
        return ['error' => 'nothing left to restore in ' . $backup];
    }

    $undone = [];
    $conn->begin_transaction();
    try {
        foreach ($pairs as $pair) {
            $table  = $pair['tbl'];
            $pk     = $pair['pk_col'];
            $column = $pair['col'];

            if (!marle_repair_table_exists($conn, $table) || !marle_repair_column_exists($conn, $table, $column)) {
                continue;
            }

            $sql = "UPDATE `$table` t
                       JOIN `$backup` b
                         ON b.tbl = ? AND b.col = ? AND b.pk_col = ? AND b.pk_val = CAST(t.`$pk` AS CHAR)
                        SET t.`$column` = b.old_value
                      WHERE b.restored = 0";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sss', $table, $column, $pk);
            $stmt->execute();
            $rows = $stmt->affected_rows;
            $stmt->close();

            $mark = $conn->prepare("UPDATE `$backup` SET restored = 1 WHERE tbl = ? AND col = ? AND pk_col = ? AND restored = 0");
            $mark->bind_param('sss', $table, $column, $pk);
            $mark->execute();
            $mark->close();

            $undone[] = ['table' => $table, 'column' => $column, 'restored' => $rows];
        }
        $conn->commit();

        log_event($conn, 'stamp_repair_undone', 'system', null, 'success', 'Restored from ' . $backup);
        return ['undone' => $undone];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['error' => $e->getMessage()];
    }
}

// ── Input: command line ─────────────────────────────────────────────────────

$cli = [];
if ($marleRepairIsCli) {
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
            $cli[$m[1]] = $m[2] ?? '1';
        }
    }
}

$conn = marle_db_connect();
if (!($conn instanceof mysqli)) {
    if ($marleRepairIsCli) {
        fwrite(STDERR, "Could not connect to the database.\n");
        exit(2);
    }
    marle_db_guard($conn, 'page', 'staff');
}

$defaultsFile = __DIR__ . '/../includes/db_clock.php';
$defaultUntil = is_file($defaultsFile)
    ? date('Y-m-d H:i:s', (int) filemtime($defaultsFile))   // when the fix arrived
    : marle_db_now($conn);                                 // no file: the database's own clock
$defaultFrom = '2026-10-18 00:00:00';                       // the day the site went live

$from  = trim((string) ($cli['from']  ?? ($_POST['from']  ?? ($_GET['from']  ?? $defaultFrom))));
$until = trim((string) ($cli['until'] ?? ($_POST['until'] ?? ($_GET['until'] ?? $defaultUntil))));

$fromTs  = strtotime($from);
$untilTs = strtotime($until);
$windowError = null;
if ($fromTs === false || $untilTs === false) {
    $windowError = 'Both timestamps need to be real dates, e.g. 2026-10-18 00:00:00.';
} elseif ($untilTs <= $fromTs) {
    $windowError = 'The cut-off has to come after the start of the window.';
} else {
    $from  = date('Y-m-d H:i:s', $fromTs);
    $until = date('Y-m-d H:i:s', $untilTs);
}

// ── The one precondition that cannot be apologised for ─────────────────────
$offset = marle_repair_session_offset($conn);
$clockReady = ($offset === $MARLE_SHIFT_MINUTES);

$plan        = ($windowError === null && $clockReady) ? marle_repair_plan($conn, $from, $until, $marleStampColumns, $MARLE_SHIFT_MINUTES) : [];
$previous    = ($windowError === null) ? marle_repair_previous_runs($conn, $from, $until) : [];
$acknowledged = !empty($cli['ack']) || !empty($_POST['acknowledged_overlap']);
$canApply    = ($windowError === null && $clockReady && (!$previous || $acknowledged));

$action = 'report';
if (!empty($cli['undo'])) {
    $action = 'undo';
} elseif (!empty($cli['apply'])) {
    $action = 'apply';
} elseif (!$marleRepairIsCli && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!empty($_POST['do']) && $_POST['do'] === 'undo') {
        $action = 'undo';
    } elseif (!empty($_POST['do']) && $_POST['do'] === 'apply') {
        $action = 'apply';
    }
}

$result = null;
$undoTarget = (string) ($_POST['backup'] ?? ($cli['undo'] ?? ''));

if ($action !== 'report' && !$marleRepairIsCli) {
    require_csrf_token();
}

if ($action === 'apply') {
    if ($windowError !== null) {
        $result = ['apply' => ['error' => 'The window is not valid, so nothing was changed.']];
    } elseif (!$clockReady) {
        $result = ['apply' => ['error' => 'This session is not on Philippine time, so nothing was changed.']];
    } elseif ($previous && !$acknowledged) {
        $result = ['apply' => ['error' => 'This window was already repaired (' . $previous[0]['backup_table']
            . '). Shifting it again would move the same rows twice — acknowledge the overlap first.']];
    } else {
        $result = ['apply' => marle_repair_apply($conn, $from, $until, $plan, $MARLE_SHIFT_MINUTES)];
    }
} elseif ($action === 'undo') {
    $result = ['undo' => marle_repair_undo($conn, $undoTarget)];
}

$totalRows = 0;
foreach ($plan as $entry) {
    $totalRows += (int) $entry['count'];
}

// ── Command line output ─────────────────────────────────────────────────────
if ($marleRepairIsCli) {
    echo "MARLE — repair of stamps written while the host was on UTC\n";
    echo str_repeat('=', 72), "\n";
    printf("Database       : %s\n", MARLE_DB_NAME);
    printf("Session offset : %s\n", $offset === null ? 'unknown' : ($offset > 0 ? '+' : '') . $offset . ' minutes');
    printf("Window         : %s  →  %s\n", $from, $until);
    printf("Shift          : +%d minutes\n\n", $MARLE_SHIFT_MINUTES);

    if ($windowError !== null) {
        echo "WINDOW ERROR: $windowError\n";
        exit(2);
    }
    if (!$clockReady) {
        echo "REFUSING: this session is at ", $offset === null ? 'an unknown offset' : $offset . ' minutes',
            ", not +$MARLE_SHIFT_MINUTES. Upload the clock fix first — shifting rows while the host is\n",
            "still on UTC would move them the wrong way.\n";
        exit(3);
    }

    if ($previous) {
        echo "ALREADY REPAIRED IN THIS WINDOW:\n";
        foreach ($previous as $run) {
            printf("  %s  %s → %s  %d row(s)  (%s)\n", $run['ran_at'], $run['window_from'], $run['window_until'], $run['rows_changed'], $run['backup_table']);
        }
        echo "  Applying again would shift the same rows twice. Add --ack to allow it.\n\n";
    }

    foreach ($plan as $entry) {
        $label = $entry['table'] . '.' . $entry['column'];
        if ($entry['skipped']) {
            printf("%-46s skipped (%s)\n", $label, $entry['skipped']);
            continue;
        }
        printf("%-46s %6d row(s)\n", $label, $entry['count']);
        foreach ($entry['samples'] as $s) {
            printf("      #%-6s %s  →  %s\n", $s['pk'], $s['before'], $s['after']);
        }
    }
    printf("\nTOTAL: %d value(s) in %d column(s)\n", $totalRows, count($plan));

    if ($result !== null) {
        echo "\n", str_repeat('-', 72), "\n";
        if (isset($result['apply'])) {
            $r = $result['apply'];
            if (isset($r['error'])) {
                echo "APPLY FAILED: ", $r['error'], "\n";
                exit(1);
            }
            printf("APPLIED: %d value(s) shifted. Backup table: %s\n", $r['total'], $r['backup']);
            foreach ($r['changed'] as $c) {
                printf("  %-46s %d\n", $c['table'] . '.' . $c['column'], $c['moved']);
            }
            printf("\nUndo with: php superadmin/repair_utc_stamps.php --undo=%s\n", $r['backup']);
        }
        if (isset($result['undo'])) {
            $r = $result['undo'];
            if (isset($r['error'])) {
                echo "UNDO FAILED: ", $r['error'], "\n";
                exit(1);
            }
            echo "UNDONE:\n";
            foreach ($r['undone'] as $u) {
                printf("  %-46s %d restored\n", $u['table'] . '.' . $u['column'], $u['restored']);
            }
        }
    } elseif ($action === 'report') {
        echo "\nDry run only — nothing was written. Add --apply to shift these rows.\n";
    }
    exit(0);
}

// ── Browser: the same report, on the console's own chrome ──────────────────
$staffPageTitle  = 'Clock repair';
$staffPageNote   = 'Shift the stamps the host wrote while it was running on UTC, once.';
$staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['Clock repair', null]];
$staffHeadBell   = false;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clock repair — Marle Hotel</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
<link rel="stylesheet" href="../css/staff-footer.css?v=20261021">
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
<style>
    body.staff-page .repair-note { font-size: 13px; color: var(--ink-3); }
    body.staff-page .repair-table { font-size: 13px; }
    body.staff-page .repair-table code { font-size: 12px; }
    body.staff-page .repair-samples { font-size: 12px; color: var(--ink-3); }
    body.staff-page .repair-card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 18px 20px; margin-bottom: 16px; }
    body.staff-page .repair-card h2 { font-size: 1rem; font-weight: 700; margin-bottom: 4px; }
    body.staff-page .repair-window { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
    body.staff-page .repair-window label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-3); }
    body.staff-page .repair-stop { border-left: 3px solid var(--danger); background: #fdf3f1; }
    body.staff-page .repair-ok { border-left: 3px solid var(--ok); background: #f2f8f4; }
</style>
</head>
<body class="staff-page staff-superadmin">

<?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>
<?php require __DIR__ . '/../includes/staff_page_head.php'; ?>

<main class="main-card" style="padding:20px;">

    <div class="repair-card">
        <h2>What this does</h2>
        <p class="repair-note mb-2">
            Until the clock fix was uploaded, the host wrote every timestamp on UTC, eight hours behind
            Philippine time. New rows are correct now; this page moves the old ones. Nothing is written
            until you press <strong>Apply</strong>, and every old value is copied to a backup table first.
        </p>
        <p class="repair-note mb-0">
            Only rows stamped between the two timestamps below are touched: older rows were imported from the
            staging database and are already Manila time, and newer rows were written after the fix.
        </p>
    </div>

    <div class="repair-card">
        <h2>Window</h2>
        <form method="get" class="repair-window">
            <div>
                <label for="from">Site went live</label>
                <input class="form-control form-control-sm" id="from" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES) ?>" size="20">
            </div>
            <div>
                <label for="until">Clock fix uploaded</label>
                <input class="form-control form-control-sm" id="until" name="until" value="<?= htmlspecialchars($until, ENT_QUOTES) ?>" size="20">
            </div>
            <button class="btn btn-sm btn-outline-secondary" type="submit">Refresh the dry run</button>
        </form>
        <p class="repair-note mt-2 mb-0">
            “Clock fix uploaded” is prefilled from the file time of <code>includes/db_clock.php</code>, which is a
            few minutes <em>before</em> the upload — timestamps written in those minutes are left alone rather than
            shifted twice. If you know the exact upload time, type it in.
        </p>
    </div>

    <?php if ($windowError !== null): ?>
    <div class="repair-card repair-stop">
        <h2>That window will not work</h2>
        <p class="mb-0"><?= htmlspecialchars($windowError, ENT_QUOTES) ?></p>
    </div>
    <?php endif; ?>

    <?php if (!$clockReady): ?>
    <div class="repair-card repair-stop">
        <h2>Refusing to run</h2>
        <p class="mb-0">
            This request's own database session is on
            <strong><?= $offset === null ? 'an unknown offset' : htmlspecialchars(($offset > 0 ? '+' : '') . $offset . ' minutes', ENT_QUOTES) ?></strong>,
            not <strong>+<?= $MARLE_SHIFT_MINUTES ?></strong>. Shifting rows while the host is still on UTC would move
            them the wrong way. Upload the clock fix (<code>includes/db_clock.php</code>, <code>dbconnect.php</code>,
            <code>.user.ini</code>) and reload this page.
        </p>
    </div>
    <?php endif; ?>

    <?php if ($result !== null && isset($result['apply'])): $r = $result['apply']; ?>
        <?php if (isset($r['error'])): ?>
        <div class="repair-card repair-stop">
            <h2>Nothing was changed</h2>
            <p class="mb-0"><?= htmlspecialchars($r['error'], ENT_QUOTES) ?></p>
        </div>
        <?php else: ?>
        <div class="repair-card repair-ok">
            <h2>Shifted <?= (int) $r['total'] ?> value(s)</h2>
            <p class="repair-note mb-2">Every old value is in <code><?= htmlspecialchars($r['backup'], ENT_QUOTES) ?></code>.</p>
            <table class="table table-sm repair-table mb-2">
                <thead><tr><th>Column</th><th class="text-end">Rows</th></tr></thead>
                <tbody>
                <?php foreach ($r['changed'] as $c): ?>
                    <tr><td><code><?= htmlspecialchars($c['table'] . '.' . $c['column'], ENT_QUOTES) ?></code></td><td class="text-end"><?= (int) $c['moved'] ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" onsubmit="return confirm('Put every value back the way it was?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <input type="hidden" name="do" value="undo">
                <input type="hidden" name="backup" value="<?= htmlspecialchars($r['backup'], ENT_QUOTES) ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Undo this repair</button>
            </form>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($result !== null && isset($result['undo'])): $r = $result['undo']; ?>
        <?php if (isset($r['error'])): ?>
        <div class="repair-card repair-stop"><h2>Undo failed</h2><p class="mb-0"><?= htmlspecialchars($r['error'], ENT_QUOTES) ?></p></div>
        <?php else: ?>
        <div class="repair-card repair-ok">
            <h2>Restored</h2>
            <table class="table table-sm repair-table mb-0">
                <thead><tr><th>Column</th><th class="text-end">Values put back</th></tr></thead>
                <tbody>
                <?php foreach ($r['undone'] as $u): ?>
                    <tr><td><code><?= htmlspecialchars($u['table'] . '.' . $u['column'], ENT_QUOTES) ?></code></td><td class="text-end"><?= (int) $u['restored'] ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="repair-card">
        <h2>Dry run — <?= (int) $totalRows ?> value(s) in the window</h2>
        <p class="repair-note">Nothing here has been written. The first three rows of each column are shown, before and after.</p>
        <div class="table-responsive">
            <table class="table table-sm repair-table align-middle">
                <thead>
                    <tr><th>Column</th><th>What it is</th><th class="text-end">Rows</th><th>Sample</th></tr>
                </thead>
                <tbody>
                <?php foreach ($plan as $entry): $label = $entry['table'] . '.' . $entry['column']; ?>
                    <tr>
                        <td><code><?= htmlspecialchars($label, ENT_QUOTES) ?></code></td>
                        <td><?= htmlspecialchars($entry['why'], ENT_QUOTES) ?></td>
                        <td class="text-end"><?= $entry['skipped'] ? '—' : (int) $entry['count'] ?></td>
                        <td class="repair-samples">
                            <?php if ($entry['skipped']): ?>
                                skipped — <?= htmlspecialchars($entry['skipped'], ENT_QUOTES) ?>
                            <?php elseif (!$entry['samples']): ?>
                                nothing in this window
                            <?php else: ?>
                                <?php foreach ($entry['samples'] as $i => $s): ?>
                                    <?php if ($i): ?><br><?php endif; ?>
                                    #<?= htmlspecialchars($s['pk'], ENT_QUOTES) ?>: <?= htmlspecialchars($s['before'], ENT_QUOTES) ?> → <strong><?= htmlspecialchars($s['after'], ENT_QUOTES) ?></strong>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($previous): ?>
        <div class="alert alert-danger mt-3 mb-3">
            <strong>This window has been repaired before.</strong>
            <?php foreach ($previous as $run): ?>
                <div class="repair-note">
                    <?= htmlspecialchars($run['ran_at'], ENT_QUOTES) ?> —
                    <?= (int) $run['rows_changed'] ?> row(s) shifted, backup <code><?= htmlspecialchars($run['backup_table'], ENT_QUOTES) ?></code>
                </div>
            <?php endforeach; ?>
            Applying again would shift the same rows a second time.
        </div>
        <?php endif; ?>

        <form method="post" class="mt-3"
              onsubmit="return confirm('Shift <?= (int) $totalRows ?> value(s) by +<?= $MARLE_SHIFT_MINUTES ?> minutes? The old values are kept in a backup table.');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
            <input type="hidden" name="do" value="apply">
            <input type="hidden" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES) ?>">
            <input type="hidden" name="until" value="<?= htmlspecialchars($until, ENT_QUOTES) ?>">
            <?php if ($previous): ?>
            <label class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="acknowledged_overlap" value="1" required>
                <span class="form-check-label">I know this window was already repaired, and I want to shift it again.</span>
            </label>
            <?php endif; ?>
            <button class="btn btn-brand" type="submit"<?= ($canApply && $totalRows > 0) ? '' : ' disabled' ?>>
                Apply +<?= $MARLE_SHIFT_MINUTES ?> minutes to <?= (int) $totalRows ?> value(s)
            </button>
            <span class="repair-note ms-2">Delete this file once the repair is done — it is a one-off, not a screen.</span>
        </form>
    </div>

    <div class="repair-card">
        <h2>Deliberately left alone</h2>
        <table class="table table-sm repair-table mb-0">
            <tbody>
            <?php foreach ($marleLeftAlone as $group => $reason): ?>
                <tr><th class="w-25"><?= htmlspecialchars($group, ENT_QUOTES) ?></th><td><?= htmlspecialchars($reason, ENT_QUOTES) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
