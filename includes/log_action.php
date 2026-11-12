<?php
/**
 * log_event — System Log writer for Marle Hotel (audit_log table).
 *
 * Single entry point for every mutating action in the system.
 * Safe to call from any context: staff sessions (superadmin/admin/employee)
 * and the guest portal (actor recorded as 'system' with room context).
 *
 * Always fails silently — logging must never break a business operation.
 *
 * Usage:
 *   log_event($conn, 'user_created', 'user', 4, 'success', 'role=employee');
 *   log_event($conn, 'token_generated', 'guest_token', 12, 'success', null, ['room_number' => '8']);
 *
 * Table: audit_log (database/phase11_access_control_audit.sql)
 */

if (!function_exists('log_event')) {
    function log_event(
        $conn,
        string $action,
        ?string $targetType = null,
        $targetId = null,
        string $outcome = 'success',
        ?string $reason = null,
        array $context = []
    ): void {
        try {
            // ── Actor (staff session) ──────────────────────────────────────────
            if (function_exists('session_status') && session_status() === PHP_SESSION_NONE && !headers_sent()) {
                @session_start();
            }
            $role = $_SESSION['usertype'] ?? null;
            $actorRole = is_string($role) && $role !== '' ? $role : 'unknown';

            $actorUserId = null;
            $actorUsername = null;
            if (isset($_SESSION['user_id']) || isset($_SESSION['username'])) {
                $actorUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
                $actorUsername = isset($_SESSION['username']) ? (string)$_SESSION['username'] : null;
            } else {
                // Portal / cron / unauthenticated context
                $actorRole = 'system';
            }

            // Context override — used by failed/denied logins so the attempted
            // username is still visible in the log without a session.
            if (isset($context['actor_username']) && is_string($context['actor_username']) && $context['actor_username'] !== '') {
                $actorUsername = substr($context['actor_username'], 0, 100);
            }

            // Context overrides (e.g. actor recorded while acting for a room)
            if (isset($context['actor_role']) && is_string($context['actor_role']) && $context['actor_role'] !== '') {
                $actorRole = $context['actor_role'];
            }

            // ── Request metadata ───────────────────────────────────────────────
            $ip = $context['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $ip = substr($ip, 0, 45);
            }
            $userAgent = $context['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
            if (is_string($userAgent)) {
                $userAgent = substr($userAgent, 0, 512);
            }

            // ── Snapshots (optional structured before/after) ───────────────────
            // JSON_INVALID_UTF8_SUBSTITUTE matters: json_encode() returns false on
            // a bad byte, and an empty string then fails the after_snapshot/before_
            // snapshot JSON constraint — which dropped the whole audit row, silently,
            // because log_event must never throw. A garbled character in a snapshot
            // is a small problem; losing the audit entry for a money change is not.
            $flags  = JSON_INVALID_UTF8_SUBSTITUTE;
            $before = isset($context['before']) ? json_encode($context['before'], $flags) : null;
            $after  = isset($context['after']) ? json_encode($context['after'], $flags) : null;
            if ($before === false) $before = null;
            if ($after === false)  $after  = null;

            $sql = "INSERT INTO audit_log
                        (actor_user_id, actor_username, actor_role, action, target_type, target_id,
                         outcome, reason, before_snapshot, after_snapshot, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = mysqli_prepare($conn, $sql);
            if (!$stmt) {
                error_log('log_event prepare failed: ' . mysqli_error($conn));
                return;
            }

            // Unauthenticated contexts (portal, failed logins, cron) must store NULL, not 0:
            // a sentinel 0 would violate fk_audit_actor and silently drop the row.
            $boundUserId   = $actorUserId;
            $boundTargetId = $targetId !== null ? (int)$targetId : null;

            mysqli_stmt_bind_param(
                $stmt,
                'issssissssss',
                $boundUserId,
                $actorUsername,
                $actorRole,
                $action,
                $targetType,
                $boundTargetId,
                $outcome,
                $reason,
                $before,
                $after,
                $ip,
                $userAgent
            );

            if (!mysqli_stmt_execute($stmt)) {
                error_log('log_event execute failed: ' . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        } catch (Throwable $e) {
            error_log('log_event failed: ' . $e->getMessage());
        }
    }
}
