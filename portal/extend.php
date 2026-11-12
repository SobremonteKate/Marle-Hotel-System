<?php
require_once __DIR__ . '/includes/session_guard.php';

$custStmt = $conn->prepare(
    "SELECT room_no, date_from, date_to, hours
     FROM customerstbl
     WHERE id = ? AND status = 'checked-in'
     LIMIT 1"
);
$custStmt->bind_param('i', $customerId);
$custStmt->execute();
$cust = $custStmt->get_result()->fetch_assoc();
$custStmt->close();

$rtStmt = $conn->prepare(
    "SELECT rooms FROM roomstbl WHERE room_number = ? LIMIT 1"
);
$rtStmt->bind_param('s', $roomNumber);
$rtStmt->execute();
$rtRow = $rtStmt->get_result()->fetch_assoc();
$rtStmt->close();
$roomType = $rtRow['rooms'] ?? null;

$rateMap = [
    'Family' => 200,
    'Suite' => 200,
    'With Garage' => 150,
    'Without Garage' => 150,
];
$ratePerHour = $rateMap[$roomType] ?? 150;

$pendingStmt = $conn->prepare(
    "SELECT r.id FROM requests r
     JOIN extension_requests er ON er.request_id = r.id
     WHERE r.customer_id = ?
       AND r.type = 'extend'
       AND r.status = 'pending'
     LIMIT 1"
);
$pendingStmt->bind_param('i', $customerId);
$pendingStmt->execute();
$pendingStmt->store_result();
$hasPending = $pendingStmt->num_rows > 0;
$pendingStmt->close();

$checkIn = $cust ? date('M j', strtotime($cust['date_from'])) : '—';
$checkOut = $cust ? date('M j', strtotime($cust['date_to'])) : '—';
$currentHours = $cust ? (int)$cust['hours'] : $hoursOfStay;
$checkOutISO = $cust ? date('Y-m-d', strtotime($cust['date_to'])) . 'T00:00:00' : 'now';
$checkOutFull = $cust ? date('F j, Y', strtotime($cust['date_to'])) : '—';

$histStmt = $conn->prepare(
    "SELECT eh.hours_added, eh.total_amount, eh.payment_status,
            eh.new_checkout, eh.approved_at
     FROM extension_history eh
     WHERE eh.customer_id = ?
     ORDER BY eh.approved_at DESC"
);
$histStmt->bind_param('i', $customerId);
$histStmt->execute();
$histRows = $histStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$histStmt->close();

$deniedStmt = $conn->prepare(
    "SELECT r.created_at, r.denial_reason, er.hours_requested
     FROM requests r
     JOIN extension_requests er ON er.request_id = r.id
     WHERE r.customer_id = ? AND r.status = 'denied'
     ORDER BY r.created_at DESC"
);
$deniedStmt->bind_param('i', $customerId);
$deniedStmt->execute();
$deniedRows = $deniedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$deniedStmt->close();

?>
<?php
$pageTitle = 'Extend Stay';
$activePage = 'extend';
$showBackButton = true;
$breadcrumb = 'Extend';
$pageCss = 'css/extend.css';
include __DIR__ . '/includes/header.php';
?>


    <!-- Session expiry warning toast (15 min) -->
    

    <!-- Session expired overlay -->
    

    <main class="portal-main extend-page">
    <!-- Hero -->
    <header id="home" class="extend-hero">
        <div class="landing-text">
            <p>BOOKING EXTENSION</p>
            <h1>Stay a little longer <br> at <span style="color:var(--marle-gold-deep)">Marle.</span></h1>
            <div class="line"></div>
            <p>Your request will be reviewed and confirmed by the front desk.</p>
        </div>
    </header>

    <div class="extend-workspace">
    <section class="container current-booking-area py-3" aria-labelledby="currentBookingTitle">

        <!-- Pending banner — only shown when a request is awaiting approval -->
        <?php if ($hasPending): ?>
        <div class="pending-banner">
            <i class="bi bi-hourglass-split text-warning fs-5"></i>
            <div>
                <strong class="d-block">Extension request pending</strong>
                <span class="text-muted small">The front desk is reviewing your request.</span>
            </div>
        </div>
        <?php endif; ?>

        <p id="currentBookingTitle" class="text-start mb-3 small fw-bold text-muted">CURRENT BOOKING</p>

        <div class="row g-0 align-items-center">

            <div class="col-5 ps-2">
                <p class="mb-0 text-muted small">Room</p>
                <h5 class="mb-0 fw-bold">
                    <?= htmlspecialchars($roomNumber) ?>
                    <?php if ($roomType): ?>
                        &bull; <span class="fw-normal" style="font-size:0.9rem;"><?= htmlspecialchars($roomType) ?></span>
                    <?php endif; ?>
                </h5>
            </div>

            <div class="col-7">
                <div class="d-flex justify-content-center gap-2">
                    <div class="px-4 text-center">
                        <p class="mb-0 text-muted small">Check-in</p>
                        <b class="date d-block"><?= $checkIn ?></b>
                    </div>

                    <div class="border-start border-secondary px-4 text-center">
                        <p class="mb-0 text-muted small">Check-out</p>
                        <b class="date d-block"><?= $checkOut ?></b>
                    </div>
                </div>
            </div>

        </div>

        <div class="row mt-3">
            <div class="col-12 hours-booked d-flex align-items-center justify-content-center gap-2">
                <h2 class="mb-0 hours fw-bold display-5"><?= $currentHours ?></h2>
                <p class="mb-0 lh-1 small">hours<br>booked</p>
            </div>
        </div>
    </section>

   <!-- Additional hours -->
    <section class="container mt-4 extend-step" aria-labelledby="additionalHoursTitle">
        <div class="row align-items-center">
            <div class="text-start ps-3 mb-1">
                <p id="additionalHoursTitle" class="text-start mb-3 small fw-bold text-muted">ADDITIONAL HOURS</p>
            </div>

            <div class="d-flex flex-wrap justify-content-center gap-2" id="hourPresets">
                    <button class="btn btn-outline-gold preset-btn" data-hours="1" onclick="selectHours(1)">1h</button>
                    <button class="btn btn-outline-gold preset-btn" data-hours="2" onclick="selectHours(2)">2h</button>
                    <button class="btn btn-outline-gold preset-btn" data-hours="3" onclick="selectHours(3)">3h</button>
                    <button class="btn btn-outline-gold preset-btn" data-hours="4" onclick="selectHours(4)">4h</button>
                    <button class="btn btn-outline-gold preset-btn" data-hours="6" onclick="selectHours(6)">6h</button>
                    <button class="btn btn-outline-gold preset-btn" data-hours="8" onclick="selectHours(8)">8h</button>
                    <button class="btn btn-outline-gold preset-btn" data-hours="12" onclick="selectHours(12)">12h</button>
                </div>

                <?php if ($roomType): ?>
            <div class="text-center mt-1">
                <span class="rate-badge">
                    &#8369;<?= number_format($ratePerHour) ?>/hr &nbsp;&bull;&nbsp; <?= htmlspecialchars($roomType) ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- New check-out date + Estimated total -->
    <section class="container mt-4 extend-step" aria-labelledby="checkoutEstimateTitle">
        <div class="row justify-content-center align-items-center">
            <div class="text-start ps-3 mb-1">
                <p id="checkoutEstimateTitle" class="text-start mb-2 small fw-bold text-muted">NEW CHECK-OUT DATE</p>
            </div>

            <div class="new-date">
                <p class="new-check-out-date mb-0" id="newCheckoutDisplay"><?= $checkOutFull ?></p>
            </div>

            <div class="d-flex estimated-total justify-content-between align-items-center ps-3 mt-3">
                <div>
                    <p class="mb-2 text-muted small text-uppercase" style="letter-spacing: 0.5px;">Estimated Total</p>
                    <h4 class="mb-0 fw-bold">&#8369;<span id="totalDisplay"><?= number_format($ratePerHour) ?></span></h4>
                    <p class="mb-0 text-muted small" id="totalBreakdown" style="font-size: 0.75rem;">
                        &#8369;<?= number_format($ratePerHour) ?>/hr &times; 1 hr
                    </p>
                </div>

                <div class="receipt-icon-container">
                    <i class="bi bi-receipt-cutoff fs-1 text-muted"></i>
                </div>
            </div>

        </div>
    </section>

    <!-- Special request -->
    <section class="container mt-4 extend-step" aria-labelledby="specialRequestTitle">
        <div class="row justify-content-center align-items-center">
            <div class="text-start ps-3 mb-1">
                <p id="specialRequestTitle" class="text-start mb-2 small fw-bold text-muted">SPECIAL REQUESTS
                    <span class="text-muted fw-normal">(optional)</span>
                </p>
            </div>

            <div class="form">
                <div class="form-group">
                    <textarea class="form-control" id="specialRequest" rows="3"
                              placeholder="E.g. early check-in, dietary restrictions, etc."
                              <?= $hasPending ? 'disabled' : '' ?>></textarea>
                </div>
            </div>
        </div>
    </section>

    <!-- Submit button -->
    <section class="container mt-4 mb-3 extend-step extend-submit" aria-label="Submit extension request">
        <div class="row justify-content-center align-items-center">

            <button class="btn extension-btn" id="submitBtn"
                    onclick="submitExtension()"
                    <?= $hasPending ? 'disabled' : '' ?>>
                Send Extension Request <i class="bi bi-arrow-right"></i>
            </button>

            <p class="small text-muted text-center mt-1">
                You'll receive a confirmation once approved by the front desk.
            </p>

        </div>
    </section>
    </div>

    <!-- Extension history -->
    <?php if (!empty($histRows) || !empty($deniedRows)): ?>
    <section class="container mt-2 mb-5 extension-history" aria-labelledby="extensionHistoryTitle">
        <p id="extensionHistoryTitle" class="text-start mb-3 small fw-bold text-muted">EXTENSION HISTORY</p>

        <!-- Payment Status Filter -->
        <div class="d-flex flex-nowrap overflow-auto gap-2 mb-3" id="extPaymentFilterArea">
            <button class="btn btn-sm ext-status-filter-btn active" data-filter="all" style="white-space: nowrap;">All</button>
            <button class="btn btn-sm ext-status-filter-btn" data-filter="unpaid" style="white-space: nowrap;">Unpaid</button>
            <button class="btn btn-sm ext-status-filter-btn" data-filter="paid" style="white-space: nowrap;">Paid</button>
            <button class="btn btn-sm ext-status-filter-btn" data-filter="denied" style="white-space: nowrap;">Denied</button>
        </div>

        <div id="extHistoryContainer">
            <?php foreach ($histRows as $h): ?>
            <div class="history-card d-flex justify-content-between align-items-center" data-ext-status="approved" data-payment-status="<?= $h['payment_status'] ?>">
                <div>
                    <p class="mb-0 fw-semibold">
                        +<?= (int)$h['hours_added'] ?> hour<?= $h['hours_added'] > 1 ? 's' : '' ?>
                    </p>
                    <p class="mb-0 text-muted small">
                        New check-out: <?= date('M j, Y', strtotime($h['new_checkout'])) ?>
                    </p>
                    <p class="mb-0 text-muted small">
                        Approved: <?= date('M j, g:i A', strtotime($h['approved_at'])) ?>
                    </p>
                </div>
                <div class="text-end">
                    <p class="mb-1 fw-bold">&#8369;<?= number_format((float)$h['total_amount'], 2) ?></p>
                    <?php if ($h['payment_status'] === 'paid'): ?>
                        <span class="badge-paid">Paid</span>
                    <?php else: ?>
                        <span class="badge-unpaid">Unpaid</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php foreach ($deniedRows as $d): ?>
            <div class="history-card denied" data-ext-status="denied">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="mb-0 fw-semibold text-danger">Request Denied</p>
                        <p class="mb-0 text-muted small">
                            +<?= (int)$d['hours_requested'] ?> hr &bull;
                            <?= date('M j, g:i A', strtotime($d['created_at'])) ?>
                        </p>
                        <?php if (!empty($d['denial_reason'])): ?>
                            <p class="mb-0 small mt-1">
                                <span class="text-muted">Reason:</span>
                                <?= htmlspecialchars($d['denial_reason']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <span class="badge bg-danger">Denied</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </section>
    <?php endif; ?>

    <!-- Chat Button (matching portal.php style) -->
    <!-- ── Reusable Chat Widget Component ── -->
    

 <!-- Bottom Nav -->
    

    <!-- Toast feedback -->
    <div class="position-fixed top-0 start-50 translate-middle-x p-3" style="z-index: 2000;">
        <div id="feedbackToast" class="toast align-items-center border-0" role="alert" aria-live="assertive">
            <div class="d-flex">
                <div class="toast-body fw-semibold" id="feedbackMsg"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <!-- Data Privacy Modal (kept from your original) -->
    <div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="privacyModalLabel">Data Privacy</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="max-height: 75vh; overflow-y: auto;">
                    <h5>1. Acceptance of Terms</h5>
                    <p>By accessing and using this system, you acknowledge that you have read, understood, and agreed to comply with all applicable policies, rules, and regulations governing system usage.</p>
                    <h5>2. User Responsibilities</h5>
                    <p>Users are responsible for maintaining the confidentiality of their account credentials and for all activities conducted under their account.</p>
                    <ul>
                        <li>Do not share login credentials.</li>
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
                    <button type="button" id="noBtn" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom nav active state -->
    

    </main>

    <script>
    // ── PHP data passed to JS ──────────────────────────
        const RATE_PER_HOUR = <?= (int)$ratePerHour ?>;
        const BASE_CHECKOUT = new Date('<?= $checkOutISO ?>');
        const HAS_PENDING   = <?= $hasPending ? 'true' : 'false' ?>;

        let selectedHours = 1;
        const MAX_HOURS   = 12; // Updated limit from 24 to 720 hours

        // Recomputes the entire interface matching current value
        function updateCalculations() {
            
            

            // New checkout date (add hours to base checkout)
            const newCO = new Date(BASE_CHECKOUT.getTime() + selectedHours * 3600 * 1000);
            document.getElementById('newCheckoutDisplay').textContent =
                newCO.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });

            // Total cost
            const total = RATE_PER_HOUR * selectedHours;
            document.getElementById('totalDisplay').textContent =
                total.toLocaleString('en-PH');
            document.getElementById('totalBreakdown').textContent =
                '₱' + RATE_PER_HOUR.toLocaleString('en-PH') + '/hr × ' +
                selectedHours + ' hr' + (selectedHours > 1 ? 's' : '');
        }

        // Initialize state on page load layout configurations
        

        function selectHours(h){if(HAS_PENDING)return;selectedHours=h;document.querySelectorAll(".preset-btn").forEach(function(b){b.classList.toggle("active",parseInt(b.dataset.hours)===h)});updateCalculations()}

selectHours(1);

function submitExtension() {
            if (HAS_PENDING) return;

            const btn = document.getElementById('submitBtn');
            // Remember the label the server rendered — English or Tagalog — so a
            // failed submit puts back exactly that instead of English hard-coded here.
            const btnLabel = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';

            const formData = new FormData();
            formData.append('hours',           selectedHours);
            formData.append('special_request', document.getElementById('specialRequest').value);

            fetch('process_extend.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    showToast(data.message, data.success ? 'success' : 'danger');
                    if (data.success) {
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = btnLabel;
                    }
                })
                .catch(() => {
                    showToast('Network error. Please try again.', 'danger');
                    btn.disabled = false;
                    btn.innerHTML = btnLabel;
                });
        }

        function showToast(msg, type) {
            const el = document.getElementById('feedbackToast');
            el.className = 'toast align-items-center border-0 text-bg-' + type;
            document.getElementById('feedbackMsg').textContent = msg;
            new bootstrap.Toast(el, { delay: 3500 }).show();
        }

        // ── Chat: handled entirely by includes/chat_widget.php's own
        //    script (toggle, loadChat, sendGuestMessage, escHtml, etc.)
        //    A duplicate implementation used to live here — it attached a
        //    second click handler to #chatToggleBtn that immediately
        //    re-closed the widget the include had just opened (both fired
        //    on the same click), and its loadChat/sendGuestMessage/escHtml
        //    function declarations silently overwrote the include's own
        //    (later-declared functions with the same name win), losing
        //    read receipts, attachments, and closed-chat handling.

        // ── Extension history filter logic ──
        let currentExtFilter = 'all';
        
        document.querySelectorAll('.ext-status-filter-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                // Remove active from all buttons
                document.querySelectorAll('.ext-status-filter-btn').forEach(b => b.classList.remove('active'));
                // Add active to clicked button
                this.classList.add('active');
                // Update filter
                currentExtFilter = this.getAttribute('data-filter');
                filterExtensionHistory();
            });
        });
        
        function filterExtensionHistory() {
            const cards = document.querySelectorAll('#extHistoryContainer .history-card');
            let visibleCount = 0;
            
            cards.forEach(card => {
                let isVisible = false;
                
                if (currentExtFilter === 'all') {
                    isVisible = true;
                } else if (currentExtFilter === 'denied') {
                    isVisible = card.hasAttribute('data-ext-status') && card.getAttribute('data-ext-status') === 'denied';
                } else if (currentExtFilter === 'paid') {
                    const paymentStatus = card.getAttribute('data-payment-status');
                    isVisible = paymentStatus === 'paid';
                } else if (currentExtFilter === 'unpaid') {
                    const paymentStatus = card.getAttribute('data-payment-status');
                    isVisible = paymentStatus === 'unpaid';
                }
                
                card.style.display = isVisible ? 'block' : 'none';
                if (isVisible) visibleCount++;
            });
            
            // Show message if no items match filter
            const container = document.getElementById('extHistoryContainer');
            let noResultsMsg = container.querySelector('.no-results-msg');
            if (visibleCount === 0) {
                if (!noResultsMsg) {
                    noResultsMsg = document.createElement('p');
                    noResultsMsg.className = 'text-muted no-results-msg';
                    noResultsMsg.textContent = 'No requests match the selected filter.';
                    container.appendChild(noResultsMsg);
                }
            } else if (noResultsMsg) {
                noResultsMsg.remove();
            }
        }
    </script>

    <!-- Live countdown widget + expiry overlay -->
    

    


<?php include __DIR__ . '/includes/review_prompt.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>

