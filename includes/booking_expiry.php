<?php
/**
 * booking_expiry.php — release bookings whose reservation deposit never came.
 *
 * An unpaid booking sits in `pending` and, by design, **blocks its room** for
 * everyone else (submit_booking.php treats pending like approved when it looks
 * for conflicts). Left alone, one guest who changes their mind could hold a room
 * forever. So a booking is only held for `deposit_hold_hours` (Settings →
 * Reservation Deposit, default 24) after the guest's own last action; then the
 * sweep here cancels it as the hotel, frees the room and tells the guest.
 *
 * Nothing is ever charged on a release: no deposit was verified, so the booking
 * carries no fee and the cancellation-policy columns stay NULL (that is the
 * difference between this and a guest cancellation).
 *
 * Run by admin/cron_expire_deposits.php (CLI or the secret-gated ?key= HTTP
 * trigger). Idempotent: a row is only ever touched while it is still `pending`
 * with an unpaid/rejected deposit, so the update itself is the lock.
 */

require_once __DIR__ . '/booking_deposit.php';
require_once __DIR__ . '/booking_notifications.php';
require_once __DIR__ . '/log_action.php';

if (!function_exists('marle_expired_booking_reason')) {
    /** The wording the guest sees on their status page and in the email. */
    function marle_expired_booking_reason(int $hours): string
    {
        return 'Reservation deposit not received within ' . marle_deposit_hold_label($hours)
             . ' — the booking was released automatically and nothing was charged';
    }
}

if (!function_exists('marle_find_expired_unpaid_bookings')) {
    /**
     * The bookings the sweep would release right now (read-only).
     *
     * A booking only qualifies when it is still `pending` **and** has not been
     * paid for: `unpaid` (never uploaded), `rejected` (uploaded, turned down and
     * never fixed) or NULL (a row from before the deposit columns existed — an
     * ancient unpaid hold is exactly what this sweep is for). A booking whose
     * proof is `for_verification` is deliberately left alone: the guest did their
     * part and the clock is now the front desk's.
     *
     * @return array{hours:int, rows:array<int,array<string,mixed>>}
     */
    function marle_find_expired_unpaid_bookings(mysqli $conn, ?int $onlyBookingId = null, ?int $hours = null): array
    {
        $hours = $hours ?? marle_deposit_hold_hours($conn);
        $hours = max(MARLE_DEPOSIT_HOLD_HOURS_MIN, min(MARLE_DEPOSIT_HOLD_HOURS_MAX, $hours));

        $sql = "SELECT id, guest_name, guest_email, room_number, room_type, booking_type,
                       check_in, check_out, created_at, deposit_status, deposit_submitted_at,
                       COALESCE(deposit_submitted_at, created_at) AS hold_started_at
                FROM bookings
                WHERE status = 'pending'
                  AND (deposit_status IN ('unpaid', 'rejected') OR deposit_status IS NULL)
                  AND COALESCE(deposit_submitted_at, created_at) <= NOW() - INTERVAL ? HOUR";
        if ($onlyBookingId !== null) {
            $sql .= ' AND id = ?';
        }
        $sql .= ' ORDER BY id';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['hours' => $hours, 'rows' => []];
        }

        if ($onlyBookingId !== null) {
            $stmt->bind_param('ii', $hours, $onlyBookingId);
        } else {
            $stmt->bind_param('i', $hours);
        }
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return ['hours' => $hours, 'rows' => $rows];
    }
}

if (!function_exists('marle_expire_unpaid_bookings')) {
    /**
     * Run the release sweep.
     *
     * @param int|null $onlyBookingId release just this one (used by the test and
     *                                by a staff one-off run) instead of everything
     * @param int|null $hours         override the configured hold window
     * @param bool     $dryRun        report what would be released, change nothing
     * @return array{hours:int,released:array<int,int>,skipped:array<int,int>,dry_run:bool}
     */
    function marle_expire_unpaid_bookings(
        mysqli $conn,
        ?int $onlyBookingId = null,
        ?int $hours = null,
        bool $dryRun = false
    ): array {
        $found  = marle_find_expired_unpaid_bookings($conn, $onlyBookingId, $hours);
        $hours  = $found['hours'];
        $result = ['hours' => $hours, 'released' => [], 'skipped' => [], 'dry_run' => $dryRun];

        if ($dryRun) {
            foreach ($found['rows'] as $row) {
                $result['released'][] = (int)$row['id'];
            }
            return $result;
        }

        $reason = marle_expired_booking_reason($hours);

        $update = $conn->prepare(
            "UPDATE bookings
                SET status = 'cancelled',
                    cancelled_at = NOW(),
                    cancelled_by = 'system',
                    cancellation_reason = ?,
                    cancellation_fee_amount = NULL,
                    cancellation_notice_hours = NULL,
                    cancellation_policy = NULL,
                    updated_at = NOW()
              WHERE id = ?
                AND status = 'pending'
                AND (deposit_status IN ('unpaid', 'rejected') OR deposit_status IS NULL)"
        );
        if (!$update) {
            return $result;
        }

        foreach ($found['rows'] as $row) {
            $bookingId = (int)$row['id'];

            // The WHERE clause re-checks the state, so a booking a manager just
            // verified (or approved) between the SELECT and here cannot be
            // cancelled out from under them.
            $update->bind_param('si', $reason, $bookingId);
            $update->execute();
            $changed = $update->affected_rows;
            if ($changed < 1) {
                $result['skipped'][] = $bookingId;
                continue;
            }

            $result['released'][] = $bookingId;

            log_event(
                $conn,
                'booking_expired',
                'booking',
                $bookingId,
                'success',
                'Room ' . (string)($row['room_number'] ?? '?') . ' — ' . (string)$row['guest_name']
                    . ' · deposit not received within ' . marle_deposit_hold_label($hours) . ' · released, nothing charged'
            );

            // Best effort: the release is already committed and a mail failure
            // must never undo it (marle_send_email never throws).
            marle_notify_booking_expired($conn, $bookingId, $hours);
        }
        $update->close();

        return $result;
    }
}
