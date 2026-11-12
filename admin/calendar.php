<?php
include "../dbconnect.php";

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.calendar');
include "../admin/auth_admin.php";
include "../csrf.php";
include '../admin/requests_badge.php';
include(__DIR__ . '/../admin/includes/notif_data.php');
// The room types the walk-in form offers — read from roomstbl, not hardcoded,
// so a type added to the hotel appears here without touching this page.
require_once __DIR__ . '/../includes/walkin_booking.php';
$walkinRoomTypes = marle_walkin_room_types($conn);
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

    <!-- icon, to be changed -->
    <?php require __DIR__ . '/../includes/staff_icons.php'; ?>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
    <link rel="stylesheet" href="../css/staff-footer.css?v=20261021">

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

/* Walk-in cash booking — green, because it is money taken at the desk, not an
   event being added to the calendar. */
.cal-walkin-btn {
    background:linear-gradient(135deg, #146c43, #198754);
    box-shadow: 0 4px 12px rgba(25, 135, 84, 0.22);
}
.cal-walkin-btn:hover { background:linear-gradient(135deg, #0f5132, #146c43); box-shadow: 0 6px 16px rgba(25, 135, 84, 0.3); }
.walkin-quote {
    background:#fffbf0; border:1px solid #f0d9bb; border-radius:12px;
    padding:12px 16px;
}
.walkin-quote-row {
    display:flex; justify-content:space-between; align-items:baseline; gap:12px;
    font-size:.88rem; color:#6b5a4a; padding:5px 0;
}
.walkin-quote-row strong { color:#0f172a; font-size:.95rem; }
.walkin-note { font-size:.78rem; color:#8c8279; line-height:1.5; margin-top:8px; }
#walkinModal .form-label { font-size:.82rem; font-weight:600; color:#555; }
#walkinModal .form-text { font-size:.75rem; }
/* staff-calendar.css paints every .modal-header with the calendar surface
   (!important), which would leave this modal's white header text invisible.
   The walk-in header is maroon on purpose — the money screens use it. */
#walkinModal .modal-header { background:#933310 !important; color:#fff !important; }
#walkinModal .modal-header .modal-title { color:#fff !important; }
#walkinModal .modal-header p { color:rgba(255,255,255,.85) !important; }
#walkinModal .modal-header .btn-close { filter:invert(1) grayscale(1) brightness(2); opacity:.9; }

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
    <link rel="stylesheet" href="../css/staff-calendar.css?v=20261021">
    <link rel="stylesheet" href="../css/staff-logout.css">
<?php require_once __DIR__ . '/../includes/server_clock.php'; marle_server_clock_assets(); ?>
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>

<body class="staff-page staff-calendar-page staff-admin">
    <?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

   <!-- Top header bar -->
    <?php
    $staffPageTitle  = 'Calendar';
    $staffPageNote   = 'Manual events and read-only bookings in one month view.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['Calendar', null]];
    $staffPageActions = '<input class="form-control" id="myInput" type="text" placeholder="Search..." style="width:200px;">';
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

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
                <div class="d-flex flex-wrap gap-2">
                    <button class="cal-add-btn cal-walkin-btn" onclick="openWalkinModal()">
                        <i class="bi bi-cash-coin"></i> Walk-in booking
                    </button>
                    <button class="cal-add-btn" onclick="openAddModal()">
                        <i class="bi bi-plus-lg"></i> Add new event
                    </button>
                </div>
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
        events: '../admin/load_events.php',

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
        fd.append('date_to',     document.getElementById('date_to').value);        fd.append('color',        document.getElementById('color').value);
        fd.append('csrf_token',   '<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>');
        fetch('../admin/save_event.php', { method:'POST', body:fd })
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
            deleteData.append('csrf_token', '<?= htmlspecialchars(csrf_token(), ENT_QUOTES, "UTF-8") ?>');
            fetch('../admin/delete_event.php', { method: 'POST', body: deleteData })
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

    <!-- Walk-in booking: availability + price from the server, then a POST. -->
    <script>
(function () {
    const WALKIN_CSRF = '<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>';

    function ready(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }

    ready(function () {
        const modalEl = document.getElementById('walkinModal');
        if (!modalEl) return;

        const el = id => document.getElementById(id);
        const money = n => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        let timer = null;
        let lastKey = '';
        let saving = false;

        // Date arithmetic on the input's own YYYY-MM-DD string, in UTC noon, so a
        // device in another timezone cannot turn "tomorrow" into today or the day
        // after. Only the *default* arrives from MarleClock (the hotel's today).
        function addDay(iso) {
            const p = String(iso || '').split('-').map(Number);
            if (p.length !== 3 || !p[0]) return iso;
            const d = new Date(Date.UTC(p[0], p[1] - 1, p[2], 12) + 86400000);
            // Read the UTC parts back out — NOT toISOString(): stringifying a date
            // can name the day before it east of Greenwich, and this value is shown
            // to the clerk as the departure date.
            return d.getUTCFullYear() + '-'
                 + String(d.getUTCMonth() + 1).padStart(2, '0') + '-'
                 + String(d.getUTCDate()).padStart(2, '0');
        }
        function todayIso() {
            if (window.MarleClock) return MarleClock.dayIso(0);
            const n = new Date();   // marle-clock-ok: only reached when the clock asset is missing
            return n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0') + '-' + String(n.getDate()).padStart(2, '0');
        }
        function showError(msg) {
            const box = el('wiError');
            box.textContent = msg || '';
            box.style.display = msg ? '' : 'none';
            // The error sits at the top of a scrolling body: bring it into view so
            // a refusal is never invisible behind the fields someone just filled.
            if (msg) modalEl.querySelector('.modal-body').scrollTop = 0;
        }
        // The button's own text, read before the first busy state can replace it:
        // the English | Tagalog switch rewrites the markup server-side, so the
        // button says "Itala ang cash booking" and the restore has to say it back.
        const SUBMIT_LABEL = (el('wiSubmit').textContent || 'Record cash booking').trim();

        function setBusy(busy, label) {
            const btn = el('wiSubmit');
            btn.disabled = !!busy;
            btn.innerHTML = busy
                ? '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + (label || 'Saving…')
                : '<i class="bi bi-cash-coin me-1"></i>' + SUBMIT_LABEL;
        }
        function syncStayType() {
            const hourly = el('wiStayType').value === 'hourly';
            el('wiCheckOutWrap').classList.toggle('d-none', hourly);
            el('wiInTimeWrap').classList.toggle('d-none', !hourly);
            el('wiOutTimeWrap').classList.toggle('d-none', !hourly);
            el('wiCheckOut').required = !hourly;
        }

        function refresh() {
            const type    = el('wiRoomType').value;
            const hourly  = el('wiStayType').value === 'hourly';
            const checkIn = el('wiCheckIn').value;
            const checkOut = hourly ? checkIn : el('wiCheckOut').value;
            const inTime  = el('wiInTime').value || '14:00';
            const outTime = el('wiOutTime').value || '12:00';

            if (!type || !checkIn || !checkOut) return;
            if (!hourly && checkOut <= checkIn) {
                showError('Departure has to be after arrival.');
                return;
            }

            const key = [type, checkIn, checkOut, hourly ? 'h' : 'd', inTime, outTime].join('|');
            lastKey = key;
            setBusy(true, 'Checking rooms…');

            const params = new URLSearchParams({
                room_type: type, check_in: checkIn, check_out: checkOut,
                booking_type: hourly ? 'hourly' : 'daily',
                check_in_time: inTime, check_out_time: outTime
            });

            fetch('../admin/walkin_rooms.php?' + params.toString(), { credentials: 'same-origin', cache: 'no-store' })
                .then(r => r.json())
                .then(d => {
                    if (key !== lastKey) return;   // a newer request already answered one
                    setBusy(false);
                    const sel = el('wiRoomNumber');
                    const previous = sel.value;
                    sel.innerHTML = '';

                    if (!d || d.success !== true) {
                        sel.innerHTML = '<option value="">—</option>';
                        el('wiRoomHint').textContent = '';
                        showError((d && d.message) ? d.message : 'Could not check room availability.');
                        return;
                    }

                    const rooms = d.rooms || [];
                    if (!rooms.length) {
                        sel.innerHTML = '<option value="">No rooms free</option>';
                        el('wiRoomHint').textContent = 'Every ' + type + ' room is taken for these dates.';
                        showError('No ' + type + ' room is free for those dates. Try another room type or different dates.');
                    } else {
                        rooms.forEach(function (room) {
                            const opt = document.createElement('option');
                            opt.value = room.room_number;
                            opt.textContent = 'Room ' + room.room_number + ' · up to ' + room.max_persons + ' guests';
                            sel.appendChild(opt);
                        });
                        if (rooms.some(r => r.room_number === previous)) sel.value = previous;
                        el('wiRoomHint').textContent = rooms.length + ' of the ' + type + ' rooms are free.';
                        showError('');
                    }

                    if (d.quote) {
                        el('wiTotal').textContent = money(d.quote.total);
                        el('wiDeposit').textContent = money(d.quote.deposit);
                        el('wiRate').textContent = '(' + Number(d.quote.deposit_rate) + '% of the total)';
                    }
                })
                .catch(function () {
                    if (key !== lastKey) return;
                    setBusy(false);
                    showError('Could not reach the server. Check the connection and try again.');
                });
        }

        function scheduleRefresh() {
            clearTimeout(timer);
            timer = setTimeout(refresh, 250);
        }

        // The button in the page header calls this.
        window.openWalkinModal = function () {
            el('walkinForm').reset();
            el('wiStayType').value = 'daily';
            el('wiPersons').value = '1';
            el('wiInTime').value = '14:00';
            el('wiOutTime').value = '18:00';
            const today = todayIso();
            el('wiCheckIn').value = today;
            el('wiCheckOut').value = addDay(today);
            el('wiRoomNumber').innerHTML = '<option value="">Checking…</option>';
            el('wiRoomHint').textContent = '';
            el('wiTotal').textContent = '—';
            el('wiDeposit').textContent = '—';
            el('wiRate').textContent = '';
            showError('');
            setBusy(false);
            syncStayType();
            new bootstrap.Modal(modalEl).show();
            refresh();
        };

        // Digits only, 11 of them, exactly as the public booking form does it.
        el('wiGuestPhone').addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 11);
        });

        ['wiStayType', 'wiRoomType', 'wiCheckIn', 'wiCheckOut', 'wiInTime', 'wiOutTime'].forEach(function (id) {
            el(id).addEventListener('change', function () {
                if (id === 'wiStayType') {
                    syncStayType();
                    if (el('wiStayType').value !== 'hourly' && el('wiCheckOut').value <= el('wiCheckIn').value) {
                        el('wiCheckOut').value = addDay(el('wiCheckIn').value);
                    }
                }
                if (id === 'wiCheckIn' && el('wiStayType').value !== 'hourly' && el('wiCheckOut').value <= el('wiCheckIn').value) {
                    el('wiCheckOut').value = addDay(el('wiCheckIn').value);
                }
                scheduleRefresh();
            });
        });

        el('walkinForm').addEventListener('submit', function (e) {
            e.preventDefault();
            if (saving) return;
            showError('');

            const name  = el('wiGuestName').value.trim();
            const phone = el('wiGuestPhone').value.trim();
            const email = el('wiGuestEmail').value.trim();
            const room  = el('wiRoomNumber').value;

            if (name === '')    { showError('Enter the guest’s full name.'); el('wiGuestName').focus(); return; }
            // The website's own phone rule (11 digits) — the server re-checks it.
            if (!/^\d{11}$/.test(phone)) {
                showError('Phone number must contain exactly 11 digits (for example, 09171234567).');
                el('wiGuestPhone').focus();
                return;
            }
            if (email !== '' && !/^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/.test(email)) {
                showError('Please enter a valid email address (for example, juan@email.com) — or leave it blank for a guest with none.');
                el('wiGuestEmail').focus();
                return;
            }
            if (!room) { showError('Choose a room.'); el('wiRoomNumber').focus(); return; }

            const hourly = el('wiStayType').value === 'hourly';
            const fd = new FormData();
            fd.append('guest_name', name);
            fd.append('guest_phone', phone);
            fd.append('guest_email', email);
            fd.append('stay_type', el('wiStayType').value);
            fd.append('booking_type', hourly ? 'hourly' : 'daily');
            fd.append('room_type', el('wiRoomType').value);
            fd.append('room_number', room);
            fd.append('persons', el('wiPersons').value);
            fd.append('check_in', el('wiCheckIn').value);
            fd.append('check_out', hourly ? el('wiCheckIn').value : el('wiCheckOut').value);
            fd.append('check_in_time', el('wiInTime').value);
            fd.append('check_out_time', el('wiOutTime').value);
            fd.append('special_requests', el('wiSpecial').value);
            fd.append('csrf_token', WALKIN_CSRF);

            saving = true;
            setBusy(true, 'Saving…');

            fetch('../admin/process_walkin_booking.php', {
                method: 'POST', body: fd, credentials: 'same-origin'
            })
                .then(r => r.json().catch(function () {
                    throw new Error('unreadable');
                }))
                .then(function (d) {
                    saving = false;
                    setBusy(false);
                    if (!d || d.success !== true) {
                        showError((d && d.message) ? d.message : 'The booking could not be saved.');
                        return;
                    }
                    bootstrap.Modal.getInstance(modalEl).hide();
                    showToast(d.message || 'Walk-in recorded.', 'success');
                    // Land on the Bookings tab, where the booking now waits for
                    // approval — the walk-in should not be left "somewhere".
                    // The sentence goes with it: this page is replaced a moment
                    // from now, and "A confirmation email is on its way to …" is
                    // too useful for the desk to miss (booking_watch.php shows it
                    // again on arrival).
                    setTimeout(function () {
                        try { sessionStorage.setItem('marleRequestsTab', 'tabBookings'); } catch (err) {}
                        try { sessionStorage.setItem('marleWalkinToast', d.message || 'Walk-in recorded.'); } catch (err) {}
                        window.location.href = d.requests_url || '../admin/requests.php';
                    }, 1200);
                })
                .catch(function () {
                    saving = false;
                    setBusy(false);
                    showError('Could not reach the server. Nothing was saved — check Requests → Bookings before trying again.');
                });
        });
    });
})();
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

    <!-- Walk-in booking — a guest at the desk paying the deposit in cash.
         Managers and the owner only: the money is recorded as received, which is
         the same permission (verify_deposits) that unlocks any deposit. -->
    <div class="modal fade" id="walkinModal" tabindex="-1" aria-labelledby="walkinModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background:#933310; color:#fff;">
                    <div>
                        <h5 class="modal-title" id="walkinModalLabel" style="color:#fff;">Walk-in booking</h5>
                        <p class="mb-0" style="font-size:.78rem; opacity:.85;">A guest at the desk, paying the reservation deposit in cash.</p>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <!-- The form lives inside the body and the footer button points at it
                     with form="walkinForm". A <form> wrapping header + body + footer made
                     the body taller than the dialog on a short laptop screen, so the
                     footer — and the Record button — was clipped off the bottom. -->
                <div class="modal-body">
                    <div id="wiError" class="alert alert-danger py-2 mb-3" style="display:none;"></div>
                    <form id="walkinForm" novalidate>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="wiGuestName">Guest name</label>
                                <input type="text" class="form-control" id="wiGuestName" maxlength="255" required autocomplete="off">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="wiGuestPhone">Phone</label>
                                <input type="tel" class="form-control" id="wiGuestPhone" maxlength="11" inputmode="numeric"
                                       pattern="\d{11}" placeholder="11 digits, e.g. 09171234567" required autocomplete="off">
                                <div class="form-text">Exactly 11 digits — the same rule the booking website uses.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="wiGuestEmail">Email <span class="text-muted fw-normal">(optional — nothing is emailed without one)</span></label>
                                <input type="email" class="form-control" id="wiGuestEmail" maxlength="255" autocomplete="off">
                                <div class="form-text">The domain is really checked — a mistyped address would never receive the booking email.</div>
                            </div>
                        </div>

                        <hr class="my-3">

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="wiStayType">Stay type</label>
                                <select class="form-select" id="wiStayType">
                                    <option value="daily">Overnight</option>
                                    <option value="hourly">Hourly</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="wiCheckIn">Arrival date</label>
                                <input type="date" class="form-control" id="wiCheckIn" required>
                            </div>
                            <div class="col-md-4" id="wiCheckOutWrap">
                                <label class="form-label" for="wiCheckOut">Departure date</label>
                                <input type="date" class="form-control" id="wiCheckOut" required>
                            </div>
                            <div class="col-md-4 d-none" id="wiInTimeWrap">
                                <label class="form-label" for="wiInTime">Arrival time</label>
                                <input type="time" class="form-control" id="wiInTime" value="14:00">
                            </div>
                            <div class="col-md-4 d-none" id="wiOutTimeWrap">
                                <label class="form-label" for="wiOutTime">Departure time</label>
                                <input type="time" class="form-control" id="wiOutTime" value="18:00">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="wiRoomType">Room type</label>
                                <select class="form-select" id="wiRoomType">
<?php foreach ($walkinRoomTypes as $walkinType): ?>
                                    <option value="<?= htmlspecialchars($walkinType, ENT_QUOTES) ?>"><?= htmlspecialchars($walkinType) ?></option>
<?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="wiRoomNumber">Room</label>
                                <select class="form-select" id="wiRoomNumber" required>
                                    <option value="">Checking…</option>
                                </select>
                                <div class="form-text" id="wiRoomHint"></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="wiPersons">Guests</label>
                                <input type="number" class="form-control" id="wiPersons" min="1" max="20" value="1" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="wiSpecial">Special requests</label>
                                <input type="text" class="form-control" id="wiSpecial" maxlength="500" placeholder="Optional" autocomplete="off">
                            </div>
                        </div>

                        <div class="walkin-quote mt-3">
                            <div class="walkin-quote-row"><span>Booking total</span><strong id="wiTotal">—</strong></div>
                            <div class="walkin-quote-row"><span>Deposit due now <span id="wiRate" class="text-muted"></span></span><strong id="wiDeposit">—</strong></div>
                            <div class="walkin-quote-row"><span>Method</span><span class="badge bg-success">Cash at the front desk</span></div>
                            <p class="walkin-note mb-0">The cash is recorded as received and verified against the person who took it. The booking still needs approving in Requests → Bookings, and that is what puts it on this calendar.</p>
                        </div>

                        <?php /* Enter in any field submits through this one; the visible
                                 button lives in the footer, outside the form. */ ?>
                        <button type="submit" class="visually-hidden" tabindex="-1" aria-hidden="true">Record cash booking</button>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-save" id="wiSubmit" form="walkinForm">
                        <i class="bi bi-cash-coin me-1"></i>Record cash booking
                    </button>
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
                    <a href="../logout.php" id="yesBtn" class="btn">Yes</a>
                </div>
            </div>
        </div>
    </div>

<script src="../admin/includes/notif_poll.js"></script>
</body>

</html>



