<?php
include '../db.php'; // Include the database connection file
include '../validate.php';

$jwt = getJWTFromHeader();
if ($jwt === null) {
    http_response_code(401); // Unauthorized
    echo json_encode(["error" => "Token is missing or invalid"]);
    return;
}

$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(["error" => "Invalid token"]);
    return;
}

header('Content-Type: application/json');

// Get the request payload (assumes JSON request)
$request = json_decode(file_get_contents('php://input'), true);

// Check if action is set in the request
if (!isset($request['action'])) {
    echo json_encode(["error" => "Action parameter is required"]);
    http_response_code(400); // Bad Request
    exit;
}

$action = $request['action'];

// Switch based on the action
switch ($action) {
  
    case 'get':
        getAllUserForHotel($request, $conn);
        break;
    case 'delete':
        deleteHotel($request, $conn);
        break;
    default:
        echo json_encode(["error" => "Invalid action"]);
        http_response_code(400); // Bad Request
        break;
}


function getAllUserForHotel($data, $conn) {
    $hotelID = isset($data['HotelID']) ? $data['HotelID'] : null;

    if (!$hotelID) {
        echo json_encode(["message" => "HotelID is required"]);
        http_response_code(400); // Bad Request
        return;
    }

    // Query to select all users for the given HotelID
    $sql = "SELECT * FROM userlist WHERE HotelID = :HotelID";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':HotelID', $hotelID, PDO::PARAM_INT);

    try {
        // Execute the query
        $stmt->execute();

        // Check if any users were found
        if ($stmt->rowCount() === 0) {
            echo json_encode(["message" => "Hotel not found"]);
            http_response_code(404); // Not Found
        } else {
            // Fetch all results
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["Users" => $result]);
            http_response_code(200); // OK
        }
    } catch (PDOException $e) {
        // Handle database error
        echo json_encode(["error" => "Internal server error", "details" => $e->getMessage()]);
        http_response_code(500); // Internal Server Error
    }
}


function deleteHotel($data, $conn) {
    try {
        $hotelID = $data['hotelID'];

        $sql = "DELETE FROM hotels WHERE HotelID = :hotelID";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);

        if ($stmt->execute()) {
            http_response_code(200); // OK
            echo json_encode(["message" => "Hotel deleted successfully"]);
        } else {
            $errorInfo = $stmt->errorInfo();
            http_response_code(500); // Internal Server Error
            echo json_encode(["error" => "Error: " . $errorInfo[2]]);
        }
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}
?>
