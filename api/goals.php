<?php
/**
 * Serenity Spaces — Goal & Progress Tracking API
 *
 * All routes require practitioner session.
 *
 *   GET  action=list              — list goals for an end_user_id
 *   POST action=save_goal         — create or update a goal (CSRF)
 *   POST action=delete_goal       — delete a goal (CSRF)
 *   POST action=save_milestone    — add a milestone to a goal (CSRF)
 *   POST action=toggle_milestone  — mark/unmark a milestone complete (CSRF)
 *   POST action=delete_milestone  — delete a milestone (CSRF)
 *   POST action=add_update        — log a progress update (CSRF)
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

function requirePractitionerGoals(): int {
    if (empty($_SESSION['practitioner_id'])) {
        http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit;
    }
    return (int)$_SESSION['practitioner_id'];
}

function verifyCsrfGoals(): void {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'CSRF token mismatch']); exit;
    }
}

// ── List goals for a client ──────────────────────────────────
if ($action === 'list' && $method === 'GET') {
    $pid       = requirePractitionerGoals();
    $endUserId = (int)($_GET['end_user_id'] ?? 0);
    if (!$endUserId) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'end_user_id required']); exit; }

    // Goals
    $stmt = $pdo->prepare(
        'SELECT g.*,
                (SELECT COUNT(*) FROM goal_milestones m WHERE m.goal_id = g.id) AS milestone_count,
                (SELECT COUNT(*) FROM goal_milestones m WHERE m.goal_id = g.id AND m.completed_at IS NOT NULL) AS milestones_done,
                (SELECT progress_pct FROM goal_updates u WHERE u.goal_id = g.id AND u.progress_pct IS NOT NULL ORDER BY u.created_at DESC LIMIT 1) AS latest_pct
         FROM client_goals g
         WHERE g.practitioner_id = ? AND g.end_user_id = ?
         ORDER BY FIELD(g.status,"active","paused","completed","abandoned"), g.created_at DESC'
    );
    $stmt->execute([$pid, $endUserId]);
    $goals = $stmt->fetchAll();

    // For each goal, fetch milestones and recent updates
    foreach ($goals as &$g) {
        $mStmt = $pdo->prepare(
            'SELECT * FROM goal_milestones WHERE goal_id = ? ORDER BY sort_order ASC, created_at ASC'
        );
        $mStmt->execute([$g['id']]);
        $g['milestones'] = $mStmt->fetchAll();

        $uStmt = $pdo->prepare(
            'SELECT * FROM goal_updates WHERE goal_id = ? ORDER BY created_at DESC LIMIT 5'
        );
        $uStmt->execute([$g['id']]);
        $g['updates'] = $uStmt->fetchAll();
    }

    echo json_encode(['ok' => true, 'goals' => $goals]);
    exit;
}

// ── Save (create/update) a goal ──────────────────────────────
if ($action === 'save_goal' && $method === 'POST') {
    $pid = requirePractitionerGoals();
    verifyCsrfGoals();

    $id          = (int)($_POST['id'] ?? 0);
    $endUserId   = (int)($_POST['end_user_id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '') ?: null;
    $targetDate  = trim($_POST['target_date'] ?? '') ?: null;
    $status      = in_array($_POST['status'] ?? '', ['active','completed','paused','abandoned'])
                   ? $_POST['status'] : 'active';

    if (!$title) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Title is required']); exit; }
    if ($targetDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) $targetDate = null;

    if ($id > 0) {
        // Update — verify ownership
        $check = $pdo->prepare('SELECT id FROM client_goals WHERE id = ? AND practitioner_id = ? LIMIT 1');
        $check->execute([$id, $pid]);
        if (!$check->fetch()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Goal not found']); exit; }
        $pdo->prepare(
            'UPDATE client_goals SET title=?, description=?, target_date=?, status=? WHERE id=? AND practitioner_id=?'
        )->execute([$title, $description, $targetDate, $status, $id, $pid]);
    } else {
        if (!$endUserId) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'end_user_id required']); exit; }
        $pdo->prepare(
            'INSERT INTO client_goals (practitioner_id, end_user_id, title, description, target_date, status) VALUES (?,?,?,?,?,?)'
        )->execute([$pid, $endUserId, $title, $description, $targetDate, $status]);
        $id = (int)$pdo->lastInsertId();
    }

    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ── Delete a goal ────────────────────────────────────────────
if ($action === 'delete_goal' && $method === 'POST') {
    $pid = requirePractitionerGoals();
    verifyCsrfGoals();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM client_goals WHERE id = ? AND practitioner_id = ?');
    $stmt->execute([$id, $pid]);
    if ($stmt->rowCount() === 0) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Goal not found']); exit; }
    echo json_encode(['ok' => true]);
    exit;
}

// ── Add a milestone ──────────────────────────────────────────
if ($action === 'save_milestone' && $method === 'POST') {
    $pid = requirePractitionerGoals();
    verifyCsrfGoals();

    $goalId = (int)($_POST['goal_id'] ?? 0);
    $title  = trim($_POST['title'] ?? '');
    if (!$title) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Title required']); exit; }

    // Verify goal ownership
    $check = $pdo->prepare('SELECT id FROM client_goals WHERE id = ? AND practitioner_id = ? LIMIT 1');
    $check->execute([$goalId, $pid]);
    if (!$check->fetch()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Goal not found']); exit; }

    $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM goal_milestones WHERE goal_id = ?');
    $sortStmt->execute([$goalId]);
    $sort = (int)$sortStmt->fetchColumn();

    $pdo->prepare('INSERT INTO goal_milestones (goal_id, title, sort_order) VALUES (?,?,?)')
        ->execute([$goalId, $title, $sort]);
    $mid = (int)$pdo->lastInsertId();

    echo json_encode(['ok' => true, 'id' => $mid]);
    exit;
}

// ── Toggle milestone completion ──────────────────────────────
if ($action === 'toggle_milestone' && $method === 'POST') {
    $pid = requirePractitionerGoals();
    verifyCsrfGoals();

    $milestoneId = (int)($_POST['milestone_id'] ?? 0);
    // Verify ownership via goal
    $stmt = $pdo->prepare(
        'SELECT m.id, m.completed_at FROM goal_milestones m
         JOIN client_goals g ON g.id = m.goal_id
         WHERE m.id = ? AND g.practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$milestoneId, $pid]);
    $ms = $stmt->fetch();
    if (!$ms) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Milestone not found']); exit; }

    $newVal = $ms['completed_at'] ? null : date('Y-m-d H:i:s');
    $pdo->prepare('UPDATE goal_milestones SET completed_at = ? WHERE id = ?')->execute([$newVal, $milestoneId]);
    echo json_encode(['ok' => true, 'completed_at' => $newVal]);
    exit;
}

// ── Delete milestone ─────────────────────────────────────────
if ($action === 'delete_milestone' && $method === 'POST') {
    $pid = requirePractitionerGoals();
    verifyCsrfGoals();
    $milestoneId = (int)($_POST['milestone_id'] ?? 0);
    $stmt = $pdo->prepare(
        'DELETE m FROM goal_milestones m
         JOIN client_goals g ON g.id = m.goal_id
         WHERE m.id = ? AND g.practitioner_id = ?'
    );
    $stmt->execute([$milestoneId, $pid]);
    if ($stmt->rowCount() === 0) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Milestone not found']); exit; }
    echo json_encode(['ok' => true]);
    exit;
}

// ── Log a progress update ────────────────────────────────────
if ($action === 'add_update' && $method === 'POST') {
    $pid = requirePractitionerGoals();
    verifyCsrfGoals();

    $goalId      = (int)($_POST['goal_id'] ?? 0);
    $note        = trim($_POST['note'] ?? '') ?: null;
    $progressPct = $_POST['progress_pct'] !== '' && $_POST['progress_pct'] !== null
                   ? min(100, max(0, (int)($_POST['progress_pct'] ?? 0)))
                   : null;
    $progressPct = (isset($_POST['progress_pct']) && $_POST['progress_pct'] !== '') ? $progressPct : null;

    // Verify goal ownership
    $check = $pdo->prepare('SELECT id FROM client_goals WHERE id = ? AND practitioner_id = ? LIMIT 1');
    $check->execute([$goalId, $pid]);
    if (!$check->fetch()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Goal not found']); exit; }

    if (!$note && $progressPct === null) {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Provide a note or progress percentage']); exit;
    }

    $pdo->prepare('INSERT INTO goal_updates (goal_id, note, progress_pct) VALUES (?,?,?)')
        ->execute([$goalId, $note, $progressPct]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action or method']);
