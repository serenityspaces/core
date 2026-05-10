<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();

// Already logged in — send to appropriate portal
if (!empty($_SESSION['practitioner_id'])) {
    header('Location: /dashboard.php');
    exit;
}
if (!empty($_SESSION['end_user_id'])) {
    header('Location: /client_portal.php');
    exit;
}

require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/db/audit.php';
require_once __DIR__ . '/includes/firewall.php';
require_once __DIR__ . '/includes/totp.php';
requireSetup();

// Check if this IP is currently blocked before doing anything else
$_fwIp = fw_get_ip();
fw_check($_fwIp);

$error        = '';
$notice       = '';
$attemptCount = 0;
if (isset($_GET['pending'])) {
    $notice = 'Your account has been created and is pending admin approval. You will receive an email once reviewed.';
}
// Which tab to activate on load
$defaultTab      = ($_GET['tab'] ?? '') === 'client' ? 'client' : 'practitioner';
$showMfa         = isset($_GET['mfa']) && $_GET['mfa'] !== 'client' && !empty($_SESSION['mfa_pending_id']);
$showClientMfa   = isset($_GET['mfa']) && $_GET['mfa'] === 'client' && !empty($_SESSION['mfa_pending_eu_id']);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── MFA verification step ─────────────────────────────────────────
if ($showMfa && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mfa_code'])) {
    require_once __DIR__ . '/includes/totp.php';
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Security token mismatch.';
    } else {
        $pendingId = (int)$_SESSION['mfa_pending_id'];
        $pdo       = getDB();
        $stmt      = $pdo->prepare('SELECT mfa_secret, display_name FROM practitioners WHERE id = ? LIMIT 1');
        $stmt->execute([$pendingId]);
        $practRow  = $stmt->fetch();
        $code      = preg_replace('/\D/', '', $_POST['mfa_code'] ?? '');
        if ($practRow && totp_verify($practRow['mfa_secret'], $code, $pendingId)) {
            // MFA passed — complete login
            unset($_SESSION['mfa_pending_id'], $_SESSION['mfa_pending_name']);
            session_regenerate_id(true);
            $_SESSION['practitioner_id'] = $pendingId;
            $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
            $_SESSION['last_activity']   = time();
            audit_log($pdo, 'auth.login', ['practitioner_id' => $pendingId, 'entity_type' => 'practitioner', 'entity_id' => $pendingId]);
            $redirect = empty($practRow['display_name']) ? '/profile.php' : '/dashboard.php';
            header('Location: ' . $redirect);
            exit;
        } else {
            fw_record_failure($_fwIp);
            $error = 'Incorrect code. Please try again.';
        }
    }
}

// Practitioner login is a standard POST; client login is handled via JS + api/client_auth.php
if (!$showMfa && !$showClientMfa && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Security token mismatch. Please refresh and try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Please enter your email and password.';
        } else {
            $pdo  = getDB();
            $stmt = $pdo->prepare('SELECT id, password_hash, display_name, account_status, mfa_secret FROM practitioners WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $row = $stmt->fetch();

            if ($row && ss_password_verify($password, $row['password_hash'])) {
                $acctStatus = $row['account_status'] ?? 'active';
                if ($acctStatus === 'pending') {
                    $error = 'Your account is pending admin approval. You will receive an email once it has been reviewed.';
                } elseif ($acctStatus === 'rejected') {
                    $error = 'Your account application was not approved. Please contact the administrator for more information.';
                } else {
                fw_clear($_fwIp, $email);
                // Check if MFA is required
                if (!empty($row['mfa_secret'])) {
                    // Password correct — hold in MFA pending state, do not grant session yet
                    session_regenerate_id(true);
                    $_SESSION['mfa_pending_id']      = (int)$row['id'];
                    $_SESSION['mfa_pending_name']    = $row['display_name'];
                    $_SESSION['csrf_token']          = bin2hex(random_bytes(32));
                    header('Location: /login.php?mfa=1');
                    exit;
                }
                session_regenerate_id(true);
                $_SESSION['practitioner_id'] = $row['id'];
                $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
                $_SESSION['last_activity']   = time();
                // Upgrade hash algorithm if needed (e.g. bcrypt → argon2id)
                if (ss_password_needs_rehash($row['password_hash'])) {
                    $pdo->prepare('UPDATE practitioners SET password_hash = ? WHERE id = ?')
                        ->execute([ss_password_hash($password), (int)$row['id']]);
                }
                audit_log($pdo, 'auth.login', ['practitioner_id' => (int)$row['id'], 'entity_type' => 'practitioner', 'entity_id' => (int)$row['id']]);

                // MFA enforcement check — redirect to setup if enforcement active and grace elapsed
                if (empty($row['mfa_secret']) && getSetting('enforce_practitioner_mfa', '0') === '1') {
                    $enabledAt = getSetting('mfa_enforce_enabled_at', '');
                    $graceDays = max(1, (int)getSetting('mfa_grace_days', '7'));
                    $graceExpired = true;
                    if ($enabledAt) {
                        $deadline = (new DateTime($enabledAt))->modify("+{$graceDays} days");
                        $graceExpired = new DateTime() >= $deadline;
                    }
                    if ($graceExpired) {
                        $_SESSION['mfa_setup_required'] = true;
                        header('Location: /profile.php?section=mfa&require=1');
                        exit;
                    }
                    // Within grace: set a session flag so dashboard can show a banner
                    $_SESSION['mfa_setup_nudge'] = true;
                }

                $redirect = empty($row['display_name']) ? '/profile.php' : '/dashboard.php';
                header('Location: ' . $redirect);
                exit;
                }
            } else {
                fw_record_failure($_fwIp, $email);
                audit_log($pdo, 'auth.login_failed', ['entity_type' => 'practitioner']);
                $attemptCount = fw_recent_count($_fwIp, 15);
                $error = 'Invalid email or password.';
                if ($attemptCount >= 3) {
                    $remaining = max(0, 5 - $attemptCount);
                    $error .= $remaining > 0
                        ? " ({$remaining} attempt" . ($remaining === 1 ? '' : 's') . " remaining before delay)"
                        : ' (access will be temporarily restricted after further failures)';
                }
            }
        }
    }
    // POST means practitioner tab was submitted
    $defaultTab = 'practitioner';
}

$appTheme = getAppTheme();
$appLogo  = getAppLogo();
$regsEnabled = getSetting('registrations_enabled', '1') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In — Serenity Spaces</title>
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
      position: relative;
      overflow: hidden;
    }

    body.auth-page::before {
      content: '';
      position: fixed;
      top: -10%;
      left: 50%;
      transform: translateX(-50%);
      width: 700px;
      height: 500px;
      background: radial-gradient(ellipse, rgba(124,106,247,0.09) 0%, transparent 65%);
      pointer-events: none;
    }
    body.auth-page::after {
      content: '';
      position: fixed;
      bottom: -5%;
      right: -5%;
      width: 400px;
      height: 400px;
      background: radial-gradient(ellipse, rgba(100,181,246,0.04) 0%, transparent 70%);
      pointer-events: none;
    }

    .auth-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 36px 40px 36px;
      width: 100%;
      max-width: 420px;
      position: relative;
      z-index: 1;
      animation: cardReveal 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
    }

    @keyframes cardReveal {
      from { opacity: 0; transform: translateY(16px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      20%      { transform: translateX(-7px); }
      40%      { transform: translateX(7px); }
      60%      { transform: translateX(-5px); }
      80%      { transform: translateX(5px); }
    }

    .auth-card.shake { animation: shake 0.4s ease; }

    .app-logo-img {
      max-height: 40px;
      width: auto;
    }

    /* ── Tab switcher ── */
    .login-tabs {
      display: flex;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 4px;
      margin-bottom: 28px;
      gap: 4px;
    }

    .login-tab {
      flex: 1;
      padding: 9px 12px;
      border: none;
      background: transparent;
      border-radius: 9px;
      font-size: 13px;
      font-weight: 600;
      color: var(--text-muted);
      cursor: pointer;
      transition: background 0.2s, color 0.2s;
      font-family: inherit;
      letter-spacing: 0.1px;
    }

    .login-tab.active {
      background: var(--accent);
      color: #fff;
    }

    .login-tab:not(.active):hover {
      background: rgba(124,106,247,0.08);
      color: var(--text);
    }

    /* ── Panels ── */
    .login-panel { display: none; }
    .login-panel.active { display: block; }

    .panel-heading {
      margin-bottom: 22px;
    }

    .panel-heading h1 {
      font-size: 20px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 4px;
      letter-spacing: -0.3px;
    }

    .panel-heading .subtitle {
      font-size: 13px;
      color: var(--text-muted);
      margin: 0;
    }

    /* ── Form elements ── */
    .form-group {
      margin-bottom: 16px;
    }

    .form-group label {
      display: block;
      font-size: 13px;
      font-weight: 500;
      color: var(--text-muted);
      margin-bottom: 6px;
    }

    .form-group input {
      width: 100%;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 11px 14px;
      font-size: 14px;
      color: var(--text);
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
      font-family: inherit;
      box-sizing: border-box;
    }

    .form-group input:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(124,106,247,0.12);
    }

    .form-group input::placeholder {
      color: rgba(143,141,170,0.45);
    }

    .btn-signin {
      width: 100%;
      background: var(--accent);
      color: #fff;
      border: none;
      border-radius: 10px;
      padding: 12px;
      font-size: 15px;
      font-weight: 600;
      cursor: pointer;
      transition: opacity 0.2s, transform 0.1s;
      font-family: inherit;
      margin-top: 4px;
    }

    .btn-signin:hover  { opacity: 0.88; }
    .btn-signin:active { transform: scale(0.98); }
    .btn-signin:disabled { opacity: 0.5; cursor: not-allowed; }

    .alert-error {
      background: rgba(239,83,80,0.1);
      border: 1px solid rgba(239,83,80,0.25);
      border-radius: 10px;
      color: #ef9a9a;
      font-size: 13px;
      padding: 11px 14px;
      margin-bottom: 18px;
      line-height: 1.5;
    }

    .auth-footer {
      margin-top: 22px;
      padding-top: 18px;
      border-top: 1px solid var(--border);
      text-align: center;
      font-size: 13px;
      color: var(--text-muted);
      line-height: 1.6;
    }

    .auth-footer a {
      color: var(--accent);
      text-decoration: none;
    }
    .auth-footer a:hover { text-decoration: underline; }
  </style>
</head>
<body class="auth-page theme-<?= htmlspecialchars($appTheme) ?>">

<div class="auth-card" id="loginCard">
  <?php if ($appLogo): ?>
    <div style="text-align:center;margin-bottom:20px;">
      <img src="<?= htmlspecialchars($appLogo) ?>" alt="Logo" class="app-logo-img">
    </div>
  <?php endif; ?>

  <?php if ($showMfa): ?>
  <!-- ── MFA verification panel ── -->
  <div class="panel-heading">
    <h1>Two-step verification</h1>
    <p class="subtitle">Enter the 6-digit code from your authenticator app.</p>
  </div>
  <?php if ($error): ?>
    <div class="alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="post" action="/login.php?mfa=1" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <div class="form-group">
      <label for="mfa-code">Authentication code</label>
      <input type="text" id="mfa-code" name="mfa_code"
             inputmode="numeric" pattern="\d{6}" maxlength="6"
             placeholder="000000" autocomplete="one-time-code"
             style="letter-spacing:3px;font-size:20px;text-align:center;"
             required autofocus>
    </div>
    <button type="submit" class="btn-signin">Verify</button>
  </form>
  <div class="auth-footer">
    <a href="/login.php">Use a different account</a>
  </div>
  <?php else: ?>

  <!-- Tab switcher -->
  <div class="login-tabs" role="tablist">
    <button class="login-tab" id="tab-practitioner" role="tab"
            aria-controls="panel-practitioner" aria-selected="false"
            data-tab="practitioner">Practitioner</button>
    <button class="login-tab" id="tab-client" role="tab"
            aria-controls="panel-client" aria-selected="false"
            data-tab="client">Client</button>
  </div>

  <!-- ── Practitioner panel ── -->
  <div class="login-panel" id="panel-practitioner" role="tabpanel">
    <div class="panel-heading">
      <h1>Welcome back</h1>
      <p class="subtitle">Sign in to your practitioner account</p>
    </div>

    <?php if ($notice): ?>
      <div style="background:rgba(124,106,247,0.12);border:1px solid rgba(124,106,247,0.3);border-radius:8px;padding:10px 14px;font-size:13px;color:#c4b8ff;margin-bottom:14px;"><?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

      <div class="form-group">
        <label for="p-email">Email address</label>
        <input type="email" id="p-email" name="email"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               placeholder="you@example.com" required autocomplete="email">
      </div>

      <div class="form-group">
        <label for="p-password">Password</label>
        <input type="password" id="p-password" name="password"
               placeholder="••••••••" required autocomplete="current-password">
      </div>

      <button type="submit" class="btn-signin">Sign In</button>
    </form>

    <div class="auth-footer">
      <a href="/forgot_password.php">Forgot your password?</a>
    </div>
    <?php if ($regsEnabled): ?>
    <div class="auth-footer" style="border-top:none;margin-top:8px;padding-top:0;">
      Don't have an account? <a href="/register.php">Create one</a>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Client panel ── -->
  <div class="login-panel" id="panel-client" role="tabpanel">
    <div id="client-login-step">
      <div class="panel-heading">
        <h1>Welcome back</h1>
        <p class="subtitle">Sign in to your client account</p>
      </div>

      <div id="client-error" class="alert-error" style="display:none;"></div>

      <div class="form-group">
        <label for="c-email">Email address</label>
        <input type="email" id="c-email" placeholder="you@example.com"
               autocomplete="email">
      </div>

      <div class="form-group">
        <label for="c-password">Password</label>
        <input type="password" id="c-password" placeholder="••••••••"
               autocomplete="current-password">
      </div>

      <button type="button" class="btn-signin" id="clientSignInBtn">
        Sign In
      </button>

      <div class="auth-footer">
        Need help accessing your account?<br>
        Please reach out to your practitioner directly.
      </div>
      <div class="auth-footer" style="margin-top:4px;">
        <a href="/privacy.php">Privacy Notice</a>
      </div>
    </div>

    <!-- Client MFA challenge — shown after successful password check -->
    <div id="client-mfa-step" style="display:none;">
      <div class="panel-heading">
        <h1>Two-step verification</h1>
        <p class="subtitle">Enter the 6-digit code from your authenticator app.</p>
      </div>
      <div id="client-mfa-error" class="alert-error" style="display:none;"></div>
      <div class="form-group">
        <label for="c-mfa-code">Authentication code</label>
        <input type="text" id="c-mfa-code"
               inputmode="numeric" pattern="\d{6}" maxlength="6"
               placeholder="000000" autocomplete="one-time-code"
               style="letter-spacing:3px;font-size:20px;text-align:center;">
      </div>
      <button type="button" class="btn-signin" id="clientMfaBtn">
        Verify
      </button>
      <div class="auth-footer">
        <a href="#" id="client-login-back">Use a different account</a>
      </div>
    </div>
  </div>

  <?php endif; // end !showMfa ?>
</div>

<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
  const card = document.getElementById('loginCard');

  function switchTab(tab) {
    document.querySelectorAll('.login-tab').forEach(function(btn) {
      const isActive = btn.id === 'tab-' + tab;
      btn.classList.toggle('active', isActive);
      btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    document.querySelectorAll('.login-panel').forEach(function(panel) {
      panel.classList.toggle('active', panel.id === 'panel-' + tab);
    });
    // Focus first input in newly active panel
    const panel = document.getElementById('panel-' + tab);
    const firstInput = panel && panel.querySelector('input');
    if (firstInput) firstInput.focus();
  }

  // Tab switcher — delegated from [data-tab] buttons
  document.querySelector('.login-tabs')?.addEventListener('click', function(e) {
      const btn = e.target.closest('[data-tab]');
      if (btn) switchTab(btn.dataset.tab);
  });

  // Client auth buttons
  document.getElementById('clientSignInBtn')?.addEventListener('click', doClientLogin);
  document.getElementById('clientMfaBtn')?.addEventListener('click', doClientMfaVerify);
  document.getElementById('client-login-back')?.addEventListener('click', function(e) {
      e.preventDefault();
      showClientLoginStep();
  });

  // Initialise to server-determined default
  switchTab(<?= json_encode($defaultTab) ?>);

  // Allow Enter in client login step to submit
  document.getElementById('panel-client').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      if (document.getElementById('client-mfa-step').style.display !== 'none') {
        doClientMfaVerify();
      } else {
        doClientLogin();
      }
    }
  });

  function showClientError(msg) {
    const box = document.getElementById('client-error');
    box.textContent = msg;
    box.style.display = 'block';
    card.classList.remove('shake');
    void card.offsetWidth;
    card.classList.add('shake');
  }

  function showClientMfaError(msg) {
    const box = document.getElementById('client-mfa-error');
    box.textContent = msg;
    box.style.display = 'block';
    card.classList.remove('shake');
    void card.offsetWidth;
    card.classList.add('shake');
  }

  function showClientLoginStep() {
    document.getElementById('client-login-step').style.display = '';
    document.getElementById('client-mfa-step').style.display   = 'none';
    document.getElementById('c-email').focus();
  }

  function showClientMfaStep() {
    document.getElementById('client-login-step').style.display = 'none';
    document.getElementById('client-mfa-step').style.display   = '';
    document.getElementById('c-mfa-code').value = '';
    document.getElementById('c-mfa-code').focus();
  }

  function handleLoginSuccess(data) {
    const returnUrl = new URLSearchParams(window.location.search).get('return');
    if (data.must_change_password) {
      window.location.href = '/client_portal.php?must_change=1';
    } else if (returnUrl && returnUrl.startsWith('/')) {
      window.location.href = returnUrl;
    } else {
      window.location.href = '/client_portal.php';
    }
  }

  async function doClientLogin() {
    const email    = document.getElementById('c-email').value.trim();
    const password = document.getElementById('c-password').value;
    const btn      = document.getElementById('clientSignInBtn');

    if (!email || !password) {
      showClientError('Please enter your email and password.');
      return;
    }

    btn.disabled    = true;
    btn.textContent = 'Signing in\u2026';
    document.getElementById('client-error').style.display = 'none';

    try {
      const res  = await fetch('/api/client_auth.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'login', email, password }),
      });
      const data = await res.json();

      if (res.ok && data.mfa_required) {
        // Password correct but TOTP required — show MFA step
        btn.disabled    = false;
        btn.textContent = 'Sign In';
        showClientMfaStep();
      } else if (res.ok && data.ok) {
        handleLoginSuccess(data);
      } else {
        showClientError(data.error || 'Unable to sign in. Please try again.');
        btn.disabled    = false;
        btn.textContent = 'Sign In';
      }
    } catch (err) {
      showClientError('Connection error. Please check your internet and try again.');
      btn.disabled    = false;
      btn.textContent = 'Sign In';
    }
  }

  async function doClientMfaVerify() {
    const code = document.getElementById('c-mfa-code').value.replace(/\D/g, '');
    const btn  = document.getElementById('clientMfaBtn');

    if (code.length !== 6) {
      showClientMfaError('Please enter the 6-digit code from your authenticator app.');
      return;
    }

    btn.disabled    = true;
    btn.textContent = 'Verifying\u2026';
    document.getElementById('client-mfa-error').style.display = 'none';

    try {
      const res  = await fetch('/api/client_auth.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'verify_mfa', mfa_code: code }),
      });
      const data = await res.json();

      if (res.ok && data.ok) {
        handleLoginSuccess(data);
      } else {
        showClientMfaError(data.error || 'Verification failed. Please try again.');
        btn.disabled    = false;
        btn.textContent = 'Verify';
      }
    } catch (err) {
      showClientMfaError('Connection error. Please check your internet and try again.');
      btn.disabled    = false;
      btn.textContent = 'Verify';
    }
  }

  <?php if ($showClientMfa): ?>
  // Server-side: MFA pending from previous page load — show MFA step immediately
  document.addEventListener('DOMContentLoaded', function() {
    switchTab('client');
    showClientMfaStep();
  });
  <?php endif; ?>
</script>

<div id="cookie-notice" style="
  display:none;position:fixed;bottom:20px;left:50%;transform:translateX(-50%);
  background:#1a1828;border:1px solid rgba(124,106,247,0.25);border-radius:10px;
  padding:12px 20px;max-width:540px;width:calc(100% - 40px);
  display:flex;align-items:center;gap:14px;z-index:9999;
  box-shadow:0 4px 24px rgba(0,0,0,0.4);font-size:13px;color:rgba(220,216,255,0.85);">
  <span style="flex:1">This site uses only essential session cookies for login and security. No tracking or advertising. <a href="/privacy.php#cookies" style="color:#a78bfa;text-decoration:underline;text-underline-offset:2px;">Learn more</a></span>
  <button id="cookie-ack-btn"
    style="background:#7c6af7;color:#fff;border:none;border-radius:6px;padding:6px 14px;cursor:pointer;font-size:12px;white-space:nowrap;flex-shrink:0;">Got it</button>
</div>
<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
  if (!document.cookie.split('; ').some(function(c){ return c.startsWith('ss_cookie_consent='); })) {
    document.getElementById('cookie-notice').style.display = 'flex';
  }
  document.getElementById('cookie-ack-btn')?.addEventListener('click', function() {
      document.cookie = 'ss_cookie_consent=1; max-age=31536000; path=/; SameSite=Strict';
      document.getElementById('cookie-notice').style.display = 'none';
  });
</script>

</body>
</html>
