-- MarleHotel Phase 26: how a booking's reservation deposit was paid.
--
-- Run ONCE against marlehoteldb (not idempotent):
--   mysql -uroot marlehoteldb < database/phase26_booking_payment_method.sql
--
-- WHY THIS EXISTS
--   Until now every reservation deposit arrived the same way: the guest paid by
--   GCash / bank transfer and uploaded a receipt, and a manager verified that
--   receipt. A guest who walks in and puts cash on the desk has no receipt to
--   upload, so `marle_deposit_verify()` — which refuses to verify without a
--   proof file — could never record their money.
--
--   The column says which method was used. NULL means "not recorded", which is
--   every row created before this migration; those keep behaving exactly as
--   they did (proof required). 'cash' is the one method whose money is taken
--   in person, so it is verified by the person who counted it and is allowed to
--   have no uploaded proof.
--
--   It is deliberately NOT an enum value on deposit_status: "cash" is a way of
--   paying, not a state the deposit is in.

ALTER TABLE bookings
    ADD COLUMN payment_method ENUM('cash', 'gcash', 'bank')
        NULL DEFAULT NULL AFTER deposit_proof_path;
