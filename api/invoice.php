<?php
/**
 * Serenity Spaces — Invoice / Receipt
 *
 * Access:
 *   Practitioner (session):   GET ?payment_id=X
 *   Client (logged in):       GET ?payment_id=X  (end_user_id session)
 *   Client (booking token):   GET ?payment_id=X&token=BOOKING_TOKEN
 *
 * Add &download=1 to trigger the browser print dialog automatically.
 */
session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';

$pdo       = getDB();
$paymentId = (int)($_GET['payment_id'] ?? 0);
$token     = trim($_GET['token'] ?? '');
$download  = !empty($_GET['download']);

if (!$paymentId) {
    http_response_code(400);
    echo 'Missing payment ID.';
    exit;
}

// ── Fetch payment record ───────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        bp.id, bp.booking_id, bp.practitioner_id,
        bp.amount_due, bp.amount_paid, bp.currency,
        bp.discount_pct, bp.status, bp.gateway, bp.gateway_ref,
        bp.paid_at, bp.notes,
        b.guest_name, b.end_user_id, b.booking_token, b.scheduled_at, b.created_at AS booked_at,
        r.name AS session_name,
        COALESCE(eu.display_name, b.guest_name) AS client_name,
        p.display_name AS practitioner_name, p.practice_name, p.role_type
    FROM booking_payments bp
    LEFT JOIN bookings b       ON b.id  = bp.booking_id
    LEFT JOIN rooms r          ON r.id  = b.room_id
    LEFT JOIN end_users eu     ON eu.id = b.end_user_id
    LEFT JOIN practitioners p  ON p.id  = bp.practitioner_id
    WHERE bp.id = ?
    LIMIT 1
");
$stmt->execute([$paymentId]);
$pay = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pay) {
    http_response_code(404);
    echo 'Invoice not found.';
    exit;
}

// ── Access control ─────────────────────────────────────────────────
$authorized = false;

// Practitioner session
if (!empty($_SESSION['practitioner_id']) && (int)$_SESSION['practitioner_id'] === (int)$pay['practitioner_id']) {
    $authorized = true;
}

// Client logged in (end_user_id session)
if (!$authorized && !empty($_SESSION['end_user_id']) && (int)$_SESSION['end_user_id'] === (int)$pay['end_user_id']) {
    $authorized = true;
}

// Client via booking token (email link / guest)
if (!$authorized && $token !== '' && hash_equals($pay['booking_token'] ?? '', $token)) {
    $authorized = true;
}

if (!$authorized) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

// ── Derived display values ─────────────────────────────────────────
$appName   = getSetting('app_name', 'Serenity Spaces');
$appLogo   = getAppLogo();

$invoiceNo = 'INV-' . str_pad((int)$pay['id'], 5, '0', STR_PAD_LEFT);

$currency     = strtoupper($pay['currency'] ?: 'GBP');
$amountDue    = (float)$pay['amount_due'];
$amountPaid   = (float)$pay['amount_paid'];
$discountPct  = (int)$pay['discount_pct'];
$isFree       = ($amountDue == 0.0 || $pay['gateway'] === 'free');

// Resolve display status
if ($isFree) {
    $statusLabel = 'Paid';
    $statusNote  = 'Complimentary session';
    $statusColor = '#3ecf8e';
} elseif ($pay['status'] === 'paid') {
    $statusLabel = 'Paid';
    $statusNote  = '';
    $statusColor = '#3ecf8e';
} elseif ($pay['status'] === 'waived') {
    $statusLabel = 'Waived';
    $statusNote  = 'No charge applied';
    $statusColor = '#7c6af7';
} elseif ($pay['status'] === 'pending') {
    $statusLabel = 'Payment Pending';
    $statusNote  = 'Please complete payment via your practitioner\'s payment link.';
    $statusColor = '#f5c842';
} elseif ($pay['status'] === 'failed') {
    $statusLabel = 'Payment Failed';
    $statusNote  = 'Please contact your practitioner.';
    $statusColor = '#e05c5c';
} else {
    $statusLabel = ucfirst($pay['status']);
    $statusNote  = '';
    $statusColor = '#888';
}

$gwLabels = [
    'paypal'  => 'PayPal',
    'stripe'  => 'Stripe',
    'bitcoin' => 'Bitcoin',
    'square'  => 'Square',
    'free'    => 'Free',
    'manual'  => 'Manual / Bank Transfer',
    'waived'  => 'Waived',
];
$gwLabel = $gwLabels[$pay['gateway']] ?? ucfirst($pay['gateway'] ?: '—');

$roleLabels = [
    'therapist'              => 'Therapist',
    'counselor'              => 'Counselor',
    'coach'                  => 'Life Coach',
    'philosophical_counselor'=> 'Philosophical Counselor',
    'peer_support'           => 'Peer Support Specialist',
    'pastoral_counselor'     => 'Pastoral Counselor',
    'spiritual_director'     => 'Spiritual Director',
];

$practitionerTitle = $pay['practice_name'] ?: ($roleLabels[$pay['role_type']] ?? '');

$fmt = fn($n) => number_format((float)$n, 2);
$fmtCur = fn($n) => $currency . ' ' . number_format((float)$n, 2);

$issuedDate = $pay['booked_at']
    ? (new DateTime($pay['booked_at']))->format('j F Y')
    : date('j F Y');

$paidDate = $pay['paid_at']
    ? (new DateTime($pay['paid_at']))->format('j F Y')
    : null;

$sessionDate = $pay['scheduled_at']
    ? (new DateTime($pay['scheduled_at']))->format('j F Y \a\t g:i A')
    : null;

$autoprint = $download ? 'true' : 'false';

// Serve as HTML (print-to-PDF via browser, same as export.php pattern)
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($invoiceNo) ?> — <?= htmlspecialchars($appName) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 13px;
    color: #1a1a2e;
    background: #f4f4f6;
    padding: 40px 20px;
  }

  .invoice-wrap {
    background: #fff;
    max-width: 680px;
    margin: 0 auto;
    border-radius: 10px;
    box-shadow: 0 2px 24px rgba(0,0,0,.09);
    overflow: hidden;
  }

  /* Header bar */
  .inv-header {
    background: #0d1433;
    color: #fff;
    padding: 30px 36px 28px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
  }
  .inv-header-left {}
  .inv-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
  }
  .inv-brand img { max-height: 40px; width: auto; }
  .inv-brand-name { font-size: 16px; font-weight: 700; color: #e8e8f4; font-family: Arial, sans-serif; }
  .inv-title { font-size: 28px; font-weight: 700; letter-spacing: -0.5px; color: #fff; font-family: Arial, sans-serif; }
  .inv-number { font-size: 13px; color: #8890b8; margin-top: 4px; font-family: Arial, sans-serif; }

  .inv-status-badge {
    display: inline-block;
    padding: 6px 16px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .04em;
    font-family: Arial, sans-serif;
    text-transform: uppercase;
    margin-top: 6px;
  }

  /* Body */
  .inv-body { padding: 32px 36px; }

  .inv-parties {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 28px;
  }
  .inv-party-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: #888;
    margin-bottom: 6px;
    font-family: Arial, sans-serif;
  }
  .inv-party-name { font-size: 15px; font-weight: 700; color: #1a1a2e; margin-bottom: 2px; }
  .inv-party-sub  { font-size: 12px; color: #666; }

  .inv-meta-row {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    padding: 16px 0;
    border-top: 1px solid #eee;
    border-bottom: 1px solid #eee;
    margin-bottom: 28px;
  }
  .inv-meta-item {}
  .inv-meta-label { font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #888; margin-bottom: 3px; font-family: Arial, sans-serif; }
  .inv-meta-val   { font-size: 13px; color: #1a1a2e; }

  /* Line items table */
  .inv-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
  .inv-table th {
    text-align: left;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: #888;
    padding: 8px 0;
    border-bottom: 2px solid #eee;
    font-family: Arial, sans-serif;
  }
  .inv-table th:last-child { text-align: right; }
  .inv-table td {
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 13px;
    color: #1a1a2e;
    vertical-align: top;
  }
  .inv-table td:last-child { text-align: right; }
  .inv-table .td-sub { font-size: 11px; color: #888; margin-top: 2px; }

  .inv-totals { margin-left: auto; width: 240px; }
  .inv-total-row {
    display: flex;
    justify-content: space-between;
    padding: 5px 0;
    font-size: 13px;
    color: #555;
  }
  .inv-total-row.total {
    font-size: 16px;
    font-weight: 700;
    color: #1a1a2e;
    border-top: 2px solid #1a1a2e;
    margin-top: 6px;
    padding-top: 10px;
  }

  .inv-status-block {
    margin-top: 24px;
    padding: 14px 16px;
    border-radius: 8px;
    border-left: 4px solid;
  }
  .inv-status-block .status-title { font-weight: 700; font-size: 13px; margin-bottom: 2px; font-family: Arial, sans-serif; }
  .inv-status-block .status-note  { font-size: 12px; }

  .inv-ref-row {
    margin-top: 16px;
    font-size: 12px;
    color: #888;
  }
  .inv-ref-row span { color: #1a1a2e; font-weight: 600; }

  .inv-notes {
    margin-top: 20px;
    padding: 12px 14px;
    background: #f9f9fb;
    border-radius: 6px;
    font-size: 12px;
    color: #555;
    line-height: 1.6;
    white-space: pre-wrap;
  }
  .inv-notes-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .07em;
    text-transform: uppercase;
    color: #888;
    margin-bottom: 6px;
    font-family: Arial, sans-serif;
  }

  /* Footer */
  .inv-footer {
    margin-top: 32px;
    padding-top: 18px;
    border-top: 1px solid #eee;
    font-size: 11px;
    color: #aaa;
    text-align: center;
    line-height: 1.6;
  }

  .print-bar {
    text-align: center;
    margin-bottom: 24px;
  }
  .print-bar button {
    background: #0d1433;
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 10px 22px;
    font-size: 13px;
    cursor: pointer;
    font-family: Arial, sans-serif;
  }
  .print-bar button:hover { background: #1a2550; }

  @media print {
    body { background: #fff; padding: 0; }
    .invoice-wrap { box-shadow: none; border-radius: 0; }
    .print-bar { display: none; }
  }
</style>
</head>
<body>

<div class="print-bar">
  <button onclick="window.print()">Print / Save as PDF</button>
</div>

<div class="invoice-wrap">

  <!-- Header -->
  <div class="inv-header">
    <div class="inv-header-left">
      <div class="inv-brand">
        <?php if ($appLogo): ?>
          <img src="<?= htmlspecialchars($appLogo) ?>" alt="<?= htmlspecialchars($appName) ?>">
        <?php else: ?>
          <span class="inv-brand-name"><?= htmlspecialchars($appName) ?></span>
        <?php endif; ?>
      </div>
      <div class="inv-title">Invoice</div>
      <div class="inv-number"><?= htmlspecialchars($invoiceNo) ?></div>
    </div>
    <div style="text-align:right;padding-top:6px;">
      <div style="font-size:11px;color:#8890b8;margin-bottom:6px;font-family:Arial,sans-serif;">STATUS</div>
      <div class="inv-status-badge" style="background:<?= $statusColor ?>22;color:<?= $statusColor ?>;border:1px solid <?= $statusColor ?>55;">
        <?= htmlspecialchars($statusLabel) ?>
      </div>
    </div>
  </div>

  <!-- Body -->
  <div class="inv-body">

    <!-- From / To -->
    <div class="inv-parties">
      <div>
        <div class="inv-party-label">From</div>
        <div class="inv-party-name"><?= htmlspecialchars($pay['practitioner_name'] ?: 'Your Practitioner') ?></div>
        <?php if ($practitionerTitle): ?>
          <div class="inv-party-sub"><?= htmlspecialchars($practitionerTitle) ?></div>
        <?php endif; ?>
      </div>
      <div>
        <div class="inv-party-label">To</div>
        <div class="inv-party-name"><?= htmlspecialchars($pay['client_name'] ?: 'Client') ?></div>
      </div>
    </div>

    <!-- Meta row -->
    <div class="inv-meta-row">
      <div class="inv-meta-item">
        <div class="inv-meta-label">Invoice Date</div>
        <div class="inv-meta-val"><?= htmlspecialchars($issuedDate) ?></div>
      </div>
      <?php if ($paidDate): ?>
      <div class="inv-meta-item">
        <div class="inv-meta-label">Paid On</div>
        <div class="inv-meta-val"><?= htmlspecialchars($paidDate) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($sessionDate): ?>
      <div class="inv-meta-item">
        <div class="inv-meta-label">Session Date</div>
        <div class="inv-meta-val"><?= htmlspecialchars($sessionDate) ?></div>
      </div>
      <?php endif; ?>
      <div class="inv-meta-item">
        <div class="inv-meta-label">Payment Method</div>
        <div class="inv-meta-val"><?= htmlspecialchars($gwLabel) ?></div>
      </div>
    </div>

    <!-- Line item table -->
    <table class="inv-table">
      <thead>
        <tr>
          <th>Description</th>
          <th style="text-align:right;">Amount</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>
            <?= htmlspecialchars($pay['session_name'] ?: 'Therapeutic Session') ?>
            <?php if ($sessionDate): ?>
              <div class="td-sub"><?= htmlspecialchars($sessionDate) ?></div>
            <?php endif; ?>
          </td>
          <td>
          <?php if ($discountPct > 0 && $amountDue > 0):
            $original = round($amountDue / (1 - $discountPct / 100), 2);
          ?>
            <?= htmlspecialchars($fmtCur($original)) ?>
          <?php else: ?>
            <?= htmlspecialchars($fmtCur($amountDue)) ?>
          <?php endif; ?>
          </td>
        </tr>
        <?php if ($discountPct > 0): ?>
        <tr>
          <td style="color:#3ecf8e;">Discount (<?= (int)$discountPct ?>%)</td>
          <td style="color:#3ecf8e;">−<?= htmlspecialchars($fmtCur(round($amountDue / (1 - $discountPct / 100) * ($discountPct / 100), 2))) ?></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- Totals -->
    <div class="inv-totals">
      <?php if ($discountPct > 0): ?>
      <div class="inv-total-row">
        <span>Subtotal</span>
        <span><?= htmlspecialchars($fmtCur($amountDue)) ?></span>
      </div>
      <?php endif; ?>
      <div class="inv-total-row total">
        <span>Total</span>
        <span><?= htmlspecialchars($fmtCur($amountDue)) ?></span>
      </div>
      <?php if ($amountPaid > 0): ?>
      <div class="inv-total-row" style="color:#3ecf8e;">
        <span>Amount Paid</span>
        <span><?= htmlspecialchars($fmtCur($amountPaid)) ?></span>
      </div>
      <?php endif; ?>
    </div>

    <!-- Status block -->
    <div class="inv-status-block" style="border-color:<?= $statusColor ?>;background:<?= $statusColor ?>11;">
      <div class="status-title" style="color:<?= $statusColor ?>;"><?= htmlspecialchars($statusLabel) ?></div>
      <?php if ($statusNote): ?>
        <div class="status-note" style="color:<?= $statusColor ?>cc;"><?= htmlspecialchars($statusNote) ?></div>
      <?php endif; ?>
    </div>

    <!-- Gateway reference -->
    <?php if ($pay['gateway_ref']): ?>
    <div class="inv-ref-row">
      Payment reference: <span><?= htmlspecialchars($pay['gateway_ref']) ?></span>
    </div>
    <?php endif; ?>

    <!-- Notes -->
    <?php if ($pay['notes']): ?>
    <div style="margin-top:20px;">
      <div class="inv-notes-label">Notes</div>
      <div class="inv-notes"><?= htmlspecialchars($pay['notes']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="inv-footer">
      <?= htmlspecialchars($invoiceNo) ?> · Generated by <?= htmlspecialchars($appName) ?><br>
      This document serves as a record of services provided.
    </div>

  </div><!-- /inv-body -->
</div><!-- /invoice-wrap -->

<?php if ($download): ?>
<script>window.addEventListener('load', () => window.print());</script>
<?php endif; ?>

</body>
</html>
