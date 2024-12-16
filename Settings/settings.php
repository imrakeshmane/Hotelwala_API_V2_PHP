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
    case 'updateSettings':
        updateSettings($request, $conn);
        break;
    case 'getSettings':
        getSettings($request, $conn);
        break;
    case 'getSettingsbyHotelID':
        getSettingsbyHotelID($request, $conn);
        break;
    case 'deleteSettingByHotelID':
        deleteSettingByHotelID($request, $conn);
        break;
    default:
        echo json_encode(["error" => "Invalid action"]);
        http_response_code(400); // Bad Request
        break;
}

function updateSettings($data, $conn) {
    try {
        $KOTEnable = $data['KOTEnable'];
        $BillFirst = $data['BillFirst'];
        $SingleBill = $data['SingleBill'];
        $AlwaysEnablePrinter = $data['AlwaysEnablePrinter'];
        $GSTEnable = $data['GSTEnable'];
        $CGST = $data['CGST'];
        $SGST = $data['SGST'];
        $SettingID = $data['SettingID'];

        $sql = "UPDATE settings SET 
                    KOTEnable = :KOTEnable, 
                    BillFirst = :BillFirst, 
                    SingleBill = :SingleBill, 
                    AlwaysEnablePrinter = :AlwaysEnablePrinter, 
                    GSTEnable = :GSTEnable, 
                    CGST = :CGST, 
                    SGST = :SGST, 
                    UpdatedDate = NOW() 
                WHERE SettingID = :SettingID";
        
        $stmt = $conn->prepare($sql);

        $stmt->bindParam(':KOTEnable', $KOTEnable, PDO::PARAM_INT);
        $stmt->bindParam(':BillFirst', $BillFirst, PDO::PARAM_INT);
        $stmt->bindParam(':SingleBill', $SingleBill, PDO::PARAM_INT);
        $stmt->bindParam(':AlwaysEnablePrinter', $AlwaysEnablePrinter, PDO::PARAM_INT);
        $stmt->bindParam(':GSTEnable', $GSTEnable, PDO::PARAM_INT);
        $stmt->bindParam(':CGST', $CGST, PDO::PARAM_STR);
        $stmt->bindParam(':SGST', $SGST, PDO::PARAM_STR);
        $stmt->bindParam(':SettingID', $SettingID, PDO::PARAM_INT);

        if ($stmt->execute()) {
            getSettings(['SettingID' => $SettingID], $conn);
        } else {
            $errorInfo = $stmt->errorInfo();
            http_response_code(500); // Internal Server Error
            echo json_encode(["error" => "Error: " . $errorInfo[2]]);
        }
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}

function getSettings($data, $conn) {
    try {
        $SettingID = $data['SettingID'];

        $sql = "SELECT * FROM settings WHERE SettingID = :SettingID";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':SettingID', $SettingID, PDO::PARAM_INT);

        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(["Setting" => $settings]);
        } else {
            echo json_encode(["message" => "Settings not found"]);
            http_response_code(404); // Not Found
        }
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}

function getSettingsbyHotelID($data, $conn) {
    try {
        $HotelID = $data['HotelID'];

        $sql = "SELECT * FROM settings WHERE HotelID = :HotelID";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':HotelID', $HotelID, PDO::PARAM_INT);

        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["Setting" => $settings]);
        } else {
            echo json_encode(["message" => "Settings not found"]);
            http_response_code(404); // Not Found
        }
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}
function deleteSettingByHotelID($data, $conn) {
    try {
        $HotelID = $data['HotelID'];

        // SQL query to delete the setting for the given HotelID
        $sql = "DELETE FROM settings WHERE HotelID = :HotelID";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':HotelID', $HotelID, PDO::PARAM_INT);

        // Execute the delete query
        if ($stmt->execute()) {
            if ($stmt->rowCount() > 0) {
                echo json_encode(["message" => "Setting deleted successfully"]);
                http_response_code(200); // OK
            } else {
                echo json_encode(["message" => "Setting not found for this HotelID"]);
                http_response_code(404); // Not Found
            }
        } else {
            $errorInfo = $stmt->errorInfo();
            http_response_code(500); // Internal Server Error
            echo json_encode(["error" => "Error: " . $errorInfo[2]]);
        }
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}

?>
