<?php
/**
 * License submission API
 *
 * POST (multipart) action=submit  — practitioner submits credential document
 * GET  action=status              — returns current license_status for practitioner
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

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

// Check whether platform prerequisites for licensed submissions are met
function isLicenseSubmissionLocked(PDO $pdo): bool {
    $hostingOk = (bool)$pdo->query("SELECT id FROM dpa_documents WHERE scope='platform' AND type='hosting' LIMIT 1")->fetch();
    $smtpOk    = (bool)$pdo->query("SELECT id FROM dpa_documents WHERE scope='platform' AND type='smtp' LIMIT 1")->fetch();
    $ctrlName  = getSetting('controller_name',  '');
    $ctrlEmail = getSetting('controller_email', '');
    return !$hostingOk || !$smtpOk || $ctrlName === '' || $ctrlEmail === '';
}

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT license_status FROM practitioners WHERE id = ? LIMIT 1');
    $stmt->execute([$practId]);
    $row  = $stmt->fetch();
    // Also get latest submission note
    $sub = $pdo->prepare('SELECT status, reviewer_notes, submitted_at FROM license_submissions WHERE practitioner_id = ? ORDER BY submitted_at DESC LIMIT 1');
    $sub->execute([$practId]);
    $latest = $sub->fetch();
    echo json_encode([
        'license_status'      => $row ? $row['license_status'] : 'none',
        'latest_status'       => $latest ? $latest['status'] : null,
        'reviewer_notes'      => $latest ? $latest['reviewer_notes'] : null,
        'submissions_locked'  => isLicenseSubmissionLocked($pdo),
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action !== 'submit') {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// CSRF check
$csrf = $_POST['csrf_token'] ?? '';
if (!validate_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF validation failed']);
    exit;
}

// Block submissions when platform prerequisites are not met
if (isLicenseSubmissionLocked($pdo)) {
    http_response_code(403);
    echo json_encode(['error' => 'Credential submissions are currently unavailable. The platform operator must complete required compliance setup before licensed status applications can be accepted.']);
    exit;
}

// Check for pending/approved — don't allow duplicate submissions
$stmt = $pdo->prepare("SELECT status FROM license_submissions WHERE practitioner_id = ? AND status IN ('pending','approved') LIMIT 1");
$stmt->execute([$practId]);
$existing = $stmt->fetch();
if ($existing) {
    $msg = $existing['status'] === 'approved'
        ? 'Your credentials are already approved.'
        : 'You already have a pending submission under review.';
    http_response_code(409);
    echo json_encode(['error' => $msg]);
    exit;
}

// File upload
if (empty($_FILES['document']['name']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Document file is required']);
    exit;
}

$file    = $_FILES['document'];
$allowed = ['application/pdf','image/jpeg','image/png','image/webp'];
$finfo   = new finfo(FILEINFO_MIME_TYPE);
$mime    = $finfo->file($file['tmp_name']);

if (!in_array($mime, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Only PDF, JPEG, PNG, or WEBP files are accepted.']);
    exit;
}

if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'File must be under 10 MB.']);
    exit;
}

$extMap  = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
$ext     = $extMap[$mime] ?? 'pdf';
$fname   = 'license_' . $practId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$destDir = __DIR__ . '/../assets/uploads/licenses/';
if (!is_dir($destDir)) {
    mkdir($destDir, 0755, true);
}
$destPath = $destDir . $fname;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file.']);
    exit;
}

$docPath  = '/assets/uploads/licenses/' . $fname;
$docName  = basename($file['name']);
$notes    = trim($_POST['notes'] ?? '');

$pdo->prepare(
    'INSERT INTO license_submissions (practitioner_id, document_path, document_name, notes)
     VALUES (?, ?, ?, ?)'
)->execute([$practId, $docPath, $docName, $notes ?: null]);

// Update practitioner's license_status to 'pending'
$pdo->prepare('UPDATE practitioners SET license_status = ? WHERE id = ?')
    ->execute(['pending', $practId]);

echo json_encode(['ok' => true]);
