<?php
// calendar_actions.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure the request is coming from a logged-in organization
if (!isset($_SESSION['org_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
require_once 'db.php';
require_once 'helpers.php';

$current_org_id = $_SESSION['org_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ==========================================
// ACTION 1: FETCH EVENTS FOR CALENDAR
// ==========================================
if ($action === 'fetch') {
    // Fetch only scheduled appointments for this specific organization
    $stmt = $pdo->prepare("
        SELECT a.id, a.start_time as start, a.end_time as end, l.name as title 
        FROM appointments a 
        JOIN leads l ON a.lead_id = l.id 
        WHERE a.status = 'Scheduled' AND a.org_id = ?
    ");
    $stmt->execute([$current_org_id]);
    $events = $stmt->fetchAll();
    
    // Format for FullCalendar (forces events to show in the time grid, not as "all-day" banners)
    foreach ($events as &$event) {
        $event['allDay'] = false;
    }
    
    echo json_encode($events);
    exit;
}

// ==========================================
// ACTION 2: CREATE NEW APPOINTMENT
// ==========================================
if ($action === 'create') {
    $name   = trim($_POST['name']   ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $start  = trim($_POST['start']  ?? '');
    $end    = trim($_POST['end']    ?? '');

    if (empty($name) || empty($mobile) || empty($start) || empty($end)) {
        echo json_encode(['status' => 'error', 'message' => 'Name, mobile, start and end are required']);
        exit;
    }

    // Find or create lead
    $stmt = $pdo->prepare("SELECT id FROM leads WHERE org_id = ? AND mobile = ? LIMIT 1");
    $stmt->execute([$current_org_id, $mobile]);
    $lead = $stmt->fetch();

    if ($lead) {
        $lead_id = $lead['id'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO leads (org_id, name, mobile) VALUES (?, ?, ?)");
        $stmt->execute([$current_org_id, $name, $mobile]);
        $lead_id = $pdo->lastInsertId();
    }

    // Create appointment
    $meet_link = generateMeetLink();

    $stmt = $pdo->prepare("
        INSERT INTO appointments (org_id, lead_id, start_time, end_time, meet_link, status)
        VALUES (?, ?, ?, ?, ?, 'Scheduled')
    ");
    $stmt->execute([$current_org_id, $lead_id, $start, $end, $meet_link]);

    $appt_id = $pdo->lastInsertId();

    // Prepare data for optional WhatsApp notification (event type: 'book')
    $dynamic_data = [
        'lead_name'  => $name,
        'start_time' => date('M j, Y g:i A', strtotime($start)),
        'meet_link'  => $meet_link
    ];

    // Fire WhatsApp template if mapping is configured (errors are ignored)
    @sendGupshupTemplate($pdo, $current_org_id, 'book', $mobile, $dynamic_data, $lead_id);

    echo json_encode([
        'status' => 'success',
        'appointment_id' => (int)$appt_id
    ]);
    exit;
}

// ==========================================
// ACTION 3: UPDATE (RESCHEDULE) OR CANCEL
// ==========================================
if ($action === 'update' || $action === 'cancel') {
    $id = $_POST['id'] ?? null;
    
    if (!$id) {
        echo json_encode(['status' => 'error', 'message' => 'Appointment ID missing']);
        exit;
    }

    // 1. Verify Ownership & Fetch Data Needed for WhatsApp Variables
    $stmt = $pdo->prepare("
        SELECT a.id, a.lead_id, l.mobile, l.name as lead_name, a.meet_link, a.start_time 
        FROM appointments a 
        JOIN leads l ON a.lead_id = l.id 
        WHERE a.id = ? AND a.org_id = ?
    ");
    $stmt->execute([$id, $current_org_id]);
    $data = $stmt->fetch();
    
    if (!$data) {
        echo json_encode(['status' => 'error', 'message' => 'Appointment not found or unauthorized']);
        exit;
    }

    // --- RESCHEDULE LOGIC ---
    if ($action === 'update') {
        $new_start = $_POST['start'];
        $new_end = $_POST['end'];

        // Update Database
        $stmt_update = $pdo->prepare("UPDATE appointments SET start_time = ?, end_time = ? WHERE id = ?");
        $stmt_update->execute([$new_start, $new_end, $id]);
        
        // Prepare Dynamic Data for Gupshup Variables (e.g., {{1}}, {{2}})
        $dynamic_data = [
            'lead_name'  => $data['lead_name'],
            'start_time' => date('M j, Y g:i A', strtotime($new_start)), // Formats nicely: Mar 10, 2026 2:00 PM
            'meet_link'  => $data['meet_link']
        ];
        
        // Fire WhatsApp Template API (Event type must match what you saved in settings.php)
        sendGupshupTemplate($pdo, $current_org_id, 'reschedule', $data['mobile'], $dynamic_data, $data['lead_id']);
    } 
    
    // --- CANCEL LOGIC ---
    else if ($action === 'cancel') {
        // Update Database Status
        $stmt_cancel = $pdo->prepare("UPDATE appointments SET status = 'Cancelled' WHERE id = ?");
        $stmt_cancel->execute([$id]);
        
        // Prepare Dynamic Data for Gupshup Variables
        $dynamic_data = [
            'lead_name'  => $data['lead_name'],
            'start_time' => date('M j, Y g:i A', strtotime($data['start_time'])), 
            'meet_link'  => '' // Usually not needed for a cancellation, but here just in case
        ];
        
        // Fire WhatsApp Template API
        sendGupshupTemplate($pdo, $current_org_id, 'cancel', $data['mobile'], $dynamic_data, $data['lead_id']);
    }
    
    echo json_encode(['status' => 'success']); 
    exit;
}

// If no valid action matches
echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
?>