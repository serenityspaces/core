<?php
/**
 * Serenity Spaces — Media Recommendations Config API
 *
 * GET  ?action=load          — returns current config (keys masked)
 * POST action=save           — saves config; encrypts API keys
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/phi_crypto.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$pdo     = getDB();
$practId = (int)$_SESSION['practitioner_id'];

// ── GET: load config ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['action'] ?? '') !== 'load') {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM practitioner_media_config WHERE practitioner_id = ? LIMIT 1');
        $stmt->execute([$practId]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        $row = null;
    }

    echo json_encode([
        'open_library'           => $row ? (bool)(int)$row['open_library']          : true,
        'google_books_enabled'   => $row ? (bool)(int)$row['google_books_enabled']  : false,
        'google_books_key_set'   => $row ? !empty($row['google_books_key_enc'])      : false,
        'tmdb_enabled'           => $row ? (bool)(int)$row['tmdb_enabled']           : false,
        'tmdb_key_set'           => $row ? !empty($row['tmdb_key_enc'])              : false,
        'amazon_enabled'         => $row ? (bool)(int)$row['amazon_enabled']         : false,
        'amazon_tag'             => $row ? ($row['amazon_tag'] ?? '')                 : '',
        'amazon_access_key_set'  => $row ? !empty($row['amazon_access_key_enc'])     : false,
        'amazon_secret_key_set'  => $row ? !empty($row['amazon_secret_key_enc'])     : false,
        'has_books_source'       => $row ? ((bool)(int)$row['open_library'] || (bool)(int)$row['google_books_enabled']) : true,
        'has_video_source'       => $row ? (bool)(int)$row['tmdb_enabled']           : false,
    ]);
    exit;
}

// ── POST: save config ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    if ($action !== 'save') {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    }

    if (!validate_csrf($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF validation failed']);
        exit;
    }

    $openLibrary        = !empty($input['open_library'])        ? 1 : 0;
    $googleBooksEnabled = !empty($input['google_books_enabled']) ? 1 : 0;
    $googleBooksKey     = trim($input['google_books_key']   ?? '');
    $tmdbEnabled        = !empty($input['tmdb_enabled'])         ? 1 : 0;
    $tmdbKey            = trim($input['tmdb_key']           ?? '');
    $amazonEnabled      = !empty($input['amazon_enabled'])       ? 1 : 0;
    $amazonTag          = trim($input['amazon_tag']         ?? '');
    $amazonAccessKey    = trim($input['amazon_access_key']  ?? '');
    $amazonSecretKey    = trim($input['amazon_secret_key']  ?? '');

    // Load existing row to check if keys already stored
    try {
        $existStmt = $pdo->prepare('SELECT * FROM practitioner_media_config WHERE practitioner_id = ? LIMIT 1');
        $existStmt->execute([$practId]);
        $existing = $existStmt->fetch();
    } catch (PDOException $e) {
        $existing = null;
    }

    // Validate: enabled sources must have keys
    if ($googleBooksEnabled && empty($googleBooksKey) && empty($existing['google_books_key_enc'])) {
        echo json_encode(['ok' => false, 'error' => 'Google Books API key is required when enabled.']);
        exit;
    }
    if ($tmdbEnabled && empty($tmdbKey) && empty($existing['tmdb_key_enc'])) {
        echo json_encode(['ok' => false, 'error' => 'TMDB API key is required when enabled.']);
        exit;
    }
    if ($amazonEnabled) {
        if (empty($amazonTag)) {
            echo json_encode(['ok' => false, 'error' => 'Amazon Associate Tag is required when Amazon is enabled.']);
            exit;
        }
        if (empty($amazonAccessKey) && empty($existing['amazon_access_key_enc'])) {
            echo json_encode(['ok' => false, 'error' => 'Amazon PA API Access Key is required when Amazon is enabled.']);
            exit;
        }
        if (empty($amazonSecretKey) && empty($existing['amazon_secret_key_enc'])) {
            echo json_encode(['ok' => false, 'error' => 'Amazon PA API Secret Key is required when Amazon is enabled.']);
            exit;
        }
    }

    // Encrypt keys; preserve existing if no new value provided
    $ctx = "media_config:{$practId}";
    $googleBooksKeyEnc  = !empty($googleBooksKey)
        ? phi_encrypt($googleBooksKey,  $ctx)
        : ($googleBooksEnabled ? ($existing['google_books_key_enc']  ?? null) : null);
    $tmdbKeyEnc         = !empty($tmdbKey)
        ? phi_encrypt($tmdbKey,         $ctx)
        : ($tmdbEnabled         ? ($existing['tmdb_key_enc']          ?? null) : null);
    $amazonAccessKeyEnc = !empty($amazonAccessKey)
        ? phi_encrypt($amazonAccessKey, $ctx)
        : ($amazonEnabled       ? ($existing['amazon_access_key_enc'] ?? null) : null);
    $amazonSecretKeyEnc = !empty($amazonSecretKey)
        ? phi_encrypt($amazonSecretKey, $ctx)
        : ($amazonEnabled       ? ($existing['amazon_secret_key_enc'] ?? null) : null);

    // Clear keys for disabled sources
    if (!$googleBooksEnabled) $googleBooksKeyEnc  = null;
    if (!$tmdbEnabled)        $tmdbKeyEnc          = null;
    if (!$amazonEnabled)      { $amazonAccessKeyEnc = null; $amazonSecretKeyEnc = null; $amazonTag = ''; }

    try {
        $pdo->prepare(
            'INSERT INTO practitioner_media_config
             (practitioner_id, open_library, google_books_enabled, google_books_key_enc,
              tmdb_enabled, tmdb_key_enc, amazon_enabled, amazon_tag,
              amazon_access_key_enc, amazon_secret_key_enc)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               open_library=VALUES(open_library),
               google_books_enabled=VALUES(google_books_enabled),
               google_books_key_enc=VALUES(google_books_key_enc),
               tmdb_enabled=VALUES(tmdb_enabled),
               tmdb_key_enc=VALUES(tmdb_key_enc),
               amazon_enabled=VALUES(amazon_enabled),
               amazon_tag=VALUES(amazon_tag),
               amazon_access_key_enc=VALUES(amazon_access_key_enc),
               amazon_secret_key_enc=VALUES(amazon_secret_key_enc),
               updated_at=NOW()'
        )->execute([
            $practId, $openLibrary, $googleBooksEnabled, $googleBooksKeyEnc,
            $tmdbEnabled, $tmdbKeyEnc, $amazonEnabled,
            $amazonTag ?: null, $amazonAccessKeyEnc, $amazonSecretKeyEnc,
        ]);
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'Database error saving configuration.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
