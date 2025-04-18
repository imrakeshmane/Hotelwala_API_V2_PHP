<?php
include '../db.php';
include '../validate.php';

header('Content-Type: application/json');
$requestMethod = $_SERVER['REQUEST_METHOD'];

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
        createExpenseType($conn, $userID, $userType);
        break;
    case 'GET':
        getExpenseTypes($conn, $userID, $userType);
        break;
    case 'PUT':
        updateExpenseType($conn, $userID, $userType);
        break;
    case 'DELETE':
        deleteExpenseType($conn, $userID, $userType);
        break;
    default:
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
        break;
}

function createExpenseType($conn, $userID, $userType) {
    if (!in_array($userType, ['owner', 'manager'])) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can create expense types"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['hotel_id'], $data['expense_type_name'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $sql = "SELECT hotel_id FROM hotels WHERE hotel_id = :hotel_id ";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $data['hotel_id']);
    
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Hotel not found or access denied"]);
        return;
    }

    $sql = "INSERT INTO expense_types (hotel_id, expense_type_name, created_at, updated_at) 
            VALUES (:hotel_id, :expense_type_name, NOW(), NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $data['hotel_id']);
    $stmt->bindParam(':expense_type_name', $data['expense_type_name']);

    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(["message" => "Expense type created successfully"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error creating expense type"]);
    }
}

function getExpenseTypes($conn, $userID, $userType) {
    if (!isset($_GET['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "Hotel ID is required"]);
        return;
    }

    $hotelId = $_GET['hotel_id'];

    $sql = "SELECT hotel_id FROM hotels WHERE hotel_id = :hotel_id ";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Hotel not found or access denied"]);
        return;
    }

    $sql = "SELECT * FROM expense_types WHERE hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $hotelId);
    $stmt->execute();
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode(["expense_types" => $types]);
}

function updateExpenseType($conn, $userID, $userType) {
    if (!in_array($userType, ['owner', 'manager'])) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can update expense types"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['expense_type_id'], $data['hotel_id'], $data['expense_type_name'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $sql = "SELECT hotel_id FROM hotels WHERE hotel_id = :hotel_id ";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $data['hotel_id']);
    
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Hotel not found or access denied"]);
        return;
    }

    $sql = "UPDATE expense_types SET expense_type_name = :expense_type_name, updated_at = NOW()
            WHERE expense_type_id = :expense_type_id AND hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':expense_type_name', $data['expense_type_name']);
    $stmt->bindParam(':expense_type_id', $data['expense_type_id']);
    $stmt->bindParam(':hotel_id', $data['hotel_id']);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(["message" => "Expense type updated successfully"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error updating expense type"]);
    }
}

function deleteExpenseType($conn, $userID, $userType) {
    if (!in_array($userType, ['owner', 'manager'])) {
        http_response_code(403);
        echo json_encode(["error" => "Only owners or managers can delete expense types"]);
        return;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['expense_type_id'], $data['hotel_id'])) {
        http_response_code(400);
        echo json_encode(["error" => "Expense Type ID and Hotel ID are required"]);
        return;
    }

    $sql = "SELECT hotel_id FROM hotels WHERE hotel_id = :hotel_id ";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotel_id', $data['hotel_id']);
    
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Hotel not found or access denied"]);
        return;
    }

    $sql = "DELETE FROM expense_types WHERE expense_type_id = :expense_type_id AND hotel_id = :hotel_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':expense_type_id', $data['expense_type_id']);
    $stmt->bindParam(':hotel_id', $data['hotel_id']);

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(["message" => "Expense type deleted successfully"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error deleting expense type"]);
    }
}
?>
