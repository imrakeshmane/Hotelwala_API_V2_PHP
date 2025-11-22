<?php
include '../db.php'; // database connection (PDO $conn)
include '../validate.php'; // JWT helpers (getJWTFromHeader, validateJWT)

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

/*
  Support tokens that use owner_id or user_id
*/
$userID   = $payload['user_id']   ?? $payload['owner_id'] ?? null;
$userType = $payload['user_type'] ?? null;

if ($userID === null || $userType === null) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid token payload (missing user id or type)"]);
    return;
}

/**
 * hasHotelAccess - check actor access to hotel (owner, staff, mapping)
 */
function hasHotelAccess($conn, $hotelId, $actorId, $actorType) {
    if (empty($hotelId) || empty($actorId)) return false;

    try {
        if ($actorType === 'owner') {
            $sql = "SELECT 1 FROM hotels WHERE hotel_id = :hotel_id AND owner_id = :actor_id LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':hotel_id', $hotelId);
            $stmt->bindParam(':actor_id', $actorId);
            $stmt->execute();
            if ($stmt->fetchColumn()) return true;
        }

        // staff check via users.hotel_id
        $sql = "SELECT 1 FROM users WHERE user_id = :actor_id AND hotel_id = :hotel_id LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':actor_id', $actorId);
        $stmt->bindParam(':hotel_id', $hotelId);
        $stmt->execute();
        if ($stmt->fetchColumn()) return true;

        // fallback mapping table user_hotels (if exists)
        $sql = "SELECT 1 FROM user_hotels WHERE user_id = :actor_id AND hotel_id = :hotel_id LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':actor_id', $actorId);
        $stmt->bindParam(':hotel_id', $hotelId);
        $stmt->execute();
        if ($stmt->fetchColumn()) return true;

    } catch (PDOException $e) {
        return false;
    }

    return false;
}

/* Helpers */

// safe numeric parse
function safe_float($v) {
    if (!isset($v)) return 0.0;
    if (!is_numeric($v)) return 0.0;
    return floatval($v);
}

// compute and normalize a single order_data object (orders array => compute sub_total and tax fields)
// returns normalized order object (array) and final_amount (float)
function computeOrderFields(array $orderObj, $defaultCgstPct = 0.0, $defaultSgstPct = 0.0) {
    $orders = isset($orderObj['orders']) && is_array($orderObj['orders']) ? $orderObj['orders'] : [];

    // compute sub_total from items
    $computedSub = 0.0;
    foreach ($orders as $it) {
        $qty = isset($it['quantity']) ? floatval($it['quantity']) : 1.0;
        $price = isset($it['price']) ? floatval($it['price']) : 0.0;
        $computedSub += ($qty * $price);
    }
    $computedSub = round($computedSub, 2);

    // percentages: prefer fields in object else defaults
    $cg_pct = safe_float($orderObj['cgst_percentage'] ?? $orderObj['cgst_pct'] ?? $defaultCgstPct);
    $sg_pct = safe_float($orderObj['sgst_percentage'] ?? $orderObj['sgst_pct'] ?? $defaultSgstPct);

    // compute amounts
    $cg_amt = round($computedSub * ($cg_pct / 100.0), 2);
    $sg_amt = round($computedSub * ($sg_pct / 100.0), 2);
    $final_amt = round($computedSub + $cg_amt + $sg_amt, 2);

    // normalize and attach
    $orderObj['orders'] = $orders;
    $orderObj['sub_total'] = $computedSub;
    $orderObj['cgst_percentage'] = $cg_pct;
    $orderObj['sgst_percentage'] = $sg_pct;
    $orderObj['cgst_amount'] = $cg_amt;
    $orderObj['sgst_amount'] = $sg_amt;
    $orderObj['final_amount'] = $final_amt;

    return [$orderObj, $final_amt];
}

// compute for split array of objects - returns normalized split array and overall final sum
function computeSplitArray(array $splitArr, $defaultCgstPct = 0.0, $defaultSgstPct = 0.0) {
    $computedFinalSum = 0.0;
    $normalized = [];
    foreach ($splitArr as $sp) {
        $orderObj = [];

        if (isset($sp['items']) && is_array($sp['items'])) {
            $orderObj['orders'] = array_map(function($it){
                return [
                    'menu_item_id' => $it['menu_item_id'] ?? $it['menu_id'] ?? null,
                    'name' => $it['name'] ?? $it['menu_name'] ?? null,
                    'price' => isset($it['price']) ? $it['price'] : ($it['menu_price'] ?? 0),
                    'quantity' => isset($it['quantity']) ? $it['quantity'] : 1,
                ];
            }, $sp['items']);
        } elseif (isset($sp['orders']) && is_array($sp['orders'])) {
            $orderObj['orders'] = $sp['orders'];
        } else {
            $orderObj['orders'] = [];
        }

        if (isset($sp['cgst_percentage'])) $orderObj['cgst_percentage'] = $sp['cgst_percentage'];
        if (isset($sp['sgst_percentage'])) $orderObj['sgst_percentage'] = $sp['sgst_percentage'];

        list($normalizedOrder, $final_amt) = computeOrderFields($orderObj, $defaultCgstPct, $defaultSgstPct);

        $out = [
            'split_order_id' => isset($sp['split_order_id']) ? $sp['split_order_id'] : null,
            'items' => isset($sp['items']) && is_array($sp['items']) ? $sp['items'] : array_map(function($o){
                return [
                    'menu_item_id' => $o['menu_item_id'] ?? $o['menu_id'] ?? null,
                    'name' => $o['name'] ?? null,
                    'price' => isset($o['price']) ? $o['price'] : 0,
                    'quantity' => isset($o['quantity']) ? intval($o['quantity']) : 1,
                ];
            }, $normalizedOrder['orders']),
            'sub_total' => $normalizedOrder['sub_total'],
            'cgst_percentage' => $normalizedOrder['cgst_percentage'],
            'sgst_percentage' => $normalizedOrder['sgst_percentage'],
            'cgst_amount' => $normalizedOrder['cgst_amount'],
            'sgst_amount' => $normalizedOrder['sgst_amount'],
            'final_amount' => $normalizedOrder['final_amount'],
        ];

        $normalized[] = $out;
        $computedFinalSum += $final_amt;
    }

    $computedFinalSum = round($computedFinalSum, 2);
    return [$normalized, $computedFinalSum];
}

/* Route handling */

switch ($requestMethod) {
    case 'POST': // Create a new table
        createTable($conn, $userID, $userType);
        break;
    case 'GET': // Read tables
        getTables($conn, $userID, $userType);
        break;
    case 'PUT': // Update an existing table
        updateTable($conn, $userID, $userType);
        break;
    case 'DELETE': // Delete a table
        deleteTable($conn, $userID, $userType);
        break;
    default:
        http_response_code(405); // Method not allowed
        echo json_encode(["error" => "Method not allowed"]);
        break;
}

/* GET: list categories + tables for a hotel */
function getTables($conn, $userID, $userType) {
    $hotelId = null;
    if (isset($_GET['hotel_id'])) {
        $hotelId = $_GET['hotel_id'];
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['hotel_id'])) $hotelId = $data['hotel_id'];
    }

    if ($hotelId === null) {
        http_response_code(400);
        echo json_encode(["error" => "Hotel ID is required"]);
        return;
    }

    if (!hasHotelAccess($conn, $hotelId, $userID, $userType)) {
        http_response_code(403);
        echo json_encode(["error" => "Hotel not found or you don't have access"]);
        return;
    }

    $sql = "SELECT category_id, category_name, category_table_count, created_at, updated_at 
            FROM categories WHERE hotel_id = :hotel_id ORDER BY category_id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultCategories = [];
    foreach ($categories as $c) {
        $sql2 = "SELECT * FROM tables WHERE category_id = :category_id ORDER BY CAST(table_number AS UNSIGNED) ASC, table_id ASC";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->bindParam(':category_id', $c['category_id']);
        $stmt2->execute();
        $tables = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tables as &$t) {
            // defensive checks to avoid warnings
            $t['table_id'] = isset($t['table_id']) ? (int)$t['table_id'] : null;
            $t['category_id'] = isset($t['category_id']) ? (int)$t['category_id'] : null;
            // Use final_amount column (may be null)
            $t['final_amount'] = isset($t['final_amount']) ? number_format((float)$t['final_amount'], 2, '.', '') : "0.00";
            $t['is_split'] = isset($t['is_split']) ? (int)$t['is_split'] : 0;
            $t['taken_by_id'] = isset($t['taken_by_id']) ? (int)$t['taken_by_id'] : null;

            if (!empty($t['order_data'])) {
                $decoded = json_decode($t['order_data'], true);
                $t['order_data'] = ($decoded === null) ? $t['order_data'] : $decoded;
            } else {
                $t['order_data'] = null;
            }

            if (!empty($t['split_order_data'])) {
                $decoded = json_decode($t['split_order_data'], true);
                $t['split_order_data'] = ($decoded === null) ? $t['split_order_data'] : $decoded;
            } else {
                $t['split_order_data'] = null;
            }
        }
        unset($t);

        $resultCategories[] = [
            'category_id' => $c['category_id'],
            'category_name' => $c['category_name'],
            'category_table_count' => (int)$c['category_table_count'],
            'tables' => $tables,
            'created_at' => $c['created_at'],
            'updated_at' => $c['updated_at'],
        ];
    }

    http_response_code(200);
    echo json_encode(["categories" => $resultCategories]);
}

/* CREATE new table */
function createTable($conn, $userID, $userType) {
    if ($userType !== 'owner' && $userType !== 'manager') {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can create tables"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['category_id'], $data['table_number'], $data['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing required fields. category_id, table_number and hotel_id required"]);
        return;
    }

    $categoryId = $data['category_id'];
    $tableNumber = $data['table_number'];
    $hotelId = $data['hotel_id'];

    if (!hasHotelAccess($conn, $hotelId, $userID, $userType)) {
        http_response_code(403);
        echo json_encode(["error" => "You don't have access to this hotel"]);
        return;
    }

    // Check category belongs to hotel
    $sql = "SELECT category_id FROM categories WHERE category_id = :category_id AND hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryId);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Category not found for this hotel"]);
        return;
    }

    $tableStatus = $data['table_status'] ?? 'available';
    $isSplit = isset($data['is_split']) ? ($data['is_split'] ? 1 : 0) : 0;
    $splitOrderDataRaw = $data['split_order_data'] ?? null;
    $orderDataRaw = $data['order_data'] ?? null;
    $takenById = $data['taken_by_id'] ?? null;
    $takenByRole = $data['taken_by_role'] ?? null;

    // compute and normalize order_data / split_order_data and final_amount
    $finalAmountToStore = null;
    $orderJson = null;
    $splitJson = null;

    if ($isSplit && is_array($splitOrderDataRaw)) {
        list($normalizedSplit, $computedFinal) = computeSplitArray($splitOrderDataRaw);
        $splitJson = json_encode($normalizedSplit);
        $finalAmountToStore = $computedFinal;
    } elseif (!$isSplit && is_array($orderDataRaw)) {
        list($normalizedOrder, $finalAmt) = computeOrderFields($orderDataRaw);
        $orderJson = json_encode($normalizedOrder);
        $finalAmountToStore = $finalAmt;
    }

    // Insert
    $sql = "INSERT INTO tables (category_id, table_number, table_status, is_split, split_order_data, order_data, taken_by_id, taken_by_role, final_amount) 
            VALUES (:category_id, :table_number, :table_status, :is_split, :split_order_data, :order_data, :taken_by_id, :taken_by_role, :final_amount)";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryId);
    $stmt->bindParam(':table_number', $tableNumber);
    $stmt->bindParam(':table_status', $tableStatus);
    $stmt->bindParam(':is_split', $isSplit);
    $stmt->bindValue(':split_order_data', isset($splitJson) ? $splitJson : null, PDO::PARAM_STR);
    $stmt->bindValue(':order_data', isset($orderJson) ? $orderJson : null, PDO::PARAM_STR);
    $stmt->bindParam(':taken_by_id', $takenById);
    $stmt->bindParam(':taken_by_role', $takenByRole);

    if ($finalAmountToStore === null) {
        $stmt->bindValue(':final_amount', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':final_amount', $finalAmountToStore);
    }

    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(["message" => "Table created successfully"]);
    } else {
        http_response_code(500);
        $err = $stmt->errorInfo();
        echo json_encode(["error" => "Error creating table", "db_error" => $err]);
    }
}

/* UPDATE table */
function updateTable($conn, $userID, $userType) {
    if ($userType !== 'owner' && $userType !== 'manager') {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can update tables"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['table_id'], $data['category_id'], $data['table_number'], $data['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $tableId = $data['table_id'];
    $categoryId = $data['category_id'];
    $tableNumber = $data['table_number'];
    $hotelId = $data['hotel_id'];

    if (!hasHotelAccess($conn, $hotelId, $userID, $userType)) {
        http_response_code(403);
        echo json_encode(["error" => "You don't have access to this hotel"]);
        return;
    }

    // Check category
    $sql = "SELECT category_id FROM categories WHERE category_id = :category_id AND hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryId);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Category not found for this hotel"]);
        return;
    }

    $tableStatus = $data['table_status'] ?? 'available';
    $isSplit = isset($data['is_split']) ? ($data['is_split'] ? 1 : 0) : 0;
    // use array_key_exists so we can detect explicit null vs omitted
    $splitOrderDataRaw = array_key_exists('split_order_data', $data) ? $data['split_order_data'] : null;
    $orderDataRaw = array_key_exists('order_data', $data) ? $data['order_data'] : null;
    $takenById = $data['taken_by_id'] ?? null;
    $takenByRole = $data['taken_by_role'] ?? null;

    // compute normalized JSON and final_amount
    $finalAmountToStore = null;
    $orderJson = null;
    $splitJson = null;

    if ($isSplit && is_array($splitOrderDataRaw)) {
        list($normalizedSplit, $computedFinal) = computeSplitArray($splitOrderDataRaw);
        $splitJson = json_encode($normalizedSplit);
        $finalAmountToStore = $computedFinal;
    } elseif (!$isSplit && is_array($orderDataRaw)) {
        list($normalizedOrder, $finalAmt) = computeOrderFields($orderDataRaw);
        $orderJson = json_encode($normalizedOrder);
        $finalAmountToStore = $finalAmt;
    }

    // Update
    $sql = "UPDATE tables SET table_number = :table_number, table_status = :table_status, is_split = :is_split,
            split_order_data = :split_order_data, order_data = :order_data, taken_by_id = :taken_by_id, taken_by_role = :taken_by_role, final_amount = :final_amount
            WHERE table_id = :table_id AND category_id = :category_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':table_number', $tableNumber);
    $stmt->bindParam(':table_status', $tableStatus);
    $stmt->bindParam(':is_split', $isSplit);

    if (isset($splitJson)) {
        $stmt->bindValue(':split_order_data', $splitJson, PDO::PARAM_STR);
    } else {
        // if caller provided the key (even null) use it, else set to NULL
        $stmt->bindValue(':split_order_data', is_null($splitOrderDataRaw) ? null : (is_array($splitOrderDataRaw) ? json_encode($splitOrderDataRaw) : $splitOrderDataRaw), PDO::PARAM_STR);
    }

    if (isset($orderJson)) {
        $stmt->bindValue(':order_data', $orderJson, PDO::PARAM_STR);
    } else {
        $stmt->bindValue(':order_data', is_null($orderDataRaw) ? null : (is_array($orderDataRaw) ? json_encode($orderDataRaw) : $orderDataRaw), PDO::PARAM_STR);
    }

    $stmt->bindParam(':taken_by_id', $takenById);
    $stmt->bindParam(':taken_by_role', $takenByRole);

    if ($finalAmountToStore === null) {
        $stmt->bindValue(':final_amount', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':final_amount', $finalAmountToStore);
    }

    $stmt->bindParam(':table_id', $tableId);
    $stmt->bindParam(':category_id', $categoryId);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(["message" => "Table updated successfully"]);
    } else {
        http_response_code(500);
        $err = $stmt->errorInfo();
        echo json_encode(["error" => "Error updating table", "db_error" => $err]);
    }
}

/* DELETE table */
function deleteTable($conn, $userID, $userType) {
    if ($userType !== 'owner' && $userType !== 'manager') {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can delete tables"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['table_id'], $data['category_id'], $data['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "Table ID, Category ID and hotel_id are required"]);
        return;
    }

    $tableId = $data['table_id'];
    $categoryId = $data['category_id'];
    $hotelId = $data['hotel_id'];

    if (!hasHotelAccess($conn, $hotelId, $userID, $userType)) {
        http_response_code(403);
        echo json_encode(["error" => "You don't have access to this hotel"]);
        return;
    }

    $sql = "SELECT category_id FROM categories WHERE category_id = :category_id AND hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':category_id', $categoryId);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Category not found for this hotel"]);
        return;
    }

    $sql = "DELETE FROM tables WHERE table_id = :table_id AND category_id = :category_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':table_id', $tableId);
    $stmt->bindParam(':category_id', $categoryId);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(["message" => "Table deleted successfully"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error deleting table"]);
    }
}

?>
