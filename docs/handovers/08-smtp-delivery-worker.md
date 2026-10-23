# Assignment 08 — SMTP delivery worker handover

> Historical note (2026-10-23): the shared foundation, dependencies, migrations, and local runtime checks were subsequently completed. See root `handover.md` and `docs/testing.md` for current evidence; the runtime blockers below describe the state when this module was written.

Implemented PHPMailer 7 transport configuration with verified TLS, stable Message-ID and explicit timeouts; numeric RCPT/DATA instrumentation; safe accepted/partial/transient/permanent/uncertain classification; a bounded `SendWorker`; 1/2/4-minute retry policy with four-attempt ceiling; per-attempt recipient outcomes; account pause policy for repeated authentication failures; and abandoned-lease recovery after accepted-but-unpersisted SMTP.

No migration was added. Existing unapplied migrations 002/003/007 are required. `composer.json` now declares PHPMailer and PSR contracts, but no lock/vendor tree exists. `bootstrap/worker.php` remains an integration-owner task because the foundation/configuration container is absent.

Checks: pinned `php-parser` syntax parsing passed during implementation. PHP unit/database/PHPMailer checks, controlled TLS SMTP fixtures, cPanel, and second-provider evidence were not run because PHP, Composer, database, containers, and WSL are unavailable here. `tests/Unit/run-smtp-delivery.php` is a controlled test double, not provider evidence.

## Gate C follow-up (2026-10-23)

`bootstrap/worker.php` now registers `smtp.send`; migrations 002/003/007 are applied to the disposable schema. `tests/Integration/run-smtp-worker.php` drives the real worker and transport against `tests/Fixtures/smtp/worker-server.js` for accepted, RCPT 550/451, partial, DATA 550/451, final-DATA disconnect, interrupted lease, persistence failure after acceptance, and authentication-failure pausing (34 assertions). Fixes: configured CA bundle is passed to `PhpMailerSmtpTransport`; microsecond job timestamps no longer lose precision through `gmdate(..., 'u')`; permanent RCPT rejection maps to `permanent`. Real-provider/cPanel evidence remains.

Exact next step: proceed to Gate D (sole send API) with real HTTP, OpenAPI, and two-connection idempotency/rate-limit tests. Keep the API and outbound worker disabled at the proxy until that evidence passes.

**Read and update handover.md before handing over, and require the next contributor to do the same.**
