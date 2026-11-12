-- MarleHotel Phase 2: booking retention columns.
-- Run once against marlehoteldb before enabling booking retention.

ALTER TABLE bookings
    ADD COLUMN is_archived TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
        COMMENT '0 = active, 1 = retained in archive',
    ADD COLUMN archived_at DATETIME DEFAULT NULL
        COMMENT 'When the booking entered the archive';

ALTER TABLE bookings
    ADD KEY idx_booking_archive (is_archived, archived_at);
