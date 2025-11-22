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
      case 'POST':
        createOrderHistory($conn, $userID, $userType);
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
        echo json_encode(["error" => "Method not allowed. Use GET/POST/PUT/DELETE."]);
        break;
}




/* ---------------------------
   POST - create order history (bill-first flow)
   Body JSON:
   {
     "table_id": 12,
     "payment_status": "online"|"cash"|...,
     "order_data": { "orders":[...], "total_cost":..., "cgst_percentage":..., "sgst_percentage":..., "final_amount":... },
     "taken_by_id": ..., "taken_by_role": ...
   }
*/
function createOrderHistory($conn, $userID, $userType) {
    global $payload;

    // owners and users are allowed to create (same as payBill/addOrderHistory)
    if ($userType !== 'owner' && $userType !== 'user') {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or users can create order history (bill-first)"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid JSON body"]);
        return;
    }

    if (!isset($data['table_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "table_id is required"]);
        return;
    }

    if (!isset($data['order_data']) || !is_array($data['order_data'])) {
        http_response_code(400);
        echo json_encode(["error" => "order_data (object) is required for bill-first flow"]);
        return;
    }

    $tableId = (int)$data['table_id'];
    $orderData = $data['order_data'];
    $paymentStatus = isset($data['payment_status']) ? trim((string)$data['payment_status']) : 'paid';
    $takenById = $data['taken_by_id'] ?? ($userID ?? null);
    $takenByRole = $data['taken_by_role'] ?? ($userType ?? null);

    $cgstPct = isset($data['cgst_percentage']) ? floatval($data['cgst_percentage']) : null;
    $sgstPct = isset($data['sgst_percentage']) ? floatval($data['sgst_percentage']) : null;
    $clientTotalCost = isset($data['final_amount']) ? floatval($data['final_amount']) : null;
    $clientFinalAmount = isset($data['final_amount']) ? floatval($data['final_amount']) : null;

    try {
        // Lock table and get category hotel id (same as payBill)
        $sql = "SELECT t.*, c.hotel_id AS category_hotel_id
                FROM tables t
                JOIN categories c ON t.category_id = c.category_id
                WHERE t.table_id = :table_id
                FOR UPDATE";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':table_id', $tableId, PDO::PARAM_INT);
        $stmt->execute();
        $table = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$table) {
            http_response_code(404);
            echo json_encode(["error" => "Table not found"]);
            return;
        }

        // resolve hotel_id
        $hotelIdFromToken = $payload['hotel_id'] ?? ($payload['hotelId'] ?? null);
        $hotelIdToUse = $hotelIdFromToken ?? ($table['category_hotel_id'] ?? null);
        $usedFallbackToUserId = false;
        if (empty($hotelIdToUse)) {
            $hotelIdToUse = $userID;
            $usedFallbackToUserId = true;
        }

        // validate hotel exists
        $chk = $conn->prepare("SELECT hotel_id FROM hotels WHERE hotel_id = :hotel_id");
        $chk->bindValue(':hotel_id', $hotelIdToUse);
        $chk->execute();
        $hotelRow = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$hotelRow) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid hotel_id", "message" => "Resolved hotel_id does not exist"]);
            return;
        }

        // compute totals from order_data if not provided
        $ordersList = [];
        if (isset($orderData['orders']) && is_array($orderData['orders'])) {
            $ordersList = $orderData['orders'];
        } elseif (isset($orderData['items']) && is_array($orderData['items'])) {
            $ordersList = $orderData['items'];
        } elseif (is_array($orderData)) {
            $ordersList = $orderData['orders'] ?? $orderData;
        }

        $calcTotal = 0.0;
        foreach ($ordersList as $it) {
            $qty = isset($it['quantity']) ? floatval($it['quantity']) : 1.0;
            $price = 0.0;
            if (isset($it['price'])) $price = floatval($it['price']);
            elseif (isset($it['menu_price'])) $price = floatval($it['menu_price']);
            $calcTotal += ($price * $qty);
        }
        $baseTotal = $clientTotalCost !== null ? $clientTotalCost : $calcTotal;

        // GST: prefer passed values; otherwise read from settings table for this hotel
        if ($cgstPct === null || $sgstPct === null) {
            $sstmt = $conn->prepare("SELECT gst_enabled, cgst_percentage, sgst_percentage FROM settings WHERE hotel_id = :hotel_id LIMIT 1");
            $sstmt->bindValue(':hotel_id', $hotelIdToUse, PDO::PARAM_INT);
            $sstmt->execute();
            $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
            if ($srow) {
                if ($cgstPct === null) $cgstPct = floatval($srow['cgst_percentage'] ?? 0);
                if ($sgstPct === null) $sgstPct = floatval($srow['sgst_percentage'] ?? 0);
                if ((int)($srow['gst_enabled'] ?? 0) !== 1) {
                    $cgstPct = 0.0;
                    $sgstPct = 0.0;
                }
            } else {
                if ($cgstPct === null) $cgstPct = 0.0;
                if ($sgstPct === null) $sgstPct = 0.0;
            }
        }

        $gstRate = (($cgstPct ?? 0.0) + ($sgstPct ?? 0.0)) / 100.0;
        $gstAmount = round($baseTotal * $gstRate, 2);
        $finalAmount = $clientFinalAmount !== null ? $clientFinalAmount : round($baseTotal + $gstAmount, 2);

        if ($clientFinalAmount !== null && abs($clientFinalAmount - $finalAmount) > 0.5) {
            http_response_code(400);
            echo json_encode([
                "error" => "final_amount_mismatch",
                "message" => "Client final_amount doesn't match server calculation. Provided: {$clientFinalAmount}, Calculated: {$finalAmount}"
            ]);
            return;
        }

        $conn->beginTransaction();

        $orderJson = is_string($orderData) ? $orderData : json_encode($orderData);

        // Insert into orderhistory table
        $insertSql = "INSERT INTO orderhistory
                (hotel_id, table_id, split_order_id, is_split, order_data, final_amount, payment_status, taken_by_id, taken_by_role, created_at, updated_at)
                VALUES (:hotel_id, :table_id, NULL, 0, :order_data, :final_amount, :payment_status, :taken_by_id, :taken_by_role, NOW(), NOW())";
        $insStmt = $conn->prepare($insertSql);
        $insStmt->bindValue(':hotel_id', $hotelIdToUse, PDO::PARAM_INT);
        $insStmt->bindValue(':table_id', $tableId, PDO::PARAM_INT);
        $insStmt->bindValue(':order_data', $orderJson, PDO::PARAM_STR);
        $insStmt->bindValue(':final_amount', $finalAmount);
        $insStmt->bindValue(':payment_status', $paymentStatus, PDO::PARAM_STR);
        $insStmt->bindValue(':taken_by_id', $takenById !== null ? $takenById : null);
        $insStmt->bindValue(':taken_by_role', $takenByRole !== null ? $takenByRole : null);

        if (!$insStmt->execute()) {
            $conn->rollBack();
            $err = $insStmt->errorInfo();
            http_response_code(500);
            echo json_encode(["error" => "Error inserting order history", "db_error" => $err]);
            return;
        }
        $historyId = $conn->lastInsertId();

        // Reset table to available (bill-first)
        $updateSql = "UPDATE tables SET 
                        table_status = 'available',
                        order_data = NULL,
                        split_order_data = NULL,
                        is_split = 0,
                        final_amount = NULL,
                        taken_by_id = NULL,
                        taken_by_role = NULL,
                        updated_at = NOW()
                      WHERE table_id = :table_id";
        $updStmt = $conn->prepare($updateSql);
        $updStmt->bindValue(':table_id', $tableId, PDO::PARAM_INT);
        if (!$updStmt->execute()) {
            $conn->rollBack();
            $err = $updStmt->errorInfo();
            http_response_code(500);
            echo json_encode(["error" => "Error resetting table after creating order history", "db_error" => $err]);
            return;
        }

        $conn->commit();

        $resp = [
            "message" => "Bill-first order recorded and payment processed",
            "order_history_id" => $historyId,
            "table_status" => "available",
            "charged_amount" => $finalAmount,
            "cgst_percentage" => $cgstPct,
            "sgst_percentage" => $sgstPct,
            "gst_amount" => $gstAmount,
        ];
        if ($usedFallbackToUserId) {
            $resp['warning'] = "hotel_id was not present in token or categories; used owner_id as fallback.";
        }
        http_response_code(201);
        echo json_encode($resp);
        return;

    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
        return;
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        http_response_code(500);
        echo json_encode(["error" => "Server error", "message" => $e->getMessage()]);
        return;
    }
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

    // ownership check (unchanged)
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

    // -----------------------
    // DETAIL BRANCH: if client requests a specific order_history_id, return single object immediately
    // -----------------------
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
            'final_amount' => (float)$row['final_amount'],
            'payment_status' => $row['payment_status'],
            'taken_by_id' => $row['taken_by_id'],
            'taken_by_role' => $row['taken_by_role'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ]);
        return;
    }

    // Optional filters
    $fromDateRaw = isset($params['from_date']) ? trim((string)$params['from_date']) : null;
    $toDateRaw = isset($params['to_date']) ? trim((string)$params['to_date']) : null;
    $paymentStatus = isset($params['payment_status']) ? trim((string)$params['payment_status']) : null;
    $tableIdFilter = isset($params['table_id']) ? (int)$params['table_id'] : null;

    // Get hotel's day_start_time from settings (fallback to 00:00:00)
    $dayStartTime = '00:00:00';
    try {
        $sstmt = $conn->prepare("SELECT day_start_time FROM settings WHERE hotel_id = :hotel_id LIMIT 1");
        $sstmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $sstmt->execute();
        $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
        if ($srow && !empty($srow['day_start_time'])) {
            $dayStartTime = $srow['day_start_time'];
        }
    } catch (Exception $e) {
        // ignore and use default dayStartTime
    }

    // Helper: produce Y-m-d H:i:s from a Y-m-d date + day_start_time
    $makeDateTime = function($dateOnly) use ($dayStartTime) {
        return DateTime::createFromFormat('Y-m-d H:i:s', $dateOnly . ' ' . $dayStartTime);
    };

    // Normalize/validate dates & compute actual window used
    $fromDateTime = null;
    $toDateTime = null;
    date_default_timezone_set(@date_default_timezone_get() ?: 'UTC'); // rely on server TZ (adjust if needed)

    if (!empty($fromDateRaw) || !empty($toDateRaw)) {
        // If user supplied explicit date(s) -> treat them as hotel-days starting at day_start_time
        if (!empty($fromDateRaw)) {
            $d = DateTime::createFromFormat('Y-m-d', $fromDateRaw);
            if ($d === false) {
                // try generic parse
                try { $d = new DateTime($fromDateRaw); } catch (Exception $e) { $d = false; }
            }
            if ($d !== false) {
                // set to date + day_start_time
                $fromDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $d->format('Y-m-d') . ' ' . $dayStartTime);
                if ($fromDateTime === false) {
                    http_response_code(400);
                    echo json_encode(["error" => "Invalid from_date format"]);
                    return;
                }
            } else {
                http_response_code(400);
                echo json_encode(["error" => "Invalid from_date. Use YYYY-MM-DD or ISO datetime."]);
                return;
            }
        }

        if (!empty($toDateRaw)) {
            $d = DateTime::createFromFormat('Y-m-d', $toDateRaw);
            if ($d === false) {
                try { $d = new DateTime($toDateRaw); } catch (Exception $e) { $d = false; }
            }
            if ($d !== false) {
                // to_date is inclusive of that hotel-day, so to = to_date + day_start_time + 1 day -1 second
                $tmp = DateTime::createFromFormat('Y-m-d H:i:s', $d->format('Y-m-d') . ' ' . $dayStartTime);
                if ($tmp === false) {
                    http_response_code(400);
                    echo json_encode(["error" => "Invalid to_date format"]);
                    return;
                }
                $toDateTime = clone $tmp;
                $toDateTime->modify('+1 day')->modify('-1 second');
            } else {
                http_response_code(400);
                echo json_encode(["error" => "Invalid to_date. Use YYYY-MM-DD or ISO datetime."]);
                return;
            }
        }
    } else {
        // No explicit from/to -> default to current hotel-day that contains "now"
        $now = new DateTime();
        $todayStart = DateTime::createFromFormat('Y-m-d H:i:s', $now->format('Y-m-d') . ' ' . $dayStartTime);
        if ($todayStart === false) {
            // fallback to midnight if dayStart parse fails
            $todayStart = new DateTime($now->format('Y-m-d') . ' 00:00:00');
        }
        if ($now < $todayStart) {
            // current time is before today's day start — use yesterday's start
            $fromDateTime = clone $todayStart;
            $fromDateTime->modify('-1 day');
        } else {
            $fromDateTime = $todayStart;
        }
        $toDateTime = clone $fromDateTime;
        $toDateTime->modify('+1 day')->modify('-1 second');
    }

    // If only one bound provided - ensure both (rare but safe)
    if ($fromDateTime === null && $toDateTime !== null) {
        // set from as to - 24h + 1s
        $fromDateTime = clone $toDateTime;
        $fromDateTime->modify('-1 day')->modify('+1 second');
    } elseif ($toDateTime === null && $fromDateTime !== null) {
        $toDateTime = clone $fromDateTime;
        $toDateTime->modify('+1 day')->modify('-1 second');
    }

    // list: pagination (same defaults)
    $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
    $perPage = isset($params['per_page']) ? min(100, max(1, (int)$params['per_page'])) : 10;
    $offset = ($page - 1) * $perPage;

    try {
        // Build WHERE with optional filters (safe prepared params)
        $where = "WHERE oh.hotel_id = :hotel_id";
        $binds = [':hotel_id' => $hotelId];

        if ($fromDateTime !== null) {
            $where .= " AND oh.created_at >= :from_date";
            $binds[':from_date'] = $fromDateTime->format('Y-m-d H:i:s');
        }
        if ($toDateTime !== null) {
            $where .= " AND oh.created_at <= :to_date";
            $binds[':to_date'] = $toDateTime->format('Y-m-d H:i:s');
        }
        if (!empty($paymentStatus)) {
            $where .= " AND oh.payment_status = :payment_status";
            $binds[':payment_status'] = $paymentStatus;
        }
        if (!empty($tableIdFilter)) {
            $where .= " AND oh.table_id = :table_id";
            $binds[':table_id'] = $tableIdFilter;
        }

        // count
        $countSql = "SELECT COUNT(*) AS total FROM orderhistory oh $where";
        $cstmt = $conn->prepare($countSql);
        foreach ($binds as $k => $v) $cstmt->bindValue($k, $v);
        $cstmt->execute();
        $totalRow = $cstmt->fetch(PDO::FETCH_ASSOC);
        $totalCount = (int)($totalRow['total'] ?? 0);

        // total collection sum for same window
        $sumSql = "SELECT COALESCE(SUM(oh.final_amount),0) AS total_collection FROM orderhistory oh $where";
        $sstmt2 = $conn->prepare($sumSql);
        foreach ($binds as $k => $v) $sstmt2->bindValue($k, $v);
        $sstmt2->execute();
        $sumRow = $sstmt2->fetch(PDO::FETCH_ASSOC);
        $totalCollection = (float)($sumRow['total_collection'] ?? 0.0);

        // fetch rows
        $sql = "SELECT oh.order_history_id, oh.hotel_id, oh.table_id, t.table_number, oh.is_split, oh.split_order_id,
                       oh.final_amount, oh.payment_status, oh.created_at, oh.updated_at, oh.order_data
                FROM orderhistory oh
                LEFT JOIN tables t ON oh.table_id = t.table_id
                $where
                ORDER BY oh.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        // bind dynamic first
        foreach ($binds as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // build lightweight list (unchanged)
        $items = [];
        foreach ($rows as $r) {
            $first_item = null;
            $items_count = 0;
            $final_amount = $r['final_amount'];
            if (!empty($r['order_data'])) {
                $decoded = json_decode($r['order_data'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $list = $decoded['orders'] ?? $decoded['items'] ?? [];
                    if (is_array($list) && count($list) > 0) {
                        $first_item = $list[0]['name'] ?? $list[0]['MenuName'] ?? null;
                        $items_count = count($list);
                    }
                    $final_amount = $decoded['final_amount'] ?? $decoded['final_amount'] ?? $final_amount;
                }
            }
            $items[] = [
                'order_history_id' => (int)$r['order_history_id'],
                'table_id' => (int)$r['table_id'],
                'table_number' => $r['table_number'],
                'is_split' => (int)$r['is_split'],
                'split_order_id' => $r['split_order_id'] !== null ? (int)$r['split_order_id'] : null,
                'final_amount' => (float)$r['final_amount'],
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
            "total_collection" => $totalCollection,
            "page" => $page,
            "per_page" => $perPage,
            "from_datetime" => $fromDateTime ? $fromDateTime->format('Y-m-d H:i:s') : null,
            "to_datetime" => $toDateTime ? $toDateTime->format('Y-m-d H:i:s') : null,
            "day_start_time" => $dayStartTime
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
        $hchk = $conn->prepare("SELECT owner_id FROM hotels WHERE hotel_id = :hotel_id LIMIT 1");
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

    $allowed = ['order_data', 'final_amount', 'payment_status', 'taken_by_id', 'taken_by_role'];
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
        $hchk = $conn->prepare("SELECT owner_id FROM hotels WHERE hotel_id = :hotel_id LIMIT 1");
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
