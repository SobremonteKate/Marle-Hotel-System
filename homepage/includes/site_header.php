<?php
/**
 * site_header.php — the public site's one header.
 *
 * Used by home.php, about.php, contact.php and booking_status.php. Keeping it
 * in one place is what stops the four copies from drifting: the old pages each
 * carried their own Bootstrap navbar, and the brand's absolute centring plus a
 * 70px fixed bar (whose collapsing panel had no background of its own) were
 * exactly the mobile glitch that made the menu unreadable over the hero.
 *
 * Set before including:
 *   $navActive   'home' | 'rooms' | 'dining' | 'story' | 'contact' | ''
 *   $navShowLang false to hide the EN | TL switch (booking_status.php is
 *                English-only, so a switch there would promise a translation
 *                the page does not deliver)
 *   $navBookHref optional, defaults to home.php#bookingArea
 */
require_once dirname(__DIR__, 2) . '/includes/site_i18n.php';

$navActive       = $navActive       ?? '';
$navShowLang     = $navShowLang     ?? true;
$navBookHref     = $navBookHref     ?? 'home.php#bookingArea';
$navForceEnglish = $navForceEnglish ?? false;

// booking_status.php is English-only, so its header must not come out half
// Tagalog just because the visitor chose Tagalog elsewhere on the site.
$t = $navForceEnglish
    ? static function (string $en, string $tl): string { return $en; }
    : 'site_t';

$navLinks = [
    'home'    => ['href' => 'home.php',            'label' => $t('Home', 'Tahanan')],
    'rooms'   => ['href' => 'home.php#rooms',      'label' => $t('Rooms', 'Mga Kwarto')],
    'dining'  => ['href' => 'home.php#dining',     'label' => $t('Dining', 'Kainan')],
    'story'   => ['href' => 'about.php',           'label' => $t('Our story', 'Ang Kwento Namin')],
    'contact' => ['href' => 'contact.php',         'label' => $t('Contact', 'Makipag-ugnayan')],
];

$isCurrent = static function (string $key) use ($navActive): string {
    return $navActive === $key ? ' aria-current="page"' : '';
};
?>
<a class="skip-link" href="#main"><?= $t('Skip to content', 'Lumaktaw sa nilalaman') ?></a>

<header class="site-header" data-site-header>
    <div class="site-header__inner">
        <a class="site-header__brand" href="home.php" aria-label="<?= $t('Marle Hotel & Restaurant — home', 'Marle Hotel & Restaurant — tahanan') ?>">
            <img src="pics/logo1.png" width="800" height="217" alt="<?= $t('Marle Hotel & Restaurant', 'Marle Hotel & Restaurant') ?>">
        </a>

        <nav class="site-nav" aria-label="<?= $t('Primary', 'Pangunahin') ?>">
            <?php foreach ($navLinks as $key => $link): ?>
                <a class="site-nav__link" href="<?= htmlspecialchars($link['href'], ENT_QUOTES) ?>"<?= $isCurrent($key) ?>><?= $link['label'] ?></a>
            <?php endforeach; ?>

        </nav>

        <div class="site-header__actions">
            <?php if ($navShowLang): ?>
                <div class="lang-switch-item"><?php site_lang_switch(); ?></div>
            <?php endif; ?>
            <a class="btn-nav" href="<?= htmlspecialchars($navBookHref, ENT_QUOTES) ?>"><?= $t('Book now', 'Mag-book Ngayon') ?></a>
            <button class="site-header__toggle" type="button" data-menu-open
                    aria-controls="siteMenu" aria-expanded="false"
                    aria-label="<?= $t('Open menu', 'Buksan ang menu') ?>">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
</header>

<dialog class="site-menu" id="siteMenu" aria-label="<?= $t('Site menu', 'Menu ng site') ?>">
    <div class="site-menu__top">
        <a class="site-menu__brand" href="home.php">
            MARLE
            <small><?= $t('Hotel & Restaurant', 'Hotel at Restaurant') ?></small>
        </a>
        <button class="site-menu__close" type="button" data-menu-close
                aria-label="<?= $t('Close menu', 'Isara ang menu') ?>">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>

    <nav class="site-menu__nav" aria-label="<?= $t('Primary', 'Pangunahin') ?>">
        <?php foreach ($navLinks as $key => $link): ?>
            <a href="<?= htmlspecialchars($link['href'], ENT_QUOTES) ?>"<?= $isCurrent($key) ?>>
                <?= $link['label'] ?>
                <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="site-menu__foot">
        <?php if ($navShowLang): ?>
            <?php site_lang_switch(); ?>
        <?php endif; ?>
        <a class="mh-btn" href="<?= htmlspecialchars($navBookHref, ENT_QUOTES) ?>"><?= $t('Book a room', 'Mag-book ng kwarto') ?></a>
        <a class="site-menu__call" href="tel:09170000000">
            <i class="bi bi-telephone" aria-hidden="true"></i> 0917 000 0000
        </a>
    </div>
</dialog>
