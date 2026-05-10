<?php
/**
 * Serenity Spaces — Web Cron Trigger
 *
 * WordPress-style web-based cron runner. When system cron is not available,
 * this endpoint is called non-blocking on web requests via includes/cron_autotrigger.php.
 * It runs any overdue cron jobs directly.
 *
 * Only active when cron_mode = 'web' in settings.
 * Authenticated via cron_web_key setting — never expose this URL publicly.
 *
 * GET /cron/trigger.php?key=SECRET
 */

define('CRON_RUN', true);

require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../db/cron_logger.php';
require_once __DIR__ . '/../includes/security_headers.php';

// Only respond to web trigger mode
if (getSetting('cron_mode', 'system') !== 'web') {
    http_response_code(403);
    exit('Web cron mode is not enabled.');
}

// Validate secret key
$expectedKey = getSetting('cron_web_key', '');
$suppliedKey = trim($_GET['key'] ?? '');

if ($expectedKey === '' || !hash_equals($expectedKey, $suppliedKey)) {
    http_response_code(403);
    exit('Invalid key.');
}

$pdo = getDB();

// Advisory lock: prevent concurrent runs via DB GET_LOCK
// Lock name is per-site to avoid cross-instance collisions
$lockName = 'ss_cron_' . substr(md5($expectedKey), 0, 8);
$locked   = $pdo->query("SELECT GET_LOCK('{$lockName}', 0)")->fetchColumn();
if (!$locked) {
    http_response_code(200);
    exit('Already running.');
}

header('Content-Type: text/plain');
header('Cache-Control: no-store');
echo "Serenity Spaces Cron Trigger\n";

// ── Cron registry: name → interval in seconds ──────────────────
$registry = [
    'firewall_cleanup'     => 3600,
    'audit_ip_purge'       => 86400,
    'appointment_reminder' => 3600,
    'mfa_reminder'         => 86400,
];

$ran = [];

foreach ($registry as $name => $interval) {
    if (!cron_is_due($pdo, $name, $interval)) {
        echo "SKIP {$name} (not due)\n";
        continue;
    }

    echo "RUN  {$name}\n";
    ob_flush();
    flush();

    $scriptPath = __DIR__ . '/' . $name . '.php';
    if (!file_exists($scriptPath)) {
        echo "     ERROR: script not found\n";
        continue;
    }

    // Run in a separate PHP process so each script can define its own constants
    // and the lock is released even if a script crashes
    $escapedPath = escapeshellarg($scriptPath);
    $output = shell_exec(PHP_BINARY . " {$escapedPath} 2>&1");
    echo "     " . trim((string)$output) . "\n";
    $ran[] = $name;
}

// Release advisory lock
$pdo->query("SELECT RELEASE_LOCK('{$lockName}')");

echo "\nDone. Ran: " . (empty($ran) ? 'none' : implode(', ', $ran)) . "\n";
