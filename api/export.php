<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/events.php';
require_once __DIR__ . '/../db/audit.php';
require_once __DIR__ . '/../includes/phi_crypto.php';

// Only practitioners
if (empty($_SESSION['practitioner_id'])) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'POST required';
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$sessionId = (int)($input['session_id'] ?? 0);
$roomId    = (int)($input['room_id'] ?? 0);
$recapData = $input['recap'] ?? [];
$recapSummary = is_string($recapData['key_summary']    ?? null) ? trim($recapData['key_summary'])    : '';
$recapInsights= is_string($recapData['key_insights']   ?? null) ? trim($recapData['key_insights'])   : '';
$recapActions = is_string($recapData['agreed_actions'] ?? null) ? trim($recapData['agreed_actions']) : '';
$recapFocus   = is_string($recapData['next_focus']     ?? null) ? trim($recapData['next_focus'])     : '';
$recapSeries  = isset($recapData['series_id']) && (int)$recapData['series_id'] > 0 ? (int)$recapData['series_id'] : null;

if ($sessionId === 0 || $roomId === 0) {
    http_response_code(400);
    echo 'session_id and room_id required';
    exit;
}

$pdo = getDB();

// Verify practitioner owns this room/session
$stmt = $pdo->prepare(
    'SELECT r.name as room_name, s.started_at, s.ended_at
     FROM rooms r JOIN sessions s ON s.room_id = r.id
     WHERE s.id = ? AND r.id = ? AND r.practitioner_id = ? LIMIT 1'
);
$stmt->execute([$sessionId, $roomId, (int)$_SESSION['practitioner_id']]);
$sessionInfo = $stmt->fetch();
if (!$sessionInfo) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// ── Fetch all session data ──────────────────────────────────────
// Messages
$stmt = $pdo->prepare(
    'SELECT m.*, p.display_name, p.avatar_path
     FROM messages m LEFT JOIN participants p ON p.id = m.participant_id
     WHERE m.session_id = ? ORDER BY m.sent_at ASC'
);
$stmt->execute([$sessionId]);
$messages = $stmt->fetchAll();
foreach ($messages as &$_xm) {
    if (($_xm['message_type'] ?? 'text') === 'text') {
        $_xm['content'] = phi_decrypt($_xm['content'] ?? '', 'messages:' . $sessionId);
    }
}
unset($_xm);

// Highlights
$msgIds = array_column($messages, 'id');
$hlMap  = [];
if ($msgIds) {
    $placeholders = implode(',', array_fill(0, count($msgIds), '?'));
    $stmtH = $pdo->prepare(
        "SELECT * FROM highlights WHERE message_id IN ($placeholders) ORDER BY start_offset ASC"
    );
    $stmtH->execute($msgIds);
    foreach ($stmtH->fetchAll() as $hl) {
        $hlMap[(int)$hl['message_id']][] = $hl;
    }
}

// Pins
$stmt = $pdo->prepare(
    'SELECT m.id, m.content, m.sent_at, p.display_name, pi.annotation, pi.created_at as pinned_at
     FROM messages m
     LEFT JOIN participants p ON p.id = m.participant_id
     LEFT JOIN pins pi ON pi.message_id = m.id
     WHERE m.session_id = ? AND m.is_pinned = 1 ORDER BY m.sent_at ASC'
);
$stmt->execute([$sessionId]);
$pins = $stmt->fetchAll();

// Participants
$stmt = $pdo->prepare('SELECT * FROM participants WHERE session_id = ? ORDER BY joined_at ASC');
$stmt->execute([$sessionId]);
$participants = $stmt->fetchAll();

// Notes
$stmt = $pdo->prepare(
    'SELECT n.*, p.display_name
     FROM practitioner_notes n
     JOIN participants p ON p.id = n.participant_id
     WHERE n.session_id = ? ORDER BY p.display_name ASC'
);
$stmt->execute([$sessionId]);
$notes = $stmt->fetchAll();
foreach ($notes as &$_xn) {
    $_xn['note_content'] = phi_decrypt($_xn['note_content'] ?? '', 'notes:' . $sessionId);
}
unset($_xn);

// ── Compute duration ───────────────────────────────────────────
$startedAt = new DateTime($sessionInfo['started_at']);
$endedAt   = new DateTime(); // now
$diff      = $startedAt->diff($endedAt);
$duration  = '';
if ($diff->h > 0) $duration .= $diff->h . 'h ';
$duration .= $diff->i . 'm';

// ── Helper: apply highlights to message text ───────────────────
function applyHighlightsToText(string $text, array $highlights): string {
    if (empty($highlights)) return htmlspecialchars($text);

    // Sort by start_offset
    usort($highlights, fn($a,$b) => (int)$a['start_offset'] - (int)$b['start_offset']);

    // Build annotated segments
    $segments = [];
    $pos = 0;
    $len = mb_strlen($text);

    foreach ($highlights as $hl) {
        $start = min((int)$hl['start_offset'], $len);
        $end   = min((int)$hl['end_offset'], $len);
        if ($start >= $end) continue;

        if ($start > $pos) {
            $segments[] = ['text' => mb_substr($text, $pos, $start - $pos), 'color' => null];
        }
        $segments[] = ['text' => mb_substr($text, $start, $end - $start), 'color' => $hl['color']];
        $pos = $end;
    }
    if ($pos < $len) {
        $segments[] = ['text' => mb_substr($text, $pos), 'color' => null];
    }

    $colorMap = [
        'yellow'  => '#ffd600',
        'pink'    => '#ff69b4',
        'blue'    => '#1e90ff',
        'crimson' => '#8b0000',
    ];

    $html = '';
    foreach ($segments as $seg) {
        if ($seg['color'] && isset($colorMap[$seg['color']])) {
            $bg = $colorMap[$seg['color']];
            $html .= '<mark style="background:' . $bg . '30;padding:0 2px;border-radius:2px;">'
                   . htmlspecialchars($seg['text']) . '</mark>';
        } else {
            $html .= htmlspecialchars($seg['text']);
        }
    }
    return $html;
}

// ── Capture session metadata snapshot (for client portal) ────────
$metaStmt = $pdo->prepare(
    'SELECT p.display_name, r.background_path
     FROM rooms r JOIN practitioners p ON p.id = r.practitioner_id
     WHERE r.id = ? LIMIT 1'
);
$metaStmt->execute([$roomId]);
$metaRow = $metaStmt->fetch();
if ($metaRow) {
    $pdo->prepare(
        'INSERT INTO session_metadata (session_id, practitioner_name, room_bg_path)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE practitioner_name = VALUES(practitioner_name), room_bg_path = VALUES(room_bg_path)'
    )->execute([$sessionId, $metaRow['display_name'] ?: '', $metaRow['background_path']]);
}

// ── Emit session_end event (clients see this and show end screen) ─
emitEvent($pdo, $sessionId, 'session_end', []);

// ── Mark session ended ─────────────────────────────────────────
$pdo->prepare('UPDATE sessions SET ended_at = NOW() WHERE id = ?')->execute([$sessionId]);

// ── Audit log: export + session end ────────────────────────────
audit_log($pdo, 'session.export', [
    'practitioner_id' => (int)$_SESSION['practitioner_id'],
    'session_id'      => $sessionId,
    'entity_type'     => 'session',
    'entity_id'       => $sessionId,
]);

// ── Audit log: data deletion ───────────────────────────────────
audit_log($pdo, 'session.data_delete', [
    'practitioner_id' => (int)$_SESSION['practitioner_id'],
    'session_id'      => $sessionId,
    'entity_type'     => 'session',
    'entity_id'       => $sessionId,
]);

// ── Record export timestamp on session ────────────────────────
$pdo->prepare('UPDATE sessions SET transcript_exported_at = NOW() WHERE id = ?')
    ->execute([$sessionId]);

// ── Save session recap (#4) ────────────────────────────────────
if ($recapSummary || $recapInsights || $recapActions || $recapFocus || $recapSeries) {
    // Validate series belongs to this practitioner
    $validSeries = null;
    if ($recapSeries) {
        $sChk = $pdo->prepare('SELECT id FROM session_series WHERE id = ? AND practitioner_id = ? LIMIT 1');
        $sChk->execute([$recapSeries, (int)$_SESSION['practitioner_id']]);
        $validSeries = $sChk->fetch() ? $recapSeries : null;
    }
    $pdo->prepare(
        'INSERT INTO session_recap (session_id, key_summary, key_insights, agreed_actions, next_focus, series_id)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE key_summary=VALUES(key_summary), key_insights=VALUES(key_insights),
             agreed_actions=VALUES(agreed_actions), next_focus=VALUES(next_focus), series_id=VALUES(series_id)'
    )->execute([$sessionId, $recapSummary ?: null, $recapInsights ?: null,
                $recapActions ?: null, $recapFocus ?: null, $validSeries]);
    // Link session to series if set
    if ($validSeries) {
        $pdo->prepare('UPDATE sessions SET series_id = ? WHERE id = ?')
            ->execute([$validSeries, $sessionId]);
    }
}

// ── Pre-read image files for embedding before deletion ─────────
// Images up to 5 MB are base64-embedded into the export so clients have a copy
// even after the server-side file is removed.
$fileEmbeds = []; // message_id => ['data' => base64_string, 'mime' => mime_type]
foreach ($messages as $msg) {
    $type = $msg['message_type'] ?? 'text';
    if ($type === 'file' && !empty($msg['content'])) {
        $path = $msg['content'];
        $mime = $msg['mime_type'] ?? '';
        if (strpos($path, '/assets/uploads/') === 0) {
            $fsDiskPath = __DIR__ . '/..' . $path;
            if (file_exists($fsDiskPath) && in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'])) {
                if (filesize($fsDiskPath) <= 5 * 1024 * 1024) {
                    $fileEmbeds[(int)$msg['id']] = [
                        'data' => base64_encode(file_get_contents($fsDiskPath)),
                        'mime' => $mime,
                    ];
                }
            }
        }
    }
}

// ── Delete shared file attachments from disk after export ─────────────────
// Session records (messages, notes, participants, highlights, voice notes) are retained in
// the DB for 7 years per professional therapeutic record-keeping guidelines (BACP).
// Only file-type uploads (images, PDFs, documents) are removed from disk after export
// to reclaim storage — their metadata (name, size, type) remains in the session record.
foreach ($messages as $msg) {
    $type = $msg['message_type'] ?? 'text';
    if ($type === 'file' && !empty($msg['content'])) {
        $path = $msg['content'];
        if (strpos($path, '/assets/uploads/') === 0) {
            $filePath = __DIR__ . '/..' . $path;
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
    }
}

// ── Generate HTML transcript ───────────────────────────────────
$roomName    = htmlspecialchars($sessionInfo['room_name']);
$dateStr     = $startedAt->format('F j, Y');
$timeStr     = $startedAt->format('g:i A');
$participantNames = implode(', ', array_map(fn($p) => htmlspecialchars($p['display_name']), $participants));

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Session Transcript — <?= $roomName ?></title>
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Georgia, serif; font-size: 13px; color: #1a1a2e; background: #fff; padding: 40px; max-width: 800px; margin: 0 auto; }
    h1 { font-size: 24px; margin-bottom: 8px; color: #0d0d1a; }
    h2 { font-size: 16px; margin: 28px 0 12px; color: #1a1a2e; border-bottom: 1px solid #ddd; padding-bottom: 6px; }
    h3 { font-size: 14px; margin: 16px 0 6px; }
    .meta { color: #555; font-size: 12px; margin-bottom: 24px; }
    .meta span { margin-right: 16px; }
    .message { padding: 6px 8px; margin-bottom: 4px; border-radius: 4px; }
    .message.host   { background: #f0eeff; border-left: 3px solid #7c6af7; }
    .message.client { background: #f8f8f8; border-left: 3px solid #ccc; }
    .voice-note-row { display:flex;align-items:center;gap:8px;padding:4px 0; }
    .file-row a { color:#7c6af7;text-decoration:none;font-size:12px; }
    .message.pinned       { background: #fff0f0; border-left: 3px solid #dc143c; }
    .msg-meta { font-size: 11px; color: #777; margin-bottom: 3px; }
    .msg-body { line-height: 1.5; }
    .pin-badge { display: inline-block; background: #dc143c; color: #fff; font-size: 10px; padding: 1px 5px; border-radius: 3px; margin-left: 6px; vertical-align: middle; }
    .notes-block { background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: 10px 12px; margin-bottom: 10px; }
    .notes-block .participant-name { font-weight: bold; margin-bottom: 4px; }
    .notes-block .note-text { color: #333; line-height: 1.5; white-space: pre-wrap; }
    .pin-item { padding: 8px 10px; background: #fff5f5; border: 1px solid #ffcccc; border-radius: 4px; margin-bottom: 8px; }
    .pin-annotation { font-style: italic; color: #c00; margin-top: 4px; font-size: 12px; }
    .shared-file-item { padding: 10px 12px; background: #f4f2ff; border: 1px solid #d0c8f7; border-radius: 4px; margin-bottom: 8px; }
    .shared-file-item .sf-meta { font-size: 11px; color: #777; margin-bottom: 4px; }
    .shared-file-item .sf-name { font-weight: bold; font-size: 13px; color: #1a1a2e; }
    .shared-file-item .sf-preview { margin-top: 8px; }
    .shared-file-item .sf-preview img { max-width: 100%; max-height: 320px; border-radius: 4px; border: 1px solid #ccc; display: block; }
    .shared-file-item .sf-ephemeral { font-size: 11px; color: #999; margin-top: 6px; font-style: italic; }
    footer { margin-top: 40px; padding-top: 16px; border-top: 1px solid #ddd; font-size: 11px; color: #999; text-align: center; }
    @media print {
      body { padding: 20px; }
      button { display: none; }
    }
  </style>
</head>
<body onload="window.print()">

<h1>Session Transcript</h1>
<div class="meta">
  <span><strong>Room:</strong> <?= $roomName ?></span>
  <span><strong>Date:</strong> <?= $dateStr ?> at <?= $timeStr ?></span>
  <span><strong>Duration:</strong> <?= htmlspecialchars($duration) ?></span>
  <span><strong>Participants:</strong> <?= $participantNames ?: 'None' ?></span>
</div>

<button onclick="window.print()" style="margin-bottom:20px;padding:8px 16px;cursor:pointer;background:#7c6af7;color:#fff;border:none;border-radius:6px;font-size:13px;">
  Print / Save as PDF
</button>

<!-- ── Section 1: Full transcript ── -->
<h2>Full Transcript</h2>
<?php if (empty($messages)): ?>
  <p style="color:#777;">No messages in this session.</p>
<?php else: ?>
  <?php foreach ($messages as $msg): ?>
    <?php
      $msgHls  = $hlMap[(int)$msg['id']] ?? [];
      $name    = $msg['display_name'] ?? 'Host';
      $role    = $msg['is_practitioner'] ? 'host' : 'client';
      $pinned  = $msg['is_pinned'] ? ' pinned' : '';
      $time    = (new DateTime($msg['sent_at']))->format('g:i A');
      $msgType = $msg['message_type'] ?? 'text';
    ?>
    <div class="message <?= $role . $pinned ?>">
      <div class="msg-meta">
        <?= htmlspecialchars($name) ?> · <?= $time ?>
        <?php if ($msg['is_pinned']): ?>
          <span class="pin-badge">PINNED</span>
        <?php endif; ?>
      </div>
      <div class="msg-body">
        <?php if ($msgType === 'voice_note'): ?>
          <div class="voice-note-row">
            🎙 <strong>Voice Note</strong>
            &nbsp;<a href="<?= htmlspecialchars($msg['content']) ?>" download>Download</a>
            <?php if (!empty($msg['caption'])): ?>
              &nbsp;— <?= htmlspecialchars($msg['caption']) ?>
            <?php endif; ?>
          </div>
        <?php elseif ($msgType === 'file'): ?>
          <div class="file-row">
            📄 <?= htmlspecialchars($msg['original_name'] ?? basename($msg['content'])) ?>
            <?php if (!empty($msg['file_size'])): ?>
              (<?= round((int)$msg['file_size'] / 1024) ?> KB)
            <?php endif; ?>
            &nbsp;<a href="<?= htmlspecialchars($msg['content']) ?>" download>Download</a>
            <?php if (!empty($msg['caption'])): ?>
              <br><?= htmlspecialchars($msg['caption']) ?>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <?= applyHighlightsToText($msg['content'], $msgHls) ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<!-- ── Section 2: Practitioner notes ── -->
<?php if ($notes): ?>
<h2>Session Notes</h2>
<?php foreach ($notes as $note): ?>
  <div class="notes-block">
    <div class="participant-name"><?= htmlspecialchars($note['display_name']) ?></div>
    <div class="note-text"><?= htmlspecialchars($note['note_content']) ?></div>
  </div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ── Section 3: Pinned messages ── -->
<?php if ($pins): ?>
<h2>Pinned Messages</h2>
<?php foreach ($pins as $pin): ?>
  <div class="pin-item">
    <div class="msg-meta">
      <?= htmlspecialchars($pin['display_name'] ?? 'Host') ?>
      · <?= (new DateTime($pin['sent_at']))->format('g:i A') ?>
    </div>
    <div class="msg-body"><?= htmlspecialchars($pin['content']) ?></div>
    <?php if ($pin['annotation']): ?>
      <div class="pin-annotation">Note: <?= htmlspecialchars($pin['annotation']) ?></div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($recapSummary || $recapInsights || $recapActions || $recapFocus): ?>
<!-- ── Section: Session Recap ── -->
<h2 style="margin-top:32px;">Session Recap</h2>
<div style="background:#f7f5ff;border:1px solid #d0c8f7;border-radius:6px;padding:16px 18px;margin-bottom:8px;">
  <?php if ($recapSummary): ?>
  <div style="margin-bottom:12px;">
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#6b58d8;margin-bottom:4px;">What was explored</div>
    <div style="font-size:13px;color:#1a1a2e;line-height:1.55;white-space:pre-wrap;"><?= htmlspecialchars($recapSummary) ?></div>
  </div>
  <?php endif; ?>
  <?php if ($recapInsights): ?>
  <div style="margin-bottom:12px;">
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#6b58d8;margin-bottom:4px;">Key insights &amp; moments</div>
    <div style="font-size:13px;color:#1a1a2e;line-height:1.55;white-space:pre-wrap;"><?= htmlspecialchars($recapInsights) ?></div>
  </div>
  <?php endif; ?>
  <?php if ($recapActions): ?>
  <div style="margin-bottom:12px;">
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#6b58d8;margin-bottom:4px;">Agreed actions &amp; commitments</div>
    <div style="font-size:13px;color:#1a1a2e;line-height:1.55;white-space:pre-wrap;"><?= htmlspecialchars($recapActions) ?></div>
  </div>
  <?php endif; ?>
  <?php if ($recapFocus): ?>
  <div>
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#6b58d8;margin-bottom:4px;">Focus for next session</div>
    <div style="font-size:13px;color:#1a1a2e;line-height:1.55;white-space:pre-wrap;"><?= htmlspecialchars($recapFocus) ?></div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
$fileMessages = array_filter($messages, fn($m) => ($m['message_type'] ?? '') === 'file');
if ($fileMessages):
?>
<!-- ── Section: Shared Files ── -->
<h2 style="margin-top:32px;">Files Shared in This Session</h2>
<p style="font-size:12px;color:#666;margin-bottom:12px;">
  All files shared during the session are listed below.
  Images up to 5 MB are embedded directly in this export.
  Other file content is removed from the server after export — save any documents you need before closing this page.
</p>
<?php foreach ($fileMessages as $fm): ?>
<?php
  $fmId    = (int)$fm['id'];
  $fmName  = $fm['original_name'] ?? basename($fm['content']);
  $fmSize  = !empty($fm['file_size']) ? round((int)$fm['file_size'] / 1024) . ' KB' : '';
  $fmMime  = $fm['mime_type'] ?? '';
  $fmTime  = (new DateTime($fm['sent_at']))->format('g:i A');
  $fmWho   = $fm['display_name'] ?? ($fm['is_practitioner'] ? 'Practitioner' : 'Client');
  $fmCaption = $fm['caption'] ?? '';
  $embed   = $fileEmbeds[$fmId] ?? null;
?>
<div class="shared-file-item">
  <div class="sf-meta">
    Shared by <?= htmlspecialchars($fmWho) ?> · <?= $fmTime ?>
    <?php if ($fmSize): ?> · <?= $fmSize ?><?php endif; ?>
    <?php if ($fmMime): ?> · <code style="font-size:10px;"><?= htmlspecialchars($fmMime) ?></code><?php endif; ?>
  </div>
  <div class="sf-name">📄 <?= htmlspecialchars($fmName) ?></div>
  <?php if ($fmCaption): ?>
    <div style="font-size:12px;color:#444;margin-top:4px;"><?= htmlspecialchars($fmCaption) ?></div>
  <?php endif; ?>
  <?php if ($embed): ?>
    <div class="sf-preview">
      <img src="data:<?= htmlspecialchars($embed['mime']) ?>;base64,<?= $embed['data'] ?>" alt="<?= htmlspecialchars($fmName) ?>">
    </div>
  <?php else: ?>
    <div class="sf-ephemeral">File content not available — removed from server after export.</div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<footer>
  Generated by <?= htmlspecialchars(getSetting('platform_name', 'Serenity Spaces')) ?> &nbsp;|&nbsp;
  <?= date('F j, Y \a\t g:i A') ?>
</footer>
<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">window.onload = function(){ window.print(); };</script>
</body>
</html>
