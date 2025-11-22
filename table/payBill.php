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

// Validate token
$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid token"]);
    return;
}

// support tokens that may use owner_id or user_id
$userID = $payload['user_id'] ?? $payload['owner_id'] ?? null;
$userType = $payload['user_type'] ?? null;

if ($requestMethod !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    return;
}

function safe_float($v) {
    if (!isset($v)) return 0.0;
    if (!is_numeric($v)) return 0.0;
    return floatval($v);
}

try {
    payBill($conn, $userID, $userType, $payload);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Server error", "message" => $e->getMessage()]);
    return;
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

    // Accept any string as payment_status from frontend. Default to 'paid'.
    $paymentStatus = isset($data['payment_status']) ? (string)$data['payment_status'] : 'paid';
    $paymentStatus = trim($paymentStatus);

    try {
        // Fetch table with category join (to determine hotel_id) and lock row
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

        // Resolve hotel_id priority: token -> category -> fallback to userID
        $hotelIdFromToken = $payload['hotel_id'] ?? ($payload['hotelId'] ?? null);
        $hotelIdToUse = $hotelIdFromToken ?? ($table['category_hotel_id'] ?? null);
        $usedFallbackToUserId = false;
        if (empty($hotelIdToUse)) {
            $hotelIdToUse = $userID;
            $usedFallbackToUserId = true;
        }

        // Validate hotel exists
        $chk = $conn->prepare("SELECT hotel_id FROM hotels WHERE hotel_id = :hotel_id");
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

        // Table already available => nothing to pay
        if (isset($table['table_status']) && $table['table_status'] === 'available') {
            http_response_code(400);
            echo json_encode(["error" => "Table is already available"]);
            return;
        }

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

            // decode JSON
            $orderJson = json_decode($table['order_data'], true);
            if (!is_array($orderJson)) {
                $conn->rollBack();
                http_response_code(500);
                echo json_encode(["error" => "order_data malformed"]);
                return;
            }

            // compute/validate amounts server-side
            $sub_total = safe_float($orderJson['sub_total'] ?? 0);
            $cgst_percentage = safe_float($orderJson['cgst_percentage'] ?? $orderJson['cgst_pct'] ?? 0);
            $sgst_percentage = safe_float($orderJson['sgst_percentage'] ?? $orderJson['sgst_pct'] ?? 0);

            $cgst_amount = round($sub_total * ($cgst_percentage / 100.0), 2);
            $sgst_amount = round($sub_total * ($sgst_percentage / 100.0), 2);
            $final_amount = round($sub_total + $cgst_amount + $sgst_amount, 2);

            // attach/overwrite computed fields
            $orderJson['sub_total'] = $sub_total;
            $orderJson['cgst_percentage'] = $cgst_percentage;
            $orderJson['sgst_percentage'] = $sgst_percentage;
            $orderJson['cgst_amount'] = $cgst_amount;
            $orderJson['sgst_amount'] = $sgst_amount;
            $orderJson['final_amount'] = $final_amount;
            $orderDataToStore = json_encode($orderJson);

            // Insert into orderhistory: keep column name total_cost for backward compatibility; store final_amount there
            $insertSql = "INSERT INTO orderhistory
                (hotel_id, table_id, split_order_id, is_split, order_data, final_amount, payment_status, taken_by_id, taken_by_role, created_at, updated_at)
                VALUES (:hotel_id, :table_id, NULL, 0, :order_data, :final_amount, :payment_status, :taken_by_id, :taken_by_role, NOW(), NOW())";
            $insStmt = $conn->prepare($insertSql);

            $insStmt->bindValue(':hotel_id', $hotelIdToUse, PDO::PARAM_INT);
            $insStmt->bindValue(':table_id', $tableId, PDO::PARAM_INT);
            $insStmt->bindValue(':order_data', $orderDataToStore, PDO::PARAM_STR);
            $insStmt->bindValue(':final_amount', $final_amount); // legacy column holds final amount
            $insStmt->bindValue(':payment_status', $paymentStatus, PDO::PARAM_STR);
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

            // Reset table to defaults: clear order_data/split_order_data and clear final_amount column
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

        // Recompute/validate split totals server-side
        $sub_total = safe_float($foundSplit['sub_total'] ?? 0);
        // If items/orders exist but sub_total missing, compute from items array
        if ($sub_total == 0 && !empty($foundSplit['items']) && is_array($foundSplit['items'])) {
            $calc = 0.0;
            foreach ($foundSplit['items'] as $it) {
                $qty = isset($it['quantity']) ? floatval($it['quantity']) : 1.0;
                $price = isset($it['price']) ? floatval($it['price']) : (isset($it['menu_price']) ? floatval($it['menu_price']) : 0.0);
                $calc += $qty * $price;
            }
            $sub_total = round($calc, 2);
        }

        $cgst_percentage = safe_float($foundSplit['cgst_percentage'] ?? $foundSplit['cgst_pct'] ?? 0);
        $sgst_percentage = safe_float($foundSplit['sgst_percentage'] ?? $foundSplit['sgst_pct'] ?? 0);
        $cgst_amount = round($sub_total * ($cgst_percentage / 100.0), 2);
        $sgst_amount = round($sub_total * ($sgst_percentage / 100.0), 2);
        $final_amount = round($sub_total + $cgst_amount + $sgst_amount, 2);

        $foundSplit['sub_total'] = $sub_total;
        $foundSplit['cgst_percentage'] = $cgst_percentage;
        $foundSplit['sgst_percentage'] = $sgst_percentage;
        $foundSplit['cgst_amount'] = $cgst_amount;
        $foundSplit['sgst_amount'] = $sgst_amount;
        $foundSplit['final_amount'] = $final_amount;

        // Insert this split into orderhistory
        $insertSql = "INSERT INTO orderhistory
                (hotel_id, table_id, split_order_id, is_split, order_data, final_amount, payment_status, taken_by_id, taken_by_role, created_at, updated_at)
                VALUES (:hotel_id, :table_id, :split_order_id, 1, :order_data, :final_amount, :payment_status, :taken_by_id, :taken_by_role, NOW(), NOW())";
        $insStmt = $conn->prepare($insertSql);

        $splitOrderJson = json_encode($foundSplit);
        $splitTotalCost = $final_amount;

        $insStmt->bindValue(':hotel_id', $hotelIdToUse, PDO::PARAM_INT);
        $insStmt->bindValue(':table_id', $tableId, PDO::PARAM_INT);
        $insStmt->bindValue(':split_order_id', $requestedSplitOrderId);
        $insStmt->bindValue(':order_data', $splitOrderJson, PDO::PARAM_STR);
        $insStmt->bindValue(':final_amount', $splitTotalCost); // legacy column holds final amount
        $insStmt->bindValue(':payment_status', $paymentStatus, PDO::PARAM_STR);
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

        // Remove this split from table's split_order_data
        array_splice($splitData, $foundIndex, 1);

        if (count($splitData) === 0) {
            // No more splits remain - reset table to available and clear final_amount
            $updateSql = "UPDATE tables SET 
                            table_status = 'available',
                            split_order_data = NULL,
                            order_data = NULL,
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
                echo json_encode(["error" => "Error resetting table after removing last split", "db_error" => $err]);
                return;
            }
            $conn->commit();
            $resp = [
                "message" => "Split paid and table reset to available (last split removed)",
                "order_history_id" => $historyId,
                "table_status" => "available"
            ];
            if ($usedFallbackToUserId) $resp['warning'] = "hotel_id was not present in token or categories; used owner_id as fallback.";
            http_response_code(200);
            echo json_encode($resp);
            return;
        } else {
            // Some splits remain - update split_order_data and recalc final_amount using remaining splits' final_amounts
            $newTotal = 0.0;
            foreach ($splitData as $i => $s) {
                $s_sub = safe_float($s['sub_total'] ?? 0);
                if ($s_sub == 0 && !empty($s['items']) && is_array($s['items'])) {
                    $calc = 0.0;
                    foreach ($s['items'] as $it) {
                        $qty = isset($it['quantity']) ? floatval($it['quantity']) : 1.0;
                        $price = isset($it['price']) ? floatval($it['price']) : (isset($it['menu_price']) ? floatval($it['menu_price']) : 0.0);
                        $calc += $qty * $price;
                    }
                    $s_sub = round($calc, 2);
                }

                $s_cg_pct = safe_float($s['cgst_percentage'] ?? $s['cgst_pct'] ?? 0);
                $s_sg_pct = safe_float($s['sgst_percentage'] ?? $s['sgst_pct'] ?? 0);
                $s_cg_amt = round($s_sub * ($s_cg_pct / 100.0), 2);
                $s_sg_amt = round($s_sub * ($s_sg_pct / 100.0), 2);
                $s_final = round($s_sub + $s_cg_amt + $s_sg_amt, 2);

                $splitData[$i]['sub_total'] = $s_sub;
                $splitData[$i]['cgst_amount'] = $s_cg_amt;
                $splitData[$i]['sgst_amount'] = $s_sg_amt;
                $splitData[$i]['final_amount'] = $s_final;

                $newTotal += $s_final;
            }

            $newSplitJson = json_encode($splitData);

            $updateSql = "UPDATE tables SET 
                            split_order_data = :split_order_data,
                            final_amount = :final_amount,
                            is_split = 1,
                            updated_at = NOW()
                          WHERE table_id = :table_id";
            $updStmt = $conn->prepare($updateSql);
            $updStmt->bindValue(':split_order_data', $newSplitJson, PDO::PARAM_STR);
            $updStmt->bindValue(':final_amount', round($newTotal, 2));
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
                "remaining_total_cost" => round($newTotal, 2)  // kept name for backward compatibility
            ];
            if ($usedFallbackToUserId) $resp['warning'] = "hotel_id was not present in token or categories; used owner_id as fallback.";
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
