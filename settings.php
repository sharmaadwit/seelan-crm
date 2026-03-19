<?php
// settings.php
require_once 'db.php';
require_once 'helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$current_org_id = $_SESSION['org_id'] ?? null;
if (!$current_org_id) { header("Location: login.php"); exit; }

$msg = '';

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

    // SAVE TIMEZONE
    if (isset($_POST['save_timezone'])) {
        $pdo->prepare("UPDATE organizations SET timezone = ? WHERE id = ?")->execute([$_POST['timezone'], $current_org_id]);
        $msg = "Timezone updated!";
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

    // 9. MANAGE TIMESLOTS
    if (isset($_POST['add_slot'])) {
        $days = $_POST['day'] === 'Everyday' ? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] : [$_POST['day']];
        $start = $_POST['start'];
        $end = $_POST['end'];
        $break_start = $_POST['break_start'] ?? '';
        $break_end = $_POST['break_end'] ?? '';
        $capacity = $_POST['capacity'];

        $stmt = $pdo->prepare("INSERT INTO agent_timeslots (org_id, day_of_week, start_time, end_time, capacity) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($days as $d) {
            if (!empty($break_start) && !empty($break_end)) {
                $stmt->execute([$current_org_id, $d, $start, $break_start, $capacity]);
                $stmt->execute([$current_org_id, $d, $break_end, $end, $capacity]);
            } else {
                $stmt->execute([$current_org_id, $d, $start, $end, $capacity]);
            }
        }
        $msg = "Working hours added successfully.";
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

$org = $pdo->prepare("SELECT api_key, gupshup_userid, gupshup_password, gupshup_app_id, gupshup_api_key, reminder_minutes, timezone FROM organizations WHERE id = ?");
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

$slots = $pdo->prepare("SELECT * FROM agent_timeslots WHERE org_id = ? ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time");
$slots->execute([$current_org_id]);
?>

<h1 style="margin-bottom: 20px;">Settings & API Integrations</h1>

<?php if ($msg): ?>
    <div style="background: #D1FAE5; color: #047857; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border: 1px solid #34D399;">
        <?php echo htmlspecialchars($msg); ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; margin-bottom: 20px;">
    
    <div style="display: flex; flex-direction: column; gap: 20px;">
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
            <label style="font-size: 14px; font-weight:bold; display:block; margin-bottom:5px;">Organization Timezone</label>
            <div style="display: flex; gap: 10px; align-items: center;">
                <select name="timezone" style="width: 250px; padding: 8px; border-radius: 4px; border: 1px solid var(--border);">
                    <option value="Asia/Kolkata" <?php echo ($org_data['timezone'] ?? 'Asia/Kolkata') == 'Asia/Kolkata' ? 'selected' : ''; ?>>Asia/Kolkata</option>
                    <option value="America/New_York" <?php echo ($org_data['timezone'] ?? '') == 'America/New_York' ? 'selected' : ''; ?>>America/New_York</option>
                    <option value="Europe/London" <?php echo ($org_data['timezone'] ?? '') == 'Europe/London' ? 'selected' : ''; ?>>Europe/London</option>
                    <option value="UTC" <?php echo ($org_data['timezone'] ?? '') == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                </select>
                <button type="submit" name="save_timezone" style="background: var(--primary); color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: auto;">Save Timezone</button>
            </div>
        </form>

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
        <form method="POST" style="margin-bottom:20px; background: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid var(--border);">
            <div style="display: flex; flex-direction: column; gap: 15px;">
                <div style="display: flex; gap: 10px; align-items: center;">
                    <label style="width: 80px; font-size:13px; font-weight:bold;">Day(s):</label>
                    <select name="day" style="padding: 8px; flex: 1; border: 1px solid var(--border); border-radius:4px;">
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
                <div style="display: flex; gap: 10px; align-items: center;">
                    <label style="width: 80px; font-size:13px; font-weight:bold;">Work Hrs:</label>
                    <input type="time" name="start" required style="padding: 8px; flex: 1; border: 1px solid var(--border); border-radius:4px;" title="Start Time"> 
                    <span style="font-size:12px; color:var(--text-muted);">to</span>
                    <input type="time" name="end" required style="padding: 8px; flex: 1; border: 1px solid var(--border); border-radius:4px;" title="End Time">
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <label style="width: 80px; font-size:13px; font-weight:bold;">Break <i>(Opt)</i>:</label>
                    <input type="time" name="break_start" style="padding: 8px; flex: 1; border: 1px solid var(--border); border-radius:4px;" title="Break Start"> 
                    <span style="font-size:12px; color:var(--text-muted);">to</span>
                    <input type="time" name="break_end" style="padding: 8px; flex: 1; border: 1px solid var(--border); border-radius:4px;" title="Break End">
                </div>
                <div style="display: flex; gap: 10px; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label style="font-size: 13px; font-weight: bold;">Concurrent Capacity:</label>
                        <input type="number" name="capacity" value="1" min="1" required style="width: 60px; padding: 8px; border: 1px solid var(--border); border-radius:4px;">
                    </div>
                    <button name="add_slot" type="submit" style="background: var(--primary); color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">Add Timeslots</button>
                </div>
            </div>
        </form>
        
        <table style="font-size: 14px;">
            <tr><th>Day</th><th>Time</th><th>Cap.</th><th>Action</th></tr>
            <?php foreach($slots->fetchAll() as $s): ?>
            <tr>
                <td><?php echo substr($s['day_of_week'], 0, 3); ?></td>
                <td><?php echo date('g:i A', strtotime($s['start_time'])).' - '.date('g:i A', strtotime($s['end_time'])); ?></td>
                <td><?php echo $s['capacity']; ?></td>
                <td><form method="POST"><input type="hidden" name="id" value="<?php echo $s['id']; ?>"><button name="del_slot" style="background:#EF4444;color:white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer;">Del</button></form></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<div class="card" style="margin-top: 30px; border-left: 4px solid #3B82F6;">
    <h2 style="margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 15px;">📖 API Assistance & Documentation</h2>
    <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px;">Use the following ready-to-use cURL commands to integrate with your external tools. Your <b>API Key</b> and <b>Project ID</b> have automatically been populated below.</p>

    <div style="display: flex; flex-direction: column; gap: 15px;">
        
        <!-- Create Lead -->
        <div style="background: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid var(--border);">
            <h4 style="margin-top: 0; color: #111827;">1. Create Lead</h4>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 10px;">Endpoint: <code>POST /api.php</code> | Action: <code>create_lead</code></p>
            <pre style="background: #1F2937; color: #F3F4F6; padding: 15px; border-radius: 6px; font-size: 12px; overflow-x: auto; margin: 0;"><code>curl -X POST "http://<?php echo $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); ?>/api.php" \
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
        <div style="background: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid var(--border);">
            <h4 style="margin-top: 0; color: #111827;">2. Check Available Slots</h4>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 10px;">Endpoint: <code>POST /api.php</code> | Action: <code>check_slots</code></p>
            <pre style="background: #1F2937; color: #F3F4F6; padding: 15px; border-radius: 6px; font-size: 12px; overflow-x: auto; margin: 0;"><code>curl -X POST "http://<?php echo $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); ?>/api.php" \
  -H "Content-Type: application/json" \
  -d '{
    "api_key": "<?php echo htmlspecialchars($org_data['api_key']); ?>",
    "project_id": "<?php echo htmlspecialchars($_SESSION['org_name']); ?>",
    "action": "check_slots",
    "date": "<?php echo date('Y-m-d'); ?>"
  }'</code></pre>
        </div>

        <!-- Check Specific Slot -->
        <div style="background: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid var(--border);">
            <h4 style="margin-top: 0; color: #111827;">3. Check Specific Slot</h4>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 10px;">Endpoint: <code>POST /api.php</code> | Action: <code>check_specific_slot</code></p>
            <pre style="background: #1F2937; color: #F3F4F6; padding: 15px; border-radius: 6px; font-size: 12px; overflow-x: auto; margin: 0;"><code>curl -X POST "http://<?php echo $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); ?>/api.php" \
  -H "Content-Type: application/json" \
  -d '{
    "api_key": "<?php echo htmlspecialchars($org_data['api_key']); ?>",
    "project_id": "<?php echo htmlspecialchars($_SESSION['org_name']); ?>",
    "action": "check_specific_slot",
    "datetime": "<?php echo date('Y-m-d 10:00:00'); ?>"
  }'</code></pre>
        </div>

        <!-- Book Appointment -->
        <div style="background: #F9FAFB; padding: 15px; border-radius: 6px; border: 1px solid var(--border);">
            <h4 style="margin-top: 0; color: #111827;">4. Book Appointment</h4>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 10px;">Endpoint: <code>POST /api.php</code> | Action: <code>book_appointment</code></p>
            <pre style="background: #1F2937; color: #F3F4F6; padding: 15px; border-radius: 6px; font-size: 12px; overflow-x: auto; margin: 0;"><code>curl -X POST "http://<?php echo $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\'); ?>/api.php" \
  -H "Content-Type: application/json" \
  -d '{
    "api_key": "<?php echo htmlspecialchars($org_data['api_key']); ?>",
    "project_id": "<?php echo htmlspecialchars($_SESSION['org_name']); ?>",
    "action": "book_appointment",
    "mobile": "9876543210",
    "start_time": "<?php echo date('Y-m-d 09:00:00'); ?>",
    "end_time": "<?php echo date('Y-m-d 10:00:00'); ?>"
  }'</code></pre>
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