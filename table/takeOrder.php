<?php
include '../db.php';
include '../validate.php';

header('Content-Type: application/json');
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Get JWT token from Authorization header
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

// support tokens that may use owner_id or user_id
$userID = $payload['owner_id'] ?? $payload['user_id'] ?? null;
$userType = $payload['user_type'] ?? null;

if ($requestMethod === 'POST') {
  takeOrder($conn, $userID, $userType);
} else {
  http_response_code(405);
  echo json_encode(["error" => "Method not allowed"]);
  return;
}

function safe_float($v) {
  if (!isset($v)) return 0.0;
  if (!is_numeric($v)) return 0.0;
  return floatval($v);
}

/**
 * computeSubTotalFromItems - helper to compute subtotal when frontend sends items array
 * items can be array of { price, quantity } or { menu_price, quantity } etc.
 */
function computeSubTotalFromItems($items) {
  $sum = 0.0;
  if (!is_array($items)) return 0.0;
  foreach ($items as $it) {
    $qty = isset($it['quantity']) ? floatval($it['quantity']) : 1.0;
    // support multiple keys for price
    $price = 0.0;
    if (isset($it['price'])) $price = floatval($it['price']);
    elseif (isset($it['menu_price'])) $price = floatval($it['menu_price']);
    elseif (isset($it['menuItemPrice'])) $price = floatval($it['menuItemPrice']);
    $sum += ($qty * $price);
  }
  return round($sum, 2);
}

function takeOrder($conn, $userID, $userType)
{
  if ($userType !== 'owner' && $userType !== 'user') {
    http_response_code(403);
    echo json_encode(["error" => "Only owners or users can take orders"]);
    return;
  }

  $data = json_decode(file_get_contents('php://input'), true);

  if (!isset($data['table_id'], $data['is_split'], $data['taken_by_id'], $data['taken_by_role'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required fields"]);
    return;
  }

  $tableId = $data['table_id'];
  $isSplit = $data['is_split'] ? 1 : 0;
  $takenById = $data['taken_by_id'];
  $takenByRole = $data['taken_by_role'];
  $occupiedStatus = 'occupied';

  // Check table exists
  try {
    $sql = "SELECT * FROM tables WHERE table_id = :table_id";
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
    if ($isSplit) {
      // Validate split array
      if (!isset($data['split_order_data']) || !is_array($data['split_order_data'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing or invalid split_order_data"]);
        return;
      }

      $splitArr = $data['split_order_data'];
      $computedTotalFinal = 0.0;
      $normalizedSplits = [];

      foreach ($splitArr as $idx => $sp) {
        // determine sub_total: prefer provided sub_total, else compute from items/orders
        $sub_total = 0.0;
        if (isset($sp['sub_total'])) {
          $sub_total = safe_float($sp['sub_total']);
        } elseif (isset($sp['items']) && is_array($sp['items'])) {
          $sub_total = computeSubTotalFromItems($sp['items']);
        } elseif (isset($sp['orders']) && is_array($sp['orders'])) {
          // orders array with price/quantity
          $sub_total = computeSubTotalFromItems($sp['orders']);
        }

        $cg_pct = safe_float($sp['cgst_percentage'] ?? $sp['cgst_pct'] ?? 0);
        $sg_pct = safe_float($sp['sgst_percentage'] ?? $sp['sgst_pct'] ?? 0);

        $cg_amt = round($sub_total * ($cg_pct / 100.0), 2);
        $sg_amt = round($sub_total * ($sg_pct / 100.0), 2);
        $final_amt = round($sub_total + $cg_amt + $sg_amt, 2);

        $normalized = $sp; // keep existing structure where possible
        $normalized['sub_total'] = $sub_total;
        $normalized['cgst_percentage'] = $cg_pct;
        $normalized['sgst_percentage'] = $sg_pct;
        $normalized['cgst_amount'] = $cg_amt;
        $normalized['sgst_amount'] = $sg_amt;
        $normalized['final_amount'] = $final_amt;

        // also ensure items/orders remain present for later reference
        if (!isset($normalized['items']) && isset($sp['orders'])) {
          $normalized['items'] = $sp['orders'];
        }

        $normalizedSplits[] = $normalized;
        $computedTotalFinal += $final_amt;
      }

      $computedTotalFinal = round($computedTotalFinal, 2);
      $splitOrderData = json_encode($normalizedSplits);

      // Update tables row: write final_amount (grand total including GST)
      $sql = "UPDATE tables SET is_split = :is_split, 
                      split_order_data = :split_order_data,
                      order_data = NULL,
                      final_amount = :final_amount,
                      table_status = :table_status,
                      taken_by_id = :taken_by_id, 
                      taken_by_role = :taken_by_role 
                      WHERE table_id = :table_id";

      $uStmt = $conn->prepare($sql);
      $uStmt->bindValue(':is_split', $isSplit, PDO::PARAM_INT);
      $uStmt->bindValue(':split_order_data', $splitOrderData, PDO::PARAM_STR);
      $uStmt->bindValue(':final_amount', $computedTotalFinal); // final_amount column
      $uStmt->bindValue(':table_status', $occupiedStatus, PDO::PARAM_STR);
      $uStmt->bindValue(':taken_by_id', $takenById);
      $uStmt->bindValue(':taken_by_role', $takenByRole);
      $uStmt->bindValue(':table_id', $tableId);
      $stmt = $uStmt;

    } else {
      // Non-split branch
      if (!isset($data['order_data']) || !is_array($data['order_data'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing or invalid order_data"]);
        return;
      }

      $od = $data['order_data'];
      // compute sub_total fallback
      if (isset($od['sub_total'])) {
        $sub_total = safe_float($od['sub_total']);
      } elseif (isset($od['orders']) && is_array($od['orders'])) {
        $sub_total = computeSubTotalFromItems($od['orders']);
      } elseif (isset($od['items']) && is_array($od['items'])) {
        $sub_total = computeSubTotalFromItems($od['items']);
      } else {
        $sub_total = 0.0;
      }

      $cg_pct = safe_float($od['cgst_percentage'] ?? $od['cgst_pct'] ?? 0);
      $sg_pct = safe_float($od['sgst_percentage'] ?? $od['sgst_pct'] ?? 0);

      $cg_amt = round($sub_total * ($cg_pct / 100.0), 2);
      $sg_amt = round($sub_total * ($sg_pct / 100.0), 2);
      $final_amt = round($sub_total + $cg_amt + $sg_amt, 2);

      $od['sub_total'] = $sub_total;
      $od['cgst_percentage'] = $cg_pct;
      $od['sgst_percentage'] = $sg_pct;
      $od['cgst_amount'] = $cg_amt;
      $od['sgst_amount'] = $sg_amt;
      $od['final_amount'] = $final_amt;

      $orderData = json_encode($od);

      $sql = "UPDATE tables SET is_split = :is_split, 
                      order_data = :order_data, 
                      split_order_data = NULL,
                      final_amount = :final_amount,
                      table_status = :table_status, 
                      taken_by_id = :taken_by_id, 
                      taken_by_role = :taken_by_role 
                      WHERE table_id = :table_id";

      $uStmt = $conn->prepare($sql);
      $uStmt->bindValue(':is_split', $isSplit, PDO::PARAM_INT);
      $uStmt->bindValue(':order_data', $orderData, PDO::PARAM_STR);
      $uStmt->bindValue(':final_amount', $final_amt);
      $uStmt->bindValue(':table_status', $occupiedStatus, PDO::PARAM_STR);
      $uStmt->bindValue(':taken_by_id', $takenById);
      $uStmt->bindValue(':taken_by_role', $takenByRole);
      $uStmt->bindValue(':table_id', $tableId);
      $stmt = $uStmt;
    }

    // Execute update
    if ($stmt->execute()) {
      http_response_code(200);
      echo json_encode(["message" => "Order data updated successfully"]);
      return;
    } else {
      $err = $stmt->errorInfo();
      http_response_code(500);
      echo json_encode(["error" => "Error updating order data", "db_error" => $err]);
      return;
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
}
