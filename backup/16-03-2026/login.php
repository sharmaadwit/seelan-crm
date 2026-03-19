<?php
session_start();
require_once 'db.php';
if (isset($_SESSION['org_id'])) { header("Location: dashboard.php"); exit; }
$msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $stmt = $pdo->prepare("SELECT id, org_name, password FROM organizations WHERE email = ?");
    $stmt->execute([$email]);
    $org = $stmt->fetch();

    if ($org && password_verify($_POST['password'], $org['password'])) {
        $_SESSION['org_id'] = $org['id'];
        $_SESSION['org_name'] = $org['org_name'];
        header("Location: dashboard.php"); exit;
    } else {
        $msg = "<p style='color:red;'>Invalid credentials.</p>";
    }
}
?>
<div style="max-width:400px; margin:50px auto; font-family:sans-serif; padding:20px; border:1px solid #ccc; border-radius:8px;">
    <h2>Login</h2>
    <?php echo $msg; ?>
    <form method="POST">
        <input type="email" name="email" placeholder="Email" required style="width:100%; padding:10px; margin-bottom:10px; box-sizing:border-box;">
        <input type="password" name="password" placeholder="Password" required style="width:100%; padding:10px; margin-bottom:10px; box-sizing:border-box;">
        <button type="submit" style="width:100%; padding:10px; background:#4F46E5; color:white; border:none; cursor:pointer;">Login</button>
    </form>
    <p><a href="register.php">Need an account? Register</a></p>
</div>