<?php
// TODO: Employee limited permissions need deeper checks on this page/action.
include "../dbconnect.php";

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.calendar');
include "../employee/auth_employee.php";
include "../employee/requests_badge.php";
include "../employee/includes/notif_data.php";
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

@media (min-width:768px) {
    .navbar { width:230px; height:100vh; position:fixed; top:0; left:0; background:#fff; border-right:1px solid #eee; display:flex; flex-direction:column; }
    .navbar > .container-fluid { height:100%; display:flex; flex-direction:column; }
    .navbar-collapse { display:flex !important; flex-direction:column; flex:1; }
    .content { margin-left:230px; }
}

.nav-link { padding:10px 16px; color:#666; border-radius:10px; position:relative; transition:all .2s; font-size:.9rem; }
.nav-link:hover { background:#fff7e6; color:#933310; }
.nav-link.active { background:#fff3d6; color:#933310 !important; font-weight:600; }
.nav-link i { font-size:17px; }
.notification-badge { background:#ffb60a; color:#fff; font-size:11px; padding:3px 7px; border-radius:20px; }
.logout-btn, #logoutBtn { background:#933310; color:#fff; border:none; padding:10px; border-radius:10px; transition:.2s; font-size:.9rem; }
.logout-btn:hover, #logoutBtn:hover { background:#7a2a0c; color:#fff; border:none; }

.cal-topbar {
    display:flex; align-items:center; justify-content:space-between;
    padding:24px 32px 8px;
    margin-bottom:8px;
}
.cal-topbar h2 { font-size:1.5rem; font-weight:700; color:#0f172a; margin:0; letter-spacing:-0.02em; }

.calendar-card {
    background:#ffffff;
    border-radius:20px;
    box-shadow:0 8px 30px rgba(0,0,0,.04);
    border:1px solid rgba(0,0,0,.02);
    padding:28px 32px 32px;
    margin:12px 24px 24px 24px;
    transition:box-shadow 0.3s ease;
}
@media (max-width:767px) {
    .calendar-card { padding:16px; border-radius:16px; margin:12px; }
    .cal-topbar { padding:16px 16px 8px; }
}

.cal-legend { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:24px; padding-bottom:16px; border-bottom:1px solid rgba(0,0,0,.04); }
.cal-legend-item { display:flex; align-items:center; gap:8px; font-size:.85rem; color:#64748b; font-weight:600; }
.cal-legend-chip {
    height:26px; padding:0 12px; border-radius:20px;
    font-size:.75rem; font-weight:700; display:flex; align-items:center; letter-spacing:0.02em;
}

.fc { color:#334155; font-family:'DM Sans',sans-serif !important; }
.fc a { text-decoration:none; color:#334155; transition:color 0.2s; }
.fc a:hover { color:#0f172a; }
.fc .fc-toolbar-title { font-size:1.25rem !important; font-weight:700; color:#0f172a; letter-spacing:-0.01em; }
.fc .fc-button-primary {
    background:#ffffff !important; border:1px solid #e2e8f0 !important;
    color:#475569 !important; border-radius:10px !important;
    font-size:.85rem !important; padding:8px 16px !important;
    font-weight:600; margin:0 4px; box-shadow:0 2px 4px rgba(0,0,0,.02) !important;
    transition:all .2s ease; text-transform:capitalize;
}
.fc .fc-button-primary:hover { background:#f8fafc !important; border-color:#cbd5e1 !important; color:#0f172a !important; transform:translateY(-1px); box-shadow:0 4px 6px rgba(0,0,0,.04) !important; }
.fc .fc-button-primary:not(:disabled).fc-button-active,
.fc .fc-button-primary:not(:disabled):active {
    background:#933310 !important; border-color:#933310 !important; color:#ffffff !important;
    box-shadow:inset 0 2px 4px rgba(0,0,0,.1) !important;
}
.fc .fc-button-primary:disabled { opacity:.5 !important; }
.fc .fc-today-button { background:#ffb60a !important; border-color:#ffb60a !important; color:#ffffff !important; font-weight:700 !important; }
.fc .fc-today-button:hover { background:#f59e0b !important; border-color:#f59e0b !important; box-shadow:0 4px 12px rgba(245, 158, 11, 0.2) !important; }

.fc .fc-col-header-cell-cushion {
    font-size:.8rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; padding:12px 0;
}
.fc .fc-daygrid-day-number {
    font-size:.85rem; color:#475569; font-weight:600; padding:8px 12px; transition:color 0.2s;
}
.fc .fc-daygrid-day:hover .fc-daygrid-day-number { color:#0f172a; }
.fc .fc-day-today .fc-daygrid-day-number {
    background:linear-gradient(135deg, #933310, #b94015); color:#ffffff !important; border-radius:50%; width:30px; height:30px;
    display:flex; align-items:center; justify-content:center; margin:6px; box-shadow:0 4px 8px rgba(147, 51, 16, 0.3);
}
.fc .fc-day-today { background:#fffaf5 !important; }
.fc .fc-daygrid-day-frame { min-height:100px; }

.fc-event {
    border:none !important;
    border-radius:8px !important;
    font-size:.75rem !important;
    font-weight:600 !important;
    padding:4px 8px !important;
    margin:2px 4px !important;
    cursor:pointer;
    transition:transform 0.2s ease, box-shadow 0.2s ease;
}
.fc-event:hover {
    transform:translateY(-1px) scale(1.02);
    box-shadow:0 4px 8px rgba(0,0,0,0.08);
    z-index:5;
}
.fc-event-title { font-weight:700 !important; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; letter-spacing:0.01em; }
.fc-event-time { font-size:.7rem !important; opacity:.9; font-weight:500; }
.fc-daygrid-event { box-shadow:none !important; }

.fc .fc-scrollgrid { border:1px solid #f1f5f9 !important; border-radius:16px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.01); }
.fc .fc-scrollgrid-section > td { border:none !important; }
.fc td, .fc th { border-color:#f1f5f9 !important; }
.fc .fc-col-header { border-bottom:2px solid #f1f5f9 !important; background:#f8fafc; }

.cal-tooltip {
    position:fixed; z-index:9999;
    background:rgba(15, 23, 42, 0.95); color:#f8fafc;
    border-radius:12px; padding:12px 16px;
    box-shadow:0 10px 25px rgba(0,0,0,.2);
    font-size:.8rem; max-width:250px; pointer-events:none;
    display:none; line-height:1.6; font-weight:500;
    backdrop-filter:blur(4px);
    border:1px solid rgba(255,255,255,0.1);
}
.cal-tooltip.show { display:block; animation:fadeIn 0.2s ease; }
@keyframes fadeIn { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:translateY(0); } }

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
#yesBtn { background:#933310 !important; border:none !important; color:#ffffff !important; }

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
#myInput { border:1px solid #cbd5e1; height:44px; border-radius:12px; font-size:.9rem; background:#f8fafc; padding-left:16px; transition:all 0.2s; }
#myInput:focus { border-color:#ffb60a; box-shadow:0 0 0 4px rgba(255,182,10,.15); background:#ffffff; outline:none; }
.badge { background-color:#ffb60a; color:#fff; }
.footer { margin-top:2rem; color:#933310; font-size:.82rem; }
.event-meta-label { width:40%; font-weight:600; color:#475569; }
.event-note { font-size:.85rem; color:#64748b; }

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

<body class="staff-page staff-calendar-page staff-employee">
    <?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

    <?php
    $staffPageTitle  = 'Calendar';
    $staffPageNote   = 'Manual events and read-only bookings in one month view.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['Calendar', null]];
    $staffPageActions = '<input class="form-control" id="myInput" type="text" placeholder="Search..." style="width:200px;">';
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

    <div class="cal-tooltip" id="calTooltip"></div>

    <div class="content main-card">
        <div class="calendar-card">
            <div class="cal-legend">
                <div class="cal-legend-item">
                    <span class="cal-legend-chip" style="background:#fff3d6;color:#b45309;">Manual Events</span>
                </div>
                <div class="cal-legend-item">
                    <span class="cal-legend-chip" style="background:#fde8e0;color:#933310;">Bookings (read-only)</span>
                </div>
            </div>
            <div id="calendar"></div>
        </div>
    </div>

    <div class="modal fade" id="viewEventModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:14px; overflow:hidden;">
                <div class="modal-header" style="background:#933310; color:#fff;">
                    <h5 class="modal-title"><i class="bi bi-calendar-event me-2"></i>Event Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><th class="event-meta-label">Title</th><td id="viewEventTitle">-</td></tr>
                        <tr><th class="event-meta-label">Category</th><td id="viewEventCategory">-</td></tr>
                        <tr><th class="event-meta-label">Schedule</th><td id="viewEventSchedule">-</td></tr>
                        <tr><th class="event-meta-label">Description</th><td id="viewEventDescription">-</td></tr>
                    </table>

                    <div id="viewBookingSection" class="mt-3 pt-3 border-top d-none">
                        <table class="table table-sm table-borderless mb-0">
                            <tr><th class="event-meta-label">Guest</th><td id="viewGuest">-</td></tr>
                            <tr><th class="event-meta-label">Room Type</th><td id="viewRoomType">-</td></tr>
                            <tr><th class="event-meta-label">Room No.</th><td id="viewRoomNumber">-</td></tr>
                            <tr><th class="event-meta-label">Persons</th><td id="viewPersons">-</td></tr>
                            <tr><th class="event-meta-label">Booking Type</th><td id="viewBookingType">-</td></tr>
                        </table>
                    </div>

                    <p class="event-note mt-3 mb-0">
                        <i class="bi bi-eye me-1"></i>This calendar is view-only for employees.
                    </p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>

    <script>
document.addEventListener('DOMContentLoaded', function () {
    const tooltip = document.getElementById('calTooltip');
    const viewEventModal = new bootstrap.Modal(document.getElementById('viewEventModal'));
    const bookingSection = document.getElementById('viewBookingSection');

    const calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
        initialView: window.innerWidth < 768 ? 'listWeek' : 'dayGridMonth',
        height: 'auto',
        handleWindowResize: true,
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,listWeek'
        },
        selectable: false,
        editable: false,

        // The HOTEL's "today" (includes/server_clock.php). Left unset, FullCalendar
        // asks the viewing device: on a phone in another timezone or with a skewed
        // clock the highlighted day — and the "today" button — were the device's day.
        // A function, so it is still right after midnight on a page left open.
        now: window.MarleClock ? function () { return MarleClock.todayDate(); } : undefined,
        events: '../employee/load_events.php',

        eventClick: function (info) {
            const event = info.event;
            const props = event.extendedProps || {};
            const isBooking = props.source === 'booking';

            setText('viewEventTitle', event.title);
            setText('viewEventCategory', isBooking ? 'Booking' : 'Manual Event');
            setText('viewEventSchedule', formatSchedule(event.start, event.end));
            setText(
                'viewEventDescription',
                props.description || (isBooking ? props.tooltip : '') || 'No description provided.'
            );

            bookingSection.classList.toggle('d-none', !isBooking);
            if (isBooking) {
                setText('viewGuest', props.guest_name);
                setText('viewRoomType', props.room_type);
                setText('viewRoomNumber', props.room_number || 'TBA');
                setText('viewPersons', props.persons);
                setText('viewBookingType', props.booking_type === 'hourly' ? 'Hourly' : 'Nightly');
            }

            viewEventModal.show();
        },

        eventMouseEnter: function (info) {
            const props = info.event.extendedProps || {};
            if (props.source !== 'booking') {
                return;
            }
            tooltip.textContent = props.tooltip || info.event.title;
            tooltip.classList.add('show');
        },

        eventMouseLeave: function () {
            tooltip.classList.remove('show');
        },

        eventDidMount: function (info) {
            info.el.addEventListener('mousemove', function (e) {
                tooltip.style.left = (e.pageX + 12) + 'px';
                tooltip.style.top = (e.pageY - 220) + 'px';
            });
        },

        windowResize: function (arg) {
            if (arg.view.type !== 'listWeek' && window.innerWidth < 768) {
                calendar.changeView('listWeek');
            }
        }
    });

    calendar.render();

    function setText(id, value) {
        document.getElementById(id).textContent = value || '-';
    }

    function formatSchedule(start, end) {
        if (!start) {
            return '-';
        }

        const formatOptions = { dateStyle: 'medium', timeStyle: 'short' };
        const startText = new Intl.DateTimeFormat('en-PH', formatOptions).format(start);

        if (!end) {
            return startText;
        }

        const endText = new Intl.DateTimeFormat('en-PH', formatOptions).format(end);
        return startText === endText ? startText : `${startText} - ${endText}`;
    }
});
    </script>

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

</body>

</html>



