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
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['hotel_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Hotel ID is required"]);
        return;
    }

    $hotelId = $data['hotel_id'];

    // Check if the hotel exists and belongs to the user
    $sql = "SELECT hotel_id FROM Hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->bindParam(':owner_id', $userID);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "Hotel not found or you don't have access"]);
        return;
    }

    // Fetch tables for the given hotel
    $sql = "SELECT * FROM Tables WHERE category_id IN (SELECT category_id FROM Categories WHERE hotel_id = :hotel_id)";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();
    $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200); // OK
    echo json_encode(["tables" => $tables]);
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
    $sql = "SELECT category_id FROM Categories WHERE category_id = :category_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryId);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "Category not found"]);
        return;
    }

    // Insert the new table
    $sql = "INSERT INTO Tables (category_id, table_number, table_status, is_split, split_order_data, order_data, taken_by_id, taken_by_role) 
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
    $sql = "SELECT category_id FROM Categories WHERE category_id = :category_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryId);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "Category not found"]);
        return;
    }

    // Update the table
    $sql = "UPDATE Tables SET table_number = :table_number, table_status = :table_status, is_split = :is_split, 
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
    $sql = "SELECT category_id FROM Categories WHERE category_id = :category_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryId);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "Category not found"]);
        return;
    }

    // Delete the table
    $sql = "DELETE FROM Tables WHERE table_id = :table_id AND category_id = :category_id";
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
