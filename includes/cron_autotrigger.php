<?php
/**
 * Serenity Spaces — Cron Auto-Trigger (Web Mode)
 *
 * Include this file in common page headers when cron_mode = 'web'.
 * On a small percentage of requests it fires a non-blocking HTTP request
 * to cron/trigger.php, mimicking WordPress's WP-Cron approach.
 *
 * Only fires when:
 *   1. cron_mode setting = 'web'
 *   2. There is at least one overdue cron (checked via cron_log)
 *   3. Random 1-in-5 chance (reduces overhead on high-traffic sites;
 *      overdue crons will still fire within a few requests)
 *
 * Usage: require_once __DIR__ . '/cron_autotrigger.php';
 *        cron_maybe_trigger();
 */

function cron_maybe_trigger(): void {
    try {
        if (getSetting('cron_mode', 'system') !== 'web') return;

        $key = getSetting('cron_web_key', '');
        if ($key === '') return;

        // Throttle: only check on ~20% of requests
        if (mt_rand(1, 5) !== 1) return;

        // Quick check: is anything overdue?
        $pdo = getDB();
        $registry = [
            'firewall_cleanup'     => 3600,
            'audit_ip_purge'       => 86400,
            'appointment_reminder' => 3600,
            'mfa_reminder'         => 86400,
        ];

        $anyDue = false;
        foreach ($registry as $name => $interval) {
            if (cron_is_due($pdo, $name, $interval)) {
                $anyDue = true;
                break;
            }
        }
        if (!$anyDue) return;

        // Build trigger URL using current host
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') return;

        $url = $scheme . '://' . $host . '/cron/trigger.php?key=' . urlencode($key);

        // Non-blocking fire-and-forget via fsockopen
        _cron_fire_nonblocking($url);

    } catch (Throwable $e) {
        // Never let cron trigger errors surface to users
    }
}

function _cron_fire_nonblocking(string $url): void {
    $parsed = parse_url($url);
    $scheme = $parsed['scheme'] ?? 'http';
    $host   = $parsed['host']   ?? '';
    $path   = ($parsed['path']  ?? '/');
    $query  = isset($parsed['query']) ? '?' . $parsed['query'] : '';
    $port   = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

    $socketHost = ($scheme === 'https' ? 'ssl://' : '') . $host;
    $fp = @fsockopen($socketHost, $port, $errno, $errstr, 2);
    if (!$fp) return;

    // Write request and immediately close — we don't wait for the response
    $request  = "GET {$path}{$query} HTTP/1.1\r\n";
    $request .= "Host: {$host}\r\n";
    $request .= "Connection: close\r\n\r\n";
    @fwrite($fp, $request);
    @fclose($fp);
}
