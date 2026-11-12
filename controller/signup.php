<?php
/**
 * GamitShare — Sign-up Controller
 * controller/signup.php
 *
 * Handles POST /controller/signup.php
 * Accepts JSON  { "name": "...", "email": "...", "password": "...", "confirm": "..." }
 * Returns  JSON { "success": true,  "user": { name, email, role } }
 *       or JSON { "success": false, "message": "..." }
 *
 * New accounts always receive the "Member" role.
 * They are added to organization_id = 1 (Barangay Central) by default;
 * update DEFAULT_ORG_ID if you add multi-tenant support.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

// ── Security headers ────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Default organization (matches seed data in gamitshare_db.sql) (L-4)
if (!defined('DEFAULT_ORG_ID')) {
    define('DEFAULT_ORG_ID', 1);
}

// PBKDF2 iterations must match auth.js (120 000)
define('PBKDF2_ITERATIONS', 120000);

// ── Read input ──────────────────────────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (str_contains($contentType, 'application/json')) {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $name    = trim($body['name']     ?? '');
    $email   = trim(strtolower($body['email']    ?? ''));
    $pass    = $body['password'] ?? '';
    $confirm = $body['confirm']  ?? '';
} else {
    $name    = trim($_POST['name']    ?? '');
    $email   = trim(strtolower($_POST['email']   ?? ''));
    $pass    = $_POST['password'] ?? '';
    $confirm = $_POST['confirm']  ?? '';
}

// ── Server-side validation ──────────────────────────────────────────────────
$errors = [];

if ($name === '') {
    $errors[] = 'Your name is required.';
} elseif (mb_strlen($name) > 70) {
    $errors[] = 'Name must be 70 characters or fewer.';
}

if ($email === '') {
    $errors[] = 'Email address is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
}

if (strlen($pass) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
}

if ($pass !== $confirm) {
    $errors[] = "Those passwords don't match yet.";
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// ── Check for duplicate email ────────────────────────────────────────────────
try {
    $pdo  = db_connect();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);

    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'An account with that email already exists. Try signing in instead.']);
        exit;
    }
} catch (RuntimeException | PDOException $e) {
    error_log('GamitShare signup DB check error: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Service temporarily unavailable. Please try again.']);
    exit;
}

// ── Hash the password  ───────────────────────────────────────────────────────
// Generate a 16-byte random salt, store it as Base64 — identical format to
// what auth.js does: crypto.getRandomValues(new Uint8Array(16)) then btoa()
$saltBytes = random_bytes(16);
$saltB64   = base64_encode($saltBytes);

// PBKDF2-SHA256, 120 000 iterations, 256-bit output → 64 hex chars
// Matches auth.js: deriveBits({ name:'PBKDF2', hash:'SHA-256', iterations:120000 }, key, 256)
$passwordHash = hash_pbkdf2('sha256', $pass, $saltBytes, PBKDF2_ITERATIONS, 64, false);

// ── Insert new user ─────────────────────────────────────────────────────────
try {
    $ins = $pdo->prepare(
        'INSERT INTO users
           (organization_id, name, email, password_hash, salt, role, joined_date)
         VALUES
           (:org, :name, :email, :hash, :salt, :role, CURDATE())'
    );
    $ins->execute([
        ':org'   => DEFAULT_ORG_ID,
        ':name'  => $name,
        ':email' => $email,
        ':hash'  => $passwordHash,
        ':salt'  => $saltB64,
        ':role'  => 'Member',
    ]);

    $newUserId = (int)$pdo->lastInsertId();
} catch (PDOException $e) {
    error_log('GamitShare signup insert error: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Your account could not be created. Please try again.']);
    exit;
}

// ── Log the new membership in activity_log ───────────────────────────────────
try {
    $log = $pdo->prepare(
        'INSERT INTO activity_log (organization_id, performed_by, icon, message)
         VALUES (:org, :uid, :icon, :msg)'
    );
    $log->execute([
        ':org'  => DEFAULT_ORG_ID,
        ':uid'  => $newUserId,
        ':icon' => '✳',
        ':msg'  => $name . ' joined as a member',
    ]);
} catch (PDOException $e) {
    // Non-fatal — account was created; just log
    error_log('GamitShare activity log insert failed: ' . $e->getMessage());
}

// ── Auto-login: start a session for the new user ─────────────────────────────
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
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
session_regenerate_id(true);

$_SESSION['user_id'] = $newUserId;
$_SESSION['name']    = $name;
$_SESSION['email']   = $email;
$_SESSION['role']    = 'Member';

// Persist session to DB
try {
    $sessionId = session_id();
    $expiresAt = date('Y-m-d H:i:s', time() + 60 * 60 * 8);
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

    $ses = $pdo->prepare(
        'INSERT INTO user_sessions (id, user_id, ip_address, user_agent, expires_at)
              VALUES (:id, :uid, :ip, :ua, :exp)'
    );
    $ses->execute([
        ':id'  => $sessionId,
        ':uid' => $newUserId,
        ':ip'  => $ipAddress,
        ':ua'  => $userAgent,
        ':exp' => $expiresAt,
    ]);
} catch (PDOException $e) {
    error_log('GamitShare new-user session insert failed: ' . $e->getMessage());
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
    echo json_encode([
        'success' => false,
        'message' => 'Your account was created, but automatic sign-in failed. Please sign in.',
    ]);
    exit;
}

// ── Success ──────────────────────────────────────────────────────────────────
http_response_code(201);
echo json_encode([
    'success'  => true,
    'message'  => 'Welcome to GamitShare, ' . $name . '!',
    'redirect' => '../views/member/dashboard.html',
    'user'     => [
        'name'  => $name,
        'email' => $email,
        'role'  => 'Member',
    ],
]);

// ── Helper ───────────────────────────────────────────────────────────────────
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}
