-- MarleHotel Phase 16: reservation deposit verification + cancellation forfeiture.
--
-- Run ONCE against marlehoteldb (not idempotent):
--   mysql -uroot marlehoteldb < database/phase16_booking_deposit.sql
--
-- The deposit flow (chosen by the owner):
--   1. the guest books on the website            → deposit_status = 'unpaid'
--   2. the guest pays by GCash / bank transfer and uploads proof on the
--      booking status page                       → deposit_status = 'for_verification'
--   3. a manager checks the proof in Requests → Bookings and verifies it
--                                                → deposit_status = 'verified'
--      Only then can the booking be approved (or a manager may override with a
--      reason, which is audit-logged).
--
-- Cancelling forfeits a flat 50% of the booking total; the recorded
-- cancellation_fee_amount is what the hotel keeps, and the notice window is
-- kept for the paper trail.

ALTER TABLE bookings
    ADD COLUMN deposit_rate DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER terms_version,
    ADD COLUMN deposit_amount DECIMAL(10,2) DEFAULT NULL AFTER deposit_rate,
    ADD COLUMN deposit_status ENUM('unpaid', 'for_verification', 'verified', 'rejected')
        NOT NULL DEFAULT 'unpaid' AFTER deposit_amount,
    ADD COLUMN deposit_reference VARCHAR(80) DEFAULT NULL AFTER deposit_status,
    ADD COLUMN deposit_proof_path VARCHAR(255) DEFAULT NULL AFTER deposit_reference,
    ADD COLUMN deposit_submitted_at DATETIME DEFAULT NULL AFTER deposit_proof_path,
    ADD COLUMN deposit_verified_by VARCHAR(100) DEFAULT NULL AFTER deposit_submitted_at,
    ADD COLUMN deposit_verified_at DATETIME DEFAULT NULL AFTER deposit_verified_by,
    ADD COLUMN deposit_rejection_reason VARCHAR(255) DEFAULT NULL AFTER deposit_verified_at,
    ADD COLUMN cancellation_fee_amount DECIMAL(10,2) DEFAULT NULL AFTER cancellation_reason,
    ADD COLUMN cancellation_notice_hours INT DEFAULT NULL AFTER cancellation_fee_amount,
    ADD COLUMN cancellation_policy VARCHAR(60) DEFAULT NULL AFTER cancellation_notice_hours,
    ADD KEY idx_booking_deposit_status (deposit_status);

-- The staff bell gained a deposit event so a new proof of payment is not missed.
ALTER TABLE notifications
    MODIFY COLUMN type ENUM('booking', 'chat', 'extend', 'service', 'food', 'deposit')
        NOT NULL;
