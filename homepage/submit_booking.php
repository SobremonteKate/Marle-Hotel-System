<?php
// ============================================================
//  homepage/submit_booking.php
//  Guest submits a booking request from home.php.
//
//  POST params (daily):
//    guest_name, guest_email, guest_phone,
//    room_type, persons, check_in, check_out, special_requests
//
//  POST params (hourly, additional):
//    booking_type    = 'hourly'
//    check_in_time   — HH:MM:SS
//    check_out_time  — HH:MM:SS  (computed by JS)
//    hours_requested — int 1-23
//    (check_in == check_out for hourly)
// ============================================================
header('Content-Type: application/json');
include('../dbconnect.php');
// No database? Say so in words instead of showing a fatal (includes/db_guard.php).
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json');
require_once __DIR__ . '/../includes/email_validation.php';
require_once __DIR__ . '/../includes/booking_notifications.php';
// The hotel's clock: the past-date gate below must use the same calendar day the
// rest of this data is stamped with. See includes/server_clock.php.
require_once __DIR__ . '/../includes/server_clock.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bookingFail([['', 'method_not_allowed', 'Method not allowed.']], 405);
}

$bookingType = trim($_POST['booking_type'] ?? 'daily');
$guestName   = trim($_POST['guest_name']   ?? '');
$guestEmail  = trim($_POST['guest_email']  ?? '');
$guestPhone  = trim($_POST['guest_phone']  ?? '');
$roomType    = trim($_POST['room_type']    ?? '');
$roomNumber  = trim($_POST['room_number']  ?? '');
$persons     = (int)($_POST['persons']     ?? 1);
$checkIn     = trim($_POST['check_in']     ?? '');
$checkOut    = trim($_POST['check_out']    ?? '');
$specialReqs = trim($_POST['special_requests'] ?? '');
$addonIdsInput = $_POST['addon_ids'] ?? [];
$termsAccepted = ($_POST['terms_accepted'] ?? '') === '1';
$termsVersion = '2026-09-v1';
// The hotel's clock, not PHP's (includes/server_clock.php): this stamp is read back
// and shown beside created_at, which the database writes — and here the two clocks are
// six hours apart.
$termsAcceptedAt = marle_db_now($conn);
$statusToken = bin2hex(random_bytes(32));

if (!is_array($addonIdsInput)) {
    $addonIdsInput = [$addonIdsInput];
}
$addonIds = array_values(array_unique(array_filter(array_map('intval', $addonIdsInput), static function ($id) {
    return $id > 0;
})));

if (!empty($addonIds)) {
    $checkAmenity = $conn->prepare('SELECT id FROM amenitiestbl WHERE id = ? LIMIT 1');
    foreach ($addonIds as $addonId) {
        $checkAmenity->bind_param('i', $addonId);
        $checkAmenity->execute();
        if (!$checkAmenity->get_result()->fetch_assoc()) {
            bookingFail([['addons', 'addon_unavailable', 'One or more selected add-ons are no longer available.']]);
        }
    }
    $checkAmenity->close();
}

function saveBookingAddons(mysqli $conn, int $bookingId, array $addonIds): void {
    if (empty($addonIds)) return;

    $findAmenity = $conn->prepare('SELECT title FROM amenitiestbl WHERE id = ? LIMIT 1');
    $saveAddon = $conn->prepare(
        'INSERT INTO booking_addons (booking_id, amenity_id, amenity_title_snapshot)
         VALUES (?, ?, ?)'
    );
    foreach ($addonIds as $addonId) {
        $findAmenity->bind_param('i', $addonId);
        $findAmenity->execute();
        $amenity = $findAmenity->get_result()->fetch_assoc();
        if (!$amenity) {
            throw new RuntimeException('One or more selected add-ons are no longer available.');
        }
        $title = (string)$amenity['title'];
        $saveAddon->bind_param('iis', $bookingId, $addonId, $title);
        $saveAddon->execute();
    }
    $findAmenity->close();
    $saveAddon->close();
}

/**
 * The message the guest sees after a successful submission.
 * Says plainly whether the confirmation email actually went out, instead of
 * promising an email that a misconfigured server would never deliver.
 */
function bookingSuccessMessage(string $email, bool $mailed, string $kind): string
{
    $safeEmail = htmlspecialchars($email);
    $intro = 'Your ' . $kind . ' booking request has been submitted! ';

    $intro .= $mailed
        ? 'Payment instructions were emailed to ' . $safeEmail . '. '
        : 'The server could not send email right now — please contact the front desk for the deposit details. ';

    return $intro . 'Pay the reservation deposit and upload your proof of payment on the booking status page to confirm your room.';
}

/**
 * Refuse a booking request in a shape the form can act on.
 *
 * The page used to print `message` — one English sentence with every failure
 * joined by a space — at the foot of the modal, far from the field at fault.
 * `errors` names the field and carries a stable code the page maps to a
 * translated sentence, so a Tagalog guest reads a Tagalog error beside the
 * input that caused it. `message` is kept for anything that only reads that.
 *
 * @param list<array{field:string, code:string, message:string}> $errors
 */
function bookingFail(array $errors, int $status = 400): void
{
    // Call sites write the terse [field, code, message]; this normalises to the
    // named shape the page consumes, so both spellings are safe.
    $normalised = array_map(static function (array $one): array {
        if (isset($one['code']) || isset($one['field'])) {
            return [
                'field'   => (string)($one['field'] ?? ''),
                'code'    => (string)($one['code'] ?? 'invalid'),
                'message' => (string)($one['message'] ?? ''),
            ];
        }
        return [
            'field'   => (string)($one[0] ?? ''),
            'code'    => (string)($one[1] ?? 'invalid'),
            'message' => (string)($one[2] ?? ''),
        ];
    }, $errors);

    if (!headers_sent()) {
        http_response_code($status);
    }

    $messages = array_map(static fn(array $one): string => $one['message'], $normalised);
    echo json_encode([
        'success' => false,
        'code'    => $normalised[0]['code']  ?? 'invalid',
        'field'   => $normalised[0]['field'] ?? '',
        'message' => implode(' ', $messages),
        'errors'  => $normalised,
    ]);
    exit();
}

// ── Shared validation ──────────────────────────────────────
// Each entry is {field, code, message}: the field the page marks, the code it
// translates, and the English sentence kept for clients that read only text.
$errors = [];
if ($guestName === '') {
    $errors[] = ['guest_name', 'name_required', 'Guest name is required.'];
}
if (!preg_match('/^\d{11}$/', $guestPhone)) {
    $errors[] = ['guest_phone', 'phone_invalid', 'Phone number must contain exactly 11 digits.'];
}
// Any real domain is welcome (corporate, school, ISP, provincial provider):
// the rule is syntax + a working mail server + no throwaway mailbox providers.
// See includes/email_validation.php.
$emailError = null;
if (!marle_validate_email($guestEmail, $emailError)) {
    $errors[] = ['guest_email', 'email_invalid', $emailError ?? 'A valid email address is required.'];
}

if ($roomType === '') {
    $errors[] = ['room_type', 'room_required', 'Room type is required.'];
}
if ($persons < 1) {
    $errors[] = ['persons', 'persons_invalid', 'At least 1 person is required.'];
}
if (!$checkIn) {
    $errors[] = ['check_in', 'check_in_required', 'Check-in date is required.'];
}
if (!$termsAccepted) {
    $errors[] = ['terms', 'terms_required', 'You must accept the booking terms and conditions.'];
}

if (!empty($errors)) {
    bookingFail($errors);
}

// The hotel's calendar day, not this server's PHP timezone. Every booking stamp is
// written with MySQL's clock, and on this box PHP runs Europe/Berlin while MySQL runs
// UTC+8 — six hours apart — so between midnight and 06:00 a PHP-date gate compared
// against *yesterday's* date and let through a check-in the hotel had already finished
// with. marle_hotel_date() asks the database (CURDATE()), which is the clock this data
// is stamped with (includes/server_clock.php).
$today = marle_hotel_date(0);
if ($checkIn < $today) {
    bookingFail([['check_in', 'check_in_past', 'Check-in date cannot be in the past.']]);
}

// ── Serialize same-room submissions (atomic availability fix, 2026-09-26) ──
// The availability SELECTs below and the INSERT are separate statements, so
// two simultaneous submissions could both pass the check and both insert
// (the double-booking race). A per-room advisory lock makes check+insert
// atomic with respect to every other booking request for this room.
// Released at request end (shutdown release; MySQL also frees the lock if
// the connection drops for any reason).
$roomLockName = 'marlehotel:booking:room:' . ($roomNumber !== '' ? $roomNumber : 'any');
$lockStmt = $conn->prepare('SELECT GET_LOCK(?, 10)');
$lockStmt->bind_param('s', $roomLockName);
$lockStmt->execute();
$lockRes = $lockStmt->get_result()->fetch_row();
$lockStmt->close();
if (!$lockRes || (int)$lockRes[0] !== 1) {
    bookingFail([['', 'room_busy', 'This room is being booked by another guest right now. Please try again in a few seconds.']], 409);
}
register_shutdown_function(static function () use ($conn, $roomLockName): void {
    try {
        $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
        $release->bind_param('s', $roomLockName);
        $release->execute();
        $release->close();
    } catch (Throwable $e) {
        // Backstop: the lock is freed automatically when the connection closes.
    }
});

// ── HOURLY path ────────────────────────────────────────────
if ($bookingType === 'hourly') {
    $checkInTime     = trim($_POST['check_in_time']   ?? '');
    $checkOutTime    = trim($_POST['check_out_time']  ?? '');
    $hoursRequested  = (int)($_POST['hours_requested'] ?? 0);

    // For hourly, check_in === check_out (same day)
    $checkOut = $checkIn;

    // Server-side validation
    if ($hoursRequested < 1 || $hoursRequested > 23) {
        bookingFail([['hours', 'hours_range', 'Duration must be between 1 and 23 hours.']]);
    }
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $checkInTime) ||
        !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $checkOutTime)) {
        bookingFail([['start_time', 'time_invalid', 'Invalid time format.']]);
    }
    // Normalise to HH:MM:SS
    if (strlen($checkInTime)  === 5) $checkInTime  .= ':00';
    if (strlen($checkOutTime) === 5) $checkOutTime .= ':00';

    if ($checkOutTime > '23:59:59') {
        bookingFail([['start_time', 'time_past_midnight', 'Booking cannot extend past midnight.']]);
    }
    if ($checkOutTime <= $checkInTime) {
        bookingFail([['start_time', 'time_order', 'Check-out time must be after check-in time.']]);
    }

    // Overlap conflict check - check against hourly bookings
    $conflictStmt = $conn->prepare(
        "SELECT id FROM bookings
         WHERE  booking_type   = 'hourly'
           AND  status         IN ('pending', 'approved')
           AND  room_type      = ?
           AND  check_in       = ?
           AND  check_in_time  < ?
           AND  check_out_time > ?
           AND  room_number    = ?
         LIMIT 1"
    );
    $conflictStmt->bind_param('sssss', $roomType, $checkIn, $checkOutTime, $checkInTime, $roomNumber);
    $conflictStmt->execute();
    $conflictRes = $conflictStmt->get_result();
    $conflict = $conflictRes ? $conflictRes->fetch_assoc() : null;
    if ($conflictRes) $conflictRes->free();
    $conflictStmt->close();

    if ($conflict) {
        bookingFail([['start_time', 'room_taken', 'Sorry, this room is not available during that time window. Please choose a different time.']], 409);
    }

    // Also check for conflicts with daily/nightly bookings for the same room
    $dailyConflictStmt = $conn->prepare(
        "SELECT id FROM bookings
         WHERE  booking_type  = 'daily'
           AND  status        IN ('pending', 'approved')
           AND  room_number   = ?
           AND  check_in      <= ?
           AND  check_out     >= ?
         LIMIT 1"
    );
    $dailyConflictStmt->bind_param('sss', $roomNumber, $checkIn, $checkIn);
    $dailyConflictStmt->execute();
    $dailyConflictRes = $dailyConflictStmt->get_result();
    $dailyConflict = $dailyConflictRes ? $dailyConflictRes->fetch_assoc() : null;
    if ($dailyConflictRes) $dailyConflictRes->free();
    $dailyConflictStmt->close();

    if ($dailyConflict) {
        bookingFail([['start_time', 'date_taken', 'Sorry, this room is not available on this date. It is already booked for a nightly stay.']], 409);
    }

    // Insert hourly booking
    $stmt = $conn->prepare(
        "INSERT INTO bookings
            (guest_name, guest_email, status_token, guest_phone, room_type, room_number, persons,
             check_in, check_out, special_requests, terms_accepted_at, terms_version,
             booking_type, check_in_time, check_out_time, hours_requested)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'hourly', ?, ?, ?)"
    );
    $stmt->bind_param(
        'ssssssisssssssi',
        $guestName, $guestEmail, $statusToken, $guestPhone, $roomType, $roomNumber, $persons,
        $checkIn, $checkOut, $specialReqs, $termsAcceptedAt, $termsVersion,
        $checkInTime, $checkOutTime, $hoursRequested
    );
    $stmt->execute();
    $bookingId = $stmt->insert_id;
    $stmt->close();

    if ($bookingId) {
        try {
            saveBookingAddons($conn, $bookingId, $addonIds);
        } catch (Throwable $e) {
            error_log('booking add-ons failed: ' . $e->getMessage());
            bookingFail([['addons', 'addon_unavailable', 'One or more selected add-ons are no longer available.']], 500);
        }
        // ── Notify staff of new booking ───────────────────────────────────
        $notifMsg  = "New booking from {$guestName} — {$roomType} (hourly)";
        $notifStmt = $conn->prepare(
            "INSERT INTO notifications (type, reference_id, room_number, message)
             VALUES ('booking', ?, ?, ?)"
        );
        $notifStmt->bind_param('iss', $bookingId, $roomNumber, $notifMsg);
        $notifStmt->execute();
        $notifStmt->close();

        // Confirmation email to the guest + a deposit alert to the hotel inbox.
        $mailed = marle_notify_new_booking($conn, $bookingId);

        echo json_encode([
            'success'    => true,
            'message'    => bookingSuccessMessage($guestEmail, $mailed['guest'], 'hourly'),
            'booking_id' => $bookingId,
            'status_token' => $statusToken,
        ]);
    } else {
        bookingFail([['', 'submit_failed', 'Failed to submit booking. Please try again.']], 500);
    }
    exit();
}

// ── DAILY path (unchanged) ─────────────────────────────────
if (!$checkOut) {
    bookingFail([['check_out', 'check_out_required', 'Check-out date is required.']]);
}
if (strtotime($checkOut) <= strtotime($checkIn)) {
    bookingFail([['check_out', 'check_out_order', 'Check-out must be after check-in.']]);
}

// Double-check availability for the specific room number
// Check for conflicts with both daily and hourly bookings
$conflictCheckStmt = $conn->prepare(
    "SELECT id FROM bookings
     WHERE status IN ('pending', 'approved')
       AND room_number = ?
       AND (
           -- Daily bookings: date range overlap
           (booking_type = 'daily' AND check_in < ? AND check_out > ?)
           OR
           -- Hourly bookings: check for time overlap
           (booking_type = 'hourly' AND check_in < ? AND check_out > ?)
       )"
);
$conflictCheckStmt->bind_param('sssss', $roomNumber, $checkOut, $checkIn, $checkOut, $checkIn);
$conflictCheckStmt->execute();
$conflictCheckRes = $conflictCheckStmt->get_result();
$conflictCheck = $conflictCheckRes ? $conflictCheckRes->fetch_assoc() : null;
if ($conflictCheckRes) $conflictCheckRes->free();
$conflictCheckStmt->close();

if ($conflictCheck) {
    bookingFail([['check_in', 'room_taken', 'Sorry, this room is not available for the selected dates. It is already booked.']], 409);
}

// Also check customerstbl for checked-in guests
$customerCheckStmt = $conn->prepare(
    "SELECT room_no FROM customerstbl
     WHERE status = 'checked-in'
       AND room_no = ?
       AND DATE(date_from) <= ? AND DATE(date_to) >= ?"
);
$customerCheckStmt->bind_param('sss', $roomNumber, $checkOut, $checkIn);
$customerCheckStmt->execute();
$customerCheckRes = $customerCheckStmt->get_result();
$customerCheck = $customerCheckRes ? $customerCheckRes->fetch_assoc() : null;
if ($customerCheckRes) $customerCheckRes->free();
$customerCheckStmt->close();

if ($customerCheck) {
    bookingFail([['check_in', 'room_occupied', 'Sorry, this room is not available. There is a current guest checked in.']]);
}

// Room assignment already validated above - just verify room exists
$roomExistsStmt = $conn->prepare(
    "SELECT room_number FROM roomstbl WHERE room_number = ?"
);
$roomExistsStmt->bind_param('s', $roomNumber);
$roomExistsStmt->execute();
$roomExistsResult = $roomExistsStmt->get_result();
if (!$roomExistsResult || $roomExistsResult->num_rows === 0) {
    // For Without Garage rooms 4-16, they may not be in roomstbl
    if ($roomType !== 'Without Garage' || $roomNumber < '4' || $roomNumber > '16') {
        bookingFail([['', 'room_invalid', 'Invalid room number.']]);
    }
}
if ($roomExistsResult) $roomExistsResult->free();
$roomExistsStmt->close();

// Insert daily booking
$stmt = $conn->prepare(
    "INSERT INTO bookings
        (guest_name, guest_email, status_token, guest_phone, room_type, persons,
         check_in, check_out, special_requests, terms_accepted_at, terms_version, room_number)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'sssssi' . 'ssssss',
    $guestName, $guestEmail, $statusToken, $guestPhone, $roomType, $persons,
    $checkIn, $checkOut, $specialReqs, $termsAcceptedAt, $termsVersion, $roomNumber
);
$stmt->execute();
$bookingId = $stmt->insert_id;
$stmt->close();

if ($bookingId) {
    try {
        saveBookingAddons($conn, $bookingId, $addonIds);
    } catch (Throwable $e) {
        error_log('booking add-ons failed: ' . $e->getMessage());
        bookingFail([['addons', 'addon_unavailable', 'One or more selected add-ons are no longer available.']], 500);
    }
    // ── Notify staff of new booking ───────────────────────────────────────
    $notifMsg  = "New booking from {$guestName} — {$roomType} (daily)";
    $notifStmt = $conn->prepare(
        "INSERT INTO notifications (type, reference_id, room_number, message)
         VALUES ('booking', ?, ?, ?)"
    );
    $notifStmt->bind_param('iss', $bookingId, $roomNumber, $notifMsg);
    $notifStmt->execute();
    $notifStmt->close();

    // Confirmation email to the guest + a deposit alert to the hotel inbox.
    $mailed = marle_notify_new_booking($conn, $bookingId);

    echo json_encode([
        'success'    => true,
        'message'    => bookingSuccessMessage($guestEmail, $mailed['guest'], 'stay'),
        'booking_id' => $bookingId,
        'status_token' => $statusToken,
    ]);
} else {
    bookingFail([['', 'submit_failed', 'Failed to submit booking. Please try again.']], 500);
}