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
        insertHotel($request, $conn);
        break;
    case 'update':
        updateHotel($request, $conn);
        break;
    case 'get':
        getHotel($request, $conn);
        break;
    case 'delete':
        deleteHotel($request, $conn);
        break;
    default:
        echo json_encode(["error" => "Invalid action"]);
        http_response_code(400); // Bad Request
        break;
}

function insertHotel($data, $conn) {
    try {
        $userID = $data['userID'];
        $name = $data['name'];
        $mobile = $data['mobile'];
        $address = $data['address'];
        $pincode = $data['pincode'];
        $active = $data['active'];

        $sql = "INSERT INTO hotels (UserID, Name, Mobile, Address, Pincode, Active, CreatedDate, UpdatedDate) 
                VALUES (:userID, :name, :mobile, :address, :pincode, :active, NOW(), NOW())";
        
        $stmt = $conn->prepare($sql);

        $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':mobile', $mobile, PDO::PARAM_STR);
        $stmt->bindParam(':address', $address, PDO::PARAM_STR);
        $stmt->bindParam(':pincode', $pincode, PDO::PARAM_STR);
        $stmt->bindParam(':active', $active, PDO::PARAM_INT);

        if ($stmt->execute()) {
            $insertedID = $conn->lastInsertId();

            // Insert into settings
            $query = "INSERT INTO settings (HotelID, UserID) VALUES (?, ?)";
            $stmt = $conn->prepare($query);
            $result = $stmt->execute([$insertedID, $userID]);

            if ($result) {
                http_response_code(201); // Created
                echo json_encode([
                    "message" => "Hotel added successfully.",
                    "HotelID" => $insertedID,
                ]);
            } else {
                http_response_code(500); // Internal Server Error
                echo json_encode(["error" => "Settings Error"]);
            }
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

function updateHotel($data, $conn) {
    try {
        $hotelID = $data['hotelID'];
        $userID = $data['userID'];
        $name = $data['name'];
        $mobile = $data['mobile'];
        $address = $data['address'];
        $pincode = $data['pincode'];
        $active = $data['active'];

        $sql = "UPDATE hotels SET 
                    UserID = :userID, 
                    Name = :name, 
                    Mobile = :mobile, 
                    Address = :address, 
                    Pincode = :pincode, 
                    Active = :active, 
                    UpdatedDate = NOW() 
                WHERE HotelID = :hotelID";
        
        $stmt = $conn->prepare($sql);

        $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':mobile', $mobile, PDO::PARAM_STR);
        $stmt->bindParam(':address', $address, PDO::PARAM_STR);
        $stmt->bindParam(':pincode', $pincode, PDO::PARAM_STR);
        $stmt->bindParam(':active', $active, PDO::PARAM_INT);

        if ($stmt->execute()) {
            http_response_code(200); // OK
            echo json_encode(["message" => "Hotel updated successfully"]);
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

function getHotel($data, $conn) {
    try {
        $hotelID = isset($data['hotelID']) ? $data['hotelID'] : null;
        $userID = isset($data['userID']) ? $data['userID'] : null;

        if ($hotelID && $userID) {
            $sql = "SELECT * FROM hotels WHERE HotelID = :hotelID AND UserID = :userID";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
            $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
        } elseif ($hotelID) {
            $sql = "SELECT * FROM hotels WHERE HotelID = :hotelID";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        } elseif ($userID) {
            $sql = "SELECT * FROM hotels WHERE UserID = :userID";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
        } else {
            $sql = "SELECT * FROM hotels";
            $stmt = $conn->prepare($sql);
        }

        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["hotels" => $hotels]);
        } else {
            echo json_encode(["message" => "No hotels found"]);
            http_response_code(404); // Not Found
        }
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
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
