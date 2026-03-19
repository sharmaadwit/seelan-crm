<?php
// profile.php
ini_set('display_errors', 1); error_reporting(E_ALL);
require_once 'db.php';
require_once 'helpers.php'; // <--- THE FIX: This loads your decryption and Gupshup functions!

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['org_id'])) { header("Location: login.php"); exit; }
$current_org_id = $_SESSION['org_id'];

$lead_id = $_GET['id'] ?? null;
if (!$lead_id) die("Lead ID is required.");

$stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ? AND org_id = ?");
$stmt->execute([$lead_id, $current_org_id]);
$lead = $stmt->fetch();
if (!$lead) die("Lead not found or unauthorized.");

// Handle AJAX Request for WhatsApp Transcript
if (isset($_POST['fetch_transcript']) && isset($_POST['days_filter'])) {
    header('Content-Type: application/json');
    $days = (int)$_POST['days_filter'];
    $end_date = date('Y-m-d H:i:s');
    $start_date = date('Y-m-d H:i:s', strtotime("-$days days"));
    
    // Get dynamic API config
    $stmt = $pdo->prepare("SELECT gupshup_app_id, gupshup_api_key FROM organizations WHERE id = ?");
    $stmt->execute([$current_org_id]);
    $org_cfg = $stmt->fetch();
    
    $app_id = !empty($org_cfg['gupshup_app_id']) ? $org_cfg['gupshup_app_id'] : '31569577';
    $api_key = !empty($org_cfg['gupshup_api_key']) ? $org_cfg['gupshup_api_key'] : '3f9eb6dcb0f60417808833ebe32f81d47c28a04c167eef34b3c74b39adeb2dd7';
    
    // Format required by Gupshup API
    $payload = [
        "startDate" => $start_date,
        "endDate" => $end_date,
        "pageSize" => "50",
        "customerHandleList" => [$lead['mobile']],
        "currentPage" => 1,
        "includeChatCustomInfo" => true,
        "includeCustomerInfo" => false,
        "includeChatTags" => true,
        "includeRelationshipManagerDetails" => false,
        "includeCustomerFeedback" => true
    ];
    
    $ch = curl_init("https://{$app_id}.assist.gupshup.io/api/91110d/mgateway/v2/transcript");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'apiKey: ' . $api_key
    ]);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if (!$response) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "CURL Error: " . $error]);
    } else {
        echo $response;
    }
    exit;
}

$msg = $_GET['msg'] ?? '';
$error = '';

// 1. Handle Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $stmt = $pdo->prepare("UPDATE leads SET status = ? WHERE id = ? AND org_id = ?");
    $stmt->execute([$_POST['status'], $lead_id, $current_org_id]);
    header("Location: profile.php?id=" . $lead_id . "&msg=Status+Updated"); exit;
}

// 2. Handle Add Internal Note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    if (!empty(trim($_POST['comment_text']))) {
        $stmt = $pdo->prepare("INSERT INTO comments (org_id, lead_id, comment_text) VALUES (?, ?, ?)");
        $stmt->execute([$current_org_id, $lead_id, trim($_POST['comment_text'])]);
    }
    header("Location: profile.php?id=" . $lead_id . "&msg=Note+Saved"); exit;
}

// 3. Handle Manual WhatsApp Template Sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_template'])) {
    try {
        $template_id = $_POST['template_id'];
        
        $stmt = $pdo->prepare("SELECT body, name FROM wa_templates WHERE template_id = ? AND org_id = ?");
        $stmt->execute([$template_id, $current_org_id]);
        $template = $stmt->fetch();

        if ($template) {
            $stmt_creds = $pdo->prepare("SELECT gupshup_userid, gupshup_password FROM organizations WHERE id = ?");
            $stmt_creds->execute([$current_org_id]);
            $creds = $stmt_creds->fetch();

            if ($creds && !empty($creds['gupshup_userid']) && !empty($creds['gupshup_password'])) {
                $password = decryptPassword($creds['gupshup_password']);
                if(!$password) throw new Exception("Failed to decrypt Gupshup password. Please re-save it in Settings.");

                $base_url = "https://media.smsgupshup.com/GatewayAPI/rest?method=SENDMESSAGE&msg_type=TEXT";
                $full_url = $base_url . "&userid={$creds['gupshup_userid']}&auth_scheme=plain&password={$password}&format=text&data_encoding=TEXT&send_to={$lead['mobile']}&v=1.1&isHSM=true&template_id={$template_id}";
                
                $var_string = "";
                $final_message_body = $template['body'];
                foreach ($_POST as $key => $val) {
                    if (strpos($key, 'var_') === 0) {
                        $var_num = str_replace('var_', '', $key);
                        $var_string .= "&var{$var_num}=" . urlencode($val);
                        $final_message_body = str_replace("{{" . $var_num . "}}", $val, $final_message_body);
                    }
                }
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $full_url . $var_string);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $res = curl_exec($ch);
                curl_close($ch);

                $stmt_log = $pdo->prepare("INSERT INTO message_logs (org_id, lead_id, template_name, message_body) VALUES (?, ?, ?, ?)");
                $stmt_log->execute([$current_org_id, $lead_id, $template['name'], $final_message_body]);
                
                header("Location: profile.php?id=" . $lead_id . "&msg=WhatsApp+Message+Sent"); exit;
            } else {
                throw new Exception("Gupshup credentials missing. Please set them in Settings.");
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 4. Handle Delete Lead
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_lead'])) {
    $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ? AND org_id = ?");
    $stmt->execute([$lead_id, $current_org_id]);
    header("Location: leads.php?msg=Lead+permanently+deleted."); exit;
}

require_once 'header.php';

// Fetch Display Data
$stmt_camps = $pdo->prepare("SELECT c.campaign_name, lc.created_at FROM lead_campaigns lc JOIN campaigns c ON lc.campaign_id = c.id WHERE lc.lead_id = ? ORDER BY lc.created_at DESC");
$stmt_camps->execute([$lead_id]);
$lead_campaigns = $stmt_camps->fetchAll();

$stmt_appts = $pdo->prepare("SELECT a.*, d.name as doctor_name FROM appointments a LEFT JOIN doctors d ON a.doctor_id = d.id WHERE a.lead_id = ? AND a.org_id = ? ORDER BY a.start_time DESC");
$stmt_appts->execute([$lead_id, $current_org_id]);
$appointments = $stmt_appts->fetchAll();

$stmt_comments = $pdo->prepare("SELECT * FROM comments WHERE lead_id = ? AND org_id = ? ORDER BY created_at DESC");
$stmt_comments->execute([$lead_id, $current_org_id]);
$comments = $stmt_comments->fetchAll();

$stmt_logs = $pdo->prepare("SELECT * FROM message_logs WHERE lead_id = ? AND org_id = ? ORDER BY sent_at DESC");
$stmt_logs->execute([$lead_id, $current_org_id]);
$message_logs = $stmt_logs->fetchAll();

$stmt_tpl = $pdo->prepare("SELECT * FROM wa_templates WHERE org_id = ?");
$stmt_tpl->execute([$current_org_id]);
$cached_templates = $stmt_tpl->fetchAll();

// Masking helpers
function maskText($text) {
    if (empty($text)) return '';
    $len = strlen($text);
    if ($len <= 2) return $text . str_repeat('*', 3);
    return substr($text, 0, 1) . str_repeat('*', $len - 1);
}

function maskCpf($cpf) {
    if (empty($cpf)) return '';
    // Show only last 3 digits
    $cpf = preg_replace('/[^0-9]/', '', $cpf);
    if (strlen($cpf) > 3) {
        return "***.***.*" . substr($cpf, -3);
    }
    return "***.***.*" . $cpf;
}

function blurLeadName($name) {
    if (empty($name)) return '';
    if (strlen($name) <= 3) return htmlspecialchars($name);
    $visible = substr($name, 0, 3);
    $blurred = substr($name, 3);
    return htmlspecialchars($visible) . '<span style="filter: blur(4px); cursor: pointer;" onclick="this.style.filter=\'none\'" title="Click to reveal">' . htmlspecialchars($blurred) . '</span>';
}
?>

<div style="margin-bottom: 20px;">
    <a href="leads.php" style="color: var(--primary); text-decoration: none; font-weight: bold;">&larr; Back to Leads</a>
</div>

<h1 style="margin-top: 0; margin-bottom: 25px;">Leads Profile: <?php echo htmlspecialchars($lead['mobile']); ?></h1>

<?php if ($msg): ?>
    <div style="background: #D1FAE5; color: #047857; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border: 1px solid #34D399;">
        <?php echo htmlspecialchars(str_replace('+', ' ', $msg)); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div style="background: #FEE2E2; color: #B91C1C; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-weight: bold; border: 1px solid #F87171;">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px;">
    
    <div style="display: flex; flex-direction: column; gap: 20px;">
        <div class="card" style="margin-bottom: 0;">
            <h2 style="margin-top: 0;">Details</h2>
            
            <p><strong>Mobile:</strong> <?php echo htmlspecialchars($lead['mobile']); ?></p>
            <p><strong>Name as on WhatsApp:</strong> <?php echo htmlspecialchars($lead['name']); ?></p>
            
            <?php if (!empty($lead['cpf_number'])): ?>
            <p><strong>CPF Number:</strong> <span style="filter: blur(2px); cursor: pointer;" onclick="this.style.filter='none'"><?php echo htmlspecialchars(maskCpf($lead['cpf_number'])); ?></span></p>
            <?php endif; ?>
            
            <?php if (!empty($lead['patient_name'])): ?>
            <p><strong>Patient Name:</strong> <span title="Masked for privacy"><?php echo htmlspecialchars(maskText($lead['patient_name'])); ?></span></p>
            <?php endif; ?>
            
            <?php if (!empty($lead['visit_reason'])): ?>
            <p><strong>Visit Reason:</strong> <span title="Masked for privacy"><?php echo htmlspecialchars(maskText($lead['visit_reason'])); ?></span></p>
            <?php endif; ?>
            
            <?php if (!empty($lead['doctor_type'])): ?>
            <p><strong>Doctor Type:</strong> <?php echo htmlspecialchars($lead['doctor_type']); ?></p>
            <?php endif; ?>
            
            <?php if (!empty($lead['patient_type'])): ?>
            <p><strong>Patient Type:</strong> <?php echo htmlspecialchars($lead['patient_type']); ?></p>
            <?php endif; ?>
            
            <?php if (!empty($lead['branch'])): ?>
            <p><strong>Branch:</strong> <?php echo htmlspecialchars($lead['branch']); ?></p>
            <?php endif; ?>

            <p><strong>Added On:</strong> <?php echo date('M j, Y', strtotime($lead['created_at'])); ?></p>
            <hr style="border:0; border-top:1px solid var(--border); margin: 15px 0;">
            <form method="POST">
                <label style="font-weight:bold; font-size:14px; color:var(--text-muted); display:block; margin-bottom:5px;">Update Status:</label>
                <select name="status" style="width: 100%; padding: 8px; margin-bottom: 10px; border-radius:4px; border:1px solid var(--border);">
                    <option <?php if($lead['status'] == 'New') echo 'selected'; ?>>New</option>
                    <option <?php if($lead['status'] == 'In Progress') echo 'selected'; ?>>In Progress</option>
                    <option <?php if($lead['status'] == 'Appointment Booked') echo 'selected'; ?>>Appointment Booked</option>
                    <option <?php if($lead['status'] == 'Converted') echo 'selected'; ?>>Converted</option>
                    <option <?php if($lead['status'] == 'Closed') echo 'selected'; ?>>Closed</option>
                </select>
                <button type="submit" name="update_status" style="background:var(--primary); color:white; width:100%; padding:10px; border:none; border-radius:4px; cursor:pointer; font-weight: bold;">Save Status</button>
            </form>
        </div>

        <div class="card" style="margin-bottom: 0; border-left: 4px solid #10B981;">
            <h2 style="margin-top: 0; color: #047857;">Send WhatsApp</h2>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 15px;">Manually trigger a synced template to this lead.</p>
            <form method="POST">
                <select name="template_id" class="manual-template-selector" required style="width: 100%; padding: 10px; margin-bottom: 15px; border-radius:4px; border:1px solid var(--border);">
                    <option value="">-- Select Template --</option>
                    <?php foreach ($cached_templates as $t): ?>
                        <option value="<?php echo $t['template_id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="manual_vars_container" style="display: none; margin-bottom: 15px; background: #F9FAFB; padding: 10px; border-radius: 4px; border: 1px solid var(--border);"></div>
                <button type="submit" name="send_template" style="background:#10B981; color:white; width:100%; padding:10px; border:none; border-radius:4px; cursor:pointer; font-weight: bold;">Send Message</button>
            </form>
        </div>

        <script>
            const templatesData = <?php echo json_encode($cached_templates); ?>;
            document.querySelector('.manual-template-selector').addEventListener('change', function() {
                const templateId = this.value;
                const varsContainer = document.getElementById('manual_vars_container');
                varsContainer.innerHTML = '';
                if(!templateId) { varsContainer.style.display = 'none'; return; }
                const template = templatesData.find(t => t.template_id === templateId);
                if (template && template.var_count > 0) {
                    let title = document.createElement('p');
                    title.innerText = "Fill Template Variables:";
                    title.style = "margin: 0 0 10px 0; font-size: 13px; font-weight: bold;";
                    varsContainer.appendChild(title);
                    for (let i = 1; i <= template.var_count; i++) {
                        let input = document.createElement('input');
                        input.type = 'text'; input.name = 'var_' + i; input.placeholder = 'Value for {{' + i + '}}';
                        input.style = 'width: 100%; padding: 8px; margin-bottom: 8px; border-radius: 4px; border: 1px solid #E5E7EB; box-sizing: border-box;';
                        input.required = true;
                        varsContainer.appendChild(input);
                    }
                    varsContainer.style.display = 'block';
                } else { varsContainer.style.display = 'none'; }
            });
        </script>

        <div class="card" style="border: 1px solid #FCA5A5; background: #FEF2F2; margin-bottom: 0;">
            <h2 style="margin-top: 0; color: #DC2626;">Danger Zone</h2>
            <form method="POST" onsubmit="return confirm('WARNING: Are you sure you want to completely delete this lead?');">
                <button type="submit" name="delete_lead" style="background: #DC2626; color: white; width: 100%; padding: 10px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Delete Lead</button>
            </form>
        </div>
    </div>

    <div style="display: flex; flex-direction: column; gap: 20px;">
        
        <div class="card" style="margin-bottom: 0;">
            <h2 style="margin-top: 0;">Appointments</h2>
            <?php foreach ($appointments as $appt): ?>
                <div style="border-bottom: 1px solid var(--border); padding-bottom: 10px; margin-bottom: 10px;">
                    <strong><?php echo date('D, M j, Y @ g:i A', strtotime($appt['start_time'])); ?></strong>
                    <div style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
                        👨‍⚕️ Doctor: <?php echo htmlspecialchars($appt['doctor_name'] ?? 'General / Unassigned'); ?>
                    </div>
                    <span style="font-size: 12px; background: #DBEAFE; color: #1D4ED8; padding: 2px 6px; border-radius: 4px; margin-top: 5px; display: inline-block;"><?php echo $appt['status']; ?></span>
                    <?php if ($appt['meet_link']): ?><br><a href="<?php echo htmlspecialchars($appt['meet_link']); ?>" target="_blank" style="font-size:14px; color:var(--primary); text-decoration: none; font-weight: bold; margin-top: 5px; display: inline-block;">Join Meeting &rarr;</a><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if(empty($appointments)): ?><p style="color:var(--text-muted); font-size:14px;">No appointments scheduled.</p><?php endif; ?>
        </div>

        <div class="card" style="border-left: 4px solid #10B981; margin-bottom: 0;">
            <h2 style="margin-top: 0;">WhatsApp Conversation</h2>
            
            <div style="margin-bottom: 15px; display: flex; gap: 10px;">
                <select id="transcript_days" style="padding: 8px; border-radius: 4px; border: 1px solid var(--border);">
                    <option value="1">Last 24 Hours</option>
                    <option value="7" selected>Last 7 Days</option>
                    <option value="14">Last 14 Days</option>
                    <option value="30">Last 30 Days</option>
                </select>
                <button id="load_transcript_btn" style="background: var(--primary); color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold;">Load Conversation</button>
            </div>

            <!-- Blurred overlay container -->
            <div id="transcript_container" style="position: relative; min-height: 200px; background: #F9FAFB; border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
                
                <div id="transcript_overlay" style="position: absolute; inset: 0; background: rgba(255,255,255,0.8); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 10;">
                    <p style="color: var(--text-muted); font-weight: bold;">Click "Load Conversation" to view messages.</p>
                </div>
                
                <div id="transcript_content" style="padding: 15px; max-height: 400px; overflow-y: auto;">
                    <!-- Messages go here -->
                </div>

            </div>
        </div>

        <script>
        document.getElementById('load_transcript_btn').addEventListener('click', function() {
            const btn = this;
            const days = document.getElementById('transcript_days').value;
            const container = document.getElementById('transcript_content');
            const overlay = document.getElementById('transcript_overlay');
            
            btn.disabled = true;
            btn.innerText = 'Loading...';
            
            const formData = new FormData();
            formData.append('fetch_transcript', '1');
            formData.append('days_filter', days);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                overlay.style.display = 'none';
                container.innerHTML = '';
                
                if (data && data.chatData) {
                    let foundMessages = false;
                    for (const key in data.chatData) {
                        if (data.chatData[key].conversation && data.chatData[key].conversation.length > 0) {
                            foundMessages = true;
                            const messages = data.chatData[key].conversation;
                            
                            messages.forEach(msg => {
                                const isCustomer = msg.msgType === 'CUSTOMER_MSG';
                                const align = isCustomer ? 'flex-start' : 'flex-end';
                                const bg = isCustomer ? '#E5E7EB' : '#D1FAE5';
                                const color = isCustomer ? '#111827' : '#047857';
                                
                                const div = document.createElement('div');
                                div.style = `display: flex; justify-content: ${align}; margin-bottom: 15px; align-items: flex-end;`;
                                
                                const bubble = document.createElement('div');
                                bubble.style = `background: ${bg}; color: ${color}; padding: 10px 14px; border-radius: 8px; max-width: 80%; font-size: 14px; white-space: pre-wrap; box-shadow: 0 1px 2px rgba(0,0,0,0.05);`;
                                bubble.innerHTML = `<strong style="font-size:12px; opacity:0.8;">${isCustomer ? 'Customer' : 'Bot'}:</strong><br>` + 
                                                   msg.msgText.replace(/</g, '&lt;').replace(/>/g, '&gt;') + 
                                                   `<div style="font-size: 11px; margin-top: 5px; opacity: 0.6; text-align: right;">${msg.createdAt}</div>`;
                                
                                div.appendChild(bubble);
                                container.appendChild(div);
                            });
                        }
                    }
                    if (!foundMessages) {
                        container.innerHTML = '<p style="color:var(--text-muted); text-align:center;">No conversations found for the selected period.</p>';
                    }
                } else {
                    container.innerHTML = '<p style="color:#EF4444; text-align:center;">Failed to load messages or no data returned.</p>';
                }
            })
            .catch(err => {
                overlay.style.display = 'none';
                container.innerHTML = '<p style="color:#EF4444; text-align:center;">Error fetching data: ' + err.message + '</p>';
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerText = 'Load Conversation';
            });
        });

        // Auto-load on page entry for last 24 hours
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('transcript_days').value = '1';
            document.getElementById('load_transcript_btn').click();
        });
        </script>

        <div class="card" style="margin-bottom: 0;">
            <h2 style="margin-top: 0;">Internal Notes</h2>
            <form method="POST" style="margin-bottom: 15px;">
                <textarea name="comment_text" rows="3" required style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 4px; box-sizing: border-box; margin-bottom: 10px;" placeholder="Write a note about this patient..."></textarea>
                <button type="submit" name="add_comment" style="background: var(--primary); color:white; padding:10px 20px; border:none; border-radius:4px; cursor:pointer; font-weight: bold;">Save Note</button>
            </form>
            
            <?php foreach ($comments as $comment): ?>
                <div style="background: #F9FAFB; padding: 12px; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 10px;">
                    <p style="margin: 0 0 5px 0; font-size: 14px; color: var(--text-main);"><?php echo nl2br(htmlspecialchars($comment['comment_text'])); ?></p>
                    <span style="font-size: 11px; color: var(--text-muted);"><?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?></span>
                </div>
            <?php endforeach; ?>
            <?php if(empty($comments)): ?><p style="color:var(--text-muted); font-size:14px;">No notes saved yet.</p><?php endif; ?>
        </div>
    </div>
</div>
<?php require_once 'footer.php'; ?>