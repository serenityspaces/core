<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/events.php';

header('Content-Type: application/json');

// Only practitioners
if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Practitioners only']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input       = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$messageId   = (int)($input['message_id'] ?? 0);
$startOffset = (int)($input['start_offset'] ?? 0);
$endOffset   = (int)($input['end_offset'] ?? 0);
$color       = $input['color'] ?? 'yellow';
$annotation  = trim($input['annotation'] ?? '');

$allowed = ['yellow','pink','blue','crimson'];
if (!in_array($color, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid color']);
    exit;
}

if ($messageId === 0 || $endOffset <= $startOffset) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$pdo = getDB();

// Verify practitioner owns this message's session
$stmt = $pdo->prepare(
    'SELECT m.id FROM messages m
     JOIN sessions s ON s.id = m.session_id
     JOIN rooms r ON r.id = s.room_id
     WHERE m.id = ? AND r.practitioner_id = ? LIMIT 1'
);
$stmt->execute([$messageId, (int)$_SESSION['practitioner_id']]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO highlights (message_id, start_offset, end_offset, color, annotation) VALUES (?,?,?,?,?)'
);
$stmt->execute([$messageId, $startOffset, $endOffset, $color, $annotation ?: null]);
$id = $pdo->lastInsertId();

$stmt = $pdo->prepare('SELECT * FROM highlights WHERE id = ?');
$stmt->execute([$id]);
$hl = $stmt->fetch();

// Emit highlight event — need session_id from the message
$stmtSess = $pdo->prepare('SELECT session_id FROM messages WHERE id = ? LIMIT 1');
$stmtSess->execute([$messageId]);
$msgRow = $stmtSess->fetch();
if ($msgRow) {
    emitEvent($pdo, (int)$msgRow['session_id'], 'highlight', [
        'id'           => (int)$hl['id'],
        'message_id'   => (int)$hl['message_id'],
        'start_offset' => (int)$hl['start_offset'],
        'end_offset'   => (int)$hl['end_offset'],
        'color'        => $hl['color'],
        'annotation'   => $hl['annotation'],
        'created_at'   => $hl['created_at'],
    ]);
}

echo json_encode($hl);
