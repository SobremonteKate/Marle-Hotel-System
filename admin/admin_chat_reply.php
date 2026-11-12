<?php
// admin_chat_reply.php — Admin sends a reply, with optional file attachment.
// POST fields: room, message (optional if file attached), attachment (file, optional)

include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../admin/auth_admin.php');   // exits if not authenticated
require_once __DIR__ . '/../includes/log_action.php';
require_once __DIR__ . '/../csrf.php';
require_csrf_token();

header('Content-Type: application/json');

$room    = trim($_POST['room']    ?? '');
$message = trim($_POST['message'] ?? '');

if (!$room) {
    echo json_encode(['success' => false, 'error' => 'No room specified.']);
    exit;
}

$tokenStmt = $conn->prepare(
    "SELECT customer_id
     FROM guest_tokens
     WHERE room_number = ?
       AND expires_at > NOW()
       AND customer_id IS NOT NULL
     ORDER BY id DESC
     LIMIT 1"
);
if (!$tokenStmt) {
    echo json_encode(['success' => false, 'error' => 'DB error: ' . $conn->error]);
    exit;
}
$tokenStmt->bind_param('s', $room);
$tokenStmt->execute();
$tokenRow = $tokenStmt->get_result()->fetch_assoc();
$tokenStmt->close();

if (!$tokenRow) {
    echo json_encode(['success' => false, 'error' => 'No active guest found for this room.']);
    exit;
}
$customerIdForReply = (int)$tokenRow['customer_id'];

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
        echo json_encode(['success' => false, 'error' => 'File upload error.']);
        exit;
    }
    if ($file['size'] > $maxSize) {
        echo json_encode(['success' => false, 'error' => 'File too large. Maximum size is 5 MB.']);
        exit;
    }

    // Validate MIME type using finfo (not just $_FILES['type'] which can be spoofed)
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file['tmp_name']);
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($realMime, $allowedMimes) || !in_array($ext, $allowedExts)) {
        echo json_encode([
            'success' => false,
            'error'   => 'Invalid file type. Accepted: JPG, PNG, GIF, PDF, DOCX, XLSX.',
        ]);
        exit;
    }

    // Save file
    $uploadDir = '../uploads/chat/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $safeFilename = uniqid('chat_admin_', true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $safeFilename)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save file.']);
        exit;
    }

    $attachPath = 'uploads/chat/' . $safeFilename;
    $attachName = htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8');
    $attachType = $realMime;
}

// Must have message OR attachment
if (!$message && !$attachPath) {
    echo json_encode(['success' => false, 'error' => 'Nothing to send.']);
    exit;
}

// ── Insert message ────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "INSERT INTO chat_messages
         (room_number, customer_id, sender, message, attachment_path, attachment_name, attachment_type, sent_at, is_read)
     VALUES (?, ?, 'admin', ?, ?, ?, ?, NOW(), 0)"
);
$stmt->bind_param('sissss', $room, $customerIdForReply, $message, $attachPath, $attachName, $attachType);
if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'error' => 'DB error: ' . $stmt->error]);
    exit;
}
$stmt->close();

// ── Bump chat_room_status to in_progress (unless it's already complete) ───────
$roomEsc = $conn->real_escape_string($room);
$conn->query(
    "INSERT INTO chat_room_status (room_number, status, first_opened_at)
     VALUES ('$roomEsc', 'in_progress', NOW())
     ON DUPLICATE KEY UPDATE
         first_opened_at = COALESCE(first_opened_at, NOW()),
         status          = IF(status = 'complete', 'complete', 'in_progress')"
);

log_event($conn, 'chat_reply_sent', 'chat', $customerIdForReply ?? null, 'success', 'Room ' . $room);
echo json_encode(['success' => true]);
