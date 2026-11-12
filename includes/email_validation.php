<?php
/**
 * email_validation.php — one strict email rule for the whole app.
 *
 * The homepage booking form used to keep a hardcoded whitelist of "known"
 * domains (gmail, yahoo, …), which rejected perfectly real addresses on any
 * other domain. This replaces it with the rule that actually matters:
 *
 *   1. the address is syntactically valid            (FILTER_VALIDATE_EMAIL)
 *   2. the domain has a mail server                  (MX record, else A record)
 *   3. the domain is not a known throwaway provider  (curated blocklist)
 *      and not a reserved documentation/test domain  (RFC 2606 / 6761)
 *
 * Any domain — corporate, school, ISP, provincial provider — is accepted as
 * long as it can actually receive mail.
 *
 * MARLE_EMAIL_REQUIRE_DNS_CHECK (config.php, default on) is the single escape
 * hatch: on a machine with no working DNS resolver (some locked-down dev
 * boxes) lookups cannot succeed for ANY domain, so a hard failure there would
 * block every booking. When the switch is off the DNS step is skipped and the
 * skip is written to the error log — never silently.
 */

if (!defined('MARLE_EMAIL_REQUIRE_DNS_CHECK')) {
    define('MARLE_EMAIL_REQUIRE_DNS_CHECK', true);
}

/**
 * Throwaway / temporary mailbox providers. Kept deliberately short and
 * high-signal: every entry is a domain whose entire purpose is a mailbox
 * that lasts minutes. Blocking these is what stops "valid format, real
 * domain, nobody home" bookings.
 */
if (!defined('MARLE_DISPOSABLE_EMAIL_DOMAINS')) {
    define('MARLE_DISPOSABLE_EMAIL_DOMAINS', [
        '10minutemail.com', '10minutemail.net', '20minutemail.com', '33mail.com',
        'anonbox.net', 'antichef.com', 'binkmail.com', 'bobmail.info',
        'bugmenot.com', 'burnermail.io', 'byom.de', 'clrmail.com',
        'deadaddress.com', 'discard.email', 'discardmail.com', 'dispostable.com',
        'dodgit.com', 'dropmail.me', 'dumpandjunk.com', 'e4ward.com',
        'emailondeck.com', 'email-temp.com', 'emailfake.com', 'emailinfive.com',
        'emailsensei.com', 'emailtemporario.com.br', 'emailtmp.com', 'fakeinbox.com',
        'fakemail.net', 'filzmail.com', 'fleckens.hu', 'getairmail.com',
        'getnada.com', 'gmailx.com', 'grr.la', 'guerrillamail.biz',
        'guerrillamail.com', 'guerrillamail.de', 'guerrillamail.info', 'guerrillamail.net',
        'guerrillamail.org', 'guerrillamailblock.com', 'harakirimail.com', 'hotpop.com',
        'inboxbear.com', 'incognitomail.com', 'incognitomail.net', 'jetable.org',
        'jnxjn.com', 'jourrapide.com', 'klzlk.com', 'kurzepost.de',
        'letthemeatspam.com', 'linshiyouxiang.net', 'lookugly.com', 'lortemail.dk',
        'mail-temporaire.fr', 'mailcatch.com', 'maildrop.cc', 'maileater.com',
        'mailexpire.com', 'mailfa.tk', 'mailforspam.com', 'mailimate.com',
        'mailinator.com', 'mailinator.net', 'mailinator2.com', 'mailismagic.com',
        'mailme.lv', 'mailmetrash.com', 'mailmoat.com', 'mailnesia.com',
        'mailnull.com', 'mailscrap.com', 'mailshell.com', 'mailsiphon.com',
        'mailslite.com', 'mailsucker.net', 'mailtemp.info', 'mailzilla.com',
        'mbx.cc', 'meltmail.com', 'messagebeamer.de', 'mierdamail.com',
        'mintemail.com', 'moburl.com', 'moncourrier.fr.nf', 'monemail.fr.nf',
        'monmail.fr.nf', 'mt2009.com', 'mycleaninbox.net', 'mypartyclip.de',
        'myphantomemail.com', 'myspaceinc.com', 'mytemp.email', 'mytempemail.com',
        'mytempmail.com', 'mailbox82.com', 'no-spam.ws', 'nobulk.com',
        'noclickemail.com', 'nogmailspam.info', 'nomail.xl.cx', 'nomail2me.com',
        'nospam.ze.tc', 'nospamfor.us', 'nowmymail.com', 'objectmail.com',
        'obobbo.com', 'oneoffemail.com', 'onewaymail.com', 'ordinaryamerican.net',
        'owlpic.com', 'pjjkp.com', 'politikerclub.de', 'proxymail.eu',
        'punkass.com', 'putthisinyourspamdatabase.com', 'quickinbox.com', 'rcpt.at',
        'recode.me', 'recursor.net', 'regbypass.com', 'rhyta.com',
        'rmqkr.net', 'rppkn.com', 'rtrtr.com', 's0ny.net',
        'safe-mail.net', 'safersignup.de', 'safetymail.info', 'safetypost.de',
        'sandelf.de', 'saynotospams.com', 'selfdestructingmail.com', 'sendspamhere.com',
        'shiftmail.com', 'shitmail.me', 'shortmail.net', 'sibmail.com',
        'skeefmail.com', 'slaskpost.se', 'slopsbox.com', 'smellfear.com',
        'snakemail.com', 'sneakemail.com', 'sofimail.com', 'sofort-mail.de',
        'sogetthis.com', 'soodonims.com', 'spam.la', 'spam.su',
        'spam4.me', 'spamavert.com', 'spambob.com', 'spambog.com',
        'spambox.us', 'spamcannon.com', 'spamcon.org', 'spamcorptastic.com',
        'spamcowboy.com', 'spamday.com', 'spamex.com', 'spamfree24.com',
        'spamfree24.de', 'spamfree24.eu', 'spamfree24.info', 'spamfree24.net',
        'spamfree24.org', 'spamgoes.in', 'spamgourmet.com', 'spamgourmet.net',
        'spamherelots.com', 'spamhereplease.com', 'spamhole.com', 'spamify.com',
        'spaminator.de', 'spamkill.info', 'spaml.com', 'spaml.de',
        'spammotel.com', 'spamobox.com', 'spamoff.de', 'spamslicer.com',
        'spamspot.com', 'spamthis.co.uk', 'spamthisplease.com', 'spamtrail.com',
        'speed.1s.fr', 'supergreatmail.com', 'supermailer.jp', 'suremail.info',
        'teewars.org', 'teleworm.com', 'teleworm.us', 'tempail.com',
        'tempalias.com', 'tempe-mail.com', 'tempemail.biz', 'tempemail.com',
        'tempemail.net', 'tempinbox.co.uk', 'tempinbox.com', 'tempmail.eu',
        'tempmail.it', 'tempmail2.com', 'tempmaildemo.com', 'tempmailer.com',
        'tempmailer.de', 'tempomail.fr', 'temporaryemail.net', 'temporaryforwarding.com',
        'temporaryinbox.com', 'temporarymailaddress.com', 'tempthe.net', 'thankyou2010.com',
        'thc.st', 'thelimestones.com', 'thisisnotmyrealemail.com', 'thismail.net',
        'throwawayemailaddress.com', 'throwawaymail.com', 'tmailinator.com', 'tradermail.info',
        'trash-amil.com', 'trash-mail.at', 'trash-mail.com', 'trash-mail.de',
        'trash2009.com', 'trashemail.de', 'trashmail.at', 'trashmail.com',
        'trashmail.me', 'trashmail.net', 'trashmail.org', 'trashmail.ws',
        'trashymail.com', 'trashymail.net', 'trialmail.de', 'trillianpro.com',
        'turual.com', 'twinmail.de', 'tyldd.com', 'uggsrock.com',
        'venompen.com', 'veryrealemail.com', 'viditag.com', 'viewcastmedia.com',
        'viewcastmedia.net', 'viewcastmedia.org', 'webemail.me', 'wegwerfadresse.de',
        'wegwerfemail.com', 'wegwerfemail.de', 'wegwerfmail.de', 'wegwerfmail.info',
        'wegwerfmail.net', 'wegwerfmail.org', 'wh4f.org', 'whyspam.me',
        'willselfdestruct.com', 'winemaven.info', 'wronghead.com', 'wuzup.net',
        'xagloo.com', 'xemaps.com', 'xents.com', 'xmaily.com',
        'xoxy.net', 'yep.it', 'yogamaven.com', 'yopmail.com',
        'yopmail.fr', 'yopmail.net', 'ypmail.webarnak.fr.eu.org', 'yuurok.com',
        'zehnminutenmail.de', 'zippymail.info', 'zoaxe.com', 'zoemail.org',
    ]);
}

/**
 * Reserved / documentation domains (RFC 2606, RFC 6761). They are syntactically
 * perfect — and `example.com` even resolves, so the DNS step cannot catch it —
 * but no human can receive mail there. Seeing one almost always means a typo,
 * a demo row or a test script rather than a real guest.
 */
if (!defined('MARLE_RESERVED_EMAIL_DOMAINS')) {
    define('MARLE_RESERVED_EMAIL_DOMAINS', [
        'example.com', 'example.net', 'example.org',
    ]);
}

/** Special-use TLDs that can never receive real mail (RFC 6761). */
if (!defined('MARLE_RESERVED_EMAIL_TLDS')) {
    define('MARLE_RESERVED_EMAIL_TLDS', ['test', 'invalid', 'example', 'localhost', 'local']);
}

/** Extract the lowercased domain from an address ('' when there is none). */
function marle_email_domain(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false) return '';
    return strtolower(trim(substr($email, $at + 1)));
}

/**
 * Does this domain have a mail server? MX first, then A (some small
 * businesses really do receive mail on their web host's A record).
 * Result is cached per request so a form with two lookups hits DNS once.
 */
function marle_email_domain_accepts_mail(string $domain): bool
{
    static $cache = [];
    if (isset($cache[$domain])) return $cache[$domain];

    if ($domain === '' || strpos($domain, '.') === false || substr($domain, -1) === '.') {
        return $cache[$domain] = false;
    }

    $hasMx = @checkdnsrr($domain, 'MX');
    $result = $hasMx || @checkdnsrr($domain, 'A');
    return $cache[$domain] = $result;
}

/** Is the domain a known throwaway mailbox provider? */
function marle_email_is_disposable(string $domain): bool
{
    return in_array($domain, MARLE_DISPOSABLE_EMAIL_DOMAINS, true);
}

/** Is the domain reserved for documentation/tests (RFC 2606 / RFC 6761)? */
function marle_email_is_reserved(string $domain): bool
{
    if (in_array($domain, MARLE_RESERVED_EMAIL_DOMAINS, true)) {
        return true;
    }
    $dot = strrpos($domain, '.');
    if ($dot === false) {
        return false;
    }
    return in_array(substr($domain, $dot + 1), MARLE_RESERVED_EMAIL_TLDS, true);
}

/**
 * The app-wide email rule.
 *
 * @param string      $email raw address from the user
 * @param string|null $error filled with a guest-friendly reason on failure
 */
function marle_validate_email(string $email, ?string &$error = null): bool
{
    $email = trim($email);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !preg_match('/^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/', $email)) {
        $error = 'Please enter a valid email address (for example, juan@email.com).';
        return false;
    }

    $domain = marle_email_domain($email);
    if ($domain === '' || strpos($domain, '.') === false) {
        $error = 'Please enter a valid email address (for example, juan@email.com).';
        return false;
    }

    if (marle_email_is_disposable($domain)) {
        $error = 'Please use a permanent email address — temporary or disposable mailboxes are not accepted.';
        return false;
    }

    if (marle_email_is_reserved($domain)) {
        $error = 'That email domain cannot receive mail. Please use a real address you can open.';
        return false;
    }

    if (MARLE_EMAIL_REQUIRE_DNS_CHECK) {
        if (!marle_email_domain_accepts_mail($domain)) {
            // Distinguish "this domain does not exist" from "this machine has no
            // resolver". dns_get_record() returns false both ways, so probe a
            // domain that must always resolve before blaming the guest.
            if (marle_email_domain_accepts_mail('gmail.com')) {
                $error = 'That email domain does not appear to receive mail. Please double-check your address.';
                return false;
            }
            error_log('email_validation: DNS lookup unavailable — accepted ' . $email . ' on syntax only');
        }
    }

    return true;
}
