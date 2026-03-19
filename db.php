<?php
$host = 'localhost';
$dbname = 'medical_dashboard'; // Ensure this database exists in your MySQL server
$username = 'phpmyadmin'; 
$password = '0]KLn{XbLuNn$^'; 

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}

try {
    $pdo->exec("ALTER TABLE organizations ADD COLUMN gupshup_app_id VARCHAR(100) DEFAULT NULL");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE organizations ADD COLUMN gupshup_api_key VARCHAR(255) DEFAULT NULL");
} catch (Exception $e) {}
?>