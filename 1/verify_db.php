<?php
// verify_db.php - Standalone script to verify the agent_timeslots table
require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$current_org_id = $_SESSION['org_id'] ?? null;

if (!$current_org_id) {
    die("Please log in to the CRM first to verify your organization's schedule.");
}

try {
    $stmt = $pdo->prepare("SELECT * FROM agent_timeslots WHERE org_id = ? ORDER BY day_of_week, start_time");
    $stmt->execute([$current_org_id]);
    $slots = $stmt->fetchAll();
} catch (Exception $e) {
    die("Error fetching slots: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Verification - Agent Timeslots</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f4f7f6; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #4f46e5; color: white; }
        tr:hover { background: #f9fafb; }
        .header { margin-bottom: 20px; }
        .badge { background: #e0e7ff; color: #4338ca; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Database Verification</h1>
        <p>Showing all schedule entries for Organization ID: <strong><?php echo htmlspecialchars($current_org_id); ?></strong></p>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Day of Week</th>
                <th>Start Time</th>
                <th>End Time</th>
                <th>Capacity</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($slots)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; color: #666;">No slots found in database for this organization.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($slots as $s): ?>
                    <tr>
                        <td><?php echo $s['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($s['day_of_week']); ?></strong></td>
                        <td><?php echo htmlspecialchars($s['start_time']); ?></td>
                        <td><?php echo htmlspecialchars($s['end_time']); ?></td>
                        <td><span class="badge">Capacity: <?php echo $s['capacity']; ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <p style="margin-top: 20px; color: #ef4444; font-size: 12px;"><strong>Security Note:</strong> Please delete this file from your server after verification.</p>
</body>
</html>
