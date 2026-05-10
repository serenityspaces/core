<?php
/**
 * Serenity Spaces — Practitioner Password Reset
 * Step 1: Enter email address to receive reset link
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();

if (!empty($_SESSION['practitioner_id'])) {
    header('Location: /dashboard.php');
    exit;
}

require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/firewall.php';
require_once __DIR__ . '/includes/mailer.php';
requireSetup();

$_fwIp = fw_get_ip();
fw_check($_fwIp);

$submitted = false;
$error     = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Security token mismatch. Please refresh and try again.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $pdo = getDB();

            // Look up practitioner — always show "sent" message to prevent enumeration
            $stmt = $pdo->prepare('SELECT id, display_name FROM practitioners WHERE email = ? AND account_status = ? LIMIT 1');
            $stmt->execute([$email, 'active']);
            $row = $stmt->fetch();

            if ($row) {
                // Clean up any expired tokens for this email
                $pdo->prepare('DELETE FROM password_resets WHERE email = ? AND (expires_at < NOW() OR used_at IS NOT NULL)')->execute([$email]);

                // Generate token: random 32 bytes → hex string as the URL token
                $rawToken  = bin2hex(random_bytes(32));
                $tokenHash = password_hash($rawToken, PASSWORD_BCRYPT, ['cost' => 14]);
                $expiresAt = (new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

                $pdo->prepare(
                    'INSERT INTO password_resets (email, token_hash, expires_at) VALUES (?, ?, ?)'
                )->execute([$email, $tokenHash, $expiresAt]);

                // Build reset URL
                $appUrl   = rtrim(getSetting('app_url', 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
                $resetUrl = $appUrl . '/reset_password.php?token=' . urlencode($rawToken) . '&email=' . urlencode($email);

                $name     = $row['display_name'] ?: $email;
                $htmlBody = '
<p>Hello ' . htmlspecialchars($name) . ',</p>
<p>A password reset was requested for your Serenity Spaces account.</p>
<p><a href="' . htmlspecialchars($resetUrl) . '" style="background:#7c6af7;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;display:inline-block;">Reset my password</a></p>
<p>This link expires in 1 hour. If you did not request this, you can safely ignore this email.</p>
<p style="font-size:12px;color:#888;">If the button does not work, copy and paste this URL into your browser:<br>' . htmlspecialchars($resetUrl) . '</p>';

                sendEmail($email, $name, 'Reset your Serenity Spaces password', $htmlBody);
            }

            // Always show submitted state regardless of whether email matched
            fw_record_failure($_fwIp); // mild rate-limit on reset requests
            $submitted = true;
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
  <title>Reset Password — Serenity Spaces</title>
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
      position: relative;
      z-index: 1;
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

  <?php if ($submitted): ?>
    <div class="alert-success">
      <strong>Check your email.</strong><br><br>
      If an account exists for that address, we've sent a password reset link.
      The link expires in 1 hour.
    </div>
    <div class="auth-footer">
      <a href="/login.php">Return to sign in</a>
    </div>

  <?php else: ?>
    <h1>Reset password</h1>
    <p class="subtitle">Enter your practitioner email address and we'll send a reset link.</p>

    <?php if ($error): ?>
      <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

      <div class="form-group">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               placeholder="you@example.com" required autofocus>
      </div>

      <button type="submit" class="btn-submit">Send reset link</button>
    </form>

    <div class="auth-footer">
      <a href="/login.php">Back to sign in</a>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
