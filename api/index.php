<?php
// Route Vercel /api requests back to your existing PHP controllers
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$target = __DIR__ . '/..' . $uri;

// Prevent directory traversal and only execute real .php files inside controller/ or test_db.php
$realBase = realpath(__DIR__ . '/..');
$realTarget = realpath($target);

if (
    $realTarget !== false &&
    strpos($realTarget, $realBase) === 0 &&
    pathinfo($realTarget, PATHINFO_EXTENSION) === 'php' &&
    basename($realTarget) !== 'database.php'
) {
    chdir(dirname($realTarget));
    require $realTarget;
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Endpoint not found']);