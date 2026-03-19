<?php
$host = "mysql.railway.internal";
$port = 3306;
$dbname = "railway";
$username = "root";
$password = "kcOQBchCBbxDWHPzEGOVsQGrBJZMbkrG";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Auto-migrate new columns
    try { $pdo->exec("ALTER TABLE organizations ADD COLUMN slot_duration_minutes INT DEFAULT 30"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE organizations ADD COLUMN event_type VARCHAR(50) DEFAULT 'google_meet'"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE organizations ADD COLUMN event_address TEXT DEFAULT NULL"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE organizations ADD COLUMN org_logo VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}

    // Multi-Doctor Support
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS doctors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            specialization VARCHAR(255) DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Exception $e) {}

    try { $pdo->exec("ALTER TABLE agent_timeslots ADD COLUMN doctor_id INT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE appointments ADD COLUMN doctor_id INT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE leads ADD COLUMN doctor_id INT DEFAULT NULL"); } catch (Exception $e) {}

    // Webhook Support
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS curl_configs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            curl_endpoint TEXT NOT NULL,
            webhook_event VARCHAR(100) NOT NULL,
            variable_mapping TEXT DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY (org_id, webhook_event)
        )");
    } catch (Exception $e) {
        // If table exists but unique key is missing, try adding it
        try { $pdo->exec("ALTER TABLE curl_configs ADD UNIQUE KEY (org_id, webhook_event)"); } catch (Exception $ex) {}
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS curl_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            project_id VARCHAR(100) DEFAULT NULL,
            webhook_event VARCHAR(100) NOT NULL,
            endpoint TEXT NOT NULL,
            response_code INT DEFAULT NULL,
            error_text TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Exception $e) {}
} catch(PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}
?>