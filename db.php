<?php

$host = "mysql.railway.internal";
$port = 3306;
$dbname = "railway";
$username = "root";
$password = "kcOQBchCBbxDWHPzEGOVsQGrBJZMbkrG";

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "✅ DB Connected";

} catch (PDOException $e) {
    die("❌ Error: " . $e->getMessage());
}
