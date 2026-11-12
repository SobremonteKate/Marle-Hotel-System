<?php

// The hotel's clock (includes/server_clock.php): the month labels and the month window
// below have to come from the same clock that stamped the rows they are matched against.
// PHP's date.timezone is a different clock on this host, so near a month boundary the
// labels ended a month early and the newest month's series matched nothing and plotted 0.
require_once __DIR__ . '/includes/server_clock.php';

function dashboard_scalar($conn, string $sql, string $field = 'total', $default = 0)
{
    // Untyped on purpose: marle_db_connect() returns FALSE when MySQL is down,
    // and `mysqli $conn` turned that into an uncaught TypeError on the way in.
    // A missing connection answers exactly like a query that did not run — with
    // the caller's own default — and the page itself degrades in words first
    // (includes/db_guard.php).
    if (!($conn instanceof mysqli)) {
        return $default;
    }

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        return $default;
    }

    $row = mysqli_fetch_assoc($result);
    return $row[$field] ?? $default;
}

function dashboard_rows($conn, string $sql): array
{
    // Same untyped parameter, same reason as dashboard_scalar(): no connection
    // yields the empty answer a failed query already yields, so the net under a
    // caller that forgot to guard is an empty chart, never a fatal.
    if (!($conn instanceof mysqli)) {
        return [];
    }

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    return $rows;
}

function dashboard_month_labels(int $months): array
{
    $labels = [];
    $hotelToday = marle_hotel_date();   // the database's today, not PHP's
    for ($i = $months - 1; $i >= 0; $i--) {
        // Anchored on the hotel's date, stepped back whole months and read back in its own
        // parts, so the label is the month the data was written in — the same '%b %Y' the
        // queries group by — and never a month PHP believes it still is.
        $labels[] = date('M Y', strtotime($hotelToday . ' first day of -' . $i . ' months'));
    }

    return $labels;
}

function dashboard_month_series($conn, string $sql, array $labels, string $valueField = 'total'): array
{
    $series = array_fill(0, count($labels), 0);
    if (!($conn instanceof mysqli)) {
        return $series;
    }

    foreach (dashboard_rows($conn, $sql) as $row) {
        $idx = array_search($row['lbl'] ?? '', $labels, true);
        if ($idx !== false) {
            $series[$idx] = (float)($row[$valueField] ?? 0);
        }
    }

    return $series;
}

function dashboard_split_series(array $rows, string $labelField, string $valueField): array
{
    $labels = [];
    $values = [];

    foreach ($rows as $row) {
        $labels[] = (string)($row[$labelField] ?? '');
        $values[] = (float)($row[$valueField] ?? 0);
    }

    return [$labels, $values];
}

function dashboard_money($value): string
{
    return '&#8369;' . number_format((float)$value, 2);
}

/**
 * Compare a current value against the same metric for the previous period so
 * the dashboard can label movement instead of showing a bare number.
 *
 * @return array{direction:string,diff:float,percent:?float,label:string}
 */
function dashboard_delta($current, $previous, bool $money = false): array
{
    $current = (float)$current;
    $previous = (float)$previous;
    $diff = $current - $previous;

    if (abs($diff) < 0.005) {
        return ['direction' => 'flat', 'diff' => 0.0, 'percent' => 0.0, 'label' => 'No change vs yesterday'];
    }

    $percent = $previous > 0 ? ($diff / $previous) * 100 : null;
    $amount = $money ? dashboard_money(abs($diff)) : number_format(abs($diff));
    $verb = $diff > 0 ? 'more' : 'less';
    $label = $percent === null
        ? $amount . ' ' . $verb . ' than yesterday'
        : $amount . ' (' . number_format(abs($percent), 1) . '%) ' . $verb . ' than yesterday';

    return [
        'direction' => $diff > 0 ? 'up' : 'down',
        'diff' => $diff,
        'percent' => $percent,
        'label' => $label,
    ];
}

/**
 * Render the delta as a small chip. "goodWhen" decides the colour so an
 * increase in unpaid balances reads as bad while an increase in revenue reads
 * as good.
 */
function dashboard_delta_chip(array $delta, string $goodWhen = 'up'): string
{
    $direction = $delta['direction'];
    $tone = 'flat';
    if ($direction === 'up') {
        $tone = $goodWhen === 'up' ? 'good' : 'bad';
    } elseif ($direction === 'down') {
        $tone = $goodWhen === 'down' ? 'good' : 'bad';
    }

    $icon = $direction === 'up' ? 'bi-arrow-up-right' : ($direction === 'down' ? 'bi-arrow-down-right' : 'bi-dash');
    $short = $direction === 'flat'
        ? 'No change'
        : ($delta['percent'] === null
            ? ($direction === 'up' ? 'New today' : 'Cleared')
            : ($direction === 'up' ? '+' : '&minus;') . number_format(abs($delta['percent']), 1) . '%');

    return '<span class="dashboard-delta ' . $tone . '" title="' . $delta['label'] . '">'
        . '<i class="bi ' . $icon . '"></i>' . $short . '</span>';
}

// function dashboard_column_exists(mysqli $conn, string $table, string $column): bool
// {
//     static $cache = [];

//     $key = $table . '.' . $column;
//     if (array_key_exists($key, $cache)) {
//         return $cache[$key];
//     }

//     $safeTable = str_replace('`', '``', $table);
//     $stmt = $conn->prepare("SHOW COLUMNS FROM `{$safeTable}` LIKE ?");
//     if (!$stmt) {
//         return $cache[$key] = false;
//     }

//     $stmt->bind_param('s', $column);
//     $stmt->execute();
//     $result = $stmt->get_result();
//     $exists = $result && $result->num_rows > 0;
//     $stmt->close();

//     return $cache[$key] = $exists;
// }

function dashboard_column_exists($conn, string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    // No connection: the schema cannot be read, so the answer is "not there"
    // rather than a TypeError. Every query that then depends on it fails anyway,
    // which is why this is a safety net and not a designed state.
    if (!($conn instanceof mysqli)) {
        return $cache[$key] = false;
    }

    // Strip out anything that isn't an alphanumeric or underscore character to prevent SQL injection
    $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

    // Bypass prepare entirely for the table structure, querying schema table natively
    $safeColumn = mysqli_real_escape_string($conn, $column);
    $query = "SHOW COLUMNS FROM `$cleanTable` LIKE '$safeColumn'";
    
    $result = mysqli_query($conn, $query);
    $exists = $result && mysqli_num_rows($result) > 0;

    return $cache[$key] = $exists;
}

function dashboard_visible_transactions_union_sql(): string
{
    return "
        SELECT
            'check_in' AS transaction_type,
            CONVERT(th.room_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS room_number,
            CONVERT(COALESCE(th.room_type, '—') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS room_type,
            th.amount,
            CONVERT(th.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
            th.created_at
        FROM transaction_history th
        WHERE th.transaction_type = 'check_in'
          AND th.archived_at IS NULL

        UNION ALL

        SELECT
            'extension' AS transaction_type,
            CONVERT(eh.room_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS room_number,
            CONVERT(COALESCE(eh.room_type, '—') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS room_type,
            th.amount,
            CONVERT(th.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
            th.created_at
        FROM transaction_history th
        JOIN extension_history eh ON eh.id = th.reference_id
        WHERE th.transaction_type = 'extension'
          AND th.archived_at IS NULL

        UNION ALL

        SELECT
            'service' AS transaction_type,
            CONVERT(r.room_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS room_number,
            CONVERT(COALESCE((
                SELECT rooms
                FROM roomstbl
                WHERE CONVERT(room_number USING utf8mb4) COLLATE utf8mb4_unicode_ci = r.room_number
                LIMIT 1
            ), '—') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS room_type,
            th.amount,
            CONVERT(th.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
            th.created_at
        FROM transaction_history th
        JOIN service_requests sr ON sr.id = th.reference_id
        JOIN requests r ON r.id = sr.request_id
        WHERE th.transaction_type = 'service'
          AND th.archived_at IS NULL

        UNION ALL

        SELECT
            'food' AS transaction_type,
            CONVERT(r.room_number USING utf8mb4) COLLATE utf8mb4_unicode_ci AS room_number,
            CONVERT(COALESCE((
                SELECT rooms
                FROM roomstbl
                WHERE CONVERT(room_number USING utf8mb4) COLLATE utf8mb4_unicode_ci = r.room_number
                LIMIT 1
            ), '—') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS room_type,
            th.amount,
            CONVERT(th.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
            th.created_at
        FROM transaction_history th
        JOIN food_order_items foi ON foi.id = th.reference_id
        JOIN requests r ON r.id = foi.request_id
        WHERE th.transaction_type = 'food'
          AND th.archived_at IS NULL
    ";
}

/**
 * Every figure on the three staff dashboards, in one call.
 *
 * $conn is deliberately untyped: a dead database arrives here as `false`, and
 * the guards inside the helpers above return their defaults for it, so the
 * worst case is an empty dashboard rather than a TypeError. Nothing should be
 * *shown* on that basis — a screen that says "0 occupied rooms" because it
 * could not read the table is worse than one that says the system is down, so
 * every caller guards first (see admin/adminDB.php).
 */
function dashboard_metrics($conn, int $months): array
{
    $labels = dashboard_month_labels($months);
    $startDate = date('Y-m-01', strtotime(marle_hotel_date() . ' first day of -' . ($months - 1) . ' months'));
    $hasBookingArchivedAt = dashboard_column_exists($conn, 'bookings', 'archived_at');
    $bookingArchiveSql = $hasBookingArchivedAt ? "AND archived_at IS NULL" : "";
    $visibleTransactionsSql = dashboard_visible_transactions_union_sql();
    $totalRooms = (int)dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM roomstbl");
    $maintenanceRooms = (int)dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM roomstbl WHERE maintenance_status = 1");
    $occupiedRooms = (int)dashboard_scalar($conn, "
        SELECT COUNT(DISTINCT room_no) AS total
        FROM customerstbl
        WHERE status = 'checked-in' AND (date_to IS NULL OR date_to > NOW())");
    $bookedRooms = (int)dashboard_scalar($conn, "
        SELECT COUNT(DISTINCT room_number) AS total
        FROM bookings
        WHERE status = 'approved'
          AND room_number IS NOT NULL
          AND room_number <> ''
          $bookingArchiveSql
          AND (
              (booking_type != 'hourly' AND check_in <= CURDATE() AND check_out > CURDATE())
              OR
              (booking_type = 'hourly' AND hourly_released = 0 AND check_in = CURDATE()
                  AND check_in_time <= TIME(NOW()) AND check_out_time > TIME(NOW()))
          )");
    $availableRooms = max(0, $totalRooms - $maintenanceRooms - $occupiedRooms - $bookedRooms);
    $occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 1) : 0;

    $monthlyRevenue = dashboard_month_series($conn, "
        SELECT DATE_FORMAT(created_at, '%b %Y') AS lbl, COALESCE(SUM(amount), 0) AS total
        FROM ($visibleTransactionsSql) AS tx
        WHERE status = 'paid'
          AND created_at >= '$startDate'
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY MIN(created_at) ASC", $labels);

    $checkInsTrend = dashboard_month_series($conn, "
        SELECT DATE_FORMAT(date_from, '%b %Y') AS lbl, COUNT(*) AS total
        FROM customerstbl
        WHERE date_from >= '$startDate'
        GROUP BY DATE_FORMAT(date_from, '%Y-%m')
        ORDER BY MIN(date_from) ASC", $labels);

    $guestTrend = dashboard_month_series($conn, "
        SELECT DATE_FORMAT(date_from, '%b %Y') AS lbl, COALESCE(SUM(persons), 0) AS total
        FROM customerstbl
        WHERE date_from >= '$startDate'
        GROUP BY DATE_FORMAT(date_from, '%Y-%m')
        ORDER BY MIN(date_from) ASC", $labels);

    [$revenueTypeLabels, $revenueTypeData] = dashboard_split_series(dashboard_rows($conn, "
        SELECT CASE transaction_type
                   WHEN 'check_in'  THEN 'Check-in'
                   WHEN 'extension' THEN 'Stay extension'
                   WHEN 'service'   THEN 'Room service'
                   WHEN 'food'      THEN 'Food orders'
                   ELSE CONCAT(UPPER(LEFT(transaction_type, 1)), SUBSTRING(transaction_type, 2))
               END AS label,
               COALESCE(SUM(amount), 0) AS total
        FROM ($visibleTransactionsSql) AS tx
        WHERE status = 'paid'
          AND created_at >= '$startDate'
        GROUP BY transaction_type
        ORDER BY total DESC"), 'label', 'total');

    [$requestTypeLabels, $requestTypeData] = dashboard_split_series(dashboard_rows($conn, "
        SELECT CASE type
                   WHEN 'service' THEN 'Room service'
                   WHEN 'food'    THEN 'Food orders'
                   WHEN 'extend'  THEN 'Stay extensions'
                   WHEN 'chat'    THEN 'Guest chat'
                   ELSE CONCAT(UPPER(LEFT(type, 1)), SUBSTRING(type, 2))
               END AS label,
               COUNT(*) AS total
        FROM requests
        WHERE is_archived = 0
        GROUP BY type
        ORDER BY total DESC"), 'label', 'total');

    return [
        'totalCheckIns' => (int)dashboard_scalar($conn, "
            SELECT COUNT(*) AS total
            FROM customerstbl
            WHERE status = 'checked-in'
              AND (date_to IS NULL OR date_to > NOW())"),
        'totalGuests' => (int)dashboard_scalar($conn, "SELECT COALESCE(SUM(persons), 0) AS total FROM customerstbl"),
        'todayCheckouts' => (int)dashboard_scalar($conn, "
            SELECT COUNT(*) AS total
            FROM customerstbl
            WHERE status = 'checked-in'
              AND date_to IS NOT NULL
              AND date_to >= NOW()
              AND DATE(date_to) = CURDATE()"),
        'monthlyGuests' => (int)dashboard_scalar($conn, "
            SELECT COALESCE(SUM(persons), 0) AS total
            FROM customerstbl
            WHERE date_from >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'arrivalsToday' => (int)dashboard_scalar($conn, "
            SELECT COUNT(*) AS total
            FROM bookings
            WHERE status = 'approved'
              AND check_in = CURDATE()
              $bookingArchiveSql"),
        'checkInsToday' => (int)dashboard_scalar($conn, "
            SELECT COUNT(*) AS total
            FROM customerstbl
            WHERE DATE(date_from) = CURDATE()"),
        'checkInsYesterday' => (int)dashboard_scalar($conn, "
            SELECT COUNT(*) AS total
            FROM customerstbl
            WHERE DATE(date_from) = CURDATE() - INTERVAL 1 DAY"),
        'pendingBookings' => (int)dashboard_scalar($conn, "
            SELECT COUNT(*) AS total
            FROM bookings
            WHERE status = 'pending'
              $bookingArchiveSql"),
        'pendingRequests' => (int)dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM requests WHERE status = 'pending' AND is_archived = 0"),
        'pendingServiceRequests' => (int)dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM requests WHERE status = 'pending' AND is_archived = 0 AND type = 'service'"),
        'pendingFoodRequests' => (int)dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM requests WHERE status = 'pending' AND is_archived = 0 AND type = 'food'"),
        'pendingStayRequests' => (int)dashboard_scalar($conn, "SELECT COUNT(*) AS total FROM requests WHERE status = 'pending' AND is_archived = 0 AND type = 'extend'"),
        'revenueToday' => (float)dashboard_scalar($conn, "
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM ($visibleTransactionsSql) AS tx
            WHERE status = 'paid' AND DATE(created_at) = CURDATE()"),
        'revenueYesterday' => (float)dashboard_scalar($conn, "
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM ($visibleTransactionsSql) AS tx
            WHERE status = 'paid' AND DATE(created_at) = CURDATE() - INTERVAL 1 DAY"),
        'revenueMonthLabel' => date('F Y', strtotime(marle_hotel_date())),
        'revenueMonth' => (float)dashboard_scalar($conn, "
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM ($visibleTransactionsSql) AS tx
            WHERE status = 'paid'
              AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'unpaidReceivables' => (float)dashboard_scalar($conn, "
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM ($visibleTransactionsSql) AS tx
            WHERE status = 'unpaid'"),
        'unpaidToday' => (float)dashboard_scalar($conn, "
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM ($visibleTransactionsSql) AS tx
            WHERE status = 'unpaid' AND DATE(created_at) = CURDATE()"),
        'unpaidYesterday' => (float)dashboard_scalar($conn, "
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM ($visibleTransactionsSql) AS tx
            WHERE status = 'unpaid' AND DATE(created_at) = CURDATE() - INTERVAL 1 DAY"),
        'unpaidOverdue' => (float)dashboard_scalar($conn, "
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM ($visibleTransactionsSql) AS tx
            WHERE status = 'unpaid' AND DATE(created_at) < CURDATE()"),
        'unpaidCount' => (int)dashboard_scalar($conn, "
            SELECT COUNT(*) AS total
            FROM ($visibleTransactionsSql) AS tx
            WHERE status = 'unpaid'"),
        'lowStockCount' => (int)dashboard_scalar($conn, "
            SELECT COUNT(*) AS total
            FROM inventorytbl
            WHERE COALESCE(quantity, 0) <= low_stock_threshold"),
        'totalRooms' => $totalRooms,
        'availableRooms' => $availableRooms,
        'occupiedRooms' => $occupiedRooms,
        'bookedRooms' => $bookedRooms,
        'maintenanceRooms' => $maintenanceRooms,
        'occupancyRate' => $occupancyRate,
        'recentPaidTransactions' => dashboard_rows($conn, "
            SELECT transaction_type, room_number, room_type, amount, created_at
            FROM ($visibleTransactionsSql) AS tx
            WHERE status = 'paid'
            ORDER BY created_at DESC
            LIMIT 5"),
        'recentUnpaidTransactions' => dashboard_rows($conn, "
            SELECT transaction_type, room_number, room_type, amount, created_at
            FROM ($visibleTransactionsSql) AS tx
            WHERE status = 'unpaid'
            ORDER BY created_at DESC
            LIMIT 5"),
        'lowStockItems' => dashboard_rows($conn, "
            SELECT item, category, COALESCE(quantity, 0) AS quantity, low_stock_threshold
            FROM inventorytbl
            WHERE COALESCE(quantity, 0) <= low_stock_threshold
            ORDER BY COALESCE(quantity, 0) ASC, item ASC
            LIMIT 5"),
        'labels' => $labels,
        'monthlyRevenue' => $monthlyRevenue,
        'checkInsTrend' => $checkInsTrend,
        'guestTrend' => $guestTrend,
        'revenueTypeLabels' => $revenueTypeLabels,
        'revenueTypeData' => $revenueTypeData,
        'requestTypeLabels' => $requestTypeLabels,
        'requestTypeData' => $requestTypeData,
        'roomStatusLabels' => ['Available', 'Occupied', 'Booked', 'Maintenance'],
        'roomStatusData' => [$availableRooms, $occupiedRooms, $bookedRooms, $maintenanceRooms],
    ];
}
