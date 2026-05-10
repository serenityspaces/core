<?php
/**
 * Serenity Spaces — Inbox Messaging API
 *
 * Handles private messaging between practitioners and their clients
 * outside of live sessions. Supports text, voice notes, and images.
 *
 * Auth: either $_SESSION['practitioner_id'] OR $_SESSION['end_user_id']
 *
 * GET  ?action=unread_count
 * GET  ?action=conversations                  (practitioner: all client threads)
 * GET  ?action=practitioners                  (client: all practitioners with bookings)
 * GET  ?action=messages&with_id=N             (both: messages in a conversation)
 * POST action=send    — text or file upload
 * POST action=mark_read&with_id=N             (marks messages to me as read)
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$pdo = getDB();

$isPractitioner = !empty($_SESSION['practitioner_id']);
$isClient       = !empty($_SESSION['end_user_id']);

if (!$isPractitioner && !$isClient) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$myPractId  = $isPractitioner ? (int)$_SESSION['practitioner_id'] : null;
$myClientId = $isClient       ? (int)$_SESSION['end_user_id']     : null;

// ── GET routes ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    // ── Unread count (badge) ──
    if ($action === 'unread_count') {
        if ($isPractitioner) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM inbox_messages
                 WHERE practitioner_id = ? AND sender_type = 'client' AND read_at IS NULL"
            );
            $stmt->execute([$myPractId]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM inbox_messages
                 WHERE end_user_id = ? AND sender_type = 'practitioner' AND read_at IS NULL"
            );
            $stmt->execute([$myClientId]);
        }
        echo json_encode(['unread' => (int)$stmt->fetchColumn()]);
        exit;
    }

    // ── Practitioners list (client view) ──
    if ($action === 'practitioners' && $isClient) {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT p.id, p.display_name, p.avatar_path,
                    (SELECT COUNT(*) FROM inbox_messages im
                     WHERE im.practitioner_id = p.id AND im.end_user_id = ?
                     AND im.sender_type = 'practitioner' AND im.read_at IS NULL) AS unread,
                    (SELECT im2.body FROM inbox_messages im2
                     WHERE im2.practitioner_id = p.id AND im2.end_user_id = ?
                     ORDER BY im2.created_at DESC LIMIT 1) AS last_body,
                    (SELECT im3.message_type FROM inbox_messages im3
                     WHERE im3.practitioner_id = p.id AND im3.end_user_id = ?
                     ORDER BY im3.created_at DESC LIMIT 1) AS last_type,
                    (SELECT im4.created_at FROM inbox_messages im4
                     WHERE im4.practitioner_id = p.id AND im4.end_user_id = ?
                     ORDER BY im4.created_at DESC LIMIT 1) AS last_at
             FROM bookings b
             JOIN practitioners p ON p.id = b.practitioner_id
             WHERE b.end_user_id = ?
             ORDER BY last_at DESC, p.display_name ASC"
        );
        $stmt->execute([$myClientId, $myClientId, $myClientId, $myClientId, $myClientId]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // ── Conversations list (practitioner view) ──
    if ($action === 'conversations' && $isPractitioner) {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT eu.id AS end_user_id, eu.display_name, eu.email, eu.avatar_path,
                    (SELECT COUNT(*) FROM inbox_messages im
                     WHERE im.practitioner_id = ? AND im.end_user_id = eu.id
                     AND im.sender_type = 'client' AND im.read_at IS NULL) AS unread,
                    (SELECT im2.body FROM inbox_messages im2
                     WHERE im2.practitioner_id = ? AND im2.end_user_id = eu.id
                     ORDER BY im2.created_at DESC LIMIT 1) AS last_body,
                    (SELECT im3.message_type FROM inbox_messages im3
                     WHERE im3.practitioner_id = ? AND im3.end_user_id = eu.id
                     ORDER BY im3.created_at DESC LIMIT 1) AS last_type,
                    (SELECT im4.created_at FROM inbox_messages im4
                     WHERE im4.practitioner_id = ? AND im4.end_user_id = eu.id
                     ORDER BY im4.created_at DESC LIMIT 1) AS last_at
             FROM inbox_messages im
             JOIN end_users eu ON eu.id = im.end_user_id
             WHERE im.practitioner_id = ?
             GROUP BY eu.id
             ORDER BY last_at DESC"
        );
        $stmt->execute([$myPractId, $myPractId, $myPractId, $myPractId, $myPractId]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // ── Messages in a conversation ──
    if ($action === 'messages') {
        $withId = (int)($_GET['with_id'] ?? 0);
        if (!$withId) {
            http_response_code(400);
            echo json_encode(['error' => 'with_id required']);
            exit;
        }

        if ($isPractitioner) {
            // with_id = end_user_id
            // Verify client has a booking with this practitioner
            $check = $pdo->prepare(
                'SELECT 1 FROM bookings WHERE practitioner_id = ? AND end_user_id = ? LIMIT 1'
            );
            $check->execute([$myPractId, $withId]);
            if (!$check->fetch()) {
                // Also allow if there's already an existing message thread
                $check2 = $pdo->prepare(
                    'SELECT 1 FROM inbox_messages WHERE practitioner_id = ? AND end_user_id = ? LIMIT 1'
                );
                $check2->execute([$myPractId, $withId]);
                if (!$check2->fetch()) {
                    http_response_code(403);
                    echo json_encode(['error' => 'No relationship with this client']);
                    exit;
                }
            }
            $stmt = $pdo->prepare(
                'SELECT im.*, p.display_name AS sender_name, p.avatar_path AS sender_avatar
                 FROM inbox_messages im
                 LEFT JOIN practitioners p ON p.id = ? AND im.sender_type = \'practitioner\'
                 WHERE im.practitioner_id = ? AND im.end_user_id = ?
                 ORDER BY im.created_at ASC LIMIT 500'
            );
            $stmt->execute([$myPractId, $myPractId, $withId]);
            $messages = $stmt->fetchAll();

            // Also fetch client info for display
            $clientStmt = $pdo->prepare('SELECT id, display_name, email, avatar_path FROM end_users WHERE id = ? LIMIT 1');
            $clientStmt->execute([$withId]);
            $clientInfo = $clientStmt->fetch();

            // Fetch practitioner info
            $practStmt = $pdo->prepare('SELECT id, display_name, avatar_path FROM practitioners WHERE id = ? LIMIT 1');
            $practStmt->execute([$myPractId]);
            $practInfo = $practStmt->fetch();

        } else {
            // client: with_id = practitioner_id
            // Verify practitioner has a booking with this client
            $check = $pdo->prepare(
                'SELECT 1 FROM bookings WHERE practitioner_id = ? AND end_user_id = ? LIMIT 1'
            );
            $check->execute([$withId, $myClientId]);
            if (!$check->fetch()) {
                // Allow if existing thread
                $check2 = $pdo->prepare(
                    'SELECT 1 FROM inbox_messages WHERE practitioner_id = ? AND end_user_id = ? LIMIT 1'
                );
                $check2->execute([$withId, $myClientId]);
                if (!$check2->fetch()) {
                    http_response_code(403);
                    echo json_encode(['error' => 'No relationship with this practitioner']);
                    exit;
                }
            }
            $stmt = $pdo->prepare(
                'SELECT im.* FROM inbox_messages im
                 WHERE im.practitioner_id = ? AND im.end_user_id = ?
                 ORDER BY im.created_at ASC LIMIT 500'
            );
            $stmt->execute([$withId, $myClientId]);
            $messages = $stmt->fetchAll();

            $clientStmt = $pdo->prepare('SELECT id, display_name, email, avatar_path FROM end_users WHERE id = ? LIMIT 1');
            $clientStmt->execute([$myClientId]);
            $clientInfo = $clientStmt->fetch();

            $practStmt = $pdo->prepare('SELECT id, display_name, avatar_path FROM practitioners WHERE id = ? LIMIT 1');
            $practStmt->execute([$withId]);
            $practInfo = $practStmt->fetch();
        }

        // Normalise booleans / types
        foreach ($messages as &$m) {
            $m['id'] = (int)$m['id'];
        }
        unset($m);

        echo json_encode([
            'messages'   => array_values($messages),
            'client'     => $clientInfo ?: null,
            'practitioner' => $practInfo ?: null,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ── POST routes ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Support both JSON and multipart
    $isMultipart = isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'multipart');
    $input = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);

    $action = $input['action'] ?? '';

    // ── Mark read ──
    if ($action === 'mark_read') {
        $withId = (int)($input['with_id'] ?? 0);
        if (!$withId) { echo json_encode(['ok' => false]); exit; }

        if ($isPractitioner) {
            // Mark messages from client as read
            $pdo->prepare(
                "UPDATE inbox_messages SET read_at = NOW()
                 WHERE practitioner_id = ? AND end_user_id = ?
                 AND sender_type = 'client' AND read_at IS NULL"
            )->execute([$myPractId, $withId]);
        } else {
            $pdo->prepare(
                "UPDATE inbox_messages SET read_at = NOW()
                 WHERE practitioner_id = ? AND end_user_id = ?
                 AND sender_type = 'practitioner' AND read_at IS NULL"
            )->execute([$withId, $myClientId]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Send message ──
    if ($action === 'send') {
        $withId      = (int)($input['with_id'] ?? 0);
        $body        = trim($input['body'] ?? '');
        $messageType = $input['message_type'] ?? 'text';

        // media_rec allowed only from practitioner side
        $allowedTypes = ['text', 'voice_note', 'image'];
        if ($isPractitioner) $allowedTypes[] = 'media_rec';
        if (!in_array($messageType, $allowedTypes, true)) {
            $messageType = 'text';
        }

        if (!$withId) {
            http_response_code(400);
            echo json_encode(['error' => 'with_id required']);
            exit;
        }

        // Resolve practitioner_id / end_user_id
        if ($isPractitioner) {
            $practId   = $myPractId;
            $clientId  = $withId;
            $senderType = 'practitioner';
        } else {
            $practId   = $withId;
            $clientId  = $myClientId;
            $senderType = 'client';
        }

        // Verify relationship
        $check = $pdo->prepare(
            'SELECT 1 FROM bookings WHERE practitioner_id = ? AND end_user_id = ? LIMIT 1'
        );
        $check->execute([$practId, $clientId]);
        if (!$check->fetch()) {
            // Allow existing threads
            $check2 = $pdo->prepare(
                'SELECT 1 FROM inbox_messages WHERE practitioner_id = ? AND end_user_id = ? LIMIT 1'
            );
            $check2->execute([$practId, $clientId]);
            if (!$check2->fetch()) {
                http_response_code(403);
                echo json_encode(['error' => 'No relationship']);
                exit;
            }
        }

        $filePath = null;
        $fileName = null;
        $mimeType = null;

        // Handle file upload for voice_note or image
        if ($messageType !== 'text' && !empty($_FILES['file']['name']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $file  = $_FILES['file'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);

            if ($messageType === 'image') {
                $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!in_array($mime, $allowed, true)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid image type']);
                    exit;
                }
                $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                $ext    = $extMap[$mime];
                $fname  = 'ib_img_' . bin2hex(random_bytes(10)) . '.' . $ext;
            } else {
                // voice_note
                $allowed = ['audio/webm','audio/ogg','audio/mpeg','audio/wav','audio/x-wav',
                            'video/webm','application/ogg','application/octet-stream'];
                if (!in_array($mime, $allowed, true) && !str_starts_with($mime, 'audio/')) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid audio type']);
                    exit;
                }
                $extMap = ['audio/webm'=>'webm','audio/ogg'=>'ogg','audio/mpeg'=>'mp3',
                           'audio/wav'=>'wav','audio/x-wav'=>'wav','video/webm'=>'webm',
                           'application/ogg'=>'ogg','application/octet-stream'=>'webm'];
                $ext   = $extMap[$mime] ?? 'webm';
                $fname = 'ib_vn_' . bin2hex(random_bytes(10)) . '.' . $ext;
            }

            if ($file['size'] > 20 * 1024 * 1024) {
                http_response_code(400);
                echo json_encode(['error' => 'File too large (max 20 MB)']);
                exit;
            }

            $dest = __DIR__ . '/../assets/uploads/' . $fname;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                http_response_code(500);
                echo json_encode(['error' => 'Upload failed']);
                exit;
            }

            $filePath = '/assets/uploads/' . $fname;
            $fileName = $fname;
            $mimeType = $mime;
            if (empty($body) && $messageType === 'voice_note') $body = null;
        }

        if (in_array($messageType, ['text', 'media_rec'], true) && $body === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Message body required']);
            exit;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO inbox_messages (practitioner_id, end_user_id, sender_type, body, message_type, file_path, file_name, mime_type)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([$practId, $clientId, $senderType, $body ?: null, $messageType, $filePath, $fileName, $mimeType]);
        $newId = (int)$pdo->lastInsertId();

        $row = $pdo->prepare('SELECT * FROM inbox_messages WHERE id = ? LIMIT 1');
        $row->execute([$newId]);
        echo json_encode($row->fetch());
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
