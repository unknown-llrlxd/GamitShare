<?php
/**
 * GamitShare — Session Check Controller
 * controller/session.php
 *
 * GET  /controller/session.php  → returns the currently logged-in user
 * DELETE /controller/session.php → logs out (destroys session)
 *
 * Called by dashboard.js / auth.js when replacing localStorage with real
 * server-side auth.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// The session ID is validated against TiDB so authentication works across
// independent serverless function instances.
session_name('gamitshare_session');
$sessionId = $_COOKIE[session_name()] ?? '';

// ── Logout ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' || ($_GET['action'] ?? '') === 'logout') {
    if (is_string($sessionId) && $sessionId !== '') {
        try {
            $pdo  = db_connect();
            $stmt = $pdo->prepare('DELETE FROM user_sessions WHERE id = :sid');
            $stmt->execute([':sid' => $sessionId]);
        } catch (RuntimeException | PDOException $e) {
            error_log('GamitShare logout DB error: ' . $e->getMessage());
            http_response_code(503);
            echo json_encode(['success' => false, 'message' => 'Could not sign out. Please try again.']);
            exit;
        }
    }

    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'You have been signed out.']);
    exit;
}

// ── Validate the session against DB ──────────────────────────────────────────
if (!is_string($sessionId) || $sessionId === '' || strlen($sessionId) > 128) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not signed in.', 'authenticated' => false]);
    exit;
}

try {
    $pdo  = db_connect();

    // Check the session row hasn't expired
    $stmt = $pdo->prepare(
        'SELECT s.id, u.id AS user_id, u.name, u.email, u.role, u.is_active
           FROM user_sessions s
           JOIN users u ON u.id = s.user_id
          WHERE s.id = :sid AND s.expires_at > NOW()
          LIMIT 1'
    );
    $stmt->execute([':sid' => $sessionId]);
    $row = $stmt->fetch();
} catch (RuntimeException | PDOException $e) {
    error_log('GamitShare session verify error: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Service unavailable.']);
    exit;
}

if (!$row || !(bool)$row['is_active']) {
    // Session expired or account deactivated — clean up
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired. Please sign in again.', 'authenticated' => false]);
    exit;
}

// ── Return the authenticated user ─────────────────────────────────────────────
$isSteward = in_array($row['role'], ['Administrator', 'Organization officer', 'Custodian'], true);

http_response_code(200);
echo json_encode([
    'success'       => true,
    'authenticated' => true,
    'redirect'      => $isSteward ? '../views/admin/dashboard.html' : '../views/member/dashboard.html',
    'user'          => [
        'name'  => $row['name'],
        'email' => $row['email'],
        'role'  => $row['role'],
    ],
]);

// ── Helper ───────────────────────────────────────────────────────────────────
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}
