<?php
// user.php
include '../db.php'; // database connection ($conn)
include '../validate.php'; // getJWTFromHeader() and validateJWT()

// adjust this path to where you saved helpers.php
require_once __DIR__ . '/../helpers/isMultiUser.php';

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

/* helper: read params from GET or JSON body (body takes precedence) */
function readParams() {
    $params = $_GET ?? [];
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (is_array($body)) {
        $params = array_merge($params, $body); // body overrides query string
    }
    return $params;
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

/* ---------------------------
   CREATE user
*/
function createUser($conn, $userID, $userType) {
    if ($userType !== 'owner') {
        http_response_code(403);
        echo json_encode(["error" => "Only owners can create users"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['hotel_id'], $data['user_name'], $data['user_role'], $data['user_phone_number'], $data['user_password'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $hotelId = (int)$data['hotel_id'];
    $userName = trim($data['user_name']);
    $userRole = trim($data['user_role']);
    $userPhoneNumber = trim($data['user_phone_number']);
    $userPassword = password_hash($data['user_password'], PASSWORD_BCRYPT);
    $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

    // Verify hotel ownership
    $sql = "SELECT hotel_id FROM hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $stmt->bindValue(':owner_id', $userID, PDO::PARAM_INT);
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Hotel not found or you don't have access to this hotel"]);
        return;
    }

    // Check phone uniqueness
    $sql = "SELECT user_id FROM users WHERE user_phone_number = :user_phone_number";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_phone_number', $userPhoneNumber, PDO::PARAM_STR);
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        http_response_code(409);
        echo json_encode(["error" => "Phone number already in use by another user"]);
        return;
    }

    // Insert new user with is_active and timestamps
    $sql = "INSERT INTO users (hotel_id, user_name, user_role, user_phone_number, user_password, is_active, created_at, updated_at)
            VALUES (:hotel_id, :user_name, :user_role, :user_phone_number, :user_password, :is_active, NOW(), NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $stmt->bindValue(':user_name', $userName, PDO::PARAM_STR);
    $stmt->bindValue(':user_role', $userRole, PDO::PARAM_STR);
    $stmt->bindValue(':user_phone_number', $userPhoneNumber, PDO::PARAM_STR);
    $stmt->bindValue(':user_password', $userPassword, PDO::PARAM_STR);
    $stmt->bindValue(':is_active', $isActive, PDO::PARAM_INT);

    if ($stmt->execute()) {
        // Update hotel's is_multi_user flag
        if (function_exists('updateHotelIsMultiUser')) {
            updateHotelIsMultiUser($conn, $hotelId);
        }

        // Fetch updated hotel row and return it
        $hotelRow = function_exists('fetchHotelRow') ? fetchHotelRow($conn, $hotelId) : null;

        http_response_code(201); // Created
        echo json_encode([
            "message" => "User created successfully",
            "hotel" => $hotelRow
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error creating user"]);
    }
}

/* ---------------------------
   GET users
*/
function getUsers($conn, $userID, $userType) {
    $params = readParams();

    if (!isset($params['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "Hotel ID is required"]);
        return;
    }

    $hotelId = (int)$params['hotel_id'];

    // Verify hotel belongs to owner (owner required)
    $sql = "SELECT hotel_id FROM hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $stmt->bindValue(':owner_id', $userID, PDO::PARAM_INT);
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Hotel not found or doesn't belong to the owner"]);
        return;
    }

    $sql = "SELECT * FROM users WHERE hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode(["users" => $users]);
}

/* ---------------------------
   UPDATE user (supports optional password & is_active)
*/
function updateUser($conn, $userID, $userType) {
    if ($userType !== 'owner') {
        http_response_code(403);
        echo json_encode(["error" => "Only owners can update users"]);
        return;
    }

    $params = json_decode(file_get_contents('php://input'), true);

    if (!isset($params['user_id'], $params['hotel_id'], $params['user_name'], $params['user_role'], $params['user_phone_number'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $userId = (int)$params['user_id'];
    $hotelId = (int)$params['hotel_id'];
    $userName = trim($params['user_name']);
    $userRole = trim($params['user_role']);
    $userPhoneNumber = trim($params['user_phone_number']);
    $isActive = isset($params['is_active']) ? (int)$params['is_active'] : null;
    $newPasswordRaw = isset($params['user_password']) ? $params['user_password'] : null;

    // Verify hotel ownership
    $sql = "SELECT hotel_id FROM hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $stmt->bindValue(':owner_id', $userID, PDO::PARAM_INT);
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Hotel not found or you don't have access to this hotel"]);
        return;
    }

    // Check user exists and belongs to this hotel
    $sql = "SELECT user_id, user_phone_number FROM users WHERE user_id = :user_id AND hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "User not found"]);
        return;
    }
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

    // If phone changed, ensure uniqueness among other users
    if ($existingUser['user_phone_number'] !== $userPhoneNumber) {
        $sql = "SELECT user_id FROM users WHERE user_phone_number = :user_phone_number AND user_id != :user_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':user_phone_number', $userPhoneNumber, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            http_response_code(409);
            echo json_encode(["error" => "Phone number already in use by another user"]);
            return;
        }
    }

    // Build update SQL dynamically (include is_active and password if provided)
    $updateFields = "user_name = :user_name, user_role = :user_role, user_phone_number = :user_phone_number";
    if ($isActive !== null) $updateFields .= ", is_active = :is_active";
    if (!empty($newPasswordRaw)) $updateFields .= ", user_password = :user_password";

    $sql = "UPDATE users SET {$updateFields}, updated_at = NOW() WHERE user_id = :user_id AND hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_name', $userName, PDO::PARAM_STR);
    $stmt->bindValue(':user_role', $userRole, PDO::PARAM_STR);
    $stmt->bindValue(':user_phone_number', $userPhoneNumber, PDO::PARAM_STR);
    if ($isActive !== null) $stmt->bindValue(':is_active', $isActive, PDO::PARAM_INT);
    if (!empty($newPasswordRaw)) {
        $hashed = password_hash($newPasswordRaw, PASSWORD_BCRYPT);
        $stmt->bindValue(':user_password', $hashed, PDO::PARAM_STR);
    }
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);

    if ($stmt->execute()) {
        // Update hotel's is_multi_user flag after change
        if (function_exists('updateHotelIsMultiUser')) {
            updateHotelIsMultiUser($conn, $hotelId);
        }

        // fetch and return updated hotel
        $hotelRow = function_exists('fetchHotelRow') ? fetchHotelRow($conn, $hotelId) : null;

        http_response_code(200);
        echo json_encode([
            "message" => "User updated successfully",
            "hotel" => $hotelRow
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error updating user"]);
    }
}

/* ---------------------------
   DELETE user
*/
function deleteUser($conn, $userID, $userType) {
    if ($userType !== 'owner') {
        http_response_code(403);
        echo json_encode(["error" => "Only owners can delete users"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['user_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "User ID is required"]);
        return;
    }

    $userId = (int)$data['user_id'];

    // Ensure user exists and fetch hotel_id
    $sql = "SELECT hotel_id FROM users WHERE user_id = :user_id LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "User not found"]);
        return;
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $hotelId = (int)$row['hotel_id'];

    // Verify owner owns the hotel
    $check = $conn->prepare("SELECT owner_id FROM hotels WHERE hotel_id = :hotel_id LIMIT 1");
    $check->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $check->execute();
    $hr = $check->fetch(PDO::FETCH_ASSOC);
    if (!$hr || (int)$hr['owner_id'] !== (int)$userID) {
        http_response_code(403);
        echo json_encode(["error" => "You don't have permission to delete this user"]);
        return;
    }

    // Delete user
    $sql = "DELETE FROM users WHERE user_id = :user_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);

    if ($stmt->execute()) {
        // Update hotel's is_multi_user flag after deletion
        if (function_exists('updateHotelIsMultiUser')) {
            updateHotelIsMultiUser($conn, $hotelId);
        }

        // fetch and return updated hotel
        $hotelRow = function_exists('fetchHotelRow') ? fetchHotelRow($conn, $hotelId) : null;

        http_response_code(200);
        echo json_encode([
            "message" => "User deleted successfully",
            "hotel" => $hotelRow
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error deleting user"]);
    }
}
?>
