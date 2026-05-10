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

$input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? 'update_position';
$pdo    = getDB();

// ── update_position ────────────────────────────────────────────
if ($action === 'update_position') {
    $joinToken = trim($input['join_token'] ?? '');
    $x         = (float)($input['x'] ?? 0);
    $y         = (float)($input['y'] ?? 0);

    if (!$joinToken) {
        http_response_code(400);
        echo json_encode(['error' => 'join_token required']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, session_id FROM participants WHERE join_token = ? LIMIT 1');
    $stmt->execute([$joinToken]);
    $p = $stmt->fetch();
    if (!$p) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }

    $pdo->prepare('UPDATE participants SET position_x = ?, position_y = ? WHERE id = ?')
        ->execute([$x, $y, $p['id']]);

    emitEvent($pdo, (int)$p['session_id'], 'position', [
        'participant_id' => (int)$p['id'],
        'position_x'     => $x,
        'position_y'     => $y,
    ]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── link ───────────────────────────────────────────────────────
if ($action === 'link') {
    $joinToken = trim($input['join_token'] ?? '');
    $targetId  = (int)($input['target_participant_id'] ?? 0);

    if (!$joinToken || !$targetId) {
        http_response_code(400);
        echo json_encode(['error' => 'join_token and target_participant_id required']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, session_id FROM participants WHERE join_token = ? LIMIT 1');
    $stmt->execute([$joinToken]);
    $p = $stmt->fetch();
    if (!$p) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }

    // Ensure target is in same session
    $stmt = $pdo->prepare('SELECT id FROM participants WHERE id = ? AND session_id = ? LIMIT 1');
    $stmt->execute([$targetId, $p['session_id']]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Target not in session']);
        exit;
    }

    $pdo->prepare('UPDATE participants SET linked_to_participant_id = ? WHERE id = ?')
        ->execute([$targetId, $p['id']]);

    emitEvent($pdo, (int)$p['session_id'], 'link', [
        'participant_id' => (int)$p['id'],
        'linked_to'      => $targetId,
    ]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── unlink ─────────────────────────────────────────────────────
if ($action === 'unlink') {
    $joinToken = trim($input['join_token'] ?? '');

    if (!$joinToken) {
        http_response_code(400);
        echo json_encode(['error' => 'join_token required']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, session_id FROM participants WHERE join_token = ? LIMIT 1');
    $stmt->execute([$joinToken]);
    $p = $stmt->fetch();
    if (!$p) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }

    // Find participants linked to this one before clearing (for events)
    $stmtR = $pdo->prepare('SELECT id FROM participants WHERE linked_to_participant_id = ?');
    $stmtR->execute([$p['id']]);
    $reverseLinked = $stmtR->fetchAll();

    $pdo->prepare('UPDATE participants SET linked_to_participant_id = NULL WHERE id = ?')
        ->execute([$p['id']]);
    $pdo->prepare('UPDATE participants SET linked_to_participant_id = NULL WHERE linked_to_participant_id = ?')
        ->execute([$p['id']]);

    // Emit unlink events for both directions
    emitEvent($pdo, (int)$p['session_id'], 'link', [
        'participant_id' => (int)$p['id'],
        'linked_to'      => null,
    ]);
    foreach ($reverseLinked as $linked) {
        emitEvent($pdo, (int)$p['session_id'], 'link', [
            'participant_id' => (int)$linked['id'],
            'linked_to'      => null,
        ]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── update_avatar ──────────────────────────────────────────────
if ($action === 'update_avatar') {
    $joinToken = trim($input['join_token'] ?? '');

    if (!$joinToken || empty($_FILES['avatar']['name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'join_token and avatar file required']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, session_id FROM participants WHERE join_token = ? LIMIT 1');
    $stmt->execute([$joinToken]);
    $p = $stmt->fetch();
    if (!$p) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }

    $file    = $_FILES['avatar'];
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed, true) || $file['size'] > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid file']);
        exit;
    }

    // Always output JPEG — resize to 200×200 bounding box via GD
    $name = bin2hex(random_bytes(12)) . '.jpg';
    $dest = __DIR__ . '/../assets/avatars/' . $name;

    $src     = imagecreatefromstring(file_get_contents($file['tmp_name']));
    $resized = $src ? imagescale($src, 200, 200) : false;
    if ($src === false || $resized === false || !imagejpeg($resized, $dest, 85)) {
        // GD unavailable — fall back to raw copy
        move_uploaded_file($file['tmp_name'], $dest);
    }
    if ($src)     imagedestroy($src);
    if ($resized) imagedestroy($resized);

    $avatarPath = '/assets/avatars/' . $name;

    $pdo->prepare('UPDATE participants SET avatar_path = ? WHERE id = ?')
        ->execute([$avatarPath, $p['id']]);

    emitEvent($pdo, (int)$p['session_id'], 'avatar', [
        'participant_id' => (int)$p['id'],
        'avatar_path'    => $avatarPath,
    ]);

    echo json_encode(['ok' => true, 'avatar_url' => $avatarPath]);
    exit;
}

// ── restore_avatar (after webcam disable) ─────────────────────
if ($action === 'restore_avatar') {
    $joinToken = trim($input['join_token'] ?? '');
    if (!$joinToken) {
        http_response_code(400);
        echo json_encode(['error' => 'join_token required']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id, session_id, avatar_path FROM participants WHERE join_token = ? LIMIT 1');
    $stmt->execute([$joinToken]);
    $p = $stmt->fetch();
    if (!$p) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }
    emitEvent($pdo, (int)$p['session_id'], 'avatar', [
        'participant_id' => (int)$p['id'],
        'avatar_path'    => $p['avatar_path'] ?? '',
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── rename ─────────────────────────────────────────────────────
if ($action === 'rename') {
    $joinToken = trim($input['join_token'] ?? '');
    $newName   = trim($input['display_name'] ?? '');

    if (!$joinToken || $newName === '') {
        http_response_code(400);
        echo json_encode(['error' => 'join_token and display_name required']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, session_id, display_name FROM participants WHERE join_token = ? LIMIT 1');
    $stmt->execute([$joinToken]);
    $p = $stmt->fetch();
    if (!$p) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }

    $oldName = $p['display_name'];
    $pdo->prepare('UPDATE participants SET display_name = ? WHERE id = ?')
        ->execute([$newName, $p['id']]);

    emitEvent($pdo, (int)$p['session_id'], 'participant_rename', [
        'participant_id' => (int)$p['id'],
        'old_name'       => $oldName,
        'new_name'       => $newName,
    ]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
