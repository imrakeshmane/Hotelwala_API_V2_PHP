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
    case 'POST': // Create a new hotel
        createHotel($conn, $userID, $userType);
        break;
    case 'GET': // Read hotels
        getHotels($conn, $userID, $userType);
        break;
    case 'PUT': // Update an existing hotel
        updateHotel($conn, $userID, $userType);
        break;
    case 'DELETE': // Delete a hotel by hotel_id
        deleteHotel($conn, $userID, $userType);
        break;
    default:
        http_response_code(405); // Method not allowed
        echo json_encode(["error" => "Method not allowed"]);
        break;
}

function createHotel($conn, $userID, $userType)
{
    if ($userType !== 'owner') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners can create hotels"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['hotel_name'], $data['hotel_location'], $data['pincode'], $data['hotel_mobile_number'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $hotelName = $data['hotel_name'];
    $hotelLocation = $data['hotel_location'];
    $pincode = $data['pincode'];
    $hotelMobileNumber = $data['hotel_mobile_number'];

    $sql = "INSERT INTO Hotels (owner_id, hotel_name, hotel_location, pincode, hotel_mobile_number) 
            VALUES (:owner_id, :hotel_name, :hotel_location, :pincode, :hotel_mobile_number)";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':owner_id', $userID);
    $stmt->bindParam(':hotel_name', $hotelName);
    $stmt->bindParam(':hotel_location', $hotelLocation);
    $stmt->bindParam(':pincode', $pincode);
    $stmt->bindParam(':hotel_mobile_number', $hotelMobileNumber);

    if ($stmt->execute()) {
        http_response_code(201); // Created
        echo json_encode(["message" => "Hotel created successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error creating hotel"]);
    }
}

function getHotels($conn, $userID, $userType)
{
    if (isset($_GET['hotel_id'])) {
        $hotelId = $_GET['hotel_id'];

        if ($userType === 'user') {
            $sql = "SELECT * FROM Users WHERE user_id = :user_id AND hotel_id = :hotel_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':user_id', $userID);
            $stmt->bindParam(':hotel_id', $hotelId);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                http_response_code(403); // Forbidden
                echo json_encode(["error" => "User is not authorized to view this hotel"]);
                return;
            }
        }

        $sql = "SELECT * FROM Hotels WHERE hotel_id = :hotel_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':hotel_id', $hotelId);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            http_response_code(200); // OK
            echo json_encode(["hotel" => $stmt->fetch(PDO::FETCH_ASSOC)]);
        } else {
            http_response_code(404); // Not Found
            echo json_encode(["error" => "Hotel not found"]);
        }
    } else {
        if ($userType === 'owner') {
            $sql = "SELECT * FROM Hotels WHERE owner_id = :owner_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':owner_id', $userID);
            $stmt->execute();
            $hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);

            http_response_code(200); // OK
            echo json_encode(["hotels" => $hotels]);
        } else {
            http_response_code(403); // Forbidden
            echo json_encode(["error" => "Hotel ID is required for non-owners"]);
        }
    }
}

function updateHotel($conn, $userID, $userType)
{
    if ($userType !== 'owner') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners can update hotels"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['hotel_id'], $data['hotel_name'], $data['hotel_location'], $data['pincode'], $data['hotel_mobile_number'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $hotelId = $data['hotel_id'];
    $hotelName = $data['hotel_name'];
    $hotelLocation = $data['hotel_location'];
    $pincode = $data['pincode'];
    $hotelMobileNumber = $data['hotel_mobile_number'];

    $sql = "UPDATE Hotels 
            SET hotel_name = :hotel_name, hotel_location = :hotel_location, pincode = :pincode, hotel_mobile_number = :hotel_mobile_number 
            WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_name', $hotelName);
    $stmt->bindParam(':hotel_location', $hotelLocation);
    $stmt->bindParam(':pincode', $pincode);
    $stmt->bindParam(':hotel_mobile_number', $hotelMobileNumber);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->bindParam(':owner_id', $userID);

    if ($stmt->execute()) {
        http_response_code(200); // OK
        echo json_encode(["message" => "Hotel updated successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error updating hotel"]);
    }
}


function deleteHotel($conn, $userID, $userType)
{
    if ($userType !== 'owner') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners can delete hotels"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['hotel_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Hotel ID is required"]);
        return;
    }

    $hotelId = $data['hotel_id'];

    $sql = "DELETE FROM Hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->bindParam(':owner_id', $userID);

    if ($stmt->execute()) {
        http_response_code(200); // OK
        echo json_encode(["message" => "Hotel deleted successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error deleting hotel"]);
    }
}
