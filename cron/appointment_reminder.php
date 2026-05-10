<?php
/**
 * Serenity Spaces — Appointment Reminder Emails
 *
 * Sends 24-hour reminder emails to practitioners and clients for upcoming
 * scheduled bookings. Runs the 23–25 hour window to give a comfortable margin
 * against cron timing drift.
 *
 * Run via cPanel Cron Jobs — recommended schedule: hourly.
 *
 * cPanel cron command:
 *   /usr/bin/php /home/<username>/public_html/cron/appointment_reminder.php >> /home/<username>/logs/appointment_reminder.log 2>&1
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
require_once __DIR__ . '/../includes/mailer.php';

$pdo   = getDB();
$ts    = date('Y-m-d H:i:s');
$logId = cron_start($pdo, 'appointment_reminder');

$platformName = getSetting('platform_name', 'Serenity Spaces');
$platformUrl  = rtrim(getSetting('platform_url', ''), '/');
$lines        = [];
$sent         = 0;
$errors       = 0;

try {
    // Find bookings in the 23–25 hour window that haven't been reminded yet
    $stmt = $pdo->prepare(
        "SELECT
            b.id,
            b.scheduled_at,
            b.duration_minutes,
            b.guest_name,
            b.guest_email,
            b.end_user_id,
            b.practitioner_id,
            p.email        AS pract_email,
            p.display_name AS pract_name,
            p.timezone     AS pract_timezone,
            eu.email       AS client_email,
            eu.display_name AS client_name,
            par.join_token
         FROM bookings b
         JOIN practitioners p ON p.id = b.practitioner_id
         LEFT JOIN end_users eu  ON eu.id = b.end_user_id
         LEFT JOIN participants par ON par.id = b.participant_id
         WHERE b.status = 'scheduled'
           AND b.scheduled_at BETWEEN DATE_ADD(NOW(), INTERVAL 23 HOUR)
                                  AND DATE_ADD(NOW(), INTERVAL 25 HOUR)
           AND b.reminder_24h_sent_at IS NULL"
    );
    $stmt->execute();
    $bookings = $stmt->fetchAll();

    if (empty($bookings)) {
        $lines[] = "[{$ts}] appointment_reminder: no upcoming bookings in the 24h window.";
    }

    foreach ($bookings as $b) {
        $bookingId    = (int)$b['id'];
        $practName    = $b['pract_name'] ?: 'Your practitioner';
        $practEmail   = $b['pract_email'];
        $timezone     = $b['pract_timezone'] ?: 'UTC';

        // Resolve client name/email (registered or guest)
        $clientName  = $b['client_name']  ?: $b['guest_name']  ?: 'Your client';
        $clientEmail = $b['client_email'] ?: $b['guest_email'] ?: '';

        // Format appointment time in practitioner's timezone
        try {
            $dt = new DateTime($b['scheduled_at'], new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($timezone));
            $apptDisplay = $dt->format('l, F j, Y \a\t g:i A T');
        } catch (Throwable $e) {
            $apptDisplay = $b['scheduled_at'] . ' UTC';
        }

        $duration = (int)$b['duration_minutes'];
        $joinUrl  = '';
        if ($b['join_token'] && $platformUrl) {
            $joinUrl = $platformUrl . '/room.php?join_token=' . urlencode($b['join_token']);
        }

        // ── Email to Practitioner ──────────────────────────────────
        $practSubject = "Appointment Reminder: {$clientName} — {$apptDisplay}";
        $practBody    = "
            <p>This is a reminder that you have an upcoming appointment.</p>
            <table style='border-collapse:collapse;width:100%;max-width:480px;'>
              <tr><td style='padding:8px 0;color:#888;font-size:13px;'>Client</td>
                  <td style='padding:8px 0;font-weight:600;'>" . htmlspecialchars($clientName) . "</td></tr>
              <tr><td style='padding:8px 0;color:#888;font-size:13px;'>Date &amp; Time</td>
                  <td style='padding:8px 0;font-weight:600;'>" . htmlspecialchars($apptDisplay) . "</td></tr>
              <tr><td style='padding:8px 0;color:#888;font-size:13px;'>Duration</td>
                  <td style='padding:8px 0;'>{$duration} minutes</td></tr>
            </table>" .
            ($joinUrl ? "<p style='margin-top:20px;'><a href='" . htmlspecialchars($joinUrl) . "' style='background:#7c6af7;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-block;'>Open Session Room</a></p>" : '') . "
            <p style='margin-top:24px;color:#888;font-size:13px;'>Log in to your dashboard to manage this appointment.</p>";

        $practOk = sendEmail($practEmail, $practName, $practSubject, emailTemplate('Appointment Reminder', $practBody));

        // ── Email to Client ─────────────────────────────────────────
        $clientOk = true;
        if ($clientEmail) {
            $clientSubject = "Your Appointment with {$practName} — {$apptDisplay}";
            $clientBody    = "
                <p>This is a reminder about your upcoming appointment.</p>
                <table style='border-collapse:collapse;width:100%;max-width:480px;'>
                  <tr><td style='padding:8px 0;color:#888;font-size:13px;'>Practitioner</td>
                      <td style='padding:8px 0;font-weight:600;'>" . htmlspecialchars($practName) . "</td></tr>
                  <tr><td style='padding:8px 0;color:#888;font-size:13px;'>Date &amp; Time</td>
                      <td style='padding:8px 0;font-weight:600;'>" . htmlspecialchars($apptDisplay) . "</td></tr>
                  <tr><td style='padding:8px 0;color:#888;font-size:13px;'>Duration</td>
                      <td style='padding:8px 0;'>{$duration} minutes</td></tr>
                </table>" .
                ($joinUrl ? "<p style='margin-top:20px;'><a href='" . htmlspecialchars($joinUrl) . "' style='background:#7c6af7;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-block;'>Join Your Session</a></p>" : '') . "
                <p style='margin-top:24px;color:#888;font-size:13px;'>If you need to reschedule or have questions, contact your practitioner directly.</p>";

            $clientOk = sendEmail($clientEmail, $clientName, $clientSubject, emailTemplate('Appointment Reminder', $clientBody));
        }

        // ── Mark reminder sent ──────────────────────────────────────
        try {
            $pdo->prepare(
                "UPDATE bookings SET reminder_24h_sent_at = NOW() WHERE id = ?"
            )->execute([$bookingId]);
        } catch (Throwable $e) {
            // Column may not exist on pre-migration installs; log and continue
            $lines[] = "[{$ts}] appointment_reminder WARNING: could not mark reminder_24h_sent_at for booking #{$bookingId} — " . $e->getMessage();
        }

        $statusStr = ($practOk ? 'pract:ok' : 'pract:fail') . ' ' . ($clientEmail ? ($clientOk ? 'client:ok' : 'client:fail') : 'client:no-email');
        $lines[]   = "[{$ts}] appointment_reminder: booking #{$bookingId} ({$apptDisplay}) — {$statusStr}";

        if ($practOk && ($clientEmail === '' || $clientOk)) {
            $sent++;
        } else {
            $errors++;
        }
    }

    $output = implode("\n", $lines);
    echo $output . "\n";
    cron_finish($pdo, $logId, $errors === 0 ? 'success' : 'error', $output, $sent);

} catch (Throwable $e) {
    $output = "[{$ts}] appointment_reminder ERROR: " . $e->getMessage();
    echo $output . "\n";
    cron_finish($pdo, $logId, 'error', $output);
    exit(1);
}
