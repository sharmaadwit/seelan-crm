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
    $doctor_id = !empty($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : null;
    $start_date = $_GET['start'] ?? date('Y-m-d');
    $end_date = $_GET['end'] ?? date('Y-m-d', strtotime('+7 days'));

    // 1. Fetch Appointments (Scheduled or Completed)
    $sql_appts = "SELECT a.id, a.start_time as start, a.end_time as end, l.name as title, l.id as lead_id, l.mobile, l.patient_name, d.name as doctor_name, a.status
                  FROM appointments a 
                  JOIN leads l ON a.lead_id = l.id 
                  LEFT JOIN doctors d ON a.doctor_id = d.id
                  WHERE a.status IN ('Scheduled', 'Completed') AND a.org_id = ?";
    $params_appts = [$current_org_id];
    if ($doctor_id) {
        $sql_appts .= " AND a.doctor_id = ?";
        $params_appts[] = $doctor_id;
    }
    $stmt = $pdo->prepare($sql_appts);
    $stmt->execute($params_appts);
    $appointments = $stmt->fetchAll();

    $events = [];
    foreach ($appointments as $app) {
        $events[] = [
            'id' => 'appt_' . $app['id'],
            'db_id' => $app['id'],
            'type' => 'appointment',
            'start' => $app['start'],
            'end' => $app['end'],
            'title' => "📌 " . $app['title'] . ($app['doctor_name'] ? " (Dr. {$app['doctor_name']})" : ""),
            'color' => '#3B82F6', // Blue for appointments
            'extendedProps' => [
                'lead_id' => $app['lead_id'],
                'mobile' => $app['mobile'],
                'patient_name' => $app['patient_name'],
                'doctor_name' => $app['doctor_name']
            ]
        ];
    }

    // 2. Fetch Working Hours (Slots)
    $sql_slots = "SELECT * FROM agent_timeslots WHERE org_id = ?";
    $params_slots = [$current_org_id];
    if ($doctor_id) {
        $sql_slots .= " AND doctor_id = ?";
        $params_slots[] = $doctor_id;
    }
    $stmt_slots = $pdo->prepare($sql_slots);
    $stmt_slots->execute($params_slots);
    $timeslots = $stmt_slots->fetchAll();

    // To show slots for the visible range, we'll repeat them for each day
    // FullCalendar passes start/end in ISO format (e.g. 2026-03-20T00:00:00Z)
    $start_dt_str = explode('T', $start_date)[0];
    $end_dt_str = explode('T', $end_date)[0];
    
    $current = new DateTime($start_dt_str);
    $final_end = new DateTime($end_dt_str);
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($current, $interval, $final_end);

    foreach ($period as $dt) {
        $dayOfWeek = $dt->format('l');
        $dateStr = $dt->format('Y-m-d');

        foreach ($timeslots as $ts) {
            if ($ts['day_of_week'] === $dayOfWeek) {
                $ts_start = $dateStr . ' ' . $ts['start_time'];
                $ts_end = $dateStr . ' ' . $ts['end_time'];

                // Count appointments in this specific slot
                $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE org_id = ? AND status = 'Scheduled' AND start_time = ? AND (doctor_id = ? OR (doctor_id IS NULL AND ? IS NULL))");
                $stmt_count->execute([$current_org_id, $ts_start, $ts['doctor_id'], $ts['doctor_id']]);
                $booked = (int)$stmt_count->fetchColumn();
                $remaining = $ts['capacity'] - $booked;

                if ($remaining > 0) {
                    $events[] = [
                        'id' => 'slot_' . $ts['id'] . '_' . $dateStr,
                        'type' => 'slot_available',
                        'start' => $ts_start,
                        'end' => $ts_end,
                        'title' => "🟢 Open Slot ({$remaining} left)",
                        'color' => '#10B981', // Green
                        'display' => 'block'
                    ];
                } else {
                    $events[] = [
                        'id' => 'slot_full_' . $ts['id'] . '_' . $dateStr,
                        'type' => 'slot_full',
                        'start' => $ts_start,
                        'end' => $ts_end,
                        'title' => "🔴 Slot Full",
                        'color' => '#EF4444', // Red
                        'display' => 'block'
                    ];
                }
            }
        }
    }

    echo json_encode($events);
    exit;
}

// ==========================================
// ACTION 2: CREATE NEW APPOINTMENT
// ==========================================
if ($action === 'create') {
    $name      = trim($_POST['name']      ?? '');
    $mobile    = trim($_POST['mobile']    ?? '');
    $start     = trim($_POST['start']     ?? '');
    $end       = trim($_POST['end']       ?? '');
    $doctor_id = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;

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
        INSERT INTO appointments (org_id, lead_id, doctor_id, start_time, end_time, meet_link, status)
        VALUES (?, ?, ?, ?, ?, ?, 'Scheduled')
    ");
    $stmt->execute([$current_org_id, $lead_id, $doctor_id, $start, $end, $meet_link]);

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