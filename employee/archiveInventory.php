<?php
// TODO: Employee limited permissions need deeper checks on this page/action.
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');
// The hotel's date, for the CSV export's filename further down (includes/server_clock.php).
// Required here, above that export block, because the page's own clock asset is emitted
// in the head — long after the export has already sent its headers.
require_once __DIR__ . '/../includes/server_clock.php';
include('../employee/auth.php');
require_once __DIR__ . '/../includes/log_action.php';
include('../employee/requests_badge.php');

// ── CSRF Token ──────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Logout ───────────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: ../login.php?logout=success");
    exit();
}

// ── CSV Export ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory_archive_' . marle_hotel_date(0, $conn) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Item', 'Description', 'Category', 'Quantity', 'Low Stock Threshold', 'Archived At']);
    $res = $conn->query("SELECT id, item, description, category, quantity, low_stock_threshold, archived_at FROM inventory_archivetbl ORDER BY archived_at DESC");
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [$r['id'], $r['item'], $r['description'], $r['category'], $r['quantity'], $r['low_stock_threshold'], $r['archived_at']]);
    }
    fclose($out);
    exit;
}

// ── Bulk Archive from inventory.php ──────────────────────────────────────────
if (isset($_POST['bulk_archive_ids']) && is_array($_POST['bulk_archive_ids'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403); die('Invalid CSRF token.');
    }
    $ids = array_map('intval', $_POST['bulk_archive_ids']);
    $ids = array_filter($ids, fn($id) => $id > 0);
    foreach ($ids as $bid) {
        $sel = $conn->prepare("SELECT * FROM inventorytbl WHERE id = ?");
        $sel->bind_param("i", $bid);
        $sel->execute();
        $brow = $sel->get_result()->fetch_assoc();
        $sel->close();
        if ($brow) {
            $ins = $conn->prepare("INSERT INTO inventory_archivetbl (item, quantity, description, category, low_stock_threshold, archived_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $ins->bind_param("sissi", $brow['item'], $brow['quantity'], $brow['description'], $brow['category'], $brow['low_stock_threshold']);
            $ins->execute();
            $ins->close();
            $del = $conn->prepare("DELETE FROM inventorytbl WHERE id = ?");
            $del->bind_param("i", $bid);
            $del->execute();
            $del->close();
        }
    }
    log_event($conn, 'inventory_archived', 'inventory_item', null, 'success', isset($_POST['bulk_archive_ids']) ? count((array)$_POST['bulk_archive_ids']) . ' item(s)' : ('item #' . (int)($_POST['archive_id'] ?? 0)));
    header("Location: ../employee/inventory.php?archived=1");
    exit;
}

// ── Single Archive from inventory.php ────────────────────────────────────────
if (isset($_POST['archive_id'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403); die('Invalid CSRF token.');
    }
    $aid = (int)$_POST['archive_id'];
    $sel = $conn->prepare("SELECT * FROM inventorytbl WHERE id = ?");
    $sel->bind_param("i", $aid);
    $sel->execute();
    $arow = $sel->get_result()->fetch_assoc();
    $sel->close();
    if ($arow) {
        $ins = $conn->prepare("INSERT INTO inventory_archivetbl (item, quantity, description, category, low_stock_threshold, archived_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $ins->bind_param("sissi", $arow['item'], $arow['quantity'], $arow['description'], $arow['category'], $arow['low_stock_threshold']);
        $ins->execute();
        $ins->close();
        $del = $conn->prepare("DELETE FROM inventorytbl WHERE id = ?");
        $del->bind_param("i", $aid);
        $del->execute();
        $del->close();
    }
    log_event($conn, 'inventory_archived', 'inventory_item', null, 'success', isset($_POST['bulk_archive_ids']) ? count((array)$_POST['bulk_archive_ids']) . ' item(s)' : ('item #' . (int)($_POST['archive_id'] ?? 0)));
    header("Location: ../employee/inventory.php?archived=1");
    exit;
}

// ── Restore ───────────────────────────────────────────────────────────────────
if (isset($_POST['restore_id'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403); die('Invalid CSRF token.');
    }
    $id = (int)$_POST['restore_id'];
    $stmt = $conn->prepare("SELECT * FROM inventory_archivetbl WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $stmt = $conn->prepare("INSERT INTO inventorytbl (item, quantity, description, category, low_stock_threshold) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sissi", $row['item'], $row['quantity'], $row['description'], $row['category'], $row['low_stock_threshold']);
        if ($stmt->execute()) {
            $del = $conn->prepare("DELETE FROM inventory_archivetbl WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
        }
        $stmt->close();
    }
    log_event($conn, 'inventory_restored', 'inventory_item', null, 'success', isset($_POST['bulk_restore_ids']) ? count((array)$_POST['bulk_restore_ids']) . ' item(s)' : ('item #' . (int)($_POST['restore_id'] ?? 0)));
    header("Location: ../employee/inventory.php?restored=1");
    exit;
}

// ── Permanent Delete ──────────────────────────────────────────────────────────
if (isset($_POST['delete_id'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403); die('Invalid CSRF token.');
    }
    $did = (int)$_POST['delete_id'];
    $del = $conn->prepare("DELETE FROM inventory_archivetbl WHERE id = ?");
    $del->bind_param("i", $did);
    $del->execute();
    $del->close();
    log_event($conn, 'inventory_archive_deleted', 'inventory_item', (int)($_POST['delete_id'] ?? 0), 'success', 'Permanent delete');
    header("Location: ../employee/archiveInventory.php?deleted=1");
    exit;
}

// ── Bulk Restore ──────────────────────────────────────────────────────────────
if (isset($_POST['bulk_restore_ids']) && is_array($_POST['bulk_restore_ids'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403); die('Invalid CSRF token.');
    }
    $ids = array_filter(array_map('intval', $_POST['bulk_restore_ids']), fn($i) => $i > 0);
    foreach ($ids as $rid) {
        $sel = $conn->prepare("SELECT * FROM inventory_archivetbl WHERE id = ?");
        $sel->bind_param("i", $rid);
        $sel->execute();
        $rrow = $sel->get_result()->fetch_assoc();
        $sel->close();
        if ($rrow) {
            $ins = $conn->prepare("INSERT INTO inventorytbl (item, quantity, description, category, low_stock_threshold) VALUES (?, ?, ?, ?, ?)");
            $ins->bind_param("sissi", $rrow['item'], $rrow['quantity'], $rrow['description'], $rrow['category'], $rrow['low_stock_threshold']);
            $ins->execute();
            $ins->close();
            $del = $conn->prepare("DELETE FROM inventory_archivetbl WHERE id = ?");
            $del->bind_param("i", $rid);
            $del->execute();
            $del->close();
        }
    }
    log_event($conn, 'inventory_restored', 'inventory_item', null, 'success', isset($_POST['bulk_restore_ids']) ? count((array)$_POST['bulk_restore_ids']) . ' item(s)' : ('item #' . (int)($_POST['restore_id'] ?? 0)));
    header("Location: ../employee/inventory.php?restored=1");
    exit;
}

// ── Bulk Delete ───────────────────────────────────────────────────────────────
if (isset($_POST['bulk_delete_ids']) && is_array($_POST['bulk_delete_ids'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403); die('Invalid CSRF token.');
    }
    $ids = array_filter(array_map('intval', $_POST['bulk_delete_ids']), fn($i) => $i > 0);
    $count = count($ids);
    foreach ($ids as $did) {
        $del = $conn->prepare("DELETE FROM inventory_archivetbl WHERE id = ?");
        $del->bind_param("i", $did);
        $del->execute();
        $del->close();
    }
    log_event($conn, 'inventory_archive_deleted', 'inventory_item', null, 'success', $count . ' item(s) permanently deleted');
    header("Location: ../employee/archiveInventory.php?deleted=1&count=" . $count);
    exit;
}

// ── Fetch archived rows ───────────────────────────────────────────────────────
$rows_data = [];
$res = $conn->query("SELECT * FROM inventory_archivetbl ORDER BY archived_at DESC, id DESC");
while ($r = $res->fetch_assoc()) $rows_data[] = $r;
$totalArchived = count($rows_data);

// ── Stat card data ────────────────────────────────────────────────────────────
$distinctCats = count(array_unique(array_column($rows_data, 'category')));
$oldestDate   = !empty($rows_data) ? min(array_column($rows_data, 'archived_at')) : null;

// ── Profile picture (prepared statement) ─────────────────────────────────────
$username = $_SESSION['username'];
$stmtUser = $conn->prepare("SELECT picture FROM userstbl WHERE username = ? LIMIT 1");
$stmtUser->bind_param("s", $username);
$stmtUser->execute();
$resultUser = $stmtUser->get_result();
$imgSrc = "../img/default.jpg";
if ($resultUser && $resultUser->num_rows > 0) {
    $uRow = $resultUser->fetch_assoc();
    if (!empty($uRow['picture'])) {
        $finfo  = new finfo(FILEINFO_MIME_TYPE);
        $mime   = $finfo->buffer($uRow['picture']);
        $imgSrc = "data:$mime;base64," . base64_encode($uRow['picture']);
    }
}
$stmtUser->close();

// ── Categories present in archive (for pill tabs) ─────────────────────────────
$allCategories  = ['Linens','Toiletries','Food','Cleaning','Equipment','General'];
$presentCats    = array_unique(array_filter(array_column($rows_data, 'category')));
?>
<!DOCTYPE html>
<html lang="en">
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
    <link rel="stylesheet" href="../css/staff-inventory.css?v=20261021">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <title>Inventory Archive</title>

    <style>
        :root {
            --amber:      #ffb60a;
            --amber-dark: #e6a308;
            --brown:      #933310;
            --bg:         #f5ede6;
            --card-bg:    #ffffff;
            --text:       #1e1108;
            --muted:      #7a6455;
            --border:     #ecddd3;
            --sidebar-w:  230px;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'DM Sans', sans-serif;
            background-color: var(--bg);
            overflow-x: hidden;
            color: var(--text);
        }

        /* ── SIDEBAR ── */
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

        /* ── TOP BAR ── */
        .topbar-title { font-family: 'Playfair Display', serif; font-size: 1.6rem; color: var(--text); font-weight: 700; }
        .breadcrumb-line { font-size: 0.82rem; color: var(--muted); }
        .breadcrumb-line a { color: var(--amber-dark); text-decoration: none; }

        /* ── STAT CARDS ── */
        .stat-cards-row { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
        .stat-card {
            background: var(--card-bg); border-radius: 14px; border: 1px solid var(--border);
            padding: 18px 22px; display: flex; align-items: center; gap: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04); min-width: 180px;
        }
        .stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .stat-icon.gray   { background: #f0ece8; color: var(--muted); }
        .stat-icon.amber  { background: #fff8e1; color: var(--amber-dark); }
        .stat-icon.muted  { background: #f0ece8; color: var(--muted); }
        .stat-label { font-size: 0.75rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px; }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: var(--text); line-height: 1; }
        .stat-value.small { font-size: 1rem; }

        /* ── TOOLBAR ── */
        .toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 10px; }
        .toolbar-left  { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .toolbar-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        #myInputArchive {
            border: 2px solid var(--border); height: 42px; border-radius: 10px;
            width: 280px; font-size: 0.88rem; padding: 0 14px;
            background: var(--card-bg); transition: border-color 0.2s, box-shadow 0.2s;
        }
        #myInputArchive:focus { border-color: var(--amber); box-shadow: 0 0 0 3px rgba(255,182,10,0.15); outline: none; }

        .back-btn {
            display: inline-flex; align-items: center; gap: 7px;
            background: transparent; color: var(--muted);
            border: 1.5px solid var(--border); border-radius: 10px;
            padding: 8px 18px; font-size: 0.85rem; font-weight: 500;
            text-decoration: none; transition: 0.2s;
        }
        .back-btn:hover { border-color: var(--amber-dark); color: var(--amber-dark); background: #fffbf0; }

        .btn-export {
            display: inline-flex; align-items: center; gap: 7px;
            background: transparent; color: var(--muted);
            border: 1.5px solid var(--border); border-radius: 10px;
            padding: 8px 18px; font-size: 0.85rem; font-weight: 500;
            text-decoration: none; transition: 0.2s; cursor: pointer;
        }
        .btn-export:hover { border-color: #16a34a; color: #16a34a; background: #f0fdf4; }

        /* ── CATEGORY PILL TABS ── */
        .cat-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
        .cat-tab {
            padding: 5px 14px; border-radius: 99px; font-size: 0.78rem; font-weight: 600;
            border: 1.5px solid var(--border); background: var(--card-bg); color: var(--muted);
            cursor: pointer; transition: 0.18s; white-space: nowrap;
        }
        .cat-tab:hover { border-color: var(--amber); color: var(--amber-dark); background: #fffbf0; }
        .cat-tab.active { background: var(--amber); border-color: var(--amber); color: #fff; }

        /* ── RESULT COUNTER ── */
        #resultCount { font-size: 0.8rem; color: var(--muted); margin-bottom: 12px; }

        /* ── BULK ACTION BAR ── */
        #bulkBar {
            display: none;
            background: #fffbf0; border: 1.5px solid var(--amber);
            border-radius: 12px; padding: 10px 18px;
            align-items: center; gap: 14px; flex-wrap: wrap;
            margin-bottom: 14px;
            animation: slideDown 0.2s ease;
        }
        #bulkBar.visible { display: flex; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
        #bulkCount { font-size: 0.88rem; font-weight: 600; color: var(--text); }
        .btn-bulk {
            display: inline-flex; align-items: center; gap: 6px;
            border-radius: 9px; padding: 6px 16px; font-size: 0.82rem; font-weight: 600;
            border: none; cursor: pointer; transition: 0.18s;
        }
        .btn-bulk-restore { background: #dcfce7; color: #16a34a; }
        .btn-bulk-restore:hover { background: #16a34a; color: #fff; }
        .btn-bulk-delete  { background: #fee2e2; color: #dc2626; }
        .btn-bulk-delete:hover  { background: #dc2626; color: #fff; }
        .btn-bulk-clear   { background: transparent; color: var(--muted); border: 1px solid var(--border); }
        .btn-bulk-clear:hover   { background: #f0ece8; }

        /* ── TABLE CARD ── */
        .table-card {
            background: var(--card-bg); border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 20px rgba(0,0,0,0.05); overflow: hidden;
        }
        .table-scroll-wrap { overflow-x: auto; }
        .table-card table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        .table-card thead th {
            background: #fdf8f2; color: var(--muted);
            font-size: 0.72rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.07em;
            padding: 13px 16px; border-bottom: 1px solid var(--border); white-space: nowrap;
            user-select: none;
        }
        .table-card thead th.sortable { cursor: pointer; }
        .table-card thead th.sortable:hover { color: var(--amber-dark); }
        .sort-icon { margin-left: 4px; opacity: 0.45; font-size: 0.7rem; }
        .table-card thead th.sort-asc .sort-icon::after  { content: "↑"; opacity: 1; }
        .table-card thead th.sort-desc .sort-icon::after { content: "↓"; opacity: 1; }
        .table-card thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: "↕"; }
        .table-card tbody tr { border-bottom: 1px solid #f5ede6; transition: background 0.15s; }
        .table-card tbody tr:last-child { border-bottom: none; }
        .table-card tbody tr:hover { background: #fffbf3; }
        .table-card tbody td { padding: 13px 16px; vertical-align: middle; color: var(--text); }

        /* Checkbox column */
        .table-card thead th:first-child,
        .table-card tbody td:first-child { width: 44px; text-align: center; padding: 13px 10px; }
        .table-card tbody td.row-num { color: var(--muted); font-size: 0.78rem; font-weight: 600; }

        /* Item stacked */
        .item-name { font-weight: 600; color: var(--text); margin-bottom: 2px; }
        .item-desc { font-size: 0.78rem; color: var(--muted); }

        /* Qty */
        .qty-value { font-weight: 700; font-size: 1rem; color: var(--text); }

        /* Date Archived */
        .date-archived { font-size: 0.82rem; color: var(--muted); white-space: nowrap; }

        /* Category pills */
        .pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 99px; font-size: 0.72rem; font-weight: 600; white-space: nowrap; }
        .pill::before { content: ""; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
        .pill-general    { background: #f0f0f0; color: #555;    } .pill-general::before    { background: #aaa; }
        .pill-linens     { background: #fff8e1; color: #7a5c00; } .pill-linens::before     { background: #f59e0b; }
        .pill-toiletries { background: #e8f0fe; color: #1a56a4; } .pill-toiletries::before { background: #3b82f6; }
        .pill-food       { background: #e8f5e9; color: #1b5e20; } .pill-food::before       { background: #22c55e; }
        .pill-cleaning   { background: #fce4ec; color: #880e4f; } .pill-cleaning::before   { background: #ec4899; }
        .pill-equipment  { background: #ede7f6; color: #4527a0; } .pill-equipment::before  { background: #7c3aed; }

        /* Action buttons */
        .btn-act {
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 8px;
            background: transparent; border: 1.5px solid var(--border);
            font-size: 0.85rem; cursor: pointer; transition: 0.18s;
        }
        .btn-act-restore { border-color: #86efac; color: #16a34a; }
        .btn-act-restore:hover { background: #16a34a; color: #fff; border-color: #16a34a; }
        .btn-act-delete  { border-color: #fca5a5; color: #dc2626; }
        .btn-act-delete:hover  { background: #dc2626; color: #fff; border-color: #dc2626; }
        .actions-cell { display: flex; gap: 6px; align-items: center; }

        /* Empty state */
        .empty-state { text-align: center; padding: 60px 20px; color: var(--muted); }
        .empty-state i { font-size: 2.5rem; margin-bottom: 12px; opacity: 0.4; display: block; }
        .empty-state p { font-size: 0.9rem; margin: 0; }

        /* Modals */
        .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(0,0,0,0.15); }
        .modal-header { border-bottom: 1px solid var(--border); padding: 18px 22px; }
        .modal-title { font-family: 'Playfair Display', serif; font-size: 1.1rem; font-weight: 700; }
        .modal-body { padding: 20px 22px; }
        .modal-footer { border-top: 1px solid var(--border); padding: 14px 22px; }
        .btn-modal-cancel { background: #f5f5f5; color: var(--muted); border: none; border-radius: 9px; padding: 8px 18px; font-weight: 500; font-size: 0.88rem; cursor: pointer; }
        .btn-modal-cancel:hover { background: #ebebeb; color: var(--text); }
        .btn-modal-save { background: var(--amber); color: #fff; border: none; border-radius: 9px; padding: 8px 22px; font-weight: 600; font-size: 0.88rem; cursor: pointer; }
        .btn-modal-save:hover { background: var(--amber-dark); color: #fff; }

        /* Toasts */
        .toast { border-radius: 12px; font-size: 0.88rem; font-weight: 500; }
    </style>
<?php require_once __DIR__ . '/../includes/server_clock.php'; marle_server_clock_assets(); ?>
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>
<body class="staff-page staff-inventory-page staff-employee">

<!-- ── SIDEBAR ── -->
<?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

<!-- ── TOP BAR ── -->
<?php
    $staffPageTitle  = 'Inventory Archive';
    $staffPageNote   = 'Removed stock items, kept for reference.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['Inventory Archive', null]];
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

<!-- ── MAIN CONTENT ── -->
<div class="main-content">

    <!-- ── Stat Cards ── -->
    <div class="stat-cards-row">
        <div class="stat-card">
            <div class="stat-icon gray"><i class="bi bi-archive"></i></div>
            <div>
                <div class="stat-label">Archived Items</div>
                <div class="stat-value"><?= $totalArchived ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber"><i class="bi bi-tag"></i></div>
            <div>
                <div class="stat-label">Categories</div>
                <div class="stat-value"><?= $distinctCats ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon muted"><i class="bi bi-clock-history"></i></div>
            <div>
                <div class="stat-label">Oldest Archive</div>
                <div class="stat-value small" id="oldestArchiveStat"
                    <?= $oldestDate ? 'data-ts="' . htmlspecialchars($oldestDate) . '"' : '' ?>>
                    <?= $oldestDate ? htmlspecialchars($oldestDate) : '—' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Toolbar ── -->
    <div class="toolbar">
        <div class="toolbar-left">
            <input type="text" id="myInputArchive" placeholder="&#x1F50D; Search archive…">
        </div>
        <div class="toolbar-right">
            <a href="?export=csv" class="btn-export"><i class="bi bi-download"></i> Export CSV</a>
            <a href="../employee/inventory.php" class="back-btn"><i class="bi bi-arrow-left"></i> Back to Inventory</a>
        </div>
    </div>

    <!-- ── Category Pill Tabs ── -->
    <?php if (!empty($presentCats)): ?>
    <div class="cat-tabs">
        <button class="cat-tab active" data-cat="all">All</button>
        <?php foreach ($allCategories as $cat):
            if (in_array($cat, $presentCats)): ?>
        <button class="cat-tab" data-cat="<?= strtolower(htmlspecialchars($cat)) ?>"><?= htmlspecialchars($cat) ?></button>
        <?php endif; endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Bulk Action Bar ── -->
    <div id="bulkBar">
        <span id="bulkCount">0 items selected</span>
        <form method="POST" id="bulkRestoreForm" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div id="bulkRestoreInputs"></div>
            <button type="submit" class="btn-bulk btn-bulk-restore"><i class="bi bi-arrow-counterclockwise"></i> Restore Selected</button>
        </form>
        <button type="button" class="btn-bulk btn-bulk-delete" id="bulkDeleteBtn"><i class="bi bi-trash"></i> Delete Selected</button>
        <button type="button" class="btn-bulk btn-bulk-clear" id="bulkClearBtn">Clear</button>
    </div>

    <!-- ── Result Counter ── -->
    <div id="resultCount">Showing <?= $totalArchived ?> of <?= $totalArchived ?> archived items</div>

    <!-- ── Table ── -->
    <div class="table-card">
        <div class="table-scroll-wrap">
            <table>
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" title="Select all"></th>
                        <th class="sortable" data-col="item">Item <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="category">Category <span class="sort-icon"></span></th>
                        <th class="sortable" data-col="qty">Quantity <span class="sort-icon"></span></th>
                        <th class="sortable sort-desc" data-col="archived">Date Archived <span class="sort-icon"></span></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="myTable">
                <?php if (empty($rows_data)): ?>
                    <tr id="emptyRow"><td colspan="6">
                        <div class="empty-state">
                            <i class="bi bi-archive"></i>
                            <p>No archived items. Items you archive from Inventory will appear here.</p>
                        </div>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($rows_data as $i => $row):
                        $id       = (int)$row['id'];
                        $item     = htmlspecialchars($row['item']);
                        $desc     = htmlspecialchars($row['description'] ?? '');
                        $cat      = htmlspecialchars($row['category'] ?? 'General');
                        $qty      = (int)$row['quantity'];
                        $archived = htmlspecialchars($row['archived_at'] ?? '');
                        $catKey   = 'pill-' . strtolower(preg_replace('/[^a-z]/i', '', $cat));
                        $validPills = ['pill-general','pill-linens','pill-toiletries','pill-food','pill-cleaning','pill-equipment'];
                        if (!in_array($catKey, $validPills)) $catKey = 'pill-general';
                    ?>
                    <tr
                        data-item="<?= strtolower($item) ?>"
                        data-category="<?= strtolower(htmlspecialchars($row['category'] ?? 'general')) ?>"
                        data-desc="<?= strtolower($desc) ?>"
                        data-qty="<?= $qty ?>"
                        data-archived="<?= $archived ?>"
                    >
                        <td><input type="checkbox" class="row-check" data-id="<?= $id ?>"></td>
                        <td>
                            <div class="item-name"><?= $item ?></div>
                            <?php if ($desc): ?><div class="item-desc"><?= $desc ?></div><?php endif; ?>
                        </td>
                        <td><span class="pill <?= $catKey ?>"><?= $cat ?></span></td>
                        <td><span class="qty-value"><?= $qty ?></span></td>
                        <td>
                            <span class="date-archived" data-ts="<?= $archived ?>">
                                <?= $archived ?: '—' ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions-cell">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="restore_id" value="<?= $id ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <button type="submit" class="btn-act btn-act-restore" title="Restore">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                </form>
                                <button class="btn-act btn-act-delete deleteBtn" data-id="<?= $id ?>" title="Delete permanently">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /main-content -->

<!-- ── Toast Container ── -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:9999;">
    <div id="toastRestored" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body"><i class="bi bi-check-circle me-2"></i>Item restored to inventory.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
    <div id="toastDeleted" class="toast align-items-center text-bg-secondary border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="toastDeletedMsg"><i class="bi bi-trash me-2"></i>Item permanently deleted.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<!-- ── Delete Confirm Modal ── -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="delete_id" id="delete-id">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Permanently Delete Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="bi bi-trash fs-1 text-danger d-block mb-3"></i>
                    <p class="mb-0" style="font-size:0.95rem;">
                        This item will be <strong>permanently deleted</strong>.<br>
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

<!-- ── Bulk Delete Confirm Modal ── -->
<div class="modal fade" id="bulkDeleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="bulkDeleteForm">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div id="bulkDeleteInputs"></div>
                <div class="modal-header">
                    <h5 class="modal-title">Permanently Delete Selected</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="bi bi-trash fs-1 text-danger d-block mb-3"></i>
                    <p class="mb-0" style="font-size:0.95rem;">
                        <strong id="bulkDeleteCountMsg">Selected items</strong> will be <strong>permanently deleted</strong>.<br>
                        This action cannot be undone.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-modal-save" style="background:#dc2626;">Yes, Delete All</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Logout Modal ── -->
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
                <a href="../employee/archiveInventory.php?action=logout" class="btn-modal-save" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:8px 22px;">Yes, Logout</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-j1CDi7MgGQ12Z7Qab0qlWQ/Qqz24Gc6BM0thvEMVjHnfYGF0rmFCozFSxQBxwHKO"
    crossorigin="anonymous"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // ── Relative time ────────────────────────────────────────────────────────
    /* Measured against the HOTEL's clock, with the stamp read as the hotel's own wall
       time (includes/server_clock.php). This used to subtract the viewing device's clock
       and parse the stamp in its timezone, so an archive from this morning could read
       "1d ago" on a phone — or a time in the future. */
    function relativeTime(ts) {
        if (!ts) return '—';
        const clock = window.MarleClock;
        const then  = clock ? clock.parseWall(ts) : new Date(String(ts).replace(' ', 'T')).getTime();
        if (isNaN(then)) return '—';
        const diff = Math.floor(((clock ? clock.nowMs() : Date.now()) - then) / 1000);   // marle-clock-ok: only reached when includes/server_clock.php is absent; the live path counts from the hotel clock
        if (diff < 60)         return 'Just now';
        if (diff < 3600)       return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400)      return Math.floor(diff / 3600) + 'h ago';
        if (diff < 86400 * 30) return Math.floor(diff / 86400) + 'd ago';
        return clock ? clock.formatWallDate(ts) : new Date(ts).toLocaleDateString('en-US');
    }

    // Convert date cells
    document.querySelectorAll('.date-archived[data-ts]').forEach(el => {
        if (el.dataset.ts) {
            el.textContent = relativeTime(el.dataset.ts);
            el.title = el.dataset.ts;
        }
    });

    // Oldest archive stat card
    const oldestEl = document.getElementById('oldestArchiveStat');
    if (oldestEl && oldestEl.dataset.ts) {
        oldestEl.textContent = relativeTime(oldestEl.dataset.ts);
        oldestEl.title = oldestEl.dataset.ts;
    }

    // ── Toasts ───────────────────────────────────────────────────────────────
    const params = new URLSearchParams(window.location.search);
    if (params.get('restored') === '1') {
        new bootstrap.Toast(document.getElementById('toastRestored'), { delay: 3500 }).show();
    }
    if (params.get('deleted') === '1') {
        const count = params.get('count');
        if (count && parseInt(count) > 1) {
            document.getElementById('toastDeletedMsg').innerHTML =
                '<i class="bi bi-trash me-2"></i>' + count + ' items permanently deleted.';
        }
        new bootstrap.Toast(document.getElementById('toastDeleted'), { delay: 3500 }).show();
    }

    // ── State & Filter ───────────────────────────────────────────────────────
    const state = { search: '', category: 'all' };
    const tbody = document.getElementById('myTable');
    const resultCount = document.getElementById('resultCount');
    const allRows = () => Array.from(tbody.querySelectorAll('tr[data-item]'));

    function applyFilters() {
        const rows = allRows();
        let visible = 0;
        rows.forEach(row => {
            const matchSearch = !state.search ||
                (row.dataset.item  || '').includes(state.search) ||
                (row.dataset.desc  || '').includes(state.search) ||
                (row.dataset.category || '').includes(state.search);
            const matchCat = state.category === 'all' || row.dataset.category === state.category;
            const show = matchSearch && matchCat;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        // Empty state row
        let emptyRow = document.getElementById('emptyRow');
        if (rows.length === 0) {
            // no rows at all — leave static empty state
        } else {
            if (!emptyRow) {
                // create dynamic empty row
                emptyRow = document.createElement('tr');
                emptyRow.id = 'emptyRow';
                emptyRow.innerHTML = '<td colspan="6"><div class="empty-state"><i class="bi bi-search"></i><p>No items match your search.</p></div></td>';
                tbody.appendChild(emptyRow);
            }
            emptyRow.style.display = visible === 0 ? '' : 'none';
        }
        if (resultCount) {
            resultCount.textContent = 'Showing ' + visible + ' of ' + rows.length + ' archived items';
        }
    }

    // ── Search ───────────────────────────────────────────────────────────────
    const searchInput = document.getElementById('myInputArchive');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            state.search = this.value.toLowerCase().trim();
            applyFilters();
        });
    }

    // ── Category Tabs ────────────────────────────────────────────────────────
    document.querySelectorAll('.cat-tab').forEach(tab => {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            state.category = this.dataset.cat;
            applyFilters();
        });
    });

    // ── Sort ─────────────────────────────────────────────────────────────────
    const sortState = { col: 'archived', dir: 'desc' };

    /* A stored hotel stamp as a sortable instant.
       new Date('2026-10-12 03:00:00') is a non-standard parse — Chrome and Firefox
       accept it, Safari returns Invalid Date — so on an iPhone this column silently did
       not sort at all, including the default order this page opens in. MarleClock reads
       the stamp's own parts (includes/server_clock.php). */
    function wallTs(value) {
        const clock = window.MarleClock;
        if (clock) { const t = clock.parseWall(value); if (!isNaN(t)) return t; }
        const t = new Date(String(value || '').replace(' ', 'T')).getTime();
        return isNaN(t) ? 0 : t;   // no date at all sorts as the oldest
    }

    function sortRows(col, dir) {
        const rows = allRows();
        rows.sort((a, b) => {
            let va, vb;
            if (col === 'item')     { va = (a.dataset.item || '').toLowerCase();     vb = (b.dataset.item || '').toLowerCase(); }
            if (col === 'category') { va = (a.dataset.category || '').toLowerCase(); vb = (b.dataset.category || '').toLowerCase(); }
            if (col === 'qty')      { va = parseInt(a.dataset.qty) || 0;             vb = parseInt(b.dataset.qty) || 0; }
            if (col === 'archived') { va = wallTs(a.dataset.archived); vb = wallTs(b.dataset.archived); }
            if (va < vb) return dir === 'asc' ? -1 : 1;
            if (va > vb) return dir === 'asc' ? 1 : -1;
            return 0;
        });
        rows.forEach(r => tbody.appendChild(r));
        // Move empty row back to end if present
        const er = document.getElementById('emptyRow');
        if (er) tbody.appendChild(er);
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
            document.querySelectorAll('thead th.sortable').forEach(t => {
                t.classList.remove('sort-asc', 'sort-desc');
            });
            this.classList.add('sort-' + sortState.dir);
            sortRows(sortState.col, sortState.dir);
        });
    });

    // Initial sort: newest first
    sortRows('archived', 'desc');
    applyFilters();

    // ── Delete Modal ─────────────────────────────────────────────────────────
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    document.querySelectorAll('.deleteBtn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('delete-id').value = this.dataset.id;
            deleteModal.show();
        });
    });

    // ── Checkboxes & Bulk Bar ────────────────────────────────────────────────
    const selectAll = document.getElementById('selectAll');
    const bulkBar   = document.getElementById('bulkBar');
    const bulkCount = document.getElementById('bulkCount');
    const bulkRestoreInputs = document.getElementById('bulkRestoreInputs');
    const bulkDeleteInputs  = document.getElementById('bulkDeleteInputs');

    function getChecked() {
        return Array.from(document.querySelectorAll('.row-check:checked'));
    }

    function updateBulkBar() {
        const checked = getChecked();
        if (checked.length > 0) {
            bulkBar.classList.add('visible');
            bulkCount.textContent = checked.length + ' item' + (checked.length > 1 ? 's' : '') + ' selected';
            // Populate restore hidden inputs
            bulkRestoreInputs.innerHTML = '';
            checked.forEach(cb => {
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'bulk_restore_ids[]'; inp.value = cb.dataset.id;
                bulkRestoreInputs.appendChild(inp);
            });
        } else {
            bulkBar.classList.remove('visible');
        }
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('row-check')) {
            updateBulkBar();
        }
        if (e.target.id === 'selectAll') {
            const visibleChecks = Array.from(document.querySelectorAll('.row-check')).filter(cb => {
                return cb.closest('tr').style.display !== 'none';
            });
            visibleChecks.forEach(cb => cb.checked = e.target.checked);
            updateBulkBar();
        }
    });

    document.getElementById('bulkClearBtn').addEventListener('click', function () {
        document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
        if (selectAll) selectAll.checked = false;
        updateBulkBar();
    });

    // Bulk Delete — open confirm modal
    const bulkDeleteModal = new bootstrap.Modal(document.getElementById('bulkDeleteConfirmModal'));
    document.getElementById('bulkDeleteBtn').addEventListener('click', function () {
        const checked = getChecked();
        if (!checked.length) return;
        const container = document.getElementById('bulkDeleteInputs');
        container.innerHTML = '';
        checked.forEach(cb => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'bulk_delete_ids[]'; inp.value = cb.dataset.id;
            container.appendChild(inp);
        });
        document.getElementById('bulkDeleteCountMsg').textContent =
            checked.length + ' selected item' + (checked.length > 1 ? 's' : '');
        bulkDeleteModal.show();
    });

});
</script>


    <!-- Footer — one design for all three roles (includes/staff_footer.php) -->
    <?php $marleFooterTerms = false; require __DIR__ . '/../includes/staff_footer.php'; ?>
</body>
</html>



