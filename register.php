<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();

// Already logged in
if (!empty($_SESSION['practitioner_id'])) {
    header('Location: /dashboard.php');
    exit;
}

require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
requireSetup();
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/includes/firewall.php';

// Check if registrations are enabled
if (getSetting('registrations_enabled', '1') !== '1') {
    header('Location: /login.php');
    exit;
}

// Firewall check — rate-limit registration attempts by IP
$_fwIp = fw_get_ip();
fw_check($_fwIp);

$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken  = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Security token mismatch. Please refresh and try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ($email === '' || $password === '' || $confirm === '') {
            $error = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (($pwErr = validate_practitioner_password($password)) !== null) {
            $error = $pwErr;
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $pdo = getDB();
            // Check for duplicate
            $stmt = $pdo->prepare('SELECT id FROM practitioners WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                // Use generic message to prevent email enumeration
                fw_record_failure($_fwIp, $email);
                $error = 'Unable to create an account with that email address.';
            } else {
                $requireApproval = getSetting('require_practitioner_approval', '0') === '1';
                $acctStatus = $requireApproval ? 'pending' : 'active';
                $hash = ss_password_hash($password);
                $stmt = $pdo->prepare('INSERT INTO practitioners (email, password_hash, account_status) VALUES (?, ?, ?)');
                $stmt->execute([$email, $hash, $acctStatus]);
                $id = $pdo->lastInsertId();

                if ($requireApproval) {
                    // Send pending notification email
                    require_once __DIR__ . '/includes/mailer.php';
                    require_once __DIR__ . '/includes/email_templates.php';
                    send_template_email($pdo, 'practitioner_pending', 'Account Pending Approval', [
                        'practitioner_name' => $email,
                    ], $email, $email);
                    header('Location: /login.php?pending=1');
                    exit;
                }

                session_regenerate_id(true);
                $_SESSION['practitioner_id'] = $id;
                $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
                $_SESSION['last_activity']   = time();
                header('Location: /profile.php?wizard=1');
                exit;
            }
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
  <title>Create Account — Serenity Spaces</title>
  <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body class="auth-page theme-<?= $appTheme ?>">

<div class="auth-card glass-panel">
  <?php if ($appLogo): ?>
    <div style="text-align:center;margin-bottom:16px;">
      <img src="<?= htmlspecialchars($appLogo) ?>" alt="Logo" class="app-logo-img">
    </div>
  <?php endif; ?>
  <h1>Create account</h1>
  <p class="subtitle">Set up your practitioner profile</p>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

    <div class="form-group">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email"
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
             placeholder="you@example.com" required autofocus>
    </div>

    <div class="form-group">
      <label for="password">Password</label>
      <input type="password" id="reg-password" name="password" placeholder="••••••••" required autocomplete="new-password">
      <?= password_meter('reg-password', true) ?>
    </div>

    <div class="form-group">
      <label for="confirm_password">Confirm password</label>
      <input type="password" id="confirm_password" name="confirm_password" placeholder="••••••••" required autocomplete="new-password">
    </div>

    <button type="submit" class="btn btn-primary">Create Account</button>
  </form>

  <div class="auth-footer">
    Already have an account? <a href="/login.php">Sign in</a>
  </div>
</div>

</body>
</html>
