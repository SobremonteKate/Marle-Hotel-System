<?php
/**
 * staff_tour.php — the walkthrough of the staff console.
 *
 * WHY THIS EXISTS
 *   The console has thirty-nine screens, one rail and one desk strip. Nothing
 *   on the page tells a new front-desk hire that the number beside Requests is
 *   a queue, that the language switch is remembered, or that the bell and the
 *   account plate are the same pair on every screen. This is that explanation,
 *   once, over the real interface — not a manual page nobody opens.
 *
 * HOW IT IS USED
 *   Required from includes/staff_theme.php, so every staff page carries it and
 *   no page can forget. It prints a config object and a deferred script; the
 *   tour builds its own markup, so with JavaScript off the console is unchanged
 *   and no empty dialog sits in the page.
 *
 * THE FOUR PROPERTIES THAT MATTER
 *   • Once. It offers itself only on the dashboard, only after the page has
 *     settled, and only if this browser has not seen it for this role. The flag
 *     is localStorage, so it survives the session but not a different login.
 *   • Replayable. The rail's Tour button starts it on any screen, any time.
 *   • Honest about the screen. A step whose target is not on this page (the
 *     language switch is inside the collapsed menu on a phone) is dropped
 *     rather than pointed at nothing.
 *   • Bilingual. The console's phrase table supplies every word, including the
 *     buttons the script builds — a Tagalog page must not open an English tour.
 *
 * The dashboard is the only page that auto-starts; $marleTourAuto is decided
 * by the script name, not by the route the browser typed.
 */

if (!function_exists('marle_tour_say')) {
    function marle_tour_say(string $english, string $tagalog): string
    {
        return function_exists('ui_t') ? ui_t($english, $tagalog) : $english;
    }
}

$marleTourScript = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$marleTourAuto   = basename($marleTourScript) === 'adminDB.php';
$marleTourRole   = (string)($_SESSION['usertype'] ?? 'employee');
if (!in_array($marleTourRole, ['admin', 'superadmin', 'employee'], true)) {
    $marleTourRole = 'employee';
}

$marleStaffTourSteps = [
    [
        'sel'   => '.staff-rail',
        'title' => marle_tour_say('Your console', 'Ang iyong console'),
        'text'  => marle_tour_say(
            'Every screen is grouped by the job: the day\'s work, rooms and guests, money and stock, then management. The pale card is the screen you are on.',
            'Naka-grupo ang bawat screen ayon sa gawain: trabaho ng araw, kwarto at bisita, pera at stock, at pamamahala. Ang maliwanag na card ang screen na kinalalagyan mo.'
        ),
    ],
    [
        'sel'   => '.desk-strip',
        'title' => marle_tour_say('Where you are', 'Kung nasaan ka'),
        'text'  => marle_tour_say(
            'The hotel\'s own date, the name of the screen and the trail back to the dashboard. It is the same strip on all thirty-nine screens.',
            'Ang petsa ng hotel, pangalan ng screen at daan pabalik sa dashboard. Pareho ito sa lahat ng tatlumpu\'t siyam na screen.'
        ),
    ],
    [
        'sel'        => '.dashboard-attention-tile',
        'selDesktop' => '[data-nav-key="requests"]',
        'title'      => marle_tour_say('The live queue', 'Ang live na pila'),
        'text'       => marle_tour_say(
            'A number beside Requests is work still waiting on the front desk: approvals, cancellations and guest messages. It counts down as the desk clears them.',
            'Ang numero sa tabi ng Requests ay trabahong hinihintay pa ng front desk: approvals, cancellation at mensahe ng bisita. Bumababa ito habang nililinis ng desk.'
        ),
    ],
    [
        'selDesktop' => '.staff-rail__lang',
        'title'      => marle_tour_say('English or Tagalog', 'English o Tagalog'),
        'text'       => marle_tour_say(
            'The switch translates the screen you are on and remembers your choice for every staff page.',
            'Isinasalin ng switch ang screen na kinalalagyan mo at naaalala ang pinili mo sa lahat ng staff page.'
        ),
    ],
    [
        'sel'   => '.desk-strip__side',
        'title' => marle_tour_say('Notifications and you', 'Notification at ikaw'),
        'text'  => marle_tour_say(
            'The bell carries new requests; the plate is your account and role. The pair stays in this corner on every screen.',
            'Ang bell ang may dalang bagong request; ang plate ay account at role mo. Nasa sulok na ito sila palagi sa bawat screen.'
        ),
    ],
    [
        'sel'   => '.staff-footer',
        'title' => marle_tour_say('The footer', 'Ang footer'),
        'text'  => marle_tour_say(
            'Terms, the console signature and the mark, at the bottom of every screen. You can replay this tour from the Tour button in the menu.',
            'Terms, pirma ng console at ang marka, sa ilalim ng bawat screen. Maaari mong ulitin ang tour sa Tour button sa menu.'
        ),
    ],
];
?>
<script>
    window.MARLE_STAFF_TOUR = <?= json_encode([
        'auto'    => $marleTourAuto,
        'role'    => $marleTourRole,
        'version' => defined('MARLE_STAFF_ASSET_VERSION') ? MARLE_STAFF_ASSET_VERSION : '1',
        'steps'   => $marleStaffTourSteps,
        'i18n'    => [
            'eyebrow' => marle_tour_say('Quick tour', 'Mabilisang tour'),
            'skip'    => marle_tour_say('Skip tour', 'Laktawan ang tour'),
            'close'   => marle_tour_say('Close the tour', 'Isara ang tour'),
            'back'    => marle_tour_say('Back', 'Bumalik'),
            'next'    => marle_tour_say('Next', 'Susunod'),
            'done'    => marle_tour_say('Done', 'Tapos'),
            'counter' => marle_tour_say('%d of %d', '%d sa %d'),
            'step'    => marle_tour_say('Step %d of %d', 'Hakbang %d ng %d'),
        ],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="../js/staff-tour.js?v=<?= htmlspecialchars(defined('MARLE_STAFF_ASSET_VERSION') ? MARLE_STAFF_ASSET_VERSION : '1', ENT_QUOTES) ?>" defer></script>
