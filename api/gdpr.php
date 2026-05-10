<?php
/**
 * Serenity Spaces — GDPR Data Deletion API
 *
 * POST action=request  — client submits deletion request (requires client session)
 * POST action=complete — admin marks request complete + deletes data (requires admin session)
 * POST action=cancel   — admin cancels a request (requires admin session)
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/audit.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/email_templates.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = trim($input['action'] ?? '');
$pdo    = getDB();

// ── Client: submit deletion request ───────────────────────────
if ($action === 'request') {
    if (empty($_SESSION['end_user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
    $userId = (int)$_SESSION['end_user_id'];

    // Fetch user details
    $stmt = $pdo->prepare('SELECT id, email, display_name FROM end_users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }

    // Check for an existing pending request
    $stmt = $pdo->prepare("SELECT id FROM gdpr_deletion_requests WHERE end_user_id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$userId]);
    if ($stmt->fetch()) {
        echo json_encode(['ok' => true, 'already_pending' => true,
            'message' => 'You already have a pending data deletion request. We will process it within 30 days.']);
        exit;
    }

    // 30-day deadline (GDPR Article 12(3))
    $deadline = date('Y-m-d H:i:s', strtotime('+30 days'));

    $pdo->prepare(
        'INSERT INTO gdpr_deletion_requests (end_user_id, email, deadline_at) VALUES (?, ?, ?)'
    )->execute([$userId, $user['email'], $deadline]);
    $requestId = $pdo->lastInsertId();

    audit_log($pdo, 'gdpr.deletion_request', [
        'participant_id' => $userId,
        'entity_type'    => 'end_user',
        'entity_id'      => $userId,
    ]);

    // Send acknowledgement email to user
    $deadlineFormatted = date('F j, Y', strtotime($deadline));
    send_template_email($pdo, 'gdpr_request', 'Data Deletion Request Received', [
        'client_name'    => $user['display_name'] ?: 'there',
        'request_id'     => (int)$requestId,
        'submitted_date' => date('F j, Y'),
        'deadline_date'  => $deadlineFormatted,
    ], $user['email'], $user['display_name'] ?: '');

    echo json_encode(['ok' => true, 'request_id' => (int)$requestId, 'deadline' => $deadlineFormatted,
        'message' => 'Your deletion request has been submitted. We will process it by ' . $deadlineFormatted . '.']);
    exit;
}

// ── Admin: complete deletion request ──────────────────────────
if ($action === 'complete') {
    if (empty($_SESSION['practitioner_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Admin session required']);
        exit;
    }
    // Verify admin status
    $stmtA = $pdo->prepare('SELECT is_admin FROM practitioners WHERE id = ? LIMIT 1');
    $stmtA->execute([(int)$_SESSION['practitioner_id']]);
    $adminRow = $stmtA->fetch();
    if (empty($adminRow['is_admin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin only']);
        exit;
    }

    $requestId = (int)($input['request_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM gdpr_deletion_requests WHERE id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
    if (!$req) {
        http_response_code(404);
        echo json_encode(['error' => 'Request not found or already processed']);
        exit;
    }

    $userId = (int)$req['end_user_id'];
    $email  = $req['email'];

    // Delete the user's personal data
    // 1. Fetch user details before any deletion (needed for best-effort anonymisation)
    $stmtU = $pdo->prepare('SELECT avatar_path, display_name FROM end_users WHERE id = ? LIMIT 1');
    $stmtU->execute([$userId]);
    $userRow = $stmtU->fetch();

    // 2. Remove avatar file if uploaded
    if ($userRow && $userRow['avatar_path'] && strpos($userRow['avatar_path'], '/assets/uploads/') === 0) {
        $filePath = __DIR__ . '/..' . $userRow['avatar_path'];
        if (file_exists($filePath)) @unlink($filePath);
    }

    // 3. Sweep voice note and image files from session messages — must run BEFORE
    //    participant anonymisation, while we can still identify the user by display_name.
    //    (participants has no end_user_id FK; display_name match is best-effort.)
    if ($userRow && !empty($userRow['display_name'])) {
        $vnStmt = $pdo->prepare(
            "SELECT m.content FROM messages m
             JOIN participants p ON p.id = m.participant_id
             WHERE p.display_name = ? AND m.message_type IN ('voice_note','file')"
        );
        $vnStmt->execute([$userRow['display_name']]);
        foreach ($vnStmt->fetchAll() as $vnRow) {
            $path = $vnRow['content'];
            if ($path && strpos($path, '/assets/uploads/') === 0) {
                $diskPath = __DIR__ . '/..' . $path;
                if (file_exists($diskPath)) @unlink($diskPath);
            }
        }
    }

    // 4. Sweep voice note and image files from inbox messages before deleting rows.
    $inboxFileStmt = $pdo->prepare(
        "SELECT file_path FROM inbox_messages
         WHERE end_user_id = ? AND file_path IS NOT NULL"
    );
    $inboxFileStmt->execute([$userId]);
    foreach ($inboxFileStmt->fetchAll() as $ifRow) {
        $path = $ifRow['file_path'];
        if ($path && strpos($path, '/assets/uploads/') === 0) {
            $diskPath = __DIR__ . '/..' . $path;
            if (file_exists($diskPath)) @unlink($diskPath);
        }
    }

    // 5. Anonymise session participant records before the account delete
    //    (participants table has no end_user_id FK; match on display_name — best-effort)
    if ($userRow && !empty($userRow['display_name'])) {
        $pdo->prepare(
            "UPDATE participants SET display_name = '[Deleted User]', avatar_path = NULL
             WHERE display_name = ?"
        )->execute([$userRow['display_name']]);
    }

    // 6. Delete session ratings and inbox messages (not covered by FK cascades)
    $pdo->prepare('DELETE FROM session_ratings   WHERE end_user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM inbox_messages    WHERE end_user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM client_notes      WHERE end_user_id = ?')->execute([$userId]);

    // 7. Check for sessions with clinical notes subject to professional retention before deletion.
    //    BACP requires 7 years; HIPAA requires 6 years. Notes are retained anonymised; identity is removed.
    $retentionStmt = $pdo->prepare(
        'SELECT MAX(s.started_at) AS last_session
         FROM practitioner_notes pn
         JOIN sessions s ON s.id = pn.session_id
         JOIN rooms r ON r.id = s.room_id
         JOIN bookings b ON b.room_id = r.id
         WHERE b.end_user_id = ? AND pn.note_content != \'\''
    );
    $retentionStmt->execute([$userId]);
    $retentionRow     = $retentionStmt->fetch();
    $hasRetainedNotes = !empty($retentionRow['last_session']);
    $retentionEndDate = $hasRetainedNotes
        ? date('d F Y', strtotime($retentionRow['last_session'] . ' +7 years'))
        : null;

    // 7b. Delete the end_user record (cascades to bookings, consent_records, etc. via FK)
    $pdo->prepare('DELETE FROM end_users WHERE id = ?')->execute([$userId]);

    // 8. Mark request complete
    $pdo->prepare(
        "UPDATE gdpr_deletion_requests SET status = 'completed', completed_at = NOW(), completed_by = ? WHERE id = ?"
    )->execute([(int)$_SESSION['practitioner_id'], $requestId]);

    audit_log($pdo, 'gdpr.deletion_complete', [
        'practitioner_id' => (int)$_SESSION['practitioner_id'],
        'entity_type'     => 'end_user',
        'entity_id'       => $userId,
    ]);

    // 9. Send confirmation email to the user
    $emailParams = ['request_id' => (int)$requestId];
    if ($hasRetainedNotes) {
        $emailParams['retention_note']     = true;
        $emailParams['retention_end_date'] = $retentionEndDate;
    }
    send_template_email($pdo, 'gdpr_complete', 'Data Deletion Complete', $emailParams, $email, '');

    $response = ['ok' => true];
    if ($hasRetainedNotes) {
        $response['retention_note']     = 'Clinical session notes associated with your account are subject to professional retention obligations (BACP 7 years / HIPAA 6 years). Your personal identity has been removed from those records. The notes will be retained until ' . $retentionEndDate . '.';
        $response['retention_end_date'] = $retentionEndDate;
    }
    echo json_encode($response);
    exit;
}

// ── Admin: cancel request ──────────────────────────────────────
if ($action === 'cancel') {
    if (empty($_SESSION['practitioner_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Admin session required']);
        exit;
    }
    $stmtA = $pdo->prepare('SELECT is_admin FROM practitioners WHERE id = ? LIMIT 1');
    $stmtA->execute([(int)$_SESSION['practitioner_id']]);
    if (empty($stmtA->fetch()['is_admin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin only']);
        exit;
    }

    $requestId = (int)($input['request_id'] ?? 0);
    $pdo->prepare(
        "UPDATE gdpr_deletion_requests SET status = 'cancelled', completed_at = NOW(), completed_by = ? WHERE id = ? AND status = 'pending'"
    )->execute([(int)$_SESSION['practitioner_id'], $requestId]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
