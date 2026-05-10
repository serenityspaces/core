<?php
/**
 * Serenity Spaces — DC (Distributed/Alternative Location) Auth Helpers
 *
 * HMAC-SHA256 request signing and verification for inter-server communication
 * between the Primary server and Alternative Location servers.
 *
 * Signing scheme:
 *   X-DC-Timestamp: Unix timestamp (seconds)
 *   X-DC-Nonce:     Random 16-byte hex string
 *   X-DC-Signature: HMAC-SHA256(shared_secret, "timestamp:nonce:body_sha256")
 *
 * Replay protection: requests older than DC_REQUEST_TTL seconds are rejected.
 */

define('DC_REQUEST_TTL', 30); // seconds — reject requests outside this window

/**
 * Generate HMAC-SHA256 auth headers for an outgoing DC request.
 *
 * @param string $body        Raw request body (JSON string)
 * @param string $sharedSecret
 * @return array ['X-DC-Timestamp' => ..., 'X-DC-Nonce' => ..., 'X-DC-Signature' => ...]
 */
function dc_sign_request(string $body, string $sharedSecret): array
{
    $timestamp = (string)time();
    $nonce     = bin2hex(random_bytes(16));
    $bodyHash  = hash('sha256', $body);
    $message   = $timestamp . ':' . $nonce . ':' . $bodyHash;
    $signature = hash_hmac('sha256', $message, $sharedSecret);

    return [
        'X-DC-Timestamp' => $timestamp,
        'X-DC-Nonce'     => $nonce,
        'X-DC-Signature' => $signature,
    ];
}

/**
 * Verify an incoming DC request's HMAC signature.
 * Call this at the top of api/dc.php before processing any action.
 *
 * @param string $body          Raw request body (read before calling this)
 * @param array  $headers       Associative array of request headers (lowercased keys)
 * @param string $sharedSecret
 * @return bool true if valid, false if invalid/expired/tampered
 */
function dc_verify_request(string $body, array $headers, string $sharedSecret): bool
{
    $timestamp = $headers['x-dc-timestamp'] ?? '';
    $nonce     = $headers['x-dc-nonce']     ?? '';
    $signature = $headers['x-dc-signature'] ?? '';

    if ($timestamp === '' || $nonce === '' || $signature === '') {
        return false;
    }

    // Replay protection
    $ts = (int)$timestamp;
    if (abs(time() - $ts) > DC_REQUEST_TTL) {
        return false;
    }

    $bodyHash  = hash('sha256', $body);
    $message   = $timestamp . ':' . $nonce . ':' . $bodyHash;
    $expected  = hash_hmac('sha256', $message, $sharedSecret);

    return hash_equals($expected, $signature);
}

/**
 * Read all incoming HTTP headers in a normalised lowercase-key format.
 * Compatible with Apache and cPanel's PHP CGI mode.
 */
function dc_get_headers(): array
{
    if (function_exists('getallheaders')) {
        $raw = getallheaders();
    } else {
        $raw = [];
        foreach ($_SERVER as $key => $val) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $raw[$name] = $val;
            }
        }
    }
    $out = [];
    foreach ($raw as $k => $v) {
        $out[strtolower($k)] = $v;
    }
    return $out;
}

/**
 * Send a signed POST request to an Alternative Location server.
 * Returns decoded JSON response array, or null on failure.
 *
 * @param string $dcBaseUrl    Base URL of the target server (no trailing slash)
 * @param string $endpoint     Path, e.g. '/api/dc.php'
 * @param array  $payload      Data to JSON-encode and POST
 * @param string $sharedSecret
 * @param int    $timeout      cURL timeout in seconds
 * @return array|null
 */
function dc_post(string $dcBaseUrl, string $endpoint, array $payload, string $sharedSecret, int $timeout = 10): ?array
{
    $body    = json_encode($payload);
    $headers = dc_sign_request($body, $sharedSecret);
    $url     = rtrim($dcBaseUrl, '/') . $endpoint;

    $curlHeaders = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $curlHeaders[] = $k . ': ' . $v;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $curlHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}
