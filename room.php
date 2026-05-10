<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
requireSetup();
require_once __DIR__ . '/db/events.php';
require_once __DIR__ . '/db/audit.php';
require_once __DIR__ . '/includes/avatar_presets.php';
require_once __DIR__ . '/includes/phi_crypto.php';

$pdo            = getDB();
$isPractitioner = false;
$room           = null;
$session        = null;
$participant    = null;

// ── Auth logic ─────────────────────────────────────────────────
if (isset($_GET['room_id']) && !empty($_SESSION['practitioner_id'])) {
    // Practitioner access
    $roomId = (int)$_GET['room_id'];
    $stmt   = $pdo->prepare(
        'SELECT * FROM rooms WHERE id = ? AND practitioner_id = ? LIMIT 1'
    );
    $stmt->execute([$roomId, (int)$_SESSION['practitioner_id']]);
    $room = $stmt->fetch();
    if (!$room) { header('Location: /dashboard.php'); exit; }

    // Load practitioner profile — redirect to setup if incomplete
    $stmt = $pdo->prepare('SELECT * FROM practitioners WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$_SESSION['practitioner_id']]);
    $practitionerRow = $stmt->fetch();
    if (empty($practitionerRow['display_name'])) {
        header('Location: /profile.php?next=' . urlencode('/room.php?room_id=' . $roomId));
        exit;
    }

    // Find or create active session
    $stmt = $pdo->prepare(
        'SELECT * FROM sessions WHERE room_id = ? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1'
    );
    $stmt->execute([$room['id']]);
    $session = $stmt->fetch();
    if (!$session) {
        $pdo->prepare('INSERT INTO sessions (room_id) VALUES (?)')->execute([$room['id']]);
        $sessionId = $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();
    }

    // Create or retrieve the practitioner's participant record for this session
    $practJoinToken = 'pract-' . $session['id'] . '-' . (int)$_SESSION['practitioner_id'];
    $stmt = $pdo->prepare('SELECT * FROM participants WHERE join_token = ? LIMIT 1');
    $stmt->execute([$practJoinToken]);
    $practParticipant = $stmt->fetch();
    if (!$practParticipant) {
        $pdo->prepare(
            'INSERT INTO participants (session_id, display_name, avatar_path, join_token, is_host, position_x, position_y)
             VALUES (?, ?, ?, ?, 1, ?, ?)'
        )->execute([
            $session['id'],
            $practitionerRow['display_name'],
            $practitionerRow['avatar_path'] ?: 'preset:DefaultMe',
            $practJoinToken,
            0.5, 0.3,
        ]);
        $stmt = $pdo->prepare('SELECT * FROM participants WHERE join_token = ? LIMIT 1');
        $stmt->execute([$practJoinToken]);
        $practParticipant = $stmt->fetch();
    }

    $participant     = $practParticipant;
    $isPractitioner  = true;

    audit_log($pdo, 'session.view', [
        'practitioner_id' => (int)$_SESSION['practitioner_id'],
        'session_id'      => (int)$session['id'],
        'entity_type'     => 'session',
        'entity_id'       => (int)$session['id'],
    ]);

} elseif (isset($_GET['join_token']) || !empty($_SESSION['ss_join_token'])) {
    // Client access
    // If token arrived in URL, store in session and redirect to clean URL (#7)
    if (isset($_GET['join_token'])) {
        $rawToken = trim($_GET['join_token']);
        // Quick validity check before redirecting so we don't store garbage
        $testStmt = $pdo->prepare('SELECT id FROM participants WHERE join_token = ? LIMIT 1');
        $testStmt->execute([$rawToken]);
        if (!$testStmt->fetch()) { header('Location: /index.php'); exit; }
        $_SESSION['ss_join_token'] = $rawToken;
        // Preserve any extra query params except join_token
        $extra = $_GET;
        unset($extra['join_token']);
        $qs = $extra ? '?' . http_build_query($extra) : '';
        header('Location: /room.php' . $qs, true, 302);
        exit;
    }
    $joinToken = trim($_SESSION['ss_join_token']);
    $stmt = $pdo->prepare('SELECT * FROM participants WHERE join_token = ? LIMIT 1');
    $stmt->execute([$joinToken]);
    $participant = $stmt->fetch();
    if (!$participant) { header('Location: /index.php'); exit; }

    $stmt = $pdo->prepare('SELECT * FROM sessions WHERE id = ? LIMIT 1');
    $stmt->execute([$participant['session_id']]);
    $session = $stmt->fetch();
    if (!$session || $session['ended_at'] !== null) {
        unset($_SESSION['ss_join_token']); // clear so old token isn't reused
        header('Location: /index.php?error=session_ended'); exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ? LIMIT 1');
    $stmt->execute([$session['room_id']]);
    $room = $stmt->fetch();
    if (!$room) { header('Location: /index.php'); exit; }

    // Set reconnect cookie so client can resume if they disconnect
    setcookie('ss_reconnect_' . $room['room_token'], $joinToken, [
        'expires'  => time() + 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    // Audit: log client joining the session (HIPAA §164.312(b))
    require_once __DIR__ . '/db/audit.php';
    audit_log($pdo, 'session.join', [
        'session_id'     => (int)$session['id'],
        'participant_id' => (int)$participant['id'],
        'entity_type'    => 'session',
        'entity_id'      => (int)$session['id'],
    ]);

    // ── DC routing: sync session to client's preferred Alternative Location ──
    // If this is the Primary server and the client has a preferred_dc configured,
    // push the session + participant data to that alt server so messages can be
    // stored there and routed back efficiently.
    $dcBaseUrl = null;
    if (defined('INSTALL_TYPE') && INSTALL_TYPE === 'primary') {
        $endUserId = $_SESSION['end_user_id'] ?? null;
        if ($endUserId) {
            $dcStmt = $pdo->prepare(
                'SELECT l.url, l.auth_token_hash
                 FROM locations l
                 JOIN end_users eu ON eu.preferred_dc = l.id
                 WHERE eu.id = ? AND l.is_active = 1
                 LIMIT 1'
            );
            $dcStmt->execute([(int)$endUserId]);
            $dcLocation = $dcStmt->fetch();

            if ($dcLocation) {
                $dcBaseUrl = $dcLocation['url'];
                require_once __DIR__ . '/includes/dc_auth.php';

                // Gather all participants for this session
                $allPartsStmt = $pdo->prepare(
                    'SELECT id, join_token, is_host FROM participants WHERE session_id = ?'
                );
                $allPartsStmt->execute([(int)$session['id']]);
                $allParts = $allPartsStmt->fetchAll();

                $dcParticipants = array_map(function($p) {
                    return [
                        'id'              => (int)$p['id'],
                        'join_token'      => $p['join_token'],
                        'is_practitioner' => str_starts_with($p['join_token'], 'pract-') ? 1 : 0,
                    ];
                }, $allParts);

                dc_post($dcBaseUrl, '/api/dc.php', [
                    'action'       => 'sync_session',
                    'session_id'   => (int)$session['id'],
                    'room_id'      => (int)$session['room_id'],
                    'participants' => $dcParticipants,
                ], DC_SHARED_SECRET ?? '');
            }
        }
    }

    // Check if practitioner is already present (seen within last 35 seconds)
    $practToken  = 'pract-' . $session['id'] . '-%';
    $practPStmt  = $pdo->prepare(
        'SELECT last_seen_at FROM participants WHERE session_id = ? AND join_token LIKE ? LIMIT 1'
    );
    $practPStmt->execute([$session['id'], $practToken]);
    $practPRow   = $practPStmt->fetch();
    $practPresent = false;
    if ($practPRow && $practPRow['last_seen_at']) {
        $practPresent = (time() - strtotime($practPRow['last_seen_at'])) <= 35;
    }

} else {
    header('Location: /index.php');
    exit;
}

if (!isset($practPresent)) $practPresent = true; // default: don't show waiting overlay

// ── Practitioner highlight colors ─────────────────────────────
// For client path, load room owner's colors so highlights render correctly
if (!isset($practitionerRow)) {
    $stmtPR = $pdo->prepare('SELECT hl_color_1, hl_color_2, hl_color_3, role_icon, practice_types, inclusivity_tags, license_status FROM practitioners WHERE id = ? LIMIT 1');
    $stmtPR->execute([$room['practitioner_id']]);
    $practitionerRow = $stmtPR->fetch() ?: [];
}
$hlColor1 = $practitionerRow['hl_color_1'] ?? '#f5e24a';
$hlColor2 = $practitionerRow['hl_color_2'] ?? '#f48fb1';
$hlColor3 = $practitionerRow['hl_color_3'] ?? '#64b5f6';

// ── Practitioner role icon ─────────────────────────────────────
// Licensed practitioners are always shown mental-health.png; others use their saved role_icon,
// defaulting to ribbon if none is set.
$_licenseStatus = $practitionerRow['license_status'] ?? 'none';
if ($_licenseStatus === 'approved') {
    $practitionerRoleIcon = 'mental-health';
} else {
    $practitionerRoleIcon = $practitionerRow['role_icon'] ?? 'ribbon';
    if (empty($practitionerRoleIcon)) $practitionerRoleIcon = 'ribbon';
}

// Convert hex to rgba highlight value (40% opacity)
function hexToHighlight(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return 'rgba(255,214,0,0.35)';
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "rgba($r,$g,$b,0.4)";
}

// ── Load initial data ──────────────────────────────────────────
// Participants for this session
$stmtP = $pdo->prepare(
    'SELECT * FROM participants WHERE session_id = ? ORDER BY joined_at ASC'
);
$stmtP->execute([$session['id']]);
$participants = $stmtP->fetchAll();

// Recent messages (last 100)
$stmtM = $pdo->prepare(
    'SELECT m.*, p.display_name, p.avatar_path FROM messages m
     LEFT JOIN participants p ON p.id = m.participant_id
     WHERE m.session_id = ? ORDER BY m.sent_at ASC LIMIT 100'
);
$stmtM->execute([$session['id']]);
$messages = $stmtM->fetchAll();
foreach ($messages as &$_m) {
    if (($_m['message_type'] ?? 'text') === 'text') {
        $_m['content'] = phi_decrypt($_m['content'] ?? '');
    }
}
unset($_m);

// Message IDs for highlights query
$msgIds = array_column($messages, 'id');
$highlights = [];
if ($msgIds) {
    $placeholders = implode(',', array_fill(0, count($msgIds), '?'));
    $stmtH = $pdo->prepare("SELECT * FROM highlights WHERE message_id IN ($placeholders) ORDER BY start_offset ASC");
    $stmtH->execute($msgIds);
    $hlRows = $stmtH->fetchAll();
    foreach ($hlRows as $hl) {
        $highlights[$hl['message_id']][] = $hl;
    }
}

// Reactions for initial messages
$reactionsMap = [];
if ($msgIds) {
    $placeholders = implode(',', array_fill(0, count($msgIds), '?'));
    $stmtR = $pdo->prepare(
        "SELECT mr.message_id, mr.participant_id, mr.emoji, p.avatar_path
         FROM message_reactions mr
         JOIN participants p ON p.id = mr.participant_id
         WHERE mr.message_id IN ($placeholders)"
    );
    $stmtR->execute($msgIds);
    foreach ($stmtR->fetchAll() as $r) {
        $reactionsMap[(int)$r['message_id']][] = [
            'participant_id' => (int)$r['participant_id'],
            'emoji'          => $r['emoji'],
            'avatar_url'     => resolveAvatarUrl($r['avatar_path'] ?? ''),
        ];
    }
}

// Pinned messages
$stmtPin = $pdo->prepare(
    'SELECT m.*, p.display_name, p.avatar_path, pi.annotation as pin_annotation
     FROM messages m
     LEFT JOIN participants p ON p.id = m.participant_id
     LEFT JOIN pins pi ON pi.message_id = m.id
     WHERE m.session_id = ? AND m.is_pinned = 1 ORDER BY m.sent_at ASC'
);
$stmtPin->execute([$session['id']]);
$pinnedMessages = $stmtPin->fetchAll();

// Has the session been formally begun?
$stmtBegun = $pdo->prepare("SELECT id FROM events WHERE session_id = ? AND type = 'session_begun' LIMIT 1");
$stmtBegun->execute([$session['id']]);
$sessionBegun = (bool)$stmtBegun->fetch();

// Practitioner notes (keyed by participant_id)
$notes = [];
if ($isPractitioner) {
    $stmtN = $pdo->prepare(
        'SELECT * FROM practitioner_notes WHERE session_id = ?'
    );
    $stmtN->execute([$session['id']]);
    foreach ($stmtN->fetchAll() as $n) {
        $notes[$n['participant_id']] = phi_decrypt($n['note_content'] ?? '');
    }
}

// Concept tags — practitioner only
$conceptTagLibrary = [];
$msgConceptTags    = [];
$noteConceptTags   = [];
if ($isPractitioner && $msgIds) {
    // Tag library for this practitioner
    $stmtCT = $pdo->prepare('SELECT * FROM concept_tags WHERE practitioner_id = ? ORDER BY name ASC');
    $stmtCT->execute([(int)$_SESSION['practitioner_id']]);
    $conceptTagLibrary = $stmtCT->fetchAll();

    // Message tags for this session's messages
    $placeholders = implode(',', array_fill(0, count($msgIds), '?'));
    $stmtMCT = $pdo->prepare(
        "SELECT mct.message_id, ct.id AS tag_id, ct.name, ct.color
         FROM message_concept_tags mct
         JOIN concept_tags ct ON ct.id = mct.tag_id
         WHERE mct.message_id IN ($placeholders)"
    );
    $stmtMCT->execute($msgIds);
    foreach ($stmtMCT->fetchAll() as $r) {
        $msgConceptTags[(int)$r['message_id']][] = ['id' => (int)$r['tag_id'], 'name' => $r['name'], 'color' => $r['color']];
    }

    // Note tags for this session
    $stmtNCT = $pdo->prepare(
        'SELECT nct.participant_id, ct.id AS tag_id, ct.name, ct.color
         FROM note_concept_tags nct
         JOIN concept_tags ct ON ct.id = nct.tag_id
         WHERE nct.session_id = ? AND ct.practitioner_id = ?'
    );
    $stmtNCT->execute([$session['id'], (int)$_SESSION['practitioner_id']]);
    foreach ($stmtNCT->fetchAll() as $r) {
        $noteConceptTags[(int)($r['participant_id'] ?? 0)][] = ['id' => (int)$r['tag_id'], 'name' => $r['name'], 'color' => $r['color']];
    }
} elseif ($isPractitioner) {
    $stmtCT = $pdo->prepare('SELECT * FROM concept_tags WHERE practitioner_id = ? ORDER BY name ASC');
    $stmtCT->execute([(int)$_SESSION['practitioner_id']]);
    $conceptTagLibrary = $stmtCT->fetchAll();
}

// ── Build avatar URL helper ────────────────────────────────────
function resolveAvatarUrl(string $path): string {
    global $avatarPresets;
    if (strpos($path, 'preset:') === 0) {
        $key = substr($path, 7);
        return $avatarPresets[$key] ?? $avatarPresets['Default'];
    }
    return $path ?: $avatarPresets['Default'];
}

// Detect reconnect: participant was created more than 30s ago (not a fresh redirect)
$joinEventType = (!$isPractitioner && (time() - strtotime($participant['joined_at'])) > 30)
    ? 'participant_reconnect'
    : 'participant_join';

// Emit join/reconnect event so others see this person enter
emitEvent($pdo, (int)$session['id'], $joinEventType, [
    'id'           => (int)$participant['id'],
    'display_name' => $participant['display_name'],
    'avatar_path'  => $participant['avatar_path'] ?? '',
    'position_x'   => (float)$participant['position_x'],
    'position_y'   => (float)$participant['position_y'],
    'linked_to'    => $participant['linked_to_participant_id'] ? (int)$participant['linked_to_participant_id'] : null,
    'join_token'   => $participant['join_token'],
]);

// Capture lastEventId AFTER emitting join (so own join isn't polled back)
$stmtEv = $pdo->prepare('SELECT COALESCE(MAX(id), 0) as maxId FROM events WHERE session_id = ?');
$stmtEv->execute([$session['id']]);
$lastEventId = (int)$stmtEv->fetch()['maxId'];

// ── Audit log: session view ────────────────────────────────────
audit_log($pdo, 'session.view', [
    'practitioner_id' => $isPractitioner ? (int)$_SESSION['practitioner_id'] : null,
    'participant_id'  => $participant ? (int)$participant['id'] : null,
    'session_id'      => (int)$session['id'],
    'entity_type'     => 'session',
    'entity_id'       => (int)$session['id'],
]);

// Find the practitioner's participant record so all clients know who the host is
$stmtHost = $pdo->prepare(
    "SELECT id FROM participants WHERE session_id = ? AND join_token LIKE 'pract-%' LIMIT 1"
);
$stmtHost->execute([$session['id']]);
$hostPart = $stmtHost->fetch();

// ── AI Integration ─────────────────────────────────────────────
$aiConfig     = null;
$aiParticipantId = null;
$aiPlatformEnabled = getSetting('ai_enabled', '0') === '1';

if ($aiPlatformEnabled && $isPractitioner) {
    $practId = (int)$_SESSION['practitioner_id'];

    // Load practitioner AI config
    try {
        $stmtAI = $pdo->prepare('SELECT * FROM practitioner_ai_config WHERE practitioner_id = ? AND enabled = 1');
        $stmtAI->execute([$practId]);
        $practAiRow = $stmtAI->fetch();

        if ($practAiRow && $practAiRow['scope_in_session']) {
            $aiConfig = [
                'enabled'        => true,
                'assistantName'  => $practAiRow['assistant_name'],
                'assistantAvatar'=> $practAiRow['assistant_avatar'] ?: 'assets/images/ai.png',
                'vendor'         => $practAiRow['vendor'],
                'scopes' => [
                    'summarization' => (bool)(int)$practAiRow['scope_summarization'],
                    'notes'         => (bool)(int)$practAiRow['scope_notes'],
                    'post_session'  => (bool)(int)$practAiRow['scope_post_session'],
                    'in_session'    => (bool)(int)$practAiRow['scope_in_session'],
                ],
            ];

            // Ensure AI participant exists in this session
            $aiJoinToken = 'ai-' . $session['id'] . '-' . $practId;
            $stmtAIP = $pdo->prepare('SELECT id FROM participants WHERE session_id = ? AND join_token = ?');
            $stmtAIP->execute([$session['id'], $aiJoinToken]);
            $aiPart = $stmtAIP->fetch();

            if (!$aiPart) {
                $avatarPath = $practAiRow['assistant_avatar'] ?: 'assets/images/ai.png';
                $pdo->prepare('
                    INSERT INTO participants
                        (session_id, display_name, avatar_path, join_token, is_host, is_ai, position_x, position_y)
                    VALUES (?, ?, ?, ?, 0, 1, 120, 120)
                ')->execute([$session['id'], $practAiRow['assistant_name'], $avatarPath, $aiJoinToken]);
                $aiParticipantId = (int)$pdo->lastInsertId();
            } else {
                $aiParticipantId = (int)$aiPart['id'];
            }

            $aiConfig['participantId'] = $aiParticipantId;

            // Refresh participants list to include the AI participant
            $stmtP = $pdo->prepare('SELECT * FROM participants WHERE session_id = ? ORDER BY joined_at ASC');
            $stmtP->execute([$session['id']]);
            $participants = $stmtP->fetchAll();
        }
    } catch (PDOException $e) { /* ai tables may not exist on older installs */ }
}

// ── Media Recommendations config ───────────────────────────────
$mediaConfig = null;
if ($isPractitioner) {
    $practId = (int)$_SESSION['practitioner_id'];
    try {
        $stmtMC = $pdo->prepare('SELECT open_library, google_books_enabled, tmdb_enabled, amazon_enabled, amazon_tag FROM practitioner_media_config WHERE practitioner_id = ? LIMIT 1');
        $stmtMC->execute([$practId]);
        $mcRow = $stmtMC->fetch();
        if ($mcRow) {
            $mediaConfig = [
                'open_library'         => (bool)(int)$mcRow['open_library'],
                'google_books_enabled' => (bool)(int)$mcRow['google_books_enabled'],
                'tmdb_enabled'         => (bool)(int)$mcRow['tmdb_enabled'],
                'amazon_enabled'       => (bool)(int)$mcRow['amazon_enabled'],
                'amazon_tag'           => $mcRow['amazon_tag'] ?? '',
                'has_books'            => (bool)(int)$mcRow['open_library'] || (bool)(int)$mcRow['google_books_enabled'],
                'has_video'            => (bool)(int)$mcRow['tmdb_enabled'],
            ];
        } else {
            // Default: Open Library on, nothing else
            $mediaConfig = ['open_library'=>true,'google_books_enabled'=>false,'tmdb_enabled'=>false,'amazon_enabled'=>false,'amazon_tag'=>'','has_books'=>true,'has_video'=>false];
        }
    } catch (PDOException $e) { /* table may not exist on older installs */ }
}

// Participant JSON for JS
$participantsJson = json_encode(array_values(array_map(function($p) {
    return [
        'id'           => (int)$p['id'],
        'display_name' => $p['display_name'],
        'avatar_path'  => $p['avatar_path'] ?? '',
        'avatar_url'   => resolveAvatarUrl($p['avatar_path'] ?? ''),
        'position_x'   => (float)$p['position_x'],
        'position_y'   => (float)$p['position_y'],
        'linked_to'    => $p['linked_to_participant_id'] ? (int)$p['linked_to_participant_id'] : null,
        'join_token'   => $p['join_token'],
        'is_ai'        => !empty($p['is_ai']),
    ];
}, $participants)));

// Prepare messages with highlights for JS
$messagesForJs = array_map(function($m) use ($highlights, $reactionsMap) {
    return [
        'id'              => (int)$m['id'],
        'participant_id'  => $m['participant_id'] ? (int)$m['participant_id'] : null,
        'display_name'    => $m['display_name'] ?? '',
        'content'         => $m['content'],
        'message_type'    => $m['message_type'] ?? 'text',
        'caption'         => $m['caption'] ?? null,
        'file_size'       => $m['file_size'] ? (int)$m['file_size'] : null,
        'mime_type'       => $m['mime_type'] ?? null,
        'original_name'   => $m['original_name'] ?? null,
        'sent_at'         => $m['sent_at'],
        'is_practitioner' => (bool)$m['is_practitioner'],
        'is_pinned'       => (bool)$m['is_pinned'],
        'highlights'      => $highlights[(int)$m['id']] ?? [],
        'reactions'       => $reactionsMap[(int)$m['id']] ?? [],
        'concept_tags'    => $msgConceptTags[(int)$m['id']] ?? [],
    ];
}, $messages);

$messagesJson = json_encode(array_values($messagesForJs));
$pinsJson     = json_encode(array_values(array_map(function($m) {
    return [
        'id'         => (int)$m['id'],
        'display_name' => $m['display_name'] ?? 'Practitioner',
        'content'    => $m['content'],
        'sent_at'    => $m['sent_at'],
        'annotation' => $m['pin_annotation'],
    ];
}, $pinnedMessages)));

$myParticipantId    = (int)$participant['id'];
$myJoinToken        = $participant['join_token'];
$hostParticipantId  = $hostPart ? (int)$hostPart['id'] : null;
$scheme             = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$inviteUrl          = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/index.php?token=' . urlencode($room['room_token']);
$sessionId        = (int)$session['id'];
$bgPath           = $room['background_path'] ?? '';
$bgMime           = '';
if ($bgPath) {
    $stmtBgMime = $pdo->prepare(
        'SELECT mime_type FROM backgrounds WHERE file_path = ? AND practitioner_id = ? LIMIT 1'
    );
    $stmtBgMime->execute([$bgPath, $room['practitioner_id']]);
    $bgMimeRow = $stmtBgMime->fetch();
    $bgMime    = $bgMimeRow ? ($bgMimeRow['mime_type'] ?? '') : '';
}
$bgPathSafe = $bgPath ? htmlspecialchars($bgPath) : '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── Feature flags from admin settings ─────────────────────────
$featureFlags = [
    'allowWebcam'     => getSetting('allow_webcam',      '1') === '1',
    'allowVoiceChat'  => getSetting('allow_voice_chat',  '1') === '1',
    'allowVoiceNotes' => getSetting('allow_voice_notes', '1') === '1',
    'allowAttachments'=> getSetting('allow_attachments', '1') === '1',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($room['name']) ?> — Serenity Spaces</title>
  <link rel="stylesheet" href="/assets/css/fonts.css">
  <link rel="stylesheet" href="/assets/css/main.css">
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
    :root {
      --highlight-yellow: <?= htmlspecialchars(hexToHighlight($hlColor1)) ?>;
      --highlight-pink:   <?= htmlspecialchars(hexToHighlight($hlColor2)) ?>;
      --highlight-blue:   <?= htmlspecialchars(hexToHighlight($hlColor3)) ?>;
    }
  </style>
</head>
<body>

<!-- Sidebar toggle (mobile) -->
<button id="sidebar-toggle" aria-label="Show panel" title="Show panel">
  <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 5h10M4 9h10M4 13h10"/></svg>
</button>

<div id="container">
  <!-- ── Main area ── -->
  <div id="main">
    <!-- Background mirror: visible behind chat when room is collapsed -->
    <div id="room-bg-mirror"<?= $bgPath && !str_starts_with($bgMime, 'video/') ? ' style="background-image:url(\'' . $bgPathSafe . '\')"' : '' ?>>
      <?php if ($bgPath && str_starts_with($bgMime, 'video/')): ?>
      <video autoplay loop muted playsinline style="width:100%;height:100%;object-fit:cover;pointer-events:none;">
        <source src="<?= $bgPathSafe ?>" type="<?= htmlspecialchars($bgMime) ?>">
      </video>
      <?php endif; ?>
    </div>
    <div id="room">
      <div class="bg" <?= (!$bgPath || str_starts_with($bgMime, 'video/')) ? '' : 'style="background-image:url(\'' . $bgPathSafe . '\')"' ?>>
        <?php if ($bgPath && str_starts_with($bgMime, 'video/')): ?>
          <video autoplay loop muted playsinline oncontextmenu="return false"
                 style="width:100%;height:100%;object-fit:cover;pointer-events:none;">
            <source src="<?= $bgPathSafe ?>" type="<?= htmlspecialchars($bgMime) ?>">
          </video>
        <?php endif; ?>
      </div>
    </div>
    <div id="horizontal-divider">
      <button id="room-collapse-btn" aria-label="Toggle room view" title="Toggle room view">
        <svg id="room-collapse-icon" viewBox="0 0 16 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 2l6 6 6-6"/></svg>
      </button>
    </div>
    <div id="chat-pane">
      <button id="new-msg-pill" style="display:none;position:absolute;bottom:72px;left:50%;transform:translateX(-50%);z-index:20;background:#7c6af7;color:#fff;border:none;border-radius:999px;padding:6px 18px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;box-shadow:0 2px 12px rgba(124,106,247,0.45);" onclick="dismissNewMsgPill()">↓ <span id="new-msg-count">0</span> new message<span id="new-msg-plural">s</span></button>
      <div id="messages"></div>
      <div id="file-preview" style="display:none"></div>
      <div id="voice-preview" style="display:none"></div>
      <div id="input-area">
        <button id="file-btn" class="input-icon-btn" title="Send File">📎</button>
        <input type="file" id="file-input" style="display:none">
        <?php if ($isPractitioner): ?>
        <div id="crisis-panel" style="display:none;" aria-label="Crisis Resources">
          <div class="crisis-panel-header">
            <span class="crisis-panel-title">⚠️ Crisis Resources</span>
            <button class="crisis-panel-close" id="crisis-panel-close-btn" title="Close">×</button>
          </div>
          <div id="crisis-panel-list"><span style="color:var(--text-muted);font-size:12px;">Loading…</span></div>
        </div>
        <button id="crisis-btn" class="input-icon-btn" title="Send Crisis Resource">⚠️</button>
        <?php if ($mediaConfig): ?>
        <button id="media-rec-btn" class="input-icon-btn" title="Recommend Media">📖</button>
        <?php endif; ?>
        <?php endif; ?>
        <input id="chat-input" type="text" placeholder="Speak freely…" autocomplete="off">
        <button id="voice-btn" class="input-icon-btn" title="Voice Note">🎙</button>
        <button id="emoji-btn" class="input-icon-btn" title="Emoji">😊</button>
        <button id="send-btn">Send</button>
      </div>
    </div>
  </div>

  <!-- ── Sidebar ── -->
  <div id="sidebar">

    <!-- Room info -->
    <div class="room-info-strip sidebar-section">
      <div class="room-title"><?= htmlspecialchars($room['name']) ?></div>
      <div class="session-info" id="session-info">
        Session #<?= $sessionId ?>
        <?php if ($isPractitioner): ?>
          &nbsp;·&nbsp;<span id="participant-count"><?= count($participants) ?></span> participants
        <?php endif; ?>
      </div>
      <?php if ($isPractitioner): ?>
      <button class="btn btn-ghost btn-sm" id="copy-invite-btn" style="width:100%;justify-content:center;margin-top:8px;">
        Copy Invite Link
      </button>
      <div id="invite-copied" style="display:none;text-align:center;font-size:12px;color:var(--accent);margin-top:4px;">
        Copied to clipboard
      </div>
      <?php endif; ?>
    </div>

    <?php if ($isPractitioner && !$sessionBegun): ?>
    <!-- Begin Session -->
    <div class="sidebar-section" id="begin-session-section">
      <button class="btn btn-primary" id="begin-session-btn" style="width:100%;justify-content:center;">
        ✨ Begin Session
      </button>
      <p style="font-size:11px;color:var(--text-muted);text-align:center;margin:6px 0 0;">
        Chat is open once you begin
      </p>
    </div>
    <?php endif; ?>

    <?php if ($isPractitioner): ?>
    <!-- Duration management -->
    <div class="sidebar-section" id="duration-section">
      <div class="sidebar-label">Session Timer <span id="duration-display" style="font-weight:600;color:var(--accent);margin-left:6px;"></span></div>
      <div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;">
        <input type="number" id="duration-input" min="1" max="480" placeholder="min" class="timer-input">
        <button class="btn btn-sm btn-ghost" id="duration-start-btn">Start</button>
        <button class="btn btn-sm btn-ghost" id="duration-pause-btn" style="display:none;">Pause</button>
        <button class="btn btn-sm btn-ghost" id="duration-stop-btn" style="display:none;">Stop</button>
      </div>
    </div>
    <?php endif; ?>

    <!-- Participants -->
    <div class="sidebar-section">
      <div class="sidebar-label">Participants</div>
      <ul id="user-list"></ul>
    </div>

    <!-- Voice Chat -->
    <div class="sidebar-section" id="voice-section">
      <div class="sidebar-label">Voice Chat</div>
      <div id="voice-participants"></div>
      <button id="voice-join-btn" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center;margin-top:6px;">🎤 Join Voice</button>
    </div>

    <!-- Activities -->
    <div class="sidebar-section" id="activities-section">
      <div class="sidebar-label">Activities</div>
      <div id="active-activities"></div>
      <div style="position:relative;">
        <button class="btn btn-ghost btn-sm" id="show-activity-picker-btn" style="width:100%;justify-content:center;margin-top:6px;">🎮 Start Activity</button>
        <div id="activity-picker" class="activity-picker-dropdown">
          <button class="activity-pick-item" data-activity="paint">🎨 Paint</button>
          <button class="activity-pick-item" data-activity="chess">♟ Chess</button>
          <button class="activity-pick-item" data-activity="checkers">🔴 Checkers</button>
          <button class="activity-pick-item" data-activity="zen_garden">🪨 Zen Garden</button>
        </div>
      </div>
    </div>

    <?php if ($isPractitioner): ?>

    <!-- Notes panel -->
    <div class="sidebar-section notes-panel" id="notes-panel">
      <div class="sidebar-label">Session Notes</div>

      <!-- Participant selector (excludes practitioner) -->
      <div class="notes-participant-select">
        <select id="notes-participant-select">
          <option value="">— Select participant —</option>
          <?php foreach ($participants as $p):
            // Skip the practitioner's own record
            if ($p['id'] === $participant['id']) continue;
          ?>
            <option value="<?= $p['id'] ?>">
              <?= htmlspecialchars($p['display_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Template selector (#13) -->
      <div class="notes-template-row" id="notes-template-row" style="display:none;">
        <select id="notes-template-select" title="Load a note template">
          <option value="">📋 Load template…</option>
          <option value="soap">SOAP</option>
          <option value="dap">DAP</option>
          <option value="birp">BIRP</option>
          <option value="blank">Blank</option>
        </select>
      </div>

      <!-- Concept tag strip -->
      <div class="notes-tag-strip" id="notes-tag-strip" style="display:none;"></div>
      <button class="notes-tag-add-btn" id="notes-tag-add-btn" style="display:none;">🏷 Tag note</button>

      <!-- Markdown toolbar (#10) -->
      <div class="notes-toolbar" id="notes-toolbar" style="display:none;">
        <button type="button" class="notes-tb-btn" title="Bold" data-fmt-open="**" data-fmt-close="**"><b>B</b></button>
        <button type="button" class="notes-tb-btn" title="Italic" data-fmt-open="*" data-fmt-close="*"><i>I</i></button>
        <button type="button" class="notes-tb-btn" title="Heading" data-fmt-line="## ">H</button>
        <button type="button" class="notes-tb-btn" title="List item" data-fmt-line="- ">≡</button>
        <button type="button" class="notes-tb-btn" title="Inline code" data-fmt-open="`" data-fmt-close="`">{ }</button>
        <div class="notes-tb-sep"></div>
        <button type="button" class="notes-tb-btn notes-tb-toggle" id="notes-preview-btn"
                title="Toggle preview">👁</button>
      </div>

      <!-- Edit / preview tabs (#10) -->
      <div id="notes-edit-pane">
        <textarea class="notes-textarea" id="notes-textarea"
                  placeholder="Notes for this participant…"></textarea>
      </div>
      <div id="notes-preview-pane" class="notes-preview-pane" style="display:none;"></div>

      <div class="notes-save-indicator" id="notes-save-indicator"></div>

      <!-- Session history (#12) -->
      <div class="notes-history-section" id="notes-history-section" style="display:none;">
        <div class="notes-history-header" id="notes-history-header">
          <span class="notes-history-label">Previous Sessions</span>
          <span id="notes-history-toggle">▶</span>
        </div>
        <div id="notes-history-body" style="display:none;"></div>
      </div>
    </div>

    <!-- Action Items panel (#15) -->
    <div class="sidebar-section" id="action-items-section">
      <div class="collapsible-header" id="action-items-header">
        <div class="sidebar-label">Action Items <span id="action-items-count" class="sidebar-label-badge"></span></div>
        <span class="collapsible-toggle" id="action-items-toggle">▶</span>
      </div>
      <div id="action-items-body" style="display:none;">
        <div class="action-add-row">
          <input type="text" id="action-item-input" class="action-item-input"
                 placeholder="Add action item…" maxlength="512"
                 >
          <button class="action-add-btn" id="action-item-add-btn" title="Add">+</button>
        </div>
        <div id="action-items-list"></div>
      </div>
    </div>

    <!-- Pin panel -->
    <div class="sidebar-section" id="pin-panel-section">
      <div class="collapsible-header" id="pin-panel-header">
        <div class="sidebar-label">Pinned Messages <span id="pin-count"></span></div>
        <span class="collapsible-toggle" id="pin-toggle">▼</span>
      </div>
      <div class="pin-panel" id="pin-panel"></div>
    </div>

    <!-- End session -->
    <button id="end-session-btn" class="btn btn-danger">
      End Session &amp; Export
    </button>

    <?php endif; ?>
  </div><!-- /#sidebar -->
</div><!-- /#container -->

<?php if (!$isPractitioner && !$practPresent): ?>
<?php
  $waitPractName   = htmlspecialchars($practitionerRow['display_name'] ?? 'Your practitioner');
  $waitPractAvatar = $practitionerRow['avatar_path'] ?? '';
  $waitPractInit   = mb_strtoupper(mb_substr($practitionerRow['display_name'] ?? '?', 0, 1));
?>
<!-- ── Waiting Room Overlay ── -->
<div id="waiting-overlay" class="waiting-overlay">
  <div class="waiting-card glass-panel" style="max-width:440px;">

    <!-- Practitioner identity -->
    <div style="margin-bottom:24px;">
      <?php if ($waitPractAvatar): ?>
        <img src="<?= htmlspecialchars($waitPractAvatar) ?>" alt=""
             style="width:72px;height:72px;border-radius:50%;object-fit:cover;
                    border:3px solid rgba(124,106,247,0.5);margin:0 auto 14px;display:block;">
      <?php else: ?>
        <div style="width:72px;height:72px;border-radius:50%;background:rgba(124,106,247,0.18);
                    border:3px solid rgba(124,106,247,0.5);margin:0 auto 14px;
                    display:flex;align-items:center;justify-content:center;
                    font-size:26px;font-weight:700;color:#7c6af7;"><?= $waitPractInit ?></div>
      <?php endif; ?>
      <h2 class="waiting-title" style="margin-bottom:4px;">Waiting for <?= $waitPractName ?></h2>
      <p class="waiting-sub" id="waiting-sub">Your session will begin when they join.</p>
    </div>

    <!-- Animated indicator -->
    <div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:24px;">
      <div class="waiting-pulse" style="margin:0;width:10px;height:10px;border-width:2px;animation-delay:0s;"></div>
      <div class="waiting-pulse" style="margin:0;width:10px;height:10px;border-width:2px;animation-delay:0.3s;"></div>
      <div class="waiting-pulse" style="margin:0;width:10px;height:10px;border-width:2px;animation-delay:0.6s;"></div>
    </div>

    <p class="waiting-muted" style="margin-bottom:20px;">You're in the right place. You'll be connected automatically when <?= $waitPractName ?> arrives.</p>

    <!-- Camera permission note — shown only if not yet granted -->
    <div id="waiting-media-note" style="background:rgba(124,106,247,0.08);border:1px solid rgba(124,106,247,0.2);
         border-radius:8px;padding:12px 14px;text-align:left;margin-top:4px;display:none;">
      <div style="font-size:12px;font-weight:700;color:#7c6af7;margin-bottom:5px;letter-spacing:0.3px;">📷 Camera &amp; Microphone</div>
      <p style="font-size:12px;color:rgba(221,220,242,0.6);line-height:1.55;margin:0;">
        When the session begins, your browser will ask for camera and microphone access.
        These are used <strong>only for your private session</strong> — nothing is recorded by the server.
        If you accidentally click "Block", you can re-enable access in your browser's address bar.
      </p>
    </div>
  </div>
</div>
<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
// Show the media note if permissions haven't been granted yet
if (navigator.permissions) {
    Promise.allSettled([
        navigator.permissions.query({name:'camera'}),
        navigator.permissions.query({name:'microphone'})
    ]).then(function(results) {
        var anyNotGranted = results.some(function(r) {
            return r.status === 'fulfilled' && r.value.state !== 'granted';
        });
        if (anyNotGranted) {
            var note = document.getElementById('waiting-media-note');
            if (note) note.style.display = 'block';
        }
    }).catch(function() {
        // Permissions API not available — show the note as a safe default
        var note = document.getElementById('waiting-media-note');
        if (note) note.style.display = 'block';
    });
} else {
    var note = document.getElementById('waiting-media-note');
    if (note) note.style.display = 'block';
}
</script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
(function waitForPractitioner() {
    const sessionId = <?= (int)$session['id'] ?>;
    let checkCount  = 0;

    function check() {
        fetch('/api/presence.php?session_id=' + sessionId + (ROOM_CONFIG.myJoinToken ? '&join_token=' + encodeURIComponent(ROOM_CONFIG.myJoinToken) : ''))
            .then(r => r.json())
            .then(data => {
                if (data.present) {
                    const overlay = document.getElementById('waiting-overlay');
                    if (overlay) {
                        overlay.style.opacity = '0';
                        overlay.style.transition = 'opacity 0.5s';
                        setTimeout(() => overlay.remove(), 500);
                    }
                    return; // Stop polling
                }
                checkCount++;
                if (data.practitioner_name) {
                    document.getElementById('waiting-sub').textContent =
                        'Waiting for ' + data.practitioner_name + ' to join…';
                }
                setTimeout(check, 5000);
            })
            .catch(() => setTimeout(check, 8000));
    }

    setTimeout(check, 3000); // Initial delay before first check
})();
</script>
<?php endif; ?>

<!-- Floating highlight toolbar -->
<div class="highlight-toolbar" id="highlight-toolbar">
  <div class="hl-section" id="hl-section-markup">
    <span class="hl-section-label">Markup</span>
    <div class="hl-section-btns">
      <button class="hl-btn hl-btn-yellow" title="Action Item" data-highlight-color="yellow" style="background:<?= htmlspecialchars($hlColor1) ?>80;">🟡</button>
      <button class="hl-btn hl-btn-pink"   title="Clinical Note" data-highlight-color="pink" style="background:<?= htmlspecialchars($hlColor2) ?>80;">🩷</button>
      <button class="hl-btn hl-btn-blue"   title="Reference" data-highlight-color="blue" style="background:<?= htmlspecialchars($hlColor3) ?>80;">🔵</button>
      <button class="hl-btn hl-btn-pin"    title="Pin message" id="hl-pin-btn">📌</button>
    </div>
  </div>
  <div class="hl-section" id="hl-section-react">
    <span class="hl-section-label">React</span>
    <div class="hl-section-btns">
      <button class="hl-btn react-emoji-btn" data-reaction="❤️">❤️</button>
      <button class="hl-btn react-emoji-btn" data-reaction="👍🏻">👍🏻</button>
      <button class="hl-btn react-emoji-btn" data-reaction="👎🏻">👎🏻</button>
      <button class="hl-btn react-emoji-btn" data-reaction="👌🏻">👌🏻</button>
      <button class="hl-btn react-emoji-btn" data-reaction="✅">✅</button>
      <button class="hl-btn react-emoji-btn" data-reaction="⭐️">⭐️</button>
    </div>
  </div>
  <div class="hl-section" id="hl-section-edit" style="display:none;">
    <span class="hl-section-sep">|</span>
    <div class="hl-section-btns">
      <button class="hl-btn" id="hl-edit-btn" title="Edit message">✏️</button>
      <button class="hl-btn" id="hl-delete-btn" title="Delete message">✕</button>
    </div>
  </div>
</div>

<!-- Avatar context menu -->
<div id="ctx-menu">
  <button id="ctx-edit-avatar">Change Avatar</button>
  <button id="ctx-edit-nickname" style="display:none;">Edit Nickname</button>
  <button id="ctx-webcam-enable" style="display:none;">Enable Webcam</button>
  <button id="ctx-unlink" class="danger" style="display:none;">Unlink</button>
</div>
<input type="file" id="avatar-file-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">

<!-- Room area context menu (host only — background change) -->
<?php if ($isPractitioner): ?>
<div id="room-ctx-menu">
  <button id="room-ctx-bg-btn">🖼 Change Background</button>
</div>
<?php endif; ?>

<!-- Background picker -->
<?php if ($isPractitioner): ?>
<div id="bg-picker">
  <div id="bg-picker-header">
    <span>Change Background</span>
    <button class="bg-picker-close" id="bg-picker-close-btn">✕</button>
  </div>
  <div id="bg-picker-tabs">
    <button id="bg-tab-images" class="bg-tab active" data-bg-tab="images">Images</button>
    <button id="bg-tab-videos" class="bg-tab" data-bg-tab="videos">Videos</button>
  </div>
  <div id="bg-picker-grid"></div>
  <label class="bg-upload-label">
    <span>⬆ Upload New Background</span>
    <input type="file" id="bg-file-input"
           accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm"
           style="display:none">
  </label>
  <div id="bg-upload-progress" style="display:none;padding:8px 12px;">
    <div style="height:6px;background:var(--border);border-radius:3px;overflow:hidden;margin-bottom:4px;">
      <div id="bg-upload-bar" style="height:100%;width:0%;background:var(--accent);transition:width 0.2s;border-radius:3px;"></div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);">
      <span id="bg-upload-msg">Uploading…</span>
      <span id="bg-upload-pct">0%</span>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Image lightbox -->
<div id="lightbox">
  <img id="lightbox-img" src="" alt="">
</div>

<!-- Link confirmation modal -->
<div id="link-modal" class="ss-modal" style="display:none;">
  <div class="ss-modal-box">
    <p class="ss-modal-msg" id="link-modal-msg">Link participants?</p>
    <div class="ss-modal-actions">
      <button class="btn btn-primary btn-sm" id="link-confirm-btn">Link</button>
      <button class="btn btn-ghost btn-sm" id="link-dismiss-btn">Cancel</button>
    </div>
  </div>
</div>

<!-- Delete message confirm modal -->
<div id="delete-modal" class="ss-modal" style="display:none;">
  <div class="ss-modal-box">
    <p class="ss-modal-msg">Delete this message? This cannot be undone.</p>
    <div class="ss-modal-actions">
      <button class="btn btn-danger btn-sm" id="delete-confirm-btn">Delete</button>
      <button class="btn btn-ghost btn-sm" id="delete-dismiss-btn">Cancel</button>
    </div>
  </div>
</div>

<?php if ($isPractitioner): ?>
<!-- Session Recap Modal (#4) -->
<div id="recap-modal" class="ss-modal" style="display:none;">
  <div class="ss-modal-box" style="max-width:560px;width:95vw;">
    <h2 style="font-size:16px;font-weight:700;color:var(--text);margin-bottom:4px;">End Session &amp; Export</h2>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:20px;line-height:1.5;">
      Complete the session recap before exporting. These notes are for your reference only — they appear in the export file and are not shared with the client.
    </p>

    <div class="recap-field">
      <label class="recap-label">What was explored today</label>
      <textarea id="recap-summary" class="recap-ta" rows="2"
                placeholder="Main themes, topics, or areas covered in this session…"></textarea>
    </div>

    <div class="recap-field">
      <label class="recap-label">Key insights &amp; moments</label>
      <textarea id="recap-insights" class="recap-ta" rows="2"
                placeholder="Breakthroughs, realisations, or significant moments…"></textarea>
    </div>

    <div class="recap-field">
      <label class="recap-label">Agreed actions &amp; commitments</label>
      <textarea id="recap-actions" class="recap-ta" rows="2"
                placeholder="Steps agreed on, commitments made, things to try before next session…"></textarea>
    </div>

    <div class="recap-field" style="margin-bottom:20px;">
      <label class="recap-label">Focus for next session</label>
      <textarea id="recap-next-focus" class="recap-ta" rows="2"
                placeholder="What to continue, explore, or build on next time…"></textarea>
    </div>

    <?php
    // Load existing series for this practitioner
    $seriesStmt = $pdo->prepare('SELECT id, name FROM session_series WHERE practitioner_id = ? ORDER BY created_at DESC');
    $seriesStmt->execute([(int)$_SESSION['practitioner_id']]);
    $existingSeries = $seriesStmt->fetchAll();
    ?>
    <?php if ($existingSeries): ?>
    <div class="recap-field" style="margin-bottom:20px;">
      <label class="recap-label">Add to a session series <span style="font-weight:400;opacity:0.6;">(optional)</span></label>
      <select id="recap-series-id" style="width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 10px;color:var(--text);font-size:13px;font-family:inherit;">
        <option value="">— Don't assign to a series —</option>
        <?php foreach ($existingSeries as $ser): ?>
          <option value="<?= (int)$ser['id'] ?>"><?= htmlspecialchars($ser['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div class="ss-modal-actions" style="gap:8px;">
      <button class="btn btn-danger btn-sm" id="recap-submit-btn">Export &amp; Close Session</button>
      <button class="btn btn-ghost btn-sm" id="recap-dismiss-btn">Cancel</button>
    </div>
    <p style="font-size:11px;color:var(--text-muted);margin-top:12px;line-height:1.4;text-align:center;">
      Session records are retained for 7 years in accordance with professional therapeutic guidelines. File attachments are removed from the server after export.
    </p>
  </div>
</div>
<style nonce="<?= $GLOBALS['csp_nonce'] ?>">
.recap-field { margin-bottom:14px; }
.recap-label { display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:6px; }
.recap-ta { width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px 12px;color:var(--text);font-size:13px;font-family:inherit;resize:vertical;line-height:1.5;box-sizing:border-box; }
.recap-ta:focus { outline:none;border-color:var(--accent);box-shadow:0 0 0 2px rgba(124,106,247,0.12); }
</style>
<?php endif; ?>
<style nonce="<?= $GLOBALS['csp_nonce'] ?>">
/* ── Crisis banner ── */
.crisis-banner {
  display: flex; align-items: stretch;
  background: #080808;
  border: 1px solid rgba(62,207,142,0.22);
  border-radius: 12px; overflow: hidden;
  margin: 6px 0;
}
.crisis-banner-icon {
  width: 20%; min-width: 56px; max-width: 80px;
  display: flex; align-items: center; justify-content: center;
  padding: 16px 8px;
  background: rgba(62,207,142,0.07);
  border-right: 1px solid rgba(62,207,142,0.15);
}
.crisis-banner-icon img {
  width: 32px; height: 32px; object-fit: contain;
  filter: drop-shadow(0 0 6px rgba(62,207,142,0.55));
}
.crisis-banner-content {
  flex: 1; padding: 14px 16px; text-align: center;
}
.crisis-banner-title {
  font-size: 10px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 1.2px; color: #3ecf8e; margin-bottom: 8px;
}
.crisis-banner-body {
  font-size: 13px; color: #ffffff; line-height: 1.55;
  white-space: pre-wrap; word-break: break-word;
}

/* ── Crisis panel ── */
#crisis-panel {
  position: absolute; bottom: calc(100% + 8px); left: 0;
  width: 340px; max-width: calc(100vw - 16px);
  background: var(--surface2,#141c38); border: 1px solid var(--border,rgba(255,255,255,.1));
  border-radius: 10px; padding: 0; overflow: hidden;
  box-shadow: 0 8px 32px rgba(0,0,0,0.5); z-index: 200;
}
.crisis-panel-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 14px; border-bottom: 1px solid var(--border,rgba(255,255,255,.08));
  background: rgba(224,122,48,0.08);
}
.crisis-panel-title { font-size: 12px; font-weight: 700; color: #e07a30; }
.crisis-panel-close {
  background: none; border: none; color: var(--text-muted,#aaa); cursor: pointer;
  font-size: 18px; line-height: 1; padding: 0 2px;
}
#crisis-panel-list { padding: 6px 0; }
.crisis-resource-item {
  display: flex; align-items: center; padding: 10px 14px; cursor: pointer;
  transition: background 0.12s; gap: 10px; border: none; background: none;
  width: 100%; text-align: left;
}
.crisis-resource-item:hover { background: rgba(224,122,48,0.12); }
.crisis-resource-item-label { font-size: 13px; color: var(--text,#e8e6f4); flex: 1; }
.crisis-resource-item-send { font-size: 11px; color: var(--text-muted,#aaa); flex-shrink: 0; }
#input-area { position: relative; }

/* ── Media Recommendation Widget ── */
.media-rec-widget {
  background: linear-gradient(135deg, #2a1b5e 0%, #100d22 45%, #060409 100%);
  border: 1px solid rgba(124,106,247,0.3);
  border-radius: 10px;
  overflow: hidden;
  width: 100%;
  max-width: 45%;
  margin-top: 4px;
  display: inline-block;
}
.mrw-inner { display: flex; min-height: 90px; }
.mrw-cover {
  width: 95px; flex-shrink: 0;
  background: rgba(0,0,0,0.25);
  position: relative; overflow: hidden;
  display: flex; align-items: center; justify-content: center;
}
.mrw-cover img { width: 100%; height: 100%; object-fit: cover; display: block; }
.mrw-cover-ph { font-size: 24px; color: rgba(255,255,255,0.15); position: absolute; }
.mrw-content { padding: 10px 12px; flex: 1; min-width: 0; }
.mrw-badge {
  font-size: 9.5px; font-weight: 700; letter-spacing: 0.06em;
  text-transform: uppercase; color: rgba(124,106,247,0.85); margin-bottom: 5px;
}
.mrw-title {
  font-size: 13px; font-weight: 700; color: #dddcf2;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px;
}
.mrw-author {
  font-size: 11.5px; color: rgba(221,220,242,0.6);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 5px;
}
.mrw-meta { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 5px; }
.mrw-meta span { font-size: 10.5px; color: rgba(221,220,242,0.4); }
.mrw-meta-sep { color: rgba(221,220,242,0.2) !important; }
.mrw-desc {
  font-size: 11px; color: rgba(221,220,242,0.5); line-height: 1.5;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.mrw-note {
  font-size: 11px; color: rgba(221,220,242,0.55); font-style: italic;
  margin-top: 5px; padding-top: 5px; border-top: 1px solid rgba(124,106,247,0.15);
  line-height: 1.5;
}
.mrw-amazon {
  display: block; margin-top: 6px; font-size: 11px; font-weight: 600;
  color: rgba(245,200,66,0.75); text-decoration: none;
}
.mrw-amazon:hover { color: #f5c842; }

/* ── Media search result rows (inside modal) ── */
.mrm-result-row {
  display: flex; gap: 12px; padding: 11px 20px;
  border-bottom: 1px solid rgba(255,255,255,0.05);
  cursor: pointer; transition: background 0.12s; align-items: flex-start;
}
.mrm-result-row:hover { background: rgba(124,106,247,0.07); }
.mrm-result-cover {
  width: 42px; height: 60px; border-radius: 4px;
  background: rgba(255,255,255,0.05); flex-shrink: 0;
  overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.mrm-result-cover img { width: 100%; height: 100%; object-fit: cover; }
.mrm-result-info { flex: 1; min-width: 0; }
.mrm-result-title { font-size: 13px; font-weight: 700; color: #dddcf2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mrm-result-author { font-size: 12px; color: rgba(221,220,242,0.55); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mrm-result-chips { display: flex; gap: 5px; margin-top: 5px; flex-wrap: wrap; }
.mrm-result-chips span { font-size: 10.5px; color: rgba(221,220,242,0.4); background: rgba(255,255,255,0.05); padding: 1px 6px; border-radius: 3px; }
.mrm-detail-inner { padding: 16px 20px; }
.mrm-detail-top { display: flex; gap: 16px; margin-bottom: 14px; }
.mrm-detail-cover { width: 90px; border-radius: 7px; flex-shrink: 0; overflow: hidden; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; font-size: 32px; }
.mrm-detail-cover img { width: 100%; display: block; }
.mrm-detail-info { flex: 1; }
.mrm-detail-title { font-size: 15px; font-weight: 700; color: #dddcf2; margin-bottom: 5px; }
.mrm-detail-author { font-size: 13px; color: rgba(221,220,242,0.6); margin-bottom: 8px; }
.mrm-detail-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.mrm-detail-chip { font-size: 11px; background: rgba(124,106,247,0.1); border: 1px solid rgba(124,106,247,0.22); border-radius: 4px; padding: 2px 8px; color: rgba(221,220,242,0.6); }
.mrm-detail-desc { font-size: 12.5px; color: rgba(221,220,242,0.6); line-height: 1.65; margin-bottom: 14px; max-height: 140px; overflow-y: auto; }

/* ── Concept tag pills ── */
.concept-tag-pills {
  display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; padding: 0 0 2px;
}
.concept-tag-pill {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 10px; font-family: var(--mono,monospace); font-weight: 700;
  letter-spacing: 0.4px; padding: 2px 7px; border-radius: 12px;
  cursor: default; user-select: none;
}
.concept-tag-pill .pill-remove {
  cursor: pointer; opacity: 0.6; font-size: 11px; line-height: 1; margin-left: 1px;
}
.concept-tag-pill .pill-remove:hover { opacity: 1; }

/* ── Tag button on message hover ── */
.chat-message .msg-tag-btn {
  display: none; background: none; border: none;
  cursor: pointer; font-size: 12px; opacity: 0.55;
  padding: 0 3px; margin-left: 4px; vertical-align: middle;
  transition: opacity 0.12s;
}
.chat-message:hover .msg-tag-btn { display: inline; }
.chat-message .msg-tag-btn:hover { opacity: 1; }
/* Always show tag button when tags are present */
.chat-message .msg-tag-btn.has-tags {
  display: inline; opacity: 0.75;
  font-size: 11px; color: var(--accent,#7c6af7);
}

/* ── Tag popover ── */
.tag-popover {
  position: fixed; z-index: 300;
  background: var(--surface2,#141c38); border: 1px solid var(--border,rgba(255,255,255,.1));
  border-radius: 10px; padding: 8px; min-width: 200px; max-width: 260px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.55);
}
.tag-popover-title {
  font-size: 10px; font-family: var(--mono,monospace); font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-muted,#aaa);
  padding: 2px 4px 6px; border-bottom: 1px solid var(--border,rgba(255,255,255,.08));
  margin-bottom: 4px;
}
.tag-option {
  display: flex; align-items: center; gap: 8px; padding: 6px 8px;
  border-radius: 6px; cursor: pointer; transition: background 0.1s; user-select: none;
}
.tag-option:hover { background: rgba(255,255,255,0.06); }
.tag-option-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.tag-option-name { font-size: 13px; color: var(--text,#e8e6f4); flex: 1; }
.tag-option-check { font-size: 12px; color: var(--accent,#7c6af7); }
.tag-new-row {
  display: flex; gap: 6px; margin-top: 6px; padding-top: 6px;
  border-top: 1px solid var(--border,rgba(255,255,255,.08));
}
.tag-new-input {
  flex: 1; background: var(--surface,rgba(255,255,255,.05));
  border: 1px solid var(--border,rgba(255,255,255,.1)); border-radius: 6px;
  padding: 5px 8px; color: var(--text,#e8e6f4); font-size: 12px; font-family: inherit; outline: none;
}
.tag-new-input:focus { border-color: var(--accent,#7c6af7); }
.tag-new-btn {
  background: var(--accent,#7c6af7); border: none; border-radius: 6px;
  color: #fff; font-size: 11px; font-weight: 700; padding: 5px 10px; cursor: pointer;
}

/* ── Note tag strip ── */
.notes-tag-strip {
  display: flex; flex-wrap: wrap; gap: 4px; padding: 6px 0 2px;
  min-height: 22px;
}
.notes-tag-add-btn {
  font-size: 11px; background: none; border: 1px dashed var(--border,rgba(255,255,255,.15));
  border-radius: 10px; color: var(--text-muted,#aaa); cursor: pointer;
  padding: 2px 8px; transition: border-color 0.12s, color 0.12s;
}
.notes-tag-add-btn:hover { border-color: var(--accent,#7c6af7); color: var(--accent,#7c6af7); }
</style>

<!-- Edit nickname modal -->
<div id="nickname-modal" class="ss-modal" style="display:none;">
  <div class="ss-modal-box">
    <p class="ss-modal-msg">Enter new nickname:</p>
    <input type="text" id="nickname-input" class="ss-modal-input" maxlength="64" placeholder="Your name">
    <div class="ss-modal-actions">
      <button class="btn btn-primary btn-sm" id="nickname-confirm-btn">Save</button>
      <button class="btn btn-ghost btn-sm" id="nickname-dismiss-btn">Cancel</button>
    </div>
  </div>
</div>

<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
// ── Configuration passed from PHP ──────────────────────────────
window.ROOM_CONFIG = {
  isPractitioner:    <?= $isPractitioner ? 'true' : 'false' ?>,
  sessionId:         <?= $sessionId ?>,
  myParticipantId:   <?= $myParticipantId ?? 'null' ?>,
  myJoinToken:       <?= $myJoinToken ? json_encode($myJoinToken) : 'null' ?>,
  roomId:            <?= (int)$room['id'] ?>,
  roomName:          <?= json_encode($room['name']) ?>,
  csrfToken:         <?= json_encode($csrfToken) ?>,
  initialParticipants: <?= $participantsJson ?>,
  initialMessages:     <?= $messagesJson ?>,
  initialPins:         <?= $pinsJson ?>,
  initialNotes:        <?= json_encode($notes) ?>,
  lastEventId:         <?= $lastEventId ?>,
  avatarPresets:       <?= avatarPresetsJson() ?>,
  hostParticipantId:   <?= $hostParticipantId ?? 'null' ?>,
  inviteUrl:           <?= json_encode($inviteUrl) ?>,
  backgroundPath:      <?= json_encode($bgPath) ?>,
  backgroundMime:      <?= json_encode($bgMime) ?>,
  features:            <?= json_encode($featureFlags) ?>,
  hlColors:            <?= json_encode(['color1' => $hlColor1, 'color2' => $hlColor2, 'color3' => $hlColor3]) ?>,
  sessionBegun:        <?= ($isPractitioner || $sessionBegun) ? 'true' : 'false' ?>,
  conceptTagLibrary:   <?= json_encode(array_values($conceptTagLibrary)) ?>,
  noteConceptTags:     <?= json_encode($noteConceptTags) ?>,
  practitionerRoleIcon: <?= json_encode($practitionerRoleIcon) ?>,
  dcBaseUrl:           <?= json_encode($dcBaseUrl ?? null) ?>,
  aiConfig:            <?= json_encode($aiConfig) ?>,
  mediaConfig:         <?= json_encode($mediaConfig) ?>,
};
</script>
<!-- ── Welcome Overlay ── -->
<?php if ($isPractitioner && $mediaConfig): ?>
<!-- ── Media Recommendation Modal ── -->
<div id="media-rec-modal" style="display:none;position:fixed;inset:0;z-index:10500;">
  <div id="mrm-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,0.65);"></div>
  <div id="mrm-panel" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:540px;max-width:calc(100vw - 24px);max-height:84vh;background:#0d1433;border:1px solid rgba(124,106,247,0.25);border-radius:14px;display:flex;flex-direction:column;overflow:hidden;">
    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid rgba(255,255,255,0.07);flex-shrink:0;">
      <div style="font-size:15px;font-weight:700;color:#dddcf2;">Recommend Media</div>
      <button id="mrm-close-btn" style="background:none;border:none;color:rgba(221,220,242,0.4);font-size:20px;cursor:pointer;padding:0 4px;line-height:1;">&times;</button>
    </div>
    <!-- Type tabs -->
    <div id="mrm-tabs" style="display:flex;padding:12px 20px 0;flex-shrink:0;border-bottom:1px solid rgba(255,255,255,0.06);">
      <button class="mrm-tab active" id="mrm-tab-books" data-mrm-type="books" style="padding:8px 18px;border:none;border-bottom:2px solid #7c6af7;background:transparent;color:#7c6af7;font-size:13px;font-weight:600;cursor:pointer;">📚 Books</button>
      <button class="mrm-tab" id="mrm-tab-video" data-mrm-type="video" style="padding:8px 18px;border:none;border-bottom:2px solid transparent;background:transparent;color:rgba(221,220,242,0.45);font-size:13px;font-weight:600;cursor:pointer;">🎬 Movies &amp; TV</button>
      <button class="mrm-tab" id="mrm-tab-refs" data-mrm-type="refs" style="padding:8px 18px;border:none;border-bottom:2px solid transparent;background:transparent;color:rgba(221,220,242,0.45);font-size:13px;font-weight:600;cursor:pointer;">⭐ References</button>
    </div>
    <!-- Search -->
    <div style="display:flex;gap:8px;padding:12px 20px;flex-shrink:0;">
      <input type="text" id="mrm-query" placeholder="Search…" style="flex:1;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);border-radius:8px;padding:9px 14px;color:#dddcf2;font-size:13px;outline:none;font-family:inherit;">
      <button id="mrm-search-btn" style="padding:9px 18px;background:rgba(124,106,247,0.2);border:1px solid rgba(124,106,247,0.4);border-radius:8px;color:#c5b8ff;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;">Search</button>
    </div>
    <!-- Results / Detail -->
    <div id="mrm-body" style="overflow-y:auto;flex:1;"></div>
    <!-- Detail note + actions -->
    <div id="mrm-detail-footer" style="display:none;padding:14px 20px;border-top:1px solid rgba(255,255,255,0.07);flex-shrink:0;">
      <textarea id="mrm-note" rows="2" placeholder="Add a note (optional): I think you'll like this because…" style="width:100%;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;padding:9px 12px;color:#dddcf2;font-size:12.5px;font-family:inherit;resize:none;outline:none;box-sizing:border-box;margin-bottom:10px;"></textarea>
      <div style="display:flex;gap:10px;">
        <button id="mrm-share-btn" style="flex:1;padding:10px;background:rgba(124,106,247,0.2);border:1px solid rgba(124,106,247,0.4);border-radius:8px;color:#c5b8ff;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;">Share Recommendation</button>
        <button id="mrm-back-btn" style="padding:10px 18px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:rgba(221,220,242,0.6);font-size:13px;cursor:pointer;font-family:inherit;">← Back</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div id="welcome-overlay" class="welcome-overlay" aria-live="polite">
  <div class="welcome-card">
    <div class="welcome-text" id="welcome-text"></div>
    <div class="welcome-sub" id="welcome-sub-1" style="min-height:1.9em;"></div>
    <div class="welcome-sub" id="welcome-sub-2" style="min-height:1.9em;"></div>
  </div>
</div>

<script src="/assets/js/room.js"></script>
<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
// ── Welcome overlay — typewriter effect ───────────────────────
(function() {
    const overlay = document.getElementById('welcome-overlay');
    if (!overlay) return;

    requestAnimationFrame(() => overlay.classList.add('welcome-visible'));

    function typeOut(el, text, baseMs, onDone) {
        let i = 0;
        function next() {
            if (i >= text.length) { if (onDone) setTimeout(onDone, 0); return; }
            el.textContent += text[i];
            const ch = text[i++];
            let delay = baseMs + (Math.random() * 18 - 9);
            if (ch === '.' || ch === '!' || ch === '?') delay = baseMs * 6;
            else if (ch === ',') delay = baseMs * 2.8;
            else if (ch === ' ') delay = baseMs * 1.4;
            setTimeout(next, Math.max(12, delay));
        }
        setTimeout(next, 0);
    }

    const BASE = 46;
    // Type first line, then after a beat type the two subtitles
    setTimeout(() => {
        typeOut(document.getElementById('welcome-text'), 'Welcome to your session', BASE, () => {
            setTimeout(() => {
                typeOut(document.getElementById('welcome-sub-1'), 'Take a moment to settle in.', BASE, () => {
                    setTimeout(() => {
                        typeOut(document.getElementById('welcome-sub-2'), "There's no rush.", BASE + 14, null);
                    }, 360);
                });
            }, 520);
        });
    }, 180);

    // Fade out after 10s
    setTimeout(() => dismissWelcome(), 10000);

    overlay.addEventListener('click', dismissWelcome);
    document.addEventListener('keydown', dismissWelcome, { once: true });
    function dismissWelcome() {
        if (!overlay.parentNode) return; // already removed
        overlay.removeEventListener('click', dismissWelcome);
        overlay.style.opacity = '0';
        setTimeout(() => {
            overlay.remove();
            document.dispatchEvent(new CustomEvent('ss:welcome-dismissed'));
        }, 800);
    }
})();

// ── Begin Session ─────────────────────────────────────────────
function beginSession() {
    fetch('/api/session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action:     'begin',
            session_id: ROOM_CONFIG.sessionId,
            csrf_token: ROOM_CONFIG.csrfToken,
        }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok || data.error === undefined) {
            const section = document.getElementById('begin-session-section');
            if (section) section.remove();
            document.getElementById('container')?.classList.remove('session-not-begun');
            document.getElementById('chat-input')?.removeAttribute('disabled');
            if (typeof addSystemMessage === 'function') {
                addSystemMessage('✨ Session has begun');
            }
        }
    })
    .catch(() => {
        // Fail open — remove gate even if API call fails
        const section = document.getElementById('begin-session-section');
        if (section) section.remove();
        document.getElementById('container')?.classList.remove('session-not-begun');
        document.getElementById('chat-input')?.removeAttribute('disabled');
    });
}

// Apply not-begun dim on load
(function() {
    if (!ROOM_CONFIG.sessionBegun) {
        document.getElementById('container')?.classList.add('session-not-begun');
        const input = document.getElementById('chat-input');
        if (input) input.setAttribute('disabled', 'disabled');
    }
})();

// ── Static element listeners ──────────────────────────────────
// Input area
document.getElementById('file-btn')?.addEventListener('click', openFilePicker);
document.getElementById('crisis-panel-close-btn')?.addEventListener('click', hideCrisisPanel);
document.getElementById('crisis-btn')?.addEventListener('click', toggleCrisisPanel);
document.getElementById('media-rec-btn')?.addEventListener('click', openMediaRecModal);
document.getElementById('voice-btn')?.addEventListener('click', toggleVoiceRecording);
document.getElementById('emoji-btn')?.addEventListener('click', toggleEmojiPicker);

// Sidebar
document.getElementById('copy-invite-btn')?.addEventListener('click', copyInviteLink);
document.getElementById('begin-session-btn')?.addEventListener('click', beginSession);
document.getElementById('duration-start-btn')?.addEventListener('click', durationStart);
document.getElementById('duration-pause-btn')?.addEventListener('click', durationPause);
document.getElementById('duration-stop-btn')?.addEventListener('click', durationStop);
document.getElementById('voice-join-btn')?.addEventListener('click', toggleVoiceChat);
document.getElementById('show-activity-picker-btn')?.addEventListener('click', showActivityPicker);
document.getElementById('notes-tag-add-btn')?.addEventListener('click', function() { showNoteTagPopover(this); });
document.getElementById('notes-preview-btn')?.addEventListener('click', toggleNotesPreview);
document.getElementById('notes-history-header')?.addEventListener('click', toggleNotesHistory);
document.getElementById('action-items-header')?.addEventListener('click', toggleActionItems);
document.getElementById('action-item-input')?.addEventListener('keydown', e => { if (e.key === 'Enter') addActionItem(); });
document.getElementById('action-item-add-btn')?.addEventListener('click', addActionItem);
document.getElementById('pin-panel-header')?.addEventListener('click', togglePinPanel);
document.getElementById('end-session-btn')?.addEventListener('click', endSession);

// Activity picker (delegated)
document.getElementById('activity-picker')?.addEventListener('click', e => {
    const btn = e.target.closest('[data-activity]');
    if (btn) launchActivity(btn.dataset.activity);
});

// Notes toolbar (delegated)
document.querySelector('.notes-toolbar')?.addEventListener('click', e => {
    const btn = e.target.closest('.notes-tb-btn');
    if (!btn) return;
    if (btn.dataset.fmtOpen !== undefined) notesFmt(btn.dataset.fmtOpen, btn.dataset.fmtClose);
    else if (btn.dataset.fmtLine !== undefined) notesFmtLine(btn.dataset.fmtLine);
});

// Highlight toolbar
document.getElementById('hl-section-markup')?.addEventListener('click', e => {
    const btn = e.target.closest('[data-highlight-color]');
    if (btn) applyHighlight(btn.dataset.highlightColor);
});
document.getElementById('hl-pin-btn')?.addEventListener('click', pinFromSelection);
document.getElementById('hl-section-react')?.addEventListener('click', e => {
    const btn = e.target.closest('[data-reaction]');
    if (btn) applyReaction(btn.dataset.reaction);
});
document.getElementById('hl-edit-btn')?.addEventListener('click', startEditMessage);
document.getElementById('hl-delete-btn')?.addEventListener('click', startDeleteMessage);

// Avatar / context menus
document.getElementById('ctx-edit-avatar')?.addEventListener('click', triggerAvatarUpload);
document.getElementById('ctx-edit-nickname')?.addEventListener('click', editNickname);
document.getElementById('ctx-webcam-enable')?.addEventListener('click', toggleWebcam);
document.getElementById('ctx-unlink')?.addEventListener('click', unlinkAvatar);
document.getElementById('room-ctx-bg-btn')?.addEventListener('click', openBgPicker);

// Background picker
document.getElementById('bg-picker-close-btn')?.addEventListener('click', closeBgPicker);
document.getElementById('bg-picker-tabs')?.addEventListener('click', e => {
    const btn = e.target.closest('[data-bg-tab]');
    if (btn) switchBgTab(btn.dataset.bgTab);
});
document.getElementById('bg-file-input')?.addEventListener('change', function() { uploadBackground(this); });

// Lightbox
document.getElementById('lightbox')?.addEventListener('click', closeLightbox);

// Modals
document.getElementById('link-confirm-btn')?.addEventListener('click', confirmLink);
document.getElementById('link-dismiss-btn')?.addEventListener('click', dismissLinkModal);
document.getElementById('delete-confirm-btn')?.addEventListener('click', confirmDeleteMessage);
document.getElementById('delete-dismiss-btn')?.addEventListener('click', dismissDeleteModal);
document.getElementById('recap-submit-btn')?.addEventListener('click', submitRecapAndExport);
document.getElementById('recap-dismiss-btn')?.addEventListener('click', dismissRecapModal);
document.getElementById('nickname-confirm-btn')?.addEventListener('click', confirmNickname);
document.getElementById('nickname-dismiss-btn')?.addEventListener('click', dismissNicknameModal);

// Media rec modal
document.getElementById('mrm-backdrop')?.addEventListener('click', closeMediaRecModal);
document.getElementById('mrm-close-btn')?.addEventListener('click', closeMediaRecModal);
document.getElementById('mrm-tabs')?.addEventListener('click', e => {
    const btn = e.target.closest('[data-mrm-type]');
    if (btn) mrmSetType(btn.dataset.mrmType, btn);
});
document.getElementById('mrm-query')?.addEventListener('keydown', e => { if (e.key === 'Enter') mrmSearch(); });
// While the References tab is active, filter live as the practitioner types
document.getElementById('mrm-query')?.addEventListener('input', () => {
    if (typeof _mrmType !== 'undefined' && _mrmType === 'refs') mrmSearch();
});
document.getElementById('mrm-search-btn')?.addEventListener('click', mrmSearch);
document.getElementById('mrm-share-btn')?.addEventListener('click', mrmShare);
document.getElementById('mrm-back-btn')?.addEventListener('click', mrmShowResults);
</script>
</body>
</html>
