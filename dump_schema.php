<?php
require 'db.php';
$tables = ['users', 'organizations', 'leads', 'campaigns'];
foreach($tables as $t) {
    try {
        $stmt = $pdo->query("SHOW CREATE TABLE $t");
        $res = $stmt->fetch();
        if ($res) {
            echo $res['Create Table'] . "\n\n";
        }
    } catch(Exception $e) { echo "Table $t error: " . $e->getMessage() . "\n"; }
}
