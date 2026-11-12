<?php
require_once __DIR__ . '/includes/session_guard.php';

?>
<?php
$pageTitle = 'Hotel Guide - Marle Hotel';
$activePage = 'guide';
$showBackButton = true;
$breadcrumb = 'Guide';
$pageCss = 'css/hotel_guide.css';
include __DIR__ . '/includes/header.php';
?>


    <!-- Session expiry warning toast (15 min) -->
    

    <!-- Session expired overlay -->
    

    <main class="portal-main guide-page">
    <!-- Page Header — the same composition as Services, Extend, Menu and Review:
         eyebrow, display heading, brass rule, then the one-line summary. The
         Bootstrap `text-center` container this used to sit in was left over from
         the old centred head and was the widest thing on the page (it centred the
         heading full-width but the subtitle inside its own 46ch box, so the two
         lines never lined up with each other or with the cards below). -->
    <header class="guide-header">
        <div class="landing-text">
            <p class="eyebrow"><?= ui_t('Guest guide', 'Gabay ng bisita') ?></p>
            <h1>Welcome to Marle Hotel</h1>
            <div class="line"></div>
            <p class="mb-0">Everything you need to know for a comfortable stay.</p>
        </div>
    </header>

    <!-- Guide Sections -->
    <!-- No Bootstrap `.container` here: its auto margins centred the column 102px
         right of the head, so the cards never lined up with the title above them.
         width/max-width come from css/refresh.css, which left-aligns it. -->
    <div class="guide-content">

        <!-- About -->
        <div class="guide-section">
            <div class="guide-section-header">
                <i class="bi bi-building"></i>
                <h5 class="mb-0">About the Hotel</h5>
            </div>
            <div class="guide-section-body">
                <p>
                    Marle Hotel &amp; Restaurant offers a relaxing and affordable getaway for families,
                    couples, and solo travellers. Located in a convenient area, we provide comfortable
                    rooms, excellent dining, and personalized guest services.
                </p>
            </div>
        </div>

        <!-- Amenities -->
        <div class="guide-section">
            <div class="guide-section-header">
                <i class="bi bi-stars"></i>
                <h5 class="mb-0">Amenities</h5>
            </div>
            <div class="guide-section-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="amenity-item">
                            <i class="bi bi-wifi"></i>
                            <span>Free Wi-Fi</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="amenity-item">
                            <i class="bi bi-tv"></i>
                            <span>Cable TV</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="amenity-item">
                            <i class="bi bi-snow"></i>
                            <span>Air Conditioning</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="amenity-item">
                            <i class="bi bi-car-front"></i>
                            <span>Parking</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="amenity-item">
                            <i class="fa-solid fa-bath"></i>
                            <span>Spa Services</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="amenity-item">
                            <i class="bi bi-cup-hot"></i>
                            <span>Restaurant</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- House Rules -->
        <div class="guide-section">
            <div class="guide-section-header">
                <i class="bi bi-clipboard-check"></i>
                <h5 class="mb-0">House Rules</h5>
            </div>
            <div class="guide-section-body">
                <ul class="guide-rules-list">
                    <li><i class="bi bi-check-circle-fill text-success"></i> Keep noise levels low after 10:00 PM.</li>
                    <li><i class="bi bi-check-circle-fill text-success"></i> No smoking inside the rooms.</li>
                    <li><i class="bi bi-check-circle-fill text-success"></i> Visitors must register at the front desk.
                    </li>
                    <li><i class="bi bi-check-circle-fill text-success"></i> Please do not remove hotel property from
                        the premises.</li>
                    <li><i class="bi bi-check-circle-fill text-success"></i> Pets are not allowed unless prior
                        arrangements have been made.</li>
                    <li><i class="bi bi-check-circle-fill text-success"></i> Guests are responsible for any damage to
                        room furnishings.</li>
                </ul>
            </div>
        </div>

        <!-- Check-in / Check-out -->
        <div class="guide-section">
            <div class="guide-section-header">
                <i class="bi bi-clock"></i>
                <h5 class="mb-0">Check-in &amp; Check-out</h5>
            </div>
            <div class="guide-section-body">
                <div class="row g-3 text-center">
                    <div class="col-6">
                        <div class="info-box">
                            <div class="info-box-label">Check-in</div>
                            <div class="info-box-value">2:00 PM</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="info-box">
                            <div class="info-box-label">Check-out</div>
                            <div class="info-box-value">12:00 PM</div>
                        </div>
                    </div>
                </div>
                <p class="mt-3 text-muted small mb-0">
                    Extensions can be requested through the <strong>Extend</strong> tab in the portal.
                    All extensions are subject to room availability and approval.
                </p>
            </div>
        </div>

        <!-- Services Overview -->
        <div class="guide-section">
            <div class="guide-section-header">
                <i class="bi bi-bell"></i>
                <h5 class="mb-0">Available Services</h5>
            </div>
            <div class="guide-section-body">
                <div class="row g-2">
                    <div class="col-12">
                        <div class="service-row"><i class="bi bi-egg-fried"></i> In-Room Dining</div>
                    </div>
                    <div class="col-12">
                        <div class="service-row"><i class="bi bi-brush"></i> Room Cleaning &amp; Housekeeping</div>
                    </div>
                    <div class="col-12">
                        <div class="service-row"><i class="fa-solid fa-shirt"></i> Laundry &amp; Press</div>
                    </div>
                    <div class="col-12">
                        <div class="service-row"><i class="fa-solid fa-bath"></i> Deluxe Bath &amp; Spa</div>
                    </div>
                    <div class="col-12">
                        <div class="service-row"><i class="bi bi-heart-pulse"></i> Medical / First Aid</div>
                    </div>
                    <div class="col-12">
                        <div class="service-row"><i class="bi bi-cup-straw"></i> Mini-Bar / Snacks</div>
                    </div>
                    <div class="col-12">
                        <div class="service-row"><i class="bi bi-music-note-beamed"></i> Entertainment Services</div>
                    </div>
                    <div class="col-12">
                        <div class="service-row"><i class="bi bi-gift"></i> Special Occasions Setup</div>
                    </div>
                </div>
                <p class="mt-3 text-muted small mb-0">
                    Request any service through the <strong>Services</strong> tab or send us a message via chat.
                </p>
            </div>
        </div>

        <!-- Contact -->
        <div class="guide-section">
            <div class="guide-section-header">
                <i class="bi bi-telephone"></i>
                <h5 class="mb-0">Contact &amp; Assistance</h5>
            </div>
            <div class="guide-section-body">
                <div class="contact-item">
                    <i class="bi bi-telephone-fill"></i>
                    <div>
                        <strong>Front Desk</strong><br>
                        <span class="text-muted">Available 24/7 — dial 0 from your room phone</span>
                    </div>
                </div>
                <div class="contact-item">
                    <i class="bi bi-chat-dots-fill"></i>
                    <div>
                        <strong>Live Chat</strong><br>
                        <span class="text-muted">Tap the chat icon at the bottom-right of your portal</span>
                    </div>
                </div>
                <div class="contact-item">
                    <i class="bi bi-envelope-fill"></i>
                    <div>
                        <strong>Email</strong><br>
                        <span class="text-muted">info@marlehotel.com</span>
                    </div>
                </div>
                <div class="contact-item">
                    <i class="bi bi-geo-alt-fill"></i>
                    <div>
                        <strong>Location</strong><br>
                        <span class="text-muted">Marle Hotel &amp; Restaurant, Philippines</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- The tour is re-runnable: the dashboard reads ?tour=1 as "run again". -->
        <a class="guide-tour-link" href="portal.php?tour=1">
            <span>
                <strong><?= ui_t('New to the portal?', 'Bago ka sa portal?') ?></strong>
                <span><?= ui_t('Walk through the dashboard again in five steps.', 'Balikan ang dashboard sa limang hakbang.') ?></span>
            </span>
            <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </a>

    </div>

    <!-- Chat Widget -->
    

    <!-- Bottom Nav -->
    

    

    

    <!-- Live countdown widget + expiry overlay -->
    

    </main>
<?php include __DIR__ . '/includes/footer.php'; ?>

