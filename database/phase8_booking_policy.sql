-- MarleHotel Phase 8: booking terms, cancellation, and no-show audit fields.
-- Run once against marlehoteldb before enabling cancellation/no-show handling.

ALTER TABLE bookings
    ADD COLUMN terms_accepted_at DATETIME DEFAULT NULL AFTER special_requests,
    ADD COLUMN terms_version VARCHAR(20) DEFAULT NULL AFTER terms_accepted_at,
    ADD COLUMN cancelled_at DATETIME DEFAULT NULL AFTER denial_reason,
    ADD COLUMN cancelled_by VARCHAR(100) DEFAULT NULL AFTER cancelled_at,
    ADD COLUMN cancellation_reason TEXT DEFAULT NULL AFTER cancelled_by,
    ADD KEY idx_booking_cancelled_at (cancelled_at);
