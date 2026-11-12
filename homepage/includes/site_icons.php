<?php
/**
 * site_icons.php — the hotel's identity in the tab, in one place.
 *
 * Included by home.php, about.php, contact.php and booking_status.php so the
 * four pages cannot drift apart on something as small as a favicon. Paths are
 * relative to homepage/, where every page lives; the bare-domain fallback at
 * the web root (../favicon.ico) covers a tab opened before any page has been
 * parsed, and any browser that ignores the link tags.
 *
 * THE MARK
 *   A brass M on a maroon tile — the console's monogram, flattened to one
 *   letter. The old icon was the logo's M with its crown and curled stem; at
 *   16px those details turned the letter into a smudge, so the tab read as a
 *   dark square. The tile is the site's own maroon (#7A2A15) with mustard
 *   lettering (#E8B33F), which holds its shape down to 16 and still reads
 *   as the wordmark at 512.
 *
 *   The masters live in ../img/brand/ as an SVG plus a 16/32/180/192/512 PNG
 *   set and a 16+32+48 .ico; the copies in pics/ are what these tags point at,
 *   because they are served with the homepage. A maskable variant (full-bleed
 *   background, art inside the safe zone) is declared in site.webmanifest so
 *   Android does not crop the M.
 */
?>
<link rel="icon" type="image/svg+xml" href="pics/marle-mark.svg">
<link rel="icon" href="pics/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="pics/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="pics/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="pics/apple-touch-icon.png">
<link rel="manifest" href="site.webmanifest">
<meta name="theme-color" content="#6C2314">
