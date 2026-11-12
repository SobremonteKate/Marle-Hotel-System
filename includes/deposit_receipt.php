<?php
/**
 * deposit_receipt.php — the printable receipt for a cash deposit taken at the desk.
 *
 * WHY THIS EXISTS
 *   A guest who pays the reservation deposit in cash at the front desk leaves
 *   with nothing to hold: nothing was uploaded, so there is no proof-of-payment
 *   image, and the confirmation email is a screen-only summary. A receipt the
 *   guest can print or keep is the paper half of the record the hotel already
 *   keeps — the amount counted, the room, the stay, and which clerk took it.
 *
 * WHY A HAND-BUILT PDF
 *   The hotel runs on shared hosting with no Composer and no PDF extension (the
 *   same reasoning as send_email.php's HTTP transport). A one-page receipt needs
 *   nothing from a library: the standard Type1 fonts are built into every PDF
 *   reader, so a text-only page is a few hundred bytes of operators. This file
 *   writes that page itself and is therefore dependency-free by construction
 *   rather than by luck.
 *
 * WHAT IT IS CAREFUL ABOUT
 *   - Money is written "PHP 4,800.00", never "₱": the peso sign does not exist in
 *     WinAnsiEncoding — the encoding a non-embedded Type1 font uses — so it would
 *     render as a black box or vanish in the reader.
 *   - Every string is transliterated to Windows-1252 and the three bytes that
 *     could break the file are escaped, so a guest name containing an em dash, an
 *     arrow or an emoji cannot corrupt the document.
 *   - The issue date comes from the hotel's own clock (marle_db_now($conn)), the
 *     stamp the audit log uses — never from the machine rendering the page.
 *   - Amounts are right-aligned with the font's real widths for the characters an
 *     amount contains (digits 556/1000 em, comma, period and space 278, P 667,
 *     H 722), so the figures line up. Prose is wrapped with a deliberately
 *     generous estimate of average glyph width and a wide margin; the estimate is
 *     only ever used for wrapping, never to place a number.
 *
 * Called by includes/booking_notifications.php (marle_notify_walkin_created) and
 * attached to the guest's confirmation email — see the walk-in section of
 * Module E.
 */

require_once __DIR__ . '/server_clock.php';   // the hotel's "now", not this PC's

if (!function_exists('marle_receipt_glyph_width')) {
    /**
     * Width of one character in 1/1000 em for Helvetica, for the characters this
     * receipt prints. Standard AFM values; anything unlisted falls back to 556,
     * which only ever affects how prose wraps (an amount never contains them).
     */
    function marle_receipt_glyph_width(string $char): int
    {
        static $widths = [
            '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556,
            '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556,
            '.' => 278, ',' => 278, ' ' => 278, '-' => 333, '/' => 278,
            'P' => 667, 'H' => 722, '#' => 556, ':' => 278,
        ];

        return $widths[$char] ?? 556;
    }
}

if (!function_exists('marle_receipt_width')) {
    /** Width in points of $text set in Helvetica at $size. */
    function marle_receipt_width(string $text, float $size): float
    {
        $total = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $total += marle_receipt_glyph_width($text[$i]);
        }

        return $total * $size / 1000;
    }
}

if (!function_exists('marle_receipt_text')) {
    /**
     * A string fit for a WinAnsi-encoded page.
     *
     * Deliberately not an escaping function: measuring has to happen on the
     * characters the reader will draw, so the escaping happens at emit time
     * (marle_receipt_literal) on the string this returns.
     */
    function marle_receipt_text(string $text): string
    {
        // The characters Windows-1252 genuinely does not have. Swapped for the
        // nearest thing that reads correctly, before any conversion can turn them
        // into a question mark or drop them silently. Deliberately NOT the em
        // dash, the en dash or the curly quotes: Windows-1252 has all of them
        // (0x97, 0x96, 0x91-0x94), so the receipt keeps the hotel's own
        // punctuation instead of being flattened to hyphens.
        $text = str_replace(
            ['₱', '→', '←', '✓', '✗'],
            ['PHP ', '->', '<-', '+', 'x'],
            $text
        );

        // mbstring first, and measured rather than assumed: on this host's PHP,
        // iconv('UTF-8', 'Windows-1252//TRANSLIT', …) FAILS OUTRIGHT (returns
        // false) as soon as the string holds a character Windows-1252 has no
        // equivalent for — an emoji in a name — which would have left the original
        // UTF-8 bytes in the page as mojibake. mbstring substitutes "?" and keeps
        // everything it can map (the em dash and the curly quotes survive).
        $converted = null;
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
            if (!is_string($converted) || $converted === '') {
                $converted = null;   // an empty page line is worse than the original
            }
        }
        if ($converted === null && function_exists('iconv')) {
            $candidate = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
            if ($candidate !== false) {
                $converted = $candidate;
            }
        }
        if ($converted !== null) {
            $text = $converted;
        } else {
            // Neither conversion is available: keep what a reader can actually
            // draw rather than emitting multi-byte UTF-8 into a single-byte font.
            $text = (string)preg_replace('/[\x80-\xff]/', '', $text);
        }

        // Control bytes would print as nothing useful and can only confuse a
        // reader's parser.
        return (string)preg_replace('/[\x00-\x1f\x7f]/', '', $text);
    }
}

if (!function_exists('marle_receipt_literal')) {
    /** Escape for a PDF literal string: backslash first, then the parentheses. */
    function marle_receipt_literal(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}

if (!function_exists('marle_receipt_money')) {
    /** "PHP 4,800.00" — the peso sign is not printable in WinAnsi, so it is spelled out. */
    function marle_receipt_money(float $amount): string
    {
        return 'PHP ' . number_format($amount, 2, '.', ',');
    }
}

if (!function_exists('marle_deposit_receipt_filename')) {
    /** The name the guest sees on the attachment. ASCII only, and unique per booking. */
    function marle_deposit_receipt_filename(array $booking): string
    {
        return 'Marle-Hotel-cash-deposit-receipt-' . (int)($booking['id'] ?? 0) . '.pdf';
    }
}

if (!function_exists('marle_deposit_receipt_pdf')) {
    /**
     * The receipt, as PDF bytes.
     *
     * @param mysqli $conn       used only for the hotel's clock (marle_db_now)
     * @param array  $booking    the stored booking row (marle_fetch_booking output)
     * @param string $recordedBy the clerk who counted the cash
     */
    function marle_deposit_receipt_pdf(mysqli $conn, array $booking, string $recordedBy = ''): string
    {
        require_once __DIR__ . '/booking_deposit.php';

        // ── What the receipt says ───────────────────────────────────────────
        $id       = (int)($booking['id'] ?? 0);
        $guest    = trim((string)($booking['guest_name'] ?? ''));
        $phone    = trim((string)($booking['guest_phone'] ?? ''));
        $roomNo   = trim((string)($booking['room_number'] ?? ''));
        $roomType = trim((string)($booking['room_type'] ?? ''));
        $roomText = $roomType . ($roomNo !== '' ? ' — Room ' . $roomNo : '');
        if ($roomText === '') {
            $roomText = 'Assigned at the front desk';
        }
        $when  = function_exists('marle_booking_when_text')
            ? marle_booking_when_text($booking)
            : trim((string)($booking['check_in'] ?? ''));

        $total   = function_exists('marle_booking_total') ? (float)marle_booking_total($booking) : 0.0;
        $deposit = (float)($booking['deposit_amount'] ?? 0);
        if ($deposit <= 0 && function_exists('marle_deposit_amount')) {
            $deposit = (float)marle_deposit_amount($conn, $total);
        }
        $balance = max(0, $total - $deposit);
        $rate    = (float)($booking['deposit_rate'] ?? 0);
        if ($rate <= 0 && function_exists('marle_deposit_rate')) {
            $rate = (float)marle_deposit_rate($conn);
        }

        // The hotel's clock, formatted only with an explicit timestamp (never a
        // bare date() call, which would be this machine's time — see
        // clock_hygiene_checks.php).
        $issuedRaw  = marle_db_now($conn);
        $issuedTs   = strtotime($issuedRaw);
        $issuedText = $issuedTs !== false ? date('F j, Y g:i A', $issuedTs) : $issuedRaw;

        $forfeit = defined('MARLE_CANCELLATION_FORFEIT_RATE')
            ? (int)MARLE_CANCELLATION_FORFEIT_RATE
            : 50;

        // ── The page ────────────────────────────────────────────────────────
        $pageWidth  = 595.0;   // A4
        $pageHeight = 842.0;
        $left       = 48.0;
        $right      = 547.0;

        $maroon = [0.576, 0.200, 0.063];   // #933310 — the app's brand maroon
        $gold   = [1.000, 0.714, 0.039];   // #ffb60a
        $ink    = [0.130, 0.130, 0.130];
        $muted  = [0.450, 0.450, 0.450];
        $rule   = [0.850, 0.850, 0.850];
        $cream  = [0.996, 0.973, 0.945];
        $note   = [0.976, 0.965, 0.953];
        $white  = [1.0, 1.0, 1.0];

        $ops  = [];
        $n    = static fn(float $v): string => rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
        $rgb  = static fn(array $c): string => $n($c[0]) . ' ' . $n($c[1]) . ' ' . $n($c[2]);

        // Every string the page draws goes through marle_receipt_text() first: a
        // WinAnsi font cannot draw UTF-8, so an em dash or a curly quote left as
        // UTF-8 renders as two or three junk characters instead of one glyph.
        // Conversion then
        // escaping — in that order, since escaping adds backslashes that must not
        // be re-encoded.
        $text = function (string $content, float $x, float $y, string $font, float $size, array $colour) use (&$ops, $rgb, $n): void {
            $ops[] = 'BT ' . $rgb($colour) . ' rg /' . $font . ' ' . $n($size) . ' Tf 1 0 0 1 '
                . $n($x) . ' ' . $n($y) . ' Tm ('
                . marle_receipt_literal(marle_receipt_text($content)) . ') Tj ET';
        };
        $rightText = function (string $content, float $rightX, float $y, string $font, float $size, array $colour) use ($text): void {
            $plain = marle_receipt_text($content);
            $text($content, $rightX - marle_receipt_width($plain, $size), $y, $font, $size, $colour);
        };
        $rect = function (float $x, float $y, float $w, float $h, array $colour, ?array $stroke = null) use (&$ops, $rgb, $n): void {
            $ops[] = $rgb($colour) . ' rg ' . $n($x) . ' ' . $n($y) . ' ' . $n($w) . ' ' . $n($h) . ' re f';
            if ($stroke !== null) {
                $ops[] = $rgb($stroke) . ' RG 0.7 w ' . $n($x) . ' ' . $n($y) . ' ' . $n($w) . ' ' . $n($h) . ' re S';
            }
        };
        $line = function (float $x1, float $y1, float $x2, float $y2, float $width, array $colour) use (&$ops, $rgb, $n): void {
            $ops[] = $rgb($colour) . ' RG ' . $n($width) . ' w ' . $n($x1) . ' ' . $n($y1) . ' m ' . $n($x2) . ' ' . $n($y2) . ' l S';
        };
        // Conservative wrapping: 0.56 em per character over-estimates most Latin
        // text, so a line that fits this budget cannot overflow its column.
        $wrap = function (string $content, float $maxWidth, float $size): array {
            $budget = max(8, (int)floor($maxWidth / (0.56 * $size)));
            $words  = preg_split('/\s+/', trim($content)) ?: [];
            $lines  = [];
            $current = '';
            foreach ($words as $word) {
                while (strlen($word) > $budget) {          // a word longer than the column
                    if ($current !== '') { $lines[] = $current; $current = ''; }
                    $lines[] = substr($word, 0, $budget);
                    $word = substr($word, $budget);
                }
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if (strlen($candidate) <= $budget) {
                    $current = $candidate;
                } else {
                    $lines[] = $current;
                    $current = $word;
                }
            }
            if ($current !== '') {
                $lines[] = $current;
            }

            return $lines;
        };

        // ── Header band ─────────────────────────────────────────────────────
        $rect(0, 758, $pageWidth, 84, $maroon);
        $rect(0, 753, $pageWidth, 5, $gold);
        $text('MARLE HOTEL & RESTAURANT', $left, 806, 'F2', 18, $white);
        $text('Sabutan Ibayo, Silang, Cavite  |  0917 000 0000', $left, 787, 'F1', 9, $white);
        $rightText('CASH DEPOSIT RECEIPT', $right, 807, 'F2', 12, $white);
        $rightText('Reference #' . $id, $right, 788, 'F2', 10.5, $gold);

        // ── Left column: who paid, for what ─────────────────────────────────
        $text('RECEIVED FROM', $left, 712, 'F1', 8, $muted);
        $nameLines = $wrap($guest !== '' ? $guest : 'Guest', 250.0, 13);
        $nameY = 694.0;
        foreach (array_slice($nameLines, 0, 2) as $lineText) {
            $text($lineText, $left, $nameY, 'F2', 13, $ink);
            $nameY -= 16;
        }
        $text($phone !== '' ? $phone : 'No phone recorded', $left, $nameY - 2, 'F1', 9.5, $muted);

        $text('ROOM', $left, 644, 'F1', 8, $muted);
        $roomLines = $wrap($roomText, 250.0, 11);
        $roomY = 628.0;
        foreach (array_slice($roomLines, 0, 2) as $lineText) {
            $text($lineText, $left, $roomY, 'F2', 11, $ink);
            $roomY -= 14;
        }

        // The stay sentence is the longest line on the page ("October 18, 2026 —
        // October 19, 2026 (1 night)"), so its column is the full width up to the
        // money panel and it is set a half-point smaller than the rest: one clean
        // line for an ordinary stay, two for an hourly one.
        $text('STAY', $left, 596, 'F1', 8, $muted);
        $stayLines = $wrap($when, 262.0, 9.5);
        $stayY = 580.0;
        foreach (array_slice($stayLines, 0, 2) as $lineText) {
            $text($lineText, $left, $stayY, 'F1', 9.5, $ink);
            $stayY -= 13;
        }

        $text('GUESTS', $left, 542, 'F1', 8, $muted);
        $text((string)(int)($booking['persons'] ?? 0) . ' staying', $left, 526, 'F2', 11, $ink);

        // ── Right panel: the money ──────────────────────────────────────────
        $rect(320, 512, 227, 212, $cream, [0.900, 0.850, 0.800]);
        $text('DEPOSIT RECEIVED IN CASH', 336, 694, 'F1', 8, $muted);
        $text(marle_receipt_money($deposit), 336, 664, 'F2', 20, $maroon);
        $text('Counted at the front desk', 336, 646, 'F1', 9, $muted);
        $line(336, 632, 531, 632, 0.7, $rule);
        $text('Booking total', 336, 612, 'F1', 9, $muted);
        $rightText(marle_receipt_money($total), 531, 612, 'F1', 9, $ink);
        $text('Deposit rate', 336, 592, 'F1', 9, $muted);
        $rightText(number_format($rate, 0) . '% of the total', 531, 592, 'F1', 9, $ink);
        $text('Balance due at the hotel', 336, 572, 'F1', 9, $muted);
        $rightText(marle_receipt_money($balance), 531, 572, 'F2', 9, $ink);
        $line(336, 556, 531, 556, 0.7, $rule);
        $text('Booking reference', 336, 538, 'F1', 9, $muted);
        $rightText('#' . $id, 531, 538, 'F2', 9.5, $maroon);

        // ── The three facts a desk has to be able to show ───────────────────
        $rows = [
            'Received by'    => $recordedBy !== '' ? $recordedBy : 'Staff at the front desk',
            'Date issued'    => $issuedText . ' (hotel time)',
            'Payment method' => 'Cash at the front desk',
        ];
        $rowY = 490.0;
        foreach ($rows as $label => $value) {
            $text($label, $left, $rowY, 'F1', 9, $muted);
            $text($value, 190, $rowY, $label === 'Received by' ? 'F2' : 'F1', 9.5, $ink);
            $line($left, $rowY - 12, $right, $rowY - 12, 0.6, $rule);
            $rowY -= 26;
        }

        // ── Small print ─────────────────────────────────────────────────────
        $rect($left, 336, 499, 84, $note, [0.900, 0.880, 0.860]);
        $noteText = 'This receipt is generated when a cash reservation deposit is recorded at the front desk. '
            . 'Cancelling a booking forfeits ' . $forfeit . '% of the total booking amount; confirmed bookings are '
            . 'non-refundable, and guests have a one-hour grace period after check-in. '
            . 'Please present this receipt or your booking reference at check-in.';
        $noteY = 398.0;
        foreach (array_slice($wrap($noteText, 475.0, 8.5), 0, 4) as $lineText) {
            $text($lineText, 60, $noteY, 'F1', 8.5, $muted);
            $noteY -= 12;
        }

        // ── Signatures and footer ───────────────────────────────────────────
        $line($left, 268, 280, 268, 0.8, $rule);
        $text('Received by (front desk)', $left, 254, 'F1', 8, $muted);
        $line(315, 268, $right, 268, 0.8, $rule);
        $text('Guest signature', 315, 254, 'F1', 8, $muted);

        $text('Thank you for choosing Marle Hotel & Restaurant.', $left, 200, 'F2', 9.5, $maroon);
        $rightText('Marle Hotel & Restaurant  |  Silang, Cavite  |  0917 000 0000', $right, 118, 'F1', 8, $muted);

        // ── Assemble the document ───────────────────────────────────────────
        $stream = implode("\n", $ops) . "\n";
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $n($pageWidth) . ' ' . $n($pageHeight) . ']'
                 . ' /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
            4 => "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            6 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $body) {
            $offsets[$index] = strlen($pdf);
            $pdf .= $index . " 0 obj\n" . $body . "\nendobj\n";
        }

        // The cross-reference table is what makes this a document rather than a
        // pile of operators: each entry is exactly 20 bytes, and startxref names
        // its own offset. Readers that find it wrong reject the file outright.
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

        return $pdf;
    }
}
