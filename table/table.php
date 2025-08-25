<?php
include '../db.php'; // Include your database connection
include '../validate.php'; // Include the file containing getJWTFromHeader and validateJWT functions

header('Content-Type: application/json');
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Get JWT token from Authorization header
$jwt = getJWTFromHeader();
if ($jwt === null) {
    http_response_code(401); // Unauthorized
    echo json_encode(["error" => "Token is missing or invalid"]);
    return;
}

// Validate the JWT token
$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(["error" => "Invalid token"]);
    return;
}

// Extract user ID and user type from the JWT payload
$userID = $payload['owner_id'];
$userType = $payload['user_type'];

switch ($requestMethod) {
    case 'POST': // Create a new menu item
        createTable($conn, $userID, $userType);
        break;
    case 'GET': // Read menu items
        getTables($conn, $userID, $userType);
        break;
    case 'PUT': // Update an existing menu item
        updateTable($conn, $userID, $userType);
        break;
    case 'DELETE': // Delete a menu item
        deleteTable($conn, $userID, $userType);
        break;
    default:
        http_response_code(405); // Method not allowed
        echo json_encode(["error" => "Method not allowed"]);
        break;
}


function getTables($conn, $userID, $userType) {
    // Accept hotel_id from GET query or POST JSON body
    $hotelId = null;
    if (isset($_GET['hotel_id'])) {
        $hotelId = $_GET['hotel_id'];
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['hotel_id'])) $hotelId = $data['hotel_id'];
    }

    if ($hotelId === null) {
        http_response_code(400);
        echo json_encode(["error" => "Hotel ID is required"]);
        return;
    }

    // verify hotel belongs to owner
    $sql = "SELECT hotel_id FROM hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->bindParam(':owner_id', $userID);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Hotel not found or you don't have access"]);
        return;
    }

    // fetch categories for hotel
    $sql = "SELECT category_id, category_name, category_table_count, created_at, updated_at 
            FROM categories WHERE hotel_id = :hotel_id ORDER BY category_id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultCategories = [];
    foreach ($categories as $c) {
        // fetch tables for this category
        $sql2 = "SELECT * FROM tables WHERE category_id = :category_id ORDER BY table_number+0 ASC, table_id ASC";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->bindParam(':category_id', $c['category_id']);
        $stmt2->execute();
        $tables = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        // decode JSON fields so client receives objects (not JSON strings)
        foreach ($tables as &$t) {
            // Normalize numeric types if needed
            $t['table_id'] = (int)$t['table_id'];
            $t['category_id'] = (int)$t['category_id'];
            $t['total_cost'] = is_null($t['total_cost']) ? 0 : (float)$t['total_cost'];
            $t['is_split'] = (int)$t['is_split'];
            $t['taken_by_id'] = is_null($t['taken_by_id']) ? null : (int)$t['taken_by_id'];

            // decode order_data and split_order_data only if not null/empty
            if (!empty($t['order_data'])) {
                $decoded = json_decode($t['order_data'], true);
                $t['order_data'] = ($decoded === null) ? $t['order_data'] : $decoded;
            } else {
                $t['order_data'] = null;
            }

            if (!empty($t['split_order_data'])) {
                $decoded = json_decode($t['split_order_data'], true);
                $t['split_order_data'] = ($decoded === null) ? $t['split_order_data'] : $decoded;
            } else {
                $t['split_order_data'] = null;
            }
        }
        unset($t);

        $resultCategories[] = [
            'category_id' => $c['category_id'],
            'category_name' => $c['category_name'],
            'category_table_count' => (int)$c['category_table_count'],
            'tables' => $tables,
            'created_at' => $c['created_at'],
            'updated_at' => $c['updated_at'],
        ];
    }

    http_response_code(200);
    echo json_encode(["categories" => $resultCategories]);
}




function createTable($conn, $userID, $userType) {
    if ($userType !== 'owner' && $userType !== 'manager') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners or managers can create tables"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['category_id'], $data['table_number'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $categoryId = $data['category_id'];
    $tableNumber = $data['table_number'];
    $tableStatus = $data['table_status'] ?? 'available';
    $isSplit = $data['is_split'] ?? false;
    $splitOrderData = $data['split_order_data'] ?? null;
    $orderData = $data['order_data'] ?? null;
    $takenById = $data['taken_by_id'] ?? null;
    $takenByRole = $data['taken_by_role'] ?? null;

    // Check if the category exists
    $sql = "SELECT category_id FROM categories WHERE category_id = :category_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryId);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "Category not found"]);
        return;
    }

    // Insert the new table
    $sql = "INSERT INTO tables (category_id, table_number, table_status, is_split, split_order_data, order_data, taken_by_id, taken_by_role) 
            VALUES (:category_id, :table_number, :table_status, :is_split, :split_order_data, :order_data, :taken_by_id, :taken_by_role)";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryId);
    $stmt->bindParam(':table_number', $tableNumber);
    $stmt->bindParam(':table_status', $tableStatus);
    $stmt->bindParam(':is_split', $isSplit);
    $stmt->bindParam(':split_order_data', json_encode($splitOrderData));
    $stmt->bindParam(':order_data', json_encode($orderData));
    $stmt->bindParam(':taken_by_id', $takenById);
    $stmt->bindParam(':taken_by_role', $takenByRole);

    if ($stmt->execute()) {
        http_response_code(201); // Created
        echo json_encode(["message" => "Table created successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error creating table"]);
    }
}



function updateTable($conn, $userID, $userType) {
    if ($userType !== 'owner' && $userType !== 'manager') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners or managers can update tables"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['table_id'], $data['category_id'], $data['table_number'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $tableId = $data['table_id'];
    $categoryId = $data['category_id'];
    $tableNumber = $data['table_number'];
    $tableStatus = $data['table_status'] ?? 'available';
    $isSplit = $data['is_split'] ?? false;
    $splitOrderData = $data['split_order_data'] ?? null;
    $orderData = $data['order_data'] ?? null;
    $takenById = $data['taken_by_id'] ?? null;
    $takenByRole = $data['taken_by_role'] ?? null;

    // Check if the category exists
    $sql = "SELECT category_id FROM categories WHERE category_id = :category_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryId);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "Category not found"]);
        return;
    }

    // Update the table
    $sql = "UPDATE tables SET table_number = :table_number, table_status = :table_status, is_split = :is_split, 
            split_order_data = :split_order_data, order_data = :order_data, taken_by_id = :taken_by_id, 
            taken_by_role = :taken_by_role WHERE table_id = :table_id AND category_id = :category_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':table_number', $tableNumber);
    $stmt->bindParam(':table_status', $tableStatus);
    $stmt->bindParam(':is_split', $isSplit);
    $stmt->bindParam(':split_order_data', json_encode($splitOrderData));
    $stmt->bindParam(':order_data', json_encode($orderData));
    $stmt->bindParam(':taken_by_id', $takenById);
    $stmt->bindParam(':taken_by_role', $takenByRole);
    $stmt->bindParam(':table_id', $tableId);
    $stmt->bindParam(':category_id', $categoryId);

    if ($stmt->execute()) {
        http_response_code(200); // OK
        echo json_encode(["message" => "Table updated successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error updating table"]);
    }
}


function deleteTable($conn, $userID, $userType) {
    if ($userType !== 'owner' && $userType !== 'manager') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners or managers can delete tables"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['table_id'], $data['category_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Table ID and Category ID are required"]);
        return;
    }

    $tableId = $data['table_id'];
    $categoryId = $data['category_id'];

    // Check if the category exists
    $sql = "SELECT category_id FROM categories WHERE category_id = :category_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryId);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "Category not found"]);
        return;
    }

    // Delete the table
    $sql = "DELETE FROM tables WHERE table_id = :table_id AND category_id = :category_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':table_id', $tableId);
    $stmt->bindParam(':category_id', $categoryId);

    if ($stmt->execute()) {
        http_response_code(200); // OK
        echo json_encode(["message" => "Table deleted successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error deleting table"]);
    }
}


?>
