<?php
// ============================================================
//  database/demo_requests_seed.php
//  Demo data for Requests → Services / Food / Bookings.
//
//  200 marked rows: 70 service requests, 70 food orders and 60 bookings, all
//  back-dated over roughly the last 6–14 months, with a realistic spread of
//  statuses. It exists so the dashboards, the request queues and the archive
//  page have something to show — the hotel's own live rows are never touched.
//
//  Safety, by design:
//    • every row carries a marker (see MARKER_* below) so it can be found and
//      removed without guessing;
//    • every booking is **back-dated** (check_in in the past, check_out also in
//      the past), so it can never block a room for a real guest — the date
//      filters in submit_booking.php, dashboard_metrics.php and the archive cron
//      all treat a finished stay as finished;
//    • pending demo bookings are stamped deposit_status='verified', so the
//      unpaid-booking release sweep (admin/cron_expire_deposits.php) ignores
//      them instead of cancelling them;
//    • nothing is written to notifications, transaction_history, customerstbl
//      or calendartbl, so the staff bell, the revenue figures and the calendar
//      are exactly as they were;
//    • rows are left unarchived and their timestamps are recent enough that the
//      retention job cannot hard-delete them;
//    • the whole run is one transaction and is idempotent — run it twice and the
//      second run is a no-op.
//
//  Usage:
//    php database/demo_requests_seed.php                 # seed (idempotent)
//    php database/demo_requests_seed.php --clean         # remove every demo row
//    php database/demo_requests_seed.php --force         # clean, then seed
//    php database/demo_requests_seed.php --database=marlehoteldb_demo_test
//    php database/demo_requests_seed.php --count=200     # override the total
//
//  Exit code: 0 = success, 1 = refused / failed.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const MARKER_REQ       = '[DEMO-REQ] ';
const MARKER_EMAIL     = 'demo.req.';
const MARKER_EMAIL_DOM = '@example.test';
const MARKER_NAME      = 'DEMO REQ ';
const MARKER_SPECIAL   = 'demo-request-seed';

$database = 'marlehoteldb';
$clean = false;
$force = false;
$count = 200;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--database=')) {
        $database = substr($arg, 11);
    } elseif ($arg === '--clean') {
        $clean = true;
    } elseif ($arg === '--force') {
        $force = true;
    } elseif (str_starts_with($arg, '--count=')) {
        $count = max(3, (int)substr($arg, 8));
    }
}

$conn = new mysqli('localhost', 'root', '', $database);
$conn->set_charset('utf8mb4');

// Split the requested total: 35% service, 30% booking, the rest food.
$serviceCount = (int)round($count * 0.35);
$bookingCount = (int)round($count * 0.30);
$foodCount    = max(0, $count - $serviceCount - $bookingCount);

/** Every demo request id, whatever its archive state. */
function demo_request_ids(mysqli $conn): array
{
    $ids = [];
    $res = $conn->query("SELECT id FROM requests WHERE message LIKE '" . MARKER_REQ . "%'");
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['id'];
    }
    return $ids;
}

/** Remove every demo row; requests by marker, bookings by the reserved domain. */
function demo_clean(mysqli $conn): array
{
    $ids = demo_request_ids($conn);
    $removed = ['requests' => 0, 'bookings' => 0];

    if ($ids) {
        $in = implode(',', $ids);
        $conn->query("DELETE FROM food_order_items WHERE request_id IN ($in)");
        $conn->query("DELETE FROM service_requests WHERE request_id IN ($in)");
        $conn->query("DELETE FROM requests WHERE id IN ($in)");
        $removed['requests'] = count($ids);
    }

    $conn->query(
        "DELETE FROM bookings
          WHERE guest_email LIKE '" . MARKER_EMAIL . "%" . MARKER_EMAIL_DOM . "'
             OR special_requests = '" . MARKER_SPECIAL . "'"
    );
    $removed['bookings'] = (int)$conn->affected_rows;

    return $removed;
}

$existingRequests = count(demo_request_ids($conn));
$existingBookings = (int)$conn->query(
    "SELECT COUNT(*) AS total FROM bookings WHERE guest_email LIKE '" . MARKER_EMAIL . "%" . MARKER_EMAIL_DOM . "'"
)->fetch_assoc()['total'];
$existing = $existingRequests + $existingBookings;

if ($clean && !$force) {
    $removed = demo_clean($conn);
    echo "Removed {$removed['requests']} demo request(s) and {$removed['bookings']} demo booking(s).\n";
    exit(0);
}
if ($force) {
    demo_clean($conn);
    $existing = 0;
}

if ($existing === $count) {
    echo "Demo requests already seeded: {$existingRequests} request(s) + {$existingBookings} booking(s) = {$count}.\n";
    exit(0);
}
if ($existing !== 0) {
    fwrite(STDERR, "Partial demo data detected ({$existingRequests} request(s), {$existingBookings} booking(s)).\n"
        . "Re-run with --force to replace it cleanly, or --clean to just remove it.\n");
    exit(1);
}

// ── Reference pools ─────────────────────────────────────────────────────────
$customers = [];
$res = $conn->query('SELECT id, room_no FROM customerstbl ORDER BY id');
while ($row = $res->fetch_assoc()) {
    $customers[] = ['id' => (int)$row['id'], 'room' => (string)($row['room_no'] ?? '')];
}
$rooms = [];
$res = $conn->query('SELECT room_number, rooms FROM roomstbl WHERE room_number IS NOT NULL AND room_number <> \'\' ORDER BY CAST(room_number AS UNSIGNED)');
while ($row = $res->fetch_assoc()) {
    $rooms[] = ['number' => (string)$row['room_number'], 'type' => (string)$row['rooms']];
}
$roomNumbers = array_map(static fn(array $r): string => $r['number'], $rooms);

if (!$roomNumbers) {
    fwrite(STDERR, "No rooms found in roomstbl — nothing to attach demo data to.\n");
    exit(1);
}
if (!$customers) {
    fwrite(STDERR, "No rows in customerstbl — demo requests need a customer_id.\n");
    exit(1);
}

// Nightly rate map mirroring includes/booking_pricing.php, for believable
// deposit / forfeiture figures on the demo bookings.
$nightlyRate = [
    'Without Garage' => 350.00 + 21 * 150.00,
    'With Garage'    => 450.00 + 21 * 150.00,
    'Suite'          => 600.00 + 21 * 200.00,
    'Family'         => 600.00 + 21 * 200.00,
];

$serviceCatalog = [
    ['Extra Towel', 'Housekeeping', 50.00],
    ['Extra Pillow', 'Housekeeping', 80.00],
    ['Room Cleaning', 'Housekeeping', 150.00],
    ['Laundry Service', 'Laundry', 250.00],
    ['Airport Pickup', 'Transport', 600.00],
    ['Massage (1 hour)', 'Wellness', 700.00],
    ['Late Check-out', 'Front Desk', 300.00],
    ['Toiletries Kit', 'Housekeeping', 120.00],
];
$foodCatalog = [
    ['Chicken Adobo', 'Main', 220.00],
    ['Pancit Canton', 'Main', 180.00],
    ['Halo-Halo', 'Dessert', 140.00],
    ['Fresh Buko Juice', 'Drinks', 90.00],
    ['Beef Tapa Silog', 'Breakfast', 210.00],
    ['Club Sandwich', 'Snacks', 160.00],
    ['Mango Shake', 'Drinks', 110.00],
    ['Lechon Kawali', 'Main', 320.00],
];
$messages = [
    'Guest requested this from the portal.',
    'Requested by the guest at the front desk.',
    'Follow-up to an earlier request.',
    'Guest asked for this during their stay.',
    'Added after room service was called.',
    'Requested through the guest portal chat.',
];

// Deterministic output for repeat runs.
mt_srand(20261012);

$pick = static fn(array $a) => $a[mt_rand(0, count($a) - 1)];
$weighted = static function (array $weights): string {
    $roll = mt_rand(1, max(1, array_sum($weights)));
    $acc = 0;
    foreach ($weights as $value => $weight) {
        $acc += $weight;
        if ($roll <= $acc) {
            return (string)$value;
        }
    }
    return (string)array_key_first($weights);
};
$requestStatusWeights = ['completed' => 15, 'approved' => 35, 'denied' => 20, 'pending' => 30];
$pastStamp = static function (int $maxDaysAgo): string {
    $days = mt_rand(1, $maxDaysAgo);
    return date('Y-m-d H:i:s', strtotime("-{$days} days " . mt_rand(7, 21) . ':' . str_pad((string)mt_rand(0, 59), 2, '0', STR_PAD_LEFT) . ':00'));
};
$dateAgo = static fn(int $minDaysAgo, int $maxDaysAgo): string => date('Y-m-d', strtotime('-' . mt_rand($minDaysAgo, $maxDaysAgo) . ' days'));

/** Bind a value list with types inferred from the values themselves (null-safe). */
$bind = static function (mysqli_stmt $stmt, array $params): void {
    $types = '';
    foreach ($params as $value) {
        $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
    }
    $stmt->bind_param($types, ...$params);
};

$conn->begin_transaction();
try {
    $reqStmt = $conn->prepare(
        'INSERT INTO requests (customer_id, room_number, type, message, status, denial_reason, created_at, updated_at, is_archived)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
    );
    $svcStmt = $conn->prepare(
        'INSERT INTO service_requests (request_id, service_name, service_category, quantity, unit_price, total_amount, payment_status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $foodStmt = $conn->prepare(
        'INSERT INTO food_order_items (request_id, item_name, item_category, quantity, unit_price, total_amount, special_request, payment_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $bookingStmt = $conn->prepare(
        // 25 columns: 9 placeholders, 'daily', 2 placeholders, '2026-09-v1',
        // 50.00, then the remaining 11 placeholders.
        "INSERT INTO bookings
            (guest_name, guest_email, status_token, guest_phone, room_type, room_number, persons,
             check_in, check_out, booking_type,
             special_requests, terms_accepted_at, terms_version, deposit_rate,
             deposit_amount, deposit_status, deposit_verified_at, status, denial_reason,
             cancelled_at, cancelled_by, cancellation_reason, cancellation_fee_amount, cancellation_policy,
             created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,
                 'daily',
                 ?, ?,
                 '2026-09-v1',
                 50.00,
                 ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    /**
     * Insert the hub `requests` row and return [id, status].
     * @return array{0:int,1:string}
     */
    $insertRequest = static function (string $type, int $offset) use ($conn, $reqStmt, $bind, $customers, $roomNumbers, $messages, $weighted, $pick, $pastStamp, $requestStatusWeights): array {
        $customer = $customers[$offset % count($customers)];
        $room = $customer['room'] !== '' ? $customer['room'] : $pick($roomNumbers);
        $status = $weighted($requestStatusWeights);
        $message = MARKER_REQ . $pick($messages);
        $denial = $status === 'denied' ? MARKER_REQ . 'unavailable at the time of the request' : null;
        $created = $pastStamp(400);

        $bind($reqStmt, [$customer['id'], $room, $type, $message, $status, $denial, $created, $created]);
        $reqStmt->execute();
        return [(int)$conn->insert_id, $status];
    };

    $made = ['service' => 0, 'food' => 0, 'booking' => 0];

    // ── Service requests ────────────────────────────────────────────────────
    for ($i = 0; $i < $serviceCount; $i++) {
        [$requestId, $status] = $insertRequest('service', $i);
        [$name, $category, $price] = $pick($serviceCatalog);
        $qty = mt_rand(1, 3);
        $total = round($price * $qty, 2);
        $pay = match ($status) {
            'completed' => 'paid',
            'denied'    => 'denied',
            'approved'  => mt_rand(1, 100) <= 60 ? 'paid' : 'unpaid',
            default     => 'unpaid',
        };
        $bind($svcStmt, [$requestId, $name, $category, $qty, $price, $total, $pay]);
        $svcStmt->execute();
        $made['service']++;
    }

    // ── Food orders ─────────────────────────────────────────────────────────
    for ($i = 0; $i < $foodCount; $i++) {
        [$requestId, $status] = $insertRequest('food', $i + 1000);
        $pay = match ($status) {
            'completed' => 'paid',
            'denied'    => 'unpaid',
            'approved'  => mt_rand(1, 100) <= 60 ? 'paid' : 'unpaid',
            default     => 'unpaid',
        };
        $lines = mt_rand(1, 3);
        for ($line = 0; $line < $lines; $line++) {
            [$item, $category, $price] = $pick($foodCatalog);
            $qty = mt_rand(1, 3);
            $total = round($price * $qty, 2);
            $special = mt_rand(1, 100) <= 25 ? 'less sugar please' : null;
            $bind($foodStmt, [$requestId, $item, $category, $qty, $price, $total, $special, $pay]);
            $foodStmt->execute();
        }
        $made['food']++;
    }

    // ── Bookings ────────────────────────────────────────────────────────────
    $bookingStatusWeights = ['denied' => 25, 'cancelled' => 20, 'approved' => 10, 'pending' => 5];
    for ($i = 0; $i < $bookingCount; $i++) {
        $room = $pick($rooms);
        $nights = mt_rand(1, 3);
        $checkIn = $dateAgo(6, 400);
        $checkOut = date('Y-m-d', strtotime($checkIn . " +{$nights} days"));
        $status = $weighted($bookingStatusWeights);
        $total = round(($nightlyRate[$room['type']] ?? 3500.00) * $nights, 2);
        $deposit = round($total * 0.5, 2);

        $token = bin2hex(random_bytes(32));
        $name = MARKER_NAME . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT);
        $email = MARKER_EMAIL . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT) . MARKER_EMAIL_DOM;
        $phone = '09' . str_pad((string)mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
        $created = date('Y-m-d H:i:s', strtotime($checkIn . ' -' . mt_rand(1, 30) . ' days'));
        $persons = mt_rand(1, 4);

        $denial = $status === 'denied' ? MARKER_REQ . 'no rooms available for the requested dates' : null;
        $cancelledAt = $status === 'cancelled' ? date('Y-m-d H:i:s', strtotime($checkIn . ' -2 days')) : null;
        $cancelledBy = $status === 'cancelled' ? 'guest' : null;
        $cancelReason = $status === 'cancelled' ? MARKER_REQ . 'guest changed their plans' : null;
        $cancelFee = $status === 'cancelled' ? $deposit : null;
        $cancelPolicy = $status === 'cancelled' ? '50% of the booking total is non-refundable' : null;

        // Only a live reservation carries a verified deposit; a finished one
        // never does. A verified deposit also keeps the demo pending bookings
        // out of the unpaid-release sweep.
        $depositStatus = in_array($status, ['approved', 'pending'], true) ? 'verified' : 'unpaid';
        $depositAmount = $depositStatus === 'verified' ? $deposit : null;
        $depositVerifiedAt = $depositStatus === 'verified' ? $created : null;

        $bind($bookingStmt, [
            $name, $email, $token, $phone, $room['type'], $room['number'], $persons, $checkIn, $checkOut,
            MARKER_SPECIAL, $created, $depositAmount, $depositStatus, $depositVerifiedAt,
            $status, $denial, $cancelledAt, $cancelledBy, $cancelReason, $cancelFee, $cancelPolicy, $created,
        ]);
        $bookingStmt->execute();
        $made['booking']++;
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$total = $made['service'] + $made['food'] + $made['booking'];
echo "Demo requests seeded: {$made['service']} service, {$made['food']} food, {$made['booking']} booking = {$total}.\n";
echo 'Marker: requests.message LIKE "' . MARKER_REQ . '%", bookings.guest_email LIKE "' . MARKER_EMAIL . '%' . MARKER_EMAIL_DOM . '".' . "\n";
echo "Remove with: php database/demo_requests_seed.php --clean\n";
exit(0);
