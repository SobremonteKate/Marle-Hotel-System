<?php
// ============================================================
//  superadmin/activity_log.php — SYSTEM LOG
//  Every state change recorded by includes/log_action.php.
//  Owner (superadmin) only — guarded by the view_audit_log capability.
// ============================================================
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.systemlog');
include('../superadmin/auth.php');
require_capability('view_audit_log');
include('../superadmin/requests_badge.php');
include('../superadmin/includes/notif_data.php');

// ── Filters ──────────────────────────────────────────────────
$allowedRoles    = ['all', 'superadmin', 'admin', 'employee', 'system', 'unknown'];
$allowedOutcomes = ['all', 'success', 'denied', 'failure'];

$filterRole    = $_GET['role']    ?? 'all';
$filterOutcome = $_GET['outcome'] ?? 'all';
$filterAction  = trim($_GET['action'] ?? '');
$filterFrom    = trim($_GET['from'] ?? '');
$filterTo      = trim($_GET['to'] ?? '');
$search        = trim($_GET['q'] ?? '');

if (!in_array($filterRole, $allowedRoles, true))       { $filterRole = 'all'; }
if (!in_array($filterOutcome, $allowedOutcomes, true)) { $filterOutcome = 'all'; }

$conds  = [];
$params = [];
$types  = '';

if ($filterRole !== 'all') {
    $conds[]  = 'actor_role = ?';
    $params[] = $filterRole;
    $types   .= 's';
}
if ($filterOutcome !== 'all') {
    $conds[]  = 'outcome = ?';
    $params[] = $filterOutcome;
    $types   .= 's';
}
if ($filterAction !== '') {
    $conds[]  = 'action LIKE ?';
    $params[] = '%' . $filterAction . '%';
    $types   .= 's';
}
if ($filterFrom !== '') {
    $conds[]  = 'DATE(created_at) >= ?';
    $params[] = $filterFrom;
    $types   .= 's';
}
if ($filterTo !== '') {
    $conds[]  = 'DATE(created_at) <= ?';
    $params[] = $filterTo;
    $types   .= 's';
}
if ($search !== '') {
    $conds[]  = '(actor_username LIKE ? OR reason LIKE ? OR target_type LIKE ? OR ip_address LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ssss';
}

$whereSql = $conds ? (' WHERE ' . implode(' AND ', $conds)) : '';

// ── Pagination ───────────────────────────────────────────────
$perPage = 20;

$countSql  = "SELECT COUNT(*) AS c FROM audit_log" . $whereSql;
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
    if ($params) { $countStmt->bind_param($types, ...$params); }
    $countStmt->execute();
    $totalRows = (int)($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $countStmt->close();
} else {
    $totalRows = 0;
}

$totalPages  = max(1, (int)ceil($totalRows / $perPage));
$currentPage = (int)($_GET['page'] ?? 1);
if ($currentPage < 1)            { $currentPage = 1; }
if ($currentPage > $totalPages)  { $currentPage = $totalPages; }
$offset = ($currentPage - 1) * $perPage;

$rows = [];
$sql  = "SELECT * FROM audit_log" . $whereSql . " ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $listTypes  = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($listTypes, ...$listParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ── Quick stats (all-time, unfiltered) ───────────────────────
$statTotal  = (int)($conn->query("SELECT COUNT(*) AS c FROM audit_log")->fetch_assoc()['c'] ?? 0);
$statToday  = (int)($conn->query("SELECT COUNT(*) AS c FROM audit_log WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['c'] ?? 0);
$statDenied = (int)($conn->query("SELECT COUNT(*) AS c FROM audit_log WHERE outcome <> 'success'")->fetch_assoc()['c'] ?? 0);

// ── Distinct actions for the filter dropdown ─────────────────
$actionOptions = [];
$res = $conn->query("SELECT DISTINCT action FROM audit_log ORDER BY action ASC");
while ($res && $r = $res->fetch_assoc()) { $actionOptions[] = $r['action']; }

$pageQuery = $_GET;
unset($pageQuery['page']);
$pageUrl = function (int $p) use ($pageQuery): string {
    $pageQuery['page'] = $p;
    return 'activity_log.php?' . http_build_query($pageQuery);
};

// ── Profile picture ──────────────────────────────────────────
$username = $_SESSION['username'];
$imgSrc   = "../img/default.jpg";
$stmtUser = $conn->prepare("SELECT picture FROM userstbl WHERE username = ? LIMIT 1");
if ($stmtUser) {
    $stmtUser->bind_param('s', $username);
    $stmtUser->execute();
    $userRow = $stmtUser->get_result()->fetch_assoc();
    $stmtUser->close();
    if (!empty($userRow['picture'])) {
        $finfo  = new finfo(FILEINFO_MIME_TYPE);
        $mime   = $finfo->buffer($userRow['picture']);
        $imgSrc = "data:$mime;base64," . base64_encode($userRow['picture']);
    }
}

function outcomeBadge(string $outcome): string
{
    return match ($outcome) {
        'success' => '<span class="badge bg-success">Success</span>',
        'denied'  => '<span class="badge bg-danger">Denied</span>',
        'failure' => '<span class="badge bg-warning text-dark">Failure</span>',
        default   => '<span class="badge bg-secondary">' . htmlspecialchars($outcome) . '</span>',
    };
}

function roleBadge(string $role): string
{
    return match ($role) {
        'superadmin' => '<span class="badge" style="background:#352414;">Owner</span>',
        'admin'      => '<span class="badge" style="background:#933310;">Manager</span>',
        'employee'   => '<span class="badge" style="background:#ffb60a;color:#333;">Front Desk</span>',
        'system'     => '<span class="badge bg-secondary">Guest / System</span>',
        default      => '<span class="badge bg-light text-dark">' . htmlspecialchars($role) . '</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="<?= ui_html_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-4Q6Gf2aSP4eDXB8Miphtr37CMZZQ5oXLH2yaXMJ2w8e2ZtHTl7GptT4jmndRuHDT" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="stylesheet" href="../css/adminDB.css?v=20260930">
    <?php require __DIR__ . '/../includes/staff_icons.php'; ?>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
    <title>System Log — Marle Hotel</title>
    <style>
        body { background-color: #f2ece9; overflow-x: auto; }

        @media (min-width: 768px) {
            .navbar {
                width: 230px; height: 100vh; position: fixed; top: 0; left: 0;
                background-color: #f8f9fa; display: flex; flex-direction: column;
            }
            .navbar > .container-fluid { height: 100%; display: flex; flex-direction: column; }
            .navbar-collapse { display: flex !important; flex-direction: column; flex: 1; }
            .content { margin-left: 230px; margin-top: 1.5rem; }
        }

        .nav-link { padding: 12px 18px; color: #555; border-radius: 12px; position: relative; transition: all 0.3s ease; }
        .nav-link:hover, .nav-link.active { background-color: #ffb60a !important; color: #fff !important; border-radius: 12px; }
        .nav-link i { font-size: 18px; }
        .notification-badge { background-color: #ffb60a; color: #fff; font-size: 12px; padding: 5px 8px; }
        .logout-btn { background-color: #933310; color: #fff; border: none; padding: 10px; border-radius: 12px; transition: 0.3s; }
        a { color: #ffb60a; text-decoration: none; }

        .main-card {
            background-color: #fff; border-radius: 20px;
            width: min(calc(100% - var(--sidebar-w) - 40px), 1400px); max-width: 1400px;
            padding: 24px; margin: 20px 20px 20px calc(var(--sidebar-w) + max(20px, (100% - var(--sidebar-w) - 1400px) / 2));
        }
        @media (max-width: 768px) { .main-card { margin: 10px; width: calc(100% - 20px); } }

        .stat-chip { background:#f9f6f3; border-radius:12px; padding:12px 16px; display:flex; justify-content:space-between; align-items:center; }
        .stat-chip-name { font-size:0.78rem; color:#666; }
        .stat-chip-val { font-size:1.05rem; font-weight:700; color:#333; }

        .filter-bar { background:#f9f6f3; border-radius:14px; padding:14px 16px; margin-bottom:18px; }
        .filter-bar .form-control, .filter-bar .form-select { font-size:0.85rem; }

        .log-table th { font-size:0.68rem; text-transform:uppercase; color:#6c757d; white-space:nowrap; }
        .log-table td { font-size:0.78rem; vertical-align:middle; }
        .log-reason { max-width:340px; word-wrap:break-word; white-space:normal; }
        .log-time { white-space:nowrap; color:#6c757d; }
        .section-head { font-size:0.95rem; font-weight:600; color:#933310; margin-bottom:12px; padding-bottom:6px; border-bottom:2px solid #ffb60a; }
    </style>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
    <link rel="stylesheet" href="../css/staff-footer.css?v=20261021">
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>
<body class="staff-page staff-superadmin staff-log-page">

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

<!-- ═══════════════ MAIN CONTENT ══════════════ -->
<?php
    $staffPageTitle  = 'System Log';
    $staffPageNote   = 'Every recorded staff action, newest first.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['System Log', null]];
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

<div class="main-card">

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="stat-chip">
                <span class="stat-chip-name"><i class="bi bi-journal-text me-1"></i>Total logged events</span>
                <span class="stat-chip-val"><?= number_format($statTotal) ?></span>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="stat-chip">
                <span class="stat-chip-name"><i class="bi bi-calendar-day me-1"></i>Today</span>
                <span class="stat-chip-val"><?= number_format($statToday) ?></span>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="stat-chip">
                <span class="stat-chip-name"><i class="bi bi-shield-exclamation me-1"></i>Denied / failed</span>
                <span class="stat-chip-val" style="color:#dc3545;"><?= number_format($statDenied) ?></span>
            </div>
        </div>
    </div>

    <div class="section-head"><i class="bi bi-funnel me-1"></i>Filters</div>

    <form method="GET" action="activity_log.php">
        <div class="filter-bar row g-2 align-items-end">
            <div class="col-12 col-md-2">
                <label class="form-label fw-semibold small mb-1">Role</label>
                <select name="role" class="form-select form-select-sm">
                    <option value="all"        <?= $filterRole === 'all'        ? 'selected' : '' ?>>All roles</option>
                    <option value="superadmin" <?= $filterRole === 'superadmin' ? 'selected' : '' ?>>Owner</option>
                    <option value="admin"      <?= $filterRole === 'admin'      ? 'selected' : '' ?>>Manager</option>
                    <option value="employee"   <?= $filterRole === 'employee'   ? 'selected' : '' ?>>Front Desk</option>
                    <option value="system"     <?= $filterRole === 'system'     ? 'selected' : '' ?>>Guest / System</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label fw-semibold small mb-1">Outcome</label>
                <select name="outcome" class="form-select form-select-sm">
                    <option value="all"     <?= $filterOutcome === 'all'     ? 'selected' : '' ?>>All outcomes</option>
                    <option value="success" <?= $filterOutcome === 'success' ? 'selected' : '' ?>>Success</option>
                    <option value="denied"  <?= $filterOutcome === 'denied'  ? 'selected' : '' ?>>Denied</option>
                    <option value="failure" <?= $filterOutcome === 'failure' ? 'selected' : '' ?>>Failure</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label fw-semibold small mb-1">Action</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">Any action</option>
                    <?php foreach ($actionOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $filterAction === $opt ? 'selected' : '' ?>>
                            <?= htmlspecialchars($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($filterFrom) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold small mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($filterTo) ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label fw-semibold small mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="User, reason, IP…"
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-sm fw-semibold" style="background:#ffb60a;color:#fff;">
                    <i class="bi bi-funnel me-1"></i>Apply filters
                </button>
                <a href="activity_log.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                <span class="ms-auto text-muted small align-self-center">
                    <?= number_format($totalRows) ?> event<?= $totalRows === 1 ? '' : 's' ?> match
                </span>
            </div>
        </div>
    </form>

    <div class="section-head"><i class="bi bi-card-checklist me-1"></i>Activity</div>

    <?php if (empty($rows)): ?>
        <div class="alert alert-info mb-0">No log entries match the selected filters.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover log-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>When</th>
                        <th>Actor</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>Outcome</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $row): ?>
                    <tr>
                        <td class="text-muted"><?= $offset + $i + 1 ?></td>
                        <td class="log-time"><?= date('M j, Y g:i:s a', strtotime($row['created_at'])) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($row['actor_username'] ?? '—') ?></td>
                        <td><?= roleBadge((string)$row['actor_role']) ?></td>
                        <td><code style="font-size:0.72rem;"><?= htmlspecialchars($row['action']) ?></code></td>
                        <td>
                            <?php if (!empty($row['target_type'])): ?>
                                <span class="text-muted small">
                                    <?= htmlspecialchars($row['target_type']) ?><?= (int)$row['target_id'] > 0 ? ' #' . (int)$row['target_id'] : '' ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= outcomeBadge((string)$row['outcome']) ?></td>
                        <td class="log-reason"><?= htmlspecialchars($row['reason'] ?? '—') ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($row['ip_address'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav class="mt-3" aria-label="System log pages">
            <ul class="pagination pagination-sm justify-content-center mb-0 flex-wrap">
                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars($pageUrl(max(1, $currentPage - 1))) ?>">Previous</a>
                </li>
                <?php
                $pageStart = max(1, $currentPage - 3);
                $pageEnd   = min($totalPages, $currentPage + 3);
                if ($pageStart > 1) {
                    echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($pageUrl(1)) . '">1</a></li>';
                    if ($pageStart > 2) echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
                }
                for ($p = $pageStart; $p <= $pageEnd; $p++):
                ?>
                    <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars($pageUrl($p)) ?>"><?= $p ?></a>
                    </li>
                <?php
                endfor;
                if ($pageEnd < $totalPages) {
                    if ($pageEnd < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
                    echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($pageUrl($totalPages)) . '">' . $totalPages . '</a></li>';
                }
                ?>
                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars($pageUrl(min($totalPages, $currentPage + 1))) ?>">Next</a>
                </li>
            </ul>
            <p class="text-center text-muted small mt-2 mb-0">
                Showing <?= $offset + 1 ?>&ndash;<?= min($offset + $perPage, $totalRows) ?> of <?= number_format($totalRows) ?>
                &middot; page <?= $currentPage ?> of <?= $totalPages ?>
            </p>
        </nav>
        <?php endif; ?>
    <?php endif; ?>

</div>

<!-- LOGOUT MODAL -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Logout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p class="fs-5">Are you sure you want to log out?</p>
            </div>
            <div class="modal-footer">
                <button type="button" id="noBtn" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="../logout.php" class="btn" id="yesBtn">Yes</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>

    <!-- Footer — one design for all three roles (includes/staff_footer.php) -->
    <?php $marleFooterTerms = false; require __DIR__ . '/../includes/staff_footer.php'; ?>
</body>
</html>
