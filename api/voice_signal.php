<?php
/**
 * Voice Chat Signaling
 * GET  ?session_id=N&participant_id=N&after=N&join_token=T  → poll for signals
 * POST {action:'join'|'leave'|'signal', ..., join_token:T}  → submit signal
 *
 * Auth: every request must either come from a logged-in practitioner who owns
 * the session, OR include a join_token that matches participant_id within
 * session_id. No bare (session_id, participant_id) tuple is accepted.
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
header('Content-Type: application/json');

$pdo = getDB();

/**
 * Verify the requester is allowed to act as $participantId in $sessionId.
 * Returns true on success, exits with 403 on failure.
 */
function vs_authorize(PDO $pdo, int $sessionId, int $participantId, ?string $joinToken): void {
    if ($sessionId <= 0 || $participantId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'missing params']);
        exit;
    }

    // Practitioner path: logged-in practitioner who owns the session.
    if (!empty($_SESSION['practitioner_id'])) {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM rooms r
             JOIN sessions s ON s.room_id = r.id
             JOIN participants p ON p.session_id = s.id
             WHERE s.id = ? AND p.id = ? AND r.practitioner_id = ? LIMIT 1'
        );
        $stmt->execute([$sessionId, $participantId, (int)$_SESSION['practitioner_id']]);
        if ($stmt->fetchColumn()) return;
    }

    // Client path: 48-char hex join_token must belong to this exact participant.
    if (is_string($joinToken) && $joinToken !== '') {
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
    $sessionId     = (int)($_GET['session_id']     ?? 0);
    $participantId = (int)($_GET['participant_id'] ?? 0);
    $after         = (int)($_GET['after']          ?? 0);
    $joinToken     = $_GET['join_token']           ?? null;

    vs_authorize($pdo, $sessionId, $participantId, $joinToken);

    // Get current voice participants for this session
    $stmt = $pdo->prepare(
        'SELECT vs.participant_id, p.display_name
         FROM voice_sessions vs
         JOIN participants p ON p.id = vs.participant_id
         WHERE vs.session_id = ?'
    );
    $stmt->execute([$sessionId]);
    $voiceParticipants = $stmt->fetchAll();

    // Get signals directed to this participant
    $stmt = $pdo->prepare(
        'SELECT id, from_participant_id, type, data
         FROM voice_signals
         WHERE session_id = ? AND to_participant_id = ? AND id > ?
         ORDER BY id ASC
         LIMIT 50'
    );
    $stmt->execute([$sessionId, $participantId, $after]);
    $signals = $stmt->fetchAll();

    // Also check for join/leave broadcast events (from_participant_id = 0 means broadcast)
    $stmt2 = $pdo->prepare(
        'SELECT id, from_participant_id, type, data
         FROM voice_signals
         WHERE session_id = ? AND to_participant_id = 0 AND id > ?
         ORDER BY id ASC
         LIMIT 20'
    );
    $stmt2->execute([$sessionId, $after]);
    $broadcasts = $stmt2->fetchAll();

    $allSignals = array_merge($signals, $broadcasts);
    usort($allSignals, fn($a, $b) => $a['id'] <=> $b['id']);

    // Decode JSON data field
    foreach ($allSignals as &$sig) {
        $sig['id']   = (int)$sig['id'];
        $sig['from_participant_id'] = (int)$sig['from_participant_id'];
        $sig['data'] = json_decode($sig['data'], true);
    }
    unset($sig);

    echo json_encode([
        'signals'            => $allSignals,
        'voice_participants' => array_map(fn($r) => [
            'id'           => (int)$r['participant_id'],
            'display_name' => $r['display_name'],
        ], $voiceParticipants),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'join') {
        $sessionId     = (int)($body['session_id']     ?? 0);
        $participantId = (int)($body['participant_id'] ?? 0);
        $joinToken     = $body['join_token']           ?? null;
        vs_authorize($pdo, $sessionId, $participantId, $joinToken);

        // Clean up stale voice sessions (participants who left without explicitly calling leave)
        $pdo->prepare(
            'DELETE vs FROM voice_sessions vs
             JOIN participants p ON p.id = vs.participant_id
             WHERE vs.session_id = ? AND vs.joined_at < NOW() - INTERVAL 10 MINUTE'
        )->execute([$sessionId]);

        $pdo->prepare(
            'INSERT INTO voice_sessions (participant_id, session_id) VALUES (?,?) ON DUPLICATE KEY UPDATE joined_at = NOW()'
        )->execute([$participantId, $sessionId]);

        // Broadcast join signal to all other voice participants
        $stmt = $pdo->prepare('SELECT participant_id FROM voice_sessions WHERE session_id = ? AND participant_id != ?');
        $stmt->execute([$sessionId, $participantId]);
        foreach ($stmt->fetchAll() as $vp) {
            $pdo->prepare(
                'INSERT INTO voice_signals (session_id, from_participant_id, to_participant_id, type, data) VALUES (?,?,?,?,?)'
            )->execute([$sessionId, $participantId, (int)$vp['participant_id'], 'join', json_encode(['participant_id' => $participantId])]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'leave') {
        $sessionId     = (int)($body['session_id']     ?? 0);
        $participantId = (int)($body['participant_id'] ?? 0);
        $joinToken     = $body['join_token']           ?? null;
        vs_authorize($pdo, $sessionId, $participantId, $joinToken);

        $pdo->prepare('DELETE FROM voice_sessions WHERE participant_id = ?')->execute([$participantId]);

        // Broadcast leave signal to remaining voice participants
        $stmt = $pdo->prepare('SELECT participant_id FROM voice_sessions WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        foreach ($stmt->fetchAll() as $vp) {
            $pdo->prepare(
                'INSERT INTO voice_signals (session_id, from_participant_id, to_participant_id, type, data) VALUES (?,?,?,?,?)'
            )->execute([$sessionId, $participantId, (int)$vp['participant_id'], 'leave', json_encode(['participant_id' => $participantId])]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'signal') {
        $sessionId = (int)($body['session_id'] ?? 0);
        $fromId    = (int)($body['from_id']    ?? 0);
        $toId      = (int)($body['to_id']      ?? 0);
        $type      = $body['type']  ?? '';
        $data      = $body['data']  ?? null;
        $joinToken = $body['join_token'] ?? null;

        if (!$type) { echo json_encode(['error' => 'missing fields']); exit; }
        // Caller must be the from_id participant in this session.
        vs_authorize($pdo, $sessionId, $fromId, $joinToken);

        // Prevent forging the from_participant_id field on the wire.
        $pdo->prepare(
            'INSERT INTO voice_signals (session_id, from_participant_id, to_participant_id, type, data) VALUES (?,?,?,?,?)'
        )->execute([$sessionId, $fromId, $toId, $type, json_encode($data)]);

        echo json_encode(['ok' => true]);
        exit;
    }
}

echo json_encode(['error' => 'bad request']);
