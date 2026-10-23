<!-- PROJECT HANDOVER RULE:
Every contributor must read handover.md before starting work and update it
before handing work over. Record changes, documentation, configuration names,
migrations, tests and results, known issues, decisions, and the exact next step.
Never record passwords, API keys, encryption keys, authentication codes, or
private message contents. Every handover must repeat this requirement for the
next contributor, who must follow the same rule.
-->

# Testing Introduction

The repository contains dependency-free, disposable-database, HTTP, HTTPS, and SMTP runners. They ran on 2026-10-23 in a disposable Windows verification environment: PHP 8.4.27 (NTS, VS17 x64) with json/intl/mbstring/openssl/PDO/pdo_mysql/sodium/fileinfo/zip/sockets, Composer 2.10.3, MariaDB 11.4.5 on `127.0.0.1:3307`, Caddy 2.11.7 with a private local CA, and a disposable Node SMTP server with a test CA. The local toolchain, certificates, and generated secrets live under `.tools/` and are gitignored. These are local acceptance results, not production cPanel or real-provider evidence.

Add focused tests as real behavior is implemented rather than tests that merely assert the existence of placeholders.

## Critical scenarios

- Fresh install, repeated migrations, private-file access, and administrator recovery.
- Session expiry, CSRF rejection, API scopes, key revocation, and concurrent idempotency.
- Database failure before acceptance, delayed cron, expired queued mail, SMTP rejection, and uncertain acceptance.
- Worker interruption, duplicate-send prevention, separate Sent-copy retry, and safe restore.
- UIDVALIDITY change, arrivals during backfill, remote flag/deletion changes, and interrupted synchronization.
- Multipart and malformed MIME, encoded headers, oversized bodies, HTML injection, trackers, attachment access, and path traversal.
- Reply headers, reply-all recipients, Bcc privacy, draft persistence, and double-click Send.
- Contact/task links surviving cache cleanup and deletion; task due dates in the configured timezone.

Use disposable mailboxes and databases. Run real provider tests against cPanel and a second standard provider before release. Never use private mail or production secrets as fixtures. Record exact commands, results, and unverified areas in handover.md.

## Implemented module checks

- `php tests/Foundation/run-foundation.php`: strict configuration, key-format compatibility, defaults, redaction, missing values, production debug rejection, weak keys, public-storage rejection, and the private-directory write probe.
- `php tests/Unit/run-auth.php`: password length/encoding controls, Argon2id verification, action/session/expiry-bound CSRF, malformed token rejection, and secure cookie attributes.
- `php tests/Integration/run-auth.php`: destructive disposable-schema checks for one administrator, hash-only storage, email/IP throttling, session authentication, idle/absolute expiry, password-change invalidation, CLI-style recovery, and safe audit events.
- `php tests/Integration/run-auth-http.php`: actual Slim route/middleware checks for anonymous redirect, login challenge, successful login, protected dashboard, password-change CSRF/session replacement, logout CSRF rejection, logout, and revoked-session denial.
- `php tests/Integration/run-auth-https.php`: real TLS requests through a trusted CA. Verifies the peer certificate chain, HSTS/CSP/no-store/request IDs, Secure/HttpOnly/SameSite=Strict cookies for login and session, wrong-password 401, CSRF rejection, password change and session replacement, logout revocation, private-storage 404, public asset serving, and the HTTP-to-HTTPS redirect.
- `php tests/Integration/run-bootstrap.php`: validated configuration, PDO/MySQL query, private paths, JSON logging and context redaction, compilation of every Twig template, the exact web/API route table, request IDs and security headers, and anonymous redirect/login rendering. It is non-destructive.
- `php tests/Integration/run-migrations.php`: disposable MySQL/MariaDB overlap locking, apply-once behavior, checksum drift, and failure-not-recorded behavior. It requires `MAILPANEL_TEST_ALLOW_RESET=1` and the documented `MAILPANEL_TEST_DB_*` values and must never target production.
- `php tests/Unit/run-jobs.php`: handler execution, checkpoint/timeout budget, explicit sync resume, conservative SMTP recovery, and missing-handler failure.
- `php tests/Unit/run-submissions.php`: immutable normalization, recipient deduplication, header injection, sender authorization, attachment boundaries, deadlines, Bcc-safe response, and contextual encryption.
- `php tests/Integration/run-jobs-and-submissions.php`: opt-in destructive reset of a disposable MySQL/MariaDB schema followed by atomic commit/rollback, browser-operation idempotency/conflict, cancellation race, interrupted-send uncertainty, and account-lease overlap checks. Required variables and exact command are in `docs/submissions.md`.
- `php tests/Unit/run-smtp-delivery.php`: controlled transport-double outcomes for acceptance, partial acceptance, temporary retries, attempt ceiling, DATA uncertainty, disabled accounts, lease recovery, and persistence failure after acceptance.
- `php tests/Unit/run-api-keys.php`: one-time issuance, hash-only persistence, valid/invalid/revoked authentication, sender-account scope, replacement overlap, and explicit revocation.
- `php tests/Unit/run-send-api.php`: field/type/unknown-field validation, IDNA normalization, SMTPUTF8/control rejection, duplicate/recipient/deadline limits, Bcc handling, and stable omitted-deadline canonicalization.
- `php tests/Integration/run-send-api.php`: destructive disposable-schema migration through 008, atomic submission/job/quota/replay commit, identical replay without quota, changed-payload conflict, disabled-account rejection, revocation-before-replay, and database-failure rollback. Use `MAILPANEL_TEST_DB_DSN`, `MAILPANEL_TEST_DB_USER`, `MAILPANEL_TEST_DB_PASSWORD`, and `MAILPANEL_TEST_ALLOW_RESET=1`.
- `php tests/Integration/run-smtp-connectivity.php`: real PHPMailer implicit-TLS and STARTTLS probes, correct- and wrong-password authentication, certificate rejection against an untrusted CA, and one accepted transport delivery through the disposable SMTP server. It requires the `MAILPANEL_SMTP_TEST_*` variables and a running local server.

## Commands and disposable database setup

Create a disposable schema and user, then export the values used by the integration runners. Never point them at production.

```text
php bin/console.php preflight
php bin/console.php migrate status
php bin/console.php migrate up
php bin/console.php migrate status

php tests/Foundation/run-foundation.php
php tests/Unit/run-auth.php
php tests/Unit/run-unit.php
php tests/Unit/run-jobs.php
php tests/Unit/run-submissions.php
php tests/Unit/run-smtp-delivery.php
php tests/Unit/run-api-keys.php
php tests/Unit/run-send-api.php
php tests/Integration/run-bootstrap.php
php tests/Integration/run-migrations.php
php tests/Integration/run-auth.php
php tests/Integration/run-auth-http.php
php tests/Integration/run-auth-https.php
php tests/Integration/run-jobs-and-submissions.php
php tests/Integration/run-send-api.php
php tests/Integration/run-smtp-connectivity.php

php bin/console.php mail:import
php bin/console.php mail:test-account --id=acct_...
php bin/console.php mail:test-imap --password-stdin
php bin/console.php mail:test-pop3 --password-stdin
```

The authentication integration scripts require `MAILPANEL_TEST_ALLOW_RESET=1` plus `MAILPANEL_TEST_DB_DSN`, `MAILPANEL_TEST_DB_USER`, and `MAILPANEL_TEST_DB_PASSWORD`; they drop authentication tables and must target only a disposable schema. Exact examples are in `docs/authentication.md`. The HTTPS test additionally needs `MAILPANEL_HTTPS_BASE_URL`, `MAILPANEL_HTTPS_CA_FILE`, `MAILPANEL_HTTPS_TEST_EMAIL`, and `MAILPANEL_HTTPS_TEST_PASSWORD`. The SMTP test needs the `MAILPANEL_SMTP_TEST_*` variables and a disposable server with a test CA.

## Verification results (2026-10-23)

All of the following completed with exit code 0 after the fixes recorded in `handover.md`:

| Check | Result |
| --- | --- |
| Preflight | 13 OK lines, including PHP 8.4.27, all required extensions, writable private storage/logs, readable CA bundle, and database query |
| Public TLS trust | PHP OpenSSL with `verify_peer`/`verify_peer_name` fetched `https://packagist.org/packages.json` over the public CA chain (HTTP 200) |
| Migrations | 001/002/003/006/007/008 applied; 004/005 empty and skipped; repeat `up` reported no pending work; status all APPLIED |
| Foundation | 22 assertions |
| Unit auth | 16 assertions |
| Unit core | 43 checks |
| Unit jobs | 8 checks |
| Unit submissions | 10 checks |
| Unit SMTP delivery | 0 failures |
| Unit API keys | PASS |
| Unit send API | 0 failures |
| Migration locking | 8 assertions |
| Authentication database | 28 assertions |
| Authentication HTTP | 35 assertions, including the full account lifecycle (create, edit, disable, delete) and CSRF rejection |
| Authentication HTTPS | 59 assertions, including the authenticated account list/form |
| Bootstrap/runtime | 37 assertions; 15 routes; 20 Twig templates |
| Jobs/submissions database | 7 checks, 0 failures |
| Send API database | 6 checks, 0 failures |
| SMTP connectivity | 10 assertions; implicit TLS, STARTTLS, auth failure, untrusted certificate, one accepted delivery |
| Account storage and Proton SMTP | Encrypted DB account imported; `mail:test-account` authenticated to `smtp.protonmail.ch:587` without sending DATA |
| IMAP/POP3 probes | Input validation, TLS connection, correct-credential acceptance, and wrong-password rejection passed against a disposable local TLS mock; real Private Email login awaits the mailbox password; synchronization is not implemented |
| CLI recovery | exit 0; security version 5 to 6; audit event written; login over HTTPS passed afterwards |

A PHP lint pass reported `Linted 142 PHP files; failures: 0`. The dependency lock was regenerated, validated, and installed; `composer install --dry-run` reported nothing to install.

## Unverified behavior

This Windows host does not provide cPanel, a real mailbox, real IMAP 993 login, a production reverse proxy, or production cron. Still unverified: cPanel/cron timing, forwarded-proxy behavior, real provider TLS/limits and outbound delivery, real IMAP/POP3 authentication and IMAP/UIDVALIDITY synchronization, throttle races under concurrency, OpenAPI response-by-response validation, bounded retention cleanup, backup/restore, accessibility, and production deployment. Keep outbound and the public API disabled until their acceptance checks pass.
