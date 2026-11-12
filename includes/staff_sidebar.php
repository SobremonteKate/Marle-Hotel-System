<?php
/**
 * MarleHotel — the one staff sidebar (the key rack).
 *
 * WHY THIS FILE EXISTS
 *   The sidebar used to be pasted into every staff page: 38 copies across
 *   admin/, superadmin/ and employee/, and they had already drifted apart —
 *   `admin/adminDB.php` had no Requests item at all, several Front Desk pages
 *   carried no Settings item, superadmin's User Manual page highlighted
 *   *Settings* instead of itself, and Room Board and User Manual were each
 *   linked from their own page and nowhere else, so a page that exists was
 *   unreachable by navigation.
 *
 *   2026-10-19: this is the second pass. The rail is now the hotel's key rack —
 *   an ink field, a brass plate, and the work grouped the way the desk actually
 *   divides it (Today, Rooms & guests, Money & stock, Management). The groups
 *   are a wrapper around the same role lists; nothing about who may see what
 *   changed.
 *
 * HOW IT IS USED — one identical line in every page:
 *   <?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>
 *
 * HOW IT KNOWS THINGS (deliberately not passed in, so the include line stays
 * identical in all three role folders — see role_parity_checks.php):
 *   role        the folder the requesting script lives in (admin / superadmin /
 *               employee), falling back to $_SESSION['usertype'].
 *   active item the requesting script's filename, mapped to a nav key.
 *   base URL    derived from $_SERVER['SCRIPT_NAME'], so links work both under
 *               http://localhost/MarleHotel/ and at a domain root.
 *
 * OVERRIDES — a page may set either before including:
 *   $marleNavRole    'admin' | 'superadmin' | 'employee'
 *   $marleNavActive  'dashboard' | 'requests' | 'tokens' | 'transactions' |
 *                    'inventory' | 'guests' | 'roomboard' | 'calendar' |
 *                    'reviews' | 'manual' | 'settings' | 'systemlog'
 *   $marleNavQuiet   true to render no sidebar at all (print views)
 *
 * WHAT IT EXPECTS FROM THE PAGE
 *   $requestBadgeValue  optional — the unanswered-Requests badge, set by
 *                       <role>/requests_badge.php. Missing is treated as 0.
 *   ui_lang_switch()    optional — the English | Tagalog switch, from
 *                       includes/ui_i18n.php.
 *   #logoutConfirmModal must exist in the page (unchanged from before).
 *
 * Nothing here reads the clock, the device locale or any request input beyond
 * the script's own path — see clock_hygiene_checks.php.
 */

// ── Small helpers (guarded: a page could include this twice) ────────────────

if (!function_exists('marle_sidebar_base')) {
    /** The app's URL path, without a trailing slash: '' at a domain root. */
    function marle_sidebar_base(): string
    {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $base   = rtrim(dirname($dir), '/.');
        return ($base === '/' || $base === '\\') ? '' : $base;
    }
}

if (!function_exists('marle_sidebar_role')) {
    /** Which sidebar to draw: the folder the requesting script lives in. */
    function marle_sidebar_role(): string
    {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir    = basename(rtrim(dirname($script), '/'));
        if (in_array($dir, ['admin', 'superadmin', 'employee'], true)) {
            return $dir;
        }
        $type = (string)($_SESSION['usertype'] ?? '');
        return in_array($type, ['admin', 'superadmin', 'employee'], true) ? $type : 'employee';
    }
}

if (!function_exists('marle_sidebar_active_key')) {
    /** Which item to mark active: the requesting script's filename. */
    function marle_sidebar_active_key(): string
    {
        static $map = [
            'adminDB.php'             => 'dashboard',
            'requests.php'            => 'requests',
            'GuestTokens.php'         => 'tokens',
            'transaction_history.php' => 'transactions',
            'inventory.php'           => 'inventory',
            'customerRC.php'          => 'guests',
            'room_board.php'          => 'roomboard',
            'calendar.php'            => 'calendar',
            'reviews.php'             => 'reviews',
            'userManual.php'          => 'manual',
            'settings.php'            => 'settings',
            'activity_log.php'        => 'systemlog',
            // The archive pages keep the section they belong to highlighted,
            // exactly as they did before (archive → Guest Record, inventory
            // archive → Inventory).
            'archive.php'             => 'guests',
            'archiveInventory.php'    => 'inventory',
        ];
        return $map[basename(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')))] ?? '';
    }
}

if (!function_exists('marle_sidebar_role_label')) {
    /** The word the hotel uses for each console: Owner / Manager / Front desk. */
    function marle_sidebar_role_label(string $role): string
    {
        return ['superadmin' => 'Owner', 'admin' => 'Manager', 'employee' => 'Front desk'][$role] ?? 'Staff';
    }
}

if (!empty($marleNavQuiet)) {
    return;
}

$marleSidebarRole   = $marleNavRole ?? marle_sidebar_role();
$marleSidebarActive = $marleNavActive ?? marle_sidebar_active_key();
$marleSidebarBase   = marle_sidebar_base();
$marleBadge         = (int)($requestBadgeValue ?? 0);
$marleRoleLabel     = marle_sidebar_role_label($marleSidebarRole);
$marleRoleTl        = ['superadmin' => 'May-ari', 'admin' => 'Manager', 'employee' => 'Front desk'][$marleSidebarRole] ?? 'Staff';
$marleUserName      = (string)($_SESSION['username'] ?? '');

$marleInitials = '';
if ($marleUserName !== '') {
    $marleParts = preg_split('/[\s._-]+/', $marleUserName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach (array_slice($marleParts, 0, 2) as $marlePart) {
        $marleInitials .= strtoupper(substr($marlePart, 0, 1));
    }
    if ($marleInitials === '') {
        $marleInitials = strtoupper(substr($marleUserName, 0, 2));
    }
}

/**
 * key, label, file, icon, badge?  — one list per role.
 * Order here is the order inside each group; the groups themselves are below.
 */
$marleSidebarAll = [
    'dashboard'    => ['Dashboard',    'adminDB.php',             'bi-grid',            false],
    'requests'     => ['Requests',     'requests.php',            'bi-chat-dots',       true],
    'tokens'       => ['Guest Tokens', 'GuestTokens.php',         'bi-key',             false],
    'transactions' => ['Transactions', 'transaction_history.php', 'bi-receipt',         false],
    'inventory'    => ['Inventory',    'inventory.php',           'bi-clipboard-check', false],
    'guests'       => ['Guest Record', 'customerRC.php',          'bi-people',          false],
    'roomboard'    => ['Room Board',   'room_board.php',          'bi-grid-3x3-gap',    false],
    'calendar'     => ['Calendar',     'calendar.php',            'bi-calendar',        false],
    'reviews'      => ['Reviews',      'reviews.php',             'bi-star',            false],
    'manual'       => ['User Manual',  'userManual.php',          'bi-journal-text',    false],
    'settings'     => ['Settings',     'settings.php',            'bi-gear',            false],
    'systemlog'    => ['System Log',   'activity_log.php',        'bi-card-checklist',  false],
];

// What each role may see. The Front Desk has no transactions page (it is a 302
// by design), no Room Board page (its copy is a 404 by design), no System Log
// and no backup — so its sidebar offers none of them.
//
// Kept out of every sidebar on purpose (2026-10-17): Room Board and User Manual
// are reference screens, not daily work, and they made the list longer than it
// needed to be. The pages still exist at their URLs (the manual is also linked
// from Settings); they are simply no longer nav items. Settings is a manager
// screen — catalog and user management — so the Front Desk no longer sees it
// either: the page itself already refuses every mutation to an employee.
$marleSidebarByRole = [
    'employee' => ['dashboard', 'requests', 'tokens', 'inventory', 'guests',
                   'calendar', 'reviews'],
    'admin' => ['dashboard', 'requests', 'tokens', 'transactions', 'inventory',
                'guests', 'calendar', 'reviews', 'settings'],
    'superadmin' => ['dashboard', 'requests', 'tokens', 'transactions', 'inventory',
                     'guests', 'calendar', 'reviews', 'settings', 'systemlog'],
];

// The groups. Membership is filtered by the role list above, so a group with
// nothing in it is not drawn.
//
// Every key a role may see MUST also appear in one of these groups: the rail
// draws the groups, not the role list, so a key left out of a group is silently
// dropped from every console. Reviews slipped through exactly that way when the
// groups were introduced (2026-10-21) — it was still listed for all three roles
// and simply vanished from the nav. Reviews are guest feedback, so they sit with
// the guests.
$marleSidebarGroups = [
    ['Today', 'Ngayon', ['dashboard', 'requests']],
    ['Rooms & guests', 'Silid at bisita', ['tokens', 'roomboard', 'guests', 'calendar', 'reviews']],
    ['Money & stock', 'Bayad at imbentaryo', ['transactions', 'inventory']],
    ['Management', 'Pamamahala', ['settings', 'systemlog', 'manual']],
];

$marleSidebarKeys = $marleSidebarByRole[$marleSidebarRole] ?? $marleSidebarByRole['employee'];
$marleSidebarRolePath = $marleSidebarBase . '/' . $marleSidebarRole;
$marleSidebarLogo     = $marleSidebarBase . '/img/logo1.png';
?>
    <!-- Sidebar — one source for all three roles (includes/staff_sidebar.php) -->
    <nav class="navbar navbar-expand-md staff-rail p-0" aria-label="<?= function_exists('ui_t') ? ui_t('Staff navigation', 'Nabigasyon ng staff') : 'Staff navigation' ?>">
        <div class="container-fluid flex-md-column align-items-stretch p-0">

            <!-- Responsive Top Bar -->
            <div class="staff-rail__mobile d-flex d-md-none align-items-center justify-content-between w-100">
                <button class="staff-rail__toggle" type="button" data-bs-toggle="collapse"
                    data-bs-target="#sidebarMenu" aria-label="<?= function_exists('ui_t') ? ui_t('Open navigation menu', 'Buksan ang menu ng nabigasyon') : 'Open navigation menu' ?>" aria-controls="sidebarMenu">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>

                <img class="staff-rail__mobile-logo" src="<?= htmlspecialchars($marleSidebarLogo, ENT_QUOTES) ?>" width="112" alt="Marle Hotel">

                <span class="staff-rail__mobile-role"><?= htmlspecialchars($marleRoleLabel, ENT_QUOTES) ?></span>
            </div>

            <!-- Sidebar -->
            <div class="collapse navbar-collapse align-items-stretch" id="sidebarMenu">

                <!-- Brass plate + wordmark -->
                <div class="staff-rail__brand d-none d-md-flex">
                    <span class="staff-rail__mark" aria-hidden="true"><i class="bi bi-key"></i></span>
                    <span class="staff-rail__wordmark">
                        <span class="staff-rail__name">Marle</span>
                        <span class="staff-rail__console"><?= htmlspecialchars($marleRoleLabel, ENT_QUOTES) ?> <?= function_exists('ui_t') ? ui_t('console', 'console') : 'console' ?></span>
                    </span>
                </div>

<?php foreach ($marleSidebarGroups as [$marleGroupLabel, $marleGroupTl, $marleGroupKeys]):
    $marleGroupVisible = array_values(array_filter($marleGroupKeys, static fn($k) => in_array($k, $marleSidebarKeys, true)));
    if (!$marleGroupVisible) {
        continue;
    }
?>
                <p class="staff-rail__group"><?= function_exists('ui_t') ? ui_t($marleGroupLabel, $marleGroupTl) : $marleGroupLabel ?></p>
                <ul class="navbar-nav flex-md-column w-100 staff-rail__list">
<?php foreach ($marleGroupVisible as $marleKey):
    [$marleLabel, $marleFile, $marleIcon, $marleHasBadge] = $marleSidebarAll[$marleKey];
    $marleIsActive = ($marleKey === $marleSidebarActive);
    $marleHref = $marleSidebarRolePath . '/' . $marleFile;
?>
                    <li class="nav-item">
                        <a class="nav-link staff-rail__link<?= $marleIsActive ? ' active' : '' ?>"
                           href="<?= htmlspecialchars($marleHref, ENT_QUOTES) ?>"
                           data-nav-key="<?= htmlspecialchars($marleKey, ENT_QUOTES) ?>"<?= $marleIsActive ? ' aria-current="page"' : '' ?>>
                            <i class="bi <?= $marleIcon ?>" aria-hidden="true"></i>
                            <span class="staff-rail__label"><?= $marleLabel ?></span>
<?php if ($marleHasBadge): ?>
                            <span class="badge rounded-pill notification-badge<?= $marleIsActive ? ' is-on-active' : '' ?><?= $marleBadge > 0 ? ' pulse' : '' ?>"><?= $marleBadge ?></span>
<?php endif; ?>
                        </a>
                    </li>
<?php endforeach; ?>
                </ul>
<?php endforeach; ?>

                <!-- Identity plate + language + logout -->
                <div class="staff-rail__foot mt-auto">
                    <div class="staff-rail__who">
                        <span class="staff-rail__initials" aria-hidden="true"><?= htmlspecialchars($marleInitials !== '' ? $marleInitials : 'MH', ENT_QUOTES) ?></span>
                        <span class="staff-rail__who-text">
                            <span class="staff-rail__who-name"><?= htmlspecialchars($marleUserName !== '' ? $marleUserName : 'Staff', ENT_QUOTES) ?></span>
                            <span class="staff-rail__who-role"><?= function_exists('ui_t') ? ui_t($marleRoleLabel, $marleRoleTl) : $marleRoleLabel ?></span>
                        </span>
                    </div>
<?php if (function_exists('ui_lang_switch')): ?>
                    <div class="staff-rail__lang"><?php ui_lang_switch(); ?></div>
<?php endif; ?>
                    <!-- Replay the console tour (js/staff-tour.js). Hidden by CSS
                         until the script boots, so a browser without JS never
                         shows a button that does nothing. -->
                    <button class="staff-rail__tour" type="button" data-staff-tour>
                        <i class="bi bi-compass" aria-hidden="true"></i>
                        <span><?= function_exists('ui_t') ? ui_t('Tour', 'Gabay') : 'Tour' ?></span>
                    </button>
                    <button class="staff-rail__logout" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal" type="button">
                        <i class="bi bi-box-arrow-left" aria-hidden="true"></i>
                        <span><?= function_exists('ui_t') ? ui_t('Logout', 'Mag-log out') : 'Logout' ?></span>
                    </button>
                </div>

            </div>
        </div>
    </nav>
