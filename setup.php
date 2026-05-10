<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/includes/geo.php';

// ── Determine current step ─────────────────────────────────────
$step    = 1;
$error   = '';
$success = '';
$smtpSuccess = '';

if (isAppConfigured()) {
    try {
        $pdo      = getDB();
        $hasAdmin = (bool)$pdo->query('SELECT id FROM practitioners WHERE is_admin = 1 LIMIT 1')->fetch();
        if ($hasAdmin) {
            // After full setup: only allow SMTP re-configuration from an authenticated admin session.
            $isAdminSession = !empty($_SESSION['practitioner_id'])
                && (bool)$pdo->prepare('SELECT is_admin FROM practitioners WHERE id = ? AND is_admin = 1 LIMIT 1')
                              ->execute([(int)$_SESSION['practitioner_id']]) // executeQuery trick
                // Actually check via separate fetch:
                && false; // placeholder — proper check below

            // Proper admin check
            $stmtAdmin = $pdo->prepare('SELECT is_admin FROM practitioners WHERE id = ? LIMIT 1');
            $stmtAdmin->execute([(int)($_SESSION['practitioner_id'] ?? 0)]);
            $adminRow = $stmtAdmin->fetch();
            $isAdminSession = !empty($_SESSION['practitioner_id']) && !empty($adminRow['is_admin']);

            $isStep5Post = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '5');
            $setupInProgress = !empty($_SESSION['setup_smtp_allowed']);
            if (($isset_smtp = isset($_GET['smtp'])) || $isStep5Post) {
                if (!$isAdminSession && !$setupInProgress) {
                    // Return 404 so the file's existence is not revealed to unauthenticated visitors
                    http_response_code(404);
                    exit;
                }
                $step = 5;
            } else {
                // Setup complete and no SMTP action — return 404 to unauthenticated, redirect admins
                if (!$isAdminSession) {
                    http_response_code(404);
                    exit;
                }
                header('Location: /dashboard.php');
                exit;
            }
        } else {
            // Config exists but no admin yet — determine step based on install type
            $installType = $_SESSION['setup_install_type'] ?? (defined('INSTALL_TYPE') ? INSTALL_TYPE : null);
            if (!$installType) {
                $step = 2; // need to select install type first
            } elseif ($installType === 'alternative') {
                // Alternative location: check if DC config has been saved
                $altDone = defined('DC_PRIMARY_URL') && DC_PRIMARY_URL !== '';
                $step = $altDone ? 0 : 3; // 3 = alt location config form
            } else {
                // Primary path: audit user → admin → email
                $auditDone = (defined('AUDIT_DB_USER') && AUDIT_DB_USER !== '') || !empty($_SESSION['setup_audit_done']);
                $step = $auditDone ? 4 : 3;
            }
        }
    } catch (PDOException $e) {
        // SQLSTATE 42S02 = table doesn't exist — schema not yet imported.
        // Auto-import schema.sql and continue rather than showing a misleading error.
        if ($e->getCode() === '42S02') {
            try {
                $pdo2   = getDB();
                $schema = file_get_contents(__DIR__ . '/db/schema.sql');

                // Strip -- comments and /* */ blocks, normalise line endings.
                $schema = preg_replace('/--[^\n]*/', '', $schema);
                $schema = preg_replace('/\/\*.*?\*\//s', '', $schema);
                $schema = str_replace("\r\n", "\n", $schema);

                // Split on semicolons that are NOT inside single-quoted strings.
                $statements = array_filter(
                    array_map('trim', preg_split("/;(?=(?:[^']*'[^']*')*[^']*$)/", $schema)),
                    fn($s) => $s !== ''
                );

                // Disable FK checks so table creation order doesn't matter.
                $pdo2->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach ($statements as $stmt) {
                    try {
                        $pdo2->exec($stmt);
                    } catch (PDOException $stmtErr) {
                        // Ignore "duplicate column/key/table already exists" errors —
                        // these come from migration stubs that reference now-redundant ALTERs.
                        $ignore = ['42S01', '42S21', '42701'];
                        if (!in_array($stmtErr->getCode(), $ignore, true)
                            && strpos($stmtErr->getMessage(), 'Duplicate') === false
                            && strpos($stmtErr->getMessage(), 'already exists') === false) {
                            throw $stmtErr;
                        }
                    }
                }
                $pdo2->exec('SET FOREIGN_KEY_CHECKS=1');

                // Schema imported — wipe any stale setup session state and restart from step 2.
                foreach (['setup_install_type','setup_audit_done','setup_smtp_allowed','setup_location_name'] as $k) {
                    unset($_SESSION[$k]);
                }
                $step    = 2;
                $success = 'Database schema imported automatically. Continuing setup.';
            } catch (PDOException $importErr) {
                $step  = 1;
                $error = 'Schema import failed: ' . htmlspecialchars($importErr->getMessage());
            }
        } else {
            // Genuine connection failure — credentials wrong or server unreachable.
            $step  = 1;
            $error = 'Could not connect to the database: ' . htmlspecialchars($e->getMessage()) . '. Please check your database settings.';
        }
    }
}

// ── POST: Disclaimer acceptance ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'disclaimer') {
    if (!empty($_POST['accept_disclaimer'])) {
        $_SESSION['setup_disclaimer_accepted'] = true;
        $_SESSION['setup_disclaimer_ts']       = date('Y-m-d H:i:s') . ' UTC';
        $_SESSION['setup_disclaimer_ip']       = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['setup_disclaimer_ua']       = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    }
    header('Location: /setup.php');
    exit;
}

// ── POST: Step 1 — database credentials ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '1') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';

    if ($dbName === '' || $dbUser === '') {
        $error = 'Database name and username are required.';
        $step  = 1;
    } else {
        try {
            // Connect without a specific database first
            $testPdo = new PDO(
                "mysql:host={$dbHost};charset=utf8mb4",
                $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );

            // Sanitise database name for the CREATE statement
            $safeDb = preg_replace('/[^A-Za-z0-9_]/', '', $dbName);
            $testPdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $testPdo->exec("USE `{$safeDb}`");

            // Run schema — execute each statement individually
            $sql = file_get_contents(__DIR__ . '/db/schema.sql');
            $sql = preg_replace('/--[^\n]*\n/', "\n", $sql); // strip line comments
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt) { try { $testPdo->exec($stmt); } catch (PDOException $ex) { /* ignore */ } }
            }

            // Check db/ is writable
            if (!is_writable(__DIR__ . '/db')) {
                throw new RuntimeException('The <code>db/</code> directory is not writable by the web server. Please set its permissions to 755.');
            }

            // Clear any stale setup session state from a previous aborted attempt
            foreach (['setup_install_type','setup_audit_done','setup_smtp_allowed','setup_location_name'] as $k) {
                unset($_SESSION[$k]);
            }

            // Write db/config.php
            // Generate independent 256-bit keys: one for PHI, one for payment credentials.
            // Separate keys limit the blast radius of a key exposure event.
            $smtpEncKey    = bin2hex(random_bytes(32));
            $paymentEncKey = bin2hex(random_bytes(32));
            $cfg  = "<?php\n// Auto-generated by Serenity Spaces setup wizard — do not edit manually\n";
            $cfg .= "define('DB_HOST', " . var_export($dbHost, true) . ");\n";
            $cfg .= "define('DB_NAME', " . var_export($dbName, true) . ");\n";
            $cfg .= "define('DB_USER', " . var_export($dbUser, true) . ");\n";
            $cfg .= "define('DB_PASS', " . var_export($dbPass, true) . ");\n";
            $cfg .= "define('SMTP_ENCRYPT_KEY',    " . var_export($smtpEncKey, true) . ");\n";
            $cfg .= "define('PAYMENT_ENCRYPT_KEY', " . var_export($paymentEncKey, true) . ");\n";
            file_put_contents(__DIR__ . '/db/config.php', $cfg);

            // Redirect so connection.php loads the new config with correct constants
            header('Location: /setup.php');
            exit;

        } catch (PDOException $e) {
            $error = 'Connection failed: ' . htmlspecialchars($e->getMessage());
            $step  = 1;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
            $step  = 1;
        }
    }
}

// ── POST: Step 2 — installation type ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '2') {
    $installChoice = $_POST['install_type'] ?? '';
    if (!in_array($installChoice, ['primary', 'alternative'], true)) {
        $error = 'Please select an installation type.';
        $step  = 2;
    } else {
        $locationName = trim($_POST['location_name'] ?? '');
        $_SESSION['setup_install_type'] = $installChoice;
        $_SESSION['setup_location_name'] = $locationName;
        // Persist to config.php so it survives session expiry
        $configLines = "define('INSTALL_TYPE', " . var_export($installChoice, true) . ");\n";
        if ($locationName !== '') {
            $configLines .= "define('LOCATION_DISPLAY_NAME', " . var_export($locationName, true) . ");\n";
        }
        file_put_contents(__DIR__ . '/db/config.php', $configLines, FILE_APPEND);
        // Save install country to settings and auto-derive jurisdiction
        if ($locationName !== '' && $installChoice === 'primary') {
            try {
                setSetting('install_country', $locationName);
                require_once __DIR__ . '/includes/jurisdiction/jurisdiction_helper.php';
                jur_sync_from_infrastructure();
            } catch (Throwable $e) { /* DB may not be ready yet on first-time setup */ }
        }
        $step = 3;
    }
}

// ── POST: Step 3 — install type branch ───────────────────────
// Primary path: audit DB user
// Alternative path: location config (country, primary URL, shared secret)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '3') {
    $installType = $_SESSION['setup_install_type'] ?? (defined('INSTALL_TYPE') ? INSTALL_TYPE : 'primary');

    if ($installType === 'alternative') {
        // ── Alternative Location Config ──────────────────────
        $dcCountry      = strtoupper(trim($_POST['dc_country'] ?? ''));
        $dcLocationName = trim($_POST['dc_location_name'] ?? '');
        $dcPrimaryUrl   = rtrim(trim($_POST['dc_primary_url'] ?? ''), '/');
        $dcSecret       = $_POST['dc_shared_secret'] ?? '';

        if ($dcCountry === '' || $dcPrimaryUrl === '' || $dcSecret === '') {
            $error = 'All fields are required.';
            $step  = 3;
        } elseif (!filter_var($dcPrimaryUrl, FILTER_VALIDATE_URL)) {
            $error = 'Please enter a valid URL for the Primary server (e.g. https://your-domain.example).';
            $step  = 3;
        } elseif (strlen($dcSecret) < 32) {
            $error = 'Shared secret must be at least 32 characters. Use the generated value from your Primary server.';
            $step  = 3;
        } else {
            $dcConfigLines = "define('DC_COUNTRY', " . var_export($dcCountry, true) . ");\n" .
                "define('DC_PRIMARY_URL', " . var_export($dcPrimaryUrl, true) . ");\n" .
                "define('DC_SHARED_SECRET', " . var_export($dcSecret, true) . ");\n";
            if ($dcLocationName !== '') {
                $dcConfigLines .= "define('LOCATION_DISPLAY_NAME', " . var_export($dcLocationName, true) . ");\n";
            }
            file_put_contents(__DIR__ . '/db/config.php', $dcConfigLines, FILE_APPEND);
            $step    = 0;
            $success = 'Alternative location configured. This server is ready to receive sessions routed from your Primary server.';
        }

    } else {
        // ── Primary: Audit DB User ────────────────────────────
        if (($_POST['audit_action'] ?? 'save') === 'skip') {
            $_SESSION['setup_audit_done'] = true;
            $step = 4;
        } else {
            $auditUser = trim($_POST['audit_user'] ?? '');
            $auditPass = $_POST['audit_pass'] ?? '';

            if ($auditUser === '') {
                $error = 'Audit username is required.';
                $step  = 3;
            } else {
                try {
                    $auditTestPdo = new PDO(
                        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                        $auditUser, $auditPass,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                    );

                    // Verify permissions via SHOW GRANTS
                    $grants      = $auditTestPdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
                    $hasInsert   = false;
                    $hasSelect   = false;
                    $hasBadPriv  = false;
                    $badPrivList = [];

                    foreach ($grants as $grant) {
                        if (preg_match('/^GRANT\s+USAGE\s+ON/i', $grant)) continue;
                        if (preg_match('/^GRANT\s+(.+?)\s+ON\s+/i', $grant, $m)) {
                            $privStr = strtoupper($m[1]);
                            if (strpos($privStr, 'ALL') !== false)    { $hasBadPriv = true; $badPrivList[] = 'ALL PRIVILEGES'; $hasInsert = true; $hasSelect = true; }
                            if (strpos($privStr, 'UPDATE') !== false)  { $hasBadPriv = true; $badPrivList[] = 'UPDATE'; }
                            if (strpos($privStr, 'DELETE') !== false)  { $hasBadPriv = true; $badPrivList[] = 'DELETE'; }
                            if (strpos($privStr, 'DROP') !== false)    { $hasBadPriv = true; $badPrivList[] = 'DROP'; }
                            if (strpos($privStr, 'ALTER') !== false)   { $hasBadPriv = true; $badPrivList[] = 'ALTER'; }
                            if (strpos($privStr, 'INSERT') !== false)  { $hasInsert  = true; }
                            if (strpos($privStr, 'SELECT') !== false)  { $hasSelect  = true; }
                        }
                    }

                    if ($hasBadPriv) {
                        $error = 'This user has privileges beyond SELECT/INSERT: <strong>' . htmlspecialchars(implode(', ', array_unique($badPrivList))) . '</strong>. Revoke these before continuing — the audit user must be restricted to SELECT and INSERT on <code>' . htmlspecialchars(DB_NAME) . '.audit_log</code> only.';
                        $step  = 3;
                    } elseif (!$hasInsert) {
                        $error = 'This user does not have INSERT permission. Run the following and try again:<br><code>GRANT SELECT, INSERT ON `' . htmlspecialchars(DB_NAME) . '`.`audit_log` TO \'' . htmlspecialchars($auditUser) . '\'@\'localhost\';<br>FLUSH PRIVILEGES;</code>';
                        $step  = 3;
                    } else {
                        file_put_contents(
                            __DIR__ . '/db/config.php',
                            "define('AUDIT_DB_USER', " . var_export($auditUser, true) . ");\n" .
                            "define('AUDIT_DB_PASS', " . var_export($auditPass, true) . ");\n",
                            FILE_APPEND
                        );
                        $_SESSION['setup_audit_done'] = true;
                        $step = 4;
                    }
                } catch (PDOException $e) {
                    $error = 'Could not connect as audit user: ' . htmlspecialchars($e->getMessage());
                    $step  = 3;
                }
            }
        }
    }
}

// ── POST: Step 4 — admin account ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '4') {
    $email   = trim($_POST['email'] ?? '');
    $pass    = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $dname   = trim($_POST['display_name'] ?? '');

    if ($email === '' || $pass === '' || $confirm === '') {
        $error = 'All fields are required.';
        $step  = 4;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
        $step  = 4;
    } elseif (strlen($pass) < 12) {
        $error = 'Password must be at least 12 characters (HIPAA requirement for admin accounts).';
        $step  = 4;
    } elseif (!preg_match('/[A-Z]/', $pass) || !preg_match('/[a-z]/', $pass) || !preg_match('/[0-9]/', $pass) || !preg_match('/[^A-Za-z0-9]/', $pass)) {
        $error = 'Password must include at least one uppercase letter, lowercase letter, number, and special character.';
        $step  = 4;
    } elseif ($pass !== $confirm) {
        $error = 'Passwords do not match.';
        $step  = 4;
    } else {
        // Handle optional avatar upload
        $avatarPath = null;
        if (!empty($_FILES['avatar_upload']['name']) && $_FILES['avatar_upload']['error'] === UPLOAD_ERR_OK) {
            $file    = $_FILES['avatar_upload'];
            $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
            $finfo   = new finfo(FILEINFO_MIME_TYPE);
            $mime    = $finfo->file($file['tmp_name']);
            if (!in_array($mime, $allowed, true) || $file['size'] > 5 * 1024 * 1024) {
                $error = 'Photo must be a JPEG, PNG, GIF, or WEBP image under 5 MB.';
                $step  = 4;
            } else {
                $uploadsDir = __DIR__ . '/assets/avatars/';
                if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
                $setupAvatarMimeExtMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                $ext        = $setupAvatarMimeExtMap[$mime] ?? 'jpg';
                $fname      = bin2hex(random_bytes(12)) . '.' . $ext;
                move_uploaded_file($file['tmp_name'], $uploadsDir . $fname);
                $avatarPath = '/assets/avatars/' . $fname;
            }
        }

        if ($error === '') {
        try {
            $pdo  = getDB();
            $hash = ss_password_hash($pass);
            $pdo->prepare(
                'INSERT INTO practitioners (email, password_hash, display_name, avatar_path, is_admin) VALUES (?,?,?,?,1)'
            )->execute([$email, $hash, $dname ?: null, $avatarPath]);
            setSetting('registrations_enabled', '1');
            // Persist disclaimer acceptance record now that DB is confirmed available
            if (!empty($_SESSION['setup_disclaimer_ts'])) {
                setSetting('setup_disclaimer_accepted',    '1');
                setSetting('setup_disclaimer_accepted_at', $_SESSION['setup_disclaimer_ts']);
                setSetting('setup_disclaimer_accepted_ip', $_SESSION['setup_disclaimer_ip'] ?? '');
                setSetting('setup_disclaimer_ua',          $_SESSION['setup_disclaimer_ua'] ?? '');
            }
            $step = 5; // Proceed to optional SMTP step
            $_SESSION['setup_smtp_allowed'] = true; // allow SMTP step without login
        } catch (PDOException $e) {
            $error = 'Could not create admin account: ' . htmlspecialchars($e->getMessage());
            $step  = 4;
        }
        } // end if ($error === '')
    }
}

// ── POST: Step 5 — SMTP configuration (optional) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '5') {
    $action = $_POST['smtp_action'] ?? 'save';
    if ($action === 'skip') {
        $step    = 0;
        unset($_SESSION['setup_smtp_allowed']);
        $success = 'Setup complete! You can now sign in.';
    } else {
        $smtpHost    = trim($_POST['smtp_host']     ?? '');
        $smtpPort    = trim($_POST['smtp_port']     ?? '587');
        $smtpEnc     = trim($_POST['smtp_encryption'] ?? 'tls');
        $smtpUser    = trim($_POST['smtp_user']     ?? '');
        $smtpPass    = $_POST['smtp_pass']          ?? '';
        $smtpFromN   = trim($_POST['smtp_from_name']  ?? 'Serenity Spaces');
        $smtpFromE   = trim($_POST['smtp_from_email'] ?? '');

        if (!in_array($smtpEnc, ['tls','ssl','none'], true)) $smtpEnc = 'tls';
        $smtpPort = max(1, min(65535, (int)$smtpPort)) ?: 587;

        try {
            $pdo = getDB();
            setSetting('smtp_host',       $smtpHost);
            setSetting('smtp_port',       (string)$smtpPort);
            setSetting('smtp_encryption', $smtpEnc);
            setSetting('smtp_user',       $smtpUser);
            if ($smtpPass !== '') {
                // Encrypt with AES-256-CBC using key from config file (not DB)
                if (defined('SMTP_ENCRYPT_KEY') && SMTP_ENCRYPT_KEY !== '') {
                    $iv  = random_bytes(16);
                    $enc = base64_encode($iv . openssl_encrypt($smtpPass, 'AES-256-CBC', hex2bin(SMTP_ENCRYPT_KEY), OPENSSL_RAW_DATA, $iv));
                    setSetting('smtp_pass', 'enc:' . $enc);
                } else {
                    setSetting('smtp_pass', $smtpPass); // fallback if no key (existing installs)
                }
            }
            setSetting('smtp_from_name',  $smtpFromN);
            setSetting('smtp_from_email', $smtpFromE);

            if ($action === 'test') {
                // Save and test — send to admin email
                require_once __DIR__ . '/includes/mailer.php';
                require_once __DIR__ . '/includes/email_templates.php';
                $adminRow = $pdo->query('SELECT email, display_name FROM practitioners WHERE is_admin=1 LIMIT 1')->fetch();
                $step = 4;
                if ($adminRow) {
                    $adminName = $adminRow['display_name'] ?: $adminRow['email'];
                    $sent = send_template_email($pdo, 'smtp_test', 'SMTP Test', [
                        'admin_name' => $adminName,
                        'sent_at'    => date('Y-m-d H:i:s') . ' UTC',
                    ], $adminRow['email'], $adminName);
                    if ($sent) {
                        $step    = 0;
                        unset($_SESSION['setup_smtp_allowed']);
                        $success = 'Setup complete! SMTP configured and test email sent to ' . htmlspecialchars($adminRow['email']) . '.';
                    } else {
                        $step        = 4;
                        $smtpSuccess = 'Settings saved, but the test email failed. Check your credentials and try again.';
                    }
                } else {
                    $step        = 4;
                    $smtpSuccess = 'Settings saved.';
                }
            } else {
                $step    = 0;
                unset($_SESSION['setup_smtp_allowed']);
                $success = 'Setup complete! SMTP configured. You can now sign in.';
            }
        } catch (Exception $e) {
            $error = 'Could not save SMTP settings: ' . htmlspecialchars($e->getMessage());
            $step  = 4;
        }
    }
}
// ── Show disclaimer before step 1 if not yet accepted ─────────
$showDisclaimer = ($step === 1 && !isAppConfigured() && empty($_SESSION['setup_disclaimer_accepted']));

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Setup — Serenity Spaces</title>
  <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body class="auth-page theme-<?= isAppConfigured() ? getAppTheme() : 'dark' ?>">

<div class="setup-card glass-panel">

  <div class="setup-brand">Serenity Spaces</div>
  <?php
    $breadInstallType = $_SESSION['setup_install_type'] ?? (defined('INSTALL_TYPE') ? INSTALL_TYPE : null);
    if ($breadInstallType === 'alternative'):
  ?>
  <div class="setup-steps">
    <span class="setup-step <?= $showDisclaimer ? 'active' : 'done' ?>">Terms</span>
    <span class="setup-step-sep">→</span>
    <span class="setup-step <?= (!$showDisclaimer && $step === 1) ? 'active' : (!$showDisclaimer && $step > 1 ? 'done' : '') ?>">1 Database</span>
    <span class="setup-step-sep">→</span>
    <span class="setup-step <?= $step === 2 ? 'active' : ($step > 2 || $step === 0 ? 'done' : '') ?>">2 Install Type</span>
    <span class="setup-step-sep">→</span>
    <span class="setup-step <?= $step === 3 ? 'active' : ($step === 0 ? 'done' : '') ?>">3 Location Config</span>
    <span class="setup-step-sep">→</span>
    <span class="setup-step <?= $step === 0 ? 'active' : '' ?>">Done</span>
  </div>
  <?php else: ?>
  <div class="setup-steps">
    <span class="setup-step <?= $showDisclaimer ? 'active' : 'done' ?>">Terms</span>
    <span class="setup-step-sep">→</span>
    <span class="setup-step <?= (!$showDisclaimer && $step === 1) ? 'active' : (!$showDisclaimer && $step > 1 ? 'done' : '') ?>">1 Database</span>
    <span class="setup-step-sep">→</span>
    <span class="setup-step <?= $step === 2 ? 'active' : ($step > 2 || $step === 0 ? 'done' : '') ?>">2 Install Type</span>
    <span class="setup-step-sep">→</span>
    <span class="setup-step <?= $step === 3 ? 'active' : ($step > 3 || $step === 0 ? 'done' : '') ?>">3 Audit User</span>
    <span class="setup-step-sep">→</span>
    <span class="setup-step <?= $step === 4 ? 'active' : ($step > 4 || $step === 0 ? 'done' : '') ?>">4 Admin</span>
    <span class="setup-step-sep">→</span>
    <span class="setup-step <?= $step === 5 ? 'active' : ($step === 0 ? 'done' : '') ?>">5 Email</span>
    <span class="setup-step-sep">→</span>
    <span class="setup-step <?= $step === 0 ? 'active' : '' ?>">Done</span>
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= $error /* may contain <code>/<strong> tags */ ?></div>
  <?php endif; ?>
  <?php if ($smtpSuccess): ?>
    <div class="alert alert-success"><?= htmlspecialchars($smtpSuccess) ?></div>
  <?php endif; ?>

  <?php if ($showDisclaimer): ?>
  <h1>Before You Begin</h1>
  <p class="subtitle">Please read and acknowledge the following before setting up SerenitySpaces.</p>

  <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.09);border-radius:10px;padding:20px 22px;margin-bottom:22px;font-size:13.5px;line-height:1.75;color:rgba(220,228,255,0.75);">
    <p style="margin:0 0 14px;"><strong style="color:#e8eeff;">SerenitySpaces is self-hosted software.</strong> When you install and operate this platform, you become the data controller and system operator. That means the legal and technical responsibility for how it is configured, secured, and used rests with you — not with the SerenitySpaces project, its maintainers, or any contributors.</p>

    <p style="margin:0 0 14px;">Specifically, the SerenitySpaces project and its contributors are <strong style="color:#e8eeff;">not responsible or liable</strong> for:</p>
    <ul style="margin:0 0 14px;padding-left:20px;">
      <li style="margin-bottom:6px;">Misconfiguration of the platform, server, or database</li>
      <li style="margin-bottom:6px;">Failure to implement appropriate security measures for your environment</li>
      <li style="margin-bottom:6px;">Non-compliance with any applicable laws or regulations, including but not limited to HIPAA, GDPR, UK GDPR, PIPEDA, or any other data protection or healthcare privacy law in your jurisdiction</li>
      <li style="margin-bottom:6px;">Loss, exposure, or misuse of data resulting from your deployment</li>
      <li style="margin-bottom:6px;">Any harm arising from use of this software in a clinical or professional context</li>
    </ul>

    <p style="margin:0 0 14px;">This software is provided <strong style="color:#e8eeff;">as-is, without warranty of any kind</strong> — express or implied. It is your responsibility to assess whether SerenitySpaces is suitable for your use case, to configure it correctly, and to ensure that its operation complies with all laws and professional standards applicable in your jurisdiction.</p>

    <p style="margin:0;color:rgba(200,210,255,0.5);font-size:12.5px;">If you are deploying this platform for therapeutic or clinical use, we strongly recommend consulting a qualified legal professional familiar with healthcare data regulations in your region before going live.</p>
  </div>

  <form method="post">
    <input type="hidden" name="step" value="disclaimer">
    <label style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border-radius:10px;border:1px solid rgba(124,106,247,0.25);background:rgba(124,106,247,0.06);cursor:pointer;margin-bottom:18px;">
      <input type="checkbox" name="accept_disclaimer" value="1" id="accept_disclaimer"
             style="accent-color:#7c6af7;flex-shrink:0;margin-top:2px;width:16px;height:16px;"
             required>
      <span style="font-size:13px;color:#d0d8f0;line-height:1.5;">
        I understand that I am the operator of this platform and that responsibility for its configuration, security, and legal compliance rests with me. I accept these terms and acknowledge that the SerenitySpaces project and its contributors bear no liability for my deployment.
      </span>
    </label>
    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;"
            onclick="if(!document.getElementById('accept_disclaimer').checked){alert('Please check the box to confirm you have read and accepted the terms.');return false;}">
      I Agree — Continue to Setup
    </button>
  </form>

  <?php elseif ($step === 1): ?>
  <?php
  // ── PHP extension pre-flight ──────────────────────────────────��
  $extRequired = [
      'openssl'    => 'PHI encryption (AES-256-GCM) and SMTP TLS — CRITICAL',
      'pdo_mysql'  => 'Database connection',
      'fileinfo'   => 'MIME-type validation on file uploads',
      'gd'         => 'Avatar image processing',
      'mbstring'   => 'Email header encoding',
      'curl'       => 'AI vendor API calls (required for AI features)',
  ];
  $extMissing = [];
  $extCritical = false;
  foreach ($extRequired as $ext => $purpose) {
      if (!extension_loaded($ext)) {
          $extMissing[$ext] = $purpose;
          if ($ext === 'openssl' || $ext === 'pdo_mysql') $extCritical = true;
      }
  }
  ?>
  <?php if ($extMissing): ?>
  <div style="background:rgba(<?= $extCritical ? '232,85,85' : '245,200,66' ?>,0.08);border:1px solid rgba(<?= $extCritical ? '232,85,85' : '245,200,66' ?>,0.3);border-radius:10px;padding:16px 18px;margin-bottom:20px;">
    <div style="font-weight:700;color:<?= $extCritical ? '#e85555' : '#f5c842' ?>;margin-bottom:10px;font-size:13.5px;">
      <?= $extCritical ? '⚠ Required PHP extensions missing — install before continuing' : '⚠ Recommended PHP extensions not found' ?>
    </div>
    <?php foreach ($extMissing as $ext => $purpose): ?>
    <div style="display:flex;gap:10px;align-items:baseline;padding:4px 0;font-size:12.5px;color:rgba(220,228,255,0.75);">
      <code style="color:#e85555;font-size:11.5px;min-width:90px;"><?= htmlspecialchars($ext) ?></code>
      <span><?= htmlspecialchars($purpose) ?></span>
    </div>
    <?php endforeach; ?>
    <div style="margin-top:10px;font-size:12px;color:rgba(220,228,255,0.45);">
      Enable these in your <code>php.ini</code> or via cPanel → PHP Extensions, then reload this page.
    </div>
  </div>
  <?php endif; ?>
  <h1>Database Setup</h1>
  <p class="subtitle">Enter your MySQL connection details.</p>
  <form method="post" novalidate>
    <input type="hidden" name="step" value="1">
    <div class="form-group">
      <label for="db_host">Database host</label>
      <input type="text" id="db_host" name="db_host"
             value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
    </div>
    <div class="form-group">
      <label for="db_name">Database name</label>
      <input type="text" id="db_name" name="db_name"
             value="<?= htmlspecialchars($_POST['db_name'] ?? 'serenityspaces') ?>" required>
    </div>
    <div class="form-group">
      <label for="db_user">Username</label>
      <input type="text" id="db_user" name="db_user"
             value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required autocomplete="username">
    </div>
    <div class="form-group">
      <label for="db_pass">Password <span class="text-muted">(leave blank if none)</span></label>
      <input type="password" id="db_pass" name="db_pass" autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;">
      Connect &amp; Create Tables
    </button>
  </form>

  <?php elseif ($step === 2): ?>
  <h1>Installation Type</h1>
  <p class="subtitle">Choose how this server will operate in your Serenity Spaces deployment.</p>

  <form method="post" novalidate>
    <input type="hidden" name="step" value="2">
    <div style="display:grid;gap:12px;margin-bottom:20px;">
      <label style="display:block;cursor:pointer;">
        <input type="radio" name="install_type" value="primary"
               <?= ($_POST['install_type'] ?? 'primary') === 'primary' ? 'checked' : '' ?>
               style="display:none" class="install-type-radio">
        <div class="install-type-card" data-val="primary"
             style="border:2px solid var(--accent);border-radius:10px;padding:16px 18px;transition:all 0.2s;">
          <div style="font-weight:700;font-size:15px;color:var(--text);margin-bottom:4px;">Primary Server</div>
          <div style="font-size:13px;color:var(--text-muted);line-height:1.5;">
            Stores all data: practitioner accounts, client records, bookings, and session messages.
            This is the standard single-server installation.
          </div>
          <div style="font-size:12px;color:var(--accent);margin-top:8px;">Recommended for most installations</div>
        </div>
      </label>

      <label style="display:block;cursor:pointer;">
        <input type="radio" name="install_type" value="alternative"
               <?= ($_POST['install_type'] ?? '') === 'alternative' ? 'checked' : '' ?>
               style="display:none" class="install-type-radio">
        <div class="install-type-card" data-val="alternative"
             style="border:2px solid var(--border);border-radius:10px;padding:16px 18px;transition:all 0.2s;">
          <div style="font-weight:700;font-size:15px;color:var(--text);margin-bottom:4px;">Alternative Location</div>
          <div style="font-size:13px;color:var(--text-muted);line-height:1.5;">
            A secondary server that stores session messages for clients in a specific region.
            Requires an existing Primary server. Client data stays in their jurisdiction.
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">For multi-region deployments</div>
        </div>
      </label>
    </div>

    <div class="form-group" style="margin-top:8px;">
      <label for="location_name">Server country <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
      <select id="location_name" name="location_name">
        <option value="">— Select country —</option>
        <?php foreach (getCountryList() as $code => $cname): ?>
          <option value="<?= htmlspecialchars($code) ?>" <?= ($_POST['location_name'] ?? '') === $code ? 'selected' : '' ?>>
            <?= countryFlag($code) ?> <?= htmlspecialchars($cname) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;">
      Continue →
    </button>
  </form>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
  (function(){
    var radios = document.querySelectorAll('.install-type-radio');
    function updateCards(){
      radios.forEach(function(r){
        var card = r.closest('label').querySelector('.install-type-card');
        var accent = r.checked;
        card.style.borderColor = accent ? 'var(--accent)' : 'var(--border)';
        card.style.background  = accent ? 'rgba(124,106,247,.07)' : '';
      });
    }
    radios.forEach(function(r){ r.addEventListener('change', updateCards); });
    updateCards();
  })();
  </script>

  <?php elseif ($step === 3):
    $installType3 = $_SESSION['setup_install_type'] ?? (defined('INSTALL_TYPE') ? INSTALL_TYPE : 'primary');
    if ($installType3 === 'alternative'): ?>
  <h1>Location Configuration</h1>
  <p class="subtitle">Connect this server to your Primary installation.</p>

  <div style="background:rgba(124,106,247,.08);border:1px solid rgba(124,106,247,.25);border-radius:6px;padding:12px 14px;margin-bottom:18px;font-size:13px;line-height:1.6;color:var(--text-muted);">
    <strong style="color:var(--text);">Before continuing:</strong> On your Primary server, go to Admin &rarr; Linked Locations
    and add this server. Copy the generated shared secret from there.
  </div>

  <form method="post" novalidate>
    <input type="hidden" name="step" value="3">
    <div style="display:grid;grid-template-columns:1fr 2fr;gap:12px;">
      <div class="form-group">
        <label for="dc_country">Server country</label>
        <select id="dc_country" name="dc_country" required>
          <option value="">— Select —</option>
          <?php foreach (getCountryList() as $code => $cname): ?>
            <option value="<?= htmlspecialchars($code) ?>" <?= ($_POST['dc_country'] ?? '') === $code ? 'selected' : '' ?>>
              <?= countryFlag($code) ?> <?= htmlspecialchars($cname) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="dc_location_name">Location display name</label>
        <input type="text" id="dc_location_name" name="dc_location_name" maxlength="100"
               value="<?= htmlspecialchars($_POST['dc_location_name'] ?? '') ?>"
               placeholder="e.g. London, UK">
      </div>
    </div>
    <div class="form-group">
      <label for="dc_primary_url">Primary server URL</label>
      <input type="url" id="dc_primary_url" name="dc_primary_url"
             value="<?= htmlspecialchars($_POST['dc_primary_url'] ?? '') ?>"
             placeholder="https://your-domain.example" required>
    </div>
    <div class="form-group">
      <label for="dc_shared_secret">Shared secret (from Primary server admin panel)</label>
      <input type="password" id="dc_shared_secret" name="dc_shared_secret"
             placeholder="Paste the shared secret here" required autocomplete="new-password">
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;">
      Save &amp; Complete Setup →
    </button>
  </form>

  <?php else: ?>
  <?php $setupDbName = defined('DB_NAME') ? DB_NAME : 'serenityspaces'; ?>
  <h1>Audit Database User</h1>
  <p class="subtitle">An INSERT-only MySQL user prevents audit logs from being tampered with.</p>

  <div style="background:rgba(124,106,247,.08);border:1px solid rgba(124,106,247,.25);border-radius:6px;padding:12px 14px;margin-bottom:18px;font-size:13px;line-height:1.6;color:var(--text-muted);">
    <strong style="color:var(--text);">Why this matters:</strong> Every access to clinical PHI is recorded in the audit log.
    Without a restricted database user, an attacker who compromises the application layer can erase that evidence.
    An INSERT-only user can write new rows but cannot UPDATE or DELETE existing ones.
  </div>

  <div style="display:flex;gap:4px;margin-bottom:14px;">
    <button type="button" id="tabCli" data-audit-tab="cli"
            style="padding:6px 14px;border-radius:4px;border:1px solid var(--accent);background:var(--accent);color:#fff;font-size:13px;cursor:pointer;">
      Command Line
    </button>
    <button type="button" id="tabCpanel" data-audit-tab="cpanel"
            style="padding:6px 14px;border-radius:4px;border:1px solid var(--border);background:transparent;color:var(--text-muted);font-size:13px;cursor:pointer;">
      cPanel
    </button>
  </div>

  <div id="instrCli">
    <p style="font-size:13px;color:var(--text-muted);margin:0 0 8px;">Run these commands in your MySQL shell or phpMyAdmin SQL tab:</p>
    <pre style="background:rgba(0,0,0,.35);border:1px solid var(--border);border-radius:5px;padding:12px 14px;font-size:12px;line-height:1.7;overflow-x:auto;white-space:pre-wrap;word-break:break-all;color:#c9d1d9;margin:0 0 4px;">CREATE USER 'ss_audit'@'localhost' IDENTIFIED BY 'YourStrongPasswordHere';
GRANT SELECT, INSERT ON `<?= htmlspecialchars($setupDbName) ?>`.`audit_log` TO 'ss_audit'@'localhost';
FLUSH PRIVILEGES;</pre>
    <p style="font-size:11px;color:var(--text-muted);margin:6px 0 0;">Replace <code>YourStrongPasswordHere</code> with a strong password, then enter the same password below.</p>
  </div>

  <div id="instrCpanel" style="display:none;">
    <ol style="font-size:13px;color:var(--text-muted);line-height:1.8;padding-left:18px;margin:0 0 10px;">
      <li>In cPanel, go to <strong style="color:var(--text);">MySQL Databases</strong>.</li>
      <li>Under <strong style="color:var(--text);">Add New User</strong>, create a user named <code>ss_audit</code> with a strong password.
          <br><em style="font-size:11px;">Note: cPanel may prefix it with your account name, e.g. <code>cpuser_ss_audit</code>. Use that full name below.</em></li>
      <li>Under <strong style="color:var(--text);">Add User To Database</strong>, add the user to <code><?= htmlspecialchars($setupDbName) ?></code>.</li>
      <li>On the privileges screen, tick <strong style="color:var(--text);">SELECT</strong> and <strong style="color:var(--text);">INSERT</strong> only — leave all others unchecked.</li>
      <li>For maximum security, open <strong style="color:var(--text);">phpMyAdmin</strong> → SQL tab and run the following to scope the grant to just the audit_log table:</li>
    </ol>
    <pre style="background:rgba(0,0,0,.35);border:1px solid var(--border);border-radius:5px;padding:12px 14px;font-size:12px;line-height:1.7;overflow-x:auto;white-space:pre-wrap;word-break:break-all;color:#c9d1d9;margin:0 0 4px;">REVOKE ALL ON `<?= htmlspecialchars($setupDbName) ?>`.* FROM 'ss_audit'@'localhost';
GRANT SELECT, INSERT ON `<?= htmlspecialchars($setupDbName) ?>`.`audit_log` TO 'ss_audit'@'localhost';
FLUSH PRIVILEGES;</pre>
    <p style="font-size:11px;color:var(--text-muted);margin:6px 0 0;">Adjust the username if cPanel added a prefix.</p>
  </div>

  <form method="post" novalidate style="margin-top:18px;">
    <input type="hidden" name="step" value="3">
    <div class="form-group">
      <label for="audit_user">Audit username</label>
      <input type="text" id="audit_user" name="audit_user"
             value="<?= htmlspecialchars($_POST['audit_user'] ?? 'ss_audit') ?>"
             placeholder="ss_audit" required autocomplete="username">
    </div>
    <div class="form-group">
      <label for="audit_pass">Audit password</label>
      <input type="password" id="audit_pass" name="audit_pass"
             placeholder="Password you set for this user" autocomplete="new-password">
    </div>
    <div style="display:flex;gap:8px;margin-top:4px;">
      <button type="submit" name="audit_action" value="save"
              class="btn btn-primary" style="flex:1;justify-content:center;padding:12px;">
        Verify &amp; Continue →
      </button>
      <button type="submit" name="audit_action" value="skip"
              class="btn btn-ghost" style="padding:12px 20px;">
        Skip
      </button>
    </div>
  </form>
  <p style="font-size:11px;color:var(--text-muted);margin-top:10px;line-height:1.5;">
    Skipping leaves the audit log writable by the main database user. You can complete this step later
    by adding <code>AUDIT_DB_USER</code> and <code>AUDIT_DB_PASS</code> to <code>db/config.php</code>.
  </p>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
  function auditTab(tab) {
    var isCli = tab === 'cli';
    document.getElementById('instrCli').style.display    = isCli ? 'block' : 'none';
    document.getElementById('instrCpanel').style.display = isCli ? 'none'  : 'block';
    document.getElementById('tabCli').style.background    = isCli ? 'var(--accent)' : 'transparent';
    document.getElementById('tabCli').style.color         = isCli ? '#fff' : 'var(--text-muted)';
    document.getElementById('tabCli').style.borderColor   = isCli ? 'var(--accent)' : 'var(--border)';
    document.getElementById('tabCpanel').style.background = isCli ? 'transparent' : 'var(--accent)';
    document.getElementById('tabCpanel').style.color      = isCli ? 'var(--text-muted)' : '#fff';
    document.getElementById('tabCpanel').style.borderColor= isCli ? 'var(--border)' : 'var(--accent)';
  }
  document.querySelectorAll('[data-audit-tab]').forEach(function(btn) {
      btn.addEventListener('click', function() { auditTab(this.dataset.auditTab); });
  });
  </script>
  <?php endif; // end alternative/primary branch for step 3 ?>

  <?php elseif ($step === 4): ?>
  <h1>Create Admin Account</h1>
  <p class="subtitle">This account has full administrative access.</p>
  <form method="post" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="step" value="4">
    <div class="form-group">
      <label for="display_name">Display name <span class="text-muted">(optional)</span></label>
      <input type="text" id="display_name" name="display_name"
             value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>"
             placeholder="How you appear in sessions" autofocus>
    </div>
    <div class="form-group">
      <label>Profile photo <span class="text-muted">(optional — JPEG, PNG, GIF, WEBP, max 5 MB)</span></label>
      <label for="avatar_upload" class="upload-area" id="setup-upload-area">
        <div id="setup-preview-wrap" style="display:none;margin-bottom:8px;">
          <img id="setup-preview" style="width:80px;height:80px;object-fit:cover;border-radius:50%;border:2px solid var(--accent);">
        </div>
        <span id="setup-upload-label">Click to upload a photo</span>
        <input type="file" id="avatar_upload" name="avatar_upload"
               accept="image/jpeg,image/png,image/gif,image/webp"
               style="display:none">
      </label>
    </div>
    <div class="form-group">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email"
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
             placeholder="admin@example.com" required>
    </div>
    <div class="form-group">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" placeholder="••••••••" required autocomplete="new-password">
      <?= password_meter('password', true) ?>
    </div>
    <div class="form-group">
      <label for="confirm_password">Confirm password</label>
      <input type="password" id="confirm_password" name="confirm_password" placeholder="••••••••" required>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;">
      Create Admin &amp; Continue →
    </button>
  </form>
  <script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
  document.getElementById('avatar_upload')?.addEventListener('change', function() {
      handleSetupUpload(this);
  });
  function handleSetupUpload(input) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
      const prev = document.getElementById('setup-preview');
      prev.src = e.target.result;
      document.getElementById('setup-preview-wrap').style.display = 'block';
      document.getElementById('setup-upload-label').textContent = input.files[0].name;
    };
    reader.readAsDataURL(input.files[0]);
  }
  </script>

  <?php elseif ($step === 5): ?>
  <?php
    $curSmtpHost  = getSetting('smtp_host', '');
    $curSmtpPort  = getSetting('smtp_port', '587');
    $curSmtpEnc   = getSetting('smtp_encryption', 'tls');
    $curSmtpUser  = getSetting('smtp_user', '');
    $curSmtpFromN = getSetting('smtp_from_name', 'Serenity Spaces');
    $curSmtpFromE = getSetting('smtp_from_email', '');
  ?>
  <h1>Email Notifications</h1>
  <p class="subtitle">Configure SMTP to send booking confirmations. This step is optional.</p>
  <form method="post" novalidate>
    <input type="hidden" name="step" value="5">
    <div class="form-group">
      <label for="smtp_host">SMTP hostname</label>
      <input type="text" id="smtp_host" name="smtp_host"
             value="<?= htmlspecialchars($_POST['smtp_host'] ?? $curSmtpHost) ?>"
             placeholder="mail.example.com">
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:1;">
        <label for="smtp_port">Port</label>
        <input type="number" id="smtp_port" name="smtp_port" min="1" max="65535"
               value="<?= htmlspecialchars($_POST['smtp_port'] ?? $curSmtpPort) ?>"
               placeholder="587">
      </div>
      <div class="form-group" style="flex:1;">
        <label for="smtp_encryption">Encryption</label>
        <select id="smtp_encryption" name="smtp_encryption">
          <?php foreach (['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= (($_POST['smtp_encryption'] ?? $curSmtpEnc) === $v) ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label for="smtp_user">Username</label>
      <input type="email" id="smtp_user" name="smtp_user"
             value="<?= htmlspecialchars($_POST['smtp_user'] ?? $curSmtpUser) ?>"
             placeholder="notifications@example.com">
    </div>
    <div class="form-group">
      <label for="smtp_pass">Password</label>
      <input type="password" id="smtp_pass" name="smtp_pass" autocomplete="new-password"
             placeholder="<?= $curSmtpHost ? '(leave blank to keep existing)' : 'SMTP password' ?>">
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:1;">
        <label for="smtp_from_name">From name</label>
        <input type="text" id="smtp_from_name" name="smtp_from_name"
               value="<?= htmlspecialchars($_POST['smtp_from_name'] ?? $curSmtpFromN) ?>"
               placeholder="Serenity Spaces">
      </div>
      <div class="form-group" style="flex:1;">
        <label for="smtp_from_email">From address</label>
        <input type="email" id="smtp_from_email" name="smtp_from_email"
               value="<?= htmlspecialchars($_POST['smtp_from_email'] ?? $curSmtpFromE) ?>"
               placeholder="noreply@example.com">
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:4px;">
      <button type="submit" name="smtp_action" value="save" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px;">
        Save &amp; Finish
      </button>
      <button type="submit" name="smtp_action" value="test" class="btn btn-ghost" style="padding:12px 20px;">
        Test &amp; Save
      </button>
      <button type="submit" name="smtp_action" value="skip" class="btn btn-ghost" style="padding:12px 20px;">
        Skip
      </button>
    </div>
  </form>

  <?php elseif ($step === 0): ?>
  <div style="text-align:center;padding:16px 0;">
    <div style="font-size:48px;margin-bottom:12px;">✓</div>
    <h1>You're all set</h1>
    <p class="subtitle" style="margin-bottom:24px;"><?= htmlspecialchars($success) ?></p>
    <?php if (isset($pdo) && $pdo->query('SELECT id FROM practitioners WHERE is_admin = 1 LIMIT 1')->fetch()): ?>
    <a href="/dashboard.php" class="btn btn-primary" style="padding:12px 32px;">Go to Dashboard</a>
    <?php else: ?>
    <a href="/login.php" class="btn btn-primary" style="padding:12px 32px;">Sign In</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
