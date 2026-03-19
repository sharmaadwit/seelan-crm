<?php
// You can dynamically set iframe URL here
$iframe_url = "https://andromeda.gupshup.io/dashboards/6940dd1a1b7a86723135a8b3"; // Change this to your target site
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Iframe Integration</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
        }
        .header {
            background: #333;
            color: #fff;
            padding: 10px;
            text-align: center;
        }
        .iframe-container {
            width: 100%;
            height: calc(100vh - 50px);
        }
        iframe {
            width: 100%;
            height: 100%;
            border: none;
        }
    </style>
</head>
<body>

<div class="header">
    <h2>Embedded Dashboard</h2>
</div>

<div class="iframe-container">
    <iframe src="<?php echo $iframe_url; ?>"></iframe>
</div>

</body>
</html>