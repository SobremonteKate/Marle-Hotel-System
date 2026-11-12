<?php
/**
 * booking_cancel.php — cancelling a booking, in one place.
 *
 * Every door leads to the same room: the guest's booking-status page
 * (homepage/booking_status.php), the Cancel-a-booking modal on the homepage
 * (homepage/cancel_booking.php) and the front desk's own Cancel-booking button
 * on Requests → Bookings (process_cancel_booking.php). All of them must
 * forfeit the same amount, write the same audit row and send the same emails,
 * so the whole transaction lives here and no page re-implements it.
 *
 * Policy (unchanged since phase16): a cancelled booking forfeits
 * MARLE_CANCELLATION_FORFEIT_RATE (50%) of the booking total, the amount kept
 * is recorded on the row, and the guest's calendar event is removed. Nothing
 * is automatic about refunds — the front desk settles the balance.
 */

require_once __DIR__ . '/booking_deposit.php';
require_once __DIR__ . '/booking_pricing.php'; // marle_booking_total()
require_once __DIR__ . '/booking_notifications.php';
require_once __DIR__ . '/log_action.php';

if (!function_exists('marle_guest_booking_columns')) {
    /**
     * The columns every guest-facing booking screen needs, in one place: the
     * status page, its listing, and the cancel modal's lookup all select this
     * list, so a new column is added once.
     */
    function marle_guest_booking_columns(): string
    {
        return 'b.id, b.guest_name, b.guest_email, b.guest_phone, b.room_type, b.room_number, b.persons,
    b.check_in, b.check_out, b.booking_type, b.check_in_time, b.check_out_time, b.hours_requested,
    b.status, b.denial_reason, b.cancellation_reason, b.created_at, b.status_token,
    b.deposit_rate, b.deposit_amount, b.deposit_status, b.deposit_reference, b.deposit_proof_path,
    b.deposit_submitted_at, b.deposit_verified_at, b.deposit_rejection_reason,
    b.cancellation_fee_amount, b.cancellation_policy, b.calendar_event_id,
    (SELECT GROUP_CONCAT(ba.amenity_title_snapshot ORDER BY ba.id SEPARATOR ", ")
     FROM booking_addons ba WHERE ba.booking_id = b.id) AS addon_titles';
    }
}

if (!function_exists('marle_load_guest_booking')) {
    /**
     * Load one booking that belongs to this email + private status token. A
     * token only ever opens its own booking, so this is the ownership check.
     *
     * @param int $bookingId narrow to one booking (0 = the latest for this token)
     */
    function marle_load_guest_booking(mysqli $conn, string $email, string $token, int $bookingId = 0): ?array
    {
        $sql    = 'SELECT ' . marle_guest_booking_columns() . '
                   FROM bookings b WHERE b.guest_email = ? AND b.status_token = ?';
        $types  = 'ss';
        $params = [$email, $token];

        if ($bookingId > 0) {
            $sql   .= ' AND b.id = ?';
            $types .= 'i';
            $params[] = $bookingId;
        }
        $sql .= ' ORDER BY b.id DESC LIMIT 1';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('marle_cancellation_notice_hours')) {
    /** Whole hours of notice before the stay, as recorded on the cancelled row. */
    function marle_cancellation_notice_hours(array $booking): int
    {
        $checkIn = trim((string)($booking['check_in'] ?? ''));
        if ($checkIn === '') {
            return 0;
        }
        return max(0, (int)floor((strtotime($checkIn . ' 14:00:00') - time()) / 3600));
    }
}

if (!function_exists('marle_cancellation_preview')) {
    /**
     * What cancelling would cost, for a confirmation screen. Read-only: this is
     * the number both the status page and the homepage modal show BEFORE the
     * guest commits, and the same number the cancel itself will record.
     *
     * @return array{total:float, fee:float, deposit:float, balance:float,
     *               notice_hours:int, policy:string, percent:int}
     */
    function marle_cancellation_preview(array $booking): array
    {
        $total   = marle_booking_total($booking);
        $fee     = marle_cancellation_fee($total);
        $deposit = (float)($booking['deposit_amount'] ?? 0);

        return [
            'total'        => $total,
            'fee'          => $fee,
            'deposit'      => $deposit,
            'balance'      => max(0, round($fee - $deposit, 2)),
            'notice_hours' => marle_cancellation_notice_hours($booking),
            'policy'       => (int)MARLE_CANCELLATION_FORFEIT_RATE . '% of the booking total is non-refundable',
            'percent'      => (int)MARLE_CANCELLATION_FORFEIT_RATE,
        ];
    }
}

if (!function_exists('marle_cancel_booking')) {
    /**
     * Cancel a pending/approved booking and record the forfeiture.
     *
     * Idempotent by construction: the UPDATE re-checks
     * `status IN ('pending','approved')`, so a second cancel (or a cancel that
     * races the expiry sweep) changes nothing and reports back instead.
     *
     * @param array  $booking       bookings row (needs id, status, check_in, deposit_amount, calendar_event_id)
     * @param string $reasonChoice  short reason code, e.g. "change of plans"
     * @param string $detail        optional free text from the guest
     * @param string $cancelledBy   who did it: 'guest', 'system', or 'staff:<username>'
     * @param string $actorLabel    how the audit row names that actor, e.g. 'Staff (admin)'.
     *                              Defaults to ucfirst($cancelledBy).
     * @param string $reasonPrefix  how the *guest-facing* note starts its reason,
     *                              e.g. 'Cancelled by the front desk'. Defaults to
     *                              "<label> cancelled". Needed because the audit
     *                              trail may name the staff account while the guest
     *                              note must never leak it.
     * @return array{ok:bool, error:string, message:string, fee:float, total:float,
     *               notice_hours:int, balance:float, notified:bool}
     */
    function marle_cancel_booking(
        mysqli $conn,
        array $booking,
        string $reasonChoice = 'not specified',
        string $detail = '',
        string $cancelledBy = 'guest',
        string $actorLabel = '',
        string $reasonPrefix = ''
    ): array {
        $bookingId = (int)($booking['id'] ?? 0);
        $failure = static fn(string $message): array => [
            'ok'           => false,
            'error'        => $message,
            'message'      => '',
            'fee'          => 0.0,
            'total'        => 0.0,
            'notice_hours' => 0,
            'balance'      => 0.0,
            'notified'     => false,
        ];

        if ($bookingId <= 0) {
            return $failure('This booking cannot be cancelled.');
        }
        if (!in_array((string)($booking['status'] ?? ''), ['pending', 'approved'], true)) {
            return $failure('This booking cannot be cancelled.');
        }

        $canCancel = marle_booking_can_be_cancelled($booking, $cancelledBy === 'guest');
        if (!$canCancel['allowed']) {
            return $failure($canCancel['reason']);
        }

        $preview = marle_cancellation_preview($booking);
        $total   = $preview['total'];
        $fee     = $preview['fee'];
        $notice  = $preview['notice_hours'];

        // Two different audiences, two different strings: the audit trail may
        // name the account that acted, the wording the guest reads may not.
        $actorLabel   = $actorLabel !== '' ? $actorLabel : ucfirst($cancelledBy);
        $reasonPrefix = $reasonPrefix !== '' ? $reasonPrefix : $actorLabel . ' cancelled';

        $reason = $reasonPrefix . ': ' . ($reasonChoice !== '' ? $reasonChoice : 'not specified');
        $detail = trim($detail);
        if ($detail !== '') {
            $reason .= ' — ' . mb_substr($detail, 0, 160);
        }

        $conn->begin_transaction();
        try {
            $update = $conn->prepare(
                "UPDATE bookings
                    SET status = 'cancelled',
                        cancelled_at = NOW(),
                        cancelled_by = ?,
                        cancellation_reason = ?,
                        cancellation_fee_amount = ?,
                        cancellation_notice_hours = ?,
                        cancellation_policy = ?,
                        updated_at = NOW()
                  WHERE id = ? AND status IN ('pending', 'approved')"
            );
            // types: by(s) reason(s) fee(d) notice(i) policy(s) bookingId(i)
            $update->bind_param('ssdisi', $cancelledBy, $reason, $fee, $notice, $preview['policy'], $bookingId);
            $update->execute();
            $changed = $update->affected_rows;
            $update->close();

            if ($changed < 1) {
                throw new RuntimeException('This booking can no longer be cancelled.');
            }

            if (!empty($booking['calendar_event_id'])) {
                $deleteEvent = $conn->prepare('DELETE FROM calendartbl WHERE id = ?');
                $deleteEvent->bind_param('i', $booking['calendar_event_id']);
                $deleteEvent->execute();
                $deleteEvent->close();
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            return $failure($e->getMessage());
        }

        $message = 'Booking #' . $bookingId . ' cancelled. Under the '
            . (int)MARLE_CANCELLATION_FORFEIT_RATE . '% cancellation policy, '
            . marle_peso($fee) . ' of the ' . marle_peso($total) . ' booking total is retained'
            . ($preview['balance'] > 0
                ? ' — a balance of ' . marle_peso($preview['balance']) . ' remains payable at the front desk.'
                : ' — your reservation deposit covers it, so nothing further is owed.')
            . ' Call 0917 000 0000 if you would like to rebook.';

        // THE MONEY IS ALREADY COMMITTED AT THIS POINT. A mail server that is
        // unreachable, a disabled cURL function or a missing table in the bell
        // must never turn a completed cancellation into a 500 — the desk would
        // see "could not reach the server", retry, and be told the booking can no
        // longer be cancelled. The cancellation stands; the notification is
        // reported as failed instead.
        $notified = true;
        try {
            marle_notify_booking_cancelled($conn, $bookingId);
        } catch (Throwable $e) {
            $notified = false;
            error_log('booking_cancel: booking #' . $bookingId . ' was cancelled but the notification failed — '
                . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }

        log_event(
            $conn,
            'booking_cancelled',
            'booking',
            $bookingId,
            'success',
            $actorLabel . ' cancelled · ' . marle_peso($fee) . ' retained ('
                . (int)MARLE_CANCELLATION_FORFEIT_RATE . '% of ' . marle_peso($total) . ')'
                . ($notified ? '' : ' · notification FAILED')
        );

        return [
            'ok'           => true,
            'error'        => '',
            'message'      => $message,
            'fee'          => $fee,
            'total'        => $total,
            'notice_hours' => $notice,
            'balance'      => $preview['balance'],
            'notified'     => $notified,
        ];
    }
}
