<?php
/**
 * booking_watch.php — keeps the staff Requests → Bookings tab honest.
 *
 * The Bookings tab is rendered once, server-side: a guest who books, uploads a
 * deposit receipt, cancels, or has their booking released by the expiry sweep
 * used to be invisible until someone pressed F5. This file gives that page a
 * cheap way to notice:
 *
 *   marle_bookings_active()     — the ONE query that defines "the booking list"
 *                                 (shared by requests.php and the watch
 *                                 endpoint so both sides always compare the
 *                                 same set — a drifting query would make the
 *                                 page reload itself in a loop)
 *   marle_bookings_signature()  — sha1 over the fields the cards actually show
 *   marle_bookings_watch_assets() — the poller: when the signature moves, the
 *                                 page refreshes itself, but only when nobody
 *                                 is typing, a modal is open, or a file is
 *                                 staged; otherwise it offers a banner.
 *
 * Read-only: it never writes to the database.
 */

require_once __DIR__ . '/booking_deposit.php';

if (!function_exists('marle_bookings_active')) {
    /**
     * Every active (non-archived) booking, newest first — the exact rows the
     * Bookings tab renders.
     *
     * The deposit columns arrive with phase16 and archived_at with the archive
     * feature; both are probed so the page still works on a database where a
     * migration has not been applied yet.
     *
     * @return array<int,array<string,mixed>>
     */
    function marle_bookings_active(mysqli $conn): array
    {
        $bookWhere = '';
        $colCheck  = $conn->query("SHOW COLUMNS FROM bookings LIKE 'archived_at'");
        if ($colCheck && $colCheck->num_rows > 0) {
            $bookWhere = 'WHERE archived_at IS NULL';
        }

        $depositSelect = "NULL AS deposit_status, NULL AS deposit_amount, NULL AS deposit_reference,
                          NULL AS deposit_proof_path, NULL AS deposit_submitted_at,
                          NULL AS deposit_rejection_reason, NULL AS deposit_verified_at,
                          NULL AS cancellation_fee_amount";
        $depositColCheck = $conn->query("SHOW COLUMNS FROM bookings LIKE 'deposit_status'");
        if ($depositColCheck && $depositColCheck->num_rows > 0) {
            $depositSelect = "deposit_status, deposit_amount, deposit_reference, deposit_proof_path,
                              deposit_submitted_at, deposit_rejection_reason, deposit_verified_at,
                              cancellation_fee_amount";
        }

        // phase26: how the deposit was paid. Probed like the others so a host
        // that has not run the migration still renders the list, just without
        // the "Cash at desk" chip.
        $methodSelect = 'NULL AS payment_method';
        $methodColCheck = $conn->query("SHOW COLUMNS FROM bookings LIKE 'payment_method'");
        if ($methodColCheck && $methodColCheck->num_rows > 0) {
            $methodSelect = 'payment_method';
        }

        $rows   = [];
        $result = $conn->query(
            "SELECT id, guest_name, guest_email, guest_phone, room_type,
                    room_number, persons, check_in, check_out,
                    special_requests, status, denial_reason, created_at,
                    COALESCE(booking_type, 'daily')  AS booking_type,
                    check_in_time, check_out_time, hours_requested,
                    $depositSelect,
                    $methodSelect
             FROM bookings
             $bookWhere
             ORDER BY
                 created_at DESC,
                 id DESC"
        );

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}

if (!function_exists('marle_bookings_signature')) {
    /**
     * A fingerprint of everything a booking card shows. Any change a manager
     * would want to see (new booking, proof uploaded, deposit verified, guest
     * cancelled, auto-release, or a front-desk amendment moving the dates/room)
     * moves this string.
     *
     * The stay fields are in here on purpose: an amendment writes only those, so
     * without them a second desk would keep showing the old stay until F5.
     *
     * @param array<int,array<string,mixed>> $rows marle_bookings_active() output
     */
    function marle_bookings_signature(array $rows): string
    {
        $parts = [];
        foreach ($rows as $row) {
            $parts[] = implode('|', [
                (int)($row['id'] ?? 0),
                (string)($row['status'] ?? ''),
                (string)($row['deposit_status'] ?? ''),
                (string)($row['payment_method'] ?? ''),
                (string)($row['deposit_submitted_at'] ?? ''),
                trim((string)($row['deposit_proof_path'] ?? '')) !== '' ? 'proof' : 'noproof',
                (string)($row['deposit_rejection_reason'] ?? ''),
                (string)($row['denial_reason'] ?? ''),
                (string)($row['cancellation_fee_amount'] ?? ''),
                (string)($row['booking_type'] ?? ''),
                (string)($row['check_in'] ?? ''),
                (string)($row['check_out'] ?? ''),
                (string)($row['check_in_time'] ?? ''),
                (string)($row['check_out_time'] ?? ''),
                (string)($row['hours_requested'] ?? ''),
                (string)($row['room_number'] ?? ''),
                (string)($row['persons'] ?? ''),
            ]);
        }

        return sha1(implode("\n", $parts));
    }
}

if (!function_exists('marle_bookings_watch_assets')) {
    /**
     * The poller. Echo inside the page, after the markup it watches.
     *
     * @param string $endpoint  relative URL of the role's bookings_watch.php
     * @param string $signature the signature rendered into THIS page load
     * @param int    $pollMs    how often to ask (default 20s)
     */
    function marle_bookings_watch_assets(string $endpoint, string $signature, int $pollMs = 20000): void
    {
        ?>
        <script>
        (function () {
            var ENDPOINT  = <?= json_encode($endpoint) ?>;
            var RENDERED  = <?= json_encode($signature) ?>;
            var POLL_MS   = <?= (int)$pollMs ?>;
            var TAB_KEY   = 'marleRequestsTab';
            var SCROLL_KEY = 'marleRequestsScroll';
            var RELOAD_KEY = 'marleRequestsAutoReload';
            var HANDOFF_KEY = 'marleWalkinToast';
            var MIN_RELOAD_GAP_MS = 60000;

            /* The auto-reload timestamp outlives the page, so it is read back on the next
               load — but the gap is a decision about server activity, not about this PC.
               It used to be Date.now(): a reception clock set even a minute fast let the
               poller reload in a loop, and one set backwards made every reload look too
               soon, so the list quietly stopped refreshing itself. MarleClock.nowMs()
               (includes/server_clock.php) is the hotel's clock; both the stamp and the
               comparison come from it, so the gap means the same on every device. */
            function watchNowMs() {
                return window.MarleClock ? MarleClock.nowMs() : Date.now();   // marle-clock-ok: only reached on a page without the clock asset
            }

            /* ── Remember which tab (and how far down) the staff member was on,
                  so the automatic refresh never bounces them to the top. ── */
            function rememberTab(tabId) {
                try { sessionStorage.setItem(TAB_KEY, tabId); } catch (e) {}
            }

            function restoreTab() {
                var wanted = null;
                try { wanted = sessionStorage.getItem(TAB_KEY); } catch (e) {}
                if (!wanted) return;

                var trigger = document.querySelector('[data-bs-target="#' + wanted + '"]');
                if (!trigger) return;
                if (trigger.classList.contains('active')) return;

                if (window.bootstrap && window.bootstrap.Tab) {
                    new window.bootstrap.Tab(trigger).show();
                }
            }

            document.addEventListener('shown.bs.tab', function (e) {
                var target = e.target && e.target.getAttribute('data-bs-target');
                if (target) rememberTab(target.replace('#', ''));
            });

            /* ── Is it safe to reload without eating what someone is doing? ── */
            function userIsBusy() {
                if (document.visibilityState !== 'visible') return true;
                if (document.querySelector('.modal.show')) return true;

                var el = document.activeElement;
                if (el && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) return true;

                var files = document.querySelectorAll('input[type=file]');
                for (var i = 0; i < files.length; i++) {
                    if (files[i].files && files[i].files.length) return true;
                }
                return false;
            }

            function reloadNow(auto) {
                try {
                    sessionStorage.setItem(SCROLL_KEY, String(window.scrollY || 0));
                    if (auto) sessionStorage.setItem(RELOAD_KEY, String(watchNowMs()));
                } catch (e) {}
                window.location.reload();
            }

            function lastAutoReloadWasRecent() {
                try {
                    var ts = parseInt(sessionStorage.getItem(RELOAD_KEY) || '0', 10);
                    return ts > 0 && (watchNowMs() - ts) < MIN_RELOAD_GAP_MS;
                } catch (e) { return true; }
            }

            /* ── Fallback when reloading would be rude: an offer, not a surprise ── */
            function offerRefresh() {
                if (document.getElementById('marleBookingsBanner')) return;

                var bar = document.createElement('div');
                bar.id = 'marleBookingsBanner';
                bar.setAttribute('role', 'status');
                bar.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:2000;'
                    + 'background:#933310;color:#fff;border-radius:12px;padding:10px 14px;'
                    + 'font-size:13px;font-weight:600;box-shadow:0 6px 18px rgba(0,0,0,.25);'
                    + 'display:flex;align-items:center;gap:10px;';
                bar.innerHTML = '<span><i class="bi bi-arrow-repeat me-1"></i>'
                    + 'New booking activity</span>'
                    + '<button type="button" style="background:#ffb60a;border:0;border-radius:8px;'
                    + 'padding:4px 10px;font-size:12.5px;font-weight:700;color:#3a2413;cursor:pointer;">'
                    + 'Refresh</button>';
                bar.querySelector('button').addEventListener('click', function () { reloadNow(false); });
                document.body.appendChild(bar);
            }

            /* ── A sentence from the page that sent us here ──
                  Recording a walk-in on the calendar proves itself by landing on
                  this page, and that is where the desk should read what happened
                  (the booking is in, and whether the guest was emailed). The
                  calendar could only show it for the moment before it navigated,
                  so it hands the sentence over in sessionStorage and this prints
                  it once, on arrival. */
            function showHandoffToast() {
                var message = null;
                try {
                    message = sessionStorage.getItem(HANDOFF_KEY);
                    sessionStorage.removeItem(HANDOFF_KEY);
                } catch (e) { return; }
                if (!message) return;

                // The page's own toast, when it has one, so the look is identical
                // to every other message on this screen.
                if (typeof window.showToast === 'function' && document.getElementById('adminToast')) {
                    window.showToast(message, 'success');
                    return;
                }

                var wrap = document.createElement('div');
                wrap.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:2100;max-width:380px;'
                    + 'background:#198754;color:#fff;border-radius:12px;padding:12px 16px;'
                    + 'font-size:13.5px;font-weight:600;box-shadow:0 6px 18px rgba(0,0,0,.25);';
                wrap.setAttribute('role', 'status');
                wrap.textContent = message;
                document.body.appendChild(wrap);
                setTimeout(function () { wrap.remove(); }, 6000);
            }

            function checkNow() {
                fetch(ENDPOINT, { cache: 'no-store', credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) {
                        if (!d || d.success !== true || !d.signature) return;
                        if (d.signature === RENDERED) return;

                        if (userIsBusy() || lastAutoReloadWasRecent()) {
                            offerRefresh();
                            return;
                        }
                        reloadNow(true);
                    })
                    .catch(function () { /* offline or logged out: stay quiet */ });
            }

            var timer = setInterval(checkNow, POLL_MS);
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'visible') checkNow();
            });

            // Restore the tab + scroll position this page load (also after an
            // automatic refresh), then start watching.
            document.addEventListener('DOMContentLoaded', function () {
                restoreTab();
                showHandoffToast();
                try {
                    var y = parseInt(sessionStorage.getItem(SCROLL_KEY) || '0', 10);
                    if (y > 0) window.scrollTo(0, y);
                    sessionStorage.removeItem(SCROLL_KEY);
                } catch (e) {}
                setTimeout(checkNow, 1500);
            });

            // Exposed so a page-driven change (cancelling a booking) can refresh
            // itself through the same path as the automatic one — the tab and the
            // scroll position come back with it.
            window.marleBookingsWatch = {
                check: checkNow,
                reload: function () { reloadNow(false); },
                stop: function () { clearInterval(timer); }
            };
        })();
        </script>
        <?php
    }
}
