<?php
include('dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/includes/db_guard.php';
marle_db_guard($conn, 'page');
include('includes/log_action.php');
session_start();

$notif = '';

if (isset($_POST['btnLogin'])) {

    // Lockout policy lives in config.php; guarded so a bad value can never
    // turn the protection off or lock someone out forever.
    $maxAttempts = (int)MARLE_LOGIN_MAX_ATTEMPTS;
    $lockMinutes = (int)MARLE_LOGIN_LOCK_MINUTES;
    if ($maxAttempts < 1) $maxAttempts = 3;
    if ($lockMinutes < 1) $lockMinutes = 15;

    if (empty($_POST['username']) || empty($_POST['password'])) {

        $notif = 'Please complete all fields.';

    } elseif (empty($_POST['agree_terms'])) {

        // Server-side twin of the form's required checkbox: a POST that skips
        // the agreement never reaches a password check.
        $notif = 'Please accept the Terms & Conditions to sign in.';

    } elseif (!preg_match('/[\?~`@#$%^&*\-+=>{}]/', $_POST['username'])) {

        $username = $_POST['username'];
        $password = $_POST['password'];

        // Both clock values come from MySQL. PHP's date.timezone and MySQL's
        // session time zone are independent settings, so parsing the stored
        // DATETIME with strtotime() and comparing it to PHP's time() shifts the
        // lock window by the offset between them (a 15-minute lock read as 375
        // and an expired lock never cleared). NOW() and locked_until are written
        // by the same server, so their epoch seconds are always comparable.
        $queryUser = mysqli_prepare($conn, "SELECT id, username, password, usertype, status, failed_attempts, UNIX_TIMESTAMP(locked_until) AS locked_ts, UNIX_TIMESTAMP() AS now_ts FROM userstbl WHERE username = ? LIMIT 1");
        mysqli_stmt_bind_param($queryUser, 's', $username);
        mysqli_stmt_execute($queryUser);
        $queryResult = mysqli_stmt_get_result($queryUser);
        $numberQuery = mysqli_num_rows($queryResult);

        if ($numberQuery > 0) {

            $row         = mysqli_fetch_assoc($queryResult);
            $outUserId   = (int)$row['id'];
            $outUsername = $row['username'];
            $outPassword = $row['password'];
            $outUsertype = $row['usertype'];
            $outStatus   = $row['status'] ?? 'active';
            $outFailed   = (int)($row['failed_attempts'] ?? 0);

            $now         = (int)($row['now_ts'] ?? time());
            $lockedUntil = (int)($row['locked_ts'] ?? 0); // UNIX_TIMESTAMP(NULL) -> NULL -> 0

            // An expired lock clears itself, so the next attempt starts from a
            // clean count instead of piling a fourth strike onto the old one.
            if ($lockedUntil > 0 && $lockedUntil <= $now) {
                $clearLock = mysqli_prepare($conn, "UPDATE userstbl SET failed_attempts = 0, locked_until = NULL WHERE id = ?");
                mysqli_stmt_bind_param($clearLock, 'i', $outUserId);
                mysqli_stmt_execute($clearLock);
                mysqli_stmt_close($clearLock);
                $outFailed   = 0;
                $lockedUntil = 0;
            }

            if ($lockedUntil > $now) {

                // Still locked — refuse before the password is even examined.
                $minutesLeft = (int)ceil(($lockedUntil - $now) / 60);
                log_event($conn, 'login_locked', 'user', $outUserId, 'denied', 'Account locked after ' . $maxAttempts . ' failed attempts (' . $minutesLeft . ' min left)', ['actor_username' => $outUsername, 'actor_role' => 'system']);
                $notif = 'This account is locked after ' . $maxAttempts . ' failed attempts. Try again in '
                    . $minutesLeft . ' minute' . ($minutesLeft === 1 ? '' : 's') . '.';
                mysqli_stmt_close($queryUser);
                goto login_render;
            }

            if ($outUsername == $username && password_verify($password, $outPassword)) {

                // A correct password wipes the strike count and any lock.
                $clearStrikes = mysqli_prepare($conn, "UPDATE userstbl SET failed_attempts = 0, locked_until = NULL, last_failed_at = NULL WHERE id = ?");
                mysqli_stmt_bind_param($clearStrikes, 'i', $outUserId);
                mysqli_stmt_execute($clearStrikes);
                mysqli_stmt_close($clearStrikes);

                // ── Deactivated accounts cannot log in (fired-employee rule) ──
                if ($outStatus !== 'active') {
                    log_event($conn, 'login_denied', 'user', $outUserId, 'denied', 'Account is deactivated', ['actor_username' => $outUsername, 'actor_role' => 'system']);
                    $notif = 'This account has been deactivated. Please contact the administrator.';
                    mysqli_stmt_close($queryUser);
                    goto login_render; // skip remaining checks, render login form
                }

                // Clear any leftover deactivation marker so a fresh, valid
                // session never shows the "account deactivated" banner.
                if (!empty($_COOKIE['marle_deactivated'])) {
                    setcookie('marle_deactivated', '', time() - 3600, '/');
                }

                session_regenerate_id(true);
                $_SESSION['user_id']  = $outUserId;
                $_SESSION['username'] = $username;
                $_SESSION['usertype'] = $outUsertype;
                $_SESSION['logged_in_at'] = time();
                $_SESSION['last_activity'] = time();

                // Route based on usertype.
                $roleRoutes = [
                    'superadmin' => 'superadmin/adminDB.php',
                    'admin'      => 'admin/adminDB.php',
                    'employee'   => 'employee/adminDB.php',
                ];

                if (isset($roleRoutes[$outUsertype])) {
                    log_event($conn, 'login_success', 'user', $outUserId, 'success', 'Role: ' . $outUsertype);
                    header("Location: " . $roleRoutes[$outUsertype]);
                    exit();
                }

                session_unset();
                $notif = 'Your account role is not allowed to access this system.';

            } else {

                // ── Wrong password: count the strike, lock on the last one ──
                $outFailed++;
                $lockedNow = ($outFailed >= $maxAttempts);

                if ($lockedNow) {
                    $strike = mysqli_prepare($conn, "UPDATE userstbl SET failed_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE), last_failed_at = NOW() WHERE id = ?");
                    mysqli_stmt_bind_param($strike, 'iii', $outFailed, $lockMinutes, $outUserId);
                } else {
                    $strike = mysqli_prepare($conn, "UPDATE userstbl SET failed_attempts = ?, locked_until = NULL, last_failed_at = NOW() WHERE id = ?");
                    mysqli_stmt_bind_param($strike, 'ii', $outFailed, $outUserId);
                }
                mysqli_stmt_execute($strike);
                mysqli_stmt_close($strike);

                log_event($conn, 'login_failed', 'user', $outUserId, 'denied', 'Wrong password (attempt ' . $outFailed . ' of ' . $maxAttempts . ')', ['actor_username' => $outUsername, 'actor_role' => 'system']);

                if ($lockedNow) {
                    log_event($conn, 'login_locked', 'user', $outUserId, 'denied', 'Account locked after ' . $maxAttempts . ' failed attempts', ['actor_username' => $outUsername, 'actor_role' => 'system']);
                    $notif = 'Too many failed attempts. This account is locked for ' . $lockMinutes . ' minutes.';
                } else {
                    $triesLeft = $maxAttempts - $outFailed;
                    $notif = 'Username or password is incorrect. ' . $triesLeft . ' attempt' . ($triesLeft === 1 ? '' : 's') . ' left before the account is locked.';
                }
            }

        } else {
            $notif = 'Record does not exist. Please register an account first.';
        }
        mysqli_stmt_close($queryUser);

    } else {

        $notif = 'No special characters allowed.';
    }
}

login_render:
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-4Q6Gf2aSP4eDXB8Miphtr37CMZZQ5oXLH2yaXMJ2w8e2ZtHTl7GptT4jmndRuHDT" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.lineicons.com/5.0/lineicons.css" />

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">

    <!-- <link rel="stylesheet" href="login.css"> -->

    <?php $staffIconBase = 'img/brand'; require __DIR__ . '/includes/staff_icons.php'; ?>
    <link rel="stylesheet" href="css/login.css?v=20261021b">

    <title>Login</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Segoe UI", sans-serif;
            /* subject to change */
            background: #f2ece9;
            height: 100vh;
        }

        .login-wrapper {
            display: flex;
            height: 100vh;
        }

        /* LEFT SIDE */
        .login-left {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 80px;
        }

        .login-content {
            max-width: 420px;
            width: 100%;
        }

        .login-logo {
            width: 150px;
            display: block;
            margin: 0 auto 10px auto;
        }

        .main-title {
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 40px;
            color: #111;
            line-height: 1.1;
        }

        .login-card {
            background: #fff;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
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

        .btn-login {
            width: 100%;
            height: 50px;
            font-size: 18px;
            border-radius: 12px;
            background: #ffb60a;
            color: #fff;
            border: none;
            transition: 0.3s;
        }

        .btn-login:hover {
            background-color: transparent;
            border: 2px solid #933310;
            color: #933310;
        }

        .alert {
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        .alert.hide {
            opacity: 0;
            transform: translateY(-6px);
        }

        .terms a {
            text-decoration: none;
            color: #ffb60a;
            font-size: 14px;
            padding-top: 1rem;
            margin-bottom: 0;
        }

        .terms a:hover {
            color: #933310;
        }

        /* RIGHT SIDE (CAROUSEL FULL HEIGHT) */
        .login-right {
            flex: 1;
            /* position: relative; */
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 60px;
            background: #f2ece9;
        }

        #loginCarousel {
            width: 100%;
            max-width: 600px;
            height: 75vh;
            max-height: 720px;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.15);
        }

        /* #loginCarousel, */
        #loginCarousel .carousel-inner,
        #loginCarousel .carousel-item {
            height: 100%;
        }

        .carousel-bg {
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        .carousel-content {
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: #fff;
            text-align: center;
            background: rgba(0, 0, 0, 0.4);
            padding: 40px;
        }

        .bg-1 {
            background-image: url("img/marle-entrance.jpg");
        }

        .bg-2 {
            background-image: url("img/table.jpg");
        }

        .bg-3 {
            background-image: url("img/heart-towel.jpg");
        }

        .carousel-content h2 {
            font-size: 40px;
            color: #ffb60a;
            /* font-weight: 500; */
        }

        .carousel-content p {
            font-size: 18px;
        }

        .carousel-indicators {
            bottom: 30px;
        }

        .carousel-indicators [data-bs-target] {
            width: 10px;
            height: 10px;
            border-radius: 50%;

            background-color: rgba(255, 255, 255, .6);
            border: none;
            margin: 0 6px;
        }

        .carousel-indicators .active {
            background-color: #ffb60a;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .login-right {
                display: none;
            }

            .main-title {
                font-size: 36px;
            }
        }
    </style>
</head>

<body class="login-page">
    <?php if (isset($_GET['logout']) && $_GET['logout'] === 'success'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <strong>Success!</strong> You have been successfully logged out.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['deactivated'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Session ended.</strong> This account has been deactivated. Please contact the administrator.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="login-wrapper">

        <!-- LEFT SIDE -->
        <div class="login-left">

            <div class="login-content">

                <!-- <h1 class="main-title" style="margin-bottom: 40px;">Marle Hotel<br>Exellence in service</h1> -->

                <img src="img/logo2.png" alt="logo" class="login-logo">

                <h1 class="main-title text-center" style="margin-bottom: 40px;"><i>Excellence in Service, Everyday</i>
                </h1>

                <!-- Alerts -->
                <?php if (!empty($notif)): ?>
                    <div class="alert alert-danger alert-dismissible fade show auto-hide" role="alert">
                        <?php echo $notif; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Left panel: Login card -->
                <div class="login-card">
                    <form method="POST">

                        <input type="text" name="username" class="form-control mb-3" placeholder="Enter your username"
                            autocomplete="off" required>

                        <div class="password-field mb-3">
                            <input type="password" name="password" id="password" class="form-control"
                                placeholder="Enter your password" autocomplete="off" required>
                            <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password"
                                aria-pressed="false" onclick="togglePasswordVisibility()">
                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>

                        <div class="form-check terms-consent mb-3">
                            <input class="form-check-input" type="checkbox" name="agree_terms" id="agree_terms"
                                value="1" required<?php echo (isset($_POST['btnLogin']) && !empty($_POST['agree_terms'])) ? ' checked' : ''; ?>>
                            <label class="form-check-label" for="agree_terms">
                                I have read and agree to the <a href="#" data-bs-toggle="modal"
                                    data-bs-target="#termsModal">Terms &amp; Conditions</a>.
                            </label>
                        </div>

                        <button type="submit" name="btnLogin" class="btn-login">Login</button>

                    </form>

                    <div class="terms">
                        <p class="text-center mt-4 mb-0">
                            <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal"><i>Terms &
                                    Conditions</i></a>
                            <!-- <a href="#">Data Privacy</a> -->
                        </p>
                    </div>

                </div>

            </div>
        </div>

        <!-- Right panel: Carousel -->
        <div class="login-right">

            <div id="loginCarousel" class="carousel slide carousel-fade" data-bs-ride="carousel"
                data-bs-interval="5000">

                <div class="carousel-indicators">
                    <button type="button" data-bs-target="#loginCarousel" data-bs-slide-to="0" class="active"></button>
                    <button type="button" data-bs-target="#loginCarousel" data-bs-slide-to="1"></button>
                    <button type="button" data-bs-target="#loginCarousel" data-bs-slide-to="2"></button>
                </div>

                <div class="carousel-inner">

                    <div class="carousel-item active carousel-bg bg-1">
                        <div class="carousel-content">
                            <h2>Welcome!</h2>
                            <p>Log in to continue.</p>
                        </div>
                    </div>

                    <div class="carousel-item carousel-bg bg-2">
                        <div class="carousel-content">
                            <h2>Fast & Secure</h2>
                            <p>Your data is protected with us.</p>
                        </div>
                    </div>

                    <div class="carousel-item carousel-bg bg-3">
                        <div class="carousel-content">
                            <h2>Get Started</h2>
                            <p>Access your dashboard instantly.</p>
                        </div>
                    </div>

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
                        Staff accounts are created by the hotel owner. If you believe you
                        should have access and do not have credentials, contact the owner
                        directly — do not attempt to sign in with another person's account.
                    </p>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-terms-accept" data-bs-dismiss="modal"
                        onclick="acceptTerms()">I agree</button>
                </div>

            </div>
        </div>
    </div>


    <!-- Show / hide password toggle -->
    <script>
        function togglePasswordVisibility() {
            var passwordField = document.getElementById("password");
            var toggleButton = document.getElementById("togglePassword");
            if (!passwordField) {
                return;
            }

            var isHidden = passwordField.type === "password";
            passwordField.type = isHidden ? "text" : "password";

            if (toggleButton) {
                toggleButton.setAttribute("aria-pressed", isHidden ? "true" : "false");
                toggleButton.setAttribute("aria-label", isHidden ? "Hide password" : "Show password");
                var icon = toggleButton.querySelector("i");
                if (icon) {
                    icon.className = isHidden ? "fa-solid fa-eye-slash" : "fa-solid fa-eye";
                }
            }
        }

        // "I agree" in the Terms & Conditions modal ticks the consent box for them.
        function acceptTerms() {
            var consent = document.getElementById("agree_terms");
            if (consent) {
                consent.checked = true;
                consent.dispatchEvent(new Event("change", { bubbles: true }));
            }
        }
    </script>

    <!-- Auto-hide alert -->
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const alerts = document.querySelectorAll(".alert.auto-hide");

            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.remove("show");
                    alert.classList.add("hide");

                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }, 3000); // ⏱ hide after 3 seconds
            });
        });
    </script>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>

</body>

</html>
