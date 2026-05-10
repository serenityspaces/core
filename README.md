# Serenity Spaces

A self-hosted therapeutic conversation platform built on the principle of true co-existence: licensed mental health professionals, unlicensed counselors, faith-based practitioners, and Pride-affirming spaces — all under one roof, doing what each does best, without friction.

Designed for therapists, peer support specialists, pastoral and spiritual direction practitioners, philosophical counselors, life coaches, and recovery support workers who want a private, professional environment for their sessions — without corporate platform overhead.

PHP 8+ · MySQL · WebRTC · Vanilla JS · Long-polling · mPDF export

> **Operator Responsibility:** SerenitySpaces is self-hosted software. Operators are responsible for configuration, security, legal compliance, and suitability for their jurisdiction. The setup wizard includes a required acknowledgement before installation continues.

---

## Requirements

- PHP 8.0+
- MySQL 5.7+ or MariaDB 10.3+
- Apache or Nginx with PHP-FPM or PHP CGI
- Composer (optional — required for mPDF PDF export; falls back to print-ready HTML without it)

**Required PHP extensions:**

| Extension | Purpose |
|-----------|---------|
| `openssl` | AES-256-GCM PHI encryption and SMTP TLS — **critical; absence causes silent encryption failure** |
| `pdo_mysql` | Database connection |
| `fileinfo` | MIME-type validation on file uploads |
| `gd` | Avatar image processing |
| `mbstring` | Email header encoding |
| `curl` | AI vendor API calls (required for AI integration features) |

These are enabled by default on most PHP installations. Check with `php -m` or via cPanel → PHP Extensions. The setup wizard will flag any missing extensions on step 1.

---

## Quick Start

### 1. Create the database

```bash
mysql -u root -p -e "CREATE DATABASE serenityspaces CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p serenityspaces < db/schema.sql
```

### 2. Configure the database connection

Edit `db/connection.php` or set environment variables:

```bash
export DB_HOST=localhost
export DB_NAME=serenityspaces
export DB_USER=youruser
export DB_PASS=yourpassword
```

Set an encryption key for PHI at-rest encryption. This must be a 64-character hex string (32 bytes):

```bash
export SMTP_ENCRYPT_KEY=your64charhexkeyhere
```

### 3. Web server

#### Apache

```apache
<VirtualHost *:443>
    ServerName yourdomain.com
    DocumentRoot /path/to/serenityspaces
    DirectoryIndex index.php

    <Directory /path/to/serenityspaces>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

#### Nginx

```nginx
server {
    listen 443 ssl;
    server_name yourdomain.com;
    root /path/to/serenityspaces;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Block direct access to uploaded PHI files — serve through api/serve_file.php only.
    # Mirrors the Apache .htaccess rules in assets/uploads/.htaccess.
    location ~* ^/assets/uploads/(?!(bg_|thumb_|logo_|sitebg_)[0-9a-f]+\.[a-z]+$|[0-9a-f]{24}\.[a-z]+$) {
        deny all;
    }

    # Deny PHP execution in upload directories regardless of the above
    location ~* ^/assets/uploads/.*\.php {
        deny all;
    }
}
```

### 4. File permissions

```bash
chmod -R 755 assets/uploads assets/backgrounds assets/avatars
chmod 644 db/connection.php
```

### 5. Run setup

Visit `/setup.php` in your browser to create the first admin account and configure initial settings.

### 6. (Optional) Install mPDF for PDF export

```bash
composer install
```

### 7. Set up cron jobs

Four scheduled jobs keep the platform healthy. Set these up in cPanel → Cron Jobs (or your system crontab):

**Firewall cleanup** — removes expired IP blocks and stale login attempt records. Run hourly.

```
0 * * * * /usr/bin/php /home/<username>/public_html/cron/firewall_cleanup.php >> /home/<username>/logs/firewall_cleanup.log 2>&1
```

**Audit IP purge** — nulls IP addresses on audit log rows older than 12 months, satisfying GDPR storage limitation (Art. 5(1)(e)). Run daily. **This job is required for GDPR compliance — skipping it causes personal data (IP addresses) to be retained beyond the permitted period.**

```
0 2 * * * /usr/bin/php /home/<username>/public_html/cron/audit_ip_purge.php >> /home/<username>/logs/audit_purge.log 2>&1
```

**Appointment reminders** — sends 24-hour email reminders to practitioners and clients. Run daily.

```
0 8 * * * /usr/bin/php /home/<username>/public_html/cron/appointment_reminder.php >> /home/<username>/logs/reminders.log 2>&1
```

**MFA reminders** — sends reminder emails to practitioners who have not yet enabled two-factor authentication. Run daily.

```
0 9 * * * /usr/bin/php /home/<username>/public_html/cron/mfa_reminder.php >> /home/<username>/logs/mfa_reminder.log 2>&1
```

Replace `<username>` with your cPanel username and adjust the PHP binary path if needed (`which php` to find it).

If your host does not support cron jobs, `cron/trigger.php` is a key-authenticated web endpoint that executes any of the above jobs on demand. Set `CRON_SECRET` in your environment and call `https://yourdomain.com/cron/trigger.php?job=firewall_cleanup&key=<secret>`.

---

## Features

### Session room

- **Real-time chat** via long-polling (no WebSocket server required)
- **WebRTC peer-to-peer video and audio** — DTLS-SRTP encrypted; no media server in the path
- **Voice-only and text-only modes** — clients can choose their level of participation
- **Voice notes** — record and send audio clips mid-session
- **File sharing** — send files and images during a session
- **Draggable avatars** — spatial positioning of participants in the room pane
- **Avatar linking** — drag one avatar onto another to move them together
- **Message highlighting** — four practitioner-configurable highlight colours; applied to text selections
- **Message pinning** — pin important messages; visible in sidebar and included in export
- **Message reactions** — emoji reactions on any message
- **Message edit and delete** — edit or retract sent messages
- **Typing indicators** — live typing status per participant
- **Noting indicators** — visual signal when the practitioner is taking notes
- **Webcam frame smoothing** — interpolated frame delivery for smoother low-bandwidth video
- **Crisis resource signposting** — practitioner-only ⚠️ panel; one click sends a pre-configured crisis resource as a visually distinct message (amber styling)
- **Concept and theme tagging** — tag individual messages and notes by custom concept; cross-session Themes view per client in the dashboard
- **In-session activities** — shared paint canvas, chess, checkers, zen garden
- **In-session AI assistant** — optional named AI participant that responds when addressed by name; typing indicators and avatar docked to practitioner; requires client consent
- **Presence detection** — reconnect awareness; warm waiting room if client arrives early
- **First-session onboarding tour** — tooltip walkthrough for new participants

### Practitioner notes and records

- **Per-participant clinical notes** — SOAP, DAP, and BIRP templates; full markdown toolbar; auto-saved; encrypted at rest
- **Session highlights** — four colours, practitioner-customisable; appear in exports
- **Action items / homework** — captured during sessions; persist across a journey
- **Session recap workflow** — structured pre-export form: key summary, insights, actions, next focus, optional series assignment
- **Session export** — full HTML transcript with highlights, pins, notes, and recap; optional mPDF export
- **Session review** — post-session practitioner review with transcript, highlights, and notes side by side
- **Session journeys / series** — named inquiry arcs across multiple sessions; central question, arc notes, session-by-session recap
- **Goal and progress tracking** — goals per client with milestones, progress percentage, status management, and progress bar; Goals tab in client detail view
- **Concept/theme tagging** — personal tag library per practitioner; tag messages and notes; cross-session Themes view

### Bookings and scheduling

- **Practitioner availability** — set weekly availability by day and time in any timezone
- **Client booking flow** — browse directory, select slot, complete booking form
- **Practitioner-initiated booking** — practitioners can create bookings from the dashboard directly (no client action required)
- **Guest and account-based booking** — account optional; guests book with name and email
- **Intake forms** — build custom forms (text, textarea, select, radio, checkbox); assign to bookings; client receives secure email link; responses visible in booking detail panel
- **Booking management** — confirm, reschedule, or cancel; client can request changes from their portal
- **Cancellation policy** — per-practitioner cancellation terms displayed to clients during booking
- **Calendar overview** — monthly calendar view in dashboard
- **Booking confirmation emails** — with .ics calendar attachment
- **Discount campaigns** — time-limited percentage discounts
- **Discount codes** — practitioner-created codes with percentage, validity window, total use cap, and per-client use cap

### Payments

- **PayPal** — via PayPal.Me or direct email (Basic); full Checkout API with order creation and capture (Advanced)
- **Stripe** — card payments; keys stored encrypted at rest; Checkout Session API (Advanced)
- **Square** — Square application ID, access token (encrypted at rest), and location ID; Payment Link API (Advanced)
- **BTCPay Server** — self-hosted Bitcoin and Lightning invoice creation via BTCPay API (Advanced)
- **Bitcoin** — static Bitcoin payment address (Basic)
- **Free sessions** — zero-cost sessions without payment flow
- **Manual / bank transfer** — tracked without automated processing
- **Unified webhook receiver** — `api/payment_webhook.php` handles Stripe, PayPal, Square, and BTCPay Server incoming events; signature-verified; idempotent event logging
- **Finance view** — earnings history, CSV export
- **DPA enforcement** — enabling Stripe, PayPal, or Square requires uploading the processor's Art. 28 DPA via the practitioner profile first

### Client portal

- **Dashboard** — upcoming sessions at a glance
- **Session history** — past sessions with notes and review access
- **My Notes** — private reflection space per session; encrypted at rest; never visible to practitioners
- **Inbox** — secure messaging with practitioner between sessions
- **Settings** — update profile, change password
- **Booking requests** — reschedule or cancellation requests from the portal
- **Session rating** — post-session 1–5 star rating (visible to admin, not practitioner)
- **AI Agreements** — view and manage AI consent per practitioner; update scope permissions (summarisation, notes, post-session, in-session) at any time
- **My Practitioners** — view all practitioners the client has booked with; see each practitioner's uploaded Data Processing Agreements (DPAs) for AI vendors and payment processors
- **GDPR deletion request** — request full deletion of personal data; tracked and processed within 30 days

### Practitioner profile

- Display name, avatar, and biography
- About and practice description
- Identity fields and inclusivity / language settings (visible in practitioner directory)
- Credential / licence verification submission (admin review gates licensed practice types)
- Highlight colour customisation (4 colours, hex, with live preview)
- Availability configuration
- Rates and payment gateway configuration
- Intake form builder
- **References & Quotes library** — personal library of therapeutic references, quotes, and passages; categorised (spiritual traditions, philosophy, recovery, grief, pop culture and more); manage from profile; access during sessions via the Share panel; 117-entry seed library included across 13 categories
- **Cancellation policy** — per-practitioner text displayed to clients at booking; stored in profile
- **AI Integration** — connect an AI vendor (OpenAI, Anthropic, Google Gemini, or Cohere); configure API key, model, assistant name, avatar, and system prompt; enable scopes per-practitioner (summarisation, notes assistance, post-session discussion, in-session participation); enabling AI requires uploading the vendor's Art. 28 DPA first
- **Data Processing Agreements (DPAs)** — upload and manage DPAs for each AI vendor (OpenAI, Anthropic, Google, Cohere, or custom) and each payment gateway (Stripe, PayPal, Square, custom) from the practitioner profile; required before enabling the corresponding feature

### Client management

- **Client detail view** — intake history, session history, goals, themes, notes, and wellness trend for each client
- **Wellness timeline** — SVG sparkline visualisation of 1–10 numeric intake fields over time; trend arrow and delta displayed per client
- **Practitioner-to-practitioner referrals** — refer a client to another practitioner on the platform with encrypted notes; receiving practitioner accepts or declines; accepted referral snapshots the client's goals and context; configurable per-referral permission flags (view intake, view goals, view themes, view notes, contact client)
- **Directory search** — full AND-logic text search across practitioner name, specialty, approach, and bio; filterable from the public directory

### Admin panel

- General settings (Production Mode toggle, platform name, logo, theme, registration toggle)
- Room feature flags
- SMTP / email configuration
- Email template editor (8 templates with variable placeholders and preview)
- User management (view, edit, delete practitioner accounts; add manually)
- Session reviews (client ratings — admin-only view)
- Licence review (pending credential submissions with approval/rejection)
- Firewall (view and manage IP blocks)
- GDPR deletion request management
- Crisis resource management (add, edit, delete, reorder)
- **AI Integration** — platform-level enable/disable toggle; allowlist of permitted vendors; allowlist of permitted scopes (all off by default)
- **DPA Document management** — upload and manage platform-level DPAs for hosting provider and SMTP provider; stored in `storage/dpa/` with web access denied; served only through authenticated endpoint

### Privacy and compliance

- **AES-256-GCM encryption at rest** — chat messages, practitioner notes, session recap summaries, client reflection notes, booking PII (name, email, DOB, gender, sexuality, location), and AI vendor API keys; context-authenticated encryption binds each record to its session, making cross-record tampering detectable
- **Explicit GDPR Article 9 consent** — for special category booking fields; timestamped consent records stored
- **Audit log** — session.join, notes.write, file.upload, session.export, gdpr events; HMAC-SHA256 tamper-evident chain hash per row
- **Session inactivity timeout** — 15 minutes for practitioners (PHI access), 30 minutes for clients (`includes/session_timeout.php`)
- **HTTP security headers** — HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy; enforced when Production Mode is enabled (`includes/security_headers.php`)
- **Production Mode gate** — disables public-facing bookings and client sign-ups until TLS and initial configuration are complete; prevents header/HTTPS errors during setup
- **Authenticated file serving** — session attachments, voice notes, and uploaded backgrounds served only through `api/serve_file.php`; validates session membership before streaming; direct web access to upload directories blocked
- **GDPR erasure workflow** — client-initiated from portal; admin completion; audit record retained
- **Article 30 ROPA** — accessible to practitioners at `/ropa.php`
- **Data Protection Impact Assessment (DPIA)** — generated at `/dpia.php`; covers session transcripts, clinical notes, special category booking data, AI processing, and file uploads
- **Art. 28 DPA enforcement** — enabling AI vendors or payment gateways (Stripe, PayPal, Square) is blocked server-side until the practitioner uploads a valid Data Processing Agreement for that processor; platform-level DPAs (hosting, SMTP) managed in admin panel
- **CSRF protection** — token-based on all state-changing requests
- **Brute-force firewall** — IP-based rate limiting and blocking on all auth endpoints
- **TOTP two-factor authentication** — available for both practitioners and registered clients; Google Authenticator compatible; optional (`includes/totp.php`)
- **AI consent architecture** — per-scope, per-practitioner client consent captured at booking and manageable from the client portal; platform operator controls which vendors and scopes are permitted at all
- **No third-party tracking** — no analytics, no advertising, no external scripts in sessions
- **WebRTC P2P** — video and audio travel directly between participants; DTLS-SRTP encrypted; no media server

---

## File Structure

```
/index.php                      Landing page + client join form + practitioner directory
/login.php                      Practitioner login
/register.php                   Practitioner registration
/dashboard.php                  Practitioner dashboard
/room.php                       Session room (practitioners + clients)
/profile.php                    Practitioner profile and settings
/admin.php                      Admin panel
/book.php                       Client booking flow (with Pay Now for Advanced payment modes)
/client_portal.php              Client portal (upcoming sessions, history, notes, inbox)
/intake.php                     Client intake form submission (public, token-gated)
/about.php                      Platform about page
/coverage.php                   Practitioner type coverage guide (all supported roles and modalities)
/privacy.php                    Privacy notice (GDPR Article 13)
/ropa.php                       Record of Processing Activities (Article 30, auth-gated)
/setup.php                      First-run setup wizard
/blocked.php                    Firewall block page

/api/action_items.php           Action items / homework CRUD
/api/dpa.php                    DPA document upload, list, download, delete (platform + practitioner scope)
/api/activities.php             In-session activities (paint, chess, checkers, zen garden)
/api/ai.php                     AI assistant actions (summarize, notes assist, post-session chat, in-session respond)
/api/ai_config.php              Practitioner AI config (load, save, avatar, fetch models)
/api/ai_consent.php             Client AI consent CRUD (list, load, save)
/api/auth.php                   Practitioner logout
/api/availability.php           Practitioner availability CRUD
/api/backgrounds.php            Room background image upload and library
/api/bookings.php               Booking CRUD + intake assignment
/api/client_auth.php            Client account login / logout / register
/api/client_export.php          Client data export
/api/client_notes.php           Client private reflection notes (encrypted)
/api/client.php                 Client account profile management
/api/concept_tags.php           Concept/theme tag library + tagging + cross-session themes
/api/crisis_resources.php       Crisis resource list, save, delete (admin)
/api/dc.php                     Multi-location DC sync endpoint (HMAC-authenticated)
/api/discount_codes.php         Discount code CRUD + validation
/api/email_templates.php        Email template CRUD + preview
/api/export.php                 Session export (HTML transcript + mPDF)
/api/files.php                  In-session file upload and serving
/api/gdpr.php                   GDPR deletion request submit, complete, cancel
/api/goals.php                  Goal and progress tracking CRUD
/api/highlights.php             Message highlight CRUD
/api/inbox.php                  Practitioner–client between-session messaging
/api/intake.php                 Intake form builder + delivery + submission
/api/intake_templates.php       Intake form template library (system defaults + practitioner custom)
/api/license.php                Practitioner licence submission
/api/messages.php               Send, edit, delete session messages (with crisis type)
/api/notes.php                  Practitioner session notes (encrypted)
/api/noting.php                 Noting indicator (practitioner is writing notes)
/api/participants.php           Avatar position, link/unlink, avatar upload
/api/payment_webhook.php        Unified payment webhook receiver (Stripe, PayPal, Square, BTCPay); signature-verified
/api/payments.php               Payment gateway config, checkout creation (Basic + Advanced modes)
/api/pins.php                   Pin / unpin messages
/api/poll.php                   Long-polling endpoint (real-time event delivery)
/api/practitioner_clients.php   Client list and detail view for dashboard (incl. wellness series)
/api/presence.php               Participant presence check (last seen)
/api/reactions.php              Message emoji reactions
/api/references.php             Practitioner references/quotes library CRUD + seed library search
/api/referrals.php              Practitioner-to-practitioner client referrals (send, accept, decline, withdraw)
/api/rooms.php                  Room CRUD
/api/series.php                 Session journey / series CRUD
/api/session_review.php         Post-session practitioner review
/api/session.php                End session without export
/api/typing.php                 Typing indicator
/api/voice_note_listen.php      Mark voice note as listened
/api/voice_notes.php            Voice note upload and serving
/api/voice_signal.php           WebRTC voice chat signalling (join_token or practitioner session required)
/api/webcam_frame.php           Webcam frame delivery (with interpolation)
/api/serve_file.php             Authenticated file serving endpoint (session files, voice notes, backgrounds)

/assets/css/main.css            Shared stylesheet
/assets/css/fonts.css           Font imports
/assets/js/room.js              Session room JavaScript
/assets/js/dashboard.js        Dashboard JavaScript
/assets/uploads/                Session file uploads (voice notes, attachments)
/assets/backgrounds/            Room background images
/assets/avatars/                User avatar uploads

/db/connection.php              PDO database connection + settings helpers
/db/schema.sql                  Database schema (55 tables)
/db/events.php                  emitEvent() helper for long-polling
/db/audit.php                   audit_log() helper

/includes/avatar_presets.php    Built-in avatar preset list
/includes/btcpay_api.php        BTCPay Server invoice creation helper
/includes/coverage/             Practitioner type coverage documentation (Markdown, one file per type)
/includes/dc_auth.php           HMAC-SHA256 inter-server auth helpers (multi-location DC)
/includes/email_templates.php   Email template defaults
/includes/firewall.php          IP rate limiting and blocking
/includes/geo.php               Timezone / geo helpers
/includes/mailer.php            PHPMailer SMTP wrapper
/includes/password_policy.php   Password strength enforcement (Argon2id / bcrypt)
/includes/paypal_api.php        PayPal Checkout API order creation helper
/includes/phi_crypto.php        PHI at-rest encryption (AES-256-GCM, context-authenticated enc3:)
/includes/quote_library.php     117-entry seed quote library across 13 categories (spiritual traditions, philosophy, recovery, pop culture, etc.)
/includes/security_headers.php  HTTP security headers (HSTS, nonce CSP, etc.) — active in production mode
/includes/session_timeout.php   Inactivity timeout enforcement (15 min practitioners / 30 min clients)
/includes/square_api.php        Square Payment Link creation helper
/includes/stripe_api.php        Stripe Checkout Session creation helper
/includes/totp.php              TOTP two-factor authentication (RFC 6238)

/cron/audit_ip_purge.php        Scheduled job: null ip_address on audit_log rows older than 12 months
/cron/appointment_reminder.php  Scheduled job: 24h appointment reminders to practitioner + client
/cron/firewall_cleanup.php      Scheduled job: expire temporary IP blocks
/cron/mfa_reminder.php          Scheduled job: 48h MFA enforcement reminder emails
/cron/trigger.php               Web-trigger fallback for scheduled jobs (key-authenticated)

/lib/                           Vendor libraries (mPDF installed here by Composer)
/composer.json                  Composer dependencies (mPDF)
/reports/                       Internal assessment reports (not web-accessible)
/storage/dpa/                   DPA document storage (outside webroot or .htaccess-protected)
```

---

## Security

- All database queries use PDO prepared statements — no string interpolation
- CSRF tokens on every state-changing request (POST forms and JSON endpoints)
- Passwords hashed with **Argon2id** (64 MB memory, 4 iterations); falls back to bcrypt cost-14 on PHP builds without Argon2id (`includes/password_policy.php`)
- Practitioner passwords: 12+ chars, upper, lower, digit, special character; client passwords: 10+ chars, upper, lower, digit
- **TOTP two-factor authentication** for practitioners and registered clients — Google Authenticator compatible; optional but strongly recommended (`includes/totp.php`)
- PHI encrypted at rest with **AES-256-GCM** (`includes/phi_crypto.php`) — context-authenticated encryption (enc3:) binds each record to its session; key in environment variable, never in code or database
- All PHI fields encrypted: session messages, practitioner notes, session recap summaries, client reflection notes, intake form responses, booking PII (name, email, DOB, gender, sexuality), client special category fields
- MIME-type allowlisting on all file uploads; extension derived from validated MIME type only; stored filenames are random 24-byte hex strings
- All uploaded files served through an authenticated PHP endpoint (`api/serve_file.php`); validates session membership before streaming; direct web access to upload paths blocked
- Enabling payment gateways (Stripe, PayPal, Square) or AI vendors is blocked server-side in `api/payments.php` and `api/ai_config.php` until a valid Art. 28 DPA is on file
- HTTP security headers enforced in production mode (`includes/security_headers.php`): HSTS, nonce-based CSP, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy; HTTP→HTTPS redirect
- Session inactivity timeout: 15 minutes for practitioners (PHI access), 30 minutes for clients (`includes/session_timeout.php`)
- Brute-force firewall (`includes/firewall.php`) — progressive delays, 1-hour temp blocks, permanent blocks on all auth and registration endpoints
- Join tokens are 48-character hex strings (24 random bytes); room tokens are 32-character hex strings
- Reconnect cookies set with `httponly=true`, `samesite=Strict`
- Audit log records all sensitive access events (auth, session joins, note writes, file uploads, exports, GDPR events); HMAC-SHA256 tamper-evident chain hash per row; IP addresses automatically nulled after 12 months

---

## License

SerenitySpaces is released under the **Elastic License 2.0 (ELv2)**.

You are free to self-host, use, and modify this software. The source is open and auditable. The one thing you cannot do is take it and sell it as a product or managed service to others.

**In plain terms:**
- A therapist, counsellor, or peer support specialist running SerenitySpaces for their own practice — and charging for their professional time — is fully permitted. You are selling your expertise, not the platform.
- A company packaging SerenitySpaces as a commercial SaaS offering ("hosted therapy platform, £X/month") is not permitted.

See the `LICENSE` file for the full license text.
