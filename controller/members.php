<?php
/**
 * GamitShare — User Accounts & Role Management Controller
 * controller/members.php
 *
 * Handles:
 *  - GET  /controller/members.php                     → List community members (stewards)
 *  - POST /controller/members.php?action=create       → Add a new person/profile (admin)
 *  - POST /controller/members.php?action=update_role  → Update person's role (admin)
 *  - POST /controller/members.php?action=delete       → Remove/deactivate person (admin)
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
$data   = get_request_data();
$action = $data['action'] ?? $_GET['action'] ?? '';

// ── GET: LIST MEMBERS ─────────────────────────────────────────────────────────
if ($method === 'GET') {
    // Check steward access
    $currentUser = require_steward($pdo);

    $stmt = $pdo->query(
        'SELECT id, organization_id, name, email, role, is_active, joined_date, created_at
           FROM users
          WHERE is_active = 1
          ORDER BY FIELD(role, "Administrator", "Organization officer", "Custodian", "Member"), name ASC'
    );
    $rows = $stmt->fetchAll();

    $members = array_map(static function (array $row): array {
        return [
            'id'       => (string)$row['id'],
            'name'     => $row['name'],
            'email'    => $row['email'],
            'role'     => $row['role'],
            'joined'   => $row['joined_date'],
            'isActive' => (bool)$row['is_active'],
        ];
    }, $rows);

    json_response([
        'success' => true,
        'count'   => count($members),
        'members' => $members,
    ]);
}

// ── WRITE ACTIONS: REQUIRE ADMINISTRATOR ──────────────────────────────────────
$currentUser = require_admin($pdo);
$validRoles  = ['Administrator', 'Organization officer', 'Custodian', 'Member'];

// ── ADD MEMBER ───────────────────────────────────────────────────────────────
if ($action === 'create' || ($method === 'POST' && empty($action) && isset($data['name'], $data['email']))) {
    $name  = trim((string)($data['name'] ?? ''));
    $email = trim(strtolower((string)($data['email'] ?? '')));
    $role  = trim((string)($data['role'] ?? 'Member'));

    if ($name === '') {
        json_error('Person name is required.');
    }
    if (mb_strlen($name) > 70) {
        json_error('Name must be 70 characters or fewer.');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('A valid email address is required.');
    }
    if (!in_array($role, $validRoles, true)) {
        $role = 'Member';
    }

    // Check duplicate
    $chk = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = :email LIMIT 1');
    $chk->execute([':email' => $email]);
    if ($chk->fetch()) {
        json_error('A person with this email is already in the community.', 409);
    }

    // Generate initial password hash (default: member12345 or role-specific)
    $initialPw = strtolower(explode(' ', $role)[0]) . '12345';
    $saltBytes = random_bytes(16);
    $saltB64   = base64_encode($saltBytes);
    $hash      = hash_pbkdf2('sha256', $initialPw, $saltBytes, 120000, 64, false);

    $orgId = (int)($currentUser['organization_id'] ?? DEFAULT_ORG_ID);

    try {
        $ins = $pdo->prepare(
            'INSERT INTO users
                (organization_id, name, email, password_hash, salt, role, joined_date, is_active)
             VALUES
                (:org, :name, :email, :hash, :salt, :role, CURDATE(), 1)'
        );
        $ins->execute([
            ':org'   => $orgId,
            ':name'  => $name,
            ':email' => $email,
            ':hash'  => $hash,
            ':salt'  => $saltB64,
            ':role'  => $role,
        ]);

        $newId = (int)$pdo->lastInsertId();

        log_activity(
            $pdo,
            $orgId,
            (int)$currentUser['id'],
            '♧',
            "{$name} joined as a " . strtolower($role)
        );

        json_response([
            'success' => true,
            'message' => "{$name} has been added to the community.",
            'id'      => $newId,
            'member'  => [
                'id'     => (string)$newId,
                'name'   => $name,
                'email'  => $email,
                'role'   => $role,
                'joined' => date('Y-m-d'),
            ],
        ], 201);
    } catch (PDOException $e) {
        error_log('GamitShare member add error: ' . $e->getMessage());
        json_error('A database error occurred. Please try again.', 500);
    }
}

// ── UPDATE ROLE ──────────────────────────────────────────────────────────────
if ($action === 'update_role' || $action === 'change_role') {
    $targetId = (int)($data['id'] ?? $data['user_id'] ?? 0);
    $newRole  = trim((string)($data['role'] ?? ''));

    if ($targetId <= 0) {
        json_error('Valid user ID is required.');
    }
    if (!in_array($newRole, $validRoles, true)) {
        json_error('Invalid role specified.');
    }

    // Fetch target user
    $tStmt = $pdo->prepare('SELECT id, name, email, role, organization_id FROM users WHERE id = :id LIMIT 1');
    $tStmt->execute([':id' => $targetId]);
    $target = $tStmt->fetch();

    if (!$target) {
        json_error('User not found.', 404);
    }

    // Safety rule: Admin cannot demote themselves if they are the only Administrator
    if ((int)$target['id'] === (int)$currentUser['id'] && $newRole !== 'Administrator') {
        $countAdmin = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Administrator' AND is_active = 1")->fetchColumn();
        if ((int)$countAdmin <= 1) {
            json_error('Keep at least one administrator on your own account.', 400);
        }
    }

    try {
        $upd = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
        $upd->execute([':role' => $newRole, ':id' => $targetId]);

        log_activity(
            $pdo,
            (int)$target['organization_id'],
            (int)$currentUser['id'],
            '♧',
            "{$target['name']} is now a " . strtolower($newRole)
        );

        json_response([
            'success' => true,
            'message' => "{$target['name']}’s role was updated.",
            'id'      => $targetId,
            'role'    => $newRole,
        ]);
    } catch (PDOException $e) {
        error_log('GamitShare role update error: ' . $e->getMessage());
        json_error('A database error occurred. Please try again.', 500);
    }
}

// ── REMOVE / DEACTIVATE MEMBER ───────────────────────────────────────────────
if ($method === 'DELETE' || $action === 'delete' || $action === 'remove') {
    $targetId = (int)($data['id'] ?? $data['user_id'] ?? $_GET['id'] ?? 0);

    if ($targetId <= 0) {
        json_error('Valid user ID is required.');
    }

    if ($targetId === (int)$currentUser['id']) {
        json_error('You cannot remove your own active administrator account.', 400);
    }

    $tStmt = $pdo->prepare('SELECT id, name, email, role, organization_id FROM users WHERE id = :id LIMIT 1');
    $tStmt->execute([':id' => $targetId]);
    $target = $tStmt->fetch();

    if (!$target) {
        json_error('User not found.', 404);
    }

    // Check for active borrowings
    $chk = $pdo->prepare(
        "SELECT COUNT(*) FROM borrow_requests
          WHERE user_id = :uid AND status IN ('Approved', 'Overdue')"
    );
    $chk->execute([':uid' => $targetId]);
    if ((int)$chk->fetchColumn() > 0) {
        json_error("{$target['name']} currently has active borrowed equipment and cannot be removed.", 400);
    }

    try {
        // Soft delete / deactivate so historical requests remain consistent
        $del = $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :id');
        $del->execute([':id' => $targetId]);

        log_activity(
            $pdo,
            (int)$target['organization_id'],
            (int)$currentUser['id'],
            '×',
            "{$target['name']} left the community"
        );

        json_response([
            'success' => true,
            'message' => 'Person removed from the community.',
        ]);
    } catch (PDOException $e) {
        error_log('GamitShare remove person error: ' . $e->getMessage());
        json_error('A database error occurred. Please try again.', 500);
    }
}

json_error('Invalid action or method.', 400);

