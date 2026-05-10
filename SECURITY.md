# Security Policy

SerenitySpaces handles sensitive therapeutic data. Security findings are taken seriously and acted on quickly.

## Reporting a Vulnerability

**Do not open a public GitHub issue for security findings.**

Send a private report to: **security@serenityspaces.app**

Include:
- A description of the vulnerability and its impact.
- Steps to reproduce, ideally with a proof-of-concept.
- The version / commit hash you tested against.
- Whether the issue is already public anywhere (responsibly or not).

If you prefer, you can request a PGP key by emailing the address above with the subject line `PGP key request`.

## What to Expect

- **Acknowledgement** within 72 hours of your initial report.
- **Initial assessment** within 7 days, including severity rating and a rough timeline.
- **Patch released** within 30 days for critical / high severity. Lower severity follows the next regular release cycle.
- **Public disclosure** coordinated with you. Default is 90 days from initial report or until a patch is shipped, whichever is later.

We will credit you in the release notes unless you prefer otherwise.

## Supported Versions

Only the most recent tagged release receives security patches. Older versions are end-of-life on release day. Self-hosted operators are responsible for staying current.

## Out of Scope

The following are not vulnerabilities for this project:

- Missing security headers in non-production mode (intentional — see `includes/security_headers.php`).
- Self-XSS that requires the victim to paste attacker-controlled content into the practitioner's own admin panel.
- Reports that require physical access to a logged-in practitioner's machine.
- Best-practice findings without a concrete exploit (e.g. "TLS 1.2 should be 1.3" without showing the impact).
- Findings against the demo environment that don't reproduce on a fresh self-hosted install.

## Operator Responsibility

SerenitySpaces is self-hosted software. Operators are responsible for:

- Keeping their PHP / MySQL / web server patched.
- Configuring TLS correctly and enabling **production mode** in admin settings before going live.
- Storing `db/config.php` outside any public path and ensuring `assets/uploads/` is not directly browsable.
- Rotating the encryption keys on a documented schedule and keeping backups of `SMTP_ENCRYPT_KEY` and `PAYMENT_ENCRYPT_KEY` (loss = unrecoverable PHI).
- Reviewing the audit log periodically for anomalous access patterns.

A vulnerability in the operator's deployment configuration is the operator's responsibility to fix; we will help diagnose it but it is not a project-side issue.

## Cryptographic Primitives in Use

For your reference when assessing reports:

- AES-256-GCM with context-bound additional authenticated data (AAD) for PHI at rest (`includes/phi_crypto.php`).
- HMAC-SHA256 chained audit log (`db/audit.php`).
- HMAC-SHA256 with 30-second TTL for inter-DC authentication (`includes/dc_auth.php`).
- Argon2id password hashing with bcrypt fallback and auto-rehash on login (`includes/password_policy.php`).
- TOTP (RFC 6238) with replay prevention for MFA (`includes/totp.php`).
- 48-character hex tokens (24 bytes from `random_bytes`) for client join links and password resets.

Implementation flaws in the above are in scope. The choice of primitives is settled — proposals to swap to different cipher suites without a concrete weakness will be declined.
