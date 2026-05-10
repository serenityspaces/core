<?php
/**
 * Activity State API — persistent game state (e.g. canvas image for paint)
 * GET  ?lobby=CODE             → return state JSON
 * POST {lobby_id, user_id, state} → upsert state
 */
require_once __DIR__ . '/../../db/connection.php';
require_once __DIR__ . '/../../includes/security_headers.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lobby = $_GET['lobby'] ?? '';
    if (!$lobby) { echo 'null'; exit; }

    $stmt = $pdo->prepare('SELECT state_json FROM activity_state WHERE lobby_code = ? LIMIT 1');
    $stmt->execute([$lobby]);
    $row = $stmt->fetch();
    // Wrap in {state:...} format that the game clients expect
    if ($row) {
        echo json_encode(['state' => json_decode($row['state_json'], true)]);
    } else {
        echo 'null';
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $lobby = $body['lobby_id'] ?? '';
    $state = $body['state']    ?? null;

    if (!$lobby || $state === null) {
        echo json_encode(['error' => 'missing fields']); exit;
    }

    $pdo->prepare(
        'INSERT INTO activity_state (lobby_code, state_json) VALUES (?,?)
         ON DUPLICATE KEY UPDATE state_json = ?, updated_at = NOW()'
    )->execute([$lobby, json_encode($state), json_encode($state)]);

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['error' => 'bad request']);
