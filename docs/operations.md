<!-- PROJECT HANDOVER RULE:
Every contributor must read handover.md before starting work and update it
before handing work over. Record changes, documentation, configuration names,
migrations, tests and results, known issues, decisions, and the exact next step.
Never record passwords, API keys, encryption keys, authentication codes, or
private message contents. Every handover must repeat this requirement for the
next contributor, who must follow the same rule.
-->

# Operations Introduction

This scaffold is not deployable as a working mail service. Complete hosting feasibility and installation before exposing it publicly.

## Hosting preflight

Confirm web and CLI PHP 8.4, compatible Composer dependencies, PDO MySQL, Sodium, OpenSSL, mbstring, DOM/XML, fileinfo, and intl. Confirm private writable storage, cPanel database names and grants, one-minute cron, certificate trust, and outbound IMAP 993 and SMTP 465/587. Test SMTP separately from IMAP.

## Deployment requirements

Dedicated subdomain, database credentials, session cookie, API keys, and encryption key. Set the document root to public/ only. Keep all private storage and configuration outside it. Use HTTPS, production-safe errors, explicit migrations, absolute cron paths, and pinned dependencies. Do not reuse the old panel's secret values.

The foundation now provides `php bin/console.php preflight`, `php bin/console.php migrate status`, and `php bin/console.php migrate up`. Preflight checks PHP 8.4, required extensions, Argon2id availability, private writable paths, OpenSSL trust, and a database query; exit 3 identifies a database check failure, 78 invalid configuration, and 1 another failed prerequisite. The migration command returns 64 for misuse and 2 when status finds a changed/missing applied file. Run these with the same PHP binary and environment as cron. No web migration endpoint exists. On the disposable local host on 2026-10-23, preflight returned 0 with all checks OK and migrations applied 001/002/003/006/007/008; 004/005 were empty and skipped. The private-directory check uses a real create/write/delete probe because `is_writable()` can be wrong on Windows ACLs.

Dependencies are locked in `composer.lock` (17 packages, validated by Composer 2.10.3). Production must use `composer install --no-dev --classmap-authoritative` from that reviewed lock, not an update. The web route table contains the six Milestone 2 routes plus `POST /api/v1/mail/send` behind `ApiKeyMiddleware`; the worker registers `smtp.send`. The API and outbound paths have unit/database evidence but no real HTTP/provider acceptance, so keep them disabled at the deployment/proxy layer until Milestones 4 and 5 are accepted.

## Administrator provisioning and recovery

After migrations 001 and 006 are applied, provision the only administrator with `php bin/console.php admin:create --email=administrator@example.test`. The command prompts twice without echo; a password is never accepted in argv. For a controlled noninteractive secret pipe, send two identical lines to `php bin/console.php admin:create --email=administrator@example.test --password-stdin`. Use the same pattern with `admin:reset-password` for recovery. The reset increments security version and revokes every session.

Authentication startup also requires separate base64 secrets `MAILPANEL_AUTH_CSRF_KEY` and `MAILPANEL_AUTH_THROTTLE_KEY`; names/defaults are in `docs/configuration.md`. Serve only through HTTPS because cookies are unconditionally Secure. Confirm the real reverse proxy preserves HTTPS and the configured cookie names, but do not trust forwarded IP headers for throttle identity. Monitor 401/403/429 results and safe audit event volume without logging credentials, cookies, form bodies, or raw IP addresses.

Expired/revoked session and throttle cleanup is not implemented. Do not improvise broad deletion against production. Before exposing a restored database, keep traffic disabled, run CLI password recovery to revoke restored sessions, and verify a fresh login. See `docs/authentication.md` for exact tests and failure behavior. The bounded cleanup work is scheduled under Milestone 12; its required shape is described in “Deferred bounded cleanup” below.

## Worker and recovery requirements

Use database jobs with leases, bounded execution around 40 seconds, per-account locks, heartbeat monitoring, and resumable batches. Prioritize outbound delivery independently of IMAP backfill. Alert visibly if the worker heartbeat is older than five minutes. The bounded worker command was exercised locally on 2026-10-23 (`--queue=maintenance --budget=1` and `--queue=outbound --budget=1`, both exit 0, `budget_exhausted`, `worker_runs` rows written). Actual cPanel cron scheduling, delayed cron, and overlap under load remain unverified.

Back up database, private files, configuration, and encryption key securely. Restore with outbound sending disabled and accounts paused. Review in-flight and backup-gap sends before resuming; never blindly resend historical authentication emails. Test restoration on a separate installation.

## Starting retention

Logs: 30 days. Audit/submission metadata: 90 days. Temporary uploads: 24 hours. API content: encrypted while queued and purged within 24 hours after terminal state. Document provider-side retention separately. Do not auto-delete remote email during cleanup.

Add exact installation, upgrade, rotation, cleanup, backup, restore, and rollback commands as their features are implemented.

## Deferred bounded cleanup (Milestone 12)

Session and throttle cleanup is not implemented. Do not run ad-hoc broad `DELETE` statements. When it is scheduled as a bounded maintenance job, it must:

- Delete only expired or long-revoked sessions and expired throttle rows, using explicit UTC cutoffs and indexed time columns.
- Never delete an active session, an active throttle block, audit events inside the 90-day policy, active jobs/submissions, private content needed for recovery, or remote mail.
- Work in small batches (for example, 500 rows per table per invocation) inside the worker run budget and lease, with checkpoints between batches.
- Report counts and safe categories only; never log row identifiers, IP values, tokens, hashes, or message content.
- Have tests for the retention boundary, repeated runs, partial failure, and concurrent workers before it is enabled.

## Implemented worker command

After applying migration 007, run the bounded CLI with an absolute PHP 8.4 path. Separate one-minute cron entries should invoke `php bin/worker.php --queue=outbound --budget=40`, `--queue=sync`, and `--queue=maintenance`; use absolute installation paths as shown in `docs/jobs.md`. Overlap is supported by row/account leases. Exit 2 means the database was unavailable and no work was intentionally processed. Keep outbound disabled after restore until leased sends are reconciled. The migration runner and `smtp.send` handler are registered; the bounded CLI was exercised locally on 2026-10-23, but actual cPanel cron scheduling remains unverified.

Submission recovery must distinguish `queued` from `sending`. An expired leased job for a `sending` submission becomes `uncertain` and requires operator/provider reconciliation; never update it back to queued manually. A deadline is checked only before an attempt begins. Content-key loss prevents decryption and must produce a visible failure without starting SMTP. Purge terminal private content only through the future bounded maintenance handler and only after backup/retention policy is confirmed.

SMTP operations additionally require Composer installation, PHPMailer 7.1, OpenSSL CA trust, and registered `smtp.send` handler wiring. Both are present; the local SMTP verification exercised implicit TLS, STARTTLS, authentication failure, untrusted-certificate rejection, and one accepted DATA delivery through a disposable server. A real provider/cPanel SMTP test is still required. Repeated authentication failures pause an account at three failures in 15 minutes when `AccountService` receives `JobRepository`. A final-DATA disconnect, stale in-flight lease, or database failure after acceptance is `uncertain`; inspect provider logs and never broadly requeue it.

The send route is registered at `POST /api/v1/mail/send` behind `ApiKeyMiddleware`, and migration 008 is applied locally. Real HTTP response validation against `docs/openapi.yaml`, two-connection idempotency/rate races, replay-key cleanup, and provider acceptance remain required before enabling it. Monitor 429/503 rates and idempotency/rate-table cleanup; never log Authorization, idempotency keys, request bodies, or replay responses. Retain replay mappings seven days, including after encrypted terminal content is purged. On restore keep the API and outbound workers disabled, review key revocations and queued/in-flight work, and rotate credentials if backup exposure is suspected.

## SMTP account operational notes

- Back up credential master keys separately from, but together with, the database recovery set. Losing all historical keys makes stored SMTP passwords unrecoverable.
- To rotate keys, add a new key ID and set it active. Keep old keys available until every stored value using them is re-encrypted or removed.
- Disabling an account must stop new submissions and leave queued work visibly paused until later queue rules resolve it.
- A connection test may connect and authenticate but must not send message DATA. Never disable certificate verification to make a test pass.
- Migration 002 was applied on the disposable local schema, but every real provider behavior and migration on production remain unverified. Do not deploy this scaffold.
