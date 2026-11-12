<?php
/**
 * Reusable Review Prompt (polite ad)
 *
 * Renders the floating "How was your stay?" card with the subtle
 * shake-on-reappear animation, a fixed 2-minute snooze so it keeps
 * nudging until reviewed, and a guard that never shows it while the
 * chat widget is open.
 *
 * Requirements: an active guest session (session_guard.php) so the
 * token-identity review check can run.
 *
 * The card is retired permanently once a review exists for this
 * token (or, for legacy rows, this guest+room) — mirroring the
 * dedupe rule in review.php.
 */

$reviewGuestName = 'Guest ' . str_pad((string)($_SESSION['customer_id'] ?? 0), 3, '0', STR_PAD_LEFT);
$guestToken = trim((string)($_SESSION['guest_token'] ?? ''));
$reviewRoomNo = (string)($_SESSION['room_number'] ?? 'N/A');
$reviewCheck = $conn->prepare(
    'SELECT id FROM reviewstbl
     WHERE token = ? OR (token IS NULL AND guest_name = ? AND room_no = ?)'
);
$reviewCheck->bind_param('sss', $guestToken, $reviewGuestName, $reviewRoomNo);
$reviewCheck->execute();
$reviewAlreadySubmitted = $reviewCheck->get_result()->num_rows > 0;
$reviewCheck->close();

if ($reviewAlreadySubmitted) {
    return; // already reviewed — no prompt, no script
}
?>
<aside class="portal-review-prompt" id="portalReviewPrompt" aria-label="Review invitation">
    <button type="button" class="portal-review-prompt-close" id="dismissReviewPrompt" aria-label="Dismiss review invitation">&times;</button>
    <div class="portal-review-prompt-icon"><i class="bi bi-star-fill"></i></div>
    <div class="portal-review-prompt-copy">
        <strong>How was your stay?</strong>
        <span>Your feedback takes a minute. Submit a review and claim your free drink.</span>
    </div>
    <a class="btn portal-review-prompt-action" href="review.php">Review now <i class="bi bi-arrow-right ms-1"></i></a>
</aside>
<script>
(function () {
    // Persistent review prompt — behaves like a (polite) ad:
    // the ✕ snoozes it for 2 minutes, then it pops back up with a
    // gentle shake, forever, until the guest actually submits the
    // review. It always yields while the chat widget is open.
    var prompt = document.getElementById('portalReviewPrompt');
    var dismiss = document.getElementById('dismissReviewPrompt');
    if (!prompt) return;
    var key = 'marle-review-prompt-dismissed-<?= htmlspecialchars((string)($_SESSION['guest_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>';
    var baseSnoozeMs   = 2 * 60 * 1000;  // pops back up every 2 minutes until reviewed
    var hideTimer = null;
    var attentionTimer = null;

    /* The snooze is stored between pages, so it needs an absolute "now" that means the
       same on every page load. It used to be the guest's own Date.now(): a phone whose
       clock was set a day fast read its own snooze as long expired (the card popped
       straight back), and one set backwards read seconds as days — and the two-minute
       snooze turned into "until you close the browser". MarleClock.nowMs()
       (includes/server_clock.php) is the hotel's clock, continued in this browser, so the
       stored stamp and the one it is compared against always come from the same clock. */
    function snoozeNowMs() {
        return window.MarleClock ? MarleClock.nowMs() : Date.now();   // marle-clock-ok: only reached on a page without the clock asset
    }

    function chatWidgetOpen() {
        var widget = document.getElementById('chatWidget');
        return !!widget && widget.style.display === 'flex';
    }
    // The five-step tour owns the bottom of the screen while it runs, so the
    // prompt steps aside for it exactly as it does for the chat widget.
    function tourOpen() {
        return document.documentElement.classList.contains('tour-open');
    }
    function doShow(shake) {
        prompt.classList.add('is-visible');
        prompt.classList.remove('is-attention');
        clearTimeout(attentionTimer);
        if (shake) {
            // Let the slide-up transition finish first — a running
            // animation would override the transform — then wiggle.
            attentionTimer = setTimeout(function () {
                prompt.classList.add('is-attention');
            }, 250);
        }
    }
    prompt.addEventListener('animationend', function (e) {
        if (e.animationName === 'portal-prompt-wiggle') {
            prompt.classList.remove('is-attention');
        }
    });
    function show(shake) {
        if (chatWidgetOpen() || tourOpen()) {
            // Never cover the chat widget or the tour — wait politely until they finish.
            hideTimer = setTimeout(function () { show(shake); }, 1000);
        } else {
            doShow(shake);
        }
    }
    document.addEventListener('marle:tour-open', function () {
        clearTimeout(hideTimer);
        prompt.classList.remove('is-visible');
    });
    document.addEventListener('marle:tour-end', function () {
        // Resume the normal two-minute cycle from the moment the tour closed.
        hideTimer = setTimeout(function () { show(false); }, baseSnoozeMs);
    });
    function snooze() {
        prompt.classList.remove('is-visible');
        sessionStorage.setItem(key, String(snoozeNowMs()));
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () { show(true); }, baseSnoozeMs);
    }

    var dismissedAt = parseInt(sessionStorage.getItem(key) || '0', 10);
    var sinceSnooze = dismissedAt ? snoozeNowMs() - dismissedAt : Infinity;
    if (dismissedAt && sinceSnooze < baseSnoozeMs) {
        // Recently snoozed: stay hidden for the remainder of the snooze
        // window, then pop back (chat permitting) with the wiggle.
        hideTimer = setTimeout(function () { show(true); }, baseSnoozeMs - sinceSnooze);
    } else {
        // Fresh visit, or snooze long expired: reappear immediately.
        show(dismissedAt > 0);
    }

    dismiss.addEventListener('click', snooze);
    // Stop the timer once the guest finally opens the review form.
    prompt.querySelector('.portal-review-prompt-action').addEventListener('click', function () {
        clearTimeout(hideTimer);
    });
})();
</script>
