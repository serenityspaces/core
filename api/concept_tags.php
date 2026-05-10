<?php
/**
 * Serenity Spaces — Concept / Theme Tag API
 *
 * All routes require practitioner session.
 *
 *   GET  action=list           — practitioner's tag library
 *   POST action=save_tag       — create or update a tag (CSRF)
 *   POST action=delete_tag     — delete a tag (CSRF)
 *   POST action=tag_message    — toggle tag on a message (CSRF)
 *   POST action=tag_note       — toggle tag on a note (session + participant) (CSRF)
 *   GET  action=session_tags   — all concept tags applied in a session (messages + notes)
 *   GET  action=themes         — cross-session theme summary for an end_user_id
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function requirePractitionerTags(): int {
    if (empty($_SESSION['practitioner_id'])) {
        http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit;
    }
    return (int)$_SESSION['practitioner_id'];
}
function verifyCsrfTags(): void {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'CSRF token mismatch']); exit;
    }
}

// ── List tag library ──────────────────────────────────────────────
if ($action === 'list' && $method === 'GET') {
    $pid  = requirePractitionerTags();
    $stmt = $pdo->prepare('SELECT * FROM concept_tags WHERE practitioner_id = ? ORDER BY name ASC');
    $stmt->execute([$pid]);
    echo json_encode(['ok' => true, 'tags' => $stmt->fetchAll()]);
    exit;
}

// ── Save tag (create / update) ────────────────────────────────────
if ($action === 'save_tag' && $method === 'POST') {
    $pid = requirePractitionerTags();
    verifyCsrfTags();

    $id    = (int)($_POST['id'] ?? 0);
    $name  = trim($_POST['name'] ?? '');
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#7c6af7';

    if (!$name) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Name required']); exit; }

    if ($id > 0) {
        $check = $pdo->prepare('SELECT id FROM concept_tags WHERE id = ? AND practitioner_id = ? LIMIT 1');
        $check->execute([$id, $pid]);
        if (!$check->fetch()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Tag not found']); exit; }
        $pdo->prepare('UPDATE concept_tags SET name=?, color=? WHERE id=? AND practitioner_id=?')
            ->execute([$name, $color, $id, $pid]);
    } else {
        try {
            $pdo->prepare('INSERT INTO concept_tags (practitioner_id, name, color) VALUES (?,?,?)')
                ->execute([$pid, $name, $color]);
            $id = (int)$pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                // Duplicate — return existing
                $dup = $pdo->prepare('SELECT id FROM concept_tags WHERE practitioner_id = ? AND name = ? LIMIT 1');
                $dup->execute([$pid, $name]);
                $id = (int)$dup->fetchColumn();
            } else { throw $e; }
        }
    }

    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ── Delete tag ────────────────────────────────────────────────────
if ($action === 'delete_tag' && $method === 'POST') {
    $pid = requirePractitionerTags();
    verifyCsrfTags();
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare('DELETE FROM concept_tags WHERE id = ? AND practitioner_id = ?')->execute([$id, $pid]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Toggle tag on a message ───────────────────────────────────────
if ($action === 'tag_message' && $method === 'POST') {
    $pid = requirePractitionerTags();
    verifyCsrfTags();

    $tagId     = (int)($_POST['tag_id'] ?? 0);
    $messageId = (int)($_POST['message_id'] ?? 0);
    $sessionId = (int)($_POST['session_id'] ?? 0);

    if (!$tagId || !$messageId || !$sessionId) {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'tag_id, message_id, session_id required']); exit;
    }

    // Verify tag ownership
    $check = $pdo->prepare('SELECT id FROM concept_tags WHERE id = ? AND practitioner_id = ? LIMIT 1');
    $check->execute([$tagId, $pid]);
    if (!$check->fetch()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Tag not found']); exit; }

    // Check if already tagged
    $exists = $pdo->prepare('SELECT id FROM message_concept_tags WHERE tag_id = ? AND message_id = ? LIMIT 1');
    $exists->execute([$tagId, $messageId]);
    if ($exists->fetch()) {
        // Remove
        $pdo->prepare('DELETE FROM message_concept_tags WHERE tag_id = ? AND message_id = ?')->execute([$tagId, $messageId]);
        $applied = false;
    } else {
        // Add
        $pdo->prepare('INSERT INTO message_concept_tags (tag_id, message_id, session_id) VALUES (?,?,?)')
            ->execute([$tagId, $messageId, $sessionId]);
        $applied = true;
    }

    // Return updated tags for this message
    $stmt = $pdo->prepare(
        'SELECT ct.id, ct.name, ct.color FROM message_concept_tags mct
         JOIN concept_tags ct ON ct.id = mct.tag_id
         WHERE mct.message_id = ? ORDER BY ct.name ASC'
    );
    $stmt->execute([$messageId]);

    echo json_encode(['ok' => true, 'applied' => $applied, 'tags' => $stmt->fetchAll()]);
    exit;
}

// ── Toggle tag on a note ──────────────────────────────────────────
if ($action === 'tag_note' && $method === 'POST') {
    $pid = requirePractitionerTags();
    verifyCsrfTags();

    $tagId         = (int)($_POST['tag_id'] ?? 0);
    $sessionId     = (int)($_POST['session_id'] ?? 0);
    $participantId = (int)($_POST['participant_id'] ?? 0);

    if (!$tagId || !$sessionId) {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'tag_id and session_id required']); exit;
    }

    // Verify tag ownership
    $check = $pdo->prepare('SELECT id FROM concept_tags WHERE id = ? AND practitioner_id = ? LIMIT 1');
    $check->execute([$tagId, $pid]);
    if (!$check->fetch()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Tag not found']); exit; }

    $pIdVal = $participantId ?: null;

    $exists = $pdo->prepare('SELECT id FROM note_concept_tags WHERE tag_id = ? AND session_id = ? AND participant_id <=> ? LIMIT 1');
    $exists->execute([$tagId, $sessionId, $pIdVal]);
    if ($exists->fetch()) {
        $pdo->prepare('DELETE FROM note_concept_tags WHERE tag_id = ? AND session_id = ? AND participant_id <=> ?')
            ->execute([$tagId, $sessionId, $pIdVal]);
        $applied = false;
    } else {
        $pdo->prepare('INSERT INTO note_concept_tags (tag_id, session_id, participant_id) VALUES (?,?,?)')
            ->execute([$tagId, $sessionId, $pIdVal]);
        $applied = true;
    }

    // Return all tags on this note slot
    $stmt = $pdo->prepare(
        'SELECT ct.id, ct.name, ct.color FROM note_concept_tags nct
         JOIN concept_tags ct ON ct.id = nct.tag_id
         WHERE nct.session_id = ? AND nct.participant_id <=> ? ORDER BY ct.name ASC'
    );
    $stmt->execute([$sessionId, $pIdVal]);

    echo json_encode(['ok' => true, 'applied' => $applied, 'tags' => $stmt->fetchAll()]);
    exit;
}

// ── Session tags (messages + notes combined) ──────────────────────
if ($action === 'session_tags' && $method === 'GET') {
    $pid       = requirePractitionerTags();
    $sessionId = (int)($_GET['session_id'] ?? 0);
    if (!$sessionId) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'session_id required']); exit; }

    // Message tags for this session
    $stmt = $pdo->prepare(
        'SELECT mct.message_id, ct.id AS tag_id, ct.name, ct.color
         FROM message_concept_tags mct
         JOIN concept_tags ct ON ct.id = mct.tag_id
         WHERE mct.session_id = ? AND ct.practitioner_id = ?'
    );
    $stmt->execute([$sessionId, $pid]);
    $msgTags = [];
    foreach ($stmt->fetchAll() as $r) {
        $msgTags[(int)$r['message_id']][] = ['id' => (int)$r['tag_id'], 'name' => $r['name'], 'color' => $r['color']];
    }

    // Note tags for this session
    $stmt2 = $pdo->prepare(
        'SELECT nct.participant_id, ct.id AS tag_id, ct.name, ct.color
         FROM note_concept_tags nct
         JOIN concept_tags ct ON ct.id = nct.tag_id
         WHERE nct.session_id = ? AND ct.practitioner_id = ?'
    );
    $stmt2->execute([$sessionId, $pid]);
    $noteTags = [];
    foreach ($stmt2->fetchAll() as $r) {
        $key = (int)($r['participant_id'] ?? 0);
        $noteTags[$key][] = ['id' => (int)$r['tag_id'], 'name' => $r['name'], 'color' => $r['color']];
    }

    echo json_encode(['ok' => true, 'message_tags' => $msgTags, 'note_tags' => $noteTags]);
    exit;
}

// ── Cross-session themes for a client ─────────────────────────────
if ($action === 'themes' && $method === 'GET') {
    $pid       = requirePractitionerTags();
    $endUserId = (int)($_GET['end_user_id'] ?? 0);
    if (!$endUserId) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'end_user_id required']); exit; }

    // Get all session IDs for this client booked with this practitioner
    $sessStmt = $pdo->prepare(
        'SELECT DISTINCT b.session_id FROM bookings b
         JOIN rooms r ON r.id = (SELECT room_id FROM sessions s2 WHERE s2.id = b.session_id LIMIT 1)
         WHERE b.end_user_id = ? AND r.practitioner_id = ? AND b.session_id IS NOT NULL'
    );
    $sessStmt->execute([$endUserId, $pid]);
    $sessionIds = array_column($sessStmt->fetchAll(), 'session_id');

    if (!$sessionIds) {
        echo json_encode(['ok' => true, 'themes' => []]);
        exit;
    }

    $ph = implode(',', array_fill(0, count($sessionIds), '?'));

    // Message tag counts per concept tag
    $stmt = $pdo->prepare(
        "SELECT ct.id, ct.name, ct.color,
                COUNT(DISTINCT mct.message_id) AS message_count,
                COUNT(DISTINCT mct.session_id) AS session_count,
                GROUP_CONCAT(DISTINCT mct.session_id ORDER BY mct.session_id) AS session_ids
         FROM concept_tags ct
         JOIN message_concept_tags mct ON mct.tag_id = ct.id
         WHERE ct.practitioner_id = ? AND mct.session_id IN ($ph)
         GROUP BY ct.id
         ORDER BY session_count DESC, message_count DESC"
    );
    $stmt->execute(array_merge([$pid], $sessionIds));
    $themes = $stmt->fetchAll();

    // Add note tag counts
    $stmt2 = $pdo->prepare(
        "SELECT nct.tag_id, COUNT(*) AS note_count
         FROM note_concept_tags nct
         JOIN concept_tags ct ON ct.id = nct.tag_id
         WHERE ct.practitioner_id = ? AND nct.session_id IN ($ph)
         GROUP BY nct.tag_id"
    );
    $stmt2->execute(array_merge([$pid], $sessionIds));
    $noteCounts = [];
    foreach ($stmt2->fetchAll() as $r) {
        $noteCounts[(int)$r['tag_id']] = (int)$r['note_count'];
    }

    foreach ($themes as &$t) {
        $t['note_count']  = $noteCounts[(int)$t['id']] ?? 0;
        $t['session_ids'] = array_map('intval', explode(',', $t['session_ids']));
    }

    echo json_encode(['ok' => true, 'themes' => $themes]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
