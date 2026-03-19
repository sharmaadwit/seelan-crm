<?php
// ai_analysis.php - AI Analysis Chat Widget
require_once 'db.php';
require_once 'header.php';

$ai_api_key = 'sk_8_G2bOuXdXUcfC-4MVB-HnEYqN3NFk4lx-5PB7pzigV1c';
$ai_api_url = 'https://ce.smsgupshup.com/ai/ai_studio/api/agents/8/chat/stream';

$page_type = $_GET['type'] ?? 'calendar'; // calendar or analytics
$response = '';
$error = '';

// Handle AI query
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_query'])) {
    $query = trim($_POST['ai_query']);
    
    if ($page_type === 'calendar') {
        // Get calendar data
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as today_count FROM appointments 
            WHERE DATE(start_time) = CURDATE() AND org_id = ? AND status = 'Scheduled'
        ");
        $stmt->execute([$current_org_id]);
        $today_appts = $stmt->fetch()['today_count'];
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as upcoming_count FROM appointments 
            WHERE DATE(start_time) > CURDATE() AND org_id = ? AND status = 'Scheduled'
        ");
        $stmt->execute([$current_org_id]);
        $upcoming_appts = $stmt->fetch()['upcoming_count'];
        
        $context = "Calendar Statistics:\n";
        $context .= "- Appointments scheduled for today: " . $today_appts . "\n";
        $context .= "- Upcoming appointments (future dates): " . $upcoming_appts . "\n";
        $context .= "- Total: " . ($today_appts + $upcoming_appts) . "\n";
        
    } else { // analytics
        // Get analytics data
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT l.id) as total_leads,
                   COUNT(DISTINCT CASE WHEN l.status = 'Appointment Booked' THEN l.id END) as interested
            FROM leads l
            WHERE l.org_id = ?
        ");
        $stmt->execute([$current_org_id]);
        $data = $stmt->fetch();
        
        $context = "Analytics Data:\n";
        $context .= "- Total leads: " . $data['total_leads'] . "\n";
        $context .= "- Interested (Appointment Booked): " . $data['interested'] . "\n";
        $context .= "- Not interested: " . ($data['total_leads'] - $data['interested']) . "\n";
    }
    
    $full_prompt = $context . "\n\nUser Question: " . $query . "\n\nProvide a brief, helpful response.";
    
    // Call AI API
    $response = callAIAPI($ai_api_url, $ai_api_key, $full_prompt);
    
    if (!$response) {
        $error = "Failed to get response from AI. Please try again.";
    }
}

function callAIAPI($api_url, $api_key, $message) {
    $payload = json_encode([
        'message' => $message,
        'session_id' => 'analysis_' . time(),
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
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        // Extract text from streaming response
        $lines = explode("\n", $response);
        $text_response = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $text_response .= $line . " ";
            }
        }
        
        return trim($text_response);
    }
    
    return null;
}
?>

<style>
.ai-analysis-container {
    max-width: 800px;
    margin: 0 auto;
}

.chat-widget {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.chat-header {
    background: linear-gradient(135deg, var(--primary) 0%, #7C3AED 100%);
    color: white;
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chat-header h2 {
    margin: 0;
    font-size: 20px;
}

.chat-close {
    background: none;
    border: none;
    color: white;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.chat-close:hover {
    opacity: 0.8;
}

.chat-messages {
    height: 400px;
    overflow-y: auto;
    padding: 20px;
    background: #F9FAFB;
}

.message {
    margin-bottom: 15px;
    display: flex;
    gap: 10px;
}

.message.user {
    justify-content: flex-end;
}

.message.ai {
    justify-content: flex-start;
}

.message-content {
    background: white;
    padding: 12px 16px;
    border-radius: 8px;
    max-width: 70%;
    border: 1px solid var(--border);
}

.message.user .message-content {
    background: var(--primary);
    color: white;
    border: none;
}

.message.ai .message-content {
    background: white;
    color: var(--text-main);
    border: 1px solid var(--border);
}

.message-icon {
    display: flex;
    align-items: flex-end;
    font-size: 20px;
}

.chat-input-area {
    padding: 20px;
    background: white;
    border-top: 1px solid var(--border);
}

.chat-form {
    display: flex;
    gap: 10px;
}

.chat-input {
    flex: 1;
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 12px;
    font-size: 14px;
    font-family: inherit;
}

.chat-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.chat-send {
    background: var(--primary);
    color: white;
    border: none;
    border-radius: 6px;
    padding: 12px 24px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.chat-send:hover {
    background: #4338CA;
    transform: translateY(-2px);
}

.chat-send:disabled {
    background: #9CA3AF;
    cursor: not-allowed;
    transform: none;
}

.placeholder-text {
    text-align: center;
    color: var(--text-muted);
    padding: 40px 20px;
    font-size: 14px;
}

.error-message {
    background: #FEE2E2;
    color: #B91C1C;
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 15px;
    border: 1px solid #FCA5A5;
}

@media (max-width: 768px) {
    .message-content {
        max-width: 90%;
    }
    
    .chat-messages {
        height: 300px;
    }
}
</style>

<div class="ai-analysis-container" style="padding: 20px;">
    <div class="chat-widget">
        <div class="chat-header">
            <h2>🤖 AI Analysis Chat</h2>
            <button class="chat-close" onclick="closeChat()">×</button>
        </div>

        <div class="chat-messages" id="chatMessages">
            <div class="placeholder-text">
                <p><strong>AI Analysis Assistant</strong></p>
                <p style="margin-top: 8px;">
                    <?php if ($page_type === 'calendar'): ?>
                        Ask me questions about your calendar and appointments
                    <?php else: ?>
                        Ask me questions about your leads and analytics
                    <?php endif; ?>
                </p>
                <p style="margin-top: 12px; font-size: 13px; color: #9CA3AF;">
                    Examples:
                    <?php if ($page_type === 'calendar'): ?>
                        <br>"How many appointments today?"
                        <br>"What's my appointment schedule?"
                    <?php else: ?>
                        <br>"How many interested leads do I have?"
                        <br>"What's my lead breakdown?"
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="chat-input-area">
            <?php if (!empty($error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="chat-form" id="chatForm">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($page_type); ?>">
                <input 
                    type="text" 
                    name="ai_query" 
                    class="chat-input" 
                    placeholder="Ask me something..." 
                    required
                    id="queryInput"
                    autocomplete="off"
                >
                <button type="submit" class="chat-send" id="sendBtn">Send</button>
            </form>
        </div>
    </div>
</div>

<script>
function closeChat() {
    window.close();
    // Or if opened as modal, hide it
    if (window.opener) {
        window.close();
    }
}

document.getElementById('chatForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const query = document.getElementById('queryInput').value;
    const sendBtn = document.getElementById('sendBtn');
    
    sendBtn.disabled = true;
    sendBtn.textContent = 'Sending...';
    
    // Add user message
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages.children[0].classList.contains('placeholder-text')) {
        chatMessages.innerHTML = '';
    }
    
    const userMsg = document.createElement('div');
    userMsg.className = 'message user';
    userMsg.innerHTML = `
        <div class="message-content">${escapeHtml(query)}</div>
        <div class="message-icon">👤</div>
    `;
    chatMessages.appendChild(userMsg);
    
    // Submit form
    this.submit();
});

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

<?php if (!empty($response)): ?>
    // Show AI response
    window.addEventListener('load', function() {
        const chatMessages = document.getElementById('chatMessages');
        
        if (chatMessages.children[0].classList.contains('placeholder-text')) {
            chatMessages.innerHTML = '';
        }
        
        const aiMsg = document.createElement('div');
        aiMsg.className = 'message ai';
        aiMsg.innerHTML = `
            <div class="message-icon">🤖</div>
            <div class="message-content"><?php echo htmlspecialchars(substr($response, 0, 500)); ?></div>
        `;
        chatMessages.appendChild(aiMsg);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        
        document.getElementById('sendBtn').disabled = false;
        document.getElementById('sendBtn').textContent = 'Send';
        document.getElementById('queryInput').focus();
    });
<?php endif; ?>
</script>

<?php require_once 'footer.php'; ?>
