<?php
/**
 * Reusable Chat Widget Component
 * 
 * Requirements before including this file:
 * - Session must be started with guest_token, room_number, customer_id
 * - ROOM constant must be defined: define('ROOM', $_SESSION['room_number']);
 */

if (empty($_SESSION['guest_token']) || !defined('ROOM')) {
    exit('Chat widget requires active guest session and ROOM constant');
}

$guestPortalToken = (string)($_SESSION['guest_token'] ?? '');
?>

<style>
/* ── Chat Widget Styling ── */
#chatToggleBtn {
    position: fixed;
    right: 16px;
    bottom: calc(72px + env(safe-area-inset-bottom) + 14px);
    z-index: 1030;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 52px;
    height: 52px;
    padding: 0;
    border: 1px solid rgba(255, 255, 255, .12);
    border-radius: 50%;
    background: var(--marle-brown, #263431);
    color: #fff;
    font-size: 1.2rem;
    box-shadow: 0 12px 26px rgba(35, 50, 45, .22);
    cursor: pointer;
    transition: background-color .2s ease, transform .2s ease;
}

#chatToggleBtn:hover {
    background: var(--marle-rust, #a24f35);
    transform: translateY(-1px);
}

@media (min-width: 768px) {
    #chatToggleBtn {
        right: 22px;
        bottom: 22px;
        width: 56px;
        height: 56px;
    }
}

.chat-close-btn {
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    margin: -4px -6px -4px 0;
    border: 1px solid rgba(255, 255, 255, .25);
    border-radius: 50%;
    background: transparent;
    color: #fff;
    font-size: 1rem;
    line-height: 1;
    cursor: pointer;
}

.chat-close-btn:hover { background: rgba(255, 255, 255, .12); }

.chat-unread-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    min-width: 20px;
    height: 20px;
    padding: 0 5px;
    border: 2px solid #fff;
    border-radius: 999px;
    background: #dc3545;
    color: #fff;
    font-size: 11px;
    line-height: 16px;
    text-align: center;
}

.chat-unread-badge[hidden],
.chat-reply-toast[hidden] {
    display: none;
}

.chat-reply-toast {
    position: fixed;
    right: 20px;
    bottom: 220px;
    z-index: 1100;
    display: flex;
    align-items: center;
    gap: 8px;
    max-width: min(320px, calc(100vw - 32px));
    padding: 12px 14px;
    border-radius: 10px;
    background: #212529;
    color: #fff;
    box-shadow: 0 8px 24px rgba(0, 0, 0, .2);
    font-size: 13px;
}

#chatWidget {
    position: fixed;
    right: 22px;
    bottom: 90px;
    z-index: 2050;
    width: 360px;
    height: 560px;
    max-height: calc(100dvh - 120px);
    background: var(--marle-card, #fffdf9);
    border: 1px solid var(--marle-line, #dde4df);
    border-radius: 18px;
    box-shadow: 0 24px 60px rgba(35, 50, 45, .22);
    overflow: hidden;
    display: none;
    flex-direction: column;
}

#chatWidget[hidden] { display: none; }#chatHeader {
    background: var(--marle-brown, #263431);
    padding: 14px 16px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border-bottom: 1px solid rgba(255, 255, 255, .12);
    flex-shrink: 0;
}

.chat-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-family: var(--m-display, Georgia, serif);
    font-size: 1.05rem;
    font-weight: 500;
}

.chat-title__meta {
    display: block;
    margin-top: 1px;
    color: rgba(255, 255, 255, .6);
    font-family: var(--m-mono, monospace);
    font-size: .55rem;
    letter-spacing: .12em;
    text-transform: uppercase;
}

.chat-title__dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #7bd39a;
    box-shadow: 0 0 0 3px rgba(123, 211, 154, .18);
    flex: 0 0 auto;
}

/* Message bubbles */
.chat-msg { display: flex; margin-bottom: 10px; }
.chat-msg--guest { justify-content: flex-end; }
.chat-msg--admin { justify-content: flex-start; }
.chat-msg__bubble {
    max-width: 82%;
    padding: 10px 13px;
    border-radius: 14px;
    font-size: .88rem;
    line-height: 1.45;
    word-break: break-word;
}
.chat-msg--guest .chat-msg__bubble {
    background: var(--marle-brown, #263431);
    color: #fff;
    border-bottom-right-radius: 4px;
}
.chat-msg--admin .chat-msg__bubble {
    background: #fff;
    color: var(--marle-ink, #1d2525);
    border: 1px solid var(--marle-line, #dde4df);
    border-bottom-left-radius: 4px;
}

.chat-close-btn {
    background: none;
    border: none;
    color: #fff;
    font-size: 18px;
    cursor: pointer;
    padding: 0;
    line-height: 1;
    transition: transform 0.2s;
}

.chat-close-btn:hover {
    transform: scale(1.2);
}

#chatMessages {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
    background: #f3f5f1;
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-height: 200px;
}

#chatMessages::-webkit-scrollbar {
    width: 6px;
}

#chatMessages::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

#chatMessages::-webkit-scrollbar-thumb {
    background: #ffb60a;
    border-radius: 3px;
}

#guestFilePreviewBar {
    display: none;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    background: #fff3cd;
    border-top: 1px solid #ffc107;
    font-size: 12px;
    flex-shrink: 0;
}

#guestFilePreviewBar.show {
    display: flex;
}

#guestFileError {
    color: #dc3545;
    font-size: 11px;
    padding: 2px 10px;
    background: #fff;
    flex-shrink: 0;
}

#chatInputBar {
    display: flex;
    padding: 10px;
    gap: 8px;
    background: var(--marle-card, #fffdf9);
    border-top: 1px solid var(--marle-line, #dde4df);
    flex-shrink: 0;
    align-items: center;
}

#chatInputBar .bi-paperclip {
    font-size: 20px;
    cursor: pointer;
    color: var(--marle-gold-deep, #936126);
    transition: transform 0.2s;
    flex-shrink: 0;
}

#chatInputBar .bi-paperclip:hover {
    transform: scale(1.1);
}

#chatInput {
    flex: 1;
    min-width: 0;
    border: 1px solid var(--marle-line, #dde4df);
    border-radius: 999px;
    font-size: 14px;
    padding: 8px 14px;
    background: #fff;
}

#chatInput:focus {
    border-color: var(--marle-gold, #c38a45);
    box-shadow: 0 0 0 3px rgba(195, 138, 69, .16);
    outline: none;
}

#chatInputBar .btn {
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    border: none;
    border-radius: 50%;
    background: var(--marle-brown, #263431);
    color: #fff;
    padding: 0;
    cursor: pointer;
    transition: background 0.2s;
    flex-shrink: 0;
}

#chatInputBar .btn:hover { background: var(--marle-rust, #a24f35); }

#imagePreviewOverlay {
    display: none !important;
    position: fixed;
    inset: 0;
    z-index: 2001;
    background: rgba(0, 0, 0, 0.85);
    align-items: center;
    justify-content: center;
    padding: 18px;
}

#imagePreviewOverlay.show {
    display: flex !important;
}

/* ── Chat Timestamp Styling ── */
.chat-timestamp {
    font-family: var(--m-mono, monospace);
    font-size: 9.5px;
    letter-spacing: .02em;
    margin-top: 5px;
    display: block;
}

.chat-timestamp.guest-ts {
    color: rgba(255, 255, 255, 0.62);
    text-align: right;
}

.chat-timestamp.admin-ts {
    color: var(--marle-muted, #71807a);
    text-align: left;
}

.ts-read {
    display: block;
    font-size: 10px;
    color: rgba(255, 255, 255, 0.6);
    margin-top: 2px;
}

/* ── Mobile Responsive Styling ── */
@media (max-width: 767.98px) {
    #chatMessages { min-height: 0; }

    #chatInput {
        font-size: 16px;
        padding: 9px 12px;
    }

    #chatInputBar { gap: 8px; }

    #chatInputBar .bi-paperclip { font-size: 19px; }

    .chat-reply-toast {
        right: 12px;
        bottom: calc(72px + env(safe-area-inset-bottom) + 78px);
    }

    /* The image viewer must sit above the full-screen conversation. */
    #imagePreviewOverlay { z-index: 2100; }

    /* A guest on a phone gets the whole screen — the old 320px card sat on top
       of the page and was too narrow to read a conversation in. These rules
       live after the panel's own, because the media query alone would not win
       against a later `#chatWidget` rule of the same specificity. */
    body.chat-open { overflow: hidden; }

    #chatWidget {
        inset: 0;
        right: auto;
        bottom: auto;
        width: 100%;
        max-width: none;
        height: 100vh;
        height: 100dvh;
        max-height: none;
        border: 0;
        border-radius: 0;
    }

    #chatHeader { padding: calc(14px + env(safe-area-inset-top)) 16px 14px; }
    #chatMessages { padding: 16px; }
    #chatInputBar { padding: 10px 12px calc(10px + env(safe-area-inset-bottom)); }

    /* The launcher stays: with the panel full-screen there is nowhere else on a
       phone to open the conversation from. */
    #chatToggleBtn {
        right: 14px;
        bottom: calc(env(safe-area-inset-bottom) + 78px);
        width: 50px;
        height: 50px;
    }

    body.chat-open #chatToggleBtn { visibility: hidden; }
}
</style>

<!-- Chat Button (floating) -->
<button id="chatToggleBtn" aria-label="Message the front desk" aria-controls="chatWidget" aria-expanded="false">
    <i class="bi bi-chat-dots-fill" aria-hidden="true"></i>
    <span id="chatUnreadBadge" class="chat-unread-badge" hidden>0</span>
</button>

<!-- Chat Widget -->
<div id="chatWidget" role="dialog" aria-modal="true" aria-labelledby="chatHeaderTitle">

    <div id="chatHeader">
        <span class="chat-title">
            <span class="chat-title__dot" aria-hidden="true"></span>
            <span id="chatHeaderTitle">Front Desk
                <span class="chat-title__meta">Messages are answered by staff</span>
            </span>
        </span>
        <button class="chat-close-btn" type="button" onclick="closeChat()" aria-label="Close chat">&#x2715;</button>
    </div>

    <div id="chatMessages">
        <!-- Messages load here -->
    </div>

    <!-- Feature 3: file preview bar -->
    <div id="guestFilePreviewBar">
        <span id="guestFileIcon" style="font-size:16px;"></span>
        <span id="guestFileName" style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"></span>
        <img id="guestFileThumb" src="" alt="" style="display:none; max-height:36px; border-radius:4px;">
        <button onclick="clearGuestAttachment()"
                style="background:none; border:none; font-size:14px; cursor:pointer;">&#x2715;</button>
    </div>
    <div id="guestFileError" style="display:none;"></div>

    <!-- Closed chat indicator — shown when admin marks chat complete -->
    <div id="chatClosedBar"
         style="display:none; padding:10px 14px; background:#f8d7da; color:#842029;
                font-size:13px; text-align:center; border-top:1px solid #f5c2c7;">
        <i class="bi bi-lock-fill me-1"></i>
        This chat has been closed by staff. Please contact the front desk directly.
    </div>

    <div id="chatInputBar">
        <input type="file" id="guestAttachInput"
               accept=".jpg,.jpeg,.png,.gif,.pdf,.docx,.xlsx"
               style="display:none;" onchange="handleGuestFile(this)">
        <i class="bi bi-paperclip"
           onclick="document.getElementById('guestAttachInput').click()"
           title="Attach file (max 5 MB)"></i>
        <input id="chatInput" type="text" class="form-control"
               placeholder="Type a message...">
        <button onclick="sendGuestMessage()"
                class="btn">
            <i class="bi bi-send"></i>
        </button>
    </div>
</div>

<div id="imagePreviewOverlay">
    <div style="position:relative; max-width:100%; max-height:100%; width:100%; max-width:720px;">
        <button onclick="closeChatImagePreview()"
                style="position:absolute; top:10px; right:10px; background:rgba(255,255,255,0.95); border:none; border-radius:50%; width:34px; height:34px; font-size:18px; cursor:pointer;">
            &times;
        </button>
        <img id="imagePreviewContent" src="" alt="Image preview"
             style="display:block; max-width:100%; max-height:100vh; margin:0 auto; border-radius:14px; box-shadow:0 10px 30px rgba(0,0,0,0.35);" />
    </div>
</div>

<div id="chatReplyToast" class="chat-reply-toast" role="status" aria-live="polite" hidden>
    <i class="bi bi-chat-left-text-fill"></i>
    <span>Front Desk replied to your chat.</span>
</div>

<script>
const ROOM = "<?= htmlspecialchars(ROOM, ENT_QUOTES, 'UTF-8') ?>";
let guestSelectedFile = null;

/**
 * Format a MySQL datetime string into a human-readable chat timestamp.
 *   - Today:     "2:45 PM"
 *   - Yesterday: "Yesterday, 2:45 PM"
 *   - Older:     "Mar 31, 9:12 AM"
 */
function formatChatTime(datetimeStr) {
    if (!datetimeStr) return '';
    // A stamp is MySQL's own wall clock — the hotel's local time — so its date and time
    // parts are used exactly as written, and "today"/"yesterday" come from the server's
    // clock (includes/server_clock.php), never from the guest's device. A phone whose
    // clock was out used to label this morning's message "Yesterday".
    const c = window.MarleClock;
    if (!c) return String(datetimeStr);
    const parts = c.wallParts(datetimeStr);
    if (!parts) return '';

    const timeStr = c.formatWallTime(datetimeStr);

    if (parts.iso === c.dayIso(0))  return timeStr;                 // today
    if (parts.iso === c.dayIso(-1)) return 'Yesterday, ' + timeStr; // yesterday
    return c.formatWallDate(datetimeStr, true) + ', ' + timeStr;    // older
}

const chatWidgetEl = document.getElementById('chatWidget');
const chatToggleEl = document.getElementById('chatToggleBtn');
let chatReturnFocus = null;

function openChat() {
    chatReturnFocus = document.activeElement;
    chatWidgetEl.style.display = 'flex';
    document.body.classList.add('chat-open');
    chatToggleEl.setAttribute('aria-expanded', 'true');
    loadChat(true);
    const input = document.getElementById('chatInput');
    if (input && window.matchMedia('(min-width: 768px)').matches) {
        setTimeout(function () { input.focus({ preventScroll: true }); }, 80);
    }
}

function closeChat() {
    chatWidgetEl.style.display = 'none';
    document.body.classList.remove('chat-open');
    chatToggleEl.setAttribute('aria-expanded', 'false');
    if (chatReturnFocus && typeof chatReturnFocus.focus === 'function') {
        chatReturnFocus.focus({ preventScroll: true });
    } else {
        chatToggleEl.focus({ preventScroll: true });
    }
}

chatToggleEl.addEventListener('click', openChat);

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && chatWidgetEl.style.display === 'flex') {
        event.preventDefault();
        closeChat();
    }
});

document.getElementById('chatInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') sendGuestMessage();
});

async function loadChat(markRead = false) {
    if (markRead) {
        await fetch('chat_mark_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'room=' + encodeURIComponent(ROOM)
        });
    }

    fetch('chat_fetch.php?room=' + encodeURIComponent(ROOM))
        .then(r => r.json())
        .then(data => {
            // chat_fetch.php now returns { messages: [...], chat_status: '...' }
            const msgs       = data.messages   ?? [];
            const chatStatus = data.chat_status ?? 'new';
            updateChatUnreadCount(markRead ? 0 : (data.unread_admin_count ?? 0));

            const box = document.getElementById('chatMessages');
            box.innerHTML = '';
            msgs.forEach(m => {
                const isGuest = m.sender === 'guest';
                const attachHtml = renderAttachment(m, isGuest);
                const textHtml   = escHtml(m.message);

                // ── Timestamp block ──
                const sentLabel = formatChatTime(m.sent_at);
                let tsHtml = '';
                if (isGuest) {
                    // Guest's own message: show sent time + read receipt (if admin has read it)
                    const readLabel = m.read_at ? '<span class="ts-read">Read ' + formatChatTime(m.read_at) + '</span>' : '';
                    tsHtml = `<div class="chat-timestamp guest-ts">Sent ${sentLabel}${readLabel}</div>`;
                } else {
                    // Admin message shown to guest: show received/sent time
                    tsHtml = `<div class="chat-timestamp admin-ts">${sentLabel}</div>`;
                }

                box.innerHTML += `
                    <div class="chat-msg ${isGuest ? 'chat-msg--guest' : 'chat-msg--admin'}">
                        <div class="chat-msg__bubble">
                            ${textHtml}${textHtml && attachHtml ? '<br>' : ''}${attachHtml}
                            ${tsHtml}
                        </div>
                    </div>`;
            });
            box.scrollTop = box.scrollHeight;

            // ── Show/hide closed-chat indicator based on status ───────────
            const closedBar = document.getElementById('chatClosedBar');
            const inputBar  = document.getElementById('chatInputBar');
            if (chatStatus === 'complete') {
                if (closedBar) closedBar.style.display = 'block';
                if (inputBar)  inputBar.style.display  = 'none';
            } else {
                if (closedBar) closedBar.style.display = 'none';
                if (inputBar)  inputBar.style.display  = 'flex';
            }
        });
}

let previousChatUnreadCount = 0;
function updateChatUnreadCount(count) {
    const badge = document.getElementById('chatUnreadBadge');
    const toast = document.getElementById('chatReplyToast');
    const widget = document.getElementById('chatWidget');
    const nextCount = Math.max(0, Number(count) || 0);

    if (badge) {
        badge.textContent = nextCount > 99 ? '99+' : String(nextCount);
        badge.hidden = nextCount === 0;
    }

    if (nextCount > previousChatUnreadCount && widget && widget.style.display !== 'flex' && toast) {
        toast.hidden = false;
        window.clearTimeout(window.chatReplyToastTimer);
        window.chatReplyToastTimer = window.setTimeout(() => { toast.hidden = true; }, 5000);
    }
    previousChatUnreadCount = nextCount;
}

let previousRequestUpdates = null;
async function pollGuestRequestUpdates() {
    try {
        const response = await fetch('fetch_request_updates.php', { credentials: 'same-origin' });
        if (!response.ok) return;
        const data = await response.json();
        if (!data.success || !Array.isArray(data.updates)) return;

        const updates = {};
        data.updates.forEach(update => { updates[update.id] = update; });
        if (previousRequestUpdates) {
            Object.values(updates).forEach(update => {
                const previous = previousRequestUpdates[update.id];
                const changed = previous && (
                    previous.status !== update.status
                    || previous.latest_reply_at !== update.latest_reply_at
                );
                if (changed) {
                    const label = update.type.charAt(0).toUpperCase() + update.type.slice(1);
                    const detail = update.latest_reply || ('Request is now ' + update.status + '.');
                    showGuestReplyToast(label + ': ' + detail);
                }
            });
        }
        previousRequestUpdates = updates;
    } catch (error) {
        console.warn('Guest request update poll failed:', error);
    }
}

function showGuestReplyToast(message) {
    const toast = document.getElementById('chatReplyToast');
    if (!toast) return;
    const text = toast.querySelector('span');
    if (text) text.textContent = message;
    toast.hidden = false;
    window.clearTimeout(window.chatReplyToastTimer);
    window.chatReplyToastTimer = window.setTimeout(() => { toast.hidden = true; }, 6000);
}

function renderAttachment(m, isGuest) {
    if (!m.attachment_path) return '';

    const attachmentUrl  = buildAttachmentUrl(m.attachment_path);
    const attachmentName = escHtml(m.attachment_name || m.attachment_path.split('/').pop() || 'attachment');
    const isImg          = m.attachment_type && m.attachment_type.startsWith('image/');
    const bgColor        = isGuest ? '#ffffff' : '#f8f9fa';
    const textColor      = '#212529';
    const border         = '1px solid rgba(0,0,0,0.08)';
    const cardStyle      = `display:inline-flex; flex-direction:column; gap:6px; width:auto; min-width:160px; max-width:220px; margin-top:8px; padding:10px; background:${bgColor}; color:${textColor}; border:${border}; border-radius:12px;`;

    if (isImg) {
        return `<div style="${cardStyle}">
                    <img src="${attachmentUrl}"
                         onclick="openChatImagePreview('${attachmentUrl}')"
                         onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend', '<div style=&quot;font-size:12px;color:#495057;&quot;>Unable to preview image. <a href=&quot;${attachmentUrl}&quot; target=&quot;_blank&quot; style=&quot;color:#0d6efd;&quot;>Open file</a></div>');"
                         style="width:100%; max-width:220px; height:auto; border-radius:10px; display:block; cursor:pointer;"
                         alt="${attachmentName}">
                    <span style="font-size:12px; color:#495057;">${attachmentName}</span>
                </div>`;
    }

    return `<div style="${cardStyle}">
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <span style="font-size:16px;">📎</span>
                    <a href="${attachmentUrl}"
                       target="_blank" download="${attachmentName}"
                       style="color:#0d6efd; text-decoration:underline; word-break:break-word; font-size:14px;">
                       ${attachmentName}
                    </a>
                </div>
            </div>`;
}

function buildAttachmentUrl(path) {
    const normalized = path.replace(/^\/+/, '');
    const baseDir = window.location.origin + window.location.pathname.replace(/\/[^/]*$/, '/') + '../';
    const url = new URL(normalized, baseDir).href;
    return url;
}

function openChatImagePreview(src) {
    const overlay = document.getElementById('imagePreviewOverlay');
    const img = document.getElementById('imagePreviewContent');
    img.src = src;
    overlay.classList.add('show');
    overlay.style.display = 'flex';
}

function closeChatImagePreview() {
    const overlay = document.getElementById('imagePreviewOverlay');
    const img = document.getElementById('imagePreviewContent');
    img.src = '';
    overlay.classList.remove('show');
    overlay.style.display = 'none';
}

// Feature 3: file selection handler for guest
function handleGuestFile(input) {
    const errEl = document.getElementById('guestFileError');
    errEl.style.display = 'none';

    if (!input.files || input.files.length === 0) { clearGuestAttachment(); return; }

    const file     = input.files[0];
    const maxBytes = 5 * 1024 * 1024;
    const allowed  = ['image/jpeg','image/png','image/gif',
                       'application/pdf',
                       'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                       'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

    if (file.size > maxBytes) {
        errEl.textContent    = 'File too large. Maximum size is 5 MB.';
        errEl.style.display  = 'block';
        clearGuestAttachment(); return;
    }
    if (!allowed.includes(file.type)) {
        errEl.textContent    = 'Invalid file type. Accepted: JPG, PNG, GIF, PDF, DOCX, XLSX.';
        errEl.style.display  = 'block';
        clearGuestAttachment(); return;
    }

    guestSelectedFile = file;

    const bar   = document.getElementById('guestFilePreviewBar');
    const name  = document.getElementById('guestFileName');
    const thumb = document.getElementById('guestFileThumb');
    const icon  = document.getElementById('guestFileIcon');

    name.textContent  = file.name;
    bar.classList.add('show');

    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = e => { thumb.src = e.target.result; thumb.style.display = 'inline'; icon.textContent = ''; };
        reader.readAsDataURL(file);
    } else {
        thumb.style.display = 'none';
        const icons = { 'application/pdf': '📄' };
        icon.textContent = icons[file.type] || (file.type.includes('word') ? '📝' : (file.type.includes('sheet') ? '📊' : '📎'));
    }
}

function clearGuestAttachment() {
    guestSelectedFile = null;
    document.getElementById('guestAttachInput').value = '';
    document.getElementById('guestFilePreviewBar').classList.remove('show');
    document.getElementById('guestFileThumb').style.display = 'none';
    document.getElementById('guestFileName').textContent  = '';
    document.getElementById('guestFileIcon').textContent  = '';
    document.getElementById('guestFileError').style.display = 'none';
}

function sendGuestMessage() {
    const input = document.getElementById('chatInput');
    const msg   = input.value.trim();

    if (!msg && !guestSelectedFile) {
        const errEl = document.getElementById('guestFileError');
        errEl.textContent   = 'Please enter a message or attach a file.';
        errEl.style.display = 'block';
        return;
    }

    const formData = new FormData();
    formData.append('message', msg);
    formData.append('guest_token', '<?= htmlspecialchars($guestPortalToken, ENT_QUOTES, 'UTF-8') ?>');
    if (guestSelectedFile) {
        formData.append('attachment', guestSelectedFile);
    }

    fetch('chat_send.php', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(async response => {
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (err) {
                return { success: false, error: text || 'Unexpected server response.' };
            }
        })
        .then(data => {
            if (data.success) {
                input.value = '';
                clearGuestAttachment();
                loadChat();
            } else {
                const errEl = document.getElementById('guestFileError');
                errEl.textContent   = data.error || 'Unable to send message.';
                errEl.style.display = 'block';
            }
        })
        .catch(() => {
            const errEl = document.getElementById('guestFileError');
            errEl.textContent   = 'Network error. Please try again.';
            errEl.style.display = 'block';
        });
}

function escHtml(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Poll in the background so closed chats can surface new replies.
setInterval(() => {
    const w = document.getElementById('chatWidget');
    loadChat(w.style.display === 'flex');
    pollGuestRequestUpdates();
}, 5000);

loadChat(false);
pollGuestRequestUpdates();
</script>
