<?php
include '../db.php'; // Make sure the path is correct

// Check if the connection is set
if (isset($conn)) {
    // echo "Connection variable is set.<br />";
    
} else {
    echo json_encode(["error" => "Database connection issue"]);
    return;
}



header('Content-Type: application/json');
$request = json_decode(file_get_contents('php://input'), true);

$action = $request['action'];

switch($action) {
    case 'register':
        insertUser($request, $conn);
        break;
    case 'update':
        updateUser($request, $conn);
        break;
    case 'delete':
        deleteUser($request, $conn);
        break;
    case 'login':
        loginUser($request, $conn);
        break;
    case 'forgotPassword':
        forgotPassword($request, $conn);
        break;    
    default:
        echo json_encode(["error" => "Invalid action"]);
}

function insertUser($data, $conn) {
    $name = $data['name'];
    $mobile = $data['mobile'];
    $email = $data['email'];
    $password = password_hash($data['password'], PASSWORD_BCRYPT);
    $hotelID =0;
    $deviceToken = $data['deviceToken'];
    $userType = 1;
    $active =1;

    // Step 1: Check if the mobile number already exists in the database
    $checkSql = "SELECT Mobile FROM userlist WHERE Mobile = :mobile";
    $stmt = $conn->prepare($checkSql);
    $stmt->bindParam(':mobile', $mobile, PDO::PARAM_STR);

    try {
        // Execute the check query
        $stmt->execute();

        // Check if any rows are returned (mobile number exists)
        if ($stmt->rowCount() > 0) {
            // Mobile number already exists
            http_response_code(400); // Bad Request
            echo json_encode(["error" => "Mobile number is already registered"]);
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
            loginUser($data,$conn);

            // echo json_encode(["message" => "User created successfully","userId"=>$insertedID,"Data"=>$newData]);
        } else {
            // Failure: Something went wrong
            $errorInfo = $stmt->errorInfo();
            http_response_code(500); // Internal Server Error
            echo json_encode(["error" => "Error: " . $errorInfo[2]]);
        }
    } catch (PDOException $e) {
        // Catch any exceptions and return an error
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Database error: " . $e->getMessage()]);
    }
}


function updateUser($data, $conn) {
    $userID = $data['userID'];
    $name = $data['name'];
    $mobile = $data['mobile'];
    $password = password_hash($data['password'], PASSWORD_BCRYPT);
    $hotelID = $data['hotelId'];
    $deviceToken = $data['deviceToken'];
    $userType = $data['userType'];
    $active = $data['active'];

    $sql = "UPDATE userlist SET 
            Name='$name', 
            Mobile='$mobile', 
            Password='$password', 
            HotelID='$hotelID', 
            DeviceToken='$deviceToken', 
            UserType='$userType', 
            Active='$active', 
            UpdatedDate=NOW() 
            WHERE UserID='$userID'";

    if ($conn->query($sql) === TRUE) {
        echo json_encode(["message" => "User updated successfully"]);
    } else {
        echo json_encode(["error" => "Error: " . $sql . "<br>" . $conn->error]);
    }
}

function deleteUser($data, $conn) {
    $userID = $data['userID'];
    $sql = "DELETE FROM userlist WHERE UserID='$userID'";
    if ($conn->query($sql) === TRUE) {
        echo json_encode(["message" => "User deleted successfully"]);
    } else {
        echo json_encode(["error" => "Error: " . $sql . "<br>" . $conn->error]);
    }
}

function loginUser($data, $conn) {
    $mobile = $data['mobile'];
    $password = $data['password'];
    // Prepare the SQL query
    $sql = "SELECT * FROM userlist WHERE Mobile=:mobile";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':mobile', $mobile, PDO::PARAM_STR);
    $stmt->execute();

    // Check if any user is found
    if ($stmt->rowCount() > 0) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // Verify the password
        if (password_verify($password, $row['Password'])) {
            // Successful login
            http_response_code(200); // OK

            $userID = $row['UserID'];
            $userType = $row['UserType'];
            $id=0;
            if ($userType == 1) {
                $id = $userID;
               
            } else {
                $id = $row['HotelID'];
            }
            $hotels = getHotelsByUser($id, $conn, $userType);
            $setting = getSettingsByUser($id, $conn, $userType);
            $token = generateJWT($userID, $userType);
            echo json_encode([
                "message" => "User Get Successfully", 
                "token" =>$token,
                "user" => [$row],
                "Hotels" => $hotels,
                "Setting" => $setting
            ]);
        } else {
            // Invalid password
            http_response_code(401); // Unauthorized
            echo json_encode(["error" => "Invalid password"]);
        }
    } else {
        // No user found
        http_response_code(404); // Not Found
        echo json_encode(["error" => "No user found with this mobile"]);
    }
}

// Helper function to get hotels by user ID or hotel ID, based on user type
function getHotelsByUser($id, $conn, $userType) {
   
    // Define the SQL queries for both conditions
    $sqlByUser = "SELECT * FROM hotels WHERE UserID = :id";
    $sqlByHotel = "SELECT * FROM hotels WHERE HotelID = :id";

    // Select the appropriate query based on userType
    if ($userType == 1) {
        $sql = $sqlByUser;
       
    } else {
        $sql = $sqlByHotel;
    }

    // Prepare the query
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);

    try {
        // Execute the query
        $stmt->execute();

        // Check if any hotels are found
        if ($stmt->rowCount() > 0) {
            // Return all the matching hotels
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Return an empty array if no hotels are found
            return [];
        }
    } catch (PDOException $e) {
        // Handle database errors
        return ["error" => "Database error: " . $e->getMessage()];
    }
}
// Helper function to get settings by UserID or HotelID, based on user type or other condition
function getSettingsByUser($id, $conn, $userType) {
    // Define the SQL queries for both conditions
    $sqlByUser = "SELECT * FROM settings WHERE UserID = :id";
    $sqlByHotel = "SELECT * FROM settings WHERE HotelID = :id";
    // Select the appropriate query based on userType (or any other condition)
    if ($userType == 1) {
        $sql = $sqlByUser;
    } else {
        $sql = $sqlByHotel;
    }

    // Prepare the query
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);

    try {
        // Execute the query
        $stmt->execute();

        // Check if any settings are found
        if ($stmt->rowCount() > 0) {
            // Return all the matching settings
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Return an empty array if no settings are found
            return [];
        }
    } catch (PDOException $e) {
        // Handle database errors
        return ["error" => "Database error: " . $e->getMessage()];
    }
}

// Simple JWT generation function
// Function to generate JWT
function generateJWT($userID, $userType) {
    $key = $GLOBALS['secretKey']; // Secret key for signing
    $issuedAt = time();
    $expirationTime = $issuedAt + 360000;  // JWT valid for 1 hour from the issued time

    // Create the payload
    $payload = [
        "iat" => $issuedAt,
        "exp" => $expirationTime,
        "userID" => $userID,
        "userType" => $userType
    ];

    // Create the header
    $header = json_encode(["alg" => "HS256", "typ" => "JWT"]);

    // Base64 URL encode the header and payload
    $headerEncoded = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
    $payloadEncoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

    // Create the signature
    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $key, true);
    $signatureEncoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    // Combine all parts to form the JWT
    $jwt = "$headerEncoded.$payloadEncoded.$signatureEncoded";

    return $jwt;
}
function forgotPassword($data, $conn) {
    try {
        // Extract email from input
        $email = $data['email'];

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400); // Bad Request
            echo json_encode(["error" => "Invalid email address"]);
            return;
        }

        // Check if the email exists in the database
        $query = "SELECT UserID, Name FROM userlist WHERE Email = :email";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            http_response_code(404); // Not Found
            echo json_encode(["error" => "Email not registered"]);
            return;
        }

        // Fetch user details
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $userID = $user['UserID'];
        $userName = $user['Name'];

        // Generate a new random password
        $newPassword = bin2hex(random_bytes(4)); // 8-character random password
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

        // Update the password in the database
        $updateQuery = "UPDATE userlist SET Password = :password WHERE UserID = :userID";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bindParam(':password', $hashedPassword, PDO::PARAM_STR);
        $updateStmt->bindParam(':userID', $userID, PDO::PARAM_INT);

        if ($updateStmt->execute()) {
            // Prepare email content
            $subject = "Password Reset Request";
            $message = "Hi $userName,\n\nYour password has been reset. Here is your new password:\n\n$newPassword\n\nPlease log in and change it as soon as possible.\n\nThanks,\nYour Team";
            $headers = "From: worldtechwala@gmail.com";

            // Send the email
            if (mail($email, $subject, $message, $headers)) {
                echo json_encode(["message" => "New password sent to your email"]);
                http_response_code(200); // OK
            } else {
                echo json_encode(["error" => "Failed to send email. Please check SMTP configuration."]);
                http_response_code(500); // Internal Server Error
            }
        } else {
            echo json_encode(["error" => "Failed to update password"]);
            http_response_code(500); // Internal Server Error
        }
    } catch (Exception $e) {
        // Handle errors
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}


// $conn->close();
?>
