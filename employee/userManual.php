<?php
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');
include('../employee/auth.php');
include('../employee/requests_badge.php');
include('../employee/includes/notif_data.php');

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

<body class="staff-page staff-employee staff-manual-page">

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
                        <span class="manual-kicker">Front Desk Guide</span>
                        <h1 class="manual-title">User Manual</h1>
                        <p class="manual-subtitle">Everything this role can do</p>
                    </div>

                    <div class="manual-role-banner mb-4">
                        <strong><i class="bi bi-person-badge me-1"></i>You are signed in as Front Desk (employee).</strong>
                        <div class="mt-1">
                            This guide covers the Front Desk scope only: guest operations, requests, room occupancy and
                            payment collection at the counter. Transaction reports, staff accounts, the System Log and
                            database backup belong to the owner and are intentionally not available to this role.
                        </div>
                    </div>

                    <div class="table-responsive mb-4">
                        <table class="table table-bordered align-middle manual-table mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 50%">What this role can do</th>
                                    <th>What this role cannot do</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <ul class="mb-0 ps-3">
                                            <li>Review, approve or deny guest requests</li>
                                            <li>Issue and revoke guest portal tokens</li>
                                            <li>Collect payments for an occupied room</li>
                                            <li>Update a guest's stay status, maintain inventory and read the calendar</li>
                                        </ul>
                                    </td>
                                    <td>
                                        <ul class="mb-0 ps-3">
                                            <li>Open the Transactions ledger or export reports</li>
                                            <li>Create staff accounts, or deactivate/reactivate anyone</li>
                                            <li>Read the System Log (audit trail)</li>
                                            <li>Download a database backup</li>
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
                                    Signing in, the sidebar, session limits and how to sign out safely.
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
                                    Dashboard, the four request tabs, chat and the calendar.
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

                        <!-- Collecting payments -->
                        <div class="col-md-4">
                            <div class="manual-box" data-bs-toggle="modal" data-bs-target="#paymentsModal">

                                <span class="manual-num">4</span>

                                <div class="manual-icon">
                                    <i class="bi bi-cash-coin"></i>
                                </div>

                                <h3>Collecting Payments</h3>
                                <p>
                                    Reading a room's bill at the counter and recording the collection.
                                </p>

                                <span class="manual-circle">Open guide <i class="bi bi-arrow-right-circle"></i></span>
                            </div>
                        </div>

                        <!-- Records and feedback -->
                        <div class="col-md-4">
                            <div class="manual-box" data-bs-toggle="modal" data-bs-target="#recordsModal">

                                <span class="manual-num">5</span>

                                <div class="manual-icon">
                                    <i class="bi bi-people"></i>
                                </div>

                                <h3>Records &amp; Feedback</h3>
                                <p>
                                    Guest records, inventory levels and guest reviews.
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
                                    Protecting your account, the counter device and guest data.
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
                    <p>Open the staff login page, enter your username and password, then select <strong>Login</strong>. Front Desk accounts land on the Front Desk dashboard. Usernames cannot contain special characters.</p>

                    <h6>The sidebar</h6>
                    <p>The sidebar is your whole workspace: <strong>Dashboard</strong>, <strong>Requests</strong>, <strong>Guest Tokens</strong>, <strong>Inventory</strong>, <strong>Guest Record</strong>, <strong>Calendar</strong>, <strong>Reviews</strong>, <strong>Settings</strong> and this <strong>User Manual</strong>. If a page is not in this list, it is not part of the Front Desk role — opening its address directly returns the not-authorized page.</p>

                    <h6>Session limits</h6>
                    <ul>
                        <li>You are signed out automatically after <strong>30 minutes of inactivity</strong>, and after <strong>8 hours</strong> regardless of activity.</li>
                        <li>If the owner or a manager deactivates your account, your session ends on your very next click and the login page explains that the account was deactivated.</li>
                        <li>Signing in again is all that is needed after an idle sign-out.</li>
                    </ul>

                    <h6>System requirements</h6>
                    <ul>
                        <li>A current browser (Chrome, Edge or Firefox) on a device with a stable connection.</li>
                        <li>A screen wide enough for the sidebar layout; the sidebar collapses on smaller screens.</li>
                        <li>Do not run the page in several tabs with the same account while collecting payments.</li>
                    </ul>

                    <h6>Signing out</h6>
                    <p>Select <strong>Logout</strong> at the bottom of the sidebar and confirm. Always sign out when you leave the counter device.</p>
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
                    <p>A monitoring page: occupancy and availability cards, the room board, and quick counts of today's activity. Use it to decide where to go next, then open the matching page to make the change.</p>

                    <h6>Requests</h6>
                    <p>Every guest request arrives here, sorted with the <strong>newest first</strong>:</p>
                    <ul>
                        <li><strong>Bookings</strong> — approve or deny a booking; denying asks for a reason the guest can read.</li>
                        <li><strong>Extensions</strong> — a guest asking to stay longer, with the requested hours.</li>
                        <li><strong>Services</strong> — amenities and services billed to the room.</li>
                        <li><strong>Food</strong> — food orders with their items and total.</li>
                        <li><strong>Chat</strong> — messages from guests using the portal; reply from the request card.</li>
                    </ul>
                    <p>Approving an extension, service or food request creates a payable entry on that room's bill, which is collected from the Guest Tokens page (see <em>Collecting Payments</em>). Denied rows stay on record with the reason.</p>

                    <h6>Calendar</h6>
                    <p>Read-only for the Front Desk: scheduled stays and events with their details. Use it to check timing, then confirm details against the guest record before acting. Adding or saving events is done by the manager and owner.</p>

                    <h6>Before you approve anything</h6>
                    <ul>
                        <li>Check the room number, the dates and the requested hours.</li>
                        <li>Make sure the request matches what the guest actually asked for.</li>
                        <li>Never approve a request for a room you have not verified as occupied.</li>
                    </ul>
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
                    <p>The <strong>Guest Tokens</strong> page lists exactly the rooms that are currently occupied — one card per room, titled with the room number (for example <strong>Room 8</strong>).</p>

                    <h6>Issuing a token</h6>
                    <ol>
                        <li>Select the room the guest is checking into.</li>
                        <li>Enter the number of persons, the hours of stay, and the vehicle plate number when there is one.</li>
                        <li>Generate the token and hand it to the guest — it is their key to the guest portal.</li>
                    </ol>
                    <p>Confirm the room is available before issuing. A token issued for the wrong room gives the guest access to a bill that is not theirs.</p>

                    <h6>What each room card shows</h6>
                    <ul>
                        <li>A paid / unpaid chip and the room's unpaid total.</li>
                        <li>Counts of unpaid and settled items.</li>
                        <li>A breakdown of the bill by check-in, extension, service and food.</li>
                        <li>The <strong>Collect Payment</strong> action.</li>
                    </ul>
                    <p>The itemized ledger rows behind those totals are visible to the owner and manager only; the Front Desk sees the room-level summary and collects against it.</p>

                    <h6>The guest portal</h6>
                    <p>With the token, the guest signs in to the guest portal and gets: a live countdown of the remaining stay, a running bill, the ability to order food and request services or an extension, chat with the front desk, and a review prompt at the end.</p>

                    <h6>Automatic check-out</h6>
                    <p>When the countdown reaches zero the system checks the guest out on its own: the room is released, chat messages are archived, and finished requests are closed. If a guest has already left, you do not need to do anything — the sweep completes it.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Payments modal -->
    <div class="modal fade manual-modal" id="paymentsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">4. Collecting Payments</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>Where</h6>
                    <p><strong>Guest Tokens → the guest's room card → Collect Payment.</strong> The Front Desk has no Transactions page: the counter collects per room.</p>

                    <h6>What happens when you collect</h6>
                    <ol>
                        <li>The modal recalculates that room's unpaid total from the ledger and validates it against the source records.</li>
                        <li>Enter the amount received; the change is computed as you type.</li>
                        <li>Confirm once. The button locks while saving so a double click cannot record two collections.</li>
                    </ol>

                    <h6>Rules</h6>
                    <ul>
                        <li>The amount due is fixed by the system — never negotiate it down or type a different total.</li>
                        <li><strong>Underpayment is refused</strong>. Paying the exact amount shows change ₱0.00; paying more is allowed and the change is recorded.</li>
                        <li>The collected amounts are recorded in a collection record; the original ledger entries keep their amounts.</li>
                        <li>Count the cash before confirming, and do not confirm a collection the guest has not paid.</li>
                    </ul>

                    <h6>What is not here</h6>
                    <p>Itemized histories, period summaries, printed/PDF transaction reports and archiving belong to the manager and owner. If a guest needs an itemized receipt, ask them.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Records modal -->
    <div class="modal fade manual-modal" id="recordsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">5. Records &amp; Feedback</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>Guest Record</h6>
                    <p>Search and filter guest stays, review stay dates, hours and status, update a stay's status (for example checking a guest out early), and export the list to CSV when a hard copy is needed. Adding or editing guest entries is a manager-and-owner action &mdash; the Front Desk form is view-and-status only.</p>

                    <h6>Inventory</h6>
                    <p>Stock levels for the items used in operations. Search or filter for low stock, add an item, update quantities, export CSV, and archive entries that are no longer used.</p>
                    <p>Watch the low-stock filter — running out of an amenity is the most common guest complaint at the counter.</p>

                    <h6>Reviews</h6>
                    <p>Guest ratings and comments with the Marle AI sentiment labels, and the Sentiment Analysis tool (see card 6). Reviews are written by guests from the portal &mdash; staff read and analyse them; there is nothing to add or edit here.</p>

                    <h6>Accuracy first</h6>
                    <ul>
                        <li>Check the room number, dates, hours and guest count before saving.</li>
                        <li>A wrong guest record propagates into requests, room status and every report built from it.</li>
                        <li>Use the search box instead of scrolling — the lists are long.</li>
                    </ul>
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
                    <p>The reviews guests leave can be read by <strong>Marle AI</strong> &mdash; the built-in sentiment service behind the <em>"Powered by Groq (Qwen 3)"</em> badge on the Reviews page. For each comment it assigns a label (<strong>positive</strong>, <strong>neutral</strong> or <strong>negative</strong>), a strength score between &minus;1.0 and +1.0, and the <strong>main theme</strong> the guest is talking about: cleanliness, staff, location, amenities, value, food, noise, maintenance or other.</p>

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
                        <li>Every review keeps its own label on the list &mdash; read the actual comment behind a number before raising it at the huddle.</li>
                    </ul>

                    <h6>How it reads Filipino reviews</h6>
                    <p>The AI is taught to read <strong>English, Tagalog and Taglish</strong>, including informal spelling and slang: <em>"npaka sungit ng front desk nku"</em> is decoded as <em>"the front desk was very rude"</em> (negative, theme: staff); <em>"sulit"</em> counts as worth it (positive); <em>"ok lang"</em> stays neutral, and indifference (<em>"kebs lang"</em>, <em>"bahala na"</em>) stays neutral too unless the guest names a real problem (<em>"walang kwenta"</em> = worthless, negative); and sarcastic praise like <em>"galing galing nman ha"</em> after a complaint counts as negative. The guest's <strong>star rating is passed along as a supporting signal</strong> for ambiguous wording &mdash; but a clearly worded comment always outweighs the stars.</p>

                    <h6>Limits to keep in mind</h6>
                    <ul>
                        <li><strong>It is a helper, not a judge.</strong> Very short replies ("meh"), heavy sarcasm or brand-new slang can still land on the wrong label &mdash; read the comments themselves before acting on a label.</li>
                        <li><strong>Neutral clusters are a flag.</strong> If a run returns many neutrals, read them yourself; the AI may simply not know a new slang word yet.</li>
                        <li><strong>It reads text only.</strong> An empty comment or a stars-only rating gives it nothing to work with.</li>
                        <li><strong>It never edits data.</strong> Labels are read-only notes on top of the reviews &mdash; nothing you do with Marle AI changes a guest record, a bill or a room status.</li>
                        <li><strong>It needs the internet.</strong> If the analysis cannot reach the AI service, the page says so and nothing is half-written; previously saved labels remain available.</li>
                    </ul>

                    <h6>Good habits</h6>
                    <ul>
                        <li>Analyse one scope at a time ("Restaurant, this month") &mdash; a report is only as good as the scope behind it.</li>
                        <li>Chase <strong>repeating themes</strong>, not single reviews: one bad night is noise, the same complaint in five reviews is a pattern worth telling the manager about.</li>
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
                    <h6>Your account</h6>
                    <ul>
                        <li>Never share your username or password, and never let a colleague work under your login.</li>
                        <li>Every action you take is recorded with your username, the time and the device — that record is what protects you if a room's charges are questioned.</li>
                        <li>Change your password from <strong>Settings → Account Settings</strong> if you think it was seen.</li>
                        <li>If your account is deactivated you lose access immediately; only the owner can restore it.</li>
                    </ul>

                    <h6>The counter device</h6>
                    <ul>
                        <li>Sign out before leaving the desk, even for a short break.</li>
                        <li>Do not leave the collection modal open with a guest's bill on screen.</li>
                        <li>Do not install anything on the front desk computer or let guests use it.</li>
                    </ul>

                    <h6>Guest data</h6>
                    <ul>
                        <li>Do not export CSV files to personal devices or send them through personal messaging apps.</li>
                        <li>Enter only the information the guest actually provided.</li>
                        <li>Discuss a guest's bill only with that guest.</li>
                    </ul>

                    <h6>If something goes wrong</h6>
                    <p>Report it immediately to the owner or manager and describe exactly what you did. Silent mistakes are much harder to correct than reported ones.</p>
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
                    <p><strong>I was signed out in the middle of my shift.</strong><br>
                        Either 30 minutes passed without activity, or the account was deactivated. Sign in again — if it happens twice in a row, ask the owner to check your account status.</p>

                    <p><strong>My password does not work.</strong><br>
                        Check that Caps Lock is off. If the message says the account was deactivated, only the owner can reactivate it. Otherwise ask the owner to reset the password.</p>

                    <p><strong>The guest cannot sign in to the portal.</strong><br>
                        Confirm the token was issued for the right room, that the guest typed it correctly, and that the stay has not expired. Issue a new token from Guest Tokens if the old one is expired or lost.</p>

                    <p><strong>A room still shows as occupied but the guest has left.</strong><br>
                        The token countdown is probably still running and the system will check the guest out automatically at zero. To free the room sooner, open <strong>Guest Record</strong>, find the stay and set its status to <em>checked-out</em> — the room leaves the occupied list immediately and the action is logged.</p>

                    <p><strong>Collect Payment does nothing.</strong><br>
                        That room has no unpaid balance left (check the chip on the card), or a collection is already being saved. Reload the page and look at the unpaid total before trying again.</p>

                    <p><strong>I approved the wrong request.</strong><br>
                        Tell the owner or manager straight away. Approvals create payable entries on the room's bill, and only they can reverse or adjust them.</p>

                    <p><strong>Where are the Transactions page and the reports?</strong><br>
                        Not part of the Front Desk role. The counter collects per room from the occupied-room cards; itemized history, summaries and printed reports are produced by the manager or owner.</p>

                    <p><strong>The page looks broken or an image is missing.</strong><br>
                        Reload once (Ctrl+F5). If it persists, note the page name and what you clicked, then report it to the owner.</p>

                    <p><strong>The AI labeled a review wrong.</strong><br>
                        It happens with heavy sarcasm or brand-new slang &mdash; treat the label as a hint and read the comment itself. Re-running the analysis refreshes labels without duplicates; report the exact phrase that fooled it so the AI's word guide can be taught the term.</p>
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
                        Questions about these terms should be raised with the hotel owner
                        before the account is used.
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

