<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/' || $uri === '' || !file_exists(__DIR__ . $uri)) {
    $_GET['path'] = trim($uri, '/');
    include __DIR__ . '/index.php';
} else {
    return false;
}
