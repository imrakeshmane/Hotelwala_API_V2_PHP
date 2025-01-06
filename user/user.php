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
    case 'POST': // Create a new user
        createUser($conn, $userID, $userType);
        break;
    case 'GET': // Read users
        getUsers($conn, $userID, $userType);
        break;
    case 'PUT': // Update an existing user
        updateUser($conn, $userID, $userType);
        break;
    case 'DELETE': // Delete a user
        deleteUser($conn, $userID, $userType);
        break;
    default:
        http_response_code(405); // Method not allowed
        echo json_encode(["error" => "Method not allowed"]);
        break;
}

function createUser($conn, $userID, $userType) {
    if ($userType !== 'owner') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners can create users"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['hotel_id'], $data['user_name'], $data['user_role'], $data['user_phone_number'], $data['user_password'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $hotelId = $data['hotel_id'];
    $userName = $data['user_name'];
    $userRole = $data['user_role'];
    $userPhoneNumber = $data['user_phone_number'];
    $userPassword = password_hash($data['user_password'], PASSWORD_BCRYPT);

    // Check if the hotel exists
    $sql = "SELECT hotel_id FROM Hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->bindParam(':owner_id', $userID);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "Hotel not found or you don't have access to this hotel"]);
        return;
    }

    // Check if the phone number already exists for another user
    $sql = "SELECT user_id FROM Users WHERE user_phone_number = :user_phone_number";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':user_phone_number', $userPhoneNumber);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        http_response_code(409); // Conflict
        echo json_encode(["error" => "Phone number already in use by another user"]);
        return;
    }

    // Insert the new user
    $sql = "INSERT INTO Users (hotel_id, user_name, user_role, user_phone_number, user_password) 
            VALUES (:hotel_id, :user_name, :user_role, :user_phone_number, :user_password)";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->bindParam(':user_name', $userName);
    $stmt->bindParam(':user_role', $userRole);
    $stmt->bindParam(':user_phone_number', $userPhoneNumber);
    $stmt->bindParam(':user_password', $userPassword);

    if ($stmt->execute()) {
        http_response_code(201); // Created
        echo json_encode(["message" => "User created successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error creating user"]);
    }
}



function getUsers($conn, $userID, $userType) {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['hotel_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Hotel ID is required"]);
        return;
    }

    $hotelId = $data['hotel_id'];

    // Check if hotel exists and belongs to the owner
    $sql = "SELECT * FROM Hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->bindParam(':owner_id', $userID);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "Hotel not found or doesn't belong to the owner"]);
        return;
    }

    // Fetch users for the given hotel
    $sql = "SELECT * FROM Users WHERE hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200); // OK
    echo json_encode(["users" => $users]);
}

function updateUser($conn, $userID, $userType) {
    if ($userType !== 'owner') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners can update users"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['user_id'], $data['hotel_id'], $data['user_name'], $data['user_role'], $data['user_phone_number'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $userId = $data['user_id'];
    $hotelId = $data['hotel_id'];
    $userName = $data['user_name'];
    $userRole = $data['user_role'];
    $userPhoneNumber = $data['user_phone_number'];

    // Check if the hotel exists and belongs to the owner
    $sql = "SELECT hotel_id FROM Hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->bindParam(':owner_id', $userID);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "Hotel not found or you don't have access to this hotel"]);
        return;
    }

    // Check if the user exists
    $sql = "SELECT user_id, user_phone_number FROM Users WHERE user_id = :user_id AND hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':user_id', $userId);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "User not found"]);
        return;
    }

    // Check if the phone number is being updated and is already in use by another user
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existingUser['user_phone_number'] !== $userPhoneNumber) {
        $sql = "SELECT user_id FROM Users WHERE user_phone_number = :user_phone_number";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':user_phone_number', $userPhoneNumber);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            http_response_code(409); // Conflict
            echo json_encode(["error" => "Phone number already in use by another user"]);
            return;
        }
    }

    // Update user details
    $sql = "UPDATE Users SET user_name = :user_name, user_role = :user_role, user_phone_number = :user_phone_number 
            WHERE user_id = :user_id AND hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':user_name', $userName);
    $stmt->bindParam(':user_role', $userRole);
    $stmt->bindParam(':user_phone_number', $userPhoneNumber);
    $stmt->bindParam(':user_id', $userId);
    $stmt->bindParam(':hotel_id', $hotelId);

    if ($stmt->execute()) {
        http_response_code(200); // OK
        echo json_encode(["message" => "User updated successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error updating user"]);
    }
}



function deleteUser($conn, $userID, $userType) {
    if ($userType !== 'owner') {
        http_response_code(403); // Forbidden
        echo json_encode(["error" => "Only owners can delete users"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['user_id'])) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "User ID is required"]);
        return;
    }

    $userId = $data['user_id'];

    // Check if user exists
    $sql = "SELECT * FROM Users WHERE user_id = :user_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':user_id', $userId);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404); // Not Found
        echo json_encode(["error" => "User not found"]);
        return;
    }

    // Delete user
    $sql = "DELETE FROM Users WHERE user_id = :user_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':user_id', $userId);

    if ($stmt->execute()) {
        http_response_code(200); // OK
        echo json_encode(["message" => "User deleted successfully"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error deleting user"]);
    }
}
?>
