<?php
include '../db.php'; // Include your database connection

header('Content-Type: application/json');
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Function to generate JWT
function generateJWT($payload)
{
    $key = $GLOBALS['secretKey'];
    $issuedAt = time();
    $expirationTime = $issuedAt + 3600; // JWT valid for 1 hour

    $header = json_encode(["alg" => "HS256", "typ" => "JWT"]);
    $headerEncoded = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
    $payloadEncoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $key, true);
    $signatureEncoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    return "$headerEncoded.$payloadEncoded.$signatureEncoded";
}

if ($requestMethod == 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (!isset($data['owner_name'], $data['owner_email'], $data['owner_phone_number'], $data['owner_password'])) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Missing required fields", "data" => null]);
        return;
    }

    $ownerName = $data['owner_name'];
    $ownerEmail = $data['owner_email'];
    $ownerPhoneNumber = $data['owner_phone_number'];
    $ownerPassword = password_hash($data['owner_password'], PASSWORD_BCRYPT);

    // Check if the email or phone number already exists
    $checkSql = "SELECT * FROM Owners WHERE owner_email = :owner_email OR owner_phone_number = :owner_phone_number";
    $stmt = $conn->prepare($checkSql);
    $stmt->bindParam(':owner_email', $ownerEmail);
    $stmt->bindParam(':owner_phone_number', $ownerPhoneNumber);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Email or phone number already exists", "data" => null]);
        return;
    }

    // Insert the new owner
    $sql = "INSERT INTO Owners (owner_name, owner_email, owner_phone_number, owner_password) 
            VALUES (:owner_name, :owner_email, :owner_phone_number, :owner_password)";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':owner_name', $ownerName);
    $stmt->bindParam(':owner_email', $ownerEmail);
    $stmt->bindParam(':owner_phone_number', $ownerPhoneNumber);
    $stmt->bindParam(':owner_password', $ownerPassword);

    if ($stmt->execute()) {
        $ownerId = $conn->lastInsertId(); // Get the inserted owner's ID

        // Generate JWT token
        $payload = [
            "owner_id" => $ownerId,
            "owner_name" => $ownerName,
            "owner_email" => $ownerEmail,
            "user_type" => "owner",
            "iat" => time(),
            "exp" => time() + 36000000000
        ];
        $jwt = generateJWT($payload);

        // Return the login response with an empty hotel structure
        echo json_encode([
            "success" => true,
            "message" => "Owner registered and logged in successfully",
            "data" => [
                "user_type" => "owner",
                "owner_id" => $ownerId,
                "owner_name" => $ownerName,
                "owner_email" => $ownerEmail,
                "token" => $jwt,
                "hotels" => [] // Empty list, prompt for hotel setup
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Error registering owner", "data" => null]);
    }
}
