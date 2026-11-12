<?php
/**
 * entry_screen.php — the hotel's front door, once per session.
 *
 * Ships hidden. JavaScript reveals it, so with no script — or after any script
 * failure — the homepage underneath is simply the page. It dismisses itself
 * after 2.2s (the hairline at its foot is the timer), on Enter, on Escape, on a
 * click, or on the first scroll, and pauses while it is being read.
 *
 * Requires: $totalRooms (from includes/room_tariff.php) and site_t().
 */
$entryRooms = (int)($totalRooms ?? 0);
?>
<div class="entry" data-entry hidden>
    <div class="entry__panel" role="dialog" aria-modal="true" aria-labelledby="entryTitle" aria-describedby="entryLead">
        <img class="entry__mark" src="pics/logo1.png" width="800" height="217" alt="<?= site_t('Marle Hotel & Restaurant', 'Marle Hotel & Restaurant') ?>">

        <p class="entry__place">Sabutan Ibayo &middot; Silang, Cavite</p>

        <h2 class="entry__title" id="entryTitle">
            <?php if ($entryRooms > 0): ?>
                <span class="entry__count"><?= $entryRooms ?></span> <?= site_t('rooms and a kitchen in Silang, Cavite.', 'kwarto at kusina sa Silang, Cavite.') ?>
            <?php else: ?>
                <?= site_t('Rooms and a kitchen in Silang, Cavite.', 'Mga kwarto at kusina sa Silang, Cavite.') ?>
            <?php endif; ?>
        </h2>

        <p class="entry__lead" id="entryLead"><?= site_t(
            'Open since 2015. Book by the night or by the hour — the front desk confirms every room.',
            'Bukas mula 2015. Mag-book bawat gabi o bawat oras — kinukumpirma ng front desk ang bawat kwarto.'
        ) ?></p>

        <div class="entry__actions">
            <button type="button" class="mh-btn mh-btn--mustard" data-entry-enter>
                <?= site_t('Enter the hotel', 'Pumasok sa hotel') ?>
            </button>
            <button type="button" class="entry__tour" data-entry-tour>
                <?= site_t('Show me around', 'Ipakita sa akin') ?>
                <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </button>
        </div>

        <div class="entry__timer" aria-hidden="true"><span></span></div>
    </div>
</div>
