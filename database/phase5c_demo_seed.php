<?php
// MarleHotel Phase 5C: deterministic demo seed.
// Usage: php phase5c_demo_seed.php --database=marlehoteldb_demo_test
// The seed inserts exactly 225 marked demo stays and is idempotent.

// CLI only. This script writes 225 demo stays plus their reviews, tokens,
// requests and ledger rows, and it opens its own mysqli handle — it is a
// maintenance tool, not a page. Until 2026-10-14 a plain GET of
// /MarleHotel/database/phase5c_demo_seed.php ran it for anyone who asked and
// echoed the result; database/.htaccess now refuses the directory outright,
// and this is the same rule held by the script itself (the convention is
// already in database/demo_requests_seed.php).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/demo_review_content.php';

$database = 'marlehoteldb';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--database=')) {
        $database = substr($argument, 11);
    }
}

$conn = new mysqli('localhost', 'root', '', $database);
$conn->set_charset('utf8mb4');

$marker = 'DEMO-5C-';
$existing = $conn->query("SELECT COUNT(*) AS total FROM bookings WHERE guest_email LIKE 'demo.5c.%@example.test'")->fetch_assoc();
if ((int)$existing['total'] === 225) {
    echo "Demo seed already complete: 225 stays.\n";
    exit(0);
}
if ((int)$existing['total'] !== 0) {
    throw new RuntimeException('Partial demo seed detected; restore the disposable database before rerunning.');
}

$roomTypes = ['Without Garage', 'With Garage', 'Suite', 'Family'];
$roomNumbers = ['4', '5', '10', '13'];
$categories = ['hotel', 'restaurant', 'amenities', 'service'];
$amenityIds = [1, 2, 3, 4];
$baseDate = new DateTimeImmutable('2026-10-01');
$counts = [
    'customerstbl' => 0, 'bookings' => 0, 'reviews' => 0, 'booking_addons' => 0,
    'guest_tokens' => 0, 'requests' => 0, 'extension_requests' => 0,
    'extension_history' => 0, 'service_requests' => 0, 'food_order_items' => 0,
    'transaction_history' => 0, 'transaction_collections' => 0,
    'transaction_collection_items' => 0,
];

$conn->begin_transaction();
try {
    $customerStmt = $conn->prepare(
        'INSERT INTO customerstbl
            (room_no, transportation, persons, plate_no, hours, additional, status, checked_out_at, date_from, date_to)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $bookingStmt = $conn->prepare(
        'INSERT INTO bookings
            (guest_name, guest_email, status_token, guest_phone, room_type, room_number, persons,
             check_in, check_out, booking_type, check_in_time, check_out_time, hours_requested,
             special_requests, terms_accepted_at, terms_version, status, denial_reason,
             cancelled_at, cancelled_by, cancellation_reason, admin_notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $addonStmt = $conn->prepare(
        'INSERT INTO booking_addons (booking_id, amenity_id, amenity_title_snapshot)
         SELECT ?, id, title FROM amenitiestbl WHERE id = ?'
    );
    $reviewStmt = $conn->prepare(
        'INSERT INTO reviewstbl (guest_name, room_no, token, rating, category, review_text, review_date)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $tokenStmt = $conn->prepare(
        'INSERT INTO guest_tokens (token, room_number, customer_id, hours_of_stay, expires_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $requestStmt = $conn->prepare(
        'INSERT INTO requests (customer_id, room_number, type, message, status)
         VALUES (?, ?, ?, ?, ?)'
    );
    $extensionRequestStmt = $conn->prepare(
        'INSERT INTO extension_requests
            (request_id, customer_id, room_number, room_type, hours_requested, rate_per_hour,
             total_amount, payment_status, special_request, approved_at, approved_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $extensionHistoryStmt = $conn->prepare(
        'INSERT INTO extension_history
            (extension_req_id, customer_id, room_number, room_type, hours_added, rate_per_hour,
             total_amount, payment_status, new_checkout, approved_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $serviceStmt = $conn->prepare(
        'INSERT INTO service_requests
            (request_id, service_name, service_category, quantity, unit_price, total_amount, payment_status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $foodStmt = $conn->prepare(
        'INSERT INTO food_order_items
            (request_id, item_name, item_category, quantity, unit_price, total_amount, payment_status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $transactionStmt = $conn->prepare(
        'INSERT INTO transaction_history
            (transaction_type, customer_id, room_number, room_type, amount, status, denial_reason, reference_id, notes, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $collectionStmt = $conn->prepare(
        'INSERT INTO transaction_collections
            (room_number, total_due, amount_received, change_amount, collected_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $collectionItemStmt = $conn->prepare(
        'INSERT INTO transaction_collection_items (collection_id, transaction_id, amount)
         VALUES (?, ?, ?)'
    );

    for ($index = 1; $index <= 225; $index++) {
        $sequence = str_pad((string)$index, 3, '0', STR_PAD_LEFT);
        $roomType = $roomTypes[($index - 1) % 4];
        $roomNumber = $roomNumbers[($index - 1) % 4];
        $persons = (($index - 1) % 6) + 1;
        $bookingType = $index % 2 === 0 ? 'hourly' : 'daily';
        $date = $baseDate->modify('+' . (($index - 1) % 30) . ' days');
        $checkIn = $date->format('Y-m-d');
        $checkOut = $bookingType === 'hourly' ? $checkIn : $date->modify('+1 day')->format('Y-m-d');
        $checkInTime = $bookingType === 'hourly' ? sprintf('%02d:00:00', 8 + (($index - 1) % 10)) : null;
        $checkOutTime = $bookingType === 'hourly' ? sprintf('%02d:00:00', 11 + (($index - 1) % 10)) : null;
        $hours = $bookingType === 'hourly' ? 3 : null;
        $guestName = demo_guest_name($index);
        $email = 'demo.5c.' . strtolower($sequence) . '@example.test';
        $phone = '09' . str_pad((string)(100000000 + $index), 9, '0', STR_PAD_LEFT);
        $statusToken = hash('sha256', $marker . $sequence);
        $bookingStatus = match ($index % 5) {
            0 => 'approved', 1 => 'pending', 2 => 'denied', 3 => 'cancelled', default => 'approved'
        };
        $isNoShow = $index % 15 === 0;
        $denialReason = $bookingStatus === 'denied' ? 'Demo booking denied for workflow testing.' : null;
        $cancelledAt = $bookingStatus === 'cancelled' ? $date->format('Y-m-d 12:00:00') : null;
        $cancelledBy = $bookingStatus === 'cancelled' ? 'system-demo' : null;
        $cancellationReason = $bookingStatus === 'cancelled'
            ? ($isNoShow ? 'Demo no-show cancellation.' : 'Demo guest cancellation.') : null;
        $termsAt = $date->format('Y-m-d 10:00:00');
        $specialRequests = 'Demo note ' . $sequence;

        $transport = $index % 3 === 0 ? 'Private Vehicle' : 'Walk-In';
        $plate = 'D5C-' . $sequence;
        $customerStatus = $bookingStatus === 'approved' && $index % 7 === 0 ? 'checked-in' : 'checked-out';
        $dateFrom = $date->format('Y-m-d 14:00:00');
        $dateTo = $bookingType === 'hourly' ? $date->format('Y-m-d 17:00:00') : $date->modify('+1 day')->format('Y-m-d 12:00:00');
        $checkedOutAt = $customerStatus === 'checked-out' ? $dateTo : null;
        $additional = json_encode(['demo_marker' => $marker . $sequence], JSON_THROW_ON_ERROR);
        $hoursText = (string)($bookingType === 'hourly' ? 3 : 24);
        $customerStmt->bind_param('ssisssssss', $roomNumber, $transport, $persons, $plate, $hoursText, $additional, $customerStatus, $checkedOutAt, $dateFrom, $dateTo);
        $customerStmt->execute();
        $customerId = $conn->insert_id;
        $counts['customerstbl']++;

        $termsVersion = '2026-09-v1';
        $adminNotes = $marker . $sequence;
        $bookingStmt->bind_param('ssssssisssssisssssssss', $guestName, $email, $statusToken, $phone, $roomType, $roomNumber, $persons, $checkIn, $checkOut, $bookingType, $checkInTime, $checkOutTime, $hours, $specialRequests, $termsAt, $termsVersion, $bookingStatus, $denialReason, $cancelledAt, $cancelledBy, $cancellationReason, $adminNotes);
        $bookingStmt->execute();
        $bookingId = $conn->insert_id;
        $counts['bookings']++;

        $amenityId = $amenityIds[($index - 1) % 4];
        $addonStmt->bind_param('ii', $bookingId, $amenityId);
        $addonStmt->execute();
        $counts['booking_addons']++;

        $category = $categories[($index - 1) % 4];
        $rating = 3 + ($index % 3);
        $reviewText = demo_review_text($category, $rating, $index);
        $reviewDate = $date->format('Y-m-d');
        $reviewStmt->bind_param('sssisss', $guestName, $roomNumber, $statusToken, $rating, $category, $reviewText, $reviewDate);
        $reviewStmt->execute();
        $counts['reviews']++;

        $transactionType = ['check_in', 'extension', 'service', 'food'][($index - 1) % 4];
        $transactionStatus = $index % 7 === 0 ? 'denied' : ($index % 2 === 0 ? 'unpaid' : 'paid');
        $amount = 250.00 + (($index % 10) * 50);
        $sourceId = null;
        $transactionNote = $marker . $sequence . ' ' . $transactionType;

        if ($transactionType === 'check_in') {
            $token = 'D5C' . $sequence;
            $expires = $date->format('Y-m-d 23:59:59');
            $tokenHours = $bookingType === 'hourly' ? 3 : 24;
            $createdBy = $marker . $sequence;
            $tokenStmt->bind_param('ssiiss', $token, $roomNumber, $customerId, $tokenHours, $expires, $createdBy);
            $tokenStmt->execute();
            $sourceId = $conn->insert_id;
            $counts['guest_tokens']++;
        } else {
            $requestType = $transactionType === 'extension' ? 'extend' : $transactionType;
            $requestMessage = $marker . $sequence . ' ' . $requestType . ' request';
            $requestStatus = $transactionStatus === 'denied' ? 'denied' : ($transactionStatus === 'paid' ? 'completed' : 'approved');
            $requestStmt->bind_param('issss', $customerId, $roomNumber, $requestType, $requestMessage, $requestStatus);
            $requestStmt->execute();
            $requestId = $conn->insert_id;
            $counts['requests']++;

            if ($transactionType === 'extension') {
                $rate = 200.00;
                $extensionStatus = $transactionStatus === 'denied' ? 'unpaid' : ($transactionStatus === 'paid' ? 'paid' : 'unpaid');
                $approvedAt = $extensionStatus === 'paid' ? $date->format('Y-m-d 13:00:00') : null;
                $approvedBy = $approvedAt ? 'demo-staff' : null;
                $extensionHours = 1;
                $extensionRequestStmt->bind_param('iissiddssss', $requestId, $customerId, $roomNumber, $roomType, $extensionHours, $rate, $amount, $extensionStatus, $specialRequests, $approvedAt, $approvedBy);
                $extensionRequestStmt->execute();
                $extensionRequestId = $conn->insert_id;
                $counts['extension_requests']++;
                $newCheckout = $date->modify('+1 day')->format('Y-m-d');
                $historyStatus = $extensionStatus === 'paid' ? 'paid' : 'unpaid';
                $historyHours = 1;
                $historyApprovedBy = 'demo-staff';
                $extensionHistoryStmt->bind_param('iissiddsss', $extensionRequestId, $customerId, $roomNumber, $roomType, $historyHours, $rate, $amount, $historyStatus, $newCheckout, $historyApprovedBy);
                $extensionHistoryStmt->execute();
                $sourceId = $conn->insert_id;
                $counts['extension_history']++;
            } elseif ($transactionType === 'service') {
                $serviceStatus = $transactionStatus === 'denied' ? 'denied' : ($transactionStatus === 'paid' ? 'paid' : 'unpaid');
                $serviceName = 'Demo Housekeeping';
                $serviceCategory = 'Room Service';
                $quantity = 1;
                $unitPrice = $amount;
                $serviceStmt->bind_param('issidds', $requestId, $serviceName, $serviceCategory, $quantity, $unitPrice, $amount, $serviceStatus);
                $serviceStmt->execute();
                $sourceId = $conn->insert_id;
                $counts['service_requests']++;
            } else {
                $foodStatus = $transactionStatus === 'denied' ? 'unpaid' : ($transactionStatus === 'paid' ? 'paid' : 'unpaid');
                $itemName = 'Demo Breakfast';
                $itemCategory = 'Breakfast';
                $quantity = 1;
                $unitPrice = $amount;
                $foodStmt->bind_param('issidds', $requestId, $itemName, $itemCategory, $quantity, $unitPrice, $amount, $foodStatus);
                $foodStmt->execute();
                $sourceId = $conn->insert_id;
                $counts['food_order_items']++;
            }
        }

        $denial = $transactionStatus === 'denied' ? 'Demo denied transaction.' : null;
        $createdAt = $date->format('Y-m-d 15:00:00');
        $transactionStmt->bind_param('sissdssiss', $transactionType, $customerId, $roomNumber, $roomType, $amount, $transactionStatus, $denial, $sourceId, $transactionNote, $createdAt);
        $transactionStmt->execute();
        $transactionId = $conn->insert_id;
        $counts['transaction_history']++;

        if ($transactionStatus === 'paid') {
            $received = $amount + ($index % 3 === 0 ? 50.00 : 0.00);
            $change = $received - $amount;
            $collector = 'demo-seed';
            $collectionStmt->bind_param('sddds', $roomNumber, $amount, $received, $change, $collector);
            $collectionStmt->execute();
            $collectionId = $conn->insert_id;
            $counts['transaction_collections']++;
            $collectionItemStmt->bind_param('iid', $collectionId, $transactionId, $amount);
            $collectionItemStmt->execute();
            $counts['transaction_collection_items']++;
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'database' => $database, 'stays' => 225, 'counts' => $counts], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Demo seed rolled back: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
