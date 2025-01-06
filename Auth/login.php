<?php
include '../db.php'; // Include your database connection

header('Content-Type: application/json');
$requestMethod = $_SERVER['REQUEST_METHOD'];



// Function to generate JWT
function generateJWT($payload) {
    $key = $GLOBALS['secretKey']; // Secret key for signing
    $issuedAt = time();
    $expirationTime = $issuedAt + 360000;  // JWT valid for 1 hour from the issued time

    // Create the payload
    // $payload = [
    //     "iat" => $issuedAt,
    //     "exp" => $expirationTime,
    //     "userID" => $userID,
    //     "userType" => $userType
    // ];

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

if ($requestMethod == 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (!isset($data['phone_number'], $data['password'])) {
        echo json_encode(["error" => "Missing phone number or password"]);
        return;
    }

    $phoneNumber = $data['phone_number'];
    $password = $data['password'];

    // Check in Owners table
    $sqlOwner = "SELECT * FROM Owners WHERE owner_phone_number = :phone_number";
    $stmt = $conn->prepare($sqlOwner);
    $stmt->bindParam(':phone_number', $phoneNumber);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $owner = $stmt->fetch(PDO::FETCH_ASSOC);
        if (password_verify($password, $owner['owner_password'])) {
            // Generate JWT token
            $issuedAt = time();
            $expirationTime = $issuedAt + 360000;  // jwt valid for 1 hour from the issued time
            $payload = array(
                "owner_id" => $owner['owner_id'],
                "owner_name" => $owner['owner_name'],
                "owner_email" => $owner['owner_email'],
                "user_type" => "owner",
                "iat" => $issuedAt,
                "exp" => $expirationTime
            );

            // Create JWT Token
            // $jwt = JWT::encode($payload, $key);
            $jwt = generateJWT($payload);

            // Fetch hotels owned by the owner
            $sqlHotels = "SELECT * FROM Hotels WHERE owner_id = :owner_id";
            $stmtHotels = $conn->prepare($sqlHotels);
            $stmtHotels->bindParam(':owner_id', $owner['owner_id']);
            $stmtHotels->execute();

            // Fetch hotels data
            $hotels = [];
            if ($stmtHotels->rowCount() > 0) {
                $hotels = $stmtHotels->fetchAll(PDO::FETCH_ASSOC);

                // For each hotel, fetch categories and tables
                foreach ($hotels as &$hotel) {
                    // Fetch categories for the hotel
                    $sqlCategories = "SELECT * FROM Categories WHERE hotel_id = :hotel_id";
                    $stmtCategories = $conn->prepare($sqlCategories);
                    $stmtCategories->bindParam(':hotel_id', $hotel['hotel_id']);
                    $stmtCategories->execute();

                    // Fetch categories data
                    $categories = [];
                    if ($stmtCategories->rowCount() > 0) {
                        $categories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

                        // For each category, fetch tables
                        foreach ($categories as &$category) {
                            // Fetch tables for the category
                            $sqlTables = "SELECT * FROM Tables WHERE category_id = :category_id";
                            $stmtTables = $conn->prepare($sqlTables);
                            $stmtTables->bindParam(':category_id', $category['category_id']);
                            $stmtTables->execute();

                            // Fetch tables data
                            $tables = [];
                            if ($stmtTables->rowCount() > 0) {
                                $tables = $stmtTables->fetchAll(PDO::FETCH_ASSOC);
                            }

                            // Add tables to category
                            $category['tables'] = $tables;
                        }
                    }

                    // Add categories to hotel
                    $hotel['categories'] = $categories;
                }
            }

            // Response with owner data, JWT, and hotels with categories and tables
            echo json_encode([
                "message" => "Login successful",
                "user_type" => "owner",
                "owner_id" => $owner['owner_id'],
                "owner_name" => $owner['owner_name'],
                "owner_email" => $owner['owner_email'],
                "token" => $jwt,
                "hotels" => $hotels
            ]);
        } else {
            echo json_encode(["error" => "Invalid password"]);
        }
    } else {
        // Check in Users table if not found in Owners
        $sqlUser = "SELECT * FROM Users WHERE user_phone_number = :phone_number";
        $stmt = $conn->prepare($sqlUser);
        $stmt->bindParam(':phone_number', $phoneNumber);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (password_verify($password, $user['user_password'])) {
                // Generate JWT token for user
                $issuedAt = time();
                $expirationTime = $issuedAt + 3600;  // jwt valid for 1 hour from the issued time
                $payload = array(
                    "user_id" => $user['user_id'],
                    "user_name" => $user['user_name'],
                    "user_role" => $user['user_role'],
                    "user_type" => "user",
                    "iat" => $issuedAt,
                    "exp" => $expirationTime
                );

                // Create JWT Token for user
                $jwt = generateJWT($payload);

                // Fetch the hotel where the user works (single hotel)
                $sqlHotel = "SELECT * FROM Hotels WHERE hotel_id = :hotel_id";
                $stmtHotel = $conn->prepare($sqlHotel);
                $stmtHotel->bindParam(':hotel_id', $user['hotel_id']);
                $stmtHotel->execute();

                $hotel = null;
                if ($stmtHotel->rowCount() > 0) {
                    $hotel = $stmtHotel->fetch(PDO::FETCH_ASSOC);

                    // Fetch categories for the hotel
                    $sqlCategories = "SELECT * FROM Categories WHERE hotel_id = :hotel_id";
                    $stmtCategories = $conn->prepare($sqlCategories);
                    $stmtCategories->bindParam(':hotel_id', $hotel['hotel_id']);
                    $stmtCategories->execute();

                    // Fetch categories data
                    $categories = [];
                    if ($stmtCategories->rowCount() > 0) {
                        $categories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

                        // For each category, fetch tables
                        foreach ($categories as &$category) {
                            // Fetch tables for the category
                            $sqlTables = "SELECT * FROM Tables WHERE category_id = :category_id";
                            $stmtTables = $conn->prepare($sqlTables);
                            $stmtTables->bindParam(':category_id', $category['category_id']);
                            $stmtTables->execute();

                            // Fetch tables data
                            $tables = [];
                            if ($stmtTables->rowCount() > 0) {
                                $tables = $stmtTables->fetchAll(PDO::FETCH_ASSOC);
                            }

                            // Add tables to category
                            $category['tables'] = $tables;
                        }
                    }

                    // Add categories to hotel
                    $hotel['categories'] = $categories;
                }

                // Response with user data, JWT, and hotel data (including categories and tables)
                echo json_encode([
                    "message" => "Login successful",
                    "user_type" => "user",
                    "user_id" => $user['user_id'],
                    "user_name" => $user['user_name'],
                    "user_role" => $user['user_role'],
                    "hotel_id" => $user['hotel_id'],
                    "token" => $jwt,
                    "hotel" => $hotel ? $hotel : []
                ]);
            } else {
                echo json_encode(["error" => "Invalid password"]);
            }
        } else {
            echo json_encode(["error" => "No account found with that phone number"]);
        }
    }
}
?>
