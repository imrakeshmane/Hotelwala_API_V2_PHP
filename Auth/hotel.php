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
$userID = $payload['userID'];  // Extract userID from the payload
$userType = $payload['userType'];  // Extract userType from the payload

switch ($requestMethod) {
    case 'POST': // Create a new hotel
        createHotel($conn, $userID, $userType);
        break;
    case 'GET': // Read a hotel by hotel_id
        getHotel($conn, $userID, $userType);
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

function createHotel($conn, $userID, $userType) {
    // Only allow owners to create hotels
    if ($userType !== 'owner') {
        echo json_encode(["error" => "Only owners can create hotels"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (!isset($data['hotel_name'], $data['hotel_location'])) {
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $hotelName = $data['hotel_name'];
    $hotelLocation = $data['hotel_location'];

    // Insert the new hotel
    $sql = "INSERT INTO Hotels (owner_id, hotel_name, hotel_location) 
            VALUES (:owner_id, :hotel_name, :hotel_location)";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':owner_id', $userID); // Use the owner ID from the token
    $stmt->bindParam(':hotel_name', $hotelName);
    $stmt->bindParam(':hotel_location', $hotelLocation);

    if ($stmt->execute()) {
        echo json_encode(["message" => "Hotel created successfully"]);
    } else {
        echo json_encode(["error" => "Error creating hotel"]);
    }
}

function getHotel($conn, $userID, $userType) {
    if (!isset($_GET['hotel_id'])) {
        echo json_encode(["error" => "Hotel ID is required"]);
        return;
    }

    $hotelId = $_GET['hotel_id'];

    // Check if the user is authorized to view the hotel (Only owners or users working in the hotel)
    if ($userType === 'user') {
        // Fetch the hotel the user works at
        $sqlUserHotel = "SELECT * FROM Users WHERE user_id = :user_id AND hotel_id = :hotel_id";
        $stmt = $conn->prepare($sqlUserHotel);
        $stmt->bindParam(':user_id', $userID);
        $stmt->bindParam(':hotel_id', $hotelId);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            echo json_encode(["error" => "User is not authorized to view this hotel"]);
            return;
        }
    }

    // Fetch hotel details
    $sql = "SELECT * FROM Hotels WHERE hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $hotel = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(["hotel" => $hotel]);
    } else {
        echo json_encode(["error" => "Hotel not found"]);
    }
}

function updateHotel($conn, $userID, $userType) {
    if (!isset($_GET['hotel_id'])) {
        echo json_encode(["error" => "Hotel ID is required"]);
        return;
    }

    $hotelId = $_GET['hotel_id'];

    // Only owners can update hotels
    if ($userType !== 'owner') {
        echo json_encode(["error" => "Only owners can update hotels"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (!isset($data['hotel_name'], $data['hotel_location'])) {
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $hotelName = $data['hotel_name'];
    $hotelLocation = $data['hotel_location'];

    // Update the hotel
    $sql = "UPDATE Hotels SET hotel_name = :hotel_name, hotel_location = :hotel_location WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_name', $hotelName);
    $stmt->bindParam(':hotel_location', $hotelLocation);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->bindParam(':owner_id', $userID);

    if ($stmt->execute()) {
        echo json_encode(["message" => "Hotel updated successfully"]);
    } else {
        echo json_encode(["error" => "Error updating hotel"]);
    }
}

function deleteHotel($conn, $userID, $userType) {
    if (!isset($_GET['hotel_id'])) {
        echo json_encode(["error" => "Hotel ID is required"]);
        return;
    }

    $hotelId = $_GET['hotel_id'];

    // Only owners can delete hotels
    if ($userType !== 'owner') {
        echo json_encode(["error" => "Only owners can delete hotels"]);
        return;
    }

    // Delete the hotel
    $sql = "DELETE FROM Hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->bindParam(':owner_id', $userID);

    if ($stmt->execute()) {
        echo json_encode(["message" => "Hotel deleted successfully"]);
    } else {
        echo json_encode(["error" => "Error deleting hotel"]);
    }
}
?>
