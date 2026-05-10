<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/geo.php';
require_once __DIR__ . '/includes/banners.php';
require_once __DIR__ . '/db/cron_logger.php';
require_once __DIR__ . '/includes/cron_autotrigger.php';
requireSetup();
cron_maybe_trigger();

$token  = trim($_GET['token'] ?? '');
$browse = ($token === '' && !empty($_GET['browse']));
$error  = '';
$room   = null;

// ── Client join flow ──────────────────────────────────────────
$reconnectToken = null;
if ($token !== '') {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE room_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $room = $stmt->fetch();

    if (!$room) {
        $error = 'We\'re sorry, the session has expired or is invalid. Please contact your host for a new invitation.';
        $token = '';
    } else {
        // Check for a reconnect cookie from a previous session
        $cookieName = 'ss_reconnect_' . $room['room_token'];
        if (!empty($_COOKIE[$cookieName])) {
            $candidateToken = $_COOKIE[$cookieName];
            $stmt2 = $pdo->prepare(
                'SELECT p.join_token FROM participants p
                 JOIN sessions s ON s.id = p.session_id
                 WHERE p.join_token = ? AND s.room_id = ? AND s.ended_at IS NULL LIMIT 1'
            );
            $stmt2->execute([$candidateToken, $room['id']]);
            if ($stmt2->fetch()) {
                $reconnectToken = $candidateToken;
            }
        }
    }
}

// ── POST: client submitting join form ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_room'])) {
    $pdo         = getDB();
    $roomToken   = trim($_POST['room_token'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '');
    $csrfToken   = $_POST['csrf_token'] ?? '';
    // booking_avatar_path: path already uploaded during booking (passed as hidden field)
    $bookingAvatarPath = trim($_POST['booking_avatar_path'] ?? '');

    // If portal user has an account avatar and no booking/upload avatar, use theirs
    if (empty($bookingAvatarPath) && !empty($_SESSION['end_user_id']) &&
        (empty($_FILES['avatar_upload']['name']) || $_FILES['avatar_upload']['error'] !== UPLOAD_ERR_OK)) {
        $puStmt2 = $pdo->prepare('SELECT avatar_path FROM end_users WHERE id = ? LIMIT 1');
        $puStmt2->execute([(int)$_SESSION['end_user_id']]);
        $puAvatar = $puStmt2->fetchColumn();
        if ($puAvatar && preg_match('#^/assets/avatars/#', $puAvatar) && file_exists(__DIR__ . $puAvatar)) {
            $bookingAvatarPath = $puAvatar;
        }
    }

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $error = 'Security token mismatch. Please refresh and try again.';
    } elseif ($displayName === '') {
        $error = 'Please enter your name.';
    } elseif (
        empty($bookingAvatarPath) &&
        (empty($_FILES['avatar_upload']['name']) || $_FILES['avatar_upload']['error'] !== UPLOAD_ERR_OK)
    ) {
        $error = 'Please upload a photo to continue.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM rooms WHERE room_token = ? LIMIT 1');
        $stmt->execute([$roomToken]);
        $room = $stmt->fetch();

        if (!$room) {
            $error = 'Room not found.';
        } else {
            $avatarPath = null;

            // Use already-uploaded booking avatar if present and path is valid
            if ($bookingAvatarPath && preg_match('#^/assets/avatars/[a-f0-9]+\.(jpg|jpeg|png|gif|webp)$#i', $bookingAvatarPath)
                && file_exists(__DIR__ . $bookingAvatarPath)) {
                $avatarPath = $bookingAvatarPath;
            } else {
                // File upload
                $file     = $_FILES['avatar_upload'];
                $allowed  = ['image/jpeg','image/png','image/gif','image/webp'];
                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($file['tmp_name']);

                if (!in_array($mimeType, $allowed, true)) {
                    $error = 'Only JPEG, PNG, GIF, and WEBP images are allowed.';
                } elseif ($file['size'] > 5 * 1024 * 1024) {
                    $error = 'File size must not exceed 5 MB.';
                } else {
                    $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                    $ext  = $extMap[$mimeType] ?? 'bin';
                    $name = bin2hex(random_bytes(12)) . '.' . $ext;
                    move_uploaded_file($file['tmp_name'], __DIR__ . '/assets/avatars/' . $name);
                    $avatarPath = '/assets/avatars/' . $name;
                }
            }

            if ($error === '' && $avatarPath) {
                // Find or create session for this room
                $stmt = $pdo->prepare(
                    'SELECT * FROM sessions WHERE room_id = ? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1'
                );
                $stmt->execute([$room['id']]);
                $session = $stmt->fetch();

                if (!$session) {
                    $pdo->prepare('INSERT INTO sessions (room_id) VALUES (?)')->execute([$room['id']]);
                    $sessionId = $pdo->lastInsertId();
                } else {
                    $sessionId = $session['id'];
                }

                $joinToken = bin2hex(random_bytes(24));
                $pdo->prepare(
                    'INSERT INTO participants (session_id, display_name, avatar_path, join_token, position_x, position_y)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$sessionId, $displayName, $avatarPath, $joinToken, 0.1, 0.1]);
                $newParticipantId = (int)$pdo->lastInsertId();

                // Link participant to booking if booking_token present
                $bookingTok = trim($_POST['booking_token'] ?? '');
                if ($bookingTok) {
                    $pdo->prepare('UPDATE bookings SET participant_id = ? WHERE booking_token = ?')
                        ->execute([$newParticipantId, $bookingTok]);
                }

                header('Location: /room.php?join_token=' . urlencode($joinToken));
                exit;
            }
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$registrationsEnabled  = getSetting('registrations_enabled', '1') === '1';
$appTheme              = getAppTheme();
$appLogo               = getAppLogo();
$appName               = getSetting('app_name', 'Serenity Spaces');
$homeHeroHeading       = getSetting('home_hero_heading', 'Serenity Spaces');
$homeHeroSubtext       = getSetting('home_hero_subtext', 'A calm, private space for therapeutic conversations.');
$showFindPractitioners = getSetting('show_find_practitioners', '0') === '1';
$siteBgPath            = getSetting('site_background_path', '');
$siteBgType            = getSetting('site_background_type', '');

// ── Practitioner directory (shown when no room token) ──────────
$practitioners = [];
$practRates    = [];   // keyed by practitioner_id
$practPromos   = [];   // keyed by practitioner_id → best active campaign
if ($token === '') {
    $pdo = getDB();
    $aiPlatformEnabled = getSetting('ai_enabled', '0') === '1';
    $dirStmt = $pdo->query(
        'SELECT p.id, p.display_name, p.avatar_path, p.about_work, p.practice_types,
                p.gender, p.sexuality, p.date_of_birth, p.location,
                p.show_gender, p.show_sexuality, p.show_age, p.show_location,
                p.inclusivity_tags, p.languages, p.service_type,
                p.accepting_new_clients,
                COALESCE(ai.enabled, 0) AS has_ai
         FROM practitioners p
         LEFT JOIN practitioner_ai_config ai ON ai.practitioner_id = p.id
         WHERE p.display_name IS NOT NULL
           AND (p.account_status IS NULL OR p.account_status = \'active\')
           AND EXISTS (
             SELECT 1 FROM practitioner_availability a WHERE a.practitioner_id = p.id LIMIT 1
           )
         ORDER BY p.created_at ASC'
    );
    $practitioners = $dirStmt->fetchAll();

    if (!empty($practitioners)) {
        $pids = implode(',', array_map(fn($p) => (int)$p['id'], $practitioners));

        // Rates
        $rateRows = $pdo->query("SELECT * FROM practitioner_rates WHERE practitioner_id IN ($pids)")->fetchAll();
        foreach ($rateRows as $r) $practRates[(int)$r['practitioner_id']] = $r;

        // Best active campaign per practitioner (highest discount)
        $campRows = $pdo->query("
            SELECT * FROM discount_campaigns
            WHERE practitioner_id IN ($pids)
              AND is_active = 1
              AND valid_from <= CURDATE()
              AND (valid_until IS NULL OR valid_until >= CURDATE())
            ORDER BY discount_pct DESC
        ")->fetchAll();
        foreach ($campRows as $c) {
            $pid = (int)$c['practitioner_id'];
            if (!isset($practPromos[$pid])) $practPromos[$pid] = $c; // already sorted DESC
        }
    }
}

// Handle booking pre-fill from booking token
$bookingPrefill = null;
if ($token !== '' && !empty($_GET['booking'])) {
    if (!isset($pdo)) $pdo = getDB();
    $bkStmt = $pdo->prepare(
        'SELECT guest_name, guest_avatar FROM bookings WHERE booking_token = ? LIMIT 1'
    );
    $bkStmt->execute([trim($_GET['booking'])]);
    $bookingPrefill = $bkStmt->fetch() ?: null;
}

// Check if client is logged into the portal — pre-fill name + use account avatar
$portalUser = null;
if ($token !== '' && !empty($_SESSION['end_user_id'])) {
    if (!isset($pdo)) $pdo = getDB();
    $puStmt = $pdo->prepare('SELECT display_name, avatar_path FROM end_users WHERE id = ? LIMIT 1');
    $puStmt->execute([(int)$_SESSION['end_user_id']]);
    $portalUser = $puStmt->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Serenity Spaces</title>
  <link rel="stylesheet" href="/assets/css/main.css">
  <link rel="stylesheet" href="/assets/css/fonts.css">
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
    :root {
      --sky-void:    #050810;
      --sky-deep:    #080d1e;
      --sky-mid:     #0d1433;
      --star-color:  #e8eeff;
      --cloud-glow:  rgba(180,195,255,0.06);
      --moon-warm:   rgba(255,248,210,0.03);
    }

    body, .directory-page, .landing-page, .join-page {
      font-family: 'Outfit', sans-serif;
    }

    /* ── Sky canvas ───────────────────────────────── */
    .sky-canvas {
      position: fixed;
      inset: 0;
      z-index: 0;
      pointer-events: none;
      background: linear-gradient(170deg,
        var(--sky-void) 0%,
        var(--sky-deep) 35%,
        var(--sky-mid)  65%,
        #0a0f28         100%
      );
      overflow: hidden;
    }

    /* Moon glow — upper right */
    .sky-moon-glow {
      position: absolute;
      top: -18%;
      right: 8%;
      width: 700px;
      height: 700px;
      background: radial-gradient(circle,
        rgba(255,250,220,0.055) 0%,
        rgba(200,215,255,0.025) 30%,
        transparent 70%
      );
      border-radius: 50%;
    }

    /* Horizon atmospheric haze */
    .sky-horizon {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 35%;
      background: linear-gradient(to top,
        rgba(60,50,120,0.12) 0%,
        transparent 100%
      );
    }

    /* Star layers */
    .star-layer {
      position: absolute;
      inset: 0;
    }
    .star {
      position: absolute;
      border-radius: 50%;
      background: var(--star-color);
      animation: star-twinkle var(--dur) var(--delay) ease-in-out infinite;
    }
    @keyframes star-twinkle {
      0%, 100% { opacity: var(--op-max); transform: scale(1); }
      45%       { opacity: var(--op-min); transform: scale(var(--scale-min, 0.85)); }
    }

    /* Clouds */
    .sky-cloud {
      position: absolute;
      border-radius: 50%;
      animation: sky-cloud-drift var(--cdur, 90s) var(--cdelay, 0s) linear infinite;
    }
    @keyframes sky-cloud-drift {
      from { transform: translateX(115vw) translateY(0); }
      to   { transform: translateX(-160%) translateY(var(--drift-y, 0px)); }
    }

    /* ── Hero overrides ───────────────────────────── */
    .hero-section {
      background: transparent !important;
    }
    /* Hide old cloud divs — replaced by .sky-canvas */
    .hero-clouds, .directory-clouds { display: none !important; }

    .hero-wordmark {
      font-family: 'Outfit', sans-serif;
      font-weight: 800;
      letter-spacing: -2px;
      background: linear-gradient(135deg, #ffffff 0%, #c4b8ff 60%, #7c6af7 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .hero-tagline {
      font-family: 'Outfit', sans-serif;
      font-weight: 300;
      letter-spacing: 0.01em;
      color: rgba(200,210,255,0.6);
    }

    /* ── Header ─────────────────────────────────── */
    .directory-header {
      position: fixed;
      top: 0; left: 0; right: 0;
      z-index: 100;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 32px;
      height: 60px;
      background: rgba(5,8,16,0.82);
      backdrop-filter: blur(24px);
      border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .directory-header-brand {
      font-weight: 700;
      font-size: 16px;
      color: #dddcf2;
      text-decoration: none;
      letter-spacing: -0.3px;
      display: flex;
      align-items: center;
    }
    .directory-header-brand img { height: 48px; }
    .directory-header-nav {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .directory-header-nav a {
      font-size: 13px;
      padding: 7px 16px;
      border-radius: 8px;
      text-decoration: none;
      color: rgba(220,220,255,0.7);
      transition: color 0.15s, background 0.15s;
      font-weight: 500;
      font-family: 'Outfit', sans-serif;
    }
    .directory-header-nav a:hover { color: #dddcf2; background: rgba(255,255,255,0.05); }
    .directory-header-nav a.nav-primary {
      background: rgba(124,106,247,0.18);
      border: 1px solid rgba(124,106,247,0.35);
      color: #c5b8ff;
    }
    .directory-header-nav a.nav-primary:hover { background: rgba(124,106,247,0.28); color: #ddd6ff; }
    @media (max-width: 540px) {
      .directory-header { padding: 0 16px; height: 50px; }
      .directory-header-nav a { font-size: 11px; padding: 6px 8px; }
    }

    /* ── Directory body ───────────────────────────── */
    .directory-page {
      background: transparent;
      padding-top: 60px;
    }
    .directory-body {
      position: relative;
      z-index: 1;
    }
    .directory-intro h2 {
      font-family: 'Outfit', sans-serif;
      font-weight: 700;
      font-size: 28px;
      letter-spacing: -0.5px;
    }

    /* ── Practitioner cards ───────────────────────── */
    .practitioner-grid {
      grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
      gap: 24px;
      max-width: 1600px;
      margin: 0 auto;
    }
    .practitioner-card {
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.08);
      backdrop-filter: blur(20px);
      transition: border-color 0.2s, transform 0.2s, box-shadow 0.2s;
    }
    .practitioner-card:hover {
      border-color: rgba(124,106,247,0.35);
      transform: translateY(-2px);
      box-shadow: 0 12px 40px rgba(0,0,0,0.4);
    }
    .practitioner-card-avatar {
      height: 160px;
      background: linear-gradient(160deg, rgba(124,106,247,0.12) 0%, rgba(5,8,16,0.6) 100%);
      position: relative; overflow: hidden;
    }
    .practitioner-card-avatar img {
      position: absolute; inset: 0; width: 100%; height: 100%;
      object-fit: cover; object-position: top center;
    }
    .practitioner-card-body { padding: 18px 18px 10px; }
    .practitioner-card-name {
      font-family: 'Outfit', sans-serif;
      font-weight: 600;
      font-size: 17px;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 4px;
    }

    /* Label rows */
    .card-label-row {
      display: flex;
      align-items: baseline;
      gap: 8px;
      margin-top: 10px;
    }
    .card-section-label {
      font-size: 10px;
      font-weight: 600;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: rgba(124,106,247,0.7);
      white-space: nowrap;
      flex-shrink: 0;
      min-width: 68px;
    }
    .card-tags {
      display: flex;
      flex-wrap: wrap;
      gap: 4px;
    }
    .practice-badge {
      font-size: 11px;
      padding: 3px 8px;
      border-radius: 4px;
      background: rgba(124,106,247,0.1);
      color: rgba(196,184,255,0.9);
      border: 1px solid rgba(124,106,247,0.18);
    }
    .inclusivity-badge {
      font-size: 11px;
      padding: 3px 8px;
      border-radius: 4px;
      background: rgba(62,207,142,0.08);
      color: rgba(130,235,190,0.9);
      border: 1px solid rgba(62,207,142,0.18);
    }
    .lang-badge {
      font-size: 11px;
      padding: 3px 8px;
      border-radius: 4px;
      background: rgba(255,255,255,0.05);
      color: rgba(200,210,255,0.75);
      border: 1px solid rgba(255,255,255,0.1);
    }
    .practitioner-card-about {
      font-size: 12.5px;
      color: rgba(200,210,255,0.5);
      line-height: 1.55;
      margin-top: 10px;
    }
    .practitioner-card-about.collapsed {
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .card-about-toggle {
      display: none; font-size: 11.5px; color: rgba(124,106,247,0.85); background: none; border: none;
      font-family: inherit; cursor: pointer; padding: 3px 0 0; transition: opacity .15s;
    }
    .card-about-toggle.visible { display: inline-block; }
    .card-about-toggle:hover { opacity: 0.7; }
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
    .practitioner-card-meta {
      font-size: 12px;
      color: rgba(200,210,255,0.45);
      margin-top: 4px;
    }
    .practitioner-card-footer {
      padding: 12px 18px 16px;
      margin-top: 8px;
    }
    .svc-badge {
      font-size: 10px;
      padding: 2px 7px;
      border-radius: 4px;
      font-weight: 500;
    }
    .svc-badge-clinical     { background:rgba(30,144,255,0.12); color:#6ab4ff; border:1px solid rgba(30,144,255,0.2); }
    .svc-badge-non-clinical { background:rgba(124,106,247,0.12); color:#a898f8; border:1px solid rgba(124,106,247,0.2); }
    .svc-badge-ai { background:rgba(245,200,66,0.12); color:#f5c842; border:1px solid rgba(245,200,66,0.3); }

    /* ── Rate & promo ── */
    .card-rate-row {
      display: flex; align-items: center; gap: 8px; margin-top: 10px; flex-wrap: wrap;
    }
    .card-rate {
      font-size: 15px; font-weight: 700; color: #e8eeff;
    }
    .card-rate-orig {
      font-size: 12px; color: rgba(200,210,255,0.4); text-decoration: line-through;
    }
    .card-rate-discounted {
      font-size: 15px; font-weight: 700; color: #4caf87;
    }
    .card-promo-badge {
      font-size: 10.5px; font-weight: 600; padding: 2px 8px; border-radius: 4px;
      background: rgba(245,200,66,0.12); color: #f5c842; border: 1px solid rgba(245,200,66,0.25);
    }

    /* ── Filter bar ─────────────────────────────────── */
    .filter-bar {
      display: flex; align-items: center; flex-wrap: wrap; gap: 8px;
      margin-bottom: 24px; padding: 14px 16px;
      background: rgba(255,255,255,0.025); border: 1px solid rgba(255,255,255,0.07);
      border-radius: 12px;
    }
    .filter-bar-label {
      font-size: 11px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase;
      color: rgba(124,106,247,0.7); margin-right: 4px; white-space: nowrap;
    }
    .filter-btn {
      display: inline-flex; align-items: center; gap: 5px;
      font-size: 12px; font-family: 'Outfit', sans-serif;
      padding: 5px 11px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.1);
      background: rgba(255,255,255,0.04); color: rgba(200,210,255,0.75);
      cursor: pointer; transition: all 0.15s; white-space: nowrap;
    }
    .filter-btn:hover { border-color: rgba(124,106,247,0.35); color: rgba(196,184,255,0.95); }
    .filter-btn.active {
      background: rgba(124,106,247,0.18); border-color: rgba(124,106,247,0.45);
      color: #c4b8ff;
    }
    .filter-info-btn {
      width: 15px; height: 15px; border-radius: 50%;
      background: rgba(124,106,247,0.2); border: 1px solid rgba(124,106,247,0.35);
      color: rgba(196,184,255,0.85); font-size: 9px; font-weight: 700;
      display: inline-flex; align-items: center; justify-content: center;
      cursor: pointer; flex-shrink: 0; transition: background 0.15s;
    }
    .filter-info-btn:hover { background: rgba(124,106,247,0.35); }
    .filter-clear { margin-left: auto; }
    .practitioner-card.hidden-by-filter { display: none; }
    .filter-no-results {
      grid-column: 1 / -1; text-align: center; padding: 48px 24px;
      color: rgba(200,210,255,0.4); font-size: 14px;
    }

    /* ── Practice type info modal ────────────────────── */
    .pt-info-modal {
      position: fixed; inset: 0; z-index: 800; display: flex;
      align-items: center; justify-content: center; padding: 24px;
      background: rgba(0,0,0,0.7); backdrop-filter: blur(4px);
      opacity: 0; pointer-events: none; transition: opacity 0.2s;
    }
    .pt-info-modal.open { opacity: 1; pointer-events: all; }
    .pt-info-box {
      background: #0d102a; border: 1px solid rgba(124,106,247,0.25);
      border-radius: 16px; padding: 28px; max-width: 420px; width: 100%;
      box-shadow: 0 24px 80px rgba(0,0,0,0.6);
    }
    .pt-info-box h3 { font-size: 16px; font-weight: 700; color: #e8eeff; margin-bottom: 10px; }
    .pt-info-box p  { font-size: 13px; color: rgba(200,210,255,0.65); line-height: 1.65; margin-bottom: 16px; }
    .pt-info-close {
      background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);
      border-radius: 8px; padding: 7px 16px; font-size: 13px; color: rgba(200,210,255,0.7);
      cursor: pointer; font-family: 'Outfit', sans-serif; transition: background 0.15s;
    }
    .pt-info-close:hover { background: rgba(255,255,255,0.1); }

    /* ── Join card override ───────────────────────── */
    .join-page {
      position: relative;
      z-index: 1;
    }
    .join-card {
      background: rgba(8,12,30,0.85) !important;
      backdrop-filter: blur(28px) !important;
      border-color: rgba(255,255,255,0.08) !important;
    }
    .join-card h1 {
      font-family: 'Outfit', sans-serif;
      font-weight: 700;
      letter-spacing: -0.5px;
    }
  </style>
</head>
<body class="theme-<?= $appTheme ?><?= ($token === '' && !$error) ? ' scrollable' : '' ?>">
<?php renderBanners('public'); ?>

<!-- ── Night sky canvas ── -->
<div class="sky-canvas" id="sky-canvas" aria-hidden="true">
  <div class="sky-moon-glow"></div>
  <div class="sky-horizon"></div>
  <div class="star-layer" id="star-layer-0"></div>
  <div class="star-layer" id="star-layer-1"></div>
  <div class="star-layer" id="star-layer-2"></div>
  <!-- Clouds -->
  <div class="sky-cloud" style="width:680px;height:180px;top:14%;background:radial-gradient(ellipse,rgba(180,195,255,0.055) 0%,transparent 70%);filter:blur(50px);--cdur:110s;--cdelay:-32s;--drift-y:-12px;"></div>
  <div class="sky-cloud" style="width:420px;height:120px;top:31%;background:radial-gradient(ellipse,rgba(200,185,255,0.04) 0%,transparent 70%);filter:blur(40px);--cdur:85s;--cdelay:-18s;--drift-y:8px;"></div>
  <div class="sky-cloud" style="width:820px;height:240px;top:54%;background:radial-gradient(ellipse,rgba(160,175,255,0.045) 0%,rgba(124,106,247,0.02) 50%,transparent 70%);filter:blur(65px);--cdur:140s;--cdelay:-60s;--drift-y:-20px;"></div>
  <div class="sky-cloud" style="width:360px;height:100px;top:22%;background:radial-gradient(ellipse,rgba(220,225,255,0.035) 0%,transparent 70%);filter:blur(35px);--cdur:70s;--cdelay:-8s;--drift-y:5px;"></div>
  <div class="sky-cloud" style="width:560px;height:160px;top:71%;background:radial-gradient(ellipse,rgba(150,160,255,0.04) 0%,transparent 70%);filter:blur(55px);--cdur:95s;--cdelay:-44s;--drift-y:-8px;"></div>
  <div class="sky-cloud" style="width:300px;height:90px;top:42%;background:radial-gradient(ellipse,rgba(255,248,220,0.028) 0%,transparent 70%);filter:blur(30px);--cdur:60s;--cdelay:-24s;--drift-y:14px;"></div>
  <div class="sky-cloud" style="width:720px;height:200px;top:83%;background:radial-gradient(ellipse,rgba(130,145,255,0.05) 0%,transparent 70%);filter:blur(60px);--cdur:125s;--cdelay:-78s;--drift-y:-6px;"></div>
</div>

<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
(function(){
  const rnd = (a,b) => a + Math.random()*(b-a);
  const layers = [
    // [count, sizeMin, sizeMax, opMax, opMin, durMin, durMax, scaleMin]
    [200, 0.7, 1.3,  0.55, 0.10, 7,  14, 0.80],   // distant — tiny, slow fade
    [90,  1.3, 2.2,  0.75, 0.18, 4,  9,  0.88],   // mid — moderate
    [35,  2.2, 3.5,  0.95, 0.30, 2,  6,  0.92],   // near — bright, quick
  ];
  layers.forEach(([count,sMin,sMax,opMax,opMin,dMin,dMax,scMin], li) => {
    const layer = document.getElementById('star-layer-'+li);
    for (let i=0; i<count; i++) {
      const s   = document.createElement('div');
      const sz  = rnd(sMin, sMax);
      const dur = rnd(dMin, dMax).toFixed(2);
      const dly = rnd(0, dMax).toFixed(2);
      s.className = 'star';
      s.style.cssText = `
        width:${sz.toFixed(2)}px;height:${sz.toFixed(2)}px;
        top:${rnd(0,100).toFixed(3)}%;
        left:${rnd(0,100).toFixed(3)}%;
        --op-max:${opMax.toFixed(2)};
        --op-min:${opMin.toFixed(2)};
        --scale-min:${scMin};
        --dur:${dur}s;
        --delay:${dly}s;
      `;
      layer.appendChild(s);
    }
  });
})();
</script>

<?php if ($token !== '' && $room): ?>
<!-- ── JOIN ROOM FORM ── -->
<div class="join-page">
  <div class="join-card glass-panel">
    <h1>Join Room</h1>
    <p class="room-name"><?= htmlspecialchars($room['name']) ?></p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($reconnectToken): ?>
    <!-- ── Reconnect option ── -->
    <div style="margin-bottom:20px;padding:14px;background:rgba(var(--accent-rgb,100,180,255),0.08);border:1px solid rgba(var(--accent-rgb,100,180,255),0.25);border-radius:var(--radius);">
      <p style="margin:0 0 10px;font-size:14px;color:var(--text-muted);">It looks like you were here before.</p>
      <a href="/room.php?join_token=<?= urlencode($reconnectToken) ?>" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;display:flex;">
        Resume Session
      </a>
    </div>
    <p style="text-align:center;font-size:12px;color:var(--text-muted);margin-bottom:16px;">— or join as a new participant —</p>
    <?php endif; ?>

    <?php
      $prefillName        = $bookingPrefill ? ($bookingPrefill['guest_name']   ?? '') : '';
      $bookingAvatarPath  = $bookingPrefill ? ($bookingPrefill['guest_avatar'] ?? '') : '';
      // Only treat as a valid path if it's an uploaded file (not a legacy preset key)
      if (!preg_match('#^/assets/avatars/#', $bookingAvatarPath)) $bookingAvatarPath = '';
      $bookingToken       = !empty($_GET['booking']) ? trim($_GET['booking']) : '';

      // Portal user overrides: use their account avatar and name
      $portalAvatarPath = '';
      if ($portalUser) {
          if (!$prefillName) $prefillName = $portalUser['display_name'] ?? '';
          $av = $portalUser['avatar_path'] ?? '';
          if ($av && preg_match('#^/assets/uploads/#', $av) && file_exists(__DIR__ . $av)) {
              $portalAvatarPath = $av;
          }
      }
      $effectiveAvatarPath = $bookingAvatarPath ?: $portalAvatarPath;
      $returnUrl = '/index.php?token=' . urlencode($token) . ($bookingToken ? '&booking=' . urlencode($bookingToken) : '');
    ?>

    <?php if ($portalUser): ?>
    <!-- Logged in as portal user — photo skip enabled -->
    <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;background:rgba(124,106,247,0.07);border:1px solid rgba(124,106,247,0.2);border-radius:10px;margin-bottom:18px;">
      <?php if ($effectiveAvatarPath): ?>
        <img src="<?= htmlspecialchars($effectiveAvatarPath) ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);">
      <?php else: ?>
        <div style="width:40px;height:40px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#fff;"><?= htmlspecialchars(mb_substr($portalUser['display_name'] ?? '?', 0, 1)) ?></div>
      <?php endif; ?>
      <div style="flex:1;min-width:0;">
        <div style="font-size:13px;font-weight:600;color:var(--text);"><?= htmlspecialchars($portalUser['display_name'] ?? '') ?></div>
        <div style="font-size:11px;color:var(--text-muted);">Joined from your account · photo upload not required</div>
      </div>
      <a href="/api/client_auth.php?action=logout" style="font-size:11px;color:var(--text-muted);text-decoration:none;white-space:nowrap;">Not you?</a>
    </div>
    <?php else: ?>
    <!-- Not logged in — offer login option to skip photo -->
    <div style="font-size:12px;color:var(--text-muted);text-align:center;margin-bottom:14px;">
      Have a portal account?
      <a href="/login.php?tab=client&return=<?= urlencode($returnUrl) ?>" style="color:var(--accent);text-decoration:none;">Log in first to skip the photo upload.</a>
    </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="join-form">
      <input type="hidden" name="join_room"   value="1">
      <input type="hidden" name="room_token"  value="<?= htmlspecialchars($token) ?>">
      <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <?php if ($bookingToken): ?>
        <input type="hidden" name="booking_token" value="<?= htmlspecialchars($bookingToken) ?>">
      <?php endif; ?>
      <?php if ($effectiveAvatarPath): ?>
        <input type="hidden" name="booking_avatar_path" value="<?= htmlspecialchars($effectiveAvatarPath) ?>">
      <?php endif; ?>

      <div class="form-group">
        <label for="display_name">Your name</label>
        <input type="text" id="display_name" name="display_name"
               value="<?= htmlspecialchars($_POST['display_name'] ?? $prefillName) ?>"
               placeholder="Enter your name" required autofocus>
      </div>

      <div class="form-group">
        <label>Your photo<?php if (!$effectiveAvatarPath): ?> <span style="color:var(--crimson-bright)">*</span><?php endif; ?></label>
        <?php if ($effectiveAvatarPath): ?>
        <!-- Photo from booking or account — show preview, allow re-upload -->
        <div style="margin-bottom:12px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
          <img src="<?= htmlspecialchars($effectiveAvatarPath) ?>"
               id="upload-preview"
               style="width:80px;height:80px;object-fit:cover;border:2px solid var(--accent);">
          <span class="text-muted" style="font-size:13px;"><?= $portalAvatarPath && !$bookingAvatarPath ? 'Using your account photo.' : 'Photo from your booking.' ?> Upload a new one to change it.</span>
        </div>
        <?php else: ?>
        <div id="upload-preview-wrap" style="display:none;margin-bottom:12px;">
          <img id="upload-preview" style="width:100px;height:100px;object-fit:cover;border:2px solid var(--accent);">
        </div>
        <?php endif; ?>
        <label for="avatar_upload" class="upload-area" id="upload-area">
          <span id="upload-label"><?= $effectiveAvatarPath ? 'Click to upload a different photo' : 'Click to upload your photo (JPEG, PNG, GIF, WEBP — max 5 MB)' ?></span>
          <input type="file" id="avatar_upload" name="avatar_upload"
                 accept="image/jpeg,image/png,image/gif,image/webp"
                 style="display:none"
                 <?= $effectiveAvatarPath ? '' : 'required' ?>>
        </label>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;">
        Enter Room
      </button>
    </form>
  </div>
</div>

<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
function handleUpload(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const prev = document.getElementById('upload-preview');
    if (prev) {
      prev.src = e.target.result;
      prev.style.display = 'block';
    }
    const wrap = document.getElementById('upload-preview-wrap');
    if (wrap) wrap.style.display = 'block';
    document.getElementById('upload-label').textContent = input.files[0].name;
    // If they upload a new file, clear the booking avatar path so the new upload is used
    const hidden = document.querySelector('input[name="booking_avatar_path"]');
    if (hidden) hidden.value = '';
  };
  reader.readAsDataURL(input.files[0]);
}

(function () {
  const form = document.getElementById('join-form');
  if (!form) return;

  const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
  const MAX_SIZE = 5 * 1024 * 1024; // 5 MB

  function showInlineError(msg) {
    let el = document.getElementById('join-inline-error');
    if (!el) {
      el = document.createElement('div');
      el.id = 'join-inline-error';
      el.style.cssText = 'background:rgba(232,85,85,0.12);border:1px solid rgba(232,85,85,0.4);color:#e85555;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:14px;';
      form.insertBefore(el, form.firstChild);
    }
    el.textContent = msg;
    el.style.display = 'block';
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function clearInlineError() {
    const el = document.getElementById('join-inline-error');
    if (el) el.style.display = 'none';
  }

  form.addEventListener('submit', function (e) {
    clearInlineError();

    // Name check
    const nameInput = form.querySelector('input[name="display_name"]');
    if (nameInput && nameInput.value.trim() === '') {
      e.preventDefault();
      nameInput.focus();
      showInlineError('Please enter your name before joining.');
      return;
    }

    // File check — only when a file was selected (no effective avatar already set)
    const fileInput = form.querySelector('input[name="avatar_upload"]');
    const bookingAvatarHidden = form.querySelector('input[name="booking_avatar_path"]');
    const hasExistingAvatar = bookingAvatarHidden && bookingAvatarHidden.value.trim() !== '';

    if (fileInput && fileInput.files.length > 0) {
      const file = fileInput.files[0];
      if (!ALLOWED_TYPES.includes(file.type)) {
        e.preventDefault();
        showInlineError('That file type isn\'t supported. Please upload a JPEG, PNG, GIF, or WEBP image.');
        return;
      }
      if (file.size > MAX_SIZE) {
        e.preventDefault();
        showInlineError('Your photo is too large (max 5 MB). Please choose a smaller image.');
        return;
      }
    } else if (!hasExistingAvatar && fileInput && fileInput.required) {
      // No file chosen and no existing avatar — browser `required` will catch this,
      // but provide a friendlier message
      e.preventDefault();
      showInlineError('Please upload a photo to continue.');
      return;
    }
  });
})();
</script>

<?php elseif ($error && $token === ''): ?>
<!-- ── Error state ── -->
<div class="landing-page">
  <div class="landing-card glass-panel">
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <a href="/index.php" class="btn btn-ghost">Go Home</a>
  </div>
</div>

<?php else: ?>
<!-- ── PRACTITIONER DIRECTORY / LANDING ── -->
<div class="directory-page theme-<?= $appTheme ?>">

  <!-- Header -->
  <header class="directory-header">
    <a href="/" class="directory-header-brand">
      <?php if ($appLogo): ?>
        <img src="<?= htmlspecialchars($appLogo) ?>" alt="<?= htmlspecialchars($appName) ?>">
      <?php else: ?>
        <?= htmlspecialchars($appName) ?>
      <?php endif; ?>
    </a>
    <nav class="directory-header-nav">
      <?php if (!empty($practitioners) && !$browse): ?>
        <a href="/?browse=1">Find a Practitioner</a>
      <?php endif; ?>
      <a href="/coverage.php">Coverage</a>
      <a href="/about.php">About</a>
      <a href="/login.php">Login</a>
      <?php if ($registrationsEnabled): ?>
        <a href="/register.php" class="nav-primary">Create Account</a>
      <?php endif; ?>
    </nav>
  </header>

  <?php if ($siteBgPath): ?>
  <!-- Custom site background -->
  <div id="site-bg-layer" style="position:fixed;inset:0;z-index:0;overflow:hidden;pointer-events:none;">
    <?php if ($siteBgType === 'video'): ?>
      <video autoplay muted loop playsinline style="width:100%;height:100%;object-fit:cover;" src="<?= htmlspecialchars($siteBgPath) ?>"></video>
    <?php else: ?>
      <img src="<?= htmlspecialchars($siteBgPath) ?>" style="width:100%;height:100%;object-fit:cover;" alt="">
    <?php endif; ?>
    <div style="position:absolute;inset:0;background:rgba(0,0,0,0.5);"></div>
  </div>
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">#sky-canvas{display:none!important;}</style>
  <?php endif; ?>

  <?php if (!$browse): ?>
  <!-- Hero section with cloud background -->
  <section class="hero-section" style="<?= $siteBgPath ? 'position:relative;z-index:1;' : '' ?>">
    <?php if (!$siteBgPath): ?>
    <div class="hero-clouds" aria-hidden="true">
      <div class="hero-cloud hero-cloud-1"></div>
      <div class="hero-cloud hero-cloud-2"></div>
      <div class="hero-cloud hero-cloud-3"></div>
      <div class="hero-cloud hero-cloud-4"></div>
      <div class="hero-cloud hero-cloud-5"></div>
    </div>
    <?php endif; ?>
    <div class="hero-content">
      <?php if ($appLogo): ?>
        <img src="<?= htmlspecialchars($appLogo) ?>" alt="<?= htmlspecialchars($homeHeroHeading) ?>" class="hero-logo">
      <?php else: ?>
        <div class="hero-wordmark"><?= htmlspecialchars($homeHeroHeading) ?></div>
      <?php endif; ?>
      <p class="hero-tagline"><?= htmlspecialchars($homeHeroSubtext) ?></p>
      <div class="hero-actions">
        <?php if (!empty($practitioners)): ?>
          <a href="/?browse=1" class="btn btn-primary hero-btn">Find a Practitioner</a>
        <?php endif; ?>
        <a href="/login.php" class="btn btn-ghost hero-btn">Login</a>
      </div>
    </div>
    <div class="hero-scroll-hint" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
      </svg>
    </div>
  </section>
  <?php else: ?>
  <!-- Cloud background for directory view (no hero) -->
  <div class="hero-clouds directory-clouds" aria-hidden="true">
    <div class="hero-cloud hero-cloud-1"></div>
    <div class="hero-cloud hero-cloud-2"></div>
    <div class="hero-cloud hero-cloud-3"></div>
    <div class="hero-cloud hero-cloud-4"></div>
    <div class="hero-cloud hero-cloud-5"></div>
  </div>
  <?php endif; ?>

  <div class="directory-body" id="directory">

    <?php if (!empty($practitioners) && ($browse || $showFindPractitioners)): ?>
    <div class="directory-intro">
      <h2>Our Practitioners</h2>
      <p class="text-muted">Browse, view their approach, and book a session.</p>
    </div>

    <?php
    // Build the set of practice types that actually appear in this listing
    $presentTypes = [];
    foreach ($practitioners as $_p) {
        $pts = json_decode($_p['practice_types'] ?? '[]', true) ?: [];
        foreach ($pts as $pt) $presentTypes[$pt] = true;
    }
    $filterTypeLabels = [
        'life_coaching'            => 'Life Coaching',
        'philosophical_counseling' => 'Philosophical Counseling',
        'licensed_therapy'         => 'Licensed Therapy',
        'peer_support'             => 'Peer Support',
        'mindfulness'              => 'Mindfulness',
        'cbt'                      => 'CBT',
        'dbt'                      => 'DBT',
        'trauma_informed'          => 'Trauma-Informed',
        'grief_support'            => 'Grief Support',
        'addiction_recovery'       => 'Addiction Recovery',
        'pastoral_care'            => 'Pastoral Counseling',
        'spiritual_direction'      => 'Spiritual Direction',
    ];
    ?>
    <!-- Directory search — name, practice, tags, languages -->
    <?php if (count($practitioners) > 4): ?>
      <div style="margin:0 auto 14px;max-width:520px;">
        <input type="search" id="practSearchInput" placeholder="Search by name, focus, or language…"
               style="width:100%;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:10px;color:rgba(220,220,255,0.9);font-family:inherit;font-size:14px;padding:11px 16px;outline:none;transition:border-color 0.15s;"
               autocomplete="off" spellcheck="false">
      </div>
    <?php endif; ?>

    <?php if (count($presentTypes) > 1): ?>
    <div class="filter-bar" id="practFilterBar">
      <span class="filter-bar-label">Filter</span>
      <?php foreach ($filterTypeLabels as $key => $label): if (!isset($presentTypes[$key])) continue; ?>
        <button class="filter-btn" data-filter="<?= htmlspecialchars($key) ?>">
          <?= htmlspecialchars($label) ?>
          <span class="filter-info-btn" data-pt-info="<?= htmlspecialchars($key, ENT_QUOTES) ?>" title="What is this?">i</span>
        </button>
      <?php endforeach; ?>
      <button class="filter-btn filter-clear" id="filterClearBtn" style="display:none;color:rgba(255,255,255,0.4);">✕ Clear</button>
    </div>
    <?php endif; ?>

    <div class="practitioner-grid" id="practGrid">
      <?php foreach ($practitioners as $p):
        $ptypes = json_decode($p['practice_types'] ?? '[]', true) ?: [];
        $inclTags = json_decode($p['inclusivity_tags'] ?? '[]', true) ?: [];
        $langList = json_decode($p['languages'] ?? '[]', true) ?: [];
        $typeLabels = [
            'life_coaching'            => 'Life Coaching',
            'philosophical_counseling' => 'Philosophical Counseling',
            'licensed_therapy'         => 'Licensed Therapy',
            'peer_support'             => 'Peer Support',
            'mindfulness'              => 'Mindfulness',
            'cbt'                      => 'CBT',
            'dbt'                      => 'DBT',
            'trauma_informed'          => 'Trauma-Informed',
            'grief_support'            => 'Grief Support',
            'addiction_recovery'       => 'Addiction Recovery',
            'pastoral_care'            => 'Pastoral Counseling',
            'spiritual_direction'      => 'Spiritual Direction',
        ];
        $inclLabels = [
            'lgbtqia_affirming'  => 'LGBTQIA+ Affirming',
            'christian_friendly' => 'Christian Friendly',
            'individual_clients' => 'Individual Clients',
            'couples_counseling' => 'Couples Counseling',
        ];
        // Compute age if visible
        $ageStr = '';
        if ($p['show_age'] && $p['date_of_birth']) {
            $age = (int)date_diff(new DateTime($p['date_of_birth']), new DateTime())->y;
            $ageStr = $age . ' years old';
        }
      ?>
      <?php
        // Combined search haystack (name + about + practice types + inclusivity tags + languages)
        $searchHaystack = mb_strtolower(($p['display_name'] ?? '') . ' ' . ($p['about_work'] ?? '') . ' '
                          . implode(' ', $ptypes) . ' '
                          . implode(' ', $inclTags) . ' '
                          . implode(' ', $langList));
      ?>
      <div class="practitioner-card glass-panel"
           id="prac-<?= (int)$p['id'] ?>"
           data-types="<?= htmlspecialchars(implode(',', $ptypes)) ?>"
           data-incl="<?= htmlspecialchars(implode(',', $inclTags)) ?>"
           data-search="<?= htmlspecialchars($searchHaystack) ?>">
        <div class="practitioner-card-avatar">
          <?php if ($p['avatar_path']): ?>
            <img src="<?= htmlspecialchars($p['avatar_path']) ?>" alt="" loading="lazy" decoding="async">
          <?php else: ?>
            <div class="practitioner-card-avatar-placeholder">
              <?= mb_strtoupper(mb_substr($p['display_name'], 0, 1)) ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="practitioner-card-body">

          <!-- Name + service badge -->
          <?php $svc = $p['service_type'] ?? 'non_clinical'; ?>
          <?php $acceptingNew = (int)($p['accepting_new_clients'] ?? 1); ?>
          <div class="practitioner-card-name">
            <?= htmlspecialchars($p['display_name']) ?>
            <span class="svc-badge <?= $svc === 'clinical' ? 'svc-badge-clinical' : 'svc-badge-non-clinical' ?>">
              <?= $svc === 'clinical' ? 'Licensed Clinical' : 'Non-Clinical' ?>
            </span>
            <?php if ($aiPlatformEnabled && $p['has_ai']): ?>
            <span class="svc-badge svc-badge-ai" title="This practitioner uses an AI assistant in some sessions">Optional AI Assistance</span>
            <?php endif; ?>
          </div>
          <?php if (!$acceptingNew): ?>
            <div style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:#e07a30;background:rgba(224,122,48,0.12);border:1px solid rgba(224,122,48,0.25);border-radius:100px;padding:2px 10px;margin-bottom:6px;">
              <svg width="9" height="9" viewBox="0 0 10 10" fill="currentColor"><circle cx="5" cy="5" r="4"/></svg>
              Not accepting new clients
            </div>
          <?php endif; ?>

          <!-- Location & age -->
          <?php
          $identityMeta = [];
          if ($p['show_location'] && $p['location']) {
              $countries = getCountryList();
              $cName = $countries[$p['location']] ?? $p['location'];
              $identityMeta[] = countryFlag($p['location']) . ' ' . htmlspecialchars($cName);
          }
          if ($ageStr) $identityMeta[] = htmlspecialchars($ageStr);
          ?>
          <?php if (!empty($identityMeta)): ?>
            <div class="practitioner-card-meta"><?= implode(' · ', $identityMeta) ?></div>
          <?php endif; ?>

          <!-- About -->
          <?php if ($p['about_work']): ?>
            <?php $aboutLong = mb_strlen($p['about_work']) > 200; ?>
            <p class="practitioner-card-about<?= $aboutLong ? ' collapsed' : '' ?>" id="pabout-<?= $p['id'] ?>"><?= htmlspecialchars($p['about_work']) ?></p>
            <button class="card-about-toggle<?= $aboutLong ? ' visible' : '' ?>" id="pabout-btn-<?= $p['id'] ?>" data-about-id="<?= (int)$p['id'] ?>">Read more ↓</button>
          <?php endif; ?>

          <!-- Specialties -->
          <?php if (!empty($ptypes)): ?>
          <div class="card-label-row">
            <span class="card-section-label">Specialties</span>
            <div class="card-tags">
              <?php foreach (array_slice($ptypes, 0, 4) as $pt): ?>
                <span class="practice-badge"><?= htmlspecialchars($typeLabels[$pt] ?? $pt) ?></span>
              <?php endforeach; ?>
              <?php if (count($ptypes) > 4): ?>
                <span class="practice-badge" style="opacity:.6;">+<?= count($ptypes)-4 ?></span>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Inclusivity -->
          <?php if (!empty($inclTags)): ?>
          <div class="card-label-row">
            <span class="card-section-label">Offering</span>
            <div class="card-tags">
              <?php foreach ($inclTags as $tag): ?>
                <span class="inclusivity-badge"><?= htmlspecialchars($inclLabels[$tag] ?? $tag) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Languages -->
          <?php if (!empty($langList)): ?>
          <div class="card-label-row">
            <span class="card-section-label">Languages</span>
            <div class="card-tags">
              <?php foreach (array_slice($langList, 0, 4) as $lang): ?>
                <span class="lang-badge"><?= htmlspecialchars($lang) ?></span>
              <?php endforeach; ?>
              <?php if (count($langList) > 4): ?>
                <span class="lang-badge" style="opacity:.6;">+<?= count($langList)-4 ?></span>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>


        <!-- Rate & active promo -->
        <?php
          $rate  = $practRates[(int)$p['id']] ?? null;
          $promo = $practPromos[(int)$p['id']] ?? null;
          if ($rate): ?>
          <div class="card-rate-row">
            <?php if ($rate['is_free']): ?>
              <span class="card-rate" style="color:#4caf87;">Free</span>
            <?php elseif ($promo): ?>
              <?php
                $orig = (float)$rate['amount'];
                $disc = round($orig * (1 - $promo['discount_pct'] / 100), 2);
                $sym  = match($rate['currency']) { 'USD' => '$', 'EUR' => '€', 'GBP' => '£', default => $rate['currency'] . ' ' };
              ?>
              <span class="card-rate-orig"><?= $sym . number_format($orig, 2) ?></span>
              <span class="card-rate-discounted"><?= $promo['discount_pct'] == 100 ? 'Free' : ($sym . number_format($disc, 2)) ?></span>
              <span class="card-promo-badge"><?= $promo['discount_pct'] ?>% off</span>
            <?php else: ?>
              <?php $sym = match($rate['currency']) { 'USD' => '$', 'EUR' => '€', 'GBP' => '£', default => $rate['currency'] . ' ' }; ?>
              <span class="card-rate"><?= $sym . number_format((float)$rate['amount'], 2) ?> / session</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        </div><!-- /.practitioner-card-body -->
        <div class="practitioner-card-footer">
          <a href="/book.php?practitioner=<?= $p['id'] ?>" class="btn btn-primary" style="width:100%;justify-content:center;font-family:'Outfit',sans-serif;font-weight:500;">
            Book a Session
          </a>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="filter-no-results" id="filterNoResults" style="display:none;">
        No practitioners match the selected filters.
        <br><button class="filter-btn" id="filterClearBtn2" style="margin-top:12px;">Clear filters</button>
      </div>
    </div>

    <?php else: ?>
    <!-- No practitioners — directory body is empty; hero already shown above -->
    <?php endif; ?>

  </div><!-- /.directory-body -->
</div><!-- /.directory-page -->

<!-- Practice type info modal -->
<div class="pt-info-modal" id="ptInfoModal">
  <div class="pt-info-box">
    <h3 id="ptInfoTitle"></h3>
    <p id="ptInfoDesc"></p>
    <button class="pt-info-close" id="ptInfoCloseBtn">Got it</button>
  </div>
</div>

<?php endif; ?>

<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
const _ptDescriptions = {
  life_coaching:            { label: 'Life Coaching', desc: 'Focuses on personal growth, goal-setting, and actionable strategies for navigating life transitions, career, relationships, or wellbeing. Not a clinical service — think of it as having a structured, experienced thinking partner.' },
  philosophical_counseling: { label: 'Philosophical Counseling', desc: 'Explores questions of meaning, identity, and values through dialogue rooted in philosophical traditions. Useful for existential concerns, ethical dilemmas, or when you want to think deeply rather than receive clinical advice.' },
  licensed_therapy:         { label: 'Licensed Therapy', desc: 'Provided by a credentialled mental health professional (e.g. LCSW, LPC, psychologist). Appropriate for diagnosable conditions, trauma processing, and clinical treatment. Verify credentials before clinical reliance.' },
  peer_support:             { label: 'Peer Support', desc: 'Led by someone with lived experience of mental health challenges. Peer supporters are trained in recovery-oriented, non-clinical support — meeting you where you are with empathy and shared understanding.' },
  mindfulness:              { label: 'Mindfulness', desc: 'Incorporates evidence-based mindfulness practices — breathwork, grounding, awareness techniques — to help manage stress, anxiety, or overwhelming emotion. Secular or spiritually-inflected depending on the practitioner.' },
  cbt:                      { label: 'CBT (Cognitive Behavioural Therapy)', desc: 'A structured approach that explores the connection between thoughts, feelings, and behaviours. Widely evidenced for depression, anxiety, and phobias. Look for practitioners who are trained or certified in CBT.' },
  dbt:                      { label: 'DBT (Dialectical Behaviour Therapy)', desc: 'Builds skills in four areas: mindfulness, distress tolerance, emotion regulation, and interpersonal effectiveness. Originally developed for BPD; now used broadly for emotional dysregulation and self-harm.' },
  trauma_informed:          { label: 'Trauma-Informed', desc: "Approaches all support through the lens of trauma's impact on the nervous system, relationships, and sense of self. Does not assume trauma exists — simply means the practitioner won't inadvertently re-traumatise." },
  grief_support:            { label: 'Grief Support', desc: 'Specialised in supporting bereavement, ambiguous loss, anticipatory grief, or the loss of identity, relationship, or hope. Grief is not linear — grief-informed practitioners know this.' },
  addiction_recovery:       { label: 'Addiction Recovery', desc: 'Supports people navigating substance use, behavioural addiction, or recovery journeys. May use harm reduction, 12-step-informed, or abstinence approaches — confirm with the practitioner which model they work from.' },
  pastoral_care:            { label: 'Pastoral Counseling', desc: 'Faith-rooted support that integrates psychological insight with spiritual practice. Provided by ordained ministers, chaplains, or trained pastoral counselors. Centers prayer, scripture, and spiritual formation alongside emotional support — often within a specific faith tradition. Not a substitute for clinical therapy when one is needed; complementary by design.' },
  spiritual_direction:      { label: 'Spiritual Direction', desc: 'A contemplative companionship practice — the director helps you notice and respond to the movement of the sacred in your life. Rooted in long Christian, Jewish, Buddhist, and interfaith traditions. Less about advice, more about discernment. Often suitable for clients whose deepest questions are about meaning, calling, faith doubt, or relationship with the divine.' },
};

function showPtInfo(key) {
  const info = _ptDescriptions[key];
  if (!info) return;
  document.getElementById('ptInfoTitle').textContent = info.label;
  document.getElementById('ptInfoDesc').textContent  = info.desc;
  document.getElementById('ptInfoModal').classList.add('open');
}
function closePtInfo() { document.getElementById('ptInfoModal').classList.remove('open'); }

// ── Practitioner filtering ─────────────────────────────────────
let activeFilters = new Set();

function toggleFilter(btn) {
  const key = btn.dataset.filter;
  if (activeFilters.has(key)) {
    activeFilters.delete(key);
    btn.classList.remove('active');
  } else {
    activeFilters.add(key);
    btn.classList.add('active');
  }
  applyFilters();
}

function clearFilters() {
  activeFilters.clear();
  document.querySelectorAll('.filter-btn[data-filter]').forEach(b => b.classList.remove('active'));
  const searchEl = document.getElementById('practSearchInput');
  if (searchEl) searchEl.value = '';
  applyFilters();
}

function applyFilters() {
  const cards     = document.querySelectorAll('#practGrid .practitioner-card');
  const clearBtn  = document.getElementById('filterClearBtn');
  const noResults = document.getElementById('filterNoResults');
  const searchEl  = document.getElementById('practSearchInput');
  const searchQ   = (searchEl?.value || '').trim().toLowerCase();
  let visibleCount = 0;

  cards.forEach(card => {
    // Filter chips — match if EVERY active filter appears in either practice
    // types or inclusivity tags. Cross-axis AND-combiner ("matches both X and Y").
    let match = true;
    if (activeFilters.size > 0) {
      const types = (card.dataset.types || '').split(',').filter(Boolean);
      const incl  = (card.dataset.incl  || '').split(',').filter(Boolean);
      const all   = types.concat(incl);
      match = [...activeFilters].every(f => all.includes(f));
    }

    // Search — substring match on the precomputed haystack
    if (match && searchQ !== '') {
      const hay = card.dataset.search || '';
      if (!hay.includes(searchQ)) match = false;
    }

    card.classList.toggle('hidden-by-filter', !match);
    if (match) visibleCount++;
  });

  const anyActive = activeFilters.size > 0 || searchQ !== '';
  if (clearBtn) clearBtn.style.display = activeFilters.size ? '' : 'none';
  if (noResults) noResults.style.display = (visibleCount === 0 && anyActive) ? '' : 'none';
}

function toggleCardAbout(id) {
  const el  = document.getElementById('pabout-' + id);
  const btn = document.getElementById('pabout-btn-' + id);
  if (!el || !btn) return;
  const collapsed = el.classList.toggle('collapsed');
  btn.textContent = collapsed ? 'Read more ↓' : 'Show less ↑';
}

// ── Shooting stars ─────────────────────────────────────────────
(function() {
  const rnd = (a, b) => a + Math.random() * (b - a);
  const skyEl = document.getElementById('sky-canvas');
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
</script>

<footer style="text-align:center;padding:28px 24px 40px;position:relative;z-index:1;">
  <p style="font-size:12px;color:rgba(255,255,255,0.2);">
    <?= htmlspecialchars($appName) ?> &nbsp;·&nbsp;
    <a href="/coverage.php" style="color:rgba(124,106,247,0.6);text-decoration:none;">Coverage</a>
    &nbsp;·&nbsp;
    <a href="/about.php" style="color:rgba(124,106,247,0.6);text-decoration:none;">About</a>
    &nbsp;·&nbsp;
    <a href="/privacy.php" style="color:rgba(124,106,247,0.6);text-decoration:none;">Privacy Notice</a>
  </p>
</footer>

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

  // Filter bar — delegated (toggle filter + info button with stopPropagation)
  document.getElementById('practFilterBar')?.addEventListener('click', function(e) {
      const infoBtn = e.target.closest('[data-pt-info]');
      if (infoBtn) { e.stopPropagation(); showPtInfo(infoBtn.dataset.ptInfo); return; }
      const filterBtn = e.target.closest('[data-filter]');
      if (filterBtn) toggleFilter(filterBtn);
  });

  // Clear filters buttons
  document.getElementById('filterClearBtn')?.addEventListener('click', clearFilters);
  document.getElementById('filterClearBtn2')?.addEventListener('click', clearFilters);

  // Search-by-name — debounced 120ms so each keystroke isn't a layout thrash
  const _searchEl = document.getElementById('practSearchInput');
  if (_searchEl) {
      let _searchTimer = null;
      _searchEl.addEventListener('input', () => {
          clearTimeout(_searchTimer);
          _searchTimer = setTimeout(applyFilters, 120);
      });
      _searchEl.addEventListener('keydown', e => { if (e.key === 'Escape') { _searchEl.value = ''; applyFilters(); } });
  }

  // "Read more" toggles — delegated from practitioner grid
  document.getElementById('practGrid')?.addEventListener('click', function(e) {
      const btn = e.target.closest('[data-about-id]');
      if (btn) toggleCardAbout(btn.dataset.aboutId);
  });

  // Practice type info modal — backdrop click + close button
  document.getElementById('ptInfoModal')?.addEventListener('click', function(e) {
      if (e.target === this) closePtInfo();
  });
  document.getElementById('ptInfoCloseBtn')?.addEventListener('click', closePtInfo);

  // Avatar upload on profile page (index page profile edit form)
  document.getElementById('avatar_upload')?.addEventListener('change', function() { handleUpload(this); });
</script>

</body>
</html>
