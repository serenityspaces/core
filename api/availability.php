<?php
/**
 * Serenity Spaces — Availability API
 * Handles reading and writing practitioner availability (weekly schedule + exclusions).
 *
 * GET  ?practitioner_id=N          — returns public availability (for booking page)
 * POST action=save_schedule        — practitioner saves weekly availability (auth required)
 * POST action=add_exclusion        — practitioner adds a date exclusion (auth required)
 * POST action=remove_exclusion     — practitioner removes a date exclusion (auth required)
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

header('Content-Type: application/json');

$pdo = getDB();

// ── GET: public availability for a practitioner ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $practId = (int)($_GET['practitioner_id'] ?? 0);
    if (!$practId) {
        http_response_code(400);
        echo json_encode(['error' => 'practitioner_id required']);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT id, day_of_week, start_time, end_time FROM practitioner_availability
         WHERE practitioner_id = ? ORDER BY day_of_week, start_time'
    );
    $stmt->execute([$practId]);
    $slots = $stmt->fetchAll();

    $exStmt = $pdo->prepare(
        'SELECT excluded_date FROM practitioner_exclusions WHERE practitioner_id = ? ORDER BY excluded_date'
    );
    $exStmt->execute([$practId]);
    $exclusions = array_column($exStmt->fetchAll(), 'excluded_date');

    $tzStmt = $pdo->prepare('SELECT timezone FROM practitioners WHERE id = ? LIMIT 1');
    $tzStmt->execute([$practId]);
    $row = $tzStmt->fetch();

    // Also get existing bookings (pending/scheduled) to exclude taken slots
    $bookStmt = $pdo->prepare(
        'SELECT scheduled_at, duration_minutes FROM bookings
         WHERE practitioner_id = ? AND status IN (\'pending\',\'scheduled\') AND scheduled_at > UTC_TIMESTAMP()'
    );
    $bookStmt->execute([$practId]);
    $booked = $bookStmt->fetchAll();

    echo json_encode([
        'timezone'   => $row['timezone'] ?? 'UTC',
        'slots'      => $slots,
        'exclusions' => $exclusions,
        'booked'     => $booked,
    ]);
    exit;
}

// ── POST: authenticated practitioner actions ───────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'GET or POST required']);
    exit;
}

if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$practId = (int)$_SESSION['practitioner_id'];
$input   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action  = $input['action'] ?? '';

// ── save_schedule ──────────────────────────────────────────────
if ($action === 'save_schedule') {
    $schedule = $input['schedule'] ?? []; // array of {day_of_week, start_time, end_time}

    $pdo->prepare('DELETE FROM practitioner_availability WHERE practitioner_id = ?')
        ->execute([$practId]);

    $ins = $pdo->prepare(
        'INSERT INTO practitioner_availability (practitioner_id, day_of_week, start_time, end_time)
         VALUES (?, ?, ?, ?)'
    );

    foreach ($schedule as $slot) {
        $dow   = (int)($slot['day_of_week'] ?? 0);
        $start = trim($slot['start_time'] ?? '');
        $end   = trim($slot['end_time']   ?? '');
        if ($dow < 0 || $dow > 6) continue;
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) continue;
        if ($end <= $start) continue;
        $ins->execute([$practId, $dow, $start . ':00', $end . ':00']);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── add_exclusion ──────────────────────────────────────────────
if ($action === 'add_exclusion') {
    $date = trim($input['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format (YYYY-MM-DD expected)']);
        exit;
    }
    $pdo->prepare(
        'INSERT IGNORE INTO practitioner_exclusions (practitioner_id, excluded_date) VALUES (?,?)'
    )->execute([$practId, $date]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── remove_exclusion ───────────────────────────────────────────
if ($action === 'remove_exclusion') {
    $date = trim($input['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date format']);
        exit;
    }
    $pdo->prepare(
        'DELETE FROM practitioner_exclusions WHERE practitioner_id = ? AND excluded_date = ?'
    )->execute([$practId, $date]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
