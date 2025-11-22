<?php
// settings.php
include '../db.php';
include '../validate.php';

header('Content-Type: application/json');
$requestMethod = $_SERVER['REQUEST_METHOD'];

/* Validate JWT */
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

$userID = $payload['owner_id'] ?? null;
$userType = $payload['user_type'] ?? null;

switch ($requestMethod) {
    case 'POST': createSetting($conn, $userID, $userType); break;
    case 'GET':  getSettings($conn, $userID, $userType); break;
    case 'PUT':  updateSetting($conn, $userID, $userType); break;
    case 'DELETE': deleteSetting($conn, $userID, $userType); break;
    default:
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        break;
}

/* ---------------------------
   POST - create settings for a hotel
*/
function createSetting($conn, $userID, $userType) {
    global $payload;
    if (!isOwnerOrManager($userType, $payload)) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can create settings"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "hotel_id is required"]);
        return;
    }

    $hotelId = (int)$data['hotel_id'];

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

    try {
        // check if settings row already exists for this hotel
        $chkSql = "SELECT setting_id FROM settings WHERE hotel_id = :hotel_id LIMIT 1";
        $chk = $conn->prepare($chkSql);
        $chk->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $chk->execute();
        $exists = $chk->fetch(PDO::FETCH_ASSOC);
        if ($exists) {
            http_response_code(409);
            echo json_encode(["error" => "Settings already exist for this hotel", "setting_id" => (int)$exists['setting_id']]);
            return;
        }

        // sanitize and set defaults
        $gst_enabled = isset($data['gst_enabled']) ? (int)$data['gst_enabled'] : 1;
        $single_bill_enabled = isset($data['single_bill_enabled']) ? (int)$data['single_bill_enabled'] : 0;
        $kot_enabled = isset($data['kot_enabled']) ? (int)$data['kot_enabled'] : 1;
        $bill_first_enabled = isset($data['bill_first_enabled']) ? (int)$data['bill_first_enabled'] : 0;
        $always_printer_enable = isset($data['always_printer_enable']) ? (int)$data['always_printer_enable'] : 1;
        $cgst_percentage = isset($data['cgst_percentage']) ? floatval($data['cgst_percentage']) : 0.00;
        $sgst_percentage = isset($data['sgst_percentage']) ? floatval($data['sgst_percentage']) : 0.00;

        // day_start_time handling (expect "HH:MM:SS")
        $day_start_time = null;
        if (array_key_exists('day_start_time', $data)) {
            $dst = $data['day_start_time'];
            if ($dst === null || $dst === '') {
                $day_start_time = null;
            } else {
                // validate HH:MM:SS (24h)
                if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $dst)) {
                    $day_start_time = $dst;
                } else {
                    // try to parse with strtotime
                    $ts = strtotime($dst);
                    if ($ts !== false) $day_start_time = date('H:i:s', $ts);
                    else {
                        http_response_code(400);
                        echo json_encode(["error" => "Invalid day_start_time format. Expected HH:MM:SS or parseable time."]);
                        return;
                    }
                }
            }
        } else {
            // default to 00:00:00 if not provided (or you can leave as NULL)
            $day_start_time = '00:00:00';
        }

        $ins = "INSERT INTO settings (hotel_id, gst_enabled, single_bill_enabled, kot_enabled, bill_first_enabled, always_printer_enable, cgst_percentage, sgst_percentage, day_start_time, created_at, updated_at)
                VALUES (:hotel_id, :gst_enabled, :single_bill_enabled, :kot_enabled, :bill_first_enabled, :always_printer_enable, :cgst_percentage, :sgst_percentage, :day_start_time, NOW(), NOW())";
        $s = $conn->prepare($ins);
        $s->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $s->bindValue(':gst_enabled', $gst_enabled, PDO::PARAM_INT);
        $s->bindValue(':single_bill_enabled', $single_bill_enabled, PDO::PARAM_INT);
        $s->bindValue(':kot_enabled', $kot_enabled, PDO::PARAM_INT);
        $s->bindValue(':bill_first_enabled', $bill_first_enabled, PDO::PARAM_INT);
        $s->bindValue(':always_printer_enable', $always_printer_enable, PDO::PARAM_INT);
        $s->bindValue(':cgst_percentage', $cgst_percentage);
        $s->bindValue(':sgst_percentage', $sgst_percentage);
        // day_start_time bind (allow null)
        if ($day_start_time === null) $s->bindValue(':day_start_time', null, PDO::PARAM_NULL);
        else $s->bindValue(':day_start_time', $day_start_time);
        $s->execute();

        // fetch created row
        $fs = $conn->prepare("SELECT * FROM settings WHERE hotel_id = :hotel_id LIMIT 1");
        $fs->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $fs->execute();
        $row = $fs->fetch(PDO::FETCH_ASSOC);

        http_response_code(201);
        echo json_encode(["message" => "Settings created successfully", "settings" => $row]);
        return;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
        return;
    }
}

/* ---------------------------
   GET - fetch settings by hotel_id only
*/
function getSettings($conn, $userID, $userType) {
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

    // fetch settings for hotel
    try {
        $sql = "SELECT * FROM settings WHERE hotel_id = :hotel_id LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(["error" => "Settings not found for this hotel"]);
            return;
        }
        http_response_code(200);
        echo json_encode($row);
        return;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
        return;
    }
}

/* ---------------------------
   PUT - partial update settings by hotel_id only
*/
function updateSetting($conn, $userID, $userType) {
    global $payload;
    if (!isOwnerOrManager($userType, $payload)) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can update settings"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "hotel_id is required"]);
        return;
    }

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

    // ensure settings row exists
    $chk = $conn->prepare("SELECT setting_id FROM settings WHERE hotel_id = :hotel_id LIMIT 1");
    $chk->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $chk->execute();
    $exists = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$exists) {
        http_response_code(404);
        echo json_encode(["error" => "Settings not found for this hotel"]);
        return;
    }

    // Build dynamic update from allowed fields
    $allowed = [
        'gst_enabled' => 'int',
        'single_bill_enabled' => 'int',
        'kot_enabled' => 'int',
        'bill_first_enabled' => 'int',
        'always_printer_enable' => 'int',
        'cgst_percentage' => 'float',
        'sgst_percentage' => 'float',
        'day_start_time' => 'time' // string in HH:MM:SS
    ];
    $sets = [];
    $binds = [':hotel_id' => $hotelId];

    foreach ($allowed as $field => $type) {
        if (array_key_exists($field, $data)) {
            if ($field === 'day_start_time') {
                // validate or convert
                $val = $data[$field];
                if ($val === null || $val === '') {
                    $sets[] = "$field = NULL";
                    continue;
                }
                if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $val)) {
                    $sets[] = "$field = :$field";
                    $binds[":$field"] = $val;
                } else {
                    $ts = strtotime($val);
                    if ($ts !== false) {
                        $sets[] = "$field = :$field";
                        $binds[":$field"] = date('H:i:s', $ts);
                    } else {
                        http_response_code(400);
                        echo json_encode(["error" => "Invalid day_start_time format. Use HH:MM:SS or parseable time."]);
                        return;
                    }
                }
            } else {
                $sets[] = "$field = :$field";
                if ($type === 'int') $binds[":$field"] = (int)$data[$field];
                else $binds[":$field"] = floatval($data[$field]);
            }
        }
    }

    if (count($sets) === 0) {
        http_response_code(400);
        echo json_encode(["error" => "No updatable fields provided"]);
        return;
    }

    $sets[] = "updated_at = NOW()";
    $setSql = implode(', ', $sets);

    try {
        $sql = "UPDATE settings SET $setSql WHERE hotel_id = :hotel_id";
        $stmt = $conn->prepare($sql);
        foreach ($binds as $k => $v) {
            if (is_int($v)) $stmt->bindValue($k, $v, PDO::PARAM_INT);
            elseif ($v === null) $stmt->bindValue($k, null, PDO::PARAM_NULL);
            else $stmt->bindValue($k, $v);
        }
        if ($stmt->execute()) {
            // return updated row
            $fs = $conn->prepare("SELECT * FROM settings WHERE hotel_id = :hotel_id LIMIT 1");
            $fs->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
            $fs->execute();
            $row = $fs->fetch(PDO::FETCH_ASSOC);
            http_response_code(200);
            echo json_encode(["message" => "Settings updated successfully", "settings" => $row]);
            return;
        } else {
            $err = $stmt->errorInfo();
            http_response_code(500);
            echo json_encode(["error" => "Error updating settings", "db_error" => $err]);
            return;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
        return;
    }
}

/* ---------------------------
   DELETE - delete settings by hotel_id only (owner/manager only)
*/
function deleteSetting($conn, $userID, $userType) {
    global $payload;
    if (!isOwnerOrManager($userType, $payload)) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can delete settings"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "hotel_id is required"]);
        return;
    }

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

    // ensure settings exist
    $chk = $conn->prepare("SELECT setting_id FROM settings WHERE hotel_id = :hotel_id LIMIT 1");
    $chk->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
    $chk->execute();
    $exists = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$exists) {
        http_response_code(404);
        echo json_encode(["error" => "Settings not found for this hotel"]);
        return;
    }

    try {
        $sql = "DELETE FROM settings WHERE hotel_id = :hotel_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode(["message" => "Settings deleted successfully"]);
            return;
        } else {
            $err = $stmt->errorInfo();
            http_response_code(500);
            echo json_encode(["error" => "Error deleting settings", "db_error" => $err]);
            return;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
        return;
    }
}
?>
