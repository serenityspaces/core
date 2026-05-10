<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
requireSetup();

$appName  = getSetting('app_name', 'Serenity Spaces');
$appLogo  = getSetting('logo_path', '');
$appTheme = getSetting('theme', 'dark');
$registrationsEnabled = getSetting('registrations_enabled', '1') === '1';
$siteBgPath = getSetting('site_background_path', '');
$siteBgType = getSetting('site_background_type', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>About — <?= htmlspecialchars($appName) ?></title>
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

    html, body {
      overflow-y: auto;
      height: auto;
    }
    body {
      font-family: 'Outfit', sans-serif;
      background: var(--sky-void);
      color: #dddcf2;
      min-height: 100vh;
    }

    /* ── Night sky ── */
    .sky-canvas {
      position: fixed;
      inset: 0;
      z-index: 0;
      pointer-events: none;
      background: linear-gradient(170deg,
        var(--sky-void) 0%,
        var(--sky-deep) 35%,
        var(--sky-mid)  65%,
        #0a0f28 100%
      );
      overflow: hidden;
    }
    .sky-moon-glow {
      position: absolute;
      top: -18%; right: 8%;
      width: 700px; height: 700px;
      background: radial-gradient(circle,
        rgba(255,250,220,0.055) 0%,
        rgba(200,215,255,0.025) 30%,
        transparent 70%
      );
      border-radius: 50%;
    }
    .sky-horizon {
      position: absolute;
      bottom: 0; left: 0; right: 0; height: 35%;
      background: linear-gradient(to top, rgba(60,50,120,0.12) 0%, transparent 100%);
    }
    .star-layer { position: absolute; inset: 0; }
    .star {
      position: absolute;
      border-radius: 50%;
      background: var(--star-color);
      animation: star-twinkle var(--dur) var(--delay) ease-in-out infinite;
    }
    @keyframes star-twinkle {
      0%, 100% { opacity: var(--op-max); transform: scale(1); }
      45%       { opacity: var(--op-min); transform: scale(var(--scale-min, 0.85)); }
    }
    .sky-cloud {
      position: absolute;
      border-radius: 50%;
      animation: sky-cloud-drift var(--cdur, 90s) var(--cdelay, 0s) linear infinite;
    }
    @keyframes sky-cloud-drift {
      from { transform: translateX(115vw) translateY(0); }
      to   { transform: translateX(-160%) translateY(var(--drift-y, 0px)); }
    }

    /* ── Header ── */
    .about-header {
      position: fixed;
      top: 0; left: 0; right: 0;
      z-index: 100;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 32px;
      height: 60px;
      background: rgba(5,8,16,0.82);
      backdrop-filter: blur(24px);
      border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .about-brand {
      font-weight: 700;
      font-size: 16px;
      color: #dddcf2;
      text-decoration: none;
      letter-spacing: -0.3px;
    }
    .about-nav {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .about-nav a {
      font-size: 13px;
      padding: 7px 16px;
      border-radius: 8px;
      text-decoration: none;
      color: rgba(220,220,255,0.7);
      transition: color 0.15s, background 0.15s;
      font-weight: 500;
    }
    .about-nav a:hover { color: #dddcf2; background: rgba(255,255,255,0.05); }
    .about-nav a.btn-primary {
      background: rgba(124,106,247,0.18);
      border: 1px solid rgba(124,106,247,0.35);
      color: #c5b8ff;
    }
    .about-nav a.btn-primary:hover { background: rgba(124,106,247,0.28); color: #ddd6ff; }

    /* ── Page wrapper ── */
    .about-page {
      position: relative;
      z-index: 1;
      padding-top: 60px;
    }

    /* ── Hero ── */
    .about-hero {
      text-align: center;
      padding: 90px 24px 72px;
      max-width: 780px;
      margin: 0 auto;
    }
    .about-hero-label {
      display: inline-block;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 2px;
      text-transform: uppercase;
      color: rgba(124,106,247,0.8);
      margin-bottom: 20px;
    }
    .about-hero h1 {
      font-size: clamp(36px, 6vw, 64px);
      font-weight: 800;
      letter-spacing: -2px;
      line-height: 1.08;
      background: linear-gradient(135deg, #ffffff 0%, #c4b8ff 60%, #7c6af7 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: 24px;
    }
    .about-hero p {
      font-size: 18px;
      font-weight: 300;
      line-height: 1.65;
      color: rgba(200,210,255,0.65);
      max-width: 600px;
      margin: 0 auto 36px;
    }
    .about-hero-actions {
      display: flex;
      gap: 12px;
      justify-content: center;
      flex-wrap: wrap;
    }
    .about-hero-actions a {
      font-size: 14px;
      font-weight: 600;
      padding: 12px 28px;
      border-radius: 10px;
      text-decoration: none;
      transition: all 0.18s;
    }
    .hero-cta-primary {
      background: rgba(124,106,247,0.2);
      border: 1px solid rgba(124,106,247,0.45);
      color: #c5b8ff;
    }
    .hero-cta-primary:hover { background: rgba(124,106,247,0.32); color: #ddd6ff; transform: translateY(-1px); }
    .hero-cta-ghost {
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.1);
      color: rgba(220,220,255,0.7);
    }
    .hero-cta-ghost:hover { background: rgba(255,255,255,0.08); color: #dddcf2; transform: translateY(-1px); }

    /* ── Sections ── */
    .about-content {
      max-width: 920px;
      margin: 0 auto;
      padding: 0 24px 100px;
    }

    .about-divider {
      width: 1px;
      height: 60px;
      background: linear-gradient(to bottom, transparent, rgba(124,106,247,0.3), transparent);
      margin: 0 auto 60px;
    }

    /* Section header */
    .section-label {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 2px;
      text-transform: uppercase;
      color: rgba(124,106,247,0.7);
      margin-bottom: 10px;
    }
    .section-title {
      font-size: 28px;
      font-weight: 700;
      letter-spacing: -0.8px;
      color: #dddcf2;
      margin-bottom: 14px;
    }
    .section-body {
      font-size: 15px;
      font-weight: 300;
      line-height: 1.75;
      color: rgba(200,210,255,0.6);
    }
    .section-body p { margin-bottom: 14px; }
    .section-body p:last-child { margin-bottom: 0; }

    /* Two-column layouts */
    .about-two-col {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 32px;
      margin-top: 48px;
    }
    @media (max-width: 640px) { .about-two-col { grid-template-columns: 1fr; } }

    /* Cards */
    .about-card {
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.07);
      border-radius: 14px;
      padding: 28px;
      transition: border-color 0.2s;
    }
    .about-card:hover { border-color: rgba(124,106,247,0.25); }
    .about-card-icon {
      font-size: 22px;
      margin-bottom: 14px;
    }
    .about-card h3 {
      font-size: 15px;
      font-weight: 700;
      color: #dddcf2;
      margin-bottom: 8px;
      letter-spacing: -0.2px;
    }
    .about-card p {
      font-size: 13.5px;
      font-weight: 300;
      line-height: 1.65;
      color: rgba(200,210,255,0.55);
    }

    /* Booth model callout */
    .booth-callout {
      margin-top: 48px;
      background: rgba(124,106,247,0.06);
      border: 1px solid rgba(124,106,247,0.18);
      border-radius: 16px;
      padding: 36px 40px;
    }
    .booth-callout h3 {
      font-size: 20px;
      font-weight: 700;
      letter-spacing: -0.4px;
      color: #c5b8ff;
      margin-bottom: 12px;
    }
    .booth-callout p {
      font-size: 14.5px;
      font-weight: 300;
      line-height: 1.75;
      color: rgba(200,210,255,0.6);
      margin-bottom: 12px;
    }
    .booth-callout p:last-child { margin-bottom: 0; }

    /* Privacy grid */
    .privacy-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
      gap: 20px;
      margin-top: 36px;
    }
    .privacy-item {
      background: rgba(255,255,255,0.02);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 12px;
      padding: 20px;
    }
    .privacy-item-head {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 8px;
    }
    .privacy-dot {
      width: 7px; height: 7px;
      border-radius: 50%;
      background: #7c6af7;
      flex-shrink: 0;
    }
    .privacy-item h4 {
      font-size: 13px;
      font-weight: 600;
      color: rgba(220,220,255,0.85);
    }
    .privacy-item p {
      font-size: 12.5px;
      font-weight: 300;
      line-height: 1.6;
      color: rgba(200,210,255,0.45);
      padding-left: 17px;
    }

    /* CTA footer */
    .about-cta {
      text-align: center;
      margin-top: 80px;
      padding: 60px 32px;
      background: rgba(255,255,255,0.02);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 20px;
    }
    .about-cta h2 {
      font-size: 28px;
      font-weight: 700;
      letter-spacing: -0.8px;
      color: #dddcf2;
      margin-bottom: 12px;
    }
    .about-cta p {
      font-size: 15px;
      font-weight: 300;
      color: rgba(200,210,255,0.55);
      margin-bottom: 28px;
      max-width: 480px;
      margin-left: auto;
      margin-right: auto;
    }

    /* Footer */
    .about-footer {
      text-align: center;
      padding: 32px 24px 48px;
      position: relative;
      z-index: 1;
    }
    .about-footer p {
      font-size: 12px;
      color: rgba(255,255,255,0.2);
    }
    .about-footer a {
      color: rgba(124,106,247,0.6);
      text-decoration: none;
    }
    .about-footer a:hover { color: rgba(124,106,247,0.9); }

    /* ── Shooting stars ── */
    .shooting-star {
      position: absolute;
      height: 1.5px;
      background: linear-gradient(to left, transparent 0%, rgba(200,210,255,0.7) 55%, rgba(255,255,255,0.95) 100%);
      border-radius: 999px;
      pointer-events: none;
      will-change: transform, opacity;
      animation: shoot-travel var(--shoot-dur, 0.65s) ease-out forwards;
    }
    @keyframes shoot-travel {
      0%   { opacity: 0; transform: rotate(var(--shoot-angle)) translateX(0); }
      8%   { opacity: 1; }
      100% { opacity: 0; transform: rotate(var(--shoot-angle)) translateX(var(--shoot-dist)); }
    }

    /* ── Mobile ── */
    @media (max-width: 480px) {
      .about-header { padding: 0 16px; height: 50px; }
      .about-nav a { font-size: 11px; padding: 6px 8px; }
      .about-hero { padding: 72px 16px 48px; }
      .about-hero p { font-size: 15px; }
      .about-content { padding: 0 16px 60px; }
      .section-title { font-size: 22px; }
      .booth-callout { padding: 24px 20px; }
      .about-cta { padding: 40px 20px; margin-top: 48px; }
      .about-cta h2 { font-size: 22px; }
      .privacy-grid { grid-template-columns: 1fr; gap: 12px; }
    }
  </style>
</head>
<body>
<?php if ($siteBgPath): ?>
<div id="site-bg-layer" style="position:fixed;inset:0;z-index:0;overflow:hidden;pointer-events:none;">
  <?php if ($siteBgType === 'video'): ?>
    <video autoplay muted loop playsinline style="width:100%;height:100%;object-fit:cover;" src="<?= htmlspecialchars($siteBgPath) ?>"></video>
  <?php else: ?>
    <img src="<?= htmlspecialchars($siteBgPath) ?>" style="width:100%;height:100%;object-fit:cover;" alt="">
  <?php endif; ?>
  <div style="position:absolute;inset:0;background:rgba(0,0,0,0.5);"></div>
</div>
<style nonce="<?= $GLOBALS['csp_nonce'] ?>">#sky-canvas,div.sky-canvas{display:none!important;}</style>
<?php endif; ?>

<!-- Night sky -->
<div class="sky-canvas" aria-hidden="true">
  <div class="sky-moon-glow"></div>
  <div class="sky-horizon"></div>
  <div class="star-layer" id="star-layer-0"></div>
  <div class="star-layer" id="star-layer-1"></div>
  <div class="star-layer" id="star-layer-2"></div>
  <div class="sky-cloud" style="width:680px;height:180px;top:14%;background:radial-gradient(ellipse,rgba(180,195,255,0.055) 0%,transparent 70%);filter:blur(50px);--cdur:110s;--cdelay:-32s;--drift-y:-12px;"></div>
  <div class="sky-cloud" style="width:420px;height:120px;top:31%;background:radial-gradient(ellipse,rgba(200,185,255,0.04) 0%,transparent 70%);filter:blur(40px);--cdur:85s;--cdelay:-18s;--drift-y:8px;"></div>
  <div class="sky-cloud" style="width:820px;height:240px;top:54%;background:radial-gradient(ellipse,rgba(160,175,255,0.045) 0%,rgba(124,106,247,0.02) 50%,transparent 70%);filter:blur(65px);--cdur:140s;--cdelay:-60s;--drift-y:-20px;"></div>
  <div class="sky-cloud" style="width:560px;height:160px;top:71%;background:radial-gradient(ellipse,rgba(150,160,255,0.04) 0%,transparent 70%);filter:blur(55px);--cdur:95s;--cdelay:-44s;--drift-y:-8px;"></div>
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
  // Shooting stars
  const skyEl = document.querySelector('.sky-canvas');
  function spawnShootingStar() {
    if (!skyEl) return;
    const el = document.createElement('div');
    el.className = 'shooting-star';
    const angle = -(12 + rnd(0, 22));
    const length = 90 + rnd(0, 110);
    const dist   = 260 + rnd(0, 200);
    const dur    = (0.45 + rnd(0, 0.4)).toFixed(2);
    el.style.cssText = `width:${length}px;top:${rnd(5,62).toFixed(1)}%;left:${rnd(5,78).toFixed(1)}%;--shoot-angle:${angle.toFixed(1)}deg;--shoot-dist:${dist.toFixed(0)}px;--shoot-dur:${dur}s`;
    skyEl.appendChild(el);
    setTimeout(() => el.remove(), parseFloat(dur) * 1000 + 80);
    setTimeout(spawnShootingStar, 3000 + rnd(0, 2000));
  }
  setTimeout(spawnShootingStar, 1200 + rnd(0, 1800));
})();
</script>

<!-- Header -->
<header class="about-header">
  <a href="/" class="about-brand">
    <?php if ($appLogo): ?>
      <img src="<?= htmlspecialchars($appLogo) ?>" alt="<?= htmlspecialchars($appName) ?>" style="height:48px;">
    <?php else: ?>
      <?= htmlspecialchars($appName) ?>
    <?php endif; ?>
  </a>
  <nav class="about-nav">
    <a href="/?browse=1">Find a Practitioner</a>
    <a href="/coverage.php">Coverage</a>
    <a href="/login.php">Login</a>
    <?php if ($registrationsEnabled): ?>
      <a href="/register.php" class="btn-primary">Create Account</a>
    <?php endif; ?>
  </nav>
</header>

<!-- Page -->
<div class="about-page">

  <!-- Hero -->
  <div class="about-hero">
    <?php if ($appLogo): ?>
      <img src="<?= htmlspecialchars($appLogo) ?>" alt="<?= htmlspecialchars($appName) ?>" class="hero-logo">
    <?php else: ?>
      <span class="about-hero-label">True Co-existence</span>
    <?php endif; ?>
    <h1>Every kind of healer. Every kind of seeker. One quiet space.</h1>
    <p>Licensed mental health professionals and unlicensed counselors. Faith-based practitioners and Pride-affirming spaces. Peer support and philosophical inquiry. Different credentials, different traditions, different communities — not in competition, not in conflict, but doing what each does best, side by side. <?= htmlspecialchars($appName) ?> exists because the world is already loud enough with division. This is the other thing: a space where the full spectrum of care comes together in harmony, for the people who need it most.</p>
    <div class="about-hero-actions">
      <a href="/?browse=1" class="hero-cta-primary">Find a Practitioner</a>
      <a href="/login.php" class="hero-cta-ghost">I'm a Practitioner</a>
    </div>
  </div>

  <div class="about-content">

    <div class="about-divider"></div>

    <!-- What is this -->
    <div>
      <div class="section-label">What we are</div>
      <div class="section-title">Built for support, not for scale</div>
      <div class="section-body">
        <p><?= htmlspecialchars($appName) ?> is a self-hosted platform for one-on-one therapeutic and support conversations. It was built for practitioners who want a private, professional space for their sessions — and for clients who deserve better than a waiting list and a subscription queue.</p>
        <p>There's no algorithm deciding who you see. No recommendations engine. No advertising. Just a quiet, encrypted space between you and the person you came to talk to — and the tools both of you need to make that conversation meaningful.</p>
      </div>
    </div>

    <div class="booth-callout">
      <h3>How it works — the booth model</h3>
      <p>Think of it like a salon that rents out booths. The salon provides the space; each practitioner brings their own clients and runs their own practice. <?= htmlspecialchars($appName) ?> works the same way.</p>
      <p>The platform owner sets up the space. Independent practitioners — therapists, peer support specialists, life coaches, philosophical counselors — use it to host their sessions. Clients book directly with the practitioner they choose. Everyone benefits from a shared, well-maintained environment.</p>
      <p>Practitioners get a professional, private home for their work without the overhead of building one. Clients get access to support through a practitioner they trust, not a random assignment from a corporate platform.</p>
    </div>

    <div class="about-divider" style="margin-top:60px;"></div>

    <!-- For clients -->
    <div>
      <div class="section-label">For clients</div>
      <div class="section-title">From booking to session to reflection</div>
      <div class="section-body">
        <p>Sessions run entirely in your browser — no app to download. Browse practitioners, book a time that suits you, and join with a single click when the moment comes. An account is optional; you can join as a guest if you prefer.</p>
      </div>
      <div class="about-two-col" style="margin-top:32px;">
        <div class="about-card">
          <div class="about-card-icon">📅</div>
          <h3>Book directly</h3>
          <p>Browse the practitioner directory, view availability, and request a session time. No gatekeeping, no referral letters, no waiting months for an intake call.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">🎥</div>
          <h3>Video, voice &amp; text</h3>
          <p>Join with video and microphone, voice only, or purely in text — whatever feels right on the day. No camera ever means no camera. No judgment.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">⏳</div>
          <h3>A warm waiting room</h3>
          <p>If you arrive before your practitioner, you'll wait in a calm space that shows their name and photo — not a blank loading screen. You're in the right place.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">✉</div>
          <h3>Between sessions</h3>
          <p>Message your practitioner directly through your portal — text, image, or a voice note when typing doesn't feel right. No need to swap personal contact details.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">📓</div>
          <h3>My Notes</h3>
          <p>Each session has a private reflection space visible only to you — never to your practitioner. A place to capture what landed, what you want to remember, what you're still sitting with.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">🔒</div>
          <h3>Your portal, your data</h3>
          <p>View upcoming and past sessions, manage bookings, and request full deletion of your personal data at any time — all from your own private portal.</p>
        </div>
      </div>
    </div>

    <div class="about-divider" style="margin-top:60px;"></div>

    <!-- For practitioners -->
    <div>
      <div class="section-label">For practitioners</div>
      <div class="section-title">Every tool your practice needs. Nothing it doesn't.</div>
      <div class="section-body">
        <p>Whether you're a licensed therapist, a certified peer support specialist, a life coach, or a philosophical counselor, <?= htmlspecialchars($appName) ?> gives you a full professional environment for your work — without tying you to a corporate platform's terms, pricing, or oversight.</p>
      </div>
      <div class="about-two-col" style="margin-top:32px;">
        <div class="about-card">
          <div class="about-card-icon">🌿</div>
          <h3>Private session rooms</h3>
          <p>Each room is yours to shape. Upload a custom background — still image or looping video — to set the tone before a word is spoken. A forest. A quiet study. Something abstract and grounding. The space is part of the work, and here you control it.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">📋</div>
          <h3>Clinical &amp; session notes</h3>
          <p>Take notes during sessions using SOAP, DAP, or BIRP templates — or freeform markdown with a full toolbar. Notes are encrypted at rest and tied to individual participants. Review previous session notes directly within the room.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">🗺</div>
          <h3>Session journeys</h3>
          <p>Track inquiry arcs across multiple sessions with the same client. Create a named journey with a central question or theme, log session-by-session insights and recaps, and maintain arc notes that evolve over time.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">✅</div>
          <h3>Action items</h3>
          <p>Capture commitments and next steps during the session. Action items persist across sessions within a series so nothing falls through the cracks between conversations.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">📤</div>
          <h3>Session recap &amp; export</h3>
          <p>At the end of each session, complete a structured recap — what was explored, key insights, agreed actions, next focus. Export the full transcript with your notes and recap as a printable HTML file.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">🎨</div>
          <h3>In-session activities</h3>
          <p>Launch collaborative activities directly within a session — shared paint canvas, chess, checkers, or a zen garden. Structured engagement tools for when conversation benefits from something to do together.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">🗓</div>
          <h3>Schedule &amp; bookings</h3>
          <p>Set your availability by day and time in your timezone. Clients book from your available slots. Confirm, reschedule, or cancel from a single view — with a full calendar overview when you need it.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">🔔</div>
          <h3>Appointment reminders</h3>
          <p>Automated 24-hour reminder emails go out to both practitioner and client before each scheduled session. Reminders handle registered clients and guest bookings alike, formatted in the practitioner's timezone. Powered by the built-in cron system — no third-party scheduler required.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">⏱</div>
          <h3>Cron &amp; automation manager</h3>
          <p>A built-in job scheduler handles recurring platform tasks — firewall cleanup, GDPR IP purging, and appointment reminders — without requiring server-level cron access. Supports real system crons for full control, or a WordPress-style web-trigger fallback for shared hosting environments. The admin panel shows each job's last run time, status, output, and duration.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">💳</div>
          <h3>Payments</h3>
          <p>Accept payments via PayPal, Stripe, Square, or Bitcoin — or offer sessions free or by manual arrangement. Configure per-session rates, discount campaigns, and practitioner-created discount codes with per-client and total use limits. Enabling a payment processor requires uploading a Business Associate Agreement (BAA) or Data Processing Agreement (DPA) first, depending on your region.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">📝</div>
          <h3>Intake forms</h3>
          <p>Build custom intake forms with text, select, radio, and checkbox fields. Assign them to individual bookings from your dashboard — the client receives a secure email link and submits before the session. View all responses in one place.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">🎯</div>
          <h3>Goal &amp; progress tracking</h3>
          <p>Set goals per client with title, description, and target date. Break each goal into milestones and log progress updates with an optional percentage — visualised as a progress bar. All accessible from the client detail view in your dashboard.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">⚠️</div>
          <h3>Crisis resource signposting</h3>
          <p>A single-click panel gives you instant access to pre-configured crisis resources during any session — 988 Lifeline, Crisis Text Line, SAMHSA, and others. Sending a resource delivers it as a visually distinct message the client cannot miss. Resources are fully configurable by the platform operator.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">🏷</div>
          <h3>Concept &amp; theme tagging</h3>
          <p>Tag individual messages and notes by concept during or after sessions — autonomy, meaning, grief, identity, or any theme you define. Your tag library is personal to you. A cross-session Themes view in the client panel surfaces recurring patterns across all sessions with a given client.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">🤖</div>
          <h3>AI assistant integration</h3>
          <p>Connect an AI assistant to your practice — OpenAI, Anthropic, Google Gemini, or Cohere. Give it a name, an avatar, and a system prompt shaped to your approach. Use it to generate session summaries, assist with clinical notes, or invite it into the room as a named participant that responds when addressed directly. Client consent is captured at booking and manageable from their portal at any time.</p>
        </div>
        <div class="about-card">
          <div class="about-card-icon">📖</div>
          <h3>Media recommendations</h3>
          <p>Recommend books, films, and series to clients directly from within a session or through the inbox. Connect Open Library (free, no API key required), Google Books, or TMDB for film and TV. Search, select, and send — recommendations arrive as rich cards with cover art, title, and metadata, not bare links.</p>
        </div>
      </div>

      <div class="booth-callout" style="margin-top:48px;">
        <h3>Licensed practitioners</h3>
        <p>If you hold a professional licence — as a therapist, counsellor, or other regulated practitioner — you can submit your credentials for verification. Once approved, your directory listing is updated with a licensed clinical badge, and you gain access to clinical practice type categories. Non-licensed practitioners are never misrepresented.</p>
        <p>All practitioners, licensed or otherwise, are verified by the platform operator before receiving access.</p>
      </div>
    </div>

    <div class="about-divider" style="margin-top:60px;"></div>

    <!-- Privacy -->
    <div>
      <div class="section-label">Privacy &amp; security</div>
      <div class="section-title">Genuine privacy. Not a checkbox.</div>
      <div class="section-body">
        <p>Therapeutic conversations carry information that can define someone's life. <?= htmlspecialchars($appName) ?> is built with that weight in mind — privacy as architecture, not policy.</p>
      </div>
      <div class="privacy-grid">
        <div class="privacy-item">
          <div class="privacy-item-head">
            <div class="privacy-dot"></div>
            <h4>Encrypted sensitive data</h4>
          </div>
          <p>Session transcripts, clinical notes, client reflection notes, and special category personal data (gender, sexuality, date of birth) are encrypted at rest using AES-256-GCM with context-authenticated encryption. Even database access doesn't expose them in plaintext.</p>
        </div>
        <div class="privacy-item">
          <div class="privacy-item-head">
            <div class="privacy-dot"></div>
            <h4>Explicit consent for sensitive data</h4>
          </div>
          <p>If you choose to share identity-related information at booking, you're asked for explicit consent under HIPAA and applicable privacy law. That consent is timestamped and audited. You can decline and discuss it in session instead.</p>
        </div>
        <div class="privacy-item">
          <div class="privacy-item-head">
            <div class="privacy-dot"></div>
            <h4>No third-party tracking</h4>
          </div>
          <p>No advertising networks. No analytics services monitoring your session. No external scripts loaded during a conversation. What happens in your room stays there.</p>
        </div>
        <div class="privacy-item">
          <div class="privacy-item-head">
            <div class="privacy-dot"></div>
            <h4>Peer-to-peer video</h4>
          </div>
          <p>Video and audio travel directly between participants via WebRTC DTLS-SRTP — no media server in the middle capturing your conversation in transit.</p>
        </div>
        <div class="privacy-item">
          <div class="privacy-item-head">
            <div class="privacy-dot"></div>
            <h4>Your right to erasure</h4>
          </div>
          <p>Clients can request full deletion of their personal data at any time from their portal. Requests are tracked and processed within 30 days, in line with HIPAA and applicable privacy regulations. A complete audit trail is maintained.</p>
        </div>
        <div class="privacy-item">
          <div class="privacy-item-head">
            <div class="privacy-dot"></div>
            <h4>HIPAA by design</h4>
          </div>
          <p>This platform is built to HIPAA standards — Privacy Rule, Security Rule, Breach Notification Rule, data minimisation, purpose limitation. Hosted on HIPAA-compliant infrastructure in Virginia, USA. Not retrofitted compliance — designed in from the start.</p>
        </div>
        <div class="privacy-item">
          <div class="privacy-item-head">
            <div class="privacy-dot"></div>
            <h4>Brute-force protection</h4>
          </div>
          <p>Automatic rate limiting, progressive delays, and IP blocking protect practitioner and client logins. No third-party identity services, no data leaving the platform to verify who you are.</p>
        </div>
        <div class="privacy-item">
          <div class="privacy-item-head">
            <div class="privacy-dot"></div>
            <h4>Self-hosted by design</h4>
          </div>
          <p>This platform runs on infrastructure controlled by the operator who deployed it — not a shared SaaS environment where your data sits alongside hundreds of other organisations.</p>
        </div>
        <div class="privacy-item">
          <div class="privacy-item-head">
            <div class="privacy-dot"></div>
            <h4>Explicit AI consent</h4>
          </div>
          <p>If your practitioner uses an AI assistant, you are told who provides it and what it can access before you book. Consent is granular — summarisation, note assistance, post-session discussion, and in-session participation are each independently controllable. You can update your consent at any time from your client portal.</p>
        </div>
        <div class="privacy-item">
          <div class="privacy-item-head">
            <div class="privacy-dot"></div>
            <h4>Multi-jurisdiction compliance</h4>
          </div>
          <p>The platform operator configures which privacy regime applies — HIPAA (US), UK GDPR, EU GDPR, or a custom framework. The Block Builder system generates jurisdiction-appropriate consent language, privacy notices, and data processing disclosures. Booking consent capture, data deletion workflows, and audit trails adapt automatically to the configured jurisdiction.</p>
        </div>
      </div>
      <div style="margin-top:24px;text-align:center;">
        <a href="/privacy.php" style="font-size:13px;color:rgba(124,106,247,0.75);text-decoration:none;border-bottom:1px solid rgba(124,106,247,0.25);padding-bottom:1px;">Read the full Privacy Notice →</a>
      </div>
    </div>

    <div class="about-divider" style="margin-top:60px;"></div>

    <!-- Open source -->
    <div>
      <div class="section-label">Open source</div>
      <div class="section-title">Free to run, free to inspect</div>
      <div class="section-body">
        <p><?= htmlspecialchars($appName) ?> is free and open source. The full source code is available for anyone to review, self-host, or contribute to. There is no premium tier, no per-seat pricing, and no proprietary lock-in.</p>
        <p>The platform exists because access to mental health support shouldn't depend on your zip code, your income, or whether you live near a city. Self-hosting means any practitioner, any collective, any community organisation can run it — on their own terms, for the people they serve.</p>
        <p>If you're a developer, designer, or practitioner who sees what this is trying to do and wants to be part of it, contributions are welcome.</p>
      </div>
      <div style="margin-top:24px;text-align:center;">
        <a href="https://github.com/serenityspaces/" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:10px;padding:12px 24px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);border-radius:10px;text-decoration:none;color:rgba(220,220,255,0.85);font-size:14px;font-weight:600;transition:background 0.15s,border-color 0.15s;" onmouseover="this.style.background='rgba(255,255,255,0.1)';this.style.borderColor='rgba(255,255,255,0.2)'" onmouseout="this.style.background='rgba(255,255,255,0.06)';this.style.borderColor='rgba(255,255,255,0.12)'">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.477 2 2 6.477 2 12c0 4.418 2.865 8.166 6.839 9.489.5.092.682-.217.682-.482 0-.237-.009-.868-.013-1.703-2.782.604-3.369-1.34-3.369-1.34-.454-1.154-1.11-1.462-1.11-1.462-.908-.62.069-.608.069-.608 1.003.07 1.531 1.03 1.531 1.03.892 1.529 2.341 1.087 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.11-4.555-4.943 0-1.091.39-1.984 1.029-2.683-.103-.253-.446-1.27.098-2.647 0 0 .84-.269 2.75 1.025A9.578 9.578 0 0112 6.836c.85.004 1.705.114 2.504.336 1.909-1.294 2.747-1.025 2.747-1.025.546 1.377.202 2.394.1 2.647.64.699 1.028 1.592 1.028 2.683 0 3.842-2.339 4.687-4.566 4.935.359.309.678.919.678 1.852 0 1.336-.012 2.415-.012 2.743 0 .267.18.578.688.48C19.138 20.163 22 16.418 22 12c0-5.523-4.477-10-10-10z"/></svg>
          View on GitHub
        </a>
      </div>
    </div>

    <!-- CTA -->
    <div class="about-cta">
      <h2>Ready to get started?</h2>
      <p>Browse available practitioners and request a session — or sign in if you're a practitioner looking for your dashboard.</p>
      <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
        <a href="/?browse=1" class="hero-cta-primary" style="font-size:14px;font-weight:600;padding:12px 28px;border-radius:10px;text-decoration:none;background:rgba(124,106,247,0.2);border:1px solid rgba(124,106,247,0.45);color:#c5b8ff;">Find a Practitioner</a>
        <a href="/login.php" class="hero-cta-ghost" style="font-size:14px;font-weight:600;padding:12px 28px;border-radius:10px;text-decoration:none;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);color:rgba(220,220,255,0.7);">Sign In</a>
      </div>
    </div>

  </div><!-- /.about-content -->

  <footer class="about-footer">
    <p><?= htmlspecialchars($appName) ?> &nbsp;·&nbsp; <a href="/">Home</a> &nbsp;·&nbsp; <a href="/?browse=1">Find a Practitioner</a> &nbsp;·&nbsp; <a href="/privacy.php">Privacy Notice</a></p>
  </footer>

</div><!-- /.about-page -->

</body>
</html>
