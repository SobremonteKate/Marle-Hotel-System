<?php
// delete_event.php — removes a calendar event (superadmin variant).
// Hardened: POST-only + CSRF (mirrors calendar/delete_event.php).
include "../superadmin/auth.php";
include "../dbconnect.php";

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include "../csrf.php";
require_once __DIR__ . '/../includes/log_action.php';
require_csrf_token();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$id = intval($_POST['id'] ?? 0);
if ($id > 0) {
    $stmt = $conn->prepare("DELETE FROM calendartbl WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        log_event($conn, 'calendar_event_deleted', 'calendar_event', $id, 'success');
    }
    $stmt->close();
}
?>
