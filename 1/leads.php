<?php
// leads.php
require_once 'db.php';
require_once 'header.php';

$msg = $_GET['msg'] ?? '';

// Handle Manual Lead Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_manual_lead'])) {
    $name = trim($_POST['name']);
    $mobile = trim($_POST['mobile']);
    $camp_name = 'Manual';

    $stmt = $pdo->prepare("SELECT id FROM campaigns WHERE campaign_name = ? AND org_id = ?");
    $stmt->execute([$camp_name, $current_org_id]);
    $camp = $stmt->fetch();
    if ($camp) { $camp_id = $camp['id']; } 
    else { 
        $pdo->prepare("INSERT INTO campaigns (org_id, campaign_name) VALUES (?, ?)")->execute([$current_org_id, $camp_name]);
        $camp_id = $pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("SELECT id FROM leads WHERE mobile = ? AND org_id = ?");
    $stmt->execute([$mobile, $current_org_id]);
    $lead = $stmt->fetch();
    
    if ($lead) {
        $lead_id = $lead['id'];
        $msg = "Lead already exists. Tagged as Manual.";
    } else {
        $pdo->prepare("INSERT INTO leads (org_id, name, mobile) VALUES (?, ?, ?)")->execute([$current_org_id, $name, $mobile]);
        $lead_id = $pdo->lastInsertId();
        $msg = "Manual lead created successfully.";

        // Optional: fire CURL webhooks for lead_created (source = manual)
        require_once 'helpers.php';
        $leadPayload = [
            'lead_id'        => (int)$lead_id,
            'lead_name'      => $name,
            'mobile'         => $mobile,
            'email'          => null,
            'status'         => 'New',
            'campaign_name'  => $camp_name,
            'source'         => 'manual',
            'created_at'     => date('Y-m-d H:i:s'),
        ];
        sendCurlWebhooks($pdo, (int)$current_org_id, 'lead_created', $leadPayload);
    }

    $pdo->prepare("INSERT IGNORE INTO lead_campaigns (org_id, lead_id, campaign_id) VALUES (?, ?, ?)")->execute([$current_org_id, $lead_id, $camp_id]);
}

// Get Filters from URL
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;

// Build Query
$query = "SELECT l.id, l.name, l.mobile, l.status, l.created_at, 
          (SELECT d_s.name FROM appointments a_s JOIN doctors d_s ON a_s.doctor_id = d_s.id WHERE a_s.lead_id = l.id AND a_s.org_id = :org_id ORDER BY a_s.created_at DESC LIMIT 1) as doctor_name 
          FROM leads l 
          WHERE l.org_id = :org_id";
$params = [':org_id' => $current_org_id];

if ($search) {
    $query .= " AND (l.name LIKE :search OR l.mobile LIKE :search)";
    $params[':search'] = "%$search%";
}
if ($status) {
    $query .= " AND l.status = :status";
    $params[':status'] = $status;
}
$query .= " ORDER BY l.created_at DESC LIMIT $limit";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $leads = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Leads Query Error: " . $e->getMessage());
    $leads = [];
    $msg = "Database Error: Unable to fetch leads.";
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
    <h1 style="margin: 0;">Lead Management</h1>
    <button onclick="document.getElementById('addLeadModal').style.display='block'" style="background: var(--primary); color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px; transition: all 0.2s ease;" onmouseover="this.style.background='#4338CA'; this.style.transform='translateY(-2px)'" onmouseout="this.style.background='var(--primary)'; this.style.transform='translateY(0)'">+ Add Lead</button>
</div>

<?php if ($msg): ?>
    <div style="background: #D1FAE5; color: #047857; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border: 1px solid #34D399;">
        <?php echo htmlspecialchars($msg); ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 20px;">
    <div class="card">
        <h2 style="margin-top: 0; font-size: 18px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">All Leads</h2>
        
        <form method="GET" style="display: flex; gap: 10px; margin-bottom: 20px; background: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid var(--border);">
            <input type="text" name="search" placeholder="Search name or mobile..." value="<?php echo htmlspecialchars($search); ?>" style="flex: 2; padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
            <select name="status" style="flex: 1; padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
                <option value="">All Statuses</option>
                <option value="New" <?php if($status=='New') echo 'selected'; ?>>New</option>
                <option value="In Progress" <?php if($status=='In Progress') echo 'selected'; ?>>In Progress</option>
                <option value="Appointment Booked" <?php if($status=='Appointment Booked') echo 'selected'; ?>>Appt. Booked</option>
                <option value="Converted" <?php if($status=='Converted') echo 'selected'; ?>>Converted</option>
                <option value="Closed" <?php if($status=='Closed') echo 'selected'; ?>>Closed</option>
            </select>
            <select name="limit" style="flex: 1; padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
                <option value="25" <?php if($limit==25) echo 'selected'; ?>>Show 25</option>
                <option value="50" <?php if($limit==50) echo 'selected'; ?>>Show 50</option>
                <option value="100" <?php if($limit==100) echo 'selected'; ?>>Show 100</option>
            </select>
            <button type="submit" style="background: var(--primary); color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;">Filter</button>
            <a href="leads.php" style="padding: 8px 15px; text-decoration: none; color: #EF4444; border: 1px solid #EF4444; border-radius: 4px; display: inline-block; text-align: center;">Reset</a>
        </form>

        <table>
            <tr><th>Name / Mobile</th><th>Doctor</th><th>Status</th><th>Added On</th><th>Action</th></tr>
            <?php foreach ($leads as $lead): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($lead['name']); ?></strong><br><small><?php echo htmlspecialchars($lead['mobile']); ?></small></td>
                <td><span style="font-size: 13px; font-weight: 500;"><?php echo htmlspecialchars($lead['doctor_name'] ?? 'Unassigned'); ?></span></td>
                <td><span style="background: #F3F4F6; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold;"><?php echo $lead['status']; ?></span></td>
                <td><?php echo date('M j, Y', strtotime($lead['created_at'])); ?></td>
                <td><a href="profile.php?id=<?php echo $lead['id']; ?>" style="color: var(--primary); font-weight: bold; text-decoration: none;">View Profile &rarr;</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($leads)): ?><tr><td colspan="4" style="text-align:center; color: var(--text-muted);">No leads found.</td></tr><?php endif; ?>
        </table>
    </div>
</div>

<!-- Add Lead Modal -->
<div id="addLeadModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
    <div style="background-color: #fefefe; margin: 10% auto; padding: 20px; border-radius: 8px; width: 90%; max-width: 400px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">
            <h2 style="margin: 0; font-size: 18px;">➕ Add Manual Lead</h2>
            <span onclick="document.getElementById('addLeadModal').style.display='none'" style="cursor: pointer; font-size: 24px; font-weight: bold; color: var(--text-muted);">&times;</span>
        </div>
        <form method="POST">
            <label style="font-size: 13px; font-weight: bold; color: var(--text-muted); display: block; margin-bottom: 5px;">Full Name</label>
            <input type="text" name="name" required style="width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid var(--border); border-radius: 4px; box-sizing: border-box;">
            
            <label style="font-size: 13px; font-weight: bold; color: var(--text-muted); display: block; margin-bottom: 5px;">Mobile Number</label>
            <input type="text" name="mobile" required style="width: 100%; padding: 10px; margin-bottom: 20px; border: 1px solid var(--border); border-radius: 4px; box-sizing: border-box;">
            
            <button type="submit" name="add_manual_lead" style="background: var(--primary); color: white; border: none; padding: 12px; border-radius: 6px; width: 100%; cursor: pointer; font-weight: bold; font-size: 14px;">Create Lead</button>
            <p style="font-size: 11px; color: var(--text-muted); text-align: center; margin-top: 15px; margin-bottom: 0;">Lead source will automatically be set to "Manual".</p>
        </form>
    </div>
</div>

<script>
window.onclick = function(event) {
    var modal = document.getElementById('addLeadModal');
    if (event.target == modal) {
        modal.style.display = "none";
    }
}
</script>

<?php require_once 'footer.php'; ?>