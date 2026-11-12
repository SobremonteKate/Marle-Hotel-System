<?php
$activePage = $activePage ?? 'home';
$bottomItems = [
    'home' => ['portal.php', 'Home', 'bi-house-door-fill'],
    'services' => ['services.php', 'Services', 'bi-briefcase'],
    'extend' => ['extend.php', 'Extend', 'bi-calendar3'],
    'menu' => ['menu.php', 'Menu', 'bi-fork-knife'],
    'guide' => ['hotel_guide.php', 'Guide', 'bi-book'],
];
?>
<nav class="navbar fixed-bottom navbar-bottom-mobile p-0" aria-label="Mobile portal navigation">
    <div class="container-fluid h-100">
        <?php foreach ($bottomItems as $key => [$href, $label, $icon]): ?>
            <a class="nav-link-icon<?= $activePage === $key ? ' active' : '' ?>" href="<?= $href ?>"><i class="bi <?= $icon ?>"></i><span class="nav-link-text"><?= $label ?></span></a>
        <?php endforeach; ?>
    </div>
</nav>
<div id="sessionExpiryToast"><div class="toast-icon"><i class="bi bi-exclamation-triangle-fill"></i></div><div class="toast-body"><div class="toast-title">Session Expiring Soon</div><p class="toast-msg">Your portal access expires in <strong id="expiryMinutes">15</strong> minutes. Visit the front desk to extend your stay.</p></div><button class="toast-close" onclick="this.parentElement.classList.remove('show');" aria-label="Dismiss">&times;</button></div>
<div id="expiredOverlay"><div class="expired-card"><i class="bi bi-clock-history text-danger" style="font-size:3rem;"></i><h4 class="mt-3 mb-2">Session Expired</h4><p class="text-muted mb-4">Your portal access has ended. Please visit the front desk if you need a new token.</p><a href="guestLogin.php" class="btn btn-primary w-100">Back to Login</a></div></div>
<div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h4 class="modal-title" id="privacyModalLabel">Data Privacy</h4><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><h5>1. Information We Collect</h5><p>We collect information necessary to manage your stay, including your room assignment and stay duration.</p><h5>2. How We Use Your Information</h5><p>Your information is used solely to provide hotel services and manage your guest portal access during your stay.</p><h5>3. Data Retention</h5><p>We retain information only as long as needed to provide services and meet legal obligations.</p></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>
<?php include __DIR__ . '/chat_widget.php'; ?>
<?php require_once __DIR__ . '/../../includes/server_clock.php'; marle_server_clock_assets(); ?>
<?php include __DIR__ . '/sw_register.php'; ?>
<script>
(function () {
    var navEl = document.getElementById('navCountdown') || document.getElementById('mobileCountdown');
    if (!navEl) return;
    // Never Date.now(): a guest whose phone clock runs fast used to be force-checked
    // out of a stay that was still running. data-left + MarleClock are the server's
    // own numbers (includes/server_clock.php).
    var clock = window.MarleClock || null;
    var secsLeftAtRender = parseInt(navEl.dataset.left, 10);
    if (isNaN(secsLeftAtRender)) { secsLeftAtRender = (parseInt(navEl.dataset.expires, 10) || 0) - (clock ? clock.nowSecs() : Math.floor(Date.now() / 1000)); }   // marle-clock-ok: only when includes/server_clock.php is missing; the live path uses data-left + MarleClock
    var totalSecs = Math.max(1, parseInt(navEl.dataset.total, 10) || 0);
    var fallbackStarted = Date.now();   // marle-clock-ok: no clock asset — measures elapsed time inside this page load only, never an absolute expiry
    function secsLeft() {
        if (clock) return clock.secsLeft(secsLeftAtRender);
        return Math.max(0, Math.floor(secsLeftAtRender - (Date.now() - fallbackStarted) / 1000));   // marle-clock-ok: the same no-clock fallback, elapsed-only
    }
    var navText = document.getElementById('navCountdownText');
    var navBar = document.getElementById('navCountdownBar');
    var mobile = document.getElementById('mobileCountdown');
    var mobileText = document.getElementById('mobileCountdownText');
    var mobileBar = document.getElementById('mobileCountdownBar');
    var toast = document.getElementById('sessionExpiryToast');
    var minutes = document.getElementById('expiryMinutes');
    var overlay = document.getElementById('expiredOverlay');
    var warned = false;
    function format(seconds) { var h = Math.floor(seconds / 3600), m = Math.floor((seconds % 3600) / 60), s = seconds % 60; return [h, m, s].map(function (v) { return String(v).padStart(2, '0'); }).join(':'); }
    function color(percent) { return percent < 20 ? '#dc3545' : percent < 50 ? '#ffb60a' : '#198754'; }
    /* The countdown runs on the server's clock, but a browser is still the caller —
       so the server is asked to confirm before anything is written. If the stay is
       actually still live (a clock we can't see, a tab left open, someone poking the
       endpoint by hand), nothing is checked out: reload and take the server's numbers. */
    var ending = false;
    function endSession() {
        if (ending) return;
        ending = true;
        fetch('../auto_checkout.php', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (data) {
                if (data && data.still_active) { window.location.reload(); return; }
                if (overlay) overlay.classList.add('show');
                window.location.href = 'guestLogin.php?expired=1';
            })
            .catch(function () {
                if (overlay) overlay.classList.add('show');
                window.location.href = 'guestLogin.php?expired=1';
            });
    }

    function tick() {
        var left = secsLeft(), percent = Math.max(0, Math.min(100, (left / totalSecs) * 100)), urgent = left < 3600, text = format(left), shade = color(percent);
        if (navText) { navText.textContent = text; navText.className = 'countdown-value' + (urgent ? ' urgent' : ''); }
        if (navBar) { navBar.style.width = percent + '%'; navBar.style.backgroundColor = shade; }
        if (mobileText) { mobileText.textContent = text; mobileText.className = 'mb-0' + (urgent ? ' text-danger fw-bold' : ''); }
        if (mobileBar) { mobileBar.style.width = percent + '%'; mobileBar.style.backgroundColor = shade; }
        if (left <= 900 && left > 0 && !warned) { warned = true; if (toast) toast.classList.add('show'); }
        if (left <= 900 && left > 0 && minutes) minutes.textContent = Math.ceil(left / 60);
        if (left <= 0) { clearInterval(timer); endSession(); }
    }
    tick();
    var timer = setInterval(tick, 1000);
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
</body>
</html>
