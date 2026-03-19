<?php
// campaign_details.php
require_once 'db.php';
require_once 'header.php';

$campaign_name = $_GET['name'] ?? '';
if (empty($campaign_name)) die("No campaign specified.");

$filter = $_GET['filter'] ?? 'all';

// 1. Fetch Campaign ID & Stats
$stmt_stats = $pdo->prepare("
    SELECT c.id,
           COUNT(DISTINCT lc.lead_id) as total_leads,
           SUM(CASE WHEN (SELECT COUNT(*) FROM appointments a WHERE a.lead_id = lc.lead_id AND a.org_id = ?) > 0 THEN 1 ELSE 0 END) as booked_leads
    FROM campaigns c
    JOIN lead_campaigns lc ON c.id = lc.campaign_id
    WHERE c.campaign_name = ? AND c.org_id = ?
    GROUP BY c.id
");
$stmt_stats->execute([$current_org_id, $campaign_name, $current_org_id]);
$stats = $stmt_stats->fetch();

$total = $stats['total_leads'] ?? 0;
$booked = $stats['booked_leads'] ?? 0;
$unbooked = $total - $booked;

// 2. Fetch Filtered Leads List
$query = "
    SELECT l.id, l.name, l.mobile, l.status, lc.created_at,
    (SELECT COUNT(*) FROM appointments a WHERE a.lead_id = l.id AND a.org_id = :org_id) as has_appointment
    FROM lead_campaigns lc 
    JOIN leads l ON lc.lead_id = l.id 
    JOIN campaigns c ON lc.campaign_id = c.id 
    WHERE c.campaign_name = :camp_name AND l.org_id = :org_id
";

if ($filter === 'booked') {
    $query .= " HAVING has_appointment > 0";
} elseif ($filter === 'unbooked') {
    $query .= " HAVING has_appointment = 0";
}

$query .= " ORDER BY lc.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute([':org_id' => $current_org_id, ':camp_name' => $campaign_name]);
$leads = $stmt->fetchAll();
?>

<div style="margin-bottom: 20px;">
    <a href="analytics.php" style="color: var(--primary); text-decoration: none; font-weight: bold;">&larr; Back to Analytics</a>
</div>

<h1 style="margin-top: 0; margin-bottom: 25px;">Campaign: <span style="color: var(--primary);"><?php echo htmlspecialchars($campaign_name); ?></span></h1>

<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 20px;">
    <div class="card" style="text-align: center; border-left: 4px solid var(--text-muted);">
        <h3 style="margin-top: 0; font-size: 14px; color: var(--text-muted);">Total Leads</h3>
        <p style="font-size: 36px; font-weight: bold; margin: 0;"><?php echo $total; ?></p>
    </div>
    <div class="card" style="text-align: center; border-left: 4px solid #10B981;">
        <h3 style="margin-top: 0; font-size: 14px; color: var(--text-muted);">Booked Appointments</h3>
        <p style="font-size: 36px; font-weight: bold; margin: 0; color: #10B981;"><?php echo $booked; ?></p>
    </div>
    <div class="card" style="text-align: center; border-left: 4px solid #EF4444;">
        <h3 style="margin-top: 0; font-size: 14px; color: var(--text-muted);">Did Not Book</h3>
        <p style="font-size: 36px; font-weight: bold; margin: 0; color: #EF4444;"><?php echo $unbooked; ?></p>
    </div>
</div>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 15px;">
        <h2 style="margin: 0;">Lead Breakdown</h2>
        
        <form method="GET" style="display: flex; gap: 10px;">
            <input type="hidden" name="name" value="<?php echo htmlspecialchars($campaign_name); ?>">
            <select name="filter" onchange="this.form.submit()" style="padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
                <option value="all" <?php if($filter=='all') echo 'selected'; ?>>Show All Leads</option>
                <option value="booked" <?php if($filter=='booked') echo 'selected'; ?>>Show Booked Only</option>
                <option value="unbooked" <?php if($filter=='unbooked') echo 'selected'; ?>>Show Unbooked Only</option>
            </select>
        </form>
    </div>
    
    <table>
        <thead><tr><th>Name</th><th>Mobile</th><th>Status</th><th>Booking Status</th><th>Action</th></tr></thead>
        <tbody>
            <?php foreach ($leads as $lead): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($lead['name']); ?></strong></td>
                <td><?php echo htmlspecialchars($lead['mobile']); ?></td>
                <td><span style="background: #F3F4F6; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold;"><?php echo $lead['status']; ?></span></td>
                <td>
                    <?php if ($lead['has_appointment'] > 0): ?>
                        <span style="color: #10B981; font-weight: bold; font-size: 13px;">&#10003; Booked</span>
                    <?php else: ?>
                        <span style="color: #EF4444; font-weight: bold; font-size: 13px;">&#10007; No Booking</span>
                    <?php endif; ?>
                </td>
                <td><a href="profile.php?id=<?php echo $lead['id']; ?>" style="color: var(--primary); font-weight: bold; text-decoration: none;">Profile &rarr;</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($leads)): ?><tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No leads match this filter.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once 'footer.php'; ?>