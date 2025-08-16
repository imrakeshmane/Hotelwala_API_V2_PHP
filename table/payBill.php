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

// Validate the JWT token
$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid token"]);
    return;
}

$userID = $payload['owner_id'] ?? null;
$userType = $payload['user_type'] ?? null;

if ($requestMethod === 'POST') {
    payBill($conn, $userID, $userType, $payload);
} else {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
}

function payBill($conn, $userID, $userType, $payload) {
    if ($userType !== 'owner' && $userType !== 'user') {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or users can pay the bill"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['table_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "Table ID is required"]);
        return;
    }

    $tableId = $data['table_id'];
    $requestIsSplit = isset($data['is_split']) && ($data['is_split'] === true || $data['is_split'] === 1);
    $requestedSplitOrderId = $requestIsSplit ? ($data['split_order_id'] ?? null) : null;

    if ($requestIsSplit && $requestedSplitOrderId === null) {
        http_response_code(400);
        echo json_encode(["error" => "split_order_id is required for split payments"]);
        return;
    }

    try {
        // Fetch table joined to categories to get hotel_id (and lock row)
        // Tables doesn't directly store hotel_id in your schema; hotel is reachable via Categories.hotel_id
        $sql = "SELECT t.*, c.hotel_id AS category_hotel_id
                FROM Tables t
                JOIN Categories c ON t.category_id = c.category_id
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

        // Determine hotel_id priority:
        // 1) from JWT payload if present (common key names: hotel_id or hotelId)
        // 2) category_hotel_id (derived from Categories)
        // 3) as last resort fallback to owner id (with warning) - optional and discouraged if owners manage multiple hotels
        $hotelIdFromToken = $payload['hotel_id'] ?? ($payload['hotelId'] ?? null);
        $hotelIdToUse = $hotelIdFromToken ?? ($table['category_hotel_id'] ?? null);
        $usedFallbackToUserId = false;

        if (empty($hotelIdToUse)) {
            // last resort fallback — use with caution
            $hotelIdToUse = $userID;
            $usedFallbackToUserId = true;
        }

        // Make sure hotel_id exists in Hotels table to avoid FK violation
        $chk = $conn->prepare("SELECT hotel_id FROM Hotels WHERE hotel_id = :hotel_id");
        $chk->bindValue(':hotel_id', $hotelIdToUse);
        $chk->execute();
        $hotelRow = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$hotelRow) {
            http_response_code(400);
            echo json_encode([
                "error" => "Invalid hotel_id",
                "message" => "hotel_id resolved to '{$hotelIdToUse}' but no such hotel exists. Provide a valid hotel_id in token or fix Categories/Hotels data."
            ]);
            return;
        }

        // If table already available, nothing to pay
        if (isset($table['table_status']) && $table['table_status'] === 'available') {
            http_response_code(400);
            echo json_encode(["error" => "Table is already available"]);
            return;
        }

        // Begin transaction
        $conn->beginTransaction();

        $isSplitInTable = isset($table['is_split']) && ($table['is_split'] == 1 || $table['is_split'] === true);

        // ---------- CASE 1: Normal (non-split) payment ----------
        if (!$isSplitInTable || !$requestIsSplit) {
            if (empty($table['order_data'])) {
                if ($isSplitInTable) {
                    $conn->rollBack();
                    http_response_code(400);
                    echo json_encode(["error" => "Table has split orders; to pay a split provide is_split=true and split_order_id"]);
                    return;
                } else {
                    $conn->rollBack();
                    http_response_code(400);
                    echo json_encode(["error" => "No order_data present for this table"]);
                    return;
                }
            }

            $insertSql = "INSERT INTO OrderHistory
                (hotel_id, table_id, split_order_id, is_split, order_data, total_cost, payment_status, taken_by_id, taken_by_role, created_at, updated_at)
                VALUES (:hotel_id, :table_id, NULL, 0, :order_data, :total_cost, 'paid', :taken_by_id, :taken_by_role, NOW(), NOW())";
            $insStmt = $conn->prepare($insertSql);

            $insStmt->bindValue(':hotel_id', $hotelIdToUse, PDO::PARAM_INT);
            $insStmt->bindValue(':table_id', $tableId, PDO::PARAM_INT);
            $insStmt->bindValue(':order_data', $table['order_data'] !== null ? $table['order_data'] : null, PDO::PARAM_STR);
            $insStmt->bindValue(':total_cost', isset($table['total_cost']) ? $table['total_cost'] : null);
            $insStmt->bindValue(':taken_by_id', isset($table['taken_by_id']) ? $table['taken_by_id'] : null);
            $insStmt->bindValue(':taken_by_role', isset($table['taken_by_role']) ? $table['taken_by_role'] : null);

            if (!$insStmt->execute()) {
                $conn->rollBack();
                $err = $insStmt->errorInfo();
                http_response_code(500);
                echo json_encode(["error" => "Error moving order to history", "db_error" => $err]);
                return;
            }
            $historyId = $conn->lastInsertId();

            // Reset table to defaults
            $updateSql = "UPDATE Tables SET 
                            table_status = 'available',
                            order_data = NULL,
                            split_order_data = NULL,
                            is_split = 0,
                            total_cost = NULL,
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
                echo json_encode(["error" => "Error resetting table", "db_error" => $err]);
                return;
            }

            $conn->commit();

            $resp = [
                "message" => "Bill paid and table reset successfully",
                "order_history_id" => $historyId,
                "table_status" => "available"
            ];
            if ($usedFallbackToUserId) {
                $resp['warning'] = "hotel_id was not present in token or categories; used owner_id as fallback. Consider adding hotel_id to token or fixing Categories/Hotels data.";
            }
            http_response_code(200);
            echo json_encode($resp);
            return;
        }

        // ---------- CASE 2: Split payment ----------
        if (empty($table['split_order_data'])) {
            $conn->rollBack();
            http_response_code(400);
            echo json_encode(["error" => "No split_order_data present for this table"]);
            return;
        }

        $splitData = json_decode($table['split_order_data'], true);
        if (!is_array($splitData)) {
            $conn->rollBack();
            http_response_code(500);
            echo json_encode(["error" => "split_order_data is malformed"]);
            return;
        }

        // Find requested split
        $foundIndex = null;
        $foundSplit = null;
        foreach ($splitData as $idx => $s) {
            if (isset($s['split_order_id']) && $s['split_order_id'] == $requestedSplitOrderId) {
                $foundIndex = $idx;
                $foundSplit = $s;
                break;
            }
        }

        if ($foundIndex === null) {
            $conn->rollBack();
            http_response_code(404);
            echo json_encode(["error" => "Split order with given split_order_id not found"]);
            return;
        }

        // Insert this split into OrderHistory
        $insertSql = "INSERT INTO OrderHistory
                (hotel_id, table_id, split_order_id, is_split, order_data, total_cost, payment_status, taken_by_id, taken_by_role, created_at, updated_at)
                VALUES (:hotel_id, :table_id, :split_order_id, 1, :order_data, :total_cost, 'paid', :taken_by_id, :taken_by_role, NOW(), NOW())";
        $insStmt = $conn->prepare($insertSql);

        $splitOrderJson = json_encode($foundSplit);
        $splitTotalCost = isset($foundSplit['total_cost']) ? $foundSplit['total_cost'] : null;

        $insStmt->bindValue(':hotel_id', $hotelIdToUse, PDO::PARAM_INT);
        $insStmt->bindValue(':table_id', $tableId, PDO::PARAM_INT);
        $insStmt->bindValue(':split_order_id', $requestedSplitOrderId);
        $insStmt->bindValue(':order_data', $splitOrderJson, PDO::PARAM_STR);
        $insStmt->bindValue(':total_cost', $splitTotalCost);
        $insStmt->bindValue(':taken_by_id', isset($table['taken_by_id']) ? $table['taken_by_id'] : null);
        $insStmt->bindValue(':taken_by_role', isset($table['taken_by_role']) ? $table['taken_by_role'] : null);

        if (!$insStmt->execute()) {
            $conn->rollBack();
            $err = $insStmt->errorInfo();
            http_response_code(500);
            echo json_encode(["error" => "Error moving split order to history", "db_error" => $err]);
            return;
        }
        $historyId = $conn->lastInsertId();

        // Remove this split from the table's split_order_data
        array_splice($splitData, $foundIndex, 1);

        if (count($splitData) === 0) {
            // No more split orders remain - reset table to available
            $updateSql = "UPDATE Tables SET 
                            table_status = 'available',
                            split_order_data = NULL,
                            order_data = NULL,
                            is_split = 0,
                            total_cost = NULL,
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
                echo json_encode(["error" => "Error resetting table after removing last split", "db_error" => $err]);
                return;
            }
            $conn->commit();
            $resp = [
                "message" => "Split paid and table reset to available (last split removed)",
                "order_history_id" => $historyId,
                "table_status" => "available"
            ];
            if ($usedFallbackToUserId) {
                $resp['warning'] = "hotel_id was not present in token or categories; used owner_id as fallback.";
            }
            http_response_code(200);
            echo json_encode($resp);
            return;
        } else {
            // Some splits remain - update split_order_data and recalc total_cost
            $newSplitJson = json_encode($splitData);
            $newTotal = 0;
            foreach ($splitData as $s) {
                if (isset($s['total_cost'])) {
                    $newTotal += (float)$s['total_cost'];
                } elseif (isset($s['items']) && is_array($s['items'])) {
                    foreach ($s['items'] as $it) {
                        $qty = isset($it['quantity']) ? (float)$it['quantity'] : 1;
                        $price = isset($it['price']) ? (float)$it['price'] : 0;
                        $newTotal += $qty * $price;
                    }
                }
            }

            $updateSql = "UPDATE Tables SET 
                            split_order_data = :split_order_data,
                            total_cost = :total_cost,
                            is_split = 1,
                            updated_at = NOW()
                          WHERE table_id = :table_id";
            $updStmt = $conn->prepare($updateSql);
            $updStmt->bindValue(':split_order_data', $newSplitJson, PDO::PARAM_STR);
            $updStmt->bindValue(':total_cost', $newTotal);
            $updStmt->bindValue(':table_id', $tableId, PDO::PARAM_INT);

            if (!$updStmt->execute()) {
                $conn->rollBack();
                $err = $updStmt->errorInfo();
                http_response_code(500);
                echo json_encode(["error" => "Error updating table after removing split", "db_error" => $err]);
                return;
            }

            $conn->commit();
            $resp = [
                "message" => "Split paid and removed from table (remaining splits updated)",
                "order_history_id" => $historyId,
                "table_status" => "occupied",
                "remaining_total_cost" => $newTotal
            ];
            if ($usedFallbackToUserId) {
                $resp['warning'] = "hotel_id was not present in token or categories; used owner_id as fallback.";
            }
            http_response_code(200);
            echo json_encode($resp);
            return;
        }
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
?>
