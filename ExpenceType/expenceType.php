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
        insertExpenseType($request, $conn);
        break;
    case 'update':
        updateExpenseType($request, $conn);
        break;
    case 'get':
        getExpenseType($request, $conn);
        break;
    case 'delete':
        deleteExpenseType($request, $conn);
        break;
    default:
        echo json_encode(["error" => "Invalid action"]);
        http_response_code(400); // Bad Request
        break;
}

function insertExpenseType($data, $conn) {
    try {
        $userID = $data['userID'];
        $hotelID = $data['hotelID'];
        $expName = $data['expName'];

        $sql = "INSERT INTO expencetype (userID, HotelID, ExpName, CreatedDate, UpdatedDate) 
                VALUES (:userID, :hotelID, :expName, NOW(), NOW())";
        
        $stmt = $conn->prepare($sql);

        $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
        $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        $stmt->bindParam(':expName', $expName, PDO::PARAM_STR);

        if ($stmt->execute()) {
            $insertedID = $conn->lastInsertId();
            http_response_code(201); // Created
            echo json_encode([
                "message" => "Expense type added successfully.",
                "ExpTypeID" => $insertedID,
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

function updateExpenseType($data, $conn) {
    try {
        $expTypeID = $data['expTypeID'];
        $userID = $data['userID'];
        $hotelID = $data['hotelID'];
        $expName = $data['expName'];

        $sql = "UPDATE expencetype SET 
                    userID = :userID, 
                    HotelID = :hotelID, 
                    ExpName = :expName, 
                    UpdatedDate = NOW() 
                WHERE ExpTypeID = :expTypeID";
        
        $stmt = $conn->prepare($sql);

        $stmt->bindParam(':expTypeID', $expTypeID, PDO::PARAM_INT);
        $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
        $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        $stmt->bindParam(':expName', $expName, PDO::PARAM_STR);

        if ($stmt->execute()) {
            http_response_code(200); // OK
            echo json_encode(["message" => "Expense type updated successfully"]);
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

function getExpenseType($data, $conn) {
    try {
        $expTypeID = isset($data['expTypeID']) ? $data['expTypeID'] : null;
        $userID = isset($data['userID']) ? $data['userID'] : null;
        $hotelID = isset($data['hotelID']) ? $data['hotelID'] : null;

        if ($expTypeID && $userID && $hotelID) {
            $sql = "SELECT * FROM expencetype WHERE ExpTypeID = :expTypeID AND userID = :userID AND HotelID = :hotelID";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':expTypeID', $expTypeID, PDO::PARAM_INT);
            $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
            $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        } elseif ($expTypeID && $userID) {
            $sql = "SELECT * FROM expencetype WHERE ExpTypeID = :expTypeID AND userID = :userID";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':expTypeID', $expTypeID, PDO::PARAM_INT);
            $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
        } elseif ($expTypeID && $hotelID) {
            $sql = "SELECT * FROM expencetype WHERE ExpTypeID = :expTypeID AND HotelID = :hotelID";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':expTypeID', $expTypeID, PDO::PARAM_INT);
            $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        } elseif ($userID && $hotelID) {
            $sql = "SELECT * FROM expencetype WHERE userID = :userID AND HotelID = :hotelID";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
            $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        } elseif ($expTypeID) {
            $sql = "SELECT * FROM expencetype WHERE ExpTypeID = :expTypeID";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':expTypeID', $expTypeID, PDO::PARAM_INT);
        } elseif ($userID) {
            $sql = "SELECT * FROM expencetype WHERE userID = :userID";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
        } elseif ($hotelID) {
            $sql = "SELECT * FROM expencetype WHERE HotelID = :hotelID";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        } else {
            $sql = "SELECT * FROM expencetype";
            $stmt = $conn->prepare($sql);
        }

        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $expenseTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["expenseTypes" => $expenseTypes]);
        } else {
            echo json_encode(["message" => "No expense types found"]);
            http_response_code(404); // Not Found
        }
    } catch (Exception $e) {
        http_response_code(500); // Internal Server Error
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
    }
}


function deleteExpenseType($data, $conn) {
    try {
        $expTypeID = $data['expTypeID'];

        $sql = "DELETE FROM expencetype WHERE ExpTypeID = :expTypeID";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':expTypeID', $expTypeID, PDO::PARAM_INT);

        if ($stmt->execute()) {
            http_response_code(200); // OK
            echo json_encode(["message" => "Expense type deleted successfully"]);
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
