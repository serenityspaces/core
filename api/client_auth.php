<?php
/**
 * Serenity Spaces — Client Authentication API
 *
 * POST action=login            — log in as end_user (returns mfa_required:true if TOTP enabled)
 * POST action=verify_mfa       — complete login after TOTP challenge
 * POST action=logout           — log out
 * POST action=change_password  — change password (required on first login)
 * POST action=setup_totp_begin — generate a new TOTP secret, return provisioning URI (authenticated)
 * POST action=setup_totp_confirm — verify code and activate TOTP (authenticated)
 * POST action=disable_totp     — disable TOTP after verifying current password (authenticated)
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/audit.php';
require_once __DIR__ . '/../includes/firewall.php';
require_once __DIR__ . '/../includes/password_policy.php';
require_once __DIR__ . '/../includes/totp.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$ct    = $_SERVER['CONTENT_TYPE'] ?? '';
$input = str_contains($ct, 'application/json')
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : $_POST;

$method = $_SERVER['REQUEST_METHOD'];
$action = $input['action'] ?? ($method === 'GET' ? ($_GET['action'] ?? '') : '');
$pdo    = getDB();

// ── My Practitioners (GET) ────────────────────────────────────────
if ($action === 'my_practitioners' && $method === 'GET') {
    $userId = !empty($_SESSION['end_user_id']) ? (int)$_SESSION['end_user_id'] : 0;
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }

    // Return distinct practitioners this client has a booking with
    $stmt = $pdo->prepare('
        SELECT DISTINCT p.id, p.display_name, p.service_type, p.avatar_path
        FROM bookings b
        JOIN practitioners p ON p.id = b.practitioner_id
        WHERE b.end_user_id = ?
        ORDER BY p.display_name
    ');
    $stmt->execute([$userId]);
    $practitioners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'practitioners' => $practitioners]);
    exit;
}

// ── Login ──────────────────────────────────────────────────────
if ($action === 'login') {
    $ip       = fw_get_ip();
    $email    = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    fw_check($ip);

    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Email and password are required']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, password_hash, display_name, must_change_password, mfa_secret FROM end_users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && $user['password_hash'] !== null && ss_password_verify($password, $user['password_hash'])) {
        fw_clear($ip, $email);

        // Upgrade hash to Argon2id if needed
        if (ss_password_needs_rehash($user['password_hash'])) {
            $pdo->prepare('UPDATE end_users SET password_hash = ? WHERE id = ?')
                ->execute([ss_password_hash($password), (int)$user['id']]);
        }

        // If TOTP is enabled, hold login in pending state and return MFA challenge
        if (!empty($user['mfa_secret'])) {
            session_regenerate_id(true);
            $_SESSION['mfa_pending_eu_id']   = (int)$user['id'];
            $_SESSION['mfa_pending_eu_name'] = $user['display_name'];
            $_SESSION['csrf_token']          = bin2hex(random_bytes(32));
            echo json_encode(['mfa_required' => true]);
            exit;
        }

        session_regenerate_id(true);
        $_SESSION['end_user_id']    = (int)$user['id'];
        $_SESSION['end_user_name']  = $user['display_name'];
        $_SESSION['csrf_token']     = bin2hex(random_bytes(32));
        $_SESSION['last_activity']  = time();
        audit_log($pdo, 'auth.login', ['entity_type' => 'client', 'entity_id' => (int)$user['id']]);
        echo json_encode([
            'ok'                   => true,
            'must_change_password' => (bool)$user['must_change_password'],
            'display_name'         => $user['display_name'],
        ]);
    } else {
        fw_record_failure($ip, $email);
        audit_log($pdo, 'auth.login_failed', ['entity_type' => 'client']);
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password']);
    }
    exit;
}

// ── MFA verification step (after password check) ──────────────
if ($action === 'verify_mfa') {
    if (empty($_SESSION['mfa_pending_eu_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'No MFA session pending']);
        exit;
    }

    $ip   = fw_get_ip();
    $code = preg_replace('/\D/', '', $input['mfa_code'] ?? '');

    $pendingId = (int)$_SESSION['mfa_pending_eu_id'];
    $stmt      = $pdo->prepare('SELECT id, mfa_secret, display_name, must_change_password FROM end_users WHERE id = ? LIMIT 1');
    $stmt->execute([$pendingId]);
    $user = $stmt->fetch();

    if ($user && totp_verify((string)$user['mfa_secret'], $code, $pendingId, 'client')) {
        unset($_SESSION['mfa_pending_eu_id'], $_SESSION['mfa_pending_eu_name']);
        session_regenerate_id(true);
        $_SESSION['end_user_id']   = $pendingId;
        $_SESSION['end_user_name'] = $user['display_name'];
        $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
        $_SESSION['last_activity'] = time();
        audit_log($pdo, 'auth.login', ['entity_type' => 'client', 'entity_id' => $pendingId]);
        echo json_encode([
            'ok'                   => true,
            'must_change_password' => (bool)$user['must_change_password'],
            'display_name'         => $user['display_name'],
        ]);
    } else {
        fw_record_failure($ip);
        http_response_code(401);
        echo json_encode(['error' => 'Incorrect code. Please try again.']);
    }
    exit;
}

// ── Logout ─────────────────────────────────────────────────────
if ($action === 'logout') {
    // Validate CSRF token before destroying session
    $token = $input['csrf_token'] ?? '';
    if (!validate_csrf($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token invalid']);
        exit;
    }
    $userId = $_SESSION['end_user_id'] ?? null;
    audit_log($pdo, 'auth.logout', ['entity_type' => 'client', 'entity_id' => $userId ? (int)$userId : null]);
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;
}

// ── Change password ────────────────────────────────────────────
if ($action === 'change_password') {
    if (empty($_SESSION['end_user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    // CSRF verification — mirrors the practitioner change-password pattern
    $csrfToken = $input['csrf_token'] ?? '';
    if (!validate_csrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token invalid']);
        exit;
    }

    $newPassword     = $input['new_password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';

    if (strlen($newPassword) < 12) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 12 characters']);
        exit;
    }
    if ($newPassword !== $confirmPassword) {
        http_response_code(400);
        echo json_encode(['error' => 'Passwords do not match']);
        exit;
    }

    $hash = ss_password_hash($newPassword);
    $pdo->prepare(
        'UPDATE end_users SET password_hash = ?, must_change_password = 0 WHERE id = ?'
    )->execute([$hash, (int)$_SESSION['end_user_id']]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── TOTP setup — generate secret and provisioning URI ─────────
if ($action === 'setup_totp_begin') {
    if (empty($_SESSION['end_user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }

    $csrfToken = $input['csrf_token'] ?? '';
    if (!validate_csrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token invalid']);
        exit;
    }

    $userId   = (int)$_SESSION['end_user_id'];
    $stmt     = $pdo->prepare('SELECT email, display_name FROM end_users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user     = $stmt->fetch();
    $secret   = totp_generate_secret();
    $account  = $user ? ($user['email'] ?? 'client') : 'client';
    $uri      = totp_provisioning_uri($secret, $account);

    // Store pending secret in session — only persist to DB after confirm
    $_SESSION['totp_pending_secret_eu'] = $secret;

    echo json_encode(['ok' => true, 'secret' => $secret, 'uri' => $uri]);
    exit;
}

// ── TOTP setup — confirm code and activate ────────────────────
if ($action === 'setup_totp_confirm') {
    if (empty($_SESSION['end_user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }

    $csrfToken = $input['csrf_token'] ?? '';
    if (!validate_csrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token invalid']);
        exit;
    }

    $pendingSecret = $_SESSION['totp_pending_secret_eu'] ?? '';
    if ($pendingSecret === '') {
        http_response_code(400);
        echo json_encode(['error' => 'No pending TOTP setup. Please start setup again.']);
        exit;
    }

    $code   = preg_replace('/\D/', '', $input['mfa_code'] ?? '');
    $userId = (int)$_SESSION['end_user_id'];

    if (!totp_verify($pendingSecret, $code, $userId, 'client')) {
        http_response_code(400);
        echo json_encode(['error' => 'Incorrect code. Please try again.']);
        exit;
    }

    // Code verified — activate TOTP
    unset($_SESSION['totp_pending_secret_eu']);
    $pdo->prepare('UPDATE end_users SET mfa_secret = ? WHERE id = ?')
        ->execute([$pendingSecret, $userId]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── TOTP disable ──────────────────────────────────────────────
if ($action === 'disable_totp') {
    if (empty($_SESSION['end_user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }

    $csrfToken = $input['csrf_token'] ?? '';
    if (!validate_csrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token invalid']);
        exit;
    }

    $userId   = (int)$_SESSION['end_user_id'];
    $password = $input['password'] ?? '';

    $stmt = $pdo->prepare('SELECT password_hash FROM end_users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || !ss_password_verify($password, $user['password_hash'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Incorrect password']);
        exit;
    }

    $pdo->prepare('UPDATE end_users SET mfa_secret = NULL WHERE id = ?')->execute([$userId]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
