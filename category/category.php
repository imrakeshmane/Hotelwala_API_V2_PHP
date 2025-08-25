<?php
include '../db.php'; // Include your database connection
include '../validate.php'; // Include the file containing getJWTFromHeader and validateJWT functions

header('Content-Type: application/json');
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Reusable function to send standardized responses
function sendResponse($success, $message, $data = null, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ]);
    exit;
}

// Get JWT token from Authorization header
$jwt = getJWTFromHeader();
if ($jwt === null) {
    sendResponse(false, "Token is missing or invalid", null, 401);
}

// Validate the JWT token
$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
    sendResponse(false, "Invalid token", null, 401);
}

// Extract owner ID and user type from the JWT payload
$ownerID = $payload['owner_id'];
$userType = $payload['user_type'];

if ($userType !== 'owner') {
    sendResponse(false, "Only owners can manage categories and tables", null, 403);
}

switch ($requestMethod) {
    case 'POST':
        createCategory($conn, $ownerID);
        break;
    case 'GET':
        getCategories($conn, $ownerID);
        break;
    case 'PUT':
        updateCategory($conn, $ownerID);
        break;
    case 'DELETE':
        deleteCategory($conn, $ownerID);
        break;
    default:
        sendResponse(false, "Method not allowed", null, 405);
}

function createCategory($conn, $ownerID) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['hotel_id'], $data['category_name'], $data['num_of_tables'])) {
        sendResponse(false, "Missing required fields", null, 400);
    }

    $hotelID = $data['hotel_id'];
    $categoryName = $data['category_name'];
    $numOfTables = $data['num_of_tables'];

    // Check if the hotel belongs to the owner
    $sql = "SELECT * FROM hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelID);
    $stmt->bindParam(':owner_id', $ownerID);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        sendResponse(false, "Unauthorized to manage this hotel", null, 403);
    }

    // Insert the category
    $sql = "INSERT INTO categories (hotel_id, category_name, category_table_count) 
            VALUES (:hotel_id, :category_name, :num_of_tables)";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelID);
    $stmt->bindParam(':category_name', $categoryName);
    $stmt->bindParam(':num_of_tables', $numOfTables);

    if ($stmt->execute()) {
        $categoryID = $conn->lastInsertId();

        // Insert tables for the category
        $sqlTable = "INSERT INTO tables (category_id, table_number) VALUES (:category_id, :table_number)";
        $stmtTable = $conn->prepare($sqlTable);
        for ($i = 1; $i <= $numOfTables; $i++) {
            $tableNumber = $i;
            $stmtTable->bindParam(':category_id', $categoryID);
            $stmtTable->bindParam(':table_number', $tableNumber);
            $stmtTable->execute();
        }

        // Now fetch the newly created category along with its tables using a LEFT JOIN
        $sqlSelect = "SELECT c.category_id, c.category_name, c.category_table_count,
                             t.table_id, t.table_number, t.table_status
                      FROM categories c
                      LEFT JOIN tables t ON c.category_id = t.category_id
                      WHERE c.category_id = :category_id
                      ORDER BY t.table_number";
        $stmtSelect = $conn->prepare($sqlSelect);
        $stmtSelect->bindParam(':category_id', $categoryID);
        $stmtSelect->execute();

        // Build the category structure
        $categoryData = null;
        while ($row = $stmtSelect->fetch(PDO::FETCH_ASSOC)) {
            if (!$categoryData) {
                $categoryData = [
                    "category_id" => $row['category_id'],
                    "category_name" => $row['category_name'],
                    "category_table_count" => $row['category_table_count'],
                    "tables" => []
                ];
            }
            if ($row['table_id']) {
                $categoryData["tables"][] = [
                    "table_id" => $row['table_id'],
                    "table_number" => $row['table_number'],
                    "table_status" => $row['table_status']
                ];
            }
        }

        sendResponse(true, "Category and tables created successfully", $categoryData, 201);
    } else {
        sendResponse(false, "Error creating category", null, 500);
    }
}


function getCategories($conn, $ownerID) {
    if (!isset($_GET['hotel_id'])) {
        sendResponse(false, "Hotel ID is required", null, 400);
    }

    $hotelID = $_GET['hotel_id'];

    // Check if the hotel belongs to the owner
    $sql = "SELECT * FROM hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelID);
    $stmt->bindParam(':owner_id', $ownerID);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        sendResponse(false, "Unauthorized to view this hotel's categories", null, 403);
    }

    // Fetch categories and their tables
    $sql = "SELECT c.category_id, c.category_name, c.category_table_count, 
                   t.table_id, t.table_number, t.table_status
            FROM categories c
            LEFT JOIN tables t ON c.category_id = t.category_id
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

    sendResponse(true, "Categories fetched successfully", ["categories" => array_values($categories)], 200);
}

function updateCategory($conn, $ownerID) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['category_id'], $data['category_name'])) {
        sendResponse(false, "Missing required fields", null, 400);
    }

    $categoryID = $data['category_id'];
    $categoryName = $data['category_name'];

    // Check if the category belongs to a hotel owned by the owner
    $sql = "SELECT c.category_id 
            FROM categories c
            JOIN hotels h ON c.hotel_id = h.hotel_id
            WHERE c.category_id = :category_id AND h.owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryID);
    $stmt->bindParam(':owner_id', $ownerID);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        sendResponse(false, "Unauthorized to update this category", null, 403);
    }

    // Update the category name
    $sql = "UPDATE categories SET category_name = :category_name WHERE category_id = :category_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_name', $categoryName);
    $stmt->bindParam(':category_id', $categoryID);

    if ($stmt->execute()) {
        sendResponse(true, "Category updated successfully", null, 200);
    } else {
        sendResponse(false, "Error updating category", null, 500);
    }
}

function deleteCategory($conn, $ownerID) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['category_id'])) {
        sendResponse(false, "Category ID is required", null, 400);
    }

    $categoryID = $data['category_id'];

    // Check if the category belongs to a hotel owned by the owner
    $sql = "SELECT c.category_id 
            FROM categories c
            JOIN hotels h ON c.hotel_id = h.hotel_id
            WHERE c.category_id = :category_id AND h.owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryID);
    $stmt->bindParam(':owner_id', $ownerID);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        sendResponse(false, "Unauthorized to delete this category", null, 403);
    }

    // Delete the category and its associated tables
    $sql = "DELETE FROM categories WHERE category_id = :category_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryID);

    if ($stmt->execute()) {
        sendResponse(true, "Category and associated tables deleted successfully", null, 200);
    } else {
        sendResponse(false, "Error deleting category", null, 500);
    }
}
?>
