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
    case 'insert':
        insertUserSubscription($request, $conn);
        break;
    case 'getSubscription':
        getSubscriptionByUserAndHotel($request, $conn);
        break;    
    default:
        echo json_encode(["error" => "Invalid action"]);
        http_response_code(400); // Bad Request
        break;
}

function insertUserSubscription($data, $conn) {
    try {
        // Get the necessary parameters from the request
        $HotelID = $data['HotelID'];
        $UserID = $data['UserID'];
        $SubID = $data['SubID'];
        $SubName = $data['SubName'];
        $IsActive = isset($data['IsActive']) ? $data['IsActive'] : 1;  // Default to 1 if IsActive is not provided

        // Prepare the SQL query
        $sql = "INSERT INTO usersubscription (HotelID, UserID, SubID, SubName, CreatedDate, UpdatedDate, IsActive)
                VALUES (:HotelID, :UserID, :SubID, :SubName, NOW(), NOW(), :IsActive)";

        // Prepare the statement
        $stmt = $conn->prepare($sql);

        // Bind the parameters
        $stmt->bindParam(':HotelID', $HotelID, PDO::PARAM_INT);
        $stmt->bindParam(':UserID', $UserID, PDO::PARAM_INT);
        $stmt->bindParam(':SubID', $SubID, PDO::PARAM_INT);
        $stmt->bindParam(':SubName', $SubName, PDO::PARAM_STR);
        $stmt->bindParam(':IsActive', $IsActive, PDO::PARAM_INT);

        // Execute the statement
        if ($stmt->execute()) {
            http_response_code(201); // Created
            echo json_encode(["message" => "Subscription added successfully"]);
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

function getSubscriptionByUserAndHotel($data, $conn) {
    try {
        // Get the necessary parameters from the request
        $HotelID = $data['HotelID'];
        $UserID = $data['UserID'];

        // Prepare the SQL query
        $sql = "SELECT * FROM usersubscription WHERE HotelID = :HotelID AND UserID = :UserID AND IsActive = 1";

        // Prepare the statement
        $stmt = $conn->prepare($sql);

        // Bind the parameters
        $stmt->bindParam(':HotelID', $HotelID, PDO::PARAM_INT);
        $stmt->bindParam(':UserID', $UserID, PDO::PARAM_INT);

        // Execute the statement
        $stmt->execute();

        // Fetch the result
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($subscriptions) {
            http_response_code(200); // OK
            echo json_encode(["subscriptions" => $subscriptions]);
        } else {
            http_response_code(404); // Not Found
            echo json_encode(["message" => "No subscriptions found for this User and Hotel"]);
        }
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}
?>
