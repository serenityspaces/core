<?php
/**
 * Serenity Spaces — Practitioner presence check.
 * Called by clients waiting in a room to know when the practitioner has joined.
 *
 * GET ?session_id=N&join_token=T  → { present: bool, practitioner_name: string|null }
 *
 * Auth: must supply a valid join_token belonging to this session, OR have an active
 *       practitioner PHP session (for the practitioner's own room view).
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET required']);
    exit;
}

$sessionId = (int)($_GET['session_id'] ?? 0);
if (!$sessionId) {
    http_response_code(400);
    echo json_encode(['error' => 'session_id required']);
    exit;
}

$pdo = getDB();

// ── Authentication ────────────────────────────────────────────────
// Practitioner session: allow if they own the room for this session
$isPract = false;
if (!empty($_SESSION['practitioner_id'])) {
    $authStmt = $pdo->prepare(
        'SELECT r.id FROM rooms r JOIN sessions s ON s.room_id = r.id
         WHERE s.id = ? AND r.practitioner_id = ? LIMIT 1'
    );
    $authStmt->execute([$sessionId, (int)$_SESSION['practitioner_id']]);
    if ($authStmt->fetch()) $isPract = true;
}

// Client join_token: validate it belongs to this session
if (!$isPract) {
    $joinToken = trim($_GET['join_token'] ?? '');
    if ($joinToken === '') {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required — supply join_token']);
        exit;
    }
    $tokStmt = $pdo->prepare('SELECT id FROM participants WHERE join_token = ? AND session_id = ? LIMIT 1');
    $tokStmt->execute([$joinToken, $sessionId]);
    if (!$tokStmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or expired join_token']);
        exit;
    }
}

// mode=snapshot — full participant presence for all room members (used by room.js polling)
if (($_GET['mode'] ?? '') === 'snapshot') {
    $snapStmt = $pdo->prepare(
        'SELECT id,
         CASE
           WHEN last_seen_at IS NULL THEN \'unknown\'
           WHEN TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) < 30 THEN \'online\'
           WHEN TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) < 60 THEN \'dim\'
           ELSE \'disconnected\'
         END AS presence_status
         FROM participants WHERE session_id = ?'
    );
    $snapStmt->execute([$sessionId]);
    $snapshot = [];
    foreach ($snapStmt->fetchAll() as $pr) {
        $snapshot[(int)$pr['id']] = $pr['presence_status'];
    }
    echo json_encode(['presence' => $snapshot]);
    exit;
}

// Default mode — host presence check (waiting room use)
$stmt = $pdo->prepare(
    'SELECT p.display_name, p.last_seen_at
     FROM participants p
     WHERE p.session_id = ? AND p.is_host = 1
     ORDER BY p.id LIMIT 1'
);
$stmt->execute([$sessionId]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['present' => false, 'practitioner_name' => null]);
    exit;
}

// Consider the practitioner present if they were seen within the last 35 seconds
$lastSeen = $row['last_seen_at'];
$present  = false;
if ($lastSeen) {
    $seenTs = strtotime($lastSeen);
    $present = (time() - $seenTs) <= 35;
}

echo json_encode([
    'present'           => $present,
    'practitioner_name' => $row['display_name'],
    'last_seen_at'      => $lastSeen,
]);
