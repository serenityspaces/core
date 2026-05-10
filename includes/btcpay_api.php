<?php
/**
 * Tiny BTCPay Server Greenfield API helper.
 * No SDK dependency: vanilla curl.
 *
 * Public function:
 *   btcpay_create_invoice()  — creates an invoice, returns checkoutLink URL.
 *
 * Routing — invoices set metadata.orderId to the booking_token. BTCPay's
 * webhook events carry metadata through unchanged, which our webhook handler
 * reads to route the result back to the right booking_payment row.
 */

if (!function_exists('btcpay_create_invoice')) {

/**
 * @param string $serverUrl      BTCPay Server base URL (e.g. https://btcpay.example.com)
 * @param string $apiKey         Decrypted BTCPay API key with invoice scopes
 * @param string $storeId        Store ID
 * @param string $bookingToken   Booking token — copied to metadata.orderId
 * @param float  $amount         Amount in the chosen currency
 * @param string $currency       Display currency code (USD, GBP, BTC, SATS, …) — BTCPay quotes BTC at the spot rate
 * @param string $description    Buyer-facing description
 * @param string $redirectUrl    Where BTCPay sends the buyer after the invoice is paid
 * @return array  ['ok' => bool, 'url' => string, 'invoice_id' => string, 'error' => string]
 */
function btcpay_create_invoice(
    string $serverUrl,
    string $apiKey,
    string $storeId,
    string $bookingToken,
    float $amount,
    string $currency,
    string $description,
    string $redirectUrl
): array {
    $base = rtrim($serverUrl, '/');
    if ($base === '' || $storeId === '' || $apiKey === '') {
        return ['ok' => false, 'error' => 'BTCPay configuration incomplete'];
    }

    $body = json_encode([
        'amount'   => number_format($amount, 8, '.', ''),  // BTCPay accepts up to 8 decimals
        'currency' => strtoupper($currency),
        'metadata' => [
            'orderId'           => $bookingToken,
            'itemDesc'          => mb_substr($description, 0, 255),
            'orderUrl'          => $redirectUrl,
        ],
        'checkout' => [
            'redirectURL'              => $redirectUrl,
            'redirectAutomatically'    => true,
            'requiresRefundEmail'      => false,
            'speedPolicy'              => 'MediumSpeed',  // 1 confirmation
        ],
    ], JSON_UNESCAPED_SLASHES);

    $url = $base . '/api/v1/stores/' . urlencode($storeId) . '/invoices';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: token ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Network error contacting BTCPay Server'];
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Bad response from BTCPay'];
    }
    if ($code >= 400) {
        // BTCPay errors come back as either {code, message} or array of validation errors.
        $msg = '';
        if (isset($data['message']))      $msg = (string)$data['message'];
        elseif (isset($data[0]['message'])) $msg = (string)$data[0]['message'];
        return ['ok' => false, 'error' => $msg !== '' ? $msg : 'BTCPay error'];
    }
    if (empty($data['id']) || empty($data['checkoutLink'])) {
        return ['ok' => false, 'error' => 'BTCPay response missing checkoutLink'];
    }

    return [
        'ok'         => true,
        'url'        => (string)$data['checkoutLink'],
        'invoice_id' => (string)$data['id'],
    ];
}

}
