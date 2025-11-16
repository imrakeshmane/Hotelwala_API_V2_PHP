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

/*
  IMPORTANT: JWT payload may contain different keys depending on your auth implementation.
  Some tokens might include 'owner_id' (for owner), others 'user_id' (for manager/staff).
  We'll support both: prefer 'user_id' then fallback 'owner_id'.
*/
$userID   = $payload['user_id']   ?? $payload['owner_id'] ?? null;
$userType = $payload['user_type'] ?? null;

if ($userID === null || $userType === null) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid token payload (missing user id or type)"]);
    return;
}

/**
 * Robust hotel-access check. Adapt the fallback checks to your schema.
 *
 * - owner: checks hotels.owner_id = actor id
 * - staff: tries users.hotel_id (if your users table stores hotel_id for staff)
 * - fallback: checks user_hotels mapping table (many-to-many)
 *
 * NOTE: remove or edit fallbacks you don't have. If you have a different mapping (manager_hotels etc.)
 * update the SQL accordingly.
 */
function hasHotelAccess($conn, $hotelId, $actorId, $actorType) {
    if (empty($hotelId) || empty($actorId)) return false;

    try {
        // 1) Owner check
        if ($actorType === 'owner') {
            $sql = "SELECT 1 FROM hotels WHERE hotel_id = :hotel_id AND owner_id = :actor_id LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':hotel_id', $hotelId);
            $stmt->bindParam(':actor_id', $actorId);
            $stmt->execute();
            if ($stmt->fetchColumn()) return true;
        }

        // 2) Staff check via users table
        // NOTE: many schemas store staff->hotel mapping in users.hotel_id OR a separate mapping table.
        // This query only checks users.user_id and users.hotel_id (no user_type column required).
        $sql = "SELECT 1 FROM users WHERE user_id = :actor_id AND hotel_id = :hotel_id LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':actor_id', $actorId);
        $stmt->bindParam(':hotel_id', $hotelId);
        $stmt->execute();
        if ($stmt->fetchColumn()) return true;

        // 3) Fallback: many-to-many mapping table (user_hotels)
        // Keep this only if you have such a table.
        $sql = "SELECT 1 FROM user_hotels WHERE user_id = :actor_id AND hotel_id = :hotel_id LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':actor_id', $actorId);
        $stmt->bindParam(':hotel_id', $hotelId);
        $stmt->execute();
        if ($stmt->fetchColumn()) return true;

    } catch (PDOException $e) {
        // Optional: log error somewhere during development
        // error_log('hasHotelAccess DB error: ' . $e->getMessage());
        return false;
    }

    return false;
}


switch ($requestMethod) {
    case 'POST': // Create a new table
        createTable($conn, $userID, $userType);
        break;
    case 'GET': // Read tables
        getTables($conn, $userID, $userType);
        break;
    case 'PUT': // Update an existing table
        updateTable($conn, $userID, $userType);
        break;
    case 'DELETE': // Delete a table
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

    // Verify access for this hotel for ANY user type (owner/manager/staff)
    if (!hasHotelAccess($conn, $hotelId, $userID, $userType)) {
        http_response_code(403);
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
            $t['table_id'] = (int)$t['table_id'];
            $t['category_id'] = (int)$t['category_id'];
            $t['total_cost'] = is_null($t['total_cost']) ? 0 : (float)$t['total_cost'];
            $t['is_split'] = (int)$t['is_split'];
            $t['taken_by_id'] = is_null($t['taken_by_id']) ? null : (int)$t['taken_by_id'];

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
    // only owner or manager allowed
    if ($userType !== 'owner' && $userType !== 'manager') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners or managers can create tables"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['category_id'], $data['table_number'], $data['hotel_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Missing required fields. category_id, table_number and hotel_id required"]);
        return;
    }

    $categoryId = $data['category_id'];
    $tableNumber = $data['table_number'];
    $hotelId = $data['hotel_id'];

    // Verify the actor has access to this hotel
    if (!hasHotelAccess($conn, $hotelId, $userID, $userType)) {
        http_response_code(403);
        echo json_encode(["error" => "You don't have access to this hotel"]);
        return;
    }

    // Check if the category exists and belongs to the hotel
    $sql = "SELECT category_id FROM categories WHERE category_id = :category_id AND hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryId);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "Category not found for this hotel"]);
        return;
    }

    $tableStatus = $data['table_status'] ?? 'available';
    $isSplit = $data['is_split'] ?? false;
    $splitOrderData = $data['split_order_data'] ?? null;
    $orderData = $data['order_data'] ?? null;
    $takenById = $data['taken_by_id'] ?? null;
    $takenByRole = $data['taken_by_role'] ?? null;

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

    if (!isset($data['table_id'], $data['category_id'], $data['table_number'], $data['hotel_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $tableId = $data['table_id'];
    $categoryId = $data['category_id'];
    $tableNumber = $data['table_number'];
    $hotelId = $data['hotel_id'];

    // Verify the actor has access to this hotel
    if (!hasHotelAccess($conn, $hotelId, $userID, $userType)) {
        http_response_code(403);
        echo json_encode(["error" => "You don't have access to this hotel"]);
        return;
    }

    // Check if the category exists and belongs to the hotel
    $sql = "SELECT category_id FROM categories WHERE category_id = :category_id AND hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryId);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Category not found for this hotel"]);
        return;
    }

    $tableStatus = $data['table_status'] ?? 'available';
    $isSplit = $data['is_split'] ?? false;
    $splitOrderData = $data['split_order_data'] ?? null;
    $orderData = $data['order_data'] ?? null;
    $takenById = $data['taken_by_id'] ?? null;
    $takenByRole = $data['taken_by_role'] ?? null;

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
    if (!isset($data['table_id'], $data['category_id'], $data['hotel_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Table ID, Category ID and hotel_id are required"]);
        return;
    }

    $tableId = $data['table_id'];
    $categoryId = $data['category_id'];
    $hotelId = $data['hotel_id'];

    // Verify the actor has access to this hotel
    if (!hasHotelAccess($conn, $hotelId, $userID, $userType)) {
        http_response_code(403);
        echo json_encode(["error" => "You don't have access to this hotel"]);
        return;
    }

    // Check if the category exists and belongs to the hotel
    $sql = "SELECT category_id FROM categories WHERE category_id = :category_id AND hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryId);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Category not found for this hotel"]);
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
