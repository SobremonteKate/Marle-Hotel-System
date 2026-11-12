<?php
/**
 * staff_icons.php — the hotel's mark in a staff tab, from one place.
 *
 * WHAT IT REPLACES
 *   Every console page carried `<link rel="icon" href="../img/reception.png">`
 *   — a photograph of the front-desk bell. At 16px it was a grey smudge and it
 *   did not match the maroon-and-gold mark on the public site, so a staff member
 *   with the brochure, the console and the guest portal open saw three unrelated
 *   tabs.
 *
 *   The mark now lives in img/brand/ as an SVG with PNG fallbacks, generated
 *   from marle-mark.svg. This file is the only place the console points at it.
 *
 * HOW IT IS USED — in <head>, on every staff page (one level below the root):
 *   <?php require __DIR__ . '/../includes/staff_icons.php'; ?>
 *
 *   login.php sits at the root, so it sets the base first:
 *   <?php $staffIconBase = 'img/brand'; require __DIR__ . '/includes/staff_icons.php'; ?>
 *
 *   $staffIconBase  optional — path to the icon folder, no trailing slash.
 *
 * The SVG is listed first so a modern browser takes it and ignores the rest;
 * the .ico stays for a tab opened before any page has been parsed and for
 * browsers that still ask for /favicon.ico.
 */

$marleIconBase = rtrim($staffIconBase ?? '../img/brand', '/');
?>
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($marleIconBase, ENT_QUOTES) ?>/marle-mark.svg">
    <link rel="icon" href="<?= htmlspecialchars($marleIconBase, ENT_QUOTES) ?>/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($marleIconBase, ENT_QUOTES) ?>/icon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= htmlspecialchars($marleIconBase, ENT_QUOTES) ?>/icon-16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars($marleIconBase, ENT_QUOTES) ?>/icon-180.png">
    <meta name="theme-color" content="#6C2314">
