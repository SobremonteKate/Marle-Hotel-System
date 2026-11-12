<?php
/**
 * send_email.php — portable email sender for InfinityFree + local XAMPP.
 *
 * InfinityFree shared hosting blocks PHP's mail() entirely, so booking
 * notifications are delivered through an HTTP email API instead. Any
 * provider with a simple JSON POST works; out of the box:
 *
 *   MARLE_EMAIL_PROVIDER = "resend"  → POST https://api.resend.com/emails
 *                          "brevo"   → POST https://api.brevo.com/v3/smtp/email
 *                          "mailgun" → POST https://api.mailgun.net/v3/{domain}/messages
 *
 * Required env vars (set in InfinityFree vPanel → PHP Configuration, or on
 * the ngrok/host box):
 *   MARLE_EMAIL_PROVIDER   one of: resend | brevo | mailgun
 *   MARLE_EMAIL_API_KEY    the provider API key
 *   MARLE_EMAIL_FROM       e.g. "Marle Hotel <noreply@yourdomain.com>"
 *   MARLE_EMAIL_DOMAIN     Mailgun only: your sending domain
 *
 * With no key configured the call is skipped silently and false is
 * returned, so flows never break while email is unset.
 */

if (!function_exists('marle_send_email')) {

    /**
     * @param string $toEmail      recipient address
     * @param string $toName       display name (may be empty)
     * @param string $subject      subject line
     * @param string $htmlBody     full HTML document as produced by the caller
     * @param string $replyTo      optional reply address; when left empty, mail
     *                             to anyone other than the hotel inbox replies
     *                             to the hotel inbox (see below)
     * @param array|null $attachment optional ['filename' => string, 'content' =>
     *                             raw bytes, 'mime' => string]. A malformed or
     *                             oversized attachment is logged and dropped —
     *                             the message is still sent, because a receipt is
     *                             worth less than the booking it describes.
     * @param array|null $result   optional, filled with what actually happened
     *                             (status, provider body, message id, timing) so
     *                             a caller can SHOW the reason a mail failed
     *                             instead of only writing it to the log. See
     *                             marle_email_result().
     * @return bool true when the provider accepted the message
     */
    function marle_send_email(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $replyTo = '',
        ?array $attachment = null,
        ?array &$result = null
    ): bool {
        $result = marle_email_result(false, '', 0, '', 'Not attempted yet.');
        $toEmail = trim($toEmail);
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $result = marle_email_result(false, '', 0, '', 'The recipient address is not valid: "' . $toEmail . '".');
            return false;
        }

        $attachment = marle_email_clean_attachment($attachment);

        // Env vars win; config.php constants are the hosting-friendly fallback
        // (shared hosting does not always expose user-settable environment
        // variables). Note: a STALE env var therefore beats a correct
        // config.php — marle_email_config() reports exactly what was used.
        $config   = marle_email_config();
        $provider = $config['provider'];
        $apiKey   = $config['api_key'];
        $fromRaw  = $config['from'];

        // Not configured: skip quietly (booking flows keep working) but say why
        // in the log, with the reason a human can act on.
        if (!$config['configured']) {
            error_log('send_email: skipped — ' . $config['problem']);
            $result = marle_email_result(false, $provider, 0, '', $config['problem']);
            return false;
        }

        // "Marle Hotel <noreply@marlehotel.com>" → name + address
        $fromName = '';
        $fromEmail = $fromRaw;
        if (preg_match('/^(.*?)<([^>]+)>\s*$/', $fromRaw, $m)) {
            $fromName = trim($m[1]);
            $fromEmail = trim($m[2]);
        }

        // Guest mail is sent from the hotel's system address, so a reply must
        // land in the hotel's inbox instead of bouncing off a dead noreply@.
        // Staff alerts already go to that inbox, so they get no reply-to.
        if ($replyTo === '' && function_exists('marle_email_notify_to')) {
            $inbox = marle_email_notify_to();
            if ($inbox !== '' && strcasecmp($inbox, $toEmail) !== 0) {
                $replyTo = $inbox;
            }
        }

        $request = marle_email_request($provider, $apiKey, $fromRaw, $fromName, $fromEmail, $toEmail, $toName, $subject, $htmlBody, $replyTo, $attachment);
        if ($request === null) {
            $result = marle_email_result(false, $provider, 0, '', 'The provider request could not be built — see the server log.');
            return false;
        }
        [$url, $headers, $body] = $request;

        $attempt = marle_email_post($url, $headers, $body);
        $result  = marle_email_result(
            $attempt['ok'],
            $provider,
            $attempt['status'],
            $attempt['response'],
            $attempt['error'],
            $attempt['ms']
        );

        if (!$attempt['ok']) {
            error_log('send_email: provider ' . $provider . ' failed (HTTP ' . $attempt['status'] . '): '
                . substr((string)$attempt['response'], 0, 300) . ($attempt['error'] !== '' ? ' curl: ' . $attempt['error'] : ''));
            return false;
        }

        return true;
    }
}

if (!function_exists('marle_email_result')) {
    /**
     * The shape every caller gets back when it asks what happened to a message.
     *
     * Kept separate so the transport, the test screen and any future caller read
     * the same fields — and so a provider's own words ("message", "error") reach
     * a human instead of being flattened to a bare false.
     *
     * @return array{ok:bool, provider:string, status:int, message_id:string, detail:string, response:string, error:string, ms:int}
     */
    function marle_email_result(
        bool $ok,
        string $provider,
        int $status,
        string $response = '',
        string $error = '',
        int $ms = 0
    ): array {
        $decoded   = json_decode((string)$response, true);
        $messageId = '';
        $detail    = '';
        if (is_array($decoded)) {
            $messageId = (string)($decoded['messageId'] ?? ($decoded['id'] ?? ''));
            if (!$ok) {
                $detail = (string)($decoded['message'] ?? ($decoded['error'] ?? ''));
            }
        }
        if (!$ok && $detail === '' && $error !== '') {
            $detail = $error;
        }

        return [
            'ok'         => $ok,
            'provider'   => $provider,
            'status'     => $status,
            'message_id' => $messageId,
            'detail'     => $detail,
            'response'   => substr((string)$response, 0, 600),
            'error'      => $error,
            'ms'         => $ms,
        ];
    }
}

if (!function_exists('marle_email_post')) {
    /**
     * One POST to the provider, reporting exactly what came back and how long it
     * took. The timeout is short on purpose: a booking must never hang on a mail
     * provider, so a slow provider is a failure, not a wait.
     *
     * @return array{ok:bool, status:int, response:string, error:string, ms:int}
     */
    function marle_email_post(string $url, array $headers, string $body): array
    {
        // Some shared hosts disable one cURL function rather than the whole
        // extension (curl_exec is a common entry in disable_functions). Calling a
        // disabled function is a fatal Error, and on this project that fatal
        // landed *after* a cancellation had been committed — the booking was
        // cancelled and the desk was told the server was unreachable. A mail
        // failure must never be able to do that, so both halves are checked.
        if (!function_exists('curl_init') || !function_exists('curl_exec')) {
            return [
                'ok' => false, 'status' => 0, 'response' => '', 'ms' => 0,
                'error' => 'PHP cURL is not available on this server (curl_init/curl_exec missing or disabled), so no provider can be reached.',
            ];
        }

        $started = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err      = (string)curl_error($ch);
        curl_close($ch);

        return [
            'ok'       => ($response !== false && $status >= 200 && $status < 300),
            'status'   => $status,
            'response' => (string)$response,
            'error'    => $err,
            'ms'       => (int)round((microtime(true) - $started) * 1000),
        ];
    }
}

if (!function_exists('marle_email_get')) {
    /** One authenticated GET against the provider's own API (no message sent).
     *  @return array{ok:bool, status:int, response:string, error:string, ms:int} */
    function marle_email_get(string $url, array $headers): array
    {
        if (!function_exists('curl_init') || !function_exists('curl_exec')) {
            return ['ok' => false, 'status' => 0, 'response' => '', 'ms' => 0,
                    'error' => 'PHP cURL is not available on this server (curl_init/curl_exec missing or disabled).'];
        }

        $started = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err      = (string)curl_error($ch);
        curl_close($ch);

        return [
            'ok'       => ($response !== false && $status >= 200 && $status < 300),
            'status'   => $status,
            'response' => (string)$response,
            'error'    => $err,
            'ms'       => (int)round((microtime(true) - $started) * 1000),
        ];
    }
}

if (!function_exists('marle_email_handshake')) {
    /**
     * Ask the provider whether this API key works — WITHOUT sending anything.
     *
     * A wrong key and a blocked network look identical from the outside ("no
     * email arrived"), so this separates them: 401 means the key, a curl error
     * means the network, 200 means the transport is fine and the fault is
     * somewhere else (sender, quota, recipient).
     *
     * Brevo also reports the plan's remaining daily credits and whether the
     * configured From address is a verified, active sender — the two reasons a
     * key that works still refuses every message.
     *
     * @return array{ok:bool, configured:bool, problem:string, provider:string,
     *               checks:array<int,array{name:string,ok:bool,detail:string}>}
     */
    function marle_email_handshake(): array
    {
        $config = marle_email_config();
        $out = [
            'ok'         => false,
            'configured' => $config['configured'],
            'problem'    => $config['problem'],
            'provider'   => $config['provider'],
            'checks'     => [],
        ];

        if (!$config['configured']) {
            return $out;
        }

        $key      = $config['api_key'];
        $fromRaw  = $config['from'];
        $fromEmail = $fromRaw;
        if (preg_match('/<([^>]+)>\s*$/', $fromRaw, $m)) {
            $fromEmail = trim($m[1]);
        }

        $add = static function (string $name, bool $ok, string $detail) use (&$out): void {
            $out['checks'][] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        };

        switch ($config['provider']) {
            case 'brevo':
                $account = marle_email_get('https://api.brevo.com/v3/account', [
                    'accept: application/json', 'api-key: ' . $key,
                ]);
                $accountOk = $account['ok'];
                if ($account['status'] === 0) {
                    $add('Provider reachable', false, 'Could not reach api.brevo.com: ' . ($account['error'] ?: 'no response'));
                    return $out;
                }
                $acct = json_decode($account['response'], true) ?: [];
                $add(
                    'API key accepted',
                    $accountOk,
                    $accountOk
                        ? 'Signed in as ' . ($acct['email'] ?? 'unknown') . ' (' . ($acct['companyName'] ?? 'no company') . ').'
                        : 'HTTP ' . $account['status'] . ' — ' . ((string)($acct['message'] ?? '') ?: 'the key was refused.')
                );
                if (!$accountOk) {
                    return $out;
                }

                $credits = $acct['plan'][0]['credits'] ?? null;
                if ($credits !== null) {
                    $add('Daily sending credits', ((int)$credits) > 0, (string)$credits . ' left on the ' . ($acct['plan'][0]['type'] ?? 'free') . ' plan today.');
                }

                $senders = marle_email_get('https://api.brevo.com/v3/senders', [
                    'accept: application/json', 'api-key: ' . $key,
                ]);
                $list = json_decode($senders['response'], true)['senders'] ?? [];
                $matched = null;
                foreach ((array)$list as $sender) {
                    if (strcasecmp((string)($sender['email'] ?? ''), $fromEmail) === 0) {
                        $matched = $sender;
                        break;
                    }
                }
                if ($matched === null) {
                    $add('From address is a verified sender', false, '"' . $fromEmail . '" is not in the sender list on this account.');
                } else {
                    $active = !empty($matched['active']);
                    $add('From address is a verified sender', $active, '"' . $fromEmail . '" is ' . ($active ? 'active' : 'NOT active — Brevo will refuse every message') . '.');
                }

                $domains = marle_email_get('https://api.brevo.com/v3/senders/domains', [
                    'accept: application/json', 'api-key: ' . $key,
                ]);
                $domainList = json_decode($domains['response'], true)['domains'] ?? [];
                $fromDomain = strtolower((string)substr((string)strrchr('@' . $fromEmail, '@'), 1));
                $domainOk = false;
                $domainName = '';
                foreach ((array)$domainList as $domain) {
                    if (strcasecmp((string)($domain['domain_name'] ?? ''), $fromDomain) === 0) {
                        $domainName = (string)$domain['domain_name'];
                        $domainOk = !empty($domain['authenticated']);
                        break;
                    }
                }
                $add(
                    'Sending domain authenticated',
                    $domainOk,
                    $domainOk
                        ? $domainName . ' is authenticated in Brevo (DKIM/SPF present).'
                        : $fromDomain . ' is not authenticated on this account — mail sent "from" it will be filtered or refused.'
                );

                $out['ok'] = $accountOk;
                return $out;

            case 'resend':
                $r = marle_email_get('https://api.resend.com/domains', [
                    'accept: application/json', 'Authorization: Bearer ' . $key,
                ]);
                $add('API key accepted', $r['ok'], $r['ok'] ? 'The key is valid.' : 'HTTP ' . $r['status'] . ($r['status'] === 0 ? ' — ' . $r['error'] : ' — ' . substr($r['response'], 0, 200)));
                $out['ok'] = $r['ok'];
                return $out;

            case 'mailgun':
                $domain = trim((string)(getenv('MARLE_EMAIL_DOMAIN') ?: (defined('MARLE_EMAIL_DOMAIN') ? MARLE_EMAIL_DOMAIN : '')));
                if ($domain === '') {
                    $add('Sending domain set', false, 'MARLE_EMAIL_DOMAIN is empty, which Mailgun requires.');
                    return $out;
                }
                $r = marle_email_get('https://api.mailgun.net/v3/domains', [
                    'Authorization: Basic ' . base64_encode('api:' . $key),
                ]);
                $add('API key accepted', $r['ok'], $r['ok'] ? 'The key is valid.' : 'HTTP ' . $r['status'] . ($r['status'] === 0 ? ' — ' . $r['error'] : ' — ' . substr($r['response'], 0, 200)));
                $out['ok'] = $r['ok'];
                return $out;

            default:
                $add('Known provider', false, 'Unknown provider "' . $config['provider'] . '" — expected resend, brevo or mailgun.');
                return $out;
        }
    }
}

if (!function_exists('marle_email_clean_attachment')) {
    /**
     * Validate and normalise an attachment, or return null.
     *
     * The name reaches a header and a JSON body, so it is reduced to the
     * characters every provider accepts and every filesystem can hold; the bytes
     * are capped because base64 in a JSON body is 33% larger again and providers
     * reject oversized messages with an error that reads like a server fault.
     *
     * @return array{filename:string, content:string, mime:string}|null
     */
    function marle_email_clean_attachment(?array $attachment): ?array
    {
        if ($attachment === null) {
            return null;
        }

        $name    = preg_replace('/[^A-Za-z0-9._-]/', '-', (string)($attachment['filename'] ?? '')) ?? '';
        $content = (string)($attachment['content'] ?? '');
        $mime    = trim((string)($attachment['mime'] ?? 'application/pdf')) ?: 'application/pdf';

        if ($name === '' || $content === '') {
            error_log('send_email: attachment dropped — ' . ($name === '' ? 'no filename' : 'no content'));
            return null;
        }
        if (strlen($content) > 4194304) {   // 4 MB: far above any receipt we generate
            error_log('send_email: attachment ' . $name . ' dropped — ' . strlen($content) . ' bytes is too large');
            return null;
        }

        return ['filename' => $name, 'content' => $content, 'mime' => $mime];
    }
}

if (!function_exists('marle_email_request')) {
    /**
     * Build the provider-specific HTTP request for one message.
     *
     * Separated from the send so the exact bytes that leave the server can be
     * asserted in isolation (see the transport probe) without touching the
     * network, and so a new provider is added in exactly one place.
     *
     * @param array|null $attachment ['filename' => …, 'content' => …, 'mime' => …]
     * @return array{0:string,1:string[],2:string}|null [url, headers, body]
     */
    function marle_email_request(
        string $provider,
        string $apiKey,
        string $fromRaw,
        string $fromName,
        string $fromEmail,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $replyTo = '',
        ?array $attachment = null
    ): ?array {
        $attachment = marle_email_clean_attachment($attachment);
        $headers = ['Content-Type: application/json'];

        switch ($provider) {
            case 'resend':
                $payload = [
                    'from'    => $fromRaw,
                    'to'      => [$toEmail],
                    'subject' => $subject,
                    'html'    => $htmlBody,
                ];
                if ($replyTo !== '') $payload['reply_to'] = $replyTo;
                if ($attachment !== null) {
                    $payload['attachments'] = [[
                        'filename' => $attachment['filename'],
                        'content'  => base64_encode($attachment['content']),
                    ]];
                }
                return [
                    'https://api.resend.com/emails',
                    array_merge($headers, ['Authorization: Bearer ' . $apiKey]),
                    (string)json_encode($payload),
                ];

            case 'brevo':
                // Brevo rejects an empty recipient name with a 400
                // ("name is missing in to"), which turns a cosmetic detail into
                // a lost confirmation. The address is a valid name when none was
                // supplied.
                $payload = [
                    'sender'      => ['name' => $fromName, 'email' => $fromEmail],
                    'to'          => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
                    'subject'     => $subject,
                    'htmlContent' => $htmlBody,
                ];
                if ($replyTo !== '') $payload['replyTo'] = ['email' => $replyTo];
                if ($attachment !== null) {
                    $payload['attachment'] = [[
                        'name'    => $attachment['filename'],
                        'content' => base64_encode($attachment['content']),
                    ]];
                }
                return [
                    'https://api.brevo.com/v3/smtp/email',
                    array_merge($headers, ['api-key: ' . $apiKey]),
                    (string)json_encode($payload),
                ];

            case 'mailgun':
                $domain = trim((string)(getenv('MARLE_EMAIL_DOMAIN') ?: (defined('MARLE_EMAIL_DOMAIN') ? MARLE_EMAIL_DOMAIN : '')));
                if ($domain === '') {
                    error_log('send_email: MARLE_EMAIL_DOMAIN required for mailgun');
                    return null;
                }
                $fields = array_filter([
                    'from'       => $fromRaw,
                    'to'         => $toName !== '' ? $toName . ' <' . $toEmail . '>' : $toEmail,
                    'subject'    => $subject,
                    'html'       => $htmlBody,
                    'h:Reply-To' => $replyTo,
                ], static function ($v) { return $v !== ''; });

                // Mailgun takes attachments only as multipart/form-data, so a
                // message with one is built as multipart and a plain one stays
                // on the smaller urlencoded body.
                if ($attachment !== null) {
                    $boundary = 'marle-' . bin2hex(random_bytes(16));
                    $body = '';
                    foreach ($fields as $field => $value) {
                        $body .= '--' . $boundary . "\r\n"
                            . 'Content-Disposition: form-data; name="' . $field . '"' . "\r\n\r\n"
                            . $value . "\r\n";
                    }
                    $body .= '--' . $boundary . "\r\n"
                        . 'Content-Disposition: form-data; name="attachment"; filename="'
                        . str_replace('"', '', $attachment['filename']) . '"' . "\r\n"
                        . 'Content-Type: ' . $attachment['mime'] . "\r\n"
                        . 'Content-Transfer-Encoding: binary' . "\r\n\r\n"
                        . $attachment['content'] . "\r\n"
                        . '--' . $boundary . '--' . "\r\n";

                    return [
                        'https://api.mailgun.net/v3/' . rawurlencode($domain) . '/messages',
                        [
                            'Content-Type: multipart/form-data; boundary=' . $boundary,
                            'Authorization: Basic ' . base64_encode('api:' . $apiKey),
                        ],
                        $body,
                    ];
                }

                // Mailgun uses basic auth with the literal user "api".
                return [
                    'https://api.mailgun.net/v3/' . rawurlencode($domain) . '/messages',
                    [
                        'Content-Type: application/x-www-form-urlencoded',
                        'Authorization: Basic ' . base64_encode('api:' . $apiKey),
                    ],
                    (string)http_build_query($fields),
                ];

            default:
                error_log('send_email: unknown provider "' . $provider . '"');
                return null;
        }
    }
}

if (!function_exists('marle_email_config')) {
    /**
     * Resolve the effective email configuration (env wins over config.php) and
     * say whether it is usable. Placeholders like "noreply@yourdomain.com" are
     * treated as NOT configured — they would otherwise fail at the provider and
     * look like a mystery outage.
     *
     * @return array{provider:string, api_key:string, from:string, configured:bool, problem:string, sources:array<string,string>}
     */
    function marle_email_config(): array
    {
        if (!defined('MARLE_EMAIL_PROVIDER')) {
            $provider = strtolower(trim(getenv('MARLE_EMAIL_PROVIDER') ?: ''));
            $apiKey   = trim(getenv('MARLE_EMAIL_API_KEY') ?: '');
            $fromRaw  = trim(getenv('MARLE_EMAIL_FROM') ?: '');
        } else {
            $provider = strtolower(trim((string)(getenv('MARLE_EMAIL_PROVIDER') ?: MARLE_EMAIL_PROVIDER)));
            $apiKey   = trim((string)(getenv('MARLE_EMAIL_API_KEY') ?: MARLE_EMAIL_API_KEY));
            $fromRaw  = trim((string)(getenv('MARLE_EMAIL_FROM') ?: MARLE_EMAIL_FROM));
        }

        // Where each value actually came from, so "I edited config.php" can be
        // answered instead of argued about. A host whose config.php is an older
        // copy (an extract that kept the existing file, so the database password
        // survived) has no email block at all, and the only symptom is an empty
        // provider — which looks exactly like a typo.
        $sourceOf = static function (string $envName, string $constName): string {
            $env = getenv($envName);
            if (is_string($env) && trim($env) !== '') {
                return 'environment variable';
            }
            if (!defined($constName)) {
                return 'not set';
            }
            $value = (string)constant($constName);
            return trim($value) === '' ? 'config.php (empty)' : 'config.php';
        };

        $sources = [
            'provider' => $sourceOf('MARLE_EMAIL_PROVIDER', 'MARLE_EMAIL_PROVIDER'),
            'api_key'  => $sourceOf('MARLE_EMAIL_API_KEY', 'MARLE_EMAIL_API_KEY'),
            'from'     => $sourceOf('MARLE_EMAIL_FROM', 'MARLE_EMAIL_FROM'),
        ];

        $problem = '';
        if ($provider === '')      $problem = 'No email provider is selected.';
        elseif ($apiKey === '')    $problem = 'The ' . $provider . ' API key is empty.';
        elseif ($fromRaw === '')   $problem = 'No sender (MARLE_EMAIL_FROM) is set.';
        elseif (stripos($fromRaw, 'yourdomain.com') !== false) {
            $problem = 'The sender still uses the placeholder "yourdomain.com" — set a real, verified sender.';
        }

        return [
            'provider'   => $provider,
            'api_key'    => $apiKey,
            'from'       => $fromRaw,
            'configured' => ($problem === ''),
            'problem'    => $problem,
            'sources'    => $sources,
        ];
    }
}

if (!function_exists('marle_base_url')) {
    /**
     * Absolute root URL of this app.
     *
     * Derived from the filesystem so it survives being deployed to a subfolder
     * (Hostinger: public_html/marlehotel) as well as the local dev server, with
     * MARLE_BASE_URL available in config.php as an explicit override.
     */
    function marle_base_url(): string
    {
        static $base = null;
        if ($base !== null) return $base;

        if (defined('MARLE_BASE_URL') && MARLE_BASE_URL !== '') {
            return $base = rtrim(MARLE_BASE_URL, '/');
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $appRoot = str_replace('\\', '/', dirname(__DIR__));
        $docRoot = str_replace('\\', '/', (string)(realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ''));
        $subPath = '';
        if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
            $subPath = substr($appRoot, strlen($docRoot));
        }

        return $base = ($isHttps ? 'https://' : 'http://') . $host . rtrim($subPath, '/');
    }
}

if (!function_exists('marle_absolute_url')) {
    /** Turn 'homepage/booking_status.php' into a clickable absolute link. */
    function marle_absolute_url(string $relative): string
    {
        return marle_base_url() . '/' . ltrim($relative, '/');
    }
}

if (!function_exists('marle_email_notify_to')) {
    /**
     * The hotel's own inbox for new-booking / cancellation alerts.
     * MARLE_EMAIL_NOTIFY_TO (config.php or env) wins; the client's published
     * address is the fallback so alerts are never simply dropped.
     */
    function marle_email_notify_to(): string
    {
        $configured = trim((string)(getenv('MARLE_EMAIL_NOTIFY_TO')
            ?: (defined('MARLE_EMAIL_NOTIFY_TO') ? MARLE_EMAIL_NOTIFY_TO : '')));
        if ($configured !== '') {
            return $configured;
        }

        // No invented fallback. This used to fall back to a fixed Gmail address
        // which, measured on 2026-10-18, does not exist: Gmail answered
        // "550-5.1.1 The email account that you tried to reach does not exist"
        // for every alert sent to it. A hard bounce is worse than a refusal — it
        // damages the sending domain and Brevo then blocks the address, so the
        // hotel ends up with neither the alert nor the reputation. Saying
        // nothing, and recording why, is the honest failure.
        static $logged = false;
        if (!$logged) {
            $logged = true;
            error_log('send_email: MARLE_EMAIL_NOTIFY_TO is empty — no hotel inbox is configured, so staff alerts are not being sent.');
        }
        return '';
    }
}

if (!function_exists('marle_email_delivery_events')) {
    /**
     * What the provider did with a message AFTER accepting it.
     *
     * This is the half of "did it send?" that the HTTP status cannot answer.
     * Brevo answers 201 when it takes the message, then reports the real outcome
     * seconds later — `delivered`, or `hardBounces` / `blocked` / `error` with the
     * receiving server's own words (e.g. Gmail's 550 5.1.1 for an address that
     * does not exist). Without this, an accepted-but-refused message looks like a
     * success forever.
     *
     * @return array{ok:bool, supported:bool, status:int, events:array<int,array{event:string,date:string,reason:string,email:string}>, error:string}
     */
    function marle_email_delivery_events(string $messageId, int $days = 2): array
    {
        $out = ['ok' => false, 'supported' => false, 'status' => 0, 'events' => [], 'error' => ''];
        $messageId = trim($messageId);
        if ($messageId === '') {
            $out['error'] = 'No provider message id was supplied.';
            return $out;
        }

        $config = marle_email_config();
        if ($config['provider'] !== 'brevo') {
            $out['error'] = 'Delivery lookups are implemented for Brevo only.';
            return $out;
        }

        $out['supported'] = true;
        $url = 'https://api.brevo.com/v3/smtp/statistics/events?' . http_build_query([
            'days'      => max(1, min(30, $days)),
            'limit'     => 20,
            'sort'      => 'desc',
            'messageId' => $messageId,
        ]);
        $response = marle_email_get($url, ['accept: application/json', 'api-key: ' . $config['api_key']]);
        $out['status'] = $response['status'];

        if (!$response['ok']) {
            $decoded = json_decode($response['response'], true);
            $out['error'] = is_array($decoded) && isset($decoded['message'])
                ? (string)$decoded['message']
                : ($response['error'] !== '' ? $response['error'] : 'The provider did not answer.');
            return $out;
        }

        $decoded = json_decode($response['response'], true) ?: [];
        foreach ((array)($decoded['events'] ?? []) as $event) {
            $out['events'][] = [
                'event'  => (string)($event['event'] ?? ''),
                'date'   => (string)($event['date'] ?? ''),
                'reason' => trim((string)($event['reason'] ?? '')),
                'email'  => (string)($event['email'] ?? ''),
            ];
        }
        $out['ok'] = true;
        return $out;
    }
}

if (!function_exists('marle_email_template')) {
    /**
     * One branded HTML shell for every guest/staff notification.
     *
     * @param string $heading  main headline
     * @param string $accent   hex colour for the heading (green = good news, red = bad)
     * @param array  $paragraphs plain-text lines
     * @param array  $rows     label => value pairs rendered as a detail table
     * @param array|null $cta  ['label' => ..., 'url' => ...] button
     * @param string $note     small print under the button
     */
    function marle_email_template(
        string $heading,
        string $accent,
        array $paragraphs = [],
        array $rows = [],
        ?array $cta = null,
        string $note = ''
    ): string {
        $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        $html = '<html><body style="font-family:Arial,sans-serif; background:#f7f3ef; padding:18px;">'
              . '<div style="max-width:600px; margin:0 auto;">'
              . '<div style="background:#ffb60a; padding:20px; text-align:center; border-radius:10px 10px 0 0;">'
              . '<h1 style="color:#fff; margin:0; font-size:22px; letter-spacing:.5px;">Marle Hotel &amp; Restaurant</h1>'
              . '</div>'
              . '<div style="background:#fff; padding:26px; border:1px solid #eee; border-radius:0 0 10px 10px;">'
              . '<h2 style="color:' . $h($accent) . '; margin-top:0;">' . $h($heading) . '</h2>';

        foreach ($paragraphs as $paragraph) {
            $html .= '<p style="color:#333; line-height:1.5;">' . $paragraph . '</p>';
        }

        if (!empty($rows)) {
            $html .= '<table style="width:100%; border-collapse:collapse; margin:16px 0;">';
            foreach ($rows as $label => $value) {
                $html .= '<tr><td style="padding:8px; border-bottom:1px solid #eee; color:#888; width:42%;">'
                       . $h($label) . '</td>'
                       . '<td style="padding:8px; border-bottom:1px solid #eee; font-weight:bold; color:#222;">'
                       . $h($value) . '</td></tr>';
            }
            $html .= '</table>';
        }

        if ($cta !== null && !empty($cta['url'])) {
            $html .= '<p style="text-align:center; margin:24px 0;">'
                   . '<a href="' . $h($cta['url']) . '" style="background:#933310; color:#fff; padding:12px 22px; '
                   . 'border-radius:8px; text-decoration:none; font-weight:bold;">' . $h($cta['label'] ?? 'Open') . '</a></p>';
        }

        if ($note !== '') {
            $html .= '<p style="color:#8a8a8a; font-size:12px; line-height:1.5; margin-bottom:0;">' . $note . '</p>';
        }

        $html .= '</div>'
               . '<p style="text-align:center; color:#9a9a9a; font-size:11px; margin-top:12px;">'
               . 'Marle Hotel &amp; Restaurant &middot; Sabutan Ibayo, Silang, Cavite &middot; 0917 000 0000</p>'
               . '</div></body></html>';

        return $html;
    }
}
