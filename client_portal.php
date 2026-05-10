<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
if (empty($_SESSION['end_user_id'])) {
    header('Location: /login.php?tab=client');
    exit;
}
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/banners.php';
require_once __DIR__ . '/includes/session_timeout.php';
require_once __DIR__ . '/includes/geo.php';
requireSetup();

$userId   = (int)$_SESSION['end_user_id'];
$pdo      = getDB();
$appTheme = getAppTheme();
$appLogo  = getAppLogo();

$stmt = $pdo->prepare(
    'SELECT id, email, display_name, avatar_path, avatar_preset, must_change_password, created_at, mfa_secret FROM end_users WHERE id = ? LIMIT 1'
);
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) {
    session_destroy();
    header('Location: /login.php?tab=client');
    exit;
}

$mustChange = !empty($user['must_change_password']) || isset($_GET['must_change']);
$myName     = $user['display_name'];
$myAvatar   = $user['avatar_path'] ?: '';

// AI Integration — show AI Agreements tab only if platform has AI enabled
$aiPlatformEnabled = getSetting('ai_enabled', '0') === '1';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Portal — Serenity Spaces</title>
  <link rel="stylesheet" href="/assets/css/fonts.css">
  <link rel="stylesheet" href="/assets/css/main.css">
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
    /* ── Shared design system (mirrors dashboard) ── */
    :root {
      --font:        'Outfit', -apple-system, sans-serif;
      --bg:          #080910;
      --sidebar-bg:  #060710;
      --surface:     #0f1020;
      --surface2:    #14152a;
      --surface3:    #1a1c32;
      --border:      rgba(255,255,255,0.07);
      --border-hi:   rgba(255,255,255,0.13);
      --accent:      #7c6af7;
      --accent-dim:  rgba(124,106,247,0.14);
      --accent-glow: rgba(124,106,247,0.28);
      --text:        #dddcf2;
      --text-muted:  rgba(221,220,242,0.44);
      --text-dim:    rgba(221,220,242,0.26);
      --success:     #5db87c;
      --warning:     #e8ad4a;
      --danger:      #e05252;
      --danger-dim:  rgba(224,82,82,0.12);
      --sidebar-w:   220px;
      --topbar-h:    52px;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
      height: 100%;
      overflow: hidden;
      background: var(--bg);
      color: var(--text);
      font-family: var(--font);
      font-size: 14px;
      line-height: 1.5;
    }

    body.theme-light {
      --bg:          #f0f0f8;
      --sidebar-bg:  #eae9f6;
      --surface:     #ffffff;
      --surface2:    #f5f4fc;
      --surface3:    #edeaf8;
      --border:      rgba(0,0,0,0.08);
      --border-hi:   rgba(0,0,0,0.14);
      --text:        #1a1830;
      --text-muted:  rgba(26,24,48,0.5);
      --text-dim:    rgba(26,24,48,0.3);
      --accent:      #6355e0;
      --accent-dim:  rgba(99,85,224,0.1);
      --accent-glow: rgba(99,85,224,0.22);
      --danger-dim:  rgba(200,60,60,0.08);
    }

    /* ── Layout ── */
    .cms-wrap {
      display: grid;
      grid-template-columns: var(--sidebar-w) 1fr;
      height: 100vh;
      overflow: hidden;
    }

    /* ── Sidebar ── */
    .cms-sidebar {
      background: var(--sidebar-bg);
      border-right: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      height: 100vh;
      overflow-y: auto;
    }

    .sidebar-brand {
      height: var(--topbar-h);
      display: flex;
      align-items: center;
      padding: 0 18px;
      border-bottom: 1px solid var(--border);
      flex-shrink: 0;
      gap: 10px;
    }

    .sidebar-brand .brand-name {
      font-size: 15px; font-weight: 700; color: var(--text); letter-spacing: -0.3px;
    }

    .portal-badge {
      font-size: 9px; font-weight: 600; letter-spacing: 0.6px; text-transform: uppercase;
      color: var(--accent); background: var(--accent-dim);
      border: 1px solid rgba(124,106,247,0.2); border-radius: 20px; padding: 2px 7px;
    }

    .sidebar-nav { flex: 1; padding: 14px 10px; }
    .nav-group { margin-bottom: 20px; }
    .nav-group-label {
      font-size: 10px; font-weight: 600; letter-spacing: 0.9px;
      text-transform: uppercase; color: var(--text-dim);
      padding: 0 10px; margin-bottom: 3px; display: block;
    }

    .nav-item {
      display: flex; align-items: center; gap: 10px;
      padding: 8px 10px; border-radius: 8px;
      font-size: 13.5px; font-weight: 500; color: var(--text-muted);
      cursor: pointer; transition: background 0.14s, color 0.14s;
      text-decoration: none; border: none; background: transparent;
      width: 100%; text-align: left; font-family: var(--font);
      position: relative; margin-bottom: 1px;
    }
    .nav-item:hover { background: rgba(255,255,255,0.045); color: var(--text); }
    .nav-item.active { background: var(--accent-dim); color: var(--accent); font-weight: 600; }
    .nav-item.active::before {
      content: ''; position: absolute; left: 0; top: 20%; height: 60%; width: 2px;
      background: var(--accent); border-radius: 0 2px 2px 0;
    }
    .nav-icon { width: 17px; height: 17px; flex-shrink: 0; opacity: 0.65; }
    .nav-item.active .nav-icon, .nav-item:hover .nav-icon { opacity: 1; }

    .sidebar-footer { padding: 10px 10px 14px; border-top: 1px solid var(--border); flex-shrink: 0; }

    /* ── Main ── */
    .cms-body { display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

    /* ── Topbar ── */
    .cms-topbar {
      height: var(--topbar-h); background: var(--sidebar-bg);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 28px; flex-shrink: 0;
    }

    .topbar-left { display: flex; align-items: center; gap: 7px; font-size: 13.5px; }
    .bc-root { color: var(--text-muted); }
    .bc-sep  { color: var(--text-dim); font-size: 11px; }
    .bc-curr { font-weight: 600; color: var(--text); }

    .topbar-right { display: flex; align-items: center; gap: 10px; }

    .user-chip {
      display: flex; align-items: center; gap: 8px;
      padding: 5px 12px 5px 6px;
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 20px; cursor: default;
    }
    .user-chip-av {
      width: 24px; height: 24px; border-radius: 50%;
      object-fit: cover; flex-shrink: 0;
    }
    .user-chip-ph {
      width: 24px; height: 24px; border-radius: 50%;
      background: var(--surface3); flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 10px; font-weight: 700; color: var(--accent);
    }
    .user-chip-name { font-size: 12.5px; font-weight: 500; color: var(--text); }

    .btn-signout {
      background: transparent; border: 1px solid var(--border);
      border-radius: 8px; padding: 6px 12px; font-size: 12.5px;
      color: var(--text-muted); cursor: pointer; font-family: var(--font);
      transition: border-color 0.15s, color 0.15s;
    }
    .btn-signout:hover { border-color: var(--border-hi); color: var(--text); }

    /* ── Content ── */
    .cms-content { flex: 1; overflow-y: auto; padding: 28px 36px; scroll-behavior: smooth; }

    /* ── Sections ── */
    .portal-section { display: none; }
    .portal-section.active { display: block; animation: fadeUp 0.18s ease; }
    @keyframes fadeUp { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }

    /* ── Section header ── */
    .sec-hdr { margin-bottom: 24px; }
    .sec-hdr h1 { font-size: 20px; font-weight: 700; color: var(--text); letter-spacing: -0.4px; }
    .sec-hdr p  { font-size: 13px; color: var(--text-muted); margin-top: 3px; }

    /* ── Panel ── */
    .panel {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 14px; margin-bottom: 20px; overflow: hidden;
    }
    .panel-hdr {
      padding: 14px 20px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }
    .panel-title { font-size: 13px; font-weight: 600; color: var(--text); }
    .panel-body  { padding: 20px; }

    /* ── Buttons ── */
    .btn-c {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; border-radius: 8px;
      border: 1px solid var(--border-hi);
      font-size: 13px; font-weight: 500; cursor: pointer;
      font-family: var(--font); background: var(--surface);
      color: var(--text); transition: background 0.14s;
      text-decoration: none; white-space: nowrap;
    }
    .btn-c:hover { background: var(--surface2); }
    .btn-c.primary {
      background: var(--accent); border-color: var(--accent); color: #fff;
      box-shadow: 0 2px 14px var(--accent-glow);
    }
    .btn-c.primary:hover { background: #6a58e8; }
    .btn-c.primary:disabled { opacity: 0.4; cursor: not-allowed; }
    .btn-c.ghost { background: transparent; color: var(--text-muted); border-color: var(--border); }
    .btn-c.ghost:hover { background: rgba(255,255,255,0.04); color: var(--text); }
    .btn-c.sm { padding: 6px 12px; font-size: 12px; border-radius: 7px; }
    .btn-c.danger-outline {
      background: transparent; border-color: rgba(224,82,82,0.25);
      color: var(--danger);
    }
    .btn-c.danger-outline:hover { background: var(--danger-dim); border-color: var(--danger); }

    /* ── Session card (upcoming) ── */
    .session-card {
      background: var(--surface2); border: 1px solid var(--border);
      border-radius: 12px; padding: 18px 20px;
      display: flex; align-items: flex-start; gap: 18px;
      margin-bottom: 14px; transition: border-color 0.2s;
    }
    .session-card:hover { border-color: rgba(124,106,247,0.25); }

    .session-thumb {
      width: 80px; height: 54px; border-radius: 8px;
      object-fit: cover; flex-shrink: 0;
      border: 1px solid var(--border);
    }
    .session-thumb-ph {
      width: 80px; height: 54px; border-radius: 8px;
      background: linear-gradient(135deg, var(--surface3), var(--surface2));
      border: 1px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      font-size: 20px; flex-shrink: 0;
    }

    .session-info { flex: 1; min-width: 0; }
    .session-practitioner { font-size: 12.5px; color: var(--text-muted); margin-bottom: 4px; }
    .session-time { font-size: 16px; font-weight: 600; color: var(--text); margin-bottom: 2px; }
    .session-date { font-size: 13px; color: var(--text-muted); }
    .session-note { font-size: 12px; color: var(--success); font-weight: 600; margin-top: 4px; }
    .session-actions { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }

    .status-pill {
      display: inline-flex; align-items: center; gap: 4px;
      font-size: 10px; font-weight: 600; letter-spacing: 0.4px;
      text-transform: uppercase; padding: 2px 8px; border-radius: 20px;
    }
    .status-pill.pending   { background: rgba(232,173,74,0.12); color: var(--warning); }
    .status-pill.scheduled { background: var(--accent-dim); color: var(--accent); }
    .status-pill.live      { background: rgba(93,184,124,0.12); color: var(--success); }

    /* ── Past session rows ── */
    .past-row {
      display: flex; align-items: center; gap: 16px;
      padding: 14px 0; border-bottom: 1px solid var(--border);
      transition: opacity 0.15s;
    }
    .past-row:last-child { border-bottom: none; }

    .past-thumb {
      width: 54px; height: 38px; border-radius: 6px;
      object-fit: cover; border: 1px solid var(--border); flex-shrink: 0;
    }
    .past-thumb-ph {
      width: 54px; height: 38px; border-radius: 6px;
      background: var(--surface3); border: 1px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; flex-shrink: 0;
    }

    .past-info { flex: 1; min-width: 0; }
    .past-meta { font-size: 11.5px; color: var(--text-muted); margin-bottom: 2px; }
    .past-date { font-size: 13.5px; font-weight: 600; color: var(--text); }
    .past-dur  { font-size: 12px; color: var(--text-muted); margin-top: 1px; }

    /* ── Stars ── */
    .stars { display: flex; gap: 4px; cursor: pointer; }
    .star { font-size: 20px; color: var(--border); transition: color 0.12s, transform 0.1s; cursor: pointer; user-select: none; }
    .star.filled { color: var(--warning); }
    .star:hover, .star.hover { color: var(--warning); transform: scale(1.15); }
    .stars-small { display: flex; gap: 2px; }
    .star-sm { font-size: 13px; color: var(--border); }
    .star-sm.filled { color: var(--warning); }

    /* ── Forms ── */
    .f-group { margin-bottom: 18px; }
    .f-label {
      display: block; font-size: 12px; font-weight: 600;
      color: var(--text-muted); margin-bottom: 6px; letter-spacing: 0.2px;
    }
    .f-input {
      width: 100%; background: var(--surface2); border: 1px solid var(--border);
      border-radius: 9px; padding: 10px 14px; font-size: 14px;
      color: var(--text); outline: none; font-family: var(--font);
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    .f-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(124,106,247,0.1); }
    .f-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 580px) { .f-row { grid-template-columns: 1fr; } }

    /* ── Avatar section ── */
    .avatar-section {
      display: flex; align-items: center; gap: 20px;
      padding: 18px 20px; background: var(--surface2);
      border-bottom: 1px solid var(--border);
    }
    .av-preview {
      width: 68px; height: 68px; border-radius: 50%;
      object-fit: cover; border: 2.5px solid var(--border-hi); flex-shrink: 0;
    }
    .av-preview-ph {
      width: 68px; height: 68px; border-radius: 50%;
      background: var(--surface3); border: 2.5px solid var(--border-hi);
      display: flex; align-items: center; justify-content: center;
      font-size: 26px; font-weight: 700; color: var(--accent); flex-shrink: 0;
    }

    /* ── Strength bar ── */
    .strength-bar { height: 3px; background: var(--border); border-radius: 2px; margin-top: 8px; overflow: hidden; }
    .strength-fill { height: 100%; border-radius: 2px; transition: width 0.3s, background 0.3s; }

    /* ── Alerts ── */
    .alert { border-radius: 10px; padding: 11px 16px; font-size: 13.5px; margin-bottom: 18px; }
    .alert-success { background: rgba(93,184,124,0.09); border: 1px solid rgba(93,184,124,0.22); color: #7ecf96; }
    .alert-error   { background: rgba(224,82,82,0.09);  border: 1px solid rgba(224,82,82,0.22);  color: #e88080; }

    /* ── Empty state ── */
    .empty-c { text-align: center; padding: 48px 24px; color: var(--text-muted); }
    .empty-c-icon { font-size: 34px; margin-bottom: 12px; opacity: 0.45; }
    .empty-c h3 { font-size: 16px; color: var(--text); margin-bottom: 6px; font-weight: 600; }
    .empty-c p  { font-size: 13px; line-height: 1.6; max-width: 360px; margin: 0 auto 18px; }

    /* ── Spinner ── */
    .spinner {
      display: inline-block; width: 18px; height: 18px;
      border: 2px solid rgba(255,255,255,0.15); border-top-color: var(--accent);
      border-radius: 50%; animation: spin 0.7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Toast ── */
    .toast {
      position: fixed; bottom: 24px; right: 24px;
      background: var(--surface2); border: 1px solid var(--border-hi);
      border-radius: 10px; padding: 12px 18px;
      font-size: 13.5px; color: var(--text);
      box-shadow: 0 8px 32px rgba(0,0,0,0.45);
      z-index: 2000; transform: translateY(80px); opacity: 0;
      transition: transform 0.28s cubic-bezier(0.22,1,0.36,1), opacity 0.28s;
      pointer-events: none;
    }
    .toast.show { transform: translateY(0); opacity: 1; }

    /* ── Modal ── */
    .modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,0.65); z-index: 500;
      align-items: center; justify-content: center; padding: 20px;
    }
    .modal-overlay.open { display: flex; }
    .modal {
      background: var(--surface); border: 1px solid var(--border-hi);
      border-radius: 16px; padding: 28px;
      width: 100%; max-width: 440px;
    }
    .modal h3 { font-size: 17px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
    .modal p  { font-size: 13px; color: var(--text-muted); margin-bottom: 20px; line-height: 1.5; }
    .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }

    /* ── Must-change overlay ── */
    #mustChangeOverlay {
      position: fixed; inset: 0; background: rgba(0,0,0,0.88);
      z-index: 900; display: flex; align-items: center;
      justify-content: center; padding: 20px;
    }
    #mustChangeOverlay h3 { color: var(--accent); }

    /* ── My Notes ── */
    .notes-appt-card {
      background: var(--surface); border: 1px solid var(--border); border-radius: 12px;
      padding: 20px; margin-bottom: 16px;
    }
    .notes-appt-meta {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 12px; flex-wrap: wrap; gap: 8px;
    }
    .notes-appt-title { font-size: 14px; font-weight: 600; color: var(--text); }
    .notes-appt-date  { font-size: 12px; color: var(--text-muted); }
    .notes-appt-badge {
      font-size: 11px; padding: 2px 8px; border-radius: 20px;
      background: rgba(124,106,247,0.15); color: var(--accent); font-weight: 500;
    }
    .notes-appt-badge.upcoming { background: rgba(74,222,128,0.12); color: #4ade80; }
    .client-note-ta {
      width: 100%; min-height: 90px; background: rgba(255,255,255,0.03);
      border: 1px solid var(--border); border-radius: 8px;
      color: var(--text); font-family: var(--font); font-size: 13px;
      line-height: 1.6; padding: 10px 12px; resize: vertical; box-sizing: border-box;
      transition: border-color 0.15s;
    }
    .client-note-ta:focus { outline: none; border-color: rgba(124,106,247,0.5); }
    .client-note-ta::placeholder { color: var(--text-muted); }
    .client-note-save-row {
      display: flex; align-items: center; justify-content: flex-end; gap: 8px;
      margin-top: 8px; min-height: 22px;
    }
    .client-note-status { font-size: 11px; color: var(--text-muted); transition: opacity 0.3s; }

    /* ── Find practitioner card ── */
    .find-pract-card {
      background: linear-gradient(135deg, rgba(124,106,247,0.09), rgba(100,181,246,0.05));
      border: 1px solid rgba(124,106,247,0.18);
      border-radius: 14px; padding: 32px; text-align: center;
    }
    .find-pract-card h3 { font-size: 18px; color: var(--text); margin-bottom: 10px; }
    .find-pract-card p  { font-size: 14px; color: var(--text-muted); margin-bottom: 24px; line-height: 1.6; }

    @media (max-width: 640px) {
      .cms-sidebar { display: none; }
      .cms-sidebar.mobile-open { display: flex !important; }
      .cms-wrap { grid-template-columns: 1fr; }
      .cms-content { padding: 20px 16px; }
      .topbar-hamburger { display: flex; }
    }
  </style>
</head>
<body class="theme-<?= htmlspecialchars($appTheme) ?>">
<?php renderBanners('client'); ?>

<!-- ── Must-change password overlay ── -->
<?php if ($mustChange): ?>
<div id="mustChangeOverlay">
  <div class="modal">
    <h3 style="color:var(--accent);">Welcome to Serenity Spaces</h3>
    <p style="color:var(--text-muted);font-size:14px;line-height:1.6;margin-bottom:20px;">Before you get started, please choose a personal password for your account. This is a one-time step — you'll use it to sign in from now on.</p>
    <div id="changePassError" class="alert alert-error" style="display:none;"></div>
    <div class="f-group">
      <label class="f-label" for="newPass">New password</label>
      <input type="password" id="newPass" class="f-input" placeholder="At least 12 characters">
      <div class="strength-bar" style="margin-top:8px;">
        <div class="strength-fill" id="strengthFill" style="width:0;background:var(--danger);"></div>
      </div>
    </div>
    <div class="f-group">
      <label class="f-label" for="confirmPass">Confirm new password</label>
      <input type="password" id="confirmPass" class="f-input" placeholder="Repeat your password">
    </div>
    <div class="modal-actions">
      <button class="btn-c primary" id="changePassBtn">Set Password</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="cms-wrap">

  <!-- ═══════════ SIDEBAR ═══════════ -->
  <aside class="cms-sidebar" id="cms-sidebar">

    <div class="sidebar-brand">
      <?php if ($appLogo): ?>
        <img src="<?= htmlspecialchars($appLogo) ?>" alt="Logo" class="app-logo-img" style="max-height:26px;max-width:130px;">
      <?php else: ?>
        <span class="brand-name">Serenity Spaces</span>
      <?php endif; ?>
      <span class="portal-badge">Client</span>
    </div>

    <nav class="sidebar-nav" role="tablist" aria-label="Portal navigation">
      <div class="nav-group">
        <span class="nav-group-label">My Space</span>

        <button class="nav-item active" id="nav-dashboard" data-portal-section="dashboard" role="tab" aria-selected="true" aria-controls="section-dashboard">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <rect x="1.5" y="1.5" width="6.5" height="6.5" rx="1.5"/>
            <rect x="10" y="1.5" width="6.5" height="6.5" rx="1.5"/>
            <rect x="1.5" y="10" width="6.5" height="6.5" rx="1.5"/>
            <rect x="10" y="10" width="6.5" height="6.5" rx="1.5"/>
          </svg>
          Dashboard
        </button>

        <button class="nav-item" id="nav-sessions" data-portal-section="sessions" role="tab" aria-selected="false" aria-controls="section-sessions">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <path d="M2 14V6a1 1 0 011-1h12a1 1 0 011 1v8a1 1 0 01-1 1H3a1 1 0 01-1-1z"/>
            <line x1="5" y1="9" x2="13" y2="9"/>
            <line x1="5" y1="12" x2="9" y2="12"/>
            <path d="M6 2v4M12 2v4"/>
          </svg>
          Session History
        </button>

        <button class="nav-item" id="nav-mynotes" data-portal-section="mynotes" role="tab" aria-selected="false" aria-controls="section-mynotes">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <path d="M3 2h9l3 3v11a1 1 0 01-1 1H3a1 1 0 01-1-1V3a1 1 0 011-1z"/>
            <path d="M12 2v4h3"/>
            <line x1="5" y1="8"  x2="13" y2="8"/>
            <line x1="5" y1="11" x2="13" y2="11"/>
            <line x1="5" y1="14" x2="9"  y2="14"/>
          </svg>
          My Notes
        </button>
      </div>

      <div class="nav-group">
        <span class="nav-group-label">Explore</span>
        <button class="nav-item" id="nav-find-practitioners-btn">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <circle cx="7.5" cy="7.5" r="5"/>
            <line x1="11.5" y1="11.5" x2="16" y2="16"/>
          </svg>
          Find a Practitioner
        </button>
        <button class="nav-item" id="nav-inbox" data-portal-section="inbox" role="tab" aria-selected="false" aria-controls="section-inbox">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <path d="M2 4h14v10a1 1 0 01-1 1H3a1 1 0 01-1-1V4z"/>
            <polyline points="2,4 9,10 16,4"/>
          </svg>
          Messages
          <span class="ib-badge" id="cpInboxBadge" style="display:none;"></span>
        </button>
      </div>

      <div class="nav-group">
        <span class="nav-group-label">Account</span>
        <button class="nav-item" id="nav-settings" data-portal-section="settings" role="tab" aria-selected="false" aria-controls="section-settings">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <circle cx="9" cy="9" r="2.5"/>
            <path d="M9 1v2M9 15v2M1 9h2M15 9h2M3.22 3.22l1.42 1.42M13.36 13.36l1.42 1.42M3.22 14.78l1.42-1.42M13.36 4.64l1.42-1.42"/>
          </svg>
          Settings
        </button>
        <button class="nav-item" id="nav-my-practitioners" data-portal-section="my-practitioners" role="tab" aria-selected="false" aria-controls="section-my-practitioners">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <circle cx="9" cy="5.5" r="2.5"/>
            <path d="M2.5 15.5c0-3.31 2.91-6 6.5-6s6.5 2.69 6.5 6"/>
            <path d="M13.5 10l1.5 1.5L17 9" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          My Practitioners
        </button>
        <?php if ($aiPlatformEnabled): ?>
        <button class="nav-item" id="nav-ai-agreements" data-portal-section="ai-agreements" role="tab" aria-selected="false" aria-controls="section-ai-agreements">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <path d="M7.5 12.5L7 14.5l-.5-2a3 3 0 00-2-2L2.5 10l2-.5a3 3 0 002-2L7 5.5l.5 2a3 3 0 002 2l2 .5-2 .5a3 3 0 00-2 2zM13.5 7L13 8.5l-.5-1.5a2 2 0 00-1.5-1.5L9.5 5l1.5-.5A2 2 0 0012.5 3L13 1.5 13.5 3a2 2 0 001.5 1.5l1.5.5-1.5.5A2 2 0 0013.5 7z"/>
          </svg>
          AI Agreements
        </button>
        <?php endif; ?>
      </div>
    </nav>

    <div class="sidebar-footer">
      <a href="/privacy.php" class="nav-item" style="color:rgba(255,255,255,0.28);font-size:12px;" title="Privacy Notice" target="_blank">
        <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.5;">
          <circle cx="9" cy="9" r="7.5"/>
          <line x1="9" y1="8.5" x2="9" y2="13"/>
          <circle cx="9" cy="5.5" r="0.8" fill="currentColor" stroke="none"/>
        </svg>
        Privacy Notice
      </a>
      <button class="nav-item" id="nav-request-deletion-btn" style="color:rgba(255,255,255,0.35);font-size:12px;" title="Request deletion of your personal data (GDPR)">
        <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" style="opacity:.5;">
          <polyline points="3,6 15,6"/><path d="M8 6V4h2v2"/><rect x="5" y="6" width="8" height="9" rx="1"/>
        </svg>
        Request Data Deletion
      </button>
      <button class="nav-item" id="nav-logout-btn" style="color:var(--danger);">
        <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" style="opacity:1;">
          <path d="M7 3H3a1 1 0 00-1 1v10a1 1 0 001 1h4"/>
          <polyline points="12,13 16,9 12,5"/>
          <line x1="16" y1="9" x2="6" y2="9"/>
        </svg>
        Sign Out
      </button>
    </div>

  </aside>

  <!-- ═══════════ MAIN ═══════════ -->
  <div class="cms-body">

    <header class="cms-topbar">
      <div style="display:flex;align-items:center;gap:10px;">
        <button class="topbar-hamburger" id="hamburger-btn" aria-label="Toggle menu">
          <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" width="18" height="18"><line x1="2" y1="5" x2="16" y2="5"/><line x1="2" y1="9" x2="16" y2="9"/><line x1="2" y1="13" x2="16" y2="13"/></svg>
        </button>
        <div class="topbar-left">
          <span class="bc-root">Client Portal</span>
          <span class="bc-sep">/</span>
          <span class="bc-curr" id="topbar-label">Dashboard</span>
        </div>
      </div>
      <div class="topbar-right">
        <div class="user-chip">
          <?php if ($myAvatar): ?>
            <img src="<?= htmlspecialchars($myAvatar) ?>" alt="" class="user-chip-av">
          <?php else: ?>
            <div class="user-chip-ph"><?= htmlspecialchars(mb_substr($myName,0,1)) ?></div>
          <?php endif; ?>
          <span class="user-chip-name"><?= htmlspecialchars($myName) ?></span>
        </div>
        <button class="btn-signout" id="topbar-signout-btn">Sign out</button>
      </div>
    </header>

    <main class="cms-content">

      <!-- ══════════ DASHBOARD ══════════ -->
      <section class="portal-section active" id="section-dashboard" role="tabpanel" aria-labelledby="nav-dashboard">
        <div class="sec-hdr">
          <h1>Welcome back, <?= htmlspecialchars($myName) ?></h1>
          <p>Your upcoming sessions and recent activity.</p>
        </div>

        <div class="panel">
          <div class="panel-hdr">
            <span class="panel-title">Upcoming Sessions</span>
          </div>
          <div class="panel-body" id="upcomingList">
            <div class="empty-c"><div class="spinner"></div></div>
          </div>
        </div>
      </section>

      <!-- ══════════ SESSION HISTORY ══════════ -->
      <section class="portal-section" id="section-sessions" role="tabpanel" aria-labelledby="nav-sessions">
        <div class="sec-hdr">
          <h1>Session History</h1>
          <p>Your completed sessions with Serenity Spaces practitioners.</p>
        </div>

        <div class="panel">
          <div class="panel-hdr">
            <span class="panel-title">Past Sessions</span>
          </div>
          <div class="panel-body" id="pastList">
            <div class="empty-c"><div class="spinner"></div></div>
          </div>
        </div>
      </section>

      <!-- ══════════ MY NOTES ══════════ -->
      <section class="portal-section" id="section-mynotes" role="tabpanel" aria-labelledby="nav-mynotes">
        <div class="sec-hdr">
          <h1>My Notes</h1>
          <p>Private reflection space for each appointment. These notes are visible to you only — your practitioner cannot see them.</p>
        </div>
        <div id="myNotesList">
          <div class="empty-c"><div class="spinner"></div></div>
        </div>
      </section>

      <!-- ══════════ SETTINGS ══════════ -->
      <section class="portal-section" id="section-settings" role="tabpanel" aria-labelledby="nav-settings">
        <div class="sec-hdr">
          <h1>Account Settings</h1>
          <p>Update your profile and account preferences.</p>
        </div>

        <div id="settingsAlert"></div>

        <div class="panel">
          <div class="panel-hdr">
            <span class="panel-title">Profile</span>
          </div>

          <div class="avatar-section">
            <?php if ($myAvatar): ?>
              <img src="<?= htmlspecialchars($myAvatar) ?>" alt="" class="av-preview" id="avatarPreview">
            <?php else: ?>
              <div class="av-preview-ph" id="avatarPreview"><?= htmlspecialchars(mb_substr($myName,0,1)) ?></div>
            <?php endif; ?>
            <div>
              <div style="font-size:15px;font-weight:600;color:var(--text);margin-bottom:3px;"><?= htmlspecialchars($myName) ?></div>
              <div style="font-size:13px;color:var(--text-muted);margin-bottom:12px;"><?= htmlspecialchars($user['email']) ?></div>
              <label id="cp-avatar-label" style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:var(--accent);border:1px solid rgba(124,106,247,0.28);border-radius:8px;padding:6px 12px;transition:background 0.14s;">
                <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" style="width:13px;height:13px;"><rect x="1" y="2" width="12" height="9" rx="1.5"/><circle cx="7" cy="6.5" r="2"/></svg>
                Change Photo
                <input type="file" id="avatarFile" accept="image/*" style="display:none;">
              </label>
            </div>
          </div>

          <div class="panel-body">
            <div class="f-row">
              <div class="f-group">
                <label class="f-label" for="settingName">Display name</label>
                <input type="text" id="settingName" class="f-input" value="<?= htmlspecialchars($myName) ?>" placeholder="Your name">
              </div>
              <div class="f-group">
                <label class="f-label" for="settingEmail">Email address</label>
                <input type="email" id="settingEmail" class="f-input" value="<?= htmlspecialchars($user['email']) ?>" placeholder="you@example.com">
              </div>
            </div>
            <button class="btn-c primary" id="cp-save-profile-btn">Save Changes</button>
          </div>
        </div>

        <div class="panel">
          <div class="panel-hdr">
            <span class="panel-title">Personal Information</span>
          </div>
          <div class="panel-body">
            <p style="font-size:13px;color:var(--text-muted);margin:0 0 16px;">These fields are optional and encrypted at rest. You have the right to correct or clear any of this information at any time (UK GDPR Art. 16).</p>
            <div class="f-row">
              <div class="f-group">
                <label class="f-label" for="sensitiveGender">Gender</label>
                <select id="sensitiveGender" class="f-input">
                  <option value="">Prefer not to say / not specified</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                  <option value="trans_woman">Trans woman</option>
                  <option value="trans_man">Trans man</option>
                  <option value="non_binary">Non-binary</option>
                  <option value="genderfluid">Genderfluid</option>
                  <option value="prefer_not_to_say">Prefer not to say</option>
                </select>
              </div>
              <div class="f-group">
                <label class="f-label" for="sensitiveSexuality">Sexuality</label>
                <select id="sensitiveSexuality" class="f-input">
                  <option value="">Not specified</option>
                  <option value="straight">Straight / heterosexual</option>
                  <option value="gay">Gay</option>
                  <option value="lesbian">Lesbian</option>
                  <option value="bisexual">Bisexual</option>
                  <option value="pansexual">Pansexual</option>
                  <option value="asexual">Asexual</option>
                  <option value="queer">Queer</option>
                  <option value="prefer_not_to_say">Prefer not to say</option>
                </select>
              </div>
            </div>
            <div class="f-row">
              <div class="f-group">
                <label class="f-label" for="sensitiveDob">Date of birth</label>
                <input type="date" id="sensitiveDob" class="f-input">
              </div>
              <div class="f-group">
                <label class="f-label" for="sensitiveLocation">Country</label>
                <select id="sensitiveLocation" class="f-input">
                  <option value="">Not specified</option>
                  <?php
                  $savedLocation = '';
                  try {
                      $locStmt = $pdo->prepare('SELECT location FROM end_users WHERE id = ? LIMIT 1');
                      $locStmt->execute([$userId]);
                      $savedLocation = $locStmt->fetchColumn() ?: '';
                  } catch (Throwable $e) { error_log('client_portal: could not load saved location for user ' . ($userId ?? '?') . ': ' . $e->getMessage()); }
                  foreach (getCountryList() as $code => $name):
                  ?>
                    <option value="<?= htmlspecialchars($code) ?>"<?= $savedLocation === $code ? ' selected' : '' ?>><?= countryFlag($code) ?> <?= htmlspecialchars($name) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <button class="btn-c primary" id="cp-save-sensitive-btn">Save Personal Information</button>
          </div>
        </div>

        <div class="panel">
          <div class="panel-hdr">
            <span class="panel-title">Timezone Preference</span>
          </div>
          <div class="panel-body">
            <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px;">Your preferred timezone is used when displaying booking times. If your browser detects the wrong timezone (e.g. when using a VPN), set it here.</p>
            <div class="f-group" style="max-width:340px;">
              <label class="f-label" for="settingTimezone">Timezone</label>
              <select id="settingTimezone" class="f-input"></select>
            </div>
            <button class="btn-c primary" id="cp-save-tz-btn" style="margin-top:10px;">Save Timezone</button>
          </div>
        </div>

        <div class="panel" id="consentPanel">
          <div class="panel-hdr">
            <span class="panel-title">Data Consent</span>
          </div>
          <div class="panel-body" id="consentPanelBody">
            <div style="text-align:center;padding:12px 0;"><div class="spinner"></div></div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-hdr">
            <span class="panel-title">Change Password</span>
          </div>
          <div class="panel-body">
            <div class="f-group">
              <label class="f-label" for="pwdNew">New password</label>
              <input type="password" id="pwdNew" class="f-input" placeholder="At least 12 characters">
              <div class="strength-bar"><div class="strength-fill" id="strengthFill2" style="width:0;background:var(--danger);"></div></div>
            </div>
            <div class="f-group">
              <label class="f-label" for="pwdConfirm">Confirm new password</label>
              <input type="password" id="pwdConfirm" class="f-input" placeholder="Repeat your password">
            </div>
            <button class="btn-c primary" id="cp-change-password-btn">Update Password</button>
          </div>
        </div>

        <!-- Account Security (TOTP MFA) -->
        <div class="panel" id="securityPanel">
          <div class="panel-hdr">
            <span class="panel-title">Account Security</span>
            <span id="mfaStatusBadge" style="font-size:11px;padding:2px 9px;border-radius:100px;font-weight:600;"></span>
          </div>
          <div class="panel-body">
            <p style="font-size:13px;color:var(--text-muted);margin:0 0 16px;">Two-factor authentication adds an extra layer of security to your account. When enabled, you will need your authenticator app each time you sign in.</p>

            <!-- State: MFA disabled -->
            <div id="mfaDisabledView">
              <button class="btn-c primary" id="cp-begin-totp-btn">Enable Two-Factor Authentication</button>
            </div>

            <!-- State: MFA setup — show secret / enter code -->
            <div id="mfaSetupView" style="display:none;">
              <p style="font-size:13px;color:var(--text-muted);margin:0 0 12px;">
                1. Open your authenticator app (Google Authenticator, Authy, etc.) and add a new account. Tap the link below or enter the secret key manually.
              </p>
              <div style="text-align:center;margin-bottom:12px;">
                <a id="mfaOtpLink" href="#" style="font-size:13px;color:var(--accent);" target="_blank">Open in Authenticator App</a>
              </div>
              <div style="margin-bottom:14px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px 14px;display:flex;align-items:center;gap:10px;">
                <code id="mfaSecretDisplay" style="font-family:monospace;font-size:13px;color:var(--text);flex:1;word-break:break-all;"></code>
                <button class="btn-c" id="cp-copy-mfa-secret-btn" style="font-size:11px;padding:4px 10px;white-space:nowrap;">Copy Key</button>
              </div>
              <p style="font-size:13px;color:var(--text-muted);margin:0 0 10px;">2. Enter the 6-digit code from your authenticator app to confirm setup.</p>
              <div id="mfaSetupError" class="alert-error" style="display:none;margin-bottom:10px;"></div>
              <div class="f-group">
                <label class="f-label" for="mfaConfirmCode">Verification code</label>
                <input type="text" id="mfaConfirmCode" class="f-input"
                       inputmode="numeric" pattern="\d{6}" maxlength="6"
                       placeholder="000000" autocomplete="off"
                       style="letter-spacing:3px;font-size:18px;text-align:center;max-width:160px;">
              </div>
              <div style="display:flex;gap:10px;margin-top:6px;">
                <button class="btn-c primary" id="cp-confirm-totp-btn">Activate</button>
                <button class="btn-c" id="cp-cancel-totp-btn">Cancel</button>
              </div>
            </div>

            <!-- State: MFA enabled -->
            <div id="mfaEnabledView" style="display:none;">
              <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px;">Two-factor authentication is active. To disable it, enter your current password below.</p>
              <div id="mfaDisableError" class="alert-error" style="display:none;margin-bottom:10px;"></div>
              <div class="f-group">
                <label class="f-label" for="mfaDisablePassword">Current password</label>
                <input type="password" id="mfaDisablePassword" class="f-input" placeholder="Your password" style="max-width:260px;">
              </div>
              <button class="btn-c danger" id="cp-disable-totp-btn">Disable Two-Factor Authentication</button>
            </div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-hdr">
            <span class="panel-title">Find a Practitioner</span>
          </div>
          <div class="panel-body">
            <div class="find-pract-card">
              <h3>Connect with a Practitioner</h3>
              <p>Browse available practitioners and request a session through the Serenity Spaces directory.</p>
              <button class="btn-c primary" id="cp-find-pract-btn">Browse Practitioners</button>
            </div>
          </div>
        </div>
      </section>

      <!-- ── My Practitioners ── -->
      <section class="portal-section" id="section-my-practitioners">
        <div class="sec-hdr">
          <h1>My Practitioners</h1>
          <p>Practitioners you have booked sessions with, and the data processing agreements they hold with third parties on your behalf.</p>
        </div>
        <div id="myPractLoading" style="text-align:center;padding:32px 0;"><div class="spinner"></div></div>
        <div id="myPractList" style="display:none;"></div>
        <div id="myPractEmpty" style="display:none;padding:32px 0;text-align:center;color:var(--text-muted);font-size:14px;">
          You have no booked sessions yet.
        </div>
      </section>

      <?php if ($aiPlatformEnabled): ?>
      <!-- ── AI Agreements ── -->
      <section class="portal-section" id="section-ai-agreements">
        <div class="sec-hdr">
          <h1>AI Agreements</h1>
          <p>Manage your consent for AI-assisted features used by your practitioners.</p>
        </div>

        <div id="aiAgreementsLoading" style="text-align:center;padding:32px 0;"><div class="spinner"></div></div>
        <div id="aiAgreementsList" style="display:none;"></div>
        <div id="aiAgreementsEmpty" style="display:none;padding:32px 0;text-align:center;color:var(--text-muted);font-size:14px;">
          You have no AI agreements on record. These are created when you book with a practitioner who uses AI features.
        </div>
      </section>
      <?php endif; ?>

    </main>
  </div>
</div>

<!-- ── Request modal ── -->
<div class="modal-overlay" id="requestModal">
  <div class="modal">
    <h3 id="requestModalTitle">Request</h3>
    <p id="requestModalDesc"></p>
    <div class="f-group">
      <label class="f-label" for="requestMessage">Message (optional)</label>
      <textarea id="requestMessage" class="f-input" style="resize:vertical;min-height:80px;" placeholder="Let your practitioner know any details…"></textarea>
    </div>
    <div class="modal-actions">
      <button class="btn-c ghost" id="cp-close-request-modal-btn">Cancel</button>
      <button class="btn-c primary" id="requestSubmitBtn">Send Request</button>
      <!-- ══════════ INBOX ══════════ -->
      <section class="portal-section" id="section-inbox" role="tabpanel" aria-labelledby="nav-inbox">
        <div class="sec-hdr">
          <h1>Messages</h1>
          <p>Private messages between you and your practitioners.</p>
        </div>
        <div class="ib-layout">
          <div class="ib-list" id="cpIbList">
            <div class="empty-c"><div class="spinner"></div></div>
          </div>
          <div class="ib-thread-wrap" id="cpIbThread">
            <div class="empty-c" style="flex:1;display:flex;align-items:center;justify-content:center;">
              <div style="text-align:center;">
                <div style="font-size:32px;margin-bottom:8px;">💬</div>
                <p style="color:var(--text-muted);font-size:13px;">Select a conversation to view messages</p>
              </div>
            </div>
          </div>
        </div>
      </section>

    </div>
  </div>
</div>

<!-- ── Rating modal ── -->
<div class="modal-overlay" id="ratingModal">
  <div class="modal">
    <h3>Rate Your Session</h3>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">How did the session feel? Your rating is private.</p>
    <div class="stars" id="ratingStars" style="justify-content:center;gap:12px;margin-bottom:20px;">
      <span class="star" data-star="1">★</span>
      <span class="star" data-star="2">★</span>
      <span class="star" data-star="3">★</span>
      <span class="star" data-star="4">★</span>
      <span class="star" data-star="5">★</span>
    </div>
    <div class="f-group">
      <label class="f-label" for="ratingComment">Comment (optional)</label>
      <textarea id="ratingComment" class="f-input" style="resize:vertical;min-height:70px;" placeholder="Anything you'd like to share…"></textarea>
    </div>
    <div class="modal-actions">
      <button class="btn-c ghost" id="cp-close-rating-modal-btn">Cancel</button>
      <button class="btn-c primary" id="ratingSubmitBtn">Submit Rating</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
'use strict';
const csrf        = <?= json_encode($csrf) ?>;
const mfaEnabled  = <?= json_encode(!empty($user['mfa_secret'])) ?>;
let currentSection   = 'dashboard';
let upcomingLoaded   = false;
let pastLoaded       = false;
let pastPage         = 1;
let pastHasMore      = false;
let myNotesLoaded    = false;
let requestType      = '';
let requestBookingId = 0;
let ratingSessionId  = 0;
let selectedRating   = 0;

const _sectionLabels = { dashboard: 'Dashboard', sessions: 'Session History', mynotes: 'My Notes', settings: 'Settings', inbox: 'Messages' };

function showSection(id, navEl) {
  document.querySelectorAll('.portal-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-item[role="tab"]').forEach(n => {
    n.classList.remove('active');
    n.setAttribute('aria-selected', 'false');
  });
  const sec = document.getElementById('section-' + id);
  if (sec) sec.classList.add('active');
  if (navEl) { navEl.classList.add('active'); navEl.setAttribute('aria-selected', 'true'); }
  const lbl = document.getElementById('topbar-label');
  if (lbl) lbl.textContent = _sectionLabels[id] || id;
  currentSection = id;
  if (id === 'dashboard' && !upcomingLoaded) loadUpcoming();
  if (id === 'sessions'  && !pastLoaded)    loadPastSessions();
  if (id === 'mynotes'   && !myNotesLoaded) loadMyNotes();
  if (id === 'inbox'     && !cpIbLoaded)    cpLoadConversations();
  if (id === 'settings')       { loadSensitiveFields(); loadConsentStatus(); initMfaPanel(); initTimezoneSelect(); }
  if (id === 'my-practitioners' && !myPractLoaded) loadMyPractitioners();
  if (id === 'ai-agreements'   && !aiAgreementsLoaded) loadAiAgreements();
}

// Arrow key navigation between tabs (ARIA pattern)
document.querySelector('.sidebar-nav[role="tablist"]')?.addEventListener('keydown', e => {
  const tabs = [...document.querySelectorAll('.nav-item[role="tab"]')];
  const idx  = tabs.indexOf(document.activeElement);
  if (idx === -1) return;
  if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
    e.preventDefault(); tabs[(idx + 1) % tabs.length].focus();
  } else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
    e.preventDefault(); tabs[(idx - 1 + tabs.length) % tabs.length].focus();
  } else if (e.key === 'Home') {
    e.preventDefault(); tabs[0].focus();
  } else if (e.key === 'End') {
    e.preventDefault(); tabs[tabs.length - 1].focus();
  }
});

function goFindPractitioners() { window.location.href = '/?browse=1'; }

async function doLogout() {
  await fetch('/api/client_auth.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'logout', csrf_token: csrf }),
  });
  window.location.href = '/login.php?tab=client';
}

async function requestDataDeletion() {
  if (!confirm(
    'Request deletion of all your personal data from Serenity Spaces?\n\n' +
    'This includes your account, session access, and uploaded files.\n\n' +
    'You will receive an email confirmation. We are required to complete\n' +
    'your request within 30 days under GDPR Article 17.\n\n' +
    'This action cannot be undone once processed.'
  )) return;

  try {
    const res  = await fetch('/api/gdpr.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'request' }),
    });
    const data = await res.json();
    if (data.ok) {
      if (data.already_pending) {
        alert('You already have a pending deletion request on file. We will process it within 30 days.');
      } else {
        alert('Your data deletion request has been submitted.\n\nWe will process it by ' + data.deadline + '.\n\nA confirmation has been sent to your email address.');
      }
    } else {
      alert('Could not submit request: ' + (data.error || 'Unknown error'));
    }
  } catch(e) {
    alert('Network error — please try again.');
  }
}

function showToast(msg, duration = 3200) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), duration);
}

// ── Upcoming sessions ──────────────────────────────────────────
async function loadUpcoming() {
  upcomingLoaded = true;
  try {
    const res  = await fetch('/api/client.php?action=upcoming_sessions');
    renderUpcoming(await res.json());
  } catch(e) {
    document.getElementById('upcomingList').innerHTML = '<div class="empty-c"><p>Unable to load sessions. Please refresh.</p></div>';
  }
}

function renderUpcoming(bookings) {
  const el = document.getElementById('upcomingList');
  if (!bookings || bookings.length === 0) {
    el.innerHTML = `<div class="empty-c">
      <div class="empty-c-icon">📭</div>
      <h3>No upcoming sessions</h3>
      <p>When a practitioner confirms your booking, your session will appear here.</p>
    </div>`;
    return;
  }

  el.innerHTML = bookings.map(b => {
    const dt      = new Date(b.scheduled_at + ' UTC');
    const dateStr = dt.toLocaleDateString(undefined, { weekday:'long', year:'numeric', month:'long', day:'numeric' });
    const timeStr = dt.toLocaleTimeString(undefined, { hour:'2-digit', minute:'2-digit' });
    const pill    = b.status === 'scheduled'
      ? `<span class="status-pill scheduled">Confirmed</span>`
      : `<span class="status-pill pending">Pending</span>`;

    const thumbEl = b.background_path
      ? `<img class="session-thumb" src="${escHtml(b.background_path)}" alt="">`
      : `<div class="session-thumb-ph">🌿</div>`;

    const joinBtn = b.can_join && b.join_url
      ? `<a class="btn-c primary" href="${escHtml(b.join_url)}" style="gap:6px;">▶ Join Session</a>`
      : `<button class="btn-c primary" disabled style="opacity:0.4;cursor:not-allowed;">▶ Join Session</button>`;

    const joinNote = b.can_join
      ? `<div class="session-note">Session is ready — join now</div>`
      : (b.status === 'scheduled' ? `<div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Join button activates 2 hours before your session</div>` : '');

    return `<div class="session-card">
      ${thumbEl}
      <div class="session-info">
        <div class="session-practitioner">With ${escHtml(b.practitioner_name || 'Your Practitioner')} ${pill}</div>
        <div class="session-time">${escHtml(timeStr)}</div>
        <div class="session-date">${escHtml(dateStr)}</div>
        ${joinNote}
        <div class="session-actions">
          ${joinBtn}
          ${b.status !== 'cancelled' ? `
            <button class="btn-c ghost sm" data-cancel-booking="${b.id}">Request Cancel</button>
            <button class="btn-c ghost sm" data-reschedule-booking="${b.id}">Request Reschedule</button>
          ` : ''}
          ${b.practitioner_id ? `<button class="btn-c ghost sm" data-message-pract-id="${b.practitioner_id}" data-message-pract-name="${escHtml(b.practitioner_name||'')}">✉ Message</button>` : ''}
        </div>
      </div>
    </div>`;
  }).join('');
}

// ── Past sessions ──────────────────────────────────────────────
async function loadPastSessions() {
  pastLoaded = true; pastPage = 1;
  try {
    const res      = await fetch('/api/client.php?action=past_sessions&page=1');
    const sessions = await res.json();
    pastHasMore    = sessions.length === 20;
    renderPastSessions(sessions, false);
  } catch(e) {
    document.getElementById('pastList').innerHTML = '<div class="empty-c"><p>Unable to load session history. Please refresh.</p></div>';
  }
}

async function loadMorePastSessions() {
  const btn = document.getElementById('past-load-more-btn');
  if (btn) { btn.disabled = true; btn.textContent = 'Loading…'; }
  try {
    const res      = await fetch(`/api/client.php?action=past_sessions&page=${pastPage + 1}`);
    const sessions = await res.json();
    pastPage++;
    pastHasMore    = sessions.length === 20;
    renderPastSessions(sessions, true);
  } catch(e) {
    if (btn) { btn.disabled = false; btn.textContent = 'Load more'; }
  }
}

function renderPastSessions(sessions, append = false) {
  const el = document.getElementById('pastList');
  if (!sessions || sessions.length === 0) {
    if (!append) {
      el.innerHTML = `<div class="empty-c">
        <div class="empty-c-icon">🗂️</div>
        <h3>No past sessions yet</h3>
        <p>Your completed sessions will appear here once they've ended.</p>
      </div>`;
    }
    const existingBtn = document.getElementById('past-load-more-btn');
    if (existingBtn) existingBtn.remove();
    return;
  }

  const startIdx = append ? el.querySelectorAll('.past-row').length : 0;
  const html = sessions.map((s, i) => {
    const idx = startIdx + i;
    const dt      = new Date(s.started_at + ' UTC');
    const dateStr = dt.toLocaleDateString(undefined, { year:'numeric', month:'long', day:'numeric' });
    const timeStr = dt.toLocaleTimeString(undefined, { hour:'2-digit', minute:'2-digit' });

    const thumbEl = s.room_bg_path
      ? `<img class="past-thumb" src="${escHtml(s.room_bg_path)}" alt="">`
      : `<div class="past-thumb-ph">🌿</div>`;

    let ratingEl;
    if (s.rating) {
      const starsHtml = [1,2,3,4,5].map(n => `<span class="star-sm${n <= s.rating ? ' filled' : ''}">★</span>`).join('');
      ratingEl = `<div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-muted);">
        <div class="stars-small">${starsHtml}</div> Rated
      </div>`;
    } else {
      ratingEl = `<button class="btn-c ghost sm" data-rate-session="${s.session_id}">Rate session</button>`;
    }

    // Invoice button — show if a payment record exists
    let invoiceEl = '';
    if (s.payment_id) {
      const isFree = (parseFloat(s.amount_due) === 0 || s.gateway === 'free');
      const displayStatus = isFree ? 'paid' : (s.payment_status || '');
      const statusColors = { paid:'#3ecf8e', pending:'#f5c842', waived:'#7c6af7', failed:'#e05c5c' };
      const statusLabels = { paid:'Paid', pending:'Pending', waived:'Waived', failed:'Failed' };
      const sc = statusColors[displayStatus] || '#888';
      const sl = statusLabels[displayStatus] || displayStatus;
      const invoiceUrl = `/api/invoice.php?payment_id=${s.payment_id}&token=${encodeURIComponent(s.booking_token || '')}`;
      invoiceEl = `<div style="margin-top:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="font-size:11px;padding:2px 8px;border-radius:999px;background:${sc}22;color:${sc};border:1px solid ${sc}44;">${escHtml(sl)}</span>
        <a href="${invoiceUrl}" target="_blank" style="font-size:11px;color:var(--accent);text-decoration:none;border:1px solid var(--border);border-radius:5px;padding:2px 8px;">View Invoice</a>
      </div>`;
    }

    const exportUrl = `/api/client_export.php?session_id=${encodeURIComponent(s.session_id)}`;
    const exportBtn = `<a href="${exportUrl}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--text-muted);text-decoration:none;border:1px solid var(--border);border-radius:5px;padding:3px 9px;margin-top:6px;white-space:nowrap;" title="Download your session transcript">
      <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Transcript
    </a>`;

    return `<div class="past-row">
      ${thumbEl}
      <div class="past-info">
        <div class="past-meta">Session #${idx+1} · ${escHtml(s.practitioner_name || 'Your Practitioner')}</div>
        <div class="past-date">${escHtml(dateStr)} at ${escHtml(timeStr)}</div>
        ${s.duration ? `<div class="past-dur">${escHtml(s.duration)}</div>` : ''}
        ${invoiceEl}
        ${exportBtn}
      </div>
      <div style="flex-shrink:0;">${ratingEl}</div>
    </div>`;
  }).join('');

  const existingBtn = document.getElementById('past-load-more-btn');
  if (existingBtn) existingBtn.remove();

  if (append) {
    el.insertAdjacentHTML('beforeend', html);
  } else {
    el.innerHTML = html;
  }

  if (pastHasMore) {
    el.insertAdjacentHTML('beforeend', `<div style="text-align:center;padding:16px 0;">
      <button id="past-load-more-btn" class="btn-c ghost sm" onclick="loadMorePastSessions()">Load more sessions</button>
    </div>`);
  }
}

// ── My Notes ──────────────────────────────────────────────────
async function loadMyNotes() {
  myNotesLoaded = true;
  const el = document.getElementById('myNotesList');
  try {
    // Load both upcoming and past bookings in parallel
    const [upRes, pastRes] = await Promise.all([
      fetch('/api/client.php?action=upcoming_sessions'),
      fetch('/api/client.php?action=past_sessions'),
    ]);
    const upcoming = await upRes.json();
    const past     = await pastRes.json();

    // Combine: upcoming first (keyed by booking b.id), past by b.booking_id
    const allBookings = [
      ...upcoming.map(b => ({ booking_id: b.id, practitioner: b.practitioner_name, scheduled_at: b.scheduled_at, type: 'upcoming' })),
      ...past.map(s     => ({ booking_id: s.booking_id, practitioner: s.practitioner_name, scheduled_at: s.started_at, type: 'past' })),
    ].filter(b => b.booking_id);

    if (!allBookings.length) {
      el.innerHTML = `<div class="empty-c">
        <div class="empty-c-icon">📓</div>
        <h3>No appointments yet</h3>
        <p>Your notes will appear here once you have upcoming or past sessions.</p>
      </div>`;
      return;
    }

    el.innerHTML = allBookings.map(b => {
      const dt      = new Date(b.scheduled_at + ' UTC');
      const dateStr = dt.toLocaleDateString(undefined, { weekday:'long', year:'numeric', month:'long', day:'numeric' });
      const timeStr = dt.toLocaleTimeString(undefined, { hour:'2-digit', minute:'2-digit' });
      const badge   = b.type === 'upcoming'
        ? `<span class="notes-appt-badge upcoming">Upcoming</span>`
        : `<span class="notes-appt-badge">Past</span>`;
      return `<div class="notes-appt-card" id="note-card-${b.booking_id}" data-booking-id="${b.booking_id}">
        <div class="notes-appt-meta">
          <div>
            <div class="notes-appt-title">With ${escHtml(b.practitioner || 'Your Practitioner')}</div>
            <div class="notes-appt-date">${escHtml(dateStr)} · ${escHtml(timeStr)}</div>
          </div>
          ${badge}
        </div>
        <textarea class="client-note-ta" id="note-ta-${b.booking_id}"
          placeholder="Your private reflections — before or after your session. Only you can see this."></textarea>
        <div class="client-note-save-row">
          <span class="client-note-status" id="note-status-${b.booking_id}"></span>
          <button class="btn-c ghost sm client-note-save-btn">Save</button>
        </div>
      </div>`;
    }).join('');

    // Attach listeners for each note card
    el.querySelectorAll('.notes-appt-card[data-booking-id]').forEach(card => {
      const bid = card.dataset.bookingId;
      const ta  = card.querySelector('.client-note-ta');
      const btn = card.querySelector('.client-note-save-btn');
      if (ta)  ta.addEventListener('blur', () => saveClientNote(bid));
      if (btn) btn.addEventListener('click', () => saveClientNote(bid));
    });

    // Lazy-load existing note text for each booking
    allBookings.forEach(b => fetchClientNote(b.booking_id));

  } catch(e) {
    el.innerHTML = '<div class="empty-c"><p>Unable to load notes. Please refresh.</p></div>';
  }
}

async function fetchClientNote(bookingId) {
  try {
    const res  = await fetch(`/api/client_notes.php?booking_id=${bookingId}`);
    const data = await res.json();
    const ta   = document.getElementById('note-ta-' + bookingId);
    if (ta && data.note_text) ta.value = data.note_text;
  } catch(e) { /* silent */ }
}

let _noteSaveTimers = {};
async function saveClientNote(bookingId) {
  clearTimeout(_noteSaveTimers[bookingId]);
  const ta     = document.getElementById('note-ta-' + bookingId);
  const status = document.getElementById('note-status-' + bookingId);
  if (!ta) return;
  status.textContent = 'Saving…'; status.style.opacity = '1';
  try {
    const res  = await fetch('/api/client_notes.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'save', booking_id: bookingId, note_text: ta.value }),
    });
    const data = await res.json();
    if (data.ok) {
      status.textContent = 'Saved';
      setTimeout(() => { status.style.opacity = '0'; }, 2000);
    } else {
      status.textContent = 'Error saving';
    }
  } catch(e) { status.textContent = 'Error saving'; }
}

// ── Request modal ──────────────────────────────────────────────
function openRequestModal(type, bookingId) {
  requestType = type; requestBookingId = bookingId;
  document.getElementById('requestMessage').value = '';
  document.getElementById('requestModalTitle').textContent = type === 'cancel' ? 'Request Cancellation' : 'Request Reschedule';
  document.getElementById('requestModalDesc').textContent  = type === 'cancel'
    ? 'Send a cancellation request to your practitioner. They will be notified and will reach out to confirm.'
    : "Let your practitioner know you'd like to reschedule. Include any preferred dates or times below.";
  document.getElementById('requestModal').classList.add('open');
}
function closeRequestModal() { document.getElementById('requestModal').classList.remove('open'); }

async function submitRequest() {
  const btn     = document.getElementById('requestSubmitBtn');
  const message = document.getElementById('requestMessage').value.trim();
  const action  = requestType === 'cancel' ? 'request_cancel' : 'request_reschedule';
  btn.disabled = true; btn.textContent = 'Sending…';
  try {
    const res  = await fetch('/api/client.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, booking_id: requestBookingId, message }),
    });
    const data = await res.json();
    if (data.ok) { closeRequestModal(); showToast(requestType === 'cancel' ? 'Cancellation request sent.' : 'Reschedule request sent.'); }
    else          { showToast(data.error || 'Request failed. Please try again.'); }
  } catch(e) { showToast('Connection error. Please try again.'); }
  btn.disabled = false; btn.textContent = 'Send Request';
}

// ── Rating modal ───────────────────────────────────────────────
function openRatingModal(sessionId) {
  ratingSessionId = sessionId; selectedRating = 0;
  updateStars(0); document.getElementById('ratingComment').value = '';
  document.getElementById('ratingModal').classList.add('open');
}
function closeRatingModal() { document.getElementById('ratingModal').classList.remove('open'); }
function setRating(n)    { selectedRating = n; updateStars(n); }
function hoverRating(n)  { updateStars(n, true); }
function unhoverRating() { updateStars(selectedRating); }
function updateStars(n, hover = false) {
  document.querySelectorAll('#ratingStars .star').forEach((s, i) => {
    s.classList.toggle('filled', i < n); s.classList.toggle('hover', hover && i < n);
  });
}

async function submitRating() {
  if (selectedRating === 0) { showToast('Please select a rating.'); return; }
  const btn     = document.getElementById('ratingSubmitBtn');
  const comment = document.getElementById('ratingComment').value.trim();
  btn.disabled = true; btn.textContent = 'Submitting…';
  try {
    const res  = await fetch('/api/client.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'rate_session', session_id: ratingSessionId, rating: selectedRating, comment }),
    });
    const data = await res.json();
    if (data.ok) { closeRatingModal(); showToast('Thank you for your feedback.'); pastLoaded = false; loadPastSessions(); }
    else          { showToast(data.error || 'Could not submit rating.'); }
  } catch(e) { showToast('Connection error.'); }
  btn.disabled = false; btn.textContent = 'Submit Rating';
}

// ── Profile / settings ─────────────────────────────────────────
function previewAvatar(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const prev = document.getElementById('avatarPreview');
    if (prev.tagName === 'IMG') { prev.src = e.target.result; }
    else { const img = document.createElement('img'); img.className = 'av-preview'; img.id = 'avatarPreview'; img.src = e.target.result; prev.replaceWith(img); }
  };
  reader.readAsDataURL(input.files[0]);
}

async function saveProfile() {
  const name  = document.getElementById('settingName').value.trim();
  const email = document.getElementById('settingEmail').value.trim();
  const file  = document.getElementById('avatarFile').files[0];
  const fd    = new FormData();
  fd.append('action', 'update_profile');
  if (name)  fd.append('display_name', name);
  if (email) fd.append('email', email);
  if (file)  fd.append('avatar', file);
  try {
    const res  = await fetch('/api/client.php', { method: 'POST', body: fd });
    const data = await res.json();
    showToast(data.ok ? 'Profile updated.' : (data.error || 'Update failed.'));
  } catch(e) { showToast('Connection error.'); }
}

function initTimezoneSelect() {
  const sel = document.getElementById('settingTimezone');
  if (!sel || sel.options.length > 1) return;
  const allTz = (typeof Intl.supportedValuesOf === 'function')
    ? Intl.supportedValuesOf('timeZone')
    : ['UTC','America/New_York','America/Chicago','America/Denver','America/Los_Angeles','Europe/London','Europe/Paris','Europe/Berlin','Asia/Dubai','Asia/Kolkata','Asia/Singapore','Asia/Tokyo','Australia/Sydney','Pacific/Auckland'];
  const saved = localStorage.getItem('ss_preferred_tz') || Intl.DateTimeFormat().resolvedOptions().timeZone;
  allTz.forEach(tz => {
    const opt = document.createElement('option');
    opt.value = tz; opt.textContent = tz;
    if (tz === saved) opt.selected = true;
    sel.appendChild(opt);
  });
  document.getElementById('cp-save-tz-btn')?.addEventListener('click', () => {
    localStorage.setItem('ss_preferred_tz', sel.value);
    showToast('Timezone preference saved.');
  });
}

async function loadSensitiveFields() {
  try {
    const res  = await fetch('/api/client.php?action=profile');
    const data = await res.json();
    if (!data || data.error) return;
    const gEl = document.getElementById('sensitiveGender');
    const sEl = document.getElementById('sensitiveSexuality');
    const dEl = document.getElementById('sensitiveDob');
    const lEl = document.getElementById('sensitiveLocation');
    if (gEl && data.gender)        gEl.value = data.gender;
    if (sEl && data.sexuality)     sEl.value = data.sexuality;
    if (dEl && data.date_of_birth) dEl.value = data.date_of_birth;
    if (lEl && data.location)      lEl.value = data.location;
  } catch(e) { /* silent — fields stay at default */ }
}

async function saveSensitiveFields() {
  const gender    = document.getElementById('sensitiveGender').value;
  const sexuality = document.getElementById('sensitiveSexuality').value;
  const dob       = document.getElementById('sensitiveDob').value;
  const location  = document.getElementById('sensitiveLocation').value;
  try {
    const res  = await fetch('/api/client.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update_sensitive_fields', gender, sexuality, date_of_birth: dob, location }),
    });
    const data = await res.json();
    showToast(data.ok ? 'Personal information updated.' : (data.error || 'Update failed.'));
  } catch(e) { showToast('Connection error.'); }
}

async function loadConsentStatus() {
  const body = document.getElementById('consentPanelBody');
  if (!body) return;
  try {
    const res  = await fetch('/api/client.php?action=consent_status');
    const data = await res.json();
    if (data.has_active) {
      body.innerHTML = `
        <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px;">
          You have active consent on file for the processing of your special category personal data
          (gender, sexuality, date of birth), given at booking.
          You may withdraw this consent at any time (UK GDPR Art. 7(3)).
        </p>
        <div id="consentConfirmWrap" style="display:none;background:rgba(232,85,85,0.08);border:1px solid rgba(232,85,85,0.25);border-radius:8px;padding:12px 14px;margin-bottom:12px;">
          <p style="font-size:13px;font-weight:600;color:var(--text);margin:0 0 6px;">Withdraw consent?</p>
          <p style="font-size:12px;color:var(--text-muted);margin:0 0 12px;line-height:1.6;">
            This will immediately clear your gender, sexuality, and date of birth from your account
            and notify your practitioner. This cannot be undone.
          </p>
          <div style="display:flex;gap:8px;">
            <button class="btn-c ghost" id="consentWithdrawYesBtn"
                    style="border-color:rgba(232,85,85,0.5);color:#e85555;">
              Yes, Withdraw Consent
            </button>
            <button class="btn-c ghost" id="consentCancelWithdrawBtn">
              Cancel
            </button>
          </div>
        </div>
        <button class="btn-c ghost" id="consentWithdrawBtn"
                style="border-color:rgba(232,85,85,0.35);color:#e85555;">
          Withdraw Special Category Data Consent
        </button>
        <p style="font-size:11px;color:var(--text-muted);margin-top:10px;line-height:1.5;">
          Withdrawal does not affect the lawfulness of processing before this date (Art. 7(3)).
          Session records already exported are not affected.
        </p>`;
      body.querySelector('#consentWithdrawYesBtn')?.addEventListener('click', withdrawConsent);
      body.querySelector('#consentCancelWithdrawBtn')?.addEventListener('click', () => {
        document.getElementById('consentConfirmWrap').style.display = 'none';
        document.getElementById('consentWithdrawBtn').style.display = '';
      });
      body.querySelector('#consentWithdrawBtn')?.addEventListener('click', function() {
        document.getElementById('consentConfirmWrap').style.display = 'block';
        this.style.display = 'none';
      });
    } else {
      body.innerHTML = `<p style="font-size:13px;color:var(--text-muted);margin:0;line-height:1.6;">
        No active special category data consent on file.
        Your gender, sexuality, and date of birth are not being processed under consent.</p>`;
    }
  } catch(e) {
    if (body) body.innerHTML = '<p style="font-size:13px;color:var(--text-muted);">Could not load consent status.</p>';
  }
}

async function withdrawConsent() {
  try {
    const res  = await fetch('/api/client.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'withdraw_consent' }),
    });
    const data = await res.json();
    if (data.ok) {
      showToast('Consent withdrawn. Your special category data has been cleared.');
      loadConsentStatus();
      // Clear the Personal Information fields visually to match the cleared DB state
      const fields = ['sensitiveGender','sensitiveSexuality','sensitiveDob'];
      fields.forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    } else {
      showToast(data.error || 'Withdrawal failed. Please try again.');
    }
  } catch(e) { showToast('Connection error. Please try again.'); }
}

async function changePassword() {
  const newPass = document.getElementById('pwdNew').value;
  const confirm = document.getElementById('pwdConfirm').value;
  if (newPass.length < 12) { showToast('Password must be at least 12 characters.'); return; }
  if (newPass !== confirm)  { showToast('Passwords do not match.'); return; }
  try {
    const res  = await fetch('/api/client_auth.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'change_password', new_password: newPass, confirm_password: confirm }),
    });
    const data = await res.json();
    if (data.ok) { showToast('Password updated.'); document.getElementById('pwdNew').value = ''; document.getElementById('pwdConfirm').value = ''; }
    else          { showToast(data.error || 'Password change failed.'); }
  } catch(e) { showToast('Connection error.'); }
}

// ── Must-change password ───────────────────────────────────────
function updateStrength(val) { _updateStr(val, 'strengthFill'); }
function updateStrength2(val) { _updateStr(val, 'strengthFill2'); }
function _updateStr(val, id) {
  const el = document.getElementById(id); if (!el) return;
  let score = 0;
  if (val.length >= 12)                                              score++;
  if (/[A-Z]/.test(val) && /[a-z]/.test(val))                       score++;
  if (/[0-9]/.test(val))                                             score++;
  if (/[^A-Za-z0-9]/.test(val))                                     score++;
  const colors = ['#e05252','#e8ad4a','#e8ad4a','#5db87c','#5db87c'];
  el.style.width = (score * 25) + '%';
  el.style.background = colors[score] || '#e05252';
}

async function submitPasswordChange() {
  const newPass = document.getElementById('newPass').value;
  const confirm = document.getElementById('confirmPass').value;
  const errEl   = document.getElementById('changePassError');
  if (newPass.length < 12) { errEl.textContent = 'Password must be at least 12 characters.'; errEl.style.display = 'block'; return; }
  if (newPass !== confirm)  { errEl.textContent = 'Passwords do not match.'; errEl.style.display = 'block'; return; }
  const btn = document.getElementById('changePassBtn'); btn.disabled = true; btn.textContent = 'Setting…';
  try {
    const res  = await fetch('/api/client_auth.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'change_password', new_password: newPass, confirm_password: confirm }),
    });
    const data = await res.json();
    if (data.ok) {
      document.getElementById('mustChangeOverlay').remove();
      window.history.replaceState({}, '', window.location.pathname);
      // Show upcoming sessions
      showSection('dashboard', document.getElementById('nav-dashboard'));
      loadUpcoming();
      cpPollUnread();
      showToast('Password set — welcome to Serenity Spaces.');
    } else { errEl.textContent = data.error || 'Failed to set password.'; errEl.style.display = 'block'; }
  } catch(e) { errEl.textContent = 'Connection error.'; errEl.style.display = 'block'; }
  btn.disabled = false; btn.textContent = 'Set Password';
}

function escHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Load dashboard on init
if (!<?= json_encode($mustChange) ?>) { loadUpcoming(); cpPollUnread(); }

// ── Inbox (client portal) ──────────────────────────────────────
let cpIbLoaded        = false;
let cpIbActivePractId = null;
let cpIbPollTimer     = null;
let cpMediaRecorder   = null;
let cpIbChunks        = [];

function cpPollUnread() {
  fetch('/api/inbox.php?action=unread_count').then(r => r.json()).then(d => {
    const badge = document.getElementById('cpInboxBadge');
    if (badge) { badge.textContent = d.unread || ''; badge.style.display = d.unread > 0 ? 'inline-flex' : 'none'; }
  }).catch(() => {});
  setTimeout(cpPollUnread, 30000);
}

async function cpLoadConversations() {
  cpIbLoaded = true;
  const el = document.getElementById('cpIbList');
  el.innerHTML = '<div class="empty-c"><div class="spinner"></div></div>';
  try {
    const res  = await fetch('/api/inbox.php?action=practitioners');
    const list = await res.json();
    if (!list.length) {
      el.innerHTML = '<div class="empty-c"><p style="color:var(--text-muted);font-size:13px;">No conversations yet.<br>Book a session to start messaging a practitioner.</p></div>';
      return;
    }
    el.innerHTML = list.map(p => {
      const lastText = p.last_type === 'voice_note' ? '🎙 Voice note' : p.last_type === 'image' ? '🖼 Image' : p.last_type === 'media_rec' ? '📖 Media recommendation' : escHtml(p.last_body || '');
      const av = p.avatar_path
        ? `<img src="${escHtml(p.avatar_path)}" class="ib-av" alt="">`
        : `<div class="ib-av-ph">${escHtml((p.display_name||'?')[0].toUpperCase())}</div>`;
      const badge = p.unread > 0 ? `<span class="ib-badge">${p.unread}</span>` : '';
      return `<div class="ib-conv-item${cpIbActivePractId===p.id?' active':''}" data-pract-id="${p.id}" data-pract-name="${escHtml(p.display_name)}">
        ${av}<div class="ib-conv-info"><div class="ib-conv-name">${escHtml(p.display_name)}${badge}</div>
        <div class="ib-conv-preview">${lastText}</div></div></div>`;
    }).join('');
  } catch(e) { el.innerHTML = '<div class="empty-c"><p>Unable to load conversations.</p></div>'; }
}

async function cpOpenThread(practId, practName) {
  cpIbActivePractId = practId;
  document.querySelectorAll('#cpIbList .ib-conv-item').forEach(el => el.classList.remove('active'));
  const item = document.querySelector(`#cpIbList .ib-conv-item[data-pract-id="${practId}"]`);
  if (item) item.classList.add('active');

  const wrap = document.getElementById('cpIbThread');
  wrap.innerHTML = `<div class="ib-thread-hdr"><div class="ib-thread-name">${escHtml(practName)}</div></div>
    <div class="ib-msgs" id="cpIbMsgs"><div class="empty-c"><div class="spinner"></div></div></div>
    <div class="ib-compose" id="cpIbCompose">
      <button class="ib-attach-btn" id="cpIbAttachBtn" title="Send image">
        <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" width="16" height="16"><rect x="1" y="2" width="16" height="13" rx="2"/><circle cx="6" cy="7" r="1.5"/><polyline points="1,13 6,8 10,12 13,9 17,13"/></svg>
      </button>
      <input type="file" id="cpIbImgFile" accept="image/*" style="display:none">
      <button class="ib-voice-btn" id="cpVoiceBtn" title="Record voice note">
        <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" width="16" height="16"><rect x="6" y="1" width="6" height="10" rx="3"/><path d="M3 9a6 6 0 0012 0"/><line x1="9" y1="17" x2="9" y2="15"/></svg>
      </button>
      <input type="text" class="ib-input" id="cpIbInput" placeholder="Type a message…">
      <button class="btn-c primary sm" id="cpIbSendBtn">Send</button>
    </div>`;

  document.getElementById('cpIbAttachBtn')?.addEventListener('click', () => document.getElementById('cpIbImgFile').click());
  document.getElementById('cpIbImgFile')?.addEventListener('change', function() { cpSendImage(this); });
  document.getElementById('cpVoiceBtn')?.addEventListener('click', cpToggleVoice);
  document.getElementById('cpIbInput')?.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); cpSendText(); } });
  document.getElementById('cpIbSendBtn')?.addEventListener('click', cpSendText);

  await cpFetchMessages(practId);
  // Mark read
  fetch('/api/inbox.php', { method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ action:'mark_read', with_id: practId }) });
  cpPollUnread();
  if (cpIbPollTimer) clearInterval(cpIbPollTimer);
  cpIbPollTimer = setInterval(() => cpFetchMessages(practId, true), 10000);
}

async function cpFetchMessages(practId, silent = false) {
  try {
    const res  = await fetch(`/api/inbox.php?action=messages&with_id=${practId}`);
    const data = await res.json();
    if (!silent || document.getElementById('cpIbMsgs')) cpRenderMessages(data);
  } catch(e) { /* silent — background inbox poll; stale messages remain visible on transient errors */ }
}

function cpRenderMessages(data) {
  const el = document.getElementById('cpIbMsgs');
  if (!el) return;
  const msgs = data.messages || [];
  if (!msgs.length) {
    el.innerHTML = '<div class="empty-c" style="flex:1;"><p style="color:var(--text-muted);font-size:13px;">No messages yet. Send the first one!</p></div>';
    return;
  }
  const myId = <?= json_encode((int)($user['id'] ?? 0)) ?>;
  const wasAtBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
  el.innerHTML = msgs.map(m => {
    const mine = m.sender_type === 'client';
    const ts   = new Date(m.created_at + ' UTC').toLocaleTimeString(undefined, {hour:'2-digit',minute:'2-digit'});
    let content;
    if (m.message_type === 'image' && m.file_path) {
      content = `<img src="${escHtml(m.file_path)}" class="ib-img-thumb" data-lightbox-src="${escHtml(m.file_path)}" alt="Image">`;
    } else if (m.message_type === 'voice_note' && m.file_path) {
      content = `<audio controls src="${escHtml(m.file_path)}" class="ib-audio"></audio>`;
    } else if (m.message_type === 'media_rec') {
      content = cpBuildMediaRecWidget(m.body || '');
    } else {
      content = escHtml(m.body || '');
    }
    return `<div class="ib-msg-row ${mine?'mine':'theirs'}">
      <div class="ib-bubble"><div class="ib-bubble-content">${content}</div><div class="ib-ts">${ts}</div></div>
    </div>`;
  }).join('');
  if (wasAtBottom || !data._polled) el.scrollTop = el.scrollHeight;
}

async function cpSendText() {
  const input = document.getElementById('cpIbInput');
  const body  = input.value.trim();
  if (!body || !cpIbActivePractId) return;
  input.value = '';
  try {
    await fetch('/api/inbox.php', { method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ action:'send', with_id: cpIbActivePractId, body, message_type:'text' }) });
    cpFetchMessages(cpIbActivePractId);
  } catch(e) { showToast('Failed to send message'); }
}

async function cpSendImage(input) {
  if (!input.files[0] || !cpIbActivePractId) return;
  const fd = new FormData();
  fd.append('action', 'send'); fd.append('with_id', cpIbActivePractId);
  fd.append('message_type', 'image'); fd.append('file', input.files[0]);
  input.value = '';
  try {
    const res = await fetch('/api/inbox.php', { method:'POST', body: fd });
    if (res.ok) cpFetchMessages(cpIbActivePractId);
    else showToast('Image upload failed');
  } catch(e) { showToast('Upload error'); }
}

async function cpToggleVoice() {
  const btn = document.getElementById('cpVoiceBtn');
  if (cpMediaRecorder && cpMediaRecorder.state === 'recording') {
    cpMediaRecorder.stop(); return;
  }
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    cpIbChunks = [];
    cpMediaRecorder = new MediaRecorder(stream);
    cpMediaRecorder.ondataavailable = e => cpIbChunks.push(e.data);
    cpMediaRecorder.onstop = async () => {
      stream.getTracks().forEach(t => t.stop());
      btn.classList.remove('recording');
      const blob = new Blob(cpIbChunks, { type: 'audio/webm' });
      const fd   = new FormData();
      fd.append('action', 'send'); fd.append('with_id', cpIbActivePractId);
      fd.append('message_type', 'voice_note'); fd.append('file', blob, 'voice.webm');
      try {
        const res = await fetch('/api/inbox.php', { method:'POST', body: fd });
        if (res.ok) cpFetchMessages(cpIbActivePractId);
        else showToast('Voice note upload failed');
      } catch(e) { showToast('Upload error'); }
    };
    cpMediaRecorder.start();
    btn.classList.add('recording');
  } catch(e) { showToast('Microphone access denied'); }
}

function cpOpenLightbox(src) {
  let lb = document.getElementById('cpLightbox');
  if (!lb) {
    lb = document.createElement('div');
    lb.id = 'cpLightbox'; lb.className = 'ib-lightbox';
    lb.innerHTML = '<img id="cpLightboxImg"><button class="ib-lb-close" id="cpLightboxCloseBtn">✕</button>';
    lb.addEventListener('click', e => { if (e.target === lb) lb.style.display = 'none'; });
    document.body.appendChild(lb);
    lb.querySelector('#cpLightboxCloseBtn').addEventListener('click', () => { lb.style.display = 'none'; });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') lb.style.display = 'none'; });
  }
  document.getElementById('cpLightboxImg').src = src;
  lb.style.display = 'flex';
}

function cpOpenInboxWith(practId, practName) {
  showSection('inbox', document.getElementById('nav-inbox'));
  if (cpIbLoaded) { cpOpenThread(practId, practName || ''); }
  else { cpLoadConversations().then(() => cpOpenThread(practId, practName || '')); }
}

(function initPortalTour() {
  const TOUR_KEY = 'ss_tour_v1_portal';
  if (localStorage.getItem(TOUR_KEY)) return;

  const steps = [
    {
      target: '#nav-dashboard',
      fallback: '.cp-sidebar',
      title: 'Your Dashboard',
      body: 'This is your home. See your upcoming sessions at a glance, recent activity, and any messages from your practitioner.'
    },
    {
      target: '#nav-sessions',
      fallback: '.cp-sidebar',
      title: 'Sessions',
      body: 'View your session history and join active sessions here. Your practitioner will send you a link when a session is ready.'
    },
    {
      target: '#nav-mynotes',
      fallback: '.cp-sidebar',
      title: 'My Notes',
      body: 'A private space for your own reflections and notes. Only you can see what you write here — your practitioner cannot access this section.'
    },
    {
      target: '#nav-inbox',
      fallback: '.cp-sidebar',
      title: 'Messages',
      body: 'Send and receive messages with your practitioner between sessions. Voice notes are supported too.'
    },
    {
      target: '#nav-settings',
      fallback: '.cp-sidebar',
      title: 'Settings',
      body: 'Update your display name, change your password, or manage your account preferences here.'
    },
  ];

  const overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;z-index:9998;pointer-events:none;';
  document.body.appendChild(overlay);

  const spotlight = document.createElement('div');
  spotlight.style.cssText = 'position:fixed;z-index:9999;border-radius:8px;pointer-events:none;transition:box-shadow 0.25s ease,top 0.25s ease,left 0.25s ease,width 0.25s ease,height 0.25s ease;box-shadow:0 0 0 9999px rgba(0,0,0,0.82);';
  document.body.appendChild(spotlight);

  const bubble = document.createElement('div');
  bubble.style.cssText = 'position:fixed;z-index:10000;background:#1a1f35;border:1px solid rgba(255,255,255,0.12);border-radius:12px;padding:20px 22px 16px;width:300px;box-shadow:0 8px 32px rgba(0,0,0,0.6);font-family:inherit;';
  bubble.innerHTML = `
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
      <span id="ptour-title" style="font-size:15px;font-weight:600;color:#e8eaf6;"></span>
      <button id="ptour-close" style="background:none;border:none;color:#666;font-size:18px;cursor:pointer;line-height:1;padding:0 0 0 12px;">&times;</button>
    </div>
    <p id="ptour-body" style="font-size:13px;color:#9e9eb8;line-height:1.6;margin:0 0 16px;"></p>
    <div style="display:flex;align-items:center;justify-content:space-between;">
      <span id="ptour-prog" style="font-size:12px;color:#555;"></span>
      <div style="display:flex;gap:8px;">
        <button id="ptour-skip" style="background:none;border:1px solid #333;color:#888;font-size:12px;padding:6px 12px;border-radius:6px;cursor:pointer;">Skip</button>
        <button id="ptour-next" style="background:#7c6af7;border:none;color:#fff;font-size:12px;padding:6px 14px;border-radius:6px;cursor:pointer;font-weight:600;">Next</button>
      </div>
    </div>`;
  document.body.appendChild(bubble);

  let current = 0;

  function positionBubble(rect) {
    const bw = 300, bh = 180, pad = 14, vw = window.innerWidth, vh = window.innerHeight;
    let top, left;
    if (rect.right + bw + pad < vw) {
      left = rect.right + pad;
      top  = Math.min(Math.max(pad, rect.top), vh - bh - pad);
    } else if (rect.left - bw - pad > 0) {
      left = rect.left - bw - pad;
      top  = Math.min(Math.max(pad, rect.top), vh - bh - pad);
    } else {
      left = Math.max(pad, Math.min(rect.left, vw - bw - pad));
      top  = rect.bottom + pad + bh < vh ? rect.bottom + pad : rect.top - bh - pad;
    }
    bubble.style.top  = top  + 'px';
    bubble.style.left = left + 'px';
  }

  function showPortalStep(i) {
    const s = steps[i];
    let el = document.querySelector(s.target) || document.querySelector(s.fallback);
    if (!el) el = document.body;
    const rect = el.getBoundingClientRect();
    const pad = 6;
    spotlight.style.top    = (rect.top    - pad) + 'px';
    spotlight.style.left   = (rect.left   - pad) + 'px';
    spotlight.style.width  = (rect.width  + pad * 2) + 'px';
    spotlight.style.height = (rect.height + pad * 2) + 'px';
    document.getElementById('ptour-title').textContent = s.title;
    document.getElementById('ptour-body').textContent  = s.body;
    document.getElementById('ptour-prog').textContent  = (i + 1) + ' / ' + steps.length;
    const nextBtn = document.getElementById('ptour-next');
    nextBtn.textContent = i === steps.length - 1 ? 'Done' : 'Next';
    positionBubble(rect);
    bubble.style.opacity = '1';
  }

  window.advancePortalTour = function() {
    current++;
    if (current >= steps.length) { endPortalTour(); return; }
    showPortalStep(current);
  };

  window.endPortalTour = function() {
    localStorage.setItem(TOUR_KEY, '1');
    spotlight.remove(); bubble.remove(); overlay.remove();
  };

  bubble.querySelector('#ptour-close').addEventListener('click', () => endPortalTour());
  bubble.querySelector('#ptour-skip').addEventListener('click', () => endPortalTour());
  bubble.querySelector('#ptour-next').addEventListener('click', () => advancePortalTour());

  setTimeout(() => showPortalStep(0), 1200);
})();

// ── My Practitioners ──────────────────────────────────────────────
let myPractLoaded = false;

async function loadMyPractitioners() {
  myPractLoaded = true;
  const loading = document.getElementById('myPractLoading');
  const list    = document.getElementById('myPractList');
  const empty   = document.getElementById('myPractEmpty');

  // Gather unique practitioner IDs from bookings we already have loaded,
  // or fetch from a dedicated endpoint. Use the bookings API.
  try {
    const res  = await fetch(`/api/client_auth.php?action=my_practitioners&csrf=${encodeURIComponent(csrf)}`);
    const data = await res.json();
    loading.style.display = 'none';

    if (!data.ok || !data.practitioners?.length) {
      empty.style.display = '';
      return;
    }

    list.style.display = '';
    list.innerHTML = data.practitioners.map(p => `
      <div style="border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:20px;margin-bottom:16px;background:rgba(255,255,255,0.02);">
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;">
          ${p.avatar_path
            ? `<img src="${escHtml(p.avatar_path)}" style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid rgba(124,106,247,0.3);">`
            : `<div style="width:44px;height:44px;border-radius:50%;background:rgba(124,106,247,0.15);display:flex;align-items:center;justify-content:center;font-size:18px;color:#a78bfa;">${escHtml((p.display_name||'?')[0].toUpperCase())}</div>`}
          <div>
            <div style="font-size:15px;font-weight:600;color:var(--text);">${escHtml(p.display_name || 'Practitioner')}</div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">${escHtml(p.service_type ? p.service_type.replace('_',' ') : '')}</div>
          </div>
        </div>
        <div id="pract-dpa-${p.id}" style="margin-top:4px;">
          <div style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Data Processing Agreements</div>
          <div id="pract-dpa-inner-${p.id}" style="font-size:13px;color:var(--text-muted);">Loading…</div>
        </div>
      </div>`).join('');

    // Load DPAs for each practitioner
    for (const p of data.practitioners) {
      loadPractDpas(p.id);
    }
  } catch(e) {
    if (window.SS_DEBUG) console.error('loadDpaSection failed', e);
    loading.style.display = 'none';
    empty.style.display = '';
  }
}

async function loadPractDpas(practId) {
  const inner = document.getElementById('pract-dpa-inner-' + practId);
  if (!inner) return;
  try {
    const res  = await fetch(`/api/dpa.php?action=list_for_client&practitioner_id=${practId}`);
    const data = await res.json();
    if (!data.ok || !data.docs?.length) {
      inner.textContent = 'No data processing agreements on file for this practitioner.';
      return;
    }
    inner.innerHTML = data.docs.map(d => `
      <div style="display:flex;align-items:center;gap:8px;padding:6px 10px;border:1px solid rgba(78,203,138,0.2);border-radius:6px;margin-bottom:6px;background:rgba(78,203,138,0.04);">
        <svg viewBox="0 0 16 16" fill="none" stroke="#4ecb8a" stroke-width="1.5" width="14" height="14" style="flex-shrink:0;"><path d="M4 8.5l2.5 2.5 5-5"/><rect x="1.5" y="1.5" width="13" height="13" rx="2"/></svg>
        <div>
          <span style="font-weight:600;color:#4ecb8a;">${escHtml(d.type_label)}</span>
          <span style="color:var(--text-muted);margin-left:6px;">${escHtml(d.vendor_name)}</span>
          <span style="font-size:11px;color:var(--text-muted);margin-left:6px;">· Uploaded ${escHtml(d.uploaded_at.slice(0,10))}</span>
        </div>
      </div>`).join('');
  } catch(e) {
    if (inner) inner.textContent = 'Could not load DPA information.';
  }
}

<?php if ($aiPlatformEnabled): ?>
// ── AI Agreements ─────────────────────────────────────────────────
let aiAgreementsLoaded = false;

async function loadAiAgreements() {
  aiAgreementsLoaded = true;
  const loading = document.getElementById('aiAgreementsLoading');
  const list    = document.getElementById('aiAgreementsList');
  const empty   = document.getElementById('aiAgreementsEmpty');
  try {
    const res  = await fetch('/api/ai_consent.php?action=list');
    const data = await res.json();
    loading.style.display = 'none';
    if (!data.ok || !data.consents.length) {
      empty.style.display = '';
      return;
    }
    list.style.display = '';
    list.innerHTML = data.consents.map(c => `
      <div class="panel" style="margin-bottom:16px;">
        <div class="panel-hdr">
          <span class="panel-title" style="display:flex;align-items:center;gap:10px;">
            ${c.practitioner_avatar ? `<img src="${escHtml(c.practitioner_avatar)}" style="width:32px;height:32px;border-radius:50%;object-fit:cover;">` : ''}
            ${escHtml(c.practitioner_name)}
            <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;${c.ai_allowed?'background:rgba(124,106,247,0.18);color:#a89ff7;':'background:rgba(255,255,255,0.05);color:rgba(255,255,255,0.35);'}">
              ${c.ai_allowed ? 'Consented' : 'Declined'}
            </span>
          </span>
        </div>
        <div class="panel-body">
          <p style="font-size:13px;color:var(--text-muted);margin:0 0 14px;">
            AI assistant: <strong style="color:var(--text);">${escHtml(c.assistant_name || 'Assistant')}</strong> · Provider: ${escHtml((c.vendor||'').toUpperCase())}
          </p>

          <label style="display:flex;align-items:center;gap:10px;margin-bottom:12px;cursor:pointer;">
            <input type="checkbox" data-pract="${c.practitioner_id}" class="ai-agree-main" style="accent-color:#7c6af7;" ${c.ai_allowed?'checked':''}>
            <span style="font-size:13px;color:var(--text);">Allow AI assistance in sessions with this practitioner</span>
          </label>

          <div class="ai-scopes-detail" id="ai-scopes-${c.practitioner_id}" style="display:${c.ai_allowed?'block':'none'};padding-left:22px;">
            ${buildScopeRows(c)}
          </div>

          <div id="ai-save-msg-${c.practitioner_id}" style="display:none;font-size:12px;margin-top:10px;"></div>
          <button class="btn-c primary ai-agree-save-btn" data-pract="${c.practitioner_id}" style="margin-top:12px;font-size:13px;">Save</button>
          <div style="font-size:11px;color:var(--text-muted);margin-top:8px;">Last updated: ${escHtml(c.updated_at ? c.updated_at.substring(0,10) : '—')}</div>
        </div>
      </div>
    `).join('');
  } catch(e) {
    if (window.SS_DEBUG) console.error('loadAiAgreements failed', e);
    loading.style.display = 'none';
    empty.style.display = '';
  }
}

function buildScopeRows(c) {
  const scopes = [
    {key:'summarization', apiKey:'pract_scope_sum',   name:'Session Summarization', desc:'AI generates a summary after sessions.'},
    {key:'notes',         apiKey:'pract_scope_notes',  name:'Notes Assistance',      desc:'AI assists with clinical note drafting.'},
    {key:'post_session',  apiKey:'pract_scope_post',  name:'Post-Session Discussion',desc:'AI discusses completed sessions with your practitioner.'},
    {key:'in_session',    apiKey:'pract_scope_in',    name:'In-Session Assistant',  desc:'AI joins live sessions as a participant.'},
  ];
  return scopes.filter(s => c[s.apiKey])
    .map(s => `
      <label style="display:flex;align-items:flex-start;gap:10px;padding:6px 0;cursor:pointer;border-top:1px solid rgba(255,255,255,0.05);">
        <input type="checkbox" class="ai-scope-agree" data-scope="${s.key}" style="margin-top:2px;accent-color:#7c6af7;" ${c['scope_'+s.key]?'checked':''}>
        <span style="font-size:12.5px;color:var(--text-muted);"><strong style="color:var(--text);">${escHtml(s.name)}:</strong> ${escHtml(s.desc)}</span>
      </label>
    `).join('');
}

function aiAgreementToggle(cb) {
  const practId = cb.dataset.pract;
  const detail  = document.getElementById('ai-scopes-' + practId);
  if (detail) detail.style.display = cb.checked ? 'block' : 'none';
}

async function saveAiAgreement(practId) {
  const mainCb  = document.querySelector(`.ai-agree-main[data-pract="${practId}"]`);
  const allowed = mainCb ? mainCb.checked : false;
  const body    = { action:'save', csrf_token: csrf, practitioner_id: practId, ai_allowed: allowed ? 1 : 0 };
  const container = document.getElementById('ai-scopes-' + practId);
  if (container) {
    container.querySelectorAll('.ai-scope-agree').forEach(cb => {
      body['scope_' + cb.dataset.scope] = (allowed && cb.checked) ? 1 : 0;
    });
  }
  try {
    const res  = await fetch('/api/ai_consent.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
    const data = await res.json();
    const msg  = document.getElementById('ai-save-msg-' + practId);
    if (msg) {
      msg.style.display = '';
      msg.style.color = data.ok ? '#3ecf8e' : '#ef9a9a';
      msg.textContent = data.ok ? 'Saved.' : (data.error || 'Error saving.');
      setTimeout(() => msg.style.display = 'none', 3000);
    }
  } catch(e) {
    if (window.SS_DEBUG) console.error('saveAiAgreement failed', e);
    showToast('Connection error — consent not saved.');
  }
}

function escHtml(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function cpBuildMediaRecWidget(bodyStr) {
  let data;
  try { data = JSON.parse(bodyStr); } catch(e) { data = null; }
  if (!data) return '<span style="color:rgba(221,220,242,0.4);font-size:12px;">[Media recommendation]</span>';

  const typeLabel = data.type === 'book' ? '📚 Book Recommendation'
                  : data.type === 'movie' ? '🎬 Film Recommendation'
                  : data.type === 'tv'    ? '📺 Series Recommendation'
                  : '📖 Media Recommendation';
  const coverPh = data.type === 'book' ? '📚' : data.type === 'movie' ? '🎬' : '📺';

  const metaParts = [];
  if (data.year)   metaParts.push(escHtml(data.year));
  if (data.genre)  metaParts.push(escHtml(data.genre));
  if (data.rating) metaParts.push('★ ' + escHtml(data.rating));
  const metaHtml = metaParts.map((p, i) =>
    (i > 0 ? '<span class="mrw-meta-sep">·</span>' : '') + `<span>${p}</span>`
  ).join('');

  const descHtml   = data.description ? `<div class="mrw-desc">${escHtml(data.description)}</div>` : '';
  const noteHtml   = data.note ? `<div class="mrw-note">"${escHtml(data.note)}"</div>` : '';
  const authorHtml = data.author ? `<div class="mrw-author">${escHtml(data.author)}</div>` : '';

  return `<div class="media-rec-widget">
    <div class="mrw-inner">
      <div class="mrw-cover">
        <span class="mrw-cover-ph">${coverPh}</span>
        ${data.cover_url ? `<img src="${escHtml(data.cover_url)}" alt="" data-hide-on-error>` : ''}
      </div>
      <div class="mrw-content">
        <div class="mrw-badge">${typeLabel}</div>
        <div class="mrw-title">${escHtml(data.title || 'Unknown')}</div>
        ${authorHtml}
        ${metaParts.length ? `<div class="mrw-meta">${metaHtml}</div>` : ''}
        ${descHtml}
        ${noteHtml}
      </div>
    </div>
  </div>`;
}

// AI agreements delegation
document.getElementById('aiAgreementsList')?.addEventListener('change', e => {
  if (e.target.matches('.ai-agree-main')) aiAgreementToggle(e.target);
});
document.getElementById('aiAgreementsList')?.addEventListener('click', e => {
  const btn = e.target.closest('.ai-agree-save-btn');
  if (btn) saveAiAgreement(+btn.dataset.pract);
});
<?php endif; ?>

// ── Account Security / TOTP MFA ───────────────────────────────
let _mfaActive = mfaEnabled; // local state, updated after setup/disable

function initMfaPanel() {
  const badge = document.getElementById('mfaStatusBadge');
  if (_mfaActive) {
    badge.textContent = 'Enabled';
    badge.style.cssText = 'font-size:11px;padding:2px 9px;border-radius:100px;font-weight:600;background:rgba(34,197,94,0.12);color:#4ade80;';
    document.getElementById('mfaDisabledView').style.display = 'none';
    document.getElementById('mfaSetupView').style.display    = 'none';
    document.getElementById('mfaEnabledView').style.display  = '';
  } else {
    badge.textContent = 'Disabled';
    badge.style.cssText = 'font-size:11px;padding:2px 9px;border-radius:100px;font-weight:600;background:rgba(239,68,68,0.12);color:#f87171;';
    document.getElementById('mfaDisabledView').style.display = '';
    document.getElementById('mfaSetupView').style.display    = 'none';
    document.getElementById('mfaEnabledView').style.display  = 'none';
  }
}

async function beginTotpSetup() {
  try {
    const res  = await fetch('/api/client_auth.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action: 'setup_totp_begin', csrf_token: csrf }),
    });
    const data = await res.json();
    if (!data.ok) { showToast(data.error || 'Error starting TOTP setup.', true); return; }

    document.getElementById('mfaSecretDisplay').textContent = data.secret;
    const otpLink = document.getElementById('mfaOtpLink');
    otpLink.href = data.uri;
    document.getElementById('mfaDisabledView').style.display = 'none';
    document.getElementById('mfaSetupView').style.display    = '';
    document.getElementById('mfaConfirmCode').value = '';
    document.getElementById('mfaSetupError').style.display   = 'none';
    document.getElementById('mfaConfirmCode').focus();
  } catch (e) {
    showToast('Connection error. Please try again.', true);
  }
}

function copyMfaSecret() {
  const secret = document.getElementById('mfaSecretDisplay').textContent;
  navigator.clipboard.writeText(secret).then(() => showToast('Secret key copied.'));
}

function cancelTotpSetup() {
  document.getElementById('mfaSetupView').style.display    = 'none';
  document.getElementById('mfaDisabledView').style.display = '';
}

async function confirmTotpSetup() {
  const code  = document.getElementById('mfaConfirmCode').value.replace(/\D/g, '');
  const errEl = document.getElementById('mfaSetupError');
  errEl.style.display = 'none';

  if (code.length !== 6) {
    errEl.textContent = 'Please enter the 6-digit code from your authenticator app.';
    errEl.style.display = '';
    return;
  }

  try {
    const res  = await fetch('/api/client_auth.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action: 'setup_totp_confirm', csrf_token: csrf, mfa_code: code }),
    });
    const data = await res.json();
    if (data.ok) {
      _mfaActive = true;
      document.getElementById('mfaSetupView').style.display = 'none';
      initMfaPanel();
      showToast('Two-factor authentication enabled.');
    } else {
      errEl.textContent   = data.error || 'Incorrect code. Please try again.';
      errEl.style.display = '';
    }
  } catch (e) {
    errEl.textContent   = 'Connection error. Please try again.';
    errEl.style.display = '';
  }
}

// ── Static + delegated event listeners ───────────────────────
// Nav buttons
document.querySelectorAll('.sidebar-nav [data-portal-section]').forEach(btn => {
  btn.addEventListener('click', () => showSection(btn.dataset.portalSection, btn));
});
document.getElementById('nav-find-practitioners-btn')?.addEventListener('click', goFindPractitioners);
document.getElementById('nav-request-deletion-btn')?.addEventListener('click', requestDataDeletion);
document.getElementById('nav-logout-btn')?.addEventListener('click', doLogout);
document.getElementById('topbar-signout-btn')?.addEventListener('click', doLogout);
document.getElementById('hamburger-btn')?.addEventListener('click', function() {
  const sidebar = document.getElementById('cms-sidebar');
  if (sidebar) sidebar.classList.toggle('mobile-open');
});

// Avatar label hover
document.getElementById('cp-avatar-label')?.addEventListener('mouseenter', function() { this.style.background = 'var(--accent-dim)'; });
document.getElementById('cp-avatar-label')?.addEventListener('mouseleave', function() { this.style.background = ''; });

// Settings inputs/buttons
document.getElementById('avatarFile')?.addEventListener('change', function() { previewAvatar(this); });
document.getElementById('cp-save-profile-btn')?.addEventListener('click', saveProfile);
document.getElementById('cp-save-sensitive-btn')?.addEventListener('click', saveSensitiveFields);
document.getElementById('pwdNew')?.addEventListener('input', function() { updateStrength2(this.value); });
document.getElementById('cp-change-password-btn')?.addEventListener('click', changePassword);
document.getElementById('cp-begin-totp-btn')?.addEventListener('click', beginTotpSetup);
document.getElementById('cp-copy-mfa-secret-btn')?.addEventListener('click', copyMfaSecret);
document.getElementById('cp-confirm-totp-btn')?.addEventListener('click', confirmTotpSetup);
document.getElementById('cp-cancel-totp-btn')?.addEventListener('click', cancelTotpSetup);
document.getElementById('cp-disable-totp-btn')?.addEventListener('click', disableTotp);
document.getElementById('cp-find-pract-btn')?.addEventListener('click', goFindPractitioners);

// Must-change password overlay
document.getElementById('newPass')?.addEventListener('input', function() { updateStrength(this.value); });
document.getElementById('changePassBtn')?.addEventListener('click', submitPasswordChange);

// Modal buttons
document.getElementById('cp-close-request-modal-btn')?.addEventListener('click', closeRequestModal);
document.getElementById('requestSubmitBtn')?.addEventListener('click', submitRequest);
document.getElementById('cp-close-rating-modal-btn')?.addEventListener('click', closeRatingModal);
document.getElementById('ratingSubmitBtn')?.addEventListener('click', submitRating);

// Star ratings (delegated — mouseover/mouseout bubble; mouseenter/leave do not)
(function() {
  const stars = document.getElementById('ratingStars');
  if (!stars) return;
  stars.addEventListener('click', e => {
    const s = e.target.closest('.star[data-star]');
    if (s) setRating(+s.dataset.star);
  });
  stars.addEventListener('mouseover', e => {
    const s = e.target.closest('.star[data-star]');
    if (s) hoverRating(+s.dataset.star);
  });
  stars.addEventListener('mouseout', e => {
    const s = e.target.closest('.star[data-star]');
    if (s) unhoverRating();
  });
})();

// Upcoming sessions action buttons (delegated)
document.getElementById('upcomingList')?.addEventListener('click', e => {
  const cancelBtn   = e.target.closest('[data-cancel-booking]');
  const reschedBtn  = e.target.closest('[data-reschedule-booking]');
  const msgBtn      = e.target.closest('[data-message-pract-id]');
  if (cancelBtn)  openRequestModal('cancel', +cancelBtn.dataset.cancelBooking);
  else if (reschedBtn) openRequestModal('reschedule', +reschedBtn.dataset.rescheduleBooking);
  else if (msgBtn) cpOpenInboxWith(+msgBtn.dataset.messagePractId, msgBtn.dataset.messagePractName || '');
});

// Past sessions rate button (delegated)
document.getElementById('pastList')?.addEventListener('click', e => {
  const btn = e.target.closest('[data-rate-session]');
  if (btn) openRatingModal(+btn.dataset.rateSession);
});

// Inbox conversation list (delegated)
document.getElementById('cpIbList')?.addEventListener('click', e => {
  const item = e.target.closest('[data-pract-id]');
  if (item) cpOpenThread(+item.dataset.practId, item.dataset.practName || '');
});

// Lightbox image thumbnails (delegated from document — rendered in dynamic context)
document.addEventListener('click', e => {
  const img = e.target.closest('.ib-img-thumb[data-lightbox-src]');
  if (img) cpOpenLightbox(img.dataset.lightboxSrc);
});

// Media rec cover image error (hide on broken image)
document.addEventListener('error', e => {
  if (e.target.tagName === 'IMG' && 'hideOnError' in e.target.dataset) e.target.style.display = 'none';
}, true);

async function disableTotp() {
  const password = document.getElementById('mfaDisablePassword').value;
  const errEl    = document.getElementById('mfaDisableError');
  errEl.style.display = 'none';

  if (!password) {
    errEl.textContent   = 'Please enter your current password to disable two-factor authentication.';
    errEl.style.display = '';
    return;
  }

  try {
    const res  = await fetch('/api/client_auth.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action: 'disable_totp', csrf_token: csrf, password }),
    });
    const data = await res.json();
    if (data.ok) {
      _mfaActive = false;
      document.getElementById('mfaDisablePassword').value = '';
      initMfaPanel();
      showToast('Two-factor authentication disabled.');
    } else {
      errEl.textContent   = data.error || 'Unable to disable. Please try again.';
      errEl.style.display = '';
    }
  } catch (e) {
    errEl.textContent   = 'Connection error. Please try again.';
    errEl.style.display = '';
  }
}
</script>
</body>
</html>
