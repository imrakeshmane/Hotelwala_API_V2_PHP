<?php

// Function to get JWT from the header
function getJWTFromHeader() {
    // Check if 'Authorization' header is set in $_SERVER
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];

        // Log the Authorization header for debugging
        error_log("Authorization Header: " . $authHeader);

        // Extract the token from the "Bearer" prefix
        if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return $matches[1]; // Return the token
        }
    }
    
    // Try to use getallheaders() as a fallback if needed
    // For environments where $_SERVER['HTTP_AUTHORIZATION'] doesn't work (e.g., some setups)
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['authorization'])) {
            $authHeader = $headers['authorization'];

            // Log the Authorization header for debugging
            error_log("Authorization Header (getallheaders): " . $authHeader);

            // Extract the token from the "Bearer" prefix
            if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
                return $matches[1]; // Return the token
            }
        }
    }

    return null; // No token found
}
// Function to validate JWT
function validateJWT($jwt, $secretKey) {
    // Split the token into three parts
    $segments = explode('.', $jwt);
    
    if (count($segments) !== 3) {
        return ["error" => "Invalid token format"];
    }

    // Decode the header and payload from Base64 URL encoding
    list($headerEncoded, $payloadEncoded, $signatureEncoded) = $segments;
    $header = json_decode(base64_decode(strtr($headerEncoded, '-_', '+/')), true);
    $payload = json_decode(base64_decode(strtr($payloadEncoded, '-_', '+/')), true);

    // Verify if the token is expired
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return ["error" => "Token has expired"];
    }

    // Reconstruct the signature
    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $secretKey, true);
    $signatureCheck = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    // Verify if the signature matches
    if ($signatureEncoded === $signatureCheck) {
        return $payload; // Return the payload if the signature is valid
    } else {
        return ["error" => "Invalid token"];
    }
}


// Example Usage:

// Get JWT token from the Authorization header
$jwt = getJWTFromHeader();

// Check if the token is provided
if ($jwt === null) {
    http_response_code(401); // Unauthorized
    echo json_encode(["error" => "Authorization token is required"]);
    return;
}

// Secret key used to generate and validate the token (should be the same as the one used to generate it)
$secretKey = "HotelWalaApp"; // Replace this with your actual secret key

// Validate the JWT using the provided secret key
$payload = validateJWT($jwt, $secretKey);

// If the payload contains an error (invalid token), return the error response
if (isset($payload['error'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(["error" => $payload['error']]);
    return;
}

// If the token is valid, proceed with your logic (e.g., accessing user data, etc.)
// echo json_encode(["message" => "Token is valid", "userID" => $payload['userID']]);


?>