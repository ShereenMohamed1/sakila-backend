<?php
require __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'post only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$filmId = (int)($input['film_id'] ?? 0);
$customerId = (int)($input['customer_id'] ?? 0);

if (!$filmId || !$customerId) {
    http_response_code(400);
    echo json_encode(['error' => 'need film_id and customer_id']);
    exit;
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("
        SELECT i.inventory_id
        FROM inventory i
        LEFT JOIN rental r ON i.inventory_id = r.inventory_id AND r.return_date IS NULL
        WHERE i.film_id = ? AND r.rental_id IS NULL
        LIMIT 1
    ");
    $stmt->execute([$filmId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'no copies']);
        exit;
    }

    $staffId = 1;
    $stmt = $pdo->prepare("
        INSERT INTO rental (rental_date, inventory_id, customer_id, staff_id)
        VALUES (NOW(), ?, ?, ?)
    ");
    $stmt->execute([$inv['inventory_id'], $customerId, $staffId]);
    $rentalId = $pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT rental_rate FROM film WHERE film_id = ?");
    $stmt->execute([$filmId]);
    $film = $stmt->fetch(PDO::FETCH_ASSOC);
    $amount = $film['rental_rate'] ?? 4.99;

    $stmt = $pdo->prepare("
        INSERT INTO payment (customer_id, staff_id, rental_id, amount, payment_date)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$customerId, $staffId, $rentalId, $amount]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'rental_id' => (int)$rentalId]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'failed']);
}
