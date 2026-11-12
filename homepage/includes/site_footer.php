<?php
/**
 * site_footer.php — the public site's one footer.
 *
 * The old homepage footer carried a "Contact Us" form with no action (it
 * submitted nowhere) and Terms / Privacy links pointing at "#". Both were
 * removed rather than restyled: the footer now offers the ways to reach the
 * hotel that actually work, and the booking actions that actually exist.
 */
require_once dirname(__DIR__, 2) . '/includes/site_i18n.php';
require_once dirname(__DIR__, 2) . '/includes/server_clock.php';

// The hotel's calendar year (database clock where there is a connection), never
// the visitor's device and never PHP's own zone. clock_hygiene_checks.php
// flags bare date() calls on request paths for exactly this reason.
$year = substr(marle_hotel_date(0), 0, 4);
?>
<footer class="site-footer">
    <div class="shell">
        <div class="site-footer__grid">
            <div class="site-footer__brand">
                <img src="pics/logo1.png" width="800" height="217" alt="<?= site_t('Marle Hotel & Restaurant', 'Marle Hotel & Restaurant') ?>" loading="lazy" decoding="async">
                <p><?= site_t('Serving our community since 2015 — not just here to meet expectations, but to exceed them.', 'Naglilingkod sa aming komunidad mula 2015 — hindi lang upang tuparin ang inaasahan, kundi upang higitan pa ito.') ?></p>
                <div class="site-footer__social">
                    <a href="https://www.facebook.com/MARLE-Hotel-Restaurant-264513383682017/" target="_blank" rel="noopener" aria-label="<?= site_t('Marle Hotel on Facebook', 'Marle Hotel sa Facebook') ?>">
                        <i class="bi bi-facebook" aria-hidden="true"></i>
                    </a>
                    <a href="tel:09170000000" aria-label="<?= site_t('Call the front desk', 'Tumawag sa front desk') ?>">
                        <i class="bi bi-telephone" aria-hidden="true"></i>
                    </a>
                </div>
            </div>

            <div>
                <h2><?= site_t('Stay', 'Pamamalagi') ?></h2>
                <ul>
                    <li><a href="home.php#rooms"><?= site_t('Rooms & rates', 'Mga kwarto at presyo') ?></a></li>
                    <li><a href="home.php#dining"><?= site_t('Restaurant', 'Restaurant') ?></a></li>
                    <li><a href="home.php#diningMenu"><?= site_t('Menu', 'Menu') ?></a></li>
                    <li><a href="home.php#amenities"><?= site_t('Amenities', 'Mga amenidad') ?></a></li>
                    <li><a href="about.php"><?= site_t('Our story', 'Ang kwento namin') ?></a></li>
                </ul>
            </div>

            <div>
                <h2><?= site_t('Visit', 'Bisitahin') ?></h2>
                <address>
                    Sabutan Ibayo,<br>
                    Silang, Cavite<br>
                    <a href="tel:09170000000">0917 000 0000</a><br>
                    <a href="mailto:frontdesk@marlehotel.example">frontdesk@marlehotel.example</a>
                </address>
            </div>

            <div>
                <h2><?= site_t('Booking', 'Pag-book') ?></h2>
                <ul>
                    <li><a href="home.php#bookingArea"><?= site_t('Check availability', 'Tingnan ang availability') ?></a></li>
                    <li><a href="booking_status.php"><?= site_t('Booking status', 'Status ng booking') ?></a></li>
                    <li><a href="contact.php"><?= site_t('Plan a celebration', 'Magplano ng selebrasyon') ?></a></li>
                    <li><a href="home.php#policies"><?= site_t('Deposit & cancellation', 'Deposito at pagkansela') ?></a></li>
                    <li><a href="home.php#tour" data-tour-replay><?= site_t('Replay the website tour', 'Ulitin ang tour sa site') ?></a></li>
                </ul>
            </div>
        </div>

        <div class="site-footer__bottom">
            <span>&copy; <?= $year ?> Marle Hotel &amp; Restaurant. <?= site_t('All rights reserved.', 'Lahat ng karapatan ay nakalaan.') ?></span>
            <span><?= site_t('Sabutan Ibayo, Silang, Cavite', 'Sabutan Ibayo, Silang, Cavite') ?></span>
        </div>
    </div>
</footer>
