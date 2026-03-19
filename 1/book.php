<?php
require_once 'db.php';

$org_id = isset($_GET['org_id']) ? (int)$_GET['org_id'] : 0;

if (!$org_id) {
    die("Invalid Organization ID.");
}

// Fetch Org Details
$stmt = $pdo->prepare("SELECT org_name, api_key, timezone, slot_duration_minutes, event_type, event_address, org_logo FROM organizations WHERE id = ?");
$stmt->execute([$org_id]);
$org = $stmt->fetch();

if (!$org) {
    die("Organization not found.");
}
// Fetch Doctors
$docs_stmt = $pdo->prepare("SELECT id, name, specialization FROM doctors WHERE org_id = ? AND is_active = 1 ORDER BY name ASC");
$docs_stmt->execute([$org_id]);
$doctors = $docs_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment - <?php echo htmlspecialchars($org['org_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4F46E5;
            --primary-hover: #4338CA;
            --bg: #F8FAFC;
            --text-main: #1E293B;
            --text-muted: #64748B;
            --border: #E2E8F0;
            --white: #FFFFFF;
        }

        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .booking-container {
            background: var(--white);
            width: 100%;
            max-width: 500px;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            padding: 40px;
            border: 1px solid var(--border);
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 24px;
            font-weight: 800;
            margin: 0 0 10px 0;
            color: var(--primary);
        }

        .header p {
            color: var(--text-muted);
            font-size: 14px;
            margin: 0;
        }

        .step-label {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            margin-bottom: 15px;
            display: block;
        }

        input[type="date"], input[type="text"], input[type="tel"] {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 15px;
            font-family: inherit;
            margin-bottom: 20px;
            transition: border-color 0.2s;
        }

        input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .slots-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            margin-bottom: 25px;
        }

        .slot-btn {
            background: var(--white);
            border: 1px solid var(--border);
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .slot-btn:hover:not(.disabled) {
            border-color: var(--primary);
            background: #F5F3FF;
        }

        .slot-btn.selected {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .slot-btn.disabled {
            background: #FEF2F2;
            color: #EF4444;
            cursor: not-allowed;
            border-color: #FCA5A5;
        }

        .btn-confirm {
            background: var(--primary);
            color: var(--white);
            border: none;
            width: 100%;
            padding: 14px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 10px;
        }

        .btn-confirm:hover {
            background: var(--primary-hover);
        }

        .loading {
            text-align: center;
            padding: 20px;
            font-size: 14px;
            color: var(--text-muted);
        }

        #success-msg {
            display: none;
            text-align: center;
            padding: 40px 0;
        }

        #success-msg h2 { color: #10B981; }
    </style>
</head>
<body>

<div class="booking-container" id="booking-form">
    <div class="header">
        <?php if (!empty($org['org_logo'])): ?>
            <img src="uploads/<?php echo htmlspecialchars($org['org_logo']); ?>" style="max-height: 80px; margin-bottom: 20px; display: block; margin-left: auto; margin-right: auto;">
        <?php endif; ?>
        <h1><?php echo htmlspecialchars($org['org_name']); ?></h1>
        <p>Schedule your appointment in seconds</p>
    </div>

    <!-- Step 1: Select Doctor -->
    <span class="step-label">1. Select Doctor</span>
    <select id="doctor-id" style="width: 100%; padding: 12px 16px; border: 1px solid var(--border); border-radius: 8px; font-size: 15px; margin-bottom: 20px; font-family: inherit;">
        <option value="">-- Choose a Doctor --</option>
        <?php foreach($doctors as $d): ?>
            <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?> <?php echo $d['specialization'] ? "(".htmlspecialchars($d['specialization']).")" : ""; ?></option>
        <?php endforeach; ?>
    </select>

    <!-- Step 2: Select Date -->
    <span class="step-label">2. Choose a Date</span>
    <input type="date" id="booking-date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>">

    <!-- Step 3: Select Slot -->
    <span class="step-label">3. Select a Time</span>
    <div id="slots-container" class="slots-grid">
        <div class="loading">Please select a date to see available slots.</div>
    </div>

    <!-- Step 4: Your Info -->
    <div id="user-info" style="display: none;">
        <span class="step-label">4. Your Information</span>
        <input type="text" id="patient-name" placeholder="Full Name" required>
        <input type="tel" id="patient-mobile" placeholder="Mobile Number (with country code)" required>
        
        <button class="btn-confirm" id="btn-submit">Confirm Booking</button>
    </div>
</div>

<div class="booking-container" id="success-msg">
    <div style="font-size: 60px; margin-bottom: 20px;">✅</div>
    <h2>Booking Confirmed!</h2>
    <p id="summary-text" style="color: var(--text-muted); line-height: 1.6;"></p>
    <button class="btn-confirm" onclick="location.reload()" style="margin-top: 20px; background: #F1F5F9; color: var(--text-main);">Book Another</button>
</div>

<script>
    const orgId = "<?php echo $org_id; ?>";
    const apiKey = "<?php echo $org['api_key']; ?>";
    const projectName = "<?php echo htmlspecialchars($org['org_name']); ?>";
    
    let selectedSlot = null;

    document.getElementById('booking-date').addEventListener('change', fetchSlots);
    document.getElementById('doctor-id').addEventListener('change', fetchSlots);

    function fetchSlots() {
        const doctorId = document.getElementById('doctor-id').value;
        const date = document.getElementById('booking-date').value;
        const container = document.getElementById('slots-container');
        const userInfo = document.getElementById('user-info');
        
        if (!doctorId) {
            container.innerHTML = '<div class="loading">Please select a doctor to see availability.</div>';
            userInfo.style.display = 'none';
            return;
        }

        container.innerHTML = '<div class="loading">Fetching slots...</div>';
        userInfo.style.display = 'none';
        selectedSlot = null;

        const payload = {
            api_key: apiKey,
            project_id: projectName,
            action: 'check_slots',
            date: date,
            doctor_id: doctorId
        };

        fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                container.innerHTML = '';
                if (data.slots.length === 0) {
                    container.innerHTML = '<div class="loading">No slots available for this date.</div>';
                    return;
                }
                data.slots.forEach(slot => {
                    const btn = document.createElement('div');
                    btn.className = 'slot-btn' + (slot.available > 0 ? '' : ' disabled');
                    btn.innerHTML = `<div>${slot.time}</div><div style="font-size: 10px; opacity: 0.7;">${slot.available} available</div>`;
                    
                    if (slot.available > 0) {
                        btn.onclick = () => {
                            document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
                            btn.classList.add('selected');
                            selectedSlot = slot;
                            userInfo.style.display = 'block';
                        };
                    }
                    container.appendChild(btn);
                });
            } else {
                container.innerHTML = `<div class="loading">Error: ${data.message}</div>`;
            }
        });
    }

    document.getElementById('btn-submit').onclick = () => {
        const name = document.getElementById('patient-name').value.trim();
        const mobile = document.getElementById('patient-mobile').value.trim();
        const date = document.getElementById('booking-date').value;

        if (!name || !mobile) {
            alert("Please fill in your name and mobile.");
            return;
        }

        const btn = document.getElementById('btn-submit');
        btn.disabled = true;
        btn.innerText = "Booking...";

        const doctorId = document.getElementById('doctor-id').value;

        const payload = {
            api_key: apiKey,
            project_id: projectName,
            action: 'book_appointment',
            mobile: mobile,
            start_time: date + ' ' + selectedSlot.start_time,
            end_time: date + ' ' + selectedSlot.end_time,
            name: name,
            doctor_id: doctorId
        };

        fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                const isMeet = data.meet_link && data.meet_link.includes('http');
                document.getElementById('booking-form').style.display = 'none';
                document.getElementById('success-msg').style.display = 'block';
                document.getElementById('summary-text').innerHTML = `
                    <strong>${name}</strong>, your appointment is scheduled for<br>
                    <strong>${date} at ${selectedSlot.time}</strong>.<br><br>
                    <div style="background: #ECFDF5; padding: 15px; border-radius: 8px; border: 1px solid #10B981; color: #065F46; font-size: 14px;">
                        ${isMeet ? `🎥 <strong>Meeting Link:</strong> <a href="${data.meet_link}" target="_blank" style="color: #059669;">${data.meet_link}</a>` : `📍 <strong>Appointment Address:</strong> ${data.meet_link}`}
                        <br><br>
                        💬 <strong>Details have been sent to you via WhatsApp.</strong>
                    </div>
                `;
            } else {
                alert("Error: " + data.message);
                btn.disabled = false;
                btn.innerText = "Confirm Booking";
            }
        });
    };

    // Initial fetch
    fetchSlots();
</script>

</body>
</html>
