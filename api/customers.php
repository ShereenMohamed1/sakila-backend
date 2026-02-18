<?php
require __DIR__ . '/../config/database.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(5, (int)($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;

$stmt = $pdo->query("SELECT COUNT(*) FROM customer");
$total = (int) $stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT c.customer_id, c.store_id, c.first_name, c.last_name, c.email, c.active, c.create_date,
           COUNT(r.rental_id) AS rental_count
    FROM customer c
    LEFT JOIN rental r ON c.customer_id = r.customer_id
    GROUP BY c.customer_id
    ORDER BY c.customer_id
    LIMIT $limit OFFSET $offset
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'data' => $rows,
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'pages' => ceil($total / $limit)
]);
