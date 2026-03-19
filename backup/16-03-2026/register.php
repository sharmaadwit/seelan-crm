<?php
session_start();
require_once 'db.php';
if (isset($_SESSION['org_id'])) { header("Location: dashboard.php"); exit; }
$msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $org_name = trim($_POST['org_name']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("SELECT id FROM organizations WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        $msg = "<p style='color:red;'>Email already exists.</p>";
    } else {
        $api_key = "sk_live_" . bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("INSERT INTO organizations (org_name, email, password, api_key) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$org_name, $email, $password, $api_key])) {
            $msg = "<p style='color:green;'>Registered! <a href='login.php'>Login here</a>.</p>";
        }
    }
}
?>
<div style="max-width:400px; margin:50px auto; font-family:sans-serif; padding:20px; border:1px solid #ccc; border-radius:8px;">
    <h2>Register Organization</h2>
    <?php echo $msg; ?>
    <form method="POST">
        <input type="text" name="org_name" placeholder="Clinic Name" required style="width:100%; padding:10px; margin-bottom:10px; box-sizing:border-box;">
        <input type="email" name="email" placeholder="Email" required style="width:100%; padding:10px; margin-bottom:10px; box-sizing:border-box;">
        <input type="password" name="password" placeholder="Password" required style="width:100%; padding:10px; margin-bottom:10px; box-sizing:border-box;">
        <button type="submit" style="width:100%; padding:10px; background:#4F46E5; color:white; border:none; cursor:pointer;">Register</button>
    </form>
    <p><a href="login.php">Already have an account? Login</a></p>
</div>