<?php
include '../db.php'; // Include your database connection

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$requestMethod = $_SERVER['REQUEST_METHOD'];

// Function to generate JWT
function generateJWT($payload)
{
  $key = $GLOBALS['secretKey']; // Secret key for signing
  $issuedAt = time();
  $expirationTime = $issuedAt + 3600000;  // JWT valid for 1 hour

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
  if (!isset($data['phone_number'], $data['password'])) {
    echo json_encode([
      "success" => false,
      "message" => "Missing phone number or password",
      "data" => null
    ]);
    exit;
  }

  $phoneNumber = $data['phone_number'];
  $password = $data['password'];

  // Check in Owners table
  $sqlOwner = "SELECT * FROM owners WHERE owner_phone_number = :phone_number";
  $stmt = $conn->prepare($sqlOwner);
  $stmt->bindParam(':phone_number', $phoneNumber);
  $stmt->execute();

  if ($stmt->rowCount() > 0) {
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);
    if (password_verify($password, $owner['owner_password'])) {
      $payload = [
        "owner_id" => $owner['owner_id'],
        "owner_name" => $owner['owner_name'],
        "owner_email" => $owner['owner_email'],
        "user_type" => "owner",
        "iat" => time(),
        "exp" => time() + 3600000000
      ];
      $jwt = generateJWT($payload);

      // Fetch hotels owned by the owner
      $sqlHotels = "SELECT * FROM hotels WHERE owner_id = :owner_id";
      $stmtHotels = $conn->prepare($sqlHotels);
      $stmtHotels->bindParam(':owner_id', $owner['owner_id']);
      $stmtHotels->execute();
      $hotels = $stmtHotels->fetchAll(PDO::FETCH_ASSOC);

      // Populate hotel data with categories and tables
      foreach ($hotels as &$hotel) {
        $sqlCategories = "SELECT * FROM categories WHERE hotel_id = :hotel_id";
        $stmtCategories = $conn->prepare($sqlCategories);
        $stmtCategories->bindParam(':hotel_id', $hotel['hotel_id']);
        $stmtCategories->execute();
        $categories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

        foreach ($categories as &$category) {
          $sqlTables = "SELECT * FROM tables WHERE category_id = :category_id  ORDER BY CAST(table_number AS UNSIGNED) ASC";
          $stmtTables = $conn->prepare($sqlTables);
          $stmtTables->bindParam(':category_id', $category['category_id']);
          $stmtTables->execute();
          $category['tables'] = $stmtTables->fetchAll(PDO::FETCH_ASSOC);
        }

        $hotel['categories'] = $categories;
      }

      echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "data" => [
          "user_type" => "owner",
          "owner_id" => $owner['owner_id'],
          "owner_name" => $owner['owner_name'],
          "owner_email" => $owner['owner_email'],
          "token" => $jwt,
          "hotels" => $hotels
        ]
      ]);
    } else {
      echo json_encode([
        "success" => false,
        "message" => "Invalid password",
        "data" => null
      ]);
    }
    exit;
  }

  // Check in Users table
  $sqlUser = "SELECT * FROM users WHERE user_phone_number = :phone_number";
  $stmt = $conn->prepare($sqlUser);
  $stmt->bindParam(':phone_number', $phoneNumber);
  $stmt->execute();

  if ($stmt->rowCount() > 0) {
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (password_verify($password, $user['user_password'])) {
      $payload = [
        "user_id" => $user['user_id'],
        "user_name" => $user['user_name'],
        "user_role" => $user['user_role'],
        "user_type" => "user",
        "iat" => time(),
        "exp" => time() + 360000000
      ];
      $jwt = generateJWT($payload);

      // Fetch user's hotel
      $sqlHotel = "SELECT * FROM hotels WHERE hotel_id = :hotel_id";
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
          $sqlTables = "SELECT * FROM tables WHERE category_id = :category_id";
          $stmtTables = $conn->prepare($sqlTables);
          $stmtTables->bindParam(':category_id', $category['category_id']);
          $stmtTables->execute();
          $category['tables'] = $stmtTables->fetchAll(PDO::FETCH_ASSOC);
        }

        $hotel['categories'] = $categories;
      }

      echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "data" => [
          "user_type" => "user",
          "user_id" => $user['user_id'],
          "user_name" => $user['user_name'],
          "user_role" => $user['user_role'],
          "hotel" => $hotel ? $hotel : []
        ]
      ]);
    } else {
      echo json_encode([
        "success" => false,
        "message" => "Invalid password",
        "data" => null
      ]);
    }
  } else {
    echo json_encode([
      "success" => false,
      "message" => "No account found with that phone number",
      "data" => null
    ]);
  }
}
