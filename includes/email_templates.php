<?php
/**
 * Serenity Spaces — Email Template System
 *
 * Provides default templates, DB seed/lookup, variable interpolation,
 * and a send_template_email() helper used throughout the app.
 *
 * Template keys:
 *   booking_request         — client submits a booking
 *   booking_confirmed       — practitioner confirms a session
 *   booking_rescheduled     — practitioner reschedules
 *   client_action_request   — client requests cancel/reschedule
 *   gdpr_request            — client submits data deletion request
 *   gdpr_complete           — admin processes deletion
 *   security_ip_blocked     — firewall blocks an IP
 *   smtp_test               — SMTP configuration test
 */

function get_default_email_templates(): array
{
    return [
        'booking_request' => [
            'name'      => 'Booking Request Received',
            'subject'   => 'Session Request Received — Serenity Spaces',
            'body_html' => '<p>Hello {{client_name}},</p>
<p>Your session request with <strong>{{practitioner_name}}</strong> has been received.</p>
<p><strong>Reference:</strong> #{{booking_ref}}<br>
   <strong>Requested time:</strong> {{scheduled_date}}</p>
<p>Your practitioner will review your request and confirm the session shortly. You will receive a separate confirmation email that includes your room access link.</p>
{{account_section}}<p>If you did not make this request, please contact us immediately.</p>
<hr style="border:none;border-top:1px solid rgba(255,255,255,0.1);margin:24px 0;">
<p style="font-size:11px;color:rgba(255,255,255,0.35);line-height:1.6;">
  <strong style="color:rgba(255,255,255,0.5);">Your privacy:</strong> The personal information you provided when booking is processed by Serenity Spaces to deliver your session. Session records are retained for 7 years from your last session in accordance with professional therapeutic record-keeping guidelines (BACP). You have the right to access, correct, or request deletion of your data at any time. For full details see our <a href="{{privacy_url}}" style="color:rgba(167,139,250,0.7);">Privacy Notice</a>.
</p>',
        ],
        'booking_confirmed' => [
            'name'      => 'Session Confirmed',
            'subject'   => 'Your Session is Confirmed — Serenity Spaces',
            'body_html' => '<p>Hello {{client_name}},</p>
<p>Your session with <strong>{{practitioner_name}}</strong> has been confirmed.</p>
<p><strong>Date &amp; time:</strong> {{scheduled_date}}<br>
   <strong>Room:</strong> {{room_name}}</p>
<p>To join your session, click the link below at the scheduled time:</p>
<p><a href="{{join_url}}" style="display:inline-block;padding:10px 24px;background:#7c6af7;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;">Join Session →</a></p>
<p style="font-size:12px;color:rgba(255,255,255,0.4);">Or copy this link: {{join_url}}</p>
<p>If you have any questions before your session, please contact your practitioner directly.</p>
<hr style="border:none;border-top:1px solid rgba(255,255,255,0.1);margin:24px 0;">
<p style="font-size:11px;color:rgba(255,255,255,0.35);line-height:1.6;">
  <strong style="color:rgba(255,255,255,0.5);">Your privacy:</strong> Your session records — including messages, clinical notes, and any files shared — are retained for 7 years from your last session in accordance with professional therapeutic record-keeping guidelines (BACP). This supports continuity of care and practitioner accountability. You have the right to access, correct, or request deletion of your personal data at any time. To exercise your rights or read our full Privacy Notice, visit <a href="{{privacy_url}}" style="color:rgba(167,139,250,0.7);">our Privacy Notice</a>.
</p>',
        ],
        'booking_rescheduled' => [
            'name'      => 'Session Rescheduled',
            'subject'   => 'Your Session Has Been Rescheduled — Serenity Spaces',
            'body_html' => '<p>Hello {{client_name}},</p>
<p>Your session with <strong>{{practitioner_name}}</strong> has been rescheduled.</p>
<p><strong>New date &amp; time:</strong> {{new_date}}</p>
<p>If you need to discuss this change or it is not convenient for you, please contact your practitioner as soon as possible.</p>',
        ],
        'client_action_request' => [
            'name'      => 'Client Cancel / Reschedule Request',
            'subject'   => 'Client {{action_type}} Request — Serenity Spaces',
            'body_html' => '<p>Hello {{practitioner_name}},</p>
<p>Your client <strong>{{client_name}}</strong> has submitted a <strong>{{action_type}}</strong> request for their session scheduled on <strong>{{scheduled_date}}</strong>.</p>
{{client_message_block}}
<p>Please log in to your dashboard to review and respond to this request.</p>',
        ],
        'intake_form' => [
            'name'      => 'Intake Form Request',
            'subject'   => 'Please Complete Your Intake Form — Serenity Spaces',
            'body_html' => '<p>Hello {{client_name}},</p>
<p>Your practitioner <strong>{{practitioner_name}}</strong> has sent you an intake form to complete before your session.</p>
<p><strong>Form:</strong> {{form_name}}</p>
<p>Please take a few minutes to complete it at your convenience:</p>
<p><a href="{{form_url}}" style="display:inline-block;padding:10px 24px;background:#7c6af7;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;">Complete Intake Form →</a></p>
<p style="font-size:12px;color:rgba(255,255,255,0.4);">Or copy this link: {{form_url}}</p>
<p>This form is confidential and will only be seen by your practitioner. If you did not expect this email, please contact your practitioner directly.</p>',
        ],
        'gdpr_request' => [
            'name'      => 'Data Deletion Request Received',
            'subject'   => 'Your Data Deletion Request — Serenity Spaces',
            'body_html' => '<p>Hello {{client_name}},</p>
<p>We have received your request to have your personal data deleted from Serenity Spaces.</p>
<p><strong>Request reference:</strong> #{{request_id}}<br>
   <strong>Submitted:</strong> {{submitted_date}}<br>
   <strong>Processing deadline:</strong> {{deadline_date}}</p>
<p>Under GDPR Article 12(3), we are required to process your request within <strong>30 days</strong>. In exceptional circumstances this may be extended by a further two months, in which case we will notify you.</p>
<p>Once your data has been deleted you will receive a confirmation email. If you did not make this request, please contact us immediately.</p>',
        ],
        'gdpr_complete' => [
            'name'      => 'Data Deletion Complete',
            'subject'   => 'Your Data Has Been Deleted — Serenity Spaces',
            'body_html' => '<p>Hello,</p>
<p>Your personal data deletion request (Reference #{{request_id}}) has been completed.</p>
<p>Your personal data — including your account, session history access, and uploaded files — has been removed from Serenity Spaces.</p>
<p>If you have any questions, please contact us directly.</p>',
        ],
        'security_ip_blocked' => [
            'name'      => 'Security Alert: IP Blocked',
            'subject'   => 'Security Alert: IP Blocked — Serenity Spaces',
            'body_html' => '<p>Hello,</p>
<p>A security event has been logged on your Serenity Spaces installation.</p>
<p><strong>IP Address:</strong> <code>{{ip_address}}</code><br>
   <strong>Blocked at:</strong> {{blocked_at}}<br>
   <strong>Reason:</strong> {{reason}}</p>
<p>This IP has been automatically blocked by the login firewall due to repeated failed authentication attempts.</p>
<p>If this was a legitimate user, you can unblock them from the <strong>Admin Panel → Security</strong> tab.</p>',
        ],
        'consent_withdrawal' => [
            'name'      => 'Client Consent Withdrawal',
            'subject'   => 'Client Consent Withdrawn — Action Required',
            'body_html' => '<p>Hello {{practitioner_name}},</p>
<p>Your client <strong>{{client_name}}</strong> has withdrawn their consent to the processing of special category personal data (gender, sexuality, and date of birth) under UK GDPR Article 7(3).</p>
<p><strong>Withdrawn at:</strong> {{withdrawn_at}}</p>
<p>This withdrawal is effective immediately. The special category data fields have been cleared from their account automatically. Please review any clinical notes or records that reference this data and handle them in accordance with your data management obligations.</p>
<p>Withdrawal does not affect the lawfulness of processing that occurred before the withdrawal date.</p>
<p>If you have questions about your obligations following a consent withdrawal, please refer to your professional body\'s guidance (BACP, UKCP, etc.) or seek advice from a qualified data protection professional.</p>',
        ],
        'smtp_test' => [
            'name'      => 'SMTP Test Email',
            'subject'   => 'Serenity Spaces — SMTP Test',
            'body_html' => '<p>Hello {{admin_name}},</p>
<p>This is a test email from your Serenity Spaces installation confirming that your SMTP configuration is working correctly.</p>
<p><strong>Sent at:</strong> {{sent_at}}</p>
<p>No further action is required.</p>',
        ],
        'practitioner_approved' => [
            'name'      => 'Account Approved',
            'subject'   => 'Your Serenity Spaces Account Has Been Approved',
            'body_html' => '<p>Hello {{practitioner_name}},</p>
<p>Great news — your Serenity Spaces practitioner account has been reviewed and <strong>approved</strong>.</p>
<p>You can now log in and complete your profile to start accepting client bookings.</p>
<p><a href="{{login_url}}" style="display:inline-block;padding:10px 24px;background:#7c6af7;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;">Log In →</a></p>
<p>If you have any questions, please contact the platform administrator.</p>',
        ],
        'practitioner_rejected' => [
            'name'      => 'Account Not Approved',
            'subject'   => 'Serenity Spaces — Account Application Update',
            'body_html' => '<p>Hello {{practitioner_name}},</p>
<p>Thank you for applying for a Serenity Spaces practitioner account.</p>
<p>After review, we are unable to approve your account at this time.</p>
{{rejection_reason_block}}
<p>If you believe this is an error or would like more information, please contact the platform administrator.</p>',
        ],
        'practitioner_pending' => [
            'name'      => 'Account Pending Review',
            'subject'   => 'Serenity Spaces — Account Pending Approval',
            'body_html' => '<p>Hello {{practitioner_name}},</p>
<p>Thank you for registering with Serenity Spaces.</p>
<p>Your account is currently <strong>pending review</strong> by our team. You will receive an email once your account has been reviewed.</p>
<p>If you have any questions, please contact the platform administrator.</p>',
        ],
    ];
}

/**
 * Seed any missing templates into the DB. Call on admin panel load.
 */
function seed_email_templates(PDO $pdo): void
{
    try {
        $defaults = get_default_email_templates();
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO email_templates (template_key, name, subject, body_html) VALUES (?,?,?,?)'
        );
        foreach ($defaults as $key => $tpl) {
            $stmt->execute([$key, $tpl['name'], $tpl['subject'], $tpl['body_html']]);
        }
    } catch (Throwable $e) {
        error_log('seed_email_templates: ' . $e->getMessage());
    }
}

/**
 * Fetch a single template from DB, falling back to the hardcoded default.
 * Returns ['subject' => '...', 'body_html' => '...'] or null.
 */
function get_email_template(PDO $pdo, string $key): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT subject, body_html FROM email_templates WHERE template_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row) return $row;
    } catch (Throwable $e) {
        // Table may not exist yet on fresh installs — silent fail
    }
    $defaults = get_default_email_templates();
    return isset($defaults[$key])
        ? ['subject' => $defaults[$key]['subject'], 'body_html' => $defaults[$key]['body_html']]
        : null;
}

/**
 * Replace {{var}} placeholders in a string.
 */
function render_template(string $str, array $vars): string
{
    foreach ($vars as $k => $v) {
        $str = str_replace('{{' . $k . '}}', (string)$v, $str);
    }
    return $str;
}

/**
 * Look up a template from DB/defaults, substitute vars, and send.
 * Returns true on success, false if template not found.
 */
function send_template_email(
    PDO    $pdo,
    string $tplKey,
    string $tplTitle,
    array  $vars,
    string $toEmail,
    string $toName = '',
    array  $attachments = []
): bool {
    $tpl = get_email_template($pdo, $tplKey);
    if (!$tpl) return false;

    $subject = render_template($tpl['subject'], $vars);
    $body    = emailTemplate($tplTitle, render_template($tpl['body_html'], $vars));

    sendEmail($toEmail, $toName, $subject, $body, $attachments);
    return true;
}

/**
 * Generate a minimal iCalendar (.ics) event string.
 *
 * @param string $dtStart    UTC datetime string parseable by strtotime()
 * @param int    $durationMin Duration in minutes (default 60)
 * @param string $summary    Event title
 * @param string $description Event description / body
 * @param string $url        Optional URL
 * @param string $uid        Unique event ID (e.g. booking token)
 * @return string Raw .ics content
 */
function ics_event(
    string $dtStart,
    int    $durationMin = 60,
    string $summary     = '',
    string $description = '',
    string $url         = '',
    string $uid         = ''
): string {
    $ts     = strtotime($dtStart);
    $tsEnd  = $ts + ($durationMin * 60);
    $start  = gmdate('Ymd\THis\Z', $ts);
    $end    = gmdate('Ymd\THis\Z', $tsEnd);
    $stamp  = gmdate('Ymd\THis\Z');
    $uid    = $uid ?: bin2hex(random_bytes(16));

    // Fold long lines per RFC 5545 (max 75 octets)
    $fold = function(string $line): string {
        $out = '';
        while (strlen($line) > 75) {
            $out  .= substr($line, 0, 75) . "\r\n ";
            $line  = substr($line, 75);
        }
        return $out . $line;
    };

    $esc = fn(string $s) => str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $s);

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//SerenitySpaces//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:REQUEST',
        'BEGIN:VEVENT',
        $fold('DTSTART:' . $start),
        $fold('DTEND:'   . $end),
        $fold('DTSTAMP:' . $stamp),
        $fold('UID:'     . $uid . '@serenityspaces'),
        $fold('SUMMARY:' . $esc($summary)),
    ];
    if ($description !== '') $lines[] = $fold('DESCRIPTION:' . $esc($description));
    if ($url          !== '') $lines[] = $fold('URL:'         . $esc($url));
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", $lines) . "\r\n";
}
