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
        insertUserForPertucalHotel($request, $conn);
        break;
    case 'update':
        updateUserForPertucalHotel($request, $conn);
        break;
    case 'get':
        getAllUserForHotel($request, $conn);
        break;
    case 'delete':
        deleteUserForPertucalHotel($request, $conn);
        break;
    default:
        echo json_encode(["error" => "Invalid action"]);
        http_response_code(400); // Bad Request
        break;
}



function insertUserForPertucalHotel($data, $conn) {
    $name = $data['name'];
    $mobile = $data['mobile'];
    $email = $data['email'];
    $password = password_hash($data['password'], PASSWORD_BCRYPT);
    $hotelID =$data['hotelID'];;
    $deviceToken = $data['deviceToken'];
    $userType = $data['userType'];;
    $active =1;

    // Step 1: Check if the mobile number already exists in the database
    $checkSql = "SELECT Mobile FROM userlist WHERE Mobile = :mobile AND Email=:email";
    $stmt = $conn->prepare($checkSql);
    $stmt->bindParam(':mobile', $mobile, PDO::PARAM_STR);
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);

    try {
        // Execute the check query
        $stmt->execute();

        // Check if any rows are returned (mobile number exists)
        if ($stmt->rowCount() > 0) {
            // Mobile number already exists
            http_response_code(400); // Bad Request
            echo json_encode(["error" => "Mobile number Or Email is already registered"]);
            return; // Stop further execution
        }

        // Step 2: Proceed with insertion if mobile number is unique
                $sql = "INSERT INTO userlist (Name, Mobile,Email, Password, HotelID, DeviceToken, UserType, Active, CreatedDate, UpdatedDate) 
                VALUES (:name, :mobile,:email, :password, :hotelID, :deviceToken, :userType, :active, NOW(), NOW())";

                $stmt = $conn->prepare($sql);

                // Bind parameters to prevent SQL injection
                    $stmt->bindParam(':name', $name, PDO::PARAM_STR);
                    $stmt->bindParam(':mobile', $mobile, PDO::PARAM_STR);
                    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
                    $stmt->bindParam(':password', $password, PDO::PARAM_STR);
                    $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
                    $stmt->bindParam(':deviceToken', $deviceToken, PDO::PARAM_STR);
                    $stmt->bindParam(':userType', $userType, PDO::PARAM_STR);
                    $stmt->bindParam(':active', $active, PDO::PARAM_INT);

                // Execute the query to insert the user
                if ($stmt->execute()) {
                    $insertedID = $conn->lastInsertId();

                    // Success: User is created
                    http_response_code(201); // Created
                    echo json_encode(["message" => "User Added successfully"]);
                    return;
                    // loginUser($data,$conn);

                    // echo json_encode(["message" => "User created successfully","userId"=>$insertedID,"Data"=>$newData]);
                } else {
                    // Failure: Something went wrong
                    $errorInfo = $stmt->errorInfo();
                    http_response_code(500); // Internal Server Error
                    echo json_encode(["error" => "Error: " . $errorInfo[2]]);
                    return;
                }
    } catch (PDOException $e) {
        // Catch any exceptions and return an error
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Database error: " . $e->getMessage()]);
    }
}


function updateUserForPertucalHotel($data, $conn) {
    $userID = $data['userID'];
    $name = $data['name'];
    $mobile = $data['mobile'];
    $email = $data['email'];
    $userType = $data['userType'];


    // Step 1: Check if the mobile number or email already exists for another user
    $checkSql = "SELECT UserID FROM userlist WHERE (Mobile = :mobile OR Email = :email) AND UserID != :userID";
    $stmt = $conn->prepare($checkSql);
    $stmt->bindParam(':mobile', $mobile, PDO::PARAM_STR);
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);

    try {
        // Execute the check query
        $stmt->execute();

        // Check if any rows are returned (mobile number or email exists for another user)
        if ($stmt->rowCount() > 0) {
            // Mobile number or email already exists
            http_response_code(400); // Bad Request
            echo json_encode(["error" => "Mobile number or Email is already registered for another user"]);
            return; // Stop further execution
        }

        // Step 2: Proceed with the update if mobile number and email are unique
        $sql = "UPDATE userlist SET 
                    Name = :name, 
                    Mobile = :mobile, 
                    Email = :email, 
                    UserType = :userType, 
                    UpdatedDate = NOW()
                WHERE UserID = :userID";

        $stmt = $conn->prepare($sql);

        // Bind parameters to prevent SQL injection
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':mobile', $mobile, PDO::PARAM_STR);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->bindParam(':userType', $userType, PDO::PARAM_STR);
        $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);

        // Execute the query to update the user
        if ($stmt->execute()) {
            // Success: User is updated
            http_response_code(200); // OK
            echo json_encode(["message" => "User updated successfully"]);
            return;
        } else {
            // Failure: Something went wrong
            $errorInfo = $stmt->errorInfo();
            http_response_code(500); // Internal Server Error
            echo json_encode(["error" => "Error: " . $errorInfo[2]]);
            return;
        }
    } catch (PDOException $e) {
        // Catch any exceptions and return an error
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Database error: " . $e->getMessage()]);
    }
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

function deleteUserForPertucalHotel($data, $conn) {
    $userID = isset($data['userID']) ? $data['userID'] : null;

    // Validate the provided UserID
    if (empty($userID)) {
        http_response_code(400); // Bad Request
        echo json_encode(["error" => "Invalid UserID provided"]);
        return;
    }

    // Step 1: Check if the user exists
    $checkSql = "SELECT UserID FROM userlist WHERE UserID = :userID";
    $stmt = $conn->prepare($checkSql);
    $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);

    try {
        // Execute the check query
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            // User does not exist
            http_response_code(404); // Not Found
            echo json_encode(["error" => "User not found"]);
            return;
        }

        // Step 2: Proceed with deletion
        $deleteSql = "DELETE FROM userlist WHERE UserID = :userID";
        $stmt = $conn->prepare($deleteSql);
        $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);

        if ($stmt->execute()) {
            // Success: User is deleted
            http_response_code(200); // OK
            echo json_encode(["message" => "User deleted successfully"]);
            return;
        } else {
            // Failure: Something went wrong
            $errorInfo = $stmt->errorInfo();
            http_response_code(500); // Internal Server Error
            echo json_encode(["error" => "Error: " . $errorInfo[2]]);
            return;
        }
    } catch (PDOException $e) {
        // Catch any exceptions and return an error
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Database error: " . $e->getMessage()]);
    }
}

?>
