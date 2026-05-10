<?php
/**
 * Serenity Spaces — Email Templates API (Admin only)
 *
 * GET  ?action=list           — all templates
 * POST action=save            — save subject + body_html for a template
 * POST action=reset           — reset a template to its hardcoded default
 * POST action=preview         — render template with sample vars → returns HTML
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json');

// Admin only
if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$pdo     = getDB();
$practId = (int)$_SESSION['practitioner_id'];

$stmt = $pdo->prepare('SELECT is_admin FROM practitioners WHERE id = ? LIMIT 1');
$stmt->execute([$practId]);
$me = $stmt->fetch();
if (empty($me['is_admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : (json_decode(file_get_contents('php://input'), true)['action'] ?? '');

$input = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : [];

// ── List all templates ────────────────────────────────────────────
if ($action === 'list') {
    seed_email_templates($pdo);
    $rows = $pdo->query(
        'SELECT id, template_key, name, subject, body_html, updated_at FROM email_templates ORDER BY name ASC'
    )->fetchAll();
    echo json_encode($rows);
    exit;
}

// ── Save a template ───────────────────────────────────────────────
if ($action === 'save') {
    $key     = trim($input['template_key'] ?? '');
    $subject = trim($input['subject'] ?? '');
    $body    = trim($input['body_html'] ?? '');

    if (!$key || !$subject || !$body) {
        http_response_code(400);
        echo json_encode(['error' => 'template_key, subject, and body_html required']);
        exit;
    }

    // Verify key exists
    $defaults = get_default_email_templates();
    if (!isset($defaults[$key])) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown template key']);
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO email_templates (template_key, name, subject, body_html)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE subject = VALUES(subject), body_html = VALUES(body_html), updated_at = NOW()'
    );
    $stmt->execute([$key, $defaults[$key]['name'], $subject, $body]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── Reset a template to default ────────────────────────────────────
if ($action === 'reset') {
    $key = trim($input['template_key'] ?? '');
    $defaults = get_default_email_templates();

    if (!isset($defaults[$key])) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown template key']);
        exit;
    }

    $d = $defaults[$key];
    $stmt = $pdo->prepare(
        'INSERT INTO email_templates (template_key, name, subject, body_html)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE subject = VALUES(subject), body_html = VALUES(body_html), updated_at = NOW()'
    );
    $stmt->execute([$key, $d['name'], $d['subject'], $d['body_html']]);

    echo json_encode(['ok' => true, 'subject' => $d['subject'], 'body_html' => $d['body_html']]);
    exit;
}

// ── Preview a template ────────────────────────────────────────────
if ($action === 'preview') {
    $key  = trim($input['template_key'] ?? '');
    $subj = trim($input['subject'] ?? '');
    $body = trim($input['body_html'] ?? '');

    // Sample variables for preview substitution
    $sampleVars = [
        'client_name'         => 'Alex Morgan',
        'practitioner_name'   => 'Dr. Jordan Lee',
        'booking_ref'         => '8472',
        'scheduled_date'      => 'Thursday, 17 April 2026 at 2:00 PM',
        'new_date'            => 'Monday, 21 April 2026 at 10:00 AM',
        'join_url'            => rtrim(getSetting('app_url', (isset($_SERVER['HTTPS'])?'https':'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.example')), '/') . '/room.php?token=preview',
        'room_name'           => 'Calm Room A',
        'request_id'          => '42',
        'submitted_date'      => 'Wednesday, 16 April 2026',
        'deadline_date'       => 'Friday, 16 May 2026',
        'action_type'         => 'reschedule',
        'client_message_block'=> '<p><em>Client note: "Can we move to next week? I have a conflict."</em></p>',
        'ip_address'          => '203.0.113.55',
        'blocked_at'          => date('F j, Y \a\t g:i A') . ' UTC',
        'reason'              => 'Exceeded 5 failed login attempts',
        'admin_name'          => 'Admin',
        'sent_at'             => date('Y-m-d H:i:s') . ' UTC',
    ];

    $renderedBody = render_template($body, $sampleVars);
    header('Content-Type: application/json');
    echo json_encode([
        'subject' => render_template($subj, $sampleVars),
        'html'    => emailTemplate('Preview: ' . htmlspecialchars($subj), $renderedBody),
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
