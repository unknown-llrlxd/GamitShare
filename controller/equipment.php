<?php
/**
 * GamitShare — Equipment Inventory Controller
 * controller/equipment.php
 *
 * Handles:
 *  - GET    /controller/equipment.php                → List equipment (filtered by q, category, status)
 *  - GET    /controller/equipment.php?action=categories → List all categories
 *  - GET    /controller/equipment.php?id=X          → Get single equipment details
 *  - POST   /controller/equipment.php               → Add new equipment (admin)
 *      Accepts JSON or form data. Optional photo/photo_url field must be an external HTTP(S) URL.
 *  - POST   /controller/equipment.php?action=update  → Edit equipment (admin)
 *  - POST   /controller/equipment.php?action=delete  → Remove equipment (admin)
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

// ── GET REQUESTS ─────────────────────────────────────────────────────────────
if ($method === 'GET') {
    require_auth($pdo);
    // 1. Get Categories
    if ($action === 'categories') {
        $stmt = $pdo->query('SELECT id, name, emoji FROM equipment_categories ORDER BY name ASC');
        json_response([
            'success'    => true,
            'categories' => $stmt->fetchAll(),
        ]);
    }

    // 2. Get Single Equipment by ID
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if ($id) {
        $stmt = $pdo->prepare(
            'SELECT e.id, e.name, ec.name AS category, e.category_id, e.emoji,
                    e.description, e.photo_url AS photo, e.quantity, e.available,
                    e.condition_state AS `condition`, e.status, e.organization_id, e.created_at
               FROM equipment e
               JOIN equipment_categories ec ON ec.id = e.category_id
              WHERE e.id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch();
        if (!$item) {
            json_error('Equipment not found.', 404);
        }
        json_response(['success' => true, 'equipment' => $item]);
    }

    // 3. List Equipment with Filters
    $q        = trim((string)($_GET['q'] ?? ''));
    $category = trim((string)($_GET['category'] ?? ''));
    $status   = trim((string)($_GET['status'] ?? ''));

    $sql = 'SELECT e.id, e.name, ec.name AS category, e.category_id, e.emoji,
                   e.description, e.photo_url AS photo, e.quantity, e.available,
                   e.condition_state AS `condition`, e.status, e.organization_id, e.created_at
              FROM equipment e
              JOIN equipment_categories ec ON ec.id = e.category_id
             WHERE 1=1';

    $params = [];

    if ($q !== '') {
        $sql .= ' AND (e.name LIKE :q OR ec.name LIKE :q OR e.description LIKE :q)';
        $params[':q'] = "%{$q}%";
    }

    if ($category !== '') {
        if (is_numeric($category)) {
            $sql .= ' AND e.category_id = :cat';
            $params[':cat'] = (int)$category;
        } else {
            $sql .= ' AND ec.name = :cat';
            $params[':cat'] = $category;
        }
    }

    if ($status !== '') {
        $sql .= ' AND e.status = :status';
        $params[':status'] = $status;
    }

    $sql .= ' ORDER BY e.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    json_response([
        'success'   => true,
        'count'     => count($items),
        'equipment' => $items,
    ]);
}

// ── WRITE ACTIONS (Admin Only) ────────────────────────────────────────────────
$currentUser = require_admin($pdo);

// Helper function to resolve category ID from name or ID
function resolve_category_id(PDO $pdo, string|int $catInput, string $emoji = '📦'): int
{
    if (is_numeric($catInput) && (int)$catInput > 0) {
        return (int)$catInput;
    }

    $name = trim((string)$catInput);
    if ($name === '') {
        $name = 'Other';
    }

    $stmt = $pdo->prepare('SELECT id FROM equipment_categories WHERE name = :name LIMIT 1');
    $stmt->execute([':name' => $name]);
    $catId = $stmt->fetchColumn();

    if ($catId) {
        return (int)$catId;
    }

    $ins = $pdo->prepare('INSERT INTO equipment_categories (name, emoji) VALUES (:name, :emoji)');
    $ins->execute([':name' => $name, ':emoji' => $emoji]);
    return (int)$pdo->lastInsertId();
}

// ── DELETE EQUIPMENT ─────────────────────────────────────────────────────────
if ($method === 'DELETE' || $action === 'delete') {
    $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        json_error('Equipment ID is required for deletion.');
    }

    // Check if equipment exists
    $stmt = $pdo->prepare('SELECT id, name FROM equipment WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $item = $stmt->fetch();
    if (!$item) {
        json_error('Equipment not found.', 404);
    }

    // Check if currently borrowed (Approved or Overdue)
    $chk = $pdo->prepare(
        "SELECT COUNT(*) FROM borrow_requests
          WHERE equipment_id = :id AND status IN ('Approved', 'Overdue')"
    );
    $chk->execute([':id' => $id]);
    if ((int)$chk->fetchColumn() > 0) {
        json_error('This equipment is currently borrowed and cannot be removed.', 400);
    }

    try {
        $pdo->beginTransaction();

        // Remove maintenance and request references to satisfy foreign keys
        $pdo->prepare('DELETE FROM equipment_maintenance WHERE equipment_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM borrow_requests WHERE equipment_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM equipment WHERE id = :id')->execute([':id' => $id]);

        log_activity(
            $pdo,
            (int)$currentUser['organization_id'],
            (int)$currentUser['id'],
            '×',
            "Removed {$item['name']} from the collection",
            null,
            $id
        );

        $pdo->commit();

        json_response([
            'success' => true,
            'message' => 'Equipment removed from the collection.',
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('GamitShare equipment delete error: ' . $e->getMessage());
        json_error('A database error occurred. Please try again.', 500);
    }
}

// ── CREATE OR UPDATE EQUIPMENT ───────────────────────────────────────────────
if ($method === 'POST') {
    $id          = !empty($data['id']) ? (int)$data['id'] : null;
    $name        = trim((string)($data['name'] ?? ''));
    $categoryIn  = $data['category'] ?? 'Tools';
    $quantity    = isset($data['quantity']) ? (int)$data['quantity'] : 1;
    $available   = isset($data['available']) ? (int)$data['available'] : $quantity;
    $emoji       = trim((string)($data['emoji'] ?? '📦')) ?: '📦';
    $condition   = trim((string)($data['condition'] ?? 'Good')) ?: 'Good';
    $status      = trim((string)($data['status'] ?? 'Available')) ?: 'Available';
    $description = trim((string)($data['description'] ?? ''));
    $photoInput  = $data['photo'] ?? $data['photo_url'] ?? '';

    if (!is_string($photoInput)) {
        json_error('Photo URL must be a string.');
    }
    $photoUrl = trim($photoInput);
    if ($photoUrl !== '') {
        if (mb_strlen($photoUrl) > 2048 || filter_var($photoUrl, FILTER_VALIDATE_URL) === false) {
            json_error('Photo must be a valid HTTP or HTTPS image URL.');
        }
        $photoScheme = strtolower((string)parse_url($photoUrl, PHP_URL_SCHEME));
        if (!in_array($photoScheme, ['http', 'https'], true)) {
            json_error('Photo must be a valid HTTP or HTTPS image URL.');
        }
    }

    // Validation
    if ($name === '') {
        json_error('Equipment name is required.');
    }
    if (mb_strlen($name) > 60) {
        json_error('Equipment name must be 60 characters or fewer.');
    }
    if ($quantity < 0 || $available < 0) {
        json_error('Quantity and available units must be non-negative.');
    }
    if ($available > $quantity) {
        json_error('Available quantity cannot exceed total quantity.');
    }

    $validConditions = ['Good', 'Fair', 'Needs repair', 'Damaged'];
    if (!in_array($condition, $validConditions, true)) {
        $condition = 'Good';
    }

    $validStatuses = ['Available', 'Borrowed', 'Reserved', 'Under maintenance', 'Damaged'];
    if (!in_array($status, $validStatuses, true)) {
        $status = $available > 0 ? 'Available' : 'Borrowed';
    }

    if ($id) {
        $exists = $pdo->prepare('SELECT id FROM equipment WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $id]);
        if (!$exists->fetchColumn()) {
            json_error('Equipment not found.', 404);
        }
    }

    $categoryId = resolve_category_id($pdo, $categoryIn, $emoji);
    $orgId      = (int)($currentUser['organization_id'] ?? DEFAULT_ORG_ID);

    try {
        if ($id) {
            // Update existing
            $upd = $pdo->prepare(
                'UPDATE equipment
                    SET name            = :name,
                        category_id     = :category_id,
                        emoji           = :emoji,
                        description     = :description,
                        photo_url       = :photo_url,
                        quantity        = :quantity,
                        available       = :available,
                        condition_state = :condition,
                        status          = :status
                  WHERE id = :id'
            );
            $upd->execute([
                ':name'        => $name,
                ':category_id' => $categoryId,
                ':emoji'       => $emoji,
                ':description' => $description,
                ':photo_url'   => $photoUrl,
                ':quantity'    => $quantity,
                ':available'   => $available,
                ':condition'   => $condition,
                ':status'      => $status,
                ':id'          => $id,
            ]);

            log_activity(
                $pdo,
                $orgId,
                (int)$currentUser['id'],
                '✳',
                "Updated {$name} equipment details",
                null,
                $id
            );

            json_response([
                'success' => true,
                'message' => 'Equipment details saved.',
                'id'      => $id,
            ]);
        } else {
            // Insert new
            $ins = $pdo->prepare(
                'INSERT INTO equipment
                    (organization_id, category_id, name, emoji, description, photo_url,
                     quantity, available, condition_state, status, added_by)
                 VALUES
                    (:org, :cat, :name, :emoji, :desc, :photo, :qty, :avail, :cond, :status, :added_by)'
            );
            $ins->execute([
                ':org'      => $orgId,
                ':cat'      => $categoryId,
                ':name'     => $name,
                ':emoji'    => $emoji,
                ':desc'     => $description,
                ':photo'    => $photoUrl,
                ':qty'      => $quantity,
                ':avail'    => $available,
                ':cond'     => $condition,
                ':status'   => $status,
                ':added_by' => (int)$currentUser['id'],
            ]);

            $newId = (int)$pdo->lastInsertId();

            log_activity(
                $pdo,
                $orgId,
                (int)$currentUser['id'],
                '＋',
                "Added {$name} to the collection",
                null,
                $newId
            );

            json_response([
                'success' => true,
                'message' => 'Added to the community collection.',
                'id'      => $newId,
            ], 201);
        }
    } catch (PDOException $e) {
        error_log('GamitShare equipment save error: ' . $e->getMessage());
        json_error('A database error occurred. Please try again.', 500);
    }
}

json_error('Method not allowed.', 405);
