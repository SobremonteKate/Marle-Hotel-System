-- MarleHotel Phase 24: login brute-force lockout.
-- Non-destructive migration. Apply once (plain ALTER, no idempotency guard — do NOT re-run).
-- Pairs with login.php, which counts failed password attempts per account and
-- locks the account for MARLE_LOGIN_LOCK_MINUTES once MARLE_LOGIN_MAX_ATTEMPTS
-- (default 3) is reached.

-- failed_attempts : consecutive wrong-password strikes since the last success.
-- locked_until    : when non-NULL and in the future, the account cannot sign in.
--                   An expired value clears itself on the next attempt, so a
--                   lock is never permanent and needs no manual unlock.
ALTER TABLE userstbl
    ADD COLUMN failed_attempts INT NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN locked_until DATETIME NULL DEFAULT NULL AFTER failed_attempts,
    ADD COLUMN last_failed_at DATETIME NULL DEFAULT NULL AFTER locked_until;
