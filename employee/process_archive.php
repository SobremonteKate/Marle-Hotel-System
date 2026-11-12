<?php
// TODO: Employee limited permissions need deeper checks on this page/action.
// ============================================================
//  admin/process_archive.php
//  Archive or restore a single request.
//  Guards against archiving pending requests.
//
//  POST params:
//    action     — archive | restore
//    request_id — int
// ============================================================
session_start();
header('Content-Type: application/json');

include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../employee/auth.php');
require_once __DIR__ . '/../includes/log_action.php';

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}

$action    = trim($_POST['action']     ?? '');
$requestId = (int)($_POST['request_id'] ?? 0);

if ($requestId < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
    exit();
}

if ($action === 'archive') {

    // Guard: never archive a pending request — staff must action it first.
    $check = $conn->prepare(
        "SELECT status FROM requests WHERE id = ? LIMIT 1"
    );
    $check->bind_param('i', $requestId);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Request not found.']);
        exit();
    }
    if ($row['status'] === 'pending') {
        echo json_encode([
            'success' => false,
            'message' => 'Pending requests cannot be archived. Please approve or deny it first.',
        ]);
        exit();
    }

    $stmt = $conn->prepare(
        "UPDATE requests
         SET    is_archived = 1,
                archived_at = NOW()
         WHERE  id     = ?
           AND  status != 'pending'"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        log_event($conn, 'request_archived', 'request', $requestId, 'success');
        echo json_encode(['success' => true, 'message' => 'Request archived successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not archive request.']);
    }

} elseif ($action === 'restore') {

    $stmt = $conn->prepare(
        "UPDATE requests
         SET    is_archived = 0,
                archived_at = NULL
         WHERE  id = ?"
    );
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
        log_event($conn, 'request_restored', 'request', $requestId, 'success');
        echo json_encode(['success' => true, 'message' => 'Request restored to active tabs.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not restore request.']);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
