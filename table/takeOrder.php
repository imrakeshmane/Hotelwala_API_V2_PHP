<?php
include '../db.php';
include '../validate.php';

header('Content-Type: application/json');
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Get JWT token from Authorization header
$jwt = getJWTFromHeader();
if ($jwt === null) {
  http_response_code(401); // Unauthorized
  echo json_encode(["error" => "Token is missing or invalid"]);
  return;
}

// Validate the JWT token
$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
  http_response_code(401); // Unauthorized
  echo json_encode(["error" => "Invalid token"]);
  return;
}

$userID = $payload['owner_id'];
$userType = $payload['user_type'];

if ($requestMethod === 'POST') {
  takeOrder($conn, $userID, $userType);
} else {
  http_response_code(405); // Method Not Allowed
  echo json_encode(["error" => "Method not allowed"]);
}

function takeOrder($conn, $userID, $userType)
{
  if ($userType !== 'owner' && $userType !== 'user') {
    http_response_code(403); // Forbidden
    echo json_encode(["error" => "Only owners or users can take orders"]);
    return;
  }

  $data = json_decode(file_get_contents('php://input'), true);

  if (
    !isset(
      $data['table_id'],
      $data['is_split'],
      $data['total_cost'],
      $data['taken_by_id'],
      $data['taken_by_role']
    )
  ) {
    http_response_code(400); // Bad Request
    echo json_encode(["error" => "Missing required fields"]);
    return;
  }


  $tableId = $data['table_id'];
  $isSplit = $data['is_split'];
  $totalCost = $data['total_cost'];
  $takenById = $data['taken_by_id'];
  $takenByRole = $data['taken_by_role'];

    // Desired table status when an order is taken
  $occupiedStatus = 'occupied'; // <-- sets status to "occupied"

  // Check if the table exists
  $sql = "SELECT * FROM tables WHERE table_id = :table_id";
  $stmt = $conn->prepare($sql);
  $stmt->bindParam(':table_id', $tableId);
  $stmt->execute();
  $table = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$table) {
    http_response_code(404); // Not Found
    echo json_encode(["error" => "Table not found"]);
    return;
  }

  // Prepare order data
  if ($isSplit) {
    if (!isset($data['split_order_data']) || !is_array($data['split_order_data'])) {
      http_response_code(400); // Bad Request
      echo json_encode(["error" => "Missing or invalid split_order_data"]);
      return;
    }
    $splitOrderData = json_encode($data['split_order_data']);
    $sql = "UPDATE tables SET is_split = :is_split, 
                    split_order_data = :order_data,
                    order_data = NULL, -- Clear order_data for split orders
                    total_cost = :total_cost, 
                      table_status = :table_status,
                    taken_by_id = :taken_by_id, 
                    taken_by_role = :taken_by_role 
                    WHERE table_id = :table_id";
  } else {
    if (!isset($data['order_data']) || !is_array($data['order_data'])) {
      http_response_code(400); // Bad Request
      echo json_encode(["error" => "Missing or invalid order_data"]);
      return;
    }
    $orderData = json_encode($data['order_data']);
    $sql = "UPDATE tables SET is_split = :is_split, 
                    order_data = :order_data, 
                    split_order_data = NULL, -- Clear split_order_data for normal orders
                    total_cost = :total_cost,
                      table_status = :table_status, 
                    taken_by_id = :taken_by_id, 
                    taken_by_role = :taken_by_role 
                    WHERE table_id = :table_id";
  }

  // Execute SQL
  $stmt = $conn->prepare($sql);
  $stmt->bindParam(':is_split', $isSplit, PDO::PARAM_BOOL);
  if ($isSplit) {
    $stmt->bindParam(':order_data', $splitOrderData);
  } else {
    $stmt->bindParam(':order_data', $orderData);
  }
  $stmt->bindParam(':total_cost', $totalCost);
      $stmt->bindValue(':table_status', $occupiedStatus, PDO::PARAM_STR);
  $stmt->bindParam(':taken_by_id', $takenById);
  $stmt->bindParam(':taken_by_role', $takenByRole);
  $stmt->bindParam(':table_id', $tableId);

  if ($stmt->execute()) {
    http_response_code(200); // OK
    echo json_encode(["message" => "Order data updated successfully"]);
  } else {
    http_response_code(500); // Internal Server Error
    echo json_encode(["error" => "Error updating order data"]);
  }
}
