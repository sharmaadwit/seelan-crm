<?php
// profile.php
ini_set('display_errors', 1); error_reporting(E_ALL);
require_once 'db.php';
require_once 'helpers.php'; // <--- THE FIX: This loads your decryption and Gupshup functions!
require_once 'header.php';

$lead_id = $_GET['id'] ?? null;
if (!$lead_id) die("Lead ID is required.");

$msg = $_GET['msg'] ?? '';
$error = '';

$stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ? AND org_id = ?");
$stmt->execute([$lead_id, $current_org_id]);
$lead = $stmt->fetch();
if (!$lead) die("Lead not found or unauthorized.");

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

// Fetch Display Data
$stmt_camps = $pdo->prepare("SELECT c.campaign_name, lc.created_at FROM lead_campaigns lc JOIN campaigns c ON lc.campaign_id = c.id WHERE lc.lead_id = ? ORDER BY lc.created_at DESC");
$stmt_camps->execute([$lead_id]);
$lead_campaigns = $stmt_camps->fetchAll();

$stmt_appts = $pdo->prepare("SELECT * FROM appointments WHERE lead_id = ? AND org_id = ? ORDER BY start_time DESC");
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
?>

<div style="margin-bottom: 20px;">
    <a href="leads.php" style="color: var(--primary); text-decoration: none; font-weight: bold;">&larr; Back to Leads</a>
</div>

<h1 style="margin-top: 0; margin-bottom: 25px;">Patient Profile: <?php echo htmlspecialchars($lead['name']); ?></h1>

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
                    <span style="font-size: 12px; background: #DBEAFE; color: #1D4ED8; padding: 2px 6px; border-radius: 4px; margin-left: 10px;"><?php echo $appt['status']; ?></span>
                    <?php if ($appt['meet_link']): ?><br><a href="<?php echo htmlspecialchars($appt['meet_link']); ?>" target="_blank" style="font-size:14px; color:var(--primary); text-decoration: none; font-weight: bold; margin-top: 5px; display: inline-block;">Join Meeting &rarr;</a><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if(empty($appointments)): ?><p style="color:var(--text-muted); font-size:14px;">No appointments scheduled.</p><?php endif; ?>
        </div>

        <div class="card" style="border-left: 4px solid #10B981; margin-bottom: 0;">
            <h2 style="margin-top: 0;">WhatsApp Log</h2>
            <div style="max-height: 300px; overflow-y: auto; padding-right: 10px;">
                <?php foreach ($message_logs as $log): ?>
                    <div style="background: #F9FAFB; padding: 12px; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 10px;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                            <strong style="font-size:14px; color:#10B981;">Template: <?php echo htmlspecialchars($log['template_name']); ?></strong>
                            <span style="font-size:12px; color:var(--text-muted);"><?php echo date('M j, Y g:i A', strtotime($log['sent_at'])); ?></span>
                        </div>
                        <p style="margin:0; font-size:14px; white-space: pre-wrap; color:var(--text-main);"><?php echo htmlspecialchars($log['message_body']); ?></p>
                    </div>
                <?php endforeach; ?>
                <?php if(empty($message_logs)): ?><p style="color:var(--text-muted); font-size:14px;">No messages sent yet.</p><?php endif; ?>
            </div>
        </div>

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