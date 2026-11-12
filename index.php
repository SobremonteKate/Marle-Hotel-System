<?php
// ============================================================
//  index.php — the site's front door.
//
//  The public website lives in homepage/home.php, which loads its
//  includes and assets relative to that folder. It is therefore a
//  redirect, not an include: including it from the web root would make
//  its own `include('../dbconnect.php')` resolve against the document
//  root's parent and fail.
//
//  Locally the site is opened at /MarleHotel/homepage/home.php; on a
//  host where the app sits in public_html this makes the bare domain
//  (https://yourdomain.com/) land on the booking page instead of a
//  directory listing. 302 (not 301) so the target can change later
//  without browsers caching the old one.
// ============================================================

header('Location: homepage/home.php', true, 302);
exit;
