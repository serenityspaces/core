<?php
/**
 * PHI / PII at-rest encryption helpers.
 *
 * enc3: AES-256-GCM with context-binding AAD (session_id bound)     — HIPAA §164.312(c)(2)
 * enc2: AES-256-GCM, 12-byte nonce, 16-byte auth tag                — existing data, no AAD
 * enc:  AES-256-CBC with 16-byte IV                                  — legacy, read-only
 *
 * Encrypted format (enc3:/enc2:): base64( nonce[12] . tag[16] . ciphertext )
 * Encrypted format (enc:):        base64( iv[16] . ciphertext )
 *
 * Context parameter (enc3:) — pass the same string at encrypt and decrypt time.
 * Recommended format: "messages:$sessionId" or "notes:$sessionId".
 * Binding the ciphertext to its context makes cross-record substitution detectable.
 *
 * If SMTP_ENCRYPT_KEY is absent or empty (e.g. dev installs) the functions
 * are transparent no-ops — data is stored / returned plaintext.
 */

/**
 * Returns the decoded encryption key bytes, cached for the request lifetime.
 * Avoids repeated getenv() + hex2bin() on pages that decrypt many PHI fields.
 */
function _phi_key(): ?string
{
    static $keyBytes = false; // false = not yet resolved; null = no key; string = key bytes
    if ($keyBytes !== false) return $keyBytes;
    if (!defined('SMTP_ENCRYPT_KEY') || SMTP_ENCRYPT_KEY === '') {
        $keyBytes = null;
        return null;
    }
    $keyBytes = hex2bin(SMTP_ENCRYPT_KEY);
    return $keyBytes;
}

/**
 * Encrypt a PHI value.
 *
 * @param string $value   Plaintext to encrypt.
 * @param string $context Optional context string for AAD binding (e.g. "messages:42").
 *                        When non-empty, uses enc3: prefix; otherwise enc2:.
 */
function phi_encrypt(string $value, string $context = ''): string
{
    if ($value === '') return '';
    $key = _phi_key();
    if ($key === null) return $value;
    $nonce  = random_bytes(12);
    $tag    = '';
    $aad    = $context !== '' ? $context : '';
    $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
    if ($cipher === false) return $value;
    $prefix = $context !== '' ? 'enc3:' : 'enc2:';
    return $prefix . base64_encode($nonce . $tag . $cipher);
}

/**
 * Decrypt a PHI value.
 *
 * @param string $stored  Stored value (enc3:, enc2:, enc:, or legacy plaintext).
 * @param string $context Must match the context used at encrypt time for enc3: values.
 *                        Ignored for enc2: and enc: (backward-compatible).
 */
function phi_decrypt(string $stored, string $context = ''): string
{
    if ($stored === '') return '';

    // GCM context-bound (enc3:) — HIPAA §164.312(c)(2)
    if (str_starts_with($stored, 'enc3:')) {
        $key = _phi_key();
        if ($key === null) return '';
        $decoded = base64_decode(substr($stored, 5), true);
        if ($decoded === false || strlen($decoded) < 29) return '';
        $nonce  = substr($decoded, 0, 12);
        $tag    = substr($decoded, 12, 16);
        $cipher = substr($decoded, 28);
        $plain  = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context);
        return $plain === false ? '' : $plain;
    }

    // GCM no-AAD (enc2:) — existing data, backward-compatible
    if (str_starts_with($stored, 'enc2:')) {
        $key = _phi_key();
        if ($key === null) return '';
        $decoded = base64_decode(substr($stored, 5), true);
        if ($decoded === false || strlen($decoded) < 29) return '';
        $nonce  = substr($decoded, 0, 12);
        $tag    = substr($decoded, 12, 16);
        $cipher = substr($decoded, 28);
        $plain  = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        return $plain === false ? '' : $plain;
    }

    // CBC legacy (enc:)
    if (str_starts_with($stored, 'enc:')) {
        $key = _phi_key();
        if ($key === null) return '';
        $decoded = base64_decode(substr($stored, 4), true);
        if ($decoded === false || strlen($decoded) < 17) return '';
        $iv     = substr($decoded, 0, 16);
        $cipher = substr($decoded, 16);
        $plain  = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }

    // Plaintext legacy row
    return $stored;
}

/**
 * Decrypt PHI fields in a booking row in-place.
 * Safe to call on already-plaintext rows (legacy compat).
 */
function phi_decrypt_booking(array &$row): void
{
    static $fields = ['guest_name', 'guest_email', 'date_of_birth', 'gender', 'sexuality'];
    foreach ($fields as $f) {
        if (isset($row[$f]) && $row[$f] !== null) {
            $row[$f] = phi_decrypt((string)$row[$f]);
        }
    }
}

/**
 * Decrypt PHI fields in every row of a result set in-place.
 */
function phi_decrypt_bookings(array &$rows): void
{
    foreach ($rows as &$row) {
        phi_decrypt_booking($row);
    }
    unset($row);
}

/**
 * Decrypt special-category PHI fields in an end_users row in-place.
 * Handles enc3:, enc2:, enc:, and legacy plaintext values.
 */
function phi_decrypt_end_user(array &$row): void
{
    static $fields = ['gender', 'sexuality', 'date_of_birth'];
    foreach ($fields as $f) {
        if (isset($row[$f]) && $row[$f] !== null) {
            $row[$f] = phi_decrypt((string)$row[$f]);
        }
    }
}
