<?php
/**
 * GamitShare — Shared Backend Helpers & Middleware
 * controller/helpers.php
 *
 * Provides JSON request/response handling, session authentication,
 * role authorization, and centralized activity logging.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

define('DEFAULT_ORG_ID', 1);

/**
 * Initializes standard API response headers, CORS, and handles preflight OPTIONS.
 */
function init_api_headers(): void
{
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
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Reads request input from JSON body or $_POST/$_GET.
 *
 * @return array<string, mixed>
 */
function get_request_data(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && trim($raw) !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return $json;
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return $_POST;
    }

    return $_GET;
}

/**
 * Retrieves the currently authenticated user.
 * Verifies the HTTP-only session cookie against the persistent database session.
 *
 * @param PDO $pdo
 * @return array<string, mixed>|null
 */
function get_current_user_account(PDO $pdo): ?array
{
    session_name('gamitshare_session');
    $sessionId = $_COOKIE[session_name()] ?? '';
    if (!is_string($sessionId) || $sessionId === '' || strlen($sessionId) > 128) {
        return null;
    }

    // Vercel may route consecutive requests to different instances, so PHP's
    // local session files are not authoritative; the TiDB session row is.
    $stmt = $pdo->prepare(
        'SELECT u.id, u.organization_id, u.name, u.email, u.role, u.is_active
           FROM user_sessions s
           JOIN users u ON u.id = s.user_id
          WHERE s.id = :sid AND s.expires_at > NOW() AND u.is_active = 1
          LIMIT 1'
    );
    $stmt->execute([':sid' => $sessionId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/**
 * Requires an authenticated user; exits with 401 if unauthenticated.
 *
 * @param PDO $pdo
 * @return array<string, mixed>
 */
function require_auth(PDO $pdo): array
{
    $user = get_current_user_account($pdo);
    if (!$user) {
        json_error('Authentication required. Please sign in.', 401);
    }
    return $user;
}

/**
 * Requires steward role: Administrator, Organization officer, or Custodian.
 *
 * @param PDO $pdo
 * @return array<string, mixed>
 */
function require_steward(PDO $pdo): array
{
    $user = require_auth($pdo);
    $stewardRoles = ['Administrator', 'Organization officer', 'Custodian'];
    if (!in_array($user['role'], $stewardRoles, true)) {
        json_error('Access denied. Steward privileges required.', 403);
    }
    return $user;
}

/**
 * Requires Administrator role only.
 *
 * @param PDO $pdo
 * @return array<string, mixed>
 */
function require_admin(PDO $pdo): array
{
    $user = require_auth($pdo);
    if ($user['role'] !== 'Administrator') {
        json_error('Access denied. Administrator privileges required.', 403);
    }
    return $user;
}

/**
 * Logs an event to the activity_log table.
 */
function log_activity(
    PDO $pdo,
    int $orgId,
    ?int $userId,
    string $icon,
    string $message,
    ?int $requestId = null,
    ?int $equipmentId = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO activity_log
                (organization_id, performed_by, icon, message, related_request_id, related_equipment_id, created_at)
             VALUES
                (:org, :user, :icon, :msg, :req, :equip, NOW())'
        );
        $stmt->execute([
            ':org'   => $orgId,
            ':user'  => $userId,
            ':icon'  => mb_substr($icon, 0, 4),
            ':msg'   => mb_substr($message, 0, 255),
            ':req'   => $requestId,
            ':equip' => $equipmentId,
        ]);
    } catch (PDOException $e) {
        error_log('Failed to log activity: ' . $e->getMessage());
    }
}

/**
 * Sends a successful JSON response and terminates.
 *
 * @param mixed $data
 * @param int $statusCode
 */
function json_response($data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Sends an error JSON response and terminates.
 *
 * @param string $message
 * @param int $statusCode
 */
function json_error(string $message, int $statusCode = 400): void
{
    json_response(['success' => false, 'message' => $message], $statusCode);
}
