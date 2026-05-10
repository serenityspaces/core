<?php
/**
 * Client-facing session transcript export.
 *
 * Authenticated by the participant's join_token (stored in the ss_reconnect_
 * cookie set when they entered the room, or passed as POST join_token).
 *
 * Returns a printable HTML transcript of the session's messages.
 * Does NOT include practitioner notes, highlights, or internal annotations.
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/phi_crypto.php';

// ── Auth: join_token (guest/room link) OR end_user session (client portal) ─
$input     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$joinToken = trim($input['join_token'] ?? '');

if ($joinToken === '') {
    // Try reconnect cookie — name pattern: ss_reconnect_{room_token}
    foreach ($_COOKIE as $name => $val) {
        if (str_starts_with($name, 'ss_reconnect_')) {
            $joinToken = $val;
            break;
        }
    }
}

$pdo = getDB();
$participant = null;
$sessionId   = 0;
$session     = null;

if ($joinToken !== '') {
    // Path A: join_token (guest or room participant)
    $stmt = $pdo->prepare('SELECT * FROM participants WHERE join_token = ? LIMIT 1');
    $stmt->execute([$joinToken]);
    $participant = $stmt->fetch();
    if ($participant) {
        $sessionId = (int)$participant['session_id'];
    }
} elseif (!empty($_SESSION['end_user_id'])) {
    // Path B: authenticated client portal session + ?session_id=N
    $sessionId = (int)($_GET['session_id'] ?? $input['session_id'] ?? 0);
    if ($sessionId > 0) {
        // Verify this user was booked into this session
        $stmt = $pdo->prepare(
            'SELECT b.id FROM bookings b
             JOIN sessions s ON s.room_id = b.room_id
               AND s.started_at BETWEEN b.scheduled_at - INTERVAL 2 HOUR
                                    AND b.scheduled_at + INTERVAL 4 HOUR
             WHERE b.end_user_id = ? AND s.id = ? AND s.ended_at IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute([(int)$_SESSION['end_user_id'], $sessionId]);
        if (!$stmt->fetch()) {
            $sessionId = 0; // not authorised
        } else {
            // Build a synthetic participant record for display
            $nameStmt = $pdo->prepare('SELECT display_name FROM end_users WHERE id = ? LIMIT 1');
            $nameStmt->execute([(int)$_SESSION['end_user_id']]);
            $clientName  = $nameStmt->fetchColumn() ?: ($_SESSION['end_user_name'] ?? 'You');
            $participant = [
                'session_id'   => $sessionId,
                'display_name' => $clientName,
                'join_token'   => null,
            ];
        }
    }
}

if (!$participant || $sessionId <= 0) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

// Verify session has ended (no transcript of an active session)
$stmt = $pdo->prepare('SELECT s.*, r.name AS room_name, r.practitioner_id FROM sessions s JOIN rooms r ON r.id = s.room_id WHERE s.id = ? LIMIT 1');
$stmt->execute([$sessionId]);
$session = $stmt->fetch();
if (!$session || !$session['ended_at']) {
    http_response_code(403);
    echo 'Session is still active or not found';
    exit;
}

// ── Fetch messages ─────────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT m.id, m.content, m.sent_at, m.is_practitioner, m.message_type,
            m.original_name, m.file_size, m.caption, m.is_pinned,
            p.display_name, p.avatar_path
     FROM messages m
     LEFT JOIN participants p ON p.id = m.participant_id
     WHERE m.session_id = ? AND m.is_deleted = 0
     ORDER BY m.sent_at ASC'
);
$stmt->execute([$sessionId]);
$messages = $stmt->fetchAll();

foreach ($messages as &$_m) {
    if (($_m['message_type'] ?? 'text') === 'text') {
        $_m['content'] = phi_decrypt($_m['content'] ?? '');
    }
}
unset($_m);

// ── Fetch practitioner display name ────────────────────────────
$pStmt = $pdo->prepare('SELECT display_name FROM practitioners WHERE id = ? LIMIT 1');
$pStmt->execute([$session['practitioner_id']]);
$practName = $pStmt->fetchColumn() ?: 'Your practitioner';

$startedAt = new DateTime($session['started_at']);
$endedAt   = new DateTime($session['ended_at']);
$diff      = $startedAt->diff($endedAt);
$duration  = ($diff->h > 0 ? $diff->h . 'h ' : '') . $diff->i . 'm';

$roomName  = htmlspecialchars($session['room_name']);
$dateStr   = $startedAt->format('F j, Y');
$timeStr   = $startedAt->format('g:i A');
$myName    = htmlspecialchars($participant['display_name']);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Your Session Transcript</title>
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Georgia, serif; font-size: 13px; color: #1a1a2e; background: #fff; padding: 40px; max-width: 760px; margin: 0 auto; }
    h1 { font-size: 22px; margin-bottom: 8px; color: #0d0d1a; }
    h2 { font-size: 15px; margin: 24px 0 10px; color: #1a1a2e; border-bottom: 1px solid #ddd; padding-bottom: 6px; }
    .meta { color: #555; font-size: 12px; margin-bottom: 20px; line-height: 1.8; }
    .meta span { margin-right: 16px; }
    .notice { background: #f0eeff; border-left: 3px solid #7c6af7; padding: 10px 14px; font-size: 12px; color: #444; margin-bottom: 20px; border-radius: 0 4px 4px 0; }
    .message { padding: 6px 8px; margin-bottom: 4px; border-radius: 4px; }
    .message.host   { background: #f0eeff; border-left: 3px solid #7c6af7; }
    .message.mine   { background: #f0fff4; border-left: 3px solid #34a853; }
    .message.other  { background: #f8f8f8; border-left: 3px solid #ccc; }
    .msg-meta { font-size: 11px; color: #777; margin-bottom: 3px; }
    .msg-body { line-height: 1.5; }
    .voice-note-row { display:flex;align-items:center;gap:8px;padding:4px 0; }
    .file-row a { color:#7c6af7;text-decoration:none;font-size:12px; }
    .pin-badge { display:inline-block;background:#dc143c;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:6px;vertical-align:middle; }
    footer { margin-top: 40px; padding-top: 16px; border-top: 1px solid #ddd; font-size: 11px; color: #999; text-align: center; }
    button { display:none; }
    @media screen { button { display:inline-block; margin-bottom:20px; padding:8px 16px; cursor:pointer; background:#7c6af7; color:#fff; border:none; border-radius:6px; font-size:13px; } }
  </style>
</head>
<body onload="window.print()">

<h1>Your Session Transcript</h1>
<div class="meta">
  <span><strong>Room:</strong> <?= $roomName ?></span>
  <span><strong>Date:</strong> <?= $dateStr ?> at <?= $timeStr ?></span>
  <span><strong>Duration:</strong> <?= htmlspecialchars($duration) ?></span>
  <span><strong>Practitioner:</strong> <?= htmlspecialchars($practName) ?></span>
</div>

<div class="notice">This is your personal copy of the session conversation. It does not include practitioner notes or internal annotations.</div>

<button onclick="window.print()">Print / Save as PDF</button>

<h2>Conversation</h2>
<?php if (empty($messages)): ?>
  <p style="color:#777;">No messages in this session.</p>
<?php else: ?>
  <?php foreach ($messages as $msg): ?>
    <?php
      $isMe    = ((int)$msg['is_practitioner'] === 0 && $msg['display_name'] === $participant['display_name']);
      $isHost  = (bool)$msg['is_practitioner'];
      $cls     = $isHost ? 'host' : ($isMe ? 'mine' : 'other');
      $name    = $msg['display_name'] ?? ($isHost ? 'Host' : 'Participant');
      $time    = (new DateTime($msg['sent_at']))->format('g:i A');
      $msgType = $msg['message_type'] ?? 'text';
    ?>
    <div class="message <?= $cls ?>">
      <div class="msg-meta">
        <?= htmlspecialchars($name) ?> · <?= $time ?>
        <?php if ($msg['is_pinned']): ?><span class="pin-badge">PINNED</span><?php endif; ?>
      </div>
      <div class="msg-body">
        <?php if ($msgType === 'voice_note'): ?>
          <div class="voice-note-row">🎙 <strong>Voice Note</strong>
            &nbsp;<a href="<?= htmlspecialchars($msg['content']) ?>" download>Download</a>
            <?php if (!empty($msg['caption'])): ?>&nbsp;— <?= htmlspecialchars($msg['caption']) ?><?php endif; ?>
          </div>
        <?php elseif ($msgType === 'file'): ?>
          <div class="file-row">📄 <?= htmlspecialchars($msg['original_name'] ?? basename($msg['content'])) ?>
            <?php if (!empty($msg['file_size'])): ?>(<?= round((int)$msg['file_size'] / 1024) ?> KB)<?php endif; ?>
            &nbsp;<a href="<?= htmlspecialchars($msg['content']) ?>" download>Download</a>
            <?php if (!empty($msg['caption'])): ?><br><?= htmlspecialchars($msg['caption']) ?><?php endif; ?>
          </div>
        <?php else: ?>
          <?= htmlspecialchars($msg['content']) ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<footer>
  Your personal session transcript — Serenity Spaces &nbsp;|&nbsp; <?= date('F j, Y \a\t g:i A') ?>
</footer>
<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">window.onload = function(){ window.print(); };</script>
</body>
</html>
