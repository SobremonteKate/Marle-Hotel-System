<?php
// ── 6d: Pre-load active hourly booking badges ──────────────
include('../dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page');
// Reservation-deposit rules live in one place so the public modal, the emails
// and the booking status page always quote the same percentage.
require_once __DIR__ . '/../includes/booking_deposit.php';
// English / Tagalog for the public site. site_lang() runs before any output so
// the visitor's choice can be remembered in the marle_lang cookie.
require_once __DIR__ . '/../includes/site_i18n.php';
site_lang();
/** @var mysqli $conn */

// ── Fetch add-on suggestions from amenitiestbl ────────────────────────────────
$amenities = [];
$_aRes = $conn->query("SELECT id, title, description FROM amenitiestbl ORDER BY id ASC");
if ($_aRes) { while ($_aRow = $_aRes->fetch_assoc()) $amenities[] = $_aRow; }

$hourlyBadges = []; // room_type => ['available_at' => string]

$badgeStmt = $conn->prepare(
    "SELECT room_type,
            check_out_time,
            (TIME(NOW()) < check_out_time) AS still_future
     FROM   bookings
     WHERE  booking_type    = 'hourly'
       AND  status          = 'approved'
       AND  hourly_released = 0
       AND  check_in        = CURDATE()
     ORDER BY check_out_time ASC"
);
$badgeStmt->execute();
$badgeRows = $badgeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$badgeStmt->close();

foreach ($badgeRows as $br) {
    $rt = $br['room_type'];
    if (isset($hourlyBadges[$rt])) continue; // keep earliest
    if ((int)$br['still_future'] === 1) {
        $hourlyBadges[$rt] = site_t('Available at', 'Magagamit sa') . ' ' . date('g:i A', strtotime($br['check_out_time']));
    } else {
        $hourlyBadges[$rt] = site_t('Available Now', 'Magagamit Na');
    }
}

// Helper to render badge HTML
function hourlyBadge(array $hourlyBadges, string $roomType): string {
    if (!isset($hourlyBadges[$roomType])) return '';
    $label = htmlspecialchars($hourlyBadges[$roomType]);
    // Compare against the localised "now" label — the badge value is already
    // translated, so the English literal would never match in Tagalog.
    $color  = ($hourlyBadges[$roomType] === site_t('Available Now', 'Magagamit Na')) ? '#198754' : '#e67e00';
    return '<span class="hourly-avail-badge" data-room-type="' . htmlspecialchars($roomType) . '" '
         . 'style="display:inline-block;background:' . $color . ';color:#fff;'
         . 'font-size:0.72rem;font-weight:600;padding:2px 8px;border-radius:20px;'
         . 'margin-top:6px;letter-spacing:.3px;">'
         . '<i class="bi bi-clock me-1"></i>' . $label . '</span>';
}

// Room types, capacities and both rate sets, shared with the help concierge.
require __DIR__ . '/includes/room_tariff.php';


?>
<!DOCTYPE html>
<html lang="<?= site_html_lang() ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="description" content="<?= site_t('Marle Hotel & Restaurant in Sabutan Ibayo, Silang, Cavite — rooms by the night or by the hour, and a kitchen open with the front desk. Since 2015.', 'Marle Hotel & Restaurant sa Sabutan Ibayo, Silang, Cavite — mga kwarto bawat gabi o bawat oras, at kusinang bukas kasama ang front desk. Mula 2015.') ?>">
    <meta name="theme-color" content="#7A2A15">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400..700;1,9..144,400..600&family=IBM+Plex+Mono:wght@400;500&family=Instrument+Sans:ital,wght@0,400..700;1,400&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="site.css?v=20261029c">
    <link rel="preload" as="image" href="pics/MarleHotelFront.webp" type="image/webp" fetchpriority="high">
<?php include __DIR__ . '/includes/site_icons.php'; ?>

    <title><?= site_t('Home | Marle Hotel', 'Tahanan | Marle Hotel') ?></title>

<?php
// The hotel's clock, for every date decision on this page (see
// includes/server_clock.php): they used to be made from the visitor's own device.
require_once __DIR__ . '/../includes/server_clock.php';
marle_server_clock_assets();
?>
</head>

<body class="site">
<?php include __DIR__ . '/includes/entry_screen.php'; ?>
<?php
$navActive = 'home';
include __DIR__ . '/includes/site_header.php';
?>

    <main id="main">
        <section class="hero" id="home" aria-labelledby="heroTitle">
            <div class="hero__grid">
                <div class="hero__panel">
                    <p class="eyebrow hero__eyebrow">Sabutan Ibayo &middot; Silang, Cavite</p>
                    <h1 id="heroTitle">
                        <?= site_t('A HEAVEN OF OPULENCE', 'ISANG PARAISONG MARANGYA') ?>
                        <em><?= site_t('AND TRANQUILITY', 'AT MAPAYAPA') ?></em>
                    </h1>
                    <p class="hero__lead"><?= site_t(
                        'Where timeless elegance meets modern luxury in the heart of Marle Hotel, offering unmatched comfort and exquisite service.',
                        'Kung saan nagsasalubong ang walang kupas na kagandahan at modernong karangyaan sa gitna ng Marle Hotel, handog ang walang katulad na kaginhawahan at pinong serbisyo.'
                    ) ?></p>
                    <div class="hero__actions">
                        <a class="mh-btn mh-btn--mustard" href="#bookingArea"><?= site_t('Check availability', 'Tingnan ang availability') ?></a>
                        <a class="mh-btn mh-btn--ghost" href="tel:09170000000">
                            <i class="bi bi-telephone" aria-hidden="true"></i><?= site_t('Call the front desk', 'Tumawag sa front desk') ?>
                        </a>
                    </div>
                    <dl class="hero__facts">
                        <div>
                            <dt><?= site_t('From', 'Simula') ?></dt>
                            <dd><?= $peso($minHourly) ?> <span>/ 3 <?= site_t('hrs', 'oras') ?></span></dd>
                        </div>
                        <div>
                            <dt><?= site_t('Overnight', 'Magdamag') ?></dt>
                            <dd><?= $peso($minNightly) ?> <span>/ <?= site_t('night', 'gabi') ?></span></dd>
                        </div>
                        <div>
                            <dt><?= site_t('Front desk', 'Front desk') ?></dt>
                            <dd>24 <?= site_t('hrs', 'oras') ?></dd>
                        </div>
                    </dl>
                </div>
                <figure class="hero__media">
                    <div class="hero__photo">
                        <picture>
                            <source srcset="pics/MarleHotelFront.webp" type="image/webp">
                            <img src="pics/MarleHotelFront.jpg" width="2048" height="1383"
                                 alt="<?= site_t('The ochre facade of Marle Hotel & Restaurant in Sabutan Ibayo, Silang', 'Ang dilaw na harapan ng Marle Hotel & Restaurant sa Sabutan Ibayo, Silang') ?>"
                                 fetchpriority="high" decoding="async">
                        </picture>
                    </div>
                    <div class="keytag" aria-hidden="true">
                        <span class="keytag__label"><?= site_t('Est.', 'Mula') ?></span>
                        <span class="keytag__value">2015</span>
                    </div>
                </figure>
            </div>
        </section>

        <div class="desk-wrap">
            <section class="desk" id="bookingArea" aria-labelledby="deskTitle">
                <div class="desk__head">
                    <h2 class="desk__title" id="deskTitle">
                        <?= site_t('Book your stay', 'I-book ang iyong pamamalagi') ?>
                        <small><?= site_t('Live availability from the front desk', 'Live availability mula sa front desk') ?></small>
                    </h2>
                    <div class="seg" role="group" aria-label="<?= site_t('Booking mode', 'Paraan ng pag-book') ?>">
                        <button type="button" id="modeDaily" class="seg__btn is-active" aria-pressed="true"
                                onclick="switchBookingMode('daily')"><?= site_t('By nights', 'Bawat gabi') ?></button>
                        <button type="button" id="modeHourly" class="seg__btn" aria-pressed="false"
                                onclick="switchBookingMode('hourly')"><?= site_t('By hours', 'Bawat oras') ?></button>
                    </div>
                </div>

                <form class="desk__form" id="availabilityForm" onsubmit="return checkAvailability(event)">
                    <div class="field">
                        <label for="bookCheckIn"><?= site_t('Check-in', 'Petsa ng pagpasok') ?></label>
                        <input type="date" class="form-control" id="bookCheckIn" required>
                    </div>
                    <div class="field">
                        <label for="bookCheckOut"><?= site_t('Check-out', 'Petsa ng paglabas') ?></label>
                        <input type="date" class="form-control" id="bookCheckOut" required>
                    </div>
                    <div class="field">
                        <label for="bookGuests"><?= site_t('Guests', 'Bisita') ?></label>
                        <select class="form-select" id="bookGuests">
                            <?php for ($g = 1; $g <= 6; $g++): ?>
                                <option value="<?= $g ?>"<?= $g === 2 ? ' selected' : '' ?>><?= $g ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="bookRoomType"><?= site_t('Room type', 'Uri ng kwarto') ?></label>
                        <select class="form-select" id="bookRoomType">
                            <option value=""><?= site_t('All types', 'Lahat ng uri') ?></option>
                            <option value="Without Garage"><?= site_t('Without Garage', 'Walang Garahe') ?></option>
                            <option value="With Garage"><?= site_t('With Garage', 'May Garahe') ?></option>
                            <option value="Suite"><?= site_t('Suite', 'Suite') ?></option>
                            <option value="Family"><?= site_t('Family', 'Pampamilya') ?></option>
                        </select>
                    </div>
                    <div class="field field--button">
                        <button type="submit" class="mh-btn" id="checkAvailBtn"><?= site_t('Check availability', 'Tingnan ang availability') ?></button>
                    </div>
                </form>

                <form class="desk__form desk__form--hourly" id="hourlyForm" style="display:none;"
                      onsubmit="return populateHourlyRooms(event)">
                    <div class="field">
                        <label for="hrDate"><?= site_t('Date', 'Petsa') ?></label>
                        <input type="date" class="form-control" id="hrDate" required>
                    </div>
                    <div class="field">
                        <label for="hrRoomType"><?= site_t('Room type', 'Uri ng kwarto') ?></label>
                        <select class="form-select" id="hrRoomType" required>
                            <option value=""><?= site_t('Select type', 'Pumili ng uri') ?></option>
                            <option value="Without Garage"><?= site_t('Without Garage', 'Walang Garahe') ?></option>
                            <option value="With Garage"><?= site_t('With Garage', 'May Garahe') ?></option>
                            <option value="Suite">Suite</option>
                            <option value="Family"><?= site_t('Family', 'Pampamilya') ?></option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="hrStartTime"><?= site_t('Start time', 'Oras ng simula') ?></label>
                        <select class="form-select" id="hrStartTime" required></select>
                    </div>
                    <div class="field">
                        <label for="hrDuration"><?= site_t('Duration', 'Tagal') ?></label>
                        <select class="form-select" id="hrDuration" required>
                            <?php for ($h = 3; $h <= 23; $h++): ?>
                                <option value="<?= $h ?>"><?= $h ?> <?= site_t('hrs', 'oras') ?></option>
                            <?php endfor; ?>
                        </select>
                        <span class="field__hint"><?= site_t('Ends at', 'Matatapos') ?> <output id="hrCheckoutDisplay">&mdash;</output></span>
                    </div>
                    <div class="field field--button">
                        <button type="submit" class="mh-btn">
                            <i class="bi bi-clock" aria-hidden="true"></i><?= site_t('Check rooms', 'Tingnan ang kwarto') ?>
                        </button>
                    </div>
                </form>

                <div id="availabilityResults" class="results" style="display:none;" aria-live="polite">
                    <div id="availabilityMsg"></div>
                </div>
                <div id="hrAvailResults" class="results" style="display:none;" aria-live="polite"></div>

                <p class="desk__links">
                    <a href="booking_status.php"><?= site_t('Already booked? Check your booking status', 'Nakapag-book na? Tingnan ang status ng booking') ?></a>
                    <span class="dot" aria-hidden="true">&middot;</span>
                    <button type="button" class="desk__linkbtn" onclick="openCancelBookingModal(); return false;"><?= site_t('Cancel a booking', 'Kanselahin ang booking') ?></button>
                </p>
            </section>
        </div>

    <!-- Booking Form Modal -->
    <div class="modal fade" id="bookingModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius:15px; overflow:hidden;">
                <div class="modal-header" style="background:#933310; color:#fff;">
                    <h5 class="modal-title"><i class="bi bi-calendar-check me-2"></i><?= site_t('Complete Your Booking', 'Kumpletuhin ang Iyong Booking') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small" id="bookingModalInfo"></div>
                    <!--
                        novalidate on purpose: the fields keep their `required` and
                        `pattern` attributes so assistive tech still announces them as
                        required, but the browser must not answer first with its own
                        English bubble. submitBooking() and submit_booking.php own the
                        messages, which is the only way they come out in Tagalog, under
                        the right field.
                    -->
                    <form id="bookingForm" novalidate onsubmit="return submitBooking(event)">
                        <input type="hidden" id="bkRoomType">
                        <input type="hidden" id="bkCheckIn">
                        <input type="hidden" id="bkCheckOut">
                        <input type="hidden" id="bkRoomNumber">

                        <div class="mb-3">
                            <label class="form-label" for="bkGuestName"><?= site_t('Full Name', 'Buong Pangalan') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="bkGuestName" required autocomplete="name" placeholder="e.g. Juan Dela Cruz">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="bkGuestEmail"><?= site_t('Email Address', 'Email Address') ?> <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="bkGuestEmail" required autocomplete="email" placeholder="e.g. juan@email.com">
                            <div class="form-text"><?= site_t("You'll receive booking confirmation here.", 'Dito mo matatanggap ang kumpirmasyon ng iyong booking.') ?></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="bkGuestPhone"><?= site_t('Phone Number', 'Numero ng Telepono') ?></label>
                            <input type="tel" class="form-control" id="bkGuestPhone" inputmode="numeric" autocomplete="tel" maxlength="11" minlength="11" pattern="[0-9]{11}" required placeholder="e.g. 09171234567">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="bkPersons"><?= site_t('Number of Guests', 'Bilang ng Bisita') ?> <span class="text-danger">*</span></label>
                            <select class="form-select" id="bkPersons" required>
                                <option value="1">1</option>
                                <option value="2" selected>2</option>
                                <option value="3">3</option>
                                <option value="4">4</option>
                            <option value="5">5</option>
                                <option value="6">6</option>
                            </select>
                            <div id="bkGuestWarning" class="text-warning small mt-1" style="display:none;">
                                <i class="bi bi-exclamation-triangle me-1"></i><?= site_t('Warning: this room type fits a maximum of', 'Babala: hanggang') ?> <span id="bkGuestWarningMax"></span> <?= site_t('guests.', 'bisita lamang ang kasya sa ganitong uri ng kwarto.') ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="bkSpecialRequests"><?= site_t('Special Requests', 'Espesyal na Kahilingan') ?></label>
                            <textarea class="form-control" id="bkSpecialRequests" rows="2"
                                      placeholder="<?= site_t('Any special requirements or preferences...', 'Anumang espesyal na kahilingan o kagustuhan...') ?>"></textarea>
                        </div>

                        <?php if (!empty($amenities)): ?>
                        <div class="mb-3">
                            <hr style="border-color: rgba(0,0,0,0.1); margin: 0 0 0.75rem;">
                            <label class="form-label text-muted small mb-2">
                                <i class="bi bi-stars me-1"></i><?= site_t('Suggested add-ons', 'Mga iminungkahing dagdag') ?> <span class="fw-normal"><?= site_t('(optional)', '(opsyonal)') ?></span>
                            </label>
                            <div class="row g-2" id="bkAddonGrid">
                                <?php foreach ($amenities as $_a): ?>
                                <div class="col-6">
                                    <div class="addon-card rounded-3 p-2 h-100"
                                         role="button" tabindex="0" aria-pressed="false"
                                         data-addon-id="<?= (int)$_a['id'] ?>"
                                         data-addon="<?= htmlspecialchars($_a['title'], ENT_QUOTES) ?>"
                                         onclick="toggleAddonCard(this, 'bkAddonGrid')">
                                        <div class="fw-500" style="font-size:13px;">
                                            <?= htmlspecialchars($_a['title']) ?>
                                        </div>
                                        <div class="text-muted" style="font-size:11px; line-height:1.3; margin-top:2px;">
                                            <?= htmlspecialchars(mb_strimwidth($_a['description'], 0, 60, '...')) ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div id="bookingFormError" class="booking-summary" role="alert" style="display:none;"></div>

                        <div class="p-3 mb-3 rounded-3" style="background:#fff8e7; border:1px solid #f0c36d;">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="bkTermsAccepted" required>
                            <label class="form-check-label small" for="bkTermsAccepted">
                                <?= site_t('I agree to the booking policy: a', 'Sumasang-ayon ako sa patakaran ng pag-book: kailangan ang') ?> <strong><?= (int)marle_deposit_rate($conn) ?>% <?= site_t('reservation deposit', 'reserbasyong deposito') ?></strong> <?= site_t('is required to confirm my room, paid by GCash or bank transfer and uploaded on my booking status page for verification.', 'upang kumpirmahin ang aking kwarto. Babayaran ito sa GCash o bank transfer at ia-upload sa aking booking status page para sa beripikasyon.') ?>
                                <?= site_t('If I cancel,', 'Kung magkansela ako,') ?> <strong><?= (int)MARLE_CANCELLATION_FORFEIT_RATE ?>% <?= site_t('of the total booking amount is non-refundable', 'ng kabuuang halaga ng booking ay hindi na maibabalik') ?></strong>. <?= site_t('A booking that has neither checked in nor called by 4:00 PM on the arrival day is released automatically as a no-show — the front desk is staffed 24 hours, so call if you are running late.', 'Ang booking na hindi pa nag-check in at hindi tumawag bago mag-4:00 PM sa araw ng pagdating ay awtomatikong ibinabalik bilang no-show — 24 oras na may tao sa front desk, kaya tumawag kung mahuhuli ka.') ?>
                                <a class="policy-link" href="#policies"><?= site_t('Read the deposit and cancellation policy', 'Basahin ang patakaran sa deposito at pagkansela') ?></a>
                            </label>
                        </div>
                        </div>

                        <button type="submit" class="btn w-100" id="submitBookingBtn"
                                style="background:#933310; color:#fff; border-radius:10px; padding:10px;">
                            <i class="bi bi-send me-1"></i><?= site_t('Submit Booking Request', 'Isumite ang Booking Request') ?>
                        </button>
                    </form>

                    <!-- Success message (hidden by default) -->
                    <div id="bookingSuccess" style="display:none; text-align:center; padding:20px 0;">
                        <i class="bi bi-check-circle" style="font-size:3rem; color:#198754;"></i>
                        <h5 class="mt-3"><?= site_t('Booking Request Submitted!', 'Naisumite na ang Booking Request!') ?></h5>
                        <p class="text-muted" id="bookingSuccessMsg"></p>
                        <div class="text-start bg-light border rounded-3 p-3 mb-3">
                            <div class="small text-muted"><?= site_t('Private status token', 'Pribadong status token') ?></div>
                            <code id="bookingSuccessToken" class="d-block user-select-all text-break"></code>
                            <a id="bookingStatusLink" class="btn btn-outline-primary btn-sm mt-2" href="booking_status.php"><?= site_t('Open booking status', 'Buksan ang booking status') ?></a>
                        </div>
                        <a href="booking_status.php" class="btn btn-outline-secondary btn-sm mb-3">
                            <i class="bi bi-search me-1"></i><?= site_t('Check Booking Status', 'Tingnan ang Booking Status') ?>
                        </a><br>
                        <button class="btn" style="background:#933310; color:#fff;" data-bs-dismiss="modal"><?= site_t('Close', 'Isara') ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hourly Booking Details Modal -->
    <div class="modal fade" id="hourlyModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius:15px; overflow:hidden;">
                <div class="modal-header" style="background:#933310; color:#fff;">
                    <h5 class="modal-title"><i class="bi bi-clock me-2"></i><?= site_t('Hourly Booking Details', 'Detalye ng Booking Bawat Oras') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small" id="hrModalInfo"></div>
                    <form id="hourlyDetailsForm" novalidate onsubmit="return submitHourlyDetails(event)">
<input type="hidden" id="hrBkRoomType">
                        <input type="hidden" id="hrBkRoomNumber">
                        <input type="hidden" id="hrBkDate">
                        <input type="hidden" id="hrBkCheckInTime">
                        <input type="hidden" id="hrBkCheckOutTime">
                        <input type="hidden" id="hrBkHours">

                        <div class="mb-3">
                            <label class="form-label" for="hrBkGuestName"><?= site_t('Full Name', 'Buong Pangalan') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="hrBkGuestName" required autocomplete="name" placeholder="e.g. Juan Dela Cruz">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="hrBkGuestEmail"><?= site_t('Email Address', 'Email Address') ?> <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="hrBkGuestEmail" required autocomplete="email" placeholder="e.g. juan@email.com">
                            <div class="form-text"><?= site_t("You'll receive booking confirmation here.", 'Dito mo matatanggap ang kumpirmasyon ng iyong booking.') ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="hrBkGuestPhone"><?= site_t('Phone Number', 'Numero ng Telepono') ?></label>
                            <input type="tel" class="form-control" id="hrBkGuestPhone" inputmode="numeric" autocomplete="tel" maxlength="11" minlength="11" pattern="[0-9]{11}" required placeholder="e.g. 09171234567">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="hrBkPersons"><?= site_t('Number of Guests', 'Bilang ng Bisita') ?> <span class="text-danger">*</span></label>
                            <select class="form-select" id="hrBkPersons" required>
                                <option value="1">1</option>
                                <option value="2" selected>2</option>
                                <option value="3">3</option>
                                <option value="4">4</option>
                                <option value="5">5</option>
                            </select>
                            <div id="hrBkGuestWarning" class="text-warning small mt-1" style="display:none;">
                                <i class="bi bi-exclamation-triangle me-1"></i><?= site_t('Warning: this room type fits a maximum of', 'Babala: hanggang') ?> <span id="hrBkGuestWarningMax"></span> <?= site_t('guests.', 'bisita lamang ang kasya sa ganitong uri ng kwarto.') ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="hrBkSpecialRequests"><?= site_t('Special Requests', 'Espesyal na Kahilingan') ?></label>
                            <textarea class="form-control" id="hrBkSpecialRequests" rows="2"
                                      placeholder="<?= site_t('Any special requirements...', 'Anumang espesyal na kahilingan...') ?>"></textarea>
                        </div>

                        <?php if (!empty($amenities)): ?>
                        <div class="mb-3">
                            <hr style="border-color: rgba(0,0,0,0.1); margin: 0 0 0.75rem;">
                            <label class="form-label text-muted small mb-2">
                                <i class="bi bi-stars me-1"></i><?= site_t('Suggested add-ons', 'Mga iminungkahing dagdag') ?> <span class="fw-normal"><?= site_t('(optional)', '(opsyonal)') ?></span>
                            </label>
                            <div class="row g-2" id="hrBkAddonGrid">
                                <?php foreach ($amenities as $_a): ?>
                                <div class="col-6">
                                    <div class="addon-card rounded-3 p-2 h-100"
                                         role="button" tabindex="0" aria-pressed="false"
                                         data-addon-id="<?= (int)$_a['id'] ?>"
                                         data-addon="<?= htmlspecialchars($_a['title'], ENT_QUOTES) ?>"
                                         onclick="toggleAddonCard(this, 'hrBkAddonGrid')">
                                        <div class="fw-500" style="font-size:13px;">
                                            <?= htmlspecialchars($_a['title']) ?>
                                        </div>
                                        <div class="text-muted" style="font-size:11px; line-height:1.3; margin-top:2px;">
                                            <?= htmlspecialchars(mb_strimwidth($_a['description'], 0, 60, '...')) ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div id="hrBookingFormError" class="booking-summary" role="alert" style="display:none;"></div>

                        <div class="p-3 mb-3 rounded-3" style="background:#fff8e7; border:1px solid #f0c36d;">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="hrBkTermsAccepted" required>
                            <label class="form-check-label small" for="hrBkTermsAccepted">
                                <?= site_t('I agree to the booking policy: a', 'Sumasang-ayon ako sa patakaran ng pag-book: kailangan ang') ?> <strong><?= (int)marle_deposit_rate($conn) ?>% <?= site_t('reservation deposit', 'reserbasyong deposito') ?></strong> <?= site_t('is required to confirm my room, paid by GCash or bank transfer and uploaded on my booking status page for verification.', 'upang kumpirmahin ang aking kwarto. Babayaran ito sa GCash o bank transfer at ia-upload sa aking booking status page para sa beripikasyon.') ?>
                                <?= site_t('If I cancel,', 'Kung magkansela ako,') ?> <strong><?= (int)MARLE_CANCELLATION_FORFEIT_RATE ?>% <?= site_t('of the total booking amount is non-refundable', 'ng kabuuang halaga ng booking ay hindi na maibabalik') ?></strong>. <?= site_t('A booking that has neither checked in nor called by 4:00 PM on the arrival day is released automatically as a no-show — the front desk is staffed 24 hours, so call if you are running late.', 'Ang booking na hindi pa nag-check in at hindi tumawag bago mag-4:00 PM sa araw ng pagdating ay awtomatikong ibinabalik bilang no-show — 24 oras na may tao sa front desk, kaya tumawag kung mahuhuli ka.') ?>
                                <a class="policy-link" href="#policies"><?= site_t('Read the deposit and cancellation policy', 'Basahin ang patakaran sa deposito at pagkansela') ?></a>
                            </label>
                        </div>
                        </div>

                        <button type="submit" class="btn w-100" id="submitHourlyBtn"
                                style="background:#933310; color:#fff; border-radius:10px; padding:10px;">
                            <i class="bi bi-send me-1"></i><?= site_t('Submit Hourly Booking', 'Isumite ang Booking Bawat Oras') ?>
                        </button>
                    </form>

                    <div id="hrBookingSuccess" style="display:none; text-align:center; padding:20px 0;">
                        <i class="bi bi-check-circle" style="font-size:3rem; color:#198754;"></i>
                        <h5 class="mt-3"><?= site_t('Hourly Booking Submitted!', 'Naisumite na ang Booking Bawat Oras!') ?></h5>
                        <p class="text-muted" id="hrBookingSuccessMsg"></p>
                        <div class="text-start bg-light border rounded-3 p-3 mb-3">
                            <div class="small text-muted"><?= site_t('Private status token', 'Pribadong status token') ?></div>
                            <code id="hrBookingSuccessToken" class="d-block user-select-all text-break"></code>
                            <a id="hrBookingStatusLink" class="btn btn-outline-primary btn-sm mt-2" href="booking_status.php"><?= site_t('Open booking status', 'Buksan ang booking status') ?></a>
                        </div>
                        <a href="booking_status.php" class="btn btn-outline-secondary btn-sm mb-3">
                            <i class="bi bi-search me-1"></i><?= site_t('Check Booking Status', 'Tingnan ang Booking Status') ?>
                        </a><br>
                        <button class="btn" style="background:#933310; color:#fff;" data-bs-dismiss="modal"><?= site_t('Close', 'Isara') ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <section class="section" id="rooms" aria-labelledby="roomsTitle">
        <div class="shell">
            <div class="section-head" data-reveal>
                <div>
                    <p class="eyebrow"><?= site_t('Rooms', 'Mga Kwarto') ?> &middot; <?= (int)$totalRooms ?> <?= site_t('rooms', 'kwarto') ?></p>
                    <h2 class="section-title" id="roomsTitle"><?= site_t('Four ways to stay', 'Apat na paraan para mag-stay') ?></h2>
                </div>
                <p class="section-lead"><?= site_t(
                    'Rates are the booking desk’s own — a three-hour base or a full night. Tap a room to see it, then send the dates to the front desk.',
                    'Ang mga presyo ay mula mismo sa booking desk — tatlong oras o buong gabi. Pindutin ang kwarto para makita ito, pagkatapos ipadala ang mga petsa sa front desk.'
                ) ?></p>
            </div>

            <?php if ($firstCard): ?>
            <div class="folio" data-folio>
                <figure class="folio__figure">
                    <div class="folio__media">
                        <img data-folio-img src="<?= htmlspecialchars($firstCard['image'], ENT_QUOTES) ?>"
                             width="1280" height="720"
                             alt="<?= htmlspecialchars($firstCard['alt'], ENT_QUOTES) ?>"
                             loading="lazy" decoding="async">
                        <div class="keytag folio__keytag" aria-hidden="true">
                            <span class="keytag__label"><?= site_t('Room', 'Kwarto') ?></span>
                            <span class="keytag__value" data-folio-room><?= htmlspecialchars($firstCard['room']) ?></span>
                        </div>
                    </div>
                    <figcaption class="folio__caption">
                        <span class="folio__caption-name" data-folio-name><?= htmlspecialchars($firstCard['label']) ?></span>
                        <span class="folio__caption-meta" data-folio-meta><?= htmlspecialchars($firstCard['meta']) ?></span>
                    </figcaption>
                    <button type="button" class="mh-btn mh-btn--outline folio__action" data-folio-book>
                        <?= site_t('Check this room', 'Tingnan ang kwartong ito') ?>
                        <i class="bi bi-arrow-down-right" aria-hidden="true"></i>
                    </button>
                </figure>

                <div class="folio__list" role="group" aria-label="<?= site_t('Room types and rates', 'Mga uri ng kwarto at presyo') ?>">
                    <?php foreach ($roomOrder as $index => $type): $card = $roomCards[$type]; ?>
                    <button type="button" class="folio-row" data-folio-row
                            aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"
                            data-room-type="<?= htmlspecialchars($type, ENT_QUOTES) ?>"
                            data-src="<?= htmlspecialchars($card['image'], ENT_QUOTES) ?>"
                            data-alt="<?= htmlspecialchars($card['alt'], ENT_QUOTES) ?>"
                            data-name="<?= htmlspecialchars($card['label'], ENT_QUOTES) ?>"
                            data-meta="<?= htmlspecialchars($card['meta'], ENT_QUOTES) ?>"
                            data-room="<?= htmlspecialchars($card['room'], ENT_QUOTES) ?>">
                        <span class="folio-row__head">
                            <span class="folio-row__name"><?= htmlspecialchars($card['label']) ?></span>
                            <span class="folio-row__dots" aria-hidden="true"></span>
                            <span class="folio-row__rates">
                                <span class="folio-row__rate">
                                    <b><?= $peso($card['three']) ?></b>
                                    <span>3 <?= site_t('hrs', 'oras') ?></span>
                                </span>
                                <?php if ($card['extension'] > 0): ?>
                                <span class="folio-row__rate">
                                    <b><?= $peso($card['extension']) ?></b>
                                    <span><?= site_t('per extra hr', 'bawat dagdag na oras') ?></span>
                                </span>
                                <?php endif; ?>
                                <span class="folio-row__rate">
                                    <b><?= $peso($card['nightly']) ?></b>
                                    <span><?= site_t('night', 'gabi') ?></span>
                                </span>
                            </span>
                        </span>
                        <span class="folio-row__meta">
                            <span class="folio-chip folio-chip--sleeps"><?= htmlspecialchars($card['sleeps_label']) ?></span>
                            <span><?= htmlspecialchars($card['count_label']) ?></span>
                            <?php if ($card['count'] > 0): ?>
                                <?= hourlyBadge($hourlyBadges, $type) ?>
                            <?php endif; ?>
                        </span>
                        <span class="folio-row__desc"><?= htmlspecialchars($card['blurb']) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <?php include __DIR__ . '/includes/booking_policies.php'; ?>

    <section class="section" id="story" aria-labelledby="storyTitle">
        <div class="shell">
            <div class="story">
                <figure class="story__media" data-reveal>
                    <picture>
                        <source srcset="pics/MarleHotelFront.webp" type="image/webp">
                        <img src="pics/MarleHotelFront.jpg" width="2048" height="1383"
                             alt="<?= site_t('The front of Marle Hotel & Restaurant', 'Ang harapan ng Marle Hotel & Restaurant') ?>"
                             loading="lazy" decoding="async">
                    </picture>
                </figure>
                <div class="story__copy" data-reveal>
                    <p class="eyebrow"><?= site_t('Our story', 'Ang aming kwento') ?></p>
                    <h2 id="storyTitle"><?= site_t('Hospitality that keeps people coming back.', 'Pagka-magiliw na pinapabalik ang mga bisita.') ?></h2>
                    <p><?= site_t(
                        'Since opening in 2015, Marle Hotel has been proud to serve the community. We are committed to quality service, thoughtful stays, and going above and beyond for every guest.',
                        'Mula nang magbukas noong 2015, ipinagmamalaki ng Marle Hotel ang paglilingkod sa komunidad. Nakatuon kami sa de-kalidad na serbisyo, maalagang pamamalagi, at paglampas sa inaasahan ng bawat bisita.'
                    ) ?></p>
                    <dl class="facts">
                        <div>
                            <dt><?= site_t('Since', 'Mula') ?></dt>
                            <dd>2015</dd>
                        </div>
                        <div>
                            <dt><?= site_t('Where', 'Saan') ?></dt>
                            <dd>Silang, Cavite</dd>
                        </div>
                        <div>
                            <dt><?= site_t('Rooms', 'Kwarto') ?></dt>
                            <dd><?= (int)$totalRooms ?></dd>
                        </div>
                    </dl>
                    <a class="text-link" href="about.php"><?= site_t('Discover our background', 'Alamin ang aming pinagmulan') ?> <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                </div>
            </div>
        </div>
    </section>

    <section class="dining" id="dining" aria-labelledby="diningTitle">
        <div class="shell">
            <div class="dining__grid">
                <div class="dining__images" data-reveal>
                    <figure>
                        <img src="pics/cake.jpg" width="825" height="1242"
                             alt="<?= site_t('A slice of cake from the Marle Hotel kitchen', 'Isang slice ng cake mula sa kusina ng Marle Hotel') ?>"
                             loading="lazy" decoding="async">
                    </figure>
                    <figure>
                        <img src="pics/table.jpg" width="1098" height="598"
                             alt="<?= site_t('A table set in the Marle Hotel restaurant', 'Hapag na inihanda sa restaurant ng Marle Hotel') ?>"
                             loading="lazy" decoding="async">
                    </figure>
                </div>
                <div data-reveal>
                    <p class="eyebrow"><?= site_t('Dining', 'Kainan') ?></p>
                    <h2 id="diningTitle"><?= site_t('Savor the flavors of Marle Hotel', 'Lasapin ang mga sarap ng Marle Hotel') ?></h2>
                    <p class="dining__lead"><?= site_t(
                        'Flavorful dishes, warm hospitality, and the convenience of eating where you are staying — familiar local favorites alongside meals made for every kind of stay.',
                        'Malalasang putahe, mainit na pagtanggap, at kaginhawahan ng pagkain sa kinaroroonan mo — pamilyar na paboritong pagkain kasama ang mga pagkaing mainam para sa anumang uri ng pamamalagi.'
                    ) ?></p>
                    <p class="dining__note">
                        <i class="bi bi-door-open" aria-hidden="true"></i>
                        <?= site_t('Open to everyone — hotel guests and walk-ins alike. Room service for guests.', 'Bukas sa lahat — bisita ng hotel at walk-in. May room service para sa mga naka-check in.') ?>
                    </p>
                    <div class="dining__list">
                        <div class="dining__item">
                            <i class="bi bi-egg-fried" aria-hidden="true"></i>
                            <div>
                                <h3><?= site_t('The Marle Hotel Restaurant', 'Ang Restaurant ng Marle Hotel') ?></h3>
                                <p><?= site_t('Well-loved Filipino favorites prepared with care. Room service is available when you would rather dine in.', 'Mga paboritong pagkaing Pilipino na inihanda nang may pagmamalasakit. May room service kung mas gusto mong kumain sa kwarto.') ?></p>
                            </div>
                        </div>
                        <div class="dining__item">
                            <i class="bi bi-cup-hot" aria-hidden="true"></i>
                            <div>
                                <h3><?= site_t('The Terrace Lounge', 'Ang Terrace Lounge') ?></h3>
                                <p><?= site_t('A relaxed table for coffee, good food, and a quiet hour before the drive home.', 'Relaks na hapag para sa kape, masarap na pagkain, at tahimik na oras bago umuwi.') ?></p>
                            </div>
                        </div>
                        <div class="dining__item">
                            <i class="bi bi-stars" aria-hidden="true"></i>
                            <div>
                                <h3><?= site_t('Private dining', 'Pribadong kainan') ?></h3>
                                <p><?= site_t('Birthdays, reunions, and small celebrations, set around your occasion.', 'Mga kaarawan, pagsasama-sama, at maliliit na selebrasyon, inihanda ayon sa iyong okasyon.') ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="dining__actions">
                        <a class="mh-btn" href="#diningMenu"><?= site_t('See the menu', 'Tingnan ang menu') ?> <i class="bi bi-arrow-down" aria-hidden="true"></i></a>
                        <a class="text-link dining__reserve" href="contact.php"><?= site_t('Reserve a table', 'Magreserba ng mesa') ?> <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include __DIR__ . '/includes/dining_menu.php'; ?>

    <section class="section" id="amenities" aria-labelledby="amenitiesTitle">
        <div class="shell">
            <div class="section-head" data-reveal>
                <div>
                    <p class="eyebrow"><?= site_t('Amenities', 'Mga Amenidad') ?></p>
                    <h2 class="section-title" id="amenitiesTitle"><?= site_t('What every stay includes', 'Kasama sa bawat pamamalagi') ?></h2>
                </div>
                <p class="section-lead"><?= site_t(
                    'At Marle Hotel, the essentials are looked after so the stay can be simple: cool rooms, hot water where it matters, and a front desk that answers.',
                    'Sa Marle Hotel, inaasikaso ang mga mahalaga upang maging simple ang pamamalagi: malamig na kwarto, mainit na tubig kung kailangan, at front desk na sumasagot.'
                ) ?></p>
            </div>
            <div class="amenities__grid" data-reveal>
                <div class="amenity">
                    <i class="bi bi-wifi" aria-hidden="true"></i>
                    <div>
                        <h3><?= site_t('Free Wi-Fi', 'Libreng Wi-Fi') ?></h3>
                        <p><?= site_t('Complimentary wireless internet in all rooms and common areas.', 'Libreng wireless internet sa lahat ng kwarto at karaniwang lugar.') ?></p>
                    </div>
                </div>
                <div class="amenity">
                    <i class="bi bi-snow2" aria-hidden="true"></i>
                    <div>
                        <h3><?= site_t('Fully air-conditioned', 'Ganap na may aircon') ?></h3>
                        <p><?= site_t('Every room and common area is air-conditioned, all day long.', 'Lahat ng kwarto at karaniwang lugar ay may aircon, buong maghapon.') ?></p>
                    </div>
                </div>
                <div class="amenity">
                    <i class="bi bi-droplet-half" aria-hidden="true"></i>
                    <div>
                        <h3><?= site_t('Shower with heater', 'Shower na may heater') ?> <span><?= site_t('(selected rooms)', '(piling kwarto)') ?></span></h3>
                        <p><?= site_t('Hot water heaters in selected room types.', 'May water heater sa ilang uri ng kwarto.') ?></p>
                    </div>
                </div>
                <div class="amenity">
                    <i class="bi bi-tv" aria-hidden="true"></i>
                    <div>
                        <h3><?= site_t('Smart TV with cable', 'Smart TV na may cable') ?> <span><?= site_t('(selected rooms)', '(piling kwarto)') ?></span></h3>
                        <p><?= site_t('A wide channel selection in selected rooms.', 'Maraming channel sa piling kwarto.') ?></p>
                    </div>
                </div>
                <div class="amenity">
                    <i class="bi bi-water" aria-hidden="true"></i>
                    <div>
                        <h3><?= site_t('Bathtub', 'Bathtub') ?> <span><?= site_t('(selected rooms)', '(piling kwarto)') ?></span></h3>
                        <p><?= site_t('A private soak in selected upgraded rooms.', 'Pribadong paglublob sa piling upgraded na kwarto.') ?></p>
                    </div>
                </div>
                <div class="amenity">
                    <i class="bi bi-percent" aria-hidden="true"></i>
                    <div>
                        <h3><?= site_t('Discounts for regulars, PWD & seniors', 'Diskwento para sa regular, PWD at senior') ?></h3>
                        <p><?= site_t('Special rates for regular guests, persons with disability, and senior citizens.', 'Espesyal na presyo para sa regular na bisita, persons with disability, at senior citizens.') ?></p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="celebrate" aria-labelledby="celebrateTitle">
        <div class="shell">
            <div class="celebrate__inner">
                <div>
                    <h2 id="celebrateTitle"><?= site_t('Celebrations, reunions, long weekends.', 'Mga selebrasyon, pagsasama-sama, mahabang weekend.') ?></h2>
                    <p><?= site_t(
                        'Tell us the occasion and we will set the table. Celebrations are arranged with the front desk — nothing is sold off a shelf.',
                        'Sabihin ang okasyon at kami ang maghahanda ng hapag. Ang mga selebrasyon ay inaayos kasama ang front desk — walang binibentang nakabalot na package.'
                    ) ?></p>
                </div>
                <a class="mh-btn mh-btn--mustard" href="contact.php"><?= site_t('Plan it with us', 'Plano kasama kami') ?> <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
            </div>
        </div>
    </section>

    <section class="quotes section" aria-labelledby="quotesTitle">
        <div class="shell">
            <div class="section-head" data-reveal>
                <div>
                    <p class="eyebrow"><?= site_t('Guest notes', 'Mga tala ng bisita') ?></p>
                    <h2 class="section-title" id="quotesTitle"><?= site_t('What guests remember', 'Ang naaalala ng mga bisita') ?></h2>
                </div>
                <p class="section-lead"><?= site_t('A few words from people who have stayed with us.', 'Ilang salita mula sa mga bisitang nakapag-stay sa amin.') ?></p>
            </div>
            <div class="quotes__grid" data-reveal>
                <figure class="quote">
                    <span class="quote__mark" aria-hidden="true">&ldquo;</span>
                    <blockquote><p>An unforgettable stay! The staff went above and beyond.</p></blockquote>
                    <figcaption class="quote__by">
                        <span class="quote__initials" aria-hidden="true">SM</span>
                        <div>
                            <div class="quote__name">Sarah M.</div>
                            <div class="quote__stars" aria-label="<?= site_t('5 out of 5 stars', '5 sa 5 bituin') ?>">
                                <i class="bi bi-star-fill" aria-hidden="true"></i><i class="bi bi-star-fill" aria-hidden="true"></i><i class="bi bi-star-fill" aria-hidden="true"></i><i class="bi bi-star-fill" aria-hidden="true"></i><i class="bi bi-star-fill" aria-hidden="true"></i>
                            </div>
                        </div>
                    </figcaption>
                </figure>
                <figure class="quote">
                    <span class="quote__mark" aria-hidden="true">&ldquo;</span>
                    <blockquote><p>Beautiful rooms, perfect location, and amazing breakfast.</p></blockquote>
                    <figcaption class="quote__by">
                        <span class="quote__initials" aria-hidden="true">DR</span>
                        <div>
                            <div class="quote__name">Daniel R.</div>
                            <div class="quote__stars" aria-label="<?= site_t('5 out of 5 stars', '5 sa 5 bituin') ?>">
                                <i class="bi bi-star-fill" aria-hidden="true"></i><i class="bi bi-star-fill" aria-hidden="true"></i><i class="bi bi-star-fill" aria-hidden="true"></i><i class="bi bi-star-fill" aria-hidden="true"></i><i class="bi bi-star-fill" aria-hidden="true"></i>
                            </div>
                        </div>
                    </figcaption>
                </figure>
                <figure class="quote">
                    <span class="quote__mark" aria-hidden="true">&ldquo;</span>
                    <blockquote><p>Luxury and comfort combined. We&rsquo;ll definitely return.</p></blockquote>
                    <figcaption class="quote__by">
                        <span class="quote__initials" aria-hidden="true">PK</span>
                        <div>
                            <div class="quote__name">Priya K.</div>
                            <div class="quote__stars" aria-label="<?= site_t('5 out of 5 stars', '5 sa 5 bituin') ?>">
                                <i class="bi bi-star-fill" aria-hidden="true"></i><i class="bi bi-star-fill" aria-hidden="true"></i><i class="bi bi-star-fill" aria-hidden="true"></i><i class="bi bi-star-fill" aria-hidden="true"></i><i class="bi bi-star-fill" aria-hidden="true"></i>
                            </div>
                        </div>
                    </figcaption>
                </figure>
            </div>
        </div>
    </section>

    <section class="section" id="contact" aria-labelledby="findusTitle">
        <div class="shell">
            <div class="findus__grid">
                <div data-reveal>
                    <p class="eyebrow"><?= site_t('Get in touch', 'Makipag-ugnayan') ?></p>
                    <h2 class="section-title" id="findusTitle"><?= site_t('Find your way to Marle.', 'Hanapin ang daan papuntang Marle.') ?></h2>
                    <div class="contact-list">
                        <a href="tel:09170000000">
                            <i class="bi bi-telephone" aria-hidden="true"></i>
                            <span><small><?= site_t('Front desk', 'Front desk') ?></small>0917 000 0000</span>
                        </a>
                        <a href="mailto:frontdesk@marlehotel.example">
                            <i class="bi bi-envelope" aria-hidden="true"></i>
                            <span><small>Email</small>frontdesk@marlehotel.example</span>
                        </a>
                        <span class="contact-row">
                            <i class="bi bi-geo-alt" aria-hidden="true"></i>
                            <span><small><?= site_t('Address', 'Tirahan') ?></small>Sabutan Ibayo, Silang, Cavite</span>
                        </span>
                        <a href="https://www.facebook.com/MARLE-Hotel-Restaurant-264513383682017/" target="_blank" rel="noopener">
                            <i class="bi bi-facebook" aria-hidden="true"></i>
                            <span><small>Facebook</small><?= site_t('Marle Hotel & Restaurant', 'Marle Hotel & Restaurant') ?></span>
                        </a>
                    </div>
                    <a class="text-link" href="contact.php"><?= site_t('View contact details', 'Tingnan ang mga detalye ng kontak') ?> <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                </div>
                <div class="findus__map" data-reveal>
                    <iframe src="https://www.google.com/maps?q=Sabutan%20Ibayo%2C%20Silang%2C%20Cavite&amp;output=embed"
                            title="<?= site_t('Map showing Marle Hotel in Sabutan Ibayo, Silang, Cavite', 'Mapa na nagpapakita ng Marle Hotel sa Sabutan Ibayo, Silang, Cavite') ?>"
                            loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
            </div>
        </div>
    </section>
    </main>

<?php include __DIR__ . '/includes/site_footer.php'; ?>

<div class="book-bar" data-book-bar hidden>
    <a class="mh-btn" href="#bookingArea"><?= site_t('Check availability', 'Tingnan ang availability') ?></a>
    <a class="book-bar__call" href="tel:09170000000" aria-label="<?= site_t('Call the front desk', 'Tumawag sa front desk') ?>">
        <i class="bi bi-telephone" aria-hidden="true"></i>
    </a>
</div>

<?php include __DIR__ . '/includes/site_help.php'; ?>

<?php include __DIR__ . '/includes/room_viewer.php'; ?>

<!-- The policy reader.

     "Read the deposit and cancellation policy" used to close the booking form and
     scroll the page to the policy block, which threw away everything the guest had
     already typed. The policy now opens over the form: read it, close it, and the
     half-filled booking is exactly where it was.

     It is a native <dialog>, like the room viewer, and not a second Bootstrap
     modal. Bootstrap 5 traps focus by listening on the whole document and pulling
     it back into the open modal, so a second Bootstrap modal stacked on top of the
     booking form had its focus stolen on every keystroke — the trap will not let
     go. A modal <dialog> lives in the browser's top layer and makes the rest of
     the page inert, so the trap's pull has nowhere to land.

     The body is filled by site-nav.js from the real #policies section on first
     open, so there is still one copy of these words in the page (and still one
     source — includes/booking_policies.php — for the numbers in them). The
     fallback link below is what a guest sees if the script never runs. -->
<dialog class="policyreader" id="policyDialog" aria-labelledby="policyDialogTitle">
    <div class="policyreader__sheet">
        <header class="policyreader__bar">
            <div>
                <p class="policyreader__eyebrow"><?= site_t('Before you book', 'Bago ka mag-book') ?></p>
                <h2 class="policyreader__title" id="policyDialogTitle"><?= site_t('Deposit, payment and cancellation', 'Deposito, bayad at pagkansela') ?></h2>
            </div>
            <button type="button" class="policyreader__close" data-policy-close
                    aria-label="<?= site_t('Close the policy', 'Isara ang patakaran') ?>">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>
        <div class="policyreader__body" data-policy-body>
            <p class="policyreader__lead">
                <a href="#policies"><?= site_t('Read the policy below the rooms', 'Basahin ang patakaran sa ibaba ng mga kwarto') ?></a>
            </p>
        </div>
        <footer class="policyreader__foot">
            <span class="policyreader__foot-note"><?= site_t('Your booking form is still open behind this.', 'Nakabukas pa rin ang form mo sa likod nito.') ?></span>
            <button type="button" class="mh-btn mh-btn--mustard" data-policy-close>
                <?= site_t('Back to the form', 'Bumalik sa form') ?>
            </button>
        </footer>
    </div>
</dialog>

<?php
// The tour's words are written here, beside the page they describe, so every
// number in it is the same variable the folio and the hero already use.
$marleTour = [
    [
        'sel'   => '#heroTitle',
        'title' => site_t('Welcome to Marle', 'Maligayang pagdating sa Marle'),
        'text'  => sprintf(
            site_t('%d rooms and a kitchen in Silang, Cavite — open since 2015. A three-hour stay starts at %s; a night at %s.', '%d kwarto at kusina sa Silang, Cavite — bukas mula 2015. Ang tatlong oras ay nagsisimula sa %s; ang isang gabi sa %s.'),
            (int)$totalRooms,
            $peso($minHourly),
            $peso($minNightly)
        ),
    ],
    [
        'sel'   => '.folio-row',
        'title' => site_t('Four ways to stay', 'Apat na paraan ng pag-stay'),
        'text'  => site_t('Without Garage, With Garage, Suite and Family. Tap a row: the photograph, the sleeping capacity and both rates change with it.', 'Walang Garahe, May Garahe, Suite at Pampamilya. Pindutin ang isang hilera: magbabago ang larawan, kapasidad at dalawang presyo.'),
    ],
    [
        'sel'   => '#bookingArea',
        'title' => site_t('Check the dates that matter', 'Tingnan ang tamang petsa'),
        'text'  => site_t('Pick your dates here — or switch to By hours — then Check availability. The desk answers with the rooms that are actually free.', 'Pumili ng petsa — o lumipat sa Bawat oras — pagkatapos ay Check availability. Ang sagot ay ang mga kwartong talagang bakante.'),
    ],
    [
        'sel'   => '.desk__links',
        'title' => site_t('Already booked?', 'Nakapag-book na?'),
        'text'  => site_t('Your email and private status token open the booking: the total, the deposit receipt and cancellation.', 'Ang email at pribadong status token ang nagbubukas ng booking: kabuuang bayad, resibo ng deposito at pagkansela.'),
    ],
    [
        'sel'   => '[data-help-launcher]',
        'title' => site_t('Anything else', 'May iba pa?'),
        'text'  => site_t('Deposits, parking, check-in — the Help button answers with this site’s own rates and policies. It is there on every page.', 'Deposito, paradahan, check-in — sinasagot ng Help button gamit ang sariling presyo at patakaran ng site. Nasa bawat pahina ito.'),
    ],
];
?>
<script>
    window.MARLE_TOUR = <?= json_encode($marleTour, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>

<script src="site-nav.js?v=20261029b"></script>
<script src="site-guide.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
    crossorigin="anonymous"></script>

<!-- Booking JS -->
<script>
    // Strings the page composes in JavaScript, supplied by the same language
    // layer the server-rendered markup uses.
    window.MARLE_I18N = <?= json_encode([
        'availableNow'      => site_t('Available Now', 'Magagamit Na'),
        'availableAt'       => site_t('Available at', 'Magagamit sa'),
        'checking'          => site_t('Checking...', 'Sinusuri...'),
        'checkAvailability' => site_t('Check Availability', 'Tingnan ang Availability'),
        'bookingNo'         => site_t('Booking no.', 'Booking blg.'),
        'guest'             => site_t('Guest', 'Bisita'),
        'room'              => site_t('Room', 'Kwarto'),
        'stay'              => site_t('Stay', 'Pamamalagi'),
        'guests'            => site_t('Guests', 'Mga bisita'),
        'bookingTotal'      => site_t('Booking total', 'Kabuuang booking'),
        'nonRefundable'     => site_t('Non-refundable', 'Hindi na maibabalik'),
        'stillPayable'      => site_t('Still payable at the front desk', 'Babayaran pa sa front desk'),
        'coveredByDeposit'  => site_t('Covered by your deposit', 'Sakop ng iyong deposito'),
        'cannotCancelOnline' => site_t('This booking cannot be cancelled online.', 'Hindi maisasagawa online ang pagkansela ng booking na ito.'),
        'selectDates'       => site_t('Please select both check-in and check-out dates.', 'Pumili ng petsa ng pagpasok at paglabas.'),
        'noRooms'           => site_t('No rooms available', 'Walang available na kwarto'),
        'forYourDates'      => site_t('for your selected dates.', 'para sa napili mong petsa.'),
        'networkError'      => site_t('Network error. Please try again.', 'Error sa network. Subukan muli.'),
        'submitting'        => site_t('Submitting...', 'Isinusumite...'),
        'submitBooking'     => site_t('Submit Booking Request', 'Isumite ang Booking Request'),
        'submitHourly'      => site_t('Submit Hourly Booking', 'Isumite ang Booking Bawat Oras'),
        'invalidEmail'      => site_t('Please enter a valid email address (e.g. juan@email.com).', 'Maglagay ng wastong email address (hal. juan@email.com).'),
        'invalidPhone'      => site_t('Phone number must contain exactly 11 digits.', 'Dapat eksaktong 11 digit ang numero ng telepono.'),
        'roomsAvailable'    => site_t('room(s) available', 'kwarto ang available'),
        'forLabel'          => site_t('for', 'para sa'),
        'tryDifferent'      => site_t('Please try different dates or a different room type.', 'Subukan ang ibang petsa o ibang uri ng kwarto.'),
        'showingUnavailable' => site_t('Showing unavailable rooms below.', 'Ipinapakita sa ibaba ang mga hindi available na kwarto.'),
        'tooSmallNote'      => site_t('{rooms} room(s) in the house sleep fewer than {guests}.', '{rooms} kwarto sa bahay ang kasya sa mas kaunti kaysa {guests} bisita.'),
        'roomLabel'         => site_t('Room', 'Kwarto'),
        'upTo'              => site_t('Up to', 'Hanggang'),
        'guestWord'         => site_t('guests', 'bisita'),
        'roomAvailable'     => site_t('Available', 'Available'),
        'bookRoom'          => site_t('Book Room', 'I-book ang Kwarto'),
        'underMaintenance'  => site_t('Under Maintenance', 'Sinasailalim sa Maintenance'),
        'booked'            => site_t('Booked', 'Naka-book'),
        'availableAtLabel'  => site_t('Available at:', 'Available sa:'),
        'toLabel'           => site_t('to', 'hanggang'),
        'estimatedTotal'    => site_t('Estimated total:', 'Tantiyang kabuuan:'),
        'reservationDeposit' => site_t('Reservation deposit', 'Reserbasyong deposito'),
        'requiredToConfirm' => site_t('required to confirm', 'kailangan upang kumpirmahin'),
        'addOnsRequested'   => site_t('Add-ons requested:', 'Mga hiniling na dagdag:'),
    ], JSON_UNESCAPED_UNICODE) ?>;

    // ── What each refusal means, in the guest's language ──────────────────────
    // submit_booking.php answers a rejected booking with {field, code, message}.
    // The code is the part that can be translated without parsing English, so
    // every code it can emit has a sentence here. An unknown code falls back to
    // the server's own English message, then to a plain apology.
    window.MARLE_ERRORS = <?= json_encode([
        'name_required'      => site_t('Please enter the guest’s full name.', 'Ilagay ang buong pangalan ng bisita.'),
        'phone_invalid'      => site_t('Phone number must contain exactly 11 digits, like 09171234567.', 'Dapat eksaktong 11 digit ang numero ng telepono, tulad ng 09171234567.'),
        'email_invalid'      => site_t('Please enter a valid email address, like juan@email.com.', 'Maglagay ng wastong email address, tulad ng juan@email.com.'),
        'room_required'      => site_t('Please choose a room type.', 'Pumili ng uri ng kwarto.'),
        'room_invalid'       => site_t('That room is no longer listed. Please choose another.', 'Wala na sa listahan ang kwartong iyon. Pumili ng iba.'),
        'persons_invalid'    => site_t('At least one guest is required.', 'Kailangan ng kahit isang bisita.'),
        'check_in_required'  => site_t('Please choose a check-in date.', 'Pumili ng petsa ng pagpasok.'),
        'check_in_past'      => site_t('That check-in date has passed. Please pick today or a later date.', 'Lumipas na ang petsang iyon. Pumili ng araw ngayon o mas bago.'),
        'check_out_required' => site_t('Please choose a check-out date.', 'Pumili ng petsa ng paglabas.'),
        'check_out_order'    => site_t('Check-out must be after check-in.', 'Dapat pagkatapos ng pagpasok ang paglabas.'),
        'terms_required'     => site_t('Please accept the deposit and cancellation policy to continue.', 'Tanggapin ang patakaran sa deposito at pagkansela para magpatuloy.'),
        'room_busy'          => site_t('Someone else is booking this room right now. Please try again in a few seconds.', 'May ibang nagbu-book sa kwartong ito ngayon. Subukan muli pagkalipas ng ilang segundo.'),
        'room_taken'         => site_t('That room is already booked for these dates. Please choose another room or other dates.', 'Naka-book na ang kwartong iyon sa mga petsang ito. Pumili ng ibang kwarto o ibang petsa.'),
        'room_occupied'      => site_t('That room still has a guest checked in. Please choose another room.', 'May bisitang naka-check in pa sa kwartong iyon. Pumili ng ibang kwarto.'),
        'date_taken'         => site_t('That room is booked overnight on this date. Please choose another time or room.', 'Naka-book ang kwartong iyon nang magdamag sa petsang ito. Pumili ng ibang oras o kwarto.'),
        'hours_range'        => site_t('Duration must be between 1 and 23 hours.', 'Dapat 1 hanggang 23 oras ang tagal.'),
        'time_invalid'       => site_t('That start time is not valid. Please choose it again.', 'Hindi wasto ang oras ng simula. Pumili muli.'),
        'time_past_midnight' => site_t('A booking cannot run past midnight. Start earlier or shorten the stay.', 'Hindi pwedeng lumampas ng hatinggabi ang booking. Mag-umpisa nang mas maaga o paikliin ito.'),
        'time_order'         => site_t('The end time must be after the start time.', 'Dapat pagkatapos ng oras ng simula ang pagtatapos.'),
        'addon_unavailable'  => site_t('One of the add-ons you picked is no longer available. Please choose again.', 'Wala nang available sa mga dagdag na pinili mo. Pumili muli.'),
        'submit_failed'      => site_t('We could not save the booking. Please try again, or call the front desk.', 'Hindi na-save ang booking. Subukan muli, o tumawag sa front desk.'),
        'network'            => site_t('We could not reach the front desk. Please check your connection and try again.', 'Hindi namin maabot ang front desk. Tingnan ang koneksyon at subukan muli.'),
        'unknown'            => site_t('Something went wrong. Please try again, or call the front desk.', 'May nagkamali. Subukan muli, o tumawag sa front desk.'),
    ], JSON_UNESCAPED_UNICODE) ?>;

    // ── One source of prices ───────────────────────────────────────────────────
    // Emitted from includes/booking_pricing.php. The page used to carry five
    // separate hardcoded rate maps (page cards, nightly modal, hourly modal,
    // badges), which is how the marketing figures drifted from what the hotel
    // actually charges.
    window.MARLE_RATES = <?= json_encode($ratesForJs, JSON_UNESCAPED_UNICODE) ?>;

    // ── The hotel's date, not the visitor's device's ─────────────────────────
    // These min dates used to come from the visitor's own clock, which is the wrong
    // clock for a hotel: a guest booking from another timezone — or from a phone set
    // to the wrong day — got minimums a day out, and the availability check further
    // down then disagreed with get_room_availability.php, which decides
    // "Occupied until ..." from the database's own CURDATE().
    //
    // MarleClock (includes/server_clock.php) carries the server's date and keeps
    // counting it in this browser. Never new Date().toISOString(): that is UTC,
    // which names yesterday before 08:00 here and lets a guest pick a past day.
    const _todayNow   = new Date();   // marle-clock-ok: last-resort fallback only — MarleClock and MARLE_HOTEL_TODAY are tried first, on the next line
    const _todayLocal = _todayNow.getFullYear() + '-' + String(_todayNow.getMonth() + 1).padStart(2, '0') + '-' + String(_todayNow.getDate()).padStart(2, '0');
    const today = window.MarleClock ? MarleClock.dayIso(0) : (window.MARLE_HOTEL_TODAY || _todayLocal);

    /** The hotel's today, re-read live so a page left open past midnight stays right. */
    function hotelToday() { return window.MarleClock ? MarleClock.dayIso(0) : today; }

    /** Add days to a YYYY-MM-DD string from its own parts — no timezone, no device clock. */
    function addDays(isoDate, days) {
        const p = String(isoDate).split('-');
        const d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]) + days);
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    document.getElementById('bookCheckIn').min = today;
    document.getElementById('bookCheckOut').min = today;


    document.getElementById('bookCheckIn').addEventListener('change', function() {
        const checkOut = document.getElementById('bookCheckOut');
        if (this.value) {
            // The next night used to be built by parsing the date as UTC midnight and
            // formatting it back with toISOString(); that round trip happened to agree
            // with itself, but it is a timezone in a date decision. Built from the
            // date's own parts instead, it cannot drift at all.
            const nextDay = addDays(this.value, 1);
            checkOut.min = nextDay;
            if (checkOut.value && checkOut.value <= this.value) {
                checkOut.value = nextDay;
            }
        }
    });

    function checkAvailability(e) {
        e.preventDefault();
        const checkIn  = document.getElementById('bookCheckIn').value;
        const checkOut = document.getElementById('bookCheckOut').value;
        const roomType = document.getElementById('bookRoomType').value;
        const guests   = document.getElementById('bookGuests').value;
        const btn      = document.getElementById('checkAvailBtn');
        const results  = document.getElementById('availabilityResults');
        const msg      = document.getElementById('availabilityMsg');

        if (!checkIn || !checkOut) {
            msg.innerHTML = '<div class="alert alert-warning py-2 small">' + MARLE_I18N.selectDates + '</div>';
            results.style.display = '';
            return false;
        }

        btn.disabled = true;
        btn.textContent = MARLE_I18N.checking;
        results.style.display = '';
        msg.innerHTML = '<div class="text-muted small">' + MARLE_I18N.checking + '</div>';

        let url = 'check_availability.php?check_in=' + encodeURIComponent(checkIn)
                + '&check_out=' + encodeURIComponent(checkOut);
        if (roomType) url += '&room_type=' + encodeURIComponent(roomType);
        // The party size goes with the dates, so the desk answers with rooms that
        // can actually hold them instead of making them open each one to check.
        if (guests) url += '&persons=' + encodeURIComponent(guests);

        fetch(url)
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.textContent = MARLE_I18N.checkAvailability;
                if (!data.success) {
                    msg.innerHTML = '<div class="alert alert-danger py-2 small">' + escHtml(data.message) + '</div>';
                    return;
                }

                // Filter by selected room type (mirrors hourly booking logic)
                let available   = (data.available   || []).filter(r => !roomType || r.room_type === roomType);
                let unavailable = (data.unavailable || []).filter(r => !roomType || r.room_type === roomType);

                if (available.length === 0 && unavailable.length === 0) {
                    // If capacity is the reason, say that — "try different dates"
                    // would send the guest off to look for another weekend when no
                    // weekend has a room big enough.
                    const emptyHint = (data.too_small && data.too_small.length)
                        ? MARLE_I18N.tooSmallNote
                            .replace('{rooms}', String(data.too_small.length))
                            .replace('{guests}', String(data.persons || guests))
                        : MARLE_I18N.tryDifferent;
                    msg.innerHTML = '<div class="alert alert-danger py-2">'
                        + '<i class="bi bi-x-circle me-1"></i>'
                        + '<strong>' + MARLE_I18N.noRooms + '</strong> ' + MARLE_I18N.forYourDates
                        + '<br><small class="text-muted">' + escHtml(emptyHint) + '</small>'
                        + '</div>';
                    return;
                }

                // Group available rooms by type
                const grouped = {};
                available.forEach(r => {
                    if (!grouped[r.room_type]) grouped[r.room_type] = [];
                    grouped[r.room_type].push(r);
                });

                // Group unavailable rooms by type
                const groupedUnavail = {};
                unavailable.forEach(r => {
                    if (!groupedUnavail[r.room_type]) groupedUnavail[r.room_type] = [];
                    groupedUnavail[r.room_type].push(r);
                });

                // Collect all room types across both groups (preserve order)
                const allTypes = [...new Set([...Object.keys(grouped), ...Object.keys(groupedUnavail)])];

                let html = '';
                if (available.length > 0) {
                    html += '<div class="alert alert-success py-2 mb-2">'
                        + '<i class="bi bi-check-circle me-1"></i>'
                        + '<strong>' + available.length + ' ' + MARLE_I18N.roomsAvailable + '</strong> ' + MARLE_I18N.forLabel + ' '
                        + formatDate(data.check_in) + ' ' + MARLE_I18N.toLabel + ' ' + formatDate(data.check_out)
                        + '</div>';
                } else {
                    html += '<div class="alert alert-warning py-2 mb-2">'
                        + '<i class="bi bi-exclamation-circle me-1"></i>'
                        + '<strong>' + MARLE_I18N.noRooms + '</strong> ' + MARLE_I18N.forYourDates
                        + '<br><small class="text-muted">' + MARLE_I18N.showingUnavailable + '</small>'
                        + '</div>';
                }

                if (data.too_small && data.too_small.length) {
                    html += '<p class="small text-muted mb-2">'
                        + '<i class="bi bi-people me-1"></i>'
                        + escHtml(MARLE_I18N.tooSmallNote
                            .replace('{rooms}', String(data.too_small.length))
                            .replace('{guests}', String(data.persons || guests)))
                        + '</p>';
                }

                html += '<div class="row g-2">';
                allTypes.forEach(type => {
                    html += '<div class="col-12"><h6 class="mt-2 mb-1 text-muted small text-uppercase">' + escHtml(type) + '</h6></div>';

                    // Available rooms for this type
                    (grouped[type] || []).forEach(r => {
                        html += '<div class="col-12 col-md-6">'
                            + '<div class="card h-100" style="border-radius:10px;">'
                            + '<div class="card-body p-3">'
                            + '<h6 class="mb-1">' + MARLE_I18N.roomLabel + ' ' + escHtml(r.room_number) + '</h6>'
                            + '<p class="text-muted small mb-2">' + MARLE_I18N.upTo + ' ' + escHtml(String(r.max_persons || '—')) + ' ' + MARLE_I18N.guestWord + '</p>'
                            + '<p class="text-success small mb-2"><i class="bi bi-check-circle me-1"></i>' + MARLE_I18N.roomAvailable + '</p>'
                            + '<button class="btn btn-sm w-100" style="background:#933310; color:#fff; border-radius:8px;" '
                            + 'onclick="openBookingForm(\'' + escHtml(type) + '\', \'' + escHtml(data.check_in) + '\', \'' + escHtml(data.check_out) + '\', \'' + escHtml(r.room_number) + '\', ' + (r.max_persons || 0) + ')">'
                            + '<i class="bi bi-calendar-check me-1"></i>' + MARLE_I18N.bookRoom + ' ' + escHtml(r.room_number)
                            + '</button>'
                            + '</div></div></div>';
                    });

                    // Unavailable (blocked) rooms for this type — dimmed, no button
                    (groupedUnavail[type] || []).forEach(r => {
                        const isMaint = (r.status && r.status === 'Maintenance');
                        const statusLabel = isMaint
                            ? '<p class="small mb-1" style="color:#e67e00;"><i class="bi bi-tools me-1"></i>' + MARLE_I18N.underMaintenance + '</p>'
                            : '<p class="text-danger small mb-1"><i class="bi bi-x-circle me-1"></i>' + MARLE_I18N.booked + '</p>';
                        const nextLine = (!isMaint && r.next_available)
                            ? '<p class="text-muted small mb-0"><i class="bi bi-clock me-1"></i>' + MARLE_I18N.availableAtLabel + ' ' + escHtml(r.next_available) + '</p>'
                            : '';
                        html += '<div class="col-12 col-md-6">'
                            + '<div class="card h-100" style="border-radius:10px; opacity:1;">'
                            + '<div class="card-body p-3">'
                            + '<h6 class="mb-1 text-muted">' + MARLE_I18N.roomLabel + ' ' + escHtml(r.room_number) + '</h6>'
                            + '<p class="text-muted small mb-2">' + MARLE_I18N.upTo + ' ' + escHtml(String(r.max_persons || '—')) + ' ' + MARLE_I18N.guestWord + '</p>'
                            + statusLabel
                            + nextLine
                            + '</div></div></div>';
                    });
                });
                html += '</div>';

                msg.innerHTML = html;
            })
            .catch(() => {
                btn.disabled = false;
                btn.textContent = MARLE_I18N.checkAvailability;
                msg.innerHTML = '<div class="alert alert-danger py-2 small">' + MARLE_I18N.networkError + '</div>';
            });

        return false;
    }

function openBookingForm(roomType, checkIn, checkOut, roomNumber, maxPersons) {
        // Reset form state first so we don't clear values set programmatically
        const bookingFormEl = document.getElementById('bookingForm');
        bookingFormEl.reset();
        bookingFormEl.style.display = '';
        document.getElementById('bookingSuccess').style.display = 'none';
        document.getElementById('bookingFormError').style.display = 'none';
        document.getElementById('bookingFormError').innerHTML = '';
        clearBookingErrors('bookingForm');
        document.getElementById('bkGuestWarning').style.display = 'none';

        // Then populate hidden fields and modal info
        document.getElementById('bkRoomType').value   = roomType;
        document.getElementById('bkCheckIn').value    = checkIn;
        document.getElementById('bkCheckOut').value   = checkOut;
        document.getElementById('bkRoomNumber').value = roomNumber || '';
        const nightlyRate = (window.MARLE_RATES && window.MARLE_RATES[roomType])
            ? Number(window.MARLE_RATES[roomType].nightly) || 0
            : 0;
        const checkInD  = new Date(checkIn  + 'T00:00:00');
        const checkOutD = new Date(checkOut + 'T00:00:00');
        const nights    = Math.max(1, Math.round((checkOutD - checkInD) / 86400000));
        const total     = nightlyRate * nights;
        const DEPOSIT_RATE = <?= json_encode(round(marle_deposit_rate($conn) / 100, 4)) ?>;
        const resFee    = Math.round(total * DEPOSIT_RATE);
document.getElementById('bookingModalInfo').innerHTML =
    '<strong>' + escHtml(roomType) + '</strong> &mdash; ' + MARLE_I18N.roomLabel + ' <strong>' + escHtml(roomNumber) + '</strong>'
    + ' &mdash; ' + formatDate(checkIn) + ' ' + MARLE_I18N.toLabel + ' ' + formatDate(checkOut)
    // Pinned to the same grouping the rest of the app uses (marle_peso() in PHP), not
    // the visitor's own locale: toLocaleString() with no locale renders ₱3.500,00 — or
    // even ₱3.500 for three thousand five hundred — on a European phone.
    + '<br><small class="text-muted">' + MARLE_I18N.estimatedTotal + ' <strong>₱' + peso(total) + '</strong>'
    + ' &nbsp;|&nbsp; ' + MARLE_I18N.reservationDeposit + ' (<?= (int)marle_deposit_rate($conn) ?>%, ' + MARLE_I18N.requiredToConfirm + '): <strong class="text-danger">₱' + peso(resFee) + '</strong></small>';

        // Guest count warning
        const personsEl = document.getElementById('bkPersons');
        const warnEl    = document.getElementById('bkGuestWarning');
        const warnMax   = document.getElementById('bkGuestWarningMax');
        personsEl.onchange = function() {
            if (maxPersons && parseInt(this.value) > maxPersons) {
                warnMax.textContent = maxPersons;
                warnEl.style.display = '';
            } else {
                warnEl.style.display = 'none';
            }
        };

        new bootstrap.Modal(document.getElementById('bookingModal')).show();
    }

    // ── Add-on card helpers ───────────────────────────────────────────────
        function toggleAddonCard(card, gridId) {
            // Class only — inline cssText used to fight the stylesheet and left
            // the selected state with no accessible signal.
            const isSelected = card.classList.toggle('addon-selected');
            card.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        }

        function getSelectedAddons(gridId) {
            const grid = document.getElementById(gridId);
            if (!grid) return '';
            const selected = [...grid.querySelectorAll('.addon-selected')]
                .map(c => c.dataset.addon.trim())
                .filter(Boolean);
            return selected.length ? MARLE_I18N.addOnsRequested + ' ' + selected.join(', ') : '';
        }

        function buildSpecialRequests(textareaId, gridId) {
            return document.getElementById(textareaId).value.trim();
        }

        function getSelectedAddonIds(gridId) {
            const grid = document.getElementById(gridId);
            if (!grid) return [];
            return [...grid.querySelectorAll('.addon-selected')]
                .map(card => card.dataset.addonId)
                .filter(Boolean);
        }

        function keepPhoneDigits(input) {
            input.value = input.value.replace(/\D/g, '').slice(0, 11);
        }

        function isValidPhone(phone) {
            return /^\d{11}$/.test(phone);
        }

/* Any real domain is allowed (corporate, school, ISP, PH providers). The
   server additionally checks that the domain actually receives mail and
   rejects throwaway mailbox providers — this is only a fast first pass. */
function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/.test(email);
}

    /* ── Booking errors ──────────────────────────────────────────────────────
       submit_booking.php answers a refusal with {field, code, message}. These
       helpers turn that into a sentence beside the input that caused it, and
       into a summary announced to a screen reader. Nothing the server writes is
       ever printed raw: a response that is not JSON (a PHP fatal, an error page,
       a truncated body) becomes the same apology a lost connection gets, and the
       body itself goes to the console for whoever is debugging.
       ------------------------------------------------------------------------ */
    function bookingFieldIds(prefix) {
        return {
            guest_name:  prefix + 'GuestName',
            guest_email: prefix + 'GuestEmail',
            guest_phone: prefix + 'GuestPhone',
            persons:     prefix + 'Persons',
            terms:       prefix + 'TermsAccepted'
        };
    }

    /** Whatever shape the refusal came in, as a list of {field, code, message}. */
    function bookingServerErrors(data) {
        if (data && Array.isArray(data.errors) && data.errors.length) {
            return data.errors;
        }
        return [{ code: (data && data.code) || 'unknown', message: (data && data.message) || '' }];
    }

    function clearBookingErrors(formId) {
        const form = document.getElementById(formId);
        if (!form) { return; }
        form.querySelectorAll('.is-invalid').forEach(function (el) {
            el.classList.remove('is-invalid');
            el.removeAttribute('aria-invalid');
            el.removeAttribute('aria-describedby');
        });
        form.querySelectorAll('[data-booking-error]').forEach(function (el) {
            if (el.parentNode) { el.parentNode.removeChild(el); }
        });
    }

    function showBookingErrors(prefix, summaryEl, errors) {
        if (!summaryEl) { return; }
        const ids = bookingFieldIds(prefix);
        const list = document.createElement('ul');
        list.className = 'booking-errors';
        let focusTarget = null;

        (errors || []).forEach(function (item) {
            const text = (window.MARLE_ERRORS && MARLE_ERRORS[item.code])
                || item.message
                || (window.MARLE_ERRORS ? MARLE_ERRORS.unknown : 'Please try again.');

            const li = document.createElement('li');
            li.textContent = text;
            list.appendChild(li);

            const id = item.field ? ids[item.field] : '';
            const input = id ? document.getElementById(id) : null;
            if (!input) { return; }

            input.classList.add('is-invalid');
            input.setAttribute('aria-invalid', 'true');

            const note = document.createElement('div');
            note.className = 'invalid-feedback d-block';
            note.setAttribute('data-booking-error', item.code || 'error');
            note.id = id + '-error';
            note.textContent = text;

            // A checkbox's message belongs after the label, not wedged between
            // the box and the words beside it.
            const check = input.closest ? input.closest('.form-check') : null;
            if (input.type === 'checkbox' && check) {
                check.appendChild(note);
            } else {
                input.insertAdjacentElement('afterend', note);
            }
            input.setAttribute('aria-describedby', note.id);

            if (!focusTarget && input.offsetParent !== null) { focusTarget = input; }
        });

        summaryEl.innerHTML = '';
        if (list.childElementCount) {
            summaryEl.appendChild(list);
            summaryEl.style.display = '';
        }
        if (focusTarget) {
            try { focusTarget.focus(); } catch (err) { /* focus is a nicety */ }
        }
    }

    function submitBooking(e) {
        e.preventDefault();
        const btn   = document.getElementById('submitBookingBtn');
        const errEl = document.getElementById('bookingFormError');
        errEl.style.display = 'none';
        errEl.innerHTML = '';
        clearBookingErrors('bookingForm');

        const emailVal = document.getElementById('bkGuestEmail').value.trim();
        const phoneVal = document.getElementById('bkGuestPhone').value.trim();
        const localErrors = [];
        if (!isValidEmail(emailVal)) { localErrors.push({ field: 'guest_email', code: 'email_invalid' }); }
        if (!isValidPhone(phoneVal)) { localErrors.push({ field: 'guest_phone', code: 'phone_invalid' }); }
        if (localErrors.length) {
            showBookingErrors('bk', errEl, localErrors);
            return false;
        }

        const formData = new FormData();
        formData.append('guest_name',       document.getElementById('bkGuestName').value.trim());
        formData.append('guest_email',      document.getElementById('bkGuestEmail').value.trim());
        formData.append('guest_phone',      phoneVal);
        formData.append('room_type',        document.getElementById('bkRoomType').value);
        formData.append('persons',          document.getElementById('bkPersons').value);
        formData.append('check_in',         document.getElementById('bkCheckIn').value);
        formData.append('check_out',        document.getElementById('bkCheckOut').value);
        formData.append('room_number',      document.getElementById('bkRoomNumber').value);
        formData.append('special_requests', buildSpecialRequests('bkSpecialRequests', 'bkAddonGrid'));
        getSelectedAddonIds('bkAddonGrid').forEach(id => formData.append('addon_ids[]', id));
        formData.append('terms_accepted',   document.getElementById('bkTermsAccepted').checked ? '1' : '0');

        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>' + MARLE_I18N.submitting;

        fetch('submit_booking.php', { method: 'POST', body: formData })
            .then(async r => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (err) {
                    // A PHP fatal, a host error page or a truncated body. The
                    // guest gets a sentence; the body is kept for the console so
                    // nobody has to guess what the server said.
                    console.error('submit_booking.php returned non-JSON', r.status, text.slice(0, 1500));
                    return { success: false, code: 'submit_failed', message: '' };
                }
            })
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send me-1"></i>' + MARLE_I18N.submitBooking;
                if (data.success) {
                    document.getElementById('bookingForm').style.display = 'none';
                    document.getElementById('bookingSuccessMsg').textContent = data.message
                        ;
                    document.getElementById('bookingSuccessToken').textContent = data.status_token || '';
                    const statusUrl = new URL('booking_status.php', window.location.href);
                    statusUrl.searchParams.set('email', emailVal);
                    statusUrl.searchParams.set('status_token', data.status_token || '');
                    document.getElementById('bookingStatusLink').href = statusUrl.href;
                    document.getElementById('bookingSuccess').style.display = '';
                } else {
                    showBookingErrors('bk', errEl, bookingServerErrors(data));
                }
            })
            .catch((err) => {
                // A real network failure. The browser's own message ("Failed to
                // fetch") is English and says nothing useful to a guest.
                console.error('submitBooking network error:', err);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send me-1"></i>' + MARLE_I18N.submitBooking;
                showBookingErrors('bk', errEl, [{ code: 'network' }]);
            });

        return false;
    }

    function formatDate(dateStr) {
        const d = new Date(dateStr + 'T00:00:00');
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    /**
     * A ₱ amount, grouped and rounded the way marle_peso() does it in PHP.
     *
     * The locale is pinned on purpose: bare toLocaleString() groups by the visitor's
     * own device locale, so ₱3,500 rendered as ₱3.500 on a European phone — a price the
     * guest could read as three and a half pesos.
     */
    function peso(amount) {
        return Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Booking mode toggle ────────────────────────────────
    function switchBookingMode(mode) {
        const isHourly = mode === 'hourly';
        document.getElementById('availabilityForm').style.display  = isHourly ? 'none' : '';
        document.getElementById('hourlyForm').style.display        = isHourly ? '' : 'none';

        const nightlyResults = document.getElementById('availabilityResults');
        nightlyResults.style.display = 'none';
        document.getElementById('availabilityMsg').innerHTML = '';

        const hourlyResults = document.getElementById('hrAvailResults');
        hourlyResults.style.display = 'none';
        hourlyResults.innerHTML = '';

        // State lives in the class and aria-pressed, not in inline colours.
        const daily  = document.getElementById('modeDaily');
        const hourly = document.getElementById('modeHourly');
        daily.classList.toggle('is-active', !isHourly);
        hourly.classList.toggle('is-active', isHourly);
        daily.setAttribute('aria-pressed', isHourly ? 'false' : 'true');
        hourly.setAttribute('aria-pressed', isHourly ? 'true' : 'false');
    }

    // ── Hourly form: populate start times ─────────────────
    (function populateStartTimes() {
        const sel = document.getElementById('hrStartTime');
        for (let h = 0; h <= 23; h++) {
            for (let m = 0; m < 60; m += 30) {
                const hh  = String(h).padStart(2, '0');
                const mm  = String(m).padStart(2, '0');
                const val = hh + ':' + mm;
                const ampm = h < 12 ? 'AM' : 'PM';
                const h12  = h % 12 === 0 ? 12 : h % 12;
                const label = h12 + ':' + mm + ' ' + ampm;
                const opt = document.createElement('option');
                opt.value = val;
                opt.textContent = label;
                sel.appendChild(opt);
            }
        }
    })();

    // ── Compute checkout time ──────────────────────────────
    function updateHourlyCheckout() {
    const startVal = document.getElementById('hrStartTime').value;
    const duration = parseInt(document.getElementById('hrDuration').value, 10);
    const display  = document.getElementById('hrCheckoutDisplay');

    if (!display) return;
    if (!startVal || !duration) { display.textContent = '—'; return; }

        const [hh, mm] = startVal.split(':').map(Number);
        const totalMins = hh * 60 + mm + duration * 60;

            const outH  = Math.floor(totalMins / 60) % 24;
            const outM  = totalMins % 60;
            const ampm  = outH < 12 ? 'AM' : 'PM';
            const h12   = outH % 12 === 0 ? 12 : outH % 12;
            display.textContent = h12 + ':' + String(outM).padStart(2, '0') + ' ' + ampm;
    }

    document.getElementById('hrStartTime').addEventListener('change', updateHourlyCheckout);
    document.getElementById('hrDuration').addEventListener('change', updateHourlyCheckout);

    // ── Set min date for hourly picker (the hotel's today — see the block above) ──
    document.getElementById('hrDate').min = hotelToday();

// ── Populate hourly availability results (mirrors book by nights) ──
    function populateHourlyRooms(event) {
        // The form's own submit button now actually runs the check; before this,
        // onsubmit="return false" made "Check rooms" a dead control while the
        // results only appeared as a side effect of changing a field.
        if (event && typeof event.preventDefault === 'function') {
            event.preventDefault();
        }

        const date     = document.getElementById('hrDate').value;
        const roomType = document.getElementById('hrRoomType').value;
        const startVal = document.getElementById('hrStartTime').value;
        const duration = parseInt(document.getElementById('hrDuration').value, 10);
        const results  = document.getElementById('hrAvailResults');

        // Hide results if inputs incomplete
        if (!date || !roomType || !startVal || !duration) {
            results.style.display = 'none';
            results.innerHTML     = '';
            return false;
        }

        const [hh, mm]   = startVal.split(':').map(Number);
        const totalMins  = hh * 60 + mm + duration * 60;

        const reqInMins  = hh * 60 + mm;

        // Show loading
        results.style.display = '';
        results.innerHTML = '<div class="text-muted small">'
            + '<span class="spinner-border spinner-border-sm me-1"></span>'
            + 'Checking room availability...</div>';

        fetch('get_room_availability.php?mode=full')
            .then(r => r.json())
            .then(data => {
                if (!data.success || !Array.isArray(data.rooms)) {
                    results.innerHTML = '<div class="alert alert-danger py-2 small">Unable to load rooms. Please try again.</div>';
                    return;
                }

                // The hotel's date, read live (includes/server_clock.php) — not the
                // visitor's, and never toISOString() (UTC, which reported yesterday
                // before 08:00 in UTC+8, made isToday false, and let a room whose status
                // is "Occupied until 4:51 PM" fall through every block below and be
                // offered to the guest as available). This is the same date
                // get_room_availability.php used when it built these statuses.
                const todayStr = hotelToday();
                const isToday  = (date === todayStr);
                const typeRooms = data.rooms.filter(r => r.room_type === roomType);

                if (typeRooms.length === 0) {
                    results.innerHTML = '<div class="alert alert-danger py-2 small">No rooms found for this type.</div>';
                    return;
                }

                // Separate available vs blocked
                const available = [];
                const blocked   = [];

                typeRooms.forEach(r => {
                    let isBlocked    = false;
                    let blockedLabel = '';
                    let nextAvail    = r.next_available || null;

                    if (isToday && r.status.startsWith('Occupied until')) {
                        const match = r.status.match(/Occupied until (\d+):(\d+)\s*(AM|PM)/i);
                        if (match) {
                            let occH   = parseInt(match[1], 10);
                            const occM = parseInt(match[2], 10);
                            const ap   = match[3].toUpperCase();
                            if (ap === 'PM' && occH !== 12) occH += 12;
                            if (ap === 'AM' && occH === 12) occH  = 0;
                            if (reqInMins < occH * 60 + occM) {
                                isBlocked    = true;
                                blockedLabel = r.status;
                            }
                        }
                    } else if (r.status === 'Checked In' || r.status === 'Booked') {
                        isBlocked    = true;
                        blockedLabel = r.status;
                    } else if (r.status === 'Maintenance') {
                        isBlocked    = true;
                        blockedLabel = 'Under Maintenance';
                    }

                    if (isBlocked) {
                        blocked.push({ room_number: r.room_number, label: blockedLabel, next_available: nextAvail });
                    } else {
                       available.push(r);
                    }
                });

               if (available.length === 0) {
                    results.innerHTML = '<div class="alert alert-danger py-2">'
                        + '<i class="bi bi-x-circle me-1"></i>'
                        + '<strong>No rooms available</strong> for ' + escHtml(roomType) + ' during this time slot.'
                        + '<br><small class="text-muted">Please choose a different time or room type.</small>'
                        + '</div>';
                    return;
                }

                // ── Build card grid (same style as book by nights) ──
                let html = '<div class="alert alert-success py-2 mb-2">'
                    + '<i class="bi bi-check-circle me-1"></i>'
                    + '<strong>' + available.length + ' room(s) available</strong> for '
                    + escHtml(roomType) + ' on ' + escHtml(date)
                    + '</div>';

                html += '<div class="row g-2">';

                // Available rooms
                available.forEach(r => {
                    const maxPersons = r.max_persons || 0;
                    html += '<div class="col-12 col-md-6">'
                        + '<div class="card h-100" style="border-radius:10px;">'
                        + '<div class="card-body p-3">'
                        + '<h6 class="mb-1">Room ' + escHtml(r.room_number) + '</h6>'
                        + '<p class="text-muted small mb-2">Up to ' + escHtml(String(maxPersons || '—')) + ' guests</p>'
                        + '<p class="text-success small mb-2"><i class="bi bi-check-circle me-1"></i>Available</p>'
                        + '<button class="btn btn-sm w-100" style="background:#933310; color:#fff; border-radius:8px;" '
                        + 'onclick="openHourlyBookingForm(\'' + escHtml(r.room_number) + '\', \'' + escHtml(roomType) + '\', \'' + escHtml(date) + '\', \'' + escHtml(startVal) + '\', ' + duration + ', ' + maxPersons + ')">'
                        + '<i class="bi bi-clock me-1"></i>Book Room ' + escHtml(r.room_number)
                        + '</button>'
                        + '</div></div></div>';
                });

                // Blocked rooms (dimmed, no button)
                blocked.forEach(r => {
                    const nextLine = r.next_available
                        ? '<p class="text-muted small mb-0"><i class="bi bi-clock me-1"></i>Available at: ' + escHtml(r.next_available) + '</p>'
                        : '';
                    html += '<div class="col-12 col-md-6">'
                        + '<div class="card h-100" style="border-radius:10px; opacity:1;">'
                        + '<div class="card-body p-3">'
                        + '<h6 class="mb-1 text-muted">Room ' + escHtml(r.room_number) + '</h6>'
                        + '<p class="text-danger small mb-1"><i class="bi bi-x-circle me-1"></i>' + escHtml(r.label) + '</p>'
                        + nextLine
                        + '</div></div></div>';
                });

                html += '</div>';
                results.innerHTML = html;
            })
            .catch(() => {
                results.innerHTML = '<div class="alert alert-danger py-2 small">' + MARLE_I18N.networkError + '</div>';
            });
    }
// Re-populate results whenever any slot field changes
    document.addEventListener('DOMContentLoaded', function () {
        ['bkGuestPhone', 'hrBkGuestPhone'].forEach(function (id) {
            const phoneInput = document.getElementById(id);
            if (phoneInput) phoneInput.addEventListener('input', function () { keepPhoneDigits(this); });
        });
        ['hrDate', 'hrRoomType', 'hrStartTime', 'hrDuration'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', populateHourlyRooms);
        });
    });

    // Called when guest clicks Book on a specific room card
    function openHourlyBookingForm(roomNumber, roomType, date, startVal, duration, maxPersons) {
        const [hh, mm]  = startVal.split(':').map(Number);
        const totalMins = hh * 60 + mm + duration * 60;
        const outH = Math.floor(totalMins / 60) % 24;
        const outM = totalMins % 60;
        const checkInTime  = String(hh).padStart(2,'0') + ':' + String(mm).padStart(2,'0') + ':00';
        const checkOutTime = String(outH).padStart(2,'0') + ':' + String(outM).padStart(2,'0') + ':00';

        // Reset + populate modal
        const hourlyDetailsFormEl = document.getElementById('hourlyDetailsForm');
        hourlyDetailsFormEl.reset();
        hourlyDetailsFormEl.style.display = '';
        document.getElementById('hrBookingSuccess').style.display   = 'none';
        document.getElementById('hrBookingFormError').style.display = 'none';
        document.getElementById('hrBkGuestWarning').style.display   = 'none';

        document.getElementById('hrBkRoomType').value     = roomType;
        document.getElementById('hrBkRoomNumber').value   = roomNumber;
        document.getElementById('hrBkDate').value         = date;
        document.getElementById('hrBkCheckInTime').value  = checkInTime;
        document.getElementById('hrBkCheckOutTime').value = checkOutTime;
        document.getElementById('hrBkHours').value        = duration;

        const ampmIn  = hh < 12 ? 'AM' : 'PM';
        const ampmOut = outH < 12 ? 'AM' : 'PM';
        const h12In   = hh % 12 === 0 ? 12 : hh % 12;
        const h12Out  = outH % 12 === 0 ? 12 : outH % 12;
        const _rate   = (window.MARLE_RATES && window.MARLE_RATES[roomType]) || {};
        const _base   = Number(_rate.threeHour) || 0;
        const _ext    = Number(_rate.extension) || 0;
        const _hTotal = _base + (Math.max(0, duration - 3) * _ext);
        const _hFee   = Math.round(_hTotal * <?= json_encode(round(marle_deposit_rate($conn) / 100, 4)) ?>);
        document.getElementById('hrModalInfo').innerHTML =
            '<strong>' + escHtml(roomType) + '</strong>'
            + ' &mdash; ' + MARLE_I18N.roomLabel + ' <strong>' + escHtml(roomNumber) + '</strong>'
            + ' &mdash; ' + escHtml(date) + ' &bull; '
            + h12In + ':' + String(mm).padStart(2,'0') + ' ' + ampmIn
            + ' &rarr; '
            + h12Out + ':' + String(outM).padStart(2,'0') + ' ' + ampmOut
            + ' (' + duration + ' hr' + (duration > 1 ? 's' : '') + ')'
            + '<br><small class="text-muted">' + MARLE_I18N.estimatedTotal + ' <strong>\u20b1' + peso(_hTotal) + '</strong>'
            + ' &nbsp;|&nbsp; ' + MARLE_I18N.reservationDeposit + ' (<?= (int)marle_deposit_rate($conn) ?>%, ' + MARLE_I18N.requiredToConfirm + '): <strong class="text-danger">\u20b1' + peso(_hFee) + '</strong></small>';

        // Guest count warning
        const hrPersonsEl = document.getElementById('hrBkPersons');
        const hrWarnEl    = document.getElementById('hrBkGuestWarning');
        const hrWarnMax   = document.getElementById('hrBkGuestWarningMax');
        hrPersonsEl.onchange = function() {
            if (maxPersons && parseInt(this.value) > maxPersons) {
                hrWarnMax.textContent = maxPersons;
                hrWarnEl.style.display = '';
            } else {
                hrWarnEl.style.display = 'none';
            }
        };

        new bootstrap.Modal(document.getElementById('hourlyModal')).show();
    }


    // ── Submit hourly booking details ──────────────────────
    function submitHourlyDetails(e) {
        e.preventDefault();
        const btn   = document.getElementById('submitHourlyBtn');
        const errEl = document.getElementById('hrBookingFormError');
        errEl.style.display = 'none';
        errEl.innerHTML = '';
        clearBookingErrors('hourlyDetailsForm');

        const emailVal = document.getElementById('hrBkGuestEmail').value.trim();
        const phoneVal = document.getElementById('hrBkGuestPhone').value.trim();
        const localErrors = [];
        if (!isValidEmail(emailVal)) { localErrors.push({ field: 'guest_email', code: 'email_invalid' }); }
        if (!isValidPhone(phoneVal)) { localErrors.push({ field: 'guest_phone', code: 'phone_invalid' }); }
        if (localErrors.length) {
            showBookingErrors('hrBk', errEl, localErrors);
            return false;
        }

        const formData = new FormData();
        formData.append('booking_type',    'hourly');
        formData.append('room_number',     document.getElementById('hrBkRoomNumber').value);
        formData.append('guest_name',      document.getElementById('hrBkGuestName').value.trim());
        formData.append('guest_email',     document.getElementById('hrBkGuestEmail').value.trim());
        formData.append('guest_phone',     phoneVal);
        formData.append('room_type',       document.getElementById('hrBkRoomType').value);
        formData.append('persons',         document.getElementById('hrBkPersons').value);
        formData.append('check_in',        document.getElementById('hrBkDate').value);
        formData.append('check_out',       document.getElementById('hrBkDate').value);
        formData.append('check_in_time',   document.getElementById('hrBkCheckInTime').value);
        formData.append('check_out_time',  document.getElementById('hrBkCheckOutTime').value);
        formData.append('hours_requested', document.getElementById('hrBkHours').value);
        formData.append('special_requests', buildSpecialRequests('hrBkSpecialRequests', 'hrBkAddonGrid'));
        getSelectedAddonIds('hrBkAddonGrid').forEach(id => formData.append('addon_ids[]', id));
        formData.append('terms_accepted',  document.getElementById('hrBkTermsAccepted').checked ? '1' : '0');

        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>' + MARLE_I18N.submitting;

        fetch('submit_booking.php', { method: 'POST', body: formData })
            .then(async r => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (err) {
                    console.error('submit_booking.php returned non-JSON', r.status, text.slice(0, 1500));
                    return { success: false, code: 'submit_failed', message: '' };
                }
            })
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send me-1"></i>' + MARLE_I18N.submitHourly;
                if (data.success) {
                    document.getElementById('hourlyDetailsForm').style.display = 'none';
                    document.getElementById('hrBookingSuccessMsg').textContent = data.message
                        ;
                    document.getElementById('hrBookingSuccessToken').textContent = data.status_token || '';
                    const hourlyStatusUrl = new URL('booking_status.php', window.location.href);
                    hourlyStatusUrl.searchParams.set('email', emailVal);
                    hourlyStatusUrl.searchParams.set('status_token', data.status_token || '');
                    document.getElementById('hrBookingStatusLink').href = hourlyStatusUrl.href;
                    document.getElementById('hrBookingSuccess').style.display  = '';
                } else {
                    showBookingErrors('hrBk', errEl, bookingServerErrors(data));
                }
            })
            .catch((err) => {
                console.error('submitHourlyDetails network error:', err);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send me-1"></i>' + MARLE_I18N.submitHourly;
                showBookingErrors('hrBk', errEl, [{ code: 'network' }]);
            });

        return false;
    }

    // ── 6e/6f: Poll — cron + badge refresh every 60s ──────
    function refreshHourlyBadges() {
        // Native fetch replaced jQuery: the library was loaded on the public
        // page for these two requests alone.
        fetch('release_hourly_rooms.php', { credentials: 'same-origin' })
            .then(function () {
                return fetch('get_room_availability.php', { credentials: 'same-origin' });
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success) return;
                const roomToId = {
                    'Without Garage': 'badge-Without-Garage',
                    'With Garage':    'badge-With-Garage',
                    'Suite':          'badge-Suite',
                    'Family':         'badge-Family',
                };
                for (const [rt, id] of Object.entries(roomToId)) {
                    const info = data.rooms[rt];
                    const el   = document.getElementById(id);
                    if (!el) continue;
                    if (!info || info.available) {
                        el.innerHTML = '';
                    } else {
                        // The endpoint returns either a clock time or the literal
                        // "Available Now". Decide by shape, not by that literal, so
                        // the badge reads correctly in either language.
                        const isNow = !/\d{1,2}:\d{2}/.test(String(info.available_at || ''));
                        const label = isNow
                            ? MARLE_I18N.availableNow
                            : MARLE_I18N.availableAt + ' ' + info.available_at;
                        const color = isNow ? '#198754' : '#e67e00';
                        el.innerHTML =
                            '<span class="hourly-avail-badge" style="display:inline-block;background:'
                            + color + ';color:#fff;font-size:.72rem;font-weight:600;'
                            + 'padding:2px 8px;border-radius:20px;letter-spacing:.3px;">'
                            + '<i class="bi bi-clock me-1"></i>' + label + '</span>';
                    }
                }
            })
            .catch(function () {
                // Availability refresh is an enhancement; a dead cron endpoint
                // must not throw on every poll.
            });
    }

    // Start polling after page load
    setInterval(refreshHourlyBadges, 60000);
</script>

<!-- ── Cancel a Booking Modal (guest self-service) ── -->
<div class="modal fade" id="cancelBookingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:15px; overflow:hidden;">
            <div class="modal-header" style="background:#933310; color:#fff;">
                <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i><?= site_t('Cancel a Booking', 'Kanselahin ang Booking') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">

                <!-- Step 1 — find the booking -->
                <div id="cbStepFind">
                    <p class="text-muted small mb-3">
                        <?= site_t('Enter the email address you booked with and the private status token from your confirmation email. We show exactly what cancelling costs before anything happens.', 'Ilagay ang email address na ginamit mo sa pag-book at ang pribadong status token mula sa iyong confirmation email. Ipinapakita namin nang tumpak ang halaga ng pagkansela bago may mangyari.') ?>
                    </p>
                    <div class="alert alert-danger py-2 small d-none" id="cbFindError"></div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1" for="cbEmail"><?= site_t('Email address', 'Email address') ?></label>
                        <input type="email" class="form-control form-control-sm" id="cbEmail"
                               placeholder="you@example.com" autocomplete="email">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1" for="cbToken"><?= site_t('Status token', 'Status token') ?></label>
                        <input type="text" class="form-control form-control-sm" id="cbToken"
                               placeholder="<?= site_t('The 64-character token from your booking email', 'Ang 64-character na token mula sa iyong booking email') ?>" autocomplete="off">
                        <div class="form-text"><?= site_t('It is in your booking confirmation email, next to your booking number.', 'Makikita ito sa iyong booking confirmation email, katabi ng iyong booking number.') ?></div>
                    </div>
                    <button type="button" class="btn w-100 fw-semibold" id="cbFindBtn"
                            style="background:#933310; color:#fff;" onclick="cbLookup()">
                        <i class="bi bi-search me-1"></i><?= site_t('Find my booking', 'Hanapin ang aking booking') ?>
                    </button>
                </div>

                <!-- Step 2 — confirm, with the forfeiture spelled out -->
                <div id="cbStepConfirm" class="d-none">
                    <div id="cbSummary" class="border rounded-3 p-3 mb-3 small" style="background:#fbf7f4;"></div>
                    <div class="alert alert-warning py-2 small d-none" id="cbBlocked"></div>
                    <div id="cbReasonBlock">
                        <div class="mb-2">
                            <label class="form-label small fw-semibold mb-1" for="cbReason"><?= site_t('Reason (optional)', 'Dahilan (opsyonal)') ?></label>
                            <select class="form-select form-select-sm" id="cbReason">
                                <option value="change of plans"><?= site_t('Change of plans', 'Nagbago ang plano') ?></option>
                                <option value="travel cancelled"><?= site_t('Travel cancelled', 'Kinansela ang biyahe') ?></option>
                                <option value="found another place"><?= site_t('Found another place', 'May nahanap na ibang lugar') ?></option>
                                <option value="budget reasons"><?= site_t('Budget reasons', 'Dahil sa budget') ?></option>
                                <option value="booked by mistake"><?= site_t('Booked by mistake', 'Napag-book nang mali') ?></option>
                                <option value="other"><?= site_t('Other', 'Iba pa') ?></option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold mb-1" for="cbDetail"><?= site_t('Details (optional)', 'Karagdagang detalye (opsyonal)') ?></label>
                            <input type="text" class="form-control form-control-sm" id="cbDetail" maxlength="160"
                                   placeholder="<?= site_t('Anything we should know?', 'May dapat ba kaming malaman?') ?>">
                        </div>
                        <div class="alert alert-danger py-2 small d-none" id="cbCancelError"></div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary w-50" onclick="cbBack()"><?= site_t('Back', 'Bumalik') ?></button>
                        <button type="button" class="btn btn-danger w-50 fw-semibold" id="cbConfirmBtn"
                                onclick="cbConfirm()">
                            <i class="bi bi-x-circle me-1"></i><?= site_t('Cancel this booking', 'Kanselahin ang booking na ito') ?>
                        </button>
                    </div>
                </div>

                <!-- Step 3 — done -->
                <div id="cbStepDone" class="d-none text-center">
                    <i class="bi bi-check-circle" style="font-size:2.5rem; color:#198754;"></i>
                    <h6 class="mt-3 mb-2"><?= site_t('Booking cancelled', 'Nakansela ang booking') ?></h6>
                    <p class="text-muted small" id="cbDoneMsg"></p>
                    <a href="booking_status.php" class="btn btn-outline-secondary btn-sm" id="cbDoneStatusLink">
                        <i class="bi bi-search me-1"></i><?= site_t('View booking status', 'Tingnan ang booking status') ?>
                    </a>
                    <button type="button" class="btn btn-sm" style="background:#933310; color:#fff;"
                            data-bs-dismiss="modal"><?= site_t('Close', 'Isara') ?></button>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ── Cancel a Booking JS ── -->
<script>
(function () {
    var cancelModal = null;
    var cbBookingId = 0;

    function el(id) { return document.getElementById(id); }

    function cbError(id, message) {
        var box = el(id);
        if (!box) return;
        if (!message) { box.classList.add('d-none'); box.textContent = ''; return; }
        box.textContent = message;
        box.classList.remove('d-none');
    }

    function cbStep(name) {
        el('cbStepFind').classList.toggle('d-none', name !== 'find');
        el('cbStepConfirm').classList.toggle('d-none', name !== 'confirm');
        el('cbStepDone').classList.toggle('d-none', name !== 'done');
    }

    function cbRow(label, value) {
        var line = document.createElement('div');
        line.className = 'd-flex justify-content-between gap-2 py-1';
        var l = document.createElement('span');
        l.className = 'text-muted';
        l.textContent = label;
        var v = document.createElement('span');
        v.className = 'fw-semibold text-end';
        v.textContent = value;
        line.appendChild(l);
        line.appendChild(v);
        return line;
    }

    function cbDivider() {
        var hr = document.createElement('hr');
        hr.className = 'my-2';
        return hr;
    }

    function cbFormatDate(value) {
        if (!value) return '';
        var d = new Date(value + 'T00:00:00');
        if (isNaN(d)) return value;
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    window.openCancelBookingModal = function () {
        cancelModal = cancelModal || bootstrap.Modal.getOrCreateInstance(el('cancelBookingModal'));
        cbError('cbFindError', '');
        cbError('cbCancelError', '');
        cbStep('find');
        cancelModal.show();
        setTimeout(function () { el('cbEmail').focus(); }, 300);
    };

    window.cbBack = function () { cbStep('find'); };

    window.cbLookup = function () {
        var email = (el('cbEmail').value || '').trim();
        var token = (el('cbToken').value || '').trim();
        var btn   = el('cbFindBtn');

        cbError('cbFindError', '');
        if (!email || !token) {
            cbError('cbFindError', 'Enter both the email address and the status token.');
            return;
        }

        btn.disabled = true;
        fetch('cancel_booking.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ mode: 'lookup', email: email, status_token: token })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (!d.success) { cbError('cbFindError', d.message); return; }
            cbRenderConfirm(d);
            cbStep('confirm');
        })
        .catch(function () {
            btn.disabled = false;
            cbError('cbFindError', 'Could not reach the server. Please try again.');
        });
    };

    function cbRenderConfirm(d) {
        var b = d.booking || {};
        var p = d.preview || {};
        cbBookingId = b.id || 0;

        var stay = b.booking_type === 'hourly'
            ? cbFormatDate(b.check_in) + ' · ' + (b.hours_requested || 0) + ' hour(s)'
            : cbFormatDate(b.check_in) + ' → ' + cbFormatDate(b.check_out);

        var box = el('cbSummary');
        box.textContent = '';
        box.appendChild(cbRow(MARLE_I18N.bookingNo, '#' + cbBookingId));
        box.appendChild(cbRow(MARLE_I18N.guest, b.guest_name || ''));
        box.appendChild(cbRow(MARLE_I18N.room, (b.room_type || '') + (b.room_number ? ' · ' + b.room_number : '')));
        box.appendChild(cbRow(MARLE_I18N.stay, stay));
        box.appendChild(cbRow(MARLE_I18N.guests, String(b.persons || 0)));
        box.appendChild(cbDivider());
        box.appendChild(cbRow(MARLE_I18N.bookingTotal, p.total || ''));
        box.appendChild(cbRow(MARLE_I18N.nonRefundable + ' (' + (p.percent || 0) + '%)', p.fee || ''));
        if (Number(p.balance_amount) > 0) {
            box.appendChild(cbRow(MARLE_I18N.stillPayable, p.balance));
        } else if (Number(p.balance_amount) === 0 && Number(p.fee_amount) > 0) {
            box.appendChild(cbRow(MARLE_I18N.coveredByDeposit, p.deposit || ''));
        }

        var blocked = el('cbBlocked');
        if (d.can_cancel) {
            blocked.classList.add('d-none');
            blocked.textContent = '';
            el('cbReasonBlock').classList.remove('d-none');
            el('cbConfirmBtn').classList.remove('d-none');
        } else {
            blocked.textContent = d.reason || MARLE_I18N.cannotCancelOnline;
            blocked.classList.remove('d-none');
            el('cbReasonBlock').classList.add('d-none');
            el('cbConfirmBtn').classList.add('d-none');
        }
    }

    window.cbConfirm = function () {
        if (!cbBookingId) return;
        var btn = el('cbConfirmBtn');
        cbError('cbCancelError', '');
        btn.disabled = true;

        fetch('cancel_booking.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                mode: 'cancel',
                email: (el('cbEmail').value || '').trim(),
                status_token: (el('cbToken').value || '').trim(),
                booking_id: String(cbBookingId),
                cancel_reason: el('cbReason').value,
                cancel_reason_detail: (el('cbDetail').value || '').trim()
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (!d.success) { cbError('cbCancelError', d.message); return; }
            el('cbDoneMsg').textContent = d.message;
            try {
                var url = new URL('booking_status.php', window.location.href);
                url.searchParams.set('email', (el('cbEmail').value || '').trim());
                url.searchParams.set('status_token', (el('cbToken').value || '').trim());
                el('cbDoneStatusLink').href = url.href;
            } catch (e) { /* keep the plain link */ }
            cbStep('done');
        })
        .catch(function () {
            btn.disabled = false;
            cbError('cbCancelError', 'Could not reach the server. Please try again.');
        });
    };
})();</script>
</body>

</html>
