<?php
/**
 * Client private notes — per-booking reflection space.
 * Visible to the client only. Never exposed to the practitioner.
 *
 * GET  ?booking_id=X   — load note for booking
 * POST {action:'save', booking_id:X, note_text:'...'}   — upsert note
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/phi_crypto.php';
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();

header('Content-Type: application/json');

// Auth: must be a logged-in end_user OR have a valid reconnect cookie for this booking
$endUserId = null;
$guestVerified = false;
$bookingId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $bookingId = (int)($_GET['booking_id'] ?? 0);
} else {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $bookingId = (int)($input['booking_id'] ?? 0);
    $action    = $input['action'] ?? '';
    $noteText  = $input['note_text'] ?? '';
}

if ($bookingId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'booking_id required']);
    exit;
}

// Verify caller is the client for this booking
if (isset($_SESSION['end_user_id'])) {
    $endUserId = (int)$_SESSION['end_user_id'];
    // Confirm booking belongs to this end_user
    $stmt = $pdo->prepare('SELECT id FROM bookings WHERE id = ? AND end_user_id = ?');
    $stmt->execute([$bookingId, $endUserId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
} else {
    // Guest: validate via reconnect cookie (raw join_token value) matching participants table
    $joinToken = '';
    foreach ($_COOKIE as $k => $v) {
        if (str_starts_with($k, 'ss_reconnect_')) { $joinToken = $v; break; }
    }
    if ($joinToken === '') {
        http_response_code(403);
        echo json_encode(['error' => 'unauthenticated']);
        exit;
    }
    // Verify join_token belongs to a participant in a session linked to this booking
    $stmt = $pdo->prepare(
        'SELECT p.id FROM participants p
         JOIN sessions s ON s.id = p.session_id
         JOIN bookings b ON b.room_id = s.room_id
         WHERE b.id = ? AND p.join_token = ?
         LIMIT 1'
    );
    $stmt->execute([$bookingId, $joinToken]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    $guestVerified = true;
}

// ── GET: load ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT note_text FROM client_notes WHERE booking_id = ?');
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $text = $row ? phi_decrypt((string)($row['note_text'] ?? '')) : '';
    echo json_encode(['note_text' => $text]);
    exit;
}

// ── POST: save ────────────────────────────────────────────────────
if (($action ?? '') !== 'save') {
    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
    exit;
}

$encrypted = phi_encrypt($noteText);

$stmt = $pdo->prepare(
    'INSERT INTO client_notes (booking_id, end_user_id, note_text)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE note_text = VALUES(note_text), updated_at = NOW()'
);
$stmt->execute([$bookingId, $endUserId ?: null, $encrypted]);

echo json_encode(['ok' => true]);
