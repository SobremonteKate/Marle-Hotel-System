<?php
require_once __DIR__ . '/includes/session_guard.php';
// Fetch this guest's service requests
$svcRequests = [];
if ($customerId > 0) {
    $svcStmt = $conn->prepare(
        "SELECT r.id, r.status, r.denial_reason, r.created_at,
                sr.service_name, sr.service_category, sr.quantity,
                sr.unit_price, sr.total_amount, sr.payment_status
         FROM requests r
         JOIN service_requests sr ON sr.request_id = r.id
         WHERE r.customer_id = ? AND r.type = 'service'
         ORDER BY r.created_at DESC"
    );
    $svcStmt->bind_param('i', $customerId);
    $svcStmt->execute();
    $svcResult = $svcStmt->get_result();
    while ($row = $svcResult->fetch_assoc()) {
        $svcRequests[] = $row;
    }
    $svcStmt->close();
}


?>
<?php
$pageTitle = 'Hotel Services';
$activePage = 'services';
$showBackButton = true;
$breadcrumb = 'Services';
$pageCss = 'css/services.css';
include __DIR__ . '/includes/header.php';
?>


    <!-- Session expiry warning toast (15 min) -->
    

    <!-- Session expired overlay -->
    

    <main class="portal-main services-page">
    <!-- Home - CHANGE FONTS LATER -->
    <header id="home" class="services-hero">
        <div class="landing-text">
            <p style="color:var(--marle-gold-deep)">AVAILABLE NOW</p>
            <h1>What can we do <br> for you today?</h1>
            <div class="line"></div>
            <p style="color:#7a6b52">Request a service and we'll be right there.</p>
        </div>
    </header>

    <!-- Services button filter -->
    <div class="container services-category-area">
        <div class="d-flex flex-nowrap overflow-auto scroll-wrapper py-3 gap-2 justify-content-md-center">
            <button class="btn category-btn active" data-filter="all">All</button>
            <button class="btn category-btn" data-filter="housekeeping">Housekeeping</button>
            <button class="btn category-btn" data-filter="wellness">Wellness</button>
            <button class="btn category-btn" data-filter="dining">Dining</button>
            <button class="btn category-btn" data-filter="laundry">Laundry</button>
        </div>
    </div>

    <!-- Services cards -->
    <div class="content p-4 mb-2">
        <div class="container">
            <div class="row g-3" id="services-grid">

                <div class="col-12 col-md-6 service-card" data-category="wellness">
                    <div class="card">
                        <div class="card-body">
                            <div class="card-icon"><i class="fa-solid fa-bed"></i></div>
                            <div class="flex-grow-1 d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title">Deluxe Bath & Spa</h5>
                                    <p class="card-text">In-room spa treatment with premium bath oils and relaxation ritual.</p>
                                    <p class="mb-0">
                                        <span class="price">&#8369;123</span>
                                        <span class="text-muted small">/ per session</span>
                                    </p>
                                </div>
                                <button class="add-service-btn" aria-label="Request Deluxe Bath and Spa" data-name="Deluxe Bath &amp; Spa" data-category="wellness" data-price="123">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 service-card" data-category="housekeeping">
                    <div class="card">
                        <div class="card-body">
                            <div class="card-icon"><i class="fa-solid fa-broom"></i></div>
                            <div class="flex-grow-1 d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title">Room Cleaning & Housekeeping</h5>
                                    <p class="card-text">Daily cleaning, bed making, trash removal. Extra towels or pillow replacement on request.</p>
                                    <p class="mb-0">
                                        <span class="price">&#8369;80</span>
                                        <span class="text-muted small">/ per session</span>
                                    </p>
                                </div>
                                <button class="add-service-btn" data-name="Room Cleaning &amp; Housekeeping" data-category="housekeeping" data-price="80">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 service-card" data-category="dining">
                    <div class="card">
                        <div class="card-body">
                            <div class="card-icon"><i class="fa-solid fa-utensils"></i></div>
                            <div class="flex-grow-1 d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title">In-Room Dining</h5>
                                    <p class="card-text">Full breakfast, lunch, and dinner menu delivered to your room. Beverage service included.</p>
                                    <p class="mb-0">
                                        <span class="price">&#8369;350</span>
                                        <span class="text-muted small">/ per order</span>
                                    </p>
                                </div>
                                <button class="add-service-btn" data-name="In-Room Dining" data-category="dining" data-price="350">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 service-card" data-category="dining">
                    <div class="card">
                        <div class="card-body">
                            <div class="card-icon"><i class="fa-solid fa-glass-water"></i></div>
                            <div class="flex-grow-1 d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title">Mini-Bar / Snacks</h5>
                                    <p class="card-text">Soft drinks, bottled water, and a selection of snacks delivered to your room on request.</p>
                                    <p class="mb-0">
                                        <span class="price">&#8369;50</span>
                                        <span class="text-muted small">/ per item</span>
                                    </p>
                                </div>
                                <button class="add-service-btn" data-name="Mini-Bar / Snacks" data-category="dining" data-price="50">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 service-card" data-category="laundry">
                    <div class="card">
                        <div class="card-body">
                            <div class="card-icon"><i class="fa-solid fa-shirt"></i></div>
                            <div class="flex-grow-1 d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title">Laundry & Press</h5>
                                    <p class="card-text">Same-day laundry pickup, wash, fold, and return service.</p>
                                    <p class="mb-0">
                                        <span class="price">&#8369;150</span>
                                        <span class="text-muted small">/ per bag</span>
                                    </p>
                                </div>
                                <button class="add-service-btn" data-name="Laundry &amp; Press" data-category="laundry" data-price="150">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 service-card" data-category="housekeeping">
                    <div class="card">
                        <div class="card-body">
                            <div class="card-icon"><i class="fa-solid fa-pump-soap"></i></div>
                            <div class="flex-grow-1 d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title">Toiletries & Amenities</h5>
                                    <p class="card-text">Extra shampoo, soap, toothbrush, or slippers delivered to your room on request.</p>
                                    <p class="mb-0">
                                        <span class="price">Free</span>
                                        <span class="text-muted small">/ on request</span>
                                    </p>
                                </div>
                                <button class="add-service-btn" data-name="Toiletries &amp; Amenities" data-category="housekeeping" data-price="0">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 service-card" data-category="wellness">
                    <div class="card">
                        <div class="card-body">
                            <div class="card-icon"><i class="fa-solid fa-champagne-glasses"></i></div>
                            <div class="flex-grow-1 d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title">Special Occasions Setup</h5>
                                    <p class="card-text">Room decorations for birthdays, anniversaries, and honeymoon setups. Cake or flowers delivery available.</p>
                                    <p class="mb-0">
                                        <span class="price">&#8369;500</span>
                                        <span class="text-muted small">/ per setup</span>
                                    </p>
                                </div>
                                <button class="add-service-btn" data-name="Special Occasions Setup" data-category="wellness" data-price="500">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 service-card" data-category="wellness">
                    <div class="card">
                        <div class="card-body">
                            <div class="card-icon"><i class="fa-solid fa-film"></i></div>
                            <div class="flex-grow-1 d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title">Entertainment Services</h5>
                                    <p class="card-text">DVD or streaming setup, board games, or magazines delivered directly to your room.</p>
                                    <p class="mb-0">
                                        <span class="price">&#8369;100</span>
                                        <span class="text-muted small">/ per request</span>
                                    </p>
                                </div>
                                <button class="add-service-btn" data-name="Entertainment Services" data-category="wellness" data-price="100">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 service-card" data-category="housekeeping">
                    <div class="card">
                        <div class="card-body">
                            <div class="card-icon"><i class="fa-solid fa-clock"></i></div>
                            <div class="flex-grow-1 d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title">Wake-Up Call / Alarm</h5>
                                    <p class="card-text">Personalized wake-up calls or messages delivered at your preferred time.</p>
                                    <p class="mb-0">
                                        <span class="price">Free</span>
                                        <span class="text-muted small">/ on request</span>
                                    </p>
                                </div>
                                <button class="add-service-btn" data-name="Wake-Up Call / Alarm" data-category="housekeeping" data-price="0">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 service-card" data-category="wellness">
                    <div class="card">
                        <div class="card-body">
                            <div class="card-icon"><i class="fa-solid fa-kit-medical"></i></div>
                            <div class="flex-grow-1 d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title">Medical / First Aid</h5>
                                    <p class="card-text">Basic medical kits or over-the-counter medicines (e.g., paracetamol) delivered to your room.</p>
                                    <p class="mb-0">
                                        <span class="price">&#8369;50</span>
                                        <span class="text-muted small">/ per item</span>
                                    </p>
                                </div>
                                <button class="add-service-btn" data-name="Medical / First Aid" data-category="wellness" data-price="50">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6 service-card" data-category="housekeeping">
                    <div class="card">
                        <div class="card-body">
                            <div class="card-icon"><i class="fa-solid fa-bed"></i></div>
                            <div class="flex-grow-1 d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="card-title">Extra Bedding / Furniture</h5>
                                    <p class="card-text">Rollaway beds, extra pillows, or blankets delivered to your room on request.</p>
                                    <p class="mb-0">
                                        <span class="price">&#8369;200</span>
                                        <span class="text-muted small">/ per item</span>
                                    </p>
                                </div>
                                <button class="add-service-btn" data-name="Extra Bedding / Furniture" data-category="housekeeping" data-price="200">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Tap to see more button container -->
            <div class="text-center mt-4" id="see-more-container">
                <button type="button" class="btn see-more-btn" id="toggleServicesBtn">Tap to see more</button>
            </div>
        </div>
    </div>

    <!-- My Service Requests -->
    <div class="content p-4 mb-2">
        <div class="container">
            <h4 class="mb-3" style="color:#333;">My Requests</h4>
            
            <!-- Payment Status Filter -->
            <div class="d-flex flex-nowrap overflow-auto gap-2 mb-3" id="paymentFilterArea">
                <button class="btn btn-sm status-filter-btn active" data-filter="all" style="white-space: nowrap;">All</button>
                <button class="btn btn-sm status-filter-btn" data-filter="unpaid" style="white-space: nowrap;">Unpaid</button>
                <button class="btn btn-sm status-filter-btn" data-filter="paid" style="white-space: nowrap;">Paid</button>
                <button class="btn btn-sm status-filter-btn" data-filter="denied" style="white-space: nowrap;">Denied</button>
            </div>
            
            <div id="svcRequestsContainer">
                <?php if (empty($svcRequests)): ?>
                    <p class="text-muted">No service requests yet. Tap <strong>+</strong> on any service to request it.</p>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($svcRequests as $svc): ?>
                        <div class="col-12">
                            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                                <div class="card-body d-flex justify-content-between align-items-start flex-wrap gap-2">
                                    <div style="flex: 1; min-width: 200px;">
                                        <strong><?= htmlspecialchars($svc['service_name']) ?></strong>
                                        <?php if ((int)$svc['quantity'] > 1): ?>
                                            <span class="text-muted small">x<?= (int)$svc['quantity'] ?></span>
                                        <?php endif; ?>
                                        <br>
                                        <span class="text-muted small">
                                            <?= date('M j, g:ia', strtotime($svc['created_at'])) ?>
                                        </span>
                                    </div>
                                    <div class="d-flex flex-column align-items-end gap-2">
                                        <span class="fw-bold">&#8369;<?= number_format((float)$svc['total_amount'], 2) ?></span>
                                        <div class="d-flex gap-2 flex-wrap justify-content-end">
                                            <span class="badge <?php
                                                echo match($svc['status']) {
                                                    'pending'  => 'bg-warning text-dark',
                                                    'approved' => 'bg-success',
                                                    'denied'   => 'bg-danger',
                                                    default    => 'bg-secondary'
                                                };
                                            ?>"><?= ucfirst($svc['status']) ?></span>
                                            <?php if ($svc['status'] === 'approved'): ?>
                                                <span class="<?= $svc['payment_status'] === 'paid' ? 'badge-paid' : 'badge-unpaid' ?>">
                                                    <?= ucfirst($svc['payment_status']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($svc['status'] === 'denied' && !empty($svc['denial_reason'])): ?>
                                    <div class="card-footer bg-transparent border-0 pt-0">
                                        <small class="text-danger">
                                            <i class="bi bi-info-circle me-1"></i>
                                            <?= htmlspecialchars($svc['denial_reason']) ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Service Request Toast -->
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:2000;">
        <div id="serviceToast" class="toast align-items-center border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body fw-semibold" id="serviceToastMsg"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <!-- Chat btn -->
    <!-- ── Reusable Chat Widget Component ── -->
    

    <!-- Bottom Nav -->
    

    <!-- Data Privacy Modal -->
    <div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">

                <div class="modal-header">
                    <h4 class="modal-title" id="privacyModalLabel">Data Privacy</h4>
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
                    <button type="button" id="noBtn" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>

            </div>
        </div>
    </div>

    <!-- For clicking the tiny button above -->
    <script>
        document.querySelectorAll('.category-btn').forEach(button => {
            button.addEventListener('click', function () {
                // 1. Remove 'active' class from all buttons
                document.querySelectorAll('.category-btn').forEach(btn => btn.classList.remove('active'));

                // 2. Add 'active' class to the clicked button
                this.classList.add('active');

                // 3. Optional: Smoothly center the button when clicked
                this.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            });
        });
    </script>
    <!-- the logic: -->
    <script>
        const filterButtons = document.querySelectorAll('.category-btn');
        const serviceCards = document.querySelectorAll('.service-card');

        filterButtons.forEach(button => {
            button.addEventListener('click', function () {
                // 1. Handle Active State
                filterButtons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');

                // 2. Center clicked button (for mobile)
                this.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });

                // 3. Filter Logic
                const filterValue = this.getAttribute('data-filter');

                serviceCards.forEach(card => {
                    if (filterValue === 'all' || card.getAttribute('data-category') === filterValue) {
                        card.style.display = 'block'; card.classList.add('fade-in');
                    } else {
                        card.style.display = 'none';  // Hide
                    }
                });
            });
        });
    </script>

    <!-- For clicking bottom nav icons to make it active -->
    

    <!-- + Button handler: submits service request via submit_service.php -->
    <script>
        // ── Real-time service requests polling ──
        async function loadServiceRequests() {
            try {
                const response = await fetch('fetch_service_requests.php');
                if (!response.ok) return;
                
                const data = await response.json();
                if (!data.success || !Array.isArray(data.requests)) return;
                
                const container = document.getElementById('svcRequestsContainer');
                if (!container) return;
                
                // If no requests, show empty message
                if (data.requests.length === 0) {
                    container.innerHTML = '<p class="text-muted">No service requests yet. Tap <strong>+</strong> on any service to request it.</p>';
                    return;
                }
                
                // Filter requests based on current filter
                let filteredRequests = data.requests;
                if (currentPaymentFilter !== 'all') {
                    filteredRequests = data.requests.filter(svc => {
                        if (currentPaymentFilter === 'denied') {
                            return svc.status === 'denied';
                        } else if (currentPaymentFilter === 'paid') {
                            return svc.status === 'approved' && svc.payment_status === 'paid';
                        } else if (currentPaymentFilter === 'unpaid') {
                            return svc.status === 'approved' && svc.payment_status === 'unpaid';
                        }
                        return true;
                    });
                }
                
                // If no requests after filtering, show message
                if (filteredRequests.length === 0) {
                    container.innerHTML = '<p class="text-muted">No requests match the selected filter.</p>';
                    return;
                }
                
                // Build HTML for all filtered requests
                let html = '<div class="row g-3">';
                filteredRequests.forEach(svc => {
                    const statusClass = svc.status === 'pending' ? 'bg-warning text-dark' : 
                                       (svc.status === 'approved' ? 'bg-success' : 'bg-danger');
                    const paymentDisplay = svc.status === 'approved' 
                        ? `<span class="${svc.payment_status === 'paid' ? 'badge-paid' : 'badge-unpaid'}">${svc.payment_status.charAt(0).toUpperCase() + svc.payment_status.slice(1)}</span>`
                        : '';
                    
                    html += `
                        <div class="col-12">
                            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                                <div class="card-body d-flex justify-content-between align-items-start flex-wrap gap-2">
                                    <div style="flex: 1; min-width: 200px;">
                                        <strong>${escHtmlSvc(svc.service_name)}</strong>
                                        ${svc.quantity > 1 ? `<span class="text-muted small">x${svc.quantity}</span>` : ''}
                                        <br>
                                        <span class="text-muted small">${svc.created_at_formatted}</span>
                                    </div>
                                    <div class="d-flex flex-column align-items-end gap-2">
                                        <span class="fw-bold">&#8369;${svc.total_amount}</span>
                                        <div class="d-flex gap-2 flex-wrap justify-content-end">
                                            <span class="badge ${statusClass}">${svc.status.charAt(0).toUpperCase() + svc.status.slice(1)}</span>
                                            ${paymentDisplay}
                                        </div>
                                    </div>
                                </div>
                                ${svc.status === 'denied' && svc.denial_reason 
                                    ? `<div class="card-footer bg-transparent border-0 pt-0">
                                        <small class="text-danger">
                                            <i class="bi bi-info-circle me-1"></i>
                                            ${escHtmlSvc(svc.denial_reason)}
                                        </small>
                                    </div>` 
                                    : ''}
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                
                container.innerHTML = html;
            } catch (err) {
                console.error('Error loading service requests:', err);
            }
        }
        
        function escHtmlSvc(str) {
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
        
        // Load requests on page load and set up polling every 5 seconds
        let currentPaymentFilter = 'all';
        
        document.addEventListener('DOMContentLoaded', function () {
            // Set up filter button listeners
            document.querySelectorAll('.status-filter-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    // Remove active class from all buttons
                    document.querySelectorAll('.status-filter-btn').forEach(b => b.classList.remove('active'));
                    // Add active class to clicked button
                    this.classList.add('active');
                    // Update filter and reload
                    currentPaymentFilter = this.getAttribute('data-filter');
                    loadServiceRequests();
                });
            });
            
            loadServiceRequests();
            setInterval(loadServiceRequests, 5000);
        });

        document.querySelectorAll('.add-service-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const name     = this.dataset.name     || '';
                const category = this.dataset.category || '';
                const price    = parseFloat(this.dataset.price) || 0;

                if (!name) return;

                this.disabled = true;
                this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

                const body = new URLSearchParams({
                    service_name:     name,
                    service_category: category,
                    quantity:         1,
                    unit_price:       price
                });

                fetch('submit_service.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    body.toString()
                })
                .then(r => r.json())
                .then(data => {
                    showServiceToast(data.message, data.success ? 'success' : 'danger');
                    // Refresh requests after successful submission
                    if (data.success) loadServiceRequests();
                })
                .catch(() => {
                    showServiceToast('Network error. Please try again.', 'danger');
                })
                .finally(() => {
                    this.disabled = false;
                    this.innerHTML = '<i class="bi bi-plus-lg"></i>';
                });
            });
        });

        function showServiceToast(msg, type) {
            const el = document.getElementById('serviceToast');
            el.className = 'toast align-items-center border-0 text-bg-' + type;
            document.getElementById('serviceToastMsg').textContent = msg;
            new bootstrap.Toast(el, { delay: 3000 }).show();
        }
    </script>

    <!-- Live countdown widget + expiry overlay -->
    

    <!-- See more btn -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
        const filterButtons = document.querySelectorAll('.category-btn');
        const serviceCards = Array.from(document.querySelectorAll('.service-card'));
        const toggleBtn = document.getElementById('toggleServicesBtn');
        const seeMoreContainer = document.getElementById('see-more-container');
        
        let currentFilter = 'all';
        let isExpanded = false;

        function applyLayoutFilter() {
                // Remove fade-in class from all cards first
                serviceCards.forEach(card => card.classList.remove("fade-in"));
            // Filter down the array based on selected category type
            const matches = serviceCards.filter(card => {
                return currentFilter === 'all' || card.getAttribute('data-category') === currentFilter;
            });

            // Hide anomalies out of scope completely
            serviceCards.forEach(card => card.style.display = 'none');

            // Apply visible boundaries (slice off at index 3 unless expanded)
            matches.forEach((card, index) => {
                if (!isExpanded && index >= 3) {
                    card.style.display = 'none';
                } else {
                    card.style.display = "block"; card.classList.add("fade-in"); }
            });

            // If overall items for category <= 3, keep structural hide layout hidden
            if (matches.length <= 3) {
                seeMoreContainer.style.display = 'none';
            } else {
                seeMoreContainer.style.display = 'block';
                toggleBtn.textContent = isExpanded ? 'Show Less' : 'Tap to see more';
            }
        }

        // Toggle Click Interactions
        toggleBtn.addEventListener('click', function () {
            isExpanded = !isExpanded;
            applyLayoutFilter();
        });

        // Filter Category Click updates
        filterButtons.forEach(button => {
            button.addEventListener('click', function () {
                filterButtons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');

                this.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });

                currentFilter = this.getAttribute('data-filter');
                isExpanded = false; // Reset toggle expansion rule on layout pivot swap
                applyLayoutFilter();
            });
        });

        // Instantiate display configurations initial state values
        applyLayoutFilter();
    });
    </script>


    </main>
<?php include __DIR__ . '/includes/review_prompt.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>

