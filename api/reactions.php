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

$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$messageId = (int)($input['message_id'] ?? 0);
$emoji     = trim($input['emoji']      ?? '');
$joinToken = trim($input['join_token'] ?? '');

if ($messageId === 0 || $emoji === '') {
    http_response_code(400);
    echo json_encode(['error' => 'message_id and emoji required']);
    exit;
}

// Allowed emoji whitelist
$allowed = ['❤️','👍🏻','👎🏻','👌🏻','✅','⭐️'];
if (!in_array($emoji, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid emoji']);
    exit;
}

$pdo = getDB();

// ── Auth ────────────────────────────────────────────────────────
$participantId = null;
$sessionId     = null;

if (!empty($_SESSION['practitioner_id'])) {
    $stmt = $pdo->prepare(
        'SELECT p.id, p.session_id FROM participants p
         JOIN messages m ON m.session_id = p.session_id
         JOIN sessions s ON s.id = p.session_id
         JOIN rooms r ON r.id = s.room_id
         WHERE m.id = ? AND r.practitioner_id = ?
           AND p.join_token LIKE \'pract-%\'
         LIMIT 1'
    );
    $stmt->execute([$messageId, (int)$_SESSION['practitioner_id']]);
    $p = $stmt->fetch();
    if (!$p) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }
    $participantId = (int)$p['id'];
    $sessionId     = (int)$p['session_id'];
} elseif ($joinToken) {
    $stmt = $pdo->prepare(
        'SELECT p.id, p.session_id FROM participants p
         JOIN messages m ON m.session_id = p.session_id
         WHERE m.id = ? AND p.join_token = ?
         LIMIT 1'
    );
    $stmt->execute([$messageId, $joinToken]);
    $p = $stmt->fetch();
    if (!$p) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }
    $participantId = (int)$p['id'];
    $sessionId     = (int)$p['session_id'];
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Upsert / toggle ─────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT id, emoji FROM message_reactions WHERE message_id = ? AND participant_id = ? LIMIT 1'
);
$stmt->execute([$messageId, $participantId]);
$existing = $stmt->fetch();

$prevEmoji = null;
if ($existing) {
    if ($existing['emoji'] === $emoji) {
        $pdo->prepare('DELETE FROM message_reactions WHERE id = ?')->execute([$existing['id']]);
        $action = 'removed';
    } else {
        $prevEmoji = $existing['emoji'];
        $pdo->prepare('UPDATE message_reactions SET emoji = ?, created_at = NOW() WHERE id = ?')
            ->execute([$emoji, $existing['id']]);
        $action = 'updated';
    }
} else {
    $pdo->prepare('INSERT INTO message_reactions (message_id, participant_id, emoji) VALUES (?,?,?)')
        ->execute([$messageId, $participantId, $emoji]);
    $action = 'added';
}

$payload = [
    'message_id'     => $messageId,
    'participant_id' => $participantId,
    'emoji'          => $emoji,
    'action'         => $action,
];
if ($prevEmoji !== null) $payload['prev_emoji'] = $prevEmoji;

emitEvent($pdo, $sessionId, 'reaction', $payload);
echo json_encode(array_merge(['ok' => true], $payload));
