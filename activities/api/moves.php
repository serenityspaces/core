<?php
/**
 * Activity Moves API
 * GET  ?lobby=CODE&lastSeq=N  → return moves since lastSeq
 * POST {lobby_id, user_id, payload} → append a move
 */
require_once __DIR__ . '/../../db/connection.php';
require_once __DIR__ . '/../../includes/security_headers.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lobby   = $_GET['lobby']   ?? '';
    $lastSeq = (int)($_GET['lastSeq'] ?? 0);
    if (!$lobby) { echo '[]'; exit; }

    $stmt = $pdo->prepare(
        'SELECT id, user_id, payload, sequence, created_at
         FROM activity_moves
         WHERE lobby_code = ? AND sequence > ?
         ORDER BY sequence ASC
         LIMIT 200'
    );
    $stmt->execute([$lobby, $lastSeq]);
    $rows = $stmt->fetchAll();
    $out  = array_map(fn($r) => [
        'id'       => (int)$r['id'],
        'user_id'  => (int)$r['user_id'],
        'payload'  => json_decode($r['payload'], true),
        'sequence' => (int)$r['sequence'],
    ], $rows);
    echo json_encode($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $lobby   = $body['lobby_id'] ?? '';
    $userId  = (int)($body['user_id'] ?? 0);
    $payload = $body['payload']  ?? null;

    if (!$lobby || !$userId || $payload === null) {
        echo json_encode(['error' => 'missing fields']); exit;
    }

    // Get next sequence number
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sequence), 0) + 1 FROM activity_moves WHERE lobby_code = ?');
    $stmt->execute([$lobby]);
    $seq = (int)$stmt->fetchColumn();

    $pdo->prepare(
        'INSERT INTO activity_moves (lobby_code, user_id, payload, sequence) VALUES (?,?,?,?)'
    )->execute([$lobby, $userId, json_encode($payload), $seq]);

    // Update lobby: set user2 if it's a join and user1 already set
    if (isset($payload['type']) && $payload['type'] === 'join') {
        $stmt = $pdo->prepare('SELECT user1_id, user2_id FROM activity_lobbies WHERE lobby_code = ? LIMIT 1');
        $stmt->execute([$lobby]);
        $lb = $stmt->fetch();
        if ($lb) {
            if (!$lb['user1_id']) {
                $pdo->prepare("UPDATE activity_lobbies SET user1_id = ?, status = 'waiting' WHERE lobby_code = ?")->execute([$userId, $lobby]);
            } elseif ($lb['user1_id'] != $userId && !$lb['user2_id']) {
                $pdo->prepare("UPDATE activity_lobbies SET user2_id = ?, status = 'active' WHERE lobby_code = ?")->execute([$userId, $lobby]);
            }
        }
    }

    echo json_encode(['ok' => true, 'sequence' => $seq]);
    exit;
}

echo json_encode(['error' => 'bad request']);
