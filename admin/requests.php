<?php
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.requests');
require_once __DIR__ . '/../includes/booking_deposit.php';
require_once __DIR__ . '/../includes/booking_details_modal.php';
require_once __DIR__ . '/../includes/requests_pagination.php';
include('../admin/auth_admin.php');
include('../admin/requests_badge.php');
include('../admin/includes/notif_data.php');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 60-day archive cleanup now runs from admin/cron_archive.php.

// Logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid request.');
    }
    session_unset();
    session_destroy();
    header("Location: ../login.php?logout=success");
    exit();
}

// ── Extension requests data ───────────────────────────────
$extPending = [];
$extAll     = [];

$extResult = $conn->query(
    "SELECT r.id, r.customer_id, r.room_number, r.message,
            r.status, r.denial_reason,
            r.created_at,
            er.id           AS ext_id,
            er.hours_requested,
            er.room_type,
            er.rate_per_hour,
            er.total_amount,
            er.payment_status,
            er.special_request,
            eh.id           AS history_id,
            eh.payment_status AS hist_payment_status
     FROM requests r
     JOIN extension_requests er ON er.request_id = r.id
     LEFT JOIN extension_history eh ON eh.extension_req_id = er.id
     WHERE r.type = 'extend'
       AND r.is_archived = 0
     ORDER BY
         r.created_at DESC,
         r.id DESC"
);
if ($extResult) {
    while ($row = $extResult->fetch_assoc()) {
        $extAll[] = $row;
        if ($row['status'] === 'pending') $extPending[] = $row;
    }
}
$pendingCount = count($extPending);
$extApprovedTotal = array_sum(array_map(
    fn($e) => ($e['status'] === 'approved') ? (float)$e['total_amount'] : 0.0,
    $extAll
));
$extPendingTotal = array_sum(array_map(
    fn($e) => ($e['status'] === 'pending') ? (float)$e['total_amount'] : 0.0,
    $extAll
));
$extUnpaidTotal = array_sum(array_map(
    fn($e) => (
        $e['status'] === 'approved'
        && (($e['hist_payment_status'] ?? $e['payment_status'] ?? 'unpaid') === 'unpaid')
    ) ? (float)$e['total_amount'] : 0.0,
    $extAll
));

// ── Service requests data ─────────────────────────────────
$svcAll     = [];
$svcPending = [];

$svcResult = $conn->query(
    "SELECT r.id, r.customer_id, r.room_number, r.message,
            r.status, r.denial_reason, r.created_at,
            sr.id           AS svc_id,
            sr.service_name,
            sr.service_category,
            sr.quantity,
            sr.unit_price,
            sr.total_amount,
            sr.payment_status
     FROM requests r
     JOIN service_requests sr ON sr.request_id = r.id
     WHERE r.type = 'service'
       AND r.is_archived = 0
     ORDER BY
         r.created_at DESC,
         r.id DESC"
);
if ($svcResult) {
    while ($row = $svcResult->fetch_assoc()) {
        $svcAll[] = $row;
        if ($row['status'] === 'pending') $svcPending[] = $row;
    }
}
$svcPendingCount = count($svcPending);

// Cost summary
$svcApprovedTotal = array_sum(array_map(
    fn($s) => ($s['status'] === 'approved') ? (float)$s['total_amount'] : 0.0,
    $svcAll
));
$svcPendingTotal = array_sum(array_map(
    fn($s) => ($s['status'] === 'pending') ? (float)$s['total_amount'] : 0.0,
    $svcAll
));
$svcUnpaidTotal = array_sum(array_map(
    fn($s) => ($s['status'] === 'approved' && $s['payment_status'] === 'unpaid')
               ? (float)$s['total_amount'] : 0.0,
    $svcAll
));

// ── Food order requests data ──────────────────────────────
$foodAll     = [];
$foodPending = [];

$foodResult = $conn->query(
    "SELECT r.id, r.customer_id, r.room_number, r.message,
            r.status, r.denial_reason, r.created_at,
            SUM(foi.total_amount)               AS total_amount,
            MAX(foi.payment_status)             AS payment_status,
            GROUP_CONCAT(
                foi.item_name, ' x', foi.quantity
                ORDER BY foi.id SEPARATOR ' | '
            )                                   AS item_summary,
            GROUP_CONCAT(
                DISTINCT NULLIF(foi.special_request, '')
                ORDER BY foi.id SEPARATOR ' / '
            )                                   AS special_requests
     FROM requests r
     JOIN food_order_items foi ON foi.request_id = r.id
     WHERE r.type = 'food'
       AND r.is_archived = 0
     GROUP BY r.id, r.customer_id, r.room_number, r.message,
              r.status, r.denial_reason, r.created_at
     ORDER BY
         r.created_at DESC,
         r.id DESC"
);
if ($foodResult) {
    while ($row = $foodResult->fetch_assoc()) {
        $foodAll[] = $row;
        if ($row['status'] === 'pending') $foodPending[] = $row;
    }
}
$foodPendingCount = count($foodPending);

// Food cost summary
$foodApprovedTotal = array_sum(array_map(
    fn($f) => ($f['status'] === 'approved') ? (float)$f['total_amount'] : 0.0,
    $foodAll
));
$foodPendingTotal = array_sum(array_map(
    fn($f) => ($f['status'] === 'pending') ? (float)$f['total_amount'] : 0.0,
    $foodAll
));
$foodUnpaidTotal = array_sum(array_map(
    fn($f) => ($f['status'] === 'approved' && $f['payment_status'] === 'unpaid')
               ? (float)$f['total_amount'] : 0.0,
    $foodAll
));

// ── Booking requests data ─────────────────────────────────
// "The booking list" is defined once, in includes/booking_watch.php: the same
// query and the same archive/deposit column probes feed this page AND the
// bookings_watch.php endpoint, so the auto-refresh at the bottom of this page
// always compares like for like (a drifting query would reload in a loop).
require_once __DIR__ . '/../includes/booking_watch.php';
$bookAll          = marle_bookings_active($conn);
$bookPending      = array_values(array_filter($bookAll, fn($b) => $b['status'] === 'pending'));
$bookPendingCount = count($bookPending);
$bookSignature    = marle_bookings_signature($bookAll);

// ── Chat rooms list (left panel) ──────────────────────────
$rooms_result = $conn->query(
    "SELECT m.room_number,
            MAX(m.sent_at) AS last_time,
            SUM(m.sender='guest' AND m.is_read=0) AS unread,
        (SELECT message FROM chat_messages m2
         WHERE m2.room_number = m.room_number
           AND m2.customer_id = gt.customer_id
           AND m2.archived_at IS NULL
         ORDER BY sent_at DESC LIMIT 1) AS last_msg,
            COALESCE(crs.status, 'new') AS chat_status
     FROM guest_tokens gt
     JOIN chat_messages m
       ON m.room_number = gt.room_number
      AND m.customer_id = gt.customer_id
      AND m.archived_at IS NULL
     LEFT JOIN chat_room_status crs ON crs.room_number = m.room_number
     WHERE gt.expires_at > NOW()
       AND gt.customer_id IS NOT NULL
     GROUP BY m.room_number, gt.customer_id
     ORDER BY last_time DESC"
);



// ── Admin profile picture ─────────────────────────────────
$username   = $_SESSION['username'];
$imgSrc     = "../img/default.jpg";
$stmtUser = $conn->prepare("SELECT picture FROM userstbl WHERE username = ? LIMIT 1");
$stmtUser->bind_param("s", $username);
$stmtUser->execute();
$resultUser = $stmtUser->get_result();
if ($resultUser && $resultUser->num_rows > 0) {
    $userRow = $resultUser->fetch_assoc();
    if (!empty($userRow['picture'])) {
        $finfo   = new finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->buffer($userRow['picture']);
        $imgSrc  = "data:$mime;base64," . base64_encode($userRow['picture']);
    }
}
$stmtUser->close();
?>

<!DOCTYPE html>
<html lang="<?= ui_html_lang() ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-4Q6Gf2aSP4eDXB8Miphtr37CMZZQ5oXLH2yaXMJ2w8e2ZtHTl7GptT4jmndRuHDT" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <?php require __DIR__ . '/../includes/staff_icons.php'; ?>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
    <link rel="stylesheet" href="../css/staff-requests.css?v=20261029">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.0"></script>
    <title>Requests</title>

    <style>
        body { overflow-x: hidden; background-color: #f2ece9; }

        @media (min-width: 768px) {
            .navbar {
                width: 230px; height: 100vh;
                position: fixed; top: 0; left: 0;
                background-color: #f8f9fa;
                display: flex; flex-direction: column;
            }
            .navbar>.container-fluid { height: 100%; display: flex; flex-direction: column; }
            .navbar-collapse { display: flex !important; flex-direction: column; flex: 1; }
            .content { margin-left: 230px; margin-top: 1.5rem; }
        }

        .nav-link { padding: 12px 18px; color: #555; border-radius: 12px; position: relative; transition: all 0.3s ease; }
        .nav-link:hover { background-color: #ffb60a; color: #fff; }
        .navbar-nav .nav-link.active { background-color: #ffb60a !important; color: #fff !important; border-radius: 12px; }
        .navbar-nav .nav-link.active .notification-badge { background-color: #fff !important; color: #ffb60a !important; }

        .nav-tabs .nav-link.active,
        .nav-tabs .nav-link.show.active,
        .nav-tabs .nav-link.active:focus,
        .nav-tabs .nav-link.active:hover {
            background-color: #ffb60a !important; color: #fff !important;
            border-color: #ffb60a !important; border-bottom-color: #ffb60a !important;
            font-weight: 500; box-shadow: none !important;
        }
        .nav-tabs .nav-link .badge { background-color: #ffb60a !important; color: #fff !important; }
        .nav-tabs .nav-link.active .badge { background-color: #fff !important; color: #ffb60a !important; }

        .nav-link.active::before {
            content: ""; position: absolute; left: -12px; top: 8px; bottom: 8px;
            width: 4px; border-radius: 4px; background-color: #ffb60a;
        }
        .nav-link i { font-size: 18px; }

        .notification-badge { background-color: #fff; color: #933310; font-size: 12px; padding: 5px 8px; }

        .logout-btn { background-color: #933310; color: #fff; border: none; padding: 10px; border-radius: 12px; transition: 0.3s; }
        a { color: #ffb60a; text-decoration: none; }
        .logout-btn:hover, #yesBtn:hover, #noBtn:hover { background-color: transparent; border-color: #933310; color: #933310; }
        #yesBtn { background-color: #ffb60a; color: #fff; }

        .card { border-radius: 20px; padding-left: 15px; }
        .card h5 { font-weight: 400; margin-top: 15px; }
        .card-text { font-size: 30px; font-weight: 700; }

        #myInput { border: solid #ffb60a 3px; height: 45px; }
        .bi-bell { font-size: 20px; padding: 7px; border-radius: 10px; background-color: transparent; color: #000; margin-right: 0; }
        .nav-link.active .bi-bell { color: #fff; }
        .search-box { border: solid #ffb60a 3px; max-width: 220px; }
        .bg-custom-success { background-color: #ffb60a !important; }
        .bi-sliders2-vertical { background-color: #ffb60a; color: #fff; padding: 7px; margin: 10px; border-radius: 10px; }

        .main-card {
            background-color: #fff; border-radius: 20px;
            width: min(calc(100% - var(--sidebar-w) - 40px), 1400px); max-width: 1400px;
            padding: 20px; margin: 20px 20px 20px calc(var(--sidebar-w) + max(20px, (100% - var(--sidebar-w) - 1400px) / 2));
        }
        @media (max-width: 768px) { .main-card { margin: 10px; width: calc(100% - 20px); } }

        .request-layout { height: 77vh; }

        .messages { width: 35%; border-right: 1px solid #eee; display: flex; flex-direction: column; }
        .message-list-container { flex: 1; overflow-y: auto; padding-right: 5px; }
        .search-filter { gap: 5px; margin-bottom: 10px; }
        .filter-icon { background: #ffb60a; color: white; padding: 10px; border-radius: 8px; }
        .message-list-container { height: 600px; overflow-y: auto; padding-right: 5px; }
        .icon-btn { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .chip {
            font-size: 11px; font-weight: 500;
            padding: 4px 14px; border-radius: 20px;
            border: 1px solid #e0dbd4; background: #fff;
            color: #6b6560; cursor: pointer; transition: all .15s;
        }
        .chip.active, .chip:hover { background: #ffb60a; border-color: #ffb60a; color: #412402; }

        .message-item { display: flex; gap: 10px; padding: 12px; cursor: pointer; border-bottom: solid 1px #888; }
        .message-item:hover { background: #f6f6f6; }
        .avatar { width: 35px; height: 35px; border-radius: 8px; }
        .room-avatar {
            width: 42px; height: 42px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 600; flex-shrink: 0;
            border: 2px solid transparent;
        }
        .message-item.active .room-avatar { border-color: #ffb60a; }
        .message-details { width: 100%; }

        @media (max-width: 768px) { .message-item .avatar { display: none; } }

        .top-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .bottom-row { display: flex; justify-content: space-between; align-items: center; font-size: 15px; }

        .status { font-size: 11px; padding: 2px 6px; border-radius: 6px; font-weight: 600; white-space: nowrap; }
        .status.new         { background: #6c757d; color: white; }
        .status.in-progress { background: #0d6efd; color: white; }
        .status.completed   { background: #198754; color: white; }

        .bi-check-all.unread    { color: #ffb60a; }
        .bi-check-all.completed { color: #888; }
        .time { font-size: 12px; color: #888; margin-top: 20px; }

        .main-message {
            width: 65%; background: #f2ece9; margin-left: 15px;
            border-radius: 15px; padding: 15px;
            display: flex; flex-direction: column;
        }
        .main-message-content { flex: 1; overflow-y: auto; padding-right: 5px; }
        .guest-name { background-color: #fff; padding: 15px; border-radius: 10px; margin-bottom: 30px; }
        .room-number { font-size: 11px; padding: 2px 6px; border-radius: 6px; background-color: #ffb60a; color: #fff; }
        .chat-sub-status { font-size: 11px; color: #6b6560; display: flex; align-items: center; gap: 4px; }
        .dot-online { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; display: inline-block; }
        .chat-closed-bar {
            display: none; background: #fef2f2; border: 1px solid #fecaca;
            border-radius: 10px; padding: 9px 14px; margin-bottom: 10px;
            font-size: 12px; color: #991b1b; font-weight: 500; text-align: center;
        }

        .btn-success:hover { border-color: #933310; color: #933310; background-color: transparent; }

        .guest-request { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
        .guest-message { background: white; padding: 8px 12px; border-radius: 10px; }
        .reply {
            display: inline-block; align-self: flex-end;
            background: #ffb60a; color: white;
            padding: 10px; border-radius: 10px;
            margin: 10px 0; max-width: 80%; width: auto; word-wrap: break-word;
        }
        .main-message-content { display: flex; flex-direction: column; gap: 8px; }

        .msg-checkmark { font-size: 11px; margin-left: 5px; opacity: 0.85; }
        .msg-checkmark.unread { color: rgba(255,255,255,0.55); }
        .msg-checkmark.read   { color: #fff; }

        .chat-ts { font-size: 10px; margin-top: 3px; line-height: 1.3; opacity: 0.7; }
        .chat-ts.admin-bubble { color: rgba(255,255,255,0.75); text-align: right; }
        .chat-ts.guest-bubble { color: #999; text-align: left; }
        .chat-ts .ts-read { display: block; font-style: italic; opacity: 0.85; }

        .attach-preview { margin-top: 5px; padding: 6px 10px; background: rgba(255,255,255,0.2); border-radius: 6px; font-size: 12px; display: flex; align-items: center; gap: 6px; }
        .attach-preview a { color: #fff; text-decoration: underline; word-break: break-all; }
        .attach-preview.guest-attach { background: #f0f0f0; }
        .attach-preview.guest-attach a { color: #333; }
        .attach-thumb { max-height: 80px; max-width: 140px; border-radius: 6px; display: block; margin-top: 5px; }

        #filePreviewBar {
            display: none;
            background: #fff3cd; border: 1px solid #ffc107;
            border-radius: 8px; padding: 6px 10px; margin-bottom: 6px;
            font-size: 13px; align-items: center; gap: 8px;
        }
        #filePreviewBar .preview-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        #filePreviewBar img { max-height: 40px; border-radius: 4px; }
        #fileErrorMsg { display: none; color: #dc3545; font-size: 12px; margin-bottom: 4px; padding: 0 4px; }

        .reply-box-area {
            display: flex; flex-direction: column;
            background: #fff; padding: 10px; border-radius: 15px; margin-top: 10px;
        }
        .reply-input-row { display: flex; align-items: center; gap: 10px; }
        .reply-box-area input[type="text"] { flex: 1; border: none; padding: 10px; border-radius: 8px; }

        .bi-paperclip { font-size: 25px; cursor: pointer; }
        .send {
            background: #ffb60a; color: white; width: 42px; height: 42px;
            border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .send:hover { border-color: #933310; color: #933310; background-color: transparent; }

        /* ── Extension / Service cards ── */
        .ext-card, .svc-card {
            background: #f9f6f3; border: 1px solid #e9e0d8; border-radius: 14px;
            padding: 16px 18px; margin-bottom: 12px;
        }
        .ext-card.pending, .svc-card.pending { border-left: 4px solid #ffb60a; }
        .ext-card.approved, .svc-card.approved { border-left: 4px solid #198754; }
        .ext-card.denied, .svc-card.denied { border-left: 4px solid #dc3545; }

        /* ── Service cost summary card ── */
        .svc-summary-card {
            background: linear-gradient(135deg, #fffbf0 0%, #fff8e1 100%);
            border: 1px solid #ffb60a; border-radius: 14px;
            padding: 16px 20px; margin-bottom: 20px;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px;
        }
        .svc-summary-card .svc-sum-item { text-align: center; }
        .svc-summary-card .svc-sum-label { font-size: 0.72rem; color: #888; text-transform: uppercase; margin-bottom: 2px; }
        .svc-summary-card .svc-sum-value { font-size: 1.25rem; font-weight: 700; color: #333; }
        .svc-summary-card .svc-sum-value.highlight { color: #933310; }

        .footer { margin-top: 3rem; text-decoration: none; color: #933310; }
    </style>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
    <link rel="stylesheet" href="../css/staff-footer.css?v=20261021">
    <link rel="stylesheet" href="../css/staff-requests.css?v=20261029">

<?php require_once __DIR__ . '/../includes/server_clock.php'; marle_server_clock_assets(); ?>
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>

<body class="staff-page staff-requests-page staff-admin">

<?php
// The wording this page shows from its own JavaScript — the confirm dialogs on
// Approve/Archive and the failure toasts. The switch never rewrites code, so the
// phrases are written here, in the markup, and read back by the script (LB).
$requestLabels = [
    'approve-extension' => ["Approve this extension? The guest's session will be extended immediately.",
                            'Aprubahan ang extension na ito? Agad na mapapahaba ang sesyon ng bisita.'],
    'approve-service'   => ['Approve this service request?', 'Aprubahan ang kahilingan sa serbisyong ito?'],
    'approve-food'      => ['Approve this food order?', 'Aprubahan ang order na pagkain na ito?'],
    'archive-request'   => ['Archive this request? It will be hidden from active tabs.',
                            'I-archive ang kahilingang ito? Maitatago ito mula sa mga aktibong tab.'],
    'archive-booking'   => ['Archive this booking? It will be hidden from active booking cards.',
                            'I-archive ang booking na ito? Maitatago ito mula sa mga aktibong booking card.'],
    'saved'             => ['Saved.', 'Nai-save.'],
    'save-failed'       => ['Could not save the decision.', 'Hindi nai-save ang desisyon.'],
    'unreachable'       => ['Could not reach the server. Please try again.', 'Hindi maabot ang server. Subukan muli.'],
    'load-failed'       => ['This booking could not be loaded.', 'Hindi na-load ang booking na ito.'],
    'cancel-failed'     => ['The booking could not be cancelled.', 'Hindi makansela ang booking.'],
    'amend-failed'      => ['The booking could not be changed.', 'Hindi mabago ang booking.'],
    'no-archived'       => ['No archived conversations or requests found.',
                            'Walang nahanap na naka-archive na usapan o kahilingan.'],
    'file-too-large'    => ['⚠ File too large. Maximum size is 5 MB.', '⚠ Masyadong malaki ang file. Hanggang 5 MB lang.'],
];
?>
<div id="requestLabels" class="d-none" hidden<?php foreach ($requestLabels as $reqKey => [$reqEn, $reqTl]): ?> data-<?= $reqKey ?>="<?= htmlspecialchars(ui_t($reqEn, $reqTl), ENT_QUOTES, 'UTF-8') ?>"<?php endforeach; ?>></div>
<script>const LB = document.getElementById('requestLabels').dataset;</script>

    <!-- ── SIDEBAR ── -->
    <?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

    <!-- ── TOP BAR ── -->
    <?php
    $staffPageTitle  = 'Requests';
    $staffPageNote   = 'Guest chat, extensions, service and food requests in one queue.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['Requests', null]];
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

    <!-- ── TABS ── -->
    <div class="main-card">
        <ul class="nav nav-tabs" id="reqTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="chat-tab"
                        data-bs-toggle="tab" data-bs-target="#tabChat"
                        type="button" role="tab">
                    <i class="bi bi-chat-dots me-1"></i>Chat
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="ext-tab"
                        data-bs-toggle="tab" data-bs-target="#tabExtensions"
                        type="button" role="tab">
                    <i class="bi bi-calendar-plus me-1"></i>Extensions
                    <?php if ($pendingCount > 0): ?>
                        <span class="badge bg-warning text-white ms-1 pulse"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </button>
            </li>
<!-- ★ NEW: Services tab -->
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="svc-tab"
                        data-bs-toggle="tab" data-bs-target="#tabServices"
                        type="button" role="tab">
                    <i class="bi bi-bell me-1"></i>Services
                    <?php if ($svcPendingCount > 0): ?>
                        <span class="badge bg-warning text-white ms-1"><?= $svcPendingCount ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <!-- Food tab -->
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="food-tab"
                        data-bs-toggle="tab" data-bs-target="#tabFood"
                        type="button" role="tab">
                    <i class="bi bi-egg-fried me-1"></i>Food
                    <?php if ($foodPendingCount > 0): ?>
                        <span class="badge bg-warning text-white ms-1"><?= $foodPendingCount ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <!-- Bookings tab -->
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="book-tab"
                        data-bs-toggle="tab" data-bs-target="#tabBookings"
                        type="button" role="tab">
                    <i class="bi bi-journal-bookmark me-1"></i>Bookings
                    <?php if ($bookPendingCount > 0): ?>
                        <span class="badge bg-warning text-white ms-1"><?= $bookPendingCount ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <!-- Archive tab -->
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="archive-tab"
                        data-bs-toggle="tab" data-bs-target="#tabArchive"
                        type="button" role="tab">
                    <i class="bi bi-archive me-1"></i>Archive
                </button>
            </li>
        </ul>

        <div class="tab-content">

            <!-- ══════════════════════════════════════════
                 TAB 1 — CHAT
            ══════════════════════════════════════════ -->
            <div class="tab-pane fade show active" id="tabChat" role="tabpanel">
                <div class="d-flex request-layout">

                    <!-- LEFT PANEL -->
                    <div class="messages">
                        <div class="search-filter d-flex flex-column gap-1">
                            <div class="d-flex align-items-center">
                                <input class="form-control" id="chatSearch" type="text"
                                       placeholder="Search room or message..." autocomplete="off">
                                <button class="btn btn-outline-secondary btn-sm ms-2 icon-btn" data-bs-toggle="modal"
                                        data-bs-target="#oldConversationsModal" title="Old conversations">
                                    <i class="bi bi-archive"></i>
                                </button>
                            </div>
                            <div class="filter-chips d-flex gap-2 flex-wrap">
                                <button class="chip active" data-status="all"         onclick="setStatusFilter(this)">All</button>
                                <button class="chip"        data-status="new"         onclick="setStatusFilter(this)">New</button>
                                <button class="chip"        data-status="in_progress" onclick="setStatusFilter(this)">Active</button>
                                <button class="chip"        data-status="complete"    onclick="setStatusFilter(this)">Done</button>
                            </div>
                        </div>

                        <div class="message-list-container" id="roomList">
                            <?php
                            if ($rooms_result && $rooms_result->num_rows > 0):
                                while ($r = $rooms_result->fetch_assoc()):
                                    $unread     = (int)$r['unread'];
                                    $chatStatus = $r['chat_status'] ?? 'new';

                                    $badgeClass = match($chatStatus) {
                                        'in_progress' => 'in-progress',
                                        'complete'    => 'completed',
                                        default       => 'new',
                                    };
                                    $badgeLabel = match($chatStatus) {
                                        'in_progress' => 'In Progress',
                                        'complete'    => 'Complete',
                                        default       => 'New',
                                    };
                                    $colors = [
                                        ['bg'=>'#FAEEDA','text'=>'#633806'],
                                        ['bg'=>'#E6F1FB','text'=>'#0C447C'],
                                        ['bg'=>'#EAF3DE','text'=>'#27500A'],
                                        ['bg'=>'#FAECE7','text'=>'#712B13'],
                                        ['bg'=>'#EEEDFE','text'=>'#3C3489'],
                                    ];
                                    $ci = abs(crc32((string)$r['room_number'])) % count($colors);
                                    $c = $colors[$ci];
                            ?>
                            <div class="message-item"
                                 data-room="<?= htmlspecialchars($r['room_number']) ?>"
                                 data-chat-status="<?= htmlspecialchars($chatStatus) ?>"
                                 onclick="openChat('<?= htmlspecialchars($r['room_number']) ?>')">
                                <div class="room-avatar" style="background:<?= $c['bg'] ?>; color:<?= $c['text'] ?>;">
                                    <?= htmlspecialchars($r['room_number']) ?>
                                </div>
                                <div class="message-details">
                                    <div class="top-row">
                                        <span class="guest-number">Room <?= htmlspecialchars($r['room_number']) ?></span>
                                        <span class="status <?= $badgeClass ?> room-status-badge"><?= $badgeLabel ?></span>
                                        <?php if ($unread > 0): ?>
                                            <span class="badge bg-danger rounded-pill"
                                                  style="font-size:10px;"
                                                  title="<?= $unread ?> unread message<?= $unread > 1 ? 's' : '' ?>">
                                                <?= $unread ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="bottom-row">
                                        <p class="message-peek mb-0"><?php
                                            $msg = $r['last_msg'] ?? '';
                                            echo htmlspecialchars(strlen($msg) > 40 ? substr($msg, 0, 40) . '...' : $msg);
                                        ?></p>
                                        <span class="time"><?= date('h:i A', strtotime($r['last_time'])) ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php
                                endwhile;
                            else:
                            ?>
                                <?php
                                $emptyIcon = 'bi-chat-square-text';
                                $emptyTitle = 'No conversations yet';
                                $emptyTitleTl = 'Wala pang usapan';
                                $emptyBody = 'A guest appears here as soon as they send a message from the portal.';
                                $emptyBodyTl = 'Lalabas dito ang bisita sa sandaling magpadala siya ng mensahe mula sa portal.';
                                require __DIR__ . '/../includes/staff_empty_state.php';
                                ?>
                            <?php endif; ?>
                        </div>
                    </div><!-- /.messages -->

                    <!-- RIGHT PANEL -->
                    <div class="main-message">
                        <div class="guest-name d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-2">
                                <div class="room-avatar" id="activeChatAvatar"></div>
                                <div>
                                    <strong id="activeChatName">Select a conversation</strong>
                                    <div class="d-flex align-items-center gap-1 mt-1">
                                        <span class="room-number" id="activeChatRoom"></span>
                                        <span id="activeChatStatus" class="chat-sub-status" style="display:none;">
                                            <span class="dot-online"></span> Active now
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="main-message-content" id="chatMessageBox">
                            <p class="text-muted text-center mt-5" style="font-size:14px;">
                                Select a guest from the left to view their messages.
                            </p>
                        </div>

                        <div class="reply-box-area">
                            <div id="chatClosedIndicator" class="chat-closed-bar">
                                🔒 This conversation is closed
                            </div>
                            <div id="fileErrorMsg"></div>
                            <div id="filePreviewBar" style="display:none;">
                                <span id="filePreviewIcon" style="font-size:18px;"></span>
                                <span class="preview-name" id="filePreviewName"></span>
                                <img id="filePreviewThumb" src="" alt="" style="display:none; max-height:40px; border-radius:4px;">
                                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto"
                                        onclick="clearAttachment()" title="Remove file">✕</button>
                            </div>
                            <div class="reply-input-row" id="replyInputRow">
                                <input type="file" id="attachmentInput"
                                       accept=".jpg,.jpeg,.png,.gif,.pdf,.docx,.xlsx"
                                       style="display:none;"
                                       onchange="handleFileSelect(this)">
                                <input type="text" id="adminReplyInput" placeholder="Type a message...">
                                <i class="bi bi-paperclip" id="attachBtn"
                                   onclick="document.getElementById('attachmentInput').click()"
                                   title="Attach file (max 5 MB)"></i>
                                <button class="btn send" onclick="sendAdminReply()" title="Send message">
                                    <i class="bi bi-send-fill"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </div><!-- /#tabChat -->

            <!-- ══════════════════════════════════════════
                 TAB 2 — EXTENSIONS
            ══════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabExtensions" role="tabpanel">
                <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
                    <select class="form-select form-select-sm" id="extStatusFilter" 
                            style="max-width:160px;" onchange="filterExtensions()">
                        <option value="all">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="denied">Denied</option>
                    </select>
                </div>

                <div class="svc-summary-card">
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Total</div>
                        <div class="svc-sum-value"><?= count($extAll) ?></div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Pending</div>
                        <div class="svc-sum-value <?= $pendingCount > 0 ? 'highlight' : '' ?>"><?= $pendingCount ?></div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Pending Value</div>
                        <div class="svc-sum-value <?= $extPendingTotal > 0 ? 'highlight' : '' ?>">&#8369;<?= number_format($extPendingTotal, 2) ?></div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Approved Total</div>
                        <div class="svc-sum-value highlight">&#8369;<?= number_format($extApprovedTotal, 2) ?></div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Outstanding</div>
                        <div class="svc-sum-value <?= $extUnpaidTotal > 0 ? 'highlight' : '' ?>">&#8369;<?= number_format($extUnpaidTotal, 2) ?></div>
                    </div>
                </div>

                <?php if (empty($extAll)): ?>
                    <p class="text-muted p-2">No extension requests yet.</p>
                <?php endif; ?>

                <?php foreach ($extAll as $ext): ?>
                <?php $extPayStatus = $ext['hist_payment_status'] ?? $ext['payment_status'] ?? 'unpaid'; ?>
                <div class="ext-card <?= htmlspecialchars($ext['status']) ?>" data-request-id="<?= (int)$ext['id'] ?>" 
                     data-ext-status="<?= htmlspecialchars($ext['status']) ?>"
                     data-ext-payment="<?= htmlspecialchars($extPayStatus) ?>">

                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                            <span class="fw-bold">Room <?= htmlspecialchars($ext['room_number']) ?></span>
                            <span class="text-muted small ms-2">
                                <?= date('M j, Y g:ia', strtotime($ext['created_at'])) ?>
                            </span>
                        </div>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <span class="badge bg-light text-dark border">
                                +<?= (int)$ext['hours_requested'] ?>h
                                &nbsp;&bull;&nbsp;
                                &#8369;<?= number_format((float)$ext['total_amount'], 2) ?>
                            </span>
                            <?php if ($ext['status'] !== 'denied'): ?>
                                <span class="payment-badge badge <?= $extPayStatus === 'paid' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                    <?= ucfirst($extPayStatus) ?>
                                </span>
                            <?php endif; ?>
                            <span class="status-badge badge <?php
                                echo match($ext['status']) {
                                    'pending'  => 'bg-warning text-dark',
                                    'approved' => 'bg-success',
                                    'denied'   => 'bg-danger',
                                    default    => 'bg-secondary'
                                };
                            ?>">
                                <?= ucfirst($ext['status']) ?>
                            </span>
                        </div>
                    </div>

                    <p class="mb-1 fw-semibold"><?= htmlspecialchars($ext['message']) ?></p>
                    <p class="mb-1 text-muted small">
                        <i class="bi bi-building me-1"></i><?= htmlspecialchars($ext['room_type']) ?>
                        &nbsp;&bull;&nbsp; &#8369;<?= number_format((float)$ext['rate_per_hour'], 0) ?>/hr
                        &nbsp;&bull;&nbsp; Front Desk payment
                    </p>

                    <?php if (!empty($ext['special_request'])): ?>
                        <p class="mb-2 text-muted small">
                            <i class="bi bi-chat-quote me-1"></i>
                            <?= htmlspecialchars($ext['special_request']) ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($ext['status'] === 'pending'): ?>
                    <div class="d-flex gap-2 flex-wrap mt-2">
                        <button class="btn btn-success btn-sm"
                                onclick="approveExt(<?= (int)$ext['id'] ?>)">
                            <i class="bi bi-check-lg me-1"></i>Approve &amp; Extend
                        </button>
                        <button class="btn btn-outline-danger btn-sm"
                                onclick="openDenyModal(<?= (int)$ext['id'] ?>)">
                            <i class="bi bi-x-lg me-1"></i>Deny
                        </button>
                    </div>
                    <?php endif; ?>

                    <?php if ($ext['status'] === 'denied' && !empty($ext['denial_reason'])): ?>
                    <div class="alert alert-danger py-2 mt-2 mb-0 small">
                        <i class="bi bi-info-circle me-1"></i>
                        Reason: <?= htmlspecialchars($ext['denial_reason']) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($ext['status'] !== 'pending'): ?>
                    <div class="mt-2 text-end">
                        <button class="btn btn-outline-secondary btn-sm"
                                onclick="archiveRequest(<?= (int)$ext['id'] ?>, this)">
                            <i class="bi bi-archive me-1"></i>Archive
                        </button>
                    </div>
                    <?php endif; ?>

                </div>
                <?php endforeach; ?>

            </div><!-- /#tabExtensions -->

            <!-- ══════════════════════════════════════════
                 TAB 3 — SERVICES  ★ NEW
            ══════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabServices" role="tabpanel">
                <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
                    <select class="form-select form-select-sm" id="svcStatusFilter" 
                            style="max-width:160px;" onchange="filterServices()">
                        <option value="all">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="approved-unpaid">Approved - Unpaid</option>
                        <option value="approved-paid">Approved - Paid</option>
                        <option value="denied">Denied</option>
                    </select>
                </div>

                <!-- Cost summary card -->
                <div class="svc-summary-card">
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Total Requests</div>
                        <div class="svc-sum-value"><?= count($svcAll) ?></div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Pending</div>
                        <div class="svc-sum-value"><?= $svcPendingCount ?></div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Pending Value</div>
                        <div class="svc-sum-value <?= $svcPendingTotal > 0 ? 'highlight' : '' ?>">
                            &#8369;<?= number_format($svcPendingTotal, 2) ?>
                        </div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Approved Total</div>
                        <div class="svc-sum-value highlight">&#8369;<?= number_format($svcApprovedTotal, 2) ?></div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Outstanding (Unpaid)</div>
                        <div class="svc-sum-value <?= $svcUnpaidTotal > 0 ? 'highlight' : '' ?>">
                            &#8369;<?= number_format($svcUnpaidTotal, 2) ?>
                        </div>
                    </div>
                </div>

                <?php if (empty($svcAll)): ?>
                    <p class="text-muted p-2">No service requests yet.</p>
                <?php endif; ?>

                <?php foreach ($svcAll as $svc): ?>
                <div class="svc-card <?= htmlspecialchars($svc['status']) ?>" data-request-id="<?= (int)$svc['id'] ?>" 
                     data-svc-status="<?= htmlspecialchars($svc['status']) ?>" 
                     data-svc-payment="<?= htmlspecialchars($svc['payment_status']) ?>">

                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                            <span class="fw-bold">Room <?= htmlspecialchars($svc['room_number']) ?></span>
                            <span class="badge bg-secondary ms-1" style="font-size:10px;">
                                <?= htmlspecialchars(ucfirst($svc['service_category'])) ?>
                            </span>
                            <span class="text-muted small ms-2">
                                <?= date('M j, Y g:ia', strtotime($svc['created_at'])) ?>
                            </span>
                        </div>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <?php if ((float)$svc['total_amount'] > 0): ?>
                            <span class="badge bg-light text-dark border">
                                x<?= (int)$svc['quantity'] ?>
                                &nbsp;&bull;&nbsp;
                                &#8369;<?= number_format((float)$svc['unit_price'], 2) ?>/ea
                                &nbsp;&bull;&nbsp;
                                &#8369;<?= number_format((float)$svc['total_amount'], 2) ?>
                            </span>
                            <?php else: ?>
                            <span class="badge bg-light text-dark border">Free</span>
                            <?php endif; ?>

                            <?php if ($svc['status'] === 'approved'): ?>
                                <span class="payment-badge badge <?= $svc['payment_status'] === 'paid' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                    <?= ucfirst($svc['payment_status']) ?>
                                </span>
                            <?php endif; ?>
                            <span class="status-badge badge <?php
                                echo match($svc['status']) {
                                    'pending'  => 'bg-warning text-dark',
                                    'approved' => 'bg-success',
                                    'denied'   => 'bg-danger',
                                    default    => 'bg-secondary'
                                };
                            ?>">
                                <?= ucfirst($svc['status']) ?>
                            </span>
                        </div>
                    </div>

                    <p class="mb-1 fw-semibold">
                        <i class="bi bi-bell me-1 text-muted"></i>
                        <?= htmlspecialchars($svc['service_name']) ?>
                    </p>
                    <p class="mb-1 text-muted small">
                        Guest ID #<?= (int)$svc['customer_id'] ?>
                        &nbsp;&bull;&nbsp; Front Desk payment
                    </p>

                    <!-- Pending: Approve + Deny -->
                    <?php if ($svc['status'] === 'pending'): ?>
                    <div class="d-flex gap-2 flex-wrap mt-2">
                        <button class="btn btn-success btn-sm"
                                onclick="approveSvc(<?= (int)$svc['id'] ?>)">
                            <i class="bi bi-check-lg me-1"></i>Approve
                        </button>
                        <button class="btn btn-outline-danger btn-sm"
                                onclick="openSvcDenyModal(<?= (int)$svc['id'] ?>)">
                            <i class="bi bi-x-lg me-1"></i>Deny
                        </button>
                    </div>
                    <?php endif; ?>

                    <!-- Denial reason -->
                    <?php if ($svc['status'] === 'denied' && !empty($svc['denial_reason'])): ?>
                    <div class="alert alert-danger py-2 mt-2 mb-0 small">
                        <i class="bi bi-info-circle me-1"></i>
                        Reason: <?= htmlspecialchars($svc['denial_reason']) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($svc['status'] !== 'pending'): ?>
                    <div class="mt-2 text-end">
                        <button class="btn btn-outline-secondary btn-sm"
                                onclick="archiveRequest(<?= (int)$svc['id'] ?>, this)">
                            <i class="bi bi-archive me-1"></i>Archive
                        </button>
                    </div>
                    <?php endif; ?>

                </div>
                <?php endforeach; ?>

</div><!-- /#tabServices -->

            <!-- ══════════════════════════════════════════
                 TAB 4 — FOOD ORDERS
            ══════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabFood" role="tabpanel">
                <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
                    <select class="form-select form-select-sm" id="foodStatusFilter" 
                            style="max-width:160px;" onchange="filterFood()">
                        <option value="all">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="approved-unpaid">Approved - Unpaid</option>
                        <option value="approved-paid">Approved - Paid</option>
                        <option value="denied">Denied</option>
                    </select>
                </div>

                <!-- Cost summary card -->
                <div class="svc-summary-card">
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Total Orders</div>
                        <div class="svc-sum-value"><?= count($foodAll) ?></div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Pending</div>
                        <div class="svc-sum-value"><?= $foodPendingCount ?></div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Pending Value</div>
                        <div class="svc-sum-value <?= $foodPendingTotal > 0 ? 'highlight' : '' ?>">
                            &#8369;<?= number_format($foodPendingTotal, 2) ?>
                        </div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Approved Total</div>
                        <div class="svc-sum-value highlight">&#8369;<?= number_format($foodApprovedTotal, 2) ?></div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Outstanding (Unpaid)</div>
                        <div class="svc-sum-value <?= $foodUnpaidTotal > 0 ? 'highlight' : '' ?>">
                            &#8369;<?= number_format($foodUnpaidTotal, 2) ?>
                        </div>
                    </div>
                </div>

                <?php if (empty($foodAll)): ?>
                    <p class="text-muted p-2">No food orders yet.</p>
                <?php endif; ?>

                <?php foreach ($foodAll as $food): ?>
                <div class="svc-card <?= htmlspecialchars($food['status']) ?>" data-request-id="<?= (int)$food['id'] ?>" 
                     data-food-status="<?= htmlspecialchars($food['status']) ?>" 
                     data-food-payment="<?= htmlspecialchars($food['payment_status']) ?>">

                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                            <span class="fw-bold">Room <?= htmlspecialchars($food['room_number']) ?></span>
                            <span class="badge bg-secondary ms-1" style="font-size:10px;">Food</span>
                            <span class="text-muted small ms-2">
                                <?= date('M j, Y g:ia', strtotime($food['created_at'])) ?>
                            </span>
                        </div>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <?php if ((float)$food['total_amount'] > 0): ?>
                            <span class="badge bg-light text-dark border">
                                &#8369;<?= number_format((float)$food['total_amount'], 2) ?>
                            </span>
                            <?php else: ?>
                            <span class="badge bg-light text-dark border">Free</span>
                            <?php endif; ?>

                            <?php if ($food['status'] === 'approved'): ?>
                                <span class="payment-badge badge <?= $food['payment_status'] === 'paid' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                    <?= ucfirst($food['payment_status']) ?>
                                </span>
                            <?php endif; ?>
                            <span class="status-badge badge <?php
                                echo match($food['status']) {
                                    'pending'  => 'bg-warning text-dark',
                                    'approved' => 'bg-success',
                                    'denied'   => 'bg-danger',
                                    default    => 'bg-secondary'
                                };
                            ?>">
                                <?= ucfirst($food['status']) ?>
                            </span>
                        </div>
                    </div>

                    <p class="mb-1 fw-semibold">
                        <i class="bi bi-egg-fried me-1 text-muted"></i>
                        <?= htmlspecialchars($food['item_summary']) ?>
                    </p>
                    <?php if (!empty($food['special_requests'])): ?>
                    <p class="mb-1 text-muted small">
                        <i class="bi bi-chat-quote me-1"></i>
                        <?= htmlspecialchars($food['special_requests']) ?>
                    </p>
                    <?php endif; ?>
                    <p class="mb-1 text-muted small">
                        Guest ID #<?= (int)$food['customer_id'] ?>
                        &nbsp;&bull;&nbsp; Front Desk payment
                    </p>

                    <!-- Pending: Approve + Deny -->
                    <?php if ($food['status'] === 'pending'): ?>
                    <div class="d-flex gap-2 flex-wrap mt-2">
                        <button class="btn btn-success btn-sm"
                                onclick="approveFoodOrder(<?= (int)$food['id'] ?>)">
                            <i class="bi bi-check-lg me-1"></i>Approve
                        </button>
                        <button class="btn btn-outline-danger btn-sm"
                                onclick="openFoodDenyModal(<?= (int)$food['id'] ?>)">
                            <i class="bi bi-x-lg me-1"></i>Deny
                        </button>
                    </div>
                    <?php endif; ?>

                    <!-- Denial reason -->
                    <?php if ($food['status'] === 'denied' && !empty($food['denial_reason'])): ?>
                    <div class="alert alert-danger py-2 mt-2 mb-0 small">
                        <i class="bi bi-info-circle me-1"></i>
                        Reason: <?= htmlspecialchars($food['denial_reason']) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($food['status'] !== 'pending'): ?>
                    <div class="mt-2 text-end">
                        <button class="btn btn-outline-secondary btn-sm"
                                onclick="archiveRequest(<?= (int)$food['id'] ?>, this)">
                            <i class="bi bi-archive me-1"></i>Archive
                        </button>
                    </div>
                    <?php endif; ?>

                </div>
                <?php endforeach; ?>

            </div><!-- /#tabFood -->

            <!-- ══════════════════════════════════════════
                 TAB 5 — BOOKINGS
            ══════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabBookings" role="tabpanel">
                <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
                    <select class="form-select form-select-sm" id="bookStatusFilter"
                            style="max-width:160px;" onchange="filterBookings()">
                        <option value="all">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="denied">Denied</option>
                    </select>
                </div>

                <!-- Summary card -->
                <div class="svc-summary-card">
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Total Bookings</div>
                        <div class="svc-sum-value"><?= count($bookAll) ?></div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Pending</div>
                        <div class="svc-sum-value <?= $bookPendingCount > 0 ? 'highlight' : '' ?>"><?= $bookPendingCount ?></div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Approved</div>
                        <div class="svc-sum-value"><?= count(array_filter($bookAll, fn($b) => $b['status'] === 'approved')) ?></div>
                    </div>
                    <div class="svc-sum-item">
                        <div class="svc-sum-label">Denied</div>
                        <div class="svc-sum-value"><?= count(array_filter($bookAll, fn($b) => $b['status'] === 'denied')) ?></div>
                    </div>
                </div>

                <?php if (empty($bookAll)): ?>
                    <p class="text-muted p-2">No booking requests yet.</p>
                <?php endif; ?>

                <?php foreach ($bookAll as $bk): ?>
                <div class="svc-card <?= htmlspecialchars($bk['status']) ?>"
                     data-booking-id="<?= (int)$bk['id'] ?>"
                     data-book-status="<?= htmlspecialchars($bk['status']) ?>">

                    <?php $isHourly = ($bk['booking_type'] === 'hourly'); ?>

                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                            <span class="fw-bold"><?= htmlspecialchars($bk['guest_name']) ?></span>
                            <span class="badge bg-secondary ms-1" style="font-size:10px;">
                                <?= htmlspecialchars($bk['room_type']) ?>
                            </span>
                            <?php if ($isHourly): ?>
                                <span class="badge ms-1" style="font-size:10px; background:#6f42c1;">
                                    <i class="bi bi-clock me-1"></i>Hourly
                                </span>
                            <?php endif; ?>
                            <span class="text-muted small ms-2">
                                <?= date('M j, Y g:ia', strtotime($bk['created_at'])) ?>
                            </span>
                        </div>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <?php if ($bk['room_number']): ?>
                                <span class="badge bg-info text-dark">Room <?= htmlspecialchars($bk['room_number']) ?></span>
                            <?php endif; ?>
                            <span class="status-badge badge <?php
                                echo match($bk['status']) {
                                    'pending'  => 'bg-warning text-dark',
                                    'approved' => 'bg-success',
                                    'denied'   => 'bg-danger',
                                    default    => 'bg-secondary'
                                };
                            ?>">
                                <?= ucfirst($bk['status']) ?>
                            </span>
                        </div>
                    </div>

                    <div class="row mb-2">
                        <?php if ($isHourly && $bk['check_in_time'] && $bk['check_out_time']): ?>
                        <div class="col-auto">
                            <span class="text-muted small">
                                <i class="bi bi-calendar-event me-1"></i>
                                <?= date('M j, Y', strtotime($bk['check_in'])) ?>
                            </span>
                        </div>
                        <div class="col-auto">
                            <span class="text-muted small">
                                <i class="bi bi-clock me-1"></i>
                                <?= date('g:i A', strtotime($bk['check_in_time'])) ?>
                                &mdash;
                                <?= date('g:i A', strtotime($bk['check_out_time'])) ?>
                                <?php if ((int)$bk['hours_requested'] > 0): ?>
                                    <span class="badge bg-light text-dark border ms-1" style="font-size:10px;">
                                        <?= (int)$bk['hours_requested'] ?>h
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php else: ?>
                        <div class="col-auto">
                            <span class="text-muted small"><i class="bi bi-calendar-event me-1"></i>
                                <?= date('M j, Y', strtotime($bk['check_in'])) ?> &mdash; <?= date('M j, Y', strtotime($bk['check_out'])) ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="col-auto">
                            <span class="text-muted small"><i class="bi bi-people me-1"></i><?= (int)$bk['persons'] ?> guest(s)</span>
                        </div>
                    </div>

                    <p class="mb-1 text-muted small">
                        <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($bk['guest_email']) ?>
                        <?php if ($bk['guest_phone']): ?>
                            &nbsp;&bull;&nbsp;<i class="bi bi-telephone me-1"></i><?= htmlspecialchars($bk['guest_phone']) ?>
                        <?php endif; ?>
                    </p>

                    <?php if (!empty($bk['special_requests'])): ?>
                        <p class="mb-2 text-muted small">
                            <i class="bi bi-chat-quote me-1"></i><?= htmlspecialchars($bk['special_requests']) ?>
                        </p>
                    <?php endif; ?>

                    <?php
                        // ── Reservation deposit state (phase16) ──────────────
                        $depStatus = (string)($bk['deposit_status'] ?? '');
                        if ($depStatus === '') $depStatus = 'unpaid';
                        $depMeta    = marle_deposit_status_meta($depStatus);
                        $depVerified = ($depStatus === 'verified');
                        // A deposit is only verified against a receipt the guest
                        // uploaded — the manager reads that proof and checks the
                        // bank/GCash statement, so no proof means no decision.
                        $depHasProof = trim((string)($bk['deposit_proof_path'] ?? '')) !== '';
                    ?>
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-2 mt-2">
                        <?php if (($bk['payment_method'] ?? '') === 'cash'): ?>
                            <?php /* phase26: taken across the desk — there is no receipt to upload,
                                     so the person who counted it is the verification. */ ?>
                            <span class="badge bg-dark" style="font-size:10px;">
                                <i class="bi bi-cash-coin me-1"></i>Cash at desk
                            </span>
                        <?php endif; ?>
                        <span class="badge <?= $depMeta['chip'] ?>" style="font-size:10px;">
                            <i class="bi bi-cash-stack me-1"></i><?= htmlspecialchars($depMeta['label']) ?>
                        </span>
                        <?php if (!empty($bk['deposit_amount'])): ?>
                            <span class="text-muted small">Deposit <?= marle_peso((float)$bk['deposit_amount']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($bk['deposit_reference'])): ?>
                            <span class="text-muted small">Ref <strong><?= htmlspecialchars((string)$bk['deposit_reference']) ?></strong></span>
                        <?php endif; ?>
                        <?php if (!empty($bk['deposit_submitted_at'])): ?>
                            <span class="text-muted small"><?= date('M j, g:ia', strtotime((string)$bk['deposit_submitted_at'])) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($bk['deposit_proof_path'])): ?>
                            <?php /* Served by a capability-gated script, not a public URL: a
                                     guest's GCash/bank receipt is private financial data. */ ?>
                            <a class="small" href="view_deposit_proof.php?booking_id=<?= (int)$bk['id'] ?>" target="_blank" rel="noopener">
                                <i class="bi bi-paperclip me-1"></i>View proof
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($bk['cancellation_fee_amount'])): ?>
                            <span class="badge bg-secondary" style="font-size:10px;">
                                Retained <?= marle_peso((float)$bk['cancellation_fee_amount']) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if (has_capability('verify_deposits') && !$depVerified && $depHasProof && $bk['status'] !== 'cancelled' && $bk['status'] !== 'denied'): ?>
                    <div class="d-flex gap-2 flex-wrap align-items-end mb-2">
                        <button type="button" class="btn btn-outline-success btn-sm"
                                onclick="submitDepositAction(<?= (int)$bk['id'] ?>, 'verify')">
                            <i class="bi bi-patch-check me-1"></i>Verify deposit
                        </button>
                        <input type="text" class="form-control form-control-sm" style="max-width:230px;"
                               id="depositReason<?= (int)$bk['id'] ?>" maxlength="255"
                               placeholder="Reason if rejecting…">
                        <button type="button" class="btn btn-outline-danger btn-sm"
                                onclick="submitDepositAction(<?= (int)$bk['id'] ?>, 'reject')">
                            <i class="bi bi-x-circle me-1"></i>Reject proof
                        </button>
                    </div>
                    <?php elseif (!$depVerified && $bk['status'] === 'pending'): ?>
                    <p class="small text-muted mb-2">
                        <i class="bi bi-lock me-1"></i>
                        <?php if ($depHasProof): ?>
                            The manager's deposit decision is pending on this booking.
                        <?php else: ?>
                            Waiting for the guest to upload their GCash or bank receipt — a manager verifies the
                            payment (against the bank/GCash statement) before this booking can be approved.
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>

                    <?php if ($bk['status'] === 'pending'): ?>
                    <div class="d-flex gap-2 flex-wrap align-items-center mt-2">
                        <?php if ($depVerified): ?>
                            <button class="btn btn-success btn-sm"
                                    onclick="openBookingApproveModal(<?= (int)$bk['id'] ?>)">
                                <i class="bi bi-check-lg me-1"></i>Approve
                            </button>
                        <?php else: ?>
                            <?php /* The deposit is the gate: process_booking_action.php
                                     refuses an approval without a verified deposit, so the
                                     button greys out and says why instead of letting the
                                     manager click into a refusal. Deny stays available —
                                     turning a booking down never needed a payment. */ ?>
                            <button class="btn btn-secondary btn-sm" disabled aria-disabled="true"
                                    style="opacity:.45;cursor:not-allowed;"
                                    title="Verify the guest's reservation deposit first — Approve unlocks after that.">
                                <i class="bi bi-lock me-1"></i>Approve
                            </button>
                            <span class="text-muted small">
                                <i class="bi bi-info-circle me-1"></i>Approve unlocks once the deposit is verified.
                            </span>
                        <?php endif; ?>
                        <button class="btn btn-outline-danger btn-sm"
                                onclick="openBookingDenyModal(<?= (int)$bk['id'] ?>)">
                            <i class="bi bi-x-lg me-1"></i>Deny
                        </button>
                    </div>
                    <?php endif; ?>
                    <?php
                        // Amending a held stay re-dates it, re-rooms it or corrects the
                        // guest count, and every one of those moves the total — so it
                        // re-quotes the deposit and the forfeiture before it writes and
                        // is a manager/owner action (amend_bookings). Cancelling forfeits
                        // money under the 50% policy, so it is gated the same way
                        // (cancel_bookings). Both buttons only render when the capability
                        // is held, and both endpoints refuse without it regardless.
                        //
                        // Once the stay is under way — the guest has checked in, or the
                        // check-in date/time has simply arrived — the server refuses both
                        // actions, and marle_booking_can_be_cancelled() is the one rule
                        // the amend and cancel endpoints share. Rather than offer a button
                        // that can only fail, the card swaps the pair for a read-only
                        // "View booking details" so the queue shows what the server does.
                        $canAmendBooking  = has_capability('amend_bookings');
                        $canCancelBooking = has_capability('cancel_bookings');
                        $bookingIsOpen    = in_array($bk['status'], ['pending', 'approved'], true);
                        $bookingChange    = $bookingIsOpen
                            ? marle_booking_can_be_cancelled($bk, false)
                            : ['allowed' => false, 'reason' => ''];
                        $bookingLocked    = $bookingIsOpen && !$bookingChange['allowed'];
                        $stayLabel = ($isHourly && $bk['check_in_time'] && $bk['check_out_time'])
                            ? date('M j, Y', strtotime($bk['check_in'])) . ', '
                              . date('g:i A', strtotime($bk['check_in_time'])) . ' – '
                              . date('g:i A', strtotime($bk['check_out_time']))
                            : date('M j, Y', strtotime($bk['check_in'])) . ' – '
                              . date('M j, Y', strtotime($bk['check_out']));
                    ?>
                    <?php if ($bookingLocked): ?>
                    <div class="mt-2">
                        <p class="small text-muted mb-2">
                            <i class="bi bi-lock me-1"></i><?= htmlspecialchars($bookingChange['reason']) ?>
                        </p>
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                onclick="openBookingDetails(this)"
                                data-ref="#<?= (int)$bk['id'] ?>"
                                data-guest="<?= htmlspecialchars((string)$bk['guest_name']) ?>"
                                data-email="<?= htmlspecialchars((string)$bk['guest_email']) ?>"
                                data-phone="<?= htmlspecialchars((string)($bk['guest_phone'] ?? '')) ?>"
                                data-room="<?= htmlspecialchars((string)($bk['room_number'] ?? '')) ?>"
                                data-room-type="<?= htmlspecialchars((string)$bk['room_type']) ?>"
                                data-stay="<?= htmlspecialchars($stayLabel) ?>"
                                data-persons="<?= (int)$bk['persons'] ?>"
                                data-status="<?= htmlspecialchars(ucfirst($bk['status'])) ?>"
                                data-deposit="<?= htmlspecialchars($depMeta['label']) ?>"
                                data-requests="<?= htmlspecialchars((string)($bk['special_requests'] ?? '')) ?>">
                            <i class="bi bi-eye me-1"></i>View booking details
                        </button>
                    </div>
                    <?php elseif (($canAmendBooking || $canCancelBooking) && $bookingIsOpen): ?>
                    <div class="mt-2 d-flex gap-2 flex-wrap">
                        <?php if ($canAmendBooking): ?>
                        <button class="btn btn-outline-primary btn-sm"
                                onclick="openBookingAmendModal(<?= (int)$bk['id'] ?>)">
                            <i class="bi bi-pencil-square me-1"></i>Amend booking
                        </button>
                        <?php endif; ?>
                        <?php if ($canCancelBooking): ?>
                        <button class="btn btn-outline-danger btn-sm"
                                onclick="openBookingCancelModal(<?= (int)$bk['id'] ?>)">
                            <i class="bi bi-x-circle me-1"></i>Cancel booking
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($bk['status'] === 'denied' && !empty($bk['denial_reason'])): ?>
                    <div class="alert alert-danger py-2 mt-2 mb-0 small">
                        <i class="bi bi-info-circle me-1"></i>
                        Reason: <?= htmlspecialchars($bk['denial_reason']) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($bk['status'] !== 'pending'): ?>
                    <div class="mt-2 text-end">
                        <button class="btn btn-outline-secondary btn-sm"
                                onclick="archiveBooking(<?= (int)$bk['id'] ?>, this)">
                            <i class="bi bi-archive me-1"></i>Archive
                        </button>
                    </div>
                    <?php endif; ?>

                </div>
                <?php endforeach; ?>

            </div><!-- /#tabBookings -->

            <!-- ══════════════════════════════════════════
                 TAB 6 — ARCHIVE
            ══════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tabArchive" role="tabpanel">
                <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
                    <select class="form-select form-select-sm" id="archiveTypeFilter"
                            style="max-width:160px;" onchange="loadArchive()">
                        <option value="all">All Types</option>
                        <option value="extend">Extensions</option>
                        <option value="service">Services</option>
                        <option value="food">Food</option>
                    </select>
                    <input type="date" id="archiveDateFrom" class="form-control form-control-sm" 
                           style="max-width:160px;" placeholder="From Date" onchange="loadArchive()">
                    <input type="date" id="archiveDateTo" class="form-control form-control-sm" 
                           style="max-width:160px;" placeholder="To Date" onchange="loadArchive()">
                    <button class="btn btn-outline-secondary btn-sm" onclick="clearArchiveFilters()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                    </button>
                    <span class="text-muted small">Auto-deleted 60 days after archiving</span>
                </div>
                <div id="archiveList">
                    <p class="text-muted p-2">Select a type to load archived records.</p>
                </div>
            </div><!-- /#tabArchive -->

        </div><!-- /.tab-content -->
    </div><!-- /.main-card -->


    <!-- Footer — one design for all three roles (includes/staff_footer.php) -->
    <?php $marleFooterTerms = true; require __DIR__ . '/../includes/staff_footer.php'; ?>

    <!-- ── EXTENSION DENY MODAL ── -->
    <div class="modal fade" id="denyModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Deny Extension Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-2">Please provide a reason. The guest will see this message.</p>
                    <textarea id="denyReason" class="form-control" rows="3"
                              placeholder="E.g. Room is already booked by another guest..."></textarea>
                    <div class="text-danger small mt-1 d-none" id="denyReasonError">A reason is required.</div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger" onclick="confirmDeny()">
                        <i class="bi bi-x-lg me-1"></i>Confirm Rejection
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── SERVICE DENY MODAL ★ NEW ── -->
    <div class="modal fade" id="svcDenyModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Deny Service Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-2">Please provide a reason. The guest will see this message.</p>
                    <textarea id="svcDenyReason" class="form-control" rows="3"
                              placeholder="E.g. Service not available at this time..."></textarea>
                    <div class="text-danger small mt-1 d-none" id="svcDenyReasonError">A reason is required.</div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger" onclick="confirmSvcDeny()">
                        <i class="bi bi-x-lg me-1"></i>Confirm Rejection
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── FOOD DENY MODAL ── -->
    <div class="modal fade" id="foodDenyModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Deny Food Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-2">Please provide a reason. The guest will see this message.</p>
                    <textarea id="foodDenyReason" class="form-control" rows="3"
                              placeholder="E.g. Item unavailable at this time..."></textarea>
                    <div class="text-danger small mt-1 d-none" id="foodDenyReasonError">A reason is required.</div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger" onclick="confirmFoodDeny()">
                        <i class="bi bi-x-lg me-1"></i>Confirm Rejection
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── BOOKING APPROVE MODAL ── -->
    <div class="modal fade" id="bookingApproveModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-check-circle text-success me-2"></i>Approve Booking</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Are you sure you want to approve this booking?</p>
                    <div id="bookingApproveError" class="alert alert-danger d-none"></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-success" onclick="confirmBookingApprove()" id="bookingApproveBtn">
                        <i class="bi bi-check-lg me-1"></i>Approve Booking
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── BOOKING DENY MODAL ── -->
    <div class="modal fade" id="bookingDenyModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-2"></i>Deny Booking</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-2">Please provide a reason. The guest will receive this via email.</p>
                    <textarea id="bookingDenyReason" class="form-control" rows="3"
                              placeholder="E.g. No rooms available for the requested dates..."></textarea>
                    <div class="text-danger small mt-1 d-none" id="bookingDenyReasonError">A reason is required.</div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger" onclick="confirmBookingDeny()">
                        <i class="bi bi-x-lg me-1"></i>Confirm Denial
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── BOOKING CANCEL MODAL (staff side — same money path as the guest modal) ── -->
    <div class="modal fade" id="bookingCancelModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:#933310; color:#fff;">
                    <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Cancel Booking</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="bookingCancelLoading" class="text-center py-3 text-muted small">
                        <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                        Working out what cancelling costs…
                    </div>
                    <div id="bookingCancelError" class="alert alert-danger py-2 small d-none"></div>

                    <div id="bookingCancelBody" class="d-none">
                        <div id="bookingCancelSummary" class="border rounded-3 p-3 mb-3 small" style="background:#fbf7f4;"></div>
                        <div id="bookingCancelBlocked" class="alert alert-warning py-2 small d-none"></div>

                        <div id="bookingCancelReasonBlock">
                            <div class="mb-2">
                                <label class="form-label small fw-semibold mb-1" for="bookingCancelReason">Reason (required)</label>
                                <select class="form-select form-select-sm" id="bookingCancelReason">
                                    <option value="">Choose a reason…</option>
                                    <option value="guest called to cancel">Guest called to cancel</option>
                                    <option value="guest emailed to cancel">Guest emailed to cancel</option>
                                    <option value="guest cancelled at the desk">Guest cancelled at the desk</option>
                                    <option value="guest did not confirm the deposit">Guest did not confirm the deposit</option>
                                    <option value="guest unreachable">Guest unreachable</option>
                                    <option value="duplicate booking">Duplicate booking</option>
                                    <option value="payment could not be completed">Payment could not be completed</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold mb-1" for="bookingCancelDetail">Details (optional)</label>
                                <input type="text" class="form-control form-control-sm" id="bookingCancelDetail" maxlength="160"
                                       placeholder="Anything the record should carry?">
                            </div>
                            <p class="small text-muted mb-0" id="bookingCancelNote"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Keep booking</button>
                    <button class="btn btn-danger d-none" onclick="confirmBookingCancel()" id="bookingCancelBtn">
                        <i class="bi bi-x-circle me-1"></i>Cancel this booking
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── BOOKING AMEND MODAL (staff side — re-quotes before it writes) ── -->
    <div class="modal fade" id="bookingAmendModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background:#933310; color:#fff;">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Amend Booking</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="bookingAmendLoading" class="text-center py-3 text-muted small">
                        <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                        Loading the booking…
                    </div>
                    <div id="bookingAmendError" class="alert alert-danger py-2 small d-none"></div>

                    <div id="bookingAmendBody" class="d-none">
                        <div id="bookingAmendSummary" class="border rounded-3 p-3 mb-3 small" style="background:#fbf7f4;"></div>
                        <div id="bookingAmendBlocked" class="alert alert-warning py-2 small d-none"></div>

                        <div id="bookingAmendForm">
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <label class="form-label small fw-semibold mb-1" for="bookingAmendCheckIn">Check-in</label>
                                    <input type="date" class="form-control form-control-sm" id="bookingAmendCheckIn">
                                </div>
                                <div class="col-sm-6" id="bookingAmendCheckOutWrap">
                                    <label class="form-label small fw-semibold mb-1" for="bookingAmendCheckOut">Check-out</label>
                                    <input type="date" class="form-control form-control-sm" id="bookingAmendCheckOut">
                                </div>
                                <div class="col-sm-6 d-none" id="bookingAmendTimeWrap">
                                    <label class="form-label small fw-semibold mb-1" for="bookingAmendTime">Start time</label>
                                    <input type="time" class="form-control form-control-sm" id="bookingAmendTime">
                                </div>
                                <div class="col-sm-6 d-none" id="bookingAmendHoursWrap">
                                    <label class="form-label small fw-semibold mb-1" for="bookingAmendHours">Hours</label>
                                    <input type="number" min="1" max="23" class="form-control form-control-sm" id="bookingAmendHours">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small fw-semibold mb-1" for="bookingAmendRoom">Room</label>
                                    <select class="form-select form-select-sm" id="bookingAmendRoom"></select>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small fw-semibold mb-1" for="bookingAmendPersons">Guests</label>
                                    <input type="number" min="1" max="20" class="form-control form-control-sm" id="bookingAmendPersons">
                                </div>
                            </div>
                            <p class="small text-muted mt-2 mb-0" id="bookingAmendHint"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Keep as is</button>
                    <button class="btn btn-primary d-none" onclick="confirmBookingAmend()" id="bookingAmendBtn">
                        <i class="bi bi-check-lg me-1"></i>Save changes
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- ── TERMS MODAL ── -->
    <div class="modal fade" id="termsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">Terms &amp; Conditions</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height:75vh; overflow-y:auto;">
                    <h5>1. Acceptance of Terms</h5>
                    <p>By accessing and using this system, you acknowledge that you have read, understood, and agreed to comply with all applicable policies, rules, and regulations governing system usage.</p>
                    <h5>2. User Responsibilities</h5>
                    <p>Users are responsible for maintaining the confidentiality of their account credentials and for all activities conducted under their account.</p>
                    <h5>3. Data Privacy and Protection</h5>
                    <p>All personal data collected will be handled in accordance with data protection laws and company privacy policies.</p>
                    <h5>4. System Security</h5>
                    <p>Unauthorized access, misuse, or tampering with system data is strictly prohibited.</p>
                    <h5>5. Limitation of Liability</h5>
                    <p>The company shall not be liable for any damages resulting from improper system use or unauthorized access.</p>
                    <h5>6. Amendments</h5>
                    <p>These terms may be updated at any time without prior notice.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
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
                <div class="modal-body text-center">
                    <p class="fs-5">Are you sure you want to log out?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" id="noBtn" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="../admin/requests.php" class="m-0">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn" id="yesBtn">Yes</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ── ADMIN TOAST ── -->
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:2000;">
        <div id="adminToast" class="toast align-items-center border-0 text-bg-success" role="alert">
            <div class="d-flex">
                <div class="toast-body fw-semibold" id="adminToastMsg"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto"
                        data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         CHAT JS
    ═══════════════════════════════════════════════════════════════ -->
    <script>
        let currentRoom    = null;
        let selectedFile   = null;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const roomAvatarPalette = [
            { bg: '#FAEEDA', text: '#633806' },
            { bg: '#E6F1FB', text: '#0C447C' },
            { bg: '#EAF3DE', text: '#27500A' },
            { bg: '#FAECE7', text: '#712B13' },
            { bg: '#EEEDFE', text: '#3C3489' }
        ];

        function hashRoom(room) {
            return String(room).split('').reduce((h, ch) => ((h << 5) - h + ch.charCodeAt(0)) | 0, 0);
        }

        function roomAvatarStyle(room) {
            const c = roomAvatarPalette[Math.abs(hashRoom(room)) % roomAvatarPalette.length];
            return `background:${c.bg}; color:${c.text};`;
        }

        function roomAvatarHtml(room) {
            return `<div class="room-avatar" style="${roomAvatarStyle(room)}">${escHtml(String(room))}</div>`;
        }

        function encodeBody(params) {
            return Object.entries({ ...params, csrf_token: csrfToken })
                .map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v))
                .join('&');
        }

        /* A chat stamp is MySQL's own wall clock — the hotel's local time — so its date
           and time parts are used exactly as written, and Today/Yesterday come from the
           server's clock (includes/server_clock.php), never from this device's. A phone
           whose clock was out used to label this morning's message "Yesterday", and a
           phone in another timezone shifted every time by hours. */
        function formatChatTime(datetimeStr) {
            if (!datetimeStr) return '';
            const c = window.MarleClock;
            if (!c) return String(datetimeStr);          // clock asset missing: show it raw rather than guess
            const parts = c.wallParts(datetimeStr);
            if (!parts) return '';
            const timeStr = c.formatWallTime(datetimeStr);
            if (parts.iso === c.dayIso(0))  return timeStr;
            if (parts.iso === c.dayIso(-1)) return 'Yesterday, ' + timeStr;
            return c.formatWallDate(datetimeStr, true) + ', ' + timeStr;
        }

        function updateRoomBadge(room, newStatus) {
            const item = document.querySelector(`.message-item[data-room="${CSS.escape(room)}"]`);
            if (!item) return;
            const badge = item.querySelector('.room-status-badge');
            if (!badge) return;
            const classMap = { new: 'new', in_progress: 'in-progress', complete: 'completed' };
            const labelMap = { new: 'New', in_progress: 'In Progress', complete: 'Complete' };
            badge.className = 'status room-status-badge ' + (classMap[newStatus] ?? 'new');
            badge.textContent = labelMap[newStatus] ?? 'New';
        }

        function openChat(room) {
            clearAttachment();
            currentRoom = room;
            document.getElementById('activeChatName').textContent  = 'Room ' + room;
            document.getElementById('activeChatRoom').textContent  = 'Room No. ' + room;
            document.getElementById('activeChatStatus').style.display = 'flex';
            const activeAvatar = document.getElementById('activeChatAvatar');
            activeAvatar.textContent = room;
            activeAvatar.setAttribute('style', roomAvatarStyle(room));
            document.querySelectorAll('.message-item').forEach(item => {
                item.classList.toggle('active', item.dataset.room === String(room));
            });
            fetch('chat_mark_open.php', {
                method : 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body   : 'room=' + encodeURIComponent(room) + '&csrf_token=' + encodeURIComponent(csrfToken)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    updateRoomBadge(room, data.status);
                    if (data.status === 'complete') {
                        disableChatInput();
                    } else {
                        enableChatInput();
                    }
                }
            })
            .catch(() => {});
            loadAdminChat(true);
        }

        function disableChatInput() {
            document.getElementById('chatClosedIndicator').style.display = 'block';
            document.getElementById('adminReplyInput').disabled = true;
            document.getElementById('attachBtn').style.opacity = '0.5';
            document.getElementById('attachBtn').style.cursor = 'not-allowed';
            document.getElementById('attachBtn').onclick = null;
            document.querySelector('.send').disabled = true;
            document.querySelector('.send').style.opacity = '0.5';
            document.querySelector('.send').style.cursor = 'not-allowed';
        }

        function enableChatInput() {
            document.getElementById('chatClosedIndicator').style.display = 'none';
            document.getElementById('adminReplyInput').disabled = false;
            document.getElementById('attachBtn').style.opacity = '1';
            document.getElementById('attachBtn').style.cursor = 'pointer';
            document.getElementById('attachBtn').onclick = function() { document.getElementById('attachmentInput').click(); };
            document.querySelector('.send').disabled = false;
            document.querySelector('.send').style.opacity = '1';
            document.querySelector('.send').style.cursor = 'pointer';
        }

        function checkChatStatus() {
            if (!currentRoom) return;
            fetch('chat_mark_open.php', {
                method : 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body   : 'room=' + encodeURIComponent(currentRoom) + '&csrf_token=' + encodeURIComponent(csrfToken)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.status === 'complete') {
                    disableChatInput();
                } else if (data.success && data.status !== 'complete') {
                    enableChatInput();
                }
            })
            .catch(() => {});
        }

        async function loadAdminChat(markRead) {
            if (!currentRoom) return;

            if (markRead) {
                await fetch('chat_mark_read.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'room=' + encodeURIComponent(currentRoom) + '&csrf_token=' + encodeURIComponent(csrfToken)
                });
            }

            var url = 'chat_fetch.php?room=' + encodeURIComponent(currentRoom);
            fetch(url)
                .then(r => r.json())
                .then(msgs => {
                    const box = document.getElementById('chatMessageBox');
                    box.innerHTML = '';
                    if (msgs.length === 0) {
                        box.innerHTML = '<p class="text-muted text-center mt-5" style="font-size:14px;">No messages yet.</p>';
                        return;
                    }
                    msgs.forEach(m => {
                        const sentLabel = formatChatTime(m.sent_at);
                        const greetingMsg = "Welcome! We're glad to have you with us. Please don't hesitate to message us if you need anything — including any questions about your bill.";
                        const isAutomatedGreeting = m.sender === 'admin' && m.message === greetingMsg;
                        
                        if (m.sender === 'guest') {
                            box.innerHTML += `
                                <div class="guest-request">
                                    <img src="../img/default.jpg" class="avatar">
                                    <div>
                                        <p class="guest-message mb-0">${escHtml(m.message)}</p>
                                        ${renderAttachment(m, true)}
                                        <div class="chat-ts guest-bubble">${sentLabel}</div>
                                    </div>
                                </div>`;
                        } else if (isAutomatedGreeting) {
                            // ★ Automated greeting message styling
                            box.innerHTML += `
                                <div class="automated-message">
                                    <div style="background:#f0f7ff; border-left:4px solid #0066ff; padding:12px 14px; border-radius:6px; margin:8px 0;">
                                        <div style="font-size:11px; font-weight:600; color:#0066ff; text-transform:uppercase; margin-bottom:4px; letter-spacing:0.5px;">
                                            ⚙ Automated Message
                                        </div>
                                        <p class="mb-0" style="color:#333; font-size:13px; line-height:1.5;">${escHtml(m.message)}</p>
                                        <div class="chat-ts" style="color:#0066ff; font-size:11px; margin-top:6px;">Sent ${sentLabel}</div>
                                    </div>
                                </div>`;
                        } else {
                            const checkClass = m.is_read == 1 ? 'read' : 'unread';
                            const checkIcon  = m.is_read == 1 ? '✓✓' : '✓';
                            const readLabel  = m.read_at ? '<span class="ts-read">Read ' + formatChatTime(m.read_at) + '</span>' : '';
                            box.innerHTML += `
                                <div class="reply">
                                    ${escHtml(m.message)}
                                    ${renderAttachment(m, false)}
                                    <span class="msg-checkmark ${checkClass}">${checkIcon}</span>
                                    <div class="chat-ts admin-bubble">Sent ${sentLabel}${readLabel}</div>
                                </div>`;
                        }
                    });
                    box.scrollTop = box.scrollHeight;
                })
                .catch(() => {});
        }

        function renderAttachment(m, isGuest) {
            if (!m.attachment_path) return '';
            const isImage = m.attachment_type && m.attachment_type.startsWith('image/');
            const cls     = isGuest ? 'guest-attach' : '';
            if (isImage) {
                return `<div class="attach-preview ${cls}">
                            <img src="../${escHtml(m.attachment_path)}"
                                 class="attach-thumb"
                                 alt="${escHtml(m.attachment_name)}"
                                 onclick="openChatImagePreview('../${escHtml(m.attachment_path)}')"
                                 style="cursor:pointer;">
                        </div>`;
            }
            const icon = fileIcon(m.attachment_type);
            return `<div class="attach-preview ${cls}">
                        <span>${icon}</span>
                        <a href="../${escHtml(m.attachment_path)}"
                           target="_blank" download="${escHtml(m.attachment_name)}">
                           ${escHtml(m.attachment_name)}
                        </a>
                    </div>`;
        }

        function fileIcon(mime) {
            if (!mime) return '📎';
            if (mime === 'application/pdf') return '📄';
            if (mime.includes('word'))      return '📝';
            if (mime.includes('sheet') || mime.includes('excel')) return '📊';
            return '📎';
        }

        function openChatImagePreview(src) {
            const overlay = document.getElementById('adminImagePreviewOverlay');
            const img     = document.getElementById('adminImagePreviewContent');
            img.src = src;
            overlay.style.display = 'flex';
        }

        function closeChatImagePreview() {
            document.getElementById('adminImagePreviewOverlay').style.display = 'none';
            document.getElementById('adminImagePreviewContent').src = '';
        }

        function handleFileSelect(input) {
            const errEl = document.getElementById('fileErrorMsg');
            errEl.style.display = 'none';
            if (!input.files || input.files.length === 0) { clearAttachment(); return; }
            const file     = input.files[0];
            const maxBytes = 5 * 1024 * 1024;
            const allowed  = ['image/jpeg','image/png','image/gif','application/pdf',
                               'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                               'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
            if (file.size > maxBytes) {
                errEl.textContent = LB.fileTooLarge;
                errEl.style.display = 'block';
                clearAttachment(); return;
            }
            if (!allowed.includes(file.type)) {
                errEl.textContent = '⚠ Invalid file type. Accepted: JPG, PNG, GIF, PDF, DOCX, XLSX.';
                errEl.style.display = 'block';
                clearAttachment(); return;
            }
            selectedFile = file;
            const bar   = document.getElementById('filePreviewBar');
            const name  = document.getElementById('filePreviewName');
            const thumb = document.getElementById('filePreviewThumb');
            const icon  = document.getElementById('filePreviewIcon');
            name.textContent = file.name;
            bar.style.display = 'flex';
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = e => { thumb.src = e.target.result; thumb.style.display = 'inline-block'; icon.textContent = ''; };
                reader.readAsDataURL(file);
            } else {
                thumb.style.display = 'none';
                icon.textContent    = fileIcon(file.type);
            }
        }

        function clearAttachment() {
            selectedFile = null;
            document.getElementById('attachmentInput').value = '';
            document.getElementById('filePreviewBar').style.display = 'none';
            document.getElementById('filePreviewThumb').style.display = 'none';
            document.getElementById('filePreviewThumb').src = '';
            document.getElementById('filePreviewName').textContent = '';
            document.getElementById('filePreviewIcon').textContent = '';
            document.getElementById('fileErrorMsg').style.display  = 'none';
        }

        function sendAdminReply() {
            if (!currentRoom) return alert('Please select a guest conversation first.');
            const input = document.getElementById('adminReplyInput');
            const msg   = input.value.trim();
            if (!msg && !selectedFile) return;
            const formData = new FormData();
            formData.append('room',    currentRoom);
            formData.append('message', msg);
            formData.append('csrf_token', csrfToken);
            if (selectedFile) formData.append('attachment', selectedFile);
            fetch('admin_chat_reply.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        input.value = '';
                        clearAttachment();
                        loadAdminChat(true);
                        const item = document.querySelector(`.message-item[data-room="${CSS.escape(currentRoom)}"]`);
                        if (item) {
                            const badge = item.querySelector('.room-status-badge');
                            if (badge && badge.classList.contains('new')) updateRoomBadge(currentRoom, 'in_progress');
                        }
                    } else {
                        const errEl = document.getElementById('fileErrorMsg');
                        errEl.textContent   = '⚠ ' + (data.error || 'Send failed.');
                        errEl.style.display = 'block';
                    }
                })
                .catch(() => {
                    const errEl = document.getElementById('fileErrorMsg');
                    errEl.textContent   = '⚠ Network error. Please try again.';
                    errEl.style.display = 'block';
                });
        }

        function legacyPaymentCollectionDisabled() {
            alert('Payment collection is now handled from Transactions.');
        }

        function refreshRoomRequests() {
            if (!currentRoom) return;
            fetch('refresh_room_requests.php?room_number=' + encodeURIComponent(currentRoom))
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        // Refresh extensions tab
                        updateExtensionsTab(data.extensions);
                        
                        // Refresh services tab
                        updateServicesTab(data.services);
                        
                        // Refresh food tab
                        updateFoodTab(data.food_orders);
                    }
                })
                .catch(() => {});
        }

        function updateExtensionsTab(extensions) {
            if (!extensions || extensions.length === 0) return;
            extensions.forEach(ext => {
                const card = document.querySelector(`.ext-card[data-request-id="${ext.id}"]`);
                if (card) {
                    card.dataset.extStatus = ext.status;
                    if (ext.hist_payment_status) card.dataset.extPayment = ext.hist_payment_status;
                    
                    const paymentEl = card.querySelector('.payment-badge');
                    if (paymentEl && ext.hist_payment_status) {
                        paymentEl.className = 'payment-badge badge';
                        if (ext.hist_payment_status === 'paid') {
                            paymentEl.classList.add('bg-success');
                            paymentEl.textContent = 'Paid';
                        } else if (ext.hist_payment_status === 'unpaid') {
                            paymentEl.classList.add('bg-warning');
                            paymentEl.textContent = 'Unpaid';
                        }
                    }
                    
                    // Hide mark paid button if now paid
                    const markPaidBtn = card.querySelector('button[onclick*="markPaid"]');
                    if (markPaidBtn && ext.hist_payment_status === 'paid') {
                        markPaidBtn.style.display = 'none';
                    }
                }
            });
        }

        function updateServicesTab(services) {
            if (!services || services.length === 0) return;
            services.forEach(svc => {
                const card = document.querySelector(`.svc-card[data-request-id="${svc.id}"]`);
                if (card) {
                    card.dataset.svcStatus = svc.status;
                    if (svc.payment_status) card.dataset.svcPayment = svc.payment_status;
                    
                    const paymentEl = card.querySelector('.payment-badge');
                    if (paymentEl && svc.payment_status) {
                        paymentEl.className = 'payment-badge badge';
                        if (svc.payment_status === 'paid') {
                            paymentEl.classList.add('bg-success');
                            paymentEl.textContent = 'Paid';
                        } else if (svc.payment_status === 'unpaid') {
                            paymentEl.classList.add('bg-warning');
                            paymentEl.textContent = 'Unpaid';
                        }
                    }
                    
                    // Hide mark paid button if now paid
                    const markPaidBtn = card.querySelector('button[onclick*="markSvcPaid"]');
                    if (markPaidBtn && svc.payment_status === 'paid') {
                        markPaidBtn.style.display = 'none';
                    }
                }
            });
        }

        function updateFoodTab(foodOrders) {
            if (!foodOrders || foodOrders.length === 0) return;
            foodOrders.forEach(food => {
                const card = document.querySelector(`.svc-card[data-request-id="${food.id}"]`);
                if (card) {
                    card.dataset.foodStatus = food.status;
                    if (food.payment_status) card.dataset.foodPayment = food.payment_status;
                    
                    const paymentEl = card.querySelector('.payment-badge');
                    if (paymentEl && food.payment_status) {
                        paymentEl.className = 'payment-badge badge';
                        if (food.payment_status === 'paid') {
                            paymentEl.classList.add('bg-success');
                            paymentEl.textContent = 'Paid';
                        } else if (food.payment_status === 'unpaid') {
                            paymentEl.classList.add('bg-warning');
                            paymentEl.textContent = 'Unpaid';
                        }
                    }
                    
                    // Hide mark paid button if now paid
                    const markPaidBtn = card.querySelector('button[onclick*="markFoodPaid"]');
                    if (markPaidBtn && food.payment_status === 'paid') {
                        markPaidBtn.style.display = 'none';
                    }
                }
            });
        }

        function escHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }

        function appendDenialReason(card, reason) {
            if (!reason || card.querySelector('.denial-reason-alert')) return;
            const reasonDiv = document.createElement('div');
            reasonDiv.className = 'denial-reason-alert alert alert-danger py-2 mt-2 mb-0 small';
            reasonDiv.innerHTML = `<i class="bi bi-info-circle me-1"></i>Reason: ${escHtml(reason)}`;
            card.appendChild(reasonDiv);
        }

        function appendArchiveButton(card, id, type) {
            if (card.querySelector('.archive-action-wrap')) return;
            const wrap = document.createElement('div');
            wrap.className = 'archive-action-wrap mt-2 text-end';
            const handler = type === 'booking' ? `archiveBooking(${id}, this)` : `archiveRequest(${id}, this)`;
            wrap.innerHTML = `<button class="btn btn-outline-secondary btn-sm" onclick="${handler}"><i class="bi bi-archive me-1"></i>Archive</button>`;
            card.appendChild(wrap);
        }

        clearAttachment();

        document.getElementById('adminReplyInput').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') sendAdminReply();
        });

        setInterval(() => {
            if (currentRoom && document.visibilityState === 'visible') {
                loadAdminChat(true);
                checkChatStatus();
            }
        }, 5000);

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && currentRoom) {
                loadAdminChat(true);
                checkChatStatus();
            }
        });

        let activeStatus = 'all';
        let searchTimer  = null;
        let initialRoomListHTML = document.getElementById('roomList').innerHTML;

        function setStatusFilter(btn) {
            document.querySelectorAll('.filter-chips .chip').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeStatus  = btn.dataset.status;
            runSearch();
        }

        document.getElementById('chatSearch').addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(runSearch, 280);
        });

        function runSearch() {
            const q = document.getElementById('chatSearch').value.trim();
            if (!q) {
                // Restore the initial room list when search is cleared
                reloadAllRooms();
                return;
            }
            fetch('chat_search.php?q=' + encodeURIComponent(q) + '&status=' + encodeURIComponent(activeStatus))
                .then(r => r.json())
                .then(renderRoomList)
                .catch(() => {});
        }

        function reloadAllRooms() {
            // Restore the initial room list HTML
            document.getElementById('roomList').innerHTML = initialRoomListHTML;
            // Re-apply status filter if needed
            if (activeStatus !== 'all') {
                document.querySelectorAll('.message-item').forEach(el => {
                    const statusMatch = (el.dataset.chatStatus || 'new') === activeStatus;
                    el.style.display = statusMatch ? '' : 'none';
                });
            }
        }

        function renderRoomList(rooms) {
            const list = document.getElementById('roomList');
            if (!rooms.length) {
                list.innerHTML = '<p class="text-muted p-3" style="font-size:14px;">No results found.</p>';
                return;
            }
            const statusClass = { new: 'new', in_progress: 'in-progress', complete: 'completed' };
            const statusLabel = { new: 'New', in_progress: 'In Progress', complete: 'Complete' };
            list.innerHTML = rooms.map(r => {
                const st  = r.chat_status || 'new';
                const unr = parseInt(r.unread) || 0;
                return `<div class="message-item"
                             data-room="${escHtml(r.room_number)}"
                             data-chat-status="${escHtml(st)}"
                             onclick="openChat('${escHtml(r.room_number)}')">
                            ${roomAvatarHtml(r.room_number)}
                            <div class="message-details">
                                <div class="top-row">
                                    <span class="guest-number">Room ${escHtml(r.room_number)}</span>
                                    <span class="status ${statusClass[st] || 'new'} room-status-badge">${statusLabel[st] || 'New'}</span>
                                    ${unr > 0 ? `<span class="badge bg-danger rounded-pill" style="font-size:10px;">${unr}</span>` : ''}
                                </div>
                                <div class="bottom-row">
                                    <p class="message-peek mb-0">${escHtml((r.last_msg || '').length > 40 ? (r.last_msg || '').slice(0, 40) + '...' : (r.last_msg || ''))}</p>
                                    <span class="time">${formatChatTime(r.last_time)}</span>
                                </div>
                            </div>
                        </div>`;
            }).join('');
        }
    </script>

    <!-- ── EXTENSION JS ── -->
    <script>
        let denyTargetId = null;
        let denyModal    = null;

        function getDenyModal() {
            if (!denyModal) denyModal = new bootstrap.Modal(document.getElementById('denyModal'));
            return denyModal;
        }

        function approveExt(requestId) {
            if (!confirm(LB.approveExtension)) return;
            doExtAction({ action: 'approve', request_id: requestId });
        }

        function openDenyModal(requestId) {
            denyTargetId = requestId;
            document.getElementById('denyReason').value = '';
            document.getElementById('denyReasonError').classList.add('d-none');
            getDenyModal().show();
        }

        function confirmDeny() {
            const reason = document.getElementById('denyReason').value.trim();
            if (!reason) { document.getElementById('denyReasonError').classList.remove('d-none'); return; }
            getDenyModal().hide();
            doExtAction({ action: 'deny', request_id: denyTargetId, reason: reason });
        }

        function doExtAction(params) {
            fetch('process_extend_action.php', {
                method : 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body   : encodeBody(params)
            })
            .then(r => r.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'danger');
                if (data.success) {
                    const card = document.querySelector(`.ext-card[data-request-id="${params.request_id}"]`);
                    if (card) {
                        card.classList.remove('pending', 'approved', 'denied');
                        const statusBadge = card.querySelector('.status-badge');
                        const actionWrap = card.querySelector('.d-flex.gap-2.flex-wrap.mt-2') ||
                                           card.querySelector('div.mt-2');
                        if (params.action === 'approve') {
                            card.classList.add('approved');
                            card.dataset.extStatus = 'approved';
                            if (statusBadge) {
                                statusBadge.className = 'status-badge badge bg-success';
                                statusBadge.textContent = 'Approved';
                            }
                            if (actionWrap) actionWrap.remove();
                            appendArchiveButton(card, params.request_id, 'request');
                        } else if (params.action === 'deny') {
                            card.classList.add('denied');
                            card.dataset.extStatus = 'denied';
                            if (statusBadge) {
                                statusBadge.className = 'status-badge badge bg-danger';
                                statusBadge.textContent = 'Denied';
                            }
                            if (actionWrap) actionWrap.remove();
                            appendDenialReason(card, params.reason);
                            appendArchiveButton(card, params.request_id, 'request');
                        } else if (params.action === 'mark_paid') {
                            card.classList.add('approved');
                            card.dataset.extPayment = 'paid';
                            const payBadge = card.querySelector('.payment-badge');
                            if (payBadge) {
                                payBadge.className = 'payment-badge badge bg-success';
                                payBadge.textContent = 'Paid';
                            }
                            if (actionWrap) actionWrap.remove();
                        }
                    }
                }
            })
            .catch(() => showToast('Network error. Please try again.', 'danger'));
        }

        function showToast(msg, type) {
            const el = document.getElementById('adminToast');
            el.className = 'toast align-items-center border-0 text-bg-' + type;
            document.getElementById('adminToastMsg').textContent = msg;
            new bootstrap.Toast(el, { delay: 3000 }).show();
        }
    </script>

    <!-- ── SERVICES JS ★ NEW ── -->
    <script>
        let svcDenyTargetId = null;
        let svcDenyModal    = null;

        function getSvcDenyModal() {
            if (!svcDenyModal) svcDenyModal = new bootstrap.Modal(document.getElementById('svcDenyModal'));
            return svcDenyModal;
        }

        function approveSvc(requestId) {
            if (!confirm(LB.approveService)) return;
            doSvcAction({ action: 'approve', request_id: requestId });
        }

        function openSvcDenyModal(requestId) {
            svcDenyTargetId = requestId;
            document.getElementById('svcDenyReason').value = '';
            document.getElementById('svcDenyReasonError').classList.add('d-none');
            getSvcDenyModal().show();
        }

        function confirmSvcDeny() {
            const reason = document.getElementById('svcDenyReason').value.trim();
            if (!reason) {
                document.getElementById('svcDenyReasonError').classList.remove('d-none');
                return;
            }
            getSvcDenyModal().hide();
            doSvcAction({ action: 'deny', request_id: svcDenyTargetId, reason: reason });
        }

        function doSvcAction(params) {
            fetch('process_service_action.php', {
                method : 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body   : encodeBody(params)
            })
            .then(r => {
                const ct = r.headers.get('Content-Type') || '';
                if (ct.includes('application/json')) {
                    return r.json().then(data => {
                        if (!r.ok) throw data; // server returned JSON with error
                        return data;
                    });
                }
                throw { message: 'Invalid server response', success: false };
            })
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'danger');
                if (data.success) {
                    const card = document.querySelector(`.svc-card[data-request-id="${params.request_id}"]`);
                    if (card) {
                        card.classList.remove('pending', 'approved', 'denied');
                        const statusBadge = card.querySelector('.status-badge');
                        const actionWrap = card.querySelector('.d-flex.gap-2.flex-wrap.mt-2') ||
                                           card.querySelector('div.mt-2');
                        if (params.action === 'approve') {
                            card.classList.add('approved');
                            card.dataset.svcStatus = 'approved';
                            if (statusBadge) {
                                statusBadge.className = 'status-badge badge bg-success';
                                statusBadge.textContent = 'Approved';
                            }
                            if (actionWrap) actionWrap.remove();
                            appendArchiveButton(card, params.request_id, 'request');
                        } else if (params.action === 'deny') {
                            card.classList.add('denied');
                            card.dataset.svcStatus = 'denied';
                            if (statusBadge) {
                                statusBadge.className = 'status-badge badge bg-danger';
                                statusBadge.textContent = 'Denied';
                            }
                            if (actionWrap) actionWrap.remove();
                            appendDenialReason(card, params.reason);
                            appendArchiveButton(card, params.request_id, 'request');
                        } else if (params.action === 'mark_paid') {
                            card.classList.add('approved');
                            card.dataset.svcPayment = 'paid';
                            const payBadge = card.querySelector('.payment-badge');
                            if (payBadge) {
                                payBadge.className = 'payment-badge badge bg-success';
                                payBadge.textContent = 'Paid';
                            }
                            if (actionWrap) actionWrap.remove();
                        }
                    }
                }
            })
            .catch(err => {
                const msg = (err && err.message) ? err.message : 'Network error. Please try again.';
                const type = (err && typeof err.success !== 'undefined') ? (err.success ? 'success' : 'danger') : 'danger';
                showToast(msg, type);
            });
        }
    </script>

<!-- ── FOOD JS ── -->
    <script>
        let foodDenyTargetId = null;
        let foodDenyModal    = null;

        function getFoodDenyModal() {
            if (!foodDenyModal) foodDenyModal = new bootstrap.Modal(document.getElementById('foodDenyModal'));
            return foodDenyModal;
        }

        function approveFoodOrder(requestId) {
            if (!confirm(LB.approveFood)) return;
            doFoodAction({ action: 'approve', request_id: requestId });
        }

        function openFoodDenyModal(requestId) {
            foodDenyTargetId = requestId;
            document.getElementById('foodDenyReason').value = '';
            document.getElementById('foodDenyReasonError').classList.add('d-none');
            getFoodDenyModal().show();
        }

        function confirmFoodDeny() {
            const reason = document.getElementById('foodDenyReason').value.trim();
            if (!reason) {
                document.getElementById('foodDenyReasonError').classList.remove('d-none');
                return;
            }
            getFoodDenyModal().hide();
            doFoodAction({ action: 'deny', request_id: foodDenyTargetId, reason: reason });
        }

        function doFoodAction(params) {
            fetch('process_food_action.php', {
                method : 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body   : encodeBody(params)
            })
            .then(r => r.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'danger');
                if (data.success) {
                    const card = document.querySelector(`.svc-card[data-request-id="${params.request_id}"]`);
                    if (card) {
                        card.classList.remove('pending', 'approved', 'denied');
                        const statusBadge = card.querySelector('.status-badge');
                        const actionWrap = card.querySelector('.d-flex.gap-2.flex-wrap.mt-2') ||
                                           card.querySelector('div.mt-2');
                        if (params.action === 'approve') {
                            card.classList.add('approved');
                            card.dataset.foodStatus = 'approved';
                            if (statusBadge) {
                                statusBadge.className = 'status-badge badge bg-success';
                                statusBadge.textContent = 'Approved';
                            }
                            if (actionWrap) actionWrap.remove();
                            appendArchiveButton(card, params.request_id, 'request');
                        } else if (params.action === 'deny') {
                            card.classList.add('denied');
                            card.dataset.foodStatus = 'denied';
                            if (statusBadge) {
                                statusBadge.className = 'status-badge badge bg-danger';
                                statusBadge.textContent = 'Denied';
                            }
                            if (actionWrap) actionWrap.remove();
                            appendDenialReason(card, params.reason);
                            appendArchiveButton(card, params.request_id, 'request');
                        } else if (params.action === 'mark_paid') {
                            card.classList.add('approved');
                            card.dataset.foodPayment = 'paid';
                            const payBadge = card.querySelector('.payment-badge');
                            if (payBadge) {
                                payBadge.className = 'payment-badge badge bg-success';
                                payBadge.textContent = 'Paid';
                            }
                            if (actionWrap) actionWrap.remove();
                        }
                    }
                }
            })
            .catch(() => showToast('Network error. Please try again.', 'danger'));
        }
    </script>

    <!-- ── ARCHIVE JS ── -->
    <script>
        function archiveRequest(requestId, btn) {
            if (!confirm(LB.archiveRequest)) return;
            btn.disabled = true;
            fetch('process_archive.php', {
                method : 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body   : encodeBody({ action: 'archive', request_id: requestId })
            })
            .then(r => r.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'danger');
                if (data.success) {
                    const card = btn.closest('.ext-card, .svc-card');
                    if (card) {
                        card.style.opacity    = '0';
                        card.style.transition = 'opacity 0.3s';
                        setTimeout(() => card.remove(), 300);
                    }
                } else {
                    btn.disabled = false;
                }
            })
            .catch(() => {
                showToast('Network error.', 'danger');
                btn.disabled = false;
            });
        }

        function restoreRequest(id, el) {
            fetch('process_archive.php', {
                method : 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body   : encodeBody({ action: 'restore', request_id: id })
            })
            .then(r => r.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'danger');
                if (data.success && el) el.remove();
            })
            .catch(() => showToast('Network error.', 'danger'));
        }

        /* Reservation deposit: manager verify / reject (phase16) */
        function submitDepositAction(bookingId, action) {
            const reasonEl = document.getElementById('depositReason' + bookingId);
            const reason = reasonEl ? reasonEl.value.trim() : '';

            if (action === 'reject' && reason === '') {
                alert('Tell the guest why the proof of payment was rejected.');
                if (reasonEl) reasonEl.focus();
                return;
            }
            if (action === 'verify' && !confirm('Verify this reservation deposit? The booking can then be approved.')) {
                return;
            }

            const fd = new FormData();
            fd.append('booking_id', bookingId);
            fd.append('deposit_action', action);
            fd.append('reason', reason);
            fd.append('csrf_token', csrfToken);

            fetch('process_deposit_action.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    alert(d.message || (d.success ? LB.saved : LB.saveFailed));
                    if (d.success) location.reload();
                })
                .catch(() => alert(LB.unreachable));
        }

        function archiveBooking(bookingId, btn) {
            if (!confirm(LB.archiveBooking)) return;
            btn.disabled = true;
            fetch('process_archive_booking.php', {
                method : 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body   : encodeBody({ action: 'archive', booking_id: bookingId })
            })
            .then(r => r.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'danger');
                if (data.success) {
                    const card = btn.closest('.svc-card');
                    if (card) {
                        card.style.opacity = '0';
                        card.style.transition = 'opacity 0.3s';
                        setTimeout(() => card.remove(), 300);
                    }
                } else {
                    btn.disabled = false;
                }
            })
            .catch(() => {
                showToast('Network error.', 'danger');
                btn.disabled = false;
            });
        }

        function clearArchiveFilters() {
            document.getElementById('archiveDateFrom').value = '';
            document.getElementById('archiveDateTo').value = '';
            loadArchive();
        }

        function loadArchive() {
            const type = document.getElementById('archiveTypeFilter').value;
            const dateFrom = document.getElementById('archiveDateFrom').value;
            const dateTo = document.getElementById('archiveDateTo').value;
            const list = document.getElementById('archiveList');
            list.innerHTML = '<p class="text-muted p-2">Loading…</p>';
            
            let url = 'fetch_archived_request.php?type=' + encodeURIComponent(type);
            if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
            if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);
            
            fetch(url)
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !data.requests.length) {
                        list.innerHTML = '<p class="text-muted p-2">No archived records found.</p>';
                        return;
                    }
                    list.innerHTML = data.requests.map(rec => `
                        <div class="ext-card" style="border-left:4px solid #6c757d;" id="arc-${rec.id}">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <span class="fw-bold">Room ${escHtml(rec.room_number)}</span>
                                    <span class="badge bg-secondary ms-1">${escHtml(rec.type)}</span>
                                    <span class="text-muted small ms-2">${escHtml(rec.created_at)}</span>
                                </div>
                                <div class="d-flex gap-2 align-items-center">
                                    <span class="badge ${rec.status === 'approved' ? 'bg-success' : 'bg-danger'}">${escHtml(rec.status)}</span>
                                    <button class="btn btn-outline-primary btn-sm"
                                            onclick="restoreRequest(${rec.id}, document.getElementById('arc-${rec.id}'))">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                                    </button>
                                </div>
                            </div>
                            <p class="text-muted small mt-1 mb-0">Archived: ${escHtml(rec.archived_at || '—')}</p>
                        </div>`).join('');
                })
                .catch(() => {
                    list.innerHTML = '<p class="text-danger p-2">Failed to load.</p>';
                });
        }

        let archiveLoaded = false;
        document.getElementById('archive-tab')?.addEventListener('shown.bs.tab', function () {
            if (!archiveLoaded) {
                loadArchive();
                archiveLoaded = true;
            }
        });
    </script>

    <!-- ── STATUS FILTERS JS ── -->
    <script>
        function filterExtensions() {
            const statusFilter = document.getElementById('extStatusFilter').value;
            document.querySelectorAll('#tabExtensions .ext-card').forEach(card => {
                const cardStatus = card.dataset.extStatus;
                if (statusFilter === 'all' || cardStatus === statusFilter) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function filterServices() {
            const statusFilter = document.getElementById('svcStatusFilter').value;
            document.querySelectorAll('#tabServices .svc-card').forEach(card => {
                const status = card.dataset.svcStatus;
                const payment = card.dataset.svcPayment;
                let show = false;
                
                if (statusFilter === 'all') {
                    show = true;
                } else if (statusFilter === 'pending' && status === 'pending') {
                    show = true;
                } else if (statusFilter === 'approved-unpaid' && status === 'approved' && payment === 'unpaid') {
                    show = true;
                } else if (statusFilter === 'approved-paid' && status === 'approved' && payment === 'paid') {
                    show = true;
                } else if (statusFilter === 'denied' && status === 'denied') {
                    show = true;
                }
                
                card.style.display = show ? '' : 'none';
            });
        }

        function filterFood() {
            const statusFilter = document.getElementById('foodStatusFilter').value;
            document.querySelectorAll('#tabFood .svc-card').forEach(card => {
                const status = card.dataset.foodStatus;
                const payment = card.dataset.foodPayment;
                let show = false;
                
                if (statusFilter === 'all') {
                    show = true;
                } else if (statusFilter === 'pending' && status === 'pending') {
                    show = true;
                } else if (statusFilter === 'approved-unpaid' && status === 'approved' && payment === 'unpaid') {
                    show = true;
                } else if (statusFilter === 'approved-paid' && status === 'approved' && payment === 'paid') {
                    show = true;
                } else if (statusFilter === 'denied' && status === 'denied') {
                    show = true;
                }
                
                card.style.display = show ? '' : 'none';
            });
        }
    </script>

<div id="adminImagePreviewOverlay"
     style="display:none; position:fixed; inset:0; z-index:3000; background:rgba(0,0,0,0.85); align-items:center; justify-content:center; padding:18px;"
     onclick="if (event.target === this) closeChatImagePreview()">
    <div style="position:relative; max-width:100%; max-height:100%; width:100%; max-width:720px;">
        <button type="button" onclick="closeChatImagePreview()"
                style="position:absolute; top:10px; right:10px; background:rgba(255,255,255,0.95); border:none; border-radius:50%; width:34px; height:34px; font-size:18px; cursor:pointer;">
            &times;
        </button>
        <img id="adminImagePreviewContent" src="" alt="Image preview"
             style="display:block; max-width:100%; max-height:100vh; margin:0 auto; border-radius:14px; box-shadow:0 10px 30px rgba(0,0,0,0.35);" />
    </div>
</div>

    <!-- ── BOOKINGS JS ── -->
    <script>
        let bookingApproveTargetId = null;
        let bookingDenyTargetId    = null;
        let bookingApproveModal    = null;
        let bookingDenyModal       = null;

        function getBookingApproveModal() {
            if (!bookingApproveModal) bookingApproveModal = new bootstrap.Modal(document.getElementById('bookingApproveModal'));
            return bookingApproveModal;
        }
        function getBookingDenyModal() {
            if (!bookingDenyModal) bookingDenyModal = new bootstrap.Modal(document.getElementById('bookingDenyModal'));
            return bookingDenyModal;
        }

        function openBookingApproveModal(bookingId) {
            bookingApproveTargetId = bookingId;
            getBookingApproveModal().show();
        }

        function confirmBookingApprove() {
            getBookingApproveModal().hide();
            doBookingAction({ 
                action: 'approve', 
                booking_id: bookingApproveTargetId
            });
        }

        function openBookingDenyModal(bookingId) {
            bookingDenyTargetId = bookingId;
            document.getElementById('bookingDenyReason').value = '';
            document.getElementById('bookingDenyReasonError').classList.add('d-none');
            getBookingDenyModal().show();
        }

        function confirmBookingDeny() {
            const reason = document.getElementById('bookingDenyReason').value.trim();
            if (!reason) {
                document.getElementById('bookingDenyReasonError').classList.remove('d-none');
                return;
            }
            getBookingDenyModal().hide();
            doBookingAction({ action: 'deny', booking_id: bookingDenyTargetId, reason: reason });
        }

        /* ── Cancel booking (manager/owner) ─────────────────────────────────────
           process_cancel_booking.php answers with the same forfeiture the guest
           modal quotes — one money path (includes/booking_cancel.php), three front
           doors. Nothing is written until the reason is chosen and confirmed. */
        let bookingCancelTargetId = null;
        let bookingCancelModal    = null;

        function getBookingCancelModal() {
            if (!bookingCancelModal) bookingCancelModal = new bootstrap.Modal(document.getElementById('bookingCancelModal'));
            return bookingCancelModal;
        }

        function bookingCancelRow(label, value, danger) {
            const line = document.createElement('div');
            line.className = 'd-flex justify-content-between gap-2 py-1';
            const l = document.createElement('span');
            l.className = 'text-muted';
            l.textContent = label;
            const v = document.createElement('span');
            v.className = danger ? 'fw-semibold text-end text-danger' : 'fw-semibold text-end';
            v.textContent = value;
            line.appendChild(l);
            line.appendChild(v);
            return line;
        }

        function bookingCancelDate(value) {
            if (!value) return '—';
            const d = new Date(value + 'T00:00:00');
            return isNaN(d) ? value : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function bookingCancelError(message) {
            const box = document.getElementById('bookingCancelError');
            document.getElementById('bookingCancelLoading').classList.add('d-none');
            document.getElementById('bookingCancelBody').classList.add('d-none');
            if (!message) { box.classList.add('d-none'); box.textContent = ''; return; }
            box.textContent = message;
            box.classList.remove('d-none');
        }

        function openBookingCancelModal(bookingId) {
            bookingCancelTargetId = bookingId;
            bookingCancelError('');
            document.getElementById('bookingCancelReason').value = '';
            document.getElementById('bookingCancelDetail').value = '';
            document.getElementById('bookingCancelBtn').disabled = false;
            document.getElementById('bookingCancelBtn').classList.add('d-none');
            document.getElementById('bookingCancelBlocked').classList.add('d-none');
            document.getElementById('bookingCancelLoading').classList.remove('d-none');
            getBookingCancelModal().show();

            fetch('process_cancel_booking.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: encodeBody({ mode: 'preview', booking_id: bookingId })
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) { bookingCancelError(data.message || LB.loadFailed); return; }
                renderBookingCancelPreview(data);
            })
            .catch(() => bookingCancelError(LB.unreachable));
        }

        function renderBookingCancelPreview(data) {
            document.getElementById('bookingCancelLoading').classList.add('d-none');
            const b = data.booking || {};
            const p = data.preview || {};

            const stay = b.booking_type === 'hourly'
                ? bookingCancelDate(b.check_in) + ' · ' + (b.hours_requested || 0) + ' hour(s)'
                : bookingCancelDate(b.check_in) + ' → ' + bookingCancelDate(b.check_out);

            const box = document.getElementById('bookingCancelSummary');
            box.textContent = '';
            box.appendChild(bookingCancelRow('Booking no.', '#' + b.id));
            box.appendChild(bookingCancelRow('Guest', (b.guest_name || '') + (b.guest_phone ? ' · ' + b.guest_phone : '')));
            box.appendChild(bookingCancelRow('Room', (b.room_type || '') + (b.room_number ? ' · ' + b.room_number : '')));
            box.appendChild(bookingCancelRow('Stay', stay));
            const hr = document.createElement('hr');
            hr.className = 'my-2';
            box.appendChild(hr);
            box.appendChild(bookingCancelRow('Booking total', p.total));
            box.appendChild(bookingCancelRow('Non-refundable (' + (p.percent || 0) + '%)', p.fee, true));
            box.appendChild(bookingCancelRow('Deposit on record', p.deposit));

            const deposit = Number(p.deposit_amount) || 0;
            const balance = Number(p.balance_amount) || 0;
            const refund  = Number(p.refund_amount) || 0;

            let note;
            if (balance > 0) {
                box.appendChild(bookingCancelRow('Still to collect at the desk', p.balance, true));
                note = 'The guest will be emailed this breakdown and told the ' + p.balance + ' balance can be settled at the desk.';
            } else if (refund > 0) {
                box.appendChild(bookingCancelRow('Refund due to the guest', p.refund, true));
                note = 'The guest will be emailed this breakdown; ' + p.refund + ' is refundable to them.';
            } else if (deposit > 0) {
                box.appendChild(bookingCancelRow('Covered by the deposit', p.deposit));
                note = 'The guest will be emailed this breakdown. Nothing is owed either way.';
            } else {
                note = 'No deposit is on record for this booking — nothing is refunded, and the guest is told so.';
            }
            document.getElementById('bookingCancelNote').textContent = note;

            const blocked  = document.getElementById('bookingCancelBlocked');
            const cancelBtn = document.getElementById('bookingCancelBtn');
            if (data.can_cancel) {
                blocked.classList.add('d-none');
                document.getElementById('bookingCancelReasonBlock').classList.remove('d-none');
                cancelBtn.classList.remove('d-none');
            } else {
                blocked.textContent = data.reason || 'This booking cannot be cancelled.';
                blocked.classList.remove('d-none');
                document.getElementById('bookingCancelReasonBlock').classList.add('d-none');
                cancelBtn.classList.add('d-none');
            }
            document.getElementById('bookingCancelBody').classList.remove('d-none');
        }

        function confirmBookingCancel() {
            const reason = document.getElementById('bookingCancelReason').value;
            const error  = document.getElementById('bookingCancelError');
            if (!reason) {
                error.textContent = 'Choose a reason for the cancellation.';
                error.classList.remove('d-none');
                return;
            }
            const btn = document.getElementById('bookingCancelBtn');
            btn.disabled = true;

            fetch('process_cancel_booking.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: encodeBody({
                    mode: 'cancel',
                    booking_id: bookingCancelTargetId,
                    cancel_reason: reason,
                    cancel_reason_detail: document.getElementById('bookingCancelDetail').value.trim()
                })
            })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                if (!data.success) {
                    error.textContent = data.message || LB.cancelFailed;
                    error.classList.remove('d-none');
                    return;
                }
                getBookingCancelModal().hide();
                showToast(data.message, 'success');
                // The card is server-rendered, so refresh — through the poller's own
                // path when it is present, which brings the tab and scroll back.
                setTimeout(function () {
                    if (window.marleBookingsWatch && window.marleBookingsWatch.reload) {
                        window.marleBookingsWatch.reload();
                    } else {
                        window.location.reload();
                    }
                }, 900);
            })
            .catch(() => {
                btn.disabled = false;
                error.textContent = LB.unreachable;
                error.classList.remove('d-none');
            });
        }

        /* ── Amend booking (manager/owner) ──────────────────────────────────────
           process_amend_booking.php re-quotes every time: the total, the deposit
           difference and the new forfeiture come from the same helper the write
           uses, so what the manager approves is what gets recorded. Availability
           is re-checked again on save, under an advisory lock. */
        let bookingAmendTargetId = null;
        let bookingAmendModal    = null;
        let bookingAmendType     = 'daily';
        let bookingAmendBooking  = null;
        let bookingAmendTimer    = null;

        function getBookingAmendModal() {
            if (!bookingAmendModal) bookingAmendModal = new bootstrap.Modal(document.getElementById('bookingAmendModal'));
            return bookingAmendModal;
        }

        function marleAmendPeso(value) {
            const n = Number(value) || 0;
            return '\u20B1' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function marleAmendDate(value) {
            if (!value) return '—';
            const d = new Date(value + 'T00:00:00');
            return isNaN(d) ? value : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function bookingAmendError(message) {
            const box = document.getElementById('bookingAmendError');
            document.getElementById('bookingAmendLoading').classList.add('d-none');
            document.getElementById('bookingAmendBody').classList.add('d-none');
            if (!message) { box.classList.add('d-none'); box.textContent = ''; return; }
            box.textContent = message;
            box.classList.remove('d-none');
        }

        function bookingAmendParams() {
            const params = {
                booking_id: bookingAmendTargetId,
                room_number: document.getElementById('bookingAmendRoom').value,
                persons: document.getElementById('bookingAmendPersons').value,
                check_in: document.getElementById('bookingAmendCheckIn').value,
                check_out: document.getElementById('bookingAmendCheckOut').value
            };
            if (bookingAmendType === 'hourly') {
                params.check_in_time = document.getElementById('bookingAmendTime').value;
                params.hours_requested = document.getElementById('bookingAmendHours').value;
            }
            return params;
        }

        function bookingAmendSetRooms(rooms, selected) {
            const sel = document.getElementById('bookingAmendRoom');
            sel.textContent = '';
            let found = false;
            (rooms || []).forEach(function (room) {
                const opt = document.createElement('option');
                opt.value = room.room_number;
                opt.textContent = 'Room ' + room.room_number + (room.max_persons ? ' (max ' + room.max_persons + ')' : '');
                if (String(room.room_number) === String(selected)) { opt.selected = true; found = true; }
                sel.appendChild(opt);
            });
            if (!found && selected) {
                const opt = document.createElement('option');
                opt.value = selected;
                opt.textContent = 'Room ' + selected + ' — unavailable for these dates';
                opt.selected = true;
                opt.disabled = true;
                sel.insertBefore(opt, sel.firstChild);
            }
        }

        function renderBookingAmendSummary(booking, q) {
            const box = document.getElementById('bookingAmendSummary');
            box.textContent = '';
            const isHourly = booking.booking_type === 'hourly';
            const oldStay = isHourly
                ? marleAmendDate(booking.check_in) + ' · ' + (booking.hours_requested || 0) + ' hour(s)'
                : marleAmendDate(booking.check_in) + ' → ' + marleAmendDate(booking.check_out);
            const newStay = isHourly
                ? marleAmendDate(q.check_in) + ' · ' + (q.hours || 0) + ' hour(s)'
                : marleAmendDate(q.check_in) + ' → ' + marleAmendDate(q.check_out);

            box.appendChild(bookingCancelRow('Booking no.', '#' + booking.id));
            box.appendChild(bookingCancelRow('Guest', (booking.guest_name || '') + (booking.guest_phone ? ' · ' + booking.guest_phone : '')));
            box.appendChild(bookingCancelRow('Currently', (booking.room_type || '') + (booking.room_number ? ' · Room ' + booking.room_number : '') + ' — ' + oldStay));

            const hr = document.createElement('hr');
            hr.className = 'my-2';
            box.appendChild(hr);

            box.appendChild(bookingCancelRow('New dates', newStay));
            box.appendChild(bookingCancelRow('New room', (booking.room_type || '') + ' · Room ' + q.room_number));
            box.appendChild(bookingCancelRow('Guests', String(q.persons)));

            const delta = Number(q.total_delta) || 0;
            const deltaText = delta === 0 ? '' : (delta > 0
                ? ' (+' + marleAmendPeso(delta) + ')'
                : ' (−' + marleAmendPeso(Math.abs(delta)) + ')');
            box.appendChild(bookingCancelRow('Booking total',
                marleAmendPeso(q.old_total) + ' → ' + marleAmendPeso(q.new_total) + deltaText, delta !== 0));

            box.appendChild(bookingCancelRow('Non-refundable (' + (q.percent || 0) + '%)', marleAmendPeso(q.new_fee), true));
            box.appendChild(bookingCancelRow('Deposit required', marleAmendPeso(q.new_deposit)));
            if (Number(q.deposit_paid) > 0) {
                box.appendChild(bookingCancelRow('Deposit on record', marleAmendPeso(q.deposit_paid)));
            }
            const depDelta = Number(q.deposit_delta) || 0;
            if (depDelta > 0) {
                box.appendChild(bookingCancelRow('Collect more deposit', marleAmendPeso(depDelta), true));
            } else if (depDelta < 0) {
                box.appendChild(bookingCancelRow('Credit to the guest', marleAmendPeso(Math.abs(depDelta)), true));
            }
        }

        function applyBookingAmendData(data, prefill) {
            document.getElementById('bookingAmendLoading').classList.add('d-none');
            const booking = data.booking || {};
            const q       = data.quote || {};
            bookingAmendBooking = booking;
            bookingAmendType    = booking.booking_type === 'hourly' ? 'hourly' : 'daily';

            // Show the right fields for this booking type.
            document.getElementById('bookingAmendCheckOutWrap').classList.toggle('d-none', bookingAmendType === 'hourly');
            document.getElementById('bookingAmendTimeWrap').classList.toggle('d-none', bookingAmendType !== 'hourly');
            document.getElementById('bookingAmendHoursWrap').classList.toggle('d-none', bookingAmendType !== 'hourly');

            if (prefill) {
                const form = data.form || {};
                document.getElementById('bookingAmendCheckIn').value  = form.check_in || '';
                document.getElementById('bookingAmendCheckOut').value = form.check_out || '';
                document.getElementById('bookingAmendTime').value     = form.check_in_time || '';
                document.getElementById('bookingAmendHours').value    = form.hours_requested || '';
                document.getElementById('bookingAmendPersons').value  = form.persons || 1;
            }

            bookingAmendSetRooms(data.rooms, (data.form || {}).room_number);
            renderBookingAmendSummary(booking, q);

            // Errors first, then an unavailable room, then the green light.
            const hint    = document.getElementById('bookingAmendHint');
            const blocked = document.getElementById('bookingAmendBlocked');
            const saveBtn = document.getElementById('bookingAmendBtn');
            blocked.classList.add('d-none');
            hint.textContent = '';

            let ok = !!data.can_amend && !!q.available;
            if ((q.errors || []).length) {
                hint.textContent = q.errors.join(' ');
                ok = false;
            } else if (!q.available) {
                hint.textContent = q.note || '';
                ok = false;
            } else if (!data.can_amend) {
                blocked.textContent = data.reason || 'This booking cannot be changed.';
                blocked.classList.remove('d-none');
            } else {
                const depDelta = Number(q.deposit_delta) || 0;
                hint.textContent = depDelta > 0
                    ? 'The guest will be emailed the new details and asked for the extra ' + marleAmendPeso(depDelta) + ' deposit.'
                    : (depDelta < 0
                        ? 'The guest will be emailed the new details; ' + marleAmendPeso(Math.abs(depDelta)) + ' on record is creditable.'
                        : 'The guest will be emailed the new details.');
            }

            document.getElementById('bookingAmendForm').classList.toggle('d-none', !data.can_amend);
            saveBtn.classList.toggle('d-none', !ok);
            saveBtn.disabled = false;
            document.getElementById('bookingAmendBody').classList.remove('d-none');
        }

        function fetchBookingAmend(payload, prefill) {
            fetch('process_amend_booking.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: encodeBody(payload)
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) { bookingAmendError(data.message || LB.loadFailed); return; }
                applyBookingAmendData(data, prefill);
            })
            .catch(() => bookingAmendError(LB.unreachable));
        }

        function scheduleBookingAmendQuote() {
            if (bookingAmendTimer) clearTimeout(bookingAmendTimer);
            bookingAmendTimer = setTimeout(function () {
                bookingAmendTimer = null;
                const params = bookingAmendParams();
                params.mode = 'quote';
                // Only quote once the basic shape is there; the server refuses
                // otherwise and the hint explains why.
                if (!params.check_in || (bookingAmendType !== 'hourly' && !params.check_out)) return;
                fetchBookingAmend(params, false);
            }, 350);
        }

        function openBookingAmendModal(bookingId) {
            bookingAmendTargetId = bookingId;
            bookingAmendBooking  = null;
            bookingAmendError('');
            document.getElementById('bookingAmendLoading').classList.remove('d-none');
            document.getElementById('bookingAmendBody').classList.add('d-none');
            document.getElementById('bookingAmendBlocked').classList.add('d-none');
            document.getElementById('bookingAmendBtn').classList.add('d-none');
            document.getElementById('bookingAmendBtn').disabled = false;
            getBookingAmendModal().show();
            fetchBookingAmend({ mode: 'preview', booking_id: bookingId }, true);
        }

        function confirmBookingAmend() {
            const error = document.getElementById('bookingAmendError');
            const btn   = document.getElementById('bookingAmendBtn');
            btn.disabled = true;
            const params = bookingAmendParams();
            params.mode = 'amend';

            fetch('process_amend_booking.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: encodeBody(params)
            })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                if (!data.success) {
                    error.textContent = data.message || LB.amendFailed;
                    error.classList.remove('d-none');
                    return;
                }
                getBookingAmendModal().hide();
                showToast(data.message, 'success');
                setTimeout(function () {
                    if (window.marleBookingsWatch && window.marleBookingsWatch.reload) {
                        window.marleBookingsWatch.reload();
                    } else {
                        window.location.reload();
                    }
                }, 900);
            })
            .catch(() => {
                btn.disabled = false;
                error.textContent = LB.unreachable;
                error.classList.remove('d-none');
            });
        }

        ['bookingAmendCheckIn', 'bookingAmendCheckOut', 'bookingAmendTime', 'bookingAmendHours', 'bookingAmendRoom', 'bookingAmendPersons']
            .forEach(function (id) {
                const el = document.getElementById(id);
                if (!el) return;
                el.addEventListener('change', scheduleBookingAmendQuote);
                el.addEventListener('input', scheduleBookingAmendQuote);
            });
        function doBookingAction(params) {
            fetch('process_booking_action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: encodeBody(params)
            })
            .then(r => r.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'danger');
                if (data.success) {
                    const card = document.querySelector(`.svc-card[data-booking-id="${params.booking_id}"]`);
                    if (card) {
                        card.classList.remove('pending', 'approved', 'denied');
                        const badgesWrap = card.querySelector('.d-flex.gap-2.align-items-center');
                        const statusBadge = card.querySelector('.status-badge');
                        const actionWrap = card.querySelector('.d-flex.gap-2.flex-wrap.mt-2');

                        if (params.action === 'approve') {
                            card.classList.add('approved');
                            card.dataset.bookStatus = 'approved';
                            if (statusBadge) {
                                statusBadge.className = 'status-badge badge bg-success';
                                statusBadge.textContent = 'Approved';
                            }
                            // Add room badge
                            if (data.room_number && badgesWrap) {
                                const roomBadge = document.createElement('span');
                                roomBadge.className = 'badge bg-info text-dark';
                                roomBadge.textContent = 'Room ' + data.room_number;
                                badgesWrap.insertBefore(roomBadge, statusBadge);
                            }
                            if (actionWrap) actionWrap.remove();
                            appendArchiveButton(card, params.booking_id, 'booking');
                        } else if (params.action === 'deny') {
                            card.classList.add('denied');
                            card.dataset.bookStatus = 'denied';
                            if (statusBadge) {
                                statusBadge.className = 'status-badge badge bg-danger';
                                statusBadge.textContent = 'Denied';
                            }
                            if (actionWrap) actionWrap.remove();
                            appendDenialReason(card, params.reason);
                            appendArchiveButton(card, params.booking_id, 'booking');
                        }
                    }
                }
            })
            .catch(() => showToast('Network error. Please try again.', 'danger'));
        }

        function filterBookings() {
            const statusFilter = document.getElementById('bookStatusFilter').value;
            document.querySelectorAll('#tabBookings .svc-card').forEach(card => {
                const status = card.dataset.bookStatus;
                card.style.display = (statusFilter === 'all' || status === statusFilter) ? '' : 'none';
            });
        }

        document.getElementById('myInput')?.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            const activePane = document.querySelector('.tab-pane.active');
            if (!activePane) return;
            const cards = activePane.querySelectorAll('.message-item, .ext-card, .svc-card');
            cards.forEach(card => {
                card.style.display = !q || card.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    </script>

    <!-- ── OLD CONVERSATIONS MODAL ── -->
    <div class="modal fade" id="oldConversationsModal" tabindex="-1" aria-labelledby="oldConvLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background:#ffb60a; color:#fff;">
                    <h5 class="modal-title" id="oldConvLabel"><i class="bi bi-archive me-2"></i>Old Conversations</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="min-height:400px;">
                    <!-- Filters -->
                    <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
                        <input type="text" id="oldConvRoomFilter" class="form-control form-control-sm"
                               style="max-width:140px;" placeholder="Room #" oninput="loadOldConversations()">
                        <input type="date" id="oldConvDateFrom" class="form-control form-control-sm"
                               style="max-width:160px;" onchange="loadOldConversations()">
                        <input type="date" id="oldConvDateTo" class="form-control form-control-sm"
                               style="max-width:160px;" onchange="loadOldConversations()">
                        <button class="btn btn-outline-secondary btn-sm" onclick="clearOldConvFilters()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                        </button>
                        <span class="text-muted small ms-auto">Auto-deleted 60 days after archiving</span>
                    </div>
                    <!-- Conversation list / messages view -->
                    <div id="oldConvList">
                        <p class="text-muted p-2">Loading...</p>
                    </div>
                    <!-- Message detail view (hidden by default) -->
                    <div id="oldConvMessages" style="display:none;">
                        <button class="btn btn-outline-secondary btn-sm mb-3" onclick="backToConvList()">
                            <i class="bi bi-arrow-left me-1"></i>Back to list
                        </button>
                        <h6 id="oldConvRoomTitle" class="mb-3"></h6>
                        <div id="oldConvMsgContent" style="max-height:50vh; overflow-y:auto; padding:10px; background:#f2ece9; border-radius:12px;">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── OLD CONVERSATIONS JS ── -->
    <script>
        let oldConvTimer = null;

        document.getElementById('oldConversationsModal')?.addEventListener('shown.bs.modal', function() {
            loadOldConversations();
        });

        function clearOldConvFilters() {
            document.getElementById('oldConvRoomFilter').value = '';
            document.getElementById('oldConvDateFrom').value   = '';
            document.getElementById('oldConvDateTo').value     = '';
            loadOldConversations();
        }

        function loadOldConversations() {
            clearTimeout(oldConvTimer);
            oldConvTimer = setTimeout(_doLoadOldConv, 250);
        }

        function _doLoadOldConv() {
            const room     = document.getElementById('oldConvRoomFilter').value.trim();
            const dateFrom = document.getElementById('oldConvDateFrom').value;
            const dateTo   = document.getElementById('oldConvDateTo').value;
            const list     = document.getElementById('oldConvList');
            const msgs     = document.getElementById('oldConvMessages');

            list.style.display = '';
            msgs.style.display = 'none';
            list.innerHTML = '<p class="text-muted p-2">Loading...</p>';

            // Build URLs for both chats and requests
            let chatUrl = 'fetch_archived_chats.php?page=1';
            let reqUrl = 'fetch_archived_request.php?page=1';
            if (room) {
                chatUrl += '&room=' + encodeURIComponent(room);
                reqUrl += '&room=' + encodeURIComponent(room);
            }
            if (dateFrom) {
                chatUrl += '&date_from=' + encodeURIComponent(dateFrom);
                reqUrl += '&date_from=' + encodeURIComponent(dateFrom);
            }
            if (dateTo) {
                chatUrl += '&date_to=' + encodeURIComponent(dateTo);
                reqUrl += '&date_to=' + encodeURIComponent(dateTo);
            }

            // Fetch both archived chats and archived requests
            Promise.all([
                fetch(chatUrl).then(r => r.json()),
                fetch(reqUrl).then(r => r.json())
            ])
            .then(([chatData, reqData]) => {
                // Merge results by room
                const roomMap = {};

                if (chatData.success && chatData.rooms) {
                    chatData.rooms.forEach(r => {
                        if (!roomMap[r.room_number]) {
                            roomMap[r.room_number] = {
                                room_number: r.room_number,
                                chats: [],
                                requests: []
                            };
                        }
                        roomMap[r.room_number].chats.push(r);
                    });
                }

                if (reqData.success && reqData.requests) {
                    reqData.requests.forEach(req => {
                        if (!roomMap[req.room_number]) {
                            roomMap[req.room_number] = {
                                room_number: req.room_number,
                                chats: [],
                                requests: []
                            };
                        }
                        roomMap[req.room_number].requests.push(req);
                    });
                }

                const rooms = Object.values(roomMap);
                if (rooms.length === 0) {
                    list.innerHTML = '<p class="text-muted p-2">' + LB.noArchived + '</p>';
                    return;
                }

                // Render unified list
                list.innerHTML = rooms.map(r => {
                    const chatCount = r.chats.reduce((sum, c) => sum + (c.message_count || 0), 0);
                    const reqCount = r.requests.length;
                    const chatSummary = r.chats.length > 0 ? r.chats[0] : null;

                    return `
                        <div class="ext-card" style="border-left:4px solid #6c757d; cursor:pointer;"
                             onclick="viewOldConversation('${escHtml(r.room_number)}')">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <span class="fw-bold"><i class="bi bi-chat-dots me-1"></i>Room ${escHtml(r.room_number)}</span>
                                    ${chatCount > 0 ? `<span class="badge bg-secondary ms-1">${chatCount} chat messages</span>` : ''}
                                    ${reqCount > 0 ? `<span class="badge bg-info ms-1">${reqCount} requests (${r.requests.map(rq => rq.type).join(', ')})</span>` : ''}
                                </div>
                            </div>
                            ${chatSummary ? `
                            <p class="text-muted small mt-1 mb-0">
                                <i class="bi bi-clock me-1"></i>
                                ${escHtml(chatSummary.first_message)} &mdash; ${escHtml(chatSummary.last_message)}
                                &nbsp;&bull;&nbsp; Archived: ${escHtml(chatSummary.archived_at || '—')}
                            </p>` : ''}
                        </div>
                    `;
                }).join('');
            })
            .catch((err) => {
                list.innerHTML = '<p class="text-danger p-2">Failed to load archived conversations. Please try again.</p>';
            });
        }

        function viewOldConversation(room) {
            const list = document.getElementById('oldConvList');
            const msgs = document.getElementById('oldConvMessages');
            const content = document.getElementById('oldConvMsgContent');
            const title   = document.getElementById('oldConvRoomTitle');

            list.style.display = 'none';
            msgs.style.display = '';
            title.textContent  = 'Room ' + room + ' — Archived Conversation & Requests';
            content.innerHTML  = '<p class="text-muted">Loading messages and requests...</p>';

            // Fetch both chat messages and archived requests
            Promise.all([
                fetch('fetch_archived_chat_messages.php?room=' + encodeURIComponent(room)).then(r => r.json()),
                fetch('fetch_archived_request.php?room=' + encodeURIComponent(room)).then(r => r.json())
            ])
            .then(([chatData, reqData]) => {
                let html = '';

                // Display archived chat messages
                if (chatData.success && chatData.messages && chatData.messages.length > 0) {
                    html += '<div class="mb-3 pb-2 border-bottom"><h6 class="text-muted"><i class="bi bi-chat-dots me-1"></i>Chat Messages</h6></div>';
                    html += chatData.messages.map(m => {
                        const isGuest = m.sender === 'guest';
                        const sentLabel = formatChatTime(m.sent_at);
                        const attachHtml = renderAttachment(m, isGuest);
                        if (isGuest) {
                            return `<div class="guest-request">
                                <img src="../img/default.jpg" class="avatar">
                                <div>
                                    <p class="guest-message mb-0">${escHtml(m.message)}</p>
                                    ${attachHtml}
                                    <div class="chat-ts guest-bubble">${sentLabel}</div>
                                </div>
                            </div>`;
                        } else {
                            return `<div class="reply">
                                ${escHtml(m.message)}
                                ${attachHtml}
                                <div class="chat-ts admin-bubble">Sent ${sentLabel}</div>
                            </div>`;
                        }
                    }).join('');
                }

                // Display archived requests
                if (reqData.success && reqData.requests && reqData.requests.length > 0) {
                    html += '<div class="mt-4 mb-3 pb-2 border-bottom"><h6 class="text-muted"><i class="bi bi-file-earmark-check me-1"></i>Archived Requests</h6></div>';
                    html += reqData.requests.map(req => {
                        const statusBadge = `<span class="badge bg-${req.status === 'approved' ? 'success' : req.status === 'denied' ? 'danger' : 'warning'}">${req.status}</span>`;
                        const typeIcon = {
                            'extend': 'bi-calendar-plus',
                            'service': 'bi-tools',
                            'food': 'bi-egg-fried',
                            'chat': 'bi-chat-dots'
                        }[req.type] || 'bi-file-earmark';
                        
                        return `<div class="ext-card mb-2" style="border-left:4px solid #0d6efd;">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <span class="fw-bold"><i class="bi ${typeIcon} me-1"></i>${escHtml(req.type.charAt(0).toUpperCase() + req.type.slice(1))} Request</span>
                                    ${statusBadge}
                                </div>
                            </div>
                            <p class="text-muted small mb-1">${escHtml(req.message)}</p>
                            <p class="text-muted small mb-0">
                                <i class="bi bi-clock me-1"></i>Created: ${escHtml(req.created_at)} | Archived: ${escHtml(req.archived_at || '—')}
                            </p>
                        </div>`;
                    }).join('');
                }

                if (!html || ((!chatData.success || !chatData.messages || chatData.messages.length === 0) && 
                             (!reqData.success || !reqData.requests || reqData.requests.length === 0))) {
                    content.innerHTML = '<p class="text-muted">No archived messages or requests found for this room.</p>';
                } else {
                    content.innerHTML = html;
                    content.scrollTop = 0;
                }
            })
            .catch((err) => {
                content.innerHTML = '<p class="text-danger">Failed to load messages or requests.</p>';
            });
        }

        function backToConvList() {
            document.getElementById('oldConvList').style.display     = '';
            document.getElementById('oldConvMessages').style.display = 'none';
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-j1CDi7MgGQ12Z7Qab0qlWQ/Qqz24Gc6BM0thvEMVjHnfYGF0rmFCozFSxQBxwHKO"
        crossorigin="anonymous"></script>
    <script src="../admin/includes/notif_poll.js"></script>

    <script>
    // ── Deep-link handler: navigate to tab/card from notification URL params ──
    (function handleNotifDeepLink() {
        const params = new URLSearchParams(window.location.search);
        const tab    = params.get('tab');
        const refId  = params.get('reference_id');
        const room   = params.get('room');

        if (!tab) return;

        const tabBtnMap = {
            chat: 'chat-tab', ext: 'ext-tab', svc: 'svc-tab',
            food: 'food-tab', book: 'book-tab', archive: 'archive-tab'
        };
        const tabBtn = document.getElementById(tabBtnMap[tab]);
        if (tabBtn) {
            const bsTab = new bootstrap.Tab(tabBtn);
            bsTab.show();
        }

        if (tab === 'chat' && room) {
            setTimeout(function() {
                if (typeof openChat === 'function') openChat(room);
            }, 300);
        }

        if (refId && (tab === 'ext' || tab === 'svc' || tab === 'food')) {
            const typeMap = { ext: 'extend', svc: 'service', food: 'food' };
            const notifType = typeMap[tab];

            setTimeout(function() {
                const card = document.querySelector('[data-request-id="' + refId + '"]');
                if (card) {
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    card.style.outline = '3px solid #ffb60a';
                    card.style.outlineOffset = '2px';
                    setTimeout(function() {
                        card.style.outline = '';
                        card.style.outlineOffset = '';
                    }, 3000);

                    var statusAttr = card.dataset.extStatus
                                  || card.dataset.svcStatus
                                  || card.dataset.foodStatus || '';
                    if (statusAttr === 'approved' || statusAttr === 'denied') {
                        fetch('includes/mark_notif_read.php', {
                            method: 'POST',
                            body: new URLSearchParams({
                                type: notifType,
                                reference_id: refId
                            })
                        });
                    }
                }
            }, 400);
        }

        if (window.history.replaceState) {
            window.history.replaceState({}, '', window.location.pathname);
        }
    })();
    </script>

<?php /* Requests → Bookings notices guest activity (a new booking, a receipt
         upload, a cancellation, an automatic hold release) on its own — no F5. */ ?>
<?php marle_bookings_watch_assets('bookings_watch.php', $bookSignature); ?>
<?php marle_booking_details_assets(); ?>
<?php marle_requests_pagination_assets(); ?>
</body>
</html>



