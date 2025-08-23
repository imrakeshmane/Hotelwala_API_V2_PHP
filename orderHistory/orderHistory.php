<?php
// orderhistory.php
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

// Validate the JWT token
$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid token"]);
    return;
}

// Extract user ID and user type from JWT (same as Menu API)
$userID = $payload['owner_id'];
$userType = $payload['user_type'];

switch ($requestMethod) {
    case 'GET':     // Read (body JSON)
        getOrderHistory($conn, $userID, $userType);
        break;
    case 'PUT':     // Update (owner/manager only)
        updateOrderHistory($conn, $userID, $userType);
        break;
    case 'DELETE':  // Delete (owner/manager only)
        deleteOrderHistory($conn, $userID, $userType);
        break;
    case 'POST':
    default:
        http_response_code(405); // Method not allowed
        echo json_encode(["error" => "Method not allowed. Use GET/PUT/DELETE."]);
        break;
}

/* ---------------------------
   Helper: owner/manager check
   --------------------------- */
function isOwnerOrManager($userType, $payload) {
    if ($userType === 'owner' || $userType === 'manager') return true;
    if (($payload['user_role'] ?? '') === 'manager') return true;
    return false;
}

/* ---------------------------
   GET - list or detail (body JSON)
   Body to get all: { "hotel_id": 8 }
   Body to get detail: { "hotel_id": 8, "order_history_id": 123 }
*/
function getOrderHistory($conn, $userID, $userType) {
    // read body JSON
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = [];

    if (!isset($body['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "Hotel ID is required"]);
        return;
    }
    $hotelId = (int)$body['hotel_id'];

    // verify hotel exists and belongs to user (same check as Menu API)
    $sql = "SELECT hotel_id FROM Hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $stmt->bindValue(':owner_id', $userID, PDO::PARAM_INT);
    $stmt->execute();
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Hotel not found or you don't have access"]);
        return;
    }

    // detail requested?
    if (isset($body['order_history_id'])) {
        $id = (int)$body['order_history_id'];
        $sql = "SELECT oh.*, t.table_number
                FROM orderhistory oh
                JOIN tables t ON oh.table_id = t.table_id
                WHERE oh.order_history_id = :id AND oh.hotel_id = :hotel_id
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(["error" => "Order history not found"]);
            return;
        }
        $decoded = null;
        if (!empty($row['order_data'])) {
            $decoded = json_decode($row['order_data'], true);
            if (json_last_error() !== JSON_ERROR_NONE) $decoded = null;
        }
        http_response_code(200);
        echo json_encode([
            'order_history_id' => (int)$row['order_history_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'table_id' => (int)$row['table_id'],
            'table_number' => $row['table_number'],
            'is_split' => (int)$row['is_split'],
            'split_order_id' => $row['split_order_id'] !== null ? (int)$row['split_order_id'] : null,
            'order_data_raw' => $row['order_data'],
            'order_data' => $decoded,
            'total_cost' => (float)$row['total_cost'],
            'payment_status' => $row['payment_status'],
            'taken_by_id' => $row['taken_by_id'],
            'taken_by_role' => $row['taken_by_role'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ]);
        return;
    }

    // list all
    $sql = "SELECT oh.order_history_id, oh.hotel_id, oh.table_id, t.table_number, oh.is_split, oh.split_order_id,
                   oh.total_cost, oh.payment_status, oh.created_at, oh.updated_at, oh.order_data
            FROM orderhistory oh
            JOIN tables t ON oh.table_id = t.table_id
            WHERE oh.hotel_id = :hotel_id
            ORDER BY oh.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $r) {
        $first_item = null;
        $items_count = 0;
        $final_amount = $r['total_cost'];
        if (!empty($r['order_data'])) {
            $decoded = json_decode($r['order_data'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $list = $decoded['orders'] ?? $decoded['items'] ?? [];
                if (is_array($list) && count($list) > 0) {
                    $first_item = $list[0]['name'] ?? $list[0]['MenuName'] ?? null;
                    $items_count = count($list);
                }
                $final_amount = $decoded['final_amount'] ?? $decoded['total_cost'] ?? $final_amount;
            }
        }
        $items[] = [
            'order_history_id' => (int)$r['order_history_id'],
            'table_id' => (int)$r['table_id'],
            'table_number' => $r['table_number'],
            'is_split' => (int)$r['is_split'],
            'split_order_id' => $r['split_order_id'] !== null ? (int)$r['split_order_id'] : null,
            'total_cost' => (float)$r['total_cost'],
            'final_amount' => (float)$final_amount,
            'payment_status' => $r['payment_status'],
            'created_at' => $r['created_at'],
            'items_count' => $items_count,
            'first_item' => $first_item,
        ];
    }

    http_response_code(200);
    echo json_encode(["orderhistory" => $items]);
}

/* ---------------------------
   PUT - update order history (owner/manager only)
   Body JSON:
   {
     "order_history_id": 123,
     "order_data": { ... },            // optional
     "total_cost": 420,                // optional
     "payment_status": "paid"          // optional
   }
*/
function updateOrderHistory($conn, $userID, $userType) {
    global $payload;
    if (!isOwnerOrManager($userType, $payload)) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can update order history"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['order_history_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "order_history_id and fields to update are required"]);
        return;
    }
    $id = (int)$data['order_history_id'];

    $allowed = ['order_data', 'total_cost', 'payment_status', 'taken_by_id', 'taken_by_role'];
    $sets = [];
    $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            if ($f === 'order_data') {
                $json = is_array($data[$f]) ? json_encode($data[$f]) : $data[$f];
                $sets[] = "order_data = :order_data";
                $params[':order_data'] = $json;
            } else {
                $sets[] = "$f = :$f";
                $params[":$f"] = $data[$f];
            }
        }
    }
    if (count($sets) === 0) {
        http_response_code(400);
        echo json_encode(["error" => "No update fields provided"]);
        return;
    }

    // ensure record exists
    $chk = $conn->prepare("SELECT hotel_id FROM orderhistory WHERE order_history_id = :id LIMIT 1");
    $chk->bindValue(':id', $id, PDO::PARAM_INT);
    $chk->execute();
    $found = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$found) {
        http_response_code(404);
        echo json_encode(["error" => "Order history not found"]);
        return;
    }

    $sql = "UPDATE orderhistory SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE order_history_id = :id";
    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(["message" => "Order history updated successfully"]);
    } else {
        $err = $stmt->errorInfo();
        http_response_code(500);
        echo json_encode(["error" => "Failed to update", "db_error" => $err]);
    }
}

/* ---------------------------
   DELETE - owner/manager only
   Body JSON: { "order_history_id": 123 }
*/
function deleteOrderHistory($conn, $userID, $userType) {
    global $payload;
    if (!isOwnerOrManager($userType, $payload)) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can delete order history"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['order_history_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "order_history_id is required"]);
        return;
    }
    $id = (int)$data['order_history_id'];

    // verify exists
    $chk = $conn->prepare("SELECT hotel_id FROM orderhistory WHERE order_history_id = :id LIMIT 1");
    $chk->bindValue(':id', $id, PDO::PARAM_INT);
    $chk->execute();
    $found = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$found) {
        http_response_code(404);
        echo json_encode(["error" => "Order history not found"]);
        return;
    }

    $stmt = $conn->prepare("DELETE FROM orderhistory WHERE order_history_id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(["message" => "Order history deleted successfully"]);
    } else {
        $err = $stmt->errorInfo();
        http_response_code(500);
        echo json_encode(["error" => "Failed to delete", "db_error" => $err]);
    }
}
?>