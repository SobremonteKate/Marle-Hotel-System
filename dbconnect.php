<?php

require_once __DIR__ . '/config.php';

// The clock is pinned here, not only inside config.php, because config.php is the
// one file an update never replaces (it holds the hosting credentials and is
// edited by hand in hPanel). A host still running an older config.php was left on
// MySQL's own UTC, and stamped every chat message and token eight hours behind
// Philippine time. includes/db_clock.php carries the same definitions and skips
// them when config.php already has them, so this line is a no-op on a current
// config and the fix on a stale one. See that file's header.
require_once __DIR__ . '/includes/db_clock.php';

/** @var mysqli|false $conn */
$conn = marle_db_connect();

if ($conn instanceof mysqli) {
    marle_db_pin_time_zone($conn);
}

// Do not echo anything here; other endpoints expect no HTML output.

?>