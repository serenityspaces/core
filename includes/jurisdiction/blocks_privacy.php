<?php
/**
 * Privacy Notice Block Functions — SerenitySpaces
 *
 * Each function returns an HTML string for a jurisdiction-specific supplementary
 * section, ready to be echoed inside the .priv-page container.
 *
 * CSS classes assumed present: .priv-section .priv-section-num h2 p ul li
 *   .data-table .rights-grid .right-card .priv-contact-box .alert-special
 *
 * All user-supplied strings (controller name, email, etc.) must arrive via $ctx
 * and are htmlspecialchars()-escaped inside each function.
 */

if (!defined('PRIVACY_BLOCKS_INCLUDED')) {
    define('PRIVACY_BLOCKS_INCLUDED', true);

    // ──────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────

    /** Wrap a string in the priv-section shell with a jurisdiction label. */
    function _priv_section(string $jurisLabel, string $inner): string {
        return '<div class="priv-section">'
             . '<div class="priv-section-num">' . htmlspecialchars($jurisLabel) . '</div>'
             . $inner
             . '</div>';
    }

    /** Render a controller/contact box from ctx. */
    function _priv_contact_box(array $ctx): string {
        $name  = $ctx['controller_display'];
        $addr  = $ctx['controller_address'];
        $email = $ctx['contact_line']; // already HTML-safe from jur_build_ctx()

        $addrLine = $addr ? '<br>' . htmlspecialchars($addr) : '';

        return '<div class="priv-contact-box">'
             . '<p><strong style="color:#c8d2ff;">Data Controller</strong><br>'
             . htmlspecialchars($name) . $addrLine . '<br>'
             . 'Email: ' . $email . '</p>'
             . '</div>';
    }

    /** Render a DPO block if name is set. */
    function _priv_dpo_block(array $ctx): string {
        if (!$ctx['dpo_name']) return '';
        $dpoContact = $ctx['dpo_email']
            ? '<a href="mailto:' . htmlspecialchars($ctx['dpo_email']) . '">'
              . htmlspecialchars($ctx['dpo_email']) . '</a>'
            : 'contact details available on request';
        return '<p style="margin-top:12px;"><strong style="color:#c8d2ff;">Data Protection Officer:</strong> '
             . htmlspecialchars($ctx['dpo_name']) . ' — ' . $dpoContact . '</p>';
    }


    // ──────────────────────────────────────────────────────────────
    // EU / EEA — GDPR (Regulation 2016/679)
    // ──────────────────────────────────────────────────────────────

    function priv_block_eu(array $ctx): string {
        $app = htmlspecialchars($ctx['app_name']);
        ob_start(); ?>

<div class="priv-section">
  <div class="priv-section-num">EU / EEA — General Data Protection Regulation (GDPR)</div>
  <h2>GDPR Supplementary Notice</h2>
  <p>This section supplements the main Privacy Notice and applies specifically to data subjects located in the European Economic Area (EEA). Processing is governed by Regulation (EU) 2016/679 (GDPR).</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Controller Identity</h3>
  <?= _priv_contact_box($ctx) ?>
  <?= _priv_dpo_block($ctx) ?>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Legal Bases for Processing (GDPR Article 6 &amp; 9)</h3>
  <table class="data-table">
    <thead>
      <tr><th>Purpose</th><th>Legal Basis</th><th>Article</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Provision of therapeutic session service</td>
        <td>Contractual necessity — processing required to perform the contract for services you have requested</td>
        <td>Art. 6(1)(b)</td>
      </tr>
      <tr>
        <td>Platform security, fraud prevention, audit logging</td>
        <td>Legitimate interests — ensuring the security and integrity of the platform and protecting all users from harm</td>
        <td>Art. 6(1)(f)</td>
      </tr>
      <tr>
        <td>Compliance with legal obligations (e.g. data subject requests, retention rules)</td>
        <td>Legal obligation — compliance with EU / member-state law applicable to the controller</td>
        <td>Art. 6(1)(c)</td>
      </tr>
      <tr>
        <td>Processing special category data (gender, sexuality, mental health session content)</td>
        <td>Explicit informed consent — collected at booking; may be withdrawn at any time</td>
        <td>Art. 9(2)(a)</td>
      </tr>
      <tr>
        <td>Cross-datacenter transfer initiated by you</td>
        <td>Explicit informed consent — separate consent flow; you may withdraw before transfer completes</td>
        <td>Art. 49(1)(a)</td>
      </tr>
    </tbody>
  </table>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">International Transfers</h3>
  <p>Where data is processed on infrastructure outside the EEA (for example, if a US-region datacenter is used), transfers are conducted under appropriate safeguards. Transfers to third countries are made either on the basis of an adequacy decision (where applicable) or under Standard Contractual Clauses (SCCs) adopted by the European Commission under Art. 46(2)(c) GDPR. A copy of the applicable transfer mechanism can be requested from the controller.</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Your GDPR Rights</h3>
  <div class="rights-grid">
    <div class="right-card">
      <h4>Access (Art. 15)</h4>
      <p>Request confirmation of whether your data is processed and obtain a copy.</p>
    </div>
    <div class="right-card">
      <h4>Rectification (Art. 16)</h4>
      <p>Request correction of inaccurate or incomplete personal data.</p>
    </div>
    <div class="right-card">
      <h4>Erasure (Art. 17)</h4>
      <p>Request deletion where there is no overriding legitimate ground to continue processing. Available directly from your client portal.</p>
    </div>
    <div class="right-card">
      <h4>Restriction (Art. 18)</h4>
      <p>Request that we restrict processing of your data in certain circumstances.</p>
    </div>
    <div class="right-card">
      <h4>Portability (Art. 20)</h4>
      <p>Receive your data in a structured, machine-readable format for transfer to another controller.</p>
    </div>
    <div class="right-card">
      <h4>Objection (Art. 21)</h4>
      <p>Object to processing based on legitimate interests; we will cease unless we can demonstrate compelling grounds.</p>
    </div>
    <div class="right-card">
      <h4>Withdraw Consent (Art. 7(3))</h4>
      <p>Withdraw consent at any time without affecting lawfulness of prior processing.</p>
    </div>
    <div class="right-card">
      <h4>Lodge a Complaint (Art. 77)</h4>
      <p>File a complaint with your national supervisory authority or the lead supervisory authority in the EU.</p>
    </div>
  </div>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Supervisory Authority</h3>
  <p>You have the right to lodge a complaint with the competent data protection supervisory authority in your EU member state. A list of national supervisory authorities is maintained by the European Data Protection Board (EDPB) at <a href="https://edpb.europa.eu/about-edpb/about-edpb/members_en" style="color:rgba(196,184,255,0.85);" target="_blank" rel="noopener">edpb.europa.eu</a>.</p>
  <p>All data subject requests under GDPR are responded to within <strong>30 days</strong> of receipt. Where requests are complex or numerous, this may be extended by a further two months; you will be notified of any extension within the initial 30-day period.</p>
</div>

        <?php return ob_get_clean();
    }


    // ──────────────────────────────────────────────────────────────
    // United Kingdom — UK GDPR + Data Protection Act 2018
    // ──────────────────────────────────────────────────────────────

    function priv_block_uk(array $ctx): string {
        ob_start(); ?>

<div class="priv-section">
  <div class="priv-section-num">United Kingdom — UK GDPR &amp; Data Protection Act 2018</div>
  <h2>UK GDPR Supplementary Notice</h2>
  <p>This section applies to data subjects located in the United Kingdom. Processing is governed by the UK GDPR (as retained in UK law under the European Union (Withdrawal) Act 2018) and the Data Protection Act 2018.</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Controller Identity</h3>
  <?= _priv_contact_box($ctx) ?>
  <?= _priv_dpo_block($ctx) ?>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Legal Bases for Processing</h3>
  <table class="data-table">
    <thead>
      <tr><th>Purpose</th><th>Legal Basis</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Provision of therapeutic session service</td>
        <td>Contractual necessity — UK GDPR Art. 6(1)(b)</td>
      </tr>
      <tr>
        <td>Platform security and audit logging</td>
        <td>Legitimate interests — UK GDPR Art. 6(1)(f)</td>
      </tr>
      <tr>
        <td>Legal compliance and data subject requests</td>
        <td>Legal obligation — UK GDPR Art. 6(1)(c)</td>
      </tr>
      <tr>
        <td>Processing special category data (gender, sexuality, mental health content)</td>
        <td>Explicit consent — UK GDPR Art. 9(2)(a); DPA 2018 Schedule 1 Part 1</td>
      </tr>
    </tbody>
  </table>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">International Transfers</h3>
  <p>Where data is transferred outside the United Kingdom, transfers are conducted under appropriate safeguards. The UK government maintains a list of countries with adequacy regulations under section 17A of the DPA 2018. For transfers to countries without an adequacy decision, International Data Transfer Agreements (IDTAs) — the UK equivalent of EU Standard Contractual Clauses — are used. A copy of the applicable transfer mechanism can be requested from the controller.</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Your Rights Under UK GDPR</h3>
  <p>Your rights under UK GDPR mirror those under EU GDPR (see above). You have rights of access, rectification, erasure, restriction, portability, and objection. You also have the right to withdraw consent at any time and the right not to be subject to solely automated decision-making that produces significant effects.</p>
  <p>Requests are responded to within <strong>one calendar month</strong> (extendable by two further months for complex requests, with notice given within the first month).</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">UK Supervisory Authority</h3>
  <p>You have the right to lodge a complaint with the <strong>Information Commissioner's Office (ICO)</strong>, the UK data protection supervisory authority:</p>
  <div class="priv-contact-box">
    <p>
      <strong style="color:#c8d2ff;">Information Commissioner's Office</strong><br>
      Wycliffe House, Water Lane, Wilmslow, Cheshire, SK9 5AF<br>
      Tel: <a href="tel:+443031231113">0303 123 1113</a><br>
      Web: <a href="https://ico.org.uk/make-a-complaint/" target="_blank" rel="noopener">ico.org.uk/make-a-complaint</a>
    </p>
  </div>
</div>

        <?php return ob_get_clean();
    }


    // ──────────────────────────────────────────────────────────────
    // United States — HIPAA + CCPA/CPRA
    // ──────────────────────────────────────────────────────────────

    function priv_block_us(array $ctx): string {
        $app = htmlspecialchars($ctx['app_name']);
        ob_start(); ?>

<div class="priv-section">
  <div class="priv-section-num">United States — HIPAA Notice of Privacy Practices &amp; CCPA/CPRA</div>
  <h2>US Privacy Supplementary Notice</h2>
  <p>This section constitutes the <strong>HIPAA Notice of Privacy Practices (NPP)</strong> required under 45 CFR § 164.520. It applies to protected health information (PHI) processed by <?= $app ?> on US infrastructure. A supplementary California Consumer Privacy Act (CCPA/CPRA) notice follows for California residents.</p>

  <div class="alert-special">
    <strong>YOUR RIGHTS UNDER HIPAA:</strong> This Notice describes how medical and mental health information about you may be used and disclosed and how you can get access to this information. Please review it carefully.
  </div>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">How We May Use and Disclose Your PHI</h3>
  <table class="data-table">
    <thead>
      <tr><th>Use / Disclosure</th><th>Basis</th><th>Authorisation Required?</th></tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>Treatment</strong> — Providing, coordinating, or managing your healthcare and related services. This includes sharing information with practitioners involved in your care.</td>
        <td>HIPAA Treatment Operations</td>
        <td>No</td>
      </tr>
      <tr>
        <td><strong>Healthcare Operations</strong> — Activities necessary to run the platform: quality assessment, training, business planning, and compliance.</td>
        <td>HIPAA Healthcare Operations</td>
        <td>No</td>
      </tr>
      <tr>
        <td><strong>Payment</strong> — Processing or facilitating payment for your sessions.</td>
        <td>HIPAA Payment Operations</td>
        <td>No</td>
      </tr>
      <tr>
        <td><strong>As Required by Law</strong> — Disclosures required by applicable federal or state law, including disclosures to public health authorities, law enforcement under specific conditions, or judicial orders.</td>
        <td>Legal obligation</td>
        <td>No</td>
      </tr>
      <tr>
        <td><strong>Serious Threat to Health or Safety</strong> — Disclosure to prevent or lessen a serious and imminent threat to you or others, where disclosure is to a person reasonably able to prevent the threat.</td>
        <td>HIPAA § 164.512(j)</td>
        <td>No</td>
      </tr>
      <tr>
        <td><strong>All Other Disclosures</strong> — Any disclosure of your PHI not covered above, including to family members, employers, or for marketing purposes.</td>
        <td>Your prior written HIPAA Authorization</td>
        <td><strong>Yes</strong></td>
      </tr>
    </tbody>
  </table>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Your HIPAA Rights</h3>
  <div class="rights-grid">
    <div class="right-card">
      <h4>Right of Access</h4>
      <p>Inspect and obtain a copy of your PHI held in our designated record set. Requests responded to within 30 days (extendable once by 30 days with notice).</p>
    </div>
    <div class="right-card">
      <h4>Right to Amend</h4>
      <p>Request amendment of PHI you believe is incorrect or incomplete. We may deny the request in certain circumstances and will explain why.</p>
    </div>
    <div class="right-card">
      <h4>Right to an Accounting of Disclosures</h4>
      <p>Request a list of disclosures of your PHI made in the six years prior to your request, other than for treatment, payment, and operations.</p>
    </div>
    <div class="right-card">
      <h4>Right to Request Restrictions</h4>
      <p>Request restrictions on certain uses and disclosures. We are not required to agree, except for disclosures to your health plan for items you paid out-of-pocket in full.</p>
    </div>
    <div class="right-card">
      <h4>Right to Confidential Communications</h4>
      <p>Request that we communicate with you about your PHI by alternative means or at alternative locations.</p>
    </div>
    <div class="right-card">
      <h4>Right to a Paper Copy</h4>
      <p>Request a paper copy of this Notice at any time, even if you previously agreed to receive it electronically.</p>
    </div>
  </div>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Breach Notification</h3>
  <p>In the event of a breach of unsecured PHI, <?= $app ?> will notify affected individuals without unreasonable delay and within <strong>60 days</strong> of discovery, as required by the HIPAA Breach Notification Rule (45 CFR §§ 164.400–414). Large breaches affecting 500 or more individuals in a state are also notified to HHS and prominent media outlets in that state within 60 days.</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Filing a Complaint</h3>
  <p>If you believe your HIPAA privacy rights have been violated, you may file a complaint with the controller (contact details above) or with the <strong>HHS Office for Civil Rights (OCR)</strong>:</p>
  <div class="priv-contact-box">
    <p>
      <strong style="color:#c8d2ff;">HHS Office for Civil Rights</strong><br>
      200 Independence Avenue, S.W., Washington, D.C. 20201<br>
      Toll-free: 1-800-368-1019 &nbsp;·&nbsp; TDD: 1-800-537-7697<br>
      Web: <a href="https://www.hhs.gov/hipaa/filing-a-complaint" target="_blank" rel="noopener">hhs.gov/hipaa/filing-a-complaint</a>
    </p>
  </div>
  <p style="margin-top:12px;">You will not be penalised for filing a complaint.</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:24px 0 10px;">California Residents — CCPA/CPRA Notice</h3>
  <p>If you are a California resident, the California Consumer Privacy Act (CCPA) as amended by the California Privacy Rights Act (CPRA) grants you additional rights in relation to your personal information:</p>
  <div class="rights-grid">
    <div class="right-card">
      <h4>Right to Know</h4>
      <p>Know what personal information we collect about you, why we collect it, and who we share it with. You may request up to two disclosures in any 12-month period.</p>
    </div>
    <div class="right-card">
      <h4>Right to Delete</h4>
      <p>Request deletion of personal information we have collected from you, subject to certain exceptions (e.g. legal retention obligations).</p>
    </div>
    <div class="right-card">
      <h4>Right to Correct</h4>
      <p>Request correction of inaccurate personal information we maintain about you.</p>
    </div>
    <div class="right-card">
      <h4>Right to Opt-Out of Sale/Sharing</h4>
      <p>We do not sell or share your personal information for cross-context behavioural advertising. No opt-out mechanism is required.</p>
    </div>
    <div class="right-card">
      <h4>Right to Limit Sensitive PI Use</h4>
      <p>Limit the use and disclosure of sensitive personal information to purposes necessary to provide services to you.</p>
    </div>
    <div class="right-card">
      <h4>Right to Non-Discrimination</h4>
      <p>We will not discriminate against you for exercising your CCPA rights.</p>
    </div>
  </div>
  <p style="margin-top:14px;">To exercise your CCPA/CPRA rights, contact the controller using the details in this notice. We will respond within <strong>45 days</strong> (extendable by a further 45 days with notice). Requests are free of charge, up to twice per 12-month period.</p>
  <p>To submit a complaint regarding our handling of your California privacy rights, contact the <strong>California Privacy Protection Agency (CPPA)</strong> at <a href="https://cppa.ca.gov" target="_blank" rel="noopener" style="color:rgba(196,184,255,0.85);">cppa.ca.gov</a>.</p>
</div>

        <?php return ob_get_clean();
    }


    // ──────────────────────────────────────────────────────────────
    // Canada — PIPEDA + Québec Law 25
    // ──────────────────────────────────────────────────────────────

    function priv_block_canada(array $ctx): string {
        $app = htmlspecialchars($ctx['app_name']);
        ob_start(); ?>

<div class="priv-section">
  <div class="priv-section-num">Canada — PIPEDA / Québec Law 25 (Law 25)</div>
  <h2>Canadian Privacy Supplementary Notice</h2>
  <p>This section applies to individuals in Canada and constitutes the privacy notice required under the <strong>Personal Information Protection and Electronic Documents Act (PIPEDA)</strong> (S.C. 2000, c. 5) and, where applicable, <strong>Québec Law 25</strong> (An Act to modernize legislative provisions as regards the protection of personal information, S.Q. 2021, c. 25, also referred to as Bill 64).</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">The Ten PIPEDA Principles</h3>
  <p><?= $app ?> adheres to the ten fair information principles set out in Schedule 1 of PIPEDA:</p>
  <table class="data-table">
    <thead>
      <tr><th>Principle</th><th>How it applies to <?= $app ?></th></tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>1. Accountability</strong></td>
        <td>The controller identified in this notice is responsible for personal information under its control and has designated a privacy contact (see Section 01). Where a DPO is named, they serve this accountability function.</td>
      </tr>
      <tr>
        <td><strong>2. Identifying Purposes</strong></td>
        <td>The purposes for which personal information is collected are identified in Sections 02 and 03 of this notice, before or at the time of collection.</td>
      </tr>
      <tr>
        <td><strong>3. Consent</strong></td>
        <td>Knowledge and consent are obtained for the collection, use, or disclosure of personal information, except where appropriate (e.g. legal obligation). Consent may be withdrawn at any time, subject to legal and contractual restrictions.</td>
      </tr>
      <tr>
        <td><strong>4. Limiting Collection</strong></td>
        <td>Collection is limited to that which is necessary for the purposes identified. Information is not collected indiscriminately.</td>
      </tr>
      <tr>
        <td><strong>5. Limiting Use, Disclosure and Retention</strong></td>
        <td>Personal information is not used or disclosed for purposes other than those for which it was collected, except with consent or as required by law. Information is retained only as long as necessary (see Section 04).</td>
      </tr>
      <tr>
        <td><strong>6. Accuracy</strong></td>
        <td>Personal information is kept as accurate, complete, and up-to-date as is necessary. You may request correction of inaccurate information.</td>
      </tr>
      <tr>
        <td><strong>7. Safeguards</strong></td>
        <td>Security measures appropriate to the sensitivity of the information are in place (see Section 08). Sensitive health information receives enhanced protection including encryption at rest.</td>
      </tr>
      <tr>
        <td><strong>8. Openness</strong></td>
        <td>This notice makes our policies and practices relating to personal information publicly available.</td>
      </tr>
      <tr>
        <td><strong>9. Individual Access</strong></td>
        <td>Upon request, we will inform you of the existence, use, and disclosure of your personal information and give you access to it. You may challenge the accuracy and completeness of the information and request amendment.</td>
      </tr>
      <tr>
        <td><strong>10. Challenging Compliance</strong></td>
        <td>You may direct a challenge concerning compliance with the above principles to the designated privacy contact (see Section 01).</td>
      </tr>
    </tbody>
  </table>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Québec Law 25 — Additional Requirements</h3>
  <p>Where Québec residents' information is processed, additional obligations under Law 25 apply from 22 September 2022 (Phase 1) and 22 September 2023 (Phase 2 and 3):</p>
  <ul>
    <li><strong>Privacy by default:</strong> Default settings are set to the highest level of privacy. Only personal information necessary for the stated purpose is collected by default.</li>
    <li><strong>Privacy Impact Assessments (PIAs):</strong> A PIA is conducted before communicating personal information outside Québec or before implementing new information systems involving personal information collection.</li>
    <li><strong>Breach notification:</strong> Where a privacy incident presents a risk of serious injury, the Commission d'accès à l'information (CAI) and affected individuals are notified within <strong>72 hours</strong> of discovery.</li>
    <li><strong>Right to data portability:</strong> Québec residents have the right to receive their personal information in a structured, commonly-used, technologically-readable format and to request that it be transmitted to any person or body authorised to collect personal information.</li>
    <li><strong>Right to de-indexation (right to be forgotten):</strong> Québec residents may request that hyperlinks connecting them to personal information be de-indexed, where that information is injurious to reputation or privacy, obsolete, or not necessary for the purposes for which it was collected.</li>
    <li><strong>Consent for sensitive information:</strong> Explicit consent is required for collection of sensitive personal information (including health, sexual orientation, and financial information). Such consent must be requested separately from other consents and in clear, plain language.</li>
    <li><strong>Person responsible for personal information protection:</strong> The controller has designated a person responsible for the protection of personal information. This person's title and contact information are available on request.</li>
  </ul>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Your Rights</h3>
  <p>Under PIPEDA and Law 25, you have the right to:</p>
  <ul>
    <li>Know whether we hold personal information about you and to access that information</li>
    <li>Challenge the accuracy and completeness of your information and request amendment</li>
    <li>Withdraw consent to the collection, use, or disclosure of your information (subject to legal and contractual restrictions)</li>
    <li>Request deletion of personal information that is no longer necessary for the identified purposes (Law 25)</li>
    <li>Data portability — receive your information in a machine-readable format (Law 25)</li>
    <li>Lodge a complaint with the Privacy Commissioner or the CAI</li>
  </ul>
  <p>Requests are responded to within <strong>30 days</strong> of receipt (with possible extension for complex requests, with notice provided within the 30-day period).</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Supervisory Authorities</h3>
  <div class="priv-contact-box">
    <p>
      <strong style="color:#c8d2ff;">Office of the Privacy Commissioner of Canada (OPC)</strong><br>
      30 Victoria Street, Gatineau, Quebec, K1A 1H3<br>
      Tel: 1-800-282-1376<br>
      Web: <a href="https://www.priv.gc.ca/en/report-a-concern/" target="_blank" rel="noopener">priv.gc.ca/en/report-a-concern</a>
    </p>
    <p style="margin-top:12px;">
      <strong style="color:#c8d2ff;">Commission d'accès à l'information du Québec (CAI)</strong> — Québec residents only<br>
      Web: <a href="https://www.cai.gouv.qc.ca/en/for-citizens/file-complaint/" target="_blank" rel="noopener">cai.gouv.qc.ca/en/for-citizens/file-complaint</a>
    </p>
  </div>
</div>

        <?php return ob_get_clean();
    }


    // ──────────────────────────────────────────────────────────────
    // Australia — Privacy Act 1988 + APPs + NDB scheme
    // ──────────────────────────────────────────────────────────────

    function priv_block_australia(array $ctx): string {
        $app = htmlspecialchars($ctx['app_name']);
        ob_start(); ?>

<div class="priv-section">
  <div class="priv-section-num">Australia — Privacy Act 1988 &amp; Australian Privacy Principles (APPs)</div>
  <h2>Australian Privacy Supplementary Notice</h2>
  <p>This section applies to individuals in Australia and is provided in accordance with the <strong>Privacy Act 1988 (Cth)</strong> and the <strong>Australian Privacy Principles (APPs)</strong> contained in Schedule 1 of that Act. It also addresses obligations under the <strong>Notifiable Data Breaches (NDB) scheme</strong> (Part IIIC of the Privacy Act).</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Controller Identity</h3>
  <?= _priv_contact_box($ctx) ?>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Collection of Personal Information</h3>
  <p>Under APP 5, we are required to notify you of certain matters when collecting personal information. The personal information collected by <?= $app ?> is set out in Section 02 of this notice. Information is collected for the purposes described in Section 03, which are the purposes of providing and improving therapeutic session services, ensuring platform security, and complying with legal obligations.</p>
  <p>Where it is reasonable and practicable, personal information is collected directly from you. Where information is collected from a third party, we will take reasonable steps to notify you within a reasonable period.</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Sensitive Information</h3>
  <p>Some information collected by <?= $app ?> constitutes <strong>sensitive information</strong> under the Privacy Act (APP 3.3), specifically: health information (session content, clinical notes), information about sexual orientation or practices (if provided in booking data), and information about mental health.</p>
  <p>Sensitive information is collected only with your express consent, or where required or authorised by law. You may decline to provide sensitive information; however, this may limit the service we are able to provide.</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Use and Disclosure</h3>
  <p>Under APP 6, personal information is only used or disclosed for the primary purpose for which it was collected, a directly related secondary purpose that you would reasonably expect, or where you have consented. We do not use personal information for direct marketing without your consent (APP 7).</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Cross-Border Disclosure</h3>
  <p>Under APP 8, before disclosing personal information to an overseas recipient, we take reasonable steps to ensure the recipient does not breach the APPs. Where data is stored on overseas infrastructure, we take reasonable steps — including contractual arrangements — to ensure the overseas infrastructure provider protects the information in a manner consistent with the APPs. By using <?= $app ?>, you acknowledge that your information may be stored on servers located outside Australia.</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Data Quality and Security</h3>
  <p>We take reasonable steps to ensure personal information is accurate, up-to-date, and complete (APP 10) and to protect it from misuse, interference, loss, and unauthorised access, modification, or disclosure (APP 11). Security measures are described in Section 08. When personal information is no longer needed for any purpose for which it may be used or disclosed, we will take reasonable steps to destroy or de-identify it (APP 11.2), subject to applicable retention obligations.</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Notifiable Data Breaches (NDB)</h3>
  <p>In the event of an eligible data breach (i.e. unauthorised access, disclosure, or loss of personal information that is likely to result in serious harm), we will notify the <strong>Office of the Australian Information Commissioner (OAIC)</strong> and affected individuals as soon as practicable, and in any event within <strong>30 days</strong> of becoming aware of the breach. Notification will include a description of the breach, the type of information involved, and the steps we recommend you take in response.</p>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Your Rights</h3>
  <div class="rights-grid">
    <div class="right-card">
      <h4>Access (APP 12)</h4>
      <p>Request access to the personal information we hold about you. We will respond within a reasonable time and provide access in the format you request, where reasonable.</p>
    </div>
    <div class="right-card">
      <h4>Correction (APP 13)</h4>
      <p>Request correction of personal information that is inaccurate, out-of-date, incomplete, irrelevant, or misleading. We will respond within a reasonable time (generally 30 days).</p>
    </div>
    <div class="right-card">
      <h4>Complaint</h4>
      <p>Lodge a complaint with us first; if not resolved within a reasonable time (generally 30 days), you may complain to the OAIC.</p>
    </div>
    <div class="right-card">
      <h4>Anonymity (APP 2)</h4>
      <p>Where lawful and practicable, you have the option to interact with us anonymously or using a pseudonym. Note: full session functionality requires an identified account.</p>
    </div>
  </div>

  <h3 style="font-size:15px;font-weight:700;color:#e8eeff;margin:20px 0 10px;">Complaints and Supervisory Authority</h3>
  <p>If you have a complaint about how we handle your personal information, please contact the controller using the details above. We will acknowledge the complaint within 10 business days and respond substantively within 30 days.</p>
  <p>If you are not satisfied with our response, you may escalate to the <strong>Office of the Australian Information Commissioner (OAIC)</strong>:</p>
  <div class="priv-contact-box">
    <p>
      <strong style="color:#c8d2ff;">Office of the Australian Information Commissioner</strong><br>
      GPO Box 5218, Sydney NSW 2001<br>
      Tel: 1300 363 992<br>
      Web: <a href="https://www.oaic.gov.au/privacy/privacy-complaints" target="_blank" rel="noopener">oaic.gov.au/privacy/privacy-complaints</a>
    </p>
  </div>
</div>

        <?php return ob_get_clean();
    }


    // ──────────────────────────────────────────────────────────────
    // Custom — render operator-supplied HTML block
    // ──────────────────────────────────────────────────────────────

    function priv_block_custom(array $ctx): string {
        $html = jur_custom_privacy_html();
        if (!trim($html)) return '';
        // Admin-entered HTML rendered directly — not user-supplied input
        return '<div class="priv-section">' . "\n" . $html . "\n" . '</div>' . "\n";
    }

}
