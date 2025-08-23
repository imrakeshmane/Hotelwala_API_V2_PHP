<?php
// menus.php
include '../db.php';
include '../validate.php';

header('Content-Type: application/json');
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Validate JWT
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

// Extract user info
$userID = $payload['owner_id'] ?? null;
$userType = $payload['user_type'] ?? null;

/* helper: read params from GET or JSON body (body takes precedence) */
function readParams() {
    $params = $_GET ?? [];
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (is_array($body)) {
        $params = array_merge($params, $body); // body overrides query string
    }
    return $params;
}

/* helper: owner/manager check */
function isOwnerOrManager($userType, $payload) {
    if ($userType === 'owner' || $userType === 'manager') return true;
    if (($payload['user_role'] ?? '') === 'manager') return true;
    return false;
}

switch ($requestMethod) {
    case 'POST': createMenu($conn, $userID, $userType); break;
    case 'GET':  getMenus($conn, $userID, $userType); break;
    case 'PUT':  updateMenu($conn, $userID, $userType); break;
    case 'DELETE': deleteMenu($conn, $userID, $userType); break;
    default:
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        break;
}

/* ---------------------------
   POST - create menu (unchanged flow)
   Body JSON:
   { "hotel_id":..., "menu_name": "...", "menu_type":"...", "menu_price": 123, ... }
*/
function createMenu($conn, $userID, $userType) {
    global $payload;
    if (!isOwnerOrManager($userType, $payload)) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can create menu items"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['hotel_id'], $data['menu_name'], $data['menu_type'], $data['menu_price'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $hotelId = (int)$data['hotel_id'];
    $menuName = trim($data['menu_name']);
    $menuType = trim($data['menu_type']);
    $menuPrice = floatval($data['menu_price']);
    $menuStock = isset($data['menu_stock']) ? (int)$data['menu_stock'] : 0;
    $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

    // ownership check (owner must own the hotel)
    $sql = "SELECT owner_id FROM hotels WHERE hotel_id = :hotel_id LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $stmt->execute();
    $hrow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hrow) {
        http_response_code(404);
        echo json_encode(["error" => "Hotel not found"]);
        return;
    }
    if ($userType === 'owner' && (int)$hrow['owner_id'] !== (int)$userID) {
        http_response_code(403);
        echo json_encode(["error" => "You don't have access to this hotel"]);
        return;
    }

    try {
        $ins = "INSERT INTO menus (hotel_id, menu_name, menu_type, menu_price, menu_stock, is_active, created_at, updated_at)
                VALUES (:hotel_id, :menu_name, :menu_type, :menu_price, :menu_stock, :is_active, NOW(), NOW())";
        $s = $conn->prepare($ins);
        $s->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $s->bindValue(':menu_name', $menuName);
        $s->bindValue(':menu_type', $menuType);
        $s->bindValue(':menu_price', $menuPrice);
        $s->bindValue(':menu_stock', $menuStock, PDO::PARAM_INT);
        $s->bindValue(':is_active', $isActive, PDO::PARAM_INT);
        $s->execute();

        // return current page (first page) of menus to keep old behavior
        $fetchSql = "SELECT * FROM menus WHERE hotel_id = :hotel_id ORDER BY menu_name ASC";
        $fs = $conn->prepare($fetchSql);
        $fs->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $fs->execute();
        $menus = $fs->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(201);
        echo json_encode(["message" => "Menu item created successfully", "menus" => $menus]);
        return;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
        return;
    }
}

/* ---------------------------
   GET - list or single detail (supports query params OR JSON body)
   Params:
     - hotel_id (required)
     - menu_id (optional) -> returns single menu detail
     - page (optional, default 1)
     - per_page (optional, default 10, max 100)
     - optional: menu_type, is_active (filters)
*/
function getMenus($conn, $userID, $userType) {
    $params = readParams();

    if (!isset($params['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "hotel_id is required"]);
        return;
    }
    $hotelId = (int)$params['hotel_id'];

    // verify hotel exists and owner access if owner
    $sqlCheck = "SELECT hotel_id, owner_id FROM hotels WHERE hotel_id = :hotel_id LIMIT 1";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $stmtCheck->execute();
    $hotelRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$hotelRow) {
        http_response_code(404);
        echo json_encode(["error" => "Hotel not found"]);
        return;
    }
    if ($userType === 'owner' && (int)$hotelRow['owner_id'] !== (int)$userID) {
        http_response_code(403);
        echo json_encode(["error" => "You don't have access to this hotel's data"]);
        return;
    }

    // single menu detail
    if (isset($params['menu_id'])) {
        $menuId = (int)$params['menu_id'];
        $sql = "SELECT * FROM menus WHERE menu_id = :menu_id AND hotel_id = :hotel_id LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':menu_id', $menuId, PDO::PARAM_INT);
        $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(["error" => "Menu item not found"]);
            return;
        }
        http_response_code(200);
        echo json_encode($row);
        return;
    }

    // list with pagination & filters
    $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
    $perPage = isset($params['per_page']) ? min(100, max(1, (int)$params['per_page'])) : 10;
    $offset = ($page - 1) * $perPage;

    // optional filters
    $filters = [];
    $binds = [':hotel_id' => $hotelId];
    if (isset($params['menu_type'])) {
        $filters[] = "menu_type = :menu_type";
        $binds[':menu_type'] = $params['menu_type'];
    }
    if (isset($params['is_active'])) {
        $filters[] = "is_active = :is_active";
        $binds[':is_active'] = (int)$params['is_active'];
    }

    $where = "hotel_id = :hotel_id";
    if (count($filters) > 0) {
        $where .= " AND " . implode(' AND ', $filters);
    }

    try {
        // count total
        $countSql = "SELECT COUNT(*) AS total FROM menus WHERE $where";
        $cstmt = $conn->prepare($countSql);
        foreach ($binds as $k => $v) {
            // type guess
            if (is_int($v)) $cstmt->bindValue($k, $v, PDO::PARAM_INT); else $cstmt->bindValue($k, $v);
        }
        $cstmt->execute();
        $totalRow = $cstmt->fetch(PDO::FETCH_ASSOC);
        $totalCount = (int)($totalRow['total'] ?? 0);

        // fetch page
        $sql = "SELECT menu_id, hotel_id, menu_name, menu_type, menu_price, menu_stock, is_active, created_at, updated_at
                FROM menus
                WHERE $where
                ORDER BY menu_name ASC
                LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        foreach ($binds as $k => $v) {
            if (is_int($v)) $stmt->bindValue($k, $v, PDO::PARAM_INT); else $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            "menus" => $rows,
            "total_count" => $totalCount,
            "page" => $page,
            "per_page" => $perPage
        ]);
        return;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
        return;
    }
}

/* ---------------------------
   PUT - update a menu (owner/manager only)
   Body JSON:
   { "menu_id": 1, "hotel_id": 8, "menu_name": "...", "menu_type": "...", "menu_price": 123, ... }
*/
function updateMenu($conn, $userID, $userType) {
    global $payload;
    if (!isOwnerOrManager($userType, $payload)) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can update menu items"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['menu_id'], $data['hotel_id'], $data['menu_name'], $data['menu_type'], $data['menu_price'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $menuId = (int)$data['menu_id'];
    $hotelId = (int)$data['hotel_id'];

    // verify hotel and owner access
    $sql = "SELECT owner_id FROM hotels WHERE hotel_id = :hotel_id LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $stmt->execute();
    $hrow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hrow) {
        http_response_code(404);
        echo json_encode(["error" => "Hotel not found"]);
        return;
    }
    if ($userType === 'owner' && (int)$hrow['owner_id'] !== (int)$userID) {
        http_response_code(403);
        echo json_encode(["error" => "You don't have access to this hotel's data"]);
        return;
    }

    $menuName = trim($data['menu_name']);
    $menuType = trim($data['menu_type']);
    $menuPrice = floatval($data['menu_price']);
    $menuStock = isset($data['menu_stock']) ? (int)$data['menu_stock'] : 0;
    $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

    try {
        $sql = "UPDATE menus SET menu_name = :menu_name, menu_type = :menu_type, menu_price = :menu_price, 
                menu_stock = :menu_stock, is_active = :is_active, updated_at = NOW()
                WHERE menu_id = :menu_id AND hotel_id = :hotel_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':menu_name', $menuName);
        $stmt->bindValue(':menu_type', $menuType);
        $stmt->bindValue(':menu_price', $menuPrice);
        $stmt->bindValue(':menu_stock', $menuStock, PDO::PARAM_INT);
        $stmt->bindValue(':is_active', $isActive, PDO::PARAM_INT);
        $stmt->bindValue(':menu_id', $menuId, PDO::PARAM_INT);
        $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        if ($stmt->execute()) {
            // return updated page of menus
            $fetchSql = "SELECT * FROM Menus WHERE hotel_id = :hotel_id";
            $fs = $conn->prepare($fetchSql);
            $fs->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
            $fs->execute();
            $menus = $fs->fetchAll(PDO::FETCH_ASSOC);
            http_response_code(200);
            echo json_encode(["message" => "Menu item updated successfully", "menus" => $menus]);
            return;
        } else {
            $err = $stmt->errorInfo();
            http_response_code(500);
            echo json_encode(["error" => "Error updating menu item", "db_error" => $err]);
            return;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
        return;
    }
}

/* ---------------------------
   DELETE - delete a menu (owner/manager only)
   Body JSON: { "menu_id": 1, "hotel_id": 8 }
*/
function deleteMenu($conn, $userID, $userType) {
    global $payload;
    if (!isOwnerOrManager($userType, $payload)) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can delete menu items"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['menu_id'], $data['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "Menu ID and Hotel ID are required"]);
        return;
    }

    $menuId = (int)$data['menu_id'];
    $hotelId = (int)$data['hotel_id'];

    // verify hotel/owner access
    $sql = "SELECT owner_id FROM hotels WHERE hotel_id = :hotel_id LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $stmt->execute();
    $hrow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hrow) {
        http_response_code(404);
        echo json_encode(["error" => "Hotel not found"]);
        return;
    }
    if ($userType === 'owner' && (int)$hrow['owner_id'] !== (int)$userID) {
        http_response_code(403);
        echo json_encode(["error" => "You don't have access to this hotel's data"]);
        return;
    }

    try {
        $sql = "DELETE FROM menus WHERE menu_id = :menu_id AND hotel_id = :hotel_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':menu_id', $menuId, PDO::PARAM_INT);
        $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(["message" => "Menu item deleted successfully"]);
            return;
        } else {
            $err = $stmt->errorInfo();
            http_response_code(500);
            echo json_encode(["error" => "Error deleting menu item", "db_error" => $err]);
            return;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
        return;
    }
}
?>
