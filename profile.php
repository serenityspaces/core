<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();

if (empty($_SESSION['practitioner_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/geo.php';
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/includes/banners.php';
require_once __DIR__ . '/includes/session_timeout.php';
requireSetup();

$pdo     = getDB();
$practId = (int)$_SESSION['practitioner_id'];
$next    = trim($_GET['next'] ?? '/dashboard.php');

if (!preg_match('#^/(room\.php|dashboard\.php)#', $next)) {
    $next = '/dashboard.php';
}

$stmt = $pdo->prepare('SELECT * FROM practitioners WHERE id = ? LIMIT 1');
$stmt->execute([$practId]);
$practitioner = $stmt->fetch();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$profileError   = '';
$profileSuccess = '';
$pwError        = '';
$pwSuccess      = '';
$colorSuccess   = '';

$hasAvatar     = !empty($practitioner['avatar_path']);
$currentAvatar = $practitioner['avatar_path'] ?? '';
$previewUrl    = (strpos($currentAvatar, '/assets/') === 0) ? $currentAvatar : '';

// ── Wizard mode: new practitioners (no display_name) or explicit ?wizard= param ──
$isWizard   = isset($_GET['wizard']) || empty($practitioner['display_name']);
$wizardStep = max(1, min(2, (int)($_GET['wizard'] ?? 1)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $profileError = 'Security token mismatch. Please refresh and try again.';
    } else {
        $formType = $_POST['form_type'] ?? 'profile';

        // ── Profile update ──────────────────────────────────────
        if ($formType === 'profile') {
            $displayName = trim($_POST['display_name'] ?? '');
            if ($displayName === '') {
                $profileError = 'Please enter a display name.';
            } else {
                $avatarPath = $currentAvatar ?: null;

                if (!empty($_FILES['avatar_upload']['name']) && $_FILES['avatar_upload']['error'] === UPLOAD_ERR_OK) {
                    $file     = $_FILES['avatar_upload'];
                    $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $finfo    = new finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($file['tmp_name']);
                    if (!in_array($mimeType, $allowed, true)) {
                        $profileError = 'Only JPEG, PNG, GIF, and WEBP images are allowed.';
                    } elseif ($file['size'] > 5 * 1024 * 1024) {
                        $profileError = 'File size must not exceed 5 MB.';
                    } else {
                        $extMap     = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                        $ext        = $extMap[$mimeType] ?? 'bin';
                        $name       = bin2hex(random_bytes(12)) . '.' . $ext;
                        move_uploaded_file($file['tmp_name'], __DIR__ . '/assets/uploads/' . $name);
                        $avatarPath = '/assets/uploads/' . $name;
                    }
                } elseif (!$avatarPath) {
                    $profileError = 'Please upload a photo to continue.';
                }

                if ($profileError === '') {
                    $pdo->prepare('UPDATE practitioners SET display_name = ?, avatar_path = ? WHERE id = ?')
                        ->execute([$displayName, $avatarPath, $practId]);
                    if ($isWizard) {
                        header('Location: /profile.php?wizard=2');
                    } else {
                        header('Location: ' . $next);
                    }
                    exit;
                }
            }
        }

        // ── Password change ─────────────────────────────────────
        if ($formType === 'password') {
            $currentPw = $_POST['current_password'] ?? '';
            $newPw     = $_POST['new_password'] ?? '';
            $confirmPw = $_POST['confirm_password'] ?? '';

            if (!$currentPw || !$newPw || !$confirmPw) {
                $pwError = 'All password fields are required.';
            } elseif (!ss_password_verify($currentPw, $practitioner['password_hash'])) {
                $pwError = 'Current password is incorrect.';
            } elseif (($pwPolicyErr = validate_practitioner_password($newPw)) !== null) {
                $pwError = $pwPolicyErr;
            } elseif ($newPw !== $confirmPw) {
                $pwError = 'New passwords do not match.';
            } else {
                $hash = ss_password_hash($newPw);
                $pdo->prepare('UPDATE practitioners SET password_hash = ? WHERE id = ?')->execute([$hash, $practId]);
                $pwSuccess = 'Password updated successfully.';
            }
        }

        // ── About & Practice Types ──────────────────────────────
        if ($formType === 'about_practice') {
            $aboutWork     = trim($_POST['about_work'] ?? '');
            $practiceTypes = $_POST['practice_types'] ?? [];
            $allowed       = ['life_coaching','philosophical_counseling','licensed_therapy',
                              'peer_support','mindfulness','cbt','dbt','trauma_informed',
                              'grief_support','addiction_recovery',
                              'pastoral_care','spiritual_direction'];
            $practiceTypes = array_values(array_intersect((array)$practiceTypes, $allowed));
            $serviceType = trim($_POST['service_type'] ?? 'non_clinical');
            if (!in_array($serviceType, ['clinical','non_clinical'], true)) $serviceType = 'non_clinical';
            // Prevent setting clinical unless license is approved
            $lsCheck = $pdo->prepare('SELECT license_status FROM practitioners WHERE id = ? LIMIT 1');
            $lsCheck->execute([$practId]);
            $lsRow = $lsCheck->fetch();
            if ($serviceType === 'clinical' && ($lsRow['license_status'] ?? 'none') !== 'approved') {
                $serviceType = 'non_clinical';
            }
            // Also strip licensed-only practice types if not approved
            if (($lsRow['license_status'] ?? 'none') !== 'approved') {
                $licensedTypes = ['licensed_therapy','cbt','dbt'];
                $practiceTypes = array_values(array_diff($practiceTypes, $licensedTypes));
            }
            // Role icon — validate against allowed set; licensed practitioners are locked to mental-health
            $allowedIcons = ['ribbon','mental-health','lgbt','faith','bible','christianity',
                             'philosophy','philosophy-1','philosophy-2','philosophy-3',
                             'grief-support','lotus','meditation','yin-yang',
                             'emotional-intelligence','heart','social-services','brain'];
            $roleIcon = trim($_POST['role_icon'] ?? '');
            if (!in_array($roleIcon, $allowedIcons, true)) $roleIcon = '';
            if (($lsRow['license_status'] ?? 'none') === 'approved') $roleIcon = 'mental-health';
            $acceptingNew = isset($_POST['accepting_new_clients']) ? 1 : 0;
            $cancellationPolicy = trim($_POST['cancellation_policy'] ?? '');
            // 1500 char ceiling so it stays readable on the booking page
            if (mb_strlen($cancellationPolicy) > 1500) {
                $cancellationPolicy = mb_substr($cancellationPolicy, 0, 1500);
            }
            $pdo->prepare('UPDATE practitioners SET about_work = ?, practice_types = ?, service_type = ?, role_icon = ?, accepting_new_clients = ?, cancellation_policy = ? WHERE id = ?')
                ->execute([$aboutWork ?: null, json_encode($practiceTypes), $serviceType, $roleIcon ?: null, $acceptingNew, $cancellationPolicy ?: null, $practId]);
            if ($isWizard) {
                header('Location: /dashboard.php?tour=1');
            } else {
                header('Location: /profile.php?next=' . urlencode($next) . '&about_saved=1');
            }
            exit;
        }

        // ── Timezone ────────────────────────────────────────────
        if ($formType === 'timezone') {
            $tz = trim($_POST['timezone'] ?? 'UTC');
            try { new DateTimeZone($tz); } catch (Exception $e) { $tz = 'UTC'; }
            $pdo->prepare('UPDATE practitioners SET timezone = ? WHERE id = ?')
                ->execute([$tz, $practId]);
            header('Location: /profile.php?next=' . urlencode($next) . '&tz_saved=1');
            exit;
        }

        // ── Highlight colors ────────────────────────────────────
        if ($formType === 'highlight_colors') {
            $c1    = trim($_POST['hl_color_1'] ?? '#f5e24a');
            $c2    = trim($_POST['hl_color_2'] ?? '#f48fb1');
            $c3    = trim($_POST['hl_color_3'] ?? '#64b5f6');
            $hexRe = '/^#[0-9a-fA-F]{6}$/';
            if (!preg_match($hexRe, $c1)) $c1 = '#f5e24a';
            if (!preg_match($hexRe, $c2)) $c2 = '#f48fb1';
            if (!preg_match($hexRe, $c3)) $c3 = '#64b5f6';
            $pdo->prepare('UPDATE practitioners SET hl_color_1 = ?, hl_color_2 = ?, hl_color_3 = ? WHERE id = ?')
                ->execute([$c1, $c2, $c3, $practId]);
            header('Location: /profile.php?next=' . urlencode($next) . '&colors_saved=1');
            exit;
        }

        // ── MFA ─────────────────────────────────────────────────
        if ($formType === 'mfa_enable') {
            require_once __DIR__ . '/includes/totp.php';
            $secret = trim($_POST['mfa_pending_secret'] ?? '');
            $code   = preg_replace('/\D/', '', $_POST['mfa_code'] ?? '');
            // Validate secret format (base32, 32 chars)
            if (!preg_match('/^[A-Z2-7]{32}$/', $secret)) {
                $profileError = 'Invalid setup state. Please start over.';
            } elseif (!totp_verify($secret, $code)) {
                $profileError = 'Incorrect code. Please check the time on your device and try again.';
            } else {
                $pdo->prepare('UPDATE practitioners SET mfa_secret = ? WHERE id = ?')
                    ->execute([$secret, $practId]);
                header('Location: /profile.php?next=' . urlencode($next) . '&mfa_enabled=1');
                exit;
            }
        }

        if ($formType === 'mfa_disable') {
            require_once __DIR__ . '/includes/totp.php';
            $code = preg_replace('/\D/', '', $_POST['mfa_code'] ?? '');
            $stmt = $pdo->prepare('SELECT mfa_secret FROM practitioners WHERE id = ? LIMIT 1');
            $stmt->execute([$practId]);
            $secret = $stmt->fetchColumn();
            if (!$secret || !totp_verify($secret, $code)) {
                $profileError = 'Incorrect code. MFA was not disabled.';
            } else {
                $pdo->prepare('UPDATE practitioners SET mfa_secret = NULL WHERE id = ?')
                    ->execute([$practId]);
                header('Location: /profile.php?next=' . urlencode($next) . '&mfa_disabled=1');
                exit;
            }
        }

        // ── Identity ────────────────────────────────────────────
        if ($formType === 'identity') {
            $allowedGenders = ['male','female','trans_woman','trans_man','non_binary','genderfluid','prefer_not_to_say'];
            $allowedSexualities = ['straight','gay','lesbian','bisexual','pansexual','asexual','queer','questioning','prefer_not_to_say'];
            $gender      = trim($_POST['gender'] ?? '');
            $sexuality   = trim($_POST['sexuality'] ?? '');
            $dob         = trim($_POST['date_of_birth'] ?? '');
            $location    = trim($_POST['location'] ?? '');
            if (!in_array($gender, $allowedGenders, true)) $gender = null;
            if (!in_array($sexuality, $allowedSexualities, true)) $sexuality = null;
            if ($dob && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) $dob = null;
            if ($location && !preg_match('/^[A-Z]{2}$/', $location)) $location = null;
            $showGender    = isset($_POST['show_gender'])    ? 1 : 0;
            $showSexuality = isset($_POST['show_sexuality']) ? 1 : 0;
            $showAge       = isset($_POST['show_age'])       ? 1 : 0;
            $showLocation  = isset($_POST['show_location'])  ? 1 : 0;
            $pdo->prepare(
                'UPDATE practitioners SET gender=?, sexuality=?, date_of_birth=?, location=?,
                 show_gender=?, show_sexuality=?, show_age=?, show_location=? WHERE id=?'
            )->execute([$gender ?: null, $sexuality ?: null, $dob ?: null, $location ?: null,
                        $showGender, $showSexuality, $showAge, $showLocation, $practId]);
            header('Location: /profile.php?next=' . urlencode($next) . '&identity_saved=1');
            exit;
        }

        // ── Inclusivity ─────────────────────────────────────────
        if ($formType === 'inclusivity') {
            $allowed = ['lgbtqia_affirming','christian_friendly','individual_clients','couples_counseling'];
            $tags    = array_values(array_intersect((array)($_POST['inclusivity_tags'] ?? []), $allowed));
            $pdo->prepare('UPDATE practitioners SET inclusivity_tags=? WHERE id=?')
                ->execute([json_encode($tags), $practId]);
            header('Location: /profile.php?next=' . urlencode($next) . '&inclusivity_saved=1');
            exit;
        }

        // ── Languages ───────────────────────────────────────────
        if ($formType === 'languages') {
            $langs = array_values(array_filter(array_map('trim', (array)($_POST['languages'] ?? []))));
            // Whitelist against known ISO 639-1 codes + common names — we just sanitise length/charset
            $langs = array_filter($langs, fn($l) => preg_match('/^[a-zA-Z ()\\/\\-]{2,50}$/', $l));
            $langs = array_values(array_slice($langs, 0, 20));
            $pdo->prepare('UPDATE practitioners SET languages=? WHERE id=?')
                ->execute([json_encode($langs), $practId]);
            header('Location: /profile.php?next=' . urlencode($next) . '&languages_saved=1');
            exit;
        }
    }
}

// Re-fetch after potential update
$stmt = $pdo->prepare('SELECT * FROM practitioners WHERE id = ? LIMIT 1');
$stmt->execute([$practId]);
$practitioner = $stmt->fetch();

$currentName   = $practitioner['display_name'] ?? '';
$hlColor1      = $practitioner['hl_color_1'] ?? '#f5e24a';
$hlColor2      = $practitioner['hl_color_2'] ?? '#f48fb1';
$hlColor3      = $practitioner['hl_color_3'] ?? '#64b5f6';
$aboutWork     = $practitioner['about_work'] ?? '';
$practiceTypes = json_decode($practitioner['practice_types'] ?? '[]', true) ?: [];
$serviceType       = $practitioner['service_type']          ?? 'non_clinical';
$licenseStatus     = $practitioner['license_status']         ?? 'none';  // none|pending|approved|rejected
$acceptingNewClients = (int)($practitioner['accepting_new_clients'] ?? 1);
$timezone      = $practitioner['timezone'] ?? 'UTC';
$hasAvatar     = !empty($practitioner['avatar_path']);
$currentAvatar = $practitioner['avatar_path'] ?? '';
$previewUrl    = (strpos($currentAvatar, '/assets/') === 0) ? $currentAvatar : '';

// Load current availability for this practitioner
$availStmt = $pdo->prepare(
    'SELECT day_of_week, start_time, end_time FROM practitioner_availability
     WHERE practitioner_id = ? ORDER BY day_of_week, start_time'
);
$availStmt->execute([$practId]);
$availSlots = $availStmt->fetchAll();

$exclStmt = $pdo->prepare(
    'SELECT excluded_date FROM practitioner_exclusions
     WHERE practitioner_id = ? AND excluded_date >= CURDATE() ORDER BY excluded_date'
);
$exclStmt->execute([$practId]);
$exclusions = array_column($exclStmt->fetchAll(), 'excluded_date');

$genderField     = $practitioner['gender']           ?? '';
$sexualityField  = $practitioner['sexuality']        ?? '';
$dobField        = $practitioner['date_of_birth']    ?? '';
$locationField   = $practitioner['location']         ?? '';
$showGender      = (int)($practitioner['show_gender']    ?? 0);
$showSexuality   = (int)($practitioner['show_sexuality'] ?? 0);
$showAge         = (int)($practitioner['show_age']       ?? 0);
$showLocation    = (int)($practitioner['show_location']  ?? 0);
$inclusivityTags = json_decode($practitioner['inclusivity_tags'] ?? '[]', true) ?: [];
$languages       = json_decode($practitioner['languages']        ?? '[]', true) ?: [];

$aboutSuccess      = '';
$tzSuccess         = '';
$availSuccess      = '';
$identitySuccess   = '';
$inclusivitySuccess = '';
$languagesSuccess  = '';
if (isset($_GET['colors_saved']))     $colorSuccess      = 'Highlight colors saved.';
if (isset($_GET['about_saved']))      $aboutSuccess      = 'Profile updated.';
if (isset($_GET['tz_saved']))         $tzSuccess         = 'Timezone saved.';
if (isset($_GET['identity_saved']))   $identitySuccess   = 'Identity settings saved.';
if (isset($_GET['inclusivity_saved'])) $inclusivitySuccess = 'Inclusivity settings saved.';
if (isset($_GET['languages_saved']))  $languagesSuccess  = 'Languages saved.';

$appTheme = getAppTheme();
$appLogo  = getAppLogo();

// AI Integration — platform availability
$aiPlatformEnabled = getSetting('ai_enabled', '0') === '1';
$aiAllowedVendors  = json_decode(getSetting('ai_allowed_vendors', '["openai","anthropic","google","cohere"]'), true) ?? [];
$aiAllowedScopes   = json_decode(getSetting('ai_allowed_scopes',  '["summarization","notes","post_session","in_session"]'), true) ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your Profile — Serenity Spaces</title>
  <link rel="stylesheet" href="/assets/css/fonts.css">
  <link rel="stylesheet" href="/assets/css/main.css">
  <script nonce="<?= $GLOBALS['csp_nonce'] ?>" src="/assets/js/qrcode.min.js"></script>
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:           #080910;
      --sidebar-bg:   #060710;
      --surface:      #0f1020;
      --surface2:     #161828;
      --border:       rgba(255,255,255,0.07);
      --border-hover: rgba(255,255,255,0.14);
      --accent:       #7c6af7;
      --accent-dim:   rgba(124,106,247,0.15);
      --accent-glow:  rgba(124,106,247,0.35);
      --text:         #e8e9f0;
      --text-dim:     rgba(232,233,240,0.55);
      --text-muted:   rgba(232,233,240,0.35);
      --danger:       #e05555;
      --success:      #3ecf8e;
      --sidebar-w:    230px;
      --topbar-h:     56px;
      --radius:       10px;
      --font:         'Outfit', sans-serif;
    }

    html, body {
      height: 100%;
      background: var(--bg);
      color: var(--text);
      font-family: var(--font);
      font-size: 14px;
      line-height: 1.5;
      overflow: hidden;
    }

    /* ── Layout ─────────────────────────────────── */
    .prof-wrap {
      display: grid;
      grid-template-columns: var(--sidebar-w) 1fr;
      grid-template-rows: var(--topbar-h) 1fr;
      height: 100vh;
    }

    /* ── Topbar ─────────────────────────────────── */
    .prof-topbar {
      grid-column: 1 / -1;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 24px;
      background: var(--sidebar-bg);
      border-bottom: 1px solid var(--border);
      gap: 16px;
    }
    .prof-brand {
      display: flex;
      align-items: center;
      gap: 10px;
      text-decoration: none;
      color: var(--text);
      font-weight: 600;
      font-size: 15px;
      flex-shrink: 0;
    }
    .prof-brand img { height: 48px; width: auto; }
    .prof-brand-dot {
      width: 6px; height: 6px; border-radius: 50%;
      background: var(--accent);
      flex-shrink: 0;
    }
    .prof-breadcrumb {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
      color: var(--text-dim);
    }
    .prof-breadcrumb a { color: var(--text-dim); text-decoration: none; }
    .prof-breadcrumb a:hover { color: var(--text); }
    .prof-breadcrumb-sep { opacity: 0.4; }
    .prof-breadcrumb-current { color: var(--text); font-weight: 500; }
    .prof-topbar-right {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-shrink: 0;
    }

    /* ── Sidebar ────────────────────────────────── */
    .prof-sidebar {
      background: var(--sidebar-bg);
      border-right: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      overflow-y: auto;
      padding: 20px 0 24px;
    }
    .prof-nav-group {
      padding: 0 12px;
      margin-bottom: 4px;
    }
    .prof-nav-label {
      font-size: 10px;
      font-weight: 600;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--text-muted);
      padding: 12px 8px 6px;
    }
    .prof-nav-item {
      display: flex;
      align-items: center;
      gap: 9px;
      width: 100%;
      padding: 8px 10px;
      border: none;
      background: none;
      color: var(--text-dim);
      font-family: var(--font);
      font-size: 13.5px;
      font-weight: 400;
      text-align: left;
      border-radius: 7px;
      cursor: pointer;
      transition: background 0.15s, color 0.15s;
      margin-bottom: 1px;
    }
    .prof-nav-item svg { flex-shrink: 0; opacity: 0.7; }
    .prof-nav-item:hover { background: rgba(255,255,255,0.04); color: var(--text); }
    .prof-nav-item:hover svg { opacity: 1; }
    .prof-nav-item.active {
      background: var(--accent-dim);
      color: var(--accent);
      font-weight: 500;
    }
    .prof-nav-item.active svg { opacity: 1; }

    /* ── Body ───────────────────────────────────── */
    .prof-body {
      overflow-y: auto;
      padding: 32px 36px;
      display: flex;
      flex-direction: column;
      gap: 0;
    }

    /* ── Sections ───────────────────────────────── */
    .prof-section {
      display: none;
      animation: fadeInUp 0.2s ease both;
    }
    .prof-section.active { display: block; }

    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(8px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    .prof-section-title {
      font-size: 20px;
      font-weight: 600;
      color: var(--text);
      margin-bottom: 4px;
    }
    .prof-section-sub {
      font-size: 13px;
      color: var(--text-dim);
      margin-bottom: 24px;
    }

    /* ── Panel ──────────────────────────────────── */
    .prof-panel {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 24px;
      margin-bottom: 16px;
    }
    .prof-panel-title {
      font-size: 13px;
      font-weight: 600;
      color: var(--text-dim);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: 16px;
    }

    /* ── Setting rows ───────────────────────────── */
    .s-row {
      display: flex;
      align-items: flex-start;
      gap: 16px;
      padding: 16px 0;
      flex-wrap: wrap;
    }
    .s-row:not(:last-child) { border-bottom: 1px solid var(--border); }
    .s-row-label { flex: 1; min-width: 140px; padding-top: 2px; }
    .s-row-label strong {
      display: block;
      font-size: 13.5px;
      font-weight: 500;
      color: var(--text);
      margin-bottom: 2px;
    }
    .s-row-label span {
      font-size: 12px;
      color: var(--text-muted);
    }
    .s-row-controls {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      flex: 1;
      min-width: 0;
      max-width: 100%;
    }

    /* ── Form controls ──────────────────────────── */
    .f-input,
    .prof-panel input[type="text"],
    .prof-panel input[type="password"],
    .prof-panel input[type="date"],
    .prof-panel input[type="time"],
    .prof-panel select,
    .prof-panel textarea {
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 7px;
      color: var(--text);
      font-family: var(--font);
      font-size: 13.5px;
      padding: 8px 12px;
      outline: none;
      transition: border-color 0.15s;
      width: 100%;
    }
    .prof-panel input[type="text"]:focus,
    .prof-panel input[type="password"]:focus,
    .prof-panel input[type="date"]:focus,
    .prof-panel input[type="time"]:focus,
    .prof-panel select:focus,
    .prof-panel textarea:focus {
      border-color: var(--accent);
    }
    .prof-panel textarea { resize: vertical; min-height: 100px; }
    .prof-panel select option { background: #1a1b2e; }

    .form-row { margin-bottom: 18px; }
    .form-row label {
      display: block;
      font-size: 12px;
      font-weight: 600;
      color: var(--text-dim);
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: 6px;
    }

    /* ── Buttons ────────────────────────────────── */
    .btn-c {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 18px;
      border-radius: 7px;
      border: none;
      font-family: var(--font);
      font-size: 13.5px;
      font-weight: 500;
      cursor: pointer;
      text-decoration: none;
      transition: opacity 0.15s, background 0.15s;
    }
    .btn-c:hover { opacity: 0.88; }
    .btn-c-primary { background: var(--accent); color: #fff; }
    .btn-c-ghost {
      background: rgba(255,255,255,0.06);
      border: 1px solid var(--border);
      color: var(--text-dim);
    }
    .btn-c-ghost:hover { color: var(--text); }
    .btn-c-danger { background: rgba(224,85,85,0.15); color: var(--danger); border: 1px solid rgba(224,85,85,0.25); }
    .btn-c-sm { padding: 6px 14px; font-size: 13px; }

    /* ── Alert ──────────────────────────────────── */
    .prof-alert {
      padding: 10px 14px;
      border-radius: 7px;
      font-size: 13px;
      margin-bottom: 16px;
    }
    .prof-alert-success { background: rgba(62,207,142,0.12); color: var(--success); border: 1px solid rgba(62,207,142,0.2); }
    .prof-alert-error   { background: rgba(224,85,85,0.12);  color: var(--danger);  border: 1px solid rgba(224,85,85,0.2); }

    /* ── Toggle switch ──────────────────────────── */
    .toggle-switch { display: flex; align-items: center; cursor: pointer; gap: 8px; flex-shrink: 0; }
    .toggle-switch input { display: none; }
    .toggle-track {
      width: 36px; height: 20px;
      background: rgba(255,255,255,0.1);
      border-radius: 10px;
      position: relative;
      transition: background 0.2s;
      flex-shrink: 0;
    }
    .toggle-track::after {
      content: '';
      position: absolute;
      width: 14px; height: 14px;
      top: 3px; left: 3px;
      background: #fff;
      border-radius: 50%;
      transition: left 0.2s;
    }
    .toggle-switch input:checked + .toggle-track { background: var(--accent); }
    .toggle-switch input:checked + .toggle-track::after { left: 19px; }
    .toggle-label { font-size: 12px; color: var(--text-dim); white-space: nowrap; }

    /* ── References & Quotes ── */
    .ref-tab {
      background: transparent; border: none; border-bottom: 2px solid transparent;
      color: var(--text-muted); font-family: inherit; font-size: 12.5px; font-weight: 500;
      padding: 8px 14px; cursor: pointer; transition: color 0.15s, border-color 0.15s;
      margin-bottom: -1px;
    }
    .ref-tab:hover { color: var(--text); }
    .ref-tab.active { color: var(--accent); border-bottom-color: var(--accent); font-weight: 600; }
    .ref-cat-chip {
      font-size: 11.5px; font-weight: 500;
      padding: 4px 10px; border-radius: 999px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,0.04);
      color: var(--text-muted);
      cursor: pointer; user-select: none;
      transition: color 0.15s, border-color 0.15s, background 0.15s;
    }
    .ref-cat-chip:hover { color: var(--text); border-color: rgba(124,106,247,0.35); }
    .ref-cat-chip.active {
      background: rgba(124,106,247,0.15); color: #c5b8ff;
      border-color: rgba(124,106,247,0.4);
    }
    .ref-cat-chip .count {
      font-family: 'Outfit Mono', 'SF Mono', Consolas, monospace;
      font-size: 10px; opacity: 0.6; margin-left: 6px;
    }
    .ref-card {
      background: rgba(255,255,255,0.025);
      border: 1px solid var(--border);
      border-left: 3px solid rgba(124,106,247,0.3);
      border-radius: 10px;
      padding: 14px 16px;
      transition: border-color 0.15s;
    }
    .ref-card:hover { border-left-color: var(--accent); }
    .ref-card-body {
      font-size: 13.5px; color: var(--text); line-height: 1.65;
      margin-bottom: 8px; white-space: pre-line;
    }
    .ref-card-attribution {
      font-size: 11.5px; color: var(--text-muted);
      font-style: italic;
    }
    .ref-card-meta {
      display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
      margin-top: 10px; padding-top: 10px;
      border-top: 1px solid var(--border);
    }
    .ref-card-cat {
      font-size: 10px; font-weight: 700; letter-spacing: 0.5px;
      padding: 2px 8px; border-radius: 999px;
      background: rgba(124,106,247,0.12); color: #c5b8ff;
      border: 1px solid rgba(124,106,247,0.25);
      text-transform: uppercase;
    }
    .ref-card-tag {
      font-size: 10.5px; color: var(--text-muted);
      padding: 1px 8px; border-radius: 4px;
      background: rgba(255,255,255,0.04);
      border: 1px solid var(--border);
    }
    .ref-card-actions { margin-left: auto; display: flex; gap: 6px; }
    .ref-card-actions button {
      font-size: 11.5px; padding: 4px 10px;
      background: transparent; border: 1px solid var(--border);
      border-radius: 6px; color: var(--text-muted);
      cursor: pointer; font-family: inherit;
      transition: color 0.15s, border-color 0.15s;
    }
    .ref-card-actions button:hover { color: var(--text); border-color: var(--accent); }
    .ref-star-btn {
      cursor: pointer; font-size: 18px; line-height: 1;
      background: transparent; border: none; padding: 0;
      color: var(--text-dim); transition: color 0.15s;
    }
    .ref-star-btn:hover { color: var(--amber); }
    .ref-star-btn.starred { color: var(--amber); }

    /* ── Payment gateway Basic / Advanced tabs ── */
    .pay-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 14px; }
    .pay-tab {
      background: transparent; border: none; border-bottom: 2px solid transparent;
      color: var(--text-muted); font-family: inherit; font-size: 12.5px; font-weight: 500;
      padding: 8px 14px; cursor: pointer; transition: color 0.15s, border-color 0.15s;
      margin-bottom: -1px;
    }
    .pay-tab:hover { color: var(--text); }
    .pay-tab.active { color: var(--accent); border-bottom-color: var(--accent); font-weight: 600; }
    .pay-tab-pane { display: none; }
    .pay-tab-pane.active { display: block; }
    .pay-help {
      font-size: 11.5px; color: var(--text-muted); line-height: 1.55;
      margin: 0; padding: 8px 0;
    }
    .pay-webhook-strip {
      background: rgba(124,106,247,0.06);
      border: 1px solid rgba(124,106,247,0.18);
      border-radius: 8px; padding: 12px 14px;
      margin-top: 4px;
    }
    .pay-webhook-label {
      font-family: 'Outfit Mono', 'SF Mono', Consolas, monospace;
      font-size: 10px; letter-spacing: 1px; text-transform: uppercase;
      color: rgba(197,184,255,0.85); margin-bottom: 6px;
    }
    .pay-webhook-row {
      display: flex; gap: 8px; align-items: stretch;
    }
    .pay-webhook-url {
      flex: 1; font-family: 'Outfit Mono', 'SF Mono', Consolas, monospace;
      font-size: 11.5px; color: var(--text);
      background: rgba(0,0,0,0.25);
    }
    .pay-copy-btn { white-space: nowrap; flex-shrink: 0; }
    .pay-copy-btn.copied { color: var(--green); border-color: var(--green); }
    .pay-instructions {
      background: rgba(255,255,255,0.02);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 0;
      margin-top: 4px;
    }
    .pay-instructions summary {
      padding: 10px 14px; cursor: pointer;
      font-size: 12.5px; font-weight: 600; color: var(--text-muted);
      list-style: none; user-select: none;
    }
    .pay-instructions summary::-webkit-details-marker { display: none; }
    .pay-instructions[open] summary { color: var(--text); border-bottom: 1px solid var(--border); }
    .pay-instructions ol {
      padding: 12px 14px 4px 32px;
      font-size: 12.5px; line-height: 1.65;
      color: var(--text-muted);
    }
    .pay-instructions ol li { margin-bottom: 6px; }
    .pay-instructions ol code,
    .pay-instructions p code {
      background: rgba(124,106,247,0.10);
      color: rgba(197,184,255,0.95);
      padding: 1px 5px; border-radius: 3px;
      font-size: 11px;
    }
    .pay-instructions p { padding: 0 14px 12px; }

    /* ── Avatar ─────────────────────────────────── */
    .avatar-wrap {
      display: flex;
      align-items: center;
      gap: 24px;
      flex-wrap: wrap;
      margin-bottom: 20px;
    }
    .avatar-ring {
      width: 88px; height: 88px;
      border-radius: 50%;
      border: 2px solid var(--accent);
      object-fit: cover;
      flex-shrink: 0;
    }
    .avatar-placeholder {
      width: 88px; height: 88px;
      border-radius: 50%;
      background: var(--surface2);
      border: 2px dashed var(--border-hover);
      display: flex; align-items: center; justify-content: center;
      font-size: 28px;
      flex-shrink: 0;
    }
    .avatar-meta { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

    /* ── Identity icons ─────────────────────────── */
    .identity-icon {
      width: 20px; height: 20px;
      vertical-align: middle;
      margin-right: 6px;
      opacity: 0.85;
    }
    .identity-vis {
      display: flex; align-items: center; gap: 6px;
      font-size: 12px; color: var(--text-dim);
    }

    /* ── Checkboxes grid ────────────────────────── */
    .check-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: 8px;
      margin-top: 4px;
    }
    .check-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 7px;
      cursor: pointer;
      transition: border-color 0.15s;
      font-size: 13px;
      color: var(--text-dim);
    }
    .check-item:hover { border-color: var(--border-hover); color: var(--text); }
    .check-item input { accent-color: var(--accent); width: 14px; height: 14px; flex-shrink: 0; }
    .check-item input:checked ~ span { color: var(--text); }
    .check-item-locked { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
    .check-item-locked:hover { border-color: var(--border); }

    /* ── Schedule ───────────────────────────────── */
    .sched-row {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 0;
      border-bottom: 1px solid var(--border);
    }
    .sched-row:last-child { border-bottom: none; }
    .sched-day-name {
      width: 90px;
      font-size: 13px;
      font-weight: 500;
      color: var(--text-dim);
      flex-shrink: 0;
    }
    .sched-times {
      display: flex;
      align-items: center;
      gap: 8px;
      flex: 1;
    }
    .sched-times input { width: 110px; }
    .sched-sep { color: var(--text-muted); font-size: 12px; }
    .sched-row input[type="checkbox"] { accent-color: var(--accent); width: 16px; height: 16px; cursor: pointer; flex-shrink: 0; }

    /* ── Tags ───────────────────────────────────── */
    .tag-list { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; min-height: 32px; }
    .tag-pill {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 4px 10px;
      background: var(--accent-dim);
      border: 1px solid rgba(124,106,247,0.3);
      border-radius: 20px;
      font-size: 12.5px;
      color: var(--text);
    }
    .tag-pill button {
      background: none; border: none; color: var(--text-dim);
      cursor: pointer; font-size: 14px; line-height: 1; padding: 0;
    }
    .tag-pill button:hover { color: var(--danger); }

    /* ── Date exclusion tags ────────────────────── */
    .excl-tag {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 4px 10px;
      background: var(--surface2);
      border: 1px solid var(--border);
      border-radius: 6px;
      font-size: 12px;
      color: var(--text-dim);
    }
    .excl-tag button {
      background: none; border: none; color: var(--text-muted);
      cursor: pointer; font-size: 14px; line-height: 1; padding: 0;
    }
    .excl-tag button:hover { color: var(--danger); }

    /* ── Color swatches ─────────────────────────── */
    .color-row {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 14px 0;
    }
    .color-row:not(:last-child) { border-bottom: 1px solid var(--border); }
    .color-meta { flex: 1; }
    .color-meta strong { display: block; font-size: 13.5px; font-weight: 500; color: var(--text); margin-bottom: 2px; }
    .color-meta span { font-size: 12px; color: var(--text-muted); }

    /* ── Section divider ────────────────────────── */
    .s-divider {
      border: none;
      border-top: 1px solid var(--border);
      margin: 20px 0;
    }

    /* ── Save row ───────────────────────────────── */
    .save-row {
      display: flex;
      align-items: center;
      gap: 12px;
      padding-top: 20px;
      border-top: 1px solid var(--border);
      margin-top: 20px;
    }
  </style>
</head>
<body style="overflow:hidden;">
<?php renderBanners('profile'); ?>

<?php if ($isWizard): ?>
<!-- ═══════════════════ PRACTITIONER SETUP WIZARD ═══════════════════ -->
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);padding:24px;">
<div style="width:100%;max-width:520px;">

  <!-- Brand -->
  <div style="text-align:center;margin-bottom:28px;">
    <?php if ($appLogo): ?>
      <img src="<?= htmlspecialchars($appLogo) ?>" alt="Logo" style="max-height:38px;width:auto;margin-bottom:12px;">
    <?php else: ?>
      <div style="font-size:18px;font-weight:700;color:var(--accent);letter-spacing:-.3px;margin-bottom:12px;">Serenity Spaces</div>
    <?php endif; ?>
    <div style="font-size:13px;color:var(--text-muted);">Set up your practitioner profile</div>
  </div>

  <!-- Step progress -->
  <div style="display:flex;align-items:center;gap:0;margin-bottom:28px;">
    <?php foreach ([1 => 'Your Details', 2 => 'Your Practice'] as $n => $label): ?>
      <div style="flex:1;text-align:center;">
        <div style="width:28px;height:28px;border-radius:50%;margin:0 auto 5px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;
          <?= $n < $wizardStep ? 'background:var(--accent);color:#fff;' : ($n === $wizardStep ? 'background:var(--accent);color:#fff;box-shadow:0 0 0 3px rgba(124,106,247,.25);' : 'background:var(--surface2);color:var(--text-muted);border:1px solid var(--border);') ?>">
          <?= $n < $wizardStep ? '✓' : $n ?>
        </div>
        <div style="font-size:11px;color:<?= $n === $wizardStep ? 'var(--text)' : 'var(--text-muted)' ?>;font-weight:<?= $n === $wizardStep ? '600' : '400' ?>;"><?= $label ?></div>
      </div>
      <?php if ($n < 2): ?>
        <div style="flex:none;width:40px;height:1px;background:<?= $wizardStep > $n ? 'var(--accent)' : 'var(--border)' ?>;margin-bottom:18px;"></div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <!-- Card -->
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:30px 32px;">

    <?php if ($profileError): ?>
      <div class="alert alert-error" style="margin-bottom:18px;"><?= htmlspecialchars($profileError) ?></div>
    <?php endif; ?>

    <?php if ($wizardStep === 1): ?>
    <!-- ── Step 1: Name + Photo ── -->
    <h2 style="font-size:18px;font-weight:700;color:var(--text);margin:0 0 4px;">Welcome — let's get you set up</h2>
    <p style="font-size:13px;color:var(--text-muted);margin:0 0 22px;line-height:1.5;">
      This is how you'll appear to clients. You can update everything later from your profile.
    </p>
    <form method="post" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="form_type" value="profile">

      <!-- Avatar upload -->
      <div style="display:flex;flex-direction:column;align-items:center;margin-bottom:22px;gap:12px;">
        <div id="wiz-avatar-wrap" style="width:88px;height:88px;border-radius:50%;background:var(--surface2);border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;overflow:hidden;cursor:pointer;position:relative;transition:border-color .2s;">
          <?php if ($previewUrl): ?>
            <img id="wiz-avatar-preview" src="<?= htmlspecialchars($previewUrl) ?>" style="width:100%;height:100%;object-fit:cover;">
          <?php else: ?>
            <img id="wiz-avatar-preview" src="" style="width:100%;height:100%;object-fit:cover;display:none;">
            <svg id="wiz-avatar-placeholder" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28" style="color:var(--text-muted);"><circle cx="16" cy="12" r="5"/><path d="M4 28c0-6.6 5.4-12 12-12s12 5.4 12 12"/></svg>
          <?php endif; ?>
        </div>
        <input type="file" id="wiz-avatar-file" name="avatar_upload" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
        <div>
          <button type="button" id="wiz-avatar-upload-btn"
                  style="background:transparent;border:1px solid var(--border);border-radius:7px;padding:6px 14px;font-size:12px;color:var(--text-muted);cursor:pointer;font-family:inherit;">
            <?= $previewUrl ? 'Change photo' : 'Upload photo' ?>
          </button>
          <div style="font-size:11px;color:var(--text-muted);margin-top:5px;text-align:center;">JPEG, PNG, GIF or WEBP · max 5 MB</div>
        </div>
      </div>

      <!-- Display name -->
      <div class="form-group">
        <label for="wiz-name">Your name</label>
        <input type="text" id="wiz-name" name="display_name"
               value="<?= htmlspecialchars($_POST['display_name'] ?? $practitioner['display_name'] ?? '') ?>"
               placeholder="How you appear to clients" required autofocus>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:13px;font-size:15px;margin-top:4px;">
        Continue →
      </button>
    </form>

    <?php elseif ($wizardStep === 2): ?>
    <!-- ── Step 2: About + Practice Types ── -->
    <h2 style="font-size:18px;font-weight:700;color:var(--text);margin:0 0 4px;">Tell clients about your practice</h2>
    <p style="font-size:13px;color:var(--text-muted);margin:0 0 22px;line-height:1.5;">
      This appears on your public profile. Everything here is optional — you can fill it in later.
    </p>
    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="form_type" value="about_practice">

      <div class="form-group">
        <label for="wiz-about">About your work</label>
        <textarea id="wiz-about" name="about_work" rows="3"
                  placeholder="A sentence or two about your approach…"
                  style="width:100%;box-sizing:border-box;resize:vertical;"><?= htmlspecialchars($practitioner['about_work'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label style="margin-bottom:10px;display:block;">Areas of focus <span style="color:var(--text-muted);font-weight:400;">(select all that apply)</span></label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:7px;">
          <?php
          $practiceOptions = [
            'life_coaching'          => 'Life Coaching',
            'philosophical_counseling'=> 'Philosophical Counseling',
            'peer_support'           => 'Peer Support',
            'mindfulness'            => 'Mindfulness',
            'trauma_informed'        => 'Trauma-Informed',
            'grief_support'          => 'Grief Support',
            'addiction_recovery'     => 'Addiction Recovery',
            'pastoral_care'          => 'Pastoral Counseling',
            'spiritual_direction'    => 'Spiritual Direction',
          ];
          $currentTypes = json_decode($practitioner['practice_types'] ?? '[]', true) ?: [];
          foreach ($practiceOptions as $val => $label):
            $checked = in_array($val, $currentTypes, true) ? 'checked' : '';
          ?>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text-muted);padding:6px 10px;border:1px solid var(--border);border-radius:7px;background:var(--surface2);user-select:none;">
            <input type="checkbox" name="practice_types[]" value="<?= $val ?>" <?= $checked ?>
                   style="accent-color:var(--accent);width:14px;height:14px;flex-shrink:0;">
            <?= $label ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-group">
        <label for="wiz-accepting">
          <input type="checkbox" name="accepting_new_clients" id="wiz-accepting" value="1"
                 <?= $practitioner['accepting_new_clients'] ? 'checked' : '' ?>
                 style="accent-color:var(--accent);width:14px;height:14px;vertical-align:middle;margin-right:6px;">
          I am accepting new clients
        </label>
      </div>

      <div style="display:flex;gap:10px;margin-top:4px;">
        <a href="/profile.php?wizard=1" style="flex:0 0 auto;display:flex;align-items:center;justify-content:center;padding:13px 18px;border:1px solid var(--border);border-radius:10px;color:var(--text-muted);text-decoration:none;font-size:14px;font-family:inherit;">
          ← Back
        </a>
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:13px;font-size:15px;">
          Go to Dashboard →
        </button>
      </div>
      <div style="text-align:center;margin-top:10px;">
        <a href="/dashboard.php" style="font-size:12px;color:var(--text-muted);text-decoration:none;">Skip for now</a>
      </div>
    </form>
    <?php endif; ?>

  </div>
</div>
</div>
<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
function wizPreviewAvatar(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const img = document.getElementById('wiz-avatar-preview');
    const ph  = document.getElementById('wiz-avatar-placeholder');
    img.src   = e.target.result;
    img.style.display = 'block';
    if (ph) ph.style.display = 'none';
    document.getElementById('wiz-avatar-wrap').style.borderStyle = 'solid';
    document.getElementById('wiz-avatar-wrap').style.borderColor = 'var(--accent)';
  };
  reader.readAsDataURL(input.files[0]);
}
document.getElementById('wiz-avatar-wrap').addEventListener('click', () => document.getElementById('wiz-avatar-file').click());
document.getElementById('wiz-avatar-file').addEventListener('change', function() { wizPreviewAvatar(this); });
document.getElementById('wiz-avatar-upload-btn').addEventListener('click', () => document.getElementById('wiz-avatar-file').click());
</script>
<?php else: ?>
<div class="prof-wrap">

  <!-- ── Topbar ── -->
  <header class="prof-topbar">
    <div style="display:flex;align-items:center;gap:20px;">
      <?php if ($appLogo): ?>
        <a href="/dashboard.php" class="prof-brand"><img src="<?= htmlspecialchars($appLogo) ?>" alt="Logo"></a>
      <?php else: ?>
        <a href="/dashboard.php" class="prof-brand">
          <span class="prof-brand-dot"></span>Serenity Spaces
        </a>
      <?php endif; ?>
      <nav class="prof-breadcrumb">
        <a href="/dashboard.php">Dashboard</a>
        <span class="prof-breadcrumb-sep">/</span>
        <span class="prof-breadcrumb-current" id="bc-section">Profile</span>
      </nav>
    </div>
    <div class="prof-topbar-right">
      <a href="/dashboard.php" class="btn-c btn-c-ghost btn-c-sm">← Dashboard</a>
    </div>
  </header>

  <!-- ── Sidebar ── -->
  <nav class="prof-sidebar" aria-label="Profile sections">

    <div class="prof-nav-group">
      <div class="prof-nav-label">Profile</div>
      <button class="prof-nav-item" data-section="profile" data-label="Display &amp; Photo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
        Display &amp; Photo
      </button>
      <button class="prof-nav-item" data-section="identity" data-label="Identity">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.168-.789 3.376 3.376 0 016.338 0z"/></svg>
        Identity
      </button>
      <button class="prof-nav-item" data-section="inclusivity" data-label="Inclusivity &amp; Languages">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
        Inclusivity &amp; Languages
      </button>
      <button class="prof-nav-item" data-section="about" data-label="About &amp; Practice">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
        About &amp; Practice
      </button>
    </div>

    <div class="prof-nav-group">
      <div class="prof-nav-label">Schedule</div>
      <button class="prof-nav-item" data-section="availability" data-label="Availability">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
        Availability
      </button>
    </div>

    <div class="prof-nav-group">
      <div class="prof-nav-label">Payments</div>
      <button class="prof-nav-item" data-section="payments" data-label="Rates &amp; Gateways">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg>
        Rates &amp; Gateways
      </button>
      <button class="prof-nav-item" data-section="intake" data-label="Intake Forms">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/></svg>
        Intake Forms
      </button>
    </div>

    <div class="prof-nav-group">
      <div class="prof-nav-label">Account</div>
      <button class="prof-nav-item" data-section="security" data-label="Security">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
        Security
      </button>
      <button class="prof-nav-item" data-section="colors" data-label="Highlight Colors">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M4.098 19.902a3.75 3.75 0 005.304 0l6.401-6.402M6.75 21A3.75 3.75 0 013 17.25V4.125C3 3.504 3.504 3 4.125 3h5.25c.621 0 1.125.504 1.125 1.125v4.072M6.75 21a3.75 3.75 0 003.75-3.75V8.197M6.75 21h13.125c.621 0 1.125-.504 1.125-1.125v-5.25c0-.621-.504-1.125-1.125-1.125h-4.072M10.5 8.197l2.88-2.88c.438-.439 1.15-.439 1.59 0l3.712 3.713c.44.44.44 1.152 0 1.59l-2.879 2.88M6.75 17.25h.008v.008H6.75v-.008z"/></svg>
        Highlight Colors
      </button>
    </div>

    <?php if ($aiPlatformEnabled): ?>
    <div class="prof-nav-group">
      <div class="prof-nav-label">AI</div>
      <button class="prof-nav-item" data-section="ai-integration" data-label="AI Integration">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z"/></svg>
        AI Integration
      </button>
    </div>
    <?php endif; ?>

    <div class="prof-nav-group">
      <div class="prof-nav-label">Tools</div>
      <button class="prof-nav-item" data-section="media-recommendations" data-label="Media Recommendations">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
        Media Recommendations
      </button>
      <button class="prof-nav-item" data-section="references" data-label="References &amp; Quotes">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        References &amp; Quotes
      </button>
    </div>

  </nav>

  <!-- ── Body ── -->
  <main class="prof-body">

    <?php if ($profileError): ?>
      <div class="prof-alert prof-alert-error" style="margin-bottom:20px;"><?= htmlspecialchars($profileError) ?></div>
    <?php endif; ?>

    <!-- ── Display Name & Photo ── -->
    <div class="prof-section" id="section-profile">
      <div class="prof-section-title">Display Name &amp; Photo</div>
      <div class="prof-section-sub">How you appear to clients in sessions and the directory.</div>

      <div class="prof-panel">
        <?php if ($profileError): ?>
          <div class="prof-alert prof-alert-error"><?= htmlspecialchars($profileError) ?></div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="form_type" value="profile">

          <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;margin-bottom:4px;">
            <!-- Avatar col -->
            <div style="display:flex;flex-direction:column;align-items:center;gap:10px;flex-shrink:0;">
              <?php if ($previewUrl): ?>
                <img src="<?= htmlspecialchars($previewUrl) ?>" id="upload-preview" class="avatar-ring" alt="Your photo">
              <?php else: ?>
                <div id="upload-preview-wrap" style="display:none;">
                  <img id="upload-preview" class="avatar-ring" alt="Your photo">
                </div>
                <div class="avatar-placeholder" id="avatar-placeholder">👤</div>
              <?php endif; ?>
              <label class="btn-c btn-c-ghost btn-c-sm" style="cursor:pointer;display:inline-flex;">
                <?= $hasAvatar ? 'Change photo' : 'Upload photo' ?>
                <input type="file" name="avatar_upload" id="avatar_upload"
                       accept="image/jpeg,image/png,image/gif,image/webp"
                       style="display:none;">
              </label>
              <div class="avatar-meta">JPEG, PNG, GIF or WEBP<br>max 5 MB</div>
            </div>
            <!-- Name col -->
            <div style="flex:1;min-width:180px;max-width:320px;padding-top:4px;">
              <label for="display_name" style="display:block;font-size:12px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;">Display name</label>
              <input type="text" id="display_name" name="display_name"
                     value="<?= htmlspecialchars($currentName) ?>"
                     placeholder="How clients will see you" required autofocus
                     style="width:100%;">
            </div>
          </div>

          <div class="save-row">
            <button type="submit" class="btn-c btn-c-primary">Save Profile</button>
            <?php if ($next !== '/dashboard.php'): ?>
              <a href="<?= htmlspecialchars($next) ?>" class="btn-c btn-c-ghost">Skip for now</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- ── About & Practice ── -->
    <div class="prof-section" id="section-about">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:6px;">
        <div>
          <div class="prof-section-title" style="margin-bottom:0;">About &amp; Practice</div>
          <div class="prof-section-sub" style="margin-top:4px;">Your public bio, service type, and practice specialties.</div>
        </div>
        <?php
          // "Preview as visitor" — opens the public directory anchored on this
          // practitioner's card so the practitioner sees exactly what clients see.
          // Falls back to /book.php?practitioner_id=… if the directory anchor
          // lookup is unavailable.
        ?>
        <a href="/?browse=1#prac-<?= (int)$practId ?>" target="_blank" rel="noopener"
           style="flex-shrink:0;font-size:12px;font-weight:500;padding:7px 14px;border-radius:7px;border:1px solid rgba(124,106,247,0.35);background:rgba(124,106,247,0.08);color:#c5b8ff;text-decoration:none;white-space:nowrap;align-self:center;">
          ↗ Preview as visitor
        </a>
      </div>

      <div class="prof-panel">
        <?php if ($aboutSuccess): ?>
          <div class="prof-alert prof-alert-success"><?= htmlspecialchars($aboutSuccess) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="form_type" value="about_practice">

          <!-- About My Work -->
          <div style="margin-bottom:24px;">
            <label for="about_work" style="display:block;font-size:12px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;">About My Work</label>
            <textarea id="about_work" name="about_work" rows="6"
                      placeholder="Describe your approach, philosophy, and what clients can expect…"><?= htmlspecialchars($aboutWork) ?></textarea>
            <p style="font-size:12px;color:var(--text-muted);margin-top:5px;">Displayed publicly on your directory card and booking page.</p>
          </div>

          <hr class="s-divider" style="margin:0 0 24px;">

          <!-- Service Type & Licensing -->
          <div style="margin-bottom:24px;">
            <label style="display:block;font-size:12px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:8px;">Are you a Licensed Practitioner?</label>
            <?php if ($licenseStatus === 'approved'): ?>
              <div style="display:flex;align-items:center;gap:8px;background:rgba(80,200,120,0.1);border:1px solid rgba(80,200,120,0.25);border-radius:8px;padding:10px 14px;margin-bottom:12px;">
                <span style="color:#6fd98c;font-size:14px;">✓</span>
                <span style="font-size:13px;color:#6fd98c;font-weight:600;">Credentials verified — licensed status active</span>
              </div>
              <input type="hidden" name="service_type" value="clinical">
            <?php elseif ($licenseStatus === 'pending'): ?>
              <div style="background:rgba(245,200,66,0.1);border:1px solid rgba(245,200,66,0.25);border-radius:8px;padding:10px 14px;margin-bottom:12px;">
                <div style="font-size:13px;color:#f5c842;font-weight:600;margin-bottom:2px;">⏳ Credential submission under review</div>
                <div style="font-size:12px;color:var(--text-muted);">Once verified by an admin, your licensed practice types will be enabled.</div>
              </div>
              <input type="hidden" name="service_type" value="non_clinical">
            <?php elseif ($licenseStatus === 'rejected'): ?>
              <div style="background:rgba(239,83,80,0.08);border:1px solid rgba(239,83,80,0.2);border-radius:8px;padding:10px 14px;margin-bottom:12px;">
                <div style="font-size:13px;color:#ef9a9a;font-weight:600;margin-bottom:2px;">✕ Previous submission was not approved</div>
                <div style="font-size:12px;color:var(--text-muted);">You may resubmit with different or clearer documentation below.</div>
              </div>
              <input type="hidden" name="service_type" value="non_clinical">
            <?php else: ?>
              <div style="display:flex;gap:12px;margin-bottom:12px;">
                <label style="display:flex;align-items:center;gap:7px;cursor:pointer;padding:9px 14px;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:8px;font-size:13px;transition:border-color 0.15s;" id="license-no-lbl">
                  <input type="radio" name="is_licensed_q" value="no" id="licensed-no"
                         <?= $serviceType !== 'clinical' ? 'checked' : '' ?>>
                  No, I am non-clinical
                </label>
                <label style="display:flex;align-items:center;gap:7px;cursor:pointer;padding:9px 14px;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:8px;font-size:13px;transition:border-color 0.15s;" id="license-yes-lbl">
                  <input type="radio" name="is_licensed_q" value="yes" id="licensed-yes"
                         <?= $serviceType === 'clinical' ? 'checked' : '' ?>>
                  Yes, I hold a professional licence
                </label>
              </div>
              <input type="hidden" name="service_type" value="non_clinical">
            <?php endif; ?>
            <p style="font-size:12px;color:var(--text-muted);margin-top:0;">Sets the badge shown on your directory card and clinical disclosures on your booking page.</p>
          </div>

          <!-- License upload panel (shown when "yes" is selected and no prior submission) -->
          <?php
          // Check whether the platform operator has completed required prerequisites
          $licSubmissionsLocked = false;
          try {
              $hostingOk = (bool)$pdo->query("SELECT id FROM dpa_documents WHERE scope='platform' AND type='hosting' LIMIT 1")->fetch();
              $smtpOk    = (bool)$pdo->query("SELECT id FROM dpa_documents WHERE scope='platform' AND type='smtp' LIMIT 1")->fetch();
              $ctrlName  = getSetting('controller_name',  '');
              $ctrlEmail = getSetting('controller_email', '');
              $licSubmissionsLocked = !$hostingOk || !$smtpOk || $ctrlName === '' || $ctrlEmail === '';
          } catch (Throwable $_e) { $licSubmissionsLocked = true; error_log('profile: license submission lock check failed: ' . $_e->getMessage()); }
          ?>
          <?php if ($licenseStatus === 'none' || $licenseStatus === 'rejected'): ?>
          <?php if ($licSubmissionsLocked): ?>
          <div id="license-upload-panel" style="display:none;background:rgba(200,210,255,0.04);border:1px solid rgba(200,210,255,0.1);border-radius:10px;padding:14px 16px;margin-bottom:24px;">
            <div style="font-size:13px;font-weight:600;color:rgba(200,210,255,0.7);margin-bottom:6px;">Licensed Status — Currently Unavailable</div>
            <p style="font-size:12px;color:var(--text-muted);margin:0;line-height:1.5;">
              The platform operator has not yet completed the required compliance setup for accepting licensed practitioner applications. Credential submissions are temporarily unavailable. Please check back later or contact the platform administrator.
            </p>
          </div>
          <?php else: ?>
          <div id="license-upload-panel" style="display:none;background:rgba(124,106,247,0.06);border:1px solid rgba(124,106,247,0.18);border-radius:10px;padding:16px 18px;margin-bottom:24px;">
            <div style="font-size:13px;font-weight:600;margin-bottom:4px;">Upload Credential Document</div>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;line-height:1.5;">
              Upload a scan or photo of your professional licence or registration certificate. PDF, JPEG, PNG or WEBP, up to 10 MB. This will be reviewed by an admin before your licensed status is activated.
            </p>
            <div style="margin-bottom:10px;">
              <input type="file" id="license-file-input" accept=".pdf,.jpg,.jpeg,.png,.webp"
                     style="font-size:13px;color:var(--text);">
            </div>
            <div style="margin-bottom:10px;">
              <textarea id="license-notes" rows="2" maxlength="500"
                        placeholder="Optional: note your licence number, issuing body, or expiry date…"
                        style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:13px;font-family:inherit;resize:vertical;outline:none;box-sizing:border-box;line-height:1.5;"></textarea>
            </div>
            <button type="button" id="license-submit-btn" class="btn-c btn-c-primary" style="margin-top:4px;">
              Submit for Review
            </button>
            <div id="license-submit-msg" style="font-size:12px;margin-top:8px;line-height:1.4;"></div>
          </div>
          <script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
          function toggleLicenseUpload(show) {
            document.getElementById('license-upload-panel').style.display = show ? 'block' : 'none';
          }
          <?php if ($serviceType === 'clinical'): ?>
          toggleLicenseUpload(true);
          <?php endif; ?>

          async function submitLicenseApplication() {
            const file = document.getElementById('license-file-input').files[0];
            if (!file) { document.getElementById('license-submit-msg').textContent = 'Please select a document to upload.'; return; }
            const btn = event.target;
            btn.disabled    = true;
            btn.textContent = 'Submitting…';
            const formData  = new FormData();
            formData.append('action',      'submit');
            formData.append('csrf_token',  '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
            formData.append('document',    file);
            formData.append('notes',       document.getElementById('license-notes').value);
            try {
              const res  = await fetch('/api/license.php', { method: 'POST', body: formData });
              const data = await res.json();
              const msg  = document.getElementById('license-submit-msg');
              if (data.ok) {
                msg.style.color = '#6fd98c';
                msg.textContent = '✓ Submitted for review. Your profile will be updated once approved.';
                document.getElementById('license-upload-panel').innerHTML =
                  '<div style="font-size:13px;color:#f5c842;">⏳ Submission received — pending admin review.</div>';
              } else {
                msg.style.color = '#ef9a9a';
                msg.textContent = data.error || 'Submission failed. Please try again.';
                btn.disabled    = false;
                btn.textContent = 'Submit for Review';
              }
            } catch (e) {
              document.getElementById('license-submit-msg').textContent = 'Connection error. Please try again.';
              btn.disabled    = false;
              btn.textContent = 'Submit for Review';
            }
          }
          document.getElementById('licensed-no')?.addEventListener('change', () => toggleLicenseUpload(false));
          document.getElementById('licensed-yes')?.addEventListener('change', () => toggleLicenseUpload(true));
          document.getElementById('license-submit-btn')?.addEventListener('click', submitLicenseApplication);
          </script>
          <?php endif; // end !$licSubmissionsLocked ?>
          <?php endif; // end $licenseStatus === 'none' || 'rejected' ?>

          <hr class="s-divider" style="margin:0 0 24px;">

          <!-- Practice Types -->
          <div style="margin-bottom:0;">
            <label style="display:block;font-size:12px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Practice Types</label>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">Select all that apply. Shown as tags on your directory card.</p>
            <div class="check-grid">
              <?php
              // Licensed-only types: only available if credentials are approved
              $licensedTypes  = ['licensed_therapy','cbt','dbt'];
              $isLicensed     = $licenseStatus === 'approved';
              $practiceLabels = [
                  'life_coaching'           => 'Life Coaching',
                  'philosophical_counseling'=> 'Philosophical Counseling',
                  'peer_support'            => 'Peer Support',
                  'mindfulness'             => 'Mindfulness',
                  'trauma_informed'         => 'Trauma-Informed',
                  'grief_support'           => 'Grief Support',
                  'addiction_recovery'      => 'Addiction Recovery',
                  'pastoral_care'           => 'Pastoral Counseling',
                  'spiritual_direction'     => 'Spiritual Direction',
                  'licensed_therapy'        => 'Licensed Therapy',
                  'cbt'                     => 'Cognitive Behavioral (CBT)',
                  'dbt'                     => 'Dialectical Behavioral (DBT)',
              ];
              foreach ($practiceLabels as $key => $label):
                $isLicensedType = in_array($key, $licensedTypes, true);
                $locked = $isLicensedType && !$isLicensed;
              ?>
                <label class="check-item<?= $locked ? ' check-item-locked' : '' ?>"
                       <?= $locked ? 'title="Requires verified licensed credentials"' : '' ?>>
                  <input type="checkbox" name="practice_types[]" value="<?= $key ?>"
                         <?= in_array($key, $practiceTypes, true) ? 'checked' : '' ?>
                         <?= $locked ? 'disabled' : '' ?>>
                  <span><?= htmlspecialchars($label) ?><?= $locked ? ' <span style="font-size:10px;opacity:0.5;">🔒</span>' : '' ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <?php if (!$isLicensed): ?>
            <p style="font-size:12px;color:var(--text-muted);margin-top:8px;">Licensed practice types (Therapy, CBT, DBT) require verified credentials. Submit your licence above to unlock them.</p>
            <?php endif; ?>
          </div>

          <hr class="s-divider" style="margin:24px 0;">

          <!-- Role Icon -->
          <div style="margin-bottom:0;" id="role-icon-section">
            <label style="display:block;font-size:12px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Role Icon</label>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;">
              Shown before your name in the session room and when linked with a client. Choose the icon that best represents your practice.
            </p>

            <?php if ($licenseStatus === 'approved'): ?>
              <!-- Licensed: locked to mental-health icon -->
              <div style="display:flex;align-items:center;gap:12px;background:rgba(124,106,247,0.06);border:1px solid rgba(124,106,247,0.18);border-radius:8px;padding:12px 16px;">
                <img src="/assets/role_icons/mental-health.png" style="width:36px;height:36px;object-fit:contain;" alt="Mental Health Professional">
                <div>
                  <div style="font-size:13px;font-weight:600;">Mental Health Professional</div>
                  <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Icon is locked to verified licensed practitioners.</div>
                </div>
              </div>
              <input type="hidden" name="role_icon" value="mental-health">
            <?php else: ?>
              <!-- Non-licensed: dynamic picker based on practice types / inclusivity tags -->
              <div id="role-icon-grid" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:8px;"></div>
              <input type="hidden" name="role_icon" id="role-icon-value" value="<?= htmlspecialchars($practitioner['role_icon'] ?? 'ribbon') ?>">
              <p style="font-size:11px;color:var(--text-muted);margin-top:6px;" id="role-icon-hint">Select your practice types above to see additional icon options.</p>
            <?php endif; ?>
          </div>

          <!-- Accepting new clients toggle -->
          <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-top:1px solid var(--border);margin-top:8px;gap:16px;">
            <div>
              <div style="font-size:13px;font-weight:600;">Accepting New Clients</div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">When off, new clients cannot book with you. Existing clients with prior sessions can still book.</div>
            </div>
            <label class="toggle-switch" style="flex-shrink:0;">
              <input type="checkbox" name="accepting_new_clients" id="accepting-new-clients" <?= $acceptingNewClients ? 'checked' : '' ?>>
              <span class="toggle-track"><span class="toggle-thumb"></span></span>
            </label>
          </div>

          <!-- Cancellation policy -->
          <div style="padding:18px 0;border-top:1px solid var(--border);margin-top:8px;">
            <label for="cancellation_policy" style="display:block;font-size:12px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;">Cancellation Policy</label>
            <p style="font-size:12px;color:var(--text-muted);margin:0 0 10px;line-height:1.55;">
              Shown to clients on the booking page in a collapsible section before they confirm. Clear, plain-language phrasing works best. Up to 1500 characters.
            </p>
            <textarea id="cancellation_policy" name="cancellation_policy" rows="5" maxlength="1500"
                      placeholder="e.g. Sessions can be rescheduled or cancelled up to 24 hours in advance at no charge. Cancellations within 24 hours are charged the full session rate. Reschedules within 24 hours are charged 50%."
                      style="width:100%;box-sizing:border-box;resize:vertical;font-family:inherit;font-size:13px;padding:10px 12px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:8px;color:var(--text);"><?= htmlspecialchars($practitioner['cancellation_policy'] ?? '') ?></textarea>
          </div>

          <div class="save-row">
            <button type="submit" class="btn-c btn-c-primary">Save Profile Info</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Identity ── -->
    <div class="prof-section" id="section-identity">
      <div class="prof-section-title">Identity</div>
      <div class="prof-section-sub">Optional. Control what appears on your public directory card.</div>

      <div class="prof-panel">
        <?php if ($identitySuccess): ?>
          <div class="prof-alert prof-alert-success"><?= htmlspecialchars($identitySuccess) ?></div>
        <?php endif; ?>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px;">This information is entirely optional. Use the visibility toggles to choose what appears publicly.</p>
        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="form_type" value="identity">

          <!-- Gender -->
          <div class="s-row">
            <div class="s-row-label">
              <strong>
                <img src="/assets/icons/gender/<?= $genderField ? htmlspecialchars(str_replace('_','-',$genderField)) : 'male' ?>.svg"
                     id="gender-icon" class="identity-icon" alt="" aria-hidden="true">
                Gender
              </strong>
            </div>
            <div class="s-row-controls">
              <select name="gender" id="gender-select" style="width:180px;">
                <option value="">Prefer not to say</option>
                <option value="male"              <?= $genderField==='male'              ?'selected':'' ?>>Male</option>
                <option value="female"            <?= $genderField==='female'            ?'selected':'' ?>>Female</option>
                <option value="trans_woman"       <?= $genderField==='trans_woman'       ?'selected':'' ?>>MtF (Trans Woman)</option>
                <option value="trans_man"         <?= $genderField==='trans_man'         ?'selected':'' ?>>FtM (Trans Man)</option>
                <option value="non_binary"        <?= $genderField==='non_binary'        ?'selected':'' ?>>Non-binary</option>
                <option value="genderfluid"       <?= $genderField==='genderfluid'       ?'selected':'' ?>>Genderfluid</option>
                <option value="prefer_not_to_say" <?= $genderField==='prefer_not_to_say' ?'selected':'' ?>>Prefer not to say</option>
              </select>
              <label class="toggle-switch">
                <input type="checkbox" name="show_gender" <?= $showGender ? 'checked' : '' ?>>
                <span class="toggle-track"></span>
                <span class="toggle-label">Show publicly</span>
              </label>
            </div>
          </div>

          <!-- Sexuality -->
          <div class="s-row">
            <div class="s-row-label">
              <strong>
                <img src="/assets/icons/sexuality/<?= $sexualityField ? htmlspecialchars(str_replace('_','-',$sexualityField)) : 'prefer-not-to-say' ?>.svg"
                     id="sexuality-icon" class="identity-icon" alt="" aria-hidden="true">
                Sexuality
              </strong>
            </div>
            <div class="s-row-controls">
              <select name="sexuality" id="sexuality-select" style="width:180px;">
                <option value="">Prefer not to say</option>
                <option value="straight"          <?= $sexualityField==='straight'          ?'selected':'' ?>>Straight / Heterosexual</option>
                <option value="gay"               <?= $sexualityField==='gay'               ?'selected':'' ?>>Gay</option>
                <option value="lesbian"           <?= $sexualityField==='lesbian'           ?'selected':'' ?>>Lesbian</option>
                <option value="bisexual"          <?= $sexualityField==='bisexual'          ?'selected':'' ?>>Bisexual</option>
                <option value="pansexual"         <?= $sexualityField==='pansexual'         ?'selected':'' ?>>Pansexual</option>
                <option value="asexual"           <?= $sexualityField==='asexual'           ?'selected':'' ?>>Asexual</option>
                <option value="queer"             <?= $sexualityField==='queer'             ?'selected':'' ?>>Queer</option>
                <option value="questioning"       <?= $sexualityField==='questioning'       ?'selected':'' ?>>Questioning</option>
                <option value="prefer_not_to_say" <?= $sexualityField==='prefer_not_to_say' ?'selected':'' ?>>Prefer not to say</option>
              </select>
              <label class="toggle-switch">
                <input type="checkbox" name="show_sexuality" <?= $showSexuality ? 'checked' : '' ?>>
                <span class="toggle-track"></span>
                <span class="toggle-label">Show publicly</span>
              </label>
            </div>
          </div>

          <!-- Date of Birth -->
          <div class="s-row">
            <div class="s-row-label">
              <strong>Date of Birth</strong>
              <span>Your age may be shown — never your exact date.</span>
            </div>
            <div class="s-row-controls">
              <input type="date" name="date_of_birth" value="<?= htmlspecialchars($dobField) ?>"
                     max="<?= date('Y-m-d') ?>" style="width:170px;">
              <label class="toggle-switch">
                <input type="checkbox" name="show_age" <?= $showAge ? 'checked' : '' ?>>
                <span class="toggle-track"></span>
                <span class="toggle-label">Show age</span>
              </label>
            </div>
          </div>

          <!-- Location -->
          <div class="s-row">
            <div class="s-row-label">
              <strong>
                <span id="location-flag" style="font-size:18px;line-height:1;vertical-align:middle;margin-right:6px;"><?= $locationField ? countryFlag($locationField) : '🌍' ?></span>
                Location
              </strong>
              <span>Country only.</span>
            </div>
            <div class="s-row-controls">
              <select name="location" id="location-select" style="width:190px;">
                <option value="">Not specified</option>
                <?php foreach (getCountryList() as $code => $name): ?>
                  <option value="<?= htmlspecialchars($code) ?>" <?= $locationField===$code?'selected':'' ?>>
                    <?= countryFlag($code) ?> <?= htmlspecialchars($name) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <label class="toggle-switch">
                <input type="checkbox" name="show_location" <?= $showLocation ? 'checked' : '' ?>>
                <span class="toggle-track"></span>
                <span class="toggle-label">Show publicly</span>
              </label>
            </div>
          </div>

          <div class="save-row">
            <button type="submit" class="btn-c btn-c-primary">Save Identity</button>
          </div>
        </form>
      </div>
    </div><!-- /#section-identity -->

    <!-- ── Inclusivity & Languages ── -->
    <div class="prof-section" id="section-inclusivity">
      <div class="prof-section-title">Inclusivity &amp; Languages</div>
      <div class="prof-section-sub">Tags and languages displayed prominently on your directory card.</div>

      <!-- Inclusivity toggles -->
      <div class="prof-panel">
        <?php if ($inclusivitySuccess): ?>
          <div class="prof-alert prof-alert-success"><?= htmlspecialchars($inclusivitySuccess) ?></div>
        <?php endif; ?>
        <div class="prof-panel-title">Inclusivity Tags</div>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px;">Enabled tags appear prominently on your public directory card.</p>
        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="form_type" value="inclusivity">
          <?php
          $inclusivityOptions = [
              'lgbtqia_affirming'  => ['label' => 'LGBTQIA+ Affirming',       'desc' => 'You create a safe, affirming space for LGBTQIA+ individuals.'],
              'christian_friendly' => ['label' => 'Faith-based Friendly',      'desc' => 'You are comfortable integrating faith into sessions.'],
              'individual_clients' => ['label' => 'Accepts Individual Clients','desc' => 'You work with individual clients (not couples/groups only).'],
              'couples_counseling' => ['label' => 'Offers Couples Counseling', 'desc' => 'You offer sessions for couples or partners.'],
          ];
          foreach ($inclusivityOptions as $key => $opt): ?>
            <div class="s-row">
              <div class="s-row-label">
                <strong><?= htmlspecialchars($opt['label']) ?></strong>
                <span><?= htmlspecialchars($opt['desc']) ?></span>
              </div>
              <label class="toggle-switch">
                <input type="checkbox" name="inclusivity_tags[]" value="<?= $key ?>"
                       <?= in_array($key, $inclusivityTags, true) ? 'checked' : '' ?>>
                <span class="toggle-track"></span>
              </label>
            </div>
          <?php endforeach; ?>
          <div class="save-row">
            <button type="submit" class="btn-c btn-c-primary">Save Inclusivity</button>
          </div>
        </form>
      </div>

      <!-- Languages — inside the same prof-section, bug fixed -->
      <div class="prof-panel">
        <?php if ($languagesSuccess): ?>
          <div class="prof-alert prof-alert-success"><?= htmlspecialchars($languagesSuccess) ?></div>
        <?php endif; ?>
        <div class="prof-panel-title">Languages</div>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;">Languages you can conduct sessions in. Shown on your public profile.</p>
        <form method="post" novalidate id="languages-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="form_type" value="languages">
          <div id="lang-tag-list" class="tag-list">
            <?php foreach ($languages as $lang): ?>
              <span class="tag-pill lang-tag">
                <?= htmlspecialchars($lang) ?>
                <input type="hidden" name="languages[]" value="<?= htmlspecialchars($lang) ?>">
                <button type="button">×</button>
              </span>
            <?php endforeach; ?>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:4px;">
            <select id="lang-add-select" style="width:240px;">
              <option value="">Add a language…</option>
              <?php foreach (getLanguageList() as $code => $name): ?>
                <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="button" id="add-lang-tag-btn" class="btn-c btn-c-ghost btn-c-sm">Add</button>
          </div>
          <div class="save-row">
            <button type="submit" class="btn-c btn-c-primary">Save Languages</button>
          </div>
        </form>
      </div>
    </div><!-- /#section-inclusivity -->

    <!-- ── Availability ── -->
    <div class="prof-section" id="section-availability">
      <div class="prof-section-title">Availability</div>
      <div class="prof-section-sub">Set your timezone and weekly availability for client bookings.</div>

      <div class="prof-panel">
        <?php if ($tzSuccess): ?>
          <div class="prof-alert prof-alert-success"><?= htmlspecialchars($tzSuccess) ?></div>
        <?php endif; ?>

        <!-- Timezone -->
        <div class="prof-panel-title">Timezone</div>
        <form method="post" novalidate style="margin-bottom:28px;padding-bottom:28px;border-bottom:1px solid var(--border);">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="form_type" value="timezone">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <select name="timezone" id="pract_timezone" style="flex:1;max-width:340px;">
              <?php
              $tzList = DateTimeZone::listIdentifiers();
              foreach ($tzList as $tzId):
                $sel = ($tzId === $timezone) ? 'selected' : '';
              ?>
                <option value="<?= htmlspecialchars($tzId) ?>" <?= $sel ?>><?= htmlspecialchars($tzId) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-c btn-c-primary btn-c-sm">Save</button>
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:6px;">Used to display your availability times to visiting clients.</div>
        </form>

        <!-- Weekly schedule -->
        <div class="prof-panel-title">Weekly Availability</div>
        <div id="schedule-builder">
          <?php
          $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
          $availByDay = [];
          foreach ($availSlots as $slot) {
              $availByDay[(int)$slot['day_of_week']] = $slot;
          }
          foreach ($days as $dow => $dayName):
            $slot    = $availByDay[$dow] ?? null;
            $checked = $slot !== null;
            $start   = $slot ? substr($slot['start_time'], 0, 5) : '09:00';
            $end     = $slot ? substr($slot['end_time'],   0, 5) : '17:00';
          ?>
          <div class="sched-row schedule-day-row" data-dow="<?= $dow ?>">
            <input type="checkbox" class="sched-day-check" <?= $checked ? 'checked' : '' ?>>
            <span class="sched-day-name"><?= $dayName ?></span>
            <div class="sched-times schedule-day-times" style="<?= !$checked ? 'opacity:0.35;pointer-events:none;' : '' ?>">
              <input type="time" class="sched-start" value="<?= $start ?>" step="900">
              <span class="sched-sep">to</span>
              <input type="time" class="sched-end" value="<?= $end ?>" step="900">
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;align-items:center;gap:12px;margin-top:16px;">
          <button type="button" id="save-schedule-btn" class="btn-c btn-c-primary btn-c-sm">Save Schedule</button>
          <span id="sched-save-msg" style="font-size:13px;color:var(--success);display:none;">Saved</span>
        </div>

        <!-- Date exclusions -->
        <hr class="s-divider">
        <div class="prof-panel-title">Exclude Specific Dates</div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
          <input type="date" id="excl-date-input" min="<?= date('Y-m-d') ?>" style="width:180px;">
          <button type="button" id="add-exclusion-btn" class="btn-c btn-c-ghost btn-c-sm">Add Date</button>
        </div>
        <div id="exclusions-list" style="display:flex;flex-wrap:wrap;gap:8px;">
          <?php foreach ($exclusions as $exDate): ?>
            <span class="excl-tag" data-date="<?= htmlspecialchars($exDate) ?>">
              <?= htmlspecialchars($exDate) ?>
              <button type="button">×</button>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    </div><!-- /#section-availability -->

    <!-- ── Security ── -->
    <div class="prof-section" id="section-security">
      <div class="prof-section-title">Security</div>
      <div class="prof-section-sub">Update your account password.</div>

      <div class="prof-panel" style="max-width:480px;">
        <?php if ($pwSuccess): ?>
          <div class="prof-alert prof-alert-success"><?= htmlspecialchars($pwSuccess) ?></div>
        <?php endif; ?>
        <?php if ($pwError): ?>
          <div class="prof-alert prof-alert-error"><?= htmlspecialchars($pwError) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="form_type" value="password">
          <div class="form-row">
            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password"
                   placeholder="••••••••" required autocomplete="current-password">
          </div>
          <div class="form-row">
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password"
                   placeholder="••••••••" required autocomplete="new-password">
            <?= password_meter('new_password', true) ?>
          </div>
          <div class="form-row" style="margin-bottom:0;">
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password"
                   placeholder="••••••••" required autocomplete="new-password">
          </div>
          <div class="save-row">
            <button type="submit" class="btn-c btn-c-primary">Update Password</button>
          </div>
        </form>
      </div>
    </div><!-- /#section-security -->

    <!-- ── Two-Factor Authentication ── -->
    <?php
    require_once __DIR__ . '/includes/totp.php';
    $mfaEnabled    = !empty($practitioner['mfa_secret']);
    $mfaJustOn     = isset($_GET['mfa_enabled']);
    $mfaJustOff    = isset($_GET['mfa_disabled']);
    $pendingSecret = !$mfaEnabled ? totp_generate_secret() : '';
    if (!$mfaEnabled && $pendingSecret) {
        $mfaQrUri = totp_provisioning_uri($pendingSecret, $practitioner['email'] ?? '');
    }
    ?>
    <div class="prof-section" id="section-mfa">
      <div class="prof-section-title">Two-Factor Authentication</div>
      <div class="prof-section-sub">Add an extra layer of security using an authenticator app (Google Authenticator, Authy, etc.).</div>

      <div class="prof-panel" style="max-width:480px;">
        <?php if ($mfaJustOn): ?>
          <div class="prof-alert prof-alert-success">Two-factor authentication has been enabled.</div>
        <?php endif; ?>
        <?php if ($mfaJustOff): ?>
          <div class="prof-alert prof-alert-success">Two-factor authentication has been disabled.</div>
        <?php endif; ?>
        <?php if ($profileError && in_array($formType ?? '', ['mfa_enable','mfa_disable'])): ?>
          <div class="prof-alert prof-alert-error"><?= htmlspecialchars($profileError) ?></div>
        <?php endif; ?>

        <?php if ($mfaEnabled): ?>
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;">
            <span style="width:10px;height:10px;border-radius:50%;background:#3ecf8e;display:inline-block;flex-shrink:0;"></span>
            <span style="font-size:14px;color:#e0dff5;">Two-factor authentication is <strong>enabled</strong></span>
          </div>
          <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="form_type" value="mfa_disable">
            <div class="form-row">
              <label for="mfa-disable-code">Enter your current 6-digit code to disable MFA</label>
              <input type="text" id="mfa-disable-code" name="mfa_code"
                     inputmode="numeric" pattern="\d{6}" maxlength="6"
                     placeholder="000000" autocomplete="one-time-code"
                     style="max-width:160px;letter-spacing:3px;font-size:18px;">
            </div>
            <div class="save-row">
              <button type="submit" class="btn-c btn-c-ghost" style="color:#e85555;border-color:#e85555;">Disable 2FA</button>
            </div>
          </form>

        <?php else: ?>
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;">
            <span style="width:10px;height:10px;border-radius:50%;background:#888;display:inline-block;flex-shrink:0;"></span>
            <span style="font-size:14px;color:#8f8daa;">Two-factor authentication is <strong>not enabled</strong></span>
          </div>
          <p style="font-size:13px;color:#8f8daa;margin-bottom:18px;line-height:1.6;">
            Scan the QR code below with your authenticator app, then enter the 6-digit code to confirm.
          </p>
          <div style="margin-bottom:18px;text-align:center;">
            <canvas id="mfa-qr-canvas" width="160" height="160" style="border:4px solid #fff;border-radius:8px;"></canvas>
            <div style="font-size:11px;color:#8f8daa;margin-top:8px;">
              Or enter manually: <code style="font-size:12px;letter-spacing:1px;color:#c4b8ff;"><?= htmlspecialchars($pendingSecret) ?></code>
            </div>
          </div>
          <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="form_type" value="mfa_enable">
            <input type="hidden" name="mfa_pending_secret" value="<?= htmlspecialchars($pendingSecret) ?>">
            <div class="form-row">
              <label for="mfa-setup-code">6-digit verification code</label>
              <input type="text" id="mfa-setup-code" name="mfa_code"
                     inputmode="numeric" pattern="\d{6}" maxlength="6"
                     placeholder="000000" autocomplete="one-time-code"
                     style="max-width:160px;letter-spacing:3px;font-size:18px;">
            </div>
            <div class="save-row">
              <button type="submit" class="btn-c btn-c-primary">Enable 2FA</button>
            </div>
          </form>
          <script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
          (function(){
            const uri = <?= json_encode($mfaQrUri ?? '') ?>;
            if (!uri) return;
            // Minimal QR encoder using a CDN-free approach: render the URI as text link fallback
            // For full QR, we use the qrcodejs approach via inline data URI generation
            const canvas  = document.getElementById('mfa-qr-canvas');
            if (!canvas) return;
            const ctx     = canvas.getContext('2d');
            const size    = 160;
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, size, size);
            ctx.fillStyle = '#333';
            ctx.font      = '11px monospace';
            ctx.textAlign = 'center';
            ctx.fillText('Scan with', size/2, 70);
            ctx.fillText('authenticator app', size/2, 86);
            ctx.fillText('or use key below', size/2, 102);
            // qrcode.min.js is loaded in <head> — always available
            if (typeof QRCode !== 'undefined') {
                canvas.getContext('2d').clearRect(0,0,size,size);
                new QRCode(canvas, { text: uri, width: size, height: size, correctLevel: QRCode.CorrectLevel.M });
            }
          })();
          </script>
        <?php endif; ?>
      </div>
    </div><!-- /#section-mfa -->

    <!-- ── Highlight Colors ── -->
    <div class="prof-section" id="section-colors">
      <div class="prof-section-title">Highlight Colors</div>
      <div class="prof-section-sub">Customize the three annotation highlight colors used in session notes.</div>

      <div class="prof-panel" style="max-width:480px;">
        <?php if ($colorSuccess): ?>
          <div class="prof-alert prof-alert-success"><?= htmlspecialchars($colorSuccess) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="form_type" value="highlight_colors">
          <div class="color-row">
            <div class="color-meta">
              <strong>Action Item</strong>
              <span>Tasks, next steps, or follow-up items</span>
            </div>
            <input type="color" name="hl_color_1" value="<?= htmlspecialchars($hlColor1) ?>"
                   style="width:44px;height:36px;border:none;background:none;cursor:pointer;padding:0;flex-shrink:0;">
          </div>
          <div class="color-row">
            <div class="color-meta">
              <strong>Clinical Note</strong>
              <span>Observations, symptoms, or clinical context</span>
            </div>
            <input type="color" name="hl_color_2" value="<?= htmlspecialchars($hlColor2) ?>"
                   style="width:44px;height:36px;border:none;background:none;cursor:pointer;padding:0;flex-shrink:0;">
          </div>
          <div class="color-row">
            <div class="color-meta">
              <strong>Reference</strong>
              <span>Resources, citations, or background information</span>
            </div>
            <input type="color" name="hl_color_3" value="<?= htmlspecialchars($hlColor3) ?>"
                   style="width:44px;height:36px;border:none;background:none;cursor:pointer;padding:0;flex-shrink:0;">
          </div>
          <div class="save-row">
            <button type="submit" class="btn-c btn-c-primary">Save Colors</button>
          </div>
        </form>
      </div>
    </div><!-- /#section-colors -->

    <!-- ── Payments & Rates ── -->
    <div class="prof-section" id="section-payments">
      <div class="prof-section-title">Rates &amp; Payment Gateways</div>
      <div class="prof-section-sub">Configure your session rate and how clients can pay you.</div>

      <div id="payments-loading" style="padding:32px;text-align:center;color:var(--text-muted);">Loading…</div>
      <div id="payments-content" style="display:none;">

        <!-- Rate panel -->
        <div class="prof-panel">
          <div class="prof-panel-title">Session Rate</div>
          <div class="prof-panel-sub">Set your per-session fee. Clients will see this on your booking page.</div>
          <div id="rate-error" class="prof-alert prof-alert-error" style="display:none;margin-bottom:12px;"></div>
          <div id="rate-success" class="prof-alert prof-alert-success" style="display:none;margin-bottom:12px;"></div>

          <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
            <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;cursor:pointer;">
              <input type="checkbox" id="rate-is-free" style="accent-color:var(--accent);width:15px;height:15px;">
              Offer sessions free of charge
            </label>
          </div>

          <div id="rate-amount-wrap" style="display:flex;gap:10px;align-items:flex-end;margin-bottom:20px;">
            <div style="flex:1">
              <label class="form-label">Amount</label>
              <input type="number" id="rate-amount" class="input-field" min="0" step="0.01" placeholder="e.g. 60.00" style="max-width:160px;">
            </div>
            <div>
              <label class="form-label">Currency</label>
              <select id="rate-currency" class="input-field" style="max-width:120px;">
                <option value="GBP">GBP £</option>
                <option value="USD">USD $</option>
                <option value="EUR">EUR €</option>
                <option value="CAD">CAD $</option>
                <option value="AUD">AUD $</option>
                <option value="PHP">PHP ₱</option>
                <option value="NZD">NZD $</option>
                <option value="SGD">SGD $</option>
                <option value="ZAR">ZAR R</option>
                <option value="INR">INR ₹</option>
              </select>
            </div>
          </div>

          <button id="save-rate-btn" class="btn btn-primary" style="font-family:inherit;">Save Rate</button>
        </div>

        <!--
        ════════════════════════════════════════════════════════
        Each gateway panel has Basic / Advanced tabs.

        Basic   = manual flow (current behaviour). Practitioner gets emailed
                  when client indicates payment, manually marks paid.
        Advanced = API keys + webhook signing secret. Provider sends signed
                   webhook events to /api/payment_webhook.php; we auto-mark
                   the booking_payment as paid. No manual confirmation needed.
        ════════════════════════════════════════════════════════ -->

        <!-- PayPal panel -->
        <div class="prof-panel pay-gateway" style="margin-top:20px;" data-gateway="paypal">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <div>
              <div class="prof-panel-title" style="margin-bottom:2px;">PayPal</div>
              <div class="prof-panel-sub" style="margin-bottom:0;">Card and PayPal balance payments. Basic uses PayPal.me; Advanced auto-confirms via REST API webhooks.</div>
            </div>
            <label class="toggle-switch" style="flex-shrink:0;">
              <input type="checkbox" id="gw-paypal-enabled">
              <span class="toggle-track"><span class="toggle-thumb"></span></span>
            </label>
          </div>
          <div id="gw-paypal-fields" style="display:none;margin-top:12px;">
            <div class="pay-tabs" data-target="paypal">
              <button type="button" class="pay-tab active" data-tab="basic">Basic — Manual</button>
              <button type="button" class="pay-tab" data-tab="advanced">Advanced — API + Webhook</button>
            </div>
            <input type="hidden" id="gw-paypal-mode" value="basic">

            <div class="pay-tab-pane active" data-pane="paypal-basic">
              <div style="display:flex;flex-direction:column;gap:12px;">
                <div>
                  <label class="form-label">PayPal Email</label>
                  <input type="email" id="gw-paypal-email" class="input-field" placeholder="you@paypal.com">
                </div>
                <div>
                  <label class="form-label">PayPal.me Username <span style="font-weight:400;opacity:.6;">(optional — for direct link)</span></label>
                  <input type="text" id="gw-paypal-me" class="input-field" placeholder="YourUsername">
                </div>
                <p class="pay-help">Clients click your PayPal.me link or send to your email address. PayPal emails you when funds arrive — log in here and mark the payment as paid manually. No API access needed.</p>
              </div>
            </div>

            <div class="pay-tab-pane" data-pane="paypal-advanced">
              <div style="display:flex;flex-direction:column;gap:12px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                  <div>
                    <label class="form-label">Environment</label>
                    <select id="gw-paypal-env" class="input-field">
                      <option value="live">Live (production)</option>
                      <option value="sandbox">Sandbox (testing)</option>
                    </select>
                  </div>
                  <div>
                    <label class="form-label">Webhook ID <span style="font-weight:400;opacity:.6;">(from PayPal dashboard)</span></label>
                    <input type="text" id="gw-paypal-webhook-id" class="input-field" placeholder="WH-XXXXX">
                  </div>
                </div>
                <div>
                  <label class="form-label">REST API Client ID</label>
                  <input type="text" id="gw-paypal-client-id" class="input-field" placeholder="AeA1Q…">
                </div>
                <div>
                  <label class="form-label">REST API Client Secret <span style="font-weight:400;opacity:.6;">(encrypted — blank keeps existing)</span></label>
                  <input type="password" id="gw-paypal-client-secret" class="input-field" placeholder="EBR-… (or leave blank to keep existing)">
                </div>
                <div class="pay-webhook-strip">
                  <div class="pay-webhook-label">Your Webhook URL</div>
                  <div class="pay-webhook-row">
                    <input type="text" readonly class="input-field pay-webhook-url" id="gw-paypal-webhook-url" value="">
                    <button type="button" class="btn btn-ghost btn-sm pay-copy-btn" data-copy-target="gw-paypal-webhook-url">Copy</button>
                  </div>
                </div>
                <details class="pay-instructions">
                  <summary>How to set up PayPal webhooks</summary>
                  <ol>
                    <li>Go to <strong>developer.paypal.com → Apps &amp; Credentials</strong> and create (or open) a REST API app.</li>
                    <li>Copy the <strong>Client ID</strong> and <strong>Secret</strong> into the fields above.</li>
                    <li>Under that app, open <strong>Webhooks → Add Webhook</strong>.</li>
                    <li>Paste the webhook URL above into the <em>Webhook URL</em> field.</li>
                    <li>Subscribe to events: <code>PAYMENT.CAPTURE.COMPLETED</code>, <code>CHECKOUT.ORDER.APPROVED</code>, <code>PAYMENT.SALE.COMPLETED</code>.</li>
                    <li>Save. PayPal shows a <strong>Webhook ID</strong> (format <code>WH-…</code>) — paste it above and save here.</li>
                    <li>Use <strong>Sandbox</strong> environment to test before flipping to Live.</li>
                  </ol>
                  <p class="pay-help" style="margin-top:8px;color:var(--green);">Routing is automatic — when your client books, we create a real PayPal Orders v2 order with <code>intent: CAPTURE</code> and the booking token in <code>invoice_id</code>. Your client gets PayPal's hosted checkout, approves, and PayPal captures the funds instantly. The webhook fires <code>PAYMENT.CAPTURE.COMPLETED</code>; we verify the signature against PayPal's verify-webhook-signature API and mark the booking paid. Your client gets a confirmation email automatically.</p>
                </details>
              </div>
            </div>
          </div>
        </div>

        <!-- Stripe panel -->
        <div class="prof-panel pay-gateway" style="margin-top:20px;" data-gateway="stripe">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <div>
              <div class="prof-panel-title" style="margin-bottom:2px;">Stripe <span style="font-size:11px;padding:2px 7px;border-radius:4px;background:rgba(62,207,142,0.1);color:#4caf87;border:1px solid rgba(62,207,142,0.25);margin-left:6px;">Includes Apple Pay &amp; Google Pay</span></div>
              <div class="prof-panel-sub" style="margin-bottom:0;">Card payments. Basic = pub/secret keys for manual reconciliation. Advanced = full Stripe Checkout + auto-confirm via webhook.</div>
            </div>
            <label class="toggle-switch" style="flex-shrink:0;">
              <input type="checkbox" id="gw-stripe-enabled">
              <span class="toggle-track"><span class="toggle-thumb"></span></span>
            </label>
          </div>
          <div id="gw-stripe-fields" style="display:none;margin-top:12px;">
            <div class="pay-tabs" data-target="stripe">
              <button type="button" class="pay-tab active" data-tab="basic">Basic — Manual</button>
              <button type="button" class="pay-tab" data-tab="advanced">Advanced — Auto-Confirm</button>
            </div>
            <input type="hidden" id="gw-stripe-mode" value="basic">

            <div class="pay-tab-pane active" data-pane="stripe-basic">
              <div style="display:flex;flex-direction:column;gap:12px;">
                <div>
                  <label class="form-label">Publishable Key</label>
                  <input type="text" id="gw-stripe-pub" class="input-field" placeholder="pk_live_…">
                </div>
                <div>
                  <label class="form-label">Secret Key <span style="font-weight:400;opacity:.6;">(encrypted — blank keeps existing)</span></label>
                  <input type="password" id="gw-stripe-secret" class="input-field" placeholder="sk_live_… (or leave blank to keep existing)">
                </div>
                <p class="pay-help">Stores your keys for use with Stripe's hosted payment links. You'll receive a Stripe email when a charge succeeds and mark the booking paid manually here.</p>
              </div>
            </div>

            <div class="pay-tab-pane" data-pane="stripe-advanced">
              <div style="display:flex;flex-direction:column;gap:12px;">
                <div>
                  <label class="form-label">Publishable Key</label>
                  <input type="text" id="gw-stripe-pub-adv" class="input-field" placeholder="pk_live_…">
                </div>
                <div>
                  <label class="form-label">Secret Key <span style="font-weight:400;opacity:.6;">(encrypted — blank keeps existing)</span></label>
                  <input type="password" id="gw-stripe-secret-adv" class="input-field" placeholder="sk_live_… (or leave blank to keep existing)">
                </div>
                <div>
                  <label class="form-label">Webhook Signing Secret <span style="font-weight:400;opacity:.6;">(<code>whsec_…</code> — encrypted, blank keeps existing)</span></label>
                  <input type="password" id="gw-stripe-webhook-secret" class="input-field" placeholder="whsec_… (or leave blank to keep existing)">
                </div>
                <div class="pay-webhook-strip">
                  <div class="pay-webhook-label">Your Webhook URL</div>
                  <div class="pay-webhook-row">
                    <input type="text" readonly class="input-field pay-webhook-url" id="gw-stripe-webhook-url" value="">
                    <button type="button" class="btn btn-ghost btn-sm pay-copy-btn" data-copy-target="gw-stripe-webhook-url">Copy</button>
                  </div>
                </div>
                <details class="pay-instructions" open>
                  <summary>How to set up Stripe webhooks</summary>
                  <ol>
                    <li>Sign in to <strong>dashboard.stripe.com</strong>.</li>
                    <li>Go to <strong>Developers → API Keys</strong>. Copy your publishable and secret keys above.</li>
                    <li>Go to <strong>Developers → Webhooks → Add endpoint</strong>.</li>
                    <li>Paste the webhook URL above into the <em>Endpoint URL</em> field.</li>
                    <li>Under <em>Events to send</em>, add: <code>checkout.session.completed</code>, <code>payment_intent.succeeded</code>, <code>charge.succeeded</code>.</li>
                    <li>Save. Stripe shows a <strong>Signing secret</strong> (<code>whsec_…</code>) — click <em>Reveal</em>, copy it, paste it above, and save here.</li>
                  </ol>
                  <p class="pay-help" style="margin-top:8px;color:var(--green);">Routing is automatic — when a client books, we create a real Stripe Checkout Session via the API with the booking token in <code>metadata</code>. Stripe sends the signed webhook on success; we verify the signature, look up the booking by token, and mark it paid. Client gets the confirmation email instantly. You don't have to do anything.</p>
                </details>
              </div>
            </div>
          </div>
        </div>

        <!-- Bitcoin panel -->
        <div class="prof-panel pay-gateway" style="margin-top:20px;" data-gateway="btc">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <div>
              <div class="prof-panel-title" style="margin-bottom:2px;">Bitcoin</div>
              <div class="prof-panel-sub" style="margin-bottom:0;">Basic = direct wallet address (manual). Advanced = self-hosted BTCPay Server with API + webhooks.</div>
            </div>
            <label class="toggle-switch" style="flex-shrink:0;">
              <input type="checkbox" id="gw-btc-enabled">
              <span class="toggle-track"><span class="toggle-thumb"></span></span>
            </label>
          </div>
          <div id="gw-btc-fields" style="display:none;margin-top:12px;">
            <div class="pay-tabs" data-target="btc">
              <button type="button" class="pay-tab active" data-tab="basic">Basic — Wallet Address</button>
              <button type="button" class="pay-tab" data-tab="advanced">Advanced — BTCPay Server</button>
            </div>
            <input type="hidden" id="gw-btc-mode" value="basic">

            <div class="pay-tab-pane active" data-pane="btc-basic">
              <label class="form-label">Bitcoin Wallet Address</label>
              <input type="text" id="gw-btc-address" class="input-field" placeholder="bc1q…">
              <p class="pay-help" style="margin-top:8px;">Clients send BTC to this address. You watch your wallet for confirmation and mark paid here. No automation.</p>
            </div>

            <div class="pay-tab-pane" data-pane="btc-advanced">
              <div style="display:flex;flex-direction:column;gap:12px;">
                <div>
                  <label class="form-label">BTCPay Server URL</label>
                  <input type="url" id="gw-btcpay-server" class="input-field" placeholder="https://btcpay.example.com">
                </div>
                <div>
                  <label class="form-label">Store ID</label>
                  <input type="text" id="gw-btcpay-store" class="input-field" placeholder="abc123…">
                </div>
                <div>
                  <label class="form-label">API Key <span style="font-weight:400;opacity:.6;">(encrypted — blank keeps existing)</span></label>
                  <input type="password" id="gw-btcpay-api-key" class="input-field" placeholder="(or leave blank to keep existing)">
                </div>
                <div>
                  <label class="form-label">Webhook Secret <span style="font-weight:400;opacity:.6;">(encrypted — blank keeps existing)</span></label>
                  <input type="password" id="gw-btcpay-webhook-secret" class="input-field" placeholder="(or leave blank to keep existing)">
                </div>
                <div class="pay-webhook-strip">
                  <div class="pay-webhook-label">Your Webhook URL</div>
                  <div class="pay-webhook-row">
                    <input type="text" readonly class="input-field pay-webhook-url" id="gw-btcpay-webhook-url" value="">
                    <button type="button" class="btn btn-ghost btn-sm pay-copy-btn" data-copy-target="gw-btcpay-webhook-url">Copy</button>
                  </div>
                </div>
                <details class="pay-instructions">
                  <summary>How to set up BTCPay Server webhooks</summary>
                  <ol>
                    <li>Sign in to your BTCPay Server install.</li>
                    <li>Open your <strong>Store → General Settings</strong>. Copy the <strong>Store ID</strong> from the URL into the field above.</li>
                    <li>Open <strong>Account → Manage Account → API Keys → Generate Key</strong>. Grant scopes <code>btcpay.store.canviewinvoices</code> and <code>btcpay.store.canmodifyinvoices</code>. Paste it above.</li>
                    <li>Open your store's <strong>Settings → Webhooks → Create Webhook</strong>.</li>
                    <li>Paste the webhook URL above into the <em>Payload URL</em> field.</li>
                    <li>Tick the events <code>InvoiceSettled</code> and <code>InvoicePaymentSettled</code>.</li>
                    <li>Set a <strong>Secret</strong> (any random string ≥ 32 chars). Paste the same value into the field above.</li>
                  </ol>
                  <p class="pay-help" style="margin-top:8px;color:var(--green);">Routing is automatic — when your client books, we create a real BTCPay invoice via the Greenfield API with <code>metadata.orderId</code> set to your booking token. Your client gets BTCPay's hosted invoice page (BTC + Lightning + altcoins your store accepts). When the payment confirms, BTCPay fires <code>InvoiceSettled</code>; we verify the HMAC, look up the booking by token, and mark it paid. Your client gets a confirmation email automatically.</p>
                </details>
              </div>
            </div>
          </div>
        </div>

        <!-- Square panel -->
        <div class="prof-panel pay-gateway" style="margin-top:20px;" data-gateway="square">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <div>
              <div class="prof-panel-title" style="margin-bottom:2px;">Square <span style="font-size:11px;padding:2px 7px;border-radius:4px;background:rgba(62,207,142,0.1);color:#4caf87;border:1px solid rgba(62,207,142,0.25);margin-left:6px;">Includes Cash App Pay</span></div>
              <div class="prof-panel-sub" style="margin-bottom:0;">In-person and online payments via Square. Basic = manual reconciliation; Advanced = webhook auto-confirm.</div>
            </div>
            <label class="toggle-switch" style="flex-shrink:0;">
              <input type="checkbox" id="gw-square-enabled">
              <span class="toggle-track"><span class="toggle-thumb"></span></span>
            </label>
          </div>
          <div id="gw-square-fields" style="display:none;margin-top:12px;">
            <div class="pay-tabs" data-target="square">
              <button type="button" class="pay-tab active" data-tab="basic">Basic — Manual</button>
              <button type="button" class="pay-tab" data-tab="advanced">Advanced — Webhook Auto-Confirm</button>
            </div>
            <input type="hidden" id="gw-square-mode" value="basic">

            <div class="pay-tab-pane active" data-pane="square-basic">
              <div style="display:flex;flex-direction:column;gap:12px;">
                <div>
                  <label class="form-label">Application ID</label>
                  <input type="text" id="gw-square-app-id" class="input-field" placeholder="sq0idp-…">
                </div>
                <div>
                  <label class="form-label">Access Token <span style="font-weight:400;opacity:.6;">(encrypted — blank keeps existing)</span></label>
                  <input type="password" id="gw-square-token" class="input-field" placeholder="EAAAl… (or leave blank to keep existing)">
                </div>
                <div>
                  <label class="form-label">Location ID</label>
                  <input type="text" id="gw-square-location-id" class="input-field" placeholder="L…">
                </div>
                <p class="pay-help">Stores your Square credentials for use with Square's hosted invoices and Cash App Pay links. Square emails you on success — mark paid here manually.</p>
              </div>
            </div>

            <div class="pay-tab-pane" data-pane="square-advanced">
              <div style="display:flex;flex-direction:column;gap:12px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                  <div>
                    <label class="form-label">Environment</label>
                    <select id="gw-square-env" class="input-field">
                      <option value="production">Production</option>
                      <option value="sandbox">Sandbox</option>
                    </select>
                  </div>
                  <div>
                    <label class="form-label">Location ID</label>
                    <input type="text" id="gw-square-location-id-adv" class="input-field" placeholder="L…">
                  </div>
                </div>
                <div>
                  <label class="form-label">Application ID</label>
                  <input type="text" id="gw-square-app-id-adv" class="input-field" placeholder="sq0idp-…">
                </div>
                <div>
                  <label class="form-label">Access Token <span style="font-weight:400;opacity:.6;">(encrypted — blank keeps existing)</span></label>
                  <input type="password" id="gw-square-token-adv" class="input-field" placeholder="EAAAl… (or leave blank to keep existing)">
                </div>
                <div>
                  <label class="form-label">Webhook Signature Key <span style="font-weight:400;opacity:.6;">(encrypted — blank keeps existing)</span></label>
                  <input type="password" id="gw-square-webhook-sig" class="input-field" placeholder="(or leave blank to keep existing)">
                </div>
                <div class="pay-webhook-strip">
                  <div class="pay-webhook-label">Your Webhook URL</div>
                  <div class="pay-webhook-row">
                    <input type="text" readonly class="input-field pay-webhook-url" id="gw-square-webhook-url" value="">
                    <button type="button" class="btn btn-ghost btn-sm pay-copy-btn" data-copy-target="gw-square-webhook-url">Copy</button>
                  </div>
                </div>
                <details class="pay-instructions">
                  <summary>How to set up Square webhooks</summary>
                  <ol>
                    <li>Sign in to <strong>developer.squareup.com</strong> and open your application.</li>
                    <li>Copy the <strong>Application ID</strong> and <strong>Access Token</strong> into the fields above. (Use a <em>Production</em> token, not Sandbox, for live payments.)</li>
                    <li>Find your <strong>Location ID</strong> under <em>Locations</em> and paste it above.</li>
                    <li>Go to your application's <strong>Webhooks → Add subscription</strong>.</li>
                    <li>Paste the webhook URL above into the <em>Notification URL</em> field.</li>
                    <li>Subscribe to events: <code>payment.created</code> and <code>payment.updated</code>.</li>
                    <li>Save. Square reveals a <strong>Signature Key</strong> — copy it and paste it above.</li>
                  </ol>
                  <p class="pay-help" style="margin-top:8px;color:var(--green);">Routing is automatic — when your client books, we create a real Square Online Checkout payment link with <code>order.reference_id</code> set to your booking token. Your client gets Square's hosted checkout (cards + Cash App Pay + Apple Pay). When the payment completes, Square fires <code>payment.updated</code> with <code>status: COMPLETED</code>; we verify the HMAC, match the booking, and mark it paid. Your client gets a confirmation email automatically.</p>
                </details>
              </div>
            </div>
          </div>
        </div>

        <div id="gw-error" class="prof-alert prof-alert-error" style="display:none;margin-top:12px;"></div>
        <div id="gw-success" class="prof-alert prof-alert-success" style="display:none;margin-top:12px;"></div>
        <button id="save-gateways-btn" class="btn btn-primary" style="font-family:inherit;margin-top:20px;">Save Payment Settings</button>

        <!-- Payment DPA panel -->
        <div class="prof-panel" style="margin-top:32px;" id="payment-dpa-panel">
          <div class="prof-panel-title">Data Processing Agreements</div>
          <div class="prof-panel-sub">GDPR requires a signed DPA with each payment processor you use. Upload the DPA before enabling a processor. Clients can view your active DPAs from their portal.</div>
          <div id="payment-dpa-list" style="margin-top:16px;display:flex;flex-direction:column;gap:10px;"></div>
          <div style="margin-top:16px;border:1px solid var(--border);border-radius:8px;padding:16px;background:rgba(255,255,255,0.02);">
            <div class="prof-panel-title" style="font-size:13px;margin-bottom:12px;">Upload DPA Document</div>
            <div style="display:flex;flex-direction:column;gap:10px;">
              <div>
                <label class="form-label">Processor</label>
                <select id="payment-dpa-type" class="input-field">
                  <option value="payment_stripe">Stripe</option>
                  <option value="payment_paypal">PayPal</option>
                  <option value="payment_square">Square</option>
                  <option value="payment_custom">Custom Processor</option>
                </select>
              </div>
              <div>
                <label class="form-label">Vendor / Processor Name</label>
                <input type="text" id="payment-dpa-vendor" class="input-field" placeholder="e.g. Stripe Payments UK Ltd">
              </div>
              <div>
                <label class="form-label">DPA Document <span style="font-weight:400;opacity:.6;">(PDF, DOC, DOCX — max 20MB)</span></label>
                <input type="file" id="payment-dpa-file" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" class="input-field" style="padding:6px;">
              </div>
              <div>
                <label class="form-label">Notes <span style="font-weight:400;opacity:.6;">(optional)</span></label>
                <input type="text" id="payment-dpa-notes" class="input-field" placeholder="e.g. Standard DPA — data controller addendum signed">
              </div>
            </div>
            <div id="payment-dpa-error" class="prof-alert prof-alert-error" style="display:none;margin-top:10px;"></div>
            <div id="payment-dpa-success" class="prof-alert prof-alert-success" style="display:none;margin-top:10px;"></div>
            <button id="upload-payment-dpa-btn" class="btn btn-primary" style="font-family:inherit;margin-top:14px;">Upload DPA</button>
          </div>
        </div>

        <!-- Discount campaigns -->
        <div class="prof-panel" style="margin-top:32px;">
          <div class="prof-panel-title">Discount Campaigns</div>
          <div class="prof-panel-sub">Create promotional periods — e.g. 100% off for first 3 months. Clients will see active campaigns on your booking page.</div>

          <div id="campaigns-list" style="margin-bottom:16px;display:flex;flex-direction:column;gap:8px;"></div>

          <div id="campaign-form" style="border:1px solid var(--border);border-radius:8px;padding:16px;background:rgba(255,255,255,0.02);">
            <div class="prof-panel-title" style="font-size:14px;margin-bottom:12px;" id="campaign-form-title">New Campaign</div>
            <input type="hidden" id="campaign-edit-id" value="0">
            <div style="display:flex;flex-direction:column;gap:12px;">
              <div>
                <label class="form-label">Label</label>
                <input type="text" id="campaign-label" class="input-field" placeholder="e.g. Launch Promo — First 3 Months">
              </div>
              <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div style="flex:1;min-width:100px;">
                  <label class="form-label">Discount %</label>
                  <input type="number" id="campaign-pct" class="input-field" min="0" max="100" placeholder="100">
                </div>
                <div style="flex:1;min-width:120px;">
                  <label class="form-label">Valid From</label>
                  <input type="date" id="campaign-from" class="input-field">
                </div>
                <div style="flex:1;min-width:120px;">
                  <label class="form-label">Valid Until <span style="font-weight:400;opacity:.6;">(blank = ongoing)</span></label>
                  <input type="date" id="campaign-until" class="input-field">
                </div>
              </div>
              <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;cursor:pointer;">
                <input type="checkbox" id="campaign-active" checked style="accent-color:var(--accent);width:15px;height:15px;">
                Active (visible to clients)
              </label>
            </div>
            <div id="campaign-error" class="prof-alert prof-alert-error" style="display:none;margin-top:10px;"></div>
            <div style="display:flex;gap:8px;margin-top:14px;">
              <button class="btn btn-primary" style="font-family:inherit;" id="campaign-save-btn">Add Campaign</button>
              <button class="btn btn-ghost" style="font-family:inherit;display:none;" id="campaign-cancel-btn">Cancel</button>
            </div>
          </div>
        </div>

        <!-- Discount Codes -->
        <div class="prof-panel" style="margin-top:32px;">
          <div class="prof-panel-title">Discount Codes</div>
          <div class="prof-panel-sub">Create codes clients enter at booking — set percentage off, expiry, total uses, and per-client limits. Codes are case-insensitive.</div>

          <div id="dcodes-list" style="margin-bottom:16px;display:flex;flex-direction:column;gap:8px;"></div>

          <div id="dcode-form" style="border:1px solid var(--border);border-radius:8px;padding:16px;background:rgba(255,255,255,0.02);">
            <div class="prof-panel-title" style="font-size:14px;margin-bottom:12px;" id="dcode-form-title">New Discount Code</div>
            <input type="hidden" id="dcode-edit-id" value="0">
            <div style="display:flex;flex-direction:column;gap:12px;">

              <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div style="flex:2;min-width:160px;">
                  <label class="form-label">Code <span style="font-weight:400;opacity:.6;">(letters, numbers, hyphens, underscores)</span></label>
                  <input type="text" id="dcode-code" class="input-field" placeholder="e.g. SUMMER25"
       
                         maxlength="64" style="font-family:monospace;letter-spacing:.05em;">
                </div>
                <div style="flex:1;min-width:100px;">
                  <label class="form-label">Discount %</label>
                  <input type="number" id="dcode-pct" class="input-field" min="1" max="100" placeholder="20">
                </div>
              </div>

              <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div style="flex:1;min-width:120px;">
                  <label class="form-label">Valid From</label>
                  <input type="date" id="dcode-from" class="input-field">
                </div>
                <div style="flex:1;min-width:140px;">
                  <label class="form-label">Valid Until</label>
                  <input type="date" id="dcode-until" class="input-field">
                  <label style="display:flex;align-items:center;gap:6px;margin-top:6px;font-size:12.5px;cursor:pointer;">
                    <input type="checkbox" id="dcode-no-expiry" style="accent-color:var(--accent);width:13px;height:13px;">
                    No expiry
                  </label>
                </div>
              </div>

              <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <div style="flex:1;min-width:140px;">
                  <label class="form-label">Max Total Uses</label>
                  <input type="number" id="dcode-max-total" class="input-field" min="1" placeholder="e.g. 100">
                  <label style="display:flex;align-items:center;gap:6px;margin-top:6px;font-size:12.5px;cursor:pointer;">
                    <input type="checkbox" id="dcode-no-total-limit" style="accent-color:var(--accent);width:13px;height:13px;">
                    No restriction
                  </label>
                </div>
                <div style="flex:1;min-width:140px;">
                  <label class="form-label">Max Uses Per Client <span style="font-weight:400;opacity:.6;">(account)</span></label>
                  <input type="number" id="dcode-max-client" class="input-field" min="1" placeholder="e.g. 3">
                  <label style="display:flex;align-items:center;gap:6px;margin-top:6px;font-size:12.5px;cursor:pointer;">
                    <input type="checkbox" id="dcode-no-client-limit" style="accent-color:var(--accent);width:13px;height:13px;">
                    No restriction
                  </label>
                </div>
              </div>

              <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;cursor:pointer;">
                <input type="checkbox" id="dcode-active" checked style="accent-color:var(--accent);width:15px;height:15px;">
                Active (clients can use this code)
              </label>
            </div>

            <div id="dcode-error" class="prof-alert prof-alert-error" style="display:none;margin-top:10px;"></div>
            <div style="display:flex;gap:8px;margin-top:14px;">
              <button class="btn btn-primary" style="font-family:inherit;" id="dcode-save-btn">Create Code</button>
              <button class="btn btn-ghost" style="font-family:inherit;display:none;" id="dcode-cancel-btn">Cancel</button>
            </div>
          </div>
        </div><!-- /discount codes panel -->

      </div><!-- /#payments-content -->
    </div><!-- /#section-payments -->

    <!-- ── Intake Forms ── -->
    <div class="prof-section" id="section-intake">
      <div class="prof-section-title">Intake Forms</div>
      <div class="prof-section-sub">Build forms to send to clients before their session. Start from a system template or create your own. Assign a form to any booking from the Dashboard → Schedule section.</div>

      <div id="intake-loading" style="padding:24px 0;color:var(--text-muted);font-size:13px;">Loading…</div>
      <div id="intake-content" style="display:none;">

        <!-- Form Templates panel -->
        <div class="prof-panel" style="margin-bottom:20px;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
            <div class="prof-panel-title" style="margin-bottom:0;">Form Templates</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <select id="profFtRoleFilter" style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:5px 9px;font-size:12px;color:var(--text);outline:none;">
                <option value="">All roles</option>
                <option value="therapist">Therapist</option>
                <option value="counselor">Counselor</option>
                <option value="coach">Life Coach</option>
                <option value="philosophical_counselor">Philosophical Counselor</option>
                <option value="peer_support">Peer Support</option>
                <option value="any">Universal</option>
              </select>
              <input type="text" id="profFtSearch" placeholder="Search templates…"
                     style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:5px 9px;font-size:12px;color:var(--text);outline:none;min-width:150px;">
            </div>
          </div>
          <div id="profFtList" style="display:flex;flex-direction:column;gap:8px;">
            <p style="font-size:13px;color:var(--text-muted);">Loading templates…</p>
          </div>
        </div>

        <!-- Existing forms list -->
        <div class="prof-panel">
          <div class="prof-panel-title">Your Forms</div>
          <div id="intake-forms-list" style="margin-bottom:16px;display:flex;flex-direction:column;gap:8px;">
            <p style="font-size:13px;color:var(--text-muted);">Loading forms…</p>
          </div>
          <button id="intake-new-form-btn" class="btn btn-primary" style="font-family:inherit;">
            + New Form
          </button>
        </div>

        <!-- Form builder panel (hidden until create/edit) -->
        <div id="intake-builder-panel" class="prof-panel" style="display:none;margin-top:20px;">
          <div class="prof-panel-title" id="intake-builder-title">New Form</div>
          <input type="hidden" id="intake-edit-id" value="0">

          <div style="display:flex;flex-direction:column;gap:14px;">
            <div>
              <label class="form-label">Form Name</label>
              <input type="text" id="intake-form-name" class="input-field" placeholder="e.g. New Client Intake, Pre-Session Questionnaire">
            </div>
            <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;cursor:pointer;">
              <input type="checkbox" id="intake-form-active" checked style="accent-color:var(--accent);width:14px;height:14px;">
              Active (can be assigned to bookings)
            </label>
          </div>

          <!-- Field builder -->
          <div style="margin-top:20px;">
            <div style="font-size:13.5px;font-weight:600;color:var(--text);margin-bottom:10px;">Questions</div>
            <div id="intake-fields-list" style="display:flex;flex-direction:column;gap:10px;margin-bottom:14px;"></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <button id="intake-add-text-btn" class="btn btn-ghost" style="font-family:inherit;font-size:12px;">+ Short Answer</button>
              <button id="intake-add-textarea-btn" class="btn btn-ghost" style="font-family:inherit;font-size:12px;">+ Long Answer</button>
              <button id="intake-add-select-btn" class="btn btn-ghost" style="font-family:inherit;font-size:12px;">+ Dropdown</button>
              <button id="intake-add-radio-btn" class="btn btn-ghost" style="font-family:inherit;font-size:12px;">+ Multiple Choice</button>
              <button id="intake-add-checkbox-btn" class="btn btn-ghost" style="font-family:inherit;font-size:12px;">+ Checkbox</button>
            </div>
          </div>

          <div id="intake-builder-error" class="prof-alert prof-alert-error" style="display:none;margin-top:12px;"></div>
          <div style="display:flex;gap:8px;margin-top:16px;">
            <button class="btn btn-primary" style="font-family:inherit;" id="intake-save-btn">Save Form</button>
            <button id="intake-cancel-btn" class="btn btn-ghost" style="font-family:inherit;">Cancel</button>
          </div>
        </div>

      </div><!-- /intake-content -->
    </div><!-- /#section-intake -->

    <!-- Template preview modal (outside section, fixed overlay) -->
    <div id="intakeFtPreviewModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1200;align-items:center;justify-content:center;padding:20px;">
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;width:100%;max-width:560px;max-height:88vh;overflow-y:auto;padding:28px 30px;position:relative;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px;">
          <div>
            <h2 id="intakeFtPreviewTitle" style="font-size:17px;font-weight:700;color:var(--text);margin:0 0 4px;"></h2>
            <div id="intakeFtPreviewMeta" style="font-size:11px;color:var(--text-muted);"></div>
          </div>
          <button id="intakeFtPreviewClose" style="background:transparent;border:none;color:var(--text-muted);cursor:pointer;font-size:22px;line-height:1;padding:0 4px;flex-shrink:0;">×</button>
        </div>
        <div id="intakeFtPreviewFields" style="margin-top:18px;display:flex;flex-direction:column;gap:14px;"></div>
        <div style="display:flex;gap:8px;margin-top:22px;justify-content:flex-end;">
          <button id="intakeFtPreviewClose2" class="btn btn-ghost" style="font-family:inherit;font-size:13px;">Close</button>
          <button id="intakeFtPreviewUse" class="btn btn-primary" style="font-family:inherit;font-size:13px;">Use This Template</button>
        </div>
      </div>
    </div>

    <?php if ($aiPlatformEnabled): ?>
    <!-- ── AI Integration ── -->
    <div class="prof-section" id="section-ai-integration">
      <div class="prof-section-title">AI Integration</div>
      <div class="prof-section-sub">Connect an AI assistant to your practice. Configure your vendor, API key, the scopes you want to use, and build your assistant's identity.</div>

      <div id="ai-loading" style="padding:32px;text-align:center;color:var(--text-muted);">Loading…</div>
      <div id="ai-content" style="display:none;">

        <div id="ai-error" class="prof-alert prof-alert-error" style="display:none;margin-bottom:16px;"></div>
        <div id="ai-success" class="prof-alert prof-alert-success" style="display:none;margin-bottom:16px;"></div>

        <!-- Platform-disabled callout (shown only when ai_enabled = 0) -->
        <div id="ai-platform-disabled" class="prof-panel" style="display:none;margin-bottom:16px;border-left:3px solid var(--amber);background:rgba(245,200,66,0.08);">
          <div class="prof-panel-title" style="color:var(--amber);">AI features are switched off platform-wide</div>
          <div class="prof-panel-sub" id="ai-platform-disabled-msg" style="margin-bottom:10px;">Your platform administrator has not yet enabled AI integration. Contact them to request access — once enabled, you can configure your own vendor and API key here.</div>
          <a id="ai-platform-disabled-link" href="/admin.php#ai" style="display:none;font-size:13px;color:var(--accent);text-decoration:none;">Go to Admin Settings → AI →</a>
        </div>

        <!-- Enable toggle -->
        <div class="prof-panel" style="margin-bottom:16px;">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;">
            <div>
              <div class="prof-panel-title" style="margin-bottom:4px;">Enable AI Integration</div>
              <div class="prof-panel-sub" style="margin:0;">Activates AI features for your sessions. Clients will be informed of the scopes you use when they book.</div>
            </div>
            <label class="toggle-switch" style="flex-shrink:0;">
              <input type="checkbox" id="ai-enabled-toggle">
              <span class="toggle-slider"></span>
            </label>
          </div>
        </div>

        <!-- Vendor & model -->
        <div class="prof-panel" style="margin-bottom:16px;">
          <div class="prof-panel-title">Provider &amp; Model</div>
          <div class="prof-panel-sub">Select your AI vendor and enter your API key. The key is encrypted and stored securely.</div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
            <div>
              <label class="form-label">Vendor</label>
              <select id="ai-vendor" class="input-field">
                <?php foreach (['openai'=>'OpenAI','anthropic'=>'Anthropic','google'=>'Google Gemini','cohere'=>'Cohere'] as $vk => $vn):
                    if (!in_array($vk, $aiAllowedVendors)) continue; ?>
                <option value="<?= $vk ?>"><?= $vn ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="form-label">API Key</label>
              <div style="display:flex;gap:8px;">
                <input type="password" id="ai-api-key" class="input-field" placeholder="Paste your API key" autocomplete="off" style="flex:1;">
              </div>
            </div>
          </div>

          <div style="margin-bottom:14px;">
            <label class="form-label">Model</label>
            <div style="display:flex;gap:8px;align-items:flex-start;">
              <div style="flex:1;position:relative;">
                <input type="text" id="ai-model-filter" class="input-field" placeholder="Search or type a model ID…" style="margin-bottom:6px;">
                <div id="ai-model-list" style="max-height:180px;overflow-y:auto;border:1px solid rgba(255,255,255,0.1);border-radius:8px;background:rgba(0,0,0,0.3);">
                  <div style="padding:12px;color:var(--text-muted);font-size:13px;">Click "Fetch Models" to load available models.</div>
                </div>
              </div>
              <button id="ai-fetch-models-btn" class="btn btn-ghost btn-sm" style="white-space:nowrap;margin-top:2px;">Fetch Models</button>
            </div>
            <div id="ai-model-selected" style="margin-top:8px;font-size:12px;color:var(--text-muted);"></div>
          </div>
        </div>

        <!-- Scopes -->
        <div class="prof-panel" style="margin-bottom:16px;">
          <div class="prof-panel-title">Active Scopes</div>
          <div class="prof-panel-sub">Each scope sends session content to the AI provider. Only enable what you actively use. Clients see these scopes during booking.</div>

          <div id="ai-scopes-list" style="display:flex;flex-direction:column;gap:8px;margin-top:14px;">
            <?php
            $scopeDisplay = [
                'summarization' => ['name'=>'Session Summarization', 'desc'=>'AI generates a summary after export.'],
                'notes'         => ['name'=>'Notes Assistance',       'desc'=>'AI drafts SOAP/DAP/BIRP notes from your sessions.'],
                'post_session'  => ['name'=>'Post-Session Discussion', 'desc'=>'Chat with the AI about a completed session.'],
                'in_session'    => ['name'=>'In-Session Assistant',   'desc'=>'AI joins your live session as a participant, responds when addressed by name.'],
            ];
            foreach ($scopeDisplay as $sk => $sd):
                if (!in_array($sk, $aiAllowedScopes)) continue; ?>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 14px;background:rgba(255,255,255,0.03);border-radius:8px;">
              <div>
                <div style="font-size:13px;font-weight:600;color:var(--text);"><?= $sd['name'] ?></div>
                <div style="font-size:12px;color:var(--text-muted);"><?= $sd['desc'] ?></div>
              </div>
              <label class="toggle-switch" style="flex-shrink:0;">
                <input type="checkbox" class="ai-scope-toggle" data-scope="<?= $sk ?>">
                <span class="toggle-slider"></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Assistant identity -->
        <div class="prof-panel" style="margin-bottom:16px;">
          <div class="prof-panel-title">Assistant Identity</div>
          <div class="prof-panel-sub">Give your assistant a name, an avatar, and a system prompt that shapes how it speaks and behaves in your practice.</div>

          <div style="display:flex;gap:24px;align-items:flex-start;margin-top:14px;flex-wrap:wrap;">

            <!-- Avatar -->
            <div style="flex-shrink:0;text-align:center;">
              <div style="position:relative;width:80px;margin:0 auto 8px;">
                <img id="ai-avatar-preview" src="/assets/images/ai.png" alt="Assistant avatar"
                     style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:2px solid rgba(124,106,247,0.4);">
                <button id="ai-avatar-pick-btn" title="Change avatar"
                        style="position:absolute;bottom:0;right:0;width:24px;height:24px;border-radius:50%;background:var(--accent);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;">
                  <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" width="12" height="12"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
                </button>
              </div>
              <input type="file" id="ai-avatar-file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
              <button class="btn btn-ghost" id="ai-avatar-delete-btn" style="font-size:11px;padding:3px 8px;display:none;">Remove</button>
            </div>

            <!-- Name and prompt -->
            <div style="flex:1;min-width:220px;">
              <div style="margin-bottom:14px;">
                <label class="form-label">Assistant Name</label>
                <input type="text" id="ai-assistant-name" class="input-field" placeholder="e.g. Sage, Aria, Nova" maxlength="100">
                <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Clients address the assistant by this name in live sessions.</div>
              </div>
            </div>
          </div>

          <div style="margin-top:16px;">
            <label class="form-label">System Prompt</label>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">Describe your assistant's role, tone, and any practice-specific guidance. You can drag a .txt file onto this field to load it.</div>
            <textarea id="ai-system-prompt" class="input-field" rows="8"
                      style="font-family:inherit;resize:vertical;"
                      placeholder="e.g. You are a calm, reflective assistant supporting a trauma-informed counselling practice. You speak with warmth and precision. Never speculate about diagnoses. Use the client's own language when possible."
                      ></textarea>
          </div>
        </div>

        <!-- AI vendor DPA -->
        <div class="prof-panel" style="margin-bottom:16px;" id="ai-dpa-panel">
          <div class="prof-panel-title">Data Processing Agreement</div>
          <div class="prof-panel-sub">GDPR requires a signed DPA with your AI vendor. Upload the DPA for the selected vendor before enabling AI integration. Clients can view your active DPAs from their portal.</div>
          <div id="ai-dpa-status" style="margin-top:12px;font-size:13px;color:var(--text-muted);">Loading…</div>
          <div id="ai-dpa-upload-wrap" style="margin-top:12px;border:1px solid var(--border);border-radius:8px;padding:14px;background:rgba(255,255,255,0.02);display:none;">
            <div class="prof-panel-title" style="font-size:13px;margin-bottom:12px;">Upload DPA Document</div>
            <div style="display:flex;flex-direction:column;gap:10px;">
              <div>
                <label class="form-label">Vendor Name</label>
                <input type="text" id="ai-dpa-vendor" class="input-field" placeholder="e.g. OpenAI OpCo LLC">
              </div>
              <div>
                <label class="form-label">DPA Document <span style="font-weight:400;opacity:.6;">(PDF, DOC, DOCX — max 20MB)</span></label>
                <input type="file" id="ai-dpa-file" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" class="input-field" style="padding:6px;">
              </div>
              <div>
                <label class="form-label">Notes <span style="font-weight:400;opacity:.6;">(optional)</span></label>
                <input type="text" id="ai-dpa-notes" class="input-field" placeholder="e.g. EU SCCs applied — Schedule 1 reviewed">
              </div>
            </div>
            <div id="ai-dpa-error" class="prof-alert prof-alert-error" style="display:none;margin-top:10px;"></div>
            <div id="ai-dpa-success" class="prof-alert prof-alert-success" style="display:none;margin-top:10px;"></div>
            <button id="ai-upload-dpa-btn" class="btn btn-primary" style="font-family:inherit;margin-top:12px;">Upload DPA</button>
          </div>
        </div>

        <div style="display:flex;gap:10px;">
          <button id="ai-save-btn" class="btn btn-primary" style="font-family:inherit;">Save AI Configuration</button>
        </div>

      </div><!-- /ai-content -->
    </div><!-- /#section-ai-integration -->
    <?php endif; ?>

    <!-- ── Media Recommendations ── -->
    <div class="prof-section" id="section-media-recommendations">
      <div class="prof-section-title">Media Recommendations</div>
      <div class="prof-section-sub">Configure media sources to recommend books, films, and series to your clients.</div>

      <div id="mr-loading" style="padding:32px;text-align:center;color:var(--text-muted);">Loading…</div>
      <div id="mr-content" style="display:none;">

        <div id="mr-error"   class="prof-alert prof-alert-error"   style="display:none;margin-bottom:16px;"></div>
        <div id="mr-success" class="prof-alert prof-alert-success" style="display:none;margin-bottom:16px;"></div>

        <!-- ── BOOKS ── -->
        <div class="prof-panel" style="margin-bottom:16px;">
          <div class="prof-panel-title">Books</div>

          <!-- Open Library -->
          <div style="padding:14px 0;border-bottom:1px solid rgba(255,255,255,0.06);">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;">
              <div>
                <div style="font-size:13px;font-weight:600;color:var(--text);">Open Library <span style="font-size:11px;font-weight:400;background:rgba(78,203,138,0.12);color:#4ecb8a;border:1px solid rgba(78,203,138,0.25);border-radius:4px;padding:1px 7px;margin-left:6px;">Free · No key needed</span></div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">A free, open catalog provided by the Internet Archive. No API key required.</div>
              </div>
              <label class="toggle-switch" style="flex-shrink:0;">
                <input type="checkbox" id="mr-open-library" checked>
                <span class="toggle-slider"></span>
              </label>
            </div>
          </div>

          <!-- Google Books -->
          <div style="padding:14px 0;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:10px;">
              <div>
                <div style="font-size:13px;font-weight:600;color:var(--text);">Google Books API</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">Google's book catalog with rich metadata and cover images. Requires a free API key.</div>
              </div>
              <label class="toggle-switch" style="flex-shrink:0;">
                <input type="checkbox" id="mr-google-books-toggle">
                <span class="toggle-slider"></span>
              </label>
            </div>
            <div id="mr-google-books-fields" style="display:none;">
              <input type="password" id="mr-google-books-key" class="input-field" placeholder="Paste your Google Books API key here" autocomplete="off" style="margin-bottom:8px;">
              <div style="font-size:12px;color:var(--text-muted);line-height:1.6;">
                Sign up / get access: <a href="https://developers.google.com/books/docs/v1/using" target="_blank" rel="noopener" style="color:var(--accent);text-decoration:none;">developers.google.com/books/docs/v1/using</a><br>
                Visit the Google Cloud Console, create or select a project, enable the Books API, and generate an API key under Credentials. The free tier is sufficient for typical practitioner usage.
              </div>
            </div>
          </div>
        </div>

        <!-- ── VIDEO ── -->
        <div class="prof-panel" style="margin-bottom:16px;">
          <div class="prof-panel-title">Movies &amp; TV Series</div>

          <!-- TMDB -->
          <div style="padding:14px 0;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:10px;">
              <div>
                <div style="font-size:13px;font-weight:600;color:var(--text);">TMDB API (The Movie Database)</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">A comprehensive, community-maintained database of films and television series including posters, ratings, and metadata. Requires a free API key.</div>
              </div>
              <label class="toggle-switch" style="flex-shrink:0;">
                <input type="checkbox" id="mr-tmdb-toggle">
                <span class="toggle-slider"></span>
              </label>
            </div>
            <div id="mr-tmdb-fields" style="display:none;">
              <input type="password" id="mr-tmdb-key" class="input-field" placeholder="Paste your TMDB API key here" autocomplete="off" style="margin-bottom:8px;">
              <div style="font-size:12px;color:var(--text-muted);line-height:1.6;">
                Sign up / get access: <a href="https://www.themoviedb.org/settings/api" target="_blank" rel="noopener" style="color:var(--accent);text-decoration:none;">themoviedb.org/settings/api</a><br>
                Create a free TMDB account, navigate to Settings &gt; API, and request a Developer API key. Approval is typically instant. Use the v3 API key in the field above.
              </div>
            </div>
          </div>
        </div>

        <!-- ── AMAZON AFFILIATE ── -->
        <div style="border-top:1px solid rgba(255,255,255,0.07);padding-top:20px;margin-bottom:16px;">
          <div class="prof-panel">
            <div class="prof-panel-title">Amazon Affiliate API <span style="font-size:11px;font-weight:400;color:var(--text-muted);">(Optional)</span></div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">Allows you to earn referral income when clients purchase recommended books through your affiliate links.</div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:12px;">
              <div style="font-size:13px;font-weight:600;color:var(--text);">Enable Amazon Affiliate links</div>
              <label class="toggle-switch" style="flex-shrink:0;">
                <input type="checkbox" id="mr-amazon-toggle">
                <span class="toggle-slider"></span>
              </label>
            </div>

            <div style="background:rgba(245,200,66,0.08);border:1px solid rgba(245,200,66,0.25);border-radius:8px;padding:12px 14px;font-size:12px;color:rgba(221,220,242,0.7);line-height:1.65;margin-bottom:14px;">
              <strong style="color:#f5c842;">⚠ Important:</strong> Amazon's Product Advertising API requires an active Amazon Associates account with recent qualifying sales. Amazon may suspend API access if your account does not maintain sufficient referral activity. This integration is recommended only if you actively promote and generate purchases. All core book search functionality works without it via Open Library and Google Books.
            </div>

            <div id="mr-amazon-fields" style="display:none;">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                  <label class="form-label">Associate Tag</label>
                  <input type="text" id="mr-amazon-tag" class="input-field" placeholder="your-associate-tag-20" autocomplete="off">
                </div>
                <div>
                  <label class="form-label">PA API Access Key</label>
                  <input type="text" id="mr-amazon-access-key" class="input-field" placeholder="Access key" autocomplete="off">
                </div>
              </div>
              <div>
                <label class="form-label">PA API Secret Key</label>
                <input type="password" id="mr-amazon-secret-key" class="input-field" placeholder="Secret key" autocomplete="off">
              </div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:10px;line-height:1.6;">
                Sign up / get access: <a href="https://affiliate-program.amazon.com" target="_blank" rel="noopener" style="color:var(--accent);text-decoration:none;">affiliate-program.amazon.com</a><br>
                Enroll in Amazon Associates, then visit your account dashboard under Tools &gt; Product Advertising API to generate your access credentials.
              </div>
            </div>
          </div>
        </div>

        <div style="display:flex;gap:10px;">
          <button id="mr-save-btn" class="btn btn-primary" style="font-family:inherit;">Save Configuration</button>
        </div>

      </div><!-- /mr-content -->
    </div><!-- /#section-media-recommendations -->

    <!-- ══════════════════════════════════════════════
         REFERENCES & QUOTES
         Practitioner-curated reference material — quotes from a built-in
         library (Bible, Quran, Tao Te Ching, Bhagavad Gita, philosophy,
         recovery, grief, mindfulness, indigenous wisdom), starred media
         recommendations, and the practitioner's own custom additions.
         Drop into chat or notes during sessions; manage out of session.
    ══════════════════════════════════════════════ -->
    <div class="prof-section" id="section-references">
      <div class="prof-section-title">References &amp; Quotes</div>
      <div class="prof-section-sub">Reference material you've curated — yours, not platform doctrine.</div>

      <div class="prof-panel" style="border-left:3px solid var(--accent);background:rgba(124,106,247,0.05);">
        <p style="font-size:13px;color:var(--text-muted);line-height:1.65;margin:0;">
          <strong style="color:var(--text);">A starting library, not a canon.</strong>
          The built-in collection includes carefully attributed quotes from
          religious traditions (Bible OT &amp; NT, Quran, Tao Te Ching,
          Bhagavad Gita, Dhammapada, Talmud, Sufi, Sikh, Bahá'í, Indigenous
          traditions), Stoic and existential philosophy, recovery, grief,
          and mindfulness sources. Star what's useful for your practice.
          Add your own. Edit categories. Nothing here is platform doctrine —
          it's a starting point you make yours.
        </p>
      </div>

      <!-- Tab strip -->
      <div class="ref-tabs" style="display:flex;gap:4px;border-bottom:1px solid var(--border);margin-top:18px;margin-bottom:0;">
        <button type="button" class="ref-tab active" data-ref-tab="library">Quote Library</button>
        <button type="button" class="ref-tab" data-ref-tab="mine">My References</button>
        <button type="button" class="ref-tab" data-ref-tab="add">Add Custom</button>
      </div>

      <!-- ── Tab: Quote Library ── -->
      <div class="ref-tab-pane" data-ref-pane="library" style="display:block;padding-top:16px;">
        <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center;">
          <input type="search" id="ref-lib-search" placeholder="Search the library — author, body, source, tag…"
                 style="flex:1;min-width:240px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px 12px;font-size:13px;font-family:inherit;outline:none;">
        </div>
        <div id="ref-lib-categories" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;"></div>
        <div id="ref-lib-results" style="display:flex;flex-direction:column;gap:10px;">
          <div style="font-size:13px;color:var(--text-muted);padding:20px;text-align:center;">Loading library…</div>
        </div>
      </div>

      <!-- ── Tab: My References ── -->
      <div class="ref-tab-pane" data-ref-pane="mine" style="display:none;padding-top:16px;">
        <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center;">
          <input type="search" id="ref-mine-search" placeholder="Search my references…"
                 style="flex:1;min-width:240px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px 12px;font-size:13px;font-family:inherit;outline:none;">
        </div>
        <div id="ref-mine-categories" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;"></div>
        <div id="ref-mine-results" style="display:flex;flex-direction:column;gap:10px;">
          <div style="font-size:13px;color:var(--text-muted);padding:20px;text-align:center;">Loading your references…</div>
        </div>
      </div>

      <!-- ── Tab: Add Custom ── -->
      <div class="ref-tab-pane" data-ref-pane="add" style="display:none;padding-top:16px;">
        <p style="font-size:12.5px;color:var(--text-muted);line-height:1.6;margin-bottom:14px;">
          Add a quote, passage, book, or other reference of your own. Attribution fields keep authorship clear when you share.
        </p>
        <div style="display:flex;flex-direction:column;gap:12px;">
          <input type="hidden" id="ref-add-id" value="0">

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div>
              <label class="form-label">Type</label>
              <select id="ref-add-type" class="input-field">
                <option value="quote">Quote</option>
                <option value="passage">Passage</option>
                <option value="book">Book</option>
                <option value="film">Film</option>
                <option value="tv">TV / Series</option>
                <option value="article">Article</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div>
              <label class="form-label">Category</label>
              <select id="ref-add-category" class="input-field">
                <?php foreach (['Practitioner','Christian','Jewish','Islamic','Buddhist','Hindu','Taoist','Interfaith','Philosophy','Mindfulness','Recovery','Grief','Pop Culture'] as $cat): ?>
                  <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div>
            <label class="form-label">Title / short label</label>
            <input type="text" id="ref-add-title" class="input-field" placeholder="e.g. The Guest House — Rumi">
          </div>

          <div>
            <label class="form-label">Body / passage <span style="font-weight:400;opacity:.6;">(the full text)</span></label>
            <textarea id="ref-add-body" rows="4" class="input-field" placeholder="Type or paste the quote, passage, or paraphrase here…" style="resize:vertical;"></textarea>
          </div>

          <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;">
            <div>
              <label class="form-label">Author / speaker</label>
              <input type="text" id="ref-add-author" class="input-field" placeholder="e.g. Rumi, Marcus Aurelius, Anonymous">
            </div>
            <div>
              <label class="form-label">Year / period</label>
              <input type="text" id="ref-add-year" class="input-field" placeholder="e.g. 1946, c. 165 CE">
            </div>
          </div>

          <div>
            <label class="form-label">Source <span style="font-weight:400;opacity:.6;">(book / publication / film / speech)</span></label>
            <input type="text" id="ref-add-source" class="input-field" placeholder="e.g. Meditations Book IV, A Grief Observed">
          </div>

          <div>
            <label class="form-label">Source URL <span style="font-weight:400;opacity:.6;">(optional)</span></label>
            <input type="url" id="ref-add-source-url" class="input-field" placeholder="https://…">
          </div>

          <div>
            <label class="form-label">Tags <span style="font-weight:400;opacity:.6;">(comma-separated themes — e.g. grief, anxiety, cbt)</span></label>
            <input type="text" id="ref-add-tags" class="input-field" placeholder="grief, recovery, comfort">
          </div>

          <div>
            <label class="form-label">Private notes <span style="font-weight:400;opacity:.6;">(why you saved this — never sent to clients)</span></label>
            <textarea id="ref-add-notes" rows="2" class="input-field" placeholder="Reminder note for yourself…" style="resize:vertical;"></textarea>
          </div>

          <div id="ref-add-error" class="prof-alert prof-alert-error" style="display:none;"></div>
          <div id="ref-add-success" class="prof-alert prof-alert-success" style="display:none;"></div>

          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px;">
            <button class="btn btn-ghost" type="button" id="ref-add-clear-btn" style="font-family:inherit;">Clear</button>
            <button class="btn btn-primary" type="button" id="ref-add-save-btn" style="font-family:inherit;">Save Reference</button>
          </div>
        </div>
      </div>
    </div><!-- /#section-references -->

  </main><!-- /.prof-body -->

</div><!-- /.prof-wrap -->

<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
function handleUpload(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    let prev = document.getElementById('upload-preview');
    const placeholder = document.getElementById('avatar-placeholder');
    if (placeholder) placeholder.style.display = 'none';
    const wrap = document.getElementById('upload-preview-wrap');
    if (wrap) wrap.style.display = 'block';
    if (!prev) {
      prev = document.createElement('img');
      prev.id = 'upload-preview';
      prev.className = 'avatar-ring';
      if (wrap) wrap.appendChild(prev);
    }
    prev.src = e.target.result;
    prev.style.display = 'block';
  };
  reader.readAsDataURL(input.files[0]);
}

// ── Schedule day toggles ──────────────────────────────────────
document.querySelectorAll('.sched-day-check').forEach(cb => {
  cb.addEventListener('change', function() {
    const row   = this.closest('.schedule-day-row');
    const times = row.querySelector('.schedule-day-times');
    times.style.opacity      = this.checked ? '1'    : '0.35';
    times.style.pointerEvents= this.checked ? 'auto' : 'none';
  });
});

async function saveSchedule() {
  const schedule = [];
  document.querySelectorAll('.schedule-day-row').forEach(row => {
    const dow   = parseInt(row.dataset.dow);
    const check = row.querySelector('.sched-day-check');
    if (!check.checked) return;
    const start = row.querySelector('.sched-start').value;
    const end   = row.querySelector('.sched-end').value;
    if (start && end && end > start) schedule.push({ day_of_week: dow, start_time: start, end_time: end });
  });
  const res = await fetch('/api/availability.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'save_schedule', schedule }),
  });
  if (res.ok) {
    const msg = document.getElementById('sched-save-msg');
    msg.style.display = 'inline';
    setTimeout(() => msg.style.display = 'none', 2500);
  }
}

async function addExclusion() {
  const input = document.getElementById('excl-date-input');
  const date  = input.value;
  if (!date) return;
  const res = await fetch('/api/availability.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'add_exclusion', date }),
  });
  if (res.ok) {
    const list = document.getElementById('exclusions-list');
    const tag  = document.createElement('span');
    tag.className    = 'excl-tag';
    tag.dataset.date = date;
    tag.innerHTML = `${date} <button type="button">×</button>`;
    list.appendChild(tag);
    input.value = '';
  }
}

async function removeExclusion(date, btn) {
  const res = await fetch('/api/availability.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'remove_exclusion', date }),
  });
  if (res.ok) btn.closest('.excl-tag').remove();
}

// ── Identity icon updates ─────────────────────────────────────
const genderIconMap = {
  '':'prefer-not-to-say',
  male:'male', female:'female', trans_woman:'trans-woman', trans_man:'trans-man',
  non_binary:'nonbinary', genderfluid:'genderfluid', prefer_not_to_say:'prefer-not-to-say'
};
const sexualityIconMap = {
  straight:'straight', gay:'gay', lesbian:'lesbian', bisexual:'bisexual',
  pansexual:'pansexual', asexual:'asexual', queer:'queer', questioning:'questioning',
  prefer_not_to_say:'prefer-not-to-say'
};
function updateGenderIcon(val) {
  document.getElementById('gender-icon').src =
    '/assets/icons/gender/' + (genderIconMap[val] || 'prefer-not-to-say') + '.svg';
}
function updateSexualityIcon(val) {
  document.getElementById('sexuality-icon').src =
    '/assets/icons/sexuality/' + (sexualityIconMap[val] || 'prefer-not-to-say') + '.svg';
}
function updateFlag(countryCode) {
  document.getElementById('location-flag').textContent =
    countryCode ? countryCodeToFlag(countryCode) : '🌍';
}
function countryCodeToFlag(code) {
  return code.toUpperCase().replace(/./g, c =>
    String.fromCodePoint(0x1F1E6 - 65 + c.charCodeAt(0)));
}

// ── Language tags ─────────────────────────────────────────────
function addLangTag() {
  const sel = document.getElementById('lang-add-select');
  const val = sel.value.trim();
  if (!val) return;
  const existing = [...document.querySelectorAll('.lang-tag input[type=hidden]')].map(i => i.value);
  if (existing.includes(val)) { sel.value = ''; return; }
  const list = document.getElementById('lang-tag-list');
  const span = document.createElement('span');
  span.className = 'tag-pill lang-tag';
  span.innerHTML = `${val}<input type="hidden" name="languages[]" value="${val}"><button type="button">×</button>`;
  list.appendChild(span);
  sel.value = '';
}
function removeLangTag(btn) {
  btn.closest('.lang-tag').remove();
}

// ── Form Template Library (for Intake Forms section) ──────────
let _profFtTemplates  = [];
let _profFtLoaded     = false;
let _profFtPreviewId  = null;
const _profFtRoleLabels = {
  any: 'Universal', therapist: 'Therapist', counselor: 'Counselor',
  coach: 'Life Coach', philosophical_counselor: 'Philosophical Counselor',
  peer_support: 'Peer Support'
};

async function loadProfileFormTemplates() {
  if (_profFtLoaded) return;
  _profFtLoaded = true;
  try {
    const res  = await fetch('/api/intake_templates.php?action=list');
    const data = await res.json();
    _profFtTemplates = data.templates || [];
  } catch(e) { _profFtTemplates = []; }
  renderProfileTemplateList();
}

function renderProfileTemplateList() {
  const role   = document.getElementById('profFtRoleFilter')?.value || '';
  const search = (document.getElementById('profFtSearch')?.value || '').toLowerCase();
  const list   = document.getElementById('profFtList');
  if (!list) return;

  const filtered = _profFtTemplates.filter(t => {
    if (role   && t.role_type !== role && t.role_type !== 'any') return false;
    if (search && !t.name.toLowerCase().includes(search)) return false;
    return true;
  });

  if (!filtered.length) {
    list.innerHTML = '<p style="font-size:13px;color:var(--text-muted);">No templates found.</p>';
    return;
  }

  list.innerHTML = filtered.map(t => `
    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:12px 14px;display:flex;align-items:center;gap:10px;">
      <div style="flex:1;min-width:0;">
        <div style="display:flex;align-items:center;gap:7px;margin-bottom:2px;flex-wrap:wrap;">
          <span style="font-weight:600;font-size:13px;color:var(--text);">${escHtmlProfile(t.name)}</span>
          <span style="background:rgba(124,106,247,.12);color:var(--accent);font-size:10px;font-weight:600;padding:1px 7px;border-radius:4px;">${escHtmlProfile(_profFtRoleLabels[t.role_type] || t.role_type)}</span>
          ${t.is_default ? '<span style="background:rgba(62,207,142,.1);color:#3ecf8e;font-size:10px;font-weight:600;padding:1px 7px;border-radius:4px;">System</span>' : ''}
        </div>
        <div style="font-size:12px;color:var(--text-muted);">${Array.isArray(t.fields) ? t.fields.length : 0} question${(Array.isArray(t.fields) ? t.fields.length : 0) !== 1 ? 's' : ''}</div>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0;">
        <button data-profft-preview="${t.id}" style="background:transparent;border:1px solid var(--border);border-radius:6px;padding:4px 11px;font-size:12px;color:var(--text-muted);cursor:pointer;">Preview</button>
        <button data-profft-use="${t.id}" style="background:var(--accent);color:#fff;border:none;border-radius:6px;padding:4px 11px;font-size:12px;cursor:pointer;">Use</button>
      </div>
    </div>
  `).join('');
}

function openIntakeFtPreview(id) {
  const t = _profFtTemplates.find(t => t.id === id);
  if (!t) return;
  _profFtPreviewId = id;

  document.getElementById('intakeFtPreviewTitle').textContent = t.name;
  document.getElementById('intakeFtPreviewMeta').textContent =
    (_profFtRoleLabels[t.role_type] || t.role_type) + (t.is_default ? ' · System template' : '');

  const fields = Array.isArray(t.fields) ? t.fields : [];
  const container = document.getElementById('intakeFtPreviewFields');
  if (!fields.length) {
    container.innerHTML = '<p style="font-size:13px;color:var(--text-muted);">No questions defined.</p>';
  } else {
    container.innerHTML = fields.map((f, i) => {
      const typeLabel = {text:'Short answer',textarea:'Long answer',select:'Dropdown',checkbox:'Yes/No',radio:'Multiple choice'}[f.type] || f.type;
      return `
        <div style="border:1px solid var(--border);border-radius:8px;padding:12px 14px;">
          <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:4px;">${i+1}. ${escHtmlProfile(f.label || '')}</div>
          <div style="font-size:11px;color:var(--text-muted);">${typeLabel}</div>
          ${(f.type === 'select' || f.type === 'radio') && Array.isArray(f.options) && f.options.length
            ? '<div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:5px;">' +
              f.options.map(o => `<span style="background:var(--surface2);border:1px solid var(--border);border-radius:4px;padding:2px 8px;font-size:11px;color:var(--text-muted);">${escHtmlProfile(o)}</span>`).join('') +
              '</div>'
            : ''}
        </div>`;
    }).join('');
  }

  const modal = document.getElementById('intakeFtPreviewModal');
  modal.style.display = 'flex';
}

function closeIntakeFtPreview() {
  document.getElementById('intakeFtPreviewModal').style.display = 'none';
  _profFtPreviewId = null;
}

function useIntakeTemplate(id) {
  const t = _profFtTemplates.find(t => t.id === id);
  if (!t) return;
  closeIntakeFtPreview();

  // Open the form builder
  openIntakeFormBuilder(0);
  document.getElementById('intake-form-name').value = t.name;
  document.getElementById('intake-builder-title').textContent = 'New Form (from template)';

  // Directly populate intakeBuilderFields from template fields
  const templateFields = Array.isArray(t.fields) ? t.fields : [];
  intakeBuilderFields = templateFields.map(f => {
    const field = {
      id: 'f_' + Math.random().toString(36).slice(2, 10),
      type: f.type || 'text',
      label: f.label || '',
      required: !!f.required,
    };
    if (f.type === 'select' || f.type === 'radio') {
      field.options = Array.isArray(f.options) && f.options.length ? f.options : [''];
    }
    return field;
  });
  renderIntakeFieldList();

  // Scroll to builder
  const panel = document.getElementById('intake-builder-panel');
  if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Intake Forms ──────────────────────────────────────────────
let intakeFormsLoaded = false;
let intakeBuilderFields = []; // [{id, type, label, required, options}]

async function loadIntakeForms() {
  intakeFormsLoaded = true;
  const loading = document.getElementById('intake-loading');
  const content = document.getElementById('intake-content');
  try {
    const [formsRes] = await Promise.all([
      fetch('/api/intake.php?action=list'),
      loadProfileFormTemplates(),
    ]);
    const data = await formsRes.json();
    if (data.ok) renderIntakeFormsList(data.forms || []);
    if (loading) loading.style.display = 'none';
    if (content) content.style.display = 'block';
  } catch(e) {
    if (loading) loading.textContent = 'Could not load intake forms.';
  }
}

function renderIntakeFormsList(forms) {
  const el = document.getElementById('intake-forms-list');
  if (!el) return;
  if (!forms.length) {
    el.innerHTML = '<p style="font-size:13px;color:var(--text-muted);">No forms yet. Create one below.</p>';
    return;
  }
  el.innerHTML = forms.map(f => {
    const fieldCount = (f.fields || []).length;
    const activeColor = f.is_active ? '#4caf87' : 'var(--text-muted)';
    return `<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;border:1px solid var(--border);border-radius:8px;background:rgba(255,255,255,.02);">
      <div>
        <div style="font-size:13.5px;font-weight:500;">${escHtmlProfile(f.name)}</div>
        <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px;">
          ${fieldCount} question${fieldCount !== 1 ? 's' : ''} · ${f.response_count || 0} response${(f.response_count||0) !== 1 ? 's' : ''} ·
          <span style="color:${activeColor}">${f.is_active ? 'Active' : 'Inactive'}</span>
        </div>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0;">
        <button data-edit-intake-form='${JSON.stringify(f).replace(/'/g,"&#39;")}' class="btn btn-ghost sm" style="font-family:inherit;font-size:12px;padding:5px 10px;">Edit</button>
        <button data-delete-intake-form="${f.id}" class="btn btn-ghost sm" style="font-family:inherit;font-size:12px;padding:5px 10px;color:var(--error-color,#e05c5c);">Delete</button>
      </div>
    </div>`;
  }).join('');
}

function openIntakeFormBuilder(formOrId) {
  const panel = document.getElementById('intake-builder-panel');
  if (!panel) return;
  panel.style.display = 'block';
  panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

  if (typeof formOrId === 'object' && formOrId && formOrId.id) {
    // Edit existing
    document.getElementById('intake-edit-id').value        = formOrId.id;
    document.getElementById('intake-form-name').value      = formOrId.name || '';
    document.getElementById('intake-form-active').checked  = !!parseInt(formOrId.is_active);
    document.getElementById('intake-builder-title').textContent = 'Edit Form';
    document.getElementById('intake-save-btn').textContent = 'Save Changes';
    intakeBuilderFields = (formOrId.fields || []).map(f => ({ ...f }));
  } else {
    document.getElementById('intake-edit-id').value        = '0';
    document.getElementById('intake-form-name').value      = '';
    document.getElementById('intake-form-active').checked  = true;
    document.getElementById('intake-builder-title').textContent = 'New Form';
    document.getElementById('intake-save-btn').textContent = 'Save Form';
    intakeBuilderFields = [];
  }
  renderIntakeFieldList();
}

function closeIntakeFormBuilder() {
  const panel = document.getElementById('intake-builder-panel');
  if (panel) panel.style.display = 'none';
  intakeBuilderFields = [];
}

function addIntakeField(type) {
  const id = 'f_' + Math.random().toString(36).slice(2, 10);
  const field = { id, type, label: '', required: false };
  if (type === 'select' || type === 'radio') field.options = [''];
  intakeBuilderFields.push(field);
  renderIntakeFieldList();
  // Focus the newly added label input
  setTimeout(() => {
    const el = document.getElementById('label_' + id);
    if (el) el.focus();
  }, 50);
}

function removeIntakeField(id) {
  intakeBuilderFields = intakeBuilderFields.filter(f => f.id !== id);
  renderIntakeFieldList();
}

function moveIntakeField(id, dir) {
  const idx = intakeBuilderFields.findIndex(f => f.id === id);
  if (idx < 0) return;
  const newIdx = idx + dir;
  if (newIdx < 0 || newIdx >= intakeBuilderFields.length) return;
  [intakeBuilderFields[idx], intakeBuilderFields[newIdx]] = [intakeBuilderFields[newIdx], intakeBuilderFields[idx]];
  renderIntakeFieldList();
}

function syncIntakeField(id) {
  const f = intakeBuilderFields.find(x => x.id === id);
  if (!f) return;
  const labelEl = document.getElementById('label_' + id);
  const reqEl   = document.getElementById('req_' + id);
  if (labelEl) f.label = labelEl.value;
  if (reqEl)   f.required = reqEl.checked;
  if ((f.type === 'select' || f.type === 'radio')) {
    const optsEl = document.getElementById('opts_' + id);
    if (optsEl) f.options = optsEl.value.split('\n').map(s => s.trim()).filter(Boolean);
  }
}

function syncAllIntakeFields() {
  intakeBuilderFields.forEach(f => syncIntakeField(f.id));
}

function renderIntakeFieldList() {
  const el = document.getElementById('intake-fields-list');
  if (!el) return;
  const typeLabels = { text: 'Short Answer', textarea: 'Long Answer', select: 'Dropdown', radio: 'Multiple Choice', checkbox: 'Checkbox' };
  el.innerHTML = intakeBuilderFields.map((f, i) => `
    <div style="border:1px solid var(--border);border-radius:8px;padding:12px 14px;background:rgba(255,255,255,.02);">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
        <span style="font-size:11.5px;color:var(--text-muted);font-weight:500;">${typeLabels[f.type]||f.type}</span>
        <div style="display:flex;gap:4px;">
          <button class="btn btn-ghost sm" style="padding:3px 8px;font-size:11px;" data-move-up="${f.id}" ${i===0?'disabled':''}>↑</button>
          <button class="btn btn-ghost sm" style="padding:3px 8px;font-size:11px;" data-move-down="${f.id}" ${i===intakeBuilderFields.length-1?'disabled':''}>↓</button>
          <button class="btn btn-ghost sm" style="padding:3px 8px;font-size:11px;color:var(--error-color,#e05c5c);" data-remove-field="${f.id}">Remove</button>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <input type="text" id="label_${f.id}" class="input-field" placeholder="Question text"
               value="${escHtmlProfile(f.label)}" data-sync-field="${f.id}">
        ${(f.type === 'select' || f.type === 'radio') ? `
          <div>
            <label class="form-label" style="font-size:11.5px;">Options (one per line)</label>
            <textarea id="opts_${f.id}" class="input-field" rows="3" style="font-size:12.5px;" data-sync-field="${f.id}">${(f.options||[]).join('\n')}</textarea>
          </div>` : ''}
        <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;cursor:pointer;">
          <input type="checkbox" id="req_${f.id}" ${f.required?'checked':''} style="accent-color:var(--accent);width:13px;height:13px;" data-sync-field="${f.id}">
          Required
        </label>
      </div>
    </div>
  `).join('');
}

async function saveIntakeForm() {
  syncAllIntakeFields();
  const errEl = document.getElementById('intake-builder-error');
  errEl.style.display = 'none';
  const name = document.getElementById('intake-form-name').value.trim();
  if (!name) { errEl.textContent = 'Form name is required.'; errEl.style.display = 'block'; return; }

  const fd = new FormData();
  fd.append('action',    'save');
  fd.append('csrf_token', PAYMENTS_CSRF);
  fd.append('id',         document.getElementById('intake-edit-id').value);
  fd.append('name',       name);
  fd.append('is_active',  document.getElementById('intake-form-active').checked ? '1' : '');
  fd.append('fields',     JSON.stringify(intakeBuilderFields));

  try {
    const res  = await fetch('/api/intake.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      closeIntakeFormBuilder();
      const r = await fetch('/api/intake.php?action=list');
      const d = await r.json();
      if (d.ok) renderIntakeFormsList(d.forms || []);
    } else {
      errEl.textContent = data.error || 'Could not save form.';
      errEl.style.display = 'block';
    }
  } catch(e) { errEl.textContent = 'Network error.'; errEl.style.display = 'block'; }
}

async function deleteIntakeForm(id) {
  if (!confirm('Delete this form? Any responses already received will be kept, but the form can no longer be assigned to new bookings.')) return;
  const fd = new FormData();
  fd.append('action',    'delete');
  fd.append('csrf_token', PAYMENTS_CSRF);
  fd.append('id',         id);
  try {
    const res  = await fetch('/api/intake.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      const r = await fetch('/api/intake.php?action=list');
      const d = await r.json();
      if (d.ok) renderIntakeFormsList(d.forms || []);
    }
  } catch(e) { console.error(e); }
}

// ── Section navigation ────────────────────────────────────────
function showSection(id, navEl) {
  document.querySelectorAll('.prof-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.prof-nav-item').forEach(b => b.classList.remove('active'));
  const sec = document.getElementById('section-' + id);
  if (sec) sec.classList.add('active');
  const btn = navEl || document.querySelector(`.prof-nav-item[data-section="${id}"]`);
  if (btn) btn.classList.add('active');
  const bc = document.getElementById('bc-section');
  if (bc && btn) bc.textContent = btn.dataset.label || id;
  history.replaceState(null, '', '#' + id);
  if (id === 'payments'              && !paymentsLoaded)    loadPaymentsSection();
  if (id === 'intake'                && !intakeFormsLoaded) loadIntakeForms();
  if (id === 'ai-integration'        && !aiCfgLoaded)       aiLoad();
  if (id === 'media-recommendations' && !mrCfgLoaded)       mrLoad();
  if (id === 'references'            && !refsLoaded)        loadReferencesInit();
}

// ── Payments section JS ───────────────────────────────────────────
const PAYMENTS_CSRF = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
let paymentsLoaded = false;

// ── Payment gateway tab switching ──
function setActiveTab(gateway, tab) {
  if (!['basic','advanced'].includes(tab)) tab = 'basic';
  const hidden = document.getElementById('gw-' + gateway + '-mode');
  if (hidden) hidden.value = tab;

  const tabsContainer = document.querySelector('.pay-tabs[data-target="' + gateway + '"]');
  if (tabsContainer) {
    tabsContainer.querySelectorAll('.pay-tab').forEach(b => {
      b.classList.toggle('active', b.dataset.tab === tab);
    });
  }
  const fieldRoot = document.getElementById('gw-' + gateway + '-fields');
  if (fieldRoot) {
    fieldRoot.querySelectorAll('.pay-tab-pane').forEach(p => {
      p.classList.toggle('active', p.dataset.pane === gateway + '-' + tab);
    });
  }
}

// Wire payment tab clicks + copy buttons (delegated, runs once)
document.addEventListener('click', e => {
  const tab = e.target.closest('.pay-tab');
  if (tab) {
    const tabsContainer = tab.closest('.pay-tabs');
    if (tabsContainer) setActiveTab(tabsContainer.dataset.target, tab.dataset.tab);
    return;
  }
  const copyBtn = e.target.closest('.pay-copy-btn');
  if (copyBtn) {
    const targetId = copyBtn.dataset.copyTarget;
    const input    = document.getElementById(targetId);
    if (input && input.value) {
      navigator.clipboard.writeText(input.value).then(() => {
        const orig = copyBtn.textContent;
        copyBtn.textContent = 'Copied';
        copyBtn.classList.add('copied');
        setTimeout(() => { copyBtn.textContent = orig; copyBtn.classList.remove('copied'); }, 1600);
      }).catch(() => {
        // Clipboard API unavailable — fall back to select-all so user can ⌘C.
        input.removeAttribute('readonly');
        input.select();
        input.setAttribute('readonly', 'readonly');
      });
    }
  }
});

async function loadPaymentsSection() {
  paymentsLoaded = true;
  const loading = document.getElementById('payments-loading');
  const content = document.getElementById('payments-content');

  try {
    const [rateRes, gwRes, campRes, dcRes] = await Promise.all([
      fetch(`/api/payments.php?action=get_rate&csrf=${PAYMENTS_CSRF}`),
      fetch(`/api/payments.php?action=get_gateways&csrf=${PAYMENTS_CSRF}`),
      fetch(`/api/payments.php?action=list_campaigns&csrf=${PAYMENTS_CSRF}`),
      fetch(`/api/discount_codes.php?action=list`),
    ]);
    const [rateData, gwData, campData, dcData] = await Promise.all([rateRes.json(), gwRes.json(), campRes.json(), dcRes.json()]);

    // Rate
    if (rateData.ok && rateData.rate) {
      const r = rateData.rate;
      document.getElementById('rate-is-free').checked = !!r.is_free;
      document.getElementById('rate-amount').value    = r.amount || '';
      const currSel = document.getElementById('rate-currency');
      if (currSel) { for (let o of currSel.options) if (o.value === r.currency) { o.selected = true; break; } }
      toggleRateFree();
    }

    // Gateways
    if (gwData.ok && gwData.gateways) {
      const g = gwData.gateways;
      const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };
      const setSecretPlaceholder = (id, isSet) => {
        const el = document.getElementById(id);
        if (el && isSet) el.placeholder = '••••••••••• (set — leave blank to keep)';
      };

      // ── PayPal ──
      const ppEn = document.getElementById('gw-paypal-enabled');
      if (ppEn) { ppEn.checked = !!g.paypal_enabled; ppEn.dispatchEvent(new Event('change')); }
      setVal('gw-paypal-email', g.paypal_email);
      setVal('gw-paypal-me',    g.paypal_me_username);
      setVal('gw-paypal-mode',  g.paypal_mode || 'basic');
      setVal('gw-paypal-env',   g.paypal_environment || 'live');
      setVal('gw-paypal-client-id',  g.paypal_client_id);
      setVal('gw-paypal-webhook-id', g.paypal_webhook_id);
      setSecretPlaceholder('gw-paypal-client-secret', g.paypal_client_secret_set);
      setActiveTab('paypal', g.paypal_mode || 'basic');

      // ── Stripe ──
      const stEn = document.getElementById('gw-stripe-enabled');
      if (stEn) { stEn.checked = !!g.stripe_enabled; stEn.dispatchEvent(new Event('change')); }
      setVal('gw-stripe-pub',    g.stripe_pub_key);
      setVal('gw-stripe-pub-adv', g.stripe_pub_key);
      setVal('gw-stripe-mode',   g.stripe_mode || 'basic');
      setSecretPlaceholder('gw-stripe-secret',     g.stripe_secret_set);
      setSecretPlaceholder('gw-stripe-secret-adv', g.stripe_secret_set);
      setSecretPlaceholder('gw-stripe-webhook-secret', g.stripe_webhook_secret_set);
      setActiveTab('stripe', g.stripe_mode || 'basic');

      // ── Bitcoin ──
      const btcEn = document.getElementById('gw-btc-enabled');
      if (btcEn) { btcEn.checked = !!g.btc_enabled; btcEn.dispatchEvent(new Event('change')); }
      setVal('gw-btc-address',   g.btc_address);
      setVal('gw-btc-mode',      g.btc_mode || 'basic');
      setVal('gw-btcpay-server', g.btcpay_server_url);
      setVal('gw-btcpay-store',  g.btcpay_store_id);
      setSecretPlaceholder('gw-btcpay-api-key',        g.btcpay_api_key_set);
      setSecretPlaceholder('gw-btcpay-webhook-secret', g.btcpay_webhook_secret_set);
      setActiveTab('btc', g.btc_mode || 'basic');

      // ── Square ──
      const sqEn = document.getElementById('gw-square-enabled');
      if (sqEn) { sqEn.checked = !!g.square_enabled; sqEn.dispatchEvent(new Event('change')); }
      setVal('gw-square-app-id',          g.square_app_id);
      setVal('gw-square-location-id',     g.square_location_id);
      setVal('gw-square-app-id-adv',      g.square_app_id);
      setVal('gw-square-location-id-adv', g.square_location_id);
      setVal('gw-square-mode',            g.square_mode || 'basic');
      setVal('gw-square-env',             g.square_environment || 'production');
      setSecretPlaceholder('gw-square-token',     g.square_token_set);
      setSecretPlaceholder('gw-square-token-adv', g.square_token_set);
      setSecretPlaceholder('gw-square-webhook-sig', g.square_webhook_signature_key_set);
      setActiveTab('square', g.square_mode || 'basic');
    }

    // Webhook URLs (always populated — practitioner can copy even when disabled)
    if (gwData.webhook_urls) {
      const u = gwData.webhook_urls;
      const setUrl = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
      setUrl('gw-stripe-webhook-url', u.stripe);
      setUrl('gw-paypal-webhook-url', u.paypal);
      setUrl('gw-square-webhook-url', u.square);
      setUrl('gw-btcpay-webhook-url', u.btcpay);
    }

    // Campaigns
    if (campData.ok) renderCampaigns(campData.campaigns || []);

    // Discount codes
    if (dcData.ok) renderDiscountCodes(dcData.codes || []);

    // Wire up gateway toggles
    ['paypal','stripe','btc','square'].forEach(gw => {
      const chk = document.getElementById('gw-' + gw + '-enabled');
      const flds = document.getElementById('gw-' + gw + '-fields');
      if (chk && flds) {
        const sync = () => { flds.style.display = chk.checked ? 'block' : 'none'; };
        sync();
        chk.addEventListener('change', sync);
      }
    });

    // Load payment DPAs
    loadPaymentDpas();

    // Set today as default campaign start
    const fromEl = document.getElementById('campaign-from');
    if (fromEl && !fromEl.value) fromEl.value = new Date().toISOString().slice(0, 10);

    // Set today as default discount code start
    const dcFromEl = document.getElementById('dcode-from');
    if (dcFromEl && !dcFromEl.value) dcFromEl.value = new Date().toISOString().slice(0, 10);

  } catch(e) {
    console.error('loadPaymentsSection error', e);
  } finally {
    if (loading) loading.style.display = 'none';
    if (content) content.style.display = 'block';
  }
}

function toggleRateFree() {
  const isFree = document.getElementById('rate-is-free')?.checked;
  const wrap   = document.getElementById('rate-amount-wrap');
  if (wrap) wrap.style.opacity = isFree ? '0.4' : '1';
  const amountEl = document.getElementById('rate-amount');
  if (amountEl) amountEl.disabled = !!isFree;
}

async function saveRate() {
  const errEl  = document.getElementById('rate-error');
  const sucEl  = document.getElementById('rate-success');
  errEl.style.display = 'none'; sucEl.style.display = 'none';

  const fd = new FormData();
  fd.append('action',    'save_rate');
  fd.append('csrf_token', PAYMENTS_CSRF);
  fd.append('is_free',    document.getElementById('rate-is-free').checked ? '1' : '');
  fd.append('amount',     document.getElementById('rate-amount').value);
  fd.append('currency',   document.getElementById('rate-currency').value);

  try {
    const res  = await fetch('/api/payments.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) { sucEl.textContent = 'Rate saved.'; sucEl.style.display = 'block'; }
    else         { errEl.textContent = data.error || 'Could not save rate.'; errEl.style.display = 'block'; }
  } catch(e) { errEl.textContent = 'Network error.'; errEl.style.display = 'block'; }
}

async function saveGateways() {
  const errEl = document.getElementById('gw-error');
  const sucEl = document.getElementById('gw-success');
  errEl.style.display = 'none'; sucEl.style.display = 'none';

  const v = id => document.getElementById(id)?.value ?? '';
  const chk = id => document.getElementById(id)?.checked ? '1' : '';

  // Stripe Advanced has its own pub/secret inputs — when Advanced is the active
  // mode, prefer those over the Basic ones. Same idea for Square.
  const stripeMode = v('gw-stripe-mode') || 'basic';
  const stripePub  = stripeMode === 'advanced' ? (v('gw-stripe-pub-adv')    || v('gw-stripe-pub'))    : v('gw-stripe-pub');
  const stripeSec  = stripeMode === 'advanced' ? (v('gw-stripe-secret-adv') || v('gw-stripe-secret')) : v('gw-stripe-secret');

  const squareMode  = v('gw-square-mode') || 'basic';
  const squareApp   = squareMode === 'advanced' ? (v('gw-square-app-id-adv')      || v('gw-square-app-id'))     : v('gw-square-app-id');
  const squareTok   = squareMode === 'advanced' ? (v('gw-square-token-adv')       || v('gw-square-token'))      : v('gw-square-token');
  const squareLoc   = squareMode === 'advanced' ? (v('gw-square-location-id-adv') || v('gw-square-location-id')): v('gw-square-location-id');

  const fd = new FormData();
  fd.append('action',     'save_gateways');
  fd.append('csrf_token', PAYMENTS_CSRF);

  // PayPal
  fd.append('paypal_enabled',       chk('gw-paypal-enabled'));
  fd.append('paypal_mode',          v('gw-paypal-mode'));
  fd.append('paypal_email',         v('gw-paypal-email'));
  fd.append('paypal_me_username',   v('gw-paypal-me'));
  fd.append('paypal_environment',   v('gw-paypal-env'));
  fd.append('paypal_client_id',     v('gw-paypal-client-id'));
  fd.append('paypal_client_secret', v('gw-paypal-client-secret'));
  fd.append('paypal_webhook_id',    v('gw-paypal-webhook-id'));

  // Stripe
  fd.append('stripe_enabled',         chk('gw-stripe-enabled'));
  fd.append('stripe_mode',            stripeMode);
  fd.append('stripe_pub_key',         stripePub);
  fd.append('stripe_secret_key',      stripeSec);
  fd.append('stripe_webhook_secret',  v('gw-stripe-webhook-secret'));

  // Bitcoin
  fd.append('btc_enabled',           chk('gw-btc-enabled'));
  fd.append('btc_mode',              v('gw-btc-mode'));
  fd.append('btc_address',           v('gw-btc-address'));
  fd.append('btcpay_server_url',     v('gw-btcpay-server'));
  fd.append('btcpay_api_key',        v('gw-btcpay-api-key'));
  fd.append('btcpay_store_id',       v('gw-btcpay-store'));
  fd.append('btcpay_webhook_secret', v('gw-btcpay-webhook-secret'));

  // Square
  fd.append('square_enabled',     chk('gw-square-enabled'));
  fd.append('square_mode',        squareMode);
  fd.append('square_environment', v('gw-square-env'));
  fd.append('square_app_id',      squareApp);
  fd.append('square_token',       squareTok);
  fd.append('square_location_id', squareLoc);
  fd.append('square_webhook_signature_key', v('gw-square-webhook-sig'));

  try {
    const res  = await fetch('/api/payments.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) { sucEl.textContent = 'Payment settings saved.'; sucEl.style.display = 'block'; }
    else         { errEl.textContent = data.error || 'Could not save.'; errEl.style.display = 'block'; }
  } catch(e) { errEl.textContent = 'Network error.'; errEl.style.display = 'block'; }
}

function renderCampaigns(campaigns) {
  const list = document.getElementById('campaigns-list');
  if (!list) return;
  if (!campaigns.length) {
    list.innerHTML = '<p style="font-size:13px;color:var(--text-muted);">No campaigns yet. Create one below.</p>';
    return;
  }
  list.innerHTML = campaigns.map(c => {
    const activeColor = c.is_active ? '#4caf87' : 'var(--text-muted)';
    const dateRange   = c.valid_from + (c.valid_until ? ' → ' + c.valid_until : ' → ongoing');
    return `<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;border:1px solid var(--border);border-radius:8px;background:rgba(255,255,255,0.02);">
      <div>
        <div style="font-size:13.5px;font-weight:500;">${escHtmlProfile(c.label)}</div>
        <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px;">${escHtmlProfile(dateRange)} · <span style="color:${activeColor}">${c.discount_pct}% off</span> · <span style="color:${activeColor}">${c.is_active ? 'Active' : 'Inactive'}</span></div>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0;">
        <button data-edit-campaign='${JSON.stringify(c).replace(/'/g,"&#39;")}' class="btn btn-ghost sm" style="font-family:inherit;font-size:12px;padding:5px 10px;">Edit</button>
        <button data-delete-campaign="${c.id}" class="btn btn-ghost sm" style="font-family:inherit;font-size:12px;padding:5px 10px;color:var(--error-color,#e05c5c);">Delete</button>
      </div>
    </div>`;
  }).join('');
}

function escHtmlProfile(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Payment DPA Functions ──────────────────────────────────────────
const PAYMENT_DPA_TYPE_LABELS = {
  payment_stripe: 'Stripe', payment_paypal: 'PayPal',
  payment_square: 'Square', payment_custom: 'Custom Processor'
};

async function loadPaymentDpas() {
  const list = document.getElementById('payment-dpa-list');
  if (!list) return;
  try {
    const res  = await fetch('/api/dpa.php?action=list_mine');
    const data = await res.json();
    if (!data.ok) { list.innerHTML = ''; return; }
    const paymentDocs = (data.docs || []).filter(d => d.type.startsWith('payment_'));
    if (!paymentDocs.length) {
      list.innerHTML = '<p style="font-size:13px;color:var(--text-muted);margin:0;">No payment DPAs uploaded yet.</p>';
      return;
    }
    list.innerHTML = paymentDocs.map(d => `
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;border:1px solid rgba(78,203,138,0.25);border-radius:8px;background:rgba(78,203,138,0.05);">
        <div>
          <div style="font-size:13px;font-weight:600;color:#4ecb8a;">${escHtmlProfile(PAYMENT_DPA_TYPE_LABELS[d.type] || d.type)}</div>
          <div style="font-size:11.5px;color:var(--text-muted);margin-top:2px;">${escHtmlProfile(d.vendor_name)} &middot; ${escHtmlProfile(d.file_name)} &middot; Uploaded ${escHtmlProfile(d.uploaded_at.slice(0,10))}</div>
          ${d.notes ? `<div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${escHtmlProfile(d.notes)}</div>` : ''}
        </div>
        <button data-delete-payment-dpa="${d.id}" class="btn btn-ghost" style="font-family:inherit;font-size:11px;padding:4px 10px;color:#e05c5c;flex-shrink:0;">Delete</button>
      </div>`).join('');
  } catch(e) { console.error('loadPaymentDpas', e); }
}

async function uploadPaymentDpa() {
  const errEl = document.getElementById('payment-dpa-error');
  const sucEl = document.getElementById('payment-dpa-success');
  errEl.style.display = 'none'; sucEl.style.display = 'none';

  const file = document.getElementById('payment-dpa-file').files[0];
  if (!file) { errEl.textContent = 'Select a file first.'; errEl.style.display = ''; return; }
  const vendor = document.getElementById('payment-dpa-vendor').value.trim();
  if (!vendor) { errEl.textContent = 'Enter the vendor/processor name.'; errEl.style.display = ''; return; }

  const fd = new FormData();
  fd.append('action',      'upload_practitioner');
  fd.append('csrf_token',  PAYMENTS_CSRF);
  fd.append('type',        document.getElementById('payment-dpa-type').value);
  fd.append('vendor_name', vendor);
  fd.append('notes',       document.getElementById('payment-dpa-notes').value.trim());
  fd.append('dpa_file',    file);

  try {
    const res  = await fetch('/api/dpa.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      sucEl.textContent = 'DPA uploaded.';
      sucEl.style.display = '';
      document.getElementById('payment-dpa-file').value   = '';
      document.getElementById('payment-dpa-vendor').value = '';
      document.getElementById('payment-dpa-notes').value  = '';
      loadPaymentDpas();
    } else {
      errEl.textContent = data.error || 'Upload failed.';
      errEl.style.display = '';
    }
  } catch(e) { errEl.textContent = 'Network error.'; errEl.style.display = ''; }
}

async function deletePaymentDpa(id) {
  if (!confirm('Delete this DPA document? This cannot be undone.')) return;
  const fd = new FormData();
  fd.append('action',     'delete');
  fd.append('id',         id);
  fd.append('csrf_token', PAYMENTS_CSRF);
  try {
    const res  = await fetch('/api/dpa.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) loadPaymentDpas();
  } catch(e) { console.error('deletePaymentDpa', e); }
}

function editCampaign(c) {
  document.getElementById('campaign-edit-id').value  = c.id;
  document.getElementById('campaign-label').value    = c.label;
  document.getElementById('campaign-pct').value      = c.discount_pct;
  document.getElementById('campaign-from').value     = c.valid_from;
  document.getElementById('campaign-until').value    = c.valid_until || '';
  document.getElementById('campaign-active').checked = !!c.is_active;
  document.getElementById('campaign-form-title').textContent = 'Edit Campaign';
  document.getElementById('campaign-save-btn').textContent   = 'Save Changes';
  document.getElementById('campaign-cancel-btn').style.display = 'inline-flex';
  document.getElementById('campaign-form').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function resetCampaignForm() {
  document.getElementById('campaign-edit-id').value  = '0';
  document.getElementById('campaign-label').value    = '';
  document.getElementById('campaign-pct').value      = '';
  document.getElementById('campaign-from').value     = new Date().toISOString().slice(0,10);
  document.getElementById('campaign-until').value    = '';
  document.getElementById('campaign-active').checked = true;
  document.getElementById('campaign-form-title').textContent = 'New Campaign';
  document.getElementById('campaign-save-btn').textContent   = 'Add Campaign';
  document.getElementById('campaign-cancel-btn').style.display = 'none';
  document.getElementById('campaign-error').style.display = 'none';
}

async function saveCampaign() {
  const errEl = document.getElementById('campaign-error');
  errEl.style.display = 'none';

  const fd = new FormData();
  fd.append('action',       'save_campaign');
  fd.append('csrf_token',    PAYMENTS_CSRF);
  fd.append('id',            document.getElementById('campaign-edit-id').value);
  fd.append('label',         document.getElementById('campaign-label').value);
  fd.append('discount_pct',  document.getElementById('campaign-pct').value);
  fd.append('valid_from',    document.getElementById('campaign-from').value);
  fd.append('valid_until',   document.getElementById('campaign-until').value);
  fd.append('is_active',     document.getElementById('campaign-active').checked ? '1' : '');

  try {
    const res  = await fetch('/api/payments.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      resetCampaignForm();
      // Reload campaign list
      const campRes = await fetch(`/api/payments.php?action=list_campaigns&csrf=${PAYMENTS_CSRF}`);
      const campData = await campRes.json();
      if (campData.ok) renderCampaigns(campData.campaigns || []);
    } else {
      errEl.textContent = data.error || 'Could not save campaign.';
      errEl.style.display = 'block';
    }
  } catch(e) { errEl.textContent = 'Network error.'; errEl.style.display = 'block'; }
}

async function deleteCampaign(id) {
  if (!confirm('Delete this campaign?')) return;
  const fd = new FormData();
  fd.append('action',     'delete_campaign');
  fd.append('csrf_token',  PAYMENTS_CSRF);
  fd.append('id',          id);
  try {
    const res  = await fetch('/api/payments.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      const campRes  = await fetch(`/api/payments.php?action=list_campaigns&csrf=${PAYMENTS_CSRF}`);
      const campData = await campRes.json();
      if (campData.ok) renderCampaigns(campData.campaigns || []);
    }
  } catch(e) { console.error('deleteCampaign error', e); }
}

// ── Discount Codes ─────────────────────────────────────────────
function renderDiscountCodes(codes) {
  const list = document.getElementById('dcodes-list');
  if (!list) return;
  if (!codes.length) {
    list.innerHTML = '<p style="font-size:13px;color:var(--text-muted);">No discount codes yet. Create one below.</p>';
    return;
  }
  list.innerHTML = codes.map(c => {
    const activeColor  = c.is_active ? '#4caf87' : 'var(--text-muted)';
    const expiryLabel  = c.valid_until ? c.valid_from + ' → ' + c.valid_until : c.valid_from + ' → no expiry';
    const totalLabel   = c.max_total_uses   ? `${c.total_uses} / ${c.max_total_uses} uses` : `${c.total_uses} uses (unlimited)`;
    const clientLabel  = c.max_uses_per_client ? `${c.max_uses_per_client} per client` : 'unlimited per client';
    return `<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:10px 14px;border:1px solid var(--border);border-radius:8px;background:rgba(255,255,255,0.02);">
      <div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;">
          <span style="font-size:13.5px;font-weight:600;font-family:monospace;letter-spacing:.04em;">${escHtmlProfile(c.code)}</span>
          <span style="font-size:12px;font-weight:500;color:${activeColor};background:${c.is_active ? 'rgba(76,175,135,0.12)' : 'rgba(150,150,150,0.1)'};border-radius:4px;padding:1px 7px;">${c.discount_pct}% off · ${c.is_active ? 'Active' : 'Inactive'}</span>
        </div>
        <div style="font-size:11.5px;color:var(--text-muted);">${escHtmlProfile(expiryLabel)} · ${escHtmlProfile(totalLabel)} · ${escHtmlProfile(clientLabel)}</div>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0;">
        <button data-edit-dcode='${JSON.stringify(c).replace(/'/g,"&#39;")}' class="btn btn-ghost sm" style="font-family:inherit;font-size:12px;padding:5px 10px;">Edit</button>
        <button data-delete-dcode="${c.id}" class="btn btn-ghost sm" style="font-family:inherit;font-size:12px;padding:5px 10px;color:var(--error-color,#e05c5c);">Delete</button>
      </div>
    </div>`;
  }).join('');
}

function editDiscountCode(c) {
  document.getElementById('dcode-edit-id').value      = c.id;
  document.getElementById('dcode-code').value         = c.code;
  document.getElementById('dcode-pct').value          = c.discount_pct;
  document.getElementById('dcode-from').value         = c.valid_from;
  document.getElementById('dcode-until').value        = c.valid_until || '';
  document.getElementById('dcode-active').checked     = !!parseInt(c.is_active);

  const noExpiry = document.getElementById('dcode-no-expiry');
  noExpiry.checked = !c.valid_until;
  document.getElementById('dcode-until').disabled = noExpiry.checked;

  const noTotal = document.getElementById('dcode-no-total-limit');
  noTotal.checked = !c.max_total_uses;
  document.getElementById('dcode-max-total').value    = c.max_total_uses || '';
  document.getElementById('dcode-max-total').disabled = noTotal.checked;

  const noClient = document.getElementById('dcode-no-client-limit');
  noClient.checked = !c.max_uses_per_client;
  document.getElementById('dcode-max-client').value    = c.max_uses_per_client || '';
  document.getElementById('dcode-max-client').disabled = noClient.checked;

  document.getElementById('dcode-form-title').textContent  = 'Edit Discount Code';
  document.getElementById('dcode-save-btn').textContent    = 'Save Changes';
  document.getElementById('dcode-cancel-btn').style.display = 'inline-flex';
  document.getElementById('dcode-form').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function resetDiscountCodeForm() {
  document.getElementById('dcode-edit-id').value     = '0';
  document.getElementById('dcode-code').value        = '';
  document.getElementById('dcode-pct').value         = '';
  document.getElementById('dcode-from').value        = new Date().toISOString().slice(0,10);
  document.getElementById('dcode-until').value       = '';
  document.getElementById('dcode-active').checked    = true;

  ['dcode-no-expiry','dcode-no-total-limit','dcode-no-client-limit'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.checked = false;
  });
  document.getElementById('dcode-until').disabled     = false;
  document.getElementById('dcode-max-total').value    = '';
  document.getElementById('dcode-max-total').disabled = false;
  document.getElementById('dcode-max-client').value   = '';
  document.getElementById('dcode-max-client').disabled = false;

  document.getElementById('dcode-form-title').textContent   = 'New Discount Code';
  document.getElementById('dcode-save-btn').textContent     = 'Create Code';
  document.getElementById('dcode-cancel-btn').style.display = 'none';
  document.getElementById('dcode-error').style.display      = 'none';
}

async function saveDiscountCode() {
  const errEl = document.getElementById('dcode-error');
  errEl.style.display = 'none';

  const fd = new FormData();
  fd.append('action',              'save');
  fd.append('csrf_token',          PAYMENTS_CSRF);
  fd.append('id',                  document.getElementById('dcode-edit-id').value);
  fd.append('code',                document.getElementById('dcode-code').value);
  fd.append('discount_pct',        document.getElementById('dcode-pct').value);
  fd.append('valid_from',          document.getElementById('dcode-from').value);
  fd.append('valid_until',         document.getElementById('dcode-no-expiry').checked ? '' : document.getElementById('dcode-until').value);
  fd.append('max_total_uses',      document.getElementById('dcode-no-total-limit').checked ? '' : document.getElementById('dcode-max-total').value);
  fd.append('max_uses_per_client', document.getElementById('dcode-no-client-limit').checked ? '' : document.getElementById('dcode-max-client').value);
  fd.append('is_active',           document.getElementById('dcode-active').checked ? '1' : '');

  try {
    const res  = await fetch('/api/discount_codes.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      resetDiscountCodeForm();
      const r = await fetch('/api/discount_codes.php?action=list');
      const d = await r.json();
      if (d.ok) renderDiscountCodes(d.codes || []);
    } else {
      errEl.textContent = data.error || 'Could not save code.';
      errEl.style.display = 'block';
    }
  } catch(e) { errEl.textContent = 'Network error.'; errEl.style.display = 'block'; }
}

async function deleteDiscountCode(id) {
  if (!confirm('Delete this discount code? This cannot be undone.')) return;
  const fd = new FormData();
  fd.append('action',     'delete');
  fd.append('csrf_token',  PAYMENTS_CSRF);
  fd.append('id',          id);
  try {
    const res  = await fetch('/api/discount_codes.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      const r = await fetch('/api/discount_codes.php?action=list');
      const d = await r.json();
      if (d.ok) renderDiscountCodes(d.codes || []);
    }
  } catch(e) { console.error('deleteDiscountCode error', e); }
}

// ── Role Icon Picker ─────────────────────────────────────────────
(function() {
  const grid = document.getElementById('role-icon-grid');
  if (!grid) return; // licensed practitioners see locked UI — no grid

  const valueInput = document.getElementById('role-icon-value');
  const hint = document.getElementById('role-icon-hint');

  // Full icon catalog: { key, label, file, triggers }
  // triggers: which practice_types or inclusivity_tags unlock this icon
  // 'always' = always available
  const ICONS = [
    { key: 'ribbon',               label: 'Green Ribbon (Default)', file: 'ribbon.png',               triggers: ['always'] },
    { key: 'heart',                label: 'Heart',                  file: 'heart.png',                triggers: ['life_coaching','peer_support'] },
    { key: 'philosophy',           label: 'Philosophy',             file: 'philosophy.png',           triggers: ['philosophical_counseling'] },
    { key: 'philosophy-1',         label: 'Philosophy (scroll)',    file: 'philosophy-1.png',         triggers: ['philosophical_counseling'] },
    { key: 'philosophy-2',         label: 'Philosophy (lamp)',      file: 'philosophy-2.png',         triggers: ['philosophical_counseling'] },
    { key: 'philosophy-3',         label: 'Philosophy (tree)',      file: 'philosophy-3.png',         triggers: ['philosophical_counseling'] },
    { key: 'grief-support',        label: 'Grief Support',          file: 'grief-support.png',        triggers: ['grief_support'] },
    { key: 'lotus',                label: 'Lotus',                  file: 'lotus.png',                triggers: ['mindfulness'] },
    { key: 'meditation',           label: 'Meditation',             file: 'meditation.png',           triggers: ['mindfulness'] },
    { key: 'yin-yang',             label: 'Yin Yang',               file: 'yin-yang.png',             triggers: ['mindfulness'] },
    { key: 'emotional-intelligence', label: 'Emotional Intelligence', file: 'emotional-intelligence.png', triggers: ['peer_support'] },
    { key: 'social-services',      label: 'Social Services',        file: 'social-services.png',      triggers: ['peer_support'] },
    { key: 'brain',                label: 'Brain',                  file: 'brain.png',                triggers: ['trauma_informed','addiction_recovery','cbt','dbt'] },
    { key: 'lgbt',                 label: 'LGBTQIA+',               file: 'lgbt.png',                 triggers: ['lgbtqia_affirming'] },
    { key: 'faith',                label: 'Faith',                  file: 'faith.png',                triggers: ['christian_friendly','pastoral_care','spiritual_direction'] },
    { key: 'bible',                label: 'Bible',                  file: 'bible.png',                triggers: ['christian_friendly','pastoral_care','spiritual_direction'] },
    { key: 'christianity',         label: 'Christianity',           file: 'christianity.png',         triggers: ['christian_friendly','pastoral_care','spiritual_direction'] },
  ];

  // Read current saved selection from server
  let currentKey = valueInput.value || 'ribbon';

  function getActiveTriggers() {
    const active = new Set();
    // Practice type checkboxes
    document.querySelectorAll('input[name="practice_types[]"]:checked').forEach(cb => active.add(cb.value));
    // Inclusivity tags (in the other section — read if present in DOM)
    document.querySelectorAll('input[name="inclusivity_tags[]"]:checked').forEach(cb => active.add(cb.value));
    return active;
  }

  function buildGrid() {
    const active = getActiveTriggers();
    grid.innerHTML = '';
    let selectedStillAvailable = false;

    ICONS.forEach(icon => {
      const isAlways = icon.triggers.includes('always');
      const isEnabled = isAlways || icon.triggers.some(t => active.has(t));
      if (!isEnabled) return;

      const isSelected = currentKey === icon.key;
      if (isSelected) selectedStillAvailable = true;

      const tile = document.createElement('label');
      tile.title = icon.label;
      tile.style.cssText = [
        'display:flex;flex-direction:column;align-items:center;gap:6px;',
        'cursor:pointer;padding:10px 8px;border-radius:10px;border:2px solid;',
        'width:80px;text-align:center;transition:border-color 0.15s,background 0.15s;',
        isSelected
          ? 'border-color:var(--accent);background:rgba(124,106,247,0.12);'
          : 'border-color:var(--border);background:rgba(255,255,255,0.02);'
      ].join('');

      const img = document.createElement('img');
      img.src = '/assets/role_icons/' + icon.file;
      img.style.cssText = 'width:40px;height:40px;object-fit:contain;';
      img.alt = icon.label;

      const lbl = document.createElement('span');
      lbl.textContent = icon.label;
      lbl.style.cssText = 'font-size:10px;color:var(--text-muted);line-height:1.3;';

      const radio = document.createElement('input');
      radio.type  = 'radio';
      radio.name  = '_role_icon_pick';
      radio.value = icon.key;
      radio.style.cssText = 'display:none;';
      if (isSelected) radio.checked = true;

      tile.appendChild(img);
      tile.appendChild(lbl);
      tile.appendChild(radio);

      tile.addEventListener('click', () => {
        currentKey = icon.key;
        valueInput.value = icon.key;
        buildGrid();
      });

      grid.appendChild(tile);
    });

    // If the saved icon is no longer available (practice type was deselected), fall back to ribbon
    if (!selectedStillAvailable) {
      currentKey = 'ribbon';
      valueInput.value = 'ribbon';
      buildGrid();
      return;
    }

    hint.style.display = active.size > 0 ? 'none' : 'block';
  }

  // Rebuild grid when practice type checkboxes change
  document.querySelectorAll('input[name="practice_types[]"]').forEach(cb => {
    cb.addEventListener('change', buildGrid);
  });
  // Also listen for inclusivity tag changes (may be on a different section, so use document)
  document.addEventListener('change', function(e) {
    if (e.target && e.target.name === 'inclusivity_tags[]') buildGrid();
  });

  buildGrid();
})();

// Auto-activate section from URL hash, saved state, or default
const savedSection = <?php
  if (isset($_GET['about_saved']))        echo "'about'";
  elseif (isset($_GET['tz_saved']))       echo "'availability'";
  elseif (isset($_GET['identity_saved'])) echo "'identity'";
  elseif (isset($_GET['inclusivity_saved']) || isset($_GET['languages_saved'])) echo "'inclusivity'";
  elseif (isset($_GET['colors_saved']))   echo "'colors'";
  elseif ($profileError || $pwError || $pwSuccess) echo "'security'";
  else echo "null";
?>;
var aiCfgLoaded = false; // declared before showSection so it's always defined
showSection(location.hash.slice(1) || savedSection || 'profile');

// ── AI Integration JS ─────────────────────────────────────────────
<?php if ($aiPlatformEnabled): ?>
const AI_CSRF = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
let aiSelectedModel = '';
let aiAllModels = [];
let aiHasCustomAvatar = false;

async function aiLoad() {
  aiCfgLoaded = true;
  try {
    const res = await fetch('/api/ai_config.php?action=load');
    const data = await res.json();
    document.getElementById('ai-loading').style.display = 'none';
    document.getElementById('ai-content').style.display = '';

    // Show platform-disabled callout when ai_enabled = 0 platform-wide.
    // If practitioner is also an admin, surface the direct path to enable.
    if (!data.platform_enabled) {
      const callout = document.getElementById('ai-platform-disabled');
      const link    = document.getElementById('ai-platform-disabled-link');
      const msg     = document.getElementById('ai-platform-disabled-msg');
      if (callout) callout.style.display = '';
      if (data.is_admin) {
        if (msg)  msg.textContent = 'AI integration is currently disabled platform-wide. Because you are an admin on this install, you can enable it directly in the admin panel.';
        if (link) link.style.display = '';
      }
    }

    document.getElementById('ai-enabled-toggle').checked = !!data.enabled;
    document.getElementById('ai-vendor').value = data.vendor || 'openai';
    document.getElementById('ai-api-key').value = data.api_key_set ? '••••••••' : '';
    aiSelectedModel = data.model || '';
    document.getElementById('ai-model-filter').value = aiSelectedModel;
    if (aiSelectedModel) {
      document.getElementById('ai-model-selected').textContent = 'Selected: ' + aiSelectedModel;
    }
    document.getElementById('ai-assistant-name').value = data.assistant_name || '';
    document.getElementById('ai-system-prompt').value  = data.assistant_prompt || '';

    // Scopes
    document.querySelectorAll('.ai-scope-toggle').forEach(cb => {
      const scope = cb.dataset.scope;
      cb.checked = !!(data['scope_' + scope]);
    });

    // Avatar
    if (data.assistant_avatar) {
      document.getElementById('ai-avatar-preview').src = '/' + data.assistant_avatar + '?t=' + Date.now();
      document.getElementById('ai-avatar-delete-btn').style.display = '';
      aiHasCustomAvatar = true;
    }

    // Load DPA status for current vendor
    loadAiDpaStatus();
  } catch(e) {
    document.getElementById('ai-loading').style.display = 'none';
    document.getElementById('ai-content').style.display = '';
    aiShowError('Failed to load AI configuration.');
  }
}

function aiVendorChanged() {
  aiAllModels = [];
  document.getElementById('ai-model-list').innerHTML = '<div style="padding:12px;color:var(--text-muted);font-size:13px;">Click "Fetch Models" to load available models.</div>';
  document.getElementById('ai-model-filter').value = '';
  aiSelectedModel = '';
  document.getElementById('ai-model-selected').textContent = '';
  loadAiDpaStatus();
}

async function aiFetchModels() {
  const vendor = document.getElementById('ai-vendor').value;
  const apiKey = document.getElementById('ai-api-key').value;
  const listEl = document.getElementById('ai-model-list');
  listEl.innerHTML = '<div style="padding:12px;color:var(--text-muted);font-size:13px;">Loading…</div>';
  try {
    const res = await fetch('/api/ai_config.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({action:'fetch_models', vendor, api_key: apiKey, csrf_token: AI_CSRF})
    });
    const data = await res.json();
    if (!data.ok) { listEl.innerHTML = '<div style="padding:12px;color:#ef9a9a;font-size:13px;">' + (data.error||'Failed') + '</div>'; return; }
    aiAllModels = data.models || [];
    aiRenderModelList(aiAllModels);
  } catch(e) {
    listEl.innerHTML = '<div style="padding:12px;color:#ef9a9a;font-size:13px;">Request failed.</div>';
  }
}

function aiRenderModelList(models) {
  const listEl = document.getElementById('ai-model-list');
  if (!models.length) { listEl.innerHTML = '<div style="padding:12px;color:var(--text-muted);font-size:13px;">No models found.</div>'; return; }
  if (!document.getElementById('ai-model-list-style')) {
    const _s = document.createElement('style');
    _s.id = 'ai-model-list-style';
    _s.textContent = '#ai-model-list [data-select-model]:hover{background:rgba(255,255,255,0.06)!important;}';
    document.head.appendChild(_s);
  }
  listEl.innerHTML = models.map(m =>
    `<div data-select-model="${m.id}" style="padding:9px 12px;cursor:pointer;font-size:13px;${aiSelectedModel===m.id?'background:rgba(124,106,247,0.2);color:#a89ff7;':''}border-bottom:1px solid rgba(255,255,255,0.05);">${m.name}</div>`
  ).join('');
}

function aiFilterModels() {
  const q = document.getElementById('ai-model-filter').value.toLowerCase();
  aiSelectedModel = document.getElementById('ai-model-filter').value; // allow typing a model directly
  document.getElementById('ai-model-selected').textContent = aiSelectedModel ? 'Selected: ' + aiSelectedModel : '';
  const filtered = q ? aiAllModels.filter(m => m.id.toLowerCase().includes(q) || m.name.toLowerCase().includes(q)) : aiAllModels;
  if (aiAllModels.length) aiRenderModelList(filtered);
}

function aiSelectModel(id) {
  aiSelectedModel = id;
  document.getElementById('ai-model-filter').value = id;
  document.getElementById('ai-model-selected').textContent = 'Selected: ' + id;
  aiRenderModelList(aiAllModels);
}

async function aiUploadAvatar(input) {
  const file = input.files[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('avatar', file);
  fd.append('csrf_token', AI_CSRF);
  fd.append('action', 'save_avatar');
  try {
    const res = await fetch('/api/ai_config.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.ok) {
      document.getElementById('ai-avatar-preview').src = '/' + data.path + '?t=' + Date.now();
      document.getElementById('ai-avatar-delete-btn').style.display = '';
      aiHasCustomAvatar = true;
    } else {
      aiShowError(data.error || 'Upload failed.');
    }
  } catch(e) { aiShowError('Upload failed.'); }
  input.value = '';
}

async function aiDeleteAvatar() {
  if (!confirm('Remove custom assistant avatar?')) return;
  const res = await fetch('/api/ai_config.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'delete_avatar', csrf_token: AI_CSRF})
  });
  const data = await res.json();
  if (data.ok) {
    document.getElementById('ai-avatar-preview').src = '/assets/images/ai.png';
    document.getElementById('ai-avatar-delete-btn').style.display = 'none';
    aiHasCustomAvatar = false;
  }
}

function aiPromptFileDrop(e) {
  e.preventDefault();
  const file = e.dataTransfer.files[0];
  if (!file || !file.name.endsWith('.txt')) return;
  const reader = new FileReader();
  reader.onload = ev => { document.getElementById('ai-system-prompt').value = ev.target.result; };
  reader.readAsText(file);
}

async function aiSave() {
  const apiKey = document.getElementById('ai-api-key').value;
  const body = {
    action:            'save',
    csrf_token:        AI_CSRF,
    enabled:           document.getElementById('ai-enabled-toggle').checked ? 1 : 0,
    vendor:            document.getElementById('ai-vendor').value,
    api_key:           apiKey === '••••••••' ? '' : apiKey,
    model:             aiSelectedModel || document.getElementById('ai-model-filter').value,
    assistant_name:    document.getElementById('ai-assistant-name').value,
    assistant_prompt:  document.getElementById('ai-system-prompt').value,
  };
  document.querySelectorAll('.ai-scope-toggle').forEach(cb => {
    body['scope_' + cb.dataset.scope] = cb.checked ? 1 : 0;
  });
  try {
    const res  = await fetch('/api/ai_config.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
    const data = await res.json();
    if (data.ok) {
      aiShowSuccess('AI configuration saved.');
    } else {
      aiShowError(data.error || 'Save failed.');
    }
  } catch(e) { aiShowError('Request failed.'); }
}

function aiShowError(msg) {
  const el = document.getElementById('ai-error');
  el.textContent = msg; el.style.display = '';
  setTimeout(() => el.style.display = 'none', 6000);
}
function aiShowSuccess(msg) {
  const el = document.getElementById('ai-success');
  el.textContent = msg; el.style.display = '';
  setTimeout(() => el.style.display = 'none', 4000);
}

// ── AI DPA Functions ──────────────────────────────────────────────
const AI_VENDOR_DPA_TYPE = {
  openai: 'ai_openai', anthropic: 'ai_anthropic',
  google: 'ai_google', cohere: 'ai_cohere'
};
const AI_VENDOR_LABELS = {
  openai: 'OpenAI', anthropic: 'Anthropic', google: 'Google AI', cohere: 'Cohere'
};

async function loadAiDpaStatus() {
  const statusEl  = document.getElementById('ai-dpa-status');
  const uploadWrap = document.getElementById('ai-dpa-upload-wrap');
  if (!statusEl) return;

  const vendor    = document.getElementById('ai-vendor')?.value || 'openai';
  const dpaType   = AI_VENDOR_DPA_TYPE[vendor] || 'ai_custom';
  const vendorLabel = AI_VENDOR_LABELS[vendor] || vendor;

  statusEl.textContent = 'Checking DPA status…';
  try {
    const res  = await fetch(`/api/dpa.php?action=check&type=${encodeURIComponent(dpaType)}`);
    const data = await res.json();
    if (data.has_dpa) {
      statusEl.innerHTML = `<span style="color:#4ecb8a;font-weight:600;">&#10003; DPA on file for ${escHtmlProfile(vendorLabel)}.</span> <button data-dpa-replace class="btn btn-ghost" style="font-family:inherit;font-size:11px;padding:3px 8px;margin-left:8px;">Replace</button>`;
      uploadWrap.style.display = 'none';
    } else {
      statusEl.innerHTML = `<span style="color:#e07a30;font-weight:600;">&#9888; No DPA on file for ${escHtmlProfile(vendorLabel)}.</span> Upload one below before enabling AI.`;
      uploadWrap.style.display = '';
    }
  } catch(e) {
    statusEl.textContent = 'Could not check DPA status.';
  }
}

async function uploadAiDpa() {
  const errEl = document.getElementById('ai-dpa-error');
  const sucEl = document.getElementById('ai-dpa-success');
  errEl.style.display = 'none'; sucEl.style.display = 'none';

  const file = document.getElementById('ai-dpa-file').files[0];
  if (!file) { errEl.textContent = 'Select a file first.'; errEl.style.display = ''; return; }
  const vendor     = document.getElementById('ai-vendor')?.value || 'openai';
  const vendorName = document.getElementById('ai-dpa-vendor').value.trim();
  if (!vendorName) { errEl.textContent = 'Enter the vendor name.'; errEl.style.display = ''; return; }

  const fd = new FormData();
  fd.append('action',      'upload_practitioner');
  fd.append('csrf_token',  AI_CSRF);
  fd.append('type',        AI_VENDOR_DPA_TYPE[vendor] || 'ai_custom');
  fd.append('vendor_name', vendorName);
  fd.append('notes',       document.getElementById('ai-dpa-notes').value.trim());
  fd.append('dpa_file',    file);

  try {
    const res  = await fetch('/api/dpa.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      sucEl.textContent = 'DPA uploaded.';
      sucEl.style.display = '';
      document.getElementById('ai-dpa-file').value   = '';
      document.getElementById('ai-dpa-vendor').value = '';
      document.getElementById('ai-dpa-notes').value  = '';
      loadAiDpaStatus();
    } else {
      errEl.textContent = data.error || 'Upload failed.';
      errEl.style.display = '';
    }
  } catch(e) { errEl.textContent = 'Network error.'; errEl.style.display = ''; }
}

// AI listeners
document.getElementById('ai-vendor')?.addEventListener('change', aiVendorChanged);
document.getElementById('ai-model-filter')?.addEventListener('input', aiFilterModels);
document.getElementById('ai-fetch-models-btn')?.addEventListener('click', aiFetchModels);
document.getElementById('ai-avatar-pick-btn')?.addEventListener('click', () => document.getElementById('ai-avatar-file').click());
document.getElementById('ai-avatar-file')?.addEventListener('change', function() { aiUploadAvatar(this); });
document.getElementById('ai-avatar-delete-btn')?.addEventListener('click', aiDeleteAvatar);
document.getElementById('ai-upload-dpa-btn')?.addEventListener('click', uploadAiDpa);
document.getElementById('ai-save-btn')?.addEventListener('click', aiSave);
document.getElementById('ai-model-list')?.addEventListener('click', e => {
  const item = e.target.closest('[data-select-model]');
  if (item) aiSelectModel(item.dataset.selectModel);
});
document.getElementById('ai-dpa-status')?.addEventListener('click', e => {
  if (e.target.closest('[data-dpa-replace]')) document.getElementById('ai-dpa-upload-wrap').style.display = '';
});
document.getElementById('ai-system-prompt')?.addEventListener('dragover', e => e.preventDefault());
document.getElementById('ai-system-prompt')?.addEventListener('drop', aiPromptFileDrop);
<?php endif; ?>

// ── Media Recommendations JS ──────────────────────────────────────
const MR_CSRF = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
let mrCfgLoaded = false;

async function mrLoad() {
  mrCfgLoaded = true;
  try {
    const res  = await fetch('/api/media_config.php?action=load');
    const data = await res.json();
    document.getElementById('mr-loading').style.display = 'none';
    document.getElementById('mr-content').style.display = '';

    document.getElementById('mr-open-library').checked     = !!data.open_library;
    document.getElementById('mr-google-books-toggle').checked = !!data.google_books_enabled;
    mrToggleGoogleBooks();
    if (data.google_books_key_set) {
      document.getElementById('mr-google-books-key').placeholder = '••••••••  (key saved)';
    }

    document.getElementById('mr-tmdb-toggle').checked = !!data.tmdb_enabled;
    mrToggleTmdb();
    if (data.tmdb_key_set) {
      document.getElementById('mr-tmdb-key').placeholder = '••••••••  (key saved)';
    }

    document.getElementById('mr-amazon-toggle').checked = !!data.amazon_enabled;
    mrToggleAmazon();
    if (data.amazon_tag)             document.getElementById('mr-amazon-tag').value        = data.amazon_tag;
    if (data.amazon_access_key_set)  document.getElementById('mr-amazon-access-key').placeholder = '••••••••  (key saved)';
    if (data.amazon_secret_key_set)  document.getElementById('mr-amazon-secret-key').placeholder = '••••••••  (key saved)';
  } catch(e) {
    document.getElementById('mr-loading').style.display = 'none';
    document.getElementById('mr-content').style.display = '';
    mrShowError('Failed to load Media Recommendations configuration.');
  }
}

function mrToggleGoogleBooks() {
  const on = document.getElementById('mr-google-books-toggle').checked;
  document.getElementById('mr-google-books-fields').style.display = on ? '' : 'none';
}
function mrToggleTmdb() {
  const on = document.getElementById('mr-tmdb-toggle').checked;
  document.getElementById('mr-tmdb-fields').style.display = on ? '' : 'none';
}
function mrToggleAmazon() {
  const on = document.getElementById('mr-amazon-toggle').checked;
  document.getElementById('mr-amazon-fields').style.display = on ? '' : 'none';
}

async function mrSave() {
  const googleKey = document.getElementById('mr-google-books-key').value;
  const tmdbKey   = document.getElementById('mr-tmdb-key').value;
  const body = {
    action:               'save',
    csrf_token:           MR_CSRF,
    open_library:         document.getElementById('mr-open-library').checked              ? 1 : 0,
    google_books_enabled: document.getElementById('mr-google-books-toggle').checked       ? 1 : 0,
    google_books_key:     (googleKey && googleKey !== '••••••••  (key saved)') ? googleKey : '',
    tmdb_enabled:         document.getElementById('mr-tmdb-toggle').checked               ? 1 : 0,
    tmdb_key:             (tmdbKey && tmdbKey !== '••••••••  (key saved)')     ? tmdbKey   : '',
    amazon_enabled:       document.getElementById('mr-amazon-toggle').checked             ? 1 : 0,
    amazon_tag:           document.getElementById('mr-amazon-tag').value.trim(),
    amazon_access_key:    document.getElementById('mr-amazon-access-key').value.trim(),
    amazon_secret_key:    document.getElementById('mr-amazon-secret-key').value.trim(),
  };
  try {
    const res  = await fetch('/api/media_config.php', {
      method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body)
    });
    const data = await res.json();
    if (data.ok) {
      mrShowSuccess('Media Recommendation settings saved.');
    } else {
      mrShowError(data.error || 'Save failed.');
    }
  } catch(e) { mrShowError('Request failed.'); }
}

function mrShowError(msg) {
  const el = document.getElementById('mr-error');
  el.textContent = msg; el.style.display = '';
  setTimeout(() => el.style.display = 'none', 7000);
}
function mrShowSuccess(msg) {
  const el = document.getElementById('mr-success');
  el.textContent = msg; el.style.display = '';
  setTimeout(() => el.style.display = 'none', 4000);
}

// ── CSP: Event Listeners replacing inline handlers ────────────────────────

// Nav delegation
document.querySelectorAll('.prof-nav-item').forEach(btn => {
  btn.addEventListener('click', () => showSection(btn.dataset.section, btn));
});

// Profile avatar upload
document.getElementById('avatar_upload')?.addEventListener('change', function() { handleUpload(this); });

// Identity selects
document.getElementById('gender-select')?.addEventListener('change', function() { updateGenderIcon(this.value); });
document.getElementById('sexuality-select')?.addEventListener('change', function() { updateSexualityIcon(this.value); });
document.getElementById('location-select')?.addEventListener('change', function() { updateFlag(this.value); });

// Language tags
document.getElementById('lang-tag-list')?.addEventListener('click', e => {
  const btn = e.target.closest('.lang-tag button');
  if (btn) removeLangTag(btn);
});
document.getElementById('add-lang-tag-btn')?.addEventListener('click', addLangTag);

// Availability
document.getElementById('save-schedule-btn')?.addEventListener('click', saveSchedule);
document.getElementById('add-exclusion-btn')?.addEventListener('click', addExclusion);
document.getElementById('exclusions-list')?.addEventListener('click', e => {
  const btn = e.target.closest('.excl-tag button');
  if (btn) removeExclusion(btn.closest('.excl-tag').dataset.date, btn);
});

// Rate
document.getElementById('rate-is-free')?.addEventListener('change', toggleRateFree);
document.getElementById('save-rate-btn')?.addEventListener('click', saveRate);

// Gateways & DPA
document.getElementById('save-gateways-btn')?.addEventListener('click', saveGateways);
document.getElementById('upload-payment-dpa-btn')?.addEventListener('click', uploadPaymentDpa);
document.getElementById('payment-dpa-list')?.addEventListener('click', e => {
  const btn = e.target.closest('[data-delete-payment-dpa]');
  if (btn) deletePaymentDpa(parseInt(btn.dataset.deletePaymentDpa));
});

// Campaigns
document.getElementById('campaign-save-btn')?.addEventListener('click', saveCampaign);
document.getElementById('campaign-cancel-btn')?.addEventListener('click', resetCampaignForm);
document.getElementById('campaigns-list')?.addEventListener('click', e => {
  const editBtn = e.target.closest('[data-edit-campaign]');
  if (editBtn) { editCampaign(JSON.parse(editBtn.dataset.editCampaign)); return; }
  const delBtn = e.target.closest('[data-delete-campaign]');
  if (delBtn) { deleteCampaign(parseInt(delBtn.dataset.deleteCampaign)); return; }
});

// Discount codes
document.getElementById('dcode-code')?.addEventListener('input', function() {
  this.value = this.value.toUpperCase().replace(/[^A-Z0-9_\-]/g, '');
});
document.getElementById('dcode-no-expiry')?.addEventListener('change', function() {
  document.getElementById('dcode-until').disabled = this.checked;
  if (this.checked) document.getElementById('dcode-until').value = '';
});
document.getElementById('dcode-no-total-limit')?.addEventListener('change', function() {
  document.getElementById('dcode-max-total').disabled = this.checked;
  if (this.checked) document.getElementById('dcode-max-total').value = '';
});
document.getElementById('dcode-no-client-limit')?.addEventListener('change', function() {
  document.getElementById('dcode-max-client').disabled = this.checked;
  if (this.checked) document.getElementById('dcode-max-client').value = '';
});
document.getElementById('dcode-save-btn')?.addEventListener('click', saveDiscountCode);
document.getElementById('dcode-cancel-btn')?.addEventListener('click', resetDiscountCodeForm);
document.getElementById('dcodes-list')?.addEventListener('click', e => {
  const editBtn = e.target.closest('[data-edit-dcode]');
  if (editBtn) { editDiscountCode(JSON.parse(editBtn.dataset.editDcode)); return; }
  const delBtn = e.target.closest('[data-delete-dcode]');
  if (delBtn) { deleteDiscountCode(parseInt(delBtn.dataset.deleteDcode)); return; }
});

// Intake forms
document.getElementById('intake-new-form-btn')?.addEventListener('click', () => openIntakeFormBuilder(0));
document.getElementById('intake-add-text-btn')?.addEventListener('click', () => addIntakeField('text'));
document.getElementById('intake-add-textarea-btn')?.addEventListener('click', () => addIntakeField('textarea'));
document.getElementById('intake-add-select-btn')?.addEventListener('click', () => addIntakeField('select'));
document.getElementById('intake-add-radio-btn')?.addEventListener('click', () => addIntakeField('radio'));
document.getElementById('intake-add-checkbox-btn')?.addEventListener('click', () => addIntakeField('checkbox'));
document.getElementById('intake-save-btn')?.addEventListener('click', saveIntakeForm);
document.getElementById('intake-cancel-btn')?.addEventListener('click', closeIntakeFormBuilder);
document.getElementById('intake-forms-list')?.addEventListener('click', e => {
  const editBtn = e.target.closest('[data-edit-intake-form]');
  if (editBtn) { openIntakeFormBuilder(JSON.parse(editBtn.dataset.editIntakeForm)); return; }
  const delBtn = e.target.closest('[data-delete-intake-form]');
  if (delBtn) { deleteIntakeForm(parseInt(delBtn.dataset.deleteIntakeForm)); return; }
});
document.getElementById('intake-fields-list')?.addEventListener('click', e => {
  const upBtn = e.target.closest('[data-move-up]');
  if (upBtn) { moveIntakeField(upBtn.dataset.moveUp, -1); return; }
  const dnBtn = e.target.closest('[data-move-down]');
  if (dnBtn) { moveIntakeField(dnBtn.dataset.moveDown, 1); return; }
  const rmBtn = e.target.closest('[data-remove-field]');
  if (rmBtn) { removeIntakeField(rmBtn.dataset.removeField); return; }
});
document.getElementById('intake-fields-list')?.addEventListener('input', e => {
  const el = e.target.closest('[data-sync-field]');
  if (el) syncIntakeField(el.dataset.syncField);
});
document.getElementById('intake-fields-list')?.addEventListener('change', e => {
  const el = e.target.closest('[data-sync-field]');
  if (el) syncIntakeField(el.dataset.syncField);
});

// Form template library (Intake Forms section)
document.getElementById('profFtRoleFilter')?.addEventListener('change', renderProfileTemplateList);
document.getElementById('profFtSearch')?.addEventListener('input', renderProfileTemplateList);
document.getElementById('profFtList')?.addEventListener('click', e => {
  const previewBtn = e.target.closest('[data-profft-preview]');
  if (previewBtn) { openIntakeFtPreview(parseInt(previewBtn.dataset.profftPreview)); return; }
  const useBtn = e.target.closest('[data-profft-use]');
  if (useBtn) { useIntakeTemplate(parseInt(useBtn.dataset.profftUse)); return; }
});
document.getElementById('intakeFtPreviewClose')?.addEventListener('click', closeIntakeFtPreview);
document.getElementById('intakeFtPreviewClose2')?.addEventListener('click', closeIntakeFtPreview);
document.getElementById('intakeFtPreviewUse')?.addEventListener('click', () => {
  if (_profFtPreviewId !== null) useIntakeTemplate(_profFtPreviewId);
});
document.getElementById('intakeFtPreviewModal')?.addEventListener('click', e => {
  if (e.target === document.getElementById('intakeFtPreviewModal')) closeIntakeFtPreview();
});

// Media recommendations
document.getElementById('mr-google-books-toggle')?.addEventListener('change', mrToggleGoogleBooks);
document.getElementById('mr-tmdb-toggle')?.addEventListener('change', mrToggleTmdb);
document.getElementById('mr-amazon-toggle')?.addEventListener('change', mrToggleAmazon);
document.getElementById('mr-save-btn')?.addEventListener('click', mrSave);

// ══════════════════════════════════════════════════════════════
// References & Quotes — library search + my-references + add-custom
// ══════════════════════════════════════════════════════════════
const REF_CSRF = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
let refsLoaded = false;
let refLibState  = { q: '', category: '' };
let refMineState = { q: '', category: '' };

function refEsc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function loadReferencesInit() {
  refsLoaded = true;
  // Tab clicks (delegated)
  document.querySelectorAll('#section-references .ref-tab').forEach(b => {
    b.addEventListener('click', () => {
      const t = b.dataset.refTab;
      document.querySelectorAll('#section-references .ref-tab').forEach(x => x.classList.toggle('active', x.dataset.refTab === t));
      document.querySelectorAll('#section-references .ref-tab-pane').forEach(p => p.style.display = p.dataset.refPane === t ? 'block' : 'none');
      if (t === 'library' && !document.getElementById('ref-lib-results').dataset.loaded) refLibrarySearch();
      if (t === 'mine')    refMineLoad();
    });
  });

  // Search inputs (debounced)
  let libTimer = null, mineTimer = null;
  document.getElementById('ref-lib-search')?.addEventListener('input', e => {
    clearTimeout(libTimer);
    libTimer = setTimeout(() => { refLibState.q = e.target.value.trim(); refLibrarySearch(); }, 180);
  });
  document.getElementById('ref-mine-search')?.addEventListener('input', e => {
    clearTimeout(mineTimer);
    mineTimer = setTimeout(() => { refMineState.q = e.target.value.trim(); refMineLoad(); }, 180);
  });

  // Add Custom buttons
  document.getElementById('ref-add-save-btn')?.addEventListener('click', refAddSave);
  document.getElementById('ref-add-clear-btn')?.addEventListener('click', refAddClear);

  refLibrarySearch();   // initial
}

async function refLibrarySearch() {
  const params = new URLSearchParams({ action: 'library_search' });
  if (refLibState.q !== '')        params.set('q', refLibState.q);
  if (refLibState.category !== '') params.set('category', refLibState.category);
  try {
    const res  = await fetch('/api/references.php?' + params.toString());
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Search failed');
    renderRefLibraryCategories(data.categories || {});
    renderRefLibraryResults(data.results || []);
    document.getElementById('ref-lib-results').dataset.loaded = '1';
  } catch (e) {
    document.getElementById('ref-lib-results').innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:20px;text-align:center;">Could not load library. Refresh to try again.</div>';
  }
}

function renderRefLibraryCategories(counts) {
  const el = document.getElementById('ref-lib-categories');
  if (!el) return;
  const order = ['','Christian','Jewish','Islamic','Buddhist','Hindu','Taoist','Interfaith','Philosophy','Mindfulness','Recovery','Grief','Pop Culture'];
  el.innerHTML = order.map(cat => {
    if (cat === '') {
      const active = refLibState.category === '' ? ' active' : '';
      return `<button class="ref-cat-chip${active}" data-ref-cat="">All</button>`;
    }
    if (!counts[cat]) return '';
    const active = refLibState.category === cat ? ' active' : '';
    return `<button class="ref-cat-chip${active}" data-ref-cat="${refEsc(cat)}">${refEsc(cat)}<span class="count">${counts[cat]}</span></button>`;
  }).join('');
  el.querySelectorAll('[data-ref-cat]').forEach(b => {
    b.addEventListener('click', () => { refLibState.category = b.dataset.refCat; refLibrarySearch(); });
  });
}

function renderRefLibraryResults(results) {
  const el = document.getElementById('ref-lib-results');
  if (!results.length) {
    el.innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:20px;text-align:center;">No matches. Try a different search or category.</div>';
    return;
  }
  el.innerHTML = results.map(q => {
    const tags = (q.tags || []).map(t => `<span class="ref-card-tag">${refEsc(t)}</span>`).join('');
    const yearLine = [q.author, q.year].filter(Boolean).join(' · ');
    return `<article class="ref-card" data-lib-id="${refEsc(q.id)}">
      <div class="ref-card-body">${refEsc(q.body)}</div>
      <div class="ref-card-attribution">— ${refEsc(yearLine)}${q.source ? ` · <span style="opacity:0.85;">${refEsc(q.source)}</span>` : ''}</div>
      <div class="ref-card-meta">
        <span class="ref-card-cat">${refEsc(q.category || 'Interfaith')}</span>
        ${tags}
        <div class="ref-card-actions">
          <button type="button" class="ref-star-btn ${q.starred ? 'starred' : ''}" data-star-lib="${refEsc(q.id)}" title="${q.starred ? 'Already in your references' : 'Star to save'}">${q.starred ? '★' : '☆'}</button>
        </div>
      </div>
    </article>`;
  }).join('');
  el.querySelectorAll('[data-star-lib]').forEach(b => b.addEventListener('click', () => refStarLibrary(b.dataset.starLib, b)));
}

async function refStarLibrary(libraryId, btn) {
  if (btn.classList.contains('starred')) return;  // already starred — no-op
  try {
    const res = await fetch('/api/references.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'star_library_quote', library_id: libraryId, csrf_token: REF_CSRF }),
    });
    const data = await res.json();
    if (data.ok) {
      btn.classList.add('starred');
      btn.textContent = '★';
      btn.title = 'Already in your references';
      // Invalidate "mine" load so it refetches next time
      const mineEl = document.getElementById('ref-mine-results');
      if (mineEl) mineEl.dataset.loaded = '';
    }
  } catch (e) { /* silent — UI will retry on next click */ }
}

async function refMineLoad() {
  const params = new URLSearchParams({ action: 'list' });
  if (refMineState.q !== '')        params.set('q', refMineState.q);
  if (refMineState.category !== '') params.set('category', refMineState.category);
  try {
    const res  = await fetch('/api/references.php?' + params.toString());
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Load failed');
    renderRefMineCategories(data.category_counts || {}, data.available_categories || []);
    renderRefMineResults(data.references || []);
    document.getElementById('ref-mine-results').dataset.loaded = '1';
  } catch (e) {
    document.getElementById('ref-mine-results').innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:20px;text-align:center;">Could not load your references.</div>';
  }
}

function renderRefMineCategories(counts, available) {
  const el = document.getElementById('ref-mine-categories');
  if (!el) return;
  if (!Object.keys(counts).length) { el.innerHTML = ''; return; }
  const order = ['','Christian','Jewish','Islamic','Buddhist','Hindu','Taoist','Interfaith','Philosophy','Mindfulness','Recovery','Grief','Pop Culture','Practitioner'];
  el.innerHTML = order.map(cat => {
    if (cat === '') {
      const active = refMineState.category === '' ? ' active' : '';
      return `<button class="ref-cat-chip${active}" data-ref-mine-cat="">All</button>`;
    }
    if (!counts[cat]) return '';
    const active = refMineState.category === cat ? ' active' : '';
    return `<button class="ref-cat-chip${active}" data-ref-mine-cat="${refEsc(cat)}">${refEsc(cat)}<span class="count">${counts[cat]}</span></button>`;
  }).join('');
  el.querySelectorAll('[data-ref-mine-cat]').forEach(b => {
    b.addEventListener('click', () => { refMineState.category = b.dataset.refMineCat; refMineLoad(); });
  });
}

function renderRefMineResults(refs) {
  const el = document.getElementById('ref-mine-results');
  if (!refs.length) {
    el.innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:20px;text-align:center;line-height:1.6;">Nothing here yet. Star quotes from the Library tab, or use Add Custom to enter your own.</div>';
    return;
  }
  el.innerHTML = refs.map(r => {
    const tags = (r.tags || []).map(t => `<span class="ref-card-tag">${refEsc(t)}</span>`).join('');
    const yearLine = [r.author, r.year].filter(Boolean).join(' · ');
    const isExternal = !!r.external_kind;
    return `<article class="ref-card" data-ref-id="${r.id}">
      ${r.body ? `<div class="ref-card-body">${refEsc(r.body)}</div>` : `<div class="ref-card-body" style="font-weight:600;">${refEsc(r.title)}</div>`}
      ${(yearLine || r.source) ? `<div class="ref-card-attribution">— ${refEsc(yearLine)}${r.source ? ` · <span style="opacity:0.85;">${refEsc(r.source)}</span>` : ''}</div>` : ''}
      <div class="ref-card-meta">
        <span class="ref-card-cat">${refEsc(r.category || 'Practitioner')}</span>
        ${tags}
        ${r.external_kind === 'library' ? '<span class="ref-card-tag" style="opacity:0.6;">from library</span>' : ''}
        ${(r.external_kind && r.external_kind !== 'library') ? `<span class="ref-card-tag" style="opacity:0.6;">from ${refEsc(r.external_kind)}</span>` : ''}
        <div class="ref-card-actions">
          ${!isExternal ? `<button type="button" data-ref-edit="${r.id}">Edit</button>` : ''}
          <button type="button" data-ref-delete="${r.id}">Remove</button>
        </div>
      </div>
    </article>`;
  }).join('');

  el.querySelectorAll('[data-ref-delete]').forEach(b => b.addEventListener('click', () => refDelete(parseInt(b.dataset.refDelete, 10))));
  el.querySelectorAll('[data-ref-edit]').forEach(b => b.addEventListener('click', () => {
    const ref = refs.find(r => String(r.id) === b.dataset.refEdit);
    if (ref) refEditOpen(ref);
  }));
}

async function refDelete(id) {
  if (!confirm('Remove this reference from your library?')) return;
  try {
    const res = await fetch('/api/references.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', id, csrf_token: REF_CSRF }),
    });
    const data = await res.json();
    if (data.ok) refMineLoad();
  } catch(e) { /* silent */ }
}

function refEditOpen(r) {
  // Switch to Add Custom tab in edit mode
  document.querySelector('#section-references .ref-tab[data-ref-tab="add"]').click();
  document.getElementById('ref-add-id').value           = r.id;
  document.getElementById('ref-add-type').value         = r.ref_type || 'quote';
  document.getElementById('ref-add-category').value     = r.category || 'Practitioner';
  document.getElementById('ref-add-title').value        = r.title  || '';
  document.getElementById('ref-add-body').value         = r.body   || '';
  document.getElementById('ref-add-author').value       = r.author || '';
  document.getElementById('ref-add-year').value         = r.year   || '';
  document.getElementById('ref-add-source').value       = r.source || '';
  document.getElementById('ref-add-source-url').value   = r.source_url || '';
  document.getElementById('ref-add-tags').value         = (r.tags || []).join(', ');
  document.getElementById('ref-add-notes').value        = r.notes  || '';
  document.getElementById('ref-add-save-btn').textContent = 'Save Changes';
}

function refAddClear() {
  document.getElementById('ref-add-id').value = '0';
  ['ref-add-title','ref-add-body','ref-add-author','ref-add-year','ref-add-source','ref-add-source-url','ref-add-tags','ref-add-notes'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  document.getElementById('ref-add-type').value     = 'quote';
  document.getElementById('ref-add-category').value = 'Practitioner';
  document.getElementById('ref-add-save-btn').textContent = 'Save Reference';
  document.getElementById('ref-add-error').style.display   = 'none';
  document.getElementById('ref-add-success').style.display = 'none';
}

async function refAddSave() {
  const errEl = document.getElementById('ref-add-error');
  const okEl  = document.getElementById('ref-add-success');
  errEl.style.display = 'none'; okEl.style.display = 'none';

  const body = {
    action:      'save',
    csrf_token:  REF_CSRF,
    id:          document.getElementById('ref-add-id').value,
    ref_type:    document.getElementById('ref-add-type').value,
    category:    document.getElementById('ref-add-category').value,
    title:       document.getElementById('ref-add-title').value,
    body:        document.getElementById('ref-add-body').value,
    author:      document.getElementById('ref-add-author').value,
    year:        document.getElementById('ref-add-year').value,
    source:      document.getElementById('ref-add-source').value,
    source_url:  document.getElementById('ref-add-source-url').value,
    tags:        document.getElementById('ref-add-tags').value,
    notes:       document.getElementById('ref-add-notes').value,
  };

  if (!body.title && !body.body) {
    errEl.textContent = 'Either a title or body is required.';
    errEl.style.display = 'block';
    return;
  }

  try {
    const res = await fetch('/api/references.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Could not save');
    okEl.textContent = body.id > 0 ? 'Saved.' : 'Reference added.';
    okEl.style.display = 'block';
    refAddClear();
    // Force My References to reload next time
    const mineEl = document.getElementById('ref-mine-results');
    if (mineEl) mineEl.dataset.loaded = '';
  } catch(e) {
    errEl.textContent = e.message;
    errEl.style.display = 'block';
  }
}
</script>
<?php endif; // end non-wizard mode ?>
</body>
</html>
