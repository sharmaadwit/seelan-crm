<?php
// upgrade_db.php
require_once 'db.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<h3>Starting Database Upgrade...</h3>";

    // 1. Create users table
    $sql_users = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        org_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($sql_users);
    echo "<p>✅ `users` table created or already exists.</p>";

    // Function to check if a column exists
    function addColumnIfNotExists($pdo, $table, $column, $definition) {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $stmt->execute([$table, $column]);
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo "<p>✅ Added column `$column` to `$table`.</p>";
        } else {
            echo "<p>ℹ️ Column `$column` already exists in `$table`.</p>";
        }
    }

    // 2. Add timezone to organizations
    addColumnIfNotExists($pdo, 'organizations', 'timezone', "VARCHAR(100) DEFAULT 'Asia/Kolkata'");

    // 3. Add new fields to leads
    addColumnIfNotExists($pdo, 'leads', 'cpf_number', "VARCHAR(50) NULL");
    addColumnIfNotExists($pdo, 'leads', 'doctor_type', "VARCHAR(100) NULL");
    addColumnIfNotExists($pdo, 'leads', 'patient_type', "VARCHAR(100) NULL");
    addColumnIfNotExists($pdo, 'leads', 'branch', "VARCHAR(255) NULL");
    addColumnIfNotExists($pdo, 'leads', 'patient_name', "VARCHAR(255) NULL");
    addColumnIfNotExists($pdo, 'leads', 'visit_reason', "TEXT NULL");

    echo "<h3 style='color:green;'>🎉 Database Upgrade Complete! Please delete this file for security.</h3>";
} catch (PDOException $e) {
    echo "<h3 style='color:red;'>❌ Database Error:</h3><p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
