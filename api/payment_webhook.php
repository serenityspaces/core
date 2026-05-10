<?php
/**
 * Unified payment webhook receiver
 *
 * Inbound URL pattern:
 *   /api/payment_webhook.php?gateway={stripe|paypal|square|btcpay}&practitioner_id={N}
 *
 * Every webhook event is verified against the practitioner's stored signing
 * secret for that gateway, recorded in payment_webhook_events for audit, and
 * routed to the matching booking_payment row by booking_token.
 *
 * Booking token routing — each gateway carries the platform's booking_token
 * differently:
 *   Stripe:  metadata.booking_token  on the Checkout Session / PaymentIntent
 *   PayPal:  invoice_id OR custom_id on the Order
 *   Square:  reference_id            on the Payment
 *   BTCPay:  metadata.orderId        on the Invoice (set to booking_token)
 *
 * On a verified successful-payment event we mark the booking_payment as paid,
 * fill amount_paid, set paid_at, and email the client a confirmation. The
 * practitioner does NOT need to manually mark these — the whole point of
 * Advanced mode.
 *
 * No CSRF: webhooks come from the provider, not the browser. The provider's
 * signed payload IS the auth.
 */

require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_once __DIR__ . '/../db/audit.php';

header('Content-Type: application/json');

$pdo     = getDB();
$gateway = $_GET['gateway']         ?? '';
$pid     = (int)($_GET['practitioner_id'] ?? 0);
$rawBody = file_get_contents('php://input');

if (!in_array($gateway, ['stripe','paypal','square','btcpay'], true) || $pid <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad request']);
    exit;
}

// ── Decryption helper (matches api/payments.php) ──
function whEncryptKey(): string {
    if (defined('PAYMENT_ENCRYPT_KEY') && PAYMENT_ENCRYPT_KEY !== '') return PAYMENT_ENCRYPT_KEY;
    if (defined('SMTP_ENCRYPT_KEY')    && SMTP_ENCRYPT_KEY !== '')    return SMTP_ENCRYPT_KEY;
    return '';
}
function whDecrypt(string $stored): string {
    if ($stored === '') return '';
    $bin = base64_decode($stored, true);
    if ($bin === false || strlen($bin) < 17) return '';
    $iv = substr($bin, 0, 16);
    $ct = substr($bin, 16);
    $key = whEncryptKey();
    if ($key === '') return '';
    $hex = pack('H*', $key);
    $plain = openssl_decrypt($ct, 'aes-256-cbc', $hex, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

// ── Load gateway config ──
$cfg = $pdo->prepare('SELECT * FROM practitioner_payment_gateways WHERE practitioner_id = ? LIMIT 1');
$cfg->execute([$pid]);
$gw = $cfg->fetch(PDO::FETCH_ASSOC);

if (!$gw) {
    http_response_code(404);
    echo json_encode(['error' => 'No gateway config']);
    exit;
}

// ── Per-gateway dispatch ──
$result = ['ok' => false];
try {
    switch ($gateway) {
        case 'stripe': $result = wh_handle_stripe($pdo, $pid, $gw, $rawBody); break;
        case 'paypal': $result = wh_handle_paypal($pdo, $pid, $gw, $rawBody); break;
        case 'square': $result = wh_handle_square($pdo, $pid, $gw, $rawBody); break;
        case 'btcpay': $result = wh_handle_btcpay($pdo, $pid, $gw, $rawBody); break;
    }
} catch (Throwable $e) {
    error_log('payment_webhook fatal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Webhook handler error']);
    exit;
}

http_response_code($result['status'] ?? 200);
echo json_encode(['ok' => !empty($result['ok'])]);

// ═══════════════════════════════════════════════════════════════════════
// Shared helpers
// ═══════════════════════════════════════════════════════════════════════

/**
 * Look up a booking_payment by booking_token + practitioner_id.
 * Practitioner binding prevents cross-practitioner webhook abuse: even if
 * someone forges a webhook with a known booking_token from another tenant,
 * the practitioner_id must match.
 */
function wh_find_payment(PDO $pdo, int $pid, string $bookingToken): ?array {
    if ($bookingToken === '') return null;
    $stmt = $pdo->prepare(
        'SELECT bp.id, bp.booking_id, bp.amount_due, bp.currency, bp.status, bp.gateway,
                b.guest_name, b.guest_email, b.scheduled_at,
                eu.email AS eu_email, eu.display_name AS eu_name,
                p.display_name AS pract_name
         FROM booking_payments bp
         JOIN bookings b ON b.id = bp.booking_id
         LEFT JOIN end_users eu ON eu.id = b.end_user_id
         JOIN practitioners p ON p.id = bp.practitioner_id
         WHERE b.booking_token = ? AND bp.practitioner_id = ?
         LIMIT 1'
    );
    $stmt->execute([$bookingToken, $pid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Mark a booking_payment as paid via webhook. Sends client confirmation email.
 * Idempotent — safe to call on already-paid records.
 */
function wh_mark_paid(PDO $pdo, array $pay, string $gateway, string $providerRef): void {
    if ($pay['status'] === 'paid') return;
    $stmt = $pdo->prepare(
        "UPDATE booking_payments
         SET status = 'paid',
             amount_paid = amount_due,
             gateway = ?,
             gateway_ref = ?,
             paid_at = NOW()
         WHERE id = ?"
    );
    $stmt->execute([$gateway, $providerRef, (int)$pay['id']]);

    audit_log($pdo, 'payment.webhook_paid', [
        'entity_type' => 'booking_payment',
        'entity_id'   => (int)$pay['id'],
        'context'     => ['gateway' => $gateway, 'provider_ref' => $providerRef],
    ]);

    $clientEmail = $pay['guest_email'] ?: ($pay['eu_email'] ?? '');
    $clientName  = $pay['guest_name']  ?: ($pay['eu_name']  ?? '');
    $practName   = $pay['pract_name']  ?: 'your practitioner';
    $sessionDate = $pay['scheduled_at']
        ? date('D j M Y, H:i', strtotime($pay['scheduled_at'])) . ' UTC'
        : 'your session';

    if ($clientEmail) {
        $bodyHtml = '
            <p>Hi ' . htmlspecialchars($clientName ?: 'there') . ',</p>
            <p>Your payment for your session on <strong>' . htmlspecialchars($sessionDate) . '</strong>
               with <strong>' . htmlspecialchars($practName) . '</strong> has been received and confirmed automatically.</p>
            <p>Thank you — we look forward to your session.</p>';
        sendEmail($clientEmail, $clientName, 'Payment confirmed', emailTemplate('Payment Confirmed', $bodyHtml));
    }
}

/**
 * Record a webhook event row for the audit trail. Returns false if this
 * (gateway, payload_id) pair has already been seen — idempotent guard.
 */
function wh_log_event(PDO $pdo, int $pid, string $gateway, string $payloadId,
                       ?string $eventType, ?string $bookingToken, ?int $bpId,
                       bool $sigOk, bool $processed, ?string $err): bool {
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO payment_webhook_events
            (practitioner_id, gateway, payload_id, event_type, booking_token,
             booking_payment_id, signature_ok, processed, error_message)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $pid, $gateway, $payloadId, $eventType, $bookingToken,
        $bpId, $sigOk ? 1 : 0, $processed ? 1 : 0, $err,
    ]);
    return $stmt->rowCount() > 0;
}

// ═══════════════════════════════════════════════════════════════════════
// STRIPE
// Signature header: Stripe-Signature: t=…,v1=…
// Verify per https://stripe.com/docs/webhooks/signatures using the practitioner's
// whsec_… signing secret.
// ═══════════════════════════════════════════════════════════════════════
function wh_handle_stripe(PDO $pdo, int $pid, array $gw, string $body): array {
    $secret = whDecrypt((string)($gw['stripe_webhook_secret_enc'] ?? ''));
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    if ($secret === '' || $sigHeader === '') {
        return ['ok' => false, 'status' => 400];
    }

    // Parse t=…,v1=… elements
    $t = null; $v1 = null;
    foreach (explode(',', $sigHeader) as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't')  $t  = $kv[1];
        if ($kv[0] === 'v1') $v1 = $kv[1];
    }
    if ($t === null || $v1 === null) return ['ok' => false, 'status' => 400];

    // Tolerance window — 5 minutes
    if (abs(time() - (int)$t) > 300) return ['ok' => false, 'status' => 400];

    $signedPayload = $t . '.' . $body;
    $expected = hash_hmac('sha256', $signedPayload, $secret);
    if (!hash_equals($expected, $v1)) {
        wh_log_event($pdo, $pid, 'stripe', 'unverified-' . bin2hex(random_bytes(8)), null, null, null, false, false, 'signature mismatch');
        return ['ok' => false, 'status' => 400];
    }

    $event = json_decode($body, true);
    if (!is_array($event)) return ['ok' => false, 'status' => 400];

    $eventId   = (string)($event['id']   ?? '');
    $eventType = (string)($event['type'] ?? '');
    $obj       = $event['data']['object'] ?? [];

    // Booking token via metadata. Stripe Checkout Session, PaymentIntent, and
    // Charge all carry metadata in the same place.
    $bookingToken = '';
    if (isset($obj['metadata']['booking_token'])) {
        $bookingToken = (string)$obj['metadata']['booking_token'];
    } elseif (isset($obj['payment_intent']) && is_string($obj['payment_intent']) && isset($obj['metadata'])) {
        // Some event shapes — leave empty if not present.
    }

    // Process success-shaped events only
    $isSuccess = in_array($eventType, [
        'checkout.session.completed',
        'payment_intent.succeeded',
        'charge.succeeded',
    ], true);

    $providerRef = (string)($obj['id'] ?? '');
    $payment = $bookingToken !== '' ? wh_find_payment($pdo, $pid, $bookingToken) : null;

    $bpId = $payment ? (int)$payment['id'] : null;
    $fresh = wh_log_event($pdo, $pid, 'stripe', $eventId, $eventType, $bookingToken ?: null, $bpId, true, false, null);
    if (!$fresh) return ['ok' => true]; // duplicate — already processed

    if ($isSuccess && $payment) {
        wh_mark_paid($pdo, $payment, 'stripe', $providerRef);
        $u = $pdo->prepare('UPDATE payment_webhook_events SET processed = 1 WHERE gateway = "stripe" AND payload_id = ?');
        $u->execute([$eventId]);
    }
    return ['ok' => true];
}

// ═══════════════════════════════════════════════════════════════════════
// PAYPAL
// Signature header(s): PAYPAL-TRANSMISSION-ID, PAYPAL-TRANSMISSION-TIME,
//   PAYPAL-CERT-URL, PAYPAL-AUTH-ALGO, PAYPAL-TRANSMISSION-SIG, plus webhook id.
// PayPal verification is API-call-based: POST to
// /v1/notifications/verify-webhook-signature with the headers + payload + webhook_id.
// We then trust their {verification_status: "SUCCESS"} response.
// ═══════════════════════════════════════════════════════════════════════
function wh_handle_paypal(PDO $pdo, int $pid, array $gw, string $body): array {
    $clientId     = (string)($gw['paypal_client_id'] ?? '');
    $clientSecret = whDecrypt((string)($gw['paypal_client_secret_enc'] ?? ''));
    $webhookId    = (string)($gw['paypal_webhook_id'] ?? '');
    $env          = (string)($gw['paypal_environment'] ?? 'live');
    if ($clientId === '' || $clientSecret === '' || $webhookId === '') {
        return ['ok' => false, 'status' => 400];
    }

    $apiBase = $env === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

    // OAuth token
    $tokRes = wh_curl($apiBase . '/v1/oauth2/token', 'POST',
        'grant_type=client_credentials',
        ['Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
         'Content-Type: application/x-www-form-urlencoded']);
    $tok = json_decode($tokRes['body'] ?? '', true);
    $accessToken = $tok['access_token'] ?? '';
    if ($accessToken === '') return ['ok' => false, 'status' => 400];

    $verifyPayload = json_encode([
        'auth_algo'         => $_SERVER['HTTP_PAYPAL_AUTH_ALGO']         ?? '',
        'cert_url'          => $_SERVER['HTTP_PAYPAL_CERT_URL']          ?? '',
        'transmission_id'   => $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID']   ?? '',
        'transmission_sig'  => $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG']  ?? '',
        'transmission_time' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? '',
        'webhook_id'        => $webhookId,
        'webhook_event'     => json_decode($body, true),
    ]);

    $verRes = wh_curl($apiBase . '/v1/notifications/verify-webhook-signature', 'POST',
        $verifyPayload,
        ['Authorization: Bearer ' . $accessToken,
         'Content-Type: application/json']);
    $ver = json_decode($verRes['body'] ?? '', true);
    $sigOk = (($ver['verification_status'] ?? '') === 'SUCCESS');

    $event = json_decode($body, true) ?: [];
    $eventId   = (string)($event['id']          ?? '');
    $eventType = (string)($event['event_type']  ?? '');
    $resource  = $event['resource'] ?? [];

    // PayPal carries our token in invoice_id or custom_id depending on flow.
    $bookingToken = (string)($resource['invoice_id'] ?? $resource['custom_id'] ?? '');
    if ($bookingToken === '' && isset($resource['purchase_units'][0])) {
        $pu = $resource['purchase_units'][0];
        $bookingToken = (string)($pu['invoice_id'] ?? $pu['custom_id'] ?? '');
    }

    if (!$sigOk) {
        wh_log_event($pdo, $pid, 'paypal', $eventId ?: 'unverified-' . bin2hex(random_bytes(8)),
            $eventType, $bookingToken ?: null, null, false, false, 'signature mismatch');
        return ['ok' => false, 'status' => 400];
    }

    $isSuccess = in_array($eventType, [
        'PAYMENT.CAPTURE.COMPLETED',
        'CHECKOUT.ORDER.APPROVED',
        'PAYMENT.SALE.COMPLETED',
    ], true);

    $providerRef = (string)($resource['id'] ?? '');
    $payment = $bookingToken !== '' ? wh_find_payment($pdo, $pid, $bookingToken) : null;
    $bpId = $payment ? (int)$payment['id'] : null;

    $fresh = wh_log_event($pdo, $pid, 'paypal', $eventId, $eventType, $bookingToken ?: null, $bpId, true, false, null);
    if (!$fresh) return ['ok' => true];

    if ($isSuccess && $payment) {
        wh_mark_paid($pdo, $payment, 'paypal', $providerRef);
        $u = $pdo->prepare('UPDATE payment_webhook_events SET processed = 1 WHERE gateway = "paypal" AND payload_id = ?');
        $u->execute([$eventId]);
    }
    return ['ok' => true];
}

// ═══════════════════════════════════════════════════════════════════════
// SQUARE
// Signature header: x-square-hmacsha256-signature
// Verify per https://developer.squareup.com/docs/webhooks/step3validate
//   expected = base64(hmac_sha256(notification_url + body, signature_key))
// Where notification_url is the full URL the webhook was sent to.
// ═══════════════════════════════════════════════════════════════════════
function wh_handle_square(PDO $pdo, int $pid, array $gw, string $body): array {
    $sigKey = whDecrypt((string)($gw['square_webhook_signature_key_enc'] ?? ''));
    $sigHeader = $_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? '';

    if ($sigKey === '' || $sigHeader === '') {
        return ['ok' => false, 'status' => 400];
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/api/payment_webhook.php';
    $url    = $scheme . '://' . $host . $uri;

    $expected = base64_encode(hash_hmac('sha256', $url . $body, $sigKey, true));

    $event = json_decode($body, true) ?: [];
    $eventId   = (string)($event['event_id'] ?? '');
    $eventType = (string)($event['type']     ?? '');
    $payment   = $event['data']['object']['payment'] ?? [];

    if (!hash_equals($expected, $sigHeader)) {
        wh_log_event($pdo, $pid, 'square', $eventId ?: 'unverified-' . bin2hex(random_bytes(8)),
            $eventType, null, null, false, false, 'signature mismatch');
        return ['ok' => false, 'status' => 400];
    }

    $bookingToken = (string)($payment['reference_id'] ?? $payment['note'] ?? '');
    $isSuccess    = ($eventType === 'payment.updated' && (($payment['status'] ?? '') === 'COMPLETED'))
                 || ($eventType === 'payment.created' && (($payment['status'] ?? '') === 'COMPLETED'));

    $providerRef = (string)($payment['id'] ?? '');
    $bp = $bookingToken !== '' ? wh_find_payment($pdo, $pid, $bookingToken) : null;
    $bpId = $bp ? (int)$bp['id'] : null;

    $fresh = wh_log_event($pdo, $pid, 'square', $eventId, $eventType, $bookingToken ?: null, $bpId, true, false, null);
    if (!$fresh) return ['ok' => true];

    if ($isSuccess && $bp) {
        wh_mark_paid($pdo, $bp, 'square', $providerRef);
        $u = $pdo->prepare('UPDATE payment_webhook_events SET processed = 1 WHERE gateway = "square" AND payload_id = ?');
        $u->execute([$eventId]);
    }
    return ['ok' => true];
}

// ═══════════════════════════════════════════════════════════════════════
// BTCPAY SERVER
// Signature header: BTCPay-Sig: sha256=<hex>
// Verify: hmac_sha256(body, webhook_secret)
// Per https://docs.btcpayserver.org/Development/Greenfield/#webhooks
// ═══════════════════════════════════════════════════════════════════════
function wh_handle_btcpay(PDO $pdo, int $pid, array $gw, string $body): array {
    $secret = whDecrypt((string)($gw['btcpay_webhook_secret_enc'] ?? ''));
    $sigHeader = $_SERVER['HTTP_BTCPAY_SIG'] ?? '';

    if ($secret === '' || $sigHeader === '') return ['ok' => false, 'status' => 400];

    $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);

    $event = json_decode($body, true) ?: [];
    $eventId   = (string)($event['deliveryId'] ?? $event['invoiceId'] ?? '');
    $eventType = (string)($event['type']       ?? '');
    $bookingToken = (string)($event['metadata']['orderId'] ?? '');

    if (!hash_equals($expected, $sigHeader)) {
        wh_log_event($pdo, $pid, 'btcpay', $eventId ?: 'unverified-' . bin2hex(random_bytes(8)),
            $eventType, $bookingToken ?: null, null, false, false, 'signature mismatch');
        return ['ok' => false, 'status' => 400];
    }

    // BTCPay event types that signal payment finality:
    //   InvoiceSettled, InvoicePaymentSettled, InvoiceProcessing
    $isSuccess = in_array($eventType, [
        'InvoiceSettled',
        'InvoicePaymentSettled',
    ], true);

    $providerRef = (string)($event['invoiceId'] ?? '');
    $payment = $bookingToken !== '' ? wh_find_payment($pdo, $pid, $bookingToken) : null;
    $bpId = $payment ? (int)$payment['id'] : null;

    $fresh = wh_log_event($pdo, $pid, 'btcpay', $eventId, $eventType, $bookingToken ?: null, $bpId, true, false, null);
    if (!$fresh) return ['ok' => true];

    if ($isSuccess && $payment) {
        wh_mark_paid($pdo, $payment, 'bitcoin', $providerRef);
        $u = $pdo->prepare('UPDATE payment_webhook_events SET processed = 1 WHERE gateway = "btcpay" AND payload_id = ?');
        $u->execute([$eventId]);
    }
    return ['ok' => true];
}

// ═══════════════════════════════════════════════════════════════════════
// Tiny curl helper used by PayPal verification
// ═══════════════════════════════════════════════════════════════════════
function wh_curl(string $url, string $method, string $body, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $resp === false ? '' : $resp, 'code' => $code];
}
