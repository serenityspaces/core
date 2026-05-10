<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/events.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false]);
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$sessionId = (int)($input['session_id'] ?? 0);
$isNoting  = !empty($input['is_noting']);
$joinToken = trim($input['join_token'] ?? '');

if ($sessionId === 0) {
    echo json_encode(['ok' => false]);
    exit;
}

$pdo = getDB();
$now = $isNoting ? date('Y-m-d H:i:s') : null;

$participantId = null;

if (!empty($_SESSION['practitioner_id'])) {
    $practJoinToken = 'pract-' . $sessionId . '-' . (int)$_SESSION['practitioner_id'];
    $stmt = $pdo->prepare('SELECT id FROM participants WHERE join_token = ? AND session_id = ? LIMIT 1');
    $stmt->execute([$practJoinToken, $sessionId]);
    $p = $stmt->fetch();
    if ($p) {
        $pdo->prepare('UPDATE participants SET noting_at = ? WHERE id = ?')->execute([$now, $p['id']]);
        $participantId = (int)$p['id'];
    }
} elseif ($joinToken !== '') {
    $stmt = $pdo->prepare('SELECT id FROM participants WHERE join_token = ? AND session_id = ? AND is_host = 0 LIMIT 1');
    $stmt->execute([$joinToken, $sessionId]);
    $p = $stmt->fetch();
    if ($p) {
        $pdo->prepare('UPDATE participants SET noting_at = ? WHERE id = ?')->execute([$now, $p['id']]);
        $participantId = (int)$p['id'];
    }
}

if ($participantId !== null) {
    emitEvent($pdo, $sessionId, 'noting', [
        'participant_id' => $participantId,
        'is_noting'      => $isNoting,
    ]);
}

echo json_encode(['ok' => true]);
