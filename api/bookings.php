<?php
/**
 * Serenity Spaces — Bookings API
 *
 * Public (no session required):
 *   POST action=create — visitor books a session
 *
 * Practitioner (session required):
 *   GET  action=list              — list bookings for this practitioner
 *   POST action=assign_room       — assign a room to a pending booking (→ scheduled)
 *   POST action=cancel            — cancel a booking
 *   POST action=complete          — mark a booking as completed
 *
 * End-user (end_user session or booking token):
 *   GET  action=my_bookings       — list bookings for an end user (future feature)
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_once __DIR__ . '/../includes/phi_crypto.php';
require_once __DIR__ . '/../includes/password_policy.php';
require_once __DIR__ . '/../db/audit.php';

/** Generate an Apple-style temporary password: xxxx-xxxx-xxxx-xxxx (lowercase, no l/o) */
function _generateTempPassword(): string {
    $chars = 'abcdefghijkmnpqrstuvwxyz'; // 24 chars, excludes l and o
    $len   = strlen($chars);
    $groups = [];
    for ($g = 0; $g < 4; $g++) {
        $group = '';
        for ($c = 0; $c < 4; $c++) {
            $group .= $chars[random_int(0, $len - 1)];
        }
        $groups[] = $group;
    }
    return implode('-', $groups);
}

header('Content-Type: application/json');

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
// Support both multipart/form-data (FormData) and application/json
if ($method === 'POST') {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($ct, 'application/json')) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $input = $_POST;
    }
} else {
    $input = $_GET;
}
$action = $input['action'] ?? '';

// ── Public: create booking ─────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    if (getSetting('production_mode', '0') !== '1') {
        http_response_code(503);
        echo json_encode(['error' => 'Bookings are not available yet. The platform is currently in setup mode.']);
        exit;
    }
    $practId    = (int)($input['practitioner_id'] ?? 0);
    $schUtc     = trim($input['scheduled_at_utc'] ?? '');
    $guestName  = trim($input['guest_name']  ?? '');
    $guestEmail = trim($input['guest_email'] ?? '');

    // ── Avatar upload (required) ────────────────────────────────
    $guestAvatar = null;
    if (!empty($_FILES['avatar_upload']) && $_FILES['avatar_upload']['error'] === UPLOAD_ERR_OK) {
        $file      = $_FILES['avatar_upload'];
        $allowed   = ['image/jpeg','image/png','image/gif','image/webp'];
        $finfo     = new finfo(FILEINFO_MIME_TYPE);
        $mimeType  = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, $allowed, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Only JPEG, PNG, GIF, and WEBP images are allowed.']);
            exit;
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['error' => 'File size must not exceed 5 MB.']);
            exit;
        }
        $extMap      = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        $ext         = $extMap[$mimeType] ?? 'jpg';
        $name        = bin2hex(random_bytes(12)) . '.' . $ext;
        move_uploaded_file($file['tmp_name'], __DIR__ . '/../assets/uploads/' . $name);
        $guestAvatar = '/assets/uploads/' . $name;
    }
    if (!$guestAvatar) {
        http_response_code(400);
        echo json_encode(['error' => 'A photo is required to complete your booking.']);
        exit;
    }
    $gender       = trim($input['gender']        ?? '');
    $sexuality    = trim($input['sexuality']     ?? '');
    $dob          = trim($input['date_of_birth'] ?? '');
    $location     = trim($input['location']      ?? '');
    // Sanitise identity fields
    $allowedGenders    = ['male','female','trans_woman','trans_man','non_binary','genderfluid','prefer_not_to_say'];
    $allowedSexualities= ['straight','gay','lesbian','bisexual','pansexual','asexual','queer','questioning','prefer_not_to_say'];
    if (!in_array($gender, $allowedGenders, true)) $gender = null;
    if (!in_array($sexuality, $allowedSexualities, true)) $sexuality = null;
    if ($dob && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) $dob = null;
    if ($location && !preg_match('/^[A-Z]{2}$/', $location)) $location = null;

    if (!$practId || !$schUtc || !$guestName) {
        http_response_code(400);
        echo json_encode(['error' => 'practitioner_id, scheduled_at_utc, and guest_name are required']);
        exit;
    }

    if ($guestEmail && !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email address']);
        exit;
    }

    // Validate datetime format
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $schUtc, new DateTimeZone('UTC'));
    if (!$dt) {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $schUtc, new DateTimeZone('UTC'));
    }
    if (!$dt || $dt < new DateTime('now', new DateTimeZone('UTC'))) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or past scheduled_at_utc']);
        exit;
    }
    $scheduledAt = $dt->format('Y-m-d H:i:s');

    // Check practitioner exists
    $stmt = $pdo->prepare('SELECT id FROM practitioners WHERE id = ? LIMIT 1');
    $stmt->execute([$practId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Practitioner not found']);
        exit;
    }

    // Check slot not already taken
    $stmt = $pdo->prepare(
        'SELECT id FROM bookings
         WHERE practitioner_id = ? AND scheduled_at = ? AND status IN (\'pending\',\'scheduled\')'
    );
    $stmt->execute([$practId, $scheduledAt]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'This time slot is no longer available']);
        exit;
    }

    // Auto-create or link client account when email is provided
    $endUserId   = null;
    $tempPassword = null;
    $isNewAccount = false;
    if ($guestEmail) {
        $stmt = $pdo->prepare('SELECT id, must_change_password FROM end_users WHERE email = ? LIMIT 1');
        $stmt->execute([$guestEmail]);
        $existing = $stmt->fetch();
        if ($existing) {
            // Existing account — link to it
            $endUserId = (int)$existing['id'];
        } else {
            // New account — generate Apple-style temporary password
            $tempPassword = _generateTempPassword();
            $hash = ss_password_hash($tempPassword);
            $pdo->prepare(
                'INSERT INTO end_users (email, password_hash, display_name, avatar_preset, must_change_password, gender, sexuality, date_of_birth, location)
                 VALUES (?,?,?,?,1,?,?,?,?)'
            )->execute([$guestEmail, $hash, $guestName, $guestAvatar,
                        $gender    ? phi_encrypt($gender)    : null,
                        $sexuality ? phi_encrypt($sexuality) : null,
                        $dob       ? phi_encrypt($dob)       : null,
                        $location ?: null]);
            $endUserId    = (int)$pdo->lastInsertId();
            $isNewAccount = true;
        }
    }

    // ── Discount code (optional) ───────────────────────────────
    $discountCode   = strtoupper(trim($input['discount_code'] ?? ''));
    $discountCodeId = null;
    if ($discountCode !== '') {
        $dcStmt = $pdo->prepare(
            "SELECT * FROM discount_codes
             WHERE practitioner_id = ? AND code = ? AND is_active = 1
               AND valid_from <= CURDATE()
               AND (valid_until IS NULL OR valid_until >= CURDATE())
             LIMIT 1"
        );
        $dcStmt->execute([$practId, $discountCode]);
        $dc = $dcStmt->fetch();

        if ($dc) {
            // Check total uses
            $utStmt = $pdo->prepare('SELECT COUNT(*) FROM discount_code_uses WHERE code_id = ?');
            $utStmt->execute([$dc['id']]);
            $usedTotal = (int)$utStmt->fetchColumn();
            if ($dc['max_total_uses'] !== null && $usedTotal >= (int)$dc['max_total_uses']) {
                $dc = null; // exhausted — silently ignore (client validated before submit)
            }
        }
        if ($dc && $endUserId && $dc['max_uses_per_client'] !== null) {
            // Check per-client uses
            $ucStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM discount_code_uses WHERE code_id = ? AND end_user_id = ?'
            );
            $ucStmt->execute([$dc['id'], $endUserId]);
            $usedByClient = (int)$ucStmt->fetchColumn();
            if ($usedByClient >= (int)$dc['max_uses_per_client']) {
                $dc = null; // per-client limit reached — silently ignore
            }
        }
        if ($dc) {
            $discountCodeId = (int)$dc['id'];
        }
    }

    $bookingToken = bin2hex(random_bytes(24));
    $pdo->prepare(
        'INSERT INTO bookings
            (practitioner_id, end_user_id, guest_name, guest_email, guest_avatar,
             scheduled_at, booking_token, discount_code_id)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $practId,
        $endUserId,
        phi_encrypt($guestName),
        $guestEmail ? phi_encrypt($guestEmail) : null,
        $guestAvatar,
        $scheduledAt,
        $bookingToken,
        $discountCodeId,
    ]);
    $bookingId = (int)$pdo->lastInsertId();

    // Record discount code use
    if ($discountCodeId) {
        $pdo->prepare(
            'INSERT INTO discount_code_uses (code_id, booking_id, end_user_id) VALUES (?,?,?)'
        )->execute([$discountCodeId, $bookingId, $endUserId]);
    }

    // Store consent record if special category data was provided and consent given
    $specialDataConsent = ($input['special_data_consent'] ?? '') === '1';
    $hasSpecialData     = $gender || $sexuality || $dob;
    if ($hasSpecialData && $specialDataConsent) {
        $consentText = 'I give explicit consent for the processing of the special category personal data I have provided (gender, sexuality, date of birth) for the purpose of supporting my session. This data is encrypted at rest and used only to help my practitioner provide appropriate support. I understand I may withdraw this consent at any time.';
        $pdo->prepare(
            'INSERT INTO consent_records (booking_id, end_user_id, guest_email_enc, consent_type, consent_version, consent_text, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $bookingId,
            $endUserId ?: null,
            $guestEmail ? phi_encrypt($guestEmail) : null,
            'special_category_data',
            '1.0',
            $consentText,
            $_SERVER['REMOTE_ADDR'] ?? null,
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
        ]);
    }

    // Store AI consent if practitioner has AI enabled and end_user_id is known
    if ($endUserId && !empty($input['ai_consent_allowed'])) {
        $aiAllowed = ($input['ai_consent_allowed'] ?? '0') === '1' ? 1 : 0;
        try {
            $pdo->prepare('
                INSERT INTO ai_consent
                    (end_user_id, practitioner_id, booking_id, ai_allowed,
                     scope_summarization, scope_notes, scope_post_session, scope_in_session)
                VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    booking_id          = COALESCE(booking_id, VALUES(booking_id)),
                    ai_allowed          = VALUES(ai_allowed),
                    scope_summarization = VALUES(scope_summarization),
                    scope_notes         = VALUES(scope_notes),
                    scope_post_session  = VALUES(scope_post_session),
                    scope_in_session    = VALUES(scope_in_session),
                    updated_at          = CURRENT_TIMESTAMP
            ')->execute([
                $endUserId, $practId, $bookingId, $aiAllowed,
                ($aiAllowed && ($input['ai_consent_scope_summarization'] ?? '0') === '1') ? 1 : 0,
                ($aiAllowed && ($input['ai_consent_scope_notes']         ?? '0') === '1') ? 1 : 0,
                ($aiAllowed && ($input['ai_consent_scope_post_session']  ?? '0') === '1') ? 1 : 0,
                ($aiAllowed && ($input['ai_consent_scope_in_session']    ?? '0') === '1') ? 1 : 0,
            ]);
        } catch (PDOException $e) { /* ai_consent table may not exist on older installs */ }
    }

    // Send confirmation email to client
    if ($guestEmail) {
        $practStmt = $pdo->prepare('SELECT display_name FROM practitioners WHERE id = ? LIMIT 1');
        $practStmt->execute([$practId]);
        $practName = $practStmt->fetchColumn() ?: 'Your practitioner';

        $dtDisplay = $dt->format('l, F j, Y \a\t g:i A T');

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl  = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $portalUrl = $baseUrl . '/login.php?tab=client';

        $accountSection = '';
        if ($isNewAccount && $tempPassword !== null) {
            $accountSection = "
            <div style='margin:24px 0;padding:20px;background:rgba(124,106,247,0.08);border:1px solid rgba(124,106,247,0.2);border-radius:10px;'>
                <p style='margin:0 0 12px;font-weight:600;color:#7c6af7;'>Your Client Portal Account</p>
                <p style='margin:0 0 8px;'>A Serenity Spaces account has been created for you so you can manage your sessions:</p>
                <p style='margin:8px 0;'><strong>Email:</strong> " . htmlspecialchars($guestEmail) . "</p>
                <p style='margin:8px 0;'><strong>Temporary password:</strong> <code style='background:rgba(255,255,255,0.1);padding:3px 8px;border-radius:4px;font-size:14px;letter-spacing:1px;'>{$tempPassword}</code></p>
                <p style='margin:12px 0 0;font-size:13px;color:rgba(255,255,255,0.6);'>You will be asked to set a new password on first login.</p>
                <p style='margin:16px 0 0;text-align:center;'>
                    <a href='" . htmlspecialchars($portalUrl) . "' style='display:inline-block;background:#7c6af7;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;font-size:14px;'>
                        Access Your Portal
                    </a>
                </p>
            </div>";
        }

        send_template_email($pdo, 'booking_request', 'Booking Received', [
            'client_name'       => $guestName,
            'practitioner_name' => $practName,
            'booking_ref'       => $bookingToken,
            'scheduled_date'    => $dtDisplay,
            'account_section'   => $accountSection,
            'privacy_url'       => $baseUrl . '/privacy.php',
        ], $guestEmail, $guestName);
    }

    echo json_encode(['ok' => true, 'booking_id' => $bookingId, 'booking_token' => $bookingToken]);
    exit;
}

// ── Practitioner: list bookings ────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    if (empty($_SESSION['practitioner_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }
    $practId  = (int)$_SESSION['practitioner_id'];
    $statuses = ['pending','scheduled'];
    $statusIn = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $pdo->prepare(
        "SELECT b.*, r.name AS room_name, r.room_token
         FROM bookings b
         LEFT JOIN rooms r ON r.id = b.room_id
         WHERE b.practitioner_id = ? AND b.status IN ({$statusIn}) AND b.scheduled_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR
         ORDER BY b.scheduled_at ASC"
    );
    $stmt->execute(array_merge([$practId], $statuses));
    $rows = $stmt->fetchAll();
    phi_decrypt_bookings($rows);
    echo json_encode($rows);
    exit;
}

// ── Practitioner: today's schedule ────────────────────────────
if ($method === 'GET' && $action === 'today') {
    if (empty($_SESSION['practitioner_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }
    $practId = (int)$_SESSION['practitioner_id'];
    // Get practitioner timezone to determine "today" for them
    $tzStmt = $pdo->prepare('SELECT timezone FROM practitioners WHERE id = ? LIMIT 1');
    $tzStmt->execute([$practId]);
    $tz = $tzStmt->fetchColumn() ?: 'UTC';
    try {
        $tzObj = new DateTimeZone($tz);
    } catch (Exception $e) {
        $tzObj = new DateTimeZone('UTC');
    }
    $localNow   = new DateTime('now', $tzObj);
    $localStart = (clone $localNow)->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'));
    $localEnd   = (clone $localNow)->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'));

    $stmt = $pdo->prepare(
        'SELECT b.*, r.name AS room_name, r.room_token, r.id AS room_id_check
         FROM bookings b
         LEFT JOIN rooms r ON r.id = b.room_id
         WHERE b.practitioner_id = ? AND b.status IN (\'pending\',\'scheduled\',\'completed\')
           AND b.scheduled_at BETWEEN ? AND ?
         ORDER BY b.scheduled_at ASC'
    );
    $stmt->execute([$practId, $localStart->format('Y-m-d H:i:s'), $localEnd->format('Y-m-d H:i:s')]);
    $bookings = $stmt->fetchAll();
    phi_decrypt_bookings($bookings);
    echo json_encode(['bookings' => $bookings, 'practitioner_tz' => $tz]);
    exit;
}

// ── Calendar: all bookings for a given month ───────────────────
if ($method === 'GET' && $action === 'calendar') {
    if (empty($_SESSION['practitioner_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }
    $practId = (int)$_SESSION['practitioner_id'];
    $monthParam = trim($input['month'] ?? ''); // expected: "YYYY-MM"
    if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
        $monthParam = date('Y-m');
    }
    [$yr, $mo] = explode('-', $monthParam);
    $yr = (int)$yr; $mo = (int)$mo;
    // UTC bounds: start = first moment of that calendar month, end = last
    // We fetch a generous range (prev/next day padding) to cover timezone edges
    $rangeStart = sprintf('%04d-%02d-01 00:00:00', $yr, $mo);
    $lastDay    = date('t', mktime(0, 0, 0, $mo, 1, $yr));
    $rangeEnd   = sprintf('%04d-%02d-%02d 23:59:59', $yr, $mo, $lastDay);
    // Pad by one day each side for TZ boundary safety
    $startDt = new DateTime($rangeStart, new DateTimeZone('UTC'));
    $startDt->modify('-1 day');
    $endDt   = new DateTime($rangeEnd, new DateTimeZone('UTC'));
    $endDt->modify('+1 day');

    $stmt = $pdo->prepare(
        'SELECT b.id, b.scheduled_at, b.status, b.guest_name, b.guest_avatar, b.guest_email,
                r.name AS room_name, r.id AS room_id_val
         FROM bookings b
         LEFT JOIN rooms r ON r.id = b.room_id
         WHERE b.practitioner_id = ?
           AND b.scheduled_at BETWEEN ? AND ?
         ORDER BY b.scheduled_at ASC'
    );
    $stmt->execute([$practId, $startDt->format('Y-m-d H:i:s'), $endDt->format('Y-m-d H:i:s')]);
    $bookings = $stmt->fetchAll();
    phi_decrypt_bookings($bookings);

    $tzStmt = $pdo->prepare('SELECT timezone FROM practitioners WHERE id = ? LIMIT 1');
    $tzStmt->execute([$practId]);
    $tz = $tzStmt->fetchColumn() ?: 'UTC';

    echo json_encode(['bookings' => $bookings, 'practitioner_tz' => $tz]);
    exit;
}

// ── Practitioner-only POST actions ─────────────────────────────
if ($method === 'POST') {
    if (empty($_SESSION['practitioner_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }
    $practId = (int)$_SESSION['practitioner_id'];

    // ── create_for_client ────────────────────────────────────────
    // Practitioner-initiated booking — practitioner schedules a session for an
    // existing client (or by email for a new one). Goes straight to 'scheduled'
    // since the practitioner is the originator. Sends client confirmation email.
    if ($action === 'create_for_client') {
        if (!validate_csrf($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF validation failed']);
            exit;
        }

        $endUserId = (int)($input['end_user_id'] ?? 0);
        $guestName = trim((string)($input['guest_name']  ?? ''));
        $guestEmail= trim((string)($input['guest_email'] ?? ''));
        $schUtc    = trim((string)($input['scheduled_at_utc'] ?? ''));
        $roomId    = (int)($input['room_id']         ?? 0) ?: null;
        $intakeId  = (int)($input['intake_form_id']  ?? 0) ?: null;
        $duration  = (int)($input['duration_minutes']?? 60);
        if ($duration <= 0 || $duration > 480) $duration = 60;

        // Resolve client identity: either an existing portal account, or
        // by email (we'll create a guest-only booking).
        $resolvedEndUserId = null;
        $resolvedName      = $guestName;
        $resolvedEmail     = $guestEmail;
        if ($endUserId > 0) {
            // Verify the practitioner has worked with this end_user before.
            // Stops a hostile practitioner from booking against arbitrary user IDs.
            $check = $pdo->prepare(
                'SELECT eu.id, eu.email, eu.display_name
                 FROM end_users eu
                 JOIN bookings b ON b.end_user_id = eu.id
                 WHERE eu.id = ? AND b.practitioner_id = ?
                 LIMIT 1'
            );
            $check->execute([$endUserId, $practId]);
            $eu = $check->fetch(PDO::FETCH_ASSOC);
            if (!$eu) {
                http_response_code(403);
                echo json_encode(['error' => 'No prior relationship with this client']);
                exit;
            }
            $resolvedEndUserId = (int)$eu['id'];
            $resolvedName      = $resolvedName ?: ($eu['display_name'] ?? '');
            $resolvedEmail     = $resolvedEmail ?: ($eu['email'] ?? '');
        }

        if ($resolvedName === '' || ($resolvedEmail !== '' && !filter_var($resolvedEmail, FILTER_VALIDATE_EMAIL))) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid client name (and email if guest) required']);
            exit;
        }

        // Validate datetime
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $schUtc, new DateTimeZone('UTC'))
            ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $schUtc, new DateTimeZone('UTC'));
        if (!$dt || $dt < new DateTime('now', new DateTimeZone('UTC'))) {
            http_response_code(400);
            echo json_encode(['error' => 'scheduled_at_utc must be valid and in the future']);
            exit;
        }
        $scheduledAt = $dt->format('Y-m-d H:i:s');

        // Slot conflict check
        $clash = $pdo->prepare(
            'SELECT id FROM bookings
             WHERE practitioner_id = ? AND scheduled_at = ? AND status IN (\'pending\',\'scheduled\')'
        );
        $clash->execute([$practId, $scheduledAt]);
        if ($clash->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'That slot is already taken']);
            exit;
        }

        // Verify room ownership if specified
        if ($roomId) {
            $rs = $pdo->prepare('SELECT id FROM rooms WHERE id = ? AND practitioner_id = ?');
            $rs->execute([$roomId, $practId]);
            if (!$rs->fetch()) {
                http_response_code(403);
                echo json_encode(['error' => 'Room not found']);
                exit;
            }
        }

        // Verify intake form ownership if specified
        $intakeToken = null;
        if ($intakeId) {
            $is = $pdo->prepare('SELECT id FROM intake_forms WHERE id = ? AND practitioner_id = ?');
            $is->execute([$intakeId, $practId]);
            if (!$is->fetch()) $intakeId = null;
            else $intakeToken = bin2hex(random_bytes(24));
        }

        $bookingToken = bin2hex(random_bytes(24));

        $ins = $pdo->prepare(
            'INSERT INTO bookings
                (practitioner_id, room_id, end_user_id, guest_name, guest_email,
                 scheduled_at, duration_minutes, status, booking_token,
                 intake_form_id, intake_token)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'scheduled\', ?, ?, ?)'
        );
        $ins->execute([
            $practId, $roomId, $resolvedEndUserId,
            $resolvedName, $resolvedEmail ?: null,
            $scheduledAt, $duration, $bookingToken,
            $intakeId, $intakeToken,
        ]);
        $bookingId = (int)$pdo->lastInsertId();

        audit_log($pdo, 'booking.create_for_client', [
            'practitioner_id' => $practId,
            'entity_type'     => 'booking',
            'entity_id'       => $bookingId,
            'context'         => ['end_user_id' => $resolvedEndUserId, 'scheduled_at' => $scheduledAt],
        ]);

        // Email the client a confirmation with portal link
        if ($resolvedEmail) {
            $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl   = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $portalUrl = $baseUrl . '/client_portal.php';
            $intakeUrl = $intakeToken ? $baseUrl . '/intake.php?token=' . urlencode($intakeToken) : '';

            $practNameStmt = $pdo->prepare('SELECT display_name FROM practitioners WHERE id = ? LIMIT 1');
            $practNameStmt->execute([$practId]);
            $practName = $practNameStmt->fetchColumn() ?: 'your practitioner';

            $bodyHtml = '
                <p>Hi ' . htmlspecialchars($resolvedName) . ',</p>
                <p><strong>' . htmlspecialchars($practName) . '</strong> has scheduled a session with you on
                   <strong>' . htmlspecialchars(date('D j M Y, H:i', strtotime($scheduledAt))) . ' UTC</strong>
                   (' . (int)$duration . ' minutes).</p>'
                . ($intakeUrl ? '<p>Before your session, please fill in this short intake form:<br>'
                  . '<a href="' . htmlspecialchars($intakeUrl) . '">' . htmlspecialchars($intakeUrl) . '</a></p>' : '')
                . '<p>You can manage your sessions, view past notes, and reach out via your client portal:</p>'
                . '<p style="margin:20px 0;"><a href="' . htmlspecialchars($portalUrl) . '"
                  style="background:#7c6af7;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;">
                  Open Client Portal</a></p>';

            sendEmail($resolvedEmail, $resolvedName,
                      'Session scheduled — ' . $practName,
                      emailTemplate('Session scheduled', $bodyHtml));
        }

        echo json_encode([
            'ok' => true,
            'booking_id'    => $bookingId,
            'booking_token' => $bookingToken,
        ]);
        exit;
    }

    // ── reschedule ───────────────────────────────────────────────
    if ($action === 'reschedule') {
        $bookingId     = (int)($input['booking_id'] ?? 0);
        $newScheduledAt = trim($input['new_scheduled_at'] ?? '');

        if (!$bookingId || !$newScheduledAt) {
            http_response_code(400);
            echo json_encode(['error' => 'booking_id and new_scheduled_at are required']);
            exit;
        }

        // Validate datetime format
        $newDt = DateTime::createFromFormat('Y-m-d H:i:s', $newScheduledAt, new DateTimeZone('UTC'));
        if (!$newDt) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid datetime format; expected Y-m-d H:i:s UTC']);
            exit;
        }

        // Validate new datetime is in the future
        $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
        if ($newDt <= $nowUtc) {
            http_response_code(400);
            echo json_encode(['error' => 'new_scheduled_at must be in the future']);
            exit;
        }

        // Verify booking belongs to this practitioner and has an eligible status
        $stmt = $pdo->prepare(
            'SELECT id, guest_email, guest_name, status FROM bookings
             WHERE id = ? AND practitioner_id = ? AND status IN (\'pending\',\'scheduled\') LIMIT 1'
        );
        $stmt->execute([$bookingId, $practId]);
        $booking = $stmt->fetch();
        if (!$booking) {
            http_response_code(404);
            echo json_encode(['error' => 'Booking not found or not eligible for rescheduling']);
            exit;
        }
        phi_decrypt_booking($booking);

        // Update scheduled_at
        $pdo->prepare(
            'UPDATE bookings SET scheduled_at = ? WHERE id = ? AND practitioner_id = ?'
        )->execute([$newDt->format('Y-m-d H:i:s'), $bookingId, $practId]);

        // Send reschedule notification if guest_email is present
        $guestEmail = $booking['guest_email'] ?? '';
        $guestName  = $booking['guest_name']  ?? '';
        if ($guestEmail) {
            $dtDisplay = $newDt->format('l, F j, Y \a\t g:i A T');
            $practNameRow = $pdo->prepare('SELECT display_name FROM practitioners WHERE id = ? LIMIT 1');
            $practNameRow->execute([$practId]);
            $practDisplayName = $practNameRow->fetchColumn() ?: '';

            send_template_email($pdo, 'booking_rescheduled', 'Session Rescheduled', [
                'client_name'       => $guestName,
                'practitioner_name' => $practDisplayName,
                'new_date'          => $dtDisplay,
            ], $guestEmail, $guestName);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── assign_room ──────────────────────────────────────────────
    if ($action === 'assign_room') {
        $bookingId = (int)($input['booking_id'] ?? 0);
        $roomId    = (int)($input['room_id'] ?? 0);

        // Verify booking ownership
        $stmt = $pdo->prepare(
            'SELECT b.*, r.room_token FROM bookings b
             LEFT JOIN rooms r ON r.id = ?
             WHERE b.id = ? AND b.practitioner_id = ? LIMIT 1'
        );
        $stmt->execute([$roomId, $bookingId, $practId]);
        $booking = $stmt->fetch();
        if (!$booking) {
            http_response_code(404);
            echo json_encode(['error' => 'Booking not found']);
            exit;
        }
        phi_decrypt_booking($booking);

        // Verify room ownership
        $rStmt = $pdo->prepare('SELECT id, room_token, name FROM rooms WHERE id = ? AND practitioner_id = ? LIMIT 1');
        $rStmt->execute([$roomId, $practId]);
        $room = $rStmt->fetch();
        if (!$room) {
            http_response_code(403);
            echo json_encode(['error' => 'Room not found or not yours']);
            exit;
        }

        $pdo->prepare(
            'UPDATE bookings SET room_id = ?, status = \'scheduled\' WHERE id = ?'
        )->execute([$roomId, $bookingId]);

        // Send "session scheduled" email to client
        $guestEmail = $booking['guest_email'] ?? '';
        $guestName  = $booking['guest_name']  ?? '';
        if ($guestEmail) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl  = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $joinUrl  = $baseUrl . '/index.php?token=' . urlencode($room['room_token'])
                      . '&booking=' . urlencode($booking['booking_token']);

            $dt = new DateTime($booking['scheduled_at'], new DateTimeZone('UTC'));
            $dtDisplay = $dt->format('l, F j, Y \a\t g:i A T');

            $practNameRow = $pdo->prepare('SELECT display_name FROM practitioners WHERE id = ? LIMIT 1');
            $practNameRow->execute([$practId]);
            $practDisplayName = $practNameRow->fetchColumn() ?: '';

            $icsData = ics_event(
                $booking['scheduled_at'],
                60,
                'Session with ' . $practDisplayName,
                'Join your session at: ' . $joinUrl,
                $joinUrl,
                $booking['booking_token'] ?? bin2hex(random_bytes(16))
            );
            send_template_email($pdo, 'booking_confirmed', 'Session Confirmed', [
                'client_name'       => $guestName,
                'practitioner_name' => $practDisplayName,
                'scheduled_date'    => $dtDisplay,
                'room_name'         => $room['name'],
                'join_url'          => $joinUrl,
                'privacy_url'       => $baseUrl . '/privacy.php',
            ], $guestEmail, $guestName, [
                ['filename' => 'session.ics', 'mime' => 'text/calendar; method=REQUEST', 'data' => $icsData],
            ]);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── cancel ───────────────────────────────────────────────────
    if ($action === 'cancel') {
        $bookingId = (int)($input['booking_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT id, guest_email, guest_name FROM bookings WHERE id = ? AND practitioner_id = ? LIMIT 1'
        );
        $stmt->execute([$bookingId, $practId]);
        $booking = $stmt->fetch();
        if (!$booking) {
            http_response_code(404);
            echo json_encode(['error' => 'Booking not found']);
            exit;
        }
        phi_decrypt_booking($booking);
        $pdo->prepare('UPDATE bookings SET status = \'cancelled\' WHERE id = ?')->execute([$bookingId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── complete ──────────────────────────────────────────────────
    if ($action === 'complete') {
        $bookingId = (int)($input['booking_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT id FROM bookings WHERE id = ? AND practitioner_id = ? LIMIT 1'
        );
        $stmt->execute([$bookingId, $practId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Booking not found']);
            exit;
        }
        $pdo->prepare('UPDATE bookings SET status = \'completed\' WHERE id = ?')->execute([$bookingId]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action or method']);
