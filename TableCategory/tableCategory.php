<?php
include '../db.php'; // Include the database connection file
include '../validate.php';

$jwt = getJWTFromHeader();
if ($jwt === null) {
    http_response_code(401); // Unauthorized
    return;
}

$payload = validateJWT($jwt, $GLOBALS['secretKey']);
if (isset($payload['error'])) {
    http_response_code(401); // Unauthorized
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
        insertTableCategory($request, $conn);
        break;
    case 'update':
        updateTableCategory($request, $conn);
        break;
    case 'get':
        getTableCategoryList($request, $conn);
        break;
    case 'delete':
        deleteTableCategory($request, $conn);
        break;
    default:
        echo json_encode(["error" => "Invalid action"]);
        http_response_code(400); // Bad Request
        break;
}

function insertTableCategory($data, $conn) {
    $hotelID = $data['hotelID'];
    $userID = $data['userID'];
    $name = $data['name'];
    $tableNumber = $data['tableNumber'];

    $sql = "INSERT INTO tablecategory (HotelID, UserID, Name, TableNumber, CreatedDate, UpdatedDate)
            VALUES (:hotelID, :userID, :name, :tableNumber, NOW(), NOW())";

    $stmt = $conn->prepare($sql);

    $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
    $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
    $stmt->bindParam(':name', $name, PDO::PARAM_STR);
    $stmt->bindParam(':tableNumber', $tableNumber, PDO::PARAM_INT);

    if ($stmt->execute()) {
        $insertedID = $conn->lastInsertId();

        for ($i = 0; $i < $tableNumber; $i++) {
            $data = [
                'hotelID' => $hotelID,
                'userID' => $userID,
                'name' => $name,
                'tblNumber' => $i + 1,
                'isSplitTable' => 0,
                'tableSplitList' => '',
                'menuCards' => '',
                'total' => 0,
                'categoryID' => $insertedID,
                'categoryName' => $name,
                'isActiveTable' => 0
            ];
            createTable($data, $conn);
        }

        http_response_code(200);
        echo json_encode(["message" => "Table Category item Added successfully"]);
    } else {
        $errorInfo = $stmt->errorInfo();
        http_response_code(500);
        echo json_encode(["error" => "Error: " . $errorInfo[2]]);
    }
}

function createTable($data, $conn) {
    $userID = $data['userID'];
    $hotelID = $data['hotelID'];
    $tblNumber = $data['tblNumber'];
    $isSplitTable = $data['isSplitTable'];
    $tableSplitList = $data['tableSplitList'];
    $menuCards = $data['menuCards'];
    $total = $data['total'];
    $isActiveTable = $data['isActiveTable'];
    $categoryID = $data['categoryID'];
    $categoryName = $data['categoryName'];

    $sql = "SELECT * FROM tabledata WHERE HotelID = :hotelID AND CategoryID = :categoryID AND TableNumber = :tblNumber";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
    $stmt->bindParam(':categoryID', $categoryID, PDO::PARAM_INT);
    $stmt->bindParam(':tblNumber', $tblNumber, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() == 0) {
        $sql = "INSERT INTO tabledata (UserID, HotelID, TableNumber, IsSplitTable, TableSplitList, MenuCards, Total, IsActiveTable, CreatedDate, UpdatedDate, CategoryID, CategoryName)
                VALUES (:userID, :hotelID, :tblNumber, :isSplitTable, :tableSplitList, :menuCards, :total, :isActiveTable, NOW(), NOW(), :categoryID, :categoryName)";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':userID', $userID, PDO::PARAM_INT);
        $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        $stmt->bindParam(':tblNumber', $tblNumber, PDO::PARAM_INT);
        $stmt->bindParam(':isSplitTable', $isSplitTable, PDO::PARAM_INT);
        $stmt->bindParam(':tableSplitList', $tableSplitList, PDO::PARAM_STR);
        $stmt->bindParam(':menuCards', $menuCards, PDO::PARAM_STR);
        $stmt->bindParam(':total', $total, PDO::PARAM_INT);
        $stmt->bindParam(':isActiveTable', $isActiveTable, PDO::PARAM_INT);
        $stmt->bindParam(':categoryID', $categoryID, PDO::PARAM_INT);
        $stmt->bindParam(':categoryName', $categoryName, PDO::PARAM_STR);

        if (!$stmt->execute()) {
            $errorInfo = $stmt->errorInfo();
            http_response_code(500);
            echo json_encode(["error" => "Error: " . $errorInfo[2]]);
        }
    }
}

function updateTableCategory($data, $conn) {
    $categoryID = $data['categoryID'];
    $hotelID = $data['hotelID'];
    $userID = $data['userID'];
    $name = $data['name'];
    $tableNumber = $data['tableNumber'];

    $sql = "UPDATE tablecategory SET Name = :name, TableNumber = :tableNumber, UpdatedDate = NOW() WHERE CategoryID = :categoryID";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':categoryID', $categoryID, PDO::PARAM_INT);
    $stmt->bindParam(':name', $name, PDO::PARAM_STR);
    $stmt->bindParam(':tableNumber', $tableNumber, PDO::PARAM_INT);

    if ($stmt->execute()) {
        for ($i = 0; $i < $tableNumber; $i++) {
            $data = [
                'hotelID' => $hotelID,
                'userID' => $userID,
                'name' => $name,
                'tblNumber' => $i + 1,
                'isSplitTable' => 0,
                'tableSplitList' => '',
                'menuCards' => '',
                'total' => 0,
                'categoryID' => $categoryID,
                'categoryName' => $name,
                'isActiveTable' => 0
            ];
            createTable($data, $conn);
        }

        http_response_code(200);
        echo json_encode(["message" => "Table Category Updated successfully"]);
    } else {
        $errorInfo = $stmt->errorInfo();
        http_response_code(500);
        echo json_encode(["error" => "Error: " . $errorInfo[2]]);
    }
}

function deleteTableCategory($data, $conn) {
    $categoryID = $data['categoryID'];
    $hotelID = $data['hotelID'];


    try {
        // Begin transaction
        $conn->beginTransaction();

        // Fetch the category and its related tables

        // Get the tables associated with the category
        $tables = getTable($hotelID,  $categoryID, $conn);

        // If tables were found, delete them
        if (!empty($tables)) {
            foreach ($tables as $table) {
                // Assuming you have some specific logic to delete related data from other tables
                // before deleting the table itself
                // deleteRelatedData($table['ID'], $conn); // Implement this function as needed

                // Delete the table
                $sql = "DELETE FROM tabledata WHERE ID = :tableID";
                $stmt = $conn->prepare($sql);
                $stmt->bindParam(':tableID', $table['ID'], PDO::PARAM_INT);
                $stmt->execute();
            }
        }

        // Delete the category
        $sql = "DELETE FROM tablecategory WHERE CategoryID = :categoryID";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':categoryID', $categoryID, PDO::PARAM_INT);

        if ($stmt->execute()) {
            // Commit transaction
            $conn->commit();
            http_response_code(200);
            echo json_encode(["message" => "Table Category and related tables deleted successfully"]);
        } else {
            // Rollback transaction
            $conn->rollBack();
            $errorInfo = $stmt->errorInfo();
            http_response_code(500);
            echo json_encode(["error" => "Error: " . $errorInfo[2]]);
        }
    } catch (PDOException $e) {
        // Rollback transaction in case of error
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(["error" => "Internal server error", "details" => $e->getMessage()]);
    }
}

function getTableCategoryList($data, $conn) {
    // Fetch all table categories
    $sql = "SELECT * FROM tablecategory";
    if (isset($data['hotelID'])) {
        $sql .= " WHERE HotelID = :hotelID";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':hotelID', $data['hotelID'], PDO::PARAM_INT);
    } else {
        $stmt = $conn->prepare($sql);
    }

    $stmt->execute();

    // Check if categories are found
    if ($stmt->rowCount() > 0) {
        $tableCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Loop through each category to get the tables for that category
        $response = []; // Initialize the response array
        foreach ($tableCategories as &$category) {
            $tables = getTable($category['HotelID'], $category['CategoryID'], $conn); // Fetch tables for the category
            
            // Add tables to the category array
            $category['Tables'] = $tables;

            // Add the category and its associated tables to the response array
            $response[] = $category; // Now both category and tables are together in the same object
        }

        // Return categories with tables in one object
        echo json_encode(["CategoryWithTables" => $response]);  
     } else {
        http_response_code(404);
        echo json_encode(["message" => "No Table Categories found"]);
    }
}

function getTable($hotelID, $catID, $conn) {
    try {
        // Prepare the SQL query to get tables for a specific category and hotel
        $sql = "SELECT * FROM tabledata WHERE HotelID = :hotelID AND CategoryID = :catID";
        $stmt = $conn->prepare($sql);

        // Bind parameters
        $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        $stmt->bindParam(':catID', $catID, PDO::PARAM_INT);

        // Execute the query
        $stmt->execute();

        // Check if any rows were returned
        if ($stmt->rowCount() == 0) {
            // No tables found
            return []; // Return an empty array for no tables found
        } else {
            // Return the result as an associative array
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Log the error
        error_log("Error in getTable: " . $e->getMessage());

        // Return internal server error
        echo json_encode(["error" => "Internal server error", "details" => $e->getMessage()]);
        http_response_code(500); // Internal Server Error
        return []; // Return an empty array in case of error
    }
}


?>
