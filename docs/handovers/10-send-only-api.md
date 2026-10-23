<!-- PROJECT HANDOVER RULE: Read and update handover.md before handoff; never record secrets or private content; require the next contributor to do the same. -->

# Assignment 10 handover

Implemented the isolated send-only API contract: strict JSON/recipient/IDNA/deadline validation, 256 KiB limit, account authorization before replay, HMAC-protected canonical idempotency, independent request abuse counting, atomic account quota/submission/job/replay persistence, original 202 replay, safe errors, Retry-After, controller, OpenAPI, examples, and unit/disposable-database scripts. No API attachments or other endpoints were added.

Changed files include `config/api.php`; `composer.json`; API DTO/error/validator classes; `ApiAccessRepository`; `ApiSendService`; `SendMailController`; structured-recipient support in submission contracts; `tests/Unit/run-send-api.php`; `tests/Integration/run-send-api.php`; OpenAPI/examples and related architecture/configuration/database/operations/security/submission/testing docs.

Run `composer install`, apply migrations 002/003/007/008 through the migration runner, `php tests/Unit/run-send-api.php`, all neighboring unit runners, and `php tests/Integration/run-send-api.php` with the disposable DB variables documented in `docs/testing.md`. On 2026-10-23 the unit runner and the disposable-database runner both passed (6 database checks, 0 failures), and migrations 002/003/007/008 applied locally. No real HTTP server, concurrent two-connection race, OpenAPI validator, controlled real-provider SMTP, or cPanel test has run.

Migrations 002/003/007/008 are applied only to the disposable local schema. Configuration names are `security.api_fingerprint_key`, `api.maximum_body_bytes`, `api.requests_per_minute_per_key`, `api.accepted_recipients_per_hour_per_account`, and `api.idempotency_retention_seconds`; the fingerprint secret must remain outside the database, `public/`, logs, and version control.

Integration status changed during foundation verification: `routes/api.php` now registers exactly `POST /api/v1/mail/send` behind `ApiKeyMiddleware`, and `bootstrap/worker.php` registers the `smtp.send` handler. An unauthenticated route dispatch returns 401, but the route has not been exercised with a real API key over HTTP. CORS must remain disabled. No 202 may be returned before commit. `00-SHARED-CONTRACT.md` is still absent.

Exact next step: keep outbound/API disabled at the proxy until real HTTP response tests compare every `docs/openapi.yaml` response, two simultaneous database connections exercise same/different idempotency payloads and rate-limit boundaries, and a controlled verified-TLS provider test passes.

**Read and update handover.md before handing over, and require the next contributor to do the same.**
