-- MarleHotel Phase 7: auditable front-desk payment collections.
-- Run once against marlehoteldb before enabling tendered/change collection.

CREATE TABLE IF NOT EXISTS transaction_collections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_number VARCHAR(20) NOT NULL,
    total_due DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount_received DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    change_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('front_desk') NOT NULL DEFAULT 'front_desk',
    collected_by VARCHAR(100) NOT NULL,
    collected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_collection_room (room_number),
    KEY idx_collection_collected_at (collected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transaction_collection_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    collection_id INT UNSIGNED NOT NULL,
    transaction_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id),
    UNIQUE KEY uq_collection_transaction (collection_id, transaction_id),
    KEY idx_collection_item_transaction (transaction_id),
    CONSTRAINT fk_collection_item_collection
        FOREIGN KEY (collection_id) REFERENCES transaction_collections (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_collection_item_transaction
        FOREIGN KEY (transaction_id) REFERENCES transaction_history (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
