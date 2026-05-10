<?php
/**
 * Serenity Spaces — DPA Document Management API
 *
 * POST action=upload_platform      (admin) — upload hosting or SMTP DPA
 * POST action=upload_practitioner  (practitioner) — upload AI vendor or payment processor DPA
 * GET  action=list_platform        (admin) — list platform DPAs
 * GET  action=list_mine            (practitioner) — list own DPAs
 * GET  action=list_for_client      (client/public) — list a practitioner's public-facing DPAs
 * GET  action=download             (admin or doc owner) — stream DPA file
 * POST action=delete               (admin or doc owner) — delete a DPA document
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/audit.php';
require_once __DIR__ . '/../includes/phi_crypto.php';

header('Content-Type: application/json');

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Determine actor
$isAdmin        = !empty($_SESSION['admin_id'])        || !empty($_SESSION['is_admin']);
$practId        = !empty($_SESSION['practitioner_id']) ? (int)$_SESSION['practitioner_id'] : 0;
$endUserId      = !empty($_SESSION['end_user_id'])     ? (int)$_SESSION['end_user_id'] : 0;

$ct             = $_SERVER['CONTENT_TYPE'] ?? '';
$isJsonPost     = $method === 'POST' && str_contains($ct, 'application/json');
$input          = $isJsonPost
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : ($method === 'POST' ? $_POST : $_GET);
$action         = $input['action'] ?? ($_GET['action'] ?? '');

// ── Storage root ────────────────────────────────────────────────
define('DPA_STORAGE', __DIR__ . '/../storage/dpa/');

// Allowed DPA types by scope
$platformTypes     = ['hosting', 'smtp'];
$practitionerTypes = ['ai_openai', 'ai_anthropic', 'ai_google', 'ai_cohere', 'ai_custom',
                      'payment_stripe', 'payment_paypal', 'payment_square', 'payment_custom'];
$allTypes          = array_merge($platformTypes, $practitionerTypes);

// Friendly vendor names by type
$typeLabels = [
    'hosting'          => 'Hosting Provider',
    'smtp'             => 'SMTP / Email Provider',
    'ai_openai'        => 'OpenAI',
    'ai_anthropic'     => 'Anthropic',
    'ai_google'        => 'Google AI',
    'ai_cohere'        => 'Cohere',
    'ai_custom'        => 'Custom AI Vendor',
    'payment_stripe'   => 'Stripe',
    'payment_paypal'   => 'PayPal',
    'payment_square'   => 'Square',
    'payment_custom'   => 'Custom Payment Processor',
];

// ── Helper: CSRF check ──────────────────────────────────────────
function _dpa_csrf(array $input): bool {
    return validate_csrf($input['csrf_token'] ?? '');
}

// ── Upload — platform (admin only) ─────────────────────────────
if ($action === 'upload_platform') {
    if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Admin access required']); exit; }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validate_csrf($csrfToken)) {
        http_response_code(403); echo json_encode(['error' => 'CSRF token invalid']); exit;
    }

    $type       = $_POST['type'] ?? '';
    $vendorName = trim($_POST['vendor_name'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');

    if (!in_array($type, $platformTypes, true)) {
        http_response_code(400); echo json_encode(['error' => 'Invalid DPA type']); exit;
    }
    if ($vendorName === '') {
        http_response_code(400); echo json_encode(['error' => 'Vendor name required']); exit;
    }
    if (empty($_FILES['dpa_file']['name'])) {
        http_response_code(400); echo json_encode(['error' => 'DPA file required']); exit;
    }

    $file = $_FILES['dpa_file'];
    if ($file['size'] > 20 * 1024 * 1024) {
        http_response_code(400); echo json_encode(['error' => 'File must be under 20MB']); exit;
    }

    // Accept PDF, DOC, DOCX only
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mime     = $finfo->file($file['tmp_name']);
    $allowed  = ['application/pdf', 'application/msword',
                 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    if (!in_array($mime, $allowed, true)) {
        http_response_code(400); echo json_encode(['error' => 'Only PDF and Word documents accepted']); exit;
    }

    $extMap   = ['application/pdf' => 'pdf', 'application/msword' => 'doc',
                 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'];
    $ext      = $extMap[$mime];
    $filename = 'dpa_' . $type . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dest     = DPA_STORAGE . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500); echo json_encode(['error' => 'File upload failed']); exit;
    }

    $pdo->prepare(
        'INSERT INTO dpa_documents (scope, type, vendor_name, file_name, file_path, file_size, uploaded_by_type, uploaded_by_id, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(['platform', $type, $vendorName, basename($file['name']), 'storage/dpa/' . $filename,
                (int)$file['size'], 'admin', (int)($_SESSION['admin_id'] ?? 0), $notes ?: null]);

    $docId = (int)$pdo->lastInsertId();
    audit_log($pdo, 'dpa.upload', ['entity_type' => 'dpa_document', 'entity_id' => $docId]);
    echo json_encode(['ok' => true, 'id' => $docId]);
    exit;
}

// ── Upload — practitioner ───────────────────────────────────────
if ($action === 'upload_practitioner') {
    if (!$practId) { http_response_code(401); echo json_encode(['error' => 'Practitioner session required']); exit; }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validate_csrf($csrfToken)) {
        http_response_code(403); echo json_encode(['error' => 'CSRF token invalid']); exit;
    }

    $type       = $_POST['type'] ?? '';
    $vendorName = trim($_POST['vendor_name'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');

    if (!in_array($type, $practitionerTypes, true)) {
        http_response_code(400); echo json_encode(['error' => 'Invalid DPA type']); exit;
    }
    if ($vendorName === '') {
        $vendorName = $typeLabels[$type] ?? $type;
    }
    if (empty($_FILES['dpa_file']['name'])) {
        http_response_code(400); echo json_encode(['error' => 'DPA file required']); exit;
    }

    $file = $_FILES['dpa_file'];
    if ($file['size'] > 20 * 1024 * 1024) {
        http_response_code(400); echo json_encode(['error' => 'File must be under 20MB']); exit;
    }

    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    $allowed = ['application/pdf', 'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    if (!in_array($mime, $allowed, true)) {
        http_response_code(400); echo json_encode(['error' => 'Only PDF and Word documents accepted']); exit;
    }

    $extMap   = ['application/pdf' => 'pdf', 'application/msword' => 'doc',
                 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'];
    $ext      = $extMap[$mime];
    $filename = 'dpa_p' . $practId . '_' . $type . '_' . bin2hex(random_bytes(10)) . '.' . $ext;
    $dest     = DPA_STORAGE . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        http_response_code(500); echo json_encode(['error' => 'File upload failed']); exit;
    }

    $pdo->prepare(
        'INSERT INTO dpa_documents (scope, type, practitioner_id, vendor_name, file_name, file_path, file_size, uploaded_by_type, uploaded_by_id, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(['practitioner', $type, $practId, $vendorName, basename($file['name']),
                'storage/dpa/' . $filename, (int)$file['size'], 'practitioner', $practId, $notes ?: null]);

    $docId = (int)$pdo->lastInsertId();
    audit_log($pdo, 'dpa.upload', ['practitioner_id' => $practId, 'entity_type' => 'dpa_document', 'entity_id' => $docId]);
    echo json_encode(['ok' => true, 'id' => $docId]);
    exit;
}

// ── List — platform (admin) ─────────────────────────────────────
if ($action === 'list_platform') {
    if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Admin access required']); exit; }

    $stmt = $pdo->prepare(
        'SELECT id, type, vendor_name, file_name, file_size, notes, uploaded_at
         FROM dpa_documents WHERE scope = ? ORDER BY type, uploaded_at DESC'
    );
    $stmt->execute(['platform']);
    echo json_encode(['ok' => true, 'docs' => $stmt->fetchAll()]);
    exit;
}

// ── List — practitioner's own ───────────────────────────────────
if ($action === 'list_mine') {
    if (!$practId) { http_response_code(401); echo json_encode(['error' => 'Practitioner session required']); exit; }

    $stmt = $pdo->prepare(
        'SELECT id, type, vendor_name, file_name, file_size, notes, uploaded_at
         FROM dpa_documents WHERE scope = ? AND practitioner_id = ? ORDER BY type, uploaded_at DESC'
    );
    $stmt->execute(['practitioner', $practId]);
    echo json_encode(['ok' => true, 'docs' => $stmt->fetchAll()]);
    exit;
}

// ── List — for client (public view of practitioner's DPAs) ──────
if ($action === 'list_for_client') {
    $targetPractId = (int)($input['practitioner_id'] ?? 0);
    if (!$targetPractId) {
        http_response_code(400); echo json_encode(['error' => 'practitioner_id required']); exit;
    }

    // Only allow if requester is a client who has a booking with this practitioner
    if (!$endUserId) {
        http_response_code(401); echo json_encode(['error' => 'Authentication required']); exit;
    }
    $stmt = $pdo->prepare('SELECT id FROM bookings WHERE end_user_id = ? AND practitioner_id = ? LIMIT 1');
    $stmt->execute([$endUserId, $targetPractId]);
    if (!$stmt->fetch()) {
        http_response_code(403); echo json_encode(['error' => 'No booking relationship with this practitioner']); exit;
    }

    // Return only vendor name, type label, and upload date — no download access for clients
    $stmt = $pdo->prepare(
        'SELECT type, vendor_name, uploaded_at
         FROM dpa_documents WHERE scope = ? AND practitioner_id = ? ORDER BY type'
    );
    $stmt->execute(['practitioner', $targetPractId]);
    $docs = $stmt->fetchAll();
    foreach ($docs as &$d) {
        $d['type_label'] = $typeLabels[$d['type']] ?? $d['type'];
    }
    unset($d);
    echo json_encode(['ok' => true, 'docs' => $docs]);
    exit;
}

// ── Download ────────────────────────────────────────────────────
if ($action === 'download') {
    $docId = (int)($input['id'] ?? 0);
    if (!$docId) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

    $stmt = $pdo->prepare('SELECT * FROM dpa_documents WHERE id = ? LIMIT 1');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();
    if (!$doc) { http_response_code(404); echo json_encode(['error' => 'Document not found']); exit; }

    // Access control: admin can access any; practitioner can access their own
    $canAccess = $isAdmin
        || ($practId && $doc['scope'] === 'practitioner' && (int)$doc['practitioner_id'] === $practId);

    if (!$canAccess) { http_response_code(403); echo json_encode(['error' => 'Access denied']); exit; }

    $filePath = __DIR__ . '/../' . $doc['file_path'];
    if (!file_exists($filePath)) { http_response_code(404); echo json_encode(['error' => 'File not found on disk']); exit; }

    $ext      = strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION));
    $mimeMap  = ['pdf' => 'application/pdf', 'doc' => 'application/msword',
                 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $outMime  = $mimeMap[$ext] ?? 'application/octet-stream';
    $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $doc['file_name']);

    header('Content-Type: ' . $outMime);
    header('Content-Disposition: inline; filename="' . $safeName . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, no-store');
    readfile($filePath);
    audit_log($pdo, 'dpa.download', [
        'practitioner_id' => $practId ?: null,
        'entity_type'     => 'dpa_document',
        'entity_id'       => $docId,
    ]);
    exit;
}

// ── Delete ──────────────────────────────────────────────────────
if ($action === 'delete') {
    $docId = (int)($input['id'] ?? 0);
    if (!$docId) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

    if (!_dpa_csrf($input)) { http_response_code(403); echo json_encode(['error' => 'CSRF token invalid']); exit; }

    $stmt = $pdo->prepare('SELECT * FROM dpa_documents WHERE id = ? LIMIT 1');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();
    if (!$doc) { http_response_code(404); echo json_encode(['error' => 'Document not found']); exit; }

    $canDelete = $isAdmin
        || ($practId && $doc['scope'] === 'practitioner' && (int)$doc['practitioner_id'] === $practId);

    if (!$canDelete) { http_response_code(403); echo json_encode(['error' => 'Access denied']); exit; }

    // Delete file from disk
    $filePath = __DIR__ . '/../' . $doc['file_path'];
    if (file_exists($filePath)) {
        @unlink($filePath);
    }

    $pdo->prepare('DELETE FROM dpa_documents WHERE id = ?')->execute([$docId]);
    audit_log($pdo, 'dpa.delete', [
        'practitioner_id' => $practId ?: null,
        'entity_type'     => 'dpa_document',
        'entity_id'       => $docId,
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Check — does a DPA exist for given type/practitioner? ───────
// Used internally by AI/payment save endpoints for gating
if ($action === 'check') {
    $type      = $input['type'] ?? '';
    $checkPid  = (int)($input['practitioner_id'] ?? $practId);

    if (!$isAdmin && $checkPid !== $practId) {
        http_response_code(403); echo json_encode(['error' => 'Access denied']); exit;
    }

    if ($type === '') { http_response_code(400); echo json_encode(['error' => 'type required']); exit; }

    $scope = in_array($type, $platformTypes, true) ? 'platform' : 'practitioner';
    if ($scope === 'platform') {
        $stmt = $pdo->prepare('SELECT id FROM dpa_documents WHERE scope = ? AND type = ? LIMIT 1');
        $stmt->execute(['platform', $type]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM dpa_documents WHERE scope = ? AND type = ? AND practitioner_id = ? LIMIT 1');
        $stmt->execute(['practitioner', $type, $checkPid]);
    }
    echo json_encode(['ok' => true, 'has_dpa' => (bool)$stmt->fetch()]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
