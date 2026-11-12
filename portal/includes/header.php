<?php
// Server-anchored clock (includes/server_clock.php): the portal countdown below
// counts from the server's expiry, never from the guest's device clock.
require_once __DIR__ . '/../../includes/server_clock.php';
// English | Tagalog for all five guest screens: this header is the one place
// each of them passes through, and each page has already named itself in
// $activePage, so the switch starts here (includes/ui_i18n.php).
require_once __DIR__ . '/../../includes/ui_i18n.php';
$pageTitle = $pageTitle ?? 'Guest Portal';
$activePage = $activePage ?? 'home';
ui_i18n_start('portal.' . $activePage);
$showBackButton = $showBackButton ?? ($activePage !== 'home');
$breadcrumb = $breadcrumb ?? ucfirst($activePage);
$pageCss = $pageCss ?? '';
$expiresUnix = $expiresUnix ?? 0; $secsLeft = $secsLeft ?? marle_secs_left($expiresUnix);
$totalSecs = $totalSecs ?? 0;
$navItems = [
    'home' => ['portal.php', 'Home'],
    'services' => ['services.php', 'Services'],
    'extend' => ['extend.php', 'Extend'],
    'menu' => ['menu.php', 'Menu'],
    'guide' => ['hotel_guide.php', 'Guide'],
];
// The breadcrumb is a bare word joined straight onto "Dashboard / ", so the
// phrase table never sees it as its own text node — it gets an inline pair,
// keyed by the page that set $activePage.
$portalBreadcrumbs = [
    'home' => 'Tahanan',
    'services' => 'Serbisyo',
    'extend' => 'Magdagdag',
    'menu' => 'Menu',
    'guide' => 'Gabay',
];
$assetVersion = '20261025';   // bumped with the Guide head + sign-out dialog (css/refresh.css)
?>
<!DOCTYPE html>
<html lang="<?= ui_html_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:ital,opsz,wght@0,9..144,400..600;1,9..144,400..500&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="stylesheet" href="css/shared.css?v=<?= $assetVersion ?>">
    <?php if ($pageCss !== ''): ?><link rel="stylesheet" href="<?= htmlspecialchars($pageCss, ENT_QUOTES, 'UTF-8') ?>?v=<?= $assetVersion ?>"><?php endif; ?>
    <link rel="stylesheet" href="css/refresh.css?v=<?= $assetVersion ?>">
<?php include __DIR__ . '/portal_icons.php'; ?>
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<?php marle_server_clock_assets(); ?>
</head>
<body>
<nav class="navbar portal-navbar" aria-label="<?= ui_t('Guest portal navigation', 'Nabigasyon ng guest portal') ?>">
    <div class="portal-navbar__inner">
        <div class="navbar-left-group">
            <?php if ($showBackButton): ?>
                <a class="navbar-brand" href="portal.php" aria-label="<?= ui_t('Back to home', 'Bumalik sa tahanan') ?>"><i class="bi bi-arrow-left"></i></a>
            <?php else: ?>
                <a class="navbar-brand portal-logo" href="portal.php"><img src="images/logo1.png" alt="<?= ui_t('Marle Hotel', 'Marle Hotel') ?>"></a>
            <?php endif; ?>
            <div>
                <h5 class="mb-0"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h5>
                <p class="text-muted small mb-0">Dashboard / <?= htmlspecialchars(ui_t($breadcrumb, $portalBreadcrumbs[$activePage] ?? $breadcrumb), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
        <div class="d-none d-lg-flex desktop-nav-links" aria-label="<?= ui_t('Primary navigation', 'Pangunahing nabigasyon') ?>">
            <?php foreach ($navItems as $key => [$href, $label]): ?>
                <a href="<?= $href ?>" class="desktop-nav-link<?= $activePage === $key ? ' active' : '' ?>"<?= $activePage === $key ? ' aria-current="page"' : '' ?>><?= ui_t($label, ['Home' => 'Tahanan', 'Services' => 'Serbisyo', 'Extend' => 'Magdagdag', 'Menu' => 'Menu', 'Guide' => 'Gabay'][$label] ?? $label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="d-flex align-items-center navbar-right-group">
            <div class="token-countdown-card" id="navCountdown" data-left="<?= $secsLeft ?>" data-expires="<?= $expiresUnix ?>" data-total="<?= $totalSecs ?>">
                <div>
                    <div class="countdown-label"><?= ui_t('Session expires in', 'Mag-eexpire ang session sa') ?></div>
                    <div class="countdown-value" id="navCountdownText">--:--:--</div>
                    <div class="countdown-bar-bg"><div class="countdown-bar" id="navCountdownBar" style="width:100%;"></div></div>
                </div>
                <i class="bi bi-clock" aria-hidden="true"></i>
            </div>
            <?php ui_lang_switch('navbar'); ?>
            <!-- Opens the portal's own confirmation dialog instead of the browser's
                 confirm() box. The href is kept as the no-JavaScript path: with the
                 Bootstrap data-api unavailable the link still signs out. -->
            <a href="portal.php?action=logout" class="signout-btn"
               data-bs-toggle="modal" data-bs-target="#signOutModal" aria-haspopup="dialog"
               aria-label="<?= ui_t('Sign out', 'Mag-sign out') ?>">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i><span><?= ui_t('Sign Out', 'Mag-sign out') ?></span>
            </a>
        </div>
    </div>
</nav>

<!-- End-of-session confirmation. Lives in the shared header so all six portal
     screens carry the same dialog; Bootstrap's bundle (loaded in the footer)
     handles focus trapping, Escape and the backdrop. -->
<div class="modal fade signout-modal" id="signOutModal" tabindex="-1"
     aria-labelledby="signOutModalTitle" aria-describedby="signOutModalCopy" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body">
                <span class="signout-modal__icon" aria-hidden="true"><i class="bi bi-box-arrow-right"></i></span>
                <h2 class="signout-modal__title" id="signOutModalTitle"><?= ui_t('End your portal session?', 'Tapusin ang iyong sesyon sa portal?') ?></h2>
                <p class="signout-modal__copy" id="signOutModalCopy"><?= ui_t(
                    'Your room access, your requests and the chat close with it. You can sign in again with a new token from the front desk.',
                    'Magsasara rin ang access sa iyong silid, ang mga kahilingan at ang chat. Makakapag-sign in ka muli gamit ang bagong token mula sa front desk.'
                ) ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= ui_t('Stay signed in', 'Manatiling naka-sign in') ?></button>
                <a href="portal.php?action=logout" class="btn btn-primary"><?= ui_t('Sign out', 'Mag-sign out') ?></a>
            </div>
        </div>
    </div>
</div>
