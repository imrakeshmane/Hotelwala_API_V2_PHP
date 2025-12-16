<?php
// hotel.php
include '../db.php'; // database connection ($conn)
include '../validate.php'; // getJWTFromHeader() and validateJWT()

header('Content-Type: application/json');
$requestMethod = $_SERVER['REQUEST_METHOD'];

/* Get and validate JWT */
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

$userID = $payload['owner_id'];
$userType = $payload['user_type'];

switch ($requestMethod) {
    case 'POST':
        createHotel($conn, $userID, $userType);
        break;
    case 'GET':
        getHotels($conn, $userID, $userType);
        break;
    case 'PUT':
        updateHotel($conn, $userID, $userType);
        break;
    case 'DELETE':
        deleteHotel($conn, $userID, $userType);
        break;
    default:
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        break;
}

/* ---------------------------
   POST - create a hotel + default settings (transactional)
   Required fields: hotel_name, hotel_location, pincode, hotel_mobile_number
*/
function createHotel($conn, $userID, $userType)
{
    if ($userType !== 'owner') {
        http_response_code(403);
        echo json_encode(["error" => "Only owners can create hotels"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['hotel_name'], $data['hotel_location'], $data['pincode'], $data['hotel_mobile_number'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $hotelName = trim($data['hotel_name']);
    $hotelLocation = trim($data['hotel_location']);
    $pincode = trim($data['pincode']);
    $hotelMobileNumber = trim($data['hotel_mobile_number']);

    try {
        // Begin transaction so hotel + settings are atomic
        $conn->beginTransaction();

        // Insert hotel
        $sql = "INSERT INTO hotels (owner_id, hotel_name, hotel_location, pincode, hotel_mobile_number, created_at, updated_at)
                VALUES (:owner_id, :hotel_name, :hotel_location, :pincode, :hotel_mobile_number, NOW(), NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':owner_id', $userID, PDO::PARAM_INT);
        $stmt->bindValue(':hotel_name', $hotelName);
        $stmt->bindValue(':hotel_location', $hotelLocation);
        $stmt->bindValue(':pincode', $pincode);
        $stmt->bindValue(':hotel_mobile_number', $hotelMobileNumber);
        $stmt->execute();

        $hotelId = (int)$conn->lastInsertId();

        // Insert default settings for this hotel
        // Defaults (adjust if you want different defaults):
        // gst_enabled = 1, single_bill_enabled = 0, kot_enabled = 1,
        // bill_first_enabled = 0, always_printer_enable = 1, cgst_percentage = 0.00, sgst_percentage = 0.00
        $insSettings = "INSERT INTO settings (hotel_id, gst_enabled, single_bill_enabled, kot_enabled, bill_first_enabled, always_printer_enable, cgst_percentage, sgst_percentage, created_at, updated_at)
                        VALUES (:hotel_id, :gst_enabled, :single_bill_enabled, :kot_enabled, :bill_first_enabled, :always_printer_enable, :cgst_percentage, :sgst_percentage, NOW(), NOW())";
        $sstmt = $conn->prepare($insSettings);
        $sstmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $sstmt->bindValue(':gst_enabled', 0, PDO::PARAM_INT);
        $sstmt->bindValue(':single_bill_enabled', 0, PDO::PARAM_INT);
        $sstmt->bindValue(':kot_enabled', 0, PDO::PARAM_INT);
        $sstmt->bindValue(':bill_first_enabled', 0, PDO::PARAM_INT);
        $sstmt->bindValue(':always_printer_enable', 0, PDO::PARAM_INT);
        $sstmt->bindValue(':cgst_percentage', 0.00);
        $sstmt->bindValue(':sgst_percentage', 0.00);
        $sstmt->execute();

        // Commit transaction
        $conn->commit();

        // Fetch created hotel and settings to return
        $sqlSelect = "SELECT * FROM hotels WHERE hotel_id = :hotel_id LIMIT 1";
        $stmtSelect = $conn->prepare($sqlSelect);
        $stmtSelect->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $stmtSelect->execute();
        $hotelData = $stmtSelect->fetch(PDO::FETCH_ASSOC);

        $fs = $conn->prepare("SELECT * FROM settings WHERE hotel_id = :hotel_id LIMIT 1");
        $fs->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $fs->execute();
        $settingsRow = $fs->fetch(PDO::FETCH_ASSOC);

        // Add empty categories key for backward compatibility
        $hotelData['categories'] = [];
        $hotelData['settings'] = $settingsRow ?: null;

        http_response_code(201);
        echo json_encode([
            "message" => "Hotel created successfully",
            "data" => $hotelData
        ]);
        return;
    } catch (PDOException $e) {
        // rollback if started
        if ($conn->inTransaction()) $conn->rollBack();
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
        return;
    }
}

/* ---------------------------
   GET - read hotels
   GET /hotel.php?hotel_id=xxx  -> single hotel
   GET /hotel.php               -> all hotels for owner
*/
function getHotels($conn, $userID, $userType)
{
    if (isset($_GET['hotel_id'])) {
        $hotelId = (int)$_GET['hotel_id'];

        if ($userType === 'user') {
            $sql = "SELECT * FROM users WHERE user_id = :user_id AND hotel_id = :hotel_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':user_id', $userID, PDO::PARAM_INT);
            $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                http_response_code(403);
                echo json_encode(["error" => "User is not authorized to view this hotel"]);
                return;
            }
        }

        $sql = "SELECT * FROM hotels WHERE hotel_id = :hotel_id LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $hotel = $stmt->fetch(PDO::FETCH_ASSOC);

            // optionally include settings in response
            $fs = $conn->prepare("SELECT * FROM settings WHERE hotel_id = :hotel_id LIMIT 1");
            $fs->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
            $fs->execute();
            $hotel['settings'] = $fs->fetch(PDO::FETCH_ASSOC) ?: null;
            $hotel['categories'] = []; // keep backward compat

            http_response_code(200);
            echo json_encode(["hotel" => $hotel]);
        } else {
            http_response_code(404);
            echo json_encode(["error" => "Hotel not found"]);
        }
    } else {
        if ($userType === 'owner') {
            $sql = "SELECT * FROM hotels WHERE owner_id = :owner_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':owner_id', $userID, PDO::PARAM_INT);
            $stmt->execute();
            $hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Optionally attach settings snippet for each hotel
            foreach ($hotels as &$h) {
                $fs = $conn->prepare("SELECT * FROM settings WHERE hotel_id = :hotel_id LIMIT 1");
                $fs->bindValue(':hotel_id', (int)$h['hotel_id'], PDO::PARAM_INT);
                $fs->execute();
                $h['settings'] = $fs->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            http_response_code(200);
            echo json_encode(["hotels" => $hotels]);
        } else {
            http_response_code(403);
            echo json_encode(["error" => "Hotel ID is required for non-owners"]);
        }
    }
}

/* ---------------------------
   PUT - update hotel
   Body: hotel_id, hotel_name, hotel_location, pincode, hotel_mobile_number
*/
function updateHotel($conn, $userID, $userType)
{
    if ($userType !== 'owner') {
        http_response_code(403);
        echo json_encode(["error" => "Only owners can update hotels"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['hotel_id'], $data['hotel_name'], $data['hotel_location'], $data['pincode'], $data['hotel_mobile_number'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $hotelId = (int)$data['hotel_id'];
    $hotelName = trim($data['hotel_name']);
    $hotelLocation = trim($data['hotel_location']);
    $pincode = trim($data['pincode']);
    $hotelMobileNumber = trim($data['hotel_mobile_number']);

    try {
        $sql = "UPDATE hotels 
                SET hotel_name = :hotel_name, hotel_location = :hotel_location, pincode = :pincode, hotel_mobile_number = :hotel_mobile_number, updated_at = NOW()
                WHERE hotel_id = :hotel_id AND owner_id = :owner_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':hotel_name', $hotelName);
        $stmt->bindValue(':hotel_location', $hotelLocation);
        $stmt->bindValue(':pincode', $pincode);
        $stmt->bindValue(':hotel_mobile_number', $hotelMobileNumber);
        $stmt->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $stmt->bindValue(':owner_id', $userID, PDO::PARAM_INT);

        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            // either no change or hotel not found / not owner
            // check existence & ownership explicitly
            $check = $conn->prepare("SELECT owner_id FROM hotels WHERE hotel_id = :hotel_id LIMIT 1");
            $check->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
            $check->execute();
            $hr = $check->fetch(PDO::FETCH_ASSOC);
            if (!$hr) {
                http_response_code(404);
                echo json_encode(["error" => "Hotel not found"]);
            } else if ((int)$hr['owner_id'] !== (int)$userID) {
                http_response_code(403);
                echo json_encode(["error" => "You don't have permission to update this hotel"]);
            } else {
                // no actual update performed (values might be same)
                http_response_code(200);
                echo json_encode(["message" => "No changes made to the hotel"]);
            }
            return;
        }

        http_response_code(200);
        echo json_encode(["message" => "Hotel updated successfully"]);
        return;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
        return;
    }
}

/* ---------------------------
   DELETE - delete hotel and its settings (transactional)
   Body JSON: { "hotel_id": 8 }
*/
function deleteHotel($conn, $userID, $userType)
{
    if ($userType !== 'owner') {
        http_response_code(403);
        echo json_encode(["error" => "Only owners can delete hotels"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !isset($data['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "Hotel ID is required"]);
        return;
    }

    $hotelId = (int)$data['hotel_id'];

    try {
        // Verify hotel exists and belongs to this owner
        $check = $conn->prepare("SELECT owner_id FROM hotels WHERE hotel_id = :hotel_id LIMIT 1");
        $check->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $check->execute();
        $hr = $check->fetch(PDO::FETCH_ASSOC);
        if (!$hr) {
            http_response_code(404);
            echo json_encode(["error" => "Hotel not found"]);
            return;
        }
        if ((int)$hr['owner_id'] !== (int)$userID) {
            http_response_code(403);
            echo json_encode(["error" => "You don't have permission to delete this hotel"]);
            return;
        }

        // begin transaction: delete settings then hotel
        $conn->beginTransaction();

        $delSettings = $conn->prepare("DELETE FROM settings WHERE hotel_id = :hotel_id");
        $delSettings->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $delSettings->execute();

        $delHotel = $conn->prepare("DELETE FROM hotels WHERE hotel_id = :hotel_id AND owner_id = :owner_id");
        $delHotel->bindValue(':hotel_id', $hotelId, PDO::PARAM_INT);
        $delHotel->bindValue(':owner_id', $userID, PDO::PARAM_INT);
        $delHotel->execute();

        if ($delHotel->rowCount() === 0) {
            // something unexpected: rollback
            $conn->rollBack();
            http_response_code(500);
            echo json_encode(["error" => "Error deleting hotel"]);
            return;
        }

        $conn->commit();

        http_response_code(200);
        echo json_encode(["message" => "Hotel and its settings deleted successfully"]);
        return;
    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        http_response_code(500);
        echo json_encode(["error" => "Database error", "message" => $e->getMessage()]);
        return;
    }
}
?>
