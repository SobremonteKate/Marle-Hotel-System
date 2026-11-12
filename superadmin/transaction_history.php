<?php
// ============================================================
//  admin/transaction_history.php
//  Admin/Staff only — full transaction audit log.
//  Sources: check-ins (transaction_history), extensions
//           (extension_history), services (service_requests).
// ============================================================
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.transactions');
require_once __DIR__ . '/../includes/server_clock.php';   // marle_hotel_date(): the hotel's date

$conn->set_charset("utf8mb4");
$conn->query("SET collation_connection = utf8mb4_unicode_ci");

include('../superadmin/auth.php');
require_once __DIR__ . '/../includes/log_action.php';
include('../csrf.php');
include('../superadmin/requests_badge.php');
include('../superadmin/includes/notif_data.php');

$collectionCsrfToken = csrf_token();
$canGenerateReports = has_capability('generate_reports');

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'update_tx') {
    // Direct edits of a stored ledger row (status/amount) are deliberately
    // disabled: collections and the audited archive flow are the only ways a
    // row changes. Log the attempt and refuse.
    log_event($conn, 'transaction_direct_edit_denied', 'transaction', (int)($_POST['tx_id'] ?? 0), 'denied', 'Direct ledger edits are disabled');
    http_response_code(403);
    exit('Direct transaction edits are disabled. Use the audited adjustment workflow.');
}

// ── Handle POST: Update Transaction ───────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'update_tx') {
    $tx_id = (int)($_POST['tx_id'] ?? 0);
    $new_status = trim($_POST['status'] ?? '');
    $new_amount = (float)($_POST['amount'] ?? 0);
    $new_denial_reason = trim($_POST['denial_reason'] ?? '');
    $new_notes = trim($_POST['notes'] ?? '');
    
    if ($tx_id > 0) {
        $conn->begin_transaction();
        
        try {
            // Fetch transaction details to get type and reference_id
            $fetchStmt = $conn->prepare(
                "SELECT transaction_type, reference_id FROM transaction_history WHERE id = ?"
            );
            $fetchStmt->bind_param('i', $tx_id);
            $fetchStmt->execute();
            $txRow = $fetchStmt->get_result()->fetch_assoc();
            $fetchStmt->close();
            
            if (!$txRow) throw new Exception("Transaction not found");
            
            $tx_type = $txRow['transaction_type'];
            $ref_id = $txRow['reference_id'];
            
            // Update transaction_history
            $updateStmt = $conn->prepare(
                "UPDATE transaction_history SET status=?, amount=?, denial_reason=?, notes=? WHERE id=?"
            );
            $updateStmt->bind_param('sdssi', $new_status, $new_amount, $new_denial_reason, $new_notes, $tx_id);
            $updateStmt->execute();
            $updateStmt->close();
            
            // Cascade update to source tables
            if ($tx_type === 'service' && $ref_id > 0) {
                // Update service_requests + requests
                $srcStmt = $conn->prepare(
                    "SELECT sr.request_id FROM service_requests sr WHERE sr.id = ?"
                );
                $srcStmt->bind_param('i', $ref_id);
                $srcStmt->execute();
                $srcRow = $srcStmt->get_result()->fetch_assoc();
                $srcStmt->close();
                
                if ($srcRow) {
                    $req_id = $srcRow['request_id'];
                    // Update requests table status
                    $req_status = ($new_status === 'denied') ? 'denied' : 'approved';
                    $upReq = $conn->prepare(
                        "UPDATE requests SET status=?, denial_reason=? WHERE id=?"
                    );
                    $upReq->bind_param('ssi', $req_status, $new_denial_reason, $req_id);
                    $upReq->execute();
                    $upReq->close();
                    
                    // Update service_requests payment_status
                    $upSvc = $conn->prepare(
                        "UPDATE service_requests SET payment_status=? WHERE id=?"
                    );
                    $upSvc->bind_param('si', $new_status, $ref_id);
                    $upSvc->execute();
                    $upSvc->close();
                }
} elseif ($tx_type === 'extension' && $ref_id > 0) {
                // Update extension_history + extension_requests
                $upExt = $conn->prepare(
                    "UPDATE extension_history SET payment_status=? WHERE id=?"
                );
                $upExt->bind_param('si', $new_status, $ref_id);
                $upExt->execute();
                $upExt->close();
                
                // Also update extension_requests if needed
                $upExtReq = $conn->prepare(
                    "UPDATE extension_requests SET payment_status=? WHERE id = (SELECT extension_req_id FROM extension_history WHERE id = ?)"
                );
                $upExtReq->bind_param('si', $new_status, $ref_id);
                $upExtReq->execute();
                $upExtReq->close();
            } elseif ($tx_type === 'food' && $ref_id > 0) {
                // Update food_order_items payment_status
                $upFoi = $conn->prepare(
                    "UPDATE food_order_items SET payment_status=? WHERE id=?"
                );
                $upFoi->bind_param('si', $new_status, $ref_id);
                $upFoi->execute();
                $upFoi->close();

                // Cascade status to requests table
                $srcStmt = $conn->prepare(
                    "SELECT request_id FROM food_order_items WHERE id = ?"
                );
                $srcStmt->bind_param('i', $ref_id);
                $srcStmt->execute();
                $srcRow = $srcStmt->get_result()->fetch_assoc();
                $srcStmt->close();

                if ($srcRow) {
                    $req_id    = $srcRow['request_id'];
                    $req_status = ($new_status === 'denied') ? 'denied' : 'approved';
                    $upReq = $conn->prepare(
                        "UPDATE requests SET status=?, denial_reason=? WHERE id=?"
                    );
                    $upReq->bind_param('ssi', $req_status, $new_denial_reason, $req_id);
                    $upReq->execute();
                    $upReq->close();
                }
            }
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
        }
    }
    header("Location: transaction_history.php?updated=1");
    exit();
}

// ── Handle POST: Archive Transaction ──────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'archive_tx') {
    $tx_id = (int)($_POST['tx_id'] ?? 0);
    
    if ($tx_id > 0) {
        $conn->begin_transaction();
        
        try {
            // Fetch transaction details
            $fetchStmt = $conn->prepare(
                "SELECT transaction_type, reference_id FROM transaction_history WHERE id = ?"
            );
            $fetchStmt->bind_param('i', $tx_id);
            $fetchStmt->execute();
            $txRow = $fetchStmt->get_result()->fetch_assoc();
            $fetchStmt->close();
            
            if (!$txRow) throw new Exception("Transaction not found");
            
            $tx_type = $txRow['transaction_type'];
            $ref_id = $txRow['reference_id'];
            
            // Archive transaction_history
            $archStmt = $conn->prepare(
                "UPDATE transaction_history SET archived_at=NOW() WHERE id=?"
            );
            $archStmt->bind_param('i', $tx_id);
            $archStmt->execute();
            $archStmt->close();
            
            // Cascade archive to source tables (mark as denied/archived)
            if ($tx_type === 'service' && $ref_id > 0) {
                // Archive service request
                $srcStmt = $conn->prepare(
                    "SELECT request_id FROM service_requests WHERE id = ?"
                );
                $srcStmt->bind_param('i', $ref_id);
                $srcStmt->execute();
                $srcRow = $srcStmt->get_result()->fetch_assoc();
                $srcStmt->close();
                
                if ($srcRow) {
                    $req_id = $srcRow['request_id'];
                    $denial_msg = 'Archived from transaction history';
                    $dStatus = 'denied';
                    // Mark request as denied (archived)
                    $upReq = $conn->prepare(
                        "UPDATE requests SET status=?, denial_reason=? WHERE id=?"
                    );
                    $upReq->bind_param('ssi', $dStatus, $denial_msg, $req_id);
                    $upReq->execute();
                    $upReq->close();
                }
} elseif ($tx_type === 'extension' && $ref_id > 0) {
                // Archive extension by marking payment as archived
                $denial_msg = 'Archived from transaction history';
                $archStatus = 'archived';
                $upExt = $conn->prepare(
                    "UPDATE extension_history SET payment_status=? WHERE id=?"
                );
                $upExt->bind_param('si', $archStatus, $ref_id);
                $upExt->execute();
                $upExt->close();
            } elseif ($tx_type === 'food' && $ref_id > 0) {
                // Archive food order: mark request as denied (archived)
                $denial_msg = 'Archived from transaction history';
                $dStatus    = 'denied';

                $srcStmt = $conn->prepare(
                    "SELECT request_id FROM food_order_items WHERE id = ?"
                );
                $srcStmt->bind_param('i', $ref_id);
                $srcStmt->execute();
                $srcRow = $srcStmt->get_result()->fetch_assoc();
                $srcStmt->close();

                if ($srcRow) {
                    $req_id = $srcRow['request_id'];
                    $upReq  = $conn->prepare(
                        "UPDATE requests SET status=?, denial_reason=? WHERE id=?"
                    );
                    $upReq->bind_param('ssi', $dStatus, $denial_msg, $req_id);
                    $upReq->execute();
                    $upReq->close();
                }
            }
            $conn->commit();
            log_event($conn, 'transaction_archived', 'transaction', $tx_id, 'success', 'Type: ' . $tx_type . ', reference: ' . $ref_id);
        } catch (Exception $e) {
            $conn->rollback();
            log_event($conn, 'transaction_archived', 'transaction', $tx_id, 'failure', 'Archive failed: ' . $e->getMessage());
        }
    }
    header("Location: transaction_history.php?archived=1");
    exit();
}

// ── Filter inputs ─────────────────────────────────────────────
$filterType   = $_GET['type']      ?? 'all';
$filterGuest  = trim($_GET['guest'] ?? '');
$filterFrom   = $_GET['from']      ?? '';
$filterTo     = $_GET['to']        ?? '';
$filterStatus = $_GET['status']    ?? 'all';
$allowedPaymentStatuses = ['all', 'paid', 'unpaid', 'denied', 'change'];
if (!in_array($filterStatus, $allowedPaymentStatuses, true)) {
    $filterStatus = 'all';
}

// ── Build UNION query for all transaction types ───────────────
// Source 1: check_in rows written by GuestTokens.php
$q1 = "
    SELECT
        th.id                                                                          AS tx_id,
        'check_in'                                                                     AS tx_type,
        th.customer_id,
        CONCAT('ID #', th.customer_id, ' · Rm ', CONVERT(th.room_number USING utf8mb4) COLLATE utf8mb4_unicode_ci) AS guest_label,
        CONVERT(th.room_number USING utf8mb4) COLLATE utf8mb4_unicode_ci               AS room_number,
        CONVERT(COALESCE(th.room_type, '—') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS room_type,
        th.amount,
        tc.total_due AS collection_total_due,
        tc.amount_received AS collection_amount_received,
        tc.change_amount AS collection_change_amount,
        tc.id AS collection_id,
        CONVERT(th.status USING utf8mb4) COLLATE utf8mb4_unicode_ci                   AS status,
        CONVERT(th.denial_reason USING utf8mb4) COLLATE utf8mb4_unicode_ci            AS denial_reason,
        th.created_at,
        CONVERT(th.notes USING utf8mb4) COLLATE utf8mb4_unicode_ci                    AS description
    FROM transaction_history th
    LEFT JOIN transaction_collection_items tci ON tci.transaction_id = th.id
    LEFT JOIN transaction_collections tc ON tc.id = tci.collection_id
    WHERE th.transaction_type = 'check_in' AND th.archived_at IS NULL
";

$q2 = "
    SELECT
        th.id                                                                          AS tx_id,
        'extension'                                                                    AS tx_type,
        eh.customer_id,
        CONCAT('ID #', eh.customer_id, ' · Rm ', CONVERT(eh.room_number USING utf8mb4) COLLATE utf8mb4_unicode_ci) AS guest_label,
        CONVERT(eh.room_number USING utf8mb4) COLLATE utf8mb4_unicode_ci               AS room_number,
        CONVERT(eh.room_type USING utf8mb4) COLLATE utf8mb4_unicode_ci                 AS room_type,
        th.amount,
        tc.total_due AS collection_total_due,
        tc.amount_received AS collection_amount_received,
        tc.change_amount AS collection_change_amount,
        tc.id AS collection_id,
        CONVERT(th.status USING utf8mb4) COLLATE utf8mb4_unicode_ci                   AS status,
        CONVERT(th.denial_reason USING utf8mb4) COLLATE utf8mb4_unicode_ci            AS denial_reason,
        th.created_at,
        CONVERT(th.notes USING utf8mb4) COLLATE utf8mb4_unicode_ci                    AS description
    FROM transaction_history th
    LEFT JOIN transaction_collection_items tci ON tci.transaction_id = th.id
    LEFT JOIN transaction_collections tc ON tc.id = tci.collection_id
    JOIN extension_history eh ON eh.id = th.reference_id
    WHERE th.transaction_type = 'extension' AND th.archived_at IS NULL
";

$q3 = "
    SELECT
        th.id                                                                          AS tx_id,
        'service'                                                                      AS tx_type,
        r.customer_id,
        CONCAT('ID #', r.customer_id, ' · Rm ', r.room_number)                        AS guest_label,
        r.room_number                                                                  AS room_number,
        CONVERT(COALESCE((SELECT rooms FROM roomstbl WHERE CONVERT(room_number USING utf8mb4) COLLATE utf8mb4_unicode_ci = r.room_number LIMIT 1), '—') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS room_type,
        th.amount,
        tc.total_due AS collection_total_due,
        tc.amount_received AS collection_amount_received,
        tc.change_amount AS collection_change_amount,
        tc.id AS collection_id,
        CONVERT(th.status USING utf8mb4) COLLATE utf8mb4_unicode_ci                   AS status,
        CONVERT(th.denial_reason USING utf8mb4) COLLATE utf8mb4_unicode_ci            AS denial_reason,
        th.created_at,
        CONVERT(th.notes USING utf8mb4) COLLATE utf8mb4_unicode_ci                    AS description
    FROM transaction_history th
    LEFT JOIN transaction_collection_items tci ON tci.transaction_id = th.id
    LEFT JOIN transaction_collections tc ON tc.id = tci.collection_id
    JOIN service_requests sr ON sr.id = th.reference_id
    JOIN requests r ON r.id = sr.request_id
    WHERE th.transaction_type = 'service' AND th.archived_at IS NULL
";

$q4 = "
    SELECT
        th.id                                                                          AS tx_id,
        'food'                                                                         AS tx_type,
        r.customer_id,
        CONCAT('ID #', r.customer_id, ' · Rm ', r.room_number)                        AS guest_label,
        r.room_number                                                                  AS room_number,
        CONVERT(COALESCE((SELECT rooms FROM roomstbl WHERE CONVERT(room_number USING utf8mb4) COLLATE utf8mb4_unicode_ci = r.room_number LIMIT 1), '—') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS room_type,
        th.amount,
        tc.total_due AS collection_total_due,
        tc.amount_received AS collection_amount_received,
        tc.change_amount AS collection_change_amount,
        tc.id AS collection_id,
        CONVERT(th.status USING utf8mb4) COLLATE utf8mb4_unicode_ci                   AS status,
        CONVERT(th.denial_reason USING utf8mb4) COLLATE utf8mb4_unicode_ci            AS denial_reason,
        th.created_at,
        CONVERT(th.notes USING utf8mb4) COLLATE utf8mb4_unicode_ci                    AS description
    FROM transaction_history th
    LEFT JOIN transaction_collection_items tci ON tci.transaction_id = th.id
    LEFT JOIN transaction_collections tc ON tc.id = tci.collection_id
    JOIN food_order_items foi ON foi.id = th.reference_id
    JOIN requests r ON r.id = foi.request_id
    WHERE th.transaction_type = 'food' AND th.archived_at IS NULL
";

// Apply type filter
$unionParts = [];
if ($filterType === 'all' || $filterType === 'check_in')  $unionParts[] = $q1;
if ($filterType === 'all' || $filterType === 'extension')  $unionParts[] = $q2;
if ($filterType === 'all' || $filterType === 'service')    $unionParts[] = $q3;
if ($filterType === 'all' || $filterType === 'food')       $unionParts[] = $q4;

if (empty($unionParts)) {
    $allTx = [];
} else {
    $unionSql = '(' . implode(') UNION ALL (', $unionParts) . ')';
    $wrapSql  = "SELECT * FROM ($unionSql) AS tx WHERE 1=1";

    $params     = [];
    $paramTypes = '';

    if (!empty($filterGuest)) {
        $wrapSql    .= " AND (guest_label LIKE ? OR room_number LIKE ?)";
        $like        = '%' . $filterGuest . '%';
        $params[]    = $like;
        $params[]    = $like;
        $paramTypes .= 'ss';
    }
    if (!empty($filterFrom)) {
        $wrapSql    .= " AND DATE(created_at) >= ?";
        $params[]    = $filterFrom;
        $paramTypes .= 's';
    }
    if (!empty($filterTo)) {
        $wrapSql    .= " AND DATE(created_at) <= ?";
        $params[]    = $filterTo;
        $paramTypes .= 's';
    }
    if ($filterStatus === 'change') {
        $wrapSql .= " AND COALESCE(collection_change_amount, 0) > 0";
    } elseif ($filterStatus !== 'all') {
        $wrapSql    .= " AND status = ?";
        $params[]    = $filterStatus;
        $paramTypes .= 's';
    }

    // Deterministic newest-first ordering — the tx_id tiebreaker keeps rows
    // created in the same second from shuffling (e.g. a fresh room 11 check-in).
    $wrapSql .= " ORDER BY created_at DESC, tx_id DESC";

    if (!empty($params)) {
        $stmt = $conn->prepare($wrapSql);
        $stmt->bind_param($paramTypes, ...$params);
        $stmt->execute();
        $allTx = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $res   = $conn->query($wrapSql);
        $allTx = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// ── Detailed-view pagination (filters + summary aggregates stay full) ─────
$detailPerPage = 15;
$detailTotal   = count($allTx);
$detailPages   = max(1, (int)ceil($detailTotal / $detailPerPage));
$detailPage    = (int)($_GET['page'] ?? 1);
if ($detailPage < 1)            { $detailPage = 1; }
if ($detailPage > $detailPages) { $detailPage = $detailPages; }
$detailOffset  = ($detailPage - 1) * $detailPerPage;
$detailTx       = array_slice($allTx, $detailOffset, $detailPerPage);

$pageQuery = $_GET;
unset($pageQuery['report']);
$detailPageUrl = function (int $p) use ($pageQuery): string {
    $pageQuery['page'] = $p;
    return 'transaction_history.php?' . http_build_query($pageQuery);
};

$unpaidLedgerStmt = $conn->prepare(
    "SELECT room_number, transaction_type AS tx_type, amount
         FROM transaction_history
         WHERE status = 'unpaid'
             AND archived_at IS NULL
               AND (
                   -- check_in rows intentionally have no EXISTS guard: tokens are
                   -- swept/revoked (archived to guest_tokens_history) while the
                   -- charge is still unpaid; the charge must stay visible & collectable
                   (transaction_type = 'check_in')
                   OR (transaction_type = 'extension' AND EXISTS (SELECT 1 FROM extension_history WHERE extension_history.id = transaction_history.reference_id))
                   OR (transaction_type = 'service' AND EXISTS (SELECT 1 FROM service_requests WHERE service_requests.id = transaction_history.reference_id))
                   OR (transaction_type = 'food' AND EXISTS (SELECT 1 FROM food_order_items WHERE food_order_items.id = transaction_history.reference_id))
               )
         ORDER BY room_number, id"
);
$unpaidLedgerStmt->execute();
$unpaidLedgerRows = $unpaidLedgerStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$unpaidLedgerStmt->close();

// ── Summary period filter ─────────────────────────────────────
$summaryPeriod = $_GET['period'] ?? 'all';
$validPeriods  = ['daily', 'weekly', 'monthly', 'all'];
if (!in_array($summaryPeriod, $validPeriods, true)) $summaryPeriod = 'all';

// The period boundaries are the HOTEL's days, not this server's PHP timezone
// (includes/server_clock.php): PHP runs six hours behind the database on this box, so
// "today" used to start a day late and the weekly/monthly windows were shifted with it.
$hotelToday = marle_hotel_date(0, $conn);
$summaryPeriodLabel = '';
switch ($summaryPeriod) {
    case 'daily':
        $periodStart = $hotelToday;
        $summaryPeriodLabel = 'Today (' . date('M j, Y', strtotime($hotelToday)) . ')';
        break;
    case 'weekly':
        $periodStart = date('Y-m-d', strtotime('monday this week', strtotime($hotelToday)));
        $summaryPeriodLabel = 'This Week (' . date('M j', strtotime('monday this week', strtotime($hotelToday))) . ' – ' . date('M j', strtotime('sunday this week', strtotime($hotelToday))) . ')';
        break;
    case 'monthly':
        $periodStart = date('Y-m-01', strtotime($hotelToday));
        $summaryPeriodLabel = date('F Y', strtotime($hotelToday));
        break;
    default:
        $periodStart = null;
        $summaryPeriodLabel = 'All Time';
        break;
}

// ── Summary aggregates ────────────────────────────────────────
$summaryByType = ['check_in' => 0.0, 'extension' => 0.0, 'service' => 0.0, 'food' => 0.0];
$roomTypeBuckets = ['Suite', 'Without Garage', 'Family', 'With Garage'];
$summaryByRoomType = array_fill_keys($roomTypeBuckets, 0.0);
$overallTotal  = 0.0;

foreach ($allTx as $tx) {
    // Only count paid / approved amounts (not denied, not unpaid pending)
    $countable = in_array($tx['status'], ['paid', 'approved', 'unpaid']);
    if (!$countable) continue;

    // Apply period filter for summary
    if ($periodStart !== null) {
        $txDate = date('Y-m-d', strtotime($tx['created_at']));
        if ($txDate < $periodStart) continue;
    }

    $amt = (float)$tx['amount'];
    $summaryByType[$tx['tx_type']] = ($summaryByType[$tx['tx_type']] ?? 0.0) + $amt;
    $roomType = trim((string)($tx['room_type'] ?? ''));
    foreach ($roomTypeBuckets as $roomTypeBucket) {
        if (strcasecmp($roomType, $roomTypeBucket) === 0) {
            $summaryByRoomType[$roomTypeBucket] += $amt;
            break;
        }
    }
    $overallTotal += $amt;
}

$unpaidCollections = [];
foreach ($unpaidLedgerRows as $tx) {
    $room = trim((string)$tx['room_number']);
    if ($room === '') continue;

    if (!isset($unpaidCollections[$room])) {
        $unpaidCollections[$room] = [
            'room_number' => $room,
            'total'       => 0.0,
            'item_count'  => 0,
            'types'       => [],
        ];
    }

    $unpaidCollections[$room]['total'] += (float)$tx['amount'];
    $unpaidCollections[$room]['item_count']++;
    $unpaidCollections[$room]['types'][$tx['tx_type']] = true;
}

uasort($unpaidCollections, fn($a, $b) => $b['total'] <=> $a['total']);

$reportParams = [
    'report' => '1',
    'type'   => $filterType,
    'guest'  => $filterGuest,
    'from'   => $filterFrom,
    'to'     => $filterTo,
    'status' => $filterStatus,
    'period' => $summaryPeriod,
];
$reportUrl = 'transaction_history.php?' . http_build_query($reportParams);

$reportTx = [];
foreach ($allTx as $tx) {
    if ($periodStart !== null) {
        $txDate = date('Y-m-d', strtotime($tx['created_at']));
        if ($txDate < $periodStart) continue;
    }
    $reportTx[] = $tx;
}

$filterSummary = [];
$filterSummary[] = 'Type: ' . ($filterType === 'all' ? 'All Types' : ucwords(str_replace('_', ' ', $filterType)));
$filterSummary[] = 'Payment: ' . ($filterStatus === 'all' ? 'All Statuses' : ($filterStatus === 'change' ? 'Change Received' : ucfirst($filterStatus)));
$filterSummary[] = 'Period: ' . $summaryPeriodLabel;
if ($filterGuest !== '') $filterSummary[] = 'Guest / Room: ' . $filterGuest;
if ($filterFrom !== '') $filterSummary[] = 'From: ' . date('M j, Y', strtotime($filterFrom));
if ($filterTo !== '') $filterSummary[] = 'To: ' . date('M j, Y', strtotime($filterTo));

$reportPdfRows = [];
foreach ($reportTx as $i => $tx) {
    $reportPdfRows[] = [
        (string)($i + 1),
        ucwords(str_replace('_', ' ', $tx['tx_type'])),
        $tx['guest_label'],
        $tx['room_type'],
        $tx['description'] ?? '-',
        $tx['status'] === 'denied' ? '-' : 'PHP ' . number_format((float)$tx['amount'], 2),
        $tx['collection_total_due'] === null ? '-' : 'PHP ' . number_format((float)$tx['collection_total_due'], 2),
        $tx['collection_amount_received'] === null ? '-' : 'PHP ' . number_format((float)$tx['collection_amount_received'], 2),
        $tx['collection_change_amount'] === null ? '-' : 'PHP ' . number_format((float)$tx['collection_change_amount'], 2),
        ucfirst($tx['status']),
        date('M j, Y g:ia', strtotime($tx['created_at'])),
    ];
}

$reportPdfData = [
    'filename' => 'Transaction_Report_' . $summaryPeriod . '_' . marle_hotel_date(0, $conn) . '.pdf',
    'generated' => date('F j, Y g:i A', strtotime(marle_db_now($conn))),
    'periodLabel' => $summaryPeriodLabel,
    'filters' => implode(' | ', $filterSummary),
    'overallTotal' => 'PHP ' . number_format($overallTotal, 2),
    'summary' => [
        ['label' => 'Check-In', 'value' => 'PHP ' . number_format($summaryByType['check_in'], 2)],
        ['label' => 'Extension', 'value' => 'PHP ' . number_format($summaryByType['extension'], 2)],
        ['label' => 'Service', 'value' => 'PHP ' . number_format($summaryByType['service'], 2)],
        ['label' => 'Food', 'value' => 'PHP ' . number_format($summaryByType['food'], 2)],
    ],
    'rows' => $reportPdfRows,
];

if (isset($_GET['report']) && $_GET['report'] === '1') {
    if (!$canGenerateReports) {
        http_response_code(403);
        exit('Report generation is not available for your role.');
    }
    log_event($conn, 'report_generated', 'transaction_report', null, 'success', implode(' | ', $filterSummary));
    ?>
<!DOCTYPE html>
<html lang="<?= ui_html_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Report - Marle Hotel</title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #eef0f3;
            color: #1f2933;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            line-height: 1.35;
        }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 12mm;
            background: #fff;
        }
        .report-top {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            border-bottom: 2px solid #933310;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .brand { color: #933310; font-size: 20px; font-weight: 700; margin: 0; }
        .title { font-size: 13px; font-weight: 700; margin: 3px 0 0; color: #333; }
        .meta { text-align: right; color: #555; font-size: 10px; }
        .filters {
            color: #555;
            border: 1px solid #e5e7eb;
            background: #fafafa;
            padding: 7px 9px;
            margin-bottom: 10px;
        }
        .total-band {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #933310;
            color: #fff;
            padding: 10px 12px;
            margin-bottom: 8px;
        }
        .total-label { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; opacity: .8; }
        .total-value { font-size: 22px; font-weight: 700; }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 14px;
        }
        .summary-box {
            border: 1px solid #e5e7eb;
            padding: 8px;
            min-height: 48px;
        }
        .summary-name { color: #666; font-size: 10px; margin-bottom: 5px; }
        .summary-value { font-size: 14px; font-weight: 700; color: #222; }
        .section-title {
            font-size: 12px;
            color: #933310;
            font-weight: 700;
            margin: 12px 0 6px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        tr { page-break-inside: avoid; }
        th {
            background: #f2ece9;
            color: #333;
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            border-bottom: 1px solid #d8d2ce;
            padding: 6px 5px;
            /* Headers must be free to wrap: in a table-layout:fixed table a
               nowrap header spills into the next column, which is what made
               "AMOUNT RECEIVED" and "CHANGE" run together. */
            overflow-wrap: break-word;
            white-space: normal;
        }
        td {
            border-bottom: 1px solid #ededed;
            padding: 5px;
            vertical-align: top;
            word-wrap: break-word;
        }
        .num { text-align: right; }
        /* Values stay on one line; only the data cells, never the headers. */
        td.num { white-space: nowrap; }
        .nowrap { white-space: nowrap; }
        .muted { color: #777; }
        .footer-total td {
            border-top: 2px solid #933310;
            border-bottom: 0;
            font-weight: 700;
            padding-top: 8px;
        }
        .empty {
            border: 1px solid #e5e7eb;
            padding: 14px;
            text-align: center;
            color: #777;
        }
        @media print {
            body { background: #fff; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; }
        }
    </style>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930-final">
    <link rel="stylesheet" href="../css/staff-transactions.css?v=20261021">
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>
<body class="staff-page staff-transactions-page staff-superadmin">
<main class="sheet">
    <header class="report-top">
        <div>
            <h1 class="brand">Marle Hotel &amp; Restaurant</h1>
            <p class="title">Transaction Report</p>
        </div>
        <div class="meta">
            <!-- The database's clock, not PHP's: every line below is stamped by MySQL, so a
                 "Generated" time from PHP's clock (six hours apart on this host) dated the
                 report against data it did not come from. Same helper the CSV export uses. -->
            <div>Generated: <?= date('F j, Y g:i A', strtotime(marle_db_now($conn))) ?></div>
            <div><?= htmlspecialchars($summaryPeriodLabel) ?></div>
        </div>
    </header>

    <section class="filters">
        <?= htmlspecialchars(implode(' | ', $filterSummary)) ?>
    </section>

    <section class="total-band">
        <div>
            <div class="total-label">Overall Total</div>
            <div><?= htmlspecialchars($summaryPeriodLabel) ?></div>
        </div>
        <div class="total-value">&#8369;<?= number_format($overallTotal, 2) ?></div>
    </section>

    <section class="summary-grid">
        <div class="summary-box">
            <div class="summary-name">Check-In</div>
            <div class="summary-value">&#8369;<?= number_format($summaryByType['check_in'], 2) ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-name">Extension</div>
            <div class="summary-value">&#8369;<?= number_format($summaryByType['extension'], 2) ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-name">Service</div>
            <div class="summary-value">&#8369;<?= number_format($summaryByType['service'], 2) ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-name">Food</div>
            <div class="summary-value">&#8369;<?= number_format($summaryByType['food'], 2) ?></div>
        </div>
    </section>

    <h2 class="section-title">Detailed Transactions</h2>
    <?php if (empty($reportTx)): ?>
        <div class="empty">No transactions match the selected filters.</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <?php /* 11 columns that add up to exactly 100% — table-layout is
                         fixed, so percentages over 100 squeezed the columns into
                         each other. The Collection Reference column is gone: the
                         report is a money ledger, and the reference belongs on the
                         screen's collection workflow, not on the printed summary. */ ?>
                <th style="width:3%;">#</th>
                <th style="width:8%;">Type</th>
                <th style="width:11%;">Guest</th>
                <th style="width:9%;">Room</th>
                <th style="width:13%;">Description</th>
                <th style="width:9%;" class="num">Amount</th>
                <th style="width:9%;" class="num">Amount Due</th>
                <th style="width:10%;" class="num">Amount Received</th>
                <th style="width:8%;" class="num">Change</th>
                <th style="width:7%;">Status</th>
                <th style="width:13%;">Date</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($reportTx as $i => $tx): ?>
            <tr>
                <td class="muted"><?= $i + 1 ?></td>
                <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $tx['tx_type']))) ?></td>
                <td><?= htmlspecialchars($tx['guest_label']) ?></td>
                <td><?= htmlspecialchars($tx['room_type']) ?></td>
                <td><?= htmlspecialchars($tx['description'] ?? '-') ?></td>
                <td class="num">
                    <?= $tx['status'] === 'denied' ? '-' : '&#8369;' . number_format((float)$tx['amount'], 2) ?>
                </td>
                <td class="num"><?= $tx['collection_total_due'] === null ? '-' : '&#8369;' . number_format((float)$tx['collection_total_due'], 2) ?></td>
                <td class="num"><?= $tx['collection_amount_received'] === null ? '-' : '&#8369;' . number_format((float)$tx['collection_amount_received'], 2) ?></td>
                <td class="num"><?= $tx['collection_change_amount'] === null ? '-' : '&#8369;' . number_format((float)$tx['collection_change_amount'], 2) ?></td>
                <td><?= htmlspecialchars(ucfirst($tx['status'])) ?></td>
                <td class="nowrap"><?= date('M j, Y g:ia', strtotime($tx['created_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="footer-total">
                <td colspan="5" class="num">Total</td>
                <td class="num">&#8369;<?= number_format($overallTotal, 2) ?></td>
                <td colspan="5"></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</main>
<script>
    window.addEventListener('load', function () {
        window.print();
    });
</script>
</body>
</html>
<?php
    exit();
}

// ── Admin profile picture ─────────────────────────────────────
$username   = $_SESSION['username'];
$sqlUser    = "SELECT picture FROM userstbl WHERE username = '$username' LIMIT 1";
$resultUser = mysqli_query($conn, $sqlUser);
$imgSrc     = "../img/default.jpg";
if ($resultUser && mysqli_num_rows($resultUser) > 0) {
    $userRow = mysqli_fetch_assoc($resultUser);
    if (!empty($userRow['picture'])) {
        $finfo  = new finfo(FILEINFO_MIME_TYPE);
        $mime   = $finfo->buffer($userRow['picture']);
        $imgSrc = "data:$mime;base64," . base64_encode($userRow['picture']);
    }
}

// ── Helpers ───────────────────────────────────────────────────
function txTypeBadge(string $type): string {
    return match($type) {
        'check_in'  => '<span class="badge" style="background:#352414; font-size:10px;">Check-In</span>',
        'extension' => '<span class="badge bg-primary" style="font-size:10px;">Extension</span>',
        'service'   => '<span class="badge" style="background:#933310; font-size:10px;">Service</span>',
        'food'      => '<span class="badge" style="background:#2e7d32; font-size:10px;">Food</span>',
        default     => '<span class="badge bg-secondary" style="font-size:10px;">' . ucfirst($type) . '</span>',
    };
}

function statusBadge(string $status): string {
    return match($status) {
        'paid'     => '<span class="badge bg-success" style="font-size:10px;">Paid</span>',
        'unpaid'   => '<span class="badge bg-warning text-dark" style="font-size:10px;">Unpaid</span>',
        'approved' => '<span class="badge bg-success" style="font-size:10px;">Approved</span>',
        'denied'   => '<span class="badge bg-danger" style="font-size:10px;">Denied</span>',
        default    => '<span class="badge bg-secondary" style="font-size:10px;">' . ucfirst($status) . '</span>',
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
    <link rel="stylesheet" href="../css/adminDB.css">
    <?php require __DIR__ . '/../includes/staff_icons.php'; ?>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
    <title>Transaction History — Marle Hotel</title>
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
        #yesBtn { background-color: #ffb60a; color: #fff; }
        

        .main-card {
            background-color: #fff; border-radius: 20px;
            width: min(calc(100% - var(--sidebar-w) - 40px), 1400px); max-width: 1400px;
            padding: 24px; margin: 20px 20px 20px calc(var(--sidebar-w) + max(20px, (100% - var(--sidebar-w) - 1400px) / 2));
        }
        @media (max-width: 768px) { .main-card { margin: 10px; width: calc(100% - 20px); } }

        /* Summary cards */
        /* Summary — Option B */
        .b-badge { background:#f9f6f3; border-radius:10px; padding:12px 16px; display:flex; justify-content:space-between; align-items:center; }
        .b-badge-name { font-size:0.78rem; color:#666; display:flex; align-items:center; gap:6px; }
        .b-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; display:inline-block; }
        .b-badge-val { font-size:0.95rem; font-weight:700; color:#333; }

        /* Filter bar */
        .filter-bar { background: #f9f6f3; border-radius: 14px; padding: 14px 16px; margin-bottom: 20px; }
        .filter-bar .form-control, .filter-bar .form-select { font-size: 0.88rem; }
        .filter-bar .btn { font-size: 0.88rem; }

        /* View toggle */
        .view-toggle .btn { border-radius: 10px; font-size: 0.85rem; }
        .view-toggle .btn.active { background: #ffb60a; color: #fff; border-color: #ffb60a; }

        /* Period filter */
        #periodFilter .btn { border-radius: 10px; font-size: 0.82rem; }
        #periodFilter .btn.active { background: #933310; color: #fff; border-color: #933310; }

        /* Print / PDF buttons */
        .summary-actions .btn { font-size: 0.82rem; border-radius: 10px; }

        /* Print styles */
        @media print {
            body * { visibility: hidden; }
            #fullPrintArea, #fullPrintArea * { visibility: visible; }
            #fullPrintArea {
                position: absolute; left: 0; top: 0; width: 100%;
                padding: 20px;
            }
            .navbar, .sidebar, .view-toggle, #periodFilter, .summary-actions,
            .modal, .notif-dropdown-widget, .alert,
            .navbar-bottom-mobile, .filter-bar, .archiveBtn, script { display: none !important; }
            .metric-card { break-inside: avoid; border: 1px solid #ddd !important; }
            #printDetailDivider { display: block !important; }
            #printDetailTable { display: block !important; }
        } 

        /* Table */
        .table-responsive { width: 100%; overflow-x: hidden; }
        .table-responsive > table { width: 100%; max-width: 100%; table-layout: fixed; }
        .table-responsive th,
        .table-responsive td { white-space: normal !important; overflow-wrap: anywhere; word-break: break-word; }
        .table-responsive .table th { font-size: 0.62rem; text-transform: uppercase; color: #6c757d; }
        .table-responsive .table td { font-size: 0.68rem; vertical-align: middle; padding: 0.35rem 0.2rem; }
        .table-responsive .btn { padding: 0.2rem 0.25rem !important; }
        @media (max-width: 768px) {
            .table-responsive .table th { font-size: 0.55rem; }
            .table-responsive .table td { font-size: 0.6rem; padding: 0.25rem 0.15rem; }
        }
        .table tr:hover { background: #fffbf0; }

        /* Room totals in summary */
        .room-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f0e8e0; }
        .room-row:last-child { border-bottom: none; }

        /* Payment note banner */
        .payment-note {
            background: #fff8ec; border: 1px solid #ffb60a; border-radius: 10px;
            padding: 10px 16px; font-size: 0.85rem; color: #6c4300;
            display: flex; align-items: center; gap: 10px; margin-bottom: 16px;
        }

        .collection-panel {
            background:#fff8ec; border:1px solid #f3d18a; border-radius:14px;
            padding:16px; margin-bottom:18px;
        }
        .collection-row {
            background:#fff; border:1px solid #f0e2d5; border-radius:10px;
            padding:12px 14px; display:flex; align-items:center; justify-content:space-between;
            gap:12px; margin-top:10px;
        }
        .collection-types { display:flex; flex-wrap:wrap; gap:6px; }
        .collection-types .badge { font-size:10px; }

        .section-head { font-size: 0.9rem; font-weight: 600; color: #933310; margin-bottom: 12px; padding-bottom: 6px; border-bottom: 2px solid #ffb60a; }
    #fullPrintArea .metric-card {
    border-left: none !important;
    border-radius: 8px !important;
    border-top: 4px solid #ffb60a;
    box-shadow: none !important;
}
#fullPrintArea .metric-card.check-in  { border-top-color: #352414; }
#fullPrintArea .metric-card.extension { border-top-color: #0d6efd; }
#fullPrintArea .metric-card.service   { border-top-color: #933310; }
#fullPrintArea .metric-card.overall   { border-top-color: #198754; }
#fullPrintArea .metric-card.food      { border-top-color: #2e7d32; }

    </style>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930-live">
    <link rel="stylesheet" href="../css/staff-footer.css">
    <link rel="stylesheet" href="../css/staff-transactions.css?v=20261021">
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>
<body class="staff-page staff-transactions-page staff-superadmin">

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

<!-- ═══════════════ MAIN CONTENT ══════════════ -->
<?php
    $staffPageTitle  = 'Transaction History';
    $staffPageNote   = 'Every payment and charge, with a printable summary.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['Transaction History', null]];
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

<div class="main-card">

    <!-- Status messages -->
    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i>Transaction updated successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['archived'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-archive me-2"></i>Transaction archived successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['collected'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-cash-coin me-2"></i>Room payment collected successfully.
            Change: &#8369;<?= number_format((float)($_GET['change'] ?? 0), 2) ?>
            <?php if (!empty($_GET['collection_id'])): ?>
                <span class="ms-2">Collection #<?= htmlspecialchars($_GET['collection_id']) ?></span>
            <?php endif; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ── VIEW TOGGLE ── -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div class="view-toggle btn-group">
            <button type="button" class="btn btn-outline-secondary active" id="btnSummaryView" onclick="showView('summary')">
                <i class="bi bi-bar-chart-line me-1"></i>Summary
            </button>
            <button type="button" class="btn btn-outline-secondary" id="btnDetailView" onclick="showView('detail')">
                <i class="bi bi-list-ul me-1"></i>Detailed
            </button>
        </div>
        <span class="text-muted small"><?= count($allTx) ?> records found</span>
    </div>

    <!-- ════════════════════════════════════════
         SUMMARY VIEW
    ════════════════════════════════════════ -->
    <div id="summaryView">

        <div class="transaction-summary-controls">
        <!-- Period filter -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div class="btn-group" id="periodFilter">
                <?php
                $periods = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'all' => 'All Time'];
                // Preserve existing GET params
                $baseParams = $_GET;
                foreach ($periods as $pKey => $pLabel):
                    $baseParams['period'] = $pKey;
                    $active = ($summaryPeriod === $pKey) ? ' active' : '';
                ?>
                <a href="?<?= http_build_query($baseParams) ?>"
                   class="btn btn-outline-secondary btn-sm<?= $active ?>"><?= $pLabel ?></a>
                <?php endforeach; ?>
            </div>
            <span class="text-muted small"><i class="bi bi-calendar3 me-1"></i><?= $summaryPeriodLabel ?></span>
        </div>

        <!-- Print / Download buttons -->
        <?php if ($canGenerateReports): ?>
        <div class="d-flex gap-2 mb-3 summary-actions">
            <a href="<?= htmlspecialchars($reportUrl) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </a>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="downloadTransactionPDF()">
                <i class="bi bi-file-earmark-pdf me-1"></i>Download PDF
            </button>
        </div>
        <?php endif; ?>

        </div>

       <!-- Printable full report area (summary + detail stacked) -->
        <div id="fullPrintArea">
        <div id="summaryPrintArea">

        <!-- Print header (hidden on screen, visible on print/PDF) -->
        <div id="printHeader" style="display:none; margin-bottom:20px;">
            <h3 style="margin:0; color:#933310;">Marle Hotel &amp; Restaurant</h3>
            <h5 style="margin:4px 0 2px; color:#333;">Transaction Summary — <?= $summaryPeriodLabel ?></h5>
            <p style="margin:0; font-size:0.85rem; color:#666;">Generated on <?= date('F j, Y g:i A', strtotime(marle_db_now($conn))) ?></p>
            <hr style="border-color:#ddd;">
        </div>

        <!-- Metric cards -->
      <!-- Hero banner -->
        <div style="background:#933310;border-radius:14px;padding:20px 24px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.08em;color:rgba(255,255,255,0.65);margin-bottom:4px;">Overall Total — <?= htmlspecialchars($summaryPeriodLabel) ?></div>
                <div style="font-size:2rem;font-weight:700;color:#fff;">&#8369;<?= number_format($overallTotal, 2) ?></div>
                <div style="font-size:0.75rem;color:rgba(255,255,255,0.55);margin-top:2px;">All transaction types</div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.08em;color:rgba(255,255,255,0.65);margin-bottom:4px;">Period</div>
                <div style="font-size:1rem;color:#fff;font-weight:600;"><?= htmlspecialchars($summaryPeriodLabel) ?></div>
            </div>
        </div>

        <!-- Badge tiles -->
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
            <div class="b-badge" style="flex:1 1 calc(25% - 8px);min-width:130px;">
                <div class="b-badge-name"><span class="b-dot" style="background:#352414;"></span>Check-In</div>
                <div class="b-badge-val">&#8369;<?= number_format($summaryByType['check_in'], 2) ?></div>
            </div>
            <div class="b-badge" style="flex:1 1 calc(25% - 8px);min-width:130px;">
                <div class="b-badge-name"><span class="b-dot" style="background:#0d6efd;"></span>Extension</div>
                <div class="b-badge-val">&#8369;<?= number_format($summaryByType['extension'], 2) ?></div>
            </div>
            <div class="b-badge" style="flex:1 1 calc(25% - 8px);min-width:130px;">
                <div class="b-badge-name"><span class="b-dot" style="background:#933310;"></span>Service</div>
                <div class="b-badge-val">&#8369;<?= number_format($summaryByType['service'], 2) ?></div>
            </div>
            <div class="b-badge" style="flex:1 1 calc(25% - 8px);min-width:130px;">
                <div class="b-badge-name"><span class="b-dot" style="background:#2e7d32;"></span>Food</div>
                <div class="b-badge-val">&#8369;<?= number_format($summaryByType['food'], 2) ?></div>
            </div>
        </div>

        <!-- Revenue by room + by type -->
        <div class="row g-4">
            <div class="col-12 col-md-6">
                <p class="section-head"><i class="bi bi-door-open me-1"></i>Revenue by Room Type</p>
                <?php foreach ($summaryByRoomType as $roomType => $total): ?>
                    <div class="room-row">
                        <span class="fw-semibold"><?= htmlspecialchars($roomType) ?></span>
                        <span class="fw-bold" style="color:#333;">&#8369;<?= number_format($total, 2) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="col-12 col-md-6">
                <p class="section-head"><i class="bi bi-pie-chart me-1"></i>Revenue by Type</p>
                <div style="display:flex;align-items:center;gap:20px;">
                    <canvas id="donutChart" width="90" height="90" style="flex-shrink:0;">
                        <circle cx="32" cy="32" r="26" fill="none" stroke="#e9ecef" stroke-width="10"/>
                        <?php
                        $circ = round(2 * M_PI * 26, 2);
                        $donut_types = [
                            ['key'=>'check_in',  'color'=>'#352414'],
                            ['key'=>'extension', 'color'=>'#0d6efd'],
                            ['key'=>'service',   'color'=>'#933310'],
                            ['key'=>'food',      'color'=>'#2e7d32'],
                        ];
                        $cumul = 0;
                        foreach ($donut_types as $dt):
                            $pct  = $overallTotal > 0 ? ($summaryByType[$dt['key']] / $overallTotal) : 0;
                            $dash = round($pct * $circ, 2);
                            if ($dash <= 0) continue;
                            $gap    = round($circ - $dash, 2);
                            $offset = round($circ * 0.25 - $cumul, 2);
                            $cumul += $dash;
                        ?>
                        <circle cx="32" cy="32" r="26" fill="none"
                                stroke="<?= $dt['color'] ?>" stroke-width="10"
                                stroke-dasharray="<?= $dash ?> <?= $gap ?>"
                                stroke-dashoffset="<?= $offset ?>"/>
                        <?php endforeach; ?>
                    </canvas>
                    <div style="flex:1;">
                        <?php
                        $types = [
                            'check_in'  => ['Check-In',  '#352414'],
                            'extension' => ['Extension', '#0d6efd'],
                            'service'   => ['Service',   '#933310'],
                            'food'      => ['Food',      '#2e7d32'],
                        ];
                        foreach ($types as $key => [$label, $color]):
                            $amt = $summaryByType[$key] ?? 0;
                            $pct = $overallTotal > 0 ? ($amt / $overallTotal) * 100 : 0;
                        ?>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:0.82rem;">
                            <span style="width:8px;height:8px;border-radius:50%;background:<?= $color ?>;display:inline-block;flex-shrink:0;"></span>
                            <span style="color:#555;flex:1;"><?= $label ?></span>
                            <span style="color:#333;font-weight:600;"><?= number_format($pct,1) ?>%</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        </div><!-- /#summaryPrintArea -->

        <!-- Print-only: detail section header + table (hidden on screen) -->
        <div id="printDetailDivider" style="display:none; margin-top:28px; border-top:2px solid #933310; padding-top:16px;">
            <h5 style="margin:0 0 4px; color:#933310;">Transaction Detail</h5>
            <p style="margin:0 0 14px; font-size:0.8rem; color:#666;">All transactions for the period — <?= htmlspecialchars($summaryPeriodLabel) ?></p>
        </div>
        <div id="printDetailTable" style="display:none;">
        <?php if (!empty($allTx)): ?>
        <table style="width:100%; border-collapse:collapse; font-size:0.78rem;">
            <thead>
                <tr style="background:#f2ece9;">
                    <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #ddd;">#</th>
                    <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #ddd;">Type</th>
                    <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #ddd;">Guest</th>
                    <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #ddd;">Room</th>
                    <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #ddd;">Description</th>
                    <th style="padding:6px 8px; text-align:right; border-bottom:1px solid #ddd;">Amount</th>
                    <th style="padding:6px 8px; text-align:right; border-bottom:1px solid #ddd;">Amount Due</th>
                    <th style="padding:6px 8px; text-align:right; border-bottom:1px solid #ddd;">Amount Received</th>
                    <th style="padding:6px 8px; text-align:right; border-bottom:1px solid #ddd;">Change</th>
                    <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #ddd;">Status</th>
                    <th style="padding:6px 8px; text-align:left; border-bottom:1px solid #ddd;">Date</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allTx as $i => $tx): ?>
            <tr style="border-bottom:0.5px solid #eee; <?= ($i % 2 === 1) ? 'background:#fafafa;' : '' ?>">
                <td style="padding:5px 8px; color:#888;"><?= $i + 1 ?></td>
                <td style="padding:5px 8px; text-transform:capitalize;"><?= htmlspecialchars(str_replace('_', ' ', $tx['tx_type'])) ?></td>
                <td style="padding:5px 8px; font-weight:600;"><?= htmlspecialchars($tx['guest_label']) ?></td>
                <td style="padding:5px 8px;"><?= htmlspecialchars($tx['room_type']) ?></td>
                <td style="padding:5px 8px;"><?= htmlspecialchars($tx['description'] ?? '—') ?></td>
                <td style="padding:5px 8px; text-align:right; font-weight:600;">
                    <?= $tx['status'] === 'denied' ? '—' : '₱' . number_format((float)$tx['amount'], 2) ?>
                </td>
                <td style="padding:5px 8px; text-align:right;"><?= $tx['collection_total_due'] === null ? '—' : '₱' . number_format((float)$tx['collection_total_due'], 2) ?></td>
                <td style="padding:5px 8px; text-align:right;"><?= $tx['collection_amount_received'] === null ? '—' : '₱' . number_format((float)$tx['collection_amount_received'], 2) ?></td>
                <td style="padding:5px 8px; text-align:right;"><?= $tx['collection_change_amount'] === null ? '—' : '₱' . number_format((float)$tx['collection_change_amount'], 2) ?></td>
                <td style="padding:5px 8px;"><?= htmlspecialchars(ucfirst($tx['status'])) ?></td>
                <td style="padding:5px 8px; white-space:nowrap;"><?= date('M j, Y g:ia', strtotime($tx['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" style="padding:8px; text-align:right; font-size:0.8rem; color:#666; border-top:1px solid #ddd;">Total</td>
                    <td style="padding:8px; text-align:right; font-weight:700; border-top:1px solid #ddd;">₱<?= number_format($overallTotal, 2) ?></td>
                    <td colspan="5" style="border-top:1px solid #ddd;"></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
        </div>

        </div><!-- /#fullPrintArea -->
    </div><!-- /#summaryView -->

    <!-- ════════════════════════════════════════
         DETAILED VIEW
    ════════════════════════════════════════ -->
    <div id="detailView" style="display:none;">

        <div class="collection-panel">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h6 class="mb-1" style="color:#933310;"><i class="bi bi-cash-coin me-1"></i>Unpaid Collections</h6>
                    <p class="text-muted small mb-0">Grouped by room for front-desk payment collection.</p>
                </div>
                <span class="badge bg-warning text-dark"><?= count($unpaidCollections) ?> room<?= count($unpaidCollections) === 1 ? '' : 's' ?> with unpaid balance</span>
            </div>

            <?php if (empty($unpaidCollections)): ?>
                <div class="text-muted small mt-3">No unpaid room balances match the current filters.</div>
            <?php else: ?>
                <?php foreach ($unpaidCollections as $room => $collection): ?>
                    <div class="collection-row">
                        <div>
                            <div class="fw-bold">Room <?= htmlspecialchars($collection['room_number']) ?></div>
                            <div class="small text-muted">
                                <?= (int)$collection['item_count'] ?> unpaid item<?= (int)$collection['item_count'] === 1 ? '' : 's' ?>
                            </div>
                            <div class="collection-types mt-2">
                                <?php foreach (array_keys($collection['types']) as $type): ?>
                                    <?= txTypeBadge($type) ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold fs-5">&#8369;<?= number_format($collection['total'], 2) ?></div>
                            <button type="button"
                                    class="btn btn-sm mt-1 collect-room-btn"
                                    style="background:#ffb60a;color:#fff;"
                                    data-room="<?= htmlspecialchars($collection['room_number']) ?>"
                                    data-total="<?= number_format($collection['total'], 2, '.', '') ?>">
                                <i class="bi bi-check2-circle me-1"></i>Collect Payment
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Filter bar -->
        <form method="GET" action="">
            <div class="filter-bar row g-2 align-items-end">
                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Transaction Type</label>
<select name="type" class="form-select form-select-sm">
                        <option value="all"      <?= $filterType === 'all'       ? 'selected' : '' ?>>All Types</option>
                        <option value="check_in" <?= $filterType === 'check_in'  ? 'selected' : '' ?>>Check-In</option>
                        <option value="extension"<?= $filterType === 'extension' ? 'selected' : '' ?>>Extension</option>
                        <option value="service"  <?= $filterType === 'service'   ? 'selected' : '' ?>>Service</option>
                        <option value="food"     <?= $filterType === 'food'      ? 'selected' : '' ?>>Food</option>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Payment Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="all"    <?= $filterStatus === 'all'    ? 'selected' : '' ?>>All</option>
                        <option value="paid"   <?= $filterStatus === 'paid'   ? 'selected' : '' ?>>Paid</option>
                        <option value="unpaid" <?= $filterStatus === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="denied" <?= $filterStatus === 'denied' ? 'selected' : '' ?>>Denied</option>
                        <option value="change" <?= $filterStatus === 'change' ? 'selected' : '' ?>>Change Received</option>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold small mb-1">Guest / Room</label>
                    <input type="text" name="guest" class="form-control form-control-sm"
                           placeholder="Guest ID or room no."
                           value="<?= htmlspecialchars($filterGuest) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1">From</label>
                    <input type="date" name="from" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filterFrom) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label fw-semibold small mb-1">To</label>
                    <input type="date" name="to" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filterTo) ?>">
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm w-100 fw-semibold"
                            style="background:#ffb60a; color:#fff;" onclick="showView('detail')">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="transaction_history.php" class="btn btn-sm btn-outline-secondary w-100">Reset</a>
                </div>
            </div>
        </form>

        <!-- Transactions table -->
        <?php if (empty($allTx)): ?>
            <div class="alert alert-info mt-3">No transactions match your filters.</div>
        <?php else: ?>
        <div class="table-responsive mt-3">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Type</th>
                        <th>Guest</th>
                        <th>Room Type</th>
                        <th class="tx-description">Description</th>
                        <th>Amount</th>
                        <th>Amount Due</th>
                        <th>Amount Received</th>
                        <th>Change</th>
                        <th>Collection Reference</th>
                        <th>Status</th>
                        <th>Date / Time</th>
                        <th>Note</th>
                        <th style="width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($detailTx as $i => $tx): ?>
                <tr>
                    <td class="text-muted"><?= $detailOffset + $i + 1 ?></td>
                    <td><?= txTypeBadge($tx['tx_type']) ?></td>
                    <td class="fw-semibold" style="white-space:nowrap;">
                        <?= htmlspecialchars($tx['guest_label']) ?>
                    </td>
                    <td><?= htmlspecialchars($tx['room_type']) ?></td>
                    <td class="tx-description"><?= htmlspecialchars($tx['description'] ?? '—') ?></td>
                    <td class="fw-bold">
                        <?php if ($tx['status'] === 'denied'): ?>
                            <span class="text-muted">—</span>
                        <?php else: ?>
                            &#8369;<?= number_format((float)$tx['amount'], 2) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $tx['collection_total_due'] === null ? '-' : '&#8369;' . number_format((float)$tx['collection_total_due'], 2) ?></td>
                    <td><?= $tx['collection_amount_received'] === null ? '-' : '&#8369;' . number_format((float)$tx['collection_amount_received'], 2) ?></td>
                    <td><?= $tx['collection_change_amount'] === null ? '-' : '&#8369;' . number_format((float)$tx['collection_change_amount'], 2) ?></td>
                    <td><?= $tx['collection_id'] === null ? '-' : '#' . (int)$tx['collection_id'] ?></td>
                    <td><?= statusBadge($tx['status']) ?></td>
                    <td class="text-nowrap" style="font-size:0.8rem;">
                        <?= date('M j, Y g:ia', strtotime($tx['created_at'])) ?>
                    </td>
                    <td class="tx-note">
                        <?php if ($tx['status'] === 'denied' && !empty($tx['denial_reason'])): ?>
                            <span class="text-danger small" title="<?= htmlspecialchars($tx['denial_reason']) ?>">
                                <i class="bi bi-info-circle me-1"></i>
                                <?= htmlspecialchars(substr($tx['denial_reason'], 0, 40)) ?>
                                <?= strlen($tx['denial_reason']) > 40 ? '…' : '' ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center; white-space:nowrap;">
                        <button type="button" class="btn btn-sm btn-outline-danger archiveBtn" 
                                data-tx-id="<?= $tx['tx_id'] ?>"
                                title="Archive transaction" style="padding:4px 8px;">
                            <i class="bi bi-archive"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($detailPages > 1): ?>
        <nav class="mt-3" aria-label="Transaction pages">
            <ul class="pagination pagination-sm justify-content-center mb-0 flex-wrap">
                <li class="page-item <?= $detailPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars($detailPageUrl(max(1, $detailPage - 1))) ?>#detailView">Previous</a>
                </li>
                <?php
                $pageStart = max(1, $detailPage - 2);
                $pageEnd   = min($detailPages, $detailPage + 2);
                if ($pageStart > 1) {
                    echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($detailPageUrl(1)) . '#detailView">1</a></li>';
                    if ($pageStart > 2) echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
                }
                for ($p = $pageStart; $p <= $pageEnd; $p++):
                ?>
                    <li class="page-item <?= $p === $detailPage ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars($detailPageUrl($p)) ?>#detailView"><?= $p ?></a>
                    </li>
                <?php
                endfor;
                if ($pageEnd < $detailPages) {
                    if ($pageEnd < $detailPages - 1) echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
                    echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($detailPageUrl($detailPages)) . '#detailView">' . $detailPages . '</a></li>';
                }
                ?>
                <li class="page-item <?= $detailPage >= $detailPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars($detailPageUrl(min($detailPages, $detailPage + 1))) ?>#detailView">Next</a>
                </li>
            </ul>
            <p class="text-center text-muted small mt-2 mb-0">
                Showing <?= $detailOffset + 1 ?>&ndash;<?= min($detailOffset + $detailPerPage, $detailTotal) ?> of <?= $detailTotal ?> transactions
                &middot; page <?= $detailPage ?> of <?= $detailPages ?>
            </p>
        </nav>
        <?php endif; ?>

        <!-- Running total footer -->
        <div class="d-flex justify-content-end mt-2">
            <div class="text-end">
                <span class="text-muted small">Showing total for applied filters:</span><br>
                <span class="fw-bold fs-5">&#8369;<?= number_format($overallTotal, 2) ?></span>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /#detailView -->

</div><!-- /.main-card -->

<!-- COLLECTION MODAL -->
<div class="modal fade" id="collectPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Collect Room Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <form id="collectPaymentForm" novalidate>
                <div class="modal-body">
                    <div class="row g-3"><div class="col-6"><label class="form-label" for="collectRoomNumber">Room number</label><input class="form-control" id="collectRoomNumber" readonly></div><div class="col-6"><label class="form-label" for="collectAmountDue">Amount due</label><input class="form-control" id="collectAmountDue" readonly></div></div>
                    <div class="mt-3"><label class="form-label" for="collectAmountReceived">Amount received</label><input type="number" class="form-control" id="collectAmountReceived" min="0.01" step="0.01" inputmode="decimal" required></div>
                    <div class="d-flex justify-content-between mt-3"><span class="text-muted">Change</span><strong id="collectChange">₱0.00</strong></div>
                    <div id="collectPaymentError" class="alert alert-danger mt-3 mb-0 d-none" role="alert"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="confirmCollectionBtn">Confirm Collection</button></div>
            </form>
        </div>
    </div>
</div>

<!-- ARCHIVE TRANSACTION MODAL -->
<div class="modal fade" id="archiveTransactionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Archive</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="archive_tx">
                <input type="hidden" name="tx_id" id="archiveTxId">
                <div class="modal-body text-center">
                    <p class="fs-5">Are you sure you want to archive this transaction?</p>
                    <p class="text-muted small">Archived transactions are hidden from the main view but can be restored if needed.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Archive</button>
                </div>
            </form>
        </div>
    </div>
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
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="../logout.php" class="btn" id="yesBtn">Yes</a>
            </div>
        </div>
    </div>
</div>

<script>
    function showView(view) {
        document.getElementById('summaryView').style.display = (view === 'summary') ? 'block' : 'none';
        document.getElementById('detailView').style.display  = (view === 'detail')  ? 'block' : 'none';
        document.getElementById('btnSummaryView').classList.toggle('active', view === 'summary');
        document.getElementById('btnDetailView').classList.toggle('active', view === 'detail');

        // Persist view preference in session storage
        sessionStorage.setItem('txView', view);
    }

    // Restore last view on load and setup modals
    document.addEventListener('DOMContentLoaded', function () {
        const params = new URLSearchParams(window.location.search);
        const saved = params.get('view') || sessionStorage.getItem('txView') || 'summary';
        showView(saved);
        
        // Archive transaction button handlers
        document.querySelectorAll('.archiveBtn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('archiveTxId').value = this.dataset.txId;
                new bootstrap.Modal(document.getElementById('archiveTransactionModal')).show();
            });
        });

        document.querySelectorAll('.collect-room-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openCollectionModal(this.dataset.room, parseFloat(this.dataset.total || '0'), this);
            });
        });
        document.getElementById('collectAmountReceived').addEventListener('input', updateCollectionChange);
        document.getElementById('collectPaymentForm').addEventListener('submit', submitCollection);
        
        // Auto-dismiss alerts after 5 seconds
        document.querySelectorAll('.alert:not(#collectPaymentError)').forEach(function (alert) {
            const timeout = setTimeout(function () {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
    });

    var activeCollectionButton = null;
    var collectionModal = null;
    function openCollectionModal(roomNumber, totalDue, btn) {
        activeCollectionButton = btn;
        document.getElementById('collectRoomNumber').value = roomNumber;
        document.getElementById('collectAmountDue').value = '₱' + totalDue.toFixed(2);
        document.getElementById('collectAmountDue').dataset.amount = totalDue.toFixed(2);
        document.getElementById('collectAmountReceived').min = totalDue.toFixed(2);
        document.getElementById('collectAmountReceived').value = totalDue.toFixed(2);
        document.getElementById('collectPaymentError').classList.add('d-none');
        updateCollectionChange();
        collectionModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('collectPaymentModal'));
        collectionModal.show();
    }
    function updateCollectionChange() {
        var due = parseFloat(document.getElementById('collectAmountDue').dataset.amount || '0');
        var received = parseFloat(document.getElementById('collectAmountReceived').value) || 0;
        var submitButton = document.getElementById('confirmCollectionBtn');
        var error = document.getElementById('collectPaymentError');
        document.getElementById('collectChange').textContent = '₱' + Math.max(0, received - due).toFixed(2);
        if (received > 0 && received < due) { error.textContent = 'Amount received is short by ₱' + (due - received).toFixed(2) + '. Pay at least ₱' + due.toFixed(2) + '.'; error.classList.remove('d-none'); submitButton.disabled = true; } else { error.classList.add('d-none'); submitButton.disabled = false; }
    }
    function submitCollection(event) {
        event.preventDefault();
        var roomNumber = document.getElementById('collectRoomNumber').value;
        var receivedInput = document.getElementById('collectAmountReceived').value.trim();
        var received = Number(receivedInput);
        var due = Number(document.getElementById('collectAmountDue').dataset.amount || '0');
        var error = document.getElementById('collectPaymentError');
        var submitButton = document.getElementById('confirmCollectionBtn');
        if (!/^\d+(\.\d{1,2})?$/.test(receivedInput) || !Number.isFinite(received) || received <= 0) { error.textContent = 'Enter a valid amount received greater than zero.'; error.classList.remove('d-none'); return; }
        if (received < due) { error.textContent = 'Amount received is less than the actual amount due of ₱' + due.toFixed(2) + '.'; error.classList.remove('d-none'); return; }
        error.classList.add('d-none'); submitButton.disabled = true; submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Processing...';
        if (activeCollectionButton) activeCollectionButton.disabled = true;

        fetch('process_mark_all_paid.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'room_number=' + encodeURIComponent(roomNumber)
                + '&amount_received=' + encodeURIComponent(receivedInput)
                + '&csrf_token=' + encodeURIComponent('<?php echo $collectionCsrfToken; ?>')
        })
        .then(function (r) { return r.text().then(function (body) { try { return JSON.parse(body); } catch (e) { throw new Error(body || 'The collection endpoint returned an invalid response.'); } }); })
        .then(function (data) {
            if (!data.success) {
                if ((data.message || '').indexOf('No unpaid items found') !== -1) { window.location.reload(); return; }
                error.textContent = data.message || 'No unpaid items were collected.'; error.classList.remove('d-none'); submitButton.disabled = false; submitButton.textContent = 'Confirm Collection'; if (activeCollectionButton) activeCollectionButton.disabled = false;
                return;
            }

            sessionStorage.setItem('txView', 'detail');
            const params = new URLSearchParams(window.location.search);
            // After marking items as paid, show all transactions (not just unpaid)
            params.set('status', 'all');
            params.set('view', 'detail');
            params.set('collected', '1');
            params.set('change', Number(data.change_amount || 0).toFixed(2));
            params.set('collection_id', data.collection_id || '');
            window.location.href = 'transaction_history.php?' + params.toString();
        })
        .catch(function (requestError) {
            error.textContent = requestError.message || 'Network error. Please try again.'; error.classList.remove('d-none'); submitButton.disabled = false; submitButton.textContent = 'Confirm Collection'; if (activeCollectionButton) activeCollectionButton.disabled = false;
        });
    }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-j1CDi7MgGQ12Z7Qab0qlWQ/Qqz24Gc6BM0thvEMVjHnfYGF0rmFCozFSxQBxwHKO"
    crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
<script src="../superadmin/includes/notif_poll.js"></script>

<script>
    function showPrintSections() {
        var printHeader = document.getElementById('printHeader');
        var printDetailDivider = document.getElementById('printDetailDivider');
        var printDetailTable = document.getElementById('printDetailTable');

        if (printHeader) printHeader.style.display = 'block';
        if (printDetailDivider) printDetailDivider.style.display = 'block';
        if (printDetailTable) printDetailTable.style.display = 'block';
    }
    function hidePrintSections() {
        var printHeader = document.getElementById('printHeader');
        var printDetailDivider = document.getElementById('printDetailDivider');
        var printDetailTable = document.getElementById('printDetailTable');

        if (printHeader) printHeader.style.display = 'none';
        if (printDetailDivider) printDetailDivider.style.display = 'none';
        if (printDetailTable) printDetailTable.style.display = 'none';
    }

    function printSummary() {
        window.print();
    }

var reportPdfData = <?= json_encode($reportPdfData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?>;

function downloadTransactionPDF() {
    if (!window.jspdf || !window.jspdf.jsPDF) {
        alert('PDF generator is not ready. Please refresh the page and try again.');
        return;
    }

    var jsPDF = window.jspdf.jsPDF;
    // Landscape A4: the report's money ledger carries enough columns that a
    // portrait page could not fit them (fixed widths once summed to 259mm inside
    // a 186mm content box, which made the table overlap the page edge).
    var doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    if (typeof doc.autoTable !== 'function') {
        alert('PDF table generator is not ready. Please refresh the page and try again.');
        return;
    }

    var pageWidth = doc.internal.pageSize.getWidth();
    var pageHeight = doc.internal.pageSize.getHeight();
    var margin = 12;
    var contentWidth = pageWidth - (margin * 2);
    var y = 14;

    doc.setProperties({
        title: 'Transaction Report',
        subject: 'Marle Hotel & Restaurant Transaction Report',
        author: 'Marle Hotel & Restaurant'
    });

    doc.setTextColor(147, 51, 16);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(17);
    doc.text('Marle Hotel & Restaurant', margin, y);

    doc.setTextColor(35, 35, 35);
    doc.setFontSize(10);
    doc.text('Transaction Report', margin, y + 6);

    doc.setTextColor(95, 95, 95);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    doc.text('Generated: ' + reportPdfData.generated, pageWidth - margin, y, { align: 'right' });
    doc.text(reportPdfData.periodLabel, pageWidth - margin, y + 5, { align: 'right' });

    y += 12;
    doc.setDrawColor(147, 51, 16);
    doc.setLineWidth(0.6);
    doc.line(margin, y, pageWidth - margin, y);

    y += 5;
    var filterLines = doc.splitTextToSize(reportPdfData.filters, contentWidth - 5);
    var filterBoxHeight = Math.max(8, (filterLines.length * 4) + 4);
    doc.setFillColor(250, 250, 250);
    doc.setDrawColor(225, 225, 225);
    doc.roundedRect(margin, y, contentWidth, filterBoxHeight, 1.5, 1.5, 'FD');
    doc.setFontSize(8);
    doc.setTextColor(85, 85, 85);
    doc.text(filterLines, margin + 2.5, y + 5.2);

    y += filterBoxHeight + 5;
    doc.setFillColor(147, 51, 16);
    doc.rect(margin, y, contentWidth, 16, 'F');
    doc.setTextColor(255, 255, 255);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    doc.text('OVERALL TOTAL', margin + 4, y + 6);
    doc.text(reportPdfData.periodLabel, margin + 4, y + 11);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(18);
    doc.text(reportPdfData.overallTotal, pageWidth - margin - 4, y + 10.8, { align: 'right' });

    y += 21;
    var boxGap = 3;
    var boxWidth = (contentWidth - (boxGap * 3)) / 4;
    reportPdfData.summary.forEach(function (item, index) {
        var x = margin + (index * (boxWidth + boxGap));
        doc.setFillColor(255, 255, 255);
        doc.setDrawColor(225, 225, 225);
        doc.roundedRect(x, y, boxWidth, 15, 1.5, 1.5, 'S');
        doc.setTextColor(100, 100, 100);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7.5);
        doc.text(item.label, x + 3, y + 5);
        doc.setTextColor(35, 35, 35);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(10);
        doc.text(item.value, x + 3, y + 11);
    });

    y += 22;
    doc.setTextColor(147, 51, 16);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(10);
    doc.text('Detailed Transactions', margin, y);

    // Widths are RATIOS of the printable width, so the columns always add up to
    // exactly the page width — on A4 landscape, on Letter, at any margin.
    var PDF_COLUMN_RATIOS = [0.031, 0.066, 0.115, 0.046, 0.192, 0.086, 0.086, 0.096, 0.071, 0.064, 0.147];
    var pdfColumnStyles = {};
    PDF_COLUMN_RATIOS.forEach(function (ratio, index) {
        pdfColumnStyles[index] = { cellWidth: contentWidth * ratio };
    });
    [5, 6, 7, 8].forEach(function (index) { pdfColumnStyles[index].halign = 'right'; });

    var rows = reportPdfData.rows || [];
    if (rows.length === 0) {
        doc.setTextColor(100, 100, 100);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(9);
        doc.text('No transactions match the selected filters.', margin, y + 8);
    } else {
        doc.autoTable({
            startY: y + 4,
            head: [['#', 'Type', 'Guest', 'Room', 'Description', 'Amount', 'Amount Due', 'Amount Received', 'Change', 'Status', 'Date']],
            body: rows,
            theme: 'grid',
            margin: { left: margin, right: margin, top: 12, bottom: 12 },
            tableWidth: contentWidth,
            styles: {
                font: 'helvetica',
                fontSize: 7.2,
                cellPadding: 1.6,
                overflow: 'linebreak',
                valign: 'top',
                lineColor: [232, 232, 232],
                lineWidth: 0.1,
                textColor: [40, 40, 40]
            },
            headStyles: {
                fillColor: [242, 236, 233],
                textColor: [45, 45, 45],
                fontStyle: 'bold',
                fontSize: 7,
                halign: 'left'
            },
            columnStyles: pdfColumnStyles,
            didDrawPage: function () {
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(7);
                doc.setTextColor(130, 130, 130);
                doc.text('Marle Hotel & Restaurant', margin, pageHeight - 7);
            }
        });

        var finalY = doc.lastAutoTable.finalY + 7;
        if (finalY > pageHeight - 18) {
            doc.addPage();
            finalY = 18;
        }
        doc.setDrawColor(147, 51, 16);
        doc.setLineWidth(0.5);
        doc.line(pageWidth - margin - 62, finalY - 4, pageWidth - margin, finalY - 4);
        doc.setTextColor(35, 35, 35);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(9);
        doc.text('Total', pageWidth - margin - 62, finalY);
        doc.text(reportPdfData.overallTotal, pageWidth - margin, finalY, { align: 'right' });
    }

    // "Page X of Y" needs the real total, so it is stamped once every page
    // exists (didDrawPage only knows the pages drawn so far).
    var marlePageCount = doc.internal.getNumberOfPages();
    for (var marlePage = 1; marlePage <= marlePageCount; marlePage++) {
        doc.setPage(marlePage);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(7);
        doc.setTextColor(130, 130, 130);
        doc.text('Page ' + marlePage + ' of ' + marlePageCount, pageWidth - margin, pageHeight - 7, { align: 'right' });
    }

    doc.save(reportPdfData.filename);
}

var donutData = [
    { value: <?= $summaryByType['check_in'] ?>,  color: '#352414' },
    { value: <?= $summaryByType['extension'] ?>,  color: '#0d6efd' },
    { value: <?= $summaryByType['service'] ?>,    color: '#933310' },
    { value: <?= $summaryByType['food'] ?>,       color: '#2e7d32' },
];

function drawDonut(canvas, data) {
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var cx = 45, cy = 45, r = 33, lw = 13;
    var total = data.reduce(function(s, d) { return s + d.value; }, 0);
    ctx.clearRect(0, 0, 90, 90);
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, 2 * Math.PI);
    ctx.strokeStyle = '#e9ecef'; ctx.lineWidth = lw; ctx.stroke();
    if (total > 0) {
        var start = -Math.PI / 2;
        data.forEach(function(seg) {
            if (seg.value <= 0) return;
            var angle = (seg.value / total) * 2 * Math.PI;
            ctx.beginPath();
            ctx.arc(cx, cy, r, start, start + angle);
            ctx.strokeStyle = seg.color; ctx.lineWidth = lw; ctx.stroke();
            start += angle;
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    drawDonut(document.getElementById('donutChart'), donutData);
});

// Paging reloads start on the Summary tab — jump straight back to Detailed.
if (new URLSearchParams(window.location.search).has('page')) {
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof showView === 'function') { showView('detail'); }
    });
}
</script>

    <!-- Footer — one design for all three roles (includes/staff_footer.php) -->
    <?php $marleFooterTerms = false; require __DIR__ . '/../includes/staff_footer.php'; ?>
</body>
</html>



