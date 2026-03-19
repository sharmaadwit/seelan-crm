<?php
// ai_chat.php - AJAX endpoint for AI Analysis chat (no iframe)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['org_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once 'db.php';

$ai_api_key = 'sk_8_G2bOuXdXUcfC-4MVB-HnEYqN3NFk4lx-5PB7pzigV1c';
$ai_api_url = 'https://ce.smsgupshup.com/ai/ai_studio/api/agents/8/chat/stream';

$page_type = $_POST['type'] ?? 'calendar'; // calendar or analytics
$query = trim($_POST['query'] ?? '');

if ($query === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing query']);
    exit;
}

try {
    $context = '';
    if ($page_type === 'calendar') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as today_count FROM appointments 
            WHERE DATE(start_time) = CURDATE() AND org_id = ? AND status = 'Scheduled'
        ");
        $stmt->execute([$_SESSION['org_id']]);
        $today_appts = (int)($stmt->fetch()['today_count'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as upcoming_count FROM appointments 
            WHERE DATE(start_time) > CURDATE() AND org_id = ? AND status = 'Scheduled'
        ");
        $stmt->execute([$_SESSION['org_id']]);
        $upcoming_appts = (int)($stmt->fetch()['upcoming_count'] ?? 0);

        $context = "Calendar Statistics:\n";
        $context .= "- Appointments scheduled for today: " . $today_appts . "\n";
        $context .= "- Upcoming appointments (future dates): " . $upcoming_appts . "\n";
        $context .= "- Total: " . ($today_appts + $upcoming_appts) . "\n";
    } else {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT l.id) as total_leads,
                   COUNT(DISTINCT CASE WHEN l.status = 'Appointment Booked' THEN l.id END) as interested
            FROM leads l
            WHERE l.org_id = ?
        ");
        $stmt->execute([$_SESSION['org_id']]);
        $data = $stmt->fetch() ?: ['total_leads' => 0, 'interested' => 0];

        $total = (int)$data['total_leads'];
        $interested = (int)$data['interested'];

        $context = "Analytics Data:\n";
        $context .= "- Total leads: " . $total . "\n";
        $context .= "- Interested (Appointment Booked): " . $interested . "\n";
        $context .= "- Not interested: " . ($total - $interested) . "\n";
    }

    $full_prompt = $context . "\n\nUser Question: " . $query . "\n\nProvide a brief, helpful response.";
    $answer = callAIAPI($ai_api_url, $ai_api_key, $full_prompt);

    if (!$answer) {
        http_response_code(502);
        echo json_encode(['status' => 'error', 'message' => 'Failed to get response from AI. Please try again.']);
        exit;
    }

    echo json_encode(['status' => 'success', 'answer' => $answer]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error', 'debug' => $e->getMessage()]);
    exit;
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

