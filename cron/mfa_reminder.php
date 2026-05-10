<?php
/**
 * Serenity Spaces — MFA Enforcement Reminder
 *
 * When enforce_practitioner_mfa is enabled, sends an email reminder to any
 * practitioner who has not yet configured MFA and whose grace period expires
 * within the next 48 hours. Each practitioner receives at most one reminder
 * (tracked via the mfa_reminder_sent_at column added at runtime if missing).
 *
 * Recommended schedule: daily (runs once per day via the cron system).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('CRON_RUN', true);

require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../db/cron_logger.php';
require_once __DIR__ . '/../includes/mailer.php';

$pdo   = getDB();
$ts    = date('Y-m-d H:i:s');
$logId = cron_start($pdo, 'mfa_reminder');

$lines  = [];
$sent   = 0;
$errors = 0;

try {
    // Only run when enforcement is active
    if (getSetting('enforce_practitioner_mfa', '0') !== '1') {
        $lines[] = "[{$ts}] mfa_reminder: enforcement not enabled — skipped.";
        cron_finish($pdo, $logId, implode("\n", $lines));
        exit(0);
    }

    $enabledAt = getSetting('mfa_enforce_enabled_at', '');
    $graceDays = max(1, (int)getSetting('mfa_grace_days', '7'));

    if (!$enabledAt) {
        $lines[] = "[{$ts}] mfa_reminder: enforce_enabled_at not set — skipped.";
        cron_finish($pdo, $logId, implode("\n", $lines));
        exit(0);
    }

    $deadline    = (new DateTime($enabledAt))->modify("+{$graceDays} days");
    $reminderCutoff = (new DateTime())->modify('+48 hours');

    // Only send reminders when we're within 48h of the deadline
    if ($reminderCutoff < $deadline) {
        $deadlineStr = $deadline->format('F j, Y');
        $daysLeft = (int)(new DateTime())->diff($deadline)->days;
        $lines[] = "[{$ts}] mfa_reminder: deadline is {$deadlineStr} ({$daysLeft} days away) — no reminder needed yet.";
        cron_finish($pdo, $logId, implode("\n", $lines));
        exit(0);
    }

    // Ensure column exists (graceful — silently skip if ALTER fails)
    try {
        $pdo->exec("ALTER TABLE practitioners ADD COLUMN mfa_reminder_sent_at DATETIME DEFAULT NULL");
    } catch (Throwable) {}

    $platformName = getSetting('app_name', 'Serenity Spaces');
    $platformUrl  = rtrim(getSetting('platform_url', ''), '/');
    $deadlineStr  = $deadline->format('l, F j, Y');

    // Find active practitioners without MFA who haven't been reminded yet
    $stmt = $pdo->query(
        "SELECT id, email, display_name FROM practitioners
         WHERE (mfa_secret IS NULL OR mfa_secret = '')
           AND account_status = 'active'
           AND (mfa_reminder_sent_at IS NULL)
         ORDER BY id ASC"
    );
    $practitioners = $stmt->fetchAll();

    if (empty($practitioners)) {
        $lines[] = "[{$ts}] mfa_reminder: no practitioners to remind.";
    }

    foreach ($practitioners as $p) {
        $name  = $p['display_name'] ?: 'Practitioner';
        $email = $p['email'];
        if (!$email) continue;

        $mfaUrl = ($platformUrl ?: '') . '/profile.php?section=mfa';

        $subject = "{$platformName}: Action required — set up two-factor authentication by {$deadlineStr}";
        $body    = "
            <p>Hi " . htmlspecialchars($name) . ",</p>
            <p>This is a reminder that <strong>two-factor authentication (MFA) is now required</strong> for all practitioner accounts on " . htmlspecialchars($platformName) . ".</p>
            <p>You must set up MFA by <strong>" . htmlspecialchars($deadlineStr) . "</strong>. After that date, you will be redirected to the MFA setup page every time you log in until it is configured.</p>
            <p>MFA protects your account and the sensitive client data you have access to. It takes less than two minutes to set up using any authenticator app (Google Authenticator, Authy, etc.).</p>
            <p style='margin-top:20px;'>
              <a href='" . htmlspecialchars($mfaUrl) . "' style='background:#7c6af7;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-block;'>
                Set Up MFA Now
              </a>
            </p>
            <p style='margin-top:24px;color:#888;font-size:13px;'>Log in to your dashboard, go to Profile → Security, and enable the authenticator app option.</p>";

        $ok = sendEmail($email, $name, $subject, emailTemplate('MFA Reminder', $body));
        if ($ok) {
            $pdo->prepare("UPDATE practitioners SET mfa_reminder_sent_at = NOW() WHERE id = ?")
                ->execute([(int)$p['id']]);
            $lines[] = "[{$ts}] mfa_reminder: reminder sent to {$email} ({$name})";
            $sent++;
        } else {
            $lines[] = "[{$ts}] mfa_reminder: FAILED to send to {$email}";
            $errors++;
        }
    }

} catch (Throwable $e) {
    $lines[] = "[{$ts}] mfa_reminder: ERROR — " . $e->getMessage();
    $errors++;
}

$lines[] = "[{$ts}] mfa_reminder: done. sent={$sent} errors={$errors}";
cron_finish($pdo, $logId, implode("\n", $lines));

echo implode("\n", $lines) . "\n";
exit($errors > 0 ? 1 : 0);
