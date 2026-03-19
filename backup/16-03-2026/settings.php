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
        
        if (!empty($password) && $password !== '********') {
            $enc_pass = encryptPassword($password);
            $pdo->prepare("UPDATE organizations SET gupshup_userid = ?, gupshup_password = ? WHERE id = ?")->execute([$userid, $enc_pass, $current_org_id]);
        } else {
            $pdo->prepare("UPDATE organizations SET gupshup_userid = ? WHERE id = ?")->execute([$userid, $current_org_id]);
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
            // Validate JSON mapping
            $map_data = @json_decode($mapping, true);
            if (empty($mapping) || $map_data !== null) {
                $stmt = $pdo->prepare("
                    INSERT INTO curl_configs (org_id, curl_endpoint, webhook_event, variable_mapping, is_active) 
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    curl_endpoint = ?, variable_mapping = ?, is_active = ?
                ");
                $stmt->execute([$current_org_id, $endpoint, $event, $mapping, $is_active, $endpoint, $mapping, $is_active]);
                $msg = "Webhook configured successfully.";
            } else {
                $msg = "Invalid JSON in variable mapping.";
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

    // 5. SYNC TEMPLATES FROM GUPSHUP API
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

    // 4. SAVE TEMPLATE VARIABLE MAPPING
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

    // 5. SAVE REMINDER TIME
    if (isset($_POST['save_reminder_time'])) {
        $pdo->prepare("UPDATE organizations SET reminder_minutes = ? WHERE id = ?")->execute([$_POST['reminder_minutes'], $current_org_id]);
        $msg = "Reminder timing updated!";
    }

    // 6. MANAGE TIMESLOTS
    if (isset($_POST['add_slot'])) {
        $pdo->prepare("INSERT INTO agent_timeslots (org_id, day_of_week, start_time, end_time, capacity) VALUES (?, ?, ?, ?, ?)")
            ->execute([$current_org_id, $_POST['day'], $_POST['start'], $_POST['end'], $_POST['capacity']]);
        $msg = "Working hours added.";
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

$org = $pdo->prepare("SELECT api_key, gupshup_userid, gupshup_password, reminder_minutes FROM organizations WHERE id = ?");
$org->execute([$current_org_id]);
$org_data = $org->fetch();
$has_pass = !empty($org_data['gupshup_password']);

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
            <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                <select name="day" style="padding: 8px; flex: 1; border: 1px solid var(--border); border-radius:4px;"><option>Monday</option><option>Tuesday</option><option>Wednesday</option><option>Thursday</option><option>Friday</option><option>Saturday</option><option>Sunday</option></select>
                <input type="time" name="start" required style="padding: 8px; flex: 1; border: 1px solid var(--border); border-radius:4px;"> 
                <input type="time" name="end" required style="padding: 8px; flex: 1; border: 1px solid var(--border); border-radius:4px;">
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <label style="font-size: 14px; font-weight: bold;">Capacity (Slots):</label>
                <input type="number" name="capacity" value="1" min="1" required style="width: 80px; padding: 8px; border: 1px solid var(--border); border-radius:4px;">
                <button name="add_slot" type="submit" style="background: var(--primary); color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;">Add Slot</button>
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
        <textarea name="variable_mapping" placeholder='{"lead_name": "name", "mobile": "phone", "status": "lead_status"}' style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 4px; margin-bottom: 15px; box-sizing: border-box; font-family: monospace; font-size: 12px; height: 100px;"></textarea>
        
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
                    <th>Action</th>
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

<?php require_once 'footer.php'; ?>