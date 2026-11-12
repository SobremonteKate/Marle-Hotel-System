<?php
/**
 * token_print.php — the printable guest-token slip.
 *
 * Rendered once per page (a hidden container at the end of <body>) and filled
 * by JavaScript from the data-slip JSON carried by each card's Print button.
 * Printing hides the whole app with CSS and prints only the container, so the
 * slip comes out as an A4 half-page on any printer — no PDF library, nothing
 * to install, and it works on Hostinger exactly as it does locally.
 *
 * The QR code points at the guest portal LOGIN page, never at a URL containing
 * the token: a scanned slip should ask for the code, not hand out a session.
 *
 * The slip deliberately prints NO portal address: the paper carries the token
 * and the stay facts, and the QR is the only way across. When the QR library
 * cannot be fetched (offline front desk) the caption says so instead of
 * falling back to printing the URL.
 */

require_once __DIR__ . '/discounts.php';

if (!function_exists('marle_token_portal_login_url')) {
    /** Absolute URL of the guest portal login, for the QR code. */
    function marle_token_portal_login_url(): string
    {
        if (!function_exists('marle_absolute_url')) {
            require_once __DIR__ . '/send_email.php';
        }
        return marle_absolute_url('portal/guestLogin.php');
    }
}

if (!function_exists('marle_token_print_assets')) {
    /** CSS + QR library + the print helper. Echo inside the page. */
    function marle_token_print_assets(string $hotelName = 'Marle Hotel & Restaurant'): void
    {
        $loginUrl = marle_token_portal_login_url();
        $hotel    = htmlspecialchars($hotelName, ENT_QUOTES, 'UTF-8');
        ?>
        <style>
            /* ── Printable guest slip (hidden on screen, the only thing on paper) ── */
            #marlePrintRoot {
                display: none;
                font-family: "DM Sans", "Segoe UI", Arial, sans-serif;
                color: #1e1108;
            }
            .slip {
                border: 1.5px solid #933310;
                border-radius: 10px;
                padding: 18px 20px 16px;
                max-width: 190mm;
            }
            .slip-head {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                border-bottom: 2px solid #ffb60a;
                padding-bottom: 10px;
                margin-bottom: 14px;
            }
            .slip-hotel { font-size: 19px; font-weight: 700; letter-spacing: .3px; }
            .slip-kicker { font-size: 11px; text-transform: uppercase; letter-spacing: 1.4px; color: #933310; font-weight: 700; }
            .slip-token-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1.2px; color: #7a6455; }
            .slip-token {
                font-family: "Courier New", monospace;
                font-size: 30px;
                font-weight: 700;
                letter-spacing: 2px;
                color: #933310;
                line-height: 1.15;
            }
            .slip-body { display: flex; gap: 18px; align-items: flex-start; }
            .slip-facts { flex: 1; font-size: 13px; }
            .slip-row { display: flex; gap: 10px; padding: 4px 0; border-bottom: 1px dashed #eadfd6; }
            .slip-row-label { color: #7a6455; min-width: 110px; }
            .slip-row-value { font-weight: 600; }
            .slip-qr { text-align: center; }
            .slip-qr canvas,
            .slip-qr-img { width: 132px !important; height: 132px !important; image-rendering: pixelated; }
            .slip-qr-caption { font-size: 10px; color: #7a6455; max-width: 140px; margin-top: 4px; line-height: 1.35; }
            .slip-qr-fallback { font-size: 10.5px; color: #7a6455; word-break: break-all; max-width: 140px; }
            .slip-discount {
                margin-top: 12px;
                background: #fdf6ee;
                border: 1px solid #f0d9bb;
                border-radius: 8px;
                padding: 10px 12px;
                font-size: 12.5px;
            }
            .slip-discount-title { font-weight: 700; color: #933310; margin-bottom: 4px; }
            .slip-discount-row { display: flex; justify-content: space-between; padding: 1px 0; }
            .slip-discount-total { border-top: 1px solid #e6cfae; margin-top: 4px; padding-top: 4px; font-weight: 700; }
            .slip-legal { font-size: 10px; color: #7a6455; margin-top: 8px; line-height: 1.4; }
            .slip-foot {
                margin-top: 14px;
                border-top: 1px solid #eadfd6;
                padding-top: 8px;
                font-size: 10.5px;
                color: #7a6455;
                display: flex;
                justify-content: space-between;
                gap: 12px;
            }

            /* ── Card extras: discount badge + Print button ── */
            .token-card-discount-badge {
                display: inline-block;
                margin-top: 6px;
                padding: 4px 8px;
                border-radius: 8px;
                background: #fdf6ee;
                border: 1px solid #f0d9bb;
                color: #8a4b12;
                font-size: 11px;
                font-weight: 700;
                line-height: 1.35;
                cursor: help;
            }
            .token-card-print-btn {
                flex: 0 0 auto;
                padding: 6px 12px;
                border-radius: 8px;
                border: 1px solid #d9cfc6;
                background: #fff;
                color: #5c4a3d;
                font-size: 12.5px;
                font-weight: 600;
                cursor: pointer;
            }
            .token-card-print-btn:hover { background: #933310; border-color: #933310; color: #fff; }

            @page { size: A4 portrait; margin: 12mm; }

            @media print {
                body.marle-printing > *:not(#marlePrintRoot) { display: none !important; }
                body.marle-printing #marlePrintRoot { display: block !important; }
                #marlePrintRoot { display: block !important; }
                #marlePrintRoot .slip { page-break-inside: avoid; }
            }
        </style>
        <script>
        (function () {
            var PORTAL_URL = <?= json_encode($loginUrl) ?>;

            /* ── QR library, loaded on demand with a second CDN as backstop ──
               The slip's QR code is nice-to-have, never a blocker: if both
               CDNs are unreachable (offline front desk) the URL is printed
               as text instead and the slip is still perfectly usable. */
            var qrLoading = false;
            var qrWaiters = [];

            function flushQrWaiters() {
                qrLoading = false;
                var waiters = qrWaiters;
                qrWaiters = [];
                waiters.forEach(function (fn) { fn(!!window.qrcode); });
            }

            function ensureQrLib(cb) {
                if (window.qrcode) { cb(true); return; }
                qrWaiters.push(cb);
                if (qrLoading) return;
                qrLoading = true;

                var sources = [
                    'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js',
                    'https://unpkg.com/qrcode-generator@1.4.4/qrcode.js'
                ];
                (function tryNext(index) {
                    if (index >= sources.length) { flushQrWaiters(); return; }
                    var tag = document.createElement('script');
                    tag.src = sources[index];
                    tag.onload = function () { window.qrcode ? flushQrWaiters() : tryNext(index + 1); };
                    tag.onerror = function () { tryNext(index + 1); };
                    document.head.appendChild(tag);
                })(0);
            }

            function renderQr(host, text, done) {
                if (!host) { done(); return; }
                ensureQrLib(function (ok) {
                    if (ok) {
                        try {
                            var qr = window.qrcode(0, 'M');
                            qr.addData(text);
                            qr.make();
                            host.innerHTML = '<img alt="Guest portal QR code" class="slip-qr-img" src="'
                                + qr.createDataURL(4, 2) + '">';
                            done();
                            return;
                        } catch (e) { /* fall through */ }
                    }
                    // No library, no QR — and no printed portal address either:
                    // the slip points the guest at the front desk instead.
                    host.innerHTML = '<div class="slip-qr-fallback">QR unavailable — '
                        + 'ask the front desk to open the guest portal.</div>';
                    done();
                });
            }

            function slipRow(label, value) {
                if (!value && value !== 0) return '';
                return '<div class="slip-row"><span class="slip-row-label">' + label + '</span>'
                     + '<span class="slip-row-value">' + value + '</span></div>';
            }

            function esc(v) {
                return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }

            /**
             * Build the slip and open the browser's print dialog.
             * `data` comes from the card's data-slip attribute (see the token page).
             */
            window.printTokenSlip = function (data) {
                var root = document.getElementById('marlePrintRoot');
                if (!root || !data) return;

                var discount = '';
                if (data.discount && data.discount.applied) {
                    discount =
                        '<div class="slip-discount">'
                      + '<div class="slip-discount-title">' + esc(data.discount.label) + ' discount applied</div>'
                      + '<div class="slip-discount-row"><span>Amount before discount (VAT inclusive)</span><span>' + esc(data.discount.gross) + '</span></div>'
                      + '<div class="slip-discount-row"><span>VAT-exempt amount</span><span>' + esc(data.discount.vatExempt) + '</span></div>'
                      + '<div class="slip-discount-row"><span>' + esc(data.discount.percent) + '% discount (ID ' + esc(data.discount.idMasked) + ')</span><span>&minus;' + esc(data.discount.amount) + '</span></div>'
                      + '<div class="slip-discount-row slip-discount-total"><span>Amount payable</span><span>' + esc(data.discount.net) + '</span></div>'
                      + '<div class="slip-legal">Discount granted under RA 9994 (Senior Citizens Act) / RA 7277 (Magna Carta for Persons with Disability). '
                      + 'Valid only for this stay and only with the guest\'s own ID presented.</div>'
                      + '</div>';
                }

                root.innerHTML =
                    '<div class="slip">'
                  + '<div class="slip-head"><div><div class="slip-hotel">' + esc(data.hotel) + '</div>'
                  + '<div class="slip-kicker">Guest Portal Slip</div></div>'
                  + '<div style="text-align:right"><div class="slip-token-label">Guest token</div>'
                  + '<div class="slip-token">' + esc(data.token) + '</div></div></div>'
                  + '<div class="slip-body"><div class="slip-facts">'
                  + slipRow('Room', esc(data.room))
                  + slipRow('Room type', esc(data.roomType))
                  + slipRow('Stay', esc(data.hours) + ' hour(s)')
                  + slipRow('Issued', esc(data.issued))
                  + slipRow('Expires', esc(data.expires))
                  + slipRow('Plate no.', esc(data.plate))
                  + slipRow('Persons', esc(data.persons))
                  + '</div>'
                  + '<div class="slip-qr"><div id="marleSlipQr"></div>'
                  + '<div class="slip-qr-caption">Scan to open the guest portal, then type the token above.</div></div>'
                  + '</div>'
                  + discount
                  + '<div class="slip-foot"><span>Keep this slip — the front desk will ask for the token.</span>'
                  + '<span>Front desk: 0917 000 0000</span></div>'
                  + '</div>';

                // Print once the QR is on the slip (or after a short wait if the
                // library cannot be fetched) so the paper never comes out early.
                var printed = false;
                var doPrint = function () {
                    if (printed) return;
                    printed = true;

                    document.body.classList.add('marle-printing');
                    var cleanup = function () {
                        document.body.classList.remove('marle-printing');
                        window.removeEventListener('afterprint', cleanup);
                    };
                    window.addEventListener('afterprint', cleanup);
                    window.print();
                    // Safari/iOS fallback when afterprint never fires.
                    setTimeout(cleanup, 4000);
                };

                var waitGuard = setTimeout(doPrint, 2500);
                renderQr(document.getElementById('marleSlipQr'), PORTAL_URL, function () {
                    clearTimeout(waitGuard);
                    doPrint();
                });
            };

            /** Card helper: read the JSON off the button and print it. */
            window.printTokenSlipFrom = function (btn) {
                var raw = btn && btn.getAttribute ? btn.getAttribute('data-slip') : null;
                if (!raw) return;
                try { window.printTokenSlip(JSON.parse(raw)); }
                catch (e) { console.error('printTokenSlip: bad payload', e); }
            };
        })();
        </script>
        <?php
    }
}

if (!function_exists('marle_token_print_root')) {
    /** The hidden container the slip is built into. Echo just before </body>. */
    function marle_token_print_root(): void
    {
        echo '<div id="marlePrintRoot" aria-hidden="true"></div>';
    }
}

if (!function_exists('marle_token_slip_payload')) {
    /**
     * Build one card's slip data.
     *
     * @param array  $tk           guest_tokens row (token, room_number, hours_of_stay,
     *                             created_at, expires_at)
     * @param string $roomType     room type from roomstbl
     * @param array  $customer     customerstbl row for the stay (may be empty)
     * @param array  $discount     marle_compute_discount() result (may be empty)
     * @param string $discountType
     * @param string $discountIdNo
     */
    function marle_token_slip_payload(
        array $tk,
        string $roomType = '',
        array $customer = [],
        array $discount = [],
        string $discountType = 'none',
        string $discountIdNo = '',
        string $hotelName = 'Marle Hotel & Restaurant'
    ): string {
        if (!function_exists('formatToken')) {
            // The role pages define formatToken(); keep this callable standalone.
            $raw = (string)($tk['token'] ?? '');
            $formatted = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
        } else {
            $formatted = formatToken((string)($tk['token'] ?? ''));
        }

        $applied = !empty($discount['has_discount']);

        $payload = [
            'hotel'    => $hotelName,
            'token'    => $formatted,
            'room'     => (string)($tk['room_number'] ?? ''),
            'roomType' => $roomType,
            'hours'    => (int)($tk['hours_of_stay'] ?? 0),
            'issued'   => !empty($tk['created_at']) ? date('M j, Y g:i A', strtotime((string)$tk['created_at'])) : '',
            'expires'  => !empty($tk['expires_at']) ? date('M j, Y g:i A', strtotime((string)$tk['expires_at'])) : '',
            'plate'    => (string)($customer['plate_no'] ?? ''),
            'persons'  => (int)($customer['persons'] ?? 0),
        ];

        if ($applied) {
            $payload['discount'] = [
                'applied'   => true,
                'label'     => marle_discount_label($discountType),
                'percent'   => rtrim(rtrim(number_format((float)$discount['percent'], 2, '.', ''), '0'), '.'),
                'gross'     => marle_peso((float)$discount['gross']),
                'vatExempt' => marle_peso((float)$discount['vat_exempt_amount']),
                'amount'    => marle_peso((float)$discount['discount_amount']),
                'net'       => marle_peso((float)$discount['net_amount']),
                'idMasked'  => marle_mask_id($discountIdNo),
            ];
        }

        return (string)json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
