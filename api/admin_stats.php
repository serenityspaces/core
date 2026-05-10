<?php
/**
 * Serenity Spaces — Admin Stats API
 * Admin-only. Powers the analytics dashboard, system docklet, and audit log viewer.
 *
 * Actions (GET):
 *   system_stats      — OS-level metrics (uptime, CPU, RAM, disk, network)
 *   platform_stats    — Platform-level metrics (practitioners, sessions, revenue, MFA, licenses)
 *   audit_log         — Paginated/filtered audit log rows
 *   audit_event_types — Distinct action values for filter dropdown
 *   verify_chain      — HMAC chain integrity verification
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

header('Content-Type: application/json');

if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo     = getDB();
$practId = (int)$_SESSION['practitioner_id'];
$me      = $pdo->prepare('SELECT is_admin FROM practitioners WHERE id = ? LIMIT 1');
$me->execute([$practId]);
$me      = $me->fetch();
if (!$me || !$me['is_admin']) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$action = $_GET['action'] ?? '';

// ── system_stats ──────────────────────────────────────────────
if ($action === 'system_stats') {
    // Uptime
    $uptimeRaw     = @file_get_contents('/proc/uptime');
    $uptimeSec     = $uptimeRaw ? (float)explode(' ', trim($uptimeRaw))[0] : 0;
    $uptimeDays    = (int)floor($uptimeSec / 86400);
    $uptimeHours   = (int)floor(($uptimeSec % 86400) / 3600);
    $uptimeMins    = (int)floor(($uptimeSec % 3600) / 60);
    if ($uptimeDays > 0)       $uptimeStr = "{$uptimeDays}d {$uptimeHours}h";
    elseif ($uptimeHours > 0)  $uptimeStr = "{$uptimeHours}h {$uptimeMins}m";
    else                       $uptimeStr = "{$uptimeMins}m";

    // CPU — 1-minute load average, normalised to CPU count
    $load = sys_getloadavg();
    $ncpu = 1;
    if (is_readable('/proc/cpuinfo')) {
        $ncpu = max(1, (int)substr_count(@file_get_contents('/proc/cpuinfo'), 'processor'));
    }
    $cpuPct = min(100, round(($load[0] / $ncpu) * 100, 1));

    // RAM from /proc/meminfo (kB values)
    $mem = [];
    if (is_readable('/proc/meminfo')) {
        foreach (file('/proc/meminfo') as $line) {
            [$k, $v] = array_pad(explode(':', $line, 2), 2, '0');
            $mem[trim($k)] = (int)trim($v);
        }
    }
    $memTotal = ($mem['MemTotal']  ?? 0) * 1024;
    $memAvail = ($mem['MemAvailable'] ?? (($mem['MemFree'] ?? 0) + ($mem['Buffers'] ?? 0) + ($mem['Cached'] ?? 0))) * 1024;
    $memUsed  = max(0, $memTotal - $memAvail);
    $memPct   = $memTotal > 0 ? round($memUsed / $memTotal * 100, 1) : 0;

    // Disk
    $diskTotal = @disk_total_space(__DIR__ . '/..') ?: 0;
    $diskFree  = @disk_free_space(__DIR__ . '/..') ?: 0;
    $diskUsed  = $diskTotal - $diskFree;
    $diskPct   = $diskTotal > 0 ? round($diskUsed / $diskTotal * 100, 1) : 0;

    // Network cumulative bytes from /proc/net/dev (exclude loopback)
    $netRx = 0.0;
    $netTx = 0.0;
    if (is_readable('/proc/net/dev')) {
        foreach (file('/proc/net/dev') as $line) {
            $line = trim($line);
            if (!str_contains($line, ':')) continue;
            [$iface, $data] = explode(':', $line, 2);
            if (trim($iface) === 'lo') continue;
            $parts  = preg_split('/\s+/', trim($data));
            $netRx += (float)($parts[0] ?? 0);
            $netTx += (float)($parts[8] ?? 0);
        }
    }

    echo json_encode([
        'ok'         => true,
        'uptime'     => $uptimeStr,
        'cpu_pct'    => $cpuPct,
        'cpu_load'   => round($load[0], 2),
        'mem_pct'    => $memPct,
        'mem_used'   => $memUsed,
        'mem_total'  => $memTotal,
        'disk_pct'   => $diskPct,
        'disk_used'  => $diskUsed,
        'disk_total' => $diskTotal,
        'net_rx'     => $netRx,
        'net_tx'     => $netTx,
        'ts'         => microtime(true),
    ]);
    exit;
}

// ── platform_stats ────────────────────────────────────────────
if ($action === 'platform_stats') {
    $activePract  = (int)$pdo->query("SELECT COUNT(*) FROM practitioners WHERE account_status = 'active'")->fetchColumn();
    $sessions30d  = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
    $totalSessions = (int)$pdo->query("SELECT COUNT(*) FROM sessions")->fetchColumn();

    $revenue = 0.0;
    try {
        $revenue = (float)$pdo->query("SELECT COALESCE(SUM(amount_paid), 0) FROM booking_payments WHERE amount_paid > 0")->fetchColumn();
    } catch (Throwable $e) {}

    $totalPract = (int)$pdo->query("SELECT COUNT(*) FROM practitioners")->fetchColumn();
    $mfaPract   = (int)$pdo->query("SELECT COUNT(*) FROM practitioners WHERE mfa_secret IS NOT NULL AND mfa_secret <> ''")->fetchColumn();
    $mfaRate    = $totalPract > 0 ? round($mfaPract / $totalPract * 100) : 0;

    $licenseQueue = 0;
    try {
        $licenseQueue = (int)$pdo->query("SELECT COUNT(*) FROM license_submissions WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {}

    echo json_encode([
        'ok'                   => true,
        'active_practitioners' => $activePract,
        'total_practitioners'  => $totalPract,
        'sessions_30d'         => $sessions30d,
        'sessions_total'       => $totalSessions,
        'revenue_total'        => round($revenue, 2),
        'mfa_rate'             => $mfaRate,
        'mfa_count'            => $mfaPract,
        'license_queue'        => $licenseQueue,
    ]);
    exit;
}

// ── audit_event_types ─────────────────────────────────────────
if ($action === 'audit_event_types') {
    $types = $pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action ASC')->fetchAll(PDO::FETCH_COLUMN);
    $practitioners = $pdo->query('SELECT id, display_name FROM practitioners ORDER BY display_name ASC')->fetchAll();
    echo json_encode(['ok' => true, 'types' => $types, 'practitioners' => $practitioners]);
    exit;
}

// ── audit_log ─────────────────────────────────────────────────
if ($action === 'audit_log') {
    $eventType    = trim($_GET['event_type']      ?? '');
    $practFilter  = (int)($_GET['practitioner_id'] ?? 0);
    $dateFrom     = trim($_GET['date_from']        ?? '');
    $dateTo       = trim($_GET['date_to']          ?? '');
    $page         = max(1, (int)($_GET['page']     ?? 1));
    $perPage      = 50;
    $offset       = ($page - 1) * $perPage;

    $where  = [];
    $params = [];
    if ($eventType !== '')   { $where[] = 'al.action LIKE ?';            $params[] = '%' . $eventType . '%'; }
    if ($practFilter > 0)    { $where[] = 'al.practitioner_id = ?';      $params[] = $practFilter; }
    if ($dateFrom !== '')    { $where[] = 'al.created_at >= ?';          $params[] = $dateFrom . ' 00:00:00'; }
    if ($dateTo   !== '')    { $where[] = 'al.created_at <= ?';          $params[] = $dateTo   . ' 23:59:59'; }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log al $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT al.id, al.practitioner_id, al.participant_id, al.session_id,
                al.action, al.entity_type, al.entity_id,
                al.ip_address, al.created_at,
                p.display_name AS practitioner_name
         FROM audit_log al
         LEFT JOIN practitioners p ON p.id = al.practitioner_id
         $whereClause
         ORDER BY al.id DESC
         LIMIT " . $perPage . " OFFSET " . $offset
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    echo json_encode([
        'ok'       => true,
        'rows'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
    ]);
    exit;
}

// ── verify_chain ──────────────────────────────────────────────
if ($action === 'verify_chain') {
    $rows    = $pdo->query('SELECT * FROM audit_log ORDER BY id ASC')->fetchAll();
    $total   = count($rows);
    $broken  = 0;
    $purged  = 0;
    $prevHash = 'genesis';
    $firstBreak = null;

    $useHmac = defined('SMTP_ENCRYPT_KEY') && SMTP_ENCRYPT_KEY !== '';
    $hmacKey = $useHmac ? hash_hmac('sha256', 'audit-chain-v1', SMTP_ENCRYPT_KEY, false) : null;

    foreach ($rows as $row) {
        $ip = $row['ip_address'] ?? '';
        if ($ip === null || $ip === '') $purged++;

        if ($hmacKey) {
            $expected = hash_hmac('sha256',
                $prevHash . '|' . $row['action'] . '|' .
                ($row['practitioner_id'] ?? '') . '|' .
                ($row['participant_id']  ?? '') . '|' .
                ($row['session_id']      ?? '') . '|' .
                ($ip) . '|' . $row['created_at'],
                $hmacKey
            );
        } else {
            $expected = hash('sha256',
                $prevHash . '|' . $row['action'] . '|' .
                ($row['practitioner_id'] ?? '') . '|' .
                ($row['participant_id']  ?? '') . '|' .
                ($row['session_id']      ?? '') . '|' .
                ($ip) . '|' . $row['created_at']
            );
        }

        if (!hash_equals($expected, (string)$row['row_hash'])) {
            $broken++;
            if (!$firstBreak) {
                $firstBreak = [
                    'id'         => (int)$row['id'],
                    'action'     => $row['action'],
                    'created_at' => $row['created_at'],
                    'ip_purged'  => ($ip === null || $ip === ''),
                ];
            }
        }
        $prevHash = $row['row_hash'];
    }

    echo json_encode([
        'ok'          => true,
        'intact'      => $broken === 0,
        'checked'     => $total,
        'broken'      => $broken,
        'purged_ips'  => $purged,
        'keyed'       => $useHmac,
        'first_break' => $firstBreak,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
