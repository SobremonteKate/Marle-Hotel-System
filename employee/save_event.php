<?php
// save_event.php — create or update a calendar event (employee variant).
// Hardened: POST-only + CSRF (token appended by calendar.php JS).
include "../employee/auth.php";
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

$id          = trim($_POST['id'] ?? '');
$title       = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$date_from   = trim($_POST['date_from'] ?? '');
$date_to     = trim($_POST['date_to'] ?? '');
$time        = trim($_POST['time'] ?? '');
$allowed_colors = ['#009416', '#933310', '#ffb60a'];
$color = in_array($_POST['color'] ?? '', $allowed_colors, true) ? $_POST['color'] : '#ffb60a';

if (empty($date_from)) {
    die("Date From is required");
}

if (empty($date_to)) {
    $date_to = $date_from;
}

if (empty($time)) {
    $time = "00:00:00";
} else {
    $time .= ":00";
}

$start = $date_from . " " . $time;
$end   = $date_to . " " . $time;

if ($id == "") {

    $stmt = $conn->prepare("INSERT INTO calendartbl 
            (title, description, start, end, color) 
            VALUES (?, ?, ?, ?, ?)");

    $stmt->bind_param("sssss", $title, $description, $start, $end, $color);
    $stmt->execute();
    log_event($conn, 'calendar_event_created', 'calendar_event', $conn->insert_id, 'success', $title);
    $stmt->close();

} else {

    $stmt = $conn->prepare("UPDATE calendartbl 
            SET title=?, description=?, start=?, end=?, color=? 
            WHERE id=?");

    $idInt = intval($id);
    $stmt->bind_param("sssssi", $title, $description, $start, $end, $color, $idInt);
    $stmt->execute();
    log_event($conn, 'calendar_event_updated', 'calendar_event', $idInt, 'success', $title);
    $stmt->close();
}

echo "success";
?>
