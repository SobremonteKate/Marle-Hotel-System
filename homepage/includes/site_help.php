<?php
/**
 * site_help.php — the floating FAQ concierge.
 *
 * Answers, not conversation: a launcher, a search box, chips and a short set
 * of answers built from the site's own facts. Every peso and every room count
 * comes from includes/room_tariff.php, the deposit and cancellation
 * percentages from includes/booking_deposit.php — nothing is typed in here, so
 * the concierge cannot quote a price the booking engine would not charge.
 *
 * No network calls and no API key: the whole thing is this file plus
 * site-guide.js.
 *
 * Requires: $conn (mysqli) and site_t(). Includes the tariff itself when the
 * page has not already built it.
 */
// Our Story and Contact are otherwise static pages, so they arrive here
// without a connection. Open one rather than making the concierge a homepage
// feature only — and if the database is down, print nothing at all instead of
// taking the page with it.
if (!isset($conn) || !($conn instanceof mysqli)) {
    include __DIR__ . '/../../dbconnect.php';
}
if (!($conn instanceof mysqli)) {
    return;
}

require_once __DIR__ . '/../../includes/booking_deposit.php';
require_once __DIR__ . '/room_tariff.php';

$helpPhone     = '0917 000 0000';
$helpPhoneHref = 'tel:09170000000';
$helpEmail     = 'frontdesk@marlehotel.example';
$helpDeposit   = (int)marle_deposit_rate($conn);
$helpForfeit   = (int)MARLE_CANCELLATION_FORFEIT_RATE;
$helpGarages   = (int)($roomCards['With Garage']['count'] ?? 0);

// One rates line per type, e.g. "Without Garage — ₱350 / 3 hrs · ₱3,500 / night (sleeps 2)".
$helpRateLines = [];
$helpHourlyLines = [];
foreach ($roomOrder as $type) {
    $card = $roomCards[$type] ?? null;
    if (!$card) {
        continue;
    }
    $helpRateLines[] = '<li><strong>' . htmlspecialchars($card['label']) . '</strong> — '
        . $peso($card['three']) . ' ' . site_t('for 3 hours', 'para sa 3 oras')
        . ' &middot; ' . $peso($card['nightly']) . ' ' . site_t('a night', 'bawat gabi')
        . ' <span class="help__meta">(' . site_t('sleeps', 'hanggang') . ' ' . (int)$card['sleeps'] . ')</span></li>';
    $ext = (float)($ratesForJs[$type]['extension'] ?? 0);
    if ($ext > 0) {
        $helpHourlyLines[] = '<li><strong>' . htmlspecialchars($card['label']) . '</strong> — '
            . $peso($ext) . ' ' . site_t('per extra hour', 'bawat dagdag na oras') . '</li>';
    }
}
$helpRatesHtml  = '<ul class="help__list">' . implode('', $helpRateLines) . '</ul>';
$helpHourlyHtml = '<ul class="help__list">' . implode('', $helpHourlyLines) . '</ul>';

$helpTopics = [
    [
        'id'    => 'rates',
        'chip'  => site_t('Rates', 'Presyo'),
        'title' => site_t('What a room costs', 'Magkano ang kwarto'),
        'keys'  => ['rate', 'price', 'cost', 'how much', 'magkano', 'presyo', 'room', 'kwarto', 'cheap'],
        'html'  => '<p>' . site_t('Two ways to stay, both priced by the booking desk itself:', 'Dalawang paraan ng pag-stay, parehong presyo mula sa booking desk:') . '</p>'
                 . $helpRatesHtml
                 . '<p class="help__note">' . site_t('The full tariff is on the homepage.', 'Nasa homepage ang buong listahan.') . '</p>',
    ],
    [
        'id'    => 'hourly',
        'chip'  => site_t('By the hour', 'Bawat oras'),
        'title' => site_t('Staying for a few hours', 'Ilang oras lang'),
        'keys'  => ['hour', 'hourly', 'short stay', 'oras', '3 hours', 'three hours', 'extension', 'extend'],
        'html'  => '<p>' . site_t('The shortest hourly stay is three hours. Choose a start time on the booking desk, then extend hour by hour if you need longer.', 'Tatlong oras ang pinakamaikling stay bawat oras. Pumili ng oras ng simula sa booking desk, at magdagdag ng oras kung kailangan pa.') . '</p>'
                 . $helpHourlyHtml
                 . '<p class="help__note">' . site_t('An hourly stay must end before midnight.', 'Dapat matapos bago maghatinggabi ang bawat oras na stay.') . '</p>',
    ],
    [
        'id'    => 'deposit',
        'chip'  => site_t('Deposit', 'Deposito'),
        'title' => site_t('Confirming your room', 'Pagkumpirma ng kwarto'),
        'keys'  => ['deposit', 'downpayment', 'down payment', 'gcash', 'bank', 'pay', 'reserve', 'deposito', 'bayad'],
        'html'  => '<p>' . sprintf(
            site_t('A %d%% reservation deposit confirms your room.', '%d%% na deposito ang nagkukumpirma ng iyong kwarto.'),
            $helpDeposit
        ) . ' ' . site_t('Pay by GCash or bank transfer, then upload the reference number and a photo of the receipt on your booking status page.', 'Magbayad sa GCash o bank transfer, pagkatapos i-upload ang reference number at larawan ng resibo sa booking status page.') . '</p>'
                 . '<p><a class="help__link" href="booking_status.php">' . site_t('Open booking status', 'Buksan ang booking status') . ' <i class="bi bi-arrow-right" aria-hidden="true"></i></a></p>'
                 . '<p class="help__note">' . site_t('The front desk checks the receipt and marks the deposit verified.', 'Sinusuri ng front desk ang resibo at minamarkahang verified ang deposito.') . '</p>',
    ],
    [
        'id'    => 'cancel',
        'chip'  => site_t('Cancelling', 'Pagkansela'),
        'title' => site_t('If your plans change', 'Kung magbago ang plano'),
        'keys'  => ['cancel', 'cancellation', 'refund', 'forfeit', 'kansela', 'rebook'],
        'html'  => '<p>' . sprintf(
            site_t('%d%% of the booking total is non-refundable if you cancel.', '%d%% ng kabuuang bayad ay hindi na maibabalik kung magkansela.'),
            $helpForfeit
        ) . ' ' . site_t('You can cancel from your booking status page — the exact amount is shown before you confirm.', 'Maaari kang magkansela sa booking status page — ipinapakita ang eksaktong halaga bago ka magkumpirma.') . '</p>'
                 . '<p class="help__note">' . site_t('A booking that has neither checked in nor called by 4:00 PM on the arrival day — one hour after the 3:00 PM check-in time — is cancelled automatically as a no-show.', 'Ang booking na hindi pa nag-check in at hindi tumawag bago mag-4:00 PM sa araw ng pagdating — isang oras pagkatapos ng 3:00 PM check-in — ay awtomatikong kinakansela bilang no-show.') . '</p>',
    ],
    [
        'id'    => 'checkin',
        'chip'  => site_t('Check-in', 'Check-in'),
        'title' => site_t('Check-in and check-out', 'Pagpasok at paglabas'),
        'keys'  => ['check in', 'check-in', 'checkin', 'check out', 'checkout', 'time', 'what time', 'oras', 'pumasok', 'labas'],
        'html'  => '<p>' . site_t('Overnight rooms check in from 3:00 PM and check out by 12:00 noon. Hourly rooms begin at the start time you choose.', 'Ang overnight na kwarto ay pumapasok mula 3:00 PM at umaalis bago mag-12:00 ng tanghali. Ang bawat oras na kwarto ay nagsisimula sa oras na pipiliin mo.') . '</p>'
                 . '<p class="help__note">' . site_t('The front desk is staffed 24 hours.', '24 oras na may tao sa front desk.') . '</p>',
    ],
    [
        'id'    => 'parking',
        'chip'  => site_t('Parking', 'Paradahan'),
        'title' => site_t('Parking and garages', 'Paradahan at garahe'),
        'keys'  => ['park', 'parking', 'garage', 'car', 'vehicle', 'garahe', 'sasakyan', 'motor'],
        'html'  => '<p>' . sprintf(
            site_t('%d of the %d rooms come with their own garage behind a private roller door — book “With Garage” for those. The other rooms use the open parking in front of the hotel.', 'May sariling garahe sa likod ng pribadong roller door ang %d sa %d kwarto — piliin ang “May Garahe”. Ang ibang kwarto ay gumagamit ng paradahan sa harap ng hotel.'),
            $helpGarages,
            (int)$totalRooms
        ) . '</p>',
    ],
    [
        'id'    => 'amenities',
        'chip'  => site_t('In the room', 'Sa kwarto'),
        'title' => site_t('What every room has', 'Ano ang nasa bawat kwarto'),
        'keys'  => ['wifi', 'wi-fi', 'aircon', 'air con', 'tv', 'shower', 'hot water', 'bathtub', 'amenities', 'amenidad', 'internet'],
        'html'  => '<p>' . site_t('Every room is air-conditioned with free Wi-Fi. Selected rooms add a hot-water shower, a cable smart TV or a bathtub — ask the front desk when you book.', 'May aircon at libreng Wi-Fi ang bawat kwarto. May piling kwarto na may mainit na shower, cable smart TV o bathtub — sabihin sa front desk kapag mag-book.') . '</p>'
                 . '<p class="help__note">' . site_t('PWD and senior discounts are available at the desk.', 'May diskwento para sa PWD at senior sa desk.') . '</p>',
    ],
    [
        'id'    => 'status',
        'chip'  => site_t('My booking', 'Booking ko'),
        'title' => site_t('Checking or changing a booking', 'Tingnan o baguhin ang booking'),
        'keys'  => ['status', 'my booking', 'token', 'confirmation', 'booking', 'reference', 'cancel booking', 'reschedule'],
        'html'  => '<p>' . site_t('Your confirmation carries a private status token. Open the booking status page, enter your email and that token, and you can see the room, the total, upload a deposit receipt or cancel.', 'May pribadong status token ang iyong kumpirmasyon. Buksan ang booking status page, ilagay ang email at token, at makikita ang kwarto, kabuuang bayad, makakapag-upload ng resibo o makakapagkansela.') . '</p>'
                 . '<p><a class="help__link" href="booking_status.php">' . site_t('Open booking status', 'Buksan ang booking status') . ' <i class="bi bi-arrow-right" aria-hidden="true"></i></a></p>',
    ],
    [
        'id'    => 'location',
        'chip'  => site_t('Where we are', 'Saan kami'),
        'title' => site_t('Finding the hotel', 'Paghahanap sa hotel'),
        'keys'  => ['where', 'location', 'address', 'directions', 'map', 'silang', 'cavite', 'saan', 'tirahan', 'mapa'],
        'html'  => '<p>' . site_t('Sabutan Ibayo, Silang, Cavite — on the Silang road, a short drive from the Tagaytay turnoff.', 'Sabutan Ibayo, Silang, Cavite — sa kalsada ng Silang, maikling biyahe mula sa liko ng Tagaytay.') . '</p>'
                 . '<p><a class="help__link" href="https://www.google.com/maps?q=Sabutan%20Ibayo%2C%20Silang%2C%20Cavite" target="_blank" rel="noopener">' . site_t('Open in Maps', 'Buksan sa Maps') . ' <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></p>',
    ],
    [
        'id'    => 'contact',
        'chip'  => site_t('Talk to us', 'Kausapin kami'),
        'title' => site_t('Reaching the front desk', 'Pag-abot sa front desk'),
        'keys'  => ['call', 'phone', 'contact', 'email', 'facebook', 'message', 'tawag', 'telepono', 'kausap'],
        'html'  => '<p>' . site_t('The front desk answers around the clock.', 'Sumasagot ang front desk sa lahat ng oras.') . '</p>'
                 . '<ul class="help__list">'
                 . '<li><a href="' . $helpPhoneHref . '">' . $helpPhone . '</a></li>'
                 . '<li><a href="mailto:' . $helpEmail . '">' . $helpEmail . '</a></li>'
                 . '<li><a href="https://www.facebook.com/MARLE-Hotel-Restaurant-264513383682017/" target="_blank" rel="noopener">Facebook — Marle Hotel &amp; Restaurant</a></li>'
                 . '</ul>',
    ],
];

$helpData = [
    'title'    => site_t('Ask about your stay', 'Magtanong tungkol sa iyong stay'),
    'prompt'   => site_t('Common questions, answered from this site’s own rates and policies.', 'Mga karaniwang tanong, sinagot mula sa sariling presyo at patakaran ng site.'),
    'search'   => site_t('Search: deposit, parking, rates…', 'Maghanap: deposito, paradahan, presyo…'),
    'chipsAll' => site_t('All questions', 'Lahat ng tanong'),
    'noResult' => site_t('That one is better answered by a person.', 'Mas mainam na tao ang makasagot niyan.'),
    'call'     => site_t('Call the front desk', 'Tumawag sa front desk'),
    'email'    => site_t('Email', 'Email'),
    'topics'   => $helpTopics,
];
?>
<button class="help-launcher" type="button" data-help-launcher aria-expanded="false" aria-controls="helpPanel" hidden>
    <i class="bi bi-question-lg" aria-hidden="true"></i>
    <span><?= site_t('Help', 'Tulong') ?></span>
</button>

<section class="help" id="helpPanel" data-help hidden role="dialog" aria-modal="true" aria-labelledby="helpTitle">
    <header class="help__head">
        <div>
            <p class="help__eyebrow"><?= site_t('Front desk', 'Front desk') ?> &middot; <?= $helpPhone ?></p>
            <h2 class="help__title" id="helpTitle"><?= site_t('Ask about your stay', 'Magtanong tungkol sa iyong stay') ?></h2>
        </div>
        <button class="help__close" type="button" data-help-close aria-label="<?= site_t('Close help', 'Isara ang tulong') ?>">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </header>

    <div class="help__search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" data-help-search autocomplete="off"
               aria-label="<?= site_t('Search the questions', 'Hanapin sa mga tanong') ?>"
               placeholder="<?= site_t('Search: deposit, parking, rates…', 'Maghanap: deposito, paradahan, presyo…') ?>">
    </div>

    <div class="help__chips" data-help-chips role="group" aria-label="<?= site_t('Common questions', 'Karaniwang tanong') ?>"></div>

    <div class="help__body" data-help-body aria-live="polite"></div>

    <footer class="help__foot">
        <a href="<?= $helpPhoneHref ?>"><i class="bi bi-telephone" aria-hidden="true"></i><?= site_t('Call the front desk', 'Tumawag sa front desk') ?></a>
        <a href="mailto:<?= $helpEmail ?>"><i class="bi bi-envelope" aria-hidden="true"></i><?= site_t('Email', 'Email') ?></a>
    </footer>
</section>

<script>
    window.MARLE_HELP = <?= json_encode($helpData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>
