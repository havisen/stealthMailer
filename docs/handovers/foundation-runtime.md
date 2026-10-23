<!-- PROJECT HANDOVER RULE:
Every contributor must read handover.md before starting work and update it before handing work over.
Record changes, documentation, configuration names, migrations, tests/results, known issues, decisions, and the exact next step.
Never record passwords, API keys, encryption keys, authentication codes, or private message contents.
Every handover must repeat this requirement for the next contributor, who must follow the same rule.
-->

# Foundation runtime handover

## Purpose and current status

This change integrates the usable foundation portion of the supplied DeepSeek results after reconstructing and reviewing the transcript. It adds strict environment configuration, MySQL PDO creation, private structured logging with redaction, Twig with safe rendering defaults, a checksum migration runner with an advisory overlap lock, request IDs, a fail-closed Slim entry point, and runnable migration/preflight commands. On 2026-10-23 the foundation was installed and verified on a disposable Windows host: Composer 2.10.3 resolved and locked 17 packages; preflight returned 0; migrations 001/002/003/006/007/008 applied and repeat runs were idempotent; and foundation, migration-locking, bootstrap, and unit suites passed. The application is still not deployable as a secured service until production cPanel, real IMAP/SMTP providers, cleanup, and backup/restore checks pass.

The DeepSeek authentication result was deferred because Milestone 2 depends on this foundation and its proposed test suite included a known non-running placeholder. No distinct result 3 was present in the supplied file. Result 4 described an incomplete acceptance harness with a placeholder SMTP fixture and was not added as evidence.

## Interfaces and changed behavior

- `ConfigLoader::fromEnvironment()` validates all startup settings and fails before the web application or commands run.
- `PdoFactory::create()` enables exceptions, native prepared statements, strict SQL mode, and UTC sessions.
- `MigrationRunner::up()` applies ordered nonempty PHP migrations, records SHA-256 checksums after success, rejects edits/missing applied files, and serializes runners with `GET_LOCK`. It never runs from an HTTP route.
- `php bin/console.php migrate status|up` and `php bin/console.php preflight` are real CLI entry points. Exit 64 is command misuse, 78 is invalid configuration, 3 is preflight database failure, and 1 is another runtime failure.
- `public/index.php` boots Slim and returns a generic 503 if startup fails. The registered surface is the six Milestone 2 panel routes, eight authenticated sending-account routes, and `POST /api/v1/mail/send` behind `ApiKeyMiddleware`; unmatched requests are 404.
- Internal request IDs are random and are returned as `X-Request-ID`; inbound values are not trusted.
- `DirectoryProbe` proves private-directory writability with a real create/write/delete probe because `is_writable()` can report false on Windows with a narrow deny ACE; preflight and the logger use it.

## Configuration

Required process environment names are `APP_ENV`, `MAILPANEL_DB_DSN`, `MAILPANEL_DB_USER`, `MAILPANEL_DB_PASSWORD`, `MAILPANEL_CREDENTIAL_KEYSET`, `MAILPANEL_CREDENTIAL_ACTIVE_KEY_ID`, `MAILPANEL_CONTENT_KEYSET`, `MAILPANEL_CONTENT_ACTIVE_KEY_ID`, `MAILPANEL_API_FINGERPRINT_KEY`, and `MAILPANEL_MESSAGE_ID_DOMAIN`. Optional names are `APP_DEBUG`, `PRIVATE_STORAGE_PATH`, `LOG_PATH`, `MAILPANEL_TLS_CA_BUNDLE`, `MAILPANEL_API_MAXIMUM_BODY_BYTES`, `MAILPANEL_API_REQUESTS_PER_MINUTE`, `MAILPANEL_API_RECIPIENTS_PER_HOUR`, and `MAILPANEL_IDEMPOTENCY_RETENTION_SECONDS`.

Keysets are JSON objects whose values are base64-encoded 32-byte values. The loader deliberately preserves those encoded values because the existing cipher services decode them. The fingerprint key is separate base64 material of at least 32 bytes and is decoded by the loader. Do not store values in the repository, under `public/`, in logs, or in this handover.

## Install, run, and migration state

Dependencies are installed and locked. `composer.lock` was regenerated with Composer 2.10.3, validated, and installed; `composer install --dry-run` reported nothing to install. Production installation must use the reviewed committed lock with `composer install --no-dev --classmap-authoritative`. Set the document root to `public/` only.

```text
php bin/console.php preflight
php bin/console.php migrate status
php bin/console.php migrate up
php bin/console.php migrate status
```

Migration state on the disposable local application schema: 001/002/003/006/007/008 APPLIED; 004/005 empty and skipped; a second `up` reported no pending migrations. Empty reserved migrations are skipped; once populated they become pending. MySQL/MariaDB DDL may auto-commit, so a failed migration is not automatically rolled back and must never be recorded as applied; inspect and repair it before rerunning.

## Tests and evidence

Commands added:

```text
php tests/Foundation/run-foundation.php
php tests/Integration/run-migrations.php
php tests/Integration/run-bootstrap.php
```

The foundation runner checks encoded cipher key compatibility, decoded fingerprint keys, inherited queue/API defaults, redaction, missing configuration, production debug rejection, short keys, public storage rejection, and the writable-directory probe. The migration runner requires a disposable schema plus `MAILPANEL_TEST_DB_DSN`, `MAILPANEL_TEST_DB_USER`, `MAILPANEL_TEST_DB_PASSWORD`, and `MAILPANEL_TEST_ALLOW_RESET=1`; it destructively resets only its probe tables and the migration ledger, then checks overlap locking, apply-once behavior, checksums, and failed-ledger behavior. The bootstrap runner verifies configuration, PDO, private paths, JSON logging/redaction, compilation of all 20 Twig templates, the 15-route table, security headers/request IDs, anonymous redirect, and login rendering.

Local results on 2026-10-23: foundation 26 assertions; migration locking 8 assertions; bootstrap 37 assertions (15 routes, 20 templates); PHP lint 143 files with 0 failures. A fresh task-01 schema reproduced pending → applied → no-pending migrations. PHP 8.4.27, Composer 2.10.3, and MariaDB 11.4.5 were provisioned under a disposable `.tools/` toolchain that is gitignored. The earlier `php-parser` static result is superseded by these runtime checks but remains historical context in prior handovers.

Still unverified: cPanel document-root/proxy behavior, real cron timing, real IMAP/SMTP providers, concurrency races outside the migration lock, bounded cleanup, and backup/restore.

## Security, recovery, and decisions

Logs are JSON under the private log path and redact sensitive keys, bearer values, bodies, and content fields. Startup and HTTP failures do not expose configuration or database errors. No health route was added because the external surface is intentionally narrow. Migrations are CLI-only. Migrations were applied only to the disposable local schema; no production migration or private production data was touched.

Back up the database, private files, and encryption-key material together but store key material separately from database backups. A changed applied migration is an operator error: restore the original file or add a new migration; never update the ledger checksum to conceal it.

## Integration requests and exact next step

Milestone 2 and the sending-account panel are locally verified. Keep the API and outbound workers disabled, complete Milestone 0's remaining cPanel/IMAP feasibility checks, and repeat HTTPS/database checks through the real hosting environment. Implement bounded session/throttle cleanup with Milestone 12. Do not expose any read/status/contact/task/OTP API.

**Read and update handover.md before handing over, and require the next contributor to do the same.**
