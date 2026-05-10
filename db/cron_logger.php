<?php
/**
 * Serenity Spaces — Cron Job Logging Helpers
 *
 * Provides cron_start() / cron_finish() for recording run history
 * in the cron_log table, and cron_is_due() for the web-trigger mode.
 */

/**
 * Begin a cron run. Returns the log row ID.
 * Also clears any stuck 'running' rows older than 10 minutes.
 */
function cron_start(PDO $pdo, string $name): int {
    // Clear stuck locks from a previous crash
    $pdo->prepare(
        "UPDATE cron_log
         SET status = 'error',
             output = CONCAT(COALESCE(output,''), '\n[aborted: exceeded 10 min lock]'),
             completed_at = NOW(3)
         WHERE cron_name = ? AND status = 'running' AND started_at < NOW() - INTERVAL 10 MINUTE"
    )->execute([$name]);

    $stmt = $pdo->prepare(
        "INSERT INTO cron_log (cron_name, started_at, status) VALUES (?, NOW(3), 'running')"
    );
    $stmt->execute([$name]);
    return (int)$pdo->lastInsertId();
}

/**
 * Finish a cron run.
 *
 * @param string   $status       'success' | 'error'
 * @param string   $output       Human-readable log lines
 * @param int|null $rowsAffected Number of records processed (optional)
 */
function cron_finish(PDO $pdo, int $logId, string $status, string $output, ?int $rowsAffected = null): void {
    $pdo->prepare(
        "UPDATE cron_log
         SET status        = ?,
             output        = ?,
             rows_affected = ?,
             completed_at  = NOW(3),
             duration_ms   = TIMESTAMPDIFF(MICROSECOND, started_at, NOW(3)) DIV 1000
         WHERE id = ?"
    )->execute([$status, $output, $rowsAffected, $logId]);
}

/**
 * Returns true if the cron is due to run.
 * Due = never run successfully, or last success was >= $intervalSeconds ago.
 */
function cron_is_due(PDO $pdo, string $name, int $intervalSeconds): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT started_at FROM cron_log
             WHERE cron_name = ? AND status = 'success'
             ORDER BY started_at DESC LIMIT 1"
        );
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        if (!$row) return true;
        return (time() - strtotime($row['started_at'])) >= $intervalSeconds;
    } catch (Throwable $e) {
        return true; // if table missing, consider it due
    }
}
