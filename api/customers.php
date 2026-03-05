<?php
require __DIR__ . '/../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = explode('/', trim($uri, '/'));
$customerId = isset($parts[2]) && is_numeric($parts[2]) ? (int)$parts[2] : null;

if ($method === 'GET' && $customerId) {
    $stmt = $pdo->prepare("
        SELECT c.customer_id, c.store_id, c.first_name, c.last_name, c.email, c.active, c.create_date,
               a.address, a.district, a.postal_code, a.phone,
               ci.city, co.country
        FROM customer c
        LEFT JOIN address a ON c.address_id = a.address_id
        LEFT JOIN city ci ON a.city_id = ci.city_id
        LEFT JOIN country co ON ci.country_id = co.country_id
        WHERE c.customer_id = ?
    ");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        http_response_code(404);
        echo json_encode(['error' => 'Customer not found']);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT r.rental_id, r.rental_date, r.return_date,
               f.film_id, f.title, f.rental_rate
        FROM rental r
        JOIN inventory i ON r.inventory_id = i.inventory_id
        JOIN film f ON i.film_id = f.film_id
        WHERE r.customer_id = ?
        ORDER BY r.rental_date DESC
        LIMIT 50
    ");
    $stmt->execute([$customerId]);
    $rentals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $customer['rentals'] = $rentals;
    echo json_encode($customer);
    exit;
}

if ($method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(5, (int)($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    
    $search = $_GET['q'] ?? '';
    $searchBy = $_GET['by'] ?? 'first_name';
    
    $whereClause = '';
    $params = [];
    
    if ($search !== '') {
        if ($searchBy === 'id') {
            $whereClause = 'WHERE c.customer_id = ?';
            $params[] = (int)$search;
        } elseif ($searchBy === 'last_name') {
            $whereClause = 'WHERE c.last_name LIKE ?';
            $params[] = '%' . $search . '%';
        } else {
            $whereClause = 'WHERE c.first_name LIKE ?';
            $params[] = '%' . $search . '%';
        }
    }
    
    $countSql = "SELECT COUNT(*) FROM customer c $whereClause";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    
    $sql = "
        SELECT c.customer_id, c.store_id, c.first_name, c.last_name, c.email, c.active, c.create_date,
               COUNT(r.rental_id) AS rental_count
        FROM customer c
        LEFT JOIN rental r ON c.customer_id = r.customer_id
        $whereClause
        GROUP BY c.customer_id
        ORDER BY c.customer_id
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'data' => $rows,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    $firstName = trim($input['first_name'] ?? '');
    $lastName = trim($input['last_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $address = substr(trim($input['address'] ?? '') ?: 'Not provided', 0, 50);
    $phone = substr(trim($input['phone'] ?? '') ?: 'N/A', 0, 20);
    
    if (!$firstName || !$lastName) {
        http_response_code(400);
        echo json_encode(['error' => 'First name and last name are required']);
        exit;
    }
    
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query("SELECT city_id FROM city LIMIT 1");
        $cityRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $cityId = $cityRow ? (int)$cityRow['city_id'] : 1;
        
        $stmt = $pdo->prepare("
            INSERT INTO address (address, district, city_id, postal_code, phone, location)
            VALUES (?, 'N/A', ?, '', ?, ST_GeomFromText('POINT(0 0)'))
        ");
        $stmt->execute([$address, $cityId, $phone]);
        $addressId = $pdo->lastInsertId();
        
        $stmt = $pdo->query("SELECT store_id FROM store LIMIT 1");
        $storeRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $storeId = $storeRow ? (int)$storeRow['store_id'] : 1;
        
        $stmt = $pdo->prepare("
            INSERT INTO customer (store_id, first_name, last_name, email, address_id, active, create_date)
            VALUES (?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$storeId, $firstName, $lastName, $email ?: null, $addressId]);
        $customerId = $pdo->lastInsertId();
        
        $pdo->commit();
        echo json_encode(['ok' => true, 'customer_id' => (int)$customerId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create customer']);
    }
    exit;
}

if ($method === 'PUT' && $customerId) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    $firstName = trim($input['first_name'] ?? '');
    $lastName = trim($input['last_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $address = trim($input['address'] ?? '');
    
    if (!$firstName || !$lastName) {
        http_response_code(400);
        echo json_encode(['error' => 'First name and last name are required']);
        exit;
    }
    
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT address_id FROM customer WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Customer not found']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            UPDATE address SET address = ?, phone = ? WHERE address_id = ?
        ");
        $stmt->execute([$address, $phone, $row['address_id']]);
        
        $stmt = $pdo->prepare("
            UPDATE customer SET first_name = ?, last_name = ?, email = ? WHERE customer_id = ?
        ");
        $stmt->execute([$firstName, $lastName, $email, $customerId]);
        
        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update customer']);
    }
    exit;
}

if ($method === 'DELETE' && $customerId) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT address_id FROM customer WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Customer not found']);
            exit;
        }
        
        $stmt = $pdo->prepare("DELETE FROM payment WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        
        $stmt = $pdo->prepare("DELETE FROM rental WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        
        $stmt = $pdo->prepare("DELETE FROM customer WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        
        $stmt = $pdo->prepare("DELETE FROM address WHERE address_id = ?");
        $stmt->execute([$row['address_id']]);
        
        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete customer']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
