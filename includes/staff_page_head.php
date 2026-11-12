<?php
/**
 * staff_page_head.php — the desk strip at the top of a staff screen.
 *
 * WHAT IT REPLACES
 *   Twenty-odd pages each pasted a slightly different version of the same three
 *   things: a title, a breadcrumb, and the notification bell. They drifted —
 *   some used <h2>, some a div.topbar-title, some <h4>; most carried
 *   style="margin-left:20px" on the heading and again on the breadcrumb; only
 *   some showed who was signed in. This is that strip, once.
 *
 * HOW IT IS USED — after the sidebar, in place of the old header block:
 *   <?php require __DIR__ . '/../includes/staff_page_head.php'; ?>
 *
 * OPTIONS (all optional; a page sets only what it needs)
 *   $staffPageTitle    'Requests'                     the page name
 *   $staffPageEyebrow  'Front desk · Monday, 19 Oct'   defaults to role + today
 *   $staffPageNote     'Generate portal access…'      one line under the title
 *   $staffPageMeta     '12 open · 4 waiting'          quiet count beside the title
 *   $staffPageCrumbs   [['Dashboard','adminDB.php'], ['Requests', null]]
 *   $staffPageActions  '<a class="btn …">…</a>'       right-hand controls
 *   $staffPageBell     false                          hide the notification bell
 *
 * WHAT IT EXPECTS
 *   staff_sidebar.php included earlier (marle_sidebar_role()), and notif_data.php
 *   already included by the page if the bell is wanted — the bell is skipped
 *   rather than guessed when those counts are absent.
 *
 * The role and the date are read from the session and the database clock, never
 * from the browser: "Today" on this strip is the hotel's today.
 */

require_once __DIR__ . '/server_clock.php';

// Not every staff page bootstraps the English | Tagalog switch (the archive,
// room board and manual screens are unilingual by design), so the partial asks
// for a translation only when the table is present.
$staffHeadSay = static function (string $english, string $tagalog): string {
    return function_exists('ui_t') ? ui_t($english, $tagalog) : $english;
};

$staffHeadTitle = (string)($staffPageTitle ?? 'Dashboard');
$staffHeadRole = function_exists('marle_sidebar_role') ? marle_sidebar_role() : 'employee';
$staffHeadRoleLabel = ['employee' => 'Front desk', 'admin' => 'Manager', 'superadmin' => 'Owner'][$staffHeadRole] ?? 'Staff';

$staffHeadToday = '';
if (function_exists('marle_hotel_date')) {
    $staffHeadStamp = strtotime(marle_hotel_date());
    if ($staffHeadStamp !== false) {
        $staffHeadToday = date('l, j F', $staffHeadStamp);
    }
}
$staffHeadEyebrow = $staffPageEyebrow ?? trim($staffHeadRoleLabel . ($staffHeadToday !== '' ? ' · ' . $staffHeadToday : ''));
$staffHeadNote = $staffPageNote ?? '';
$staffHeadMeta = $staffPageMeta ?? '';
$staffHeadActions = $staffPageActions ?? '';
$staffHeadBell = $staffPageBell ?? true;

$staffHeadCrumbs = $staffPageCrumbs ?? [['Dashboard', 'adminDB.php'], [$staffHeadTitle, null]];

$staffHeadUser = (string)($_SESSION['username'] ?? '');
$staffHeadInitials = '';
if ($staffHeadUser !== '') {
    $staffHeadParts = preg_split('/[\s._-]+/', $staffHeadUser, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach (array_slice($staffHeadParts, 0, 2) as $staffHeadPart) {
        $staffHeadInitials .= strtoupper(substr($staffHeadPart, 0, 1));
    }
    if ($staffHeadInitials === '') {
        $staffHeadInitials = strtoupper(substr($staffHeadUser, 0, 2));
    }
}
?>
<header class="desk-strip" data-staff-head="<?= htmlspecialchars($staffHeadRole, ENT_QUOTES, 'UTF-8') ?>">
    <div class="desk-strip__lead">
        <p class="desk-strip__eyebrow"><?= htmlspecialchars($staffHeadEyebrow, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="desk-strip__titlerow">
            <h2 class="desk-strip__title"><?= htmlspecialchars($staffHeadTitle, ENT_QUOTES, 'UTF-8') ?></h2>
<?php if ($staffHeadMeta !== ''): ?>
            <span class="desk-strip__meta"><?= htmlspecialchars($staffHeadMeta, ENT_QUOTES, 'UTF-8') ?></span>
<?php endif; ?>
        </div>
<?php if ($staffHeadNote !== ''): ?>
        <p class="desk-strip__note"><?= htmlspecialchars($staffHeadNote, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($staffHeadCrumbs): ?>
        <nav class="desk-strip__crumbs" aria-label="<?= htmlspecialchars($staffHeadSay('Breadcrumb', 'Landas'), ENT_QUOTES, 'UTF-8') ?>">
<?php foreach ($staffHeadCrumbs as $staffHeadIndex => [$staffHeadLabel, $staffHeadHref]): ?>
<?php if ($staffHeadIndex > 0): ?><span class="desk-strip__sep" aria-hidden="true">/</span><?php endif; ?>
<?php if ($staffHeadHref !== null): ?>
            <a href="<?= htmlspecialchars($staffHeadHref, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($staffHeadLabel, ENT_QUOTES, 'UTF-8') ?></a>
<?php else: ?>
            <span aria-current="page"><?= htmlspecialchars($staffHeadLabel, ENT_QUOTES, 'UTF-8') ?></span>
<?php endif; ?>
<?php endforeach; ?>
        </nav>
<?php endif; ?>
    </div>

    <div class="desk-strip__side">
        <div class="desk-strip__actions">
<?php if ($staffHeadActions !== ''): ?>
            <?= $staffHeadActions ?>
<?php endif; ?>
<?php
// The bell needs the counts the page's notif_data.php produced; without them it
// is omitted rather than rendered as a broken dropdown.
if ($staffHeadBell && isset($totalNotifCount) && function_exists('marle_sidebar_role')):
    $staffHeadBellPath = __DIR__ . '/../' . $staffHeadRole . '/includes/notification_dropdown.php';
    if (is_file($staffHeadBellPath)) {
        include $staffHeadBellPath;
    }
endif;
?>
        </div>
<?php if ($staffHeadInitials !== ''): ?>
        <div class="desk-user" title="<?= htmlspecialchars($staffHeadUser, ENT_QUOTES, 'UTF-8') ?>">
            <span class="desk-user__initials" aria-hidden="true"><?= htmlspecialchars($staffHeadInitials, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="desk-user__text">
                <span class="desk-user__name"><?= htmlspecialchars($staffHeadUser, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="desk-user__role"><?= htmlspecialchars($staffHeadSay($staffHeadRoleLabel, ['Front desk' => 'Front desk', 'Manager' => 'Manager', 'Owner' => 'May-ari'][$staffHeadRoleLabel] ?? $staffHeadRoleLabel), ENT_QUOTES, 'UTF-8') ?></span>
            </span>
        </div>
<?php endif; ?>
    </div>
</header>
