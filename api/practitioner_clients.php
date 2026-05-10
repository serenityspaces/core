<?php
/**
 * Serenity Spaces — Practitioner Clients API
 *
 * GET action=list   [?q=search_term]  — list all clients with session count
 * GET action=detail [?end_user_id=N]  — client profile + session history + notes
 *
 * Clients are end_users who have bookings with this practitioner,
 * OR guests (no account) identified by guest_email on bookings.
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

header('Content-Type: application/json');

if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$practId = (int)$_SESSION['practitioner_id'];
$pdo     = getDB();
$action  = $_GET['action'] ?? '';

// ── List clients ───────────────────────────────────────────────
if ($action === 'list') {
    $q = trim($_GET['q'] ?? '');

    if ($q !== '') {
        // Full-text search across name, email, and session notes
        $like = '%' . $q . '%';

        $stmt = $pdo->prepare(
            'SELECT DISTINCT
                eu.id        AS end_user_id,
                eu.email,
                eu.display_name,
                eu.avatar_path,
                NULL         AS guest_name,
                NULL         AS guest_email,
                (SELECT COUNT(DISTINCT s2.id)
                 FROM sessions s2
                 JOIN rooms r2 ON r2.id = s2.room_id
                 JOIN bookings b2 ON b2.room_id = r2.id AND b2.end_user_id = eu.id
                 WHERE r2.practitioner_id = ? AND s2.ended_at IS NOT NULL) AS session_count
             FROM end_users eu
             JOIN bookings b ON b.end_user_id = eu.id AND b.practitioner_id = ?
             WHERE (eu.display_name LIKE ? OR eu.email LIKE ?)
             UNION
             SELECT DISTINCT
                NULL AS end_user_id,
                b.guest_email AS email,
                NULL AS display_name,
                NULL AS avatar_path,
                b.guest_name,
                b.guest_email,
                (SELECT COUNT(DISTINCT s3.id)
                 FROM sessions s3
                 JOIN rooms r3 ON r3.id = s3.room_id
                 JOIN bookings b3 ON b3.room_id = r3.id AND b3.guest_email = b.guest_email
                 WHERE r3.practitioner_id = ? AND s3.ended_at IS NOT NULL) AS session_count
             FROM bookings b
             WHERE b.practitioner_id = ? AND b.end_user_id IS NULL AND b.guest_email IS NOT NULL
               AND (b.guest_name LIKE ? OR b.guest_email LIKE ?)
             ORDER BY session_count DESC, display_name ASC
             LIMIT 50'
        );
        $stmt->execute([$practId, $practId, $like, $like, $practId, $practId, $like, $like]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT
                eu.id        AS end_user_id,
                eu.email,
                eu.display_name,
                eu.avatar_path,
                NULL         AS guest_name,
                NULL         AS guest_email,
                (SELECT COUNT(DISTINCT s2.id)
                 FROM sessions s2
                 JOIN rooms r2 ON r2.id = s2.room_id
                 JOIN bookings b2 ON b2.room_id = r2.id AND b2.end_user_id = eu.id
                 WHERE r2.practitioner_id = ? AND s2.ended_at IS NOT NULL) AS session_count
             FROM end_users eu
             JOIN bookings b ON b.end_user_id = eu.id AND b.practitioner_id = ?
             UNION
             SELECT DISTINCT
                NULL AS end_user_id,
                b.guest_email AS email,
                NULL AS display_name,
                NULL AS avatar_path,
                b.guest_name,
                b.guest_email,
                (SELECT COUNT(DISTINCT s3.id)
                 FROM sessions s3
                 JOIN rooms r3 ON r3.id = s3.room_id
                 JOIN bookings b3 ON b3.room_id = r3.id AND b3.guest_email = b.guest_email
                 WHERE r3.practitioner_id = ? AND s3.ended_at IS NOT NULL) AS session_count
             FROM bookings b
             WHERE b.practitioner_id = ? AND b.end_user_id IS NULL AND b.guest_email IS NOT NULL
             ORDER BY session_count DESC, display_name ASC
             LIMIT 100'
        );
        $stmt->execute([$practId, $practId, $practId, $practId]);
    }

    echo json_encode($stmt->fetchAll());
    exit;
}

// ── Client detail ──────────────────────────────────────────────
if ($action === 'detail') {
    $endUserId = (int)($_GET['end_user_id'] ?? 0);

    if ($endUserId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'end_user_id required']);
        exit;
    }

    // Verify this client has bookings with this practitioner
    $clientStmt = $pdo->prepare(
        'SELECT eu.id, eu.email, eu.display_name, eu.avatar_path, eu.created_at
         FROM end_users eu
         JOIN bookings b ON b.end_user_id = eu.id AND b.practitioner_id = ?
         WHERE eu.id = ? LIMIT 1'
    );
    $clientStmt->execute([$practId, $endUserId]);
    $client = $clientStmt->fetch();

    if (!$client) {
        http_response_code(403);
        echo json_encode(['error' => 'Client not found or not yours']);
        exit;
    }

    // Fetch all completed sessions for this client with this practitioner
    // including practitioner notes and any rating they gave
    $sessStmt = $pdo->prepare(
        'SELECT DISTINCT
            s.id AS session_id, s.started_at, s.ended_at,
            r.name AS room_name,
            pn.note_content,
            sr.rating, sr.comment AS rating_comment
         FROM sessions s
         JOIN rooms r ON r.id = s.room_id AND r.practitioner_id = ?
         JOIN bookings b ON b.room_id = r.id AND b.end_user_id = ?
           AND s.started_at BETWEEN b.scheduled_at - INTERVAL 2 HOUR AND b.scheduled_at + INTERVAL 4 HOUR
         LEFT JOIN participants p ON p.session_id = s.id
         LEFT JOIN practitioner_notes pn ON pn.session_id = s.id AND pn.participant_id = p.id
         LEFT JOIN session_ratings sr ON sr.session_id = s.id AND sr.end_user_id = ?
         WHERE s.ended_at IS NOT NULL
         ORDER BY s.started_at DESC
         LIMIT 50'
    );
    $sessStmt->execute([$practId, $endUserId, $endUserId]);
    $sessions = $sessStmt->fetchAll();

    // Compute durations
    foreach ($sessions as &$s) {
        if ($s['started_at'] && $s['ended_at']) {
            $start = new DateTime($s['started_at']);
            $end   = new DateTime($s['ended_at']);
            $diff  = $start->diff($end);
            $s['duration'] = ($diff->h > 0 ? $diff->h . 'h ' : '') . $diff->i . 'm';
        } else {
            $s['duration'] = '';
        }
    }
    unset($s);

    // Average rating
    $avgStmt = $pdo->prepare(
        'SELECT AVG(sr.rating)
         FROM session_ratings sr
         JOIN sessions s ON s.id = sr.session_id
         JOIN rooms r ON r.id = s.room_id AND r.practitioner_id = ?
         WHERE sr.end_user_id = ?'
    );
    $avgStmt->execute([$practId, $endUserId]);
    $avgRating = $avgStmt->fetchColumn();

    // ── Wellness rating timeline ─────────────────────────────────
    // Scans intake_responses for "wellness rating" style fields — select
    // fields whose options are exactly ['1','2','3','4','5','6','7','8','9','10'].
    // Aggregates by booking date so the practitioner can see whether the
    // client is trending up or down across sessions.
    $wellnessSeries = [];   // [{ field_label, points: [{ date, value }] }]
    $wellnessStmt = $pdo->prepare(
        'SELECT ir.responses, ir.submitted_at, b.scheduled_at, f.fields, f.id AS form_id
         FROM intake_responses ir
         JOIN bookings      b ON b.id = ir.booking_id
         JOIN intake_forms  f ON f.id = ir.form_id
         WHERE b.practitioner_id = ? AND b.end_user_id = ?
         ORDER BY b.scheduled_at ASC'
    );
    $wellnessStmt->execute([$practId, $endUserId]);
    $wellnessRows = $wellnessStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($wellnessRows)) {
        // Build a map keyed by form_id → list of {field_id, label} that match
        // the wellness shape. Done once per form to avoid re-parsing.
        $wellnessFieldsPerForm = [];
        foreach ($wellnessRows as $row) {
            $formId = (int)$row['form_id'];
            if (isset($wellnessFieldsPerForm[$formId])) continue;
            $fields = json_decode($row['fields'] ?? '[]', true) ?: [];
            $hits = [];
            foreach ($fields as $f) {
                if (($f['type'] ?? '') !== 'select') continue;
                $opts = $f['options'] ?? [];
                if (!is_array($opts) || count($opts) !== 10) continue;
                $expected = ['1','2','3','4','5','6','7','8','9','10'];
                $stringOpts = array_map(fn($o) => (string)$o, $opts);
                if ($stringOpts !== $expected) continue;
                $hits[] = ['id' => $f['id'] ?? '', 'label' => $f['label'] ?? 'Wellness rating'];
            }
            $wellnessFieldsPerForm[$formId] = $hits;
        }

        // Aggregate into series keyed by field label (so the same field on
        // different form versions still groups together for the chart).
        $byLabel = [];
        foreach ($wellnessRows as $row) {
            $formId = (int)$row['form_id'];
            $hits = $wellnessFieldsPerForm[$formId] ?? [];
            if (empty($hits)) continue;
            $responses = json_decode($row['responses'] ?? '{}', true) ?: [];
            $date = $row['scheduled_at'] ?: $row['submitted_at'];
            foreach ($hits as $field) {
                $val = $responses[$field['id']] ?? null;
                if ($val === null || $val === '') continue;
                $intVal = (int)$val;
                if ($intVal < 1 || $intVal > 10) continue;
                $label = $field['label'];
                if (!isset($byLabel[$label])) $byLabel[$label] = [];
                $byLabel[$label][] = ['date' => $date, 'value' => $intVal];
            }
        }
        foreach ($byLabel as $label => $points) {
            $wellnessSeries[] = ['field_label' => $label, 'points' => $points];
        }
    }

    echo json_encode([
        'client'           => $client,
        'sessions'         => $sessions,
        'avg_rating'       => $avgRating ? round((float)$avgRating, 1) : null,
        'wellness_series'  => $wellnessSeries,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
