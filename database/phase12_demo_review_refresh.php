<?php
// Refreshes the marked Phase 5C reviews in an existing database.
// Usage: php phase12_demo_review_refresh.php --database=marlehoteldb

// CLI only. Rewrites the marked Phase 5C reviews in a live database, opening
// its own mysqli handle. Same rule as database/demo_requests_seed.php, and
// matched by database/.htaccess, which refuses the whole directory over HTTP.
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

$bookingSelectStmt = $conn->prepare(
    "SELECT id, guest_email
     FROM bookings
     WHERE guest_email LIKE 'demo.5c.%@example.test'
        ORDER BY id"
);
$bookingSelectStmt->execute();
$bookings = $bookingSelectStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$bookingSelectStmt->close();

$selectStmt = $conn->prepare(
    "SELECT rv.id, b.guest_email, rv.category, rv.rating
     FROM reviewstbl rv
    JOIN bookings b ON CONVERT(b.status_token USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(rv.token USING utf8mb4) COLLATE utf8mb4_unicode_ci
     WHERE b.guest_email LIKE 'demo.5c.%@example.test'
    ORDER BY rv.id"
);
$selectStmt->execute();
$reviews = $selectStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$selectStmt->close();

$bookingUpdateStmt = $conn->prepare('UPDATE bookings SET guest_name = ? WHERE id = ?');
$reviewNameUpdateStmt = $conn->prepare('UPDATE reviewstbl SET guest_name = ? WHERE id = ?');
$updateStmt = $conn->prepare('UPDATE reviewstbl SET review_text = ? WHERE id = ?');
$updatedBookings = 0;
$updatedReviews = 0;

$conn->begin_transaction();
try {
    foreach ($bookings as $booking) {
        if (!preg_match('/\.([0-9]+)@example\.test$/', (string)$booking['guest_email'], $matches)) {
            throw new RuntimeException('Unexpected demo guest email: ' . $booking['guest_email']);
        }

        $sequence = (int)$matches[1];
        $guestName = demo_guest_name($sequence);
        $bookingId = (int)$booking['id'];
        $bookingUpdateStmt->bind_param('si', $guestName, $bookingId);
        $bookingUpdateStmt->execute();
        $updatedBookings++;
    }

    foreach ($reviews as $review) {
        if (!preg_match('/\.([0-9]+)@example\.test$/', (string)$review['guest_email'], $matches)) {
            throw new RuntimeException('Unexpected demo guest email: ' . $review['guest_email']);
        }

        $sequence = (int)$matches[1];
        $guestName = demo_guest_name($sequence);
        $reviewId = (int)$review['id'];
        $reviewNameUpdateStmt->bind_param('si', $guestName, $reviewId);
        $reviewNameUpdateStmt->execute();

        $reviewText = demo_review_text((string)$review['category'], (int)$review['rating'], $sequence);
        $updateStmt->bind_param('si', $reviewText, $reviewId);
        $updateStmt->execute();
        $updatedReviews++;
    }

    $conn->commit();
    echo json_encode([
        'success' => true,
        'database' => $database,
        'matched_bookings' => count($bookings),
        'updated_bookings' => $updatedBookings,
        'matched_reviews' => count($reviews),
        'updated_reviews' => $updatedReviews,
    ], JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, 'Demo review refresh rolled back: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
