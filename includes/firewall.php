<?php
/**
 * Serenity Spaces — PHP Firewall
 *
 * Tracks failed login attempts and blocks abusive IPs.
 * Fail-open: if DB is unavailable, all checks pass silently.
 *
 * Thresholds:
 *   5  failures in 15 min  → escalating delay (1s per attempt over 5)
 *   10 failures in 30 min  → temp block (1 hour)
 *   3  temp blocks in 24h  → perm block
 *   20 total failures in 1h → perm block directly
 */

require_once __DIR__ . '/../db/connection.php';

/** Return the best available client IP.
 *
 * Forwarded-IP headers (CF-Connecting-IP, X-Real-IP, X-Forwarded-For) are
 * only trusted when REMOTE_ADDR is a private or loopback address, which
 * indicates a local reverse proxy is in the path. On direct connections
 * (shared hosting, cPanel) REMOTE_ADDR IS the client — trusting forged
 * headers would let any client spoof their IP and bypass rate limiting.
 */
function fw_get_ip(): string {
    $remoteAddr = trim($_SERVER['REMOTE_ADDR'] ?? '');

    // Trust forwarded headers only when the direct connection comes from a local proxy
    $isBehindProxy = $remoteAddr !== ''
        && filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

    if ($isBehindProxy) {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',   // Cloudflare
            $_SERVER['HTTP_X_REAL_IP']        ?? '',   // nginx proxy
            $_SERVER['HTTP_X_FORWARDED_FOR']  ?? '',   // load balancer (first hop only)
        ];
        foreach ($candidates as $ip) {
            $ip = trim(explode(',', $ip)[0]);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP))
        ? $remoteAddr
        : '0.0.0.0';
}

/**
 * Check if IP is currently blocked.
 * If blocked, render blocked.php and exit.
 * Silently passes if DB is unavailable (fail-open).
 */
function fw_check(string $ip): void {
    try {
        $pdo  = getDB();
        $stmt = $pdo->prepare(
            'SELECT block_type, expires_at, reason FROM ip_blocks WHERE ip_address = ? LIMIT 1'
        );
        $stmt->execute([$ip]);
        $block = $stmt->fetch();

        if (!$block) return;

        if ($block['block_type'] === 'temp' && $block['expires_at'] !== null) {
            $expiry = new DateTime($block['expires_at'], new DateTimeZone('UTC'));
            $now    = new DateTime('now', new DateTimeZone('UTC'));
            if ($now >= $expiry) {
                // Block expired — remove it
                $pdo->prepare('DELETE FROM ip_blocks WHERE ip_address = ?')->execute([$ip]);
                return;
            }
        }

        // Still blocked — show blocked page
        _fw_show_blocked($block['block_type'], $block['expires_at'], $block['reason']);
    } catch (Exception $e) {
        // Fail-open: DB unavailable, allow through
        error_log('[Firewall] fw_check error: ' . $e->getMessage());
    }
}

/**
 * Record a failed login attempt.
 * Escalates to temp block, then perm block based on thresholds.
 */
function fw_record_failure(string $ip, string $email = ''): void {
    try {
        $pdo = getDB();

        // Insert attempt record
        $pdo->prepare(
            'INSERT INTO login_attempts (ip_address, email) VALUES (?, ?)'
        )->execute([$ip, strtolower(trim($email))]);

        // Clean up attempts older than 24h to keep the table lean
        $pdo->prepare(
            'DELETE FROM login_attempts WHERE ip_address = ? AND attempted_at < NOW() - INTERVAL 24 HOUR'
        )->execute([$ip]);

        // Count failures in the last 30 minutes
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at >= NOW() - INTERVAL 30 MINUTE'
        );
        $stmt->execute([$ip]);
        $recent30 = (int)$stmt->fetchColumn();

        // Count failures in the last hour
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at >= NOW() - INTERVAL 1 HOUR'
        );
        $stmt->execute([$ip]);
        $recent1h = (int)$stmt->fetchColumn();

        // Perm block if 20+ failures in 1 hour
        if ($recent1h >= 20) {
            _fw_block($pdo, $ip, 'perm', null, 'Excessive failures: ' . $recent1h . ' in 1 hour');
            _fw_send_alert($ip, 'permanent', 'Excessive failures: ' . $recent1h . ' in 1 hour');
            _fw_show_blocked('perm', null, '');
        }

        // Temp block (1 hour) if 10+ failures in 30 minutes
        if ($recent30 >= 10) {
            $expiresAt = (new DateTime('now', new DateTimeZone('UTC')))->modify('+1 hour')->format('Y-m-d H:i:s');

            // Check if already blocked
            $existStmt = $pdo->prepare('SELECT id, block_type FROM ip_blocks WHERE ip_address = ? LIMIT 1');
            $existStmt->execute([$ip]);
            $existing = $existStmt->fetch();

            if (!$existing) {
                _fw_block($pdo, $ip, 'temp', $expiresAt, 'Rapid failures: ' . $recent30 . ' in 30 minutes');

                // Count temp blocks in the past 24 hours
                // (We approximate by counting how many times this IP has been blocked)
                // Simple heuristic: if they've already been temp-blocked before, escalate to perm
                // We track this by looking at total failure volume
                $stmt24 = $pdo->prepare(
                    'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at >= NOW() - INTERVAL 24 HOUR'
                );
                $stmt24->execute([$ip]);
                $total24h = (int)$stmt24->fetchColumn();

                if ($total24h >= 30) {
                    // Third-strike escalation: perm block
                    _fw_block($pdo, $ip, 'perm', null, 'Persistent abuse: ' . $total24h . ' attempts in 24h');
                    _fw_send_alert($ip, 'permanent', 'Persistent abuse: ' . $total24h . ' attempts in 24h');
                } else {
                    _fw_send_alert($ip, 'temporary (1 hour)', 'Rapid failures: ' . $recent30 . ' in 30 minutes');
                }

                _fw_show_blocked('temp', $expiresAt, '');
            }
        }

        // Escalating delay after 5 failures in 15 minutes
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at >= NOW() - INTERVAL 15 MINUTE'
        );
        $stmt->execute([$ip]);
        $recent15 = (int)$stmt->fetchColumn();

        if ($recent15 >= 5) {
            $delay = min($recent15 - 4, 10); // 1s to 10s escalating cap
            sleep($delay);
        }

    } catch (Exception $e) {
        error_log('[Firewall] fw_record_failure error: ' . $e->getMessage());
    }
}

/**
 * Clear failure records on successful login.
 */
function fw_clear(string $ip, string $email = ''): void {
    try {
        $pdo = getDB();
        $pdo->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ip]);
        if ($email !== '') {
            $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([strtolower(trim($email))]);
        }
    } catch (Exception $e) {
        error_log('[Firewall] fw_clear error: ' . $e->getMessage());
    }
}

/**
 * Get recent failure count for display purposes (e.g. showing attempt counter).
 */
function fw_recent_count(string $ip, int $minutes = 15): int {
    try {
        $stmt = getDB()->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at >= NOW() - INTERVAL ? MINUTE'
        );
        $stmt->execute([$ip, $minutes]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Admin: unblock an IP address.
 */
function fw_unblock(string $ip): void {
    try {
        $pdo = getDB();
        $pdo->prepare('DELETE FROM ip_blocks WHERE ip_address = ?')->execute([$ip]);
        $pdo->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ip]);
    } catch (Exception $e) {
        error_log('[Firewall] fw_unblock error: ' . $e->getMessage());
    }
}

// ── Internal helpers ───────────────────────────────────────────────

function _fw_block(PDO $pdo, string $ip, string $type, ?string $expiresAt, string $reason): void {
    $pdo->prepare(
        'INSERT INTO ip_blocks (ip_address, block_type, expires_at, reason)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE block_type = VALUES(block_type), expires_at = VALUES(expires_at), reason = VALUES(reason)'
    )->execute([$ip, $type, $expiresAt, $reason]);
}

function _fw_send_alert(string $ip, string $blockType, string $reason): void {
    try {
        require_once __DIR__ . '/mailer.php';
        require_once __DIR__ . '/email_templates.php';
        $adminEmail = getSetting('admin_email', '');
        if ($adminEmail === '') return;

        send_template_email(getDB(), 'security_ip_blocked', 'Security Alert — IP Blocked', [
            'ip_address' => $ip,
            'blocked_at' => date('F j, Y \a\t g:i A') . ' UTC',
            'reason'     => $blockType . ' — ' . $reason,
        ], $adminEmail, 'Admin');
    } catch (Exception $e) {
        error_log('[Firewall] alert email failed: ' . $e->getMessage());
    }
}

function _fw_show_blocked(string $blockType, ?string $expiresAt, string $reason): void {
    // Include the blocked template and exit
    $GLOBALS['_fw_block_type']    = $blockType;
    $GLOBALS['_fw_expires_at']    = $expiresAt;
    require __DIR__ . '/../blocked.php';
    exit;
}
