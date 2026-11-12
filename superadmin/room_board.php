<?php
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');
include('auth.php');
include('../superadmin/requests_badge.php');
include('../superadmin/includes/notif_data.php');

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: ../login.php?logout=success");
    exit;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$username = $_SESSION['username'] ?? '';
$imgSrc = "../img/default.jpg";
$stmtUser = $conn->prepare("SELECT picture FROM userstbl WHERE username = ? LIMIT 1");
if ($stmtUser) {
    $stmtUser->bind_param("s", $username);
    $stmtUser->execute();
    $resultUser = $stmtUser->get_result();
    if ($resultUser && $resultUser->num_rows > 0) {
        $userRow = $resultUser->fetch_assoc();
        if (!empty($userRow['picture'])) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($userRow['picture']);
            $imgSrc = 'data:' . $mimeType . ';base64,' . base64_encode($userRow['picture']);
        }
    }
    $stmtUser->close();
}
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
    <title>Room Board | Hotel Admin</title>
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
        body { margin: 0; font-family: 'DM Sans', sans-serif; background-color: var(--bg); color: var(--text); overflow-x: hidden; }
        a { color: var(--amber-dark); }

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
        .notification-badge { background: var(--amber); color: #fff; font-size: 11px; padding: 4px 7px; }
        .logout-btn { background: var(--brown); color: #fff; border: none; padding: 10px; border-radius: 10px; transition: 0.2s; font-size: 0.9rem; }
        .logout-btn:hover { background: transparent; border: 1px solid var(--brown); color: var(--brown); }
        .bi-bell { font-size: 19px; padding: 7px; border-radius: 10px; background: var(--card-bg); color: var(--text); }

        .topbar-title { font-family: 'Playfair Display', serif; font-size: 1.6rem; color: var(--text); font-weight: 700; }
        .breadcrumb-line { font-size: 0.82rem; color: var(--muted); }
        .breadcrumb-line a { color: var(--amber-dark); text-decoration: none; }
        .page-note { font-size: 0.9rem; color: var(--muted); margin-top: 6px; }

        .table-card {
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .room-board-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding: 18px 20px 0;
        }

        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text);
        }

        .room-board-body { padding: 16px 20px 20px; }

        .back-btn {
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
            color: var(--muted);
            border: 1.5px solid var(--border);
        }

        .back-btn:hover {
            border-color: var(--amber-dark);
            color: var(--amber-dark);
            background: #fffbf0;
        }

        .cat-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
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

        .room-card {
            border: 1px solid var(--border);
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

        .room-card:hover {
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }

        .room-card.status-available { border-left-color: #16a34a; }
        .room-card.status-occupied { border-left-color: #dc2626; }
        .room-card.status-booked { border-left-color: var(--amber); }
        .room-card.status-cancelled { border-left-color: #dc2626; }
        .room-card.status-maintenance { border-left-color: var(--muted); }

        .room-number {
            font-family: 'Playfair Display', serif;
            font-size: 1.9rem;
            font-weight: 700;
            line-height: 1;
            color: var(--text);
        }

        .room-type { font-size: 0.75rem; color: var(--muted); margin-top: 2px; }
        .room-detail { font-size: 0.78rem; color: #5f4b3f; margin-top: 6px; min-height: 1.1rem; }

        .status-badge {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .badge-available { background: #dcfce7; color: #16a34a; }
        .badge-occupied { background: #fee2e2; color: #dc2626; }
        .badge-booked { background: #fff8e1; color: var(--amber-dark); }
        .badge-cancelled { background: #fee2e2; color: #dc2626; }
        .badge-maintenance { background: #f0ece8; color: var(--muted); }

        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        .skeleton-card {
            border-radius: 10px;
            height: 120px;
            background: linear-gradient(90deg, #fff 25%, var(--border) 50%, #fff 75%);
            background-size: 200% 100%;
            animation: shimmer 1.2s infinite;
        }

        .modal-content { border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15); }
        .modal-header { border-bottom: 1px solid var(--border); padding: 18px 22px; }
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
        .form-control:focus, .form-select:focus { border-color: var(--amber); box-shadow: 0 0 0 3px rgba(255, 182, 10, 0.15); }
    </style>
<?php require_once __DIR__ . '/../includes/server_clock.php'; marle_server_clock_assets(); ?>
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>
<body class="staff-page staff-roomboard-page staff-superadmin">
<?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

<?php
    $staffPageTitle  = 'Room Board';
    $staffPageNote   = 'Live state of every room, with maintenance overrides.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['Room Board', null]];
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

<div class="main-content">
    <div class="table-card">
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
<script src="../superadmin/includes/notif_poll.js"></script>

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

    fetch('../superadmin/get_room_board.php', { credentials: 'same-origin' })
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
});
</script>

    <!-- Footer — one design for all three roles (includes/staff_footer.php) -->
    <?php $marleFooterTerms = false; require __DIR__ . '/../includes/staff_footer.php'; ?>
</body>
</html>





