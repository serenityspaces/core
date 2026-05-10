<?php
/**
 * ROPA Block Functions — SerenitySpaces
 *
 * Each function returns an HTML string for a jurisdiction-specific section
 * of the Record of Processing Activities (ROPA) page.
 *
 * CSS classes assumed present from ropa.php inline style:
 *   .ropa-section .ropa-section-label h2 table thead th tbody td
 *   .basis-tag .basis-6b .basis-6c .basis-6f .basis-9a
 *   .notice-box .ropa-footer
 *
 * All user-supplied context values must be passed via $ctx and are
 * htmlspecialchars()-escaped inside each function.
 */

if (!defined('ROPA_BLOCKS_INCLUDED')) {
    define('ROPA_BLOCKS_INCLUDED', true);

    // ──────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────

    function _ropa_section(string $label, string $inner): string {
        return '<div class="ropa-section">'
             . '<div class="ropa-section-label">' . htmlspecialchars($label) . '</div>'
             . $inner
             . '</div>';
    }

    function _ropa_controller_block(array $ctx): string {
        $name  = $ctx['controller_display'];
        $addr  = $ctx['controller_address'];
        $email = $ctx['contact_line'];
        $addrLine = $addr ? '<br>' . htmlspecialchars($addr) : '';
        $out = '<p style="font-size:13px;color:rgba(200,210,255,0.65);line-height:1.7;margin-bottom:10px;">'
             . '<strong style="color:#c8d2ff;">Controller:</strong> ' . htmlspecialchars($name) . $addrLine . '<br>'
             . '<strong style="color:#c8d2ff;">Contact:</strong> ' . $email;
        if ($ctx['dpo_name']) {
            $dpoContact = $ctx['dpo_email']
                ? '<a href="mailto:' . htmlspecialchars($ctx['dpo_email']) . '" style="color:rgba(196,184,255,0.8);">'
                  . htmlspecialchars($ctx['dpo_email']) . '</a>'
                : 'available on request';
            $out .= '<br><strong style="color:#c8d2ff;">DPO:</strong> '
                  . htmlspecialchars($ctx['dpo_name']) . ' — ' . $dpoContact;
        }
        $out .= '</p>';
        return $out;
    }

    function _ropa_basis(string $class, string $label): string {
        return '<span class="basis-tag ' . htmlspecialchars($class) . '">' . htmlspecialchars($label) . '</span>';
    }

    // Shared processing activities table (used by EU, UK, and US blocks with minor variations)
    function _ropa_activities_table(string $legalFramework): string {
        $isHipaa = ($legalFramework === 'hipaa');
        $basisLabel = $isHipaa ? 'HIPAA Basis' : 'Legal Basis';
        ob_start(); ?>
<table>
  <thead>
    <tr>
      <th style="min-width:160px;">Activity</th>
      <th style="min-width:130px;">Data Subjects</th>
      <th style="min-width:180px;">Categories of Data</th>
      <th style="min-width:120px;"><?= htmlspecialchars($basisLabel) ?></th>
      <th style="min-width:140px;">Recipients / Processors</th>
      <th style="min-width:100px;">Transfers</th>
      <th>Retention</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><strong>Account registration &amp; authentication</strong></td>
      <td>Practitioners, registered clients</td>
      <td>Email, display name, hashed password, avatar image, account status, IP address (login)</td>
      <td><?= $isHipaa
          ? _ropa_basis('basis-6b', 'Healthcare Ops')
          : _ropa_basis('basis-6b', 'Art. 6(1)(b) — Contract') ?></td>
      <td>Hosting provider (processor). No marketing recipients.</td>
      <td>See infrastructure note</td>
      <td>Active account + 30 days after closure</td>
    </tr>
    <tr>
      <td><strong>Session booking</strong></td>
      <td>Clients booking sessions</td>
      <td>Name, email, date of birth, gender, sexuality, location, consent records — all encrypted at rest</td>
      <td>
        <?= $isHipaa
            ? _ropa_basis('basis-6b', 'Treatment / Ops')
            : _ropa_basis('basis-6b', 'Art. 6(1)(b) — Contract') ?>
        <?= $isHipaa
            ? ' ' . _ropa_basis('basis-9a', 'HIPAA Auth')
            : ' ' . _ropa_basis('basis-9a', 'Art. 9(2)(a) — Consent') ?>
        (for gender / sexuality)
      </td>
      <td>Practitioner. Hosting provider. Payment processor (payment data only).</td>
      <td>See infrastructure note</td>
      <td>7 years from last session</td>
    </tr>
    <tr>
      <td><strong>Therapeutic session delivery</strong></td>
      <td>Session participants (practitioners, clients, guests)</td>
      <td>Chat messages, clinical notes, client reflection notes, highlights, voice notes, file attachments, session metadata — messages and notes encrypted at rest</td>
      <td>
        <?= $isHipaa
            ? _ropa_basis('basis-6b', 'Treatment')
            : _ropa_basis('basis-6b', 'Art. 6(1)(b) — Contract') ?>
        <?= $isHipaa
            ? ' ' . _ropa_basis('basis-9a', 'HIPAA Auth')
            : ' ' . _ropa_basis('basis-9a', 'Art. 9(2)(a) — Consent') ?>
        (health / mental health content)
      </td>
      <td>Practitioner. Hosting provider. AI provider (if AI enabled and consented).</td>
      <td>See infrastructure note</td>
      <td>7 years from session date</td>
    </tr>
    <tr>
      <td><strong>Intake form responses</strong></td>
      <td>Clients completing pre-session intake</td>
      <td>Responses to practitioner-configured questionnaires (may include health history, goals, clinical information)</td>
      <td>
        <?= $isHipaa
            ? _ropa_basis('basis-6b', 'Treatment')
            : _ropa_basis('basis-6b', 'Art. 6(1)(b) — Contract') ?>
        <?= $isHipaa
            ? ' ' . _ropa_basis('basis-9a', 'HIPAA Auth')
            : ' ' . _ropa_basis('basis-9a', 'Art. 9(2)(a) — Consent') ?>
      </td>
      <td>Practitioner. Hosting provider.</td>
      <td>See infrastructure note</td>
      <td>7 years from last session</td>
    </tr>
    <tr>
      <td><strong>Goal and progress tracking</strong></td>
      <td>Clients with active goals</td>
      <td>Goals set by practitioner, milestones, progress notes</td>
      <td>
        <?= $isHipaa
            ? _ropa_basis('basis-6b', 'Treatment')
            : _ropa_basis('basis-6b', 'Art. 6(1)(b) — Contract') ?>
      </td>
      <td>Practitioner. Hosting provider.</td>
      <td>See infrastructure note</td>
      <td>7 years from last session</td>
    </tr>
    <tr>
      <td><strong>Session export / PDF generation</strong></td>
      <td>Session participants</td>
      <td>All session content (messages, notes, highlights) — included in PDF export</td>
      <td>
        <?= $isHipaa
            ? _ropa_basis('basis-6b', 'Healthcare Ops')
            : _ropa_basis('basis-6b', 'Art. 6(1)(b) — Contract') ?>
      </td>
      <td>Practitioner (export recipient). Hosting provider (PDF generation).</td>
      <td>None (export stored locally by practitioner)</td>
      <td>Source records: 7 years. Export file: under practitioner control.</td>
    </tr>
    <tr>
      <td><strong>Payment processing</strong></td>
      <td>Clients paying for sessions</td>
      <td>Session payment amount, currency, method type, payment status. No card data stored on-platform.</td>
      <td>
        <?= $isHipaa
            ? _ropa_basis('basis-6b', 'Payment Ops')
            : _ropa_basis('basis-6b', 'Art. 6(1)(b) — Contract') ?>
      </td>
      <td>Payment processor (PayPal / Stripe / Square — controller decides). Hosting provider.</td>
      <td>Payment processor's own infrastructure (may be international)</td>
      <td>7 years (financial records)</td>
    </tr>
    <tr>
      <td><strong>AI assistant integration</strong></td>
      <td>Session participants (where AI enabled)</td>
      <td>Session chat messages sent to AI provider's API for in-session assistance or summarisation. Controlled by practitioner configuration.</td>
      <td>
        <?= $isHipaa
            ? _ropa_basis('basis-9a', 'HIPAA Auth')
            : _ropa_basis('basis-9a', 'Art. 9(2)(a) — Consent') ?>
        (explicit consent at booking required before enabling)
      </td>
      <td>AI vendor (OpenAI / Anthropic / Google / Cohere — practitioner-configured). BAA/DPA required.</td>
      <td>AI vendor's infrastructure (may be outside EU/UK)</td>
      <td>As per AI vendor's data retention terms; not retained on-platform after API call</td>
    </tr>
    <tr>
      <td><strong>Email notifications</strong></td>
      <td>Practitioners and clients (where email enabled)</td>
      <td>Email address, display name, notification content</td>
      <td>
        <?= $isHipaa
            ? _ropa_basis('basis-6b', 'Healthcare Ops')
            : _ropa_basis('basis-6b', 'Art. 6(1)(b) — Contract') ?>
      </td>
      <td>SMTP provider (controller-configured). BAA/DPA required.</td>
      <td>SMTP provider's infrastructure (may be international)</td>
      <td>Not retained on-platform. SMTP logs per provider terms.</td>
    </tr>
    <tr>
      <td><strong>Security, fraud prevention &amp; audit logging</strong></td>
      <td>All users</td>
      <td>IP address, user agent, action type, resource identifier, timestamp, HMAC chain hash</td>
      <td>
        <?= $isHipaa
            ? _ropa_basis('basis-6c', 'HIPAA Security Rule')
            : _ropa_basis('basis-6f', 'Art. 6(1)(f) — Legitimate Interests') ?>
        <?= !$isHipaa ? ' ' . _ropa_basis('basis-6c', 'Art. 6(1)(c) — Legal Obligation') : '' ?>
      </td>
      <td>Hosting provider. No third-party sharing for security purposes.</td>
      <td>See infrastructure note</td>
      <td>Minimum 12 months (HIPAA Security Rule minimum: 6 years for HIPAA-related records)</td>
    </tr>
    <tr>
      <td><strong>GDPR / data subject request handling</strong></td>
      <td>Any data subject exercising rights</td>
      <td>Request details, identity verification, correspondence</td>
      <td>
        <?= $isHipaa
            ? _ropa_basis('basis-6c', 'Legal Obligation')
            : _ropa_basis('basis-6c', 'Art. 6(1)(c) — Legal Obligation') ?>
      </td>
      <td>Internal. No third-party processors for rights-request handling.</td>
      <td>None</td>
      <td>3 years from resolution (accountability record)</td>
    </tr>
    <tr>
      <td><strong>Cross-datacenter transfer (client-initiated)</strong></td>
      <td>Clients requesting regional transfer</td>
      <td>All data associated with the client account</td>
      <td>
        <?= $isHipaa
            ? _ropa_basis('basis-9a', 'HIPAA Auth')
            : _ropa_basis('basis-9a', 'Art. 49(1)(a) — Explicit Consent') ?>
      </td>
      <td>Destination datacenter operator. Source datacenter operator.</td>
      <td>International transfer (consent-based)</td>
      <td>Source copy deleted upon confirmed transfer completion</td>
    </tr>
  </tbody>
</table>
        <?php return ob_get_clean();
    }


    // ──────────────────────────────────────────────────────────────
    // EU / EEA — Article 30 GDPR Record
    // ──────────────────────────────────────────────────────────────

    function ropa_block_eu(array $ctx): string {
        $app = htmlspecialchars($ctx['app_name']);
        ob_start(); ?>

<div class="ropa-section">
  <div class="ropa-section-label">EU / EEA — GDPR Article 30 Record of Processing Activities</div>
  <h2>Record of Processing Activities — EU GDPR</h2>
  <p style="font-size:13px;color:rgba(200,210,255,0.55);line-height:1.7;margin-bottom:16px;">
    Maintained under Article 30(1) of Regulation (EU) 2016/679. This record must be made available to the supervisory authority on request.
  </p>

  <?= _ropa_controller_block($ctx) ?>

  <div class="notice-box" style="background:rgba(124,106,247,0.05);border-color:rgba(124,106,247,0.2);color:rgba(196,184,255,0.75);">
    <strong>Legal basis tag guide:</strong>&nbsp;
    <span class="basis-tag basis-6b">Art. 6(1)(b) — Contract</span>&nbsp;
    <span class="basis-tag basis-6c">Art. 6(1)(c) — Legal Obligation</span>&nbsp;
    <span class="basis-tag basis-6f">Art. 6(1)(f) — Legitimate Interests</span>&nbsp;
    <span class="basis-tag basis-9a">Art. 9(2)(a) — Explicit Consent</span>
  </div>

  <?= _ropa_activities_table('gdpr') ?>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:28px 0 10px;">Infrastructure and International Transfers</h3>
  <table>
    <thead>
      <tr><th>Region</th><th>Infrastructure Provider</th><th>Transfer Mechanism</th><th>Status</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>United States (Virginia)</td>
        <td>OVH Public Cloud (US)</td>
        <td>Standard Contractual Clauses (SCCs) — Commission Implementing Decision (EU) 2021/914; or HIPAA BAA where US health data</td>
        <td>Active</td>
      </tr>
      <tr>
        <td>European Union (France)</td>
        <td>OVH Public Cloud (France)</td>
        <td>No transfer (EU infrastructure — data stays within EEA)</td>
        <td>Planned</td>
      </tr>
    </tbody>
  </table>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:28px 0 10px;">General Description of Technical and Organisational Security Measures (Art. 30(1)(g))</h3>
  <p style="font-size:13px;color:rgba(200,210,255,0.6);line-height:1.7;">AES-256-GCM encryption at rest for all PHI fields; bcrypt password hashing; TLS/HTTPS enforcement in production mode; CSRF token protection; rate limiting and IP firewall; per-request CSP nonce; HSTS; automatic inactivity session timeout; HMAC-chained audit log; role-based access controls; WebRTC DTLS-SRTP for in-transit audio/video.</p>
</div>

        <?php return ob_get_clean();
    }


    // ──────────────────────────────────────────────────────────────
    // United Kingdom — UK GDPR Article 30 Record
    // ──────────────────────────────────────────────────────────────

    function ropa_block_uk(array $ctx): string {
        $app = htmlspecialchars($ctx['app_name']);
        ob_start(); ?>

<div class="ropa-section">
  <div class="ropa-section-label">United Kingdom — UK GDPR Article 30 Record of Processing Activities</div>
  <h2>Record of Processing Activities — UK GDPR</h2>
  <p style="font-size:13px;color:rgba(200,210,255,0.55);line-height:1.7;margin-bottom:16px;">
    Maintained under Article 30(1) of the UK GDPR (as retained in UK law by the European Union (Withdrawal) Act 2018) and the Data Protection Act 2018. This record must be made available to the ICO on request.
  </p>

  <?= _ropa_controller_block($ctx) ?>

  <div class="notice-box" style="background:rgba(124,106,247,0.05);border-color:rgba(124,106,247,0.2);color:rgba(196,184,255,0.75);">
    <strong>Legal basis tag guide (UK GDPR mirrors EU GDPR Art. 6 and 9):</strong>&nbsp;
    <span class="basis-tag basis-6b">Art. 6(1)(b) — Contract</span>&nbsp;
    <span class="basis-tag basis-6c">Art. 6(1)(c) — Legal Obligation</span>&nbsp;
    <span class="basis-tag basis-6f">Art. 6(1)(f) — Legitimate Interests</span>&nbsp;
    <span class="basis-tag basis-9a">Art. 9(2)(a) — Explicit Consent</span>
  </div>

  <?= _ropa_activities_table('gdpr') ?>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:28px 0 10px;">International Transfers</h3>
  <p style="font-size:13px;color:rgba(200,210,255,0.6);line-height:1.7;margin-bottom:12px;">Transfers outside the UK are conducted under International Data Transfer Agreements (IDTAs) — the UK equivalent of EU Standard Contractual Clauses — or, where the destination country has a UK adequacy regulation under section 17A of the DPA 2018, no additional mechanism is required. The ICO publishes the current list of countries with UK adequacy decisions.</p>
  <table>
    <thead>
      <tr><th>Destination</th><th>Transfer Mechanism</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>United States (hosting infrastructure)</td>
        <td>International Data Transfer Agreement (IDTA) with hosting provider</td>
      </tr>
      <tr>
        <td>EU/EEA (if EU datacenter used)</td>
        <td>UK adequacy regulations for EU/EEA — no additional mechanism required</td>
      </tr>
    </tbody>
  </table>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:28px 0 10px;">Technical and Organisational Security Measures</h3>
  <p style="font-size:13px;color:rgba(200,210,255,0.6);line-height:1.7;">AES-256-GCM encryption at rest for all PHI fields; bcrypt password hashing; TLS/HTTPS enforcement in production mode; CSRF token protection; rate limiting and IP firewall; per-request CSP nonce; HSTS; automatic inactivity session timeout; HMAC-chained audit log; role-based access controls; WebRTC DTLS-SRTP for in-transit audio/video.</p>
</div>

        <?php return ob_get_clean();
    }


    // ──────────────────────────────────────────────────────────────
    // United States — HIPAA Activity Inventory
    // ──────────────────────────────────────────────────────────────

    function ropa_block_us(array $ctx): string {
        $app = htmlspecialchars($ctx['app_name']);
        ob_start(); ?>

<div class="ropa-section">
  <div class="ropa-section-label">United States — HIPAA Protected Health Information Processing Inventory</div>
  <h2>PHI Processing Inventory — HIPAA</h2>
  <p style="font-size:13px;color:rgba(200,210,255,0.55);line-height:1.7;margin-bottom:16px;">
    HIPAA does not require a formal Record of Processing Activities (ROPA), but this inventory documents PHI processing activities for operational accountability, BAA management, and breach response readiness, consistent with the HIPAA Security Rule (45 CFR Part 164, Subpart C) requirement to document policies and procedures.
  </p>

  <?= _ropa_controller_block($ctx) ?>

  <div class="notice-box" style="background:rgba(245,200,66,0.05);border-color:rgba(245,200,66,0.15);color:rgba(245,220,120,0.75);">
    <strong>Basis tag guide:</strong>&nbsp;
    <span class="basis-tag basis-6b">Treatment</span>&nbsp;
    <span class="basis-tag basis-6b">Healthcare Ops</span>&nbsp;
    <span class="basis-tag basis-6b">Payment Ops</span>&nbsp;
    <span class="basis-tag basis-6c">Legal Obligation / HIPAA Security Rule</span>&nbsp;
    <span class="basis-tag basis-9a">HIPAA Authorization</span>
  </div>

  <?= _ropa_activities_table('hipaa') ?>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:28px 0 10px;">Business Associate Agreements (BAAs)</h3>
  <p style="font-size:13px;color:rgba(200,210,255,0.6);line-height:1.7;margin-bottom:12px;">The following third-party service categories qualify as Business Associates under 45 CFR § 160.103 and require a signed BAA before processing PHI:</p>
  <table>
    <thead>
      <tr><th>Category</th><th>Examples</th><th>BAA Required</th><th>Status</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Hosting / Cloud Infrastructure</td>
        <td>OVH Public Cloud (US)</td>
        <td>Yes — as infrastructure processor handling PHI at rest</td>
        <td>Required prior to production deployment</td>
      </tr>
      <tr>
        <td>Email / SMTP Provider</td>
        <td>Any SMTP provider configured by operator</td>
        <td>Yes — if any PHI transmitted in email notifications</td>
        <td>Operator responsibility — must be completed before enabling email</td>
      </tr>
      <tr>
        <td>AI Service Provider</td>
        <td>OpenAI, Anthropic, Google, Cohere</td>
        <td>Yes — session PHI transmitted to API</td>
        <td>Operator responsibility — must be completed before enabling AI feature</td>
      </tr>
      <tr>
        <td>Payment Processor</td>
        <td>PayPal, Stripe, Square</td>
        <td>Yes — if payment context includes PHI (e.g. session descriptions)</td>
        <td>Operator responsibility — must be completed before accepting payments</td>
      </tr>
    </tbody>
  </table>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:28px 0 10px;">HIPAA Security Rule — Technical Safeguards Summary</h3>
  <table>
    <thead>
      <tr><th>Safeguard</th><th>Implementation</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Access controls (§ 164.312(a)(1))</td>
        <td>Role-based access (practitioner / client / guest). Unique user identifiers. PHP session authentication.</td>
      </tr>
      <tr>
        <td>Automatic logoff (§ 164.312(a)(2)(iii))</td>
        <td>15-minute inactivity timeout for practitioner accounts; 30-minute timeout for client accounts.</td>
      </tr>
      <tr>
        <td>Encryption and decryption (§ 164.312(a)(2)(iv))</td>
        <td>AES-256-GCM at rest for PHI fields; TLS/HTTPS in transit; WebRTC DTLS-SRTP for audio/video.</td>
      </tr>
      <tr>
        <td>Audit controls (§ 164.312(b))</td>
        <td>HMAC-chained audit log for PHI access, modification, export, and deletion events.</td>
      </tr>
      <tr>
        <td>Integrity controls (§ 164.312(c)(1))</td>
        <td>Database foreign key constraints; prepared statement parameterisation; context-bound encryption (each record's encryption is bound to its session context).</td>
      </tr>
      <tr>
        <td>Transmission security (§ 164.312(e)(1))</td>
        <td>TLS enforced in production mode (HSTS header); WebRTC DTLS-SRTP; encrypted API transit.</td>
      </tr>
    </tbody>
  </table>
</div>

        <?php return ob_get_clean();
    }


    // ──────────────────────────────────────────────────────────────
    // Canada — PIPEDA Accountability Record
    // ──────────────────────────────────────────────────────────────

    function ropa_block_canada(array $ctx): string {
        $app = htmlspecialchars($ctx['app_name']);
        ob_start(); ?>

<div class="ropa-section">
  <div class="ropa-section-label">Canada — PIPEDA / Québec Law 25 Processing Record</div>
  <h2>Personal Information Processing Record — Canada</h2>
  <p style="font-size:13px;color:rgba(200,210,255,0.55);line-height:1.7;margin-bottom:16px;">
    PIPEDA does not mandate a formal ROPA, but maintaining a documented inventory of personal information processing activities is a recognised best practice for demonstrating compliance with the Accountability Principle (Schedule 1, Principle 1) and Québec Law 25 obligations. This record also supports the mandatory Privacy Impact Assessment (PIA) obligations for transfers outside Québec.
  </p>

  <?= _ropa_controller_block($ctx) ?>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Personal Information Inventory</h3>
  <table>
    <thead>
      <tr>
        <th style="min-width:160px;">Activity</th>
        <th>Data Subjects</th>
        <th>Personal Information Categories</th>
        <th>Sensitivity</th>
        <th>Consent Basis</th>
        <th>Transfers Outside Canada</th>
        <th>Retention</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>Account registration</strong></td>
        <td>Practitioners, registered clients</td>
        <td>Email, display name, hashed password, avatar</td>
        <td>Standard</td>
        <td>Implied (account creation)</td>
        <td>To hosting infrastructure (OVH US or EU)</td>
        <td>Active account + 30 days</td>
      </tr>
      <tr>
        <td><strong>Session booking</strong></td>
        <td>Clients</td>
        <td>Name, email, DOB, gender, sexuality, location — encrypted at rest</td>
        <td><span class="basis-tag basis-9a">Sensitive</span></td>
        <td>Express (at booking; separate consent for sensitive fields)</td>
        <td>To hosting infrastructure</td>
        <td>7 years from last session</td>
      </tr>
      <tr>
        <td><strong>Session delivery</strong></td>
        <td>Session participants</td>
        <td>Chat messages, clinical notes, reflection notes, voice notes, file attachments — encrypted at rest</td>
        <td><span class="basis-tag basis-9a">Sensitive (health / mental health)</span></td>
        <td>Express (session participation)</td>
        <td>To hosting infrastructure; to AI vendor if AI enabled (with BAA/DPA)</td>
        <td>7 years from session date</td>
      </tr>
      <tr>
        <td><strong>Intake forms</strong></td>
        <td>Clients</td>
        <td>Health history, goals, clinical questionnaire responses</td>
        <td><span class="basis-tag basis-9a">Sensitive (health)</span></td>
        <td>Express (informed at booking)</td>
        <td>To hosting infrastructure</td>
        <td>7 years from last session</td>
      </tr>
      <tr>
        <td><strong>Payment records</strong></td>
        <td>Clients paying for sessions</td>
        <td>Payment amount, currency, method type, status</td>
        <td>Standard (financial)</td>
        <td>Implied (payment for service)</td>
        <td>Payment processor (PayPal / Stripe / Square)</td>
        <td>7 years (financial records)</td>
      </tr>
      <tr>
        <td><strong>Security / audit log</strong></td>
        <td>All users</td>
        <td>IP address, action type, resource, timestamp, HMAC hash</td>
        <td>Standard</td>
        <td>Legitimate purpose (security)</td>
        <td>To hosting infrastructure</td>
        <td>Minimum 12 months</td>
      </tr>
    </tbody>
  </table>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:28px 0 10px;">Cross-Border Transfer Safeguards</h3>
  <p style="font-size:13px;color:rgba(200,210,255,0.6);line-height:1.7;">Personal information transferred to service providers outside Canada (including hosting infrastructure and third-party processors) is governed by contractual arrangements requiring the recipient to provide comparable protection. A Privacy Impact Assessment (PIA) was completed (or is required to be completed) before commencing transfers outside Québec under Law 25 section 70.1. Copies of PIAs are available to the Commission d'accès à l'information (CAI) on request.</p>
</div>

        <?php return ob_get_clean();
    }


    // ──────────────────────────────────────────────────────────────
    // Australia — APP-based Processing Record
    // ──────────────────────────────────────────────────────────────

    function ropa_block_australia(array $ctx): string {
        $app = htmlspecialchars($ctx['app_name']);
        ob_start(); ?>

<div class="ropa-section">
  <div class="ropa-section-label">Australia — Privacy Act 1988 / Australian Privacy Principles (APPs) Processing Record</div>
  <h2>Personal Information Handling Record — Australia</h2>
  <p style="font-size:13px;color:rgba(200,210,255,0.55);line-height:1.7;margin-bottom:16px;">
    The Australian Privacy Act 1988 does not require a formal ROPA. This record is maintained as an APP 1 (open and transparent management of personal information) best-practice document and to support Notifiable Data Breaches (NDB) readiness.
  </p>

  <?= _ropa_controller_block($ctx) ?>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Personal Information Handling Summary</h3>
  <table>
    <thead>
      <tr>
        <th style="min-width:160px;">Activity</th>
        <th>Data Subjects</th>
        <th>Personal Information (incl. Sensitive)</th>
        <th>APP Basis</th>
        <th>Cross-Border Disclosure</th>
        <th>Retention</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>Account registration</strong></td>
        <td>Practitioners, registered clients</td>
        <td>Email, display name, hashed password, avatar</td>
        <td>APP 3 — necessary for service</td>
        <td>Hosting provider (overseas) — APP 8 safeguards apply</td>
        <td>Active account + 30 days</td>
      </tr>
      <tr>
        <td><strong>Session booking</strong></td>
        <td>Clients</td>
        <td>Name, email, DOB, gender, sexual orientation, location. Sensitive PI under Privacy Act s. 6 (health info; sexual orientation).</td>
        <td>APP 3.3 — express consent for sensitive PI</td>
        <td>Hosting provider — APP 8 safeguards apply</td>
        <td>7 years from last session</td>
      </tr>
      <tr>
        <td><strong>Session delivery</strong></td>
        <td>Session participants</td>
        <td>Chat messages, clinical notes, voice notes, file attachments. Health information = sensitive PI.</td>
        <td>APP 3.3 — express consent; APP 6 — primary purpose of service</td>
        <td>Hosting provider; AI vendor if enabled — APP 8 safeguards / contractual protections</td>
        <td>7 years from session date</td>
      </tr>
      <tr>
        <td><strong>Intake forms</strong></td>
        <td>Clients</td>
        <td>Health history, goals, clinical questionnaire responses. Health information = sensitive PI.</td>
        <td>APP 3.3 — express consent</td>
        <td>Hosting provider — APP 8 safeguards apply</td>
        <td>7 years from last session</td>
      </tr>
      <tr>
        <td><strong>Payment records</strong></td>
        <td>Clients</td>
        <td>Payment amount, currency, method type, status</td>
        <td>APP 3 — necessary for payment; APP 6 — directly related secondary purpose</td>
        <td>Payment processor (may be overseas) — APP 8 safeguards / T&amp;Cs apply</td>
        <td>7 years (financial records)</td>
      </tr>
      <tr>
        <td><strong>Security / audit log</strong></td>
        <td>All users</td>
        <td>IP address, action type, resource, timestamp</td>
        <td>APP 11 — security of personal information</td>
        <td>Hosting provider — APP 8 safeguards apply</td>
        <td>Minimum 12 months</td>
      </tr>
    </tbody>
  </table>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:28px 0 10px;">Overseas Disclosure (APP 8)</h3>
  <p style="font-size:13px;color:rgba(200,210,255,0.6);line-height:1.7;">Before disclosing personal information to overseas recipients, we take reasonable steps to ensure those recipients do not breach the APPs in relation to that information. This is achieved through contractual arrangements with hosting providers, AI vendors (where applicable), and other processors. We note that, under APP 8.1, where such steps are taken, if an overseas recipient nonetheless breaches the APPs, <?= $app ?> may still be accountable under the Privacy Act.</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:28px 0 10px;">Notifiable Data Breaches (NDB) — Readiness</h3>
  <table>
    <thead>
      <tr><th>Element</th><th>Approach</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Breach detection</td>
        <td>HMAC-chained audit log; server-side error monitoring; access anomaly detection via IP firewall</td>
      </tr>
      <tr>
        <td>Eligible data breach threshold</td>
        <td>Unauthorised access to, or disclosure or loss of, personal information that a reasonable person would conclude is likely to result in serious harm to any affected individual</td>
      </tr>
      <tr>
        <td>Notification timeline</td>
        <td>OAIC and affected individuals notified as soon as practicable — target within 30 days of becoming aware</td>
      </tr>
      <tr>
        <td>Notification content</td>
        <td>Description of breach; type of information involved; steps affected individuals should take; operator contact details</td>
      </tr>
    </tbody>
  </table>
</div>

        <?php return ob_get_clean();
    }


    // ──────────────────────────────────────────────────────────────
    // Custom — operator-supplied HTML block
    // ──────────────────────────────────────────────────────────────

    function ropa_block_custom(array $ctx): string {
        $html = jur_custom_ropa_html();
        if (!trim($html)) return '';
        return '<div class="ropa-section">' . "\n" . $html . "\n" . '</div>' . "\n";
    }

}
