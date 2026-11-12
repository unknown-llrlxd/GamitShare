<?php
/**
 * GamitShare — Database & Backend Diagnostics Tool
 * test_db.php
 *
 * Open in your browser at:
 * http://localhost/Final%20Project/test_db.php
 *
 * Verifies database connection, table structures, seed data,
 * and test accounts for seamless demonstration and testing.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

$checks = [];

// 1. PHP Version & PDO MySQL
$checks['php_version'] = [
    'title'  => 'PHP Version',
    'status' => PHP_VERSION_ID >= 80000 ? 'OK' : 'WARN',
    'detail' => 'PHP ' . PHP_VERSION . ' (PHP 8.0+ recommended)',
];

$checks['pdo_mysql'] = [
    'title'  => 'PDO MySQL Driver',
    'status' => extension_loaded('pdo_mysql') ? 'OK' : 'FAIL',
    'detail' => extension_loaded('pdo_mysql') ? 'Loaded and available' : 'Missing pdo_mysql extension in php.ini',
];

// 2. Database Connection
$pdo = null;
try {
    $pdo = db_connect();
    $checks['db_connection'] = [
        'title'  => 'Database Connection',
        'status' => 'OK',
        'detail' => 'Connected to `' . DB_NAME . '` on `' . DB_HOST . ':' . DB_PORT . '` as user `' . DB_USER . '`',
    ];
} catch (Throwable $e) {
    $checks['db_connection'] = [
        'title'  => 'Database Connection',
        'status' => 'FAIL',
        'detail' => $e->getMessage(),
    ];
}

// 3. Table Checks & Counts
$tables = [
    'organizations'        => 0,
    'users'                => 0,
    'equipment_categories' => 0,
    'equipment'            => 0,
    'borrow_requests'      => 0,
    'activity_log'         => 0,
    'user_sessions'        => 0,
    'equipment_maintenance'=> 0,
    'equipment_suggestions'=> 0,
    'login_attempts'       => 0,
];

if ($pdo) {
    foreach ($tables as $tbl => &$count) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$tbl}`");
            $count = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $count = -1; // table doesn't exist
        }
    }
    unset($count);

    $missing = array_filter($tables, fn($c) => $c === -1);
    $checks['tables'] = [
        'title'  => 'Required Database Tables',
        'status' => empty($missing) ? 'OK' : 'FAIL',
        'detail' => empty($missing)
            ? 'All ' . count($tables) . ' core tables exist in database'
            : 'Missing tables: ' . implode(', ', array_keys($missing)),
    ];

    // 4. Test Accounts Check
    try {
        $uStmt = $pdo->query("SELECT id, name, email, role, is_active FROM users ORDER BY id ASC");
        $usersList = $uStmt->fetchAll();
        $adminFound = array_filter($usersList, fn($u) => $u['role'] === 'Administrator');

        $checks['admin_account'] = [
            'title'  => 'Administrator Account',
            'status' => !empty($adminFound) ? 'OK' : 'FAIL',
            'detail' => !empty($adminFound)
                ? 'Found administrator: ' . reset($adminFound)['name'] . ' (' . reset($adminFound)['email'] . ')'
                : 'No active Administrator account found in `users` table',
        ];
    } catch (PDOException $e) {
        $usersList = [];
        $checks['admin_account'] = [
            'title'  => 'Administrator Account',
            'status' => 'FAIL',
            'detail' => $e->getMessage(),
        ];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>GamitShare · System & Database Diagnostics</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #f6f7f2; color: #22252a; font-family: system-ui, -apple-system, sans-serif; padding: 2.5rem 1rem; }
    .card-box { background: #fff; border-radius: 14px; border: 1px solid rgba(0,0,0,0.08); box-shadow: 0 4px 18px rgba(0,0,0,0.03); }
    .status-badge { font-size: 0.8rem; padding: 0.35rem 0.65rem; border-radius: 20px; font-weight: 600; text-transform: uppercase; }
    .badge-ok { background: #e3f5e5; color: #1e7e34; }
    .badge-warn { background: #fff3cd; color: #856404; }
    .badge-fail { background: #f8d7da; color: #721c24; }
    .brand-mark { display: inline-flex; width: 34px; height: 34px; align-items: center; justify-content: center; background: #607955; color: #fff; border-radius: 50%; font-weight: bold; margin-right: 0.5rem; }
  </style>
</head>
<body>
  <div class="container" style="max-width: 900px;">
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div class="d-flex align-items-center">
        <span class="brand-mark">g</span>
        <h2 class="mb-0 fw-bold">GamitShare <span class="fs-6 text-secondary fw-normal">· Diagnostics</span></h2>
      </div>
      <div>
        <a href="views/admin/dashboard.html" class="btn btn-sm btn-success me-1">Admin Dashboard</a>
        <a href="views/auth/login.html" class="btn btn-sm btn-outline-secondary">Sign In</a>
      </div>
    </div>

    <!-- System Checks -->
    <div class="card-box p-4 mb-4">
      <h5 class="fw-bold mb-3">System & Database Status</h5>
      <ul class="list-group list-group-flush">
        <?php foreach ($checks as $c): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-3">
            <div>
              <strong><?= htmlspecialchars($c['title']) ?></strong>
              <div class="small text-secondary"><?= htmlspecialchars($c['detail']) ?></div>
            </div>
            <span class="status-badge badge-<?= strtolower($c['status']) ?>"><?= $c['status'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <!-- Database Table Counts -->
    <?php if ($pdo): ?>
    <div class="card-box p-4 mb-4">
      <h5 class="fw-bold mb-3">Database Tables & Record Counts</h5>
      <div class="row g-3">
        <?php foreach ($tables as $tName => $tCount): ?>
          <div class="col-sm-6 col-md-3">
            <div class="p-3 bg-light rounded text-center border">
              <div class="fs-4 fw-bold text-success"><?= $tCount >= 0 ? $tCount : '!' ?></div>
              <div class="small text-secondary text-truncate" title="<?= htmlspecialchars($tName) ?>"><?= htmlspecialchars($tName) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Active Test Accounts -->
    <div class="card-box p-4 mb-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">Configured Test Accounts</h5>
        <span class="badge bg-secondary"><?= count($usersList ?? []) ?> accounts</span>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr class="small text-secondary">
              <th>ID</th>
              <th>NAME</th>
              <th>EMAIL</th>
              <th>ROLE</th>
              <th>DEFAULT PASSWORD</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $pwMap = [
                'maya.santos@example.org' => 'admin12345',
                'noah.cruz@example.org'   => 'officer12345',
                'rafi.dc@example.org'     => 'custodian12345',
                'lina.reyes@example.org'  => 'member12345',
                'ivo.m@example.org'       => 'member12345',
            ];
            foreach ($usersList ?? [] as $u):
                $defaultPw = $pwMap[strtolower($u['email'])] ?? 'member12345';
            ?>
              <tr>
                <td><?= $u['id'] ?></td>
                <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
                <td><code><?= htmlspecialchars($u['email']) ?></code></td>
                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($u['role']) ?></span></td>
                <td><code><?= $defaultPw ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Controller API Endpoints -->
    <div class="card-box p-4 mb-4">
      <h5 class="fw-bold mb-3">Modular Feature Endpoints</h5>
      <div class="list-group list-group-flush">
        <a href="controller/equipment.php" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
          <div>
            <strong>controller/equipment.php</strong>
            <small class="d-block text-secondary">GET equipment inventory, search & categories</small>
          </div>
          <span class="badge bg-light text-dark border">JSON API ↗</span>
        </a>
        <a href="controller/requests.php" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
          <div>
            <strong>controller/requests.php</strong>
            <small class="d-block text-secondary">GET borrowing requests list & approval flow</small>
          </div>
          <span class="badge bg-light text-dark border">JSON API ↗</span>
        </a>
        <a href="controller/suggestions.php" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
          <div>
            <strong>controller/suggestions.php</strong>
            <small class="d-block text-secondary">GET / POST item suggestions & donations review workflow</small>
          </div>
          <span class="badge bg-light text-dark border">JSON API ↗</span>
        </a>
        <a href="controller/reports.php?action=overview" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
          <div>
            <strong>controller/reports.php?action=overview</strong>
            <small class="d-block text-secondary">GET overview metrics, health pulse & counts</small>
          </div>
          <span class="badge bg-light text-dark border">JSON API ↗</span>
        </a>
        <a href="controller/reports.php?action=export_csv&type=requests" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
          <div>
            <strong>controller/reports.php?action=export_csv</strong>
            <small class="d-block text-secondary">Download native CSV file stream of borrowing requests</small>
          </div>
          <span class="badge bg-light text-dark border">CSV Download ↗</span>
        </a>
        <a href="controller/activity.php" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
          <div>
            <strong>controller/activity.php</strong>
            <small class="d-block text-secondary">GET community activity feed</small>
          </div>
          <span class="badge bg-light text-dark border">JSON API ↗</span>
        </a>
      </div>
    </div>
    <?php endif; ?>

    <p class="text-center text-secondary small">GamitShare Community Equipment Borrowing System · Ready for paired testing</p>
  </div>
</body>
</html>

