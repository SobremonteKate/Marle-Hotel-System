-- MarleHotel Phase 11: role separation, append-only audit, and financial adjustments.
-- Non-destructive migration. Apply once after reviewing in a disposable database.

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id INT NULL,
    actor_username VARCHAR(100) NULL,
    actor_role ENUM('superadmin', 'admin', 'employee', 'system', 'unknown') NOT NULL DEFAULT 'unknown',
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(100) NULL,
    target_id BIGINT UNSIGNED NULL,
    outcome ENUM('success', 'denied', 'failure') NOT NULL,
    reason VARCHAR(500) NULL,
    before_snapshot JSON NULL,
    after_snapshot JSON NULL,
    request_id VARCHAR(100) NULL,
    session_id_hash CHAR(64) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_actor_time (actor_user_id, created_at),
    KEY idx_audit_action_time (action, created_at),
    KEY idx_audit_target (target_type, target_id),
    KEY idx_audit_outcome_time (outcome, created_at),
    CONSTRAINT fk_audit_actor
        FOREIGN KEY (actor_user_id) REFERENCES userstbl (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financial_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_id INT UNSIGNED NOT NULL,
    adjustment_type ENUM('discount', 'void', 'refund', 'correction') NOT NULL,
    original_amount DECIMAL(10,2) NOT NULL,
    adjustment_amount DECIMAL(10,2) NOT NULL,
    proposed_amount DECIMAL(10,2) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    requested_by INT NULL,
    requested_by_username VARCHAR(100) NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'approved', 'rejected', 'applied', 'cancelled') NOT NULL DEFAULT 'pending',
    approved_by INT NULL,
    approved_by_username VARCHAR(100) NULL,
    approved_at DATETIME NULL,
    applied_at DATETIME NULL,
    applied_entry_id INT NULL,
    rejection_reason VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_adjustment_transaction (transaction_id),
    KEY idx_adjustment_status_time (status, requested_at),
    KEY idx_adjustment_requester (requested_by, requested_at),
    KEY idx_adjustment_approver (approved_by, approved_at),
    CONSTRAINT fk_adjustment_transaction
        FOREIGN KEY (transaction_id) REFERENCES transaction_history (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_adjustment_requester
        FOREIGN KEY (requested_by) REFERENCES userstbl (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_adjustment_approver
        FOREIGN KEY (approved_by) REFERENCES userstbl (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_adjustment_amounts
        CHECK (original_amount >= 0 AND adjustment_amount <> 0 AND proposed_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
