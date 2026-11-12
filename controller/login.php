<?php
/**
 * GamitShare — Login Controller
 * controller/login.php
 *
 * Handles POST /controller/login.php
 * Accepts JSON  { "email": "...", "password": "..." }
 * Returns  JSON { "success": true,  "user": { name, email, role } }
 *       or JSON { "success": false, "message": "..." }
 *
 * On success it also sets a secure HTTP-only session cookie so
 * the browser dashboards (admin.html / member.html) can call
 * controller/session.php to verify the logged-in user without
 * exposing credentials.
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/database.php';

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Allow requests from the frontend pages (same origin in dev; adjust for prod)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Configure and start session ─────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_name('gamitshare_session');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// 1. Rate Limiting Check (5 attempts / 5 minutes) — DB IP-based with session fallback (M-1)
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$recordLoginAttempt = static function (?PDO $pdo, string $ip, string $email, ?int $userId, bool $successful): void {
    if (!$successful && isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts']++;
        $_SESSION['last_attempt_time'] = time();
    }
    if (!$pdo) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (ip_address, email, user_id, successful)
             VALUES (:ip, :email, :user_id, :successful)'
        );
        $stmt->execute([
            ':ip'         => $ip,
            ':email'      => substr($email, 0, 254),
            ':user_id'    => $userId,
            ':successful' => $successful ? 1 : 0,
        ]);
    } catch (PDOException $e) {
        error_log('GamitShare login attempt logging failed: ' . $e->getMessage());
    }
};

try {
    $pdoRate = db_connect();
    $pdoRate->exec(
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(254) NOT NULL DEFAULT '',
            user_id INT UNSIGNED NULL,
            ip_address VARCHAR(45) NOT NULL,
            successful TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip_address, attempted_at),
            INDEX idx_attempted_at (attempted_at)
        ) ENGINE=InnoDB"
    );
    $columns = $pdoRate->query('SHOW COLUMNS FROM login_attempts')->fetchAll(PDO::FETCH_COLUMN, 0);
    $missingColumns = [];
    if (!in_array('email', $columns, true)) {
        $missingColumns[] = "ADD COLUMN email VARCHAR(254) NOT NULL DEFAULT ''";
    }
    if (!in_array('user_id', $columns, true)) {
        $missingColumns[] = 'ADD COLUMN user_id INT UNSIGNED NULL';
    }
    if (!in_array('successful', $columns, true)) {
        $missingColumns[] = 'ADD COLUMN successful TINYINT(1) NOT NULL DEFAULT 0';
    }
    if ($missingColumns) {
        $pdoRate->exec('ALTER TABLE login_attempts ' . implode(', ', $missingColumns));
    }

    $indexes = $pdoRate->query('SHOW INDEX FROM login_attempts')->fetchAll(PDO::FETCH_COLUMN, 2);
    if (!in_array('idx_attempted_at', $indexes, true)) {
        $pdoRate->exec('ALTER TABLE login_attempts ADD INDEX idx_attempted_at (attempted_at)');
    }

    $pdoRate->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $rateStmt = $pdoRate->prepare(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip_address = :ip
            AND successful = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)'
    );
    $rateStmt->execute([':ip' => $clientIp]);
    $recentAttempts = (int)$rateStmt->fetchColumn();

    if ($recentAttempts >= 5) {
        $recordLoginAttempt($pdoRate, $clientIp, '', null, false);
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please try again in 5 minutes.']);
        exit;
    }
} catch (Throwable $e) {
    error_log('GamitShare login rate-limit setup failed: ' . $e->getMessage());
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['last_attempt_time'] = time();
    }
    if ($_SESSION['login_attempts'] >= 5 && (time() - $_SESSION['last_attempt_time']) < 300) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please try again in 5 minutes.']);
        exit;
    }
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── Read input ─────────────────────────────────────────────────────────────
// Support both JSON body (fetch API) and HTML form POST
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (str_contains($contentType, 'application/json')) {
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $email    = trim(strtolower($body['email']    ?? ''));
    $password = $body['password'] ?? '';
} else {
    // Classic HTML form POST
    $email    = trim(strtolower($_POST['email']    ?? ''));
    $password = $_POST['password'] ?? '';
}

// ── Validate input ─────────────────────────────────────────────────────────
if ($email === '' || $password === '') {
    $recordLoginAttempt($pdoRate ?? null, $clientIp, $email, null, false);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $recordLoginAttempt($pdoRate ?? null, $clientIp, $email, null, false);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

// ── Look up the user ────────────────────────────────────────────────────────
try {
    $pdo  = db_connect();
    $stmt = $pdo->prepare(
        'SELECT id, name, email, password_hash, salt, role, is_active
           FROM users
          WHERE email = :email
          LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();
} catch (RuntimeException | PDOException $e) {
    error_log('GamitShare login DB error: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Service temporarily unavailable. Please try again.']);
    exit;
}

// ── Verify credentials ──────────────────────────────────────────────────────
if (!$user) {
    http_response_code(401);
    $recordLoginAttempt($pdo, $clientIp, $email, null, false);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    exit;
}

if (!(bool)$user['is_active']) {
    // Avoid user enumeration (M-3)
    http_response_code(401);
    $recordLoginAttempt($pdo, $clientIp, $email, (int)$user['id'], false);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    exit;
}

// Re-derive the PBKDF2-SHA256 hash and compare
$computedHash = derive_pbkdf2_hash($password, $user['salt']);

if (!hash_equals($user['password_hash'], $computedHash)) {
    http_response_code(401);
    $recordLoginAttempt($pdo, $clientIp, $email, (int)$user['id'], false);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    exit;
}

// Clear the session fallback counter; keep the persistent audit history.
unset($_SESSION['login_attempts'], $_SESSION['last_attempt_time']);

// ── Create a server-side session ────────────────────────────────────────────
session_regenerate_id(true);

$_SESSION['user_id'] = $user['id'];
$_SESSION['name']    = $user['name'];
$_SESSION['email']   = $user['email'];
$_SESSION['role']    = $user['role'];

// Also persist the session to the DB (gamitshare_db.sql: user_sessions table)
try {
    $sessionId  = session_id();
    $expiresAt  = date('Y-m-d H:i:s', time() + 60 * 60 * 8); // 8-hour window
    $ipAddress  = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

    $ins = $pdo->prepare(
        'INSERT INTO user_sessions (id, user_id, ip_address, user_agent, expires_at)
              VALUES (:id, :uid, :ip, :ua, :exp)'
    );
    $ins->execute([
        ':id'  => $sessionId,
        ':uid' => $user['id'],
        ':ip'  => $ipAddress,
        ':ua'  => $userAgent,
        ':exp' => $expiresAt,
    ]);
} catch (PDOException $e) {
    error_log('GamitShare session insert failed: ' . $e->getMessage());
    $_SESSION = [];
    session_destroy();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Could not create a sign-in session. Please try again.']);
    exit;
}

// Never store the submitted password; only record the sign-in outcome.
$recordLoginAttempt($pdo, $clientIp, (string)$user['email'], (int)$user['id'], true);

// ── Success ─────────────────────────────────────────────────────────────────
$isSteward = in_array($user['role'], ['Administrator', 'Organization officer', 'Custodian'], true);

http_response_code(200);
echo json_encode([
    'success'   => true,
    'message'   => 'Welcome back, ' . $user['name'] . '!',
    'redirect'  => $isSteward ? '../views/admin/dashboard.html' : '../views/member/dashboard.html',
    'user'      => [
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ],
]);

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Derives a PBKDF2-SHA256 hash that matches what auth.js produces.
 *
 * auth.js uses:
 *   crypto.subtle.deriveBits({ name:'PBKDF2', salt, iterations:120000, hash:'SHA-256' }, key, 256)
 * then converts the 32-byte ArrayBuffer to a 64-char hex string.
 *
 * PHP's hash_pbkdf2() with $raw_output=false does the same thing when
 * $length = 64 (hex digits = 256 bits / 4 bits per hex char).
 *
 * @param string $password  Plain-text password
 * @param string $saltB64   Base-64 encoded salt stored in the DB (from auth.js)
 * @return string 64-char lowercase hex string
 */
function derive_pbkdf2_hash(string $password, string $saltB64): string
{
    $salt = base64_decode($saltB64);
    return hash_pbkdf2('sha256', $password, $salt, 120000, 64, false);
}

/**
 * Returns true if the current request arrived over HTTPS.
 */
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}
