<?php
require_once __DIR__ . '/includes/session_guard.php';

// A successful login leaves this flag behind. It is consumed here so the tour
// runs once for this login — not once per browser, which used to mean a guest
// who logged in again on a new stay never saw it.
$portalTourPending = !empty($_SESSION['portal_tour_pending']);
unset($_SESSION['portal_tour_pending']);
if (isset($_GET['tour'])) {
    $portalTourPending = true;
}

// ?install=1 is the same kind of review hook for the home-screen card at the
// foot of this page: it shows the card on demand, without waiting for a browser
// to offer one. See includes/install_prompt.php.

?>
<?php
$pageTitle = 'Guest Portal';
$activePage = 'home';
$showBackButton = false;
$breadcrumb = 'Home';
$pageCss = 'css/portal.css';
include __DIR__ . '/includes/header.php';
?>

<main class="portal-main dashboard-page">

    <section class="stay-card" data-tour="stay" aria-labelledby="stayTitle">
        <div class="stay-key">
            <small>Room</small>
            <strong><?= $roomNumber ?></strong>
        </div>
        <div class="stay-copy">
            <p>Checked in &middot; Front desk is open</p>
            <h2 id="stayTitle">Your stay at Marle</h2>
            <div class="stay-metrics">
                <div class="stay-metric" id="mobileCountdown"
                     data-left="<?= $secsLeft ?>" data-expires="<?= $expiresUnix ?>" data-total="<?= $totalSecs ?>">
                    <small>Time left</small>
                    <strong id="mobileCountdownText">&mdash;</strong>
                    <div class="countdown-bar-bg" style="width:96px;margin-top:6px;">
                        <div class="countdown-bar" id="mobileCountdownBar" style="width:100%;"></div>
                    </div>
                </div>
                <div class="stay-metric">
                    <small>Booked for</small>
                    <strong><?= (int)$hoursOfStay ?> hours</strong>
                </div>
            </div>
        </div>
    </section>

    <section class="quick-grid" data-tour="actions" aria-label="Quick actions">
        <a class="quick-card" href="services.php">
            <i class="bi bi-bell" aria-hidden="true"></i>
            <strong>Services</strong>
            <span>Request to your room</span>
        </a>
        <a class="quick-card" href="menu.php">
            <i class="bi bi-fork-knife" aria-hidden="true"></i>
            <strong>Menu</strong>
            <span>Order from the kitchen</span>
        </a>
        <a class="quick-card" href="extend.php">
            <i class="bi bi-calendar3" aria-hidden="true"></i>
            <strong>Extend</strong>
            <span>Add more hours</span>
        </a>
        <a class="quick-card" href="hotel_guide.php">
            <i class="bi bi-book" aria-hidden="true"></i>
            <strong>Guide</strong>
            <span>House notes &amp; contacts</span>
        </a>
    </section>

    <section class="bill-card" data-tour="bill" aria-labelledby="billTitle">
        <h2 id="billTitle"><i class="bi bi-receipt" aria-hidden="true"></i>Your running total</h2>
        <div class="bill-row"><span>Check-in</span><span id="bill-checkin">&hellip;</span></div>
        <div class="bill-row"><span>Extensions</span><span id="bill-extension">&hellip;</span></div>
        <div class="bill-row"><span>Services</span><span id="bill-service">&hellip;</span></div>
        <div class="bill-row"><span>Food</span><span id="bill-food">&hellip;</span></div>
        <div class="bill-total-row"><span>Total</span><span id="bill-total">&hellip;</span></div>
        <p class="bill-footnote">Unpaid charges only. Paid items are not shown.</p>
    </section>

    <section class="mt-4" aria-labelledby="homeServicesTitle">
        <div class="d-flex justify-content-between align-items-end mb-2">
            <h2 class="section-title" id="homeServicesTitle">Hotel services</h2>
            <a href="services.php" class="see-all">See all <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </div>

        <div class="row g-2">
            <div class="col-12 col-md-4">
                <div class="card h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="service-icon-container"><i class="fa-solid fa-bath" aria-hidden="true"></i></div>
                        <div class="flex-grow-1">
                            <h3 class="card-title mb-0" style="font-size:.92rem;">Deluxe Bath &amp; Spa</h3>
                            <p class="card-text mb-1" style="font-size:.76rem;">Relaxing treatments in your room</p>
                            <span class="price">&#8369;123</span>
                            <span class="text-muted" style="font-size:.7rem;">/ session</span>
                        </div>
                        <button class="add-service-btn" aria-label="Request Deluxe Bath and Spa" data-name="Deluxe Bath &amp; Spa" data-category="wellness" data-price="123"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="card h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="service-icon-container"><i class="fa-solid fa-broom" aria-hidden="true"></i></div>
                        <div class="flex-grow-1">
                            <h3 class="card-title mb-0" style="font-size:.92rem;">Room Cleaning</h3>
                            <p class="card-text mb-1" style="font-size:.76rem;">Deep clean and fresh linen</p>
                            <span class="price">&#8369;80</span>
                            <span class="text-muted" style="font-size:.7rem;">/ session</span>
                        </div>
                        <button class="add-service-btn" aria-label="Request Room Cleaning" data-name="Room Cleaning &amp; Housekeeping" data-category="housekeeping" data-price="80"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="card h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="service-icon-container"><i class="fa-solid fa-concierge-bell" aria-hidden="true"></i></div>
                        <div class="flex-grow-1">
                            <h3 class="card-title mb-0" style="font-size:.92rem;">Room Service</h3>
                            <p class="card-text mb-1" style="font-size:.76rem;">Meals brought to your door</p>
                            <span class="price">&#8369;50</span>
                            <span class="text-muted" style="font-size:.7rem;">/ delivery</span>
                        </div>
                        <button class="add-service-btn" aria-label="Request Room Service" data-name="In-Room Dining" data-category="dining" data-price="50"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="mt-4" aria-labelledby="reviewBandTitle">
        <div class="card extend-stay">
            <div class="card-body d-flex justify-content-between align-items-center gap-3">
                <div>
                    <h2 class="card-title-extend mb-1" id="reviewBandTitle" style="font-family:var(--m-display);font-weight:500;">Tell us what you think</h2>
                    <p class="card-text mb-0" style="font-size:.85rem;">Leave a review and the front desk adds a free drink to your stay.</p>
                </div>
                <a class="btn review text-nowrap" href="review.php">Review <i class="bi bi-star-fill ms-1" aria-hidden="true"></i></a>
            </div>
        </div>
    </section>

    <?php
    // The home-screen offer sits at the end of the dashboard: after the guest's
    // own business (stay, shortcuts, bill, services) and after the review band,
    // so it is the last thing read rather than the first. ?install=1 shows it on
    // demand, the same review hook as ?tour=1.
    include __DIR__ . '/includes/install_prompt.php';
    ?>

</main>

<p class="tour-replay-wrap">
    <button type="button" class="tour-replay" data-portal-tour><?= ui_t('Take the five-step tour', 'Ulitin ang limang-hakbang na tour') ?></button>
</p>

<div class="portal-footer">
    <div class="d-flex justify-content-center gap-4 mb-2">
        <a href="contact.php"><?= ui_t('Contact', 'Makipag-ugnayan') ?></a>
        <a href="#" data-bs-toggle="modal" data-bs-target="#privacyModal"><?= ui_t('Privacy Policy', 'Patakaran sa Privacy') ?></a>
        <a href="https://marlehotel.site" target="_blank" rel="noopener">marlehotel.site</a>
    </div>
    <div class="copyright">&copy; <?= substr(marle_hotel_date(0), 0, 4) ?> Marle Hotel &amp; Restaurant. <?= ui_t('All rights reserved.', 'Lahat ng karapatan ay nakalaan.') ?></div>
</div>

<?php include __DIR__ . '/includes/portal_tour.php'; ?>

<script>
(function () {
    function fmt(amount) {
        return '\u20b1' + parseFloat(amount).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    function setAll(val) {
        ['bill-checkin', 'bill-extension', 'bill-service', 'bill-food', 'bill-total']
            .forEach(function (id) { var el = document.getElementById(id); if (el) el.textContent = val; });
    }
    fetch('get_bill_summary.php')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) { setAll('\u2014'); return; }
            document.getElementById('bill-checkin').textContent   = fmt(d.checkin);
            document.getElementById('bill-extension').textContent = fmt(d.extension);
            document.getElementById('bill-service').textContent   = fmt(d.service);
            document.getElementById('bill-food').textContent      = fmt(d.food);
            document.getElementById('bill-total').textContent     = fmt(d.total);
        })
        .catch(function () { setAll('\u2014'); });
})();
</script>

<?php include __DIR__ . '/includes/review_prompt.php'; ?>

<script>
(function () {
    var toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center border-0';
    toastEl.setAttribute('role', 'alert');
    toastEl.innerHTML = '<div class="d-flex"><div class="toast-body fw-semibold" id="homeServiceToastMsg"></div>' +
        '<button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    document.body.appendChild(toastEl);

    function showToast(msg, ok) {
        toastEl.className = 'toast align-items-center border-0 text-bg-' + (ok ? 'success' : 'danger');
        document.getElementById('homeServiceToastMsg').textContent = msg;
        if (window.bootstrap) new bootstrap.Toast(toastEl, { delay: 3000 }).show();
    }

    document.querySelectorAll('.add-service-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var name = btn.dataset.name || '';
            if (!name) return;

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            var body = new URLSearchParams({
                service_name:     name,
                service_category: btn.dataset.category || '',
                quantity:         1,
                unit_price:       btn.dataset.price || 0
            });

            fetch('submit_service.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (data) { showToast(data.message || 'Request submitted.', !!data.success); })
            .catch(function () { showToast('Network error. Please try again.', false); })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-plus-lg"></i>';
            });
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
