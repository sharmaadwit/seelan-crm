<?php
// api.php - Optimized for large payloads and preventing 504 errors
set_time_limit(120);
ini_set('memory_limit', '512M');
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Allow CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key");
header("Content-Type: application/json; charset=utf-8");
header("Connection: close");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Enable output buffering
ob_start();

function tableHasColumn(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$table, $column]);
    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

try {
    // Get input from POST or GET
    $input = [];
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $content = file_get_contents('php://input');
        if (!empty($content)) {
            $input = json_decode($content, true) ?? [];
        }
    }
    
    // Also check GET parameters and POST form data as fallback
    $input = array_merge($input, $_GET, $_POST);
    
    // Require database connection
    require_once 'db.php';
    require_once 'helpers.php';
    
    // Check if this is just a test request
    if (empty($input) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "API is working",
            "version" => "2.3",
            "methods" => ["create_lead", "book_appointment"],
            "documentation" => "Send POST request with api_key and action"
        ]);
        ob_end_flush();
        exit;
    }
    
    $action = $input['action'] ?? '';

    // ==========================================
    // REGISTER PROJECT
    // ==========================================
    if ($action === 'register_project') {
        $project_id = trim($input['project_id'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = trim($input['password'] ?? '');

        if (empty($project_id) || empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "project_id, email, and password are required"]);
            ob_end_flush();
            exit;
        }

        // Check if project exists by project_id (org_name)
        $stmt = $pdo->prepare("SELECT id FROM organizations WHERE LOWER(org_name) = ?");
        $stmt->execute([strtolower($project_id)]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(["status" => "error", "message" => "Project ID already exists."]);
            ob_end_flush();
            exit;
        }

        // Create new organization
        $api_key = "sk_live_" . bin2hex(random_bytes(16));
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("INSERT INTO organizations (org_name, email, password, api_key) VALUES (?, ?, ?, ?)");
        $stmt->execute([$project_id, $email, $hashed, $api_key]);

        http_response_code(201);
        echo json_encode([
            "status" => "success",
            "message" => "Project registered successfully",
            "project_id" => $project_id,
            "api_key" => $api_key
        ]);
        ob_end_flush();
        exit;
    }

    // Verify API key and Project ID for all other actions
    if (empty($input['project_id'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Missing project_id"]);
        ob_end_flush();
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, org_name, timezone FROM organizations WHERE api_key = ?");
    $stmt->execute([$input['api_key'] ?? '']);
    $org = $stmt->fetch();
    
    if (!$org || strtolower(trim($input['project_id'])) !== strtolower(trim($org['org_name']))) {
        http_response_code(401);
        echo json_encode([
            "status" => "error",
            "message" => "Invalid API Key or Project ID mismatch"
        ]);
        ob_end_flush();
        exit;
    }
    
    $org_id = $org['id'];

    // ==========================================
    // CREATE LEAD
    // ==========================================
    if ($action === 'create_lead') {
        $mobile = trim($input['mobile'] ?? '');
        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $camp = trim($input['campaign_name'] ?? 'Organic');
        
        $cpf_number = trim($input['cpf_number'] ?? '');
        $doctor_type = trim($input['doctor_type'] ?? '');
        $patient_type = trim($input['patient_type'] ?? '');
        $branch = trim($input['branch'] ?? '');
        $patient_name = trim($input['patient_name'] ?? '');
        $visit_reason = trim($input['visit_reason'] ?? '');
        
        if (empty($mobile) || empty($name)) {
            http_response_code(400);
            echo json_encode([
                "status" => "error",
                "message" => "Mobile and name are required"
            ]);
            ob_end_flush();
            exit;
        }
        
        // Find or Create Campaign
        $stmt = $pdo->prepare("SELECT id FROM campaigns WHERE campaign_name = ? AND org_id = ? LIMIT 1");
        $stmt->execute([$camp, $org_id]);
        $c = $stmt->fetch();
        
        if ($c) {
            $camp_id = $c['id'];
        } else {
            $pdo->prepare("INSERT INTO campaigns (org_id, campaign_name) VALUES (?, ?)")
                ->execute([$org_id, $camp]);
            $camp_id = $pdo->lastInsertId();
        }

        // Find or Create Lead
        $stmt = $pdo->prepare("SELECT id FROM leads WHERE mobile = ? AND org_id = ? LIMIT 1");
        $stmt->execute([$mobile, $org_id]);
        $lead = $stmt->fetch();
        
        if ($lead) {
            $lead_id = $lead['id'];
            $msg = "Existing lead found and mapped to new campaign";
            $is_new = false;
        } else {
            // Insert with email and extra fields if provided
            $columns = "org_id, name, mobile";
            $values = "?, ?, ?";
            $params = [$org_id, $name, $mobile];
            
            if (!empty($email) && tableHasColumn($pdo, 'leads', 'email')) {
                $columns .= ", email";
                $values .= ", ?";
                $params[] = $email;
            }
            if (tableHasColumn($pdo, 'leads', 'cpf_number')) {
                $columns .= ", cpf_number, doctor_type, patient_type, branch, patient_name, visit_reason";
                $values .= ", ?, ?, ?, ?, ?, ?";
                array_push($params, $cpf_number, $doctor_type, $patient_type, $branch, $patient_name, $visit_reason);
            }
            
            $pdo->prepare("INSERT INTO leads ($columns) VALUES ($values)")->execute($params);
            $lead_id = $pdo->lastInsertId();
            $msg = "New lead created successfully";
            $is_new = true;

            // Fire CURL webhooks for lead_created
            $leadPayload = [
                'lead_id'        => (int)$lead_id,
                'lead_name'      => $name,
                'mobile'         => $mobile,
                'email'          => $email,
                'status'         => 'New',
                'campaign_name'  => $camp,
                'source'         => 'api',
                'created_at'     => date('Y-m-d H:i:s'),
            ];
            sendCurlWebhooks($pdo, (int)$org_id, 'lead_created', $leadPayload);
        }

        // Map Lead to Campaign
        $pdo->prepare("INSERT IGNORE INTO lead_campaigns (org_id, lead_id, campaign_id) VALUES (?, ?, ?)")
            ->execute([$org_id, $lead_id, $camp_id]);

        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => $msg,
            "lead_id" => (int)$lead_id,
            "is_new" => $is_new,
            "timestamp" => date('Y-m-d H:i:s')
        ]);
        ob_end_flush();
        exit;
    }

    // ==========================================
    // BOOK APPOINTMENT
    // ==========================================
    if ($action === 'book_appointment') {
        $mobile = trim($input['mobile'] ?? '');
        $start = trim($input['start_time'] ?? '');
        $end = trim($input['end_time'] ?? '');
        
        if (empty($mobile) || empty($start) || empty($end)) {
            http_response_code(400);
            echo json_encode([
                "status" => "error",
                "message" => "Mobile, start_time, and end_time are required"
            ]);
            ob_end_flush();
            exit;
        }
        
        // Validate datetime format
        if (!strtotime($start) || !strtotime($end)) {
            http_response_code(400);
            echo json_encode([
                "status" => "error",
                "message" => "Invalid datetime format. Use: Y-m-d H:i:s"
            ]);
            ob_end_flush();
            exit;
        }
        
        // Find lead
        $stmt = $pdo->prepare("SELECT id, name FROM leads WHERE mobile = ? AND org_id = ? LIMIT 1");
        $stmt->execute([$mobile, $org_id]);
        $lead = $stmt->fetch();
        
        if (!$lead) {
            http_response_code(404);
            echo json_encode([
                "status" => "error",
                "message" => "Lead not found"
            ]);
            ob_end_flush();
            exit;
        }

        // Check availability
        $day = date('l', strtotime($start));
        $time = date('H:i:s', strtotime($start));
        
        $stmt = $pdo->prepare("SELECT capacity FROM agent_timeslots WHERE day_of_week = ? AND org_id = ? AND start_time <= ? AND end_time >= ? LIMIT 1");
        $stmt->execute([$day, $org_id, $time, $time]);
        $rule = $stmt->fetch();
        
        if (!$rule) {
            http_response_code(409);
            echo json_encode([
                "status" => "error",
                "message" => "Outside working hours"
            ]);
            ob_end_flush();
            exit;
        }

        // Check capacity
        $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM appointments WHERE status = 'Scheduled' AND org_id = ? AND start_time = ?");
        $stmt->execute([$org_id, $start]);
        $count = $stmt->fetch()['c'];
        
        if ($count >= $rule['capacity']) {
            http_response_code(409);
            echo json_encode([
                "status" => "error",
                "message" => "Time slot fully booked"
            ]);
            ob_end_flush();
            exit;
        }

        // Generate meeting link
        $link = "https://meet.google.com/" . substr(md5(microtime()), 0, 16);
        
        // Book appointment
        $pdo->prepare("INSERT INTO appointments (org_id, lead_id, start_time, end_time, meet_link, status) VALUES (?, ?, ?, ?, ?, 'Scheduled')")
            ->execute([$org_id, $lead['id'], $start, $end, $link]);
        
        $appt_id = $pdo->lastInsertId();
        
        // Update lead status
        $pdo->prepare("UPDATE leads SET status = 'Appointment Booked' WHERE id = ?")
            ->execute([$lead['id']]);

        http_response_code(201);
        echo json_encode([
            "status" => "success",
            "message" => "Appointment booked successfully",
            "appointment_id" => (int)$appt_id,
            "meet_link" => $link,
            "lead_id" => (int)$lead['id'],
            "timestamp" => date('Y-m-d H:i:s')
        ]);
        ob_end_flush();
        exit;
    }

    // ==========================================
    // CHECK TIMESTLOTS
    // ==========================================
    if ($action === 'check_slots') {
        $date = trim($input['date'] ?? date('Y-m-d'));
        if (!strtotime($date)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Invalid date format. Use Y-m-d"]);
            ob_end_flush();
            exit;
        }
        
        $day = date('l', strtotime($date));
        $timezone = $org['timezone'] ?? 'Asia/Kolkata';
        
        $stmt = $pdo->prepare("SELECT start_time, end_time, capacity FROM agent_timeslots WHERE day_of_week = ? AND org_id = ? ORDER BY start_time");
        $stmt->execute([$day, $org_id]);
        $timeslots = $stmt->fetchAll();
        
        $slotsRaw = [];
        foreach ($timeslots as $ts) {
            $stmt_app = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE org_id = ? AND start_time = ? AND status = 'Scheduled'");
            $check_time = $date . ' ' . $ts['start_time'];
            $stmt_app->execute([$org_id, $check_time]);
            $booked = $stmt_app->fetchColumn();
            
            $slotsRaw[] = [
                "time" => $ts['start_time'] . ' - ' . $ts['end_time'],
                "start_time" => $ts['start_time'],
                "end_time" => $ts['end_time'],
                "capacity" => $ts['capacity'],
                "booked" => $booked,
                "available" => max(0, $ts['capacity'] - $booked)
            ];
        }
        
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "timezone" => $timezone,
            "date" => $date,
            "slots" => $slotsRaw
        ]);
        ob_end_flush();
        exit;
    }

    // ==========================================
    // CHECK SPECIFIC SLOT
    // ==========================================
    if ($action === 'check_specific_slot') {
        $datetime = trim($input['datetime'] ?? '');
        
        if (!strtotime($datetime)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Invalid datetime format. Use Y-m-d H:i:s"]);
            ob_end_flush();
            exit;
        }
        
        $day = date('l', strtotime($datetime));
        $time = date('H:i:s', strtotime($datetime));
        
        // 1. Check if the time falls inside working hours
        $stmt = $pdo->prepare("SELECT capacity, start_time, end_time FROM agent_timeslots WHERE day_of_week = ? AND org_id = ? AND start_time <= ? AND end_time >= ? LIMIT 1");
        $stmt->execute([$day, $org_id, $time, $time]);
        $rule = $stmt->fetch();
        
        if (!$rule) {
            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "datetime" => $datetime,
                "is_available" => false,
                "reason" => "Outside configured working hours"
            ]);
            ob_end_flush();
            exit;
        }
        
        // 2. Check capacity vs actual scheduled bookings
        $stmt_app = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE org_id = ? AND start_time = ? AND status = 'Scheduled'");
        $stmt_app->execute([$org_id, $datetime]);
        $booked = (int)$stmt_app->fetchColumn();
        $capacity = (int)$rule['capacity'];
        
        $is_available = ($booked < $capacity);
        
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "datetime" => $datetime,
            "is_available" => $is_available,
            "total_capacity" => $capacity,
            "currently_booked" => $booked,
            "remaining_slots" => max(0, $capacity - $booked),
            "working_hours" => $rule['start_time'] . ' - ' . $rule['end_time']
        ]);
        ob_end_flush();
        exit;
    }

    // Invalid action
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid or missing action",
        "valid_actions" => ["create_lead", "book_appointment", "check_slots", "check_specific_slot", "register_project"]
    ]);
    ob_end_flush();
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    $error_msg = $e->getMessage();
    error_log("API PDO Error: " . $error_msg);
    echo json_encode([
        "status" => "error",
        "message" => "Database error occurred",
        "debug" => $error_msg // Remove in production
    ]);
    ob_end_flush();
    exit;
} catch (Exception $e) {
    http_response_code(500);
    $error_msg = $e->getMessage();
    error_log("API Error: " . $error_msg);
    echo json_encode([
        "status" => "error",
        "message" => "An error occurred",
        "debug" => $error_msg // Remove in production
    ]);
    ob_end_flush();
    exit;
}
?>