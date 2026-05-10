<?php
/**
 * Serenity Spaces — TOTP (RFC 6238) Implementation
 *
 * Generates and verifies time-based one-time passwords compatible with
 * Google Authenticator, Authy, and any RFC 6238 compliant app.
 *
 * Algorithm: HMAC-SHA1, 30-second window, 6-digit codes.
 */

/**
 * Generate a random base32-encoded secret key (20 bytes → 32 chars base32).
 */
function totp_generate_secret(): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bytes    = random_bytes(20);
    $result   = '';
    $buffer   = 0;
    $bitsLeft = 0;
    for ($i = 0; $i < strlen($bytes); $i++) {
        $buffer   = ($buffer << 8) | ord($bytes[$i]);
        $bitsLeft += 8;
        while ($bitsLeft >= 5) {
            $bitsLeft -= 5;
            $result   .= $alphabet[($buffer >> $bitsLeft) & 31];
        }
    }
    if ($bitsLeft > 0) {
        $result .= $alphabet[($buffer << (5 - $bitsLeft)) & 31];
    }
    return $result;
}

/**
 * Decode a base32 string to raw bytes.
 */
function _totp_base32_decode(string $base32): string {
    $base32  = strtoupper(trim($base32));
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer   = 0;
    $bitsLeft = 0;
    $result   = '';
    for ($i = 0; $i < strlen($base32); $i++) {
        $pos = strpos($alphabet, $base32[$i]);
        if ($pos === false) continue;
        $buffer    = ($buffer << 5) | $pos;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $result   .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    return $result;
}

/**
 * Compute a HOTP value for the given secret and counter.
 * Returns a zero-padded 6-digit string.
 */
function _totp_hotp(string $secret, int $counter): string {
    $key     = _totp_base32_decode($secret);
    $msgPack = pack('N*', 0) . pack('N*', $counter);
    $hash    = hash_hmac('sha1', $msgPack, $key, true);
    $offset  = ord($hash[19]) & 0x0F;
    $code    = (
        ((ord($hash[$offset])     & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
        ((ord($hash[$offset + 3]) & 0xFF))
    ) % 1_000_000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

/**
 * Generate the current TOTP code for a secret.
 * Useful for testing/display.
 */
function totp_current(string $secret): string {
    $counter = (int)floor(time() / 30);
    return _totp_hotp($secret, $counter);
}

/**
 * Verify a TOTP code.
 * Allows a ±1 window (90-second tolerance) to account for clock drift.
 * Prevents replay attacks by recording accepted codes for the window duration.
 *
 * @param  string   $secret    Base32-encoded secret
 * @param  string   $code      6-digit code from authenticator app
 * @param  int|null $userId    User ID — used to key the used-code store (optional; skips replay check if null)
 * @param  string   $userType  'pract' for practitioners (default), 'client' for end_users
 * @return bool
 */
function totp_verify(string $secret, string $code, ?int $userId = null, string $userType = 'pract'): bool {
    $code    = trim($code);
    $counter = (int)floor(time() / 30);

    // Check replay before verifying — reject any code already used within the window
    if ($userId !== null) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare(
                'SELECT id FROM totp_used_codes WHERE user_type = ? AND user_id = ? AND code = ? AND used_at > DATE_SUB(NOW(), INTERVAL 90 SECOND) LIMIT 1'
            );
            $stmt->execute([$userType, $userId, $code]);
            if ($stmt->fetch()) {
                return false; // Already used within the 90-second window
            }
        } catch (Throwable $e) {
            // Cannot verify replay — fail secure rather than allow a potentially replayed code.
            // This only triggers if totp_used_codes is missing (incomplete schema install).
            error_log('[SerenitySpaces] TOTP replay check failed — totp_used_codes table may be missing: ' . $e->getMessage());
            return false;
        }
    }

    $matched = false;
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(_totp_hotp($secret, $counter + $i), $code)) {
            $matched = true;
            break;
        }
    }

    if ($matched && $userId !== null) {
        try {
            $pdo = getDB();
            // Record the used code; purge expired entries opportunistically
            $pdo->prepare('INSERT IGNORE INTO totp_used_codes (user_type, user_id, code) VALUES (?, ?, ?)')->execute([$userType, $userId, $code]);
            $pdo->prepare('DELETE FROM totp_used_codes WHERE used_at < DATE_SUB(NOW(), INTERVAL 90 SECOND)')->execute();
        } catch (Throwable $e) {
            // Non-fatal — if the record can't be stored the verify still succeeds
        }
    }

    return $matched;
}

/**
 * Build a TOTP provisioning URI for QR code generation.
 * Compatible with Google Authenticator format.
 */
function totp_provisioning_uri(string $secret, string $account, string $issuer = 'Serenity Spaces'): string {
    return 'otpauth://totp/' .
        rawurlencode($issuer . ':' . $account) .
        '?secret=' . $secret .
        '&issuer=' . rawurlencode($issuer) .
        '&algorithm=SHA1&digits=6&period=30';
}
