<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
requireSetup();
require_once __DIR__ . '/includes/geo.php';
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/includes/banners.php';

$pdo = getDB();

// Production Mode gate — bookings are disabled during setup
if (getSetting('production_mode', '0') !== '1') {
    $appTheme = getAppTheme();
    $appLogo  = getAppLogo();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Not Yet Available — Serenity Spaces</title>
      <link rel="stylesheet" href="/assets/css/main.css">
      <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
        body.setup-gate { min-height:100vh; display:flex; align-items:center; justify-content:center; background:var(--bg); }
        .gate-card { background:var(--surface); border:1px solid var(--border); border-radius:18px; padding:40px; max-width:440px; text-align:center; }
        .gate-icon { font-size:40px; margin-bottom:16px; }
        .gate-card h1 { font-size:20px; font-weight:700; color:var(--text); margin:0 0 10px; }
        .gate-card p { font-size:14px; color:var(--text-muted); line-height:1.6; margin:0 0 24px; }
        .gate-card a { color:var(--accent); text-decoration:none; font-size:14px; }
        .gate-card a:hover { text-decoration:underline; }
        .badge-setup { display:inline-block; background:rgba(245,200,66,0.15); color:#f5c842; font-size:12px; font-weight:600; padding:4px 12px; border-radius:20px; letter-spacing:0.04em; margin-bottom:20px; }
      </style>
    </head>
    <body class="auth-page setup-gate">
      <div class="gate-card">
        <?php if ($appLogo): ?>
          <img src="<?= htmlspecialchars($appLogo) ?>" alt="Logo" style="max-height:36px;display:block;margin:0 auto 20px;">
        <?php endif; ?>
        <div class="badge-setup">SETUP MODE</div>
        <h1>Bookings Not Yet Available</h1>
        <p>This platform is currently being configured. Booking will be available once the administrator has enabled Production Mode.</p>
        <a href="/">Return to home</a>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$practId = (int)($_GET['practitioner'] ?? 0);
if (!$practId) {
    header('Location: /index.php');
    exit;
}

// Load practitioner
$stmt = $pdo->prepare(
    'SELECT id, display_name, avatar_path, about_work, practice_types, timezone, service_type, accepting_new_clients
     FROM practitioners WHERE id = ? AND display_name IS NOT NULL LIMIT 1'
);
$stmt->execute([$practId]);
$pract = $stmt->fetch();
if (!$pract) {
    header('Location: /index.php');
    exit;
}

// Determine "not accepting" gate state
$notAccepting = isset($pract['accepting_new_clients']) && (int)$pract['accepting_new_clients'] === 0;
$gateBlocked  = false; // will be true if we should block the booking flow
$gateReason   = '';    // 'login_required' | 'no_prior_session'

if ($notAccepting) {
    $clientId = $_SESSION['end_user_id'] ?? null;
    if (!$clientId) {
        $gateBlocked = true;
        $gateReason  = 'login_required';
    } else {
        // Check for a prior completed session between this client and practitioner
        $chk = $pdo->prepare(
            'SELECT COUNT(*) FROM sessions
             WHERE practitioner_id = ? AND end_user_id = ?'
        );
        $chk->execute([$practId, $clientId]);
        $priorCount = (int)$chk->fetchColumn();
        if ($priorCount === 0) {
            $gateBlocked = true;
            $gateReason  = 'no_prior_session';
        }
    }
}

$practTypes = json_decode($pract['practice_types'] ?? '[]', true) ?: [];
$typeLabels = [
    'life_coaching'            => 'Life Coaching',
    'philosophical_counseling' => 'Philosophical Counseling',
    'licensed_therapy'         => 'Licensed Therapy',
    'peer_support'             => 'Peer Support',
    'mindfulness'              => 'Mindfulness',
    'cbt'                      => 'Cognitive Behavioral (CBT)',
    'dbt'                      => 'Dialectical Behavioral (DBT)',
    'trauma_informed'          => 'Trauma-Informed',
    'grief_support'            => 'Grief Support',
    'addiction_recovery'       => 'Addiction Recovery',
    'pastoral_care'            => 'Pastoral Counseling',
    'spiritual_direction'      => 'Spiritual Direction',
];

$appTheme = getAppTheme();
$appLogo  = getAppLogo();
$appName  = getSetting('app_name', 'Serenity Spaces');

// Load rate and active campaigns for this practitioner
$practRate    = null;
$practCampaigns = [];
try {
    $stmt = $pdo->prepare('SELECT * FROM practitioner_rates WHERE practitioner_id = ? LIMIT 1');
    $stmt->execute([$practId]);
    $practRate = $stmt->fetch() ?: null;

    $stmt = $pdo->prepare("
        SELECT * FROM discount_campaigns
        WHERE practitioner_id = ? AND is_active = 1
          AND valid_from <= CURDATE()
          AND (valid_until IS NULL OR valid_until >= CURDATE())
        ORDER BY discount_pct DESC
    ");
    $stmt->execute([$practId]);
    $practCampaigns = $stmt->fetchAll();
} catch (PDOException $e) { /* tables may not exist on older installs */ }

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl  = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

// Load AI config for this practitioner (for badge + consent step)
$practAiCfg = null;
$aiPlatformEnabled = getSetting('ai_enabled', '0') === '1';
if ($aiPlatformEnabled) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM practitioner_ai_config WHERE practitioner_id = ? AND enabled = 1');
        $stmt->execute([$practId]);
        $practAiCfg = $stmt->fetch() ?: null;
    } catch (PDOException $e) { /* table may not exist on older installs */ }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Book a Session — <?= htmlspecialchars($pract['display_name']) ?></title>
  <link rel="stylesheet" href="/assets/css/main.css">
  <link rel="stylesheet" href="/assets/css/fonts.css">
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
    :root {
      --sky-void: #050810; --sky-deep: #080d1e; --sky-mid: #0d1433;
      --bk-surface: rgba(255,255,255,0.04);
      --bk-border:  rgba(255,255,255,0.07);
      --bk-border-h:rgba(124,106,247,0.35);
      --bk-accent:  #7c6af7;
      --bk-accent-d:rgba(124,106,247,0.15);
      --bk-text:    #e8eeff;
      --bk-dim:     rgba(200,210,255,0.55);
      --bk-muted:   rgba(200,210,255,0.35);
      --bk-success: #3ecf8e;
      --bk-radius:  10px;
    }
    *, *::before, *::after { box-sizing: border-box; }
    html { font-family: 'Outfit', sans-serif; }
    body {
      font-family: 'Outfit', sans-serif;
      background: linear-gradient(160deg, var(--sky-void) 0%, var(--sky-deep) 40%, var(--sky-mid) 100%);
      min-height: 100vh;
      color: var(--bk-text);
      overflow: auto;
    }

    /* ── Sky background (reuse from index) ── */
    .sky-canvas {
      position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden;
      background: linear-gradient(160deg, var(--sky-void) 0%, var(--sky-deep) 40%, var(--sky-mid) 100%);
    }
    .sky-moon-glow {
      position: absolute; top: -20%; right: 6%; width: 600px; height: 600px; border-radius: 50%;
      background: radial-gradient(circle, rgba(255,250,220,0.05) 0%, rgba(200,215,255,0.02) 30%, transparent 70%);
    }
    .star-layer { position: absolute; inset: 0; }
    .star {
      position: absolute; border-radius: 50%; background: #e8eeff;
      animation: star-twinkle var(--dur) var(--delay) ease-in-out infinite;
    }
    @keyframes star-twinkle {
      0%,100% { opacity: var(--op-max); transform: scale(1); }
      45%     { opacity: var(--op-min); transform: scale(var(--scale-min,0.85)); }
    }
    .sky-cloud { position: absolute; border-radius: 50%; animation: sky-cloud-drift var(--cdur,90s) var(--cdelay,0s) linear infinite; }
    @keyframes sky-cloud-drift {
      from { transform: translateX(115vw) translateY(0); }
      to   { transform: translateX(-160%) translateY(var(--drift-y,0px)); }
    }

    /* ── Header ── */
    .bk-header {
      position: fixed; top: 0; left: 0; right: 0; z-index: 100;
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 32px; height: 60px;
      background: rgba(5,8,16,0.82); backdrop-filter: blur(24px);
      border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .bk-brand {
      font-weight: 700; font-size: 16px; color: #dddcf2;
      text-decoration: none; letter-spacing: -0.3px;
      display: flex; align-items: center;
    }
    .bk-brand img { height: 28px; }
    .bk-header-nav {
      display: flex; align-items: center; gap: 8px;
    }
    .bk-header-nav a {
      font-size: 13px; padding: 7px 16px; border-radius: 8px;
      text-decoration: none; color: rgba(220,220,255,0.7);
      transition: color 0.15s, background 0.15s; font-weight: 500;
      font-family: 'Outfit', sans-serif;
    }
    .bk-header-nav a:hover { color: #dddcf2; background: rgba(255,255,255,0.05); }

    /* ── Page layout ── */
    .bk-page {
      position: relative; z-index: 1;
      max-width: 980px; margin: 0 auto; padding: 80px 24px 80px;
      display: grid; grid-template-columns: 280px 1fr; gap: 28px;
      align-items: start;
    }
    @media (max-width: 720px) {
      .bk-page { grid-template-columns: 1fr; }
      .bk-profile-wrap { position: static !important; }
    }

    /* ── Practitioner panel ── */
    .bk-profile-wrap { position: sticky; top: 72px; max-height: calc(100vh - 100px); overflow-y: auto; }
    .bk-profile {
      background: var(--bk-surface); border: 1px solid var(--bk-border);
      border-radius: var(--bk-radius); overflow: hidden;
    }
    .bk-profile-banner {
      height: 160px;
      background: linear-gradient(160deg, rgba(124,106,247,0.2) 0%, rgba(5,8,16,0.5) 100%);
      position: relative; display: flex; align-items: flex-end; justify-content: center;
      padding-bottom: 0; overflow: hidden;
    }
    .bk-profile-banner-img {
      position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: top center; opacity: 0.45;
    }
    .bk-profile-avatar-ring {
      width: 80px; height: 80px; border-radius: 50%;
      border: 3px solid rgba(124,106,247,0.5);
      object-fit: cover;
      position: absolute; bottom: -24px; left: 50%; transform: translateX(-50%);
      background: var(--bk-surface);
    }
    .bk-profile-avatar-placeholder {
      width: 80px; height: 80px; border-radius: 50%;
      background: rgba(124,106,247,0.18); border: 3px solid rgba(124,106,247,0.4);
      display: flex; align-items: center; justify-content: center;
      font-size: 26px; font-weight: 700; color: var(--bk-accent);
      position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
    }
    .bk-profile-body { padding: 20px 20px 20px; text-align: center; }
    .bk-profile-name { font-size: 17px; font-weight: 700; margin-bottom: 6px; letter-spacing: -0.3px; }
    .bk-profile-badges { display: flex; flex-wrap: wrap; gap: 5px; justify-content: center; margin-bottom: 12px; }
    .bk-svc-badge {
      font-size: 10.5px; font-weight: 500; padding: 3px 9px; border-radius: 4px;
    }
    .bk-svc-clinical    { background:rgba(30,144,255,.12); color:#6ab4ff; border:1px solid rgba(30,144,255,.2); }
    .bk-svc-nonclinical { background:rgba(124,106,247,.12); color:#a898f8; border:1px solid rgba(124,106,247,.2); }
    .bk-practice-pill {
      font-size: 10.5px; padding: 3px 8px; border-radius: 4px;
      background: rgba(124,106,247,.1); color: rgba(196,184,255,.9); border: 1px solid rgba(124,106,247,.18);
    }
    .bk-about {
      font-size: 12.5px; color: var(--bk-muted); line-height: 1.6; text-align: left;
      margin-top: 10px;
    }
    .bk-disclosure {
      font-size: 11px; color: rgba(200,210,255,0.3); line-height: 1.5;
      margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--bk-border);
      text-align: left;
    }

    /* ── Step indicator ── */
    .bk-steps {
      display: flex; align-items: center; gap: 0; margin-bottom: 24px;
    }
    .bk-step-item {
      display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 500;
    }
    .bk-step-num {
      width: 26px; height: 26px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 600; flex-shrink: 0;
      border: 1.5px solid var(--bk-border); color: var(--bk-muted);
      background: var(--bk-surface); transition: all .2s;
    }
    .bk-step-item.active .bk-step-num {
      background: var(--bk-accent); border-color: var(--bk-accent); color: #fff;
      box-shadow: 0 0 12px rgba(124,106,247,0.4);
    }
    .bk-step-item.done .bk-step-num {
      background: rgba(62,207,142,0.15); border-color: var(--bk-success); color: var(--bk-success);
    }
    .bk-step-label { color: var(--bk-muted); transition: color .2s; }
    .bk-step-item.active .bk-step-label { color: var(--bk-text); }
    .bk-step-item.done  .bk-step-label { color: var(--bk-success); }
    .bk-step-line {
      flex: 1; height: 1px; background: var(--bk-border); margin: 0 10px; min-width: 20px;
    }

    /* ── Flow panels ── */
    .bk-flow { display: flex; flex-direction: column; gap: 0; min-width: 0; }
    .bk-panel {
      background: var(--bk-surface); border: 1px solid var(--bk-border);
      border-radius: var(--bk-radius); padding: 28px; display: none;
      animation: bk-fade-in .2s ease;
    }
    .bk-panel.visible { display: block; }
    @keyframes bk-fade-in { from { opacity: 0; transform: translateY(6px); } to { opacity:1; transform:translateY(0); } }
    .bk-panel-title {
      font-size: 17px; font-weight: 700; margin-bottom: 4px; letter-spacing: -0.3px;
    }
    .bk-panel-sub { font-size: 13px; color: var(--bk-dim); margin-bottom: 24px; }

    /* ── Calendar / date scroller ── */
    .bk-date-scroller {
      display: flex; gap: 6px; overflow-x: auto; padding-bottom: 4px;
      scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.1) transparent;
      margin-bottom: 20px;
    }
    .bk-date-btn {
      flex-shrink: 0; display: flex; flex-direction: column; align-items: center;
      gap: 2px; padding: 10px 14px; border-radius: 8px;
      border: 1px solid var(--bk-border); background: rgba(255,255,255,0.03);
      color: var(--bk-text); cursor: pointer; transition: all .15s; min-width: 60px;
    }
    .bk-date-btn:hover { border-color: var(--bk-border-h); background: rgba(124,106,247,0.08); }
    .bk-date-btn.active { border-color: var(--bk-accent); background: var(--bk-accent-d); }
    .bk-date-dow { font-size: 10px; color: var(--bk-muted); text-transform: uppercase; letter-spacing: .06em; }
    .bk-date-d   { font-size: 20px; font-weight: 700; line-height: 1.1; }
    .bk-date-mon { font-size: 10px; color: var(--bk-muted); }
    .bk-time-wrap {
      display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px;
      padding: 16px; background: rgba(255,255,255,0.02); border-radius: 8px;
      border: 1px solid var(--bk-border); min-height: 56px; align-items: center;
    }
    .bk-time-btn {
      padding: 8px 14px; border-radius: 6px; border: 1px solid var(--bk-border);
      background: rgba(255,255,255,0.03); color: var(--bk-text); cursor: pointer;
      font-size: 13px; font-family: 'Outfit',sans-serif; font-weight: 500;
      transition: all .15s;
    }
    .bk-time-btn:hover  { border-color: var(--bk-border-h); background: rgba(124,106,247,0.1); }
    .bk-time-btn.active { border-color: var(--bk-accent); background: var(--bk-accent-d); color: #c4b8ff; }
    .bk-selected-slot {
      display: none; margin-top: 16px; padding: 12px 16px;
      background: var(--bk-accent-d); border: 1px solid rgba(124,106,247,0.3);
      border-radius: 8px; font-size: 13.5px; font-weight: 500;
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
    }
    .bk-selected-slot { display: none; }
    .bk-slot-change {
      font-size: 12px; color: var(--bk-muted); cursor: pointer; background: none; border: none;
      font-family: inherit; padding: 0; transition: color .15s;
    }
    .bk-slot-change:hover { color: var(--bk-text); }

    /* ── Form fields ── */
    .bk-form-row { margin-bottom: 18px; }
    .bk-form-row label {
      display: block; font-size: 11px; font-weight: 600; color: var(--bk-muted);
      text-transform: uppercase; letter-spacing: .07em; margin-bottom: 6px;
    }
    .bk-form-row label span { font-weight: 400; text-transform: none; letter-spacing: 0; margin-left: 4px; }
    .bk-input {
      width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--bk-border);
      border-radius: 8px; color: var(--bk-text); font-family: 'Outfit',sans-serif;
      font-size: 14px; padding: 10px 14px; outline: none; transition: border-color .15s;
    }
    .bk-input:focus { border-color: var(--bk-accent); }
    .bk-input::placeholder { color: rgba(200,210,255,0.25); }
    .bk-input option { background: #0d1433; }

    /* ── Upload area ── */
    .bk-upload-area {
      display: block; border: 1.5px dashed rgba(124,106,247,0.3); border-radius: 8px;
      padding: 20px; text-align: center; cursor: pointer; transition: all .15s;
      background: rgba(124,106,247,0.04);
    }
    .bk-upload-area:hover { border-color: var(--bk-accent); background: var(--bk-accent-d); }
    .bk-upload-icon { font-size: 28px; margin-bottom: 6px; opacity: 0.6; }
    .bk-upload-text { font-size: 12.5px; color: var(--bk-muted); }
    .bk-photo-preview {
      display: none; align-items: center; gap: 14px;
      padding: 12px; background: rgba(255,255,255,0.03); border-radius: 8px;
      border: 1px solid var(--bk-border); margin-bottom: 10px;
    }
    .bk-photo-preview img { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 2px solid var(--bk-accent); }
    .bk-photo-preview-meta { font-size: 12px; color: var(--bk-muted); }

    /* ── Account checkbox ── */
    .bk-check-row {
      display: flex; align-items: center; gap: 10px;
      padding: 12px 14px; border-radius: 8px;
      background: rgba(255,255,255,0.03); border: 1px solid var(--bk-border);
      cursor: pointer; font-size: 13.5px; color: var(--bk-dim);
      transition: border-color .15s;
    }
    .bk-check-row:hover { border-color: var(--bk-border-h); }
    .bk-check-row input { accent-color: var(--bk-accent); width: 15px; height: 15px; cursor: pointer; flex-shrink: 0; }

    /* ── Agreement ── */
    .bk-agreement-scroll {
      max-height: 300px; overflow-y: auto; scrollbar-width: thin;
      scrollbar-color: rgba(255,255,255,0.1) transparent;
      border: 1px solid var(--bk-border); border-radius: 8px;
      padding: 16px 18px; background: rgba(255,255,255,0.02);
      margin-bottom: 20px;
    }
    .bk-agreement-section { margin-bottom: 16px; }
    .bk-agreement-section:last-child { margin-bottom: 0; }
    .bk-agreement-title {
      font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
      color: var(--bk-accent); margin-bottom: 6px;
    }
    .bk-agreement-section p, .bk-agreement-section li {
      font-size: 12.5px; color: var(--bk-muted); line-height: 1.6; margin-bottom: 4px;
    }
    .bk-agreement-section ul { padding-left: 16px; }
    .bk-agreement-crisis { background: rgba(224,85,85,0.05); border-left: 2px solid rgba(224,85,85,0.4); padding-left: 12px; border-radius: 0 6px 6px 0; }
    .bk-consent-row {
      display: flex; align-items: center; gap: 10px;
      font-size: 13.5px; cursor: pointer; color: var(--bk-dim); padding: 12px 0;
    }
    .bk-consent-row input { accent-color: var(--bk-accent); width: 16px; height: 16px; cursor: pointer; flex-shrink: 0; }

    /* ── Buttons ── */
    .bk-btn-row { display: flex; align-items: center; gap: 10px; margin-top: 24px; }
    .bk-btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 10px 22px; border-radius: 8px; border: none; cursor: pointer;
      font-family: 'Outfit',sans-serif; font-size: 14px; font-weight: 600;
      transition: opacity .15s, box-shadow .15s;
    }
    .bk-btn:hover { opacity: .88; }
    .bk-btn-primary { background: var(--bk-accent); color: #fff; box-shadow: 0 0 16px rgba(124,106,247,.35); }
    .bk-btn-ghost   { background: rgba(255,255,255,.06); color: var(--bk-dim); border: 1px solid var(--bk-border); }
    .bk-btn-ghost:hover { color: var(--bk-text); }
    .bk-btn-full { width: 100%; justify-content: center; padding: 12px 22px; }

    /* ── Error ── */
    .bk-error { background: rgba(224,85,85,.12); border: 1px solid rgba(224,85,85,.25); border-radius: 8px; padding: 10px 14px; font-size: 13px; color: #f08080; margin-bottom: 16px; display: none; }

    /* ── Confirm step ── */
    .bk-confirm {
      text-align: center; padding: 48px 24px;
    }
    .bk-confirm-icon {
      width: 72px; height: 72px; border-radius: 50%;
      background: rgba(62,207,142,0.12); border: 2px solid var(--bk-success);
      display: flex; align-items: center; justify-content: center;
      font-size: 32px; margin: 0 auto 20px; color: var(--bk-success);
    }
    .bk-confirm h3 { font-size: 22px; font-weight: 700; margin-bottom: 8px; }
    .bk-confirm p  { color: var(--bk-muted); font-size: 14px; line-height: 1.6; max-width: 340px; margin: 0 auto; }

    /* ── Tz display ── */
    #bk-tz-display { font-size: 12px; color: var(--bk-muted); margin-bottom: 16px; }

    /* ── Rate & promo ── */
    .bk-rate-block {
      margin-top: 12px; padding: 10px 14px;
      background: rgba(255,255,255,0.03); border: 1px solid var(--bk-border);
      border-radius: 8px; text-align: center;
    }
    .bk-rate-amount { font-size: 20px; font-weight: 700; color: var(--bk-text); line-height: 1.2; }
    .bk-rate-free   { font-size: 18px; font-weight: 700; color: var(--bk-success); }
    .bk-rate-orig   { font-size: 13px; color: var(--bk-muted); text-decoration: line-through; margin-right: 4px; }
    .bk-rate-disc   { font-size: 20px; font-weight: 700; color: var(--bk-success); }
    .bk-rate-per    { font-size: 11px; color: var(--bk-muted); margin-top: 2px; }
    .bk-promo-badge {
      display: inline-block; font-size: 10.5px; font-weight: 600;
      padding: 2px 8px; border-radius: 4px; margin-top: 6px;
      background: rgba(245,200,66,0.12); color: #f5c842; border: 1px solid rgba(245,200,66,0.25);
    }

    /* ── Expandable about ── */
    .bk-about-toggle {
      display: none; font-size: 11.5px; color: var(--bk-accent); background: none; border: none;
      font-family: inherit; cursor: pointer; padding: 4px 0 0; text-align: left;
      transition: opacity .15s;
    }
    .bk-about-toggle:hover { opacity: 0.75; }
    .bk-about.collapsed { display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden; }

    /* ── Shooting stars ── */
    .shooting-star {
      position: absolute; height: 1.5px;
      background: linear-gradient(to left, transparent 0%, rgba(200,210,255,0.7) 55%, rgba(255,255,255,0.95) 100%);
      border-radius: 999px; pointer-events: none; will-change: transform, opacity;
      animation: shoot-travel var(--shoot-dur, 0.65s) ease-out forwards;
    }
    @keyframes shoot-travel {
      0%   { opacity: 0; transform: rotate(var(--shoot-angle)) translateX(0); }
      8%   { opacity: 1; }
      100% { opacity: 0; transform: rotate(var(--shoot-angle)) translateX(var(--shoot-dist)); }
    }

    /* ── Mobile ── */
    @media (max-width: 540px) {
      .bk-header { padding: 0 16px; height: 50px; }
      .bk-header-nav a { font-size: 11px; padding: 6px 8px; }
      .bk-page { padding: 20px 12px 60px; gap: 16px; }
      .bk-profile-banner { height: 120px; }
      .bk-profile-body { padding: 32px 14px 16px; }
      .bk-profile-name { font-size: 15px; }
      .bk-panel { padding: 20px 16px; }
      .bk-panel-title { font-size: 15px; }
      .bk-steps { gap: 0; overflow-x: auto; padding-bottom: 4px; flex-shrink: 0; }
      .bk-step-label { display: none; }
      .bk-step-line { min-width: 12px; margin: 0 6px; }
      .bk-btn { font-size: 13px; padding: 9px 16px; }
      .bk-date-btn { padding: 8px 10px; min-width: 52px; }
      .bk-date-d { font-size: 17px; }
      .bk-agreement-scroll { max-height: 220px; }
    }
  </style>
</head>
<body style="overflow:auto;">
<?php renderBanners('public'); ?>

<!-- Sky canvas -->
<div class="sky-canvas" id="sky-canvas-bk" aria-hidden="true">
  <div class="sky-moon-glow"></div>
  <div class="star-layer" id="bk-star-0"></div>
  <div class="star-layer" id="bk-star-1"></div>
  <div class="sky-cloud" style="width:700px;height:190px;top:10%;background:radial-gradient(ellipse,rgba(180,195,255,0.05) 0%,transparent 70%);filter:blur(55px);--cdur:120s;--cdelay:-40s;--drift-y:-10px;"></div>
  <div class="sky-cloud" style="width:450px;height:130px;top:55%;background:radial-gradient(ellipse,rgba(150,160,255,0.04) 0%,transparent 70%);filter:blur(45px);--cdur:90s;--cdelay:-20s;--drift-y:12px;"></div>
  <div class="sky-cloud" style="width:580px;height:160px;top:78%;background:radial-gradient(ellipse,rgba(124,106,247,0.035) 0%,transparent 70%);filter:blur(50px);--cdur:105s;--cdelay:-65s;--drift-y:-8px;"></div>
</div>

<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
(function(){
  const rnd=(a,b)=>a+Math.random()*(b-a);
  [[150,0.8,1.4,0.50,0.10,7,13,0.82],[60,1.4,2.4,0.80,0.22,4,8,0.90]].forEach(([n,s0,s1,oMax,oMin,d0,d1,sc],li)=>{
    const l=document.getElementById('bk-star-'+li);
    if(!l)return;
    for(let i=0;i<n;i++){
      const s=document.createElement('div');const sz=rnd(s0,s1);const dur=rnd(d0,d1).toFixed(2);const dly=rnd(0,d1).toFixed(2);
      s.className='star';
      s.style.cssText=`width:${sz.toFixed(2)}px;height:${sz.toFixed(2)}px;top:${rnd(0,100).toFixed(3)}%;left:${rnd(0,100).toFixed(3)}%;--op-max:${oMax};--op-min:${oMin};--scale-min:${sc};--dur:${dur}s;--delay:${dly}s;`;
      l.appendChild(s);
    }
  });
})();
</script>

<!-- ── Header ── -->
<header class="bk-header">
  <a href="/" class="bk-brand">
    <?php if ($appLogo): ?>
      <img src="<?= htmlspecialchars($appLogo) ?>" alt="<?= htmlspecialchars($pract['display_name']) ?>">
    <?php else: ?>
      <?= htmlspecialchars($appName ?? 'Serenity Spaces') ?>
    <?php endif; ?>
  </a>
  <nav class="bk-header-nav">
    <a href="/?browse=1">Find a Practitioner</a>
    <a href="/coverage.php">Coverage</a>
    <a href="/about.php">About</a>
    <a href="/login.php">Login</a>
  </nav>
</header>

<div class="bk-page">

  <!-- ── Practitioner profile (sticky) ── -->
  <div class="bk-profile-wrap">
    <div class="bk-profile">
      <div class="bk-profile-banner">
        <?php if ($pract['avatar_path']): ?>
          <img src="<?= htmlspecialchars($pract['avatar_path']) ?>" alt="" class="bk-profile-banner-img">
        <?php else: ?>
          <div class="bk-profile-avatar-placeholder"><?= mb_strtoupper(mb_substr($pract['display_name'], 0, 1)) ?></div>
        <?php endif; ?>
      </div>
      <div class="bk-profile-body">
        <div class="bk-profile-name"><?= htmlspecialchars($pract['display_name']) ?></div>
        <?php $svcType = $pract['service_type'] ?? 'non_clinical'; ?>
        <div class="bk-profile-badges">
          <span class="bk-svc-badge <?= $svcType === 'clinical' ? 'bk-svc-clinical' : 'bk-svc-nonclinical' ?>">
            <?= $svcType === 'clinical' ? 'Licensed Clinical' : 'Non-Clinical' ?>
          </span>
          <?php foreach (array_slice($practTypes, 0, 3) as $pt): ?>
            <span class="bk-practice-pill"><?= htmlspecialchars($typeLabels[$pt] ?? $pt) ?></span>
          <?php endforeach; ?>
          <?php if ($practAiCfg): ?>
            <span class="bk-practice-pill" title="This practitioner uses an AI assistant in some sessions" style="background:rgba(245,200,66,0.12);color:#f5c842;border-color:rgba(245,200,66,0.3);">Optional AI Assistance</span>
          <?php endif; ?>
        </div>
        <?php if ($pract['about_work']): ?>
          <?php $aboutFull = htmlspecialchars($pract['about_work']); $aboutLong = mb_strlen($pract['about_work']) > 220; ?>
          <p class="bk-about<?= $aboutLong ? ' collapsed' : '' ?>" id="bk-about-text"><?= $aboutFull ?></p>
          <?php if ($aboutLong): ?>
            <button class="bk-about-toggle" id="bk-about-toggle" style="display:inline-block;">Read more ↓</button>
          <?php endif; ?>
        <?php endif; ?>
        <p class="bk-disclosure">
          <?= $svcType === 'clinical'
            ? 'Licensed clinical services. Credentials and scope will be confirmed at session start.'
            : 'Non-clinical services only. Does not diagnose or treat mental health conditions.' ?>
        </p>

        <?php if ($practRate): ?>
          <?php
            $bestPromo  = $practCampaigns[0] ?? null; // already sorted DESC by discount_pct
            $rateSym    = match($practRate['currency'] ?? 'GBP') { 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AUD' => 'A$', 'CAD' => 'C$', 'NZD' => 'NZ$', 'SGD' => 'S$', 'PHP' => '₱', 'INR' => '₹', 'ZAR' => 'R', default => ($practRate['currency'] ?? '') . ' ' };
            $rateOrig   = (float)$practRate['amount'];
            $rateDisc   = $bestPromo ? round($rateOrig * (1 - $bestPromo['discount_pct'] / 100), 2) : null;
          ?>
          <div class="bk-rate-block">
            <?php if ($practRate['is_free']): ?>
              <div class="bk-rate-free">Free</div>
              <div class="bk-rate-per">No charge for sessions</div>
            <?php elseif ($bestPromo): ?>
              <div>
                <span class="bk-rate-orig"><?= $rateSym . number_format($rateOrig, 2) ?></span>
                <span class="bk-rate-disc"><?= $bestPromo['discount_pct'] == 100 ? 'Free' : ($rateSym . number_format($rateDisc, 2)) ?></span>
              </div>
              <div class="bk-rate-per">per session</div>
              <div class="bk-promo-badge"><?= htmlspecialchars($bestPromo['label']) ?> — <?= $bestPromo['discount_pct'] ?>% off</div>
            <?php else: ?>
              <div class="bk-rate-amount"><?= $rateSym . number_format($rateOrig, 2) ?></div>
              <div class="bk-rate-per">per session</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- ── Booking flow ── -->
  <div class="bk-flow">

  <?php if ($gateBlocked): ?>
    <!-- Not-accepting gate -->
    <div class="bk-panel visible" id="gate-panel" style="text-align:center;padding:40px 32px;">
      <div style="width:52px;height:52px;border-radius:50%;background:rgba(224,122,48,0.12);border:1px solid rgba(224,122,48,0.3);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#e07a30" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <?php if ($gateReason === 'login_required'): ?>
        <div style="font-size:18px;font-weight:700;color:var(--bk-text);margin-bottom:10px;">Sign In Required</div>
        <p style="font-size:14px;color:var(--bk-dim);line-height:1.6;margin-bottom:28px;max-width:320px;margin-left:auto;margin-right:auto;">
          <?= htmlspecialchars($pract['display_name']) ?> is not currently accepting new clients.
          If you've worked with them before, please sign in to check your eligibility.
        </p>
        <a href="/login.php?tab=client&return=<?= urlencode('/book.php?practitioner=' . $practId) ?>" class="bk-btn bk-btn-primary" style="display:inline-flex;">
          Sign In as Client
          <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14" style="margin-left:6px;"><path fill-rule="evenodd" d="M3 10a.75.75 0 01.75-.75h10.638L10.23 5.29a.75.75 0 111.04-1.08l5.5 5.25a.75.75 0 010 1.08l-5.5 5.25a.75.75 0 11-1.04-1.08l4.158-3.96H3.75A.75.75 0 013 10z" clip-rule="evenodd"/></svg>
        </a>
        <div style="margin-top:14px;font-size:12px;color:var(--bk-muted);">No account? Reach out to your practitioner directly.</div>
      <?php else: ?>
        <div style="font-size:18px;font-weight:700;color:var(--bk-text);margin-bottom:10px;">Not Accepting New Clients</div>
        <p style="font-size:14px;color:var(--bk-dim);line-height:1.6;margin-bottom:24px;max-width:340px;margin-left:auto;margin-right:auto;">
          <?= htmlspecialchars($pract['display_name']) ?> is not currently accepting new clients.
          Please check back later or reach out to them directly.
        </p>
        <a href="/?browse=1" class="bk-btn" style="display:inline-flex;background:rgba(255,255,255,0.06);color:var(--bk-dim);border:1px solid rgba(255,255,255,0.1);">Browse Other Practitioners</a>
      <?php endif; ?>
    </div>
  <?php else: ?>

    <!-- Step indicator -->
    <div class="bk-steps" id="step-indicator">
      <div class="bk-step-item active" id="si-1">
        <span class="bk-step-num">1</span>
        <span class="bk-step-label">Schedule</span>
      </div>
      <div class="bk-step-line"></div>
      <div class="bk-step-item" id="si-2">
        <span class="bk-step-num">2</span>
        <span class="bk-step-label">Your Details</span>
      </div>
      <div class="bk-step-line"></div>
      <div class="bk-step-item" id="si-3">
        <span class="bk-step-num">3</span>
        <span class="bk-step-label">Agreement</span>
      </div>
      <div class="bk-step-line"></div>
      <div class="bk-step-item" id="si-4">
        <span class="bk-step-num">4</span>
        <span class="bk-step-label">Confirmed</span>
      </div>
    </div>

    <!-- Step 1: Schedule -->
    <div class="bk-panel visible" id="step-schedule">
      <div class="bk-panel-title">Select a Date &amp; Time</div>
      <div id="bk-tz-display" class="bk-panel-sub">Loading availability…</div>

      <div id="calendar-loading" style="padding:32px;text-align:center;color:rgba(200,210,255,0.35);">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="animation:spin 1.2s linear infinite;margin-bottom:8px;display:block;margin-left:auto;margin-right:auto;"><path stroke-linecap="round" d="M12 3v3m0 12v3M3 12h3m12 0h3"/></svg>
        Loading schedule…
      </div>

      <div id="calendar-wrap" style="display:none;">
        <div id="date-scroller" class="bk-date-scroller"></div>
        <div id="time-slots" class="bk-time-wrap" style="display:none;"></div>
      </div>

      <div id="selected-slot-display" class="bk-selected-slot" style="display:none;">
        <div>
          <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14" style="vertical-align:middle;margin-right:4px;color:#7c6af7;"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm.75-13a.75.75 0 00-1.5 0v5.25l3.5 2.1a.75.75 0 00.75-1.3L10.75 9.5V5z" clip-rule="evenodd"/></svg>
          <span id="selected-slot-label" style="font-weight:600;"></span>
        </div>
        <button type="button" class="bk-slot-change" id="bk-slot-change-btn">change</button>
      </div>

      <div class="bk-btn-row" id="step-next-btn-wrap" style="display:none;">
        <button class="bk-btn bk-btn-primary" id="bk-step2-btn">
          Continue
          <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M3 10a.75.75 0 01.75-.75h10.638L10.23 5.29a.75.75 0 111.04-1.08l5.5 5.25a.75.75 0 010 1.08l-5.5 5.25a.75.75 0 11-1.04-1.08l4.158-3.96H3.75A.75.75 0 013 10z" clip-rule="evenodd"/></svg>
        </button>
      </div>
    </div>

    <!-- Step 2: Your Details -->
    <div class="bk-panel" id="step-form">
      <div class="bk-panel-title">Your Details</div>
      <div class="bk-panel-sub">What you share here helps your practitioner create the right space for you. Everything is optional.</div>

      <div class="bk-error" id="book-error"></div>

      <div class="bk-form-row">
        <label for="book-name">Your name</label>
        <input type="text" id="book-name" class="bk-input" placeholder="How should we call you?" required>
      </div>

      <div class="bk-form-row">
        <label for="book-email">Email address <span>(optional — for session reminders)</span></label>
        <input type="email" id="book-email" class="bk-input" placeholder="you@example.com">
      </div>

      <div class="bk-form-row">
        <label>Your photo <span style="color:#f08080;">required</span></label>
        <div class="bk-photo-preview" id="book-photo-preview">
          <img id="book-upload-preview" alt="">
          <div class="bk-photo-preview-meta" id="book-upload-label">Photo ready</div>
        </div>
        <label for="book-avatar-upload" class="bk-upload-area">
          <div class="bk-upload-icon">📷</div>
          <div class="bk-upload-text" id="bk-upload-text">Click to upload your photo<br><span style="font-size:11px;opacity:.7;">JPEG, PNG, GIF, WEBP — max 5 MB</span></div>
          <input type="file" id="book-avatar-upload" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
        </label>
      </div>

      <input type="hidden" id="wants-account" value="0">
      <div class="bk-form-row">
        <label style="margin-bottom:8px;">Have an account or want to create one?</label>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <a href="/login.php?tab=client&return=<?= urlencode('/book.php?practitioner='.$practId) ?>"
             class="bk-btn bk-btn-ghost" style="flex:1;text-align:center;text-decoration:none;font-size:13px;">Sign in</a>
          <button type="button" id="btn-create-account" class="bk-btn bk-btn-ghost"
                  style="flex:1;font-size:13px;">Create account</button>
        </div>
      </div>

      <div id="account-fields" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.07);">
        <div class="bk-form-row">
          <label for="account-pass">Password</label>
          <input type="password" id="account-pass" class="bk-input" placeholder="••••••••" autocomplete="new-password">
          <?= password_meter('account-pass', false) ?>
        </div>
        <div style="background:rgba(255,255,255,0.03);border-radius:8px;border-left:2px solid rgba(124,106,247,0.3);padding:12px 14px;margin-bottom:16px;">
          <p style="font-size:12px;font-weight:600;color:var(--bk-text);margin-bottom:4px;">About these optional details</p>
          <p style="font-size:12px;color:var(--bk-muted);line-height:1.55;margin:0;">
            Every field below is optional and can be left blank — you can share any of this in session instead. Where you do share it, it helps your practitioner understand your context and prepare appropriately. This information is encrypted at rest, never sold or shared with third parties, and you can request deletion at any time.
          </p>
        </div>
        <div class="bk-form-row">
          <label for="book-gender">
            Gender <span>(optional)</span>
            <span style="display:block;font-size:11px;font-weight:400;color:var(--bk-muted);margin-top:1px;">Helps your practitioner use the right language and approach.</span>
          </label>
          <select id="book-gender" class="bk-input">
            <option value="">Prefer not to say / skip</option>
            <option value="male">Male</option><option value="female">Female</option>
            <option value="trans_woman">MtF (Trans Woman)</option><option value="trans_man">FtM (Trans Man)</option>
            <option value="non_binary">Non-binary</option><option value="genderfluid">Genderfluid</option>
            <option value="prefer_not_to_say">Prefer not to say</option>
          </select>
        </div>
        <div class="bk-form-row">
          <label for="book-sexuality">
            Sexuality <span>(optional)</span>
            <span style="display:block;font-size:11px;font-weight:400;color:var(--bk-muted);margin-top:1px;">Useful if relevant to what you want to explore. Completely safe to skip.</span>
          </label>
          <select id="book-sexuality" class="bk-input">
            <option value="">Prefer not to say / skip</option>
            <option value="straight">Straight / Heterosexual</option><option value="gay">Gay</option>
            <option value="lesbian">Lesbian</option><option value="bisexual">Bisexual</option>
            <option value="pansexual">Pansexual</option><option value="asexual">Asexual</option>
            <option value="queer">Queer</option><option value="questioning">Questioning</option>
            <option value="prefer_not_to_say">Prefer not to say</option>
          </select>
        </div>
        <div class="bk-form-row">
          <label for="book-dob">
            Date of Birth <span>(optional)</span>
            <span style="display:block;font-size:11px;font-weight:400;color:var(--bk-muted);margin-top:1px;">Used only to provide age-appropriate context to your practitioner. Not used for identification.</span>
          </label>
          <input type="date" id="book-dob" class="bk-input" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="bk-form-row">
          <label for="book-location">
            Country <span>(optional)</span>
            <span style="display:block;font-size:11px;font-weight:400;color:var(--bk-muted);margin-top:1px;">Helps your practitioner understand your legal and cultural context where relevant.</span>
          </label>
          <select id="book-location" class="bk-input">
            <option value="">Not specified / skip</option>
            <?php foreach (getCountryList() as $code => $name): ?>
              <option value="<?= htmlspecialchars($code) ?>"><?= countryFlag($code) ?> <?= htmlspecialchars($name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <?php if ($practRate && !$practRate['is_free']): ?>
      <div class="bk-form-row" style="margin-top:20px;padding-top:20px;border-top:1px solid rgba(255,255,255,0.07);">
        <label for="book-discount-code">Discount code <span>(optional)</span></label>
        <div style="display:flex;gap:8px;align-items:center;">
          <input type="text" id="book-discount-code" class="bk-input"
                 placeholder="Enter code"
                 style="flex:1;font-family:monospace;letter-spacing:.05em;text-transform:uppercase;">
          <button type="button" class="bk-btn bk-btn-ghost" id="book-discount-apply-btn"
                  style="flex-shrink:0;padding:0 16px;height:40px;font-size:13px;">Apply</button>
        </div>
        <div id="book-discount-feedback" style="display:none;margin-top:8px;font-size:13px;border-radius:6px;padding:8px 12px;"></div>
      </div>
      <?php endif; ?>

      <div class="bk-btn-row">
        <button class="bk-btn bk-btn-ghost" id="bk-back-btn">← Back</button>
        <button class="bk-btn bk-btn-primary" id="bk-agreement-btn">
          Continue
          <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M3 10a.75.75 0 01.75-.75h10.638L10.23 5.29a.75.75 0 111.04-1.08l5.5 5.25a.75.75 0 010 1.08l-5.5 5.25a.75.75 0 11-1.04-1.08l4.158-3.96H3.75A.75.75 0 013 10z" clip-rule="evenodd"/></svg>
        </button>
      </div>
    </div>

    <!-- Step 3: Agreement -->
    <div class="bk-panel" id="step-agreement">
      <div class="bk-panel-title">Platform Use &amp; Session Agreement</div>
      <div class="bk-panel-sub">Please read and accept before confirming your booking.</div>

      <div class="bk-agreement-scroll">
        <div class="bk-agreement-section">
          <div class="bk-agreement-title">Platform Role</div>
          <p>Serenity Spaces is a private, browser-based platform that enables practitioners to host sessions with clients. Serenity Spaces itself does not provide medical, psychological, or therapeutic services. All services are provided independently by the practitioner you select.</p>
        </div>
        <div class="bk-agreement-section">
          <div class="bk-agreement-title">Practitioner Responsibility</div>
          <p>Each practitioner on Serenity Spaces operates independently and is responsible for the type of services they offer, their qualifications and credentials, and the scope and nature of their sessions.</p>
        </div>
        <div class="bk-agreement-section">
          <div class="bk-agreement-title">Service Type Disclosure</div>
          <p>Before your session begins, your practitioner will indicate whether their services are clinical or non-clinical and the scope and limitations of their work. By proceeding, you agree to review and accept the practitioner's specific terms.</p>
        </div>
        <div class="bk-agreement-section bk-agreement-crisis">
          <div class="bk-agreement-title">Emergency &amp; Crisis Limitation</div>
          <p><strong>Serenity Spaces is not a crisis service.</strong> If you are experiencing thoughts of self-harm, risk of harm to others, or a mental health emergency, contact <strong>988</strong> (U.S. Suicide &amp; Crisis Lifeline) or local emergency services immediately.</p>
        </div>
        <div class="bk-agreement-section">
          <div class="bk-agreement-title">Technology &amp; Privacy</div>
          <p>Sessions take place over an online platform. While reasonable steps are taken to maintain privacy, no system can guarantee absolute security. Transcripts and session data may be generated depending on session settings.</p>
        </div>
        <div class="bk-agreement-section">
          <div class="bk-agreement-title">User Responsibility</div>
          <p>By continuing, you acknowledge that you are voluntarily participating, you understand the nature of online communication, and you accept responsibility for your decisions and actions.</p>
        </div>
      </div>

      <div class="bk-error" id="agreement-error"></div>

      <?php if ($practAiCfg): ?>
      <div id="ai-consent-block" style="background:rgba(124,106,247,0.06);border:1px solid rgba(124,106,247,0.2);border-radius:10px;padding:14px 16px;margin-bottom:12px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="#a89ff7" stroke-width="1.8" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/></svg>
          <span style="font-size:13px;font-weight:600;color:#a89ff7;">AI Integration — Your Consent</span>
        </div>
        <p style="font-size:12.5px;color:rgba(221,220,242,0.65);margin:0 0 10px;line-height:1.6;">
          <?= htmlspecialchars($pract['display_name']) ?> uses an AI assistant named <strong style="color:rgba(221,220,242,0.85);"><?= htmlspecialchars($practAiCfg['assistant_name']) ?></strong> in their practice.
          Session content may be sent to a third-party AI provider (<?= htmlspecialchars(strtoupper($practAiCfg['vendor'])) ?>) for the purposes listed below.
          You can update these preferences at any time from your client portal.
        </p>

        <label style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;cursor:pointer;" id="ai-allow-label">
          <input type="checkbox" id="ai-consent-allowed" style="margin-top:2px;accent-color:#7c6af7;">
          <span style="font-size:13px;color:rgba(221,220,242,0.75);">I consent to the use of AI assistance in my sessions with this practitioner</span>
        </label>

        <div id="ai-scope-consents" style="display:none;padding-left:22px;margin-top:6px;">
          <?php
          $scopeLabels = [
              'summarization' => ['name'=>'Session Summarization', 'desc'=>'AI generates a summary of your session after it ends.'],
              'notes'         => ['name'=>'Notes Assistance',       'desc'=>'AI helps draft clinical notes from session content.'],
              'post_session'  => ['name'=>'Post-Session Discussion', 'desc'=>'Practitioner can discuss your session with the AI after it ends.'],
              'in_session'    => ['name'=>'In-Session Assistant',   'desc'=>'An AI participant joins your live session and can be addressed by name.'],
          ];
          foreach ($scopeLabels as $sk => $sl):
              if (!$practAiCfg['scope_' . $sk]) continue; ?>
          <label style="display:flex;align-items:flex-start;gap:10px;padding:6px 0;cursor:pointer;border-top:1px solid rgba(124,106,247,0.1);">
            <input type="checkbox" class="ai-scope-consent-cb" data-scope="<?= $sk ?>" style="margin-top:2px;accent-color:#7c6af7;" checked>
            <span style="font-size:12.5px;color:rgba(221,220,242,0.65);">
              <strong style="color:rgba(221,220,242,0.8);"><?= $sl['name'] ?>:</strong> <?= $sl['desc'] ?>
            </span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <label class="bk-consent-row" id="special-consent-row" style="display:none;background:rgba(232,85,85,0.05);border:1px solid rgba(232,85,85,0.18);border-radius:8px;padding:10px 12px;margin-bottom:8px;">
        <input type="checkbox" id="special-data-consent">
        <span style="font-size:13px;line-height:1.5;color:rgba(221,220,242,0.75);">
          I give explicit consent for the processing of the special category personal data I have provided (gender, sexuality, date of birth) for the purpose of supporting my session. This data is encrypted at rest and used only to help my practitioner provide appropriate support. I understand I may withdraw this consent at any time. &nbsp;<a href="/privacy.php" target="_blank" style="color:rgba(196,184,255,0.8);">Privacy Notice</a>
        </span>
      </label>

      <label class="bk-consent-row">
        <input type="checkbox" id="agreement-consent">
        I have read and agree to the above terms
      </label>

      <div style="font-size:12px;color:rgba(200,210,255,0.35);margin-top:8px;">
        By booking, you acknowledge our <a href="/privacy.php" target="_blank" style="color:rgba(196,184,255,0.6);">Privacy Notice</a> and understand how your data will be used.
      </div>

      <?php if (!empty($pract['cancellation_policy'])): ?>
        <details style="margin-top:14px;border:1px solid rgba(124,106,247,0.18);border-radius:8px;padding:0;background:rgba(124,106,247,0.04);">
          <summary style="cursor:pointer;list-style:none;padding:11px 14px;font-size:12.5px;font-weight:600;color:rgba(200,210,255,0.78);user-select:none;display:flex;align-items:center;justify-content:space-between;gap:8px;">
            <span><?= htmlspecialchars($pract['display_name'] ?: 'Practitioner') ?>'s cancellation policy</span>
            <span style="font-size:10px;font-weight:500;opacity:0.55;">tap to view ▾</span>
          </summary>
          <div style="padding:10px 14px 14px;font-size:12.5px;color:rgba(200,210,255,0.65);line-height:1.65;border-top:1px solid rgba(124,106,247,0.12);white-space:pre-line;"><?= htmlspecialchars($pract['cancellation_policy']) ?></div>
        </details>
      <?php endif; ?>

      <div class="bk-btn-row">
        <button class="bk-btn bk-btn-ghost" id="bk-back-to-form-btn">← Back</button>
        <button class="bk-btn bk-btn-primary" id="bk-submit-btn">Confirm &amp; Book</button>
      </div>
    </div>

    <!-- Step 4: Confirmed -->
    <div class="bk-panel" id="step-confirm">
      <div class="bk-confirm">
        <div class="bk-confirm-icon">✓</div>
        <h3>Booking Request Sent</h3>
        <p id="confirm-message"></p>
        <div id="confirm-next-steps" style="margin-top:24px;text-align:left;background:rgba(255,255,255,0.03);border-radius:10px;padding:18px 20px;">
          <div style="font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:rgba(221,220,242,0.35);margin-bottom:14px;">What happens next</div>
          <div style="display:flex;flex-direction:column;gap:12px;">
            <div style="display:flex;gap:12px;align-items:flex-start;">
              <div style="width:22px;height:22px;border-radius:50%;background:rgba(124,106,247,0.18);border:1px solid rgba(124,106,247,0.35);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#7c6af7;flex-shrink:0;">1</div>
              <div style="font-size:13px;color:rgba(221,220,242,0.65);line-height:1.5;"><strong style="color:#dddcf2;">Your practitioner reviews your request.</strong> They'll confirm or reach out within their usual response time.</div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
              <div style="width:22px;height:22px;border-radius:50%;background:rgba(124,106,247,0.18);border:1px solid rgba(124,106,247,0.35);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#7c6af7;flex-shrink:0;">2</div>
              <div id="confirm-step2" style="font-size:13px;color:rgba(221,220,242,0.65);line-height:1.5;"><strong style="color:#dddcf2;">You'll receive your session link by email</strong> once confirmed. Keep an eye on your inbox (and spam folder).</div>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;">
              <div style="width:22px;height:22px;border-radius:50%;background:rgba(124,106,247,0.18);border:1px solid rgba(124,106,247,0.35);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#7c6af7;flex-shrink:0;">3</div>
              <div style="font-size:13px;color:rgba(221,220,242,0.65);line-height:1.5;"><strong style="color:#dddcf2;">At your session time, click the link.</strong> The room opens in your browser — no app to download, no account required.</div>
            </div>
          </div>
        </div>
        <p style="font-size:12px;color:rgba(221,220,242,0.3);margin-top:16px;line-height:1.5;">
          Need to reach out before then? Contact your practitioner directly or check your email for their contact details.
        </p>

        <!--
          Advanced-gateway payment block. Hidden by default; populated by
          loadCheckoutOptions() after the booking is created. Pay Now buttons
          here trigger /api/payments.php?action={gateway}_checkout which
          returns a hosted checkout URL we redirect the client to.
        -->
        <div id="bk-pay-block" style="display:none;margin-top:24px;padding:20px;border-radius:10px;border:1px solid rgba(124,106,247,0.25);background:rgba(124,106,247,0.06);">
          <div style="font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:rgba(197,184,255,0.85);margin-bottom:6px;">Pay now to secure your slot</div>
          <div id="bk-pay-amount" style="font-size:22px;font-weight:700;color:#dddcf2;margin-bottom:14px;"></div>
          <div id="bk-pay-buttons" style="display:flex;flex-direction:column;gap:8px;"></div>
          <div id="bk-pay-error" style="display:none;font-size:12.5px;color:#e85555;margin-top:10px;"></div>
          <p style="font-size:11.5px;color:rgba(221,220,242,0.4);line-height:1.55;margin-top:14px;margin-bottom:0;">
            You'll be redirected to your practitioner's chosen payment processor. Your booking is held — payment confirms it.
          </p>
        </div>

        <div class="bk-btn-row" style="justify-content:center;margin-top:24px;">
          <a href="/index.php" class="bk-btn bk-btn-ghost">← Back to Directory</a>
        </div>
      </div>
    </div>

  <?php endif; // end gate else ?>

  </div><!-- /.bk-flow -->
</div><!-- /.bk-page -->

<style nonce="<?= $GLOBALS['csp_nonce'] ?>">
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
const PRACTITIONER_ID = <?= $practId ?>;
const CSRF_TOKEN      = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
<?php if ($practRate && !$practRate['is_free']): ?>
const RATE_AMOUNT     = <?= (float)$practRate['amount'] ?>;
const RATE_CURRENCY   = '<?= htmlspecialchars($practRate['currency'] ?? 'GBP') ?>';
const RATE_SYMBOL     = '<?= match($practRate['currency'] ?? 'GBP') { 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AUD' => 'A$', 'CAD' => 'C$', 'NZD' => 'NZ$', 'SGD' => 'S$', 'PHP' => '₱', 'INR' => '₹', 'ZAR' => 'R', default => htmlspecialchars($practRate['currency'] ?? 'GBP') . ' ' } ?>';
<?php else: ?>
const RATE_AMOUNT = 0; const RATE_CURRENCY = ''; const RATE_SYMBOL = '';
<?php endif; ?>

let selectedSlotUtc     = null;
let selectedSlotLabel   = '';
let availData           = null;
let confirmedClientTz   = Intl.DateTimeFormat().resolvedOptions().timeZone;
let appliedDiscountCode = null;  // {code, pct} when a valid code is applied

// ── Step navigation ────────────────────────────────────────────
function showStep(n) {
    // Toggle panels
    ['step-schedule','step-form','step-agreement','step-confirm'].forEach((id, i) => {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('visible', i + 1 === n);
    });
    // Update step indicator
    for (let i = 1; i <= 4; i++) {
        const si = document.getElementById('si-' + i);
        if (!si) continue;
        si.classList.remove('active','done');
        if (i < n)  si.classList.add('done');
        if (i === n) si.classList.add('active');
    }
    // Scroll to top of flow
    const flow = document.querySelector('.bk-flow');
    if (flow) flow.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Load availability from API ─────────────────────────────────
async function loadAvailability() {
    try {
        const res  = await fetch('/api/availability.php?practitioner_id=' + PRACTITIONER_ID);
        availData  = await res.json();
        document.getElementById('calendar-loading').style.display = 'none';
        document.getElementById('calendar-wrap').style.display    = 'block';
        const tzEl = document.getElementById('bk-tz-display');
        if (tzEl) {
            const browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            confirmedClientTz = localStorage.getItem('ss_preferred_tz') || browserTz;
            const practTz  = availData.timezone || 'UTC';
            renderTzDisplay(tzEl, confirmedClientTz, practTz);
        }
        buildCalendar();
    } catch(e) {
        document.getElementById('calendar-loading').textContent = 'Could not load availability.';
    }
}

function renderTzDisplay(tzEl, clientTz, practTz) {
    const tzLabel = clientTz && clientTz !== practTz
        ? `Times shown in <strong>${clientTz}</strong> (your time). Practitioner is in <strong>${practTz}</strong>.`
        : `Times shown in your timezone (${clientTz || practTz}).`;
    tzEl.innerHTML = tzLabel + ` <a href="#" id="bk-tz-change-link" style="color:rgba(124,106,247,0.8);font-size:11px;margin-left:6px;text-decoration:underline;" onclick="toggleTzSelect(event)">Not your timezone?</a>`;
}

function toggleTzSelect(e) {
    e.preventDefault();
    let sel = document.getElementById('bk-tz-select-wrap');
    if (sel) { sel.remove(); return; }
    const wrap = document.createElement('div');
    wrap.id = 'bk-tz-select-wrap';
    wrap.style.cssText = 'margin-top:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;';
    const allTz = (typeof Intl.supportedValuesOf === 'function')
        ? Intl.supportedValuesOf('timeZone')
        : ['UTC','America/New_York','America/Chicago','America/Denver','America/Los_Angeles','America/Anchorage','Pacific/Honolulu','Europe/London','Europe/Paris','Europe/Berlin','Europe/Madrid','Europe/Rome','Europe/Amsterdam','Europe/Stockholm','Europe/Helsinki','Europe/Athens','Europe/Istanbul','Asia/Dubai','Asia/Karachi','Asia/Kolkata','Asia/Dhaka','Asia/Bangkok','Asia/Singapore','Asia/Tokyo','Australia/Sydney','Australia/Melbourne','Pacific/Auckland'];
    const select = document.createElement('select');
    select.style.cssText = 'background:#1a1828;color:#e0dff5;border:1px solid rgba(124,106,247,0.3);border-radius:6px;padding:5px 8px;font-size:12px;';
    allTz.forEach(tz => {
        const opt = document.createElement('option');
        opt.value = tz; opt.textContent = tz;
        if (tz === confirmedClientTz) opt.selected = true;
        select.appendChild(opt);
    });
    select.addEventListener('change', () => {
        confirmedClientTz = select.value;
        localStorage.setItem('ss_preferred_tz', confirmedClientTz);
        renderTzDisplay(document.getElementById('bk-tz-display'), confirmedClientTz, availData?.timezone || 'UTC');
        buildCalendar();
    });
    const lbl = document.createElement('span');
    lbl.textContent = 'Select timezone:';
    lbl.style.cssText = 'font-size:11px;color:rgba(200,210,255,0.5);';
    wrap.appendChild(lbl); wrap.appendChild(select);
    document.getElementById('bk-tz-display').after(wrap);
}

function buildCalendar() {
    const scroller = document.getElementById('date-scroller');
    scroller.innerHTML = '';

    const now   = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

    // Build booked set (UTC ms)
    const bookedSlots = new Set((availData.booked || []).map(b => {
        return new Date(b.scheduled_at.replace(' ','T') + 'Z').getTime();
    }));

    // Exclusions set
    const exclSet = new Set(availData.exclusions || []);

    let daysAdded = 0;
    for (let d = 0; d < 56 && daysAdded < 28; d++) {
        const date    = new Date(today);
        date.setDate(today.getDate() + d);
        const dateStr = date.toISOString().slice(0, 10);

        // Convert date to practitioner timezone to get their local DOW
        const practDow = getPractitionerDow(date, availData.timezone);

        const slotDef = (availData.slots || []).find(s => s.day_of_week == practDow);
        if (!slotDef) continue;
        if (exclSet.has(dateStr)) continue;

        // Generate time slots for this day
        const slots = generateSlots(date, slotDef, bookedSlots, availData.timezone);
        if (!slots.length) continue;

        daysAdded++;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'bk-date-btn';
        btn.dataset.date  = dateStr;
        btn.dataset.slots = JSON.stringify(slots);
        btn.innerHTML = `<span class="bk-date-dow">${date.toLocaleDateString('en-US',{weekday:'short'})}</span>
                         <span class="bk-date-d">${date.getDate()}</span>
                         <span class="bk-date-mon">${date.toLocaleDateString('en-US',{month:'short'})}</span>`;
        btn.addEventListener('click', () => selectDate(btn, slots));
        scroller.appendChild(btn);
    }

    if (!daysAdded) {
        scroller.innerHTML = '<p style="padding:16px;color:rgba(200,210,255,0.4);font-size:13px;">No available dates in the next 8 weeks.</p>';
    }
}

function getPractitionerDow(jsDate, tz) {
    // Get what day of week it is in the practitioner's timezone
    const str = jsDate.toLocaleDateString('en-US', { weekday: 'short', timeZone: tz });
    const map = { Sun:0, Mon:1, Tue:2, Wed:3, Thu:4, Fri:5, Sat:6 };
    return map[str] ?? jsDate.getDay();
}

function generateSlots(date, slotDef, bookedSlots, practTz) {
    // slotDef.start_time / end_time are local practitioner times "HH:MM:SS"
    const [startH, startM] = slotDef.start_time.split(':').map(Number);
    const [endH,   endM]   = slotDef.end_time.split(':').map(Number);

    const slots = [];
    // Build slot start times in practitioner local time, convert to UTC
    let curH = startH, curM = startM;

    while (curH < endH || (curH === endH && curM < endM)) {
        // Build an ISO date string in practitioner tz
        const dateStr   = date.toLocaleDateString('en-CA'); // YYYY-MM-DD
        const timeStr   = `${String(curH).padStart(2,'0')}:${String(curM).padStart(2,'0')}:00`;
        const localIso  = `${dateStr}T${timeStr}`;

        // Convert to UTC
        const utcMs     = localToUtcMs(localIso, practTz);
        if (utcMs === null) { ({ curH, curM } = advance60(curH, curM)); continue; }
        const utcDate   = new Date(utcMs);

        // Skip past slots
        if (utcDate <= new Date()) { ({ curH, curM } = advance60(curH, curM)); continue; }

        // Skip booked
        if (bookedSlots.has(utcMs)) { ({ curH, curM } = advance60(curH, curM)); continue; }

        // User-local display — uses confirmed timezone (overridable via "Not your timezone?" link)
        const userLabel = utcDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone: confirmedClientTz });
        const practLabel = utcDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone: practTz });

        slots.push({ utcMs, utcIso: utcDate.toISOString(), userLabel, practLabel });
        ({ curH, curM } = advance60(curH, curM));
    }
    return slots;
}

function advance60(h, m) {
    m += 60;
    h += Math.floor(m / 60);
    m  = m % 60;
    return { curH: h, curM: m };
}

function localToUtcMs(localIso, tz) {
    // Use Intl to figure out UTC offset for this local datetime in practitioner tz
    try {
        // Parse the local datetime as if it were UTC, then adjust
        const naiveUtc  = new Date(localIso + 'Z');
        const parts     = new Intl.DateTimeFormat('en-US', {
            timeZone: tz,
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false
        }).formatToParts(naiveUtc);
        const p = {};
        parts.forEach(x => p[x.type] = x.value);
        const asLocal = new Date(`${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}:${p.second}Z`);
        const offset  = asLocal - naiveUtc;
        return naiveUtc.getTime() - offset;
    } catch(e) {
        return null;
    }
}

function selectDate(btn, slots) {
    document.querySelectorAll('.bk-date-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const timeEl = document.getElementById('time-slots');
    timeEl.innerHTML = '';
    timeEl.style.display = 'flex';

    slots.forEach(slot => {
        const tb = document.createElement('button');
        tb.type = 'button';
        tb.className = 'bk-time-btn';
        tb.textContent = slot.userLabel;
        tb.addEventListener('click', (e) => selectSlot(slot, btn.dataset.date, e));
        timeEl.appendChild(tb);
    });
}

function selectSlot(slot, dateStr, e) {
    document.querySelectorAll('.bk-time-btn').forEach(b => b.classList.remove('active'));
    if (e && e.currentTarget) e.currentTarget.classList.add('active');
    else if (e && e.target) e.target.classList.add('active');

    selectedSlotUtc   = slot.utcIso;
    const d = new Date(slot.utcMs);
    const dateLabel = d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
    selectedSlotLabel = `${dateLabel} at ${slot.userLabel}`;

    document.getElementById('selected-slot-label').textContent = selectedSlotLabel;
    document.getElementById('selected-slot-display').style.display = 'block';
    document.getElementById('step-next-btn-wrap').style.display    = 'block';
}

function clearSlotSelection() {
    selectedSlotUtc   = null;
    selectedSlotLabel = '';
    document.querySelectorAll('.bk-time-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('selected-slot-display').style.display = 'none';
    document.getElementById('step-next-btn-wrap').style.display    = 'none';
}

function goToStep2() {
    if (!selectedSlotUtc) return;
    showStep(2);
}

function handleBookUpload(input) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const prev = document.getElementById('book-upload-preview');
        if (prev) prev.src = e.target.result;
        const wrap = document.getElementById('book-photo-preview');
        if (wrap) wrap.style.display = 'flex';
        const lbl = document.getElementById('book-upload-label');
        if (lbl) lbl.textContent = input.files[0].name;
    };
    reader.readAsDataURL(input.files[0]);
}

function goToStep1() {
    showStep(1);
}

function goToAgreement() {
    const name      = document.getElementById('book-name').value.trim();
    const fileInput = document.getElementById('book-avatar-upload');
    const errEl     = document.getElementById('book-error');
    const wantsAcct = document.getElementById('wants-account').value === '1';
    const pass      = document.getElementById('account-pass').value;
    const email     = document.getElementById('book-email').value.trim();
    errEl.style.display = 'none';
    if (!name) { errEl.textContent = 'Please enter your name.'; errEl.style.display = 'block'; return; }
    if (!fileInput.files || !fileInput.files[0]) { errEl.textContent = 'Please upload a photo to continue.'; errEl.style.display = 'block'; return; }
    if (wantsAcct && (pass.length < 10 || !/[A-Z]/.test(pass) || !/[a-z]/.test(pass) || !/[0-9]/.test(pass))) { errEl.textContent = 'Password must be at least 10 characters with uppercase, lowercase, and a number.'; errEl.style.display = 'block'; return; }
    if (wantsAcct && !email) { errEl.textContent = 'An email address is required to create an account.'; errEl.style.display = 'block'; return; }
    // Show special category consent row only if user provided those fields
    const gender    = document.getElementById('book-gender')?.value   || '';
    const sexuality = document.getElementById('book-sexuality')?.value || '';
    const dob       = document.getElementById('book-dob')?.value       || '';
    const specialRow = document.getElementById('special-consent-row');
    if (specialRow) {
        const hasSpecialData = gender || sexuality || dob;
        specialRow.style.display = hasSpecialData ? '' : 'none';
        const scCb = document.getElementById('special-data-consent');
        if (scCb) scCb.checked = false;
    }
    showStep(3);
}

function aiConsentToggle() {
    const allowed = document.getElementById('ai-consent-allowed');
    const scopes  = document.getElementById('ai-scope-consents');
    if (scopes) scopes.style.display = (allowed && allowed.checked) ? 'block' : 'none';
}

function goBackToForm() {
    showStep(2);
}


function toggleAccountFields() {
    const hiddenInput = document.getElementById('wants-account');
    const fields      = document.getElementById('account-fields');
    const btn         = document.getElementById('btn-create-account');
    const isOpen      = hiddenInput.value === '1';
    hiddenInput.value = isOpen ? '0' : '1';
    fields.style.display = isOpen ? 'none' : 'block';
    if (btn) {
        btn.classList.toggle('bk-btn-primary', !isOpen);
        btn.classList.toggle('bk-btn-ghost',   isOpen);
    }
}

// ── Submit booking ─────────────────────────────────────────────
async function submitBooking() {
    const name         = document.getElementById('book-name').value.trim();
    const email        = document.getElementById('book-email').value.trim();
    const fileInput    = document.getElementById('book-avatar-upload');
    const wantsAccount = document.getElementById('wants-account').value === '1';
    const pass         = document.getElementById('account-pass').value;
    const errEl        = document.getElementById('agreement-error');
    const consent      = document.getElementById('agreement-consent');

    errEl.style.display = 'none';

    if (!consent || !consent.checked) {
        errEl.textContent = 'Please read and accept the agreement to continue.';
        errEl.style.display = 'block';
        return;
    }
    const specialConsentRow = document.getElementById('special-consent-row');
    const specialConsent    = document.getElementById('special-data-consent');
    if (specialConsentRow && specialConsentRow.style.display !== 'none' && specialConsent && !specialConsent.checked) {
        errEl.textContent = 'Please give explicit consent for the processing of your special category data to continue, or go back and leave those fields blank.';
        errEl.style.display = 'block';
        return;
    }
    if (!selectedSlotUtc) {
        errEl.textContent = 'No time slot selected. Please go back and select one.';
        errEl.style.display = 'block';
        return;
    }

    // Format UTC for PHP: "Y-m-d H:i:s"
    const dt        = new Date(selectedSlotUtc);
    const scheduled = dt.toISOString().replace('T',' ').replace(/\.\d+Z/,'');

    const fd = new FormData();
    fd.append('action',           'create');
    fd.append('practitioner_id',  PRACTITIONER_ID);
    fd.append('scheduled_at_utc', scheduled);
    fd.append('guest_name',       name);
    fd.append('guest_email',      email);
    fd.append('avatar_upload',    fileInput.files[0]);
    fd.append('wants_account',    wantsAccount ? '1' : '0');
    fd.append('account_password', pass);
    fd.append('gender',           document.getElementById('book-gender')?.value   || '');
    fd.append('sexuality',        document.getElementById('book-sexuality')?.value || '');
    fd.append('date_of_birth',    document.getElementById('book-dob')?.value       || '');
    fd.append('location',         document.getElementById('book-location')?.value  || '');
    fd.append('special_data_consent', (specialConsent && specialConsent.checked) ? '1' : '0');
    // AI consent
    const aiAllowed = document.getElementById('ai-consent-allowed');
    if (aiAllowed) {
      fd.append('ai_consent_allowed', aiAllowed.checked ? '1' : '0');
      document.querySelectorAll('.ai-scope-consent-cb').forEach(cb => {
        fd.append('ai_consent_scope_' + cb.dataset.scope, (aiAllowed.checked && cb.checked) ? '1' : '0');
      });
    }
    if (appliedDiscountCode) fd.append('discount_code', appliedDiscountCode.code);

    try {
        const res = await fetch('/api/bookings.php', {
            method: 'POST',
            body: fd,
        });
        const data = await res.json();
        if (!res.ok) {
            errEl.textContent = data.error || 'Could not submit booking. Please try again.';
            errEl.style.display = 'block';
            return;
        }
        // Show confirmation
        showStep(4);
        const msg = email
            ? `Your request has been sent to <?= htmlspecialchars($pract['display_name']) ?>. Confirmation will come to ${email}.`
            : `Your request has been sent to <?= htmlspecialchars($pract['display_name']) ?>. They will confirm your session shortly.`;
        document.getElementById('confirm-message').textContent = msg;
        // Adjust step 2 text based on whether email was provided
        const step2 = document.getElementById('confirm-step2');
        if (step2) {
            step2.innerHTML = email
                ? `<strong style="color:#dddcf2;">You'll receive your session link at <span style="color:#7c6af7;">${email}</span></strong> once your practitioner confirms. Check your spam folder too.`
                : `<strong style="color:#dddcf2;">Your practitioner will send your session link directly.</strong> Make sure they have a way to reach you.`;
        }

        // Surface Advanced-mode pay-now buttons if any are configured.
        if (data.booking_token) {
            loadCheckoutOptions(data.booking_token);
        }
    } catch(e) {
        document.getElementById('agreement-error').textContent = 'Network error. Please try again.';
        document.getElementById('agreement-error').style.display = 'block';
    }
}

// ── Discount code ──────────────────────────────────────────────
async function applyDiscountCode() {
    const input    = document.getElementById('book-discount-code');
    const feedback = document.getElementById('book-discount-feedback');
    const applyBtn = document.getElementById('book-discount-apply-btn');
    if (!input || !feedback) return;

    const code = input.value.trim().toUpperCase();
    if (!code) return;

    applyBtn.disabled = true;
    applyBtn.textContent = 'Checking…';
    feedback.style.display = 'none';
    appliedDiscountCode = null;
    updateRateSidebarDiscount(0);

    try {
        const res  = await fetch(`/api/discount_codes.php?action=validate&practitioner_id=${PRACTITIONER_ID}&code=${encodeURIComponent(code)}`);
        const data = await res.json();
        if (data.valid) {
            appliedDiscountCode = { code, pct: data.discount_pct };
            const disc     = Math.round(RATE_AMOUNT * (data.discount_pct / 100) * 100) / 100;
            const newPrice = Math.round((RATE_AMOUNT - disc) * 100) / 100;
            const savings  = data.discount_pct === 100
                ? 'Session is free with this code.'
                : `${RATE_SYMBOL}${disc.toFixed(2)} off — new total: ${data.discount_pct === 100 ? 'Free' : RATE_SYMBOL + newPrice.toFixed(2)}`;
            feedback.style.cssText = 'display:block;background:rgba(76,175,135,0.12);border:1px solid rgba(76,175,135,0.25);color:#4caf87;margin-top:8px;font-size:13px;border-radius:6px;padding:8px 12px;';
            feedback.textContent   = `✓ ${data.discount_pct}% discount applied. ${savings}`;
            updateRateSidebarDiscount(data.discount_pct);
            input.disabled = true;
            applyBtn.textContent = 'Applied';
        } else {
            feedback.style.cssText = 'display:block;background:rgba(224,92,92,0.1);border:1px solid rgba(224,92,92,0.25);color:#e05c5c;margin-top:8px;font-size:13px;border-radius:6px;padding:8px 12px;';
            feedback.textContent   = data.error || 'Code not valid.';
            applyBtn.disabled    = false;
            applyBtn.textContent = 'Apply';
        }
    } catch(e) {
        feedback.style.cssText = 'display:block;background:rgba(224,92,92,0.1);border:1px solid rgba(224,92,92,0.25);color:#e05c5c;margin-top:8px;font-size:13px;border-radius:6px;padding:8px 12px;';
        feedback.textContent   = 'Could not check code. Please try again.';
        applyBtn.disabled    = false;
        applyBtn.textContent = 'Apply';
    }
}

function clearDiscountCode() {
    appliedDiscountCode = null;
    updateRateSidebarDiscount(0);
    const applyBtn = document.getElementById('book-discount-apply-btn');
    const feedback = document.getElementById('book-discount-feedback');
    const input    = document.getElementById('book-discount-code');
    if (applyBtn) { applyBtn.disabled = false; applyBtn.textContent = 'Apply'; }
    if (feedback) feedback.style.display = 'none';
    if (input)    input.disabled = false;
}

function updateRateSidebarDiscount(pct) {
    // Update the rate display in the left sidebar if it exists
    const origEl = document.querySelector('.bk-rate-orig, .bk-rate-amount, .bk-rate-free');
    if (!origEl || !RATE_AMOUNT) return;

    let discRow = document.getElementById('bk-code-disc-row');
    if (pct > 0) {
        const newPrice = pct === 100 ? 'Free' : RATE_SYMBOL + (Math.round((RATE_AMOUNT * (1 - pct/100)) * 100)/100).toFixed(2);
        if (!discRow) {
            discRow = document.createElement('div');
            discRow.id = 'bk-code-disc-row';
            discRow.style.cssText = 'margin-top:6px;font-size:12px;color:#4caf87;background:rgba(76,175,135,0.1);border-radius:5px;padding:4px 8px;display:inline-block;';
            origEl.closest('.bk-rate-block')?.appendChild(discRow);
        }
        discRow.textContent = `Code: ${pct}% off → ${newPrice}`;
    } else if (discRow) {
        discRow.remove();
    }
}

// ── Init ───────────────────────────────────────────────────────
loadAvailability();

// ── Expandable about ──────────────────────────────────────────
function toggleBkAbout() {
  const el  = document.getElementById('bk-about-text');
  const btn = document.getElementById('bk-about-toggle');
  if (!el || !btn) return;
  const collapsed = el.classList.toggle('collapsed');
  btn.textContent = collapsed ? 'Read more ↓' : 'Show less ↑';
}

// ── Shooting stars ────────────────────────────────────────────
(function() {
  const rnd = (a, b) => a + Math.random() * (b - a);
  const skyEl = document.getElementById('sky-canvas-bk');
  function spawnShootingStar() {
    if (!skyEl) return;
    const el = document.createElement('div');
    el.className = 'shooting-star';
    const angle  = -(12 + rnd(0, 22));
    const length = 90 + rnd(0, 110);
    const dist   = 260 + rnd(0, 200);
    const dur    = (0.45 + rnd(0, 0.4)).toFixed(2);
    el.style.cssText = `width:${length.toFixed(0)}px;top:${rnd(5,62).toFixed(1)}%;left:${rnd(5,78).toFixed(1)}%;--shoot-angle:${angle.toFixed(1)}deg;--shoot-dist:${dist.toFixed(0)}px;--shoot-dur:${dur}s`;
    skyEl.appendChild(el);
    setTimeout(() => el.remove(), parseFloat(dur) * 1000 + 80);
    setTimeout(spawnShootingStar, 3000 + rnd(0, 2000));
  }
  setTimeout(spawnShootingStar, 1200 + rnd(0, 1800));
})();

// ── Inline handler replacements ──────────────────────────────
document.getElementById('bk-about-toggle')?.addEventListener('click', toggleBkAbout);
document.getElementById('bk-slot-change-btn')?.addEventListener('click', clearSlotSelection);
document.getElementById('bk-step2-btn')?.addEventListener('click', goToStep2);
document.getElementById('book-avatar-upload')?.addEventListener('change', function() { handleBookUpload(this); });
document.getElementById('btn-create-account')?.addEventListener('click', toggleAccountFields);
document.getElementById('book-discount-code')?.addEventListener('input', function() {
    this.value = this.value.toUpperCase();
    clearDiscountCode();
});
document.getElementById('book-discount-apply-btn')?.addEventListener('click', applyDiscountCode);
document.getElementById('bk-back-btn')?.addEventListener('click', goToStep1);
document.getElementById('bk-agreement-btn')?.addEventListener('click', goToAgreement);
document.getElementById('ai-consent-allowed')?.addEventListener('change', aiConsentToggle);
document.getElementById('bk-back-to-form-btn')?.addEventListener('click', goBackToForm);
document.getElementById('bk-submit-btn')?.addEventListener('click', submitBooking);

// ── Hosted-checkout (Advanced mode) integration ──────────────
async function loadCheckoutOptions(bookingToken) {
    const block   = document.getElementById('bk-pay-block');
    const amountEl= document.getElementById('bk-pay-amount');
    const btnsEl  = document.getElementById('bk-pay-buttons');
    if (!block || !btnsEl) return;
    try {
        const res  = await fetch('/api/payments.php?action=checkout_options&token=' + encodeURIComponent(bookingToken));
        const data = await res.json();
        if (!data.ok || data.is_free || !Array.isArray(data.available) || data.available.length === 0) {
            return;  // silent — no advanced gateways, fall back to existing manual flow
        }

        // Format amount with the practitioner's currency.
        const sym = currencySymbol(data.currency);
        amountEl.textContent = sym + Number(data.amount).toFixed(2) + ' ' + data.currency;

        btnsEl.innerHTML = '';
        data.available.forEach(opt => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'bk-btn bk-btn-primary';
            btn.style.justifyContent = 'center';
            btn.textContent = 'Pay with ' + opt.label;
            btn.dataset.gateway = opt.gateway;
            btn.dataset.token   = bookingToken;
            btn.addEventListener('click', startCheckout);
            btnsEl.appendChild(btn);
        });
        block.style.display = 'block';
    } catch (e) {
        // Silent — booking still succeeded; manual payment flow remains available
    }
}

async function startCheckout(ev) {
    const btn   = ev.currentTarget;
    const token = btn.dataset.token;
    const gw    = btn.dataset.gateway;
    const errEl = document.getElementById('bk-pay-error');
    if (errEl) errEl.style.display = 'none';

    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Connecting…';

    try {
        const fd = new FormData();
        fd.append('action', gw + '_checkout');
        fd.append('token',  token);
        const res  = await fetch('/api/payments.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok && data.url) {
            window.location.href = data.url;
            return;  // redirect — no need to restore button
        }
        throw new Error(data.error || 'Could not start checkout');
    } catch (e) {
        btn.disabled = false;
        btn.textContent = orig;
        if (errEl) {
            errEl.textContent = e.message || 'Could not start checkout. Please try again.';
            errEl.style.display = 'block';
        }
    }
}

function currencySymbol(code) {
    const map = { USD:'$', GBP:'£', EUR:'€', CAD:'C$', AUD:'A$', NZD:'NZ$',
                  JPY:'¥', CHF:'Fr ', SEK:'kr ', NOK:'kr ', DKK:'kr ',
                  PLN:'zł ', CZK:'Kč ', HUF:'Ft ', BRL:'R$', MXN:'$',
                  INR:'₹', SGD:'S$', HKD:'HK$', PHP:'₱', ZAR:'R' };
    return map[(code||'').toUpperCase()] || '';
}

// Handle return from a hosted checkout (?paid=1 or ?cancelled=1) — show a
// short banner above the directory link in step 4.
(function handleCheckoutReturn(){
    const params = new URLSearchParams(location.search);
    if (params.get('paid') !== '1' && params.get('cancelled') !== '1') return;
    showStep(4);
    const msgEl = document.getElementById('confirm-message');
    const block = document.getElementById('bk-pay-block');
    if (params.get('paid') === '1') {
        if (msgEl) msgEl.innerHTML = '<strong style="color:#3ecf8e;">Payment received.</strong> Your session is confirmed — your practitioner has been notified.';
        if (block) block.style.display = 'none';
    } else {
        if (msgEl) msgEl.innerHTML = '<strong style="color:#f5c842;">Payment was cancelled.</strong> Your booking request is still on file — you can pay later from your confirmation email.';
    }
})();
</script>
</body>
</html>
