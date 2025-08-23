<?php
// orderHistory.php
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

// Extract user ID and user type from JWT
$userID = $payload['owner_id'] ?? null;
$userType = $payload['user_type'] ?? null;

// Helper: check owner/manager
function isOwnerOrManager($userType, $payload) {
    if ($userType === 'owner' || $userType === 'manager') return true;
    if (($payload['user_role'] ?? '') === 'manager') return true;
    return false;
}

// Helper: read params from GET or JSON body (body wins when present)
function readParams() {
    // Read query params first
    $params = $_GET ?? [];
    // Read JSON body (works both for POST and GET bodies)
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (is_array($body)) {
        // merge body over query params (body takes precedence)
        $params = array_merge($params, $body);
    }
    return $params;
}

switch ($requestMethod) {
    case 'GET':
        getOrderHistory($conn, $userID, $userType);
        break;
    case 'PUT':
        updateOrderHistory($conn, $userID, $userType);
        break;
    case 'DELETE':
        deleteOrderHistory($conn, $userID, $userType);
        break;
    default:
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed. Use GET/PUT/DELETE."]);
        break;
}

/* ---------------------------
   GET - list or detail
   Accepts input from either query string or JSON body.
   Parameters:
     - hotel_id (required)
     - order_history_id (optional) -> return single detail
     - page (optional, default 1)
     - per_page (optional, default 10, max 100)
*/
function getOrderHistory($conn, $userID, $userType) {
    $params = readParams();

    if (!isset($params['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "hotel_id is required"]);
        return;
    }
    $hotelId = (int)$params['hotel_id'];

    // ownership check: ensure the hotel exists and belongs to the owner (only when userType is owner)
    $sqlCheck = "SELECT hotel_id, owner_id FROM Hotels WHERE hotel_id = :hotel_id LIMIT 1";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $stmtCheck->execute();
    $hotelRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$hotelRow) {
        http_response_code(404);
        echo json_encode(["error" => "Hotel not found"]);
        return;
    }
    // If user is owner, ensure they own the hotel
    if ($userType === 'owner' && (int)$hotelRow['owner_id'] !== (int)$userID) {
        http_response_code(403);
        echo json_encode(["error" => "You don't have access to this hotel's data"]);
        return;
    }
    // If you want to restrict managers to specific hotels, add that check here (requires mapping table)

    // If detail requested
    if (isset($params['order_history_id'])) {
        $id = (int)$params['order_history_id'];
        $sql = "SELECT oh.*, t.table_number
                FROM orderhistory oh
                LEFT JOIN tables t ON oh.table_id = t.table_id
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

    // list: pagination
    $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
    $perPage = isset($params['per_page']) ? min(100, max(1, (int)$params['per_page'])) : 10;
    $offset = ($page - 1) * $perPage;

    // total count for this hotel (fast if indexed)
    try {
        $countSql = "SELECT COUNT(*) AS total FROM orderhistory WHERE hotel_id = :hotel_id";
        $cstmt = $conn->prepare($countSql);
        $cstmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $cstmt->execute();
        $totalRow = $cstmt->fetch(PDO::FETCH_ASSOC);
        $totalCount = (int)($totalRow['total'] ?? 0);

        // fetch page rows
        $sql = "SELECT oh.order_history_id, oh.hotel_id, oh.table_id, t.table_number, oh.is_split, oh.split_order_id,
                       oh.total_cost, oh.payment_status, oh.created_at, oh.updated_at, oh.order_data
                FROM orderhistory oh
                LEFT JOIN tables t ON oh.table_id = t.table_id
                WHERE oh.hotel_id = :hotel_id
                ORDER BY oh.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // build lightweight list
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
        echo json_encode([
            "orderhistory" => $items,
            "total_count" => $totalCount,
            "page" => $page,
            "per_page" => $perPage,
        ]);
        return;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
        return;
    }
}

/* ---------------------------
   PUT - update order history (owner/manager only)
   Body JSON:
   { "order_history_id": 123, "order_data": {...}, "total_cost": 420, "payment_status": "paid" }
   Additional server-side ownership protection: only owner of hotel can modify that hotel's records (manager allowed if your app maps them).
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

    // fetch record and hotel's owner to verify permission
    $chk = $conn->prepare("SELECT hotel_id FROM orderhistory WHERE order_history_id = :id LIMIT 1");
    $chk->bindValue(':id', $id, PDO::PARAM_INT);
    $chk->execute();
    $found = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$found) {
        http_response_code(404);
        echo json_encode(["error" => "Order history not found"]);
        return;
    }
    $recordHotelId = (int)$found['hotel_id'];

    // If user is owner, ensure they own this hotel
    if ($userType === 'owner') {
        $hchk = $conn->prepare("SELECT owner_id FROM Hotels WHERE hotel_id = :hotel_id LIMIT 1");
        $hchk->bindValue(':hotel_id', $recordHotelId, PDO::PARAM_INT);
        $hchk->execute();
        $hrow = $hchk->fetch(PDO::FETCH_ASSOC);
        if (!$hrow || (int)$hrow['owner_id'] !== (int)$userID) {
            http_response_code(403);
            echo json_encode(["error" => "You don't have permission to update this record"]);
            return;
        }
    }
    // If you want manager permission checks, implement mapping checks here.

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
    try {
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
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
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

    // fetch record hotel
    $chk = $conn->prepare("SELECT hotel_id FROM orderhistory WHERE order_history_id = :id LIMIT 1");
    $chk->bindValue(':id', $id, PDO::PARAM_INT);
    $chk->execute();
    $found = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$found) {
        http_response_code(404);
        echo json_encode(["error" => "Order history not found"]);
        return;
    }
    $recordHotelId = (int)$found['hotel_id'];

    // If user is owner, ensure they own this hotel
    if ($userType === 'owner') {
        $hchk = $conn->prepare("SELECT owner_id FROM Hotels WHERE hotel_id = :hotel_id LIMIT 1");
        $hchk->bindValue(':hotel_id', $recordHotelId, PDO::PARAM_INT);
        $hchk->execute();
        $hrow = $hchk->fetch(PDO::FETCH_ASSOC);
        if (!$hrow || (int)$hrow['owner_id'] !== (int)$userID) {
            http_response_code(403);
            echo json_encode(["error" => "You don't have permission to delete this record"]);
            return;
        }
    }

    try {
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
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
    }
}
?>
