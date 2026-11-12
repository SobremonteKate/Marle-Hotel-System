<?php
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.inventory');
include('../superadmin/auth.php');
require_once __DIR__ . '/../includes/log_action.php';
require_once __DIR__ . '/../includes/server_clock.php';   // marle_hotel_date(): the hotel's date
include('../superadmin/requests_badge.php');
include('../superadmin/includes/notif_data.php');

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: ../login.php?logout=success");
    exit();
}

// CSV Export — must be before any HTML output
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory_' . marle_hotel_date(0, $conn) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Item', 'Description', 'Category', 'Quantity', 'Low Stock Threshold']);
    $res = $conn->query("SELECT id, item, description, category, quantity, low_stock_threshold FROM inventorytbl ORDER BY (quantity <= low_stock_threshold) DESC, category, item");
    while ($r = $res->fetch_assoc()) {
        fputcsv($out, [$r['id'], $r['item'], $r['description'], $r['category'], $r['quantity'], $r['low_stock_threshold']]);
    }
    fclose($out);
    exit;
}

$notif = '';

// Add Item
if (isset($_POST['addItem'])) {
    $item                = trim($_POST['itemName'] ?? '');
    $quantity            = (int)($_POST['quantity'] ?? 0);
    $description         = trim($_POST['description'] ?? '');
    $category            = $_POST['category'] ?? 'General';
    $low_stock_threshold = (int)($_POST['low_stock_threshold'] ?? 0);

    if ($item === '') {
        $notif = "Item name cannot be empty.";
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO inventorytbl (item, quantity, description, category, low_stock_threshold)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sissi", $item, $quantity, $description, $category, $low_stock_threshold);
        if ($stmt->execute()) {
            log_event($conn, 'inventory_item_added', 'inventory_item', $conn->insert_id, 'success', $item . ' (qty ' . $quantity . ')');
            header("Location: inventory.php?status=added");
            exit();
        } else {
            $notif = "Failed to add item.";
        }
        $stmt->close();
    }
}

// Edit Item
if (isset($_POST['updateItem'])) {
    $id                  = (int)($_POST['id'] ?? 0);
    $item                = trim($_POST['item'] ?? '');
    $quantity            = (int)($_POST['quantity'] ?? 0);
    $description         = trim($_POST['description'] ?? '');
    $category            = $_POST['category'] ?? 'General';
    $low_stock_threshold = (int)($_POST['low_stock_threshold'] ?? 0);

    if ($id <= 0 || $item === '') {
        $notif = "Invalid item data.";
    } else {
        $stmt = $conn->prepare(
            "UPDATE inventorytbl
             SET item = ?, quantity = ?, description = ?, category = ?, low_stock_threshold = ?
             WHERE id = ?"
        );
        $stmt->bind_param("sissii", $item, $quantity, $description, $category, $low_stock_threshold, $id);
        if ($stmt->execute()) {
            log_event($conn, 'inventory_item_updated', 'inventory_item', $id, 'success', $item . ' (qty ' . $quantity . ')');
            header("Location: inventory.php?status=updated");
            exit();
        } else {
            $notif = "Failed to update item.";
        }
        $stmt->close();
    }
}

$sql    = "SELECT * FROM inventorytbl ORDER BY (quantity <= low_stock_threshold) DESC, category, item";
$result = mysqli_query($conn, $sql);

$totalItems    = 0;
$totalQty      = 0;
$lowStockCount = 0;
$rows_data     = [];
while ($r = mysqli_fetch_assoc($result)) {
    $totalItems++;
    $totalQty += (int)$r['quantity'];
    $lst = (int)($r['low_stock_threshold'] ?? 0);
    if ($lst > 0 && (int)$r['quantity'] <= $lst) $lowStockCount++;
    $rows_data[] = $r;
}

// Profile pic — prepared statement
$username = $_SESSION['username'];
$stmtUser = $conn->prepare("SELECT picture FROM userstbl WHERE username = ? LIMIT 1");
$stmtUser->bind_param("s", $username);
$stmtUser->execute();
$resultUser = $stmtUser->get_result();
$imgSrc     = "../img/default.jpg";
if ($resultUser && $resultUser->num_rows > 0) {
    $userRow   = $resultUser->fetch_assoc();
    $imageData = $userRow['picture'];
    if (!empty($imageData)) {
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($imageData);
        $imgSrc   = "data:$mimeType;base64," . base64_encode($imageData);
    }
}

// Build category list for tabs
$categorySet = [];
foreach ($rows_data as $r) {
    $c = $r['category'] ?? 'General';
    if (!in_array($c, $categorySet)) $categorySet[] = $c;
}
sort($categorySet);
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <title>Inventory</title>

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
        .stat-cards { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px; }
        .stat-card {
            flex: 1; min-width: 160px;
            background: var(--card-bg);
            border-radius: 14px;
            border: 1px solid var(--border);
            padding: 18px 20px;
            display: flex; align-items: center; gap: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .stat-card.clickable { cursor: pointer; }
        .stat-card.clickable:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.08); transform: translateY(-1px); }
        .stat-card.active-filter { border-color: var(--amber); box-shadow: 0 0 0 3px rgba(255,182,10,0.18); }
        .stat-icon {
            width: 44px; height: 44px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; flex-shrink: 0;
        }
        .stat-icon.amber  { background: #fff5d6; color: var(--amber-dark); }
        .stat-icon.green  { background: #d6f5e3; color: #1a7a42; }
        .stat-icon.red    { background: #fde8e8; color: #b71c1c; }
        .stat-label { font-size: 0.75rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 2px; }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: var(--text); line-height: 1; }

        /* ── TOOLBAR ── */
        .toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; }
        .toolbar-left { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .toolbar-right { display: flex; gap: 8px; align-items: center; }
        #myInputInventory {
            border: 2px solid var(--border); height: 42px; border-radius: 10px;
            width: 280px; font-size: 0.88rem; padding: 0 14px;
            background: var(--card-bg);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        #myInputInventory:focus { border-color: var(--amber); box-shadow: 0 0 0 3px rgba(255,182,10,0.15); outline: none; }

        .btn-low-stock {
            height: 42px; border-radius: 10px; font-size: 0.85rem; font-weight: 500;
            padding: 0 14px; border: 2px solid var(--border); background: var(--card-bg);
            color: var(--muted); cursor: pointer; transition: 0.2s; white-space: nowrap;
            display: flex; align-items: center; gap: 6px;
        }
        .btn-low-stock:hover { border-color: var(--amber); color: var(--amber-dark); }
        .btn-low-stock.active { background: var(--amber); border-color: var(--amber); color: #fff; }

        .btn-export-csv {
            height: 42px; border-radius: 10px; font-size: 0.85rem; font-weight: 500;
            padding: 0 16px; border: 2px solid var(--border); background: var(--card-bg);
            color: var(--muted); cursor: pointer; transition: 0.2s;
            display: flex; align-items: center; gap: 6px; text-decoration: none;
        }
        .btn-export-csv:hover { border-color: var(--amber-dark); color: var(--amber-dark); }

        .btn-add-item {
            background: var(--amber); color: #fff; border: none;
            height: 42px; border-radius: 10px; font-size: 0.88rem; font-weight: 600;
            padding: 0 22px; transition: 0.2s; display: flex; align-items: center; gap: 7px;
        }
        .btn-add-item:hover { background: var(--amber-dark); color: #fff; }

        .per-page-wrap { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; color: var(--muted); }
        #perPageSelect {
            height: 36px; border: 1.5px solid var(--border); border-radius: 8px;
            font-size: 0.82rem; padding: 0 8px; background: var(--card-bg); color: var(--text);
        }

        /* ── CATEGORY PILL TABS ── */
        .cat-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .cat-tab {
            padding: 5px 14px; border-radius: 99px; font-size: 0.8rem; font-weight: 600;
            border: 1.5px solid var(--border); background: var(--card-bg); color: var(--muted);
            cursor: pointer; transition: 0.18s; user-select: none;
        }
        .cat-tab:hover { border-color: var(--amber); color: var(--amber-dark); }
        .cat-tab.active { background: var(--amber); border-color: var(--amber); color: #fff; }

        /* ── BULK ACTION BAR ── */
        .bulk-bar {
            display: none;
            align-items: center; gap: 12px;
            background: #fff9ec; border: 1.5px solid var(--amber);
            border-radius: 12px; padding: 10px 16px;
            margin-bottom: 12px;
            animation: slideDown 0.2s ease;
        }
        .bulk-bar.visible { display: flex; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        .bulk-bar-count { font-size: 0.88rem; font-weight: 600; color: var(--text); }
        .btn-bulk-archive {
            background: var(--amber); color: #fff; border: none;
            border-radius: 8px; padding: 6px 14px; font-size: 0.82rem; font-weight: 600; cursor: pointer;
        }
        .btn-bulk-archive:hover { background: var(--amber-dark); }
        .btn-bulk-clear {
            background: transparent; color: var(--muted); border: 1.5px solid var(--border);
            border-radius: 8px; padding: 6px 14px; font-size: 0.82rem; font-weight: 500; cursor: pointer;
        }
        .btn-bulk-clear:hover { color: var(--text); border-color: var(--text); }

        /* ── RESULT COUNT ── */
        #resultCount { font-size: 0.8rem; color: var(--muted); margin-bottom: 8px; min-height: 18px; }

        /* ── TABLE CARD ── */
        .table-card {
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .table-card table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
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
        }
        .table-card thead th.sortable-th {
            cursor: pointer; user-select: none;
        }
        .table-card thead th.sortable-th:hover { color: var(--amber-dark); }
        .sort-icon { margin-left: 4px; opacity: 0.4; font-size: 0.7rem; }
        .sortable-th.sort-asc .sort-icon::after { content: "↑"; opacity: 1; }
        .sortable-th.sort-desc .sort-icon::after { content: "↓"; opacity: 1; }
        .sortable-th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: "↕"; }
        .sortable-th.sort-asc .sort-icon,
        .sortable-th.sort-desc .sort-icon { opacity: 1; color: var(--amber-dark); }

        .table-card thead th:first-child { width: 40px; text-align: center; }
        .table-card thead th:nth-child(2) { width: 40px; text-align: center; }
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
        .table-card tbody td:first-child { text-align: center; color: var(--muted); font-size: 0.78rem; }
        .table-card tbody td:nth-child(2) { text-align: center; }

        /* Row num col */
        .row-num { color: var(--muted); font-size: 0.78rem; font-weight: 600; }

        /* Item name + description stacked */
        .item-name { font-weight: 600; color: var(--text); margin-bottom: 2px; }
        .item-desc { font-size: 0.78rem; color: var(--muted); }

        /* Quantity cell */
        .qty-cell { display: flex; align-items: center; gap: 8px; }
        .qty-adjust-btn {
            width: 24px; height: 24px; border-radius: 6px; border: none;
            background: transparent; cursor: pointer; font-size: 1rem; font-weight: 700;
            color: var(--muted); display: flex; align-items: center; justify-content: center;
            transition: 0.15s; line-height: 1;
        }
        .qty-adjust-btn:hover:not(:disabled) { background: #f0e8df; color: var(--text); }
        .qty-adjust-btn:disabled { opacity: 0.28; cursor: not-allowed; }
        .qty-value { font-weight: 700; font-size: 1rem; color: var(--text); min-width: 28px; text-align: center; }
        .qty-bar-wrap { flex: 1; height: 6px; background: #f0e8df; border-radius: 99px; overflow: hidden; max-width: 80px; }
        .qty-bar { height: 100%; border-radius: 99px; background: linear-gradient(90deg, var(--amber), var(--amber-dark)); transition: width 0.4s; }
        .qty-bar.low { background: linear-gradient(90deg, #ff7043, #e53935); }

        /* Category pills */
        .pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 99px;
            font-size: 0.72rem; font-weight: 600; white-space: nowrap;
        }
        .pill::before { content: ""; width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
        .pill-general    { background: #f0f0f0; color: #555;    } .pill-general::before    { background: #aaa; }
        .pill-linens     { background: #fff8e1; color: #7a5c00; } .pill-linens::before     { background: #f59e0b; }
        .pill-toiletries { background: #e8f0fe; color: #1a56a4; } .pill-toiletries::before { background: #3b82f6; }
        .pill-food       { background: #e8f5e9; color: #1b5e20; } .pill-food::before       { background: #22c55e; }
        .pill-cleaning   { background: #fce4ec; color: #880e4f; } .pill-cleaning::before   { background: #ec4899; }
        .pill-equipment  { background: #ede7f6; color: #4527a0; } .pill-equipment::before  { background: #7c3aed; }

        /* Low stock chip */
        .chip-lowstock {
            display: inline-flex; align-items: center; gap: 4px;
            background: #fff3e0; color: #e65100;
            padding: 2px 8px; border-radius: 99px;
            font-size: 0.7rem; font-weight: 700; border: 1px solid #ffd180;
            white-space: nowrap;
        }

        /* Action buttons */
        .action-wrap { display: flex; gap: 6px; }
        .btn-act {
            width: 32px; height: 32px; border-radius: 8px; border: 1.5px solid;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem; cursor: pointer; transition: 0.18s; background: transparent;
            text-decoration: none;
        }
        .btn-act-edit  { border-color: #93c5fd; color: #2563eb; }
        .btn-act-edit:hover  { background: #2563eb; color: #fff; border-color: #2563eb; }
        .btn-act-archive { border-color: #fca5a5; color: #dc2626; }
        .btn-act-archive:hover { background: #dc2626; color: #fff; border-color: #dc2626; }

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
        .form-label { font-size: 0.8rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 5px; }
        .form-control, .form-select {
            border: 1.5px solid var(--border); border-radius: 10px; font-size: 0.9rem;
            padding: 9px 13px; transition: 0.2s;
        }
        .form-control:focus, .form-select:focus { border-color: var(--amber); box-shadow: 0 0 0 3px rgba(255,182,10,0.15); }
        .btn-modal-cancel { background: #f5f5f5; color: var(--muted); border: none; border-radius: 9px; padding: 8px 18px; font-weight: 500; font-size: 0.88rem; }
        .btn-modal-cancel:hover { background: #ebebeb; color: var(--text); }
        .btn-modal-save { background: var(--amber); color: #fff; border: none; border-radius: 9px; padding: 8px 22px; font-weight: 600; font-size: 0.88rem; }
        .btn-modal-save:hover { background: var(--amber-dark); color: #fff; }

        /* Toasts */
        .toast { border-radius: 12px; font-size: 0.88rem; font-weight: 500; }

        /* Bottom archive link */
        .archive-link-btn {
            display: inline-flex; align-items: center; gap: 7px;
            background: transparent; color: var(--muted);
            border: 1.5px solid var(--border); border-radius: 10px;
            padding: 8px 18px; font-size: 0.85rem; font-weight: 500;
            text-decoration: none; transition: 0.2s;
        }
        .archive-link-btn:hover { border-color: var(--brown); color: var(--brown); background: #fff5f2; }

        /* Responsive table scroll */
        .table-scroll-wrap { overflow-x: auto; }

        /* Pagination */
        .pagination-wrap {
            display: flex; align-items: center; justify-content: center; gap: 10px;
            margin-top: 18px; font-size: 0.85rem; color: var(--muted);
        }
        .btn-page {
            height: 34px; min-width: 34px; border-radius: 8px;
            border: 1.5px solid var(--border); background: var(--card-bg);
            color: var(--text); font-size: 0.82rem; font-weight: 500;
            cursor: pointer; transition: 0.15s; padding: 0 12px;
        }
        .btn-page:hover:not(:disabled) { border-color: var(--amber); color: var(--amber-dark); }
        .btn-page:disabled { opacity: 0.38; cursor: not-allowed; }
        #pageInfo { font-weight: 600; color: var(--text); }

        /* Checkbox column */
        .cb-col { width: 40px; text-align: center !important; }
        input[type="checkbox"] { accent-color: var(--amber); width: 15px; height: 15px; cursor: pointer; }
    </style>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930-inventory">
    <link rel="stylesheet" href="../css/staff-footer.css?v=20261021">
    <link rel="stylesheet" href="../css/staff-inventory.css?v=20261021">
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>
<body class="staff-page staff-inventory-page staff-superadmin">

<!-- ── SIDEBAR ── -->
<?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

<!-- ── TOP BAR ── -->
<?php
    $staffPageTitle  = 'Inventory';
    $staffPageNote   = 'Stock levels, low-stock alerts and quantity changes.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['Inventory', null]];
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

<!-- ── MAIN CONTENT ── -->
<div class="main-content">

    <?php if (!empty($notif)): ?>
    <div class="alert alert-danger alert-dismissible fade show rounded-3 mb-3" style="font-size:0.88rem;">
        <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($notif) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Stat cards -->
    <div class="stat-cards">
        <div class="stat-card">
            <div class="stat-icon amber"><i class="bi bi-clipboard-check"></i></div>
            <div>
                <div class="stat-label">Total Items</div>
                <div class="stat-value"><?= $totalItems ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="bi bi-boxes"></i></div>
            <div>
                <div class="stat-label">Total Quantity</div>
                <div class="stat-value"><?= number_format($totalQty) ?></div>
            </div>
        </div>
        <div class="stat-card clickable" id="statLowStock">
            <div class="stat-icon red"><i class="bi bi-exclamation-triangle"></i></div>
            <div>
                <div class="stat-label">Low Stock</div>
                <div class="stat-value"><?= $lowStockCount ?></div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="toolbar-left">
            <input type="text" id="myInputInventory" placeholder="&#x1F50D; Search inventory…">
            <button class="btn-low-stock" id="btnLowStock">
                <i class="bi bi-exclamation-triangle"></i> Low Stock Only
            </button>
        </div>
        <div class="toolbar-right">
            <div class="per-page-wrap">
                <label for="perPageSelect">Show</label>
                <select id="perPageSelect">
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="0">All</option>
                </select>
            </div>
            <a href="?export=csv" class="btn-export-csv">
                <i class="bi bi-download"></i> Export CSV
            </a>
            <button class="btn-add-item" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="bi bi-plus-lg"></i> Add Item
            </button>
        </div>
    </div>

    <!-- Category pill tabs -->
    <div class="cat-tabs" id="catTabs">
        <button class="cat-tab active" data-cat="all">All</button>
        <?php foreach ($categorySet as $c): ?>
        <button class="cat-tab" data-cat="<?= strtolower(htmlspecialchars($c)) ?>"><?= htmlspecialchars($c) ?></button>
        <?php endforeach; ?>
    </div>

    <!-- Bulk action bar -->
    <div class="bulk-bar" id="bulkBar">
        <span class="bulk-bar-count" id="bulkCount">0 items selected</span>
        <form method="POST" action="../superadmin/archiveInventory.php" id="bulkForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div id="bulkIdsContainer"></div>
            <button type="submit" class="btn-bulk-archive">
                <i class="bi bi-archive"></i> Archive Selected
            </button>
        </form>
        <button type="button" class="btn-bulk-clear" id="btnBulkClear">Clear selection</button>
    </div>

    <!-- Result count -->
    <div id="resultCount"></div>

    <!-- Table -->
    <div class="table-card">
        <div class="table-scroll-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="cb-col"><input type="checkbox" id="checkAll" title="Select all"></th>
                        <th style="width:44px;text-align:center;">#</th>
                        <th class="sortable-th" data-sort-col="item">Item <span class="sort-icon"></span></th>
                        <th class="sortable-th" data-sort-col="category">Category <span class="sort-icon"></span></th>
                        <th class="sortable-th" data-sort-col="quantity">Quantity <span class="sort-icon"></span></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="myTable">
                <?php if (empty($rows_data)): ?>
                    <tr><td colspan="6">
                        <div class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <p>No inventory items yet. Click <strong>Add Item</strong> to get started.</p>
                        </div>
                    </td></tr>
                <?php else: ?>
                    <?php
                    $maxQty = max(array_column($rows_data, 'quantity') ?: [1]);
                    if ($maxQty <= 0) $maxQty = 1;
                    foreach ($rows_data as $i => $row):
                        $id          = (int)$row['id'];
                        $item        = htmlspecialchars($row['item']);
                        $desc        = htmlspecialchars($row['description'] ?? '');
                        $cat         = htmlspecialchars($row['category'] ?? 'General');
                        $qty         = (int)$row['quantity'];
                        $lst         = (int)($row['low_stock_threshold'] ?? 0);
                        $isLow       = $lst > 0 && $qty <= $lst;
                        $barPct      = min(100, round(($qty / $maxQty) * 100));
                        $catKey      = 'pill-' . strtolower(preg_replace('/[^a-z]/i', '', $cat));
                        $validPills  = ['pill-general','pill-linens','pill-toiletries','pill-food','pill-cleaning','pill-equipment'];
                        if (!in_array($catKey, $validPills)) $catKey = 'pill-general';
                    ?>
                    <tr
                        data-item="<?= strtolower($item) ?>"
                        data-category="<?= strtolower($cat) ?>"
                        data-low="<?= $isLow ? '1' : '0' ?>"
                        data-id="<?= $id ?>"
                        data-qty="<?= $qty ?>"
                        data-lst="<?= $lst ?>"
                        data-max="<?= $maxQty ?>"
                    >
                        <td class="cb-col"><input type="checkbox" class="row-check" data-id="<?= $id ?>"></td>
                        <td class="row-num"><?= $i + 1 ?></td>
                        <td>
                            <div class="item-name"><?= $item ?></div>
                            <?php if ($desc): ?><div class="item-desc"><?= $desc ?></div><?php endif; ?>
                        </td>
                        <td><span class="pill <?= $catKey ?>"><?= $cat ?></span></td>
                        <td>
                            <div class="qty-cell">
                                <button class="qty-adjust-btn qty-minus" data-id="<?= $id ?>" title="Decrease" <?= $qty <= 0 ? 'disabled' : '' ?>>−</button>
                                <span class="qty-value" id="qty-<?= $id ?>"><?= $qty ?></span>
                                <button class="qty-adjust-btn qty-plus" data-id="<?= $id ?>" title="Increase">+</button>
                                <div class="qty-bar-wrap">
                                    <div class="qty-bar <?= $isLow ? 'low' : '' ?>" id="bar-<?= $id ?>" style="width:<?= $barPct ?>%"></div>
                                </div>
                                <span class="chip-lowstock <?= $isLow ? '' : 'd-none' ?>" id="chip-<?= $id ?>">&#9888; Low</span>
                            </div>
                        </td>
                        <td>
                            <div class="action-wrap">
                                <a href="#" class="btn-act btn-act-edit editBtn"
                                    data-bs-toggle="modal" data-bs-target="#editItemModal"
                                    data-id="<?= $id ?>"
                                    data-item="<?= $item ?>"
                                    data-quantity="<?= $qty ?>"
                                    data-description="<?= $desc ?>"
                                    data-category="<?= $cat ?>"
                                    data-threshold="<?= $lst ?>"
                                    title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="#" class="btn-act btn-act-archive archiveBtn"
                                    data-id="<?= $id ?>"
                                    title="Archive">
                                    <i class="bi bi-archive"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="pagination-wrap" id="paginationWrap">
        <button class="btn-page" id="btnPrev"><i class="bi bi-chevron-left"></i> Prev</button>
        <span id="pageInfo"></span>
        <button class="btn-page" id="btnNext">Next <i class="bi bi-chevron-right"></i></button>
    </div>

    <div class="d-flex justify-content-end mt-3">
        <a href="../superadmin/archiveInventory.php" class="archive-link-btn">
            <i class="bi bi-archive"></i> View Archive
        </a>
    </div>

</div><!-- /main-content -->

<!-- ── ADD ITEM MODAL ── -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add Inventory Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Item Name <span class="text-danger">*</span></label>
                            <input class="form-control" type="text" name="itemName" placeholder="e.g. Bath Towels" autocomplete="off" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <input class="form-control" type="text" name="description" placeholder="Optional short description" autocomplete="off">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category">
                                <option value="General">General</option>
                                <option value="Linens">Linens</option>
                                <option value="Toiletries">Toiletries</option>
                                <option value="Food">Food</option>
                                <option value="Cleaning">Cleaning</option>
                                <option value="Equipment">Equipment</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input class="form-control" type="number" name="quantity" placeholder="0" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Low Stock Threshold</label>
                            <input class="form-control" type="number" name="low_stock_threshold" placeholder="0" min="0" value="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="addItem" class="btn-modal-save">Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── EDIT MODAL ── -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="id" id="edit-id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Item Name <span class="text-danger">*</span></label>
                            <input class="form-control" type="text" name="item" id="edit-item" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <input class="form-control" type="text" name="description" id="edit-description">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category" id="edit-category">
                                <option value="General">General</option>
                                <option value="Linens">Linens</option>
                                <option value="Toiletries">Toiletries</option>
                                <option value="Food">Food</option>
                                <option value="Cleaning">Cleaning</option>
                                <option value="Equipment">Equipment</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input class="form-control" type="number" name="quantity" id="edit-quantity" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Low Stock Threshold</label>
                            <input class="form-control" type="number" name="low_stock_threshold" id="edit-threshold" min="0" value="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="updateItem" class="btn-modal-save">Update Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── ARCHIVE CONFIRM MODAL ── -->
<div class="modal fade" id="archiveConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="../superadmin/archiveInventory.php">
                <input type="hidden" name="archive_id" id="archive-id">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Archive Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="bi bi-archive fs-1 text-warning d-block mb-3"></i>
                    <p class="mb-0" style="font-size:0.95rem;">This item will be moved to the archive.<br>You can restore it anytime.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-modal-save">Yes, Archive</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── LOGOUT MODAL ── -->
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
                <a href="../logout.php" class="btn-modal-save" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:8px 22px;">Yes, Logout</a>
            </div>
        </div>
    </div>
</div>

<!-- ── TOASTS ── -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1100;">
    <div id="statusToast" class="toast border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastMessage"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
    <div id="restoreToast" class="toast text-bg-success border-0 mt-2">
        <div class="d-flex">
            <div class="toast-body">Record successfully restored.</div>
            <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
    <div id="archiveToast" class="toast text-bg-secondary border-0 mt-2" role="alert">
        <div class="d-flex">
            <div class="toast-body">Item archived successfully.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<!-- ── SCRIPTS ── -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-j1CDi7MgGQ12Z7Qab0qlWQ/Qqz24Gc6BM0thvEMVjHnfYGF0rmFCozFSxQBxwHKO"
    crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="../superadmin/includes/notif_poll.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // ── Toast notifications ──
    const params = new URLSearchParams(window.location.search);
    const statusMsgs = { added: "Item added successfully.", updated: "Item updated successfully.", deleted: "Item deleted successfully." };
    const status = params.get("status");
    if (status && statusMsgs[status]) {
        const el = document.getElementById("statusToast");
        el.classList.add("text-bg-success");
        document.getElementById("toastMessage").innerText = statusMsgs[status];
        new bootstrap.Toast(el, { delay: 3500 }).show();
        window.history.replaceState({}, "", location.pathname);
    }
    if (params.get("restored") === "1") {
        new bootstrap.Toast(document.getElementById("restoreToast"), { delay: 3000 }).show();
        window.history.replaceState({}, "", location.pathname);
    }
    if (params.get("archived") === "1") {
        new bootstrap.Toast(document.getElementById("archiveToast"), { delay: 3000 }).show();
        window.history.replaceState({}, "", location.pathname);
    }

    <?php if (!empty($notif)): ?>
    (function(){
        const el = document.getElementById("statusToast");
        el.classList.add("text-bg-danger");
        document.getElementById("toastMessage").innerText = <?= json_encode($notif) ?>;
        new bootstrap.Toast(el, { delay: 4000 }).show();
    })();
    <?php endif; ?>

    // ── State ──
    const state = {
        search: '',
        category: 'all',
        lowOnly: false,
        page: 1,
        perPage: 25,
        sortCol: null,
        sortDir: null
    };

    // ── Restore sessionStorage state ──
    const ss = sessionStorage;
    if (ss.getItem('inv_category'))  state.category = ss.getItem('inv_category');
    if (ss.getItem('inv_lowOnly'))   state.lowOnly  = ss.getItem('inv_lowOnly') === '1';
    if (ss.getItem('inv_perPage'))   state.perPage  = parseInt(ss.getItem('inv_perPage')) || 25;
    if (ss.getItem('inv_sortCol'))   state.sortCol  = ss.getItem('inv_sortCol');
    if (ss.getItem('inv_sortDir'))   state.sortDir  = ss.getItem('inv_sortDir');

    // ── DOM refs ──
    const tbody        = document.getElementById("myTable");
    const searchInput  = document.getElementById("myInputInventory");
    const btnLowStock  = document.getElementById("btnLowStock");
    const statLowStock = document.getElementById("statLowStock");
    const catTabs      = document.querySelectorAll(".cat-tab");
    const perPageSel   = document.getElementById("perPageSelect");
    const resultCount  = document.getElementById("resultCount");
    const paginationWrap = document.getElementById("paginationWrap");
    const btnPrev      = document.getElementById("btnPrev");
    const btnNext      = document.getElementById("btnNext");
    const pageInfo     = document.getElementById("pageInfo");
    const checkAll     = document.getElementById("checkAll");
    const bulkBar      = document.getElementById("bulkBar");
    const bulkCount    = document.getElementById("bulkCount");
    const bulkIdsContainer = document.getElementById("bulkIdsContainer");
    const btnBulkClear = document.getElementById("btnBulkClear");
    const sortableHeaders = document.querySelectorAll(".sortable-th");
    let emptyFilterRow = null;

    // Restore UI from state
    searchInput.value = state.search;
    perPageSel.value  = String(state.perPage);
    if (state.lowOnly) {
        btnLowStock.classList.add('active');
        statLowStock.classList.add('active-filter');
    }
    catTabs.forEach(t => {
        t.classList.toggle('active', t.dataset.cat === state.category);
    });
    if (state.sortCol) applySortHeaders();

    // ── applyFilters — single source of truth ──
    function applyFilters() {
        const allRows = Array.from(tbody.querySelectorAll("tr[data-id]"));
        const totalAll = allRows.length;

        // 1. Sort in place if sortCol set
        if (state.sortCol) {
            allRows.sort((a, b) => {
                let av, bv;
                if (state.sortCol === 'item') {
                    av = a.dataset.item || '';
                    bv = b.dataset.item || '';
                    return state.sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
                } else if (state.sortCol === 'category') {
                    av = a.dataset.category || '';
                    bv = b.dataset.category || '';
                    return state.sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
                } else if (state.sortCol === 'quantity') {
                    av = parseInt(a.dataset.qty) || 0;
                    bv = parseInt(b.dataset.qty) || 0;
                    return state.sortDir === 'asc' ? av - bv : bv - av;
                }
                return 0;
            });
            allRows.forEach(r => tbody.appendChild(r));
        }

        // 2. Apply search + category + lowOnly filters
        let visibleRows = allRows.filter(r => {
            const matchSearch   = !state.search || r.dataset.item.includes(state.search) ||
                                  r.querySelector('.item-name').textContent.toLowerCase().includes(state.search) ||
                                  (r.querySelector('.item-desc') || {textContent:''}).textContent.toLowerCase().includes(state.search);
            const matchCategory = state.category === 'all' || r.dataset.category === state.category;
            const matchLow      = !state.lowOnly || r.dataset.low === '1';
            return matchSearch && matchCategory && matchLow;
        });

        const visibleCount = visibleRows.length;

        // 3. Remove old empty state row
        if (emptyFilterRow) { emptyFilterRow.remove(); emptyFilterRow = null; }

        // 4. Pagination — paginate only visible rows
        const perPage = state.perPage === 0 ? visibleRows.length || 1 : state.perPage;
        const totalPages = Math.max(1, Math.ceil(visibleCount / perPage));
        if (state.page > totalPages) state.page = totalPages;
        const startIdx = (state.page - 1) * perPage;
        const endIdx   = startIdx + perPage;

        let rowNum = 1;
        allRows.forEach(r => {
            const isVisible = visibleRows.includes(r);
            const inPage    = isVisible && visibleRows.indexOf(r) >= startIdx && visibleRows.indexOf(r) < endIdx;
            r.style.display = inPage ? '' : 'none';
            if (inPage) {
                const numCell = r.querySelector('.row-num');
                if (numCell) numCell.textContent = startIdx + rowNum++;
            }
        });

        // 5. Result count label
        if (visibleCount < totalAll) {
            resultCount.textContent = `Showing ${Math.min(visibleCount - startIdx, perPage > visibleCount ? visibleCount : perPage)} of ${totalAll} items`;
        } else {
            resultCount.textContent = '';
        }

        // 6. Pagination controls
        if (totalPages > 1 || state.perPage !== 0) {
            paginationWrap.style.display = '';
            pageInfo.textContent = `Page ${state.page} of ${totalPages}`;
            btnPrev.disabled = state.page <= 1;
            btnNext.disabled = state.page >= totalPages;
        } else {
            paginationWrap.style.display = 'none';
        }

        // 7. Empty state
        if (visibleCount === 0) {
            emptyFilterRow = document.createElement('tr');
            emptyFilterRow.id = 'emptyFilterRow';
            let msg = '';
            if (state.lowOnly && !state.search && state.category === 'all') {
                msg = 'No low stock items. Everything is well stocked! ✓';
            } else if (state.search) {
                msg = `No items match "${state.search}".`;
            } else if (state.category !== 'all') {
                const catName = state.category.charAt(0).toUpperCase() + state.category.slice(1);
                msg = `No ${catName} items found.`;
            } else {
                msg = 'No items found.';
            }
            emptyFilterRow.innerHTML = `<td colspan="6"><div class="empty-state"><i class="bi bi-search"></i><p>${msg}</p></div></td>`;
            tbody.appendChild(emptyFilterRow);
        }

        // 8. Update sort header visuals
        applySortHeaders();

        // 9. Reset checkboxes
        updateBulkBar();
    }

    function applySortHeaders() {
        sortableHeaders.forEach(th => {
            th.classList.remove('sort-asc', 'sort-desc');
            if (th.dataset.sortCol === state.sortCol) {
                th.classList.add(state.sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
            }
        });
    }

    // ── Wire up column header sorting ──
    sortableHeaders.forEach(th => {
        th.addEventListener('click', () => {
            const col = th.dataset.sortCol;
            if (state.sortCol === col) {
                state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                state.sortCol = col;
                state.sortDir = 'asc';
            }
            state.page = 1;
            ss.setItem('inv_sortCol', state.sortCol);
            ss.setItem('inv_sortDir', state.sortDir);
            applyFilters();
        });
    });

    // ── Wire up search ──
    searchInput.addEventListener('input', () => {
        state.search = searchInput.value.toLowerCase().trim();
        state.page = 1;
        applyFilters();
    });

    // ── Wire up category tabs ──
    catTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            state.category = tab.dataset.cat;
            state.page = 1;
            catTabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            ss.setItem('inv_category', state.category);
            applyFilters();
        });
    });

    // ── Wire up low stock button ──
    function toggleLowStock() {
        state.lowOnly = !state.lowOnly;
        state.page = 1;
        btnLowStock.classList.toggle('active', state.lowOnly);
        statLowStock.classList.toggle('active-filter', state.lowOnly);
        ss.setItem('inv_lowOnly', state.lowOnly ? '1' : '0');
        applyFilters();
    }
    btnLowStock.addEventListener('click', toggleLowStock);
    statLowStock.addEventListener('click', toggleLowStock);

    // ── Per page ──
    perPageSel.value = String(state.perPage);
    perPageSel.addEventListener('change', () => {
        state.perPage = parseInt(perPageSel.value) || 0;
        state.page = 1;
        ss.setItem('inv_perPage', state.perPage);
        applyFilters();
    });

    // ── Pagination buttons ──
    btnPrev.addEventListener('click', () => { if (state.page > 1) { state.page--; applyFilters(); } });
    btnNext.addEventListener('click', () => { state.page++; applyFilters(); });

    // ── Bulk checkboxes ──
    function getCheckedRows() {
        return Array.from(tbody.querySelectorAll('.row-check:checked'));
    }

    function updateBulkBar() {
        const checked = getCheckedRows();
        const n = checked.length;
        if (n > 0) {
            bulkBar.classList.add('visible');
            bulkCount.textContent = `${n} item${n > 1 ? 's' : ''} selected`;
            bulkIdsContainer.innerHTML = '';
            checked.forEach(cb => {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'bulk_archive_ids[]';
                inp.value = cb.dataset.id;
                bulkIdsContainer.appendChild(inp);
            });
        } else {
            bulkBar.classList.remove('visible');
            checkAll.checked = false;
            checkAll.indeterminate = false;
        }
    }

    checkAll.addEventListener('change', () => {
        const visibleChecks = Array.from(tbody.querySelectorAll('tr[data-id]:not([style*="display: none"]) .row-check, tr[data-id]:not([style*="display:none"]) .row-check'));
        visibleChecks.forEach(cb => { cb.checked = checkAll.checked; });
        updateBulkBar();
    });

    tbody.addEventListener('change', e => {
        if (e.target.classList.contains('row-check')) updateBulkBar();
    });

    btnBulkClear.addEventListener('click', () => {
        tbody.querySelectorAll('.row-check').forEach(cb => { cb.checked = false; });
        checkAll.checked = false;
        checkAll.indeterminate = false;
        updateBulkBar();
    });

    // ── Inline quantity +/– via AJAX ──
    tbody.addEventListener('click', e => {
        const btn = e.target.closest('.qty-adjust-btn');
        if (!btn) return;
        const id    = parseInt(btn.dataset.id);
        const delta = btn.classList.contains('qty-plus') ? 1 : -1;
        const row   = btn.closest('tr');

        fetch('ajax_update_qty.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}&delta=${delta}&csrf_token=${encodeURIComponent('<?= $_SESSION['csrf_token'] ?? '' ?>')}`
        })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;
            const qty     = data.quantity;
            const lst     = parseInt(row.dataset.lst) || 0;
            const maxQty  = parseInt(row.dataset.max) || 1;
            const isLow   = lst > 0 && qty <= lst;
            const barPct  = Math.min(100, Math.round((qty / maxQty) * 100));

            // Update DOM
            document.getElementById('qty-' + id).textContent = qty;
            const bar  = document.getElementById('bar-' + id);
            const chip = document.getElementById('chip-' + id);
            bar.style.width = barPct + '%';
            bar.classList.toggle('low', isLow);
            chip.classList.toggle('d-none', !isLow);

            // Update data attrs
            row.dataset.qty = qty;
            row.dataset.low = isLow ? '1' : '0';

            // Update minus button disabled state
            const minusBtn = row.querySelector('.qty-minus');
            minusBtn.disabled = qty <= 0;

            // Re-apply filters so low-stock filter reacts
            applyFilters();
        })
        .catch(() => {});
    });

    // ── Edit modal populate ──
    document.querySelectorAll(".editBtn").forEach(btn => {
        btn.addEventListener("click", function () {
            document.getElementById("edit-id").value          = this.dataset.id;
            document.getElementById("edit-item").value        = this.dataset.item;
            document.getElementById("edit-quantity").value    = this.dataset.quantity;
            document.getElementById("edit-description").value = this.dataset.description;
            document.getElementById("edit-threshold").value   = this.dataset.threshold;
            const sel = document.getElementById("edit-category");
            for (let o of sel.options) { if (o.value === this.dataset.category) { o.selected = true; break; } }
        });
    });

    // ── Archive modal ──
    const archiveModal = new bootstrap.Modal(document.getElementById("archiveConfirmModal"));
    document.querySelectorAll(".archiveBtn").forEach(btn => {
        btn.addEventListener("click", e => {
            e.preventDefault();
            document.getElementById("archive-id").value = btn.dataset.id;
            archiveModal.show();
        });
    });

    // ── Initial render ──
    applyFilters();

});
</script>


    <!-- Footer — one design for all three roles (includes/staff_footer.php) -->
    <?php $marleFooterTerms = false; require __DIR__ . '/../includes/staff_footer.php'; ?>
</body>
</html>



