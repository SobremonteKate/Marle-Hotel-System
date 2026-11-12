<?php
/**
 * booking_pricing.php — the server-side source of truth for booking money.
 *
 * The homepage modal computes these same numbers in JavaScript for the guest's
 * instant estimate. Everything that is *recorded* — the reservation deposit,
 * the cancellation forfeiture — is recomputed here, so a tampered or stale
 * client can never change what the hotel charges.
 *
 *   Nightly:  (base + 21 × extension rate) × nights
 *   Hourly:   base + max(0, duration − 3) × extension rate
 */

if (!defined('MARLE_BASE_RATE')) {
    define('MARLE_BASE_RATE', [
        'With Garage'    => 450.00,
        'Suite'          => 600.00,
        'Family'         => 600.00,
        'Without Garage' => 350.00,
    ]);
}

if (!defined('MARLE_EXTENSION_RATE')) {
    define('MARLE_EXTENSION_RATE', [
        'With Garage'    => 150.00,
        'Suite'          => 200.00,
        'Family'         => 200.00,
        'Without Garage' => 150.00,
    ]);
}

if (!function_exists('marle_nightly_rate')) {
    /** One night's rate for a room type (3-hour block + 21 extra hours). */
    function marle_nightly_rate(string $roomType): float
    {
        $base = (float)(MARLE_BASE_RATE[$roomType] ?? 0.00);
        $ext  = (float)(MARLE_EXTENSION_RATE[$roomType] ?? 0.00);
        return $base + (21 * $ext);
    }
}

if (!function_exists('marle_booking_total')) {
    /**
     * Estimated total for a booking row (array from `bookings`) or for raw
     * values. Returns 0.00 when the room type is unknown.
     *
     * @param array $booking needs booking_type, room_type, check_in, check_out,
     *                       hours_requested
     */
    function marle_booking_total(array $booking): float
    {
        $roomType = (string)($booking['room_type'] ?? '');
        $base     = (float)(MARLE_BASE_RATE[$roomType] ?? 0.00);
        $ext      = (float)(MARLE_EXTENSION_RATE[$roomType] ?? 0.00);

        $isHourly = ($booking['booking_type'] ?? 'daily') === 'hourly';

        if ($isHourly) {
            $hours = (int)($booking['hours_requested'] ?? 0);
            return round($base + (max(0, $hours - 3) * $ext), 2);
        }

        $checkIn  = (string)($booking['check_in'] ?? '');
        $checkOut = (string)($booking['check_out'] ?? '');
        $inTs     = strtotime($checkIn . ' 00:00:00');
        $outTs    = strtotime($checkOut . ' 00:00:00');

        $nights = 1;
        if ($inTs !== false && $outTs !== false && $outTs > $inTs) {
            $nights = max(1, (int)round(($outTs - $inTs) / 86400));
        }

        return round(marle_nightly_rate($roomType) * $nights, 2);
    }
}
