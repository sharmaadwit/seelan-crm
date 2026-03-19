<?php
// settings.php
require_once 'db.php';
require_once 'helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$current_org_id = $_SESSION['org_id'] ?? null;
if (!$current_org_id) { header("Location: login.php"); exit; }

$msg = '';

// Handle Logo Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['org_logo'])) {
    $file = $_FILES['org_logo'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (in_array(strtolower($ext), $allowed)) {
            $filename = "logo_" . $current_org_id . "_" . time() . "." . $ext;
            $upload_dir = 'uploads/';
            
            // Ensure directory exists or try to create it
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0777, true)) {
                    $msg = "Error: 'uploads' directory missing and could not be created.";
                }
            }
            
            if (empty($msg)) {
                if (is_writable($upload_dir)) {
                    if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                        $pdo->prepare("UPDATE organizations SET org_logo = ? WHERE id = ?")->execute([$filename, $current_org_id]);
                        $msg = "Logo uploaded successfully!";
                    } else {
                        $msg = "Failed to move uploaded file. Check PHP tmp_dir and target permissions.";
                    }
                } else {
                    $msg = "Error: 'uploads' directory is not writable. Check permissions.";
                }
            }
        } else {
            $msg = "Invalid file type. Allowed: " . implode(', ', $allowed);
        }
    } else {
        $msg = "Upload error: " . $file['error'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. GENERATE API KEY
    if (isset($_POST['gen_api'])) {
        $key = "sk_live_" . bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE organizations SET api_key = ? WHERE id = ?")->execute([$key, $current_org_id]);
        $msg = "New API Key generated successfully.";
    }

    // 2. SAVE GUPSHUP CREDENTIALS
    if (isset($_POST['save_gupshup'])) {
        $userid = trim($_POST['gupshup_userid']);
        $password = trim($_POST['gupshup_password']);
        $app_id = trim($_POST['gupshup_app_id']);
        $api_key = trim($_POST['gupshup_api_key']);
        
        if (!empty($password) && $password !== '********') {
            $enc_pass = encryptPassword($password);
            $pdo->prepare("UPDATE organizations SET gupshup_userid = ?, gupshup_password = ?, gupshup_app_id = ?, gupshup_api_key = ? WHERE id = ?")->execute([$userid, $enc_pass, $app_id, $api_key, $current_org_id]);
        } else {
            $pdo->prepare("UPDATE organizations SET gupshup_userid = ?, gupshup_app_id = ?, gupshup_api_key = ? WHERE id = ?")->execute([$userid, $app_id, $api_key, $current_org_id]);
        }
        $msg = "WhatsApp credentials saved successfully.";
    }

    // 3. SAVE CURL WEBHOOK
    if (isset($_POST['save_curl'])) {
        $endpoint = trim($_POST['curl_endpoint']);
        $event = trim($_POST['webhook_event']);
        $mapping = trim($_POST['variable_mapping']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (!empty($endpoint) && !empty($event)) {
            // Validate JSON mapping (if provided)
            $is_valid_json = true;
            if (!empty($mapping)) {
                json_decode($mapping, true);
                $is_valid_json = (json_last_error() === JSON_ERROR_NONE);
            }

            if (!$is_valid_json) {
                $msg = "Invalid JSON in variable mapping.";
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO curl_configs (org_id, curl_endpoint, webhook_event, variable_mapping, is_active) 
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                        curl_endpoint = VALUES(curl_endpoint),
                        variable_mapping = VALUES(variable_mapping),
                        is_active = VALUES(is_active)
                    ");
                    $stmt->execute([$current_org_id, $endpoint, $event, $mapping, $is_active]);
                    $msg = "Webhook configured successfully.";
                } catch (PDOException $e) {
                    $msg = "Failed to save webhook configuration. Please ensure the database table exists.";
                    error_log("Settings save_curl error: " . $e->getMessage());
                }
            }
        } else {
            $msg = "Please fill in all required fields.";
        }
    }
    
    // 4. DELETE CURL WEBHOOK
    if (isset($_POST['delete_curl'])) {
        $curl_id = (int)$_POST['delete_curl'];
        $pdo->prepare("DELETE FROM curl_configs WHERE id = ? AND org_id = ?")->execute([$curl_id, $current_org_id]);
        $msg = "Webhook deleted successfully.";
    }

    // 5. TEST CURL WEBHOOK
    if (isset($_POST['test_curl'])) {
        $curl_id = (int)$_POST['test_curl'];
        $project_id = $_SESSION['project_id'] ?? null;

        $stmt = $pdo->prepare("SELECT webhook_event FROM curl_configs WHERE id = ? AND org_id = ?");
        $stmt->execute([$curl_id, $current_org_id]);
        $cfg = $stmt->fetch();

        if ($cfg) {
            $event = $cfg['webhook_event'];
            // Simple sample payload
            $sample = [
                'lead_id'        => 99999,
                'lead_name'      => 'Test Lead',
                'mobile'         => '9999999999',
                'email'          => 'test@example.com',
                'status'         => 'New',
                'campaign_name'  => 'Test Campaign',
                'source'         => 'test',
                'created_at'     => date('Y-m-d H:i:s'),
                'appointment_id' => 0,
                'start_time'     => date('Y-m-d H:i:s', strtotime('+1 day')),
                'end_time'       => date('Y-m-d H:i:s', strtotime('+1 day +30 minutes')),
                'meet_link'      => 'https://example.com/test-meet'
            ];
            sendCurlWebhooks($pdo, (int)$current_org_id, $event, $sample, $project_id ? (int)$project_id : null, $curl_id);
            $msg = "Test webhook fired for ID #" . $curl_id . ". Check your endpoint / logs.";
        } else {
            $msg = "Unable to find webhook to test.";
        }
    }
    
    // 6. SYNC TEMPLATES FROM GUPSHUP API
    if (isset($_POST['sync_templates'])) {
        $stmt = $pdo->prepare("SELECT gupshup_userid, gupshup_password FROM organizations WHERE id = ?");
        $stmt->execute([$current_org_id]);
        $creds = $stmt->fetch();
        
        if ($creds && !empty($creds['gupshup_userid'])) {
            $pass = decryptPassword($creds['gupshup_password']);
            $url = "https://wamedia.smsgupshup.com/GatewayAPI/rest?method=get_whatsapp_hsm&userid={$creds['gupshup_userid']}&password={$pass}&limit=100";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $data = json_decode($response, true);
            
            if (isset($data['data'])) {
                $pdo->prepare("DELETE FROM wa_templates WHERE org_id = ?")->execute([$current_org_id]);
                
                $inserted = 0;
                foreach ($data['data'] as $item) {
                    if (isset($item['button_type']) && $item['button_type'] === 'STATIC' && isset($item['type']) && $item['type'] === 'TEXT') {
                        $body = $item['body'];
                        preg_match_all('/{{(\d+)}}/', $body, $matches);
                        $var_count = empty($matches[1]) ? 0 : max($matches[1]);
                        
                        $stmt = $pdo->prepare("INSERT INTO wa_templates (org_id, template_id, name, body, var_count) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$current_org_id, $item['id'], $item['name'], $body, $var_count]);
                        $inserted++;
                    }
                }
                $msg = "Successfully synced $inserted compatible text/static templates.";
            } else {
                $msg = "Error syncing templates. Please check your Gupshup credentials.";
            }
        } else {
            $msg = "Please save your Gupshup credentials first before syncing.";
        }
    }

    // 7. SAVE TEMPLATE VARIABLE MAPPING
    if (isset($_POST['save_mapping'])) {
        $event = $_POST['event_type']; // 'cancel', 'reschedule', or 'reminder'
        $template_id = $_POST['template_id'];
        
        $mapping = [];
        foreach ($_POST as $key => $val) {
            if (strpos($key, 'var_') === 0) {
                $var_num = str_replace('var_', '', $key);
                $mapping[$var_num] = $val;
            }
        }
        $json_map = json_encode($mapping);
        
        $stmt = $pdo->prepare("INSERT INTO wa_event_mappings (org_id, event_type, template_id, var_mapping) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE template_id = ?, var_mapping = ?");
        $stmt->execute([$current_org_id, $event, $template_id, $json_map, $template_id, $json_map]);
        $msg = ucfirst($event) . " template mapping saved successfully!";
    }

    // 8. SAVE REMINDER TIME
    if (isset($_POST['save_reminder_time'])) {
        $pdo->prepare("UPDATE organizations SET reminder_minutes = ? WHERE id = ?")->execute([$_POST['reminder_minutes'], $current_org_id]);
        $msg = "Reminder timing updated!";
    }

    // SAVE APPOINTMENT SETTINGS (Timezone, Slot Duration, Event Type)
    if (isset($_POST['save_appt_settings'])) {
        $pdo->prepare("UPDATE organizations SET timezone = ?, slot_duration_minutes = ?, event_type = ?, event_address = ? WHERE id = ?")->execute([
            $_POST['timezone'], 
            (int)$_POST['slot_duration_minutes'], 
            $_POST['event_type'], 
            $_POST['event_address'], 
            $current_org_id
        ]);
        $msg = "Appointment settings updated!";
    }

    // ADD DOCTOR
    if (isset($_POST['add_doctor'])) {
        $name = trim($_POST['doctor_name']);
        $spec = trim($_POST['doctor_specialization']);
        if (!empty($name)) {
            $pdo->prepare("INSERT INTO doctors (org_id, name, specialization) VALUES (?, ?, ?)")->execute([$current_org_id, $name, $spec]);
            $msg = "Doctor added successfully.";
        }
    }

    // DELETE DOCTOR
    if (isset($_POST['delete_doctor'])) {
        $pdo->prepare("DELETE FROM doctors WHERE id = ? AND org_id = ?")->execute([$_POST['doctor_id'], $current_org_id]);
        $msg = "Doctor removed.";
    }

    // ADD USER
    if (isset($_POST['add_user']) && ($_SESSION['user_type'] ?? '') === 'admin') {
        $upass = password_hash($_POST['user_password'], PASSWORD_DEFAULT);
        try {
            $pdo->prepare("INSERT INTO users (org_id, name, email, password) VALUES (?, ?, ?, ?)")
                ->execute([$current_org_id, $_POST['user_name'], $_POST['user_email'], $upass]);
            $msg = "User added successfully.";
        } catch (Exception $e) { $msg = "Error adding user: Email might be taken."; }
    }

    // DELETE USER
    if (isset($_POST['delete_user']) && ($_SESSION['user_type'] ?? '') === 'admin') {
        $pdo->prepare("DELETE FROM users WHERE id = ? AND org_id = ?")->execute([$_POST['delete_user_id'], $current_org_id]);
        $msg = "User deleted successfully.";
    }

    // 9. ADD/UPDATE TIMESLOTS
    if (isset($_POST['add_slot']) || isset($_POST['update_slot'])) {
        $day = $_POST['day'];
        $start = $_POST['start'];
        $end = $_POST['end'];
        $capacity = (int)$_POST['capacity'];
        $doctor_id = !empty($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : null;
        $id = $_POST['slot_id'] ?? null;

        if ($id && isset($_POST['update_slot'])) {
            $stmt = $pdo->prepare("UPDATE agent_timeslots SET day_of_week = ?, start_time = ?, end_time = ?, capacity = ?, doctor_id = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$day, $start, $end, $capacity, $doctor_id, $id, $current_org_id]);
            $msg = "Timeslot updated successfully.";
        } else {
            $days_to_add = ($day === 'Everyday') ? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] : [$day];
            $stmt = $pdo->prepare("INSERT INTO agent_timeslots (org_id, day_of_week, start_time, end_time, capacity, doctor_id) VALUES (?, ?, ?, ?, ?, ?)");
            $check = $pdo->prepare("SELECT id FROM agent_timeslots WHERE org_id = ? AND day_of_week = ? AND start_time = ? AND end_time = ? AND (doctor_id = ? OR (doctor_id IS NULL AND ? IS NULL)) LIMIT 1");
            
            $added = 0;
            foreach ($days_to_add as $d) {
                $check->execute([$current_org_id, $d, $start, $end, $doctor_id, $doctor_id]);
                if ($check->fetch()) continue;
                
                $stmt->execute([$current_org_id, $d, $start, $end, $capacity, $doctor_id]);
                $added++;
            }
            $msg = $added > 0 ? "Timeslot(s) added successfully." : "No new slots added (duplicates ignored).";
        }
    }
    if (isset($_POST['del_slot'])) {
        $pdo->prepare("DELETE FROM agent_timeslots WHERE id = ? AND org_id = ?")->execute([$_POST['id'], $current_org_id]);
        $msg = "Working hours removed.";
    }
    
    header("Location: settings.php?msg=" . urlencode($msg)); 
    exit;
}

require_once 'header.php';

if (isset($_GET['msg'])) $msg = $_GET['msg'];

$org = $pdo->prepare("SELECT api_key, gupshup_userid, gupshup_password, gupshup_app_id, gupshup_api_key, reminder_minutes, timezone, slot_duration_minutes, event_type, event_address, org_logo FROM organizations WHERE id = ?");
$org->execute([$current_org_id]);
$org_data = $org->fetch();
$has_pass = !empty($org_data['gupshup_password']);

$users_stmt = $pdo->prepare("SELECT * FROM users WHERE org_id = ?");
$users_stmt->execute([$current_org_id]);
$org_users = $users_stmt->fetchAll();

$templates = $pdo->prepare("SELECT * FROM wa_templates WHERE org_id = ?");
$templates->execute([$current_org_id]);
$cached_templates = $templates->fetchAll();

$available_variables = [
    'lead_name' => 'Patient / Lead Name',
    'start_time' => 'Appointment Time',
    'meet_link' => 'Meeting Link'
];

$analytics = $pdo->prepare("
    SELECT c.campaign_name, 
           COUNT(DISTINCT lc.lead_id) as leads, 
           COUNT(DISTINCT a.id) as appts 
    FROM campaigns c 
    LEFT JOIN lead_campaigns lc ON c.id = lc.campaign_id 
    LEFT JOIN appointments a ON lc.lead_id = a.lead_id AND a.org_id = c.org_id 
    WHERE c.org_id = ? 
    GROUP BY c.id ORDER BY leads DESC
");
$analytics->execute([$current_org_id]);

$doctors_stmt = $pdo->prepare("SELECT * FROM doctors WHERE org_id = ? ORDER BY name ASC");
$doctors_stmt->execute([$current_org_id]);
$doctors = $doctors_stmt->fetchAll();

// Auto-populate Generic Doctor if none exist
if (empty($doctors)) {
    $pdo->prepare("INSERT INTO doctors (org_id, name, specialization) VALUES (?, ?, ?)")
        ->execute([$current_org_id, 'Generic Doctor', 'General Practice']);
    // Re-fetch
    $doctors_stmt->execute([$current_org_id]);
    $doctors = $doctors_stmt->fetchAll();
}

$slots_stmt = $pdo->prepare("SELECT s.*, d.name as doctor_name FROM agent_timeslots s LEFT JOIN doctors d ON s.doctor_id = d.id WHERE s.org_id = ? ORDER BY d.name, FIELD(s.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), s.start_time");
$slots_stmt->execute([$current_org_id]);
$all_slots = $slots_stmt->fetchAll();
?>

<h1 style="margin-bottom: 20px;">Settings & API Integrations</h1>

<?php if ($msg): ?>
    <div style="background: #D1FAE5; color: #047857; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border: 1px solid #34D399;">
        <?php echo htmlspecialchars($msg); ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-bottom: 20px;">
    
    <div style="display: flex; flex-direction: column; gap: 20px;">
        <!-- Logo & Branding Card -->
        <div class="card" style="border-left: 4px solid #6366F1; margin-bottom: 0;">
            <h3 style="margin-top: 0;">Organization Brand (Logo)</h3>
            <div style="display: flex; flex-direction: column; align-items: center; gap: 15px;">
                <div style="width: 120px; height: 120px; border: 2px dashed var(--border); border-radius: 12px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #F9FAFB;">
                    <?php if (!empty($org_data['org_logo'])): ?>
                        <img src="uploads/<?php echo htmlspecialchars($org_data['org_logo']); ?>" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                    <?php else: ?>
                        <span style="color: var(--text-muted); font-size: 12px;">No Logo</span>
                    <?php endif; ?>
                </div>
                <form method="POST" enctype="multipart/form-data" style="width: 100%;">
                    <label style="background: var(--primary); color: white; padding: 12px; border-radius: 8px; cursor: pointer; display: block; text-align: center; font-weight: bold; font-size: 15px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                        Upload New Logo
                        <input type="file" name="org_logo" style="display: none;" onchange="this.form.submit()">
                    </label>
                </form>
                <p style="font-size: 12px; color: var(--text-muted);">Recommended: Square PNG or JPG.</p>
            </div>
        </div>

        <div class="card" style="border-left: 4px solid var(--primary); margin-bottom: 0;">
            <h3 style="margin-top: 0;">Your API Key</h3>
            <p style="font-size: 14px; color: var(--text-muted);">Use this key to authenticate external API requests.</p>
            <p style="background: #F9FAFB; padding: 10px; border: 1px solid var(--border); border-radius: 4px; font-family: monospace; word-break: break-all;">
                <?php echo htmlspecialchars($org_data['api_key']); ?>
            </p>
            <form method="POST" onsubmit="return confirm('Warning: This will break existing API integrations. Continue?');">
                <button name="gen_api" type="submit" style="background: #1F2937; color: white; padding: 8px 12px; border: none; border-radius: 4px; cursor: pointer; width: 100%; font-weight: bold;">Generate New Key</button>
            </form>
        </div>

        <div class="card" style="border-left: 4px solid #10B981; margin-bottom: 0;">
            <h3 style="margin-top: 0;">WhatsApp (Gupshup) Config</h3>
            <form method="POST" style="margin-bottom: 15px;">
                <label style="font-size: 13px; font-weight: bold;">UserID</label>
                <input type="text" name="gupshup_userid" value="<?php echo htmlspecialchars($org_data['gupshup_userid'] ?? ''); ?>" style="width: 100%; padding: 10px; margin-bottom:10px; border: 1px solid var(--border); border-radius: 4px; box-sizing: border-box;" required>
                
                <label style="font-size: 13px; font-weight: bold;">Password</label>
                <input type="password" name="gupshup_password" value="<?php echo $has_pass ? '********' : ''; ?>" style="width: 100%; padding: 10px; margin-bottom:15px; border: 1px solid var(--border); border-radius: 4px; box-sizing: border-box;" required>
                
                <hr style="border: 0; border-top: 1px solid var(--border); margin: 5px 0 15px 0;">

                <label style="font-size: 13px; font-weight: bold;">Transcript Project ID</label>
                <input type="text" name="gupshup_app_id" placeholder="e.g. 31569577" value="<?php echo htmlspecialchars($org_data['gupshup_app_id'] ?? ''); ?>" style="width: 100%; padding: 10px; margin-bottom:10px; border: 1px solid var(--border); border-radius: 4px; box-sizing: border-box;">
                
                <label style="font-size: 13px; font-weight: bold;">Transcript API Key</label>
                <input type="text" name="gupshup_api_key" placeholder="e.g. 3f9eb6dcb..." value="<?php echo htmlspecialchars($org_data['gupshup_api_key'] ?? ''); ?>" style="width: 100%; padding: 10px; margin-bottom:15px; border: 1px solid var(--border); border-radius: 4px; box-sizing: border-box;">

                <button name="save_gupshup" type="submit" style="background: #10B981; color: white; padding: 10px; border: none; border-radius: 4px; cursor: pointer; width: 100%; font-weight: bold;">Save Credentials</button>
            </form>
            
            <hr style="border: 0; border-top: 1px solid var(--border); margin: 15px 0;">
            
            <form method="POST">
                <button name="sync_templates" type="submit" style="background: var(--primary); color: white; padding: 10px; border: none; border-radius: 4px; cursor: pointer; width: 100%; font-weight: bold;">Fetch / Sync Templates</button>
            </form>
            <p style="font-size: 12px; color: var(--text-muted); text-align: center; margin-top: 10px;">Pulls STATIC/TEXT templates from your account.</p>
        </div>
    </div>

    <div class="card" style="margin-bottom: 0;">
        <h2 style="margin-top: 0;">Map Event Templates</h2>
        
        <form method="POST" style="margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px solid var(--border);">
            <label style="font-size: 14px; font-weight:bold; display:block; margin-bottom:5px;">Send Automated Reminders</label>
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="number" name="reminder_minutes" value="<?php echo htmlspecialchars($org_data['reminder_minutes'] ?? 60); ?>" style="width: 80px; padding: 8px; border-radius: 4px; border: 1px solid var(--border);">
                <span style="font-size: 14px; color: var(--text-muted);">minutes before the appointment.</span>
                <button type="submit" name="save_reminder_time" style="background: var(--primary); color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: auto;">Save Time</button>
            </div>
        </form>

        <form method="POST" style="margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px solid var(--border);">
            <h3 style="margin-top: 0; margin-bottom: 15px;">Appointment Settings</h3>
            
            <label style="font-size: 14px; font-weight:bold; display:block; margin-bottom:5px;">Organization Timezone</label>
            <select name="timezone" style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid var(--border); margin-bottom: 15px;">
                <option value="Asia/Kolkata" <?php echo ($org_data['timezone'] ?? 'Asia/Kolkata') == 'Asia/Kolkata' ? 'selected' : ''; ?>>Asia/Kolkata</option>
                <option value="America/New_York" <?php echo ($org_data['timezone'] ?? '') == 'America/New_York' ? 'selected' : ''; ?>>America/New_York</option>
                <option value="Europe/London" <?php echo ($org_data['timezone'] ?? '') == 'Europe/London' ? 'selected' : ''; ?>>Europe/London</option>
                <option value="America/Sao_Paulo" <?php echo ($org_data['timezone'] ?? '') == 'America/Sao_Paulo' ? 'selected' : ''; ?>>America/Sao_Paulo (Brazil)</option>
                <option value="UTC" <?php echo ($org_data['timezone'] ?? '') == 'UTC' ? 'selected' : ''; ?>>UTC</option>
            </select>
            
            <label style="font-size: 14px; font-weight:bold; display:block; margin-bottom:5px;">Slot Duration (Minutes)</label>
            <input type="number" name="slot_duration_minutes" value="<?php echo htmlspecialchars($org_data['slot_duration_minutes'] ?? 30); ?>" min="5" step="5" style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid var(--border); margin-bottom: 15px;">
            
            <label style="font-size: 14px; font-weight:bold; display:block; margin-bottom:5px;">Event Type</label>
            <div style="margin-bottom: 15px;">
                <label style="margin-right: 15px;">
                    <input type="radio" name="event_type" value="google_meet" <?php echo ($org_data['event_type'] ?? 'google_meet') == 'google_meet' ? 'checked' : ''; ?> onclick="document.getElementById('in_person_address').style.display='none'"> Google Meet
                </label>
                <label>
                    <input type="radio" name="event_type" value="in_person" <?php echo ($org_data['event_type'] ?? '') == 'in_person' ? 'checked' : ''; ?> onclick="document.getElementById('in_person_address').style.display='block'"> In-Person
                </label>
            </div>
            
            <div id="in_person_address" style="display: <?php echo ($org_data['event_type'] ?? '') == 'in_person' ? 'block' : 'none'; ?>; margin-bottom: 15px;">
                <label style="font-size: 14px; font-weight:bold; display:block; margin-bottom:5px;">In-Person Address</label>
                <textarea name="event_address" rows="3" style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid var(--border);"><?php echo htmlspecialchars($org_data['event_address'] ?? ''); ?></textarea>
            </div>

            <button type="submit" name="save_appt_settings" style="background: var(--primary); color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; width: 100%;">Save Appointment Settings</button>
        </form>

        <div style="background: #F0F9FF; border: 1px solid #BAE6FD; padding: 15px; border-radius: 8px; margin-top: 10px;">
            <h4 style="margin-top: 0; color: #0369A1; font-size: 15px;">🌐 Your Public Booking Page</h4>
            <p style="font-size: 13px; color: #0C4A6E; margin-bottom: 10px;">Share this link with your patients for them to book appointments online.</p>
            <div style="display: flex; gap: 10px;">
                <input type="text" readonly value="http://<?php echo $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); ?>/book.php?org_id=<?php echo $current_org_id; ?>" style="flex: 1; padding: 8px; border: 1px solid #BAE6FD; border-radius: 4px; font-size: 13px; background: #fff;">
                <a href="book.php?org_id=<?php echo $current_org_id; ?>" target="_blank" style="background: #0369A1; color: white; text-decoration: none; padding: 8px 15px; border-radius: 4px; font-weight: bold; font-size: 13px;">Open Page</a>
            </div>
        </div>

        <?php if (empty($cached_templates)): ?>
            <div style="background: #FEF3C7; color: #D97706; padding: 15px; border-radius: 6px; border: 1px solid #FCD34D;">
                No templates found. Please enter your Gupshup credentials and click "Fetch / Sync Templates".
            </div>
        <?php else: ?>
            
            <?php 
            $events_to_map = ['cancel' => 'Cancel Event', 'reschedule' => 'Reschedule Event', 'reminder' => 'Appointment Reminder'];
            foreach ($events_to_map as $event_key => $event_label): 
                $stmt = $pdo->prepare("SELECT template_id, var_mapping FROM wa_event_mappings WHERE org_id = ? AND event_type = ?");
                $stmt->execute([$current_org_id, $event_key]);
                $current_map = $stmt->fetch();
                $saved_var_map = $current_map ? json_decode($current_map['var_mapping'], true) : [];
                $saved_template_id = $current_map['template_id'] ?? '';
            ?>
            <div style="background: #F9FAFB; padding: 20px; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 20px;">
                <h3 style="margin-top: 0; margin-bottom: 15px;"><?php echo $event_label; ?></h3>
                <form method="POST">
                    <input type="hidden" name="event_type" value="<?php echo $event_key; ?>">
                    
                    <label style="font-size: 14px; font-weight:bold; display:block; margin-bottom:5px;">Select Template</label>
                    <select name="template_id" class="template-selector" data-target="body_display_<?php echo $event_key; ?>" data-vars="vars_container_<?php echo $event_key; ?>" style="width: 100%; padding: 10px; margin-bottom: 15px; border-radius: 4px; border: 1px solid var(--border);">
                        <option value="">-- None Selected --</option>
                        <?php foreach ($cached_templates as $t): ?>
                            <option value="<?php echo $t['template_id']; ?>" <?php if($saved_template_id == $t['template_id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($t['name']); ?> (<?php echo $t['var_count']; ?> vars)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <div id="body_display_<?php echo $event_key; ?>" style="background: white; padding: 12px; border-radius: 4px; border: 1px solid #E5E7EB; margin-bottom: 15px; font-size: 14px; white-space: pre-wrap; display: none; color: #4B5563;"></div>
                    
                    <div id="vars_container_<?php echo $event_key; ?>" style="padding: 15px; border-left: 3px solid var(--primary); background: white; margin-bottom: 15px; display: none; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <p style="margin-top: 0; font-size: 14px; font-weight: bold;">Map Variables to Template:</p>
                        <div class="vars-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;"></div>
                    </div>

                    <script>window['savedMap_<?php echo $event_key; ?>'] = <?php echo json_encode($saved_var_map); ?>;</script>

                    <button type="submit" name="save_mapping" style="background: var(--text-main); color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; width: 100%;">Save <?php echo $event_label; ?> Mapping</button>
                </form>
            </div>
            <?php endforeach; ?>

            <script>
                const templatesData = <?php echo json_encode($cached_templates); ?>;
                const availableVariables = <?php echo json_encode($available_variables); ?>;

                document.querySelectorAll('.template-selector').forEach(selector => {
                    selector.addEventListener('change', function() { updateTemplateUI(this); });
                    if(selector.value !== "") { updateTemplateUI(selector, true); }
                });

                function updateTemplateUI(selectElement, isInitialLoad = false) {
                    const templateId = selectElement.value;
                    const bodyDisplay = document.getElementById(selectElement.getAttribute('data-target'));
                    const varsContainer = document.getElementById(selectElement.getAttribute('data-vars'));
                    const varsGrid = varsContainer.querySelector('.vars-grid');
                    
                    const eventKey = selectElement.closest('form').querySelector('input[name="event_type"]').value;
                    const savedMap = window['savedMap_' + eventKey] || {};

                    if (!templateId) {
                        bodyDisplay.style.display = 'none';
                        varsContainer.style.display = 'none';
                        return;
                    }

                    const template = templatesData.find(t => t.template_id === templateId);
                    if (template) {
                        bodyDisplay.innerHTML = "<strong style='color:var(--text-main);'>Template Preview:</strong><br><br>" + template.body;
                        bodyDisplay.style.display = 'block';

                        varsGrid.innerHTML = ''; 
                        if (template.var_count > 0) {
                            for (let i = 1; i <= template.var_count; i++) {
                                let div = document.createElement('div');
                                let label = document.createElement('label');
                                label.style = "font-size: 13px; font-weight:bold; color: var(--text-muted); display:block; margin-bottom:5px;";
                                label.innerText = "{{" + i + "}} maps to:";
                                let select = document.createElement('select');
                                select.name = "var_" + i;
                                select.style = "width: 100%; padding: 8px; border: 1px solid #E5E7EB; border-radius: 4px;";
                                
                                for (const [val, text] of Object.entries(availableVariables)) {
                                    let option = document.createElement('option');
                                    option.value = val; option.text = text;
                                    if (isInitialLoad && savedMap[i] === val) option.selected = true;
                                    select.appendChild(option);
                                }
                                div.appendChild(label); div.appendChild(select); varsGrid.appendChild(div);
                            }
                            varsContainer.style.display = 'block';
                        } else {
                            varsContainer.style.display = 'none';
                        }
                    }
                }
            </script>
        <?php endif; ?>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
    
    <div class="card" style="margin-bottom: 0;">
        <h3 style="margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid var(--border);">Campaign Analytics</h3>
        <table>
            <tr><th>Campaign Name</th><th>Total Leads</th><th>Bookings</th></tr>
            <?php foreach($analytics->fetchAll() as $row): ?>
            <tr>
                <td><a href="campaign_details.php?name=<?php echo urlencode($row['campaign_name']); ?>" style="color: var(--primary); font-weight: bold; text-decoration: none;"><?php echo htmlspecialchars($row['campaign_name']); ?></a></td>
                <td><?php echo $row['leads']; ?></td>
                <td><?php echo $row['appts']; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card" style="margin-bottom: 0;">
        <h3 style="margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid var(--border);">Working Hours</h3>
        <form method="POST" id="slotForm" style="margin-bottom:20px; background: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid var(--border);">
            <input type="hidden" name="slot_id" id="slot_id">
            <div style="display: flex; flex-direction: column; gap: 15px;">
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <label style="width: 80px; font-size:13px; font-weight:bold;">Doctor:</label>
                    <select name="doctor_id" id="slot_doctor_id" style="padding: 10px; flex: 1; border: 1px solid var(--border); border-radius:4px; min-width: 150px;">
                        <option value="">-- General / No Doctor --</option>
                        <?php foreach($doctors as $d): ?>
                            <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <label style="width: 80px; font-size:13px; font-weight:bold;">Day(s):</label>
                    <select name="day" id="slot_day" style="padding: 10px; flex: 1; border: 1px solid var(--border); border-radius:4px; min-width: 150px;">
                        <option value="Everyday">Everyday (Mon - Sun)</option>
                        <option value="Monday">Monday</option>
                        <option value="Tuesday">Tuesday</option>
                        <option value="Wednesday">Wednesday</option>
                        <option value="Thursday">Thursday</option>
                        <option value="Friday">Friday</option>
                        <option value="Saturday">Saturday</option>
                        <option value="Sunday">Sunday</option>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <label style="width: 80px; font-size:13px; font-weight:bold;">Work Hrs:</label>
                    <input type="time" name="start" id="slot_start" required style="padding: 10px; flex: 1; border: 1px solid var(--border); border-radius:4px; min-width: 100px;" title="Start Time"> 
                    <span style="font-size:12px; color:var(--text-muted);">to</span>
                    <input type="time" name="end" id="slot_end" required style="padding: 10px; flex: 1; border: 1px solid var(--border); border-radius:4px; min-width: 100px;" title="End Time">
                </div>
                <!-- Break logic remains hidden/legacy for now -->
                <div style="display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label style="font-size: 13px; font-weight: bold;">Concurrent Capacity:</label>
                        <input type="number" name="capacity" id="slot_capacity" value="1" min="1" required style="width: 80px; padding: 10px; border: 1px solid var(--border); border-radius:4px;">
                    </div>
                    <div>
                        <button type="button" id="cancelEdit" style="display:none; background:#94A3B8; color:white; border:none; padding:10px 15px; border-radius:4px; cursor:pointer; font-weight:bold; margin-right:5px;" onclick="resetSlotForm()">Cancel</button>
                        <button name="add_slot" id="slotSubmitBtn" type="submit" style="background: var(--primary); color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">+ Add Schedule</button>
                    </div>
                </div>
            </div>
        </form>
        
        <script>
        function editSlot(id, day, start, end, capacity, doctor_id) {
            document.getElementById('slot_id').value = id;
            document.getElementById('slot_day').value = day;
            document.getElementById('slot_start').value = start.substring(0,5);
            document.getElementById('slot_end').value = end.substring(0,5);
            document.getElementById('slot_capacity').value = capacity;
            document.getElementById('slot_doctor_id').value = doctor_id || "";
            
            document.getElementById('slotSubmitBtn').name = 'update_slot';
            document.getElementById('slotSubmitBtn').innerHTML = 'Update Schedule';
            document.getElementById('slotSubmitBtn').style.background = '#F59E0B';
            document.getElementById('cancelEdit').style.display = 'inline-block';
            
            document.getElementById('slotForm').scrollIntoView({behavior: 'smooth'});
        }
        
        function resetSlotForm() {
            document.getElementById('slot_id').value = '';
            document.getElementById('slotSubmitBtn').name = 'add_slot';
            document.getElementById('slotSubmitBtn').innerHTML = '+ Add Schedule';
            document.getElementById('slotSubmitBtn').style.background = 'var(--primary)';
            document.getElementById('cancelEdit').style.display = 'none';
            document.getElementById('slotForm').reset();
        }
        </script>
        
        <div style="display: flex; flex-direction: column; gap: 10px; max-height: 400px; overflow-y: auto; padding-right: 5px;">
            <?php 
            if (empty($all_slots)): ?>
                <p style="color: var(--text-muted); font-size: 14px; text-align: center; padding: 20px;">No working hours set.</p>
            <?php else: 
                $grouped = [];
                foreach($all_slots as $s) {
                    $doc_name = $s['doctor_name'] ?? 'General / Unassigned';
                    $grouped[$doc_name][$s['day_of_week']][] = $s;
                }
                foreach ($grouped as $doc_name => $days):
            ?>
                <div style="margin-bottom: 25px;">
                    <h4 style="margin: 0 0 10px 0; color: var(--primary); font-size: 16px;">👨‍⚕️ <?php echo htmlspecialchars($doc_name); ?></h4>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach($days as $day => $day_slots): ?>
                            <div style="border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
                                <div style="background: #F3F4F6; padding: 10px 15px; font-weight: bold; font-size: 14px; color: #374151; border-bottom: 1px solid var(--border);">
                                    <?php echo htmlspecialchars($day); ?>
                                </div>
                                <div style="background: #FFFFFF; padding: 10px 15px;">
                                    <?php foreach($day_slots as $s): ?>
                                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px dashed #E5E7EB;">
                                            <div style="display: flex; align-items: center; gap: 15px;">
                                                <span style="font-size: 14px; color: var(--text-main); font-weight: 500;">
                                                    🕒 <?php echo date('g:i A', strtotime($s['start_time'])) . ' - ' . date('g:i A', strtotime($s['end_time'])); ?>
                                                </span>
                                                <span style="background: #E0E7FF; color: #4338CA; font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: bold;">
                                                    Capacity: <?php echo $s['capacity']; ?>
                                                </span>
                                            </div>
                                            <div style="display: flex; gap: 5px;">
                                                <button type="button" onclick="editSlot(<?php echo $s['id']; ?>, '<?php echo $s['day_of_week']; ?>', '<?php echo $s['start_time']; ?>', '<?php echo $s['end_time']; ?>', <?php echo $s['capacity']; ?>, <?php echo $s['doctor_id'] ?: 'null'; ?>)" style="background:#DBEAFE; color:#2563EB; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold;">Edit</button>
                                                <form method="POST" style="margin: 0;" onsubmit="return confirm('Delete this schedule?')">
                                                    <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                                    <button name="del_slot" style="background:#FEE2E2; color:#DC2626; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; transition: background 0.2s;" onmouseover="this.style.background='#FCA5A5'" onmouseout="this.style.background='#FEE2E2'">Remove</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<script>
function copyCurl(id, btn) {
    var copyText = document.getElementById(id).innerText;
    navigator.clipboard.writeText(copyText).then(function() {
        var originalText = btn.innerHTML;
        btn.innerHTML = '✅ Copied!';
        btn.style.background = '#10B981';
        setTimeout(function() {
            btn.innerHTML = originalText;
            btn.style.background = '#3B82F6';
        }, 2000);
    });
}
</script>
<div class="card" style="margin-top: 30px; border-left: 4px solid #3B82F6;">
    <h2 style="margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 15px;">📖 API Assistance & Documentation</h2>
    <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">Use the following ready-to-use cURL commands to integrate with your external tools. Your <b>API Key</b> and <b>Project ID</b> have automatically been populated below.</p>

    <div style="display: flex; flex-direction: column; gap: 15px;">
        
        <!-- Create Lead -->
        <div style="background: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid var(--border); position: relative;">
            <button onclick="copyCurl('curl1', this)" style="position: absolute; right: 15px; top: 15px; background: #3B82F6; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; transition: background 0.3s;">📋 Copy cURL</button>
            <h4 style="margin-top: 0; color: #111827;">1. Create Lead</h4>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 10px;">Endpoint: <code>POST /api.php</code> | Action: <code>create_lead</code></p>
            <pre style="background: #1F2937; color: #F3F4F6; padding: 15px; border-radius: 6px; font-size: 12px; overflow-x: auto; margin: 0;"><code id="curl1">curl -X POST "http://<?php echo $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); ?>/api.php" \
  -H "Content-Type: application/json" \
  -d '{
    "api_key": "<?php echo htmlspecialchars($org_data['api_key']); ?>",
    "project_id": "<?php echo htmlspecialchars($_SESSION['org_name']); ?>",
    "action": "create_lead",
    "name": "Jane Doe",
    "mobile": "9876543210",
    "campaign_name": "Website Form",
    "cpf_number": "123.456.789-00",
    "doctor_type": "Orthopedics",
    "patient_type": "New Patient",
    "branch": "Downtown Clinic",
    "patient_name": "Jane Doe",
    "visit_reason": "Severe back pain"
  }'</code></pre>
        </div>

        <!-- Check Available Slots -->
        <div style="background: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid var(--border); position: relative;">
            <button onclick="copyCurl('curl2', this)" style="position: absolute; right: 15px; top: 15px; background: #3B82F6; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; transition: background 0.3s;">📋 Copy cURL</button>
            <h4 style="margin-top: 0; color: #111827;">2. Check Available Slots</h4>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 10px;">Endpoint: <code>POST /api.php</code> | Action: <code>check_slots</code></p>
            <pre style="background: #1F2937; color: #F3F4F6; padding: 15px; border-radius: 6px; font-size: 12px; overflow-x: auto; margin: 0;"><code id="curl2">curl -X POST "http://<?php echo $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); ?>/api.php" \
  -H "Content-Type: application/json" \
  -d '{
    "api_key": "<?php echo htmlspecialchars($org_data['api_key']); ?>",
    "project_id": "<?php echo htmlspecialchars($_SESSION['org_name']); ?>",
    "action": "check_slots",
    "date": "<?php echo date('Y-m-d'); ?>"
  }'</code></pre>
        </div>

        <!-- Check Specific Slot -->
        <div style="background: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid var(--border); position: relative;">
            <button onclick="copyCurl('curl3', this)" style="position: absolute; right: 15px; top: 15px; background: #3B82F6; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; transition: background 0.3s;">📋 Copy cURL</button>
            <h4 style="margin-top: 0; color: #111827;">3. Check Specific Slot</h4>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 10px;">Endpoint: <code>POST /api.php</code> | Action: <code>check_specific_slot</code></p>
            <pre style="background: #1F2937; color: #F3F4F6; padding: 15px; border-radius: 6px; font-size: 12px; overflow-x: auto; margin: 0;"><code id="curl3">curl -X POST "http://<?php echo $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); ?>/api.php" \
  -H "Content-Type: application/json" \
  -d '{
    "api_key": "<?php echo htmlspecialchars($org_data['api_key']); ?>",
    "project_id": "<?php echo htmlspecialchars($_SESSION['org_name']); ?>",
    "action": "check_specific_slot",
    "datetime": "<?php echo date('Y-m-d 10:00:00'); ?>"
  }'</code></pre>
        </div>

        <!-- Book Appointment -->
        <div style="background: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid var(--border); position: relative;">
            <button onclick="copyCurl('curl4', this)" style="position: absolute; right: 15px; top: 15px; background: #3B82F6; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; transition: background 0.3s;">📋 Copy cURL</button>
            <h4 style="margin-top: 0; color: #111827;">4. Book Appointment</h4>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 10px;">Endpoint: <code>POST /api.php</code> | Action: <code>book_appointment</code></p>
            <pre style="background: #1F2937; color: #F3F4F6; padding: 15px; border-radius: 6px; font-size: 12px; overflow-x: auto; margin: 0;"><code id="curl4">curl -X POST "http://<?php echo $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); ?>/api.php" \
  -H "Content-Type: application/json" \
  -d '{
    "api_key": "<?php echo htmlspecialchars($org_data['api_key']); ?>",
    "project_id": "<?php echo htmlspecialchars($_SESSION['org_name'] ?? ''); ?>",
    "action": "book_appointment",
    "mobile": "9876543210",
    "start_time": "<?php echo date('Y-m-d 09:00:00'); ?>",
    "end_time": "<?php echo date('Y-m-d 10:00:00'); ?>",
    "doctor_id": 1
  }'</code></pre>
        </div>

        <!-- Check Booking Status by Doctor -->
        <div style="background: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid var(--border); position: relative;">
            <button onclick="copyCurl('curl5', this)" style="position: absolute; right: 15px; top: 15px; background: #3B82F6; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; transition: background 0.3s;">📋 Copy cURL</button>
            <h4 style="margin-top: 0; color: #111827;">5. Check Bookings by Doctor Name</h4>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 10px;">Endpoint: <code>POST /api.php</code> | Action: <code>get_doctor_bookings</code></p>
            <pre style="background: #1F2937; color: #F3F4F6; padding: 15px; border-radius: 6px; font-size: 12px; overflow-x: auto; margin: 0;"><code id="curl5">curl -X POST "http://<?php echo $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); ?>/api.php" \
  -H "Content-Type: application/json" \
  -d '{
    "api_key": "<?php echo htmlspecialchars($org_data['api_key']); ?>",
    "project_id": "<?php echo htmlspecialchars($_SESSION['org_name'] ?? ''); ?>",
    "action": "get_doctor_bookings",
    "doctor_name": "Generic Doctor"
  }'</code></pre>
        </div>

    </div>
</div>

<div class="card" style="margin-top: 30px; border-left: 4px solid #F59E0B;">
    <h2 style="margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 15px;">🏥 Doctor Management</h2>
    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-top: 20px;">
        <div style="background: #FFFBEB; padding: 15px; border-radius: 6px; border: 1px solid #FEF3C7;">
            <h3 style="margin-top: 0;">Add New Doctor</h3>
            <form method="POST">
                <input type="text" name="doctor_name" placeholder="Doctor's Full Name" required style="width: 100%; padding: 10px; margin-bottom: 10px; border-radius: 4px; border: 1px solid var(--border);">
                <input type="text" name="doctor_specialization" placeholder="Specialization (e.g. Cardiologist)" style="width: 100%; padding: 10px; margin-bottom: 10px; border-radius: 4px; border: 1px solid var(--border);">
                <button type="submit" name="add_doctor" style="background: #F59E0B; color: white; border: none; padding: 10px; border-radius: 4px; width: 100%; cursor: pointer; font-weight: bold;">+ Add Doctor</button>
            </form>
        </div>
        <div>
            <h3 style="margin-top: 0;">Existing Doctors</h3>
            <table style="width: 100%; font-size: 14px;">
                <thead>
                    <tr><th style="text-align:left;">Name</th><th style="text-align:left;">Specialization</th><th style="text-align:right;">Action</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($doctors)): ?>
                        <tr><td colspan="3" style="color:var(--text-muted); padding: 10px 0; text-align: center;">No doctors configured.</td></tr>
                    <?php else: ?>
                        <?php foreach($doctors as $d): ?>
                        <tr>
                            <td style="padding: 10px 0; border-bottom: 1px solid #F3F4F6; font-weight: 500;"><?php echo htmlspecialchars($d['name']); ?></td>
                            <td style="padding: 10px 0; border-bottom: 1px solid #F3F4F6; color: var(--text-muted);"><?php echo htmlspecialchars($d['specialization'] ?? '-'); ?></td>
                            <td style="padding: 10px 0; border-bottom: 1px solid #F3F4F6; text-align:right;">
                                <form method="POST" onsubmit="return confirm('Delete this doctor? This will NOT delete existing slots but they will become unassigned.');">
                                    <input type="hidden" name="doctor_id" value="<?php echo $d['id']; ?>">
                                    <button name="delete_doctor" style="background:#EF4444; color:white; border:none; padding:4px 8px; border-radius:4px; cursor:pointer; font-size: 12px;">Remove</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 30px;">
    <h2 style="margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 15px;">🔗 CURL Webhook Integration</h2>
    
    <form method="POST" style="margin-bottom: 20px;">
        <p style="color: var(--text-muted); font-size: 13px;">Configure webhooks to forward API events to your external services</p>
        
        <label style="font-weight: bold; margin-top: 15px; display: block;">CURL Endpoint URL</label>
        <input type="url" name="curl_endpoint" placeholder="https://your-api.com/webhook" style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 4px; margin-bottom: 15px; box-sizing: border-box;">
        
        <label style="font-weight: bold; display: block;">Webhook Event</label>
        <select name="webhook_event" style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 4px; margin-bottom: 15px; box-sizing: border-box;">
            <option value="lead_created">Lead Created</option>
            <option value="appointment_booked">Appointment Booked</option>
            <option value="lead_converted">Lead Converted</option>
            <option value="message_sent">Message Sent</option>
        </select>
        
        <label style="font-weight: bold; display: block;">Variable Mapping (JSON)</label>
        <textarea id="variable_mapping" name="variable_mapping" placeholder='{"external_key": "available_variable_key"}' style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 4px; margin-bottom: 8px; box-sizing: border-box; font-family: monospace; font-size: 12px; height: 80px;"></textarea>
        <div id="curlVarBuilder" style="background:#F9FAFB; border:1px dashed var(--border); border-radius:6px; padding:10px; margin-bottom:15px; font-size:12px;">
            <div style="display:flex; gap:8px; margin-bottom:8px; align-items:center; flex-wrap:wrap;">
                <span style="font-weight:600;">Add variable:</span>
                <input type="text" id="curl_external_key" placeholder="external field name (e.g. lead_name)" style="flex:1; min-width:140px; padding:6px 8px; border:1px solid var(--border); border-radius:4px;">
                <select id="curl_available_key" style="min-width:160px; padding:6px 8px; border-radius:4px; border:1px solid var(--border);">
                    <option value="">Select available value…</option>
                    <option value="lead_id">lead_id</option>
                    <option value="lead_name">lead_name</option>
                    <option value="mobile">mobile</option>
                    <option value="email">email</option>
                    <option value="status">status</option>
                    <option value="campaign_name">campaign_name</option>
                    <option value="source">source</option>
                    <option value="created_at">created_at</option>
                    <option value="appointment_id">appointment_id</option>
                    <option value="start_time">start_time</option>
                    <option value="end_time">end_time</option>
                    <option value="meet_link">meet_link</option>
                </select>
                <button type="button" id="curl_add_var_btn" style="background:var(--primary); color:#fff; border:none; border-radius:4px; padding:6px 10px; font-weight:600; cursor:pointer;">Add</button>
            </div>
            <div style="color:var(--text-muted);">
                Mapping format: <code>{"your_key":"available_variable_key"}</code>. If left empty, the full payload is sent.
            </div>
        </div>
        
        <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 15px;">
            <input type="checkbox" name="is_active" value="1" checked>
            <span style="font-weight: 600;">Active</span>
        </label>
        
        <button type="submit" name="save_curl" style="background: var(--primary); color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Save Webhook</button>
    </form>
    
    <hr style="border: none; border-top: 1px solid var(--border); margin: 20px 0;">
    
    <h3 style="margin-top: 15px;">Configured Webhooks</h3>
    
    <?php
    $stmt = $pdo->prepare("SELECT * FROM curl_configs WHERE org_id = ? ORDER BY created_at DESC");
    $stmt->execute([$current_org_id]);
    $curl_configs = $stmt->fetchAll();
    ?>
    
    <?php if (!empty($curl_configs)): ?>
        <table>
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Endpoint</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($curl_configs as $config): ?>
                <tr>
                    <td><?php echo htmlspecialchars($config['webhook_event']); ?></td>
                    <td style="font-size: 12px; color: var(--text-muted);">
                        <?php echo substr(htmlspecialchars($config['curl_endpoint']), 0, 50) . '...'; ?>
                    </td>
                    <td>
                        <span style="background: <?php echo $config['is_active'] ? '#D1FAE5' : '#FEE2E2'; ?>; color: <?php echo $config['is_active'] ? '#047857' : '#B91C1C'; ?>; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold;">
                            <?php echo $config['is_active'] ? '✓ Active' : '✗ Inactive'; ?>
                        </span>
                    </td>
                    <td>
                        <form method="POST" style="display: inline; margin-right:6px;">
                            <input type="hidden" name="test_curl" value="<?php echo $config['id']; ?>">
                            <button type="submit" style="background: #3B82F6; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold;">Test</button>
                        </form>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="delete_curl" value="<?php echo $config['id']; ?>">
                            <button type="submit" onclick="return confirm('Delete this webhook?')" style="background: #EF4444; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold;">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="color: var(--text-muted); text-align: center; padding: 20px;">No webhooks configured yet.</p>
    <?php endif; ?>
</div>



<?php if (($_SESSION['user_type'] ?? '') === 'admin'): ?>

<div class="card" style="margin-top: 30px;">
    <h2 style="margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 15px;">👥 Team Management</h2>
    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-top: 20px;">
        <div style="background: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid var(--border);">
            <h3 style="margin-top: 0;">Add User</h3>
            <form method="POST">
                <input type="text" name="user_name" placeholder="Full Name" required style="width: 100%; padding: 8px; margin-bottom: 10px; border-radius: 4px; border: 1px solid var(--border);">
                <input type="email" name="user_email" placeholder="Email Address" required style="width: 100%; padding: 8px; margin-bottom: 10px; border-radius: 4px; border: 1px solid var(--border);">
                <input type="password" name="user_password" placeholder="Password" required style="width: 100%; padding: 8px; margin-bottom: 10px; border-radius: 4px; border: 1px solid var(--border);">
                <button type="submit" name="add_user" style="background: #10B981; color: white; border: none; padding: 10px; border-radius: 4px; width: 100%; cursor: pointer; font-weight: bold;">Create User Account</button>
            </form>
        </div>
        <div>
            <h3 style="margin-top: 0;">Organization Users</h3>
            <table style="width: 100%; font-size: 14px;">
                <tr><th style="text-align:left;">Name</th><th style="text-align:left;">Email</th><th style="text-align:right;">Action</th></tr>
                <?php foreach($org_users as $u): ?>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #F3F4F6;"><?php echo htmlspecialchars($u['name']); ?></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #F3F4F6;"><?php echo htmlspecialchars($u['email']); ?></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #F3F4F6; text-align:right;">
                        <form method="POST" onsubmit="return confirm('Delete this user?');">
                            <input type="hidden" name="delete_user_id" value="<?php echo $u['id']; ?>">
                            <button name="delete_user" style="background:#EF4444; color:white; border:none; padding:4px 8px; border-radius:4px; cursor:pointer;">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($org_users)): ?><tr><td colspan="3" style="color:var(--text-muted); padding: 10px 0;">No users created.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<h3 style="margin-top: 30px; border-bottom: 1px solid var(--border); padding-bottom: 10px;">Recent CURL Webhook Logs</h3>
<?php
try {
    $projectFilter = $_SESSION['project_id'] ?? null;
    if ($projectFilter) {
        $stmtLogs = $pdo->prepare("SELECT * FROM curl_logs WHERE org_id = ? AND (project_id IS NULL OR project_id = ?) ORDER BY created_at DESC LIMIT 50");
        $stmtLogs->execute([$current_org_id, $projectFilter]);
    } else {
        $stmtLogs = $pdo->prepare("SELECT * FROM curl_logs WHERE org_id = ? ORDER BY created_at DESC LIMIT 50");
        $stmtLogs->execute([$current_org_id]);
    }
    $curl_logs = $stmtLogs->fetchAll();
} catch (Exception $e) {
    $curl_logs = [];
}
?>
<?php if (!empty($curl_logs)): ?>
    <div style="max-height: 320px; overflow-y: auto; border: 1px solid var(--border); border-radius: 6px; background:#FFF;">
        <table style="width: 100%; font-size: 12px;">
            <thead>
                <tr>
                    <th style="padding:8px; border-bottom:1px solid var(--border);">Time</th>
                    <th style="padding:8px; border-bottom:1px solid var(--border);">Event</th>
                    <th style="padding:8px; border-bottom:1px solid var(--border);">Endpoint</th>
                    <th style="padding:8px; border-bottom:1px solid var(--border);">Code</th>
                    <th style="padding:8px; border-bottom:1px solid var(--border);">Error</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($curl_logs as $log): ?>
                <tr>
                    <td style="padding:6px 8px; border-bottom:1px solid #F3F4F6;"><?php echo htmlspecialchars($log['created_at']); ?></td>
                    <td style="padding:6px 8px; border-bottom:1px solid #F3F4F6;"><?php echo htmlspecialchars($log['webhook_event']); ?></td>
                    <td style="padding:6px 8px; border-bottom:1px solid #F3F4F6; max-width:220px; word-break:break-all;"><?php echo htmlspecialchars($log['endpoint']); ?></td>
                    <td style="padding:6px 8px; border-bottom:1px solid #F3F4F6;"><?php echo $log['response_code'] !== null ? (int)$log['response_code'] : '-'; ?></td>
                    <td style="padding:6px 8px; border-bottom:1px solid #F3F4F6; max-width:200px; word-break:break-all;"><?php echo htmlspecialchars($log['error_text'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <p style="color: var(--text-muted); font-size: 12px; margin-top: 8px;">No webhook logs yet.</p>
<?php endif; ?>

<?php require_once 'footer.php'; ?>

<script>
(function(){
    const textarea = document.getElementById('variable_mapping');
    const addBtn = document.getElementById('curl_add_var_btn');
    const extInput = document.getElementById('curl_external_key');
    const availSel = document.getElementById('curl_available_key');
    if (!textarea || !addBtn || !extInput || !availSel) return;

    function safeParse(json) {
        if (!json || !json.trim()) return {};
        try {
            const v = JSON.parse(json);
            return v && typeof v === 'object' ? v : {};
        } catch (e) {
            return {};
        }
    }

    addBtn.addEventListener('click', function(){
        const external = extInput.value.trim();
        const available = availSel.value;
        if (!external || !available) {
            return;
        }
        const current = safeParse(textarea.value || '{}');
        current[external] = available;
        textarea.value = JSON.stringify(current, null, 2);
    });
})();
</script>