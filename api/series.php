<?php
/**
 * Session Series (Inquiry Arc) API
 *
 * GET  ?action=list                     — list all series for practitioner
 * GET  ?action=get&id=N                 — get series detail with sessions + recaps
 * POST {action:'create', name, description, client_email, end_user_id}
 * POST {action:'update', id, name, description, shared_notes}
 * POST {action:'delete', id}
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/audit.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$practId = (int)$_SESSION['practitioner_id'];
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

// ── GET ─────────────────────────────────────────────────────────
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $stmt = $pdo->prepare(
            'SELECT ss.id, ss.name, ss.description, ss.client_email, ss.end_user_id,
                    ss.shared_notes, ss.created_at,
                    eu.display_name AS client_name,
                    COUNT(s.id) AS session_count
             FROM session_series ss
             LEFT JOIN end_users eu ON eu.id = ss.end_user_id
             LEFT JOIN sessions s ON s.series_id = ss.id
             WHERE ss.practitioner_id = ?
             GROUP BY ss.id
             ORDER BY ss.created_at DESC'
        );
        $stmt->execute([$practId]);
        echo json_encode(['series' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

        $stmt = $pdo->prepare(
            'SELECT ss.*, eu.display_name AS client_name
             FROM session_series ss
             LEFT JOIN end_users eu ON eu.id = ss.end_user_id
             WHERE ss.id = ? AND ss.practitioner_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $practId]);
        $series = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$series) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

        // Sessions in this series with their recaps
        $stmtS = $pdo->prepare(
            'SELECT s.id, s.started_at, s.ended_at, s.transcript_exported_at,
                    r.name AS room_name,
                    sr.key_summary, sr.key_insights, sr.agreed_actions, sr.next_focus
             FROM sessions s
             JOIN rooms r ON r.id = s.room_id
             LEFT JOIN session_recap sr ON sr.session_id = s.id
             WHERE s.series_id = ? AND r.practitioner_id = ?
             ORDER BY s.started_at DESC'
        );
        $stmtS->execute([$id, $practId]);

        echo json_encode(['series' => $series, 'sessions' => $stmtS->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ── POST ────────────────────────────────────────────────────────
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

if ($action === 'create') {
    $name     = trim($input['name'] ?? '');
    if ($name === '') { http_response_code(400); echo json_encode(['error' => 'name required']); exit; }

    $desc     = trim($input['description'] ?? '');
    $email    = trim($input['client_email'] ?? '');
    $userId   = isset($input['end_user_id']) ? (int)$input['end_user_id'] : null;

    // Validate end_user_id belongs to this practitioner's clients
    if ($userId) {
        $chk = $pdo->prepare(
            'SELECT eu.id FROM end_users eu
             JOIN bookings b ON b.end_user_id = eu.id
             WHERE eu.id = ? AND b.practitioner_id = ? LIMIT 1'
        );
        $chk->execute([$userId, $practId]);
        if (!$chk->fetch()) $userId = null;
    }

    $pdo->prepare(
        'INSERT INTO session_series (practitioner_id, name, description, client_email, end_user_id)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$practId, $name, $desc ?: null, $email ?: null, $userId]);

    $newId = (int)$pdo->lastInsertId();
    audit_log($pdo, 'series.create', ['practitioner_id' => $practId, 'entity_type' => 'series', 'entity_id' => $newId]);
    echo json_encode(['ok' => true, 'id' => $newId]);
    exit;
}

if ($action === 'update') {
    $id   = (int)($input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

    // Verify ownership
    $chk = $pdo->prepare('SELECT id FROM session_series WHERE id = ? AND practitioner_id = ? LIMIT 1');
    $chk->execute([$id, $practId]);
    if (!$chk->fetch()) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

    $name   = trim($input['name'] ?? '');
    $desc   = trim($input['description'] ?? '');
    $notes  = trim($input['shared_notes'] ?? '');

    if ($name !== '') {
        $pdo->prepare(
            'UPDATE session_series SET name = ?, description = ?, shared_notes = ? WHERE id = ?'
        )->execute([$name, $desc ?: null, $notes ?: null, $id]);
    } else {
        $pdo->prepare(
            'UPDATE session_series SET shared_notes = ? WHERE id = ?'
        )->execute([$notes ?: null, $id]);
    }

    audit_log($pdo, 'series.update', ['practitioner_id' => $practId, 'entity_type' => 'series', 'entity_id' => $id]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

    $chk = $pdo->prepare('SELECT id FROM session_series WHERE id = ? AND practitioner_id = ? LIMIT 1');
    $chk->execute([$id, $practId]);
    if (!$chk->fetch()) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

    // Unlink sessions from this series before deleting
    $pdo->prepare('UPDATE sessions SET series_id = NULL WHERE series_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM session_series WHERE id = ?')->execute([$id]);

    audit_log($pdo, 'series.delete', ['practitioner_id' => $practId, 'entity_type' => 'series', 'entity_id' => $id]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
