<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/coverage/coverage_helper.php';
requireSetup();

$appName  = getSetting('app_name', 'Serenity Spaces');
$appLogo  = getSetting('logo_path', '');
$registrationsEnabled = getSetting('registrations_enabled', '1') === '1';

$pdo      = getDB();
$entries  = coverage_load_active($pdo);

// Tier groupings — entries already arrive in canonical order.
$tierGroups = [
    'Non-Clinical'         => [],
    'Modifier'             => [],
    'Pastoral / Spiritual' => [],
    'Clinical'             => [],
];
foreach ($entries as $e) {
    $tier = $e['tier'] !== '' ? $e['tier'] : 'Non-Clinical';
    if (!isset($tierGroups[$tier])) $tierGroups[$tier] = [];
    $tierGroups[$tier][] = $e;
}
$tierGroups = array_filter($tierGroups, fn($arr) => !empty($arr));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Coverage — <?= htmlspecialchars($appName) ?></title>
  <link rel="stylesheet" href="/assets/css/main.css">
  <link rel="stylesheet" href="/assets/css/fonts.css">
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
    :root {
      --sky-void:   #050810;
      --sky-deep:   #080d1e;
      --sky-mid:    #0d1433;
      --star-color: #e8eeff;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { overflow-y: auto; height: auto; }
    body {
      font-family: 'Outfit', sans-serif;
      background: var(--sky-void);
      color: #dddcf2;
      min-height: 100vh;
    }

    /* ── Night sky (matches about.php) ── */
    .sky-canvas {
      position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background: linear-gradient(170deg, var(--sky-void) 0%, var(--sky-deep) 35%, var(--sky-mid) 65%, #0a0f28 100%);
      overflow: hidden;
    }
    .sky-moon-glow {
      position: absolute; top: -18%; right: 8%;
      width: 700px; height: 700px;
      background: radial-gradient(circle, rgba(255,250,220,0.055) 0%, rgba(200,215,255,0.025) 30%, transparent 70%);
      border-radius: 50%;
    }
    .sky-horizon {
      position: absolute; bottom: 0; left: 0; right: 0; height: 35%;
      background: linear-gradient(to top, rgba(60,50,120,0.12) 0%, transparent 100%);
    }
    .star-layer { position: absolute; inset: 0; }
    .star {
      position: absolute; border-radius: 50%; background: var(--star-color);
      animation: star-twinkle var(--dur) var(--delay) ease-in-out infinite;
    }
    @keyframes star-twinkle {
      0%, 100% { opacity: var(--op-min); transform: scale(var(--scale-min)); }
      50%      { opacity: var(--op-max); transform: scale(1); }
    }

    /* ── Header ── */
    .cov-header {
      position: fixed; top: 0; left: 0; right: 0; z-index: 100;
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 32px; height: 60px;
      background: rgba(5,8,16,0.82);
      backdrop-filter: blur(24px);
      border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .cov-brand {
      font-weight: 700; font-size: 16px;
      color: #dddcf2; text-decoration: none; letter-spacing: -0.3px;
    }
    .cov-nav { display: flex; align-items: center; gap: 8px; }
    .cov-nav a {
      font-size: 13px; padding: 7px 16px; border-radius: 8px;
      text-decoration: none; color: rgba(220,220,255,0.7);
      transition: color 0.15s, background 0.15s; font-weight: 500;
    }
    .cov-nav a:hover { color: #dddcf2; background: rgba(255,255,255,0.05); }
    .cov-nav a.btn-primary {
      background: rgba(124,106,247,0.18);
      border: 1px solid rgba(124,106,247,0.35);
      color: #c5b8ff;
    }
    .cov-nav a.btn-primary:hover { background: rgba(124,106,247,0.28); color: #ddd6ff; }

    /* ── Page wrapper ── */
    .cov-page { position: relative; z-index: 1; padding-top: 60px; }

    /* ── Hero ── */
    .cov-hero { text-align: center; padding: 90px 24px 56px; max-width: 780px; margin: 0 auto; }
    .cov-hero-label {
      display: inline-block; font-size: 11px; font-weight: 700;
      letter-spacing: 2px; text-transform: uppercase;
      color: rgba(124,106,247,0.8); margin-bottom: 20px;
    }
    .cov-hero h1 {
      font-size: clamp(36px, 6vw, 60px);
      font-weight: 800; letter-spacing: -2px; line-height: 1.08;
      background: linear-gradient(135deg, #ffffff 0%, #c4b8ff 60%, #7c6af7 100%);
      -webkit-background-clip: text; -webkit-text-fill-color: transparent;
      background-clip: text; margin-bottom: 22px;
    }
    .cov-hero p {
      font-size: 17px; font-weight: 300; line-height: 1.65;
      color: rgba(200,210,255,0.65); max-width: 620px; margin: 0 auto;
    }

    /* ── Content ── */
    .cov-content { max-width: 920px; margin: 0 auto; padding: 0 24px 100px; }

    /* ── Tier band ── */
    .cov-tier { margin-top: 64px; }
    .cov-tier:first-of-type { margin-top: 28px; }
    .cov-tier-label {
      font-size: 11px; font-weight: 700; letter-spacing: 2px;
      text-transform: uppercase; color: rgba(124,106,247,0.7);
      margin-bottom: 8px;
    }
    .cov-tier-title {
      font-size: 22px; font-weight: 700; letter-spacing: -0.4px;
      color: #dddcf2; margin-bottom: 22px;
      padding-bottom: 14px;
      border-bottom: 1px solid rgba(124,106,247,0.15);
    }

    /* ── Type card ── */
    .cov-card {
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.07);
      border-radius: 14px;
      padding: 28px 30px;
      margin-bottom: 18px;
      transition: border-color 0.2s;
    }
    .cov-card:hover { border-color: rgba(124,106,247,0.25); }
    .cov-card-head {
      display: flex; align-items: flex-start; justify-content: space-between;
      gap: 12px; margin-bottom: 4px; flex-wrap: wrap;
    }
    .cov-card h2 {
      font-size: 20px; font-weight: 700;
      color: #dddcf2; letter-spacing: -0.4px;
    }
    .cov-card-tags {
      display: flex; gap: 6px; flex-wrap: wrap;
    }
    .cov-tag {
      font-size: 10px; font-weight: 700; letter-spacing: 0.5px;
      text-transform: uppercase;
      padding: 3px 9px; border-radius: 999px;
      background: rgba(124,106,247,0.12);
      color: rgba(197,184,255,0.85);
      border: 1px solid rgba(124,106,247,0.25);
    }
    .cov-tag.cov-tag-clinical {
      background: rgba(245,200,66,0.10);
      color: rgba(245,200,66,0.85);
      border-color: rgba(245,200,66,0.25);
    }
    .cov-tag.cov-tag-pastoral {
      background: rgba(78,203,138,0.10);
      color: rgba(78,203,138,0.88);
      border-color: rgba(78,203,138,0.25);
    }
    .cov-summary {
      font-size: 14px; font-style: italic;
      color: rgba(200,210,255,0.55);
      margin: 4px 0 18px;
    }
    .cov-body p {
      font-size: 14.5px; font-weight: 300; line-height: 1.75;
      color: rgba(200,210,255,0.7);
      margin-bottom: 14px;
    }
    .cov-body p:last-child { margin-bottom: 0; }
    .cov-body code {
      background: rgba(124,106,247,0.10);
      color: rgba(197,184,255,0.9);
      padding: 1px 6px; border-radius: 4px;
      font-family: 'Outfit Mono', 'SF Mono', Consolas, monospace;
      font-size: 13px;
    }
    .cov-body strong { color: #dddcf2; font-weight: 600; }

    /* ── Empty state ── */
    .cov-empty {
      text-align: center; padding: 80px 24px; max-width: 540px; margin: 0 auto;
      background: rgba(255,255,255,0.02);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 16px;
    }
    .cov-empty h2 {
      font-size: 22px; color: #dddcf2; margin-bottom: 10px; letter-spacing: -0.4px;
    }
    .cov-empty p {
      font-size: 14.5px; color: rgba(200,210,255,0.55); line-height: 1.7;
    }

    /* ── Boundary note ── */
    .cov-boundary {
      margin-top: 56px;
      padding: 22px 26px;
      border-left: 3px solid rgba(124,106,247,0.4);
      background: rgba(124,106,247,0.05);
      border-radius: 0 10px 10px 0;
    }
    .cov-boundary h3 {
      font-size: 14px; font-weight: 700; letter-spacing: 0.5px;
      color: #c5b8ff; margin-bottom: 8px; text-transform: uppercase;
    }
    .cov-boundary p {
      font-size: 13.5px; font-weight: 300; line-height: 1.7;
      color: rgba(200,210,255,0.6);
    }

    /* ── Footer ── */
    .cov-footer {
      text-align: center;
      padding: 32px 24px 48px;
      position: relative; z-index: 1;
    }
    .cov-footer p { font-size: 12px; color: rgba(255,255,255,0.2); }
    .cov-footer a { color: rgba(124,106,247,0.6); text-decoration: none; }
    .cov-footer a:hover { color: rgba(124,106,247,0.9); }

    @media (max-width: 640px) {
      .cov-header { padding: 0 16px; }
      .cov-nav a { font-size: 11px; padding: 6px 8px; }
      .cov-card { padding: 22px 22px; }
      .cov-tier-title { font-size: 19px; }
    }
  </style>
</head>
<body>

<!-- Night sky background -->
<div class="sky-canvas">
  <div class="sky-moon-glow"></div>
  <div class="sky-horizon"></div>
  <div class="star-layer" id="star-layer-0"></div>
  <div class="star-layer" id="star-layer-1"></div>
  <div class="star-layer" id="star-layer-2"></div>
</div>

<script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
(function(){
  const rnd = (a,b) => a + Math.random()*(b-a);
  const layers = [
    [200, 0.7, 1.3,  0.55, 0.10, 7,  14, 0.80],
    [90,  1.3, 2.2,  0.75, 0.18, 4,  9,  0.88],
    [35,  2.2, 3.5,  0.95, 0.30, 2,  6,  0.92],
  ];
  layers.forEach(([count,sMin,sMax,opMax,opMin,dMin,dMax,scMin], li) => {
    const layer = document.getElementById('star-layer-'+li);
    for (let i=0; i<count; i++) {
      const s   = document.createElement('div');
      const sz  = rnd(sMin, sMax);
      const dur = rnd(dMin, dMax).toFixed(2);
      const dly = rnd(0, dMax).toFixed(2);
      s.className = 'star';
      s.style.cssText = `width:${sz.toFixed(2)}px;height:${sz.toFixed(2)}px;top:${rnd(0,100).toFixed(3)}%;left:${rnd(0,100).toFixed(3)}%;--op-max:${opMax};--op-min:${opMin};--scale-min:${scMin};--dur:${dur}s;--delay:${dly}s;`;
      layer.appendChild(s);
    }
  });
})();
</script>

<header class="cov-header">
  <a href="/" class="cov-brand">
    <?php if ($appLogo): ?>
      <img src="<?= htmlspecialchars($appLogo) ?>" alt="<?= htmlspecialchars($appName) ?>" style="height:48px;">
    <?php else: ?>
      <?= htmlspecialchars($appName) ?>
    <?php endif; ?>
  </a>
  <nav class="cov-nav">
    <a href="/?browse=1">Find a Practitioner</a>
    <a href="/about.php">About</a>
    <a href="/login.php">Login</a>
    <?php if ($registrationsEnabled): ?>
      <a href="/register.php" class="btn-primary">Create Account</a>
    <?php endif; ?>
  </nav>
</header>

<div class="cov-page">

  <section class="cov-hero">
    <div class="cov-hero-label">Coverage</div>
    <h1>What kind of support is available here</h1>
    <p>The kinds of practitioners working on this instance of <?= htmlspecialchars($appName) ?>, what each role does, and where it fits. Only categories with at least one active practitioner are listed — what you see here is what the platform actually offers right now.</p>
  </section>

  <div class="cov-content">

    <?php if (empty($entries)): ?>
      <div class="cov-empty">
        <h2>No practitioners listed yet</h2>
        <p>This instance hasn't onboarded any practitioners with public availability yet. Once practitioners join and set their availability, the categories they offer will appear here.</p>
      </div>
    <?php else: ?>

      <?php foreach ($tierGroups as $tier => $tierEntries): ?>
        <section class="cov-tier">
          <div class="cov-tier-label">Tier</div>
          <h2 class="cov-tier-title"><?= htmlspecialchars($tier) ?></h2>

          <?php foreach ($tierEntries as $e): ?>
            <article class="cov-card" id="cov-<?= htmlspecialchars($e['type_key']) ?>">
              <div class="cov-card-head">
                <h2><?= htmlspecialchars($e['display_label']) ?></h2>
                <div class="cov-card-tags">
                  <?php
                    $tagClass = 'cov-tag';
                    if ($e['tier'] === 'Clinical')             $tagClass .= ' cov-tag-clinical';
                    elseif ($e['tier'] === 'Pastoral / Spiritual') $tagClass .= ' cov-tag-pastoral';
                  ?>
                  <span class="<?= $tagClass ?>"><?= htmlspecialchars($e['tier']) ?></span>
                  <?php if ($e['license_gate'] && strtolower($e['license_gate']) !== 'none'): ?>
                    <span class="cov-tag cov-tag-clinical"><?= htmlspecialchars($e['license_gate']) ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <?php if ($e['summary'] !== ''): ?>
                <p class="cov-summary"><?= htmlspecialchars($e['summary']) ?></p>
              <?php endif; ?>
              <div class="cov-body">
                <?= $e['body_html'] ?>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>

      <div class="cov-boundary">
        <h3>Boundary note</h3>
        <p>Coverage on this page reflects what active practitioners on this instance currently offer. Each practitioner is responsible for their own credentials, scope of practice, and the kind of support they describe in their listing. Practice type categories on SerenitySpaces are descriptive — not certifications. Always read individual practitioner profiles to confirm fit before booking.</p>
      </div>

    <?php endif; ?>

  </div>

</div>

<footer class="cov-footer">
  <p>
    <?= htmlspecialchars($appName) ?> &nbsp;·&nbsp;
    <a href="/about.php">About</a> &nbsp;·&nbsp;
    <a href="/coverage.php">Coverage</a> &nbsp;·&nbsp;
    <a href="/privacy.php">Privacy Notice</a>
  </p>
</footer>

</body>
</html>
