<?php
/**
 * Serenity Spaces — Practitioner Password Reset
 * Step 2: Validate token and set new password
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();

if (!empty($_SESSION['practitioner_id'])) {
    header('Location: /dashboard.php');
    exit;
}

require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/password_policy.php';
requireSetup();

$token     = trim($_GET['token'] ?? '');
$email     = strtolower(trim($_GET['email'] ?? ''));
$error     = '';
$tokenRow  = null;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Validate token on every load
if ($token !== '' && $email !== '') {
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, token_hash FROM password_resets
         WHERE email = ? AND expires_at > NOW() AND used_at IS NULL
         ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if ($row && password_verify($token, $row['token_hash'])) {
        $tokenRow = $row;
    }
}

if ($tokenRow === null) {
    $error = 'invalid_or_expired';
}

$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenRow !== null) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Security token mismatch. Please refresh and try again.';
    } else {
        $pdo         = getDB();
        $newPassword = $_POST['new_password']     ?? '';
        $confirm     = $_POST['confirm_password'] ?? '';

        if (($pwErr = validate_practitioner_password($newPassword)) !== null) {
            $error = $pwErr;
        } elseif ($newPassword !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            // Mark token as used immediately (single-use)
            $pdo->prepare(
                'UPDATE password_resets SET used_at = NOW() WHERE id = ?'
            )->execute([$tokenRow['id']]);

            // Update practitioner password
            $hash = ss_password_hash($newPassword);
            $pdo->prepare(
                'UPDATE practitioners SET password_hash = ? WHERE email = ?'
            )->execute([$hash, $email]);

            $done = true;
        }
    }
}

$appTheme = getAppTheme();
$appLogo  = getAppLogo();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Set New Password — Serenity Spaces</title>
  <link rel="stylesheet" href="/assets/css/main.css">
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
    :root {
      --bg:         #0f0e17;
      --surface:    #1a1828;
      --surface2:   #211f33;
      --border:     #2e2b45;
      --accent:     #7c6af7;
      --text:       #e0dff5;
      --text-muted: #8f8daa;
    }
    html, body { overflow-y: auto; height: auto; }
    body.auth-page {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--bg);
    }
    .auth-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 36px 40px;
      width: 100%;
      max-width: 420px;
    }
    .app-logo-img { max-height: 40px; width: auto; display: block; margin: 0 auto 20px; }
    h1 { font-size: 20px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
    .subtitle { font-size: 13px; color: var(--text-muted); margin: 0 0 22px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 13px; font-weight: 500; color: var(--text-muted); margin-bottom: 6px; }
    .form-group input {
      width: 100%; background: var(--surface2); border: 1px solid var(--border);
      border-radius: 10px; padding: 11px 14px; font-size: 14px; color: var(--text);
      outline: none; transition: border-color 0.2s; font-family: inherit; box-sizing: border-box;
    }
    .form-group input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(124,106,247,0.12); }
    .btn-submit {
      width: 100%; background: var(--accent); color: #fff; border: none;
      border-radius: 10px; padding: 12px; font-size: 15px; font-weight: 600;
      cursor: pointer; transition: opacity 0.2s; font-family: inherit; margin-top: 4px;
    }
    .btn-submit:hover { opacity: 0.88; }
    .alert-error {
      background: rgba(239,83,80,0.1); border: 1px solid rgba(239,83,80,0.25);
      border-radius: 10px; color: #ef9a9a; font-size: 13px; padding: 11px 14px; margin-bottom: 18px;
    }
    .alert-success {
      background: rgba(62,207,142,0.08); border: 1px solid rgba(62,207,142,0.25);
      border-radius: 10px; color: #3ecf8e; font-size: 13px; padding: 16px 18px; line-height: 1.6;
    }
    .auth-footer { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--border); text-align: center; font-size: 13px; color: var(--text-muted); }
    .auth-footer a { color: var(--accent); text-decoration: none; }
    .auth-footer a:hover { text-decoration: underline; }
  </style>
</head>
<body class="auth-page">

<div class="auth-card">
  <?php if ($appLogo): ?>
    <img src="<?= htmlspecialchars($appLogo) ?>" alt="Logo" class="app-logo-img">
  <?php endif; ?>

  <?php if ($error === 'invalid_or_expired'): ?>
    <h1>Link expired</h1>
    <p class="subtitle">This password reset link is invalid or has already been used.</p>
    <div class="auth-footer">
      <a href="/forgot_password.php">Request a new link</a> &nbsp;·&nbsp; <a href="/login.php">Sign in</a>
    </div>

  <?php elseif ($done): ?>
    <div class="alert-success">
      <strong>Password updated.</strong><br><br>
      Your password has been changed successfully. You can now sign in with your new password.
    </div>
    <div class="auth-footer">
      <a href="/login.php">Sign in</a>
    </div>

  <?php else: ?>
    <h1>Set new password</h1>
    <p class="subtitle">Choose a strong password for your account.</p>

    <?php if ($error && $error !== 'invalid_or_expired'): ?>
      <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

      <div class="form-group">
        <label for="new_password">New password</label>
        <input type="password" id="reset-password" name="new_password"
               placeholder="••••••••" required autocomplete="new-password">
        <?= password_meter('reset-password', true) ?>
      </div>

      <div class="form-group">
        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password"
               placeholder="••••••••" required autocomplete="new-password">
      </div>

      <button type="submit" class="btn-submit">Set new password</button>
    </form>

    <div class="auth-footer">
      <a href="/login.php">Cancel</a>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
