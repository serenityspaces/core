<?php
/**
 * Event emission helper.
 * All real-time changes (messages, positions, typing, etc.) are written
 * as events so every client receives them via the event-based poll endpoint.
 */
function emitEvent(PDO $pdo, int $sessionId, string $type, array $payload): void {
    $pdo->prepare('INSERT INTO events (session_id, type, payload) VALUES (?,?,?)')
        ->execute([$sessionId, $type, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}
