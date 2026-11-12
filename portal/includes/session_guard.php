<?php
require_once __DIR__ . '/../guest_session.php';
include __DIR__ . '/../../dbconnect.php';
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../../includes/db_guard.php';
marle_db_guard($conn, 'page');

function clearGuestSession(): void
{
    unset(
        $_SESSION['guest_token'],
        $_SESSION['room_number'],
        $_SESSION['hours_of_stay'],
        $_SESSION['expires_at'],
        $_SESSION['expires_at_unix'],
        $_SESSION['customer_id']
    );
}

function markGuestCheckedOut(): void
{
    global $conn;
    if (empty($_SESSION['guest_token'])) {
        return;
    }

    $stmt = $conn->prepare(
        "UPDATE customerstbl c
         JOIN guest_tokens g ON g.room_number = c.room_no
         SET c.status = 'checked-out',
             c.checked_out_at = COALESCE(c.checked_out_at, NOW())
         WHERE g.token = ?
           AND c.status = 'checked-in'"
    );
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('s', $_SESSION['guest_token']);
    $stmt->execute();
    $stmt->close();
}

if (empty($_SESSION['guest_token'])) {
    header('Location: guestLogin.php');
    exit();
}

$chk = $conn->prepare(
    "SELECT expires_at, UNIX_TIMESTAMP(expires_at) AS expires_at_unix
     FROM guest_tokens
     WHERE token = ? AND expires_at > NOW()
     LIMIT 1"
);
$chk->bind_param('s', $_SESSION['guest_token']);
$chk->execute();
$live = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$live) {
    $customerId = (int)($_SESSION['customer_id'] ?? 0);
    $roomNo = $_SESSION['room_number'] ?? null;

    if ($customerId > 0) {
        $arc = $conn->prepare(
            "UPDATE chat_messages SET archived_at = NOW()
             WHERE customer_id = ? AND archived_at IS NULL"
        );
        $arc->bind_param('i', $customerId);
        $arc->execute();
        $arc->close();

        $arcReq = $conn->prepare(
            "UPDATE requests SET is_archived = 1, archived_at = NOW()
             WHERE customer_id = ? AND is_archived = 0 AND status != 'pending'"
        );
        $arcReq->bind_param('i', $customerId);
        $arcReq->execute();
        $arcReq->close();
    }

    if ($roomNo) {
        $arcRoom = $conn->prepare(
            "UPDATE chat_messages SET archived_at = NOW()
             WHERE room_number = ? AND customer_id IS NULL AND archived_at IS NULL"
        );
        $arcRoom->bind_param('s', $roomNo);
        $arcRoom->execute();
        $arcRoom->close();
    }

    markGuestCheckedOut();
    clearGuestSession();
    header('Location: guestLogin.php?expired=1');
    exit();
}

$_SESSION['expires_at'] = $live['expires_at'];
$_SESSION['expires_at_unix'] = (int)$live['expires_at_unix'];

$roomNumber = htmlspecialchars($_SESSION['room_number'] ?? '—', ENT_QUOTES, 'UTF-8');
$hoursOfStay = (int)($_SESSION['hours_of_stay'] ?? 0);
$expiresUnix = (int)$_SESSION['expires_at_unix'];
$totalSecs = $hoursOfStay * 3600;
$customerId = (int)($_SESSION['customer_id'] ?? 0);

if (!defined('STAFF_GREETING_MESSAGE')) {
    define(
        'STAFF_GREETING_MESSAGE',
        "Welcome! We're glad to have you with us. Please don't hesitate to message us "
        . "if you need anything — including any questions about your bill."
    );
}

$tokenRoom = $_SESSION['room_number'] ?? null;
$greetingMessage = STAFF_GREETING_MESSAGE;
if ($tokenRoom) {
    $dupCheck = $conn->prepare(
        "SELECT id FROM chat_messages
         WHERE room_number = ? AND sender = 'admin' AND message = ? LIMIT 1"
    );
    $dupCheck->bind_param('ss', $tokenRoom, $greetingMessage);
    $dupCheck->execute();
    $isDuplicate = $dupCheck->get_result()->num_rows > 0;
    $dupCheck->close();

    if (!$isDuplicate) {
        $insertGreeting = $conn->prepare(
            "INSERT INTO chat_messages
             (room_number, customer_id, sender, message, attachment_path, attachment_name, attachment_type)
             VALUES (?, ?, 'admin', ?, NULL, NULL, NULL)"
        );
        $insertGreeting->bind_param('sis', $tokenRoom, $customerId, $greetingMessage);
        $insertGreeting->execute();
        $insertGreeting->close();
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    clearGuestSession();
    header('Location: guestLogin.php');
    exit();
}

if (!defined('ROOM')) {
    define('ROOM', $_SESSION['room_number']);
}
