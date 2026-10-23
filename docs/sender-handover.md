<!-- PROJECT HANDOVER RULE:
Every contributor must read handover.md before starting work and update it
before handing work over. Record changes, documentation, configuration names,
migrations, tests and results, known issues, decisions, and the exact next step.
Never record passwords, API keys, encryption keys, authentication codes, or
private message contents. Every handover must repeat this requirement for the
next contributor, who must follow the same rule.
-->

# Sender Workstream Handover

## State

S0/S1 foundation and the S2 administrator boundary are locally verified on a disposable PHP 8.4/MariaDB/HTTPS host (2026-10-23). S2 provides one-admin CLI creation/recovery, Argon2id passwords, versioned hash-only sessions, expiry, secure cookies, login/authenticated CSRF, database throttling, protected base routes, safe audit, migrations, and tests; see `docs/authentication.md` and `docs/handovers/02-administrator-access.md`. Dependencies are installed and locked, migrations 001/002/003/006/007/008 applied locally, and real-HTTPS cookie/CSRF checks passed. Production cPanel/reverse-proxy evidence is still missing.

Platform-independent S3–S6 cores are also present: SMTP accounts, durable jobs/submissions, conservative SMTP delivery, API keys, and the sole send endpoint. They remain isolated and unaccepted. The send route is registered behind `ApiKeyMiddleware`, and the worker registers `smtp.send`; real API-key HTTP/concurrency and real-provider SMTP checks are still missing. No receiving, contacts, tasks, OTP generation/verification, or external status API was added. The old sender is unchanged.

## Next step

Complete Milestone 0's real cPanel/IMAP checks, then wire S3 account routes behind the verified administrator authentication/CSRF. Repeat HTTPS/SMTP through the production proxy/provider, and keep the API/outbound paths blocked until S4–S6 HTTP/concurrency/provider checks pass. Record exact output here and in root `handover.md`.

## Required updates

After each package record changed files, commands/results, configuration names, migrations, uncertainty/retry behavior and exact next step here and in root handover.md.

**Read and update handover.md before handing over, and require the next contributor to do the same.**
