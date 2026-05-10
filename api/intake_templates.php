<?php
/**
 * Serenity Spaces — Intake Form Template API
 *
 * Practitioner session required for all actions.
 *
 * GET  action=list                         — list templates (filtered by role_type if ?role= supplied)
 * POST action=save {id?,name,role_type,fields}  — create or update a template
 * POST action=delete {id}                  — delete own template (cannot delete system defaults)
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();

require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

header('Content-Type: application/json');

// Practitioner must be logged in
if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$practId = (int)$_SESSION['practitioner_id'];
$pdo     = getDB();

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: list ────────────────────────────────────────────────────
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    if ($action !== 'list') {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    }

    $roleFilter = trim($_GET['role'] ?? '');

    if ($roleFilter && in_array($roleFilter, ['therapist','counselor','coach','philosophical_counselor','peer_support','pastoral_counselor','spiritual_director','any'], true)) {
        $stmt = $pdo->prepare(
            'SELECT id, name, role_type, fields, is_default, created_by, created_at
             FROM intake_form_templates
             WHERE (role_type = ? OR role_type = \'any\')
               AND (is_default = 1 OR created_by = ?)
             ORDER BY is_default DESC, created_at DESC'
        );
        $stmt->execute([$roleFilter, $practId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, name, role_type, fields, is_default, created_by, created_at
             FROM intake_form_templates
             WHERE is_default = 1 OR created_by = ?
             ORDER BY is_default DESC, role_type ASC, name ASC'
        );
        $stmt->execute([$practId]);
    }

    $templates = $stmt->fetchAll();

    // Decode fields JSON for each template
    foreach ($templates as &$t) {
        $t['fields'] = json_decode($t['fields'] ?? '[]', true) ?? [];
        $t['id']     = (int)$t['id'];
    }
    unset($t);

    // Seed system defaults if none exist yet
    if (empty(array_filter($templates, fn($t) => $t['is_default']))) {
        _seed_default_templates($pdo);
        // Reload after seeding
        $stmt->execute($roleFilter ? [$roleFilter, $practId] : [$practId]);
        $templates = $stmt->fetchAll();
        foreach ($templates as &$t) {
            $t['fields'] = json_decode($t['fields'] ?? '[]', true) ?? [];
            $t['id']     = (int)$t['id'];
        }
        unset($t);
    }

    echo json_encode(['templates' => $templates]);
    exit;
}

// ── POST: save / delete ──────────────────────────────────────────
if ($method === 'POST') {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
    $action = $input['action'] ?? '';

    if ($action === 'save') {
        $id       = isset($input['id']) ? (int)$input['id'] : null;
        $name     = trim($input['name']     ?? '');
        $roleType = trim($input['role_type'] ?? 'any');
        $fields   = $input['fields']         ?? [];

        $allowedRoles = ['any','therapist','counselor','coach','philosophical_counselor','peer_support','pastoral_counselor','spiritual_director'];
        if (!$name) {
            http_response_code(400);
            echo json_encode(['error' => 'name is required']);
            exit;
        }
        if (!in_array($roleType, $allowedRoles, true)) $roleType = 'any';

        // Sanitise fields
        $cleanFields = [];
        foreach ((array)$fields as $f) {
            $label = trim($f['label'] ?? '');
            $type  = $f['type'] ?? 'text';
            if (!in_array($type, ['text','textarea','select','checkbox'], true)) $type = 'text';
            if ($label) $cleanFields[] = ['label' => $label, 'type' => $type];
        }
        $fieldsJson = json_encode($cleanFields);

        if ($id) {
            // Update — must own the template and it must not be a system default
            $check = $pdo->prepare('SELECT id, is_default, created_by FROM intake_form_templates WHERE id = ? LIMIT 1');
            $check->execute([$id]);
            $row = $check->fetch();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'Template not found']);
                exit;
            }
            if ($row['is_default'] || (int)$row['created_by'] !== $practId) {
                http_response_code(403);
                echo json_encode(['error' => 'Cannot modify system templates or templates owned by others']);
                exit;
            }
            $pdo->prepare(
                'UPDATE intake_form_templates SET name = ?, role_type = ?, fields = ? WHERE id = ?'
            )->execute([$name, $roleType, $fieldsJson, $id]);
            echo json_encode(['ok' => true, 'id' => $id]);
        } else {
            // Insert
            $pdo->prepare(
                'INSERT INTO intake_form_templates (name, role_type, fields, is_default, created_by)
                 VALUES (?, ?, ?, 0, ?)'
            )->execute([$name, $roleType, $fieldsJson, $practId]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        }
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'id required']);
            exit;
        }
        $check = $pdo->prepare('SELECT is_default, created_by FROM intake_form_templates WHERE id = ? LIMIT 1');
        $check->execute([$id]);
        $row = $check->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Template not found']);
            exit;
        }
        if ($row['is_default'] || (int)$row['created_by'] !== $practId) {
            http_response_code(403);
            echo json_encode(['error' => 'Cannot delete system templates or templates owned by others']);
            exit;
        }
        $pdo->prepare('DELETE FROM intake_form_templates WHERE id = ?')->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'GET or POST required']);

/**
 * Seed system-default intake form templates on first use.
 */
function _seed_default_templates(PDO $pdo): void
{
    $defaults = [
        [
            'name'      => 'General Intake',
            'role_type' => 'any',
            'fields'    => [
                ['label' => 'What brings you here today?',                   'type' => 'textarea'],
                ['label' => 'Have you received any form of support before?', 'type' => 'checkbox'],
                ['label' => 'What are your main goals for our sessions?',    'type' => 'textarea'],
                ['label' => 'Is there anything I should know about you?',    'type' => 'textarea'],
            ],
        ],
        [
            'name'      => 'Crisis Risk Screen',
            'role_type' => 'any',
            'fields'    => [
                ['label' => 'Are you currently feeling safe?',                              'type' => 'checkbox'],
                ['label' => 'Have you had any thoughts of harming yourself or others?',     'type' => 'checkbox'],
                ['label' => 'Do you have a support person you can contact in a crisis?',    'type' => 'checkbox'],
                ['label' => 'Is there anything urgent you would like to discuss today?',    'type' => 'textarea'],
            ],
        ],
        [
            'name'      => 'Life Coaching — Goal Setting',
            'role_type' => 'coach',
            'fields'    => [
                ['label' => 'What area of your life would you most like to improve?',       'type' => 'select'],
                ['label' => 'Where do you see yourself in 12 months?',                     'type' => 'textarea'],
                ['label' => 'What has held you back from making this change so far?',       'type' => 'textarea'],
                ['label' => 'How will you know when you have succeeded?',                   'type' => 'textarea'],
            ],
        ],
        [
            'name'      => 'Peer Support Check-In',
            'role_type' => 'peer_support',
            'fields'    => [
                ['label' => 'How have you been feeling this week (1–10)?',                  'type' => 'text'],
                ['label' => 'What has been the hardest thing lately?',                      'type' => 'textarea'],
                ['label' => 'What is one thing that helped you cope this week?',            'type' => 'textarea'],
                ['label' => 'What support would be most helpful today?',                    'type' => 'textarea'],
            ],
        ],
        [
            'name'      => 'Philosophical Dialogue — Opening',
            'role_type' => 'philosophical_counselor',
            'fields'    => [
                ['label' => 'What question or concern brings you to this conversation?',    'type' => 'textarea'],
                ['label' => 'What beliefs or values feel most important to you right now?', 'type' => 'textarea'],
                ['label' => 'Is there a contradiction or tension you are trying to resolve?','type' => 'textarea'],
            ],
        ],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO intake_form_templates (name, role_type, fields, is_default, created_by)
         VALUES (?, ?, ?, 1, NULL)'
    );

    foreach ($defaults as $d) {
        $stmt->execute([$d['name'], $d['role_type'], json_encode($d['fields'])]);
    }
}
