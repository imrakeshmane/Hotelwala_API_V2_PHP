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
    case 'POST': // Pay the bill and reset table status
        payBill($conn, $userID, $userType);
        break;
    default:
        http_response_code(405); // Method not allowed
        echo json_encode(["error" => "Method not allowed"]);
        break;
}

function payBill($conn, $userID, $userType) {
    if ($userType !== 'owner' && $userType !== 'user') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners or users can pay the bill"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['table_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Table ID is required"]);
        return;
    }

    $tableId = $data['table_id'];

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

    if ($table['table_status'] === 'available') {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Table is already available"]);
        return;
    }

    // Insert order data into OrderHistory
    $sql = "INSERT INTO OrderHistory (hotel_id, table_id, order_data, total_cost, payment_status, 
                                      taken_by_id, taken_by_role) 
            VALUES (:hotel_id, :table_id, :order_data, :total_cost, 'paid', :taken_by_id, :taken_by_role)";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $table['hotel_id']);
    $stmt->bindParam(':table_id', $tableId);
    $stmt->bindParam(':order_data', $table['order_data']);
    $stmt->bindParam(':total_cost', $table['total_cost']);
    $stmt->bindParam(':taken_by_id', $table['taken_by_id']);
    $stmt->bindParam(':taken_by_role', $table['taken_by_role']);

    if ($stmt->execute()) {
        // Reset the table status and order data
        $sql = "UPDATE Tables SET table_status = 'available', order_data = NULL, total_cost = NULL, 
                taken_by_id = NULL, taken_by_role = NULL WHERE table_id = :table_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':table_id', $tableId);
        
        if ($stmt->execute()) {
            http_response_code(200); // OK
            echo json_encode(["message" => "Bill paid, table reset successfully"]);
        } else {
            http_response_code(500); // Internal Server Error
            echo json_encode(["error" => "Error resetting table status"]);
        }
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error moving order to history"]);
    }
}
?>
