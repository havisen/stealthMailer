<!-- PROJECT HANDOVER RULE:
Every contributor must read handover.md before starting work and update it
before handing work over.
Record changes, documentation, configuration names, migrations, tests/results, known issues, decisions, and the exact next step.
Never record passwords, API keys, encryption keys, authentication codes, or private message contents.
Every handover must repeat this requirement for the next contributor, who must follow the same rule.
-->

# Project handover

## Current state and latest changes

The shared foundation and administrator access boundary are now locally installed and verified. On 2026-10-23 a disposable Windows toolchain used PHP 8.4.27 NTS VS17 x64 with json, intl, mbstring, openssl, PDO, pdo_mysql, sodium, fileinfo, zip, and sockets; Composer 2.10.3; MariaDB 11.4.5 on `127.0.0.1:3307`; Caddy 2.11.7 with a private local CA; PHP-CGI FastCGI on `127.0.0.1:9000`; and a disposable Node SMTP harness. The toolchain, certificates, and generated secrets live under `.tools/`, which is gitignored and must never be committed.

`composer.lock` now has a valid content hash and 17 locked packages. Preflight passed all checks. Migrations 001/002/003/006/007/008 were applied to the disposable `mailpanel` application schema; 004/005 are empty and were skipped; a repeat `migrate up` reported no pending work. The disposable `mailpanel_test` schema is reset destructively by the integration runners. No production migration has run.

Unit, disposable-database, Slim HTTP, real-HTTPS cookie, bootstrap, migration-locking, worker, SMTP-connectivity, jobs/submission, and send-API database checks all passed. The actual CLI recovery command also ran successfully: the administrator security version moved from 5 to 6, sessions were revoked, an `administrator_password_reset` audit event was written, and a subsequent HTTPS login passed.

The authenticated sending-account UI is now wired: list, create, edit, test, disable, and delete, all behind administrator authentication and action-specific CSRF. A test-only Proton sender was imported from the gitignored `config/mail.local.php` into the encrypted `mail_accounts` table, listed in the account UI over real HTTPS, and authenticated successfully through `mail:test-account` against `smtp.protonmail.ch:587`. No message DATA was sent. The token is not in version control. IMAP/POP3 authentication probes are implemented, but the real Private Email login awaits the mailbox password; inbox synchronization is not implemented.

Runtime verification found and fixed real defects: repeated named PDO placeholders in `AdministratorRepository`, `LoginThrottleRepository`, `JobRepository`, and `SubmissionRepository` (native prepares reject duplicates); a Windows ACL false negative in private-directory checks, now handled by `DirectoryProbe`; discarded PHPMailer TLS error detail, now retained by `InstrumentedSmtp` for safe category mapping only; a test-double interface gap in the API-key runner; and a test-scope collision in the authentication HTTP runner. The repository's `.gitignore` now excludes the disposable `.tools/` toolchain.

The route table now contains the six administrator routes, eight sending-account routes, and `POST /api/v1/mail/send` behind `ApiKeyMiddleware`; the worker registers `smtp.send`. The API route registration differs from the previous handover note, which said `routes/api.php` was empty. Its unit and disposable-database checks pass, but no real API-key HTTP request, OpenAPI response comparison, two-connection concurrency race, or real-provider send acceptance has run. Keep the API and outbound workers disabled at the proxy until Milestones 4 and 5 are accepted.

The repository also contains isolated SMTP account/submission/delivery and Assignments 06–10 implementations. It is not an end-to-end application and must not be deployed. The old sending application was not modified. The external API surface remains exactly the send endpoint; no receiving, inbox, contacts, tasks, OTP-generation, or OTP-verification endpoint was added.

## Files and interfaces

Key foundation additions are `src/Runtime/DirectoryProbe.php`, `src/Config/LocalMailConfig.php`, `src/Mail/MailboxConnectionTester.php`, `bin/mail.php`, `tests/Integration/run-bootstrap.php`, `tests/Integration/run-auth-https.php`, and `tests/Integration/run-smtp-connectivity.php`. `DirectoryProbe::isWritable()` proves a private directory accepts a short create/write/delete probe; `Preflight` and `LoggerFactory` use it instead of `is_writable()`. `InstrumentedSmtp::safeLastErrorDetail()` exposes only the captured connection warning to failure classification, never to output. `MailboxConnectionTester` performs IMAP/POP3 authentication over implicit TLS and returns only a safe category.

Existing interfaces remain `SmtpTransport`, `SubmissionDeliveryStore`, `SendingAccountProvider`, `ApiKeyStore`, `ApiPrincipal`, `ApiKeyService`, `ApiKeyMiddleware`, `SendRequestValidator`, `ApiAccessRepository`, `ApiSendService`, and `SendMailController`. Authentication uses `AuthService`, four prepared repositories, `PasswordPolicy`/`PasswordHasher`, `CsrfTokenManager`, `SecureCookieManager`, authentication/CSRF/security-header middleware, `AuthController`, three DTOs, CLI management, templates, and unit/database/HTTP/HTTPS checks. The account boundary adds `AccountController`, `AccountService`, `AccountRepository`, `EndpointPolicy`, `CredentialCipher`, `SmtpConnectionTester`, and `PhpMailerSmtpProbe`.

`routes/web.php` registers `GET|POST /login`, protected `GET /`, protected `GET|POST /settings/password`, protected+CSRF `POST /logout`, and the eight protected/CSRF sending-account routes. `routes/api.php` registers exactly `POST /api/v1/mail/send` behind `ApiKeyMiddleware`. `bootstrap/worker.php` registers `smtp.send`. Future browser mutations must add the same administrator authentication and action-specific CSRF. CORS stays disabled.

## Configuration and database

No secrets are present in version control. Required foundation names are documented in `docs/configuration.md`: `APP_ENV`, `MAILPANEL_DB_DSN`, `MAILPANEL_DB_USER`, `MAILPANEL_DB_PASSWORD`, both keyset/active-key pairs, `MAILPANEL_API_FINGERPRINT_KEY`, `MAILPANEL_AUTH_CSRF_KEY`, `MAILPANEL_AUTH_THROTTLE_KEY`, and `MAILPANEL_MESSAGE_ID_DOMAIN`. Standalone auth/API secrets must remain separate base64 values of at least 32 random bytes. The local verification environment lives only in the gitignored `.tools/verify-env.ps1`; never copy those values into documentation, commits, or production.

`config/mail.php` is the committed operator allow-list for SMTP endpoints and currently approves `smtp.protonmail.ch:587` plus `mail.privateemail.com:465`/`:587`; `MAILPANEL_APPROVED_SMTP_ENDPOINTS` overrides it. `config/mail.local.php` is gitignored via `/config/*.local.php` and holds the test-only Proton token plus Private Email IMAP/POP3 endpoints. It must never be committed, exposed under `public/`, or logged. The token was also shared in chat and should be rotated after testing. The final app accepts these values through the authenticated `/accounts` form; the local file is only for import and connectivity tests.

The committed `composer.lock` is the reviewed dependency source. Production must use `composer install --no-dev --classmap-authoritative`, not an update. The application document root must be `public/` only; private storage, logs, the application root, `.tools/`, and `config/mail.local.php` are outside it.

Migrations 001 (administrator, sessions, throttles), 002 (accounts), 003 (submissions), 006 (audit events), 007 (jobs), and 008 (API access) are applied only to the disposable local schema. 004 and 005 are empty placeholders and are skipped. Never rewrite an applied migration; use additive migrations. There are no down migrations; back up and test restore before production changes.

## Verification and results

All commands were run with the disposable environment loaded. Exact per-module results are in `docs/testing.md`.

- `php bin/console.php preflight`: 13 OK, including PHP 8.4.27, all required extensions, Argon2id, writable private storage/logs, readable CA bundle, and database query; exit 0.
- Public TLS trust: PHP OpenSSL with `verify_peer` and `verify_peer_name` fetched `https://packagist.org/packages.json` over the public CA chain (HTTP 200); the local HTTPS test separately validated the private CA chain.
- `php bin/console.php migrate status` / `up` / `status`: 001/002/003/006/007/008 applied; repeat `up` reported no pending migrations; exit 0.
- `php tests/Foundation/run-foundation.php`: 26 assertions, including the committed endpoint allow-list and environment override.
- `php tests/Unit/run-auth.php`: 16 assertions.
- `php tests/Unit/run-unit.php`: 43 checks, including mailbox input validation.
- `php tests/Unit/run-jobs.php`: 8 checks.
- `php tests/Unit/run-submissions.php`: 10 checks.
- `php tests/Unit/run-smtp-delivery.php`: 0 failures.
- `php tests/Unit/run-api-keys.php`: PASS.
- `php tests/Unit/run-send-api.php`: 0 failures.
- `php tests/Integration/run-migrations.php`: 8 assertions, including advisory-lock overlap behavior.
- `php tests/Integration/run-auth.php`: 28 assertions.
- `php tests/Integration/run-auth-http.php`: 35 assertions, including the full authenticated account lifecycle (create, edit with blank password, disable, delete) and CSRF rejection.
- `php tests/Integration/run-auth-https.php`: 59 assertions over real TLS with certificate-chain validation, Secure/HttpOnly/SameSite=Strict cookies, CSRF, password-change session replacement, logout revocation, private-storage 404, HTTP-to-HTTPS redirect, and the authenticated account list/form.
- `php tests/Integration/run-bootstrap.php`: 37 assertions; 15 routes; 20 Twig templates; PDO, logging/redaction, and security headers.
- `php tests/Integration/run-jobs-and-submissions.php`: 7 checks, 0 failures.
- `php tests/Integration/run-send-api.php`: 6 checks, 0 failures.
- `php tests/Integration/run-smtp-connectivity.php`: 10 assertions; implicit TLS, STARTTLS, wrong-password authentication failure, untrusted-CA rejection, and one accepted DATA delivery.
- `php bin/console.php mail:import` followed by `mail:test-account --id=acct_...`: encrypted account stored; Proton SMTP authentication succeeded without sending DATA.
- `php bin/console.php mail:test-imap --password-stdin` and `mail:test-pop3 --password-stdin`: safe failure categories for empty input; IMAP/POP3 framing and correct/wrong-password behavior also passed against a disposable local TLS mock. Real Private Email login awaits the mailbox password.
- `php bin/worker.php --queue=maintenance --budget=1` and `--queue=outbound --budget=1`: exit 0, `budget_exhausted`, worker rows written. The invalid-queue path returned 64.
- CLI recovery: `admin:reset-password --email=administrator@example.test --password-stdin` exit 0; security version 5 to 6; sessions revoked; audit event present; HTTPS login passed afterwards.
- `php -l` across 142 project PHP files: 0 failures. `composer validate` passed with only the missing-license warning; `composer install --dry-run` reported nothing to install.

Unverified: production cPanel document-root/reverse-proxy behavior, actual `REMOTE_ADDR`/forwarded-header boundary, real Private Email IMAP/POP3 authentication (mailbox password not yet provided), IMAP synchronization, real outbound delivery through Proton/Private Email, cPanel cron scheduling and delayed cron, throttle races under real concurrency, OpenAPI response-by-response validation, two-connection idempotency/rate races, bounded retention cleanup, backup/restore, accessibility, and production deployment.

## Known issues and decisions

- The authenticated account UI manages SMTP senders only. IMAP/POP3 settings and inbox synchronization are not part of the account schema or panel yet; `mail:test-imap`/`mail:test-pop3` are authentication probes only.
- The Proton token was shared in chat and is stored in gitignored local configuration and encrypted in the disposable database. Rotate it after testing; never commit or reuse it. `config/mail.local.php` and `.tools/` are local-only.
- The stored Proton account authenticated, but no message DATA or real outbound delivery was attempted. The API remains without an API key and must stay blocked at the proxy until Milestone 5 acceptance.
- Bounded session/throttle cleanup is not implemented. It is deferred to Milestone 12 and must be a small-batch, UTC-cutoff, rerunnable maintenance job that never deletes active sessions/blocks, remote mail, contacts/tasks, or audit events inside retention. Do not use ad-hoc broad deletion.
- The API route is registered even though Milestone 5 acceptance is incomplete. It is protected by `ApiKeyMiddleware`, but no production API key exists; keep the path blocked at the proxy until HTTP/concurrency/provider checks pass.
- Forwarded IP headers remain intentionally ignored. Deployment must use the real server `REMOTE_ADDR` boundary.
- Expired/in-flight or post-acceptance-persistence sends become uncertain and are never automatically resent. Revocation blocks new/replay HTTP authorization but does not cancel already queued submissions. Bcc is encrypted/envelope-only; API content has no attachments.
- The local CA, test certificate, synthetic SMTP credentials, database password, and generated keys under `.tools/` are disposable. Never reuse or commit them.
- The Windows `is_writable()` false negative is worked around with `DirectoryProbe`; production permission checks still need a real hosting review.
- Restore must start with public/API/outbound disabled, CLI password recovery, and queued/in-flight reconciliation.

## Exact next step

Provide the Private Email mailbox password and run `php bin/console.php mail:test-imap --password-stdin` and `mail:test-pop3 --password-stdin` to complete the real authentication evidence. Then finish Milestone 3 acceptance with wrong-password/TLS failures, disable/delete behavior, and a restore/decrypt check. Implement Milestone 6 IMAP synchronization behind the authenticated panel before exposing any inbox UI. Repeat HTTPS/SMTP through the real cPanel reverse proxy and provider, keep the API and outbound workers disabled until Milestones 4–5 pass real HTTP/concurrency/provider acceptance, and implement bounded session/throttle cleanup under Milestone 12 before production exposure. Do not deploy this scaffold as a working or secured service.

**Read and update handover.md before handing over, and require the next contributor to do the same.**
