<?php
/**
 * AI Integration — Completion Engine
 *
 * Actions (practitioner-only except where noted):
 *   summarize        — summarise session transcript
 *   assist_notes     — generate draft clinical note from transcript
 *   post_session_chat — chat with AI about a completed session
 *   in_session_respond — AI responds in a live session (called by room JS)
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/phi_crypto.php';
require_once __DIR__ . '/../db/events.php';
require_once __DIR__ . '/../db/audit.php';

header('Content-Type: application/json');

if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Practitioners only']);
    exit;
}

$practId = (int)$_SESSION['practitioner_id'];
$pdo     = getDB();

// Platform gate
if (!(bool)(int)getSetting('ai_enabled', '0')) {
    http_response_code(403);
    echo json_encode(['error' => 'AI Integration is disabled']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// CSRF
$csrfToken = $input['csrf_token'] ?? '';
if (!validate_csrf($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF validation failed']);
    exit;
}

$action = $input['action'] ?? '';

// Load practitioner AI config
$stmt = $pdo->prepare('SELECT * FROM practitioner_ai_config WHERE practitioner_id = ? AND enabled = 1');
$stmt->execute([$practId]);
$cfg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cfg) {
    http_response_code(403);
    echo json_encode(['error' => 'AI Integration not configured or not enabled']);
    exit;
}

$apiKey = phi_decrypt($cfg['api_key_enc'] ?? '', "ai_config:{$practId}");
if ($apiKey === '') {
    http_response_code(400);
    echo json_encode(['error' => 'No API key configured']);
    exit;
}

$vendor        = $cfg['vendor'];
$model         = $cfg['model'];
$systemPrompt  = trim($cfg['assistant_prompt'] ?? '');
$assistantName = $cfg['assistant_name'] ?? 'Assistant';

// ── SUMMARIZE ─────────────────────────────────────────────────────
if ($action === 'summarize') {
    if (!$cfg['scope_summarization']) {
        http_response_code(403);
        echo json_encode(['error' => 'Summarization scope not enabled']);
        exit;
    }

    $sessionId = (int)($input['session_id'] ?? 0);
    if (!$sessionId) {
        http_response_code(400);
        echo json_encode(['error' => 'session_id required']);
        exit;
    }

    // Verify ownership
    $stmt = $pdo->prepare('SELECT id FROM sessions s JOIN rooms r ON r.id = s.room_id WHERE s.id = ? AND r.practitioner_id = ?');
    $stmt->execute([$sessionId, $practId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    $transcript = build_transcript($pdo, $sessionId, $practId);

    $base = 'You are a clinical documentation assistant. Summarise the following session transcript into a concise, structured summary suitable for a practitioner\'s records. Focus on key themes, client concerns, and practitioner observations. Do not include any interpretation beyond what is stated. Keep the summary professional and factual.';
    $sysP = $systemPrompt !== '' ? $systemPrompt . "\n\n" . $base : $base;

    $reply = call_vendor($vendor, $apiKey, $model, $sysP, [['role'=>'user','content'=>$transcript]]);

    echo json_encode(['ok' => true, 'summary' => $reply]);
    exit;
}

// ── ASSIST NOTES ──────────────────────────────────────────────────
if ($action === 'assist_notes') {
    if (!$cfg['scope_notes']) {
        http_response_code(403);
        echo json_encode(['error' => 'Notes assistance scope not enabled']);
        exit;
    }

    $sessionId = (int)($input['session_id'] ?? 0);
    $template  = strtoupper(trim($input['template'] ?? 'SOAP'));
    if (!in_array($template, ['SOAP','DAP','BIRP'])) $template = 'SOAP';
    if (!$sessionId) {
        http_response_code(400);
        echo json_encode(['error' => 'session_id required']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM sessions s JOIN rooms r ON r.id = s.room_id WHERE s.id = ? AND r.practitioner_id = ?');
    $stmt->execute([$sessionId, $practId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    $transcript = build_transcript($pdo, $sessionId, $practId);

    $base = "You are a clinical documentation assistant. Based on the following session transcript, generate a draft {$template} clinical note for the practitioner. Structure it clearly with {$template} headings. Use professional clinical language. Mark inferred content with [inferred] so the practitioner can review. Do not fabricate details not present in the transcript.";
    $sysP = $systemPrompt !== '' ? $systemPrompt . "\n\n" . $base : $base;

    $reply = call_vendor($vendor, $apiKey, $model, $sysP, [['role'=>'user','content'=>$transcript]]);

    echo json_encode(['ok' => true, 'draft' => $reply]);
    exit;
}

// ── POST-SESSION CHAT ─────────────────────────────────────────────
if ($action === 'post_session_chat') {
    if (!$cfg['scope_post_session']) {
        http_response_code(403);
        echo json_encode(['error' => 'Post-session scope not enabled']);
        exit;
    }

    $sessionId = (int)($input['session_id'] ?? 0);
    $messages  = $input['messages'] ?? [];

    if (!$sessionId || !is_array($messages)) {
        http_response_code(400);
        echo json_encode(['error' => 'session_id and messages required']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id FROM sessions s JOIN rooms r ON r.id = s.room_id WHERE s.id = ? AND r.practitioner_id = ?');
    $stmt->execute([$sessionId, $practId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    // Build context: transcript + conversation history
    $transcript = build_transcript($pdo, $sessionId, $practId);
    $base = "You are {$assistantName}, a reflective thinking partner for practitioners reviewing their sessions. You have access to the session transcript below. Help the practitioner think through what occurred, identify patterns, and plan next steps. Be thoughtful, curious, and non-directive.\n\nSESSION TRANSCRIPT:\n{$transcript}";
    $sysP = $systemPrompt !== '' ? $systemPrompt . "\n\n" . $base : $base;

    // Sanitise messages to role/content only
    $history = [];
    foreach ($messages as $m) {
        $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
        $history[] = ['role' => $role, 'content' => (string)($m['content'] ?? '')];
    }

    $reply = call_vendor($vendor, $apiKey, $model, $sysP, $history);

    echo json_encode(['ok' => true, 'reply' => $reply]);
    exit;
}

// ── IN-SESSION RESPOND ────────────────────────────────────────────
if ($action === 'in_session_respond') {
    if (!$cfg['scope_in_session']) {
        http_response_code(403);
        echo json_encode(['error' => 'In-session scope not enabled']);
        exit;
    }

    $sessionId = (int)($input['session_id'] ?? 0);
    $trigger   = trim($input['message'] ?? '');
    if (!$sessionId || $trigger === '') {
        http_response_code(400);
        echo json_encode(['error' => 'session_id and message required']);
        exit;
    }

    // Verify session belongs to this practitioner
    $stmt = $pdo->prepare('
        SELECT s.id, s.room_id FROM sessions s
        JOIN rooms r ON r.id = s.room_id
        WHERE s.id = ? AND r.practitioner_id = ? AND s.ended_at IS NULL
    ');
    $stmt->execute([$sessionId, $practId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        http_response_code(403);
        echo json_encode(['error' => 'Session not found or already ended']);
        exit;
    }

    // Emit typing indicator from AI participant
    $aiJoinToken = 'ai-' . $sessionId . '-' . $practId;
    $stmt = $pdo->prepare('SELECT id, display_name FROM participants WHERE session_id = ? AND join_token = ?');
    $stmt->execute([$sessionId, $aiJoinToken]);
    $aiParticipant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$aiParticipant) {
        http_response_code(404);
        echo json_encode(['error' => 'AI participant not found in session']);
        exit;
    }

    $aiParticipantId   = (int)$aiParticipant['id'];
    $aiDisplayName     = $aiParticipant['display_name'];

    emitEvent($pdo, $sessionId, 'typing', [
        'participant_id' => $aiParticipantId,
        'is_typing'      => true,
    ]);

    // Build recent context (last 20 messages)
    $context = build_transcript($pdo, $sessionId, $practId, 20);
    $base = "You are {$assistantName}, an AI assistant present in a live therapeutic session. You may only respond when directly addressed by name at the start of a message. Keep responses brief (2-4 sentences unless asked for more), supportive, and clinically aware. Do not interrupt or interject unprompted. You are aware this is a sensitive clinical context involving mental health.";
    $sysP = $systemPrompt !== '' ? $systemPrompt . "\n\n" . $base : $base;

    $messages = [
        ['role' => 'user', 'content' => "Recent session context:\n{$context}\n\nThe practitioner just said to you: {$trigger}"],
    ];

    $reply = call_vendor($vendor, $apiKey, $model, $sysP, $messages);

    // Strip "AssistantName: " self-prefix if the model included it.
    // Models sometimes open with their own name (e.g. "Aria: Yes, I can help…")
    // which duplicates the display_name we already show in the chat UI.
    $namePrefix = $assistantName . ':';
    if (stripos($reply, $namePrefix) === 0) {
        $reply = ltrim(substr($reply, strlen($namePrefix)));
    }

    // Insert AI message
    $nowTs    = date('Y-m-d H:i:s');
    $encrypted = phi_encrypt($reply, "messages:{$sessionId}");
    $stmt = $pdo->prepare('
        INSERT INTO messages (session_id, participant_id, content, message_type, is_practitioner)
        VALUES (?, ?, ?, \'text\', 0)
    ');
    $stmt->execute([$sessionId, $aiParticipantId, $encrypted]);
    $messageId = (int)$pdo->lastInsertId();

    // Clear typing only after message is ready, then immediately emit the message —
    // ensures the typing indicator stays visible until the response lands in the chat.
    emitEvent($pdo, $sessionId, 'typing', [
        'participant_id' => $aiParticipantId,
        'is_typing'      => false,
    ]);

    emitEvent($pdo, $sessionId, 'message', [
        'id'             => $messageId,
        'session_id'     => $sessionId,
        'participant_id' => $aiParticipantId,
        'display_name'   => $aiDisplayName,
        'content'        => $reply,
        'message_type'   => 'text',
        'is_practitioner'=> false,
        'is_pinned'      => false,
        'is_deleted'     => false,
        'sent_at'        => $nowTs,
    ]);

    audit_log($pdo, 'ai.respond', $practId, ['session_id' => $sessionId]);

    echo json_encode(['ok' => true, 'message_id' => $messageId]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
exit;

// ── Helpers ───────────────────────────────────────────────────────

function build_transcript(PDO $pdo, int $sessionId, int $practId, int $limit = 0): string
{
    $sql = '
        SELECT m.content, m.is_practitioner, m.message_type, m.sent_at,
               p.display_name
        FROM messages m
        LEFT JOIN participants p ON p.id = m.participant_id
        WHERE m.session_id = ? AND m.is_deleted = 0 AND m.message_type = \'text\'
        ORDER BY m.id ASC
    ';
    if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sessionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $lines = [];
    foreach ($rows as $row) {
        $content = phi_decrypt($row['content'], "messages:{$sessionId}");
        $speaker = $row['display_name'] ?? ($row['is_practitioner'] ? 'Practitioner' : 'Client');
        $lines[] = "[{$row['sent_at']}] {$speaker}: {$content}";
    }

    return implode("\n", $lines);
}

function call_vendor(string $vendor, string $apiKey, string $model, string $systemPrompt, array $messages): string
{
    switch ($vendor) {
        case 'openai':    return call_openai($apiKey, $model, $systemPrompt, $messages);
        case 'anthropic': return call_anthropic($apiKey, $model, $systemPrompt, $messages);
        case 'google':    return call_google($apiKey, $model, $systemPrompt, $messages);
        case 'cohere':    return call_cohere($apiKey, $model, $systemPrompt, $messages);
        default:          return '';
    }
}

function call_openai(string $apiKey, string $model, string $systemPrompt, array $messages): string
{
    $payload = [
        'model'    => $model ?: 'gpt-4o',
        'messages' => array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages
        ),
    ];
    $resp = ai_http_post('https://api.openai.com/v1/chat/completions', $payload, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ]);
    return $resp['choices'][0]['message']['content'] ?? '';
}

function call_anthropic(string $apiKey, string $model, string $systemPrompt, array $messages): string
{
    $payload = [
        'model'      => $model ?: 'claude-sonnet-4-6',
        'max_tokens' => 1024,
        'system'     => $systemPrompt,
        'messages'   => $messages,
    ];
    $resp = ai_http_post('https://api.anthropic.com/v1/messages', $payload, [
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'Content-Type: application/json',
    ]);
    return $resp['content'][0]['text'] ?? '';
}

function call_google(string $apiKey, string $model, string $systemPrompt, array $messages): string
{
    $m = $model ?: 'gemini-1.5-pro';
    // Convert to Gemini format
    $contents = [];
    foreach ($messages as $msg) {
        $role = $msg['role'] === 'assistant' ? 'model' : 'user';
        $contents[] = ['role' => $role, 'parts' => [['text' => $msg['content']]]];
    }
    $payload = [
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
    ];
    $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$m}:generateContent?key=" . urlencode($apiKey);
    $resp = ai_http_post($url, $payload, ['Content-Type: application/json']);
    return $resp['candidates'][0]['content']['parts'][0]['text'] ?? '';
}

function call_cohere(string $apiKey, string $model, string $systemPrompt, array $messages): string
{
    $payload = [
        'model'    => $model ?: 'command-r-plus',
        'messages' => array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages
        ),
    ];
    $resp = ai_http_post('https://api.cohere.com/v2/chat', $payload, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ]);
    return $resp['message']['content'][0]['text'] ?? '';
}

function ai_http_post(string $url, array $payload, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw  = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) return [];
    return json_decode($raw, true) ?? [];
}
