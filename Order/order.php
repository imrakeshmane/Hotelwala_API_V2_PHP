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
    case 'getRunningItems':
        getRunningItems($request, $conn);
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
        $categoryID = $data['categoryID'];
        $orderDateTime = $data['orderDateTime'];  // Ensure this is in correct format
        $orderList = json_encode($data['orderList']);  // Serialize orderList (assuming it's an array)
        $userNameList = json_encode($data['userNameList']);  // Serialize userNameList (assuming it's an array)
        $tableObject = $data['tableObject'];

        // Insert order into orderdata table
        $query = "INSERT INTO orderdata (HotelID, UserID, Total, OrderList, TableNumber,CategoryID, OrderDateTime, CreatedDate, UpdatedDate, UserNameList) 
                  VALUES (:hotelID, :userID, :total, :orderList, :tableNumber,:categoryID, :orderDateTime, NOW(), NOW(), :userNameList)";
        
        $stmt = $conn->prepare($query);

        // Bind parameters,
        $stmt->bindValue(':hotelID', $hotelID, PDO::PARAM_INT);
        $stmt->bindValue(':userID', $userID, PDO::PARAM_INT);
        $stmt->bindValue(':total', $total, PDO::PARAM_INT);
        $stmt->bindValue(':orderList', $orderList, PDO::PARAM_STR);
        $stmt->bindValue(':tableNumber', $tableNumber, PDO::PARAM_INT);
        $stmt->bindValue(':categoryID', $categoryID, PDO::PARAM_INT);
        $stmt->bindValue(':orderDateTime', $orderDateTime, PDO::PARAM_STR);
        $stmt->bindValue(':userNameList', $userNameList, PDO::PARAM_STR);

        // Execute query
        if ($stmt->execute()) {
            $orderID = $conn->lastInsertId();  // Get the last inserted ID
              updateTable($tableObject, $conn, $orderID); // Call updateTable function after creating order
            
            // echo json_encode(["message" => "Order created successfully", "OrderID" => $orderID]);
            // http_response_code(201); // Created
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
        $isActiveTable = 0;
        $categoryID = $tableObject['categoryID'];
        $categoryName = $tableObject['categoryName'];

        // echo $hotelID," tableNumber: ", $tableNumber," categoryID: ", $categoryID;

        // Check if the table already exists
        $query1 = "SELECT * FROM tabledata WHERE HotelID = :hotelID AND TableNumber = :tableNumber AND CategoryID = :categoryID";
        $stmt1 = $conn->prepare($query1);
        $stmt1->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        $stmt1->bindParam(':tableNumber', $tableNumber, PDO::PARAM_INT);
        $stmt1->bindParam(':categoryID', $categoryID, PDO::PARAM_INT);
        $stmt1->execute();

        if ($stmt1->rowCount() == 0) {
            // Insert new table entry
            $query2 = "INSERT INTO tabledata (UserID, HotelID, TableNumber, IsSplitTable, TableSplitList, MenuCards, Total, IsActiveTable, CreatedDate, UpdatedDate, CategoryID, CategoryName) 
                       VALUES (:userID, :hotelID, :tableNumber, :isSplitTable, :tableSplitList, :menuCards, :total, :isActiveTable, NOW(), NOW(), :categoryID, :categoryName)";
            $stmt2 = $conn->prepare($query2);
            $stmt2->bindParam(':userID', $userID, PDO::PARAM_INT);
            $stmt2->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
            $stmt2->bindParam(':tableNumber', $tableNumber, PDO::PARAM_INT);
            $stmt2->bindParam(':isSplitTable', $isSplitTable, PDO::PARAM_STR);
            $stmt2->bindParam(':tableSplitList', $tableSplitList, PDO::PARAM_STR);
            $stmt2->bindParam(':menuCards', $menuCards, PDO::PARAM_STR);
            $stmt2->bindParam(':total', $total, PDO::PARAM_STR);
            $stmt2->bindParam(':isActiveTable', $isActiveTable, PDO::PARAM_INT);
            $stmt2->bindParam(':categoryID', $categoryID, PDO::PARAM_INT);
            $stmt2->bindParam(':categoryName', $categoryName, PDO::PARAM_STR);
            $stmt2->execute();
            http_response_code(200); 
            echo json_encode(["message" => "Table added successfully"]);
        } else {
            // Update existing table
            $query3 = "UPDATE tabledata SET IsSplitTable = :isSplitTable, TableSplitList = :tableSplitList, MenuCards = :menuCards, Total = :total, IsActiveTable = :isActiveTable, UpdatedDate = NOW() 
                       WHERE HotelID = :hotelID AND TableNumber = :tableNumber AND CategoryID = :categoryID";
            $stmt3 = $conn->prepare($query3);
            $stmt3->bindParam(':isSplitTable', $isSplitTable, PDO::PARAM_STR);
            $stmt3->bindParam(':tableSplitList', $tableSplitList, PDO::PARAM_STR);
            $stmt3->bindParam(':menuCards', $menuCards, PDO::PARAM_STR);
            $stmt3->bindParam(':total', $total, PDO::PARAM_STR);
            $stmt3->bindParam(':isActiveTable', $isActiveTable, PDO::PARAM_INT);
            $stmt3->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
            $stmt3->bindParam(':tableNumber', $tableNumber, PDO::PARAM_INT);
            $stmt3->bindParam(':categoryID', $categoryID, PDO::PARAM_INT);
            $stmt3->execute();
            http_response_code(200); 
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
        $query = "SELECT * FROM orderdata WHERE HotelID = :hotelID";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        $stmt->execute();

        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($orders) > 0) {
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

function getRunningItems($data, $conn) {
    $hotelID = isset($data['hotelID']) ? $data['hotelID'] : null;
    $fromDate = isset($data['fromDate']) ? $data['fromDate'] : null;
    $toDate = isset($data['toDate']) ? $data['toDate'] : null;

    // Build query based on filters
    if ($hotelID && $fromDate && $toDate) {
        $sql = "SELECT * FROM orderdata WHERE HotelID = :hotelID AND CreatedDate >= :fromDate AND CreatedDate <= :toDate";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
        $stmt->bindParam(':fromDate', $fromDate, PDO::PARAM_STR);
        $stmt->bindParam(':toDate', $toDate, PDO::PARAM_STR);
    } elseif ($hotelID) {
        $sql = "SELECT * FROM orderdata WHERE HotelID = :hotelID";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':hotelID', $hotelID, PDO::PARAM_INT);
    } else {
        echo json_encode(["message" => "Invalid parameters"]);
        http_response_code(400); // Bad Request
        return;
    }

    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $finalList = [];

        foreach ($results as $e) {
            $orderList = json_decode($e['OrderList'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Decode the nested JSON string
                $orderList = json_decode($orderList, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (is_array($orderList)) {
                        // echo "OrderList for ID {$e['OrderID']} is a valid array.\n";
                        foreach ($orderList as $o) {
                            if (empty($finalList)) {
                                $finalList[] = [
                                    "MenuID" => $o['MenuID'],
                                    "MenuName" => $o['MenuName'],
                                    "Quantity" => $o['Quantity'],
                                ];
                            } else {
                                $indexOfMenu = array_search($o['MenuID'], array_column($finalList, 'MenuID'));
                                if ($indexOfMenu === false) {
                                    $finalList[] = [
                                        "MenuID" => $o['MenuID'],
                                        "MenuName" => $o['MenuName'],
                                        "Quantity" => $o['Quantity'],
                                    ];
                                } else {
                                    $finalList[$indexOfMenu]['Quantity'] += $o['Quantity'];
                                }
                            }
                        }
                    } else {
                        echo "OrderList for ID {$e['OrderID']} is not an array after second decode.\n";
                    }
                } else {
                    echo "Error decoding nested OrderList for ID {$e['OrderID']}: " . json_last_error_msg() . "\n";
                    echo "Raw nested OrderList data: " . print_r($orderList, true) . "\n";
                }
            } else {
                echo "Error decoding OrderList for ID {$e['OrderID']}: " . json_last_error_msg() . "\n";
                echo "Raw OrderList data: " . $e['OrderList'] . "\n";
            }
        }

        echo json_encode(["RunningItemList" => $finalList]);
    } else {
        echo json_encode(["message" => "RunningItemList not found"]);
        http_response_code(404); // Not Found
    }
}


?>
