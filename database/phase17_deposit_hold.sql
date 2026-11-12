-- ============================================================
--  phase17_deposit_hold.sql
--  Hold window for unpaid reservation deposits (2026-10-12).
--
--  An unpaid booking blocks its room, so it is only held for
--  `deposit_hold_hours`; past that, admin/cron_expire_deposits.php cancels it
--  (cancelled_by = 'system'), frees the room and emails the guest. Nothing is
--  ever charged on a release.
--
--  Safe to re-run: this only seeds one site_options row and INSERT IGNORE can
--  never overwrite a value the owner has already changed in
--  Settings → Reservation Deposit.
--
--  The code already falls back to MARLE_DEPOSIT_HOLD_HOURS_DEFAULT (24) when the
--  row is missing, so applying this file is optional — it just makes the
--  default visible/editable in the settings table.
-- ============================================================

INSERT IGNORE INTO site_options (option_key, option_value)
VALUES ('deposit_hold_hours', '24');
