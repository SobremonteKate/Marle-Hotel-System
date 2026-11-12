<?php
// Request badge values for sidebar display
// Normally included by a page that already provides $conn; the guard keeps a
// direct request to this fragment from fataling on an undefined connection.
if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/../dbconnect.php';

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
}
// All counts now sourced from the unified notifications table.
// Variables below are kept for backward compatibility with any
// page templates that reference them directly.
$pendingCount    = 0;
$svcPendingCount = 0;
$foodPendingCount = 0;
$chatCount        = 0;

// Single indexed query replaces all previous direct-table queries
$requestBadgeValue = 0;
$r = $conn->query(
	"SELECT COUNT(DISTINCT n.id) AS cnt
	 FROM notifications n
	 LEFT JOIN requests req ON n.type IN ('extend', 'service', 'food') AND req.id = n.reference_id
	 LEFT JOIN bookings b ON n.type = 'booking' AND b.id = n.reference_id
	 LEFT JOIN chat_messages cm ON n.type = 'chat' AND cm.id = n.reference_id
	 LEFT JOIN chat_room_status crs ON crs.room_number COLLATE utf8mb4_unicode_ci = n.room_number COLLATE utf8mb4_unicode_ci
	LEFT JOIN customerstbl active_guest ON active_guest.room_no COLLATE utf8mb4_general_ci = n.room_number COLLATE utf8mb4_general_ci AND active_guest.id = cm.customer_id AND active_guest.status = 'checked-in'
	 WHERE ((n.type IN ('extend', 'service', 'food') AND req.status = 'pending' AND req.is_archived = 0)
		 OR (n.type = 'booking' AND b.status = 'pending' AND b.is_archived = 0)
		 OR (n.type = 'chat' AND cm.id IS NOT NULL AND active_guest.id IS NOT NULL
			 AND (crs.status IS NULL OR crs.status <> 'complete')
			 AND NOT EXISTS (SELECT 1 FROM chat_messages reply WHERE reply.customer_id = cm.customer_id AND reply.sender = 'admin' AND reply.sent_at > cm.sent_at)));"
);
?>