<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/events.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

// Only practitioners
if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Practitioners only']);
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action    = $input['action'] ?? '';
$sessionId = (int)($input['session_id'] ?? 0);
$pdo       = getDB();

if ($action === 'end' && $sessionId > 0) {
    // Verify ownership
    $stmt = $pdo->prepare(
        'SELECT s.id FROM sessions s
         JOIN rooms r ON r.id = s.room_id
         WHERE s.id = ? AND r.practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$sessionId, (int)$_SESSION['practitioner_id']]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $pdo->prepare('UPDATE sessions SET ended_at = NOW() WHERE id = ?')->execute([$sessionId]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'begin' && $sessionId > 0) {
    // Verify ownership
    $stmt = $pdo->prepare(
        'SELECT s.id FROM sessions s
         JOIN rooms r ON r.id = s.room_id
         WHERE s.id = ? AND r.practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$sessionId, (int)$_SESSION['practitioner_id']]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    // Only emit once
    $stmtCheck = $pdo->prepare("SELECT id FROM events WHERE session_id = ? AND type = 'session_begun' LIMIT 1");
    $stmtCheck->execute([$sessionId]);
    if (!$stmtCheck->fetch()) {
        emitEvent($pdo, $sessionId, 'session_begun', []);
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Bad request']);
