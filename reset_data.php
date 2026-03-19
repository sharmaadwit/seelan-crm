<?php
// reset_data.php
require_once 'db.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<h3>Starting Database Reset & Seeding...</h3>";

    // Disable foreign key checks temporarily to truncate tables
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    
    // Clear existing data safely (ignoring if table doesn't exist)
    $tables = [
        'message_logs', 'comments', 'appointments', 'lead_campaigns',
        'leads', 'campaigns', 'agent_timeslots', 'wa_event_mappings',
        'wa_templates', 'curl_configs', 'curl_logs', 'users', 'organizations'
    ];
    
    foreach ($tables as $t) {
        try {
            $pdo->exec("TRUNCATE TABLE `$t`");
        } catch (Exception $e) {
            // Ignore missing tables
        }
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    
    echo "<p>✅ All existing data cleared.</p>";

    // ----- SEED DATA -----
    
    // 1. Create a Sample Organization (Project)
    $org_name = 'Sample Project 1';
    $org_email = 'admin@sample.com';
    $org_pass = password_hash('password123', PASSWORD_DEFAULT);
    $api_key = 'sk_live_sample_1234567890';
    $timezone = 'Asia/Kolkata';

    $stmt = $pdo->prepare("INSERT INTO organizations (org_name, email, password, api_key, timezone) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$org_name, $org_email, $org_pass, $api_key, $timezone]);
    $org_id = $pdo->lastInsertId();

    echo "<p>✅ Sample Organization/Project Created: <b>$org_name</b> (Admin Email: $org_email | Pass: password123)</p>";
    echo "<p>🔑 <b>Sample API Key:</b> $api_key</p>";

    // 2. Create a Sample Standard User under this Organization
    $user_name = 'Dr. Smith';
    $user_email = 'user@sample.com';
    $user_pass = password_hash('user123', PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("INSERT INTO users (org_id, name, email, password) VALUES (?, ?, ?, ?)");
    $stmt->execute([$org_id, $user_name, $user_email, $user_pass]);

    echo "<p>✅ Sample Standard User Created: <b>$user_name</b> (Email: $user_email | Pass: user123)</p>";

    // 3. Create Sample Leads
    // Check if email column exists in leads
    $stmt = $pdo->query("SHOW COLUMNS FROM `leads` LIKE 'email'");
    $hasEmail = ($stmt->fetch() !== false);

    if ($hasEmail) {
        $stmt = $pdo->prepare("INSERT INTO leads (org_id, name, mobile, email, status, cpf_number, doctor_type, patient_type, branch, patient_name, visit_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $org_id, 
            'John Parent (Lead)', 
            '9876543210', 
            'john@example.com', 
            'New', 
            '123.456.789-00', 
            'Pediatrician', 
            'New Patient', 
            'Downtown Clinic', 
            'Little Timmy', 
            'High fever and consistent cough'
        ]);

        $stmt->execute([
            $org_id, 
            'Mary Jane', 
            '5551234567', 
            'mary@example.com', 
            'In Progress', 
            '987.654.321-11', 
            'Orthopedics', 
            'Returning', 
            'Uptown Branch', 
            'Mary Jane', 
            'Follow-up on knee surgery'
        ]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO leads (org_id, name, mobile, status, cpf_number, doctor_type, patient_type, branch, patient_name, visit_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $org_id, 
            'John Parent (Lead)', 
            '9876543210', 
            'New', 
            '123.456.789-00', 
            'Pediatrician', 
            'New Patient', 
            'Downtown Clinic', 
            'Little Timmy', 
            'High fever and consistent cough'
        ]);

        $stmt->execute([
            $org_id, 
            'Mary Jane', 
            '5551234567', 
            'In Progress', 
            '987.654.321-11', 
            'Orthopedics', 
            'Returning', 
            'Uptown Branch', 
            'Mary Jane', 
            'Follow-up on knee surgery'
        ]);
    }

    echo "<p>✅ Sample Leads Created.</p>";

    echo "<h3 style='color:green;'>🎉 Data Reset & Seeding Complete! <a href='login.php'>Go to Login</a>. Or test the API with the provided API Key and Project ID '$org_name'.</h3>";
    echo "<p style='color:red;'><b>IMPORTANT:</b> Delete this file (reset_data.php) immediately after running it so unauthorized users do not wipe your database.</p>";

} catch (PDOException $e) {
    echo "<h3 style='color:red;'>❌ Database Error:</h3><p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
