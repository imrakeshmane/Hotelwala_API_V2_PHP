<?php
include '../db.php'; // Include database connection
include '../validate.php'; // Include JWT validation

// Fetch JWT token from the Authorization header
$jwt = getJWTFromHeader();
if ($jwt === null) {
    http_response_code(401); // Unauthorized
    return;
}

// Validate JWT token
$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
    http_response_code(401); // Unauthorized
    return;
}

// Set header for JSON response
header('Content-Type: application/json');

// Get the incoming JSON request body
$request = json_decode(file_get_contents('php://input'), true);

// Ensure the 'action' parameter is set
if (!isset($request['action'])) {
    echo json_encode(["error" => "Action parameter is required"]);
    http_response_code(400); // Bad Request
    exit;
}

$action = $request['action'];

// Switch based on the 'action' parameter
switch ($action) {
    case 'insert':
        insertMenuCard($request, $conn);
        break;
    case 'update':
        updateMenuCard($request, $conn);
        break;
    case 'get':
        getMenuCard($request, $conn);
        break;
    case 'delete':
        deleteMenuCard($request, $conn);
        break;
    default:
        echo json_encode(["error" => "Invalid action"]);
        http_response_code(400); // Bad Request
        break;
}

// Insert a new menu item
function insertMenuCard($data, $conn) {
    $hotelID = $data['hotelID'];
    $userID = $data['userID'];
    $menuName = $data['menuName'];
    $menuType = $data['menuType'];
    $menuPrice = $data['menuPrice'];
    $stock = $data['stock'];
    $active = $data['active'];

    $sql = "INSERT INTO menucard (HotelID, UserID, MenuName, MenuType, MenuPrice, Stock, Active, CreatedDate, UpdatedDate) 
            VALUES (:hotelID, :userID, :menuName, :menuType, :menuPrice, :stock, :active, NOW(), NOW())";
    
    $stmt = $conn->prepare($sql);

    $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
    $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
    $stmt->bindParam(':menuName', $menuName, PDO::PARAM_STR);
    $stmt->bindParam(':menuType', $menuType, PDO::PARAM_STR);
    $stmt->bindParam(':menuPrice', $menuPrice, PDO::PARAM_STR);
    $stmt->bindParam(':stock', $stock, PDO::PARAM_INT);
    $stmt->bindParam(':active', $active, PDO::PARAM_INT);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode([
            "message" => "Menu item added successfully.",
            "MenuID" => $conn->lastInsertId(),
        ]);
    } else {
        $errorInfo = $stmt->errorInfo();
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error: " . $errorInfo[2]]);
    }
}

// Update an existing menu item
function updateMenuCard($data, $conn) {
    $menuID = $data['menuID'];
    $hotelID = $data['hotelID'];
    $userID = $data['userID'];
    $menuName = $data['menuName'];
    $menuType = $data['menuType'];
    $menuPrice = $data['menuPrice'];
    $stock = $data['stock'];
    $active = $data['active'];

    $sql = "UPDATE menucard SET 
                HotelID = :hotelID, 
                UserID = :userID, 
                MenuName = :menuName, 
                MenuType = :menuType, 
                MenuPrice = :menuPrice, 
                Stock = :stock, 
                Active = :active, 
                UpdatedDate = NOW() 
            WHERE MenuID = :menuID";
    
    $stmt = $conn->prepare($sql);

    $stmt->bindParam(':menuID', $menuID, PDO::PARAM_INT);
    $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
    $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
    $stmt->bindParam(':menuName', $menuName, PDO::PARAM_STR);
    $stmt->bindParam(':menuType', $menuType, PDO::PARAM_STR);
    $stmt->bindParam(':menuPrice', $menuPrice, PDO::PARAM_STR);
    $stmt->bindParam(':stock', $stock, PDO::PARAM_INT);
    $stmt->bindParam(':active', $active, PDO::PARAM_INT);

    if ($stmt->execute()) {
        http_response_code(200); // OK
        echo json_encode(["message" => "Menu item updated successfully"]);
    } else {
        $errorInfo = $stmt->errorInfo();
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error: " . $errorInfo[2]]);
    }
}

// Get menu items (with optional filters)
function getMenuCard($data, $conn) {
    $menuID = isset($data['menuID']) ? $data['menuID'] : null;
    $hotelID = isset($data['hotelID']) ? $data['hotelID'] : null;
    $userID = isset($data['userID']) ? $data['userID'] : null;
    
    // Build query based on filters
    if ($menuID) {
        $sql = "SELECT * FROM menucard WHERE MenuID = :menuID";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':menuID', $menuID, PDO::PARAM_INT);
    } elseif ($hotelID && $userID) {
        $sql = "SELECT * FROM menucard WHERE HotelID = :hotelID AND UserID = :userID";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
    } elseif ($hotelID) {
        $sql = "SELECT * FROM menucard WHERE HotelID = :hotelID";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
    } else {
        $sql = "SELECT * FROM menucard";
        $stmt = $conn->prepare($sql);
    }

    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $menuItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["MenuList" => $menuItems]);
    } else {
        echo json_encode(["message" => "No menu items found"]);
        http_response_code(404); // Not Found
    }
}

// Delete a menu item
function deleteMenuCard($data, $conn) {
    $menuID = $data['menuID'];

    $sql = "DELETE FROM menucard WHERE MenuID = :menuID";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':menuID', $menuID, PDO::PARAM_INT);

    if ($stmt->execute()) {
        http_response_code(200); // OK
        echo json_encode(["message" => "Menu item deleted successfully"]);
    } else {
        $errorInfo = $stmt->errorInfo();
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error: " . $errorInfo[2]]);
    }
}
?>
