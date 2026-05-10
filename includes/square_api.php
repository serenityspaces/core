<?php
/**
 * Tiny Square Online Checkout (Payment Links) helper.
 * No SDK dependency: vanilla curl.
 *
 * Public function:
 *   square_create_checkout()  — creates a Quick Pay payment link, returns the
 *                               hosted checkout URL.
 *
 * Routing — we use the order form (not quick_pay) so we can set
 * `order.reference_id` to the booking_token. Square copies that reference_id
 * onto the resulting Payment row, which our webhook reads from
 * payment.reference_id to look up the booking_payment.
 */

if (!function_exists('square_create_checkout')) {

/**
 * @param string $accessToken    Decrypted Square access token (production or sandbox)
 * @param string $environment    'production' or 'sandbox'
 * @param string $locationId     Square location ID (L…)
 * @param string $bookingToken   Booking token — copied to order.reference_id
 * @param int    $amountMinor    Amount in smallest currency unit (cents/pence)
 * @param string $currency       3-letter ISO code (uppercase)
 * @param string $description    Line-item name shown on the checkout page
 * @param string $redirectUrl    Where Square sends the buyer after payment
 * @return array  ['ok' => bool, 'url' => string, 'order_id' => string, 'error' => string]
 */
function square_create_checkout(
    string $accessToken,
    string $environment,
    string $locationId,
    string $bookingToken,
    int $amountMinor,
    string $currency,
    string $description,
    string $redirectUrl
): array {
    $apiBase = $environment === 'sandbox'
        ? 'https://connect.squareupsandbox.com'
        : 'https://connect.squareup.com';

    // Idempotency — Square requires a unique key per request. Per booking is
    // safe: a double-click returns the same payment link.
    $idempotencyKey = 'ss-' . $bookingToken;

    $body = json_encode([
        'idempotency_key'  => $idempotencyKey,
        'order' => [
            'location_id'  => $locationId,
            'reference_id' => $bookingToken,
            'line_items'   => [[
                'name'             => mb_substr($description, 0, 511),
                'quantity'         => '1',
                'base_price_money' => [
                    'amount'   => $amountMinor,
                    'currency' => strtoupper($currency),
                ],
            ]],
        ],
        'checkout_options' => [
            'redirect_url'        => $redirectUrl,
            'ask_for_shipping_address' => false,
        ],
        'payment_note' => 'Booking ' . $bookingToken,
    ], JSON_UNESCAPED_SLASHES);

    $ch = curl_init($apiBase . '/v2/online-checkout/payment-links');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Square-Version: 2024-06-04',
        ],
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Network error contacting Square'];
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Bad response from Square'];
    }
    if ($code >= 400 || isset($data['errors'])) {
        $err = $data['errors'][0]['detail'] ?? ($data['errors'][0]['code'] ?? 'Square error');
        return ['ok' => false, 'error' => $err];
    }
    $link = $data['payment_link'] ?? null;
    if (!$link || empty($link['url'])) {
        return ['ok' => false, 'error' => 'Square response missing payment_link'];
    }

    return [
        'ok'       => true,
        'url'      => (string)$link['url'],
        'order_id' => (string)($link['order_id'] ?? ''),
    ];
}

}
