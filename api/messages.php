<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/events.php';
require_once __DIR__ . '/../includes/phi_crypto.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$pdo       = getDB();
$action    = $input['action'] ?? 'send';
$sessionId = (int)($input['session_id'] ?? 0);

if ($sessionId === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'session_id required']);
    exit;
}

// Verify session exists and is active
$stmt = $pdo->prepare('SELECT * FROM sessions WHERE id = ? AND ended_at IS NULL LIMIT 1');
$stmt->execute([$sessionId]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Session not active']);
    exit;
}

$isPractitioner = 0;
$participantId  = null;

if (!empty($_SESSION['practitioner_id'])) {
    $csrfToken = $input['csrf_token'] ?? '';
    if (!validate_csrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token mismatch']);
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT r.id FROM rooms r JOIN sessions s ON s.room_id = r.id
         WHERE s.id = ? AND r.practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$sessionId, (int)$_SESSION['practitioner_id']]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $practJoinToken = 'pract-' . $sessionId . '-' . (int)$_SESSION['practitioner_id'];
    $stmt = $pdo->prepare('SELECT id FROM participants WHERE join_token = ? LIMIT 1');
    $stmt->execute([$practJoinToken]);
    $practP = $stmt->fetch();
    if ($practP) $participantId = (int)$practP['id'];
    $isPractitioner = 1;
} else {
    $joinToken = trim($input['join_token'] ?? '');
    if (!$joinToken) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM participants WHERE join_token = ? AND session_id = ? AND is_host = 0 LIMIT 1'
    );
    $stmt->execute([$joinToken, $sessionId]);
    $p = $stmt->fetch();
    if (!$p) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid join token']);
        exit;
    }
    $participantId = (int)$p['id'];
}

// ── Edit message ──────────────────────────────────────────────
if ($action === 'edit') {
    $msgId   = (int)($input['message_id'] ?? 0);
    $content = trim($input['content'] ?? '');
    if ($msgId === 0 || $content === '') {
        http_response_code(400);
        echo json_encode(['error' => 'message_id and content required']);
        exit;
    }
    // Verify ownership
    $stmt = $pdo->prepare('SELECT * FROM messages WHERE id = ? AND session_id = ? LIMIT 1');
    $stmt->execute([$msgId, $sessionId]);
    $msg = $stmt->fetch();
    if (!$msg || (int)$msg['participant_id'] !== $participantId) {
        http_response_code(403);
        echo json_encode(['error' => 'Cannot edit this message']);
        exit;
    }
    $pdo->prepare('UPDATE messages SET content = ?, edited_at = NOW() WHERE id = ?')
        ->execute([phi_encrypt($content, 'messages:' . $sessionId), $msgId]);
    $payload = ['message_id' => $msgId, 'content' => $content]; // plaintext in event
    emitEvent($pdo, $sessionId, 'message_edit', $payload);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Delete message ────────────────────────────────────────────
if ($action === 'delete') {
    $msgId = (int)($input['message_id'] ?? 0);
    if ($msgId === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'message_id required']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT * FROM messages WHERE id = ? AND session_id = ? LIMIT 1');
    $stmt->execute([$msgId, $sessionId]);
    $msg = $stmt->fetch();
    // Own message OR practitioner can delete any
    if (!$msg || ((int)$msg['participant_id'] !== $participantId && !$isPractitioner)) {
        http_response_code(403);
        echo json_encode(['error' => 'Cannot delete this message']);
        exit;
    }
    $pdo->prepare('UPDATE messages SET is_deleted = 1, edited_at = NOW() WHERE id = ?')->execute([$msgId]);
    emitEvent($pdo, $sessionId, 'message_delete', ['message_id' => $msgId]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Send message (default) ────────────────────────────────────
$content = trim($input['content'] ?? '');
if ($content === '') {
    http_response_code(400);
    echo json_encode(['error' => 'content required']);
    exit;
}

// Only practitioners may send crisis or media_rec typed messages
$reqType     = $input['message_type'] ?? 'text';
$messageType = 'text';
if ($isPractitioner) {
    if ($reqType === 'crisis')    $messageType = 'crisis';
    if ($reqType === 'media_rec') $messageType = 'media_rec';
}

$stmt = $pdo->prepare(
    'INSERT INTO messages (session_id, participant_id, content, message_type, is_practitioner) VALUES (?,?,?,?,?)'
);
$stmt->execute([$sessionId, $participantId, phi_encrypt($content, 'messages:' . $sessionId), $messageType, $isPractitioner]);
$msgId = $pdo->lastInsertId();

$stmt = $pdo->prepare(
    'SELECT m.*, p.display_name, p.avatar_path
     FROM messages m LEFT JOIN participants p ON p.id = m.participant_id
     WHERE m.id = ?'
);
$stmt->execute([$msgId]);
$msg = $stmt->fetch();

$response = [
    'id'             => (int)$msg['id'],
    'participant_id' => $msg['participant_id'] ? (int)$msg['participant_id'] : null,
    'display_name'   => $msg['display_name'] ?? '',
    'content'        => $content, // use original plaintext, not re-read encrypted DB value
    'message_type'   => $messageType,
    'sent_at'        => $msg['sent_at'] ? $msg['sent_at'] . 'Z' : null,
    'is_practitioner'=> (bool)$msg['is_practitioner'],
    'is_pinned'      => false,
    'highlights'     => [],
];
emitEvent($pdo, $sessionId, 'message', $response);
echo json_encode($response);
