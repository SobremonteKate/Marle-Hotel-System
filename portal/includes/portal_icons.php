<?php
/**
 * portal_icons.php — the guest portal's mark in the tab, in one place.
 *
 * The same monogram as the public site, drawn in the portal's own palette —
 * reception green (#263431) with brass (#C38A45) instead of maroon and gold —
 * so a guest with both tabs open can tell the guest area from the brochure.
 *
 * The old icon was a photograph of the reception bell, then a recolour of the
 * public logo with its crown and curled stem. Both were unreadable at 16px.
 * The portal files are now rendered straight from marle-mark.svg (also in
 * pics/), so the letter is the same shape at every size and the palette is the
 * only difference from the public tab.
 *
 * Included by includes/header.php (every portal screen) and guestLogin.php, so
 * the two cannot drift on something this small. Paths are relative to portal/.
 */
?>
<link rel="icon" type="image/svg+xml" href="pics/marle-mark.svg">
<link rel="icon" href="pics/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="pics/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="pics/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="pics/apple-touch-icon.png">
<link rel="manifest" href="portal.webmanifest">
<meta name="theme-color" content="#263431">
