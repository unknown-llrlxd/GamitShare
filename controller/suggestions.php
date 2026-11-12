<?php
/**
 * GamitShare — Item Suggestions & Donations Controller
 * controller/suggestions.php
 *
 * Handles:
 *  - GET  /controller/suggestions.php                 → List suggestions (stewards see all; members see their own)
 *  - POST /controller/suggestions.php?action=create   → Submit a new suggestion / donation (member)
 *  - POST /controller/suggestions.php?action=approve  → Approve suggestion and insert into equipment (steward)
 *  - POST /controller/suggestions.php?action=reject   → Decline suggestion with optional feedback (steward)
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

init_api_headers();

try {
    $pdo = db_connect();
} catch (RuntimeException $e) {
    json_error('Service temporarily unavailable. Please try again.', 503);
}

// ── ENSURE TABLE EXISTS (Auto-migration) ──────────────────────────────────────
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS equipment_suggestions (
            id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            organization_id  INT UNSIGNED NOT NULL DEFAULT 1,
            member_id        INT UNSIGNED NOT NULL,
            item_name        VARCHAR(120) NOT NULL,
            category         VARCHAR(60) NOT NULL DEFAULT 'Other',
            description      TEXT,
            quantity         INT NOT NULL DEFAULT 1,
            condition_state  VARCHAR(40) NOT NULL DEFAULT 'Good',
            image_url        VARCHAR(2048) NULL,
            status           ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
            rejection_reason TEXT NULL,
            reviewed_by      INT UNSIGNED NULL,
            reviewed_at      DATETIME NULL,
            created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_suggestions_org (organization_id),
            KEY idx_suggestions_member (member_id),
            KEY idx_suggestions_status (status),
            CONSTRAINT fk_suggestions_member
              FOREIGN KEY (member_id) REFERENCES users (id)
              ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_suggestions_reviewer
              FOREIGN KEY (reviewed_by) REFERENCES users (id)
              ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (PDOException $e) {
    error_log('Failed to ensure equipment_suggestions table: ' . $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'];
$data   = get_request_data();
$action = $data['action'] ?? $_GET['action'] ?? '';

// ── PHOTO UPLOAD HELPERS ─────────────────────────────────────────────────────
const SUGGESTION_PHOTO_DIR_REL   = 'uploads/equipment';
const SUGGESTION_PHOTO_MAX_BYTES = 5 * 1024 * 1024; // 5 MB
const SUGGESTION_PHOTO_TYPES     = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function save_suggestion_photo(array $file): string
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        json_error('Photo is too large. The maximum size is 5 MB.', 413);
    }
    if ($err !== UPLOAD_ERR_OK) {
        json_error('Photo upload failed. Please try again.', 400);
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        json_error('Invalid file upload.', 400);
    }
    if ((int)$file['size'] > SUGGESTION_PHOTO_MAX_BYTES) {
        json_error('Photo is too large. The maximum size is 5 MB.', 413);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($file['tmp_name']);
    if (!isset(SUGGESTION_PHOTO_TYPES[$mime]) || @getimagesize($file['tmp_name']) === false) {
        json_error('Photo must be a JPG, PNG, or WebP image.', 415);
    }

    $dir = dirname(__DIR__) . '/' . SUGGESTION_PHOTO_DIR_REL;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        json_error('Could not create the uploads folder.', 500);
    }

    $name = sprintf('suggest_%d_%s.%s', time(), bin2hex(random_bytes(8)), SUGGESTION_PHOTO_TYPES[$mime]);
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        json_error('Could not save the uploaded photo.', 500);
    }
    return SUGGESTION_PHOTO_DIR_REL . '/' . $name;
}

function resolve_category_emoji(string $cat): string
{
    $emojis = [
        'Tools'       => '🧰',
        'Events'      => '🎪',
        'Electronics' => '🔌',
        'Garden'      => '🌱',
        'Sports'      => '⚽',
        'Other'       => '📦',
    ];
    return $emojis[$cat] ?? '📦';
}

function resolve_equipment_category_id(PDO $pdo, string $catName): int
{
    $name = trim($catName);
    if ($name === '') {
        $name = 'Other';
    }

    $stmt = $pdo->prepare('SELECT id FROM equipment_categories WHERE name = :name LIMIT 1');
    $stmt->execute([':name' => $name]);
    $catId = $stmt->fetchColumn();

    if ($catId) {
        return (int)$catId;
    }

    $emoji = resolve_category_emoji($name);
    $ins = $pdo->prepare('INSERT INTO equipment_categories (name, emoji) VALUES (:name, :emoji)');
    $ins->execute([':name' => $name, ':emoji' => $emoji]);
    return (int)$pdo->lastInsertId();
}

// ── GET SUGGESTIONS ──────────────────────────────────────────────────────────
if ($method === 'GET') {
    $user = get_current_user_account($pdo);

    $status     = trim((string)($_GET['status'] ?? ''));
    $q          = trim((string)($_GET['q'] ?? ''));
    $memberOnly = !empty($_GET['member_only']) || $action === 'my_suggestions';
    $emailParam = trim((string)($_GET['email'] ?? ''));

    $sql = 'SELECT s.id, s.organization_id, s.member_id, s.item_name, s.category,
                   s.description, s.quantity, s.condition_state, s.image_url,
                   s.status, s.rejection_reason, s.reviewed_by, s.reviewed_at, s.created_at,
                   u.name AS member_name, u.email AS member_email,
                   rev.name AS reviewer_name
              FROM equipment_suggestions s
              JOIN users u ON u.id = s.member_id
              LEFT JOIN users rev ON rev.id = s.reviewed_by
             WHERE 1=1';

    $params = [];

    // Filter by member — use authenticated user ID (never trust client email param) (M-6)
    if ($user && $user['role'] === 'Member') {
        $sql .= ' AND s.member_id = :member_id';
        $params[':member_id'] = (int)$user['id'];
    } elseif ($memberOnly && $user) {
        $sql .= ' AND s.member_id = :member_id';
        $params[':member_id'] = (int)$user['id'];
    }

    if ($status !== '' && in_array($status, ['Pending', 'Approved', 'Rejected'], true)) {
        $sql .= ' AND s.status = :status';
        $params[':status'] = $status;
    }

    if ($q !== '') {
        $sql .= ' AND (s.item_name LIKE :q OR s.category LIKE :q OR s.description LIKE :q OR u.name LIKE :q OR u.email LIKE :q)';
        $params[':q'] = "%{$q}%";
    }

    $sql .= ' ORDER BY s.created_at DESC, s.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $suggestions = array_map(static function (array $row): array {
        return [
            'id'              => (string)$row['id'],
            'memberId'        => (string)$row['member_id'],
            'memberName'      => $row['member_name'],
            'memberEmail'     => $row['member_email'],
            'itemName'        => $row['item_name'],
            'category'        => $row['category'],
            'description'     => $row['description'] ?? '',
            'quantity'        => (int)$row['quantity'],
            'conditionState'  => $row['condition_state'],
            'imageUrl'        => $row['image_url'] ?? '',
            'status'          => $row['status'],
            'rejectionReason' => $row['rejection_reason'] ?? '',
            'reviewedBy'      => $row['reviewed_by'] ? (string)$row['reviewed_by'] : null,
            'reviewerName'    => $row['reviewer_name'] ?? null,
            'reviewedAt'      => $row['reviewed_at'],
            'createdAt'       => $row['created_at'],
        ];
    }, $rows);

    json_response([
        'success'     => true,
        'count'       => count($suggestions),
        'suggestions' => $suggestions,
    ]);
}

// ── CREATE SUGGESTION (Member Action) ─────────────────────────────────────────
if ($action === 'create' || ($method === 'POST' && empty($action) && isset($data['item_name']))) {
    $itemName       = trim((string)($data['item_name'] ?? ''));
    $category       = trim((string)($data['category'] ?? 'Other')) ?: 'Other';
    $description    = trim((string)($data['description'] ?? ''));
    $quantity       = isset($data['quantity']) ? (int)$data['quantity'] : 1;
    $conditionState = trim((string)($data['condition_state'] ?? $data['condition'] ?? 'Good')) ?: 'Good';
    $imageUrl       = trim((string)($data['image_url'] ?? $data['photo_url'] ?? $data['photo'] ?? ''));
    $hasUpload      = isset($_FILES['photo_file']) && ($_FILES['photo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    // Validation
    if ($itemName === '') {
        json_error('Item name is required.');
    }
    if (mb_strlen($itemName) > 120) {
        json_error('Item name must be 120 characters or fewer.');
    }
    if ($quantity < 1) {
        json_error('Quantity must be at least 1.');
    }

    $validConditions = ['Good', 'Fair', 'Needs repair', 'Damaged'];
    if (!in_array($conditionState, $validConditions, true)) {
        $conditionState = 'Good';
    }

    $validCategories = ['Tools', 'Events', 'Electronics', 'Garden', 'Sports', 'Other'];
    if (!in_array($category, $validCategories, true)) {
        $category = 'Other';
    }

    // Process image: upload or URL
    $finalImageUrl = null;
    if ($hasUpload) {
        $finalImageUrl = save_suggestion_photo($_FILES['photo_file']);
    } elseif ($imageUrl !== '') {
        if (preg_match('#^https?://#i', $imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            $finalImageUrl = $imageUrl;
        } elseif (str_starts_with($imageUrl, 'uploads/') && !str_contains($imageUrl, '..')) {
            $finalImageUrl = $imageUrl;
        }
    }

    // Resolve submitting member — strictly enforce authenticated session (C-3)
    $user = require_auth($pdo);
    $memberId   = (int)$user['id'];
    $memberName = $user['name'];
    $orgId      = (int)($user['organization_id'] ?? DEFAULT_ORG_ID);

    try {
        $ins = $pdo->prepare(
            'INSERT INTO equipment_suggestions
                (organization_id, member_id, item_name, category, description,
                 quantity, condition_state, image_url, status, created_at)
             VALUES
                (:org, :member, :name, :category, :desc, :qty, :cond, :img, "Pending", NOW())'
        );
        $ins->execute([
            ':org'      => $orgId,
            ':member'   => $memberId,
            ':name'     => $itemName,
            ':category' => $category,
            ':desc'     => $description,
            ':qty'      => $quantity,
            ':cond'     => $conditionState,
            ':img'      => $finalImageUrl,
        ]);

        $newSuggestionId = (int)$pdo->lastInsertId();

        // Log community activity
        log_activity(
            $pdo,
            $orgId,
            $memberId,
            '🎁',
            "{$memberName} suggested/donated an item: {$itemName}"
        );

        json_response([
            'success'       => true,
            'message'       => 'Thank you! Your donation suggestion has been submitted for review.',
            'suggestion_id' => $newSuggestionId,
        ], 201);
    } catch (PDOException $e) {
        error_log('GamitShare suggestion submit error: ' . $e->getMessage());
        json_error('A database error occurred. Please try again.', 500);
    }
}

// ── STEWARD ACTIONS: APPROVE / REJECT ─────────────────────────────────────────
$currentUser = require_steward($pdo);
$suggestionId = (int)($data['id'] ?? $data['suggestion_id'] ?? $data['suggestionId'] ?? 0);

if ($suggestionId <= 0) {
    json_error('Valid suggestion ID is required.');
}

// Fetch suggestion record with member details
$sugStmt = $pdo->prepare(
    'SELECT s.*, u.name AS member_name, u.email AS member_email
       FROM equipment_suggestions s
       JOIN users u ON u.id = s.member_id
      WHERE s.id = :id
      LIMIT 1'
);
$sugStmt->execute([':id' => $suggestionId]);
$suggestion = $sugStmt->fetch();

if (!$suggestion) {
    json_error('Suggestion record not found.', 404);
}

// ── APPROVE SUGGESTION ────────────────────────────────────────────────────────
if ($action === 'approve') {
    if ($suggestion['status'] !== 'Pending') {
        json_error('Only pending suggestions can be approved.');
    }

    try {
        $pdo->beginTransaction();

        // 1. Mark suggestion as Approved
        $upd = $pdo->prepare(
            "UPDATE equipment_suggestions
                SET status      = 'Approved',
                    reviewed_by = :reviewer,
                    reviewed_at = NOW()
              WHERE id = :id"
        );
        $upd->execute([
            ':reviewer' => $currentUser['id'],
            ':id'       => $suggestionId,
        ]);

        // 2. Resolve equipment category ID & emoji
        $categoryId = resolve_equipment_category_id($pdo, (string)$suggestion['category']);
        $emoji      = resolve_category_emoji((string)$suggestion['category']);
        $orgId      = (int)$suggestion['organization_id'];

        // 3. Insert into main equipment table
        $insEq = $pdo->prepare(
            'INSERT INTO equipment
                (organization_id, category_id, name, emoji, description, photo_url,
                 quantity, available, condition_state, status, added_by, created_at)
             VALUES
                (:org, :cat, :name, :emoji, :desc, :photo, :qty, :avail, :cond, "Available", :added_by, NOW())'
        );
        $insEq->execute([
            ':org'      => $orgId,
            ':cat'      => $categoryId,
            ':name'     => $suggestion['item_name'],
            ':emoji'    => $emoji,
            ':desc'     => $suggestion['description'],
            ':photo'    => $suggestion['image_url'] ?? '',
            ':qty'      => (int)$suggestion['quantity'],
            ':avail'    => (int)$suggestion['quantity'],
            ':cond'     => $suggestion['condition_state'],
            ':added_by' => (int)$suggestion['member_id'],
        ]);

        $newEquipmentId = (int)$pdo->lastInsertId();

        // 4. Log activity
        log_activity(
            $pdo,
            $orgId,
            (int)$currentUser['id'],
            '🎁',
            "{$currentUser['name']} approved donated item: {$suggestion['item_name']} (from {$suggestion['member_name']})",
            null,
            $newEquipmentId
        );

        $pdo->commit();

        json_response([
            'success'      => true,
            'message'      => "Item '{$suggestion['item_name']}' approved and added to equipment inventory!",
            'equipment_id' => $newEquipmentId,
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('GamitShare suggestion approve error: ' . $e->getMessage());
        json_error('A database error occurred. Please try again.', 500);
    }
}

// ── REJECT SUGGESTION ─────────────────────────────────────────────────────────
if ($action === 'reject') {
    if ($suggestion['status'] !== 'Pending') {
        json_error('Only pending suggestions can be rejected.');
    }

    $reason = trim((string)($data['reason'] ?? $data['feedback'] ?? ''));

    try {
        $upd = $pdo->prepare(
            "UPDATE equipment_suggestions
                SET status           = 'Rejected',
                    rejection_reason = :reason,
                    reviewed_by      = :reviewer,
                    reviewed_at      = NOW()
              WHERE id = :id"
        );
        $upd->execute([
            ':reason'   => $reason !== '' ? $reason : null,
            ':reviewer' => $currentUser['id'],
            ':id'       => $suggestionId,
        ]);

        $orgId = (int)$suggestion['organization_id'];

        log_activity(
            $pdo,
            $orgId,
            (int)$currentUser['id'],
            '×',
            "{$currentUser['name']} declined suggested item: {$suggestion['item_name']}"
        );

        json_response([
            'success' => true,
            'message' => "Suggestion for '{$suggestion['item_name']}' has been rejected.",
        ]);
    } catch (PDOException $e) {
        error_log('GamitShare suggestion reject error: ' . $e->getMessage());
        json_error('A database error occurred. Please try again.', 500);
    }
}

json_error('Invalid action requested.');

