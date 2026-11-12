<?php
/**
 * room_viewer.php — the four photographs behind "Check this room".
 *
 * The tariff folio can only show one picture at a time, so the details a guest
 * actually asks about at the desk — how many sleep here, the kitchen, the
 * bathroom — had nowhere to live. This is that room, opened as a dialog.
 *
 * Ships as an empty skeleton: site-nav.js fills the name, the photograph, the
 * thumbnails and the rates from the JSON below when the button is pressed. With
 * no JavaScript the dialog never opens, which is exactly what the button did
 * before. A photo whose file is missing (includes/room_tariff.php decides) is
 * drawn as a captioned plate rather than a broken thumbnail.
 *
 * Requires: $roomCards, $roomOrder, $peso, site_t().
 */

$marleRoomView = [];
foreach ($roomOrder as $type) {
    $card = $roomCards[$type] ?? null;
    if (!$card) {
        continue;
    }
    $marleRoomView[$type] = [
        'name'      => $card['label'],
        'room'      => $card['room'],
        'meta'      => $card['meta'],
        'blurb'     => $card['blurb'],
        'three'     => $peso($card['three']) . ' · 3 ' . site_t('hrs', 'oras'),
        'extra'     => $card['extension'] > 0
            ? $peso($card['extension']) . ' · ' . site_t('each extra hour', 'bawat dagdag na oras')
            : '',
        'night'     => $peso($card['nightly']) . ' · ' . site_t('night', 'gabi'),
        'amenities' => $card['amenities'],
        'photos'    => $card['gallery'],
    ];
}
?>
<dialog class="roomview" id="roomViewer" aria-labelledby="roomViewTitle">
    <div class="roomview__sheet">
        <header class="roomview__bar">
            <div class="roomview__id">
                <p class="roomview__eyebrow">
                    <?= site_t('Room', 'Kwarto') ?>
                    <span class="roomview__number" data-rv-room></span>
                </p>
                <h2 class="roomview__title" id="roomViewTitle" data-rv-name></h2>
            </div>
            <button type="button" class="roomview__close" data-rv-close
                    aria-label="<?= site_t('Close the room', 'Isara ang kwarto') ?>">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>

        <div class="roomview__body">
            <figure class="roomview__stage">
                <img data-rv-img src="" alt="" width="1024" height="768" decoding="async">
                <span class="roomview__counter" data-rv-count aria-hidden="true"></span>
                <figcaption class="roomview__credit" data-rv-credit hidden></figcaption>
            </figure>

            <div class="roomview__aside">
                <div class="roomview__thumbs" role="group"
                     aria-label="<?= site_t('Photographs of this room', 'Mga larawan ng kwartong ito') ?>"
                     data-rv-thumbs></div>                    <div class="roomview__facts">
                    <div class="roomview__copy">
                        <p class="roomview__meta" data-rv-meta></p>
                        <p class="roomview__blurb" data-rv-blurb></p>
                        <ul class="roomview__amenities" data-rv-amenities
                            aria-label="<?= site_t('What is in this room', 'Ano ang nasa kwartong ito') ?>"></ul>
                    </div>
                    <p class="roomview__rates">
                        <span data-rv-three></span>
                        <span data-rv-extra></span>
                        <span data-rv-night></span>
                    </p>
                </div>
            </div>
        </div>

        <footer class="roomview__foot">
            <button type="button" class="mh-btn mh-btn--mustard" data-rv-book>
                <?= site_t('Check these dates', 'Tingnan ang mga petsang ito') ?>
                <i class="bi bi-arrow-down-right" aria-hidden="true"></i>
            </button>
            <a class="roomview__call" href="tel:09170000000">
                <i class="bi bi-telephone" aria-hidden="true"></i>
                <?= site_t('Call the front desk', 'Tumawag sa front desk') ?>
            </a>
        </footer>
    </div>
</dialog>

<script type="application/json" id="roomViewerData"><?= json_encode(
    $marleRoomView,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
) ?></script>
