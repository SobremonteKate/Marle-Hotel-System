<?php
/**
 * staff_theme.php — the one place the staff console's look is loaded.
 *
 * WHY THIS EXISTS
 *   Every staff page already linked sidebar-theme.css and its own family
 *   stylesheet, and then — further down the same <head> — a page-local
 *   <style> block that redefined the shell (body background, .navbar width,
 *   .content margin). Because the page block came last it won, and the console
 *   drifted into three palettes and two sidebar widths.
 *
 *   This include is the enforcement point. It is required immediately before
 *   </head>, so staff-console.css is the last stylesheet the browser sees: it
 *   owns the tokens, the shell and the shared components, and the page-local
 *   blocks above it can no longer contradict them.
 *
 * HOW IT IS USED — one line, immediately before </head> on every staff page:
 *   <?php require __DIR__ . '/../includes/staff_theme.php'; ?>
 *   (login.php, at the site root, links css/staff-console.css directly.)
 *
 * WHAT IT EMITS
 *   • the three faces: Playfair Display (display), DM Sans (UI),
 *     IBM Plex Mono (every figure — money, times, room numbers, tokens)
 *   • css/staff-console.css, versioned by one constant so a release cannot
 *     half-update across 39 pages
 *   • css/staff-tour.css and the tour's config + deferred script, from
 *     includes/staff_tour.php
 *   • a light color-scheme hint, so native controls do not turn dark under an
 *     OS dark theme
 *
 * Nothing here reads the clock or the database. staff_tour.php reads the
 * session's role for its localStorage key. Included twice on one page, the
 * whole block prints once.
 */

if (!defined('MARLE_STAFF_ASSET_VERSION')) {
    // Bump on every staff release. It is appended to the stylesheet URL only;
    // page-local ?v= strings elsewhere are left alone.
    define('MARLE_STAFF_ASSET_VERSION', '20261029');
}

if (empty($marleStaffThemePrinted)):
    $marleStaffThemePrinted = true;
?>
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#1f2a2a">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/staff-console.css?v=<?= MARLE_STAFF_ASSET_VERSION ?>">
    <link rel="stylesheet" href="../css/staff-tour.css?v=<?= MARLE_STAFF_ASSET_VERSION ?>">
<?php
    // The walkthrough config and its deferred script. Every staff page is one
    // level below the root, which is why the asset paths above are ../.
    require __DIR__ . '/staff_tour.php';
endif; ?>
