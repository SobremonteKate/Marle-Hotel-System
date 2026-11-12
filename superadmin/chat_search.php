<?php
// ============================================================
//  admin/chat_search.php
//  Search chat rooms by message content (FULLTEXT) with an
//  optional status filter.  Called via GET from requests.php.
//
//  GET params:
//    q      — keyword string (required; use FULLTEXT boolean mode)
//    status — all | new | in_progress | complete  (default: all)
// ============================================================
session_start();
header('Content-Type: application/json');

include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../superadmin/auth.php');

$q      = trim($_GET['q']      ?? '');
$status = trim($_GET['status'] ?? 'all');

// Whitelist status values to avoid any injection vector in the
// COALESCE comparison (even though it's parameterised, keep it clean).
$allowedStatuses = ['all', 'new', 'in_progress', 'complete'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

// Nothing to search — return empty rather than a full table scan.
if ($q === '') {
    echo json_encode([]);
    exit();
}

// ── Build the query conditionally ────────────────────────────
//
// Base columns mirror what requests.php renders in the room list.
// FULLTEXT search on chat_messages.message using the existing index
// idx_ft_chat_message.  Status filter is applied via HAVING so that
// the COALESCE on the joined crs.status works correctly after GROUP BY.

$sql = "SELECT m.room_number,
               MAX(m.sent_at)                                  AS last_time,
               SUM(m.sender = 'guest' AND m.is_read = 0)       AS unread,
               (SELECT message
                FROM   chat_messages m2
                WHERE  m2.room_number = m.room_number
                  AND  m2.customer_id = gt.customer_id
                  AND  m2.archived_at IS NULL
                ORDER  BY sent_at DESC
                LIMIT  1)                                       AS last_msg,
               COALESCE(crs.status, 'new')                     AS chat_status
        FROM   guest_tokens gt
        JOIN   chat_messages m
          ON   m.room_number = gt.room_number
         AND   m.customer_id = gt.customer_id
         AND   m.archived_at IS NULL
        LEFT   JOIN chat_room_status crs ON crs.room_number = m.room_number
        WHERE  gt.expires_at > NOW()
          AND  gt.customer_id IS NOT NULL
          AND  (MATCH(m.message) AGAINST (? IN BOOLEAN MODE) OR m.room_number LIKE ?)";

$types  = 'ss';
$params = [$q, '%' . $q . '%'];

if ($status !== 'all') {
    // Filter after aggregation; MySQL/MariaDB allows referencing aliases
    // from the SELECT in HAVING, so we use the alias chat_status.
    $sql   .= " GROUP BY m.room_number, gt.customer_id
                HAVING   chat_status = ?
                ORDER BY last_time DESC";
    $types  .= 's';
    $params[] = $status;
} else {
    $sql .= " GROUP BY m.room_number, gt.customer_id
              ORDER BY last_time DESC";
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    // Prepare failed — return empty rather than expose DB errors.
    echo json_encode([]);
    exit();
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rooms = [];
while ($row = $result->fetch_assoc()) {
    $rooms[] = [
        'room_number' => $row['room_number'],
        'last_time'   => $row['last_time'],
        'unread'      => (int)$row['unread'],
        'last_msg'    => $row['last_msg'] ?? '',
        'chat_status' => $row['chat_status'],
    ];
}
$stmt->close();

echo json_encode($rooms);
