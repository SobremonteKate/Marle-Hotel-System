<?php
// ============================================================
//  employee/transaction_history.php
//  Front desk no longer has a standalone transactions ledger —
//  employees only see surface-level room totals and collect
//  payments from the Occupied Rooms cards on GuestTokens.php.
//  This URL is kept as an auth-gated redirect so old bookmarks
//  and links do not 404.
// ============================================================
require_once __DIR__ . '/../dbconnect.php';

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.transactions');
require_once __DIR__ . '/../access_control.php';

require_role('employee');

header('Location: GuestTokens.php');
exit();
