<?php
// bot_journey.php - AI Bot Journey Generator with AI API Integration
set_time_limit(60);
ini_set('memory_limit', '256M');

require_once 'db.php';
require_once 'header.php';

$ai_api_key = 'sk_8_G2bOuXdXUcfC-4MVB-HnEYqN3NFslx-5PB7pzigV1c';
$ai_api_url = 'https://webhook.site/808f70d9-a883-4c70-943c-306cb1e62b67';

// Fetch recent leads
$stmt = $pdo->prepare("
    SELECT l.id, l.name, l.mobile, l.status, l.created_at,
           (SELECT c.campaign_name 
            FROM lead_campaigns lc 
            JOIN campaigns c ON lc.campaign_id = c.id 
            WHERE lc.lead_id = l.id 
            ORDER BY lc.created_at DESC LIMIT 1) as campaign_name
    FROM leads l 
    WHERE l.org_id = ? 
    ORDER BY l.created_at DESC LIMIT 20
");
$stmt->execute([$current_org_id]);
$leads = $stmt->fetchAll();

$journey_result = '';
$ai_response = '';
$selected_lead = null;
$sync_message = '';

// Handle journey generation via AI API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_ai_journey'])) {
    $lead_id = (int)$_POST['lead_id'];
    
    $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ? AND org_id = ?");
    $stmt->execute([$lead_id, $current_org_id]);
    $selected_lead = $stmt->fetch();
    
    if ($selected_lead) {
        // Fetch interactions
        $stmt_msgs = $pdo->prepare("SELECT * FROM message_logs WHERE lead_id = ? AND org_id = ? ORDER BY sent_at DESC LIMIT 5");
        $stmt_msgs->execute([$lead_id, $current_org_id]);
        $messages = $stmt_msgs->fetchAll();
        
        $stmt_appts = $pdo->prepare("SELECT * FROM appointments WHERE lead_id = ? AND org_id = ? ORDER BY start_time DESC LIMIT 3");
        $stmt_appts->execute([$lead_id, $current_org_id]);
        $appointments = $stmt_appts->fetchAll();
        
        // Build AI prompt with patient context
        $prompt = "Analyze this patient journey and return ONLY a valid JSON object:\n\n";
        $prompt .= "Patient: " . $selected_lead['name'] . "\n";
        $prompt .= "Mobile: " . $selected_lead['mobile'] . "\n";
        $prompt .= "Status: " . $selected_lead['status'] . "\n";
        $prompt .= "Days Active: " . ceil((time() - strtotime($selected_lead['created_at'])) / 86400) . "\n";
        $prompt .= "Messages Sent: " . count($messages) . "\n";
        $prompt .= "Appointments: " . count($appointments) . "\n\n";
        $prompt .= "Return JSON with: {\"lead_info\": {}, \"journey_stages\": [], \"engagement_metrics\": {}, \"recommendations\": [], \"next_actions\": \"\"}";
        
        // Call AI API and get response
        $ai_response = callAIAPI($ai_api_url, $ai_api_key, $prompt, $selected_lead['name']);
        
        if ($ai_response) {
            $journey_result = $ai_response;
        } else {
            $journey_result = json_encode(['error' => 'Failed to connect to AI API']);
        }
    }
}

// Handle Journey Sync API call
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_journey'])) {
    $journey_json = $_POST['journey_json'] ?? '{}';
    $sync_endpoint = $_POST['sync_endpoint'] ?? '';
    
    if ($sync_endpoint) {
        $sync_result = syncJourneyToAPI($sync_endpoint, $journey_json, $current_org_id);
        if ($sync_result) {
            $sync_message = "✅ Journey synced successfully to: " . $sync_endpoint;
        } else {
            $sync_message = "❌ Failed to sync journey. Check endpoint and try again.";
        }
    } else {
        $sync_message = "❌ Please provide a Journey Sync API endpoint";
    }
}

function callAIAPI($api_url, $api_key, $message, $session_id) {
    $payload = json_encode([
        'message' => $message,
        'session_id' => $session_id . '_' . time(),
        'email_id' => 'crm@medical.com'
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-Key: ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        try {
            // Extract JSON from streaming response
            $lines = explode("\n", $response);
            $json_response = '';
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, 'data:') === 0) {
                    $json_response .= substr($line, 5);
                } elseif (!empty($line) && $line[0] === '{') {
                    $json_response .= $line;
                }
            }
            
            // Parse JSON
            if (!empty($json_response)) {
                $parsed = json_decode($json_response, true);
                if ($parsed) {
                    return json_encode($parsed);
                }
            }
            return $json_response;
        } catch (Exception $e) {
            error_log("AI API parse error: " . $e->getMessage());
            return null;
        }
    }
    
    if ($curl_error) {
        error_log("Curl error: " . $curl_error);
    }
    return null;
}

function syncJourneyToAPI($sync_endpoint, $journey_json, $org_id) {
    try {
        $payload = [
            'org_id' => $org_id,
            'journey_data' => json_decode($journey_json, true),
            'timestamp' => date('Y-m-d H:i:s'),
            'synced_from' => 'crm'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $sync_endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLOPT_HTTP_CODE);
        curl_close($ch);
        
        return ($http_code >= 200 && $http_code < 300);
    } catch (Exception $e) {
        error_log("Sync API error: " . $e->getMessage());
        return false;
    }
}
?>

<style>
.bot-journey-container {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 20px;
    margin-bottom: 30px;
}

.journey-sidebar {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    height: fit-content;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.journey-sidebar h2 {
    margin-top: 0;
    font-size: 18px;
    border-bottom: 2px solid var(--border);
    padding-bottom: 12px;
    margin-bottom: 16px;
}

.journey-sidebar label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.journey-sidebar select {
    width: 100%;
    padding: 10px;
    margin-bottom: 20px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
}

.journey-sidebar select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.generate-btn {
    width: 100%;
    background: linear-gradient(135deg, var(--primary) 0%, #7C3AED 100%);
    color: white;
    padding: 12px;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.generate-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
}

.journey-content {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.json-response-container {
    margin-top: 20px;
}

.json-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--border);
}

.json-header h3 {
    margin: 0;
    font-size: 16px;
    color: var(--text-main);
}

.json-viewer {
    background: #1E293B;
    color: #E2E8F0;
    padding: 20px;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    max-height: 600px;
    overflow: auto;
    margin-bottom: 20px;
    border: 1px solid #334155;
    white-space: pre-wrap;
    word-break: break-all;
}

.json-viewer::-webkit-scrollbar {
    width: 8px;
}

.json-viewer::-webkit-scrollbar-track {
    background: #0F172A;
}

.json-viewer::-webkit-scrollbar-thumb {
    background: #475569;
    border-radius: 4px;
}

.action-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 14px;
}

.btn-copy {
    background: var(--primary);
    color: white;
}

.btn-copy:hover {
    background: #4338CA;
    transform: translateY(-2px);
}

.btn-sync {
    background: #10B981;
    color: white;
    flex: 1;
}

.btn-sync:hover {
    background: #059669;
    transform: translateY(-2px);
}

.sync-form {
    background: #F0FDF4;
    border: 1px solid #86EFAC;
    border-radius: 8px;
    padding: 16px;
    margin-top: 20px;
}

.sync-form label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    font-size: 13px;
}

.sync-form input {
    width: 100%;
    padding: 10px;
    border: 1px solid #86EFAC;
    border-radius: 6px;
    margin-bottom: 12px;
    font-family: inherit;
}

.sync-form button {
    width: 100%;
    padding: 10px;
    background: #10B981;
    color: white;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
}

.sync-form button:hover {
    background: #059669;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}

.empty-state-icon {
    font-size: 64px;
    margin-bottom: 16px;
}

.status-message {
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-weight: 600;
}

.status-success {
    background: #D1FAE5;
    color: #047857;
    border: 1px solid #6EE7B7;
}

.status-error {
    background: #FEE2E2;
    color: #B91C1C;
    border: 1px solid #FCA5A5;
}

@media (max-width: 968px) {
    .bot-journey-container {
        grid-template-columns: 1fr;
    }
    
    .journey-sidebar {
        height: auto;
    }
}
</style>

<div style="margin-bottom: 30px;">
    <h1 style="margin: 0 0 8px 0; font-size: 32px; background: linear-gradient(135deg, var(--primary) 0%, #7C3AED 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">🤖 AI Bot Journey Generator</h1>
    <p style="margin: 0; color: var(--text-muted); font-size: 14px;">Generate patient journeys using AI and sync to your account service</p>
</div>

<div class="bot-journey-container">
    <div class="journey-sidebar">
        <h2>Select Patient</h2>
        <form method="POST">
            <label>Choose Patient to Analyze</label>
            <select name="lead_id" required>
                <option value="">-- Select a patient --</option>
                <?php foreach ($leads as $lead): ?>
                    <option value="<?php echo $lead['id']; ?>" <?php echo (isset($_POST['lead_id']) && $_POST['lead_id'] == $lead['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($lead['name']); ?> (<?php echo htmlspecialchars($lead['mobile']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit" name="generate_ai_journey" class="generate-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2v20M2 12h20"></path>
                </svg>
                Generate Journey
            </button>
        </form>

        <hr style="border: none; border-top: 1px solid var(--border); margin: 20px 0;">
        
        <label style="margin-bottom: 12px;">Recent Patients</label>
        <div style="max-height: 350px; overflow-y: auto;">
            <?php foreach ($leads as $lead): ?>
                <div style="background: #F9FAFB; border: 1px solid var(--border); padding: 12px; border-radius: 6px; margin-bottom: 10px; cursor: pointer; transition: all 0.2s ease;" onclick="document.querySelector('select[name=lead_id]').value='<?php echo $lead['id']; ?>'; document.querySelector('form').submit();">
                    <strong style="display: block; color: var(--text-main);"><?php echo htmlspecialchars($lead['name']); ?></strong>
                    <span style="color: var(--text-muted); display: block; font-size: 12px;"><?php echo htmlspecialchars($lead['mobile']); ?></span>
                    <span style="color: var(--text-muted); display: block; font-size: 12px;"><?php echo ucfirst($lead['status']); ?> • <?php echo date('M j', strtotime($lead['created_at'])); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="journey-content">
        <?php if (!empty($sync_message)): ?>
            <div class="status-message <?php echo strpos($sync_message, '✅') === 0 ? 'status-success' : 'status-error'; ?>">
                <?php echo htmlspecialchars($sync_message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($journey_result) && $selected_lead): ?>
            <div style="margin-bottom: 20px;">
                <h2 style="margin: 0 0 15px 0; font-size: 22px; color: var(--text-main);">
                    👤 <?php echo htmlspecialchars($selected_lead['name']); ?> - Journey Analysis
                </h2>
                <p style="color: var(--text-muted); font-size: 13px; margin: 0;">Generated: <?php echo date('M j, Y @ g:i A'); ?></p>
            </div>

            <div class="json-response-container">
                <div class="json-header">
                    <h3>🤖 AI Generated Journey JSON</h3>
                    <button class="btn btn-copy" onclick="copyToClipboard()">📋 Copy JSON</button>
                </div>

                <div class="json-viewer" id="jsonOutput">
<?php echo htmlspecialchars(json_encode(json_decode($journey_result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?>
                </div>

                <form method="POST" class="sync-form">
                    <input type="hidden" name="journey_json" value='<?php echo htmlspecialchars($journey_result); ?>'>
                    
                    <label>Journey Sync API Endpoint</label>
                    <input type="url" name="sync_endpoint" placeholder="https://your-api.com/journey/sync" required style="font-size: 13px;">
                    
                    <button type="submit" name="sync_journey" style="cursor: pointer;">
                        ✨ Sync to Account Service
                    </button>
                </form>
            </div>

            <div style="background: #FEF3C7; border: 1px solid #FCD34D; border-radius: 8px; padding: 16px; margin-top: 20px;">
                <h4 style="margin-top: 0; color: #92400E;">💡 How to Use</h4>
                <ol style="margin: 8px 0; padding-left: 20px; font-size: 13px; color: #78350F;">
                    <li>Review the AI-generated JSON journey above</li>
                    <li>Click "Copy JSON" to copy the entire response</li>
                    <li>Enter your Journey Sync API endpoint</li>
                    <li>Click "Sync to Account Service" to push the data</li>
                    <li>Check your account service for the synced journey</li>
                </ol>
            </div>

        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">🤖</div>
                <p><strong>AI Bot Journey Generator</strong></p>
                <p style="color: var(--text-muted); font-size: 13px; margin-top: 8px;">
                    Select a patient and click "Generate Journey" to analyze their customer journey using AI
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function copyToClipboard() {
    const jsonOutput = document.getElementById('jsonOutput');
    const text = jsonOutput.innerText;
    
    navigator.clipboard.writeText(text).then(() => {
        alert('✅ JSON copied to clipboard!');
    }).catch(() => {
        // Fallback
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        alert('✅ JSON copied to clipboard!');
    });
}
</script>

<?php require_once 'footer.php'; ?>