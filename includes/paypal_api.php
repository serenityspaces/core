<?php
/**
 * Tiny PayPal Orders v2 helper — only the calls SerenitySpaces needs.
 * No SDK dependency: vanilla curl. Mirrors the shape of stripe_api.php so the
 * checkout flow in api/payments.php is uniform across providers.
 *
 * Public functions:
 *   paypal_create_order()  — creates a v2 Order, returns the approval URL
 *
 * The order's invoice_id field is set to the booking_token. PayPal carries
 * that through to PAYMENT.CAPTURE.COMPLETED webhook events as
 * resource.invoice_id, which our webhook handler reads to route the result
 * back to the right booking_payment row.
 *
 * Auto-capture: we use intent=CAPTURE so PayPal captures the funds the
 * instant the buyer approves the order. No separate capture call needed.
 */

if (!function_exists('paypal_create_order')) {

/**
 * @param string $clientId       PayPal REST API client ID
 * @param string $clientSecret   PayPal REST API client secret (decrypted)
 * @param string $environment    'live' or 'sandbox'
 * @param string $bookingToken   Booking token — copied to invoice_id for webhook routing
 * @param float  $amount         Decimal amount (e.g. 50.00). PayPal accepts decimal strings.
 * @param string $currency       3-letter ISO code (uppercase: USD, GBP, EUR, …)
 * @param string $description    What the buyer sees on PayPal
 * @param string $returnUrl      Where PayPal sends the buyer after approval
 * @param string $cancelUrl      Where PayPal sends the buyer if they bail out
 * @return array  ['ok' => bool, 'url' => string, 'order_id' => string, 'error' => string]
 */
function paypal_create_order(
    string $clientId,
    string $clientSecret,
    string $environment,
    string $bookingToken,
    float $amount,
    string $currency,
    string $description,
    string $returnUrl,
    string $cancelUrl
): array {
    $apiBase = $environment === 'sandbox'
        ? 'https://api-m.sandbox.paypal.com'
        : 'https://api-m.paypal.com';

    // ── 1. OAuth token exchange ──
    $tok = paypal_curl(
        $apiBase . '/v1/oauth2/token',
        'POST',
        'grant_type=client_credentials',
        [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ]
    );
    if ($tok['code'] >= 400) {
        return ['ok' => false, 'error' => 'PayPal authentication failed (check Client ID and Secret)'];
    }
    $tokData = json_decode($tok['body'], true);
    $accessToken = $tokData['access_token'] ?? '';
    if ($accessToken === '') {
        return ['ok' => false, 'error' => 'PayPal returned no access_token'];
    }

    // ── 2. Create order ──
    // Format amount: 2 decimal places for most currencies. PayPal validates
    // currency-specific decimals server-side, so this is a sane default.
    $body = json_encode([
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'invoice_id'  => $bookingToken,
            'custom_id'   => $bookingToken,  // belt + braces: PayPal exposes both fields on the webhook
            'description' => mb_substr($description, 0, 127),
            'amount'      => [
                'currency_code' => strtoupper($currency),
                'value'         => number_format($amount, 2, '.', ''),
            ],
        ]],
        'application_context' => [
            'return_url'           => $returnUrl,
            'cancel_url'           => $cancelUrl,
            'shipping_preference'  => 'NO_SHIPPING',
            'user_action'          => 'PAY_NOW',
            'brand_name'           => 'SerenitySpaces',
        ],
    ], JSON_UNESCAPED_SLASHES);

    $res = paypal_curl(
        $apiBase . '/v2/checkout/orders',
        'POST',
        $body,
        [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            // Idempotency — replay-safe if the user double-clicks.
            // Per-booking key, so duplicate calls within 6 hours return the same order.
            'PayPal-Request-Id: ss-' . $bookingToken,
        ]
    );
    if ($res['code'] >= 400) {
        $err  = json_decode($res['body'], true);
        $msg  = $err['message'] ?? 'PayPal order creation failed';
        return ['ok' => false, 'error' => $msg];
    }
    $data = json_decode($res['body'], true);
    if (!is_array($data) || empty($data['id']) || empty($data['links'])) {
        return ['ok' => false, 'error' => 'Bad response from PayPal'];
    }

    // Find the approve link
    $approveUrl = '';
    foreach ($data['links'] as $link) {
        if (($link['rel'] ?? '') === 'approve' || ($link['rel'] ?? '') === 'payer-action') {
            $approveUrl = (string)$link['href'];
            break;
        }
    }
    if ($approveUrl === '') {
        return ['ok' => false, 'error' => 'PayPal response missing approval URL'];
    }

    return [
        'ok'       => true,
        'url'      => $approveUrl,
        'order_id' => (string)$data['id'],
    ];
}

function paypal_curl(string $url, string $method, string $body, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $resp === false ? '' : $resp, 'code' => (int)$code];
}

}
