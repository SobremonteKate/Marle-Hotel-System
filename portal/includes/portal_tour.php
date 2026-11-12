<?php
/**
 * portal_tour.php — the five-step tour of the guest dashboard.
 *
 * The flag is set by guestLogin.php for every successful login, so a guest who
 * comes back for a second stay is walked through the portal again; it is
 * consumed by portal.php so a refresh in the same session does not repeat it.
 *
 * Markup and script ship together: with no JavaScript the tour simply never
 * appears, and the page underneath is unchanged.
 */
$tourPending = $portalTourPending ?? false;

$portalTour = [
    [
        'sel'   => '[data-tour="stay"]',
        'title' => ui_t('Your stay', 'Ang iyong pamamalagi'),
        'text'  => ui_t(
            'Your room number, the hours you booked and the time left on your stay live here. The brass tag is your key.',
            'Narito ang numero ng kwarto, bilang ng oras na binook at ang natitirang oras. Ang brass na tag ay ang iyong susi.'
        ),
    ],
    [
        'sel'   => '[data-tour="actions"]',
        'title' => ui_t('Four things you will use', 'Apat na gagamitin mo'),
        'text'  => ui_t(
            'Services, Menu, Extend and the Guide — everything else in the portal starts from one of these.',
            'Serbisyo, Menu, Magdagdag at Gabay — lahat ng iba sa portal ay nagsisimula sa isa sa mga ito.'
        ),
    ],
    [
        'sel'   => '[data-tour="bill"]',
        'title' => ui_t('Your running total', 'Ang kabuuang bayad'),
        'text'  => ui_t(
            'Unpaid charges appear here as you order. Nothing is charged to your card — the front desk settles it when you check out.',
            'Lumalabas dito ang mga hindi pa bayad habang umoorder ka. Walang sinisingil sa card mo — sa front desk babayaran pag-check out.'
        ),
    ],
    [
        'sel'   => '#chatToggleBtn',
        'title' => ui_t('Message the front desk', 'Mag-message sa front desk'),
        'text'  => ui_t(
            'Tap the chat button to ask about your bill, towels or anything else. Staff answer from the front desk.',
            'Pindutin ang chat para magtanong tungkol sa bayad, tuwalya o iba pa. Sumasagot ang staff mula sa front desk.'
        ),
    ],
    [
        'sel'        => '.navbar-bottom-mobile',
        'selDesktop' => '.desktop-nav-links',
        'title'      => ui_t('Getting around', 'Paglibot sa portal'),
        'text'       => ui_t(
            'Home, Services, Extend, Menu and Guide. You can replay this tour any time from the Guide.',
            'Tahanan, Serbisyo, Magdagdag, Menu at Gabay. Maaari mong ulitin ang tour na ito sa Gabay.'
        ),
    ],
];
?>
<script>
    window.MARLE_PORTAL_TOUR = <?= json_encode([
        'pending' => (bool)$tourPending,
        'steps'   => $portalTour,
        // The buttons are built by the script, so their words have to travel
        // with it — otherwise a Tagalog page shows a Tagalog tour in English
        // chrome.
        'i18n'    => [
            'eyebrow' => ui_t('Quick tour', 'Mabilisang tour'),
            'skip'    => ui_t('Skip tour', 'Laktawan ang tour'),
            'back'    => ui_t('Back', 'Bumalik'),
            'next'    => ui_t('Next', 'Susunod'),
            'done'    => ui_t('Done', 'Tapos'),
            'counter' => ui_t('%d of %d', '%d sa %d'),
        ],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="js/portal-tour.js?v=20261019a" defer></script>
