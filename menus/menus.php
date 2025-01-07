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
        createMenu($conn, $userID, $userType);
        break;
    case 'GET': // Read menu items
        getMenus($conn, $userID, $userType);
        break;
    case 'PUT': // Update an existing menu item
        updateMenu($conn, $userID, $userType);
        break;
    case 'DELETE': // Delete a menu item
        deleteMenu($conn, $userID, $userType);
        break;
    default:
        http_response_code(405); // Method not allowed
        echo json_encode(["error" => "Method not allowed"]);
        break;
}

function createMenu($conn, $userID, $userType) {
    if ($userType !== 'owner' && $userType !== 'manager') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners or managers can create menu items"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['hotel_id'], $data['menu_name'], $data['menu_type'], $data['menu_price'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $hotelId = $data['hotel_id'];
    $menuName = $data['menu_name'];
    $menuType = $data['menu_type'];
    $menuPrice = $data['menu_price'];
    $menuStock = $data['menu_stock'] ?? 0;
    $isActive = $data['is_active'] ?? true;

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

    // Insert the new menu item
    $sql = "INSERT INTO Menus (hotel_id, menu_name, menu_type, menu_price, menu_stock, is_active) 
            VALUES (:hotel_id, :menu_name, :menu_type, :menu_price, :menu_stock, :is_active)";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->bindParam(':menu_name', $menuName);
    $stmt->bindParam(':menu_type', $menuType);
    $stmt->bindParam(':menu_price', $menuPrice);
    $stmt->bindParam(':menu_stock', $menuStock);
    $stmt->bindParam(':is_active', $isActive);

    if ($stmt->execute()) {
        http_response_code(201); // Created
        echo json_encode(["message" => "Menu item created successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error creating menu item"]);
    }
}

function getMenus($conn, $userID, $userType) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['hotel_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Hotel ID is required"]);
        return;
    }

    $hotelId = $data['hotel_id'];

    // Check if hotel exists and belongs to the user
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

    // Fetch menu items for the given hotel
    $sql = "SELECT * FROM Menus WHERE hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();
    $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200); // OK
    echo json_encode(["menus" => $menus]);
}

function updateMenu($conn, $userID, $userType) {
    if ($userType !== 'owner' && $userType !== 'manager') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners or managers can update menu items"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['menu_id'], $data['hotel_id'], $data['menu_name'], $data['menu_type'], $data['menu_price'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $menuId = $data['menu_id'];
    $hotelId = $data['hotel_id'];
    $menuName = $data['menu_name'];
    $menuType = $data['menu_type'];
    $menuPrice = $data['menu_price'];
    $menuStock = $data['menu_stock'] ?? 0;
    $isActive = $data['is_active'] ?? true;

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

    // Update the menu item
    $sql = "UPDATE Menus SET menu_name = :menu_name, menu_type = :menu_type, menu_price = :menu_price, 
            menu_stock = :menu_stock, is_active = :is_active WHERE menu_id = :menu_id AND hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':menu_name', $menuName);
    $stmt->bindParam(':menu_type', $menuType);
    $stmt->bindParam(':menu_price', $menuPrice);
    $stmt->bindParam(':menu_stock', $menuStock);
    $stmt->bindParam(':is_active', $isActive);
    $stmt->bindParam(':menu_id', $menuId);
    $stmt->bindParam(':hotel_id', $hotelId);

    if ($stmt->execute()) {
        http_response_code(200); // OK
        echo json_encode(["message" => "Menu item updated successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error updating menu item"]);
    }
}

function deleteMenu($conn, $userID, $userType) {
    if ($userType !== 'owner' && $userType !== 'manager') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners or managers can delete menu items"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['menu_id'], $data['hotel_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Menu ID and Hotel ID are required"]);
        return;
    }

    $menuId = $data['menu_id'];
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

    // Delete the menu item
    $sql = "DELETE FROM Menus WHERE menu_id = :menu_id AND hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':menu_id', $menuId);
    $stmt->bindParam(':hotel_id', $hotelId);

    if ($stmt->execute()) {
        http_response_code(200); // OK
        echo json_encode(["message" => "Menu item deleted successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error deleting menu item"]);
    }
}
?>
