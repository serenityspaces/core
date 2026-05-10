<?php
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../db/audit.php';

header('Content-Type: application/json');

// Only accept POST — GET logout is a CSRF attack surface
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'logout') {
    // Validate CSRF token before destroying session
    $token = $_POST['csrf_token'] ?? '';
    if (!validate_csrf($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token invalid']);
        exit;
    }
    $practId = $_SESSION['practitioner_id'] ?? null;
    $pdo = getDB();
    audit_log($pdo, 'auth.logout', ['practitioner_id' => $practId ? (int)$practId : null, 'entity_type' => 'practitioner', 'entity_id' => $practId ? (int)$practId : null]);
    session_destroy();
    header('Location: /login.php');
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
