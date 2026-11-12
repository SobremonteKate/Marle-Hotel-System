<?php
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.dashboard');
include('auth.php');
include('../superadmin/requests_badge.php');
include('../superadmin/includes/notif_data.php');
require_once __DIR__ . '/includes/token_sweep.php';
run_token_sweep($conn);
require_once __DIR__ . '/../dashboard_metrics.php';

$allowedRanges = [6, 12, 24, 36];
$months = 6;
if (isset($_GET['months']) && in_array((int)$_GET['months'], $allowedRanges, true)) {
    $months = (int)$_GET['months'];
}

$dashboardMetrics = dashboard_metrics($conn, $months);
extract($dashboardMetrics, EXTR_SKIP);

$jsLabels = json_encode($labels);
$jsCheckIns = json_encode($checkInsTrend);
$jsGuests = json_encode($guestTrend);
$jsRevenueTypeLabels = json_encode($revenueTypeLabels);
$jsRevenueTypeData = json_encode($revenueTypeData);
$jsRequestTypeLabels = json_encode($requestTypeLabels);
$jsRequestTypeData = json_encode($requestTypeData);
$jsRoomStatusLabels = json_encode($roomStatusLabels);
$jsRoomStatusData = json_encode($roomStatusData);

$dashboardSectionTitle = 'Operations summary for the owner';
$dashboardLinks = [
    'requests'     => 'requests.php',
    'inventory'    => 'inventory.php',
    'transactions' => 'transaction_history.php',
];

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    // Redirect to login with a success flag
    header("Location: ../login.php?logout=success");
    exit();
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
    <link rel="stylesheet" href="https://cdn.lineicons.com/5.0/lineicons.css" />
    <link rel="stylesheet" href="../css/adminDB.css?v=20261012b">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">

    <!-- icon, to be changed -->
    <?php require __DIR__ . '/../includes/staff_icons.php'; ?>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
    <link rel="stylesheet" href="../css/staff-footer.css?v=20261021">
    <link rel="stylesheet" href="../css/staff-logout.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

    <!-- For charts/graphs -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.0"></script>

    <title>Dashboard</title>

    <style>
        :root {
            --room-amber: #ffb60a;
            --room-amber-dark: #e6a308;
            --room-brown: #933310;
            --room-text: #1e1108;
            --room-muted: #7a6455;
            --room-border: #ecddd3;
        }

        .dashboard-room-board {
            background: #fff;
            border-radius: 16px;
            border: 1px solid var(--room-border);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .dashboard-room-board .room-board-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding: 18px 20px 0;
        }

        .dashboard-room-board .section-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--room-text);
        }

        .dashboard-room-board .room-board-body {
            padding: 16px 20px 20px;
        }

        .dashboard-room-board .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border-radius: 10px;
            padding: 8px 18px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: 0.2s;
            cursor: pointer;
            background: transparent;
            color: var(--room-muted);
            border: 1.5px solid var(--room-border);
        }

        .dashboard-room-board .back-btn:hover {
            border-color: var(--room-amber-dark);
            color: var(--room-amber-dark);
            background: #fffbf0;
        }

        .dashboard-room-board .cat-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 14px;
        }

        .dashboard-room-board .cat-tab {
            padding: 5px 14px;
            border-radius: 99px;
            font-size: 0.78rem;
            font-weight: 600;
            border: 1.5px solid var(--room-border);
            background: #fff;
            color: var(--room-muted);
            cursor: pointer;
            transition: 0.18s;
            white-space: nowrap;
            text-decoration: none;
        }

        .dashboard-room-board .cat-tab:hover {
            border-color: var(--room-amber);
            color: var(--room-amber-dark);
            background: #fffbf0;
        }

        .dashboard-room-board .cat-tab.active {
            background: var(--room-amber);
            border-color: var(--room-amber);
            color: #fff;
        }

        .dashboard-room-board .room-card {
            border: 1px solid var(--room-border);
            border-left-width: 5px;
            border-radius: 10px;
            padding: 1rem 1rem 0.85rem;
            cursor: pointer;
            transition: box-shadow 0.18s, transform 0.18s;
            background: #fff;
            position: relative;
            user-select: none;
            min-height: 120px;
        }

        .dashboard-room-board .room-card:hover {
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }

        .dashboard-room-board .room-card.status-available { border-left-color: #16a34a; }
        .dashboard-room-board .room-card.status-occupied { border-left-color: #dc2626; }
        .dashboard-room-board .room-card.status-booked { border-left-color: var(--room-amber); }
        .dashboard-room-board .room-card.status-cancelled { border-left-color: #dc2626; }
        .dashboard-room-board .room-card.status-maintenance { border-left-color: var(--room-muted); }

        .dashboard-room-board .room-number {
            font-family: 'Playfair Display', serif;
            font-size: 1.9rem;
            font-weight: 700;
            line-height: 1;
            color: var(--room-text);
        }

        .dashboard-room-board .room-type {
            font-size: 0.75rem;
            color: var(--room-muted);
            margin-top: 2px;
        }

        .dashboard-room-board .room-detail {
            font-size: 0.78rem;
            color: #5f4b3f;
            margin-top: 6px;
            min-height: 1.1rem;
        }

        .dashboard-room-board .status-badge,
        #roomEditModal .status-badge {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .badge-available { background: #dcfce7; color: #16a34a; }
        .badge-occupied { background: #fee2e2; color: #dc2626; }
        .badge-booked { background: #fff8e1; color: var(--room-amber-dark); }
        .badge-cancelled { background: #fee2e2; color: #dc2626; }
        .badge-maintenance { background: #f0ece8; color: var(--room-muted); }

        @keyframes dashboardRoomShimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        .dashboard-room-board .skeleton-card {
            border-radius: 10px;
            height: 120px;
            background: linear-gradient(90deg, #fff 25%, var(--room-border) 50%, #fff 75%);
            background-size: 200% 100%;
            animation: dashboardRoomShimmer 1.2s infinite;
        }

        #roomEditModal .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        }

        #roomEditModal .modal-header {
            border-bottom: 1px solid var(--room-border);
            padding: 18px 22px;
        }

        #roomEditModal .modal-body {
            padding: 20px 22px;
        }

        #roomEditModal .modal-footer {
            border-top: 1px solid var(--room-border);
            padding: 14px 22px;
        }

        #roomEditModal .modal-room-number {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            font-weight: 700;
            color: var(--room-brown);
            line-height: 1.1;
        }

        #roomEditModal .detail-block {
            background: #fdf8f2;
            border: 1px solid var(--room-border);
            border-radius: 10px;
            padding: 0.85rem 1rem;
            font-size: 0.86rem;
        }

        #roomEditModal .detail-block dt {
            color: var(--room-muted);
            font-weight: 700;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        #roomEditModal .detail-block dd {
            color: var(--room-text);
            margin-bottom: 0.35rem;
        }

        #roomEditModal .form-control:focus,
        #roomEditModal .form-select:focus {
            border-color: var(--room-amber);
            box-shadow: 0 0 0 3px rgba(255, 182, 10, 0.15);
        }

        .btn-modal-cancel {
            background: #f5f5f5;
            color: var(--room-muted);
            border: none;
            border-radius: 9px;
            padding: 8px 18px;
            font-weight: 500;
            font-size: 0.88rem;
            cursor: pointer;
        }

        .btn-modal-cancel:hover {
            background: #ebebeb;
            color: var(--room-text);
        }

        .btn-modal-save {
            background: var(--room-amber);
            color: #fff;
            border: none;
            border-radius: 9px;
            padding: 8px 22px;
            font-weight: 600;
            font-size: 0.88rem;
            cursor: pointer;
        }

        .btn-modal-save:hover {
            background: var(--room-amber-dark);
            color: #fff;
        }

        #last-updated-text,
        #refresh-spinner {
            display: none !important;
        }
    </style>

<?php require_once __DIR__ . '/../includes/server_clock.php'; marle_server_clock_assets(); ?>
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>

<body class="staff-dashboard staff-superadmin staff-page staff-dashboard-page">
    <?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

    <!-- Welcome message + search -->
    <?php
    $staffPageTitle  = 'Dashboard';
    $staffPageCrumbs = [];
    $staffPageNote   = 'Welcome back, ' . $_SESSION['username'] . '.';
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>
    <?php include(__DIR__ . '/../includes/dashboard_overview_panel.php'); ?>

    <!-- Room Board Section -->
    <div class="content p-4">
        <div class="container">
            <div class="dashboard-room-board">
                <div class="room-board-head">
                    <div class="section-title">Room Availability Board</div>
                    <div class="d-flex align-items-center gap-2">
                        <span id="availRefreshStatus" class="text-muted small"></span>
                        <span id="last-updated-text">Loading...</span>
                        <button class="back-btn" type="button" onclick="fetchRoomBoard(true)">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                    </div>
                </div>

                <div class="room-board-body">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <span class="status-badge badge-available">Available</span>
                        <span class="status-badge badge-occupied">Occupied</span>
                        <span class="status-badge badge-booked">Booked</span>
                        <span class="status-badge badge-cancelled">Cancelled</span>
                        <span class="status-badge badge-maintenance">Maintenance</span>
                    </div>
                    <div class="cat-tabs">
                        <button class="cat-tab active room-board-filter-btn" data-filter="all" type="button">All <span data-count-for="all">0</span></button>
                        <button class="cat-tab room-board-filter-btn" data-filter="available" type="button">Available <span data-count-for="available">0</span></button>
                        <button class="cat-tab room-board-filter-btn" data-filter="occupied" type="button">Occupied <span data-count-for="occupied">0</span></button>
                        <button class="cat-tab room-board-filter-btn" data-filter="booked" type="button">Booked <span data-count-for="booked">0</span></button>
                        <button class="cat-tab room-board-filter-btn" data-filter="cancelled" type="button">Cancelled <span data-count-for="cancelled">0</span></button>
                        <button class="cat-tab room-board-filter-btn" data-filter="maintenance" type="button">Maintenance <span data-count-for="maintenance">0</span></button>
                    </div>

                    <?php
                    // The room board's cards are built in JavaScript from
                    // get_room_board.php, and the switch never rewrites code — so the
                    // wording is written here, in the markup, and read back by the
                    // script through these data attributes (see LB below).
                    // Keys are kebab-case on purpose: data-last-updated reads back
                    // as dataset.lastUpdated in the script below.
                    $boardLabels = [
                        'view-details'      => ['View details', 'Tingnan ang detalye'],
                        'no-details'        => ['No additional details', 'Walang karagdagang detalye'],
                        'no-rooms'          => ['No room data available.', 'Walang available na datos ng silid.'],
                        'no-match'          => ['No rooms match this filter.', 'Walang silid na tumutugma sa filter na ito.'],
                        'load-failed'       => ['Failed to load room availability.', 'Nabigo ang pag-load ng availability ng silid.'],
                        'room-prefix'       => ['Room ', 'Silid '],
                        'hours-staying'     => ['Hours Staying', 'Oras ng Paninirahan'],
                        'available-at'      => ['Available At', 'Magagamit sa'],
                        'time-slot'         => ['Time Slot', 'Takdang Oras'],
                        'cancelled-slot'    => ['Cancelled Slot', 'Kinanselang Slot'],
                        'hours-unit'        => ['hr(s)', 'oras'],
                        'under-maintenance' => ['Under Maintenance', 'Nasa Pag-aayos'],
                        'force-release'     => ['Force release (check guest out & free room)', 'Force release (i-check out ang bisita at palayain ang silid)'],
                        'saved'             => ['Saved. Refreshing board...', 'Nai-save. Nire-refresh ang board...'],
                        'cancelled-save'    => ['Booking cancelled. Refreshing board...', 'Kinansela ang booking. Nire-refresh ang board...'],
                        'save-failed'       => ['Save failed.', 'Nabigo ang pag-save.'],
                        'network-error'     => ['Network error. Please try again.', 'Error sa network. Subukan muli.'],
                        'refreshing'        => ['Refreshing...', 'Nire-refresh...'],
                        'loading'           => ['Loading...', 'Nilo-load...'],
                        'last-updated'      => ['Last updated: ', 'Huling update: '],
                        'seconds-ago'       => ['s ago', 'seg ang nakalipas'],
                        'minutes-ago'       => ['m ago', 'min ang nakalipas'],
                        // The room modal is filled in by script too.
                        'no-override'       => ['No override (clear maintenance)', 'Walang override (alisin ang pag-aayos)'],
                        'mark-maintenance'  => ['Mark as Under Maintenance', 'Markahan bilang Nasa Pag-aayos'],
                        'cancel-booking'    => ['Cancel Booking', 'Kanselahin ang Booking'],
                        'guest'             => ['Guest', 'Bisita'],
                        'booking-type'      => ['Booking Type', 'Uri ng Booking'],
                        'check-in'          => ['Check-in', 'Pag-check-in'],
                        'check-out'         => ['Check-out', 'Pag-check-out'],
                        'cancelled-stay'    => ['Cancelled Stay', 'Kinanselang Paninirahan'],
                        'note'              => ['Note', 'Tala'],
                        'available-at-time' => ['Available at', 'Magagamit sa'],
                        'cancelled-booking' => ['Cancelled booking', 'Kinanselang booking'],
                    ];
                    ?>
                    <div id="roomBoardLabels" class="d-none" hidden<?php foreach ($boardLabels as $boardKey => [$boardEn, $boardTl]): ?> data-<?= $boardKey ?>="<?= htmlspecialchars(ui_t($boardEn, $boardTl), ENT_QUOTES, 'UTF-8') ?>"<?php endforeach; ?>></div>
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
    </div>

    <!-- Room Board Edit Modal -->
    <div class="modal fade" id="roomEditModal" tabindex="-1" aria-labelledby="roomEditModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <div class="modal-room-number" id="modal-room-number" style="font-size:2rem;font-weight:700;color:#933310;">-</div>
                        <div class="text-muted" style="font-size:0.82rem;" id="modal-room-type">-</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <span class="status-badge" id="modal-live-badge" style="font-size:0.72rem;font-weight:600;padding:3px 9px;border-radius:20px;text-transform:uppercase;letter-spacing:0.4px;">-</span>
                        <span class="text-muted ms-2" style="font-size:0.78rem;">current live status</span>
                    </div>
                    <div id="modal-detail-block" class="detail-block mb-3 d-none">
                        <dl class="mb-0" id="modal-detail-list"></dl>
                    </div>
                    <p class="section-title mb-2" style="font-size:1.05rem;">Admin Override</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:0.85rem;">Set Room Override</label>
                       <select class="form-select form-select-sm" id="modal-status-select">
                            <option value="available">No override (clear maintenance)</option>
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
    <?php include(__DIR__ . '/../includes/dashboard_trends_panel.php'); ?>

    <!-- Footer — one design for all three roles (includes/staff_footer.php) -->
    <?php $marleFooterTerms = true; require __DIR__ . '/../includes/staff_footer.php'; ?>

    <!-- Terms & Conditions Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header">
                    <h4 class="modal-title" id="termsModalLabel">Terms & Conditions</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">

                    <h5>1. Acceptance of Terms</h5>
                    <p>
                        By accessing and using this system, you acknowledge that you have read,
                        understood, and agreed to comply with all applicable policies, rules,
                        and regulations governing system usage.
                    </p>

                    <h5>2. User Responsibilities</h5>
                    <p>
                        Users are responsible for maintaining the confidentiality of their
                        account credentials and for all activities conducted under their account.
                    </p>
                    <ul>
                        <li>Do not share login credentials.</li>
                        <li>Log out after each session.</li>
                        <li>Report suspicious activity immediately.</li>
                    </ul>

                    <h5>3. Data Privacy and Protection</h5>
                    <p>
                        All personal data collected will be handled in accordance with
                        data protection laws and company privacy policies.
                    </p>

                    <h5>4. System Security</h5>
                    <p>
                        Unauthorized access, misuse, or tampering with system data
                        is strictly prohibited and may result in disciplinary action.
                    </p>

                    <h5>5. Limitation of Liability</h5>
                    <p>
                        The company shall not be liable for any damages resulting from
                        improper system use or unauthorized access.
                    </p>

                    <h5>6. Amendments</h5>
                    <p>
                        These terms may be updated at any time without prior notice.
                        Continued use of the system constitutes acceptance of changes.
                    </p>

                    <p>
                        Lorem ipsum dolor sit amet, consectetur adipiscing elit.
                        Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.
                    </p>

                    <p>
                        Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris
                        nisi ut aliquip ex ea commodo consequat.
                    </p>

                    <p>
                        Duis aute irure dolor in reprehenderit in voluptate velit esse
                        cillum dolore eu fugiat nulla pariatur.
                    </p>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>

            </div>
        </div>
    </div>

    <!-- LOGOUT MODAL -->
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
                    <a href="../logout.php" class="btn" id="yesBtn">Yes</a>
                </div>
            </div>
        </div>
    </div>

    <?php include(__DIR__ . '/../includes/dashboard_trends_script.php'); ?>

    <!-- Room Board JS -->
    <script>
    (() => {
        const state = {
            roomData: [],
            secondsSince: 0,
            countdownTimer: null,
            currentFilter: 'all',
            roomEditModal: null,
            currentRoomNum: null
        };
        const REFRESH_INTERVAL_LOCAL = 30;
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

        // The wording the board builds in JavaScript: read from the markup (see
        // roomBoardLabels), never written into the code itself.
        const LB = document.getElementById('roomBoardLabels').dataset;

        function badgeLabel(status) {
            // The legend badges are server-rendered and already translated, so the
            // card borrows their wording rather than repeating it here.
            const legend = document.querySelector('.status-badge.badge-' + status);
            return legend ? legend.textContent.trim() : status;
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
                const hrsPart = room.hours_staying != null ? ` &middot; ${room.hours_staying}${LB.hoursUnit}` : '';
                return `<i class="bi bi-clock"></i> ${LB.availableAtTime} ${fmtTime(room.available_at)}${hrsPart}`;
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
                    return `<i class="bi bi-x-circle"></i> ${LB.cancelledBooking} &middot; ${ci} - ${co}`;
                }
                return `<i class="bi bi-x-circle"></i> ${LB.cancelledBooking} &middot; ${fmtDate(b.check_in)} - ${fmtDate(b.check_out)}`;
            }
            if (s === 'maintenance') {
                return `<i class="bi bi-wrench"></i> ${LB.underMaintenance}`;
            }
            return '';
        }

        function renderCards(rooms) {
            const grid = document.getElementById('room-board-grid');
            if (!grid) return;
            if (!rooms || rooms.length === 0) {
                grid.innerHTML = '<div class="col-12 text-muted text-center py-5">' + LB.noRooms + '</div>';
                return;
            }

            updateRoomFilterCounts(rooms);
            const filtered = rooms.filter(room => state.currentFilter === 'all' || room.status === state.currentFilter);
            if (filtered.length === 0) {
                grid.innerHTML = '<div class="col-12 text-muted text-center py-5">' + LB.noMatch + '</div>';
                return;
            }

            grid.innerHTML = filtered.map(room => {
                const detail = buildDetail(room);
                    return `
                    <div class="col-6 col-sm-4 col-md-3 col-xl-2">
                        <article class="room-card status-${room.status}"
                             data-room='${JSON.stringify(room).replace(/'/g, "&#39;")}'
                             onclick="openEditModal(this)" tabindex="0" role="button"
                             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openEditModal(this)}">
                            <div class="room-card-top">
                                <span class="room-number">${room.room_number}</span>
                                <span class="status-badge ${badgeClass(room.status)}"><span class="status-dot"></span>${badgeLabel(room.status)}</span>
                            </div>
                            <div class="room-type">${room.room_type}</div>
                            <div class="room-detail">${detail || '<span class="room-detail-empty">' + LB.noDetails + '</span>'}</div>
                            <div class="room-card-action"><i class="bi bi-arrow-up-right"></i> ${LB.viewDetails}</div>
                        </article>
                    </div>`;
            }).join('');
        }

        function toggleRoomNoteField() {
            const select = document.getElementById('modal-status-select');
            const noteWrap = document.getElementById('modal-note-wrap');
            if (select && noteWrap) {
                noteWrap.classList.toggle('d-none', select.value !== 'maintenance');
            }
        }

        window.openEditModal = function (cardEl) {
            const room = JSON.parse(cardEl.dataset.room);
            state.currentRoomNum = room.room_number;

            document.getElementById('modal-room-number').textContent = LB.roomPrefix + room.room_number;
            document.getElementById('modal-room-type').textContent = room.room_type || '\u2014';

            const liveBadge = document.getElementById('modal-live-badge');
            liveBadge.className = 'status-badge ' + badgeClass(room.status);
            liveBadge.textContent = badgeLabel(room.status);

            const detailBlock = document.getElementById('modal-detail-block');
            const detailList = document.getElementById('modal-detail-list');
            detailList.innerHTML = '';

            if (room.status === 'occupied') {
                detailBlock.classList.remove('d-none');
                const hoursRow = room.hours_staying != null
                    ? `<dt>${LB.hoursStaying}</dt><dd>${room.hours_staying} ${LB.hoursUnit}</dd>`
                    : '';
                detailList.innerHTML = `<dt>${LB.availableAt}</dt><dd>${fmtTime(room.available_at)}</dd>${hoursRow}`;
            } else if (room.status === 'booked' && room.booking_info) {
                detailBlock.classList.remove('d-none');
                const b = room.booking_info;
                let rows = `<dt>${LB.guest}</dt><dd>${b.guest_name || '&mdash;'}</dd>
                            <dt>${LB.bookingType}</dt><dd>${b.booking_type || '&mdash;'}</dd>`;
                if (b.booking_type === 'hourly') {
                    const ci = b.check_in_time ? b.check_in_time.slice(0, 5) : '&mdash;';
                    const co = b.check_out_time ? b.check_out_time.slice(0, 5) : '&mdash;';
                    rows += `<dt>${LB.timeSlot}</dt><dd>${ci} - ${co}</dd>`;
                } else {
                    rows += `<dt>${LB.checkIn}</dt><dd>${fmtDate(b.check_in)}</dd>
                             <dt>${LB.checkOut}</dt><dd>${fmtDate(b.check_out)}</dd>`;
                }
                detailList.innerHTML = rows;
            } else if (room.status === 'cancelled' && room.cancelled_info) {
                detailBlock.classList.remove('d-none');
                const b = room.cancelled_info;
                let rows = `<dt>${LB.guest}</dt><dd>${b.guest_name || '&mdash;'}</dd>
                            <dt>${LB.bookingType}</dt><dd>${b.booking_type || '&mdash;'}</dd>`;
                if (b.booking_type === 'hourly') {
                    const ci = b.check_in_time ? b.check_in_time.slice(0, 5) : '&mdash;';
                    const co = b.check_out_time ? b.check_out_time.slice(0, 5) : '&mdash;';
                    rows += `<dt>${LB.cancelledSlot}</dt><dd>${ci} - ${co}</dd>`;
                } else {
                    rows += `<dt>${LB.cancelledStay}</dt><dd>${fmtDate(b.check_in)} - ${fmtDate(b.check_out)}</dd>`;
                }
                detailList.innerHTML = rows;
            } else if (room.status === 'maintenance' && room.maintenance_note) {
                detailBlock.classList.remove('d-none');
                detailList.innerHTML = `<dt>${LB.note}</dt><dd>${room.maintenance_note}</dd>`;
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
                relOpt.textContent = LB.forceRelease;
                select.insertBefore(relOpt, select.firstChild);
            }
            document.getElementById('modal-maint-note').value = room.maintenance_note || '';
            toggleRoomNoteField();

            const msg = document.getElementById('modal-save-msg');
            msg.className = 'alert py-2 d-none';
            msg.textContent = '';

            if (state.roomEditModal) state.roomEditModal.show();
        };

        function saveRoomOverride() {
            const selectedStatus = document.getElementById('modal-status-select').value;
            const note = document.getElementById('modal-maint-note').value.trim();
            const msg = document.getElementById('modal-save-msg');
            const btn = document.getElementById('modal-save-btn');

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

            const body = new URLSearchParams({
                room_number: state.currentRoomNum,
                status: selectedStatus,
                maintenance_note: note,
                csrf_token: '<?= $_SESSION['csrf_token'] ?? '' ?>'
            });

            fetch('../superadmin/update_room_status.php', {
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
                            ? LB.cancelledSave
                            : LB.saved;
                        setTimeout(() => {
                            if (state.roomEditModal) state.roomEditModal.hide();
                            window.fetchRoomBoard(true);
                        }, 900);
                    } else {
                        msg.className = 'alert alert-danger py-2';
                        msg.textContent = res.message || LB.saveFailed;
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Save';
                    msg.className = 'alert alert-danger py-2';
                    msg.textContent = LB.networkError;
                    msg.classList.remove('d-none');
                });
        }

        window.fetchRoomBoard = function (manual = false) {
            const status = document.getElementById('availRefreshStatus');
            const last = document.getElementById('availLastUpdated');
            if (status) status.textContent = manual ? LB.refreshing : LB.loading;

            fetch('../superadmin/get_room_board.php', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    const rooms = Array.isArray(data) ? data : [];
                    state.roomData = rooms;
                    renderCards(rooms);
                    state.secondsSince = 0;
                    // The hotel's clock, not this PC's (includes/server_clock.php): this
                    // label is read by whoever is looking at it, and a device whose clock
                    // is out used to stamp it with the wrong time.
                    const timeStr = window.MarleClock
                        ? MarleClock.formatClockSecs(MarleClock.nowMs())
                        : new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });   // marle-clock-ok: fallback for a page whose clock asset is missing; locale pinned so a 24-hour device still prints the board's clock
                    if (last) last.textContent = LB.lastUpdated + timeStr;
                    if (status) status.textContent = '';
                })
                .catch(() => {
                    if (status) status.textContent = 'Error';
                    const grid = document.getElementById('room-board-grid');
                    if (grid) {
                        grid.innerHTML = '<div class="col-12 text-danger text-center py-5">' + LB.loadFailed + '</div>';
                    }
                });
        };

        window.fetchBoard = window.fetchRoomBoard;

        window.startTicker = function () {
            if (state.countdownTimer) clearInterval(state.countdownTimer);
            state.countdownTimer = setInterval(() => {
                state.secondsSince++;
                const last = document.getElementById('availLastUpdated');
                if (last) {
                    if (state.secondsSince < 60) {
                        last.textContent = `${LB.lastUpdated}${state.secondsSince}${LB.secondsAgo}`;
                    } else {
                        const m = Math.floor(state.secondsSince / 60);
                        last.textContent = `${LB.lastUpdated}${m}${LB.minutesAgo}`;
                    }
                }
                if (state.secondsSince >= REFRESH_INTERVAL_LOCAL) {
                    state.secondsSince = 0;
                    window.fetchRoomBoard();
                }
            }, 1000);
        };

        document.addEventListener('DOMContentLoaded', function () {
            const roomModalEl = document.getElementById('roomEditModal');
            if (roomModalEl) {
                state.roomEditModal = bootstrap.Modal.getOrCreateInstance(roomModalEl);
            }

            const select = document.getElementById('modal-status-select');
            if (select) {
                select.innerHTML = `
                    <option value="available">${LB.noOverride}</option>
                    <option value="maintenance">${LB.markMaintenance}</option>
                    <option value="cancelled">${LB.cancelBooking}</option>`;
                select.addEventListener('change', toggleRoomNoteField);
            }

            const roomNumberEl = document.getElementById('modal-room-number');
            const roomTypeEl = document.getElementById('modal-room-type');
            const liveBadgeEl = document.getElementById('modal-live-badge');
            if (roomNumberEl) roomNumberEl.innerHTML = '&mdash;';
            if (roomTypeEl) roomTypeEl.innerHTML = '&mdash;';
            if (liveBadgeEl) liveBadgeEl.innerHTML = '&mdash;';

            const saveBtn = document.getElementById('modal-save-btn');
            if (saveBtn) {
                const cleanBtn = saveBtn.cloneNode(true);
                saveBtn.parentNode.replaceChild(cleanBtn, saveBtn);
                cleanBtn.addEventListener('click', saveRoomOverride);
            }

            document.querySelectorAll('.room-board-filter-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    document.querySelectorAll('.room-board-filter-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    state.currentFilter = btn.dataset.filter;
                    renderCards(state.roomData);
                });
            });

            window.fetchRoomBoard();
            window.startTicker();
        });
    })();
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
    <script src="../superadmin/includes/notif_poll.js"></script>
</body>

</html>






