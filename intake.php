<?php
/**
 * Serenity Spaces — Client Intake Form
 * Public page. No authentication required. Uses intake_token from URL.
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/includes/security_headers.php';
requireSetup();

$pdo   = getDB();
$token = trim($_GET['token'] ?? '');

$formData = null;
$error    = null;

if (!$token) {
    $error = 'No form token provided. Please use the link from your email.';
} else {
    $stmt = $pdo->prepare(
        'SELECT b.id AS booking_id, b.intake_form_id, b.guest_name,
                f.name AS form_name, f.fields, f.practitioner_id,
                p.display_name AS practitioner_name
         FROM bookings b
         JOIN intake_forms f ON f.id = b.intake_form_id
         JOIN practitioners p ON p.id = f.practitioner_id
         WHERE b.intake_token = ? LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        $error = 'This form link is not valid or has expired.';
    } else {
        // Check if already submitted
        $rCheck = $pdo->prepare('SELECT id FROM intake_responses WHERE booking_id = ? LIMIT 1');
        $rCheck->execute([$row['booking_id']]);
        if ($rCheck->fetch()) {
            $error = 'submitted';
        } else {
            require_once __DIR__ . '/includes/phi_crypto.php';
            $formData = [
                'booking_id'        => (int)$row['booking_id'],
                'form_id'           => (int)$row['intake_form_id'],
                'form_name'         => $row['form_name'],
                'practitioner_name' => $row['practitioner_name'],
                'guest_name'        => phi_decrypt($row['guest_name'] ?? ''),
                'fields'            => json_decode($row['fields'] ?? '[]', true) ?: [],
            ];
        }
    }
}

$appName = 'Serenity Spaces';
try {
    $appName = getSetting('app_name', 'Serenity Spaces');
} catch (Throwable $e) { error_log('intake: could not load app_name setting: ' . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Intake Form — <?= htmlspecialchars($appName) ?></title>
  <link rel="stylesheet" href="/assets/css/main.css">
  <link rel="stylesheet" href="/assets/css/fonts.css">
  <style nonce="<?= $GLOBALS['csp_nonce'] ?>">
    body { min-height: 100vh; background: var(--bg, #060710); display: flex; flex-direction: column; align-items: center; padding: 48px 16px 80px; }
    .intake-wrap  { width: 100%; max-width: 640px; }
    .intake-header { text-align: center; margin-bottom: 36px; }
    .intake-logo  { font-size: 18px; font-weight: 700; color: var(--accent, #7c6af7); letter-spacing: .04em; margin-bottom: 20px; }
    .intake-title { font-size: 22px; font-weight: 700; color: var(--text, #e8e6f4); margin-bottom: 6px; }
    .intake-sub   { font-size: 13.5px; color: var(--text-muted, rgba(200,198,230,.55)); }
    .intake-panel { background: var(--surface, rgba(255,255,255,.04)); border: 1px solid var(--border, rgba(255,255,255,.08)); border-radius: 14px; padding: 28px 32px; margin-bottom: 16px; }
    .intake-field { margin-bottom: 22px; }
    .intake-field:last-child { margin-bottom: 0; }
    .intake-label { display: block; font-size: 13.5px; font-weight: 600; color: var(--text, #e8e6f4); margin-bottom: 8px; }
    .intake-req   { color: var(--accent, #7c6af7); margin-left: 2px; }
    .intake-input, .intake-textarea, .intake-select {
      width: 100%; background: var(--surface2, rgba(255,255,255,.05)); border: 1px solid var(--border, rgba(255,255,255,.1));
      border-radius: 8px; padding: 10px 14px; color: var(--text, #e8e6f4); font-size: 14px; font-family: inherit; outline: none;
      transition: border-color .15s; box-sizing: border-box;
    }
    .intake-input:focus, .intake-textarea:focus, .intake-select:focus { border-color: var(--accent, #7c6af7); }
    .intake-textarea { resize: vertical; min-height: 100px; }
    .intake-select option { background: #1a1b2e; color: #e8e6f4; }
    .intake-radio-group, .intake-check-wrap { display: flex; flex-direction: column; gap: 8px; }
    .intake-radio-item, .intake-check-item { display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--text, #e8e6f4); cursor: pointer; }
    .intake-radio-item input, .intake-check-item input { accent-color: var(--accent, #7c6af7); width: 16px; height: 16px; cursor: pointer; }
    .intake-submit { width: 100%; padding: 13px; background: var(--accent, #7c6af7); color: #fff; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; transition: opacity .15s; margin-top: 8px; }
    .intake-submit:hover { opacity: .88; }
    .intake-submit:disabled { opacity: .5; cursor: not-allowed; }
    .intake-error { background: rgba(224,92,92,.1); border: 1px solid rgba(224,92,92,.25); border-radius: 8px; padding: 12px 16px; color: #e05c5c; font-size: 13.5px; margin-bottom: 20px; }
    .intake-success { text-align: center; padding: 48px 24px; }
    .intake-success-icon { font-size: 48px; margin-bottom: 16px; }
    .intake-success-title { font-size: 22px; font-weight: 700; color: var(--text, #e8e6f4); margin-bottom: 8px; }
    .intake-success-sub { font-size: 14px; color: var(--text-muted, rgba(200,198,230,.55)); }
    .intake-meta-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; font-size: 12px; color: var(--text-muted, rgba(200,198,230,.45)); }
    .intake-save-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: rgba(124,106,247,0.4); margin-right: 5px; vertical-align: middle; transition: background .3s; }
    .intake-save-dot.saving { background: #7c6af7; }
  </style>
</head>
<body>

<div class="intake-wrap">
  <div class="intake-header">
    <div class="intake-logo"><?= htmlspecialchars($appName) ?></div>
    <?php if ($error === 'submitted'): ?>
      <div class="intake-title">Form Already Submitted</div>
      <div class="intake-sub">Your practitioner has received your responses.</div>
    <?php elseif ($error): ?>
      <div class="intake-title">Form Unavailable</div>
      <div class="intake-sub"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($formData): ?>
      <div class="intake-title"><?= htmlspecialchars($formData['form_name']) ?></div>
      <div class="intake-sub">
        Sent by <strong><?= htmlspecialchars($formData['practitioner_name']) ?></strong>
        <?php if ($formData['guest_name']): ?> · For <?= htmlspecialchars($formData['guest_name']) ?><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($error === 'submitted'): ?>
    <div class="intake-panel" style="text-align:center;">
      <div style="font-size:36px;margin-bottom:12px;">✓</div>
      <p style="color:var(--text-muted);font-size:14px;">Your responses have already been received. No further action is needed.</p>
    </div>

  <?php elseif ($error): ?>
    <div class="intake-error"><?= htmlspecialchars($error) ?></div>

  <?php elseif ($formData): ?>
    <div id="intake-form-wrap">
      <div class="intake-meta-bar">
        <span id="intake-counter" style="font-variant-numeric:tabular-nums;"></span>
        <span><span class="intake-save-dot" id="intake-save-dot"></span><span id="intake-save-label">Draft saved</span></span>
      </div>
      <div id="intake-error-msg" class="intake-error" style="display:none;"></div>
      <form id="intake-form">
        <div class="intake-panel">
          <?php foreach ($formData['fields'] as $field):
            $fid      = htmlspecialchars($field['id'] ?? '');
            $type     = $field['type'] ?? 'text';
            $label    = htmlspecialchars($field['label'] ?? '');
            $required = !empty($field['required']);
            $options  = $field['options'] ?? [];
          ?>
          <div class="intake-field">
            <label class="intake-label" for="field_<?= $fid ?>">
              <?= $label ?>
              <?php if ($required): ?><span class="intake-req">*</span><?php endif; ?>
            </label>

            <?php if ($type === 'text'): ?>
              <input type="text" id="field_<?= $fid ?>" name="<?= $fid ?>" class="intake-input"
                     <?= $required ? 'required' : '' ?>>

            <?php elseif ($type === 'textarea'): ?>
              <textarea id="field_<?= $fid ?>" name="<?= $fid ?>" class="intake-textarea"
                        <?= $required ? 'required' : '' ?>></textarea>

            <?php elseif ($type === 'select'): ?>
              <select id="field_<?= $fid ?>" name="<?= $fid ?>" class="intake-select" <?= $required ? 'required' : '' ?>>
                <option value="">— Select an option —</option>
                <?php foreach ($options as $opt): ?>
                  <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
              </select>

            <?php elseif ($type === 'radio'): ?>
              <div class="intake-radio-group" role="group">
                <?php foreach ($options as $i => $opt): ?>
                  <label class="intake-radio-item">
                    <input type="radio" name="<?= $fid ?>" value="<?= htmlspecialchars($opt) ?>"
                           <?= ($required && $i === 0) ? '' : '' ?>>
                    <?= htmlspecialchars($opt) ?>
                  </label>
                <?php endforeach; ?>
              </div>

            <?php elseif ($type === 'checkbox'): ?>
              <div class="intake-check-wrap">
                <label class="intake-check-item">
                  <input type="checkbox" name="<?= $fid ?>" id="field_<?= $fid ?>"
                         value="yes" <?= $required ? 'required' : '' ?>>
                  Yes
                </label>
              </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <button type="submit" class="intake-submit" id="intake-submit-btn">
          Submit Form
        </button>
      </form>

      <div id="intake-success" style="display:none;" class="intake-panel">
        <div class="intake-success">
          <div class="intake-success-icon">✓</div>
          <div class="intake-success-title">Form Submitted</div>
          <div class="intake-success-sub">Your responses have been sent to your practitioner. You can close this page.</div>
        </div>
      </div>
    </div>

    <script nonce="<?= $GLOBALS['csp_nonce'] ?? '' ?>">
    const INTAKE_BOOKING_ID = <?= $formData['booking_id'] ?>;
    const INTAKE_FORM_ID    = <?= $formData['form_id'] ?>;
    const INTAKE_TOKEN      = <?= json_encode($token) ?>;
    const INTAKE_FIELDS     = <?= json_encode($formData['fields']) ?>;
    const DRAFT_KEY         = 'intake_draft_' + INTAKE_TOKEN;
    const TOTAL_FIELDS      = INTAKE_FIELDS.length;

    // ── Question counter ──────────────────────────────────────────
    function updateCounter() {
        const counter = document.getElementById('intake-counter');
        if (!counter || !TOTAL_FIELDS) return;
        let answered = 0;
        for (const field of INTAKE_FIELDS) {
            const fid  = field.id;
            const type = field.type;
            if (type === 'text' || type === 'textarea') {
                const el = document.querySelector(`[name="${fid}"]`);
                if (el && el.value.trim()) answered++;
            } else if (type === 'select') {
                const el = document.querySelector(`select[name="${fid}"]`);
                if (el && el.value) answered++;
            } else if (type === 'radio') {
                const el = document.querySelector(`input[name="${fid}"]:checked`);
                if (el) answered++;
            } else if (type === 'checkbox') {
                answered++; // checkbox fields have no "unanswered" state
            }
        }
        counter.textContent = `${answered} / ${TOTAL_FIELDS} answered`;
    }

    // ── Draft helpers ─────────────────────────────────────────────
    function collectResponses() {
        const r = {};
        for (const field of INTAKE_FIELDS) {
            const fid  = field.id;
            const type = field.type;
            if (type === 'text' || type === 'textarea') {
                const el = document.querySelector(`[name="${fid}"]`);
                r[fid] = el ? el.value : '';
            } else if (type === 'select') {
                const el = document.querySelector(`select[name="${fid}"]`);
                r[fid] = el ? el.value : '';
            } else if (type === 'radio') {
                const el = document.querySelector(`input[name="${fid}"]:checked`);
                r[fid] = el ? el.value : '';
            } else if (type === 'checkbox') {
                const el = document.querySelector(`input[name="${fid}"]`);
                r[fid] = el ? el.checked : false;
            }
        }
        return r;
    }

    function saveDraft() {
        try {
            localStorage.setItem(DRAFT_KEY, JSON.stringify(collectResponses()));
            const dot   = document.getElementById('intake-save-dot');
            const label = document.getElementById('intake-save-label');
            if (dot) { dot.classList.add('saving'); setTimeout(() => dot.classList.remove('saving'), 600); }
            if (label) label.textContent = 'Draft saved';
        } catch(e) {}
    }

    function restoreDraft() {
        try {
            const raw = localStorage.getItem(DRAFT_KEY);
            if (!raw) return;
            const saved = JSON.parse(raw);
            for (const field of INTAKE_FIELDS) {
                const fid  = field.id;
                const type = field.type;
                const val  = saved[fid];
                if (val === undefined || val === null) continue;
                if (type === 'text' || type === 'textarea') {
                    const el = document.querySelector(`[name="${fid}"]`);
                    if (el) el.value = val;
                } else if (type === 'select') {
                    const el = document.querySelector(`select[name="${fid}"]`);
                    if (el) el.value = val;
                } else if (type === 'radio' && val) {
                    const el = document.querySelector(`input[name="${fid}"][value="${CSS.escape(val)}"]`);
                    if (el) el.checked = true;
                } else if (type === 'checkbox') {
                    const el = document.querySelector(`input[name="${fid}"]`);
                    if (el) el.checked = !!val;
                }
            }
            const label = document.getElementById('intake-save-label');
            if (label) label.textContent = 'Draft restored';
        } catch(e) {}
    }

    // ── Wire up autosave on every input change ────────────────────
    let saveTimer = null;
    let hasUnsavedChanges = false;
    document.getElementById('intake-form')?.addEventListener('input', () => {
        hasUnsavedChanges = true;
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => { saveDraft(); updateCounter(); }, 800);
    });
    document.getElementById('intake-form')?.addEventListener('change', () => {
        hasUnsavedChanges = true;
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => { saveDraft(); updateCounter(); }, 200);
    });

    document.getElementById('intake-form')?.addEventListener('submit', function(e) {
        submitIntake(e);
    });

    async function submitIntake(e) {
        e.preventDefault();
        const errEl  = document.getElementById('intake-error-msg');
        const btn    = document.getElementById('intake-submit-btn');
        errEl.style.display = 'none';
        btn.disabled = true;
        btn.textContent = 'Submitting…';

        const responses = collectResponses();

        try {
            const res  = await fetch('/api/intake.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'submit', token: INTAKE_TOKEN, responses }),
            });
            const data = await res.json();
            if (data.ok) {
                hasUnsavedChanges = false;
                try { localStorage.removeItem(DRAFT_KEY); } catch(e) {}
                document.getElementById('intake-form').style.display = 'none';
                document.getElementById('intake-success').style.display = 'block';
            } else {
                errEl.textContent = data.error || 'Could not submit. Please try again.';
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Submit Form';
            }
        } catch(err) {
            errEl.textContent = 'Network error. Please try again.';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Submit Form';
        }
    }

    // Warn before tab close if form has unsaved changes
    window.addEventListener('beforeunload', e => {
        if (hasUnsavedChanges) { e.preventDefault(); }
    });

    // Restore draft and update counter on load
    restoreDraft();
    updateCounter();
    </script>
  <?php endif; ?>
</div>

</body>
</html>
