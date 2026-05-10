<?php
/**
 * Serenity Spaces — Intake Forms API
 *
 * Practitioner (session required):
 *   GET  action=list          — list practitioner's forms (with response counts)
 *   POST action=save          — create or update a form (CSRF)
 *   POST action=delete        — delete a form (CSRF)
 *   POST action=assign        — assign a form to a booking, generate token, email client (CSRF)
 *   GET  action=response      — get intake response for a booking_id (CSRF via param)
 *   GET  action=booking_status — check intake status for multiple booking IDs
 *
 * Public (no session):
 *   GET  action=form           — load form structure for an intake_token
 *   POST action=submit         — submit responses for an intake_token
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_once __DIR__ . '/../includes/phi_crypto.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Auth helpers ─────────────────────────────────────────────
function ifRequirePractitioner(): ?int {
    return isset($_SESSION['practitioner_id']) ? (int)$_SESSION['practitioner_id'] : null;
}
function requirePractitionerIntake(): int {
    if (empty($_SESSION['practitioner_id'])) {
        http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit;
    }
    return (int)$_SESSION['practitioner_id'];
}
function verifyCsrfIntake(): void {
    if (!validate_csrf($_POST['csrf_token'] ?? $_GET['csrf'] ?? '')) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'CSRF token mismatch']); exit;
    }
}

// ── Practitioner: list forms ─────────────────────────────────
if ($action === 'list' && $method === 'GET') {
    $pid = requirePractitionerIntake();
    $stmt = $pdo->prepare(
        "SELECT f.*, COUNT(r.id) AS response_count
         FROM intake_forms f
         LEFT JOIN intake_responses r ON r.form_id = f.id
         LEFT JOIN bookings b ON b.id = r.booking_id AND b.practitioner_id = ?
         WHERE f.practitioner_id = ?
         GROUP BY f.id
         ORDER BY f.created_at DESC"
    );
    $stmt->execute([$pid, $pid]);
    $forms = $stmt->fetchAll();
    // Decode fields JSON for each form
    foreach ($forms as &$f) {
        $f['fields'] = json_decode($f['fields'] ?? '[]', true) ?: [];
    }
    echo json_encode(['ok' => true, 'forms' => $forms]);
    exit;
}

// ── Practitioner: save form ──────────────────────────────────
if ($action === 'save' && $method === 'POST') {
    $pid = requirePractitionerIntake();
    verifyCsrfIntake();

    $id       = (int)($_POST['id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $fieldsRaw = $_POST['fields'] ?? '[]';
    $isActive = !empty($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Form name is required']); exit;
    }

    $fields = json_decode($fieldsRaw, true);
    if (!is_array($fields)) $fields = [];

    // Sanitise fields: ensure each has required keys
    $sanitised = [];
    foreach ($fields as $f) {
        if (empty($f['label'])) continue;
        $type = in_array($f['type'] ?? '', ['text','textarea','select','radio','checkbox']) ? $f['type'] : 'text';
        $entry = [
            'id'       => $f['id'] ?? ('f_' . bin2hex(random_bytes(4))),
            'type'     => $type,
            'label'    => substr(trim($f['label']), 0, 500),
            'required' => !empty($f['required']),
        ];
        if (in_array($type, ['select','radio']) && !empty($f['options']) && is_array($f['options'])) {
            $entry['options'] = array_values(array_filter(array_map('trim', $f['options'])));
        }
        $sanitised[] = $entry;
    }

    $fieldsJson = json_encode($sanitised, JSON_UNESCAPED_UNICODE);

    if ($id > 0) {
        $check = $pdo->prepare('SELECT id FROM intake_forms WHERE id = ? AND practitioner_id = ? LIMIT 1');
        $check->execute([$id, $pid]);
        if (!$check->fetch()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Form not found']); exit; }
        $pdo->prepare('UPDATE intake_forms SET name=?, fields=?, is_active=? WHERE id=? AND practitioner_id=?')
            ->execute([$name, $fieldsJson, $isActive, $id, $pid]);
    } else {
        $pdo->prepare('INSERT INTO intake_forms (practitioner_id, name, fields, is_active) VALUES (?,?,?,?)')
            ->execute([$pid, $name, $fieldsJson, $isActive]);
        $id = (int)$pdo->lastInsertId();
    }

    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ── Practitioner: delete form ────────────────────────────────
if ($action === 'delete' && $method === 'POST') {
    $pid = requirePractitionerIntake();
    verifyCsrfIntake();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM intake_forms WHERE id = ? AND practitioner_id = ?');
    $stmt->execute([$id, $pid]);
    if ($stmt->rowCount() === 0) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Form not found']); exit; }
    echo json_encode(['ok' => true]);
    exit;
}

// ── Practitioner: assign form to booking ─────────────────────
if ($action === 'assign' && $method === 'POST') {
    $pid = requirePractitionerIntake();
    verifyCsrfIntake();

    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $formId    = (int)($_POST['form_id'] ?? 0);

    // Verify booking ownership
    $bStmt = $pdo->prepare(
        'SELECT b.*, r.name AS room_name FROM bookings b
         LEFT JOIN rooms r ON r.id = b.room_id
         WHERE b.id = ? AND b.practitioner_id = ? LIMIT 1'
    );
    $bStmt->execute([$bookingId, $pid]);
    $booking = $bStmt->fetch();
    if (!$booking) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Booking not found']); exit; }
    phi_decrypt_booking($booking);

    // Verify form ownership
    $fStmt = $pdo->prepare('SELECT id, name FROM intake_forms WHERE id = ? AND practitioner_id = ? AND is_active = 1 LIMIT 1');
    $fStmt->execute([$formId, $pid]);
    $form = $fStmt->fetch();
    if (!$form) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Form not found']); exit; }

    // Generate token
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE bookings SET intake_form_id = ?, intake_token = ? WHERE id = ?')
        ->execute([$formId, $token, $bookingId]);

    // Send email to client if they have one
    $guestEmail = $booking['guest_email'] ?? '';
    $guestName  = $booking['guest_name']  ?? 'Client';
    if ($guestEmail) {
        $practStmt = $pdo->prepare('SELECT display_name FROM practitioners WHERE id = ? LIMIT 1');
        $practStmt->execute([$pid]);
        $practName = $practStmt->fetchColumn() ?: 'Your practitioner';

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl  = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $formUrl  = $baseUrl . '/intake.php?token=' . $token;

        send_template_email($pdo, 'intake_form', 'Intake Form Request', [
            'client_name'       => $guestName,
            'practitioner_name' => $practName,
            'form_name'         => $form['name'],
            'form_url'          => $formUrl,
        ], $guestEmail, $guestName);
    }

    echo json_encode(['ok' => true, 'token' => $token, 'emailed' => (bool)$guestEmail]);
    exit;
}

// ── Practitioner: get response for a booking ─────────────────
if ($action === 'response' && $method === 'GET') {
    $pid = requirePractitionerIntake();
    $bookingId = (int)($_GET['booking_id'] ?? 0);
    // Verify booking belongs to this practitioner
    $bCheck = $pdo->prepare('SELECT id FROM bookings WHERE id = ? AND practitioner_id = ? LIMIT 1');
    $bCheck->execute([$bookingId, $pid]);
    if (!$bCheck->fetch()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }

    $stmt = $pdo->prepare(
        'SELECT r.*, f.name AS form_name, f.fields
         FROM intake_responses r
         JOIN intake_forms f ON f.id = r.form_id
         WHERE r.booking_id = ? ORDER BY r.submitted_at DESC LIMIT 1'
    );
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch();
    if (!$row) { echo json_encode(['ok'=>true,'response'=>null]); exit; }
    $row['fields']    = json_decode($row['fields'] ?? '[]', true) ?: [];
    $row['responses'] = json_decode(phi_decrypt($row['responses'] ?? '', 'intake_responses:' . $bookingId), true) ?: [];
    echo json_encode(['ok' => true, 'response' => $row]);
    exit;
}

// ── Practitioner: batch booking intake status ─────────────────
if ($action === 'booking_status' && $method === 'GET') {
    $pid = requirePractitionerIntake();
    // Returns intake_form_id and whether a response exists for each booking
    $ids = array_filter(array_map('intval', explode(',', $_GET['booking_ids'] ?? '')));
    if (empty($ids)) { echo json_encode(['ok'=>true,'statuses'=>[]]); exit; }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT b.id AS booking_id, b.intake_form_id, b.intake_token,
                IF(r.id IS NOT NULL, 1, 0) AS response_received
         FROM bookings b
         LEFT JOIN intake_responses r ON r.booking_id = b.id
         WHERE b.id IN ({$placeholders}) AND b.practitioner_id = ?"
    );
    $stmt->execute(array_merge($ids, [$pid]));
    $statuses = [];
    foreach ($stmt->fetchAll() as $row) {
        $statuses[(int)$row['booking_id']] = [
            'intake_form_id'    => $row['intake_form_id'],
            'intake_token'      => $row['intake_token'],
            'response_received' => (bool)$row['response_received'],
        ];
    }
    echo json_encode(['ok' => true, 'statuses' => $statuses]);
    exit;
}

// ── Public: get form structure for a token ───────────────────
if ($action === 'form' && $method === 'GET') {
    $token = trim($_GET['token'] ?? '');
    if (!$token) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Token required']); exit; }

    $stmt = $pdo->prepare(
        'SELECT b.id AS booking_id, b.intake_form_id, b.guest_name,
                f.name AS form_name, f.fields, f.practitioner_id,
                p.display_name AS practitioner_name
         FROM bookings b
         JOIN intake_forms f ON f.id = b.intake_form_id
         JOIN practitioners p ON p.id = f.practitioner_id
         WHERE b.intake_token = ? LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Form not found or expired']); exit; }

    // Check if already submitted
    $rCheck = $pdo->prepare('SELECT id FROM intake_responses WHERE booking_id = ? LIMIT 1');
    $rCheck->execute([$row['booking_id']]);
    $alreadySubmitted = (bool)$rCheck->fetch();

    echo json_encode([
        'ok'                => true,
        'form_name'         => $row['form_name'],
        'practitioner_name' => $row['practitioner_name'],
        'guest_name'        => phi_decrypt($row['guest_name'] ?? ''),
        'fields'            => json_decode($row['fields'] ?? '[]', true) ?: [],
        'already_submitted' => $alreadySubmitted,
        'booking_id'        => (int)$row['booking_id'],
        'form_id'           => (int)$row['intake_form_id'],
    ]);
    exit;
}

// ── Public: submit form response ─────────────────────────────
if ($action === 'submit' && $method === 'POST') {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($ct, 'application/json')
        ? (json_decode(file_get_contents('php://input'), true) ?? [])
        : $_POST;

    $token     = trim($input['token'] ?? '');
    $responses = $input['responses'] ?? [];

    if (!$token) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Token required']); exit; }
    if (!is_array($responses)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid responses']); exit; }

    $stmt = $pdo->prepare(
        'SELECT b.id AS booking_id, b.intake_form_id
         FROM bookings b
         WHERE b.intake_token = ? AND b.intake_form_id IS NOT NULL LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Token not found']); exit; }

    // Prevent duplicate submission
    $rCheck = $pdo->prepare('SELECT id FROM intake_responses WHERE booking_id = ? LIMIT 1');
    $rCheck->execute([$row['booking_id']]);
    if ($rCheck->fetch()) { http_response_code(409); echo json_encode(['ok'=>false,'error'=>'Already submitted']); exit; }

    // Sanitise response values (strings/booleans only)
    $clean = [];
    foreach ($responses as $key => $val) {
        $clean[(string)$key] = is_bool($val) ? $val : (string)$val;
    }

    $pdo->prepare(
        'INSERT INTO intake_responses (booking_id, form_id, responses) VALUES (?,?,?)'
    )->execute([$row['booking_id'], $row['intake_form_id'],
        phi_encrypt(json_encode($clean), 'intake_responses:' . $row['booking_id'])]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action or method']);
