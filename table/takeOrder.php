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
    case 'POST': // Take a new order
        takeOrder($conn, $userID, $userType);
        break;
    default:
        http_response_code(405); // Method not allowed
        echo json_encode(["error" => "Method not allowed"]);
        break;
}

function takeOrder($conn, $userID, $userType) {
    if ($userType !== 'owner' && $userType !== 'user') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners or users can take orders"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['table_id'], $data['order_data'], $data['total_cost'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $tableId = $data['table_id'];
    $orderData = $data['order_data'];
    $totalCost = $data['total_cost'];

    // Fetch the table details
    $sql = "SELECT * FROM Tables WHERE table_id = :table_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':table_id', $tableId);
    $stmt->execute();
    $table = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$table) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "Table not found"]);
        return;
    }

    if ($table['table_status'] === 'occupied') {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Table is already occupied"]);
        return;
    }

    // Update the table status to "occupied" and set order details
    $sql = "UPDATE Tables SET table_status = 'occupied', order_data = :order_data, total_cost = :total_cost, 
            taken_by_id = :taken_by_id, taken_by_role = :taken_by_role WHERE table_id = :table_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':order_data', json_encode($orderData));
    $stmt->bindParam(':total_cost', $totalCost);
    $stmt->bindParam(':taken_by_id', $userID);
    $stmt->bindParam(':taken_by_role', $userType);
    $stmt->bindParam(':table_id', $tableId);

    if ($stmt->execute()) {
        http_response_code(200); // OK
        echo json_encode(["message" => "Order taken successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error updating table status"]);
    }
}
?>
