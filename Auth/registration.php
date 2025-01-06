<?php
include '../db.php'; // Include your database connection

header('Content-Type: application/json');
$requestMethod = $_SERVER['REQUEST_METHOD'];

if ($requestMethod == 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (!isset($data['owner_name'], $data['owner_email'], $data['owner_phone_number'], $data['owner_password'])) {
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    // Destructure the input data
    $ownerName = $data['owner_name'];
    $ownerEmail = $data['owner_email'];
    $ownerPhoneNumber = $data['owner_phone_number'];
    $ownerPassword = password_hash($data['owner_password'], PASSWORD_BCRYPT);

    // Check if the email or phone number already exists in the Owners table
    $checkSql = "SELECT * FROM Owners WHERE owner_email = :owner_email OR owner_phone_number = :owner_phone_number";
    $stmt = $conn->prepare($checkSql);
    $stmt->bindParam(':owner_email', $ownerEmail);
    $stmt->bindParam(':owner_phone_number', $ownerPhoneNumber);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        echo json_encode(["error" => "Email or phone number already exists"]);
        return;
    }

    // Insert the new owner
    $sql = "INSERT INTO Owners (owner_name, owner_email, owner_phone_number, owner_password) 
            VALUES (:owner_name, :owner_email, :owner_phone_number, :owner_password)";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':owner_name', $ownerName);
    $stmt->bindParam(':owner_email', $ownerEmail);
    $stmt->bindParam(':owner_phone_number', $ownerPhoneNumber);
    $stmt->bindParam(':owner_password', $ownerPassword);

    if ($stmt->execute()) {
        echo json_encode(["message" => "Owner registered successfully"]);
    } else {
        echo json_encode(["error" => "Error registering owner"]);
    }
}
?>
