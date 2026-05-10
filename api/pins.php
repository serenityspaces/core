<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/phi_crypto.php';
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

$input      = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$messageId  = (int)($input['message_id'] ?? 0);
$action     = $input['action'] ?? 'pin';
$annotation = trim($input['annotation'] ?? '');

if ($messageId === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'message_id required']);
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

// Need session_id and message data for event
$stmtMsgData = $pdo->prepare(
    'SELECT m.session_id, m.content, m.sent_at, p.display_name
     FROM messages m LEFT JOIN participants p ON p.id = m.participant_id
     WHERE m.id = ? LIMIT 1'
);
$stmtMsgData->execute([$messageId]);
$msgData = $stmtMsgData->fetch();

if ($action === 'pin') {
    $pdo->prepare('UPDATE messages SET is_pinned = 1 WHERE id = ?')->execute([$messageId]);
    $stmt = $pdo->prepare(
        'INSERT INTO pins (message_id, annotation) VALUES (?,?)
         ON DUPLICATE KEY UPDATE annotation = VALUES(annotation)'
    );
    $stmt->execute([$messageId, $annotation ?: null]);

    if ($msgData) {
        $sessionId = (int)$msgData['session_id'];
        emitEvent($pdo, $sessionId, 'pin', [
            'message_id'   => $messageId,
            'is_pinned'    => true,
            'display_name' => $msgData['display_name'] ?? '',
            'content'      => phi_decrypt($msgData['content'] ?? '', 'messages:' . $sessionId),
            'sent_at'      => $msgData['sent_at'],
            'annotation'   => $annotation ?: null,
        ]);
    }
    echo json_encode(['ok' => true, 'pinned' => true]);
} else {
    $pdo->prepare('UPDATE messages SET is_pinned = 0 WHERE id = ?')->execute([$messageId]);
    $pdo->prepare('DELETE FROM pins WHERE message_id = ?')->execute([$messageId]);

    if ($msgData) {
        emitEvent($pdo, (int)$msgData['session_id'], 'pin', [
            'message_id' => $messageId,
            'is_pinned'  => false,
        ]);
    }
    echo json_encode(['ok' => true, 'pinned' => false]);
}
