<?php
// ============================================================
//  admin/includes/token_sweep.php
//  Shared sweep function — included by other files, no output.
//
//  Call:  $result = run_token_sweep($conn);
//
//  Returns:
//    [
//      'checked_out_count' => int,   // expired tokens + ended stays → customers flipped
//      'archived_count'    => int,   // customers archived → deleted
//    ]
//
//  Idempotent: safe to call on every page load / every cron tick.
//  Requires: an open $conn (MySQLi) passed in as argument.
// ============================================================

if (!function_exists('run_token_sweep')) {

    function run_token_sweep($conn): array {

        // No connection: there is nothing to sweep and nothing to report. The
        // parameter is deliberately NOT typed `mysqli` — marle_db_connect()
        // returns false when MySQL is down, and a typed parameter turns that
        // into an uncaught TypeError *before* the calling page's own
        // db_guard line, which would make every guard after it unreachable.
        // The caller is expected to have degraded already; this only makes sure
        // the sweep is never the thing that takes the page down.
        if (!($conn instanceof mysqli)) {
            return ['checked_out_count' => 0, 'archived_count' => 0];
        }

        $checked_out_count = 0;
        $archived_count    = 0;

        // ── STAGE 1: Expired tokens → flip customer to checked-out ───────────
        //
        // Fetch all expired tokens that still have a linked customer
        // who is still checked-in. Process each in its own transaction so
        // a single failure doesn't roll back the whole batch.

        $expired = $conn->prepare(
            "SELECT gt.id          AS gt_id,
                    gt.token,
                    gt.room_number,
                    gt.customer_id,
                    gt.hours_of_stay,
                    gt.created_at  AS issued_at,
                    gt.expires_at,
                    gt.created_by
             FROM   guest_tokens gt
             INNER JOIN customerstbl c ON c.id = gt.customer_id
             WHERE  gt.expires_at <= NOW()
               AND  c.status       = 'checked-in'"
        );
        $expired->execute();
        $expiredRows = $expired->get_result()->fetch_all(MYSQLI_ASSOC);
        $expired->close();

        foreach ($expiredRows as $row) {
            $conn->begin_transaction();
            try {
                // 1a. Flip customer status + stamp checked_out_at
                $upd = $conn->prepare(
                    "UPDATE customerstbl
                     SET    status         = 'checked-out',
                            checked_out_at = COALESCE(checked_out_at, ?)
                     WHERE  id     = ?
                       AND  status = 'checked-in'"   // guard: skip if already flipped
                );
                $upd->bind_param('si', $row['expires_at'], $row['customer_id']);
                $upd->execute();
                $upd->close();

                // 1b. Copy token to history (reason = expired)
                $hist = $conn->prepare(
                    "INSERT INTO guest_tokens_history
                         (original_id, token, room_number, customer_id,
                          hours_of_stay, issued_at, expires_at, moved_at, reason, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'expired', ?)"
                );
                $hist->bind_param(
                    'issiisss',
                    $row['gt_id'],
                    $row['token'],
                    $row['room_number'],
                    $row['customer_id'],
                    $row['hours_of_stay'],
                    $row['issued_at'],
                    $row['expires_at'],
                    $row['created_by']
                );
                $hist->execute();
                $hist->close();

                // 1c. Delete from guest_tokens
                $del = $conn->prepare(
                    "DELETE FROM guest_tokens WHERE id = ?"
                );
                $del->bind_param('i', $row['gt_id']);
                $del->execute();
                $del->close();

                $conn->commit();
                $checked_out_count++;

            } catch (Throwable $e) {
                $conn->rollback();
                // Non-fatal: log and continue with remaining tokens
                error_log('[token_sweep] Stage 1 failed for gt_id=' . $row['gt_id'] . ': ' . $e->getMessage());
            }
        }

        // ── STAGE 1b: Orphaned stays — stay ended but never checked out ─────
        //
        // Revoking a token deletes its guest_tokens row, so Stage 1 can no
        // longer see it and the linked customer would stay 'checked-in'
        // forever (room locked on the board, guest listed as checked-in).
        // Any stay whose end time has passed and that has no live token for
        // the customer or the room is flipped to checked-out here.

        $orphan = $conn->prepare(
            "SELECT c.id, c.date_to
             FROM   customerstbl c
             WHERE  c.status  = 'checked-in'
               AND  c.date_to IS NOT NULL
               AND  c.date_to <= NOW()
               AND  NOT EXISTS (
                        SELECT 1
                        FROM   guest_tokens gt
                        WHERE  gt.expires_at > NOW()
                          AND  (gt.customer_id = c.id OR gt.room_number = c.room_no)
                    )"
        );
        $orphan->execute();
        $orphanRows = $orphan->get_result()->fetch_all(MYSQLI_ASSOC);
        $orphan->close();

        foreach ($orphanRows as $row) {
            $upd = $conn->prepare(
                "UPDATE customerstbl
                 SET    status         = 'checked-out',
                        checked_out_at = COALESCE(checked_out_at, date_to, NOW())
                 WHERE  id     = ?
                   AND  status = 'checked-in'"
            );
            $upd->bind_param('i', $row['id']);
            $upd->execute();
            if ($upd->affected_rows > 0) {
                $checked_out_count++;
            }
            $upd->close();
        }

        // ── STAGE 2: Checked-out > 90 days → copy to archivetbl, delete ──────
        //
        // Only customers whose checked_out_at is set (i.e. swept by Stage 1,
        // not manually checked out before this column existed).

        $stale = $conn->prepare(
            "SELECT id, room_no, transportation, plate_no,
                    hours, additional, date_from, date_to
             FROM   customerstbl
             WHERE  status          = 'checked-out'
               AND  checked_out_at IS NOT NULL
               AND  checked_out_at <= NOW() - INTERVAL 90 DAY"
        );
        $stale->execute();
        $staleRows = $stale->get_result()->fetch_all(MYSQLI_ASSOC);
        $stale->close();

        // The Archive page's "Archived" column, its date filters (Today / This week /
        // This month / a chosen date) and its "oldest archive" stat all read
        // archivetbl.archived_at, so a stay this sweep archives has to be stamped with
        // the moment it was archived — without it every swept row reads '-' and no date
        // filter can ever match it. The column is optional on an older database
        // (archive.php probes for it the same way), so the insert adapts instead of
        // failing and silently never archiving anything.
        $hasArchivedAt = false;
        if ($colRes = $conn->query("SHOW COLUMNS FROM archivetbl LIKE 'archived_at'")) {
            $hasArchivedAt = $colRes->num_rows > 0;
        }

        foreach ($staleRows as $row) {
            $conn->begin_transaction();
            try {
                // 2a. Copy to archivetbl
                //     Note: archivetbl uses 'additionals' (not 'additional')
                //     and has no 'persons' column.
                $arch = $conn->prepare(
                    $hasArchivedAt
                        ? "INSERT INTO archivetbl
                               (room_no, transportation, plate_no,
                                hours, additionals, date_from, date_to, archived_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
                        : "INSERT INTO archivetbl
                               (room_no, transportation, plate_no,
                                hours, additionals, date_from, date_to)
                           VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $arch->bind_param(
                    'sssssss',
                    $row['room_no'],
                    $row['transportation'],
                    $row['plate_no'],
                    $row['hours'],
                    $row['additional'],   // maps to archivetbl.additionals
                    $row['date_from'],
                    $row['date_to']
                );
                $arch->execute();
                $arch->close();

                // 2b. Delete from customerstbl
                $del = $conn->prepare(
                    "DELETE FROM customerstbl WHERE id = ?"
                );
                $del->bind_param('i', $row['id']);
                $del->execute();
                $del->close();

                $conn->commit();
                $archived_count++;

            } catch (Throwable $e) {
                $conn->rollback();
                error_log('[token_sweep] Stage 2 failed for customer_id=' . $row['id'] . ': ' . $e->getMessage());
            }
        }

        return [
            'checked_out_count' => $checked_out_count,
            'archived_count'    => $archived_count,
        ];
    }

} // end if (!function_exists)
