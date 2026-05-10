<?php
/**
 * AI Consent management for end users (clients)
 *
 * Actions:
 *   load   — GET  — load consent record for a specific practitioner
 *   save   — POST — create / update consent
 *   list   — GET  — list all AI consent records for the authenticated client
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

header('Content-Type: application/json');

// Require authenticated client session
if (empty($_SESSION['end_user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Clients only']);
    exit;
}

$userId = (int)$_SESSION['end_user_id'];
$pdo    = getDB();
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$input  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ct    = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = str_contains($ct, 'application/json')
        ? (json_decode(file_get_contents('php://input'), true) ?? [])
        : $_POST;
}

// ── LIST ──────────────────────────────────────────────────────────
if ($action === 'list' || ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === '')) {
    $stmt = $pdo->prepare('
        SELECT ac.*, pac.assistant_name, pac.vendor, pac.scope_summarization AS pract_scope_sum,
               pac.scope_notes AS pract_scope_notes, pac.scope_post_session AS pract_scope_post,
               pac.scope_in_session AS pract_scope_in,
               p.display_name AS practitioner_name, p.avatar_path AS practitioner_avatar
        FROM ai_consent ac
        JOIN practitioner_ai_config pac ON pac.practitioner_id = ac.practitioner_id
        JOIN practitioners p ON p.id = ac.practitioner_id
        WHERE ac.end_user_id = ?
        ORDER BY ac.updated_at DESC
    ');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast ints to bools
    foreach ($rows as &$r) {
        foreach (['ai_allowed','scope_summarization','scope_notes','scope_post_session','scope_in_session',
                  'pract_scope_sum','pract_scope_notes','pract_scope_post','pract_scope_in'] as $f) {
            if (isset($r[$f])) $r[$f] = (bool)(int)$r[$f];
        }
    }
    unset($r);

    echo json_encode(['ok' => true, 'consents' => $rows]);
    exit;
}

// ── LOAD ──────────────────────────────────────────────────────────
if ($action === 'load') {
    $practId = (int)($_GET['practitioner_id'] ?? 0);
    if (!$practId) {
        http_response_code(400);
        echo json_encode(['error' => 'practitioner_id required']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM ai_consent WHERE end_user_id = ? AND practitioner_id = ?');
    $stmt->execute([$userId, $practId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok' => true, 'consent' => null]);
        exit;
    }

    foreach (['ai_allowed','scope_summarization','scope_notes','scope_post_session','scope_in_session'] as $f) {
        $row[$f] = (bool)(int)$row[$f];
    }

    echo json_encode(['ok' => true, 'consent' => $row]);
    exit;
}

// ── SAVE ──────────────────────────────────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? '';
    if (!validate_csrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF validation failed']);
        exit;
    }

    $practId   = (int)($input['practitioner_id'] ?? 0);
    $bookingId = !empty($input['booking_id']) ? (int)$input['booking_id'] : null;

    if (!$practId) {
        http_response_code(400);
        echo json_encode(['error' => 'practitioner_id required']);
        exit;
    }

    // Verify this practitioner has AI enabled
    $stmt = $pdo->prepare('SELECT enabled, scope_summarization, scope_notes, scope_post_session, scope_in_session FROM practitioner_ai_config WHERE practitioner_id = ?');
    $stmt->execute([$practId]);
    $cfg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cfg || !$cfg['enabled']) {
        http_response_code(400);
        echo json_encode(['error' => 'Practitioner has not enabled AI Integration']);
        exit;
    }

    $aiAllowed         = !empty($input['ai_allowed']) ? 1 : 0;
    $scopeSummarization = ($aiAllowed && $cfg['scope_summarization'] && !empty($input['scope_summarization'])) ? 1 : 0;
    $scopeNotes         = ($aiAllowed && $cfg['scope_notes']         && !empty($input['scope_notes']))         ? 1 : 0;
    $scopePostSession   = ($aiAllowed && $cfg['scope_post_session']  && !empty($input['scope_post_session']))  ? 1 : 0;
    $scopeInSession     = ($aiAllowed && $cfg['scope_in_session']    && !empty($input['scope_in_session']))    ? 1 : 0;

    $pdo->prepare('
        INSERT INTO ai_consent
            (end_user_id, practitioner_id, booking_id, ai_allowed,
             scope_summarization, scope_notes, scope_post_session, scope_in_session)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            booking_id          = COALESCE(booking_id, VALUES(booking_id)),
            ai_allowed          = VALUES(ai_allowed),
            scope_summarization = VALUES(scope_summarization),
            scope_notes         = VALUES(scope_notes),
            scope_post_session  = VALUES(scope_post_session),
            scope_in_session    = VALUES(scope_in_session),
            updated_at          = CURRENT_TIMESTAMP
    ')->execute([$userId, $practId, $bookingId, $aiAllowed,
                 $scopeSummarization, $scopeNotes, $scopePostSession, $scopeInSession]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
exit;
