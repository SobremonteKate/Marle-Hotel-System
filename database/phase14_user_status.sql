-- MarleHotel Phase 14: user account status (deactivation support).
-- Non-destructive migration. Apply once.
--Pairs with database/phase11_access_control_audit.sql (audit_log + financial_adjustments).

-- Active accounts work as before; deactivated accounts are blocked at login
-- and force-logged-out on their next request (see access_control.php).
ALTER TABLE userstbl
    ADD COLUMN status ENUM('active', 'deactivated') NOT NULL DEFAULT 'active' AFTER usertype,
    ADD COLUMN deactivated_at DATETIME NULL DEFAULT NULL AFTER status,
    ADD COLUMN deactivated_by VARCHAR(100) NULL DEFAULT NULL AFTER deactivated_at;

CREATE INDEX idx_userstbl_status ON userstbl (status);
