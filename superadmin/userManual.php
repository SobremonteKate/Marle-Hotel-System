<?php
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');
include('../superadmin/auth.php');
include('../superadmin/requests_badge.php');

// Logout
// if(isset($_POST['confirmLogout'])){
//     echo '<script>alert("Successfully logged out")</script>';
//     session_unset(); //end variable session
//     session_destroy(); //end session
//     header("Location: login.php");
// }
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    // Redirect to login with a success flag
    header("Location: ../login.php?logout=success");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-4Q6Gf2aSP4eDXB8Miphtr37CMZZQ5oXLH2yaXMJ2w8e2ZtHTl7GptT4jmndRuHDT" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.lineicons.com/5.0/lineicons.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <!-- icon, to be changed -->
    <?php require __DIR__ . '/../includes/staff_icons.php'; ?>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
    <link rel="stylesheet" href="../css/staff-footer.css?v=20261021">
    <title>User Manual</title>

    <style>
        body {
            overflow-x: hidden;
            background-color: #f2ece9;
        }

        /* Desktop Sidebar Layout */
        @media (min-width: 768px) {

            .navbar {
                width: 230px;
                height: 100vh;
                position: fixed;
                top: 0;
                left: 0;
                background-color: #f8f9fa;
                display: flex;
                flex-direction: column;
            }

            .navbar>.container-fluid {
                height: 100%;
                display: flex;
                flex-direction: column;
            }

            .navbar-collapse {
                display: flex !important;
                flex-direction: column;
                /* height: 100%; */
                flex: 1;
            }

            .content {
                /* margin-top: 1.5rem; */
                margin-left: 250px;
            }
        }

        .nav-link {
            padding: 12px 18px;
            color: #555;
            border-radius: 12px;
            position: relative;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            background-color: #ffb60a;
            color: #fff;
        }

        .nav-link.active {
            background-color: #ffb60a;
            color: #fff !important;
            font-weight: 500;
        }

        /* Left Accent Bar */
        .nav-link.active::before {
            content: "";
            position: absolute;
            left: -12px;
            top: 8px;
            bottom: 8px;
            width: 4px;
            border-radius: 4px;
            background-color: #ffb60a;
        }

        .nav-link i {
            font-size: 18px;
        }

        .notification-badge {
            background-color: #ffb60a;
            color: #fff;
            font-size: 12px;
            padding: 5px 8px;
        }

        .logout-btn {
            background-color: #933310;
            color: #fff;
            border: none;
            padding: 10px;
            border-radius: 12px;
            transition: 0.3s;
        }

        .logout-btn:hover {
            background-color: transparent;
            border-color: #933310;
            color: #933310;
        }

        #yesBtn:hover,
        #noBtn:hover {
            background-color: transparent;
            border-color: #933310;
            color: #933310;
        }

        #yesBtn {
            background-color: #ffb60a;
            color: #fff;
        }

        a {
            text-decoration: none;
            color: #ffcc66;
        }

        /* Cards */
        .card {
            border-radius: 20px;
            padding-left: 15px;
        }

        .card h5 {
            font-weight: 400;
            margin-top: 15px;
        }

        .card-text {
            font-size: 30px;
            font-weight: 700;
        }

        /* Search */
        #myInput {
            border: solid #ffb60a 3px;
            height: 45px;
            /* margin-bottom: 20px */
        }

        .bi-bell {
            font-size: 20px;
            /* margin: 10px; */
            padding: 7px;
            border-radius: 10px;
            background-color: #fff;
            color: #000;
            margin-right: 0;
        }

        .btn {
            color: #fff;
            border-radius: 12px;
        }

        .btn:hover {
            background-color: transparent;
            border-color: #933310;
            color: #933310;
        }

        .manual-wrapper {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.04);
        }

        .manual-title {
            font-size: 44px;
            font-weight: 700;
            color: #352414;
        }

        .manual-subtitle {
            font-size: 17px;
            color: #6c757d;
        }

        /* Kicker above the title — role-tagged */
        .manual-kicker {
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #933310;
            background: #f7e8e3;
            border-radius: 999px;
            padding: 6px 14px;
            margin-bottom: 12px;
        }

        .manual-box {
            position: relative;
            background: #ffffff;
            border: 1px solid #e5d5cf;
            border-radius: 14px;
            padding: 26px 22px 34px;
            text-align: center;
            cursor: pointer;
            transition: 0.25s ease;
            height: 100%;
            min-height: 220px;
            overflow: hidden;
        }

        /* Numbered chip pinned top-left of each topic card */
        .manual-num {
            position: absolute;
            top: 12px;
            left: 14px;
            width: 24px;
            height: 24px;
            border-radius: 8px;
            background: #f2ece9;
            color: #933310;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .manual-role-banner {
            background: #fff8e8;
            border: 1px solid #f0d9a3;
            border-left: 5px solid #ffb60a;
            border-radius: 12px;
            padding: 16px 20px;
            color: #5c4a25;
            font-size: 15px;
        }

        .manual-table th {
            background: #fff8e8;
            color: #7a2c0d;
            font-weight: 600;
        }

        .manual-table td {
            font-size: 14.5px;
        }

        .manual-table td li {
            margin-bottom: 4px;
        }

        .manual-box:hover {
            transform: translateY(-6px);
            border-color: #933310;
            box-shadow: 0 14px 28px rgba(147, 51, 16, 0.12);
        }

        .manual-icon {
            font-size: 44px;
            margin-bottom: 14px;
            color: #933310;
            background: #f7e8e3;
            width: 76px;
            height: 76px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .manual-box h3 {
            font-size: 19px;
            font-weight: 700;
            color: #352414;
            margin-bottom: 8px;
        }

        .manual-box p {
            font-size: 14.5px;
            color: #6c757d;
            min-height: 60px;
            margin-bottom: 0;
        }

        /* "Open guide" affordance pinned bottom-right */
        .manual-circle {
            position: absolute;
            bottom: 14px;
            right: 14px;
            font-size: 11.5px;
            font-weight: 600;
            color: #933310;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            opacity: 0;
            transform: translateX(-4px);
            transition: 0.25s ease;
        }

        .manual-box:hover .manual-circle {
            opacity: 1;
            transform: translateX(0);
        }

        .manual-faq {
            font-weight: 600;
            color: #933310;
            text-decoration: none;
            border-bottom: 2px solid #f0d9a3;
            padding-bottom: 2px;
            transition: 0.2s ease;
        }

        .manual-faq:hover {
            color: #7a2c0d;
            border-bottom-color: #933310;
        }

        /* Topic modal polish — matches the manual look */
        .manual-modal .modal-content {
            border: none;
            border-radius: 16px;
            overflow: hidden;
        }

        .manual-modal .modal-header {
            background: #fff8e8;
            border-bottom: 1px solid #f0d9a3;
        }

        .manual-modal .modal-title {
            font-weight: 700;
            color: #7a2c0d;
        }

        .manual-modal .modal-body h6 {
            color: #933310;
            font-weight: 700;
            margin-top: 1.1rem;
        }

        .manual-modal .modal-body h6:first-child {
            margin-top: 0;
        }

        .manual-modal .modal-body ul li,
        .manual-modal .modal-body p {
            font-size: 14.8px;
            line-height: 1.55;
        }

        /* Footer */
        .footer {
    </style>

<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>

<body class="staff-page staff-superadmin staff-manual-page">

    <?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

    <!-- Welcome message + search -->
    <?php
    $staffPageTitle  = 'User Manual';
    $staffPageNote   = 'How the console works, screen by screen.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['User Manual', null]];
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

    <!-- Main content -->
    <div class="container content mt-2">
        <div class="row justify-content-center">
            <div class="col-12 col-xl-10">

                <div class="manual-wrapper p-5">

                    <div class="text-center mb-4">
                        <span class="manual-kicker">Owner Guide</span>
                        <h1 class="manual-title">User Manual</h1>
                        <p class="manual-subtitle">The complete system, accounts and the audit trail</p>
                    </div>

                    <div class="manual-role-banner mb-4">
                        <strong><i class="bi bi-person-badge me-1"></i>You are signed in as Superadmin (owner).</strong>
                        <div class="mt-1">
                            This is the highest level of access: the whole Front Desk and manager toolset plus staff
                            account creation, the System Log (audit trail), database backups and the homepage content
                            sections. Because it is unrestricted, the guardrails below are what keep it safe.
                        </div>
                    </div>

                    <div class="table-responsive mb-4">
                        <table class="table table-bordered align-middle manual-table mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 50%">What this role can do</th>
                                    <th>Guardrails you must respect</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <ul class="mb-0 ps-3">
                                            <li>Everything the Front Desk and the manager can do</li>
                                            <li>Create accounts for any role, deactivate or reactivate any account</li>
                                            <li>Read the System Log (audit trail) and download backups</li>
                                            <li>Edit the homepage rooms, amenities and offers content</li>
                                        </ul>
                                    </td>
                                    <td>
                                        <ul class="mb-0 ps-3">
                                            <li>You cannot deactivate your own account or the last active owner</li>
                                            <li>Ledger amounts are never edited by hand &mdash; collections are separate records</li>
                                            <li>Deactivating a user ends their live session on their next click</li>
                                            <li>Keep backups off the reception machine and out of shared folders</li>
                                        </ul>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <h5 class="mb-3" style="color: #933310;">Choose a topic</h5>

                    <div class="row g-4">

                        <!-- Getting Started -->
                        <div class="col-md-4">
                            <div class="manual-box" data-bs-toggle="modal" data-bs-target="#gettingStartedModal">

                                <span class="manual-num">1</span>

                                <div class="manual-icon">
                                    <i class="bi bi-signpost-2"></i>
                                </div>

                                <h3>Getting Started</h3>
                                <p>
                                    Signing in, the owner sidebar, session limits and signing out safely.
                                </p>

                                <span class="manual-circle">Open guide <i class="bi bi-arrow-right-circle"></i></span>
                            </div>
                        </div>

                        <!-- Daily operations -->
                        <div class="col-md-4">
                            <div class="manual-box" data-bs-toggle="modal" data-bs-target="#operationsModal">

                                <span class="manual-num">2</span>

                                <div class="manual-icon">
                                    <i class="bi bi-clipboard-check"></i>
                                </div>

                                <h3>Daily Operations</h3>
                                <p>
                                    Dashboard, requests, guest chat and the calendar.
                                </p>

                                <span class="manual-circle">Open guide <i class="bi bi-arrow-right-circle"></i></span>
                            </div>
                        </div>

                        <!-- Guests and occupancy -->
                        <div class="col-md-4">
                            <div class="manual-box" data-bs-toggle="modal" data-bs-target="#tokensModal">

                                <span class="manual-num">3</span>

                                <div class="manual-icon">
                                    <i class="bi bi-key"></i>
                                </div>

                                <h3>Guests &amp; Occupancy</h3>
                                <p>
                                    Occupied Rooms, issuing tokens and the guest portal journey.
                                </p>

                                <span class="manual-circle">Open guide <i class="bi bi-arrow-right-circle"></i></span>
                            </div>
                        </div>

                        <!-- Payments and reports -->
                        <div class="col-md-4">
                            <div class="manual-box" data-bs-toggle="modal" data-bs-target="#paymentsModal">

                                <span class="manual-num">4</span>

                                <div class="manual-icon">
                                    <i class="bi bi-cash-coin"></i>
                                </div>

                                <h3>Payments &amp; Reports</h3>
                                <p>
                                    The transaction ledger, collections, archiving and printed reports.
                                </p>

                                <span class="manual-circle">Open guide <i class="bi bi-arrow-right-circle"></i></span>
                            </div>
                        </div>

                        <!-- Owner administration -->
                        <div class="col-md-4">
                            <div class="manual-box" data-bs-toggle="modal" data-bs-target="#ownerModal">

                                <span class="manual-num">5</span>

                                <div class="manual-icon">
                                    <i class="bi bi-person-gear"></i>
                                </div>

                                <h3>Owner Administration</h3>
                                <p>
                                    Accounts, the System Log, database backups and homepage content.
                                </p>

                                <span class="manual-circle">Open guide <i class="bi bi-arrow-right-circle"></i></span>
                            </div>
                        </div>

                        <!-- Reviews & Sentiment (Marle AI) -->
                        <div class="col-md-4">
                            <div class="manual-box" data-bs-toggle="modal" data-bs-target="#sentimentGuideModal">

                                <span class="manual-num">6</span>

                                <div class="manual-icon">
                                    <i class="bi bi-bar-chart-line"></i>
                                </div>

                                <h3>Reviews &amp; Sentiment</h3>
                                <p>
                                    Marle AI: how guest feedback is classified, and how to read the report.
                                </p>

                                <span class="manual-circle">Open guide <i class="bi bi-arrow-right-circle"></i></span>
                            </div>
                        </div>

                        <!-- Safety -->
                        <div class="col-md-4">
                            <div class="manual-box" data-bs-toggle="modal" data-bs-target="#safetyModal">

                                <span class="manual-num">7</span>

                                <div class="manual-icon">
                                    <i class="bi bi-shield-check"></i>
                                </div>

                                <h3>Safety &amp; Security</h3>
                                <p>
                                    Protecting the owner account, staff data and the guest database.
                                </p>

                                <span class="manual-circle">Open guide <i class="bi bi-arrow-right-circle"></i></span>
                            </div>
                        </div>

                    </div>

                    <!-- Bottom Link -->
                    <div class="mt-4">
                        <a href="#" class="manual-faq" data-bs-toggle="modal" data-bs-target="#faqModal">
                            Troubleshooting &amp; FAQs <i class="bi bi-patch-question-fill"></i>
                        </a>
                    </div>

                </div>

            </div>
        </div>
    </div>

    <!-- Getting Started Modal -->
    <div class="modal fade manual-modal" id="gettingStartedModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">1. Getting Started</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>Signing in</h6>
                    <p>Use the owner credentials on the staff login page. The owner account lands on the owner dashboard. Failed sign-ins, refused sign-ins and successful sign-ins are all recorded in the System Log with the username that was attempted.</p>

                    <h6>The owner sidebar</h6>
                    <p><strong>Dashboard</strong>, <strong>Requests</strong>, <strong>Guest Tokens</strong>, <strong>Transactions</strong>, <strong>Inventory</strong>, <strong>Guest Record</strong>, <strong>Calendar</strong>, <strong>Reviews</strong>, <strong>Settings</strong> and <strong>System Log</strong> &mdash; the last one is visible to this role only. This User Manual is reachable from <strong>Settings &rarr; Maintenance Shortcuts</strong>.</p>

                    <h6>Session limits</h6>
                    <ul>
                        <li>Automatic sign-out after <strong>30 minutes of inactivity</strong> and after <strong>8 hours</strong> in total.</li>
                        <li>If the account is deactivated, the session ends on the next click; only another active owner can restore it.</li>
                    </ul>

                    <h6>System requirements</h6>
                    <ul>
                        <li>A current browser (Chrome, Edge or Firefox) with a stable connection.</li>
                        <li>Enough disk space for database backups, kept off the reception machine.</li>
                    </ul>

                    <h6>Signing out</h6>
                    <p>Select <strong>Logout</strong> at the bottom of the sidebar and confirm. Never leave the owner session open on a shared computer.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Daily operations modal -->
    <div class="modal fade manual-modal" id="operationsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">2. Daily Operations</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>Dashboard</h6>
                    <p>Occupancy, availability, the room board and the day's activity. The range selector switches the trend charts between the last 6, 12, 24 and 36 months.</p>

                    <h6>Requests</h6>
                    <p>Bookings, extensions, services, food and guest chat, <strong>newest first</strong>. Approving an extension, service or food request bills the amount to the room; denying records the reason. Use this page to see what the Front Desk team is handling and to step in when a request is waiting too long.</p>

                    <h6>Calendar</h6>
                    <p>Scheduled stays and events across the property. Confirm timing against the guest record before acting.</p>

                    <h6>Guest chat</h6>
                    <p>Guests message the desk from the portal. Replies are recorded, and read receipts are intentionally not treated as business events.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Guests & occupancy modal -->
    <div class="modal fade manual-modal" id="tokensModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">3. Guests &amp; Occupancy</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>Occupied Rooms</h6>
                    <p><strong>Guest Tokens</strong> lists every occupied room as a card titled with its number (for example <strong>Room 8</strong>), showing the unpaid total, settled and unsettled item counts, the charge breakdown and the <strong>Collect Payment</strong> action. The owner sees the <strong>itemized ledger rows</strong> behind each total &mdash; the level of detail to use when a bill is disputed.</p>

                    <h6>Issuing a token</h6>
                    <ol>
                        <li>Confirm the room matches the guest and is available.</li>
                        <li>Enter persons, hours of stay and the plate number where applicable.</li>
                        <li>Generate the token and hand it to the guest &mdash; it is their portal key.</li>
                    </ol>
                    <p>Tokens can also be revoked from the same page when one is cancelled or reissued.</p>

                    <h6>The guest portal</h6>
                    <p>With a valid token the guest gets a countdown of the remaining stay, a running bill, food ordering, service and extension requests, chat with the desk, and a review prompt at the end. Each of those actions is logged as a portal event with the room number.</p>

                    <h6>Automatic check-out</h6>
                    <p>When the countdown ends the system checks the guest out on its own: the room is released, chat messages are archived and finished requests are closed. Expired tokens are swept automatically as well.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Payments & reports modal -->
    <div class="modal fade manual-modal" id="paymentsModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">4. Payments &amp; Reports</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>Two ways to collect</h6>
                    <ul>
                        <li><strong>From a room card</strong> (Guest Tokens) &mdash; the counter path: Collect Payment, check the calculated amount due, enter the amount received and confirm once.</li>
                        <li><strong>From the Transactions ledger</strong> &mdash; the <em>Collect Payment</em> action on an occupied room's unpaid items (the same collection used at the counter).</li>
                    </ul>
                    <p>Amounts due are calculated from the room's outstanding items and validated against the source records. Underpayment is refused; overpayment records the change; the submit button locks so a payment cannot be recorded twice.</p>

                    <h6>The Transactions page</h6>
                    <ul>
                        <li><strong>Summary</strong> or <strong>Detailed</strong> view.</li>
                        <li>Period quick-filters <strong>Daily</strong>, <strong>Weekly</strong>, <strong>Monthly</strong>, <strong>All Time</strong>, plus a custom <em>From / To</em> range.</li>
                        <li><strong>Type</strong> filter (check-in, extension, service, food) and <strong>Status</strong> filter (paid, unpaid, denied, change received).</li>
                        <li>Ordered <strong>newest first</strong>; the detailed view pages <strong>15 rows at a time</strong> and the pager keeps every active filter.</li>
                        <li>Per row: <em>Archive</em>. Each archive is written to the audit trail. Status and amount of a stored row are never edited by hand &mdash; a mistaken entry is corrected through a collection or an archive, and any attempt to edit a row directly is refused and logged.</li>
                    </ul>

                    <h6>Reading the numbers</h6>
                    <p>Summary totals cover the whole filtered set, not just the visible page. A collection is stored as its own record &mdash; ledger amounts are never rewritten &mdash; so the ledger and the collections together answer both "what was billed" and "what was received".</p>

                    <h6>Reports</h6>
                    <ul>
                        <li><strong>Print</strong> opens the printable report view for the current filters.</li>
                        <li><strong>Download PDF</strong> exports the same rows through jsPDF.</li>
                        <li>Generating a report is itself written to the System Log.</li>
                    </ul>

                    <h6>Daily reconciliation habit</h6>
                    <p>Compare the day's collections against the cash on hand before closing. If they disagree, open the System Log: the collections, the deactivations and any corrections are all timestamped there.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Owner administration modal -->
    <div class="modal fade manual-modal" id="ownerModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">5. Owner Administration</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>Add New User (Settings)</h6>
                    <p>Enter a unique username, a password and the role &mdash; <strong>Superadmin</strong>, <strong>Admin</strong> or <strong>Employee</strong>. The password is stored only as a hash, and the account can sign in immediately. This is the only place staff accounts are created: managers can no longer create them.</p>

                    <h6>Users (Settings)</h6>
                    <ul>
                        <li>Every account is listed with an <strong>Active</strong> or <strong>Deactivated</strong> badge.</li>
                        <li>Your own row shows <em>"This is you"</em> instead of a button &mdash; you cannot deactivate yourself.</li>
                        <li>The <strong>last active owner</strong> cannot be deactivated, so the system can never be left without an owner.</li>
                        <li><strong>Deactivate</strong> ends a live session on the user's very next click and blocks future sign-ins; <strong>Reactivate</strong> restores access immediately. Both stamp who acted and when.</li>
                    </ul>

                    <h6>System Log (audit trail)</h6>
                    <ul>
                        <li>Filter by role, outcome (success / denied / failure), action, date range or free text; the list is paginated newest-first.</li>
                        <li>Each entry records the actor and role, the action, the target, the outcome, the reason, the IP address, the device and before/after values where relevant.</li>
                        <li>Covered: staff and portal sign-ins, booking approvals and denials, extensions, services, food, payments collected, archives and restores, room status changes, chat replies, calendar changes, inventory quantity changes, token generation and revocation, account and user changes, and generated reports.</li>
                        <li>Not treated as business events: chat read receipts and read-only page loads.</li>
                    </ul>
                    <p>Read it as your control check: a payment with no matching collection, an unexpected deactivation, or a report pulled at 2 a.m. all show up here.</p>

                    <h6>Backup Database (Settings &rarr; Maintenance Shortcuts)</h6>
                    <ul>
                        <li>Downloads a complete SQL dump of the database as an attachment.</li>
                        <li>The file is written <strong>outside the web root</strong>, streamed to your browser and then deleted from the server.</li>
                        <li>Each download is itself logged as <code>database_backup_downloaded</code>.</li>
                        <li>This is an owner-only action, and a full dump contains password hashes &mdash; store copies on an encrypted drive, never in a shared folder or e-mail.</li>
                    </ul>

                    <h6>Homepage content (Settings)</h6>
                    <p>The Rooms, Exclusive Amenities and Offers sections are <strong>edit-only</strong>: rename a room or update its description and image, and edit amenity and offer titles or descriptions. There is deliberately no add or delete for these catalogs, and each edit is written to the System Log.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Reviews & Sentiment modal -->
    <div class="modal fade manual-modal" id="sentimentGuideModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">6. Reviews &amp; Sentiment (Marle AI)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>What Marle AI does</h6>
                    <p>Every review on the <strong>Reviews</strong> page can be read by <strong>Marle AI</strong> &mdash; the built-in sentiment service behind the <em>"Powered by Groq (Qwen 3)"</em> badge. For each comment it assigns a label (<strong>positive</strong>, <strong>neutral</strong> or <strong>negative</strong>), a strength score between &minus;1.0 and +1.0, and the <strong>main theme</strong> the guest is talking about: cleanliness, staff, location, amenities, value, food, noise, maintenance or other.</p>

                    <h6>Running an analysis</h6>
                    <ol>
                        <li>Open <strong>Reviews</strong> and click <strong>Sentiment Analysis</strong>.</li>
                        <li>Pick the scope: up to <strong>100 of the latest reviews</strong>, filtered by category (Hotel, Restaurant, Amenities, Service), date range and language (English or Tagalog).</li>
                        <li>Click <strong>Analyze Selected Reviews</strong>. Reviews already classified for their current text are skipped, so re-running never creates duplicates &mdash; it only adds or refreshes labels.</li>
                    </ol>

                    <h6>Reading the report</h6>
                    <ul>
                        <li>The <strong>overall sentiment</strong> ring shows the dominant mood of the scope, with a one-line recommendation.</li>
                        <li><strong>Strengths</strong> and <strong>concerns</strong> summarise what guests praise and complain about; the <strong>action plan</strong> turns the recurring themes into suggested steps.</li>
                        <li>Every review keeps its own label on the list, so you can read the comment behind any number before acting on it.</li>
                    </ul>

                    <h6>How it reads Filipino reviews</h6>
                    <p>The AI is taught to read <strong>English, Tagalog and Taglish</strong>, including informal spelling and slang: <em>"npaka sungit ng front desk nku"</em> is decoded as <em>"the front desk was very rude"</em> (negative, theme: staff); <em>"sulit"</em> counts as worth it (positive); <em>"ok lang"</em> stays neutral, and indifference (<em>"kebs lang"</em>, <em>"bahala na"</em>) stays neutral too unless the guest names a real problem (<em>"walang kwenta"</em> = worthless, negative); and sarcastic praise like <em>"galing galing nman ha"</em> after a complaint counts as negative. The guest's <strong>star rating is passed along as a supporting signal</strong> for ambiguous wording &mdash; but a clearly worded comment always outweighs the stars.</p>

                    <h6>Limits to keep in mind</h6>
                    <ul>
                        <li><strong>It is a helper, not a judge.</strong> Very short replies ("meh"), heavy sarcasm or brand-new slang can still land on the wrong label &mdash; spot-check the comments behind a report before acting on it.</li>
                        <li><strong>Neutral clusters are a flag.</strong> If a run returns many neutrals, read them yourself; the AI may simply not know a new slang word yet.</li>
                        <li><strong>It reads text only.</strong> An empty comment or a stars-only rating gives it nothing to work with.</li>
                        <li><strong>It never edits data.</strong> Labels are read-only notes on top of the reviews; reviews themselves are only changed through the normal review actions.</li>
                        <li><strong>It needs the internet.</strong> If the analysis cannot reach the AI service, the page says so and nothing is half-written; previously saved labels remain available.</li>
                    </ul>

                    <h6>Good habits</h6>
                    <ul>
                        <li>Analyse one scope at a time ("Restaurant, this month") &mdash; a report is only as good as the scope behind it.</li>
                        <li>Chase <strong>repeating themes</strong>, not single reviews: one bad night is noise, the same complaint in five reviews is a pattern.</li>
                        <li>Use the language filter to answer "what are our Tagalog-speaking guests saying?".</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Safety modal -->
    <div class="modal fade manual-modal" id="safetyModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">7. Safety &amp; Security</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>The owner account</h6>
                    <ul>
                        <li>This is the only account that can read the audit trail, export the database and create staff accounts &mdash; treat it as the keys to the building.</li>
                        <li>Do not let staff work under the owner login, not even to "check something quickly".</li>
                        <li>Keep at least one other active owner account so the business is never locked out, and remove it again when the person leaves.</li>
                        <li>Change the owner password from <strong>Settings &rarr; Account Settings</strong> whenever access changes hands.</li>
                    </ul>

                    <h6>Staff turnover</h6>
                    <p>On the last working day: <strong>deactivate the account immediately</strong> (Settings &rarr; Users). The session dies on their next click, the login is blocked, and the action is recorded. Then review the System Log for the days before they left and change any shared passwords they knew.</p>

                    <h6>Backups and data</h6>
                    <ul>
                        <li>Take a backup before every large change (homepage content, bulk inventory edits) and after every busy weekend.</li>
                        <li>Store backups encrypted and off the reception machine; test that a copy actually opens.</li>
                        <li>Delete backup files you no longer need &mdash; an old dump is a full copy of every guest record.</li>
                    </ul>

                    <h6>Reading the log regularly</h6>
                    <p>Review the System Log at least weekly: denied sign-ins, unexpected deactivations, late-night report downloads and unmatched collections are the four patterns worth chasing.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- FAQ modal -->
    <div class="modal fade manual-modal" id="faqModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Troubleshooting and FAQs</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p><strong>I cannot deactivate an account.</strong><br>
                        It is either your own account (blocked on purpose) or the last active owner (blocked so the system always has an owner). Create or activate another owner first.</p>

                    <p><strong>A dismissed employee is still shown as active.</strong><br>
                        Open Settings &rarr; Users and deactivate the row. If their badge already reads <em>Deactivated</em>, their access is gone &mdash; that is exactly what the badge reflects.</p>

                    <p><strong>The backup download failed or was empty.</strong><br>
                        Check the disk space and that MySQL is running, then try again from Settings &rarr; Maintenance Shortcuts. The download is recorded in the System Log either way, so you can confirm whether it completed.</p>

                    <p><strong>Totals look different from yesterday.</strong><br>
                        Confirm the period on the Transactions page and whether any rows were archived. Summary totals follow the active filter, and archived rows leave the ledger but stay on record.</p>

                    <p><strong>Where do I undo a wrong collection?</strong><br>
                        Collections are their own records and the ledger amount is untouched, so nothing is silently overwritten. Review the room card and the System Log, then correct the record through the appropriate request or archive action.</p>

                    <p><strong>A manager asks for the System Log or a backup.</strong><br>
                        Both are owner-only by design. Export or read it yourself and share the specific information they need instead of the access itself.</p>

                    <p><strong>Nothing loads after an update.</strong><br>
                        Reload with Ctrl+F5. If a single page stays broken, note its name and the action you took &mdash; the System Log will already have the failed request.</p>

                    <p><strong>The AI labeled a review wrong.</strong><br>
                        It happens with heavy sarcasm or brand-new slang &mdash; treat the label as a hint and read the comment. Re-running the analysis refreshes labels without duplicates, and noting the exact phrase that fooled it lets the AI's word guide be taught the term.</p>
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
                        As the owner you are the final authority on these terms; keep this
                        text aligned with how the system is actually used.
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

    <!-- LOGoUT MODAL -->
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-j1CDi7MgGQ12Z7Qab0qlWQ/Qqz24Gc6BM0thvEMVjHnfYGF0rmFCozFSxQBxwHKO"
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</body>

</html>

