<?php
/**
 * booking_deposit.php — reservation deposit + cancellation policy money rules.
 *
 * Marle Hotel takes a reservation deposit by bank transfer / GCash *before* a
 * booking is confirmed: the guest pays, uploads the proof, and a manager
 * verifies it. Cancelling forfeits a flat share of the booking total, and the
 * amount kept is recorded so the hotel can see what it retained.
 *
 * Everything here is shared by the homepage, the staff requests page and the
 * emails so the number the guest sees is the number the hotel keeps.
 */

require_once __DIR__ . '/booking_pricing.php';
require_once __DIR__ . '/server_clock.php';   // marle_db_now(): the hotel's clock, not PHP's

/** Share of the booking total a guest must pay up front to confirm. */
if (!defined('MARLE_DEPOSIT_DEFAULT_RATE')) {
    define('MARLE_DEPOSIT_DEFAULT_RATE', 50.00);
}

/** Share of the booking total the hotel keeps when a guest cancels (flat). */
if (!defined('MARLE_CANCELLATION_FORFEIT_RATE')) {
    define('MARLE_CANCELLATION_FORFEIT_RATE', 50.00);
}

/**
 * Hours a booking is held while its deposit is unpaid.
 *
 * An unpaid booking blocks the room for everyone else, so it cannot sit pending
 * forever: once this window passes the release sweep (includes/booking_expiry.php)
 * cancels it with cancelled_by = 'system' and NOTHING is charged — no deposit was
 * ever verified. Owner-editable from Settings → Reservation Deposit.
 */
if (!defined('MARLE_DEPOSIT_HOLD_HOURS_DEFAULT')) {
    define('MARLE_DEPOSIT_HOLD_HOURS_DEFAULT', 24);
}

/** Hard bounds for the hold window: 1 hour … 30 days. */
if (!defined('MARLE_DEPOSIT_HOLD_HOURS_MIN')) define('MARLE_DEPOSIT_HOLD_HOURS_MIN', 1);
if (!defined('MARLE_DEPOSIT_HOLD_HOURS_MAX')) define('MARLE_DEPOSIT_HOLD_HOURS_MAX', 720);

if (!function_exists('marle_deposit_hold_hours')) {
    /** How long an unpaid booking holds its room, in whole hours (1–720). */
    function marle_deposit_hold_hours(mysqli $conn): int
    {
        $raw = trim(marle_site_option($conn, 'deposit_hold_hours', (string)MARLE_DEPOSIT_HOLD_HOURS_DEFAULT));
        if (!is_numeric($raw)) {
            return (int)MARLE_DEPOSIT_HOLD_HOURS_DEFAULT;
        }

        $hours = (int)round((float)$raw);
        if ($hours < MARLE_DEPOSIT_HOLD_HOURS_MIN || $hours > MARLE_DEPOSIT_HOLD_HOURS_MAX) {
            return (int)MARLE_DEPOSIT_HOLD_HOURS_DEFAULT;
        }
        return $hours;
    }
}

if (!function_exists('marle_deposit_hold_label')) {
    /** "24 hours" / "1 hour" — the phrase quoted to guests and staff. */
    function marle_deposit_hold_label(int $hours): string
    {
        return max(1, $hours) . ' hour' . ($hours === 1 ? '' : 's');
    }
}

if (!function_exists('marle_deposit_deadline_ts')) {
    /**
     * When this booking's hold expires.
     *
     * The clock starts at the guest's own last action, not at booking time: the
     * first upload for an unpaid booking, and the last upload for a rejected one
     * (so asking for a clearer photo restarts the wait instead of punishing the
     * guest for the front desk's queue).
     *
     * @return int unix timestamp, 0 when the booking carries no usable date
     */
    function marle_deposit_deadline_ts(array $booking, int $hours): int
    {
        $reference = (string)($booking['deposit_submitted_at'] ?? '');
        if ($reference === '') {
            $reference = (string)($booking['created_at'] ?? '');
        }
        if ($reference === '') {
            return 0;
        }

        $ts = strtotime($reference);
        return $ts === false ? 0 : $ts + (max(1, $hours) * 3600);
    }
}

if (!function_exists('marle_deposit_hold_sentence')) {
    /** The one sentence the guest reads about the deadline. */
    function marle_deposit_hold_sentence(int $hours): string
    {
        return 'Your room is held for ' . marle_deposit_hold_label($hours)
             . ' while the deposit is unpaid. After that the booking is released automatically '
             . 'and the dates go back to other guests — nothing is charged.';
    }
}

if (!function_exists('marle_site_option_cache')) {
    /**
     * Per-request memo for `site_options`.
     * Passing an array replaces the cache (used by the writer below); passing
     * null just returns it. One extra round trip per request, not per option.
     */
    function marle_site_option_cache(?array $replace = null): array
    {
        static $cache = [];
        if ($replace !== null) $cache = $replace;
        return $cache;
    }
}

if (!function_exists('marle_site_option')) {
    /** Read a `site_options` value (cached per request). */
    function marle_site_option(mysqli $conn, string $key, string $default = ''): string
    {
        $cache = marle_site_option_cache();

        if (empty($cache)) {
            $res = $conn->query('SELECT option_key, option_value FROM site_options');
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $cache[(string)$row['option_key']] = (string)$row['option_value'];
                }
            }
            marle_site_option_cache($cache);
        }

        return array_key_exists($key, $cache) ? $cache[$key] : $default;
    }
}

if (!function_exists('marle_set_site_option')) {
    /** Upsert a `site_options` value and keep the request cache in step. */
    function marle_set_site_option(mysqli $conn, string $key, string $value): void
    {
        $stmt = $conn->prepare(
            'INSERT INTO site_options (option_key, option_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)'
        );
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();

        $cache = marle_site_option_cache();
        $cache[$key] = $value;
        marle_site_option_cache($cache);
    }
}

if (!function_exists('marle_deposit_rate')) {
    /** Deposit share as a number (1–100). */
    function marle_deposit_rate(mysqli $conn): float
    {
        $raw = trim(marle_site_option($conn, 'deposit_rate', (string)MARLE_DEPOSIT_DEFAULT_RATE));
        $rate = is_numeric($raw) ? (float)$raw : (float)MARLE_DEPOSIT_DEFAULT_RATE;
        if ($rate < 1 || $rate > 100) $rate = (float)MARLE_DEPOSIT_DEFAULT_RATE;
        return round($rate, 2);
    }
}

if (!function_exists('marle_deposit_amount')) {
    /** The peso amount a guest must pay up front for a given booking total. */
    function marle_deposit_amount(mysqli $conn, float $total): float
    {
        return round($total * (marle_deposit_rate($conn) / 100), 2);
    }
}

if (!function_exists('marle_cancellation_fee')) {
    /** Flat share of the booking total the hotel keeps on any cancellation. */
    function marle_cancellation_fee(float $total): float
    {
        return round($total * (MARLE_CANCELLATION_FORFEIT_RATE / 100), 2);
    }
}

if (!function_exists('marle_peso')) {
    function marle_peso(float $amount): string
    {
        return "\u{20B1}" . number_format($amount, 2);
    }
}

if (!function_exists('marle_deposit_status_meta')) {
    /**
     * Display metadata for a booking's deposit state.
     * @return array{label:string, chip:string, note:string}
     */
    function marle_deposit_status_meta(string $status): array
    {
        return match ($status) {
            'verified' => [
                'label' => 'Deposit verified',
                'chip'  => 'bg-success',
                'note'  => 'The reservation deposit was received and verified by the front desk.',
            ],
            'for_verification' => [
                'label' => 'For verification',
                'chip'  => 'bg-warning text-dark',
                'note'  => 'The guest uploaded proof of payment — the front desk still has to verify it.',
            ],
            'rejected' => [
                'label' => 'Proof rejected',
                'chip'  => 'bg-danger',
                'note'  => 'The uploaded proof of payment was rejected. Ask the guest to send a clear copy.',
            ],
            default => [
                'label' => 'Deposit unpaid',
                'chip'  => 'bg-secondary',
                'note'  => 'No reservation deposit has been recorded yet.',
            ],
        };
    }
}

if (!function_exists('marle_deposit_method_text')) {
    /**
     * Plain-text payment instructions (GCash number + bank details) shown on the
     * status page and quoted inside emails. Editable from Settings.
     */
    function marle_deposit_method_text(mysqli $conn): string
    {
        $gcash = trim(marle_site_option($conn, 'deposit_gcash_number', '0917 000 0000'));
        $bank  = trim(marle_site_option($conn, 'deposit_bank_details', 'Marle Hotel & Restaurant — BDO Savings (ask the front desk for the account number)'));

        $lines = [];
        if ($gcash !== '') $lines[] = 'GCash: ' . $gcash;
        if ($bank !== '')  $lines[] = 'Bank transfer: ' . $bank;
        return implode(' · ', $lines);
    }
}

if (!function_exists('marle_cancellation_policy_sentence')) {
    /** The one sentence the guest must be able to read before they cancel. */
    function marle_cancellation_policy_sentence(array $booking): string
    {
        $total = marle_booking_total($booking);
        $fee   = marle_cancellation_fee($total);
        $pct   = (int)MARLE_CANCELLATION_FORFEIT_RATE;

        return 'Cancelling this booking forfeits ' . $pct . '% of the total booking amount — '
             . marle_peso($fee) . ' of ' . marle_peso($total) . '.';
    }
}

if (!function_exists('marle_deposit_proof_dir')) {
    /**
     * Where proof-of-payment files live.
     *
     * The .htaccess written next to them disables script execution: the folder
     * only ever holds images/PDFs, and PHP must never run from an uploaded file
     * even if a future validation gap let one through.
     *
     * @return string absolute path (created on demand)
     */
    function marle_deposit_proof_dir(): string
    {
        $dir = dirname(__DIR__) . '/uploads/booking_proof/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $htaccess = $dir . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents(
                $htaccess,
                "# Uploaded reservation proof — never execute anything here.\n"
              . "php_flag engine off\n"
              . "<FilesMatch \"\\.(php|phtml|php[0-9]|pl|py|cgi|sh)$\">\n"
              . "    Require all denied\n"
              . "</FilesMatch>\n"
              . "Options -Indexes\n"
            );
        }
        return $dir;
    }
}

if (!function_exists('marle_deposit_save_proof')) {
    /**
     * Validate and store a guest's proof of payment, then move the booking into
     * 'for_verification'. Re-uploading is allowed while the deposit is not yet
     * verified, which is what a guest does when the first photo was blurry.
     *
     * @param array $file $_FILES['deposit_proof']
     * @return array{ok:bool, error:string}
     */
    function marle_deposit_save_proof(mysqli $conn, int $bookingId, string $reference, array $file): array
    {
        $reference = trim($reference);
        if (strlen($reference) < 4 || strlen($reference) > 80) {
            return ['ok' => false, 'error' => 'Enter the GCash or bank reference number (at least 4 characters).'];
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
        $allowedExts  = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
        $maxSize      = 5 * 1024 * 1024;

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $msg  = $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
                ? 'That file is too large. Maximum size is 5 MB.'
                : 'Please attach a photo or PDF of your payment receipt.';
            return ['ok' => false, 'error' => $msg];
        }

        if ((int)($file['size'] ?? 0) > $maxSize) {
            return ['ok' => false, 'error' => 'That file is too large. Maximum size is 5 MB.'];
        }

        // Trust the file's contents, not its name or the browser-supplied type.
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $realMime = (string)$finfo->file((string)$file['tmp_name']);
        $ext      = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));

        if (!in_array($realMime, $allowedMimes, true) || !in_array($ext, $allowedExts, true)) {
            return ['ok' => false, 'error' => 'Invalid file type. Upload a JPG, PNG, GIF or PDF of your receipt.'];
        }

        $dir = marle_deposit_proof_dir();
        $safeName = 'deposit_' . $bookingId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file((string)$file['tmp_name'], $dir . $safeName)) {
            return ['ok' => false, 'error' => 'We could not store that file. Please try again.'];
        }

        // A re-upload replaces the previous receipt (typically the same guest
        // sending a clearer photo after a rejection), so remember the old path
        // and delete that file once the new one is safely recorded. A superseded
        // copy of a guest's payment receipt is personal data the hotel has no
        // reason to leave lying in uploads/ — it is no longer referenced
        // anywhere, and the rejection reason is what the trail needs to keep.
        $previousPath = '';
        $prevStmt = $conn->prepare('SELECT deposit_proof_path FROM bookings WHERE id = ? LIMIT 1');
        if ($prevStmt) {
            $prevStmt->bind_param('i', $bookingId);
            $prevStmt->execute();
            $prevRow = $prevStmt->get_result()->fetch_assoc();
            $prevStmt->close();
            $previousPath = (string)($prevRow['deposit_proof_path'] ?? '');
        }

        $relativePath = 'uploads/booking_proof/' . $safeName;
        $stmt = $conn->prepare(
            "UPDATE bookings
                SET deposit_status = 'for_verification',
                    deposit_reference = ?,
                    deposit_proof_path = ?,
                    deposit_submitted_at = NOW(),
                    deposit_rejection_reason = NULL,
                    updated_at = NOW()
              WHERE id = ?"
        );
        $stmt->bind_param('ssi', $reference, $relativePath, $bookingId);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            @unlink($dir . $safeName);
            return ['ok' => false, 'error' => 'We could not save your payment details. Please try again.'];
        }

        // Only ever touch a file inside the proof folder, and never the one just written.
        if ($previousPath !== ''
            && $previousPath !== $relativePath
            && str_starts_with($previousPath, 'uploads/booking_proof/')) {
            $previousFile = dirname(__DIR__) . '/' . $previousPath;
            if (is_file($previousFile)) {
                @unlink($previousFile);
            }
        }

        return ['ok' => true, 'error' => '', 'path' => $relativePath];
    }
}

if (!function_exists('marle_deposit_verify')) {
    /**
     * Manager decision on an uploaded proof of payment.
     *
     * Verifying (approve) is only ever possible when the guest has uploaded a
     * receipt; without one the request is refused before any row is touched.
     *
     * @return array{ok:bool, error:string}
     */
    function marle_deposit_verify(
        mysqli $conn,
        int $bookingId,
        string $byUsername,
        bool $approve,
        string $reason = ''
    ): array {
        // The hotel's clock, not PHP's (includes/server_clock.php). This is the stamp a
        // manager's verification or rejection is recorded with, and every other time on
        // the booking was written by the database.
        $when = marle_db_now($conn);

        if ($approve) {
            // "Always proof": a deposit is only ever verified against a receipt the
            // guest actually uploaded — the manager opens that receipt and checks it
            // against the bank/GCash statement. No receipt on file means there is
            // nothing to verify, and an untracked "verified" would let the booking
            // be approved with no evidence that the money arrived.
            $lookup = $conn->prepare('SELECT deposit_status, deposit_proof_path FROM bookings WHERE id = ? LIMIT 1');
            $lookup->bind_param('i', $bookingId);
            $lookup->execute();
            $current = $lookup->get_result()->fetch_assoc();
            $lookup->close();

            if (!$current) {
                return ['ok' => false, 'error' => 'That booking no longer exists.'];
            }

            if (trim((string)($current['deposit_proof_path'] ?? '')) === '') {
                return [
                    'ok'    => false,
                    'error' => 'No proof of payment has been uploaded for this booking yet, so there is nothing to verify. '
                             . 'Ask the guest to upload their GCash or bank receipt first.',
                ];
            }

            if (!in_array((string)$current['deposit_status'], ['for_verification', 'rejected'], true)) {
                return ['ok' => false, 'error' => 'That booking is not waiting for a deposit decision.'];
            }

            $stmt = $conn->prepare(
                "UPDATE bookings
                    SET deposit_status = 'verified',
                        deposit_verified_by = ?,
                        deposit_verified_at = ?,
                        deposit_rejection_reason = NULL,
                        updated_at = NOW()
                  WHERE id = ?
                    AND deposit_status IN ('for_verification', 'rejected')
                    AND deposit_proof_path IS NOT NULL
                    AND deposit_proof_path <> ''"
            );
            $stmt->bind_param('ssi', $byUsername, $when, $bookingId);
        } else {
            $reason = trim($reason);
            if ($reason === '') {
                return ['ok' => false, 'error' => 'Tell the guest why the proof was rejected.'];
            }
            $stmt = $conn->prepare(
                "UPDATE bookings
                    SET deposit_status = 'rejected',
                        deposit_rejection_reason = ?,
                        deposit_verified_by = ?,
                        deposit_verified_at = ?,
                        updated_at = NOW()
                  WHERE id = ? AND deposit_status = 'for_verification'"
            );
            $stmt->bind_param('sssi', $reason, $byUsername, $when, $bookingId);
        }

        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        if ($changed < 1) {
            return ['ok' => false, 'error' => 'That booking is not waiting for a deposit decision.'];
        }

        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('marle_booking_can_be_cancelled')) {
    /**
     * Guests may cancel while the stay is still ahead of them. Once the check-in
     * date (and, for hourly stays, the check-in time) has passed the booking is
     * the front desk's to handle, so the site refuses and says so.
     *
     * The rule is the same for everyone; only the sentence changes, because the
     * guest is told to call the front desk while the front desk (cancelling from
     * Requests → Bookings) is the one being told. Pass $forGuest = false for the
     * staff wording.
     *
     * @return array{allowed:bool, reason:string}
     */
    function marle_booking_can_be_cancelled(array $booking, bool $forGuest = true): array
    {
        $checkIn = (string)($booking['check_in'] ?? '');
        if ($checkIn === '') {
            return ['allowed' => true, 'reason' => ''];
        }

        $isHourly = ($booking['booking_type'] ?? 'daily') === 'hourly';
        $time     = $isHourly ? (string)($booking['check_in_time'] ?? '00:00:00') : '00:00:00';
        $startTs  = strtotime($checkIn . ' ' . $time);

        if ($startTs !== false && $startTs <= time()) {
            return [
                'allowed' => false,
                'reason'  => $forGuest
                    ? 'The check-in date for this booking has already arrived, so it can no longer be cancelled online. '
                        . 'Please contact the front desk at 0917 000 0000.'
                    : 'The check-in date for this booking has already arrived, so it can no longer be cancelled here. '
                        . 'Settle the stay from the Front Desk / Checkout screen so it is closed properly.',
            ];
        }

        return ['allowed' => true, 'reason' => ''];
    }
}
