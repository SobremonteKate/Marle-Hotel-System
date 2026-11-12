<?php
// TODO: Admin role should only archive, not update/delete.
// TODO: Admin role should not be allowed to edit here.
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.guests');
// The hotel's date, for the CSV export's filename further down (includes/server_clock.php).
// Required here, above that export block, because the page's own clock asset is emitted
// in the head — long after the export has already sent its headers.
require_once __DIR__ . '/../includes/server_clock.php';
include('auth.php');
require_once __DIR__ . '/../includes/log_action.php';
include('../admin/requests_badge.php');
include('../admin/includes/notif_data.php');
require_once __DIR__ . '/includes/token_sweep.php';
run_token_sweep($conn);

$canManageGuests = false;
$canUpdateGuestStatus = true;
$canArchiveGuests = true;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function parseDateTimeLocal(string $value): ?DateTime
{
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value);
    $errors = DateTime::getLastErrors();
    if (!$dt || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $dt;
}

function buildAdditionalJson(array $posted): string
{
    $additionalData = [];
    foreach ($posted as $name => $values) {
        if (!is_array($values) || !isset($values['checked'])) {
            continue;
        }
        $qty = max(1, (int)($values['qty'] ?? 1));
        $additionalData[trim((string)$name)] = $qty;
    }
    return json_encode($additionalData, JSON_UNESCAPED_SLASHES) ?: '{}';
}

function redirectWithStatus(string $status): void
{
    header("Location: customerRC.php?status=" . urlencode($status));
    exit;
}

// Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: ../login.php?logout=success");
    exit;
}

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="guest_records_' . marle_hotel_date(0, $conn) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Room','Transportation','Persons','Plate No','Date From','Date To','Hours','Additional','Status']);
    $res = $conn->query("SELECT id, room_no, transportation, persons, plate_no, date_from, date_to, hours, additional, status FROM customerstbl ORDER BY id DESC");
    while ($r = $res->fetch_assoc()) {
        $addDecoded = json_decode($r['additional'] ?? '{}', true) ?: [];
        $addText = implode(', ', array_map(fn($k, $v) => "$k ($v)", array_keys($addDecoded), $addDecoded)) ?: 'None';
        fputcsv($out, [$r['id'], $r['room_no'], $r['transportation'], $r['persons'], $r['plate_no'], $r['date_from'], $r['date_to'], $r['hours'], $addText, $r['status']]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

$allowedStatuses = ['checked-in', 'checked-out', 'cancelled'];
$quickStatusOptions = ['checked-in', 'checked-out'];

// Quick status-only update
if (isset($_POST['statusOnlyUpdate'])) {
    if (!$canUpdateGuestStatus) {
        redirectWithStatus('error');
    }

    $id = (int)($_POST['id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));

    if ($id < 1 || !in_array($status, $quickStatusOptions, true)) {
        redirectWithStatus('error');
    }

    $stmt = $conn->prepare(
        "UPDATE customerstbl
         SET status = ?,
             checked_out_at = CASE
                 WHEN ? = 'checked-out' THEN COALESCE(checked_out_at, NOW())
                 ELSE NULL
             END
         WHERE id = ?"
    );
    $stmt->bind_param('ssi', $status, $status, $id);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        log_event($conn, 'guest_status_updated', 'guest_record', $id, 'success', 'Status → ' . $status);
    }
    redirectWithStatus($ok ? 'status-updated' : 'error');
}

// Add guest
if (isset($_POST['addCustomer'])) {
    if (!$canManageGuests) {
        redirectWithStatus('error');
    }

    $roomNo         = trim((string)($_POST['roomNo'] ?? ''));
    $transportation = trim((string)($_POST['transportation'] ?? ''));
    $persons        = (int)($_POST['persons'] ?? 0);
    $plateNo        = trim((string)($_POST['plateNo'] ?? ''));
    $hours          = trim((string)($_POST['hours'] ?? ''));
    $status         = trim((string)($_POST['status'] ?? 'checked-in'));
    $dateFromRaw    = trim((string)($_POST['date_from'] ?? ''));
    $dateToRaw      = trim((string)($_POST['date_to'] ?? ''));

    $dateFromDt = parseDateTimeLocal($dateFromRaw);
    $dateToDt   = parseDateTimeLocal($dateToRaw);
    if (!$dateFromDt || !$dateToDt || $dateToDt < $dateFromDt || $persons < 1 || !in_array($status, $allowedStatuses, true)) {
        redirectWithStatus('error');
    }

    if ($plateNo !== '' && $transportation === 'Walk-In') {
        $transportation = 'By Vehicle';
    }

    $dateFrom   = $dateFromDt->format('Y-m-d H:i:s');
    $dateTo     = $dateToDt->format('Y-m-d H:i:s');
    $additional = buildAdditionalJson($_POST['additional'] ?? []);

    // The hotel's clock, not PHP's (includes/server_clock.php): this stamp is displayed
    // beside the stay's own database-written dates.
    $checkedOutAt = $status === 'checked-out' ? marle_db_now($conn) : null;
    $stmt = $conn->prepare(
        "INSERT INTO customerstbl
            (room_no, transportation, persons, plate_no, date_from, date_to, hours, additional, status, checked_out_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('ssisssssss', $roomNo, $transportation, $persons, $plateNo, $dateFrom, $dateTo, $hours, $additional, $status, $checkedOutAt);
    $ok = $stmt->execute();
    $newGuestId = (int)$conn->insert_id;
    $stmt->close();
    if ($ok) {
        log_event($conn, 'guest_record_added', 'guest_record', $newGuestId, 'success', 'Room ' . $roomNo . ' — ' . $persons . ' person(s)');
    }
    redirectWithStatus($ok ? 'success' : 'error');
}

// Edit guest
if (isset($_POST['updateCustomer'])) {
    if (!$canManageGuests) {
        redirectWithStatus('error');
    }

    $id             = (int)($_POST['id'] ?? 0);
    $roomNo         = trim((string)($_POST['roomNo'] ?? ''));
    $transportation = trim((string)($_POST['transportation'] ?? ''));
    $persons        = (int)($_POST['persons'] ?? 0);
    $plateNo        = trim((string)($_POST['plateNo'] ?? ''));
    $hours          = trim((string)($_POST['hours'] ?? ''));
    $status         = trim((string)($_POST['status'] ?? 'checked-in'));
    $dateFromRaw    = trim((string)($_POST['date_from'] ?? ''));
    $dateToRaw      = trim((string)($_POST['date_to'] ?? ''));

    $dateFromDt = parseDateTimeLocal($dateFromRaw);
    $dateToDt   = parseDateTimeLocal($dateToRaw);
    if ($id < 1 || !$dateFromDt || !$dateToDt || $dateToDt < $dateFromDt || $persons < 1 || !in_array($status, $allowedStatuses, true)) {
        redirectWithStatus('error');
    }

    if ($plateNo !== '' && $transportation === 'Walk-In') {
        $transportation = 'By Vehicle';
    }

    $dateFrom   = $dateFromDt->format('Y-m-d H:i:s');
    $dateTo     = $dateToDt->format('Y-m-d H:i:s');
    $additional = buildAdditionalJson($_POST['additional'] ?? []);

    $stmt = $conn->prepare(
        "UPDATE customerstbl
         SET room_no = ?, transportation = ?, persons = ?, plate_no = ?, date_from = ?, date_to = ?, hours = ?, additional = ?, status = ?,
             checked_out_at = CASE
                 WHEN ? = 'checked-out' THEN COALESCE(checked_out_at, NOW())
                 ELSE NULL
             END
         WHERE id = ?"
    );
    $stmt->bind_param('ssisssssssi', $roomNo, $transportation, $persons, $plateNo, $dateFrom, $dateTo, $hours, $additional, $status, $status, $id);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) {
        log_event($conn, 'guest_record_updated', 'guest_record', $id, 'success', 'Room ' . $roomNo . ' — status ' . $status);
    }
    redirectWithStatus($ok ? 'updated' : 'error');
}

// Date-range filter
$allowedSorts = ['today', 'week', 'month', 'lastmonth'];
$sort = in_array($_GET['sort'] ?? '', $allowedSorts, true) ? $_GET['sort'] : '';
$dateFilterRaw = trim((string)($_GET['date'] ?? ''));
$dateFilterDt = DateTime::createFromFormat('Y-m-d', $dateFilterRaw);
$dateFilterErrors = DateTime::getLastErrors();
$dateFilter = ($dateFilterDt && ($dateFilterErrors === false || ($dateFilterErrors['warning_count'] === 0 && $dateFilterErrors['error_count'] === 0)))
    ? $dateFilterDt->format('Y-m-d')
    : '';
$whereSql = '';

if ($dateFilter !== '') {
    $whereSql = "WHERE c.date_from >= '" . $conn->real_escape_string($dateFilter) . " 00:00:00'
                 AND c.date_from < DATE_ADD('" . $conn->real_escape_string($dateFilter) . " 00:00:00', INTERVAL 1 DAY)";
} elseif ($sort === 'today') {
    $whereSql = "WHERE c.date_from >= CURDATE() AND c.date_from < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
} elseif ($sort === 'week') {
    $whereSql = "WHERE YEARWEEK(c.date_from, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($sort === 'month') {
    $whereSql = "WHERE YEAR(c.date_from) = YEAR(CURDATE()) AND MONTH(c.date_from) = MONTH(CURDATE())";
} elseif ($sort === 'lastmonth') {
    $whereSql = "WHERE c.date_from >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
                 AND c.date_from < DATE_FORMAT(CURDATE(), '%Y-%m-01')";
}

$rowsData = [];
$sql = "SELECT c.* FROM customerstbl c $whereSql ORDER BY c.date_from DESC, c.id DESC";
$result = $conn->query($sql);
while ($result && $row = $result->fetch_assoc()) {
    $rowsData[] = $row;
}

// Stats from unfiltered data
$totalGuests = (int)($conn->query("SELECT COUNT(*) AS cnt FROM customerstbl")->fetch_assoc()['cnt'] ?? 0);
$checkedIn = (int)($conn->query(
    "SELECT COUNT(*) AS cnt
     FROM customerstbl
     WHERE status = 'checked-in'
       AND (date_to IS NULL OR date_to > NOW())"
)->fetch_assoc()['cnt'] ?? 0);
$checkedOut = (int)($conn->query(
    "SELECT COUNT(*) AS cnt
     FROM customerstbl
     WHERE status = 'checked-out'"
)->fetch_assoc()['cnt'] ?? 0);
$cancelledCustomerRecords = (int)($conn->query(
    "SELECT COUNT(*) AS cnt
     FROM customerstbl
     WHERE status = 'cancelled'"
)->fetch_assoc()['cnt'] ?? 0);
$cancelledBookings = 0;
$cancelStatsRes = $conn->query(
    "SELECT COUNT(*) AS cnt
     FROM bookings
     WHERE status = 'cancelled'
       AND room_number IS NOT NULL
       AND room_number <> ''
       AND (
           (booking_type != 'hourly'
            AND check_in <= CURDATE()
            AND check_out > CURDATE())
         OR
           (booking_type = 'hourly'
            AND check_in = CURDATE()
            AND check_in_time <= TIME(NOW())
            AND check_out_time > TIME(NOW()))
       )"
);
if ($cancelStatsRes && $cancelStatsRow = $cancelStatsRes->fetch_assoc()) {
    $cancelledBookings = (int)$cancelStatsRow['cnt'];
}
$cancelled = $cancelledCustomerRecords + $cancelledBookings;

// Occupied rooms
$occupiedRooms = [];
$roomQuery = $conn->query(
    "SELECT room_no
     FROM customerstbl
     WHERE status = 'checked-in'
       AND (date_to IS NULL OR date_to > NOW())"
);
while ($roomQuery && $r = $roomQuery->fetch_assoc()) {
    $occupiedRooms[] = (string)$r['room_no'];
}

// Profile picture
$username = $_SESSION['username'];
$stmtUser = $conn->prepare("SELECT picture FROM userstbl WHERE username = ? LIMIT 1");
$stmtUser->bind_param("s", $username);
$stmtUser->execute();
$resultUser = $stmtUser->get_result();
$imgSrc = "../img/default.jpg";
if ($resultUser && $resultUser->num_rows > 0) {
    $userRow = $resultUser->fetch_assoc();
    if (!empty($userRow['picture'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($userRow['picture']);
        $imgSrc = 'data:' . $mimeType . ';base64,' . base64_encode($userRow['picture']);
    }
}
$stmtUser->close();

$additionalItems = ['Towel', 'Blanket', 'Pillow', 'Toiletries'];
?>
<!DOCTYPE html>
<html lang="<?= ui_html_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-4Q6Gf2aSP4eDXB8Miphtr37CMZZQ5oXLH2yaXMJ2w8e2ZtHTl7GptT4jmndRuHDT" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.lineicons.com/5.0/lineicons.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <?php require __DIR__ . '/../includes/staff_icons.php'; ?>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
    <link rel="stylesheet" href="../css/staff-footer.css?v=20261021">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <title>Guest Record | Hotel Admin</title>
    <style>
        :root {
            --amber: #ffb60a;
            --amber-dark: #e6a308;
            --brown: #933310;
            --bg: #f5ede6;
            --card-bg: #ffffff;
            --text: #1e1108;
            --muted: #7a6455;
            --border: #ecddd3;
            --sidebar-w: 230px;
        }

        * { box-sizing: border-box; }
        body { font-family: 'DM Sans', sans-serif; background-color: var(--bg); overflow-x: hidden; color: var(--text); }

        @media (min-width: 768px) {
            .navbar { width: var(--sidebar-w); height: 100vh; position: fixed; top: 0; left: 0; background: var(--card-bg); display: flex; flex-direction: column; border-right: 1px solid var(--border); box-shadow: 2px 0 12px rgba(0,0,0,0.04); }
            .navbar>.container-fluid { height: 100%; display: flex; flex-direction: column; }
            .navbar-collapse { display: flex !important; flex-direction: column; flex: 1; }
            .content, .main-content { margin-left: var(--sidebar-w); }
            .content { padding: 22px 28px 0; }
            .main-content { padding: 0 28px 40px; }
        }

        .nav-link { padding: 10px 16px; color: var(--muted); border-radius: 10px; position: relative; transition: all 0.2s; font-size: 0.9rem; font-weight: 500; }
        .nav-link:hover { background: #fff7e6; color: var(--amber-dark); }
        .nav-link.active { background: var(--amber); color: #fff !important; font-weight: 600; }
        .nav-link.active::before { content: ""; position: absolute; left: -12px; top: 10px; bottom: 10px; width: 4px; border-radius: 4px; background: var(--amber); }
        .nav-link i { font-size: 17px; }
        .notification-badge { background: var(--amber); color: #fff; font-size: 11px; padding: 4px 7px; }
        .logout-btn { background: var(--brown); color: #fff; border: none; padding: 10px; border-radius: 10px; transition: 0.2s; font-size: 0.9rem; }
        .logout-btn:hover { background: transparent; border: 1px solid var(--brown); color: var(--brown); }
        .bi-bell { font-size: 19px; padding: 7px; border-radius: 10px; background: var(--card-bg); color: var(--text); }
        a { color: var(--amber-dark); }

        .topbar-title { font-family: 'Playfair Display', serif; font-size: 1.6rem; color: var(--text); font-weight: 700; }
        .breadcrumb-line { font-size: 0.82rem; color: var(--muted); }
        .breadcrumb-line a { color: var(--amber-dark); text-decoration: none; }

        .stat-cards-row { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
        .stat-card { background: var(--card-bg); border-radius: 14px; border: 1px solid var(--border); padding: 18px 22px; display: flex; align-items: center; gap: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.04); min-width: 180px; }
        .stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .stat-icon.gray { background: #f0ece8; color: var(--muted); }
        .stat-icon.green { background: #dcfce7; color: #16a34a; }
        .stat-icon.muted { background: #f0ece8; color: var(--muted); }
        .stat-icon.red { background: #fee2e2; color: #dc2626; }
        .stat-label { font-size: 0.75rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px; }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: var(--text); line-height: 1; }

        .toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 10px; }
        .toolbar-left, .toolbar-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        #myInputGuest, #guestDateFilter { border: 2px solid var(--border); height: 42px; border-radius: 10px; width: 280px; font-size: 0.88rem; padding: 0 14px; background: var(--card-bg); transition: border-color 0.2s, box-shadow 0.2s; }
        #guestDateFilter { width: 180px; color: var(--muted); }
        #myInputGuest:focus, .form-control:focus, .form-select:focus { border-color: var(--amber); box-shadow: 0 0 0 3px rgba(255,182,10,0.15); outline: none; }
        .form-control, .form-select { border: 1.5px solid var(--border); border-radius: 10px; font-size: 0.9rem; }
        .form-check-input:checked { background-color: var(--amber); border-color: var(--amber); }

        .back-btn, .btn-export {
            display: inline-flex; align-items: center; gap: 7px; border-radius: 10px;
            padding: 8px 18px; font-size: 0.85rem; font-weight: 500; text-decoration: none;
            transition: 0.2s; cursor: pointer;
        }
        .back-btn { background: transparent; color: var(--muted); border: 1.5px solid var(--border); }
        .back-btn:hover { border-color: var(--amber-dark); color: var(--amber-dark); background: #fffbf0; }
        .btn-export { background: transparent; color: var(--muted); border: 1.5px solid var(--border); }
        .btn-export:hover { border-color: #16a34a; color: #16a34a; background: #f0fdf4; }
        .cat-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
        .cat-tab { padding: 5px 14px; border-radius: 99px; font-size: 0.78rem; font-weight: 600; border: 1.5px solid var(--border); background: var(--card-bg); color: var(--muted); cursor: pointer; transition: 0.18s; white-space: nowrap; text-decoration: none; }
        .cat-tab:hover { border-color: var(--amber); color: var(--amber-dark); background: #fffbf0; }
        .cat-tab.active { background: var(--amber); border-color: var(--amber); color: #fff; }
        #resultCount { font-size: 0.8rem; color: var(--muted); margin-bottom: 12px; }
        #guestPagination { display: flex; justify-content: flex-end; align-items: center; gap: 6px; margin: 12px 0 24px; }
        #guestPagination button { min-width: 34px; height: 34px; border: 1px solid var(--border); border-radius: 8px; background: var(--card-bg); color: var(--muted); font-size: 0.8rem; cursor: pointer; }
        #guestPagination button:hover:not(:disabled), #guestPagination button.active { background: var(--amber); border-color: var(--amber); color: #fff; }
        #guestPagination button:disabled { opacity: 0.45; cursor: not-allowed; }

        .table-card { background: var(--card-bg); border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 20px rgba(0,0,0,0.05); overflow: hidden; }
        .table-scroll-wrap { overflow-x: auto; }
        .table-card table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        .table-card thead th { background: #fdf8f2; color: var(--muted); font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; padding: 13px 16px; border-bottom: 1px solid var(--border); white-space: nowrap; user-select: none; }
        .table-card thead th.sortable { cursor: pointer; }
        .table-card thead th.sortable:hover { color: var(--amber-dark); }
        .sort-icon { margin-left: 4px; opacity: 0.45; font-size: 0.7rem; }
        .table-card thead th.sort-asc .sort-icon::after { content: "\2191"; opacity: 1; }
        .table-card thead th.sort-desc .sort-icon::after { content: "\2193"; opacity: 1; }
        .table-card thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: "\2195"; }
        .table-card tbody tr { border-bottom: 1px solid #f5ede6; transition: background 0.15s; }
        .table-card tbody tr:last-child { border-bottom: none; }
        .table-card tbody tr:hover { background: #fffbf3; }
        .table-card tbody td { padding: 13px 16px; vertical-align: middle; color: var(--text); }
        .actions-cell { display: flex; gap: 6px; align-items: center; }
        .btn-act { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; background: transparent; border: 1.5px solid var(--border); font-size: 0.85rem; cursor: pointer; transition: 0.18s; }
        .btn-act-edit { border-color: #fcd34d; color: var(--amber-dark); }
        .btn-act-edit:hover { background: var(--amber); color: #fff; border-color: var(--amber); }
        .btn-act-archive { border-color: #fca5a5; color: #dc2626; }
        .btn-act-archive:hover { background: #dc2626; color: #fff; border-color: #dc2626; }
        .status-pill { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 99px; font-size: 0.74rem; font-weight: 700; white-space: nowrap; }
        .status-pill-btn { border: 0; gap: 6px; cursor: pointer; }
        .status-pill-btn::after { margin-left: 2px; }
        .status-menu { border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 10px 28px rgba(0,0,0,0.1); padding: 6px; min-width: 150px; }
        .status-menu button { border: 0; background: transparent; width: 100%; border-radius: 8px; padding: 7px 10px; text-align: left; font-size: 0.84rem; color: var(--text); }
        .status-menu button:hover { background: #fffbf0; color: var(--amber-dark); }
        .status-checked-in { background: #dcfce7; color: #16a34a; }
        .status-checked-out { background: #f0ece8; color: #7a6455; }
        .status-cancelled { background: #fee2e2; color: #dc2626; }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--muted); }
        .empty-state i { font-size: 2.5rem; margin-bottom: 12px; opacity: 0.4; display: block; }
        .empty-state p { font-size: 0.9rem; margin: 0; }

        .room-board-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 18px 20px 0; }
        .section-title { font-family: 'Playfair Display', serif; font-size: 1.25rem; font-weight: 700; color: var(--text); }
        .room-board-body { padding: 16px 20px 20px; }
        .room-card { border: 1px solid var(--border); border-left-width: 5px; border-radius: 10px; padding: 1rem 1rem 0.85rem; cursor: pointer; transition: box-shadow 0.18s, transform 0.18s; background: #fff; position: relative; user-select: none; min-height: 120px; }
        .room-card:hover { box-shadow: 0 4px 18px rgba(0,0,0,0.12); transform: translateY(-2px); }
        .room-card.status-available { border-left-color: #16a34a; }
        .room-card.status-occupied { border-left-color: #dc2626; }
        .room-card.status-booked { border-left-color: var(--amber); }
        .room-card.status-cancelled { border-left-color: #dc2626; }
        .room-card.status-maintenance { border-left-color: var(--muted); }
        .room-number { font-family: 'Playfair Display', serif; font-size: 1.9rem; font-weight: 700; line-height: 1; color: var(--text); }
        .room-type { font-size: 0.75rem; color: var(--muted); margin-top: 2px; }
        .room-detail { font-size: 0.78rem; color: #5f4b3f; margin-top: 6px; min-height: 1.1rem; }
        .status-badge { font-size: 0.72rem; font-weight: 700; padding: 3px 9px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.04em; }
        .badge-available { background: #dcfce7; color: #16a34a; }
        .badge-occupied { background: #fee2e2; color: #dc2626; }
        .badge-booked { background: #fff8e1; color: var(--amber-dark); }
        .badge-cancelled { background: #fee2e2; color: #dc2626; }
        .badge-maintenance { background: #f0ece8; color: var(--muted); }
        @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        .skeleton-card { border-radius: 10px; height: 120px; background: linear-gradient(90deg, #fff 25%, var(--border) 50%, #fff 75%); background-size: 200% 100%; animation: shimmer 1.2s infinite; }

        .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(0,0,0,0.15); }
        .modal-header { border-bottom: 1px solid var(--border); padding: 18px 22px; }
        .modal-title { font-family: 'Playfair Display', serif; font-size: 1.1rem; font-weight: 700; }
        .modal-body { padding: 20px 22px; }
        .modal-footer { border-top: 1px solid var(--border); padding: 14px 22px; }
        .modal-room-number { font-family: 'Playfair Display', serif; font-size: 2rem; font-weight: 700; color: var(--brown); line-height: 1.1; }
        .detail-block { background: #fdf8f2; border: 1px solid var(--border); border-radius: 10px; padding: 0.85rem 1rem; font-size: 0.86rem; }
        .detail-block dt { color: var(--muted); font-weight: 700; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .detail-block dd { color: var(--text); margin-bottom: 0.35rem; }
        .btn-modal-cancel { background: #f5f5f5; color: var(--muted); border: none; border-radius: 9px; padding: 8px 18px; font-weight: 500; font-size: 0.88rem; cursor: pointer; }
        .btn-modal-cancel:hover { background: #ebebeb; color: var(--text); }
        .btn-modal-save { background: var(--amber); color: #fff; border: none; border-radius: 9px; padding: 8px 22px; font-weight: 600; font-size: 0.88rem; cursor: pointer; }
        .btn-modal-save:hover { background: var(--amber-dark); color: #fff; }
        .small-input { width: 88px; }
        .additional-item { gap: 8px; }
        .toast { border-radius: 12px; font-size: 0.88rem; font-weight: 500; }
    </style>
    <link rel="stylesheet" href="../css/staff-customer.css?v=20261021">
    <link rel="stylesheet" href="../css/staff-logout.css">
<?php require_once __DIR__ . '/../includes/server_clock.php'; marle_server_clock_assets(); ?>
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>
<body class="staff-page staff-customer-page staff-admin">
<?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

<?php
    $staffPageTitle  = 'Guest Record';
    $staffPageNote   = 'Every stay the front desk has recorded, with status and folio actions.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['Guest Record', null]];
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

<div class="main-content">
    <div class="stat-cards-row">
        <div class="stat-card">
            <div class="stat-icon gray"><i class="bi bi-people"></i></div>
            <div><div class="stat-label">Total Guests</div><div class="stat-value"><?= h($totalGuests) ?></div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="bi bi-door-open"></i></div>
            <div><div class="stat-label">Checked In</div><div class="stat-value"><?= h($checkedIn) ?></div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon muted"><i class="bi bi-door-closed"></i></div>
            <div><div class="stat-label">Checked Out</div><div class="stat-value"><?= h($checkedOut) ?></div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red"><i class="bi bi-x-circle"></i></div>
            <div><div class="stat-label">Cancelled</div><div class="stat-value"><?= h($cancelled) ?></div></div>
        </div>
    </div>

    <div class="toolbar">
        <div class="toolbar-left">
            <input class="form-control" id="myInputGuest" type="text" placeholder="Search records">
            <form method="GET" id="guestDateForm" class="m-0">
                <input class="form-control" id="guestDateFilter" type="date" name="date" value="<?= h($dateFilter) ?>" title="Filter by Date From">
            </form>
        </div>
        <div class="toolbar-right">
            <a href="?export=csv" class="btn-export"><i class="bi bi-filetype-csv"></i> Export CSV</a>
            <a href="../admin/archive.php" class="back-btn"><i class="bi bi-archive"></i> View Archive</a>
        </div>
    </div>

    <div class="cat-tabs">
        <a href="customerRC.php" class="cat-tab <?= $sort === '' && $dateFilter === '' ? 'active' : '' ?>">All</a>
        <a href="?sort=today" class="cat-tab <?= $sort === 'today' ? 'active' : '' ?>">Today</a>
        <a href="?sort=week" class="cat-tab <?= $sort === 'week' ? 'active' : '' ?>">This Week</a>
        <a href="?sort=month" class="cat-tab <?= $sort === 'month' ? 'active' : '' ?>">This Month</a>
        <a href="?sort=lastmonth" class="cat-tab <?= $sort === 'lastmonth' ? 'active' : '' ?>">Last Month</a>
        <?php if ($dateFilter !== ''): ?>
            <a href="customerRC.php" class="cat-tab active">Date: <?= h(date('M j, Y', strtotime($dateFilter))) ?></a>
        <?php endif; ?>
    </div>

    <div id="resultCount">Showing <?= h(count($rowsData)) ?> of <?= h(count($rowsData)) ?> records</div>

    <div class="table-card mb-4">
        <div class="table-scroll-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="sortable" data-col="id">Customer ID <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="room">Room <span class="sort-icon"></span></th>
                        <th>Transportation</th>
                        <th class="sortable" data-col="persons">Persons <span class="sort-icon"></span></th>
                        <th>Plate No.</th>
                        <th class="sortable sort-desc" data-col="datefrom">Date From <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="dateto">Date To <span class="sort-icon"></span></th>
                        <th>Hours</th>
                        <th>Additional</th>
                        <th class="sortable" data-col="status">Status <span class="sort-icon"></span></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="myTable">
                <?php foreach ($rowsData as $row): ?>
                    <?php
                        $id             = (int)$row['id'];
                        $roomNo         = (string)$row['room_no'];
                        $transportation = (string)$row['transportation'];
                        $persons        = (int)$row['persons'];
                        $plateNo        = (string)($row['plate_no'] ?? '');
                        $dateFrom       = $row['date_from'] ?? '';
                        $dateTo         = $row['date_to'] ?? '';
                        $hours          = (string)$row['hours'];
                        $additional     = $row['additional'] ?: '{}';
                        $status         = in_array($row['status'] ?? '', $allowedStatuses, true) ? $row['status'] : 'checked-in';
                        $dateFromDisplay = $dateFrom ? date('M j, Y g:i A', strtotime($dateFrom)) : '&mdash;';
                        $dateToDisplay   = $dateTo ? date('M j, Y g:i A', strtotime($dateTo)) : '&mdash;';
                        $dateFromInput   = $dateFrom ? date('Y-m-d\TH:i', strtotime($dateFrom)) : '';
                        $dateToInput     = $dateTo ? date('Y-m-d\TH:i', strtotime($dateTo)) : '';
                        $decoded = json_decode($additional, true) ?: [];
                        $items = [];
                        foreach ($decoded as $name => $qty) {
                            if ((int)$qty > 0) {
                                $items[] = $name . ' (' . (int)$qty . ')';
                            }
                        }
                        $additionalText = $items ? implode(', ', $items) : 'None';
                        $addShort = mb_strlen($additionalText) > 25 ? mb_substr($additionalText, 0, 25) . '...' : $additionalText;
                        $plateDisplay = trim($plateNo) !== '' ? h($plateNo) : '<span style="color:var(--muted)">&mdash;</span>';
                        $statusLabel = ucwords(str_replace('-', ' ', $status));
                    ?>
                    <tr
                        data-id="<?= h($id) ?>"
                        data-room="<?= h($roomNo) ?>"
                        data-transport="<?= h(strtolower($transportation)) ?>"
                        data-plate="<?= h(strtolower($plateNo)) ?>"
                        data-status="<?= h($status) ?>"
                        data-datefrom="<?= h($dateFrom) ?>"
                        data-dateto="<?= h($dateTo) ?>"
                        data-persons="<?= h($persons) ?>"
                    >
                        <td><?= h($id) ?></td>
                        <td><?= h($roomNo) ?></td>
                        <td><?= h($transportation) ?></td>
                        <td><?= h($persons) ?></td>
                        <td><?= $plateDisplay ?></td>
                        <td><?= $dateFrom ? h($dateFromDisplay) : '&mdash;' ?></td>
                        <td><?= $dateTo ? h($dateToDisplay) : '&mdash;' ?></td>
                        <td><?= h($hours) ?></td>
                        <td title="<?= h($additionalText) ?>"><?= h($addShort) ?></td>
                        <td>
                            <?php if ($status === 'cancelled'): ?>
                                <span class="status-pill status-cancelled"><?= h($statusLabel) ?></span>
                            <?php else: ?>
                                <div class="dropdown">
                                    <button class="status-pill status-pill-btn status-<?= h($status) ?> dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <?= h($statusLabel) ?>
                                    </button>
                                    <div class="dropdown-menu status-menu">
                                        <?php foreach ($quickStatusOptions as $quickStatus): ?>
                                            <?php $quickStatusLabel = ucwords(str_replace('-', ' ', $quickStatus)); ?>
                                            <form method="POST" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="statusOnlyUpdate" value="1">
                                                <input type="hidden" name="id" value="<?= h($id) ?>">
                                                <input type="hidden" name="status" value="<?= h($quickStatus) ?>">
                                                <button type="submit" <?= $status === $quickStatus ? 'disabled' : '' ?>>
                                                    <?= h($quickStatusLabel) ?>
                                                </button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions-cell">
                                <button type="button" class="btn-act btn-act-archive archiveBtn" data-id="<?= h($id) ?>" title="Archive">
                                    <i class="bi bi-archive"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                    <tr id="emptyRow" style="display:none;">
                        <td colspan="11">
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <p>No guest records found.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <div id="guestPagination" aria-label="Guest record pages"></div>

    <div class="table-card mb-5">
        <div class="room-board-head">
            <div class="section-title">Room Availability Board</div>
            <div class="d-flex align-items-center gap-2">
                <span id="availRefreshStatus" class="text-muted small"></span>
                <button class="back-btn" type="button" onclick="fetchRoomBoard(true)">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
        </div>
        <div class="room-board-body">
            <div class="cat-tabs">
                <button class="cat-tab active room-board-filter-btn" data-filter="all" type="button">All <span data-count-for="all">0</span></button>
                <button class="cat-tab room-board-filter-btn" data-filter="available" type="button">Available <span data-count-for="available">0</span></button>
                <button class="cat-tab room-board-filter-btn" data-filter="occupied" type="button">Occupied <span data-count-for="occupied">0</span></button>
                <button class="cat-tab room-board-filter-btn" data-filter="booked" type="button">Booked <span data-count-for="booked">0</span></button>
                <button class="cat-tab room-board-filter-btn" data-filter="cancelled" type="button">Cancelled <span data-count-for="cancelled">0</span></button>
                <button class="cat-tab room-board-filter-btn" data-filter="maintenance" type="button">Maintenance <span data-count-for="maintenance">0</span></button>
            </div>
            <div id="room-board-grid" class="row g-3">
                <?php for ($i = 0; $i < 16; $i++): ?>
                <div class="col-6 col-sm-4 col-md-3 col-xl-2">
                    <div class="skeleton-card"></div>
                </div>
                <?php endfor; ?>
            </div>
            <div class="text-muted small mt-2" id="availLastUpdated"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="roomEditModal" tabindex="-1" aria-labelledby="roomEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="modal-room-number" id="modal-room-number">&mdash;</div>
                    <div class="text-muted" style="font-size:0.82rem;" id="modal-room-type">&mdash;</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <span class="status-badge" id="modal-live-badge">&mdash;</span>
                    <span class="text-muted ms-2" style="font-size:0.78rem;">current live status</span>
                </div>

                <div id="modal-detail-block" class="detail-block mb-3 d-none">
                    <dl class="mb-0" id="modal-detail-list"></dl>
                </div>

                <p class="section-title mb-2">Admin Override</p>
                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size:0.85rem;">Set Room Override</label>
                    <select class="form-select form-select-sm" id="modal-status-select">
                        <option value="available">Clear maintenance</option>
                        <option value="maintenance">Mark as Under Maintenance</option>
                        <option value="cancelled">Cancel Booking</option>
                    </select>
                    <div class="form-text">Maintenance is the only override stored here. For an occupied room choose <strong>Force release</strong> to check the guest out and free the room; booked and cancelled statuses come from live booking data.</div>
                </div>

                <div id="modal-note-wrap" class="mb-3 d-none">
                    <label class="form-label fw-semibold" style="font-size:0.85rem;">Maintenance Note <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea class="form-control form-control-sm" id="modal-maint-note" rows="3" placeholder="e.g. Plumbing repair, estimated 2 days"></textarea>
                </div>

                <div id="modal-save-msg" class="alert py-2 d-none" role="alert"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-modal-save" id="modal-save-btn">
                    <i class="bi bi-floppy me-1"></i>Save
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($canManageGuests): ?>
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="addCustomerForm">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Add Guest</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Room</label>
                            <select class="form-select" name="roomNo" required>
                                <option value="" disabled selected>Select Room</option>
                                <?php for ($i = 1; $i <= 16; $i++): ?>
                                    <option value="<?= h($i) ?>" <?= in_array((string)$i, $occupiedRooms, true) ? 'disabled' : '' ?>>Room <?= h($i) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Persons</label>
                            <input class="form-control" type="number" name="persons" min="1" placeholder="No. of persons" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Transportation</label>
                            <select class="form-select" name="transportation" id="add-transportation" required>
                                <option value="" disabled selected>Select Transportation</option>
                                <option value="Walk-In">Walk-In</option>
                                <option value="By Vehicle">By Vehicle</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Plate No.</label>
                            <input class="form-control plate-input" type="text" name="plateNo" id="add-plate" placeholder="If applicable" autocomplete="off">
                            <div class="invalid-feedback">Invalid plate format. Use ABC 1234 or ABC-1234.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date From</label>
                            <input class="form-control date-from-input" type="datetime-local" name="date_from" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date To</label>
                            <input class="form-control date-to-input" type="datetime-local" name="date_to" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Hours</label>
                            <input class="form-control" type="text" name="hours" placeholder="Hours of stay" autocomplete="off" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select status-select" name="status" required>
                                <option value="checked-in">Checked In</option>
                                <option value="checked-out">Checked Out</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label fw-semibold">Additional</label>
                        <div class="d-flex align-items-center mb-2">
                            <input type="checkbox" class="form-check-input me-2" id="add-none" checked>
                            <label class="fw-semibold" for="add-none">None</label>
                        </div>
                        <div class="additional-items row g-2">
                            <?php foreach ($additionalItems as $item): ?>
                                <div class="col-sm-6">
                                    <div class="d-flex align-items-center additional-item">
                                        <input type="checkbox" class="form-check-input" id="add-<?= h($item) ?>" name="additional[<?= h($item) ?>][checked]">
                                        <label for="add-<?= h($item) ?>"><?= h($item) ?></label>
                                        <input type="number" class="form-control small-input ms-auto" name="additional[<?= h($item) ?>][qty]" min="1" value="0" disabled>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="addCustomer" class="btn-modal-save">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="editCustomerForm">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="id" id="edit-id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Guest</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Room</label>
                            <select class="form-select" name="roomNo" id="edit-room" required>
                                <option value="" disabled selected>Select Room</option>
                                <?php for ($i = 1; $i <= 16; $i++): ?>
                                    <option value="<?= h($i) ?>" <?= in_array((string)$i, $occupiedRooms, true) ? 'disabled' : '' ?>>Room <?= h($i) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Persons</label>
                            <input class="form-control" type="number" name="persons" id="edit-persons" min="1" placeholder="No. of persons" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Transportation</label>
                            <select class="form-select" name="transportation" id="edit-transportation" required>
                                <option value="Walk-In">Walk-In</option>
                                <option value="By Vehicle">By Vehicle</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Plate No.</label>
                            <input class="form-control plate-input" type="text" name="plateNo" id="edit-plate" placeholder="If applicable">
                            <div class="invalid-feedback">Invalid plate format. Use ABC 1234 or ABC-1234.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date From</label>
                            <input class="form-control date-from-input" type="datetime-local" name="date_from" id="edit-date-from" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date To</label>
                            <input class="form-control date-to-input" type="datetime-local" name="date_to" id="edit-date-to" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Hours</label>
                            <input class="form-control" type="text" name="hours" id="edit-hours" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit-status" required>
                                <option value="checked-in">Checked In</option>
                                <option value="checked-out">Checked Out</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label fw-semibold">Additional</label>
                        <div class="d-flex align-items-center mb-2">
                            <input type="checkbox" class="form-check-input me-2" id="edit-none">
                            <label class="fw-semibold" for="edit-none">None</label>
                        </div>
                        <div class="additional-items row g-2">
                            <?php foreach ($additionalItems as $item): ?>
                                <div class="col-sm-6">
                                    <div class="d-flex align-items-center additional-item">
                                        <input type="checkbox" class="form-check-input" id="edit-<?= h($item) ?>" name="additional[<?= h($item) ?>][checked]">
                                        <label for="edit-<?= h($item) ?>"><?= h($item) ?></label>
                                        <input type="number" class="form-control small-input ms-auto" name="additional[<?= h($item) ?>][qty]" min="1" value="0" disabled>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="updateCustomer" class="btn-modal-save">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="archiveConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="../admin/archive.php">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="archive_id" id="archive-id">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Archive</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="bi bi-archive fs-1 text-warning d-block mb-3"></i>
                    <p class="mb-0" style="font-size:0.95rem;">Are you sure you want to archive this record?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-modal-save">Yes, Archive</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Logout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <p class="mb-0">Are you sure you want to log out?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                <a href="?action=logout" class="btn-modal-save" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:8px 22px;">Yes, Logout</a>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:9999;">
    <div id="appToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="appToastMessage"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-j1CDi7MgGQ12Z7Qab0qlWQ/Qqz24Gc6BM0thvEMVjHnfYGF0rmFCozFSxQBxwHKO"
    crossorigin="anonymous"></script>
<script src="../admin/includes/plate-validator.js"></script>
<script src="../admin/includes/notif_poll.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const state = { search: '', page: 1 };
    const pageSize = 20;
    const tbody = document.getElementById('myTable');
    const resultCount = document.getElementById('resultCount');
    const emptyRow = document.getElementById('emptyRow');
    const pagination = document.getElementById('guestPagination');
    const rows = () => Array.from(document.querySelectorAll('#myTable tr[data-room]'));

    function applyFilters() {
        const allRows = rows();
        const matchingRows = allRows.filter(row => !state.search || [
            row.dataset.id, row.dataset.room, row.dataset.transport, row.dataset.plate,
            row.dataset.status, row.querySelector('td:nth-child(6)')?.textContent,
            row.querySelector('td:nth-child(7)')?.textContent
        ].some(v => (v || '').toLowerCase().includes(state.search)));
        const totalPages = Math.max(1, Math.ceil(matchingRows.length / pageSize));
        state.page = Math.min(state.page, totalPages);
        const first = (state.page - 1) * pageSize;
        const visibleRows = new Set(matchingRows.slice(first, first + pageSize));
        allRows.forEach(row => { row.style.display = visibleRows.has(row) ? '' : 'none'; });
        if (emptyRow) emptyRow.style.display = matchingRows.length === 0 ? '' : 'none';
        if (resultCount) {
            const last = Math.min(first + pageSize, matchingRows.length);
            resultCount.textContent = matchingRows.length === 0
                ? 'Showing 0 of 0 records'
                : `Showing ${first + 1}-${last} of ${matchingRows.length} records`;
        }
        renderPagination(totalPages);
    }

    function renderPagination(totalPages) {
        if (!pagination) return;
        pagination.innerHTML = '';
        if (totalPages <= 1) return;
        const previous = document.createElement('button');
        previous.type = 'button';
        previous.textContent = 'Prev';
        previous.disabled = state.page === 1;
        previous.addEventListener('click', () => { state.page--; applyFilters(); });
        pagination.appendChild(previous);
        // Compact page list: keep the current page and its neighbours in view
        // so the strip fits a phone screen instead of spilling off it.
        const pageWindow = 1;
        let page = Math.max(1, state.page - pageWindow);
        if (page > 1) {
            pagination.appendChild(makePageButton(1));
            if (page > 2) pagination.appendChild(makeEllipsis());
        }
        for (; page <= Math.min(totalPages, state.page + pageWindow); page++) {
            pagination.appendChild(makePageButton(page));
        }
        if (state.page + pageWindow < totalPages) {
            if (state.page + pageWindow < totalPages - 1) pagination.appendChild(makeEllipsis());
            pagination.appendChild(makePageButton(totalPages));
        }
        const next = document.createElement('button');
        next.type = 'button';
        next.textContent = 'Next';
        next.disabled = state.page === totalPages;
        next.addEventListener('click', () => { state.page++; applyFilters(); });
        pagination.appendChild(next);
    }

    function makePageButton(page) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = page;
        button.classList.toggle('active', page === state.page);
        button.setAttribute('aria-label', `Page ${page}`);
        button.setAttribute('aria-current', page === state.page ? 'page' : 'false');
        button.addEventListener('click', () => { state.page = page; applyFilters(); });
        return button;
    }

    function makeEllipsis() {
        const span = document.createElement('span');
        span.className = 'guest-pagination-ellipsis';
        span.textContent = '\u2026';
        span.setAttribute('aria-hidden', 'true');
        return span;
    }

    const searchInput = document.getElementById('myInputGuest');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            state.search = this.value.toLowerCase().trim();
            state.page = 1;
            applyFilters();
        });
    }

    <?php if ($canManageGuests): ?>
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'n') {
            e.preventDefault();
            new bootstrap.Modal(document.getElementById('addCustomerModal')).show();
        }
    });
    <?php endif; ?>

    const guestDateFilter = document.getElementById('guestDateFilter');
    if (guestDateFilter) {
        guestDateFilter.addEventListener('change', function () {
            window.location.href = this.value
                ? `customerRC.php?date=${encodeURIComponent(this.value)}`
                : 'customerRC.php';
        });
    }

    const sortState = { col: 'datefrom', dir: 'desc' };
    function sortRows(col, dir) {
        const allRows = rows();
        allRows.sort((a, b) => {
            let va = '';
            let vb = '';
            if (col === 'id') {
                va = parseInt(a.dataset.id, 10) || 0;
                vb = parseInt(b.dataset.id, 10) || 0;
            } else if (col === 'room') {
                va = parseInt(a.dataset.room, 10) || 0;
                vb = parseInt(b.dataset.room, 10) || 0;
            } else if (col === 'persons') {
                va = parseInt(a.dataset.persons, 10) || 0;
                vb = parseInt(b.dataset.persons, 10) || 0;
            } else if (col === 'datefrom') {
                va = new Date((a.dataset.datefrom || '').replace(' ', 'T')).getTime() || 0;
                vb = new Date((b.dataset.datefrom || '').replace(' ', 'T')).getTime() || 0;
            } else if (col === 'dateto') {
                va = new Date((a.dataset.dateto || '').replace(' ', 'T')).getTime() || 0;
                vb = new Date((b.dataset.dateto || '').replace(' ', 'T')).getTime() || 0;
            } else if (col === 'status') {
                va = (a.dataset.status || '').toLowerCase();
                vb = (b.dataset.status || '').toLowerCase();
            }
            if (va < vb) return dir === 'asc' ? -1 : 1;
            if (va > vb) return dir === 'asc' ? 1 : -1;
            return 0;
        });
        allRows.forEach(row => tbody.appendChild(row));
        if (emptyRow) tbody.appendChild(emptyRow);
    }

    document.querySelectorAll('thead th.sortable').forEach(th => {
        th.addEventListener('click', function () {
            const col = this.dataset.col;
            if (sortState.col === col) {
                sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
            } else {
                sortState.col = col;
                sortState.dir = 'asc';
            }
            state.page = 1;
            document.querySelectorAll('thead th.sortable').forEach(t => t.classList.remove('sort-asc', 'sort-desc'));
            this.classList.add('sort-' + sortState.dir);
            sortRows(sortState.col, sortState.dir);
            applyFilters();
        });
    });

    function setupAdditionals(modalId, noneId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        const noneCb = modal.querySelector('#' + noneId);
        const itemCbs = modal.querySelectorAll('.additional-items input[type="checkbox"]');
        const qtyInputs = modal.querySelectorAll('.additional-items input[type="number"]');
        if (!noneCb) return;

        function resetToNone() {
            noneCb.checked = true;
            itemCbs.forEach(cb => { cb.checked = false; });
            qtyInputs.forEach(q => { q.value = 0; q.disabled = true; });
        }

        resetToNone();

        noneCb.addEventListener('change', function () {
            if (noneCb.checked) resetToNone();
        });

        itemCbs.forEach((cb, index) => {
            cb.addEventListener('change', function () {
                qtyInputs[index].disabled = !cb.checked;
                if (cb.checked) {
                    noneCb.checked = false;
                    if (parseInt(qtyInputs[index].value, 10) <= 0) qtyInputs[index].value = 1;
                } else {
                    qtyInputs[index].value = 0;
                }
                const anyChecked = Array.from(itemCbs).some(item => item.checked);
                if (!anyChecked) noneCb.checked = true;
            });
        });
    }

    setupAdditionals('addCustomerModal', 'add-none');
    setupAdditionals('editCustomerModal', 'edit-none');

    function setupDateValidation(form) {
        if (!form) return;
        form.addEventListener('submit', function (event) {
            const from = form.querySelector('.date-from-input');
            const to = form.querySelector('.date-to-input');
            if (from && to && from.value && to.value && to.value < from.value) {
                event.preventDefault();
                to.setCustomValidity('Date To must be later than or equal to Date From.');
                to.reportValidity();
            } else if (to) {
                to.setCustomValidity('');
            }
        });
    }

    setupDateValidation(document.getElementById('addCustomerForm'));
    setupDateValidation(document.getElementById('editCustomerForm'));

    function setupPlateTransport(plateInput, transportSelect) {
        if (!plateInput || !transportSelect) return;
        plateInput.addEventListener('input', function () {
            transportSelect.value = this.value.trim() !== '' ? 'By Vehicle' : 'Walk-In';
        });
    }

    setupPlateTransport(document.getElementById('add-plate'), document.getElementById('add-transportation'));
    setupPlateTransport(document.getElementById('edit-plate'), document.getElementById('edit-transportation'));

    const editRoomSelect = document.getElementById('edit-room');
    if (editRoomSelect) {
        Array.from(editRoomSelect.options).forEach(option => {
            option.dataset.initialDisabled = option.disabled ? '1' : '0';
        });
    }

    document.querySelectorAll('.editBtn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('edit-id').value = btn.dataset.id || '';
            const currentRoom = btn.dataset.room || '';
            Array.from(editRoomSelect.options).forEach(option => {
                option.disabled = option.dataset.initialDisabled === '1';
            });
            const opt = Array.from(editRoomSelect.options).find(option => option.value === currentRoom);
            if (opt) opt.disabled = false;
            editRoomSelect.value = currentRoom;
            document.getElementById('edit-transportation').value = btn.dataset.transportation || 'Walk-In';
            document.getElementById('edit-persons').value = btn.dataset.persons || '';
            document.getElementById('edit-plate').value = btn.dataset.plate || '';
            document.getElementById('edit-date-from').value = btn.dataset.dateFrom || '';
            document.getElementById('edit-date-to').value = btn.dataset.dateTo || '';
            document.getElementById('edit-hours').value = btn.dataset.hours || '';
            document.getElementById('edit-status').value = btn.dataset.status || 'checked-in';

            const editModal = document.getElementById('editCustomerModal');
            const noneCb = document.getElementById('edit-none');
            const itemCbs = editModal.querySelectorAll('.additional-items input[type="checkbox"]');
            const qtyInputs = editModal.querySelectorAll('.additional-items input[type="number"]');
            noneCb.checked = true;
            itemCbs.forEach(cb => { cb.checked = false; });
            qtyInputs.forEach(q => { q.value = 0; q.disabled = true; });

            let data = {};
            try {
                data = btn.dataset.additional ? JSON.parse(btn.dataset.additional) : {};
            } catch (e) {
                data = {};
            }
            if (Object.keys(data).length > 0) {
                noneCb.checked = false;
            }
            Object.entries(data).forEach(([item, qtyValue]) => {
                const checkbox = Array.from(editModal.querySelectorAll('.additional-items input[type="checkbox"]'))
                    .find(input => input.name === `additional[${item}][checked]`);
                const qty = Array.from(editModal.querySelectorAll('.additional-items input[type="number"]'))
                    .find(input => input.name === `additional[${item}][qty]`);
                if (checkbox && qty) {
                    checkbox.checked = true;
                    qty.disabled = false;
                    qty.value = parseInt(qtyValue, 10) || 1;
                }
            });
        });
    });

    const archiveModalEl = document.getElementById('archiveConfirmModal');
    const archiveModal = archiveModalEl ? new bootstrap.Modal(archiveModalEl) : null;
    document.querySelectorAll('.archiveBtn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('archive-id').value = btn.dataset.id || '';
            if (archiveModal) archiveModal.show();
        });
    });

    const params = new URLSearchParams(window.location.search);
    const toastMap = {
        success: { className: 'text-bg-success', message: '<i class="bi bi-check-circle me-2"></i>Guest record successfully added.' },
        updated: { className: 'text-bg-success', message: '<i class="bi bi-check-circle me-2"></i>Guest record successfully updated.' },
        'status-updated': { className: 'text-bg-success', message: '<i class="bi bi-check-circle me-2"></i>Guest status successfully updated.' },
        error: { className: 'text-bg-danger', message: '<i class="bi bi-exclamation-circle me-2"></i>Operation failed. Please try again.' }
    };
    let toast = null;
    if (params.has('status') && toastMap[params.get('status')]) {
        toast = toastMap[params.get('status')];
    } else if (params.get('archived') === '1') {
        toast = { className: 'text-bg-secondary', message: '<i class="bi bi-archive me-2"></i>Record archived.' };
    } else if (params.get('restored') === '1') {
        toast = { className: 'text-bg-success', message: '<i class="bi bi-check-circle me-2"></i>Record restored.' };
    }
    if (toast) {
        const toastEl = document.getElementById('appToast');
        const toastMsg = document.getElementById('appToastMessage');
        toastEl.classList.add(toast.className);
        toastMsg.innerHTML = toast.message;
        new bootstrap.Toast(toastEl, { delay: 3500 }).show();
        history.replaceState({}, document.title, window.location.pathname);
    }

    sortRows('datefrom', 'desc');
    applyFilters();
});
</script>

<script>
const REFRESH_INTERVAL = 30;
let roomData = [];
let secondsSince = 0;
let countdownTimer = null;
let currentFilter = 'all';
let roomEditModal = null;
let currentRoomNum = null;
const STATUS_KEYS = ['available', 'occupied', 'booked', 'cancelled', 'maintenance'];

function fmtTime(dtStr) {
    if (!dtStr) return '&mdash;';
    const d = new Date(dtStr.replace(' ', 'T'));
    if (isNaN(d)) return dtStr;
    return d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true });
}

function fmtDate(dateStr) {
    if (!dateStr) return '&mdash;';
    const d = new Date(dateStr + 'T00:00:00');
    if (isNaN(d)) return dateStr;
    return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

function badgeClass(status) {
    const m = { available: 'badge-available', occupied: 'badge-occupied', booked: 'badge-booked', cancelled: 'badge-cancelled', maintenance: 'badge-maintenance' };
    return m[status] || 'badge-available';
}

function badgeLabel(status) {
    const m = { available: 'Available', occupied: 'Occupied', booked: 'Booked', cancelled: 'Cancelled', maintenance: 'Maintenance' };
    return m[status] || status;
}

function updateRoomFilterCounts(rooms) {
    const counts = { all: rooms.length };
    STATUS_KEYS.forEach(status => { counts[status] = 0; });
    rooms.forEach(room => {
        if (counts[room.status] !== undefined) counts[room.status]++;
    });
    document.querySelectorAll('.room-board-filter-btn [data-count-for]').forEach(el => {
        el.textContent = counts[el.dataset.countFor] ?? 0;
    });
}

function buildDetail(room) {
    const s = room.status;
    if (s === 'occupied' && room.available_at) {
        const hrsPart = room.hours_staying != null ? ` &middot; ${room.hours_staying}hr(s)` : '';
        return `<i class="bi bi-clock"></i> Available at ${fmtTime(room.available_at)}${hrsPart}`;
    }
    if (s === 'booked' && room.booking_info) {
        const b = room.booking_info;
        if (b.booking_type === 'hourly') {
            const ci = b.check_in_time ? b.check_in_time.slice(0, 5) : '&mdash;';
            const co = b.check_out_time ? b.check_out_time.slice(0, 5) : '&mdash;';
            return `<i class="bi bi-clock"></i> ${ci} - ${co}`;
        }
        return `<i class="bi bi-calendar3"></i> ${fmtDate(b.check_in)} - ${fmtDate(b.check_out)}`;
    }
    if (s === 'cancelled' && room.cancelled_info) {
        const b = room.cancelled_info;
        if (b.booking_type === 'hourly') {
            const ci = b.check_in_time ? b.check_in_time.slice(0, 5) : '&mdash;';
            const co = b.check_out_time ? b.check_out_time.slice(0, 5) : '&mdash;';
            return `<i class="bi bi-x-circle"></i> Cancelled booking &middot; ${ci} - ${co}`;
        }
        return `<i class="bi bi-x-circle"></i> Cancelled booking &middot; ${fmtDate(b.check_in)} - ${fmtDate(b.check_out)}`;
    }
    if (s === 'maintenance') {
        return `<i class="bi bi-wrench"></i> Under Maintenance`;
    }
    return '';
}

function renderCards(rooms) {
    const grid = document.getElementById('room-board-grid');
    if (!rooms || rooms.length === 0) {
        grid.innerHTML = '<div class="col-12 text-muted text-center py-5">No room data available.</div>';
        return;
    }

    updateRoomFilterCounts(rooms);
    const filtered = rooms.filter(room => currentFilter === 'all' || room.status === currentFilter);
    if (filtered.length === 0) {
        grid.innerHTML = '<div class="col-12 text-muted text-center py-5">No rooms match this filter.</div>';
        return;
    }

    grid.innerHTML = filtered.map(room => {
        const detail = buildDetail(room);
        return `
            <div class="col-6 col-sm-4 col-md-3 col-xl-2">
                <div class="room-card status-${room.status}"
                     data-room='${JSON.stringify(room).replace(/'/g, "&#39;")}'
                     onclick="openEditModal(this)">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="room-number">${room.room_number}</div>
                        <span class="status-badge ${badgeClass(room.status)}">${badgeLabel(room.status)}</span>
                    </div>
                    <div class="room-type">${room.room_type}</div>
                    <div class="room-detail">${detail}</div>
                </div>
            </div>`;
    }).join('');
}

function openEditModal(cardEl) {
    const room = JSON.parse(cardEl.dataset.room);
    currentRoomNum = room.room_number;

    document.getElementById('modal-room-number').textContent = 'Room ' + room.room_number;
    document.getElementById('modal-room-type').textContent = room.room_type || '&mdash;';

    const liveBadge = document.getElementById('modal-live-badge');
    liveBadge.className = 'status-badge ' + badgeClass(room.status);
    liveBadge.textContent = badgeLabel(room.status);

    const detailBlock = document.getElementById('modal-detail-block');
    const detailList = document.getElementById('modal-detail-list');
    detailList.innerHTML = '';

    if (room.status === 'occupied') {
        detailBlock.classList.remove('d-none');
        const hoursRow = room.hours_staying != null
            ? `<dt>Hours Staying</dt><dd>${room.hours_staying} hr(s)</dd>`
            : '';
        detailList.innerHTML = `<dt>Available At</dt><dd>${fmtTime(room.available_at)}</dd>${hoursRow}`;
    } else if (room.status === 'booked' && room.booking_info) {
        detailBlock.classList.remove('d-none');
        const b = room.booking_info;
        let rows = `<dt>Guest</dt><dd>${b.guest_name || '&mdash;'}</dd>
                    <dt>Booking Type</dt><dd>${b.booking_type || '&mdash;'}</dd>`;
        if (b.booking_type === 'hourly') {
            const ci = b.check_in_time ? b.check_in_time.slice(0, 5) : '&mdash;';
            const co = b.check_out_time ? b.check_out_time.slice(0, 5) : '&mdash;';
            rows += `<dt>Time Slot</dt><dd>${ci} - ${co}</dd>`;
        } else {
            rows += `<dt>Check-in</dt><dd>${fmtDate(b.check_in)}</dd>
                     <dt>Check-out</dt><dd>${fmtDate(b.check_out)}</dd>`;
        }
        detailList.innerHTML = rows;
    } else if (room.status === 'cancelled' && room.cancelled_info) {
        detailBlock.classList.remove('d-none');
        const b = room.cancelled_info;
        let rows = `<dt>Guest</dt><dd>${b.guest_name || '&mdash;'}</dd>
                    <dt>Booking Type</dt><dd>${b.booking_type || '&mdash;'}</dd>`;
        if (b.booking_type === 'hourly') {
            const ci = b.check_in_time ? b.check_in_time.slice(0, 5) : '&mdash;';
            const co = b.check_out_time ? b.check_out_time.slice(0, 5) : '&mdash;';
            rows += `<dt>Cancelled Slot</dt><dd>${ci} - ${co}</dd>`;
        } else {
            rows += `<dt>Cancelled Stay</dt><dd>${fmtDate(b.check_in)} - ${fmtDate(b.check_out)}</dd>`;
        }
        detailList.innerHTML = rows;
    } else if (room.status === 'maintenance' && room.maintenance_note) {
        detailBlock.classList.remove('d-none');
        detailList.innerHTML = `<dt>Note</dt><dd>${room.maintenance_note}</dd>`;
    } else {
        detailBlock.classList.add('d-none');
    }

    const select = document.getElementById('modal-status-select');
    select.value = room.status === 'maintenance' ? 'maintenance' : 'available';

    // Force-release only makes sense while a guest is checked in.
    const forceOpt = select.querySelector('option[value="force_release"]');
    if (forceOpt) forceOpt.remove();
    if (room.status === 'occupied') {
        const relOpt = document.createElement('option');
        relOpt.value = 'force_release';
        relOpt.textContent = 'Force release (check guest out & free room)';
        select.insertBefore(relOpt, select.firstChild);
    }
    document.getElementById('modal-maint-note').value = room.maintenance_note || '';
    toggleRoomNoteField();

    const msg = document.getElementById('modal-save-msg');
    msg.className = 'alert py-2 d-none';
    msg.textContent = '';

    if (roomEditModal) roomEditModal.show();
}

function toggleRoomNoteField() {
    const select = document.getElementById('modal-status-select');
    const noteWrap = document.getElementById('modal-note-wrap');
    if (select && noteWrap) {
        noteWrap.classList.toggle('d-none', select.value !== 'maintenance');
    }
}

function saveRoomOverride() {
    const selectedStatus = document.getElementById('modal-status-select').value;
    const note = document.getElementById('modal-maint-note').value.trim();
    const msg = document.getElementById('modal-save-msg');
    const btn = document.getElementById('modal-save-btn');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

    const body = new URLSearchParams({
        room_number: currentRoomNum,
        status: selectedStatus,
        maintenance_note: note,
        csrf_token: '<?= $_SESSION['csrf_token'] ?? '' ?>'
    });

    fetch('../admin/update_room_status.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Save';
            msg.classList.remove('d-none');
            if (res.success) {
                msg.className = 'alert alert-success py-2';
                msg.textContent = selectedStatus === 'cancelled'
                    ? 'Booking cancelled. Refreshing board...'
                    : 'Saved. Refreshing board...';
                setTimeout(() => {
                    if (roomEditModal) roomEditModal.hide();
                    fetchRoomBoard(true);
                }, 900);
            } else {
                msg.className = 'alert alert-danger py-2';
                msg.textContent = res.message || 'Save failed.';
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Save';
            msg.className = 'alert alert-danger py-2';
            msg.textContent = 'Network error. Please try again.';
            msg.classList.remove('d-none');
        });
}

function fetchRoomBoard(manual = false) {
    const status = document.getElementById('availRefreshStatus');
    const last = document.getElementById('availLastUpdated');
    if (status) status.textContent = manual ? 'Refreshing...' : 'Loading...';

    fetch('../admin/get_room_board.php', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            const rooms = Array.isArray(data) ? data : [];
            roomData = rooms;
            renderCards(rooms);
            secondsSince = 0;
            // The hotel's clock, not this PC's (includes/server_clock.php): this label is
            // read by whoever is looking at it, and a device whose clock is out used to
            // stamp it with the wrong time.
            const timeStr = window.MarleClock
                ? MarleClock.formatClockSecs(MarleClock.nowMs())
                : new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });   // marle-clock-ok: fallback for a page whose clock asset is missing; locale pinned so a 24-hour device still prints the board's clock
            if (last) last.textContent = 'Last updated: ' + timeStr;
            if (status) status.textContent = '';
        })
        .catch(() => {
            if (status) status.textContent = 'Error';
            const grid = document.getElementById('room-board-grid');
            grid.innerHTML = '<div class="col-12 text-danger text-center py-5">Failed to load room availability.</div>';
        });
}

function startTicker() {
    if (countdownTimer) clearInterval(countdownTimer);
    countdownTimer = setInterval(() => {
        secondsSince++;
        const last = document.getElementById('availLastUpdated');
        if (last) {
            if (secondsSince < 60) {
                last.textContent = `Last updated: ${secondsSince}s ago`;
            } else {
                const m = Math.floor(secondsSince / 60);
                last.textContent = `Last updated: ${m}m ago`;
            }
        }
        if (secondsSince >= REFRESH_INTERVAL) {
            secondsSince = 0;
            fetchRoomBoard();
        }
    }, 1000);
}

document.addEventListener('DOMContentLoaded', function () {
    const roomModalEl = document.getElementById('roomEditModal');
    if (roomModalEl) {
        roomEditModal = new bootstrap.Modal(roomModalEl);
    }
    const roomStatusSelect = document.getElementById('modal-status-select');
    if (roomStatusSelect) {
        roomStatusSelect.addEventListener('change', toggleRoomNoteField);
    }
    const roomSaveBtn = document.getElementById('modal-save-btn');
    if (roomSaveBtn) {
        roomSaveBtn.addEventListener('click', saveRoomOverride);
    }

    document.querySelectorAll('.room-board-filter-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.room-board-filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentFilter = btn.dataset.filter;
            renderCards(roomData);
        });
    });

    fetchRoomBoard();
    startTicker();

    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('hidden.bs.modal', function () {
            fetchRoomBoard(true);
        });
    });
});
</script>

    <!-- Footer — one design for all three roles (includes/staff_footer.php) -->
    <?php $marleFooterTerms = false; require __DIR__ . '/../includes/staff_footer.php'; ?>
</body>
</html>





