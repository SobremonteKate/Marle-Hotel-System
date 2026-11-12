<?php
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.settings');
include('../superadmin/auth.php');
require_once __DIR__ . '/../includes/log_action.php';
require_once __DIR__ . '/../includes/booking_deposit.php';
include('../csrf.php');
include('../superadmin/requests_badge.php');
include('../superadmin/includes/notif_data.php');

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
$depositSuccess  = '';
$depositError    = '';

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

// ── Handle Reservation Deposit policy (percentage + how guests pay) ─────
// The guest sees these values on the booking status page and in every
// confirmation email, so they are read back from site_options (never from the
// form) wherever money is shown. Changing them re-prices future bookings only:
// an existing booking keeps the amount it was quoted.
if (isset($_POST['save_deposit_policy'])) {
    $rateRaw = trim((string)($_POST['deposit_rate'] ?? ''));
    $gcash   = trim((string)($_POST['deposit_gcash_number'] ?? ''));
    $bank    = trim((string)($_POST['deposit_bank_details'] ?? ''));
    $holdRaw = trim((string)($_POST['deposit_hold_hours'] ?? ''));

    // Read the current values first so the audit trail can show what changed.
    $rateBefore  = marle_deposit_rate($conn);
    $gcashBefore = trim(marle_site_option($conn, 'deposit_gcash_number', ''));
    $bankBefore  = trim(marle_site_option($conn, 'deposit_bank_details', ''));
    $holdBefore  = marle_deposit_hold_hours($conn);

    if ($rateRaw === '' || !is_numeric($rateRaw)) {
        $depositError = 'Enter the deposit as a percentage, for example 50.';
    } elseif ((float)$rateRaw < 1 || (float)$rateRaw > 100) {
        $depositError = 'The deposit percentage must be between 1 and 100.';
    } elseif ($gcash === '' && $bank === '') {
        $depositError = 'Give the guest at least one way to pay — a GCash number or the bank details.';
    } elseif ($gcash !== '' && !preg_match('/^[0-9+().\-\s]{7,20}$/', $gcash)) {
        $depositError = 'That GCash number does not look right. Use digits, spaces and + - ( ) only.';
    } elseif (mb_strlen($bank) > 200) {
        $depositError = 'Shorten the bank details to 200 characters or fewer so they fit in the confirmation email.';
    } elseif ($holdRaw === '' || !is_numeric($holdRaw)
        || (int)round((float)$holdRaw) < MARLE_DEPOSIT_HOLD_HOURS_MIN
        || (int)round((float)$holdRaw) > MARLE_DEPOSIT_HOLD_HOURS_MAX) {
        $depositError = 'Enter the booking hold window in whole hours, between '
            . MARLE_DEPOSIT_HOLD_HOURS_MIN . ' and ' . MARLE_DEPOSIT_HOLD_HOURS_MAX . ' (30 days).';
    } else {
        $rate      = round((float)$rateRaw, 2);
        $rateStore = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
        $holdHours = max(MARLE_DEPOSIT_HOLD_HOURS_MIN, min(MARLE_DEPOSIT_HOLD_HOURS_MAX, (int)round((float)$holdRaw)));

        marle_set_site_option($conn, 'deposit_rate', $rateStore);
        marle_set_site_option($conn, 'deposit_gcash_number', $gcash);
        marle_set_site_option($conn, 'deposit_bank_details', $bank);
        marle_set_site_option($conn, 'deposit_hold_hours', (string)$holdHours);

        log_event(
            $conn,
            'deposit_policy_updated',
            'site_option',
            null,
            'success',
            'Deposit ' . rtrim(rtrim(number_format($rateBefore, 2, '.', ''), '0'), '.') . '% to '
                . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '% · hold '
                . marle_deposit_hold_label($holdBefore) . ' to ' . marle_deposit_hold_label($holdHours),
            [
                'before' => ['deposit_rate' => $rateBefore, 'deposit_gcash_number' => $gcashBefore, 'deposit_bank_details' => $bankBefore, 'deposit_hold_hours' => $holdBefore],
                'after'  => ['deposit_rate' => $rate, 'deposit_gcash_number' => $gcash, 'deposit_bank_details' => $bank, 'deposit_hold_hours' => $holdHours],
            ]
        );

        $depositSuccess = 'Saved. New bookings now ask for '
            . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '% up front and hold the room for '
            . marle_deposit_hold_label($holdHours) . '.';
    }
}

// ── Handle Add User form ───────────────────────────────────────────────
if (isset($_POST['create_user'])) {
    $newUser = trim($_POST['add_username'] ?? '');
    $newPass = trim($_POST['add_password'] ?? '');
    $newType = trim($_POST['add_usertype'] ?? '');

    if ($newUser === '' || $newPass === '' || !in_array($newType, ['superadmin', 'admin', 'employee'], true)) {
        $userMgmtError = 'Please provide a valid username, password, and user type.';
    } else {
        // Check if username already exists
        $stmtCheck = mysqli_prepare($conn, "SELECT id FROM userstbl WHERE username = ? LIMIT 1");
        mysqli_stmt_bind_param($stmtCheck, 's', $newUser);
        mysqli_stmt_execute($stmtCheck);
        mysqli_stmt_store_result($stmtCheck);

        if (mysqli_stmt_num_rows($stmtCheck) > 0) {
            $userMgmtError = 'That username is already taken.';
        }
        mysqli_stmt_close($stmtCheck);

        if (empty($userMgmtError)) {
            $hashedPassword = password_hash($newPass, PASSWORD_BCRYPT);
            $stmtInsert     = mysqli_prepare($conn, "INSERT INTO userstbl (username, password, usertype) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmtInsert, 'sss', $newUser, $hashedPassword, $newType);

            if (mysqli_stmt_execute($stmtInsert)) {
                $newUserId = (int)mysqli_insert_id($conn);
                log_event($conn, 'user_created', 'user', $newUserId, 'success', 'Role: ' . $newType);
                $userMgmtSuccess = 'New user added successfully.';
            } else {
                $userMgmtError = 'Error creating new user: ' . mysqli_error($conn);
            }

            mysqli_stmt_close($stmtInsert);
        }
    }
}

// ── Handle Deactivate / Reactivate user (owner only) ───────────────────
if (isset($_POST['toggle_user_status'])) {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $newStatus    = ($_POST['new_status'] ?? '') === 'deactivated' ? 'deactivated' : 'active';

    // Fetch target user
    $stmtTarget = mysqli_prepare($conn, "SELECT id, username, usertype, status FROM userstbl WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmtTarget, 'i', $targetUserId);
    mysqli_stmt_execute($stmtTarget);
    $targetRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtTarget));
    mysqli_stmt_close($stmtTarget);

    if (!$targetRow) {
        $userMgmtError = 'User not found.';
    } elseif ($targetRow['status'] === $newStatus) {
        $userMgmtError = 'That account is already ' . $newStatus . '.';
    } elseif ($newStatus === 'deactivated' && (int)$targetRow['id'] === (int)($_SESSION['user_id'] ?? 0)) {
        $userMgmtError = 'You cannot deactivate your own account.';
    } elseif (
        $newStatus === 'deactivated'
        && $targetRow['usertype'] === 'superadmin'
    ) {
        // Guard: never lock the owner out — must keep at least one active superadmin
        $stmtLast = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM userstbl WHERE usertype = 'superadmin' AND status = 'active' AND id != ?");
        mysqli_stmt_bind_param($stmtLast, 'i', $targetUserId);
        mysqli_stmt_execute($stmtLast);
        $lastRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtLast));
        mysqli_stmt_close($stmtLast);
        if ((int)($lastRow['c'] ?? 0) < 1) {
            $userMgmtError = 'Cannot deactivate the last active superadmin account.';
        }
    }

    if (empty($userMgmtError)) {
        // Deactivation stamps who/when; reactivation clears the stamp (the
        // permanent record of both lives in audit_log).
        $deactivatedBy = $newStatus === 'deactivated' ? $_SESSION['username'] : null;
        if ($newStatus === 'deactivated') {
            $stmtToggle = mysqli_prepare($conn, "UPDATE userstbl SET status = 'deactivated', deactivated_at = NOW(), deactivated_by = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmtToggle, 'si', $deactivatedBy, $targetUserId);
        } else {
            $stmtToggle = mysqli_prepare($conn, "UPDATE userstbl SET status = 'active', deactivated_at = NULL, deactivated_by = NULL WHERE id = ?");
            mysqli_stmt_bind_param($stmtToggle, 'i', $targetUserId);
        }
        if (mysqli_stmt_execute($stmtToggle)) {
            log_event(
                $conn,
                $newStatus === 'deactivated' ? 'user_deactivated' : 'user_reactivated',
                'user',
                $targetUserId,
                'success',
                'User: ' . $targetRow['username'] . ' (' . $targetRow['usertype'] . ')'
            );
            $userMgmtSuccess = ($newStatus === 'deactivated'
                ? 'Account "' . htmlspecialchars($targetRow['username']) . '" deactivated. They are logged out and can no longer sign in.'
                : 'Account "' . htmlspecialchars($targetRow['username']) . '" reactivated and can sign in again.');
        } else {
            $userMgmtError = 'Database error: ' . mysqli_error($conn);
        }
        mysqli_stmt_close($stmtToggle);
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

        /* Bootstrap's outline buttons must keep their own colour. The .btn rule
           above set white text, which made the Deactivate / Reactivate button
           in the Users table an invisible label on a transparent background
           (it only became readable on hover). */
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
    <link rel="stylesheet" href="../css/settings-ui.css?v=20261010">
    <link rel="stylesheet" href="../css/staff-settings.css">
    <link rel="stylesheet" href="../css/staff-logout.css">

<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>

<body class="settings-page staff-settings-page staff-superadmin staff-page">

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
                    <p>Use this page to keep your account updated, manage user access, and maintain the public homepage sections without jumping between separate screens.</p>
                    <div class="settings-chip-row">
                        <span class="settings-chip"><i class="bi bi-person-gear"></i> Account</span>
                        <span class="settings-chip"><i class="bi bi-people"></i> Users</span>
                        <span class="settings-chip"><i class="bi bi-cash-coin"></i> Reservation Deposit</span>
                        <span class="settings-chip"><i class="bi bi-tools"></i> Tools</span>
                        <span class="settings-chip"><i class="bi bi-house-gear"></i> Homepage Content</span>
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
                <section class="settings-card settings-section" data-search-section="add new user account access role admin employee superadmin create user">
                    <div class="settings-section-header">
                        <div>
                            <span class="settings-section-kicker">Access</span>
                            <h4 class="settings-section-title">Add New User</h4>
                            <p class="settings-section-text">Create additional accounts with the right role assignment from the same control panel.</p>
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

                        <form method="POST" class="settings-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Username</label>
                                <input type="text" name="add_username" class="form-control mb-0" autocomplete="off" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Password</label>
                                <input type="password" name="add_password" class="form-control mb-0" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">User Type</label>
                                <select name="add_usertype" class="form-select mb-0" required>
                                    <option value="" disabled selected>Select a role</option>
                                    <option value="superadmin">Superadmin</option>
                                    <option value="admin">Admin</option>
                                    <option value="employee">Employee</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <button type="submit" name="create_user" class="btn settings-submit-btn w-100">Add User</button>
                            </div>
                        </div>
                    </form>
                </section>
            </div>

            <div class="col-12 col-xl-10">
                <section class="settings-card settings-section" data-search-section="users accounts usernames roles list table directory">
                    <div class="settings-section-header">
                        <div>
                            <span class="settings-section-kicker">Directory</span>
                            <h4 class="settings-section-title">Users</h4>
                            <p class="settings-section-text">Review accounts and roles, and deactivate anyone who has left the hotel so they lose access immediately.</p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table settings-table text-center align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
                                while ($row = mysqli_fetch_assoc($result)):
                                    $rowStatus = ($row['status'] ?? 'active') === 'deactivated' ? 'deactivated' : 'active';
                                    $isActive  = ($rowStatus === 'active');
                                    $isSelf    = ((int) $row['id'] === $currentUserId);
                                ?>
                                    <tr>
                                        <td><?php echo (int) $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['username']); ?></td>
                                        <td><span class="settings-role-badge"><?php echo htmlspecialchars($row['usertype']); ?></span></td>
                                        <td>
                                            <span class="badge <?php echo $isActive ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $isActive ? 'Active' : 'Deactivated'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($isSelf): ?>
                                                <span class="text-muted small">This is you</span>
                                            <?php else: ?>
                                                <form method="POST" class="d-inline"
                                                      onsubmit="return confirm('<?php echo $isActive
                                                            ? 'Deactivate this account? The user will be logged out immediately and can no longer sign in.'
                                                            : 'Reactivate this account? The user will be able to sign in again.'; ?>');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                                    <input type="hidden" name="toggle_user_status" value="1">
                                                    <input type="hidden" name="user_id" value="<?php echo (int) $row['id']; ?>">
                                                    <input type="hidden" name="new_status" value="<?php echo $isActive ? 'deactivated' : 'active'; ?>">
                                                    <button type="submit"
                                                            class="btn btn-sm <?php echo $isActive ? 'btn-outline-danger' : 'btn-outline-success'; ?>">
                                                        <?php echo $isActive ? 'Deactivate' : 'Reactivate'; ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="settings-empty">No user records match your search.</div>
                </section>
            </div>

            <div class="col-12 col-xl-10">
                <section class="settings-card settings-section" data-search-section="tools backup user manual recovery documentation support system">
                    <div class="settings-section-header">
                        <div>
                            <span class="settings-section-kicker">System Tools</span>
                            <h4 class="settings-section-title">Maintenance Shortcuts</h4>
                            <p class="settings-section-text">Keep recovery and documentation actions close by with clearer visual entry points.</p>
                        </div>
                    </div>

                    <div class="settings-tool-grid">
                        <a href="../superadmin/backup.php" class="settings-tool-card">
                            <span class="settings-tool-icon"><i class="bi bi-cloud-arrow-down"></i></span>
                            <span class="settings-tool-title">Backup Database</span>
                            <span class="settings-tool-text">Create a current backup to reduce risk from accidental data loss.</span>
                        </a>

                        <a href="../superadmin/userManual.php" class="settings-tool-card">
                            <span class="settings-tool-icon"><i class="bi bi-journal-text"></i></span>
                            <span class="settings-tool-title">User Manual</span>
                            <span class="settings-tool-text">Open the guide for workflows, references, and admin help documentation.</span>
                        </a>
                    </div>
                </section>
            </div>

            <div class="col-12 col-xl-10">
                <section class="settings-card settings-section" data-search-section="rooms homepage section room names images edit content listing">
                    <div class="settings-section-header">
                        <div>
                            <span class="settings-section-kicker">Homepage</span>
                            <h4 class="settings-section-title">Rooms Section Settings</h4>
                            <p class="settings-section-text">Update the room names and room imagery shown on the homepage without leaving the settings screen.</p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table settings-table text-center align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Room</th>
                                    <th>Preview</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $sqlUser = "SELECT * FROM roomstbl";
                                $result = mysqli_query($conn, $sqlUser);

                                while ($row = mysqli_fetch_assoc($result)) {

                                    if (!empty($row['image'])) {
                                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                                        $mimeType = $finfo->buffer($row['image']);
                                        $base64 = base64_encode($row['image']);
                                        $imgSrc = "data:$mimeType;base64,$base64";
                                    } else {
                                        $imgSrc = "../img/default.jpg";
                                    }
                                    ?>
                                    <tr>
                                        <td><?php echo (int) $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['rooms']); ?></td>
                                        <td><img src="<?php echo $imgSrc; ?>" alt="Room preview" class="settings-thumb"></td>
                                        <td>
                                            <a href="#" class="editBtn settings-icon-btn"
                                               data-bs-toggle="modal"
                                               data-bs-target="#editRoomsModal"
                                               data-id="<?php echo (int) $row['id']; ?>"
                                               data-rooms="<?php echo htmlspecialchars($row['rooms']); ?>"
                                               aria-label="Edit room <?php echo htmlspecialchars($row['rooms']); ?>">
                                                <i class="lni lni-pen-to-square"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="settings-empty">No rooms match your search.</div>
                </section>
            </div>

            <div class="col-12 col-xl-10">
                <section class="settings-card settings-section" data-search-section="amenities exclusive amenities title description homepage edit content">
                    <div class="settings-section-header">
                        <div>
                            <span class="settings-section-kicker">Homepage</span>
                            <h4 class="settings-section-title">Exclusive Amenities Section Settings</h4>
                            <p class="settings-section-text">Refine amenity titles and descriptions in a cleaner table with faster edit actions.</p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table settings-table text-center align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Amenities</th>
                                    <th>Description</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php
                                $sqlUser = "SELECT * FROM amenitiestbl";
                                $result = mysqli_query($conn, $sqlUser);

                                while ($row = mysqli_fetch_assoc($result)) {
                                    ?>
                                    <tr>
                                        <td><?php echo (int) $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['title']); ?></td>
                                        <td><?php echo htmlspecialchars($row['description']); ?></td>
                                        <td>
                                            <a href="#" class="edit-amenity-btn settings-icon-btn" data-bs-toggle="modal" data-bs-target="#amenityModal"
                                               data-id="<?php echo (int) $row['id']; ?>"
                                               data-title="<?php echo htmlspecialchars($row['title']); ?>"
                                               data-desc="<?php echo htmlspecialchars($row['description']); ?>"
                                               aria-label="Edit amenity <?php echo htmlspecialchars($row['title']); ?>">
                                                <i class="lni lni-pen-to-square"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="settings-empty">No amenities match your search.</div>
                </section>
            </div>

            <div class="col-12 col-xl-10">
                <section class="settings-card settings-section" data-search-section="offers packages title description homepage promos edit content">
                    <div class="settings-section-header">
                        <div>
                            <span class="settings-section-kicker">Homepage</span>
                            <h4 class="settings-section-title">Offers Section Settings</h4>
                            <p class="settings-section-text">Keep package titles and descriptions polished with a simpler edit workflow.</p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table settings-table text-center align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Offers</th>
                                    <th>Description</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php
                                $sqlUser = "SELECT * FROM packagetbl";
                                $result = mysqli_query($conn, $sqlUser);

                                while ($row = mysqli_fetch_assoc($result)) {
                                    ?>
                                    <tr>
                                        <td><?php echo (int) $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['title']); ?></td>
                                        <td><?php echo htmlspecialchars($row['description']); ?></td>
                                        <td>
                                            <a href="#" class="edit-offer-btn settings-icon-btn" data-bs-toggle="modal" data-bs-target="#offerModal"
                                               data-id="<?php echo (int) $row['id']; ?>"
                                               data-title="<?php echo htmlspecialchars($row['title']); ?>"
                                               data-desc="<?php echo htmlspecialchars($row['description']); ?>"
                                               aria-label="Edit offer <?php echo htmlspecialchars($row['title']); ?>">
                                                <i class="lni lni-pen-to-square"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="settings-empty">No offers match your search.</div>
                </section>
            </div>

            <div class="col-12 col-xl-10">
                <?php
                // Live figures for the preview panel: what a guest is quoted, and
                // what the deposit comes out to on the hotel's own room rates.
                $depositRateNow    = marle_deposit_rate($conn);
                $depositRateLabel  = rtrim(rtrim(number_format($depositRateNow, 2, '.', ''), '0'), '.');
                $depositHoldNow    = marle_deposit_hold_hours($conn);
                $sampleNights      = 2;
                $sampleTotal       = marle_nightly_rate('Family') * $sampleNights;
                $sampleDeposit     = marle_deposit_amount($conn, $sampleTotal);
                $guestPayLine      = marle_deposit_method_text($conn);
                $forfeitLabel       = rtrim(rtrim(number_format((float)MARLE_CANCELLATION_FORFEIT_RATE, 2, '.', ''), '0'), '.');
                ?>
                <section class="settings-card settings-section" data-search-section="deposit reservation percentage gcash bank transfer payment instructions money bookings policy downpayment">
                    <div class="settings-section-header">
                        <div>
                            <span class="settings-section-kicker">Bookings</span>
                            <h4 class="settings-section-title">Reservation Deposit</h4>
                            <p class="settings-section-text">Decide how much of a booking the guest pays before you confirm the room, and tell them exactly where to send it. These details appear on the booking status page and inside every confirmation email.</p>
                        </div>
                    </div>

                    <?php if (!empty($depositSuccess)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($depositSuccess); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($depositError)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($depositError); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="settings-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label fw-bold" for="depositRateInput">Deposit required to confirm</label>
                                <div class="input-group">
                                    <input class="form-control" type="number" id="depositRateInput"
                                           name="deposit_rate" min="1" max="100" step="0.5" required
                                           value="<?= htmlspecialchars($depositRateLabel) ?>">
                                    <span class="input-group-text">%</span>
                                </div>
                                <div class="form-text">Share of the booking total the guest pays up front. 50 is the hotel's usual figure.</div>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label fw-bold" for="depositGcashInput">GCash number</label>
                                <input class="form-control" type="text" id="depositGcashInput"
                                       name="deposit_gcash_number" maxlength="20"
                                       value="<?= htmlspecialchars(trim(marle_site_option($conn, 'deposit_gcash_number', ''))) ?>"
                                       placeholder="0917 000 0000">
                                <div class="form-text">The number the guest sends the deposit to.</div>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label fw-bold" for="depositBankInput">Bank transfer details</label>
                                <input class="form-control" type="text" id="depositBankInput"
                                       name="deposit_bank_details" maxlength="200"
                                       value="<?= htmlspecialchars(trim(marle_site_option($conn, 'deposit_bank_details', ''))) ?>"
                                       placeholder="Marle Hotel &amp; Restaurant — BDO Savings 0012 3456 7890">
                                <div class="form-text">Account name, bank and number. Stays under 200 characters.</div>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-12 col-md-4">
                                <label class="form-label fw-bold" for="depositHoldInput">Hold window while unpaid</label>
                                <div class="input-group">
                                    <input class="form-control" type="number" id="depositHoldInput"
                                           name="deposit_hold_hours" min="<?= (int)MARLE_DEPOSIT_HOLD_HOURS_MIN ?>"
                                           max="<?= (int)MARLE_DEPOSIT_HOLD_HOURS_MAX ?>" step="1" required
                                           value="<?= (int)$depositHoldNow ?>">
                                    <span class="input-group-text">hours</span>
                                </div>
                                <div class="form-text">
                                    How long an unpaid booking keeps the room before it is released. Run
                                    <code>admin/cron_expire_deposits.php</code> hourly so the release happens on time.
                                </div>
                            </div>
                        </div>

                        <div class="settings-deposit-preview">
                            <div class="settings-deposit-preview-row">
                                <span class="settings-deposit-preview-label">Guest sees on the status page and in email</span>
                                <span class="settings-deposit-preview-value" id="depositPreviewPayLine">
                                    <?= $guestPayLine !== ''
                                        ? htmlspecialchars($guestPayLine)
                                        : '<em>Nothing yet — add a GCash number or your bank details.</em>' ?>
                                </span>
                            </div>
                            <div class="settings-deposit-preview-row">
                                <span class="settings-deposit-preview-label">
                                    Deposit on a <?= (int)$sampleNights ?>-night Family stay (<?= marle_peso($sampleTotal) ?>)
                                </span>
                                <span class="settings-deposit-preview-value settings-deposit-preview-amount" id="depositPreviewAmount">
                                    <?= marle_peso($sampleDeposit) ?>
                                </span>
                            </div>
                            <div class="settings-deposit-preview-row">
                                <span class="settings-deposit-preview-label">Unpaid booking is held for</span>
                                <span class="settings-deposit-preview-value" id="depositPreviewHold">
                                    <?= htmlspecialchars(marle_deposit_hold_label($depositHoldNow)) ?> — then the booking is released and nothing is charged
                                </span>
                            </div>
                        </div>

                        <p class="settings-note">
                            Changing the percentage or the hold window only affects <strong>new</strong> bookings — anything already quoted keeps the
                            amount the guest was shown, and the deadline each guest was emailed is the one the sweep uses
                            (a fresh proof upload restarts the wait). A cancelled booking forfeits <?= htmlspecialchars($forfeitLabel) ?>% of its
                            total, which is set in code rather than here. Releasing an unpaid booking charges <strong>nothing</strong>.
                        </p>

                        <button type="submit" name="save_deposit_policy" value="1" class="btn settings-submit-btn w-100">
                            Save deposit settings
                        </button>
                    </form>

                    <script>
                    (function () {
                        var rate = document.getElementById('depositRateInput');
                        var out  = document.getElementById('depositPreviewAmount');
                        if (!rate || !out) return;

                        var sampleTotal = <?= json_encode($sampleTotal) ?>;
                        var peso = function (n) {
                            return '\u20B1' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        };
                        var update = function () {
                            var r = parseFloat(rate.value);
                            out.textContent = (isFinite(r) && r >= 1 && r <= 100) ? peso(sampleTotal * (r / 100)) : '—';
                        };
                        rate.addEventListener('input', update);
                        update();

                        var hold    = document.getElementById('depositHoldInput');
                        var holdOut = document.getElementById('depositPreviewHold');
                        if (hold && holdOut) {
                            var updateHold = function () {
                                var h = parseInt(hold.value, 10);
                                holdOut.textContent = (isFinite(h) && h >= <?= (int)MARLE_DEPOSIT_HOLD_HOURS_MIN ?> && h <= <?= (int)MARLE_DEPOSIT_HOLD_HOURS_MAX ?>)
                                    ? h + ' hour' + (h === 1 ? '' : 's') + ' — then the booking is released and nothing is charged'
                                    : '—';
                            };
                            hold.addEventListener('input', updateHold);
                            updateHold();
                        }
                    })();
                    </script>
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
    <script src="../superadmin/includes/notif_poll.js"></script>
</body>

</html>



