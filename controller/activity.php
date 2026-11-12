<?php
/**
 * GamitShare — Activity Log Controller
 * controller/activity.php
 *
 * Handles:
 *  - GET  /controller/activity.php  → Get recent community activity feed
 *  - POST /controller/activity.php  → Append an activity entry (stewards)
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

init_api_headers();

try {
    $pdo = db_connect();
} catch (RuntimeException $e) {
    json_error('Service temporarily unavailable. Please try again.', 503);
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: FETCH ACTIVITY FEED ─────────────────────────────────────────────────
if ($method === 'GET') {
    require_auth($pdo);
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 12;

    $stmt = $pdo->prepare(
        'SELECT al.id, al.icon, al.message AS text, DATE(al.created_at) AS date,
                al.created_at, u.name AS performed_by_name
           FROM activity_log al
           LEFT JOIN users u ON u.id = al.performed_by
          ORDER BY al.created_at DESC, al.id DESC
          LIMIT :lim'
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $activities = $stmt->fetchAll();

    json_response([
        'success'  => true,
        'count'    => count($activities),
        'activity' => $activities,
    ]);
}

// ── POST: APPEND ACTIVITY ────────────────────────────────────────────────────
if ($method === 'POST') {
    $user = require_steward($pdo);
    $data = get_request_data();

    $text  = trim((string)($data['text'] ?? $data['message'] ?? ''));
    $icon  = trim((string)($data['icon'] ?? '✳')) ?: '✳';
    $reqId = !empty($data['requestId']) ? (int)$data['requestId'] : null;
    $eqId  = !empty($data['equipmentId']) ? (int)$data['equipmentId'] : null;

    if ($text === '') {
        json_error('Activity message text is required.');
    }

    log_activity(
        $pdo,
        (int)($user['organization_id'] ?? DEFAULT_ORG_ID),
        (int)$user['id'],
        $icon,
        $text,
        $reqId,
        $eqId
    );

    json_response([
        'success' => true,
        'message' => 'Activity logged.',
    ]);
}

json_error('Method not allowed.', 405);

