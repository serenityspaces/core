<?php
/**
 * Serenity Spaces — Authenticated File Serving (Rec C)
 *
 * Streams a session-uploaded file (attachment or voice note) only to
 * authenticated participants of the session that owns the file.
 *
 * Usage:  GET /api/serve_file.php?file=f_abc123.webp[&join_token=XXXX]
 *         GET /api/serve_file.php?file=vn_def456.webm[&join_token=XXXX]
 *
 * Auth accepted:
 *   - Practitioner PHP session  ($_SESSION['practitioner_id'])
 *   - Client PHP session        ($_SESSION['end_user_id'])  — verified via join_token
 *   - join_token query param    (guest/client in an active session)
 *
 * The caller must NOT pass a full path — only the bare filename.
 * Any path-separator characters in the filename cause an immediate 400.
 *
 * The web server must deny direct access to /assets/uploads/ so that
 * all file requests are routed through this endpoint.
 */
session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit;
}

$pdo = getDB();

// ── 1. Validate filename ───────────────────────────────────────
$rawFile = trim($_GET['file'] ?? '');

// Reject empty, path-traversal attempts, or anything with directory separators
if ($rawFile === '' || preg_match('#[/\\\\]#', $rawFile) || strpos($rawFile, '..') !== false) {
    http_response_code(400);
    exit;
}

// Only allow filenames that match the storage pattern: [prefix_][hex].[ext]
// f_ = file attachment, vn_ = voice note; hex = 24 chars; ext limited to known safe set
if (!preg_match('/^(f_|vn_)[0-9a-f]{24}\.(jpg|jpeg|png|gif|webp|pdf|txt|webm|ogg|mp3|mp4|wav|bin)$/i', $rawFile)) {
    http_response_code(400);
    exit;
}

$storedPath = '/assets/uploads/' . $rawFile;
$diskPath   = __DIR__ . '/../assets/uploads/' . $rawFile;

if (!file_exists($diskPath)) {
    http_response_code(404);
    exit;
}

// ── 2. Look up the message record ─────────────────────────────
$stmt = $pdo->prepare(
    'SELECT m.id, m.session_id, m.mime_type, m.original_name
     FROM messages m
     WHERE m.content = ? LIMIT 1'
);
$stmt->execute([$storedPath]);
$msg = $stmt->fetch();

if (!$msg) {
    // File exists on disk but no DB record — deny
    http_response_code(403);
    exit;
}

$sessionId = (int)$msg['session_id'];

// ── 3. Authorise the request ───────────────────────────────────
$authorised = false;
$joinToken  = trim($_GET['join_token'] ?? '');

if (!empty($_SESSION['practitioner_id'])) {
    // Practitioner: verify they own the room for this session
    $s = $pdo->prepare(
        'SELECT 1 FROM rooms r JOIN sessions s ON s.room_id = r.id
         WHERE s.id = ? AND r.practitioner_id = ? LIMIT 1'
    );
    $s->execute([$sessionId, (int)$_SESSION['practitioner_id']]);
    if ($s->fetch()) {
        $authorised = true;
    }
}

if (!$authorised && !empty($_SESSION['end_user_id'])) {
    // Authenticated client: verify they were a participant in this session
    $s = $pdo->prepare(
        'SELECT 1 FROM participants p
         WHERE p.session_id = ? AND p.end_user_id = ? LIMIT 1'
    );
    $s->execute([$sessionId, (int)$_SESSION['end_user_id']]);
    if ($s->fetch()) {
        $authorised = true;
    }
}

if (!$authorised && $joinToken !== '') {
    // Guest/client via join_token: verify the token belongs to this session
    $s = $pdo->prepare(
        'SELECT 1 FROM participants WHERE join_token = ? AND session_id = ? LIMIT 1'
    );
    $s->execute([$joinToken, $sessionId]);
    if ($s->fetch()) {
        $authorised = true;
    }
}

if (!$authorised) {
    http_response_code(403);
    exit;
}

// ── 4. Stream the file ─────────────────────────────────────────
$mimeType     = $msg['mime_type'] ?: 'application/octet-stream';
$originalName = $msg['original_name'] ?: $rawFile;

// Sanitise the download filename to prevent header injection
$safeOriginalName = preg_replace('/[^A-Za-z0-9._\-]/', '_', basename($originalName));

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($diskPath));
header('X-Content-Type-Options: nosniff');

// Inline for images and audio/video; attachment for everything else
$inlineMimes = ['image/', 'audio/', 'video/'];
$inline = false;
foreach ($inlineMimes as $prefix) {
    if (str_starts_with($mimeType, $prefix)) { $inline = true; break; }
}
$disposition = $inline ? 'inline' : 'attachment';
header('Content-Disposition: ' . $disposition . '; filename="' . $safeOriginalName . '"');

// No caching for PHI files
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($diskPath);
exit;
