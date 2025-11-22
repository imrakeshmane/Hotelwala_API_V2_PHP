<?php
// runningItems.php
// Aggregates menu sold counts for a hotel over a hotel-day window (supports hotel day_start_time).
// Place this file in your API folder. Requires ../db.php and ../validate.php as in your other endpoints.

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
        break;
}

/**
 * GET /runningItems.php?hotel_id=123&from_date=YYYY-MM-DD&to_date=YYYY-MM-DD&page=1&per_page=100&sort=high
 */
function getRunningItems($conn, $userID, $userType) {
    $params = readParams();

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

    // Optional user-provided dates (Y-m-d). We'll treat them as *hotel-day* dates (see day_start_time).
    $fromDateRaw = isset($params['from_date']) ? trim((string)$params['from_date']) : null;
    $toDateRaw = isset($params['to_date']) ? trim((string)$params['to_date']) : null;

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
        // ignore - use default dayStartTime
    }

    // Compute fromDateTime and toDateTime (Y-m-d H:i:s strings)
    $fromDateTime = null;
    $toDateTime = null;
    date_default_timezone_set(@date_default_timezone_get() ?: 'UTC'); // rely on server TZ

    if (!empty($fromDateRaw) || !empty($toDateRaw)) {
        if (!empty($fromDateRaw)) {
            $d = DateTime::createFromFormat('Y-m-d', $fromDateRaw);
            if ($d === false) {
                try { $d = new DateTime($fromDateRaw); } catch (Exception $e) { $d = false; }
            }
            if ($d !== false) {
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
                $tmp = DateTime::createFromFormat('Y-m-d H:i:s', $d->format('Y-m-d') . ' ' . $dayStartTime);
                if ($tmp === false) {
                    http_response_code(400);
                    echo json_encode(["error" => "Invalid to_date format"]);
                    return;
                }
                // inclusive to end of hotel-day
                $toDateTime = clone $tmp;
                $toDateTime->modify('+1 day')->modify('-1 second');
            } else {
                http_response_code(400);
                echo json_encode(["error" => "Invalid to_date. Use YYYY-MM-DD or ISO datetime."]);
                return;
            }
        }
    } else {
        // Default to current hotel-day containing now
        $now = new DateTime();
        $todayStart = DateTime::createFromFormat('Y-m-d H:i:s', $now->format('Y-m-d') . ' ' . $dayStartTime);
        if ($todayStart === false) {
            $todayStart = new DateTime($now->format('Y-m-d') . ' 00:00:00');
        }
        if ($now < $todayStart) {
            $fromDateTime = clone $todayStart;
            $fromDateTime->modify('-1 day');
        } else {
            $fromDateTime = $todayStart;
        }
        $toDateTime = clone $fromDateTime;
        $toDateTime->modify('+1 day')->modify('-1 second');
    }

    // If only one bound provided, ensure a 24h window
    if ($fromDateTime === null && $toDateTime !== null) {
        $fromDateTime = clone $toDateTime;
        $fromDateTime->modify('-1 day')->modify('+1 second');
    } elseif ($toDateTime === null && $fromDateTime !== null) {
        $toDateTime = clone $fromDateTime;
        $toDateTime->modify('+1 day')->modify('-1 second');
    }

    // optional filters
    $paymentStatus = !empty($params['payment_status']) ? trim((string)$params['payment_status']) : null;
    $tableIdFilter = !empty($params['table_id']) ? (int)$params['table_id'] : null;

    // server-side sort option: 'high'|'low'|'alpha' (alpha maps to alpha asc)
    $sort = isset($params['sort']) ? trim((string)$params['sort']) : 'high';
    if ($sort === 'alpha') $sort = 'alpha_asc';

    // pagination
    $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
    $perPage = isset($params['per_page']) ? min(1000, max(1, (int)$params['per_page'])) : 1000;
    $offset = ($page - 1) * $perPage;

    try {
        // fetch order_data JSON rows in the computed window
        $where = "WHERE oh.hotel_id = :hotel_id";
        $binds = [':hotel_id' => $hotelId];
        if ($fromDateTime !== null) { $where .= " AND oh.created_at >= :from_date"; $binds[':from_date'] = $fromDateTime->format('Y-m-d H:i:s'); }
        if ($toDateTime !== null)   { $where .= " AND oh.created_at <= :to_date";   $binds[':to_date']   = $toDateTime->format('Y-m-d H:i:s'); }
        if ($paymentStatus !== null) { $where .= " AND oh.payment_status = :payment_status"; $binds[':payment_status'] = $paymentStatus; }
        if ($tableIdFilter !== null) { $where .= " AND oh.table_id = :table_id"; $binds[':table_id'] = $tableIdFilter; }

        $sql = "SELECT oh.order_data FROM orderhistory oh $where";
        $stmt = $conn->prepare($sql);
        foreach ($binds as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Aggregate from order_data JSON
        $agg = [];
        foreach ($rows as $r) {
            if (empty($r['order_data'])) continue;
            $decoded = json_decode($r['order_data'], true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) continue;

            // normalize list within order_data
            $list = [];
            if (isset($decoded['orders']) && is_array($decoded['orders'])) $list = $decoded['orders'];
            elseif (isset($decoded['items']) && is_array($decoded['items'])) $list = $decoded['items'];
            elseif (is_array($decoded)) {
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

        // Build lookup maps from aggregate
        $aggById = [];
        $aggByName = [];
        foreach ($agg as $v) {
            if (!empty($v['menu_id'])) $aggById[(string)$v['menu_id']] = $v;
            elseif (!empty($v['name'])) $aggByName[(string)$v['name']] = $v;
        }

        // Fetch all menus to include zero-counts.
        // Use the actual columns from your schema: menu_name, menu_price
        $menuSql = "SELECT menu_id, menu_name AS name, menu_price AS price FROM menus WHERE hotel_id = :hotel_id";
        $mstmt = $conn->prepare($menuSql);
        $mstmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $mstmt->execute();
        $menuRows = $mstmt->fetchAll(PDO::FETCH_ASSOC);

        $final = [];
        // Merge menus first (so all menus appear)
        foreach ($menuRows as $mr) {
            $mid = $mr['menu_id'];
            $mname = strlen(trim((string)$mr['name'])) ? $mr['name'] : null;
            $mprice = $mr['price'] !== null ? (float)$mr['price'] : null;

            $qty = 0;
            $aggPrice = null;

            if ($mid !== null && isset($aggById[(string)$mid])) {
                $qty = (int)$aggById[(string)$mid]['quantity'];
                $aggPrice = $aggById[(string)$mid]['price'] ?? null;
                unset($aggById[(string)$mid]);
            } elseif ($mname !== null && isset($aggByName[(string)$mname])) {
                $qty = (int)$aggByName[(string)$mname]['quantity'];
                $aggPrice = $aggByName[(string)$mname]['price'] ?? null;
                unset($aggByName[(string)$mname]);
            }

            $final[] = [
                'menu_id' => $mid !== null ? (is_numeric($mid) ? (int)$mid : $mid) : null,
                'name' => $mname,
                'quantity' => $qty,
                'price' => $mprice !== null ? $mprice : ($aggPrice !== null ? (float)$aggPrice : null),
            ];
        }

        // Append any aggregated items that didn't match menus (sold but no menu row)
        foreach ($aggById as $v) {
            $final[] = [
                'menu_id' => $v['menu_id'],
                'name' => $v['name'],
                'quantity' => (int)$v['quantity'],
                'price' => $v['price'] !== null ? (float)$v['price'] : null,
            ];
        }
        foreach ($aggByName as $v) {
            $final[] = [
                'menu_id' => $v['menu_id'],
                'name' => $v['name'],
                'quantity' => (int)$v['quantity'],
                'price' => $v['price'] !== null ? (float)$v['price'] : null,
            ];
        }

        // Sorting options
        if ($sort === 'low') {
            usort($final, function($a, $b) {
                $cmp = $a['quantity'] <=> $b['quantity'];
                return $cmp !== 0 ? $cmp : strcasecmp((string)$a['name'], (string)$b['name']);
            });
        } elseif ($sort === 'alpha_asc') {
            usort($final, function($a, $b) { return strcasecmp((string)$a['name'], (string)$b['name']); });
        } elseif ($sort === 'alpha_desc') {
            usort($final, function($a, $b) { return strcasecmp((string)$b['name'], (string)$a['name']); });
        } else {
            // 'high' or default
            usort($final, function($a, $b) {
                $cmp = $b['quantity'] <=> $a['quantity'];
                return $cmp !== 0 ? $cmp : strcasecmp((string)$a['name'], (string)$b['name']);
            });
        }

        // pagination
        $total = count($final);
        $paged = array_slice($final, $offset, $perPage);

        http_response_code(200);
        echo json_encode([
            'items' => $paged,
            'total_count' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'from_datetime' => $fromDateTime ? $fromDateTime->format('Y-m-d H:i:s') : null,
            'to_datetime' => $toDateTime ? $toDateTime->format('Y-m-d H:i:s') : null,
            'day_start_time' => $dayStartTime,
            'sort' => $sort,
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

/* Utility: readParams() - GET query string and JSON body merge */
function readParams() {
    $params = $_GET ?? [];
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (is_array($body)) $params = array_merge($params, $body);
    return $params;
}
