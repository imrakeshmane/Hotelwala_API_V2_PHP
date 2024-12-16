<?php
include '../db.php'; // Include the database connection file
include '../validate.php'; // Include the JWT validation file

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
        createOrder($request, $conn);
        break;
    case 'update':
        updateOrder($request, $conn);
        break;
    case 'get':
        getOrders($request, $conn);
        break;
    case 'getOrdersFilter':
        getOrdersFilter($request, $conn);
        break;
    case 'delete':
        deleteOrder($request, $conn);
        break;
    default:
        echo json_encode(["error" => "Invalid action"]);
        http_response_code(400); // Bad Request
        break;
}
function createOrder($data, $conn) {
    try {
        // Extract data from the incoming request
        $hotelID = $data['hotelID'];
        $userID = $data['userID'];
        $total = $data['total'];
        $tableNumber = $data['tableNumber'];
        $orderDateTime = $data['orderDateTime'];  // Ensure this is in correct format
        $orderList = json_encode($data['orderList']);  // Serialize orderList (assuming it's an array)
        $userNameList = json_encode($data['userNameList']);  // Serialize userNameList (assuming it's an array)
        $tableObject = $data['tableObject'];

        // Insert order into orderdata table
        $query = "INSERT INTO orderdata (HotelID, UserID, Total, OrderList, TableNumber, OrderDateTime, CreatedDate, UpdatedDate, UserNameList) 
                  VALUES (:hotelID, :userID, :total, :orderList, :tableNumber, :orderDateTime, NOW(), NOW(), :userNameList)";
        
        $stmt = $conn->prepare($query);

        // Bind parameters
        $stmt->bindValue(':hotelID', $hotelID, PDO::PARAM_INT);
        $stmt->bindValue(':userID', $userID, PDO::PARAM_INT);
        $stmt->bindValue(':total', $total, PDO::PARAM_INT);
        $stmt->bindValue(':orderList', $orderList, PDO::PARAM_STR);
        $stmt->bindValue(':tableNumber', $tableNumber, PDO::PARAM_INT);
        $stmt->bindValue(':orderDateTime', $orderDateTime, PDO::PARAM_STR);
        $stmt->bindValue(':userNameList', $userNameList, PDO::PARAM_STR);

        // Execute query
        if ($stmt->execute()) {
            $orderID = $conn->lastInsertId();  // Get the last inserted ID
              updateTable($tableObject, $conn, $orderID); // Call updateTable function after creating order
            
            echo json_encode(["message" => "Order created successfully", "OrderID" => $orderID]);
            http_response_code(201); // Created
        } else {
            echo json_encode(["error" => "Failed to create order"]);
            http_response_code(500); // Internal Server Error
        }
    } catch (Exception $e) {
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
        http_response_code(500); // Internal Server Error
    }
}


function updateTable($tableObject, $conn, $orderID) {
    try {
        $userID = $tableObject['userID'];
        $hotelID = $tableObject['hotelID'];
        $tableNumber = $tableObject['tableNumber'];
        $isSplitTable = $tableObject['isSplitTable'];
        $tableSplitList = $tableObject['tableSplitList'];
        $menuCards = $tableObject['menuCards'];
        $total = 0;
        $isActiveTable =0;
        $categoryID = $tableObject['categoryID'];
        $categoryName = $tableObject['categoryName'];

        echo $hotelID, $tableNumber, $categoryID;

        // Check if the table already exists
        $query1 = "SELECT * FROM tabledata WHERE HotelID = ? AND TableNumber = ? AND CategoryID = ?";
        $stmt1 = $conn->prepare($query1);
        $stmt1->bind_param('iii', $hotelID, $tableNumber, $categoryID);
        $stmt1->execute();
        $result1 = $stmt1->get_result();

        if ($result1->num_rows == 0) {
            // Insert new table entry
            $query2 = "INSERT INTO tabledata (UserID, HotelID, TableNumber, IsSplitTable, TableSplitList, MenuCards, Total, IsActiveTable, CreatedDate, UpdatedDate, CategoryID, CategoryName) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)";
            $stmt2 = $conn->prepare($query2);
            $stmt2->bind_param('iiissssss', $userID, $hotelID, $tableNumber, $isSplitTable, $tableSplitList, $menuCards, $total, $isActiveTable,now(),now(), $categoryID, $categoryName);
            $stmt2->execute();
            echo json_encode(["message" => "Table added successfully"]);
        } else {
            // Update existing table
            $query3 = "UPDATE tabledata SET IsSplitTable = ?, TableSplitList = ?, MenuCards = ?, Total = ?, IsActiveTable = ?, UpdatedDate = NOW() 
                       WHERE HotelID = ? AND TableNumber = ? AND CategoryID = ?";
            $stmt3 = $conn->prepare($query3);
            $stmt3->bind_param('sssssiii', $isSplitTable, $tableSplitList, $menuCards, $total, $isActiveTable, $hotelID, $tableNumber, $categoryID);
            $stmt3->execute();
            echo json_encode(["message" => "Table updated successfully"]);
        }
    } catch (Exception $e) {
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
        http_response_code(500); // Internal Server Error
    }
}

function updateOrder($data, $conn) {
    try {
        $orderID = $data['orderID'];
        $total = $data['total'];
        $orderList = $data['orderList'];
        $userNameList = $data['userNameList'];

        // Update order in orderdata table
        $query = "UPDATE orderdata SET Total = ?, OrderList = ?, UserNameList = ?, UpdatedDate = NOW() WHERE OrderID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('sssi', $total, $orderList, $userNameList, $orderID);

        if ($stmt->execute()) {
            echo json_encode(["message" => "Order updated successfully"]);
            http_response_code(200); // OK
        } else {
            echo json_encode(["error" => "Failed to update order"]);
            http_response_code(500); // Internal Server Error
        }
    } catch (Exception $e) {
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
        http_response_code(500); // Internal Server Error
    }
}

function getOrders($data, $conn) {
    try {
        $hotelID = $data['hotelID'];
        $query = "SELECT * FROM orderdata WHERE HotelID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $hotelID);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $orders = $result->fetch_all(MYSQLI_ASSOC);
            echo json_encode(["orders" => $orders]);
        } else {
            echo json_encode(["message" => "No orders found"]);
            http_response_code(404); // Not Found
        }
    } catch (Exception $e) {
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
        http_response_code(500); // Internal Server Error
    }
}

function getOrdersFilter($data, $conn) {
    try {
        $hotelID = $data['hotelID'];
        $fromDate = $data['fromDate'];
        $toDate = $data['toDate'];
        $query = "SELECT * FROM orderdata WHERE HotelID = ? AND CreatedDate BETWEEN ? AND ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('iss', $hotelID, $fromDate, $toDate);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $orders = $result->fetch_all(MYSQLI_ASSOC);
            echo json_encode(["orders" => $orders]);
        } else {
            echo json_encode(["message" => "No orders found"]);
            http_response_code(404); // Not Found
        }
    } catch (Exception $e) {
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
        http_response_code(500); // Internal Server Error
    }
}

function deleteOrder($data, $conn) {
    try {
        $orderID = $data['orderID'];
        $query = "DELETE FROM orderdata WHERE OrderID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $orderID);
        if ($stmt->execute()) {
            echo json_encode(["message" => "Order deleted successfully"]);
            http_response_code(200); // OK
        } else {
            echo json_encode(["error" => "Failed to delete order"]);
            http_response_code(500); // Internal Server Error
        }
    } catch (Exception $e) {
        echo json_encode(["error" => "Error: " . $e->getMessage()]);
        http_response_code(500); // Internal Server Error
    }
}
?>