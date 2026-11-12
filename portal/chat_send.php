<?php
// portal/chat_send.php
// Sends a guest message — text, file attachment, or both.
// Feature 3: mirrors admin_chat_reply.php attach logic on the guest side.
require_once __DIR__ . '/guest_session.php';
include('../dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json');
require_once __DIR__ . '/../includes/log_action.php';

header('Content-Type: application/json');

// Recover guest context from the posted token if the PHP session cookie
// is missing or stale during an AJAX send.
$postedToken = strtoupper(trim((string)($_POST['guest_token'] ?? '')));
if ($postedToken !== '' && empty($_SESSION['guest_token'])) {
    $lookup = $conn->prepare(
        "SELECT gt.token,
                gt.room_number,
                gt.hours_of_stay,
                gt.expires_at,
                UNIX_TIMESTAMP(gt.expires_at) AS expires_at_unix,
                c.id AS customer_id
         FROM guest_tokens gt
         JOIN customerstbl c
           ON c.room_no = gt.room_number
          AND c.status = 'checked-in'
          AND (c.date_to IS NULL OR c.date_to > NOW())
         WHERE gt.token = ?
           AND gt.expires_at > NOW()
         ORDER BY c.id DESC
         LIMIT 1"
    );
    if ($lookup) {
        $lookup->bind_param('s', $postedToken);
        $lookup->execute();
        $guestRow = $lookup->get_result()->fetch_assoc();
        $lookup->close();

        if ($guestRow) {
            $_SESSION['guest_token']     = $guestRow['token'];
            $_SESSION['room_number']     = $guestRow['room_number'];
            $_SESSION['hours_of_stay']   = $guestRow['hours_of_stay'];
            $_SESSION['expires_at']      = $guestRow['expires_at'];
            $_SESSION['expires_at_unix'] = (int)$guestRow['expires_at_unix'];
            $_SESSION['customer_id']     = (int)$guestRow['customer_id'];
        }
    }
}

if (empty($_SESSION['guest_token']) || empty($_SESSION['room_number'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized. Please refresh the portal and try again.']);
    exit;
}

$room       = $_SESSION['room_number'];
$customerId = (int)($_SESSION['customer_id'] ?? 0);
$msg        = trim($_POST['message'] ?? '');

if ($customerId <= 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Guest session data missing. Please refresh the portal and try again.']);
    exit;
}

// ── Handle optional file upload ───────────────────────────────────────────────
$attachPath = null;
$attachName = null;
$attachType = null;

if (!empty($_FILES['attachment']['name'])) {
    $file    = $_FILES['attachment'];
    $maxSize = 5 * 1024 * 1024; // 5 MB

    $allowedMimes = [
        'image/jpeg', 'image/png', 'image/gif',
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // docx
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',       // xlsx
    ];
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'docx', 'xlsx'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'File upload error.']);
        exit;
    }
    if ($file['size'] > $maxSize) {
        echo json_encode(['error' => 'File too large. Maximum size is 5 MB.']);
        exit;
    }

    // Validate MIME via finfo — not trusting $_FILES['type'] which can be spoofed
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file['tmp_name']);
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($realMime, $allowedMimes) || !in_array($ext, $allowedExts)) {
        echo json_encode(['error' => 'Invalid file type. Accepted: JPG, PNG, GIF, PDF, DOCX, XLSX.']);
        exit;
    }

    $uploadDir = '../uploads/chat/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $safeFilename = uniqid('chat_guest_', true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $safeFilename)) {
        echo json_encode(['error' => 'Failed to save file.']);
        exit;
    }

    $attachPath = 'uploads/chat/' . $safeFilename;
    $attachName = htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8');
    $attachType = $realMime;
}

// Must have message OR attachment
if ($msg === '' && !$attachPath) {
    echo json_encode(['error' => 'Empty message']);
    exit;
}

// ── Block sends if chat has been marked complete by staff ─────────────────────
$roomEsc    = $conn->real_escape_string($room);
$statusRow  = $conn->query(
    "SELECT status FROM chat_room_status WHERE room_number = '$roomEsc' LIMIT 1"
)->fetch_assoc();
if (($statusRow['status'] ?? '') === 'complete') {
    echo json_encode(['error' => 'This chat has been closed by staff. Please contact the front desk directly.']);
    exit;
}

// ── Insert message ────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "INSERT INTO chat_messages
         (room_number, customer_id, sender, message, attachment_path, attachment_name, attachment_type)
     VALUES (?, ?, 'guest', ?, ?, ?, ?)"
);
$stmt->bind_param('sissss', $room, $customerId, $msg, $attachPath, $attachName, $attachType);

if (!$stmt->execute()) {
    echo json_encode(['error' => 'DB error: ' . $stmt->error]);
    exit;
}
$chatMsgId = $conn->insert_id; // capture before any further queries
$stmt->close();

// ── Notify staff of new guest message ────────────────────────────────────────
$notifMsg  = !empty($attachPath)
    ? "Room {$room}: sent an attachment"
    : "Room {$room}: " . mb_strimwidth($msg, 0, 60, '...');
$nStmt = $conn->prepare(
    "INSERT INTO notifications (type, reference_id, room_number, message)
     VALUES ('chat', ?, ?, ?)"
);
$nStmt->bind_param('iss', $chatMsgId, $room, $notifMsg);
$nStmt->execute();
$nStmt->close();

log_event($conn, 'portal_chat_message', 'chat', $chatMsgId ?? null, 'success', 'Room ' . $room);
echo json_encode(['success' => true]);
