<?php
/**
 * Serenity Spaces — DC (Distributed/Alternative Location) API
 *
 * This endpoint handles inter-server communication between the Primary server
 * and Alternative Location servers. All requests must carry a valid HMAC-SHA256
 * signature (see includes/dc_auth.php).
 *
 * Actions (POST, JSON body):
 *
 *   ping                — Health check from Primary; returns server info
 *   sync_session        — Primary pushes session + participant data to Alt server
 *   messages            — Client/practitioner posts a message (Alt stores it)
 *   poll                — Client/practitioner polls for new messages (Alt reads)
 *   revoke_session      — Primary notifies Alt to mark session as ended
 *
 * On Primary server (INSTALL_TYPE = 'primary'), the Primary calls Alt servers.
 * On Alternative server (INSTALL_TYPE = 'alternative'), this file handles those calls.
 *
 * Security: DC_SHARED_SECRET must be set in db/config.php. All requests
 * are authenticated via HMAC-SHA256 with replay protection (30-second TTL).
 */

require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/dc_auth.php';
require_once __DIR__ . '/../includes/security_headers.php';

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

// DC shared secret must be configured
if (!defined('DC_SHARED_SECRET') || DC_SHARED_SECRET === '') {
    http_response_code(503);
    echo json_encode(['error' => 'DC not configured on this server']);
    exit;
}

// Read raw body and verify signature
$rawBody = file_get_contents('php://input');
$headers = dc_get_headers();

if (!dc_verify_request($rawBody, $headers, DC_SHARED_SECRET)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired DC signature']);
    exit;
}

$input  = json_decode($rawBody, true);
$action = $input['action'] ?? '';

if (!$action) {
    http_response_code(400);
    echo json_encode(['error' => 'action required']);
    exit;
}

$pdo = getDB();

// ── ping ──────────────────────────────────────────────────────
if ($action === 'ping') {
    echo json_encode([
        'ok'           => true,
        'install_type' => defined('INSTALL_TYPE') ? INSTALL_TYPE : 'unknown',
        'country'      => defined('DC_COUNTRY')   ? DC_COUNTRY   : null,
        'server_time'  => time(),
        'php_version'  => PHP_VERSION,
    ]);
    exit;
}

// ── sync_session ──────────────────────────────────────────────
// Primary → Alt: push session + participant join_tokens so clients can auth
if ($action === 'sync_session') {
    $sessionId  = (int)($input['session_id']  ?? 0);
    $roomId     = (int)($input['room_id']     ?? 0);
    $participants = $input['participants']    ?? []; // [{id, join_token, is_practitioner}]

    if (!$sessionId || !$roomId) {
        http_response_code(400);
        echo json_encode(['error' => 'session_id and room_id required']);
        exit;
    }

    try {
        // Upsert session record
        $pdo->prepare(
            'INSERT INTO dc_sessions (session_id, room_id, synced_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE room_id = VALUES(room_id), synced_at = NOW()'
        )->execute([$sessionId, $roomId]);

        // Upsert each participant
        foreach ((array)$participants as $p) {
            $pid            = (int)($p['id']              ?? 0);
            $joinToken      = trim($p['join_token']       ?? '');
            $isPractitioner = (int)($p['is_practitioner'] ?? 0);
            if (!$pid || !$joinToken) continue;

            $pdo->prepare(
                'INSERT INTO dc_participants (participant_id, session_id, join_token, is_practitioner, synced_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE join_token = VALUES(join_token), synced_at = NOW()'
            )->execute([$pid, $sessionId, $joinToken, $isPractitioner]);
        }

        echo json_encode(['ok' => true, 'session_id' => $sessionId]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

// ── messages (write) ──────────────────────────────────────────
// Client/practitioner posts a message via Alt server
if ($action === 'messages') {
    $joinToken = trim($input['join_token'] ?? '');
    $content   = $input['content']         ?? '';
    $msgType   = $input['type']            ?? 'text';

    if (!$joinToken || $content === '') {
        http_response_code(400);
        echo json_encode(['error' => 'join_token and content required']);
        exit;
    }

    // Authenticate join_token against dc_participants
    $stmt = $pdo->prepare(
        'SELECT p.participant_id, p.session_id, p.is_practitioner
         FROM dc_participants p
         WHERE p.join_token = ?
         LIMIT 1'
    );
    $stmt->execute([$joinToken]);
    $participant = $stmt->fetch();

    if (!$participant) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid join token']);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO messages (session_id, sender_id, content, message_type, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            (int)$participant['session_id'],
            (int)$participant['participant_id'],
            $content,
            $msgType,
        ]);

        echo json_encode(['ok' => true, 'message_id' => (int)$pdo->lastInsertId()]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

// ── poll (read) ───────────────────────────────────────────────
// Client/practitioner polls for new messages via Alt server
if ($action === 'poll') {
    $joinToken = trim($input['join_token'] ?? '');
    $since     = (int)($input['since_id']  ?? 0);

    if (!$joinToken) {
        http_response_code(400);
        echo json_encode(['error' => 'join_token required']);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT p.participant_id, p.session_id
         FROM dc_participants p
         WHERE p.join_token = ?
         LIMIT 1'
    );
    $stmt->execute([$joinToken]);
    $participant = $stmt->fetch();

    if (!$participant) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid join token']);
        exit;
    }

    $sessionId = (int)$participant['session_id'];

    $stmt = $pdo->prepare(
        'SELECT m.id, m.sender_id, m.content, m.message_type,
                DATE_FORMAT(m.created_at, "%Y-%m-%dT%TZ") AS created_at
         FROM messages m
         WHERE m.session_id = ? AND m.id > ?
         ORDER BY m.id ASC
         LIMIT 100'
    );
    $stmt->execute([$sessionId, $since]);
    $messages = $stmt->fetchAll();

    echo json_encode(['ok' => true, 'messages' => $messages]);
    exit;
}

// ── revoke_session ────────────────────────────────────────────
// Primary notifies Alt that a session has ended (optional cleanup signal)
if ($action === 'revoke_session') {
    $sessionId = (int)($input['session_id'] ?? 0);

    if (!$sessionId) {
        http_response_code(400);
        echo json_encode(['error' => 'session_id required']);
        exit;
    }

    // Remove participant auth records — messages stay for export
    $pdo->prepare('DELETE FROM dc_participants WHERE session_id = ?')->execute([$sessionId]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
