<?php
/**
 * GamitShare — Borrowing Requests & Approvals Controller
 * controller/requests.php
 *
 * Handles:
 *  - GET  /controller/requests.php                 → List borrowing requests (with filters)
 *  - POST /controller/requests.php?action=create   → Create a new borrow request
 *  - POST /controller/requests.php?action=approve  → Approve a pending request (steward)
 *  - POST /controller/requests.php?action=reject   → Decline a pending request (steward)
 *  - POST /controller/requests.php?action=return   → Record check-in / equipment return (steward)
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

init_api_headers();

try {
    $pdo = db_connect();
} catch (RuntimeException $e) {
    json_error('Database connection unavailable: ' . $e->getMessage(), 503);
}

$method = $_SERVER['REQUEST_METHOD'];
$data   = get_request_data();
$action = $data['action'] ?? $_GET['action'] ?? '';

// ── GET REQUESTS ─────────────────────────────────────────────────────────────
if ($method === 'GET') {
    // Keep overdue statuses current when listing requests, not on every write.
    try {
        $pdo->exec(
            "UPDATE borrow_requests
                SET status = 'Overdue'
              WHERE status = 'Approved'
                AND due_date < CURDATE()"
        );
    } catch (PDOException $e) {
        error_log('Failed to update overdue requests: ' . $e->getMessage());
    }

    $user = get_current_user_account($pdo);

    $status     = trim((string)($_GET['status'] ?? ''));
    $q          = trim((string)($_GET['q'] ?? ''));
    $memberOnly = !empty($_GET['member_only']) || $action === 'my_requests';
    $emailParam = trim((string)($_GET['email'] ?? ''));

    // Base query
    $sql = 'SELECT br.id, br.organization_id, br.equipment_id, br.user_id, br.reviewed_by,
                   br.quantity, br.start_date, br.due_date, br.purpose, br.status,
                   br.condition_out, br.return_condition, br.return_notes, br.returned_at,
                   br.reviewed_at, br.created_at,
                   u.name  AS borrower_name,
                   u.email AS borrower_email,
                   e.name  AS equipment_name,
                   e.emoji AS equipment_emoji,
                   e.condition_state AS equipment_condition,
                   ec.name AS equipment_category
              FROM borrow_requests br
              JOIN users u ON u.id = br.user_id
              JOIN equipment e ON e.id = br.equipment_id
              JOIN equipment_categories ec ON ec.id = e.category_id
             WHERE 1=1';

    $params = [];

    // Filter by member — use authenticated user ID (never trust client email param) (M-6)
    if ($user && $user['role'] === 'Member') {
        $sql .= ' AND br.user_id = :user_id';
        $params[':user_id'] = (int)$user['id'];
    } elseif ($memberOnly && $user) {
        $sql .= ' AND br.user_id = :user_id';
        $params[':user_id'] = (int)$user['id'];
    }

    if ($status !== '') {
        $sql .= ' AND br.status = :status';
        $params[':status'] = $status;
    }

    if ($q !== '') {
        $sql .= ' AND (u.name LIKE :q OR u.email LIKE :q OR e.name LIKE :q OR br.purpose LIKE :q)';
        $params[':q'] = "%{$q}%";
    }

    $sql .= ' ORDER BY br.created_at DESC, br.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Map into format matching frontend expectations
    $requests = array_map(static function (array $row): array {
        return [
            'id'              => (string)$row['id'],
            'equipmentId'     => (string)$row['equipment_id'],
            'borrower'        => $row['borrower_name'],
            'email'           => $row['borrower_email'],
            'quantity'        => (int)$row['quantity'],
            'startDate'       => $row['start_date'],
            'dueDate'         => $row['due_date'],
            'purpose'         => $row['purpose'],
            'status'          => $row['status'],
            'conditionOut'    => $row['condition_out'] ?? 'Good',
            'returnCondition' => $row['return_condition'],
            'returnNotes'     => $row['return_notes'],
            'returnedAt'      => $row['returned_at'],
            'createdAt'       => substr((string)$row['created_at'], 0, 10),
            'equipment'       => [
                'id'        => (string)$row['equipment_id'],
                'name'      => $row['equipment_name'],
                'emoji'     => $row['equipment_emoji'],
                'category'  => $row['equipment_category'],
                'condition' => $row['equipment_condition'],
            ],
        ];
    }, $rows);

    json_response([
        'success'  => true,
        'count'    => count($requests),
        'requests' => $requests,
    ]);
}

// ── POST: CREATE BORROW REQUEST ──────────────────────────────────────────────
if ($action === 'create' || ($method === 'POST' && empty($action))) {
    $equipId   = (int)($data['equipmentId'] ?? $data['equipment_id'] ?? 0);
    $quantity  = (int)($data['quantity'] ?? 1);
    $startDate = trim((string)($data['startDate'] ?? $data['start_date'] ?? ''));
    $dueDate   = trim((string)($data['dueDate'] ?? $data['due_date'] ?? ''));
    $purpose   = trim((string)($data['purpose'] ?? ''));

    // Input validation
    if ($equipId <= 0) {
        json_error('Please select valid equipment.');
    }
    if ($quantity < 1) {
        json_error('Borrowing quantity must be at least 1.');
    }
    if ($startDate === '' || $dueDate === '') {
        json_error('Start date and expected return date are required.');
    }

    // Validate date formats (L-2)
    $startDt = DateTime::createFromFormat('Y-m-d', $startDate);
    $dueDt   = DateTime::createFromFormat('Y-m-d', $dueDate);
    if (!$startDt || !$dueDt || $startDt->format('Y-m-d') !== $startDate || $dueDt->format('Y-m-d') !== $dueDate) {
        json_error('Invalid date format. Please use YYYY-MM-DD.');
    }
    $today = date('Y-m-d');
    if ($startDate < $today) {
        json_error('Start date cannot be in the past.');
    }
    if ($dueDate <= $startDate) {
        json_error('Return date must be after the start date.');
    }
    if ($purpose === '') {
        json_error('Please tell us the intended use or project purpose.');
    }

    // Resolve user — MUST be the authenticated session user (fixes C-2 IDOR)
    $user = require_auth($pdo);
    $userId   = (int)$user['id'];
    $userName = $user['name'];

    try {
        $pdo->beginTransaction();

        // Lock equipment row to prevent race conditions (M-2, H-4)
        $eqStmt = $pdo->prepare(
            'SELECT id, name, available, condition_state, organization_id, status
               FROM equipment WHERE id = :id FOR UPDATE'
        );
        $eqStmt->execute([':id' => $equipId]);
        $equipment = $eqStmt->fetch();

        if (!$equipment) {
            $pdo->rollBack();
            json_error('Selected equipment was not found.', 404);
        }
        if ($equipment['status'] !== 'Available' || (int)$equipment['available'] < $quantity) {
            $pdo->rollBack();
            json_error("Only {$equipment['available']} units are currently available.", 400);
        }

        $orgId = (int)$equipment['organization_id'];

        $ins = $pdo->prepare(
            'INSERT INTO borrow_requests
                (organization_id, equipment_id, user_id, quantity, start_date, due_date,
                 purpose, status, condition_out, created_at)
             VALUES
                (:org, :equip, :user, :qty, :start, :due, :purpose, :status, :cond, NOW())'
        );
        $ins->execute([
            ':org'     => $orgId,
            ':equip'   => $equipId,
            ':user'    => $userId,
            ':qty'     => $quantity,
            ':start'   => $startDate,
            ':due'     => $dueDate,
            ':purpose' => $purpose,
            ':status'  => 'Pending',
            ':cond'    => $equipment['condition_state'],
        ]);

        $newReqId = (int)$pdo->lastInsertId();

        log_activity(
            $pdo,
            $orgId,
            $userId,
            '↗',
            "{$userName} requested {$equipment['name']}",
            $newReqId,
            $equipId
        );

        $pdo->commit();

        json_response([
            'success' => true,
            'message' => "Request sent! We’ll let you know when it’s reviewed.",
            'id'      => $newReqId,
        ], 201);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('GamitShare borrow request error: ' . $e->getMessage());
        json_error('A database error occurred. Please try again.', 500);
    }
}

// ── STEWARD ACTIONS: APPROVE / REJECT / RETURN ────────────────────────────────
$currentUser = require_steward($pdo);
$requestId   = (int)($data['id'] ?? $data['requestId'] ?? $data['request_id'] ?? 0);

if ($requestId <= 0) {
    json_error('Valid Request ID is required.');
}

// Fetch request with equipment details
$reqStmt = $pdo->prepare(
    'SELECT br.*, e.name AS equipment_name, e.available AS equipment_available,
            e.quantity AS equipment_total, e.condition_state, e.status AS equipment_status, u.name AS borrower_name
       FROM borrow_requests br
       JOIN equipment e ON e.id = br.equipment_id
       JOIN users u ON u.id = br.user_id
      WHERE br.id = :id
      LIMIT 1'
);
$reqStmt->execute([':id' => $requestId]);
$request = $reqStmt->fetch();

if (!$request) {
    json_error('Borrow request not found.', 404);
}

// ── APPROVE ──────────────────────────────────────────────────────────────────
if ($action === 'approve') {
    if ($request['status'] !== 'Pending') {
        json_error('Only pending requests can be approved.');
    }

    try {
        $pdo->beginTransaction();

        $requestLockStmt = $pdo->prepare(
            'SELECT id, equipment_id, quantity, status
               FROM borrow_requests
              WHERE id = :id
              FOR UPDATE'
        );
        $requestLockStmt->execute([':id' => $requestId]);
        $lockedRequest = $requestLockStmt->fetch();
        if (!$lockedRequest || $lockedRequest['status'] !== 'Pending') {
            $pdo->rollBack();
            json_error('Only pending requests can be approved.');
        }

        // Lock row to prevent simultaneous approvals from overselling
        $lockStmt = $pdo->prepare(
            'SELECT name, available, condition_state
               FROM equipment
              WHERE id = :id
              FOR UPDATE'
        );
        $lockStmt->execute([':id' => $lockedRequest['equipment_id']]);
        $lockedItem = $lockStmt->fetch();
        if (!$lockedItem) {
            $pdo->rollBack();
            json_error('Equipment record not found.', 404);
        }

        $reqQty   = (int)$lockedRequest['quantity'];
        $availQty = (int)$lockedItem['available'];

        if ($availQty < $reqQty) {
            $pdo->rollBack();
            json_error('There isn’t enough equipment available to approve this request.', 400);
        }

        // 1. Update request
        $updReq = $pdo->prepare(
            "UPDATE borrow_requests
                SET status        = 'Approved',
                    condition_out = :cond,
                    reviewed_by   = :reviewer,
                    reviewed_at   = NOW()
              WHERE id = :id AND status = 'Pending'"
        );
        $updReq->execute([
            ':cond'     => $lockedItem['condition_state'],
            ':reviewer' => $currentUser['id'],
            ':id'       => $requestId,
        ]);
        if ($updReq->rowCount() !== 1) {
            $pdo->rollBack();
            json_error('Only pending requests can be approved.');
        }

        // 2. Decrement equipment availability
        $newAvail = $availQty - $reqQty;
        $newStatus = $newAvail === 0 ? 'Borrowed' : 'Available';

        $updEq = $pdo->prepare(
            'UPDATE equipment
                SET available = :avail,
                    status    = :status
              WHERE id = :id'
        );
        $updEq->execute([
            ':avail'  => $newAvail,
            ':status' => $newStatus,
            ':id'     => $request['equipment_id'],
        ]);

        // 3. Activity log
        log_activity(
            $pdo,
            (int)$request['organization_id'],
            (int)$currentUser['id'],
            '✓',
            "{$currentUser['name']} approved {$lockedItem['name']}",
            $requestId,
            (int)$lockedRequest['equipment_id']
        );

        $pdo->commit();

        json_response([
            'success'         => true,
            'message'         => 'Request approved.',
            'requestId'       => (string)$requestId,
            'equipmentId'     => (string)$lockedRequest['equipment_id'],
            'conditionOut'    => $lockedItem['condition_state'],
            'available'       => $newAvail,
            'equipmentStatus' => $newStatus,
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('GamitShare approve request error: ' . $e->getMessage());
        json_error('A database error occurred. Please try again.', 500);
    }
}

// ── REJECT ───────────────────────────────────────────────────────────────────
if ($action === 'reject') {
    if ($request['status'] !== 'Pending') {
        json_error('Only pending requests can be rejected.');
    }

    try {
        $updReq = $pdo->prepare(
            "UPDATE borrow_requests
                SET status      = 'Rejected',
                    reviewed_by = :reviewer,
                    reviewed_at = NOW()
              WHERE id = :id"
        );
        $updReq->execute([
            ':reviewer' => $currentUser['id'],
            ':id'       => $requestId,
        ]);

        log_activity(
            $pdo,
            (int)$request['organization_id'],
            (int)$currentUser['id'],
            '×',
            "{$currentUser['name']} declined a borrowing request",
            $requestId,
            (int)$request['equipment_id']
        );

        json_response([
            'success' => true,
            'message' => 'Request declined.',
        ]);
    } catch (PDOException $e) {
        error_log('GamitShare reject request error: ' . $e->getMessage());
        json_error('A database error occurred. Please try again.', 500);
    }
}

// ── RECORD RETURN ────────────────────────────────────────────────────────────
if ($action === 'return' || $action === 'checkin') {
    if (!in_array($request['status'], ['Approved', 'Overdue'], true)) {
        json_error('Only active borrowings (Approved or Overdue) can be checked in.');
    }

    $returnCondition = trim((string)($data['condition'] ?? 'Good'));
    $returnNotes     = trim((string)($data['notes'] ?? ''));

    $validConditions = ['Good', 'Fair', 'Needs repair', 'Damaged', 'Missing', 'Incomplete'];
    if (!in_array($returnCondition, $validConditions, true)) {
        $returnCondition = 'Good';
    }

    try {
        $pdo->beginTransaction();

        // Lock & re-read fresh equipment state to avoid stale count race condition (M-5)
        $lockEq = $pdo->prepare(
            'SELECT quantity, available, condition_state, status FROM equipment WHERE id = :id FOR UPDATE'
        );
        $lockEq->execute([':id' => $request['equipment_id']]);
        $lockedEquip = $lockEq->fetch();

        if (!$lockedEquip) {
            $pdo->rollBack();
            json_error('Equipment record not found.', 404);
        }

        // 1. Update borrow request
        $updReq = $pdo->prepare(
            "UPDATE borrow_requests
                SET status           = 'Returned',
                    return_condition = :cond,
                    return_notes     = :notes,
                    returned_at      = CURDATE()
              WHERE id = :id"
        );
        $updReq->execute([
            ':cond'  => $returnCondition,
            ':notes' => $returnNotes,
            ':id'    => $requestId,
        ]);

        // 2. Adjust inventory with fresh numbers
        $qtyBorrowed  = (int)$request['quantity'];
        $currQuantity = (int)$lockedEquip['quantity'];
        $currAvail    = (int)$lockedEquip['available'];

        if ($returnCondition === 'Missing') {
            $newQuantity = max(0, $currQuantity - $qtyBorrowed);
            $newAvail    = min($newQuantity, $currAvail);
            $newStatus   = $newAvail > 0 ? 'Available' : 'Borrowed';
            $newCond     = $request['condition_state'];
        } else {
            $newQuantity = $currQuantity;
            $newAvail    = min($newQuantity, $currAvail + $qtyBorrowed);

            if (in_array($returnCondition, ['Damaged', 'Needs repair'], true)) {
                $newCond   = $returnCondition;
                $newStatus = 'Damaged';
            } elseif ($returnCondition === 'Incomplete') {
                $newCond   = 'Fair';
                $newStatus = $newAvail > 0 ? 'Available' : 'Borrowed';
            } else {
                $newCond   = $request['condition_state'];
                $newStatus = $newAvail > 0 ? 'Available' : 'Borrowed';
            }
        }

        $updEq = $pdo->prepare(
            'UPDATE equipment
                SET quantity        = :qty,
                    available       = :avail,
                    condition_state = :cond,
                    status          = :status
              WHERE id = :id'
        );
        $updEq->execute([
            ':qty'    => $newQuantity,
            ':avail'  => $newAvail,
            ':cond'   => $newCond,
            ':status' => $newStatus,
            ':id'     => $request['equipment_id'],
        ]);

        // If returned damaged or needs repair, also log to equipment_maintenance
        if (in_array($returnCondition, ['Damaged', 'Needs repair', 'Missing'], true)) {
            $maint = $pdo->prepare(
                'INSERT INTO equipment_maintenance
                    (equipment_id, reported_by, issue_notes, status_before, status_after,
                     condition_before, condition_after, created_at)
                 VALUES
                    (:equip, :rep, :notes, :s_bef, :s_aft, :c_bef, :c_aft, NOW())'
            );
            $maint->execute([
                ':equip' => $request['equipment_id'],
                ':rep'   => $currentUser['id'],
                ':notes' => "Return check-in condition: {$returnCondition}. Notes: " . ($returnNotes ?: 'None'),
                ':s_bef' => $request['equipment_status'],
                ':s_aft' => $newStatus,
                ':c_bef' => $request['condition_state'],
                ':c_aft' => $newCond,
            ]);
        }

        // 3. Log activity
        $icon = $returnCondition === 'Good' ? '↩' : '!';
        log_activity(
            $pdo,
            (int)$request['organization_id'],
            (int)$currentUser['id'],
            $icon,
            "{$request['borrower_name']} returned {$request['equipment_name']} · " . strtolower($returnCondition),
            $requestId,
            (int)$request['equipment_id']
        );

        $pdo->commit();

        json_response([
            'success' => true,
            'message' => 'Return recorded and condition noted.',
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('GamitShare return record error: ' . $e->getMessage());
        json_error('A database error occurred. Please try again.', 500);
    }
}

json_error('Invalid action or method.', 400);

