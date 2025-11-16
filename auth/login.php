<?php
// File: login.php
include '../db.php'; // must provide $conn (PDO) and $secretKey

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$DEBUG = false; // set true on local dev only (do NOT enable in production)

function jsonResponse($success, $message, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

function logServerError($context, $ex) {
    $msg = "[" . date('Y-m-d H:i:s') . "] " . $context . " - " . $ex->getMessage();
    error_log($msg);
}

function generateJWT($payload) {
    if (!isset($GLOBALS['secretKey']) || empty($GLOBALS['secretKey'])) {
        throw new Exception('JWT secretKey not configured on server.');
    }
    $key = $GLOBALS['secretKey'];
    $header = json_encode(["alg" => "HS256", "typ" => "JWT"]);
    $payloadJson = json_encode($payload);

    $headerEncoded = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
    $payloadEncoded = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $key, true);
    $signatureEncoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    return "$headerEncoded.$payloadEncoded.$signatureEncoded";
}

// Read input
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonResponse(false, 'Invalid JSON payload. Provide a valid JSON body.', null, 400);
}

$phoneNumber = isset($input['phone_number']) ? trim($input['phone_number']) : '';
$password = isset($input['password']) ? $input['password'] : '';

// Validate
$errors = [];
if ($phoneNumber === '') $errors[] = 'Phone number is required.';
if ($password === '') $errors[] = 'Password is required.';

if (!empty($errors)) {
    jsonResponse(false, 'Validation failed.', ['errors' => $errors], 400);
}

// Normalize phone
$normalizedPhone = preg_replace('/\D+/', '', $phoneNumber);
if ($normalizedPhone === '') {
    jsonResponse(false, 'Phone number is invalid.', null, 400);
}

try {
    // Try owners first
    $sqlOwner = "SELECT * FROM owners WHERE owner_phone_number = :phone_number LIMIT 1";
    $stmt = $conn->prepare($sqlOwner);
    $stmt->bindParam(':phone_number', $normalizedPhone);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $owner = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!password_verify($password, $owner['owner_password'])) {
            jsonResponse(false, 'Invalid credentials. Please check your phone number and password.', null, 401);
        }

        // build JWT payload (1 hour)
        $payload = [
            "owner_id" => $owner['owner_id'],
            "owner_name" => $owner['owner_name'],
            "owner_email" => $owner['owner_email'],
            "user_type" => "owner",
            "iat" => time(),
            "exp" => time() +  15552000
        ];
        $jwt = generateJWT($payload);

        // Fetch hotels
        $sqlHotels = "SELECT * FROM hotels WHERE owner_id = :owner_id";
        $stmtHotels = $conn->prepare($sqlHotels);
        $stmtHotels->bindParam(':owner_id', $owner['owner_id']);
        $stmtHotels->execute();
        $hotels = $stmtHotels->fetchAll(PDO::FETCH_ASSOC);

        // For each hotel, fetch categories and tables (order table_number numeric)
        foreach ($hotels as &$hotel) {
            $sqlCategories = "SELECT * FROM categories WHERE hotel_id = :hotel_id";
            $stmtCategories = $conn->prepare($sqlCategories);
            $stmtCategories->bindParam(':hotel_id', $hotel['hotel_id']);
            $stmtCategories->execute();
            $categories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

            foreach ($categories as &$category) {
                $sqlTables = "SELECT * FROM tables WHERE category_id = :category_id ORDER BY CAST(table_number AS UNSIGNED) ASC";
                $stmtTables = $conn->prepare($sqlTables);
                $stmtTables->bindParam(':category_id', $category['category_id']);
                $stmtTables->execute();
                $category['tables'] = $stmtTables->fetchAll(PDO::FETCH_ASSOC);
            }
            $hotel['categories'] = $categories;
        }

        jsonResponse(true, 'Login successful.', [
            "user_type" => "owner",
            "owner_id" => $owner['owner_id'],
            "owner_name" => $owner['owner_name'],
            "owner_email" => $owner['owner_email'],
            "token" => $jwt,
            "hotels" => $hotels
        ], 200);
    }

    // If not owner, try users table
    $sqlUser = "SELECT * FROM users WHERE user_phone_number = :phone_number LIMIT 1";
    $stmt = $conn->prepare($sqlUser);
    $stmt->bindParam(':phone_number', $normalizedPhone);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!password_verify($password, $user['user_password'])) {
            jsonResponse(false, 'Invalid credentials. Please check your phone number and password.', null, 401);
        }

        $payload = [
            "user_id" => $user['user_id'],
            "user_name" => $user['user_name'],
            "user_role" => $user['user_role'],
            "user_type" => "user",
            "iat" => time(),
            "exp" => time() + 36000000000
        ];
        $jwt = generateJWT($payload);

        // Fetch user's hotel and its categories/tables
        $sqlHotel = "SELECT * FROM hotels WHERE hotel_id = :hotel_id LIMIT 1";
        $stmtHotel = $conn->prepare($sqlHotel);
        $stmtHotel->bindParam(':hotel_id', $user['hotel_id']);
        $stmtHotel->execute();
        $hotel = $stmtHotel->fetch(PDO::FETCH_ASSOC);

        if ($hotel) {
            $sqlCategories = "SELECT * FROM categories WHERE hotel_id = :hotel_id";
            $stmtCategories = $conn->prepare($sqlCategories);
            $stmtCategories->bindParam(':hotel_id', $hotel['hotel_id']);
            $stmtCategories->execute();
            $categories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

            foreach ($categories as &$category) {
                $sqlTables = "SELECT * FROM tables WHERE category_id = :category_id ORDER BY CAST(table_number AS UNSIGNED) ASC";
                $stmtTables = $conn->prepare($sqlTables);
                $stmtTables->bindParam(':category_id', $category['category_id']);
                $stmtTables->execute();
                $category['tables'] = $stmtTables->fetchAll(PDO::FETCH_ASSOC);
            }

            $hotel['categories'] = $categories;
        }

        jsonResponse(true, 'Login successful.', [
            "user_type" => "user",
            "user_id" => $user['user_id'],
            "user_name" => $user['user_name'],
            "user_role" => $user['user_role'],
            "token" => $jwt,
            "hotels" => $hotel ? $hotel : (object)[] // return object for consistency
        ], 200);
    }

    // No account found
    jsonResponse(false, 'No account found with that phone number. Please sign up first.', null, 404);

} catch (PDOException $ex) {
    logServerError('login.php - PDOException', $ex);
    $msg = $DEBUG ? $ex->getMessage() : 'Server error while processing login.';
    jsonResponse(false, $msg, null, 500);
} catch (Exception $ex) {
    logServerError('login.php - Exception', $ex);
    $msg = $DEBUG ? $ex->getMessage() : 'Unexpected server error.';
    jsonResponse(false, $msg, null, 500);
}
