# Marle Hotel — Deployment Runbook

Two supported targets:

| Target | Use case | URL | Notes |
|---|---|---|---|
| **ngrok** (this XAMPP machine) | live demos, capstone defense, staff training | `https://<random>.ngrok-free.app` | MySQL + PHP stay on this PC; tunnel exposes Apache |
| **InfinityFree** | semi-permanent hosting | `https://<account>.infinityfreeapp.com` | upload code + import DB to their vPanel |

Both read configuration from the same `config.php` / `includes/send_email.php` layer: real environment variables win; `config.php` constants are the fallback (InfinityFree has no user-settable env vars, so constants are the practical path there).

---

## 0. Prerequisites (both targets)

- PHP **8.x** (InfinityFree runs 8.2/8.3; local XAMPP ships 8.2 — all code lints clean on both)
- MySQL 5.7+ / MariaDB, with `marlehoteldb` importable
- cURL enabled (used by the email API layer)
- A working local checkout with all tests passing

### 0.1 Generate the cron secret (do this first — both targets need it)

The maintenance jobs (`admin/cron_archive.php` daily, `admin/cron_expire_deposits.php` hourly) must only be triggerable by your scheduler:

```bash
php -r "echo bin2hex(random_bytes(24));"
```

Copy the 48-character output — that is your `MARLE_CRON_SECRET`. Never reuse it between environments.

### 0.2 Set up the email provider (both targets)

InfinityFree blocks `mail()` entirely, so booking confirmations/denials go through an HTTP email API (`includes/send_email.php`) — **HTTPS only, no Composer, no PHPMailer, no SMTP ports** (shared hosts routinely block 25/465/587). **Marle Hotel uses Brevo.**

| Provider | Env/constants | Notes |
|---|---|---|
| **Brevo** (this project's provider) | `MARLE_EMAIL_PROVIDER=brevo`, `MARLE_EMAIL_API_KEY`, `MARLE_EMAIL_FROM` | Free tier: 300/day. Verify a **sender address or domain** in the Brevo dashboard first |
| **Resend** | same three vars, `provider=resend` | Free tier: 100/day; `onboarding@resend.dev` works for testing only |
| **Mailgun** | same three + `MARLE_EMAIL_DOMAIN` | Requires a verified sending domain |

| Variable | Purpose |
|---|---|
| `MARLE_EMAIL_PROVIDER` | `brevo` (default) · `resend` · `mailgun` |
| `MARLE_EMAIL_API_KEY` | the provider API key — **never commit it** |
| `MARLE_EMAIL_FROM` | `Marle Hotel <noreply@yourdomain.com>` — must match the sender/domain verified with the provider |
| `MARLE_EMAIL_NOTIFY_TO` | the hotel inbox for new-booking/cancellation alerts; **guest mail replies here** (Reply-To). Falls back to the hotel's published address |
| `MARLE_BASE_URL` | absolute site root so links inside emails work behind a tunnel: `https://marlehotel.com` (empty = auto-detect) |
| `MARLE_EMAIL_DOMAIN` | **Mailgun only** |
| `MARLE_EMAIL_REQUIRE_DNS_CHECK` | booking-form email rule (syntax + real mail server); on by default |

**Sender verification matters:** a `From:` on a domain the provider has not verified (SPF + DKIM) fails DMARC and lands in spam. A `gmail.com` `From:` will not work.

**Guest replies:** mail to a guest carries `Reply-To: <MARLE_EMAIL_NOTIFY_TO>`, so a guest's reply reaches the front desk rather than a dead `noreply@`. Alerts to the hotel inbox carry no Reply-To.

**Unconfigured = feature off**: booking flows keep working, no email is sent, and a line lands in the error log. Placeholder values (still containing `yourdomain.com`) are treated as **not configured** on purpose, so a half-finished setup reports *which* variable is missing instead of failing at the provider. Safe to deploy before finishing this step.

### 0.3 Apply the booking migrations (both targets)

Run these **once** on the target database, in order, after importing the base dump:

```bash
mysql -u<user> -p <db> < database/phase15_guest_discounts.sql   # senior/PWD discount columns + deposit settings
mysql -u<user> -p <db> < database/phase16_booking_deposit.sql   # reservation deposit + cancellation forfeiture
mysql -u<user> -p <db> < database/phase17_deposit_hold.sql      # optional: seeds the unpaid-booking hold window (safe to re-run)
```

The first two are plain `ALTER TABLE` statements and **not idempotent — do not re-run**; they fail harmlessly with a duplicate-column error if run twice. `phase17` only seeds one `site_options` row (`deposit_hold_hours`) with `INSERT IGNORE`, so it is safe to re-run and never overwrites a value the owner changed — the code also defaults to 24 hours when the row is missing.

Then confirm the hotel's own figures (owner-editable, no code change needed):

```sql
SELECT option_key, option_value FROM site_options WHERE option_key LIKE 'deposit%';
-- deposit_rate (percent), deposit_gcash_number, deposit_bank_details
```

- [ ] `uploads/booking_proof/` exists and is **writable** by PHP — guests upload their deposit proof there. On shared hosts set it to `755` (`775` only if the PHP user is not the owner).
- [ ] Approval is now gated: a booking **cannot** be approved until its deposit is `verified`. The block is deliberate and there is **no override** (a room must never be confirmed with untracked money); the refusal is audit-logged as `booking_approve_blocked`.
- [ ] Schedule **`admin/cron_expire_deposits.php` hourly** (see §1.4). An unpaid booking holds its room, so it is only held for `deposit_hold_hours` (Settings → Reservation Deposit, default 24) before the job releases it — the guest and the front desk are emailed, and **nothing is charged**. With no scheduler the room stays held until someone runs the job (CLI: `php admin/cron_expire_deposits.php`; add `--dry-run` to see what it would release).
- [ ] **A deposit is only verified against a receipt that exists.** The manager's *Verify deposit* button appears only once the guest has uploaded proof of payment, and the endpoint refuses a verify outright when `deposit_proof_path` is empty (`e2e_booking_flow.php` step 4b). A guest paying **cash at the desk** therefore still has to send a receipt + reference through the status page, or the manager has nothing to check the payment against. Tell staff before the first shift.

---

## 1. Target A — ngrok (tunnel from this XAMPP box)

### 1.1 Prepare the host machine

1. Start Apache + MySQL in the XAMPP control panel.
2. Confirm the app works locally first: `http://localhost/MarleHotel/`.
3. Set env vars for the PHP process. Since XAMPP Apache inherits the machine environment, set them system-wide (PowerShell, elevated):

   ```powershell
   setx MARLE_DB_HOST "localhost"
   setx MARLE_DB_USER "root"
   setx MARLE_DB_PASS "<your-local-mysql-password>"
   setx MARLE_DB_NAME "marlehoteldb"
   setx MARLE_CRON_SECRET "<secret from 0.1>"
   setx MARLE_EMAIL_PROVIDER "resend"
   setx MARLE_EMAIL_API_KEY "<provider key>"
   setx MARLE_EMAIL_FROM "Marle Hotel <noreply@yourdomain.com>"
   ```

   Restart Apache after `setx` so it picks up the new environment.

4. Alternatively (no env vars): edit `config.php` constants directly — the file ships with safe local defaults and only needs `MARLE_CRON_SECRET` + email values filled in.

### 1.2 Start the tunnel

1. Install ngrok and run once: `ngrok config add-authtoken <your-token>`.
2. Reserve a **static domain** (free tier allows one): `ngrok tunnel reserved` in the dashboard, then:

   ```bash
   ngrok http --host-header=localhost 80
   ```

   With a reserved domain: `ngrok http --host-header=localhost --domain=your-name.ngrok-free.app 80`.

   `--host-header=localhost` is required — without it, Apache routes to a different vhost and PHP never runs.

3. Note the `https://...ngrok-free.app` URL.

### 1.3 Verify the tunnel

- [ ] `https://<your-url>/MarleHotel/` loads the homepage with **no PHP warnings** visible (XAMPP defaults would leak them — see §3 hardening).
- [ ] Guest login works with a real token; the session **survives a page reload** (proves the `X-Forwarded-Proto` Secure-cookie fix is working — sessions break immediately if it isn't).
- [ ] Approve a test booking in the admin panel → confirmation email arrives (or error log shows the provider rejection if the key is a placeholder).
- [ ] Admin chat reply from the tunnel reaches the guest portal page.

### 1.4 Point the external scheduler (both §1 and §2 need this)

InfinityFree cannot run cron jobs, and this XAMPP box only runs while powered on. Use a free external pinger:

1. Create an account at cron-job.org (or similar).
2. New job:
   - URL: `https://<your-domain>/admin/cron_archive.php?key=<MARLE_CRON_SECRET>`
   - Schedule: daily (e.g. 03:00). The no-show grace logic in the job is date-based, so any consistent daily slot works.
3. **Second job — unpaid-booking release:**
   - URL: `https://<your-domain>/admin/cron_expire_deposits.php?key=<MARLE_CRON_SECRET>`
   - Schedule: **hourly** (or every 15 minutes). A booking is only held for the configured window, so a daily run would leave a room held for up to a day longer than the guest was told. Add `&dry_run=1` once to see what it would release without writing anything.
4. Expected response: HTTP 200 with a JSON/log body. A 403 means the key doesn't match; 503 means the secret isn't configured on the host.

---

## 2. Target B — InfinityFree

### 2.1 Create the database

1. vPanel → **MySQL Databases**: create a database (name looks like `ifX_XXXXX_marlehotel`).
2. Note the **MySQL host** (`sqlXXX.infinityfree.com`), **username**, **password**, **database name**.
3. Export your local DB: `mysqldump -u root marlehoteldb > marlehoteldb.sql`, then import via vPanel → phpMyAdmin.
   - If the dump exceeds the upload limit, compress it (`.sql.zip` is accepted) or import table-by-table.

### 2.2 Upload the code

1. vPanel → **File Manager** (or FTP). Target: `htdocs/`.
2. Upload the app so it lives at `htdocs/MarleHotel/` (or the domain root — keep relative paths intact; the code uses only relative URLs, so either works).
3. **Exclude from upload:** `.git/`, `.history/`, `obsidian brain/`, `node_modules/`, local `homepage/release_log.txt`.
4. Create `uploads/chat/` under the app dir and make it writable (File Manager → permissions 755; uploads land there from the guest chat).
5. PHP version: vPanel → **PHP Configuration** → pick **8.2 or 8.3**. Also ensure **cURL** is enabled (it is by default).

### 2.3 Configure secrets

InfinityFree does not expose user-settable environment variables, so edit `config.php` **on the server** (never commit these values back to git):

```php
define('MARLE_DB_HOST', 'sqlXXX.infinityfree.com');
define('MARLE_DB_USER', 'ifX_XXXXX');
define('MARLE_DB_PASS', '<vpanel password>');
define('MARLE_DB_NAME', 'ifX_XXXXX_marlehotel');
define('MARLE_CRON_SECRET', '<secret from 0.1>');
define('MARLE_EMAIL_PROVIDER', 'resend');
define('MARLE_EMAIL_API_KEY', '<provider key>');
define('MARLE_EMAIL_FROM', 'Marle Hotel <noreply@yourdomain.com>');
```

Preferably keep the `getenv(...) ?: '<value>'` structure and just fill the second argument — env vars still win if you later move to a host that supports them.

### 2.4 Verify the deployment

- [ ] Homepage loads, no PHP warnings.
- [ ] Guest token login works end-to-end (room + countdown visible).
- [ ] Admin panel: approve a booking → email arrives at a real address you control. If the provider key is wrong, the flow still succeeds but the error log (vPanel has no log viewer — check with a temporary `ini_set('log_errors',1)` script) shows `send_email: provider ... failed`.
- [ ] `https://<domain>/admin/cron_archive.php` **without** a key returns **403** (proves the secret gate is live).
- [ ] Same URL **with** the key returns 200.
- [ ] `https://<domain>/admin/cron_expire_deposits.php` **without** a key returns **403** too, and **with** the key returns 200 (it is safe to call repeatedly — a booking that is not overdue is left alone).
- [ ] Chat widget: guest sends → admin receives → admin replies → guest sees reply.

### 2.5 Known InfinityFree limitations (accepted, not bugs)

| Limitation | Impact | Mitigation |
|---|---|---|
| `mail()` blocked | booking emails must use the HTTP API | done — `send_email.php` |
| No user cron | archive job needs external trigger | cron-job.org (§1.4) |
| Sleeps after inactivity | first page load after idle is slow (~1–3 s) | cosmetic only |
| No SSH/composer | PHPMailer-style deps impractical | curl-in-include approach (done) |
| Outbound ports limited to 80/443 | email APIs must be HTTPS | all three providers are HTTPS |

---

## 3. Post-deployment hardening (mostly done in code)

1. ~~Hide PHP errors~~ **Already handled in the repo** — `.user.ini` ships `display_errors=Off`, `log_errors=On`, production `error_reporting`, and the three `process_service_action.php` files set it in code too. Only remaining action: on InfinityFree, adjust `error_log` inside `.user.ini` to an absolute path inside your account if you want the log file somewhere specific.

2. ~~Force HTTPS redirect~~ **Already handled in the repo** — the site `.htaccess` ships the redirect with `X-Forwarded-Proto` support (works behind ngrok) and a localhost/127.0.0.1 exemption so local XAMPP development stays plain-HTTP. One InfinityFree note: their free hosting forces HTTPS at the edge already, so the rules are simply inert there.

3. **Rotate the cron secret** after the defense/demo if it was shared anywhere.

4. **First real booking smoke test**: create → approve → confirm email → guest portal shows the room. That path exercises DB, sessions, email API, and audit logging in one pass.

---

## 4. Rollback / incident notes

- Code is stateless; rolling back = re-uploading the previous version (git tag the release before deploying: `git tag deploy-YYYYMMDD`).
- The database is the only stateful piece: take a phpMyAdmin export before any deployment that changes schema.
- If email fails silently, check the provider dashboard's logs first (Resend/Brevo both show per-message status) — the app logs only the HTTP failure, not the provider's rejection reason.
- If the tunnel URL changed (free ngrok), re-do §1.4's cron URL.
