<?php
/**
 * List/create lobbies for a game.
 * The game HTML files redirect here on leave — we send a postMessage to the parent
 * so the activity modal can close itself gracefully.
 */
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Activity ended</title></head>
<body>
<script>
// Notify parent room that the activity was closed (works when inside an iframe)
try {
    window.parent.postMessage({ type: 'activity_close' }, '*');
} catch(e) {}
// Fallback: just close the window if opened in a new tab
try { window.close(); } catch(e) {}
</script>
<p style="font-family:sans-serif;text-align:center;padding:40px;color:#888;">
  Activity ended. You can close this window.
</p>
</body>
</html>
