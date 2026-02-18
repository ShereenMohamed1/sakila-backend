<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$path = isset($_GET['path']) ? $_GET['path'] : '';
$path = trim($path, '/');
$parts = $path ? explode('/', $path) : [];

if (empty($parts) || $parts[0] !== 'api') {
    echo json_encode(['ok' => 1]);
    exit;
}

$resource = $parts[1] ?? '';

switch ($resource) {
    case 'films':
        require __DIR__ . '/api/films.php';
        break;
    case 'actors':
        require __DIR__ . '/api/actors.php';
        break;
    case 'customers':
        require __DIR__ . '/api/customers.php';
        break;
    case 'rentals':
        require __DIR__ . '/api/rentals.php';
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'not found']);
}
