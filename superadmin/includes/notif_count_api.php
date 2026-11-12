<?php
// ============================================================
//  admin/includes/notif_count_api.php
//  AJAX endpoint — returns JSON with notification counts,
//  dropdown HTML, and sidebar badge count.
// ============================================================
header('Content-Type: application/json');
session_start();

if (empty($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

include(__DIR__ . '/../../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');

// ── Actionable notifications stay visible until their source is resolved. ────
$notifCounts = ['booking' => 0, 'chat' => 0, 'extend' => 0, 'service' => 0, 'food' => 0];
$r = $conn->query(
    "SELECT n.type, COUNT(DISTINCT n.id) AS cnt
     FROM notifications n
     LEFT JOIN requests req ON n.type IN ('extend', 'service', 'food') AND req.id = n.reference_id
     LEFT JOIN bookings b ON n.type = 'booking' AND b.id = n.reference_id
     LEFT JOIN chat_messages cm ON n.type = 'chat' AND cm.id = n.reference_id
    LEFT JOIN chat_room_status crs ON crs.room_number COLLATE utf8mb4_unicode_ci = n.room_number COLLATE utf8mb4_unicode_ci
    LEFT JOIN customerstbl active_guest ON active_guest.room_no COLLATE utf8mb4_general_ci = n.room_number COLLATE utf8mb4_general_ci AND active_guest.id = cm.customer_id AND active_guest.status = 'checked-in'
     WHERE ((n.type IN ('extend', 'service', 'food') AND req.status = 'pending' AND req.is_archived = 0)
         OR (n.type = 'booking' AND b.status = 'pending' AND b.is_archived = 0)
         OR (n.type = 'chat' AND cm.id IS NOT NULL AND active_guest.id IS NOT NULL AND (crs.status IS NULL OR crs.status <> 'complete')
             AND NOT EXISTS (SELECT 1 FROM chat_messages reply WHERE reply.customer_id = cm.customer_id AND reply.sender = 'admin' AND reply.sent_at > cm.sent_at)))
     GROUP BY n.type"
);
if ($r) {
    while ($row = $r->fetch_assoc()) {
        if (array_key_exists($row['type'], $notifCounts)) {
            $notifCounts[$row['type']] = (int)$row['cnt'];
        }
    }
}
$notifTotal = array_sum($notifCounts);

// ── Expiring sessions (kept as live query — time-based, not event-based) ──────
$expCount = 0;
$r2 = $conn->query(
    "SELECT COUNT(*) AS cnt FROM guest_tokens
     WHERE expires_at > NOW() AND expires_at <= DATE_ADD(NOW(), INTERVAL 15 MINUTE)"
);
if ($r2) $expCount = (int)$r2->fetch_assoc()['cnt'];

// ── Totals ─────────────────────────────────────────────────────────────────────
$sidebarBadge = $notifTotal;
$totalAll     = $notifTotal + $expCount;

// ── Fetch recent actionable notifications for dropdown (max 15) ──────────────
$notifs = [];
$notifRes = $conn->query(
    "SELECT DISTINCT n.id, n.type, n.reference_id, n.room_number, n.message, n.created_at
     FROM notifications n
     LEFT JOIN requests req ON n.type IN ('extend', 'service', 'food') AND req.id = n.reference_id
     LEFT JOIN bookings b ON n.type = 'booking' AND b.id = n.reference_id
     LEFT JOIN chat_messages cm ON n.type = 'chat' AND cm.id = n.reference_id
    LEFT JOIN chat_room_status crs ON crs.room_number COLLATE utf8mb4_unicode_ci = n.room_number COLLATE utf8mb4_unicode_ci
    LEFT JOIN customerstbl active_guest ON active_guest.room_no COLLATE utf8mb4_general_ci = n.room_number COLLATE utf8mb4_general_ci AND active_guest.id = cm.customer_id AND active_guest.status = 'checked-in'
     WHERE ((n.type IN ('extend', 'service', 'food') AND req.status = 'pending' AND req.is_archived = 0)
         OR (n.type = 'booking' AND b.status = 'pending' AND b.is_archived = 0)
         OR (n.type = 'chat' AND cm.id IS NOT NULL AND active_guest.id IS NOT NULL AND (crs.status IS NULL OR crs.status <> 'complete')
             AND NOT EXISTS (SELECT 1 FROM chat_messages reply WHERE reply.customer_id = cm.customer_id AND reply.sender = 'admin' AND reply.sent_at > cm.sent_at)))
    ORDER BY n.created_at DESC
     LIMIT 15"
);
if ($notifRes) {
    while ($row = $notifRes->fetch_assoc()) $notifs[] = $row;
}

// Group by type for sectioned display
$notifsByType = [];
foreach ($notifs as $n) {
    $notifsByType[$n['type']][] = $n;
}

// ── Expiring sessions detail ───────────────────────────────────────────────────
$expiring = [];
$expRes = $conn->query(
    "SELECT room_number, expires_at,
            TIMESTAMPDIFF(MINUTE, NOW(), expires_at) AS mins_left
     FROM guest_tokens
     WHERE expires_at > NOW() AND expires_at <= DATE_ADD(NOW(), INTERVAL 15 MINUTE)
     ORDER BY expires_at ASC LIMIT 5"
);
if ($expRes) {
    while ($row = $expRes->fetch_assoc()) $expiring[] = $row;
}

// ── Notification config: icon + color + label per type ────────────────────────
$notifConfig = [
    'booking' => [
        'icon'  => 'bi-calendar-check-fill',
        'color' => 'bg-success bg-opacity-25 text-success',
        'label' => 'New Bookings',
    ],
    'chat' => [
        'icon'  => 'bi-chat-dots-fill',
        'color' => 'bg-warning bg-opacity-25 text-warning',
        'label' => 'Unread Messages',
    ],
    'extend' => [
        'icon'  => 'bi-clock-fill',
        'color' => 'bg-info bg-opacity-25 text-info',
        'label' => 'Extension Requests',
    ],
    'service' => [
        'icon'  => 'bi-gear-fill',
        'color' => 'bg-primary bg-opacity-25 text-primary',
        'label' => 'Service Requests',
    ],
    'food' => [
        'icon'  => 'bi-cup-hot-fill',
        'color' => 'bg-warning bg-opacity-25 text-warning',
        'label' => 'Food Orders',
    ],
];

// ── Build dropdown HTML ────────────────────────────────────────────────────────
ob_start();
?>
<li class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
    <h6 class="mb-0 fw-bold">Notifications</h6>
    <?php if ($totalAll > 0): ?>
    <span class="badge bg-danger rounded-pill" id="notifHeaderCount"><?= $totalAll ?></span>
    <?php endif; ?>
</li>

<?php foreach ($notifConfig as $type => $cfg):
    if (empty($notifsByType[$type])) continue; ?>
<li class="px-3 pt-2 pb-1">
    <small class="text-muted fw-semibold text-uppercase" style="font-size:0.7rem;">
        <i class="bi <?= $cfg['icon'] ?> me-1"></i>
        <?= $cfg['label'] ?> (<?= count($notifsByType[$type]) ?>)
    </small>
</li>
<?php foreach ($notifsByType[$type] as $n): ?>
<li>
    <?php
        // Build deep-link: tab mapping
        $tabMap = ['chat'=>'chat','extend'=>'ext','service'=>'svc','food'=>'food','booking'=>'book'];
        $deepTab = $tabMap[$n['type']] ?? 'chat';
        $deepHref = '../superadmin/requests.php?tab=' . urlencode($deepTab)
                  . '&reference_id=' . (int)$n['reference_id']
                  . '&room=' . urlencode($n['room_number']);
    ?>
    <a href="<?= htmlspecialchars($deepHref) ?>"
       class="text-decoration-none"
       onclick="navigator.sendBeacon('includes/mark_notif_read.php', new URLSearchParams({id: '<?= (int)$n['id'] ?>'}))">

        <div class="d-flex message-item align-items-center">
            <div class="notif-icon-circle <?= htmlspecialchars($cfg['color']) ?>">
                <i class="bi <?= htmlspecialchars($cfg['icon']) ?>"></i>
            </div>
            <div class="message-details">
                <div class="top-row">
                    <?php if ($n['room_number'] !== ''): ?>
                    <span class="guest-number">Room <?= htmlspecialchars($n['room_number']) ?></span>
                    <?php endif; ?>
                    <span class="status new">New</span>
                </div>
                <div class="bottom-row">
                    <p class="message-peek mb-0">
                        <?= htmlspecialchars(mb_strimwidth($n['message'], 0, 50, '...')) ?>
                    </p>
                    <span class="time text-end"><?= date('g:i A', strtotime($n['created_at'])) ?></span>
                </div>
            </div>
        </div>
    </a>
</li>
<?php endforeach; endforeach; ?>

<?php if ($expCount > 0): ?>
<li class="px-3 pt-2 pb-1">
    <small class="text-muted fw-semibold text-uppercase" style="font-size:0.7rem;">
        <i class="bi bi-clock-history me-1"></i>Expiring Soon (<?= $expCount ?>)
    </small>
</li>
<?php foreach ($expiring as $exp): ?>
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
<?php endforeach; endif; ?>

<?php if ($totalAll === 0): ?>
<li class="px-3 py-4 text-center text-muted">
    <i class="bi bi-check-circle" style="font-size:1.5rem;"></i>
    <p class="mb-0 mt-1 small">All caught up!</p>
</li>
<?php endif; ?>

<li class="border-top d-flex align-items-stretch">
    <a class="dropdown-item see-all text-center small fw-bold py-2 flex-grow-1"
       href="../superadmin/requests.php">
        See All Requests &amp; Messages
    </a>
    <?php if ($notifTotal > 0): ?>
    <button class="btn btn-link small fw-bold py-2 px-2 text-muted border-start"
            style="font-size:0.75rem; white-space:nowrap; text-decoration:none;"
            onclick="fetch('includes/mark_notif_read.php',{method:'POST',body:new URLSearchParams({all:'1'})})">
        Mark all read
    </button>
    <?php endif; ?>
</li>
<?php
$html = ob_get_clean();

// This fragment replaces the dropdown the page already rendered, so it has to
// arrive in the same language (includes/ui_i18n.php). It is a shared piece, not
// a screen, so only the vocabulary every staff page uses applies to it.
require_once __DIR__ . '/../../includes/ui_i18n.php';
if (ui_is_tl()) {
    $html = ui_translate_html($html, UI_I18N_SHARED);
}

echo json_encode([
    'chat'         => $notifCounts['chat'],
    'requests'     => $notifCounts['extend'] + $notifCounts['service'] + $notifCounts['food'],
    'expiring'     => $expCount,
    'total'        => $totalAll,
    'sidebarBadge' => $sidebarBadge,
    'dropdownHtml' => $html,
]);