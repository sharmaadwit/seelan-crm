<?php
// dashboard.php
require_once 'db.php';
require_once 'header.php';

// Set execution limits for API calls to prevent 504 errors
set_time_limit(60);
ini_set('memory_limit', '256M');

// Fetch Metrics: Today
$stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE DATE(start_time) = CURDATE() AND org_id = ? AND status = 'Scheduled'");
$stmt->execute([$current_org_id]);
$today_appts = $stmt->fetchColumn();

// Fetch Metrics: Upcoming (Future Days)
$stmt_future = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE DATE(start_time) > CURDATE() AND org_id = ? AND status = 'Scheduled'");
$stmt_future->execute([$current_org_id]);
$upcoming_appts = $stmt_future->fetchColumn();

// Fetch Recent Leads (with LIMIT optimization)
$stmt = $pdo->prepare("
    SELECT l.id, l.name, l.mobile, l.status, l.created_at,
           (SELECT c.campaign_name 
            FROM lead_campaigns lc 
            JOIN campaigns c ON lc.campaign_id = c.id 
            WHERE lc.lead_id = l.id 
            ORDER BY lc.created_at DESC LIMIT 1) as campaign_name
    FROM leads l 
    WHERE l.org_id = ? 
    ORDER BY l.created_at DESC LIMIT 10
");
$stmt->execute([$current_org_id]);
$leads = $stmt->fetchAll();

// Get overall statistics for API metrics
$stmt_total = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE org_id = ?");
$stmt_total->execute([$current_org_id]);
$total_leads = $stmt_total->fetchColumn();

$stmt_campaigns = $pdo->prepare("SELECT COUNT(*) FROM campaigns WHERE org_id = ?");
$stmt_campaigns->execute([$current_org_id]);
$total_campaigns = $stmt_campaigns->fetchColumn();

// Dashboard data removed - export functionality disabled
?>

<style>
.dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
    gap: 15px;
}

.dashboard-header h1 {
    margin: 0;
    font-size: 32px;
    background: linear-gradient(135deg, var(--primary) 0%, #7C3AED 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.export-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #10B981;
    color: white;
    padding: 10px 16px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.3s ease;
}

.export-btn:hover {
    background: #059669;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.metric-card {
    background: linear-gradient(135deg, #FFFFFF 0%, #F9FAFB 100%);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
}

.metric-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--primary), #7C3AED);
}

.metric-card.green::before {
    background: linear-gradient(90deg, #10B981, #34D399);
}

.metric-card.purple::before {
    background: linear-gradient(90deg, #A855F7, #D946EF);
}

.metric-card.blue::before {
    background: linear-gradient(90deg, #3B82F6, #06B6D4);
}

.metric-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
    border-color: var(--primary);
}

.metric-label {
    font-size: 13px;
    color: var(--text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 12px;
}

.metric-value {
    font-size: 48px;
    font-weight: 800;
    color: var(--text-main);
    line-height: 1;
    margin-bottom: 16px;
}

.metric-action {
    display: inline-block;
    font-size: 13px;
    color: var(--primary);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s ease;
}

.metric-action:hover {
    color: #7C3AED;
    transform: translateX(4px);
}

.leads-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.leads-card h2 {
    margin: 0 0 20px 0;
    font-size: 20px;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 10px;
}

.leads-card h2::before {
    content: '';
    display: inline-block;
    width: 4px;
    height: 24px;
    background: linear-gradient(180deg, var(--primary), #7C3AED);
    border-radius: 2px;
}

.leads-table {
    width: 100%;
    border-collapse: collapse;
}

.leads-table thead {
    border-bottom: 2px solid var(--border);
}

.leads-table th {
    padding: 12px;
    text-align: left;
    font-weight: 600;
    font-size: 13px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: #F9FAFB;
}

.leads-table td {
    padding: 14px 12px;
    border-bottom: 1px solid #F3F4F6;
    font-size: 14px;
}

.leads-table tr:hover {
    background: #F9FAFB;
}

.lead-name {
    font-weight: 600;
    color: var(--text-main);
}

.lead-mobile {
    color: var(--text-muted);
    font-family: 'Courier New', monospace;
}

.campaign-badge {
    display: inline-block;
    background: linear-gradient(135deg, #E0E7FF, #F3E8FF);
    color: #6D28D9;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
}

.status-new { background: #DBEAFE; color: #1D4ED8; }
.status-progress { background: #FEF3C7; color: #92400E; }
.status-booked { background: #D1FAE5; color: #047857; }
.status-converted { background: #D1D5DB; color: #374151; }
.status-closed { background: #FEE2E2; color: #B91C1C; }

.view-profile-link {
    color: var(--primary);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s ease;
}

.view-profile-link:hover {
    color: #7C3AED;
    transform: translateX(4px);
    display: inline-block;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-muted);
}

.empty-state p {
    margin: 0;
    font-size: 14px;
}

.view-all-link {
    display: inline-block;
    margin-top: 16px;
    color: var(--primary);
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.2s ease;
}

.view-all-link:hover {
    color: #7C3AED;
    transform: translateX(4px);
}

@media (max-width: 768px) {
    .dashboard-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .metrics-grid {
        grid-template-columns: 1fr;
    }

    .metric-value {
        font-size: 36px;
    }

    .leads-table {
        font-size: 13px;
    }

    .leads-table th,
    .leads-table td {
        padding: 10px 8px;
    }
}
</style>

<div class="dashboard-header">
    <h1>Dashboard</h1>
</div>

<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-label">📅 Today's Appointments</div>
        <div class="metric-value"><?php echo $today_appts; ?></div>
        <a href="calendar.php" class="metric-action">View Calendar →</a>
    </div>

    <div class="metric-card green">
        <div class="metric-label">📈 Upcoming Appointments</div>
        <div class="metric-value"><?php echo $upcoming_appts; ?></div>
        <a href="calendar.php" class="metric-action" style="color: #10B981;">View Calendar →</a>
    </div>

    <div class="metric-card purple">
        <div class="metric-label">👥 Total Leads</div>
        <div class="metric-value"><?php echo $total_leads; ?></div>
        <a href="leads.php" class="metric-action" style="color: #A855F7;">View All →</a>
    </div>

    <div class="metric-card blue">
        <div class="metric-label">🤖 AI Analysis</div>
        <div class="metric-value" style="font-size: 20px; margin-top: 20px;">Insights</div>
        <a href="#" onclick="return openAIAnalysisChat()" class="metric-action" style="color: #3B82F6;">Ask AI →</a>
    </div>
</div>

<div class="leads-card">
    <h2>Recent Leads</h2>
    <?php if (!empty($leads)): ?>
        <table class="leads-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Mobile</th>
                    <th>Campaign</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leads as $l): ?>
                <tr>
                    <td><span class="lead-name"><?php echo htmlspecialchars($l['name']); ?></span></td>
                    <td><span class="lead-mobile"><?php echo htmlspecialchars($l['mobile']); ?></span></td>
                    <td><span class="campaign-badge"><?php echo htmlspecialchars($l['campaign_name'] ?? 'Organic'); ?></span></td>
                    <td>
                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $l['status'])); ?>">
                            <?php echo $l['status']; ?>
                        </span>
                    </td>
                    <td>
                        <a href="profile.php?id=<?php echo $l['id']; ?>" class="view-profile-link">View Profile →</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <a href="leads.php" class="view-all-link">View All Leads →</a>
    <?php else: ?>
        <div class="empty-state">
            <p>No leads yet. <a href="leads.php" style="color: var(--primary);">Create your first lead</a></p>
        </div>
    <?php endif; ?>
</div>

<script>
// Export functionality removed
function openAIAnalysisChat() {
    if (typeof openAIChat === 'function') {
        openAIChat('analytics');
        return false;
    }
    return false;
}
</script>

<?php require_once 'footer.php'; ?>