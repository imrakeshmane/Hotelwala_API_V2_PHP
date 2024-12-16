<?php
include '../db.php'; // Include the database connection file
include '../validate.php';

$jwt = getJWTFromHeader();
if ($jwt === null) {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Unauthorized: Missing JWT']);
    exit;
}

$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Unauthorized: Invalid JWT']);
    exit;
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
        createTable($request, $conn);
        break;
    case 'update':
        updateTable($request, $conn);
        break;
    case 'get':
        getTable($request, $conn);
        break;
    case 'delete':
        deleteTableID($request, $conn);
        break;
    default:
        echo json_encode(["error" => "Invalid action"]);
        http_response_code(400); // Bad Request
        break;
}

function createTable($data, $conn) {
    try {
        $userID = $data['userID'];
        $hotelID = $data['hotelID'];
        $tableNumber = $data['tableNumber'];
        $isSplitTable = $data['isSplitTable'];
        $tableSplitList = $data['tableSplitList'];
        $menuCards = $data['menuCards'];
        $total = $data['total'];
        $isActiveTable = $data['isActiveTable'];
        $categoryID = $data['categoryID'];
        $categoryName = $data['categoryName'];

        // Check if the table already exists
        $query1 = "SELECT * FROM tabledata WHERE HotelID=? AND TableNumber=? AND CategoryID=?";
        $stmt1 = $conn->prepare($query1);
        $stmt1->execute([$hotelID, $tableNumber, $categoryID]);
        $findTable = $stmt1->fetchAll(PDO::FETCH_ASSOC);

        if (empty($findTable)) {
            // Insert new table
            $query = "
            INSERT INTO tabledata (UserID, HotelID, TableNumber, IsSplitTable, TableSplitList, MenuCards, Total, IsActiveTable, CreatedDate, UpdatedDate, CategoryID, CategoryName)
            VALUES (:userID, :hotelID, :tableNumber, :isSplitTable, :tableSplitList, :menuCards, :total, :isActiveTable, NOW(), NOW(), :categoryID, :categoryName)";
            $stmt = $conn->prepare($query);

            $stmt->bindParam(':userID', $userID);
            $stmt->bindParam(':hotelID', $hotelID);
            $stmt->bindParam(':tableNumber', $tableNumber);
            $stmt->bindParam(':isSplitTable', $isSplitTable);
            $stmt->bindParam(':tableSplitList', $tableSplitList);
            $stmt->bindParam(':menuCards', $menuCards);
            $stmt->bindParam(':total', $total);
            $stmt->bindParam(':isActiveTable', $isActiveTable);
            $stmt->bindParam(':categoryID', $categoryID);
            $stmt->bindParam(':categoryName', $categoryName);

            if ($stmt->execute()) {
                echo json_encode(['message' => 'Table added successfully']);
                http_response_code(201); // Created
            } else {
                http_response_code(500); // Internal Server Error
                echo json_encode(['error' => 'Table not added']);
            }
        } else {
            // Table already exists, so update it

            $oldQuery = "SELECT * FROM tabledata WHERE ID = :id";
            $stmt = $conn->prepare($oldQuery);
            $stmt->bindParam(':id', $findTable[0]['ID']);
            $stmt->execute();
            $oldData = $stmt->fetch(PDO::FETCH_ASSOC);

            $query = "
            UPDATE tabledata SET 
            TableNumber=:tableNumber, IsSplitTable=:isSplitTable, TableSplitList=:tableSplitList, MenuCards=:menuCards, Total=:total, IsActiveTable=:isActiveTable, UpdatedDate=NOW() 
            WHERE ID=:id";
            $stmt = $conn->prepare($query);

            $stmt->bindParam(':tableNumber', $tableNumber);
            $stmt->bindParam(':isSplitTable', $isSplitTable);
            $stmt->bindParam(':tableSplitList', $tableSplitList);
            $stmt->bindParam(':menuCards', $menuCards);
            $stmt->bindParam(':total', $total);
            $stmt->bindParam(':isActiveTable', $isActiveTable);
            $stmt->bindParam(':id', $findTable[0]['ID']);
            $stmt->execute();

            $newQuery = "SELECT * FROM tabledata WHERE ID = :id";
            $stmt = $conn->prepare($newQuery);
            $stmt->bindParam(':id', $findTable[0]['ID']);
            $stmt->execute();
            $newData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($oldData !== $newData) {
                echo json_encode(['message' => 'Table updated successfully']);
                http_response_code(200); // OK
            } else {
                echo json_encode(['message' => 'No changes made']);
                http_response_code(200); // OK, but no changes
            }
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        http_response_code(500); // Internal Server Error
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log($e->getMessage());
        http_response_code(500); // Internal Server Error
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
}

function updateTable($data, $conn) {
    
}

function getTable($data, $conn) {
    try {
        $hotelID = $data['hotelID'];

        $query = "SELECT * FROM tabledata WHERE HotelID=:hotelID";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':hotelID', $hotelID);
        $stmt->execute();

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($result)) {
            echo json_encode(['Tables' => $result]);
            http_response_code(200); // OK
        } else {
            echo json_encode(['message' => 'No tables found']);
            http_response_code(404); // Not Found
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        http_response_code(500); // Internal Server Error
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function deleteTableID($data, $conn) {
    try {
        $id = $data['id'];

        $query = "DELETE FROM tabledata WHERE ID = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            echo json_encode(['message' => 'Table deleted successfully']);
            http_response_code(200); // OK
        } else {
            echo json_encode(['message' => 'Table not found']);
            http_response_code(404); // Not Found
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        http_response_code(500); // Internal Server Error
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}
?>
