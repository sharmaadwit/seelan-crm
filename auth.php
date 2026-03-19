<?php
// auth.php
session_start();
if (!isset($_SESSION['org_id'])) {
    header("Location: login.php");
    exit;
}
$current_org_id = $_SESSION['org_id'];
?>