<?php
require_once 'db.php';

try {
    $pdo->exec("ALTER TABLE organizations ADD COLUMN gupshup_app_id VARCHAR(100) DEFAULT NULL");
    echo "Added gupshup_app_id column.\n";
} catch (PDOException $e) {
    if ($e->getCode() == '42S21') {
        echo "Column gupshup_app_id already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

try {
    $pdo->exec("ALTER TABLE organizations ADD COLUMN gupshup_api_key VARCHAR(255) DEFAULT NULL");
    echo "Added gupshup_api_key column.\n";
} catch (PDOException $e) {
    if ($e->getCode() == '42S21') {
        echo "Column gupshup_api_key already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>
