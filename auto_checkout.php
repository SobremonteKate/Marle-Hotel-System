<?php
require_once __DIR__ . '/portal/guest_session.php';
// Must be resolved from this file's own directory: the portal calls this script
// as an HTTP endpoint (`fetch('../auto_checkout.php')`), so the working directory
// is the app root and a bare '../dbconnect.php' pointed above the project.
require_once __DIR__ . '/dbconnect.php';

// The portal footer calls this endpoint on every tick; a dead database has to answer in JSON.
require_once __DIR__ . '/includes/db_guard.php';
marle_db_guard($conn, 'json');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$customerId = null;
if (!empty($_SESSION['customer_id'])) {
    $customerId = (int)$_SESSION['customer_id'];
} elseif (!empty($_SESSION['guest_token'])) {
    $token = $_SESSION['guest_token'];
    $lookup = $conn->prepare(
        "SELECT c.id
         FROM customerstbl c
         JOIN guest_tokens g ON g.room_number = c.room_no
         WHERE g.token = ?
           AND c.status = 'checked-in'
         ORDER BY c.id DESC
         LIMIT 1"
    );
    $lookup->bind_param('s', $token);
    $lookup->execute();
    $result = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if ($result) {
        $customerId = (int)$result['id'];
    }
}

if (empty($customerId)) {
    http_response_code(400);
    echo json_encode(['error' => 'No active session']);
    exit();
}

// ── The server decides whether this stay is really over ───────────────────────
// This endpoint is called by the portal when its countdown reaches zero. That
// countdown now runs on the server's clock (includes/server_clock.php), but the
// caller is still a browser, so the decision is made again here, against the token
// itself. Before this guard a device whose clock was set forward could — and did —
// check a guest out of a stay that was still running, silently: the room went back
// to the pool while portal/includes/session_guard.php still reported the token as
// live on every page load. A clock a guest controls must not end a stay.
$tokenStillActive = false;
$liveSecsLeft     = null;
if (!empty($_SESSION['guest_token'])) {
    $live = $conn->prepare(
        "SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS secs_left
         FROM guest_tokens
         WHERE token = ?
         LIMIT 1"
    );
    if ($live) {
        $live->bind_param('s', $_SESSION['guest_token']);
        $live->execute();
        $liveRow = $live->get_result()->fetch_assoc();
        $live->close();
        if ($liveRow) {
            $liveSecsLeft     = (int)$liveRow['secs_left'];
            $tokenStillActive = $liveSecsLeft > 0;
        }
    }
}

if ($tokenStillActive) {
    http_response_code(409);
    echo json_encode([
        'success'      => false,
        'still_active' => true,
        'secs_left'    => $liveSecsLeft,
        'message'      => 'This stay has not expired yet — nothing was checked out.',
    ]);
    exit();
}

// ── Archive all chat messages for this stay ───────────────────────────────────
// Stamps archived_at = NOW() so the 60-day retention clock starts at checkout.
// archived_at IS NULL guard makes this safe to call more than once.
$archive = $conn->prepare(
    "UPDATE chat_messages
     SET archived_at = NOW()
     WHERE customer_id = ?
       AND archived_at IS NULL"
);
$archive->bind_param('i', $customerId);
$archive->execute();
$archive->close();

// Fallback: also archive chat messages by room_number for rows with NULL customer_id
$roomNo = null;
$roomLookup = $conn->prepare(
    "SELECT room_no FROM customerstbl WHERE id = ? LIMIT 1"
);
$roomLookup->bind_param('i', $customerId);
$roomLookup->execute();
$roomLookup->bind_result($roomNo);
$roomLookup->fetch();
$roomLookup->close();

if ($roomNo) {
    $archiveByRoom = $conn->prepare(
        "UPDATE chat_messages
         SET archived_at = NOW()
         WHERE room_number = ?
           AND customer_id IS NULL
           AND archived_at IS NULL"
    );
    $archiveByRoom->bind_param('s', $roomNo);
    $archiveByRoom->execute();
    $archiveByRoom->close();
}

// ── Archive all requests for this customer ────────────────────────────────────
$archiveReqs = $conn->prepare(
    "UPDATE requests
     SET is_archived = 1,
         archived_at = NOW()
     WHERE customer_id = ?
       AND is_archived = 0
       AND status != 'pending'"
);
$archiveReqs->bind_param('i', $customerId);
$archiveReqs->execute();
$archiveReqs->close();
// ─────────────────────────────────────────────────────────────────────────────

$stmt = $conn->prepare(
    "UPDATE customerstbl
     SET status = 'checked-out',
         checked_out_at = COALESCE(checked_out_at, NOW())
     WHERE id = ?
       AND status <> 'checked-out'"
);
$stmt->bind_param("i", $customerId);
$stmt->execute();
$updated = $stmt->affected_rows > 0;
$stmt->close();

session_unset();
session_destroy();

echo json_encode(['success' => true, 'updated' => $updated]);
