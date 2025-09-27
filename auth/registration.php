<?php
// File: signup.php
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
    // Log to PHP error log. You may change to a file or logger as needed.
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

// Trim inputs
$ownerName = isset($input['owner_name']) ? trim($input['owner_name']) : '';
$ownerEmail = isset($input['owner_email']) ? trim($input['owner_email']) : '';
$ownerPhoneNumber = isset($input['owner_phone_number']) ? trim($input['owner_phone_number']) : '';
$ownerPasswordRaw = isset($input['owner_password']) ? $input['owner_password'] : '';

// Validate
$errors = [];

if ($ownerName === '') {
    $errors[] = 'Owner name is required.';
} elseif (mb_strlen($ownerName) > 100) {
    $errors[] = 'Owner name must not exceed 100 characters.';
}

if ($ownerEmail === '') {
    $errors[] = 'Owner email is required.';
} elseif (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Owner email is not a valid email address.';
} elseif (mb_strlen($ownerEmail) > 254) {
    $errors[] = 'Owner email is too long.';
}

// Normalize phone to digits only (change if you want to support +)
$normalizedPhone = preg_replace('/\D+/', '', $ownerPhoneNumber);
if ($normalizedPhone === '') {
    $errors[] = 'Owner phone number is required.';
} elseif (strlen($normalizedPhone) < 6 || strlen($normalizedPhone) > 15) {
    $errors[] = 'Owner phone number looks invalid (must be between 6 and 15 digits).';
}

if ($ownerPasswordRaw === '') {
    $errors[] = 'Password is required.';
} elseif (strlen($ownerPasswordRaw) < 8) {
    $errors[] = 'Password must be at least 8 characters long.';
}

// Return validation errors
if (!empty($errors)) {
    jsonResponse(false, 'Validation failed.', ['errors' => $errors], 400);
}

// Proceed to DB operations
try {
    // Use transactions for atomicity
    $conn->beginTransaction();

    // Check for existing email
    $emailLower = mb_strtolower($ownerEmail);
    $sqlEmail = "SELECT owner_id FROM owners WHERE LOWER(owner_email) = :owner_email LIMIT 1";
    $stmtEmail = $conn->prepare($sqlEmail);
    $stmtEmail->bindParam(':owner_email', $emailLower);
    $stmtEmail->execute();
    if ($stmtEmail->rowCount() > 0) {
        $conn->rollBack();
        jsonResponse(false, 'Email already registered. Try logging in or use a different email.', null, 409);
    }

    // Check for existing phone
    $sqlPhone = "SELECT owner_id FROM owners WHERE owner_phone_number = :owner_phone_number LIMIT 1";
    $stmtPhone = $conn->prepare($sqlPhone);
    $stmtPhone->bindParam(':owner_phone_number', $normalizedPhone);
    $stmtPhone->execute();
    if ($stmtPhone->rowCount() > 0) {
        $conn->rollBack();
        jsonResponse(false, 'Phone number already registered. Try logging in or use a different phone number.', null, 409);
    }

    // Insert owner
    $ownerPasswordHash = password_hash($ownerPasswordRaw, PASSWORD_BCRYPT);
    $insertSql = "INSERT INTO owners (owner_name, owner_email, owner_phone_number, owner_password, created_at, updated_at)
                  VALUES (:owner_name, :owner_email, :owner_phone_number, :owner_password, NOW(), NOW())";
    $stmtInsert = $conn->prepare($insertSql);
    $stmtInsert->bindParam(':owner_name', $ownerName);
    $stmtInsert->bindParam(':owner_email', $ownerEmail);
    $stmtInsert->bindParam(':owner_phone_number', $normalizedPhone);
    $stmtInsert->bindParam(':owner_password', $ownerPasswordHash);

    if (!$stmtInsert->execute()) {
        $conn->rollBack();
        jsonResponse(false, 'Failed to register owner. Please try again later.', null, 500);
    }

    $ownerId = $conn->lastInsertId();
    $conn->commit();

    // Generate JWT (1 hour)
    $payload = [
        'owner_id' => $ownerId,
        'owner_name' => $ownerName,
        'owner_email' => $ownerEmail,
        'user_type' => 'owner',
        'iat' => time(),
        'exp' => time() +  15552000
    ];
    $jwt = generateJWT($payload);

    jsonResponse(true, 'Owner registered successfully.', [
        'user_type' => 'owner',
        'owner_id' => $ownerId,
        'owner_name' => $ownerName,
        'owner_email' => $ownerEmail,
        'token' => $jwt,
        'hotels' => []
    ], 201);

} catch (PDOException $ex) {
    // Rollback if needed and log
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    logServerError('signup.php - PDOException', $ex);
    $msg = $DEBUG ? $ex->getMessage() : 'Server error while registering owner.';
    jsonResponse(false, $msg, null, 500);
} catch (Exception $ex) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    logServerError('signup.php - Exception', $ex);
    $msg = $DEBUG ? $ex->getMessage() : 'Unexpected server error.';
    jsonResponse(false, $msg, null, 500);
}
