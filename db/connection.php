<?php
// Load config written by the setup wizard if present.
// Falls back to environment variables for Docker / manual installs.
$_ssCfg = __DIR__ . '/config.php';
if (file_exists($_ssCfg)) { require_once $_ssCfg; }
unset($_ssCfg);

if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'serenityspaces');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');

// ── Setup state helpers ────────────────────────────────────────
function isAppConfigured(): bool {
    return file_exists(__DIR__ . '/config.php');
}

/** Redirect to setup wizard unless the app is already configured. */
function requireSetup(): void {
    if (!isAppConfigured()) {
        header('Location: /setup.php');
        exit;
    }
}

// ── Database connection ────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    // Ensure PHP is always UTC so date()/strtotime() are consistent
    date_default_timezone_set('UTC');
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    // Ensure MySQL session is also UTC so NOW(), CURRENT_TIMESTAMP, etc. match PHP
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

// ── Key-value settings ─────────────────────────────────────────
function getSetting(string $key, string $default = ''): string {
    if (!isset($GLOBALS['_ss_settings_cache'])) $GLOBALS['_ss_settings_cache'] = [];
    if (array_key_exists($key, $GLOBALS['_ss_settings_cache'])) return $GLOBALS['_ss_settings_cache'][$key];
    try {
        $stmt = getDB()->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $row  = $stmt->fetch();
        $val  = ($row !== false) ? $row['value'] : $default;
        $GLOBALS['_ss_settings_cache'][$key] = $val;
        return $val;
    } catch (Exception $e) {
        return $default;
    }
}

/** Bust one or all cached settings — call after setSetting() if you need the new value in the same request. */
function clearSettingCache(string $key = ''): void {
    if (!isset($GLOBALS['_ss_settings_cache'])) return;
    if ($key === '') {
        $GLOBALS['_ss_settings_cache'] = [];
    } else {
        unset($GLOBALS['_ss_settings_cache'][$key]);
    }
}

function setSetting(string $key, string $value): void {
    getDB()->prepare(
        'INSERT INTO settings (`key`, `value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = ?'
    )->execute([$key, $value, $value]);
    clearSettingCache($key);
}

/** Returns 'dark' or 'light'. Safe to call before setup is complete. */
function getAppTheme(): string {
    try { return getSetting('theme', 'dark') === 'light' ? 'light' : 'dark'; } catch (Exception $e) { return 'dark'; }
}

/** Returns the custom logo path, or '' if none set. Safe to call before setup. */
function getAppLogo(): string {
    try { return getSetting('logo_path', ''); } catch (Exception $e) { return ''; }
}
