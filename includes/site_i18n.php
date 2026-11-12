<?php
/**
 * site_i18n.php — the public website's English / Tagalog switch.
 *
 * Scope: the guest-facing pages (home, about, contact). The staff and portal
 * screens are a different audience with their own (English) vocabulary, so this
 * is deliberately not wired into them.
 *
 * How a page uses it:
 *
 *   require_once __DIR__ . '/../includes/site_i18n.php';
 *   site_lang();                     // resolve + persist BEFORE any output,
 *                                    // so the preference cookie can be sent
 *   ...
 *   <html lang="<?= site_html_lang() ?>">
 *   ...
 *   <?= site_t('Home', 'Tahanan') ?>          // inline pair: English, Tagalog
 *   ...
 *   <?php site_lang_switch(); ?>               // the EN | TL toggle
 *
 * The English and Tagalog strings sit side by side at the point of use (rather
 * than behind a shared key table). There is exactly one place to look for a
 * string's translation, and a missing translation is impossible to hit at
 * runtime — the worst case is a string that was never wrapped at all.
 *
 * The choice is carried by a `marle_lang` cookie: `?lang=tl` sets it, and every
 * later page keeps Tagalog without needing the query string on each link.
 */

if (!function_exists('site_langs')) {
    /** @return array<string,string> code => human label */
    function site_langs(): array
    {
        return ['en' => 'English', 'tl' => 'Tagalog'];
    }
}

if (!function_exists('site_lang')) {
    /**
     * The visitor's language: `?lang=` (which also persists it) → cookie → 'en'.
     * Call this before any output so the cookie header can still be sent.
     */
    function site_lang(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $supported = site_langs();
        $resolved  = 'en';

        if (isset($_GET['lang']) && array_key_exists((string)$_GET['lang'], $supported)) {
            $resolved = (string)$_GET['lang'];
            // A preference, not a credential: readable by the page is fine, and
            // SameSite=Lax keeps it off cross-site requests.
            if (!headers_sent()) {
                setcookie('marle_lang', $resolved, [
                    'expires'  => time() + 31536000,
                    'path'     => '/',
                    'samesite' => 'Lax',
                ]);
            }
            $_COOKIE['marle_lang'] = $resolved;
        } elseif (isset($_COOKIE['marle_lang']) && array_key_exists((string)$_COOKIE['marle_lang'], $supported)) {
            $resolved = (string)$_COOKIE['marle_lang'];
        }

        return $resolved;
    }
}

if (!function_exists('site_t')) {
    /** English when reading in English, Tagalog when reading in Tagalog. */
    function site_t(string $en, string $tl): string
    {
        return site_lang() === 'tl' ? $tl : $en;
    }
}

if (!function_exists('site_html_lang')) {
    /** The value for `<html lang="…">`. */
    function site_html_lang(): string
    {
        return site_lang() === 'tl' ? 'tl' : 'en';
    }
}

if (!function_exists('site_lang_switch')) {
    /**
     * The EN | TL toggle. Links keep the current query string (minus `lang`) so
     * switching language never drops a search or filter, and carry `hreflang`
     * so assistive tech announces the target language.
     */
    function site_lang_switch(): void
    {
        $current = site_lang();
        $query   = $_GET;
        unset($query['lang']);
        $script  = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'home.php'));

        $href = static function (string $code) use ($query, $script): string {
            $params         = $query;
            $params['lang'] = $code;
            return htmlspecialchars($script . '?' . http_build_query($params), ENT_QUOTES, 'UTF-8');
        };
        ?>
        <div class="lang-switch" role="group" aria-label="<?= site_t('Language', 'Wika') ?>">
            <a href="<?= $href('en') ?>"
               class="lang-option<?= $current === 'en' ? ' active' : '' ?>"
               hreflang="en"<?= $current === 'en' ? ' aria-current="true"' : '' ?>>EN</a>
            <a href="<?= $href('tl') ?>"
               class="lang-option<?= $current === 'tl' ? ' active' : '' ?>"
               hreflang="tl"<?= $current === 'tl' ? ' aria-current="true"' : '' ?>>TL</a>
        </div>
        <?php
    }
}
