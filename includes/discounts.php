<?php
/**
 * discounts.php — statutory guest discounts (Philippines).
 *
 * Senior Citizen (RA 9994) and Person with Disability (RA 7277) guests are
 * entitled to a 20% discount, and — for a VAT-registered establishment — the
 * sale is VAT-exempt as well. The order matters:
 *
 *     gross (room price, VAT included)
 *       -> vat_exempt = gross / 1.12      (remove the 12% VAT)
 *       -> discount   = vat_exempt × 20%  (the statutory discount)
 *       -> net        = vat_exempt - discount   (what the guest pays)
 *
 * Only the CHECK-IN amount is discounted (the stay charge at token issue), and
 * the discount belongs to one guest's own consumption, which matches how the
 * front desk applies it in practice.
 *
 * The same functions run on the server AND feed the live preview in the issue
 * form, so the number the clerk sees is the number that is stored.
 */

require_once __DIR__ . '/booking_deposit.php'; // marle_peso()

/** Philippine VAT rate applied when stripping VAT off a VAT-inclusive price. */
if (!defined('MARLE_VAT_RATE')) {
    define('MARLE_VAT_RATE', 0.12);
}

/** Is the establishment VAT-registered? Drives the discount computation. */
if (!defined('MARLE_VAT_REGISTERED')) {
    define('MARLE_VAT_REGISTERED', true);
}

if (!function_exists('marle_discount_types')) {
    /**
     * Selectable discount types for the check-in form.
     * @return array<string,string> machine value => label
     */
    function marle_discount_types(): array
    {
        return [
            'none'   => 'No discount',
            'senior' => 'Senior Citizen (RA 9994)',
            'pwd'    => 'Person with Disability (RA 7277)',
            'other'  => 'Other (custom %)',
        ];
    }
}

if (!function_exists('marle_discount_label')) {
    /** Short badge label for a stored type. */
    function marle_discount_label(string $type): string
    {
        return match ($type) {
            'senior' => 'Senior Citizen',
            'pwd'    => 'PWD',
            'other'  => 'Discount',
            default  => 'No discount',
        };
    }
}

if (!function_exists('marle_discount_rate')) {
    /**
     * The discount rate for a type. Senior and PWD are fixed at 20% by law;
     * "other" carries whatever the clerk entered (validated 1–50).
     */
    function marle_discount_rate(string $type, float $customPercent = 0.0): float
    {
        return match ($type) {
            'senior', 'pwd' => 20.0,
            'other'         => round(max(0.0, min(50.0, $customPercent)), 2),
            default         => 0.0,
        };
    }
}

if (!function_exists('marle_discount_requires_id')) {
    /** Types that must carry the guest's OSCA / PWD ID number. */
    function marle_discount_requires_id(string $type): bool
    {
        return in_array($type, ['senior', 'pwd'], true);
    }
}

if (!function_exists('marle_discount_id_rules')) {
    /**
     * The shapes the law prints statutory IDs in, in one place.
     *
     * Both the issue form (placeholder, hint, HTML pattern, live check) and the
     * server-side validation read this, so the clerk can never be told one thing
     * and refused for another:
     *
     *   Senior Citizen (RA 9994)  YYYY-NNNNN               e.g. 2026-04812
     *   PWD (RA 7277)             RR-PPMM-BBB-NNNNNNN      e.g. 13-7405-000-0001234
     *
     * @return array{label:string, format:string, sample:string, regex:string,
     *               js_regex:string, pattern:string, segments:array<int,int>,
     *               maxlength:int, hint:string}|null  null = this type carries no ID
     */
    function marle_discount_id_rules(string $type): ?array
    {
        return match ($type) {
            'senior' => [
                'label'     => 'OSCA / Senior Citizen ID',
                'format'    => 'YYYY-NNNNN',
                'sample'    => '2026-04812',
                'regex'     => '/^\d{4}-\d{5}$/',
                'js_regex'  => '^\d{4}-\d{5}$',
                'pattern'   => '[0-9]{4}-[0-9]{5}',
                'segments'  => [4, 5],
                'maxlength' => 10,
                'hint'      => 'As printed on the OSCA ID: YYYY-NNNNN — e.g. 2026-04812.',
            ],
            'pwd' => [
                'label'     => 'PWD ID',
                'format'    => 'RR-PPMM-BBB-NNNNNNN',
                'sample'    => '13-7405-000-0001234',
                'regex'     => '/^\d{2}-\d{4}-\d{3}-\d{7}$/',
                'js_regex'  => '^\d{2}-\d{4}-\d{3}-\d{7}$',
                'pattern'   => '[0-9]{2}-[0-9]{4}-[0-9]{3}-[0-9]{7}',
                'segments'  => [2, 4, 3, 7],
                'maxlength' => 20,
                'hint'      => 'As printed on the PWD ID: RR-PPMM-BBB-NNNNNNN — e.g. 13-7405-000-0001234.',
            ],
            default => null,
        };
    }
}

if (!function_exists('marle_discount_format_id')) {
    /**
     * Put the dashes the ID is printed with into whatever the clerk typed.
     *
     * Progressive on purpose: "13-7" stays "13-7" instead of being rejected
     * mid-keystroke, so the field can dash itself as the clerk types. Non-digits
     * are dropped (people copy IDs with spaces and dots), and anything longer
     * than the printed shape is cut to it.
     */
    function marle_discount_format_id(string $type, string $raw): string
    {
        $rules = marle_discount_id_rules($type);
        $raw   = trim($raw);
        if ($rules === null) {
            return $raw;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        $digits = substr((string)$digits, 0, array_sum($rules['segments']));

        $parts = [];
        $rest  = $digits;
        foreach ($rules['segments'] as $len) {
            if ($rest === '') break;
            $parts[] = substr($rest, 0, $len);
            $rest    = substr($rest, $len);
        }

        return implode('-', $parts);
    }
}

if (!function_exists('marle_discount_normalize_id')) {
    /** The canonical form to store: the ID exactly as the card prints it. */
    function marle_discount_normalize_id(string $type, string $raw): string
    {
        $formatted = marle_discount_format_id($type, $raw);
        return marle_discount_id_error($type, $formatted) === null ? $formatted : trim($raw);
    }
}

if (!function_exists('marle_discount_id_error')) {
    /**
     * The sentence to show when the ID is not acceptable — null when it is.
     * Returns null for types that carry no ID ('none', 'other').
     */
    function marle_discount_id_error(string $type, string $raw): ?string
    {
        $rules = marle_discount_id_rules($type);
        if ($rules === null) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return 'Enter the guest\'s ' . $rules['label'] . ' number (' . $rules['format']
                 . ') — the statutory discount is only valid with the ID presented.';
        }

        $formatted = marle_discount_format_id($type, $raw);
        if (!preg_match($rules['regex'], $formatted)) {
            return 'The ' . $rules['label'] . ' must follow the printed format ' . $rules['format']
                 . ' (e.g. ' . $rules['sample'] . '). "' . mb_substr($raw, 0, 40) . '" does not.';
        }

        return null;
    }
}

if (!function_exists('marle_mask_id')) {
    /** Never write a full ID number into logs or notes — keep the last 4. */
    function marle_mask_id(string $idNo): string
    {
        $idNo = trim($idNo);
        if ($idNo === '') return '';
        if (strlen($idNo) <= 4) return '••••';
        return str_repeat('•', 4) . substr($idNo, -4);
    }
}

if (!function_exists('marle_compute_discount')) {
    /**
     * Full money breakdown for a gross amount and discount type.
     *
     * @param float  $gross         VAT-inclusive amount before any discount
     * @param string $type          none|senior|pwd|other
     * @param float  $customPercent only used by 'other'
     * @return array{gross:float, vat_amount:float, vat_exempt_amount:float,
     *               percent:float, discount_amount:float, net_amount:float,
     *               has_discount:bool}
     */
    function marle_compute_discount(float $gross, string $type, float $customPercent = 0.0): array
    {
        $gross  = round(max(0.0, $gross), 2);
        $rate   = marle_discount_rate($type, $customPercent);
        $pct    = $rate / 100;

        if ($pct <= 0) {
            return [
                'gross'             => $gross,
                'vat_amount'        => 0.0,
                'vat_exempt_amount' => $gross,
                'percent'           => 0.0,
                'discount_amount'   => 0.0,
                'net_amount'        => $gross,
                'has_discount'      => false,
            ];
        }

        if (MARLE_VAT_REGISTERED) {
            // Strip VAT first, then apply the statutory discount to the net of VAT.
            $vatExempt = round($gross / (1 + MARLE_VAT_RATE), 2);
        } else {
            $vatExempt = $gross;
        }

        $discount = round($vatExempt * $pct, 2);

        return [
            'gross'             => $gross,
            'vat_amount'        => round($gross - $vatExempt, 2),
            'vat_exempt_amount' => $vatExempt,
            'percent'           => $rate,
            'discount_amount'   => $discount,
            'net_amount'        => round($vatExempt - $discount, 2),
            'has_discount'      => true,
        ];
    }
}

if (!function_exists('marle_discount_from_row')) {
    /**
     * Rebuild a marle_compute_discount()-shaped array from a stored
     * customerstbl row, so the token cards and slips show the discount that was
     * actually recorded at check-in (not a recomputation of today's rules).
     */
    function marle_discount_from_row(array $row): array
    {
        $type = (string)($row['discount_type'] ?? 'none');
        $gross = (float)($row['gross_amount'] ?? 0);
        $vatExempt = (float)($row['vat_exempt_amount'] ?? 0);

        return [
            'gross'             => $gross,
            'vat_amount'        => max(0.0, round($gross - $vatExempt, 2)),
            'vat_exempt_amount' => $vatExempt,
            'percent'           => (float)($row['discount_percent'] ?? 0),
            'discount_amount'   => (float)($row['discount_amount'] ?? 0),
            'net_amount'        => (float)($row['net_amount'] ?? 0),
            'has_discount'      => ($type !== '' && $type !== 'none'),
        ];
    }
}

if (!function_exists('marle_discount_form_assets')) {
    /**
     * The issuing form's live discount preview.
     *
     * Preview only — the server recomputes every figure with the same rules
     * before anything is stored, so a tampered page cannot buy a discount.
     *
     * @param array $roomMeta roomstbl rows keyed by room number (room_type)
     */
    function marle_discount_form_assets(array $roomMeta): void
    {
        $roomTypes = [];
        foreach ($roomMeta as $roomNo => $meta) {
            $roomTypes[(string)$roomNo] = (string)($meta['room_type'] ?? '');
        }

        // Only the fields the form needs: the regexes stay server-side.
        $idRules = [];
        foreach (['senior', 'pwd'] as $idType) {
            $rules = marle_discount_id_rules($idType);
            if ($rules === null) continue;
            $idRules[$idType] = [
                'label'     => $rules['label'],
                'format'    => $rules['format'],
                'sample'    => $rules['sample'],
                'js_regex'  => $rules['js_regex'],
                'pattern'   => $rules['pattern'],
                'segments'  => $rules['segments'],
                'maxlength' => $rules['maxlength'],
                'hint'      => $rules['hint'],
            ];
        }
        ?>
        <script>
        (function () {
            var ROOM_TYPES = <?= json_encode($roomTypes) ?>;
            var BASE_RATE  = <?= json_encode(MARLE_BASE_RATE) ?>;
            var EXT_RATE   = <?= json_encode(MARLE_EXTENSION_RATE) ?>;
            var VAT_RATE   = <?= (float)MARLE_VAT_RATE ?>;
            var ID_RULES   = <?= json_encode($idRules) ?>;

            // Locale pinned on purpose: an undefined/device locale groups by the viewer's
            // own conventions, so ₱3,500 read as ₱3.500 — or ₱3 500 — on the device's say-so.
            function peso(n) {
                return '\u20b1' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            window.updateDiscountPreview = function () {
                var typeEl  = document.getElementById('discountType');
                var idWrap  = document.getElementById('discountIdWrap');
                var pctWrap = document.getElementById('discountPctWrap');
                var out     = document.getElementById('discountPreview');
                if (!typeEl || !out) return;

                var type   = typeEl.value;
                var needsId = (type === 'senior' || type === 'pwd');
                if (idWrap)  idWrap.style.display  = needsId ? '' : 'none';
                if (pctWrap) pctWrap.style.display = (type === 'other') ? '' : 'none';
                if (window.applyDiscountIdRules) window.applyDiscountIdRules();

                var pctEl = document.getElementById('discountPercent');
                var pct = needsId ? 20 : (type === 'other' ? parseFloat(pctEl && pctEl.value ? pctEl.value : 0) : 0);

                if (!(pct > 0)) {
                    out.innerHTML = 'No discount \u2014 the guest pays the full rate.';
                    return;
                }

                var room  = (document.getElementById('selectedRoomInput') || {}).value || '';
                var hours = parseInt((document.getElementById('hoursInput') || {}).value || 0, 10);
                var roomType = ROOM_TYPES[room] || '';
                var base = BASE_RATE[roomType] || 0;
                var ext  = EXT_RATE[roomType] || 0;
                var gross = (base > 0 && hours > 0) ? base + (Math.max(0, hours - 3) * ext) : 0;

                if (gross <= 0) {
                    out.innerHTML = 'Pick a room and hours of stay to preview the discount.';
                    return;
                }

                var vatExempt = VAT_RATE > 0 ? gross / (1 + VAT_RATE) : gross;
                var discount  = vatExempt * (pct / 100);
                out.innerHTML = 'VAT-exempt ' + peso(vatExempt) + ' &minus; ' + pct + '% (' + peso(discount)
                              + ') = <strong>' + peso(vatExempt - discount) + '</strong> payable';
            };

            /* ── Statutory ID formats (RA 9994 / RA 7277) ─────────────────────
               The server validates the same shape (marle_discount_id_error()),
               so this only stops a typo before it is submitted — and it dashes
               the number as it is typed, the way the card prints it. */
            function idRulesFor(type) {
                return Object.prototype.hasOwnProperty.call(ID_RULES, type) ? ID_RULES[type] : null;
            }

            function idRulesMatch(type, value) {
                var rules = idRulesFor(type);
                if (!rules) return true;
                return new RegExp(rules.js_regex).test(String(value).trim());
            }

            function formatDiscountId(type, raw) {
                var rules = idRulesFor(type);
                if (!rules) return raw;

                var digits = String(raw == null ? '' : raw).replace(/[^0-9]/g, '');
                var max = rules.segments.reduce(function (sum, len) { return sum + len; }, 0);
                digits = digits.slice(0, max);

                var parts = [];
                var rest  = digits;
                rules.segments.forEach(function (len) {
                    if (!rest) return;
                    parts.push(rest.slice(0, len));
                    rest = rest.slice(len);
                });
                return parts.join('-');
            }

            window.applyDiscountIdRules = function (fromTyping) {
                var typeEl = document.getElementById('discountType');
                var input  = document.getElementById('discountIdNo');
                var hint   = document.getElementById('discountIdHint');
                if (!typeEl || !input) return;

                var type  = typeEl.value;
                var rules = idRulesFor(type);

                if (!rules) {
                    input.removeAttribute('pattern');
                    input.removeAttribute('inputmode');
                    input.setCustomValidity('');
                    if (hint) { hint.className = 'form-text'; hint.textContent = ''; }
                    return;
                }

                input.setAttribute('placeholder', rules.sample);
                input.setAttribute('pattern', rules.pattern);
                input.setAttribute('maxlength', String(rules.maxlength));
                input.setAttribute('inputmode', 'numeric');
                input.setAttribute('title', rules.hint);

                if (fromTyping) {
                    var formatted = formatDiscountId(type, input.value);
                    if (formatted !== input.value) input.value = formatted;
                }

                var complete = idRulesMatch(type, input.value);
                if (hint) {
                    if (!input.value) {
                        hint.className = 'form-text';
                        hint.textContent = 'Format: ' + rules.format + ' — e.g. ' + rules.sample + ' (' + rules.label + ').';
                    } else if (complete) {
                        hint.className = 'form-text text-success';
                        hint.textContent = 'Format OK — ' + rules.format + '.';
                    } else {
                        hint.className = 'form-text text-danger';
                        hint.textContent = 'Incomplete: ' + rules.format + ' — e.g. ' + rules.sample + '.';
                    }
                }

                // Keep the browser's own submit-blocking in step with the server.
                input.setCustomValidity(complete || !input.value ? '' : ' ');
            };

            document.addEventListener('DOMContentLoaded', function () {
                window.updateDiscountPreview();
                window.applyDiscountIdRules();
            });
        })();
        </script>
        <?php
    }
}

if (!function_exists('marle_discount_breakdown_html')) {
    /** Renders the computation for a tooltip/panel (already escaped). */
    function marle_discount_breakdown_html(array $calc): string
    {
        if (empty($calc['has_discount'])) {
            return '<span class="text-muted">No discount applied</span>';
        }
        return 'VAT-exempt: <strong>' . marle_peso((float)$calc['vat_exempt_amount']) . '</strong>'
             . ' &minus; ' . rtrim(rtrim(number_format((float)$calc['percent'], 2, '.', ''), '0'), '.')
             . '% (' . marle_peso((float)$calc['discount_amount']) . ')'
             . ' = <strong>' . marle_peso((float)$calc['net_amount']) . '</strong>';
    }
}
