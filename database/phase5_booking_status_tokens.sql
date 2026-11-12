ALTER TABLE bookings
    ADD COLUMN status_token CHAR(64) NULL AFTER guest_email,
    ADD UNIQUE KEY uq_bookings_status_token (status_token);
