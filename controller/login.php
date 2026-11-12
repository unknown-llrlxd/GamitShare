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
try {
    $pdoRate = db_connect();
    $pdoRate->exec(
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip_address, attempted_at)
        ) ENGINE=InnoDB"
    );
    $pdoRate->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $rateStmt = $pdoRate->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND attempted_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)'
    );
    $rateStmt->execute([':ip' => $clientIp]);
    $recentAttempts = (int)$rateStmt->fetchColumn();

    if ($recentAttempts >= 5) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please try again in 5 minutes.']);
        exit;
    }
} catch (Throwable $e) {
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
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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

// Helper to record failed attempt in DB and session
$recordFailedAttempt = static function(PDO $pdo, string $ip): void {
    if (isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts']++;
        $_SESSION['last_attempt_time'] = time();
    }
    try {
        $pdo->prepare('INSERT INTO login_attempts (ip_address) VALUES (:ip)')->execute([':ip' => $ip]);
    } catch (Throwable $e) {}
};

// ── Verify credentials ──────────────────────────────────────────────────────
if (!$user) {
    http_response_code(401);
    $recordFailedAttempt($pdo, $clientIp);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    exit;
}

if (!(bool)$user['is_active']) {
    // Avoid user enumeration (M-3)
    http_response_code(401);
    $recordFailedAttempt($pdo, $clientIp);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    exit;
}

// Re-derive the PBKDF2-SHA256 hash and compare
$computedHash = derive_pbkdf2_hash($password, $user['salt']);

if (!hash_equals($user['password_hash'], $computedHash)) {
    http_response_code(401);
    $recordFailedAttempt($pdo, $clientIp);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    exit;
}

// Clear rate limiting on successful login
unset($_SESSION['login_attempts'], $_SESSION['last_attempt_time']);
try {
    $pdo->prepare('DELETE FROM login_attempts WHERE ip_address = :ip')->execute([':ip' => $clientIp]);
} catch (Throwable $e) {}

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
