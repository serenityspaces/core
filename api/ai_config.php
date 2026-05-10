<?php
/**
 * AI Integration — Practitioner Configuration
 *
 * Actions:
 *   load         — GET  — returns current config (API key redacted)
 *   save         — POST — saves vendor, model, scopes, assistant settings
 *   save_avatar  — POST — uploads assistant avatar image
 *   fetch_models — POST — queries vendor API for available model list
 *   delete_avatar — POST — removes custom assistant avatar
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/phi_crypto.php';
require_once __DIR__ . '/../includes/firewall.php';

header('Content-Type: application/json');

if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Practitioners only']);
    exit;
}

$practId = (int)$_SESSION['practitioner_id'];
$pdo     = getDB();

// ── Check platform AI enabled ────────────────────────────────────
$aiEnabled = (bool)(int)getSetting('ai_enabled', '0');

// Used by the frontend to offer admin-direct activation when the practitioner
// is also an admin on a self-hosted install.
$isAdminStmt = $pdo->prepare('SELECT is_admin FROM practitioners WHERE id = ?');
$isAdminStmt->execute([$practId]);
$isAdmin = (bool)(int)$isAdminStmt->fetchColumn();

$action = $_GET['action'] ?? ($_POST['action'] ?? (json_decode(file_get_contents('php://input'), true)['action'] ?? ''));
$input  = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST;
}

// ── LOAD ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' || $action === 'load') {
    $stmt = $pdo->prepare('SELECT * FROM practitioner_ai_config WHERE practitioner_id = ?');
    $stmt->execute([$practId]);
    $cfg = $stmt->fetch(PDO::FETCH_ASSOC);

    $allowedVendors = json_decode(getSetting('ai_allowed_vendors', '["openai","anthropic","google","cohere"]'), true) ?? [];
    $allowedScopes  = json_decode(getSetting('ai_allowed_scopes', '["summarization","notes","post_session","in_session"]'), true) ?? [];

    if (!$cfg) {
        echo json_encode([
            'enabled'             => false,
            'vendor'              => 'openai',
            'api_key_set'         => false,
            'model'               => '',
            'scope_summarization' => false,
            'scope_notes'         => false,
            'scope_post_session'  => false,
            'scope_in_session'    => false,
            'assistant_name'      => 'Assistant',
            'assistant_avatar'    => null,
            'assistant_prompt'    => '',
            'platform_enabled'    => $aiEnabled,
            'is_admin'            => $isAdmin,
            'allowed_vendors'     => $allowedVendors,
            'allowed_scopes'      => $allowedScopes,
        ]);
        exit;
    }

    echo json_encode([
        'enabled'             => (bool)(int)$cfg['enabled'],
        'vendor'              => $cfg['vendor'],
        'api_key_set'         => !empty($cfg['api_key_enc']),
        'model'               => $cfg['model'],
        'scope_summarization' => (bool)(int)$cfg['scope_summarization'],
        'scope_notes'         => (bool)(int)$cfg['scope_notes'],
        'scope_post_session'  => (bool)(int)$cfg['scope_post_session'],
        'scope_in_session'    => (bool)(int)$cfg['scope_in_session'],
        'assistant_name'      => $cfg['assistant_name'],
        'assistant_avatar'    => $cfg['assistant_avatar'],
        'assistant_prompt'    => $cfg['assistant_prompt'] ?? '',
        'platform_enabled'    => $aiEnabled,
        'is_admin'            => $isAdmin,
        'allowed_vendors'     => $allowedVendors,
        'allowed_scopes'      => $allowedScopes,
    ]);
    exit;
}

// All remaining actions are POST + require CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$csrfToken = $input['csrf_token'] ?? '';
if (!validate_csrf($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF validation failed']);
    exit;
}

// ── SAVE ──────────────────────────────────────────────────────────
if ($action === 'save') {
    if (!$aiEnabled) {
        http_response_code(403);
        echo json_encode(['error' => 'AI Integration is disabled by the platform administrator']);
        exit;
    }

    $allowedVendors = json_decode(getSetting('ai_allowed_vendors', '["openai","anthropic","google","cohere"]'), true) ?? [];
    $allowedScopes  = json_decode(getSetting('ai_allowed_scopes', '["summarization","notes","post_session","in_session"]'), true) ?? [];

    $vendor        = in_array($input['vendor'] ?? '', $allowedVendors) ? $input['vendor'] : 'openai';
    $model         = substr(trim($input['model'] ?? ''), 0, 128);
    $assistantName = substr(trim($input['assistant_name'] ?? 'Assistant'), 0, 100) ?: 'Assistant';
    $assistantPrompt = trim($input['assistant_prompt'] ?? '');
    $enabled       = !empty($input['enabled']) ? 1 : 0;

    // DPA gating — block enabling AI without a signed DPA for the selected vendor
    if ($enabled) {
        $vendorToDpaType = [
            'openai'    => 'ai_openai',
            'anthropic' => 'ai_anthropic',
            'google'    => 'ai_google',
            'cohere'    => 'ai_cohere',
        ];
        $dpaType = $vendorToDpaType[$vendor] ?? 'ai_custom';
        if ($dpaType !== 'ai_custom') {
            $dpaStmt = $pdo->prepare(
                'SELECT id FROM dpa_documents WHERE scope = ? AND type = ? AND practitioner_id = ? LIMIT 1'
            );
            $dpaStmt->execute(['practitioner', $dpaType, $practId]);
            if (!$dpaStmt->fetch()) {
                $vendorLabel = ['openai'=>'OpenAI','anthropic'=>'Anthropic','google'=>'Google AI','cohere'=>'Cohere'][$vendor] ?? $vendor;
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => "A Data Processing Agreement for {$vendorLabel} is required before enabling AI integration. Upload the DPA in the Data Processing Agreement section."]);
                exit;
            }
        }
    }

    // Scope enforcement against platform allowlist
    $scopeSummarization = (in_array('summarization', $allowedScopes) && !empty($input['scope_summarization'])) ? 1 : 0;
    $scopeNotes         = (in_array('notes', $allowedScopes) && !empty($input['scope_notes'])) ? 1 : 0;
    $scopePostSession   = (in_array('post_session', $allowedScopes) && !empty($input['scope_post_session'])) ? 1 : 0;
    $scopeInSession     = (in_array('in_session', $allowedScopes) && !empty($input['scope_in_session'])) ? 1 : 0;

    // Get existing config to check for current API key
    $stmt = $pdo->prepare('SELECT api_key_enc FROM practitioner_ai_config WHERE practitioner_id = ?');
    $stmt->execute([$practId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    // API key: only update if a new one was submitted
    $apiKeyEnc = $existing['api_key_enc'] ?? null;
    $newKey    = trim($input['api_key'] ?? '');
    if ($newKey !== '' && $newKey !== '••••••••') {
        $apiKeyEnc = phi_encrypt($newKey, "ai_config:{$practId}");
    }
    // If clear_api_key flag sent, remove it
    if (!empty($input['clear_api_key'])) {
        $apiKeyEnc = null;
    }

    $pdo->prepare('
        INSERT INTO practitioner_ai_config
            (practitioner_id, enabled, vendor, api_key_enc, model,
             scope_summarization, scope_notes, scope_post_session, scope_in_session,
             assistant_name, assistant_prompt)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            enabled = VALUES(enabled),
            vendor  = VALUES(vendor),
            api_key_enc = COALESCE(VALUES(api_key_enc), api_key_enc),
            model   = VALUES(model),
            scope_summarization = VALUES(scope_summarization),
            scope_notes         = VALUES(scope_notes),
            scope_post_session  = VALUES(scope_post_session),
            scope_in_session    = VALUES(scope_in_session),
            assistant_name      = VALUES(assistant_name),
            assistant_prompt    = VALUES(assistant_prompt),
            updated_at          = CURRENT_TIMESTAMP
    ')->execute([
        $practId, $enabled, $vendor, $apiKeyEnc, $model,
        $scopeSummarization, $scopeNotes, $scopePostSession, $scopeInSession,
        $assistantName, $assistantPrompt
    ]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── SAVE AVATAR ───────────────────────────────────────────────────
if ($action === 'save_avatar') {
    if (!isset($_FILES['avatar'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded']);
        exit;
    }

    $file     = $_FILES['avatar'];
    $mimeType = mime_content_type($file['tmp_name']);
    $allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    if (!isset($allowed[$mimeType])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid image format']);
        exit;
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'Image must be under 2 MB']);
        exit;
    }

    $ext      = $allowed[$mimeType];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dir      = __DIR__ . '/../assets/avatars/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // Delete old custom avatar if present
    $stmt = $pdo->prepare('SELECT assistant_avatar FROM practitioner_ai_config WHERE practitioner_id = ?');
    $stmt->execute([$practId]);
    $old = $stmt->fetchColumn();
    if ($old && strpos($old, 'assets/avatars/') !== false) {
        $oldPath = __DIR__ . '/../' . $old;
        if (file_exists($oldPath)) @unlink($oldPath);
    }

    move_uploaded_file($file['tmp_name'], $dir . $filename);
    $avatarPath = 'assets/avatars/' . $filename;

    $pdo->prepare('
        INSERT INTO practitioner_ai_config (practitioner_id, assistant_avatar)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE assistant_avatar = VALUES(assistant_avatar), updated_at = CURRENT_TIMESTAMP
    ')->execute([$practId, $avatarPath]);

    echo json_encode(['ok' => true, 'path' => $avatarPath]);
    exit;
}

// ── DELETE AVATAR ─────────────────────────────────────────────────
if ($action === 'delete_avatar') {
    $stmt = $pdo->prepare('SELECT assistant_avatar FROM practitioner_ai_config WHERE practitioner_id = ?');
    $stmt->execute([$practId]);
    $old = $stmt->fetchColumn();
    if ($old && strpos($old, 'assets/avatars/') !== false) {
        $oldPath = __DIR__ . '/../' . $old;
        if (file_exists($oldPath)) @unlink($oldPath);
    }
    $pdo->prepare('UPDATE practitioner_ai_config SET assistant_avatar = NULL, updated_at = CURRENT_TIMESTAMP WHERE practitioner_id = ?')
        ->execute([$practId]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── FETCH MODELS ──────────────────────────────────────────────────
if ($action === 'fetch_models') {
    $vendor = trim($input['vendor'] ?? '');
    $apiKey = trim($input['api_key'] ?? '');

    // If no key submitted, decrypt stored key
    if ($apiKey === '' || $apiKey === '••••••••') {
        $stmt = $pdo->prepare('SELECT api_key_enc, vendor FROM practitioner_ai_config WHERE practitioner_id = ?');
        $stmt->execute([$practId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $apiKey = phi_decrypt($row['api_key_enc'] ?? '', "ai_config:{$practId}");
            if ($vendor === '') $vendor = $row['vendor'];
        }
    }

    if ($apiKey === '') {
        http_response_code(400);
        echo json_encode(['error' => 'No API key available']);
        exit;
    }

    $models = [];

    switch ($vendor) {
        case 'openai':
            $resp = ai_http_get('https://api.openai.com/v1/models', ['Authorization: Bearer ' . $apiKey]);
            if (isset($resp['data'])) {
                foreach ($resp['data'] as $m) {
                    $id = $m['id'] ?? '';
                    // Surface only chat-capable models
                    if (str_starts_with($id, 'gpt-') || str_starts_with($id, 'o1') || str_starts_with($id, 'o3')) {
                        $models[] = ['id' => $id, 'name' => $id];
                    }
                }
                usort($models, fn($a, $b) => strcmp($a['id'], $b['id']));
            }
            break;

        case 'anthropic':
            // Anthropic model list is stable; return hardcoded current list
            $models = [
                ['id' => 'claude-opus-4-6',              'name' => 'Claude Opus 4.6'],
                ['id' => 'claude-sonnet-4-6',            'name' => 'Claude Sonnet 4.6'],
                ['id' => 'claude-haiku-4-5-20251001',    'name' => 'Claude Haiku 4.5'],
                ['id' => 'claude-opus-4-5',              'name' => 'Claude Opus 4.5'],
                ['id' => 'claude-sonnet-4-5',            'name' => 'Claude Sonnet 4.5'],
            ];
            break;

        case 'google':
            $resp = ai_http_get(
                'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($apiKey),
                []
            );
            if (isset($resp['models'])) {
                foreach ($resp['models'] as $m) {
                    $id = $m['name'] ?? '';
                    if (str_contains($id, 'gemini')) {
                        $short = str_replace('models/', '', $id);
                        $models[] = ['id' => $short, 'name' => $m['displayName'] ?? $short];
                    }
                }
            }
            break;

        case 'cohere':
            $resp = ai_http_get('https://api.cohere.com/v2/models', ['Authorization: Bearer ' . $apiKey]);
            if (isset($resp['models'])) {
                foreach ($resp['models'] as $m) {
                    $id = $m['name'] ?? '';
                    if (!empty($m['endpoints']) && in_array('chat', $m['endpoints'])) {
                        $models[] = ['id' => $id, 'name' => $id];
                    }
                }
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown vendor']);
            exit;
    }

    echo json_encode(['ok' => true, 'models' => $models]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
exit;

// ── HTTP helper ───────────────────────────────────────────────────
function ai_http_get(string $url, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw  = curl_exec($ch);
    if ($raw === false) return [];
    return json_decode($raw, true) ?? [];
}
