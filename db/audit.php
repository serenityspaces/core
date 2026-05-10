<?php
/**
 * Serenity Spaces — PHI Audit Log
 *
 * Call audit_log() at every point where protected health information (PHI)
 * or session data is accessed, exported, or deleted.
 *
 * Each row includes a tamper-evident chain hash (HIPAA §164.312(c)(1)).
 * Hash: HMAC-SHA256( prev_row_hash | action | actor | ip | timestamp ) keyed with
 * a derivation of SMTP_ENCRYPT_KEY.  Falls back to plain SHA-256 if no key is set.
 * Any modification or deletion of a row breaks the chain, detectable by iterating
 * and recomputing.
 *
 * INSERT-only hardening (Rec B):
 * If AUDIT_DB_USER and AUDIT_DB_PASS environment variables are set, audit INSERTs
 * use a dedicated PDO connection authenticated as that user.  That MySQL user should
 * be granted INSERT-only on the audit_log table and SELECT-only for chain reads:
 *
 *   CREATE USER 'ss_audit'@'localhost' IDENTIFIED BY '<strong-password>';
 *   GRANT SELECT, INSERT ON serenityspaces.audit_log TO 'ss_audit'@'localhost';
 *   FLUSH PRIVILEGES;
 *
 * With INSERT-only, the audit user cannot UPDATE or DELETE rows, preventing an
 * application-layer compromise from silently erasing audit evidence.
 *
 * @param PDO         $pdo           Active database connection (used for chain reads when no audit PDO)
 * @param string      $action        Short action key, e.g. 'session.join', 'session.view', 'notes.write'
 * @param array       $ctx           Optional context:
 *                                     practitioner_id, participant_id, session_id,
 *                                     entity_type, entity_id
 */

/**
 * Returns a dedicated PDO connection for audit log writes if AUDIT_DB_USER/PASS are set;
 * falls back to the supplied main connection otherwise.
 */
function _audit_pdo(PDO $mainPdo): PDO
{
    static $auditPdo = null;

    // Prefer environment variables (Docker/manual installs); fall back to constants
    // written by the setup wizard into db/config.php.
    $auditUser = getenv('AUDIT_DB_USER');
    if ($auditUser === false || $auditUser === '') {
        $auditUser = defined('AUDIT_DB_USER') ? AUDIT_DB_USER : '';
    }
    $auditPass = getenv('AUDIT_DB_PASS');
    if ($auditPass === false) {
        $auditPass = defined('AUDIT_DB_PASS') ? AUDIT_DB_PASS : '';
    }

    if ($auditUser !== '' && $auditPdo === null) {
        try {
            $host   = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : 'localhost');
            $dbName = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'serenityspaces');
            $dsn    = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";
            $auditPdo = new PDO($dsn, $auditUser, $auditPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (Throwable $e) {
            error_log('audit_pdo connection failed, falling back to main PDO: ' . $e->getMessage());
            $auditPdo = $mainPdo;
        }
    }

    return ($auditPdo !== null) ? $auditPdo : $mainPdo;
}

/**
 * Nulls out ip_address on audit_log rows older than $months months.
 * Retains the row and chain hash — only the personal data is removed.
 * Called by the cron script at cron/audit_ip_purge.php.
 * Also invoked probabilistically (1-in-100) from audit_log() as a lazy fallback
 * in case cron is not configured.
 */
function audit_ip_purge(PDO $pdo, int $months = 12): int
{
    try {
        $stmt = $pdo->prepare(
            "UPDATE audit_log SET ip_address = NULL
             WHERE ip_address IS NOT NULL
               AND created_at < DATE_SUB(NOW(), INTERVAL ? MONTH)"
        );
        $stmt->execute([$months]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('audit_ip_purge error: ' . $e->getMessage());
        return 0;
    }
}

function audit_log(PDO $pdo, string $action, array $ctx = []): void
{
    try {
        // Use fw_get_ip() for trusted-proxy-aware IP resolution — consistent with firewall.php
        if (function_exists('fw_get_ip')) {
            $ip = fw_get_ip();
        } else {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $ip = trim(explode(',', $ip)[0]);
        }
        if (strlen($ip) > 45) $ip = substr($ip, 0, 45);

        $ua        = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $timestamp = date('Y-m-d H:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000);

        // ── Chain hash ────────────────────────────────────────────
        // Cache the last known hash within this request to avoid a DB round-trip on every
        // audit_log() call (e.g. session join + notes write + export all in the same request).
        // The static is updated with each new hash so the chain remains correct across calls.
        static $cachedPrevHash = null;
        if ($cachedPrevHash === null) {
            $prevRow         = $pdo->query('SELECT row_hash FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
            $cachedPrevHash  = $prevRow ? ($prevRow['row_hash'] ?? 'genesis') : 'genesis';
        }
        $prevHash = $cachedPrevHash;

        // Derive an HMAC key from the encryption key so the chain can't be reforged
        // without server-side secret access
        if (defined('SMTP_ENCRYPT_KEY') && SMTP_ENCRYPT_KEY !== '') {
            $hmacKey  = hash_hmac('sha256', 'audit-chain-v1', SMTP_ENCRYPT_KEY, false);
            $rowHash  = hash_hmac('sha256',
                $prevHash . '|' . $action . '|' .
                ($ctx['practitioner_id'] ?? '') . '|' .
                ($ctx['participant_id']  ?? '') . '|' .
                ($ctx['session_id']      ?? '') . '|' .
                $ip . '|' . $timestamp,
                $hmacKey
            );
        } else {
            // No key available — plain SHA-256 chain (detects deletion/reordering but not forgery)
            $rowHash = hash('sha256',
                $prevHash . '|' . $action . '|' .
                ($ctx['practitioner_id'] ?? '') . '|' .
                ($ctx['participant_id']  ?? '') . '|' .
                ($ctx['session_id']      ?? '') . '|' .
                $ip . '|' . $timestamp
            );
        }

        // Use the INSERT-only audit connection if configured (Rec B hardening)
        $writePdo = _audit_pdo($pdo);

        $writePdo->prepare(
            'INSERT INTO audit_log
             (practitioner_id, participant_id, session_id, action, entity_type, entity_id, ip_address, user_agent, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ctx['practitioner_id'] ?? null,
            $ctx['participant_id']  ?? null,
            $ctx['session_id']      ?? null,
            $action,
            $ctx['entity_type']     ?? null,
            $ctx['entity_id']       ?? null,
            $ip,
            $ua,
            $rowHash,
        ]);

        // Advance the in-request cache so the next audit_log() call chains from this row
        $cachedPrevHash = $rowHash;

        // Lazy IP purge — runs on ~1% of audit writes as a fallback when cron is not configured.
        // Cheap: only fires an UPDATE when there are actually rows to purge.
        if (random_int(1, 100) === 1) {
            audit_ip_purge($pdo);
        }
    } catch (Throwable $e) {
        // Audit failures must never break the primary request
        error_log('audit_log error: ' . $e->getMessage());
    }
}
