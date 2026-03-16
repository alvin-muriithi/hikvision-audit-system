<?php
$servername = "localhost";
$username = "root";
$password = "Alvinmuriithi!8";
$dbname = "hikvision_audit";
$dbcharset = "utf8mb4";

try {
  $conn = new PDO("mysql:host=$servername;dbname=$dbname;charset=$dbcharset", $username, $password);
  // set the PDO error mode to exception
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  echo "Connected successfully";
} catch(PDOException $e) {
  echo "Connection failed: " . $e->getMessage();
}
?>