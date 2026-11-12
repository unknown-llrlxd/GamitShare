<?php
/**
 * GamitShare — Overview Metrics & Reports Controller
 * controller/reports.php
 *
 * Handles:
 *  - GET /controller/reports.php?action=overview    → Overview cards & health stats
 *  - GET /controller/reports.php?action=reports     → Reports section (usage, status, overdue)
 *  - GET /controller/reports.php?action=export_csv  → Download CSV export of requests/reports
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

init_api_headers();

try {
    $pdo = db_connect();
} catch (RuntimeException $e) {
    json_error('Service temporarily unavailable. Please try again.', 503);
}

// All reports and exports require steward-level access (H-1)
$currentUser = require_steward($pdo);

$action = $_GET['action'] ?? 'overview';

// ── EXPORT CSV ───────────────────────────────────────────────────────────────
if ($action === 'export_csv') {
    $type   = $_GET['type'] ?? 'requests';
    $period = $_GET['period'] ?? 'all';

    $sql = 'SELECT e.name AS equipment_name, u.name AS borrower_name, u.email AS borrower_email,
                   br.quantity, br.start_date, br.due_date, br.status, br.purpose,
                   IFNULL(br.return_condition, "") AS return_condition, br.created_at
              FROM borrow_requests br
              JOIN equipment e ON e.id = br.equipment_id
              JOIN users u ON u.id = br.user_id
             WHERE 1=1';

    $params = [];

    if ($type === 'report' && is_numeric($period) && (int)$period > 0) {
        $sql .= ' AND br.created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)';
        $params[':days'] = (int)$period;
    }

    $sql .= ' ORDER BY br.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $todayStr = date('Y-m-d');
    $filename = $type === 'report'
        ? "gamitshare-report-{$todayStr}.csv"
        : "gamitshare-borrowing-{$todayStr}.csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'wb');
    if ($output !== false) {
        // BOM for Excel utf-8 recognition
        fputs($output, "\xEF\xBB\xBF");

        fputcsv($output, [
            'Equipment',
            'Borrower',
            'Email',
            'Quantity',
            'Start date',
            'Return date',
            'Status',
            'Purpose',
            'Return condition',
        ]);

        foreach ($rows as $row) {
            fputcsv($output, [
                $row['equipment_name'],
                $row['borrower_name'],
                $row['borrower_email'],
                $row['quantity'],
                $row['start_date'],
                $row['due_date'],
                $row['status'],
                $row['purpose'],
                $row['return_condition'],
            ]);
        }
        fclose($output);
    }
    exit;
}

// ── OVERVIEW METRICS ─────────────────────────────────────────────────────────
if ($action === 'overview') {
    // Inventory numbers
    $eqStats = $pdo->query(
        'SELECT COUNT(*) AS total_types,
                IFNULL(SUM(quantity), 0) AS total_units,
                IFNULL(SUM(available), 0) AS available_units
           FROM equipment'
    )->fetch();

    // Requests numbers
    $reqStats = $pdo->query(
        "SELECT SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status IN ('Approved', 'Overdue') THEN 1 ELSE 0 END) AS active_count
           FROM borrow_requests"
    )->fetch();

    // Damaged count
    $damagedCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM equipment
          WHERE status IN ('Damaged', 'Under maintenance')
             OR condition_state IN ('Damaged', 'Needs repair')"
    )->fetchColumn();

    // Status breakdown
    $statuses = ['Available', 'Borrowed', 'Under maintenance', 'Damaged', 'Reserved'];
    $health = [];
    foreach ($statuses as $st) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM equipment WHERE status = :st');
        $stmt->execute([':st' => $st]);
        $health[$st] = (int)$stmt->fetchColumn();
    }

    // Top pending requests (for quick review)
    $pendingStmt = $pdo->query(
        "SELECT br.id, br.equipment_id, br.quantity, br.start_date, br.due_date, br.purpose,
                u.name AS borrower, u.email, e.name AS equipment_name, e.emoji
           FROM borrow_requests br
           JOIN users u ON u.id = br.user_id
           JOIN equipment e ON e.id = br.equipment_id
          WHERE br.status = 'Pending'
          ORDER BY br.created_at ASC
          LIMIT 4"
    );
    $pendingList = $pendingStmt->fetchAll();

    // Recent activity
    $actStmt = $pdo->query(
        'SELECT id, icon, message AS text, DATE(created_at) AS date
           FROM activity_log
          ORDER BY created_at DESC
          LIMIT 8'
    );
    $recentActivity = $actStmt->fetchAll();

    json_response([
        'success' => true,
        'metrics' => [
            'total_units'     => (int)$eqStats['total_units'],
            'total_types'     => (int)$eqStats['total_types'],
            'available_units' => (int)$eqStats['available_units'],
            'active_count'    => (int)$reqStats['active_count'],
            'pending_count'   => (int)$reqStats['pending_count'],
            'damaged_count'   => $damagedCount,
        ],
        'health'         => $health,
        'pending'        => $pendingList,
        'recentActivity' => $recentActivity,
    ]);
}

// ── REPORTS SECTION ──────────────────────────────────────────────────────────
if ($action === 'reports') {
    $period = $_GET['period'] ?? 'all';

    $dateWhere = '';
    $params    = [];
    if (is_numeric($period) && (int)$period > 0) {
        $dateWhere = ' AND created_at >= DATE_SUB(CURDATE(), INTERVAL :period DAY)';
        $params[':period'] = (int)$period;
    }

    // Total equipment units
    $totalUnits = (int)$pdo->query('SELECT IFNULL(SUM(quantity), 0) FROM equipment')->fetchColumn();

    // Borrowings in period
    $borrowStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM borrow_requests
          WHERE status IN ('Approved', 'Returned', 'Overdue') {$dateWhere}"
    );
    $borrowStmt->execute($params);
    $borrowingsCount = (int)$borrowStmt->fetchColumn();

    // Returned in period
    $returnStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM borrow_requests
          WHERE status = 'Returned' {$dateWhere}"
    );
    $returnStmt->execute($params);
    $returnedCount = (int)$returnStmt->fetchColumn();

    // Overdue count (current)
    $overdueCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM borrow_requests
          WHERE status = 'Overdue'
             OR (status = 'Approved' AND due_date < CURDATE())"
    )->fetchColumn();

    // Equipment usage ranking (top 5)
    $usageSql = "SELECT e.id, e.name, e.emoji, COUNT(br.id) AS `count`
                   FROM equipment e
                   LEFT JOIN borrow_requests br
                          ON br.equipment_id = e.id {$dateWhere}
                  GROUP BY e.id, e.name, e.emoji
                  ORDER BY `count` DESC, e.name ASC
                  LIMIT 5";
    $usageStmt = $pdo->prepare($usageSql);
    $usageStmt->execute($params);
    $usage = $usageStmt->fetchAll();

    // Status breakdown
    $statuses = ['Available', 'Borrowed', 'Under maintenance', 'Damaged', 'Reserved'];
    $statusBreakdown = [];
    foreach ($statuses as $st) {
        $sStmt = $pdo->prepare('SELECT COUNT(*) FROM equipment WHERE status = :st');
        $sStmt->execute([':st' => $st]);
        $statusBreakdown[$st] = (int)$sStmt->fetchColumn();
    }

    // Overdue list
    $overdueStmt = $pdo->query(
        "SELECT br.id, br.equipment_id, e.name AS equipment_name, e.emoji,
                u.name AS borrower, u.email, br.due_date,
                GREATEST(1, DATEDIFF(CURDATE(), br.due_date)) AS days_late
           FROM borrow_requests br
           JOIN equipment e ON e.id = br.equipment_id
           JOIN users u ON u.id = br.user_id
          WHERE br.status = 'Overdue'
             OR (br.status = 'Approved' AND br.due_date < CURDATE())
          ORDER BY days_late DESC"
    );
    $overdueItems = $overdueStmt->fetchAll();

    json_response([
        'success' => true,
        'metrics' => [
            'total_units'      => $totalUnits,
            'borrowings_count' => $borrowingsCount,
            'returned_count'   => $returnedCount,
            'overdue_count'    => $overdueCount,
        ],
        'usage'           => $usage,
        'statusBreakdown' => $statusBreakdown,
        'overdue'         => $overdueItems,
    ]);
}

json_error('Invalid action specified.', 400);

