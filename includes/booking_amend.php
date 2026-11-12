<?php
/**
 * booking_amend.php — changing a booking that is already held, in one place.
 *
 * The front desk can move a held booking's dates/room, or correct the guest
 * count, from Requests → Bookings. That is a *money* change: the total is
 * recomputed from the new dates, which moves the reservation deposit and the
 * cancellation forfeiture with it. All of it lives here so the modal's re-quote
 * and the write itself can never disagree about the numbers.
 *
 * Nothing is written until the staff member has seen the re-quote and confirmed
 * — the endpoint runs `marle_booking_amend_quote()` first, every time, and the
 * write re-checks availability under an advisory lock so two desks cannot both
 * move a booking onto the same room.
 *
 * Scope: the booking TYPE and the ROOM TYPE are fixed (a guest who wants a
 * different room type is a new booking). What can change is the stay window,
 * the room number and the number of guests.
 */

require_once __DIR__ . '/booking_deposit.php';       // marle_peso(), marle_deposit_amount(), marle_booking_can_be_cancelled()
require_once __DIR__ . '/booking_pricing.php';       // marle_booking_total()
require_once __DIR__ . '/booking_notifications.php'; // marle_fetch_booking(), marle_booking_when_text(), emails
require_once __DIR__ . '/log_action.php';
require_once __DIR__ . '/server_clock.php';       // the hotel's date, not PHP's (see below)

if (!function_exists('marle_booking_amend_guard')) {
    /**
     * May this booking be changed at all? Same shape as the cancel rule — the
     * booking must still be open and its check-in must not have arrived — but
     * with wording that points the front desk at the checkout screen.
     *
     * @return array{allowed:bool, reason:string}
     */
    function marle_booking_amend_guard(array $booking, bool $forGuest = false): array
    {
        $status = (string)($booking['status'] ?? '');
        if (!in_array($status, ['pending', 'approved'], true)) {
            return [
                'allowed' => false,
                'reason'  => 'This booking is already ' . $status
                           . ', so there is nothing to change here.',
            ];
        }

        return marle_booking_can_be_cancelled($booking, $forGuest);
    }
}

if (!function_exists('marle_booking_normalize_time')) {
    /** 'H:MM' / 'HH:MM' / 'HH:MM:SS' → 'HH:MM:SS', or '' when unparseable. */
    function marle_booking_normalize_time(?string $raw): string
    {
        $raw = trim((string)$raw);
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $raw)) {
            return '';
        }
        if (strlen($raw) === 5) {
            $raw .= ':00';
        }
        $parts = explode(':', $raw);
        return sprintf('%02d:%02d:%02d', (int)$parts[0], (int)$parts[1], (int)$parts[2]);
    }
}

if (!function_exists('marle_booking_proposed_interval')) {
    /**
     * The half-open interval [start, end) a proposed stay occupies, as MySQL
     * datetimes. Daily stays run midnight→midnight so a checkout and a new
     * checkin on the same day do not collide; hourly stays run their clock.
     *
     * @return array{start:string, end:string}
     */
    function marle_booking_proposed_interval(array $proposal): array
    {
        if (($proposal['booking_type'] ?? 'daily') === 'hourly') {
            return [
                'start' => $proposal['check_in'] . ' ' . $proposal['check_in_time'],
                'end'   => $proposal['check_in'] . ' ' . $proposal['check_out_time'],
            ];
        }
        return [
            'start' => $proposal['check_in'] . ' 00:00:00',
            'end'   => $proposal['check_out'] . ' 00:00:00',
        ];
    }
}

if (!function_exists('marle_booking_proposal')) {
    /**
     * Normalise the raw form values into the exact shape both the re-quote and
     * the write use. Returns every problem with the proposed stay in `errors`
     * rather than throwing, so the modal can show them one by one.
     *
     * @param array $booking  the current bookings row
     * @param array $input    raw posted values (check_in, check_out, room_number,
     *                        persons, check_in_time, hours_requested)
     * @return array<string,mixed>{ok:bool, errors:string[], ...}
     */
    function marle_booking_proposal(mysqli $conn, array $booking, array $input): array
    {
        $bookingType = (($booking['booking_type'] ?? 'daily') === 'hourly') ? 'hourly' : 'daily';
        $roomType    = (string)($booking['room_type'] ?? '');
        $errors      = [];

        $checkIn  = trim((string)($input['check_in'] ?? ''));
        $checkOut = trim((string)($input['check_out'] ?? ''));
        $roomNo   = trim((string)($input['room_number'] ?? ''));
        $persons  = (int)($input['persons'] ?? 0);

        $inTime   = '';
        $outTime  = '';
        $hours    = 0;

        // The hotel's calendar day, not PHP's — the same rule the public booking gate
        // applies (homepage/submit_booking.php), and on this box PHP runs Europe/Berlin
        // while MySQL runs UTC+8, so between midnight and 06:00 a PHP-date check would
        // let a room be amended onto a day the hotel had already finished with.
        $today = marle_hotel_date(0);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn)) {
            $errors[] = 'Enter a valid check-in date.';
        } elseif ($checkIn < $today) {
            $errors[] = 'The check-in date cannot be in the past.';
        }

        if ($bookingType === 'hourly') {
            $checkOut = $checkIn;
            $inTime   = marle_booking_normalize_time($input['check_in_time'] ?? '');
            $hours    = (int)($input['hours_requested'] ?? 0);

            if ($inTime === '') {
                $errors[] = 'Enter a valid check-in time.';
            }
            if ($hours < 1 || $hours > 23) {
                $errors[] = 'An hourly stay lasts between 1 and 23 hours.';
            }
            if ($inTime !== '' && $hours >= 1 && $hours <= 23) {
                $outTs    = strtotime($checkIn . ' ' . $inTime) + ($hours * 3600);
                $outTime  = date('H:i:s', $outTs);
                if ($outTime <= $inTime) {
                    $errors[] = 'The stay cannot run past midnight — shorten it or move the start time earlier.';
                }
            }
        } else {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut)) {
                $errors[] = 'Enter a valid check-out date.';
            } elseif ($checkIn !== '' && strtotime($checkOut) <= strtotime($checkIn)) {
                $errors[] = 'Check-out must be after check-in.';
            }
        }

        if ($persons < 1 || $persons > 20) {
            $errors[] = 'Enter a guest count between 1 and 20.';
        }

        if ($roomNo === '') {
            $errors[] = 'Choose a room.';
        } elseif (!marle_booking_room_is_valid_for_type($conn, $roomType, $roomNo)) {
            // The room must actually belong to this booking's room type.
            $errors[] = 'Room ' . $roomNo . ' is not a ' . $roomType . ' room.';
        }

        return [
            'ok'              => empty($errors),
            'errors'          => $errors,
            'booking_type'    => $bookingType,
            'room_type'       => $roomType,
            'check_in'        => $checkIn,
            'check_out'       => $checkOut,
            'check_in_time'   => $inTime !== '' ? $inTime : null,
            'check_out_time'  => $outTime !== '' ? $outTime : null,
            'hours_requested' => $hours,
            'room_number'     => $roomNo,
            'persons'         => $persons,
        ];
    }
}

if (!function_exists('marle_booking_room_numbers_for_type')) {
    /**
     * Every room number that exists for a room type — the candidate list the
     * amend modal offers. Mirrors the guest booking form: "Without Garage"
     * rooms 4–16 are not all in roomstbl.
     *
     * @return array<int,array{room_number:string, max_persons:?int, rate_per_hour:?float}>
     */
    function marle_booking_room_numbers_for_type(mysqli $conn, string $roomType): array
    {
        $rooms = [];

        if ($roomType === 'Without Garage') {
            $max = null;
            $mpStmt = $conn->prepare("SELECT max_persons FROM roomstbl WHERE rooms = 'Without Garage' LIMIT 1");
            $mpStmt->execute();
            $mpRow = $mpStmt->get_result()->fetch_assoc();
            $mpStmt->close();
            if ($mpRow) {
                $max = (int)$mpRow['max_persons'];
            }
            for ($i = 4; $i <= 16; $i++) {
                $rooms[] = ['room_number' => (string)$i, 'max_persons' => $max, 'rate_per_hour' => null];
            }
            return $rooms;
        }

        $stmt = $conn->prepare(
            "SELECT room_number, max_persons, rate_per_hour
               FROM roomstbl
              WHERE rooms = ? AND room_number IS NOT NULL AND room_number <> ''
              ORDER BY CAST(room_number AS UNSIGNED)"
        );
        $stmt->bind_param('s', $roomType);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rooms[] = [
                'room_number'   => (string)$row['room_number'],
                'max_persons'   => $row['max_persons'] !== null ? (int)$row['max_persons'] : null,
                'rate_per_hour' => $row['rate_per_hour'] !== null ? (float)$row['rate_per_hour'] : null,
            ];
        }
        $stmt->close();

        return $rooms;
    }
}

if (!function_exists('marle_booking_room_is_valid_for_type')) {
    /** Does this room number belong to this room type? */
    function marle_booking_room_is_valid_for_type(mysqli $conn, string $roomType, string $roomNumber): bool
    {
        if ($roomNumber === '') {
            return false;
        }
        foreach (marle_booking_room_numbers_for_type($conn, $roomType) as $room) {
            if ($room['room_number'] === $roomNumber) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('marle_booking_conflicting_rooms')) {
    /**
     * Every room number already taken over a proposed window, for the whole
     * hotel: open bookings (pending *and* approved — a pending hold blocks the
     * room, exactly as submit_booking.php treats it) plus guests currently
     * checked in.
     *
     * The booking under amendment is excluded, so a booking can keep its own
     * room and dates.
     *
     * @param int $excludeId booking id to ignore (0 for none)
     * @return array<int,string>
     */
    function marle_booking_conflicting_rooms(
        mysqli $conn,
        string $start,
        string $end,
        int $excludeId = 0
    ): array {
        $rooms = [];

        // One interval per booking, whichever type it is, so daily and hourly
        // stays are compared on the same axis. Daily = [check_in, check_out).
        $sql = "SELECT DISTINCT room_number FROM bookings
                 WHERE status IN ('pending', 'approved')
                   AND room_number IS NOT NULL AND room_number <> ''
                   AND id <> ?
                   AND CASE WHEN booking_type = 'hourly'
                            THEN STR_TO_DATE(CONCAT(check_in, ' ', COALESCE(check_in_time, '00:00:00')), '%Y-%m-%d %H:%i:%s')
                            ELSE STR_TO_DATE(check_in, '%Y-%m-%d') END < ?
                   AND CASE WHEN booking_type = 'hourly'
                            THEN STR_TO_DATE(CONCAT(check_in, ' ', COALESCE(check_out_time, '00:00:00')), '%Y-%m-%d %H:%i:%s')
                            ELSE STR_TO_DATE(check_out, '%Y-%m-%d') END > ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('iss', $excludeId, $end, $start);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rooms[] = (string)$row['room_number'];
            }
            $stmt->close();
        }

        // A guest currently checked in occupies the room across their dates.
        $custStart = substr($start, 0, 10);
        $custEnd   = substr($end, 0, 10);
        $cust = $conn->prepare(
            "SELECT room_no FROM customerstbl
              WHERE status = 'checked-in' AND room_no IS NOT NULL AND room_no <> ''
                AND DATE(date_from) < ? AND DATE(date_to) > ?"
        );
        if ($cust) {
            $cust->bind_param('ss', $end, $start);
            $cust->execute();
            $res = $cust->get_result();
            while ($row = $res->fetch_assoc()) {
                $rooms[] = (string)$row['room_no'];
            }
            $cust->close();
        }

        return array_values(array_unique($rooms));
    }
}

if (!function_exists('marle_booking_available_rooms')) {
    /**
     * The rooms the proposal can actually use: the room type's rooms minus
     * everything already taken over the proposed window.
     *
     * @return array<int,array{room_number:string, max_persons:?int, rate_per_hour:?float}>
     */
    function marle_booking_available_rooms(
        mysqli $conn,
        string $roomType,
        string $start,
        string $end,
        int $excludeId = 0
    ): array {
        $taken = marle_booking_conflicting_rooms($conn, $start, $end, $excludeId);

        $available = [];
        foreach (marle_booking_room_numbers_for_type($conn, $roomType) as $room) {
            if (!in_array($room['room_number'], $taken, true)) {
                $available[] = $room;
            }
        }
        return $available;
    }
}

if (!function_exists('marle_booking_amend_quote')) {
    /**
     * The re-quote: what the booking costs now versus under the proposal, what
     * that does to the reservation deposit and the cancellation forfeiture, and
     * whether the room is actually free.
     *
     * Read-only. Called before every write and on every keystroke in the modal,
     * so the number the manager approves is the number the write records.
     *
     * @param array $proposal marle_booking_proposal() output
     * @return array<string,mixed>
     */
    function marle_booking_amend_quote(mysqli $conn, array $booking, array $proposal): array
    {
        $bookingId = (int)($booking['id'] ?? 0);

        $oldTotal = marle_booking_total($booking);
        $newTotal = marle_booking_total($proposal);   // reads booking_type/room_type/check_in/check_out/hours_requested

        $paid = (float)($booking['deposit_amount'] ?? 0);
        $oldDeposit = $paid > 0 ? $paid : marle_deposit_amount($conn, $oldTotal);
        $newDeposit = marle_deposit_amount($conn, $newTotal);

        $nights = 0;
        $hours  = 0;
        if (($proposal['booking_type'] ?? 'daily') === 'hourly') {
            $hours = (int)($proposal['hours_requested'] ?? 0);
        } else {
            $inTs  = strtotime((string)$proposal['check_in'] . ' 00:00:00');
            $outTs = strtotime((string)$proposal['check_out'] . ' 00:00:00');
            if ($inTs !== false && $outTs !== false && $outTs > $inTs) {
                $nights = max(1, (int)round(($outTs - $inTs) / 86400));
            }
        }

        $interval = marle_booking_proposed_interval($proposal);
        $available = [];
        $conflict  = false;
        if ($proposal['ok']) {
            $taken = marle_booking_conflicting_rooms($conn, $interval['start'], $interval['end'], $bookingId);
            $available = marle_booking_available_rooms($conn, (string)$proposal['room_type'], $interval['start'], $interval['end'], $bookingId);
            $conflict  = in_array((string)$proposal['room_number'], $taken, true);
        }

        $roomValid = $proposal['ok']
            ? marle_booking_room_is_valid_for_type($conn, (string)$proposal['room_type'], (string)$proposal['room_number'])
            : false;

        $note = '';
        if (!$proposal['ok']) {
            // errors already carry the detail
        } elseif (!$roomValid) {
            $note = 'Room ' . $proposal['room_number'] . ' is not a ' . $proposal['room_type'] . ' room.';
        } elseif ($conflict) {
            $note = 'Room ' . $proposal['room_number'] . ' is already taken for those dates.';
        }

        return [
            'booking_type'    => $proposal['booking_type'],
            'room_type'       => $proposal['room_type'],
            'check_in'        => $proposal['check_in'],
            'check_out'       => $proposal['check_out'],
            'check_in_time'   => $proposal['check_in_time'],
            'check_out_time'  => $proposal['check_out_time'],
            'hours_requested' => $proposal['hours_requested'],
            'room_number'     => $proposal['room_number'],
            'persons'         => $proposal['persons'],
            'nights'          => $nights,
            'hours'           => $hours,

            'old_total'       => $oldTotal,
            'new_total'       => $newTotal,
            'total_delta'     => round($newTotal - $oldTotal, 2),

            'old_deposit'     => $oldDeposit,
            'new_deposit'     => $newDeposit,
            'deposit_paid'    => $paid,
            // Positive: collect more at the desk. Negative: money on record
            // exceeds what the new total requires — a credit to the guest.
            'deposit_delta'   => round($newDeposit - $paid, 2),

            'old_fee'         => marle_cancellation_fee($oldTotal),
            'new_fee'         => marle_cancellation_fee($newTotal),
            'percent'         => (int)MARLE_CANCELLATION_FORFEIT_RATE,

            'deposit_status'  => (string)($booking['deposit_status'] ?? 'unpaid'),
            'available'       => ($proposal['ok'] && $roomValid && !$conflict),
            'note'            => $note,
            'errors'          => $proposal['errors'],
            'rooms'           => $available,
        ];
    }
}

if (!function_exists('marle_booking_amend')) {
    /**
     * Apply the proposal. Atomic: the availability check and the UPDATE run
     * under a per-room advisory lock (the same lock submit_booking.php takes),
     * so a booking cannot be moved onto a room that a simultaneous guest booking
     * is taking, and the UPDATE itself re-checks that the booking is still open.
     *
     * The booking's own deposit record is left alone: money already received is
     * a record of what was paid, and the re-quote reports any difference. The
     * calendar event (when the booking is approved) is moved with it.
     *
     * @param array $proposal marle_booking_proposal() output (must be ok)
     * @param array $quote    marle_booking_amend_quote() output (for the numbers)
     * @param string $actorLabel how the audit row names the actor, e.g. 'Staff (admin)'
     * @return array{ok:bool, error:string, message:string}
     */
    function marle_booking_amend(
        mysqli $conn,
        array $booking,
        array $proposal,
        array $quote,
        string $actorLabel = 'Staff'
    ): array {
        $bookingId = (int)($booking['id'] ?? 0);
        if ($bookingId <= 0) {
            return ['ok' => false, 'error' => 'This booking cannot be changed.', 'message' => ''];
        }
        if (empty($proposal['ok']) || empty($quote['available'])) {
            return [
                'ok'      => false,
                'error'   => $quote['note'] !== '' ? $quote['note']
                             : (implode(' ', (array)$proposal['errors']) ?: 'That change is not possible.'),
                'message' => '',
            ];
        }

        $guard = marle_booking_amend_guard($booking);
        if (!$guard['allowed']) {
            return ['ok' => false, 'error' => $guard['reason'], 'message' => ''];
        }

        $interval = marle_booking_proposed_interval($proposal);
        $newRoom  = (string)$proposal['room_number'];
        $oldRoom  = (string)($booking['room_number'] ?? '');

        // Serialize against guest submissions for either room involved. Take the
        // locks in a stable order so two amends swapping rooms cannot deadlock.
        $locks = array_values(array_unique([
            'marlehotel:booking:room:' . $newRoom,
            $oldRoom !== '' ? 'marlehotel:booking:room:' . $oldRoom : null,
        ]));
        $locks = array_values(array_filter($locks));
        sort($locks);

        $held = [];
        foreach ($locks as $name) {
            $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 10)');
            $lockStmt->bind_param('s', $name);
            $lockStmt->execute();
            $lockRes = $lockStmt->get_result()->fetch_row();
            $lockStmt->close();
            if (!$lockRes || (int)$lockRes[0] !== 1) {
                foreach ($held as $done) {
                    $rel = $conn->prepare('SELECT RELEASE_LOCK(?)');
                    $rel->bind_param('s', $done);
                    $rel->execute();
                    $rel->close();
                }
                return [
                    'ok'      => false,
                    'error'   => 'That room is being changed by someone else right now. Please try again in a few seconds.',
                    'message' => '',
                ];
            }
            $held[] = $name;
        }

        $conn->begin_transaction();
        try {
            // Re-check availability now that we hold the lock — the modal's
            // quote may be seconds old.
            $stillFree = marle_booking_conflicting_rooms(
                $conn, $interval['start'], $interval['end'], $bookingId
            );
            if (in_array($newRoom, $stillFree, true)) {
                throw new RuntimeException('Room ' . $newRoom . ' was taken a moment ago. Pick another room.');
            }

            if (($proposal['booking_type'] ?? 'daily') === 'hourly') {
                $update = $conn->prepare(
                    "UPDATE bookings
                        SET check_in = ?, check_out = ?, check_in_time = ?, check_out_time = ?,
                            hours_requested = ?, room_number = ?, persons = ?, updated_at = NOW()
                      WHERE id = ? AND status IN ('pending', 'approved')"
                );
                $update->bind_param(
                    'ssssisii',
                    $proposal['check_in'], $proposal['check_out'],
                    $proposal['check_in_time'], $proposal['check_out_time'],
                    $proposal['hours_requested'], $newRoom, $proposal['persons'], $bookingId
                );
            } else {
                $update = $conn->prepare(
                    "UPDATE bookings
                        SET check_in = ?, check_out = ?, room_number = ?, persons = ?, updated_at = NOW()
                      WHERE id = ? AND status IN ('pending', 'approved')"
                );
                $update->bind_param(
                    'sssii',
                    $proposal['check_in'], $proposal['check_out'],
                    $newRoom, $proposal['persons'], $bookingId
                );
            }
            $update->execute();
            $changed = $update->affected_rows;
            $update->close();

            if ($changed < 1) {
                throw new RuntimeException('This booking can no longer be changed.');
            }

            // Keep the calendar event in step when the booking is approved.
            if (!empty($booking['calendar_event_id'])) {
                $start = ($proposal['booking_type'] ?? 'daily') === 'hourly'
                    ? $proposal['check_in'] . ' ' . $proposal['check_in_time']
                    : $proposal['check_in'] . ' 14:00:00';
                $end = ($proposal['booking_type'] ?? 'daily') === 'hourly'
                    ? $proposal['check_in'] . ' ' . $proposal['check_out_time']
                    : $proposal['check_out'] . ' 12:00:00';
                $title = 'Booking: ' . (string)$booking['guest_name'] . ' (Room ' . $newRoom . ')';

                $cal = $conn->prepare('UPDATE calendartbl SET title = ?, start = ?, end = ? WHERE id = ?');
                $cal->bind_param('sssi', $title, $start, $end, $booking['calendar_event_id']);
                $cal->execute();
                $cal->close();
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            foreach ($held as $done) {
                $rel = $conn->prepare('SELECT RELEASE_LOCK(?)');
                $rel->bind_param('s', $done);
                $rel->execute();
                $rel->close();
            }
            return ['ok' => false, 'error' => $e->getMessage(), 'message' => ''];
        }

        foreach ($held as $done) {
            $rel = $conn->prepare('SELECT RELEASE_LOCK(?)');
            $rel->bind_param('s', $done);
            $rel->execute();
            $rel->close();
        }

        // Tell the guest and the bell. The fetch is post-update, so the email
        // quotes the new row.
        $fresh = marle_fetch_booking($conn, $bookingId);
        if ($fresh) {
            marle_notify_booking_amended($conn, $bookingId, [
                'old_room'   => $oldRoom,
                'old_when'   => marle_booking_when_text($booking),
                'old_total'  => (float)$quote['old_total'],
                'new_total'  => (float)$quote['new_total'],
                'deposit_delta' => (float)$quote['deposit_delta'],
                'deposit_status' => (string)($booking['deposit_status'] ?? 'unpaid'),
                'actor'      => $actorLabel,
            ]);
        }

        log_event(
            $conn,
            'booking_amended',
            'booking',
            $bookingId,
            'success',
            $actorLabel . ' changed booking: Room ' . ($oldRoom !== '' ? $oldRoom : '?') . ' → ' . $newRoom
                . ' · ' . marle_booking_when_text($booking) . ' → ' . marle_booking_when_text($proposal)
                . ' · total ' . marle_peso((float)$quote['old_total']) . ' → ' . marle_peso((float)$quote['new_total'])
        );

        $message = 'Booking #' . $bookingId . ' updated — ' . marle_booking_when_text($proposal)
            . ', Room ' . $newRoom . ', ' . (int)$proposal['persons'] . ' guest(s). '
            . 'New total ' . marle_peso((float)$quote['new_total'])
            . ' (' . ((float)$quote['total_delta'] >= 0 ? '+' : '−') . marle_peso(abs((float)$quote['total_delta'])) . ').';
        if ((float)$quote['deposit_delta'] > 0) {
            $message .= ' Collect ' . marle_peso((float)$quote['deposit_delta']) . ' more deposit.';
        } elseif ((float)$quote['deposit_delta'] < 0) {
            $message .= ' ' . marle_peso(abs((float)$quote['deposit_delta'])) . ' on record is refundable/creditable.';
        }
        $message .= ' The guest has been emailed.';

        return ['ok' => true, 'error' => '', 'message' => $message];
    }
}
