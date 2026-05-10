<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/events.php';

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

if (empty($_FILES['audio']['name']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No audio uploaded']);
    exit;
}

$file     = $_FILES['audio'];
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

// application/octet-stream excluded — it accepts any binary and cannot be validated as audio.
// video/webm included because Chrome frequently labels audio/webm recordings as video/webm.
$allowedMime = str_starts_with($mimeType, 'audio/')
    || $mimeType === 'application/ogg'
    || $mimeType === 'video/webm';
if (!$allowedMime) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid audio format']);
    exit;
}
if ($file['size'] > 20 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'Audio too large (max 20 MB)']);
    exit;
}

// Derive extension from validated MIME type, never from user-supplied filename
$voiceMimeExtMap = [
    'audio/webm'                => 'webm', 'audio/ogg'  => 'ogg',
    'audio/mpeg'                => 'mp3',  'audio/mp4'  => 'mp4',
    'audio/wav'                 => 'wav',  'audio/x-wav'=> 'wav',
    'video/webm'                => 'webm', // Chrome labels audio/webm as video/webm
    'application/ogg'           => 'ogg',
    'application/octet-stream'  => 'webm', // fallback for unknown binary
];
$ext   = $voiceMimeExtMap[$mimeType] ?? 'webm';
$fname = 'vn_' . bin2hex(random_bytes(12)) . '.' . $ext;
$dest     = __DIR__ . '/../assets/uploads/' . $fname;
move_uploaded_file($file['tmp_name'], $dest);
$filePath = '/assets/uploads/' . $fname;

$stmt = $pdo->prepare(
    'INSERT INTO messages (session_id, participant_id, content, message_type, caption, file_size, mime_type, original_name, is_practitioner)
     VALUES (?,?,?,?,?,?,?,?,?)'
);
$stmt->execute([
    $sessionId, $participantId, $filePath, 'voice_note',
    $caption ?: null, $file['size'], $mimeType, $fname, $isPractitioner,
]);
$msgId = $pdo->lastInsertId();

$response = [
    'id'              => (int)$msgId,
    'participant_id'  => $participantId,
    'display_name'    => $displayName,
    'content'         => $filePath,
    'message_type'    => 'voice_note',
    'caption'         => $caption ?: null,
    'file_size'       => (int)$file['size'],
    'mime_type'       => $mimeType,
    'original_name'   => $fname,
    'sent_at'         => gmdate('Y-m-d\TH:i:s\Z'),
    'is_practitioner' => (bool)$isPractitioner,
    'is_pinned'       => false,
    'highlights'      => [],
];

session_write_close();
emitEvent($pdo, $sessionId, 'message', $response);
echo json_encode($response);
