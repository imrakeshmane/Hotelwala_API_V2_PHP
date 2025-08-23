<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// db.php

$servername = "localhost";
$username = "slakeomr_hotelwala";
$password = "Hotelwala@2025";
$dbname = "slakeomr_hotelwala_v2";
$GLOBALS['secretKey'] = 'HotelWalaApp';

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    // set the PDO error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    die();
}
?>
