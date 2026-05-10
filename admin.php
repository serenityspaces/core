<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/includes/email_templates.php';
require_once __DIR__ . '/includes/banners.php';
require_once __DIR__ . '/includes/session_timeout.php';
require_once __DIR__ . '/includes/jurisdiction/jurisdiction_helper.php';
requireSetup();

if (empty($_SESSION['practitioner_id'])) {
    header('Location: /login.php');
    exit;
}

$pdo    = getDB();
$practId = (int)$_SESSION['practitioner_id'];

// Seed any missing email templates on admin load
seed_email_templates($pdo);

// Verify admin
$me = $pdo->prepare('SELECT * FROM practitioners WHERE id = ? LIMIT 1');
$me->execute([$practId]);
$me = $me->fetch();
if (!$me || !$me['is_admin']) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error   = '';
$success = '';

// ── Handle POST actions ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Security token mismatch.';
    } else {
        $action = $_POST['action'] ?? '';

        // Toggle registrations
        if ($action === 'toggle_registrations') {
            $current = getSetting('registrations_enabled', '1');
            setSetting('registrations_enabled', $current === '1' ? '0' : '1');
            $success = 'Registration setting updated.';
        }

        // Toggle production mode
        if ($action === 'toggle_production_mode') {
            $current = getSetting('production_mode', '0');
            setSetting('production_mode', $current === '1' ? '0' : '1');
            $success = 'Production Mode setting updated.';
        }

        // Cron mode switch
        if ($action === 'set_cron_mode') {
            $newMode = ($_POST['cron_mode'] ?? '') === 'web' ? 'web' : 'system';
            setSetting('cron_mode', $newMode);
            if ($newMode === 'web' && getSetting('cron_web_key', '') === '') {
                setSetting('cron_web_key', bin2hex(random_bytes(24)));
            }
            $success = 'Cron mode updated.';
        }

        // Regenerate web trigger key
        if ($action === 'regenerate_cron_key') {
            setSetting('cron_web_key', bin2hex(random_bytes(24)));
            $success = 'Web trigger key regenerated.';
        }

        // Toggle practitioner signup approval requirement
        if ($action === 'toggle_require_approval') {
            $current = getSetting('require_practitioner_approval', '0');
            setSetting('require_practitioner_approval', $current === '1' ? '0' : '1');
            $success = 'Approval requirement updated.';
        }

        // Approve practitioner account
        if ($action === 'approve_practitioner') {
            require_once __DIR__ . '/includes/mailer.php';
            require_once __DIR__ . '/includes/email_templates.php';
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid) {
                $pdo->prepare("UPDATE practitioners SET account_status = 'active' WHERE id = ?")->execute([$uid]);
                $u = $pdo->prepare('SELECT email, display_name FROM practitioners WHERE id = ? LIMIT 1');
                $u->execute([$uid]);
                $uRow = $u->fetch();
                if ($uRow) {
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $loginUrl = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/login.php';
                    send_template_email($pdo, 'practitioner_approved', 'Account Approved', [
                        'practitioner_name' => $uRow['display_name'] ?: $uRow['email'],
                        'login_url'         => $loginUrl,
                    ], $uRow['email'], $uRow['display_name'] ?: $uRow['email']);
                }
                $success = 'Account approved and notification sent.';
            }
        }

        // Reject practitioner account
        if ($action === 'reject_practitioner') {
            require_once __DIR__ . '/includes/mailer.php';
            require_once __DIR__ . '/includes/email_templates.php';
            $uid    = (int)($_POST['user_id'] ?? 0);
            $reason = trim($_POST['rejection_reason'] ?? '');
            if ($uid) {
                $pdo->prepare("UPDATE practitioners SET account_status = 'rejected' WHERE id = ?")->execute([$uid]);
                $u = $pdo->prepare('SELECT email, display_name FROM practitioners WHERE id = ? LIMIT 1');
                $u->execute([$uid]);
                $uRow = $u->fetch();
                if ($uRow) {
                    $reasonBlock = $reason ? '<p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>' : '';
                    send_template_email($pdo, 'practitioner_rejected', 'Account Application Update', [
                        'practitioner_name'      => $uRow['display_name'] ?: $uRow['email'],
                        'rejection_reason_block' => $reasonBlock,
                    ], $uRow['email'], $uRow['display_name'] ?: $uRow['email']);
                }
                $success = 'Account rejected and notification sent.';
            }
        }

        // Save home page settings
        if ($action === 'save_home_settings') {
            setSetting('home_hero_heading', trim($_POST['home_hero_heading'] ?? 'Serenity Spaces'));
            setSetting('home_hero_subtext', trim($_POST['home_hero_subtext'] ?? 'A calm, private space for therapeutic conversations.'));
            setSetting('show_find_practitioners', isset($_POST['show_find_practitioners']) ? '1' : '0');
            $success = 'Home page settings saved.';
        }

        // Upload site background
        if ($action === 'upload_site_bg') {
            if (!empty($_FILES['site_bg_file']['name']) && $_FILES['site_bg_file']['error'] === UPLOAD_ERR_OK) {
                $file     = $_FILES['site_bg_file'];
                $allowedImg = ['image/jpeg','image/png','image/gif','image/webp'];
                $allowedVid = ['video/mp4','video/webm','video/ogg','video/quicktime'];
                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $mime     = $finfo->file($file['tmp_name']);
                $isVideo  = in_array($mime, $allowedVid, true);
                $maxSize  = $isVideo ? 200 * 1024 * 1024 : 10 * 1024 * 1024;
                if (!in_array($mime, $allowedImg, true) && !$isVideo) {
                    $error = 'Background must be an image (JPEG, PNG, GIF, WEBP) or video (MP4, WebM).';
                } elseif ($file['size'] > $maxSize) {
                    $error = 'File too large.';
                } else {
                    $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','video/mp4'=>'mp4','video/webm'=>'webm','video/ogg'=>'ogv','video/quicktime'=>'mov'];
                    $ext  = $extMap[$mime] ?? 'bin';
                    $fname = 'sitebg_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    move_uploaded_file($file['tmp_name'], __DIR__ . '/assets/uploads/' . $fname);
                    setSetting('site_background_path', '/assets/uploads/' . $fname);
                    setSetting('site_background_type', $isVideo ? 'video' : 'image');
                    $success = 'Site background updated.';
                }
            }
        }

        // Remove site background
        if ($action === 'remove_site_bg') {
            setSetting('site_background_path', '');
            setSetting('site_background_type', '');
            $success = 'Site background removed. Default starry sky will be used.';
        }

        // Toggle theme
        if ($action === 'toggle_theme') {
            $current = getSetting('theme', 'dark');
            setSetting('theme', $current === 'light' ? 'dark' : 'light');
            $success = 'Theme updated.';
        }

        // Upload logo
        if ($action === 'upload_logo') {
            if (!empty($_FILES['logo_file']['name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                $file    = $_FILES['logo_file'];
                $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
                $finfo   = new finfo(FILEINFO_MIME_TYPE);
                $mime    = $finfo->file($file['tmp_name']);
                if (!in_array($mime, $allowed, true) || $file['size'] > 2 * 1024 * 1024) {
                    $error = 'Logo must be JPEG, PNG, GIF, WEBP, or SVG and under 2 MB.';
                } else {
                    $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/svg+xml'=>'svg'];
                    $ext   = $extMap[$mime] ?? 'bin';
                    $fname = 'logo_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    move_uploaded_file($file['tmp_name'], __DIR__ . '/assets/uploads/' . $fname);
                    setSetting('logo_path', '/assets/uploads/' . $fname);
                    $success = 'Logo updated.';
                }
            }
        }

        // Remove logo
        if ($action === 'remove_logo') {
            setSetting('logo_path', '');
            $success = 'Logo removed.';
        }

        // Add user
        if ($action === 'add_user') {
            $newEmail   = trim($_POST['new_email'] ?? '');
            $newPass    = $_POST['new_password'] ?? '';
            $newName    = trim($_POST['new_display_name'] ?? '');
            $newIsAdmin = isset($_POST['new_is_admin']) ? 1 : 0;

            if (!$newEmail || !$newPass) {
                $error = 'Email and password are required.';
            } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email address.';
            } elseif (($pwAdminErr = validate_practitioner_password($newPass)) !== null) {
                $error = $pwAdminErr;
            } else {
                $check = $pdo->prepare('SELECT id FROM practitioners WHERE email = ? LIMIT 1');
                $check->execute([$newEmail]);
                if ($check->fetch()) {
                    $error = 'An account with that email already exists.';
                } else {
                    $hash = ss_password_hash($newPass);
                    $pdo->prepare(
                        'INSERT INTO practitioners (email, password_hash, display_name, is_admin) VALUES (?,?,?,?)'
                    )->execute([$newEmail, $hash, $newName ?: null, $newIsAdmin]);
                    $success = 'Account created.';
                }
            }
        }

        // Edit user
        if ($action === 'edit_user') {
            $uid     = (int)($_POST['user_id'] ?? 0);
            $uEmail  = trim($_POST['edit_email'] ?? '');
            $uName   = trim($_POST['edit_display_name'] ?? '');
            $uAdmin  = isset($_POST['edit_is_admin']) ? 1 : 0;
            $uPass   = $_POST['edit_password'] ?? '';

            if (!$uid || !$uEmail) {
                $error = 'Invalid submission.';
            } elseif (!filter_var($uEmail, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email address.';
            } else {
                // Prevent removing own admin status
                if ($uid === $practId) $uAdmin = 1;

                $pdo->prepare(
                    'UPDATE practitioners SET email = ?, display_name = ?, is_admin = ? WHERE id = ?'
                )->execute([$uEmail, $uName ?: null, $uAdmin, $uid]);

                if ($uPass !== '') {
                    $pwEditErr = validate_practitioner_password($uPass);
                    if ($pwEditErr !== null) {
                        $error = $pwEditErr;
                    } else {
                        $hash = ss_password_hash($uPass);
                        $pdo->prepare('UPDATE practitioners SET password_hash = ? WHERE id = ?')->execute([$hash, $uid]);
                    }
                }
                if (!$error) {
                    $success = 'Account updated.';
                    header('Location: /admin.php?saved=1');
                    exit;
                }
            }
        }

        // Delete user
        if ($action === 'delete_user') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid && $uid !== $practId) {
                $pdo->prepare('DELETE FROM practitioners WHERE id = ?')->execute([$uid]);
                $success = 'Account deleted.';
            } else {
                $error = 'Cannot delete your own account.';
            }
        }

        // Unblock IP
        if ($action === 'unblock_ip') {
            require_once __DIR__ . '/includes/firewall.php';
            $ip = trim($_POST['ip_address'] ?? '');
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                fw_unblock($ip);
                $success = 'IP ' . htmlspecialchars($ip) . ' has been unblocked.';
            } else {
                $error = 'Invalid IP address.';
            }
        }

        // Save SMTP settings
        if ($action === 'save_smtp') {
            $smtpHost  = trim($_POST['smtp_host']       ?? '');
            $smtpPort  = max(1, min(65535, (int)($_POST['smtp_port'] ?? 587)));
            $smtpEnc   = trim($_POST['smtp_encryption'] ?? 'tls');
            $smtpUser  = trim($_POST['smtp_user']       ?? '');
            $smtpPass  = $_POST['smtp_pass']            ?? '';
            $smtpFromN = trim($_POST['smtp_from_name']  ?? 'Serenity Spaces');
            $smtpFromE = trim($_POST['smtp_from_email'] ?? '');
            if (!in_array($smtpEnc, ['tls','ssl','none'], true)) $smtpEnc = 'tls';
            setSetting('smtp_host',       $smtpHost);
            setSetting('smtp_port',       (string)$smtpPort);
            setSetting('smtp_encryption', $smtpEnc);
            setSetting('smtp_user',       $smtpUser);
            if ($smtpPass !== '') setSetting('smtp_pass', $smtpPass);
            setSetting('smtp_from_name',  $smtpFromN);
            setSetting('smtp_from_email', $smtpFromE);
            $success = 'SMTP settings saved.';
        }

        // Test SMTP
        if ($action === 'test_smtp') {
            require_once __DIR__ . '/includes/mailer.php';
            require_once __DIR__ . '/includes/email_templates.php';
            $testTo       = trim($_POST['test_to_email'] ?? $me['email']);
            if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) $testTo = $me['email'];
            $testName     = $me['display_name'] ?: $testTo;
            $testTemplate = $_POST['test_template'] ?? 'smtp_test';
            $allTpls      = get_default_email_templates();
            if (!array_key_exists($testTemplate, $allTpls)) $testTemplate = 'smtp_test';
            // Build a sample vars set that satisfies most template placeholders
            $sampleVars = [
                'admin_name'          => $testName,
                'sent_at'             => date('Y-m-d H:i:s') . ' UTC',
                'client_name'         => 'Test Client',
                'practitioner_name'   => $testName,
                'booking_ref'         => 'TEST-0000',
                'scheduled_date'      => date('D d M Y \a\t H:i') . ' UTC',
                'new_date'            => date('D d M Y \a\t H:i') . ' UTC',
                'join_url'            => '#',
                'room_name'           => 'Test Room',
                'privacy_url'         => '#',
                'account_section'     => '',
                'rejection_reason_block' => '',
                'client_message_block'   => '',
                'request_id'          => '0',
                'submitted_date'      => date('Y-m-d'),
                'deadline_date'       => date('Y-m-d', strtotime('+30 days')),
                'withdrawn_at'        => date('Y-m-d H:i:s'),
                'ip_address'          => '0.0.0.0',
                'blocked_at'          => date('Y-m-d H:i:s'),
                'reason'              => 'Repeated failed login attempts',
                'login_url'           => '#',
                'action_type'         => 'cancellation',
                'form_name'           => 'Sample Intake Form',
                'form_url'            => '#',
            ];
            $sent = send_template_email($pdo, $testTemplate, 'SMTP Test (' . $allTpls[$testTemplate]['name'] . ')', $sampleVars, $testTo, $testName);
            if ($sent) {
                $success = 'Test email (' . htmlspecialchars($allTpls[$testTemplate]['name']) . ') sent to ' . htmlspecialchars($testTo) . '. Check your inbox.';
            } else {
                $error = 'Test email failed to send. Check your SMTP settings and server logs.';
            }
        }

        // Save feature settings
        if ($action === 'save_feature_settings') {
            $featureKeys = ['allow_webcam','allow_voice_chat','allow_voice_notes','allow_attachments'];
            foreach ($featureKeys as $k) {
                setSetting($k, isset($_POST[$k]) ? '1' : '0');
            }
            $rateLimit   = max(5, min(300, (int)($_POST['rate_limit_per_min'] ?? 30)));
            $maxPart     = max(2, min(10, (int)($_POST['max_participants'] ?? 6)));
            setSetting('rate_limit_per_min', (string)$rateLimit);
            setSetting('max_participants',   (string)$maxPart);
            $success = 'Feature settings saved.';
        }

        // License review: approve or reject
        $licenseAction = $_POST['license_action'] ?? '';
        if ($licenseAction === 'approve' || $licenseAction === 'reject') {
            $licId   = (int)($_POST['license_id']      ?? 0);
            $pId     = (int)($_POST['practitioner_id'] ?? 0);
            $revNote = trim($_POST['reviewer_notes']   ?? '');
            // Block approval when platform prerequisites are not met
            if ($licenseAction === 'approve') {
                $chkHosting = (bool)$pdo->query("SELECT id FROM dpa_documents WHERE scope='platform' AND type='hosting' LIMIT 1")->fetch();
                $chkSmtp    = (bool)$pdo->query("SELECT id FROM dpa_documents WHERE scope='platform' AND type='smtp' LIMIT 1")->fetch();
                $chkCtrl    = getSetting('controller_name', '') !== '' && getSetting('controller_email', '') !== '';
                if (!$chkHosting || !$chkSmtp || !$chkCtrl) {
                    $error = 'Cannot approve licensed status: platform prerequisites are not complete. Resolve all blocking items in Launch Status before approving credentials.';
                    goto licenseActionDone;
                }
            }
            if ($licId && $pId) {
                $newStatus = $licenseAction === 'approve' ? 'approved' : 'rejected';
                $pdo->prepare(
                    'UPDATE license_submissions SET status = ?, reviewer_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?'
                )->execute([$newStatus, $revNote ?: null, $practId, $licId]);
                if ($licenseAction === 'approve') {
                    $pdo->prepare('UPDATE practitioners SET license_status = ? WHERE id = ?')
                        ->execute(['approved', $pId]);
                } else {
                    // On reject, reset to none (allow resubmission) unless already had an approved submission
                    $chk = $pdo->prepare("SELECT id FROM license_submissions WHERE practitioner_id = ? AND status = 'approved' LIMIT 1");
                    $chk->execute([$pId]);
                    if (!$chk->fetch()) {
                        $pdo->prepare('UPDATE practitioners SET license_status = ? WHERE id = ?')
                            ->execute(['rejected', $pId]);
                    }
                }
                $success = $licenseAction === 'approve' ? 'Submission approved — practitioner now has licensed status.' : 'Submission rejected.';
            }
        }
        licenseActionDone:

        // Save AI Integration settings
        if ($action === 'save_ai_settings') {
            setSetting('ai_enabled', isset($_POST['ai_enabled']) ? '1' : '0');
            $vendorKeys = ['openai','anthropic','google','cohere'];
            $allowedVendors = array_values(array_intersect($vendorKeys, array_keys(array_filter($_POST, fn($v, $k) => str_starts_with($k, 'ai_vendor_'), ARRAY_FILTER_USE_BOTH))));
            // Parse checkboxes: ai_vendor_openai, ai_vendor_anthropic, etc.
            $enabledVendors = [];
            foreach ($vendorKeys as $vk) {
                if (!empty($_POST['ai_vendor_' . $vk])) $enabledVendors[] = $vk;
            }
            setSetting('ai_allowed_vendors', json_encode($enabledVendors));
            $scopeKeys = ['summarization','notes','post_session','in_session'];
            $enabledScopes = [];
            foreach ($scopeKeys as $sk) {
                if (!empty($_POST['ai_scope_' . $sk])) $enabledScopes[] = $sk;
            }
            setSetting('ai_allowed_scopes', json_encode($enabledScopes));
            $success = 'AI Integration settings saved.';
        }

        // Compliance — re-sync jurisdictions from infrastructure
        if ($action === 'sync_compliance') {
            jur_sync_from_infrastructure();
            $success = 'Jurisdictions re-synced from infrastructure.';
        }

        // Launch Status — DPIA sign-off toggle
        if ($action === 'toggle_dpia_signoff') {
            $cur = getSetting('dpia_signed_off', '0');
            setSetting('dpia_signed_off', $cur === '1' ? '0' : '1');
            $success = $cur === '1' ? 'DPIA sign-off removed.' : 'DPIA marked as reviewed and signed off.';
        }

        // MFA enforcement settings
        if ($action === 'save_mfa_enforcement') {
            $enforce = isset($_POST['enforce_practitioner_mfa']) ? '1' : '0';
            $grace   = max(1, min(30, (int)($_POST['mfa_grace_days'] ?? 7)));
            $prevEnforce = getSetting('enforce_practitioner_mfa', '0');
            setSetting('enforce_practitioner_mfa', $enforce);
            setSetting('mfa_grace_days', (string)$grace);
            // Record the date enforcement was enabled (for grace period calculation)
            if ($enforce === '1' && $prevEnforce !== '1') {
                setSetting('mfa_enforce_enabled_at', date('Y-m-d'));
            } elseif ($enforce === '0') {
                setSetting('mfa_enforce_enabled_at', '');
            }
            $success = 'MFA enforcement settings saved.';
        }

        // Save Compliance & Jurisdiction settings
        if ($action === 'save_compliance_settings') {
            $allowedJuris = ['eu','uk','us','canada','australia','custom'];
            $primary = trim($_POST['jurisdiction_primary'] ?? 'eu');
            if (!in_array($primary, $allowedJuris, true)) $primary = 'eu';
            setSetting('jurisdiction_primary', $primary);

            $additional = [];
            foreach ($allowedJuris as $jc) {
                if (!empty($_POST['jurisdiction_additional_' . $jc]) && $jc !== $primary) {
                    $additional[] = $jc;
                }
            }
            setSetting('jurisdictions_additional', json_encode($additional));

            setSetting('controller_name',    trim($_POST['controller_name']    ?? ''));
            setSetting('controller_address', trim($_POST['controller_address'] ?? ''));
            setSetting('controller_email',   trim($_POST['controller_email']   ?? ''));
            setSetting('dpo_name',           trim($_POST['dpo_name']           ?? ''));
            setSetting('dpo_email',          trim($_POST['dpo_email']          ?? ''));
            setSetting('jurisdiction_custom_privacy_html', $_POST['jurisdiction_custom_privacy_html'] ?? '');
            setSetting('jurisdiction_custom_ropa_html',    $_POST['jurisdiction_custom_ropa_html']    ?? '');
            $success = 'Compliance & Jurisdiction settings saved.';
        }

        // Linked Locations — add
        if ($action === 'add_location') {
            $locLabel   = trim($_POST['location_label']  ?? '');
            $locCountry = strtoupper(trim($_POST['location_country'] ?? ''));
            $locUrl     = rtrim(trim($_POST['location_url'] ?? ''), '/');
            if (!$locLabel || !$locCountry || !$locUrl) {
                $error = 'Label, country, and URL are required.';
            } elseif (!filter_var($locUrl, FILTER_VALIDATE_URL)) {
                $error = 'Please enter a valid URL (e.g. https://uk.your-domain.example).';
            } else {
                // Generate a cryptographically random shared secret (shown once)
                $sharedSecret     = bin2hex(random_bytes(32)); // 64-char hex
                $sharedSecretHash = hash('sha256', $sharedSecret);
                $pdo->prepare(
                    'INSERT INTO locations (label, country, url, auth_token_hash) VALUES (?, ?, ?, ?)'
                )->execute([$locLabel, $locCountry, $locUrl, $sharedSecretHash]);
                $newLocId = $pdo->lastInsertId();
                // Flash the secret in the success message — it is only shown once
                $_SESSION['location_secret_flash'] = [
                    'id'     => (int)$newLocId,
                    'secret' => $sharedSecret,
                    'label'  => $locLabel,
                ];
                jur_sync_from_infrastructure();
                $success = 'Location added.';
            }
        }

        // Linked Locations — toggle active
        if ($action === 'toggle_location') {
            $locId = (int)($_POST['location_id'] ?? 0);
            if ($locId) {
                $pdo->prepare('UPDATE locations SET is_active = NOT is_active WHERE id = ?')->execute([$locId]);
                jur_sync_from_infrastructure();
                $success = 'Location updated.';
            }
        }

        // Linked Locations — delete
        if ($action === 'delete_location') {
            $locId = (int)($_POST['location_id'] ?? 0);
            if ($locId) {
                $pdo->prepare('DELETE FROM locations WHERE id = ?')->execute([$locId]);
                jur_sync_from_infrastructure();
                $success = 'Location removed.';
            }
        }

        // Linked Locations — ping
        if ($action === 'ping_location') {
            $locId = (int)($_POST['location_id'] ?? 0);
            if ($locId) {
                $locRow = $pdo->prepare('SELECT * FROM locations WHERE id = ? LIMIT 1');
                $locRow->execute([$locId]);
                $locRow = $locRow->fetch();
                if ($locRow) {
                    require_once __DIR__ . '/includes/dc_auth.php';
                    // We store a hash — we can't reverse it, so ping uses HTTP Bearer with hash itself
                    // (Acceptable for health check: the hash is not the secret, just a fingerprint)
                    // Actually, for ping we need the actual secret. Store temporarily in session from flash.
                    $error = 'Ping requires the shared secret. Use the secret shown when you added this location.';
                }
            }
        }
    }
}

if (isset($_GET['saved'])) $success = 'Account updated.';

// ── Fetch data ─────────────────────────────────────────────────
$productionMode        = getSetting('production_mode', '0') === '1';
$regEnabled            = getSetting('registrations_enabled', '1') === '1';
$enforcePractMfa       = getSetting('enforce_practitioner_mfa', '0') === '1';
$mfaGraceDays          = (int)getSetting('mfa_grace_days', '7');
$mfaEnforceEnabledAt   = getSetting('mfa_enforce_enabled_at', '');
$requireApproval = getSetting('require_practitioner_approval', '0') === '1';
$homeHeroHeading = getSetting('home_hero_heading', 'Serenity Spaces');
$homeHeroSubtext = getSetting('home_hero_subtext', 'A calm, private space for therapeutic conversations.');
$showFindPract   = getSetting('show_find_practitioners', '0') === '1';
$siteBgPath      = getSetting('site_background_path', '');
$siteBgType      = getSetting('site_background_type', '');
$appTheme        = getAppTheme();
$appLogo         = getAppLogo();
$allowWebcam     = getSetting('allow_webcam',      '1') === '1';
$allowVoiceChat  = getSetting('allow_voice_chat',  '1') === '1';
$allowVoiceNotes = getSetting('allow_voice_notes', '1') === '1';
$allowAttach     = getSetting('allow_attachments', '1') === '1';
$rateLimitMin    = (int)getSetting('rate_limit_per_min', '30');
$maxParticipants = (int)getSetting('max_participants',   '6');
$currentLogo   = $appLogo;
$smtpHost      = getSetting('smtp_host',       '');
$smtpPort      = getSetting('smtp_port',       '587');
$smtpEnc       = getSetting('smtp_encryption', 'tls');
$smtpUser      = getSetting('smtp_user',       '');
$smtpFromName  = getSetting('smtp_from_name',  'Serenity Spaces');
$smtpFromEmail = getSetting('smtp_from_email', '');
$users           = $pdo->query('SELECT * FROM practitioners ORDER BY is_admin DESC, created_at ASC')->fetchAll();
$pendingPractitioners = array_filter($users, fn($u) => ($u['account_status'] ?? 'active') === 'pending');
$editUser   = null;
if (isset($_GET['edit']) && (int)$_GET['edit'] > 0) {
    $stmt = $pdo->prepare('SELECT * FROM practitioners WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$_GET['edit']]);
    $editUser = $stmt->fetch();
}

// ── Session reviews data ────────────────────────────────────────
$reviews = $pdo->query(
    'SELECT sr.*, eu.display_name AS client_name, eu.email AS client_email,
            p.display_name AS practitioner_name,
            s.started_at
     FROM session_ratings sr
     JOIN end_users eu ON eu.id = sr.end_user_id
     JOIN sessions s ON s.id = sr.session_id
     JOIN rooms r ON r.id = s.room_id
     JOIN practitioners p ON p.id = r.practitioner_id
     ORDER BY sr.created_at DESC
     LIMIT 200'
)->fetchAll();

// ── Blocked IPs data ────────────────────────────────────────────
$blockedIps = $pdo->query(
    'SELECT * FROM ip_blocks ORDER BY created_at DESC LIMIT 100'
)->fetchAll();

// ── GDPR deletion requests ───────────────────────────────────────
$gdprRequests = $pdo->query(
    "SELECT g.*, eu.display_name AS user_name
     FROM gdpr_deletion_requests g
     LEFT JOIN end_users eu ON eu.id = g.end_user_id
     ORDER BY FIELD(g.status,'pending','completed','cancelled'), g.requested_at DESC
     LIMIT 200"
)->fetchAll();

// ── License submissions ───────────────────────────────────────────
$licenseSubmissions = $pdo->query(
    "SELECT ls.*, p.display_name AS practitioner_name, p.email AS practitioner_email
     FROM license_submissions ls
     JOIN practitioners p ON p.id = ls.practitioner_id
     ORDER BY FIELD(ls.status,'pending','approved','rejected'), ls.submitted_at DESC
     LIMIT 200"
)->fetchAll();
$pendingLicenses = count(array_filter($licenseSubmissions, fn($r) => $r['status'] === 'pending'));

// ── Cron Manager data ────────────────────────────────────────────
require_once __DIR__ . '/db/cron_logger.php';
require_once __DIR__ . '/includes/cron_autotrigger.php';

$cronDefs = [
    'firewall_cleanup'     => ['label'=>'Firewall Cleanup',      'schedule'=>'Every hour',       'interval'=>3600,  'cmd'=>'cron/firewall_cleanup.php'],
    'audit_ip_purge'       => ['label'=>'Audit IP Purge (GDPR)', 'schedule'=>'Every day',         'interval'=>86400, 'cmd'=>'cron/audit_ip_purge.php'],
    'appointment_reminder' => ['label'=>'Appointment Reminders', 'schedule'=>'Every hour',        'interval'=>3600,  'cmd'=>'cron/appointment_reminder.php'],
];
$cronMode = getSetting('cron_mode', 'system');
$cronKey  = getSetting('cron_web_key', '');
if ($cronKey === '' && $cronMode === 'web') {
    $cronKey = bin2hex(random_bytes(24));
    setSetting('cron_web_key', $cronKey);
}
$cronStatus = [];
try {
    foreach (array_keys($cronDefs) as $cronName) {
        $stmt = $pdo->prepare(
            'SELECT * FROM cron_log WHERE cron_name=? ORDER BY started_at DESC LIMIT 1'
        );
        $stmt->execute([$cronName]);
        $cronStatus[$cronName] = $stmt->fetch() ?: null;
    }
} catch (Throwable $e) {
    $cronStatus = []; // cron_log table may not exist yet on this install
}

// ── AI Integration settings ───────────────────────────────────────
$aiEnabled        = getSetting('ai_enabled', '0') === '1';
$aiAllowedVendors = json_decode(getSetting('ai_allowed_vendors', '["openai","anthropic","google","cohere"]'), true) ?? [];
$aiAllowedScopes  = json_decode(getSetting('ai_allowed_scopes',  '["summarization","notes","post_session","in_session"]'), true) ?? [];

// ── Compliance & Jurisdiction settings ───────────────────────────
$jurisPrimary     = getSetting('jurisdiction_primary', 'eu');
$jurisAdditional  = json_decode(getSetting('jurisdictions_additional', '[]'), true) ?? [];
$controllerName   = getSetting('controller_name',    '');
$controllerAddr   = getSetting('controller_address', '');
$controllerEmail  = getSetting('controller_email',   '');
$dpoName          = getSetting('dpo_name',           '');
$dpoEmail         = getSetting('dpo_email',          '');
$customPrivHtml   = getSetting('jurisdiction_custom_privacy_html', '');
$customRopaHtml   = getSetting('jurisdiction_custom_ropa_html',    '');
$installCountry   = getSetting('install_country', '');

// ── Launch Status checks ──────────────────────────────────────
$launchHostingDpa  = false;
$launchSmtpDpa     = false;
$launchDpiaSignOff = getSetting('dpia_signed_off', '0') === '1';
try {
    $launchHostingDpa = (bool)$pdo->query("SELECT id FROM dpa_documents WHERE scope='platform' AND type='hosting' LIMIT 1")->fetch();
    $launchSmtpDpa    = (bool)$pdo->query("SELECT id FROM dpa_documents WHERE scope='platform' AND type='smtp' LIMIT 1")->fetch();
} catch (Throwable $_e) {}
$launchCtrlName   = $controllerName  !== '';
$launchCtrlEmail  = $controllerEmail !== '';
$launchSmtpConfig = $smtpHost        !== '';
$launchProdMode   = $productionMode;
// Items that block license credential acceptance
$launchLicenseCriteriaMet = $launchHostingDpa && $launchSmtpDpa && $launchCtrlName && $launchCtrlEmail;
// Counts for nav badge
$launchBlockers = 0;
if (!$launchHostingDpa)                    $launchBlockers++;
if (!$launchSmtpDpa)                       $launchBlockers++;
if (!$launchCtrlName || !$launchCtrlEmail) $launchBlockers++;
$launchWarnings = 0;
if (!$launchSmtpConfig)  $launchWarnings++;
if (!$launchProdMode)    $launchWarnings++;
if (!$launchDpiaSignOff) $launchWarnings++;
$launchOpenCount = $launchBlockers + $launchWarnings;

// ── Linked Locations (Multi-DC) ───────────────────────────────────
$isPrimaryInstall = !defined('INSTALL_TYPE') || INSTALL_TYPE === 'primary';
$locations = $isPrimaryInstall
    ? $pdo->query('SELECT * FROM locations ORDER BY created_at ASC')->fetchAll()
    : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Panel — Serenity Spaces</title>
  <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body class="dashboard-page theme-<?= $appTheme ?>" style="overflow:auto;">
<?php renderBanners('admin'); ?>

<header class="dashboard-header">
  <?php if ($appLogo): ?>
    <a href="/dashboard.php"><img src="<?= htmlspecialchars($appLogo) ?>" alt="Logo" class="app-logo-img" style="max-height:48px;max-width:160px;"></a>
  <?php else: ?>
    <a href="/dashboard.php" class="app-name" style="text-decoration:none;">Serenity Spaces</a>
  <?php endif; ?>
  <nav style="display:flex;align-items:center;gap:12px;">
    <a href="/dashboard.php" class="btn btn-ghost btn-sm">← Dashboard</a>
  </nav>
</header>

<div class="settings-layout">

  <!-- ── Sidebar nav ── -->
  <nav class="settings-nav" aria-label="Admin sections">
    <button class="settings-nav-item" data-section="launch" style="margin-bottom:4px;">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M15.59 14.37a6 6 0 01-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 006.16-12.12A14.98 14.98 0 009.631 8.41m5.96 5.96a14.926 14.926 0 01-5.841 2.58m-.119-8.54a6 6 0 00-7.381 5.84h4.8m2.581-5.84a14.927 14.927 0 00-2.58 5.84m2.699 2.7c-.103.021-.207.041-.311.06a15.09 15.09 0 01-2.448-2.448 14.9 14.9 0 01.06-.312m-2.24 2.39a4.493 4.493 0 00-1.757 4.306 4.493 4.493 0 004.306-1.758M16.5 9a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/></svg>
      Launch Status
      <?php if ($launchBlockers > 0): ?>
        <span style="margin-left:auto;background:rgba(239,83,80,0.2);color:#ef9a9a;font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;white-space:nowrap;"><?= $launchBlockers ?> OPEN</span>
      <?php elseif ($launchWarnings > 0): ?>
        <span style="margin-left:auto;background:rgba(245,200,66,0.18);color:#f5c842;font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;white-space:nowrap;"><?= $launchWarnings ?> ADVISORY</span>
      <?php else: ?>
        <span style="margin-left:auto;background:rgba(62,207,142,0.18);color:#3ecf8e;font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;">READY</span>
      <?php endif; ?>
    </button>
    <div class="settings-nav-label">Settings</div>
    <button class="settings-nav-item" data-section="general">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      General
    </button>
    <button class="settings-nav-item" data-section="homepage">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>
      Home Page
    </button>
    <button class="settings-nav-item" data-section="banners">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h18v4H3zM3 10h18M3 14h18M3 18h12"/></svg>
      Banners
    </button>
    <button class="settings-nav-item" data-section="features">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zm0 9.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zm9.75-9.75A2.25 2.25 0 0115.75 3.75H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zm0 9.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
      Room Features
    </button>
    <button class="settings-nav-item" data-section="email">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
      Email / SMTP
    </button>
    <?php if ($isPrimaryInstall): ?>
    <button class="settings-nav-item" data-section="linked-locations">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg>
      Linked Locations
    </button>
    <?php endif; ?>
    <div class="settings-nav-label" style="margin-top:8px;">Users</div>
    <button class="settings-nav-item" data-section="users">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
      Manage Users
      <?php if (count($pendingPractitioners) > 0): ?>
        <span style="margin-left:auto;background:rgba(245,200,66,0.18);color:#f5c842;font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;min-width:18px;text-align:center;"><?= count($pendingPractitioners) ?></span>
      <?php endif; ?>
    </button>
    <button class="settings-nav-item" data-section="add-user">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21a12.318 12.318 0 01-6.374-1.765z"/></svg>
      Add Account
    </button>
    <div class="settings-nav-label" style="margin-top:8px;">Insights</div>
    <button class="settings-nav-item" data-section="reviews">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"/></svg>
      Session Reviews
    </button>
    <button class="settings-nav-item" data-section="licenses">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.746 3.746 0 01-1.043-3.297A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.746 3.746 0 011.043-3.297 3.746 3.746 0 013.297-1.043A3.745 3.745 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.297A3.745 3.745 0 0121 12z"/></svg>
      License Review
      <?php if ($pendingLicenses > 0): ?>
        <span style="margin-left:auto;background:rgba(239,83,80,0.18);color:#ef9a9a;font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;min-width:18px;text-align:center;"><?= $pendingLicenses ?></span>
      <?php endif; ?>
    </button>
    <button class="settings-nav-item" data-section="security">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
      Security
    </button>
    <button class="settings-nav-item" data-section="gdpr">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
      GDPR
      <?php $pendingGdpr = count(array_filter($gdprRequests, fn($r) => $r['status'] === 'pending')); ?>
      <?php if ($pendingGdpr > 0): ?>
        <span style="margin-left:auto;background:rgba(239,83,80,0.18);color:#ef9a9a;font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;min-width:18px;text-align:center;"><?= $pendingGdpr ?></span>
      <?php endif; ?>
    </button>
    <button class="settings-nav-item" data-section="dpa">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
      DPA Documents
    </button>
    <button class="settings-nav-item" data-section="email-templates">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
      Email Templates
    </button>
    <button class="settings-nav-item" data-section="compliance">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582M21 12H3"/></svg>
      Compliance
    </button>
    <div class="settings-nav-label" style="margin-top:8px;">System</div>
    <button class="settings-nav-item" data-section="cron-manager">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 15" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="1" fill="currentColor"/></svg>
      Cron Manager
      <?php
        $hasCronIssue = false;
        foreach ($cronDefs as $name => $def) {
            $last = $cronStatus[$name] ?? null;
            if ($last && $last['status'] === 'error') { $hasCronIssue = true; break; }
            if ($cronMode === 'system' && !$last) { /* never run — no badge */ }
        }
      ?>
      <?php if ($hasCronIssue): ?>
        <span style="margin-left:auto;background:rgba(239,83,80,0.18);color:#ef9a9a;font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;">ERR</span>
      <?php endif; ?>
    </button>
    <div class="settings-nav-label" style="margin-top:8px;">Clinical</div>
    <button class="settings-nav-item" data-section="crisis-resources">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
      Crisis Resources
    </button>
    <div class="settings-nav-label" style="margin-top:8px;">AI</div>
    <button class="settings-nav-item" data-section="ai-integration">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z"/></svg>
      AI Integration
      <?php if ($aiEnabled): ?>
        <span style="margin-left:auto;background:rgba(124,106,247,0.18);color:#a89ff7;font-size:10px;font-weight:700;padding:1px 6px;border-radius:8px;">ON</span>
      <?php endif; ?>
    </button>
  </nav>

  <!-- ── Main content ── -->
  <div class="settings-main">

    <?php if ($error): ?>
      <div class="alert alert-error" style="margin-bottom:20px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success" style="margin-bottom:20px;"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- ── Launch Status ── -->
    <div class="settings-section" id="section-launch">
      <div class="settings-section-title">Launch Status</div>
      <div class="settings-section-sub">Platform readiness checklist. Items marked <strong>BLOCKS LICENSE</strong> must be completed before practitioners can submit credentials for licensed status. Items marked <strong>ADVISORY</strong> are strongly recommended but do not block operation.</div>

      <?php
      // Helper: inline status badge HTML
      function launchBadge(bool $ok, string $doneLabel = 'Done', string $openLabel = 'Action Required', bool $isBlocker = false): string {
          if ($ok) {
              return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;background:rgba(62,207,142,0.14);color:#3ecf8e;border:1px solid rgba(62,207,142,0.25);">✓ ' . htmlspecialchars($doneLabel) . '</span>';
          }
          if ($isBlocker) {
              return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;background:rgba(239,83,80,0.14);color:#ef9a9a;border:1px solid rgba(239,83,80,0.25);">⚠ ' . htmlspecialchars($openLabel) . '</span>';
          }
          return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;background:rgba(245,200,66,0.14);color:#f5c842;border:1px solid rgba(245,200,66,0.25);">⚠ ' . htmlspecialchars($openLabel) . '</span>';
      }
      function launchScopeBadge(string $scope): string {
          return match($scope) {
              'all'       => '<span style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;background:rgba(74,158,255,0.14);color:#4a9eff;border:1px solid rgba(74,158,255,0.2);letter-spacing:0.04em;">ALL PRACTITIONERS</span>',
              'licensed'  => '<span style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;background:rgba(124,106,247,0.14);color:#a89ff7;border:1px solid rgba(124,106,247,0.2);letter-spacing:0.04em;">LICENSED ONLY</span>',
              'cond'      => '<span style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;background:rgba(200,210,255,0.08);color:rgba(200,210,255,0.5);border:1px solid rgba(200,210,255,0.12);letter-spacing:0.04em;">CONDITIONAL</span>',
              default     => '',
          };
      }
      function launchBlockerBadge(): string {
          return '<span style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;background:rgba(239,83,80,0.12);color:#ef9a9a;border:1px solid rgba(239,83,80,0.2);letter-spacing:0.04em;">BLOCKS LICENSE</span>';
      }
      function launchRow(string $title, string $scope, bool $done, string $doneLbl, string $openLbl, bool $blocker, string $desc, string $fixLabel = '', string $fixSection = ''): void {
          $bg = $done ? 'rgba(62,207,142,0.04)' : ($blocker ? 'rgba(239,83,80,0.04)' : 'rgba(245,200,66,0.04)');
          $border = $done ? 'rgba(62,207,142,0.12)' : ($blocker ? 'rgba(239,83,80,0.15)' : 'rgba(245,200,66,0.15)');
          echo '<div style="padding:14px 16px;border-radius:10px;border:1px solid ' . $border . ';background:' . $bg . ';margin-bottom:10px;">';
          echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">';
          echo '<span style="font-size:13px;font-weight:600;color:#e8eeff;">' . htmlspecialchars($title) . '</span>';
          echo launchScopeBadge($scope);
          if ($blocker && !$done) echo launchBlockerBadge();
          echo launchBadge($done, $doneLbl, $openLbl, $blocker);
          echo '</div>';
          echo '<div style="font-size:12.5px;color:rgba(200,210,255,0.6);line-height:1.55;margin-bottom:' . ($fixLabel && !$done ? '10px' : '0') . ';">' . $desc . '</div>';
          if ($fixLabel && !$done && $fixSection) {
              echo '<a href="#' . htmlspecialchars($fixSection) . '" onclick="showSection(\'' . htmlspecialchars($fixSection) . '\');return false;" '
                 . 'style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:#a89ff7;text-decoration:none;padding:4px 10px;border-radius:6px;background:rgba(124,106,247,0.1);border:1px solid rgba(124,106,247,0.2);">'
                 . '→ ' . htmlspecialchars($fixLabel) . '</a>';
          }
          echo '</div>';
      }
      ?>

      <!-- Overall status banner -->
      <div style="padding:14px 18px;border-radius:12px;border:1px solid <?= $launchBlockers > 0 ? 'rgba(239,83,80,0.3)' : ($launchWarnings > 0 ? 'rgba(245,200,66,0.3)' : 'rgba(62,207,142,0.3)') ?>;background:<?= $launchBlockers > 0 ? 'rgba(239,83,80,0.07)' : ($launchWarnings > 0 ? 'rgba(245,200,66,0.07)' : 'rgba(62,207,142,0.07)') ?>;margin-bottom:20px;display:flex;align-items:center;gap:14px;">
        <div style="font-size:22px;line-height:1;"><?= $launchBlockers > 0 ? '⚠' : ($launchWarnings > 0 ? '📋' : '✅') ?></div>
        <div>
          <div style="font-size:14px;font-weight:700;color:#e8eeff;margin-bottom:2px;">
            <?php if ($launchBlockers > 0): ?>
              <?= $launchBlockers ?> blocking item<?= $launchBlockers !== 1 ? 's' : '' ?> — not ready to accept licensed practitioners
            <?php elseif ($launchWarnings > 0): ?>
              Minimum requirements met — <?= $launchWarnings ?> advisory item<?= $launchWarnings !== 1 ? 's' : '' ?> remaining
            <?php else: ?>
              All items complete — platform is ready to operate and accept licensed practitioners
            <?php endif; ?>
          </div>
          <div style="font-size:12px;color:rgba(200,210,255,0.55);">
            <?php if (!$launchLicenseCriteriaMet): ?>
              Credential submissions are currently <strong style="color:#ef9a9a;">locked</strong> — practitioners cannot submit credentials for licensed status until all blocking items are resolved.
            <?php else: ?>
              Credential submissions are <strong style="color:#3ecf8e;">open</strong> — practitioners can submit credentials for licensed status review.
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- ── Section: All Practitioners ── -->
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:rgba(200,210,255,0.4);font-weight:700;margin-bottom:10px;">Required — All Practitioners</div>

      <?php launchRow(
          'Hosting Provider DPA',
          'all',
          $launchHostingDpa,
          'Uploaded',
          'Upload Required',
          true,
          'UK GDPR Art. 28 requires a signed Data Processing Agreement with every processor handling personal data on your behalf. All PHI is stored on your hosting server — this DPA must be in place before any client data is processed. Contact your hosting provider and request their standard DPA. Confirm data residency is within UK/EEA.',
          'Upload Hosting DPA',
          'dpa'
      ); ?>

      <?php launchRow(
          'SMTP / Email Provider DPA',
          'all',
          $launchSmtpDpa,
          'Uploaded',
          'Upload Required',
          true,
          'Booking confirmations, password resets, and session invitations carry personal data. Your SMTP provider processes this data on your behalf and requires an Art. 28 DPA. Major providers (Postmark, Mailgun, SendGrid, Brevo) offer GDPR DPAs online. Confirm data residency.',
          'Upload SMTP DPA',
          'dpa'
      ); ?>

      <?php launchRow(
          'Data Controller Details',
          'all',
          $launchCtrlName && $launchCtrlEmail,
          'Configured',
          'Incomplete',
          true,
          'GDPR Art. 13 requires data subjects to be informed who the data controller is. Your Privacy Notice and ROPA are incomplete until a controller name and contact email are set. This is the legal identity of the person or organisation operating this platform.' . (!$launchCtrlName ? ' <strong style="color:#ef9a9a;">Controller name missing.</strong>' : '') . (!$launchCtrlEmail ? ' <strong style="color:#ef9a9a;">Controller email missing.</strong>' : ''),
          'Set Controller Details',
          'compliance'
      ); ?>

      <?php launchRow(
          'SMTP Configuration',
          'all',
          $launchSmtpConfig,
          'Configured',
          'Not Configured',
          false,
          'Without SMTP, no emails are sent — booking confirmations, session invitations, and password reset links will all fail silently. Configure your outgoing mail server in Email / SMTP settings.',
          'Configure SMTP',
          'email'
      ); ?>

      <?php launchRow(
          'Production Mode',
          'all',
          $launchProdMode,
          'Enabled',
          'Not Enabled',
          false,
          'Production Mode enables HTTP security headers (HSTS, CSP, X-Frame-Options), enforces HTTPS, and activates the brute-force firewall. It should be enabled before any real clients use the platform. Disable only during initial configuration on a secure local/test environment.',
          'Enable Production Mode',
          'general'
      ); ?>

      <!-- ── Section: Advisory ── -->
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:rgba(200,210,255,0.4);font-weight:700;margin-bottom:10px;margin-top:20px;">Advisory — Strongly Recommended</div>

      <!-- DPIA row with toggle -->
      <div style="padding:14px 16px;border-radius:10px;border:1px solid <?= $launchDpiaSignOff ? 'rgba(62,207,142,0.12)' : 'rgba(245,200,66,0.15)' ?>;background:<?= $launchDpiaSignOff ? 'rgba(62,207,142,0.04)' : 'rgba(245,200,66,0.04)' ?>;margin-bottom:10px;">
        <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
          <div style="flex:1;min-width:240px;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
              <span style="font-size:13px;font-weight:600;color:#e8eeff;">DPIA Reviewed &amp; Signed Off</span>
              <?= launchScopeBadge('all') ?>
              <?= launchBadge($launchDpiaSignOff, 'Signed Off', 'Pending Review', false) ?>
            </div>
            <div style="font-size:12.5px;color:rgba(200,210,255,0.6);line-height:1.55;">
              A Data Protection Impact Assessment is required under GDPR Art. 35 for high-risk processing. Your living DPIA is at
              <a href="/dpia.php" target="_blank" style="color:#a89ff7;">/dpia.php</a>. Sign-offs are recorded digitally in the DPIA itself — review the document, use the sign-off form on the Conclusion page, then mark this item done. The DPIA tracks its own review history and will surface an overdue warning when annual review is due.
              <strong style="color:rgba(245,200,66,0.8);display:block;margin-top:4px;">Critical for licensed / clinical deployments. Best practice for all.</strong>
            </div>
          </div>
          <form method="post" style="margin:0;flex-shrink:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="toggle_dpia_signoff">
            <button type="submit" class="btn btn-ghost btn-sm" style="font-size:12px;white-space:nowrap;">
              <?= $launchDpiaSignOff ? 'Mark as Not Done' : 'Mark as Signed Off' ?>
            </button>
          </form>
        </div>
      </div>

      <!-- ── Section: Conditional ── -->
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:rgba(200,210,255,0.4);font-weight:700;margin-bottom:10px;margin-top:20px;">Conditional — Required When Feature Is Enabled</div>

      <?php launchRow(
          'Payment Processor DPA(s)',
          'cond',
          false,
          'Uploaded',
          'Needed If Payments Enabled',
          false,
          'Applies to <strong>both non-licensed and licensed practitioners</strong> who take payments. Before any practitioner enables Stripe, PayPal, or Square, they must upload that processor\'s Art. 28 DPA from their Practitioner Profile → Data Processing Agreements. The platform blocks payment gateway activation until a DPA is on file. Stripe and PayPal offer standard DPAs via their compliance portals.',
          '',
          ''
      ); ?>

      <?php launchRow(
          'AI Vendor DPA(s)',
          'cond',
          false,
          'Uploaded',
          'Needed If AI Integration Enabled',
          false,
          'Applies to <strong>both non-licensed and licensed practitioners</strong> who use AI features. Before any practitioner enables an AI vendor (OpenAI, Anthropic, Google, Cohere), they must upload that vendor\'s Art. 28 DPA from their Practitioner Profile → Data Processing Agreements. The platform blocks AI activation until a DPA is on file. You must also ensure the AI vendor is on the platform allowlist in AI Integration settings.',
          '',
          ''
      ); ?>

      <!-- ── Section: Licensed Status ── -->
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:rgba(200,210,255,0.4);font-weight:700;margin-bottom:10px;margin-top:20px;">Licensed Practitioners</div>

      <div style="padding:14px 16px;border-radius:10px;border:1px solid <?= $launchLicenseCriteriaMet ? 'rgba(62,207,142,0.12)' : 'rgba(239,83,80,0.15)' ?>;background:<?= $launchLicenseCriteriaMet ? 'rgba(62,207,142,0.04)' : 'rgba(239,83,80,0.04)' ?>;margin-bottom:10px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
          <span style="font-size:13px;font-weight:600;color:#e8eeff;">Credential Submission Gate</span>
          <?= launchScopeBadge('licensed') ?>
          <?= launchBadge($launchLicenseCriteriaMet, 'Open — Submissions Accepted', 'Locked — Submissions Blocked', true) ?>
        </div>
        <div style="font-size:12.5px;color:rgba(200,210,255,0.6);line-height:1.55;">
          <?php if ($launchLicenseCriteriaMet): ?>
            The minimum platform requirements for accepting licensed practitioners are met. Practitioners can submit credentials from their Profile, and you can approve them in
            <a href="#licenses" onclick="showSection('licenses');return false;" style="color:#a89ff7;">License Verification</a>.
            Note: you <strong>can</strong> operate an entirely non-licensed platform — coaches, peer support specialists, and life coaches do not require credential verification and are unaffected by this gate.
          <?php else: ?>
            Credential submissions are locked because one or more blocking items above are incomplete. Practitioners cannot submit credentials for licensed status, and the submission form will not be shown on their profile. Resolve the <strong style="color:#ef9a9a;"><?= $launchBlockers ?> blocking item<?= $launchBlockers !== 1 ? 's' : '' ?></strong> above to unlock.
            <br><br>
            <strong>Note:</strong> Your platform can operate fully for non-licensed practitioners (coaches, peer support specialists, life coaches) regardless of this gate. Only practitioners seeking licensed clinical status are affected.
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- ── General Settings ── -->
    <div class="settings-section" id="section-general">
      <div class="settings-section-title">General Settings</div>
      <div class="settings-section-sub">Platform appearance and access controls.</div>
      <div class="glass-panel admin-settings-panel">

        <div class="admin-setting-row" style="background:<?= $productionMode ? 'rgba(62,207,142,0.06)' : 'rgba(252,196,25,0.06)' ?>;border-radius:10px;padding:14px 16px;margin:-4px;">
          <div>
            <div class="admin-setting-label" style="display:flex;align-items:center;gap:8px;">
              Production Mode
              <?php if (!$productionMode): ?>
                <span style="background:rgba(252,196,25,0.18);color:#f5c842;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;letter-spacing:0.04em;">SETUP MODE</span>
              <?php else: ?>
                <span style="background:rgba(62,207,142,0.18);color:#3ecf8e;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;letter-spacing:0.04em;">LIVE</span>
              <?php endif; ?>
            </div>
            <div class="text-muted" style="font-size:13px;margin-top:4px;">
              <?php if (!$productionMode): ?>
                <strong style="color:#f5c842;">Currently in Setup Mode.</strong> Bookings and client sign-ups are disabled. Only practitioner registration is allowed. Enable Production Mode once SSL is configured and the platform is ready for clients.
              <?php else: ?>
                Platform is live. Bookings, client sign-ups, and HTTP security headers are all active.
              <?php endif; ?>
            </div>
          </div>
          <form method="post" style="flex-shrink:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="toggle_production_mode">
            <button type="submit" class="btn <?= $productionMode ? 'btn-success' : 'btn-warning' ?> btn-sm" style="<?= !$productionMode ? 'background:rgba(245,200,66,0.15);color:#f5c842;border:1px solid rgba(245,200,66,0.35);' : '' ?>">
              <?= $productionMode ? 'Enabled' : 'Disabled' ?>
            </button>
          </form>
        </div>

        <div class="admin-setting-divider"></div>

        <div class="admin-setting-row">
          <div>
            <div class="admin-setting-label">Public Registration</div>
            <div class="text-muted" style="font-size:13px;">Allow new practitioners to self-register via the sign-up page.</div>
          </div>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="toggle_registrations">
            <button type="submit" class="btn <?= $regEnabled ? 'btn-success' : 'btn-danger' ?> btn-sm">
              <?= $regEnabled ? 'Enabled' : 'Disabled' ?>
            </button>
          </form>
        </div>

        <div class="admin-setting-divider"></div>

        <div class="admin-setting-row">
          <div>
            <div class="admin-setting-label">Require Signup Approval</div>
            <div class="text-muted" style="font-size:13px;">When enabled, new practitioner accounts are Pending until manually approved. Practitioners receive an email on approval or rejection.</div>
          </div>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="toggle_require_approval">
            <button type="submit" class="btn <?= $requireApproval ? 'btn-success' : 'btn-ghost' ?> btn-sm">
              <?= $requireApproval ? 'Required' : 'Not Required' ?>
            </button>
          </form>
        </div>

        <div class="admin-setting-divider"></div>

        <div class="admin-setting-row">
          <div>
            <div class="admin-setting-label">Interface Theme</div>
            <div class="text-muted" style="font-size:13px;">Applies to all pages except the session room.</div>
          </div>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="toggle_theme">
            <button type="submit" class="btn btn-ghost btn-sm">
              <?= $appTheme === 'light' ? '☀ Light Mode' : '☾ Dark Mode' ?>
            </button>
          </form>
        </div>

        <div class="admin-setting-divider"></div>

        <div class="admin-setting-row" style="align-items:flex-start;gap:24px;flex-wrap:wrap;">
          <div style="flex:1;min-width:200px;">
            <div class="admin-setting-label">Custom Logo</div>
            <div class="text-muted" style="font-size:13px;">Replaces the "Serenity Spaces" text in the header. JPEG, PNG, GIF, WEBP, or SVG, max 2 MB.</div>
            <?php if ($currentLogo): ?>
              <div style="margin-top:10px;display:flex;align-items:center;gap:12px;">
                <img src="<?= htmlspecialchars($currentLogo) ?>" alt="Current logo"
                     style="max-height:40px;max-width:160px;object-fit:contain;background:rgba(255,255,255,0.05);border-radius:4px;padding:4px;">
                <form method="post" style="margin:0;">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="remove_logo">
                  <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                </form>
              </div>
            <?php endif; ?>
          </div>
          <form method="post" enctype="multipart/form-data" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="upload_logo">
            <label class="btn btn-ghost btn-sm" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
              <?= $currentLogo ? 'Replace Logo' : 'Upload Logo' ?>
              <input type="file" name="logo_file" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml"
                     style="display:none" class="js-auto-submit">
            </label>
          </form>
        </div>
      </div>
    </div>

    <!-- ── Room Features ── -->
    <div class="settings-section" id="section-features">
      <div class="settings-section-title">Room Features</div>
      <div class="settings-section-sub">Control what participants can do inside session rooms.</div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="save_feature_settings">
        <div class="glass-panel admin-settings-panel">

          <div class="admin-setting-row">
            <div><div class="admin-setting-label">Webcam</div><div class="text-muted" style="font-size:13px;">Allow participants to share live webcam frames.</div></div>
            <label class="toggle-switch"><input type="checkbox" name="allow_webcam" value="1" <?= $allowWebcam ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
          </div>
          <div class="admin-setting-divider"></div>
          <div class="admin-setting-row">
            <div><div class="admin-setting-label">Voice Chat</div><div class="text-muted" style="font-size:13px;">Allow real-time WebRTC voice/video calls in the room.</div></div>
            <label class="toggle-switch"><input type="checkbox" name="allow_voice_chat" value="1" <?= $allowVoiceChat ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
          </div>
          <div class="admin-setting-divider"></div>
          <div class="admin-setting-row">
            <div><div class="admin-setting-label">Voice Notes</div><div class="text-muted" style="font-size:13px;">Allow participants to record and send voice note messages.</div></div>
            <label class="toggle-switch"><input type="checkbox" name="allow_voice_notes" value="1" <?= $allowVoiceNotes ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
          </div>
          <div class="admin-setting-divider"></div>
          <div class="admin-setting-row">
            <div><div class="admin-setting-label">File Attachments</div><div class="text-muted" style="font-size:13px;">Allow images, videos, and files to be shared in the chat.</div></div>
            <label class="toggle-switch"><input type="checkbox" name="allow_attachments" value="1" <?= $allowAttach ? 'checked' : '' ?>><span class="toggle-slider"></span></label>
          </div>
          <div class="admin-setting-divider"></div>
          <div class="admin-setting-row" style="gap:24px;flex-wrap:wrap;">
            <div style="flex:1;min-width:180px;"><div class="admin-setting-label">Message Rate Limit</div><div class="text-muted" style="font-size:13px;">Max messages per participant per minute (5–300).</div></div>
            <div style="display:flex;align-items:center;gap:8px;">
              <input type="number" name="rate_limit_per_min" value="<?= $rateLimitMin ?>" min="5" max="300" step="5" style="width:80px;">
              <span class="text-muted" style="font-size:13px;">/ min</span>
            </div>
          </div>
          <div class="admin-setting-divider"></div>
          <div class="admin-setting-row" style="gap:24px;flex-wrap:wrap;">
            <div style="flex:1;min-width:180px;"><div class="admin-setting-label">Max Participants</div><div class="text-muted" style="font-size:13px;">Maximum participants per session (2–10).</div></div>
            <div style="display:flex;align-items:center;gap:8px;">
              <input type="number" name="max_participants" value="<?= $maxParticipants ?>" min="2" max="10" style="width:70px;">
              <span class="text-muted" style="font-size:13px;">people</span>
            </div>
          </div>
          <div style="padding-top:16px;">
            <button type="submit" class="btn btn-primary btn-sm">Save Feature Settings</button>
          </div>
        </div>
      </form>
    </div>

    <!-- ── Email / SMTP ── -->
    <div class="settings-section" id="section-email">
      <div class="settings-section-title">Email / SMTP</div>
      <div class="settings-section-sub">Configure outgoing email for booking confirmations and notifications.</div>
      <div class="glass-panel admin-settings-panel">
        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="save_smtp">
          <div class="form-row" style="flex-wrap:wrap;gap:12px;">
            <div class="form-group flex-1" style="min-width:200px;">
              <label>SMTP hostname</label>
              <input type="text" name="smtp_host" value="<?= htmlspecialchars($smtpHost) ?>" placeholder="mail.example.com">
            </div>
            <div class="form-group" style="width:100px;">
              <label>Port</label>
              <input type="number" name="smtp_port" value="<?= htmlspecialchars($smtpPort) ?>" min="1" max="65535" placeholder="587">
            </div>
            <div class="form-group" style="width:200px;">
              <label>Encryption</label>
              <select name="smtp_encryption">
                <?php foreach (['tls'=>'STARTTLS (587)','ssl'=>'SSL (465)','none'=>'None'] as $v=>$l): ?>
                  <option value="<?= $v ?>" <?= $smtpEnc === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-row" style="flex-wrap:wrap;gap:12px;">
            <div class="form-group flex-1" style="min-width:200px;">
              <label>SMTP username</label>
              <input type="email" name="smtp_user" value="<?= htmlspecialchars($smtpUser) ?>" placeholder="notifications@example.com">
            </div>
            <div class="form-group flex-1" style="min-width:200px;">
              <label>Password <?= $smtpHost ? '<span class="text-muted">(leave blank to keep existing)</span>' : '' ?></label>
              <input type="password" name="smtp_pass" autocomplete="new-password">
            </div>
          </div>
          <div class="form-row" style="flex-wrap:wrap;gap:12px;">
            <div class="form-group flex-1" style="min-width:180px;">
              <label>From name</label>
              <input type="text" name="smtp_from_name" value="<?= htmlspecialchars($smtpFromName) ?>" placeholder="Serenity Spaces">
            </div>
            <div class="form-group flex-1" style="min-width:200px;">
              <label>From email address</label>
              <input type="email" name="smtp_from_email" value="<?= htmlspecialchars($smtpFromEmail) ?>" placeholder="noreply@example.com">
            </div>
          </div>
          <div style="padding-top:16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary btn-sm">Save SMTP Settings</button>
            <?php if ($smtpHost): ?><a href="/setup.php?smtp=1" class="btn btn-ghost btn-sm">Re-run Wizard</a><?php endif; ?>
          </div>
        </form>
        <?php if ($smtpHost): ?>
        <?php
          $allTemplates = get_default_email_templates();
        ?>
        <div style="padding-top:16px;border-top:1px solid var(--border);margin-top:16px;">
          <div class="admin-setting-label" style="margin-bottom:6px;">Test Configuration</div>
          <div class="text-muted" style="font-size:13px;margin-bottom:12px;">Send a test email to verify your SMTP settings and check templates for deliverability or spam triggers.</div>
          <form method="post" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="test_smtp">
            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
              <div class="form-group" style="flex:1;min-width:200px;margin-bottom:0;">
                <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Send to</label>
                <input type="email" name="test_to_email" value="<?= htmlspecialchars($me['email']) ?>" placeholder="recipient@example.com" style="width:100%;">
              </div>
              <div class="form-group" style="flex:1;min-width:220px;margin-bottom:0;">
                <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Template</label>
                <select name="test_template" style="width:100%;">
                  <?php foreach ($allTemplates as $tKey => $tDef): ?>
                  <option value="<?= htmlspecialchars($tKey) ?>"<?= $tKey === 'smtp_test' ? ' selected' : '' ?>><?= htmlspecialchars($tDef['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="flex-shrink:0;">
                <button type="submit" class="btn btn-ghost btn-sm">Send Test Email</button>
              </div>
            </div>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Linked Locations ── -->
    <?php if ($isPrimaryInstall): ?>
    <?php
      $locSecretFlash = $_SESSION['location_secret_flash'] ?? null;
      unset($_SESSION['location_secret_flash']);
    ?>
    <div class="settings-section" id="section-linked-locations">
      <div class="settings-section-title">Linked Locations</div>
      <div class="settings-section-sub">Connect Alternative Location servers so clients in specific regions can have their session data stored closer to them.</div>

      <?php if ($locSecretFlash): ?>
      <div style="background:rgba(62,207,142,.08);border:1px solid rgba(62,207,142,.25);border-radius:8px;padding:14px 16px;margin-bottom:20px;">
        <div style="font-weight:600;font-size:13px;color:#3ecf8e;margin-bottom:6px;">Location added — copy your shared secret now</div>
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:10px;">
          This secret is shown <strong style="color:var(--text);">once only</strong>. Copy it and paste it into the setup wizard on
          <strong style="color:var(--text);"><?= htmlspecialchars($locSecretFlash['label']) ?></strong>.
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <code id="loc-secret-flash" style="background:rgba(0,0,0,.35);border:1px solid var(--border);border-radius:5px;padding:8px 12px;font-size:12px;word-break:break-all;flex:1;"><?= htmlspecialchars($locSecretFlash['secret']) ?></code>
          <button id="copy-loc-secret-btn"
                  style="background:var(--accent);color:#fff;border:none;border-radius:6px;padding:8px 12px;cursor:pointer;font-size:12px;white-space:nowrap;">
            Copy
          </button>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($locations): ?>
      <div style="margin-bottom:20px;">
        <?php foreach ($locations as $loc): ?>
        <div style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:14px 16px;margin-bottom:10px;display:flex;align-items:center;gap:16px;">
          <div style="flex:1;min-width:0;">
            <div style="font-weight:600;font-size:14px;color:var(--text);margin-bottom:2px;"><?= htmlspecialchars($loc['label']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);">
              <span style="display:inline-block;background:rgba(124,106,247,.12);color:var(--accent);border-radius:4px;padding:1px 7px;font-size:11px;font-weight:600;margin-right:6px;"><?= htmlspecialchars($loc['country']) ?></span>
              <code style="font-size:11px;"><?= htmlspecialchars($loc['url']) ?></code>
            </div>
            <?php if ($loc['last_authenticated']): ?>
            <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Last seen: <?= htmlspecialchars($loc['last_authenticated']) ?></div>
            <?php else: ?>
            <div style="font-size:11px;color:#e07a30;margin-top:3px;">Never authenticated — configure the Alternative server to connect</div>
            <?php endif; ?>
          </div>
          <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
            <span style="width:8px;height:8px;border-radius:50%;background:<?= $loc['is_active'] ? '#3ecf8e' : '#6b7280' ?>;display:inline-block;" title="<?= $loc['is_active'] ? 'Active' : 'Paused' ?>"></span>
            <form method="post" style="display:inline;">
              <input type="hidden" name="action" value="toggle_location">
              <input type="hidden" name="location_id" value="<?= (int)$loc['id'] ?>">
              <button type="submit" style="background:transparent;border:1px solid var(--border);border-radius:5px;padding:4px 10px;font-size:12px;color:var(--text-muted);cursor:pointer;">
                <?= $loc['is_active'] ? 'Pause' : 'Activate' ?>
              </button>
            </form>
            <form method="post" style="display:inline;" data-confirm="Remove this location? Clients assigned to it will fall back to the Primary server.">
              <input type="hidden" name="action" value="delete_location">
              <input type="hidden" name="location_id" value="<?= (int)$loc['id'] ?>">
              <button type="submit" style="background:transparent;border:1px solid rgba(239,83,80,.3);border-radius:5px;padding:4px 10px;font-size:12px;color:#ef9a9a;cursor:pointer;">
                Remove
              </button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div style="text-align:center;padding:28px 0;color:var(--text-muted);font-size:13px;margin-bottom:20px;">
        No linked locations yet. Add one below to enable multi-region session routing.
      </div>
      <?php endif; ?>

      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:18px 20px;">
        <div style="font-weight:600;font-size:14px;color:var(--text);margin-bottom:14px;">Add a location</div>
        <form method="post">
          <input type="hidden" name="action" value="add_location">
          <div style="display:grid;grid-template-columns:1fr 80px;gap:10px;margin-bottom:10px;">
            <div class="form-group" style="margin:0;">
              <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Label</label>
              <input type="text" name="location_label" placeholder="e.g. UK Server" style="width:100%;box-sizing:border-box;">
            </div>
            <div class="form-group" style="margin:0;">
              <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Country</label>
              <input type="text" name="location_country" maxlength="2" placeholder="GB" style="width:100%;box-sizing:border-box;text-transform:uppercase;">
            </div>
          </div>
          <div class="form-group" style="margin-bottom:12px;">
            <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Server URL</label>
            <input type="url" name="location_url" placeholder="https://uk.your-domain.example" style="width:100%;box-sizing:border-box;">
          </div>
          <p style="font-size:12px;color:var(--text-muted);margin:0 0 10px;line-height:1.5;">
            A unique shared secret will be generated and shown once after you save.
            Copy it to the Alternative server's setup wizard.
          </p>
          <button type="submit" class="btn btn-primary" style="padding:10px 20px;">Add Location</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Manage Users ── -->
    <div class="settings-section" id="section-users">
      <div class="settings-section-title">Manage Users</div>
      <div class="settings-section-sub">View, edit, and remove practitioner accounts.</div>

      <?php if ($editUser): ?>
      <div class="glass-panel" style="padding:24px;max-width:520px;margin-bottom:24px;">
        <div style="font-weight:600;margin-bottom:16px;">Editing: <?= htmlspecialchars($editUser['display_name'] ?: $editUser['email']) ?></div>
        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="edit_user">
          <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
          <div class="form-group"><label>Email</label><input type="email" name="edit_email" value="<?= htmlspecialchars($editUser['email']) ?>" required></div>
          <div class="form-group"><label>Display name</label><input type="text" name="edit_display_name" value="<?= htmlspecialchars($editUser['display_name'] ?? '') ?>"></div>
          <div class="form-group"><label>New password <span class="text-muted">(leave blank to keep current)</span></label><input type="password" name="edit_password" placeholder="••••••••" autocomplete="new-password"></div>
          <?php if ($editUser['id'] !== $practId): ?>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" id="edit_is_admin" name="edit_is_admin" value="1" <?= $editUser['is_admin'] ? 'checked' : '' ?>>
            <label for="edit_is_admin" style="margin:0;">Administrator</label>
          </div>
          <?php endif; ?>
          <div style="display:flex;gap:8px;margin-top:8px;">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="/admin.php#users" class="btn btn-ghost" data-section="users">Cancel</a>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <?php if (!empty($pendingPractitioners)): ?>
      <div class="glass-panel admin-settings-panel" style="margin-bottom:20px;border-color:rgba(245,200,66,0.3);">
        <div style="font-size:12px;font-weight:700;letter-spacing:.08em;color:#f5c842;text-transform:uppercase;margin-bottom:14px;">
          Pending Approval (<?= count($pendingPractitioners) ?>)
        </div>
        <?php foreach ($pendingPractitioners as $u): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid var(--border);flex-wrap:wrap;">
          <div>
            <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($u['display_name'] ?: '—') ?></div>
            <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($u['email']) ?> — registered <?= date('M j, Y', strtotime($u['created_at'])) ?></div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <form method="post" style="margin:0;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="approve_practitioner">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <button type="submit" class="btn btn-success btn-sm">Approve</button>
            </form>
            <button type="button" class="btn btn-sm btn-danger js-reject-btn"
                    data-uid="<?= (int)$u['id'] ?>"
                    data-name="<?= htmlspecialchars($u['display_name'] ?: $u['email'], ENT_QUOTES) ?>">Reject</button>
          </div>
        </div>
        <?php endforeach; ?>
        <!-- Reject form (shown inline via JS) -->
        <div id="reject-form-wrap" style="display:none;margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
          <form method="post" id="reject-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="reject_practitioner">
            <input type="hidden" name="user_id" id="reject-user-id" value="">
            <div style="font-size:13px;font-weight:600;margin-bottom:10px;">Reject: <span id="reject-user-name"></span></div>
            <div class="form-group" style="margin-bottom:10px;">
              <label style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Reason (optional — included in rejection email)</label>
              <textarea name="rejection_reason" rows="3" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:13px;resize:vertical;" placeholder="Reason for rejection…"></textarea>
            </div>
            <div style="display:flex;gap:8px;">
              <button type="submit" class="btn btn-sm btn-danger">Confirm Rejection</button>
              <button type="button" class="btn btn-sm btn-ghost" id="reject-cancel-btn">Cancel</button>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <div class="admin-user-table-wrap glass-panel">
        <table class="admin-user-table">
          <thead><tr><th>Name / Email</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($users as $u): $acctStatus = $u['account_status'] ?? 'active'; ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:10px;">
                  <?php if ($u['avatar_path']): ?>
                    <img src="<?= htmlspecialchars($u['avatar_path']) ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                  <?php else: ?>
                    <div style="width:48px;height:48px;border-radius:50%;background:var(--accent-dim);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px;">
                      <?= mb_strtoupper(mb_substr($u['display_name'] ?: $u['email'], 0, 1)) ?>
                    </div>
                  <?php endif; ?>
                  <div>
                    <div style="font-weight:600;font-size:13px;">
                      <?= htmlspecialchars($u['display_name'] ?: '—') ?>
                      <?= $u['id'] === $practId ? '<span class="admin-you-badge">you</span>' : '' ?>
                    </div>
                    <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($u['email']) ?></div>
                  </div>
                </div>
              </td>
              <td><?= $u['is_admin'] ? '<span class="role-badge role-admin">Admin</span>' : '<span class="role-badge role-user">Practitioner</span>' ?></td>
              <td>
                <?php if ($acctStatus === 'pending'): ?>
                  <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:rgba(245,200,66,0.12);color:#f5c842;">Pending</span>
                <?php elseif ($acctStatus === 'rejected'): ?>
                  <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:rgba(239,83,80,0.12);color:#ef9a9a;">Rejected</span>
                <?php else: ?>
                  <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:rgba(129,199,132,0.12);color:#a5d6a7;">Active</span>
                <?php endif; ?>
              </td>
              <td style="font-size:12px;color:var(--text-muted);"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
              <td>
                <div style="display:flex;gap:6px;">
                  <a href="/admin.php?edit=<?= $u['id'] ?>#users" class="btn btn-sm btn-ghost" data-section="users">Edit</a>
                  <?php if ($u['id'] !== $practId): ?>
                  <form method="post" style="margin:0;" data-confirm="Delete <?= htmlspecialchars($u['display_name'] ?: $u['email'], ENT_QUOTES) ?>?">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                  </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ── Add Account ── -->
    <div class="settings-section" id="section-add-user">
      <div class="settings-section-title">Add Account Manually</div>
      <div class="settings-section-sub">Create a practitioner account without requiring self-registration.</div>
      <div class="glass-panel" style="padding:24px;max-width:520px;">
        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="add_user">
          <div class="form-row">
            <div class="form-group flex-1"><label>Email</label><input type="email" name="new_email" placeholder="user@example.com" required></div>
            <div class="form-group flex-1"><label>Display name <span class="text-muted">(optional)</span></label><input type="text" name="new_display_name" placeholder="Full name"></div>
          </div>
          <div class="form-group">
            <label>Password</label>
            <input type="password" id="admin-new-password" name="new_password" placeholder="••••••••" required autocomplete="new-password">
            <?= password_meter('admin-new-password', true) ?>
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" id="new_is_admin" name="new_is_admin" value="1">
            <label for="new_is_admin" style="margin:0;">Grant administrator privileges</label>
          </div>
          <button type="submit" class="btn btn-primary">Create Account</button>
        </form>
      </div>
    </div>

    <!-- ── Session Reviews ── -->
    <div class="settings-section" id="section-reviews">
      <div class="settings-section-title">Session Reviews</div>
      <div class="settings-section-sub">Client ratings are private — visible to admins only, not to practitioners.</div>

      <?php if (empty($reviews)): ?>
        <div class="glass-panel" style="padding:24px;color:var(--text-muted);font-size:14px;">
          No reviews yet. Reviews appear here once clients rate their sessions.
        </div>
      <?php else: ?>
        <div class="admin-user-table-wrap glass-panel">
        <table class="admin-user-table" style="width:100%;">
          <thead>
            <tr>
              <th>Client</th>
              <th>Practitioner</th>
              <th>Session Date</th>
              <th style="text-align:center;">Rating</th>
              <th>Comment</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reviews as $rev):
              $stars    = str_repeat('★', (int)$rev['rating']) . str_repeat('☆', 5 - (int)$rev['rating']);
              $sessDate = (new DateTime($rev['started_at']))->format('M j, Y');
            ?>
            <tr>
              <td>
                <div style="font-weight:600;"><?= htmlspecialchars($rev['client_name'] ?: '—') ?></div>
                <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($rev['client_email'] ?: '') ?></div>
              </td>
              <td><?= htmlspecialchars($rev['practitioner_name'] ?: '—') ?></td>
              <td><?= htmlspecialchars($sessDate) ?></td>
              <td style="text-align:center;color:var(--warning);font-size:16px;"><?= htmlspecialchars($stars) ?></td>
              <td style="font-size:13px;color:var(--text-muted);max-width:280px;"><?= htmlspecialchars($rev['comment'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- ── License Review ── -->
    <div class="settings-section" id="section-licenses">
      <div class="settings-section-title">License Verification</div>
      <div class="settings-section-sub">Review practitioner credential submissions. Approval unlocks licensed clinical practice type options.</div>

      <?php if (empty($licenseSubmissions)): ?>
        <div class="glass-panel" style="padding:24px;color:var(--text-muted);font-size:14px;">
          No submissions yet. Practitioners can request licensed status from their Profile page.
        </div>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px;">
          <?php foreach ($licenseSubmissions as $sub): ?>
            <?php
              $statusColor = match($sub['status']) {
                  'approved' => 'rgba(80,200,120,0.18)',
                  'rejected' => 'rgba(239,83,80,0.12)',
                  default    => 'rgba(245,200,66,0.12)',
              };
              $statusText  = match($sub['status']) {
                  'approved' => '✓ Approved',
                  'rejected' => '✕ Rejected',
                  default    => '⏳ Pending',
              };
            ?>
            <div class="glass-panel" style="padding:16px 18px;background:<?= $statusColor ?>">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
                <div>
                  <div style="font-weight:700;font-size:14px;"><?= htmlspecialchars($sub['practitioner_name']) ?></div>
                  <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($sub['practitioner_email']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted);margin-top:3px;">Submitted: <?= (new DateTime($sub['submitted_at']))->format('M j, Y g:i A') ?></div>
                </div>
                <span style="font-size:12px;font-weight:600;"><?= $statusText ?></span>
              </div>

              <?php if ($sub['notes']): ?>
                <div style="font-size:13px;color:var(--text-muted);margin-bottom:10px;line-height:1.5;">
                  <strong style="color:var(--text);">Applicant note:</strong> <?= htmlspecialchars($sub['notes']) ?>
                </div>
              <?php endif; ?>

              <div style="margin-bottom:10px;">
                <a href="<?= htmlspecialchars($sub['document_path']) ?>" target="_blank" rel="noopener"
                   style="display:inline-flex;align-items:center;gap:6px;background:rgba(124,106,247,0.12);border:1px solid rgba(124,106,247,0.25);border-radius:6px;padding:6px 12px;color:#a99cff;font-size:12px;text-decoration:none;">
                  📄 <?= htmlspecialchars($sub['document_name']) ?>
                </a>
              </div>

              <?php if ($sub['status'] === 'pending'): ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                  <form method="post" style="display:contents;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="license_action" value="approve">
                    <input type="hidden" name="license_id" value="<?= (int)$sub['id'] ?>">
                    <input type="hidden" name="practitioner_id" value="<?= (int)$sub['practitioner_id'] ?>">
                    <input type="text" name="reviewer_notes" placeholder="Optional reviewer notes…"
                           style="flex:1;min-width:160px;width:auto;background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:6px 10px;color:var(--text);font-size:12px;font-family:inherit;outline:none;">
                    <button type="submit" style="background:rgba(80,200,120,0.18);border:1px solid rgba(80,200,120,0.3);color:#6fd98c;padding:6px 14px;border-radius:6px;font-size:12px;cursor:pointer;font-family:inherit;">✓ Approve</button>
                  </form>
                  <form method="post" style="display:contents;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="license_action" value="reject">
                    <input type="hidden" name="license_id" value="<?= (int)$sub['id'] ?>">
                    <input type="hidden" name="practitioner_id" value="<?= (int)$sub['practitioner_id'] ?>">
                    <button type="submit" style="background:rgba(239,83,80,0.12);border:1px solid rgba(239,83,80,0.25);color:#ef9a9a;padding:6px 14px;border-radius:6px;font-size:12px;cursor:pointer;font-family:inherit;">✕ Reject</button>
                  </form>
                </div>
              <?php elseif ($sub['reviewer_notes']): ?>
                <div style="font-size:12px;color:var(--text-muted);">
                  <strong>Reviewer note:</strong> <?= htmlspecialchars($sub['reviewer_notes']) ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- ── Security / Blocked IPs ── -->
    <div class="settings-section" id="section-security">
      <div class="settings-section-title">Security — Firewall</div>
      <div class="settings-section-sub">Manage IP addresses blocked by the login brute-force firewall.</div>

      <!-- MFA Enforcement -->
      <form method="post" style="margin-bottom:28px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="action" value="save_mfa_enforcement">
        <div class="glass-panel" style="padding:22px 24px 18px;">
          <div style="font-size:15px;font-weight:600;color:var(--text);margin-bottom:4px;">Practitioner MFA Enforcement</div>
          <div style="font-size:13px;color:var(--text-muted);margin-bottom:16px;line-height:1.6;">
            Practitioners have access to session transcripts, clinical notes, and special category booking data.
            When enforcement is enabled, practitioners without MFA configured will be redirected to the MFA setup page after a configurable grace period.
            An email reminder is sent when the grace period is active.
          </div>
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
              <input type="checkbox" name="enforce_practitioner_mfa" value="1" <?= $enforcePractMfa ? 'checked' : '' ?>
                     style="accent-color:var(--accent);width:16px;height:16px;">
              <span>Require MFA for all practitioner accounts</span>
            </label>
          </div>
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
            <label style="font-size:13px;color:var(--text-muted);">Grace period (days):</label>
            <input type="number" name="mfa_grace_days" value="<?= (int)$mfaGraceDays ?>" min="1" max="30"
                   style="width:80px;padding:5px 10px;background:var(--surface2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:13px;">
            <span style="font-size:12px;color:var(--text-muted);">Practitioners have this many days to set up MFA before being required to do so on login.</span>
          </div>
          <?php if ($enforcePractMfa && $mfaEnforceEnabledAt): ?>
          <div style="font-size:12px;color:var(--accent);margin-bottom:12px;">
            Enforcement enabled <?= htmlspecialchars($mfaEnforceEnabledAt) ?> · Grace period expires
            <?= (new DateTime($mfaEnforceEnabledAt))->modify("+{$mfaGraceDays} days")->format('F j, Y') ?>.
          </div>
          <?php endif; ?>
          <?php
          // Count practitioners without MFA
          $noMfaCount = (int)$pdo->query("SELECT COUNT(*) FROM practitioners WHERE (mfa_secret IS NULL OR mfa_secret='') AND account_status='active'")->fetchColumn();
          if ($noMfaCount > 0):
          ?>
          <div style="font-size:12px;color:var(--warning,#f5c842);margin-bottom:12px;">
            <?= $noMfaCount ?> active practitioner<?= $noMfaCount !== 1 ? 's' : '' ?> do not currently have MFA configured.
          </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-sm">Save MFA Settings</button>
        </div>
      </form>

      <?php if (empty($blockedIps)): ?>
        <div class="glass-panel" style="padding:24px;color:var(--text-muted);font-size:14px;">
          No blocked IPs. The firewall will block IPs automatically on repeated failed logins.
        </div>
      <?php else: ?>
        <div class="admin-user-table-wrap glass-panel">
        <table class="admin-user-table" style="width:100%;">
          <thead>
            <tr>
              <th>IP Address</th>
              <th>Type</th>
              <th>Expires</th>
              <th>Reason</th>
              <th>Blocked At</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($blockedIps as $blk):
              $expiryStr = $blk['expires_at']
                ? (new DateTime($blk['expires_at']))->format('M j, Y g:i A') . ' UTC'
                : 'Never';
            ?>
            <tr>
              <td style="font-family:monospace;font-size:13px;"><?= htmlspecialchars($blk['ip_address']) ?></td>
              <td>
                <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;
                  background:<?= $blk['block_type'] === 'perm' ? 'rgba(239,83,80,0.12)' : 'rgba(245,200,66,0.12)' ?>;
                  color:<?= $blk['block_type'] === 'perm' ? '#ef9a9a' : '#f5c842' ?>;">
                  <?= $blk['block_type'] === 'perm' ? 'PERMANENT' : 'TEMPORARY' ?>
                </span>
              </td>
              <td style="font-size:13px;"><?= htmlspecialchars($expiryStr) ?></td>
              <td style="font-size:13px;color:var(--text-muted);"><?= htmlspecialchars($blk['reason'] ?: '—') ?></td>
              <td style="font-size:13px;color:var(--text-muted);"><?= (new DateTime($blk['created_at']))->format('M j, Y') ?></td>
              <td>
                <form method="post" style="margin:0;" data-confirm="Unblock <?= htmlspecialchars($blk['ip_address'], ENT_QUOTES) ?>?">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="unblock_ip">
                  <input type="hidden" name="ip_address" value="<?= htmlspecialchars($blk['ip_address']) ?>">
                  <button type="submit" class="btn btn-sm btn-ghost">Unblock</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- ── GDPR Data Deletion Requests ── -->
    <div class="settings-section" id="section-gdpr">
      <div class="settings-section-title">GDPR — Data Deletion Requests</div>
      <div class="settings-section-sub">
        Manage client requests to have their personal data erased under GDPR Article 17.
        Requests must be processed within <strong>30 days</strong> of submission (Article 12(3)).
      </div>

      <?php
      $pendingReqs   = array_filter($gdprRequests, fn($r) => $r['status'] === 'pending');
      $completedReqs = array_filter($gdprRequests, fn($r) => $r['status'] !== 'pending');
      ?>

      <?php if (empty($gdprRequests)): ?>
        <div class="glass-panel" style="padding:24px;color:var(--text-muted);font-size:14px;">
          No data deletion requests have been submitted.
        </div>
      <?php else: ?>

        <?php if (!empty($pendingReqs)): ?>
          <div style="font-size:12px;font-weight:700;letter-spacing:.08em;color:var(--text-muted);text-transform:uppercase;margin-bottom:10px;">
            Pending — Action Required
          </div>
          <div class="admin-user-table-wrap glass-panel" style="margin-bottom:28px;">
          <table class="admin-user-table" style="width:100%;">
            <thead>
              <tr>
                <th>#</th>
                <th>User</th>
                <th>Email</th>
                <th>Submitted</th>
                <th>Deadline</th>
                <th>Days Left</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pendingReqs as $req):
                $deadline    = new DateTime($req['deadline_at']);
                $now         = new DateTime();
                $daysLeft    = (int)$now->diff($deadline)->days * ($deadline > $now ? 1 : -1);
                $urgentColor = $daysLeft <= 7 ? '#ef9a9a' : ($daysLeft <= 14 ? '#f5c842' : 'var(--text-muted)');
              ?>
              <tr>
                <td style="font-size:12px;color:var(--text-muted);">#<?= (int)$req['id'] ?></td>
                <td><?= htmlspecialchars($req['user_name'] ?? '[Deleted]') ?></td>
                <td style="font-size:13px;font-family:monospace;"><?= htmlspecialchars($req['email']) ?></td>
                <td style="font-size:13px;color:var(--text-muted);"><?= (new DateTime($req['requested_at']))->format('M j, Y') ?></td>
                <td style="font-size:13px;"><?= $deadline->format('M j, Y') ?></td>
                <td style="font-size:13px;font-weight:600;color:<?= $urgentColor ?>;">
                  <?= $daysLeft > 0 ? $daysLeft . 'd' : 'OVERDUE' ?>
                </td>
                <td>
                  <div style="display:flex;gap:8px;">
                    <button class="btn btn-sm js-gdpr-complete" style="background:rgba(239,83,80,0.14);color:#ef9a9a;border-color:rgba(239,83,80,0.3);"
                      data-id="<?= (int)$req['id'] ?>"
                      data-email="<?= htmlspecialchars($req['email'], ENT_QUOTES) ?>">
                      Complete &amp; Delete
                    </button>
                    <button class="btn btn-sm btn-ghost js-gdpr-cancel"
                      data-id="<?= (int)$req['id'] ?>">
                      Cancel
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>

        <?php if (!empty($completedReqs)): ?>
          <div style="font-size:12px;font-weight:700;letter-spacing:.08em;color:var(--text-muted);text-transform:uppercase;margin-bottom:10px;">
            History
          </div>
          <div class="admin-user-table-wrap glass-panel">
          <table class="admin-user-table" style="width:100%;">
            <thead>
              <tr>
                <th>#</th>
                <th>Email</th>
                <th>Submitted</th>
                <th>Completed</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($completedReqs as $req):
                $statusColor = $req['status'] === 'completed' ? '#81c784' : 'var(--text-muted)';
              ?>
              <tr>
                <td style="font-size:12px;color:var(--text-muted);">#<?= (int)$req['id'] ?></td>
                <td style="font-size:13px;font-family:monospace;"><?= htmlspecialchars($req['email']) ?></td>
                <td style="font-size:13px;color:var(--text-muted);"><?= (new DateTime($req['requested_at']))->format('M j, Y') ?></td>
                <td style="font-size:13px;color:var(--text-muted);"><?= $req['completed_at'] ? (new DateTime($req['completed_at']))->format('M j, Y') : '—' ?></td>
                <td>
                  <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;
                    background:<?= $req['status'] === 'completed' ? 'rgba(129,199,132,0.12)' : 'rgba(255,255,255,0.06)' ?>;
                    color:<?= $statusColor ?>;">
                    <?= strtoupper(htmlspecialchars($req['status'])) ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>

      <?php endif; ?>
    </div>

    <!-- ── DPA Documents ── -->
    <div class="settings-section" id="section-dpa">
      <div class="settings-section-title">Data Processing Agreements</div>
      <div class="settings-section-sub">
        Upload and manage DPA documents for platform-level data processors (hosting provider and SMTP service).
        Practitioners manage their own AI vendor and payment processor DPAs in their practitioner panel.
        All documents are stored securely and served only to authenticated admin users.
      </div>

      <div id="dpaAlert" style="margin-bottom:12px;"></div>

      <!-- Upload Form -->
      <div class="glass-panel admin-settings-panel" style="margin-bottom:20px;">
        <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:14px;">Upload Platform DPA</div>
        <form id="dpaUploadForm" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="upload_platform">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div>
              <label style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:5px;">Document Type</label>
              <select name="type" class="admin-input" required>
                <option value="">Select type…</option>
                <option value="hosting">Hosting Provider DPA</option>
                <option value="smtp">SMTP / Email Provider DPA</option>
              </select>
            </div>
            <div>
              <label style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:5px;">Provider Name</label>
              <input type="text" name="vendor_name" class="admin-input" placeholder="e.g. GreenGeeks, Postmark" required>
            </div>
          </div>
          <div style="margin-bottom:12px;">
            <label style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:5px;">DPA Document <span style="color:var(--text-muted)">(PDF, DOC, DOCX — max 20MB)</span></label>
            <input type="file" name="dpa_file" accept=".pdf,.doc,.docx" required style="font-size:13px;color:var(--text);">
          </div>
          <div style="margin-bottom:14px;">
            <label style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:5px;">Notes (optional)</label>
            <input type="text" name="notes" class="admin-input" placeholder="e.g. Signed 3 May 2026, valid until 2027" style="max-width:400px;">
          </div>
          <button type="submit" class="btn btn-primary" id="dpaUploadBtn">Upload DPA</button>
        </form>
      </div>

      <!-- Document List -->
      <div style="font-size:12px;font-weight:700;letter-spacing:.08em;color:var(--text-muted);text-transform:uppercase;margin-bottom:10px;">Uploaded DPAs</div>
      <div id="dpaList"><div style="text-align:center;padding:24px 0;"><div class="spinner"></div></div></div>
    </div>

    <!-- ── Email Templates ── -->
    <div class="settings-section" id="section-email-templates">
      <div class="settings-section-title">Email Templates</div>
      <div class="settings-section-sub">Customise the emails sent to practitioners and clients. Use <code>{{variable}}</code> placeholders shown in each template.</div>

      <div id="etplAlert"></div>

      <div class="etpl-layout" id="etplLayout">
        <div class="etpl-list" id="etplList">
          <div class="empty-c"><div class="spinner"></div></div>
        </div>
        <div class="etpl-editor" id="etplEditor" style="display:none;">
          <div class="etpl-editor-hdr">
            <strong id="etplEditorName"></strong>
            <div style="display:flex;gap:8px;">
              <button class="btn-c ghost sm" id="etpl-reset-btn">Reset to Default</button>
              <button class="btn-c ghost sm" id="etpl-preview-btn">Preview</button>
              <button class="btn-c primary sm" id="etpl-save-btn">Save</button>
            </div>
          </div>
          <input type="hidden" id="etplKey">
          <div class="f-group" style="margin-bottom:12px;">
            <label class="f-label">Subject</label>
            <input type="text" class="f-input" id="etplSubject" placeholder="Email subject…">
          </div>
          <div class="f-group">
            <label class="f-label">Body HTML</label>
            <textarea class="f-input" id="etplBody" style="min-height:320px;font-family:monospace;font-size:12px;line-height:1.6;resize:vertical;" placeholder="HTML body with {{variable}} placeholders…"></textarea>
          </div>
          <div id="etplVars" style="margin-top:12px;font-size:12px;color:var(--text-muted);"></div>
        </div>
      </div>

      <!-- Preview modal -->
      <div class="etpl-preview-modal" id="etplPreviewModal" style="display:none;">
        <div class="etpl-preview-box">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <strong style="font-size:13px;">Preview: <span id="etplPreviewSubj"></span></strong>
            <button class="btn-c ghost sm" id="etpl-close-preview-btn">✕ Close</button>
          </div>
          <iframe id="etplPreviewFrame" style="width:100%;height:500px;border:none;border-radius:8px;background:#fff;"></iframe>
        </div>
      </div>
    </div>

    <!-- ── Crisis Resources ── -->
    <div class="settings-section" id="section-crisis-resources">
      <div class="settings-section-title">Crisis Resources</div>
      <div class="settings-section-sub">Messages practitioners can send instantly during a session. Sorted by sort order (ascending).</div>

      <div id="crisisAlert"></div>

      <div class="glass-panel" style="padding:24px;max-width:660px;margin-bottom:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
          <strong style="font-size:13px;">Active Resources</strong>
          <button class="btn btn-primary btn-sm" id="crisis-add-btn">+ Add Resource</button>
        </div>
        <div id="crisisList"><div class="empty-c"><div class="spinner"></div></div></div>
      </div>

      <div class="glass-panel" id="crisisForm" style="padding:24px;max-width:660px;display:none;">
        <strong id="crisisFormTitle" style="font-size:13px;display:block;margin-bottom:16px;">New Crisis Resource</strong>
        <input type="hidden" id="crisisId" value="0">
        <div class="f-group" style="margin-bottom:12px;">
          <label class="f-label">Label (button text shown to practitioner)</label>
          <input type="text" class="f-input" id="crisisLabel" placeholder="e.g. National Suicide &amp; Crisis Lifeline">
        </div>
        <div class="f-group" style="margin-bottom:12px;">
          <label class="f-label">Message Body (sent as chat message)</label>
          <textarea class="f-input" id="crisisBody" style="min-height:120px;resize:vertical;" placeholder="e.g. If you are in crisis, please call or text 988 (Suicide &amp; Crisis Lifeline) available 24/7."></textarea>
        </div>
        <div class="f-group" style="margin-bottom:16px;">
          <label class="f-label">Sort Order</label>
          <input type="number" class="f-input" id="crisisSortOrder" value="0" style="width:100px;">
        </div>
        <div style="display:flex;gap:8px;">
          <button class="btn btn-primary btn-sm" id="crisis-save-btn">Save</button>
          <button class="btn btn-ghost btn-sm" id="crisis-cancel-btn">Cancel</button>
        </div>
      </div>
    </div>

    <!-- ── Home Page ── -->
    <div class="settings-section" id="section-homepage">
      <div class="settings-section-title">Home Page</div>
      <div class="settings-section-sub">Edit the home page hero text, control directory visibility, and set a site-wide background.</div>

      <!-- Hero text -->
      <div class="glass-panel admin-settings-panel" style="margin-bottom:20px;">
        <div class="admin-setting-label" style="margin-bottom:16px;">Hero Content</div>
        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="save_home_settings">
          <div class="form-group" style="margin-bottom:14px;">
            <label style="font-size:12px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:6px;">Heading</label>
            <input type="text" name="home_hero_heading" value="<?= htmlspecialchars($homeHeroHeading) ?>"
                   style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:14px;" maxlength="100">
          </div>
          <div class="form-group" style="margin-bottom:14px;">
            <label style="font-size:12px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:6px;">Tagline / Subtext</label>
            <textarea name="home_hero_subtext" rows="2" maxlength="300"
                      style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:14px;resize:vertical;"><?= htmlspecialchars($homeHeroSubtext) ?></textarea>
          </div>
          <div class="admin-setting-row" style="margin-bottom:14px;padding:0;border:none;">
            <div>
              <div class="admin-setting-label" style="font-size:13px;">Show Find a Practitioner section on home page</div>
              <div class="text-muted" style="font-size:12px;">When off, the practitioner directory is hidden on the landing page but still accessible via the navigation.</div>
            </div>
            <label class="toggle-switch">
              <input type="checkbox" name="show_find_practitioners" <?= $showFindPract ? 'checked' : '' ?>>
              <span class="toggle-track"><span class="toggle-thumb"></span></span>
            </label>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Save</button>
        </form>
      </div>

      <!-- Background -->
      <div class="glass-panel admin-settings-panel">
        <div class="admin-setting-label" style="margin-bottom:6px;">Site Background</div>
        <div class="text-muted" style="font-size:13px;margin-bottom:16px;">Applies to: home page, Find a Practitioner, About, Privacy, ROPA, and booking pages. Video (MP4/WebM) up to 200 MB, images up to 10 MB.</div>
        <?php if ($siteBgPath): ?>
          <div style="margin-bottom:16px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
            <div style="font-size:13px;color:var(--text-muted);">
              Current: <code style="font-size:12px;"><?= htmlspecialchars(basename($siteBgPath)) ?></code>
              (<?= $siteBgType === 'video' ? 'video' : 'image' ?>)
            </div>
            <form method="post" style="margin:0;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="remove_site_bg">
              <button type="submit" class="btn btn-sm btn-danger">Remove — Use Default</button>
            </form>
          </div>
        <?php else: ?>
          <div style="display:inline-flex;align-items:center;gap:8px;font-size:13px;color:var(--text-muted);background:rgba(124,106,247,0.08);border:1px solid rgba(124,106,247,0.2);border-radius:6px;padding:8px 14px;margin-bottom:16px;">
            ✦ Default (Starry Skies)
          </div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="upload_site_bg">
          <label class="btn btn-ghost btn-sm" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
            <?= $siteBgPath ? 'Replace Background' : 'Upload Custom Background' ?>
            <input type="file" name="site_bg_file" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/ogg,video/quicktime"
                   style="display:none" class="js-auto-submit">
          </label>
        </form>
      </div>
    </div>

    <!-- ── Banners ── -->
    <div class="settings-section" id="section-banners">
      <div class="settings-section-title">Banners</div>
      <div class="settings-section-sub">Create announcement banners shown across the platform. Each banner can be toggled per area independently.</div>

      <div id="bannerAlert"></div>

      <div class="glass-panel" style="padding:24px;max-width:700px;margin-bottom:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
          <strong style="font-size:13px;">Active Banners</strong>
          <button class="btn btn-primary btn-sm" id="banner-add-btn">+ New Banner</button>
        </div>
        <div id="bannerList"><div class="empty-c"><div class="spinner"></div></div></div>
      </div>

      <!-- Banner editor -->
      <div class="glass-panel" id="bannerForm" style="padding:24px;max-width:700px;display:none;">
        <strong id="bannerFormTitle" style="font-size:13px;display:block;margin-bottom:16px;">New Banner</strong>
        <input type="hidden" id="bannerId" value="0">

        <div class="f-group" style="margin-bottom:12px;">
          <label class="f-label">Banner Text</label>
          <textarea class="f-input" id="bannerContent" rows="2" style="resize:vertical;" placeholder="e.g. We will be closed on May 1st for the bank holiday."></textarea>
        </div>

        <div style="display:flex;gap:16px;margin-bottom:12px;flex-wrap:wrap;align-items:flex-end;">
          <div>
            <label class="f-label" style="display:block;margin-bottom:6px;">Background Color</label>
            <input type="color" id="bannerBgColor" value="#2a2060" style="width:60px;height:36px;border:none;border-radius:6px;cursor:pointer;background:none;">
          </div>
          <div>
            <label class="f-label" style="display:block;margin-bottom:6px;">Text Color</label>
            <input type="color" id="bannerTextColor" value="#ffffff" style="width:60px;height:36px;border:none;border-radius:6px;cursor:pointer;background:none;">
          </div>
          <button type="button" class="btn btn-ghost btn-sm" id="banner-preview-btn">Preview</button>
        </div>

        <!-- Preview -->
        <div id="bannerPreviewWrap" style="display:none;margin-bottom:14px;border-radius:6px;overflow:hidden;">
          <div id="bannerPreviewEl" style="padding:10px 20px;font-size:13px;font-weight:500;text-align:center;"></div>
        </div>

        <div style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px;">Show On</div>
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
          <?php
          $bannerContexts = [
            'show_public'    => 'Public Pages',
            'show_dashboard' => 'Practitioner Dashboard',
            'show_profile'   => 'Practitioner Profile',
            'show_client'    => 'Client Portal',
            'show_admin'     => 'Admin Panel',
          ];
          foreach ($bannerContexts as $field => $label): ?>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
            <input type="checkbox" id="banner_<?= $field ?>" name="<?= $field ?>">
            <?= htmlspecialchars($label) ?>
          </label>
          <?php endforeach; ?>
        </div>

        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
            <input type="checkbox" id="bannerIsActive" checked>
            Active (visible)
          </label>
        </div>

        <div style="display:flex;gap:8px;">
          <button class="btn btn-primary btn-sm" id="banner-save-btn">Save Banner</button>
          <button class="btn btn-ghost btn-sm" id="banner-cancel-btn">Cancel</button>
        </div>
      </div>
    </div>

    <!-- ── AI Integration ── -->
    <div class="settings-section" id="section-ai-integration">
      <div class="settings-section-title">AI Integration</div>
      <div class="settings-section-sub">Platform-wide controls for AI-assisted features. Practitioners configure their own keys and models; this panel controls what they are allowed to enable.</div>
      <div class="glass-panel admin-settings-panel">

        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="save_ai_settings">

          <!-- Master enable -->
          <div class="admin-setting-row" style="background:<?= $aiEnabled ? 'rgba(124,106,247,0.06)' : 'rgba(255,255,255,0.02)' ?>;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
            <div>
              <div class="admin-setting-label" style="display:flex;align-items:center;gap:8px;">
                Enable AI Integration
                <?php if ($aiEnabled): ?>
                  <span style="background:rgba(124,106,247,0.18);color:#a89ff7;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;">ENABLED</span>
                <?php else: ?>
                  <span style="background:rgba(255,255,255,0.06);color:rgba(255,255,255,0.4);font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;">DISABLED</span>
                <?php endif; ?>
              </div>
              <div class="text-muted" style="font-size:13px;margin-top:4px;">When disabled, practitioners cannot configure AI and all AI features are hidden platform-wide. Disabled by default — enable only after reviewing vendor DPAs and ensuring appropriate data governance for your jurisdiction.</div>
            </div>
            <label class="toggle-switch" style="flex-shrink:0;">
              <input type="checkbox" name="ai_enabled" value="1" <?= $aiEnabled ? 'checked' : '' ?>>
              <span class="toggle-slider"></span>
            </label>
          </div>

          <div style="border-top:1px solid rgba(255,255,255,0.07);margin:20px 0 20px;"></div>
          <div style="font-size:13px;font-weight:600;color:rgba(255,255,255,0.7);margin-bottom:12px;letter-spacing:0.03em;">Allowed Vendors</div>
          <div class="text-muted" style="font-size:12px;margin-bottom:14px;">Practitioners may only use vendors you enable here. Vendor APIs receive session content — ensure you have reviewed their data processing terms and have appropriate agreements in place before enabling.</div>

          <?php
          $vendorInfo = [
              'openai'    => ['name' => 'OpenAI',          'note' => 'GPT-4o, GPT-4, o-series models'],
              'anthropic' => ['name' => 'Anthropic',       'note' => 'Claude Opus, Sonnet, Haiku'],
              'google'    => ['name' => 'Google Gemini',   'note' => 'Gemini 1.5 Pro / Flash series'],
              'cohere'    => ['name' => 'Cohere',          'note' => 'Command R+ and Command models'],
          ];
          foreach ($vendorInfo as $vKey => $vData): ?>
          <div class="admin-setting-row" style="padding:10px 14px;background:rgba(255,255,255,0.02);border-radius:8px;margin-bottom:6px;">
            <div>
              <div class="admin-setting-label" style="font-size:13px;"><?= $vData['name'] ?></div>
              <div class="text-muted" style="font-size:12px;"><?= $vData['note'] ?></div>
            </div>
            <label class="toggle-switch" style="flex-shrink:0;">
              <input type="checkbox" name="ai_vendor_<?= $vKey ?>" value="1" <?= in_array($vKey, $aiAllowedVendors) ? 'checked' : '' ?>>
              <span class="toggle-slider"></span>
            </label>
          </div>
          <?php endforeach; ?>

          <div style="border-top:1px solid rgba(255,255,255,0.07);margin:20px 0 20px;"></div>
          <div style="font-size:13px;font-weight:600;color:rgba(255,255,255,0.7);margin-bottom:12px;letter-spacing:0.03em;">Allowed Scopes</div>
          <div class="text-muted" style="font-size:12px;margin-bottom:14px;">Each scope sends different data to the AI provider. Practitioners can only enable scopes permitted here, and clients are shown exactly which scopes are active when they book.</div>

          <?php
          $scopeInfo = [
              'summarization' => ['name' => 'Session Summarization', 'note' => 'AI generates a summary of session transcripts after export.'],
              'notes'         => ['name' => 'Notes Assistance',       'note' => 'AI drafts SOAP/DAP/BIRP clinical notes from session content.'],
              'post_session'  => ['name' => 'Post-Session Discussion', 'note' => 'Practitioner can discuss a completed session with the AI in a chat interface.'],
              'in_session'    => ['name' => 'In-Session Assistant',   'note' => 'AI joins as a virtual participant and responds when addressed by name during live sessions.'],
          ];
          foreach ($scopeInfo as $sKey => $sData): ?>
          <div class="admin-setting-row" style="padding:10px 14px;background:rgba(255,255,255,0.02);border-radius:8px;margin-bottom:6px;">
            <div>
              <div class="admin-setting-label" style="font-size:13px;"><?= $sData['name'] ?></div>
              <div class="text-muted" style="font-size:12px;"><?= $sData['note'] ?></div>
            </div>
            <label class="toggle-switch" style="flex-shrink:0;">
              <input type="checkbox" name="ai_scope_<?= $sKey ?>" value="1" <?= in_array($sKey, $aiAllowedScopes) ? 'checked' : '' ?>>
              <span class="toggle-slider"></span>
            </label>
          </div>
          <?php endforeach; ?>

          <div style="margin-top:20px;">
            <button type="submit" class="btn btn-primary btn-sm">Save AI Settings</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Compliance & Jurisdiction ── -->
    <div class="settings-section" id="section-compliance">
      <div class="settings-section-title">Compliance &amp; Jurisdiction</div>
      <div class="settings-section-sub">Jurisdictions are detected automatically from your install country and linked DC locations. Use this panel to verify the detected values are correct, re-sync if you've added new locations, and configure controller details for your Privacy Notice and ROPA.</div>

      <?php
      // Build a source map: jurisdiction code → list of sources (install / DC label)
      $jurSources = [];
      if ($installCountry !== '') {
          $primaryFromInstall = jur_country_to_code($installCountry);
          if ($primaryFromInstall !== '') {
              $jurSources[$primaryFromInstall][] = 'Install (' . strtoupper($installCountry) . ')';
          }
      } elseif (defined('DC_COUNTRY') && DC_COUNTRY !== '') {
          $primaryFromDC = jur_country_to_code(DC_COUNTRY);
          if ($primaryFromDC !== '') {
              $jurSources[$primaryFromDC][] = 'Install config (DC_COUNTRY)';
          }
      }
      foreach ($locations as $loc) {
          if (empty($loc['country']) || !$loc['is_active']) continue;
          $lc = jur_country_to_code($loc['country']);
          if ($lc !== '') {
              $jurSources[$lc][] = htmlspecialchars($loc['label'] ?: $loc['country']) . ' (DC)';
          }
      }
      $jurisLabels = [
          'eu'        => ['label'=>'EU / EEA',      'law'=>'GDPR'],
          'uk'        => ['label'=>'United Kingdom', 'law'=>'UK GDPR + DPA 2018'],
          'us'        => ['label'=>'United States',  'law'=>'HIPAA + CCPA/CPRA'],
          'canada'    => ['label'=>'Canada',         'law'=>'PIPEDA + Québec Law 25'],
          'australia' => ['label'=>'Australia',      'law'=>'Privacy Act 1988'],
          'custom'    => ['label'=>'Custom',         'law'=>'Custom HTML blocks'],
      ];
      ?>

      <!-- Detected jurisdiction status -->
      <div class="glass-panel admin-settings-panel" style="margin-bottom:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
          <div class="settings-section-sub" style="font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);font-weight:600;margin:0;">Detected Jurisdictions</div>
          <form method="post" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="sync_compliance">
            <button type="submit" class="btn btn-ghost btn-sm" style="font-size:12px;">↻ Re-sync from Infrastructure</button>
          </form>
        </div>

        <!-- Primary -->
        <div style="margin-bottom:14px;">
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.07em;color:rgba(200,210,255,0.4);margin-bottom:8px;">Primary</div>
          <?php
          $primInfo = $jurisLabels[$jurisPrimary] ?? ['label'=>ucfirst($jurisPrimary),'law'=>''];
          $primSources = $jurSources[$jurisPrimary] ?? [];
          ?>
          <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:10px;border:1px solid rgba(124,106,247,0.4);background:rgba(124,106,247,0.08);">
            <div style="flex:1;">
              <span style="font-size:14px;font-weight:600;color:#e8eeff;"><?= htmlspecialchars($primInfo['label']) ?></span>
              <span style="font-size:12px;color:rgba(200,210,255,0.5);margin-left:8px;"><?= htmlspecialchars($primInfo['law']) ?></span>
            </div>
            <?php if ($primSources): ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
              <?php foreach ($primSources as $src): ?>
              <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(124,106,247,0.15);color:rgba(200,210,255,0.6);border:1px solid rgba(124,106,247,0.2);"><?= $src ?></span>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <span style="font-size:11px;color:rgba(200,210,255,0.35);font-style:italic;">Source: manually retained</span>
            <?php endif; ?>
            <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(60,210,120,0.12);color:#5de0a0;border:1px solid rgba(60,210,120,0.2);">Active</span>
          </div>
        </div>

        <!-- Additional -->
        <div>
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.07em;color:rgba(200,210,255,0.4);margin-bottom:8px;">Additional</div>
          <?php if (empty($jurisAdditional)): ?>
          <div style="font-size:13px;color:rgba(200,210,255,0.35);padding:10px 0;font-style:italic;">None detected — add linked DC locations to include additional jurisdictions automatically.</div>
          <?php else: ?>
          <div style="display:flex;flex-direction:column;gap:8px;">
            <?php foreach ($jurisAdditional as $addCode):
              $addInfo = $jurisLabels[$addCode] ?? ['label'=>ucfirst($addCode),'law'=>''];
              $addSources = $jurSources[$addCode] ?? [];
            ?>
            <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:8px;border:1px solid rgba(255,255,255,0.08);background:rgba(255,255,255,0.025);">
              <div style="flex:1;">
                <span style="font-size:13px;font-weight:600;color:#d0d8f0;"><?= htmlspecialchars($addInfo['label']) ?></span>
                <span style="font-size:11px;color:rgba(200,210,255,0.4);margin-left:8px;"><?= htmlspecialchars($addInfo['law']) ?></span>
              </div>
              <?php if ($addSources): ?>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <?php foreach ($addSources as $src): ?>
                <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(255,255,255,0.05);color:rgba(200,210,255,0.5);border:1px solid rgba(255,255,255,0.1);"><?= $src ?></span>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
              <span style="font-size:11px;padding:2px 8px;border-radius:20px;background:rgba(60,210,120,0.10);color:#5de0a0;border:1px solid rgba(60,210,120,0.18);">Active</span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($jurisPrimary === 'custom' || in_array('custom', $jurisAdditional)): ?>
        <div style="margin-top:12px;padding:10px 12px;border-radius:8px;background:rgba(245,200,66,0.07);border:1px solid rgba(245,200,66,0.2);font-size:12px;color:rgba(245,200,66,0.7);">
          Custom jurisdiction active — enter your custom HTML blocks in the section below.
        </div>
        <?php endif; ?>
      </div>

      <!-- Controller details form -->
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="save_compliance_settings">

        <!-- Controller details -->
        <div class="glass-panel admin-settings-panel" style="margin-bottom:16px;">
          <div class="settings-section-sub" style="font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:16px;font-weight:600;">Data Controller Details</div>
          <div style="font-size:13px;color:rgba(200,210,255,0.55);margin-bottom:18px;">
            Used in the Privacy Notice and ROPA. The controller is the person or organisation legally responsible for this platform's data processing. For a sole-practitioner deployment, this is typically your name and business address.
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
            <div>
              <label class="form-label" style="font-size:12px;margin-bottom:6px;display:block;">Controller / Operator Name</label>
              <input type="text" name="controller_name" class="form-input"
                value="<?= htmlspecialchars($controllerName) ?>"
                placeholder="e.g. Jane Smith Therapy Ltd"
                style="width:100%;">
            </div>
            <div>
              <label class="form-label" style="font-size:12px;margin-bottom:6px;display:block;">Contact Email</label>
              <input type="email" name="controller_email" class="form-input"
                value="<?= htmlspecialchars($controllerEmail) ?>"
                placeholder="privacy@yourdomain.com"
                style="width:100%;">
            </div>
          </div>
          <div style="margin-bottom:14px;">
            <label class="form-label" style="font-size:12px;margin-bottom:6px;display:block;">Business / Registered Address</label>
            <input type="text" name="controller_address" class="form-input"
              value="<?= htmlspecialchars($controllerAddr) ?>"
              placeholder="123 Example Street, City, Postcode, Country"
              style="width:100%;">
          </div>
        </div>

        <!-- DPO details -->
        <div class="glass-panel admin-settings-panel" style="margin-bottom:16px;">
          <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:6px;">
            <div class="settings-section-sub" style="font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);font-weight:600;">Data Protection Officer</div>
            <span style="font-size:11px;color:rgba(200,210,255,0.35);">Required under GDPR / UK GDPR for certain processing activities. Leave blank if not applicable.</span>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px;">
            <div>
              <label class="form-label" style="font-size:12px;margin-bottom:6px;display:block;">DPO Name</label>
              <input type="text" name="dpo_name" class="form-input"
                value="<?= htmlspecialchars($dpoName) ?>"
                placeholder="Data Protection Officer name"
                style="width:100%;">
            </div>
            <div>
              <label class="form-label" style="font-size:12px;margin-bottom:6px;display:block;">DPO Email</label>
              <input type="email" name="dpo_email" class="form-input"
                value="<?= htmlspecialchars($dpoEmail) ?>"
                placeholder="dpo@yourdomain.com"
                style="width:100%;">
            </div>
          </div>
        </div>

        <!-- Custom HTML blocks — only shown when 'custom' is active -->
        <?php if ($jurisPrimary === 'custom' || in_array('custom', $jurisAdditional)): ?>
        <div class="glass-panel admin-settings-panel" style="margin-bottom:16px;">
          <div class="settings-section-sub" style="font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:12px;font-weight:600;">Custom Jurisdiction Blocks</div>
          <div style="font-size:13px;color:rgba(200,210,255,0.55);margin-bottom:16px;">
            HTML appended to your Privacy Notice and ROPA pages. Use this for jurisdictions not covered above (Singapore PDPA, Japan APPI, etc.). Raw HTML — admin input only.
          </div>
          <div style="margin-bottom:14px;">
            <label class="form-label" style="font-size:12px;margin-bottom:6px;display:block;">Custom Privacy Notice Block</label>
            <textarea name="jurisdiction_custom_privacy_html" class="form-input"
              style="width:100%;font-family:monospace;font-size:12px;min-height:140px;resize:vertical;"
              placeholder="<section>...</section>"><?= htmlspecialchars($customPrivHtml) ?></textarea>
          </div>
          <div>
            <label class="form-label" style="font-size:12px;margin-bottom:6px;display:block;">Custom ROPA Block</label>
            <textarea name="jurisdiction_custom_ropa_html" class="form-input"
              style="width:100%;font-family:monospace;font-size:12px;min-height:140px;resize:vertical;"
              placeholder="<section>...</section>"><?= htmlspecialchars($customRopaHtml) ?></textarea>
          </div>
        </div>
        <?php endif; ?>

        <!-- Preview links -->
        <div class="glass-panel admin-settings-panel" style="margin-bottom:20px;padding:14px 16px;">
          <div style="font-size:13px;color:rgba(200,210,255,0.55);margin-bottom:10px;">Review the generated legal pages to verify they render correctly for your jurisdiction.</div>
          <div style="display:flex;gap:10px;">
            <a href="/privacy.php" target="_blank" class="btn btn-ghost btn-sm">Preview Privacy Notice →</a>
            <a href="/ropa.php" target="_blank" class="btn btn-ghost btn-sm">Preview ROPA →</a>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Save Controller Details</button>
      </form>
    </div>

    <!-- ── Cron Manager ── -->
    <div class="settings-section" id="section-cron-manager">
      <div class="settings-section-title">Cron Manager</div>
      <div class="settings-section-sub">Scheduled background jobs that keep the platform running. Choose between system cron (standard) or web-triggered cron (WordPress-style, for shared hosts without cPanel cron access).</div>

      <?php
        $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $hostDomain = $_SERVER['HTTP_HOST'] ?? '';
        $webTriggerUrl = $cronKey ? $scheme . '://' . $hostDomain . '/cron/trigger.php?key=' . urlencode($cronKey) : '';
      ?>

      <!-- Mode toggle card -->
      <div class="glass-panel" style="padding:20px 24px;margin-bottom:20px;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
          <div>
            <div style="font-weight:600;font-size:14px;margin-bottom:4px;">
              Cron Mode:
              <?php if ($cronMode === 'web'): ?>
                <span style="color:#7c6af7;margin-left:4px;">&#9679; Web Trigger</span>
              <?php else: ?>
                <span style="color:#4ade80;margin-left:4px;">&#9679; System Cron</span>
              <?php endif; ?>
            </div>
            <div style="font-size:12px;color:var(--text-muted);">
              <?php if ($cronMode === 'system'): ?>
                Crons run via cPanel/server scheduled tasks. Use the commands below to configure them.
              <?php else: ?>
                Crons are triggered automatically on web requests (no cPanel access required).
              <?php endif; ?>
            </div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <form method="post" style="margin:0;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="set_cron_mode">
              <input type="hidden" name="cron_mode" value="<?= $cronMode === 'system' ? 'web' : 'system' ?>">
              <button type="submit" class="btn btn-sm btn-ghost">
                Switch to <?= $cronMode === 'system' ? 'Web Trigger' : 'System Cron' ?>
              </button>
            </form>
          </div>
        </div>

        <?php if ($cronMode === 'web'): ?>
          <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:16px;">
            <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em;">Web Trigger URL</div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
              <code style="font-size:12px;background:var(--input-bg);padding:6px 10px;border-radius:4px;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;border:1px solid var(--border);"><?= htmlspecialchars($webTriggerUrl) ?></code>
              <button class="btn btn-sm btn-ghost" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($webTriggerUrl, ENT_QUOTES) ?>').then(()=>this.textContent='Copied!').catch(()=>{});return false;">Copy</button>
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:6px;">This URL is called non-blocking on ~20% of page requests. Keep it private — it authenticates with the key embedded in the URL.</div>
            <form method="post" style="margin-top:10px;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="regenerate_cron_key">
              <button type="submit" class="btn btn-sm btn-ghost" data-confirm="Regenerate the trigger key? The current URL will stop working.">Regenerate Key</button>
            </form>
          </div>
        <?php else: ?>
          <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:16px;">
            <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em;">cPanel Setup Commands</div>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">Add these in cPanel → Cron Jobs. Replace <code>&lt;username&gt;</code> with your cPanel username.</div>
            <?php foreach ($cronDefs as $cronName => $def): ?>
              <div style="margin-bottom:10px;">
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;"><?= htmlspecialchars($def['label']) ?> &mdash; <?= htmlspecialchars($def['schedule']) ?></div>
                <code style="font-size:11px;background:var(--input-bg);padding:5px 10px;border-radius:4px;display:block;border:1px solid var(--border);white-space:nowrap;overflow-x:auto;">/usr/bin/php /home/&lt;username&gt;/public_html/<?= htmlspecialchars($def['cmd']) ?> &gt;&gt; /home/&lt;username&gt;/logs/<?= htmlspecialchars($cronName) ?>.log 2&gt;&amp;1</code>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Cron status table -->
      <?php if (empty($cronStatus)): ?>
        <div class="glass-panel" style="padding:20px 24px;color:var(--text-muted);font-size:13px;">
          No run history yet. The <code>cron_log</code> table may not exist — run the schema to create it, or crons haven't run yet.
        </div>
      <?php else: ?>
      <div class="admin-user-table-wrap glass-panel">
        <table class="admin-user-table" style="width:100%;">
          <thead>
            <tr>
              <th>Job</th>
              <th>Schedule</th>
              <th>Last Run</th>
              <th>Status</th>
              <th>Duration</th>
              <th>Rows</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cronDefs as $cronName => $def):
              $last     = $cronStatus[$cronName] ?? null;
              $isOverdue = $last && $last['status'] === 'success'
                ? (time() - strtotime($last['started_at'])) > $def['interval'] * 2
                : false;
            ?>
            <tr>
              <td style="font-weight:600;font-size:13px;"><?= htmlspecialchars($def['label']) ?></td>
              <td style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($def['schedule']) ?></td>
              <td style="font-size:12px;">
                <?php if ($last): ?>
                  <span title="<?= htmlspecialchars($last['started_at']) ?>"><?= (new DateTime($last['started_at']))->format('M j, g:i A') ?></span>
                  <?php if ($isOverdue): ?>
                    <span style="margin-left:4px;font-size:10px;color:#f5c842;">(overdue)</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="color:var(--text-muted);">Never</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!$last): ?>
                  <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:rgba(255,255,255,0.06);color:var(--text-muted);">PENDING</span>
                <?php elseif ($last['status'] === 'success'): ?>
                  <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:rgba(74,222,128,0.12);color:#4ade80;">SUCCESS</span>
                <?php elseif ($last['status'] === 'running'): ?>
                  <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:rgba(99,179,237,0.12);color:#63b3ed;">RUNNING</span>
                <?php else: ?>
                  <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:rgba(239,83,80,0.12);color:#ef9a9a;">ERROR</span>
                <?php endif; ?>
              </td>
              <td style="font-size:12px;color:var(--text-muted);">
                <?= $last && $last['duration_ms'] !== null ? number_format((int)$last['duration_ms']) . ' ms' : '—' ?>
              </td>
              <td style="font-size:12px;color:var(--text-muted);">
                <?= $last && $last['rows_affected'] !== null ? number_format((int)$last['rows_affected']) : '—' ?>
              </td>
              <td>
                <?php if ($last && $last['output']): ?>
                  <button class="btn btn-sm btn-ghost" onclick="cronShowOutput(<?= $last['id'] ?>,<?= htmlspecialchars(json_encode($def['label'])) ?>,<?= htmlspecialchars(json_encode($last['output'])) ?>)">View Output</button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

    </div>

  </div><!-- /.settings-main -->
</div><!-- /.settings-layout -->

<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
let crisisItems = [];
let crisisLoaded = false;

function showSection(id) {
    document.querySelectorAll('.settings-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.settings-nav-item').forEach(b => b.classList.remove('active'));
    const sec = document.getElementById('section-' + id);
    if (sec) sec.classList.add('active');
    const btn = document.querySelector(`.settings-nav-item[data-section="${id}"]`);
    if (btn) btn.classList.add('active');
    history.replaceState(null, '', '#' + id);
    // Always close the email template preview modal when navigating sections
    const etplModal = document.getElementById('etplPreviewModal');
    if (etplModal) etplModal.style.display = 'none';
    if (id === 'email-templates') etplLoad();
    if (id === 'crisis-resources') crisisLoad();
    if (id === 'banners') bannerLoad();
}
// Cron output viewer modal
(function() {
    const overlay = document.createElement('div');
    overlay.id = 'cronOutputModal';
    overlay.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;overflow:auto;padding:40px 20px;';
    overlay.innerHTML = `
      <div style="background:#0d1433;border:1px solid #1e2a54;border-radius:8px;max-width:700px;margin:0 auto;padding:0;overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #1e2a54;">
          <div id="cronOutputTitle" style="font-weight:600;font-size:14px;"></div>
          <button onclick="document.getElementById('cronOutputModal').style.display='none'" style="background:none;border:none;color:#888;font-size:18px;cursor:pointer;line-height:1;">&#x2715;</button>
        </div>
        <pre id="cronOutputBody" style="margin:0;padding:16px 20px;font-family:monospace;font-size:12px;white-space:pre-wrap;word-break:break-all;color:#c8d4f0;max-height:60vh;overflow-y:auto;line-height:1.6;"></pre>
      </div>`;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.style.display='none'; });
})();

function cronShowOutput(id, label, output) {
    document.getElementById('cronOutputTitle').textContent = label + ' — Last Run Output';
    document.getElementById('cronOutputBody').textContent  = output || '(no output recorded)';
    document.getElementById('cronOutputModal').style.display = 'block';
}

// File inputs that should auto-submit their parent form on selection
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('js-auto-submit')) {
        e.target.closest('form').submit();
    }
});

// Copy location secret to clipboard
document.getElementById('copy-loc-secret-btn')?.addEventListener('click', function() {
    navigator.clipboard.writeText(document.getElementById('loc-secret-flash').textContent);
});

// Forms with data-confirm require user confirmation before submitting
document.addEventListener('submit', function(e) {
    const msg = e.target.dataset.confirm;
    if (msg && !confirm(msg)) e.preventDefault();
});

// DPA list — delegated delete on dynamically-rendered rows
document.getElementById('dpaList')?.addEventListener('click', function(e) {
    const delBtn = e.target.closest('[data-dpa-delete]');
    if (delBtn) deleteAdminDpa(delBtn.dataset.dpaDelete);
});

// Banners — static buttons
document.getElementById('banner-add-btn')?.addEventListener('click', bannerShowForm);
document.getElementById('banner-preview-btn')?.addEventListener('click', bannerPreview);
document.getElementById('banner-save-btn')?.addEventListener('click', bannerSave);
document.getElementById('banner-cancel-btn')?.addEventListener('click', bannerHideForm);

// Banners — delegated edit/delete on dynamically-rendered list
document.getElementById('bannerList')?.addEventListener('click', function(e) {
    const editBtn = e.target.closest('[data-banner-edit]');
    if (editBtn) { bannerEdit(editBtn.dataset.bannerEdit); return; }
    const delBtn = e.target.closest('[data-banner-delete]');
    if (delBtn) { bannerDelete(delBtn.dataset.bannerDelete); }
});

// Crisis resources — static buttons
document.getElementById('crisis-add-btn')?.addEventListener('click', crisisShowForm);
document.getElementById('crisis-save-btn')?.addEventListener('click', crisisSave);
document.getElementById('crisis-cancel-btn')?.addEventListener('click', crisisHideForm);

// Crisis resources — delegated edit/delete on dynamically-rendered list
document.getElementById('crisisList')?.addEventListener('click', function(e) {
    const editBtn = e.target.closest('[data-crisis-edit]');
    if (editBtn) { crisisEdit(editBtn.dataset.crisisEdit); return; }
    const delBtn = e.target.closest('[data-crisis-delete]');
    if (delBtn) { crisisDelete(delBtn.dataset.crisisDelete, delBtn.dataset.crisisLabel); }
});

// Email template editor buttons
document.getElementById('etpl-reset-btn')?.addEventListener('click', etplReset);
document.getElementById('etpl-preview-btn')?.addEventListener('click', etplPreview);
document.getElementById('etpl-save-btn')?.addEventListener('click', etplSave);
document.getElementById('etpl-close-preview-btn')?.addEventListener('click', function() {
    document.getElementById('etplPreviewModal').style.display = 'none';
});

// Email template list rows — delegated, rows are dynamically rendered by etplLoad()
document.getElementById('etplList')?.addEventListener('click', function(e) {
    const row = e.target.closest('[data-etpl-key]');
    if (row) etplOpen(row.dataset.etplKey);
});

// DPA upload form
document.getElementById('dpaUploadForm')?.addEventListener('submit', function(e) {
    uploadAdminDpa(e);
});

// GDPR complete/cancel — delegated from .js-gdpr-complete and .js-gdpr-cancel
document.addEventListener('click', function(e) {
    const complete = e.target.closest('.js-gdpr-complete');
    if (complete) { gdprComplete(complete.dataset.id, complete.dataset.email); return; }
    const cancel = e.target.closest('.js-gdpr-cancel');
    if (cancel) { gdprCancel(cancel.dataset.id); }
});

// Reject practitioner — delegated from .js-reject-btn buttons (data-uid, data-name)
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.js-reject-btn');
    if (btn) showRejectForm(btn.dataset.uid, btn.dataset.name);
});

// Cancel reject form
document.getElementById('reject-cancel-btn')?.addEventListener('click', function() {
    document.getElementById('reject-form-wrap').style.display = 'none';
});

// Delegated nav — replaces inline onclick handlers on .settings-nav-item buttons only
document.addEventListener('click', function(e) {
    const el = e.target.closest('.settings-nav-item[data-section]');
    if (!el) return;
    const sec = el.dataset.section;
    if (!sec) return;
    showSection(sec);
    if (sec === 'dpa') loadAdminDpas();
});

// Init from URL hash or default
<?php
$initSection = $editUser ? 'users' : 'general';
if (!empty($_POST['license_action'])) $initSection = 'licenses';
elseif (!empty($_POST['action'])) {
    $aMap = [
        'toggle_production_mode'=>'general','toggle_registrations'=>'general','toggle_theme'=>'general','upload_logo'=>'general','remove_logo'=>'general',
        'toggle_require_approval'=>'general',
        'save_feature_settings'=>'features','save_smtp'=>'email','test_smtp'=>'email','save_ai_settings'=>'ai-integration',
        'add_user'=>'add-user','edit_user'=>'users','delete_user'=>'users','unblock_ip'=>'security',
        'approve_practitioner'=>'users','reject_practitioner'=>'users',
        'save_home_settings'=>'homepage','upload_site_bg'=>'homepage','remove_site_bg'=>'homepage',
        'save_compliance_settings'=>'compliance','sync_compliance'=>'compliance',
        'toggle_dpia_signoff'=>'launch',
        'set_cron_mode'=>'cron-manager','regenerate_cron_key'=>'cron-manager',
    ];
    if (isset($aMap[$_POST['action']])) $initSection = $aMap[$_POST['action']];
}
?>
const initId = (location.hash.slice(1)) || <?= json_encode($initSection) ?>;
showSection(initId);
// Auto-activate the section that contains a success/error alert
<?php if ($success || $error): ?>
// Highlight the section where the form action was posted
const actionSectionMap = {
    toggle_production_mode: 'general', toggle_registrations: 'general', toggle_theme: 'general', upload_logo: 'general', remove_logo: 'general',
    toggle_require_approval: 'general',
    save_feature_settings: 'features',
    save_smtp: 'email', test_smtp: 'email', save_ai_settings: 'ai-integration',
    add_user: 'add-user', edit_user: 'users', delete_user: 'users',
    approve_practitioner: 'users', reject_practitioner: 'users',
    unblock_ip: 'security',
    license_action: 'licenses',
    save_home_settings: 'homepage', upload_site_bg: 'homepage', remove_site_bg: 'homepage',
    save_compliance_settings: 'compliance', sync_compliance: 'compliance',
    toggle_dpia_signoff: 'launch',
    set_cron_mode: 'cron-manager', regenerate_cron_key: 'cron-manager',
};
<?php endif; ?>

function showRejectForm(uid, name) {
    document.getElementById('reject-user-id').value = uid;
    document.getElementById('reject-user-name').textContent = name;
    document.getElementById('reject-form-wrap').style.display = '';
    document.getElementById('reject-form-wrap').scrollIntoView({behavior:'smooth',block:'nearest'});
}

async function gdprComplete(requestId, email) {
    if (!confirm('This will permanently delete all personal data for ' + email + ' and cannot be undone.\n\nProceed with deletion?')) return;
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Deleting…';
    try {
        const res = await fetch('/api/gdpr.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'complete', request_id: requestId})
        });
        const data = await res.json();
        if (data.ok) {
            btn.closest('tr').style.opacity = '0.4';
            btn.closest('tr').style.transition = 'opacity .4s';
            setTimeout(() => location.reload(), 600);
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
            btn.disabled = false;
            btn.textContent = 'Complete & Delete';
        }
    } catch (e) {
        alert('Request failed. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Complete & Delete';
    }
}

async function gdprCancel(requestId) {
    if (!confirm('Cancel this deletion request? The user will need to resubmit if they still want their data deleted.')) return;
    const btn = event.target;
    btn.disabled = true;
    try {
        const res = await fetch('/api/gdpr.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'cancel', request_id: requestId})
        });
        const data = await res.json();
        if (data.ok) {
            setTimeout(() => location.reload(), 300);
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
            btn.disabled = false;
        }
    } catch (e) {
        alert('Request failed.');
        btn.disabled = false;
    }
}

// ── Email Templates ────────────────────────────────────────────
let etplTemplates = [];
let etplActive = null;

const etplVarMap = {
  booking_request:      ['client_name','practitioner_name','booking_ref','scheduled_date'],
  booking_confirmed:    ['client_name','practitioner_name','scheduled_date','room_name','join_url'],
  booking_rescheduled:  ['client_name','practitioner_name','new_date'],
  client_action_request:['practitioner_name','client_name','action_type','scheduled_date','client_message_block'],
  gdpr_request:         ['client_name','request_id','submitted_date','deadline_date'],
  gdpr_complete:        ['request_id'],
  security_ip_blocked:  ['ip_address','blocked_at','reason'],
  smtp_test:            ['admin_name','sent_at'],
};

async function etplLoad() {
  if (etplTemplates.length) return;
  const res  = await fetch('/api/email_templates.php?action=list');
  etplTemplates = await res.json();
  const list = document.getElementById('etplList');
  list.innerHTML = etplTemplates.map(t =>
    `<div class="etpl-row" id="etpl-row-${escAdm(t.template_key)}" data-etpl-key="${escAdm(t.template_key)}">
       <div class="etpl-row-name">${escAdm(t.name)}</div>
       <div class="etpl-row-key">${escAdm(t.template_key)}</div>
     </div>`
  ).join('');
}

function etplOpen(key) {
  const tpl = etplTemplates.find(t => t.template_key === key);
  if (!tpl) return;
  etplActive = key;
  document.querySelectorAll('.etpl-row').forEach(r => r.classList.remove('active'));
  const row = document.getElementById('etpl-row-' + key);
  if (row) row.classList.add('active');
  document.getElementById('etplKey').value     = key;
  document.getElementById('etplEditorName').textContent = tpl.name;
  document.getElementById('etplSubject').value = tpl.subject;
  document.getElementById('etplBody').value    = tpl.body_html;
  const vars = etplVarMap[key] || [];
  document.getElementById('etplVars').innerHTML = vars.length
    ? 'Available variables: ' + vars.map(v => `<code>{{${escAdm(v)}}}</code>`).join(' ')
    : '';
  document.getElementById('etplEditor').style.display = '';
}

async function etplSave() {
  const key     = document.getElementById('etplKey').value;
  const subject = document.getElementById('etplSubject').value.trim();
  const body    = document.getElementById('etplBody').value.trim();
  if (!subject || !body) { etplAlert('Subject and body are required.', true); return; }
  const res = await fetch('/api/email_templates.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ action:'save', template_key:key, subject, body_html:body })
  });
  const data = await res.json();
  if (data.ok) {
    const tpl = etplTemplates.find(t => t.template_key === key);
    if (tpl) { tpl.subject = subject; tpl.body_html = body; }
    etplAlert('Template saved.', false);
  } else etplAlert(data.error || 'Save failed.', true);
}

async function etplReset() {
  if (!confirm('Reset this template to the default? Your customisations will be lost.')) return;
  const key = document.getElementById('etplKey').value;
  const res = await fetch('/api/email_templates.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ action:'reset', template_key:key })
  });
  const data = await res.json();
  if (data.ok) {
    document.getElementById('etplSubject').value = data.subject;
    document.getElementById('etplBody').value    = data.body_html;
    const tpl = etplTemplates.find(t => t.template_key === key);
    if (tpl) { tpl.subject = data.subject; tpl.body_html = data.body_html; }
    etplAlert('Reset to default.', false);
  } else etplAlert(data.error || 'Reset failed.', true);
}

async function etplPreview() {
  const key     = document.getElementById('etplKey').value;
  const subject = document.getElementById('etplSubject').value;
  const body    = document.getElementById('etplBody').value;
  const res = await fetch('/api/email_templates.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({ action:'preview', template_key:key, subject, body_html:body })
  });
  const data = await res.json();
  document.getElementById('etplPreviewSubj').textContent = data.subject || '';
  const frame = document.getElementById('etplPreviewFrame');
  frame.srcdoc = data.html || '';
  document.getElementById('etplPreviewModal').style.display = 'flex';
}

function etplAlert(msg, isErr) {
  const el = document.getElementById('etplAlert');
  el.innerHTML = `<div style="padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:12px;
    background:${isErr ? 'rgba(239,83,80,0.12)' : 'rgba(129,199,132,0.12)'};
    color:${isErr ? '#ef9a9a' : '#a5d6a7'};">${msg}</div>`;
  setTimeout(() => el.innerHTML = '', 4000);
}

function escAdm(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Crisis Resources ───────────────────────────────────────────
async function crisisLoad() {
  if (crisisLoaded) return;
  const res = await fetch('/api/crisis_resources.php?action=list');
  const data = await res.json();
  crisisItems = data.ok ? data.resources : [];
  crisisLoaded = true;
  crisisRender();
}

function crisisRender() {
  const el = document.getElementById('crisisList');
  if (!crisisItems.length) {
    el.innerHTML = '<div style="color:var(--text-muted);font-size:13px;">No resources configured yet.</div>';
    return;
  }
  el.innerHTML = `<table class="admin-user-table" style="width:100%;">
    <thead><tr>
      <th style="width:32px;">#</th>
      <th>Label</th>
      <th>Message Preview</th>
      <th style="width:80px;text-align:right;">Actions</th>
    </tr></thead>
    <tbody>
      ${crisisItems.map(r => `
        <tr id="crisis-row-${r.id}">
          <td style="color:var(--text-muted);font-size:12px;">${escAdm(String(r.sort_order))}</td>
          <td><strong style="font-size:13px;">${escAdm(r.label)}</strong></td>
          <td style="color:var(--text-muted);font-size:12px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escAdm(r.message_body)}</td>
          <td style="text-align:right;white-space:nowrap;">
            <button class="btn btn-ghost btn-sm" style="margin-right:4px;" data-crisis-edit="${r.id}">Edit</button>
            <button class="btn btn-sm" style="background:rgba(239,83,80,0.14);color:#ef9a9a;border-color:rgba(239,83,80,0.3);" data-crisis-delete="${r.id}" data-crisis-label="${escAdm(r.label)}">Delete</button>
          </td>
        </tr>`).join('')}
    </tbody>
  </table>`;
}

function crisisShowForm(id) {
  const form = document.getElementById('crisisForm');
  document.getElementById('crisisId').value = '0';
  document.getElementById('crisisLabel').value = '';
  document.getElementById('crisisBody').value = '';
  document.getElementById('crisisSortOrder').value = '0';
  document.getElementById('crisisFormTitle').textContent = 'New Crisis Resource';
  form.style.display = '';
  document.getElementById('crisisLabel').focus();
}

function crisisEdit(id) {
  const r = crisisItems.find(x => x.id == id);
  if (!r) return;
  document.getElementById('crisisId').value = r.id;
  document.getElementById('crisisLabel').value = r.label;
  document.getElementById('crisisBody').value = r.message_body;
  document.getElementById('crisisSortOrder').value = r.sort_order;
  document.getElementById('crisisFormTitle').textContent = 'Edit Crisis Resource';
  document.getElementById('crisisForm').style.display = '';
  document.getElementById('crisisLabel').focus();
}

function crisisHideForm() {
  document.getElementById('crisisForm').style.display = 'none';
}

async function crisisSave() {
  const id    = parseInt(document.getElementById('crisisId').value, 10);
  const label = document.getElementById('crisisLabel').value.trim();
  const body  = document.getElementById('crisisBody').value.trim();
  const sort  = parseInt(document.getElementById('crisisSortOrder').value, 10) || 0;
  if (!label || !body) { crisisAlert('Label and message body are required.', true); return; }

  const form = new FormData();
  form.append('action', 'save');
  form.append('csrf_token', <?= json_encode($_SESSION['csrf_token'] ?? '') ?>);
  form.append('label', label);
  form.append('message_body', body);
  form.append('sort_order', sort);
  form.append('is_active', '1');
  if (id > 0) form.append('id', id);

  const res = await fetch('/api/crisis_resources.php', { method: 'POST', body: form });
  const data = await res.json();
  if (data.ok) {
    crisisLoaded = false;
    await crisisLoad();
    crisisHideForm();
    crisisAlert('Resource saved.', false);
  } else crisisAlert(data.error || 'Save failed.', true);
}

async function crisisDelete(id, label) {
  if (!confirm('Delete "' + label + '"? This cannot be undone.')) return;
  const form = new FormData();
  form.append('action', 'delete');
  form.append('csrf_token', <?= json_encode($_SESSION['csrf_token'] ?? '') ?>);
  form.append('id', id);
  const res = await fetch('/api/crisis_resources.php', { method: 'POST', body: form });
  const data = await res.json();
  if (data.ok) {
    crisisItems = crisisItems.filter(x => x.id != id);
    crisisRender();
    crisisAlert('Deleted.', false);
  } else crisisAlert(data.error || 'Delete failed.', true);
}

function crisisAlert(msg, isErr) {
  const el = document.getElementById('crisisAlert');
  el.innerHTML = `<div style="padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:12px;
    background:${isErr ? 'rgba(239,83,80,0.12)' : 'rgba(129,199,132,0.12)'};
    color:${isErr ? '#ef9a9a' : '#a5d6a7'};">${escAdm(msg)}</div>`;
  setTimeout(() => el.innerHTML = '', 4000);
}

// ── Banners ────────────────────────────────────────────────────
let bannerItems = [];
let bannerLoaded = false;
const BANNER_CSRF = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
const BANNER_CONTEXTS = {show_public:'Public Pages',show_dashboard:'Dashboard',show_profile:'Profile',show_client:'Client Portal',show_admin:'Admin'};

async function bannerLoad() {
  if (bannerLoaded) return;
  const res = await fetch('/api/banners.php?action=list');
  const data = await res.json();
  bannerItems = data.ok ? data.banners : [];
  bannerLoaded = true;
  bannerRender();
}

function bannerRender() {
  const el = document.getElementById('bannerList');
  if (!bannerItems.length) {
    el.innerHTML = '<div style="color:var(--text-muted);font-size:13px;">No banners yet. Create one above.</div>';
    return;
  }
  el.innerHTML = bannerItems.map(b => {
    const activeLbl = b.is_active ? '<span style="color:#a5d6a7;font-size:11px;">● Active</span>' : '<span style="color:var(--text-muted);font-size:11px;">○ Inactive</span>';
    const contexts = Object.entries(BANNER_CONTEXTS)
      .filter(([k]) => b[k] == 1)
      .map(([,v]) => `<span style="font-size:10px;padding:1px 6px;border-radius:10px;background:rgba(124,106,247,0.14);color:#a89cff;">${v}</span>`)
      .join(' ');
    return `<div id="banner-row-${b.id}" style="padding:12px 0;border-bottom:1px solid var(--border);">
      <div style="display:flex;align-items:flex-start;gap:12px;justify-content:space-between;flex-wrap:wrap;">
        <div style="flex:1;min-width:200px;">
          <div style="display:inline-block;padding:4px 10px;border-radius:4px;margin-bottom:8px;font-size:12px;font-weight:500;background:${escAdm(b.bg_color)};color:${escAdm(b.text_color)};">${escAdm(b.content)}</div>
          <div style="font-size:11px;color:var(--text-muted);display:flex;gap:8px;flex-wrap:wrap;align-items:center;">${activeLbl}${contexts || '<span style="font-size:10px;color:var(--text-muted);">No areas selected</span>'}</div>
        </div>
        <div style="display:flex;gap:6px;">
          <button class="btn btn-ghost btn-sm" data-banner-edit="${b.id}">Edit</button>
          <button class="btn btn-sm" style="background:rgba(239,83,80,0.14);color:#ef9a9a;border-color:rgba(239,83,80,0.3);" data-banner-delete="${b.id}">Delete</button>
        </div>
      </div>
    </div>`;
  }).join('');
}

function bannerShowForm() {
  document.getElementById('bannerId').value = '0';
  document.getElementById('bannerContent').value = '';
  document.getElementById('bannerBgColor').value = '#2a2060';
  document.getElementById('bannerTextColor').value = '#ffffff';
  document.getElementById('bannerIsActive').checked = true;
  Object.keys(BANNER_CONTEXTS).forEach(k => { const el = document.getElementById('banner_' + k); if (el) el.checked = false; });
  document.getElementById('bannerFormTitle').textContent = 'New Banner';
  document.getElementById('bannerPreviewWrap').style.display = 'none';
  document.getElementById('bannerForm').style.display = '';
  document.getElementById('bannerContent').focus();
}

function bannerEdit(id) {
  const b = bannerItems.find(x => x.id == id);
  if (!b) return;
  document.getElementById('bannerId').value = b.id;
  document.getElementById('bannerContent').value = b.content;
  document.getElementById('bannerBgColor').value = b.bg_color;
  document.getElementById('bannerTextColor').value = b.text_color;
  document.getElementById('bannerIsActive').checked = !!parseInt(b.is_active);
  Object.keys(BANNER_CONTEXTS).forEach(k => { const el = document.getElementById('banner_' + k); if (el) el.checked = !!parseInt(b[k]); });
  document.getElementById('bannerFormTitle').textContent = 'Edit Banner';
  document.getElementById('bannerPreviewWrap').style.display = 'none';
  document.getElementById('bannerForm').style.display = '';
  bannerPreview();
}

function bannerHideForm() {
  document.getElementById('bannerForm').style.display = 'none';
}

function bannerPreview() {
  const content  = document.getElementById('bannerContent').value || 'Preview text';
  const bgColor  = document.getElementById('bannerBgColor').value;
  const txtColor = document.getElementById('bannerTextColor').value;
  const el = document.getElementById('bannerPreviewEl');
  el.textContent = content;
  el.style.background = bgColor;
  el.style.color = txtColor;
  document.getElementById('bannerPreviewWrap').style.display = '';
}

async function bannerSave() {
  const content = document.getElementById('bannerContent').value.trim();
  if (!content) { bannerAlert('Banner text is required.', true); return; }
  const fd = new FormData();
  fd.append('action',     'save');
  fd.append('csrf_token', BANNER_CSRF);
  fd.append('id',         document.getElementById('bannerId').value);
  fd.append('content',    content);
  fd.append('bg_color',   document.getElementById('bannerBgColor').value);
  fd.append('text_color', document.getElementById('bannerTextColor').value);
  if (document.getElementById('bannerIsActive').checked) fd.append('is_active', '1');
  Object.keys(BANNER_CONTEXTS).forEach(k => { if (document.getElementById('banner_' + k)?.checked) fd.append(k, '1'); });
  const res = await fetch('/api/banners.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) {
    bannerLoaded = false;
    await bannerLoad();
    bannerHideForm();
    bannerAlert('Banner saved.', false);
  } else bannerAlert(data.error || 'Save failed.', true);
}

async function bannerDelete(id) {
  if (!confirm('Delete this banner?')) return;
  const fd = new FormData();
  fd.append('action', 'delete'); fd.append('csrf_token', BANNER_CSRF); fd.append('id', id);
  const res = await fetch('/api/banners.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) { bannerItems = bannerItems.filter(b => b.id != id); bannerRender(); bannerAlert('Deleted.', false); }
  else bannerAlert(data.error || 'Delete failed.', true);
}

function bannerAlert(msg, isErr) {
  const el = document.getElementById('bannerAlert');
  el.innerHTML = `<div style="padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:12px;
    background:${isErr ? 'rgba(239,83,80,0.12)' : 'rgba(129,199,132,0.12)'};
    color:${isErr ? '#ef9a9a' : '#a5d6a7'};">${escAdm(msg)}</div>`;
  setTimeout(() => el.innerHTML = '', 4000);
}

(function initAdminTour() {
  const TOUR_KEY = 'ss_tour_v1_admin';
  if (localStorage.getItem(TOUR_KEY)) return;

  const steps = [
    {
      target: '[data-section="general"]',
      fallback: '.settings-nav',
      title: 'General Settings',
      body: 'Configure your platform name, logo, timezone, and public-facing options here. Start by making sure your branding is set up correctly.'
    },
    {
      target: '[data-section="features"]',
      fallback: '.settings-nav',
      title: 'Features',
      body: 'Enable or disable platform features — bookings, client portal, activities, file uploads, and more. Tailor the platform to how you work.'
    },
    {
      target: '[data-section="users"]',
      fallback: '.settings-nav',
      title: 'Manage Users',
      body: 'View and manage all practitioner accounts. Approve pending registrations, update roles, or remove access from here.'
    },
    {
      target: '[data-section="security"]',
      fallback: '.settings-nav',
      title: 'Security',
      body: 'Manage the IP firewall, rate limiting, blocked addresses, and login attempt logs. Check here if someone is having trouble accessing the platform.'
    },
    {
      target: '[data-section="gdpr"]',
      fallback: '.settings-nav',
      title: 'GDPR & Data',
      body: 'Process data deletion requests, review export logs, and manage consent records. Required for UK/EU compliance.'
    },
    {
      target: '[data-section="email-templates"]',
      fallback: '.settings-nav',
      title: 'Email Templates',
      body: 'Customise the transactional emails sent to practitioners and clients — welcome messages, password resets, booking confirmations, and more.'
    },
    {
      target: '[data-section="email"]',
      fallback: '.settings-nav',
      title: 'SMTP Settings',
      body: 'Connect your outbound mail server. Without this, notification and booking emails will not send. Test the connection after saving.'
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
      <span id="atour-title" style="font-size:15px;font-weight:600;color:#e8eaf6;"></span>
      <button id="atour-close" style="background:none;border:none;color:#666;font-size:18px;cursor:pointer;line-height:1;padding:0 0 0 12px;">&times;</button>
    </div>
    <p id="atour-body" style="font-size:13px;color:#9e9eb8;line-height:1.6;margin:0 0 16px;"></p>
    <div style="display:flex;align-items:center;justify-content:space-between;">
      <span id="atour-prog" style="font-size:12px;color:#555;"></span>
      <div style="display:flex;gap:8px;">
        <button id="atour-skip" style="background:none;border:1px solid #333;color:#888;font-size:12px;padding:6px 12px;border-radius:6px;cursor:pointer;">Skip</button>
        <button id="atour-next" style="background:#7c6af7;border:none;color:#fff;font-size:12px;padding:6px 14px;border-radius:6px;cursor:pointer;font-weight:600;">Next</button>
      </div>
    </div>`;
  document.body.appendChild(bubble);
  bubble.querySelector('#atour-close').addEventListener('click', endAdminTour);
  bubble.querySelector('#atour-skip').addEventListener('click', endAdminTour);
  bubble.querySelector('#atour-next').addEventListener('click', advanceAdminTour);

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

  function showAdminStep(i) {
    const s = steps[i];
    let el = document.querySelector(s.target) || document.querySelector(s.fallback);
    if (!el) el = document.body;
    const rect = el.getBoundingClientRect();
    const pad = 6;
    spotlight.style.top    = (rect.top    - pad) + 'px';
    spotlight.style.left   = (rect.left   - pad) + 'px';
    spotlight.style.width  = (rect.width  + pad * 2) + 'px';
    spotlight.style.height = (rect.height + pad * 2) + 'px';
    document.getElementById('atour-title').textContent = s.title;
    document.getElementById('atour-body').textContent  = s.body;
    document.getElementById('atour-prog').textContent  = (i + 1) + ' / ' + steps.length;
    const nextBtn = document.getElementById('atour-next');
    nextBtn.textContent = i === steps.length - 1 ? 'Done' : 'Next';
    positionBubble(rect);
    bubble.style.opacity = '1';
  }

  function advanceAdminTour() {
    current++;
    if (current >= steps.length) { endAdminTour(); return; }
    showAdminStep(current);
  }

  function endAdminTour() {
    localStorage.setItem(TOUR_KEY, '1');
    spotlight.remove(); bubble.remove(); overlay.remove();
  }

  setTimeout(() => showAdminStep(0), 1200);
})();

// ── Admin DPA Management ──────────────────────────────────────
const dpaTypeLabels = {
  hosting: 'Hosting Provider', smtp: 'SMTP / Email Provider',
  ai_openai: 'OpenAI', ai_anthropic: 'Anthropic', ai_google: 'Google AI',
  ai_cohere: 'Cohere', ai_custom: 'Custom AI Vendor',
  payment_stripe: 'Stripe', payment_paypal: 'PayPal',
  payment_square: 'Square', payment_custom: 'Custom Payment Processor'
};

async function loadAdminDpas() {
  const list = document.getElementById('dpaList');
  if (!list) return;
  list.innerHTML = '<div style="text-align:center;padding:24px 0;"><div class="spinner"></div></div>';
  try {
    const res  = await fetch('/api/dpa.php?action=list_platform');
    const data = await res.json();
    if (!data.ok || !data.docs.length) {
      list.innerHTML = '<p style="color:var(--text-muted);font-size:13px;padding:12px 0;">No DPA documents uploaded yet.</p>';
      return;
    }
    let html = '<table class="admin-user-table" style="width:100%;"><thead><tr><th>Type</th><th>Provider</th><th>File</th><th>Notes</th><th>Uploaded</th><th></th></tr></thead><tbody>';
    for (const d of data.docs) {
      const date = new Date(d.uploaded_at).toLocaleDateString('en-GB', {day:'numeric',month:'short',year:'numeric'});
      const size = d.file_size > 1024*1024 ? (d.file_size/1024/1024).toFixed(1)+'MB' : Math.round(d.file_size/1024)+'KB';
      html += `<tr>
        <td style="font-size:12px;"><span style="background:rgba(74,158,255,0.12);color:#4a9eff;border-radius:4px;padding:2px 7px;font-family:monospace;">${escHtmlAdmin(dpaTypeLabels[d.type]||d.type)}</span></td>
        <td style="font-weight:500;">${escHtmlAdmin(d.vendor_name)}</td>
        <td style="font-size:12px;font-family:monospace;color:var(--text-muted);">${escHtmlAdmin(d.file_name)} <span style="color:var(--text-muted);">(${size})</span></td>
        <td style="font-size:12px;color:var(--text-muted);">${escHtmlAdmin(d.notes||'—')}</td>
        <td style="font-size:12px;color:var(--text-muted);">${date}</td>
        <td style="display:flex;gap:6px;">
          <a href="/api/dpa.php?action=download&id=${d.id}" target="_blank" class="btn btn-sm btn-ghost">View</a>
          <button class="btn btn-sm" style="background:rgba(239,83,80,0.12);color:#ef9a9a;border-color:rgba(239,83,80,0.25);" data-dpa-delete="${d.id}">Delete</button>
        </td>
      </tr>`;
    }
    html += '</tbody></table>';
    list.innerHTML = html;
  } catch(e) {
    list.innerHTML = '<p style="color:#ef9a9a;font-size:13px;">Failed to load DPA documents.</p>';
  }
}

function escHtmlAdmin(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Compliance — primary jurisdiction card highlight on change
document.querySelectorAll('input[name="jurisdiction_primary"]').forEach(function(radio) {
  radio.addEventListener('change', function() {
    document.querySelectorAll('.js-juris-primary-card').forEach(function(card) {
      const checked = card.querySelector('input[type="radio"]').checked;
      card.style.borderColor  = checked ? 'rgba(124,106,247,0.5)' : 'rgba(255,255,255,0.07)';
      card.style.background   = checked ? 'rgba(124,106,247,0.1)'  : 'rgba(255,255,255,0.02)';
    });
    updateCustomPanel();
  });
});

// Compliance — additional jurisdiction card style on change
document.querySelectorAll('.js-juris-additional-card input[type="checkbox"]').forEach(function(cb) {
  cb.addEventListener('change', updateCustomPanel);
});

function updateCustomPanel() {
  const primaryCustom = document.querySelector('input[name="jurisdiction_primary"][value="custom"]')?.checked;
  const additionalCustom = document.querySelector('input[name="jurisdiction_additional_custom"]')?.checked;
  const panel = document.getElementById('js-custom-html-panel');
  if (panel) panel.style.display = (primaryCustom || additionalCustom) ? '' : 'none';
}

async function uploadAdminDpa(e) {
  e.preventDefault();
  const btn  = document.getElementById('dpaUploadBtn');
  const form = document.getElementById('dpaUploadForm');
  const alert = document.getElementById('dpaAlert');
  btn.disabled = true; btn.textContent = 'Uploading…';
  alert.innerHTML = '';
  try {
    const fd  = new FormData(form);
    const res = await fetch('/api/dpa.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      alert.innerHTML = '<div style="background:rgba(74,222,128,0.1);border:1px solid rgba(74,222,128,0.2);border-radius:8px;padding:10px 14px;font-size:13px;color:#4ade80;margin-bottom:8px;">DPA uploaded successfully.</div>';
      form.reset();
      loadAdminDpas();
    } else {
      alert.innerHTML = `<div style="background:rgba(239,83,80,0.1);border:1px solid rgba(239,83,80,0.2);border-radius:8px;padding:10px 14px;font-size:13px;color:#ef9a9a;margin-bottom:8px;">${escHtmlAdmin(data.error||'Upload failed.')}</div>`;
    }
  } catch(err) {
    alert.innerHTML = '<div style="background:rgba(239,83,80,0.1);border:1px solid rgba(239,83,80,0.2);border-radius:8px;padding:10px 14px;font-size:13px;color:#ef9a9a;margin-bottom:8px;">Connection error. Please try again.</div>';
  }
  btn.disabled = false; btn.textContent = 'Upload DPA';
}

async function deleteAdminDpa(id) {
  if (!confirm('Delete this DPA document? This cannot be undone.')) return;
  const csrf = document.querySelector('[name="csrf_token"]')?.value || '';
  try {
    const res  = await fetch('/api/dpa.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', id, csrf_token: csrf })
    });
    const data = await res.json();
    if (data.ok) { loadAdminDpas(); }
    else { alert(data.error || 'Delete failed.'); }
  } catch(e) { alert('Connection error.'); }
}

</script>
</body>
</html>
