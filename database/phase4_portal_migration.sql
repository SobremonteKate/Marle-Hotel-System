-- MarleHotel Phase 4: guest chat read receipts.
-- Run once against marlehoteldb before using chat read timestamps.

ALTER TABLE chat_messages
    ADD COLUMN read_at DATETIME DEFAULT NULL
        COMMENT 'When the recipient viewed this message';

ALTER TABLE chat_messages
    ADD KEY idx_chat_read (customer_id, sender, is_read, archived_at);
