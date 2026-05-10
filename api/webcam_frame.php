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
$sessionId = (int)($input['session_id'] ?? 0);
$joinToken = trim($input['join_token'] ?? '');
$frames    = $input['frames']     ?? null;   // multi-frame array (preferred)
$frameData = $input['frame_data'] ?? '';     // legacy single-frame fallback

// Normalise to frames array
if (is_array($frames) && count($frames) > 0) {
    $frames = array_slice(array_values($frames), 0, 48); // max 48 frames (24fps × 2s)
} elseif (!empty($frameData)) {
    $frames = [$frameData];
} else {
    $frames = null;
}

if ($sessionId === 0 || empty($frames)) {
    http_response_code(400);
    echo json_encode(['error' => 'session_id and frames required']);
    exit;
}

// Hard cap: ~6.7 KB per frame × 48 frames
$totalSize = array_sum(array_map('strlen', $frames));
if ($totalSize > 320000) {
    http_response_code(400);
    echo json_encode(['error' => 'Frames too large']);
    exit;
}

$pdo = getDB();
$participantId = null;

if (!empty($_SESSION['practitioner_id'])) {
    $stmt = $pdo->prepare(
        'SELECT r.id FROM rooms r JOIN sessions s ON s.room_id = r.id
         WHERE s.id = ? AND r.practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$sessionId, (int)$_SESSION['practitioner_id']]);
    if (!$stmt->fetch()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

    $practJoinToken = 'pract-' . $sessionId . '-' . (int)$_SESSION['practitioner_id'];
    $stmt = $pdo->prepare('SELECT id FROM participants WHERE join_token = ? LIMIT 1');
    $stmt->execute([$practJoinToken]);
    $p = $stmt->fetch();
    if (!$p) { http_response_code(403); echo json_encode(['error' => 'No participant']); exit; }
    $participantId = (int)$p['id'];
} elseif ($joinToken) {
    $stmt = $pdo->prepare('SELECT id FROM participants WHERE join_token = ? AND session_id = ? LIMIT 1');
    $stmt->execute([$joinToken, $sessionId]);
    $p = $stmt->fetch();
    if (!$p) { http_response_code(403); echo json_encode(['error' => 'Invalid token']); exit; }
    $participantId = (int)$p['id'];
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

session_write_close();

emitEvent($pdo, $sessionId, 'webcam_frame', [
    'participant_id' => $participantId,
    'frames'         => $frames,
]);

echo json_encode(['ok' => true]);
