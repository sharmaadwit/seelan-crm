<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['org_id'])) { header("Location: login.php"); exit; }
$current_org_id = $_SESSION['org_id'];
$current_org_name = $_SESSION['org_name'];
$page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRsM - <?php echo htmlspecialchars($current_org_name); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #4F46E5;
            --primary-dark: #4338CA;
            --bg: #F9FAFB;
            --surface: #FFFFFF;
            --text-main: #111827;
            --text-muted: #6B7280;
            --border: #E5E7EB;
            --success: #10B981;
            --danger: #EF4444;
            --warning: #F59E0B;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen',
                'Ubuntu', 'Cantarell', 'Fira Sans', 'Droid Sans', 'Helvetica Neue',
                sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            background: var(--bg);
            color: var(--text-main);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* NAVIGATION */
        .nav {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .nav-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 64px;
            padding: 0 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .nav-logo {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
        }

        .nav-logo img {
            height: 28px;
            width: auto;
            display: block;
        }

        .nav-links {
            display: flex;
            gap: 8px;
            align-items: center;
            flex: 1;
            margin: 0 40px;
        }

        .nav-links a {
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            padding: 8px 16px;
            border-radius: 6px;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .nav-links a:hover {
            color: var(--primary);
            background: rgba(79, 70, 229, 0.1);
        }

        .nav-links a.active {
            color: var(--primary);
            background: rgba(79, 70, 229, 0.15);
            font-weight: 600;
        }

        .nav-user {
            display: flex;
            align-items: center;
            gap: 20px;
            border-left: 1px solid var(--border);
            padding-left: 20px;
        }

        .nav-user-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-main);
        }

        .nav-logout {
            color: var(--danger);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            padding: 8px 16px;
            border-radius: 6px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-logout:hover {
            background: rgba(239, 68, 68, 0.1);
            color: #B91C1C;
        }

        /* PAGE CONTENT */
        .page-wrapper {
            padding: 32px 20px;
        }

        /* CARDS */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .card:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.1);
        }

        /* TABLES */
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        thead {
            background: var(--bg);
            border-bottom: 2px solid var(--border);
        }

        th {
            padding: 12px;
            font-weight: 600;
            font-size: 13px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }

        tbody tr:hover {
            background: var(--bg);
        }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            .nav-content {
                flex-wrap: wrap;
                height: auto;
                padding: 12px 20px;
            }

            .nav-links {
                width: 100%;
                margin: 12px 0 0 0;
                gap: 4px;
            }

            .nav-links a {
                flex: 1;
                text-align: center;
                padding: 8px 12px;
                font-size: 12px;
            }

            .nav-user {
                width: 100%;
                border-left: none;
                border-top: 1px solid var(--border);
                padding-left: 0;
                padding-top: 12px;
                margin-top: 12px;
                justify-content: space-between;
            }

            table {
                font-size: 13px;
            }

            th, td {
                padding: 10px 8px;
            }

            .page-wrapper {
                padding: 20px 16px;
            }
        }
    </style>
</head>
<body>
<nav class="nav">
    <div class="nav-content">
        <a class="nav-logo" href="dashboard.php" aria-label="Gupshup">
            <img src="assets/gupshup-logo.png" alt="Gupshup" />
        </a>
        <div class="nav-links">
            <a href="dashboard.php" class="<?php echo $page=='dashboard.php'?'active':''; ?>">Dashboard</a>
            <a href="leads.php" class="<?php echo $page=='leads.php'?'active':''; ?>">Leads</a>
            <a href="calendar.php" class="<?php echo $page=='calendar.php'?'active':''; ?>">Calendar</a>
            <a href="analytics.php" class="<?php echo $page=='analytics.php'?'active':''; ?>">Analytics</a>
            <a href="settings.php" class="<?php echo $page=='settings.php'?'active':''; ?>">Settings</a>
        </div>
        <div class="nav-user">
            <span class="nav-user-name"><?php echo htmlspecialchars(substr($current_org_name, 0, 20)); ?></span>
            <a href="logout.php" class="nav-logout">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
                Logout
            </a>
        </div>
    </div>
</nav>

<div class="page-wrapper">
    <div class="container">