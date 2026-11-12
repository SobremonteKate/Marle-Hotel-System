-- MarleHotel Phase 13: persistent Luna sentiment results.
-- Apply once after reviewing in a disposable database.

CREATE TABLE IF NOT EXISTS review_sentiment_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    review_id INT NOT NULL,
    review_text_snapshot TEXT NOT NULL,
    review_date DATE NOT NULL,
    sentiment ENUM('positive', 'neutral', 'negative') NOT NULL,
    sentiment_score DECIMAL(4,3) NOT NULL,
    theme ENUM('cleanliness', 'staff', 'location', 'amenities', 'value', 'food', 'noise', 'maintenance', 'other') NOT NULL,
    processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_review_sentiment_review (review_id),
    KEY idx_review_sentiment_date (review_date),
    KEY idx_review_sentiment_processed (processed_at),
    CONSTRAINT chk_review_sentiment_score CHECK (sentiment_score >= -1.000 AND sentiment_score <= 1.000),
    CONSTRAINT fk_review_sentiment_review
        FOREIGN KEY (review_id) REFERENCES reviewstbl (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
