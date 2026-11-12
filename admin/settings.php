<?php
// TODO: Admin role should only archive, not update/delete.
// TODO: Admin role should not be allowed to edit here.
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.settings');
include('../admin/auth.php');
require_once __DIR__ . '/../includes/log_action.php';
include('../csrf.php');
require_capability('manage_catalog');
include('../admin/requests_badge.php');
include('../admin/includes/notif_data.php');

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
$userMgmtSuccess = '';
$userMgmtError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
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
// Creating staff accounts is an owner-only action. Managers keep Front Desk
// access control (deactivate / reactivate, below) but the Add Front Desk Account
// form was removed from this page, so this guard now only catches a crafted POST.
if (isset($_POST['create_user'])) {
    $userMgmtError = 'Only the owner can create staff accounts. Ask the owner to add the Front Desk account.';
    log_event($conn, 'user_created', 'user', null, 'denied', 'Manager attempted to create a staff account (owner-only action)');
}

// ── Access control for Front Desk accounts is owner-only ──────────────────
// Managers see a read-only list of Front Desk accounts. Both account creation
// and deactivation/reactivation happen in the owner's Settings page.
if (isset($_POST['toggle_user_status'])) {
    $userMgmtError = 'Only the owner can activate or deactivate accounts. Ask the owner to manage access in Settings → Users.';
    log_event($conn, 'user_status_changed', 'user', null, 'denied', 'Manager attempted to change account status (owner-only action)');
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

// Front Desk (employee) accounts only — manager scope
$staffResult = mysqli_query($conn, "SELECT id, username, usertype, status FROM userstbl WHERE usertype = 'employee' ORDER BY id ASC");
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

        /* Bootstrap's outline buttons must keep their own colour. The .btn rule
           above sets white text, which makes outline buttons an invisible label
           on a transparent background until hovered. */
        .btn-outline-danger {
            color: #dc3545;
            border-color: #dc3545;
        }

        .btn-outline-success {
            color: #198754;
            border-color: #198754;
        }

        .btn-outline-danger:hover {
            background-color: #dc3545;
            border-color: #dc3545;
            color: #fff;
        }

        .btn-outline-success:hover {
            background-color: #198754;
            border-color: #198754;
            color: #fff;
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

<body class="settings-page staff-settings-page staff-admin staff-page">

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
                <section class="settings-intro-card settings-section" data-search-section="overview workspace account users tools rooms amenities offers homepage content settings">
                    <span class="settings-section-kicker">Overview</span>
                    <h3>Everything important stays in one place.</h3>
                    <p>Use this page to keep your account updated.</p>
                    <div class="settings-chip-row">
                        <span class="settings-chip"><i class="bi bi-person-gear"></i> Account</span>
                        <span class="settings-chip"><i class="bi bi-tools"></i> Tools</span>
                    </div>
                </section>
            </div>

            <div class="col-12 col-xl-10">
                <section class="settings-card settings-section" data-search-section="account profile username password profile picture security login credentials">
                    <div class="settings-section-header">
                        <div>
                            <span class="settings-section-kicker">Profile</span>
                            <h4 class="settings-section-title">Account Settings</h4>
                            <p class="settings-section-text">Update your profile picture, username, and password without changing how the page works behind the scenes.</p>
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
                                <div class="settings-note mb-4">
                                    Keep username and password changes here so profile updates remain simple and predictable.
                                </div>

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
                <section class="settings-card settings-section" data-search-section="front desk accounts employees staff access user management owner">
                    <div class="settings-section-header">
                        <div>
                            <span class="settings-section-kicker">Front Desk</span>
                            <h4 class="settings-section-title">Front Desk Accounts</h4>
                            <p class="settings-section-text">An overview of the Front Desk (employee) accounts used at reception. This list is read-only — the owner creates accounts and handles activation, deactivation and access control.</p>
                        </div>
                    </div>

                    <?php if (!empty($userMgmtSuccess)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle me-1"></i><?php echo $userMgmtSuccess; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($userMgmtError)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle me-1"></i><?php echo htmlspecialchars($userMgmtError); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="settings-note">
                        <strong>Access control is handled by the owner.</strong><br>
                        The owner creates accounts and can deactivate or reactivate them in <em>Settings &rarr; Users</em>. If someone has left the hotel, ask the owner to deactivate their account &mdash; they are signed out immediately and cannot sign in again.
                    </div>

                    <div class="table-responsive mt-4">
                        <table class="table settings-table text-center align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($staffResult && mysqli_num_rows($staffResult) > 0): ?>
                                    <?php while ($staff = mysqli_fetch_assoc($staffResult)):
                                        $staffActive = (($staff['status'] ?? 'active') !== 'deactivated');
                                    ?>
                                        <tr>
                                            <td><?php echo (int) $staff['id']; ?></td>
                                            <td><?php echo htmlspecialchars($staff['username']); ?></td>
                                            <td><span class="settings-role-badge"><?php echo htmlspecialchars($staff['usertype']); ?></span></td>
                                            <td>
                                                <span class="badge <?php echo $staffActive ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo $staffActive ? 'Active' : 'Deactivated'; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-muted">No Front Desk accounts yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="col-12 col-xl-10">
                <section class="settings-card settings-section" data-search-section="tools user manual documentation support system help guide">
                    <div class="settings-section-header">
                        <div>
                            <span class="settings-section-kicker">System Tools</span>
                            <h4 class="settings-section-title">Maintenance Shortcuts</h4>
                            <p class="settings-section-text">Keep the documentation and references you need while you work close by.</p>
                        </div>
                    </div>

                    <div class="settings-tool-grid">
                        <a href="../admin/userManual.php" class="settings-tool-card">
                            <span class="settings-tool-icon"><i class="bi bi-journal-text"></i></span>
                            <span class="settings-tool-title">User Manual</span>
                            <span class="settings-tool-text">Open the guide for workflows, references, and admin help documentation.</span>
                        </a>
                    </div>
                </section>
            </div>

        </div>
    </div>

            <!-- Homepage settings modal -->
            <div class="modal fade" id="editRoomsModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="id" id="edit-id">

                            <div class="modal-header">
                                <h5 class="modal-title">Edit Room Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>

                            <div class="modal-body text-start">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Room Name</label>
                                    <input class="form-control" type="text" name="rooms" id="edit-rooms" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Replace Image (Optional)</label>
                                    <input class="form-control" type="file" name="room_image" accept="image/*">
                                    <small class="text-muted">Leave empty to keep the current image.</small>
                                </div>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="updateItem" class="btn" id="yesBtn">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

             <!-- Ameneties modal -->
            <div class="modal fade" id="amenityModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <div class="modal-header">
                                <h5>Edit Amenity</h5><button type="button" class="btn-close"
                                    data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="id" id="am-id">
                                <label>Title</label>
                                <input type="text" name="title" id="am-title" class="form-control mb-2" required>
                                <label>Description</label>
                                <textarea name="description" id="am-desc" class="form-control" rows="3"
                                    required></textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="submit" name="update_amenity" class="btn" id="yesBtn">Save
                                    Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

              <!-- Offers modal -->
            <div class="modal fade" id="offerModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <div class="modal-header">
                                <h5>Edit Offer</h5><button type="button" class="btn-close"
                                    data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="id" id="of-id">
                                <label>Title</label>
                                <input type="text" name="title" id="of-title" class="form-control mb-2" required>
                                <label>Description</label>
                                <textarea name="description" id="of-desc" class="form-control" rows="3"
                                    required></textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="submit" name="update_offer" class="btn" id="yesBtn">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>

     <!-- Modals -->
    <script>
        // Homepage
        document.addEventListener("DOMContentLoaded", function () {
            const editButtons = document.querySelectorAll(".editBtn");

            editButtons.forEach(button => {
                button.addEventListener("click", function () {
                    document.getElementById("edit-id").value = this.dataset.id;
                    document.getElementById("edit-rooms").value = this.dataset.rooms;
                });
            });
        });

        // Amenities
        document.addEventListener("DOMContentLoaded", function () {
            document.querySelectorAll(".edit-amenity-btn").forEach(btn => {
                btn.addEventListener("click", function () {
                    document.getElementById("am-id").value = this.dataset.id;
                    document.getElementById("am-title").value = this.dataset.title;
                    document.getElementById("am-desc").value = this.dataset.desc;
                });
            });

            // Offers
            document.querySelectorAll(".edit-offer-btn").forEach(btn => {
                btn.addEventListener("click", function () {
                    document.getElementById("of-id").value = this.dataset.id;
                    document.getElementById("of-title").value = this.dataset.title;
                    document.getElementById("of-desc").value = this.dataset.desc;
                });
            });

            const settingsSearch = document.getElementById("myInput");
            const settingsSections = document.querySelectorAll(".settings-section");

            if (settingsSearch && settingsSections.length) {
                settingsSearch.addEventListener("input", function () {
                    const query = this.value.trim().toLowerCase();

                    settingsSections.forEach(section => {
                        const sectionText = (section.dataset.searchSection || section.innerText || "").toLowerCase();
                        const tableRows = section.querySelectorAll("tbody tr");
                        const emptyState = section.querySelector(".settings-empty");
                        let visibleRows = 0;

                        tableRows.forEach(row => {
                            const matchesRow = !query || row.innerText.toLowerCase().includes(query);
                            row.style.display = matchesRow ? "" : "none";
                            if (matchesRow) {
                                visibleRows++;
                            }
                        });

                        if (emptyState) {
                            emptyState.classList.toggle("is-visible", !!query && tableRows.length > 0 && visibleRows === 0);
                        }

                        const column = section.closest("[class*='col-']");
                        const matchesSection = !query || sectionText.includes(query) || visibleRows > 0;

                        if (column) {
                            column.style.display = matchesSection ? "" : "none";
                        }
                    });
                });
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
    <script src="../admin/includes/notif_poll.js"></script>
</body>

</html>



