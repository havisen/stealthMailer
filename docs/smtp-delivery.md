<!-- PROJECT HANDOVER RULE:
Every contributor must read handover.md before starting work and update it before handing work over.
Never record secrets or private message contents. Repeat this requirement for the next contributor.
-->

# SMTP delivery worker

## Purpose and status

The SMTP delivery module turns an already durable `smtp.send` job into one bounded SMTP attempt without unsafe duplicate retries. It provides `SmtpTransport`, PHPMailer 7 integration, command instrumentation, `SendWorker`, and controlled transport test doubles. It does not implement the API, UI, IMAP, Sent-folder APPEND, contact creation, or attachment storage.

The code is installed and locally verified. Composer 2.10.3 installed `composer.lock`; migrations 002/003/007 were applied to the disposable local schema. `tests/Integration/run-smtp-connectivity.php` exercised PHPMailer implicit TLS (465), STARTTLS (587), wrong-password authentication, untrusted-certificate rejection, and one accepted DATA delivery. `tests/Integration/run-smtp-worker.php` now drives the real `SendWorker` and `PhpMailerSmtpTransport` against a controlled TLS fixture for accepted DATA, RCPT 550/451, mixed partial acceptance, DATA 550/451, final-DATA disconnect, interrupted lease, persistence failure after acceptance, and three-authentication-failure account pausing. The transport now receives the configured `MAILPANEL_TLS_CA_BUNDLE`. Real-provider TLS/limits and cPanel SMTP remain unverified.

## Delivery workflow

`SendWorker` checks the account before claiming content. Missing, disabled, or paused accounts are not contacted. `SubmissionService::claimForSending()` checks the attempt-start deadline, decrypts the immutable snapshot, and atomically changes `queued -> sending`. The worker uses the stable stored Message-ID, configured From/Reply-To, To/Cc headers, and Bcc envelope recipients. Bcc is passed with PHPMailer's SMTP Bcc mechanism and is not exposed by application responses.

`InstrumentedSmtp` captures only numeric/safe command facts: every RCPT acceptance and the final DATA state. It never retains SMTP transcripts or provider text. It keeps the last low-level connection warning in memory solely to classify TLS/connect failures and never renders or logs it. A successful DATA reply makes accepted RCPT recipients final. Mixed RCPT acceptance becomes terminal `partially_accepted`; the whole submission is never retried. A conclusive 4xx before acceptance may retry; 5xx/configuration/authentication/TLS failures are terminal. Disconnect, timeout, or exception while final DATA may have succeeded becomes `uncertain` and never retries.

If SMTP returns acceptance but database persistence fails, `SendWorker` abandons the job lease instead of acknowledging it. Expired-lease recovery later atomically changes the still-`sending` submission to `uncertain`. This is conservative: SMTP cannot provide exactly-once delivery.

## Retries and account pausing

Retry delays after definite temporary non-acceptance are one, two, and four minutes, with four total attempts and deadline checks before scheduling. Every attempt records per-recipient outcomes. Accepted or unknown recipients are never automatically retried. Authentication failures are safe-category failures; when `AccountService` is integrated with `JobRepository`, the third authentication failure in 15 minutes pauses the account. Pausing does not cancel previously accepted/terminal mail.

For authentication email, callers should send one recipient per API request. Mixed recipients can produce partial acceptance that cannot be safely repeated as a whole.

## TLS, limits, and privacy

`PhpMailerSmtpTransport` requires SMTP authentication and either implicit TLS/465 or STARTTLS/587 from the validated account. Peer and hostname verification are enabled and self-signed certificates are rejected. Debug output is discarded. Connection/operation timeouts are clamped to the worker's remaining budget. Account recipient/size limits are enforced before enqueue by the submission module; provider limits still apply.

Never log the transport message, credentials, bodies, Authorization headers, SMTP transcript, or raw exceptions. Safe visible categories are used instead. Backups and restores follow `docs/submissions.md`; restore with outbound disabled and reconcile `sending`, `uncertain`, and leased work before cron resumes.

## Installation and integration

Requirements: PHP 8.4 CLI, PDO MySQL, Sodium, OpenSSL, JSON, Composer, PHPMailer 7.1, applied migrations 002/003/007, verified CA trust, and provider connectivity. Install dependencies with:

```sh
composer install --no-dev --classmap-authoritative
```

The dependency container builds `AccountService` with `JobRepository` and `SubmissionService`, and instantiates `PhpMailerSmtpTransport` with the configured CA bundle. `bootstrap/worker.php` registers `SendWorker` as `smtp.send`. Do not register a generic retry handler.

Run checks:

```sh
php tests/Unit/run-unit.php
php tests/Unit/run-jobs.php
php tests/Unit/run-submissions.php
php tests/Unit/run-smtp-delivery.php
php tests/Integration/run-jobs-and-submissions.php
php tests/Integration/run-smtp-worker.php
```

The controlled worker fixture is `tests/Fixtures/smtp/worker-server.js`. Start it with the disposable database/SMTP environment and a trusted test certificate, then run the worker test:

```sh
MAILPANEL_SMTP_WORKER_BASE_PORT=4465 \
MAILPANEL_SMTP_TEST_SERVER_KEY=/path/to/test.key \
MAILPANEL_SMTP_TEST_SERVER_CERT=/path/to/test.crt \
MAILPANEL_SMTP_TEST_USER=... \
MAILPANEL_SMTP_TEST_PASSWORD=... \
node tests/Fixtures/smtp/worker-server.js

MAILPANEL_SMTP_WORKER_BASE_PORT=4465 \
MAILPANEL_SMTP_TEST_CA_FILE=/path/to/test-ca.crt \
php tests/Integration/run-smtp-worker.php
```

The fixture uses ports 4465–4471, one deterministic scenario per port. The worker test skips when the server, CA, credentials, or disposable database are unavailable. `run-smtp-delivery.php` remains the dependency-free transport-double check for CI environments without a controlled server.

## Troubleshooting and exact next step

- `account_disabled`: review account pause reason and credentials; explicit operator action is required.
- `authentication`: verify credentials without logging them; three recent failures pause the account when fully integrated.
- `tls`: fix endpoint/certificate trust; never bypass verification.
- `uncertain_outcome`: reconcile with provider logs or recipient evidence; never click/requeue broadly.
- stale leased send: allow registered expired recovery to mark it uncertain.

Local implementation notes: job/lease scheduling now uses microsecond UTC timestamps consistently (`gmdate(..., 'u')` was returning `.000000` and could make an immediately due job appear not yet scheduled); a permanent RCPT rejection now records the safe `permanent` category instead of `network`; and the transport uses the configured CA bundle rather than relying on the process default.

Exact next step: keep the worker paused at the proxy, add real-provider/cPanel send evidence under the release milestone, and proceed to Gate D (the sole send API) with real HTTP, OpenAPI, and two-connection idempotency tests.

**Read and update handover.md before handing over, and require the next contributor to do the same.**
