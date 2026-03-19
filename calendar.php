<?php 
require_once 'db.php';
require_once 'header.php';

// Fetch appointment statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_scheduled,
        COUNT(CASE WHEN DATE(start_time) = CURDATE() THEN 1 END) as today_count,
        COUNT(CASE WHEN DATE(start_time) > CURDATE() THEN 1 END) as upcoming_count,
        COUNT(CASE WHEN status = 'Completed' THEN 1 END) as completed_count,
        COUNT(CASE WHEN status = 'Cancelled' THEN 1 END) as cancelled_count
    FROM appointments 
    WHERE org_id = ? AND status IN ('Scheduled', 'Completed', 'Cancelled')
");
$stmt->execute([$current_org_id]);
$stats = $stmt->fetch();
?>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>

<style>
/* Base typography and layout */
body {
    background: #f4f7fe;
}

.calendar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
    gap: 15px;
}

.calendar-header h1 {
    margin: 0;
    font-size: 38px;
    font-weight: 800;
    color: #1e293b;
    letter-spacing: -0.5px;
}

.calendar-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 20px;
    margin-bottom: 35px;
}

.stat-card {
    background: rgba(255, 255, 255, 0.9);
    border: none;
    border-radius: 16px;
    padding: 24px;
    text-align: center;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 25px rgba(79, 70, 229, 0.12);
}

.stat-number {
    font-size: 36px;
    font-weight: 900;
    color: var(--primary);
    line-height: 1.2;
    margin: 10px 0;
}

.stat-label {
    font-size: 13px;
    color: var(--text-muted);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

/* Glassmorphism calendar container */
.calendar-container {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(229, 231, 235, 0.5);
    border-radius: 20px;
    padding: 30px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.04);
}

/* FullCalendar Custom Styling */
.fc {
    font-family: inherit;
    color: #334155;
}

.fc-theme-standard td, .fc-theme-standard th, .fc-theme-standard .fc-scrollgrid {
    border-color: #f1f5f9;
}

.fc .fc-button-primary {
    background-color: var(--primary);
    border: none;
    border-radius: 8px;
    font-weight: 600;
    text-transform: capitalize;
    box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2);
    padding: 10px 20px;
    transition: all 0.2s ease;
}

.fc .fc-button-primary:hover {
    background-color: #4338CA;
    transform: translateY(-1px);
    box-shadow: 0 6px 15px rgba(79, 70, 229, 0.3);
}

.fc .fc-button-primary:not(:disabled).fc-button-active {
    background-color: #312e81;
}

.fc .fc-toolbar-title {
    font-weight: 800;
    color: #0f172a;
    font-size: 26px;
}

.fc .fc-daygrid-day.fc-day-today, .fc .fc-timegrid-col.fc-day-today {
    background-color: #f8fafc;
}

.fc .fc-event {
    border-radius: 6px;
    border: none;
    padding: 4px 6px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.08);
    font-size: 12px;
}

.fc .fc-event-title {
    font-weight: 600;
}

.fc .fc-col-header-cell {
    padding: 16px 4px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    font-size: 12px;
    letter-spacing: 0.5px;
}

.fc .fc-timegrid-slot {
    height: 2.5em;
}

.calendar-instructions {
    background: linear-gradient(135deg, #F0F9FF 0%, #E0F2FE 100%);
    border: 1px solid #BAE6FD;
    border-radius: 12px;
    padding: 20px;
    margin-top: 25px;
    color: #0369A1;
    font-size: 14px;
    line-height: 1.6;
    box-shadow: 0 2px 10px rgba(0,0,0,0.02);
}

.calendar-instructions h4 {
    margin-top: 0;
    margin-bottom: 10px;
    color: #0C4A6E;
    font-weight: 600;
}

.calendar-instructions ul {
    margin: 8px 0;
    padding-left: 20px;
}

.calendar-instructions li {
    margin: 6px 0;
}

/* AI Chat panel */
.ai-chat-fab {
    position: fixed;
    left: 20px;
    bottom: 20px;
    width: 48px;
    height: 48px;
    border-radius: 999px;
    background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.35);
    cursor: pointer;
    z-index: 1200;
    border: none;
}

.ai-chat-fab span {
    font-size: 22px;
}

.ai-chat-panel {
    position: fixed;
    left: 20px;
    bottom: 80px;
    width: 360px;
    max-width: 90vw;
    height: 520px;
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.45);
    overflow: hidden;
    border: 1px solid rgba(148, 163, 184, 0.5);
    z-index: 1199;
    display: none;
}

.ai-chat-panel iframe {
    width: 100%;
    height: 100%;
    border: none;
}

.action-modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-content {
    background-color: #fefefe;
    margin: 6vh auto;
    padding: 18px;
    border-radius: 12px;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    animation: slideDown 0.3s ease;
    max-height: 88vh;
    display: flex;
    flex-direction: column;
}

@keyframes slideDown {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    border-bottom: 1px solid var(--border);
    padding-bottom: 15px;
}

.modal-header h2 {
    margin: 0;
    font-size: 20px;
    color: var(--text-main);
}

.close-btn {
    font-size: 28px;
    font-weight: bold;
    color: var(--text-muted);
    cursor: pointer;
    border: none;
    background: none;
    padding: 0;
}

.close-btn:hover {
    color: var(--text-main);
}

.modal-body {
    margin-bottom: 12px;
    overflow-y: auto;
    padding-right: 4px;
}

.modal-body label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--text-main);
    font-size: 14px;
}

.modal-body input,
.modal-body textarea {
    width: 100%;
    padding: 10px;
    margin-bottom: 15px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
    box-sizing: border-box;
}

.modal-body textarea {
    resize: vertical;
    min-height: 100px;
}

.modal-body input:focus,
.modal-body textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
}

.modal-buttons {
    display: flex;
    gap: 10px;
    position: sticky;
    bottom: 0;
    background: #fff;
    padding-top: 10px;
}

.btn {
    flex: 1;
    padding: 10px;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s ease;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: #4338CA;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
}

.btn-danger {
    background: #EF4444;
    color: white;
}

.btn-danger:hover {
    background: #DC2626;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.btn-cancel {
    background: var(--border);
    color: var(--text-main);
}

.btn-cancel:hover {
    background: #D1D5DB;
}

@media (max-width: 768px) {
    .calendar-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .calendar-header h1 {
        font-size: 24px;
    }

    .calendar-stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .fc .fc-header-toolbar {
        flex-direction: column;
        gap: 10px;
    }

    .fc .fc-button {
        padding: 6px 8px;
        font-size: 12px;
    }
}
</style>

<div class="calendar-header">
    <h1>📅 Appointment Calendar</h1>
    <div style="display: flex; gap: 10px;">
        <button onclick="openAIAnalysis('calendar')" style="background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%); color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s ease;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(139, 92, 246, 0.3)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
            🤖 AI Analysis
        </button>
        <button onclick="openAddModal()" style="background: var(--primary); color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s ease;" onmouseover="this.style.background='#4338CA'; this.style.transform='translateY(-2px)'" onmouseout="this.style.background='var(--primary)'; this.style.transform='translateY(0)'">
            + Add Appointment
        </button>
    </div>
</div>

<div class="calendar-stats">
    <div class="stat-card">
        <div class="stat-label">📅 Total Scheduled</div>
        <div class="stat-number"><?php echo $stats['total_scheduled']; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">📍 Today</div>
        <div class="stat-number"><?php echo $stats['today_count']; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">⏰ Upcoming</div>
        <div class="stat-number"><?php echo $stats['upcoming_count']; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">✅ Completed</div>
        <div class="stat-number"><?php echo $stats['completed_count']; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">❌ Cancelled</div>
        <div class="stat-number"><?php echo $stats['cancelled_count']; ?></div>
    </div>
</div>

<div class="calendar-container">
    <div id="calendar"></div>
</div>

<div class="calendar-instructions">
    <h4>💡 How to Use the Calendar</h4>
    <ul>
        <li><strong>Drag & Drop:</strong> Click and drag an appointment to reschedule (WhatsApp notification will be sent)</li>
        <li><strong>Click Event:</strong> Click an appointment to cancel or edit it manually</li>
        <li><strong>Add Appointment:</strong> Use the "+ Add Appointment" button to create a new appointment</li>
        <li><strong>Change View:</strong> Switch between Month, Week, or Day view using the buttons at the top</li>
    </ul>
</div>

<!-- Modal for Event Actions -->
<div id="actionModal" class="action-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Event Actions</h2>
            <button class="close-btn" onclick="closeActionModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">
            <!-- Content will be inserted here -->
        </div>
        <div class="modal-buttons">
            <button class="btn btn-cancel" onclick="closeActionModal()">Close</button>
            <button class="btn btn-primary" id="submitBtn" onclick="submitAction()">Confirm</button>
        </div>
    </div>
</div>

<script>
let currentEventId = null;
let currentAction = null;

document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'timeGridWeek',
        headerToolbar: { 
            left: 'prev,next today', 
            center: 'title', 
            right: 'dayGridMonth,timeGridWeek,timeGridDay' 
        },
        slotMinTime: '00:00:00',
        slotMaxTime: '24:00:00',
        scrollTime: '08:00:00',
        editable: true,
        height: 'auto',
        contentHeight: 'auto',
        
        events: function(fetchInfo, successCallback, failureCallback) {
            fetch('calendar_actions.php?action=fetch')
                .then(response => response.json())
                .then(data => {
                    successCallback(data);
                })
                .catch(err => {
                    console.error('Error fetching events:', err);
                    failureCallback(err);
                });
        },

        eventDrop: function(info) {
            showRescheduleModal(info);
        },

        eventClick: function(info) {
            showEventActionModal(info);
        }
    });
    calendar.render();
    window.calendarInstance = calendar;
});

function showRescheduleModal(info) {
    currentEventId = info.event.id;
    currentAction = 'reschedule';
    
    const startTime = info.event.start.toISOString().slice(0, 16);
    const endTime = info.event.end ? info.event.end.toISOString().slice(0, 16) : startTime;
    
    document.getElementById('modalTitle').textContent = '📅 Reschedule Appointment';
    document.getElementById('modalBody').innerHTML = `
        <label>Patient: <strong>${info.event.title}</strong></label>
        <label>New Start Time</label>
        <input type="datetime-local" id="newStart" value="${startTime}" required>
        <label>New End Time</label>
        <input type="datetime-local" id="newEnd" value="${endTime}" required>
        <label>
            <input type="checkbox" id="sendNotif" checked> Send WhatsApp notification to patient
        </label>
    `;
    
    document.getElementById('submitBtn').textContent = 'Reschedule';
    document.getElementById('submitBtn').className = 'btn btn-primary';
    document.getElementById('actionModal').style.display = 'block';
}

function showEventActionModal(info) {
    currentEventId = info.event.id;
    
    document.getElementById('modalTitle').textContent = 'Event Actions';
    document.getElementById('modalBody').innerHTML = `
        <p><strong>Patient:</strong> ${info.event.title}</p>
        <p><strong>Time:</strong> ${info.event.start.toLocaleString()}</p>
        <p style="margin-top: 20px; font-weight: 600;">Choose an action:</p>
        <div style="display: flex; gap: 10px; margin-top: 15px;">
            <button class="btn btn-primary" onclick="setActionAndShow('edit')">✏️ Edit Time</button>
            <button class="btn btn-danger" onclick="setActionAndShow('cancel')">🗑️ Cancel</button>
        </div>
    `;
    
    document.getElementById('submitBtn').style.display = 'none';
    document.getElementById('actionModal').style.display = 'block';
}

function setActionAndShow(action) {
    currentAction = action;
    
    if (action === 'edit') {
        document.getElementById('modalTitle').textContent = '✏️ Edit Appointment Time';
        document.getElementById('modalBody').innerHTML = `
            <label>New Start Time</label>
            <input type="datetime-local" id="newStart" required>
            <label>New End Time</label>
            <input type="datetime-local" id="newEnd" required>
        `;
        document.getElementById('submitBtn').textContent = 'Save Changes';
        document.getElementById('submitBtn').className = 'btn btn-primary';
        document.getElementById('submitBtn').style.display = 'block';
    } else if (action === 'cancel') {
        if (confirm('Are you sure you want to cancel this appointment? A WhatsApp notification will be sent to the patient.')) {
            cancelAppointment();
        }
    }
}

function submitAction() {
    if (currentAction === 'create') {
        const name   = document.getElementById('patientName').value.trim();
        const mobile = document.getElementById('patientMobile').value.trim();
        const start  = document.getElementById('startTime').value;
        const end    = document.getElementById('endTime').value;

        if (!name || !mobile || !start || !end) {
            alert('Please fill in all fields');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('name', name);
        formData.append('mobile', mobile);
        formData.append('start', start.replace('T', ' '));
        formData.append('end', end.replace('T', ' '));

        fetch('calendar_actions.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    alert('✅ Appointment created successfully!');
                    closeActionModal();
                    if (window.calendarInstance) {
                        window.calendarInstance.refetchEvents();
                    }
                } else {
                    alert('❌ Error: ' + (data.message || 'Unable to create appointment'));
                }
            })
            .catch(err => {
                console.error('Error:', err);
                alert('Error creating appointment');
            });
    } else if (currentAction === 'reschedule' || currentAction === 'edit') {
        const newStart = document.getElementById('newStart').value;
        const newEnd = document.getElementById('newEnd').value;
        
        if (!newStart || !newEnd) {
            alert('Please fill in both start and end times');
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('id', currentEventId);
        formData.append('start', newStart.replace('T', ' '));
        formData.append('end', newEnd.replace('T', ' '));
        
        fetch('calendar_actions.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    alert('✅ Appointment updated and patient notified!');
                    closeActionModal();
                    window.calendarInstance.refetchEvents();
                } else {
                    alert('❌ Error: ' + data.message);
                }
            })
            .catch(err => {
                console.error('Error:', err);
                alert('Error updating appointment');
            });
    }
}

function cancelAppointment() {
    const formData = new FormData();
    formData.append('action', 'cancel');
    formData.append('id', currentEventId);
    
    fetch('calendar_actions.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                alert('✅ Appointment cancelled and patient notified!');
                closeActionModal();
                window.calendarInstance.refetchEvents();
            } else {
                alert('❌ Error: ' + data.message);
            }
        })
        .catch(err => {
            console.error('Error:', err);
            alert('Error cancelling appointment');
        });
}

function closeActionModal() {
    document.getElementById('actionModal').style.display = 'none';
    currentEventId = null;
    currentAction = null;
}

function openAIAnalysis(type) {
    if (typeof openAIChat === 'function') {
        openAIChat(type || 'calendar');
    }
}

function openAddModal() {
    currentAction = 'create';
    document.getElementById('modalTitle').textContent = '➕ Add Appointment';
    document.getElementById('modalBody').innerHTML = `
        <label>Patient Name</label>
        <input type="text" id="patientName" placeholder="Full name" required>
        <label>Mobile Number</label>
        <input type="text" id="patientMobile" placeholder="WhatsApp number with country code" required>
        <label>Start Time</label>
        <input type="datetime-local" id="startTime" required>
        <label>End Time</label>
        <input type="datetime-local" id="endTime" required>
        <p style="font-size:12px; color:var(--text-muted); margin-top:4px;">
            A Google Meet link will be generated automatically. Patient will receive WhatsApp confirmation if templates are configured.
        </p>
    `;
    document.getElementById('submitBtn').textContent = 'Create Appointment';
    document.getElementById('submitBtn').className = 'btn btn-primary';
    document.getElementById('submitBtn').style.display = 'block';
    document.getElementById('actionModal').style.display = 'block';
}

// Close modal when clicking outside of it
window.onclick = function(event) {
    var modal = document.getElementById('actionModal');
    if (event.target == modal) {
        closeActionModal();
    }
}
</script>

<?php require_once 'footer.php'; ?>