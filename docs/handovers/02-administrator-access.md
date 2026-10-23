<!-- PROJECT HANDOVER RULE:
Every contributor must read handover.md before starting work and update it
before handing work over. Record changes, documentation, configuration names,
migrations, tests and results, known issues, decisions, and the exact next step.
Never record passwords, API keys, encryption keys, authentication codes, or
private message contents. Every handover must repeat this requirement for the
next contributor, who must follow the same rule.
-->

# Milestone 2 handover — administrator access

## Delivered

Implemented a single-administrator authentication boundary: Argon2id password creation/reset, generic login failures, database-backed email/IP throttling, fresh hash-only database sessions, idle/absolute expiry, security-version revocation, login and authenticated CSRF, password change, logout, protected Slim routes, private cache/security headers, safe audit events, CLI management, and focused unit/database/HTTP tests.

The routes are `GET|POST /login`, protected `GET /`, protected `GET|POST /settings/password`, and protected+CSRF `POST /logout`. The module deliberately does not expose API auth, signup, email recovery, roles, mail/account pages, or other roadmap features. The old sender was untouched.

## Files and interfaces

Core interfaces are `AuthService`, `AdministratorRepository`, `AdministratorSessionRepository`, `LoginThrottleRepository`, `AuditRepository`, `PasswordPolicy`, `PasswordHasher`, `CsrfTokenManager`, `SecureCookieManager`, `AuthMiddleware`, `CsrfMiddleware`, `SecurityHeadersMiddleware`, and `AuthController`. DTOs are under `src/Auth/`; request IP/user-agent/request-ID extraction is in `RequestMetadata` and does not trust forwarded headers.

Migration 001 creates `administrators`, `administrator_sessions`, and `administrator_login_throttles`; migration 006 creates `audit_events`. Both were applied unchanged to the disposable local application schema on 2026-10-23, and the disposable test runners re-apply them to their own schema. Templates implement login, dashboard, and password change. `bootstrap/dependencies.php`, `bootstrap/web.php`, and `routes/web.php` register only this milestone's panel behavior; the separately implemented send API route is also present behind `ApiKeyMiddleware`.

## Configuration

New required secrets: `MAILPANEL_AUTH_CSRF_KEY` and `MAILPANEL_AUTH_THROTTLE_KEY`, each base64 for at least 32 random bytes. Optional validated names/defaults are documented in `docs/configuration.md`: session idle/absolute durations, both cookie names, CSRF TTL, and throttle limit/window/block duration. Defaults are 30-minute idle, 12-hour absolute, 15-minute CSRF, five failures in 15 minutes, and 15-minute blocking.

## Verification and limitations

Added `tests/Unit/run-auth.php`, `tests/Integration/run-auth.php`, `tests/Integration/run-auth-http.php`, and `tests/Integration/run-auth-https.php`; exact commands are in `docs/authentication.md` and `docs/testing.md`. On 2026-10-23 the unit, disposable-database, Slim HTTP, and real-HTTPS checks passed with 16, 28, 16, and 52 assertions respectively. The bootstrap runner (28 assertions) also verified PDO, logging, Twig, and routes. CLI recovery was exercised with the real command: exit 0, security version 5 to 6, `administrator_password_reset` audit event, sessions revoked, and a subsequent HTTPS login succeeded.

Runtime verification found and fixed three defects: repeated named PDO placeholders in `AdministratorRepository` and `LoginThrottleRepository` (native prepares reject duplicates), a test-scope collision where `bootstrap/web.php` overwrote the HTTP runner's CSRF helper, and a false-negative directory check caused by Windows ACL behavior (`DirectoryProbe` now performs a real write probe; the same probe is used by the logger and preflight). The authentication module itself needed no schema changes.

Still unverified: production cPanel/reverse-proxy behavior, forwarded-header policy, throttle races at concurrency boundaries, bounded session/throttle cleanup (deferred to Milestone 12), backup/restore, and a real browser/accessibility pass. Do not deploy this repository as a secured service yet.

## Exact next step

Milestone 2 is locally verified. Keep the API and outbound workers disabled, complete Milestone 0's remaining cPanel/IMAP feasibility checks, and integrate Milestone 3 account routes behind this authentication and action-specific CSRF. Schedule bounded session/throttle cleanup with Milestone 12. Before any production exposure, repeat the HTTPS checks through the real cPanel reverse proxy and confirm the actual `REMOTE_ADDR` boundary.

**Read and update handover.md before handing over, and require the next contributor to do the same.**
