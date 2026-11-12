<?php
include "dbconnect.php";
include "../admin/auth_admin.php";
include '../csrf.php';
include '../admin/requests_badge.php';
include(__DIR__ . '/../admin/includes/notif_data.php');
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

    <!-- icon, to be changed -->
    <link rel="icon" href="reception.png">
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">

    <title>Calendar</title>

    <style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap');

*, *::before, *::after { box-sizing: border-box; }
html, body { overflow-x:hidden; background:#f0f2f5; font-family:'DM Sans',sans-serif; }
a { text-decoration:none; color:#ffcc66; }

/* ── Layout ── */
@media (min-width:768px) {
    .navbar { width:230px; height:100vh; position:fixed; top:0; left:0; background:#fff; border-right:1px solid #eee; display:flex; flex-direction:column; }
    .navbar > .container-fluid { height:100%; display:flex; flex-direction:column; }
    .navbar-collapse { display:flex !important; flex-direction:column; flex:1; }
    .content { margin-left:230px; }
}

/* ── Sidebar nav ── */
.nav-link { padding:10px 16px; color:#666; border-radius:10px; position:relative; transition:all .2s; font-size:.9rem; }
.nav-link:hover { background:#fff7e6; color:#933310; }
.nav-link.active { background:#fff3d6; color:#933310 !important; font-weight:600; }
.nav-link i { font-size:17px; }
.notification-badge { background:#ffb60a; color:#fff; font-size:11px; padding:3px 7px; border-radius:20px; }
.logout-btn, #logoutBtn { background:#933310; color:#fff; border:none; padding:10px; border-radius:10px; transition:.2s; font-size:.9rem; }
.logout-btn:hover, #logoutBtn:hover { background:#7a2a0c; color:#fff; border:none; }

/* ── Top header bar ── */
.cal-topbar {
    display:flex; align-items:center; justify-content:space-between;
    padding:24px 32px 8px;
    margin-bottom:8px;
}
.cal-topbar h2 { font-size:1.5rem; font-weight:700; color:#0f172a; margin:0; letter-spacing:-0.02em; }
.cal-add-btn {
    background:linear-gradient(135deg, #933310, #b94015); color:#fff; border:none;
    padding:10px 20px; border-radius:12px;
    font-size:.9rem; font-weight:600; cursor:pointer;
    display:flex; align-items:center; gap:8px; transition:all .3s ease;
    box-shadow: 0 4px 12px rgba(147, 51, 16, 0.2);
}
.cal-add-btn:hover { background:linear-gradient(135deg, #7a2a0c, #933310); transform:translateY(-2px); box-shadow: 0 6px 16px rgba(147, 51, 16, 0.3); }

/* ── Calendar card ── */
.calendar-card {
    background:#ffffff;
    border-radius:20px;
    box-shadow:0 8px 30px rgba(0,0,0,.04);
    border: 1px solid rgba(0,0,0,.02);
    padding:28px 32px 32px;
    margin:12px 24px 24px 24px;
    transition: box-shadow 0.3s ease;
}
@media (max-width:767px) {
    .calendar-card { padding:16px; border-radius:16px; margin:12px; }
    .cal-topbar { padding:16px 16px 8px; }
}

/* ── Legend ── */
.cal-legend { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:24px; padding-bottom:16px; border-bottom:1px solid rgba(0,0,0,.04); }
.cal-legend-item { display:flex; align-items:center; gap:8px; font-size:.85rem; color:#64748b; font-weight:600; }
.cal-legend-chip {
    height:26px; padding:0 12px; border-radius:20px;
    font-size:.75rem; font-weight:700; display:flex; align-items:center; letter-spacing:0.02em;
}

/* ── FullCalendar overrides ── */
.fc { color:#334155; font-family:'DM Sans',sans-serif !important; }
.fc a { text-decoration:none; color:#334155; transition: color 0.2s; }
.fc a:hover { color:#0f172a; }
.fc .fc-toolbar-title { font-size:1.25rem !important; font-weight:700; color:#0f172a; letter-spacing:-0.01em; }

/* Toolbar buttons — pill style */
.fc .fc-button-primary {
    background:#ffffff !important; border:1px solid #e2e8f0 !important;
    color:#475569 !important; border-radius:10px !important;
    font-size:.85rem !important; padding:8px 16px !important;
    font-weight:600; margin:0 4px; box-shadow:0 2px 4px rgba(0,0,0,.02) !important;
    transition:all .2s ease; text-transform: capitalize;
}
.fc .fc-button-primary:hover { background:#f8fafc !important; border-color:#cbd5e1 !important; color:#0f172a !important; transform: translateY(-1px); box-shadow:0 4px 6px rgba(0,0,0,.04) !important; }
.fc .fc-button-primary:not(:disabled).fc-button-active,
.fc .fc-button-primary:not(:disabled):active {
    background:#933310 !important; border-color:#933310 !important; color:#ffffff !important;
    box-shadow: inset 0 2px 4px rgba(0,0,0,.1) !important;
}
.fc .fc-button-primary:disabled { opacity:.5 !important; }
.fc .fc-today-button { background:#ffb60a !important; border-color:#ffb60a !important; color:#ffffff !important; font-weight:700 !important; }
.fc .fc-today-button:hover { background:#f59e0b !important; border-color:#f59e0b !important; box-shadow:0 4px 12px rgba(245, 158, 11, 0.2) !important; }

/* Day headers */
.fc .fc-col-header-cell-cushion {
    font-size:.8rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; padding:12px 0;
}
.fc .fc-daygrid-day-number {
    font-size:.85rem; color:#475569; font-weight:600; padding:8px 12px; transition: color 0.2s;
}
.fc .fc-daygrid-day:hover .fc-daygrid-day-number { color: #0f172a; }
.fc .fc-day-today .fc-daygrid-day-number {
    background:linear-gradient(135deg, #933310, #b94015); color:#ffffff !important; border-radius:50%; width:30px; height:30px;
    display:flex; align-items:center; justify-content:center; margin:6px; box-shadow: 0 4px 8px rgba(147, 51, 16, 0.3);
}
.fc .fc-day-today { background:#fffaf5 !important; }
.fc .fc-daygrid-day-frame { min-height:100px; }

/* Events — soft pastel pill style */
.fc-event {
    border:none !important;
    border-radius:8px !important;
    font-size:.75rem !important;
    font-weight:600 !important;
    padding:4px 8px !important;
    margin:2px 4px !important;
    cursor:pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.fc-event:hover {
    transform: translateY(-1px) scale(1.02);
    box-shadow: 0 4px 8px rgba(0,0,0,0.08);
    z-index: 5;
}
.fc-event-title { font-weight:700 !important; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; letter-spacing:0.01em; }
.fc-event-time { font-size:.7rem !important; opacity:.9; font-weight: 500; }

/* Soft pastel tints for events — override inline bg with lighter version */
.fc-daygrid-event { box-shadow:none !important; }

/* Grid lines */
.fc .fc-scrollgrid { border:1px solid #f1f5f9 !important; border-radius:16px; overflow:hidden; box-shadow: 0 2px 8px rgba(0,0,0,.01); }
.fc .fc-scrollgrid-section > td { border:none !important; }
.fc td, .fc th { border-color:#f1f5f9 !important; }
.fc .fc-col-header { border-bottom:2px solid #f1f5f9 !important; background: #f8fafc; }

/* ── Tooltip ── */
.cal-tooltip {
    position:fixed; z-index:9999;
    background:rgba(15, 23, 42, 0.95); color:#f8fafc;
    border-radius:12px; padding:12px 16px;
    box-shadow:0 10px 25px rgba(0,0,0,.2);
    font-size:.8rem; max-width:250px; pointer-events:none;
    display:none; line-height:1.6; font-weight:500;
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255,255,255,0.1);
}
.cal-tooltip.show { display:block; animation: fadeIn 0.2s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

/* ── Modals ── */
.modal-content { border:none; border-radius:20px; box-shadow:0 20px 40px rgba(0,0,0,.15); }
.modal-header { border-bottom:1px solid #f1f5f9; padding:20px 24px; background:#ffffff; border-radius:20px 20px 0 0; }
.modal-footer { border-top:1px solid #f1f5f9; padding:16px 24px; background:#f8fafc; border-radius:0 0 20px 20px; }
.modal-title { font-weight:700; font-size:1.1rem; color:#0f172a; }
.form-control, .form-select { border-radius:10px; border:1px solid #cbd5e1; font-size:.9rem; padding:10px 14px; background:#f8fafc; transition:all 0.2s; }
.form-control:focus, .form-select:focus { border-color:#ffb60a; box-shadow:0 0 0 4px rgba(255,182,10,.15); background:#ffffff; outline:none; }
.btn { border-radius:10px; font-size:.9rem; font-weight:600; padding:10px 20px; transition:all 0.2s ease; }
.btn-primary, .btn-save { background:linear-gradient(135deg, #933310, #b94015) !important; border:none !important; color:#ffffff !important; box-shadow:0 4px 12px rgba(147, 51, 16, 0.2); }
.btn-primary:hover, .btn-save:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(147, 51, 16, 0.3); background:linear-gradient(135deg, #7a2a0c, #933310) !important; color:#ffffff !important; }
.btn-secondary { background:#f1f5f9 !important; border:1px solid #e2e8f0 !important; color:#475569 !important; box-shadow:none; }
.btn-secondary:hover { background:#e2e8f0 !important; color:#0f172a !important; }
#deleteBtn { background:#fef2f2 !important; border:1px solid #fca5a5 !important; color:#ef4444 !important; }
#deleteBtn:hover { background:#fee2e2 !important; }
#confirmDeleteBtn { background:#ef4444 !important; border:none !important; color:#ffffff !important; }
#yesBtn { background:#933310 !important; border:none !important; color:#ffffff !important; }

/* ── Notif bell ── */
.bi-bell { font-size:19px; padding:7px; border-radius:10px; background:#ffffff; color:#475569; border:1px solid #e2e8f0; transition:all 0.2s; }
.bi-bell:hover { color:#0f172a; border-color:#cbd5e1; background:#f8fafc; }
.dropdown-menu { width:320px; max-height:400px; overflow-y:auto; border-radius:16px; border:1px solid #e2e8f0; box-shadow:0 10px 30px rgba(0,0,0,.1); padding:8px; }
.message-item { display:flex; gap:12px; padding:12px; cursor:pointer; border-bottom:1px solid #f8fafc; border-radius:10px; transition:all 0.2s; }
.message-item:hover { background:#f8fafc; transform:translateY(-1px); }
.top-row { display:flex; align-items:center; gap:8px; }
.bottom-row { display:flex; justify-content:space-between; align-items:center; font-size:13px; margin-top:4px; }
.status { font-size:10px; padding:3px 8px; border-radius:8px; font-weight:600; }
.status.new { background:linear-gradient(135deg, #ffb60a, #f59e0b); color:#ffffff; box-shadow:0 2px 6px rgba(245, 158, 11, 0.2); }
.time { font-size:11px; color:#94a3b8; margin-top:18px; font-weight:500; }
#myInput { border:1px solid #cbd5e1; height:44px; border-radius:12px; font-size:.9rem; background: #f8fafc; padding-left: 16px; transition:all 0.2s; }
#myInput:focus { border-color:#ffb60a; box-shadow:0 0 0 4px rgba(255,182,10,.15); background: #ffffff; outline:none; }
.badge { background-color:#ffb60a; color:#fff; }
.footer { margin-top:2rem; color:#933310; font-size:.82rem; }

/* ── Mobile ── */
@media (max-width:767px) {
    .fc { font-size:.72rem; }
    #calendar { min-height:60vh; }
    .fc .fc-toolbar { flex-wrap:wrap; gap:6px; }
}
    </style>
<?php require_once __DIR__ . '/../includes/server_clock.php'; marle_server_clock_assets(); ?>
</head>

<body class="staff-page staff-admin">
    <!-- Sidebar -->
    <nav class="navbar navbar-expand-md navbar-light p-0">
        <div class="container-fluid flex-md-column align-items-stretch p-0">

            <!-- Responsive Top Bar -->
            <div class="d-flex d-md-none align-items-center justify-content-between w-100 p-3 bg-light">
                <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse"
                    data-bs-target="#sidebarMenu">
                    <i class="fa fa-bars fs-4" style="color: #daad49;"></i>
                </button>

                <img src="logo.png" width="120px">
            </div>

            <!-- Sidebar -->
            <div class="collapse navbar-collapse align-items-stretch" id="sidebarMenu">

                <!-- Desktop Logo -->
                <div class="d-none d-md-block text-center py-4">
                    <img src="logo.png" width="160">
                </div>

                <ul class="navbar-nav flex-md-column w-100 px-3 gap-2">

                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-3" href="../admin/adminDB.php">
                            <i class="bi bi-grid"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center justify-content-between" href="../admin/requests.php">
    <div class="d-flex align-items-center gap-3">
        <i class="bi bi-chat-dots"></i><span>Requests</span>
    </div>
    <?php if ($requestBadgeValue > 0): ?>
        <span class="badge rounded-pill notification-badge pulse"><?= $requestBadgeValue ?></span>
    <?php else: ?>
        <span class="badge rounded-pill notification-badge">0</span>
    <?php endif; ?>
</a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link active d-flex align-items-center gap-3" href="../calendar/calendar.php">
                            <i class="bi bi-calendar"></i>
                            <span>Calendar</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-3" href="../admin/inventory.php">
                            <i class="bi bi-clipboard-check"></i>
                            <span>Inventory</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-3" href="../admin/customerRC.php">
                            <i class="bi bi-people"></i>
                            <span>Guest Record</span>
                        </a>
                    </li>

        <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-3" href="../admin/reviews.php">
                            <i class="bi bi-star"></i>
                            <span>Reviews</span>
                        </a>
                    </li>

                    <li class="nav-item">
    <a class="nav-link d-flex align-items-center gap-3" href="../admin/GuestTokens.php">
        <i class="bi bi-key"></i>
        <span>Guest Tokens</span>
    </a>
</li>
                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-3" href="../admin/transaction_history.php">
                            <i class="bi bi-receipt"></i><span>Transactions</span>
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link d-flex align-items-center gap-3" href="../admin/settings.php">
                            <i class="bi bi-gear"></i>
                            <span>Settings</span>
                        </a>
                    </li>

                </ul>

                <!-- Logout btn-->
                <div class="mt-auto p-3">
                    <button class="btn w-100" id="logoutBtn" data-bs-toggle="modal"
                        data-bs-target="#logoutConfirmModal">
                        <i class="bi bi-box-arrow-left"></i>&nbsp;
                        Logout
                    </button>
                </div>


            </div>
        </div>
    </nav>

   <!-- Top header bar -->
    <div class="content cal-topbar">
        <h2>Calendar</h2>
        <div class="d-flex align-items-center gap-3">
            <input class="form-control" id="myInput" type="text" placeholder="Search..." style="width:200px;">
            <?php include(__DIR__ . '/../admin/includes/notification_dropdown.php'); ?>
            <?php
            $username = $_SESSION['username'];
            $sqlUser = "SELECT picture FROM userstbl WHERE username = '$username' LIMIT 1";
            $resultUser = mysqli_query($conn, $sqlUser);
            if ($resultUser && mysqli_num_rows($resultUser) > 0) {
                $userRow = mysqli_fetch_assoc($resultUser);
                $imageData = $userRow['picture'];
                if (!empty($imageData)) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->buffer($imageData);
                    $base64Image = base64_encode($imageData);
                    $imgSrc = "data:$mimeType;base64,$base64Image";
                } else { $imgSrc = "../img/default.jpg"; }
            } else { $imgSrc = "../img/default.jpg"; }
            ?>
        </div>
    </div>

    <!-- Toast -->
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999;">

        <div id="liveToast" class="toast align-items-center text-white border-0 bg-success" role="alert">
            <div class="d-flex">
                <div class="toast-body" id="toastMessage">
                    Event saved successfully.
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>

    </div>

    <!-- Tooltip element (shared) -->
    <div class="cal-tooltip" id="calTooltip"></div>

    <!-- Calendar -->
    <div class="content main-card">
        <div class="calendar-card">
            <!-- Legend + Add button row -->
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3 pb-3" style="border-bottom:1px solid #f0f0f0;">
                <div class="cal-legend" style="border-bottom:none;padding-bottom:0;margin-bottom:0;">
                    <div class="cal-legend-item">
                        <span class="cal-legend-chip" style="background:#fff3d6;color:#b45309;">Manual Events</span>
                    </div>
                    <div class="cal-legend-item">
                        <span class="cal-legend-chip" style="background:#fde8e0;color:#933310;">Bookings (read-only)</span>
                    </div>
                </div>
                <button class="cal-add-btn" onclick="openAddModal()">
                    <i class="bi bi-plus-lg"></i> Add new event
                </button>
            </div>
            <div id="calendar"></div>
        </div>
    </div>

    <!-- Event modal -->
    <div class="modal fade" id="eventModal">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="eventForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Manage Event</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="padding:20px;">
                        <input type="hidden" id="event_id">
                        <input type="hidden" id="event_date">

                        <div class="mb-3">
                            <label class="form-label fw-500" style="font-size:.85rem;color:#555;">Title</label>
                            <input type="text" id="title" class="form-control" placeholder="Event name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-500" style="font-size:.85rem;color:#555;">Description</label>
                            <textarea id="description" class="form-control" rows="2" placeholder="Optional notes"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-500" style="font-size:.85rem;color:#555;">Time</label>
                            <input type="time" id="event_time" class="form-control" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-500" style="font-size:.85rem;color:#555;">Date From</label>
                                <input type="date" id="date_from" class="form-control">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-500" style="font-size:.85rem;color:#555;">Date To</label>
                                <input type="date" id="date_to" class="form-control">
                            </div>
                        </div>
                        <div class="mb-1">
                            <label class="form-label fw-500" style="font-size:.85rem;color:#555;">Event Color</label>
                            <select name="color" id="color" class="form-select" required>
                                <option value="#ffb60a" selected>Manual Event (Gold)</option>
                                <option value="#933310">Booking (Maroon)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer" style="padding:14px 20px;">
                        <button type="button" id="deleteBtn" class="btn d-none">Delete</button>
                        <button type="submit" class="btn btn-save">Save Event</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap & FullCalendar CDN JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>

    <!-- Calendar + Add & Edit JS -->
    <script>
document.addEventListener('DOMContentLoaded', function () {
    const tooltip = document.getElementById('calTooltip');

    const calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
        initialView: window.innerWidth < 768 ? 'listWeek' : 'dayGridMonth',
        height: 'auto',
        handleWindowResize: true,
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,timeGridWeek,listWeek'
        },
        selectable: true,
        editable: true,   // per-event editable:false overrides for bookings

        // The HOTEL's "today" (includes/server_clock.php). Left unset, FullCalendar
        // asks the viewing device: on a phone in another timezone or with a skewed
        // clock the highlighted day — and the "today" button — were the device's day.
        // A function, so it is still right after midnight on a page left open.
        now: window.MarleClock ? function () { return MarleClock.todayDate(); } : undefined,
        events: '../calendar/load_events.php',

        // ── Click on empty date → open add-event modal ──
        dateClick: function (info) {
            if (info.jsEvent.target.closest('.fc-event')) return;
            resetForm();
            document.getElementById('event_date').value  = info.dateStr;
            document.getElementById('date_from').value   = info.dateStr;
            document.getElementById('date_to').value     = '';
            new bootstrap.Modal('#eventModal').show();
        },

        // ── Click on existing event ──
        eventClick: function (info) {
            const ev    = info.event;
            const props = ev.extendedProps;

            // Booking events: show read-only info modal
            if (props.source === 'booking') {
                document.getElementById('viewRoomType').textContent   = props.room_type   || '—';
                document.getElementById('viewRoomNumber').textContent = props.room_number || 'TBA';
                document.getElementById('viewGuest').textContent      = props.guest_name  || '—';
                document.getElementById('viewPersons').textContent    = props.persons     || '—';
                document.getElementById('viewType').textContent       = props.booking_type === 'hourly' ? 'Hourly' : 'Nightly';
                document.getElementById('viewDates').textContent      = props.tooltip     || '';
                new bootstrap.Modal('#viewBookingModal').show();
                return;
            }

            // Manual event: open edit form
            const dateObj = ev.start;
            document.getElementById('event_id').value          = ev.id;
            document.getElementById('title').value             = ev.title;
            document.getElementById('description').value       = props.description || '';
            document.getElementById('event_date').value        = ev.startStr.split('T')[0];
            document.getElementById('event_time').value        = String(dateObj.getHours()).padStart(2,'0') + ':' + String(dateObj.getMinutes()).padStart(2,'0');
            document.getElementById('date_from').value         = ev.startStr.split('T')[0];
            document.getElementById('date_to').value           = ev.end ? ev.endStr.split('T')[0] : '';
            document.getElementById('color').value             = ev.backgroundColor;
            document.getElementById('deleteBtn').classList.remove('d-none');
            new bootstrap.Modal('#eventModal').show();
        },

        // ── Tooltip on hover for booking events ──
        eventMouseEnter: function (info) {
            const props = info.event.extendedProps;
            if (props.source !== 'booking') return;
            tooltip.textContent = props.tooltip || info.event.title;
            tooltip.classList.add('show');
        },
        eventMouseLeave: function () {
            tooltip.classList.remove('show');
        },

        // ── Move tooltip with cursor ──
        eventDidMount: function (info) {
            info.el.addEventListener('mousemove', function (e) {
                tooltip.style.left = (e.pageX + 12) + 'px';
                tooltip.style.top  = (e.pageY - 220) + 'px';
            });
        },

        windowResize: function (arg) {
            if (arg.view.type !== 'listWeek' && window.innerWidth < 768) {
                calendar.changeView('listWeek');
            }
        },
    });

    calendar.render();

    // ── Save event ──
    document.getElementById('eventForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const fd = new FormData();
        fd.append('id',          document.getElementById('event_id').value);
        fd.append('title',       document.getElementById('title').value);
        fd.append('description', document.getElementById('description').value);
        fd.append('time',        document.getElementById('event_time').value);
        fd.append('date_from',   document.getElementById('date_from').value);
        fd.append('date_to',     document.getElementById('date_to').value);
        fd.append('color',       document.getElementById('color').value);
        fd.append('csrf_token',  '<?= htmlspecialchars(csrf_token()) ?>');
        fetch('../calendar/save_event.php', { method:'POST', body:fd })
            .then(r => r.text())
            .then(() => {
                calendar.refetchEvents();
                showToast('Event saved!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('eventModal')).hide();
            });
    });

    document.getElementById('date_from').addEventListener('change', function () {
        if (!document.getElementById('date_to').value) {
            document.getElementById('date_to').value = this.value;
        }
    });

    // ── Delete event ──
    document.getElementById('deleteBtn').addEventListener('click', function () {
        const deleteModalEl = document.getElementById('deleteConfirmModal');
        const deleteModal   = new bootstrap.Modal(deleteModalEl);
        let seconds = 5, interval;
        const confirmBtn = document.getElementById('confirmDeleteBtn');
        confirmBtn.disabled   = true;
        confirmBtn.innerText  = `Yes (${seconds})`;
        interval = setInterval(() => {
            seconds--;
            if (seconds > 0) { confirmBtn.innerText = `Yes (${seconds})`; }
            else { clearInterval(interval); confirmBtn.disabled = false; confirmBtn.innerText = 'Yes'; }
        }, 1000);
        confirmBtn.onclick = function () {
            const deleteData = new FormData();
            deleteData.append('id', document.getElementById('event_id').value);
            deleteData.append('csrf_token', '<?= htmlspecialchars(csrf_token()) ?>');
            fetch('../calendar/delete_event.php', { method: 'POST', body: deleteData })
                .then(() => {
                    calendar.refetchEvents();
                    showToast('Event deleted!', 'warning');
                    bootstrap.Modal.getInstance(document.getElementById('eventModal')).hide();
                    deleteModal.hide();
                });
        };
        deleteModalEl.addEventListener('hidden.bs.modal', function () {
            clearInterval(interval);
            confirmBtn.disabled  = true;
            confirmBtn.innerText = 'Yes (5)';
        }, { once: true });
        deleteModal.show();
    });

    function resetForm() {
        document.getElementById('eventForm').reset();
        document.getElementById('event_id').value = '';
        document.getElementById('deleteBtn').classList.add('d-none');
    }
});

function showToast(message, type = 'success') {
    const el   = document.getElementById('liveToast');
    const body = document.getElementById('toastMessage');
    body.innerText = message;
    el.classList.remove('bg-success','bg-danger','bg-warning');
    el.classList.add(type === 'success' ? 'bg-success' : 'bg-danger');
    new bootstrap.Toast(el, { delay:3000, autohide:true }).show();
}

function openAddModal() {
    const form = document.getElementById('eventForm');
    form.reset();
    document.getElementById('event_id').value = '';
    document.getElementById('deleteBtn').classList.add('d-none');
    // The HOTEL's date (includes/server_clock.php), not this PC's — and never
    // toISOString(), which is UTC and defaulted a new event to yesterday for every
    // hour before 08:00 in UTC+8.
    const _calNow = new Date();   // marle-clock-ok: fallback for a page whose clock asset is missing; the line below prefers MarleClock
    const _calLocal = _calNow.getFullYear() + '-' + String(_calNow.getMonth() + 1).padStart(2, '0') + '-' + String(_calNow.getDate()).padStart(2, '0');
    const today = window.MarleClock ? MarleClock.dayIso(0) : _calLocal;
    document.getElementById('date_from').value = today;
    document.getElementById('date_to').value   = today;
    new bootstrap.Modal('#eventModal').show();
}
    </script>

    <!-- Read-only booking info modal -->
    <div class="modal fade" id="viewBookingModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:14px; overflow:hidden;">
                <div class="modal-header" style="background:#933310; color:#fff;">
                    <h5 class="modal-title"><i class="bi bi-calendar-check me-2"></i>Booking Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><th style="width:40%">Guest</th><td id="viewGuest">—</td></tr>
                        <tr><th>Room Type</th><td id="viewRoomType">—</td></tr>
                        <tr><th>Room No.</th><td id="viewRoomNumber">—</td></tr>
                        <tr><th>Persons</th><td id="viewPersons">—</td></tr>
                        <tr><th>Type</th><td id="viewType">—</td></tr>
                        <tr><th>Details</th><td id="viewDates" style="font-size:.85rem;color:#555;">—</td></tr>
                    </table>
                    <p class="text-muted small mt-3 mb-0"><i class="bi bi-lock me-1"></i>Booking events are read-only. Manage in <a href="../admin/requests.php" style="color:#933310;">Requests</a>.</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm delete modal -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title text-danger">Confirm Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body text-center">
                    <p class="fs-5 mb-0">
                        Are you sure you want to delete this event?
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" id="noBtn" class="btn btn-secondary" data-bs-dismiss="modal">
                        No
                    </button>
                    <button type="button" id="confirmDeleteBtn" class="btn btn-danger" disabled>
                        Yes (5)
                    </button>
                </div>

            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="content">
        <div class="container">
            <div class="d-flex justify-content-between">
                <p class="footer">© 2026 Marle Hotel & Restaurant. All Rights Reserved.</p>
                <div class="d-flex">
                    <a href="#" class="footer text-decoration-none me-4" data-bs-toggle="modal"
                        data-bs-target="#termsModal">Terms of use</a>
                    <!-- <a href="#" class="text-decoration-none">Data Privacy Policy</a> -->
                </div>
            </div>
        </div>
    </div>

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
                    <a href="../logout.php" id="yesBtn" class="btn">Yes</a>
                </div>
            </div>
        </div>
    </div>

<script src="../admin/includes/notif_poll.js"></script>
</body>

</html>


