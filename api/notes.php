<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/audit.php';
require_once __DIR__ . '/../includes/phi_crypto.php';

header('Content-Type: application/json');

// Only practitioners
if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Practitioners only']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? 'save';

// ── GET-style: history lookup ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
}

if ($action === 'history') {
    $sessionId = $_SERVER['REQUEST_METHOD'] === 'GET'
        ? (int)($_GET['session_id'] ?? 0)
        : (int)($input['session_id'] ?? 0);
    $offset = $_SERVER['REQUEST_METHOD'] === 'GET'
        ? max(0, (int)($_GET['offset'] ?? 0))
        : max(0, (int)($input['offset'] ?? 0));
    $pageSize = 10;

    if ($sessionId === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'session_id required']);
        exit;
    }

    $pdo = getDB();
    $practId = (int)$_SESSION['practitioner_id'];

    // Verify ownership of the current session
    $stmt = $pdo->prepare(
        'SELECT r.id FROM rooms r JOIN sessions s ON s.room_id = r.id
         WHERE s.id = ? AND r.practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$sessionId, $practId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    // Derive client identity from the booking linked to this session's room
    // Try end_user_id first, fall back to guest_email
    $clientStmt = $pdo->prepare(
        'SELECT b.end_user_id, b.guest_email
         FROM bookings b
         JOIN sessions s ON s.room_id = b.room_id
         WHERE s.id = ?
         ORDER BY b.id DESC LIMIT 1'
    );
    $clientStmt->execute([$sessionId]);
    $clientRow  = $clientStmt->fetch(PDO::FETCH_ASSOC);
    $endUserId  = $clientRow ? (int)($clientRow['end_user_id'] ?? 0) : 0;
    $guestEmail = $clientRow ? trim($clientRow['guest_email'] ?? '') : '';

    $history = [];
    $hasMore = false;

    if ($endUserId > 0) {
        $stmt = $pdo->prepare(
            'SELECT s.id as session_id, s.started_at, n.note_content
             FROM sessions s
             JOIN rooms r ON r.id = s.room_id
             LEFT JOIN practitioner_notes n ON n.session_id = s.id
             JOIN bookings b ON b.room_id = s.room_id
             WHERE r.practitioner_id = ?
               AND s.id <> ?
               AND b.end_user_id = ?
               AND n.note_content IS NOT NULL
               AND n.note_content <> \'\'
             GROUP BY s.id
             ORDER BY s.started_at DESC
             LIMIT ' . ($pageSize + 1) . ' OFFSET ' . (int)$offset
        );
        $stmt->execute([$practId, $sessionId, $endUserId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($history) && $guestEmail !== '') {
        $stmt = $pdo->prepare(
            'SELECT s.id as session_id, s.started_at, n.note_content
             FROM sessions s
             JOIN rooms r ON r.id = s.room_id
             LEFT JOIN practitioner_notes n ON n.session_id = s.id
             JOIN bookings b ON b.room_id = s.room_id
             WHERE r.practitioner_id = ?
               AND s.id <> ?
               AND b.guest_email = ?
               AND n.note_content IS NOT NULL
               AND n.note_content <> \'\'
             GROUP BY s.id
             ORDER BY s.started_at DESC
             LIMIT ' . ($pageSize + 1) . ' OFFSET ' . (int)$offset
        );
        $stmt->execute([$practId, $sessionId, $guestEmail]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Page-size + 1 trick: if we got more than pageSize, there's more to load.
    if (count($history) > $pageSize) {
        $hasMore = true;
        array_pop($history);
    }

    // Decrypt note_content before returning
    foreach ($history as &$h) {
        if (isset($h['note_content'])) $h['note_content'] = phi_decrypt($h['note_content'], 'notes:' . ($h['session_id'] ?? ''));
    }
    unset($h);
    echo json_encode(['history' => $history, 'has_more' => $hasMore, 'offset' => $offset + count($history)]);
    exit;
}

// ── Delete: practitioner amends/retracts notes for a participant ───
if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required']);
        exit;
    }
    $sessionId     = (int)($input['session_id'] ?? 0);
    $participantId = (int)($input['participant_id'] ?? 0);
    if ($sessionId === 0 || $participantId === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'session_id and participant_id required']);
        exit;
    }
    $pdo = getDB();
    $practId = (int)$_SESSION['practitioner_id'];

    // Verify ownership
    $stmt = $pdo->prepare(
        'SELECT r.id FROM rooms r JOIN sessions s ON s.room_id = r.id
         WHERE s.id = ? AND r.practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$sessionId, $practId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    $del = $pdo->prepare(
        'DELETE FROM practitioner_notes WHERE session_id = ? AND participant_id = ?'
    );
    $del->execute([$sessionId, $participantId]);

    audit_log($pdo, 'notes.delete', [
        'practitioner_id' => $practId,
        'participant_id'  => $participantId,
        'session_id'      => $sessionId,
        'entity_type'     => 'notes',
        'entity_id'       => $participantId,
    ]);

    echo json_encode(['ok' => true, 'deleted' => $del->rowCount()]);
    exit;
}

// ── Default: save note ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$sessionId     = (int)($input['session_id'] ?? 0);
$participantId = (int)($input['participant_id'] ?? 0);
$noteContent   = $input['note_content'] ?? '';

if ($sessionId === 0 || $participantId === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'session_id and participant_id required']);
    exit;
}

$pdo = getDB();

// Verify ownership
$stmt = $pdo->prepare(
    'SELECT r.id FROM rooms r
     JOIN sessions s ON s.room_id = r.id
     WHERE s.id = ? AND r.practitioner_id = ? LIMIT 1'
);
$stmt->execute([$sessionId, (int)$_SESSION['practitioner_id']]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Verify participant belongs to this session
$stmt = $pdo->prepare('SELECT id FROM participants WHERE id = ? AND session_id = ? LIMIT 1');
$stmt->execute([$participantId, $sessionId]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Participant not in this session']);
    exit;
}

// Upsert
$stmt = $pdo->prepare(
    'INSERT INTO practitioner_notes (session_id, participant_id, note_content)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE note_content = VALUES(note_content), updated_at = NOW()'
);
$stmt->execute([$sessionId, $participantId, phi_encrypt($noteContent, 'notes:' . $sessionId)]);

audit_log($pdo, 'notes.write', [
    'practitioner_id' => (int)$_SESSION['practitioner_id'],
    'participant_id'  => $participantId,
    'session_id'      => $sessionId,
    'entity_type'     => 'notes',
    'entity_id'       => $participantId,
]);

echo json_encode(['ok' => true]);
