<?php
require __DIR__ . '/../config/database.php';

$path = $_GET['path'] ?? '';
$parts = explode('/', trim($path, '/'));
$subPath = $parts[2] ?? '';

if ($subPath === 'top-rented') {
    $stmt = $pdo->query("
        SELECT f.film_id, f.title, c.name AS category, COUNT(r.rental_id) AS rental_count
        FROM film f
        JOIN inventory i ON f.film_id = i.film_id
        JOIN rental r ON i.inventory_id = r.inventory_id
        JOIN film_category fc ON f.film_id = fc.film_id
        JOIN category c ON fc.category_id = c.category_id
        GROUP BY f.film_id, f.title, c.name
        ORDER BY rental_count DESC
        LIMIT 5
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        if (!isset($seen[$r['film_id']])) {
            $seen[$r['film_id']] = true;
            $out[] = $r;
            if (count($out) >= 5) break;
        }
    }
    echo json_encode($out);
    exit;
}

if (is_numeric($subPath)) {
    $id = (int) $subPath;
    $stmt = $pdo->prepare("SELECT * FROM film WHERE film_id = ?");
    $stmt->execute([$id]);
    $film = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$film) {
        http_response_code(404);
        echo json_encode(['error' => 'not found']);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT a.actor_id, a.first_name, a.last_name
        FROM film_actor fa
        JOIN actor a ON fa.actor_id = a.actor_id
        WHERE fa.film_id = ?
    ");
    $stmt->execute([$id]);
    $film['actors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("
        SELECT c.name
        FROM film_category fc
        JOIN category c ON fc.category_id = c.category_id
        WHERE fc.film_id = ?
    ");
    $stmt->execute([$id]);
    $film['categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($film);
    exit;
}

$q = $_GET['q'] ?? '';
$by = $_GET['by'] ?? 'title';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(5, (int)($_GET['limit'] ?? 12)));
$offset = ($page - 1) * $limit;

if (empty($q)) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM film");
    $total = (int) $stmt->fetchColumn();
    $stmt = $pdo->query("
        SELECT film_id, title, description, release_year, rental_rate, rating
        FROM film
        ORDER BY title
        LIMIT $limit OFFSET $offset
    ");
    echo json_encode([
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ]);
    exit;
}

if ($by === 'title') {
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.film_id, f.title, f.description, f.release_year, f.rental_rate, f.rating
        FROM film f
        WHERE f.title LIKE ?
        LIMIT 50
    ");
    $stmt->execute(['%' . $q . '%']);
} elseif ($by === 'actor') {
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.film_id, f.title, f.description, f.release_year, f.rental_rate, f.rating
        FROM film f
        JOIN film_actor fa ON f.film_id = fa.film_id
        JOIN actor a ON fa.actor_id = a.actor_id
        WHERE a.first_name LIKE ? OR a.last_name LIKE ? OR CONCAT(a.first_name, ' ', a.last_name) LIKE ?
        LIMIT 50
    ");
    $term = '%' . $q . '%';
    $stmt->execute([$term, $term, $term]);
} elseif ($by === 'genre') {
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.film_id, f.title, f.description, f.release_year, f.rental_rate, f.rating
        FROM film f
        JOIN film_category fc ON f.film_id = fc.film_id
        JOIN category c ON fc.category_id = c.category_id
        WHERE c.name LIKE ?
        LIMIT 50
    ");
    $stmt->execute(['%' . $q . '%']);
} else {
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.film_id, f.title, f.description, f.release_year, f.rental_rate, f.rating
        FROM film f
        LEFT JOIN film_actor fa ON f.film_id = fa.film_id
        LEFT JOIN actor a ON fa.actor_id = a.actor_id
        LEFT JOIN film_category fc ON f.film_id = fc.film_id
        LEFT JOIN category c ON fc.category_id = c.category_id
        WHERE f.title LIKE ? OR a.first_name LIKE ? OR a.last_name LIKE ? OR c.name LIKE ?
        LIMIT 50
    ");
    $term = '%' . $q . '%';
    $stmt->execute([$term, $term, $term, $term]);
}

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
