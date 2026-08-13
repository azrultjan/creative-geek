<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/menus.php';

$sessionId = 'dd00d7f2-69cf-4160-aac1-df2ae16e5ec2';
$apiKey = 'owa_k1_9adc820273a8ada22e94d24f4073d69d34cbab82932d63cdb6eb7dc703ad914c';

function callOpenWA($endpoint) {
    global $apiKey;
    $url = 'http://host.docker.internal:2785/api' . $endpoint;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: ' . $apiKey]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

$session = callOpenWA("/sessions/{$sessionId}");
$status = $session['status'] ?? '';
$phone = $session['phone'] ?? '6281246284684';
$pushName = $session['pushName'] ?? '';

$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>WhatsApp Chat - Creative Geek</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: #0a0a0a; color: #e5e7eb; height: 100vh; overflow: hidden; }
        .sidebar { background: #0d0d0d; border-right: 1px solid #1f1f1f; position:fixed; top:0; left:0; height:100%; width:250px; z-index:50; transform:translateX(-100%); transition:transform 0.3s ease; overflow-y:auto; padding:20px 0; }
        .sidebar.open { transform:translateX(0); }
        .sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:40; }
        .sidebar-overlay.active { display:block; }
        .sidebar-link { display:flex; align-items:center; gap:14px; padding:10px 20px; border-radius:10px; color:#9ca3af; transition:all 0.2s; text-decoration:none; margin:2px 12px; font-size:14px; }
        .sidebar-link i { width:20px; font-size:16px; text-align:center; }
        .sidebar-link:hover { background:#1a1a1a; color:#f5a623; }
        .sidebar-link.active { background:#1a1a1a; color:#f5a623; font-weight:600; border-right:2px solid #f5a623; }
        .main-content { margin-left:0; transition:margin-left 0.3s ease; }
        @media (min-width:768px) {
            .sidebar { transform:translateX(0); width:230px; }
            .sidebar-overlay { display:none !important; }
            .main-content { margin-left:230px; }
            .hamburger-btn { display:none !important; }
        }
        .text-gold { color:#f5a623; }
        .chat-list { overflow-y:auto; height:calc(100vh - 180px); }
        .chat-item { padding:12px 16px; border-bottom:1px solid #1f1f1f; cursor:pointer; transition:background 0.15s; }
        .chat-item:hover { background:#1a1a1a; }
        .chat-item.active { background:#1a1a1a; border-left:3px solid #f5a623; }
        .chat-item .name { font-weight:600; }
        .chat-item .last-msg { font-size:13px; color:#9ca3af; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:180px; }
        .chat-item .time { font-size:11px; color:#6b7280; }
        .chat-item .unread { background:#f5a623; color:#000; font-size:10px; font-weight:700; padding:2px 8px; border-radius:10px; }
        .messages-container { overflow-y:auto; height:calc(100vh - 280px); padding:16px 20px; display:flex; flex-direction:column; gap:6px; }
        .message-wrapper { display:flex; width:100%; animation: fadeIn 0.2s; }
        .message-wrapper.incoming { justify-content:flex-start; }
        .message-wrapper.outgoing { justify-content:flex-end; }
        .message { max-width:70%; padding:10px 14px; border-radius:12px; word-wrap:break-word; line-height:1.5; font-size:14px; position:relative; }
        .message.incoming { background:#1f1f1f; color:#e5e7eb; border-bottom-left-radius:4px; }
        .message.outgoing { background:#f5a623; color:#000; border-bottom-right-radius:4px; }
        .message .time { font-size:10px; opacity:0.6; margin-top:4px; text-align:right; }
        .message .media-wrap { margin:-8px -10px 6px -10px; border-radius:8px 8px 0 0; overflow:hidden; max-width:280px; }
        .message .media-wrap img { width:100%; max-height:250px; object-fit:cover; display:block; cursor:pointer; }
        .message .media-wrap video { width:100%; max-height:300px; display:block; cursor:pointer; }
        .message .media-wrap .gif-tag { position:absolute; top:6px; right:6px; background:rgba(0,0,0,0.6); color:#fff; font-size:9px; padding:2px 8px; border-radius:4px; font-weight:600; }
        .input-area { padding:12px 16px; border-top:1px solid #1f1f1f; background:#0d0d0d; }
        .input-area input { background:#0a0a0a; border:1px solid #2a2a2a; color:#fff; padding:10px 14px; border-radius:10px; flex:1; }
        .input-area input:focus { outline:none; border-color:#f5a623; }
        .input-area input:disabled { opacity:0.5; cursor:not-allowed; }
        .btn-send { background:#f5a623; color:#000; font-weight:600; padding:10px 18px; border-radius:10px; border:none; cursor:pointer; transition:0.2s; }
        .btn-send:hover { background:#e09515; }
        .btn-send:disabled { opacity:0.5; cursor:not-allowed; }
        .btn-icon { background:transparent; border:none; color:#6b7280; padding:6px 10px; border-radius:8px; cursor:pointer; transition:0.2s; font-size:16px; }
        .btn-icon:hover { color:#f5a623; background:#1a1a1a; }
        .btn-icon:disabled { opacity:0.3; cursor:not-allowed; }
        .empty-state { color:#6b7280; text-align:center; padding:40px 20px; }
        .status-badge { padding:4px 14px; border-radius:20px; font-size:12px; font-weight:600; }
        .status-connected { background:#1a3a2a; color:#4ade80; }
        .status-disconnected { background:#3a1a1a; color:#f87171; }
        .status-scanning { background:#3a2a1a; color:#fbbf24; }
        ::-webkit-scrollbar { width:4px; }
        ::-webkit-scrollbar-track { background:#0a0a0a; }
        ::-webkit-scrollbar-thumb { background:#2a2a2a; border-radius:10px; }
        ::-webkit-scrollbar-thumb:hover { background:#f5a623; }
        .error-box { background:#3a1a1a; border:1px solid #f87171; color:#fca5a5; padding:12px; border-radius:10px; margin-bottom:10px; font-size:13px; }
        .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.9); z-index:100; align-items:center; justify-content:center; cursor:pointer; }
        .modal.active { display:flex; }
        .modal-content { max-width:90%; max-height:90%; }
        .modal-content img { max-width:100%; max-height:90vh; border-radius:8px; }
        .modal-close { position:fixed; top:20px; right:30px; color:#fff; font-size:36px; cursor:pointer; z-index:101; }
        .modal-close:hover { color:#f5a623; }
        @keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
        @media (max-width:768px) { .chat-sidebar { width:100% !important; max-height:40vh; } .chat-main { height:60vh; } .messages-container { height:calc(60vh - 140px); } .message .media-wrap { max-width:200px; } }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <aside class="sidebar" id="sidebar">
        <?php include __DIR__ . '/sidebar.php'; ?>
    </aside>

    <main class="main-content flex flex-col h-screen" id="mainContent">
        <div class="flex items-center p-4 border-b border-[#1f1f1f] bg-[#0d0d0d] shrink-0">
            <button class="hamburger-btn text-gray-400 hover:text-gold text-2xl mr-3" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <div class="flex items-center gap-3 flex-1 flex-wrap">
                <h1 class="text-xl font-bold text-white"><i class="fab fa-whatsapp mr-2 text-green-400"></i> WhatsApp</h1>
                <span class="status-badge <?= $status === 'ready' || $status === 'connected' ? 'status-connected' : ($status === 'scanning' ? 'status-scanning' : 'status-disconnected') ?>">
                    <?= $status === 'ready' || $status === 'connected' ? '✅ Terkoneksi' : ($status === 'scanning' ? '📷 Scan QR' : '❌ Terputus') ?>
                </span>
                <?php if ($status !== 'ready' && $status !== 'connected'): ?>
                <a href="whatsapp.php" class="text-sm text-gold hover:underline"><i class="fas fa-sync-alt mr-1"></i> Refresh</a>
                <?php endif; ?>
            </div>
            <div class="text-sm text-gray-400 hidden md:block">
                <?= htmlspecialchars($phone) ?> • <?= htmlspecialchars($pushName) ?>
            </div>
        </div>

        <div class="flex flex-1 overflow-hidden">
            <div class="chat-sidebar w-[280px] border-r border-[#1f1f1f] bg-[#0d0d0d] flex flex-col shrink-0">
                <div class="p-3 border-b border-[#1f1f1f]">
                    <input type="text" id="searchChat" placeholder="Cari chat..." class="w-full bg-[#0a0a0a] border border-[#2a2a2a] text-white rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-gold">
                </div>
                <div id="chatList" class="chat-list flex-1">
                    <div class="empty-state"><i class="fas fa-spinner fa-pulse mr-2"></i> Memuat chat...</div>
                </div>
                <div class="p-2 text-center border-t border-[#1f1f1f] text-xs text-gray-500">
                    <button id="refreshChats" class="text-gold hover:underline"><i class="fas fa-sync-alt mr-1"></i> Refresh</button>
                </div>
            </div>

            <div class="chat-main flex-1 flex flex-col bg-[#0a0a0a]">
                <div id="chatHeader" class="p-3 border-b border-[#1f1f1f] bg-[#0d0d0d] shrink-0">
                    <span class="text-gray-400"><i class="fas fa-chevron-left mr-2"></i> Pilih chat untuk mulai</span>
                </div>
                <div id="messagesContainer" class="messages-container flex-1">
                    <div class="empty-state"><i class="fas fa-arrow-left mr-2"></i> Pilih chat di sebelah kiri</div>
                </div>
                <div class="input-area shrink-0 flex items-center gap-2">
                    <button class="btn-icon" onclick="document.getElementById('fileInput').click()" id="attachBtn" title="Lampirkan file" disabled>
                        <i class="fas fa-paperclip"></i>
                    </button>
                    <button class="btn-icon" onclick="document.getElementById('imageInput').click()" id="imageBtn" title="Kirim gambar" disabled>
                        <i class="fas fa-image"></i>
                    </button>
                    <button class="btn-icon" onclick="document.getElementById('gifInput').click()" id="gifBtn" title="Kirim GIF" disabled>
                        <i class="fas fa-film"></i>
                    </button>
                    <input type="text" id="messageInput" placeholder="Ketik pesan..." disabled class="flex-1">
                    <button id="sendBtn" disabled class="btn-send"><i class="fas fa-paper-plane mr-1"></i> Kirim</button>
                </div>
                <input type="file" id="fileInput" style="display:none" multiple>
                <input type="file" id="imageInput" style="display:none" accept="image/*">
                <input type="file" id="gifInput" style="display:none" accept="image/gif,video/mp4">
            </div>
        </div>
    </main>

    <!-- Modal View Media -->
    <div class="modal" id="mediaModal" onclick="closeMedia()">
        <span class="modal-close" onclick="closeMedia()">&times;</span>
        <div class="modal-content" id="mediaContent"></div>
    </div>

    <script>
        const MY_PHONE = '<?= $phone ?>';
        const MY_PHONE_CLEAN = MY_PHONE.replace(/@.*/, '');

        let currentChatId = null;
        let currentChatName = '';
        let refreshInterval = null;
        let isLoading = false;
        let isUploading = false;
        
        // Cache untuk chat list (client side)
        let chatCache = null;
        let chatCacheTime = 0;

        // ===== LOAD CHATS (DENGAN CACHE CLIENT) =====
        async function loadChats(force = false) {
            if (isLoading) return;
            
            // Gunakan cache jika masih valid (3 detik)
            const now = Date.now();
            if (!force && chatCache && (now - chatCacheTime) < 3000) {
                renderChats(chatCache);
                return;
            }
            
            isLoading = true;
            const container = document.getElementById('chatList');
            container.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-pulse mr-2"></i> Memuat chat...</div>';
            try {
                const res = await fetch('whatsapp_ajax.php?action=get_chats');
                const data = await res.json();
                if (data.success && data.data && data.data.length > 0) {
                    chatCache = data.data;
                    chatCacheTime = now;
                    renderChats(data.data);
                } else {
                    container.innerHTML = '<div class="empty-state"><i class="fas fa-inbox mr-2"></i> Belum ada chat</div>';
                }
            } catch (e) {
                console.error('Load chats error:', e);
                container.innerHTML = `<div class="error-box"><i class="fas fa-exclamation-triangle mr-2"></i> Error: ${e.message}</div>`;
            }
            isLoading = false;
        }

        // ===== RENDER CHATS =====
        function renderChats(chats) {
            const container = document.getElementById('chatList');
            let html = '';
            chats.forEach(chat => {
                const name = chat.name || chat.pushName || chat.id || 'Unknown';
                let lastMsg = 'Tidak ada pesan';
                let time = '';

                if (chat.lastMessage) {
                    lastMsg = chat.lastMessage.body || chat.lastMessage.text || chat.lastMessage.content || 'Tidak ada pesan';
                    const type = chat.lastMessage.type;
                    if (type === 'image') lastMsg = '📷 Gambar';
                    else if (type === 'gif') lastMsg = '🎥 GIF';
                    else if (type === 'video') lastMsg = '🎬 Video';
                    else if (type === 'audio') lastMsg = '🎵 Audio';
                    else if (type === 'document') lastMsg = '📄 Dokumen';
                    if (chat.lastMessage.timestamp) {
                        time = new Date(chat.lastMessage.timestamp * 1000).toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'});
                    }
                }

                const unread = chat.unreadCount || 0;
                const active = (chat.id === currentChatId) ? 'active' : '';
                const unreadBadge = unread > 0 ? `<span class="unread">${unread}</span>` : '';

                html += `
                    <div class="chat-item ${active}" data-chatid="${chat.id}" data-name="${name}">
                        <div class="flex justify-between items-center">
                            <span class="name">${name}</span>
                            <span class="time">${time}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="last-msg">${lastMsg}</span>
                            ${unreadBadge}
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
            document.querySelectorAll('.chat-item').forEach(el => {
                el.addEventListener('click', function() {
                    selectChat(this.dataset.chatid, this.dataset.name);
                });
            });

            const query = document.getElementById('searchChat').value.toLowerCase();
            document.querySelectorAll('.chat-item').forEach(el => {
                el.style.display = el.dataset.name.toLowerCase().includes(query) ? '' : 'none';
            });
        }

        // ===== SELECT CHAT =====
        function selectChat(chatId, name) {
            currentChatId = chatId;
            currentChatName = name;
            document.getElementById('chatHeader').innerHTML = `
                <span class="font-semibold text-white"><i class="fas fa-user-circle mr-2"></i> ${name}</span>
                <span class="text-xs text-gray-500 ml-2">
                    <button onclick="loadMessages(currentChatId)" class="text-gold hover:underline"><i class="fas fa-sync-alt"></i></button>
                </span>
            `;
            document.getElementById('messageInput').disabled = false;
            document.getElementById('sendBtn').disabled = false;
            document.getElementById('attachBtn').disabled = false;
            document.getElementById('imageBtn').disabled = false;
            document.getElementById('gifBtn').disabled = false;
            
            document.querySelectorAll('.chat-item').forEach(el => {
                el.classList.toggle('active', el.dataset.chatid === chatId);
            });
            loadMessages(chatId);
            if (refreshInterval) clearInterval(refreshInterval);
            refreshInterval = setInterval(() => loadMessages(chatId, true), 15000);
        }

        // ===== LOAD MESSAGES (DENGAN CACHE) =====
        let messageCache = {};
        let messageCacheTime = {};

        async function loadMessages(chatId, silent = false) {
            const now = Date.now();
            // Gunakan cache jika masih valid (5 detik)
            if (!silent && messageCache[chatId] && (now - messageCacheTime[chatId]) < 5000) {
                renderMessages(messageCache[chatId]);
                if (!silent) scrollToBottom();
                return;
            }

            try {
                const res = await fetch(`whatsapp_ajax.php?action=get_messages&chatId=${encodeURIComponent(chatId)}&limit=50`);
                const data = await res.json();
                if (data.success && data.data) {
                    messageCache[chatId] = data.data;
                    messageCacheTime[chatId] = now;
                    renderMessages(data.data);
                    if (!silent) scrollToBottom();
                } else {
                    if (!silent) {
                        document.getElementById('messagesContainer').innerHTML = `<div class="error-box"><i class="fas fa-exclamation-triangle mr-2"></i> ${data.error || 'Gagal memuat pesan'}</div>`;
                    }
                }
            } catch (e) {
                console.error('Load messages error:', e);
                if (!silent) {
                    document.getElementById('messagesContainer').innerHTML = `<div class="error-box"><i class="fas fa-exclamation-triangle mr-2"></i> Error: ${e.message}</div>`;
                }
            }
        }

        // ===== RENDER MESSAGES =====
        function renderMessages(messages) {
            const container = document.getElementById('messagesContainer');
            if (!messages || messages.length === 0) {
                container.innerHTML = '<div class="empty-state"><i class="fas fa-inbox mr-2"></i> Belum ada pesan</div>';
                return;
            }
            let html = '';
            messages.forEach(msg => {
                let isOutgoing = false;
                if (msg.direction === 'outgoing') isOutgoing = true;
                else if (msg.fromMe === true) isOutgoing = true;
                else if (msg.from) {
                    const fromClean = msg.from.replace(/@.*/, '');
                    if (fromClean === MY_PHONE_CLEAN) isOutgoing = true;
                }
                
                const text = msg.body || msg.text || '';
                const time = new Date(msg.timestamp * 1000).toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'});
                const cls = isOutgoing ? 'outgoing' : 'incoming';
                const wrapperCls = isOutgoing ? 'outgoing' : 'incoming';
                
                let mediaHtml = '';
                const type = msg.type || '';
                const mediaUrl = msg.mediaUrl || msg.url || '';
                
                if (type === 'image' && mediaUrl) {
                    mediaHtml = `
                        <div class="media-wrap" onclick="event.stopPropagation();viewMedia('${mediaUrl}')">
                            <img src="${mediaUrl}" alt="Gambar" loading="lazy" onerror="this.style.display='none'">
                        </div>
                    `;
                } else if (type === 'gif' && mediaUrl) {
                    mediaHtml = `
                        <div class="media-wrap" onclick="event.stopPropagation();viewMedia('${mediaUrl}')" style="position:relative;">
                            <video autoplay loop muted playsinline>
                                <source src="${mediaUrl}" type="video/mp4">
                            </video>
                            <span class="gif-tag">GIF</span>
                        </div>
                    `;
                } else if (type === 'video' && mediaUrl) {
                    mediaHtml = `
                        <div class="media-wrap" onclick="event.stopPropagation();viewMedia('${mediaUrl}')">
                            <video controls playsinline preload="metadata">
                                <source src="${mediaUrl}">
                            </video>
                        </div>
                    `;
                } else if (type === 'document' && msg.fileName) {
                    let icon = 'fa-file';
                    if (msg.fileName.match(/\.(pdf)$/i)) icon = 'fa-file-pdf';
                    else if (msg.fileName.match(/\.(doc|docx)$/i)) icon = 'fa-file-word';
                    else if (msg.fileName.match(/\.(xls|xlsx)$/i)) icon = 'fa-file-excel';
                    else if (msg.fileName.match(/\.(ppt|pptx)$/i)) icon = 'fa-file-powerpoint';
                    else if (msg.fileName.match(/\.(zip|rar|7z)$/i)) icon = 'fa-file-archive';
                    mediaHtml = `
                        <div style="display:flex;align-items:center;gap:8px;padding:2px 0;">
                            <i class="fas ${icon} text-xl text-gold"></i>
                            <span style="font-size:13px;">${msg.fileName}</span>
                        </div>
                    `;
                }
                
                html += `
                    <div class="message-wrapper ${wrapperCls}">
                        <div class="message ${cls}">
                            ${mediaHtml}
                            ${text ? `<div>${text}</div>` : ''}
                            <div class="time">${time}</div>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
            scrollToBottom();
        }

        function scrollToBottom() {
            const container = document.getElementById('messagesContainer');
            if (container) container.scrollTop = container.scrollHeight;
        }

        // ===== VIEW MEDIA =====
        function viewMedia(url) {
            const modal = document.getElementById('mediaModal');
            const content = document.getElementById('mediaContent');
            modal.classList.add('active');
            content.innerHTML = `<img src="${url}" alt="Media" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22300%22 height=%22300%22%3E%3Crect fill=%22%23333%22 width=%22300%22 height=%22300%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23999%22 font-size=%2214%22%3EGagal memuat%3C/text%3E%3C/svg%3E'">`;
        }

        function closeMedia() {
            document.getElementById('mediaModal').classList.remove('active');
            document.getElementById('mediaContent').innerHTML = '';
        }

        // ===== SEND MESSAGE =====
        document.getElementById('sendBtn').addEventListener('click', sendMessage);
        document.getElementById('messageInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') sendMessage();
        });

        async function sendMessage() {
            const input = document.getElementById('messageInput');
            const text = input.value.trim();
            if (!text || !currentChatId) return;
            input.disabled = true;
            document.getElementById('sendBtn').disabled = true;
            try {
                const formData = new FormData();
                formData.append('chatId', currentChatId);
                formData.append('text', text);
                const res = await fetch('whatsapp_ajax.php?action=send', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    input.value = '';
                    // Hapus cache agar refresh
                    messageCache[currentChatId] = null;
                    loadMessages(currentChatId);
                    // Refresh chat list juga
                    chatCache = null;
                    loadChats(true);
                } else {
                    alert('❌ Gagal kirim: ' + (data.error || 'Unknown error'));
                }
            } catch (e) {
                alert('❌ Error: ' + e.message);
            }
            input.disabled = false;
            document.getElementById('sendBtn').disabled = false;
            input.focus();
        }

        // ===== SEND MEDIA =====
        document.getElementById('fileInput').addEventListener('change', function(e) {
            const files = this.files;
            if (!files.length || !currentChatId) return;
            for (let file of files) sendFile(file);
            this.value = '';
        });

        document.getElementById('imageInput').addEventListener('change', function(e) {
            const file = this.files[0];
            if (!file || !currentChatId) return;
            sendMedia(file, 'image');
            this.value = '';
        });

        document.getElementById('gifInput').addEventListener('change', function(e) {
            const file = this.files[0];
            if (!file || !currentChatId) return;
            sendMedia(file, 'gif');
            this.value = '';
        });

        async function sendMedia(file, type) {
            if (isUploading) { alert('Sedang upload...'); return; }
            isUploading = true;
            try {
                const formData = new FormData();
                formData.append('chatId', currentChatId);
                formData.append('file', file);
                formData.append('type', type);
                const res = await fetch('whatsapp_ajax.php?action=send_media', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    messageCache[currentChatId] = null;
                    loadMessages(currentChatId);
                    chatCache = null;
                    loadChats(true);
                } else {
                    alert('❌ Gagal kirim: ' + (data.error || 'Unknown error'));
                }
            } catch (e) {
                alert('❌ Error: ' + e.message);
            }
            isUploading = false;
        }

        async function sendFile(file) {
            if (isUploading) { alert('Sedang upload...'); return; }
            isUploading = true;
            try {
                const formData = new FormData();
                formData.append('chatId', currentChatId);
                formData.append('file', file);
                const res = await fetch('whatsapp_ajax.php?action=send_file', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    messageCache[currentChatId] = null;
                    loadMessages(currentChatId);
                    chatCache = null;
                    loadChats(true);
                } else {
                    alert('❌ Gagal kirim: ' + (data.error || 'Unknown error'));
                }
            } catch (e) {
                alert('❌ Error: ' + e.message);
            }
            isUploading = false;
        }

        // ===== SEARCH =====
        document.getElementById('searchChat').addEventListener('input', function() {
            const query = this.value.toLowerCase();
            document.querySelectorAll('.chat-item').forEach(el => {
                el.style.display = el.dataset.name.toLowerCase().includes(query) ? '' : 'none';
            });
        });

        // ===== REFRESH =====
        document.getElementById('refreshChats').addEventListener('click', function() {
            chatCache = null;
            loadChats(true);
            if (currentChatId) {
                messageCache[currentChatId] = null;
                loadMessages(currentChatId);
            }
        });

        // ===== SIDEBAR =====
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('active');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.getElementById('sidebar').classList.remove('open');
                document.getElementById('sidebarOverlay').classList.remove('active');
                closeMedia();
            }
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth >= 768) {
                document.getElementById('sidebar').classList.remove('open');
                document.getElementById('sidebarOverlay').classList.remove('active');
            }
        });

        // ===== START =====
        loadChats();
    </script>
</body>
</html>