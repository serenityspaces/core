<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

header('Content-Type: application/json');

// Only practitioners
if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$practitionerId = (int)$_SESSION['practitioner_id'];
$pdo            = getDB();
$method         = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // List rooms
    $stmt = $pdo->prepare(
        'SELECT r.*,
         (SELECT COUNT(*) FROM sessions s
          JOIN participants p ON p.session_id = s.id
          WHERE s.room_id = r.id AND s.ended_at IS NULL) as participant_count
         FROM rooms r WHERE r.practitioner_id = ? ORDER BY r.created_at DESC'
    );
    $stmt->execute([$practitionerId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $input['action'] ?? '';

    if ($action === 'create') {
        $name = trim($input['name'] ?? '');
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Name is required']);
            exit;
        }
        $token = bin2hex(random_bytes(16));
        $stmt  = $pdo->prepare(
            'INSERT INTO rooms (practitioner_id, name, background_path, room_token) VALUES (?,?,?,?)'
        );
        $stmt->execute([$practitionerId, $name, $input['background_path'] ?? null, $token]);
        $id   = $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode($stmt->fetch());
        exit;
    }

    if ($action === 'delete') {
        $roomId = (int)($input['room_id'] ?? 0);
        $stmt   = $pdo->prepare('SELECT id FROM rooms WHERE id = ? AND practitioner_id = ?');
        $stmt->execute([$roomId, $practitionerId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'Not found or forbidden']);
            exit;
        }
        // Refuse to delete a room that has an active session — would orphan connected clients.
        $live = $pdo->prepare('SELECT COUNT(*) FROM sessions WHERE room_id = ? AND ended_at IS NULL');
        $live->execute([$roomId]);
        if ((int)$live->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Cannot delete a room with an active session. End the session first.']);
            exit;
        }
        $pdo->prepare('DELETE FROM rooms WHERE id = ?')->execute([$roomId]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Bad request']);
