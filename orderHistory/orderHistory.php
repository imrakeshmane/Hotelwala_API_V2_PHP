<?php
// orderhistory_api.php
// POST JSON API for orderhistory list/details/update/delete
// Requires: ../db.php (PDO $conn), ../validate.php (getJWTFromHeader, validateJWT)

include '../db.php';
include '../validate.php';

header('Content-Type: application/json');
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Require POST (consistent with your other endpoints)
if ($requestMethod !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed. Use POST."]);
    exit;
}

// Authenticate JWT
$jwt = getJWTFromHeader();
if ($jwt === null) {
    http_response_code(401);
    echo json_encode(["error" => "Token is missing or invalid"]);
    exit;
}
$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid token"]);
    exit;
}

// Helpful info from token (adjust names if your token uses different keys)
$tokenUserId = $payload['owner_id'] ?? $payload['user_id'] ?? null;
$tokenUserType = $payload['user_type'] ?? $payload['type'] ?? null; // 'owner' or 'user'
$tokenUserRole = $payload['user_role'] ?? $payload['role'] ?? null; // e.g. 'manager', 'waiter'
$tokenHotelId = $payload['hotel_id'] ?? $payload['hotelId'] ?? null;

// Read request
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit;
}
$action = $input['action'] ?? null;
if (!$action) {
    http_response_code(400);
    echo json_encode(["error" => "Action parameter is required"]);
    exit;
}

// Helper: check owner or manager
function isOwnerOrManager($payload) {
    // Accept owner if token says owner
    if (($payload['user_type'] ?? $payload['type'] ?? '') === 'owner') return true;
    // Accept manager if token contains role = manager (depends on token structure)
    if (($payload['user_role'] ?? $payload['role'] ?? '') === 'manager') return true;
    // If your payload stores a boolean or other key for role adjust here
    return false;
}

// ROUTER
try {
    switch ($action) {
        case 'list':
            getOrderHistoryList($conn, $payload, $input);
            break;
        case 'details':
            getOrderHistoryDetails($conn, $payload, $input);
            break;
        case 'update':
            updateOrderHistory($conn, $payload, $input);
            break;
        case 'delete':
            deleteOrderHistory($conn, $payload, $input);
            break;
        default:
            http_response_code(400);
            echo json_encode(["error" => "Invalid action"]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Server error", "message" => $e->getMessage()]);
    exit;
}

/* =========================
   LIST: paginated (lightweight fields)
   Accepts: hotel_id (optional if in token), page, per_page, q (search), fromDate, toDate, payment_status, is_split, table_id
   Returns: items[] (light), total_count, page, per_page
   ========================= */
function getOrderHistoryList($conn, $payload, $input) {
    $hotelId = $payload['hotel_id'] ?? $payload['hotelId'] ?? ($input['hotel_id'] ?? null);
    if (empty($hotelId)) {
        http_response_code(400);
        echo json_encode(["error" => "hotel_id is required in token or request"]);
        return;
    }

    $page = max(1, intval($input['page'] ?? 1));
    $perPage = max(1, min(200, intval($input['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    $q = isset($input['q']) ? trim($input['q']) : null;
    $fromDate = $input['fromDate'] ?? null; // expect 'YYYY-MM-DD' or 'YYYY-MM-DD HH:MM:SS'
    $toDate = $input['toDate'] ?? null;
    $payment_status = $input['payment_status'] ?? null; // 'paid'|'unpaid'
    $is_split = (isset($input['is_split']) ? (int)$input['is_split'] : null); // 0|1|null
    $table_id = isset($input['table_id']) ? intval($input['table_id']) : null;

    // Base query (join to tables to get table_number)
    $where = " WHERE oh.hotel_id = :hotel_id ";
    $params = [':hotel_id' => $hotelId];

    if ($table_id) {
        $where .= " AND oh.table_id = :table_id ";
        $params[':table_id'] = $table_id;
    }

    if ($payment_status) {
        $where .= " AND oh.payment_status = :payment_status ";
        $params[':payment_status'] = $payment_status;
    }
    if ($is_split !== null) {
        $where .= " AND oh.is_split = :is_split ";
        $params[':is_split'] = $is_split;
    }
    if ($fromDate && $toDate) {
        $where .= " AND oh.created_at BETWEEN :fromDate AND :toDate ";
        $params[':fromDate'] = $fromDate;
        $params[':toDate'] = $toDate;
    } elseif ($fromDate) {
        $where .= " AND oh.created_at >= :fromDate ";
        $params[':fromDate'] = $fromDate;
    } elseif ($toDate) {
        $where .= " AND oh.created_at <= :toDate ";
        $params[':toDate'] = $toDate;
    }

    if ($q !== null && $q !== '') {
        // search either by order_history_id, table_number, or text inside order_data
        // We use LIKE on order_data because order_data is TEXT JSON in your schema (see SQL dump).
        $where .= " AND (oh.order_history_id = :qnum OR t.table_number LIKE :qlike OR oh.order_data LIKE :qlike) ";
        $qnum = is_numeric($q) ? intval($q) : 0;
        $params[':qnum'] = $qnum;
        $params[':qlike'] = "%{$q}%";
    }

    // Count query
    $countSql = "SELECT COUNT(1) as cnt FROM orderhistory oh JOIN tables t ON oh.table_id = t.table_id " . $where;
    $countStmt = $conn->prepare($countSql);
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $totalCount = (int)$countStmt->fetchColumn();

    // Data query - select lightweight fields + entire order_data so we can parse small details server-side
    $sql = "SELECT oh.order_history_id, oh.hotel_id, oh.table_id, t.table_number, oh.split_order_id, oh.is_split,
                   oh.total_cost, oh.payment_status, oh.taken_by_id, oh.taken_by_role, oh.created_at, oh.updated_at,
                   oh.order_data
            FROM orderhistory oh
            JOIN tables t ON oh.table_id = t.table_id
            $where
            ORDER BY oh.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse order_data JSON to build a small summary per row (first item name, items_count)
    $items = [];
    foreach ($rows as $r) {
        $first_item = null;
        $items_count = 0;
        $final_amount = $r['total_cost'];
        if (!empty($r['order_data'])) {
            $decoded = json_decode($r['order_data'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // two common shapes in your data: {orders: [...], total_cost, final_amount} OR {items:[...], total_cost}
                $list = $decoded['orders'] ?? $decoded['items'] ?? $decoded['items'] ?? [];
                if (is_array($list) && count($list) > 0) {
                    $first_item = $list[0]['name'] ?? $list[0]['MenuName'] ?? null;
                    $items_count = count($list);
                }
                $final_amount = $decoded['final_amount'] ?? $decoded['total_cost'] ?? $final_amount;
            }
        }
        $items[] = [
            'order_history_id' => (int)$r['order_history_id'],
            'hotel_id' => (int)$r['hotel_id'],
            'table_id' => (int)$r['table_id'],
            'table_number' => (string)$r['table_number'],
            'is_split' => (int)$r['is_split'],
            'split_order_id' => $r['split_order_id'] !== null ? (int)$r['split_order_id'] : null,
            'total_cost' => (float)$r['total_cost'],
            'final_amount' => (float)$final_amount,
            'payment_status' => $r['payment_status'],
            'taken_by_id' => $r['taken_by_id'],
            'taken_by_role' => $r['taken_by_role'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
            'items_count' => $items_count,
            'first_item' => $first_item,
        ];
    }

    echo json_encode([
        'page' => $page,
        'per_page' => $perPage,
        'total_count' => $totalCount,
        'items' => $items
    ]);
}

/* =========================
   DETAILS: full order details for a single order_history_id
   Accepts: order_history_id (required)
   Returns: full row + decoded order_data
   ========================= */
function getOrderHistoryDetails($conn, $payload, $input) {
    if (!isset($input['order_history_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "order_history_id is required"]);
        return;
    }
    $orderHistoryId = (int)$input['order_history_id'];
    $hotelId = $payload['hotel_id'] ?? $payload['hotelId'] ?? ($input['hotel_id'] ?? null);
    if (empty($hotelId)) {
        http_response_code(400);
        echo json_encode(["error" => "hotel_id is required in token or request"]);
        return;
    }

    $sql = "SELECT oh.*, t.table_number, c.category_name
            FROM orderhistory oh
            JOIN tables t ON oh.table_id = t.table_id
            LEFT JOIN categories c ON t.category_id = c.category_id
            WHERE oh.order_history_id = :id AND oh.hotel_id = :hotel_id
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id', $orderHistoryId, PDO::PARAM_INT);
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

    // Return full details, including parsed order_data
    echo json_encode([
        'order_history_id' => (int)$row['order_history_id'],
        'hotel_id' => (int)$row['hotel_id'],
        'table_id' => (int)$row['table_id'],
        'table_number' => $row['table_number'],
        'category_name' => $row['category_name'] ?? null,
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
}

/* =========================
   UPDATE: owner or manager only
   Accepts: order_history_id, and any of {order_data (array), total_cost, payment_status, taken_by_id, taken_by_role}
   ========================= */
function updateOrderHistory($conn, $payload, $input) {
    // Authorization: only owner or manager
    if (!isOwnerOrManager($payload)) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers are allowed to update order history"]);
        return;
    }

    if (!isset($input['order_history_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "order_history_id is required"]);
        return;
    }
    $id = (int)$input['order_history_id'];

    // Allowable fields
    $allowed = ['order_data', 'total_cost', 'payment_status', 'taken_by_id', 'taken_by_role'];
    $sets = [];
    $params = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $input)) {
            if ($field === 'order_data') {
                // ensure it's array (client should pass array)
                $json = is_array($input[$field]) ? json_encode($input[$field]) : $input[$field];
                $sets[] = "order_data = :order_data";
                $params[':order_data'] = $json;
            } else {
                $sets[] = "$field = :$field";
                $params[":$field"] = $input[$field];
            }
        }
    }
    if (count($sets) === 0) {
        http_response_code(400);
        echo json_encode(["error" => "No updatable fields provided"]);
        return;
    }

    // Verify record exists and belongs to hotel in token
    $hotelId = $payload['hotel_id'] ?? $payload['hotelId'] ?? null;
    if (empty($hotelId)) {
        http_response_code(400);
        echo json_encode(["error" => "hotel_id is required in token"]);
        return;
    }
    $check = $conn->prepare("SELECT hotel_id FROM orderhistory WHERE order_history_id = :id LIMIT 1");
    $check->bindValue(':id', $id, PDO::PARAM_INT);
    $check->execute();
    $found = $check->fetch(PDO::FETCH_ASSOC);
    if (!$found) {
        http_response_code(404);
        echo json_encode(["error" => "Order history not found"]);
        return;
    }
    if ((int)$found['hotel_id'] !== (int)$hotelId) {
        http_response_code(403);
        echo json_encode(["error" => "Not allowed to modify order from another hotel"]);
        return;
    }

    $sql = "UPDATE orderhistory SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE order_history_id = :id";
    $params[':id'] = $id;
    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    if ($stmt->execute()) {
        echo json_encode(["message" => "Order history updated successfully"]);
    } else {
        $err = $stmt->errorInfo();
        http_response_code(500);
        echo json_encode(["error" => "Failed to update", "db_error" => $err]);
    }
}

/* =========================
   DELETE: owner or manager only
   Accepts: order_history_id
   ========================= */
function deleteOrderHistory($conn, $payload, $input) {
    if (!isOwnerOrManager($payload)) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers are allowed to delete order history"]);
        return;
    }
    if (!isset($input['order_history_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "order_history_id is required"]);
        return;
    }
    $id = (int)$input['order_history_id'];

    // Verify hotel match
    $hotelId = $payload['hotel_id'] ?? $payload['hotelId'] ?? null;
    if (empty($hotelId)) {
        http_response_code(400);
        echo json_encode(["error" => "hotel_id is required in token"]);
        return;
    }
    $check = $conn->prepare("SELECT hotel_id FROM orderhistory WHERE order_history_id = :id LIMIT 1");
    $check->bindValue(':id', $id, PDO::PARAM_INT);
    $check->execute();
    $found = $check->fetch(PDO::FETCH_ASSOC);
    if (!$found) {
        http_response_code(404);
        echo json_encode(["error" => "Order history not found"]);
        return;
    }
    if ((int)$found['hotel_id'] !== (int)$hotelId) {
        http_response_code(403);
        echo json_encode(["error" => "Not allowed to delete order from another hotel"]);
        return;
    }

    $stmt = $conn->prepare("DELETE FROM orderhistory WHERE order_history_id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    if ($stmt->execute()) {
        echo json_encode(["message" => "Order history deleted"]);
    } else {
        $err = $stmt->errorInfo();
        http_response_code(500);
        echo json_encode(["error" => "Failed to delete", "db_error" => $err]);
    }
}
