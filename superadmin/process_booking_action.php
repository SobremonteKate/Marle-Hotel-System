<?php
// ============================================================
//  admin/process_booking_action.php
//  Approve or deny a booking request.
//  On approval: assigns a room, creates a calendar event,
//  and sends email notification.
//
//  POST params:
//    action     — approve | deny
//    booking_id — int
//    room_number — string (required for approve)
//    reason     — string (required for deny)
// ============================================================
session_start();
header('Content-Type: application/json');

include('../dbconnect.php');

// A database that is down must reach this endpoint as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'json', 'staff');
include('../superadmin/auth.php');
require_once __DIR__ . '/../includes/log_action.php';
require_once __DIR__ . '/../includes/send_email.php';
require_once __DIR__ . '/../includes/booking_deposit.php';

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}

$action    = trim($_POST['action']     ?? '');
$bookingId = (int)($_POST['booking_id'] ?? 0);

if ($bookingId < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID.']);
    exit();
}

// Fetch booking
$stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $bookingId);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'Booking not found.']);
    exit();
}

if ($booking['status'] !== 'pending') {
    echo json_encode(['success' => false, 'message' => 'This booking has already been ' . $booking['status'] . '.']);
    exit();
}

if ($action === 'approve') {
    // ── Reservation deposit gate (phase16) ─────────────────────────────────
    // The room is only confirmed once the guest's deposit has been verified by
    // a manager — and a deposit is only verified against a receipt the guest
    // uploaded (Requests → Bookings → Verify deposit). One clear path, no
    // silent override that would leave the money untracked.
    $depositStatus = (string)($booking['deposit_status'] ?? 'unpaid');
    if ($depositStatus !== 'verified') {
        log_event(
            $conn,
            'booking_approve_blocked',
            'booking',
            $bookingId,
            'denied',
            'Reservation deposit not verified (status: ' . $depositStatus . ')'
        );
        echo json_encode([
            'success' => false,
            'message' => 'The reservation deposit for this booking is not verified yet. '
                       . 'Verify the guest\'s payment first (the manager verifies deposits), then approve.',
            'deposit_status' => $depositStatus,
        ]);
        exit();
    }

    // Use the room_number already assigned during booking submission
    $roomNumber = $booking['room_number'];
    if (empty($roomNumber)) {
        echo json_encode(['success' => false, 'message' => 'No room was assigned to this booking. Please try again.']);
        exit();
    }

    // Verify room is still available (check both daily and hourly bookings)
    $checkStmt = $conn->prepare(
        "SELECT id FROM bookings
         WHERE status = 'approved' AND room_number = ?
           AND id != ?
           AND (
               -- Daily bookings: date range overlap
               (booking_type = 'daily' AND check_in < ? AND check_out > ?)
               OR
               -- Hourly bookings: booking date falls within range
               (booking_type = 'hourly' AND check_in >= ? AND check_in < ?)
           )
         LIMIT 1"
    );
    $checkStmt->bind_param('ssssss', $roomNumber, $bookingId, $booking['check_out'], $booking['check_in'], $booking['check_in'], $booking['check_out']);
    $checkStmt->execute();
    $conflict = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if ($conflict) {
        echo json_encode(['success' => false, 'message' => 'Room ' . $roomNumber . ' is no longer available for these dates.']);
        exit();
    }

    // Create calendar event
    $eventTitle = 'Booking: ' . $booking['guest_name'] . ' (Room ' . $roomNumber . ')';
    $eventDesc  = 'Guest: ' . $booking['guest_name'] . "\n"
                . 'Email: ' . $booking['guest_email'] . "\n"
                . 'Phone: ' . ($booking['guest_phone'] ?: 'N/A') . "\n"
                . 'Persons: ' . $booking['persons'] . "\n"
                . 'Room Type: ' . $booking['room_type'];
    $eventStart = $booking['check_in'] . ' 14:00:00';  // Default check-in time 2 PM
    $eventEnd   = $booking['check_out'] . ' 12:00:00'; // Default check-out time 12 PM
    $eventColor = '#933310'; // Bookings color in calendar

    $calStmt = $conn->prepare(
        "INSERT INTO calendartbl (title, description, start, end, color)
         VALUES (?, ?, ?, ?, ?)"
    );
    $calStmt->bind_param('sssss', $eventTitle, $eventDesc, $eventStart, $eventEnd, $eventColor);
    $calStmt->execute();
    $calEventId = $calStmt->insert_id;
    $calStmt->close();

    // Update booking status
    $updateStmt = $conn->prepare(
        "UPDATE bookings
         SET status = 'approved', calendar_event_id = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $updateStmt->bind_param('ii', $calEventId, $bookingId);
    $updateStmt->execute();
    $updateStmt->close();

    // Send approval email
    $to      = $booking['guest_email'];
    $depositNote = '<tr><td style="padding:8px; border-bottom:1px solid #eee; color:#888;">Reservation deposit</td>'
                 . '<td style="padding:8px; border-bottom:1px solid #eee; font-weight:bold;">Verified '
                 . htmlspecialchars(marle_peso((float)($booking['deposit_amount'] ?? 0))) . '</td></tr>';
    $subject = 'Booking Confirmed - Marle Hotel';
    $body    = '<html><body style="font-family:Arial,sans-serif;">'
             . '<div style="max-width:600px; margin:0 auto; padding:20px;">'
             . '<div style="background:#ffb60a; padding:20px; text-align:center; border-radius:10px 10px 0 0;">'
             . '<h1 style="color:#fff; margin:0;">Marle Hotel</h1></div>'
             . '<div style="background:#fff; padding:30px; border:1px solid #eee; border-radius:0 0 10px 10px;">'
             . '<h2 style="color:#198754;">Booking Confirmed!</h2>'
             . '<p>Dear <strong>' . htmlspecialchars($booking['guest_name']) . '</strong>,</p>'
             . '<p>Your booking has been approved. Here are your details:</p>'
             . '<table style="width:100%; border-collapse:collapse; margin:15px 0;">'
             . '<tr><td style="padding:8px; border-bottom:1px solid #eee; color:#888;">Room</td>'
             . '<td style="padding:8px; border-bottom:1px solid #eee; font-weight:bold;">' . htmlspecialchars($booking['room_type']) . ' — Room ' . htmlspecialchars($roomNumber) . '</td></tr>'
             . '<tr><td style="padding:8px; border-bottom:1px solid #eee; color:#888;">Check-in</td>'
             . '<td style="padding:8px; border-bottom:1px solid #eee; font-weight:bold;">' . date('F j, Y', strtotime($booking['check_in'])) . ' at 2:00 PM</td></tr>'
             . '<tr><td style="padding:8px; border-bottom:1px solid #eee; color:#888;">Check-out</td>'
             . '<td style="padding:8px; border-bottom:1px solid #eee; font-weight:bold;">' . date('F j, Y', strtotime($booking['check_out'])) . ' at 12:00 PM</td></tr>'
             . '<tr><td style="padding:8px; border-bottom:1px solid #eee; color:#888;">Guests</td>'
             . '<td style="padding:8px; border-bottom:1px solid #eee; font-weight:bold;">' . (int)$booking['persons'] . '</td></tr>'
             . $depositNote
             . '</table>'
             . '<p>We look forward to welcoming you!</p>'
             . '<p style="color:#888; font-size:12px;">If you have questions, please contact the front desk.</p>'
             . '</div></div></body></html>';

    // Delivered via HTTP email API (works on InfinityFree, where mail() is blocked).
    // The result is reported, not assumed: a walk-in only has an address when
    // the guest gave one, and the provider can be down — either way the desk is
    // told the truth instead of "Email sent".
    $guestEmail = trim((string)$to);
    $mailSent   = $guestEmail !== ''
        && marle_send_email($guestEmail, (string)$booking['guest_name'], $subject, $body);

    $emailNote = $guestEmail === ''
        ? 'No email address on this booking — nothing was sent.'
        : ($mailSent
            ? 'Email sent to ' . $guestEmail . '.'
            : 'The email to ' . $guestEmail . ' could not be sent (check the mail settings).');

    log_event(
        $conn,
        'booking_approved',
        'booking',
        $bookingId,
        'success',
        'Room ' . $roomNumber . ' — ' . $booking['guest_name']
            . ($mailSent ? ' (approval email sent)' : ' (no approval email: ' . $emailNote . ')')
    );

    echo json_encode([
        'success'     => true,
        'message'     => 'Booking approved! Room ' . $roomNumber . ' confirmed. Calendar event created. ' . $emailNote,
        'room_number' => $roomNumber,
        'email_sent'  => $mailSent,
    ]);

} elseif ($action === 'deny') {
    $reason = trim($_POST['reason'] ?? '');
    if ($reason === '') {
        echo json_encode(['success' => false, 'message' => 'A denial reason is required.']);
        exit();
    }

    $updateStmt = $conn->prepare(
        "UPDATE bookings SET status = 'denied', denial_reason = ?, updated_at = NOW() WHERE id = ?"
    );
    $updateStmt->bind_param('si', $reason, $bookingId);
    $updateStmt->execute();
    $updateStmt->close();

    // Send denial email
    $to      = $booking['guest_email'];
    $subject = 'Booking Update - Marle Hotel';
    $body    = '<html><body style="font-family:Arial,sans-serif;">'
             . '<div style="max-width:600px; margin:0 auto; padding:20px;">'
             . '<div style="background:#ffb60a; padding:20px; text-align:center; border-radius:10px 10px 0 0;">'
             . '<h1 style="color:#fff; margin:0;">Marle Hotel</h1></div>'
             . '<div style="background:#fff; padding:30px; border:1px solid #eee; border-radius:0 0 10px 10px;">'
             . '<h2 style="color:#dc3545;">Booking Not Available</h2>'
             . '<p>Dear <strong>' . htmlspecialchars($booking['guest_name']) . '</strong>,</p>'
             . '<p>Unfortunately, we were unable to accommodate your booking request for:</p>'
             . '<p><strong>' . htmlspecialchars($booking['room_type']) . '</strong> — '
             . date('F j, Y', strtotime($booking['check_in'])) . ' to '
             . date('F j, Y', strtotime($booking['check_out'])) . '</p>'
             . '<p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>'
             . '<p>Please feel free to try different dates or contact us directly.</p>'
             . '<p style="color:#888; font-size:12px;">We apologize for any inconvenience.</p>'
             . '</div></div></body></html>';

    // Delivered via HTTP email API (works on InfinityFree, where mail() is blocked).
    marle_send_email($to, (string)$booking['guest_name'], $subject, $body);

    log_event($conn, 'booking_denied', 'booking', $bookingId, 'success', $reason);

    echo json_encode([
        'success' => true,
        'message' => 'Booking denied. Email notification sent to ' . $booking['guest_email'] . '.',
    ]);

} else {
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
