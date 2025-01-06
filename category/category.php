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

// Extract owner ID and user type from the JWT payload
$ownerID = $payload['owner_id'];
$userType = $payload['user_type'];

if ($userType !== 'owner') {
    http_response_code(403); // Forbidden
    echo json_encode(["error" => "Only owners can manage categories and tables"]);
    return;
}

switch ($requestMethod) {
    case 'POST': // Create a new category
        createCategory($conn, $ownerID);
        break;
    case 'GET': // Get categories and their tables
        getCategories($conn, $ownerID);
        break;
    case 'PUT': // Update a category
        updateCategory($conn, $ownerID);
        break;
    case 'DELETE': // Delete a category
        deleteCategory($conn, $ownerID);
        break;
    default:
        http_response_code(405); // Method not allowed
        echo json_encode(["error" => "Method not allowed"]);
        break;
}

function createCategory($conn, $ownerID) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['hotel_id'], $data['category_name'], $data['num_of_tables'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $hotelID = $data['hotel_id'];
    $categoryName = $data['category_name'];
    $numOfTables = $data['num_of_tables'];

    // Check if the hotel belongs to the owner
    $sql = "SELECT * FROM Hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelID);
    $stmt->bindParam(':owner_id', $ownerID);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Unauthorized to manage this hotel"]);
        return;
    }

    // Insert the category
    $sql = "INSERT INTO Categories (hotel_id, category_name, category_table_count) 
            VALUES (:hotel_id, :category_name, :num_of_tables)";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelID);
    $stmt->bindParam(':category_name', $categoryName);
    $stmt->bindParam(':num_of_tables', $numOfTables);

    if ($stmt->execute()) {
        $categoryID = $conn->lastInsertId();

        // Generate tables for the category
        $sql = "INSERT INTO Tables (category_id, table_number) VALUES (:category_id, :table_number)";
        $stmt = $conn->prepare($sql);
        for ($i = 1; $i <= $numOfTables; $i++) {
            // $tableNumber = "T" . $i;
            $tableNumber = $i;
            $stmt->bindParam(':category_id', $categoryID);
            $stmt->bindParam(':table_number', $tableNumber);
            $stmt->execute();
        }

        http_response_code(201); // Created
        echo json_encode(["message" => "Category and tables created successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error creating category"]);
    }
}

function getCategories($conn, $ownerID) {
    if (!isset($_GET['hotel_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Hotel ID is required"]);
        return;
    }

    $hotelID = $_GET['hotel_id'];

    // Check if the hotel belongs to the owner
    $sql = "SELECT * FROM Hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelID);
    $stmt->bindParam(':owner_id', $ownerID);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Unauthorized to view this hotel's categories"]);
        return;
    }

    // Fetch categories and their tables
    $sql = "SELECT c.category_id, c.category_name, c.category_table_count, 
                   t.table_id, t.table_number, t.table_status
            FROM Categories c
            LEFT JOIN Tables t ON c.category_id = t.category_id
            WHERE c.hotel_id = :hotel_id
            ORDER BY c.category_id, t.table_number";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelID);
    $stmt->execute();

    $categories = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $categoryID = $row['category_id'];
        if (!isset($categories[$categoryID])) {
            $categories[$categoryID] = [
                "category_id" => $row['category_id'],
                "category_name" => $row['category_name'],
                "category_table_count" => $row['category_table_count'],
                "tables" => []
            ];
        }
        if ($row['table_id']) {
            $categories[$categoryID]['tables'][] = [
                "table_id" => $row['table_id'],
                "table_number" => $row['table_number'],
                "table_status" => $row['table_status']
            ];
        }
    }

    http_response_code(200); // OK
    echo json_encode(["categories" => array_values($categories)]);
}



function updateCategory($conn, $ownerID) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['category_id'], $data['category_name'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $categoryID = $data['category_id'];
    $categoryName = $data['category_name'];

    // Check if the category belongs to a hotel owned by the owner
    $sql = "SELECT c.category_id 
            FROM Categories c
            JOIN Hotels h ON c.hotel_id = h.hotel_id
            WHERE c.category_id = :category_id AND h.owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryID);
    $stmt->bindParam(':owner_id', $ownerID);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Unauthorized to update this category"]);
        return;
    }

    // Update the category name
    $sql = "UPDATE Categories SET category_name = :category_name WHERE category_id = :category_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_name', $categoryName);
    $stmt->bindParam(':category_id', $categoryID);

    if ($stmt->execute()) {
        http_response_code(200); // OK
        echo json_encode(["message" => "Category updated successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error updating category"]);
    }
}

function deleteCategory($conn, $ownerID) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['category_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Category ID is required"]);
        return;
    }

    $categoryID = $data['category_id'];

    // Check if the category belongs to a hotel owned by the owner
    $sql = "SELECT c.category_id 
            FROM Categories c
            JOIN Hotels h ON c.hotel_id = h.hotel_id
            WHERE c.category_id = :category_id AND h.owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryID);
    $stmt->bindParam(':owner_id', $ownerID);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Unauthorized to delete this category"]);
        return;
    }

    // Delete the category and its associated tables
    $sql = "DELETE FROM Categories WHERE category_id = :category_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryID);

    if ($stmt->execute()) {
        http_response_code(200); // OK
        echo json_encode(["message" => "Category and associated tables deleted successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error deleting category"]);
    }
}
?>
