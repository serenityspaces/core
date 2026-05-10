<?php
/**
 * Serenity Spaces — Password Policy
 *
 * HIPAA-aligned password requirements:
 *   Practitioners (PHI access): 12+ chars, upper, lower, digit, special
 *   Clients (end_users):        10+ chars, upper, lower, digit
 *
 * Strength meter scale:
 *   0–59%  below client minimum
 *   60%    client threshold
 *   70%    practitioner threshold (required for PHI access)
 *   85–100 excellent
 */

/**
 * Hash a password using the strongest algorithm available on this PHP build.
 * PHP 7.2+ with the sodium extension: Argon2id (recommended).
 * Older PHP or missing extension: bcrypt with cost 14.
 * All hashes produced by this function are verifiable by ss_password_verify().
 */
function ss_password_hash(string $password): string
{
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,  // 64 MB
            'time_cost'   => 4,
            'threads'     => 1,
        ]);
    }
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 14]);
}

/**
 * Verify a password against a hash produced by any algorithm.
 * password_verify() is algorithm-agnostic — it reads the hash prefix.
 */
function ss_password_verify(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

/**
 * Returns true if the stored hash should be upgraded on next successful login.
 * Detects old bcrypt hashes when Argon2id is now available, or low-cost bcrypt.
 */
function ss_password_needs_rehash(string $hash): bool
{
    if (defined('PASSWORD_ARGON2ID')) {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost'   => 4,
            'threads'     => 1,
        ]);
    }
    return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 14]);
}

function validate_practitioner_password(string $pass): ?string
{
    if (strlen($pass) < 12)
        return 'Password must be at least 12 characters.';
    if (!preg_match('/[A-Z]/', $pass))
        return 'Password must include at least one uppercase letter.';
    if (!preg_match('/[a-z]/', $pass))
        return 'Password must include at least one lowercase letter.';
    if (!preg_match('/[0-9]/', $pass))
        return 'Password must include at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $pass))
        return 'Password must include at least one special character (e.g. !@#$%).';
    return null;
}

function validate_client_password(string $pass): ?string
{
    if (strlen($pass) < 10)
        return 'Password must be at least 10 characters.';
    if (!preg_match('/[A-Z]/', $pass))
        return 'Password must include at least one uppercase letter.';
    if (!preg_match('/[a-z]/', $pass))
        return 'Password must include at least one lowercase letter.';
    if (!preg_match('/[0-9]/', $pass))
        return 'Password must include at least one number.';
    return null;
}

/**
 * Returns the inner JS for the strength meter (without <script> tags).
 * Used internally by password_meter().
 */
function password_strength_js(string $inputId, string $barId, string $labelId, bool $isPractitioner = true): string
{
    return <<<JS
(function(){
  var inp = document.getElementById('{$inputId}');
  var bar = document.getElementById('{$barId}');
  var lbl = document.getElementById('{$labelId}');
  if (!inp || !bar) return;
  function score(v) {
    if (!v) return 0;
    var len  = v.length;
    var hasL = /[a-z]/.test(v);
    var hasU = /[A-Z]/.test(v);
    var hasD = /[0-9]/.test(v);
    var hasS = /[^A-Za-z0-9]/.test(v);
    var uniq = (new Set(v.split(''))).size;
    var s = 0;
    // Length (max 40)
    s += Math.min(Math.round(len * 1.5), 16);
    if (len >= 10) s += 6;
    if (len >= 12) s += 6;
    if (len >= 16) s += 6;
    if (len >= 20) s += 6;
    // Variety (max 40)
    if (hasL) s += 10;
    if (hasU) s += 10;
    if (hasD) s += 10;
    if (hasS) s += 10;
    // Uniqueness (max 20)
    if (uniq >= 6)  s += 5;
    if (uniq >= 10) s += 5;
    if (uniq >= 14) s += 5;
    if (uniq >= 18) s += 5;
    return Math.min(s, 100);
  }
  function col(s) {
    if (s < 40) return '#e05555';
    if (s < 60) return '#e07a30';
    if (s < 70) return '#d4b429';
    if (s < 85) return '#4caf50';
    return '#2e9e4e';
  }
  function lbl_text(s) {
    if (s < 40) return 'Very weak';
    if (s < 60) return 'Weak';
    if (s < 70) return 'Fair';
    if (s < 85) return 'Strong';
    return 'Excellent';
  }
  inp.addEventListener('input', function(){
    var v = this.value;
    if (!v) {
      bar.style.width = '0';
      if (lbl) { lbl.textContent = ''; lbl.style.color = ''; }
      var pct = document.getElementById('{$inputId}-mpct');
      if (pct) pct.textContent = '';
      return;
    }
    var s = score(v);
    var c = col(s);
    bar.style.width      = s + '%';
    bar.style.background = c;
    if (lbl) { lbl.textContent = lbl_text(s); lbl.style.color = c; }
    var pct = document.getElementById('{$inputId}-mpct');
    if (pct) { pct.textContent = s + '%'; pct.style.color = c; }
  });
})();
JS;
}

/**
 * Returns complete HTML + inline <script> for a password strength meter.
 * Drop this immediately after the <input type="password"> element.
 *
 * The meter shows:
 *   – A fill bar with tick marks at 60% (client min) and 70% (practitioner min)
 *   – Quality label + percentage on the right
 *   – Requirement hint text below
 *
 * @param string $inputId       The id of the password <input>
 * @param bool   $isPractitioner true = practitioner rules (12+, upper, lower, digit, special)
 *                               false = client rules (10+, upper, lower, digit)
 */
function password_meter(string $inputId, bool $isPractitioner = true): string
{
    $barId  = $inputId . '-mbar';
    $lblId  = $inputId . '-mlbl';
    $pctId  = $inputId . '-mpct';
    $req    = $isPractitioner
        ? 'Min. 12 characters &nbsp;·&nbsp; uppercase &nbsp;·&nbsp; lowercase &nbsp;·&nbsp; number &nbsp;·&nbsp; special character'
        : 'Min. 10 characters &nbsp;·&nbsp; uppercase &nbsp;·&nbsp; lowercase &nbsp;·&nbsp; number';
    $practTip = $isPractitioner
        ? ' title="Practitioner minimum — 70%"'
        : ' title="Client minimum — 60%"';

    $js    = password_strength_js($inputId, $barId, $lblId, $isPractitioner);
    $nonce = $GLOBALS['csp_nonce'] ?? '';

    return <<<HTML
<div class="pw-meter">
  <div class="pw-meter-track">
    <div class="pw-meter-fill" id="{$barId}"></div>
    <div class="pw-meter-tick" style="left:60%" title="Client minimum — 60%"></div>
    <div class="pw-meter-tick pw-tick-pract" style="left:70%"{$practTip}></div>
  </div>
  <div class="pw-meter-row">
    <span class="pw-meter-lbl" id="{$lblId}"></span>
    <span class="pw-meter-pct" id="{$pctId}"></span>
  </div>
  <div class="pw-req-text">{$req}</div>
</div>
<script nonce="{$nonce}">{$js}</script>
HTML;
}
