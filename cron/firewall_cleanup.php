<?php
/**
 * Serenity Spaces — Firewall Table Cleanup
 *
 * Removes expired temporary IP blocks and stale login attempt records.
 * Keeps the ip_blocks and login_attempts tables lean without relying on the
 * probabilistic inline cleanup in firewall.php.
 *
 * Run via cPanel Cron Jobs — recommended schedule: hourly.
 *
 * cPanel cron command:
 *   /usr/bin/php /home/<username>/public_html/cron/firewall_cleanup.php >> /home/<username>/logs/firewall_cleanup.log 2>&1
 *
 * This script is CLI-only and will exit if called over HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('CRON_RUN', true);

require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../db/cron_logger.php';

$pdo   = getDB();
$ts    = date('Y-m-d H:i:s');
$logId = cron_start($pdo, 'firewall_cleanup');

try {
    // Remove expired temporary blocks
    $stmt = $pdo->prepare(
        "DELETE FROM ip_blocks
         WHERE block_type = 'temp'
           AND expires_at IS NOT NULL
           AND expires_at < NOW()"
    );
    $stmt->execute();
    $expiredBlocks = $stmt->rowCount();

    // Remove login attempt records older than 24 hours
    $stmt = $pdo->prepare(
        "DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 24 HOUR"
    );
    $stmt->execute();
    $staleAttempts = $stmt->rowCount();

    $total  = $expiredBlocks + $staleAttempts;
    $output = "[{$ts}] firewall_cleanup: removed {$expiredBlocks} expired temp block(s), {$staleAttempts} stale attempt record(s).";
    echo $output . "\n";
    cron_finish($pdo, $logId, 'success', $output, $total);

} catch (Throwable $e) {
    $output = "[{$ts}] firewall_cleanup ERROR: " . $e->getMessage();
    echo $output . "\n";
    cron_finish($pdo, $logId, 'error', $output);
    exit(1);
}
