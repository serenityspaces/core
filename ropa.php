<?php
/**
 * Record of Processing Activities (ROPA)
 * Jurisdiction-aware compliance record — assembled from active jurisdiction settings.
 * Access: practitioner login required (as admin/operator representative).
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/jurisdiction/jurisdiction_helper.php';
require_once __DIR__ . '/includes/jurisdiction/blocks_ropa.php';
requireSetup();

// Require practitioner authentication
if (empty($_SESSION['practitioner_id'])) {
    header('Location: /login.php');
    exit;
}
require_once __DIR__ . '/includes/session_timeout.php';

$appName    = getSetting('app_name', 'Serenity Spaces');
$appTheme   = getSetting('theme', 'dark');
$siteBgPath = getSetting('site_background_path', '');
$siteBgType = getSetting('site_background_type', '');
$hostUrl = !empty($_SERVER['HTTP_HOST'])
    ? (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
    : rtrim(getSetting('app_url', 'https://your-domain.example'), '/');
$ropaDate    = '9 May 2026';
$ropaVersion = '2.1';

// ── Jurisdiction context ─────────────────────────────────────────
$ctx          = jur_build_ctx($appName, $hostUrl);
$activeJuris  = jur_active_list();
$primaryJuris = jur_primary();

// Badge label
$badgeLabels = [];
foreach ($activeJuris as $jc) {
    $badgeLabels[] = match($jc) {
        'eu'        => 'GDPR Art. 30',
        'uk'        => 'UK GDPR Art. 30',
        'us'        => 'HIPAA',
        'canada'    => 'PIPEDA',
        'australia' => 'Privacy Act 1988',
        'custom'    => 'Custom',
        default     => strtoupper($jc),
    };
}
$badgeText = implode(' · ', $badgeLabels) ?: 'Compliance Record';

// Intro paragraph — jurisdiction-aware
$introParas = [];
if (jur_is_active('eu')) {
    $introParas[] = 'This document serves as the <strong>Article 30(1) GDPR Record of Processing Activities</strong> required under Regulation (EU) 2016/679 and must be made available to the relevant EU supervisory authority on request.';
}
if (jur_is_active('uk')) {
    $introParas[] = 'This document also serves as the <strong>UK GDPR Article 30 Record</strong> under the UK GDPR (retained in UK law by the European Union (Withdrawal) Act 2018) and must be made available to the ICO on request.';
}
if (jur_is_active('us')) {
    $introParas[] = 'For US-hosted data, this record constitutes the <strong>HIPAA PHI Processing Inventory</strong> maintained under 45 CFR § 164 obligations and to support BAA management and breach response readiness.';
}
if (jur_is_active('canada')) {
    $introParas[] = 'For Canadian data subjects, this record is maintained as a <strong>PIPEDA accountability record</strong> under Schedule 1 (Principle 1) and to support Québec Law 25 Privacy Impact Assessment obligations.';
}
if (jur_is_active('australia')) {
    $introParas[] = 'For Australian data subjects, this record is maintained as an <strong>APP 1 personal information handling record</strong> and to support Notifiable Data Breaches (NDB) scheme readiness.';
}
if (empty($introParas)) {
    $introParas[] = 'This document records personal data processing activities for operational accountability and compliance.';
}

// Breach notification deadline table — jurisdiction-aware rows
function ropa_breach_rows(array $activeJuris): string {
    $rows = '';
    if (in_array('eu', $activeJuris, true)) {
        $rows .= '<tr>
          <td><strong>EU / EEA (GDPR)</strong></td>
          <td>Supervisory authority (lead DPA) — Art. 33. Affected individuals where high risk exists — Art. 34.</td>
          <td><strong style="color:#e85555;">72 hours</strong> to supervisory authority. Individuals: without undue delay.</td>
          <td><a href="https://edpb.europa.eu/about-edpb/about-edpb/members_en" target="_blank" rel="noopener" style="color:rgba(196,184,255,0.8);">edpb.europa.eu</a></td>
        </tr>';
    }
    if (in_array('uk', $activeJuris, true)) {
        $rows .= '<tr>
          <td><strong>United Kingdom (UK GDPR)</strong></td>
          <td>ICO — UK GDPR Art. 33. Affected individuals where high risk — Art. 34.</td>
          <td><strong style="color:#e85555;">72 hours</strong> to ICO. Individuals: without undue delay.</td>
          <td><a href="https://ico.org.uk/for-organisations/report-a-breach/" target="_blank" rel="noopener" style="color:rgba(196,184,255,0.8);">ico.org.uk/report-a-breach</a></td>
        </tr>';
    }
    if (in_array('us', $activeJuris, true)) {
        $rows .= '<tr>
          <td><strong>United States (HIPAA)</strong></td>
          <td>HHS OCR — all breaches of unsecured PHI. State AG and affected individuals for large breaches (500+ in a state).</td>
          <td><strong style="color:#e07a30;">60 calendar days</strong> to HHS OCR. Individuals: without unreasonable delay, within 60 days.</td>
          <td><a href="https://www.hhs.gov/hipaa/filing-a-complaint" target="_blank" rel="noopener" style="color:rgba(196,184,255,0.8);">hhs.gov/hipaa/filing-a-complaint</a></td>
        </tr>';
    }
    if (in_array('canada', $activeJuris, true)) {
        $rows .= '<tr>
          <td><strong>Canada (PIPEDA / Québec Law 25)</strong></td>
          <td>OPC — PIPEDA breach of security safeguards. CAI and affected individuals — Québec Law 25 (if risk of serious injury).</td>
          <td>OPC: <strong style="color:#e07a30;">as soon as feasible</strong>. Québec CAI &amp; individuals: <strong style="color:#e85555;">72 hours</strong> of discovery (Law 25).</td>
          <td><a href="https://www.priv.gc.ca/en/report-a-concern/report-a-privacy-breach-as-an-organization/" target="_blank" rel="noopener" style="color:rgba(196,184,255,0.8);">priv.gc.ca/en/report-a-breach</a></td>
        </tr>';
    }
    if (in_array('australia', $activeJuris, true)) {
        $rows .= '<tr>
          <td><strong>Australia (NDB scheme)</strong></td>
          <td>OAIC and affected individuals — eligible data breaches likely to result in serious harm (Privacy Act Part IIIC).</td>
          <td><strong style="color:#e07a30;">30 days</strong> of becoming aware. Individuals: as soon as practicable.</td>
          <td><a href="https://www.oaic.gov.au/privacy/notifiable-data-breaches" target="_blank" rel="noopener" style="color:rgba(196,184,255,0.8);">oaic.gov.au/privacy/notifiable-data-breaches</a></td>
        </tr>';
    }
    return $rows;
}

// Assemble ROPA supplement blocks
function ropa_supplement_blocks(array $activeJuris, array $ctx): string {
    $out = '';
    foreach ($activeJuris as $jc) {
        $out .= match($jc) {
            'eu'        => ropa_block_eu($ctx),
            'uk'        => ropa_block_uk($ctx),
            'us'        => ropa_block_us($ctx),
            'canada'    => ropa_block_canada($ctx),
            'australia' => ropa_block_australia($ctx),
            'custom'    => ropa_block_custom($ctx),
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
  <title>ROPA — <?= htmlspecialchars($appName) ?></title>
  <link rel="stylesheet" href="/assets/css/fonts.css">
  <link rel="stylesheet" href="/assets/css/main.css">
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
    :root { --sky-void:#050810; }
    *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
    html, body { overflow-y:auto; height:auto; }
    body { font-family:'Outfit', sans-serif; background:var(--sky-void); color:#dddcf2; min-height:100vh; }
    .ropa-page { max-width:1000px; margin:0 auto; padding:48px 24px 80px; }
    .ropa-topbar {
      display:flex; align-items:center; justify-content:space-between;
      margin-bottom:40px; padding-bottom:20px;
      border-bottom:1px solid rgba(255,255,255,0.06);
    }
    .ropa-topbar a { color:rgba(200,210,255,0.45); font-size:13px; text-decoration:none; }
    .ropa-topbar a:hover { color:rgba(200,210,255,0.8); }
    .ropa-badge {
      font-size:10px; font-weight:700; letter-spacing:0.1em; text-transform:uppercase;
      padding:4px 10px; border-radius:20px;
      background:rgba(245,200,66,0.1); color:rgba(245,200,66,0.8);
      border:1px solid rgba(245,200,66,0.2);
    }
    h1 { font-size:28px; font-weight:800; color:#e8eeff; letter-spacing:-0.6px; margin-bottom:6px; }
    .ropa-meta { font-size:13px; color:rgba(200,210,255,0.35); margin-bottom:8px; }
    .ropa-intro {
      font-size:14px; color:rgba(200,210,255,0.55); line-height:1.7;
      margin-bottom:40px; padding:16px 20px;
      background:rgba(245,200,66,0.04); border:1px solid rgba(245,200,66,0.12);
      border-radius:10px;
    }
    .ropa-section { margin-bottom:48px; }
    .ropa-section-label {
      font-size:10px; font-weight:700; letter-spacing:0.1em; text-transform:uppercase;
      color:rgba(124,106,247,0.6); margin-bottom:6px;
    }
    .ropa-juris-divider {
      margin:48px 0 32px; display:flex; align-items:center; gap:16px;
    }
    .ropa-juris-divider::before, .ropa-juris-divider::after {
      content:''; flex:1; height:1px; background:rgba(124,106,247,0.15);
    }
    .ropa-juris-divider-label {
      font-size:10px; font-weight:700; letter-spacing:0.1em; text-transform:uppercase;
      color:rgba(124,106,247,0.45); white-space:nowrap;
    }
    h2 { font-size:17px; font-weight:700; color:#e8eeff; margin-bottom:16px; }
    h3 { font-size:15px; font-weight:700; color:#e8eeff; margin:20px 0 10px; }
    p { font-size:13px; color:rgba(200,210,255,0.6); line-height:1.7; margin-bottom:10px; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    thead th {
      text-align:left; padding:10px 12px;
      font-size:10px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase;
      color:rgba(124,106,247,0.65); background:rgba(124,106,247,0.04);
      border-bottom:1px solid rgba(255,255,255,0.08);
    }
    tbody td {
      padding:11px 12px; vertical-align:top;
      color:rgba(200,210,255,0.65); line-height:1.6;
      border-bottom:1px solid rgba(255,255,255,0.04);
    }
    tbody tr:last-child td { border-bottom:none; }
    tbody tr:hover td { background:rgba(255,255,255,0.015); }
    .basis-tag {
      display:inline-block; font-size:11px; font-weight:600; padding:2px 8px;
      border-radius:4px; white-space:nowrap;
    }
    .basis-6b  { background:rgba(74,222,128,0.1);  color:#4ade80;  border:1px solid rgba(74,222,128,0.2); }
    .basis-6c  { background:rgba(74,185,222,0.1);  color:#4ab9de;  border:1px solid rgba(74,185,222,0.2); }
    .basis-6f  { background:rgba(245,200,66,0.1);  color:#f5c842;  border:1px solid rgba(245,200,66,0.2); }
    .basis-9a  { background:rgba(232,85,85,0.1);   color:#e85555;  border:1px solid rgba(232,85,85,0.2); }
    .risk-high   { color:#e85555; font-weight:600; }
    .risk-medium { color:#f5c842; font-weight:600; }
    .risk-low    { color:#4ade80; font-weight:600; }
    .notice-box {
      padding:14px 18px; margin-bottom:32px;
      background:rgba(232,85,85,0.06); border:1px solid rgba(232,85,85,0.18);
      border-radius:8px; font-size:13px; color:rgba(255,180,180,0.75); line-height:1.6;
    }
    .ropa-footer {
      margin-top:48px; padding-top:20px; border-top:1px solid rgba(255,255,255,0.05);
      font-size:12px; color:rgba(200,210,255,0.25); text-align:center;
    }
    .ropa-footer a { color:rgba(200,210,255,0.4); text-decoration:none; }
    @media print {
      .ropa-topbar a { display:none; }
      body { background:white; color:#111; }
      .ropa-badge { border:1px solid #999; color:#555; background:none; }
      .ropa-juris-divider::before, .ropa-juris-divider::after { background:#ccc; }
    }
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

<div class="ropa-page">

  <div class="ropa-topbar">
    <a href="/dashboard.php">← Dashboard</a>
    <span class="ropa-badge">Internal — <?= htmlspecialchars($badgeText) ?></span>
  </div>

  <h1>Record of Processing Activities</h1>
  <div class="ropa-meta">
    <?= htmlspecialchars($appName) ?> &nbsp;·&nbsp;
    Version <?= htmlspecialchars($ropaVersion) ?> &nbsp;·&nbsp;
    <?= htmlspecialchars($ropaDate) ?> &nbsp;·&nbsp;
    Controller: <?= $ctx['controller_name'] ? htmlspecialchars($ctx['controller_name']) : 'operator of ' . htmlspecialchars($hostUrl) ?>
    <?php if ($ctx['dpo_name']): ?>
      &nbsp;·&nbsp; DPO: <?= htmlspecialchars($ctx['dpo_name']) ?>
    <?php endif; ?>
  </div>

  <div class="ropa-intro">
    <?= implode('<br><br>', $introParas) ?>
    <br><br>This platform supports regional datacenters — US (Virginia, HIPAA-compliant) and EU (France, GDPR-compliant, planned). The applicable regulatory framework for each processing activity depends on the datacenter region. This is an internal document for use by the data controller and their Data Protection Officer where applicable.
  </div>

  <div class="notice-box">
    <strong>Operator responsibility:</strong> This ROPA reflects the processing activities built into the <?= htmlspecialchars($appName) ?> software. The operator must review and supplement it to reflect their specific legal context, retention decisions, and any additional processing not described below. Controller details are configured in Admin → Compliance.
  </div>

  <?php
  // ═══════════════════════════════════════════════
  // JURISDICTION-SPECIFIC FORMAL RECORDS
  // ═══════════════════════════════════════════════
  $supplements = ropa_supplement_blocks($activeJuris, $ctx);
  if (trim($supplements)): ?>
  <div class="ropa-juris-divider">
    <span class="ropa-juris-divider-label">Jurisdiction-Specific Formal Records</span>
  </div>
  <?= $supplements ?>
  <div class="ropa-juris-divider" style="margin-top:48px;">
    <span class="ropa-juris-divider-label">Platform Processing Detail</span>
  </div>
  <?php endif; ?>


  <!-- ══════════════════════════════════════════════════════════════════
       PROCESSING ACTIVITIES — platform-specific detail table
       ══════════════════════════════════════════════════════════════════ -->
  <div class="ropa-section">
    <div class="ropa-section-label">HIPAA 45 CFR § 164 · GDPR Art. 30(1) · PIPEDA Schedule 1 · APPs</div>
    <h2>Platform Processing Activities — Detailed Record</h2>
    <table>
      <thead>
        <tr>
          <th style="width:18%">Activity</th>
          <th style="width:14%">Purpose</th>
          <th style="width:16%">Data categories</th>
          <th style="width:10%">Data subjects</th>
          <th style="width:13%">Lawful basis</th>
          <th style="width:11%">Retention</th>
          <th style="width:9%">Special cat.</th>
          <th style="width:9%">Third parties</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>Practitioner account registration</strong></td>
          <td>Account creation and identity verification</td>
          <td>Email, name, password hash, avatar, profile information</td>
          <td>Practitioners</td>
          <td><span class="basis-tag basis-6b">Art. 6(1)(b)</span><br>Contractual</td>
          <td>Duration of account + 30 days post-closure</td>
          <td>No</td>
          <td>Email provider (SMTP)</td>
        </tr>
        <tr>
          <td><strong>Client account registration</strong></td>
          <td>Optional client account for portal access</td>
          <td>Email, name, password hash, avatar</td>
          <td>Registered clients</td>
          <td><span class="basis-tag basis-6b">Art. 6(1)(b)</span><br>Contractual</td>
          <td>Duration of account + 30 days post-closure</td>
          <td>No</td>
          <td>Email provider (SMTP)</td>
        </tr>
        <tr>
          <td><strong>Session booking</strong></td>
          <td>Scheduling and administering therapeutic sessions</td>
          <td>Name, email, avatar, date, time, practitioner reference; optionally: DOB, gender, sexuality, location</td>
          <td>Clients (registered and guest)</td>
          <td><span class="basis-tag basis-6b">Art. 6(1)(b)</span><br>Contractual<br><br>Special fields:<br><span class="basis-tag basis-9a">Art. 9(2)(a)</span><br>Explicit consent</td>
          <td>Duration of therapeutic relationship + applicable health record retention (HIPAA minimum: 6 years; ACA/NASW professional standard: 7 years)</td>
          <td>Yes — gender, sexuality, DOB (HIPAA sensitive PHI / GDPR Art. 9)</td>
          <td>Email provider (SMTP); payment processor (Stripe/PayPal) if applicable</td>
        </tr>
        <tr>
          <td><strong>Session delivery (chat, notes, files)</strong></td>
          <td>Delivering real-time therapeutic sessions</td>
          <td>Chat messages, clinical notes, highlights, file attachments, voice notes (all encrypted at rest)</td>
          <td>Clients, practitioners</td>
          <td><span class="basis-tag basis-6b">Art. 6(1)(b)</span><br>Contractual</td>
          <td>7 years from last session (HIPAA minimum: 6 years; ACA/NASW professional standard: 7 years). Deleted upon verified erasure request, subject to practitioner retention obligations.</td>
          <td>Potentially — session content may contain special category data</td>
          <td>Hosting provider (infrastructure)</td>
        </tr>
        <tr>
          <td><strong>Intake form delivery and submission</strong></td>
          <td>Collecting pre-session clinical or personal history from clients</td>
          <td>Form fields (text, select, radio, checkbox) defined by practitioner; responses may include health history, goals, or other sensitive information provided by client</td>
          <td>Clients completing intake forms</td>
          <td><span class="basis-tag basis-6b">Art. 6(1)(b)</span><br>Contractual<br><br>May require:<br><span class="basis-tag basis-9a">Art. 9(2)(a)</span><br>Explicit consent if health data collected</td>
          <td>Duration of therapeutic relationship + applicable health record retention (HIPAA minimum: 6 years; ACA/NASW professional standard: 7 years)</td>
          <td>Potentially — practitioner-defined fields may capture health or other special category data</td>
          <td>Email provider (SMTP) — delivery of secure intake link</td>
        </tr>
        <tr>
          <td><strong>WebRTC audio/video</strong></td>
          <td>Peer-to-peer video/audio during sessions</td>
          <td>Audio and video streams (not recorded or stored by platform)</td>
          <td>Session participants</td>
          <td><span class="basis-tag basis-6b">Art. 6(1)(b)</span><br>Contractual</td>
          <td>Not stored — ephemeral transmission only</td>
          <td>Potentially — live video may capture special category data</td>
          <td>None (peer-to-peer via DTLS-SRTP)</td>
        </tr>
        <tr>
          <td><strong>Payment processing</strong></td>
          <td>Processing fees for practitioner sessions</td>
          <td>Amount, currency, payment method type, gateway reference</td>
          <td>Clients paying for sessions</td>
          <td><span class="basis-tag basis-6b">Art. 6(1)(b)</span><br>Contractual</td>
          <td>7 years (financial records, IRS/applicable tax authority obligations)</td>
          <td>No</td>
          <td>Stripe / PayPal / Square (as processors under their own terms, subject to HIPAA BAA / DPA upload in practitioner profile)</td>
        </tr>
        <tr>
          <td><strong>Client portal messaging</strong></td>
          <td>Secure communication between clients and practitioners between sessions</td>
          <td>Message content, timestamps, sender/recipient identifiers</td>
          <td>Registered clients, practitioners</td>
          <td><span class="basis-tag basis-6b">Art. 6(1)(b)</span><br>Contractual</td>
          <td>Duration of therapeutic relationship</td>
          <td>Potentially — messages may contain sensitive content</td>
          <td>None</td>
        </tr>
        <tr>
          <td><strong>Audit logging</strong></td>
          <td>Security, fraud prevention, compliance</td>
          <td>User ID, action type, IP address, timestamp, affected resource</td>
          <td>All authenticated users</td>
          <td><span class="basis-tag basis-6f">Art. 6(1)(f)</span><br>Legitimate interests</td>
          <td>Minimum 12 months</td>
          <td>No</td>
          <td>None</td>
        </tr>
        <tr>
          <td><strong>Consent records</strong></td>
          <td>Audit trail for explicit Art. 9(2)(a) / HIPAA Authorization consent</td>
          <td>Booking reference, user ID, consent text snapshot, IP address, user agent, timestamp</td>
          <td>Clients who provide special category data</td>
          <td><span class="basis-tag basis-6c">Art. 6(1)(c)</span><br>Legal obligation (accountability)</td>
          <td>Duration of associated booking/account + 3 years</td>
          <td>No (records reference consent to process special data)</td>
          <td>None</td>
        </tr>
        <tr>
          <td><strong>Data deletion / rights requests</strong></td>
          <td>Fulfilling erasure requests (GDPR Art. 17 / HIPAA right of access and amendment)</td>
          <td>Records of deletion event, scope of data deleted, date processed</td>
          <td>All data subjects</td>
          <td><span class="basis-tag basis-6c">Art. 6(1)(c)</span><br>Legal obligation</td>
          <td>3 years (accountability record)</td>
          <td>No</td>
          <td>None</td>
        </tr>
        <tr>
          <td><strong>Session recap summaries</strong></td>
          <td>Structured post-session clinical summary for therapeutic continuity</td>
          <td>Key summary, key insights, agreed actions, next focus (session_recap table)</td>
          <td>Clients</td>
          <td><span class="basis-tag basis-9a">Art. 9(2)(a)</span><br>Explicit consent<br><br>or<br><br><span class="basis-tag basis-6b">Art. 9(2)(h)</span><br>Healthcare treatment</td>
          <td>Same as session — intended for deletion post-export. No automated purge yet implemented.</td>
          <td>Yes — clinical summaries may describe mental health status and agreed treatment actions</td>
          <td>Hosting provider (infrastructure)</td>
        </tr>
        <tr>
          <td><strong>Session journey arc notes</strong></td>
          <td>Longitudinal practitioner notes spanning multiple sessions with the same client (session_series table)</td>
          <td>Arc notes, series name, linked session references</td>
          <td>Clients</td>
          <td><span class="basis-tag basis-9a">Art. 9(2)(a)</span><br>Explicit consent<br><br>or<br><br><span class="basis-tag basis-6b">Art. 9(2)(h)</span><br>Healthcare treatment</td>
          <td>Until no associated sessions remain, or on client account deletion</td>
          <td>Potentially — arc notes may capture long-term mental health patterns</td>
          <td>Hosting provider (infrastructure)</td>
        </tr>
        <tr>
          <td><strong>Practitioner-to-practitioner referral</strong></td>
          <td>Sharing clinical context with another platform practitioner to facilitate an onward referral for a different type of support</td>
          <td>Referral notes (encrypted), accepted clinical snapshot — may include goals, intake summary, session themes, or session notes depending on the permission flags set by the referring practitioner</td>
          <td>Clients subject to a referral</td>
          <td><span class="basis-tag basis-9a">Art. 9(2)(h)</span><br>Healthcare / professional referral treatment purpose<br><br>US: HIPAA Treatment disclosure (45 CFR § 164.506(c)(1))</td>
          <td>Duration of referral relationship. Referral record: 7 years from last session involving either practitioner. Accepted snapshot deleted on client erasure request.</td>
          <td>Yes — clinical snapshot may contain mental health history, goals, and session notes</td>
          <td>Receiving practitioner (platform user — internal disclosure; no data leaves the platform)</td>
        </tr>
        <tr>
          <td><strong>Practitioner credential documents</strong></td>
          <td>Verification of professional registration and licence for licensed practice categories</td>
          <td>Uploaded PDF/image documents (license_submissions); may contain practitioner DOB, photo ID, registration numbers, home address</td>
          <td>Practitioners</td>
          <td><span class="basis-tag basis-6f">Art. 6(1)(f)</span><br>Legitimate interests (credential verification)<br><br>or<br><br><span class="basis-tag basis-6c">Art. 6(1)(c)</span><br>Legal obligation where regulated practice applies</td>
          <td>Approved: account duration + 2 years. Rejected: 30 days from decision. Pending: review within 30 days.</td>
          <td>Potentially — documents may contain DOB or other personal identifiers</td>
          <td>Hosting provider (infrastructure)</td>
        </tr>
        <tr>
          <td><strong>Security: login rate limiting</strong></td>
          <td>Brute-force and credential-stuffing protection</td>
          <td>IP address, failed attempt count, timestamp</td>
          <td>All users attempting login</td>
          <td><span class="basis-tag basis-6f">Art. 6(1)(f)</span><br>Legitimate interests</td>
          <td>Up to 90 days</td>
          <td>No</td>
          <td>None</td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- Third parties -->
  <div class="ropa-section">
    <div class="ropa-section-label">HIPAA 45 CFR § 164.502 · GDPR Art. 30(1)(d) · PIPEDA Principle 1 · APP 8</div>
    <h2>Third-Party Recipients and Transfers</h2>
    <table>
      <thead>
        <tr>
          <th>Recipient</th>
          <th>Role</th>
          <th>Data shared</th>
          <th>Transfer basis</th>
          <th>Location</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>OVH Public Cloud (Virginia, USA)</strong></td>
          <td>Infrastructure / processor — US datacenter</td>
          <td>All data stored on the server (encrypted PHI, database, logs)</td>
          <td>HIPAA BAA in place; SCCs / IDTA for EU/UK data subject transfers</td>
          <td>United States (Virginia)</td>
        </tr>
        <tr>
          <td><strong>OVH Public Cloud (France)</strong></td>
          <td>Infrastructure / processor — EU datacenter <em>(planned)</em></td>
          <td>All data stored on the server (encrypted PHI, database, logs)</td>
          <td>GDPR Art. 28 Data Processing Agreement (DPA) required before activation. No transfer (EU infrastructure).</td>
          <td>European Union (France)</td>
        </tr>
        <tr>
          <td><strong>SMTP provider (configured by operator)</strong></td>
          <td>Processor — email delivery</td>
          <td>Client name, email address, booking reference</td>
          <td>HIPAA BAA required; Art. 28 DPA / IDTA for GDPR/UK GDPR; contractual safeguards for PIPEDA/APPs</td>
          <td>Varies by SMTP configuration</td>
        </tr>
        <tr>
          <td><strong>PayPal</strong></td>
          <td>Processor — payment</td>
          <td>PayPal account reference; payment status returned to platform</td>
          <td>HIPAA BAA + Art. 28 DPA — practitioner must upload via profile settings before enabling</td>
          <td>US / EEA</td>
        </tr>
        <tr>
          <td><strong>Stripe</strong></td>
          <td>Processor — payment</td>
          <td>Payment card data (processed directly by Stripe; platform only receives status reference)</td>
          <td>HIPAA BAA + Art. 28 DPA — practitioner must upload via profile settings before enabling</td>
          <td>US / EEA</td>
        </tr>
        <tr>
          <td><strong>Square</strong></td>
          <td>Processor — payment</td>
          <td>Payment reference; payment status returned to platform</td>
          <td>HIPAA BAA + Art. 28 DPA — practitioner must upload via profile settings before enabling</td>
          <td>US / EEA</td>
        </tr>
        <tr>
          <td><strong>AI vendor (OpenAI / Anthropic / Google / Cohere)</strong></td>
          <td>Conditional processor — AI session assistance</td>
          <td>Session messages or context submitted to AI API if AI assistance is enabled by practitioner. No PHI is transmitted unless practitioner explicitly enables and configures an AI vendor.</td>
          <td>HIPAA BAA required; Art. 28 DPA for GDPR/UK GDPR compliance — practitioner must upload via profile settings before enabling AI features.</td>
          <td>US (primarily)</td>
        </tr>
        <tr>
          <td><strong>BTCPay Server (configured by operator/practitioner)</strong></td>
          <td>Conditional processor — Bitcoin/Lightning payment invoice creation</td>
          <td>Payment amount, currency, booking reference. No PHI transmitted. Card data not applicable.</td>
          <td>Typically operator-self-hosted — operator is responsible for the privacy and security of their BTCPay instance. If using a third-party hosted BTCPay, a BAA/DPA may be required depending on jurisdiction.</td>
          <td>Operator-configured (typically operator-hosted)</td>
        </tr>
        <tr>
          <td><strong>Receiving practitioner (referral)</strong></td>
          <td>Internal recipient — practitioner-to-practitioner referral disclosure</td>
          <td>Clinical context snapshot: may include client goals, intake summary, session themes, or session notes, within the scope of permission flags set by the referring practitioner. Referral notes are encrypted at rest and decrypted only on authorised access.</td>
          <td>HIPAA Treatment disclosure — 45 CFR § 164.506(c)(1) permits treatment disclosures between covered entities without patient authorization. GDPR: Art. 9(2)(h) — healthcare treatment purpose. No data leaves the platform; both practitioners are registered platform users.</td>
          <td>Internal to the platform instance</td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- Technical safeguards summary -->
  <div class="ropa-section">
    <div class="ropa-section-label">HIPAA 45 CFR § 164.308–316 · GDPR Art. 30(1)(g) · PIPEDA Principle 7 · APP 11</div>
    <h2>Technical and Organisational Measures (Summary)</h2>
    <table>
      <thead>
        <tr><th>Measure</th><th>Detail</th><th>Risk addressed</th><th>Status</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>Encryption at rest (PHI)</td>
          <td>AES-256-GCM (enc3: context-authenticated format) for all PHI fields: session messages, practitioner clinical notes, session recap summaries, client reflection notes, booking PII fields (name, email, gender, sexuality, DOB, location), and intake form responses. Context binding ties each ciphertext to its record — cross-record substitution is cryptographically detectable.</td>
          <td>Data breach via database access</td>
          <td class="risk-low">Implemented — all PHI fields encrypted at rest</td>
        </tr>
        <tr>
          <td>Encryption in transit</td>
          <td>TLS/HTTPS enforced via HSTS and HTTP security headers when Production Mode is enabled; DTLS-SRTP for WebRTC media</td>
          <td>Interception of data in transit</td>
          <td class="risk-medium">Partial — requires Production Mode enabled</td>
        </tr>
        <tr>
          <td>Access controls</td>
          <td>Role-based: practitioners access only their own sessions/clients; clients access only their own data</td>
          <td>Unauthorised access to PHI</td>
          <td class="risk-low">Implemented</td>
        </tr>
        <tr>
          <td>Authentication — password strength</td>
          <td>bcrypt hashing; minimum length enforced; strength indicator shown to users</td>
          <td>Credential compromise</td>
          <td class="risk-low">Implemented</td>
        </tr>
        <tr>
          <td>Authentication — MFA</td>
          <td>TOTP-based MFA available for both practitioners and registered clients (Google Authenticator compatible). Not mandatory — operators should consider enforcing for practitioner accounts.</td>
          <td>Account takeover</td>
          <td class="risk-medium">Partial — implemented, optional</td>
        </tr>
        <tr>
          <td>Session timeout</td>
          <td>includes/session_timeout.php — 15 minutes for practitioners (PHI access), 30 minutes for clients. Active on dashboard, admin panel, profile, and client portal pages.</td>
          <td>Unattended session exposure</td>
          <td class="risk-medium">Partial — portal pages only</td>
        </tr>
        <tr>
          <td>CSRF protection</td>
          <td>Token-based CSRF protection on all state-changing actions</td>
          <td>Cross-site request forgery</td>
          <td class="risk-low">Implemented</td>
        </tr>
        <tr>
          <td>Brute-force protection</td>
          <td>IP-based rate limiting and blocking via includes/firewall.php</td>
          <td>Credential stuffing</td>
          <td class="risk-low">Implemented</td>
        </tr>
        <tr>
          <td>Audit logging</td>
          <td>audit_log table records all PHI events: auth.login, auth.logout, auth.login_fail, session.poll_start, notes.write, file.upload, session.export, data deletion events. HMAC-SHA256 chain hash per row. IP addresses nulled after 12 months.</td>
          <td>Undetected data access or breach</td>
          <td class="risk-low">Implemented</td>
        </tr>
        <tr>
          <td>Data minimisation / retention</td>
          <td>Session records retained for 7 years from last session (HIPAA minimum: 6 years; ACA/NASW professional standard: 7 years). File attachments removed from disk post-export. Voice notes covered by 7-year policy; swept on verified erasure request.</td>
          <td>PHI accumulation beyond operational need</td>
          <td class="risk-low">Documented — 7-year professional retention policy</td>
        </tr>
        <tr>
          <td>File upload security</td>
          <td>MIME-type allowlisting on all upload endpoints; file extension derived from validated MIME type (not user-supplied filename) across all upload paths</td>
          <td>Malicious file upload / RCE</td>
          <td class="risk-low">Implemented</td>
        </tr>
        <tr>
          <td>Explicit consent for sensitive data</td>
          <td>Consent checkbox at booking, consent_records audit table, snapshot of consent wording stored</td>
          <td>Non-compliant processing of sensitive PHI / GDPR Art. 9 data</td>
          <td class="risk-low">Implemented</td>
        </tr>
        <tr>
          <td>Data subject rights</td>
          <td>Erasure request available in client portal; practitioner can delete client data; deletion audit log</td>
          <td>Failure to fulfil rights requests</td>
          <td class="risk-low">Implemented</td>
        </tr>
        <tr>
          <td>Authenticated file serving</td>
          <td>Session file attachments and voice notes served through api/serve_file.php, which validates practitioner or participant access before streaming. Direct URL access to /assets/uploads/ is denied.</td>
          <td>Unauthorised access to session files via direct URL enumeration</td>
          <td class="risk-low">Implemented</td>
        </tr>
        <tr>
          <td>Risk Analysis / DPIA</td>
          <td>Risk analysis document (HIPAA Risk Analysis requirement, 45 CFR § 164.308(a)(1)) and DPIA covering high-risk processing activities: session transcripts, clinical notes, special category booking data, AI processing, file uploads. Complies with EU GDPR Art. 35 for EU datacenter deployments.</td>
          <td>Unassessed high-risk processing</td>
          <td class="risk-medium">Completed — awaiting operator review and sign-off</td>
        </tr>
        <tr>
          <td>Business Associate Agreements / DPAs</td>
          <td>Platform enforces HIPAA BAA (and GDPR Art. 28 DPA where applicable) upload as a precondition for enabling third-party processors (Stripe, PayPal, Square, AI vendors). Agreements uploaded and managed via practitioner profile settings and admin panel.</td>
          <td>Using processors without a HIPAA BAA or Art. 28 DPA in place</td>
          <td class="risk-medium">Mechanism built — agreements pending upload by operator</td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- Breach Notification Procedure -->
  <div class="ropa-section">
    <div class="ropa-section-label">
      <?php
      $breachCites = [];
      if (jur_is_active('eu') || jur_is_active('uk')) $breachCites[] = 'GDPR Arts. 33 &amp; 34';
      if (jur_is_active('us'))        $breachCites[] = 'HIPAA Breach Notification Rule';
      if (jur_is_active('canada'))    $breachCites[] = 'PIPEDA / Québec Law 25';
      if (jur_is_active('australia')) $breachCites[] = 'Privacy Act NDB Scheme';
      echo implode(' · ', $breachCites) ?: 'Breach Notification';
      ?>
    </div>
    <h2>Personal Data Breach — Response Procedure</h2>

    <?php if (count($activeJuris) > 0): ?>
    <h3>Notification Deadlines by Jurisdiction</h3>
    <table style="margin-bottom:28px;">
      <thead>
        <tr>
          <th>Jurisdiction</th>
          <th>Who to notify</th>
          <th>Deadline</th>
          <th>Authority / Link</th>
        </tr>
      </thead>
      <tbody>
        <?= ropa_breach_rows($activeJuris) ?>
      </tbody>
    </table>
    <?php endif; ?>

    <h3>Response Steps</h3>
    <table>
      <thead>
        <tr>
          <th style="width:6%">Step</th>
          <th style="width:20%">Action</th>
          <th>Detail</th>
          <th style="width:18%">Deadline</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="font-weight:600;color:#f5c842;">1</td>
          <td><strong>Identify &amp; Contain</strong></td>
          <td>On becoming aware of a potential breach: isolate the affected system or account if safe to do so (e.g. disable compromised login, revoke access token, take affected endpoint offline). Do not delete logs or evidence. Record the date and time of discovery.</td>
          <td>Immediately on discovery</td>
        </tr>
        <tr>
          <td style="font-weight:600;color:#f5c842;">2</td>
          <td><strong>Assess Scope &amp; Risk</strong></td>
          <td>Determine: what data was affected (PHI category, volume, identifiability); who is affected (clients, practitioners, or both); how it happened (unauthorised access, accidental disclosure, ransomware, misconfiguration); whether the data is encrypted at rest (AES-256-GCM — reduces risk). Document all findings in writing.</td>
          <td>Within 24 hours of discovery</td>
        </tr>
        <tr>
          <td style="font-weight:600;color:#f5c842;">3</td>
          <td><strong>Notify Regulators</strong></td>
          <td>
            Notify the relevant authority for each active jurisdiction per the deadlines table above. If the full facts are not yet known at the time of the earliest deadline, submit an initial notification and follow up as information becomes available. Provide: nature of breach, categories and approximate number of individuals and records affected, name and contact of responsible party, likely consequences, measures taken or proposed.
          </td>
          <td>Per jurisdiction deadlines above</td>
        </tr>
        <tr>
          <td style="font-weight:600;color:#f5c842;">4</td>
          <td><strong>Notify Affected Individuals</strong></td>
          <td>
            If the breach is likely to result in a <em>high risk</em> to individuals — e.g. exposure of unencrypted therapeutic records, credentials, or special-category data — notify affected clients and practitioners directly without undue delay. Notification must: describe the nature of the breach in plain language; give the name and contact of the data protection contact; describe the likely consequences; describe the measures taken and steps individuals can take to protect themselves. Use the platform's email system or direct contact if email is compromised.
          </td>
          <td>Without undue delay where high risk</td>
        </tr>
        <tr>
          <td style="font-weight:600;color:#f5c842;">5</td>
          <td><strong>Document the Breach</strong></td>
          <td>
            Regardless of whether regulatory notification is required, document all breaches internally: date/time discovered, date/time of actual breach (if known), nature of breach, data types and volume affected, individuals affected, actions taken, outcome, and any notifications sent. Retain this record alongside this ROPA. HHS OCR, the relevant EU/UK supervisory authority, OPC, or OAIC may request it during any subsequent investigation. Retention: minimum 3 years (6 years for HIPAA documentation).
          </td>
          <td>Immediately; retain 3–6 years</td>
        </tr>
        <tr>
          <td style="font-weight:600;color:#f5c842;">6</td>
          <td><strong>Remediate &amp; Review</strong></td>
          <td>After containment: patch the vulnerability, rotate any compromised credentials, review access logs via the audit_log table, assess whether additional security measures are required. Update this ROPA and the privacy notice if the breach reveals a gap in documented processing.</td>
          <td>Within 30 days of resolution</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="ropa-footer">
    <?= htmlspecialchars($appName) ?> &nbsp;·&nbsp;
    ROPA v<?= htmlspecialchars($ropaVersion) ?> &nbsp;·&nbsp;
    <?= htmlspecialchars($ropaDate) ?>
    <?php if ($ctx['controller_name']): ?>
      &nbsp;·&nbsp; <?= htmlspecialchars($ctx['controller_name']) ?>
    <?php endif; ?>
    &nbsp;·&nbsp; <a href="/privacy.php">Privacy Notice</a>
    &nbsp;·&nbsp; <a href="/dpia.php">DPIA</a>
    &nbsp;·&nbsp; <a href="/dashboard.php">Dashboard</a>
    &nbsp;·&nbsp; Internal document — not for public distribution.
  </div>

</div><!-- /.ropa-page -->
</body>
</html>
