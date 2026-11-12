-- MarleHotel Phase 6: stable review identity for guest portal submissions.
-- Existing rows remain valid with a NULL token and continue using the legacy fallback.

ALTER TABLE reviewstbl
    ADD COLUMN token VARCHAR(64) DEFAULT NULL AFTER room_no,
    ADD KEY idx_reviews_token (token);
