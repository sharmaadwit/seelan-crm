<?php
// analytics.php - Analytics with Charts
require_once 'db.php';
require_once 'header.php';

$stmt = $pdo->prepare("
    SELECT c.campaign_name, 
           COUNT(DISTINCT lc.lead_id) as leads, 
           COUNT(DISTINCT a.id) as appts,
           COUNT(DISTINCT CASE WHEN a.status = 'Completed' THEN a.id END) as completed
    FROM campaigns c 
    LEFT JOIN lead_campaigns lc ON c.id = lc.campaign_id 
    LEFT JOIN appointments a ON lc.lead_id = a.lead_id AND a.org_id = c.org_id 
    WHERE c.org_id = ? 
    GROUP BY c.id ORDER BY leads DESC
");
$stmt->execute([$current_org_id]);
$analytics = $stmt->fetchAll();

// Calculate conversion rate
$total_leads = array_sum(array_column($analytics, 'leads'));
$total_appts = array_sum(array_column($analytics, 'appts'));
$conversion_rate = $total_leads > 0 ? round(($total_appts / $total_leads) * 100, 2) : 0;

// Chart data
$chart_campaigns = json_encode(array_column($analytics, 'campaign_name'));
$chart_leads = json_encode(array_map('intval', array_column($analytics, 'leads')));
$chart_appts = json_encode(array_map('intval', array_column($analytics, 'appts')));
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>

<style>
.analytics-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
    gap: 15px;
}

.analytics-header h1 {
    margin: 0;
    font-size: 32px;
    background: linear-gradient(135deg, var(--primary) 0%, #7C3AED 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.ai-button {
    background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%);
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    transition: all 0.2s ease;
}

.ai-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

.analytics-metrics {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.metric-box {
    background: linear-gradient(135deg, #FFFFFF 0%, #F9FAFB 100%);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s ease;
}

.metric-box:hover {
    border-color: var(--primary);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.1);
    transform: translateY(-4px);
}

.metric-label {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.metric-value {
    font-size: 32px;
    font-weight: 800;
    color: var(--primary);
    line-height: 1;
}

.charts-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.chart-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.chart-card h3 {
    margin-top: 0;
    font-size: 16px;
    color: var(--text-main);
    border-bottom: 2px solid var(--border);
    padding-bottom: 12px;
    margin-bottom: 16px;
}

.chart-wrapper {
    position: relative;
    height: 300px;
}

.table-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.table-card h2 {
    margin-top: 0;
    font-size: 18px;
    border-bottom: 1px solid var(--border);
    padding-bottom: 15px;
}

@media (max-width: 768px) {
    .analytics-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .charts-container {
        grid-template-columns: 1fr;
    }

    .chart-wrapper {
        height: 250px;
    }
}
</style>

<div class="analytics-header">
    <h1>📊 Campaign Analytics</h1>
    <button class="ai-button" onclick="openAIChat && openAIChat('analytics')">
        🤖 AI Analysis
    </button>
    <script>
        // Ensure analytics page can call the global AI chat widget if available
        if (typeof openAIChat !== 'function') {
            window.openAIChat = window.openAIChat || function() {};
        }
    </script>
</div>

<div class="analytics-metrics">
    <div class="metric-box">
        <div class="metric-label">📈 Total Leads</div>
        <div class="metric-value"><?php echo $total_leads; ?></div>
    </div>
    <div class="metric-box">
        <div class="metric-label">📅 Booked Appointments</div>
        <div class="metric-value"><?php echo $total_appts; ?></div>
    </div>
    <div class="metric-box">
        <div class="metric-label">📊 Conversion Rate</div>
        <div class="metric-value"><?php echo $conversion_rate; ?>%</div>
    </div>
    <div class="metric-box">
        <div class="metric-label">🎯 Campaigns</div>
        <div class="metric-value"><?php echo count($analytics); ?></div>
    </div>
</div>

<?php if (!empty($analytics)): ?>
<div class="charts-container">
    <div class="chart-card">
        <h3>📊 Leads per Campaign</h3>
        <div class="chart-wrapper">
            <canvas id="leadsChart"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <h3>✅ Appointments per Campaign</h3>
        <div class="chart-wrapper">
            <canvas id="appointmentsChart"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <h3>📈 Leads vs Appointments</h3>
        <div class="chart-wrapper">
            <canvas id="comparisonChart"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <h3>🎯 Conversion Rate per Campaign</h3>
        <div class="chart-wrapper">
            <canvas id="conversionChart"></canvas>
        </div>
    </div>
</div>

<script>
const campaigns = <?php echo $chart_campaigns; ?>;
const leads = <?php echo $chart_leads; ?>;
const appts = <?php echo $chart_appts; ?>;

// Leads Chart
const leadsCtx = document.getElementById('leadsChart').getContext('2d');
new Chart(leadsCtx, {
    type: 'bar',
    data: {
        labels: campaigns,
        datasets: [{
            label: 'Leads Generated',
            data: leads,
            backgroundColor: 'rgba(79, 70, 229, 0.8)',
            borderColor: 'rgba(79, 70, 229, 1)',
            borderWidth: 2,
            borderRadius: 6,
            tension: 0.3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});

// Appointments Chart
const apptCtx = document.getElementById('appointmentsChart').getContext('2d');
new Chart(apptCtx, {
    type: 'bar',
    data: {
        labels: campaigns,
        datasets: [{
            label: 'Appointments Booked',
            data: appts,
            backgroundColor: 'rgba(16, 185, 129, 0.8)',
            borderColor: 'rgba(16, 185, 129, 1)',
            borderWidth: 2,
            borderRadius: 6,
            tension: 0.3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});

// Comparison Chart
const compCtx = document.getElementById('comparisonChart').getContext('2d');
new Chart(compCtx, {
    type: 'line',
    data: {
        labels: campaigns,
        datasets: [
            {
                label: 'Leads',
                data: leads,
                borderColor: 'rgba(79, 70, 229, 1)',
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.3,
                pointRadius: 5
            },
            {
                label: 'Appointments',
                data: appts,
                borderColor: 'rgba(16, 185, 129, 1)',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.3,
                pointRadius: 5
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { 
            legend: { 
                display: true,
                position: 'top'
            } 
        },
        scales: { 
            y: { 
                beginAtZero: true,
                ticks: { stepSize: 1 }
            } 
        }
    }
});

// Conversion Rate Chart
const conversionData = leads.map((l, i) => l > 0 ? Math.round((appts[i] / l) * 100) : 0);
const convCtx = document.getElementById('conversionChart').getContext('2d');
new Chart(convCtx, {
    type: 'doughnut',
    data: {
        labels: campaigns,
        datasets: [{
            data: conversionData,
            backgroundColor: [
                'rgba(79, 70, 229, 0.8)',
                'rgba(16, 185, 129, 0.8)',
                'rgba(168, 85, 247, 0.8)',
                'rgba(59, 130, 246, 0.8)',
                'rgba(139, 92, 246, 0.8)'
            ],
            borderColor: [
                'rgba(79, 70, 229, 1)',
                'rgba(16, 185, 129, 1)',
                'rgba(168, 85, 247, 1)',
                'rgba(59, 130, 246, 1)',
                'rgba(139, 92, 246, 1)'
            ],
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});
</script>
<?php endif; ?>

<div class="table-card">
    <h2 style="margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 15px;">📋 Campaign Detailed View</h2>
    <table>
        <thead>
            <tr>
                <th>Campaign Name</th>
                <th>Total Leads Generated</th>
                <th>Booked Appointments</th>
                <th>Conversion Rate</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($analytics as $row): 
                $conv_rate = $row['leads'] > 0 ? round(($row['appts'] / $row['leads']) * 100, 2) : 0;
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($row['campaign_name']); ?></strong></td>
                <td><?php echo $row['leads']; ?></td>
                <td><span style="background: #D1FAE5; color: #047857; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 13px;"><?php echo $row['appts']; ?> Booked</span></td>
                <td><span style="background: #DBEAFE; color: #1D4ED8; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 13px;"><?php echo $conv_rate; ?>%</span></td>
                <td><a href="campaign_details.php?name=<?php echo urlencode($row['campaign_name']); ?>" style="color: var(--primary); font-weight: bold; text-decoration: none;">Details →</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($analytics)): ?><tr><td colspan="5" style="text-align: center; color: var(--text-muted);">No campaign data yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once 'footer.php'; ?>