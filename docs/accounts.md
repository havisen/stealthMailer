<!-- PROJECT HANDOVER RULE:
Every contributor must read handover.md before starting work and update it
before handing work over. Record changes, documentation, configuration names,
migrations, tests and results, known issues, decisions, and the exact next step.
Never record passwords, API keys, encryption keys, authentication codes, or
private message contents. Every handover must repeat this requirement for the
next contributor, who must follow the same rule.
-->

# SMTP Sending Accounts

Status: the SMTP account core and authenticated panel routes are implemented and locally verified on 2026-10-23. A stored Proton SMTP account authenticated successfully without sending DATA. IMAP/POP3 authentication probes exist, but synchronization is not implemented and the real Private Email login awaits the mailbox password.

## Scope

An SMTP sending account contains an operator-approved SMTP endpoint, credentials, sender identity, enabled state, and provider limits. It does not require IMAP. Receiving settings are not part of the `mail_accounts` schema yet; a later additive migration and panel module must add them. Do not duplicate the account table for IMAP.

## Configuration

- `config/mail.php` contains the operator-approved SMTP endpoint list. It currently approves `smtp.protonmail.ch:587` (STARTTLS) and `mail.privateemail.com:465`/`:587` (implicit TLS/STARTTLS). The `MAILPANEL_APPROVED_SMTP_ENDPOINTS` JSON environment variable overrides this list when set.
- `config/mail.local.php` is gitignored via `/config/*.local.php`. It holds the test-only Proton sender password/token and the Private Email IMAP/POP3 endpoints. Never commit it, copy it under `public/`, or include it in logs or documentation.
- The final application accepts account values through the authenticated form; the local test file is only for disposable verification and import.

## Credential storage

`App\Security\CredentialCipher` stores credentials in this format:

```text
v1.<key-id>.<base64url-nonce>.<base64url-ciphertext>
```

It uses Sodium secretbox with a random nonce. When a context is supplied, keyed BLAKE2b derives a context-specific secretbox key from the master key. SMTP passwords use `acct:<public-id>:password`, preventing a ciphertext copied from one account from authenticating in another account context.

Master keys are base64-encoded 32-byte values. They must be provisioned outside the database and web document root, backed up separately, and never logged. New encryption uses `security.active_key_id`; old values remain decryptable while their key IDs remain in `security.credential_keys`.

The repository never includes `password_enc` in normal panel reads. A blank password during edit preserves the existing ciphertext. Only worker and connection-test code may request and decrypt the credential.

## Endpoint policy

`App\Security\EndpointPolicy` requires an exact operator-approved hostname and permits only:

- port 465 with implicit TLS;
- port 587 with STARTTLS.

DNS results are checked before connection. Loopback, private, link-local, carrier-grade NAT, multicast, reserved, documentation, and common metadata ranges are rejected. A single approved endpoint can set `allow_private` only for a known private cPanel host. TLS certificate verification must remain enabled.

## Account lifecycle and routes

`AccountService` validates and normalizes input, allocates an immutable `acct_` public ID, encrypts the password, and delegates persistence to prepared statements in `AccountRepository`.

Authenticated routes are `GET /accounts`, `GET /accounts/create`, `POST /accounts`, `GET /accounts/{id}/edit`, `POST /accounts/{id}/edit`, `POST /accounts/{id}/test`, `POST /accounts/{id}/disable`, and `POST /accounts/{id}/delete`. Every mutation requires administrator authentication plus action-specific CSRF. The test action decrypts the stored password, connects and authenticates, and never sends DATA.

Disabling an account prevents new work and records a visible pause reason. Later queue integration must pause existing queued submissions until re-enabled or expired; it must not silently change sender identity or resend messages. Deletion requires explicit confirmation and later submission-integrity rules.

## Connection checks

`SmtpConnectionTester` performs no network work itself. Its `SmtpProbe` adapter contract is for connect, TLS, authentication, and optional sender authorization checks without sending message DATA. Results are normalized to fixed safe categories: `dns`, `connectivity`, `tls`, `auth`, `sender_auth`, `quota`, or `protocol`. Provider responses and credentials must not be shown or logged.

The PHPMailer-backed `PhpMailerSmtpProbe` verifies TLS and authentication and never issues MAIL FROM, RCPT TO, or DATA. Its connection warning is retained in memory only for failure classification.

## Local test commands

```text
php bin/console.php mail:import
php bin/console.php mail:test-account --id=acct_...
php bin/console.php mail:test-smtp
php bin/console.php mail:test-imap --password-stdin
php bin/console.php mail:test-pop3 --password-stdin
```

`mail:import` creates or updates a database account from `config/mail.local.php` with encrypted credentials. `mail:test-account` tests the stored account. `mail:test-smtp` reads the local test account. IMAP/POP3 commands prompt for the mailbox password; on Windows the fallback prompt warns that input is visible, so use `--password-stdin` there. No command sends a message, and none may print a password or token.

## Verification and limitations

On 2026-10-23 the authenticated account lifecycle (create, edit with blank password, disable, delete) and CSRF rejection passed in the Slim HTTP runner (35 assertions); the list/form passed in the real-HTTPS runner (59 assertions). The stored Proton account authenticated successfully through `mail:test-account`; no message DATA was sent. `tests/Unit/run-unit.php` passed 43 checks, including mailbox input validation. IMAP/POP3 login framing and correct/wrong-password classification were additionally exercised against a disposable local TLS mock.

IMAP/POP3 authentication against `mail.privateemail.com` still needs the mailbox password. Those commands authenticate only; inbox synchronization, folder discovery, message reading, and attachments remain later milestones. Production cPanel/provider behavior remains unverified. Do not deploy this repository as a secured service yet.

**Read and update handover.md before handing over, and require the next contributor to do the same.**
