<?php
// ============================================================
//  GuestTokens.php  —  Admin: Guest Token Manager
//  MODIFIED: logs check-in event to transaction_history table
// ============================================================
include('../dbconnect.php');

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');

// English | Tagalog for this whole screen (includes/ui_i18n.php). Must run
// before anything is echoed: the switch rewrites the finished page.
require_once __DIR__ . '/../includes/ui_i18n.php';
ui_i18n_start('staff.tokens');
include('../admin/auth_admin.php');
require_once __DIR__ . '/../includes/log_action.php';
require_once __DIR__ . '/../csrf.php';
include('../admin/requests_badge.php');
include('../admin/includes/notif_data.php');
require_once __DIR__ . '/includes/token_sweep.php';
run_token_sweep($conn);
require_once __DIR__ . '/includes/chat_sweep.php';
run_chat_sweep($conn);
require_once __DIR__ . '/../includes/discounts.php';
require_once __DIR__ . '/../includes/token_print.php';
// Server-anchored clock: the Occupied Rooms countdowns below count from the
// server's expiry, never from the clock of whatever device is looking at them.
require_once __DIR__ . '/../includes/server_clock.php';

$collectionCsrfToken = csrf_token();

$notif     = '';
$notifType = 'success';
// Slip payload for the token that was just issued (renders a Print button in
// the success alert). Empty until a generate succeeds.
$issuedSlipPayload = '';

// Ledger type labels used by the occupied-room transaction panels
$txTypeLabels = [
    'check_in'  => 'Check-in',
    'extension' => 'Extension',
    'service'   => 'Service',
    'food'      => 'Food order',
];

function generateToken(): string {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $len   = strlen($chars);
    $out   = '';
    for ($i = 0; $i < 12; $i++) $out .= $chars[random_int(0, $len - 1)];
    return $out;
}

function formatToken(string $raw): string {
    return substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
}

// ── Room type → base rate map (flat 3-hour block rate) ───────────────────────
const BASE_RATE_MAP = [
    'With Garage'    => 450.00,
    'Suite'          => 600.00,
    'Family'         => 600.00,
    'Without Garage' => 350.00,
];

// ── Room type → extension rate (per hour beyond the first 3) ─────────────────
const EXTENSION_RATE_MAP = [
    'With Garage'    => 150.00,
    'Suite'          => 200.00,
    'Family'         => 200.00,
    'Without Garage' => 150.00,
];

// ── POST: Generate ─────────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'generate') {
    $roomNumber  = trim($_POST['room_number']  ?? '');
    $persons     = (int)($_POST['persons'] ?? 0);
    $plateNumber = trim($_POST['plate_number'] ?? '');
    $hoursOfStay = (int)($_POST['hours_of_stay'] ?? 0);

    // Statutory discount (Senior Citizen / PWD / other)
    $discountType      = trim((string)($_POST['discount_type'] ?? 'none'));
    $discountIdNo      = trim((string)($_POST['discount_id_no'] ?? ''));
    $discountCustomPct = (float)($_POST['discount_percent'] ?? 0);

    if (empty($roomNumber)) {
        $notif     = 'Please select a room.';
        $notifType = 'danger';
    } elseif ($persons < 1 || $persons > 6) {
        $notif     = 'Number of persons must be between 1 and 6.';
        $notifType = 'danger';
    } elseif ($hoursOfStay < 1 || $hoursOfStay > 720) {
        $notif     = 'Hours of stay must be between 1 and 720.';
        $notifType = 'danger';
    } elseif (!array_key_exists($discountType, marle_discount_types())) {
        $notif     = 'Unknown discount type.';
        $notifType = 'danger';
    } elseif (($discountIdError = marle_discount_id_error($discountType, $discountIdNo)) !== null) {
        // RA 9994 / RA 7277 print these IDs in a fixed shape and the number is
        // copied by hand off the guest's own card, so the format is checked —
        // not trusted — before it reaches the discount ledger and a slip.
        $notif     = $discountIdError;
        $notifType = 'danger';
    } elseif ($discountType === 'other' && ($discountCustomPct < 1 || $discountCustomPct > 50)) {
        $notif     = 'The custom discount must be between 1% and 50%.';
        $notifType = 'danger';
    } else {
        // Store the ID exactly as the card prints it.
        $discountIdNo = marle_discount_normalize_id($discountType, $discountIdNo);

        // ── Booking conflict check ─────────────────────────────────────────────
        // Determine room type for hourly-booking lookup
        $rtLookup = $conn->prepare("SELECT rooms FROM roomstbl WHERE room_number = ? LIMIT 1");
        $rtLookup->bind_param('s', $roomNumber);
        $rtLookup->execute();
        $rtLookupRow  = $rtLookup->get_result()->fetch_assoc();
        $rtLookup->close();
        $lookedUpType = $rtLookupRow['rooms'] ?? null;

        $conflictMsg = null;

        // 1) Daily booking conflict: an approved booking already occupies this
        //    specific room today (check_in <= today < check_out).
        $dailyConflict = $conn->prepare(
            "SELECT id FROM bookings
             WHERE  status       = 'approved'
               AND  room_number  = ?
               AND  COALESCE(booking_type, 'daily') != 'hourly'
               AND  check_in    <= CURDATE()
               AND  check_out   >  CURDATE()
             LIMIT 1"
        );
        $dailyConflict->bind_param('s', $roomNumber);
        $dailyConflict->execute();
        $hasDailyConflict = ($dailyConflict->get_result()->num_rows > 0);
        $dailyConflict->close();

        if ($hasDailyConflict) {
            $conflictMsg = 'Room <strong>' . htmlspecialchars($roomNumber) . '</strong> '
                         . 'already has an approved overnight booking that covers today. '
                         . 'Please choose a different room.';
        }

        // 2) Hourly booking conflict: an active (unreleased) approved hourly
        //    booking for this room type overlaps NOW() -> NOW()+hours_of_stay.
        //    Overlap: check_in_time < end_time AND check_out_time > start_time
        //    where start = TIME(NOW()), end = TIME(DATE_ADD(NOW(), INTERVAL h HOUR)).
        if (!$conflictMsg && $lookedUpType !== null) {
            $hourlyConflict = $conn->prepare(
                "SELECT id FROM bookings
                 WHERE  status          = 'approved'
                   AND  booking_type    = 'hourly'
                   AND  hourly_released = 0
                   AND  room_type       = ?
                   AND  check_in        = CURDATE()
                   AND  check_in_time  < TIME(DATE_ADD(NOW(), INTERVAL ? HOUR))
                   AND  check_out_time > TIME(NOW())
                 LIMIT 1"
            );
            $hourlyConflict->bind_param('si', $lookedUpType, $hoursOfStay);
            $hourlyConflict->execute();
            $hasHourlyConflict = ($hourlyConflict->get_result()->num_rows > 0);
            $hourlyConflict->close();

            if ($hasHourlyConflict) {
                $conflictMsg = 'A <strong>' . htmlspecialchars($lookedUpType) . '</strong> room '
                             . 'already has an approved hourly booking that overlaps this time slot. '
                             . 'Please choose a different room or wait until the current booking ends.';
            }
        }

        // ── Occupied / active-session check ───────────────────────────────────
        if (!$conflictMsg) {
            $occStmt = $conn->prepare(
                "SELECT id FROM customerstbl
                 WHERE room_no = ? AND status = 'checked-in' AND date_to > NOW()
                 LIMIT 1"
            );
            $occStmt->bind_param('s', $roomNumber);
            $occStmt->execute();
            $isOccupied = ($occStmt->get_result()->num_rows > 0);
            $occStmt->close();

            if ($isOccupied) {
                $conflictMsg = 'Cannot add token — room <strong>' . htmlspecialchars($roomNumber)
                             . '</strong> is currently occupied.';
            }
        }

        if ($conflictMsg) {
            $notif     = $conflictMsg;
            $notifType = 'danger';
        } else {
        // ── End booking conflict check ─────────────────────────────────────────

        $conn->begin_transaction();

        // Revoke any existing active token for this room.
        // Archive each row to guest_tokens_history FIRST (reason 'superseded') so the
        // token chain stays auditable — a bare DELETE would orphan the room's
        // unpaid check_in ledger row and any other references.
        $oldStmt = $conn->prepare(
            "SELECT id, token, room_number, customer_id, hours_of_stay, created_at, expires_at, created_by
             FROM guest_tokens WHERE room_number = ? AND expires_at > NOW()"
        );
        $oldStmt->bind_param('s', $roomNumber);
        $oldStmt->execute();
        $oldTokens = $oldStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $oldStmt->close();

        $histStmt = $conn->prepare(
            "INSERT INTO guest_tokens_history
                 (original_id, token, room_number, customer_id,
                  hours_of_stay, issued_at, expires_at, moved_at, reason, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'superseded', ?)"
        );
        $delOld = $conn->prepare("DELETE FROM guest_tokens WHERE id = ?");
        foreach ($oldTokens as $oldRow) {
            $histStmt->bind_param(
                'issiisss',
                $oldRow['id'],
                $oldRow['token'],
                $oldRow['room_number'],
                $oldRow['customer_id'],
                $oldRow['hours_of_stay'],
                $oldRow['created_at'],
                $oldRow['expires_at'],
                $oldRow['created_by']
            );
            $histStmt->execute();
            $delOld->bind_param('i', $oldRow['id']);
            $delOld->execute();
        }
        $histStmt->close();
        $delOld->close();

        // Find a unique token
        $token = '';
        for ($try = 0; $try < 10; $try++) {
            $candidate = generateToken();
            $chk = $conn->prepare("SELECT id FROM guest_tokens WHERE token = ? LIMIT 1");
            $chk->bind_param('s', $candidate);
            $chk->execute();
            $chk->store_result();
            $exists = ($chk->num_rows > 0);
            $chk->close();
            if (!$exists) { $token = $candidate; break; }
        }

        if (empty($token)) {
            $conn->rollback();
            $notif     = 'Could not generate a unique token. Please try again.';
            $notifType = 'danger';
        } else {
            // ── Determine transportation: "By Vehicle" if plate number present, else "Walk-In" ┐
            $transportation = 'Walk-In';
            if (!empty($plateNumber)) {
                $transportation = 'By Vehicle';
            }
            // ─────────────────────────────────────────────────────────────────────────────────

            // ── Discount + rate math (RA 9994 / RA 7277) ──────────────────
            // Computed BEFORE the stay is stored so the customer row, the
            // check-in ledger row and the printed slip all carry one set of
            // numbers, and the net is what every later collection charges.
            $rateStmt = $conn->prepare("SELECT rooms FROM roomstbl WHERE room_number = ? LIMIT 1");
            $rateStmt->bind_param('s', $roomNumber);
            $rateStmt->execute();
            $rateRow  = $rateStmt->get_result()->fetch_assoc();
            $rateStmt->close();

            $roomTypeForRate = $rateRow['rooms'] ?? ($lookedUpType ?? '');
            $baseRate        = (float)(BASE_RATE_MAP[$roomTypeForRate]      ?? 0.00);
            $extensionRate   = (float)(EXTENSION_RATE_MAP[$roomTypeForRate] ?? 150.00);
            $extraHours      = max(0, $hoursOfStay - 3);
            $grossAmount     = round($baseRate + ($extraHours * $extensionRate), 2);
            $discountCalc    = marle_compute_discount($grossAmount, $discountType, $discountCustomPct);
            $netAmount       = (float)$discountCalc['net_amount'];
            // The hotel's clock, not PHP's (includes/server_clock.php) — this one prints
            // on the receipt beside database-written times.
            $discountAppliedAt = $discountCalc['has_discount'] ? marle_db_now($conn) : null;
            $adminUser       = $_SESSION['username'] ?? '';

            // Insert customer record (with the discount evidence)
            $customerStmt = $conn->prepare(
                "INSERT INTO customerstbl
                     (room_no, transportation, persons, plate_no, date_from, date_to, hours, additional, status,
                      discount_type, discount_id_no, discount_percent, gross_amount, vat_exempt_amount,
                      discount_amount, net_amount, discount_applied_by, discount_applied_at)
                 VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? HOUR), ?, '{}', 'checked-in',
                      ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $customerStmt->bind_param(
                'ssisii' . 'ssdddddss',
                $roomNumber, $transportation, $persons, $plateNumber, $hoursOfStay, $hoursOfStay,
                $discountType, $discountIdNo, $discountCalc['percent'], $grossAmount,
                $discountCalc['vat_exempt_amount'], $discountCalc['discount_amount'], $netAmount,
                $adminUser, $discountAppliedAt
            );
            $customerOk = $customerStmt->execute();
            $newCustomerId = (int)$conn->insert_id;   // ★ capture customer ID
            $customerStmt->close();

            if ($customerOk) {
                $tokenStmt = $conn->prepare(
                    "INSERT INTO guest_tokens (token, room_number, hours_of_stay, expires_at, created_by)
                     VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), ?)"
                );
                $tokenStmt->bind_param('ssiis', $token, $roomNumber, $hoursOfStay, $hoursOfStay, $adminUser);
                $tokenOk    = $tokenStmt->execute();
                $guestTokenId = (int)$conn->insert_id; // ★ capture guest_tokens.id
                $tokenStmt->close();

                // ── ★ Link token back to the customer row just inserted ───────
                if ($tokenOk && $guestTokenId > 0 && $newCustomerId > 0) {
                    $linkStmt = $conn->prepare(
                        "UPDATE guest_tokens SET customer_id = ? WHERE id = ?"
                    );
                    $linkStmt->bind_param('ii', $newCustomerId, $guestTokenId);
                    $linkStmt->execute();
                    $linkStmt->close();
                }
                // ─────────────────────────────────────────────────────────────

                if ($tokenOk) {
                    // ── ★ Log check-in to transaction_history ────────────────
                    // Room type, gross, discount and net were computed above so
                    // the ledger, the stay row and the slip agree exactly.
                    $roomType    = $roomTypeForRate !== '' ? $roomTypeForRate : null;
                    $totalAmount = $netAmount;

                    

                    $thStmt2 = $conn->prepare(
                        "INSERT INTO transaction_history
                             (transaction_type, customer_id, room_number, room_type,
                              amount, gross_amount, discount_amount, status, reference_id, notes)
                         VALUES ('check_in', ?, ?, ?, ?, ?, ?, 'unpaid', ?, ?)"
                    );
                    $notes = 'Guest check-in — ' . $hoursOfStay . ' hour(s) stay';
                    if ($discountCalc['has_discount']) {
                        $notes .= ' · ' . marle_discount_label($discountType) . ' discount (ID '
                                . marle_mask_id($discountIdNo) . ') · net of VAT and discount';
                    }
                    $thStmt2->bind_param(
                        'issddd' . 'is',
                        $newCustomerId, $roomNumber, $roomType,
                        $totalAmount, $grossAmount, $discountCalc['discount_amount'],
                        $guestTokenId, $notes
                    );
                    $thStmt2->execute();
                    $checkInTxId = (int)$conn->insert_id;
                    $thStmt2->close();

                    // ── Discount audit trail (RA 9994 / RA 7277) ─────────────
                    // Two places, on purpose: the System Log records who granted
                    // the discount, and financial_adjustments keeps the money
                    // trail the Chapter 4 audit/paper trail refers to.
                    if ($discountCalc['has_discount'] && $checkInTxId > 0) {
                        $pctLabel = rtrim(rtrim(number_format((float)$discountCalc['percent'], 2, '.', ''), '0'), '.');
                        log_event(
                            $conn,
                            'discount_applied',
                            'transaction',
                            $checkInTxId,
                            'success',
                            'Room ' . $roomNumber . ' — ' . marle_discount_label($discountType) . ' ' . $pctLabel
                                . '% (ID ' . marle_mask_id($discountIdNo) . ') · '
                                . marle_peso((float)$discountCalc['discount_amount']) . ' off '
                                . marle_peso($grossAmount) . ' → ' . marle_peso($netAmount)
                        );

                        $adjStmt = $conn->prepare(
                            "INSERT INTO financial_adjustments
                                 (transaction_id, adjustment_type, original_amount, adjustment_amount,
                                  proposed_amount, reason, requested_by, requested_by_username,
                                  status, applied_at)
                             VALUES (?, 'discount', ?, ?, ?, ?, ?, ?, 'applied', NOW())"
                        );
                        if ($adjStmt) {
                            $adjReason   = marle_discount_label($discountType) . ' discount (ID '
                                         . marle_mask_id($discountIdNo) . ') — RA 9994 / RA 7277';
                            $adjAmount   = -1 * (float)$discountCalc['discount_amount'];
                            $adjUserId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                            $adjStmt->bind_param(
                                'idddsis',
                                $checkInTxId, $grossAmount, $adjAmount, $netAmount,
                                $adjReason, $adjUserId, $adminUser
                            );
                            $adjStmt->execute();
                            $adjStmt->close();
                        }
                    }
                    // ────────────────────────────────────────────────────────────

                    // ── ★ Reset chat_room_status for new guest ────────────────
                    // Ensures a fresh guest never inherits a 'complete' status
                    // from the previous occupant of the same room.
                    $crsStmt = $conn->prepare(
                        "INSERT INTO chat_room_status (room_number, status, completed_at, first_opened_at)
                         VALUES (?, 'new', NULL, NULL)
                         ON DUPLICATE KEY UPDATE
                             status          = 'new',
                             completed_at    = NULL,
                             first_opened_at = NULL"
                    );
                    $crsStmt->bind_param('s', $roomNumber);
                    $crsStmt->execute();
                    $crsStmt->close();
                    // ─────────────────────────────────────────────────────────

                    $conn->commit();
                    log_event($conn, 'token_generated', 'guest_token', $guestTokenId, 'success', 'Room ' . $roomNumber . ' — ' . $hoursOfStay . 'h stay');
                    $notif     = 'Token generated: <strong>' . formatToken($token) . '</strong> for Room <strong>'
                               . htmlspecialchars($roomNumber) . '</strong> — valid for <strong>'
                               . $hoursOfStay . 'h</strong>.';
                    if ($discountCalc['has_discount']) {
                        $notif .= ' ' . marle_discount_label($discountType) . ' discount applied: '
                                . '<strong>' . marle_peso($netAmount) . '</strong> payable (was '
                                . marle_peso($grossAmount) . ').';
                    }
                    $notifType = 'success';

                    // Hand the just-issued slip straight to the print helper. Its times
                    // are read back from the row the INSERT just wrote instead of from
                    // PHP's own clock: expires_at is stamped by MySQL's
                    // DATE_ADD(NOW(), …), and PHP's date.timezone can sit hours away from
                    // the database's clock (Europe/Berlin vs UTC+8 on the XAMPP host),
                    // which printed a slip whose expiry disagreed with the expiry on the
                    // token card right beside it.
                    $slipTimeStmt = $conn->prepare(
                        "SELECT created_at, expires_at FROM guest_tokens WHERE id = ? LIMIT 1"
                    );
                    $slipTimeStmt->bind_param('i', $guestTokenId);
                    $slipTimeStmt->execute();
                    $slipTimes = $slipTimeStmt->get_result()->fetch_assoc() ?: [];
                    $slipTimeStmt->close();

                    // Fallbacks for a token row whose stored times are missing, on the
                    // hotel's clock (includes/server_clock.php): the hours are added to the
                    // stamp's own wall time, never to a device instant.
                    $slipNow = marle_db_now($conn);
                    $issuedSlipPayload = marle_token_slip_payload(
                        [
                            'token'         => $token,
                            'room_number'   => $roomNumber,
                            'hours_of_stay' => $hoursOfStay,
                            'created_at'    => $slipTimes['created_at'] ?? $slipNow,
                            'expires_at'    => $slipTimes['expires_at'] ?? date('Y-m-d H:i:s', strtotime($slipNow . ' +' . $hoursOfStay . ' hours')),
                        ],
                        (string)$roomTypeForRate,
                        ['plate_no' => $plateNumber, 'persons' => $persons],
                        $discountCalc,
                        $discountType,
                        $discountIdNo
                    );
                } else {
                    $conn->rollback();
                    $notif     = 'Could not save the token. SQL error: ' . htmlspecialchars(mysqli_error($conn));
                    $notifType = 'danger';
                }
            } else {
                $conn->rollback();
                $notif     = 'Could not save the customer record. SQL error: ' . htmlspecialchars(mysqli_error($conn));
                $notifType = 'danger';
            }
        }
        } // end: no booking conflict
    }
}

// ── POST: Revoke ───────────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'revoke') {
    $tokenId = (int)($_POST['token_id'] ?? 0);
    if ($tokenId > 0) {
        $conn->begin_transaction();
        $revokeOk = false;

        // Fetch the token row first so we can archive it
        $fetch = $conn->prepare(
            "SELECT id, token, room_number, customer_id,
                    hours_of_stay, created_at, expires_at, created_by
             FROM guest_tokens WHERE id = ? LIMIT 1"
        );
        $fetch->bind_param('i', $tokenId);
        $fetch->execute();
        $revokeRow = $fetch->get_result()->fetch_assoc();
        $fetch->close();

        if ($revokeRow) {
            // Copy to history with reason = superseded
            $hist = $conn->prepare(
                "INSERT INTO guest_tokens_history
                     (original_id, token, room_number, customer_id,
                      hours_of_stay, issued_at, expires_at, moved_at, reason, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'superseded', ?)"
            );
            $hist->bind_param(
                'issiisss',
                $revokeRow['id'],
                $revokeRow['token'],
                $revokeRow['room_number'],
                $revokeRow['customer_id'],
                $revokeRow['hours_of_stay'],
                $revokeRow['created_at'],
                $revokeRow['expires_at'],
                $revokeRow['created_by']
            );
            $histOk = $hist->execute();
            $hist->close();

            if ($histOk) {
                // ★ Free the room in the same transaction.
                // Occupancy on the room board, dashboard metrics and Guest
                // Record is derived from customerstbl.status, so deleting only
                // the token left the linked guest 'checked-in' and the room
                // stayed occupied until their stay time ran out.
                $custId   = (int)($revokeRow['customer_id'] ?? 0);
                $custStmt = null;
                if ($custId > 0) {
                    $custStmt = $conn->prepare(
                        "UPDATE customerstbl
                         SET    status         = 'checked-out',
                                checked_out_at = COALESCE(checked_out_at, NOW())
                         WHERE  status = 'checked-in'
                           AND  id     = ?"
                    );
                    $custStmt->bind_param('i', $custId);
                } elseif (!empty($revokeRow['expires_at']) && $revokeRow['expires_at'] > marle_db_now($conn)) {
                    // Legacy tokens without a customer link: fall back to the
                    // room, but only while this token is still active.
                    //
                    // Both sides of that comparison are the DATABASE's own wall clock
                    // (includes/server_clock.php): `expires_at` is a MySQL DATETIME and
                    // marle_db_now() is this same connection's NOW(), so the two are
                    // compared as strings in one clock. It used to read
                    // `strtotime($revokeRow['expires_at']) > time()`, which parses the
                    // hotel's stamp in PHP's timezone and weighs it against PHP's clock —
                    // six hours apart on this machine — so a token that had already run
                    // out still counted as active and revoking it checked a room out
                    // while the hotel still had a guest in it.
                    $custStmt = $conn->prepare(
                        "UPDATE customerstbl
                         SET    status         = 'checked-out',
                                checked_out_at = COALESCE(checked_out_at, NOW())
                         WHERE  status  = 'checked-in'
                           AND  room_no = ?
                           AND  date_to > NOW()"
                    );
                    $custStmt->bind_param('s', $revokeRow['room_number']);
                }
                $custOk = true;
                if ($custStmt) {
                    $custOk = $custStmt->execute();
                    $custStmt->close();
                }

                if ($custOk) {
                    $del = $conn->prepare("DELETE FROM guest_tokens WHERE id = ?");
                    $del->bind_param('i', $tokenId);
                    $del->execute();
                    $del->close();
                    $conn->commit();
                    $revokeOk = true;
                } else {
                    $conn->rollback();
                }
            } else {
                $conn->rollback();
            }
        } else {
            $conn->rollback();
        }

        if ($revokeOk) {
            log_event($conn, 'token_revoked', 'guest_token', $tokenId, 'success', 'Room ' . ($revokeRow['room_number'] ?? '?') . ' — guest checked out, room released');
            $notif     = 'Token revoked. Room <strong>' . htmlspecialchars($revokeRow['room_number'] ?? '?')
                       . '</strong> is now available and the guest will be redirected to login on their next action.';
            $notifType = 'warning';
        } else {
            $notif     = 'Revoke failed — token not found or could not be archived. Please try again.';
            $notifType = 'danger';
        }
    }
}

// ── Fetch active tokens ────────────────────────────────────────────────────────
$activeTokens = [];
$resActive = mysqli_query($conn,
    "SELECT id, token, room_number, customer_id, hours_of_stay, expires_at, UNIX_TIMESTAMP(expires_at) AS expires_at_unix, created_at, created_by
     FROM guest_tokens
     WHERE expires_at > NOW()
     ORDER BY created_at DESC"
);
while ($row = mysqli_fetch_assoc($resActive)) {
    $activeTokens[] = $row;
}

// ── Stay details (incl. any statutory discount) for the cards + print slips ──
$tokenCustomers = [];
$tokenCustomerIds = [];
foreach ($activeTokens as $tkRow) {
    if (!empty($tkRow['customer_id'])) {
        $tokenCustomerIds[] = (int)$tkRow['customer_id'];
    }
}
if (!empty($tokenCustomerIds)) {
    $customerPlaceholders = implode(',', array_fill(0, count($tokenCustomerIds), '?'));
    $custMetaStmt = $conn->prepare(
        "SELECT id, room_no, persons, plate_no, discount_type, discount_id_no, discount_percent,
                gross_amount, vat_exempt_amount, discount_amount, net_amount
         FROM customerstbl WHERE id IN ($customerPlaceholders)"
    );
    if ($custMetaStmt) {
        $custMetaStmt->bind_param(str_repeat('i', count($tokenCustomerIds)), ...$tokenCustomerIds);
        $custMetaStmt->execute();
        $custMetaRes = $custMetaStmt->get_result();
        while ($customerRow = $custMetaRes->fetch_assoc()) {
            $tokenCustomers[(int)$customerRow['id']] = $customerRow;
        }
        $custMetaStmt->close();
    }
}

// ── Fetch recently expired (last 20) ──────────────────────────────────────────
$expiredTokens = [];
$resExpired = mysqli_query($conn,
    "SELECT id, token, room_number, hours_of_stay, expires_at
     FROM guest_tokens
     WHERE expires_at <= NOW()
     ORDER BY expires_at DESC
     LIMIT 20"
);
while ($row = mysqli_fetch_assoc($resExpired)) {
    $expiredTokens[] = $row;
}

// ── Fetch room metadata + occupied state for the room picker ──────────────────
$roomMeta = [];
$resMeta = mysqli_query($conn,
    "SELECT room_number, rooms AS room_type, maintenance_status FROM roomstbl ORDER BY CAST(room_number AS UNSIGNED) ASC"
);
while ($row = mysqli_fetch_assoc($resMeta)) {
    $roomMeta[$row['room_number']] = $row;
}

$occupiedRoomNumbers = [];
$resOccupied = mysqli_query($conn,
    "SELECT room_no FROM customerstbl WHERE status = 'checked-in' AND date_to > NOW()"
);
while ($row = mysqli_fetch_assoc($resOccupied)) {
    $occupiedRoomNumbers[] = (string)$row['room_no'];
}

// ── Fetch the transaction ledger for every occupied room ───────────────────────
// Same filters as process_mark_all_paid.php so the amount shown on a card is
// exactly the amount the Collect Payment action will charge.
$roomTx     = [];
$roomUnpaid = [];
$ledgerRooms = [];
foreach ($activeTokens as $tkRow) {
    $rn = trim((string)($tkRow['room_number'] ?? ''));
    if ($rn !== '') {
        $ledgerRooms[$rn] = true;
    }
}
$ledgerRooms = array_keys($ledgerRooms);

if (!empty($ledgerRooms)) {
    $placeholders = implode(',', array_fill(0, count($ledgerRooms), '?'));
    $bindTypes    = str_repeat('s', count($ledgerRooms));
    $ledgerSql    = "SELECT th.id, th.transaction_type, th.amount, th.status, th.created_at, th.room_number
                     FROM transaction_history th
                     WHERE TRIM(th.room_number) IN ($placeholders)
                       AND th.archived_at IS NULL
                       AND (
                           -- check_in has NO EXISTS guard: tokens are swept/revoked
                           -- (archived to guest_tokens_history) while charges stay unpaid
                           (th.transaction_type = 'check_in' OR
                            (th.transaction_type = 'extension' AND EXISTS (SELECT 1 FROM extension_history eh  WHERE eh.id  = th.reference_id)))
                        OR (th.transaction_type = 'service'   AND EXISTS (SELECT 1 FROM service_requests  sr  WHERE sr.id  = th.reference_id))
                        OR (th.transaction_type = 'food'      AND EXISTS (SELECT 1 FROM food_order_items foi WHERE foi.id = th.reference_id))
                       )
                     ORDER BY th.created_at DESC, th.id DESC";
    $ledgerStmt = $conn->prepare($ledgerSql);
    if ($ledgerStmt) {
        $ledgerStmt->bind_param($bindTypes, ...$ledgerRooms);
        $ledgerStmt->execute();
        $ledgerRes = $ledgerStmt->get_result();
        while ($ledgerRow = $ledgerRes->fetch_assoc()) {
            $rn = trim((string)$ledgerRow['room_number']);
            $roomTx[$rn][] = $ledgerRow;
            if (($ledgerRow['status'] ?? '') === 'unpaid') {
                $roomUnpaid[$rn][] = $ledgerRow;
            }
        }
        $ledgerStmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="<?= ui_html_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-4Q6Gf2aSP4eDXB8Miphtr37CMZZQ5oXLH2yaXMJ2w8e2ZtHTl7GptT4jmndRuHDT" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="stylesheet" href="../css/adminDB.css">
    <?php require __DIR__ . '/../includes/staff_icons.php'; ?>
    <link rel="stylesheet" href="../css/sidebar-theme.css?v=20260930">
    <link rel="stylesheet" href="../css/staff-footer.css?v=20261021">
    <link rel="stylesheet" href="../css/staff-tokens.css?v=20261021">
    <title>Guest Token Manager</title>
    <style>
        .token-badge {
            font-family: 'Courier New', monospace;
            font-size: 1rem;
            letter-spacing: 2px;
            background: #fff3cd;
            color: #333;
            padding: 3px 10px;
            border-radius: 6px;
            border: 1px solid #ffc107;
            user-select: all;
        }
        .token-expired {
            background: #f8f9fa;
            border-color: #dee2e6;
            color: #adb5bd;
            text-decoration: line-through;
        }
        .copy-btn {
            background: none; border: none; padding: 0 4px;
            color: #adb5bd; cursor: pointer; line-height: 1;
        }
        .copy-btn:hover { color: #933310; }
        .section-title {
            font-size: 0.95rem; font-weight: 600; color: #933310;
            padding-bottom: 6px; border-bottom: 2px solid #ffb60a;
            margin-bottom: 1rem;
        }
        .table th { font-size: 0.8rem; color: #6c757d; text-transform: uppercase; }
        .table td { font-size: 0.88rem; vertical-align: middle; }

        /* ── Room transactions panel ─────────────────────────────── */
        .token-card-tx { margin-top: 10px; border-top: 1px dashed #e3ded4; padding-top: 8px; }
        .token-card-tx-toggle {
            display: flex; align-items: center; justify-content: space-between; gap: 6px;
            width: 100%; background: none; border: 0; padding: 2px 0;
            font-size: 0.78rem; font-weight: 600; color: #933310;
        }
        .token-card-tx-count { font-weight: 400; color: #6c757d; }
        .token-card-tx-chev { font-size: 0.7rem; transition: transform 0.2s ease; }
        .token-card-tx-toggle.open .token-card-tx-chev { transform: rotate(180deg); }
        .token-card-tx-chip { font-size: 0.66rem; font-weight: 700; padding: 1px 7px; border-radius: 999px; }
        .token-card-tx-chip-unpaid { background: #fde2e0; color: #b42318; }
        .token-card-tx-chip-paid { background: #e6f4ea; color: #198754; }
        .token-card-tx-body { margin-top: 8px; }
        .token-card-tx-row { display: flex; justify-content: space-between; font-size: 0.76rem; padding: 1px 0; }
        .token-card-tx-counts { font-size: 0.72rem; color: #6c757d; margin: 4px 0 8px; }
        .token-card-collect-btn {
            width: 100%; font-size: 0.75rem; font-weight: 600; padding: 3px 10px;
            border: 1px solid #ffb60a; background: #fff7e2; color: #933310; border-radius: 6px;
        }
        .token-card-collect-btn:hover { background: #ffb60a; color: #fff; }
        .token-card-collect-btn:disabled { opacity: 0.6; }
        .token-card-tx-empty { font-size: 0.72rem; color: #adb5bd; }
        .token-card-tx-list { margin-top: 6px; max-height: 170px; overflow-y: auto; }
        .token-card-tx-item {
            display: flex; flex-wrap: wrap; gap: 4px 8px; align-items: center;
            font-size: 0.72rem; padding: 3px 0; border-bottom: 1px solid #f1ede6;
        }
        .token-card-tx-item:last-child { border-bottom: 0; }
        .token-card-tx-item-type { font-weight: 600; color: #333; min-width: 74px; }
        .token-card-tx-item-amt { color: #333; }
        .token-card-tx-item-status { font-size: 0.64rem; padding: 0 6px; border-radius: 999px; background: #eef1f4; color: #495057; }
        .token-card-tx-item-status.status-paid { background: #e6f4ea; color: #198754; }
        .token-card-tx-item-status.status-unpaid { background: #fde2e0; color: #b42318; }
        .token-card-tx-item-status.status-denied { background: #f1f3f5; color: #868e96; }
        .token-card-tx-item-time { color: #adb5bd; margin-left: auto; }

        .countdown-wrap { display: flex; flex-direction: column; gap: 2px; }
        .countdown-bar-bg {
            height: 5px; border-radius: 3px;
            background: #e9ecef; overflow: hidden; width: 100px;
        }
        .countdown-bar { height: 100%; border-radius: 3px; transition: width 1s linear; }
        .countdown-text { font-size: 0.78rem; font-weight: 600; color: #333; }
        .countdown-text.urgent { color: #dc3545; }
        .hrs-preset {
            padding: 5px 14px;
            border: 0.5px solid #ced4da;
            border-radius: 20px;
            background: #fff;
            font-size: 12px;
            font-weight: 500;
            color: #6c757d;
            cursor: pointer;
            transition: all 0.12s;
        }
        .hrs-preset:hover {
            border-color: #16a34a;
            color: #16a34a;
            background: #f0fdf4;
        }

        /* ── Room Picker Grid ── */
        .room-picker-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }
        .room-tile {
            border: 2px solid transparent;
            border-radius: 10px;
            padding: 8px 4px 6px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.12s, box-shadow 0.12s, border-color 0.12s;
            user-select: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
        }
        .room-tile-num {
            font-size: 1.15rem;
            font-weight: 700;
            line-height: 1;
            color: inherit;
        }
        .room-tile-label {
            font-size: 0.64rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: inherit;
            opacity: 0.85;
            line-height: 1.2;
        }
        /* Available */
        .room-tile-available {
            background: #f0fdf4;
            border-color: #86efac;
            color: #166534;
        }
        .room-tile-available:hover {
            background: #dcfce7;
            border-color: #4ade80;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(22,101,52,0.15);
        }
        .room-tile-available.selected {
            background: #16a34a;
            border-color: #15803d;
            color: #fff;
            box-shadow: 0 4px 12px rgba(22,163,74,0.35);
        }
        /* Occupied */
        .room-tile-occupied {
            background: #f3f4f6;
            border-color: #d1d5db;
            color: #9ca3af;
        }
        /* Maintenance */
        .room-tile-maintenance {
            background: #fefce8;
            border-color: #fde047;
            color: #a16207;
            opacity: 0.65;
        }
        /* Shared disabled state */
        .room-tile-disabled {
            cursor: not-allowed;
            pointer-events: none;
        }

        /* ── Active Token Card Grid (Option B) ── */
        .token-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 12px;
        }
        .token-card-b {
            border: 1px solid #dee2e6;
            border-radius: 12px;
            padding: 14px;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 0;
            transition: border-color 0.15s;
        }
        .token-card-b.urgency-ok     { border-color: #86efac; }
        .token-card-b.urgency-warn   { border-color: #ffc107; }
        .token-card-b.urgency-danger { border-color: #f1aeb5; }

        .token-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .token-card-room-num {
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 1;
            color: #212529;
        }
        .token-card-b.urgency-danger .token-card-room-num { color: #b02a37; }
        .token-card-room-sub {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #6c757d;
            margin-top: 3px;
        }
        .token-mono-b {
            font-family: 'Courier New', monospace;
            font-size: 0.72rem;
            letter-spacing: 1px;
            background: #fff3cd;
            color: #664d03;
            padding: 3px 7px;
            border-radius: 5px;
            border: 1px solid #ffc107;
            user-select: all;
            white-space: nowrap;
        }
        .token-card-divider {
            border: none;
            border-top: 1px solid #f1f3f5;
            margin: 0 0 10px 0;
        }
        .token-card-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }
        .token-card-meta-label { font-size: 0.72rem; color: #6c757d; }
        .token-card-meta-val   { font-size: 0.78rem; font-weight: 600; color: #212529; }
        .token-card-b.urgency-danger .token-card-expires-val { color: #b02a37; }

        .token-card-bar-bg {
            background: #e9ecef;
            border-radius: 4px;
            height: 5px;
            overflow: hidden;
            margin: 8px 0 3px;
        }
        .token-card-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 1s linear;
        }
        .token-card-countdown-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .token-card-countdown-text {
            font-size: 0.78rem;
            font-weight: 700;
            color: #198754;
        }
        .token-card-countdown-text.warn   { color: #997404; }
        .token-card-countdown-text.urgent { color: #b02a37; }
        .token-card-issued {
            font-size: 0.7rem;
            color: #adb5bd;
        }
        .token-card-actions {
            display: flex;
            gap: 6px;
            margin-top: auto;
        }
        .token-card-copy-btn {
            flex: 1;
            padding: 5px 0;
            font-size: 0.75rem;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            background: #f8f9fa;
            color: #495057;
            cursor: pointer;
            transition: background 0.12s;
            text-align: center;
        }
        .token-card-copy-btn:hover { background: #e9ecef; }
        .token-card-revoke-btn {
            flex: 1;
            padding: 5px 0;
            font-size: 0.75rem;
            border-radius: 8px;
            border: 1px solid #f1aeb5;
            background: transparent;
            color: #b02a37;
            cursor: pointer;
            transition: background 0.12s;
            text-align: center;
        }
        .token-card-revoke-btn:hover { background: #fff5f5; }
        .urgent-pulse {
            display: inline-block;
            width: 7px; height: 7px;
            border-radius: 50%;
            background: #dc3545;
            margin-right: 4px;
            vertical-align: middle;
            animation: pulse 1s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.3; }
        }
    </style>
    <link rel="stylesheet" href="../css/staff-logout.css">
<?php marle_server_clock_assets(); ?>
<?php require __DIR__ . '/../includes/staff_theme.php'; ?>
</head>
<body class="staff-page staff-tokens-page staff-admin">

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<?php require __DIR__ . '/../includes/staff_sidebar.php'; ?>

<!-- ═══════════════ MAIN CONTENT ══════════════ -->
<div class="content">

    <?php
    $staffPageTitle  = 'Guest Tokens';
    $staffPageNote   = 'Generate portal access tokens at the front desk and hand them to guests.';
    $staffPageCrumbs = [['Dashboard', 'adminDB.php'], ['Guest Tokens', null]];
    require __DIR__ . '/../includes/staff_page_head.php';
    ?>

    <?php if (!empty($notif)): ?>
        <div class="alert alert-<?= $notifType ?> alert-dismissible fade show auto-hide" role="alert">
            <?= $notif ?>
            <?php if ($issuedSlipPayload !== ''): ?>
                <button type="button" class="btn btn-sm ms-2"
                        style="background:#933310; color:#fff;"
                        data-slip="<?= htmlspecialchars($issuedSlipPayload, ENT_QUOTES) ?>"
                        onclick="printTokenSlipFrom(this)">
                    <i class="bi bi-printer me-1"></i>Print guest slip
                </button>
            <?php endif; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['collected'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-1"></i>Payment collected for
            <strong>Room <?= htmlspecialchars((string)($_GET['room'] ?? '')) ?></strong>
            &middot; Change due: <strong>&#8369;<?= number_format((float)($_GET['change'] ?? 0), 2) ?></strong>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ─── Generate Token Panel ──────────────────────── -->
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <p class="section-title">
                        <i class="bi bi-plus-circle me-1"></i>Issue Token
                    </p>
                    <p class="text-muted small mb-3">
                        Select the guest's room and their length of stay.
                        A check-in transaction is automatically logged.
                    </p>

                    <form method="POST" action="../admin/GuestTokens.php">
                        <input type="hidden" name="action" value="generate">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Room</label>
                            <input type="hidden" name="room_number" id="selectedRoomInput" required>
                            <div id="roomPickerError" class="text-danger small mb-1" style="display:none;">Please select a room.</div>
                            <div class="room-picker-grid">
                                <?php for ($r = 1; $r <= 16; $r++): ?>
                                    <?php
                                        $rStr     = (string)$r;
                                        $meta     = $roomMeta[$rStr] ?? null;
                                        $type     = $meta['room_type'] ?? 'Unknown';
                                        $isMaint  = !empty($meta['maintenance_status']);
                                        $isOcc    = in_array($rStr, $occupiedRoomNumbers);
                                        $disabled = $isMaint || $isOcc;
                                        $stateClass = $isMaint ? 'room-tile-maintenance' : ($isOcc ? 'room-tile-occupied' : 'room-tile-available');
                                        $label    = $isMaint ? 'Maintenance' : ($isOcc ? 'Occupied' : $type);
                                    ?>
                                    <div class="room-tile <?= $stateClass ?> <?= $disabled ? 'room-tile-disabled' : '' ?>"
                                         data-room="<?= $r ?>"
                                         <?= $disabled ? '' : 'onclick="selectRoom(this)"' ?>>
                                        <span class="room-tile-num"><?= $r ?></span>
                                        <span class="room-tile-label"><?= htmlspecialchars($label) ?></span>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Number of Persons</label>
                            <div style="display:flex;align-items:center;gap:12px;">
                                <div style="display:inline-flex;align-items:center;border:0.5px solid #ced4da;border-radius:8px;overflow:hidden;">
                                    <button type="button" onclick="stepPersons(-1)"
                                            style="width:36px;height:36px;border:none;background:#f8f9fa;color:#333;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.12s;"
                                            onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='#f8f9fa'">−</button>
                                    <div id="personsDisplay"
                                         style="width:48px;height:36px;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:600;border-left:0.5px solid #dee2e6;border-right:0.5px solid #dee2e6;background:#fff;color:#333;">2</div>
                                    <button type="button" onclick="stepPersons(1)"
                                            style="width:36px;height:36px;border:none;background:#f8f9fa;color:#333;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.12s;"
                                            onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='#f8f9fa'">+</button>
                                </div>
                                <span id="personsLabel" style="font-size:13px;color:#6c757d;">2 persons</span>
                            </div>
                            <input type="hidden" name="persons" id="personsInput" value="2">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Plate Number</label>
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                                <label style="position:relative;width:38px;height:22px;flex-shrink:0;">
                                    <input type="checkbox" id="vehicleToggle" onchange="togglePlate()" style="opacity:0;width:0;height:0;position:absolute;">
                                    <span id="toggleSlider" style="position:absolute;inset:0;background:#ced4da;border-radius:22px;cursor:pointer;transition:background 0.2s;">
                                        <span id="toggleThumb" style="position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:transform 0.2s;display:block;"></span>
                                    </span>
                                </label>
                                <span style="font-size:13px;color:#6c757d;">Guest arrived by vehicle</span>
                            </div>
                            <div id="plateReveal" style="display:none;">
                                <div style="position:relative;">
                                    <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:14px;">🚗</span>
                                    <input type="text" name="plate_number" id="plateInput" class="form-control plate-input"
                                           placeholder="ABC-1234" maxlength="9"
                                           style="padding-left:34px;font-family:monospace;letter-spacing:1px;text-transform:uppercase;"
                                           oninput="updatePlatePreview(this.value)">
                                </div>
                                <div style="margin-top:6px;">
                                    <span id="platePreview" style="font-family:monospace;font-size:13px;font-weight:600;letter-spacing:2px;background:#f8f9fa;border:0.5px solid #dee2e6;border-radius:6px;padding:3px 10px;color:#333;display:inline-block;min-width:80px;text-align:center;">—</span>
                                </div>
                                <div class="invalid-feedback">Invalid plate format. Use ABC 1234 or ABC-1234.</div>
                            </div>
                            <div class="form-text" id="plateHint">No plate recorded — walk-in.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Hours of Stay</label>
                            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
                                <button type="button" class="hrs-preset" onclick="selectPreset(this,3)">3 hrs</button>
                                <button type="button" class="hrs-preset" onclick="selectPreset(this,6)">6 hrs</button>
                                <button type="button" class="hrs-preset" onclick="selectPreset(this,12)">12 hrs</button>
                                <button type="button" class="hrs-preset" onclick="selectPreset(this,24)">1 day</button>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <input type="number" name="hours_of_stay" id="hoursInput"
                                       min="1" max="720" placeholder="1 – 720" required
                                       style="width:90px;padding:6px 10px;border:0.5px solid #ced4da;border-radius:8px;font-size:14px;font-weight:500;text-align:center;"
                                       oninput="clearPresets();updateCheckout(this.value)">
                                <span style="font-size:13px;color:#6c757d;">hrs &nbsp;·&nbsp; checkout ~<strong id="checkoutPreview">—</strong></span>
                            </div>
                            <div class="form-text">Tap a preset or type any value from 1 to 720.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Discount (Philippines)</label>
                            <select name="discount_type" id="discountType" class="form-select form-select-sm mb-2"
                                    onchange="updateDiscountPreview()">
                                <?php foreach (marle_discount_types() as $dValue => $dLabel): ?>
                                    <option value="<?= htmlspecialchars($dValue) ?>" <?= $dValue === 'none' ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div id="discountIdWrap" style="display:none;">
                                <input type="text" name="discount_id_no" id="discountIdNo"
                                       class="form-control form-control-sm" maxlength="20"
                                       placeholder="e.g. 2026-04812" autocomplete="off"
                                       inputmode="numeric" spellcheck="false"
                                       title="Senior Citizen (RA 9994) YYYY-NNNNN · PWD (RA 7277) RR-PPMM-BBB-NNNNNNN"
                                       oninput="updateDiscountPreview(); applyDiscountIdRules(true);">
                                <div class="form-text" id="discountIdHint"></div>
                            </div>

                            <div id="discountPctWrap" style="display:none;">
                                <input type="number" name="discount_percent" id="discountPercent"
                                       class="form-control form-control-sm mb-2" min="1" max="50" step="1"
                                       value="20" oninput="updateDiscountPreview()">
                            </div>

                            <div id="discountPreview" class="form-text"></div>
                        </div>

                        <button type="submit" class="btn w-100 fw-semibold"
                style="background:#ffb60a; color:#fff;"
                onclick="return validateRoomPicker()">
            <i class="bi bi-key me-1"></i>Generate &amp; Issue Token
        </button>
    </form>
<script>
/* ── Plate toggle ── */
    function togglePlate() {
        const on = document.getElementById('vehicleToggle').checked;
        const slider = document.getElementById('toggleSlider');
        const thumb  = document.getElementById('toggleThumb');
        slider.style.background = on ? '#16a34a' : '#ced4da';
        thumb.style.transform   = on ? 'translateX(16px)' : 'translateX(0)';
        document.getElementById('plateReveal').style.display = on ? '' : 'none';
        document.getElementById('plateHint').textContent = on
            ? 'Format: ABC-1234 or ABC 1234'
            : 'No plate recorded — walk-in.';
        if (!on) {
            document.getElementById('plateInput').value = '';
            document.getElementById('platePreview').textContent = '—';
        }
    }
    function updatePlatePreview(v) {
        document.getElementById('platePreview').textContent = v.trim().toUpperCase() || '—';
    }

    /* ── Hours presets ── */
    /* The checkout preview is a promise made to the guest, so it is computed from
       the SERVER's clock and printed in the HOTEL's timezone
       (includes/server_clock.php) — not from a front-desk PC's clock, which may be
       hours off, and not from its timezone either. */
    function checkoutTime(hrs) {
        const hours = parseInt(hrs, 10);
        if (!hours || hours < 0) return '—';
        if (window.MarleClock) {
            const nowMs      = MarleClock.nowMs();
            const checkoutMs = nowMs + hours * 3600000;
            const dayDiff    = MarleClock.dayIndex(checkoutMs) - MarleClock.dayIndex(nowMs);
            return MarleClock.formatClockMs(checkoutMs) + (dayDiff > 0 ? ' +' + dayDiff + 'd' : '');
        }
        const d = new Date();   // marle-clock-ok: only reached when window.MarleClock is absent — the branch above returns the hotel's clock
        d.setHours(d.getHours() + hours);
        // Fallback only (the branch above uses the hotel's clock). Format pinned so a
        // 24-hour device locale does not print "15:00" where the rest of the board
        // prints "3:00 PM".
        const time = d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        const isNext = d.getDate() !== new Date().getDate();   // marle-clock-ok: the same no-clock fallback as the line above
        return time + (isNext ? ' +1d' : '');
    }
    function selectPreset(btn, val) {
        document.querySelectorAll('.hrs-preset').forEach(b => {
            b.style.background    = '';
            b.style.borderColor   = '';
            b.style.color         = '';
        });
        btn.style.background  = '#16a34a';
        btn.style.borderColor = '#15803d';
        btn.style.color       = '#fff';
        document.getElementById('hoursInput').value = val;
        document.getElementById('checkoutPreview').textContent = checkoutTime(val);
    }
    function clearPresets() {
        document.querySelectorAll('.hrs-preset').forEach(b => {
            b.style.background  = '';
            b.style.borderColor = '';
            b.style.color       = '';
        });
    }
    function updateCheckout(val) {
        document.getElementById('checkoutPreview').textContent = val ? checkoutTime(val) : '—';
    }

    let personsVal = 2;
function stepPersons(d) {
    personsVal = Math.min(6, Math.max(1, personsVal + d));
    document.getElementById('personsDisplay').textContent = personsVal;
    document.getElementById('personsInput').value = personsVal;
    document.getElementById('personsLabel').textContent = personsVal + ' person' + (personsVal > 1 ? 's' : '');
}

function selectRoom(tile) {
    document.querySelectorAll('.room-tile-available').forEach(t => t.classList.remove('selected'));
    tile.classList.add('selected');
    document.getElementById('selectedRoomInput').value = tile.dataset.room;
    document.getElementById('roomPickerError').style.display = 'none';
}
function validateRoomPicker() {
    if (!document.getElementById('selectedRoomInput').value) {
        document.getElementById('roomPickerError').style.display = '';
        return false;
    }
    return true;
}
</script>
                </div>
            </div>
        </div>

        <!-- ─── Occupied Rooms ─────────────────────────────── -->
        <div class="col-12 col-lg-8">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <p class="section-title">
                        <i class="bi bi-door-open me-1"></i>Occupied Rooms
                        <span class="badge ms-2" style="background:#ffb60a; color:#333; font-size:0.8rem;">
                            <?= count($activeTokens) ?>
                        </span>
                    </p>

                    <?php if (empty($activeTokens)): ?>
                        <div class="alert alert-info mb-0">
                            No occupied rooms. Issue a token from the panel on the left to check a guest in.
                        </div>
                    <?php else: ?>
                        <div class="token-cards-grid token-count-<?= min(count($activeTokens), 5) ?>">
                            <?php foreach ($activeTokens as $tk):
                                $expiresUnix = (int)$tk['expires_at_unix'];
                                $totalSecs   = (int)$tk['hours_of_stay'] * 3600;
                                $secsLeft    = max(0, $expiresUnix - time());
                                $pct         = $totalSecs > 0 ? round(($secsLeft / $totalSecs) * 100) : 0;
                                if ($pct >= 50)      { $urgencyClass = 'urgency-ok'; }
                                elseif ($pct >= 20)  { $urgencyClass = 'urgency-warn'; }
                                else                 { $urgencyClass = 'urgency-danger'; }

                                // Look up room type for subtitle
                                $roomType = $roomMeta[$tk['room_number']]['room_type'] ?? '';

                                // Statutory discount recorded for this stay (if any)
                                $cardCustomer   = [];
                                $cardCustomerId = (int)($tk['customer_id'] ?? 0);
                                if ($cardCustomerId > 0 && isset($tokenCustomers[$cardCustomerId])) {
                                    $cardCustomer = $tokenCustomers[$cardCustomerId];
                                }
                                $cardDiscount = marle_discount_from_row($cardCustomer);
                                $slipPayload  = marle_token_slip_payload(
                                    $tk,
                                    (string)$roomType,
                                    $cardCustomer,
                                    $cardDiscount,
                                    (string)($cardCustomer['discount_type'] ?? 'none'),
                                    (string)($cardCustomer['discount_id_no'] ?? '')
                                );

                                // ── Ledger summary for this room's transactions panel ──
                                $rn          = trim((string)$tk['room_number']);
                                $roomItems   = $roomTx[$rn] ?? [];
                                $unpaidItems = $roomUnpaid[$rn] ?? [];
                                $unpaidTotal = 0.0;
                                $typeCounts  = ['check_in' => 0, 'extension' => 0, 'service' => 0, 'food' => 0];
                                foreach ($unpaidItems as $u) {
                                    $unpaidTotal += (float)$u['amount'];
                                    if (isset($typeCounts[$u['transaction_type']])) {
                                        $typeCounts[$u['transaction_type']]++;
                                    }
                                }
                                $hasUnpaid = !empty($unpaidItems);
                            ?>
                            <div class="token-card-b <?= $urgencyClass ?>">

                                <!-- Top row: room number + token code -->
                                <div class="token-card-top">
                                    <div>
                                        <div class="token-card-room-num">Room <?= htmlspecialchars($tk['room_number']) ?></div>
                                        <div class="token-card-room-sub"><?= htmlspecialchars($roomType) ?> &middot; <?= (int)$tk['hours_of_stay'] ?>h stay</div>
                                        <?php if ($cardDiscount['has_discount']): ?>
                                            <span class="token-card-discount-badge"
                                                  title="<?= htmlspecialchars(marle_discount_breakdown_html($cardDiscount), ENT_QUOTES) ?>">
                                                <i class="bi bi-percent"></i>
                                                <?= htmlspecialchars(marle_discount_label((string)($cardCustomer['discount_type'] ?? 'none'))) ?>
                                                &minus;<?= rtrim(rtrim(number_format((float)$cardDiscount['percent'], 2, '.', ''), '0'), '.') ?>%<br>
                                                <?= marle_peso((float)$cardDiscount['net_amount']) ?> payable
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="token-mono-b"><?= formatToken($tk['token']) ?></span>
                                </div>

                                <hr class="token-card-divider">

                                <!-- Meta rows -->
                                <div class="token-card-meta">
                                    <span class="token-card-meta-label">Expires</span>
                                    <span class="token-card-meta-val token-card-expires-val">
                                        <?= date('g:i a', strtotime($tk['expires_at'])) ?>
                                    </span>
                                </div>
                                <div class="token-card-meta">
                                    <span class="token-card-meta-label">Issued by</span>
                                    <span class="token-card-meta-val" style="color:#6c757d; font-weight:400;">
                                        <?= htmlspecialchars($tk['created_by'] ?? '—') ?>
                                    </span>
                                </div>

                                <!-- Progress bar -->
                                <div class="token-card-bar-bg">
                                    <div class="token-card-bar"
                                         style="width:100%;"
                                         data-bar="true"></div>
                                </div>

                                <!-- Countdown + urgency indicator -->
                                <div class="token-card-countdown-row"
                                     data-left="<?= $secsLeft ?>"
                                     data-expires="<?= $expiresUnix ?>"
                                     data-total="<?= $totalSecs ?>">
                                    <span class="token-card-countdown-text">—</span>
                                    <span class="token-card-issued">
                                        <?= date('M d', strtotime($tk['expires_at'])) ?>
                                    </span>
                                </div>

                                <!-- Actions -->
                                <div class="token-card-actions">
                                    <button class="token-card-copy-btn"
                                            onclick="copyTokenCard('<?= formatToken($tk['token']) ?>', this)">
                                        <i class="bi bi-clipboard me-1"></i>Copy
                                    </button>
                                    <button type="button" class="token-card-print-btn"
                                            data-slip="<?= htmlspecialchars($slipPayload, ENT_QUOTES) ?>"
                                            onclick="printTokenSlipFrom(this)">
                                        <i class="bi bi-printer me-1"></i>Print
                                    </button>
                                    <form method="POST" action="../admin/GuestTokens.php" class="d-contents"
                                          onsubmit="return confirm('Revoke this token? The guest will lose portal access immediately.')">
                                        <input type="hidden" name="action"   value="revoke">
                                        <input type="hidden" name="token_id" value="<?= (int)$tk['id'] ?>">
                                        <button type="submit" class="token-card-revoke-btn">
                                            <i class="bi bi-x-circle me-1"></i>Revoke
                                        </button>
                                    </form>
                                </div>

                                <!-- Room transactions -->
                                <div class="token-card-tx">
                                    <button type="button" class="token-card-tx-toggle" onclick="toggleCardTx(this)" aria-expanded="false">
                                        <span><i class="bi bi-receipt me-1"></i>Transactions
                                            <span class="token-card-tx-count">(<?= count($roomItems) ?>)</span>
                                        </span>
                                        <?php if ($hasUnpaid): ?>
                                            <span class="token-card-tx-chip token-card-tx-chip-unpaid">Unpaid</span>
                                        <?php else: ?>
                                            <span class="token-card-tx-chip token-card-tx-chip-paid">All paid</span>
                                        <?php endif; ?>
                                        <i class="bi bi-chevron-down token-card-tx-chev"></i>
                                    </button>

                                    <div class="token-card-tx-body" hidden>
                                        <div class="token-card-tx-row">
                                            <span class="token-card-meta-label">Unpaid total</span>
                                            <span class="token-card-meta-val">&#8369;<?= number_format($unpaidTotal, 2) ?></span>
                                        </div>
                                        <div class="token-card-tx-row">
                                            <span class="token-card-meta-label">Unpaid items</span>
                                            <span class="token-card-meta-val"><?= count($unpaidItems) ?></span>
                                        </div>
                                        <div class="token-card-tx-counts">
                                            <?= (int)$typeCounts['food'] ?> food &middot;
                                            <?= (int)$typeCounts['service'] ?> service &middot;
                                            <?= (int)$typeCounts['extension'] ?> extension &middot;
                                            <?= (int)$typeCounts['check_in'] ?> check-in
                                        </div>

                                        <?php if ($hasUnpaid && has_capability('collect_payments')): ?>
                                            <button type="button"
                                                    class="token-card-collect-btn collect-room-btn"
                                                    data-room="<?= htmlspecialchars($rn, ENT_QUOTES) ?>"
                                                    data-total="<?= number_format($unpaidTotal, 2, '.', '') ?>">
                                                <i class="bi bi-cash-coin me-1"></i>Collect Payment
                                            </button>
                                        <?php endif; ?>

                                        <?php if (empty($roomItems)): ?>
                                            <div class="token-card-tx-empty">No transactions logged for this room.</div>
                                        <?php else: ?>
                                            <div class="token-card-tx-list">
                                                <?php foreach (array_slice($roomItems, 0, 25) as $item): ?>
                                                    <div class="token-card-tx-item">
                                                        <span class="token-card-tx-item-type">
                                                            <?= htmlspecialchars($txTypeLabels[$item['transaction_type']] ?? $item['transaction_type']) ?>
                                                        </span>
                                                        <span class="token-card-tx-item-amt">&#8369;<?= number_format((float)$item['amount'], 2) ?></span>
                                                        <span class="token-card-tx-item-status status-<?= htmlspecialchars((string)$item['status']) ?>">
                                                            <?= htmlspecialchars(ucfirst((string)$item['status'])) ?>
                                                        </span>
                                                        <span class="token-card-tx-item-time">
                                                            <?= date('M d, g:i a', strtotime((string)$item['created_at'])) ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recently Expired -->
            <?php if (!empty($expiredTokens)): ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <p class="section-title text-secondary"
                           style="color:#6c757d !important; border-color:#dee2e6;">
                            <i class="bi bi-clock-history me-1"></i>Recently Expired
                            <small class="fw-normal">(last 20)</small>
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Token</th>
                                        <th>Room</th>
                                        <th>Stay</th>
                                        <th>Expired At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expiredTokens as $et): ?>
                                        <tr class="text-muted">
                                            <td>
                                                <span class="token-badge token-expired">
                                                    <?= formatToken($et['token']) ?>
                                                </span>
                                            </td>
                                            <td>Room <?= htmlspecialchars($et['room_number']) ?></td>
                                            <td><?= (int)$et['hours_of_stay'] ?>h</td>
                                            <td><?= date('M d, g:ia', strtotime($et['expires_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- LOGOUT MODAL -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Logout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p class="fs-5">Are you sure you want to log out?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="../logout.php" class="btn" id="yesBtn">Yes</a>
            </div>
        </div>
    </div>
</div>

<!-- COLLECTION MODAL -->
<div class="modal fade" id="collectPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Collect Room Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <form id="collectPaymentForm" novalidate>
                <div class="modal-body">
                    <div class="row g-3"><div class="col-6"><label class="form-label" for="collectRoomNumber">Room number</label><input class="form-control" id="collectRoomNumber" readonly></div><div class="col-6"><label class="form-label" for="collectAmountDue">Amount due</label><input class="form-control" id="collectAmountDue" readonly></div></div>
                    <div class="mt-3"><label class="form-label" for="collectAmountReceived">Amount received</label><input type="number" class="form-control" id="collectAmountReceived" min="0.01" step="0.01" inputmode="decimal" required></div>
                    <div class="d-flex justify-content-between mt-3"><span class="text-muted">Change</span><strong id="collectChange">&#8369;0.00</strong></div>
                    <div id="collectPaymentError" class="alert alert-danger mt-3 mb-0 d-none" role="alert"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="confirmCollectionBtn">Confirm Collection</button></div>
            </form>
        </div>
    </div>
</div>

<script>
    var activeCollectionButton = null;
    var collectionModal = null;

    function toggleCardTx(btn) {
        var body = btn.parentElement.querySelector('.token-card-tx-body');
        if (!body) return;
        var willOpen = body.hasAttribute('hidden');
        if (willOpen) { body.removeAttribute('hidden'); } else { body.setAttribute('hidden', ''); }
        btn.classList.toggle('open', willOpen);
        btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    }

    function openCollectionModal(roomNumber, totalDue, btn) {
        activeCollectionButton = btn;
        document.getElementById('collectRoomNumber').value = roomNumber;
        document.getElementById('collectAmountDue').value = '\u20B1' + totalDue.toFixed(2);
        document.getElementById('collectAmountDue').dataset.amount = totalDue.toFixed(2);
        document.getElementById('collectAmountReceived').min = totalDue.toFixed(2);
        document.getElementById('collectAmountReceived').value = totalDue.toFixed(2);
        document.getElementById('collectPaymentError').classList.add('d-none');
        updateCollectionChange();
        collectionModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('collectPaymentModal'));
        collectionModal.show();
    }

    function updateCollectionChange() {
        var due = parseFloat(document.getElementById('collectAmountDue').dataset.amount || '0');
        var received = parseFloat(document.getElementById('collectAmountReceived').value) || 0;
        var submitButton = document.getElementById('confirmCollectionBtn');
        var error = document.getElementById('collectPaymentError');
        document.getElementById('collectChange').textContent = '\u20B1' + Math.max(0, received - due).toFixed(2);
        if (received > 0 && received < due) {
            error.textContent = 'Amount received is short by \u20B1' + (due - received).toFixed(2) + '. Pay at least \u20B1' + due.toFixed(2) + '.';
            error.classList.remove('d-none');
            submitButton.disabled = true;
        } else {
            error.classList.add('d-none');
            submitButton.disabled = false;
        }
    }

    function submitCollection(event) {
        event.preventDefault();
        var roomNumber = document.getElementById('collectRoomNumber').value;
        var receivedInput = document.getElementById('collectAmountReceived').value.trim();
        var received = Number(receivedInput);
        var due = Number(document.getElementById('collectAmountDue').dataset.amount || '0');
        var error = document.getElementById('collectPaymentError');
        var submitButton = document.getElementById('confirmCollectionBtn');

        if (!/^\d+(\.\d{1,2})?$/.test(receivedInput) || !Number.isFinite(received) || received <= 0) {
            error.textContent = 'Enter a valid amount received greater than zero.';
            error.classList.remove('d-none');
            return;
        }
        if (received < due) {
            error.textContent = 'Amount received is less than the actual amount due of \u20B1' + due.toFixed(2) + '.';
            error.classList.remove('d-none');
            return;
        }

        error.classList.add('d-none');
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Processing...';
        if (activeCollectionButton) activeCollectionButton.disabled = true;

        fetch('process_mark_all_paid.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'room_number=' + encodeURIComponent(roomNumber)
                + '&amount_received=' + encodeURIComponent(receivedInput)
                + '&csrf_token=' + encodeURIComponent('<?php echo $collectionCsrfToken; ?>')
        })
        .then(function (r) { return r.text().then(function (body) { try { return JSON.parse(body); } catch (e) { throw new Error(body || 'The collection endpoint returned an invalid response.'); } }); })
        .then(function (data) {
            if (!data.success) {
                if ((data.message || '').indexOf('No unpaid items found') !== -1) { window.location.reload(); return; }
                error.textContent = data.message || 'No unpaid items were collected.';
                error.classList.remove('d-none');
                submitButton.disabled = false;
                submitButton.textContent = 'Confirm Collection';
                if (activeCollectionButton) activeCollectionButton.disabled = false;
                return;
            }
            window.location.href = 'GuestTokens.php?collected=1&room=' + encodeURIComponent(roomNumber)
                + '&change=' + encodeURIComponent(Number(data.change_amount || 0).toFixed(2));
        })
        .catch(function (requestError) {
            error.textContent = requestError.message || 'Network error. Please try again.';
            error.classList.remove('d-none');
            submitButton.disabled = false;
            submitButton.textContent = 'Confirm Collection';
            if (activeCollectionButton) activeCollectionButton.disabled = false;
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.collect-room-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openCollectionModal(this.dataset.room, parseFloat(this.dataset.total || '0'), this);
            });
        });
        document.getElementById('collectAmountReceived').addEventListener('input', updateCollectionChange);
        document.getElementById('collectPaymentForm').addEventListener('submit', submitCollection);

        // Clean the collection flash params so a manual refresh does not re-show the alert
        if (window.location.search.indexOf('collected=') !== -1) {
            var cleanUrl = window.location.pathname;
            window.history.replaceState({}, '', cleanUrl);
        }
    });
</script>

<script>
    function copyToken(text, btn) {
        navigator.clipboard.writeText(text).then(function () {
            btn.innerHTML = '<i class="bi bi-check2" style="color:#198754"></i>';
            setTimeout(function () { btn.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 2000);
        });
    }

    function copyTokenCard(text, btn) {
        navigator.clipboard.writeText(text).then(function () {
            btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copied!';
            btn.style.color = '#198754';
            btn.style.borderColor = '#86efac';
            setTimeout(function () {
                btn.innerHTML = '<i class="bi bi-clipboard me-1"></i>Copy';
                btn.style.color = '';
                btn.style.borderColor = '';
            }, 2000);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.alert.auto-hide').forEach(function (el) {
            setTimeout(function () {
                el.classList.remove('show');
                setTimeout(function () { el.remove(); }, 300);
            }, 5000);
        });
    });

    function formatCountdown(secsLeft) {
        if (secsLeft <= 0) return 'Expired';
        const h = Math.floor(secsLeft / 3600);
        const m = Math.floor((secsLeft % 3600) / 60);
        const s = secsLeft % 60;
        if (h > 0) return h + 'h ' + String(m).padStart(2, '0') + 'm';
        if (m > 0) return m + 'm ' + String(s).padStart(2, '0') + 's';
        return s + 's';
    }

    /* Seconds left, counted from the SERVER's clock.
       data-left is what the server said was left the moment it rendered this card
       (includes/server_clock.php). Date.now() is deliberately not used here: a
       reception PC or phone whose clock is off by hours used to render an occupied
       room as "Expired" — while the page header still counted it under
       "OCCUPIED ROOMS". Same database row, two different answers. */
    function remainingSecs(el) {
        const leftAtRender = parseInt(el.dataset.left, 10) || 0;
        if (window.MarleClock) return MarleClock.secsLeft(leftAtRender);
        const expiresUnix = parseInt(el.dataset.expires, 10) || 0;   // pre-clock fallback
        return Math.max(0, expiresUnix - Math.floor(Date.now() / 1000));   // marle-clock-ok: only reached when window.MarleClock is absent
    }

    function tickCountdowns() {
        // ── Legacy .countdown-wrap (kept for any other uses) ──
        document.querySelectorAll('.countdown-wrap').forEach(function (wrap) {
            const totalSecs = parseInt(wrap.dataset.total, 10) || 0;
            const leftSecs  = remainingSecs(wrap);
            const pct       = totalSecs > 0 ? Math.max(0, Math.min(100, (leftSecs / totalSecs) * 100)) : 0;

            const textEl = wrap.querySelector('.countdown-text');
            const barEl  = wrap.querySelector('.countdown-bar');

            textEl.textContent = formatCountdown(leftSecs);
            textEl.className   = 'countdown-text' + (leftSecs < 3600 ? ' urgent' : '');

            let colour = '#198754';
            if (pct < 50) colour = '#ffb60a';
            if (pct < 20) colour = '#dc3545';

            barEl.style.width      = pct + '%';
            barEl.style.background = colour;
        });

        // ── New card grid countdowns ──
        document.querySelectorAll('.token-card-countdown-row').forEach(function (row) {
            const totalSecs = parseInt(row.dataset.total, 10) || 0;
            const leftSecs  = remainingSecs(row);
            const pct       = totalSecs > 0 ? Math.max(0, Math.min(100, (leftSecs / totalSecs) * 100)) : 0;

            const textEl = row.querySelector('.token-card-countdown-text');
            const card   = row.closest('.token-card-b');
            const barEl  = card ? card.querySelector('.token-card-bar') : null;

            // Countdown text + class
            let urgencyClass = '';
            if (pct < 20) urgencyClass = 'urgent';
            else if (pct < 50) urgencyClass = 'warn';

            const pulseHtml = pct < 20 ? '<span class="urgent-pulse"></span>' : '';
            textEl.innerHTML = pulseHtml + formatCountdown(leftSecs);
            textEl.className = 'token-card-countdown-text' + (urgencyClass ? ' ' + urgencyClass : '');

            // Progress bar colour
            let barColour = '#198754';
            if (pct < 50) barColour = '#ffc107';
            if (pct < 20) barColour = '#dc3545';

            if (barEl) {
                barEl.style.width      = pct + '%';
                barEl.style.background = barColour;
            }

            // Card border urgency class (only update when changed to avoid reflows)
            if (card) {
                const newUrgency = pct >= 50 ? 'urgency-ok' : pct >= 20 ? 'urgency-warn' : 'urgency-danger';
                if (!card.classList.contains(newUrgency)) {
                    card.classList.remove('urgency-ok', 'urgency-warn', 'urgency-danger');
                    card.classList.add(newUrgency);
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        tickCountdowns();
        setInterval(tickCountdowns, 1000);
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-j1CDi7MgGQ12Z7Qab0qlWQ/Qqz24Gc6BM0thvEMVjHnfYGF0rmFCozFSxQBxwHKO"
    crossorigin="anonymous"></script>
<script src="../admin/includes/plate-validator.js"></script>
<script src="../admin/includes/notif_poll.js"></script>
<?php marle_discount_form_assets($roomMeta); ?>
<?php marle_token_print_assets(); ?>
<?php marle_token_print_root(); ?>

    <!-- Footer — one design for all three roles (includes/staff_footer.php) -->
    <?php $marleFooterTerms = false; require __DIR__ . '/../includes/staff_footer.php'; ?>
</body>
</html>


