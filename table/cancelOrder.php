<?php
include '../db.php';
include '../validate.php';

header('Content-Type: application/json');
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Get JWT token from Authorization header (reuse your helper)
$jwt = getJWTFromHeader();
if ($jwt === null) {
  http_response_code(401);
  echo json_encode(["error" => "Token is missing or invalid"]);
  return;
}

$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
  http_response_code(401);
  echo json_encode(["error" => "Invalid token"]);
  return;
}

$userID = $payload['owner_id'] ?? $payload['user_id'] ?? null;
$userType = $payload['user_type'] ?? null;

if ($requestMethod !== 'POST') {
  http_response_code(405);
  echo json_encode(["error" => "Method not allowed"]);
  return;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['table_id'], $data['is_split'])) {
  http_response_code(400);
  echo json_encode(["error" => "Missing required fields"]);
  return;
}

$tableId = $data['table_id'];
$isSplit = $data['is_split'] ? 1 : 0;
$splitToRemoveId = $data['split_order_id'] ?? null;

try {
  // fetch table row
  $sql = "SELECT * FROM `tables` WHERE table_id = :table_id";
  $stmt = $conn->prepare($sql);
  $stmt->bindParam(':table_id', $tableId);
  $stmt->execute();
  $table = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
  return;
}

if (!$table) {
  http_response_code(404);
  echo json_encode(["error" => "Table not found"]);
  return;
}

try {
  if (!$isSplit) {
    // NON-SPLIT: clear whole order and reset final_amount to NULL (default)
    $sql = "UPDATE `tables` SET
              is_split = 0,
              order_data = NULL,
              split_order_data = NULL,
              final_amount = NULL,
              table_status = 'available',
              taken_by_id = NULL,
              taken_by_role = NULL
            WHERE table_id = :table_id";
    $uStmt = $conn->prepare($sql);
    $uStmt->bindParam(':table_id', $tableId);
    if ($uStmt->execute()) {
      $r = $conn->prepare("SELECT * FROM `tables` WHERE table_id = :table_id");
      $r->bindParam(':table_id', $tableId);
      $r->execute();
      $updated = $r->fetch(PDO::FETCH_ASSOC);
      http_response_code(200);
      echo json_encode(["message" => "Order cancelled", "table" => $updated]);
      return;
    } else {
      $err = $uStmt->errorInfo();
      http_response_code(500);
      echo json_encode(["error" => "DB update failed", "db_error" => $err]);
      return;
    }
  } else {
    // SPLIT handling: parse existing split_order_data
    $raw = $table['split_order_data'] ?? null;
    $splitArr = [];
    if ($raw) {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) $splitArr = $decoded;
    }

    if ($splitToRemoveId !== null) {
      // Remove single split by split_order_id
      $filtered = array_values(array_filter($splitArr, function($s) use ($splitToRemoveId) {
        return !(isset($s['split_order_id']) && $s['split_order_id'] == $splitToRemoveId);
      }));

      // If there are no splits left after removal -> reset table to defaults (final_amount NULL)
      if (count($filtered) === 0) {
        $sql = "UPDATE `tables` SET
                  is_split = 0,
                  split_order_data = NULL,
                  order_data = NULL,
                  final_amount = NULL,
                  table_status = 'available',
                  taken_by_id = NULL,
                  taken_by_role = NULL
                WHERE table_id = :table_id";
        $uStmt = $conn->prepare($sql);
        $uStmt->bindParam(':table_id', $tableId);
        if ($uStmt->execute()) {
          $r = $conn->prepare("SELECT * FROM `tables` WHERE table_id = :table_id");
          $r->bindParam(':table_id', $tableId);
          $r->execute();
          $updated = $r->fetch(PDO::FETCH_ASSOC);
          http_response_code(200);
          echo json_encode(["message" => "Split removed — no splits remain; table reset", "table" => $updated]);
          return;
        } else {
          $err = $uStmt->errorInfo();
          http_response_code(500);
          echo json_encode(["error" => "DB update failed", "db_error" => $err]);
          return;
        }
      }

      // Otherwise recompute grand final_amount using remaining splits and keep them
      $grand = 0.0;
      foreach ($filtered as $sp) {
        $sub = 0.0;
        if (isset($sp['sub_total'])) {
          $sub = floatval($sp['sub_total']);
        } elseif (isset($sp['items']) && is_array($sp['items'])) {
          foreach ($sp['items'] as $it) {
            $price = isset($it['price']) ? floatval($it['price']) : (isset($it['menu_price']) ? floatval($it['menu_price']) : 0.0);
            $qty = isset($it['quantity']) ? floatval($it['quantity']) : 1.0;
            $sub += ($price * $qty);
          }
        }
        $cg = isset($sp['cgst_percentage']) ? floatval($sp['cgst_percentage']) : 0.0;
        $sg = isset($sp['sgst_percentage']) ? floatval($sp['sgst_percentage']) : 0.0;
        $cg_amt = round($sub * ($cg / 100.0), 2);
        $sg_amt = round($sub * ($sg / 100.0), 2);
        $final_amt = round($sub + $cg_amt + $sg_amt, 2);
        $grand += $final_amt;
      }
      $grand = round($grand, 2);
      $newSplitData = json_encode($filtered);
      $newIsSplit = 1;
      $newStatus = ($grand > 0) ? 'occupied' : 'available';

      $sql = "UPDATE `tables` SET
                split_order_data = :split_order_data,
                final_amount = :final_amount,
                is_split = :is_split,
                table_status = :table_status
              WHERE table_id = :table_id";
      $uStmt = $conn->prepare($sql);
      $uStmt->bindValue(':split_order_data', $newSplitData, PDO::PARAM_STR);
      $uStmt->bindValue(':final_amount', $grand); // numeric value
      $uStmt->bindValue(':is_split', $newIsSplit, PDO::PARAM_INT);
      $uStmt->bindValue(':table_status', $newStatus, PDO::PARAM_STR);
      $uStmt->bindValue(':table_id', $tableId);

      if ($uStmt->execute()) {
        $r = $conn->prepare("SELECT * FROM `tables` WHERE table_id = :table_id");
        $r->bindParam(':table_id', $tableId);
        $r->execute();
        $updated = $r->fetch(PDO::FETCH_ASSOC);
        http_response_code(200);
        echo json_encode(["message" => "Split removed and table updated", "table" => $updated]);
        return;
      } else {
        $err = $uStmt->errorInfo();
        http_response_code(500);
        echo json_encode(["error" => "DB update failed", "db_error" => $err]);
        return;
      }
    } else {
      // No split id: clear all splits (treat as full clear) -> final_amount NULL
      $sql = "UPDATE `tables` SET
                is_split = 0,
                split_order_data = NULL,
                order_data = NULL,
                final_amount = NULL,
                table_status = 'available',
                taken_by_id = NULL,
                taken_by_role = NULL
              WHERE table_id = :table_id";
      $uStmt = $conn->prepare($sql);
      $uStmt->bindParam(':table_id', $tableId);
      if ($uStmt->execute()) {
        $r = $conn->prepare("SELECT * FROM `tables` WHERE table_id = :table_id");
        $r->bindParam(':table_id', $tableId);
        $r->execute();
        $updated = $r->fetch(PDO::FETCH_ASSOC);
        http_response_code(200);
        echo json_encode(["message" => "All splits cleared", "table" => $updated]);
        return;
      } else {
        $err = $uStmt->errorInfo();
        http_response_code(500);
        echo json_encode(["error" => "DB update failed", "db_error" => $err]);
        return;
      }
    }
  }
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
  return;
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(["error" => "Server error", "message" => $e->getMessage()]);
  return;
}
