<?php
/**
 * Serenity Spaces — HTTP Security Headers
 *
 * Only sent when Production Mode is enabled. During setup mode these headers
 * are deliberately omitted so that sites without SSL don't trigger HSTS/CSP
 * errors that break the admin interface.
 *
 * Include this file at the top of every public-facing PHP page and API endpoint,
 * AFTER db/connection.php is loaded (requires getSetting()).
 *
 * CSP nonce is generated unconditionally so templates can always reference
 * $GLOBALS['csp_nonce'] — in dev mode the nonce is set but no CSP header is sent,
 * so inline scripts still execute normally without a Content-Security-Policy.
 */
/**
 * Timing-safe CSRF token validation.
 * Requires a non-empty session token and a hash_equals match.
 * Use everywhere — one pattern, one location to audit.
 */
if (!function_exists('validate_csrf')) {
    function validate_csrf(string $token): bool {
        return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!defined('SECURITY_HEADERS_INCLUDED')) {
    define('SECURITY_HEADERS_INCLUDED', true);

    // Generate a per-request nonce for inline scripts.
    // Available to all templates as $GLOBALS['csp_nonce'].
    $cspNonce = base64_encode(random_bytes(16));
    $GLOBALS['csp_nonce'] = $cspNonce;

    if (function_exists('getSetting') && getSetting('production_mode', '0') === '1') {

        // Redirect plain HTTP to HTTPS before sending any other headers.
        if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $uri  = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: https://' . $host . $uri, true, 301);
            exit;
        }

        // Enforce HTTPS for 1 year; include subdomains
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

        // Prevent MIME-type sniffing
        header('X-Content-Type-Options: nosniff');

        // Deny framing (clickjacking protection) — also covered by frame-ancestors in CSP
        header('X-Frame-Options: DENY');

        // Limit Referer information sent to third parties
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Restrict access to sensitive browser features
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        // Content Security Policy
        // script-src: same-origin files + nonce-gated inline blocks only — no unsafe-inline
        // style-src:  unsafe-inline required for style="..." attributes; nonce covers <style> blocks.
        //             CSS injection is low-severity compared to script injection — script-src
        //             remains strict with nonce-only enforcement.
        // img-src:    blob: for canvas/avatar previews; data: for base64 thumbnails
        //             external hosts for media recommendation covers (TMDB, Open Library, Google Books)
        // media-src:  blob: for WebRTC audio/video streams and voice note playback
        // connect-src: wss: included for any future WebSocket upgrade path
        // frame-ancestors: replaces X-Frame-Options for CSP-aware browsers
        header(
            "Content-Security-Policy: " .
            "default-src 'self'; " .
            "script-src 'self' 'nonce-{$cspNonce}'; " .
            "style-src 'self' 'unsafe-inline'; " .
            "img-src 'self' data: blob: https://image.tmdb.org https://covers.openlibrary.org https://books.google.com https://archive.org https://*.archive.org; " .
            "media-src 'self' blob:; " .
            "connect-src 'self' wss:; " .
            "font-src 'self'; " .
            "object-src 'none'; " .
            "base-uri 'self'; " .
            "form-action 'self'; " .
            "frame-ancestors 'none';"
        );
    }
}
