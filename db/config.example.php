<?php
/**
 * SerenitySpaces — configuration reference file.
 *
 * The setup wizard writes the real values to db/config.php automatically.
 * This file documents every constant so headless / Docker / manual installs
 * know what to provide. Copy and rename to db/config.php, fill in real values.
 *
 * NEVER commit db/config.php to version control — it contains credentials.
 */

// ── Database ──────────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');          // MySQL host
define('DB_NAME', 'serenityspaces');     // Database name
define('DB_USER', 'ss_user');            // Database username
define('DB_PASS', 'your_db_password');   // Database password

// ── Encryption keys ───────────────────────────────────────────────────────────
// Both are 64-character hex strings (32 bytes each). Generate with:
//   php -r "echo bin2hex(random_bytes(32));"
// CRITICAL: back these up separately from the database. Losing a key makes all
// data encrypted with it unrecoverable.

// PHI encryption key — encrypts session messages, clinical notes, booking PII,
// intake form responses, client reflection notes, AI API keys, SMTP password.
define('SMTP_ENCRYPT_KEY', 'your_64_char_hex_phi_key_here');

// Payment credentials key — encrypts Stripe, PayPal, and Square API keys/tokens.
// Kept separate from PHI so payment gateway keys can be rotated independently
// and a key exposure in one domain does not affect the other.
define('PAYMENT_ENCRYPT_KEY', 'your_64_char_hex_payment_key_here');

// ── Install type ──────────────────────────────────────────────────────────────
// 'primary'     — standalone single-server install (most installs)
// 'alternative' — secondary data-centre node (requires DC_* constants below)
define('INSTALL_TYPE', 'primary');

// ── Location display name (optional) ─────────────────────────────────────────
// Human-readable name shown for this server's location in the admin panel.
// e.g. 'London, UK' or 'US-East'
// define('LOCATION_DISPLAY_NAME', 'London, UK');

// ── Multi-DC constants (alternative installs only) ───────────────────────────
// Required only when INSTALL_TYPE === 'alternative'.
// define('DC_COUNTRY',       'GB');                              // ISO 3166-1 alpha-2
// define('DC_PRIMARY_URL',   'https://primary.yourdomain.com'); // Primary node base URL
// define('DC_SHARED_SECRET', 'your_hmac_shared_secret');        // HMAC-SHA256 key for inter-node auth

// ── Audit database credentials (optional) ────────────────────────────────────
// If set, the audit log writes through a separate MySQL user that has INSERT-only
// access to the audit_log table — making the log append-only at the DB level.
// Leave undefined to write audit records through the main DB_USER connection.
// define('AUDIT_DB_USER', 'ss_audit');
// define('AUDIT_DB_PASS', 'your_audit_db_password');
