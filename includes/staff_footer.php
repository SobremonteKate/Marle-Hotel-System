<?php
/**
 * staff_footer.php — the one staff footer.
 *
 * WHY THIS EXISTS
 *   The footer had been pasted into 18 pages in four different wordings — "(c)
 *   2026", "© 2026", "&copy; 2026", some with a commented-out privacy link and
 *   some without — all styled as one maroon line of 13px text. It was the one
 *   piece of the console that never got the warm paper / gold / maroon treatment
 *   the sidebar and cards use, so it read as something left over from another
 *   site. This is that footer, once, in the house style.
 *
 * HOW IT IS USED — one identical line in every staff page, after the content:
 *   <?php $marleFooterTerms = true; require __DIR__ . '/../includes/staff_footer.php'; ?>
 *
 *   $marleFooterTerms  optional — true only on pages that already render the
 *                      #termsModal. The footer never invents a modal it cannot
 *                      open, so a page without one simply has no Terms link.
 *
 * WHAT IT EXPECTS
 *   staff_sidebar.php, included earlier on every page: marle_sidebar_base() and
 *   marle_sidebar_role() are reused so the footer cannot drift from the sidebar
 *   about which console this is. Falls back to the session / request path if a
 *   page (a print view) did not include the sidebar.
 */

if (!function_exists('marle_footer_base')) {
    function marle_footer_base(): string
    {
        if (function_exists('marle_sidebar_base')) {
            return marle_sidebar_base();
        }
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $base   = rtrim(dirname(dirname($script)), '/.');
        return ($base === '/' || $base === '\\') ? '' : $base;
    }
}

if (!function_exists('marle_footer_role')) {
    function marle_footer_role(): string
    {
        if (function_exists('marle_sidebar_role')) {
            return marle_sidebar_role();
        }
        $type = (string)($_SESSION['usertype'] ?? 'employee');
        return in_array($type, ['admin', 'superadmin', 'employee'], true) ? $type : 'employee';
    }
}

$marleFooterRoleKey = marle_footer_role();
$marleFooterBase    = marle_footer_base();
$marleFooterLabels  = [
    'employee'   => 'Front Desk',
    'admin'      => 'Manager',
    'superadmin' => 'Owner',
];
$marleFooterRoleLabel = $marleFooterLabels[$marleFooterRoleKey] ?? 'Staff';
$marleFooterLogo      = $marleFooterBase . '/img/logo1.png';
?>
    <!-- Footer — one design for all three roles (includes/staff_footer.php) -->
    <div class="content">
        <footer class="staff-footer" role="contentinfo">
            <div class="staff-footer-inner">
                <div class="staff-footer-brand">
                    <img class="staff-footer-mark"
                         src="<?= htmlspecialchars($marleFooterLogo, ENT_QUOTES) ?>"
                         alt="" width="40" height="40">
                    <div class="staff-footer-text">
                        <span class="staff-footer-name">Marle Hotel &amp; Restaurant</span>
                        <span class="staff-footer-role"><?= htmlspecialchars($marleFooterRoleLabel, ENT_QUOTES) ?> console</span>
                    </div>
                </div>
                <div class="staff-footer-links">
<?php if (!empty($marleFooterTerms)): ?>
                    <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms of use</a>
<?php endif; ?>
                    <span class="staff-footer-copy">&copy; 2026 Marle Hotel &amp; Restaurant</span>
                </div>
            </div>
        </footer>
    </div>
