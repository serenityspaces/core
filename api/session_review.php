<?php
/**
 * Serenity Spaces — Session Review API
 *
 * GET ?session_id=N  — Returns full session transcript (messages + highlights + pins + notes)
 *                      for post-session practitioner review.
 * Requires active practitioner session. Verifies room ownership.
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/audit.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET required']);
    exit;
}

if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$practId   = (int)$_SESSION['practitioner_id'];
$sessionId = (int)($_GET['session_id'] ?? 0);

if (!$sessionId) {
    http_response_code(400);
    echo json_encode(['error' => 'session_id required']);
    exit;
}

$pdo = getDB();

// Verify practitioner owns this session's room
$stmt = $pdo->prepare(
    'SELECT s.id, s.started_at, s.ended_at, r.name AS room_name
     FROM sessions s
     JOIN rooms r ON r.id = s.room_id
     WHERE s.id = ? AND r.practitioner_id = ? LIMIT 1'
);
$stmt->execute([$sessionId, $practId]);
$session = $stmt->fetch();

if (!$session) {
    http_response_code(403);
    echo json_encode(['error' => 'Session not found or not yours']);
    exit;
}

// All messages with participant info, chronological order
$stmt = $pdo->prepare(
    'SELECT m.id, m.participant_id, m.content, m.message_type, m.caption,
            m.file_size, m.mime_type, m.original_name, m.is_practitioner,
            m.is_pinned, m.sent_at,
            p.display_name, p.avatar_path
     FROM messages m
     LEFT JOIN participants p ON p.id = m.participant_id
     WHERE m.session_id = ?
     ORDER BY m.sent_at ASC, m.id ASC'
);
$stmt->execute([$sessionId]);
$messages = $stmt->fetchAll();

// All highlights for this session, grouped by message_id
$stmt = $pdo->prepare(
    'SELECT h.id, h.message_id, h.start_offset, h.end_offset, h.color, h.annotation
     FROM highlights h
     JOIN messages m ON m.id = h.message_id
     WHERE m.session_id = ?
     ORDER BY h.start_offset ASC'
);
$stmt->execute([$sessionId]);
$hlRows = $stmt->fetchAll();

$hlByMsg = [];
foreach ($hlRows as $hl) {
    $hlByMsg[(int)$hl['message_id']][] = [
        'id'           => (int)$hl['id'],
        'start_offset' => (int)$hl['start_offset'],
        'end_offset'   => (int)$hl['end_offset'],
        'color'        => $hl['color'],
        'annotation'   => $hl['annotation'],
    ];
}

// Attach highlights, normalise booleans
foreach ($messages as &$msg) {
    $msg['highlights']      = $hlByMsg[(int)$msg['id']] ?? [];
    $msg['is_pinned']       = (bool)$msg['is_pinned'];
    $msg['is_practitioner'] = (bool)$msg['is_practitioner'];
    $msg['id']              = (int)$msg['id'];
    $msg['participant_id']  = (int)$msg['participant_id'];
    $msg['file_size']       = $msg['file_size'] !== null ? (int)$msg['file_size'] : null;
}
unset($msg);

// Practitioner notes for this session (may be per-participant)
$stmt = $pdo->prepare(
    'SELECT pn.note_content, pn.updated_at, p.display_name AS participant_name
     FROM practitioner_notes pn
     LEFT JOIN participants p ON p.id = pn.participant_id
     WHERE pn.session_id = ?
     ORDER BY pn.updated_at ASC'
);
$stmt->execute([$sessionId]);
$notes = $stmt->fetchAll();

audit_log($pdo, 'session.review', [
    'practitioner_id' => $practId,
    'session_id'      => $sessionId,
    'entity_type'     => 'session',
    'entity_id'       => $sessionId,
]);

echo json_encode([
    'session'  => $session,
    'messages' => array_values($messages),
    'notes'    => $notes,
]);
