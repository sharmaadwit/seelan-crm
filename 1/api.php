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

    $stmt = $pdo->prepare("SELECT id, org_name, timezone, slot_duration_minutes, event_type, event_address FROM organizations WHERE api_key = ?");
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
    if (!empty($org['timezone'])) {
        date_default_timezone_set($org['timezone']);
    } else {
        date_default_timezone_set('UTC');
    }

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
    // GET DOCTORS
    // ==========================================
    if ($action === 'get_doctors') {
        $stmt = $pdo->prepare("SELECT id, name, specialization FROM doctors WHERE org_id = ? AND is_active = 1 ORDER BY name ASC");
        $stmt->execute([$org_id]);
        $docs = $stmt->fetchAll();
        
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "doctors" => $docs
        ]);
        ob_end_flush();
        exit;
    }

    // ==========================================
    // GET DOCTOR BOOKINGS (Status)
    // ==========================================
    if ($action === 'get_doctor_bookings') {
        $doctor_name = trim($input['doctor_name'] ?? '');
        $date = trim($input['date'] ?? ''); // Optional filter
        
        if (empty($doctor_name)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "doctor_name is required"]);
            ob_end_flush(); exit;
        }

        // Find Doctor
        $stmt = $pdo->prepare("SELECT id FROM doctors WHERE name = ? AND org_id = ? LIMIT 1");
        $stmt->execute([$doctor_name, $org_id]);
        $dr = $stmt->fetch();

        if (!$dr) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Doctor not found"]);
            ob_end_flush(); exit;
        }

        $query = "SELECT a.id, a.start_time, a.end_time, a.status, l.name as patient_name, l.mobile as patient_mobile 
                  FROM appointments a 
                  JOIN leads l ON a.lead_id = l.id 
                  WHERE a.doctor_id = ? AND a.org_id = ?";
        $params = [$dr['id'], $org_id];

        if (!empty($date)) {
            $query .= " AND DATE(a.start_time) = ?";
            $params[] = $date;
        }
        $query .= " ORDER BY a.start_time ASC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $bookings = $stmt->fetchAll();

        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "doctor" => $doctor_name,
            "bookings" => $bookings
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
        
        // Find doctor
        $doctor_id = !empty($input['doctor_id']) ? (int)$input['doctor_id'] : null;
        if (!$doctor_id) {
            // Default to Generic Doctor if no ID provided
            $stmt_dr = $pdo->prepare("SELECT id FROM doctors WHERE org_id = ? ORDER BY (name = 'Generic Doctor') DESC, id ASC LIMIT 1");
            $stmt_dr->execute([$org_id]);
            $dr_row = $stmt_dr->fetch();
            $doctor_id = $dr_row ? $dr_row['id'] : null;
        }

        // Find or Create Lead
        $stmt = $pdo->prepare("SELECT id, name FROM leads WHERE mobile = ? AND org_id = ? LIMIT 1");
        $stmt->execute([$mobile, $org_id]);
        $lead = $stmt->fetch();
        
        if (!$lead) {
            // AUTO-CREATE LEAD IF NOT FOUND
            $patient_name = trim($input['patient_name'] ?? 'Online Patient');
            $pdo->prepare("INSERT INTO leads (org_id, name, mobile, status) VALUES (?, ?, ?, 'New')")
                ->execute([$org_id, $patient_name, $mobile]);
            $lead_id = $pdo->lastInsertId();
            
            // Link to "Public Booking" campaign
            $stmt_c = $pdo->prepare("SELECT id FROM campaigns WHERE campaign_name = 'Public Booking' AND org_id = ?");
            $stmt_c->execute([$org_id]);
            $camp_id = $stmt_c->fetchColumn();
            
            if (!$camp_id) {
                $pdo->prepare("INSERT INTO campaigns (org_id, campaign_name) VALUES (?, 'Public Booking')")
                    ->execute([$org_id]);
                $camp_id = $pdo->lastInsertId();
            }
            
            $pdo->prepare("INSERT INTO lead_campaigns (lead_id, campaign_id) VALUES (?, ?)")
                ->execute([$lead_id, $camp_id]);
                
            $lead = ['id' => $lead_id, 'name' => $patient_name];
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
        $sql_cap = "SELECT COUNT(*) as c FROM appointments WHERE status = 'Scheduled' AND org_id = ? AND start_time = ?";
        $params_cap = [$org_id, $start];
        if ($doctor_id) {
            $sql_cap .= " AND doctor_id = ?";
            $params_cap[] = $doctor_id;
        } else {
            $sql_cap .= " AND doctor_id IS NULL";
        }
        
        $stmt = $pdo->prepare($sql_cap);
        $stmt->execute($params_cap);
        $count = $stmt->fetch()['c'];
        
        if ($count >= $rule['capacity']) {
            http_response_code(409);
            echo json_encode([
                "status" => "error",
                "message" => "Time slot fully booked for this doctor"
            ]);
            ob_end_flush();
            exit;
        }

        // Generate meeting link
        if (($org['event_type'] ?? 'google_meet') === 'in_person') {
            $link = !empty($org['event_address']) ? $org['event_address'] : 'In-Person Appointment';
        } else {
            $link = "https://meet.google.com/" . substr(md5(microtime()), 0, 16);
        }
        
        // Book appointment
        $pdo->prepare("INSERT INTO appointments (org_id, lead_id, start_time, end_time, meet_link, status, doctor_id) VALUES (?, ?, ?, ?, ?, 'Scheduled', ?)")
            ->execute([$org_id, $lead['id'], $start, $end, $link, $doctor_id]);
        
        $appt_id = $pdo->lastInsertId();
        
        // Update lead status
        $pdo->prepare("UPDATE leads SET status = 'Appointment Booked' WHERE id = ?")
            ->execute([$lead['id']]);

        http_response_code(201);
        // Prepare dynamic data for WhatsApp
        $dynamic_data = [
            'lead_name'  => $lead['name'],
            'start_time' => date('M j, Y g:i A', strtotime($start)),
            'meet_link'  => $final_meet_link
        ];
        
        // Fire WhatsApp Template (ignores errors)
        @sendGupshupTemplate($pdo, $org_id, 'book', $mobile, $dynamic_data, $lead['id']);

        echo json_encode([
            "status" => "success",
            "message" => "Appointment booked successfully",
            "appointment_id" => (int)$appt_id,
            "meet_link" => $final_meet_link,
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
        
        $doctor_id = !empty($input['doctor_id']) ? (int)$input['doctor_id'] : null;
        if (!$doctor_id) {
            // Default to Generic Doctor if no ID provided
            $stmt_dr = $pdo->prepare("SELECT id FROM doctors WHERE org_id = ? ORDER BY (name = 'Generic Doctor') DESC, id ASC LIMIT 1");
            $stmt_dr->execute([$org_id]);
            $dr_row = $stmt_dr->fetch();
            $doctor_id = $dr_row ? $dr_row['id'] : null;
        }
        
        $sql = "SELECT start_time, end_time, capacity FROM agent_timeslots WHERE day_of_week = ? AND org_id = ?";
        $params = [$day, $org_id];
        if ($doctor_id) {
            $sql .= " AND doctor_id = ?";
            $params[] = $doctor_id;
        } else {
            $sql .= " AND doctor_id IS NULL";
        }
        $sql .= " ORDER BY start_time";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $timeslots = $stmt->fetchAll();
        
        $slot_duration = (int)($org['slot_duration_minutes'] ?? 30);
        if ($slot_duration < 5) $slot_duration = 30; // Min 5 minutes safety
        
        $slotsRaw = [];
        $uniqueSlots = [];
        foreach ($timeslots as $ts) {
            $start_ts = strtotime($date . ' ' . $ts['start_time']);
            $end_ts = strtotime($date . ' ' . $ts['end_time']);
            
            while ($start_ts + ($slot_duration * 60) <= $end_ts) {
                $slot_start_time = date('H:i:s', $start_ts);
                $slot_end_time = date('H:i:s', $start_ts + ($slot_duration * 60));
                
                $slot_key = $slot_start_time . '-' . $slot_end_time;
                $doc_id_input = !empty($input['doctor_id']) ? (int)$input['doctor_id'] : null;

                // If we already have this slot, check if this one has more capacity
                if (isset($uniqueSlots[$slot_key])) {
                    $uniqueSlots[$slot_key]['capacity'] += (int)$ts['capacity'];
                    // We don't re-calculate booked here because uniqueSlots already includes it
                    $uniqueSlots[$slot_key]['available'] = max(0, $uniqueSlots[$slot_key]['capacity'] - $uniqueSlots[$slot_key]['booked']);
                } else {
                    $check_time = $date . ' ' . $slot_start_time;
                    // Booked check must be doctor-specific if doctor_id is provided
                    $sql_app = "SELECT COUNT(*) FROM appointments WHERE org_id = ? AND start_time = ? AND status = 'Scheduled'";
                    $params_app = [$org_id, $check_time];
                    if ($doc_id_input) {
                        $sql_app .= " AND doctor_id = ?";
                        $params_app[] = $doc_id_input;
                    }
                    
                    $stmt_app = $pdo->prepare($sql_app);
                    $stmt_app->execute($params_app);
                    $booked = (int)$stmt_app->fetchColumn();
                    
                    $uniqueSlots[$slot_key] = [
                        "time" => date('g:i A', $start_ts) . ' - ' . date('g:i A', $start_ts + ($slot_duration * 60)),
                        "start_time" => $slot_start_time,
                        "end_time" => $slot_end_time,
                        "capacity" => (int)$ts['capacity'],
                        "booked" => $booked,
                        "available" => max(0, (int)$ts['capacity'] - $booked)
                    ];
                }
                
                $start_ts += ($slot_duration * 60);
            }
        }
        $slotsRaw = array_values($uniqueSlots);
        
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
        
        $doctor_id = !empty($input['doctor_id']) ? (int)$input['doctor_id'] : null;
        if (!$doctor_id) {
            // Default to Generic Doctor if no ID provided
            $stmt_dr = $pdo->prepare("SELECT id FROM doctors WHERE org_id = ? ORDER BY (name = 'Generic Doctor') DESC, id ASC LIMIT 1");
            $stmt_dr->execute([$org_id]);
            $dr_row = $stmt_dr->fetch();
            $doctor_id = $dr_row ? $dr_row['id'] : null;
        }
        
        // 1. Check if the time falls inside working hours
        $sql_ts = "SELECT capacity, start_time, end_time FROM agent_timeslots WHERE day_of_week = ? AND org_id = ? AND start_time <= ? AND end_time >= ?";
        $params_ts = [$day, $org_id, $time, $time];
        if ($doctor_id) { $sql_ts .= " AND doctor_id = ?"; $params_ts[] = $doctor_id; }
        else { $sql_ts .= " AND doctor_id IS NULL"; }
        $sql_ts .= " LIMIT 1";

        $stmt = $pdo->prepare($sql_ts);
        $stmt->execute($params_ts);
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
        $sql_app = "SELECT COUNT(*) FROM appointments WHERE org_id = ? AND start_time = ? AND status = 'Scheduled'";
        $params_app = [$org_id, $datetime];
        if ($doctor_id) { $sql_app .= " AND doctor_id = ?"; $params_app[] = $doctor_id; }
        else { $sql_app .= " AND doctor_id IS NULL"; }

        $stmt_app = $pdo->prepare($sql_app);
        $stmt_app->execute($params_app);
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