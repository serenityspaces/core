<?php
/**
 * Serenity Spaces — Session Inactivity Timeout
 *
 * Include after session_start() and auth guard on authenticated pages.
 *
 * Practitioners: 15-minute inactivity timeout (PHI access)
 * Clients:       30-minute inactivity timeout
 *
 * HIPAA §164.312(a)(2)(iii) — Automatic Logoff
 */

if (!defined('SESSION_TIMEOUT_INCLUDED')) {
    define('SESSION_TIMEOUT_INCLUDED', true);

    $PRACTITIONER_TIMEOUT = 15 * 60;  // 15 minutes
    $CLIENT_TIMEOUT       = 30 * 60;  // 30 minutes

    $now = time();

    if (!empty($_SESSION['practitioner_id'])) {
        $timeout = $PRACTITIONER_TIMEOUT;
        $loginUrl = '/login.php';
    } elseif (!empty($_SESSION['end_user_id'])) {
        $timeout = $CLIENT_TIMEOUT;
        $loginUrl = '/login.php?tab=client';
    } else {
        $timeout   = 0;
        $loginUrl  = '/login.php';
    }

    if ($timeout > 0) {
        if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > $timeout) {
            session_destroy();
            header('Location: ' . $loginUrl . (str_contains($loginUrl, '?') ? '&' : '?') . 'timeout=1');
            exit;
        }
        $_SESSION['last_activity'] = $now;
    }
}
