<?php
/**
 * Serenity Spaces — Access Blocked Page
 * Rendered by firewall.php when an IP is temp or perm blocked.
 * Also directly accessible so the firewall can include() it.
 */

// Values injected by firewall.php via $GLOBALS, or set defaults for direct access
$blockType = $GLOBALS['_fw_block_type'] ?? 'temp';
$expiresAt = $GLOBALS['_fw_expires_at'] ?? null;

$isPerm = ($blockType === 'perm');

$expiryDisplay = '';
if (!$isPerm && $expiresAt) {
    try {
        $dt = new DateTime($expiresAt, new DateTimeZone('UTC'));
        $expiryDisplay = $dt->format('g:i A \U\T\C \o\n F j, Y');
    } catch (Exception $e) {
        $expiryDisplay = '';
    }
}

http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Access Restricted — Serenity Spaces</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:       #0f0e17;
      --surface:  #1a1828;
      --border:   #2e2b45;
      --accent:   #7c6af7;
      --danger:   #ef5350;
      --text:     #e0dff5;
      --muted:    #8f8daa;
    }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }

    /* Subtle radial glow */
    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background: radial-gradient(ellipse 60% 50% at 50% 30%, rgba(239,83,80,0.06) 0%, transparent 70%);
      pointer-events: none;
    }

    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 48px 40px;
      max-width: 480px;
      width: 100%;
      text-align: center;
      position: relative;
    }

    .icon {
      width: 64px;
      height: 64px;
      background: rgba(239,83,80,0.12);
      border: 1px solid rgba(239,83,80,0.25);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 24px;
      font-size: 28px;
    }

    h1 {
      font-size: 22px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 12px;
      letter-spacing: -0.3px;
    }

    .subtitle {
      font-size: 15px;
      color: var(--muted);
      line-height: 1.6;
      margin-bottom: 28px;
    }

    .info-box {
      background: rgba(239,83,80,0.08);
      border: 1px solid rgba(239,83,80,0.2);
      border-radius: 10px;
      padding: 16px 20px;
      margin-bottom: 28px;
      text-align: left;
    }

    .info-box .label {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      color: var(--danger);
      font-weight: 600;
      margin-bottom: 6px;
    }

    .info-box .value {
      font-size: 14px;
      color: var(--text);
      line-height: 1.5;
    }

    .contact-note {
      font-size: 13px;
      color: var(--muted);
      line-height: 1.6;
      padding: 16px;
      background: rgba(124,106,247,0.06);
      border: 1px solid rgba(124,106,247,0.15);
      border-radius: 10px;
    }

    .contact-note strong {
      color: var(--accent);
    }

    .wordmark {
      margin-top: 36px;
      font-size: 13px;
      color: rgba(255,255,255,0.2);
      letter-spacing: 0.3px;
    }
  </style>
</head>
<body>

<div class="card">
  <div class="icon">🔒</div>

  <h1>Access Temporarily Restricted</h1>
  <p class="subtitle">
    <?php if ($isPerm): ?>
      Your IP address has been blocked due to repeated failed login attempts.<br>
      This restriction is in place to protect session data.
    <?php else: ?>
      Too many failed login attempts have been detected from your connection.<br>
      Access has been paused for a short period.
    <?php endif; ?>
  </p>

  <div class="info-box">
    <div class="label">Restriction type</div>
    <div class="value"><?= $isPerm ? 'Permanent — administrator review required' : 'Temporary — access will resume automatically' ?></div>
    <?php if (!$isPerm && $expiryDisplay): ?>
      <div class="label" style="margin-top:12px;">Access resumes</div>
      <div class="value"><?= htmlspecialchars($expiryDisplay) ?></div>
    <?php endif; ?>
  </div>

  <div class="contact-note">
    If you believe this is an error or need immediate access,<br>
    please <strong>reach out to your Practitioner</strong> for assistance.
  </div>

  <div class="wordmark">Serenity Spaces</div>
</div>

</body>
</html>
<?php exit; ?>
