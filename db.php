<?php

$host = "autorack.proxy.rlwy.net";
$port = 50421;
$dbname = "railway";
$username = "root";
$password = "kcOQBchCBbxDWHPzEGOVsQGrBduwjhwrG";

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    echo "✅ DB Connected";

} catch (PDOException $e) {
    die("❌ Error: " . $e->getMessage());
}

?>
