<?php
/**
 * Serenity Spaces — Crisis Resources API
 *
 *   GET  action=list   — active resources for practitioner crisis panel (requires practitioner session)
 *   POST action=save   — create / update a resource (admin CSRF)
 *   POST action=delete — delete a resource (admin CSRF)
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── List (practitioner-accessible) ───────────────────────────────
if ($action === 'list' && $method === 'GET') {
    if (empty($_SESSION['practitioner_id']) && empty($_SESSION['admin'])) {
        http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit;
    }
    $stmt = $pdo->query('SELECT id, label, message_body, sort_order FROM crisis_resources WHERE is_active = 1 ORDER BY sort_order ASC, id ASC');
    echo json_encode(['ok' => true, 'resources' => $stmt->fetchAll()]);
    exit;
}

// Admin gate for write operations
function requireAdmin(): void {
    if (empty($_SESSION['admin'])) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admin only']); exit;
    }
}
function verifyCsrf(): void {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'CSRF token mismatch']); exit;
    }
}

// ── Save (create / update) ───────────────────────────────────────
if ($action === 'save' && $method === 'POST') {
    requireAdmin();
    verifyCsrf();

    $id          = (int)($_POST['id'] ?? 0);
    $label       = trim($_POST['label'] ?? '');
    $messageBody = trim($_POST['message_body'] ?? '');
    $sortOrder   = (int)($_POST['sort_order'] ?? 0);
    $isActive    = isset($_POST['is_active']) ? 1 : 0;

    if (!$label || !$messageBody) {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Label and message body are required']); exit;
    }

    if ($id > 0) {
        $pdo->prepare('UPDATE crisis_resources SET label=?, message_body=?, sort_order=?, is_active=? WHERE id=?')
            ->execute([$label, $messageBody, $sortOrder, $isActive, $id]);
    } else {
        $pdo->prepare('INSERT INTO crisis_resources (label, message_body, sort_order, is_active) VALUES (?,?,?,?)')
            ->execute([$label, $messageBody, $sortOrder, 1]);
        $id = (int)$pdo->lastInsertId();
    }

    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ── Delete ───────────────────────────────────────────────────────
if ($action === 'delete' && $method === 'POST') {
    requireAdmin();
    verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id required']); exit; }
    $pdo->prepare('DELETE FROM crisis_resources WHERE id = ?')->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
