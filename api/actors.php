<?php
require __DIR__ . '/../config/database.php';

$path = $_GET['path'] ?? '';
$parts = explode('/', trim($path, '/'));
$subPath = $parts[2] ?? '';

if ($subPath === 'top') {
    $stmt = $pdo->query("
        SELECT a.actor_id, a.first_name, a.last_name, COUNT(DISTINCT r.rental_id) as rental_count
        FROM actor a
        JOIN film_actor fa ON a.actor_id = fa.actor_id
        JOIN inventory i ON fa.film_id = i.film_id
        JOIN rental r ON i.inventory_id = r.inventory_id
        GROUP BY a.actor_id
        ORDER BY rental_count DESC
        LIMIT 5
    ");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if (is_numeric($subPath)) {
    $id = (int) $subPath;
    $stmt = $pdo->prepare("SELECT * FROM actor WHERE actor_id = ?");
    $stmt->execute([$id]);
    $actor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$actor) {
        http_response_code(404);
        echo json_encode(['error' => 'not found']);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT f.film_id, f.title, COUNT(r.rental_id) as rental_count
        FROM film_actor fa
        JOIN film f ON fa.film_id = f.film_id
        JOIN inventory i ON f.film_id = i.film_id
        JOIN rental r ON i.inventory_id = r.inventory_id
        WHERE fa.actor_id = ?
        GROUP BY f.film_id
        ORDER BY rental_count DESC
        LIMIT 5
    ");
    $stmt->execute([$id]);
    $actor['top_films'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($actor);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'not found']);
