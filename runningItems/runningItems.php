<?php
// runningItems.php
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

// Validate JWT
$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid token"]);
    return;
}

$userID = $payload['owner_id'] ?? null;
$userType = $payload['user_type'] ?? null;

switch ($requestMethod) {
    case 'GET':
        getRunningItems($conn, $userID, $userType);
        break;
    case 'POST':
        // allow POST (body params) as well for longer queries
        getRunningItems($conn, $userID, $userType);
        break;
    default:
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed. Use GET or POST."]);
}

/**
 * GET /runningItems.php?hotel_id=123&from_date=YYYY-MM-DD&to_date=YYYY-MM-DD&page=1&per_page=100
 * If from_date/to_date missing -> default to today
 */
function getRunningItems($conn, $userID, $userType) {
    $params = readParams(); // reuse your readParams helper (GET + JSON body)

    if (!isset($params['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "hotel_id is required"]);
        return;
    }
    $hotelId = (int)$params['hotel_id'];

    // Ownership check
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

    // Date normalization
    $fromDateRaw = isset($params['from_date']) ? trim((string)$params['from_date']) : null;
    $toDateRaw = isset($params['to_date']) ? trim((string)$params['to_date']) : null;

    if (empty($fromDateRaw) && empty($toDateRaw)) {
        $fromDate = date('Y-m-d') . ' 00:00:00';
        $toDate = date('Y-m-d') . ' 23:59:59';
    } else {
        $fromDate = null;
        $toDate = null;
        if (!empty($fromDateRaw)) {
            $d = DateTime::createFromFormat('Y-m-d', $fromDateRaw);
            if ($d === false) {
                try { $d = new DateTime($fromDateRaw); } catch (Exception $e) { $d = false; }
            }
            if ($d !== false) $fromDate = $d->format('Y-m-d 00:00:00');
            else { http_response_code(400); echo json_encode(["error"=>"Invalid from_date"]); return; }
        }
        if (!empty($toDateRaw)) {
            $d = DateTime::createFromFormat('Y-m-d', $toDateRaw);
            if ($d === false) {
                try { $d = new DateTime($toDateRaw); } catch (Exception $e) { $d = false; }
            }
            if ($d !== false) $toDate = $d->format('Y-m-d') . ' 23:59:59';
            else { http_response_code(400); echo json_encode(["error"=>"Invalid to_date"]); return; }
        }
    }

    // optional filters
    $paymentStatus = !empty($params['payment_status']) ? trim((string)$params['payment_status']) : null;
    $tableIdFilter = !empty($params['table_id']) ? (int)$params['table_id'] : null;

    // pagination
    $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
    $perPage = isset($params['per_page']) ? min(1000, max(1, (int)$params['per_page'])) : 1000;
    $offset = ($page - 1) * $perPage;

    try {
        // fetch order_data for the hotel's orders in range
        $where = "WHERE oh.hotel_id = :hotel_id";
        $binds = [':hotel_id' => $hotelId];
        if ($fromDate !== null) { $where .= " AND oh.created_at >= :from_date"; $binds[':from_date'] = $fromDate; }
        if ($toDate !== null) { $where .= " AND oh.created_at <= :to_date"; $binds[':to_date'] = $toDate; }
        if ($paymentStatus !== null) { $where .= " AND oh.payment_status = :payment_status"; $binds[':payment_status'] = $paymentStatus; }
        if ($tableIdFilter !== null) { $where .= " AND oh.table_id = :table_id"; $binds[':table_id'] = $tableIdFilter; }

        $sql = "SELECT oh.order_data FROM orderhistory oh $where";
        $stmt = $conn->prepare($sql);
        foreach ($binds as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Aggregate in PHP
        $agg = [];
        foreach ($rows as $r) {
            if (empty($r['order_data'])) continue;
            $decoded = json_decode($r['order_data'], true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) continue;

            // normalize list
            $list = [];
            if (isset($decoded['orders']) && is_array($decoded['orders'])) $list = $decoded['orders'];
            elseif (isset($decoded['items']) && is_array($decoded['items'])) $list = $decoded['items'];
            elseif (is_array($decoded)) {
                // if it looks like a list
                $val0 = array_values($decoded)[0] ?? null;
                if (is_array($val0) && (isset($val0['name']) || isset($val0['MenuName']))) $list = $decoded;
                else $list = $decoded['orders'] ?? $decoded;
            }
            if (!is_array($list)) continue;

            foreach ($list as $it) {
                if (!is_array($it)) continue;
                $qty = 1;
                if (isset($it['quantity'])) $qty = floatval($it['quantity']);
                elseif (isset($it['Quantity'])) $qty = floatval($it['Quantity']);
                elseif (isset($it['qty'])) $qty = floatval($it['qty']);

                $menu_id = null;
                if (isset($it['menu_id'])) $menu_id = $it['menu_id'];
                elseif (isset($it['MenuId'])) $menu_id = $it['MenuId'];
                elseif (isset($it['id'])) $menu_id = $it['id'];

                $name = $it['name'] ?? $it['MenuName'] ?? $it['menu_name'] ?? $it['title'] ?? null;
                $price = null;
                if (isset($it['price'])) $price = floatval($it['price']);
                elseif (isset($it['menu_price'])) $price = floatval($it['menu_price']);

                $key = $menu_id !== null ? 'id:' . $menu_id : 'name:' . ($name ?? '_unknown_');
                if (!isset($agg[$key])) {
                    $agg[$key] = [
                        'menu_id' => $menu_id !== null ? (is_numeric($menu_id) ? (int)$menu_id : $menu_id) : null,
                        'name' => $name ?? null,
                        'quantity' => 0,
                        'price' => $price !== null ? $price : null,
                    ];
                }
                $agg[$key]['quantity'] += $qty;
                if ($agg[$key]['price'] === null && $price !== null) $agg[$key]['price'] = $price;
            }
        }

        // convert & sort by quantity desc
        $out = array_values($agg);
        usort($out, function($a, $b) { return ($b['quantity'] <=> $a['quantity']); });

        $total = count($out);
        $paged = array_slice($out, $offset, $perPage);

        http_response_code(200);
        echo json_encode([
            'items' => $paged,
            'total_count' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'from_date' => $fromDate ?? null,
            'to_date' => $toDate ?? null,
        ]);
        return;

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

/* Utility: readParams() - same as your orderHistory.php helper.
   If you don't have it available in this file, add this helper:
*/
function readParams() {
    $params = $_GET ?? [];
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (is_array($body)) $params = array_merge($params, $body);
    return $params;
}
