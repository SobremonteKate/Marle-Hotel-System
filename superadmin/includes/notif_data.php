<?php
// ============================================================
//  admin/includes/notif_data.php
//  Queries unread chat messages & pending service requests
//  for the notification dropdown. Requires $conn to be set.
// ============================================================

// ── Unread chat messages (sent by guest, not yet read by admin) ──
$notifChats = [];
$chatSql = "SELECT cm.room_number, cm.message, cm.sent_at,
                   cm.attachment_name, cm.attachment_type
            FROM chat_messages cm
            JOIN customerstbl active_guest
              ON active_guest.id = cm.customer_id
             AND active_guest.status = 'checked-in'
            WHERE cm.sender = 'guest'
              AND cm.is_read = 0
              AND cm.archived_at IS NULL
            ORDER BY cm.sent_at DESC
            LIMIT 20";
$chatRes = $conn->query($chatSql);
if ($chatRes) {
    while ($row = $chatRes->fetch_assoc()) {
        $notifChats[] = $row;
    }
}
$unreadChatCount = count($notifChats);

// ── Pending service / food / extend requests ──
$notifRequests = [];
$reqSql = "SELECT r.id, r.room_number, r.type, r.message, r.created_at
           FROM requests r
           WHERE r.status = 'pending'
             AND r.is_archived = 0
           ORDER BY r.created_at DESC
           LIMIT 20";
$reqRes = $conn->query($reqSql);
if ($reqRes) {
    while ($row = $reqRes->fetch_assoc()) {
        $notifRequests[] = $row;
    }
}
$pendingReqCount = count($notifRequests);

// ── Sessions expiring within 15 minutes ──
$notifExpiring = [];
$expSql = "SELECT gt.room_number, gt.expires_at,
                  TIMESTAMPDIFF(MINUTE, NOW(), gt.expires_at) AS mins_left
           FROM guest_tokens gt
           WHERE gt.expires_at > NOW()
             AND gt.expires_at <= DATE_ADD(NOW(), INTERVAL 15 MINUTE)
           ORDER BY gt.expires_at ASC
           LIMIT 20";
$expRes = $conn->query($expSql);
if ($expRes) {
    while ($row = $expRes->fetch_assoc()) {
        $notifExpiring[] = $row;
    }
}
$expiringCount = count($notifExpiring);

$totalNotifCount = $unreadChatCount + $pendingReqCount + $expiringCount;
?>
