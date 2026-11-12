-- MarleHotel Phase 15: Senior Citizen / PWD discounts at check-in.
--
-- Run ONCE against marlehoteldb (this migration is not idempotent):
--   mysql -uroot marlehoteldb < database/phase15_guest_discounts.sql
--
-- Why the extra columns:
--   * customerstbl keeps the discount evidence for the stay (type, ID number,
--     the VAT-exempt base, the discount and the net payable).
--   * transaction_history.amount stays the NET payable amount, so every
--     existing report, collection and dashboard total keeps working unchanged;
--     gross_amount / discount_amount let a receipt show the full picture.
--
-- Discount math for a VAT-registered establishment (RA 9994 / RA 7277):
--   vat_exempt_amount = gross / 1.12
--   discount_amount   = vat_exempt_amount × rate      (20% for senior/PWD)
--   net_amount        = vat_exempt_amount - discount_amount

ALTER TABLE customerstbl
    ADD COLUMN discount_type ENUM('none', 'senior', 'pwd', 'other') NOT NULL DEFAULT 'none' AFTER status,
    ADD COLUMN discount_id_no VARCHAR(40) DEFAULT NULL AFTER discount_type,
    ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER discount_id_no,
    ADD COLUMN gross_amount DECIMAL(10,2) DEFAULT NULL AFTER discount_percent,
    ADD COLUMN vat_exempt_amount DECIMAL(10,2) DEFAULT NULL AFTER gross_amount,
    ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER vat_exempt_amount,
    ADD COLUMN net_amount DECIMAL(10,2) DEFAULT NULL AFTER discount_amount,
    ADD COLUMN discount_applied_by VARCHAR(100) DEFAULT NULL AFTER net_amount,
    ADD COLUMN discount_applied_at DATETIME DEFAULT NULL AFTER discount_applied_by,
    ADD KEY idx_customer_discount (discount_type);

ALTER TABLE transaction_history
    ADD COLUMN gross_amount DECIMAL(10,2) DEFAULT NULL AFTER amount,
    ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER gross_amount,
    ADD KEY idx_tx_discount (discount_amount);

-- Payment instructions + deposit share are owner-editable from Settings.
INSERT INTO site_options (option_key, option_value)
VALUES
    ('deposit_rate', '50'),
    ('deposit_gcash_number', '0917 000 0000'),
    ('deposit_bank_details', 'Marle Hotel & Restaurant — BDO Savings (ask the front desk for the account number)')
ON DUPLICATE KEY UPDATE option_value = VALUES(option_value);
