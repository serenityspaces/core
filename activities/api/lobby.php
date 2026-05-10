<?php
/**
 * Activity Lobby API — lobby lifecycle
 * GET  ?action=status&lobby_id=CODE  → lobby status
 * POST {action:'join'|'close', lobby_id, user_id}
 */
require_once __DIR__ . '/../../db/connection.php';
require_once __DIR__ . '/../../includes/security_headers.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $lobby  = $_GET['lobby_id'] ?? '';
    if ($action === 'status' && $lobby) {
        $stmt = $pdo->prepare('SELECT status, user1_id, user2_id FROM activity_lobbies WHERE lobby_code = ? LIMIT 1');
        $stmt->execute([$lobby]);
        $lb = $stmt->fetch();
        if ($lb) {
            $playerCount = ($lb['user1_id'] ? 1 : 0) + ($lb['user2_id'] ? 1 : 0);
            echo json_encode(['status' => $lb['status'], 'players' => $playerCount]);
        } else {
            echo json_encode(['status' => 'ended', 'players' => 0]);
        }
    } else {
        echo json_encode(['ok' => true]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => true]); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = $body['action']   ?? '';
$lobby   = $body['lobby_id'] ?? '';
$userId  = (int)($body['user_id'] ?? 0);

if ($action === 'join' && $lobby && $userId) {
    $stmt = $pdo->prepare('SELECT user1_id, user2_id FROM activity_lobbies WHERE lobby_code = ? LIMIT 1');
    $stmt->execute([$lobby]);
    $lb = $stmt->fetch();
    $playerNum = 1;
    if ($lb) {
        if (!$lb['user1_id']) {
            $pdo->prepare("UPDATE activity_lobbies SET user1_id = ? WHERE lobby_code = ?")->execute([$userId, $lobby]);
            $playerNum = 1;
        } elseif ($lb['user1_id'] == $userId) {
            $playerNum = 1;
        } elseif (!$lb['user2_id']) {
            $pdo->prepare("UPDATE activity_lobbies SET user2_id = ?, status = 'active' WHERE lobby_code = ?")->execute([$userId, $lobby]);
            $playerNum = 2;
        } else {
            $playerNum = ($lb['user2_id'] == $userId) ? 2 : 3; // 3 = spectator
        }
    }
    echo json_encode(['ok' => true, 'player_num' => $playerNum]);
    exit;
}

if ($action === 'close' && $lobby) {
    // Mark user as left — if both users left, end the lobby
    $stmt = $pdo->prepare('SELECT user1_id, user2_id FROM activity_lobbies WHERE lobby_code = ? LIMIT 1');
    $stmt->execute([$lobby]);
    $lb = $stmt->fetch();
    if ($lb) {
        if ($lb['user1_id'] == $userId) {
            $pdo->prepare("UPDATE activity_lobbies SET user1_id = NULL WHERE lobby_code = ?")->execute([$lobby]);
        } elseif ($lb['user2_id'] == $userId) {
            $pdo->prepare("UPDATE activity_lobbies SET user2_id = NULL WHERE lobby_code = ?")->execute([$lobby]);
        }
        // If both gone, mark ended
        $pdo->prepare(
            "UPDATE activity_lobbies SET status = 'ended' WHERE lobby_code = ? AND user1_id IS NULL AND user2_id IS NULL"
        )->execute([$lobby]);
    }
}

echo json_encode(['ok' => true]);
