<?php
/**
 * Serenity Spaces — Payments API
 *
 * Practitioner (session required):
 *   GET  action=financial_summary   — summary cards + monthly chart data
 *   GET  action=payment_records     — paginated payment records (filterable)
 *   GET  action=export_csv          — CSV download of all payment records
 *   GET  action=get_rate            — fetch practitioner's configured rate
 *   POST action=save_rate           — save session rate
 *   GET  action=get_gateways        — fetch configured payment gateways
 *   POST action=save_gateways       — save gateway config
 *   GET  action=list_campaigns      — list discount campaigns
 *   POST action=save_campaign       — create or update a discount campaign
 *   POST action=delete_campaign     — delete a discount campaign
 *
 * Public (no session, booking token required):
 *   GET  action=booking_rate        — get rate + active discount for a booking token
 *   POST action=record_payment      — record a payment intent/confirmation
 */
session_set_cookie_params(['secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
session_start();
require_once __DIR__ . '/../db/connection.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json');

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── CSRF helper ────────────────────────────────────────────────────
function verifyCsrf(): void {
    if (!validate_csrf($_GET['csrf'] ?? $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF token mismatch']);
        exit;
    }
}

// ── Encryption helpers (AES-256-GCM; enc: prefix = legacy CBC) ───────
// Payment credentials use PAYMENT_ENCRYPT_KEY when available (separate from PHI key).
// Falls back to SMTP_ENCRYPT_KEY for installs that predate key separation.
function _paymentKey(): string {
    if (defined('PAYMENT_ENCRYPT_KEY') && PAYMENT_ENCRYPT_KEY !== '') return PAYMENT_ENCRYPT_KEY;
    if (defined('SMTP_ENCRYPT_KEY')    && SMTP_ENCRYPT_KEY    !== '') return SMTP_ENCRYPT_KEY;
    return '';
}

function encryptValue(string $value): string {
    $hexKey = _paymentKey();
    if ($hexKey === '') return $value;
    $key    = hex2bin($hexKey);
    $nonce  = random_bytes(12);
    $tag    = '';
    $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) return $value;
    return 'enc2:' . base64_encode($nonce . $tag . $cipher);
}

function decryptValue(string $stored): string {
    $hexKey = _paymentKey();
    // GCM (current)
    if (str_starts_with($stored, 'enc2:')) {
        if ($hexKey === '') return '';
        try {
            $decoded = base64_decode(substr($stored, 5));
            if (strlen($decoded) < 29) return '';
            $key    = hex2bin($hexKey);
            $nonce  = substr($decoded, 0, 12);
            $tag    = substr($decoded, 12, 16);
            $cipher = substr($decoded, 28);
            $plain  = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            // If decryption failed with PAYMENT_ENCRYPT_KEY, retry with SMTP_ENCRYPT_KEY
            // (handles values encrypted before key separation was introduced)
            if ($plain === false && defined('PAYMENT_ENCRYPT_KEY') && PAYMENT_ENCRYPT_KEY !== ''
                && defined('SMTP_ENCRYPT_KEY') && SMTP_ENCRYPT_KEY !== '') {
                $fallback = hex2bin(SMTP_ENCRYPT_KEY);
                $plain = openssl_decrypt($cipher, 'aes-256-gcm', $fallback, OPENSSL_RAW_DATA, $nonce, $tag);
            }
            return $plain !== false ? $plain : '';
        } catch (Throwable) { return ''; }
    }
    // CBC legacy
    if (str_starts_with($stored, 'enc:')) {
        if ($hexKey === '') return '';
        try {
            $decoded = base64_decode(substr($stored, 4));
            $iv      = substr($decoded, 0, 16);
            $cipher  = substr($decoded, 16);
            $plain   = openssl_decrypt($cipher, 'AES-256-CBC', hex2bin($hexKey), OPENSSL_RAW_DATA, $iv);
            return $plain !== false ? $plain : '';
        } catch (Throwable) { return ''; }
    }
    return $stored; // plaintext
}

// ── Auth check (practitioner routes) ──────────────────────────────
function requirePractitioner(): int {
    if (empty($_SESSION['practitioner_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }
    return (int)$_SESSION['practitioner_id'];
}

// ── Route: financial_summary ───────────────────────────────────────
if ($action === 'financial_summary' && $method === 'GET') {
    $pid = requirePractitioner();
    verifyCsrf();

    // This month total (paid)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_paid),0) AS total, currency
        FROM booking_payments
        WHERE practitioner_id = ? AND status = 'paid'
          AND YEAR(paid_at) = YEAR(CURDATE()) AND MONTH(paid_at) = MONTH(CURDATE())
        GROUP BY currency ORDER BY total DESC LIMIT 1
    ");
    $stmt->execute([$pid]);
    $thisMonth = $stmt->fetch(PDO::FETCH_ASSOC);

    // All time (paid)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_paid),0) AS total, currency
        FROM booking_payments
        WHERE practitioner_id = ? AND status = 'paid'
        GROUP BY currency ORDER BY total DESC LIMIT 1
    ");
    $stmt->execute([$pid]);
    $allTime = $stmt->fetch(PDO::FETCH_ASSOC);

    // Pending
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_due),0) AS total, currency
        FROM booking_payments
        WHERE practitioner_id = ? AND status = 'pending'
        GROUP BY currency ORDER BY total DESC LIMIT 1
    ");
    $stmt->execute([$pid]);
    $pending = $stmt->fetch(PDO::FETCH_ASSOC);

    // Free sessions count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM booking_payments WHERE practitioner_id = ? AND gateway = 'free'");
    $stmt->execute([$pid]);
    $freeCount = (int)$stmt->fetchColumn();

    // Monthly totals (last 12 months)
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month, COALESCE(SUM(amount_paid),0) AS total
        FROM booking_payments
        WHERE practitioner_id = ? AND status = 'paid'
          AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY month ORDER BY month ASC
    ");
    $stmt->execute([$pid]);
    $monthly = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prefer currency from actual payment records; fall back to practitioner's configured rate currency
    $currency = $allTime['currency'] ?? $thisMonth['currency'] ?? $pending['currency'] ?? null;
    if (!$currency) {
        $rateStmt = $pdo->prepare('SELECT currency FROM practitioner_rates WHERE practitioner_id = ? LIMIT 1');
        $rateStmt->execute([$pid]);
        $currency = $rateStmt->fetchColumn() ?: 'GBP';
    }

    echo json_encode([
        'ok'         => true,
        'this_month' => (float)($thisMonth['total'] ?? 0),
        'all_time'   => (float)($allTime['total'] ?? 0),
        'pending'    => (float)($pending['total'] ?? 0),
        'free_count' => $freeCount,
        'currency'   => $currency,
        'monthly'    => $monthly,
    ]);
    exit;
}

// ── Route: payment_records ─────────────────────────────────────────
if ($action === 'payment_records' && $method === 'GET') {
    $pid = requirePractitioner();
    verifyCsrf();

    $status  = $_GET['status']  ?? '';
    $gateway = $_GET['gateway'] ?? '';

    $where  = ['bp.practitioner_id = ?'];
    $params = [$pid];

    $allowedStatuses  = ['pending','paid','waived','failed','free','manual'];
    $allowedGateways  = ['paypal','stripe','bitcoin','free','manual','waived'];

    if ($status !== '' && in_array($status, $allowedStatuses, true)) {
        $where[]  = 'bp.status = ?';
        $params[] = $status;
    }
    if ($gateway !== '' && in_array($gateway, $allowedGateways, true)) {
        $where[]  = 'bp.gateway = ?';
        $params[] = $gateway;
    }

    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT
            bp.id, bp.amount_due, bp.amount_paid, bp.currency,
            bp.status, bp.gateway, bp.gateway_ref, bp.paid_at AS date,
            bp.discount_pct, bp.notes,
            r.name AS session_name,
            COALESCE(eu.display_name, b.guest_name) AS client_name
        FROM booking_payments bp
        LEFT JOIN bookings b  ON b.id  = bp.booking_id
        LEFT JOIN rooms r     ON r.id  = b.room_id
        LEFT JOIN end_users eu ON eu.id = b.end_user_id
        WHERE $whereSql
        ORDER BY bp.id DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'records' => $records]);
    exit;
}

// ── Route: export_csv ─────────────────────────────────────────────
if ($action === 'export_csv' && $method === 'GET') {
    $pid = requirePractitioner();
    verifyCsrf();

    $stmt = $pdo->prepare("
        SELECT
            bp.id,
            COALESCE(eu.display_name, b.guest_name) AS client_name,
            r.name AS session_name,
            bp.amount_due, bp.amount_paid, bp.currency,
            bp.discount_pct, bp.status, bp.gateway,
            bp.gateway_ref, bp.paid_at, bp.notes
        FROM booking_payments bp
        LEFT JOIN bookings b   ON b.id  = bp.booking_id
        LEFT JOIN rooms r      ON r.id  = b.room_id
        LEFT JOIN end_users eu ON eu.id = b.end_user_id
        WHERE bp.practitioner_id = ?
        ORDER BY bp.id DESC
    ");
    $stmt->execute([$pid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="payments.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Client','Session','Amount Due','Amount Paid','Currency','Discount %','Status','Gateway','Gateway Ref','Paid At','Notes']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['client_name'], $r['session_name'],
            $r['amount_due'], $r['amount_paid'], $r['currency'],
            $r['discount_pct'], $r['status'], $r['gateway'],
            $r['gateway_ref'], $r['paid_at'], $r['notes'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Route: get_rate ────────────────────────────────────────────────
if ($action === 'get_rate' && $method === 'GET') {
    $pid = requirePractitioner();
    verifyCsrf();

    $stmt = $pdo->prepare('SELECT * FROM practitioner_rates WHERE practitioner_id = ? LIMIT 1');
    $stmt->execute([$pid]);
    $rate = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'rate' => $rate ?: null]);
    exit;
}

// ── Route: save_rate ──────────────────────────────────────────────
if ($action === 'save_rate' && $method === 'POST') {
    $pid = requirePractitioner();
    verifyCsrf();

    $isFree   = !empty($_POST['is_free']) ? 1 : 0;
    $amount   = $isFree ? 0.00 : round((float)($_POST['amount'] ?? 0), 2);
    $currency = strtoupper(trim($_POST['currency'] ?? 'GBP'));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) $currency = 'GBP';

    $stmt = $pdo->prepare('
        INSERT INTO practitioner_rates (practitioner_id, currency, amount, is_free)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE currency=VALUES(currency), amount=VALUES(amount), is_free=VALUES(is_free)
    ');
    $stmt->execute([$pid, $currency, $amount, $isFree]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── Route: get_gateways ────────────────────────────────────────────
if ($action === 'get_gateways' && $method === 'GET') {
    $pid = requirePractitioner();
    verifyCsrf();

    $stmt = $pdo->prepare('SELECT * FROM practitioner_payment_gateways WHERE practitioner_id = ? LIMIT 1');
    $stmt->execute([$pid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // Never expose encrypted secrets; just confirm they're set.
        $secretFields = [
            'stripe_secret_enc'                => 'stripe_secret_set',
            'stripe_webhook_secret_enc'        => 'stripe_webhook_secret_set',
            'square_token_enc'                 => 'square_token_set',
            'square_webhook_signature_key_enc' => 'square_webhook_signature_key_set',
            'paypal_client_secret_enc'         => 'paypal_client_secret_set',
            'btcpay_api_key_enc'               => 'btcpay_api_key_set',
            'btcpay_webhook_secret_enc'        => 'btcpay_webhook_secret_set',
        ];
        foreach ($secretFields as $col => $flag) {
            $row[$flag] = !empty($row[$col]);
            unset($row[$col]);
        }
    }

    // Build the public webhook URLs the practitioner copy-pastes into provider dashboards.
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base     = $protocol . '://' . $host . '/api/payment_webhook.php?practitioner_id=' . $pid . '&gateway=';
    $webhookUrls = [
        'stripe' => $base . 'stripe',
        'paypal' => $base . 'paypal',
        'square' => $base . 'square',
        'btcpay' => $base . 'btcpay',
    ];

    echo json_encode([
        'ok' => true,
        'gateways'     => $row ?: null,
        'webhook_urls' => $webhookUrls,
    ]);
    exit;
}

// ── Route: save_gateways ───────────────────────────────────────────
if ($action === 'save_gateways' && $method === 'POST') {
    $pid = requirePractitioner();
    verifyCsrf();

    // ── Basic fields (preserved from existing flow) ──
    $paypalEnabled    = !empty($_POST['paypal_enabled'])  ? 1 : 0;
    $paypalEmail      = trim($_POST['paypal_email']       ?? '');
    $paypalMe         = trim($_POST['paypal_me_username'] ?? '');
    $stripeEnabled    = !empty($_POST['stripe_enabled'])  ? 1 : 0;
    $stripePubKey     = trim($_POST['stripe_pub_key']     ?? '');
    $stripeSecretRaw  = trim($_POST['stripe_secret_key']  ?? ''); // '' means no change
    $btcEnabled       = !empty($_POST['btc_enabled'])     ? 1 : 0;
    $btcAddress       = trim($_POST['btc_address']        ?? '');
    $squareEnabled    = !empty($_POST['square_enabled'])  ? 1 : 0;
    $squareAppId      = trim($_POST['square_app_id']      ?? '');
    $squareTokenRaw   = trim($_POST['square_token']       ?? ''); // '' means no change
    $squareLocationId = trim($_POST['square_location_id'] ?? '');

    // ── Mode flags (basic vs advanced) ──
    $allowedModes = ['basic','advanced'];
    $paypalMode = in_array($_POST['paypal_mode'] ?? 'basic', $allowedModes, true) ? $_POST['paypal_mode'] : 'basic';
    $stripeMode = in_array($_POST['stripe_mode'] ?? 'basic', $allowedModes, true) ? $_POST['stripe_mode'] : 'basic';
    $btcMode    = in_array($_POST['btc_mode']    ?? 'basic', $allowedModes, true) ? $_POST['btc_mode']    : 'basic';
    $squareMode = in_array($_POST['square_mode'] ?? 'basic', $allowedModes, true) ? $_POST['square_mode'] : 'basic';

    // ── Advanced fields ──
    $paypalEnv          = ($_POST['paypal_environment'] ?? 'live') === 'sandbox' ? 'sandbox' : 'live';
    $paypalClientId     = trim($_POST['paypal_client_id']         ?? '');
    $paypalSecretRaw    = trim($_POST['paypal_client_secret']     ?? ''); // blank = keep
    $paypalWebhookId    = trim($_POST['paypal_webhook_id']        ?? '');

    $stripeWebhookRaw   = trim($_POST['stripe_webhook_secret']    ?? ''); // blank = keep

    $btcpayServerUrl    = trim($_POST['btcpay_server_url']        ?? '');
    $btcpayApiKeyRaw    = trim($_POST['btcpay_api_key']           ?? ''); // blank = keep
    $btcpayStoreId      = trim($_POST['btcpay_store_id']          ?? '');
    $btcpayWebhookRaw   = trim($_POST['btcpay_webhook_secret']    ?? ''); // blank = keep

    $squareEnv          = ($_POST['square_environment'] ?? 'production') === 'sandbox' ? 'sandbox' : 'production';
    $squareSigKeyRaw    = trim($_POST['square_webhook_signature_key'] ?? ''); // blank = keep

    // ── Validation ──
    if ($paypalEmail !== '' && !filter_var($paypalEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid PayPal email address']);
        exit;
    }
    if ($stripePubKey !== '' && !preg_match('/^pk_(test|live)_[A-Za-z0-9]+$/', $stripePubKey)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid Stripe publishable key format']);
        exit;
    }
    if ($btcpayServerUrl !== '' && !filter_var($btcpayServerUrl, FILTER_VALIDATE_URL)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid BTCPay Server URL']);
        exit;
    }

    // DPA gating — same as before
    $dpGateTypes = [];
    if ($stripeEnabled) $dpGateTypes[] = 'payment_stripe';
    if ($paypalEnabled) $dpGateTypes[] = 'payment_paypal';
    if ($squareEnabled) $dpGateTypes[] = 'payment_square';
    foreach ($dpGateTypes as $dpaType) {
        $dpaStmt = $pdo->prepare(
            'SELECT id FROM dpa_documents WHERE scope = ? AND type = ? AND practitioner_id = ? LIMIT 1'
        );
        $dpaStmt->execute(['practitioner', $dpaType, $pid]);
        if (!$dpaStmt->fetch()) {
            $labelMap = ['payment_stripe'=>'Stripe','payment_paypal'=>'PayPal','payment_square'=>'Square'];
            $label    = $labelMap[$dpaType] ?? $dpaType;
            echo json_encode(['ok' => false, 'error' => "A Data Processing Agreement for {$label} is required before enabling this gateway. Upload the DPA in the Data Processing Agreements section."]);
            exit;
        }
    }

    // Existing secrets — kept when raw input is blank.
    $exStmt = $pdo->prepare(
        'SELECT stripe_secret_enc, stripe_webhook_secret_enc,
                square_token_enc, square_webhook_signature_key_enc,
                paypal_client_secret_enc,
                btcpay_api_key_enc, btcpay_webhook_secret_enc
         FROM practitioner_payment_gateways WHERE practitioner_id = ? LIMIT 1'
    );
    $exStmt->execute([$pid]);
    $existing = $exStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Stripe secret key validation + encryption
    if ($stripeSecretRaw !== '') {
        if (!preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', $stripeSecretRaw)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid Stripe secret key format']);
            exit;
        }
        $stripeSecretEnc = encryptValue($stripeSecretRaw);
    } else {
        $stripeSecretEnc = $existing['stripe_secret_enc'] ?? '';
    }

    $stripeWebhookSecretEnc = $stripeWebhookRaw !== ''
        ? encryptValue($stripeWebhookRaw)
        : ($existing['stripe_webhook_secret_enc'] ?? '');

    $squareTokenEnc = $squareTokenRaw !== ''
        ? encryptValue($squareTokenRaw)
        : ($existing['square_token_enc'] ?? '');

    $squareSigKeyEnc = $squareSigKeyRaw !== ''
        ? encryptValue($squareSigKeyRaw)
        : ($existing['square_webhook_signature_key_enc'] ?? '');

    $paypalClientSecretEnc = $paypalSecretRaw !== ''
        ? encryptValue($paypalSecretRaw)
        : ($existing['paypal_client_secret_enc'] ?? '');

    $btcpayApiKeyEnc = $btcpayApiKeyRaw !== ''
        ? encryptValue($btcpayApiKeyRaw)
        : ($existing['btcpay_api_key_enc'] ?? '');

    $btcpayWebhookSecretEnc = $btcpayWebhookRaw !== ''
        ? encryptValue($btcpayWebhookRaw)
        : ($existing['btcpay_webhook_secret_enc'] ?? '');

    $stmt = $pdo->prepare('
        INSERT INTO practitioner_payment_gateways
            (practitioner_id,
             paypal_enabled, paypal_mode, paypal_email, paypal_me_username,
             paypal_environment, paypal_client_id, paypal_client_secret_enc, paypal_webhook_id,
             stripe_enabled, stripe_mode, stripe_pub_key, stripe_secret_enc, stripe_webhook_secret_enc,
             btc_enabled, btc_mode, btc_address,
             btcpay_server_url, btcpay_api_key_enc, btcpay_store_id, btcpay_webhook_secret_enc,
             square_enabled, square_mode, square_environment, square_app_id,
             square_token_enc, square_location_id, square_webhook_signature_key_enc)
        VALUES (?, ?,?,?,?, ?,?,?,?, ?,?,?,?,?, ?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?)
        ON DUPLICATE KEY UPDATE
            paypal_enabled=VALUES(paypal_enabled),
            paypal_mode=VALUES(paypal_mode),
            paypal_email=VALUES(paypal_email),
            paypal_me_username=VALUES(paypal_me_username),
            paypal_environment=VALUES(paypal_environment),
            paypal_client_id=VALUES(paypal_client_id),
            paypal_client_secret_enc=VALUES(paypal_client_secret_enc),
            paypal_webhook_id=VALUES(paypal_webhook_id),
            stripe_enabled=VALUES(stripe_enabled),
            stripe_mode=VALUES(stripe_mode),
            stripe_pub_key=VALUES(stripe_pub_key),
            stripe_secret_enc=VALUES(stripe_secret_enc),
            stripe_webhook_secret_enc=VALUES(stripe_webhook_secret_enc),
            btc_enabled=VALUES(btc_enabled),
            btc_mode=VALUES(btc_mode),
            btc_address=VALUES(btc_address),
            btcpay_server_url=VALUES(btcpay_server_url),
            btcpay_api_key_enc=VALUES(btcpay_api_key_enc),
            btcpay_store_id=VALUES(btcpay_store_id),
            btcpay_webhook_secret_enc=VALUES(btcpay_webhook_secret_enc),
            square_enabled=VALUES(square_enabled),
            square_mode=VALUES(square_mode),
            square_environment=VALUES(square_environment),
            square_app_id=VALUES(square_app_id),
            square_token_enc=VALUES(square_token_enc),
            square_location_id=VALUES(square_location_id),
            square_webhook_signature_key_enc=VALUES(square_webhook_signature_key_enc)
    ');
    $stmt->execute([
        $pid,
        $paypalEnabled, $paypalMode, $paypalEmail, $paypalMe,
        $paypalEnv, $paypalClientId, $paypalClientSecretEnc, $paypalWebhookId,
        $stripeEnabled, $stripeMode, $stripePubKey, $stripeSecretEnc, $stripeWebhookSecretEnc,
        $btcEnabled, $btcMode, $btcAddress,
        $btcpayServerUrl, $btcpayApiKeyEnc, $btcpayStoreId, $btcpayWebhookSecretEnc,
        $squareEnabled, $squareMode, $squareEnv, $squareAppId,
        $squareTokenEnc, $squareLocationId, $squareSigKeyEnc,
    ]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── Route: list_campaigns ──────────────────────────────────────────
if ($action === 'list_campaigns' && $method === 'GET') {
    $pid = requirePractitioner();
    verifyCsrf();

    $stmt = $pdo->prepare('
        SELECT * FROM discount_campaigns
        WHERE practitioner_id = ?
        ORDER BY valid_from DESC
    ');
    $stmt->execute([$pid]);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'campaigns' => $campaigns]);
    exit;
}

// ── Route: save_campaign ───────────────────────────────────────────
if ($action === 'save_campaign' && $method === 'POST') {
    $pid = requirePractitioner();
    verifyCsrf();

    $id          = (int)($_POST['id'] ?? 0);
    $label       = trim($_POST['label'] ?? '');
    $discountPct = min(100, max(0, (int)($_POST['discount_pct'] ?? 0)));
    $validFrom   = trim($_POST['valid_from'] ?? date('Y-m-d'));
    $validUntil  = trim($_POST['valid_until'] ?? '') ?: null;
    $isActive    = !empty($_POST['is_active']) ? 1 : 0;

    if ($label === '') {
        echo json_encode(['ok' => false, 'error' => 'Campaign label is required']);
        exit;
    }

    if ($id > 0) {
        // Update — verify ownership
        $stmt = $pdo->prepare('SELECT id FROM discount_campaigns WHERE id = ? AND practitioner_id = ?');
        $stmt->execute([$id, $pid]);
        if (!$stmt->fetch()) {
            echo json_encode(['ok' => false, 'error' => 'Campaign not found']);
            exit;
        }
        $stmt = $pdo->prepare('
            UPDATE discount_campaigns
            SET label=?, discount_pct=?, valid_from=?, valid_until=?, is_active=?
            WHERE id=? AND practitioner_id=?
        ');
        $stmt->execute([$label, $discountPct, $validFrom, $validUntil, $isActive, $id, $pid]);
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO discount_campaigns (practitioner_id, label, discount_pct, valid_from, valid_until, is_active)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$pid, $label, $discountPct, $validFrom, $validUntil, $isActive]);
        $id = (int)$pdo->lastInsertId();
    }

    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ── Route: delete_campaign ─────────────────────────────────────────
if ($action === 'delete_campaign' && $method === 'POST') {
    $pid = requirePractitioner();
    verifyCsrf();

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        echo json_encode(['ok' => false, 'error' => 'Missing campaign ID']);
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM discount_campaigns WHERE id = ? AND practitioner_id = ?');
    $stmt->execute([$id, $pid]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── Route: booking_rate (public — booking token) ───────────────────
if ($action === 'booking_rate' && $method === 'GET') {
    $token = trim($_GET['token'] ?? '');
    if ($token === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing token']);
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT b.id, b.practitioner_id, b.status
        FROM bookings b
        WHERE b.booking_token = ? LIMIT 1
    ');
    $stmt->execute([$token]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        echo json_encode(['ok' => false, 'error' => 'Booking not found']);
        exit;
    }

    $pid = (int)$booking['practitioner_id'];

    // Rate
    $stmt = $pdo->prepare('SELECT * FROM practitioner_rates WHERE practitioner_id = ? LIMIT 1');
    $stmt->execute([$pid]);
    $rate = $stmt->fetch(PDO::FETCH_ASSOC);

    // Active campaigns
    $stmt = $pdo->prepare("
        SELECT * FROM discount_campaigns
        WHERE practitioner_id = ? AND is_active = 1
          AND valid_from <= CURDATE()
          AND (valid_until IS NULL OR valid_until >= CURDATE())
        ORDER BY discount_pct DESC
    ");
    $stmt->execute([$pid]);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Gateways (public-safe subset only)
    $stmt = $pdo->prepare('
        SELECT paypal_enabled, paypal_mode, paypal_email, paypal_me_username,
               stripe_enabled, stripe_mode, stripe_pub_key,
               btc_enabled, btc_mode, btc_address,
               square_enabled, square_mode
        FROM practitioner_payment_gateways
        WHERE practitioner_id = ? LIMIT 1
    ');
    $stmt->execute([$pid]);
    $gateways = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'        => true,
        'rate'      => $rate ?: null,
        'campaigns' => $campaigns,
        'gateways'  => $gateways ?: null,
        'booking_id' => (int)$booking['id'],
    ]);
    exit;
}

// ── Route: record_payment ──────────────────────────────────────────
// Public endpoint — requires booking token only.
// Status is always recorded as 'pending'; only the authenticated practitioner
// can confirm payment via mark_paid after independently verifying receipt.
if ($action === 'record_payment' && $method === 'POST') {
    $token = trim($_POST['token'] ?? '');
    if ($token === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing token']);
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT b.id, b.practitioner_id, b.guest_name, b.guest_email, b.scheduled_at,
               p.email AS pract_email, p.display_name AS pract_name
        FROM bookings b
        JOIN practitioners p ON p.id = b.practitioner_id
        WHERE b.booking_token = ? LIMIT 1
    ');
    $stmt->execute([$token]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        echo json_encode(['ok' => false, 'error' => 'Booking not found']);
        exit;
    }

    $pid        = (int)$booking['practitioner_id'];
    $bookingId  = (int)$booking['id'];
    $gateway    = $_POST['gateway'] ?? 'manual';
    $gwRef      = trim($_POST['gateway_ref'] ?? '');
    $campaignId = (int)($_POST['campaign_id'] ?? 0) ?: null;

    // Status is always pending — practitioner confirms independently
    $status = 'pending';

    $allowedGw = ['paypal','stripe','bitcoin','free','manual','waived'];
    if (!in_array($gateway, $allowedGw, true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid gateway']);
        exit;
    }

    // Load rate
    $stmt = $pdo->prepare('SELECT * FROM practitioner_rates WHERE practitioner_id = ? LIMIT 1');
    $stmt->execute([$pid]);
    $rate = $stmt->fetch(PDO::FETCH_ASSOC);

    $currency    = $rate['currency'] ?? 'GBP';
    $amountDue   = $rate ? (float)$rate['amount'] : 0.00;
    $discountPct = 0;

    if ($campaignId) {
        $stmt = $pdo->prepare('SELECT discount_pct FROM discount_campaigns WHERE id = ? AND practitioner_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$campaignId, $pid]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($campaign) {
            $discountPct = (int)$campaign['discount_pct'];
            $amountDue   = round($amountDue * (1 - $discountPct / 100), 2);
        }
    }

    $stmt = $pdo->prepare('
        INSERT INTO booking_payments
            (booking_id, practitioner_id, amount_due, amount_paid, currency,
             discount_pct, campaign_id, gateway, status, gateway_ref, paid_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
        ON DUPLICATE KEY UPDATE
            amount_paid = 0.00,
            status      = IF(status = \'paid\', \'paid\', \'pending\'),
            gateway     = VALUES(gateway),
            gateway_ref = VALUES(gateway_ref),
            paid_at     = IF(status = \'paid\', paid_at, NULL)
    ');
    $stmt->execute([
        $bookingId, $pid, $amountDue, 0.00, $currency,
        $discountPct, $campaignId, $gateway, $status, $gwRef,
    ]);

    // Notify practitioner so they can verify receipt and confirm
    if (!empty($booking['pract_email'])) {
        $protocol     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl      = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $confirmUrl   = $baseUrl . '/dashboard.php?tab=finance';
        $platformName = getSetting('platform_name', 'Serenity Spaces');
        $guestName    = $booking['guest_name'] ?: 'A client';
        $gatewayLabel = ucfirst($gateway);
        $sessionDate  = $booking['scheduled_at']
            ? date('D j M Y, H:i', strtotime($booking['scheduled_at'])) . ' UTC'
            : 'scheduled session';

        $bodyHtml = '
            <p>Hi ' . htmlspecialchars($booking['pract_name'] ?: 'there') . ',</p>
            <p><strong>' . htmlspecialchars($guestName) . '</strong> has indicated a payment via
               <strong>' . htmlspecialchars($gatewayLabel) . '</strong> for their session on
               <strong>' . htmlspecialchars($sessionDate) . '</strong>.</p>
            <p>Please check your ' . htmlspecialchars($gatewayLabel) . ' account to confirm the funds have been received,
               then log in and mark the payment as confirmed:</p>
            <p style="margin:24px 0;">
              <a href="' . htmlspecialchars($confirmUrl) . '"
                 style="background:#7c6af7;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;">
                Confirm Payment
              </a>
            </p>
            <p style="color:rgba(255,255,255,0.5);font-size:13px;">
              The client will receive a confirmation email once you mark the payment as received.
            </p>';

        sendEmail(
            $booking['pract_email'],
            $booking['pract_name'] ?: '',
            'Payment notification — ' . $guestName . ' via ' . $gatewayLabel,
            emailTemplate('Payment Notification', $bodyHtml)
        );
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── Route: mark_paid ──────────────────────────────────────────────
if ($action === 'mark_paid' && $method === 'POST') {
    $pid = requirePractitioner();

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];

    // CSRF token may arrive in JSON body (not form POST)
    $csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_GET['csrf'] ?? '';
    if (!validate_csrf($csrfToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF token mismatch']);
        exit;
    }
    $paymentId = (int)($input['payment_id'] ?? $_POST['payment_id'] ?? 0);

    if (!$paymentId) {
        echo json_encode(['ok' => false, 'error' => 'Missing payment ID']);
        exit;
    }

    // Verify ownership and fetch current record
    $stmt = $pdo->prepare('SELECT id, amount_due, status, gateway FROM booking_payments WHERE id = ? AND practitioner_id = ?');
    $stmt->execute([$paymentId, $pid]);
    $pay = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pay) {
        echo json_encode(['ok' => false, 'error' => 'Payment record not found']);
        exit;
    }

    if ($pay['status'] === 'paid') {
        echo json_encode(['ok' => true, 'already_paid' => true]);
        exit;
    }

    $gateway = ($pay['gateway'] && !in_array($pay['gateway'], ['pending',''], true))
        ? $pay['gateway']
        : 'manual';

    $stmt = $pdo->prepare("
        UPDATE booking_payments
        SET status = 'paid',
            amount_paid = amount_due,
            gateway = ?,
            paid_at = NOW()
        WHERE id = ? AND practitioner_id = ?
    ");
    $stmt->execute([$gateway, $paymentId, $pid]);

    // Send confirmation email to client
    $stmt = $pdo->prepare('
        SELECT b.guest_name, b.guest_email, b.scheduled_at,
               eu.email AS eu_email, eu.display_name AS eu_name,
               p.display_name AS pract_name
        FROM booking_payments bp
        JOIN bookings b ON b.id = bp.booking_id
        LEFT JOIN end_users eu ON eu.id = b.end_user_id
        JOIN practitioners p ON p.id = bp.practitioner_id
        WHERE bp.id = ? LIMIT 1
    ');
    $stmt->execute([$paymentId]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($rec) {
        $clientEmail = $rec['guest_email'] ?: $rec['eu_email'] ?? '';
        $clientName  = $rec['guest_name']  ?: $rec['eu_name']  ?? '';
        $practName   = $rec['pract_name']  ?: 'your practitioner';
        $sessionDate = $rec['scheduled_at']
            ? date('D j M Y, H:i', strtotime($rec['scheduled_at'])) . ' UTC'
            : 'your session';

        if ($clientEmail) {
            $bodyHtml = '
                <p>Hi ' . htmlspecialchars($clientName ?: 'there') . ',</p>
                <p>Your payment for your session on <strong>' . htmlspecialchars($sessionDate) . '</strong>
                   with <strong>' . htmlspecialchars($practName) . '</strong> has been confirmed.</p>
                <p>Thank you — we look forward to your session.</p>';

            sendEmail(
                $clientEmail,
                $clientName,
                'Payment confirmed',
                emailTemplate('Payment Confirmed', $bodyHtml)
            );
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── Route: stripe_checkout ────────────────────────────────────────
// Public — booking-token-authenticated. Creates a real Stripe Checkout Session
// when the practitioner has Stripe in 'advanced' mode, returning the hosted
// checkout URL the client should be redirected to. The booking_token is set on
// the session metadata so the webhook can route the result back to the right
// booking_payment row.
if ($action === 'stripe_checkout' && $method === 'POST') {
    require_once __DIR__ . '/../includes/stripe_api.php';

    $token = trim($_POST['token'] ?? '');
    if ($token === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing token']);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT b.id, b.practitioner_id, b.booking_token, b.guest_name, b.guest_email,
                b.scheduled_at,
                p.display_name AS pract_name
         FROM bookings b
         JOIN practitioners p ON p.id = b.practitioner_id
         WHERE b.booking_token = ? LIMIT 1'
    );
    $stmt->execute([$token]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        echo json_encode(['ok' => false, 'error' => 'Booking not found']);
        exit;
    }

    $pid       = (int)$booking['practitioner_id'];
    $bookingId = (int)$booking['id'];

    // Practitioner must have Stripe enabled in advanced mode with secret on file.
    $gwStmt = $pdo->prepare(
        'SELECT stripe_enabled, stripe_mode, stripe_secret_enc
         FROM practitioner_payment_gateways WHERE practitioner_id = ? LIMIT 1'
    );
    $gwStmt->execute([$pid]);
    $gw = $gwStmt->fetch(PDO::FETCH_ASSOC);
    if (!$gw || (int)$gw['stripe_enabled'] !== 1 || ($gw['stripe_mode'] ?? 'basic') !== 'advanced' || empty($gw['stripe_secret_enc'])) {
        echo json_encode(['ok' => false, 'error' => 'Stripe Advanced is not configured for this practitioner']);
        exit;
    }

    // Rate + active campaign discount — same logic as record_payment
    $rateStmt = $pdo->prepare('SELECT * FROM practitioner_rates WHERE practitioner_id = ? LIMIT 1');
    $rateStmt->execute([$pid]);
    $rate = $rateStmt->fetch(PDO::FETCH_ASSOC);
    if (!$rate || (float)$rate['amount'] <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Practitioner has no payable rate configured']);
        exit;
    }

    $currency  = strtolower($rate['currency'] ?? 'usd');
    $amountDue = (float)$rate['amount'];
    $campaignId  = (int)($_POST['campaign_id'] ?? 0) ?: null;
    $discountPct = 0;
    if ($campaignId) {
        $cStmt = $pdo->prepare('SELECT discount_pct FROM discount_campaigns WHERE id = ? AND practitioner_id = ? AND is_active = 1 LIMIT 1');
        $cStmt->execute([$campaignId, $pid]);
        $c = $cStmt->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $discountPct = (int)$c['discount_pct'];
            $amountDue   = round($amountDue * (1 - $discountPct / 100), 2);
        }
    }

    // Stripe takes amounts in the smallest currency unit (e.g. cents/pence).
    // Zero-decimal currencies (JPY, KRW, etc.) need raw value — keep this
    // conservative and only special-case the common ones.
    $zeroDecimal = ['jpy','krw','vnd','clp','pyg','rwf','ugx','xaf','xof','xpf','bif','djf','gnf','kmf','mga','isk'];
    $amountMinor = in_array($currency, $zeroDecimal, true)
        ? (int)round($amountDue)
        : (int)round($amountDue * 100);

    if ($amountMinor <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Amount must be greater than zero']);
        exit;
    }

    // Pre-create the booking_payment row in pending state so the webhook has a
    // landing point. Idempotent — uses booking_id UNIQUE.
    $upsert = $pdo->prepare("
        INSERT INTO booking_payments
            (booking_id, practitioner_id, amount_due, amount_paid, currency,
             discount_pct, campaign_id, gateway, status, gateway_ref, paid_at)
        VALUES (?, ?, ?, 0.00, ?, ?, ?, 'stripe', 'pending', NULL, NULL)
        ON DUPLICATE KEY UPDATE
            amount_due  = VALUES(amount_due),
            currency    = VALUES(currency),
            discount_pct= VALUES(discount_pct),
            campaign_id = VALUES(campaign_id),
            gateway     = 'stripe',
            status      = IF(status = 'paid', 'paid', 'pending')
    ");
    $upsert->execute([$bookingId, $pid, $amountDue, strtoupper($currency), $discountPct, $campaignId]);

    $secretKey   = decryptValue($gw['stripe_secret_enc']);
    $protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl     = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $description = 'Session with ' . ($booking['pract_name'] ?: 'practitioner');
    $sessRes = stripe_create_checkout_session(
        $secretKey,
        $token,
        $amountMinor,
        $currency,
        $description,
        $baseUrl . '/book.php?paid=1&token=' . urlencode($token),
        $baseUrl . '/book.php?cancelled=1&token=' . urlencode($token),
        $booking['guest_email'] ?? ''
    );
    if (!$sessRes['ok']) {
        echo json_encode(['ok' => false, 'error' => $sessRes['error'] ?? 'Stripe error']);
        exit;
    }

    // Stash the session id for traceability
    $u = $pdo->prepare("UPDATE booking_payments SET gateway_ref = ? WHERE booking_id = ?");
    $u->execute([$sessRes['session_id'], $bookingId]);

    echo json_encode(['ok' => true, 'url' => $sessRes['url']]);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// Shared helper for the *_checkout actions below.
// Validates the booking, computes the payable amount, and upserts the
// booking_payment row in pending state. Returns a context array on success
// or echoes a JSON error and exits on failure.
// ═══════════════════════════════════════════════════════════════════════
function checkout_load_context(PDO $pdo, string $token, string $gatewayKey): array {
    if ($token === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing token']);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT b.id, b.practitioner_id, b.booking_token, b.guest_name, b.guest_email,
                b.scheduled_at,
                p.display_name AS pract_name
         FROM bookings b
         JOIN practitioners p ON p.id = b.practitioner_id
         WHERE b.booking_token = ? LIMIT 1'
    );
    $stmt->execute([$token]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        echo json_encode(['ok' => false, 'error' => 'Booking not found']);
        exit;
    }

    $rateStmt = $pdo->prepare('SELECT * FROM practitioner_rates WHERE practitioner_id = ? LIMIT 1');
    $rateStmt->execute([(int)$booking['practitioner_id']]);
    $rate = $rateStmt->fetch(PDO::FETCH_ASSOC);
    if (!$rate || (float)$rate['amount'] <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Practitioner has no payable rate configured']);
        exit;
    }

    $currency  = strtoupper($rate['currency'] ?? 'USD');
    $amountDue = (float)$rate['amount'];
    $campaignId  = (int)($_POST['campaign_id'] ?? 0) ?: null;
    $discountPct = 0;
    if ($campaignId) {
        $cStmt = $pdo->prepare(
            'SELECT discount_pct FROM discount_campaigns
             WHERE id = ? AND practitioner_id = ? AND is_active = 1 LIMIT 1'
        );
        $cStmt->execute([$campaignId, (int)$booking['practitioner_id']]);
        $c = $cStmt->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $discountPct = (int)$c['discount_pct'];
            $amountDue   = round($amountDue * (1 - $discountPct / 100), 2);
        }
    }

    if ($amountDue <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Amount must be greater than zero']);
        exit;
    }

    // Upsert booking_payment in pending state — webhook lands here on success.
    $upsert = $pdo->prepare("
        INSERT INTO booking_payments
            (booking_id, practitioner_id, amount_due, amount_paid, currency,
             discount_pct, campaign_id, gateway, status, gateway_ref, paid_at)
        VALUES (?, ?, ?, 0.00, ?, ?, ?, ?, 'pending', NULL, NULL)
        ON DUPLICATE KEY UPDATE
            amount_due  = VALUES(amount_due),
            currency    = VALUES(currency),
            discount_pct= VALUES(discount_pct),
            campaign_id = VALUES(campaign_id),
            gateway     = VALUES(gateway),
            status      = IF(status = 'paid', 'paid', 'pending')
    ");
    $upsert->execute([
        (int)$booking['id'],
        (int)$booking['practitioner_id'],
        $amountDue,
        $currency,
        $discountPct,
        $campaignId,
        $gatewayKey,
    ]);

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl  = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return [
        'booking'   => $booking,
        'amount'    => $amountDue,
        'currency'  => $currency,
        'success_url' => $baseUrl . '/book.php?paid=1&token=' . urlencode($token),
        'cancel_url'  => $baseUrl . '/book.php?cancelled=1&token=' . urlencode($token),
        'description' => 'Session with ' . ($booking['pract_name'] ?: 'practitioner'),
    ];
}

// ── Route: paypal_checkout ────────────────────────────────────────
// Public — booking-token-authenticated. Creates a real PayPal Orders v2 order
// when the practitioner has PayPal in 'advanced' mode, returning the approval
// URL. invoice_id and custom_id are both set to the booking_token so the
// webhook can route the result.
if ($action === 'paypal_checkout' && $method === 'POST') {
    require_once __DIR__ . '/../includes/paypal_api.php';

    $token = trim($_POST['token'] ?? '');

    $gwStmt = $pdo->prepare('
        SELECT g.paypal_enabled, g.paypal_mode, g.paypal_environment,
               g.paypal_client_id, g.paypal_client_secret_enc
        FROM practitioner_payment_gateways g
        JOIN bookings b ON b.practitioner_id = g.practitioner_id
        WHERE b.booking_token = ? LIMIT 1
    ');
    $gwStmt->execute([$token]);
    $gw = $gwStmt->fetch(PDO::FETCH_ASSOC);
    if (!$gw || (int)$gw['paypal_enabled'] !== 1
        || ($gw['paypal_mode'] ?? 'basic') !== 'advanced'
        || empty($gw['paypal_client_id'])
        || empty($gw['paypal_client_secret_enc'])) {
        echo json_encode(['ok' => false, 'error' => 'PayPal Advanced is not configured for this practitioner']);
        exit;
    }

    $ctx = checkout_load_context($pdo, $token, 'paypal');

    $res = paypal_create_order(
        (string)$gw['paypal_client_id'],
        decryptValue($gw['paypal_client_secret_enc']),
        (string)($gw['paypal_environment'] ?? 'live'),
        $token,
        (float)$ctx['amount'],
        (string)$ctx['currency'],
        $ctx['description'],
        $ctx['success_url'],
        $ctx['cancel_url']
    );
    if (!$res['ok']) {
        echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'PayPal error']);
        exit;
    }

    $u = $pdo->prepare('UPDATE booking_payments SET gateway_ref = ? WHERE booking_id = (SELECT id FROM bookings WHERE booking_token = ? LIMIT 1)');
    $u->execute([$res['order_id'], $token]);

    echo json_encode(['ok' => true, 'url' => $res['url']]);
    exit;
}

// ── Route: square_checkout ────────────────────────────────────────
if ($action === 'square_checkout' && $method === 'POST') {
    require_once __DIR__ . '/../includes/square_api.php';

    $token = trim($_POST['token'] ?? '');

    $gwStmt = $pdo->prepare('
        SELECT g.square_enabled, g.square_mode, g.square_environment,
               g.square_token_enc, g.square_location_id
        FROM practitioner_payment_gateways g
        JOIN bookings b ON b.practitioner_id = g.practitioner_id
        WHERE b.booking_token = ? LIMIT 1
    ');
    $gwStmt->execute([$token]);
    $gw = $gwStmt->fetch(PDO::FETCH_ASSOC);
    if (!$gw || (int)$gw['square_enabled'] !== 1
        || ($gw['square_mode'] ?? 'basic') !== 'advanced'
        || empty($gw['square_token_enc'])
        || empty($gw['square_location_id'])) {
        echo json_encode(['ok' => false, 'error' => 'Square Advanced is not configured for this practitioner']);
        exit;
    }

    $ctx = checkout_load_context($pdo, $token, 'square');

    // Square takes amounts in the smallest currency unit. Same zero-decimal list
    // as Stripe — Square also follows ISO 4217 for unit handling.
    $zeroDecimal = ['JPY','KRW','VND','CLP','PYG','RWF','UGX','XAF','XOF','XPF','BIF','DJF','GNF','KMF','MGA','ISK'];
    $currency    = (string)$ctx['currency'];
    $amountMinor = in_array($currency, $zeroDecimal, true)
        ? (int)round((float)$ctx['amount'])
        : (int)round((float)$ctx['amount'] * 100);

    $res = square_create_checkout(
        decryptValue($gw['square_token_enc']),
        (string)($gw['square_environment'] ?? 'production'),
        (string)$gw['square_location_id'],
        $token,
        $amountMinor,
        $currency,
        $ctx['description'],
        $ctx['success_url']
    );
    if (!$res['ok']) {
        echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'Square error']);
        exit;
    }

    $u = $pdo->prepare('UPDATE booking_payments SET gateway_ref = ? WHERE booking_id = (SELECT id FROM bookings WHERE booking_token = ? LIMIT 1)');
    $u->execute([$res['order_id'] ?: 'square_payment_link', $token]);

    echo json_encode(['ok' => true, 'url' => $res['url']]);
    exit;
}

// ── Route: btcpay_checkout ────────────────────────────────────────
if ($action === 'btcpay_checkout' && $method === 'POST') {
    require_once __DIR__ . '/../includes/btcpay_api.php';

    $token = trim($_POST['token'] ?? '');

    $gwStmt = $pdo->prepare('
        SELECT g.btc_enabled, g.btc_mode, g.btcpay_server_url,
               g.btcpay_api_key_enc, g.btcpay_store_id
        FROM practitioner_payment_gateways g
        JOIN bookings b ON b.practitioner_id = g.practitioner_id
        WHERE b.booking_token = ? LIMIT 1
    ');
    $gwStmt->execute([$token]);
    $gw = $gwStmt->fetch(PDO::FETCH_ASSOC);
    if (!$gw || (int)$gw['btc_enabled'] !== 1
        || ($gw['btc_mode'] ?? 'basic') !== 'advanced'
        || empty($gw['btcpay_server_url'])
        || empty($gw['btcpay_api_key_enc'])
        || empty($gw['btcpay_store_id'])) {
        echo json_encode(['ok' => false, 'error' => 'BTCPay Advanced is not configured for this practitioner']);
        exit;
    }

    $ctx = checkout_load_context($pdo, $token, 'bitcoin');

    $res = btcpay_create_invoice(
        (string)$gw['btcpay_server_url'],
        decryptValue($gw['btcpay_api_key_enc']),
        (string)$gw['btcpay_store_id'],
        $token,
        (float)$ctx['amount'],
        (string)$ctx['currency'],
        $ctx['description'],
        $ctx['success_url']
    );
    if (!$res['ok']) {
        echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'BTCPay error']);
        exit;
    }

    $u = $pdo->prepare('UPDATE booking_payments SET gateway_ref = ? WHERE booking_id = (SELECT id FROM bookings WHERE booking_token = ? LIMIT 1)');
    $u->execute([$res['invoice_id'], $token]);

    echo json_encode(['ok' => true, 'url' => $res['url']]);
    exit;
}

// ── Route: checkout_options ───────────────────────────────────────
// Public — returns the list of advanced-mode gateways available for a given
// booking_token, so the booking page can render Pay Now buttons. Returns rate
// info so the page can show the amount.
if ($action === 'checkout_options' && $method === 'GET') {
    $token = trim($_GET['token'] ?? '');
    if ($token === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing token']);
        exit;
    }

    $stmt = $pdo->prepare('
        SELECT b.id AS booking_id, b.practitioner_id, b.booking_token,
               r.amount, r.currency, r.is_free,
               g.paypal_enabled, g.paypal_mode, g.paypal_client_id, g.paypal_client_secret_enc,
               g.stripe_enabled, g.stripe_mode, g.stripe_secret_enc,
               g.square_enabled, g.square_mode, g.square_token_enc, g.square_location_id,
               g.btc_enabled,    g.btc_mode,    g.btcpay_server_url, g.btcpay_api_key_enc, g.btcpay_store_id,
               bp.status AS payment_status
        FROM bookings b
        LEFT JOIN practitioner_rates r            ON r.practitioner_id = b.practitioner_id
        LEFT JOIN practitioner_payment_gateways g ON g.practitioner_id = b.practitioner_id
        LEFT JOIN booking_payments bp             ON bp.booking_id     = b.id
        WHERE b.booking_token = ? LIMIT 1
    ');
    $stmt->execute([$token]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        echo json_encode(['ok' => false, 'error' => 'Booking not found']);
        exit;
    }

    $isFree    = !empty($r['is_free']) || (float)($r['amount'] ?? 0) <= 0;
    $available = [];

    if (!$isFree && $r) {
        if ((int)$r['stripe_enabled'] === 1 && ($r['stripe_mode'] ?? 'basic') === 'advanced'
            && !empty($r['stripe_secret_enc'])) {
            $available[] = ['gateway' => 'stripe', 'label' => 'Card (Stripe)'];
        }
        if ((int)$r['paypal_enabled'] === 1 && ($r['paypal_mode'] ?? 'basic') === 'advanced'
            && !empty($r['paypal_client_id']) && !empty($r['paypal_client_secret_enc'])) {
            $available[] = ['gateway' => 'paypal', 'label' => 'PayPal'];
        }
        if ((int)$r['square_enabled'] === 1 && ($r['square_mode'] ?? 'basic') === 'advanced'
            && !empty($r['square_token_enc']) && !empty($r['square_location_id'])) {
            $available[] = ['gateway' => 'square', 'label' => 'Card / Cash App (Square)'];
        }
        if ((int)$r['btc_enabled'] === 1 && ($r['btc_mode'] ?? 'basic') === 'advanced'
            && !empty($r['btcpay_server_url']) && !empty($r['btcpay_api_key_enc']) && !empty($r['btcpay_store_id'])) {
            $available[] = ['gateway' => 'btcpay', 'label' => 'Bitcoin (BTCPay)'];
        }
    }

    echo json_encode([
        'ok'             => true,
        'amount'         => (float)($r['amount'] ?? 0),
        'currency'       => strtoupper((string)($r['currency'] ?? 'USD')),
        'is_free'        => $isFree,
        'payment_status' => $r['payment_status'] ?? null,
        'available'      => $available,
    ]);
    exit;
}

// ── Fallback ──────────────────────────────────────────────────────
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
