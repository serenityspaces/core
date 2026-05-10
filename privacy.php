<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/jurisdiction/jurisdiction_helper.php';
require_once __DIR__ . '/includes/jurisdiction/blocks_privacy.php';
requireSetup();

$appName    = getSetting('app_name', 'Serenity Spaces');
$appLogo    = getSetting('logo_path', '');
$appTheme   = getSetting('theme', 'dark');
$siteBgPath = getSetting('site_background_path', '');
$siteBgType = getSetting('site_background_type', '');
$hostUrl = !empty($_SERVER['HTTP_HOST'])
    ? (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
    : rtrim(getSetting('app_url', 'https://your-domain.example'), '/');

$privacyVersion = '2.1';
$privacyDate    = '9 May 2026';

// ── Jurisdiction context ─────────────────────────────────────────
$ctx             = jur_build_ctx($appName, $hostUrl);
$activeJuris     = jur_active_list();         // primary first, then additional
$primaryJuris    = jur_primary();

// Build the header badge label from active jurisdictions
$jurisLabels = [];
foreach ($activeJuris as $jc) {
    $jurisLabels[] = match($jc) {
        'eu'        => 'GDPR',
        'uk'        => 'UK GDPR',
        'us'        => 'HIPAA · CCPA',
        'canada'    => 'PIPEDA',
        'australia' => 'APPs',
        'custom'    => 'Custom',
        default     => strtoupper($jc),
    };
}
$badgeText = implode(' · ', $jurisLabels) ?: 'Privacy by Design';

// Build supervisory authority paragraph for Section 06
function priv_supervisory_para(array $activeJuris, array $ctx): string {
    $parts = [];
    if (in_array('eu', $activeJuris, true)) {
        $parts[] = 'For GDPR-related concerns, contact the data protection supervisory authority in your EU member state. '
                 . 'A list of national supervisory authorities is available at '
                 . '<a href="https://edpb.europa.eu/about-edpb/about-edpb/members_en" style="color:rgba(196,184,255,0.85);" target="_blank" rel="noopener">edpb.europa.eu</a>.';
    }
    if (in_array('uk', $activeJuris, true)) {
        $parts[] = 'For UK GDPR concerns, contact the <strong>Information Commissioner\'s Office (ICO)</strong> at '
                 . '<a href="https://ico.org.uk/make-a-complaint/" style="color:rgba(196,184,255,0.85);" target="_blank" rel="noopener">ico.org.uk/make-a-complaint</a>.';
    }
    if (in_array('us', $activeJuris, true)) {
        $parts[] = 'For HIPAA-related concerns, contact the <strong>HHS Office for Civil Rights (OCR)</strong> at '
                 . '<a href="https://www.hhs.gov/hipaa/filing-a-complaint" style="color:rgba(196,184,255,0.85);" target="_blank" rel="noopener">hhs.gov/hipaa/filing-a-complaint</a>. '
                 . 'For California privacy rights concerns, contact the '
                 . '<strong>California Privacy Protection Agency (CPPA)</strong> at '
                 . '<a href="https://cppa.ca.gov" style="color:rgba(196,184,255,0.85);" target="_blank" rel="noopener">cppa.ca.gov</a>.';
    }
    if (in_array('canada', $activeJuris, true)) {
        $parts[] = 'For PIPEDA-related concerns, contact the <strong>Office of the Privacy Commissioner of Canada</strong> at '
                 . '<a href="https://www.priv.gc.ca/en/report-a-concern/" style="color:rgba(196,184,255,0.85);" target="_blank" rel="noopener">priv.gc.ca/en/report-a-concern</a>. '
                 . 'Québec residents may also contact the <strong>Commission d\'accès à l\'information (CAI)</strong>.';
    }
    if (in_array('australia', $activeJuris, true)) {
        $parts[] = 'For Australian Privacy Act concerns, contact the <strong>Office of the Australian Information Commissioner (OAIC)</strong> at '
                 . '<a href="https://www.oaic.gov.au/privacy/privacy-complaints" style="color:rgba(196,184,255,0.85);" target="_blank" rel="noopener">oaic.gov.au/privacy/privacy-complaints</a>.';
    }
    if (empty($parts)) {
        $parts[] = 'Contact the data controller using the details in Section 01 if you have any concerns about how your data is handled.';
    }
    return implode(' ', $parts);
}

// Assemble jurisdiction supplement blocks
function priv_supplement_blocks(array $activeJuris, array $ctx): string {
    $out = '';
    foreach ($activeJuris as $jc) {
        $out .= match($jc) {
            'eu'        => priv_block_eu($ctx),
            'uk'        => priv_block_uk($ctx),
            'us'        => priv_block_us($ctx),
            'canada'    => priv_block_canada($ctx),
            'australia' => priv_block_australia($ctx),
            'custom'    => priv_block_custom($ctx),
            default     => '',
        };
    }
    return $out;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Privacy Notice — <?= htmlspecialchars($appName) ?></title>
  <link rel="stylesheet" href="/assets/css/fonts.css">
  <link rel="stylesheet" href="/assets/css/main.css">
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
    :root {
      --sky-void: #050810; --sky-deep: #080d1e; --sky-mid: #0d1433;
      --star-color: #e8eeff;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { overflow-y: auto; height: auto; }
    body {
      font-family: 'Outfit', sans-serif;
      background: var(--sky-void); color: #dddcf2; min-height: 100vh;
    }
    .priv-page {
      max-width: 800px; margin: 0 auto; padding: 60px 24px 80px;
    }
    .priv-topbar {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 48px; padding-bottom: 20px;
      border-bottom: 1px solid rgba(255,255,255,0.06);
    }
    .priv-topbar a { text-decoration: none; color: rgba(200,210,255,0.5); font-size: 13px; }
    .priv-topbar a:hover { color: rgba(200,210,255,0.85); }
    .priv-badge {
      font-size: 11px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase;
      color: rgba(124,106,247,0.7); padding: 4px 10px; border-radius: 20px;
      background: rgba(124,106,247,0.1); border: 1px solid rgba(124,106,247,0.2);
    }
    h1 {
      font-size: 32px; font-weight: 800; letter-spacing: -0.8px; color: #e8eeff;
      margin-bottom: 10px; line-height: 1.2;
    }
    .priv-meta {
      font-size: 13px; color: rgba(200,210,255,0.4); margin-bottom: 40px;
    }
    .priv-intro {
      font-size: 15px; color: rgba(200,210,255,0.65); line-height: 1.7;
      margin-bottom: 48px; padding: 20px 24px;
      background: rgba(124,106,247,0.05); border: 1px solid rgba(124,106,247,0.15);
      border-radius: 12px;
    }
    .priv-section {
      margin-bottom: 40px; padding-bottom: 40px;
      border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .priv-section:last-of-type { border-bottom: none; }
    .priv-section-num {
      font-size: 11px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase;
      color: rgba(124,106,247,0.6); margin-bottom: 6px;
    }
    h2 {
      font-size: 19px; font-weight: 700; color: #e8eeff; margin-bottom: 14px;
      letter-spacing: -0.3px;
    }
    h3 { /* used inside sections */ }
    p { font-size: 14px; color: rgba(200,210,255,0.6); line-height: 1.75; margin-bottom: 12px; }
    p:last-child { margin-bottom: 0; }
    ul {
      padding-left: 0; list-style: none; margin-bottom: 12px;
    }
    ul li {
      font-size: 14px; color: rgba(200,210,255,0.6); line-height: 1.7;
      padding: 5px 0 5px 20px; position: relative;
    }
    ul li::before {
      content: '·'; position: absolute; left: 6px; color: rgba(124,106,247,0.6);
      font-size: 16px; line-height: 1.7;
    }
    .data-table {
      width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px;
    }
    .data-table th {
      text-align: left; padding: 10px 12px; font-size: 11px; font-weight: 600;
      letter-spacing: 0.06em; text-transform: uppercase;
      color: rgba(124,106,247,0.7); border-bottom: 1px solid rgba(255,255,255,0.08);
      background: rgba(124,106,247,0.05);
    }
    .data-table td {
      padding: 10px 12px; color: rgba(200,210,255,0.6);
      border-bottom: 1px solid rgba(255,255,255,0.04); vertical-align: top;
    }
    .data-table tr:last-child td { border-bottom: none; }
    .rights-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 12px; margin: 16px 0;
    }
    .right-card {
      padding: 14px 16px; background: rgba(255,255,255,0.025);
      border: 1px solid rgba(255,255,255,0.07); border-radius: 10px;
    }
    .right-card h4 { font-size: 13px; font-weight: 700; color: #c8d2ff; margin-bottom: 5px; }
    .right-card p  { font-size: 12px; margin-bottom: 0; }
    .priv-contact-box {
      margin-top: 16px; padding: 18px 20px;
      background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.07);
      border-radius: 10px;
    }
    .priv-contact-box p { font-size: 13px; }
    .priv-contact-box a { color: rgba(196,184,255,0.85); text-decoration: none; }
    .priv-contact-box a:hover { text-decoration: underline; }
    .alert-special {
      padding: 14px 18px; margin: 16px 0;
      background: rgba(232,85,85,0.07); border: 1px solid rgba(232,85,85,0.2);
      border-radius: 8px; font-size: 13px; color: rgba(255,180,180,0.8); line-height: 1.6;
    }
    /* Jurisdiction supplement section divider */
    .priv-juris-divider {
      margin: 48px 0 32px;
      display: flex; align-items: center; gap: 16px;
    }
    .priv-juris-divider::before,
    .priv-juris-divider::after {
      content: ''; flex: 1; height: 1px; background: rgba(124,106,247,0.15);
    }
    .priv-juris-divider-label {
      font-size: 11px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase;
      color: rgba(124,106,247,0.5); white-space: nowrap;
    }
    .priv-footer {
      margin-top: 60px; padding-top: 24px; border-top: 1px solid rgba(255,255,255,0.06);
      font-size: 13px; color: rgba(200,210,255,0.3); text-align: center;
    }
    .priv-footer a { color: rgba(200,210,255,0.45); text-decoration: none; }
    .priv-footer a:hover { color: rgba(200,210,255,0.7); }
  </style>
</head>
<body class="theme-<?= htmlspecialchars($appTheme) ?>">
<?php if ($siteBgPath): ?>
<div id="site-bg-layer" style="position:fixed;inset:0;z-index:0;overflow:hidden;pointer-events:none;">
  <?php if ($siteBgType === 'video'): ?>
    <video autoplay muted loop playsinline style="width:100%;height:100%;object-fit:cover;" src="<?= htmlspecialchars($siteBgPath) ?>"></video>
  <?php else: ?>
    <img src="<?= htmlspecialchars($siteBgPath) ?>" style="width:100%;height:100%;object-fit:cover;" alt="">
  <?php endif; ?>
  <div style="position:absolute;inset:0;background:rgba(0,0,0,0.5);"></div>
</div>
<?php endif; ?>

<?php
// Admin-only warning: controller details not configured
$_adminRow = null;
if (!empty($_SESSION['practitioner_id']) && isset($pdo) && $pdo instanceof PDO) {
    $_adminStmt = $pdo->prepare('SELECT is_admin FROM practitioners WHERE id = ? LIMIT 1');
    $_adminStmt->execute([(int)$_SESSION['practitioner_id']]);
    $_adminRow = $_adminStmt->fetch();
}
$_isAdmin = !empty($_SESSION['is_admin']) || (!empty($_adminRow['is_admin']));
$_ctrlMissing = (getSetting('controller_name','') === '' || getSetting('controller_email','') === '');
if ($_isAdmin && $_ctrlMissing): ?>
<div style="position:fixed;top:0;left:0;right:0;z-index:9999;background:#e07a30;color:#fff;font-size:13px;font-weight:600;text-align:center;padding:10px 16px;display:flex;align-items:center;justify-content:center;gap:12px;">
  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
  Admin notice: Controller name and/or email not configured — this privacy notice is legally incomplete under Art. 13(1)(a) UK GDPR.
  <a href="/admin.php#compliance" style="color:#fff;font-weight:700;text-decoration:underline;white-space:nowrap;">Configure in Admin → Compliance</a>
</div>
<div style="height:44px;"></div>
<?php endif; ?>

<div class="priv-page">

  <div class="priv-topbar">
    <a href="/">← <?= htmlspecialchars($appName) ?></a>
    <span class="priv-badge"><?= htmlspecialchars($badgeText) ?></span>
  </div>

  <h1>Privacy Notice</h1>
  <div class="priv-meta">Version <?= htmlspecialchars($privacyVersion) ?> &nbsp;·&nbsp; Last updated <?= htmlspecialchars($privacyDate) ?></div>

  <div class="priv-intro">
    This notice explains what personal data <?= htmlspecialchars($appName) ?> collects, why it is collected, the legal basis for processing it, how long it is kept, and the rights available to you. It applies to all clients, practitioners, and visitors of this platform.
    <?php if (count($activeJuris) > 1): ?>
    <br><br>This platform is subject to multiple privacy frameworks. Jurisdiction-specific supplements appear after the core notice below.
    <?php endif; ?>
  </div>

  <!-- ═══════════════════════════════════════════════
       CORE SECTIONS (01–09) — apply to all jurisdictions
       ═══════════════════════════════════════════════ -->

  <!-- 1. Controller identity -->
  <div class="priv-section">
    <div class="priv-section-num">Section 01</div>
    <h2>Who is the Data Controller?</h2>
    <p>The data controller for <?= htmlspecialchars($appName) ?> is the organisation or individual who operates this instance of the platform. If you are unsure who this is, contact the practitioner who referred you to this platform or reach out via the contact details below.</p>
    <p>This platform is self-hosted software. The operator — not the software developers — is the data controller responsible for all processing described in this notice.</p>

    <?php if ($ctx['controller_name'] || $ctx['controller_email'] || $ctx['controller_address']): ?>
    <div class="priv-contact-box">
      <p><strong style="color:#c8d2ff;">Data Controller</strong><br>
      <?php if ($ctx['controller_name']): ?>
        <?= htmlspecialchars($ctx['controller_name']) ?><br>
      <?php endif; ?>
      <?php if ($ctx['controller_address']): ?>
        <?= htmlspecialchars($ctx['controller_address']) ?><br>
      <?php endif; ?>
      <?php if ($ctx['controller_email']): ?>
        Email: <a href="mailto:<?= htmlspecialchars($ctx['controller_email']) ?>"><?= htmlspecialchars($ctx['controller_email']) ?></a>
      <?php endif; ?>
      </p>
      <?php if ($ctx['dpo_name']): ?>
      <p style="margin-top:10px;"><strong style="color:#c8d2ff;">Data Protection Officer:</strong>
        <?= htmlspecialchars($ctx['dpo_name']) ?>
        <?php if ($ctx['dpo_email']): ?>
          — <a href="mailto:<?= htmlspecialchars($ctx['dpo_email']) ?>"><?= htmlspecialchars($ctx['dpo_email']) ?></a>
        <?php endif; ?>
      </p>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="priv-contact-box">
      <p><strong style="color:#c8d2ff;">Data Controller Contact</strong><br>
      For data protection enquiries, requests to exercise your rights, or to obtain the identity of the controller, contact the platform operator directly. If this platform is <?= htmlspecialchars($appName) ?> at <?= htmlspecialchars($hostUrl) ?>, you may use the contact method available on the About page or via any existing correspondence with your practitioner.</p>
    </div>
    <?php endif; ?>
  </div>

  <!-- 2. What data we process -->
  <div class="priv-section">
    <div class="priv-section-num">Section 02</div>
    <h2>What Personal Data We Process</h2>

    <table class="data-table">
      <thead>
        <tr>
          <th>Category</th>
          <th>Data elements</th>
          <th>Who it relates to</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>Account data</strong></td>
          <td>Email address, display name, hashed password, avatar image</td>
          <td>Practitioners, registered clients</td>
        </tr>
        <tr>
          <td><strong>Booking data</strong></td>
          <td>Name, email, date of birth, gender, sexuality, location (all encrypted at rest)</td>
          <td>Clients booking sessions</td>
        </tr>
        <tr>
          <td><strong>Session content</strong></td>
          <td>Chat messages, practitioner clinical notes, client private reflection notes, session highlights, file attachments, voice notes — chat messages and all notes encrypted at rest</td>
          <td>Session participants</td>
        </tr>
        <tr>
          <td><strong>Intake form responses</strong></td>
          <td>Responses to pre-session questionnaires set by the practitioner (may include health history, goals, or other clinical information provided before a session)</td>
          <td>Clients completing intake forms</td>
        </tr>
        <tr>
          <td><strong>Goal and progress data</strong></td>
          <td>Goals set by the practitioner per client, milestones, and progress update notes</td>
          <td>Clients with active goals</td>
        </tr>
        <tr>
          <td><strong>Referral data</strong></td>
          <td>Clinical context snapshot shared when a practitioner refers a client to another practitioner on this platform — may include goals, intake summary, session themes, or session notes depending on the sharing permissions set by the referring practitioner. Referral notes are encrypted at rest.</td>
          <td>Clients who are the subject of a practitioner-to-practitioner referral</td>
        </tr>
        <tr>
          <td><strong>Technical data</strong></td>
          <td>IP address, browser/device info, session tokens, audit log entries</td>
          <td>All users</td>
        </tr>
        <tr>
          <td><strong>Financial data</strong></td>
          <td>Session payment amounts, currency, payment method type (no card data stored on-platform)</td>
          <td>Clients paying for sessions</td>
        </tr>
        <tr>
          <td><strong>Communication data</strong></td>
          <td>Messages sent via the in-platform messaging system</td>
          <td>Practitioners and registered clients</td>
        </tr>
      </tbody>
    </table>

    <div class="alert-special">
      <strong>Sensitive personal data:</strong> Some booking information — specifically gender, sexuality, and in some contexts date of birth — constitutes sensitive personal data under HIPAA (where US infrastructure is used) and special category data under GDPR Art. 9 (where EU infrastructure is used). This data is collected only where explicitly provided by the client, processed under explicit informed consent, and encrypted at rest. You may decline to provide any of this information.
    </div>
  </div>

  <!-- 3. Why we process it and lawful basis -->
  <div class="priv-section">
    <div class="priv-section-num">Section 03</div>
    <h2>Why We Process Your Data and Our Lawful Basis</h2>

    <table class="data-table">
      <thead>
        <tr>
          <th>Purpose</th>
          <th>Legal basis</th>
          <th>Data involved</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Providing the session booking and scheduling service</td>
          <td>Treatment / Healthcare operations (HIPAA); Contractual necessity (GDPR Art. 6(1)(b)); Necessary for service (PIPEDA / APPs)</td>
          <td>Booking data, account data</td>
        </tr>
        <tr>
          <td>Enabling practitioners to run therapeutic sessions</td>
          <td>Treatment / Healthcare operations (HIPAA); Contractual necessity (GDPR Art. 6(1)(b)); Necessary for service (PIPEDA / APPs)</td>
          <td>Session content, account data</td>
        </tr>
        <tr>
          <td>Processing sensitive booking fields (gender, sexuality)</td>
          <td>Explicit informed consent (HIPAA Authorization; GDPR Art. 9(2)(a); PIPEDA express consent; APP 3.3)</td>
          <td>Gender, sexuality, date of birth</td>
        </tr>
        <tr>
          <td>Security, fraud prevention, and audit logging</td>
          <td>HIPAA Security Rule — required safeguard; Legitimate interests (GDPR Art. 6(1)(f)); Security purpose (PIPEDA / APPs)</td>
          <td>Technical data, IP addresses, audit log</td>
        </tr>
        <tr>
          <td>Processing payments</td>
          <td>Healthcare operations (HIPAA); Contractual necessity (GDPR Art. 6(1)(b)); Necessary for service (PIPEDA / APPs)</td>
          <td>Payment amount/status data</td>
        </tr>
        <tr>
          <td>Complying with legal obligations</td>
          <td>Legal obligation (HIPAA; GDPR Art. 6(1)(c); applicable national law)</td>
          <td>Data subject requests, audit records</td>
        </tr>
        <tr>
          <td>Client-initiated datacenter transfer</td>
          <td>Explicit informed consent (HIPAA Authorization; GDPR Art. 49(1)(a); PIPEDA / APP express consent)</td>
          <td>All data associated with your account</td>
        </tr>
      </tbody>
    </table>
    <p>Jurisdiction-specific details of legal bases — including full GDPR Article 6 and 9 breakdowns, HIPAA treatment/operations/payment categories, and the applicable basis under PIPEDA and the Australian Privacy Principles — are set out in the jurisdiction supplements below.</p>
  </div>

  <!-- 4. Retention -->
  <div class="priv-section">
    <div class="priv-section-num">Section 04</div>
    <h2>How Long We Keep Your Data</h2>
    <p>Session records — including messages, clinical notes, intake responses, and voice notes — are retained for <strong>7 years from the date of your last session</strong>. This meets or exceeds the HIPAA minimum retention requirement of 6 years and aligns with professional therapeutic record-keeping guidelines published by the American Counseling Association (ACA) and the National Association of Social Workers (NASW). It also meets or exceeds retention requirements under applicable data protection law in each supported jurisdiction. Retention of these records supports continuity of care if you return, and practitioner accountability obligations.</p>
    <p>You have the right to request deletion of your personal data at any time (see Section 06 — Your Rights). Where records are subject to a professional retention obligation, we will explain any applicable limits on deletion at the time of your request.</p>

    <table class="data-table">
      <thead>
        <tr><th>Data type</th><th>Retention period</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>Session messages and clinical notes</td>
          <td>7 years from last session (HIPAA minimum: 6 years; ACA/NASW professional standard: 7 years).</td>
        </tr>
        <tr>
          <td>Voice notes recorded during sessions</td>
          <td>7 years from last session. Deleted upon verified erasure request.</td>
        </tr>
        <tr>
          <td>File attachments uploaded during sessions</td>
          <td>Removed from server storage after session export. Text records of file names are retained as part of the session record.</td>
        </tr>
        <tr>
          <td>Intake form responses</td>
          <td>7 years from last session, as part of the clinical record.</td>
        </tr>
        <tr>
          <td>Goal and progress data</td>
          <td>7 years from last session. Deleted upon verified erasure request.</td>
        </tr>
        <tr>
          <td>Booking records</td>
          <td>7 years from last session (professional accountability and potential legal obligation).</td>
        </tr>
        <tr>
          <td>Account data (practitioners)</td>
          <td>Retained while the account is active. Deleted within 30 days of account closure request.</td>
        </tr>
        <tr>
          <td>Account data (registered clients)</td>
          <td>Retained while the account is active. Deleted within 30 days of account closure request, subject to any applicable professional retention obligations.</td>
        </tr>
        <tr>
          <td>Audit logs</td>
          <td>Retained for a minimum of 12 months for security and compliance purposes (HIPAA Security Rule requires 6 years for HIPAA-related security documentation).</td>
        </tr>
        <tr>
          <td>Consent records</td>
          <td>Retained for as long as the associated booking or account exists, plus 3 years thereafter (legal accountability).</td>
        </tr>
        <tr>
          <td>IP addresses and technical session data</td>
          <td>Retained for up to 90 days for security purposes, then deleted.</td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- 5. Who we share it with -->
  <div class="priv-section">
    <div class="priv-section-num">Section 05</div>
    <h2>Who We Share Your Data With</h2>
    <p>This platform does not sell, rent, or share personal data with third parties for marketing or advertising purposes.</p>
    <p>Data may be shared with or accessible to:</p>
    <ul>
      <li><strong>The practitioner you book with</strong> — they can see your booking information, session content, and notes as necessary to provide the service.</li>
      <li><strong>Another practitioner on this platform (referral only)</strong> — if your practitioner refers you to another practitioner on this platform for a different kind of support, a snapshot of your clinical context may be shared with the receiving practitioner. The scope of information shared is set by your referring practitioner and may include your goals, intake summary, session themes, or session notes. The referring practitioner can only make referrals where they have an existing booking relationship with you. You may ask your practitioner what data was shared as part of any referral.</li>
      <li><strong>The datacenter hosting provider</strong> — the infrastructure on which this platform runs. This platform supports regional datacenters; your data is stored in the region you selected. Each datacenter operates under the privacy framework applicable to its location. Hosting providers are bound by a Business Associate Agreement (BAA) for US infrastructure and a Data Processing Agreement (DPA) for EU/UK infrastructure.</li>
      <li><strong>Payment processors</strong> — if you pay for a session via PayPal, Stripe, Square, or a BTCPay Server instance, payment processing is handled by those services under their own privacy policies. This platform does not store card numbers or payment credentials. Practitioners are required to hold a signed BAA (US) or DPA (EU/UK) with their chosen payment processor before accepting payments. BTCPay Server is typically operator-self-hosted; the operator is responsible for the privacy and security of their BTCPay instance.</li>
      <li><strong>Email delivery providers</strong> — if email notifications are enabled, messages are routed through the configured SMTP provider. An appropriate data processing agreement is required with the SMTP provider before any personal data is transmitted.</li>
      <li><strong>AI service providers (conditional)</strong> — if your practitioner has enabled an AI assistant and you have granted consent, session content may be sent to their AI vendor (OpenAI, Anthropic, Google, or Cohere). Practitioners are required to hold a signed BAA or DPA with their AI vendor before enabling AI features. You are informed of which AI vendor is in use during the consent flow at booking.</li>
    </ul>
    <p>Any transfer of data to third-party processors is subject to appropriate agreement requirements. Practitioners are responsible for ensuring their chosen processors maintain safeguards consistent with the applicable regulatory framework.</p>

    <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Optional Media Recommendation Services</h3>
    <p>Practitioners may enable an optional media recommendation feature that surfaces book, film, and music suggestions during sessions. When this feature is active, practitioner-entered search queries are sent to the following external services to retrieve cover images and metadata:</p>
    <ul>
      <li><strong>The Movie Database (TMDB)</strong> — <code>image.tmdb.org</code> — film and TV recommendations.</li>
      <li><strong>Open Library</strong> — <code>covers.openlibrary.org</code> — book cover images.</li>
      <li><strong>Google Books</strong> — <code>books.google.com</code> — book metadata and cover images.</li>
      <li><strong>Internet Archive</strong> — <code>archive.org</code> — supplementary media metadata.</li>
    </ul>
    <p>No client personal data is transmitted to these services. Only practitioner-initiated search terms are sent. This feature is practitioner-configured and disabled by default. Clients are not aware of search queries made by practitioners using this feature.</p>

    <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Data Residency and Regional Datacenters</h3>
    <p>This platform operates regional datacenters that allow you to choose where your data is stored. Your chosen datacenter determines which privacy framework primarily governs the processing of your data:</p>
    <ul>
      <li><strong>United States (Virginia)</strong> — HIPAA-compliant infrastructure (OVH Public Cloud). Processing governed by HIPAA Privacy, Security, and Breach Notification Rules.</li>
      <li><strong>European Union (France)</strong> — GDPR-compliant infrastructure (OVH Public Cloud). Processing governed by EU GDPR. <em>Coming soon.</em></li>
    </ul>
    <p>You may request transfer of your data to a different regional datacenter at any time. Cross-region transfers require your explicit informed consent, are carried out securely using encrypted transfer, and are recorded in the audit log. Consent for a cross-region transfer may be withdrawn before the transfer completes; it cannot be undone after completion.</p>
  </div>

  <!-- 6. Your rights -->
  <div class="priv-section">
    <div class="priv-section-num">Section 06</div>
    <h2>Your Privacy Rights</h2>
    <p>You have the following rights in relation to your personal data. The specific rights available to you depend on the jurisdiction in which you are located; full details are in the jurisdiction supplements below.</p>
    <div class="rights-grid">
      <div class="right-card">
        <h4>Right of access</h4>
        <p>Request a copy of the personal data held about you.</p>
      </div>
      <div class="right-card">
        <h4>Right to rectification</h4>
        <p>Ask us to correct inaccurate data or complete incomplete data.</p>
      </div>
      <div class="right-card">
        <h4>Right to erasure</h4>
        <p>Request deletion of your personal data where there is no compelling reason to continue processing. Available directly from your client portal.</p>
      </div>
      <div class="right-card">
        <h4>Right to restrict processing</h4>
        <p>Ask us to limit how we use your data in certain circumstances.</p>
      </div>
      <div class="right-card">
        <h4>Right to data portability</h4>
        <p>Receive your data in a structured, machine-readable format.</p>
      </div>
      <div class="right-card">
        <h4>Right to object</h4>
        <p>Object to certain uses of your data, including uses based on legitimate interests.</p>
      </div>
      <div class="right-card">
        <h4>Withdraw consent</h4>
        <p>Where processing is based on consent, you may withdraw it at any time without affecting the lawfulness of prior processing.</p>
      </div>
      <div class="right-card">
        <h4>Rights re: automated decisions</h4>
        <p>We do not make solely automated decisions that significantly affect you.</p>
      </div>
    </div>
    <p style="margin-top:16px;">To exercise any of these rights, contact the data controller using the details in Section 01. <?= priv_supervisory_para($activeJuris, $ctx) ?></p>
  </div>

  <!-- 7. Cookies and tracking -->
  <div class="priv-section" id="cookies">
    <div class="priv-section-num">Section 07</div>
    <h2>Cookies and Session Data</h2>
    <p>This platform uses the following cookies and local storage items. All are strictly necessary for the operation of the service. No non-essential cookies are set.</p>
    <ul>
      <li><strong>PHP session cookie (PHPSESSID)</strong> — essential for authentication and CSRF protection. No tracking purpose.</li>
      <li><strong>Reconnect cookie (ss_reconnect_*)</strong> — stores a token to allow re-entry to an active session if your browser disconnects. Expires when the session ends.</li>
      <li><strong>Local storage (ss_tour_*)</strong> — remembers whether you have completed the onboarding tour. Not transmitted to the server.</li>
      <li><strong>Cookie (ss_cookie_consent)</strong> — records that you have acknowledged the cookie notice on the login or landing page. Expires after 12 months. You may withdraw acknowledgment at any time by clearing this cookie via your browser's cookie settings.</li>
    </ul>
    <p>No third-party analytics, advertising, or cross-site tracking cookies are set by this platform. A cookie notice rather than a full consent banner is appropriate as all cookies are strictly essential to the service.</p>
  </div>

  <!-- 8. Security -->
  <div class="priv-section">
    <div class="priv-section-num">Section 08</div>
    <h2>How We Protect Your Data</h2>
    <ul>
      <li>Booking PII fields (name, email, date of birth, gender, sexuality, location) are encrypted at rest using AES-256-GCM with context-authenticated encryption.</li>
      <li>Session chat messages, practitioner clinical notes, session recap summaries, and client private reflection notes are encrypted at rest using AES-256-GCM with context binding — each record's encryption is bound to its session context, making cross-record tampering cryptographically detectable.</li>
      <li>Intake form responses are stored in the database encrypted at rest using AES-256-GCM with context-bound AAD, using the same encryption infrastructure as messages and clinical notes.</li>
      <li>Passwords are hashed using bcrypt with a work factor appropriate to current hardware.</li>
      <li>CSRF tokens protect all state-changing actions.</li>
      <li>Login and registration endpoints are rate-limited and IP-blocked after repeated failures.</li>
      <li>Sessions are subject to automatic inactivity timeout: 15 minutes for practitioner accounts (which have access to PHI), 30 minutes for client accounts. Both practitioner and client accounts support optional TOTP two-factor authentication (Google Authenticator compatible).</li>
      <li>HTTP security headers (HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, Content-Security-Policy) are enforced when the platform is operating in production mode.</li>
      <li>An audit log records sensitive data access and modification events including session joins, note writes, file uploads, session exports, and data deletion events. Each log entry includes a tamper-evident HMAC chain hash.</li>
      <li>TLS/HTTPS is enforced at the server level for all transmissions when the platform is in production mode. WebRTC video/audio is encrypted in transit using DTLS-SRTP as enforced by the browser.</li>
    </ul>
  </div>

  <!-- 9. Changes -->
  <div class="priv-section">
    <div class="priv-section-num">Section 09</div>
    <h2>Changes to This Notice</h2>
    <p>This privacy notice may be updated from time to time. The version number and date at the top of this page reflect the current version. Material changes affecting your rights will be communicated to registered users by email or platform notification where possible.</p>
  </div>

  <?php
  // ═══════════════════════════════════════════════
  // JURISDICTION SUPPLEMENTS
  // ═══════════════════════════════════════════════
  $supplements = priv_supplement_blocks($activeJuris, $ctx);
  if (trim($supplements)): ?>
  <div class="priv-juris-divider">
    <span class="priv-juris-divider-label">Jurisdiction-Specific Supplements</span>
  </div>
  <?= $supplements ?>
  <?php endif; ?>

  <div class="priv-footer">
    <p>
      <?= htmlspecialchars($appName) ?> &nbsp;·&nbsp;
      <a href="/">Home</a> &nbsp;·&nbsp;
      <a href="/coverage.php">Coverage</a> &nbsp;·&nbsp;
      <a href="/about.php">About</a> &nbsp;·&nbsp;
      Privacy Notice v<?= htmlspecialchars($privacyVersion) ?> &nbsp;·&nbsp;
      <?= htmlspecialchars($privacyDate) ?>
      <?php if ($ctx['controller_name']): ?>
        &nbsp;·&nbsp; <?= htmlspecialchars($ctx['controller_name']) ?>
      <?php endif; ?>
    </p>
  </div>

</div><!-- /.priv-page -->
</body>
</html>
