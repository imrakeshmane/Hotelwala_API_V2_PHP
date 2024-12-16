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
    case 'insert':
        insertExpence($request, $conn);
        break;
    case 'update':
        updateExpence($request, $conn);
        break;
    case 'get':
        getExpence($request, $conn);
        break;
    case 'delete':
        deleteExpence($request, $conn);
        break;
    case 'getByDate':
        getExpenceDate($request, $conn);
        break;
    default:
        echo json_encode(["error" => "Invalid action"]);
        http_response_code(400); // Bad Request
        break;
}

function insertExpence($data, $conn) {
    try {
        $userID = $data['userID'];
        $hotelID = $data['hotelID'];
        $expTypeID = $data['expTypeID'];
        $amount = $data['amount'];
        $note = $data['note'];

        $sql = "INSERT INTO expence (ExpTypeID, UserID, HotelID, Amount, Note, CreatedDate, UpdatedDate) 
                VALUES (:expTypeID, :userID, :hotelID, :amount, :note, NOW(), NOW())";

        $stmt = $conn->prepare($sql);

        $stmt->bindParam(':expTypeID', $expTypeID, PDO::PARAM_INT);
        $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
        $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        $stmt->bindParam(':amount', $amount, PDO::PARAM_STR);
        $stmt->bindParam(':note', $note, PDO::PARAM_STR);

        if ($stmt->execute()) {
            $insertedID = $conn->lastInsertId();
            http_response_code(201); // Created
            echo json_encode([
                "message" => "Expense added successfully.",
                "ExpID" => $insertedID,
            ]);
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

function updateExpence($data, $conn) {
    try {
        $expID = $data['expID'];
        $amount = $data['amount'];
        $note = $data['note'];

        $sql = "UPDATE expence SET 
                    Amount = :amount, 
                    Note = :note, 
                    UpdatedDate = NOW() 
                WHERE ExpID = :expID";

        $stmt = $conn->prepare($sql);

        $stmt->bindParam(':expID', $expID, PDO::PARAM_INT);
        $stmt->bindParam(':amount', $amount, PDO::PARAM_STR);
        $stmt->bindParam(':note', $note, PDO::PARAM_STR);

        if ($stmt->execute()) {
            http_response_code(200); // OK
            echo json_encode(["message" => "Expense updated successfully"]);
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

function getExpence($data, $conn) {
    try {
        $hotelID = isset($data['hotelID']) ? $data['hotelID'] : null;

        $sql = "SELECT * FROM expence WHERE HotelID = :hotelID";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);

        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $expences = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["expences" => $expences]);
        } else {
            echo json_encode(["message" => "No expenses found"]);
            http_response_code(404); // Not Found
        }
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}

function getExpenceDate($data, $conn) {
    try {
        $hotelID = $data['hotelID'];
        $fromDate = $data['fromDate'];
        $toDate = $data['toDate'];

        $sql = "SELECT * FROM expence WHERE HotelID = :hotelID AND CreatedDate BETWEEN :fromDate AND :toDate";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        $stmt->bindParam(':fromDate', $fromDate, PDO::PARAM_STR);
        $stmt->bindParam(':toDate', $toDate, PDO::PARAM_STR);

        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $expences = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["expences" => $expences]);
        } else {
            echo json_encode(["message" => "No expenses found"]);
            http_response_code(404); // Not Found
        }
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}

function deleteExpence($data, $conn) {
    try {
        $expID = $data['expID'];

        $sql = "DELETE FROM expence WHERE ExpID = :expID";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':expID', $expID, PDO::PARAM_INT);

        if ($stmt->execute()) {
            http_response_code(200); // OK
            echo json_encode(["message" => "Expense deleted successfully"]);
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
