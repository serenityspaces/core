<?php
/**
 * Discount Codes API
 *
 * Public (no session required):
 *   GET  action=validate  — validate a code for a practitioner; returns pct and label
 *
 * Practitioner (session required):
 *   GET  action=list      — list this practitioner's codes with usage stats
 *   POST action=save      — create or update a code (CSRF)
 *   POST action=delete    — delete a code (CSRF)
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

// ── CSRF helper ────────────────────────────────────────────────
function dcVerifyCsrf(): void {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF token mismatch']);
        exit;
    }
}

// ── Auth helper ────────────────────────────────────────────────
function dcRequirePractitioner(): int {
    if (empty($_SESSION['practitioner_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }
    return (int)$_SESSION['practitioner_id'];
}

// ── Public: validate a code ────────────────────────────────────
// GET action=validate&practitioner_id=X&code=Y
// Returns: {valid, discount_pct, remaining_total (null=unlimited), message}
if ($action === 'validate' && $method === 'GET') {
    $practId = (int)($_GET['practitioner_id'] ?? 0);
    $code    = strtoupper(trim($_GET['code'] ?? ''));

    if (!$practId || $code === '') {
        echo json_encode(['valid' => false, 'error' => 'Missing parameters']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM discount_codes
         WHERE practitioner_id = ? AND code = ? AND is_active = 1
           AND valid_from <= CURDATE()
           AND (valid_until IS NULL OR valid_until >= CURDATE())
         LIMIT 1"
    );
    $stmt->execute([$practId, $code]);
    $dc = $stmt->fetch();

    if (!$dc) {
        echo json_encode(['valid' => false, 'error' => 'Code not found or expired']);
        exit;
    }

    // Check total uses
    if ($dc['max_total_uses'] !== null) {
        $uStmt = $pdo->prepare('SELECT COUNT(*) FROM discount_code_uses WHERE code_id = ?');
        $uStmt->execute([$dc['id']]);
        $usedTotal = (int)$uStmt->fetchColumn();
        if ($usedTotal >= (int)$dc['max_total_uses']) {
            echo json_encode(['valid' => false, 'error' => 'This code has reached its usage limit']);
            exit;
        }
        $remaining = max(0, (int)$dc['max_total_uses'] - $usedTotal);
    } else {
        $remaining = null; // unrestricted
    }

    echo json_encode([
        'valid'           => true,
        'discount_pct'    => (int)$dc['discount_pct'],
        'remaining_total' => $remaining,
    ]);
    exit;
}

// ── Practitioner: list codes ───────────────────────────────────
if ($action === 'list' && $method === 'GET') {
    $pid = dcRequirePractitioner();

    $stmt = $pdo->prepare(
        "SELECT dc.*,
                COUNT(dcu.id)        AS total_uses,
                COUNT(DISTINCT dcu.end_user_id) AS unique_clients
         FROM discount_codes dc
         LEFT JOIN discount_code_uses dcu ON dcu.code_id = dc.id
         WHERE dc.practitioner_id = ?
         GROUP BY dc.id
         ORDER BY dc.created_at DESC"
    );
    $stmt->execute([$pid]);
    $codes = $stmt->fetchAll();

    echo json_encode(['ok' => true, 'codes' => $codes]);
    exit;
}

// ── Practitioner: save (create/update) ────────────────────────
if ($action === 'save' && $method === 'POST') {
    $pid = dcRequirePractitioner();
    dcVerifyCsrf();

    $id          = (int)($_POST['id'] ?? 0);
    $code        = strtoupper(preg_replace('/[^A-Z0-9_\-]/', '', strtoupper(trim($_POST['code'] ?? ''))));
    $pct         = min(100, max(0, (int)($_POST['discount_pct'] ?? 0)));
    $validFrom   = trim($_POST['valid_from'] ?? '');
    $validUntil  = trim($_POST['valid_until'] ?? '') ?: null;

    // Use limits — empty string or 'null' means unrestricted
    $maxTotalRaw    = trim($_POST['max_total_uses'] ?? '');
    $maxClientRaw   = trim($_POST['max_uses_per_client'] ?? '');
    $maxTotal       = ($maxTotalRaw  === '' || $maxTotalRaw  === 'null') ? null : max(1, (int)$maxTotalRaw);
    $maxPerClient   = ($maxClientRaw === '' || $maxClientRaw === 'null') ? null : max(1, (int)$maxClientRaw);

    $isActive    = !empty($_POST['is_active']) ? 1 : 0;

    if ($code === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Code is required (letters, numbers, hyphens, underscores)']);
        exit;
    }
    if (strlen($code) > 64) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Code must be 64 characters or fewer']);
        exit;
    }
    if (!$validFrom || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Valid From date is required']);
        exit;
    }
    if ($validUntil && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid Valid Until date']);
        exit;
    }
    if ($validUntil && $validUntil < $validFrom) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Valid Until must be on or after Valid From']);
        exit;
    }

    if ($id > 0) {
        // Update — verify ownership
        $check = $pdo->prepare('SELECT id FROM discount_codes WHERE id = ? AND practitioner_id = ? LIMIT 1');
        $check->execute([$id, $pid]);
        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Code not found']);
            exit;
        }
        // Check for duplicate code on another record
        $dup = $pdo->prepare('SELECT id FROM discount_codes WHERE practitioner_id = ? AND code = ? AND id != ? LIMIT 1');
        $dup->execute([$pid, $code, $id]);
        if ($dup->fetch()) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'You already have a code with that name']);
            exit;
        }
        $pdo->prepare(
            'UPDATE discount_codes
             SET code=?, discount_pct=?, valid_from=?, valid_until=?,
                 max_total_uses=?, max_uses_per_client=?, is_active=?
             WHERE id=? AND practitioner_id=?'
        )->execute([$code, $pct, $validFrom, $validUntil, $maxTotal, $maxPerClient, $isActive, $id, $pid]);
    } else {
        // Insert — check for duplicate
        $dup = $pdo->prepare('SELECT id FROM discount_codes WHERE practitioner_id = ? AND code = ? LIMIT 1');
        $dup->execute([$pid, $code]);
        if ($dup->fetch()) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'You already have a code with that name']);
            exit;
        }
        $pdo->prepare(
            'INSERT INTO discount_codes
                (practitioner_id, code, discount_pct, valid_from, valid_until,
                 max_total_uses, max_uses_per_client, is_active)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$pid, $code, $pct, $validFrom, $validUntil, $maxTotal, $maxPerClient, $isActive]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── Practitioner: delete ───────────────────────────────────────
if ($action === 'delete' && $method === 'POST') {
    $pid = dcRequirePractitioner();
    dcVerifyCsrf();

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'ID required']);
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM discount_codes WHERE id = ? AND practitioner_id = ?');
    $stmt->execute([$id, $pid]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Code not found']);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action or method']);
