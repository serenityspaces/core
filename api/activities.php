<?php
/**
 * Activities API — room-level activity management
 * GET  ?session_id=N&participant_id=N&join_token=T  → list active activities for session
 * POST {action:start, ..., join_token:T}            → start a new activity
 * POST {action:end,   ..., join_token:T}            → end an activity
 *
 * Auth: caller must either be a logged-in practitioner who owns the session,
 * or supply a join_token that matches the participant_id within the session.
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/events.php';
header('Content-Type: application/json');

$pdo = getDB();

function act_authorize(PDO $pdo, int $sessionId, int $participantId, ?string $joinToken): void {
    if ($sessionId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'missing session_id']);
        exit;
    }
    if (!empty($_SESSION['practitioner_id'])) {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM rooms r JOIN sessions s ON s.room_id = r.id
             WHERE s.id = ? AND r.practitioner_id = ? LIMIT 1'
        );
        $stmt->execute([$sessionId, (int)$_SESSION['practitioner_id']]);
        if ($stmt->fetchColumn()) return;
    }
    if ($participantId > 0 && is_string($joinToken) && $joinToken !== '') {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM participants WHERE id = ? AND session_id = ? AND join_token = ? LIMIT 1'
        );
        $stmt->execute([$participantId, $sessionId, $joinToken]);
        if ($stmt->fetchColumn()) return;
    }
    http_response_code(403);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sessionId     = (int)($_GET['session_id'] ?? 0);
    $participantId = (int)($_GET['participant_id'] ?? 0);
    $joinToken     = $_GET['join_token'] ?? null;
    act_authorize($pdo, $sessionId, $participantId, $joinToken);

    $stmt = $pdo->prepare(
        'SELECT as2.lobby_code, as2.activity_type, as2.started_by_participant_id,
                p.display_name as started_by_name
         FROM activity_sessions as2
         LEFT JOIN participants p ON p.id = as2.started_by_participant_id
         WHERE as2.room_session_id = ? AND as2.ended_at IS NULL'
    );
    $stmt->execute([$sessionId]);
    $rows = $stmt->fetchAll();
    $activities = array_map(fn($r) => [
        'lobby_code'     => $r['lobby_code'],
        'activity_type'  => $r['activity_type'],
        'started_by_id'  => (int)$r['started_by_participant_id'],
        'started_by_name'=> $r['started_by_name'] ?? 'Unknown',
    ], $rows);

    echo json_encode(['activities' => $activities]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'start') {
        $sessionId    = (int)($body['session_id'] ?? 0);
        $activityType = preg_replace('/[^a-z_]/', '', $body['activity_type'] ?? '');
        $participantId= (int)($body['participant_id'] ?? 0);
        $joinToken    = $body['join_token'] ?? null;

        if (!$activityType) { echo json_encode(['error' => 'missing fields']); exit; }
        act_authorize($pdo, $sessionId, $participantId, $joinToken);

        $lobbyCode = bin2hex(random_bytes(8)) . '-' . $sessionId;
        $gameIds   = ['paint' => 1, 'chess' => 2, 'checkers' => 3, 'zen_garden' => 4];
        $gameId    = $gameIds[$activityType] ?? 1;

        $pdo->prepare(
            'INSERT INTO activity_sessions (room_session_id, activity_type, lobby_code, started_by_participant_id) VALUES (?,?,?,?)'
        )->execute([$sessionId, $activityType, $lobbyCode, $participantId]);

        // Create lobby record for game backend
        $pdo->prepare(
            'INSERT INTO activity_lobbies (lobby_code, game_id, user1_id, status) VALUES (?,?,?,?)'
        )->execute([$lobbyCode, $gameId, $participantId, 'waiting']);

        // Get participant name for event
        $stmt = $pdo->prepare('SELECT display_name FROM participants WHERE id = ? LIMIT 1');
        $stmt->execute([$participantId]);
        $pName = $stmt->fetchColumn() ?: 'Someone';

        emitEvent($pdo, $sessionId, 'activity_start', [
            'lobby_code'      => $lobbyCode,
            'activity_type'   => $activityType,
            'started_by_id'   => $participantId,
            'started_by_name' => $pName,
        ]);

        echo json_encode(['ok' => true, 'lobby_code' => $lobbyCode]);
        exit;
    }

    if ($action === 'end') {
        $lobbyCode     = $body['lobby_code'] ?? '';
        $sessionId     = (int)($body['session_id'] ?? 0);
        $participantId = (int)($body['participant_id'] ?? 0);
        $joinToken     = $body['join_token'] ?? null;

        if ($lobbyCode === '' || !preg_match('/^[a-f0-9]+-\d+$/', $lobbyCode)) {
            echo json_encode(['error' => 'bad lobby_code']); exit;
        }
        act_authorize($pdo, $sessionId, $participantId, $joinToken);

        // Bind the lobby to the session — prevents cross-session lobby manipulation.
        $bound = $pdo->prepare('SELECT room_session_id FROM activity_sessions WHERE lobby_code = ? LIMIT 1');
        $bound->execute([$lobbyCode]);
        $row = $bound->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['room_session_id'] !== $sessionId) {
            http_response_code(403);
            echo json_encode(['error' => 'lobby does not belong to this session']);
            exit;
        }

        $pdo->prepare('UPDATE activity_sessions SET ended_at = NOW() WHERE lobby_code = ?')->execute([$lobbyCode]);
        $pdo->prepare("UPDATE activity_lobbies SET status = 'ended' WHERE lobby_code = ?")->execute([$lobbyCode]);

        emitEvent($pdo, $sessionId, 'activity_end', ['lobby_code' => $lobbyCode]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

echo json_encode(['error' => 'bad request']);
