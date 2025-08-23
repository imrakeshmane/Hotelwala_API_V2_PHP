<?php
include '../db.php';
include '../validate.php';

header('Content-Type: application/json');

// Validate JWT
$jwt = getJWTFromHeader();
if ($jwt === null) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Token is missing or invalid"]);
    exit;
}

$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Invalid token"]);
    exit;
}

$userId = $payload['id']; // assuming payload contains user ID
$method = $_SERVER['REQUEST_METHOD'];

// Parse URL for ID if provided
$uri = explode('/', trim($_SERVER['REQUEST_URI'], '/'));
$settingId = isset($uri[count($uri) - 1]) && is_numeric($uri[count($uri) - 1]) ? intval($uri[count($uri) - 1]) : null;

// Read input
$data = json_decode(file_get_contents('php://input'), true);

// Handle HTTP Methods
switch ($method) {
    case 'POST':
        addSetting($data, $userId, $conn);
        break;
    case 'GET':
        if ($settingId) {
            getSettingById($settingId, $userId, $conn);
        } elseif (isset($_GET['hotel_id'])) {
            getSettingsByHotel($_GET['hotel_id'], $userId, $conn);
        } else {
            echo json_encode(["status" => "error", "message" => "Hotel ID is required"]);
        }
        break;
    case 'PUT':
        if ($settingId) {
            updateSetting($settingId, $data, $userId, $conn);
        } else {
            echo json_encode(["status" => "error", "message" => "Setting ID is required"]);
        }
        break;
    case 'DELETE':
        if ($settingId) {
            deleteSetting($settingId, $userId, $conn);
        } else {
            echo json_encode(["status" => "error", "message" => "Setting ID is required"]);
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
}

// FUNCTIONS

function checkHotelOwnership($hotelId, $userId, $conn) {
    $sql = "SELECT * FROM hotels WHERE hotel_id = :hotel_id AND user_id = :user_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->bindParam(':user_id', $userId);
    $stmt->execute();
    return $stmt->rowCount() > 0;
}

function addSetting($data, $userId, $conn) {
    if (!isset($data['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Hotel ID is required"]);
        return;
    }

    if (!checkHotelOwnership($data['hotel_id'], $userId, $conn)) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        return;
    }

    $sql = "INSERT INTO settings (hotel_id, gst_enabled, single_bill_enabled, kot_enabled, bill_first_enabled, always_printer_enable, cgst_percentage, sgst_percentage, created_at, updated_at) 
            VALUES (:hotel_id, :gst_enabled, :single_bill_enabled, :kot_enabled, :bill_first_enabled, :always_printer_enable, :cgst_percentage, :sgst_percentage, NOW(), NOW())";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':hotel_id' => $data['hotel_id'],
        ':gst_enabled' => $data['gst_enabled'] ?? 0,
        ':single_bill_enabled' => $data['single_bill_enabled'] ?? 0,
        ':kot_enabled' => $data['kot_enabled'] ?? 0,
        ':bill_first_enabled' => $data['bill_first_enabled'] ?? 0,
        ':always_printer_enable' => $data['always_printer_enable'] ?? 0,
        ':cgst_percentage' => $data['cgst_percentage'] ?? 0,
        ':sgst_percentage' => $data['sgst_percentage'] ?? 0
    ]);

    echo json_encode(["status" => "success", "message" => "Setting added successfully", "setting_id" => $conn->lastInsertId()]);
}

function updateSetting($settingId, $data, $userId, $conn) {
    $sqlCheck = "SELECT * FROM settings WHERE setting_id = :setting_id";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->bindParam(':setting_id', $settingId);
    $stmtCheck->execute();
    $setting = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$setting) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Setting not found"]);
        return;
    }

    if (!checkHotelOwnership($setting['hotel_id'], $userId, $conn)) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        return;
    }

    $sql = "UPDATE settings SET 
                gst_enabled = :gst_enabled, 
                single_bill_enabled = :single_bill_enabled, 
                kot_enabled = :kot_enabled, 
                bill_first_enabled = :bill_first_enabled, 
                always_printer_enable = :always_printer_enable, 
                cgst_percentage = :cgst_percentage, 
                sgst_percentage = :sgst_percentage, 
                updated_at = NOW()
            WHERE setting_id = :setting_id";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':gst_enabled' => $data['gst_enabled'] ?? $setting['gst_enabled'],
        ':single_bill_enabled' => $data['single_bill_enabled'] ?? $setting['single_bill_enabled'],
        ':kot_enabled' => $data['kot_enabled'] ?? $setting['kot_enabled'],
        ':bill_first_enabled' => $data['bill_first_enabled'] ?? $setting['bill_first_enabled'],
        ':always_printer_enable' => $data['always_printer_enable'] ?? $setting['always_printer_enable'],
        ':cgst_percentage' => $data['cgst_percentage'] ?? $setting['cgst_percentage'],
        ':sgst_percentage' => $data['sgst_percentage'] ?? $setting['sgst_percentage'],
        ':setting_id' => $settingId
    ]);

    echo json_encode(["status" => "success", "message" => "Setting updated successfully"]);
}

function getSettingById($settingId, $userId, $conn) {
    $sql = "SELECT * FROM settings WHERE setting_id = :setting_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':setting_id', $settingId);
    $stmt->execute();
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$setting) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Setting not found"]);
        return;
    }

    if (!checkHotelOwnership($setting['hotel_id'], $userId, $conn)) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        return;
    }

    echo json_encode(["status" => "success", "data" => $setting]);
}

function getSettingsByHotel($hotelId, $userId, $conn) {
    if (!checkHotelOwnership($hotelId, $userId, $conn)) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        return;
    }

    $sql = "SELECT * FROM settings WHERE hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["status" => "success", "data" => $settings]);
}

function deleteSetting($settingId, $userId, $conn) {
    $sqlCheck = "SELECT * FROM settings WHERE setting_id = :setting_id";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->bindParam(':setting_id', $settingId);
    $stmtCheck->execute();
    $setting = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$setting) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Setting not found"]);
        return;
    }

    if (!checkHotelOwnership($setting['hotel_id'], $userId, $conn)) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        return;
    }

    $sql = "DELETE FROM settings WHERE setting_id = :setting_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':setting_id', $settingId);
    $stmt->execute();

    echo json_encode(["status" => "success", "message" => "Setting deleted successfully"]);
}
?>
