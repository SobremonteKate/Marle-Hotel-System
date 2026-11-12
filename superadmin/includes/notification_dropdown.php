<?php
// ============================================================
//  admin/includes/notification_dropdown.php
//  Reusable notification bell + dropdown for all dashboard pages.
//  Requires: notif_data.php already included (provides
//  $notifChats, $notifRequests, $unreadChatCount,
//  $pendingReqCount, $totalNotifCount).
// ============================================================
?>
<div class="dropdown notif-dropdown-widget">
    <a href="#" class="position-relative text-dark text-decoration-none" id="notificationBell"
       data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-bell fs-4"></i>
        <?php if ($totalNotifCount > 0): ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
              id="notifBadgeCount" style="font-size: 0.6rem;">
            <?= $totalNotifCount ?>
        </span>
        <?php else: ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none"
              id="notifBadgeCount" style="font-size: 0.6rem;">0</span>
        <?php endif; ?>
    </a>

    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 notif-dropdown-menu"
        aria-labelledby="notificationBell" style="width:360px; max-height:420px; overflow-y:auto;">

        <li class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">Notifications</h6>
            <?php if ($totalNotifCount > 0): ?>
            <span class="badge bg-danger rounded-pill" id="notifHeaderCount"><?= $totalNotifCount ?></span>
            <?php endif; ?>
        </li>

        <!-- Chat section -->
        <?php if ($unreadChatCount > 0): ?>
        <li class="px-3 pt-2 pb-1">
            <small class="text-muted fw-semibold text-uppercase" style="font-size:0.7rem;">
                <i class="bi bi-chat-dots me-1"></i>Unread Messages (<?= $unreadChatCount ?>)
            </small>
        </li>
        <?php foreach (array_slice($notifChats, 0, 5) as $chat): ?>
        <li>
            <a href="../superadmin/requests.php?tab=chat&amp;room=<?= urlencode((string)$chat['room_number']) ?>" class="text-decoration-none">
                <div class="d-flex message-item align-items-center">
                    <div class="notif-icon-circle bg-warning bg-opacity-25 text-warning">
                        <i class="bi bi-chat-dots-fill"></i>
                    </div>
                    <div class="message-details">
                        <div class="top-row">
                            <span class="guest-number">Room <?= htmlspecialchars($chat['room_number']) ?></span>
                            <span class="status new">Unread</span>
                        </div>
                        <div class="bottom-row">
                            <p class="message-peek mb-0">
                                <?php if (!empty($chat['attachment_name'])): ?>
                                    <i class="bi bi-paperclip"></i>
                                    <?= htmlspecialchars($chat['attachment_name']) ?>
                                <?php else: ?>
                                    <?= htmlspecialchars(mb_strimwidth($chat['message'], 0, 45, '...')) ?>
                                <?php endif; ?>
                            </p>
                            <span class="time text-end"><?= date('g:i A', strtotime($chat['sent_at'])) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </li>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- Requests section -->
        <?php if ($pendingReqCount > 0): ?>
        <li class="px-3 pt-2 pb-1">
            <small class="text-muted fw-semibold text-uppercase" style="font-size:0.7rem;">
                <i class="bi bi-bell me-1"></i>Pending Requests (<?= $pendingReqCount ?>)
            </small>
        </li>
        <?php foreach (array_slice($notifRequests, 0, 5) as $req): ?>
        <li>
            <a href="../superadmin/requests.php?tab=<?= ['service' => 'svc', 'food' => 'food', 'extend' => 'ext'][$req['type']] ?? 'chat' ?>&amp;reference_id=<?= (int)$req['id'] ?>&amp;room=<?= urlencode((string)$req['room_number']) ?>" class="text-decoration-none">
                <div class="d-flex message-item align-items-center">
                    <div class="notif-icon-circle bg-info bg-opacity-25 text-info">
                        <i class="bi bi-bell-fill"></i>
                    </div>
                    <div class="message-details">
                        <div class="top-row">
                            <span class="guest-number">Room <?= htmlspecialchars($req['room_number']) ?></span>
                            <span class="room-number"><?= ucfirst($req['type']) ?></span>
                            <span class="status new">Pending</span>
                        </div>
                        <div class="bottom-row">
                            <p class="message-peek mb-0">
                                <?= htmlspecialchars(mb_strimwidth($req['message'], 0, 45, '...')) ?>
                            </p>
                            <span class="time text-end"><?= date('g:i A', strtotime($req['created_at'])) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </li>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- Expiring sessions -->
        <?php if ($expiringCount > 0): ?>
        <li class="px-3 pt-2 pb-1">
            <small class="text-muted fw-semibold text-uppercase" style="font-size:0.7rem;">
                <i class="bi bi-clock-history me-1"></i>Expiring Soon (<?= $expiringCount ?>)
            </small>
        </li>
        <?php foreach ($notifExpiring as $exp): ?>
        <li>
            <a href="../superadmin/GuestTokens.php" class="text-decoration-none">
                <div class="d-flex message-item align-items-center">
                    <div class="notif-icon-circle bg-danger bg-opacity-25 text-danger">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div class="message-details">
                        <div class="top-row">
                            <span class="guest-number">Room <?= htmlspecialchars($exp['room_number']) ?></span>
                            <span class="status" style="background:#dc3545;color:#fff;">Expiring</span>
                        </div>
                        <div class="bottom-row">
                            <p class="message-peek mb-0">
                                <?php
                                    $ml = max(0, (int)$exp['mins_left']);
                                    echo $ml <= 0 ? 'Expiring now' : $ml . ' min remaining';
                                ?>
                            </p>
                            <span class="time text-end"><?= date('g:i A', strtotime($exp['expires_at'])) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        </li>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- Empty state -->
        <?php if ($totalNotifCount === 0): ?>
        <li class="px-3 py-4 text-center text-muted">
            <i class="bi bi-check-circle" style="font-size:1.5rem;"></i>
            <p class="mb-0 mt-1 small">All caught up!</p>
        </li>
        <?php endif; ?>

        <!-- Footer link -->
        <li class="border-top">
            <a class="dropdown-item see-all text-center small fw-bold py-2" href="../superadmin/requests.php">
                See All Requests &amp; Messages
            </a>
        </li>
    </ul>
</div>

<style>
    .notif-dropdown-widget .notif-icon-circle {
        width: 36px; height: 36px; min-width: 36px;
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem;
    }
    .notif-dropdown-menu .message-item {
        display: flex; gap: 10px; padding: 10px 12px;
        cursor: pointer; border-bottom: 1px solid #f0f0f0;
        color: #333;
    }
    .notif-dropdown-menu .message-item:hover { background: #f8f8f8; }
    .notif-dropdown-menu .message-details { flex: 1; min-width: 0; }
    .notif-dropdown-menu .top-row { display: flex; align-items: center; gap: 6px; }
    .notif-dropdown-menu .bottom-row { display: flex; justify-content: space-between; align-items: center; }
    .notif-dropdown-menu .guest-number { font-weight: 600; font-size: 0.85rem; }
    .notif-dropdown-menu .room-number {
        font-size: 11px; padding: 2px 6px; border-radius: 6px;
        background-color: #ffb60a; color: #fff;
    }
    .notif-dropdown-menu .status { font-size: 11px; padding: 2px 6px; border-radius: 6px; }
    .notif-dropdown-menu .status.new { background: #ffb60a; color: #fff; }
    .notif-dropdown-menu .message-peek {
        font-size: 0.82rem; color: #666;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;
    }
    .notif-dropdown-menu .time { font-size: 0.72rem; color: #aaa; white-space: nowrap; }
</style>
