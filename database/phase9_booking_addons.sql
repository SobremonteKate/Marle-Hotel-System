-- MarleHotel Phase 9: normalized booking add-ons.
-- Run once against marlehoteldb after the booking_addons insert path is deployed.

CREATE TABLE IF NOT EXISTS booking_addons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT UNSIGNED NOT NULL,
    amenity_id INT NOT NULL,
    amenity_title_snapshot VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_amenity (booking_id, amenity_id),
    KEY idx_booking_addons_booking (booking_id),
    KEY idx_booking_addons_amenity (amenity_id),
    CONSTRAINT fk_booking_addons_booking
        FOREIGN KEY (booking_id) REFERENCES bookings (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_booking_addons_amenity
        FOREIGN KEY (amenity_id) REFERENCES amenitiestbl (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
