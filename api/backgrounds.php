<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/events.php';

header('Content-Type: application/json');

if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$practId = (int)$_SESSION['practitioner_id'];
$pdo     = getDB();

// ── GET: list backgrounds ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $roomId = (int)($_GET['room_id'] ?? 0);
    if ($roomId === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'room_id required']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id FROM rooms WHERE id = ? AND practitioner_id = ? LIMIT 1');
    $stmt->execute([$roomId, $practId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT id, file_path, mime_type, thumb_path, original_name, uploaded_at
         FROM backgrounds WHERE practitioner_id = ? ORDER BY uploaded_at DESC'
    );
    $stmt->execute([$practId]);
    $rows = $stmt->fetchAll();
    echo json_encode(['backgrounds' => array_map(function($r) {
        return [
            'id'            => (int)$r['id'],
            'file_path'     => $r['file_path'],
            'mime_type'     => $r['mime_type'],
            'thumb_path'    => $r['thumb_path'],
            'original_name' => $r['original_name'],
        ];
    }, $rows)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($_POST['action'] ?? $input['action'] ?? '');

// ── POST action=set: apply background to room ─────────────────
if ($action === 'set') {
    $roomId    = (int)($input['room_id'] ?? 0);
    $sessionId = (int)($input['session_id'] ?? 0);
    $bgPath    = trim($input['background_path'] ?? '');
    $bgMime    = trim($input['mime_type'] ?? 'image/jpeg');

    if ($roomId === 0 || $bgPath === '') {
        http_response_code(400);
        echo json_encode(['error' => 'room_id and background_path required']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id FROM rooms WHERE id = ? AND practitioner_id = ? LIMIT 1');
    $stmt->execute([$roomId, $practId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    $pdo->prepare('UPDATE rooms SET background_path = ? WHERE id = ?')
        ->execute([$bgPath, $roomId]);

    if ($sessionId > 0) {
        session_write_close();
        emitEvent($pdo, $sessionId, 'background', [
            'path' => $bgPath,
            'mime' => $bgMime,
        ]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── POST: upload new background file ──────────────────────────
$roomId = (int)($_POST['room_id'] ?? 0);
if ($roomId === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'room_id required']);
    exit;
}
$stmt = $pdo->prepare('SELECT id FROM rooms WHERE id = ? AND practitioner_id = ? LIMIT 1');
$stmt->execute([$roomId, $practId]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if (empty($_FILES['file']['name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file     = $_FILES['file'];
$allowed  = ['image/jpeg','image/png','image/webp','image/gif','video/mp4','video/webm'];
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

if (!in_array($mimeType, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Allowed: JPEG, PNG, WEBP, GIF, MP4, WEBM']);
    exit;
}
if ($file['size'] > 200 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large (max 200 MB)']);
    exit;
}

$mimeExtMap = [
    'image/jpeg' => 'jpg', 'image/png' => 'png',
    'image/webp' => 'webp', 'image/gif' => 'gif',
    'video/mp4'  => 'mp4', 'video/webm' => 'webm',
];
$ext      = $mimeExtMap[$mimeType] ?? 'bin';
$name     = 'bg_' . bin2hex(random_bytes(12)) . '.' . $ext;
$dest     = __DIR__ . '/../assets/uploads/' . $name;
move_uploaded_file($file['tmp_name'], $dest);
$filePath = '/assets/uploads/' . $name;

// Save video thumbnail if provided
$thumbPath = null;
$thumbData = trim($_POST['thumb_data'] ?? '');
if ($thumbData && str_starts_with($thumbData, 'data:image/jpeg;base64,')) {
    $b64      = substr($thumbData, strlen('data:image/jpeg;base64,'));
    $imgBytes = base64_decode($b64, true);
    if ($imgBytes !== false && strlen($imgBytes) < 500000) {
        // Validate decoded bytes are a genuine image before writing to disk
        $imgInfo = @getimagesizefromstring($imgBytes);
        $allowedThumbMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if ($imgInfo !== false && in_array($imgInfo['mime'], $allowedThumbMimes, true)) {
            $thumbName = 'thumb_' . bin2hex(random_bytes(12)) . '.jpg';
            file_put_contents(__DIR__ . '/../assets/uploads/' . $thumbName, $imgBytes);
            $thumbPath = '/assets/uploads/' . $thumbName;
        }
        // If validation fails, $thumbPath stays null — thumbnail silently skipped
    }
}

$pdo->prepare(
    'INSERT INTO backgrounds (practitioner_id, file_path, mime_type, thumb_path, original_name) VALUES (?,?,?,?,?)'
)->execute([$practId, $filePath, $mimeType, $thumbPath, $file['name']]);
$bgId = $pdo->lastInsertId();

$stmt = $pdo->prepare('SELECT id, file_path, mime_type, thumb_path, original_name FROM backgrounds WHERE id = ?');
$stmt->execute([$bgId]);
$bg = $stmt->fetch();

echo json_encode(['ok' => true, 'background' => [
    'id'            => (int)$bg['id'],
    'file_path'     => $bg['file_path'],
    'mime_type'     => $bg['mime_type'],
    'thumb_path'    => $bg['thumb_path'],
    'original_name' => $bg['original_name'],
]]);
