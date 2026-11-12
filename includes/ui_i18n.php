<?php
/**
 * ui_i18n.php — the English / Tagalog switch for the staff dashboards and the
 * guest portal.
 *
 * WHY THIS IS NOT includes/site_i18n.php
 * --------------------------------------
 * The public website (home / about / contact) uses site_i18n.php, where every
 * string is written as an inline pair at the point of use:
 *
 *     <?= site_t('Home', 'Tahanan') ?>
 *
 * That is the right shape for three hand-written pages. It is the wrong shape
 * for these screens: the ten staff pages exist three times over (superadmin,
 * admin, employee) and are 400–3,700 lines each — roughly 19,000 lines that
 * repeat the same sidebar, breadcrumb and logout markup in every file. Inline
 * pairs there would mean thousands of edits across thirty-three files, and
 * thirty-three chances to leave a string half-wrapped.
 *
 * So these screens translate by *phrase* instead: one table per screen
 * (includes/i18n/ui_strings.php), matched against the text the page actually
 * renders. The page keeps saying "Requests" once, in English, and the table
 * knows that "Requests" reads "Mga Kahihiling" in Tagalog. Adding a page means
 * adding a screen id and its lines — not touching markup.
 *
 * HOW A PAGE USES IT
 * ------------------
 *   require_once __DIR__ . '/../includes/ui_i18n.php';
 *   ui_i18n_start('staff.requests');      // BEFORE any output; no-op in English
 *   ...
 *   <html lang="<?= ui_html_lang() ?>">
 *   ...
 *   <?php ui_lang_switch(); ?>            // the EN | TL control
 *
 * English is untouched: ui_i18n_start() returns without starting a buffer
 * unless the reader has actually chosen Tagalog, so the English page renders
 * byte-for-byte as it did before this file existed. That is deliberate — it is
 * what makes the switch safe to add to screens that 300+ checks assert on.
 *
 * The choice lives in the same `marle_lang` cookie the public site uses, so one
 * preference covers the whole system: switch on the dashboard and the public
 * pages come up Tagalog too. `?lang=tl` sets it, `?lang=en` clears it, and an
 * unknown value is ignored (no cookie is written).
 *
 * WHAT IT WILL NOT TRANSLATE
 * --------------------------
 *   - text inside <script> or <style> (a page's own JavaScript is skipped
 *     wholesale, so a translated word can never break code);
 *   - anything a database row supplied, unless the text is character-for-
 *     character one of the table's phrases (guest names, amounts, room
 *     numbers and dates pass through untouched);
 *   - attribute values other than placeholder / title / aria-label / alt /
 *     data-tooltip (plus value= on a submit or button input), so href, src,
 *     class, id and data-* payloads are never rewritten.
 */

require_once __DIR__ . '/site_i18n.php';

// ── The language itself ─────────────────────────────────────────────────────
// site_i18n.php owns the state (cookie + ?lang=), the labels and the html lang
// attribute; these are thin aliases so this file reads on its own.

if (!function_exists('ui_lang')) {
    /** The reader's language: 'en' (default) or 'tl'. Same state as the public site. */
    function ui_lang(): string
    {
        return site_lang();
    }
}

if (!function_exists('ui_is_tl')) {
    /** Is the page being read in Tagalog right now? */
    function ui_is_tl(): bool
    {
        return ui_lang() === 'tl';
    }
}

if (!function_exists('ui_html_lang')) {
    /** The value for `<html lang="…">`. */
    function ui_html_lang(): string
    {
        return site_html_lang();
    }
}

if (!function_exists('ui_t')) {
    /**
     * Inline pair, for copy written *after* this switch was added. The screens
     * themselves use the phrase table; this exists so new markup can follow the
     * public site's convention and keep its translation at the point of use.
     */
    function ui_t(string $en, string $tl): string
    {
        return ui_is_tl() ? $tl : $en;
    }
}

// ── The screens ─────────────────────────────────────────────────────────────

if (!function_exists('ui_screens')) {
    /**
     * Screen id => the files that render it. The tools use this to know what a
     * complete translation covers (i18n_scan.php checks every path still calls
     * ui_i18n_start() with the id it belongs to).
     *
     * The portal's five screens are bootstrapped once, in the shared
     * portal/includes/header.php, which is why its page list is the pages that
     * include it rather than the file that starts the buffer.
     *
     * @return array<string, array{label:string, files:array<int,string>, bootstrap:string}>
     */
    function ui_screens(): array
    {
        $staff = static function (string $file, string $label): array {
            return [
                'label'     => $label,
                'files'     => ["admin/$file", "superadmin/$file", "employee/$file"],
                'bootstrap' => 'self',
            ];
        };

        return [
            'staff.dashboard'    => $staff('adminDB.php', 'Dashboard'),
            'staff.requests'     => $staff('requests.php', 'Requests'),
            'staff.tokens'       => $staff('GuestTokens.php', 'Guest Tokens'),
            // The front desk's Transactions URL is a redirect stub with no page of
            // its own (access_control.php sends it to GuestTokens.php), so it is
            // listed as a redirect: it gets the bootstrap but no control to render.
            'staff.transactions' => [
                'label' => 'Transactions',
                'files' => ['admin/transaction_history.php', 'superadmin/transaction_history.php',
                            'employee/transaction_history.php'],
                'bootstrap' => 'self',
                'redirects' => ['employee/transaction_history.php'],
            ],
            'staff.inventory'    => $staff('inventory.php', 'Inventory'),
            'staff.guests'       => $staff('customerRC.php', 'Guest Record'),
            'staff.calendar'     => $staff('calendar.php', 'Calendar'),
            'staff.reviews'      => $staff('reviews.php', 'Reviews'),
            'staff.settings'     => $staff('settings.php', 'Settings'),
            // The System Log is the owner's page only — admin and the front desk
            // are redirected away from it (see access_control.php), so it has one file.
            'staff.systemlog'    => [
                'label' => 'System Log', 'files' => ['superadmin/activity_log.php'], 'bootstrap' => 'self',
            ],
            'portal.home'     => ['label' => 'Home',     'files' => ['portal/portal.php'],       'bootstrap' => 'header'],
            'portal.services' => ['label' => 'Services', 'files' => ['portal/services.php'],     'bootstrap' => 'header'],
            'portal.extend'   => ['label' => 'Extend',   'files' => ['portal/extend.php'],       'bootstrap' => 'header'],
            'portal.menu'     => ['label' => 'Menu',     'files' => ['portal/menu.php'],         'bootstrap' => 'header'],
            'portal.guide'    => ['label' => 'Guide',    'files' => ['portal/hotel_guide.php'],  'bootstrap' => 'header'],
        ];
    }
}

if (!function_exists('ui_screen_ids')) {
    /** @return array<int,string> */
    function ui_screen_ids(): array
    {
        return array_keys(ui_screens());
    }
}

// ── The phrase table ────────────────────────────────────────────────────────

if (!function_exists('ui_dictionary')) {
    /**
     * The whole table, loaded once per request.
     *
     * @return array{common:array<string,string>, fragments:array<string,string>, screens:array<string,array<string,string>>}
     */
    function ui_dictionary(): array
    {
        static $table = null;
        if ($table !== null) {
            return $table;
        }
        $file = __DIR__ . '/i18n/ui_strings.php';
        $loaded = is_file($file) ? require $file : [];
        if (!is_array($loaded)) {
            $loaded = [];
        }
        $table = [
            'common'    => is_array($loaded['common'] ?? null) ? $loaded['common'] : [],
            'fragments' => is_array($loaded['fragments'] ?? null) ? $loaded['fragments'] : [],
            'screens'   => is_array($loaded['screens'] ?? null) ? $loaded['screens'] : [],
        ];
        return $table;
    }
}

if (!defined('UI_I18N_SHARED')) {
    /**
     * The screen id for a piece of page that is not a screen: a fragment an
     * endpoint hands back for JavaScript to drop into an already-rendered page
     * (the notification dropdown, for one). It gets the shared vocabulary and
     * nothing screen-specific, because it does not know whose page it will land
     * on. Translate such a fragment with:
     *
     *     $html = ui_translate_html($html, UI_I18N_SHARED);
     */
    define('UI_I18N_SHARED', 'shared');
}

if (!function_exists('ui_screen_strings')) {
    /**
     * Exact-match table for one screen: the shared lines, then the screen's own
     * (its entries win, so a screen can overrule a shared wording). An id that
     * is not in ui_screens() — UI_I18N_SHARED, for instance — yields the shared
     * lines alone.
     *
     * @return array<string,string>
     */
    function ui_screen_strings(string $screen): array
    {
        static $cache = [];
        if (!isset($cache[$screen])) {
            $table = ui_dictionary();
            $cache[$screen] = ($table['screens'][$screen] ?? []) + $table['common'];
        }
        return $cache[$screen];
    }
}

if (!function_exists('ui_fragments')) {
    /**
     * Phrases translated *inside* a longer piece of text — for copy that wraps a
     * value the table can never know ("Hello <b>admin</b>, welcome back!").
     * Longest first, so a longer phrase always beats a shorter one that sits
     * inside it.
     *
     * @return array<string,string>
     */
    function ui_fragments(): array
    {
        static $fragments = null;
        if ($fragments === null) {
            $fragments = ui_dictionary()['fragments'];
            uksort($fragments, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        }
        return $fragments;
    }
}

// ── Text helpers ────────────────────────────────────────────────────────────

if (!function_exists('ui_flat')) {
    /**
     * Decode entities and collapse whitespace — how a phrase is compared.
     *
     * The non-breaking spaces are folded in on purpose: this markup separates
     * breadcrumbs with &nbsp; ("Dashboard&nbsp;/ Requests"), and a phrase table
     * should be able to say "/ Requests" without carrying an invisible character
     * nobody can see in the source.
     */
    function ui_flat(string $text): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = str_replace(["\u{00A0}", "\u{2007}", "\u{2009}", "\u{202F}", "\u{3000}"], ' ', $decoded);
        return trim((string)preg_replace('/\s+/u', ' ', $decoded));
    }
}

if (!function_exists('ui_esc')) {
    /** Escape a translated phrase for HTML output. */
    function ui_esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// ── The translator ──────────────────────────────────────────────────────────

if (!defined('UI_I18N_TOKEN_RE')) {
    /** Tag-or-comment token vs. everything else (text). One capture group, so
     *  preg_split(PREG_SPLIT_DELIM_CAPTURE) alternates text, tag, text, tag… */
    define('UI_I18N_TOKEN_RE', '/(<!--.*?-->|<[^>]*>)/s');
}

if (!defined('UI_I18N_ATTRS')) {
    /** The attributes safe to rewrite: they hold prose, never code or state. */
    define('UI_I18N_ATTRS', 'placeholder|title|aria-label|alt|data-tooltip');
}

if (!function_exists('ui_translate_text')) {
    /**
     * Translate one piece of rendered text.
     *
     * Exact phrase first, then the fragments. Surrounding whitespace is kept
     * exactly as it was, and text with no translation is returned untouched —
     * so nothing this table does not know about can be altered by accident.
     *
     * @param array<string,string> $map
     * @param array<string,string> $fragments
     */
    function ui_translate_text(string $text, array $map, array $fragments = []): string
    {
        if ($text === '' || !preg_match('/[A-Za-zÑñ]/u', $text)) {
            return $text;
        }
        if (!preg_match('/^(\s*)(.*?)(\s*)$/s', $text, $m)) {
            return $text;
        }
        $lead = $m[1];
        $core = $m[2];
        $trail = $m[3];
        if ($core === '') {
            return $text;
        }

        $flat = ui_flat($core);
        if ($flat !== '' && isset($map[$flat])) {
            return $lead . ui_esc($map[$flat]) . $trail;
        }

        if ($fragments && $flat !== '') {
            $translated = ui_translate_fragments($flat, $fragments);
            if ($translated !== $flat) {
                return $lead . ui_esc($translated) . $trail;
            }
        }

        return $text;
    }
}

if (!function_exists('ui_translate_fragments')) {
    /**
     * Replace whole phrases inside a longer line, word boundaries on both ends.
     * The replacement goes through a callback so a Tagalog line containing a $ or
     * a backslash can never be read as a backreference.
     */
    function ui_translate_fragments(string $text, array $fragments): string
    {
        foreach ($fragments as $en => $tl) {
            if ($en === '' || !str_contains($text, $en)) {
                continue;
            }
            $pattern = '/(?<![A-Za-z0-9])' . preg_quote($en, '/') . '(?![A-Za-z0-9])/u';
            $replaced = preg_replace_callback($pattern, static fn(): string => $tl, $text, 1);
            if (is_string($replaced)) {
                $text = $replaced;
            }
        }
        return $text;
    }
}

if (!function_exists('ui_translate_tag')) {
    /**
     * Translate the prose attributes of one tag token. Everything else in the
     * tag — href, src, class, id, data-* payload, inline JS — is left alone.
     *
     * @param array<string,string> $map
     */
    function ui_translate_tag(string $tag, array $map): string
    {
        if ($map === [] || $tag === '' || strlen($tag) < 3 || str_starts_with($tag, '<!--')) {
            return $tag;
        }
        // Closing tags carry nothing to translate.
        if (preg_match('#^</#', $tag)) {
            return $tag;
        }

        $attributes = UI_I18N_ATTRS;
        // value= only where it is a visible caption and *not* a submitted field:
        // a named submit input's value travels in the form body, so rewording it
        // would break the handler that is waiting for that exact string.
        $inputCaption = (bool)preg_match('/^<input\b/i', $tag)
            && (bool)preg_match('/\btype\s*=\s*["\']?(submit|button|reset)["\']?/i', $tag)
            && !preg_match('/\bname\s*=/i', $tag);
        if ($inputCaption) {
            $attributes .= '|value';
        }

        $pattern = '/\b(' . $attributes . ')\s*=\s*(["\'])(.*?)\2/is';
        $replaced = preg_replace_callback(
            $pattern,
            static function (array $m) use ($map): string {
                $name = $m[1];
                $value = $m[3];
                $flat = ui_flat($value);
                if ($flat === '' || !isset($map[$flat])) {
                    return $m[0];
                }
                return $name . '="' . ui_esc($map[$flat]) . '"';
            },
            $tag
        );

        return is_string($replaced) ? $replaced : $tag;
    }
}

if (!function_exists('ui_translate_html')) {
    /**
     * Translate a whole rendered page. Text outside <script>/<style> goes
     * through the phrase table; tags only through the safe attributes.
     *
     * @param array<string,string>|null $map  override the screen's table (tools)
     */
    function ui_translate_html(string $html, string $screen): string
    {
        if ($html === '') {
            return $html;
        }
        $map = ui_screen_strings($screen);
        $fragments = ui_fragments();
        if ($map === [] && $fragments === []) {
            return $html;
        }

        $parts = preg_split(UI_I18N_TOKEN_RE, $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $html;
        }

        $out = '';
        $inCode = 0;   // >0 while inside <script>/<style>
        foreach ($parts as $index => $part) {
            if ($part === '') {
                continue;
            }
            if ($index % 2 === 1) {                       // a tag or a comment
                $out .= $inCode > 0 ? $part : ui_translate_tag($part, $map);
                if (preg_match('/^<(script|style)\b/i', $part)) {
                    $inCode++;
                } elseif (preg_match('#^</(script|style)\s*>#i', $part)) {
                    $inCode = max(0, $inCode - 1);
                }
                continue;
            }
            $out .= $inCode > 0 ? $part : ui_translate_text($part, $map, $fragments);
        }

        return $out;
    }
}

if (!function_exists('ui_text_candidates')) {
    /**
     * Every phrase a screen's rendered HTML offers for translation, in document
     * order: ['kind' => 'text'|'attr', 'where' => tag context, 'value' => phrase].
     * Used by the test scripts to list what still needs a Tagalog line.
     *
     * @return array<int, array{kind:string, where:string, value:string}>
     */
    function ui_text_candidates(string $html): array
    {
        $parts = preg_split(UI_I18N_TOKEN_RE, $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return [];
        }
        $out = [];
        $inCode = 0;
        // The element a phrase sits in, for deciding whether it is chrome. It is
        // tracked on a stack and read past wrappers, because the label of a link
        // is almost always inside an icon ("<a><i class="bi"></i>Bookings</a>") —
        // reporting that as an <i> would hide a row of tab labels from the check.
        $stack = ['body'];
        $void = ['area' => 1, 'base' => 1, 'br' => 1, 'col' => 1, 'embed' => 1, 'hr' => 1,
            'img' => 1, 'input' => 1, 'link' => 1, 'meta' => 1, 'source' => 1, 'track' => 1, 'wbr' => 1];
        $wrapper = ['i' => 1, 'span' => 1, 'small' => 1, 'strong' => 1, 'b' => 1, 'em' => 1,
            'u' => 1, 's' => 1, 'sup' => 1, 'sub' => 1, 'svg' => 1, 'path' => 1, 'br' => 1];
        $context = static function () use (&$stack, $wrapper): string {
            for ($i = count($stack) - 1; $i >= 0; $i--) {
                if (!isset($wrapper[$stack[$i]])) {
                    return $stack[$i];
                }
            }
            return 'body';
        };

        foreach ($parts as $index => $part) {
            if ($part === '') {
                continue;
            }
            if ($index % 2 === 1) {
                if (preg_match('/^<(script|style)\b/i', $part)) {
                    $inCode++;
                    continue;
                }
                if (preg_match('#^</(script|style)\s*>#i', $part)) {
                    $inCode = max(0, $inCode - 1);
                    continue;
                }
                if ($inCode) {
                    continue;
                }
                if (preg_match('#^</#', $part)) {
                    if (count($stack) > 1) {
                        array_pop($stack);
                    }
                    continue;
                }
                if (preg_match('#^<([a-zA-Z][a-zA-Z0-9]*)#', $part, $m)) {
                    $name = strtolower($m[1]);
                    $selfClosing = str_ends_with(rtrim($part), '/>') || isset($void[$name]);
                    if (!$selfClosing) {
                        $stack[] = $name;
                    }
                }
                if (preg_match_all('/\b(' . UI_I18N_ATTRS . ')\s*=\s*(["\'])(.*?)\2/is', $part, $found, PREG_SET_ORDER)) {
                    foreach ($found as $one) {
                        $value = ui_flat($one[3]);
                        if ($value !== '') {
                            $out[] = ['kind' => 'attr', 'where' => $context() . '@' . strtolower($one[1]), 'value' => $value];
                        }
                    }
                }
                continue;
            }
            if ($inCode) {
                continue;
            }
            $value = ui_flat($part);
            if ($value !== '' && preg_match('/[A-Za-zÑñ]/u', $value)) {
                $out[] = ['kind' => 'text', 'where' => $context(), 'value' => $value];
            }
        }
        return $out;
    }
}

// ── The buffer ──────────────────────────────────────────────────────────────

if (!function_exists('ui_i18n_state')) {
    /**
     * Remembers the page being translated and the buffer level that carries it,
     * so a second ui_i18n_start() is a no-op and ui_i18n_end() can close our own
     * buffer and no one else's.
     *
     * @return array{screen:?string, level:int}
     */
    function &ui_i18n_state(): array
    {
        static $state = ['screen' => null, 'level' => 0];
        return $state;
    }
}

if (!function_exists('ui_i18n_start')) {
    /**
     * Start translating this page's output. Call before anything is echoed.
     *
     * In English this does nothing at all: no buffer, same bytes, no cost.
     */
    function ui_i18n_start(string $screen): bool
    {
        if (!ui_is_tl()) {
            return false;
        }
        $state = &ui_i18n_state();
        if ($state['screen'] !== null) {
            return false;   // one buffer per page; a second call is a no-op
        }
        $state['screen'] = $screen;
        ob_start(static fn(string $html): string => ui_translate_html($html, $screen));
        $state['level'] = ob_get_level();
        return true;
    }
}

if (!function_exists('ui_i18n_active')) {
    /** The screen currently being translated, or null. */
    function ui_i18n_active(): ?string
    {
        return ui_i18n_state()['screen'];
    }
}

if (!function_exists('ui_i18n_end')) {
    /**
     * Finish and send the translated page. Optional — PHP flushes any buffer the
     * page leaves open when the script ends — but a page that runs something else
     * after its markup can use it to send the page translated and first.
     */
    function ui_i18n_end(): void
    {
        $state = &ui_i18n_state();
        while ($state['level'] > 0 && ob_get_level() >= $state['level']) {
            ob_end_flush();
            $state['level']--;
        }
        $state['screen'] = null;
        $state['level'] = 0;
    }
}

// ── The control ─────────────────────────────────────────────────────────────

if (!function_exists('ui_lang_href')) {
    /**
     * A language link that keeps the current query string (so switching never
     * drops a filter, a page number or a search) and never repeats ?lang=.
     */
    function ui_lang_href(string $code): string
    {
        $query = $_GET;
        unset($query['lang']);
        $query['lang'] = $code;
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        return ui_esc($script . '?' . http_build_query($query));
    }
}

if (!function_exists('ui_lang_switch')) {
    /**
     * The EN | TL control. Rendered in the staff sidebar and the portal navbar by
     * the pages themselves; the styles ship with the markup so no page needs a
     * new stylesheet link.
     */
    function ui_lang_switch(string $variant = 'sidebar'): void
    {
        static $styled = false;
        $current = ui_lang();
        $variant = $variant === 'navbar' ? 'navbar' : 'sidebar';

        if (!$styled) {
            $styled = true;
            ?>
        <style>
            /* Default palette: the staff sidebar, which is dark.
               .marle-lang-switch--navbar is the same control on a light bar —
               the portal's navbar — so its two palettes are stated side by side. */
            .marle-lang-switch {
                display: inline-flex;
                align-items: center;
                gap: 2px;
                padding: 3px;
                border: 1px solid rgba(247, 233, 215, .38);
                border-radius: 999px;
                background: rgba(255, 255, 255, .06);
                font-family: 'DM Sans', system-ui, sans-serif;
                line-height: 1;
            }
            .marle-lang-switch .marle-lang-label {
                padding: 0 7px 0 9px;
                font-size: .68rem;
                letter-spacing: .06em;
                text-transform: uppercase;
                color: #f7e9d7;
                opacity: .72;
            }
            .marle-lang-switch .marle-lang-option {
                display: inline-block;
                min-width: 30px;
                padding: 5px 9px;
                border-radius: 999px;
                font-size: .74rem;
                font-weight: 600;
                text-decoration: none;
                color: #f7e9d7;
                background: transparent;
                transition: background .18s ease, color .18s ease;
            }
            .marle-lang-switch .marle-lang-option:hover,
            .marle-lang-switch .marle-lang-option:focus-visible {
                background: rgba(255, 255, 255, .16);
                color: #fff;
            }
            .marle-lang-switch .marle-lang-option.is-active {
                background: #f7e9d7;
                color: #4a1a08;
            }
            .marle-lang-switch--sidebar { width: 100%; justify-content: center; margin-bottom: 10px; }
            .marle-lang-switch--navbar { margin-left: 4px; border-color: rgba(147, 51, 16, .35); background: rgba(255, 182, 10, .12); }
            .marle-lang-switch--navbar .marle-lang-label { color: #933310; opacity: .8; padding: 0 5px 0 7px; font-size: .62rem; }
            .marle-lang-switch--navbar .marle-lang-option { color: #933310; }
            .marle-lang-switch--navbar .marle-lang-option:hover,
            .marle-lang-switch--navbar .marle-lang-option:focus-visible { background: rgba(147, 51, 16, .12); color: #933310; }
            .marle-lang-switch--navbar .marle-lang-option.is-active { background: #933310; color: #fff; }
        </style>
            <?php
        }
        ?>
        <div class="marle-lang-switch marle-lang-switch--<?= ui_esc($variant) ?>" data-marle-lang-switch
             role="group" aria-label="<?= site_t('Language', 'Wika') ?>">
            <span class="marle-lang-label"><?= site_t('Language', 'Wika') ?></span>
            <a href="<?= ui_lang_href('en') ?>" class="marle-lang-option<?= $current === 'en' ? ' is-active' : '' ?>"
               hreflang="en"<?= $current === 'en' ? ' aria-current="true"' : '' ?>>EN</a>
            <a href="<?= ui_lang_href('tl') ?>" class="marle-lang-option<?= $current === 'tl' ? ' is-active' : '' ?>"
               hreflang="tl"<?= $current === 'tl' ? ' aria-current="true"' : '' ?>>TL</a>
        </div>
        <?php
    }
}
