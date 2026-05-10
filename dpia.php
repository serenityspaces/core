<?php
/**
 * Data Protection Impact Assessment — Serenity Spaces
 * UK GDPR Art. 35 / EU GDPR Art. 35 / DPA 2018 S.64
 *
 * Admin-only. Renders the living DPIA document with sign-off workflow.
 * Review history stored in dpia_reviews table.
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
require_once __DIR__ . '/includes/session_timeout.php';
require_once __DIR__ . '/includes/jurisdiction/jurisdiction_helper.php';
requireSetup();

if (empty($_SESSION['practitioner_id'])) { header('Location: /login.php'); exit; }

$pdo     = getDB();
$practId = (int)$_SESSION['practitioner_id'];
$me      = $pdo->prepare('SELECT * FROM practitioners WHERE id = ? LIMIT 1');
$me->execute([$practId]);
$me      = $me->fetch();
if (empty($me['is_admin'])) { header('Location: /dashboard.php'); exit; }

$appName  = getSetting('app_name', 'Serenity Spaces');
$hostUrl  = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$ctx      = jur_build_ctx($appName, $hostUrl);

// ── CSRF ─────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Review history ───────────────────────────────────────────────────
$reviews   = [];
$lastReview = null;
$nextReview = null;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dpia_reviews (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reviewed_by  VARCHAR(255) NOT NULL,
        role         VARCHAR(255) DEFAULT NULL,
        dpia_version VARCHAR(20)  NOT NULL DEFAULT '1.0',
        reviewed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notes        TEXT         DEFAULT NULL,
        INDEX idx_dpia_reviewed (reviewed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->query('SELECT * FROM dpia_reviews ORDER BY reviewed_at DESC LIMIT 50');
    $reviews = $stmt->fetchAll();
    if ($reviews) {
        $lastReview = $reviews[0]['reviewed_at'];
        $nextReview = date('Y-m-d', strtotime($lastReview . ' +1 year'));
    }
} catch (Throwable $e) { error_log('dpia: could not load review history: ' . $e->getMessage()); }

// ── Sign-off POST handler ─────────────────────────────────────────────
$success = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } elseif (($_POST['action'] ?? '') === 'dpia_signoff') {
        $byName  = trim($_POST['signoff_name']    ?? '');
        $byRole  = trim($_POST['signoff_role']    ?? '');
        $version = trim($_POST['signoff_version'] ?? '1.0');
        $notes   = trim($_POST['signoff_notes']   ?? '');
        if ($byName === '') {
            $error = 'Reviewer name is required.';
        } else {
            try {
                $ins = $pdo->prepare(
                    'INSERT INTO dpia_reviews (reviewed_by, role, dpia_version, notes) VALUES (?,?,?,?)'
                );
                $ins->execute([$byName, $byRole ?: null, $version ?: '1.0', $notes ?: null]);
                $success = 'Sign-off recorded. DPIA review logged at ' . date('Y-m-d H:i') . ' UTC.';
                // Reload
                $stmt    = $pdo->query('SELECT * FROM dpia_reviews ORDER BY reviewed_at DESC LIMIT 50');
                $reviews = $stmt->fetchAll();
                $lastReview = $reviews[0]['reviewed_at'];
                $nextReview = date('Y-m-d', strtotime($lastReview . ' +1 year'));
            } catch (Throwable $e) {
                $error = 'Could not save sign-off: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

$isOverdue = $nextReview && $nextReview < date('Y-m-d');
$duesSoon  = $nextReview && !$isOverdue && $nextReview <= date('Y-m-d', strtotime('+60 days'));
$nonce     = $GLOBALS['csp_nonce'] ?? '';

$controllerDisplay = $ctx['controller_display'];
$controllerAddr    = $ctx['controller_address']  ?: 'Not configured — set in Admin → Compliance';
$controllerEmail   = $ctx['controller_email']    ?: 'Not configured';
$dpoName           = $ctx['dpo_name']            ?: 'Not designated';
$dpoEmail          = $ctx['dpo_email']           ?: '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DPIA — <?= htmlspecialchars($appName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style nonce="<?= $nonce ?>">
:root {
  --bg: #050810; --bg2: #0d1433; --surface: #111827; --surface2: #1a2238;
  --border: #1f2d4a; --accent: #7c6af7; --amber: #f5c842; --critical: #e85555;
  --high: #e07a30; --medium: #d4b429; --low: #4a9eff; --info: #64b5f6;
  --text: #d8e0f0; --muted: #6b7a9a; --green: #4ade80; --red: #f87171;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 15px; }
body { background: linear-gradient(160deg, var(--bg) 0%, var(--bg2) 100%); color: var(--text); font-family: 'DM Sans', sans-serif; min-height: 100vh; display: flex; }
#sidebar { width: 220px; min-height: 100vh; background: rgba(17,24,39,0.97); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; z-index: 10; overflow-y: auto; }
.sb-brand { padding: 20px 18px 14px; border-bottom: 1px solid var(--border); }
.sb-brand .logo { font-family: 'Space Mono', monospace; font-size: 11px; color: var(--accent); text-transform: uppercase; letter-spacing: 2px; }
.sb-brand .doc-type { font-size: 10px; color: var(--muted); margin-top: 2px; font-family: 'Space Mono', monospace; }
.sb-back { display: flex; align-items: center; gap: 7px; padding: 8px 18px; font-size: 12px; color: var(--muted); text-decoration: none; border-bottom: 1px solid var(--border); transition: color .15s; }
.sb-back:hover { color: var(--text); }
.sb-back svg { width: 12px; height: 12px; }
.sb-nav { flex: 1; padding: 10px 0; }
.sb-item { display: flex; align-items: center; gap: 9px; padding: 8px 18px; font-size: 12.5px; color: var(--muted); cursor: pointer; transition: all .15s; border-left: 2px solid transparent; text-decoration: none; }
.sb-item:hover { color: var(--text); background: rgba(124,106,247,0.06); }
.sb-item.active { color: var(--accent); border-left-color: var(--accent); background: rgba(124,106,247,0.08); }
.sb-item svg { width: 14px; height: 14px; flex-shrink: 0; opacity: .7; }
.sb-item.active svg { opacity: 1; }
.sb-footer { padding: 12px 18px; border-top: 1px solid var(--border); font-family: 'Space Mono', monospace; font-size: 9px; color: var(--muted); line-height: 1.6; }
#content { margin-left: 220px; flex: 1; padding: 40px 48px; max-width: 1040px; }
section { display: none; }
section.active { display: block; }
.sec-title { font-family: 'Space Mono', monospace; font-size: 11px; color: var(--accent); text-transform: uppercase; letter-spacing: 3px; margin-bottom: 6px; }
h1 { font-size: 26px; font-weight: 600; color: #fff; margin-bottom: 8px; line-height: 1.2; }
h2 { font-size: 17px; font-weight: 600; color: var(--text); margin: 32px 0 12px; }
h3 { font-size: 14px; font-weight: 600; color: var(--text); margin: 0 0 8px; }
p { color: var(--muted); font-size: 13.5px; line-height: 1.7; margin-bottom: 12px; }
p strong { color: var(--text); }
ul { padding-left: 16px; margin-bottom: 12px; }
li { font-size: 13px; color: var(--muted); margin-bottom: 5px; }
li strong { color: var(--text); }
.card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 20px 22px; margin-bottom: 16px; }
.card-hdr { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
.card-hdr h3 { margin: 0; }
.badge { font-family: 'Space Mono', monospace; font-size: 9.5px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; padding: 3px 9px; border-radius: 100px; white-space: nowrap; flex-shrink: 0; }
.badge-critical { background: rgba(232,85,85,.15);  color: var(--critical); }
.badge-high     { background: rgba(224,122,48,.15); color: var(--high);     }
.badge-medium   { background: rgba(212,180,41,.15); color: var(--medium);   }
.badge-low      { background: rgba(74,158,255,.15); color: var(--low);      }
.badge-ok       { background: rgba(74,222,128,.12); color: var(--green);    }
.badge-warn     { background: rgba(245,200,66,.12); color: var(--amber);    }
.badge-na       { background: rgba(107,122,154,.15);color: var(--muted);    }
.remedy { border-left: 2px solid var(--accent); padding: 10px 14px; background: rgba(124,106,247,.05); border-radius: 0 6px 6px 0; margin-top: 10px; font-size: 13px; color: var(--text); line-height: 1.65; }
.remedy strong { color: var(--accent); font-family: 'Space Mono', monospace; font-size: 11px; letter-spacing: 1px; }
table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 12.5px; }
thead th { font-family: 'Space Mono', monospace; font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: var(--muted); padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--border); }
tbody td { padding: 9px 12px; border-bottom: 1px solid rgba(31,45,74,.6); color: var(--text); vertical-align: top; line-height: 1.55; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: rgba(124,106,247,.03); }
td code { font-family: 'Space Mono', monospace; font-size: 11px; background: rgba(124,106,247,.1); padding: 1px 5px; border-radius: 3px; color: var(--accent); }
code { font-family: 'Space Mono', monospace; font-size: 11px; background: rgba(124,106,247,.1); padding: 1px 5px; border-radius: 3px; color: var(--accent); }
.stats-row { display: flex; gap: 12px; flex-wrap: wrap; margin: 20px 0; }
.stat-box { flex: 1; min-width: 120px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 16px; text-align: center; }
.stat-num { font-family: 'Space Mono', monospace; font-size: 28px; font-weight: 700; display: block; }
.stat-lbl { font-size: 11px; color: var(--muted); margin-top: 4px; display: block; font-family: 'Space Mono', monospace; text-transform: uppercase; letter-spacing: 1px; }
.stat-critical .stat-num { color: var(--critical); }
.stat-high     .stat-num { color: var(--high);     }
.stat-medium   .stat-num { color: var(--medium);   }
.stat-low      .stat-num { color: var(--low);       }
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 16px 0; }
@media (max-width: 700px) { .two-col { grid-template-columns: 1fr; } #content { padding: 24px 20px; } }
.info-box { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 18px 20px; }
.info-box h3 { font-size: 12px; font-family: 'Space Mono', monospace; text-transform: uppercase; letter-spacing: 1.5px; color: var(--muted); margin-bottom: 10px; }
.info-box ul { list-style: none; padding: 0; }
.info-box ul li { font-size: 13px; color: var(--text); padding: 4px 0; border-bottom: 1px solid var(--border); }
.info-box ul li:last-child { border-bottom: none; }
.info-box ul li::before { content: '→ '; color: var(--accent); font-size: 11px; }
.doc-meta { display: flex; gap: 24px; flex-wrap: wrap; padding: 16px 20px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; margin: 16px 0 28px; }
.meta-item { font-size: 12px; color: var(--muted); }
.meta-item strong { color: var(--text); font-weight: 500; display: block; margin-bottom: 2px; font-family: 'Space Mono', monospace; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; }
.status-bar { display: flex; align-items: center; gap: 10px; padding: 10px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
.status-bar.warn { background: rgba(245,200,66,.08); border: 1px solid rgba(245,200,66,.2); color: var(--amber); }
.status-bar.crit { background: rgba(232,85,85,.08);  border: 1px solid rgba(232,85,85,.2);  color: var(--critical); }
.status-bar.ok   { background: rgba(74,222,128,.07); border: 1px solid rgba(74,222,128,.15); color: var(--green); }
.status-bar svg  { width: 16px; height: 16px; flex-shrink: 0; }
.legal-ref { display: inline-block; font-family: 'Space Mono', monospace; font-size: 10px; color: var(--low); background: rgba(74,158,255,.1); padding: 2px 7px; border-radius: 4px; margin-right: 4px; }
/* Sign-off form */
.signoff-form { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 22px 24px; margin-top: 12px; }
.signoff-form h3 { font-size: 13px; font-family: 'Space Mono', monospace; text-transform: uppercase; letter-spacing: 1.5px; color: var(--muted); margin-bottom: 16px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr 120px; gap: 12px; margin-bottom: 12px; }
@media (max-width: 700px) { .form-row { grid-template-columns: 1fr; } }
.form-group label { display: block; font-size: 11px; color: var(--muted); font-family: 'Space Mono', monospace; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
.form-group input, .form-group textarea, .form-group select { width: 100%; background: var(--surface2); border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px; font-size: 13px; color: var(--text); font-family: 'DM Sans', sans-serif; }
.form-group input:focus, .form-group textarea:focus { outline: none; border-color: rgba(124,106,247,.5); }
.form-group textarea { resize: vertical; min-height: 64px; }
.btn-sign { background: rgba(124,106,247,.15); border: 1px solid rgba(124,106,247,.4); color: var(--accent); font-family: 'Space Mono', monospace; font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; padding: 9px 20px; border-radius: 6px; cursor: pointer; width: 100%; margin-top: 4px; transition: background .15s; }
.btn-sign:hover { background: rgba(124,106,247,.25); }
/* Review history */
.review-history { margin-top: 28px; }
.review-history h3 { font-size: 12px; font-family: 'Space Mono', monospace; text-transform: uppercase; letter-spacing: 1.5px; color: var(--muted); margin-bottom: 12px; }
.no-reviews { font-size: 13px; color: var(--muted); padding: 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; text-align: center; font-style: italic; }
.alert { padding: 10px 14px; border-radius: 7px; font-size: 13px; margin-bottom: 16px; }
.alert-success { background: rgba(74,222,128,.08); border: 1px solid rgba(74,222,128,.2); color: var(--green); }
.alert-error   { background: rgba(232,85,85,.08);  border: 1px solid rgba(232,85,85,.2);  color: var(--critical); }
</style>
</head>
<body>

<nav id="sidebar">
  <div class="sb-brand">
    <div class="logo"><?= htmlspecialchars($appName) ?></div>
    <div class="doc-type">DPIA · v1.0 · Art. 35 UK GDPR</div>
  </div>
  <a class="sb-back" href="/admin.php?section=compliance">
    <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 2L4 6l4 4"/></svg>
    Admin Panel
  </a>
  <div class="sb-nav">
    <a class="sb-item active" href="#" data-section="overview">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/><rect x="1" y="9" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/></svg>
      Overview
    </a>
    <a class="sb-item" href="#" data-section="controller">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="5" r="3"/><path d="M2 14c0-3 2.7-5 6-5s6 2 6 5"/></svg>
      Controller &amp; Processors
    </a>
    <a class="sb-item" href="#" data-section="processing">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="12" height="12" rx="1.5"/><path d="M5 8h6M5 5h6M5 11h4"/></svg>
      Processing Description
    </a>
    <a class="sb-item" href="#" data-section="necessity">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2v12M2 8h12"/></svg>
      Necessity &amp; Proportionality
    </a>
    <a class="sb-item" href="#" data-section="risks">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2L2 13h12L8 2z"/><path d="M8 6v4M8 11.5v.5"/></svg>
      Risk Assessment
    </a>
    <a class="sb-item" href="#" data-section="mitigations">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2l5 4v4c0 3-5 5-5 5S3 13 3 10V6l5-4z"/></svg>
      Mitigations
    </a>
    <a class="sb-item" href="#" data-section="transfers">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6"/><path d="M2 8h12M8 2c-2 2-3 4-3 6s1 4 3 6M8 2c2 2 3 4 3 6s-1 4-3 6"/></svg>
      International Transfers
    </a>
    <a class="sb-item" href="#" data-section="consultation">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 4h10v7H3z"/><path d="M6 11v2l2-2h2"/></svg>
      Consultation
    </a>
    <a class="sb-item" href="#" data-section="conclusion">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 8l3 3 7-7"/></svg>
      Conclusion &amp; Sign-off
    </a>
  </div>
  <div class="sb-footer">
    UK GDPR Art. 35 / DPA 2018 S.64<br>
    Controller: <?= htmlspecialchars(mb_strimwidth($controllerDisplay, 0, 28, '…')) ?><br>
    <?php if ($lastReview): ?>
    Last signed: <?= date('d M Y', strtotime($lastReview)) ?><br>
    Next review: <?= date('d M Y', strtotime($nextReview)) ?><?= $isOverdue ? ' ⚠' : '' ?>
    <?php else: ?>
    <span style="color:var(--critical);">⚠ Not yet signed off</span>
    <?php endif; ?>
  </div>
</nav>

<div id="content">

<?php if ($success): ?>
<div class="alert alert-success" data-section="conclusion" style="cursor:pointer"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($isOverdue): ?>
<div class="status-bar crit" style="margin-bottom:20px;">
  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 2L2 13h12L8 2z"/><path d="M8 6v4M8 11.5v.5"/></svg>
  <span><strong>DPIA REVIEW OVERDUE.</strong> Last sign-off was <?= date('d M Y', strtotime($lastReview)) ?>. Annual review was due <?= htmlspecialchars($nextReview) ?>. <a href="#" data-section="conclusion" style="color:inherit;text-decoration:underline;">Sign off now →</a></span>
</div>
<?php elseif ($duesSoon): ?>
<div class="status-bar warn" style="margin-bottom:20px;">
  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 2L2 13h12L8 2z"/><path d="M8 6v4M8 11.5v.5"/></svg>
  <span><strong>DPIA review due soon.</strong> Annual review scheduled for <?= htmlspecialchars($nextReview) ?>. <a href="#" data-section="conclusion" style="color:inherit;text-decoration:underline;">Review and sign off →</a></span>
</div>
<?php elseif (!$lastReview): ?>
<div class="status-bar warn" style="margin-bottom:20px;">
  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 2L2 13h12L8 2z"/><path d="M8 6v4M8 11.5v.5"/></svg>
  <span><strong>DPIA has not been signed off.</strong> UK GDPR Art. 35 requires the controller to sign off the DPIA before commencing high-risk processing. <a href="#" data-section="conclusion" style="color:inherit;text-decoration:underline;">Sign off →</a></span>
</div>
<?php endif; ?>

<!-- ══════ OVERVIEW ══════ -->
<section id="overview" class="active">
  <div class="sec-title">Data Protection Impact Assessment</div>
  <h1><?= htmlspecialchars($appName) ?> — DPIA v1.0</h1>

  <div class="doc-meta">
    <div class="meta-item"><strong>Controller</strong><?= htmlspecialchars($controllerDisplay) ?></div>
    <div class="meta-item"><strong>DPO / Contact</strong><?= $dpoName !== 'Not designated' ? htmlspecialchars($dpoName) : 'Not designated — recommended' ?></div>
    <div class="meta-item"><strong>Date Initiated</strong>3 May 2026</div>
    <div class="meta-item"><strong>Version</strong>1.0 — Initial Assessment</div>
    <div class="meta-item"><strong>Last Signed</strong><?= $lastReview ? date('d M Y', strtotime($lastReview)) : '<span style="color:var(--critical)">Not signed</span>' ?></div>
    <div class="meta-item"><strong>Next Review</strong><?= $nextReview ? htmlspecialchars($nextReview) . ($isOverdue ? ' <span style="color:var(--critical)">OVERDUE</span>' : '') : '3 May 2027 (or on material change)' ?></div>
    <div class="meta-item"><strong>Legal Basis</strong>UK GDPR Art. 35 / EU GDPR Art. 35</div>
  </div>

  <div class="status-bar warn">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 2L2 13h12L8 2z"/><path d="M8 6v4M8 11.5v.5"/></svg>
    <span><strong>REMEDIATION REQUIRED BEFORE PRODUCTION:</strong> This DPIA identifies significant residual risks that must be addressed before the platform handles live clinical PHI. See Conclusion for required actions.</span>
  </div>

  <h2>Trigger for this DPIA</h2>
  <p>UK GDPR Art. 35 requires a DPIA before commencing processing that is <em>likely to result in a high risk</em> to the rights and freedoms of natural persons. This assessment was triggered by the following high-risk indicators:</p>
  <div class="two-col">
    <div class="info-box">
      <h3>High-Risk Indicators Present</h3>
      <ul>
        <li>Processing of special category data (Art. 9) — mental health, sexuality, gender</li>
        <li>Large-scale processing of sensitive personal data (health records, session transcripts)</li>
        <li>Systematic evaluation of individuals (clinical notes, session summaries)</li>
        <li>Data transferred to third-country processors (AI vendors, payment processors)</li>
        <li>Vulnerable data subjects (individuals in mental health crisis, peer support clients)</li>
        <li>Automated processing that could affect access to services (firewall IP blocks)</li>
      </ul>
    </div>
    <div class="info-box">
      <h3>ICO Blacklist Categories</h3>
      <ul>
        <li>Health data — explicit trigger under ICO guidance</li>
        <li>Mental health — elevated sensitivity beyond standard health data</li>
        <li>Sexual orientation and gender identity — Art. 9 special category</li>
        <li>Therapeutic session transcripts — psychotherapy-equivalent data</li>
        <li>Children may be data subjects — safeguarding considerations apply</li>
      </ul>
    </div>
  </div>

  <h2>Platform Overview</h2>
  <p><?= htmlspecialchars($appName) ?> is a self-hosted web platform enabling remote therapeutic conversation sessions between practitioners (therapists, peer support specialists, life coaches) and their clients. It provides real-time video/audio (WebRTC), an in-session shared virtual environment, session recording and export, practitioner clinical notes, booking management, and a client portal.</p>
  <p>The platform is open-source, free to use, and designed for deployment on shared or dedicated web hosting. <strong>Each deploying institution or individual acts as the Data Controller.</strong> The platform software developer is not a data controller for any individual deployment.</p>

  <div class="stats-row">
    <div class="stat-box stat-critical"><span class="stat-num">4</span><span class="stat-lbl">Critical Risks</span></div>
    <div class="stat-box stat-high"><span class="stat-num">5</span><span class="stat-lbl">High Risks</span></div>
    <div class="stat-box stat-medium"><span class="stat-num">4</span><span class="stat-lbl">Medium Risks</span></div>
    <div class="stat-box stat-low"><span class="stat-num">3</span><span class="stat-lbl">Low Risks (Accepted)</span></div>
  </div>
</section>

<!-- ══════ CONTROLLER & PROCESSORS ══════ -->
<section id="controller">
  <div class="sec-title">Data Protection Impact Assessment</div>
  <h1>Controller &amp; Processors</h1>

  <h2>Data Controller</h2>
  <div class="card">
    <h3>Platform Operator</h3>
    <table>
      <thead><tr><th>Attribute</th><th>Value</th></tr></thead>
      <tbody>
        <tr><td>Controller Name</td><td><?= htmlspecialchars($controllerDisplay) ?></td></tr>
        <tr><td>Address</td><td><?= htmlspecialchars($controllerAddr) ?></td></tr>
        <tr><td>Contact</td><td><?= $ctx['controller_email'] ? '<a href="mailto:' . htmlspecialchars($ctx['controller_email']) . '" style="color:var(--accent)">' . htmlspecialchars($ctx['controller_email']) . '</a>' : 'Not configured' ?></td></tr>
        <tr><td>DPO</td><td><?= $dpoName !== 'Not designated' ? htmlspecialchars($dpoName) . ($dpoEmail ? ' — <a href="mailto:' . htmlspecialchars($dpoEmail) . '" style="color:var(--accent)">' . htmlspecialchars($dpoEmail) . '</a>' : '') : '<span style="color:var(--amber)">Not designated — recommended under Art. 37(1)(c) for special category processing at scale</span>' ?></td></tr>
        <tr><td>ICO Registration</td><td>Required for any organisation processing personal data for a fee or commercial benefit. Verify with ICO tool.</td></tr>
        <tr><td>Role</td><td>Data Controller — UK GDPR Art. 4(7) / EU GDPR Art. 4(7)</td></tr>
      </tbody>
    </table>
    <p style="font-size:12px;color:var(--muted);margin-top:8px;">Controller details are drawn from Admin → Compliance settings. Update <a href="/admin.php?section=compliance" style="color:var(--accent)">there</a> to keep this document current.</p>
  </div>

  <h2>Data Processors (Third Parties)</h2>
  <p>The following third parties process personal data on behalf of the Controller. A Data Processing Agreement (DPA) must be in place with each before personal data is transferred to them (UK GDPR Art. 28).</p>
  <table>
    <thead><tr><th>Processor</th><th>Purpose</th><th>Data Transferred</th><th>Location</th><th>Status</th></tr></thead>
    <tbody>
      <tr><td><strong>Web Hosting Provider</strong></td><td>Platform hosting, database, file storage</td><td>All personal data stored on platform (PHI, credentials, transcripts)</td><td>UK / EEA (verify)</td><td><span class="badge badge-warn">DPA upload required in Admin</span></td></tr>
      <tr><td><strong>SMTP Provider</strong></td><td>Transactional email (booking confirmations, reminders, password resets)</td><td>Name, email address, booking reference</td><td>US (typically) — international transfer applies</td><td><span class="badge badge-warn">DPA upload required in Admin</span></td></tr>
      <tr><td><strong>Payment Processor</strong><br><small>(PayPal / Stripe / Square)</small></td><td>Processing session payments</td><td>Name, email, payment reference (card data handled by PSP)</td><td>US / EEA — varies</td><td><span class="badge badge-warn">Practitioner DPA required</span></td></tr>
      <tr><td><strong>AI Vendor</strong><br><small>(e.g. OpenAI)</small></td><td>AI-assisted session summaries, therapeutic suggestions</td><td>Session transcript excerpts — <strong>may contain mental health PHI (Art. 9)</strong></td><td>US — international transfer applies</td><td><span class="badge badge-critical">DPA gap identified — see Transfers</span></td></tr>
    </tbody>
  </table>
  <div class="remedy">
    <strong>Action Required:</strong> Platform operators must upload signed DPA documents for their hosting provider and SMTP service via the Admin Panel before going live. Practitioners must upload DPAs for any AI vendor or payment processor they activate. The platform enforces this gating — AI and payment features are blocked until the corresponding DPA is on file.
  </div>
</section>

<!-- ══════ PROCESSING DESCRIPTION ══════ -->
<section id="processing">
  <div class="sec-title">Data Protection Impact Assessment</div>
  <h1>Processing Description</h1>

  <h2>Categories of Personal Data Processed</h2>
  <table>
    <thead><tr><th>Category</th><th>Examples</th><th>Sensitivity</th><th>Storage</th></tr></thead>
    <tbody>
      <tr><td><strong>Identity Data</strong></td><td>Name, email, display name, avatar photo</td><td><span class="badge badge-medium">Standard</span></td><td><code>end_users</code>, <code>practitioners</code>, <code>bookings</code></td></tr>
      <tr><td><strong>Health Data (Art. 9)</strong></td><td>Session transcripts, voice recordings, practitioner clinical notes, session summaries, highlighted moments, crisis flags</td><td><span class="badge badge-critical">Special Category</span></td><td><code>messages</code>, <code>practitioner_notes</code>, <code>highlights</code></td></tr>
      <tr><td><strong>Mental Health Data (Art. 9)</strong></td><td>Therapeutic conversation content, crisis indicators, emotional state markers, session ratings</td><td><span class="badge badge-critical">Special Category — Elevated</span></td><td><code>messages</code>, <code>practitioner_notes</code>, <code>session_ratings</code></td></tr>
      <tr><td><strong>Sexual Life / Orientation (Art. 9)</strong></td><td>Sexuality, gender identity (collected optionally)</td><td><span class="badge badge-critical">Special Category</span></td><td><code>end_users.sexuality / gender</code> — encrypted at rest</td></tr>
      <tr><td><strong>Biometric Data (Art. 9)</strong></td><td>Webcam feed (WebRTC, not stored), voice recordings (voice notes stored)</td><td><span class="badge badge-high">Special Category (stored audio)</span></td><td>Voice notes: <code>/assets/uploads/vn_*</code></td></tr>
      <tr><td><strong>Financial Data</strong></td><td>Payment amounts, payment method type (card data handled by PSP only)</td><td><span class="badge badge-medium">Standard</span></td><td><code>bookings</code></td></tr>
      <tr><td><strong>Technical Data</strong></td><td>IP addresses, user agent strings, login timestamps, session join/leave times</td><td><span class="badge badge-low">Standard (limited retention)</span></td><td><code>audit_log</code>, <code>login_attempts</code>, <code>ip_blocks</code></td></tr>
      <tr><td><strong>Credentials</strong></td><td>Password hashes (bcrypt/Argon2id), TOTP MFA secrets</td><td><span class="badge badge-high">Security-Critical</span></td><td><code>practitioners.password_hash</code>, <code>end_users.password_hash</code></td></tr>
    </tbody>
  </table>

  <h2>Processing Activities</h2>
  <table>
    <thead><tr><th>Activity</th><th>Legal Basis</th><th>Data Subjects</th><th>Retention</th></tr></thead>
    <tbody>
      <tr><td><strong>Session transcript storage</strong></td><td>Art. 9(2)(h) — health treatment; Art. 6(1)(b) — contract</td><td>Clients, session participants</td><td>Until export + deletion; configurable retention window (default: 90 days)</td></tr>
      <tr><td><strong>Clinical notes (practitioner)</strong></td><td>Art. 9(2)(h) — health treatment; professional obligation</td><td>Clients</td><td>Until export + deletion; minimum 8 years recommended</td></tr>
      <tr><td><strong>Booking records</strong></td><td>Art. 6(1)(b) — contract; Art. 6(1)(c) — legal obligation</td><td>Clients, practitioners</td><td>7 years for financial records; personal details configurable</td></tr>
      <tr><td><strong>Audit logging</strong></td><td>Art. 6(1)(c) — legal obligation (security accountability)</td><td>Practitioners, clients (as subjects)</td><td>IP addresses: 12 months then nulled. Audit records: indefinite (chain integrity)</td></tr>
      <tr><td><strong>AI processing of session data</strong></td><td>Art. 9(2)(a) — explicit consent required; Art. 6(1)(a) — consent</td><td>Clients (as data subjects in transcript)</td><td>Per AI vendor DPA — typically not retained beyond request processing</td></tr>
      <tr><td><strong>Voice notes (audio recordings)</strong></td><td>Art. 9(2)(h) — health; Art. 6(1)(b) — contract</td><td>Session participants</td><td><strong>Currently indefinite — remediation required.</strong> Should mirror session data retention.</td></tr>
      <tr><td><strong>Consent records</strong></td><td>Art. 6(1)(c) — legal obligation (demonstrate consent)</td><td>Clients</td><td>Duration of processing relationship + reasonable period for dispute resolution</td></tr>
    </tbody>
  </table>

  <h2>Data Flow Summary</h2>
  <div class="card">
    <h3>Session Data Lifecycle</h3>
    <p><strong>Collection:</strong> Client joins session via join_token URL → browser establishes WebRTC connection with practitioner → messages, voice notes, and files are exchanged in real time.</p>
    <p><strong>Storage:</strong> Session messages stored in <code>messages</code> table (AES-256-GCM encryption pending). Files stored under <code>/assets/uploads/</code> with access control via <code>serve_file.php</code>. Practitioner notes in <code>practitioner_notes</code> (encryption pending).</p>
    <p><strong>Export:</strong> Practitioner exports session as PDF or HTML. On export, session data should be deleted from the database (messages, notes, highlights, participants). Voice notes currently excluded from deletion — remediation required.</p>
    <p><strong>Deletion:</strong> GDPR Art. 17 right to erasure implemented in <code>api/gdpr.php</code>. Deletes end_user record, bookings, consent records, and session participations.</p>
  </div>
</section>

<!-- ══════ NECESSITY & PROPORTIONALITY ══════ -->
<section id="necessity">
  <div class="sec-title">Data Protection Impact Assessment</div>
  <h1>Necessity &amp; Proportionality</h1>

  <h2>Lawful Basis Assessment</h2>
  <div class="card">
    <div class="card-hdr"><h3>Special Category Data (Art. 9)</h3><span class="badge badge-critical">Requires Art. 9 Condition</span></div>
    <p>Processing of mental health session content, clinical notes, gender, and sexuality data requires both a lawful basis under Art. 6 AND a condition under Art. 9(2).</p>
    <table>
      <thead><tr><th>Data Type</th><th>Art. 9(2) Condition</th><th>Notes</th></tr></thead>
      <tbody>
        <tr><td>Session transcripts, clinical notes</td><td><strong>Art. 9(2)(h)</strong> — Preventive or occupational medicine, medical diagnosis, health or social care</td><td>Applies where a health professional bound by professional confidentiality processes the data. <strong>Peer support specialists may not satisfy this condition alone — require explicit contractual basis or consent.</strong></td></tr>
        <tr><td>Gender, sexuality, date of birth</td><td><strong>Art. 9(2)(a)</strong> — Explicit consent</td><td>Collected optionally with explicit consent at registration. Consent record in <code>consent_records</code>. Withdrawal mechanism implemented.</td></tr>
        <tr><td>AI processing of session content</td><td><strong>Art. 9(2)(a)</strong> — Explicit consent</td><td>Explicit consent required before session content is sent to any AI vendor. Obtained via AI Agreement flow in client portal.</td></tr>
      </tbody>
    </table>
    <div class="remedy">
      <strong>Action Required:</strong> Platform operators deploying for peer support (non-clinical) use must obtain explicit Art. 9(2)(a) consent for processing therapeutic session content. The consent record must clearly state the categories of special category data being processed.
    </div>
  </div>

  <h2>Data Minimisation Assessment</h2>
  <table>
    <thead><tr><th>Data Element</th><th>Collected?</th><th>Necessary?</th><th>Assessment</th></tr></thead>
    <tbody>
      <tr><td>Session transcript (full)</td><td><span class="badge badge-ok">Yes</span></td><td><span class="badge badge-ok">Yes</span></td><td>Core platform purpose — enabling therapeutic session with export for records. Proportionate.</td></tr>
      <tr><td>Gender, sexuality</td><td><span class="badge badge-ok">Optional</span></td><td><span class="badge badge-ok">Optional</span></td><td>Collected only if client volunteers it. Encrypted at rest. Clear purpose. Proportionate.</td></tr>
      <tr><td>Date of birth</td><td><span class="badge badge-ok">Optional</span></td><td><span class="badge badge-medium">Conditional</span></td><td>Useful for age verification and safeguarding. Encrypted at rest. Should not be mandatory.</td></tr>
      <tr><td>IP addresses (audit log)</td><td><span class="badge badge-ok">Yes</span></td><td><span class="badge badge-ok">Yes</span></td><td>Required for security accountability and fraud prevention. Auto-nulled after 12 months. Proportionate.</td></tr>
      <tr><td>Voice notes (stored audio)</td><td><span class="badge badge-ok">Yes (when sent)</span></td><td><span class="badge badge-ok">Yes</span></td><td>Core feature. However, current indefinite retention after session export is disproportionate. Must be included in post-export deletion.</td></tr>
    </tbody>
  </table>

  <h2>Purpose Limitation</h2>
  <div class="card">
    <p>Data collected for session delivery must not be repurposed for analytics, profiling, marketing, or third-party sharing without obtaining fresh consent. The platform must not transmit session content to AI vendors without explicit per-client, per-session consent. Any new processing purpose introduced by a platform operator requires a fresh DPIA assessment or addendum.</p>
  </div>
</section>

<!-- ══════ RISK ASSESSMENT ══════ -->
<section id="risks">
  <div class="sec-title">Data Protection Impact Assessment</div>
  <h1>Risk Assessment</h1>
  <p>Risks are rated on likelihood (1–3) × impact (1–3). Score 7–9 = Critical; 5–6 = High; 3–4 = Medium; 1–2 = Low.</p>

  <h2>Critical Risks</h2>

  <div class="card">
    <div class="card-hdr"><h3>R-01 — PHI Stored in Plaintext (Messages &amp; Notes)</h3><span class="badge badge-critical">Critical · Score 9</span></div>
    <p><strong>Description:</strong> Therapeutic session transcripts (<code>messages</code> table) and practitioner clinical notes (<code>practitioner_notes</code>) are stored in plaintext MySQL. On shared hosting infrastructure, the database is accessible to hosting provider support staff, other tenants in the event of misconfiguration, and any attacker who obtains DB credentials.</p>
    <p><strong>Likelihood:</strong> 3 (shared hosting, credential exposure is a common attack vector). <strong>Impact:</strong> 3 (therapeutic content, mental health, may contain disclosures of trauma/abuse/abuse situations).</p>
    <p><strong>Rights and Freedoms at Risk:</strong> Right to privacy (Art. 8 ECHR); psychological harm from disclosure of sensitive therapeutic conversations; potential for discrimination based on mental health history; safeguarding risk if session content reveals ongoing abuse.</p>
    <div class="remedy"><strong>Required Mitigation:</strong> Encrypt <code>messages.content</code> and <code>practitioner_notes.note_content</code> using AES-256-GCM (<code>enc3:</code> context-binding scheme already implemented in <code>phi_crypto.php</code>). Key must be stored outside the database — environment variable or external key management service.</div>
  </div>

  <div class="card">
    <div class="card-hdr"><h3>R-02 — Indefinite Retention of Session PHI After Export</h3><span class="badge badge-critical">Critical · Score 9</span></div>
    <p><strong>Description:</strong> Platform design intent is ephemeral data — delete after export. The implementation retains session data indefinitely. Voice notes are explicitly excluded from post-export deletion in the current code. This creates an ever-growing repository of clinical PHI in violation of UK GDPR Art. 5(1)(e) (storage limitation).</p>
    <p><strong>Likelihood:</strong> 3 (confirmed by code review). <strong>Impact:</strong> 3 (direct GDPR violation, clinical PHI accumulation with no purge).</p>
    <p><strong>Rights and Freedoms at Risk:</strong> Right to erasure (Art. 17); storage limitation principle (Art. 5(1)(e)); increased breach impact from accumulated PHI that should not exist.</p>
    <div class="remedy"><strong>Required Mitigation:</strong> Implement post-export deletion in <code>api/export.php</code>: on confirmed export, delete messages, practitioner_notes, highlights, and participants. Include voice note files in deletion sweep. Add configurable automated retention window (recommended: 90 days) for sessions not yet exported.</div>
  </div>

  <div class="card">
    <div class="card-hdr"><h3>R-03 — File Uploads Publicly Accessible</h3><span class="badge badge-critical">Critical · Score 9</span></div>
    <p><strong>Description:</strong> Session file attachments (<code>f_*</code>) and voice notes (<code>vn_*</code>) are stored under <code>/assets/uploads/</code> which is web-accessible. <strong>Note: an .htaccess deny directive and serve_file.php session-membership check have been implemented in build 1.0.0-294. This risk is partially mitigated pending verification testing.</strong></p>
    <p><strong>Likelihood:</strong> 2 (partially mitigated). <strong>Impact:</strong> 3 (voice recordings and image/video attachments contain PHI).</p>
    <div class="remedy"><strong>Status:</strong> Mitigated in build 1.0.0-294. Verify <code>serve_file.php</code> enforces session membership check. Test that <code>f_</code> and <code>vn_</code> files return 403 when accessed without a valid join_token.</div>
  </div>

  <div class="card">
    <div class="card-hdr"><h3>R-04 — AI Vendor DPA Gap (OpenAI Schedule 1)</h3><span class="badge badge-critical">Critical · Score 9</span></div>
    <p><strong>Description:</strong> The OpenAI DPA Schedule 1 §5 states: <em>"No sensitive data is intended to be transferred."</em> However, the AI integration sends session transcript excerpts to OpenAI, which constitute health data (Art. 9). This creates a direct conflict between the DPA's stated scope and actual use.</p>
    <p><strong>Likelihood:</strong> 3 (certain — occurs whenever AI feature is used). <strong>Impact:</strong> 3 (Art. 9 data sent under a DPA that disclaims sensitive data — contractual and regulatory exposure).</p>
    <div class="remedy"><strong>Required Mitigation:</strong> Either (a) update OpenAI DPA Schedule 1 to accurately describe mental health transcript processing, or (b) implement data minimisation — strip identifying information before API call. Option (b) recommended for defence-in-depth. Document in ROPA.</div>
  </div>

  <h2>High Risks</h2>

  <div class="card">
    <div class="card-hdr"><h3>R-05 — No Automatic Session Inactivity Timeout</h3><span class="badge badge-high">High · Score 6</span></div>
    <p>Practitioner and client sessions do not expire after inactivity. An unattended logged-in terminal provides unrestricted access to all client PHI. Particularly significant for practitioners with access to clinical notes and transcripts.</p>
    <div class="remedy"><strong>Mitigation:</strong> Implement <code>last_activity</code> timestamp check on each authenticated request. Destroy session after 15 minutes (practitioners) / 30 minutes (clients) of inactivity.</div>
  </div>

  <div class="card">
    <div class="card-hdr"><h3>R-06 — No HTTPS Enforcement at Application Layer</h3><span class="badge badge-high">High · Score 6</span></div>
    <p>Transmission of PHI over unencrypted HTTP would constitute a GDPR violation. The application does not enforce HTTPS — it relies entirely on server configuration. HTTP→HTTPS redirect is implemented in <code>security_headers.php</code> when production mode is on.</p>
    <div class="remedy"><strong>Mitigation:</strong> Operators must verify TLS is configured before enabling production mode. Setup wizard should check for HTTPS and warn before allowing production activation.</div>
  </div>

  <div class="card">
    <div class="card-hdr"><h3>R-07 — File Upload Extension Bypass (PHP Execution Risk)</h3><span class="badge badge-high">High · Score 6</span></div>
    <p>Two upload endpoints (<code>api/backgrounds.php</code>, <code>api/bookings.php</code>) derive the stored file extension from the user-supplied filename rather than from the MIME-validated type. An attacker could upload a JPEG with a <code>.php</code> extension, which would be stored executable.</p>
    <div class="remedy"><strong>Mitigation:</strong> Replace <code>pathinfo($file['name'], PATHINFO_EXTENSION)</code> with a hardcoded MIME-to-extension map, as already correctly implemented in <code>api/files.php</code>.</div>
  </div>

  <div class="card">
    <div class="card-hdr"><h3>R-08 — join_token Transmitted in URL Query Parameter</h3><span class="badge badge-high">High · Score 6</span></div>
    <p>The join_token (session credential for PHI access) appears in server access logs, browser history, Referer headers, and CDN/proxy logs. If these logs are accessible to hosting staff or compromised, active session tokens could be extracted.</p>
    <div class="remedy"><strong>Mitigation:</strong> Implement token exchange: a short-lived one-time URL token that exchanges for a server-side session variable on first access, then redirects to a clean URL. At minimum, document log retention requirements.</div>
  </div>

  <div class="card">
    <div class="card-hdr"><h3>R-09 — Incomplete Audit Coverage</h3><span class="badge badge-high">High · Score 6</span></div>
    <p>The audit log captures notes.write, file.upload, session.export, and GDPR events but does not log: session.view (practitioner opening session transcript), message reads, login/logout events, or admin actions. A malicious insider could access PHI without creating an audit trail.</p>
    <div class="remedy"><strong>Mitigation:</strong> Add audit_log() calls to session view actions, transcript access, admin actions (user creation/deletion/status changes), and authentication events.</div>
  </div>

  <h2>Medium Risks</h2>

  <div class="card">
    <div class="card-hdr"><h3>R-10 — MFA Optional for Practitioners</h3><span class="badge badge-medium">Medium · Score 4</span></div>
    <p>TOTP MFA is implemented but optional. Practitioners with access to all client PHI should be strongly encouraged or required to enable MFA. An account compromise without MFA provides full PHI access.</p>
    <div class="remedy"><strong>Mitigation:</strong> Admin panel setting to require MFA for practitioner accounts. Display prominent warning to practitioners who have not enabled MFA.</div>
  </div>

  <div class="card">
    <div class="card-hdr"><h3>R-11 — Booking PII Not Encrypted at Rest</h3><span class="badge badge-medium">Medium · Score 4</span></div>
    <p>Guest name, email, date of birth, gender, sexuality stored in plaintext in the <code>bookings</code> table. DOB + name + location constitutes strong re-identification data. The encryption infrastructure exists but is not applied to bookings.</p>
    <div class="remedy"><strong>Mitigation:</strong> Apply phi_encrypt/phi_decrypt to sensitive booking fields using the enc3: context-binding pattern already in use elsewhere.</div>
  </div>

  <div class="card">
    <div class="card-hdr"><h3>R-12 — Unauthenticated Presence Endpoint</h3><span class="badge badge-medium">Medium · Score 4</span></div>
    <p>The presence endpoint leaks practitioner activity status and display names to unauthenticated callers who know a session_id (sequential integer — trivially enumerable).</p>
    <div class="remedy"><strong>Mitigation:</strong> Require valid join_token or practitioner session to access presence data.</div>
  </div>

  <div class="card">
    <div class="card-hdr"><h3>R-13 — No Safeguarding / Crisis Escalation Procedure</h3><span class="badge badge-medium">Medium · Score 4</span></div>
    <p>The platform has a crisis message type but no documented procedure for practitioners when a participant discloses imminent risk to self or others. Platform operators serving vulnerable populations must have a safeguarding policy.</p>
    <div class="remedy"><strong>Mitigation:</strong> Platform operator must document a safeguarding policy. Not a technical control — organisational measure required.</div>
  </div>
</section>

<!-- ═══��══ MITIGATIONS ══════ -->
<section id="mitigations">
  <div class="sec-title">Data Protection Impact Assessment</div>
  <h1>Mitigation Measures</h1>

  <h2>Technical Measures (Implemented)</h2>
  <table>
    <thead><tr><th>Measure</th><th>Implementation</th><th>Status</th></tr></thead>
    <tbody>
      <tr><td>Password hashing</td><td>bcrypt + Argon2id with automatic upgrade on login</td><td><span class="badge badge-ok">Implemented</span></td></tr>
      <tr><td>TOTP MFA — Practitioners &amp; Clients</td><td>RFC 6238 TOTP with replay prevention in <code>totp_used_codes</code></td><td><span class="badge badge-ok">Implemented</span></td></tr>
      <tr><td>CSRF protection</td><td>hash_equals() token validation on all mutating endpoints</td><td><span class="badge badge-ok">Implemented</span></td></tr>
      <tr><td>Brute-force firewall</td><td>IP-based rate limiting, temporary/permanent blocks, automated email alert</td><td><span class="badge badge-ok">Implemented</span></td></tr>
      <tr><td>Special category encryption</td><td>AES-256-GCM (enc3:) for gender, sexuality, DOB in end_users</td><td><span class="badge badge-ok">Implemented</span></td></tr>
      <tr><td>Session file access control</td><td>serve_file.php — session membership check before streaming PHI files</td><td><span class="badge badge-ok">Implemented</span></td></tr>
      <tr><td>HTTP security headers</td><td>HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, CSP nonce</td><td><span class="badge badge-ok">Implemented</span></td></tr>
      <tr><td>Audit log chain hash</td><td>HMAC-SHA256 chain with INSERT-only DB user support</td><td><span class="badge badge-ok">Implemented</span></td></tr>
      <tr><td>IP address auto-purge</td><td>Audit log IPs nulled after 12 months via cron</td><td><span class="badge badge-ok">Implemented</span></td></tr>
      <tr><td>GDPR right to erasure</td><td><code>api/gdpr.php</code> — delete end_user, bookings, consent records</td><td><span class="badge badge-ok">Implemented</span></td></tr>
      <tr><td>Consent management</td><td>Explicit consent for special category data; withdrawal mechanism in client portal</td><td><span class="badge badge-ok">Implemented</span></td></tr>
      <tr><td>DPA upload gating</td><td>AI and payment features blocked until corresponding DPA is on file</td><td><span class="badge badge-ok">Implemented</span></td></tr>
      <tr><td>Intake data encryption</td><td>Intake form responses encrypted at rest via phi_encrypt</td><td><span class="badge badge-ok">Implemented</span></td></tr>
      <tr><td>Appointment reminder cron</td><td>Automated 24h reminders; idempotent (reminder_24h_sent_at guard)</td><td><span class="badge badge-ok">Implemented</span></td></tr>
    </tbody>
  </table>

  <h2>Technical Measures (Outstanding)</h2>
  <table>
    <thead><tr><th>Measure</th><th>Risk Addressed</th><th>Priority</th></tr></thead>
    <tbody>
      <tr><td>PHI encryption at rest (messages, notes)</td><td>R-01 — Plaintext PHI in database</td><td><span class="badge badge-critical">Critical</span></td></tr>
      <tr><td>Post-export session data deletion incl. voice notes</td><td>R-02 — Indefinite PHI retention</td><td><span class="badge badge-critical">Critical</span></td></tr>
      <tr><td>File upload extension fix (backgrounds + booking avatar)</td><td>R-07 — PHP execution risk</td><td><span class="badge badge-high">High</span></td></tr>
      <tr><td>Session inactivity timeout</td><td>R-05 — Unattended authenticated session</td><td><span class="badge badge-high">High</span></td></tr>
      <tr><td>join_token exchange / clean URL</td><td>R-08 — Token in server logs</td><td><span class="badge badge-medium">Medium</span></td></tr>
      <tr><td>Booking PII encryption</td><td>R-11 — Plaintext PII in bookings</td><td><span class="badge badge-medium">Medium</span></td></tr>
      <tr><td>Presence endpoint authentication</td><td>R-12 — Activity disclosure</td><td><span class="badge badge-medium">Medium</span></td></tr>
      <tr><td>Extended audit coverage (session.view, auth events, admin actions)</td><td>R-09 — Incomplete audit trail</td><td><span class="badge badge-high">High</span></td></tr>
    </tbody>
  </table>

  <h2>Organisational Measures</h2>
  <div class="card">
    <h3>Required from Platform Operators</h3>
    <ul style="list-style:none;padding:0;">
      <li style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;">Upload and maintain DPAs for hosting provider and SMTP service before going live</li>
      <li style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;">Ensure practitioners upload DPAs for any AI vendor or payment processor before activating those features</li>
      <li style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;">Designate a Data Protection Officer (or equivalent privacy contact) and document their details in Admin → Compliance</li>
      <li style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;">Register with the ICO (UK) or equivalent supervisory authority</li>
      <li style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;">Publish a Privacy Notice compliant with Art. 13/14 UK GDPR</li>
      <li style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;">Implement a safeguarding policy covering crisis disclosure procedures</li>
      <li style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;">Train practitioners on data protection obligations and their role processing special category data</li>
      <li style="padding:6px 0;border-bottom:1px solid var(--border);font-size:13px;">Establish a data breach response procedure — notify ICO within 72 hours of becoming aware (Art. 33)</li>
      <li style="padding:6px 0;font-size:13px;">Review and update this DPIA annually or when material changes to processing occur — use the sign-off below</li>
    </ul>
  </div>
</section>

<!-- ══════ INTERNATIONAL TRANSFERS ══════ -->
<section id="transfers">
  <div class="sec-title">Data Protection Impact Assessment</div>
  <h1>International Data Transfers</h1>
  <p>UK GDPR Chapter V restricts transfers of personal data outside the UK to countries that do not provide an equivalent level of protection. The following third-country transfers apply:</p>
  <table>
    <thead><tr><th>Transfer</th><th>Destination</th><th>Data</th><th>Legal Mechanism</th><th>Status</th></tr></thead>
    <tbody>
      <tr><td><strong>SMTP Provider</strong></td><td>United States (typically)</td><td>Name, email, booking reference</td><td>UK Adequacy Regulations (if applicable) or UK Addendum to EU SCCs</td><td><span class="badge badge-warn">Verify per provider</span></td></tr>
      <tr><td><strong>OpenAI (AI feature)</strong></td><td>United States</td><td>Session transcript excerpts — <strong>mental health PHI (Art. 9)</strong></td><td>OpenAI DPA + UK Addendum to EU SCCs. <strong>Gap: Schedule 1 §5 disclaims sensitive data.</strong></td><td><span class="badge badge-critical">Remediation Required</span></td></tr>
      <tr><td><strong>Stripe</strong></td><td>US / EU (customer choice)</td><td>Name, email, payment reference</td><td>Stripe DPA + SCCs; UK Addendum</td><td><span class="badge badge-ok">Standard DPA available</span></td></tr>
      <tr><td><strong>PayPal</strong></td><td>US / Luxembourg</td><td>Name, email, payment data</td><td>PayPal DPA; Luxembourg entity may satisfy adequacy for EU customers</td><td><span class="badge badge-ok">Standard DPA available</span></td></tr>
      <tr><td><strong>Square</strong></td><td>United States</td><td>Name, email, payment data</td><td>Square DPA + UK Addendum</td><td><span class="badge badge-warn">DPA required before activation</span></td></tr>
    </tbody>
  </table>

  <h2>OpenAI Transfer — Specific Analysis</h2>
  <div class="card">
    <div class="card-hdr"><h3>DPA Schedule 1 Conflict</h3><span class="badge badge-critical">Requires Remediation</span></div>
    <p>The OpenAI DPA uses the UK Addendum to EU Standard Contractual Clauses — the legal mechanism is correct. However, Schedule 1 §5 states: <em>"No sensitive data is intended to be transferred to the Importer as part of this DPA."</em> Sending therapeutic session transcripts directly contradicts this. Mental health content is Art. 9 special category data.</p>
    <div class="remedy">
      <strong>Option A (Recommended):</strong> Implement data minimisation before sending to OpenAI — strip identifying information, send only de-identified content fragments. Document in Schedule 1.<br><br>
      <strong>Option B:</strong> Negotiate an updated Schedule 1 with OpenAI describing the transfer of mental health session content. Requires an enterprise contract.<br><br>
      <strong>Option C:</strong> Do not offer AI features that send session content to OpenAI until the DPA gap is resolved. Use an alternative provider with a DPA that explicitly covers health data.
    </div>
  </div>
</section>

<!-- ══════ CONSULTATION ══════ -->
<section id="consultation">
  <div class="sec-title">Data Protection Impact Assessment</div>
  <h1>Consultation</h1>

  <h2>Data Subject Consultation</h2>
  <div class="card">
    <p>UK GDPR Art. 35(9) requires the controller to seek the views of data subjects or their representatives where appropriate. Data subjects for this platform are: clients (end users), practitioners, and guest session participants.</p>
    <p><strong>Platform-level consultation:</strong> This DPIA was prepared based on technical review of the platform and applicable data protection law. The open-source nature means any deploying operator must conduct their own consultation with their specific service users.</p>
    <p><strong>Deploying operator obligation:</strong> Before commencing services, operators should consult with representative clients where practicable. At minimum, a clear Privacy Notice satisfying Art. 13 must be provided at registration.</p>
    <div class="remedy"><strong>Required Action:</strong> Platform operators must maintain a Privacy Notice specific to their deployment context, client population, and any third parties they engage. The Privacy Notice template provided with this platform must be reviewed and customised before publication.</div>
  </div>

  <h2>Supervisory Authority Consultation</h2>
  <div class="card">
    <p>UK GDPR Art. 36 requires prior consultation with the ICO where the DPIA indicates processing that would result in a high residual risk that cannot be mitigated.</p>
    <p><strong>Pre-deployment state:</strong> Critical risks R-01 (plaintext PHI) and R-02 (indefinite retention) mean the platform in its current state should not be used to process live clinical PHI. These are unmitigated implementation gaps that must be remediated before any supervisory authority consultation makes sense.</p>
    <p><strong>Post-remediation state:</strong> Once R-01 and R-02 are addressed, and organisational measures are in place, the residual risk profile should be acceptable for a small-scale therapeutic support platform. Prior ICO consultation should not be required for standard deployments, but is recommended if processing will be large-scale or involve particularly vulnerable populations.</p>
    <p>Platform operators are advised to <strong>complete ICO registration</strong> before processing any personal data, and to consider submitting a voluntary prior consultation if serving particularly vulnerable populations (e.g., crisis intervention, youth mental health).</p>
  </div>
</section>

<!-- ══════ CONCLUSION & SIGN-OFF ══════ -->
<section id="conclusion">
  <div class="sec-title">Data Protection Impact Assessment</div>
  <h1>Conclusion &amp; Sign-off</h1>

  <div class="status-bar crit">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 2L2 13h12L8 2z"/><path d="M8 6v4M8 11.5v.5"/></svg>
    <span><strong>NOT READY FOR PRODUCTION WITH LIVE CLINICAL PHI.</strong> Two critical risks (R-01 plaintext PHI, R-02 indefinite retention) must be remediated before any therapeutic session data is processed.</span>
  </div>

  <h2>Required Pre-Production Actions</h2>
  <table>
    <thead><tr><th>#</th><th>Action</th><th>Risk</th><th>Priority</th></tr></thead>
    <tbody>
      <tr><td>1</td><td>Implement AES-256-GCM encryption for <code>messages.content</code> and <code>practitioner_notes.note_content</code> using env-variable key</td><td>R-01</td><td><span class="badge badge-critical">BLOCKER</span></td></tr>
      <tr><td>2</td><td>Implement post-export deletion of session data including voice note files</td><td>R-02</td><td><span class="badge badge-critical">BLOCKER</span></td></tr>
      <tr><td>3</td><td>Fix file upload extension bypass in <code>api/backgrounds.php</code> and <code>api/bookings.php</code></td><td>R-07</td><td><span class="badge badge-high">High</span></td></tr>
      <tr><td>4</td><td>Implement session inactivity timeout (15 min practitioner, 30 min client)</td><td>R-05</td><td><span class="badge badge-high">High</span></td></tr>
      <tr><td>5</td><td>Upload platform DPAs (hosting + SMTP) via Admin Panel</td><td>R-04 / Legal</td><td><span class="badge badge-high">High</span></td></tr>
      <tr><td>6</td><td>Resolve OpenAI DPA Schedule 1 gap before activating AI features</td><td>R-04</td><td><span class="badge badge-high">High</span></td></tr>
      <tr><td>7</td><td>Encrypt sensitive booking fields (guest_name, guest_email, DOB, gender, sexuality)</td><td>R-11</td><td><span class="badge badge-medium">Medium</span></td></tr>
      <tr><td>8</td><td>Extend audit log coverage (session.view, auth events, admin actions)</td><td>R-09</td><td><span class="badge badge-medium">Medium</span></td></tr>
      <tr><td>9</td><td>Authenticate presence endpoint</td><td>R-12</td><td><span class="badge badge-medium">Medium</span></td></tr>
      <tr><td>10</td><td>Publish Privacy Notice, document safeguarding policy, register with ICO</td><td>R-13 / Legal</td><td><span class="badge badge-high">Organisational</span></td></tr>
    </tbody>
  </table>

  <h2>Residual Risk — Post-Remediation Estimate</h2>
  <p>If items 1–6 above are implemented, the residual risk profile is estimated as: <strong>No Critical risks</strong>, 2 High (join_token URL, incomplete audit), 3 Medium, 2 Low. This is an acceptable profile for a small-scale therapeutic platform with appropriate organisational controls.</p>

  <h2>Review Schedule</h2>
  <div class="card">
    <p>This DPIA must be reviewed and updated:</p>
    <ul>
      <li>Annually — next scheduled review: <?= $nextReview ? htmlspecialchars($nextReview) : '3 May 2027' ?></li>
      <li>On any material change to processing activities (new data categories, new processors, new PHI features)</li>
      <li>Following any personal data breach</li>
      <li>If the supervisory authority issues new guidance affecting this type of processing</li>
      <li>When required remediation items above are completed — update status using sign-off below</li>
    </ul>
  </div>

  <h2>Sign-off</h2>
  <p style="font-size:13px;color:var(--muted);margin-bottom:8px;">The controller accepts responsibility for implementing the required mitigations and maintaining this assessment in accordance with UK GDPR Art. 35.</p>

  <?php if ($success): ?>
  <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="signoff-form">
    <h3>Record Sign-off / Review</h3>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action" value="dpia_signoff">
      <div class="form-row">
        <div class="form-group">
          <label>Reviewer Name *</label>
          <input type="text" name="signoff_name" value="<?= htmlspecialchars($me['display_name'] ?? '') ?>" placeholder="Full name" required>
        </div>
        <div class="form-group">
          <label>Role / Title</label>
          <input type="text" name="signoff_role" placeholder="e.g. Data Controller, DPO">
        </div>
        <div class="form-group">
          <label>DPIA Version</label>
          <input type="text" name="signoff_version" value="1.0" placeholder="1.0">
        </div>
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label>Notes / Change Summary (optional)</label>
        <textarea name="signoff_notes" placeholder="e.g. Annual review — R-01 and R-02 now remediated. Updated residual risk to High only."></textarea>
      </div>
      <button type="submit" class="btn-sign">Record Sign-off &amp; Review Date</button>
    </form>
  </div>

  <div class="review-history">
    <h3>Sign-off History</h3>
    <?php if (empty($reviews)): ?>
    <div class="no-reviews">No sign-offs recorded yet. This DPIA has not been formally signed off by the controller.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Reviewed By</th>
          <th>Role</th>
          <th>Version</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reviews as $rev): ?>
        <tr>
          <td><?= htmlspecialchars(date('d M Y H:i', strtotime($rev['reviewed_at']))) ?></td>
          <td><?= htmlspecialchars($rev['reviewed_by']) ?></td>
          <td><?= htmlspecialchars($rev['role'] ?? '—') ?></td>
          <td><?= htmlspecialchars($rev['dpia_version']) ?></td>
          <td style="color:var(--muted);font-size:12px;"><?= nl2br(htmlspecialchars($rev['notes'] ?? '—')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p style="font-size:11px;color:var(--muted);margin-top:8px;font-family:'Space Mono',monospace;">Document ID: SS-DPIA-2026-001 · Controller: <?= htmlspecialchars($controllerDisplay) ?> · Platform: <?= htmlspecialchars($appName) ?></p>
    <?php endif; ?>
  </div>
</section>

</div><!-- #content -->

<script nonce="<?= $nonce ?>">
function show(id) {
  document.querySelectorAll('section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.sb-item').forEach(a => a.classList.remove('active'));
  const sec = document.getElementById(id);
  if (sec) sec.classList.add('active');
  document.querySelectorAll('.sb-item[data-section="' + id + '"]').forEach(a => a.classList.add('active'));
  window.scrollTo(0, 0);
}

document.addEventListener('click', function (e) {
  const target = e.target.closest('[data-section]');
  if (!target) return;
  e.preventDefault();
  show(target.dataset.section);
});

<?php if ($success): ?>
show('conclusion');
<?php endif; ?>
</script>
</body>
</html>
