<?php
/**
 * Serenity Spaces — Audit Log IP Address Purge
 *
 * Nulls out ip_address on audit_log rows older than 12 months.
 * The row, action, actor IDs, and chain hash are preserved — only the
 * personal data (IP address) is removed, satisfying GDPR storage limitation.
 *
 * Run via cPanel Cron Jobs — recommended schedule: once per day.
 *
 * cPanel cron command:
 *   /usr/bin/php /home/<username>/public_html/cron/audit_ip_purge.php >> /home/<username>/logs/audit_purge.log 2>&1
 *
 * This script is CLI-only and will exit if called over HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('CRON_RUN', true);

require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../db/audit.php';
require_once __DIR__ . '/../db/cron_logger.php';

$pdo   = getDB();
$ts    = date('Y-m-d H:i:s');
$logId = cron_start($pdo, 'audit_ip_purge');

try {
    $purged = audit_ip_purge($pdo, 12);
    $output = "[{$ts}] audit_ip_purge: nulled ip_address on {$purged} row(s) older than 12 months.";
    echo $output . "\n";
    cron_finish($pdo, $logId, 'success', $output, $purged);
} catch (Throwable $e) {
    $output = "[{$ts}] audit_ip_purge ERROR: " . $e->getMessage();
    echo $output . "\n";
    cron_finish($pdo, $logId, 'error', $output);
    exit(1);
}
