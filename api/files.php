<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/events.php';
require_once __DIR__ . '/../db/audit.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$pdo       = getDB();
$sessionId = (int)($_POST['session_id'] ?? 0);
$caption   = trim($_POST['caption'] ?? '');
$joinToken = trim($_POST['join_token'] ?? '');

if ($sessionId === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'session_id required']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM sessions WHERE id = ? AND ended_at IS NULL LIMIT 1');
$stmt->execute([$sessionId]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Session not active']);
    exit;
}

$isPractitioner = 0;
$participantId  = null;
$displayName    = '';

if (!empty($_SESSION['practitioner_id'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validate_csrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token mismatch']);
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT r.id FROM rooms r JOIN sessions s ON s.room_id = r.id
         WHERE s.id = ? AND r.practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$sessionId, (int)$_SESSION['practitioner_id']]);
    if (!$stmt->fetch()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

    $practJoinToken = 'pract-' . $sessionId . '-' . (int)$_SESSION['practitioner_id'];
    $stmt = $pdo->prepare('SELECT id, display_name FROM participants WHERE join_token = ? LIMIT 1');
    $stmt->execute([$practJoinToken]);
    $practP = $stmt->fetch();
    if ($practP) { $participantId = (int)$practP['id']; $displayName = $practP['display_name']; }
    $isPractitioner = 1;
} elseif ($joinToken) {
    $stmt = $pdo->prepare('SELECT * FROM participants WHERE join_token = ? AND session_id = ? AND is_host = 0 LIMIT 1');
    $stmt->execute([$joinToken, $sessionId]);
    $p = $stmt->fetch();
    if (!$p) { http_response_code(403); echo json_encode(['error' => 'Invalid token']); exit; }
    $participantId = (int)$p['id'];
    $displayName   = $p['display_name'];
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (empty($_FILES['file']['name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file     = $_FILES['file'];
if ($file['size'] > 50 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large (max 50 MB)']);
    exit;
}

$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
$origName = $file['name'];
// Derive extension from validated MIME type, not from the user-supplied filename.
// Types not in the allowlist are rejected — no arbitrary binaries accepted.
$mimeExtMap = [
    'image/jpeg'      => 'jpg',  'image/png'  => 'png',
    'image/gif'       => 'gif',  'image/webp' => 'webp',
    'application/pdf' => 'pdf',  'text/plain' => 'txt',
    'audio/webm'      => 'webm', 'audio/ogg'  => 'ogg',
    'audio/mpeg'      => 'mp3',  'video/webm' => 'webm',
    'video/mp4'       => 'mp4',
];
if (!array_key_exists($mimeType, $mimeExtMap)) {
    http_response_code(415);
    echo json_encode(['error' => 'File type not permitted']);
    exit;
}
$ext   = $mimeExtMap[$mimeType];
$fname = 'f_' . bin2hex(random_bytes(12)) . '.' . $ext;
$dest     = __DIR__ . '/../assets/uploads/' . $fname;
move_uploaded_file($file['tmp_name'], $dest);
$filePath = '/assets/uploads/' . $fname;

$stmt = $pdo->prepare(
    'INSERT INTO messages (session_id, participant_id, content, message_type, caption, file_size, mime_type, original_name, is_practitioner)
     VALUES (?,?,?,?,?,?,?,?,?)'
);
$stmt->execute([
    $sessionId, $participantId, $filePath, 'file',
    $caption ?: null, $file['size'], $mimeType, $origName, $isPractitioner,
]);
$msgId = $pdo->lastInsertId();

$response = [
    'id'              => (int)$msgId,
    'participant_id'  => $participantId,
    'display_name'    => $displayName,
    'content'         => $filePath,
    'message_type'    => 'file',
    'caption'         => $caption ?: null,
    'file_size'       => (int)$file['size'],
    'mime_type'       => $mimeType,
    'original_name'   => $origName,
    'sent_at'         => gmdate('Y-m-d\TH:i:s\Z'),
    'is_practitioner' => (bool)$isPractitioner,
    'is_pinned'       => false,
    'highlights'      => [],
];

audit_log($pdo, 'file.upload', [
    'practitioner_id' => $isPractitioner ? (int)$_SESSION['practitioner_id'] : null,
    'participant_id'  => $participantId,
    'session_id'      => $sessionId,
    'entity_type'     => 'message',
    'entity_id'       => (int)$msgId,
]);

session_write_close();
emitEvent($pdo, $sessionId, 'message', $response);
echo json_encode($response);
