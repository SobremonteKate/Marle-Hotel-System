<?php
// ============================================================
//  homepage/booking_status.php
//  Guest checks a booking by email + private status token.
//
//  Also the guest's self-service desk for the reservation deposit:
//    * pay by GCash / bank transfer using the instructions shown
//    * upload the reference number + a photo of the receipt
//    * watch the status move Unpaid → For verification → Verified
//  and for cancellation under the flat 50% forfeiture policy, which is always
//  shown (with the exact peso amount) before the guest confirms.
// ============================================================
include('../dbconnect.php');
require_once __DIR__ . '/../includes/booking_deposit.php';
require_once __DIR__ . '/../includes/booking_cancel.php';
require_once __DIR__ . '/../includes/booking_notifications.php';
require_once __DIR__ . '/../includes/log_action.php';

$email    = trim($_GET['email'] ?? '');
$statusToken = trim($_GET['status_token'] ?? '');
$bookings = [];
$actionMessage = '';
$actionError = '';
$lookupFailed = false;
$highlightBookingId = 0;

/* The booking columns, the ownership check and the cancellation itself all
   live in includes/booking_cancel.php, shared with the homepage cancel modal. */

// ── POST: cancel, or submit proof of payment ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = (string)($_POST['action'] ?? '');
    $postEmail   = trim((string)($_POST['email'] ?? ''));
    $postToken   = trim((string)($_POST['status_token'] ?? ''));
    $bookingId   = (int)($_POST['booking_id'] ?? 0);

    $postValid = $bookingId > 0
        && filter_var($postEmail, FILTER_VALIDATE_EMAIL)
        && preg_match('/^[a-f0-9]{64}$/', $postToken);

    if (!$postValid) {
        $actionError = 'Invalid request. Please open your booking from the link in your email.';
    } elseif ($action === 'cancel') {
        $booking = marle_load_guest_booking($conn, $postEmail, $postToken, $bookingId);

        if (!$booking) {
            $actionError = 'This booking cannot be cancelled.';
        } else {
            // The forfeiture, the audit row and the emails are implemented once,
            // in includes/booking_cancel.php, and shared with the Cancel-a-booking
            // modal on the homepage (homepage/cancel_booking.php).
            $result = marle_cancel_booking(
                $conn,
                $booking,
                (string)($_POST['cancel_reason'] ?? 'not specified'),
                (string)($_POST['cancel_reason_detail'] ?? ''),
                'guest'
            );

            if (!$result['ok']) {
                $actionError = $result['error'];
            } else {
                $actionMessage      = $result['message'];
                $highlightBookingId = $bookingId;
            }
        }
    } elseif ($action === 'deposit') {
        $booking = marle_load_guest_booking($conn, $postEmail, $postToken, $bookingId);

        if (!$booking) {
            $actionError = 'Invalid request. Please open your booking from the link in your email.';
        } elseif ($booking['status'] === 'cancelled' || $booking['status'] === 'denied') {
            $actionError = 'This booking is closed, so a deposit is no longer needed.';
        } elseif (($booking['deposit_status'] ?? 'unpaid') === 'verified') {
            $actionError = 'Your deposit is already verified — thank you!';
        } else {
            $reference = trim((string)($_POST['deposit_reference'] ?? ''));
            $result = marle_deposit_save_proof(
                $conn,
                $bookingId,
                $reference,
                $_FILES['deposit_proof'] ?? []
            );

            if (!$result['ok']) {
                $actionError = $result['error'];
            } else {
                $actionMessage = 'Thank you! We received reference ' . htmlspecialchars($reference)
                    . '. Our front desk will verify your payment and email you the confirmation.';
                marle_notify_deposit_submitted($conn, $bookingId);
                log_event(
                    $conn,
                    'deposit_submitted',
                    'booking',
                    $bookingId,
                    'success',
                    'Guest uploaded proof of payment · ref ' . $reference
                );
                $highlightBookingId = $bookingId;
            }
        }
    } else {
        $actionError = 'Unknown action.';
    }

    $email = $postEmail;
    $statusToken = $postToken;
}

// ── Look up every booking for this email + token ────────────────────────────
// This query used to be run unguarded, and on 2026-10-13 it took the whole
// public page down with `Uncaught Error: Call to a member function
// bind_param() on bool` — Apache logged the fatal eight times in a row while
// the guest saw a 500 instead of their booking. This host runs PHP 8.0, where
// mysqli hands back false from a failed prepare() rather than throwing; from
// PHP 8.1 on, the same failure arrives as a mysqli_sql_exception instead. Both
// are covered below, because the point is that a guest never meets a fatal.
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)
    && preg_match('/^[a-f0-9]{64}$/', $statusToken)) {
    $stmt = false;
    try {
        $stmt = $conn->prepare(
            'SELECT ' . marle_guest_booking_columns() . '
             FROM bookings b WHERE b.guest_email = ? AND b.status_token = ?
             ORDER BY created_at DESC'
        );
    } catch (Throwable $e) {
        $stmt = false;   // PHP >= 8.1: mysqli throws instead of returning false
    }

    if ($stmt === false) {
        // Deliberately NOT "no bookings found": that would tell a guest their
        // booking does not exist when the truth is that we could not look.
        // The connection is usually still alive here (the statement was the
        // problem), so mysqli holds the reason — for the people who can act on
        // it, in the server log, never on the guest's screen.
        //
        // Two failure shapes reach this line and they need different words.
        // marle_db_connect() RETURNS false when the database is unreachable
        // (config.php), so $conn is not always a mysqli here — asking a bool
        // for ->error would raise "Attempt to read property on bool" and throw
        // away the one fact worth logging ("the database is down").
        $lookupFailed = true;
        $reason = ($conn instanceof mysqli)
            ? ($conn->error !== '' ? $conn->error : 'statement could not be prepared')
            : 'no database connection';
        error_log('booking_status: booking lookup could not run: ' . $reason);
    } else {
        $stmt->bind_param('ss', $email, $statusToken);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $bookings[] = $row;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#7A2A15">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="homepage.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400..700;1,9..144,400..600&family=IBM+Plex+Mono:wght@400;500&family=Instrument+Sans:ital,wght@0,400..700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="site.css?v=20261029c">
<?php include __DIR__ . '/includes/site_icons.php'; ?>
    <title>Booking Status - Marle Hotel</title>
    <style>
        .status-card { border-radius: 14px; border-left: 4px solid #6c757d; padding: 16px 18px; margin-bottom: 12px; background: #f9f6f3; }
        .status-card.pending  { border-left-color: #ffb60a; }
        .status-card.approved { border-left-color: #198754; }
        .status-card.denied   { border-left-color: #dc3545; }
        .status-card.cancelled { border-left-color: #adb5bd; }
        .deposit-panel { border: 1px solid #f0d9bb; background: #fffaf3; border-radius: 12px; padding: 14px 16px; margin-top: 14px; }
        .deposit-panel.verified { border-color: #b7e4c7; background: #f2fbf5; }
        .deposit-panel.rejected { border-color: #f1aeb5; background: #fdf4f5; }
        .deposit-amount { font-size: 22px; font-weight: 700; color: #933310; }
        .deposit-method { font-size: 13px; color: #6b5a4b; word-break: break-word; }
        .cancel-box { border: 1px solid #f1aeb5; background: #fff7f8; border-radius: 12px; padding: 12px 14px; margin-top: 12px; }
        .cancel-box .forfeit { font-weight: 700; color: #b02a37; }
        .proof-link { font-size: 12.5px; }
        .timeline-step { font-size: 12.5px; color: #8a7a6c; }
    </style>
</head>
<body class="has-site-header">
<?php
// The same header as the rest of the public site. This page used to carry a
// collapsing menu with no toggler, so on a phone its links were unreachable;
// the switch is hidden because the page itself is English-only.
$navActive       = '';
$navShowLang     = false;
$navForceEnglish = true;
include __DIR__ . '/includes/site_header.php';
?>

    <div class="container status-page" id="main">
        <h3 class="mb-1">Check Booking Status</h3>
        <p class="text-muted mb-4">Enter the email and private status token returned when you booked.</p>

        <form method="GET" action="booking_status.php" class="mb-4 status-lookup">
            <div class="input-group">
                <input type="email" name="email" class="form-control" placeholder="Enter your email address"
                       value="<?= htmlspecialchars($email) ?>" required autocomplete="email">
                  <input type="text" name="status_token" class="form-control" placeholder="Status token" value="<?= htmlspecialchars($statusToken) ?>"
                      pattern="[a-f0-9]{64}" minlength="64" maxlength="64" required spellcheck="false">
                <button class="btn" type="submit">
                    <i class="bi bi-search me-1"></i>Check Status
                </button>
            </div>
        </form>

        <?php if ($actionMessage): ?><div class="alert alert-success"><?= $actionMessage ?></div><?php endif; ?>
        <?php if ($actionError): ?><div class="alert alert-danger"><?= htmlspecialchars($actionError) ?></div><?php endif; ?>

        <?php if ($lookupFailed): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-1"></i>
                We couldn't load your booking just now. Please try again in a moment —
                if it keeps happening, call the front desk at <strong>0917 000 0000</strong>
                and they can look it up for you.
            </div>
        <?php elseif ($email !== '' && empty($bookings)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-1"></i>
                No bookings found for <strong><?= htmlspecialchars($email) ?></strong>.
            </div>
        <?php endif; ?>

        <?php foreach ($bookings as $bk):
            $depositStatus = (string)($bk['deposit_status'] ?? 'unpaid');
            $depositMeta   = marle_deposit_status_meta($depositStatus);
            $bookingTotal  = marle_booking_total($bk);
            $depositAmount = $bk['deposit_amount'] !== null
                ? (float)$bk['deposit_amount']
                : marle_deposit_amount($conn, $bookingTotal);
            $cancelFee     = marle_cancellation_fee($bookingTotal);
            $canCancel     = marle_booking_can_be_cancelled($bk);
            $isOpen        = in_array($bk['status'], ['pending', 'approved'], true);
            // The room is only held for a set window while the deposit is unpaid
            // (see includes/booking_expiry.php) — the guest is told the deadline.
            $holdHours     = marle_deposit_hold_hours($conn);
            $deadlineTs    = marle_deposit_deadline_ts($bk, $holdHours);
        ?>
        <div class="status-card <?= htmlspecialchars($bk['status']) ?>" id="booking-<?= (int)$bk['id'] ?>">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                <div>
                    <span class="fw-bold"><?= htmlspecialchars($bk['room_type']) ?></span>
                    <?php if ($bk['room_number']): ?>
                        <span class="badge bg-info text-dark ms-1">Room <?= htmlspecialchars($bk['room_number']) ?></span>
                    <?php endif; ?>
                    <span class="badge bg-light text-dark border ms-1">#<?= (int)$bk['id'] ?></span>
                </div>
                <span class="badge <?php
                    echo match($bk['status']) {
                        'pending'  => 'bg-warning text-dark',
                        'approved' => 'bg-success',
                        'denied'   => 'bg-danger',
                        default    => 'bg-secondary'
                    };
                ?>">
                    <?= ucfirst($bk['status']) ?>
                </span>
            </div>

            <p class="mb-1 small">
                <i class="bi bi-calendar-event me-1 text-muted"></i>
                <?= date('F j, Y', strtotime($bk['check_in'])) ?> &mdash; <?= date('F j, Y', strtotime($bk['check_out'])) ?>
                &nbsp;&bull;&nbsp;
                <i class="bi bi-people me-1 text-muted"></i><?= (int)$bk['persons'] ?> guest(s)
            </p>
            <?php if ($bk['booking_type'] === 'hourly' && $bk['check_in_time'] && $bk['check_out_time']): ?>
                <p class="mb-1 small"><i class="bi bi-clock me-1 text-muted"></i><?= date('g:i A', strtotime($bk['check_in_time'])) ?> &mdash; <?= date('g:i A', strtotime($bk['check_out_time'])) ?></p>
            <?php endif; ?>
            <?php if (!empty($bk['addon_titles'])): ?>
                <p class="mb-1 small"><i class="bi bi-stars me-1 text-muted"></i>Add-ons: <?= htmlspecialchars($bk['addon_titles']) ?></p>
            <?php endif; ?>
            <p class="mb-1 small"><i class="bi bi-cash-coin me-1 text-muted"></i>Estimated total: <strong><?= marle_peso($bookingTotal) ?></strong></p>

            <?php if ($bk['status'] === 'approved'): ?>
                <div class="alert alert-success py-2 mt-2 mb-0 small">
                    <i class="bi bi-check-circle me-1"></i>
                    Your booking is confirmed! Please check in at 2:00 PM on <?= date('F j, Y', strtotime($bk['check_in'])) ?>.
                </div>
            <?php elseif ($bk['status'] === 'pending'): ?>
                <div class="alert alert-warning py-2 mt-2 mb-0 small">
                    <i class="bi bi-hourglass-split me-1"></i>
                    Your booking is being reviewed. You will receive an email once it's processed.
                </div>
            <?php elseif ($bk['status'] === 'denied' && !empty($bk['denial_reason'])): ?>
                <div class="alert alert-danger py-2 mt-2 mb-0 small">
                    <i class="bi bi-info-circle me-1"></i>
                    Reason: <?= htmlspecialchars($bk['denial_reason']) ?>
                </div>
            <?php elseif ($bk['status'] === 'cancelled'): ?>
                <div class="alert alert-secondary py-2 mt-2 mb-0 small">
                    <i class="bi bi-x-circle me-1"></i>
                    <?= htmlspecialchars($bk['cancellation_reason'] ?: 'This booking was cancelled.') ?>
                    <?php if (!empty($bk['cancellation_fee_amount'])): ?>
                        <br><span class="fw-semibold">Amount retained under the cancellation policy:
                        <?= marle_peso((float)$bk['cancellation_fee_amount']) ?></span>
                    <?php endif; ?>
                    <br>To rebook, call the front desk at <strong>0917 000 0000</strong>.
                </div>
            <?php endif; ?>

            <?php /* ── Reservation deposit ─────────────────────────────────── */ ?>
            <?php if ($isOpen): ?>
            <div class="deposit-panel <?= htmlspecialchars($depositStatus) ?>">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <div class="text-uppercase small fw-semibold" style="letter-spacing:.08em; color:#8a7a6c;">
                            Reservation deposit (<?= rtrim(rtrim(number_format((float)($bk['deposit_rate'] ?? marle_deposit_rate($conn)), 2, '.', ''), '0'), '.') ?>% of the total)
                        </div>
                        <div class="deposit-amount"><?= marle_peso($depositAmount) ?></div>
                    </div>
                    <span class="badge <?= $depositMeta['chip'] ?>"><?= htmlspecialchars($depositMeta['label']) ?></span>
                </div>

                <p class="timeline-step mt-2 mb-2">
                    <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($depositMeta['note']) ?>
                </p>

                <?php if ($depositStatus !== 'verified'): ?>
                    <hr class="my-2">
                    <p class="mb-1 fw-semibold" style="font-size:13.5px;">How to pay</p>
                    <p class="deposit-method mb-2"><?= htmlspecialchars(marle_deposit_method_text($conn)) ?></p>
                    <p class="deposit-method mb-2">
                        Send exactly <strong><?= marle_peso($depositAmount) ?></strong> and keep the receipt —
                        your booking is confirmed only after our front desk verifies the payment.
                    </p>

                    <?php if ($bk['status'] === 'pending'): ?>
                    <p class="deposit-method mb-3">
                        <i class="bi bi-hourglass-split me-1"></i>
                        Your room is held for <strong><?= htmlspecialchars(marle_deposit_hold_label($holdHours)) ?></strong><?php if ($deadlineTs > 0): ?>
                            — pay and upload your proof before <strong><?= date('F j, Y g:i A', $deadlineTs) ?></strong>,
                            after which this booking is released automatically and the dates go back to other guests.<?php else: ?>,
                            after which this booking is released automatically.<?php endif; ?>
                        Nothing is charged on a release.
                    </p>
                    <?php endif; ?>

                    <?php if ($depositStatus === 'rejected' && !empty($bk['deposit_rejection_reason'])): ?>
                        <div class="alert alert-danger py-2 small">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Your previous proof was rejected: <?= htmlspecialchars($bk['deposit_rejection_reason']) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="booking_status.php" enctype="multipart/form-data" class="row g-2">
                        <input type="hidden" name="action" value="deposit">
                        <input type="hidden" name="booking_id" value="<?= (int)$bk['id'] ?>">
                        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                        <input type="hidden" name="status_token" value="<?= htmlspecialchars($statusToken) ?>">

                        <div class="col-12 col-sm-5">
                            <label class="form-label small fw-semibold mb-1" for="ref<?= (int)$bk['id'] ?>">GCash / bank reference no.</label>
                            <input type="text" class="form-control form-control-sm" id="ref<?= (int)$bk['id'] ?>"
                                   name="deposit_reference" maxlength="80" required
                                   placeholder="e.g. 0023145678901"
                                   value="<?= htmlspecialchars((string)($bk['deposit_reference'] ?? '')) ?>">
                        </div>
                        <div class="col-12 col-sm-7">
                            <label class="form-label small fw-semibold mb-1" for="proof<?= (int)$bk['id'] ?>">Receipt (JPG, PNG or PDF, max 5 MB)</label>
                            <input type="file" class="form-control form-control-sm" id="proof<?= (int)$bk['id'] ?>"
                                   name="deposit_proof" accept="image/jpeg,image/png,image/gif,application/pdf" required>
                        </div>
                        <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <button type="submit" class="btn btn-sm" style="background:#933310; color:#fff;">
                                <i class="bi bi-cloud-arrow-up me-1"></i>Submit proof of payment
                            </button>
                            <?php if (!empty($bk['deposit_proof_path'])): ?>
                                <span class="proof-link text-muted">
                                    Last upload: <?= date('M j, Y g:ia', strtotime((string)$bk['deposit_submitted_at'])) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="deposit-method mb-0">
                        Verified on <?= date('M j, Y g:ia', strtotime((string)$bk['deposit_verified_at'])) ?>.
                        Please keep this page and your status token — you will need the token when it is time to cancel.
                    </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php /* ── Cancellation ───────────────────────────────────────── */ ?>
            <?php if ($isOpen): ?>
                <?php if (!$canCancel['allowed']): ?>
                    <div class="alert alert-warning py-2 mt-3 mb-0 small">
                        <i class="bi bi-lock me-1"></i><?= htmlspecialchars($canCancel['reason']) ?>
                    </div>
                <?php else: ?>
                    <div class="cancel-box">
                        <p class="mb-2 small">
                            <i class="bi bi-exclamation-triangle me-1 text-danger"></i>
                            Cancelling this booking forfeits <span class="forfeit"><?= marle_peso($cancelFee) ?></span>
                            — <?= (int)MARLE_CANCELLATION_FORFEIT_RATE ?>% of the <?= marle_peso($bookingTotal) ?> booking total.
                            <?php if ((float)($bk['deposit_amount'] ?? 0) > 0): ?>
                                Your deposit of <?= marle_peso((float)$bk['deposit_amount']) ?> is applied to that amount;
                                <?php $remaining = max(0, $cancelFee - (float)$bk['deposit_amount']); ?>
                                <?php if ($remaining > 0): ?>
                                    a balance of <strong><?= marle_peso($remaining) ?></strong> would still be payable at the front desk.
                                <?php else: ?>
                                    nothing further would be owed.
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                        <form method="POST" action="booking_status.php"
                              onsubmit="return confirm('Cancel booking #<?= (int)$bk['id'] ?>? You will be charged <?= htmlspecialchars(marle_peso($cancelFee)) ?> (<?= (int)MARLE_CANCELLATION_FORFEIT_RATE ?>% of the booking total) under the cancellation policy.');">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="booking_id" value="<?= (int)$bk['id'] ?>">
                            <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                            <input type="hidden" name="status_token" value="<?= htmlspecialchars($statusToken) ?>">
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-sm-5">
                                    <label class="form-label small fw-semibold mb-1" for="why<?= (int)$bk['id'] ?>">Reason (optional)</label>
                                    <select class="form-select form-select-sm" id="why<?= (int)$bk['id'] ?>" name="cancel_reason">
                                        <option value="change of plans">Change of plans</option>
                                        <option value="travel cancelled">Travel cancelled</option>
                                        <option value="found another place">Found another place</option>
                                        <option value="budget reasons">Budget reasons</option>
                                        <option value="booked by mistake">Booked by mistake</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-4">
                                    <label class="form-label small fw-semibold mb-1" for="detail<?= (int)$bk['id'] ?>">Details (optional)</label>
                                    <input type="text" class="form-control form-control-sm" id="detail<?= (int)$bk['id'] ?>"
                                           name="cancel_reason_detail" maxlength="160" placeholder="Anything we should know?">
                                </div>
                                <div class="col-12 col-sm-3">
                                    <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                        <i class="bi bi-x-circle me-1"></i>Cancel booking
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <p class="text-muted small mt-2 mb-0">Submitted: <?= date('M j, Y g:ia', strtotime($bk['created_at'])) ?></p>
        </div>
        <?php endforeach; ?>

        <div class="text-center mt-4 mb-5">
            <a href="home.php" class="text-decoration-none" style="color:#933310;">
                <i class="bi bi-arrow-left me-1"></i>Back to Home
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>
    <script src="site-nav.js?v=20261029b"></script>
    <?php include __DIR__ . '/includes/site_help.php'; ?>
    <script src="site-guide.js"></script>
</body>
</html>
