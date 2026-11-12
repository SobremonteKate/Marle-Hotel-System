<?php
require_once __DIR__ . '/includes/session_guard.php';
require_once __DIR__ . '/../includes/log_action.php';
require_once __DIR__ . '/../includes/server_clock.php';   // marle_hotel_date(): the hotel's date

$guestId = (int)($_SESSION['customer_id'] ?? 0);
$guestName = 'Guest ' . str_pad((string)$guestId, 3, '0', STR_PAD_LEFT);
$roomNo = $_SESSION['room_number'] ?? 'N/A';
$guestToken = trim((string)($_SESSION['guest_token'] ?? ''));

$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $category = isset($_POST['category']) ? trim($_POST['category']) : '';
    $reviewText = isset($_POST['review']) ? trim($_POST['review']) : '';
    $submittedGuestName = trim((string)($_POST['guest_name'] ?? ''));
    $reviewGuestName = $submittedGuestName !== ''
        ? mb_substr(preg_replace('/\s+/', ' ', $submittedGuestName), 0, 255)
        : 'Anonymous Guest';
    $validCategories = ['hotel', 'restaurant', 'amenities', 'service'];

    if ($rating < 1 || $rating > 5) {
        $errorMsg = 'Please select a star rating before submitting.';
    } elseif (!in_array($category, $validCategories, true)) {
        $errorMsg = 'Please select a valid category.';
    } elseif ($reviewText === '') {
        $errorMsg = 'Please write something in the review field.';
    } else {
        $stmtCheck = $guestToken !== ''
            ? $conn->prepare('SELECT id FROM reviewstbl WHERE token = ? OR (token IS NULL AND guest_name = ? AND room_no = ?)')
            : $conn->prepare('SELECT id FROM reviewstbl WHERE guest_name = ? AND room_no = ?');
        if ($guestToken !== '') {
            $stmtCheck->bind_param('sss', $guestToken, $guestName, $roomNo);
        } else {
            $stmtCheck->bind_param('ss', $guestName, $roomNo);
        }
        $stmtCheck->execute();
        $stmtCheck->store_result();

        if ($stmtCheck->num_rows > 0) {
            $errorMsg = 'You have already submitted a review. Thank you for your feedback!';
        } else {
            // The hotel's calendar day, not PHP's (includes/server_clock.php): the staff Reviews
// page filters these rows by the hotel's today, so a review written after midnight
// must not be filed under yesterday.
            $reviewDate = marle_hotel_date(0, $conn);
              $stmt = $conn->prepare(
                 'INSERT INTO reviewstbl (guest_name, room_no, token, rating, category, review_text, review_date)
                  VALUES (?, ?, ?, ?, ?, ?, ?)'
              );
              $stmt->bind_param('sssisss', $reviewGuestName, $roomNo, $guestToken, $rating, $category, $reviewText, $reviewDate);

            if ($stmt->execute()) {
                log_event($conn, 'portal_review_submitted', 'review', $conn->insert_id, 'success', 'Room ' . $roomNo . ' — ' . $rating . '★ ' . $category);
                $successMsg = 'Thank you for your review! Claim your free drink at the front desk.';
            } else {
                $errorMsg = 'Something went wrong. Please try again.';
            }
            $stmt->close();
        }
        $stmtCheck->close();
    }
}

$alreadyReviewed = false;
if ($successMsg === '' && $errorMsg === '') {
    $preCheck = $guestToken !== ''
        ? $conn->prepare('SELECT id FROM reviewstbl WHERE token = ? OR (token IS NULL AND guest_name = ? AND room_no = ?)')
        : $conn->prepare('SELECT id FROM reviewstbl WHERE guest_name = ? AND room_no = ?');
    if ($guestToken !== '') {
        $preCheck->bind_param('sss', $guestToken, $guestName, $roomNo);
    } else {
        $preCheck->bind_param('ss', $guestName, $roomNo);
    }
    $preCheck->execute();
    $preCheck->store_result();
    $alreadyReviewed = $preCheck->num_rows > 0;
    $preCheck->close();
}
?>
<?php
$pageTitle = 'Review';
$activePage = 'review';
$showBackButton = true;
$breadcrumb = 'Handle form submission';
$pageCss = 'css/review.css';
include __DIR__ . '/includes/header.php';
?>


    <!-- Session expiry warning toast (15 min) -->
    

    <!-- Session expired overlay -->
    

    <main class="portal-main review-page">
    <!-- Hero banner -->
    <header id="home" class="review-hero">
        <div class="landing-text">
            <p style="color:var(--marle-gold-deep)">REVIEW</p>
            <h1>How's your stay <br> so far?</h1>
            <div class="line"></div>
            <p style="color:#7a6b52">Your feedback helps us grow. Leave us a review—it only takes a minute!</p>
        </div>
    </header>

    <!-- Review section -->
    <div class="container mt-4 mb-5">
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8">

                <?php if ($successMsg): ?>
                    <div class="alert alert-success-custom d-flex align-items-start gap-3 mb-4">
                        <i class="bi bi-check-circle-fill fs-4 mt-1" style="color:var(--marle-gold-deep);"></i>
                        <div>
                            <strong>Review submitted!</strong><br>
                            <?php echo htmlspecialchars($successMsg); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($alreadyReviewed && !$successMsg): ?>
                    <div class="alert alert-success-custom d-flex align-items-start gap-3 mb-4">
                        <i class="bi bi-check-circle-fill fs-4 mt-1" style="color:var(--marle-gold-deep);"></i>
                        <div>
                            <strong>You've already left a review!</strong><br>
                            We appreciate your feedback. Enjoy the rest of your stay at Marle Hotel &amp; Restaurant.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger alert-danger-custom d-flex align-items-start gap-3 mb-4">
                        <i class="bi bi-exclamation-circle-fill fs-4 mt-1"></i>
                        <div><?php echo htmlspecialchars($errorMsg); ?></div>
                    </div>
                <?php endif; ?>

                <!-- Auto-filled meta info -->
                <div class="review-meta-strip mb-4">
                    <span>
                        <i class="bi bi-person-fill" style="color:var(--marle-gold-deep);"></i>
                        <strong><?php echo htmlspecialchars($guestName); ?></strong>
                    </span>
                    <span>
                        <i class="bi bi-door-closed-fill" style="color:var(--marle-gold-deep);"></i>
                        Room <strong><?php echo htmlspecialchars($roomNo); ?></strong>
                    </span>
                    <span>
                        <i class="bi bi-calendar3" style="color:var(--marle-gold-deep);"></i>
                        <!-- The hotel's own day, not PHP's (includes/server_clock.php): this date
                             sits next to the room the guest stayed in, and PHP's clock here is set
                             to a different timezone than the database that stamped the stay, so
                             bare date() showed the wrong day for part of every day. -->
                        <strong><?php echo date('F j, Y', strtotime(marle_hotel_date())); ?></strong>
                    </span>
                </div>

                <?php if (!$successMsg && !$alreadyReviewed): ?>
                <form method="POST" action="review.php">

                    <!-- Star rating -->
                    <div class="mb-4">
                        <p class="section-label">Your Rating</p>
                        <div class="star-rating" id="starRating">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" name="rating" id="star<?php echo $i; ?>" value="<?php echo $i; ?>"
                                    <?php echo (isset($_POST['rating']) && (int)$_POST['rating'] === $i) ? 'checked' : ''; ?>>
                                <label for="star<?php echo $i; ?>">
                                    <i class="bi bi-star-fill"></i>
                                </label>
                            <?php endfor; ?>
                        </div>
                        <small class="text-muted mt-2 d-block" id="starLabel">Tap a star to rate</small>
                    </div>

                    <!-- Category -->
                    <div class="mb-4">
                        <p class="section-label">Category</p>
                        <div class="category-group">
                            <?php
                            $categories = [
                                'hotel'      => 'Hotel',
                                'restaurant' => 'Restaurant',
                                'amenities'  => 'Amenities',
                                'service'    => 'Service',
                            ];
                            foreach ($categories as $val => $label):
                                $checked = (isset($_POST['category']) && $_POST['category'] === $val) ? 'checked' : '';
                            ?>
                                <input type="radio" name="category" id="cat_<?php echo $val; ?>" value="<?php echo $val; ?>" <?php echo $checked; ?>>
                                <label for="cat_<?php echo $val; ?>"><?php echo $label; ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Optional guest name -->
                    <div class="mb-4">
                        <p class="section-label">Your Name <small class="text-muted fw-normal">(Optional)</small></p>
                        <input type="text" class="form-control" name="guest_name" id="guestName"
                            maxlength="255" autocomplete="name" placeholder="Leave blank to review anonymously"
                            value="<?php echo isset($_POST['guest_name']) ? htmlspecialchars($_POST['guest_name']) : ''; ?>">
                        <small class="text-muted mt-2 d-block">If left blank, only your room number will be shown to staff.</small>
                    </div>

                    <!-- Comment -->
                    <div class="mb-4">
                        <p class="section-label">Your Comment</p>
                        <textarea class="form-control" name="review" id="review" rows="4"
                            placeholder="Describe your experience, e.g. the rooms are clean, the staff is friendly, etc."><?php echo isset($_POST['review']) ? htmlspecialchars($_POST['review']) : ''; ?></textarea>
                    </div>

                    <button type="submit" class="btn submit-btn">
                        Submit Review <i class="bi bi-arrow-right ms-1"></i>
                    </button>

                    <p class="small text-muted mt-2 mb-4">
                        Claim your free drink by the front desk after submitting.
                    </p>

                </form>
                <?php endif; ?>

            </div>
        </div>
    </div>

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

    <!-- Scripts -->
    <script>
    (function () {
        // The hint under the stars used to stay "Tap a star to rate" even with a
        // rating chosen, so the guest had no confirmation of what they picked.
        // "4 / 5" reads the same in either language, so it needs no translation.
        var label = document.getElementById('starLabel');
        var group = document.getElementById('starRating');
        if (!label || !group) return;
        group.addEventListener('change', function () {
            var picked = group.querySelector('input:checked');
            if (picked) label.textContent = picked.value + ' / 5';
        });
    })();
    </script>

    <!-- Live countdown widget + expiry overlay -->
    

    

    </main>
<?php include __DIR__ . '/includes/footer.php'; ?>

