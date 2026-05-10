# Contributing to SerenitySpaces

Thanks for considering a contribution. SerenitySpaces is a mental-health platform — patches here can affect real practitioners and clients, so the bar is intentionally higher than a typical open-source project.

## Before You Start

- Open an issue describing the problem or proposal before writing significant code. This avoids duplicate work and gives the maintainer a chance to flag architectural concerns early.
- For security findings, use the responsible-disclosure process in [SECURITY.md](SECURITY.md) — do **not** open a public issue.

## Project Values (Non-Negotiable)

These are load-bearing — patches that conflict with them will be declined regardless of code quality:

1. **Privacy by default** — sessions are ephemeral, data deletes on export, no analytics phone-home.
2. **Practitioner trust** — credential-gated features stay credential-gated.
3. **No third-party telemetry** — no Google Analytics, no error trackers, no CDN beacons. Self-hosted always.
4. **PHI encryption at rest** — any new PHI field must be encrypted via `phi_encrypt()` with context-bound AAD.
5. **Audit log integrity** — security-relevant events must call `audit_log()`. Don't bypass.

## Local Setup

See the README for full setup. In short:
1. PHP 8.0+, MySQL 5.7+/MariaDB 10.3+, Composer.
2. `composer install` for mPDF.
3. Import `db/schema.sql`.
4. Visit `/setup.php` to generate `db/config.php`.

## Code Standards

- **PHP**: PSR-12 style, 4-space indent. Use prepared statements — no string-built SQL ever.
- **JavaScript**: vanilla JS only. No frameworks, no build step, no transpilers. Match the style in `assets/js/room.js`.
- **CSS**: vanilla CSS in `assets/css/main.css`. No preprocessors.
- **Naming**: snake_case for PHP, camelCase for JS, kebab-case for CSS classes and HTML IDs.
- **Error handling**: never swallow exceptions silently. Either log with `error_log()` (PHP) or gate `console.error` behind `window.SS_DEBUG` (JS).
- **Comments**: explain *why*, not *what*. The code shows what; comments capture non-obvious constraints.

## Branch & Commit Conventions

- Branch from `main`. Name branches `fix/short-description`, `feat/short-description`, `docs/short-description`.
- Commit messages: imperative mood, ≤ 72 chars on the first line, body explains *why* not *what*.
- One concern per PR. Refactors and feature work go in separate PRs.

## Pull Request Checklist

Before opening a PR, verify:

- [ ] Database changes include both fresh-install schema (`db/schema.sql`) and an upgrade-note comment block.
- [ ] New PHI fields use `phi_encrypt()` with a context-bound AAD string.
- [ ] New API endpoints validate CSRF for practitioner paths; client-token paths use the 48-char hex token.
- [ ] New file uploads validate MIME via `finfo`, derive extension from a MIME map (not user-supplied filename), and live under `assets/uploads/` or `assets/backgrounds/`.
- [ ] No new dependencies unless absolutely necessary. mPDF is the only Composer dep — keep it that way where possible.
- [ ] Manual testing in a real browser, not just unit-style verification.

## What Gets Accepted

**Welcomed:** bug fixes, accessibility improvements, security hardening, performance work on the polling/event hot path, jurisdictional privacy content, additional language translations, intake form template contributions.

**Declined:** features that introduce third-party telemetry, anything that breaks the self-hosted single-server install path, additions that bypass the credential gate for licensed practice types, anything that adds an external runtime dependency to load the landing page.

## License

By contributing, you agree your work is licensed under the [Elastic License 2.0](LICENSE). See LICENSE for what that means in plain English.
