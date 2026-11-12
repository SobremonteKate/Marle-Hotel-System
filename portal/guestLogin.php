<?php
// ============================================================
//  guestLogin.php  —  Guest Portal: Token Login
// ============================================================
include('../dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page');
require_once __DIR__ . '/../includes/log_action.php';
require_once __DIR__ . '/guest_session.php';

if (!empty($_SESSION['guest_token'])) {
    header("Location: portal.php");
    exit();
}

$notif = '';

if (isset($_POST['btnLogin'])) {
    $rawInput   = $_POST['token'] ?? '';
    $cleanToken = strtoupper(preg_replace('/[\s\-]/', '', $rawInput));

    if (empty($cleanToken)) {
        $notif = 'Please enter your access token.';

    } elseif (!preg_match('/^[A-Z0-9]{12}$/', $cleanToken)) {
        $notif = 'Invalid token format. Tokens look like XXXX-XXXX-XXXX.';

    } else {
        // Validate: token must exist, not yet expired, and map to an active customer check-in
        $stmt = $conn->prepare(
            "SELECT gt.id AS token_id,
                    gt.token,
                    gt.room_number,
                    gt.hours_of_stay,
                    gt.expires_at,
                    UNIX_TIMESTAMP(gt.expires_at) AS expires_at_unix,
                    c.id AS customer_id
             FROM guest_tokens gt
             JOIN customerstbl c
               ON c.room_no = gt.room_number
              AND c.status = 'checked-in'
             WHERE gt.token = ?
               AND gt.expires_at > NOW()
             ORDER BY c.id DESC
             LIMIT 1"
        );
        $stmt->bind_param('s', $cleanToken);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $_SESSION['guest_token']     = $cleanToken;
            $_SESSION['room_number']     = $row['room_number'];
            $_SESSION['hours_of_stay']   = $row['hours_of_stay'];
            $_SESSION['expires_at']      = $row['expires_at'];
            $_SESSION['expires_at_unix'] = (int)$row['expires_at_unix'];
            $_SESSION['customer_id']     = (int)$row['customer_id'];
            // The dashboard consumes this once, so the tour runs on every fresh
            // login rather than only the first one on a device.
            $_SESSION['portal_tour_pending'] = true;
            log_event($conn, 'portal_token_login', 'guest_token', $row['token_id'], 'success', 'Room ' . $row['room_number'] . ' — guest portal sign-in');
            header("Location: portal.php");
            exit();
        } else {
            log_event($conn, 'portal_token_login_failed', 'guest_token', null, 'denied', 'Unknown or expired token');
            $notif = 'Token not found or has expired. Please check with the front desk.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:ital,opsz,wght@0,9..144,400..600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.lineicons.com/5.0/lineicons.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <?php include __DIR__ . '/includes/portal_icons.php'; ?>
    <title>Guest Portal Login</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body { font-family: 'DM Sans', sans-serif; background: #f2ece9; height: 100vh; }

        .login-wrapper { display: flex; height: 100vh; }

        :root { --login-ink:#1d2525; --login-deep:#263431; --login-muted:#71807a; --login-brass:#c38a45; --login-rust:#a24f35; --login-surface:#fffdf9; --login-bg:#f3f5f1; --login-line:#dde4df; }
        body { color:var(--login-ink); background:radial-gradient(circle at 0 0,rgba(195,138,69,.14),transparent 28rem),var(--login-bg); }

        .login-left {
            flex: 1;
            display: flex; align-items: center; justify-content: center;
            padding: 64px;
        }

        .login-content { max-width: 420px; width: 100%; }

        .login-logo { width: 150px; display: block; margin: 0 auto 10px auto; }

        .login-card {
            background: var(--login-surface);
            padding: 30px;
            border:1px solid var(--login-line);
            border-radius: 14px;
            box-shadow: 0 14px 34px rgba(35,50,45,.08);
        }

        .form-label { font-weight: 700; color: var(--login-ink); }

        .token-input {
            height: 56px;
            border-radius: 12px;
            border: 1px solid var(--login-line);
            font-family: 'IBM Plex Mono', 'Courier New', monospace;
            font-size: 1.3rem;
            letter-spacing: 4px;
            text-align: center;
            text-transform: uppercase;
        }
        .token-input::placeholder {
            letter-spacing: 2px; font-size: 1rem;
            font-family: 'DM Sans', sans-serif; color: #adb5bd;
        }
        .token-input:focus { box-shadow: 0 0 0 .2rem rgba(195,138,69,.14); border-color: var(--login-brass); }

        .btn-login {
            width: 100%; height: 50px; font-size: 18px;
            border-radius: 9px; background: var(--login-deep);
            color: #fff; border: none; font-weight: 600; transition: 0.3s;
        }
        .btn-login:hover {
            background: var(--login-rust); border-color: var(--login-rust); color: #fff;
        }

        .help-text { font-size: 13px; color: #6c757d; text-align: center; margin-top: 10px; }

        .alert { transition: opacity 0.3s ease, transform 0.3s ease; }
        .alert.hide { opacity: 0; transform: translateY(-6px); }

        .terms a {
            display: inline-block;
            padding-block: 10px;
            margin-block: -10px;
            text-decoration: underline #6c757d 1px;
            color: var(--login-rust); font-size: 14px; font-weight: 600;
        }
        .terms a:hover { color: #933310; }

        /* The dots read as 3px lines but each one is a 44px target; the visible
           bar is the content box, the rest is padding. */
        .carousel-indicators [data-bs-target] {
            width: 44px; height: 44px; margin: 0;
            padding: 20px 10px; border: 0; border-radius: 3px;
            background-color: rgba(255,255,255,.6);
            background-clip: content-box;
        }
        .carousel-indicators .active { background-color: var(--login-brass); }

        .login-right {
            flex: 1; display: flex; align-items: center;
            justify-content: center; padding: 48px; background: transparent;
        }

        #loginCarousel {
            width: 100%; max-width: 600px; height: 75vh; max-height: 720px;
            border-radius: 18px; overflow: hidden;
            box-shadow: 0 18px 42px rgba(35,50,45,.18);
        }
        #loginCarousel .carousel-inner,
        #loginCarousel .carousel-item { height: 100%; }

        .carousel-bg {
            background-size: cover; background-position: center;
            background-repeat: no-repeat;
        }
        .carousel-content {
            height: 100%; display: flex; flex-direction: column;
            justify-content: center; align-items: center;
            color: #fff; text-align: center;
            background: linear-gradient(180deg,rgba(38,52,49,.18),rgba(38,52,49,.76)); padding: 40px;
        }

        /* Real property photos from the portal's own image folder — the old
           paths pointed at ../img/cat*.jpg, which are not the hotel. */
        .bg-1 { background-image: url("../img/suite.jpg"); }
        .bg-2 { background-image: url("images/wagyu.jpg"); }
        .bg-3 { background-image: url("images/bath.jpg"); }

        @media (max-width: 700px) {
            .login-right { display: none; }
            .login-left  { padding: 40px 24px; }
        }
        @media (min-width: 701px) and (max-width: 900px) {
            .login-left { padding: 32px 24px; }
            .login-right { padding: 28px 18px; }
            #loginCarousel { height: min(72vh, 660px); border-radius: 16px; }
            .login-card { padding: 24px; }
            .login-logo { width: 124px; }
        }
        @media (prefers-reduced-motion: reduce) { *,*::before,*::after { animation-duration:.01ms!important; transition-duration:.01ms!important; } }
    </style>
</head>
<body>
<div class="login-wrapper">

    <!-- LEFT: Token form -->
    <div class="login-left">
        <div class="login-content">
            <img src="images/logo2.png" class="login-logo" alt="Logo">
            <h2 class="text-center mb-1" style="font-family:'Fraunces',Georgia,serif; font-size:2.1rem; font-weight:500; letter-spacing:-0.02em; color:#1d2525;">
                Guest Portal
            </h2>
            <p class="text-center text-muted mb-4" style="font-size:0.92rem;">
                Enter the access token from the front desk.
            </p>

            <?php if (!empty($notif)): ?>
                <div class="alert alert-danger alert-dismissible fade show auto-hide mb-3" role="alert">
                    <?= htmlspecialchars($notif) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="login-card">
                <form method="POST" action="guestLogin.php" autocomplete="off">
                    <div class="mb-4">
                        <label for="token" class="form-label">Access Token</label>
                        <input type="text" name="token" id="token"
                               class="form-control token-input"
                               placeholder="XXXX-XXXX-XXXX"
                               maxlength="14" required autofocus>
                    </div>
                    <button type="submit" name="btnLogin" class="btn-login">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Enter Portal
                    </button>
                </form>
                <p class="help-text mt-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Lost your token? Please visit the front desk.
                </p>
            </div>

            <div class="terms text-center mt-3 d-flex justify-content-center gap-3">
                <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms &amp; Conditions</a>
                <a href="#" data-bs-toggle="modal" data-bs-target="#privacyModal">Data Privacy</a>
            </div>
        </div>
    </div>

    <!-- RIGHT: Carousel -->
    <div class="login-right">
        <div id="loginCarousel" class="carousel slide carousel-fade"
             data-bs-ride="carousel" data-bs-interval="5000">
            <div class="carousel-indicators">
                <button type="button" data-bs-target="#loginCarousel" data-bs-slide-to="0" class="active"></button>
                <button type="button" data-bs-target="#loginCarousel" data-bs-slide-to="1"></button>
                <button type="button" data-bs-target="#loginCarousel" data-bs-slide-to="2"></button>
            </div>
            <div class="carousel-inner">
                <div class="carousel-item active carousel-bg bg-1">
                    <div class="carousel-content"><h2>Welcome!</h2><p>Your stay, at your fingertips.</p></div>
                </div>
                <div class="carousel-item carousel-bg bg-2">
                    <div class="carousel-content"><h2>From the kitchen</h2><p>Room service and dining, one tap away.</p></div>
                </div>
                <div class="carousel-item carousel-bg bg-3">
                    <div class="carousel-content"><h2>Everything You Need</h2><p>Services, menu, extensions — all in one place.</p></div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- TERMS MODAL -->
<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title" id="termsModalLabel">Terms &amp; Conditions</h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height:75vh; overflow-y:auto;">
                <h5>1. Acceptance of Terms</h5>
                <p>By accessing and using this system, you acknowledge that you have read, understood, and agreed to comply with all applicable policies, rules, and regulations governing system usage.</p>
                <h5>2. User Responsibilities</h5>
                <p>Users are responsible for maintaining the confidentiality of their access token and for all activities conducted under their session.</p>
                <ul>
                    <li>Do not share your access token.</li>
                    <li>Log out after each session.</li>
                    <li>Report suspicious activity immediately.</li>
                </ul>
                <h5>3. Data Privacy and Protection</h5>
                <p>All personal data collected will be handled in accordance with data protection laws and company privacy policies.</p>
                <h5>4. System Security</h5>
                <p>Unauthorized access, misuse, or tampering with system data is strictly prohibited and may result in disciplinary action.</p>
                <h5>5. Limitation of Liability</h5>
                <p>The company shall not be liable for any damages resulting from improper system use or unauthorized access.</p>
                <h5>6. Amendments</h5>
                <p>These terms may be updated at any time without prior notice. Continued use of the system constitutes acceptance of changes.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- PRIVACY MODAL -->
<div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title" id="privacyModalLabel">Data Privacy</h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height:75vh; overflow-y:auto;">
                <h5>1. Information We Collect</h5>
                <p>We collect information necessary to manage your stay, including your room assignment and stay duration.</p>
                <h5>2. How We Use Your Information</h5>
                <p>Your information is used solely to provide hotel services and manage your guest portal access during your stay.</p>
                <h5>3. Data Retention</h5>
                <p>Guest portal access tokens expire automatically. Personal data is retained only as required by applicable law.</p>
                <h5>4. Your Rights</h5>
                <p>You have the right to request access to, correction of, or deletion of your personal data. Contact the front desk for assistance.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    // Auto-hide alerts
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.alert.auto-hide').forEach(function (el) {
            setTimeout(function () {
                el.classList.remove('show');
                el.classList.add('hide');
                setTimeout(function () { el.remove(); }, 300);
            }, 4000);
        });
    });

    // Auto-format token input: XXXX-XXXX-XXXX as user types
    document.getElementById('token').addEventListener('input', function () {
        let val = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        if      (val.length > 8) val = val.slice(0,4) + '-' + val.slice(4,8) + '-' + val.slice(8,12);
        else if (val.length > 4) val = val.slice(0,4) + '-' + val.slice(4,8);
        this.value = val;
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
    crossorigin="anonymous"></script>
<?php include __DIR__ . '/includes/sw_register.php'; ?>
</body>
</html>