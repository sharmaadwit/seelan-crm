</div>
</div>

<style>
    footer {
        border-top: 1px solid var(--border);
        padding: 24px 20px;
        text-align: center;
        color: var(--text-muted);
        font-size: 13px;
        margin-top: 40px;
    }

    .ai-chat-fab-global {
        position: fixed;
        right: 20px;
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

    .ai-chat-fab-global span {
        font-size: 22px;
    }

    .ai-chat-panel-global {
        position: fixed;
        right: 20px;
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

    .ai-chat-widget {
        height: 100%;
        display: flex;
        flex-direction: column;
        background: #fff;
    }

    .ai-chat-header {
        background: linear-gradient(135deg, var(--primary) 0%, #7C3AED 100%);
        color: #fff;
        padding: 14px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }

    .ai-chat-title {
        font-weight: 700;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        white-space: nowrap;
    }

    .ai-chat-close {
        background: rgba(255, 255, 255, 0.15);
        color: #fff;
        border: none;
        border-radius: 10px;
        width: 34px;
        height: 34px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
    }

    .ai-chat-close:hover {
        background: rgba(255, 255, 255, 0.22);
    }

    .ai-chat-messages {
        flex: 1;
        overflow-y: auto;
        padding: 14px;
        background: #F9FAFB;
    }

    .ai-msg {
        margin-bottom: 10px;
        display: flex;
        gap: 10px;
        align-items: flex-end;
    }

    .ai-msg.user {
        justify-content: flex-end;
    }

    .ai-bubble {
        max-width: 78%;
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid var(--border);
        background: #fff;
        font-size: 13px;
        line-height: 1.5;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .ai-msg.user .ai-bubble {
        background: var(--primary);
        color: #fff;
        border: none;
    }

    .ai-icon {
        font-size: 18px;
    }

    .ai-chat-input-area {
        padding: 12px;
        border-top: 1px solid var(--border);
        background: #fff;
    }

    .ai-chat-form {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .ai-chat-input {
        flex: 1;
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 13px;
        font-family: inherit;
        outline: none;
    }

    .ai-chat-input:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.10);
    }

    .ai-chat-send {
        background: var(--primary);
        color: #fff;
        border: none;
        border-radius: 10px;
        padding: 10px 14px;
        font-weight: 700;
        cursor: pointer;
        font-size: 13px;
    }

    .ai-chat-send:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .ai-chat-hint {
        color: var(--text-muted);
        font-size: 12px;
        padding: 18px 12px;
        text-align: center;
    }
</style>

<footer>
    <p>&copy; <?php echo date('Y'); ?> Built with ❤️ - Team Gupshup</p>
</footer>

<button id="aiChatFab" class="ai-chat-fab-global" title="AI Analysis Chat">
    <span>🤖</span>
</button>
<div id="aiChatPanel" class="ai-chat-panel-global">
    <div class="ai-chat-widget">
        <div class="ai-chat-header">
            <div class="ai-chat-title" id="aiChatTitle">🤖 AI Analysis</div>
            <button class="ai-chat-close" type="button" id="aiChatCloseBtn" title="Close">×</button>
        </div>
        <div class="ai-chat-messages" id="aiChatMessages">
            <div class="ai-chat-hint" id="aiChatHint">
                Ask anything about your calendar or analytics.
            </div>
        </div>
        <div class="ai-chat-input-area">
            <form class="ai-chat-form" id="aiChatForm">
                <input type="text" class="ai-chat-input" id="aiChatInput" placeholder="Type your question..." autocomplete="off" required>
                <button type="submit" class="ai-chat-send" id="aiChatSendBtn">Send</button>
            </form>
        </div>
    </div>
</div>

<script>
    let aiChatCurrentType = 'calendar';

    function openAIChat(type) {
        aiChatCurrentType = type || aiChatCurrentType || 'calendar';
        const panel = document.getElementById('aiChatPanel');
        const title = document.getElementById('aiChatTitle');
        const hint = document.getElementById('aiChatHint');
        if (!panel) return;

        if (title) {
            title.textContent = (aiChatCurrentType === 'analytics') ? '🤖 AI Analysis (Analytics)' : '🤖 AI Analysis (Calendar)';
        }
        if (hint) {
            hint.innerHTML = (aiChatCurrentType === 'analytics')
                ? 'Ask anything about your leads and analytics.'
                : 'Ask anything about your calendar and appointments.';
        }
        panel.style.display = 'block';
        const input = document.getElementById('aiChatInput');
        if (input) input.focus();
    }

    function toggleAIChat() {
        const panel = document.getElementById('aiChatPanel');
        if (!panel) return;

        if (panel.style.display === 'block') {
            panel.style.display = 'none';
        } else {
            openAIChat(aiChatCurrentType || 'calendar');
        }
    }

    (function() {
        const fab = document.getElementById('aiChatFab');
        const closeBtn = document.getElementById('aiChatCloseBtn');
        const form = document.getElementById('aiChatForm');
        const input = document.getElementById('aiChatInput');
        const sendBtn = document.getElementById('aiChatSendBtn');
        const messages = document.getElementById('aiChatMessages');
        const hint = document.getElementById('aiChatHint');

        function escapeHtml(text) {
            const map = { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' };
            return String(text || '').replace(/[&<>"']/g, m => map[m]);
        }

        function addMessage(role, text) {
            if (!messages) return;
            if (hint) hint.remove();

            const row = document.createElement('div');
            row.className = 'ai-msg ' + (role === 'user' ? 'user' : 'ai');
            row.innerHTML = (role === 'user')
                ? `<div class="ai-bubble">${escapeHtml(text)}</div><div class="ai-icon">👤</div>`
                : `<div class="ai-icon">🤖</div><div class="ai-bubble">${escapeHtml(text)}</div>`;
            messages.appendChild(row);
            messages.scrollTop = messages.scrollHeight;
        }

        if (fab) {
            fab.addEventListener('click', function(e) {
                e.preventDefault();
                toggleAIChat();
            });
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                const panel = document.getElementById('aiChatPanel');
                if (panel) panel.style.display = 'none';
            });
        }

        if (form) {
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                if (!input || !sendBtn) return;
                const q = input.value.trim();
                if (!q) return;

                addMessage('user', q);
                input.value = '';
                sendBtn.disabled = true;
                sendBtn.textContent = '...';

                try {
                    const body = new URLSearchParams();
                    body.set('type', aiChatCurrentType || 'calendar');
                    body.set('query', q);

                    const res = await fetch('ai_chat.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body
                    });
                    const data = await res.json();
                    if (data.status === 'success') {
                        addMessage('ai', data.answer || 'OK');
                    } else {
                        addMessage('ai', data.message || 'Error from AI.');
                    }
                } catch (err) {
                    addMessage('ai', 'Failed to contact AI. Please try again.');
                } finally {
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Send';
                    input.focus();
                }
            });
        }
    })();
</script>

</body>
</html>