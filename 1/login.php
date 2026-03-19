<?php
// Fix for Iframe login and SameSite cookies
if (PHP_SESSION_NONE === session_status()) {
    $current_cookie_params = session_get_cookie_params();
    $cookie_options = [
        'lifetime' => $current_cookie_params['lifetime'],
        'path' => $current_cookie_params['path'],
        'domain' => $current_cookie_params['domain'],
        'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
        'httponly' => true,
        'samesite' => 'None'
    ];
    session_set_cookie_params($cookie_options);
}
session_start();
header('X-Frame-Options: ALLOWALL'); // Older browsers
header("Content-Security-Policy: frame-ancestors *;"); // Modern browsers
header("Access-Control-Allow-Origin: *");
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
        $_SESSION['user_type'] = 'admin';
        header("Location: dashboard.php"); exit;
    } else {
        $stmt_user = $pdo->prepare("
            SELECT u.id, u.org_id, u.name, u.password, o.org_name 
            FROM users u 
            JOIN organizations o ON u.org_id = o.id 
            WHERE u.email = ?
        ");
        $stmt_user->execute([$email]);
        $user = $stmt_user->fetch();

        if ($user && password_verify($_POST['password'], $user['password'])) {
            $_SESSION['org_id'] = $user['org_id'];
            $_SESSION['org_name'] = $user['org_name'];
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_type'] = 'user';
            header("Location: dashboard.php"); exit;
        } else {
            $msg = "<p style='color:red;'>Invalid credentials.</p>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Login</title>
    <style>
        :root {
            --primary: #4F46E5;
            --primary-hover: #4338CA;
            --bg: #F3F4F6;
            --card: #FFFFFF;
            --text-main: #111827;
            --text-muted: #6B7280;
            --border: #E5E7EB;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #EEF2FF 0%, #F3E8FF 100%);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-wrapper {
            width: 100%;
            max-width: 420px;
            padding: 20px;
        }

        .login-card {
            background: var(--card);
            border-radius: 16px;
            padding: 40px 30px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h2 {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary) 0%, #8B5CF6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }

        .login-header p {
            font-size: 14px;
            color: var(--text-muted);
        }

        .alert {
            background: #FEE2E2;
            color: #B91C1C;
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
            border: 1px solid #FCA5A5;
            text-align: center;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 15px;
            color: var(--text-main);
            transition: all 0.2s ease;
            outline: none;
        }

        .form-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .btn-submit {
            width: 100%;
            background: var(--primary);
            color: white;
            border: none;
            padding: 14px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-submit:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
        }

        .login-footer {
            text-align: center;
            margin-top: 25px;
            font-size: 14px;
            color: var(--text-muted);
        }

        .login-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-header">
            <img src="assets/gupshup-logo.png" alt="Gupshup Logo" style="max-height: 48px; margin-bottom: 15px; display: block; margin-left: auto; margin-right: auto;">
            <p>Login as an Organization Admin or Team Member.</p>
        </div>
        
        <?php if($msg): ?>
            <div class="alert"><?php echo strip_tags($msg); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="you@company.com" required>
            </div>
            
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            
            <button type="submit" class="btn-submit">Sign In</button>
        </form>

        <div class="login-footer">
            Need an account? <a href="register.php">Register Here</a>
        </div>
    </div>
</div>

</body>
</html>