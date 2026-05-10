<?php
/**
 * Event-stream poll endpoint.
 * GET /api/poll.php?session_id=X&last_event_id=Y&join_token=Z
 *
 * Long-polls up to 20 seconds. Returns new events as a JSON array,
 * each with {id, type, payload}. Clients advance their last_event_id
 * to the highest event id received and re-poll immediately.
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/audit.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$sessionId   = (int)($_GET['session_id']   ?? 0);
$lastEventId = (int)($_GET['last_event_id'] ?? 0);
$joinToken   = trim($_GET['join_token']     ?? '');

if ($sessionId === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'session_id required']);
    exit;
}

$pdo = getDB();
$participantId = null;

// ── Auth ───────────────────────────────────────────────────────
if (!empty($_SESSION['practitioner_id'])) {
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
    // Log once at the start of each poll sequence (last_event_id=0 = fresh read)
    if ($lastEventId === 0) {
        audit_log($pdo, 'session.poll_start', [
            'practitioner_id' => (int)$_SESSION['practitioner_id'],
            'session_id'      => $sessionId,
            'entity_type'     => 'session',
            'entity_id'       => $sessionId,
        ]);
    }
    $practJoinToken = 'pract-' . $sessionId . '-' . (int)$_SESSION['practitioner_id'];
    $stmt2 = $pdo->prepare('SELECT id FROM participants WHERE join_token = ? AND session_id = ? LIMIT 1');
    $stmt2->execute([$practJoinToken, $sessionId]);
    $prow = $stmt2->fetch();
    if ($prow) $participantId = (int)$prow['id'];
} elseif ($joinToken) {
    $stmt = $pdo->prepare(
        'SELECT id FROM participants WHERE join_token = ? AND session_id = ? AND is_host = 0 LIMIT 1'
    );
    $stmt->execute([$joinToken, $sessionId]);
    $prow = $stmt->fetch();
    if (!$prow) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }
    $participantId = (int)$prow['id'];
    // Log session access for audit coverage — once per fresh connection (last_event_id=0)
    if ($lastEventId === 0) {
        audit_log($pdo, 'session.poll_start', [
            'participant_id' => $participantId,
            'session_id'     => $sessionId,
            'entity_type'    => 'session',
            'entity_id'      => $sessionId,
        ]);
    }
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Update last_seen_at for presence tracking
if ($participantId) {
    $pdo->prepare('UPDATE participants SET last_seen_at = NOW() WHERE id = ?')->execute([$participantId]);
}

// Presence is fetched separately by the client via api/presence.php?mode=snapshot
// every 5 seconds — keeping it out of the poll loop cuts ~2 DB queries/second/session.

// Release session lock so other API calls aren't blocked while we wait
session_write_close();

// ── Long-poll loop ─────────────────────────────────────────────
$maxWait  = 2;
$interval = 0.5;
$elapsed  = 0;

$fetchStmt = $pdo->prepare(
    'SELECT id, type, payload FROM events
     WHERE session_id = ? AND id > ?
     ORDER BY id ASC LIMIT 200'
);

while ($elapsed < $maxWait) {
    $fetchStmt->execute([$sessionId, $lastEventId]);
    $rows = $fetchStmt->fetchAll();

    if (!empty($rows)) {
        echo json_encode([
            'events' => array_map(function($e) {
                return [
                    'id'      => (int)$e['id'],
                    'type'    => $e['type'],
                    'payload' => json_decode($e['payload'], true),
                ];
            }, $rows),
        ]);
        exit;
    }

    usleep((int)($interval * 1_000_000));
    $elapsed += $interval;
}

echo json_encode(['events' => []]);
