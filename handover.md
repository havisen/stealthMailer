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

Runtime verification found and fixed real defects: repeated named PDO placeholders in `AdministratorRepository`, `LoginThrottleRepository`, `JobRepository`, and `SubmissionRepository` (native prepares reject duplicates); a Windows ACL false negative in private-directory checks, now handled by `DirectoryProbe`; discarded PHPMailer TLS error detail, now retained by `InstrumentedSmtp` for safe category mapping only; a test-double interface gap in the API-key runner; and a test-scope collision in the authentication HTTP runner. The repository's `.gitignore` now excludes the disposable `.tools/` toolchain.

The route table contains the six administrator routes plus `POST /api/v1/mail/send` behind `ApiKeyMiddleware`; the worker registers `smtp.send`. That API route registration differs from the previous handover note, which said `routes/api.php` was empty. Its unit and disposable-database checks pass, but no real API-key HTTP request, OpenAPI response comparison, two-connection concurrency race, or real-provider acceptance has run. Keep the API and outbound workers disabled at the proxy until Milestones 4 and 5 are accepted.

The repository also contains isolated SMTP account/submission/delivery and Assignments 06–10 implementations. It is not an end-to-end application and must not be deployed. The old sending application was not modified. The external API surface remains exactly the send endpoint; no receiving, inbox, contacts, tasks, OTP-generation, or OTP-verification endpoint was added.

## Files and interfaces

Key foundation additions are `src/Runtime/DirectoryProbe.php`, `tests/Integration/run-bootstrap.php`, `tests/Integration/run-auth-https.php`, and `tests/Integration/run-smtp-connectivity.php`. `DirectoryProbe::isWritable()` proves a private directory accepts a short create/write/delete probe; `Preflight` and `LoggerFactory` use it instead of `is_writable()`. `InstrumentedSmtp::safeLastErrorDetail()` exposes only the captured connection warning to failure classification, never to output.

Existing interfaces remain `SmtpTransport`, `SubmissionDeliveryStore`, `SendingAccountProvider`, `ApiKeyStore`, `ApiPrincipal`, `ApiKeyService`, `ApiKeyMiddleware`, `SendRequestValidator`, `ApiAccessRepository`, `ApiSendService`, and `SendMailController`. Authentication uses `AuthService`, four prepared repositories, `PasswordPolicy`/`PasswordHasher`, `CsrfTokenManager`, `SecureCookieManager`, authentication/CSRF/security-header middleware, `AuthController`, three DTOs, CLI management, templates, and unit/database/HTTP/HTTPS checks.

`routes/web.php` registers only `GET|POST /login`, protected `GET /`, protected `GET|POST /settings/password`, and protected+CSRF `POST /logout`. `routes/api.php` registers exactly `POST /api/v1/mail/send` behind `ApiKeyMiddleware`. `bootstrap/worker.php` registers `smtp.send`. Future browser mutations must add the same administrator authentication and action-specific CSRF. CORS stays disabled.

## Configuration and database

No secrets are present in version control. Required foundation names are documented in `docs/configuration.md`: `APP_ENV`, `MAILPANEL_DB_DSN`, `MAILPANEL_DB_USER`, `MAILPANEL_DB_PASSWORD`, both keyset/active-key pairs, `MAILPANEL_API_FINGERPRINT_KEY`, `MAILPANEL_AUTH_CSRF_KEY`, `MAILPANEL_AUTH_THROTTLE_KEY`, and `MAILPANEL_MESSAGE_ID_DOMAIN`. Standalone auth/API secrets must remain separate base64 values of at least 32 random bytes. The local verification environment lives only in the gitignored `.tools/verify-env.ps1`; never copy those values into documentation, commits, or production.

The committed `composer.lock` is the reviewed dependency source. Production must use `composer install --no-dev --classmap-authoritative`, not an update. The application document root must be `public/` only; private storage, logs, the application root, and `.tools/` are outside it.

Migrations 001 (administrator, sessions, throttles), 002 (accounts), 003 (submissions), 006 (audit events), 007 (jobs), and 008 (API access) are applied only to the disposable local schema. 004 and 005 are empty placeholders and are skipped. Never rewrite an applied migration; use additive migrations. There are no down migrations; back up and test restore before production changes.

## Verification and results

All commands were run with the disposable environment loaded. Exact per-module results are in `docs/testing.md`.

- `php bin/console.php preflight`: 13 OK, including PHP 8.4.27, all required extensions, Argon2id, writable private storage/logs, readable CA bundle, and database query; exit 0.
- Public TLS trust: PHP OpenSSL with `verify_peer` and `verify_peer_name` fetched `https://packagist.org/packages.json` over the public CA chain (HTTP 200); the local HTTPS test separately validated the private CA chain.
- `php bin/console.php migrate status` / `up` / `status`: 001/002/003/006/007/008 applied; repeat `up` reported no pending migrations; exit 0.
- `php tests/Foundation/run-foundation.php`: 22 assertions.
- `php tests/Unit/run-auth.php`: 16 assertions.
- `php tests/Unit/run-unit.php`: 40 checks.
- `php tests/Unit/run-jobs.php`: 8 checks.
- `php tests/Unit/run-submissions.php`: 10 checks.
- `php tests/Unit/run-smtp-delivery.php`: 0 failures.
- `php tests/Unit/run-api-keys.php`: PASS.
- `php tests/Unit/run-send-api.php`: 0 failures.
- `php tests/Integration/run-migrations.php`: 8 assertions, including advisory-lock overlap behavior.
- `php tests/Integration/run-auth.php`: 28 assertions.
- `php tests/Integration/run-auth-http.php`: 16 assertions.
- `php tests/Integration/run-auth-https.php`: 52 assertions over real TLS with certificate-chain validation, Secure/HttpOnly/SameSite=Strict cookies, CSRF, password-change session replacement, logout revocation, private-storage 404, and HTTP-to-HTTPS redirect.
- `php tests/Integration/run-bootstrap.php`: 28 assertions; 7 routes; 20 Twig templates; PDO, logging/redaction, and security headers.
- `php tests/Integration/run-jobs-and-submissions.php`: 7 checks, 0 failures.
- `php tests/Integration/run-send-api.php`: 6 checks, 0 failures.
- `php tests/Integration/run-smtp-connectivity.php`: 10 assertions; implicit TLS, STARTTLS, wrong-password authentication failure, untrusted-CA rejection, and one accepted DATA delivery.
- `php bin/worker.php --queue=maintenance --budget=1` and `--queue=outbound --budget=1`: exit 0, `budget_exhausted`, worker rows written. The invalid-queue path returned 64.
- CLI recovery: `admin:reset-password --email=administrator@example.test --password-stdin` exit 0; security version 5 to 6; sessions revoked; audit event present; HTTPS login passed afterwards.
- `php -l` across 138 project PHP files: 0 failures. `composer validate` passed with only the missing-license warning; `composer install --dry-run` reported nothing to install.

Unverified: production cPanel document-root/reverse-proxy behavior, actual `REMOTE_ADDR`/forwarded-header boundary, real IMAP 993 and mailbox protocol behavior, real SMTP provider TLS/limits, cPanel cron scheduling and delayed cron, throttle races under real concurrency, OpenAPI response-by-response validation, two-connection idempotency/rate races, bounded retention cleanup, backup/restore, accessibility, and production deployment.

## Known issues and decisions

- Bounded session/throttle cleanup is not implemented. It is deferred to Milestone 12 and must be a small-batch, UTC-cutoff, rerunnable maintenance job that never deletes active sessions/blocks, remote mail, contacts/tasks, or audit events inside retention. Do not use ad-hoc broad deletion.
- The API route is registered even though Milestone 5 acceptance is incomplete. It is protected by `ApiKeyMiddleware`, but no production API key exists; keep the path blocked at the proxy until HTTP/concurrency/provider checks pass.
- Forwarded IP headers remain intentionally ignored. Deployment must use the real server `REMOTE_ADDR` boundary.
- Expired/in-flight or post-acceptance-persistence sends become uncertain and are never automatically resent. Revocation blocks new/replay HTTP authorization but does not cancel already queued submissions. Bcc is encrypted/envelope-only; API content has no attachments.
- The local CA, test certificate, synthetic SMTP credentials, database password, and generated keys under `.tools/` are disposable. Never reuse or commit them.
- The Windows `is_writable()` false negative is worked around with `DirectoryProbe`; production permission checks still need a real hosting review.
- Restore must start with public/API/outbound disabled, CLI password recovery, and queued/in-flight reconciliation.

## Exact next step

Complete Milestone 0's remaining real cPanel/disposable-mailbox checks (IMAP 993, UIDVALIDITY/UID, MIME, flags, safe move, Sent APPEND) and repeat TLS/SMTP/HTTPS checks through the real reverse proxy and a real provider. Then integrate Milestone 3 account routes behind the administrator authentication and action-specific CSRF. Keep the API and outbound workers disabled until Milestones 4 and 5 pass real HTTP/concurrency/provider acceptance. Implement bounded session/throttle cleanup under Milestone 12 before production exposure. Do not deploy this scaffold as a working or secured service.

**Read and update handover.md before handing over, and require the next contributor to do the same.**
