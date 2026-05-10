<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

header('Content-Type: application/json');

// Practitioners only
if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$practId = (int)$_SESSION['practitioner_id'];
$input   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action  = $input['action'] ?? ($_GET['action'] ?? '');
$pdo     = getDB();

// ── Helper: verify practitioner owns the session ───────────────
function verifySession(PDO $pdo, int $sessionId, int $practId): bool {
    $stmt = $pdo->prepare(
        'SELECT r.id FROM rooms r JOIN sessions s ON s.room_id = r.id
         WHERE s.id = ? AND r.practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$sessionId, $practId]);
    return (bool)$stmt->fetch();
}

// ── list ───────────────────────────────────────────────────────
if ($action === 'list') {
    $sessionId = (int)($_GET['session_id'] ?? $input['session_id'] ?? 0);
    if ($sessionId === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'session_id required']);
        exit;
    }
    if (!verifySession($pdo, $sessionId, $practId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT id, text, assigned_to, due_date, completed_at
         FROM action_items WHERE session_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$sessionId]);
    echo json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── add ────────────────────────────────────────────────────────
if ($action === 'add') {
    $sessionId  = (int)($input['session_id'] ?? 0);
    $text       = trim($input['text'] ?? '');
    $assignedTo = trim($input['assigned_to'] ?? '');
    $dueDate    = $input['due_date'] ?? null;

    if ($sessionId === 0 || $text === '') {
        http_response_code(400);
        echo json_encode(['error' => 'session_id and text required']);
        exit;
    }
    if (!verifySession($pdo, $sessionId, $practId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO action_items (session_id, text, assigned_to, due_date)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$sessionId, $text, $assignedTo ?: null, $dueDate ?: null]);
    $id = (int)$pdo->lastInsertId();
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ── complete (toggle) ──────────────────────────────────────────
if ($action === 'complete') {
    $itemId    = (int)($input['id'] ?? 0);
    $completed = !empty($input['completed']);
    if ($itemId === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'id required']);
        exit;
    }
    // Verify ownership via join
    $stmt = $pdo->prepare(
        'SELECT ai.id FROM action_items ai
         JOIN sessions s ON s.id = ai.session_id
         JOIN rooms r ON r.id = s.room_id
         WHERE ai.id = ? AND r.practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$itemId, $practId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $pdo->prepare(
        'UPDATE action_items SET completed_at = ? WHERE id = ?'
    )->execute([$completed ? date('Y-m-d H:i:s') : null, $itemId]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── delete ─────────────────────────────────────────────────────
if ($action === 'delete') {
    $itemId = (int)($input['id'] ?? 0);
    if ($itemId === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'id required']);
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT ai.id FROM action_items ai
         JOIN sessions s ON s.id = ai.session_id
         JOIN rooms r ON r.id = s.room_id
         WHERE ai.id = ? AND r.practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$itemId, $practId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $pdo->prepare('DELETE FROM action_items WHERE id = ?')->execute([$itemId]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
