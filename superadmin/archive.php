<?php
// MIGRATION REQUIRED (run once):
// ALTER TABLE `archivetbl` ADD COLUMN `archived_at` DATETIME DEFAULT NULL;

include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');
// The hotel's date, for the CSV export's filename further down (includes/server_clock.php).
// Required here, above that export block, because the page's own clock asset is emitted
// in the head — long after the export has already sent its headers.
require_once __DIR__ . '/../includes/server_clock.php';
include('../superadmin/auth.php');
require_once __DIR__ . '/../includes/log_action.php';
include('../superadmin/requests_badge.php');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function lower_text(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function text_slice(string $value, int $start, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
}

function normalizeAdditionals($value): string
{
    $value = trim((string)$value);

    if ($value === '' || lower_text($value) === 'none' || lower_text($value) === 'null') {
        return '{}';
    }

    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return '{}';
    }

    $normalized = [];
    foreach ($decoded as $name => $qty) {
        $name = trim((string)$name);
        $qty = is_numeric($qty) ? (int)$qty : 0;
        if ($name !== '' && $qty > 0) {
            $normalized[$name] = $qty;
        }
    }

    if (empty($normalized)) {
        return '{}';
    }

    $json = json_encode($normalized, JSON_UNESCAPED_SLASHES);
    return $json !== false ? $json : '{}';
}

function additionalsDisplayText($value): string
{
    $decoded = json_decode(normalizeAdditionals($value), true);
    if (!is_array($decoded) || empty($decoded)) {
        return 'None';
    }

    $items = [];
    foreach ($decoded as $name => $qty) {
        $qty = (int)$qty;
        if ($qty > 0) {
            $items[] = trim((string)$name) . ' (' . $qty . ')';
        }
    }

    return !empty($items) ? implode(', ', $items) : 'None';
}

function truncateText(string $value, int $limit = 25): string
{
    if (text_length($value) <= $limit) {
        return $value;
    }

    return rtrim(text_slice($value, 0, $limit)) . '...';
}

function formatDateTimeDisplay($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    $ts = strtotime($value);
    return $ts ? date('M j, Y g:i A', $ts) : '-';
}

$hasArchivedAt = false;
$colResult = $conn->query("SHOW COLUMNS FROM archivetbl LIKE 'archived_at'");
if ($colResult && $colResult->num_rows > 0) {
    $hasArchivedAt = true;
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: ../login.php?logout=success");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

$allowedSorts = ['today', 'week', 'month'];
$sort = ($hasArchivedAt && in_array($_GET['sort'] ?? '', $allowedSorts, true)) ? $_GET['sort'] : '';
$dateFilterRaw = trim((string)($_GET['date'] ?? ''));
$dateFilterDt = DateTime::createFromFormat('Y-m-d', $dateFilterRaw);
$dateFilterErrors = DateTime::getLastErrors();
$dateFilter = ($hasArchivedAt && $dateFilterDt && ($dateFilterErrors === false || ($dateFilterErrors['warning_count'] === 0 && $dateFilterErrors['error_count'] === 0)))
    ? $dateFilterDt->format('Y-m-d')
    : '';
$whereSql = '';

if ($hasArchivedAt) {
    if ($dateFilter !== '') {
        $escapedDate = $conn->real_escape_string($dateFilter);
        $whereSql = "WHERE archived_at >= '" . $escapedDate . " 00:00:00'
                     AND archived_at < DATE_ADD('" . $escapedDate . " 00:00:00', INTERVAL 1 DAY)";
    } elseif ($sort === 'today') {
        $whereSql = "WHERE archived_at >= CURDATE() AND archived_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
    } elseif ($sort === 'week') {
        $whereSql = "WHERE YEARWEEK(archived_at, 1) = YEARWEEK(CURDATE(), 1)";
    } elseif ($sort === 'month') {
        $whereSql = "WHERE YEAR(archived_at) = YEAR(CURDATE()) AND MONTH(archived_at) = MONTH(CURDATE())";
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="guest_archive_' . marle_hotel_date(0, $conn) . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Room', 'Transportation', 'Plate No', 'Date From', 'Date To', 'Hours', 'Additionals', 'Archived At']);

    $exportSql = $hasArchivedAt
        ? "SELECT * FROM archivetbl $whereSql ORDER BY archived_at DESC, id DESC"
        : "SELECT * FROM archivetbl ORDER BY id DESC";
    $res = $conn->query($exportSql);
    while ($res && $r = $res->fetch_assoc()) {
        $addText = additionalsDisplayText($r['additionals'] ?? '{}');
        fputcsv($out, [
            $r['id'] ?? '',
            $r['room_no'] ?? '',
            $r['transportation'] ?? '',
            $r['plate_no'] ?? '',
            $r['date_from'] ?? '',
            $r['date_to'] ?? '',
            $r['hours'] ?? '',
            $addText,
            $r['archived_at'] ?? ''
        ]);
    }

    fclose($out);
    exit;
}

if (isset($_POST['restore_id'])) {
    $id = (int)($_POST['restore_id'] ?? 0);
    $restored = false;

    if ($id > 0) {
        $stmt = $conn->prepare("SELECT * FROM archivetbl WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $additionals = normalizeAdditionals($row['additionals'] ?? '{}');
            $stmt = $conn->prepare(
                "INSERT INTO customerstbl (room_no, transportation, persons, plate_no, date_from, date_to, hours, additional)
                 VALUES (?, ?, 1, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "ssssss",
                $row['room_no'],
                $row['transportation'],
                $row['plate_no'],
                $row['date_from'],
                $row['date_to'],
                $row['hours'],
                $additionals
            );

            if ($stmt->execute()) {
                $del = $conn->prepare("DELETE FROM archivetbl WHERE id = ?");
                $del->bind_param("i", $id);
                $restored = $del->execute();
                $del->close();
            }

            $stmt->close();
        }
    }

    if ($restored) {
        log_event($conn, 'guest_stay_restored', 'archive', $id, 'success', 'Restored from guest archive');
    }
    header("Location: ../superadmin/archive.php" . ($restored ? "?restored=1" : ""));
    exit;
}

if (isset($_POST['archive_id'])) {
    $id = (int)($_POST['archive_id'] ?? 0);
    $archived = false;

    if ($id > 0) {
        $stmt = $conn->prepare("SELECT * FROM customerstbl WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $additionals = normalizeAdditionals($row['additional'] ?? '{}');

            if ($hasArchivedAt) {
                $stmt = $conn->prepare(
                    "INSERT INTO archivetbl (room_no, transportation, plate_no, date_from, date_to, hours, additionals, archived_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
                );
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO archivetbl (room_no, transportation, plate_no, date_from, date_to, hours, additionals)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
            }

            $stmt->bind_param(
                "sssssss",
                $row['room_no'],
                $row['transportation'],
                $row['plate_no'],
                $row['date_from'],
                $row['date_to'],
                $row['hours'],
                $additionals
            );

            if ($stmt->execute()) {
                $del = $conn->prepare("DELETE FROM customerstbl WHERE id = ?");
                $del->bind_param("i", $id);
                $archived = $del->execute();
                $del->close();
            }

            $stmt->close();
        }
    }

    if ($archived) {
        log_event($conn, 'guest_stay_archived', 'archive', $id, 'success', 'Moved from guest records to archive');
    }
    header("Location: ../superadmin/archive.php" . ($archived ? "?archived=1" : ""));
    exit;
}

if (isset($_POST['delete_id'])) {
    $did = (int)($_POST['delete_id'] ?? 0);
    if ($did > 0) {
        $del = $conn->prepare("DELETE FROM archivetbl WHERE id = ?");
        $del->bind_param("i", $did);
        $del->execute();
        $del->close();
    }

    log_event($conn, 'guest_archive_deleted', 'archive', $did, 'success', 'Permanent delete');
    header("Location: ../superadmin/archive.php?deleted=1");
    exit;
}

$rows_data = [];
$archiveSql = $hasArchivedAt
    ? "SELECT * FROM archivetbl $whereSql ORDER BY archived_at DESC, id DESC"
    : "SELECT * FROM archivetbl ORDER BY id DESC";
$res = $conn->query($archiveSql);
while ($res && $r = $res->fetch_assoc()) {
    $rows_data[] = $r;
}
$totalArchived = count($rows_data);

$archivedDates = [];
foreach ($rows_data as $row) {
    $archivedAt = trim((string)($row['archived_at'] ?? ''));
    if ($archivedAt !== '') {
        $archivedDates[] = $archivedAt;
    }
}
$oldestDate = !empty($archivedDates) ? min($archivedDates) : null;

$roomValues = [];
foreach ($rows_data as $row) {
    $roomNo = trim((string)($row['room_no'] ?? ''));
    if ($roomNo !== '') {
        $roomValues[] = $roomNo;
    }
}
$roomsArchived = count(array_unique($roomValues));

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
        $imgSrc = "data:$mimeType;base64," . base64_encode($userRow['picture']);
    }
}
$stmtUser->close();

$exportParams = ['export' => 'csv'];
if ($sort !== '') {
    $exportParams['sort'] = $sort;
}
if ($dateFilter !== '') {
    $exportParams['date'] = $dateFilter;
}
$exportHref = '?' . http_build_query($exportParams);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-4Q6Gf2aSP4eDXB8Miphtr37CMZZQ5oXLH2yaXMJ2w8e2ZtHTl7GptT4jmndRuHDT" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.lineicons.com/5.0/lineicons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <?php require __DIR__ . '/../includes/staff_icons.php'; ?>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
    <link rel="stylesheet" href="../css/staff-footer.css?v=20261021">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <title>Guest Archive | Hotel Admin</title>
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

        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--bg);
            overflow-x: hidden;
            color: var(--text);
        }

        @media (min-width: 768px) {
            .navbar {
                width: var(--sidebar-w);
                height: 100vh;
                position: fixed;
                top: 0;
                left: 0;
                background: var(--card-bg);
                display: flex;
                flex-direction: column;
                border-right: 1px solid var(--border);
                box-shadow: 2px 0 12px rgba(0, 0, 0, 0.04);
            }

            .navbar > .container-fluid {
                height: 100%;
                display: flex;
                flex-direction: column;
            }

            .navbar-collapse {
                display: flex !important;
                flex-direction: column;
                flex: 1;
            }

            .content,
            .main-content {
                margin-left: var(--sidebar-w);
            }

            .content {
                padding: 22px 28px 0;
            }

            .main-content {
                padding: 0 28px 40px;
            }
        }

        .nav-link {
            padding: 10px 16px;
            color: var(--muted);
            border-radius: 10px;
            position: relative;
            transition: all 0.2s;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .nav-link:hover {
            background: #fff7e6;
            color: var(--amber-dark);
        }

        .nav-link.active {
            background: var(--amber);
            color: #fff !important;
            font-weight: 600;
        }

        .nav-link.active::before {
            content: "";
            position: absolute;
            left: -12px;
            top: 10px;
            bottom: 10px;
            width: 4px;
            border-radius: 4px;
            background: var(--amber);
        }

        .nav-link i { font-size: 17px; }

        .notification-badge {
            background: var(--amber);
            color: #fff;
            font-size: 11px;
            padding: 4px 7px;
        }

        .logout-btn {
            background: var(--brown);
            color: #fff;
            border: none;
            padding: 10px;
            border-radius: 10px;
            transition: 0.2s;
            font-size: 0.9rem;
        }

        .logout-btn:hover {
            background: transparent;
            border: 1px solid var(--brown);
            color: var(--brown);
        }

        .bi-bell {
            font-size: 19px;
            padding: 7px;
            border-radius: 10px;
            background: var(--card-bg);
            color: var(--text);
        }

        a { color: var(--amber-dark); }

        .topbar-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.6rem;
            color: var(--text);
            font-weight: 700;
        }

        .breadcrumb-line {
            font-size: 0.82rem;
            color: var(--muted);
        }

        .breadcrumb-line a {
            color: var(--amber-dark);
            text-decoration: none;
        }

        .stat-cards-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 14px;
            border: 1px solid var(--border);
            padding: 18px 22px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
            min-width: 180px;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .stat-icon.gray { background: #f0ece8; color: var(--muted); }
        .stat-icon.amber { background: #fff8e1; color: var(--amber-dark); }
        .stat-icon.muted { background: #f0ece8; color: var(--muted); }

        .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 2px;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text);
            line-height: 1;
        }

        .stat-value.small { font-size: 1rem; }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 10px;
        }

        .toolbar-left,
        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        #myInputArchive {
            border: 2px solid var(--border);
            height: 42px;
            border-radius: 10px;
            width: 280px;
            font-size: 0.88rem;
            padding: 0 14px;
            background: var(--card-bg);
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        #myInputArchive:focus {
            border-color: var(--amber);
            box-shadow: 0 0 0 3px rgba(255, 182, 10, 0.15);
            outline: none;
        }

        .back-btn,
        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: transparent;
            color: var(--muted);
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: 8px 18px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: 0.2s;
        }

        .back-btn:hover {
            border-color: var(--amber-dark);
            color: var(--amber-dark);
            background: #fffbf0;
        }

        .btn-export:hover {
            border-color: #16a34a;
            color: #16a34a;
            background: #f0fdf4;
        }

        .date-filter-form {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .date-filter-input {
            border: 2px solid var(--border);
            height: 42px;
            border-radius: 10px;
            padding: 0 12px;
            font-size: 0.86rem;
            background: var(--card-bg);
            color: var(--text);
            min-width: 165px;
        }

        .date-filter-input:focus {
            border-color: var(--amber);
            box-shadow: 0 0 0 3px rgba(255, 182, 10, 0.15);
            outline: none;
        }

        .date-filter-btn,
        .date-filter-clear {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            height: 42px;
            border-radius: 10px;
            padding: 0 16px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: 0.2s;
        }

        .date-filter-btn {
            border: 1.5px solid var(--border);
            background: var(--card-bg);
            color: var(--muted);
        }

        .date-filter-btn:hover {
            border-color: var(--amber-dark);
            color: var(--amber-dark);
            background: #fffbf0;
        }

        .date-filter-clear {
            border: 1.5px solid transparent;
            background: transparent;
            color: var(--muted);
        }

        .date-filter-clear:hover {
            border-color: var(--border);
            background: #fffaf5;
            color: var(--text);
        }

        .cat-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 14px;
        }

        .cat-tab {
            padding: 5px 14px;
            border-radius: 99px;
            font-size: 0.78rem;
            font-weight: 600;
            border: 1.5px solid var(--border);
            background: var(--card-bg);
            color: var(--muted);
            cursor: pointer;
            transition: 0.18s;
            white-space: nowrap;
            text-decoration: none;
        }

        .cat-tab:hover {
            border-color: var(--amber);
            color: var(--amber-dark);
            background: #fffbf0;
        }

        .cat-tab.active {
            background: var(--amber);
            border-color: var(--amber);
            color: #fff;
        }

        #resultCount {
            font-size: 0.8rem;
            color: var(--muted);
            margin-bottom: 12px;
        }

        .table-card {
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .table-scroll-wrap { overflow-x: auto; }

        .table-card table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }

        .table-card thead th {
            background: #fdf8f2;
            color: var(--muted);
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            padding: 13px 16px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
            user-select: none;
        }

        .table-card thead th.sortable { cursor: pointer; }
        .table-card thead th.sortable:hover { color: var(--amber-dark); }

        .sort-icon {
            margin-left: 4px;
            opacity: 0.45;
            font-size: 0.7rem;
        }

        .table-card thead th.sort-asc .sort-icon::after { content: "↑"; opacity: 1; }
        .table-card thead th.sort-desc .sort-icon::after { content: "↓"; opacity: 1; }
        .table-card thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: "↕"; }

        .table-card tbody tr {
            border-bottom: 1px solid #f5ede6;
            transition: background 0.15s;
        }

        .table-card tbody tr:last-child { border-bottom: none; }
        .table-card tbody tr:hover { background: #fffbf3; }

        .table-card tbody td {
            padding: 13px 16px;
            vertical-align: middle;
            color: var(--text);
        }

        .room-value {
            font-weight: 700;
            color: var(--text);
            white-space: nowrap;
        }

        .transport-value,
        .hours-value {
            font-weight: 600;
        }

        .date-cell,
        .date-archived {
            font-size: 0.82rem;
            color: var(--muted);
            white-space: nowrap;
        }

        .additionals-text {
            display: inline-block;
            max-width: 220px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: bottom;
        }

        .btn-act {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: transparent;
            border: 1.5px solid var(--border);
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.18s;
        }

        .btn-act-restore {
            border-color: #86efac;
            color: #16a34a;
        }

        .btn-act-restore:hover {
            background: #16a34a;
            color: #fff;
            border-color: #16a34a;
        }

        .btn-act-delete {
            border-color: #fca5a5;
            color: #dc2626;
        }

        .btn-act-delete:hover {
            background: #dc2626;
            color: #fff;
            border-color: #dc2626;
        }

        .actions-cell {
            display: flex;
            gap: 6px;
            align-items: center;
            white-space: nowrap;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 12px;
            opacity: 0.4;
            display: block;
        }

        .empty-state p {
            font-size: 0.9rem;
            margin: 0;
        }

        .text-muted-custom { color: var(--muted); }

        .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        }

        .modal-header {
            border-bottom: 1px solid var(--border);
            padding: 18px 22px;
        }

        .modal-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.1rem;
            font-weight: 700;
        }

        .modal-body { padding: 20px 22px; }

        .modal-footer {
            border-top: 1px solid var(--border);
            padding: 14px 22px;
        }

        .btn-modal-cancel {
            background: #f5f5f5;
            color: var(--muted);
            border: none;
            border-radius: 9px;
            padding: 8px 18px;
            font-weight: 500;
            font-size: 0.88rem;
            cursor: pointer;
        }

        .btn-modal-cancel:hover {
            background: #ebebeb;
            color: var(--text);
        }

        .btn-modal-save {
            background: var(--amber);
            color: #fff;
            border: none;
            border-radius: 9px;
            padding: 8px 22px;
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
        }

        .btn-modal-save:hover {
            background: var(--amber-dark);
            color: #fff;
        }

        .toast {
            border-radius: 12px;
            font-size: 0.88rem;
            font-weight: 500;
        }
    </style>
<?php require_once __DIR__ . '/../includes/server_clock.php'; marle_server_clock_assets(); ?>
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>
<body class="staff-page staff-customer-page staff-superadmin">
<?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

<?php
    $staffPageTitle  = 'Guest Archive';
    $staffPageNote   = 'Stays older than 60 days, kept for reference.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['Guest Archive', null]];
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

<div class="main-content">
    <div class="stat-cards-row">
        <div class="stat-card">
            <div class="stat-icon gray"><i class="bi bi-archive"></i></div>
            <div>
                <div class="stat-label">Archived Records</div>
                <div class="stat-value"><?= h($totalArchived) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber"><i class="bi bi-door-open"></i></div>
            <div>
                <div class="stat-label">Rooms Involved</div>
                <div class="stat-value"><?= h($roomsArchived) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon muted"><i class="bi bi-clock-history"></i></div>
            <div>
                <div class="stat-label">Oldest Archive</div>
                <div class="stat-value small" id="oldestArchiveStat"<?= $oldestDate ? ' data-ts="' . h($oldestDate) . '"' : '' ?>>
                    <?= h($oldestDate ?: '-') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="toolbar">
        <div class="toolbar-left">
            <input type="text" id="myInputArchive" placeholder="&#x1F50D; Search archive...">
            <?php if ($hasArchivedAt): ?>
                <form method="GET" class="date-filter-form">
                    <input type="date" name="date" class="date-filter-input" value="<?= h($dateFilter) ?>" aria-label="Filter archived date">
                    <button type="submit" class="date-filter-btn"><i class="bi bi-calendar3"></i> Filter Date</button>
                    <?php if ($dateFilter !== '' || $sort !== ''): ?>
                        <a href="archive.php" class="date-filter-clear">Clear</a>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
        <div class="toolbar-right">
            <a href="<?= h($exportHref) ?>" class="btn-export"><i class="bi bi-download"></i> Export CSV</a>
            <a href="../superadmin/customerRC.php" class="back-btn"><i class="bi bi-arrow-left"></i> Back to Guest Record</a>
        </div>
    </div>

    <?php if ($hasArchivedAt): ?>
        <div class="cat-tabs">
            <a href="archive.php" class="cat-tab <?= $sort === '' && $dateFilter === '' ? 'active' : '' ?>">All</a>
            <a href="?sort=today" class="cat-tab <?= $sort === 'today' ? 'active' : '' ?>">Today</a>
            <a href="?sort=week" class="cat-tab <?= $sort === 'week' ? 'active' : '' ?>">This Week</a>
            <a href="?sort=month" class="cat-tab <?= $sort === 'month' ? 'active' : '' ?>">This Month</a>
        </div>
    <?php endif; ?>

    <div id="resultCount">Showing <?= h($totalArchived) ?> of <?= h($totalArchived) ?> archived records</div>

    <div class="table-card">
        <div class="table-scroll-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="sortable" data-col="room">Room <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="transport">Transportation <span class="sort-icon"></span></th>
                        <th>Plate No.</th>
                        <th class="sortable" data-col="hours">Hours <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="datefrom">Date From <span class="sort-icon"></span></th>
                        <th>Date To</th>
                        <th>Additionals</th>
                        <th class="sortable sort-desc" data-col="archived">Date Archived <span class="sort-icon"></span></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="myTable">
                    <?php if (empty($rows_data)): ?>
                        <tr id="emptyRow"><td colspan="9">
                            <div class="empty-state">
                                <i class="bi bi-archive"></i>
                                <p>No archived records. Records you archive from Guest Record will appear here.</p>
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($rows_data as $row): ?>
                            <?php
                            $id = (int)($row['id'] ?? 0);
                            $roomNo = trim((string)($row['room_no'] ?? ''));
                            $transportation = trim((string)($row['transportation'] ?? ''));
                            $plateNo = trim((string)($row['plate_no'] ?? ''));
                            $hours = trim((string)($row['hours'] ?? ''));
                            $dateFromRaw = trim((string)($row['date_from'] ?? ''));
                            $dateToRaw = trim((string)($row['date_to'] ?? ''));
                            $archivedRaw = trim((string)($row['archived_at'] ?? ''));
                            $dateFromDisplay = formatDateTimeDisplay($dateFromRaw);
                            $dateToDisplay = formatDateTimeDisplay($dateToRaw);
                            $archivedDisplay = formatDateTimeDisplay($archivedRaw);
                            $addText = additionalsDisplayText($row['additionals'] ?? '{}');
                            $addShort = truncateText($addText, 25);
                            ?>
                            <tr
                                data-room="<?= h($roomNo) ?>"
                                data-transport="<?= h(lower_text($transportation)) ?>"
                                data-plate="<?= h(lower_text($plateNo)) ?>"
                                data-additionals="<?= h(lower_text($addText)) ?>"
                                data-hours="<?= h(lower_text($hours)) ?>"
                                data-datefrom="<?= h($dateFromRaw) ?>"
                                data-archived="<?= h($archivedRaw) ?>"
                            >
                                <td><span class="room-value"><?= h($roomNo) ?></span></td>
                                <td><span class="transport-value"><?= h($transportation) ?></span></td>
                                <td>
                                    <?php if ($plateNo !== ''): ?>
                                        <?= h($plateNo) ?>
                                    <?php else: ?>
                                        <span class="text-muted-custom">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="hours-value"><?= h($hours !== '' ? $hours : '-') ?></span></td>
                                <td><span class="date-cell"><?= h($dateFromDisplay) ?></span></td>
                                <td><span class="date-cell"><?= h($dateToDisplay) ?></span></td>
                                <td title="<?= h($addText) ?>"><span class="additionals-text"><?= h($addShort) ?></span></td>
                                <td>
                                    <span class="date-archived" data-ts="<?= h($archivedRaw) ?>" title="<?= h($archivedDisplay) ?>">
                                        <?= h($archivedRaw !== '' ? $archivedDisplay : '-') ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="actions-cell">
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="restore_id" value="<?= h($id) ?>">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                            <button type="submit" class="btn-act btn-act-restore" title="Restore">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                            </button>
                                        </form>
                                        <button class="btn-act btn-act-delete deleteBtn" data-id="<?= h($id) ?>" title="Delete permanently">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="emptyRow" style="display:none;"><td colspan="9">
                            <div class="empty-state">
                                <i class="bi bi-archive"></i>
                                <p>No archived records. Records you archive from Guest Record will appear here.</p>
                            </div>
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:9999;">
    <div id="toastRestored" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body"><i class="bi bi-check-circle me-2"></i>Record restored to Guest Record.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
    <div id="toastDeleted" class="toast align-items-center text-bg-secondary border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body"><i class="bi bi-trash me-2"></i>Record permanently deleted.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
    <div id="toastArchived" class="toast align-items-center text-bg-secondary border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body"><i class="bi bi-archive me-2"></i>Record archived.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="delete_id" id="delete-id">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Permanently Delete Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="bi bi-trash fs-1 text-danger d-block mb-3"></i>
                    <p class="mb-0" style="font-size:0.95rem;">
                        This record will be <strong>permanently deleted</strong>.<br>
                        This action cannot be undone.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-modal-save" style="background:#dc2626;">Yes, Delete</button>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-j1CDi7MgGQ12Z7Qab0qlWQ/Qqz24Gc6BM0thvEMVjHnfYGF0rmFCozFSxQBxwHKO"
    crossorigin="anonymous"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const state = { search: '', sort: 'all' };
    const tbody = document.getElementById('myTable');
    // Pinned locale: with `undefined` the sort order came from the viewing device, so the
// same table could order differently on two desks.
    const collator = new Intl.Collator('en', { numeric: true, sensitivity: 'base' });

    /* A stored hotel stamp as a sortable instant.
       new Date('2026-10-12 03:00:00') is a non-standard parse — Chrome and Firefox
       accept it, Safari returns Invalid Date — so on an iPhone these two columns
       silently did not sort at all. MarleClock reads the stamp's own parts
       (includes/server_clock.php); the Date fallback covers a missing clock asset. */
    function wallTs(value) {
        const clock = window.MarleClock;
        if (clock) { const t = clock.parseWall(value); if (!isNaN(t)) return t; }
        const t = new Date(String(value || '').replace(' ', 'T')).getTime();
        return isNaN(t) ? 0 : t;   // no date at all sorts as the oldest
    }

    /* Measured against the HOTEL's clock, with the stamp read as the hotel's own wall
       time (includes/server_clock.php). This used to subtract the viewing device's clock
       and parse the stamp in its timezone, so an archive from this morning could read
       "1d ago" on a phone — or a time in the future. */
    function relativeTime(ts) {
        if (!ts) return '-';
        const clock = window.MarleClock;
        const then  = clock ? clock.parseWall(ts) : new Date(String(ts).replace(' ', 'T')).getTime();
        if (isNaN(then)) return '-';
        const diff = Math.floor(((clock ? clock.nowMs() : Date.now()) - then) / 1000);   // marle-clock-ok: only reached when includes/server_clock.php is absent; the live path counts from the hotel clock
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 86400 * 30) return Math.floor(diff / 86400) + 'd ago';
        return clock ? clock.formatWallDate(ts) : new Date(ts).toLocaleDateString('en-US');
    }

    function allRows() {
        return Array.from(document.querySelectorAll('#myTable tr[data-room]'));
    }

    function applyFilters() {
        const rows = allRows();
        let visible = 0;

        rows.forEach(function (row) {
            const values = [
                row.dataset.room || '',
                row.dataset.transport || '',
                row.dataset.plate || '',
                row.dataset.additionals || ''
            ];
            const matchSearch = !state.search || values.some(function (value) {
                return value.toLowerCase().includes(state.search);
            });

            row.style.display = matchSearch ? '' : 'none';
            if (matchSearch) {
                visible++;
            }
        });

        const emptyRow = document.getElementById('emptyRow');
        if (emptyRow) {
            emptyRow.style.display = visible === 0 ? '' : 'none';
        }

        const rc = document.getElementById('resultCount');
        if (rc) {
            rc.textContent = 'Showing ' + visible + ' of ' + rows.length + ' archived records';
        }
    }

    function sortRows(col, dir) {
        const rows = allRows();
        rows.sort(function (a, b) {
            let va = '';
            let vb = '';

            if (col === 'room') {
                va = a.dataset.room || '';
                vb = b.dataset.room || '';
            } else if (col === 'transport') {
                va = a.dataset.transport || '';
                vb = b.dataset.transport || '';
            } else if (col === 'hours') {
                va = a.dataset.hours || '';
                vb = b.dataset.hours || '';
            } else if (col === 'datefrom') {
                va = wallTs(a.dataset.datefrom);
                vb = wallTs(b.dataset.datefrom);
            } else if (col === 'archived') {
                va = wallTs(a.dataset.archived);
                vb = wallTs(b.dataset.archived);
            }

            if (typeof va === 'number' && typeof vb === 'number') {
                if (va < vb) return dir === 'asc' ? -1 : 1;
                if (va > vb) return dir === 'asc' ? 1 : -1;
                return 0;
            }

            const cmp = collator.compare(String(va), String(vb));
            return dir === 'asc' ? cmp : -cmp;
        });

        rows.forEach(function (row) {
            tbody.appendChild(row);
        });

        const emptyRow = document.getElementById('emptyRow');
        if (emptyRow) {
            tbody.appendChild(emptyRow);
        }
    }

    document.querySelectorAll('.date-archived[data-ts]').forEach(function (el) {
        if (el.dataset.ts) {
            if (!el.title || el.title === '-') {
                // The stored hotel stamp verbatim: toLocaleString() would render it in the
                // viewing device's own timezone and locale.
                el.title = el.dataset.ts;
            }
            el.textContent = relativeTime(el.dataset.ts);
        }
    });

    const oldestEl = document.getElementById('oldestArchiveStat');
    if (oldestEl && oldestEl.dataset.ts) {
        oldestEl.title = oldestEl.dataset.ts;
        oldestEl.textContent = relativeTime(oldestEl.dataset.ts);
    }

    const params = new URLSearchParams(window.location.search);
    let shouldCleanUrl = false;

    if (params.get('restored') === '1') {
        new bootstrap.Toast(document.getElementById('toastRestored'), { delay: 3500 }).show();
        shouldCleanUrl = true;
    }

    if (params.get('deleted') === '1') {
        new bootstrap.Toast(document.getElementById('toastDeleted'), { delay: 3500 }).show();
        shouldCleanUrl = true;
    }

    if (params.get('archived') === '1') {
        new bootstrap.Toast(document.getElementById('toastArchived'), { delay: 3500 }).show();
        shouldCleanUrl = true;
    }

    if (shouldCleanUrl) {
        const url = new URL(window.location.href);
        url.searchParams.delete('restored');
        url.searchParams.delete('deleted');
        url.searchParams.delete('archived');
        history.replaceState({}, document.title, url.pathname + (url.search ? url.search : '') + url.hash);
    }

    const searchInput = document.getElementById('myInputArchive');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            state.search = this.value.toLowerCase().trim();
            applyFilters();
        });
    }

    const sortState = { col: 'archived', dir: 'desc' };
    document.querySelectorAll('thead th.sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            const col = this.dataset.col;
            if (sortState.col === col) {
                sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
            } else {
                sortState.col = col;
                sortState.dir = 'asc';
            }

            document.querySelectorAll('thead th.sortable').forEach(function (header) {
                header.classList.remove('sort-asc', 'sort-desc');
            });

            this.classList.add('sort-' + sortState.dir);
            sortRows(sortState.col, sortState.dir);
            applyFilters();
        });
    });

    sortRows('archived', 'desc');
    applyFilters();

    const deleteModalElement = document.getElementById('deleteConfirmModal');
    if (deleteModalElement) {
        const deleteModal = new bootstrap.Modal(deleteModalElement);
        document.querySelectorAll('.deleteBtn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('delete-id').value = this.dataset.id;
                deleteModal.show();
            });
        });
    }
});
</script>

    <!-- Footer — one design for all three roles (includes/staff_footer.php) -->
    <?php $marleFooterTerms = false; require __DIR__ . '/../includes/staff_footer.php'; ?>
</body>
</html>



