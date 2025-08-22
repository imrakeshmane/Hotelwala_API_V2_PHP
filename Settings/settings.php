<?php
include '../db.php'; // Include the database connection file
include '../validate.php';

$jwt = getJWTFromHeader();
if ($jwt === null) {
    http_response_code(401); // Unauthorized
    echo json_encode(["error" => "Token is missing or invalid"]);
    return;
}

$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(["error" => "Invalid token"]);
    return;
}

header('Content-Type: application/json');

// Get the request payload (assumes JSON request)
$request = json_decode(file_get_contents('php://input'), true);

// Check if action is set in the request
if (!isset($request['action'])) {
    echo json_encode(["error" => "Action parameter is required"]);
    http_response_code(400); // Bad Request
    exit;
}

$action = $request['action'];

// Switch based on the action
switch ($action) {
    case 'addSettings':
        addSettings($request, $conn);
        break;
    case 'updateSettings':
        updateSettings($request, $conn);
        break;
    case 'getSettings':
        getSettings($request, $conn);
        break;
    case 'getSettingsByHotelID':
        getSettingsByHotelID($request, $conn);
        break;
    case 'deleteSettingByHotelID':
        deleteSettingByHotelID($request, $conn);
        break;
    default:
        echo json_encode(["error" => "Invalid action"]);
        http_response_code(400); // Bad Request
        break;
}

function addSettings($data, $conn) {
    try {
        $hotel_id = $data['hotel_id'];
        $gst_enabled = $data['gst_enabled'];
        $single_bill_enabled = $data['single_bill_enabled'];
        $kot_enabled = $data['kot_enabled'];
        $bill_first_enabled = $data['bill_first_enabled'];
        $always_printer_enable = $data['always_printer_enable'];
        $cgst_percentage = $data['cgst_percentage'];
        $sgst_percentage = $data['sgst_percentage'];

        $sql = "INSERT INTO settings (
                    hotel_id, gst_enabled, single_bill_enabled, kot_enabled, 
                    bill_first_enabled, always_printer_enable, cgst_percentage, 
                    sgst_percentage, created_at, updated_at
                ) VALUES (
                    :hotel_id, :gst_enabled, :single_bill_enabled, :kot_enabled, 
                    :bill_first_enabled, :always_printer_enable, :cgst_percentage, 
                    :sgst_percentage, NOW(), NOW()
                )";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':hotel_id', $hotel_id, PDO::PARAM_INT);
        $stmt->bindParam(':gst_enabled', $gst_enabled, PDO::PARAM_INT);
        $stmt->bindParam(':single_bill_enabled', $single_bill_enabled, PDO::PARAM_INT);
        $stmt->bindParam(':kot_enabled', $kot_enabled, PDO::PARAM_INT);
        $stmt->bindParam(':bill_first_enabled', $bill_first_enabled, PDO::PARAM_INT);
        $stmt->bindParam(':always_printer_enable', $always_printer_enable, PDO::PARAM_INT);
        $stmt->bindParam(':cgst_percentage', $cgst_percentage);
        $stmt->bindParam(':sgst_percentage', $sgst_percentage);

        if ($stmt->execute()) {
            $last_id = $conn->lastInsertId();
            getSettings(['setting_id' => $last_id], $conn);
        } else {
            $errorInfo = $stmt->errorInfo();
            http_response_code(500);
            echo json_encode(["error" => "Error: " . $errorInfo[2]]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}


function updateSettings($data, $conn) {
    try {
        $setting_id = $data['setting_id'];
        $gst_enabled = $data['gst_enabled'];
        $single_bill_enabled = $data['single_bill_enabled'];
        $kot_enabled = $data['kot_enabled'];
        $bill_first_enabled = $data['bill_first_enabled'];
        $always_printer_enable = $data['always_printer_enable'];
        $cgst_percentage = $data['cgst_percentage'];
        $sgst_percentage = $data['sgst_percentage'];

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

        $stmt->bindParam(':gst_enabled', $gst_enabled, PDO::PARAM_INT);
        $stmt->bindParam(':single_bill_enabled', $single_bill_enabled, PDO::PARAM_INT);
        $stmt->bindParam(':kot_enabled', $kot_enabled, PDO::PARAM_INT);
        $stmt->bindParam(':bill_first_enabled', $bill_first_enabled, PDO::PARAM_INT);
        $stmt->bindParam(':always_printer_enable', $always_printer_enable, PDO::PARAM_INT);
        $stmt->bindParam(':cgst_percentage', $cgst_percentage);
        $stmt->bindParam(':sgst_percentage', $sgst_percentage);
        $stmt->bindParam(':setting_id', $setting_id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            getSettings(['setting_id' => $setting_id], $conn);
        } else {
            $errorInfo = $stmt->errorInfo();
            http_response_code(500); // Internal Server Error
            echo json_encode(["error" => "Error: " . $errorInfo[2]]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}

function getSettings($data, $conn) {
    try {
        $setting_id = $data['setting_id'];

        $sql = "SELECT * FROM settings WHERE setting_id = :setting_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':setting_id', $setting_id, PDO::PARAM_INT);

        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(["Setting" => $settings]);
        } else {
            echo json_encode(["message" => "Settings not found"]);
            http_response_code(404);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}

function getSettingsByHotelID($data, $conn) {
    try {
        $hotel_id = $data['hotel_id'];

        $sql = "SELECT * FROM settings WHERE hotel_id = :hotel_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':hotel_id', $hotel_id, PDO::PARAM_INT);

        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["Setting" => $settings]);
        } else {
            echo json_encode(["message" => "Settings not found"]);
            http_response_code(404);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}

function deleteSettingByHotelID($data, $conn) {
    try {
        $hotel_id = $data['hotel_id'];

        $sql = "DELETE FROM settings WHERE hotel_id = :hotel_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':hotel_id', $hotel_id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            if ($stmt->rowCount() > 0) {
                echo json_encode(["message" => "Setting deleted successfully"]);
            } else {
                echo json_encode(["message" => "Setting not found for this hotel_id"]);
                http_response_code(404);
            }
        } else {
            $errorInfo = $stmt->errorInfo();
            http_response_code(500);
            echo json_encode(["error" => "Error: " . $errorInfo[2]]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}
?>
