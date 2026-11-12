<?php
/**
 * walkin_booking.php — a guest at the desk, recorded from the calendar.
 *
 * WHY THIS EXISTS
 *   Every booking used to start on the website, which means every booking used
 *   to wait for a GCash / bank receipt before a manager could verify its
 *   deposit. A guest who walks in and puts cash on the desk has no receipt to
 *   upload, so there was nowhere to put them: the calendar could only draw
 *   bookings that had already been approved.
 *
 *   This file lets a manager record that stay where they noticed it was
 *   missing — the calendar — and then handle it like any other booking in
 *   Requests → Bookings. Cash is recorded as already verified, because the
 *   money was counted by the person entering it; the manager still approves the
 *   booking, which is what assigns the calendar event.
 *
 *   The form is only a convenience: every number and every rule is re-applied
 *   here, server-side, from the same pricing/deposit helpers the public site
 *   uses. A tampered or stale form cannot move the total, the deposit, or a
 *   room that is already taken.
 *
 *   The guest is emailed the moment the booking is saved (when they gave an
 *   address) and the hotel inbox is alerted, exactly as for a website booking —
 *   and the guest's email carries a printable **PDF receipt** for the cash
 *   (includes/deposit_receipt.php), so a walk-in leaves the desk with a
 *   reference number and a piece of paper they can keep.
 *
 *   Only cash is accepted. A walk-in paying by GCash/bank still belongs in the
 *   normal flow — their receipt has to be uploaded and checked, and the
 *   "always proof" rule in booking_deposit.php is not weakened for it.
 */

require_once __DIR__ . '/booking_pricing.php';
require_once __DIR__ . '/booking_deposit.php';
require_once __DIR__ . '/booking_notifications.php'; // the same guest/hotel emails the website sends
require_once __DIR__ . '/server_clock.php';      // the hotel's today, not PHP's
require_once __DIR__ . '/email_validation.php';  // the website's own email rule

if (!defined('MARLE_WALKIN_MAX_NIGHTS')) define('MARLE_WALKIN_MAX_NIGHTS', 60);

if (!function_exists('marle_walkin_room_types')) {
    /** The room types the hotel actually has, in the order staff know them. */
    function marle_walkin_room_types(mysqli $conn): array
    {
        $types = [];
        $res = $conn->query("SELECT rooms, COUNT(*) AS n FROM roomstbl GROUP BY rooms ORDER BY rooms");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $types[] = (string)$row['rooms'];
            }
        }
        return $types;
    }
}

if (!function_exists('marle_walkin_available_rooms')) {
    /**
     * Which rooms of this type are free for this stay.
     *
     * A room is busy when an active booking (pending or approved) overlaps the
     * stay, or when a guest is physically checked in. Maintenance rooms are
     * never offered. The overlap is half-open — a room whose guest checks out on
     * the arrival date is free — which is the same rule approval applies, and
     * the same one fetch_available_rooms.php uses.
     *
     * @return array<int,array{room_number:string, room_type:string, max_persons:int}>
     */
    function marle_walkin_available_rooms(
        mysqli $conn,
        string $roomType,
        string $checkIn,
        string $checkOut,
        string $stayType = 'daily',
        string $inTime = '14:00:00',
        string $outTime = '12:00:00'
    ): array {
        $busy = [];

        if ($stayType === 'hourly') {
            $stmt = $conn->prepare(
                "SELECT DISTINCT room_number FROM bookings
                  WHERE status IN ('pending', 'approved')
                    AND room_number IS NOT NULL AND room_number <> ''
                    AND (
                        (booking_type = 'hourly'
                         AND check_in = ?
                         AND check_in_time  < ?
                         AND check_out_time > ?)
                        OR
                        (booking_type = 'daily'
                         AND check_in <= ?
                         AND check_out > ?)
                    )"
            );
            $stmt->bind_param('sssss', $checkIn, $outTime, $inTime, $checkIn, $checkIn);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $busy[(string)$row['room_number']] = true;
            }
            $stmt->close();

            $stayStmt = $conn->prepare(
                "SELECT DISTINCT room_no FROM customerstbl
                  WHERE status = 'checked-in' AND room_no <> ''
                    AND DATE(date_from) <= ?
                    AND DATE(date_to)   >= ?"
            );
            $stayStmt->bind_param('ss', $checkIn, $checkIn);
        } else {
            $stmt = $conn->prepare(
                "SELECT DISTINCT room_number FROM bookings
                  WHERE status IN ('pending', 'approved')
                    AND room_number IS NOT NULL AND room_number <> ''
                    AND (
                        (booking_type = 'daily'
                         AND check_in < ? AND check_out > ?)
                        OR
                        (booking_type = 'hourly'
                         AND check_in >= ? AND check_in < ?)
                    )"
            );
            $stmt->bind_param('ssss', $checkOut, $checkIn, $checkIn, $checkOut);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $busy[(string)$row['room_number']] = true;
            }
            $stmt->close();

            $stayStmt = $conn->prepare(
                "SELECT DISTINCT room_no FROM customerstbl
                  WHERE status = 'checked-in' AND room_no <> ''
                    AND DATE(date_from) <  ?
                    AND DATE(date_to)   >  ?"
            );
            $stayStmt->bind_param('ss', $checkOut, $checkIn);
        }

        $stayStmt->execute();
        $stayRes = $stayStmt->get_result();
        while ($row = $stayRes->fetch_assoc()) {
            $busy[(string)$row['room_no']] = true;
        }
        $stayStmt->close();

        $rooms = [];
        $typeStmt = $conn->prepare(
            "SELECT room_number, rooms AS room_type, max_persons
               FROM roomstbl
              WHERE rooms = ? AND room_number IS NOT NULL AND maintenance_status = 0
              ORDER BY CAST(room_number AS UNSIGNED)"
        );
        $typeStmt->bind_param('s', $roomType);
        $typeStmt->execute();
        $typeRes = $typeStmt->get_result();
        while ($row = $typeRes->fetch_assoc()) {
            if (!isset($busy[(string)$row['room_number']])) {
                $rooms[] = [
                    'room_number' => (string)$row['room_number'],
                    'room_type'   => (string)$row['room_type'],
                    'max_persons' => (int)$row['max_persons'],
                ];
            }
        }
        $typeStmt->close();

        return $rooms;
    }
}

if (!function_exists('marle_walkin_quote')) {
    /**
     * The money for a stay, from the same helpers that price a website booking.
     *
     * @param array $stay room_type, booking_type, check_in, check_out, hours_requested
     * @return array{total:float, deposit_rate:float, deposit:float}
     */
    function marle_walkin_quote(mysqli $conn, array $stay): array
    {
        $total = marle_booking_total($stay);
        $rate  = marle_deposit_rate($conn);

        return [
            'total'        => $total,
            'deposit_rate' => $rate,
            'deposit'      => marle_deposit_amount($conn, $total),
        ];
    }
}

if (!function_exists('marle_walkin_validate')) {
    /**
     * Turn raw form input into a clean stay, or a sentence explaining what is
     * wrong. Deliberately the only place the rules live.
     *
     * @param array<string,mixed> $input typically $_POST
     * @return array{ok:bool, error:string, stay:array<string,mixed>}
     */
    function marle_walkin_validate(mysqli $conn, array $input): array
    {
        $fail = static fn(string $message): array => ['ok' => false, 'error' => $message, 'stay' => []];

        $guestName  = trim((string)($input['guest_name'] ?? ''));
        $guestPhone = trim((string)($input['guest_phone'] ?? ''));
        $guestEmail = trim((string)($input['guest_email'] ?? ''));
        $roomType   = trim((string)($input['room_type'] ?? ''));
        $roomNumber = trim((string)($input['room_number'] ?? ''));
        $persons    = (int)($input['persons'] ?? 0);
        $stayType   = ((string)($input['booking_type'] ?? 'daily')) === 'hourly' ? 'hourly' : 'daily';
        $checkIn    = trim((string)($input['check_in'] ?? ''));
        $checkOut   = trim((string)($input['check_out'] ?? ''));
        $inTime     = trim((string)($input['check_in_time'] ?? ''));
        $outTime    = trim((string)($input['check_out_time'] ?? ''));
        $special    = trim((string)($input['special_requests'] ?? ''));

        if ($guestName === '' || mb_strlen($guestName) > 255) {
            return $fail('Enter the guest’s full name.');
        }
        // The desk records the same phone rule the website enforces (exactly 11
        // digits — submit_booking.php): a desk-entered number has to be as
        // reachable as a guest-entered one, or the front desk cannot call the
        // room back.
        if (!preg_match('/^\d{11}$/', $guestPhone)) {
            return $fail('Phone number must contain exactly 11 digits (for example, 09171234567).');
        }

        // Email stays optional — a walk-in often has none — but when one is
        // given it goes through the website's own rule (includes/
        // email_validation.php): syntax, a domain that really accepts mail, and
        // no disposable-mailbox provider. That same helper is what makes the
        // approval email deliverable, so the walk-in cannot record an address
        // the guest would never receive mail at.
        if ($guestEmail !== '') {
            $emailError = null;
            if (!marle_validate_email($guestEmail, $emailError)) {
                return $fail('Email: ' . ($emailError ?? 'Please enter a valid email address.'));
            }
        }
        if (mb_strlen($special) > 500) {
            $special = mb_substr($special, 0, 500);
        }

        $types = marle_walkin_room_types($conn);
        if (!in_array($roomType, $types, true)) {
            return $fail('Choose a room type.');
        }

        $isDate = static function (string $value): bool {
            $date = DateTime::createFromFormat('!Y-m-d', $value);
            return $date !== false && $date->format('Y-m-d') === $value;
        };
        if (!$isDate($checkIn)) {
            return $fail('Choose a valid arrival date.');
        }

        $today = marle_hotel_date(0, $conn);
        if ($checkIn < $today) {
            return $fail('A walk-in cannot start before today.');
        }

        $hours = 0;
        if ($stayType === 'hourly') {
            foreach ([$inTime, $outTime] as $time) {
                if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
                    return $fail('Choose a valid arrival and departure time.');
                }
            }
            if (strlen($inTime) === 5)  $inTime  .= ':00';
            if (strlen($outTime) === 5) $outTime .= ':00';
            if ($inTime >= $outTime) {
                return $fail('Departure has to be later than arrival.');
            }

            $checkOut = $checkIn;
            $hours = (int)ceil((strtotime($checkOut . ' ' . $outTime) - strtotime($checkIn . ' ' . $inTime)) / 3600);
            if ($hours < 1 || $hours > 23) {
                return $fail('An hourly stay is between 1 and 23 hours — use an overnight stay for longer.');
            }
        } else {
            if (!$isDate($checkOut)) {
                return $fail('Choose a valid departure date.');
            }
            if ($checkOut <= $checkIn) {
                return $fail('Departure has to be after arrival.');
            }
            $nights = (int)round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
            if ($nights > MARLE_WALKIN_MAX_NIGHTS) {
                return $fail('That stay is longer than ' . MARLE_WALKIN_MAX_NIGHTS . ' nights.');
            }
            $inTime  = '14:00:00';
            $outTime = '12:00:00';
        }

        $available = marle_walkin_available_rooms($conn, $roomType, $checkIn, $checkOut, $stayType, $inTime, $outTime);
        $match = null;
        foreach ($available as $room) {
            if ($room['room_number'] === $roomNumber) {
                $match = $room;
                break;
            }
        }
        if ($match === null) {
            return $fail($roomNumber === ''
                ? 'Choose a room.'
                : 'Room ' . $roomNumber . ' is not free for those dates any more. Pick another room.');
        }

        if ($persons < 1 || $persons > 20) {
            return $fail('Enter how many guests are staying (1–20).');
        }
        if ($match['max_persons'] > 0 && $persons > $match['max_persons']) {
            return $fail('Room ' . $roomNumber . ' holds up to ' . $match['max_persons'] . ' guests.');
        }

        return [
            'ok'    => true,
            'error' => '',
            'stay'  => [
                'guest_name'       => $guestName,
                'guest_phone'      => $guestPhone,
                'guest_email'      => $guestEmail,
                'room_type'        => $roomType,
                'room_number'      => $roomNumber,
                'persons'          => $persons,
                'booking_type'     => $stayType,
                'check_in'         => $checkIn,
                'check_out'        => $checkOut,
                'check_in_time'    => $inTime,
                'check_out_time'   => $outTime,
                'hours_requested'  => $hours,
                'special_requests' => $special,
            ],
        ];
    }
}

if (!function_exists('marle_walkin_create')) {
    /**
     * Write the walk-in as a pending booking whose cash deposit is already
     * verified, then email the guest and the hotel inbox — the same two
     * messages a website booking produces — and tell the desk.
     *
     * The manager still approves it, which is what assigns the room's calendar
     * event, so this never approves a booking behind anyone's back.
     *
     * Order matters: the booking is committed first and the mail is sent after,
     * so a slow or failing provider can never cost the desk a booking it has
     * already taken money for. The message says which of the two happened.
     *
     * @param array<string,mixed> $input $_POST
     * @return array{ok:bool, error:string, id:int, message:string, email_status:string, guest_email:string, receipt_status:string}
     */
    function marle_walkin_create(mysqli $conn, array $input, string $username): array
    {
        $checked = marle_walkin_validate($conn, $input);
        if (!$checked['ok']) {
            return [
                'ok' => false, 'error' => $checked['error'], 'id' => 0, 'message' => '',
                'email_status' => 'none', 'guest_email' => '', 'receipt_status' => 'none',
            ];
        }
        $stay   = $checked['stay'];
        $quote  = marle_walkin_quote($conn, $stay);
        $when   = marle_db_now($conn);
        $token  = bin2hex(random_bytes(32));

        // Same per-room advisory lock the public form uses: the availability
        // check above and the INSERT below are separate statements, so two desks
        // (or a desk and a guest) could otherwise both pass the check.
        $lockName = 'marlehotel:booking:room:' . $stay['room_number'];
        $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 10)');
        $lockStmt->bind_param('s', $lockName);
        $lockStmt->execute();
        $lockRow = $lockStmt->get_result()->fetch_row();
        $lockStmt->close();
        if (!$lockRow || (int)$lockRow[0] !== 1) {
            return [
                'ok'      => false,
                'error'   => 'That room is being booked right now. Try again in a few seconds.',
                'id'      => 0,
                'message' => '',
                'email_status' => 'none',
                'guest_email'  => '',
            ];
        }
        register_shutdown_function(static function () use ($conn, $lockName): void {
            try {
                $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
                $release->bind_param('s', $lockName);
                $release->execute();
                $release->close();
            } catch (Throwable $e) {
                // The lock goes with the connection anyway.
            }
        });

        // Re-check under the lock: the form may have been open for a while.
        $stillFree = false;
        foreach (marle_walkin_available_rooms(
            $conn,
            $stay['room_type'],
            $stay['check_in'],
            $stay['check_out'],
            $stay['booking_type'],
            $stay['check_in_time'],
            $stay['check_out_time']
        ) as $room) {
            if ($room['room_number'] === $stay['room_number']) {
                $stillFree = true;
                break;
            }
        }
        if (!$stillFree) {
            return [
                'ok'      => false,
                'error'   => 'Room ' . $stay['room_number'] . ' was taken while this form was open. Pick another room.',
                'id'      => 0,
                'message' => '',
                'email_status' => 'none',
                'guest_email'  => '',
            ];
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "INSERT INTO bookings
                    (guest_name, guest_email, status_token, guest_phone, room_type, room_number,
                     persons, check_in, check_out, booking_type, check_in_time, check_out_time,
                     hours_requested, special_requests, deposit_rate, deposit_amount,
                     deposit_status, payment_method, deposit_verified_by, deposit_verified_at,
                     status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'verified', 'cash', ?, ?, 'pending')"
            );
            $stmt->bind_param(
                'ssssssissssssiddss',
                $stay['guest_name'],
                $stay['guest_email'],
                $token,
                $stay['guest_phone'],
                $stay['room_type'],
                $stay['room_number'],
                $stay['persons'],
                $stay['check_in'],
                $stay['check_out'],
                $stay['booking_type'],
                $stay['check_in_time'],
                $stay['check_out_time'],
                $stay['hours_requested'],
                $stay['special_requests'],
                $quote['deposit_rate'],
                $quote['deposit'],
                $username,
                $when
            );
            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->error);
            }
            $bookingId = (int)$stmt->insert_id;
            $stmt->close();

            $label = $stay['booking_type'] === 'hourly'
                ? $stay['check_in'] . ' ' . substr($stay['check_in_time'], 0, 5)
                  . '–' . substr($stay['check_out_time'], 0, 5) . ' (' . $stay['hours_requested'] . 'h)'
                : $stay['check_in'] . ' → ' . $stay['check_out'];
            $notifMsg = 'Walk-in (cash) — ' . $stay['guest_name'] . ', Room ' . $stay['room_number'] . ', ' . $label;

            $notif = $conn->prepare(
                "INSERT INTO notifications (type, reference_id, room_number, message)
                 VALUES ('booking', ?, ?, ?)"
            );
            $notif->bind_param('iss', $bookingId, $stay['room_number'], $notifMsg);
            $notif->execute();
            $notif->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('marle_walkin_create: ' . $e->getMessage());
            return [
                'ok'      => false,
                'error'   => 'The booking could not be saved. Nothing was recorded — please try again.',
                'id'      => 0,
                'message' => '',
                'email_status' => 'none',
                'guest_email'  => '',
            ];
        }

        require_once __DIR__ . '/log_action.php';
        log_event(
            $conn,
            'booking_walkin_created',
            'booking',
            $bookingId,
            'success',
            'Cash walk-in — ' . $stay['guest_name'] . ', Room ' . $stay['room_number']
                . ', deposit ' . marle_peso($quote['deposit']) . ' verified by ' . $username
        );

        // Tell the guest and the hotel inbox. Never fatal: the booking and the
        // cash are already saved, so the worst case is a sentence the clerk can
        // act on. Only the guest path depends on an address being given.
        $emailStatus   = $stay['guest_email'] === '' ? 'none' : 'failed';
        $emailNote     = 'No email address was given, so no email was sent.';
        $receiptStatus = 'none';
        try {
            $notified = marle_notify_walkin_created($conn, $bookingId, $username);
            if ($stay['guest_email'] !== '') {
                $emailStatus   = $notified['guest'] ? 'sent' : 'failed';
                $receiptStatus = !empty($notified['receipt']) ? 'attached' : 'failed';
                $emailNote = $notified['guest']
                    ? 'A confirmation email is on its way to ' . $stay['guest_email'] . '.'
                    : 'The booking is saved, but the email to ' . $stay['guest_email']
                      . ' could not be sent — tell the guest the reference number.';
                // The desk is the one who has to explain a missing receipt, so it
                // is told here rather than being left to discover it.
                $emailNote .= $receiptStatus === 'attached'
                    ? ' The printable cash receipt is attached to it.'
                    : ' The receipt could not be built, so the email went without it.';
            }
        } catch (Throwable $e) {
            error_log('marle_walkin_create: notification failed — ' . $e->getMessage());
            if ($stay['guest_email'] !== '') {
                $emailNote = 'The booking is saved, but the email to ' . $stay['guest_email']
                           . ' could not be sent — tell the guest the reference number.';
            }
        }

        return [
            'ok'      => true,
            'error'   => '',
            'id'      => $bookingId,
            'message' => 'Walk-in recorded with ' . marle_peso($quote['deposit']) . ' cash deposit. '
                       . $emailNote . ' '
                       . 'Approve it in Requests → Bookings to put it on the calendar.',
            'email_status'   => $emailStatus,
            'guest_email'    => $stay['guest_email'],
            'receipt_status' => $receiptStatus,
        ];
    }
}
