<?php
/**
 * booking_notifications.php — every booking email the hotel sends.
 *
 * Kept in one place so the guest message, the staff alert and the numbers they
 * quote are always generated from the same stored booking row.
 */

require_once __DIR__ . '/send_email.php';
require_once __DIR__ . '/booking_deposit.php';

if (!function_exists('marle_fetch_booking')) {
    /**
     * Load one booking with its add-on titles — the exact row the emails quote.
     *
     * @return array<string,mixed>|null
     */
    function marle_fetch_booking(mysqli $conn, int $bookingId): ?array
    {
        $stmt = $conn->prepare(
            'SELECT b.*,
                    (SELECT GROUP_CONCAT(ba.amenity_title_snapshot ORDER BY ba.id SEPARATOR ", ")
                     FROM booking_addons ba WHERE ba.booking_id = b.id) AS addon_titles
             FROM bookings b WHERE b.id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('marle_booking_when_text')) {
    /** Human sentence for the stay window (daily and hourly). */
    function marle_booking_when_text(array $booking): string
    {
        $checkIn  = strtotime((string)$booking['check_in']);
        $checkOut = strtotime((string)$booking['check_out']);
        $in  = $checkIn  !== false ? date('F j, Y', $checkIn)  : (string)$booking['check_in'];
        $out = $checkOut !== false ? date('F j, Y', $checkOut) : (string)$booking['check_out'];

        if (($booking['booking_type'] ?? 'daily') === 'hourly') {
            $t1 = !empty($booking['check_in_time'])  ? date('g:i A', strtotime((string)$booking['check_in_time']))  : '';
            $t2 = !empty($booking['check_out_time']) ? date('g:i A', strtotime((string)$booking['check_out_time'])) : '';
            $hours = (int)($booking['hours_requested'] ?? 0);
            return $in . ' · ' . $t1 . ' – ' . $t2 . ' (' . $hours . ' hour' . ($hours === 1 ? '' : 's') . ')';
        }

        $nights = 1;
        if ($checkIn !== false && $checkOut !== false && $checkOut > $checkIn) {
            $nights = max(1, (int)round(($checkOut - $checkIn) / 86400));
        }
        return $in . ' – ' . $out . ' (' . $nights . ' night' . ($nights === 1 ? '' : 's') . ')';
    }
}

if (!function_exists('marle_booking_room_text')) {
    function marle_booking_room_text(array $booking): string
    {
        $room = trim((string)($booking['room_number'] ?? ''));
        return (string)$booking['room_type'] . ($room !== '' ? ' — Room ' . $room : '');
    }
}

if (!function_exists('marle_notify_new_booking')) {
    /**
     * Tell the guest their request landed (with the private status link + the
     * deadline their room is held until) and tell the hotel inbox a request is
     * waiting for a deposit check.
     *
     * @return array{guest:bool, hotel:bool} provider accept/reject per address
     */
    function marle_notify_new_booking(mysqli $conn, int $bookingId): array
    {
        $booking = marle_fetch_booking($conn, $bookingId);
        if (!$booking) {
            error_log('booking_notifications: booking ' . $bookingId . ' not found for notification');
            return ['guest' => false, 'hotel' => false];
        }

        $total   = marle_booking_total($booking);
        $rate    = marle_deposit_rate($conn);
        $deposit = marle_deposit_amount($conn, $total);
        $when    = marle_booking_when_text($booking);
        $room    = marle_booking_room_text($booking);
        $guest   = (string)$booking['guest_name'];
        $token   = (string)($booking['status_token'] ?? '');

        $statusUrl = marle_absolute_url(
            'homepage/booking_status.php?email=' . rawurlencode((string)$booking['guest_email'])
            . '&status_token=' . rawurlencode($token)
        );

        // ── Guest ────────────────────────────────────────────────────────────
        $guestRows = [
            'Booking reference' => '#' . (int)$booking['id'],
            'Room'              => $room,
            'Stay'              => $when,
            'Guests'            => (int)$booking['persons'],
        ];
        if (!empty($booking['addon_titles'])) {
            $guestRows['Add-ons'] = (string)$booking['addon_titles'];
        }
        if (!empty($booking['special_requests'])) {
            $guestRows['Special requests'] = (string)$booking['special_requests'];
        }
        $guestRows['Estimated total'] = marle_peso($total);
        $guestRows['Reservation deposit (' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '% — required to confirm)'] = marle_peso($deposit);

        // The room is only held for a set window, so say so plainly — a guest
        // who oversleeps the deadline should never be surprised by it.
        $holdHours  = marle_deposit_hold_hours($conn);
        $deadlineTs = marle_deposit_deadline_ts($booking, $holdHours);
        $guestRows['Room held for'] = marle_deposit_hold_label($holdHours);
        if ($deadlineTs > 0) {
            $guestRows['Pay and upload proof by'] = date('F j, Y \\a\\t g:i A', $deadlineTs);
        }

        $guestRows['Status'] = 'Pending — awaiting deposit verification';
        $guestRows['Your private status token'] = $token;

        $guestBody = marle_email_template(
            'Booking request received',
            '#198754',
            [
                'Dear <strong>' . htmlspecialchars($guest, ENT_QUOTES, 'UTF-8') . '</strong>,',
                'Thank you for choosing Marle Hotel &amp; Restaurant. Your request is in — here is exactly what happens next.',
                '<strong>1. Pay the reservation deposit of ' . htmlspecialchars(marle_peso($deposit), ENT_QUOTES, 'UTF-8')
                    . ($deadlineTs > 0 ? ' by ' . htmlspecialchars(date('F j, Y g:i A', $deadlineTs), ENT_QUOTES, 'UTF-8') : '')
                    . '.</strong><br>'
                    . htmlspecialchars(marle_deposit_method_text($conn), ENT_QUOTES, 'UTF-8'),
                '<strong>2. Upload your proof of payment</strong> (GCash/bank receipt) on your booking status page, '
                    . 'together with the reference number. Our front desk verifies it and confirms your room.',
                '<strong>3. Keep your private status token.</strong> It is the only way to open your booking — '
                    . 'anyone with your email and this token can view or cancel the booking, so please do not share it.',
            ],
            $guestRows,
            ['label' => 'Open my booking status page', 'url' => $statusUrl],
            'Cancelling a booking forfeits ' . (int)MARLE_CANCELLATION_FORFEIT_RATE . '% of the total booking amount. '
                . 'A booking that has neither checked in nor called by 4:00 PM on the arrival day (one hour after '
                . 'the 3:00 PM check-in time) is cancelled automatically as a no-show. '
                . 'A booking whose deposit is still unpaid after ' . marle_deposit_hold_label($holdHours)
                . ' is released automatically — nothing is charged on a release.'
        );

        $guestSent = marle_send_email(
            (string)$booking['guest_email'],
            $guest,
            'Booking request received — Marle Hotel (ref #' . (int)$booking['id'] . ')',
            $guestBody
        );

        // ── Hotel inbox ──────────────────────────────────────────────────────
        $hotelRows = [
            'Reference'        => '#' . (int)$booking['id'],
            'Guest'            => $guest,
            'Email'            => (string)$booking['guest_email'],
            'Phone'            => (string)($booking['guest_phone'] ?? ''),
            'Room'             => $room,
            'Stay'             => $when,
            'Guests'           => (int)$booking['persons'],
            'Estimated total'  => marle_peso($total),
            'Deposit due'      => marle_peso($deposit),
            'Deposit status'   => 'Unpaid — waiting for the guest to upload proof',
        ];
        if (!empty($booking['addon_titles'])) {
            $hotelRows['Add-ons'] = (string)$booking['addon_titles'];
        }

        $hotelBody = marle_email_template(
            'New booking request',
            '#933310',
            [
                'A new booking request came in from the public website. It needs a deposit check before the room is confirmed.',
                'Verify the uploaded proof of payment, then approve the booking in <strong>Requests → Bookings</strong>.',
            ],
            $hotelRows,
            ['label' => 'Open Requests → Bookings', 'url' => marle_absolute_url('admin/requests.php')],
            'This alert goes to ' . htmlspecialchars(marle_email_notify_to(), ENT_QUOTES, 'UTF-8')
                . '. Change it with MARLE_EMAIL_NOTIFY_TO in config.php.'
        );

        $hotelSent = marle_send_email(
            marle_email_notify_to(),
            'Marle Hotel Front Desk',
            'New booking request #' . (int)$booking['id'] . ' — ' . $guest . ' (' . $room . ')',
            $hotelBody
        );

        return ['guest' => $guestSent, 'hotel' => $hotelSent];
    }
}

if (!function_exists('marle_notify_walkin_created')) {
    /**
     * A guest standing at the desk has just paid the reservation deposit in
     * cash: send them the same kind of confirmation a website booking gets,
     * and put the stay in the hotel inbox so it gets approved.
     *
     * WHY IT IS SEPARATE FROM marle_notify_new_booking()
     *   That email tells the guest to pay the deposit and upload a receipt.
     *   A walk-in has already done the equivalent — the money is in the drawer
     *   — so telling them to pay would be wrong, and telling them the booking
     *   "awaits deposit verification" would be worse.
     *
     *   Email is best effort on purpose: the cash is already recorded and the
     *   booking committed, so a provider outage is reported to the desk, never
     *   thrown back at it.
     *
     *   The guest's message carries a **printable PDF receipt** of the cash
     *   deposit (includes/deposit_receipt.php): the one flow where money changes
     *   hands with nothing uploaded is the one that most needs a piece of paper
     *   the guest can keep. It is attached to the guest message only — the hotel
     *   already has the row, the audit entry and the desk copy.
     *
     * @return array{guest:bool, hotel:bool, email:string, receipt:bool}
     */
    function marle_notify_walkin_created(mysqli $conn, int $bookingId, string $recordedBy = ''): array
    {
        $sent = ['guest' => false, 'hotel' => false, 'email' => '', 'receipt' => false];

        $booking = marle_fetch_booking($conn, $bookingId);
        if (!$booking) {
            error_log('booking_notifications: walk-in ' . $bookingId . ' not found for notification');
            return $sent;
        }

        $total   = marle_booking_total($booking);
        $deposit = (float)($booking['deposit_amount'] ?? 0);
        $balance = max(0, $total - $deposit);
        $guest   = (string)$booking['guest_name'];
        $room    = marle_booking_room_text($booking);
        $when    = marle_booking_when_text($booking);
        $email   = trim((string)($booking['guest_email'] ?? ''));
        $token   = trim((string)($booking['status_token'] ?? ''));
        $full    = trim((string)($booking['special_requests'] ?? ''));
        $sent['email'] = $email;

        $statusUrl = marle_absolute_url(
            'homepage/booking_status.php?email=' . rawurlencode($email)
            . '&status_token=' . rawurlencode($token)
        );

        // ── Guest ────────────────────────────────────────────────────────────
        // No address, no email: a walk-in often has none and nothing is
        // invented on their behalf.
        if ($email !== '' && $token !== '') {
            $guestRows = [
                'Booking reference' => '#' . (int)$booking['id'],
                'Guest'             => $guest,
                'Room'              => $room,
                'Stay'              => $when,
                'Guests'            => (int)$booking['persons'],
            ];
            if ($full !== '') {
                $guestRows['Special requests'] = $full;
            }
            $guestRows['Booking total']       = marle_peso($total);
            $guestRows['Deposit paid in cash'] = marle_peso($deposit);
            $guestRows['Balance due at the hotel'] = marle_peso($balance);
            $guestRows['Your private status token'] = $token;

            $guestBody = marle_email_template(
                'Your booking is recorded',
                '#198754',
                [
                    'Dear <strong>' . htmlspecialchars($guest, ENT_QUOTES, 'UTF-8') . '</strong>,',
                    'Thank you for choosing Marle Hotel &amp; Restaurant. Your reservation is recorded and your '
                        . 'deposit of <strong>' . htmlspecialchars(marle_peso($deposit), ENT_QUOTES, 'UTF-8')
                        . '</strong> has been received in cash at our front desk.',
                    'Nothing else is needed to hold the room. Please keep this email, or quote reference '
                        . '<strong>#' . (int)$booking['id'] . '</strong> when you arrive.',
                    'Your <strong>cash deposit receipt is attached</strong> as a PDF — print it or keep this '
                        . 'email as your proof of payment.',
                    'Keep your private status token: it is the only way to open your booking online, '
                        . 'and anyone with your email and this token can view or cancel it.',
                ],
                $guestRows,
                ['label' => 'View my booking', 'url' => $statusUrl],
                'Cancelling a booking forfeits ' . (int)MARLE_CANCELLATION_FORFEIT_RATE . '% of the total booking amount. '
                    . 'A booking that has neither checked in nor called by 4:00 PM on the arrival day (one hour '
                    . 'after the 3:00 PM check-in time) is cancelled automatically as a no-show.'
            );

            // The receipt the guest can print. Built here rather than inside the
            // transport so a fault in the PDF can only ever cost the attachment,
            // never the email that tells the guest their room is booked.
            $receipt = null;
            try {
                require_once __DIR__ . '/deposit_receipt.php';
                $receipt = [
                    'filename' => marle_deposit_receipt_filename($booking),
                    'mime'     => 'application/pdf',
                    'content'  => marle_deposit_receipt_pdf($conn, $booking, $recordedBy),
                ];
                $sent['receipt'] = true;
            } catch (Throwable $e) {
                error_log('booking_notifications: cash receipt for walk-in #' . $bookingId
                    . ' could not be built — ' . $e->getMessage());
            }

            $sent['guest'] = marle_send_email(
                $email,
                $guest,
                'Booking recorded at the front desk — Marle Hotel (ref #' . (int)$booking['id'] . ')',
                $guestBody,
                '',
                $receipt
            );
        }

        // ── Hotel inbox ──────────────────────────────────────────────────────
        $hotelRows = [
            'Reference'            => '#' . (int)$booking['id'],
            'Guest'                => $guest,
            'Email'                => $email !== '' ? $email : 'None given',
            'Phone'                => (string)($booking['guest_phone'] ?? ''),
            'Room'                 => $room,
            'Stay'                 => $when,
            'Guests'               => (int)$booking['persons'],
            'Booking total'        => marle_peso($total),
            'Deposit received (cash)' => marle_peso($deposit),
            'Balance on arrival'   => marle_peso($balance),
            'Cash recorded by'     => $recordedBy !== '' ? $recordedBy : 'staff at the desk',
            'Status'               => 'Pending — approve it to put it on the calendar',
        ];
        if ($full !== '') {
            $hotelRows['Special requests'] = $full;
        }

        $hotelBody = marle_email_template(
            'Walk-in recorded',
            '#933310',
            [
                'A guest at the desk paid the reservation deposit in cash, so this booking was recorded '
                    . 'with the deposit already verified. Nothing was uploaded — the money was counted at the desk.',
                'Approve it in <strong>Requests → Bookings</strong>; that is what assigns its calendar event '
                    . 'and confirms the room.',
            ],
            $hotelRows,
            ['label' => 'Open Requests → Bookings', 'url' => marle_absolute_url('admin/requests.php')]
        );

        $sent['hotel'] = marle_send_email(
            marle_email_notify_to(),
            'Marle Hotel Front Desk',
            'Walk-in #' . (int)$booking['id'] . ' — ' . $guest . ' (' . $room . ', ' . marle_peso($deposit) . ' cash)',
            $hotelBody
        );

        return $sent;
    }
}

if (!function_exists('marle_booking_deposit_rows')) {
    /** Shared deposit figures for any booking email. */
    function marle_booking_deposit_rows(mysqli $conn, array $booking): array
    {
        $total   = marle_booking_total($booking);
        $rate    = (float)($booking['deposit_rate'] ?? marle_deposit_rate($conn));
        $deposit = (float)($booking['deposit_amount'] ?? marle_deposit_amount($conn, $total));
        $meta    = marle_deposit_status_meta((string)($booking['deposit_status'] ?? 'unpaid'));

        return [
            'total'    => $total,
            'deposit'  => $deposit,
            'rate'     => $rate,
            'label'    => $meta['label'],
        ];
    }
}

if (!function_exists('marle_notify_deposit_submitted')) {
    /**
     * The guest sent proof of payment: confirm receipt to the guest and put a
     * deposit alert in the staff notification bell.
     */
    function marle_notify_deposit_submitted(mysqli $conn, int $bookingId): void
    {
        $booking = marle_fetch_booking($conn, $bookingId);
        if (!$booking) return;

        $money   = marle_booking_deposit_rows($conn, $booking);
        $guest   = (string)$booking['guest_name'];
        $room    = marle_booking_room_text($booking);
        $statusUrl = marle_absolute_url(
            'homepage/booking_status.php?email=' . rawurlencode((string)$booking['guest_email'])
            . '&status_token=' . rawurlencode((string)($booking['status_token'] ?? ''))
        );

        $guestBody = marle_email_template(
            'We received your payment details',
            '#198754',
            [
                'Dear <strong>' . htmlspecialchars($guest, ENT_QUOTES, 'UTF-8') . '</strong>,',
                'Thank you! Your proof of payment is with our front desk and is being verified.',
                'You will receive another email the moment your booking is confirmed. '
                    . 'If anything is unclear we will contact you at ' . htmlspecialchars((string)$booking['guest_phone'], ENT_QUOTES, 'UTF-8') . '.',
            ],
            [
                'Booking reference' => '#' . (int)$booking['id'],
                'Room'              => $room,
                'Stay'              => marle_booking_when_text($booking),
                'Reference no.'     => (string)($booking['deposit_reference'] ?? ''),
                'Deposit amount'    => marle_peso($money['deposit']),
                'Status'            => 'Deposit received — for verification',
            ],
            ['label' => 'View my booking', 'url' => $statusUrl]
        );
        marle_send_email(
            (string)$booking['guest_email'],
            $guest,
            'Payment received — Marle Hotel booking #' . (int)$booking['id'],
            $guestBody
        );

        $hotelBody = marle_email_template(
            'Deposit proof uploaded',
            '#933310',
            [
                'A guest uploaded proof of payment. Verify it, then approve the booking in <strong>Requests → Bookings</strong>.',
            ],
            [
                'Reference'      => '#' . (int)$booking['id'],
                'Guest'          => $guest . ' · ' . (string)$booking['guest_email'],
                'Room'           => $room,
                'Stay'           => marle_booking_when_text($booking),
                'Reference no.'  => (string)($booking['deposit_reference'] ?? ''),
                'Amount expected'=> marle_peso($money['deposit']),
            ],
            ['label' => 'Open Requests → Bookings', 'url' => marle_absolute_url('admin/requests.php')]
        );
        marle_send_email(
            marle_email_notify_to(),
            'Marle Hotel Front Desk',
            'Deposit proof for booking #' . (int)$booking['id'] . ' — ' . $guest,
            $hotelBody
        );

        // Staff bell (best effort — never blocks the guest).
        $msg = 'Reservation deposit proof for booking #' . (int)$booking['id'] . ' (' . $room . ') is waiting for verification.';
        $notif = $conn->prepare(
            "INSERT INTO notifications (type, reference_id, room_number, message)
             VALUES ('deposit', ?, ?, ?)"
        );
        if ($notif) {
            $roomNo = (string)($booking['room_number'] ?? '');
            $notif->bind_param('iss', $bookingId, $roomNo, $msg);
            $notif->execute();
            $notif->close();
        }
    }
}

if (!function_exists('marle_notify_deposit_verified')) {
    /** Tell the guest their booking is confirmed once the deposit is verified. */
    function marle_notify_deposit_verified(mysqli $conn, int $bookingId, bool $approved): void
    {
        $booking = marle_fetch_booking($conn, $bookingId);
        if (!$booking) return;

        $money = marle_booking_deposit_rows($conn, $booking);
        $guest = (string)$booking['guest_name'];

        if ($approved) {
            $when  = marle_booking_when_text($booking);
            $rows  = [
                'Booking reference' => '#' . (int)$booking['id'],
                'Room'              => marle_booking_room_text($booking),
                'Stay'              => $when,
                'Deposit received'  => marle_peso($money['deposit']),
                'Status'            => 'Deposit verified — waiting for room approval',
            ];
            $body = marle_email_template(
                'Reservation deposit verified',
                '#198754',
                [
                    'Dear <strong>' . htmlspecialchars($guest, ENT_QUOTES, 'UTF-8') . '</strong>,',
                    'Our front desk verified your payment — thank you. Your booking now moves to room approval, '
                        . 'and you will get one final email the moment the room is confirmed.',
                ],
                $rows,
                ['label' => 'View my booking', 'url' => marle_absolute_url(
                    'homepage/booking_status.php?email=' . rawurlencode((string)$booking['guest_email'])
                    . '&status_token=' . rawurlencode((string)($booking['status_token'] ?? ''))
                )]
            );
            marle_send_email(
                (string)$booking['guest_email'],
                $guest,
                'Deposit verified — Marle Hotel booking #' . (int)$booking['id'],
                $body
            );
            return;
        }

        $reason = (string)($booking['deposit_rejection_reason'] ?? 'the uploaded receipt was not clear');
        $body = marle_email_template(
            'We could not verify your payment yet',
            '#dc3545',
            [
                'Dear <strong>' . htmlspecialchars($guest, ENT_QUOTES, 'UTF-8') . '</strong>,',
                'Our front desk could not verify the proof of payment you sent.',
                '<strong>Reason:</strong> ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'),
                'Please upload a clear copy again on your booking status page, or call the front desk at 0917 000 0000.',
            ],
            [
                'Booking reference' => '#' . (int)$booking['id'],
                'Room'              => marle_booking_room_text($booking),
                'Deposit amount'    => marle_peso($money['deposit']),
            ],
            ['label' => 'Upload again', 'url' => marle_absolute_url(
                'homepage/booking_status.php?email=' . rawurlencode((string)$booking['guest_email'])
                . '&status_token=' . rawurlencode((string)($booking['status_token'] ?? ''))
            )]
        );
        marle_send_email(
            (string)$booking['guest_email'],
            $guest,
            'Action needed: proof of payment — Marle Hotel booking #' . (int)$booking['id'],
            $body
        );
    }
}

if (!function_exists('marle_notify_booking_expired')) {
    /**
     * The deposit never arrived inside the hold window, so the booking was
     * released. Tell the guest exactly why, that nothing was charged, and how to
     * get the dates back; alert the hotel inbox and the staff bell.
     */
    function marle_notify_booking_expired(mysqli $conn, int $bookingId, int $hours): void
    {
        $booking = marle_fetch_booking($conn, $bookingId);
        if (!$booking) return;

        $money  = marle_booking_deposit_rows($conn, $booking);
        $guest  = (string)$booking['guest_name'];
        $room   = marle_booking_room_text($booking);
        $when   = marle_booking_when_text($booking);
        $hold   = marle_deposit_hold_label($hours);
        $statusUrl = marle_absolute_url(
            'homepage/booking_status.php?email=' . rawurlencode((string)$booking['guest_email'])
            . '&status_token=' . rawurlencode((string)($booking['status_token'] ?? ''))
        );

        $rows = [
            'Booking reference' => '#' . (int)$booking['id'],
            'Room'              => $room,
            'Stay'              => $when,
            'Deposit that was due' => marle_peso($money['deposit']),
            'Amount charged'    => 'None — no deposit was ever verified',
            'Status'            => 'Released — deposit not received within ' . $hold,
        ];

        $guestBody = marle_email_template(
            'Your booking was released',
            '#dc3545',
            [
                'Dear <strong>' . htmlspecialchars($guest, ENT_QUOTES, 'UTF-8') . '</strong>,',
                'We did not receive the reservation deposit of <strong>'
                    . htmlspecialchars(marle_peso($money['deposit']), ENT_QUOTES, 'UTF-8') . '</strong> within '
                    . htmlspecialchars($hold, ENT_QUOTES, 'UTF-8')
                    . ', so the room has been released and the dates are open to other guests.',
                '<strong>Nothing has been charged on this booking</strong> — we only hold the room once a payment is verified.',
                'Still want these dates? Call the front desk at <strong>0917 000 0000</strong> and we will hold the room '
                    . 'while you send the deposit, or place a new booking on our website.',
            ],
            $rows,
            ['label' => 'Start a new booking', 'url' => marle_absolute_url('homepage/home.php#bookingArea')],
            'This booking was closed automatically. Keep your status token if you would like to look at it again: '
                . $statusUrl
        );
        marle_send_email(
            (string)$booking['guest_email'],
            $guest,
            'Booking released — Marle Hotel #' . (int)$booking['id'],
            $guestBody
        );

        $hotelBody = marle_email_template(
            'Unpaid booking released',
            '#933310',
            [
                'A booking was released automatically because its reservation deposit was not received in time. '
                    . 'The room is available again.',
            ],
            array_merge($rows, [
                'Guest'    => $guest . ' · ' . (string)$booking['guest_email'],
                'Contact'  => (string)($booking['guest_phone'] ?? '—'),
                'Held for' => $hold,
            ])
        );
        marle_send_email(
            marle_email_notify_to(),
            'Marle Hotel Front Desk',
            'Released #' . (int)$booking['id'] . ' — ' . $guest . ' (deposit not received)',
            $hotelBody
        );

        $msg = 'Booking #' . (int)$booking['id'] . ' (' . $room . ') released — deposit not received within ' . $hold . '.';
        $notif = $conn->prepare(
            "INSERT INTO notifications (type, reference_id, room_number, message)
             VALUES ('booking', ?, ?, ?)"
        );
        if ($notif) {
            $roomNo = (string)($booking['room_number'] ?? '');
            $notif->bind_param('iss', $bookingId, $roomNo, $msg);
            $notif->execute();
            $notif->close();
        }
    }
}

if (!function_exists('marle_notify_booking_amended')) {
    /**
     * The front desk moved a held booking (dates, room or guest count). Tell the
     * guest the new details and any deposit difference, alert the hotel inbox
     * and drop a bell notification.
     *
     * @param array $info old_room, old_when, old_total, new_total, deposit_delta,
     *                    deposit_status, actor — the figures the re-quote showed
     *                    the staff member, so the email and the screen agree
     */
    function marle_notify_booking_amended(mysqli $conn, int $bookingId, array $info = []): void
    {
        $booking = marle_fetch_booking($conn, $bookingId);
        if (!$booking) return;

        $guest    = (string)$booking['guest_name'];
        $room     = marle_booking_room_text($booking);
        $when     = marle_booking_when_text($booking);
        $total    = marle_booking_total($booking);
        $deposit  = marle_deposit_amount($conn, $total);
        $paid     = (float)($booking['deposit_amount'] ?? 0);
        $delta    = isset($info['deposit_delta']) ? round((float)$info['deposit_delta'], 2) : round($deposit - $paid, 2);
        $oldWhen  = (string)($info['old_when'] ?? '');
        $oldRoom  = (string)($info['old_room'] ?? '');

        $statusUrl = marle_absolute_url(
            'homepage/booking_status.php?email=' . rawurlencode((string)$booking['guest_email'])
            . '&status_token=' . rawurlencode((string)($booking['status_token'] ?? ''))
        );

        $rows = [
            'Booking reference' => '#' . (int)$booking['id'],
            'Room'              => $room,
            'Stay'              => $when,
            'Guests'            => (int)$booking['persons'],
            'Booking total'     => marle_peso($total),
            'Reservation deposit required' => marle_peso($deposit),
        ];
        if ($paid > 0) {
            $rows['Deposit already on record'] = marle_peso($paid);
        }
        if ($delta > 0) {
            $rows['Additional deposit to settle'] = marle_peso($delta);
        } elseif ($delta < 0) {
            $rows['Credit to you'] = marle_peso(abs($delta));
        }

        $intro = 'The front desk has updated this booking';
        if ($oldWhen !== '' && $oldWhen !== $when) {
            $intro .= ' — it was previously ' . $oldWhen
                . ($oldRoom !== '' && $oldRoom !== (string)$booking['room_number'] ? ' (Room ' . $oldRoom . ')' : '')
                . '.';
        } else {
            $intro .= '.';
        }

        $guestBody = marle_email_template(
            'Your booking was updated',
            '#198754',
            [
                'Dear <strong>' . htmlspecialchars($guest, ENT_QUOTES, 'UTF-8') . '</strong>,',
                htmlspecialchars($intro, ENT_QUOTES, 'UTF-8'),
                'Here are the current details — please check them and call us on <strong>0917 000 0000</strong> if anything looks wrong.',
                $delta > 0
                    ? 'A further <strong>' . htmlspecialchars(marle_peso($delta), ENT_QUOTES, 'UTF-8') . '</strong> reservation deposit is due to keep the reservation confirmed. '
                        . htmlspecialchars(marle_deposit_method_text($conn), ENT_QUOTES, 'UTF-8')
                    : ($delta < 0
                        ? 'Your payment on record is <strong>' . htmlspecialchars(marle_peso(abs($delta)), ENT_QUOTES, 'UTF-8') . '</strong> more than the new deposit requires — the front desk will settle that with you.'
                        : 'Your reservation deposit is unaffected by this change.'),
            ],
            $rows,
            ['label' => 'View my booking', 'url' => $statusUrl]
        );
        marle_send_email(
            (string)$booking['guest_email'],
            $guest,
            'Booking updated — Marle Hotel #' . (int)$booking['id'],
            $guestBody
        );

        $hotelBody = marle_email_template(
            'Booking changed by the front desk',
            '#933310',
            [
                (string)($info['actor'] ?? 'The front desk') . ' changed a held booking. The guest has been emailed the new details.',
            ],
            array_merge($rows, [
                'Guest'        => $guest . ' · ' . (string)$booking['guest_email'],
                'Contact'      => (string)($booking['guest_phone'] ?? '—'),
                'Was'          => ($oldWhen !== '' ? $oldWhen : '—')
                    . ($oldRoom !== '' ? ' · Room ' . $oldRoom : ''),
                'Now'          => $when . ' · Room ' . (string)($booking['room_number'] ?? '—'),
                'Total was'    => marle_peso((float)($info['old_total'] ?? 0)),
                'Deposit delta'=> ($delta > 0 ? 'collect ' . marle_peso($delta) : ($delta < 0 ? 'credit ' . marle_peso(abs($delta)) : 'none')),
            ])
        );
        marle_send_email(
            marle_email_notify_to(),
            'Marle Hotel Front Desk',
            'Booking changed #' . (int)$booking['id'] . ' — ' . $guest . ' (' . $room . ')',
            $hotelBody
        );

        $msg = 'Booking #' . (int)$booking['id'] . ' (' . $room . ') changed by the front desk — '
             . $when . '. Re-quoted to ' . marle_peso($total)
             . ($delta > 0 ? ' · collect ' . marle_peso($delta) . ' more deposit' : '') . '.';
        $notif = $conn->prepare(
            "INSERT INTO notifications (type, reference_id, room_number, message)
             VALUES ('booking', ?, ?, ?)"
        );
        if ($notif) {
            $roomNo = (string)($booking['room_number'] ?? '');
            $notif->bind_param('iss', $bookingId, $roomNo, $msg);
            $notif->execute();
            $notif->close();
        }
    }
}

if (!function_exists('marle_notify_booking_cancelled')) {
    /**
     * Cancellation: tell the guest exactly what was forfeited, alert the hotel
     * inbox, and drop a bell notification for the front desk.
     */
    function marle_notify_booking_cancelled(mysqli $conn, int $bookingId): void
    {
        $booking = marle_fetch_booking($conn, $bookingId);
        if (!$booking) return;

        $guest  = (string)$booking['guest_name'];
        $total  = marle_booking_total($booking);
        $fee    = (float)($booking['cancellation_fee_amount'] ?? marle_cancellation_fee($total));
        $paid   = (float)($booking['deposit_amount'] ?? 0);
        $extra  = max(0, round($fee - $paid, 2));

        $rows = [
            'Booking reference'  => '#' . (int)$booking['id'],
            'Room'               => marle_booking_room_text($booking),
            'Stay'               => marle_booking_when_text($booking),
            'Booking total'      => marle_peso($total),
            'Cancellation fee'   => marle_peso($fee) . ' (' . (int)MARLE_CANCELLATION_FORFEIT_RATE . '% of the total)',
            'Deposit on record'  => marle_peso($paid),
        ];
        if ($extra > 0) {
            $rows['Balance to settle'] = marle_peso($extra);
        } else {
            $rows['Balance to settle'] = 'None — the deposit covers the cancellation fee';
        }
        $rows['Status'] = 'Cancelled';

        // Who cancelled changes the wording, not the money. A staff cancellation
        // says so plainly, and shows the guest the reason that was recorded —
        // minus the actor prefix, so "Staff (admin) cancelled: guest called to
        // cancel" never reaches a guest inbox with an account name in it.
        $byStaff    = str_starts_with((string)($booking['cancelled_by'] ?? ''), 'staff');
        $reasonText = trim((string)($booking['cancellation_reason'] ?? ''));
        $guestReason = $reasonText;
        if (($colon = strpos($reasonText, ':')) !== false) {
            $guestReason = trim(substr($reasonText, $colon + 1));
        }
        if ($byStaff && $guestReason !== '') {
            $rows['Reason'] = $guestReason;
        }

        $guestBody = marle_email_template(
            'Booking cancelled',
            '#dc3545',
            [
                'Dear <strong>' . htmlspecialchars($guest, ENT_QUOTES, 'UTF-8') . '</strong>,',
                $byStaff
                    ? 'The front desk has cancelled this booking for you, as discussed. The table below shows exactly what the hotel keeps under the '
                        . (int)MARLE_CANCELLATION_FORFEIT_RATE . '% cancellation policy you accepted when booking.'
                    : 'Your booking has been cancelled. The table below shows exactly what the hotel keeps under the '
                        . (int)MARLE_CANCELLATION_FORFEIT_RATE . '% cancellation policy you accepted when booking.',
                $extra > 0
                    ? 'The remaining balance of <strong>' . htmlspecialchars(marle_peso($extra), ENT_QUOTES, 'UTF-8') . '</strong> can be settled at the front desk.'
                    : 'Nothing further is owed on this booking.',
                'Planning to visit another time? Call the front desk at <strong>0917 000 0000</strong> — we will be glad to rebook you.',
            ],
            $rows
        );
        marle_send_email(
            (string)$booking['guest_email'],
            $guest,
            'Booking cancelled — Marle Hotel #' . (int)$booking['id'],
            $guestBody
        );

        $hotelBody = marle_email_template(
            $byStaff ? 'Booking cancelled by the front desk' : 'Booking cancelled by guest',
            '#933310',
            ['A guest cancelled a booking. The forfeited amount is recorded against the booking.'],
            array_merge($rows, [
                'Guest'  => $guest . ' · ' . (string)$booking['guest_email'],
                'Reason' => (string)($booking['cancellation_reason'] ?? '—'),
            ])
        );
        marle_send_email(
            marle_email_notify_to(),
            'Marle Hotel Front Desk',
            'Cancellation #' . (int)$booking['id'] . ' — ' . $guest . ' (' . marle_peso($fee) . ' retained)',
            $hotelBody
        );

        $msg = 'Booking #' . (int)$booking['id'] . ' (' . marle_booking_room_text($booking)
             . ') cancelled — ' . marle_peso($fee) . ' retained.';
        $notif = $conn->prepare(
            "INSERT INTO notifications (type, reference_id, room_number, message)
             VALUES ('booking', ?, ?, ?)"
        );
        if ($notif) {
            $roomNo = (string)($booking['room_number'] ?? '');
            $notif->bind_param('iss', $bookingId, $roomNo, $msg);
            $notif->execute();
            $notif->close();
        }
    }
}
