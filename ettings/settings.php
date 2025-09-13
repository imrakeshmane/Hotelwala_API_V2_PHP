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
   Body JSON example:
   {
     "hotel_id": 8,
     "gst_enabled": 1,
     "single_bill_enabled": 0,
     "kot_enabled": 1,
     "bill_first_enabled": 0,
     "always_printer_enable": 1,
     "cgst_percentage": 9.00,
     "sgst_percentage": 9.00
   }
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

        $ins = "INSERT INTO settings (hotel_id, gst_enabled, single_bill_enabled, kot_enabled, bill_first_enabled, always_printer_enable, cgst_percentage, sgst_percentage, created_at, updated_at)
                VALUES (:hotel_id, :gst_enabled, :single_bill_enabled, :kot_enabled, :bill_first_enabled, :always_printer_enable, :cgst_percentage, :sgst_percentage, NOW(), NOW())";
        $s = $conn->prepare($ins);
        $s->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $s->bindValue(':gst_enabled', $gst_enabled, PDO::PARAM_INT);
        $s->bindValue(':single_bill_enabled', $single_bill_enabled, PDO::PARAM_INT);
        $s->bindValue(':kot_enabled', $kot_enabled, PDO::PARAM_INT);
        $s->bindValue(':bill_first_enabled', $bill_first_enabled, PDO::PARAM_INT);
        $s->bindValue(':always_printer_enable', $always_printer_enable, PDO::PARAM_INT);
        $s->bindValue(':cgst_percentage', $cgst_percentage);
        $s->bindValue(':sgst_percentage', $sgst_percentage);
        $s->execute();

        // fetch created row
        $lastId = (int)$conn->lastInsertId();
        $fs = $conn->prepare("SELECT * FROM settings WHERE setting_id = :id LIMIT 1");
        $fs->bindValue(':id', $lastId, PDO::PARAM_INT);
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
   GET - fetch settings
   Query/body:
     - hotel_id (required) OR
     - setting_id (optional) along with hotel_id (recommended)
*/
function getSettings($conn, $userID, $userType) {
    $params = readParams();

    // require hotel_id (we use hotel scope)
    if (!isset($params['hotel_id']) && !isset($params['setting_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "hotel_id or setting_id is required"]);
        return;
    }

    // If setting_id provided and not hotel_id, fetch by setting_id
    if (isset($params['setting_id']) && !isset($params['hotel_id'])) {
        $settingId = (int)$params['setting_id'];
        $sql = "SELECT * FROM settings WHERE setting_id = :setting_id LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':setting_id', $settingId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(["error" => "Settings not found"]);
            return;
        }

        // If owner role, verify hotel ownership
        if ($userType === 'owner') {
            $sqlH = "SELECT owner_id FROM hotels WHERE hotel_id = :hotel_id LIMIT 1";
            $sth = $conn->prepare($sqlH);
            $sth->bindValue(':hotel_id', (int)$row['hotel_id'], PDO::PARAM_INT);
            $sth->execute();
            $h = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$h || (int)$h['owner_id'] !== (int)$userID) {
                http_response_code(403);
                echo json_encode(["error" => "You don't have access to this hotel's data"]);
                return;
            }
        }

        http_response_code(200);
        echo json_encode($row);
        return;
    }

    // Otherwise expect hotel_id
    $hotelId = isset($params['hotel_id']) ? (int)$params['hotel_id'] : null;

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
   PUT - partial update settings
   Body JSON: must include hotel_id and setting_id
   Any of these fields can be sent:
     gst_enabled, single_bill_enabled, kot_enabled, bill_first_enabled, always_printer_enable, cgst_percentage, sgst_percentage
*/
function updateSetting($conn, $userID, $userType) {
    global $payload;
    if (!isOwnerOrManager($userType, $payload)) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can update settings"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['hotel_id'], $data['setting_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "hotel_id and setting_id are required"]);
        return;
    }

    $hotelId = (int)$data['hotel_id'];
    $settingId = (int)$data['setting_id'];

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

    // Build dynamic update from allowed fields
    $allowed = [
        'gst_enabled' => 'int',
        'single_bill_enabled' => 'int',
        'kot_enabled' => 'int',
        'bill_first_enabled' => 'int',
        'always_printer_enable' => 'int',
        'cgst_percentage' => 'float',
        'sgst_percentage' => 'float'
    ];
    $sets = [];
    $binds = [':setting_id' => $settingId, ':hotel_id' => $hotelId];

    foreach ($allowed as $field => $type) {
        if (array_key_exists($field, $data)) {
            $sets[] = "$field = :$field";
            if ($type === 'int') $binds[":$field"] = (int)$data[$field];
            else $binds[":$field"] = floatval($data[$field]);
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
        $sql = "UPDATE settings SET $setSql WHERE setting_id = :setting_id AND hotel_id = :hotel_id";
        $stmt = $conn->prepare($sql);
        foreach ($binds as $k => $v) {
            if (is_int($v)) $stmt->bindValue($k, $v, PDO::PARAM_INT); else $stmt->bindValue($k, $v);
        }
        if ($stmt->execute()) {
            // return updated row
            $fs = $conn->prepare("SELECT * FROM settings WHERE setting_id = :setting_id LIMIT 1");
            $fs->bindValue(':setting_id', $settingId, PDO::PARAM_INT);
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
   DELETE - delete settings (owner/manager only)
   Body JSON: { "setting_id": 1, "hotel_id": 8 }
*/
function deleteSetting($conn, $userID, $userType) {
    global $payload;
    if (!isOwnerOrManager($userType, $payload)) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can delete settings"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['setting_id'], $data['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "setting_id and hotel_id are required"]);
        return;
    }

    $settingId = (int)$data['setting_id'];
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
        $sql = "DELETE FROM settings WHERE setting_id = :setting_id AND hotel_id = :hotel_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':setting_id', $settingId, PDO::PARAM_INT);
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
