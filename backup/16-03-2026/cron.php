<?php
// cron.php
// This script should be run by your server every minute via a Cron Job
require_once 'db.php';
require_once 'helpers.php';

echo "Starting Reminder Cron Job...\n";

// 1. Fetch all scheduled appointments in the future where a reminder hasn't been sent
$stmt = $pdo->query("
    SELECT a.id as appt_id, a.org_id, a.lead_id, a.start_time, a.meet_link,
           o.reminder_minutes, l.mobile, l.name as lead_name
    FROM appointments a
    JOIN organizations o ON a.org_id = o.id
    JOIN leads l ON a.lead_id = l.id
    WHERE a.status = 'Scheduled' 
      AND a.reminder_sent = 0 
      AND a.start_time > NOW()
");
$appointments = $stmt->fetchAll();

$sent_count = 0;

foreach ($appointments as $appt) {
    $appt_time = strtotime($appt['start_time']);
    $now = time();
    $diff_minutes = round(($appt_time - $now) / 60);

    // If the time until the appointment is equal to or less than the reminder preference
    if ($diff_minutes <= $appt['reminder_minutes']) {
        
        // 1. Mark as sent immediately to prevent infinite loops if the Gupshup API fails
        $pdo->prepare("UPDATE appointments SET reminder_sent = 1 WHERE id = ?")->execute([$appt['appt_id']]);

        // 2. Prepare dynamic data
        $dynamic_data = [
            'lead_name' => $appt['lead_name'],
            'start_time' => date('M j, Y g:i A', $appt_time),
            'meet_link' => $appt['meet_link']
        ];

        // 3. Fire the template (This helper function handles logging it to the profile as well!)
        $result = sendGupshupTemplate($pdo, $appt['org_id'], 'reminder', $appt['mobile'], $dynamic_data, $appt['lead_id']);

        if ($result !== false) {
            $sent_count++;
            echo "Sent reminder for Appointment ID: {$appt['appt_id']}\n";
        }
    }
}

echo "Cron finished. Sent $sent_count reminders.\n";
?>