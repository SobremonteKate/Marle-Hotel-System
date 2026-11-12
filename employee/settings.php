<?php
// TODO: Employee limited permissions need deeper checks on this page/action.
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.settings');
include('../employee/auth.php');
require_once __DIR__ . '/../includes/log_action.php';
include('../csrf.php');
include('../employee/requests_badge.php');
include('../employee/includes/notif_data.php');

// Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: ../login.php?logout=success");
    exit();
}

$currentUsername = $_SESSION['username'];
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
}

// Catalog and user-management mutations stay with managers/owner only.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !has_capability('manage_catalog')
    && (isset($_POST['updateItem']) || isset($_POST['update_amenity']) || isset($_POST['update_offer'])
        || isset($_POST['create_user']) || isset($_POST['toggle_user_status']))) {
    http_response_code(403);
    exit('Not authorized.');
}

// ── Handle Rooms / Amenities / Offers updates ───────────────────────────────
if (isset($_POST['updateItem'])) {
    $roomId = intval($_POST['id'] ?? 0);
    $rooms  = trim($_POST['rooms'] ?? '');

    if ($roomId <= 0 || $rooms === '') {
        $errorMsg = 'Invalid room data.';
    } else {
        $updateFields = ['rooms = ?'];
        $types = 's';
        $params = [$rooms];

        // Optional image update
        if (!empty($_FILES['room_image']['name'])) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $fileType = mime_content_type($_FILES['room_image']['tmp_name']);

            if (!in_array($fileType, $allowedTypes)) {
                $errorMsg = 'Invalid room image type. Please upload JPG, PNG, GIF, or WebP.';
            } elseif ($_FILES['room_image']['size'] > 5 * 1024 * 1024) {
                $errorMsg = 'Room image too large. Maximum size is 5 MB.';
            } else {
                $imageData = file_get_contents($_FILES['room_image']['tmp_name']);
                $updateFields[] = 'image = ?';
                $types .= 's';
                $params[] = $imageData;
            }
        }

        if (empty($errorMsg)) {
            $types .= 'i';
            $params[] = $roomId;

            $sql = "UPDATE roomstbl SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, $types, ...$params);
            if (mysqli_stmt_execute($stmt)) {
                $successMsg = 'Room updated successfully.';
            } else {
                $errorMsg = 'Error updating room: ' . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
    }
}

if (isset($_POST['update_amenity'])) {
    $amenityId = intval($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    if ($amenityId <= 0 || $title === '' || $desc === '') {
        $errorMsg = 'Invalid amenity data.';
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE amenitiestbl SET title = ?, description = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'ssi', $title, $desc, $amenityId);
        if (mysqli_stmt_execute($stmt)) {
            $successMsg = 'Amenity updated successfully.';
        } else {
            $errorMsg = 'Error updating amenity: ' . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
}

if (isset($_POST['update_offer'])) {
    $offerId = intval($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    if ($offerId <= 0 || $title === '' || $desc === '') {
        $errorMsg = 'Invalid offer data.';
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE packagetbl SET title = ?, description = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'ssi', $title, $desc, $offerId);
        if (mysqli_stmt_execute($stmt)) {
            $successMsg = 'Offer updated successfully.';
        } else {
            $errorMsg = 'Error updating offer: ' . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
}

// ── Handle Add User form ───────────────────────────────────────────────
if (isset($_POST['create_user']) && !has_capability('manage_users')) {
    http_response_code(403);
    exit('Only the owner can create user accounts.');
}

if (isset($_POST['create_user'])) {
    $newUser = trim($_POST['add_username'] ?? '');
    $newPass = trim($_POST['add_password'] ?? '');
    $newType = trim($_POST['add_usertype'] ?? '');

    if ($newUser === '' || $newPass === '' || !in_array($newType, ['superadmin', 'admin', 'employee'], true)) {
        $errorMsg = 'Please provide a valid username, password, and user type.';
    } elseif ($newType === 'superadmin') {
        $errorMsg = 'Only a superadmin can create a superadmin account.';
    } else {
        // Check if username already exists
        $stmtCheck = mysqli_prepare($conn, "SELECT id FROM userstbl WHERE username = ? LIMIT 1");
        mysqli_stmt_bind_param($stmtCheck, 's', $newUser);
        mysqli_stmt_execute($stmtCheck);
        mysqli_stmt_store_result($stmtCheck);

        if (mysqli_stmt_num_rows($stmtCheck) > 0) {
            $errorMsg = 'That username is already taken.';
        }
        mysqli_stmt_close($stmtCheck);

        if (empty($errorMsg)) {
            $hashedPassword = password_hash($newPass, PASSWORD_BCRYPT);
            $stmtInsert     = mysqli_prepare($conn, "INSERT INTO userstbl (username, password, usertype) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmtInsert, 'sss', $newUser, $hashedPassword, $newType);

            if (mysqli_stmt_execute($stmtInsert)) {
                $successMsg = 'New user added successfully.';
            } else {
                $errorMsg = 'Error creating new user: ' . mysqli_error($conn);
            }

            mysqli_stmt_close($stmtInsert);
        }
    }
}

// ── Handle Account Settings form ───────────────────────────────────────────
if (isset($_POST['save_changes'])) {

    $newUsername    = trim($_POST['new_username']    ?? '');
    $oldPassword    = $_POST['old_password']         ?? '';
    $newPassword    = $_POST['new_password']         ?? '';
    $confirmPassword = $_POST['confirm_password']    ?? '';

    // Fetch current user row
    $stmtFetch = mysqli_prepare($conn, "SELECT id, username, password FROM userstbl WHERE username = ? LIMIT 1");
    mysqli_stmt_bind_param($stmtFetch, 's', $currentUsername);
    mysqli_stmt_execute($stmtFetch);
    $resFetch = mysqli_stmt_get_result($stmtFetch);
    $userRow  = mysqli_fetch_assoc($resFetch);
    mysqli_stmt_close($stmtFetch);

    if (!$userRow) {
        $errorMsg = 'User not found.';
    } else {
        $userId      = $userRow['id'];
        $dbPassword  = $userRow['password'];

        $updateFields = [];
        $types        = '';
        $params       = [];

        // ── Profile picture ────────────────────────────────────────────────
        if (!empty($_FILES['profile_picture']['name'])) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $fileType     = mime_content_type($_FILES['profile_picture']['tmp_name']);

            if (!in_array($fileType, $allowedTypes)) {
                $errorMsg = 'Invalid image type. Please upload JPG, PNG, GIF, or WebP.';
            } elseif ($_FILES['profile_picture']['size'] > 5 * 1024 * 1024) {
                $errorMsg = 'Image too large. Maximum size is 5 MB.';
            } else {
                $imageData      = file_get_contents($_FILES['profile_picture']['tmp_name']);
                $updateFields[] = 'picture = ?';
                $types         .= 's';
                $params[]       = $imageData;
            }
        }

        if (empty($errorMsg)) {
            // ── Username change ────────────────────────────────────────────
            if (!empty($newUsername) && $newUsername !== $currentUsername) {
                // Check if username already taken
                $stmtCheck = mysqli_prepare($conn, "SELECT id FROM userstbl WHERE username = ? LIMIT 1");
                mysqli_stmt_bind_param($stmtCheck, 's', $newUsername);
                mysqli_stmt_execute($stmtCheck);
                mysqli_stmt_store_result($stmtCheck);
                if (mysqli_stmt_num_rows($stmtCheck) > 0) {
                    $errorMsg = 'That username is already taken.';
                }
                mysqli_stmt_close($stmtCheck);

                if (empty($errorMsg)) {
                    $updateFields[] = 'username = ?';
                    $types         .= 's';
                    $params[]       = $newUsername;
                }
            }

            // ── Password change ────────────────────────────────────────────
            if (empty($errorMsg) && (!empty($oldPassword) || !empty($newPassword) || !empty($confirmPassword))) {
                if (empty($oldPassword)) {
                    $errorMsg = 'Please enter your current password to change it.';
                } elseif (!password_verify($oldPassword, $dbPassword)) {
                    $errorMsg = 'Current password is incorrect.';
                } elseif (empty($newPassword)) {
                    $errorMsg = 'New password cannot be empty.';
                } elseif ($newPassword !== $confirmPassword) {
                    $errorMsg = 'New password and confirm password do not match.';
                } elseif (strlen($newPassword) < 6) {
                    $errorMsg = 'New password must be at least 6 characters.';
                } else {
                    $hashedNew      = password_hash($newPassword, PASSWORD_BCRYPT);
                    $updateFields[] = 'password = ?';
                    $types         .= 's';
                    $params[]       = $hashedNew;
                }
            }

            // ── Run the UPDATE if there is anything to save ────────────────
            if (empty($errorMsg) && !empty($updateFields)) {
                $types   .= 'i';
                $params[] = $userId;

                $sql = "UPDATE userstbl SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmtUpd = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmtUpd, $types, ...$params);

                if (mysqli_stmt_execute($stmtUpd)) {
                    // Update session username if it changed
                    if (!empty($newUsername) && $newUsername !== $currentUsername) {
                        $_SESSION['username'] = $newUsername;
                        $currentUsername      = $newUsername;
                    }
                    $changed = [];
                    foreach ($updateFields as $uf) { $changed[] = explode(' ', $uf)[0]; }
                    log_event($conn, 'account_settings_updated', 'user', $userId, 'success', 'Changed: ' . implode(', ', $changed));
                    $successMsg = 'Changes saved successfully!';
                } else {
                    $errorMsg = 'Database error: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($stmtUpd);

            } elseif (empty($errorMsg) && empty($updateFields)) {
                $successMsg = 'No changes were made.';
            }
        }
    }
}

$sql    = "SELECT * FROM userstbl";
$result = mysqli_query($conn, $sql);
?>

<!DOCTYPE html>
<html lang="<?= ui_html_lang() ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-4Q6Gf2aSP4eDXB8Miphtr37CMZZQ5oXLH2yaXMJ2w8e2ZtHTl7GptT4jmndRuHDT" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.lineicons.com/5.0/lineicons.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="https://cdn.lineicons.com/5.0/lineicons.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <!-- icon, to be changed -->
    <?php require __DIR__ . '/../includes/staff_icons.php'; ?>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
    <link rel="stylesheet" href="../css/staff-footer.css?v=20261021">
    <title>Settings</title>

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
            /* padding-left: 20px; */
        }

        #dashboard-link {
            text-decoration: none;
            color: #ffcc66;
        }

        /* Cards */
        .card {
            border-radius: 20px;
            padding-left: 15px;
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

        .profile-pic-container {
            position: relative;
            /* Essential for positioning the button over the image */
            width: 150px;
            height: 150px;
            border: 1px solid #ffb60a;
            border-radius: 50%;
        }

        .profile-pic {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            display: block;
        }

        .upload-button {
            position: absolute;
            bottom: 0;
            right: 0;
            background-color: #ffb60a;
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            cursor: pointer;
            font-size: 12px;
        }

        .upload-button:hover {
            background-color: #933310;
            /* border-color: #933310; */
            color: #fff;
        }

        .form-control {
            height: 50px;
            border-radius: 12px;
            border: 1px solid #ffb60a;
        }

        .form-control:focus {
            box-shadow: none;
            border-color: #933310;
        }

        /* Footer */
        .footer {
            margin-top: 3rem;
            margin-bottom: 0;
            text-decoration: none;
            color: #933310;
        }

        .lni {
            font-size: 25px;
            transition: transform 0.3s ease;
        }

        .lni:hover {
            color: #933310;
            transform: scale(1.1);
        }
    </style>
    <link rel="stylesheet" href="../css/settings-ui.css">
    <link rel="stylesheet" href="../css/staff-settings.css">
    <link rel="stylesheet" href="../css/staff-logout.css">

<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>

<body class="settings-page staff-settings-page staff-employee staff-page">

    <?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

    <!-- Welcome message + search -->
    <?php
    $staffPageTitle  = 'Settings';
    $staffPageNote   = 'Account details, user access, system tools and homepage content in one workspace.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['Settings', null]];
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

    <!-- Main content -->
    <div class="container settings-shell">
        <div class="row g-4 justify-content-center">

            <div class="col-12 col-xl-10">
                <section class="settings-intro-card settings-section" data-search-section="overview employee account tools guide support settings">
                    <span class="settings-section-kicker">Overview</span>
                    <h3>Employee tools stay focused here.</h3>
                    <p>Use this page to update your account and open employee-only help resources without leaving the front-desk workspace.</p>
                    <div class="settings-chip-row">
                        <span class="settings-chip"><i class="bi bi-person-gear"></i> Your Account</span>
                        <span class="settings-chip"><i class="bi bi-journal-text"></i> Support Guide</span>
                    </div>
                </section>
            </div>

            <div class="col-12 col-xl-10">
                <section class="settings-card settings-section" data-search-section="account profile username password profile picture security login credentials your account">
                    <div class="settings-section-header">
                        <div>
                            <span class="settings-section-kicker">Profile</span>
                            <h4 class="settings-section-title">Account Settings</h4>
                            <p class="settings-section-text">Update your profile picture, username, and password. Catalog and user management stay with the manager and owner accounts.</p>
                        </div>
                    </div>

                    <?php if (!empty($successMsg)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($successMsg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($errorMsg)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($errorMsg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data" class="settings-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                        <?php
                        $sqlPic = "SELECT picture FROM userstbl WHERE username = ? LIMIT 1";
                        $stmtPic = mysqli_prepare($conn, $sqlPic);
                        mysqli_stmt_bind_param($stmtPic, 's', $currentUsername);
                        mysqli_stmt_execute($stmtPic);
                        $resPic = mysqli_stmt_get_result($stmtPic);
                        $picRow = mysqli_fetch_assoc($resPic);
                        mysqli_stmt_close($stmtPic);

                        if (!empty($picRow['picture'])) {
                            $finfoPic  = new finfo(FILEINFO_MIME_TYPE);
                            $mimePic   = $finfoPic->buffer($picRow['picture']);
                            $settingsPicSrc = "data:$mimePic;base64," . base64_encode($picRow['picture']);
                        } else {
                            $settingsPicSrc = "../img/default.jpg";
                        }
                        ?>
                        <div class="row g-4 align-items-stretch">
                            <div class="col-lg-4">
                                <div class="settings-profile-panel">
                                    <div class="profile-pic-container">
                                        <img src="<?php echo $settingsPicSrc; ?>" alt="Profile Picture" id="profileImage" class="profile-pic">
                                        <input type="file" id="imageUpload" name="profile_picture" accept="image/*" hidden>
                                        <label for="imageUpload" class="upload-button">Change</label>
                                    </div>
                                    <p class="settings-profile-copy">Use a square image for the cleanest result. Accepted formats: JPG, PNG, GIF, and WebP up to 5 MB.</p>
                                </div>
                            </div>

                            <div class="col-lg-8">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Username</label>
                                        <input type="text" name="new_username" class="form-control mb-0"
                                            value="<?php echo htmlspecialchars($currentUsername); ?>"
                                            autocomplete="off" placeholder="Enter new username">
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Current Password</label>
                                        <input type="password" name="old_password" class="form-control mb-0"
                                            placeholder="Required to change password">
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">New Password</label>
                                        <input type="password" name="new_password" class="form-control mb-0"
                                            placeholder="Leave blank to keep current">
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Confirm New Password</label>
                                        <input type="password" name="confirm_password" class="form-control mb-0"
                                            placeholder="Repeat new password">
                                    </div>

                                    <div class="col-12">
                                        <button type="submit" name="save_changes" class="btn settings-submit-btn w-100">Save Changes</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </section>
            </div>


            <div class="col-12 col-xl-10">
                <section class="settings-card settings-section" data-search-section="tools user manual documentation support employee guide system">
                    <div class="settings-section-header">
                        <div>
                            <span class="settings-section-kicker">Employee Support</span>
                            <h4 class="settings-section-title">Reference Tools</h4>
                            <p class="settings-section-text">Open employee guidance and day-to-day support references from one place.</p>
                        </div>
                    </div>

                    <div class="settings-tool-grid">
                        <button type="button" class="settings-tool-card settings-tool-button" data-bs-toggle="modal" data-bs-target="#userManualModal">
                            <span class="settings-tool-icon"><i class="bi bi-journal-text"></i></span>
                            <span class="settings-tool-title">User Manual</span>
                            <span class="settings-tool-text">Skim the day-to-day quick reference. The complete guide is in the sidebar under <strong>User Manual</strong>.</span>
                        </button>
                    </div>
                </section>
            </div>

        </div>
    </div>

    <!-- Footer — one design for all three roles (includes/staff_footer.php) -->
    <?php $marleFooterTerms = true; require __DIR__ . '/../includes/staff_footer.php'; ?>

    <!-- Employee User Manual Modal -->
    <div class="modal fade" id="userManualModal" tabindex="-1" aria-labelledby="userManualModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="userManualModalLabel">Front Desk Quick Reference</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body settings-manual-body" style="max-height: 75vh; overflow-y: auto;">
                    <div class="settings-manual-hero mb-4">
                        <span class="settings-section-kicker">Front Desk</span>
                        <h5 class="settings-manual-title">A short reference while you work</h5>
                        <p class="mb-0">This is the condensed version: what each Front Desk page is for and the reminders that keep records accurate.
                            For the full guide &mdash; sign-in rules, session limits, the guest portal journey, payment collection and the FAQ &mdash;
                            open <a href="../employee/userManual.php"><strong>User Manual</strong></a> in the sidebar.</p>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="settings-note h-100">
                                <strong>Allowed Access</strong><br>
                                Work only within the employee pages shown in the sidebar. Follow on-screen controls and keep guest and room records accurate.
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="settings-note h-100">
                                <strong>Sidebar Flow</strong><br>
                                Move page by page through the sidebar to monitor operations, update records, check schedules, and read guest feedback.
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="settings-note h-100">
                                <strong>Security Basics</strong><br>
                                Protect your login, verify entries before saving, and log out after use especially on shared front-desk devices.
                            </div>
                        </div>
                    </div>

                    <div class="settings-manual-nav mb-4">
                        <a href="#manual-dashboard">Dashboard</a>
                        <a href="#manual-requests">Requests</a>
                        <a href="#manual-calendar">Calendar</a>
                        <a href="#manual-inventory">Inventory</a>
                        <a href="#manual-guest-record">Guest Record</a>
                        <a href="#manual-reviews">Reviews</a>
                        <a href="#manual-guest-tokens">Guest Tokens</a>
                        <a href="#manual-collect">Collect Payment</a>
                        <a href="#manual-settings">Settings</a>
                    </div>

                    <section class="settings-manual-section" id="manual-dashboard">
                        <h5>1. Dashboard</h5>
                        <p><strong>Purpose:</strong> Use the dashboard as your first overview of current operations, room activity, and quick metrics.</p>
                        <p><strong>Main actions:</strong> Review the summary cards, watch the room board, and use the dashboard to spot rooms or activity that need attention.</p>
                        <p><strong>Important reminders:</strong> Treat the dashboard as a monitoring page. Use it to identify where to go next, then open the correct module for detailed updates.</p>
                    </section>

                    <section class="settings-manual-section" id="manual-requests">
                        <h5>2. Requests</h5>
                        <p><strong>Purpose:</strong> Manage guest requests in one place, including booking, extension, service, and food-related requests.</p>
                        <p><strong>Main actions:</strong> Review pending requests, open request details, update statuses, respond through the available request flow, and monitor room-specific activity and archived request history where shown.</p>
                        <p><strong>Important reminders:</strong> Read request details carefully before updating status. Make sure the action you choose matches the guest concern or service being requested.</p>
                    </section>

                    <section class="settings-manual-section" id="manual-calendar">
                        <h5>3. Calendar</h5>
                        <p><strong>Purpose:</strong> View schedules and upcoming items in calendar format so front-desk activity stays organized.</p>
                        <p><strong>Main actions:</strong> Check the calendar view, switch between available calendar displays, and open event details when you need more context.</p>
                        <p><strong>Important reminders:</strong> Use the calendar to confirm timing and schedule visibility. If an event looks incorrect, verify details against the source record before acting on it.</p>
                    </section>

                    <section class="settings-manual-section" id="manual-inventory">
                        <h5>4. Inventory</h5>
                        <p><strong>Purpose:</strong> Track stock levels and maintain the list of inventory items used in operations.</p>
                        <p><strong>Main actions:</strong> Search items, filter for low stock, add items, review quantities by category, export CSV data, and archive selected records when needed.</p>
                        <p><strong>Important reminders:</strong> Double-check item names, categories, and quantities before saving. Watch low-stock indicators so supplies can be addressed early.</p>
                    </section>

                    <section class="settings-manual-section" id="manual-guest-record">
                        <h5>5. Guest Record</h5>
                        <p><strong>Purpose:</strong> Maintain guest stay records, room assignments, check-in information, and status tracking.</p>
                        <p><strong>Main actions:</strong> Search and filter records, export CSV data, review stay dates and additional items, and update a stay's status (such as checking a guest out early). Adding or editing guest entries is a manager-and-owner action.</p>
                        <p><strong>Important reminders:</strong> Confirm room number, date ranges, guest count, and status before saving. Incorrect guest records can affect requests, room status, and reporting.</p>
                    </section>

                    <section class="settings-manual-section" id="manual-reviews">
                        <h5>6. Reviews</h5>
                        <p><strong>Purpose:</strong> Monitor guest feedback, ratings, and sentiment summaries to understand service performance.</p>
                        <p><strong>Main actions:</strong> Read submitted reviews, use page filters and counts, check rating summaries, and review sentiment or feedback trends shown on the page.</p>
                        <p><strong>Important reminders:</strong> Use reviews for awareness and service improvement. Do not treat summary charts alone as the full picture without reading the actual guest comments.</p>
                    </section>

                    <section class="settings-manual-section" id="manual-guest-tokens">
                        <h5>7. Guest Tokens &mdash; Occupied Rooms</h5>
                        <p><strong>Purpose:</strong> See which rooms are occupied, issue portal access tokens, and collect the room's bill.</p>
                        <p><strong>Main actions:</strong> Select the correct room, enter stay details such as persons and hours of stay, record vehicle plate information when available, and generate the access token for the guest. Each room card is titled with its number (for example <strong>Room 8</strong>) and shows the room's unpaid total, its settled and unsettled item counts, and a breakdown of check-in, extension, service and food charges.</p>
                        <p><strong>Important reminders:</strong> Make sure the selected room is correct and available before issuing a token. Incorrect room or stay information can cause guest access problems.</p>
                    </section>

                    <section class="settings-manual-section" id="manual-collect">
                        <h5>8. Collect Payment (front-desk scope)</h5>
                        <p><strong>Purpose:</strong> Record what the guest pays at the counter against the room's unpaid balance.</p>
                        <p><strong>Main actions:</strong> Open <strong>Collect Payment</strong> on the room card, check the amount due the system calculated, type the amount received, confirm the change shown, and submit once.</p>
                        <p><strong>Important reminders:</strong> Underpayment is refused, the amount due is fixed by the system, and the button locks while saving so a collection cannot be recorded twice. The Front Desk has <strong>no</strong> Transactions ledger and <strong>no</strong> report export &mdash; itemized history and printed reports belong to the manager and owner.</p>
                    </section>

                    <section class="settings-manual-section" id="manual-settings">
                        <h5>9. Settings</h5>
                        <p><strong>Purpose:</strong> Access employee support tools and system guidance from one place.</p>
                        <p><strong>Main actions:</strong> Open this employee user manual, review terms of use, and use any settings tools currently shown on the page.</p>
                        <p><strong>Important reminders:</strong> Settings should support your daily work, not replace record verification. Always confirm changes or actions in the proper operational page before finalizing them.</p>
                    </section>

                    <div class="settings-note mt-4">
                        <strong>Final reminder:</strong> If a page contains important guest, room, inventory, or payment information, slow down before saving. Small entry mistakes can affect multiple parts of the system.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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

    <!-- Live preview when user picks a new profile picture -->
    <script>
        const imageUpload = document.getElementById('imageUpload');
        const profileImage = document.getElementById('profileImage');
        if (imageUpload && profileImage) {
            imageUpload.addEventListener('change', function (event) {
                const [file] = event.target.files;
                if (file) {
                    profileImage.src = URL.createObjectURL(file);
                }
            });
        }
    </script>

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
                    <a href="?action=logout" class="btn" id="yesBtn">Yes</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-j1CDi7MgGQ12Z7Qab0qlWQ/Qqz24Gc6BM0thvEMVjHnfYGF0rmFCozFSxQBxwHKO"
        crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../employee/includes/notif_poll.js"></script>
</body>

</html>




