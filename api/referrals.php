<?php
/**
 * Practitioner-to-practitioner referrals.
 *
 * Actions:
 *   GET  list           — list incoming + outgoing referrals for this practitioner
 *   GET  detail         — full detail for one referral (must be sender or recipient)
 *   GET  search_recipients — find another practitioner to send to (by email or display name)
 *   POST send           — practitioner A sends referral to practitioner B about a client
 *   POST accept         — practitioner B accepts; permitted client data is copied across
 *   POST respond        — practitioner B sends a reply message (no data transfer)
 *   POST reject         — practitioner B declines (optional reason)
 *   POST withdraw       — practitioner A withdraws a still-pending referral
 *
 * Auth: practitioner session only. CSRF on every POST.
 */

session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/phi_crypto.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_once __DIR__ . '/../db/audit.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Practitioners only']);
    exit;
}

$pdo     = getDB();
$practId = (int)$_SESSION['practitioner_id'];
$method  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? $_POST['action'] ?? '';

$input = $_POST;
if ($method === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}
if ($method === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_GET['csrf'] ?? '';
    if (!validate_csrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF validation failed']);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════
// LIST — incoming + outgoing
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'list' && $method === 'GET') {
    $box = $_GET['box'] ?? 'all';   // 'in' | 'out' | 'all'

    $rows = ['incoming' => [], 'outgoing' => []];

    if ($box === 'in' || $box === 'all') {
        $stmt = $pdo->prepare(
            'SELECT r.id, r.status, r.sent_at, r.responded_at,
                    r.share_basic_info, r.share_intake_history, r.share_session_notes,
                    r.share_goals, r.share_themes,
                    r.end_user_id, r.guest_name, r.guest_email,
                    pf.display_name AS from_name, pf.id AS from_id
             FROM practitioner_referrals r
             JOIN practitioners pf ON pf.id = r.from_practitioner_id
             WHERE r.to_practitioner_id = ?
             ORDER BY r.sent_at DESC LIMIT 200'
        );
        $stmt->execute([$practId]);
        $rows['incoming'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($box === 'out' || $box === 'all') {
        $stmt = $pdo->prepare(
            'SELECT r.id, r.status, r.sent_at, r.responded_at,
                    r.share_basic_info, r.share_intake_history, r.share_session_notes,
                    r.share_goals, r.share_themes,
                    r.end_user_id, r.guest_name, r.guest_email,
                    pt.display_name AS to_name, pt.id AS to_id
             FROM practitioner_referrals r
             JOIN practitioners pt ON pt.id = r.to_practitioner_id
             WHERE r.from_practitioner_id = ?
             ORDER BY r.sent_at DESC LIMIT 200'
        );
        $stmt->execute([$practId]);
        $rows['outgoing'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Counts for the badge
    $cntStmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN to_practitioner_id = ?   AND status = 'pending' THEN 1 ELSE 0 END) AS pending_in,
            SUM(CASE WHEN from_practitioner_id = ? AND status = 'pending' THEN 1 ELSE 0 END) AS pending_out
         FROM practitioner_referrals
         WHERE to_practitioner_id = ? OR from_practitioner_id = ?"
    );
    $cntStmt->execute([$practId, $practId, $practId, $practId]);
    $counts = $cntStmt->fetch(PDO::FETCH_ASSOC) ?: ['pending_in'=>0,'pending_out'=>0];

    echo json_encode([
        'ok'         => true,
        'incoming'   => $rows['incoming'],
        'outgoing'   => $rows['outgoing'],
        'counts'     => $counts,
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// DETAIL — one referral, sender or recipient view
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'detail' && $method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'id required']);
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT r.*, pf.display_name AS from_name, pt.display_name AS to_name
         FROM practitioner_referrals r
         JOIN practitioners pf ON pf.id = r.from_practitioner_id
         JOIN practitioners pt ON pt.id = r.to_practitioner_id
         WHERE r.id = ? AND (r.from_practitioner_id = ? OR r.to_practitioner_id = ?)
         LIMIT 1'
    );
    $stmt->execute([$id, $practId, $practId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    // Decrypt the notes / response (only the parties to this referral can decrypt)
    $row['referral_notes']   = $row['referral_notes_enc']   ? phi_decrypt($row['referral_notes_enc'],   'referral_notes:'   . $id) : '';
    $row['response_message'] = $row['response_message_enc'] ? phi_decrypt($row['response_message_enc'], 'referral_response:' . $id) : '';
    unset($row['referral_notes_enc'], $row['response_message_enc']);
    if (!empty($row['accepted_snapshot'])) {
        $row['accepted_snapshot'] = json_decode($row['accepted_snapshot'], true) ?: null;
    }

    // What role does the requester play?
    $row['my_role'] = ((int)$row['from_practitioner_id'] === $practId) ? 'sender' : 'recipient';

    echo json_encode(['ok' => true, 'referral' => $row]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// SEARCH_RECIPIENTS — find a target practitioner to send to
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'search_recipients' && $method === 'GET') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '' || mb_strlen($q) < 2) {
        echo json_encode(['ok' => true, 'results' => []]);
        exit;
    }
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare(
        "SELECT id, display_name, email, avatar_path, service_type
         FROM practitioners
         WHERE id != ?
           AND display_name IS NOT NULL
           AND (account_status IS NULL OR account_status = 'active')
           AND (display_name LIKE ? OR email LIKE ?)
         ORDER BY display_name
         LIMIT 12"
    );
    $stmt->execute([$practId, $like, $like]);
    echo json_encode(['ok' => true, 'results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// SEND — practitioner A creates a referral for client X to practitioner B
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'send' && $method === 'POST') {
    $toPractId = (int)($input['to_practitioner_id'] ?? 0);
    if ($toPractId <= 0 || $toPractId === $practId) {
        echo json_encode(['ok' => false, 'error' => 'Pick a different practitioner']);
        exit;
    }

    // Verify recipient exists and is active
    $rcv = $pdo->prepare("SELECT id, display_name, email FROM practitioners WHERE id = ? AND (account_status IS NULL OR account_status = 'active') LIMIT 1");
    $rcv->execute([$toPractId]);
    $recipient = $rcv->fetch(PDO::FETCH_ASSOC);
    if (!$recipient) {
        echo json_encode(['ok' => false, 'error' => 'Recipient not found']);
        exit;
    }

    // Resolve the client — either an existing end_user we've worked with, or
    // a guest by email. If end_user_id is supplied, we verify a prior booking
    // relationship exists (anti-abuse — same as create_for_client).
    $endUserId = (int)($input['end_user_id'] ?? 0);
    $guestName = trim((string)($input['guest_name']  ?? ''));
    $guestEmail= trim((string)($input['guest_email'] ?? ''));

    if ($endUserId > 0) {
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
            echo json_encode(['ok' => false, 'error' => 'No prior relationship with this client']);
            exit;
        }
        $guestName  = $guestName  ?: ($eu['display_name'] ?? '');
        $guestEmail = $guestEmail ?: ($eu['email'] ?? '');
    } elseif ($guestName === '' || $guestEmail === '' || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'Need either end_user_id or guest_name + valid guest_email']);
        exit;
    }

    $notes = trim((string)($input['referral_notes'] ?? ''));
    if (mb_strlen($notes) > 8000) $notes = mb_substr($notes, 0, 8000);

    // Permission flags — sender chooses what's shareable on Accept
    $perm = function($k) use ($input) { return !empty($input[$k]) ? 1 : 0; };
    $shareBasic   = $perm('share_basic_info')     ?: 1;   // always at least basic
    $shareIntake  = $perm('share_intake_history');
    $shareNotes   = $perm('share_session_notes');
    $shareGoals   = $perm('share_goals');
    $shareThemes  = $perm('share_themes');

    // INSERT placeholder, then encrypt with the row id as part of the AAD
    $ins = $pdo->prepare(
        'INSERT INTO practitioner_referrals
            (from_practitioner_id, to_practitioner_id, end_user_id,
             guest_name, guest_email, referral_notes_enc,
             share_basic_info, share_intake_history, share_session_notes,
             share_goals, share_themes, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")'
    );
    $ins->execute([
        $practId, $toPractId, $endUserId ?: null,
        $guestName, $guestEmail, '',
        $shareBasic, $shareIntake, $shareNotes, $shareGoals, $shareThemes,
    ]);
    $referralId = (int)$pdo->lastInsertId();

    if ($notes !== '') {
        $upd = $pdo->prepare('UPDATE practitioner_referrals SET referral_notes_enc = ? WHERE id = ?');
        $upd->execute([phi_encrypt($notes, 'referral_notes:' . $referralId), $referralId]);
    }

    audit_log($pdo, 'referral.send', [
        'practitioner_id' => $practId,
        'entity_type'     => 'practitioner_referral',
        'entity_id'       => $referralId,
        'context'         => ['to_practitioner_id' => $toPractId, 'end_user_id' => $endUserId],
    ]);

    // Email the recipient practitioner so they know to check their inbox
    if (!empty($recipient['email'])) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl  = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $inboxUrl = $baseUrl . '/dashboard.php#referrals';

        $senderNameStmt = $pdo->prepare('SELECT display_name FROM practitioners WHERE id = ? LIMIT 1');
        $senderNameStmt->execute([$practId]);
        $senderName = $senderNameStmt->fetchColumn() ?: 'A practitioner';

        $bodyHtml = '
            <p>Hi ' . htmlspecialchars($recipient['display_name'] ?: 'there') . ',</p>
            <p><strong>' . htmlspecialchars($senderName) . '</strong> has sent you a client referral on
               <strong>' . htmlspecialchars(getSetting('app_name', 'Serenity Spaces')) . '</strong>.</p>
            <p>The referral is pending your review. You can accept, send a reply, or decline:</p>
            <p style="margin:24px 0;">
              <a href="' . htmlspecialchars($inboxUrl) . '"
                 style="background:#7c6af7;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;">
                Open Referrals
              </a>
            </p>
            <p style="color:rgba(255,255,255,0.5);font-size:13px;">
              No client data is shared until you accept.
            </p>';

        sendEmail(
            $recipient['email'],
            $recipient['display_name'] ?: '',
            'New referral — ' . $senderName,
            emailTemplate('Referral notification', $bodyHtml)
        );
    }

    echo json_encode(['ok' => true, 'id' => $referralId]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// ACCEPT — recipient accepts; permitted data flows over as a snapshot
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'accept' && $method === 'POST') {
    $id = (int)($input['id'] ?? 0);
    $r  = referral_load_for_recipient($pdo, $id, $practId);
    if (!$r) { echo json_encode(['ok' => false, 'error' => 'Not found or not yours']); exit; }
    if ($r['status'] !== 'pending') {
        echo json_encode(['ok' => false, 'error' => 'Referral already ' . $r['status']]);
        exit;
    }

    // Build the snapshot of what the sender authorised. We assemble it server
    // side so the receiving practitioner gets a stable record of what they
    // actually received — not a live link to the sender's data.
    $snapshot = [];
    if ((int)$r['share_basic_info'] === 1) {
        $snapshot['basic'] = [
            'name'  => $r['guest_name'],
            'email' => $r['guest_email'],
        ];
        if ($r['end_user_id']) {
            $eu = $pdo->prepare('SELECT email, display_name FROM end_users WHERE id = ? LIMIT 1');
            $eu->execute([$r['end_user_id']]);
            if ($euRow = $eu->fetch(PDO::FETCH_ASSOC)) {
                $snapshot['basic']['name']  = $snapshot['basic']['name']  ?: $euRow['display_name'];
                $snapshot['basic']['email'] = $snapshot['basic']['email'] ?: $euRow['email'];
            }
        }
    }
    if ((int)$r['share_intake_history'] === 1 && $r['end_user_id']) {
        $stmt = $pdo->prepare(
            'SELECT ir.responses, ir.submitted_at, f.name AS form_name, f.fields
             FROM intake_responses ir
             JOIN bookings     b ON b.id = ir.booking_id
             JOIN intake_forms f ON f.id = ir.form_id
             WHERE b.practitioner_id = ? AND b.end_user_id = ?
             ORDER BY ir.submitted_at ASC LIMIT 50'
        );
        $stmt->execute([(int)$r['from_practitioner_id'], (int)$r['end_user_id']]);
        $snapshot['intake_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ((int)$r['share_session_notes'] === 1 && $r['end_user_id']) {
        $stmt = $pdo->prepare(
            'SELECT s.id AS session_id, s.started_at, pn.note_content
             FROM sessions s
             JOIN rooms r2 ON r2.id = s.room_id
             JOIN bookings b ON b.room_id = r2.id AND b.end_user_id = ?
             LEFT JOIN participants p ON p.session_id = s.id
             LEFT JOIN practitioner_notes pn ON pn.session_id = s.id AND pn.participant_id = p.id
             WHERE r2.practitioner_id = ? AND pn.note_content IS NOT NULL AND pn.note_content <> ""
             ORDER BY s.started_at DESC LIMIT 50'
        );
        $stmt->execute([(int)$r['end_user_id'], (int)$r['from_practitioner_id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Decrypt note bodies for the snapshot
        foreach ($rows as &$nr) {
            if (!empty($nr['note_content'])) {
                $nr['note_content'] = phi_decrypt($nr['note_content'], 'notes:' . $nr['session_id']);
            }
        }
        unset($nr);
        $snapshot['session_notes'] = $rows;
    }
    if ((int)$r['share_goals'] === 1 && $r['end_user_id']) {
        $stmt = $pdo->prepare(
            'SELECT g.id, g.title, g.description, g.target_date, g.status, g.created_at
             FROM client_goals g
             WHERE g.practitioner_id = ? AND g.end_user_id = ?
             ORDER BY g.created_at DESC LIMIT 50'
        );
        $stmt->execute([(int)$r['from_practitioner_id'], (int)$r['end_user_id']]);
        $snapshot['goals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ((int)$r['share_themes'] === 1 && $r['end_user_id']) {
        // Collect concept tags applied to messages or notes for this client's sessions
        $stmt = $pdo->prepare(
            'SELECT DISTINCT ct.name, ct.color
             FROM concept_tags ct
             LEFT JOIN message_concept_tags mct ON mct.tag_id = ct.id
             LEFT JOIN sessions s ON s.id = mct.session_id
             LEFT JOIN bookings b ON b.room_id = s.room_id
             WHERE ct.practitioner_id = ? AND b.end_user_id = ?'
        );
        $stmt->execute([(int)$r['from_practitioner_id'], (int)$r['end_user_id']]);
        $snapshot['themes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $upd = $pdo->prepare(
        "UPDATE practitioner_referrals
         SET status = 'accepted', responded_at = NOW(), accepted_snapshot = ?
         WHERE id = ?"
    );
    $upd->execute([json_encode($snapshot), $id]);

    audit_log($pdo, 'referral.accept', [
        'practitioner_id' => $practId,
        'entity_type'     => 'practitioner_referral',
        'entity_id'       => $id,
    ]);

    // Notify the client (if email known) so they know a new practitioner has
    // received their referral. This is the privacy safeguard — client at
    // minimum knows another practitioner now has their context.
    $clientEmail = $r['guest_email'];
    $clientName  = $r['guest_name'] ?: 'there';
    if ($r['end_user_id'] && empty($clientEmail)) {
        $eu = $pdo->prepare('SELECT email, display_name FROM end_users WHERE id = ? LIMIT 1');
        $eu->execute([(int)$r['end_user_id']]);
        if ($euRow = $eu->fetch(PDO::FETCH_ASSOC)) {
            $clientEmail = $euRow['email'] ?? $clientEmail;
            $clientName  = $clientName === 'there' ? ($euRow['display_name'] ?? 'there') : $clientName;
        }
    }
    if ($clientEmail) {
        $senderName    = $pdo->query('SELECT display_name FROM practitioners WHERE id = ' . (int)$r['from_practitioner_id'])->fetchColumn() ?: 'your practitioner';
        $recipientName = $pdo->query('SELECT display_name FROM practitioners WHERE id = ' . (int)$r['to_practitioner_id'])->fetchColumn() ?: 'a colleague';

        $bodyHtml = '
            <p>Hi ' . htmlspecialchars($clientName) . ',</p>
            <p>This is a notification that <strong>' . htmlspecialchars($senderName) . '</strong> has referred you
               to <strong>' . htmlspecialchars($recipientName) . '</strong> on
               <strong>' . htmlspecialchars(getSetting('app_name', 'Serenity Spaces')) . '</strong>.</p>
            <p>' . htmlspecialchars($recipientName) . ' has accepted the referral and may reach out to you to set up a session.
               If you have questions or did not expect this referral, please contact your existing practitioner.</p>';

        sendEmail($clientEmail, $clientName,
                  'A practitioner referral has been accepted',
                  emailTemplate('Referral accepted', $bodyHtml));
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// RESPOND — recipient sends a reply message; status → responded, no copy
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'respond' && $method === 'POST') {
    $id = (int)($input['id'] ?? 0);
    $msg = trim((string)($input['message'] ?? ''));
    if ($msg === '') {
        echo json_encode(['ok' => false, 'error' => 'message required']);
        exit;
    }
    $r = referral_load_for_recipient($pdo, $id, $practId);
    if (!$r) { echo json_encode(['ok' => false, 'error' => 'Not found or not yours']); exit; }
    if ($r['status'] !== 'pending') {
        echo json_encode(['ok' => false, 'error' => 'Referral already ' . $r['status']]);
        exit;
    }

    $upd = $pdo->prepare(
        "UPDATE practitioner_referrals
         SET status = 'responded', responded_at = NOW(), response_message_enc = ?
         WHERE id = ?"
    );
    $upd->execute([phi_encrypt($msg, 'referral_response:' . $id), $id]);

    audit_log($pdo, 'referral.respond', [
        'practitioner_id' => $practId,
        'entity_type'     => 'practitioner_referral',
        'entity_id'       => $id,
    ]);

    referral_email_sender_status_change($pdo, $r, 'responded');

    echo json_encode(['ok' => true]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// REJECT — recipient declines (optional reason)
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'reject' && $method === 'POST') {
    $id = (int)($input['id'] ?? 0);
    $msg = trim((string)($input['message'] ?? ''));
    $r = referral_load_for_recipient($pdo, $id, $practId);
    if (!$r) { echo json_encode(['ok' => false, 'error' => 'Not found or not yours']); exit; }
    if ($r['status'] !== 'pending') {
        echo json_encode(['ok' => false, 'error' => 'Referral already ' . $r['status']]);
        exit;
    }

    $args = [$id];
    $sql  = "UPDATE practitioner_referrals SET status = 'rejected', responded_at = NOW()";
    if ($msg !== '') {
        $sql .= ", response_message_enc = ?";
        array_unshift($args, phi_encrypt($msg, 'referral_response:' . $id));
    }
    $sql .= ' WHERE id = ?';
    $pdo->prepare($sql)->execute($args);

    audit_log($pdo, 'referral.reject', [
        'practitioner_id' => $practId,
        'entity_type'     => 'practitioner_referral',
        'entity_id'       => $id,
    ]);

    referral_email_sender_status_change($pdo, $r, 'rejected');

    echo json_encode(['ok' => true]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// WITHDRAW — sender pulls back a still-pending referral
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'withdraw' && $method === 'POST') {
    $id = (int)($input['id'] ?? 0);
    $r  = referral_load_for_sender($pdo, $id, $practId);
    if (!$r) { echo json_encode(['ok' => false, 'error' => 'Not found or not yours']); exit; }
    if ($r['status'] !== 'pending') {
        echo json_encode(['ok' => false, 'error' => 'Cannot withdraw a ' . $r['status'] . ' referral']);
        exit;
    }
    $pdo->prepare("UPDATE practitioner_referrals SET status = 'withdrawn', responded_at = NOW() WHERE id = ?")->execute([$id]);
    audit_log($pdo, 'referral.withdraw', [
        'practitioner_id' => $practId,
        'entity_type'     => 'practitioner_referral',
        'entity_id'       => $id,
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);

// ═══════════════════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════════════════

/**
 * Load a referral row by id, scoped to the recipient practitioner. Returns
 * null if not found or not theirs.
 */
function referral_load_for_recipient(PDO $pdo, int $id, int $practId): ?array {
    if ($id <= 0) return null;
    $stmt = $pdo->prepare(
        'SELECT * FROM practitioner_referrals WHERE id = ? AND to_practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$id, $practId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Load a referral row by id, scoped to the sender practitioner.
 */
function referral_load_for_sender(PDO $pdo, int $id, int $practId): ?array {
    if ($id <= 0) return null;
    $stmt = $pdo->prepare(
        'SELECT * FROM practitioner_referrals WHERE id = ? AND from_practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$id, $practId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Email the original sender when the recipient responds or rejects, so they
 * know to check their referrals inbox.
 */
function referral_email_sender_status_change(PDO $pdo, array $r, string $newStatus): void {
    $senderRow = $pdo->prepare('SELECT email, display_name FROM practitioners WHERE id = ? LIMIT 1');
    $senderRow->execute([(int)$r['from_practitioner_id']]);
    $sender = $senderRow->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$sender || empty($sender['email'])) return;

    $recipName = $pdo->query('SELECT display_name FROM practitioners WHERE id = ' . (int)$r['to_practitioner_id'])->fetchColumn() ?: 'A colleague';

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl  = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $inboxUrl = $baseUrl . '/dashboard.php#referrals';
    $verb     = $newStatus === 'responded' ? 'responded to' : 'declined';

    $bodyHtml = '
        <p>Hi ' . htmlspecialchars($sender['display_name'] ?: 'there') . ',</p>
        <p><strong>' . htmlspecialchars($recipName) . '</strong> has ' . $verb . ' your referral.</p>
        <p style="margin:24px 0;">
          <a href="' . htmlspecialchars($inboxUrl) . '"
             style="background:#7c6af7;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;">
            Open Referrals
          </a>
        </p>';

    sendEmail($sender['email'], $sender['display_name'] ?: '',
              'Referral ' . $newStatus . ' — ' . $recipName,
              emailTemplate('Referral status update', $bodyHtml));
}
