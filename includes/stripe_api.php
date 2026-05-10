<?php
/**
 * Tiny Stripe API helper — only the calls SerenitySpaces needs.
 * No SDK dependency: vanilla curl. Stripe API stays remarkably stable.
 *
 * Public function: stripe_create_checkout_session()
 *   Returns the hosted checkout URL the client should be redirected to.
 *   Embeds booking_token in metadata so our webhook can route the result.
 */

if (!function_exists('stripe_create_checkout_session')) {

/**
 * @param string $secretKey      Practitioner's Stripe secret key (sk_live_… / sk_test_…)
 * @param string $bookingToken   Our 48-hex booking token — webhook routes by this
 * @param int    $amountMinor    Amount in the smallest currency unit (cents/pence)
 * @param string $currency       Lowercase ISO-4217 (gbp, usd, eur, …)
 * @param string $description    What the client sees on the Stripe checkout page
 * @param string $successUrl     Where Stripe sends the client on success
 * @param string $cancelUrl      Where Stripe sends the client on abandon
 * @param string $clientEmail    Pre-fill email (optional — empty string skips)
 * @return array  ['ok' => bool, 'url' => string, 'session_id' => string, 'error' => string]
 */
function stripe_create_checkout_session(
    string $secretKey,
    string $bookingToken,
    int $amountMinor,
    string $currency,
    string $description,
    string $successUrl,
    string $cancelUrl,
    string $clientEmail = ''
): array {
    $params = [
        'mode'                                => 'payment',
        'success_url'                         => $successUrl,
        'cancel_url'                          => $cancelUrl,
        'metadata[booking_token]'             => $bookingToken,
        'payment_intent_data[metadata][booking_token]' => $bookingToken,
        'line_items[0][quantity]'             => '1',
        'line_items[0][price_data][currency]' => strtolower($currency),
        'line_items[0][price_data][unit_amount]' => (string)$amountMinor,
        'line_items[0][price_data][product_data][name]' => $description,
    ];
    if ($clientEmail !== '') {
        $params['customer_email'] = $clientEmail;
    }

    $body = http_build_query($params);

    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/x-www-form-urlencoded',
            'Stripe-Version: 2024-06-20',
        ],
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Network error contacting Stripe'];
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Bad response from Stripe'];
    }

    if ($code >= 400 || isset($data['error'])) {
        $err = $data['error']['message'] ?? 'Stripe error';
        return ['ok' => false, 'error' => $err];
    }

    if (empty($data['url']) || empty($data['id'])) {
        return ['ok' => false, 'error' => 'Stripe response missing url'];
    }

    return ['ok' => true, 'url' => (string)$data['url'], 'session_id' => (string)$data['id']];
}

}
