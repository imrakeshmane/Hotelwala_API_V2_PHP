<?php
include '../db.php';
include '../validate.php';

header('Content-Type: application/json');

$jwt = getJWTFromHeader();
if ($jwt === null) {
    http_response_code(401);
    echo json_encode(["error" => "Token is missing or invalid"]);
    return;
}

$payload = validateJWT($jwt, $GLOBALS['secretKey']);
$requestMethod = $_SERVER['REQUEST_METHOD'];

if (isset($payload['error'])) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid token"]);
    return;
}

$request = json_decode(file_get_contents('php://input'), true);

// if (!isset($request['action'])) {
//     http_response_code(400);
//     echo json_encode(["error" => "Action parameter is required"]);
//     exit;
// }

// $action = $request['action'];

switch ($requestMethod) {
    case 'POST':
        insertExpense($request, $conn);
        break;
    case 'PUT':
        updateExpense($request, $conn);
        break;
    case 'GET':
        getExpenses($request, $conn);
        break;
    case 'delete':
        deleteExpense($request, $conn);
        break;
    case 'getByDate':
        getExpensesByDate($request, $conn);
        break;
    default:
        http_response_code(400);
        echo json_encode(["error" => "Invalid action"]);
        break;
}
function insertExpense($data, $conn) {
    try {
        $typeID = $data['expense_type_id'];
        $amount = $data['expense_amount'];
        $desc = $data['expense_description'];

        // Check if the expense_type_id exists
        $typeCheck = $conn->prepare("SELECT 1 FROM expense_types WHERE expense_type_id = :typeID");
        $typeCheck->bindParam(':typeID', $typeID, PDO::PARAM_INT);
        $typeCheck->execute();

        if ($typeCheck->rowCount() === 0) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid expense_type_id. No matching record in expense_types table."]);
            return;
        }

        // Proceed with insertion
        $sql = "INSERT INTO expenses (expense_type_id, expense_amount, expense_description, created_at, updated_at)
                VALUES (:typeID, :amount, :descp, NOW(), NOW())";
        $stmt = $conn->prepare($sql);

        $stmt->bindParam(':typeID', $typeID);
        $stmt->bindParam(':amount', $amount);
        $stmt->bindParam(':descp', $desc);

        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode([
                "message" => "Expense added successfully",
                "expense_id" => $conn->lastInsertId()
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => $stmt->errorInfo()[2]]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
}


function updateExpense($data, $conn) {
    try {
        $id = $data['expense_id'];
        $typeID = $data['expense_type_id'];
        $amount = $data['expense_amount'];
        $desc = $data['expense_description'];

        $sql = "UPDATE expenses SET expense_type_id = :typeID, expense_amount = :amount, 
                expense_description = :desc, updated_at = NOW() WHERE expense_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':typeID', $typeID);
        $stmt->bindParam(':amount', $amount);
        $stmt->bindParam(':desc', $desc);
        $stmt->bindParam(':id', $id);

        if ($stmt->execute()) {
            echo json_encode(["message" => "Expense updated successfully"]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => $stmt->errorInfo()[2]]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
}

function getExpenses($data, $conn) {
    try {
        $sql = "SELECT * FROM expenses ORDER BY created_at DESC";
        $stmt = $conn->query($sql);
        $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["expenses" => $expenses]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
}

function getExpensesByDate($data, $conn) {
    try {
        $from = $data['fromDate'];
        $to = $data['toDate'];

        $sql = "SELECT * FROM expenses WHERE created_at BETWEEN :from AND :to ORDER BY created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':from', $from);
        $stmt->bindParam(':to', $to);
        $stmt->execute();
        $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["expenses" => $expenses]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
}

function deleteExpense($data, $conn) {
    try {
        $id = $data['expense_id'];

        $sql = "DELETE FROM expenses WHERE expense_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':id', $id);

        if ($stmt->execute()) {
            echo json_encode(["message" => "Expense deleted successfully"]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => $stmt->errorInfo()[2]]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
}
?>
