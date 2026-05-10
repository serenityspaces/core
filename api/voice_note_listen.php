<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/events.php';
require_once __DIR__ . '/../db/audit.php';
require_once __DIR__ . '/../includes/avatar_presets.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input      = json_decode(file_get_contents('php://input'), true) ?? [];
$sessionId  = (int)($input['session_id'] ?? 0);
$messageId  = (int)($input['message_id'] ?? 0);
$joinToken  = trim($input['join_token'] ?? '');

if ($sessionId === 0 || $messageId === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'session_id and message_id required']);
    exit;
}

$pdo           = getDB();
$participantId = null;
$avatarPath    = '';
$displayName   = '';

if (!empty($_SESSION['practitioner_id'])) {
    $stmt = $pdo->prepare(
        'SELECT r.id FROM rooms r JOIN sessions s ON s.room_id = r.id
         WHERE s.id = ? AND r.practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$sessionId, (int)$_SESSION['practitioner_id']]);
    if (!$stmt->fetch()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

    $practJoinToken = 'pract-' . $sessionId . '-' . (int)$_SESSION['practitioner_id'];
    $stmt = $pdo->prepare('SELECT id, avatar_path, display_name FROM participants WHERE join_token = ? LIMIT 1');
    $stmt->execute([$practJoinToken]);
    $p = $stmt->fetch();
    if ($p) { $participantId = (int)$p['id']; $avatarPath = $p['avatar_path'] ?? ''; $displayName = $p['display_name']; }
} elseif ($joinToken) {
    $stmt = $pdo->prepare('SELECT id, avatar_path, display_name FROM participants WHERE join_token = ? AND session_id = ? LIMIT 1');
    $stmt->execute([$joinToken, $sessionId]);
    $p = $stmt->fetch();
    if (!$p) { http_response_code(403); echo json_encode(['error' => 'Invalid token']); exit; }
    $participantId = (int)$p['id']; $avatarPath = $p['avatar_path'] ?? ''; $displayName = $p['display_name'];
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Resolve avatar URL
if (strpos($avatarPath, 'preset:') === 0) {
    $key = substr($avatarPath, 7);
    $avatarUrl = $avatarPresets[$key] ?? $avatarPresets['Default'];
} else {
    $avatarUrl = $avatarPath ?: $avatarPresets['Default'];
}

audit_log($pdo, 'voice_note.listen', [
    'practitioner_id' => !empty($_SESSION['practitioner_id']) ? (int)$_SESSION['practitioner_id'] : null,
    'participant_id'  => $participantId,
    'session_id'      => $sessionId,
    'entity_type'     => 'message',
    'entity_id'       => $messageId,
]);

session_write_close();

emitEvent($pdo, $sessionId, 'voice_note_listened', [
    'message_id'     => $messageId,
    'participant_id' => $participantId,
    'avatar_url'     => $avatarUrl,
    'display_name'   => $displayName,
]);

echo json_encode(['ok' => true]);
