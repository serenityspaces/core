<?php
/**
 * Serenity Spaces — Client Portal API
 *
 * All actions require an authenticated end_user session.
 *
 * GET  action=upcoming_sessions   — bookings within next 7 days
 * GET  action=past_sessions       — completed/ended sessions
 * POST action=rate_session        — submit or update a rating
 * POST action=update_profile          — update display_name, email, avatar
 * POST action=update_sensitive_fields — update gender, sexuality, DOB, location (Art. 16)
 * GET  action=consent_status          — returns active consent count for current user
 * POST action=withdraw_consent        — withdraw special category consent (Art. 7(3))
 * POST action=request_cancel          — request cancellation of a booking
 * POST action=request_reschedule      — request reschedule (emails practitioner)
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_once __DIR__ . '/../includes/phi_crypto.php';

header('Content-Type: application/json');

if (empty($_SESSION['end_user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$userId = (int)$_SESSION['end_user_id'];
$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$ct     = $_SERVER['CONTENT_TYPE'] ?? '';
$input  = ($method === 'POST' && str_contains($ct, 'application/json'))
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : ($method === 'POST' ? $_POST : $_GET);
$action = $input['action'] ?? '';

// ── Upcoming sessions ──────────────────────────────────────────
if ($method === 'GET' && $action === 'upcoming_sessions') {
    $stmt = $pdo->prepare(
        'SELECT b.id, b.practitioner_id, b.scheduled_at, b.status, b.booking_token, b.room_id, b.participant_id,
                r.room_token, r.name AS room_name, r.background_path,
                p.display_name AS practitioner_name, p.avatar_path AS practitioner_avatar
         FROM bookings b
         LEFT JOIN rooms r ON r.id = b.room_id
         LEFT JOIN practitioners p ON p.id = b.practitioner_id
         WHERE b.end_user_id = ? AND b.status IN (\'pending\',\'scheduled\')
           AND b.scheduled_at >= UTC_TIMESTAMP() - INTERVAL 2 HOUR
         ORDER BY b.scheduled_at ASC
         LIMIT 10'
    );
    $stmt->execute([$userId]);
    $bookings = $stmt->fetchAll();

    $now = new DateTime('now', new DateTimeZone('UTC'));
    foreach ($bookings as &$b) {
        // joinable: has a room assigned and within 2 hours of scheduled time
        $canJoin = false;
        if ($b['room_token'] && $b['booking_token']) {
            $scheduled = new DateTime($b['scheduled_at'], new DateTimeZone('UTC'));
            $diff = $now->diff($scheduled);
            $minutesUntil = ($scheduled > $now)
                ? ($diff->h * 60 + $diff->i)
                : -($diff->h * 60 + $diff->i);
            $canJoin = ($minutesUntil <= 120 && $minutesUntil >= -60);
        }
        $b['can_join'] = $canJoin;
        $b['join_url'] = ($b['room_token'] && $b['booking_token'])
            ? '/index.php?token=' . urlencode($b['room_token']) . '&booking=' . urlencode($b['booking_token'])
            : null;
    }
    unset($b);

    echo json_encode($bookings);
    exit;
}

// ── Past sessions ──────────────────────────────────────────────
if ($method === 'GET' && $action === 'past_sessions') {
    $page  = max(1, (int)($input['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $stmt = $pdo->prepare(
        'SELECT b.id AS booking_id, b.booking_token, s.id AS session_id, s.started_at, s.ended_at,
                sm.practitioner_name, sm.room_bg_path,
                r.name AS room_name,
                p.display_name AS practitioner_name_live,
                sr.rating, sr.comment AS rating_comment,
                bp.id AS payment_id, bp.amount_due, bp.amount_paid, bp.currency,
                bp.status AS payment_status, bp.gateway
         FROM bookings b
         JOIN sessions s ON s.room_id = b.room_id
           AND s.started_at BETWEEN b.scheduled_at - INTERVAL 2 HOUR AND b.scheduled_at + INTERVAL 4 HOUR
         LEFT JOIN session_metadata sm ON sm.session_id = s.id
         LEFT JOIN rooms r ON r.id = s.room_id
         LEFT JOIN practitioners p ON p.id = r.practitioner_id
         LEFT JOIN session_ratings sr ON sr.session_id = s.id AND sr.end_user_id = ?
         LEFT JOIN booking_payments bp ON bp.booking_id = b.id
         WHERE b.end_user_id = ?
           AND s.ended_at IS NOT NULL
         ORDER BY s.started_at DESC
         LIMIT ? OFFSET ?'
    );
    $stmt->execute([$userId, $userId, $limit, $offset]);
    $sessions = $stmt->fetchAll();

    // Compute human-readable durations
    foreach ($sessions as &$sess) {
        if ($sess['started_at'] && $sess['ended_at']) {
            $start = new DateTime($sess['started_at']);
            $end   = new DateTime($sess['ended_at']);
            $diff  = $start->diff($end);
            $sess['duration'] = ($diff->h > 0 ? $diff->h . 'h ' : '') . $diff->i . 'm';
        } else {
            $sess['duration'] = '';
        }
        // Use snapshot name if available
        $sess['practitioner_name'] = $sess['practitioner_name'] ?: $sess['practitioner_name_live'] ?: 'Your Practitioner';
        unset($sess['practitioner_name_live']);
    }
    unset($sess);

    echo json_encode($sessions);
    exit;
}

// ── Rate session ───────────────────────────────────────────────
if ($method === 'POST' && $action === 'rate_session') {
    $sessionId = (int)($input['session_id'] ?? 0);
    $rating    = (int)($input['rating'] ?? 0);
    $comment   = trim($input['comment'] ?? '');

    if ($sessionId <= 0 || $rating < 1 || $rating > 5) {
        http_response_code(400);
        echo json_encode(['error' => 'session_id and rating (1-5) are required']);
        exit;
    }

    // Verify this user was in that session (via booking)
    $stmt = $pdo->prepare(
        'SELECT b.id FROM bookings b
         JOIN sessions s ON s.room_id = b.room_id
           AND s.started_at BETWEEN b.scheduled_at - INTERVAL 2 HOUR AND b.scheduled_at + INTERVAL 4 HOUR
         WHERE b.end_user_id = ? AND s.id = ? AND s.ended_at IS NOT NULL LIMIT 1'
    );
    $stmt->execute([$userId, $sessionId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Session not found or not accessible']);
        exit;
    }

    $pdo->prepare(
        'INSERT INTO session_ratings (session_id, end_user_id, rating, comment)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)'
    )->execute([$sessionId, $userId, $rating, $comment ?: null]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── Update profile ─────────────────────────────────────────────
if ($method === 'POST' && $action === 'update_profile') {
    $displayName = trim($input['display_name'] ?? '');
    $email       = strtolower(trim($input['email'] ?? ''));

    if ($displayName !== '') {
        $pdo->prepare('UPDATE end_users SET display_name = ? WHERE id = ?')
            ->execute([$displayName, $userId]);
        $_SESSION['end_user_name'] = $displayName;
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Check uniqueness
        $stmt = $pdo->prepare('SELECT id FROM end_users WHERE email = ? AND id != ? LIMIT 1');
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Email already in use']);
            exit;
        }
        $pdo->prepare('UPDATE end_users SET email = ? WHERE id = ?')->execute([$email, $userId]);
    }

    // Handle avatar upload
    if (!empty($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $file    = $_FILES['avatar'];
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        $finfo   = new finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->file($file['tmp_name']);
        if (in_array($mime, $allowed, true) && $file['size'] <= 5 * 1024 * 1024) {
            $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
            $ext    = $extMap[$mime] ?? 'jpg';
            $fname  = bin2hex(random_bytes(12)) . '.' . $ext;
            move_uploaded_file($file['tmp_name'], __DIR__ . '/../assets/uploads/' . $fname);
            $avatarPath = '/assets/uploads/' . $fname;
            $pdo->prepare('UPDATE end_users SET avatar_path = ? WHERE id = ?')
                ->execute([$avatarPath, $userId]);
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── Update sensitive fields (Art. 16) ─────────────────────────
if ($method === 'POST' && $action === 'update_sensitive_fields') {
    $allowed_genders     = ['male','female','trans_woman','trans_man','non_binary','genderfluid','prefer_not_to_say',''];
    $allowed_sexualities = ['straight','gay','lesbian','bisexual','pansexual','asexual','queer','prefer_not_to_say',''];

    $gender    = trim($input['gender']        ?? '');
    $sexuality = trim($input['sexuality']     ?? '');
    $dob       = trim($input['date_of_birth'] ?? '');
    $location  = trim($input['location']      ?? '');

    if ($gender !== '' && !in_array($gender, $allowed_genders, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid gender value']);
        exit;
    }
    if ($sexuality !== '' && !in_array($sexuality, $allowed_sexualities, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid sexuality value']);
        exit;
    }
    if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format']);
        exit;
    }
    if ($dob !== '') {
        $parsed = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$parsed || $parsed->format('Y-m-d') !== $dob || (int)$parsed->format('Y') < 1900 || $parsed > new DateTime()) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid date of birth']);
            exit;
        }
    }
    if (strlen($location) > 10) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid location']);
        exit;
    }

    $pdo->prepare(
        'UPDATE end_users SET gender = ?, sexuality = ?, date_of_birth = ?, location = ? WHERE id = ?'
    )->execute([
        $gender    !== '' ? phi_encrypt($gender)    : null,
        $sexuality !== '' ? phi_encrypt($sexuality) : null,
        $dob       !== '' ? $dob : null,
        $location  !== '' ? $location : null,
        $userId,
    ]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── Consent status (Art. 7(3)) ────────────────────────────────
if ($method === 'GET' && $action === 'consent_status') {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS cnt, MIN(consented_at) AS first_consent
         FROM consent_records
         WHERE end_user_id = ? AND withdrawn_at IS NULL'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    echo json_encode([
        'has_active'    => (int)$row['cnt'] > 0,
        'count'         => (int)$row['cnt'],
        'first_consent' => $row['first_consent'],
    ]);
    exit;
}

// ── Withdraw consent (Art. 7(3)) ──────────────────────────────
if ($method === 'POST' && $action === 'withdraw_consent') {
    // 1. Mark all active consent_records as withdrawn
    $pdo->prepare(
        'UPDATE consent_records SET withdrawn_at = NOW() WHERE end_user_id = ? AND withdrawn_at IS NULL'
    )->execute([$userId]);

    // 2. Cease processing — null the special category fields that were collected under that consent
    $pdo->prepare(
        'UPDATE end_users SET gender = NULL, sexuality = NULL, date_of_birth = NULL WHERE id = ?'
    )->execute([$userId]);

    // 3. Audit log
    require_once __DIR__ . '/../db/audit.php';
    audit_log($pdo, 'consent.withdraw', ['participant_id' => $userId]);

    // 4. Notify all practitioners who have bookings with this user
    $userStmt = $pdo->prepare('SELECT display_name FROM end_users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $clientName = ($userStmt->fetchColumn() ?: 'A client');

    $practStmt = $pdo->prepare(
        'SELECT DISTINCT p.id, p.email, p.display_name
         FROM bookings b
         JOIN practitioners p ON p.id = b.practitioner_id
         WHERE b.end_user_id = ? AND p.email IS NOT NULL AND p.email != \'\''
    );
    $practStmt->execute([$userId]);

    foreach ($practStmt->fetchAll() as $pract) {
        send_template_email($pdo, 'consent_withdrawal', 'Client Consent Withdrawn — Action Required', [
            'practitioner_name' => $pract['display_name'] ?: 'Practitioner',
            'client_name'       => $clientName,
            'withdrawn_at'      => gmdate('Y-m-d H:i') . ' UTC',
        ], $pract['email'], $pract['display_name']);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── Request cancellation / reschedule ─────────────────────────
if ($method === 'POST' && ($action === 'request_cancel' || $action === 'request_reschedule')) {
    $bookingId = (int)($input['booking_id'] ?? 0);
    $message   = trim($input['message'] ?? '');

    $stmt = $pdo->prepare(
        'SELECT b.*, p.display_name AS pract_name
         FROM bookings b JOIN practitioners p ON p.id = b.practitioner_id
         WHERE b.id = ? AND b.end_user_id = ? AND b.status IN (\'pending\',\'scheduled\') LIMIT 1'
    );
    $stmt->execute([$bookingId, $userId]);
    $booking = $stmt->fetch();
    if (!$booking) {
        http_response_code(404);
        echo json_encode(['error' => 'Booking not found']);
        exit;
    }

    // Get practitioner email
    $pStmt = $pdo->prepare('SELECT email FROM practitioners WHERE id = ? LIMIT 1');
    $pStmt->execute([$booking['practitioner_id']]);
    $practEmail = $pStmt->fetchColumn();

    $userStmt = $pdo->prepare('SELECT display_name, email FROM end_users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    $type    = $action === 'request_cancel' ? 'Cancellation' : 'Reschedule';
    $dt      = new DateTime($booking['scheduled_at'], new DateTimeZone('UTC'));
    $dtStr   = $dt->format('l, F j, Y \a\t g:i A T');

    if ($practEmail) {
        $clientMsgBlock = $message
            ? '<p><em>Client note: "' . htmlspecialchars($message) . '"</em></p>'
            : '';
        send_template_email($pdo, 'client_action_request', "Client {$type} Request", [
            'practitioner_name'    => $booking['pract_name'],
            'client_name'          => $user['display_name'],
            'action_type'          => strtolower($type),
            'scheduled_date'       => $dtStr,
            'client_message_block' => $clientMsgBlock,
        ], $practEmail, $booking['pract_name']);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── Get profile ────────────────────────────────────────────────
if ($method === 'GET' && $action === 'profile') {
    $stmt = $pdo->prepare(
        'SELECT id, email, display_name, avatar_path, avatar_preset, gender, sexuality, date_of_birth, location, created_at
         FROM end_users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }
    phi_decrypt_end_user($user);
    echo json_encode($user);
    exit;
}

// ── GDPR Art. 15 + Art. 20 — Export my data ───────────────────
if ($method === 'GET' && $action === 'export_my_data') {
    $export = [];

    // 1. Profile
    $stmt = $pdo->prepare(
        'SELECT id, email, display_name, avatar_path, avatar_preset, gender, sexuality, date_of_birth, location, created_at
         FROM end_users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
    if ($profile) {
        phi_decrypt_end_user($profile);
        $export['profile'] = $profile;
    }

    // 2. Booking history (decrypt PII fields)
    $stmt = $pdo->prepare(
        'SELECT b.id, b.practitioner_id, b.scheduled_at, b.status, b.booking_token,
                b.guest_name, b.guest_email, b.guest_avatar,
                p.display_name AS practitioner_name
         FROM bookings b
         LEFT JOIN practitioners p ON p.id = b.practitioner_id
         WHERE b.end_user_id = ?
         ORDER BY b.scheduled_at DESC'
    );
    $stmt->execute([$userId]);
    $bookings = $stmt->fetchAll();
    phi_decrypt_bookings($bookings);
    $export['bookings'] = $bookings;

    // 3. Consent records
    $stmt = $pdo->prepare(
        'SELECT consent_type, consent_version, consent_text, ip_address, created_at
         FROM consent_records
         WHERE end_user_id = ?
         ORDER BY created_at ASC'
    );
    $stmt->execute([$userId]);
    $export['consent_records'] = $stmt->fetchAll();

    // 4. Session participations
    $stmt = $pdo->prepare(
        'SELECT pa.id, pa.session_id, pa.display_name, pa.joined_at, pa.left_at,
                s.started_at, s.ended_at,
                r.name AS room_name,
                pr.display_name AS practitioner_name
         FROM participants pa
         JOIN sessions s ON s.id = pa.session_id
         LEFT JOIN rooms r ON r.id = s.room_id
         LEFT JOIN practitioners pr ON pr.id = r.practitioner_id
         WHERE pa.end_user_id = ?
         ORDER BY s.started_at DESC'
    );
    $stmt->execute([$userId]);
    $export['sessions'] = $stmt->fetchAll();

    // 5. Private reflection notes (client-only; encrypted at rest)
    $stmt = $pdo->prepare(
        'SELECT cn.booking_id, cn.note_text, cn.updated_at
         FROM client_notes cn
         WHERE cn.end_user_id = ?
         ORDER BY cn.updated_at DESC'
    );
    $stmt->execute([$userId]);
    $notes = $stmt->fetchAll();
    foreach ($notes as &$n) {
        $n['note_text'] = phi_decrypt((string)($n['note_text'] ?? ''));
    }
    unset($n);
    $export['private_notes'] = $notes;

    // 6. Inbox messages (between-session secure messages)
    $stmt = $pdo->prepare(
        'SELECT im.id, im.practitioner_id, im.sender_type, im.body, im.message_type,
                im.file_name, im.mime_type, im.read_at, im.created_at,
                pr.display_name AS practitioner_name
         FROM inbox_messages im
         LEFT JOIN practitioners pr ON pr.id = im.practitioner_id
         WHERE im.end_user_id = ?
         ORDER BY im.created_at ASC'
    );
    $stmt->execute([$userId]);
    $export['inbox_messages'] = $stmt->fetchAll();

    // 7. Session ratings
    $stmt = $pdo->prepare(
        'SELECT sr.session_id, sr.rating, sr.comment, sr.created_at
         FROM session_ratings sr
         WHERE sr.end_user_id = ?
         ORDER BY sr.created_at ASC'
    );
    $stmt->execute([$userId]);
    $export['session_ratings'] = $stmt->fetchAll();

    $export['exported_at']   = gmdate('Y-m-d\TH:i:s\Z');
    $export['platform']      = 'Serenity Spaces';
    $export['gdpr_basis']    = 'GDPR Art. 15 (right of access) and Art. 20 (right to data portability)';

    $filename = 'serenityspaces-my-data-' . date('Y-m-d') . '.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
