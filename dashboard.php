<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
if (empty($_SESSION['practitioner_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/phi_crypto.php';
require_once __DIR__ . '/includes/banners.php';
require_once __DIR__ . '/includes/session_timeout.php';
require_once __DIR__ . '/db/cron_logger.php';
require_once __DIR__ . '/includes/cron_autotrigger.php';
requireSetup();
cron_maybe_trigger();

$practitionerId = (int)$_SESSION['practitioner_id'];
$pdo            = getDB();
$error          = '';
$success        = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Handle form actions ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Security token mismatch.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Create room ──
        if ($action === 'create_room') {
            $roomName = trim($_POST['room_name'] ?? '');
            if ($roomName === '') {
                $roomName = 'Session ' . strtolower(bin2hex(random_bytes(4)));
            }

            $bgPath     = null;
            $bgSelected = trim($_POST['bg_selected'] ?? '');

            if (!empty($_FILES['background']['name']) && $_FILES['background']['error'] === UPLOAD_ERR_OK) {
                $file         = $_FILES['background'];
                $allowedImg   = ['image/jpeg','image/png','image/gif','image/webp'];
                $allowedVid   = ['video/mp4','video/webm','video/ogg','video/quicktime'];
                $finfo        = new finfo(FILEINFO_MIME_TYPE);
                $mime         = $finfo->file($file['tmp_name']);
                $isVideo      = in_array($mime, $allowedVid, true);
                $maxSize      = $isVideo ? 200 * 1024 * 1024 : 5 * 1024 * 1024;
                $sizeLabel    = $isVideo ? '200 MB' : '5 MB';
                if (!in_array($mime, $allowedImg, true) && !$isVideo) {
                    $error = 'Only JPEG, PNG, GIF, WEBP images or MP4/WebM/OGG videos are allowed for backgrounds.';
                } elseif ($file['size'] > $maxSize) {
                    $error = 'Background file must be under ' . $sizeLabel . '.';
                } else {
                    $dir = __DIR__ . '/assets/backgrounds/' . $practitionerId . '/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','video/mp4'=>'mp4','video/webm'=>'webm','video/ogg'=>'ogv','video/quicktime'=>'mov'];
                    $ext  = $extMap[$mime] ?? 'bin';
                    $name = bin2hex(random_bytes(12)) . '.' . $ext;
                    move_uploaded_file($file['tmp_name'], $dir . $name);
                    $bgPath = '/assets/backgrounds/' . $practitionerId . '/' . $name;

                    $dashThumbPath = null;
                    $dashThumbData = trim($_POST['thumb_data'] ?? '');
                    if ($isVideo && $dashThumbData && str_starts_with($dashThumbData, 'data:image/jpeg;base64,')) {
                        $b64      = substr($dashThumbData, strlen('data:image/jpeg;base64,'));
                        $imgBytes = base64_decode($b64, true);
                        if ($imgBytes !== false && strlen($imgBytes) < 500000) {
                            $thumbFile = bin2hex(random_bytes(12)) . '.jpg';
                            file_put_contents($dir . $thumbFile, $imgBytes);
                            $dashThumbPath = '/assets/backgrounds/' . $practitionerId . '/' . $thumbFile;
                        }
                    }

                    $stmt = $pdo->prepare(
                        'INSERT INTO backgrounds (practitioner_id, file_path, original_name, mime_type, thumb_path) VALUES (?,?,?,?,?)'
                    );
                    $stmt->execute([$practitionerId, $bgPath, $file['name'], $mime, $dashThumbPath]);
                }
            } elseif ($bgSelected !== '') {
                $stmt = $pdo->prepare(
                    'SELECT file_path FROM backgrounds WHERE id = ? AND practitioner_id = ? LIMIT 1'
                );
                $stmt->execute([(int)$bgSelected, $practitionerId]);
                $bgRow = $stmt->fetch();
                if ($bgRow) $bgPath = $bgRow['file_path'];
            }

            if ($error === '') {
                $roomToken = bin2hex(random_bytes(16));
                $stmt = $pdo->prepare(
                    'INSERT INTO rooms (practitioner_id, name, background_path, room_token) VALUES (?,?,?,?)'
                );
                $stmt->execute([$practitionerId, $roomName, $bgPath, $roomToken]);
                $success = 'Room "' . htmlspecialchars($roomName) . '" created.';
            }
        }

        if ($action === 'delete_room' && $error === '') {
            $roomId = (int)($_POST['room_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT id FROM rooms WHERE id = ? AND practitioner_id = ? LIMIT 1');
            $stmt->execute([$roomId, $practitionerId]);
            if ($stmt->fetch()) {
                $pdo->prepare('DELETE FROM rooms WHERE id = ?')->execute([$roomId]);
                $success = 'Room deleted.';
            }
        }
    }
}

// ── Fetch data ─────────────────────────────────────────────────
$rooms = $pdo->prepare(
    'SELECT r.*,
     (SELECT COUNT(*) FROM sessions s
      JOIN participants p ON p.session_id = s.id
      WHERE s.room_id = r.id AND s.ended_at IS NULL) as participant_count
     FROM rooms r WHERE r.practitioner_id = ? ORDER BY r.created_at DESC'
);
$rooms->execute([$practitionerId]);
$rooms = $rooms->fetchAll();

$backgrounds = $pdo->prepare(
    'SELECT * FROM backgrounds WHERE practitioner_id = ? ORDER BY uploaded_at DESC'
);
$backgrounds->execute([$practitionerId]);
$backgrounds = $backgrounds->fetchAll();

$host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl   = $protocol . '://' . $host;

$practStmt = $pdo->prepare('SELECT * FROM practitioners WHERE id = ? LIMIT 1');
$practStmt->execute([$practitionerId]);
$practitioner = $practStmt->fetch();
$isAdmin      = !empty($practitioner['is_admin']);
$myName       = $practitioner['display_name'] ?? 'Account';
$myAvatar     = $practitioner['avatar_path'] ?? '';
$appTheme     = getAppTheme();
$appLogo      = getAppLogo();

$tz = $practitioner['timezone'] ?? 'UTC';
try { $tzObj = new DateTimeZone($tz); } catch (Exception $e) { $tzObj = new DateTimeZone('UTC'); }
$localNow   = new DateTime('now', $tzObj);
$utcStart   = (clone $localNow)->setTime(0,0,0)->setTimezone(new DateTimeZone('UTC'));
$utcEnd     = (clone $localNow)->setTime(23,59,59)->setTimezone(new DateTimeZone('UTC'));
$todayStmt  = $pdo->prepare(
    'SELECT b.*, r.name AS room_name, r.room_token, r.id AS room_id_val
     FROM bookings b
     LEFT JOIN rooms r ON r.id = b.room_id
     WHERE b.practitioner_id = ? AND b.status IN (\'pending\',\'scheduled\')
       AND b.scheduled_at BETWEEN ? AND ?
     ORDER BY b.scheduled_at ASC'
);
$todayStmt->execute([$practitionerId, $utcStart->format('Y-m-d H:i:s'), $utcEnd->format('Y-m-d H:i:s')]);
$todayBookings = $todayStmt->fetchAll();
phi_decrypt_bookings($todayBookings);

$pendingStmt = $pdo->prepare(
    'SELECT b.*, r.name AS room_name FROM bookings b
     LEFT JOIN rooms r ON r.id = b.room_id
     WHERE b.practitioner_id = ? AND b.status = \'pending\'
       AND b.scheduled_at >= UTC_TIMESTAMP()
     ORDER BY b.scheduled_at ASC
     LIMIT 20'
);
$pendingStmt->execute([$practitionerId]);
$pendingBookings = $pendingStmt->fetchAll();
phi_decrypt_bookings($pendingBookings);

$scheduledCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM bookings
     WHERE practitioner_id = ? AND status = \'scheduled\' AND scheduled_at >= UTC_TIMESTAMP()'
);
$scheduledCountStmt->execute([$practitionerId]);
$scheduledCount = (int)$scheduledCountStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard — Serenity Spaces</title>
  <link rel="stylesheet" href="/assets/css/fonts.css">
  <link rel="stylesheet" href="/assets/css/main.css">
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
    /* ── CMS Design System ──────────────────────────────────────── */
    :root {
      --font: 'Outfit', -apple-system, sans-serif;
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
      --sidebar-w:   232px;
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

    /* ── Light theme ── */
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
      overflow-x: hidden;
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
      font-size: 15px;
      font-weight: 700;
      color: var(--text);
      letter-spacing: -0.3px;
    }

    .sidebar-nav { flex: 1; padding: 14px 10px; }

    .nav-group { margin-bottom: 20px; }

    .nav-group-label {
      font-size: 10px;
      font-weight: 600;
      letter-spacing: 0.9px;
      text-transform: uppercase;
      color: var(--text-dim);
      padding: 0 10px;
      margin-bottom: 3px;
      display: block;
    }

    .nav-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 10px;
      border-radius: 8px;
      font-size: 13.5px;
      font-weight: 500;
      color: var(--text-muted);
      cursor: pointer;
      transition: background 0.14s, color 0.14s;
      text-decoration: none;
      border: none;
      background: transparent;
      width: 100%;
      text-align: left;
      font-family: var(--font);
      position: relative;
      margin-bottom: 1px;
    }
    .nav-item:hover { background: rgba(255,255,255,0.045); color: var(--text); }
    .nav-item.active {
      background: var(--accent-dim);
      color: var(--accent);
      font-weight: 600;
    }
    .nav-item.active::before {
      content: '';
      position: absolute;
      left: 0; top: 20%; height: 60%; width: 2px;
      background: var(--accent);
      border-radius: 0 2px 2px 0;
    }

    .nav-icon { width: 17px; height: 17px; flex-shrink: 0; opacity: 0.65; }
    .nav-item.active .nav-icon { opacity: 1; }
    .nav-item:hover .nav-icon { opacity: 0.9; }

    .nav-badge {
      margin-left: auto;
      background: var(--accent);
      color: #fff;
      font-size: 10px;
      font-weight: 700;
      border-radius: 10px;
      padding: 1px 7px;
      min-width: 20px;
      text-align: center;
    }

    .sidebar-footer {
      padding: 10px 10px 14px;
      border-top: 1px solid var(--border);
      flex-shrink: 0;
    }

    /* ── Main Body ── */
    .cms-body {
      display: flex;
      flex-direction: column;
      height: 100vh;
      overflow: hidden;
      background: var(--bg);
    }

    /* ── Topbar ── */
    .cms-topbar {
      height: var(--topbar-h);
      background: var(--sidebar-bg);
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 28px;
      flex-shrink: 0;
    }

    .topbar-breadcrumb {
      display: flex;
      align-items: center;
      gap: 7px;
      font-size: 13.5px;
    }
    .bc-root  { color: var(--text-muted); }
    .bc-sep   { color: var(--text-dim); font-size: 11px; }
    .bc-curr  { font-weight: 600; color: var(--text); }

    .topbar-right { display: flex; align-items: center; gap: 10px; }

    /* user-menu */
    .user-menu { position: relative; }
    .user-menu summary { list-style: none; }
    .user-menu summary::-webkit-details-marker { display: none; }
    .user-menu-btn {
      display: flex; align-items: center; gap: 8px;
      cursor: pointer; padding: 5px 8px;
      border-radius: 8px; transition: background 0.14s;
      user-select: none;
    }
    .user-menu-btn:hover { background: rgba(255,255,255,0.05); }
    .user-menu-avatar {
      width: 28px; height: 28px; border-radius: 50%;
      object-fit: cover; border: 1.5px solid var(--border-hi);
    }
    .user-menu-avatar-placeholder {
      width: 28px; height: 28px; border-radius: 50%;
      background: var(--surface3); border: 1.5px solid var(--border-hi);
      display: flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 700; color: var(--accent);
      font-family: var(--font);
    }
    .user-menu-name { font-size: 13px; font-weight: 500; color: var(--text); }
    .user-menu-chevron { width: 12px; height: 12px; color: var(--text-muted); transition: transform 0.2s; }
    .user-menu[open] .user-menu-chevron { transform: rotate(180deg); }
    .user-menu-dropdown {
      position: absolute; top: calc(100% + 6px); right: 0;
      min-width: 190px; background: var(--surface);
      border: 1px solid var(--border-hi);
      border-radius: 11px; box-shadow: 0 12px 40px rgba(0,0,0,0.5);
      z-index: 200; overflow: hidden; padding: 4px;
    }
    .user-menu-item {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 12px; font-size: 13px; color: var(--text-muted);
      cursor: pointer; background: transparent; border: none;
      width: 100%; text-align: left; font-family: var(--font);
      border-radius: 8px; text-decoration: none;
      transition: background 0.12s, color 0.12s;
    }
    .user-menu-item:hover { background: rgba(255,255,255,0.05); color: var(--text); }
    .user-menu-item svg { width: 14px; height: 14px; flex-shrink: 0; }
    .user-menu-divider { height: 1px; background: var(--border); margin: 3px 4px; }
    .user-menu-signout { color: var(--danger); }
    .user-menu-signout:hover { background: var(--danger-dim); color: var(--danger); }

    /* ── Content ── */
    .cms-content {
      flex: 1; overflow-y: auto;
      padding: 28px 36px;
      scroll-behavior: smooth;
    }

    /* ── Sections ── */
    .cms-section { display: none; }
    .cms-section.active { display: block; animation: fadeInUp 0.18s ease; }
    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(6px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* ── Section header ── */
    .sec-hdr {
      display: flex; align-items: flex-start;
      justify-content: space-between; margin-bottom: 24px; gap: 16px;
    }
    .sec-hdr h1 {
      font-size: 20px; font-weight: 700; color: var(--text);
      letter-spacing: -0.4px; line-height: 1.2;
    }
    .sec-hdr p { font-size: 13px; color: var(--text-muted); margin-top: 3px; }
    .sec-hdr-actions { display: flex; gap: 8px; align-items: center; flex-shrink: 0; padding-top: 2px; }

    /* ── Buttons ── */
    .btn-c {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; border-radius: 8px;
      border: 1px solid var(--border-hi);
      font-size: 13px; font-weight: 500; cursor: pointer;
      font-family: var(--font); background: var(--surface);
      color: var(--text); transition: background 0.14s, border-color 0.14s;
      text-decoration: none; white-space: nowrap;
    }
    .btn-c:hover { background: var(--surface2); }
    .btn-c.primary {
      background: var(--accent); border-color: var(--accent); color: #fff;
      box-shadow: 0 2px 14px var(--accent-glow);
    }
    .btn-c.primary:hover { background: #6a58e8; box-shadow: 0 2px 20px var(--accent-glow); }
    .btn-c.danger {
      background: var(--danger-dim); border-color: rgba(224,82,82,0.25); color: var(--danger);
    }
    .btn-c.danger:hover { background: rgba(224,82,82,0.2); border-color: var(--danger); }
    .btn-c.ghost { background: transparent; color: var(--text-muted); border-color: var(--border); }
    .btn-c.ghost:hover { background: rgba(255,255,255,0.04); color: var(--text); }
    .btn-c.sm { padding: 6px 12px; font-size: 12px; border-radius: 7px; }

    /* ── Stats row ── */
    .stats-row {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px; margin-bottom: 24px;
    }
    @media (max-width: 960px) { .stats-row { grid-template-columns: repeat(2, 1fr); } }

    .stat-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 12px; padding: 16px 18px;
    }
    .stat-label {
      font-size: 10px; font-weight: 600; letter-spacing: 0.7px;
      text-transform: uppercase; color: var(--text-dim); margin-bottom: 8px;
    }
    .stat-value { font-size: 28px; font-weight: 700; color: var(--text); line-height: 1; }
    .stat-value.accent { color: var(--accent); }
    .stat-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }

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
    .panel-body { padding: 20px; }

    /* ── Overview two-column ── */
    .overview-grid {
      display: grid;
      grid-template-columns: 1fr 300px;
      gap: 20px; align-items: start;
    }
    @media (max-width: 900px) { .overview-grid { grid-template-columns: 1fr; } }

    /* ── Schedule list (in panels) ── */
    .sched-list { display: flex; flex-direction: column; }

    .sched-item {
      display: flex; align-items: center; gap: 14px;
      padding: 12px 20px; transition: background 0.12s;
      border-bottom: 1px solid var(--border);
    }
    .sched-item:last-child { border-bottom: none; }
    .sched-item.clickable { cursor: pointer; }
    .sched-item.clickable:hover { background: rgba(255,255,255,0.025); }

    .sched-time {
      width: 68px; flex-shrink: 0;
      font-size: 12px; font-weight: 600; color: var(--accent);
    }
    .sched-item.pending .sched-time { color: var(--warning); }

    .sched-av {
      width: 32px; height: 32px; border-radius: 50%;
      flex-shrink: 0; object-fit: cover;
    }
    .sched-av-ph {
      width: 32px; height: 32px; border-radius: 50%;
      background: var(--surface3); flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; font-weight: 700; color: var(--accent);
    }

    .sched-info { flex: 1; min-width: 0; }
    .sched-name {
      font-size: 13.5px; font-weight: 500; color: var(--text);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .sched-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }

    .sched-pill {
      font-size: 10px; font-weight: 600; letter-spacing: 0.4px;
      text-transform: uppercase; padding: 2px 8px; border-radius: 20px; flex-shrink: 0;
    }
    .sched-pill.scheduled { background: var(--accent-dim); color: var(--accent); }
    .sched-pill.pending   { background: rgba(232,173,74,0.12); color: var(--warning); }

    .sched-empty { padding: 28px 20px; text-align: center; color: var(--text-muted); font-size: 13px; }

    /* ── Quick rooms list ── */
    .quick-room-row {
      display: flex; align-items: center; gap: 12px;
      padding: 10px 12px; background: var(--surface2);
      border: 1px solid var(--border); border-radius: 9px; margin-bottom: 8px;
    }
    .quick-room-row:last-child { margin-bottom: 0; }

    /* ── Pending booking rows ── */
    .booking-row {
      display: flex; align-items: center; gap: 14px;
      padding: 14px 20px; border-bottom: 1px solid var(--border);
    }
    .booking-row:last-child { border-bottom: none; }

    .bk-av {
      width: 36px; height: 36px; border-radius: 50%;
      flex-shrink: 0; object-fit: cover;
    }
    .bk-av-ph {
      width: 36px; height: 36px; border-radius: 50%;
      background: var(--surface3); flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; font-weight: 700; color: var(--accent);
    }

    .bk-info { flex: 1; min-width: 0; }
    .bk-name { font-size: 14px; font-weight: 600; color: var(--text); }
    .bk-meta { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

    .bk-controls { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

    .bk-room-select {
      background: var(--surface3); border: 1px solid var(--border);
      border-radius: 7px; padding: 6px 10px; font-size: 12px;
      color: var(--text); font-family: var(--font); outline: none; cursor: pointer;
      transition: border-color 0.15s;
    }
    .bk-room-select:focus { border-color: var(--accent); }

    /* ── Rooms grid ── */
    .rooms-grid-new {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(272px, 1fr));
      gap: 16px;
    }

    .room-card-new {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 14px; overflow: hidden;
      display: flex; flex-direction: column;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .room-card-new:hover {
      border-color: rgba(124,106,247,0.3);
      box-shadow: 0 4px 24px rgba(0,0,0,0.28);
    }

    .rcn-cover {
      height: 136px; position: relative; overflow: hidden;
      background: linear-gradient(135deg, var(--surface2), var(--surface3));
    }
    .rcn-cover img, .rcn-cover video {
      width: 100%; height: 100%; object-fit: cover; display: block; opacity: 0.8;
    }
    .rcn-cover-empty {
      height: 136px; display: flex; align-items: center; justify-content: center;
      background: linear-gradient(135deg, var(--surface2) 0%, var(--surface3) 100%);
      position: relative;
    }
    .rcn-cover-icon { font-size: 28px; opacity: 0.25; }

    .rcn-live-badge {
      position: absolute; top: 10px; right: 10px;
      background: rgba(0,0,0,0.68); backdrop-filter: blur(6px);
      border-radius: 20px; padding: 4px 10px;
      font-size: 11px; font-weight: 600; color: var(--success);
      display: flex; align-items: center; gap: 5px;
    }

    .rcn-live-strip {
      display: flex; align-items: center; gap: 6px;
      padding: 6px 16px; font-size: 11px; font-weight: 600;
      color: var(--success);
      background: rgba(93,184,124,0.07);
      border-bottom: 1px solid rgba(93,184,124,0.14);
    }

    .live-dot {
      width: 6px; height: 6px; border-radius: 50%;
      background: var(--success); flex-shrink: 0;
      box-shadow: 0 0 6px var(--success);
      animation: livePulse 1.5s ease-in-out infinite;
    }
    @keyframes livePulse { 0%,100%{opacity:1} 50%{opacity:0.35} }

    .rcn-body { padding: 14px 16px; flex: 1; }
    .rcn-name { font-size: 14.5px; font-weight: 600; color: var(--text); margin-bottom: 8px; }

    .rcn-link-row {
      display: flex; align-items: center; gap: 6px;
      background: var(--surface3); border-radius: 7px; padding: 6px 10px;
    }
    .rcn-link-text {
      font-size: 11px; color: var(--text-muted);
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1;
    }

    .btn-copy {
      background: transparent; border: none; cursor: pointer;
      color: var(--text-muted); padding: 2px; border-radius: 4px;
      display: inline-flex; align-items: center; gap: 4px;
      font-size: 11px; font-family: var(--font);
      transition: color 0.14s; flex-shrink: 0;
    }
    .btn-copy:hover { color: var(--accent); }
    .btn-copy svg { width: 13px; height: 13px; }

    .rcn-footer {
      padding: 11px 16px; border-top: 1px solid var(--border);
      display: flex; gap: 8px;
    }

    /* ── Create room panel ── */
    .create-panel {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 14px; margin-bottom: 24px; overflow: hidden;
    }
    .create-panel-body { padding: 22px 24px; }

    .f-label {
      display: block; font-size: 11.5px; font-weight: 600;
      color: var(--text-muted); margin-bottom: 6px; letter-spacing: 0.2px;
    }
    .f-input {
      width: 100%; background: var(--surface3); border: 1px solid var(--border);
      border-radius: 9px; padding: 10px 14px; font-size: 14px;
      color: var(--text); outline: none; font-family: var(--font);
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    .f-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(124,106,247,0.11); }
    .f-group { margin-bottom: 16px; }

    .bg-grid-new {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(84px, 1fr));
      gap: 8px; margin-top: 10px;
    }

    /* bg-thumb stays as-is for JS compatibility */
    .bg-thumb {
      width: 100%; aspect-ratio: 16/9;
      border-radius: 7px; overflow: hidden;
      cursor: pointer; border: 2px solid transparent;
      transition: border-color 0.15s; position: relative;
      background: var(--surface3);
    }
    .bg-thumb img, .bg-thumb video { width: 100%; height: 100%; object-fit: cover; pointer-events: none; }
    .bg-thumb:hover { border-color: var(--border-hi); }

    /* ── Tab bar ── */
    .tab-bar {
      display: flex; gap: 2px;
      border-bottom: 1px solid var(--border);
      margin-bottom: 14px;
    }
    .tab-btn {
      padding: 8px 14px; font-size: 13px; font-weight: 500;
      color: var(--text-muted); background: transparent; border: none;
      cursor: pointer; font-family: var(--font);
      border-bottom: 2px solid transparent; margin-bottom: -1px;
      transition: color 0.14s, border-color 0.14s;
    }
    .tab-btn:hover { color: var(--text); }
    .tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }
    /* support old .bg-tab class from dashboard.js */
    .bg-tab { padding: 8px 14px; font-size: 13px; font-weight: 500;
      color: var(--text-muted); background: transparent; border: none;
      cursor: pointer; font-family: var(--font);
      border-bottom: 2px solid transparent; margin-bottom: -1px;
      transition: color 0.14s, border-color 0.14s; }
    .bg-tab:hover { color: var(--text); }
    .bg-tab.active { color: var(--accent); border-bottom-color: var(--accent); }

    /* ── Client search + table ── */
    .client-search {
      width: 100%; background: var(--surface); border: 1px solid var(--border);
      border-radius: 10px; padding: 10px 16px; font-size: 14px;
      color: var(--text); outline: none; font-family: var(--font);
      transition: border-color 0.15s, box-shadow 0.15s; margin-bottom: 16px;
    }
    .client-search:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(124,106,247,0.1); }
    .client-search::placeholder { color: var(--text-muted); }

    .clients-wrap {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 14px; overflow: hidden;
    }
    .clients-table {
      width: 100%; border-collapse: collapse;
    }
    .clients-table th {
      font-size: 10px; font-weight: 600; letter-spacing: 0.8px;
      text-transform: uppercase; color: var(--text-dim);
      padding: 11px 16px; text-align: left;
      border-bottom: 1px solid var(--border);
    }
    .clients-table td {
      padding: 12px 16px; border-bottom: 1px solid var(--border);
      font-size: 13.5px; color: var(--text);
    }
    .clients-table tr:last-child td { border-bottom: none; }
    .clients-table tbody tr { cursor: pointer; transition: background 0.12s; }
    .clients-table tbody tr:hover td { background: rgba(255,255,255,0.025); }

    .cl-av {
      width: 30px; height: 30px; border-radius: 50%;
      object-fit: cover; flex-shrink: 0;
    }
    .cl-av-ph {
      width: 30px; height: 30px; border-radius: 50%;
      background: var(--surface3); flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 700; color: var(--accent);
    }
    .cl-name-cell { display: flex; align-items: center; gap: 10px; }
    .cl-name { font-weight: 600; }

    /* ── Client detail ── */
    .cl-detail {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 14px; overflow: hidden;
    }
    .cl-detail-stats {
      display: grid; grid-template-columns: repeat(3,1fr);
      gap: 1px; background: var(--border);
      border-bottom: 1px solid var(--border);
    }
    .cl-stat {
      background: var(--surface); padding: 16px 20px; text-align: center;
    }
    .cl-stat-val { font-size: 22px; font-weight: 700; color: var(--text); }
    .cl-stat-label { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

    /* ── Background library ── */
    .bg-lib-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(136px, 1fr));
      gap: 12px;
    }
    .bg-lib-thumb {
      aspect-ratio: 16/9; border-radius: 10px; overflow: hidden;
      border: 1px solid var(--border); position: relative;
      background: var(--surface2); transition: border-color 0.15s;
    }
    .bg-lib-thumb:hover { border-color: var(--border-hi); }
    .bg-lib-thumb img, .bg-lib-thumb video { width: 100%; height: 100%; object-fit: cover; }
    .bg-lib-name {
      position: absolute; bottom: 0; left: 0; right: 0;
      padding: 5px 8px;
      background: linear-gradient(transparent, rgba(0,0,0,0.65));
      font-size: 10px; color: rgba(255,255,255,0.8);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .bg-vid-badge {
      position: absolute; top: 5px; right: 5px;
      background: rgba(0,0,0,0.6); border-radius: 4px;
      padding: 2px 5px; font-size: 9px; color: rgba(255,255,255,0.85);
    }

    /* ── Empty state ── */
    .empty-c {
      text-align: center; padding: 48px 24px; color: var(--text-muted);
    }
    .empty-c-icon { font-size: 34px; margin-bottom: 12px; opacity: 0.45; }
    .empty-c h3 { font-size: 16px; color: var(--text); margin-bottom: 6px; font-weight: 600; }
    .empty-c p  { font-size: 13px; line-height: 1.6; max-width: 360px; margin: 0 auto 18px; }

    /* ── Alerts ── */
    .alert { border-radius: 10px; padding: 11px 16px; font-size: 13.5px; margin-bottom: 20px; }
    .alert-success { background: rgba(93,184,124,0.09); border: 1px solid rgba(93,184,124,0.22); color: #7ecf96; }
    .alert-error   { background: rgba(224,82,82,0.09);  border: 1px solid rgba(224,82,82,0.22);  color: #e88080; }

    /* ── Upload progress ── */
    #bg-upload-progress { margin-top: 8px; width: 160px; }
    #bg-upload-bar { height: 100%; width: 0%; background: var(--accent); transition: width 0.1s; border-radius: 2px; }
  </style>
</head>
<body class="dashboard-page theme-<?= htmlspecialchars($appTheme) ?>">
<?php renderBanners('dashboard'); ?>
<?php if (empty($practitioner['mfa_secret'])):
  $mfaEnforced    = getSetting('enforce_practitioner_mfa', '0') === '1';
  $mfaGraceDaysD  = max(1, (int)getSetting('mfa_grace_days', '7'));
  $mfaEnabledAtD  = getSetting('mfa_enforce_enabled_at', '');
  $mfaDeadlineStr = '';
  $mfaUrgent      = false;
  if ($mfaEnforced && $mfaEnabledAtD) {
      $deadline       = (new DateTime($mfaEnabledAtD))->modify("+{$mfaGraceDaysD} days");
      $mfaDeadlineStr = $deadline->format('F j, Y');
      $mfaUrgent      = new DateTime() >= (new DateTime($mfaEnabledAtD))->modify('+' . ($mfaGraceDaysD - 2) . ' days');
  }
?>
<div id="mfa-notice" style="
  background:linear-gradient(135deg,<?= $mfaUrgent ? 'rgba(224,122,48,0.18),rgba(224,122,48,0.08)' : 'rgba(124,106,247,0.14),rgba(124,106,247,0.06)' ?>);
  border-bottom:1px solid <?= $mfaUrgent ? 'rgba(224,122,48,0.3)' : 'rgba(124,106,247,0.25)' ?>;
  padding:10px 24px;
  display:flex;align-items:center;gap:14px;
  font-size:13px;color:rgba(220,216,255,0.9);
  position:relative;z-index:200;">
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="<?= $mfaUrgent ? '#f5a94a' : '#a78bfa' ?>" stroke-width="1.6" style="flex-shrink:0"><path d="M8 1a4 4 0 0 1 4 4v2H4V5a4 4 0 0 1 4-4z"/><rect x="2" y="7" width="12" height="8" rx="2"/><circle cx="8" cy="11" r="1.2" fill="<?= $mfaUrgent ? '#f5a94a' : '#a78bfa' ?>" stroke="none"/></svg>
  <span style="flex:1">
    <strong style="color:<?= $mfaUrgent ? '#f5c842' : '#c4b5fd' ?>">Two-factor authentication is not enabled.</strong>
    <?php if ($mfaDeadlineStr): ?>
      MFA is required for this platform. You must set it up by <strong><?= htmlspecialchars($mfaDeadlineStr) ?></strong> or you will be redirected to setup on your next login.
    <?php else: ?>
      Enabling MFA adds a critical layer of protection for PHI access.
    <?php endif; ?>
    <a href="/profile.php?section=mfa" style="color:<?= $mfaUrgent ? '#f5a94a' : '#a78bfa' ?>;text-decoration:underline;text-underline-offset:2px">Set up MFA now →</a>
  </span>
  <?php if (!$mfaEnforced): ?>
  <button id="mfa-notice-dismiss-btn" aria-label="Dismiss" style="background:none;border:none;cursor:pointer;color:rgba(167,139,250,0.5);font-size:18px;line-height:1;padding:2px 4px;">&times;</button>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="cms-wrap">

  <!-- ═══════════════════════════════ SIDEBAR ═══════════════════════════════ -->
  <aside class="cms-sidebar" id="cms-sidebar">

    <div class="sidebar-brand">
      <?php if ($appLogo): ?>
        <img src="<?= htmlspecialchars($appLogo) ?>" alt="Logo" class="app-logo-img" style="max-height:48px;max-width:160px;">
      <?php else: ?>
        <span class="brand-name">Serenity Spaces</span>
      <?php endif; ?>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-group">
        <span class="nav-group-label">Workspace</span>

        <button class="nav-item active" id="nav-overview" data-dash-section="overview">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <rect x="1.5" y="1.5" width="6.5" height="6.5" rx="1.5"/>
            <rect x="10" y="1.5" width="6.5" height="6.5" rx="1.5"/>
            <rect x="1.5" y="10" width="6.5" height="6.5" rx="1.5"/>
            <rect x="10" y="10" width="6.5" height="6.5" rx="1.5"/>
          </svg>
          Overview
        </button>

        <button class="nav-item" id="nav-rooms" data-dash-section="rooms">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <rect x="2" y="3" width="14" height="13" rx="1.5"/>
            <line x1="7" y1="3" x2="7" y2="16"/>
            <circle cx="13" cy="9.5" r="1.2" fill="currentColor" stroke="none"/>
          </svg>
          Rooms
          <?php if (count($rooms) > 0): ?>
            <span class="nav-badge"><?= count($rooms) ?></span>
          <?php endif; ?>
        </button>

        <button class="nav-item" id="nav-schedule" data-dash-section="schedule">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <rect x="2" y="3.5" width="14" height="13" rx="1.5"/>
            <line x1="2" y1="7.5" x2="16" y2="7.5"/>
            <line x1="6" y1="1.5" x2="6" y2="5.5"/>
            <line x1="12" y1="1.5" x2="12" y2="5.5"/>
            <line x1="5.5" y1="11" x2="8.5" y2="11"/>
            <line x1="5.5" y1="13.5" x2="10" y2="13.5"/>
          </svg>
          Schedule
          <?php if ($scheduledCount > 0): ?>
            <span class="nav-badge"><?= $scheduledCount ?></span>
          <?php endif; ?>
        </button>

        <button class="nav-item" id="nav-clients" data-dash-section="clients">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <circle cx="7" cy="6" r="3"/>
            <path d="M1 16c0-3.3 2.7-6 6-6s6 2.7 6 6"/>
            <path d="M13 4c1.7 0 3 1.3 3 3s-1.3 3-3 3"/>
            <path d="M15 13c1 .4 1.7 1.3 2 2.5"/>
          </svg>
          Clients
        </button>

        <button class="nav-item" id="nav-journeys" data-dash-section="journeys">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <path d="M3 14 Q5 4 9 7 Q13 10 15 4"/>
            <circle cx="3"  cy="14" r="1.3" fill="currentColor" stroke="none"/>
            <circle cx="9"  cy="7"  r="1.3" fill="currentColor" stroke="none"/>
            <circle cx="15" cy="4"  r="1.3" fill="currentColor" stroke="none"/>
          </svg>
          Journeys
        </button>

        <button class="nav-item" id="nav-finance" data-dash-section="finance">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <rect x="1.5" y="4" width="15" height="11" rx="1.5"/>
            <line x1="1.5" y1="8" x2="16.5" y2="8"/>
            <circle cx="5.5" cy="12" r="1" fill="currentColor" stroke="none"/>
            <line x1="8.5" y1="12" x2="12.5" y2="12"/>
          </svg>
          Finances
        </button>

        <button class="nav-item" id="nav-inbox" data-dash-section="inbox">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <path d="M2 4h14v10a1 1 0 01-1 1H3a1 1 0 01-1-1V4z"/>
            <polyline points="2,4 9,10 16,4"/>
          </svg>
          Messages
          <span class="ib-badge" id="dashInboxBadge" style="display:none;"></span>
        </button>

        <button class="nav-item" id="nav-referrals" data-dash-section="referrals">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <path d="M3 9l4-4M3 9l4 4M3 9h8a4 4 0 014 4v2"/>
          </svg>
          Referrals
          <span class="nav-badge" id="dashReferralsBadge" style="display:none;"></span>
        </button>


        <?php if ($backgrounds): ?>
        <button class="nav-item" id="nav-backgrounds" data-dash-section="backgrounds">
          <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
            <rect x="1.5" y="3" width="15" height="12" rx="1.5"/>
            <circle cx="6.5" cy="7.5" r="1.5"/>
            <path d="M1.5 13l4-4.5 3.5 4 2.5-2.5 4 5"/>
          </svg>
          Backgrounds
        </button>
        <?php endif; ?>
      </div>
    </nav>

    <div class="sidebar-footer">
      <a href="/profile.php" class="nav-item">
        <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
          <circle cx="9" cy="6" r="3.5"/>
          <path d="M2 17c0-3.9 3.1-7 7-7s7 3.1 7 7"/>
        </svg>
        Edit Profile
      </a>
      <?php if ($isAdmin): ?>
      <a href="/admin.php" class="nav-item">
        <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6">
          <path d="M9 1.5L2 4.5v5c0 4.5 3.2 8.3 7 9.5 3.8-1.2 7-5 7-9.5v-5l-7-3z"/>
        </svg>
        Admin Panel
      </a>
      <?php endif; ?>
      <a href="/ropa.php" class="nav-item" style="font-size:11px;opacity:0.5;" title="Record of Processing Activities — GDPR Art. 30">
        <svg class="nav-icon" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.7;">
          <rect x="2" y="1" width="14" height="16" rx="2"/>
          <line x1="5.5" y1="6"  x2="12.5" y2="6"/>
          <line x1="5.5" y1="9"  x2="12.5" y2="9"/>
          <line x1="5.5" y1="12" x2="9"    y2="12"/>
        </svg>
        ROPA
      </a>
    </div>

  </aside>

  <!-- ═══════════════════════════════ MAIN ══════════════════════════════════ -->
  <div class="cms-body">

    <!-- Topbar -->
    <header class="cms-topbar">
      <div style="display:flex;align-items:center;gap:10px;">
        <button class="topbar-hamburger" id="hamburger-btn" aria-label="Toggle menu">
          <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" width="18" height="18"><line x1="2" y1="5" x2="16" y2="5"/><line x1="2" y1="9" x2="16" y2="9"/><line x1="2" y1="13" x2="16" y2="13"/></svg>
        </button>
        <div class="topbar-breadcrumb">
          <span class="bc-root">Dashboard</span>
          <span class="bc-sep">/</span>
          <span class="bc-curr" id="topbar-label">Overview</span>
        </div>
      </div>
      <div class="topbar-right">
        <details class="user-menu" id="user-menu">
          <summary class="user-menu-btn">
            <?php if ($myAvatar): ?>
              <img src="<?= htmlspecialchars($myAvatar) ?>" alt="" class="user-menu-avatar">
            <?php else: ?>
              <div class="user-menu-avatar-placeholder"><?= mb_strtoupper(mb_substr($myName,0,1)) ?></div>
            <?php endif; ?>
            <span class="user-menu-name"><?= htmlspecialchars($myName) ?></span>
            <svg class="user-menu-chevron" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="2,4 6,8 10,4"/>
            </svg>
          </summary>
          <div class="user-menu-dropdown">
            <a href="/profile.php" class="user-menu-item">
              <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="5" r="3"/><path d="M2 14c0-3.3 2.7-6 6-6s6 2.7 6 6"/></svg>
              Edit Profile
            </a>
            <?php if ($isAdmin): ?>
            <a href="/admin.php" class="user-menu-item">
              <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9" y="2" width="5" height="5" rx="1"/><rect x="2" y="9" width="5" height="5" rx="1"/><rect x="9" y="9" width="5" height="5" rx="1"/></svg>
              Admin Panel
            </a>
            <?php endif; ?>
            <div class="user-menu-divider"></div>
            <form method="post" action="/api/auth.php" style="margin:0;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="logout">
              <button type="submit" class="user-menu-item user-menu-signout">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2H3a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/><polyline points="11,11 14,8 11,5"/><line x1="14" y1="8" x2="6" y2="8"/></svg>
                Sign Out
              </button>
            </form>
          </div>
        </details>
      </div>
    </header>

    <!-- Content -->
    <main class="cms-content">

      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <!-- ══════════════ OVERVIEW ══════════════ -->
      <section class="cms-section active" id="sec-overview">
        <div class="sec-hdr">
          <div>
            <h1>Welcome back<?= $myName ? ', ' . htmlspecialchars($myName) : '' ?></h1>
            <p><?= $localNow->format('l, F j') ?> &nbsp;·&nbsp; <?= count($rooms) ?> room<?= count($rooms) !== 1 ? 's' : '' ?> in your workspace</p>
          </div>
          <div class="sec-hdr-actions">
            <button id="overview-calendar-btn" class="btn-c ghost sm">
              <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" style="width:13px;height:13px;"><rect x="1" y="2.5" width="12" height="10" rx="1.5"/><line x1="1" y1="5.5" x2="13" y2="5.5"/><line x1="4.5" y1="1" x2="4.5" y2="4"/><line x1="9.5" y1="1" x2="9.5" y2="4"/></svg>
              Full Calendar
            </button>
            <button id="overview-new-room-btn" class="btn-c primary">
              + New Room
            </button>
          </div>
        </div>

        <div class="stats-row">
          <div class="stat-card">
            <div class="stat-label">Rooms</div>
            <div class="stat-value"><?= count($rooms) ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Today</div>
            <div class="stat-value accent"><?= count($todayBookings) ?></div>
            <div class="stat-sub">appointment<?= count($todayBookings) !== 1 ? 's' : '' ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?= count($pendingBookings) ?></div>
            <div class="stat-sub">booking<?= count($pendingBookings) !== 1 ? 's' : '' ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Scheduled</div>
            <div class="stat-value"><?= $scheduledCount ?></div>
            <div class="stat-sub">upcoming</div>
          </div>
        </div>

        <div class="overview-grid">

          <!-- Left column -->
          <div>
            <?php if (!empty($pendingBookings)): ?>
            <div class="panel">
              <div class="panel-hdr">
                <span class="panel-title">Pending Bookings</span>
                <span style="font-size:11px;color:var(--text-muted);"><?= count($pendingBookings) ?> awaiting response</span>
              </div>
              <?php foreach ($pendingBookings as $bk):
                $bkDt = new DateTime($bk['scheduled_at'], new DateTimeZone('UTC'));
                $bkDt->setTimezone($tzObj);
                $bkName   = $bk['guest_name'] ?: 'Unknown';
                $bkAvatar = $bk['guest_avatar'] ?? '';
                $rooms_opts = '';
                foreach ($rooms as $r) {
                    $rooms_opts .= '<option value="' . $r['id'] . '">' . htmlspecialchars($r['name']) . '</option>';
                }
              ?>
              <div class="booking-row" id="booking-<?= $bk['id'] ?>">
                <div>
                  <?php if ($bkAvatar && str_starts_with($bkAvatar, '/assets/')): ?>
                    <img src="<?= htmlspecialchars($bkAvatar) ?>" alt="" class="bk-av">
                  <?php else: ?>
                    <div class="bk-av-ph"><?= mb_strtoupper(mb_substr($bkName,0,1)) ?></div>
                  <?php endif; ?>
                </div>
                <div class="bk-info">
                  <div class="bk-name"><?= htmlspecialchars($bkName) ?></div>
                  <div class="bk-meta"><?= htmlspecialchars($bkDt->format('D, M j · g:i A T')) ?></div>
                  <?php if ($bk['guest_email']): ?>
                    <div class="bk-meta"><?= htmlspecialchars($bk['guest_email']) ?></div>
                  <?php endif; ?>
                </div>
                <div class="bk-controls">
                  <?php if (!empty($rooms)): ?>
                    <select class="bk-room-select" id="room-sel-<?= $bk['id'] ?>">
                      <option value="">Assign room</option>
                      <?= $rooms_opts ?>
                    </select>
                    <button class="btn-c primary sm" data-confirm-booking="<?= $bk['id'] ?>" data-room-sel="room-sel-<?= $bk['id'] ?>">Confirm</button>
                  <?php else: ?>
                    <span style="font-size:12px;color:var(--text-muted);">Create a room first</span>
                  <?php endif; ?>
                  <button class="btn-c danger sm" data-cancel-booking="<?= $bk['id'] ?>">Decline</button>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($rooms)): ?>
            <div class="panel">
              <div class="panel-hdr">
                <span class="panel-title">Active Rooms</span>
                <button id="overview-view-rooms-btn" class="btn-c ghost sm">View all</button>
              </div>
              <div class="panel-body">
                <?php foreach (array_slice($rooms, 0, 5) as $room):
                  $inviteUrl = $baseUrl . '/index.php?token=' . urlencode($room['room_token']);
                ?>
                <div class="quick-room-row">
                  <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:2px;"><?= htmlspecialchars($room['name']) ?></div>
                    <?php if ($room['participant_count'] > 0): ?>
                      <div style="font-size:11px;color:var(--success);display:flex;align-items:center;gap:4px;">
                        <span class="live-dot" style="width:5px;height:5px;"></span><?= $room['participant_count'] ?> live
                      </div>
                    <?php endif; ?>
                  </div>
                  <a href="/room.php?room_id=<?= $room['id'] ?>" class="btn-c primary sm">Enter</a>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if (empty($rooms) && empty($pendingBookings)): ?>
            <div class="panel">
              <div class="empty-c">
                <div class="empty-c-icon">🚪</div>
                <h3>No rooms yet</h3>
                <p>Create your first room to start hosting sessions.</p>
                <button id="overview-create-first-room-btn" class="btn-c primary">Create your first room</button>
              </div>
            </div>
            <?php endif; ?>
          </div>

          <!-- Right column — today's schedule -->
          <div>
            <div class="panel" id="schedule-sidebar">
              <div class="panel-hdr" id="schedule-sidebar-hdr">
                <div>
                  <div class="panel-title">Today's Schedule</div>
                  <div style="font-size:11px;color:var(--text-muted);margin-top:2px;" id="schedule-date"><?= $localNow->format('l, F j') ?></div>
                </div>
                <button id="schedule-sidebar-calendar-btn" class="btn-c ghost sm">Full Schedule</button>
              </div>
              <div class="sched-list" id="schedule-list-inner">
                <?php if (empty($todayBookings)): ?>
                  <div class="sched-empty">No appointments today.</div>
                <?php else: ?>
                  <?php foreach ($todayBookings as $appt):
                    $apptDt     = new DateTime($appt['scheduled_at'], new DateTimeZone('UTC'));
                    $apptDt->setTimezone($tzObj);
                    $apptTime   = $apptDt->format('g:i A');
                    $apptName   = $appt['guest_name'] ?: 'Unknown';
                    $apptAvatar = $appt['guest_avatar'] ?? '';
                    $apptStatus = $appt['status'] === 'scheduled' ? 'scheduled' : 'pending';
                    $apptJson   = htmlspecialchars(json_encode([
                      'id'           => (int)$appt['id'],
                      'guest_name'   => $apptName,
                      'guest_avatar' => $apptAvatar,
                      'guest_email'  => $appt['guest_email'] ?? '',
                      'scheduled_at' => $appt['scheduled_at'],
                      'status'       => $appt['status'],
                      'room_name'      => $appt['room_name'] ?? '',
                      'room_id'        => (int)($appt['room_id_val'] ?? 0),
                      'intake_form_id' => $appt['intake_form_id'] ?? null,
                      'intake_token'   => $appt['intake_token'] ?? null,
                    ]), ENT_QUOTES);
                  ?>
                  <div class="sched-item <?= $apptStatus ?> clickable" data-booking-json='<?= $apptJson ?>'>
                    <div class="sched-time"><?= htmlspecialchars($apptTime) ?></div>
                    <?php if ($apptAvatar && str_starts_with($apptAvatar, '/assets/')): ?>
                      <img src="<?= htmlspecialchars($apptAvatar) ?>" alt="" class="sched-av">
                    <?php else: ?>
                      <div class="sched-av-ph"><?= mb_strtoupper(mb_substr($apptName,0,1)) ?></div>
                    <?php endif; ?>
                    <div class="sched-info">
                      <div class="sched-name"><?= htmlspecialchars($apptName) ?></div>
                      <?php if ($appt['room_name']): ?>
                        <div class="sched-sub"><?= htmlspecialchars($appt['room_name']) ?></div>
                      <?php elseif ($appt['status'] === 'pending'): ?>
                        <div class="sched-sub" style="font-style:italic;">Awaiting room</div>
                      <?php endif; ?>
                    </div>
                    <span class="sched-pill <?= $apptStatus ?>"><?= ucfirst($appt['status']) ?></span>
                  </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          </div>

        </div><!-- /overview-grid -->
      </section>

      <!-- ══════════════ ROOMS ══════════════ -->
      <section class="cms-section" id="sec-rooms">
        <div class="sec-hdr">
          <div>
            <h1>Room Management</h1>
            <p>Create and manage your session rooms. Share the link with clients to invite them.</p>
          </div>
          <div class="sec-hdr-actions">
            <button class="btn-c primary" id="new-room-btn">+ New Room</button>
          </div>
        </div>

        <!-- Create room form -->
        <div class="create-panel" id="create-room-panel" style="display:none;">
          <div class="panel-hdr">
            <span class="panel-title">Create New Room</span>
            <button id="create-panel-cancel-btn" class="btn-c ghost sm">✕ Cancel</button>
          </div>
          <div class="create-panel-body">
            <form method="post" enctype="multipart/form-data" id="create-room-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="create_room">
              <input type="hidden" name="bg_selected" id="bg_selected_id" value="">

              <div class="f-group">
                <label class="f-label">Room name <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
                <input type="text" name="room_name" class="f-input" placeholder="e.g. Session Room A">
              </div>

              <div class="f-group">
                <label class="f-label">Background <span style="color:var(--text-muted);font-weight:400;">(optional — image or video)</span></label>
                <label class="btn-c" style="cursor:pointer;display:inline-flex;">
                  <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" style="width:13px;height:13px;"><path d="M7 1v8M4 4l3-3 3 3"/><path d="M1 10v2a1 1 0 001 1h10a1 1 0 001-1v-2"/></svg>
                  Upload file
                  <input type="file" id="background" name="background"
                         accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/ogg,video/quicktime"
                         style="display:none;">
                </label>
                <div id="bg-preview-wrap" style="display:none;margin-top:10px;">
                  <img id="bg-preview" style="display:none;width:160px;height:90px;object-fit:cover;border-radius:8px;border:2px solid var(--accent);">
                  <video id="bg-preview-video" style="display:none;width:160px;height:90px;object-fit:cover;border-radius:8px;border:2px solid var(--accent);" muted playsinline preload="metadata"></video>
                </div>
                <div id="bg-upload-progress" style="display:none;">
                  <div style="height:4px;background:var(--border);border-radius:2px;overflow:hidden;">
                    <div id="bg-upload-bar"></div>
                  </div>
                  <div id="bg-upload-pct" style="font-size:11px;color:var(--text-muted);margin-top:3px;">0%</div>
                </div>
              </div>

              <?php if ($backgrounds): ?>
              <div class="f-group">
                <label class="f-label">Or choose from library</label>
                <div class="tab-bar">
                  <button type="button" class="tab-btn active" id="dash-tab-images">Images</button>
                  <button type="button" class="tab-btn" id="dash-tab-videos">Videos</button>
                </div>
                <div class="bg-grid-new" id="bg-library">
                  <?php foreach ($backgrounds as $bg):
                    $isVid    = str_starts_with($bg['mime_type'] ?? '', 'video/');
                    $dataType = $isVid ? 'video' : 'image';
                  ?>
                  <div class="bg-thumb" data-bg-id="<?= $bg['id'] ?>" data-mime-type="<?= $dataType ?>"
                       data-select-bg="<?= $bg['id'] ?>" title="<?= htmlspecialchars($bg['original_name']) ?>">
                    <?php if ($isVid): ?>
                      <?php if (!empty($bg['thumb_path'])): ?>
                        <img src="<?= htmlspecialchars($bg['thumb_path']) ?>" alt="">
                      <?php else: ?>
                        <video src="<?= htmlspecialchars($bg['file_path']) ?>" muted preload="metadata"></video>
                      <?php endif; ?>
                      <span class="bg-vid-badge">▶</span>
                    <?php else: ?>
                      <img src="<?= htmlspecialchars($bg['file_path']) ?>" alt="<?= htmlspecialchars($bg['original_name']) ?>">
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endif; ?>

              <button type="submit" class="btn-c primary">Create Room</button>
            </form>
          </div>
        </div>

        <?php if (empty($rooms)): ?>
          <div class="panel">
            <div class="empty-c">
              <div class="empty-c-icon">🚪</div>
              <h3>No rooms yet</h3>
              <p>Create your first room to start hosting sessions with clients.</p>
              <button class="btn-c primary" id="rooms-create-first-btn">Create your first room</button>
            </div>
          </div>
        <?php else: ?>
          <div class="rooms-grid-new">
            <?php foreach ($rooms as $room):
              $inviteUrl = $baseUrl . '/index.php?token=' . urlencode($room['room_token']);
              $cardMime = $cardThumb = '';
              if ($room['background_path']) {
                $stmtBgMime = $pdo->prepare('SELECT mime_type, thumb_path FROM backgrounds WHERE file_path = ? AND practitioner_id = ? LIMIT 1');
                $stmtBgMime->execute([$room['background_path'], $practitionerId]);
                $bgMimeRow = $stmtBgMime->fetch();
                $cardMime  = $bgMimeRow ? ($bgMimeRow['mime_type'] ?? '') : '';
                $cardThumb = $bgMimeRow ? ($bgMimeRow['thumb_path'] ?? '') : '';
              }
            ?>
            <div class="room-card-new">

              <?php if ($room['background_path']): ?>
                <div class="rcn-cover">
                  <?php if (str_starts_with($cardMime, 'video/')): ?>
                    <?php if (!empty($cardThumb)): ?>
                      <img src="<?= htmlspecialchars($cardThumb) ?>" alt="">
                    <?php else: ?>
                      <video src="<?= htmlspecialchars($room['background_path']) ?>" muted preload="metadata"></video>
                    <?php endif; ?>
                  <?php else: ?>
                    <img src="<?= htmlspecialchars($room['background_path']) ?>" alt="">
                  <?php endif; ?>
                  <?php if ($room['participant_count'] > 0): ?>
                    <div class="rcn-live-badge"><span class="live-dot"></span><?= $room['participant_count'] ?> live</div>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div class="rcn-cover-empty">
                  <div class="rcn-cover-icon">🚪</div>
                  <?php if ($room['participant_count'] > 0): ?>
                    <div class="rcn-live-badge" style="position:absolute;top:10px;right:10px;"><span class="live-dot"></span><?= $room['participant_count'] ?> live</div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <?php if ($room['participant_count'] > 0 && !$room['background_path']): ?>
                <div class="rcn-live-strip"><span class="live-dot"></span><?= $room['participant_count'] ?> active participant<?= $room['participant_count'] !== 1 ? 's' : '' ?></div>
              <?php endif; ?>

              <div class="rcn-body">
                <div class="rcn-name"><?= htmlspecialchars($room['name']) ?></div>
                <div class="rcn-link-row">
                  <span class="rcn-link-text"><?= htmlspecialchars($inviteUrl) ?></span>
                  <button class="btn-copy" data-copy-link="<?= htmlspecialchars($inviteUrl) ?>">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
                      <rect x="5" y="5" width="8" height="8" rx="1.5"/><path d="M3 11V3h8"/>
                    </svg>
                    Copy
                  </button>
                </div>
              </div>

              <div class="rcn-footer">
                <a href="/room.php?room_id=<?= $room['id'] ?>" class="btn-c primary sm" style="flex:1;justify-content:center;">Enter Room</a>
                <form method="post" style="margin:0;" data-confirm="Delete this room and all its data?">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="delete_room">
                  <input type="hidden" name="room_id" value="<?= $room['id'] ?>">
                  <button type="submit" class="btn-c danger sm">Delete</button>
                </form>
              </div>

            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- ══════════════ SCHEDULE ══════════════ -->
      <section class="cms-section" id="sec-schedule">
        <div class="sec-hdr">
          <div>
            <h1>Schedule</h1>
            <p>Your appointments calendar and pending bookings.</p>
          </div>
          <div class="sec-hdr-actions">
            <button id="schedule-open-calendar-btn" class="btn-c primary">Open Full Calendar (<?= $scheduledCount ?>)</button>
          </div>
        </div>

        <div class="panel">
          <div class="panel-hdr">
            <span class="panel-title">Today — <?= $localNow->format('l, F j') ?></span>
          </div>
          <?php if (empty($todayBookings)): ?>
            <div class="sched-empty">No appointments scheduled for today.</div>
          <?php else: ?>
            <div class="sched-list">
              <?php foreach ($todayBookings as $appt):
                $apptDt     = new DateTime($appt['scheduled_at'], new DateTimeZone('UTC'));
                $apptDt->setTimezone($tzObj);
                $apptTime   = $apptDt->format('g:i A');
                $apptName   = $appt['guest_name'] ?: 'Unknown';
                $apptAvatar = $appt['guest_avatar'] ?? '';
                $apptStatus = $appt['status'] === 'scheduled' ? 'scheduled' : 'pending';
                $apptJson   = htmlspecialchars(json_encode([
                  'id' => (int)$appt['id'], 'guest_name' => $apptName,
                  'guest_avatar' => $apptAvatar, 'guest_email' => $appt['guest_email'] ?? '',
                  'scheduled_at' => $appt['scheduled_at'], 'status' => $appt['status'],
                  'room_name' => $appt['room_name'] ?? '', 'room_id' => (int)($appt['room_id_val'] ?? 0),
                  'intake_form_id' => $appt['intake_form_id'] ?? null,
                  'intake_token'   => $appt['intake_token'] ?? null,
                ]), ENT_QUOTES);
              ?>
              <div class="sched-item <?= $apptStatus ?> clickable" data-booking-json='<?= $apptJson ?>'>
                <div class="sched-time"><?= htmlspecialchars($apptTime) ?></div>
                <?php if ($apptAvatar && str_starts_with($apptAvatar, '/assets/')): ?>
                  <img src="<?= htmlspecialchars($apptAvatar) ?>" alt="" class="sched-av">
                <?php else: ?>
                  <div class="sched-av-ph"><?= mb_strtoupper(mb_substr($apptName,0,1)) ?></div>
                <?php endif; ?>
                <div class="sched-info">
                  <div class="sched-name"><?= htmlspecialchars($apptName) ?></div>
                  <?php if ($appt['room_name']): ?>
                    <div class="sched-sub"><?= htmlspecialchars($appt['room_name']) ?></div>
                  <?php elseif ($appt['status'] === 'pending'): ?>
                    <div class="sched-sub" style="font-style:italic;">Awaiting room assignment</div>
                  <?php endif; ?>
                </div>
                <span class="sched-pill <?= $apptStatus ?>"><?= ucfirst($appt['status']) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if (!empty($pendingBookings)): ?>
        <div class="panel">
          <div class="panel-hdr">
            <span class="panel-title">Pending — Awaiting Your Response</span>
            <span style="font-size:11px;color:var(--text-muted);"><?= count($pendingBookings) ?> pending</span>
          </div>
          <?php foreach ($pendingBookings as $bk):
            $bkDt     = new DateTime($bk['scheduled_at'], new DateTimeZone('UTC'));
            $bkDt->setTimezone($tzObj);
            $bkName   = $bk['guest_name'] ?: 'Unknown';
            $bkAvatar = $bk['guest_avatar'] ?? '';
          ?>
          <div class="sched-item pending">
            <div class="sched-time" style="width:54px;"><?= $bkDt->format('M j') ?></div>
            <?php if ($bkAvatar && str_starts_with($bkAvatar, '/assets/')): ?>
              <img src="<?= htmlspecialchars($bkAvatar) ?>" alt="" class="sched-av">
            <?php else: ?>
              <div class="sched-av-ph"><?= mb_strtoupper(mb_substr($bkName,0,1)) ?></div>
            <?php endif; ?>
            <div class="sched-info">
              <div class="sched-name"><?= htmlspecialchars($bkName) ?></div>
              <div class="sched-sub"><?= $bkDt->format('g:i A T') ?><?= $bk['guest_email'] ? ' · ' . htmlspecialchars($bk['guest_email']) : '' ?></div>
            </div>
            <span class="sched-pill pending">Pending</span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </section>

      <!-- ══════════════ CLIENTS ══════════════ -->
      <section class="cms-section" id="sec-clients">
        <div class="sec-hdr">
          <div>
            <h1>Clients</h1>
            <p>Search your client history and view session notes.</p>
          </div>
        </div>

        <input type="text" id="clientSearchInput" class="client-search"
               placeholder="Search by name, email, or session notes…">

        <div id="clientsList-wrap">
          <div class="clients-wrap">
            <div id="clientsList">
              <div style="padding:32px;text-align:center;color:var(--text-muted);font-size:13px;">Loading clients…</div>
            </div>
          </div>
        </div>

        <div id="clientDetail" style="display:none;margin-top:20px;">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <button id="close-client-detail-btn" class="btn-c ghost sm">← All Clients</button>
            <div id="clientDetailName" style="font-size:18px;font-weight:700;color:var(--text);"></div>
          </div>
          <div id="clientDetailContent"></div>
        </div>
      </section>

      <!-- ══════════════ BACKGROUNDS ══════════════ -->
      <?php if ($backgrounds): ?>
      <section class="cms-section" id="sec-backgrounds">
        <div class="sec-hdr">
          <div>
            <h1>Background Library</h1>
            <p>All uploaded backgrounds available for your rooms.</p>
          </div>
        </div>

        <div class="panel">
          <div class="panel-hdr">
            <span class="panel-title">Media Library</span>
            <span style="font-size:11px;color:var(--text-muted);"><?= count($backgrounds) ?> file<?= count($backgrounds) !== 1 ? 's' : '' ?></span>
          </div>
          <div class="panel-body">
            <div class="tab-bar">
              <button type="button" class="tab-btn active" id="dash-lib-tab-images">Images</button>
              <button type="button" class="tab-btn" id="dash-lib-tab-videos">Videos</button>
            </div>
            <div class="bg-lib-grid" id="bg-library-main">
              <?php foreach ($backgrounds as $bg):
                $isVidLib = str_starts_with($bg['mime_type'] ?? '', 'video/');
                $libType  = $isVidLib ? 'video' : 'image';
              ?>
              <div class="bg-lib-thumb bg-thumb" data-mime-type="<?= $libType ?>">
                <?php if ($isVidLib): ?>
                  <?php if (!empty($bg['thumb_path'])): ?>
                    <img src="<?= htmlspecialchars($bg['thumb_path']) ?>" alt="<?= htmlspecialchars($bg['original_name']) ?>">
                  <?php else: ?>
                    <video src="<?= htmlspecialchars($bg['file_path']) ?>" muted preload="metadata"></video>
                  <?php endif; ?>
                  <span class="bg-vid-badge">▶ video</span>
                <?php else: ?>
                  <img src="<?= htmlspecialchars($bg['file_path']) ?>" alt="<?= htmlspecialchars($bg['original_name']) ?>">
                <?php endif; ?>
                <div class="bg-lib-name"><?= htmlspecialchars($bg['original_name']) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <!-- ══════════════ MESSAGES ══════════════ -->
      <section class="cms-section" id="sec-inbox">
        <div class="sec-hdr">
          <div>
            <h1>Messages</h1>
            <p>Private messages with your clients outside of live sessions.</p>
          </div>
        </div>
        <div class="ib-layout">
          <div class="ib-list" id="dashIbList">
            <div class="empty-c"><div class="spinner"></div></div>
          </div>
          <div class="ib-thread-wrap" id="dashIbThread">
            <div class="empty-c" style="flex:1;display:flex;align-items:center;justify-content:center;">
              <div style="text-align:center;">
                <div style="font-size:32px;margin-bottom:8px;">✉</div>
                <p style="color:var(--text-muted);font-size:13px;">Select a conversation to view messages</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ══════════════ REFERRALS ══════════════ -->
      <section class="cms-section" id="sec-referrals">
        <div class="sec-hdr">
          <div>
            <h1>Referrals</h1>
            <p>Send a client to another practitioner, or review referrals sent to you. Nothing is shared until the recipient accepts.</p>
          </div>
          <button class="btn-c primary" id="ref-new-btn" type="button">+ New Referral</button>
        </div>

        <!-- Tabs: Inbox / Outbox -->
        <div style="display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:18px;">
          <button class="ref-tab-btn active" data-ref-box="in"  type="button">Received <span class="ref-tab-count" id="ref-in-count" style="margin-left:6px;font-family:var(--mono);font-size:10px;color:var(--text-muted);"></span></button>
          <button class="ref-tab-btn"        data-ref-box="out" type="button">Sent <span class="ref-tab-count" id="ref-out-count" style="margin-left:6px;font-family:var(--mono);font-size:10px;color:var(--text-muted);"></span></button>
        </div>

        <div id="ref-list" style="display:flex;flex-direction:column;gap:10px;">
          <div style="color:var(--text-muted);font-size:13px;padding:24px;text-align:center;">Loading…</div>
        </div>

        <!-- Detail / action panel — shown when a referral row is clicked -->
        <div id="ref-detail-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:200;align-items:flex-start;justify-content:center;padding:24px 16px;overflow-y:auto;">
          <div id="ref-detail-card" style="background:var(--surface);border:1px solid var(--border);border-radius:14px;max-width:640px;width:100%;padding:24px 28px;margin-top:60px;">
            <div id="ref-detail-body"><div class="spinner"></div></div>
          </div>
        </div>

        <!-- New-referral compose modal -->
        <div id="ref-compose-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:200;align-items:flex-start;justify-content:center;padding:24px 16px;overflow-y:auto;">
          <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;max-width:600px;width:100%;padding:24px 28px;margin-top:60px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
              <h2 style="font-size:18px;font-weight:700;color:var(--text);">Send a referral</h2>
              <button class="btn-c ghost sm" type="button" id="ref-compose-close">✕</button>
            </div>

            <div style="display:flex;flex-direction:column;gap:14px;">
              <!-- Recipient -->
              <div>
                <label class="form-label">Refer to</label>
                <input type="text" id="ref-recipient-search" class="input-field" placeholder="Search practitioner by name or email…" autocomplete="off">
                <div id="ref-recipient-results" style="margin-top:6px;display:none;background:var(--surface2);border:1px solid var(--border);border-radius:8px;max-height:180px;overflow-y:auto;"></div>
                <input type="hidden" id="ref-to-id" value="">
                <div id="ref-to-pill" style="display:none;margin-top:8px;padding:8px 12px;border-radius:8px;background:rgba(124,106,247,0.08);border:1px solid rgba(124,106,247,0.25);font-size:13px;display:flex;align-items:center;justify-content:space-between;"></div>
              </div>

              <!-- Client -->
              <div>
                <label class="form-label">About which client</label>
                <select id="ref-client-select" class="input-field">
                  <option value="">— Select an existing client —</option>
                </select>
                <div style="margin-top:8px;font-size:11.5px;color:var(--text-muted);">Or refer a client by email (no platform account):</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:6px;">
                  <input type="text" id="ref-guest-name" class="input-field" placeholder="Client name">
                  <input type="email" id="ref-guest-email" class="input-field" placeholder="Client email">
                </div>
              </div>

              <!-- Notes -->
              <div>
                <label class="form-label">Notes for the receiving practitioner</label>
                <textarea id="ref-notes" rows="4" class="input-field" placeholder="Why are you referring? Any context they should know before deciding…" style="resize:vertical;"></textarea>
              </div>

              <!-- Permissions -->
              <div>
                <label class="form-label">If they accept, share:</label>
                <div style="display:flex;flex-direction:column;gap:6px;margin-top:6px;font-size:13px;">
                  <label style="display:flex;align-items:center;gap:8px;cursor:not-allowed;color:var(--text-muted);">
                    <input type="checkbox" id="ref-share-basic" checked disabled> <span>Basic info (name, email) — always shared</span>
                  </label>
                  <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="ref-share-intake"> <span>Intake history (previous responses)</span>
                  </label>
                  <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="ref-share-notes"> <span>Session notes</span>
                  </label>
                  <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="ref-share-goals"> <span>Goals &amp; progress</span>
                  </label>
                  <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="ref-share-themes"> <span>Recurring themes / concept tags</span>
                  </label>
                </div>
              </div>

              <div id="ref-compose-error" style="display:none;font-size:12.5px;color:#e85555;"></div>
              <div id="ref-compose-success" style="display:none;font-size:12.5px;color:#3ecf8e;"></div>

              <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px;">
                <button class="btn-c ghost" type="button" id="ref-compose-cancel-btn">Cancel</button>
                <button class="btn-c primary" type="button" id="ref-compose-send-btn">Send Referral</button>
              </div>
              <p style="font-size:11.5px;color:var(--text-muted);line-height:1.55;margin:0;">
                Nothing transfers until the recipient accepts. The client is automatically notified by email when they do.
              </p>
            </div>
          </div>
        </div>
      </section>

      <!-- ══════════════ FINANCES ══════════════ -->
      <section class="cms-section" id="sec-finance">
        <div class="sec-hdr">
          <div>
            <h1>Finances</h1>
            <p>Track payments and session earnings for your records.</p>
          </div>
          <div class="sec-hdr-actions">
            <button class="btn-c ghost sm" id="fin-export-btn">↓ Export CSV</button>
          </div>
        </div>

        <!-- Summary cards -->
        <div id="fin-summary" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:24px;">
          <div class="panel" style="padding:18px 20px;">
            <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:6px;">This Month</div>
            <div id="fin-month-total" style="font-size:24px;font-weight:700;color:var(--text);">—</div>
            <div id="fin-month-sessions" style="font-size:12px;color:var(--text-muted);margin-top:2px;"></div>
          </div>
          <div class="panel" style="padding:18px 20px;">
            <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:6px;">All Time</div>
            <div id="fin-all-total" style="font-size:24px;font-weight:700;color:var(--text);">—</div>
            <div id="fin-all-sessions" style="font-size:12px;color:var(--text-muted);margin-top:2px;"></div>
          </div>
          <div class="panel" style="padding:18px 20px;">
            <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:6px;">Pending</div>
            <div id="fin-pending-total" style="font-size:24px;font-weight:700;color:var(--warning);">—</div>
            <div id="fin-pending-count" style="font-size:12px;color:var(--text-muted);margin-top:2px;"></div>
          </div>
          <div class="panel" style="padding:18px 20px;">
            <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:6px;">Free Sessions</div>
            <div id="fin-free-count" style="font-size:24px;font-weight:700;color:var(--text);">—</div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">this month</div>
          </div>
        </div>

        <!-- Monthly bar chart -->
        <div class="panel" style="padding:20px 24px;margin-bottom:20px;">
          <div style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px;">Monthly Breakdown</div>
          <div id="fin-monthly-chart" style="display:flex;align-items:flex-end;gap:6px;height:80px;">
            <div style="color:var(--text-muted);font-size:12px;line-height:80px;">Loading…</div>
          </div>
        </div>

        <!-- Payment records table -->
        <div class="panel" style="padding:0;overflow:hidden;">
          <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <span style="font-size:13px;font-weight:600;">Payment Records</span>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <select id="fin-filter-status" style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:12px;padding:5px 10px;">
                <option value="">All Statuses</option>
                <option value="paid">Paid</option>
                <option value="pending">Pending</option>
                <option value="waived">Waived</option>
                <option value="failed">Failed</option>
              </select>
              <select id="fin-filter-gateway" style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:12px;padding:5px 10px;">
                <option value="">All Gateways</option>
                <option value="paypal">PayPal</option>
                <option value="stripe">Stripe</option>
                <option value="bitcoin">Bitcoin</option>
                <option value="square">Square</option>
                <option value="free">Free</option>
                <option value="manual">Manual</option>
                <option value="waived">Waived</option>
              </select>
            </div>
          </div>
          <div id="fin-table-wrap" style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
              <thead>
                <tr style="border-bottom:1px solid var(--border);">
                  <th style="text-align:left;padding:10px 20px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap;">Date</th>
                  <th style="text-align:left;padding:10px 12px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.06em;">Client</th>
                  <th style="text-align:right;padding:10px 12px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.06em;">Amount</th>
                  <th style="text-align:left;padding:10px 12px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.06em;">Gateway</th>
                  <th style="text-align:left;padding:10px 12px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.06em;">Status</th>
                  <th style="text-align:left;padding:10px 20px;color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.06em;">Actions</th>
                </tr>
              </thead>
              <tbody id="fin-table-body">
                <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted);">Loading…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ══════════════ JOURNEYS ══════════════ -->
      <section class="cms-section" id="sec-journeys">
        <div class="sec-hdr">
          <div>
            <h1>Session Journeys</h1>
            <p>Track inquiry arcs and progress across multiple sessions with the same client.</p>
          </div>
          <div class="sec-hdr-actions">
            <button id="new-journey-btn" class="btn-c primary sm">+ New Journey</button>
          </div>
        </div>

        <!-- Create series panel -->
        <div id="create-series-panel" style="display:none;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:24px;">
          <div style="font-size:14px;font-weight:700;margin-bottom:16px;">New Journey</div>
          <div style="display:grid;gap:12px;">
            <div>
              <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:5px;">Journey Name <span style="color:#e85555;">*</span></label>
              <input type="text" id="series-name" maxlength="255"
                     placeholder="e.g. Sarah — Grief & Loss Work"
                     style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text);font-size:13px;font-family:inherit;outline:none;box-sizing:border-box;">
            </div>
            <div>
              <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:5px;">Central Question or Theme</label>
              <textarea id="series-description" rows="2" maxlength="1000"
                        placeholder="The opening inquiry or through-line for this journey…"
                        style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text);font-size:13px;font-family:inherit;resize:vertical;outline:none;box-sizing:border-box;line-height:1.5;"></textarea>
            </div>
            <div>
              <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:5px;">Client Email (optional)</label>
              <input type="email" id="series-client-email" maxlength="255"
                     placeholder="for your reference only"
                     style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text);font-size:13px;font-family:inherit;outline:none;box-sizing:border-box;">
            </div>
          </div>
          <div style="display:flex;gap:8px;margin-top:16px;">
            <button id="create-series-btn" class="btn-c primary sm">Create Journey</button>
            <button id="cancel-series-btn" class="btn-c ghost sm">Cancel</button>
          </div>
        </div>

        <!-- Series list -->
        <div id="series-list-wrap">
          <div id="series-list" style="display:flex;flex-direction:column;gap:12px;">
            <div style="text-align:center;padding:40px 20px;color:var(--text-muted);">Loading journeys…</div>
          </div>
        </div>

        <!-- Series detail view -->
        <div id="series-detail" style="display:none;">
          <button id="back-to-journeys-btn" class="btn-c ghost sm" style="margin-bottom:18px;">← Back to Journeys</button>
          <div id="series-detail-header" style="margin-bottom:20px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
              <div>
                <h2 id="sd-name" style="font-size:18px;font-weight:700;margin-bottom:4px;"></h2>
                <p id="sd-description" style="font-size:13px;color:var(--text-muted);line-height:1.5;max-width:560px;"></p>
              </div>
              <button id="edit-series-btn" class="btn-c ghost sm" style="flex-shrink:0;">Edit</button>
            </div>
          </div>

          <!-- Arc notes (shared across sessions) -->
          <div style="background:rgba(124,106,247,0.06);border:1px solid rgba(124,106,247,0.18);border-radius:10px;padding:16px 18px;margin-bottom:24px;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:rgba(124,106,247,0.8);margin-bottom:8px;">Arc Notes</div>
            <textarea id="sd-shared-notes" rows="3" maxlength="4000"
                      placeholder="Ongoing observations, evolving themes, cross-session patterns…"
                      style="width:100%;background:transparent;border:none;color:var(--text);font-size:13px;font-family:inherit;resize:vertical;outline:none;box-sizing:border-box;line-height:1.55;"

                      ></textarea>
            <div id="sd-notes-status" style="font-size:11px;color:var(--text-muted);margin-top:4px;height:14px;"></div>
          </div>

          <!-- Sessions in this series -->
          <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:12px;">Sessions in this Journey</div>
          <div id="sd-sessions" style="display:flex;flex-direction:column;gap:10px;">
            <div style="color:var(--text-muted);font-size:13px;padding:16px;">No sessions assigned yet. At the end of a session, use the recap form to assign it to this journey.</div>
          </div>
        </div>

        <!-- Edit series panel (inline) -->
        <div id="edit-series-panel" style="display:none;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:12px;padding:20px;margin-top:16px;">
          <div style="font-size:14px;font-weight:700;margin-bottom:16px;">Edit Journey</div>
          <input type="hidden" id="edit-series-id">
          <div style="display:grid;gap:12px;">
            <div>
              <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:5px;">Journey Name</label>
              <input type="text" id="edit-series-name" maxlength="255"
                     style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text);font-size:13px;font-family:inherit;outline:none;box-sizing:border-box;">
            </div>
            <div>
              <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:5px;">Central Question or Theme</label>
              <textarea id="edit-series-description" rows="2" maxlength="1000"
                        style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;color:var(--text);font-size:13px;font-family:inherit;resize:vertical;outline:none;box-sizing:border-box;line-height:1.5;"></textarea>
            </div>
          </div>
          <div style="display:flex;gap:8px;margin-top:16px;">
            <button id="save-edit-series-btn" class="btn-c primary sm">Save</button>
            <button id="cancel-edit-series-btn" class="btn-c ghost sm">Cancel</button>
            <button id="delete-series-btn" class="btn-c danger sm" style="margin-left:auto;">Delete Journey</button>
          </div>
        </div>
      </section>

    </main>
  </div>
</div>

<!-- ── Booking Detail Modal ── -->
<div id="booking-detail-modal" class="cal-modal-overlay" style="display:none;z-index:9100;">
  <div class="cal-modal-box booking-detail-box glass-panel" style="max-width:480px;">
    <div class="booking-detail-header drag-handle">
      <div class="booking-detail-avatar-wrap"><div id="bd-avatar"></div></div>
      <div class="booking-detail-title">
        <div class="booking-detail-name" id="bd-name"></div>
        <div class="booking-detail-status" id="bd-status"></div>
      </div>
      <button id="bd-close-btn" class="cal-close-btn">&#10005;</button>
    </div>
    <div class="booking-detail-body">
      <div class="booking-detail-row" id="bd-time-row">
        <span class="booking-detail-label">When</span>
        <span class="booking-detail-value" id="bd-time"></span>
      </div>
      <div class="booking-detail-row" id="bd-email-row" style="display:none;">
        <span class="booking-detail-label">Email</span>
        <span class="booking-detail-value" id="bd-email"></span>
      </div>
      <div class="booking-detail-row" id="bd-room-row" style="display:none;">
        <span class="booking-detail-label">Room</span>
        <span class="booking-detail-value" id="bd-room"></span>
      </div>
      <div class="booking-detail-row" id="bd-intake-row" style="display:none;">
        <span class="booking-detail-label">Intake Form</span>
        <span class="booking-detail-value" id="bd-intake-status"></span>
      </div>
    </div>
    <div id="bd-reschedule-form" style="display:none;padding:16px;background:rgba(255,255,255,0.03);border-radius:8px;margin-top:4px;">
      <div style="font-size:13px;font-weight:600;margin-bottom:12px;">Pick a new date &amp; time</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <div style="margin:0;flex:1;min-width:140px;">
          <label style="font-size:12px;display:block;margin-bottom:4px;color:var(--text-muted);">Date</label>
          <input type="date" id="bd-new-date" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:13px;outline:none;">
        </div>
        <div style="margin:0;flex:1;min-width:120px;">
          <label style="font-size:12px;display:block;margin-bottom:4px;color:var(--text-muted);">Time (your timezone)</label>
          <input type="time" id="bd-new-time" step="900" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:13px;outline:none;">
        </div>
      </div>
      <div style="margin-top:12px;display:flex;gap:8px;">
        <button id="bd-reschedule-confirm-btn" class="btn-c primary sm">Confirm Reschedule</button>
        <button id="bd-reschedule-cancel-btn" class="btn-c ghost sm">Cancel</button>
      </div>
    </div>
    <!-- Intake form assign panel (shown when no form assigned yet) -->
    <div id="bd-intake-assign" style="display:none;padding:12px 16px;background:rgba(255,255,255,.02);border-top:1px solid var(--border);">
      <div style="font-size:12.5px;font-weight:600;margin-bottom:8px;color:var(--text-muted);">Send Intake Form</div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <select id="bd-intake-form-select" style="flex:1;min-width:160px;background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:7px 10px;color:var(--text);font-size:13px;font-family:inherit;outline:none;">
          <option value="">— Select a form —</option>
        </select>
        <button id="bd-send-intake-btn" class="btn-c primary sm">Send</button>
        <button id="bd-intake-cancel-btn" class="btn-c ghost sm">Cancel</button>
      </div>
      <div id="bd-intake-assign-msg" style="font-size:12px;margin-top:6px;display:none;"></div>
    </div>
    <div class="booking-detail-actions" id="bd-actions">
      <button class="btn-c sm" id="bd-reschedule-btn">Reschedule</button>
      <button class="btn-c ghost sm" id="bd-intake-btn" style="display:none;">Send Intake Form</button>
      <button class="btn-c danger sm" id="bd-cancel-btn">Cancel Booking</button>
      <button id="bd-close-bottom-btn" class="btn-c ghost sm" style="margin-left:auto;">Close</button>
    </div>
  </div>
</div>

<!-- ── Full Schedule Calendar Modal ── -->
<div id="cal-modal" class="cal-modal-overlay" style="display:none;">
  <div class="cal-modal-box glass-panel">
    <div class="cal-modal-header drag-handle">
      <button id="cal-prev-btn" class="btn-c ghost sm">&#8592;</button>
      <span class="cal-month-label" id="cal-month-label"></span>
      <button id="cal-next-btn" class="btn-c ghost sm">&#8594;</button>
      <button id="cal-close-btn" class="cal-close-btn" title="Close">&#10005;</button>
    </div>
    <div class="cal-grid-head">
      <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
    </div>
    <div class="cal-grid" id="cal-grid">
      <div class="cal-loading">Loading…</div>
    </div>
    <div class="cal-legend">
      <span class="cal-legend-dot cal-dot-scheduled"></span>Scheduled
      <span class="cal-legend-dot cal-dot-pending"></span>Pending
      <span class="cal-legend-dot cal-dot-completed"></span>Completed
      <span class="cal-legend-dot cal-dot-cancelled"></span>Cancelled
    </div>
    <div class="cal-legend cal-avail-legend">
      <span class="cal-avail-swatch cal-avail-working"></span>Working day
      <span class="cal-avail-swatch cal-avail-excluded"></span>Excluded
      <span class="cal-avail-swatch cal-avail-off"></span>Off
    </div>
  </div>
</div>

<!-- ── Session Review Overlay ── -->
<div id="srOverlay" class="sr-overlay" style="display:none;">
  <div class="sr-topbar">
    <button id="sr-close-top-btn" class="sr-close-btn">← Back</button>
    <div class="sr-topbar-title">
      <span id="srTitle">Session Log</span>
      <span class="sr-topbar-meta" id="srMeta"></span>
    </div>
    <button id="sr-close-bottom-btn" class="sr-close-btn">Close ✕</button>
  </div>
  <div class="sr-body">
    <div class="sr-chat" id="srChat"></div>
    <div class="sr-panel" id="srPanel"></div>
  </div>
</div>

<script src="/assets/js/dashboard.js"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
'use strict';
const PRACT_TZ = '<?= htmlspecialchars($tz) ?>';
const PRACT_ID = <?= (int)$practitionerId ?>;

// ── Section navigation ──────────────────────────────────────────
const DASH_CSRF = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';

const _sectionLabels = {
  overview: 'Overview', rooms: 'Rooms', schedule: 'Schedule',
  clients: 'Clients', journeys: 'Session Journeys', backgrounds: 'Backgrounds',
  inbox: 'Messages', finance: 'Finances', referrals: 'Referrals'
};

function showSection(id, navEl) {
  document.querySelectorAll('.cms-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  const sec = document.getElementById('sec-' + id);
  if (sec) sec.classList.add('active');
  if (navEl) navEl.classList.add('active');
  const lbl = document.getElementById('topbar-label');
  if (lbl) lbl.textContent = _sectionLabels[id] || id;
  if (id === 'clients'      && !clientsLoaded)   { clientsLoaded  = true; loadClients(''); }
  if (id === 'inbox'        && !dashIbLoaded)    { dashLoadConversations(); dashLoadMediaConfig(); }
  if (id === 'finance'      && !financeLoaded)   loadFinancialData();
  if (id === 'journeys'     && !journeysLoaded)  { journeysLoaded = true; loadSeriesList(); }
  if (id === 'referrals')   refLoadList();
  // Close mobile sidebar on nav
  document.getElementById('cms-sidebar')?.classList.remove('mobile-open');
}

function toggleMobileSidebar() {
  document.getElementById('cms-sidebar')?.classList.toggle('mobile-open');
}

function toggleCreatePanel(forceOpen) {
  const panel = document.getElementById('create-room-panel');
  if (!panel) return;
  const open = forceOpen !== undefined ? forceOpen : panel.style.display === 'none';
  panel.style.display = open ? 'block' : 'none';
  if (open) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// Close user-menu when clicking outside
document.addEventListener('click', e => {
  const m = document.getElementById('user-menu');
  if (m && m.open && !m.contains(e.target)) m.removeAttribute('open');
});

// ── Booking management ──────────────────────────────────────────
async function confirmBooking(bookingId, roomId) {
  if (!roomId) { alert('Please select a room first.'); return; }
  const res = await fetch('/api/bookings.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'assign_room', booking_id: bookingId, room_id: parseInt(roomId) }),
  });
  if (res.ok) {
    const card = document.getElementById('booking-' + bookingId);
    if (card) { card.style.opacity = '0.5'; card.innerHTML = '<div style="padding:12px;color:var(--text-muted);">Session confirmed — refreshing…</div>'; }
    setTimeout(() => location.reload(), 1200);
  } else { alert('Could not confirm booking. Please try again.'); }
}

async function cancelBooking(bookingId) {
  if (!confirm('Decline this booking?')) return;
  const res = await fetch('/api/bookings.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'cancel', booking_id: bookingId }),
  });
  if (res.ok) { const card = document.getElementById('booking-' + bookingId); if (card) card.remove(); }
}

// ── Real-time schedule refresh (every 60s) ──────────────────────
setInterval(async () => {
  try {
    const res  = await fetch('/api/bookings.php?action=today');
    const data = await res.json();
    if (!data.bookings) return;
    const inner = document.getElementById('schedule-list-inner');
    if (!inner) return;
    const tz = data.practitioner_tz || PRACT_TZ || 'UTC';
    if (!data.bookings.length) {
      inner.innerHTML = '<div class="sched-empty">No appointments today.</div>';
      return;
    }
    inner.innerHTML = '';
    data.bookings.forEach(b => {
      const dt   = new Date(b.scheduled_at.replace(' ', 'T') + 'Z');
      const time = dt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone: tz });
      const sc   = b.status === 'scheduled' ? 'scheduled' : 'pending';
      const name = b.guest_name || 'Unknown';
      const avHtml = (b.guest_avatar && b.guest_avatar.startsWith('/assets/'))
        ? `<img src="${b.guest_avatar}" alt="" class="sched-av">`
        : `<div class="sched-av-ph">${name.charAt(0).toUpperCase()}</div>`;
      const roomHtml = b.room_name
        ? `<div class="sched-sub">${b.room_name}</div>`
        : `<div class="sched-sub" style="font-style:italic;">Awaiting room</div>`;
      const div = document.createElement('div');
      div.className = `sched-item ${sc}`;
      div.innerHTML = `<div class="sched-time">${time}</div>${avHtml}<div class="sched-info"><div class="sched-name">${name}</div>${roomHtml}</div><span class="sched-pill ${sc}">${b.status.charAt(0).toUpperCase()+b.status.slice(1)}</span>`;
      inner.appendChild(div);
    });
  } catch(e) { /* silent — 60s schedule auto-refresh; stale data stays visible on transient errors */ }
}, 60000);

// ── Full Calendar ───────────────────────────────────────────────
let calYear, calMonth;
let calAvail = null; // cached availability: { slots: [{day_of_week, start_time, end_time}], exclusions: ['YYYY-MM-DD',...] }

async function fetchCalendarAvailability() {
  if (calAvail !== null) return;
  try {
    const res = await fetch(`/api/availability.php?practitioner_id=${PRACT_ID}`);
    const data = await res.json();
    calAvail = { slots: data.slots || [], exclusions: data.exclusions || [] };
  } catch(e) {
    calAvail = { slots: [], exclusions: [] };
  }
}

function calGetDayStatus(dateKey) {
  if (!calAvail) return 'off';
  const d = new Date(dateKey + 'T12:00:00');
  const dow = d.getDay(); // 0=Sun
  if (calAvail.exclusions.includes(dateKey)) return 'excluded';
  const hasSlot = calAvail.slots.some(s => parseInt(s.day_of_week, 10) === dow);
  return hasSlot ? 'working' : 'off';
}

function calBuildTooltip(dateKey) {
  if (!calAvail) return '';
  const d = new Date(dateKey + 'T12:00:00');
  const dow = d.getDay();
  const dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  if (calAvail.exclusions.includes(dateKey)) return dayNames[dow] + ' — excluded date';
  const slots = calAvail.slots.filter(s => parseInt(s.day_of_week, 10) === dow);
  if (!slots.length) return dayNames[dow] + ' — not available';
  const times = slots.map(s => s.start_time.slice(0,5) + '–' + s.end_time.slice(0,5)).join(', ');
  return dayNames[dow] + ' · ' + times;
}

function openCalendar() {
  const now = new Date(); calYear = now.getFullYear(); calMonth = now.getMonth();
  document.getElementById('cal-modal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
  fetchCalendarAvailability().then(() => renderCalendar());
}
function closeCalendar() { document.getElementById('cal-modal').style.display = 'none'; document.body.style.overflow = ''; }
function closeCalendarOnBackdrop(e) { if (e.target === document.getElementById('cal-modal')) closeCalendar(); }
function calPrevMonth() { calMonth--; if (calMonth < 0) { calMonth = 11; calYear--; } renderCalendar(); }
function calNextMonth() { calMonth++; if (calMonth > 11) { calMonth = 0; calYear++; } renderCalendar(); }

async function renderCalendar() {
  await fetchCalendarAvailability();
  const grid = document.getElementById('cal-grid');
  const label = document.getElementById('cal-month-label');
  const monthStr = `${calYear}-${String(calMonth + 1).padStart(2, '0')}`;
  label.textContent = new Date(calYear, calMonth, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
  grid.innerHTML = '<div class="cal-loading">Loading…</div>';
  let bookings = [];
  try {
    const res = await fetch(`/api/bookings.php?action=calendar&month=${monthStr}`);
    bookings = (await res.json()).bookings || [];
  } catch(e) { grid.innerHTML = '<div class="cal-loading" style="color:var(--danger)">Could not load schedule.</div>'; return; }
  const byDay = {};
  bookings.forEach(b => {
    const dt = new Date(b.scheduled_at.replace(' ', 'T') + 'Z');
    const k = dt.toLocaleDateString('en-CA', { timeZone: PRACT_TZ });
    if (!byDay[k]) byDay[k] = [];
    byDay[k].push(b);
  });
  const firstDay = new Date(calYear, calMonth, 1).getDay();
  const daysInMo = new Date(calYear, calMonth + 1, 0).getDate();
  const todayKey = new Date().toLocaleDateString('en-CA', { timeZone: PRACT_TZ });
  grid.innerHTML = '';
  for (let i = 0; i < firstDay; i++) { const b = document.createElement('div'); b.className = 'cal-cell cal-cell-empty'; grid.appendChild(b); }
  for (let d = 1; d <= daysInMo; d++) {
    const dateKey = `${calYear}-${String(calMonth+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    const isPast = dateKey < todayKey, isToday = dateKey === todayKey;
    const dayAppts = byDay[dateKey] || [];
    const cell = document.createElement('div');
    const dayStatus = calGetDayStatus(dateKey);
    cell.className = 'cal-cell cal-cell-' + dayStatus + (isPast ? ' cal-cell-past' : '') + (isToday ? ' cal-cell-today' : '');
    const dayNum = document.createElement('div'); dayNum.className = 'cal-day-num'; dayNum.textContent = d;
    const tooltip = calBuildTooltip(dateKey);
    if (tooltip) dayNum.title = tooltip;
    cell.appendChild(dayNum);
    dayAppts.forEach(b => {
      const dt = new Date(b.scheduled_at.replace(' ', 'T') + 'Z');
      const time = dt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone: PRACT_TZ });
      const name = b.guest_name || 'Unknown';
      const ev = document.createElement('div'); ev.className = `cal-event cal-event-${b.status}`; ev.title = `${name} — ${time}`;
      if (b.guest_avatar && b.guest_avatar.startsWith('/assets/')) { const img = document.createElement('img'); img.src = b.guest_avatar; img.alt = ''; img.className = 'cal-event-avatar'; ev.appendChild(img); }
      else { const init = document.createElement('div'); init.className = 'cal-event-avatar cal-event-avatar-initial'; init.textContent = name.charAt(0).toUpperCase(); ev.appendChild(init); }
      const ts = document.createElement('span'); ts.className = 'cal-event-time'; ts.textContent = time; ev.appendChild(ts);
      const ns = document.createElement('span'); ns.className = 'cal-event-name'; ns.textContent = name; ev.appendChild(ns);
      ev.addEventListener('click', e => { e.stopPropagation(); showBookingDetail(b); });
      cell.appendChild(ev);
    });
    grid.appendChild(cell);
  }
  const total = firstDay + daysInMo, rem = total % 7;
  if (rem > 0) for (let i = rem; i < 7; i++) { const b = document.createElement('div'); b.className = 'cal-cell cal-cell-empty'; grid.appendChild(b); }
}

// ── Keyboard close ──────────────────────────────────────────────
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeCalendar(); closeBookingDetail(); } });

// ── Booking Detail Modal ────────────────────────────────────────
let _currentBooking = null;

function showBookingDetail(b) {
  _currentBooking = b;
  const modal = document.getElementById('booking-detail-modal');
  if (!modal) return;
  const av = document.getElementById('bd-avatar');
  if (b.guest_avatar && b.guest_avatar.startsWith('/assets/')) av.innerHTML = `<img src="${b.guest_avatar}" alt="">`;
  else { const i = (b.guest_name || 'U').charAt(0).toUpperCase(); av.innerHTML = `<div class="booking-avatar-placeholder">${i}</div>`; }
  document.getElementById('bd-name').textContent = b.guest_name || 'Unknown';
  const sEl = document.getElementById('bd-status');
  sEl.textContent = (b.status || 'unknown').charAt(0).toUpperCase() + (b.status || '').slice(1);
  sEl.className = `booking-detail-status status-${b.status || 'unknown'}`;
  if (b.scheduled_at) {
    const dt = new Date(b.scheduled_at.replace(' ', 'T') + 'Z');
    document.getElementById('bd-time').textContent = dt.toLocaleString('en-US', { weekday:'short', month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit', timeZone: PRACT_TZ });
    document.getElementById('bd-time-row').style.display = '';
  } else { document.getElementById('bd-time-row').style.display = 'none'; }
  const emailRow = document.getElementById('bd-email-row');
  if (b.guest_email) { document.getElementById('bd-email').textContent = b.guest_email; emailRow.style.display = ''; } else { emailRow.style.display = 'none'; }
  const roomRow = document.getElementById('bd-room-row');
  if (b.room_name) { document.getElementById('bd-room').textContent = b.room_name; roomRow.style.display = ''; } else { roomRow.style.display = 'none'; }
  const cancellable = ['pending','scheduled'].includes(b.status);
  document.getElementById('bd-cancel-btn').style.display = cancellable ? '' : 'none';
  document.getElementById('bd-reschedule-btn').style.display = cancellable ? '' : 'none';
  toggleRescheduleForm(false);

  // Intake form status
  const intakeRow      = document.getElementById('bd-intake-row');
  const intakeStatus   = document.getElementById('bd-intake-status');
  const intakeBtn      = document.getElementById('bd-intake-btn');
  const intakeAssign   = document.getElementById('bd-intake-assign');
  if (intakeAssign) intakeAssign.style.display = 'none';
  if (b.guest_email) {
    intakeRow.style.display = '';
    if (b.intake_form_id) {
      intakeStatus.innerHTML = '<span style="color:#4caf87;">Form sent</span>';
      if (intakeBtn) intakeBtn.style.display = 'none';
    } else {
      intakeStatus.innerHTML = '<span style="color:var(--text-muted);">Not sent</span>';
      if (intakeBtn) intakeBtn.style.display = '';
    }
  } else {
    intakeRow.style.display = 'none';
    if (intakeBtn) intakeBtn.style.display = 'none';
  }

  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function closeBookingDetail() { const m = document.getElementById('booking-detail-modal'); if (m) m.style.display = 'none'; document.body.style.overflow = ''; _currentBooking = null; }
function closeDetailOnBackdrop(e) { if (e.target === document.getElementById('booking-detail-modal')) closeBookingDetail(); }

// ── Intake form assign ──────────────────────────────────────────
let _intakeForms = null;
async function openBdIntakeAssign() {
  const panel = document.getElementById('bd-intake-assign');
  if (!panel) return;
  panel.style.display = 'block';
  const sel = document.getElementById('bd-intake-form-select');
  // Load forms once
  if (!_intakeForms) {
    try {
      const res  = await fetch('/api/intake.php?action=list');
      const data = await res.json();
      _intakeForms = (data.forms || []).filter(f => f.is_active);
    } catch(e) { _intakeForms = []; }
  }
  sel.innerHTML = '<option value="">— Select a form —</option>' +
    _intakeForms.map(f => `<option value="${f.id}">${escHtml(f.name)} (${(f.fields||[]).length} questions)</option>`).join('');
  const msg = document.getElementById('bd-intake-assign-msg');
  if (msg) msg.style.display = 'none';
  if (!_intakeForms.length) {
    sel.innerHTML = '<option value="">No active forms — create one in Profile → Intake Forms</option>';
  }
}

async function sendIntakeForm() {
  if (!_currentBooking) return;
  const formId = document.getElementById('bd-intake-form-select').value;
  const msg    = document.getElementById('bd-intake-assign-msg');
  if (!formId) { if (msg) { msg.textContent = 'Please select a form.'; msg.style.cssText = 'display:block;color:#e05c5c;'; } return; }

  const fd = new FormData();
  fd.append('action',     'assign');
  fd.append('csrf_token',  DASH_CSRF);
  fd.append('booking_id',  _currentBooking.id);
  fd.append('form_id',     formId);

  try {
    const res  = await fetch('/api/intake.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      _currentBooking.intake_form_id = parseInt(formId);
      document.getElementById('bd-intake-assign').style.display = 'none';
      document.getElementById('bd-intake-status').innerHTML = '<span style="color:#4caf87;">Form sent' + (data.emailed ? ' — email delivered' : '') + '</span>';
      document.getElementById('bd-intake-btn').style.display = 'none';
    } else {
      if (msg) { msg.textContent = data.error || 'Could not send form.'; msg.style.cssText = 'display:block;color:#e05c5c;'; }
    }
  } catch(e) {
    if (msg) { msg.textContent = 'Network error.'; msg.style.cssText = 'display:block;color:#e05c5c;'; }
  }
}

function toggleRescheduleForm(show) {
  document.getElementById('bd-reschedule-form').style.display = show ? 'block' : 'none';
  document.getElementById('bd-actions').style.display = show ? 'none' : 'flex';
  if (show && _currentBooking && _currentBooking.scheduled_at) {
    const dt = new Date(_currentBooking.scheduled_at.replace(' ', 'T') + 'Z');
    document.getElementById('bd-new-date').value = dt.toLocaleDateString('en-CA', { timeZone: PRACT_TZ });
    document.getElementById('bd-new-time').value = dt.toLocaleTimeString('en-GB', { hour:'2-digit', minute:'2-digit', timeZone: PRACT_TZ });
  }
}

async function submitReschedule() {
  if (!_currentBooking) return;
  const dv = document.getElementById('bd-new-date').value, tv = document.getElementById('bd-new-time').value;
  if (!dv || !tv) { alert('Please pick a date and time.'); return; }
  const localDate = new Date(`${dv}T${tv}:00`);
  const tzOffset = getTimezoneOffset(PRACT_TZ, localDate);
  const utcStr = new Date(localDate.getTime() - tzOffset * 60000).toISOString().slice(0,19).replace('T',' ');
  const res = await fetch('/api/bookings.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'reschedule', booking_id: _currentBooking.id, new_scheduled_at: utcStr }),
  });
  const data = await res.json().catch(() => ({}));
  if (res.ok && (data.ok || data.success)) { closeBookingDetail(); setTimeout(() => location.reload(), 400); }
  else { alert(data.error || 'Could not reschedule. Please try again.'); }
}

async function submitDetailCancel() {
  if (!_currentBooking) return;
  if (!confirm(`Cancel booking for ${_currentBooking.guest_name || 'this client'}?`)) return;
  const res = await fetch('/api/bookings.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'cancel', booking_id: _currentBooking.id }),
  });
  if (res.ok) { closeBookingDetail(); setTimeout(() => location.reload(), 400); }
  else { alert('Could not cancel booking. Please try again.'); }
}

// ── Draggable modals ────────────────────────────────────────────
function makeDraggable(overlayEl, boxEl, handleEl) {
  let dragging = false, startX, startY, origLeft, origTop;
  handleEl.style.cursor = 'grab';
  handleEl.addEventListener('mousedown', e => {
    if (e.target.tagName === 'BUTTON' || e.target.closest('button')) return;
    dragging = true; handleEl.style.cursor = 'grabbing'; document.body.style.userSelect = 'none';
    const rect = boxEl.getBoundingClientRect();
    boxEl.style.position = 'fixed'; boxEl.style.left = rect.left+'px'; boxEl.style.top = rect.top+'px'; boxEl.style.margin = '0';
    overlayEl.style.alignItems = 'flex-start'; overlayEl.style.justifyContent = 'flex-start';
    origLeft = rect.left; origTop = rect.top; startX = e.clientX; startY = e.clientY; e.preventDefault();
  });
  document.addEventListener('mousemove', e => {
    if (!dragging) return;
    const newL = Math.max(0, Math.min(window.innerWidth - boxEl.offsetWidth, origLeft + e.clientX - startX));
    const newT = Math.max(0, Math.min(window.innerHeight - boxEl.offsetHeight, origTop + e.clientY - startY));
    boxEl.style.left = newL+'px'; boxEl.style.top = newT+'px';
  });
  document.addEventListener('mouseup', () => { if (dragging) { dragging = false; handleEl.style.cursor = 'grab'; document.body.style.userSelect = ''; } });
}

document.addEventListener('DOMContentLoaded', () => {
  const dO = document.getElementById('booking-detail-modal');
  const dB = dO?.querySelector('.booking-detail-box'), dH = dO?.querySelector('.drag-handle');
  if (dO && dB && dH) makeDraggable(dO, dB, dH);
  const cO = document.getElementById('cal-modal');
  const cB = cO?.querySelector('.cal-modal-box'), cH = cO?.querySelector('.drag-handle');
  if (cO && cB && cH) makeDraggable(cO, cB, cH);
});

function getTimezoneOffset(tz, date) {
  const utcMs = new Date(date.toLocaleString('en-US', { timeZone: 'UTC' })).getTime();
  const tzMs  = new Date(date.toLocaleString('en-US', { timeZone: tz })).getTime();
  return Math.round((tzMs - utcMs) / 60000);
}

// ── Journeys / Session Series section ───────────────────────────
let journeysLoaded  = false;
let _currentSeriesId = null;
let _arcNotesSaveTimer = null;

function showCreateSeriesPanel() {
  document.getElementById('create-series-panel').style.display = 'block';
  document.getElementById('series-name').focus();
}
function hideCreateSeriesPanel() {
  document.getElementById('create-series-panel').style.display = 'none';
  document.getElementById('series-name').value    = '';
  document.getElementById('series-description').value = '';
  document.getElementById('series-client-email').value = '';
}

async function createSeries() {
  const name = document.getElementById('series-name').value.trim();
  if (!name) { document.getElementById('series-name').focus(); return; }

  const res = await fetch('/api/series.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'create',
      name,
      description:  document.getElementById('series-description').value.trim(),
      client_email: document.getElementById('series-client-email').value.trim(),
    }),
  });
  const data = await res.json();
  if (data.ok) {
    hideCreateSeriesPanel();
    await loadSeriesList();
    openSeriesDetail(data.id);
  }
}

async function loadSeriesList() {
  const container = document.getElementById('series-list');
  const wrap = document.getElementById('series-list-wrap');
  container.innerHTML = '<div style="text-align:center;padding:40px 20px;color:var(--text-muted);">Loading…</div>';
  wrap.style.display = 'block';
  document.getElementById('series-detail').style.display = 'none';

  const res  = await fetch('/api/series.php?action=list');
  const data = await res.json();
  const list = data.series || [];

  if (!list.length) {
    container.innerHTML = `
      <div style="text-align:center;padding:48px 20px;color:var(--text-muted);">
        <div style="font-size:32px;margin-bottom:12px;">🗺</div>
        <div style="font-size:15px;font-weight:600;margin-bottom:6px;">No journeys yet</div>
        <div style="font-size:13px;line-height:1.55;">Create a journey to track a client's progress across multiple sessions.</div>
      </div>`;
    return;
  }

  if (!document.getElementById('journey-card-style')) {
    const _jcs = document.createElement('style');
    _jcs.id = 'journey-card-style';
    _jcs.textContent = '.journey-card:hover{border-color:rgba(124,106,247,0.4)!important;background:rgba(124,106,247,0.04)!important;}';
    document.head.appendChild(_jcs);
  }
  container.innerHTML = list.map(s => `
    <div class="journey-card" data-open-series="${s.id}" style="background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:10px;padding:16px 18px;cursor:pointer;transition:border-color 0.15s,background 0.15s;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
        <div style="flex:1;min-width:0;">
          <div style="font-size:14px;font-weight:700;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(s.name)}</div>
          ${s.description ? `<div style="font-size:12px;color:var(--text-muted);line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">${escHtml(s.description)}</div>` : ''}
          ${s.client_name ? `<div style="font-size:11px;color:rgba(124,106,247,0.8);margin-top:4px;">Client: ${escHtml(s.client_name)}</div>` : (s.client_email ? `<div style="font-size:11px;color:rgba(124,106,247,0.8);margin-top:4px;">${escHtml(s.client_email)}</div>` : '')}
        </div>
        <div style="flex-shrink:0;text-align:right;">
          <div style="font-size:22px;font-weight:700;color:var(--accent);">${s.session_count}</div>
          <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;">session${s.session_count != 1 ? 's' : ''}</div>
        </div>
      </div>
    </div>`).join('');
}

async function openSeriesDetail(id) {
  _currentSeriesId = id;
  document.getElementById('series-list-wrap').style.display = 'none';
  const detail = document.getElementById('series-detail');
  detail.style.display = 'block';
  document.getElementById('sd-sessions').innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:16px;">Loading…</div>';
  document.getElementById('edit-series-panel').style.display = 'none';

  const res  = await fetch(`/api/series.php?action=get&id=${id}`);
  const data = await res.json();
  if (!data.series) return;

  const s = data.series;
  document.getElementById('sd-name').textContent        = s.name;
  document.getElementById('sd-description').textContent = s.description || '';
  document.getElementById('sd-shared-notes').value      = s.shared_notes || '';
  document.getElementById('edit-series-id').value       = s.id;
  document.getElementById('edit-series-name').value     = s.name;
  document.getElementById('edit-series-description').value = s.description || '';

  const sessions = data.sessions || [];
  if (!sessions.length) {
    document.getElementById('sd-sessions').innerHTML =
      '<div style="color:var(--text-muted);font-size:13px;padding:16px;">No sessions assigned to this journey yet. Use the recap form when ending a session to assign it here.</div>';
  } else {
    document.getElementById('sd-sessions').innerHTML = sessions.map((sess, i) => {
      const date    = sess.started_at ? new Date(sess.started_at).toLocaleDateString('en-GB', {day:'numeric',month:'short',year:'numeric'}) : '—';
      const hasRecap= sess.key_summary || sess.key_insights || sess.agreed_actions || sess.next_focus;
      const num     = sessions.length - i;
      return `
        <div style="background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:10px;padding:14px 16px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:${hasRecap?'12px':'0'};">
            <div>
              <span style="font-size:11px;font-weight:700;color:var(--accent);text-transform:uppercase;letter-spacing:.05em;">Session ${num}</span>
              <span style="font-size:12px;color:var(--text-muted);margin-left:10px;">${date}</span>
              ${sess.room_name ? `<span style="font-size:12px;color:var(--text-muted);margin-left:8px;">· ${escHtml(sess.room_name)}</span>` : ''}
            </div>
            ${sess.transcript_exported_at ? '<span style="font-size:11px;color:rgba(100,210,100,0.7);">Exported</span>' : '<span style="font-size:11px;color:rgba(255,150,50,0.7);">In progress</span>'}
          </div>
          ${hasRecap ? `
            <div style="display:grid;gap:8px;">
              ${sess.key_summary  ? `<div><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:2px;">Explored</div><div style="font-size:12px;color:var(--text);line-height:1.5;">${escHtml(sess.key_summary)}</div></div>` : ''}
              ${sess.key_insights ? `<div><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:2px;">Insights</div><div style="font-size:12px;color:var(--text);line-height:1.5;">${escHtml(sess.key_insights)}</div></div>` : ''}
              ${sess.next_focus   ? `<div><div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:2px;">Next focus</div><div style="font-size:12px;color:var(--text);line-height:1.5;">${escHtml(sess.next_focus)}</div></div>` : ''}
            </div>` : '<div style="font-size:12px;color:var(--text-muted);">No recap recorded for this session.</div>'}
        </div>`;
    }).join('');
  }
}

function backToSeriesList() {
  _currentSeriesId = null;
  document.getElementById('series-detail').style.display = 'none';
  document.getElementById('series-list-wrap').style.display = 'block';
}

function showEditSeriesPanel() {
  document.getElementById('edit-series-panel').style.display = 'block';
  document.getElementById('edit-series-name').focus();
}
function hideEditSeriesPanel() {
  document.getElementById('edit-series-panel').style.display = 'none';
}

async function saveEditSeries() {
  const id   = parseInt(document.getElementById('edit-series-id').value, 10);
  const name = document.getElementById('edit-series-name').value.trim();
  if (!name || !id) return;
  const res = await fetch('/api/series.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'update', id, name,
      description: document.getElementById('edit-series-description').value.trim(),
    }),
  });
  if ((await res.json()).ok) {
    document.getElementById('sd-name').textContent        = name;
    document.getElementById('sd-description').textContent = document.getElementById('edit-series-description').value.trim();
    hideEditSeriesPanel();
  }
}

async function deleteSeries() {
  if (!_currentSeriesId) return;
  if (!confirm('Delete this journey? Sessions will be unlinked but not deleted.')) return;
  const res = await fetch('/api/series.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'delete', id: _currentSeriesId }),
  });
  if ((await res.json()).ok) {
    backToSeriesList();
    await loadSeriesList();
  }
}

function scheduleArcNotesSave() {
  clearTimeout(_arcNotesSaveTimer);
  document.getElementById('sd-notes-status').textContent = 'Saving…';
  _arcNotesSaveTimer = setTimeout(saveArcNotes, 1200);
}

async function saveArcNotes() {
  if (!_currentSeriesId) return;
  const notes = document.getElementById('sd-shared-notes').value.trim();
  const res = await fetch('/api/series.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'update', id: _currentSeriesId, shared_notes: notes }),
  });
  const ok = (await res.json()).ok;
  const el = document.getElementById('sd-notes-status');
  if (el) { el.textContent = ok ? 'Saved' : 'Save failed'; setTimeout(() => { if (el) el.textContent = ''; }, 2000); }
}

function escHtml(str) {
  if (!str) return '';
  return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Clients section ─────────────────────────────────────────────
let clientSearchTimer = null;
let clientsLoaded = false;

function debounceClientSearch() {
  clearTimeout(clientSearchTimer);
  clientSearchTimer = setTimeout(() => {
    loadClients(document.getElementById('clientSearchInput').value.trim());
  }, 300);
}

async function loadClients(query = '') {
  const el = document.getElementById('clientsList');
  el.innerHTML = '<div style="padding:32px;text-align:center;color:var(--text-muted);font-size:13px;">Loading…</div>';
  document.getElementById('clientDetail').style.display = 'none';
  document.getElementById('clientsList-wrap').style.display = 'block';
  try {
    const res  = await fetch('/api/practitioner_clients.php?action=list&q=' + encodeURIComponent(query));
    renderClients(await res.json());
  } catch(e) {
    el.innerHTML = '<div style="padding:32px;text-align:center;color:var(--text-muted);font-size:13px;">Unable to load clients.</div>';
  }
}

function renderClients(clients) {
  const el = document.getElementById('clientsList');
  if (!clients || clients.length === 0) {
    el.innerHTML = `<div style="padding:40px;text-align:center;color:var(--text-muted);font-size:13px;">No clients found. Clients appear once they have a booked or completed session with you.</div>`;
    return;
  }
  const rows = clients.map(c => {
    const initials = (c.display_name || c.guest_name || '?').charAt(0).toUpperCase();
    const name     = escHtml(c.display_name || c.guest_name || 'Unknown');
    const email    = escHtml(c.email || c.guest_email || '—');
    const cnt      = c.session_count || 0;
    const avHtml   = c.avatar_path
      ? `<img src="${escHtml(c.avatar_path)}" alt="" class="cl-av">`
      : `<div class="cl-av-ph">${initials}</div>`;
    const uid = c.end_user_id || 0;
    return `<tr data-client-uid="${uid}" data-client-name="${name}">
      <td><div class="cl-name-cell">${avHtml}<span class="cl-name">${name}</span></div></td>
      <td style="color:var(--text-muted);">${email}</td>
      <td style="color:var(--text-muted);">${cnt} session${cnt !== 1 ? 's' : ''}</td>
      <td><button class="btn-c ghost sm" data-view-client data-client-uid="${uid}" data-client-name="${name}">View →</button></td>
    </tr>`;
  }).join('');
  el.innerHTML = `<table class="clients-table">
    <thead><tr><th>Client</th><th>Email</th><th>Sessions</th><th></th></tr></thead>
    <tbody>${rows}</tbody>
  </table>`;
}

async function openClientDetail(endUserId, name) {
  document.getElementById('clientsList-wrap').style.display = 'none';
  document.getElementById('clientDetail').style.display = 'block';
  document.getElementById('clientDetailName').textContent = name;
  document.getElementById('clientDetailContent').innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:20px;">Loading…</div>';
  try {
    const res  = await fetch('/api/practitioner_clients.php?action=detail&end_user_id=' + encodeURIComponent(endUserId));
    renderClientDetail(await res.json());
  } catch(e) {
    document.getElementById('clientDetailContent').innerHTML = '<div style="color:var(--text-muted);padding:20px;">Unable to load client details.</div>';
  }
}

function closeClientDetail() {
  document.getElementById('clientDetail').style.display = 'none';
  document.getElementById('clientsList-wrap').style.display = 'block';
}

let _currentClientId = null;

function renderClientDetail(data) {
  const el = document.getElementById('clientDetailContent');
  const { client, sessions } = data;
  if (!client) { el.innerHTML = '<div style="color:var(--text-muted);padding:20px;">Client not found.</div>'; return; }
  _currentClientId = client.end_user_id || client.id;
  _themesLoadedFor = null; // reset so themes reload for new client

  const sessHtml = (sessions || []).map((s, i) => {
    const dt = new Date(s.started_at + ' UTC');
    const dateStr = dt.toLocaleDateString(undefined, { year:'numeric', month:'long', day:'numeric' });
    const timeStr = dt.toLocaleTimeString(undefined, { hour:'2-digit', minute:'2-digit' });
    const noteHtml = s.note_content
      ? `<div style="margin-top:10px;padding:12px;background:var(--accent-dim);border:1px solid rgba(124,106,247,0.15);border-radius:8px;font-size:13px;white-space:pre-wrap;color:var(--text-muted);">${escHtml(s.note_content)}</div>`
      : `<div style="margin-top:8px;font-size:13px;color:var(--text-dim);font-style:italic;">No notes for this session</div>`;
    const ratingHtml = s.rating
      ? `<div style="margin-top:6px;font-size:12px;color:var(--text-muted);">Rating: ${'★'.repeat(s.rating)}${'☆'.repeat(5-s.rating)}${s.rating_comment ? ' — ' + escHtml(s.rating_comment) : ''}</div>` : '';
    return `<div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:10px;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px;">
        <div>
          <div style="font-weight:600;font-size:13.5px;color:var(--text);">Session ${i+1}</div>
          <div style="font-size:12px;color:var(--text-muted);">${escHtml(dateStr)} at ${escHtml(timeStr)}${s.duration ? ' · ' + escHtml(s.duration) : ''}</div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          ${s.rating ? `<div style="font-size:14px;color:var(--warning);">${'★'.repeat(s.rating)}</div>` : ''}
          <button class="btn-c ghost sm" data-open-session-review="${s.session_id}" data-session-label="Session ${i+1}">View Log</button>
        </div>
      </div>
      ${noteHtml}${ratingHtml}
    </div>`;
  }).join('') || '<div style="color:var(--text-muted);font-size:13px;padding:16px;">No sessions yet.</div>';

  // Wellness sparkline blocks — one per recognised 1–10 wellness field
  const wellnessHtml = (data.wellness_series || []).map(series => {
      const pts = (series.points || []).filter(p => p && typeof p.value === 'number');
      if (pts.length < 2) return '';   // need ≥ 2 points to draw a line
      const w = 280, h = 56, pad = 6;
      const maxV = 10, minV = 1;
      const stepX = (w - pad * 2) / (pts.length - 1);
      const yFor  = v => pad + (h - pad * 2) * (1 - (v - minV) / (maxV - minV));
      const path = pts.map((p, i) => `${i === 0 ? 'M' : 'L'} ${(pad + i * stepX).toFixed(1)} ${yFor(p.value).toFixed(1)}`).join(' ');
      const dots = pts.map((p, i) => `<circle cx="${(pad + i * stepX).toFixed(1)}" cy="${yFor(p.value).toFixed(1)}" r="2.4" fill="#7c6af7"/>`).join('');
      const first = pts[0].value, last = pts[pts.length - 1].value;
      const delta = last - first;
      const trendColor = delta > 0 ? '#3ecf8e' : (delta < 0 ? '#e85555' : 'var(--text-muted)');
      const trendArrow = delta > 0 ? '↑' : (delta < 0 ? '↓' : '→');
      return `<div style="background:rgba(124,106,247,0.05);border:1px solid rgba(124,106,247,0.18);border-radius:10px;padding:14px 16px;margin-top:14px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:6px;">
          <div>
            <div style="font-size:11px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;color:rgba(197,184,255,0.85);">Wellness rating</div>
            <div style="font-size:13px;color:var(--text);margin-top:2px;">${escHtml(series.field_label)}</div>
          </div>
          <div style="font-size:11px;color:var(--text-muted);text-align:right;">
            <div>${pts.length} response${pts.length === 1 ? '' : 's'}</div>
            <div style="color:${trendColor};font-weight:600;margin-top:2px;">${trendArrow} ${first} → ${last}</div>
          </div>
        </div>
        <svg viewBox="0 0 ${w} ${h}" width="100%" height="${h}" preserveAspectRatio="none" style="display:block;">
          <line x1="${pad}" y1="${(yFor(5)).toFixed(1)}" x2="${w - pad}" y2="${(yFor(5)).toFixed(1)}" stroke="rgba(255,255,255,0.06)" stroke-dasharray="2 3"/>
          <path d="${path}" stroke="#7c6af7" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
          ${dots}
        </svg>
        <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-muted);margin-top:4px;font-family:'Outfit Mono',monospace;">
          <span>${escHtml((pts[0].date || '').slice(0,10))}</span>
          <span>${escHtml((pts[pts.length-1].date || '').slice(0,10))}</span>
        </div>
      </div>`;
  }).join('');

  el.innerHTML = `<div class="cl-detail">
    <div class="cl-detail-stats">
      <div class="cl-stat"><div class="cl-stat-val">${(sessions||[]).length}</div><div class="cl-stat-label">Sessions</div></div>
      <div class="cl-stat"><div class="cl-stat-val" style="color:var(--text-muted);font-size:13px;">${escHtml(client.email || '—')}</div><div class="cl-stat-label">Email</div></div>
      <div class="cl-stat"><div class="cl-stat-val" style="color:var(--warning);">${data.avg_rating ? Number(data.avg_rating).toFixed(1) : '—'}</div><div class="cl-stat-label">Avg Rating</div></div>
    </div>

    ${wellnessHtml ? `<div style="padding:0 20px;">${wellnessHtml}</div>` : ''}

    <!-- Practitioner-initiated booking + referral triggers -->
    <div style="padding:14px 20px 8px;display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;">
      <button class="btn-c sm" id="refer-client-btn" type="button"
              data-end-user-id="${client.end_user_id || ''}"
              data-client-name="${escHtml(client.display_name || client.name || '')}"
              data-client-email="${escHtml(client.email || '')}"
              style="background:rgba(124,106,247,0.12);border:1px solid rgba(124,106,247,0.35);color:#c5b8ff;">
        ↗ Refer to another Practitioner
      </button>
      <button class="btn-c primary sm" id="schedule-for-client-btn"
              data-end-user-id="${client.end_user_id || ''}"
              data-client-name="${escHtml(client.display_name || client.name || '')}"
              data-client-email="${escHtml(client.email || '')}">
        + Schedule a Session
      </button>
    </div>

    <!-- Schedule form panel — hidden until the button above is clicked -->
    <div id="schedule-for-client-panel" style="display:none;margin:0 20px 16px;padding:16px;border:1px solid var(--border);border-radius:10px;background:rgba(124,106,247,0.05);">
      <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:12px;">Schedule a session for ${escHtml(client.display_name || client.name || '')}</div>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Date</label>
            <input type="date" id="sfc-date" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:8px 10px;color:var(--text);font-family:inherit;font-size:13px;outline:none;">
          </div>
          <div>
            <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Time (your local)</label>
            <input type="time" id="sfc-time" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:8px 10px;color:var(--text);font-family:inherit;font-size:13px;outline:none;">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Duration (min)</label>
            <input type="number" id="sfc-duration" value="60" min="15" max="240" step="15" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:8px 10px;color:var(--text);font-family:inherit;font-size:13px;outline:none;">
          </div>
          <div>
            <label style="display:block;font-size:11px;color:var(--text-muted);margin-bottom:4px;">Room <span style="color:var(--text-dim);">(optional)</span></label>
            <select id="sfc-room" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:8px 10px;color:var(--text);font-family:inherit;font-size:13px;outline:none;">
              <option value="">— Assign later —</option>
            </select>
          </div>
        </div>
        <div id="sfc-error" style="display:none;font-size:12px;color:#e05c5c;"></div>
        <div id="sfc-success" style="display:none;font-size:12px;color:#3ecf8e;"></div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px;">
          <button class="btn-c ghost sm" id="sfc-cancel-btn">Cancel</button>
          <button class="btn-c primary sm" id="sfc-submit-btn">Send to client</button>
        </div>
      </div>
    </div>

    <!-- Tab bar -->
    <div style="display:flex;gap:0;border-bottom:1px solid var(--border);padding:0 20px;margin-bottom:0;">
      <button class="cl-tab active" id="cl-tab-sessions" data-cl-tab="sessions">Sessions</button>
      <button class="cl-tab" id="cl-tab-goals" data-cl-tab="goals">Goals</button>
      <button class="cl-tab" id="cl-tab-themes" data-cl-tab="themes">Themes</button>
    </div>

    <!-- Sessions tab -->
    <div id="cl-tab-content-sessions" style="padding:16px 20px;">
      ${sessHtml}
    </div>

    <!-- Themes tab -->
    <div id="cl-tab-content-themes" style="display:none;padding:16px 20px;">
      <div id="themes-wrap"><div style="color:var(--text-muted);font-size:13px;">Loading themes…</div></div>
    </div>

    <!-- Goals tab -->
    <div id="cl-tab-content-goals" style="display:none;padding:16px 20px;">
      <div id="goals-list-wrap" style="margin-bottom:20px;">
        <div style="color:var(--text-muted);font-size:13px;">Loading goals…</div>
      </div>
      <!-- Add goal form -->
      <div style="border:1px solid var(--border);border-radius:8px;padding:14px;background:rgba(255,255,255,.02);">
        <div style="font-size:13px;font-weight:600;margin-bottom:10px;color:var(--text);">Add Goal</div>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <input type="text" id="goal-title-input" placeholder="Goal title" style="background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:8px 12px;color:var(--text);font-size:13px;font-family:inherit;outline:none;width:100%;box-sizing:border-box;">
          <textarea id="goal-desc-input" placeholder="Description (optional)" rows="2" style="background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:8px 12px;color:var(--text);font-size:13px;font-family:inherit;outline:none;resize:vertical;width:100%;box-sizing:border-box;"></textarea>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <input type="date" id="goal-target-input" title="Target date (optional)" style="background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:8px 12px;color:var(--text);font-size:13px;font-family:inherit;outline:none;flex:1;">
            <button class="btn-c primary sm" id="add-client-goal-btn">Add Goal</button>
          </div>
        </div>
        <div id="goal-add-error" style="display:none;font-size:12px;color:#e05c5c;margin-top:6px;"></div>
      </div>
    </div>
  </div>`;

  // Attach tab styles (once)
  if (!document.getElementById('cl-tab-style')) {
    const s = document.createElement('style');
    s.id = 'cl-tab-style';
    s.textContent = `.cl-tab{background:none;border:none;padding:10px 16px;font-size:13px;font-weight:500;color:var(--text-muted);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;font-family:inherit;transition:color .15s,border-color .15s;}
    .cl-tab.active{color:var(--accent);border-bottom-color:var(--accent);}
    .cl-tab:hover{color:var(--text);}`;
    document.head.appendChild(s);
  }

  // Load goals for this client
  loadClientGoals(_currentClientId);
}

function switchClientTab(tab) {
  ['sessions','goals','themes'].forEach(t => {
    document.getElementById('cl-tab-' + t)?.classList.toggle('active', t === tab);
    const c = document.getElementById('cl-tab-content-' + t);
    if (c) c.style.display = t === tab ? 'block' : 'none';
  });
  if (tab === 'themes') loadClientThemes(_currentClientId);
}

// ── Concept Themes in client detail ────────────────────────────
let _themesLoadedFor = null;

async function loadClientThemes(endUserId) {
  const wrap = document.getElementById('themes-wrap');
  if (!wrap || !endUserId) return;
  if (_themesLoadedFor === endUserId) return; // already loaded
  _themesLoadedFor = endUserId;
  wrap.innerHTML = '<div style="color:var(--text-muted);font-size:13px;">Loading themes…</div>';
  try {
    const res  = await fetch(`/api/concept_tags.php?action=themes&end_user_id=${encodeURIComponent(endUserId)}`);
    const data = await res.json();
    renderThemesView(data.themes || []);
  } catch(e) {
    wrap.innerHTML = '<div style="color:var(--text-muted);font-size:13px;">Could not load themes.</div>';
    _themesLoadedFor = null;
  }
}

function renderThemesView(themes) {
  const wrap = document.getElementById('themes-wrap');
  if (!wrap) return;
  if (!themes.length) {
    wrap.innerHTML = '<div style="color:var(--text-muted);font-size:13px;">No concept tags applied to sessions with this client yet. Tag messages or notes during a session to build a theme profile.</div>';
    return;
  }

  const maxCount = Math.max(...themes.map(t => (t.message_count || 0) + (t.note_count || 0)), 1);

  wrap.innerHTML = `
    <div style="margin-bottom:16px;font-size:12px;color:var(--text-muted);">
      ${themes.length} concept theme${themes.length !== 1 ? 's' : ''} identified across sessions with this client.
    </div>
    <div style="display:flex;flex-direction:column;gap:10px;">
      ${themes.map(t => {
        const total = (parseInt(t.message_count) || 0) + (parseInt(t.note_count) || 0);
        const pct   = Math.round((total / maxCount) * 100);
        const sessionCount = parseInt(t.session_count) || 0;
        return `
          <div style="background:rgba(255,255,255,.025);border:1px solid var(--border);border-radius:8px;padding:12px 14px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
              <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${escHtml(t.color)};flex-shrink:0;"></span>
              <strong style="font-size:13px;flex:1;">${escHtml(t.name)}</strong>
              <span style="font-size:11px;color:var(--text-muted);">${sessionCount} session${sessionCount !== 1 ? 's' : ''}</span>
            </div>
            <div style="background:rgba(255,255,255,.06);border-radius:4px;height:6px;overflow:hidden;margin-bottom:6px;">
              <div style="height:100%;border-radius:4px;background:${escHtml(t.color)};width:${pct}%;transition:width .4s;"></div>
            </div>
            <div style="font-size:11px;color:var(--text-muted);display:flex;gap:12px;">
              <span>${t.message_count || 0} message tag${(t.message_count || 0) !== 1 ? 's' : ''}</span>
              <span>${t.note_count || 0} note tag${(t.note_count || 0) !== 1 ? 's' : ''}</span>
            </div>
          </div>`;
      }).join('')}
    </div>`;
}

// ── Goals in client detail ──────────────────────────────────────
async function loadClientGoals(endUserId) {
  const wrap = document.getElementById('goals-list-wrap');
  if (!wrap || !endUserId) return;
  try {
    const res  = await fetch(`/api/goals.php?action=list&end_user_id=${encodeURIComponent(endUserId)}`);
    const data = await res.json();
    renderGoalsList(data.goals || []);
  } catch(e) {
    if (wrap) wrap.innerHTML = '<div style="color:var(--text-muted);font-size:13px;">Could not load goals.</div>';
  }
}

function renderGoalsList(goals) {
  const wrap = document.getElementById('goals-list-wrap');
  if (!wrap) return;
  if (!goals.length) {
    wrap.innerHTML = '<div style="color:var(--text-muted);font-size:13px);margin-bottom:12px;">No goals yet. Add one below.</div>';
    return;
  }

  const statusColors = { active:'#4caf87', completed:'#7c6af7', paused:'#f5c842', abandoned:'rgba(200,198,230,.35)' };
  wrap.innerHTML = goals.map(g => {
    const pct       = g.latest_pct != null ? parseInt(g.latest_pct) : null;
    const pctLabel  = pct != null ? `${pct}%` : '—';
    const pctBar    = pct != null ? `<div style="height:4px;border-radius:2px;background:rgba(255,255,255,.08);margin-top:6px;margin-bottom:2px;"><div style="height:100%;border-radius:2px;background:var(--accent);width:${pct}%;"></div></div>` : '';
    const msDone    = parseInt(g.milestones_done || 0);
    const msTotal   = parseInt(g.milestone_count || 0);
    const msLabel   = msTotal ? `${msDone}/${msTotal} milestones` : '';
    const target    = g.target_date ? `Target: ${g.target_date}` : '';
    const statusCol = statusColors[g.status] || 'var(--text-muted)';

    const milestonesHtml = (g.milestones || []).map(m => `
      <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);">
        <button data-toggle-milestone="${m.id}" data-goal-id="${g.id}" style="background:none;border:none;padding:0;cursor:pointer;flex-shrink:0;color:${m.completed_at ? '#4caf87' : 'var(--text-muted)'}">
          ${m.completed_at ? '✓' : '○'}
        </button>
        <span style="font-size:12.5px;color:${m.completed_at ? 'var(--text-muted)' : 'var(--text)'};text-decoration:${m.completed_at ? 'line-through' : 'none'};flex:1;">${escHtml(m.title)}</span>
        <button data-delete-milestone="${m.id}" data-goal-id="${g.id}" style="background:none;border:none;padding:0;cursor:pointer;color:var(--text-muted);font-size:11px;opacity:.6;">✕</button>
      </div>`).join('');

    const updatesHtml = (g.updates || []).map(u => {
      const dt = new Date(u.created_at + ' UTC');
      return `<div style="font-size:12px;color:var(--text-muted);padding:4px 0;border-bottom:1px solid rgba(255,255,255,.04);">
        <span style="color:var(--text-muted);margin-right:8px;">${dt.toLocaleDateString()}</span>
        ${u.progress_pct != null ? `<span style="color:var(--accent);margin-right:8px;">${u.progress_pct}%</span>` : ''}
        ${escHtml(u.note || '')}
      </div>`;
    }).join('');

    return `<div style="border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:10px;" id="goal-card-${g.id}">
      <div style="padding:12px 14px;cursor:pointer;" data-toggle-goal="${g.id}">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">
          <div style="flex:1;">
            <div style="font-size:13.5px;font-weight:600;color:var(--text);">${escHtml(g.title)}</div>
            <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px;">
              <span style="color:${statusCol};font-weight:500;">${g.status}</span>
              ${target ? ` · ${escHtml(target)}` : ''}
              ${msLabel ? ` · ${escHtml(msLabel)}` : ''}
              ${pct != null ? ` · ${pctLabel} progress` : ''}
            </div>
            ${pctBar}
          </div>
          <button data-delete-goal="${g.id}" style="background:none;border:none;cursor:pointer;color:rgba(200,198,230,.3);font-size:12px;padding:2px 6px;flex-shrink:0;" title="Delete goal">✕</button>
        </div>
      </div>
      <div id="goal-expand-${g.id}" style="display:none;border-top:1px solid var(--border);padding:12px 14px;background:rgba(255,255,255,.015);">
        ${g.description ? `<p style="font-size:12.5px;color:var(--text-muted);margin-bottom:12px;line-height:1.5;">${escHtml(g.description)}</p>` : ''}

        <!-- Status change -->
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
          ${['active','completed','paused','abandoned'].map(s =>
            `<button data-update-goal-status="${g.id}" data-status="${s}" class="btn-c ghost sm" style="font-size:11px;padding:3px 8px;${g.status===s?'border-color:var(--accent);color:var(--accent);':''}">${s}</button>`
          ).join('')}
        </div>

        ${msTotal ? `<div style="margin-bottom:10px;"><div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:4px;">MILESTONES</div>${milestonesHtml}</div>` : ''}

        <div style="display:flex;gap:6px;margin-bottom:12px;">
          <input type="text" id="ms-input-${g.id}" placeholder="Add milestone…" style="flex:1;background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:6px 10px;color:var(--text);font-size:12.5px;font-family:inherit;outline:none;">
          <button class="btn-c ghost sm" style="font-size:12px;" data-add-milestone="${g.id}">Add</button>
        </div>

        ${updatesHtml ? `<div style="margin-bottom:10px;"><div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:4px;">PROGRESS LOG</div>${updatesHtml}</div>` : ''}

        <div style="display:flex;gap:6px;flex-wrap:wrap;">
          <input type="text" id="upd-note-${g.id}" placeholder="Progress note…" style="flex:2;min-width:120px;background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:6px 10px;color:var(--text);font-size:12.5px;font-family:inherit;outline:none;">
          <input type="number" id="upd-pct-${g.id}" placeholder="% (optional)" min="0" max="100" style="width:90px;background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:6px 10px;color:var(--text);font-size:12.5px;font-family:inherit;outline:none;">
          <button class="btn-c primary sm" style="font-size:12px;" data-log-progress="${g.id}">Log</button>
        </div>
      </div>
    </div>`;
  }).join('');
}

function toggleGoalExpand(goalId) {
  const el = document.getElementById('goal-expand-' + goalId);
  if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

async function addClientGoal() {
  const title   = document.getElementById('goal-title-input')?.value.trim();
  const desc    = document.getElementById('goal-desc-input')?.value.trim();
  const target  = document.getElementById('goal-target-input')?.value;
  const errEl   = document.getElementById('goal-add-error');
  if (!title) { if(errEl){errEl.textContent='Title required';errEl.style.display='block';} return; }
  if(errEl) errEl.style.display='none';

  const fd = new FormData();
  fd.append('action',     'save_goal');
  fd.append('csrf_token',  DASH_CSRF);
  fd.append('end_user_id', _currentClientId);
  fd.append('title',       title);
  fd.append('description', desc || '');
  fd.append('target_date', target || '');

  try {
    const res  = await fetch('/api/goals.php', { method:'POST', body:fd });
    const data = await res.json();
    if (data.ok) {
      document.getElementById('goal-title-input').value  = '';
      document.getElementById('goal-desc-input').value   = '';
      document.getElementById('goal-target-input').value = '';
      loadClientGoals(_currentClientId);
    } else { if(errEl){errEl.textContent=data.error||'Error';errEl.style.display='block';} }
  } catch(e) { if(errEl){errEl.textContent='Network error';errEl.style.display='block';} }
}

async function deleteGoal(goalId) {
  if (!confirm('Delete this goal and all its milestones and updates?')) return;
  const fd = new FormData();
  fd.append('action','delete_goal'); fd.append('csrf_token',DASH_CSRF); fd.append('id',goalId);
  const res = await fetch('/api/goals.php', { method:'POST', body:fd });
  if (res.ok) loadClientGoals(_currentClientId);
}

async function updateGoalStatus(goalId, status) {
  const fd = new FormData();
  fd.append('action','save_goal'); fd.append('csrf_token',DASH_CSRF); fd.append('id',goalId); fd.append('status',status);
  const res = await fetch('/api/goals.php', { method:'POST', body:fd });
  if (res.ok) loadClientGoals(_currentClientId);
}

async function addMilestone(goalId) {
  const input = document.getElementById('ms-input-' + goalId);
  if (!input || !input.value.trim()) return;
  const fd = new FormData();
  fd.append('action','save_milestone'); fd.append('csrf_token',DASH_CSRF); fd.append('goal_id',goalId); fd.append('title',input.value.trim());
  const res = await fetch('/api/goals.php', { method:'POST', body:fd });
  if (res.ok) { input.value = ''; loadClientGoals(_currentClientId); }
}

async function toggleMilestone(milestoneId, goalId) {
  const fd = new FormData();
  fd.append('action','toggle_milestone'); fd.append('csrf_token',DASH_CSRF); fd.append('milestone_id',milestoneId);
  const res = await fetch('/api/goals.php', { method:'POST', body:fd });
  if (res.ok) loadClientGoals(_currentClientId);
}

async function deleteMilestone(milestoneId, goalId) {
  const fd = new FormData();
  fd.append('action','delete_milestone'); fd.append('csrf_token',DASH_CSRF); fd.append('milestone_id',milestoneId);
  const res = await fetch('/api/goals.php', { method:'POST', body:fd });
  if (res.ok) loadClientGoals(_currentClientId);
}

async function logProgress(goalId) {
  const note   = document.getElementById('upd-note-' + goalId)?.value.trim();
  const pctEl  = document.getElementById('upd-pct-' + goalId);
  const pct    = pctEl?.value !== '' ? pctEl.value : '';
  if (!note && pct === '') return;
  const fd = new FormData();
  fd.append('action','add_update'); fd.append('csrf_token',DASH_CSRF); fd.append('goal_id',goalId);
  if (note) fd.append('note', note);
  if (pct !== '') fd.append('progress_pct', pct);
  const res = await fetch('/api/goals.php', { method:'POST', body:fd });
  if (res.ok) {
    if (document.getElementById('upd-note-' + goalId)) document.getElementById('upd-note-' + goalId).value = '';
    if (pctEl) pctEl.value = '';
    loadClientGoals(_currentClientId);
  }
}

function escHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ───────────── Session Review ───────────── */
let _srActiveFilters = new Set();

async function openSessionReview(sessionId, label) {
  const ov = document.getElementById('srOverlay');
  ov.style.display = 'flex';
  document.getElementById('srTitle').textContent = label || 'Session Log';
  document.getElementById('srMeta').textContent  = '';
  document.getElementById('srChat').innerHTML    = '<div style="padding:40px;text-align:center;color:var(--text-muted);font-size:13px;">Loading…</div>';
  document.getElementById('srPanel').innerHTML   = '';
  _srActiveFilters.clear();
  try {
    const res  = await fetch('/api/session_review.php?session_id=' + encodeURIComponent(sessionId));
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    renderSessionReview(data);
  } catch(e) {
    document.getElementById('srChat').innerHTML = '<div style="padding:40px;color:var(--text-muted);">Unable to load session. ' + escHtml(e.message) + '</div>';
  }
}

function closeSessionReview() {
  document.getElementById('srOverlay').style.display = 'none';
}

function renderSessionReview(data) {
  const { session, messages, notes } = data;

  // ── Header meta ──
  if (session.started_at) {
    const st  = new Date(session.started_at + (session.started_at.includes('T') ? '' : ' UTC'));
    const en  = session.ended_at ? new Date(session.ended_at + (session.ended_at.includes('T') ? '' : ' UTC')) : null;
    const dateStr = st.toLocaleDateString(undefined, { weekday:'short', year:'numeric', month:'long', day:'numeric' });
    const timeStr = st.toLocaleTimeString(undefined, { hour:'2-digit', minute:'2-digit' });
    let dur = '';
    if (en) {
      const mins = Math.round((en - st) / 60000);
      dur = mins >= 60 ? Math.floor(mins/60) + 'h ' + (mins%60) + 'm' : mins + ' min';
    }
    document.getElementById('srMeta').textContent = dateStr + ' · ' + timeStr + (dur ? ' · ' + dur : '');
  }

  // ── Compute counts for side panel chips ──
  let countVoice = 0, countPinned = 0;
  const colorCounts = { yellow:0, pink:0, blue:0, crimson:0 };

  messages.forEach(m => {
    if (m.message_type === 'voice_note') countVoice++;
    if (m.is_pinned) countPinned++;
    (m.highlights || []).forEach(h => { if (colorCounts[h.color] !== undefined) colorCounts[h.color]++; });
  });

  // ── Side panel ──
  const pinned    = messages.filter(m => m.is_pinned);
  const vNotes    = messages.filter(m => m.message_type === 'voice_note');

  const jumpItems = [...pinned.map(m => ({ type:'pin',   msg:m })),
                     ...vNotes.map(m => ({ type:'voice', msg:m }))]
                    .sort((a,b) => a.msg.id - b.msg.id);

  const jumpHtml = jumpItems.map(item => {
    const m    = item.msg;
    const icon = item.type === 'pin' ? '📌' : '🎵';
    const who  = escHtml(m.display_name || (m.is_practitioner ? 'You (Practitioner)' : 'Client'));
    const time = m.sent_at ? srFmtTime(m.sent_at) : '';
    const snip = item.type === 'pin'
      ? escHtml((m.content || '').slice(0, 60))
      : (m.caption ? escHtml(m.caption.slice(0,60)) : 'Voice note');
    return `<button class="sr-jump-item" data-jump-to="${m.id}">
      <span class="sr-jump-icon">${icon}</span>
      <div class="sr-jump-body">
        <div class="sr-jump-who">${who}</div>
        <div class="sr-jump-time">${time}</div>
        <div class="sr-jump-snippet">${snip}</div>
      </div>
    </button>`;
  }).join('');

  const chipDisabled = count => count === 0 ? ' disabled style="opacity:.35;cursor:default;"' : '';

  document.getElementById('srPanel').innerHTML = `
    <div class="sr-panel-scroll">
      <div>
        <div class="sr-section-label">Filter</div>
        <div class="sr-chips" id="srChips">
          <button class="sr-chip" data-filter="voice" data-filter="voice"${chipDisabled(countVoice)}>
            🎵 Voice <span class="sr-chip-count">${countVoice}</span>
          </button>
          <button class="sr-chip" data-filter="pinned" data-filter="pinned"${chipDisabled(countPinned)}>
            📌 Pinned <span class="sr-chip-count">${countPinned}</span>
          </button>
          <button class="sr-chip chip-yellow" data-filter="yellow" data-filter="yellow"${chipDisabled(colorCounts.yellow)}>
            <span class="sr-chip-dot yellow"></span> Yellow <span class="sr-chip-count">${colorCounts.yellow}</span>
          </button>
          <button class="sr-chip chip-pink" data-filter="pink" data-filter="pink"${chipDisabled(colorCounts.pink)}>
            <span class="sr-chip-dot pink"></span> Pink <span class="sr-chip-count">${colorCounts.pink}</span>
          </button>
          <button class="sr-chip chip-blue" data-filter="blue" data-filter="blue"${chipDisabled(colorCounts.blue)}>
            <span class="sr-chip-dot blue"></span> Blue <span class="sr-chip-count">${colorCounts.blue}</span>
          </button>
          <button class="sr-chip chip-crimson" data-filter="crimson" data-filter="crimson"${chipDisabled(colorCounts.crimson)}>
            <span class="sr-chip-dot crimson"></span> Crimson <span class="sr-chip-count">${colorCounts.crimson}</span>
          </button>
        </div>
      </div>
      <div>
        <div class="sr-section-label">Jump To</div>
        <div class="sr-jump-list">
          ${jumpHtml || '<div class="sr-empty-panel">No pinned messages or voice notes in this session.</div>'}
        </div>
      </div>
    </div>`;

  // ── Chat log ──
  let lastDay = '';
  const chatHtml = messages.map(m => {
    const msgDay = m.sent_at ? m.sent_at.slice(0, 10) : '';
    let dayDiv = '';
    if (msgDay && msgDay !== lastDay) {
      lastDay = msgDay;
      const d = new Date(msgDay + 'T00:00:00');
      dayDiv = `<div class="sr-day-divider">${d.toLocaleDateString(undefined, { weekday:'long', month:'long', day:'numeric' })}</div>`;
    }

    const isSelf  = !!m.is_practitioner;
    const name    = m.display_name || (isSelf ? 'You' : 'Client');
    const time    = m.sent_at ? srFmtTime(m.sent_at) : '';
    const avHtml  = srAvatar(m.avatar_path, name);

    // Compute data attributes for filtering
    const hlColors = [...new Set((m.highlights || []).map(h => h.color))].join(',');
    const dataParts = [
      `data-id="${m.id}"`,
      `data-type="${escHtml(m.message_type)}"`,
      `data-pinned="${m.is_pinned ? '1' : '0'}"`,
      `data-hlcolors="${hlColors}"`,
    ].join(' ');

    const pinBadge = m.is_pinned
      ? `<div class="sr-pin-badge"><svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M16 12V4h1V2H7v2h1v8l-2 2v2h5.2v6h1.6v-6H18v-2l-2-2z"/></svg> Pinned</div>`
      : '';

    let bubbleContent = '';
    if (m.message_type === 'voice_note') {
      const caption = m.caption ? `<div class="sr-caption">${escHtml(m.caption)}</div>` : '';
      bubbleContent = `<div class="sr-voice-bubble">
        <div class="sr-voice-header"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm-1-9c0-.55.45-1 1-1s1 .45 1 1v6c0 .55-.45 1-1 1s-1-.45-1-1V5zm6 6c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/></svg> Voice Note</div>
        <audio class="sr-audio" controls src="${escHtml(m.content || '')}" preload="none"></audio>
        ${caption}
      </div>`;
    } else if (m.message_type === 'file') {
      const fname = escHtml(m.original_name || m.caption || 'Attachment');
      const fsize = m.file_size ? ' <span class="sr-file-size">(' + srFmtSize(m.file_size) + ')</span>' : '';
      bubbleContent = `<a class="sr-file-link" href="${escHtml(m.content || '')}" target="_blank" rel="noopener">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        ${fname}${fsize}
      </a>`;
    } else {
      bubbleContent = srHighlightText(m.content || '', m.highlights || []);
    }

    return `${dayDiv}<div class="sr-msg${isSelf ? ' sr-self' : ''}" id="sr-msg-${m.id}" ${dataParts}>
      ${avHtml}
      <div class="sr-bubble-wrap">
        <div class="sr-meta">
          <span class="sr-meta-name">${escHtml(name)}</span>
          <span>${time}</span>
        </div>
        <div class="sr-bubble">
          ${pinBadge}${bubbleContent}
        </div>
      </div>
    </div>`;
  }).join('');

  // Notes section
  const notesHtml = notes && notes.length > 0
    ? notes.map(n => `<div class="sr-notes-content">${escHtml(n.note_content || '')}</div>`).join('')
    : '';
  const notesSec = notesHtml
    ? `<div class="sr-notes-section"><div class="sr-notes-label">Practitioner Notes</div>${notesHtml}</div>`
    : '';

  document.getElementById('srChat').innerHTML = chatHtml + notesSec
    || '<div style="padding:40px;text-align:center;color:var(--text-muted);font-size:13px;">No messages in this session.</div>';
}

function srToggleFilter(key, btn) {
  if (btn.disabled) return;
  if (_srActiveFilters.has(key)) {
    _srActiveFilters.delete(key);
    btn.classList.remove('on');
  } else {
    _srActiveFilters.add(key);
    btn.classList.add('on');
  }
  srApplyFilters();
}

function srApplyFilters() {
  const msgs = document.querySelectorAll('#srChat .sr-msg');
  if (_srActiveFilters.size === 0) {
    msgs.forEach(el => el.classList.remove('sr-hidden'));
    return;
  }
  msgs.forEach(el => {
    let match = false;
    if (_srActiveFilters.has('voice')  && el.dataset.type    === 'voice_note') match = true;
    if (_srActiveFilters.has('pinned') && el.dataset.pinned  === '1')           match = true;
    const hlColors = (el.dataset.hlcolors || '').split(',');
    for (const color of ['yellow','pink','blue','crimson']) {
      if (_srActiveFilters.has(color) && hlColors.includes(color)) { match = true; break; }
    }
    el.classList.toggle('sr-hidden', !match);
  });
}

function srJumpTo(msgId) {
  const el = document.getElementById('sr-msg-' + msgId);
  if (!el) return;
  // If filters are hiding it, clear filters first
  if (el.classList.contains('sr-hidden')) {
    _srActiveFilters.clear();
    document.querySelectorAll('.sr-chip').forEach(c => c.classList.remove('on'));
    srApplyFilters();
  }
  el.scrollIntoView({ behavior:'smooth', block:'center' });
  el.classList.add('sr-flash');
  el.addEventListener('animationend', () => el.classList.remove('sr-flash'), { once:true });
}

function srFmtTime(sent_at) {
  const d = new Date(sent_at + (sent_at.includes('T') ? '' : ' UTC'));
  return d.toLocaleTimeString(undefined, { hour:'2-digit', minute:'2-digit' });
}

function srFmtSize(bytes) {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1048576) return (bytes/1024).toFixed(1) + ' KB';
  return (bytes/1048576).toFixed(1) + ' MB';
}

function srAvatar(avatarPath, name) {
  if (avatarPath && !avatarPath.startsWith('preset:')) {
    return `<img class="sr-av" src="${escHtml(avatarPath)}" alt="">`;
  }
  const init = (name || '?').charAt(0).toUpperCase();
  return `<div class="sr-av-ph">${escHtml(init)}</div>`;
}

function srHighlightText(text, highlights) {
  if (!highlights || highlights.length === 0) return escHtml(text);
  const sorted = [...highlights].sort((a, b) => a.start_offset - b.start_offset);
  let result = '', pos = 0;
  for (const hl of sorted) {
    const s = Math.max(hl.start_offset, pos);
    const e = Math.min(hl.end_offset, text.length);
    if (s > pos) result += escHtml(text.slice(pos, s));
    if (s < e) {
      const title = hl.annotation ? ` title="${escHtml(hl.annotation)}"` : '';
      result += `<mark class="hl-${escHtml(hl.color)}"${title}>${escHtml(text.slice(s, e))}</mark>`;
      pos = e;
    }
  }
  if (pos < text.length) result += escHtml(text.slice(pos));
  return result;
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && document.getElementById('srOverlay').style.display !== 'none') closeSessionReview();
});

// ── Messages / Inbox (practitioner dashboard) ─────────────────
let dashIbLoaded        = false;
let dashIbActiveClientId = null;
let dashIbPollTimer     = null;
let dashMediaRecorder   = null;
let dashIbChunks        = [];

function dashEsc(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function dashPollUnread() {
  fetch('/api/inbox.php?action=unread_count').then(r => r.json()).then(d => {
    const badge = document.getElementById('dashInboxBadge');
    if (badge) { badge.textContent = d.unread || ''; badge.style.display = d.unread > 0 ? 'inline-flex' : 'none'; }
  }).catch(() => {});
  setTimeout(dashPollUnread, 30000);
}
dashPollUnread();

async function dashLoadConversations() {
  dashIbLoaded = true;
  const el = document.getElementById('dashIbList');
  el.innerHTML = '<div class="empty-c"><div class="spinner"></div></div>';
  try {
    const res  = await fetch('/api/inbox.php?action=conversations');
    const list = await res.json();
    if (!list.length) {
      el.innerHTML = '<div class="empty-c"><p style="color:var(--text-muted);font-size:13px;">No conversations yet.</p></div>';
      return;
    }
    el.innerHTML = list.map(c => {
      const lastText = c.last_type === 'voice_note' ? '🎙 Voice note' : c.last_type === 'image' ? '🖼 Image' : c.last_type === 'media_rec' ? '📖 Media recommendation' : dashEsc(c.last_body || '');
      const av = c.avatar_path
        ? `<img src="${dashEsc(c.avatar_path)}" class="ib-av" alt="">`
        : `<div class="ib-av-ph">${dashEsc((c.display_name||'?')[0].toUpperCase())}</div>`;
      const badge = c.unread > 0 ? `<span class="ib-badge">${c.unread}</span>` : '';
      return `<div class="ib-conv-item${dashIbActiveClientId===c.end_user_id?' active':''}" data-client-id="${c.end_user_id}" data-client-name="${dashEsc(c.display_name)}">
        ${av}<div class="ib-conv-info"><div class="ib-conv-name">${dashEsc(c.display_name)}${badge}</div>
        <div class="ib-conv-preview">${lastText}</div></div></div>`;
    }).join('');
  } catch(e) { el.innerHTML = '<div class="empty-c"><p>Unable to load conversations.</p></div>'; }
}

async function dashOpenThread(clientId, clientName) {
  dashIbActiveClientId = clientId;
  document.querySelectorAll('#dashIbList .ib-conv-item').forEach(el => el.classList.remove('active'));
  const item = document.querySelector(`#dashIbList .ib-conv-item[data-client-id="${clientId}"]`);
  if (item) item.classList.add('active');

  const wrap = document.getElementById('dashIbThread');
  wrap.innerHTML = `<div class="ib-thread-hdr"><div class="ib-thread-name">${dashEsc(clientName)}</div></div>
    <div class="ib-msgs" id="dashIbMsgs"><div class="empty-c"><div class="spinner"></div></div></div>
    <div class="ib-compose" id="dashIbCompose">
      <button id="dashIbAttachBtn" class="ib-attach-btn" title="Send image">
        <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" width="16" height="16"><rect x="1" y="2" width="16" height="13" rx="2"/><circle cx="6" cy="7" r="1.5"/><polyline points="1,13 6,8 10,12 13,9 17,13"/></svg>
      </button>
      <input type="file" id="dashIbImgFile" accept="image/*" style="display:none">
      <button class="ib-voice-btn" id="dashVoiceBtn" title="Record voice note">
        <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" width="16" height="16"><rect x="6" y="1" width="6" height="10" rx="3"/><path d="M3 9a6 6 0 0012 0"/><line x1="9" y1="17" x2="9" y2="15"/></svg>
      </button>
      <button class="ib-attach-btn" id="dashMrBtn" title="Recommend media" style="display:none">
        <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" width="16" height="16"><path d="M3 2h8l4 4v10a1 1 0 01-1 1H3a1 1 0 01-1-1V3a1 1 0 011-1z"/><polyline points="11,2 11,6 15,6"/><line x1="6" y1="9" x2="12" y2="9"/><line x1="6" y1="12" x2="10" y2="12"/></svg>
      </button>
      <input type="text" class="ib-input" id="dashIbInput" placeholder="Type a message…">
      <button id="dashIbSendBtn" class="btn-c primary sm">Send</button>
    </div>`;

  // Attach compose area listeners
  document.getElementById('dashIbAttachBtn')?.addEventListener('click', () => document.getElementById('dashIbImgFile').click());
  document.getElementById('dashIbImgFile')?.addEventListener('change', function() { dashSendImage(this); });
  document.getElementById('dashVoiceBtn')?.addEventListener('click', dashToggleVoice);
  document.getElementById('dashMrBtn')?.addEventListener('click', dashOpenMediaModal);
  document.getElementById('dashIbInput')?.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); dashSendText(); } });
  document.getElementById('dashIbSendBtn')?.addEventListener('click', dashSendText);

  // Show media rec button if config already loaded
  if (dashMrConfig && (dashMrConfig.open_library || dashMrConfig.google_books_enabled || dashMrConfig.tmdb_enabled)) {
    const mrBtn = document.getElementById('dashMrBtn');
    if (mrBtn) mrBtn.style.display = '';
  }

  await dashFetchMessages(clientId);
  fetch('/api/inbox.php', { method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ action:'mark_read', with_id: clientId }) });
  dashPollUnread();
  if (dashIbPollTimer) clearInterval(dashIbPollTimer);
  dashIbPollTimer = setInterval(() => dashFetchMessages(clientId, true), 10000);
}

async function dashFetchMessages(clientId, silent = false) {
  try {
    const res  = await fetch(`/api/inbox.php?action=messages&with_id=${clientId}`);
    const data = await res.json();
    if (!silent || document.getElementById('dashIbMsgs')) dashRenderMessages(data);
  } catch(e) { /* silent — background inbox poll; stale messages remain visible on transient errors */ }
}

function dashRenderMessages(data) {
  const el = document.getElementById('dashIbMsgs');
  if (!el) return;
  const msgs = data.messages || [];
  if (!msgs.length) {
    el.innerHTML = '<div class="empty-c" style="flex:1;"><p style="color:var(--text-muted);font-size:13px;">No messages yet.</p></div>';
    return;
  }
  const wasAtBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
  el.innerHTML = msgs.map(m => {
    const mine = m.sender_type === 'practitioner';
    const ts   = new Date(m.created_at + ' UTC').toLocaleTimeString(undefined, {hour:'2-digit',minute:'2-digit'});
    let content;
    if (m.message_type === 'image' && m.file_path) {
      content = `<img src="${dashEsc(m.file_path)}" class="ib-img-thumb" data-lightbox-src="${dashEsc(m.file_path)}" alt="Image">`;
    } else if (m.message_type === 'voice_note' && m.file_path) {
      content = `<audio controls src="${dashEsc(m.file_path)}" class="ib-audio"></audio>`;
    } else if (m.message_type === 'media_rec') {
      content = dashBuildMediaRecWidget(m.body || '');
    } else {
      content = dashEsc(m.body || '');
    }
    return `<div class="ib-msg-row ${mine?'mine':'theirs'}">
      <div class="ib-bubble"><div class="ib-bubble-content">${content}</div><div class="ib-ts">${ts}</div></div>
    </div>`;
  }).join('');
  if (wasAtBottom) el.scrollTop = el.scrollHeight;
}

async function dashSendText() {
  const input = document.getElementById('dashIbInput');
  const body  = input.value.trim();
  if (!body || !dashIbActiveClientId) return;
  input.value = '';
  try {
    await fetch('/api/inbox.php', { method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ action:'send', with_id: dashIbActiveClientId, body, message_type:'text' }) });
    dashFetchMessages(dashIbActiveClientId);
  } catch(e) { if (window.SS_DEBUG) console.error('dashSendText failed', e); }
}

async function dashSendImage(input) {
  if (!input.files[0] || !dashIbActiveClientId) return;
  const fd = new FormData();
  fd.append('action', 'send'); fd.append('with_id', dashIbActiveClientId);
  fd.append('message_type', 'image'); fd.append('file', input.files[0]);
  input.value = '';
  try {
    const res = await fetch('/api/inbox.php', { method:'POST', body: fd });
    if (res.ok) dashFetchMessages(dashIbActiveClientId);
  } catch(e) { if (window.SS_DEBUG) console.error('dashSendImage failed', e); }
}

async function dashToggleVoice() {
  const btn = document.getElementById('dashVoiceBtn');
  if (dashMediaRecorder && dashMediaRecorder.state === 'recording') {
    dashMediaRecorder.stop(); return;
  }
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    dashIbChunks = [];
    dashMediaRecorder = new MediaRecorder(stream);
    dashMediaRecorder.ondataavailable = e => dashIbChunks.push(e.data);
    dashMediaRecorder.onstop = async () => {
      stream.getTracks().forEach(t => t.stop());
      btn.classList.remove('recording');
      const blob = new Blob(dashIbChunks, { type: 'audio/webm' });
      const fd   = new FormData();
      fd.append('action', 'send'); fd.append('with_id', dashIbActiveClientId);
      fd.append('message_type', 'voice_note'); fd.append('file', blob, 'voice.webm');
      try {
        const res = await fetch('/api/inbox.php', { method:'POST', body: fd });
        if (res.ok) dashFetchMessages(dashIbActiveClientId);
      } catch(e) {}
    };
    dashMediaRecorder.start();
    btn.classList.add('recording');
  } catch(e) {}
}

function dashOpenLightbox(src) {
  let lb = document.getElementById('dashLightbox');
  if (!lb) {
    lb = document.createElement('div');
    lb.id = 'dashLightbox'; lb.className = 'ib-lightbox';
    lb.innerHTML = '<img id="dashLightboxImg"><button id="dashLightboxClose" class="ib-lb-close">✕</button>';
    lb.addEventListener('click', e => { if (e.target === lb) lb.style.display = 'none'; });
    document.body.appendChild(lb);
    document.getElementById('dashLightboxClose').addEventListener('click', () => lb.style.display = 'none');
    document.addEventListener('keydown', e => { if (e.key === 'Escape') lb.style.display = 'none'; });
  }
  document.getElementById('dashLightboxImg').src = src;
  lb.style.display = 'flex';
}

// ── Finance section ──────────────────────────────────────────────
let financeLoaded = false;

async function loadFinancialData() {
  financeLoaded = true;
  const status  = document.getElementById('fin-filter-status')?.value  || '';
  const gateway = document.getElementById('fin-filter-gateway')?.value || '';

  // Summary cards
  try {
    const res  = await fetch(`/api/payments.php?action=financial_summary&csrf=<?= htmlspecialchars($_SESSION['csrf_token']) ?>`);
    const data = await res.json();
    if (data.ok) {
      const fmt = (n, cur) => new Intl.NumberFormat('en-GB', { style: 'currency', currency: cur || 'GBP' }).format(n || 0);
      const cur = data.currency || 'GBP';
      document.getElementById('fin-month-total').textContent  = fmt(data.this_month, cur);
      document.getElementById('fin-all-total').textContent    = fmt(data.all_time, cur);
      document.getElementById('fin-pending-total').textContent = fmt(data.pending, cur);
      document.getElementById('fin-free-count').textContent   = data.free_count ?? 0;

      // Monthly chart
      renderFinChart(data.monthly || []);
    }
  } catch(e) { console.error('Finance summary error', e); }

  // Payment records table
  try {
    const params = new URLSearchParams({ action: 'payment_records', csrf: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>' });
    if (status)  params.set('status', status);
    if (gateway) params.set('gateway', gateway);
    const res  = await fetch('/api/payments.php?' + params);
    const data = await res.json();
    const tbody = document.getElementById('fin-table-body');
    if (!tbody) return;
    if (!data.ok || !data.records.length) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px">No payment records found.</td></tr>';
      return;
    }
    const csrf = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
    tbody.innerHTML = data.records.map(r => {
      const isFree = (parseFloat(r.amount_due) === 0 || r.gateway === 'free');
      const displayStatus = isFree ? 'paid' : r.status;
      const statusColor = { paid:'#4caf87', pending:'#f5c842', waived:'#7c6af7', failed:'#e05c5c', free:'#4caf87', manual:'#7c6af7' }[displayStatus] || '#888';
      const statusLabel = { paid:'Paid', pending:'Pending', waived:'Waived', failed:'Failed', free:'Free', manual:'Manual' }[displayStatus] || displayStatus;
      const gwLabel = { paypal:'PayPal', stripe:'Stripe', bitcoin:'Bitcoin', square:'Square', free:'Free', manual:'Manual', waived:'Waived' }[r.gateway] || (r.gateway || '—');
      const fmt = n => n != null ? new Intl.NumberFormat('en-GB', { style: 'currency', currency: r.currency || 'GBP' }).format(n) : '—';
      const invoiceUrl = `/api/invoice.php?payment_id=${r.id}&csrf=${encodeURIComponent(csrf)}`;
      const markPaidBtn = (displayStatus === 'pending')
        ? `<button data-mark-paid="${r.id}" style="background:transparent;border:1px solid rgba(62,207,142,.4);border-radius:5px;padding:3px 9px;font-size:11px;color:#3ecf8e;cursor:pointer;">Mark Paid</button>`
        : '';
      return `<tr style="border-bottom:1px solid var(--border);">
        <td style="padding:10px 20px;color:var(--text-muted);font-size:12px;white-space:nowrap;">${r.date ? r.date.slice(0,10) : '—'}</td>
        <td style="padding:10px 12px;">${escHtml(r.client_name || '—')}</td>
        <td style="padding:10px 12px;text-align:right;">${fmt(r.amount_due)}</td>
        <td style="padding:10px 12px;font-size:12px;color:var(--text-muted);">${escHtml(gwLabel)}</td>
        <td style="padding:10px 12px;"><span style="font-size:11px;padding:2px 8px;border-radius:999px;background:${statusColor}22;color:${statusColor};border:1px solid ${statusColor}44;">${escHtml(statusLabel)}</span></td>
        <td style="padding:10px 12px;font-size:12px;color:var(--text-muted);">${escHtml(r.session_name || '—')}</td>
        <td style="padding:10px 20px;white-space:nowrap;">
          <div style="display:flex;gap:6px;align-items:center;">
            <a href="${invoiceUrl}" target="_blank" style="background:transparent;border:1px solid var(--border);border-radius:5px;padding:3px 9px;font-size:11px;color:var(--text-muted);text-decoration:none;cursor:pointer;">View Invoice</a>
            ${markPaidBtn}
          </div>
        </td>
      </tr>`;
    }).join('');
  } catch(e) { console.error('Finance records error', e); }
}

function renderFinChart(monthly) {
  const container = document.getElementById('fin-monthly-chart');
  if (!container) return;
  if (!monthly.length) {
    container.innerHTML = '<p style="color:var(--text-muted);font-size:13px;text-align:center;padding:24px 0">No monthly data yet.</p>';
    return;
  }
  const max = Math.max(...monthly.map(m => parseFloat(m.total) || 0), 1);
  container.innerHTML = `<div style="display:flex;align-items:flex-end;gap:8px;height:120px;padding:0 4px">` +
    monthly.map(m => {
      const pct = Math.max(((parseFloat(m.total) || 0) / max) * 100, 2).toFixed(1);
      const label = m.month ? m.month.slice(0, 7) : '';
      return `<div style="display:flex;flex-direction:column;align-items:center;gap:4px;flex:1;min-width:0">
        <div title="${label}: ${m.total}" style="width:100%;background:var(--accent);border-radius:4px 4px 0 0;height:${pct}%;min-height:4px;transition:height .3s"></div>
        <span style="font-size:10px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%">${label.slice(5)}</span>
      </div>`;
    }).join('') +
  `</div>`;
}

async function exportFinancialCSV() {
  const btn = document.getElementById('fin-export-btn');
  if (btn) { btn.disabled = true; btn.textContent = 'Exporting…'; }
  try {
    const res  = await fetch(`/api/payments.php?action=export_csv&csrf=<?= htmlspecialchars($_SESSION['csrf_token']) ?>`);
    if (!res.ok) throw new Error('Export failed');
    const blob = await res.blob();
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `serenityspaces-payments-${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  } catch(e) {
    alert('CSV export failed. Please try again.');
    console.error(e);
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = '↓ Export CSV'; }
  }
}

function escHtml(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Practitioner first-visit guided tour ─────────────────────────
(function initDashTour() {
  const TOUR_KEY = 'ss_tour_v1_dash';
  if (localStorage.getItem(TOUR_KEY)) return;
  // If arriving from wizard (?tour=1) start immediately, otherwise small delay
  const fromWizard = new URLSearchParams(location.search).get('tour') === '1';

  const steps = [
    {
      target: '#nav-overview',
      fallback: '#cms-sidebar',
      title: 'Overview',
      body: 'Your dashboard home — recent sessions, upcoming bookings, and quick stats at a glance.',
    },
    {
      target: '#nav-rooms',
      fallback: '#cms-sidebar',
      title: 'Rooms',
      body: 'Create and manage your therapy rooms. Each room has its own join link you can send to clients.',
    },
    {
      target: '#new-room-btn',
      fallback: '#sec-rooms',
      title: 'Create a Room',
      body: 'Start here to create your first room. Give it a name and a custom background, then share the invite link.',
    },
    {
      target: '#nav-schedule',
      fallback: '#cms-sidebar',
      title: 'Schedule',
      body: 'Booking requests from clients appear here. Confirm them to schedule a session and notify the client.',
    },
    {
      target: '#nav-clients',
      fallback: '#cms-sidebar',
      title: 'Clients',
      body: 'View clients who have been through your rooms, their session history, and contact details.',
    },
    {
      target: '#nav-inbox',
      fallback: '#cms-sidebar',
      title: 'Messages',
      body: 'Secure direct messages with clients — separate from the session room chat.',
    },
    {
      target: '.sidebar-footer',
      fallback: '#cms-sidebar',
      title: 'Profile & Admin',
      body: 'Edit your profile, access the Admin Panel to configure the platform, and view your GDPR records here.',
    },
  ];

  let currentStep = 0;

  const overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;z-index:9000;pointer-events:none;';

  const bubble = document.createElement('div');
  bubble.style.cssText = [
    'position:fixed;z-index:9001;max-width:300px;min-width:220px;',
    'background:rgba(13,10,32,0.97);border:1px solid rgba(124,106,247,0.5);',
    'border-radius:10px;padding:16px 18px;box-shadow:0 8px 40px rgba(0,0,0,0.6);',
    'font-family:inherit;transition:top 0.25s ease,left 0.25s ease;pointer-events:auto;',
  ].join('');

  const titleEl = document.createElement('div');
  titleEl.style.cssText = 'font-size:14px;font-weight:700;color:var(--text);margin-bottom:6px;';

  const bodyEl = document.createElement('div');
  bodyEl.style.cssText = 'font-size:12px;color:var(--text-muted);line-height:1.6;margin-bottom:14px;';

  const footer = document.createElement('div');
  footer.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:8px;';

  const skipBtn = document.createElement('button');
  skipBtn.textContent = 'Skip tour';
  skipBtn.style.cssText = 'background:transparent;border:none;color:var(--text-muted);font-size:11px;cursor:pointer;padding:0;font-family:inherit;';
  skipBtn.addEventListener('click', endTour);

  const nextBtn = document.createElement('button');
  nextBtn.style.cssText = 'background:var(--accent);color:#fff;border:none;border-radius:5px;padding:6px 14px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;';
  nextBtn.addEventListener('click', advanceTour);

  const progress = document.createElement('div');
  progress.style.cssText = 'font-size:11px;color:var(--text-muted);';

  footer.append(skipBtn, progress, nextBtn);
  bubble.append(titleEl, bodyEl, footer);
  overlay.appendChild(bubble);
  document.body.appendChild(overlay);

  const spotlight = document.createElement('div');
  spotlight.style.cssText = [
    'position:fixed;z-index:8999;border-radius:6px;',
    'box-shadow:0 0 0 9999px rgba(0,0,0,0.72);',
    'transition:all 0.25s;pointer-events:none;',
    'border:2px solid rgba(124,106,247,0.7);',
  ].join('');
  document.body.appendChild(spotlight);

  function findTarget(step) {
    for (const sel of step.target.split(',').map(s => s.trim())) {
      const el = document.querySelector(sel);
      if (el) return el;
    }
    return document.querySelector(step.fallback) || document.body;
  }

  function positionBubble(targetEl) {
    const rect = targetEl.getBoundingClientRect();
    const bw = 300, bh = 160, pad = 12;
    let left, top;
    if (rect.right + bw + pad < window.innerWidth) {
      left = rect.right + pad;
      top  = Math.max(pad, Math.min(rect.top, window.innerHeight - bh - pad));
    } else if (rect.left - bw - pad > 0) {
      left = rect.left - bw - pad;
      top  = Math.max(pad, Math.min(rect.top, window.innerHeight - bh - pad));
    } else {
      left = Math.max(pad, Math.min(rect.left, window.innerWidth - bw - pad));
      top  = rect.bottom + pad;
    }
    bubble.style.left = left + 'px';
    bubble.style.top  = top  + 'px';
    spotlight.style.left   = (rect.left - 4) + 'px';
    spotlight.style.top    = (rect.top  - 4) + 'px';
    spotlight.style.width  = (rect.width  + 8) + 'px';
    spotlight.style.height = (rect.height + 8) + 'px';
  }

  function showStep(index) {
    const step   = steps[index];
    const target = findTarget(step);
    titleEl.textContent    = step.title;
    bodyEl.textContent     = step.body;
    progress.textContent   = (index + 1) + ' / ' + steps.length;
    nextBtn.textContent    = index === steps.length - 1 ? 'Done' : 'Next →';
    positionBubble(target);
    target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function advanceTour() {
    currentStep++;
    if (currentStep >= steps.length) { endTour(); return; }
    showStep(currentStep);
  }

  function endTour() {
    overlay.remove();
    spotlight.remove();
    localStorage.setItem(TOUR_KEY, '1');
    // Clean tour param from URL without reload
    const url = new URL(location.href);
    url.searchParams.delete('tour');
    history.replaceState(null, '', url);
  }

  setTimeout(() => showStep(0), fromWizard ? 600 : 1600);
})();


// ══════════════════════════════════════════════════════════════
// MEDIA RECOMMENDATIONS (Dashboard / Inbox)
// ══════════════════════════════════════════════════════════════

let dashMrConfig     = null;
let dashMrCfgLoaded  = false;
let _dashMrmType     = 'books';
let _dashMrmResults  = [];
let _dashMrmSelected = null;

async function dashLoadMediaConfig() {
  if (dashMrCfgLoaded) return;
  dashMrCfgLoaded = true;
  try {
    const res  = await fetch('/api/media_config.php?action=load');
    const data = await res.json();
    dashMrConfig = data;
    const hasSource = data.open_library || data.google_books_enabled || data.tmdb_enabled;
    const btn = document.getElementById('dashMrBtn');
    if (btn && hasSource) btn.style.display = '';
  } catch(e) { /* silent — media recommendation is a non-critical enhancement; button stays hidden if config load fails */ }
}

function dashBuildMediaRecWidget(bodyStr) {
  let data;
  try { data = JSON.parse(bodyStr); } catch(e) { data = null; }
  if (!data) return '<span style="color:rgba(221,220,242,0.4);font-size:12px;">[Media recommendation]</span>';

  const typeLabel = data.type === 'book' ? '📚 Book Recommendation'
                  : data.type === 'movie' ? '🎬 Film Recommendation'
                  : data.type === 'tv'    ? '📺 Series Recommendation'
                  : '📖 Media Recommendation';
  const coverPh = data.type === 'book' ? '📚' : data.type === 'movie' ? '🎬' : '📺';

  const metaParts = [];
  if (data.year)   metaParts.push(dashEsc(data.year));
  if (data.genre)  metaParts.push(dashEsc(data.genre));
  if (data.rating) metaParts.push('★ ' + dashEsc(data.rating));
  const metaHtml = metaParts.map((p, i) =>
    (i > 0 ? '<span class="mrw-meta-sep">·</span>' : '') + `<span>${p}</span>`
  ).join('');

  const descHtml   = data.description ? `<div class="mrw-desc">${dashEsc(data.description)}</div>` : '';
  const noteHtml   = data.note ? `<div class="mrw-note">"${dashEsc(data.note)}"</div>` : '';
  const authorHtml = data.author ? `<div class="mrw-author">${dashEsc(data.author)}</div>` : '';

  let amazonHtml = '';
  if (dashMrConfig && dashMrConfig.amazon_enabled && dashMrConfig.amazon_tag && data.type === 'book') {
    const q   = encodeURIComponent((data.title || '') + ' ' + (data.author || ''));
    const url = `https://www.amazon.com/s?k=${q}&tag=${encodeURIComponent(dashMrConfig.amazon_tag)}`;
    amazonHtml = `<a class="mrw-amazon" href="${url}" target="_blank" rel="noopener sponsored">Buy on Amazon ↗</a>`;
  }

  return `<div class="media-rec-widget">
    <div class="mrw-inner">
      <div class="mrw-cover">
        <span class="mrw-cover-ph">${coverPh}</span>
        ${data.cover_url ? `<img src="${dashEsc(data.cover_url)}" alt="" data-hide-on-error>` : ''}
      </div>
      <div class="mrw-content">
        <div class="mrw-badge">${typeLabel}</div>
        <div class="mrw-title">${dashEsc(data.title || 'Unknown')}</div>
        ${authorHtml}
        ${metaParts.length ? `<div class="mrw-meta">${metaHtml}</div>` : ''}
        ${descHtml}
        ${noteHtml}
        ${amazonHtml}
      </div>
    </div>
  </div>`;
}

function dashOpenMediaModal() {
  if (!dashMrConfig) { dashLoadMediaConfig().then(() => { if (dashMrConfig && (dashMrConfig.open_library || dashMrConfig.google_books_enabled || dashMrConfig.tmdb_enabled)) dashOpenMediaModal(); }); return; }
  if (!dashMrConfig.open_library && !dashMrConfig.google_books_enabled && !dashMrConfig.tmdb_enabled) {
    alert('No media sources configured. Go to Profile → Media Recommendations to configure.'); return;
  }
  const modal = document.getElementById('dash-media-rec-modal');
  if (!modal) return;
  modal.style.display = '';
  _dashMrmResults  = [];
  _dashMrmSelected = null;
  document.getElementById('dash-mrm-query').value = '';
  document.getElementById('dash-mrm-note').value  = '';
  dashMrmShowResults();
  dashMrmUpdateTabs();
  setTimeout(() => document.getElementById('dash-mrm-query').focus(), 60);
}

function dashCloseMediaModal() {
  const modal = document.getElementById('dash-media-rec-modal');
  if (modal) modal.style.display = 'none';
}

function dashMrmSetType(type, btn) {
  _dashMrmType = type;
  document.querySelectorAll('#dash-mrm-tabs .mrm-tab').forEach(b => {
    b.style.borderBottomColor = 'transparent';
    b.style.color = 'rgba(221,220,242,0.45)';
  });
  btn.style.borderBottomColor = '#7c6af7';
  btn.style.color = '#7c6af7';
  _dashMrmResults  = [];
  _dashMrmSelected = null;
  document.getElementById('dash-mrm-query').value = '';
  dashMrmShowResults();
}

function dashMrmUpdateTabs() {
  const hasVideo = dashMrConfig && (dashMrConfig.tmdb_enabled);
  const videoTab = document.getElementById('dash-mrm-tab-video');
  if (videoTab) {
    videoTab.style.opacity       = hasVideo ? '1' : '0.35';
    videoTab.style.pointerEvents = hasVideo ? '' : 'none';
    videoTab.title               = hasVideo ? '' : 'Configure TMDB API key in Profile → Media Recommendations';
  }
}

function dashMrmShowResults() {
  const body   = document.getElementById('dash-mrm-body');
  const footer = document.getElementById('dash-mrm-detail-footer');
  if (!body) return;
  if (footer) footer.style.display = 'none';
  if (_dashMrmSelected !== null) return; // detail view handled by dashMrmSelectResult
  if (!_dashMrmResults.length) {
    body.innerHTML = '<div style="padding:40px 20px;text-align:center;color:rgba(221,220,242,0.3);font-size:13px;">Search for a book, film, or TV series to recommend.</div>';
    return;
  }
  body.innerHTML = _dashMrmResults.map((r, i) => {
    const coverHtml = r.cover_url
      ? `<img src="${dashEsc(r.cover_url)}" alt="" data-hide-on-error>`
      : `<span style="font-size:20px;">${r.type==='book'?'📚':r.type==='movie'?'🎬':'📺'}</span>`;
    const chips = [r.year, r.genre].filter(Boolean).map(c => `<span>${dashEsc(c)}</span>`).join('');
    return `<div class="mrm-result-row" data-mrm-result="${i}">
      <div class="mrm-result-cover">${coverHtml}</div>
      <div class="mrm-result-info">
        <div class="mrm-result-title">${dashEsc(r.title)}</div>
        ${r.author ? `<div class="mrm-result-author">${dashEsc(r.author)}</div>` : ''}
        ${chips ? `<div class="mrm-result-chips">${chips}</div>` : ''}
      </div>
    </div>`;
  }).join('');
}

async function dashMrmSearch() {
  if (!dashIbActiveClientId) return;
  const query = document.getElementById('dash-mrm-query').value.trim();
  if (!query) return;
  const body    = document.getElementById('dash-mrm-body');
  const action  = _dashMrmType === 'books' ? 'search_books' : 'search_movies';
  body.innerHTML = '<div style="padding:40px 20px;text-align:center;color:rgba(221,220,242,0.3)"><div class="spinner"></div></div>';
  _dashMrmSelected = null;
  document.getElementById('dash-mrm-detail-footer').style.display = 'none';
  try {
    const res  = await fetch('/api/media_search.php', { method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ action, query, media_type: _dashMrmType === 'books' ? 'book' : 'movie' }) });
    const data = await res.json();
    _dashMrmResults = Array.isArray(data.results) ? data.results : [];
    dashMrmShowResults();
  } catch(e) {
    body.innerHTML = '<div style="padding:40px 20px;text-align:center;color:rgba(224,82,82,0.7);font-size:13px;">Search failed. Check your media API configuration.</div>';
  }
}

async function dashMrmSelectResult(idx) {
  _dashMrmSelected = idx;
  const item   = _dashMrmResults[idx];
  const body   = document.getElementById('dash-mrm-body');
  const footer = document.getElementById('dash-mrm-detail-footer');
  body.innerHTML = '<div style="padding:40px 20px;text-align:center;color:rgba(221,220,242,0.3)"><div class="spinner"></div></div>';
  footer.style.display = 'none';

  let detail = item;
  try {
    const res  = await fetch('/api/media_search.php', { method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ action:'detail', item_id: item.id, source: item.source, tmdb_type: item.tmdb_type || null }) });
    const d = await res.json();
    if (d && d.title) detail = { ...item, ...d };
  } catch(e) { /* silent — item renders with basic metadata if detail fetch fails */ }

  _dashMrmResults[idx] = detail;

  const coverHtml = detail.cover_large || detail.cover_url
    ? `<img src="${dashEsc(detail.cover_large || detail.cover_url)}" alt="" data-hide-on-error style="width:100%;display:block;">`
    : `<span style="font-size:32px;">${detail.type==='book'?'📚':detail.type==='movie'?'🎬':'📺'}</span>`;

  const chips = [detail.year, detail.genre, detail.rating ? '★ ' + detail.rating : null]
    .filter(Boolean).map(c => `<span class="mrm-detail-chip">${dashEsc(c)}</span>`).join('');

  body.innerHTML = `<div class="mrm-detail-inner">
    <div class="mrm-detail-top">
      <div class="mrm-detail-cover">${coverHtml}</div>
      <div class="mrm-detail-info">
        <div class="mrm-detail-title">${dashEsc(detail.title || '')}</div>
        ${detail.author ? `<div class="mrm-detail-author">${dashEsc(detail.author)}</div>` : ''}
        ${chips ? `<div class="mrm-detail-chips">${chips}</div>` : ''}
      </div>
    </div>
    ${detail.description ? `<div class="mrm-detail-desc">${dashEsc(detail.description)}</div>` : ''}
  </div>`;

  footer.style.display = '';
  document.getElementById('dash-mrm-note').value = '';
}

async function dashMrmShare() {
  if (_dashMrmSelected === null || !dashIbActiveClientId) return;
  const detail = _dashMrmResults[_dashMrmSelected];
  const note   = (document.getElementById('dash-mrm-note').value || '').trim();
  const recData = {
    type:        detail.type,
    title:       detail.title,
    author:      detail.author   || null,
    year:        detail.year     || null,
    genre:       detail.genre    || null,
    rating:      detail.rating   || null,
    cover_url:   detail.cover_url || null,
    description: detail.description || null,
    note:        note || null,
  };
  try {
    const res = await fetch('/api/inbox.php', { method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ action:'send', with_id: dashIbActiveClientId, body: JSON.stringify(recData), message_type:'media_rec' }) });
    const data = await res.json();
    if (data.error) { alert(data.error); return; }
    dashCloseMediaModal();
    dashFetchMessages(dashIbActiveClientId);
  } catch(e) { alert('Failed to send recommendation.'); }
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    const modal = document.getElementById('dash-media-rec-modal');
    if (modal && modal.style.display !== 'none') dashCloseMediaModal();
  }
});

// ── onerror delegation (capture phase) ────────────────────────
document.addEventListener('error', e => {
  if (e.target.dataset && e.target.dataset.hideOnError !== undefined) e.target.style.display = 'none';
}, true);

// ── Static listener block ──────────────────────────────────────

// Nav: data-dash-section delegation
document.querySelector('.sidebar-nav').addEventListener('click', e => {
  const btn = e.target.closest('[data-dash-section]');
  if (btn) showSection(btn.dataset.dashSection, btn);
});

// Hamburger
document.getElementById('hamburger-btn')?.addEventListener('click', toggleMobileSidebar);

// MFA notice dismiss
document.getElementById('mfa-notice-dismiss-btn')?.addEventListener('click', () => {
  document.getElementById('mfa-notice').style.display = 'none';
});

// Overview buttons
document.getElementById('overview-calendar-btn')?.addEventListener('click', openCalendar);
document.getElementById('overview-new-room-btn')?.addEventListener('click', () => {
  showSection('rooms', document.getElementById('nav-rooms')); toggleCreatePanel(true);
});
document.getElementById('overview-view-rooms-btn')?.addEventListener('click', () => {
  showSection('rooms', document.getElementById('nav-rooms'));
});
document.getElementById('overview-create-first-room-btn')?.addEventListener('click', () => {
  showSection('rooms', document.getElementById('nav-rooms')); toggleCreatePanel(true);
});
document.getElementById('schedule-sidebar-calendar-btn')?.addEventListener('click', openCalendar);

// data-booking-json delegation (overview + schedule sched-items)
document.addEventListener('click', e => {
  const item = e.target.closest('[data-booking-json]');
  if (item) showBookingDetail(JSON.parse(item.dataset.bookingJson));
});

// data-confirm-booking / data-cancel-booking delegation
document.addEventListener('click', e => {
  const confirmBtn = e.target.closest('[data-confirm-booking]');
  if (confirmBtn) {
    const bid = confirmBtn.dataset.confirmBooking;
    const selId = confirmBtn.dataset.roomSel;
    confirmBooking(bid, document.getElementById(selId)?.value);
    return;
  }
  const cancelBtn = e.target.closest('[data-cancel-booking]');
  if (cancelBtn) {
    cancelBooking(cancelBtn.dataset.cancelBooking);
    return;
  }
});

// data-copy-link delegation
document.addEventListener('click', e => {
  const btn = e.target.closest('[data-copy-link]');
  if (btn) copyLink(btn.dataset.copyLink, btn);
});

// data-select-bg delegation
document.getElementById('bg-library')?.addEventListener('click', e => {
  const thumb = e.target.closest('[data-select-bg]');
  if (thumb) selectBg(thumb, parseInt(thumb.dataset.selectBg));
});

// data-confirm form submission (delete room)
document.addEventListener('submit', e => {
  const form = e.target.closest('form[data-confirm]');
  if (form && !confirm(form.dataset.confirm)) e.preventDefault();
});

// Rooms section
document.getElementById('new-room-btn')?.addEventListener('click', () => toggleCreatePanel());
document.getElementById('create-panel-cancel-btn')?.addEventListener('click', () => toggleCreatePanel(false));
document.getElementById('rooms-create-first-btn')?.addEventListener('click', () => toggleCreatePanel(true));
document.getElementById('background')?.addEventListener('change', function() { previewBg(this); });
document.getElementById('dash-tab-images')?.addEventListener('click', () => switchDashTab('images'));
document.getElementById('dash-tab-videos')?.addEventListener('click', () => switchDashTab('videos'));

// Schedule section
document.getElementById('schedule-open-calendar-btn')?.addEventListener('click', openCalendar);

// Clients section
document.getElementById('clientSearchInput')?.addEventListener('input', debounceClientSearch);
document.getElementById('close-client-detail-btn')?.addEventListener('click', closeClientDetail);

// Practitioner-initiated booking + referral — delegated handlers (panel re-renders per client)
document.getElementById('clientDetailContent')?.addEventListener('click', async e => {
  const openBtn   = e.target.closest('#schedule-for-client-btn');
  const cancelBtn = e.target.closest('#sfc-cancel-btn');
  const submitBtn = e.target.closest('#sfc-submit-btn');
  const referBtn  = e.target.closest('#refer-client-btn');

  if (referBtn) {
    refOpenCompose({
      endUserId: parseInt(referBtn.dataset.endUserId, 10) || 0,
      name:      referBtn.dataset.clientName  || '',
      email:     referBtn.dataset.clientEmail || '',
    });
    return;
  }

  if (openBtn) {
    const panel = document.getElementById('schedule-for-client-panel');
    if (!panel) return;
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    if (panel.style.display === 'block') {
      // Default date = tomorrow, time = 14:00
      const t = new Date(); t.setDate(t.getDate() + 1);
      const yyyy = t.getFullYear(), mm = String(t.getMonth()+1).padStart(2,'0'), dd = String(t.getDate()).padStart(2,'0');
      document.getElementById('sfc-date').value = `${yyyy}-${mm}-${dd}`;
      if (!document.getElementById('sfc-time').value) document.getElementById('sfc-time').value = '14:00';

      // Populate room dropdown if not already
      const sel = document.getElementById('sfc-room');
      if (sel && sel.options.length <= 1) {
        try {
          const rRes = await fetch('/api/rooms.php?action=list');
          const rooms = await rRes.json();
          (rooms.rooms || rooms || []).forEach(r => {
            const o = document.createElement('option');
            o.value = r.id;
            o.textContent = r.name || ('Room ' + r.id);
            sel.appendChild(o);
          });
        } catch(e) { /* silent */ }
      }
    }
    return;
  }

  if (cancelBtn) {
    const panel = document.getElementById('schedule-for-client-panel');
    if (panel) panel.style.display = 'none';
    return;
  }

  if (submitBtn) {
    const date = document.getElementById('sfc-date').value;
    const time = document.getElementById('sfc-time').value;
    const dur  = parseInt(document.getElementById('sfc-duration').value || '60', 10);
    const room = document.getElementById('sfc-room').value;
    const errEl = document.getElementById('sfc-error');
    const okEl  = document.getElementById('sfc-success');
    errEl.style.display = 'none'; okEl.style.display = 'none';

    if (!date || !time) {
      errEl.textContent = 'Date and time are required.';
      errEl.style.display = 'block';
      return;
    }

    // Local datetime → UTC ISO
    const localDt = new Date(`${date}T${time}:00`);
    if (isNaN(localDt.getTime())) {
      errEl.textContent = 'Invalid date or time.'; errEl.style.display = 'block'; return;
    }
    const utcStr = localDt.toISOString().slice(0, 19).replace('T', ' ');

    const trigger = document.getElementById('schedule-for-client-btn');
    const endUserId = trigger?.dataset.endUserId || '';
    const guestName = trigger?.dataset.clientName || '';
    const guestEmail= trigger?.dataset.clientEmail || '';

    submitBtn.disabled = true; submitBtn.textContent = 'Sending…';
    try {
      const fd = new FormData();
      fd.append('action', 'create_for_client');
      fd.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
      if (endUserId)  fd.append('end_user_id',      endUserId);
      if (guestName)  fd.append('guest_name',       guestName);
      if (guestEmail) fd.append('guest_email',      guestEmail);
      fd.append('scheduled_at_utc', utcStr);
      fd.append('duration_minutes', String(dur));
      if (room) fd.append('room_id', room);

      const res = await fetch('/api/bookings.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.error || 'Could not schedule.');
      }
      okEl.textContent = 'Session scheduled — confirmation email sent.';
      okEl.style.display = 'block';
      setTimeout(() => {
        document.getElementById('schedule-for-client-panel').style.display = 'none';
        // Refresh client detail to show the new booking
        if (_currentClientId) openClientDetail(_currentClientId);
      }, 1400);
    } catch (e) {
      errEl.textContent = e.message;
      errEl.style.display = 'block';
    } finally {
      submitBtn.disabled = false; submitBtn.textContent = 'Send to client';
    }
  }
});

// Client list delegation (data-client-uid on tr, data-view-client on button)
document.getElementById('clientsList').addEventListener('click', e => {
  const viewBtn = e.target.closest('[data-view-client]');
  const tr = e.target.closest('tr[data-client-uid]');
  if (viewBtn) {
    openClientDetail(parseInt(viewBtn.dataset.clientUid), viewBtn.dataset.clientName);
    return;
  }
  if (tr) openClientDetail(parseInt(tr.dataset.clientUid), tr.dataset.clientName);
});

// Client detail delegation (tabs, session review, goals)
document.getElementById('clientDetail').addEventListener('click', e => {
  const tabBtn = e.target.closest('[data-cl-tab]');
  if (tabBtn) { switchClientTab(tabBtn.dataset.clTab); return; }

  const srBtn = e.target.closest('[data-open-session-review]');
  if (srBtn) { openSessionReview(parseInt(srBtn.dataset.openSessionReview), srBtn.dataset.sessionLabel); return; }

  const toggleGoal = e.target.closest('[data-toggle-goal]');
  if (toggleGoal) { toggleGoalExpand(parseInt(toggleGoal.dataset.toggleGoal)); return; }

  const deleteGoalBtn = e.target.closest('[data-delete-goal]');
  if (deleteGoalBtn) { e.stopPropagation(); deleteGoal(parseInt(deleteGoalBtn.dataset.deleteGoal)); return; }

  const updateStatusBtn = e.target.closest('[data-update-goal-status]');
  if (updateStatusBtn) { updateGoalStatus(parseInt(updateStatusBtn.dataset.updateGoalStatus), updateStatusBtn.dataset.status); return; }

  const toggleMilestoneBtn = e.target.closest('[data-toggle-milestone]');
  if (toggleMilestoneBtn) { toggleMilestone(parseInt(toggleMilestoneBtn.dataset.toggleMilestone), parseInt(toggleMilestoneBtn.dataset.goalId)); return; }

  const deleteMilestoneBtn = e.target.closest('[data-delete-milestone]');
  if (deleteMilestoneBtn) { deleteMilestone(parseInt(deleteMilestoneBtn.dataset.deleteMilestone), parseInt(deleteMilestoneBtn.dataset.goalId)); return; }

  const addMilestoneBtn = e.target.closest('[data-add-milestone]');
  if (addMilestoneBtn) { addMilestone(parseInt(addMilestoneBtn.dataset.addMilestone)); return; }

  const logProgressBtn = e.target.closest('[data-log-progress]');
  if (logProgressBtn) { logProgress(parseInt(logProgressBtn.dataset.logProgress)); return; }

  const addGoalBtn = e.target.closest('#add-client-goal-btn');
  if (addGoalBtn) { addClientGoal(); return; }
});

// Inbox conversations delegation
document.getElementById('dashIbList').addEventListener('click', e => {
  const item = e.target.closest('.ib-conv-item[data-client-id]');
  if (item) dashOpenThread(parseInt(item.dataset.clientId), item.dataset.clientName);
});

// Inbox lightbox delegation
document.addEventListener('click', e => {
  const img = e.target.closest('.ib-img-thumb[data-lightbox-src]');
  if (img) dashOpenLightbox(img.dataset.lightboxSrc);
});

// Backgrounds lib tabs
document.getElementById('dash-lib-tab-images')?.addEventListener('click', () => switchDashLibTab('images'));
document.getElementById('dash-lib-tab-videos')?.addEventListener('click', () => switchDashLibTab('videos'));

// Finance
document.getElementById('fin-export-btn')?.addEventListener('click', exportFinancialCSV);
document.getElementById('fin-filter-status')?.addEventListener('change', loadFinancialData);
document.getElementById('fin-filter-gateway')?.addEventListener('change', loadFinancialData);

document.getElementById('fin-table-body')?.addEventListener('click', async e => {
  const btn = e.target.closest('[data-mark-paid]');
  if (!btn) return;
  const payId = parseInt(btn.dataset.markPaid);
  if (!payId || !confirm('Mark this payment as paid?')) return;
  btn.disabled = true;
  btn.textContent = 'Saving…';
  try {
    const res  = await fetch('/api/payments.php?action=mark_paid', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ payment_id: payId, csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>' }),
    });
    const data = await res.json();
    if (data.ok) {
      financeLoaded = false;
      loadFinancialData();
    } else {
      alert(data.error || 'Could not mark as paid.');
      btn.disabled = false;
      btn.textContent = 'Mark Paid';
    }
  } catch(e) {
    alert('Request failed. Please try again.');
    btn.disabled = false;
    btn.textContent = 'Mark Paid';
  }
});

// Journeys
document.getElementById('new-journey-btn')?.addEventListener('click', showCreateSeriesPanel);
document.getElementById('create-series-btn')?.addEventListener('click', createSeries);
document.getElementById('cancel-series-btn')?.addEventListener('click', hideCreateSeriesPanel);
document.getElementById('back-to-journeys-btn')?.addEventListener('click', backToSeriesList);
document.getElementById('edit-series-btn')?.addEventListener('click', showEditSeriesPanel);
document.getElementById('sd-shared-notes')?.addEventListener('input', scheduleArcNotesSave);
document.getElementById('save-edit-series-btn')?.addEventListener('click', saveEditSeries);
document.getElementById('cancel-edit-series-btn')?.addEventListener('click', hideEditSeriesPanel);
document.getElementById('delete-series-btn')?.addEventListener('click', deleteSeries);

// Journey card delegation
document.getElementById('series-list').addEventListener('click', e => {
  const card = e.target.closest('[data-open-series]');
  if (card) openSeriesDetail(parseInt(card.dataset.openSeries));
});

// Booking detail modal
document.getElementById('booking-detail-modal')?.addEventListener('click', e => {
  if (e.target === document.getElementById('booking-detail-modal')) closeDetailOnBackdrop(e);
});
document.getElementById('bd-close-btn')?.addEventListener('click', closeBookingDetail);
document.getElementById('bd-close-bottom-btn')?.addEventListener('click', closeBookingDetail);
document.getElementById('bd-reschedule-btn')?.addEventListener('click', () => toggleRescheduleForm(true));
document.getElementById('bd-reschedule-confirm-btn')?.addEventListener('click', submitReschedule);
document.getElementById('bd-reschedule-cancel-btn')?.addEventListener('click', () => toggleRescheduleForm(false));
document.getElementById('bd-intake-btn')?.addEventListener('click', openBdIntakeAssign);
document.getElementById('bd-send-intake-btn')?.addEventListener('click', sendIntakeForm);
document.getElementById('bd-intake-cancel-btn')?.addEventListener('click', () => {
  document.getElementById('bd-intake-assign').style.display = 'none';
});
document.getElementById('bd-cancel-btn')?.addEventListener('click', submitDetailCancel);

// Calendar modal
document.getElementById('cal-modal')?.addEventListener('click', e => {
  if (e.target === document.getElementById('cal-modal')) closeCalendarOnBackdrop(e);
});
document.getElementById('cal-prev-btn')?.addEventListener('click', calPrevMonth);
document.getElementById('cal-next-btn')?.addEventListener('click', calNextMonth);
document.getElementById('cal-close-btn')?.addEventListener('click', closeCalendar);

// Session review
document.getElementById('sr-close-top-btn')?.addEventListener('click', closeSessionReview);
document.getElementById('sr-close-bottom-btn')?.addEventListener('click', closeSessionReview);

// Session review filter chips delegation
document.getElementById('srPanel')?.addEventListener('click', e => {
  const jumpBtn = e.target.closest('[data-jump-to]');
  if (jumpBtn) srJumpTo(parseInt(jumpBtn.dataset.jumpTo));
});
document.getElementById('srChat')?.addEventListener('click', e => {});
// srChips is dynamically rendered — use document-level delegation
document.addEventListener('click', e => {
  const chip = e.target.closest('#srChips [data-filter]');
  if (chip) srToggleFilter(chip.dataset.filter, chip);
});

// MRM result rows delegation
document.getElementById('dash-mrm-body')?.addEventListener('click', e => {
  const row = e.target.closest('[data-mrm-result]');
  if (row) dashMrmSelectResult(parseInt(row.dataset.mrmResult));
});

// Post-script media modal listeners (elements exist in DOM at this point if script runs after DOMContentLoaded)
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('dash-mrm-backdrop')?.addEventListener('click', dashCloseMediaModal);
  document.getElementById('dash-mrm-close-btn')?.addEventListener('click', dashCloseMediaModal);
  document.getElementById('dash-mrm-tab-books')?.addEventListener('click', function() { dashMrmSetType('books', this); });
  document.getElementById('dash-mrm-tab-video')?.addEventListener('click', function() { dashMrmSetType('video', this); });
  document.getElementById('dash-mrm-query')?.addEventListener('keydown', e => { if (e.key === 'Enter') dashMrmSearch(); });
  document.getElementById('dash-mrm-search-btn')?.addEventListener('click', dashMrmSearch);
  document.getElementById('dash-mrm-back-btn')?.addEventListener('click', () => { _dashMrmSelected = null; dashMrmShowResults(); });
  document.getElementById('dash-mrm-share-btn')?.addEventListener('click', dashMrmShare);
});

// ══════════════════════════════════════════════════════════════
// Referrals
// ══════════════════════════════════════════════════════════════
let _refBox = 'in';   // 'in' | 'out'
let _refIncoming = [];
let _refOutgoing = [];
let _refClientList = null;   // cached list of practitioner's clients for the compose form

function refEsc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

async function refLoadList() {
  try {
    const res = await fetch('/api/referrals.php?action=list');
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Could not load');
    _refIncoming = data.incoming || [];
    _refOutgoing = data.outgoing || [];

    document.getElementById('ref-in-count').textContent  = _refIncoming.length ? '(' + _refIncoming.length + ')' : '';
    document.getElementById('ref-out-count').textContent = _refOutgoing.length ? '(' + _refOutgoing.length + ')' : '';

    // Sidebar nav badge — only count pending incoming so it's an "action needed" signal
    const pending = (data.counts && data.counts.pending_in) || 0;
    const badge = document.getElementById('dashReferralsBadge');
    if (badge) {
      badge.textContent = pending;
      badge.style.display = pending > 0 ? '' : 'none';
    }

    refRenderList();
  } catch(e) {
    document.getElementById('ref-list').innerHTML = '<div style="color:var(--error,#e85555);font-size:13px;padding:18px;">Could not load referrals.</div>';
  }
}

function refRenderList() {
  const el = document.getElementById('ref-list');
  const rows = _refBox === 'in' ? _refIncoming : _refOutgoing;
  if (!rows.length) {
    el.innerHTML = `<div style="color:var(--text-muted);font-size:13px;padding:32px 18px;text-align:center;">
      ${_refBox === 'in'
        ? 'No referrals received yet. When another practitioner sends one, it will appear here.'
        : 'No referrals sent yet. Open a client and use the Refer button — or click "+ New Referral" above.'}
    </div>`;
    return;
  }

  el.innerHTML = rows.map(r => {
    const statusColors = {
      pending:   '#f5c842',
      accepted:  '#3ecf8e',
      responded: '#7c6af7',
      rejected:  '#e85555',
      withdrawn: '#888',
    };
    const statusColor = statusColors[r.status] || '#888';
    const otherName = _refBox === 'in' ? (r.from_name || 'Unknown') : (r.to_name || 'Unknown');
    const direction = _refBox === 'in' ? 'From' : 'To';
    const clientLabel = (r.guest_name || '(client)') + (r.guest_email ? ' · ' + r.guest_email : '');
    const dateStr = new Date((r.sent_at || '').replace(' ', 'T') + 'Z').toLocaleString(undefined, {
      year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    });

    return `<div class="ref-row" data-ref-id="${r.id}" style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px 18px;cursor:pointer;transition:border-color 0.15s;">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:6px;">
        <div style="font-size:13.5px;color:var(--text);font-weight:600;">${refEsc(direction)}: ${refEsc(otherName)}</div>
        <span style="font-family:var(--mono);font-size:10px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;padding:2px 8px;border-radius:4px;background:${statusColor}22;color:${statusColor};border:1px solid ${statusColor}55;">${refEsc(r.status)}</span>
      </div>
      <div style="font-size:12.5px;color:var(--text-muted);margin-bottom:4px;">Re: <strong style="color:var(--text);font-weight:500;">${refEsc(clientLabel)}</strong></div>
      <div style="font-size:11.5px;color:var(--text-dim);font-family:var(--mono);">${refEsc(dateStr)}</div>
    </div>`;
  }).join('');

  el.querySelectorAll('[data-ref-id]').forEach(row => {
    row.addEventListener('mouseenter', () => row.style.borderColor = 'var(--accent)');
    row.addEventListener('mouseleave', () => row.style.borderColor = 'var(--border)');
    row.addEventListener('click', () => refOpenDetail(parseInt(row.dataset.refId, 10)));
  });
}

async function refOpenDetail(id) {
  document.getElementById('ref-detail-modal').style.display = 'flex';
  const body = document.getElementById('ref-detail-body');
  body.innerHTML = '<div style="text-align:center;padding:24px;"><div class="spinner"></div></div>';
  try {
    const res = await fetch('/api/referrals.php?action=detail&id=' + encodeURIComponent(id));
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Could not load');
    refRenderDetail(data.referral);
  } catch(e) {
    body.innerHTML = '<div style="color:var(--error,#e85555);">Could not load.</div>';
  }
}

function refRenderDetail(r) {
  const body = document.getElementById('ref-detail-body');
  const isRecipient = r.my_role === 'recipient';
  const otherName = isRecipient ? r.from_name : r.to_name;
  const direction = isRecipient ? 'From' : 'To';
  const clientLabel = (r.guest_name || '(client)') + (r.guest_email ? ' · ' + r.guest_email : '');
  const sentDate = new Date((r.sent_at || '').replace(' ', 'T') + 'Z').toLocaleString();

  // Permission summary line
  const flags = [];
  if (parseInt(r.share_basic_info))     flags.push('Basic info');
  if (parseInt(r.share_intake_history)) flags.push('Intake history');
  if (parseInt(r.share_session_notes))  flags.push('Session notes');
  if (parseInt(r.share_goals))          flags.push('Goals');
  if (parseInt(r.share_themes))         flags.push('Themes');

  // Action buttons depend on status + role
  let actions = '';
  if (r.status === 'pending' && isRecipient) {
    actions = `
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px;padding-top:18px;border-top:1px solid var(--border);">
        <button class="btn-c primary" type="button" id="ref-detail-accept-btn">Accept</button>
        <button class="btn-c" type="button" id="ref-detail-respond-btn" style="background:rgba(124,106,247,0.12);border:1px solid rgba(124,106,247,0.35);color:#c5b8ff;">Respond</button>
        <button class="btn-c ghost" type="button" id="ref-detail-reject-btn" style="margin-left:auto;color:#e85555;">Decline</button>
      </div>
      <div id="ref-detail-respond-form" style="display:none;margin-top:12px;">
        <textarea id="ref-detail-respond-msg" rows="3" class="input-field" placeholder="Your message…" style="width:100%;resize:vertical;box-sizing:border-box;"></textarea>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px;">
          <button class="btn-c ghost sm" type="button" id="ref-detail-respond-cancel">Cancel</button>
          <button class="btn-c primary sm" type="button" id="ref-detail-respond-send">Send response</button>
          <button class="btn-c sm" type="button" id="ref-detail-reject-confirm" style="background:rgba(232,85,85,0.12);border:1px solid rgba(232,85,85,0.35);color:#e85555;display:none;">Confirm decline</button>
        </div>
      </div>`;
  } else if (r.status === 'pending' && !isRecipient) {
    actions = `
      <div style="display:flex;gap:8px;margin-top:18px;padding-top:18px;border-top:1px solid var(--border);">
        <button class="btn-c ghost" type="button" id="ref-detail-withdraw-btn" style="color:#e85555;">Withdraw</button>
      </div>`;
  }

  // Snapshot rendering — only shown to recipient after accept
  let snapshotHtml = '';
  if (r.accepted_snapshot && isRecipient) {
    const s = r.accepted_snapshot;
    const blocks = [];
    if (s.basic) blocks.push(`<div><strong>Basic info:</strong> ${refEsc(s.basic.name || '')}${s.basic.email ? ' &middot; ' + refEsc(s.basic.email) : ''}</div>`);
    if (s.intake_history && s.intake_history.length) blocks.push(`<div><strong>Intake history:</strong> ${s.intake_history.length} response${s.intake_history.length === 1 ? '' : 's'}</div>`);
    if (s.session_notes && s.session_notes.length)   blocks.push(`<div><strong>Session notes:</strong> ${s.session_notes.length} session${s.session_notes.length === 1 ? '' : 's'}</div>`);
    if (s.goals && s.goals.length)   blocks.push(`<div><strong>Goals:</strong> ${s.goals.length}</div>`);
    if (s.themes && s.themes.length) blocks.push(`<div><strong>Themes:</strong> ${s.themes.map(t => refEsc(t.name)).join(', ')}</div>`);
    if (blocks.length) {
      snapshotHtml = `<div style="margin-top:14px;padding:12px 14px;background:rgba(78,203,138,0.06);border:1px solid rgba(78,203,138,0.18);border-radius:8px;font-size:12.5px;color:var(--text-muted);line-height:1.7;">${blocks.join('')}</div>`;
    }
  }

  body.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:14px;">
      <h2 style="font-size:18px;font-weight:700;color:var(--text);">${refEsc(direction)}: ${refEsc(otherName)}</h2>
      <button class="btn-c ghost sm" type="button" id="ref-detail-close">✕</button>
    </div>
    <div style="font-size:11.5px;color:var(--text-muted);margin-bottom:14px;">${refEsc(sentDate)} · status: <span style="font-weight:600;color:var(--text);">${refEsc(r.status)}</span></div>

    <div style="font-size:12px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;color:rgba(124,106,247,0.7);margin-bottom:6px;">Client</div>
    <div style="font-size:13.5px;color:var(--text);margin-bottom:14px;">${refEsc(clientLabel)}</div>

    <div style="font-size:12px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;color:rgba(124,106,247,0.7);margin-bottom:6px;">Sender notes</div>
    <div style="font-size:13.5px;color:var(--text);line-height:1.65;white-space:pre-line;margin-bottom:14px;">${refEsc(r.referral_notes || '(no notes)')}</div>

    <div style="font-size:12px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;color:rgba(124,106,247,0.7);margin-bottom:6px;">Authorised to share on Accept</div>
    <div style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">${flags.length ? flags.join(' · ') : 'Basic info only'}</div>

    ${(r.response_message && r.response_message !== '') ? `
      <div style="font-size:12px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;color:rgba(124,106,247,0.7);margin-bottom:6px;">${isRecipient ? 'Your response' : 'Recipient response'}</div>
      <div style="font-size:13.5px;color:var(--text);line-height:1.65;white-space:pre-line;margin-bottom:14px;">${refEsc(r.response_message)}</div>
    ` : ''}

    ${snapshotHtml}

    <div id="ref-detail-error" style="display:none;color:var(--error,#e85555);font-size:12.5px;margin-top:10px;"></div>
    <div id="ref-detail-success" style="display:none;color:#3ecf8e;font-size:12.5px;margin-top:10px;"></div>

    ${actions}
  `;

  document.getElementById('ref-detail-close')?.addEventListener('click', refCloseDetail);
  document.getElementById('ref-detail-accept-btn')?.addEventListener('click', () => refAccept(r.id));
  document.getElementById('ref-detail-withdraw-btn')?.addEventListener('click', () => refWithdraw(r.id));
  document.getElementById('ref-detail-respond-btn')?.addEventListener('click', () => {
    document.getElementById('ref-detail-respond-form').style.display = 'block';
    document.getElementById('ref-detail-reject-confirm').style.display = 'none';
  });
  document.getElementById('ref-detail-reject-btn')?.addEventListener('click', () => {
    document.getElementById('ref-detail-respond-form').style.display = 'block';
    document.getElementById('ref-detail-reject-confirm').style.display = '';
    document.getElementById('ref-detail-respond-msg').placeholder = 'Optional reason…';
  });
  document.getElementById('ref-detail-respond-cancel')?.addEventListener('click', () => {
    document.getElementById('ref-detail-respond-form').style.display = 'none';
  });
  document.getElementById('ref-detail-respond-send')?.addEventListener('click', () => refRespond(r.id));
  document.getElementById('ref-detail-reject-confirm')?.addEventListener('click', () => refReject(r.id));
}

function refCloseDetail() {
  document.getElementById('ref-detail-modal').style.display = 'none';
}

async function refSubmit(action, id, extra) {
  const errEl = document.getElementById('ref-detail-error');
  const okEl  = document.getElementById('ref-detail-success');
  if (errEl) errEl.style.display = 'none';
  if (okEl)  okEl.style.display  = 'none';
  const body = Object.assign({ action, id, csrf_token: DASH_CSRF }, extra || {});
  try {
    const res = await fetch('/api/referrals.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Failed');
    if (okEl) { okEl.textContent = 'Done.'; okEl.style.display = 'block'; }
    setTimeout(() => { refCloseDetail(); refLoadList(); }, 700);
    return true;
  } catch(e) {
    if (errEl) { errEl.textContent = e.message; errEl.style.display = 'block'; }
    return false;
  }
}

function refAccept(id) {
  if (!confirm('Accept this referral? The authorised data will be copied to your records and the client will be notified by email.')) return;
  refSubmit('accept', id);
}
function refWithdraw(id) {
  if (!confirm('Withdraw this referral?')) return;
  refSubmit('withdraw', id);
}
function refRespond(id) {
  const msg = document.getElementById('ref-detail-respond-msg').value.trim();
  if (!msg) { document.getElementById('ref-detail-error').textContent = 'Please type a message.'; document.getElementById('ref-detail-error').style.display = 'block'; return; }
  refSubmit('respond', id, { message: msg });
}
function refReject(id) {
  const msg = document.getElementById('ref-detail-respond-msg').value.trim();
  refSubmit('reject', id, { message: msg });
}

// ── Compose modal ──
function refOpenCompose(prefill) {
  document.getElementById('ref-compose-modal').style.display = 'flex';
  // Reset
  document.getElementById('ref-recipient-search').value = '';
  document.getElementById('ref-recipient-results').style.display = 'none';
  document.getElementById('ref-to-id').value = '';
  document.getElementById('ref-to-pill').style.display = 'none';
  document.getElementById('ref-notes').value = '';
  document.getElementById('ref-guest-name').value  = prefill?.name  || '';
  document.getElementById('ref-guest-email').value = prefill?.email || '';
  ['ref-share-intake','ref-share-notes','ref-share-goals','ref-share-themes'].forEach(id => document.getElementById(id).checked = false);
  document.getElementById('ref-compose-error').style.display = 'none';
  document.getElementById('ref-compose-success').style.display = 'none';

  // Populate client dropdown from the practitioner's clients (cached after first call)
  refPopulateClientSelect(prefill?.endUserId || '');
}

async function refPopulateClientSelect(preselectId) {
  const sel = document.getElementById('ref-client-select');
  if (!sel) return;
  if (!_refClientList) {
    try {
      const res = await fetch('/api/practitioner_clients.php?action=list');
      const data = await res.json();
      // list returns a flat array; only portal users (end_user_id present) can be selected
      _refClientList = (Array.isArray(data) ? data : []).filter(c => c.end_user_id);
    } catch(e) { _refClientList = []; }
  }
  sel.innerHTML = '<option value="">— Select an existing client —</option>'
    + _refClientList.map(c => `<option value="${c.end_user_id}">${refEsc(c.display_name || c.name || c.email)}</option>`).join('');
  if (preselectId) sel.value = String(preselectId);
}

function refCloseCompose() {
  document.getElementById('ref-compose-modal').style.display = 'none';
}

async function refSearchRecipients(q) {
  const resultsEl = document.getElementById('ref-recipient-results');
  if (!q || q.length < 2) { resultsEl.style.display = 'none'; return; }
  try {
    const res = await fetch('/api/referrals.php?action=search_recipients&q=' + encodeURIComponent(q));
    const data = await res.json();
    const list = data.results || [];
    if (!list.length) {
      resultsEl.innerHTML = '<div style="padding:10px 14px;color:var(--text-muted);font-size:12.5px;">No matches.</div>';
    } else {
      resultsEl.innerHTML = list.map(p => `<div data-ref-pick="${p.id}" data-ref-pick-name="${refEsc(p.display_name)}" style="padding:9px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;">
        <div style="width:28px;height:28px;border-radius:50%;background:rgba(124,106,247,0.18);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;color:#c5b8ff;flex-shrink:0;">${refEsc((p.display_name || '?').slice(0,1).toUpperCase())}</div>
        <div style="flex:1;min-width:0;">
          <div style="color:var(--text);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${refEsc(p.display_name)}</div>
          <div style="color:var(--text-muted);font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${refEsc(p.email)} · ${refEsc(p.service_type || '')}</div>
        </div>
      </div>`).join('');
    }
    resultsEl.style.display = 'block';
  } catch(e) { resultsEl.style.display = 'none'; }
}

async function refSendCompose() {
  const errEl = document.getElementById('ref-compose-error');
  const okEl  = document.getElementById('ref-compose-success');
  errEl.style.display = 'none'; okEl.style.display = 'none';

  const toId = parseInt(document.getElementById('ref-to-id').value, 10) || 0;
  if (!toId) { errEl.textContent = 'Please select a recipient practitioner.'; errEl.style.display = 'block'; return; }

  const endUserId = parseInt(document.getElementById('ref-client-select').value, 10) || 0;
  const guestName = document.getElementById('ref-guest-name').value.trim();
  const guestEmail= document.getElementById('ref-guest-email').value.trim();
  if (!endUserId && (!guestName || !guestEmail)) {
    errEl.textContent = 'Pick an existing client or enter guest name + email.';
    errEl.style.display = 'block'; return;
  }

  const body = {
    action:                'send',
    csrf_token:            DASH_CSRF,
    to_practitioner_id:    toId,
    end_user_id:           endUserId || 0,
    guest_name:            guestName,
    guest_email:           guestEmail,
    referral_notes:        document.getElementById('ref-notes').value,
    share_basic_info:      1,
    share_intake_history:  document.getElementById('ref-share-intake').checked ? 1 : 0,
    share_session_notes:   document.getElementById('ref-share-notes').checked  ? 1 : 0,
    share_goals:           document.getElementById('ref-share-goals').checked  ? 1 : 0,
    share_themes:          document.getElementById('ref-share-themes').checked ? 1 : 0,
  };

  try {
    const res  = await fetch('/api/referrals.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Failed');
    okEl.textContent = 'Referral sent — recipient has been notified by email.';
    okEl.style.display = 'block';
    setTimeout(() => { refCloseCompose(); refLoadList(); }, 1200);
  } catch(e) {
    errEl.textContent = e.message;
    errEl.style.display = 'block';
  }
}

// Wire static handlers
document.querySelectorAll('.ref-tab-btn').forEach(b => {
  b.addEventListener('click', () => {
    _refBox = b.dataset.refBox;
    document.querySelectorAll('.ref-tab-btn').forEach(x => x.classList.toggle('active', x === b));
    refRenderList();
  });
});
document.getElementById('ref-new-btn')?.addEventListener('click', () => refOpenCompose());
document.getElementById('ref-compose-close')?.addEventListener('click', refCloseCompose);
document.getElementById('ref-compose-cancel-btn')?.addEventListener('click', refCloseCompose);
document.getElementById('ref-compose-send-btn')?.addEventListener('click', refSendCompose);

// Recipient search (debounced)
let _refSearchTimer = null;
document.getElementById('ref-recipient-search')?.addEventListener('input', e => {
  clearTimeout(_refSearchTimer);
  _refSearchTimer = setTimeout(() => refSearchRecipients(e.target.value.trim()), 200);
});
document.getElementById('ref-recipient-results')?.addEventListener('click', e => {
  const pick = e.target.closest('[data-ref-pick]');
  if (!pick) return;
  document.getElementById('ref-to-id').value = pick.dataset.refPick;
  document.getElementById('ref-to-pill').innerHTML = `<span>To: <strong style="color:var(--text);">${pick.dataset.refPickName}</strong></span><button class="btn-c ghost sm" type="button" onclick="document.getElementById('ref-to-id').value='';this.parentElement.style.display='none';document.getElementById('ref-recipient-search').value='';">Change</button>`;
  document.getElementById('ref-to-pill').style.display = 'flex';
  document.getElementById('ref-recipient-results').style.display = 'none';
  document.getElementById('ref-recipient-search').value = '';
});

// Add ref-tab-btn styling — minimal addition that fits the existing dashboard look
(function injectRefTabStyle() {
  const css = `
    .ref-tab-btn{background:transparent;border:none;border-bottom:2px solid transparent;color:var(--text-muted);font-family:inherit;font-size:13px;font-weight:500;padding:10px 18px;cursor:pointer;transition:color 0.15s,border-color 0.15s;margin-bottom:-1px;}
    .ref-tab-btn:hover{color:var(--text);}
    .ref-tab-btn.active{color:var(--accent);border-bottom-color:var(--accent);font-weight:600;}
  `;
  const s = document.createElement('style');
  s.textContent = css;
  document.head.appendChild(s);
})();
</script>

<!-- ── Media Rec Modal (Dashboard Inbox) ── -->
<div id="dash-media-rec-modal" style="display:none;position:fixed;inset:0;z-index:10500;">
  <div id="dash-mrm-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,0.72);"></div>
  <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:540px;max-width:calc(100vw - 24px);max-height:84vh;background:#0d1433;border:1px solid rgba(124,106,247,0.25);border-radius:14px;display:flex;flex-direction:column;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid rgba(255,255,255,0.07);flex-shrink:0;">
      <span style="font-size:14px;font-weight:700;color:#dddcf2;">📖 Recommend Media</span>
      <button id="dash-mrm-close-btn" style="background:none;border:none;color:rgba(221,220,242,0.5);font-size:18px;cursor:pointer;padding:0 4px;">✕</button>
    </div>
    <div id="dash-mrm-tabs" style="display:flex;padding:12px 20px 0;flex-shrink:0;border-bottom:1px solid rgba(255,255,255,0.06);">
      <button class="mrm-tab" id="dash-mrm-tab-books" style="padding:8px 18px;border:none;border-bottom:2px solid #7c6af7;background:transparent;color:#7c6af7;font-size:13px;font-weight:600;cursor:pointer;">📚 Books</button>
      <button class="mrm-tab" id="dash-mrm-tab-video" style="padding:8px 18px;border:none;border-bottom:2px solid transparent;background:transparent;color:rgba(221,220,242,0.45);font-size:13px;font-weight:600;cursor:pointer;">🎬 Movies &amp; TV</button>
    </div>
    <div style="display:flex;gap:8px;padding:12px 20px;flex-shrink:0;">
      <input type="text" id="dash-mrm-query" placeholder="Search…" style="flex:1;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);border-radius:8px;padding:9px 14px;color:#dddcf2;font-size:13px;outline:none;font-family:inherit;">
      <button id="dash-mrm-search-btn" class="btn-c primary sm" style="flex-shrink:0;">Search</button>
    </div>
    <div id="dash-mrm-body" style="overflow-y:auto;flex:1;"></div>
    <div id="dash-mrm-detail-footer" style="display:none;padding:14px 20px;border-top:1px solid rgba(255,255,255,0.07);flex-shrink:0;">
      <textarea id="dash-mrm-note" rows="2" placeholder="Add a note (optional): I think you'll like this because…" style="width:100%;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:9px 12px;color:#dddcf2;font-size:12.5px;font-family:inherit;resize:none;outline:none;box-sizing:border-box;margin-bottom:10px;"></textarea>
      <div style="display:flex;gap:8px;">
        <button id="dash-mrm-back-btn" class="btn-c sm" style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:rgba(221,220,242,0.7);">← Back</button>
        <button id="dash-mrm-share-btn" class="btn-c primary sm" style="flex:1;">Share Recommendation</button>
      </div>
    </div>
  </div>
</div>

</body>
</html>
