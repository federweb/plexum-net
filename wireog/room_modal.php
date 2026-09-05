<link rel="stylesheet" href="../../css/style_modal.css">

<div id="roomModal" class="room-modal" style="display: none;">
    <div class="room-modal-content">
        <div class="room-modal-header">
            <h2>My Rooms</h2>
            <span class="room-modal-close">&times;</span>
        </div>

        <div class="room-modal-body">
            <div class="room-modal-actions">
                <button id="modal-create-room-btn" class="modal-btn-create">
                    <span>➕</span>
                    <span class="modal-btn-text">New Room</span>
                    <span class="modal-btn-text-mobile">Create</span>
                </button>
                <button id="modal-delete-all-btn" class="modal-btn-delete-all" style="display: none;">
                    <span>🗑️</span>
                    <span class="modal-btn-text">Clear List</span>
                    <span class="modal-btn-text-mobile">Delete All</span>
                </button>
            </div>

            <div id="modal-rooms-list" class="modal-rooms-list">
                <!-- Rooms will be loaded here -->
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const modal = document.getElementById('roomModal');
    const closeBtn = document.querySelector('.room-modal-close');
    const basePath = window.location.pathname.split('rooms/')[0].replace(/[^\/]*$/, '');


    window.openRoomModal = function() {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        loadModalRooms();
    };


    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = ''; // Restore scrolling
    }

    closeBtn.onclick = closeModal;

    window.onclick = function(event) {
        if (event.target === modal) {
            closeModal();
        }
    };


    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && modal.style.display === 'block') {
            closeModal();
        }
    });


    const currentRoomId = (function() {
        const match = window.location.pathname.match(/\/rooms\/([^\/]+)\//);
        return match ? match[1] : null;
    })();

    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    }

    function setCookie(name, value, days) {
        let expires = '';
        if (days) {
            const date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = `; expires=${date.toUTCString()}`;
        }
        document.cookie = `${name}=${value || ''}${expires}; path=/`;
    }


    function loadModalRooms() {
        let userRooms = JSON.parse(getCookie('userRooms') || '[]');
        const roomsList = document.getElementById('modal-rooms-list');
        const deleteAllBtn = document.getElementById('modal-delete-all-btn');

        roomsList.innerHTML = '';

        if (userRooms.length === 0) {
            deleteAllBtn.style.display = 'none';
            roomsList.innerHTML = `
                <div class="modal-no-rooms">
                    <p>No rooms found</p>
                    <p>Create your first room to get started!</p>
                </div>
            `;
            return;
        }

        deleteAllBtn.style.display = 'block';

        userRooms.forEach(function(room, index) {
            const roomUrl = window.location.origin + basePath + 'rooms/' + room.id + '/';
            const roomDiv = document.createElement('div');
            roomDiv.className = 'modal-room-item';
            const isCreator = room.isOwner === true || /^[0-9a-f]{32}$/.test(room.sessionPwd || '');
            const isCurrentRoom = room.id === currentRoomId;
            const deleteBtn = (isCreator && !isCurrentRoom)
                ? `<button class="modal-room-btn delete" data-room-id="${room.id}">
                            <span class="modal-room-btn-icon">🗑️</span>
                            <span class="modal-room-btn-text">Delete</span>
                        </button>`
                : '';
            roomDiv.innerHTML = `
                <div class="modal-room-header">
                    <div class="modal-room-name">${room.name || 'Unnamed Room'}</div>
                    <div class="modal-room-actions">
                        <button class="modal-room-btn edit" data-room-id="${room.id}">
                            <span class="modal-room-btn-icon">✏️</span>
                            <span class="modal-room-btn-text">Edit</span>
                        </button>
                        ${deleteBtn}
                    </div>
                </div>
                <div class="modal-room-info">
                    <div class="modal-room-id">ID: ${room.id}</div>
                    <div>Created: ${new Date(room.creationTime).toLocaleString()}</div>
                </div>
                <div class="modal-room-actions">
                    <button class="modal-room-btn open" onclick="window.open('${roomUrl}', '_blank')">
                        <span class="modal-room-btn-icon">Open</span>
                        <span class="modal-room-btn-text"> Room</span>
                    </button>
                    <button class="modal-room-btn copy" data-url="${roomUrl}">
                        <span class="modal-room-btn-icon">Share</span>
                        <span class="modal-room-btn-text"> Link</span>
                    </button>
                    ${room.sessionPwd ? `<button class="modal-room-btn export-key" data-room-id="${room.id}">
                        <span class="modal-room-btn-icon">🔑</span>
                        <span class="modal-room-btn-text">Export Key</span>
                    </button>` : `<button class="modal-room-btn import-key" data-room-id="${room.id}">
                        <span class="modal-room-btn-icon">🔑</span>
                        <span class="modal-room-btn-text">Import Key</span>
                    </button>`}
                </div>
            `;
            roomsList.appendChild(roomDiv);
        });
    }


    document.getElementById('modal-rooms-list').addEventListener('click', function(e) {
        const target = e.target.closest('.modal-room-btn');
        if (!target) return;

        if (target.classList.contains('edit')) {
            const roomId = target.getAttribute('data-room-id');
            editRoom(roomId);
        } else if (target.classList.contains('delete')) {
            const roomId = target.getAttribute('data-room-id');
            deleteRoom(roomId);
        } else if (target.classList.contains('copy')) {
            const url = target.getAttribute('data-url');
            copyToClipboard(url, target);
        } else if (target.classList.contains('export-key')) {
            const roomId = target.getAttribute('data-room-id');
            exportPrivateKey(roomId, target);
        } else if (target.classList.contains('import-key')) {
            const roomId = target.getAttribute('data-room-id');
            insertAdminKey(roomId);
        }
    });


    function editRoom(roomId) {
        let userRooms = JSON.parse(getCookie('userRooms') || '[]');
        const roomIndex = userRooms.findIndex(room => room.id === roomId);

        if (roomIndex !== -1) {
            const currentName = userRooms[roomIndex].name || '';
            const newName = prompt('Enter a new name for the room:', currentName);

            if (newName !== null) {
                userRooms[roomIndex].name = newName.trim();
                setCookie('userRooms', JSON.stringify(userRooms), 365);
                loadModalRooms();
            }
        }
    }


    async function deleteRoom(roomId) {
        let userRooms = JSON.parse(getCookie('userRooms') || '[]');
        const room = userRooms.find(r => r.id === roomId);
        if (!room || !(room.isOwner === true || /^[0-9a-f]{32}$/.test(room.sessionPwd || ''))) return;

        if (!confirm('Are you sure you want to permanently delete this room? This action cannot be undone.')) {
            return;
        }

        try {
            const sessionPwd = room.sessionPwd || '';
            const response = await fetch(basePath + 'delete_room.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ roomId: roomId, sessionPwd: sessionPwd })
            });
            const data = await response.json();
            if (data.success) {
                userRooms = userRooms.filter(r => r.id !== roomId);
                setCookie('userRooms', JSON.stringify(userRooms), 365);
                loadModalRooms();
            } else {
                alert('Error deleting room: ' + (data.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred while deleting the room');
        }
    }


    document.getElementById('modal-delete-all-btn').addEventListener('click', function() {
        if (!confirm('Are you sure you want to delete ALL rooms from your list? This action cannot be undone.')) {
            return;
        }

        setCookie('userRooms', '[]', 365);
        loadModalRooms();
    });


    function generateSessionPwd() {
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
    }

    function deriveRoomId(sessionPwd) {
        return CryptoJS.SHA256(sessionPwd).toString().substring(0, 24);
    }

    // Must match derivePasswordHash() in CORE/index.php exactly (same salt,
    // same iterations) or a participant logging in later won't derive the
    // same key and won't be able to decrypt this room's welcome message.
    const PBKDF2_ITERATIONS = 350000;

    function derivePasswordHash(password, roomId) {
        if (!password) return CryptoJS.SHA256('').toString();
        return CryptoJS.PBKDF2(password, roomId, {
            keySize: 8,
            iterations: PBKDF2_ITERATIONS,
            hasher: CryptoJS.algo.SHA256
        }).toString();
    }

    function generateUserKey(username, roomId, password = '') {
        const passwordHash = derivePasswordHash(password, roomId);
        return CryptoJS.SHA256(roomId + passwordHash + CryptoJS.SHA256(username).toString()).toString().substring(0, 32);
    }

    function encryptMessage(message, username, roomId, password = '') {
        const userKey = generateUserKey(username, roomId, password);
        const ivBytes = new Uint8Array(16);
        crypto.getRandomValues(ivBytes);
        const ivHex = Array.from(ivBytes).map(b => b.toString(16).padStart(2, '0')).join('');
        const iv = CryptoJS.enc.Hex.parse(ivHex);

        const encrypted = CryptoJS.AES.encrypt(
            message,
            CryptoJS.enc.Utf8.parse(userKey),
            {
                iv: iv,
                mode: CryptoJS.mode.CBC,
                padding: CryptoJS.pad.Pkcs7
            }
        );

        return ivHex + encrypted.toString();
    }

    function createWelcomeMessages(roomId, password = '') {
        // The h2 line below is also the password check: verifyPassword()
        // in CORE/index.php decrypts this message and does a startsWith()
        // match against this exact string. Everything after the </h2> is
        // free-form and safe to edit, but that opening tag+text must stay
        // byte-for-byte identical or password verification breaks.
        //
        // The security paragraph is chosen by whether THIS room actually
        // has a password, not a one-size-fits-all claim — a passwordless
        // room's key is derivable from its public roomId + username, so
        // claiming "still fully end-to-end encrypted" here would be false
        // (see the honest disclosure already on the landing page).
        const hasPassword = password !== '';
        const securityParagraph = hasPassword
            ? '<p style="color: #34495e;">🔒 <strong>End-to-End Encrypted:</strong> This room has a password. Messages, audio and files are encrypted on your device with a key derived from it before being sent — nobody without the password (not the site owner, the hosting provider, or anyone monitoring the network) can read the content.</p>'
            : '<p style="color: #e74c3c;">⚠️ <strong>No Password Set:</strong> This room has no password, so its encryption key can be derived by anyone who knows this room\'s link and has basic technical skill. Treat it as visible to anyone with the URL, not as confidential — create a new, password-protected room for sensitive conversations.</p>';

        const welcomeMessage = '<h2 style="color: #2c3e50; margin-top: 0;">Welcome to Your Secure Chat!</h2>'
            + securityParagraph
            + '<p style="color: #34495e;">🔑 <strong>Room Access vs. Room Ownership:</strong> Being logged in here only proves you\'re a participant in this browser tab. To keep control of the room (lock/unlock it, wipe its history) from another device or after clearing cookies, open <strong>My Rooms → Export Key</strong> now and save the Admin Key somewhere safe — use <strong>Import Key</strong> to restore it later.</p>'
            + '<p style="color: #e74c3c;">🗑️ <strong>Delete All Files:</strong> If you hold this room\'s Admin Key (the room creator), you can permanently wipe all messages and files by typing the following command:</p>'
            + '<code style="font-family: monospace; font-size: 14px;">/deleteall</code>'
            + '<p style="color: #27ae60;">Happy chatting!</p>';

        const encryptedMessage = encryptMessage(welcomeMessage, 'admin', roomId, password);

        const messages = [
            {
                id: 1,
                user: 'System',
                message: 'admin has joined the room',
                type: 'system'
            },
            {
                id: 2,
                user: 'admin',
                message: encryptedMessage,
                type: 'text',
                timestamp: Math.floor(Date.now() / 1000)
            }
        ];

        return messages;
    }

    document.getElementById('modal-create-room-btn').addEventListener('click', async function() {
        const password = prompt('Enter a password for this room (leave empty for no password):') || '';

        const sessionPwd = generateSessionPwd();
        const roomId = deriveRoomId(sessionPwd);
        const messages = createWelcomeMessages(roomId, password);

        const btn = this;
        const originalHTML = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span>⏳</span> <span class="modal-btn-text">Creating...</span><span class="modal-btn-text-mobile">Wait...</span>';

        try {
            const response = await fetch(basePath + 'create_room.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sessionPwd: sessionPwd, messages: messages })
            });
            const data = await response.json();
            btn.disabled = false;
            btn.innerHTML = originalHTML;

            if (data.success) {
                let userRooms = JSON.parse(getCookie('userRooms') || '[]');
                userRooms.push({
                    id: data.roomId,
                    name: '',
                    creationTime: new Date().toISOString(),
                    sessionPwd: sessionPwd
                });
                setCookie('userRooms', JSON.stringify(userRooms), 365);
                loadModalRooms();
                alert('Room created successfully! ID: ' + data.roomId);
            } else {
                // Room-creation rate limiting was disabled server-side (see create_room.php),
                // so the old "max N rooms per hour/day" hint no longer applies.
                alert('Error creating room. ' + (data.error || 'Unknown error.'));
            }
        } catch (error) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            console.error('Error:', error);
            alert('An error occurred while creating the room');
        }
    });


    function encodeRoomPwd(dataRoomPwd) {
        const hexRoomPwd = Array.from(dataRoomPwd)
            .map(c => c.charCodeAt(0).toString(16).padStart(2, '0'))
            .join('');
        const shufRoomPwd = hexRoomPwd.split('').reverse().join('');
        const chunks = [];
        for (let i = 0; i < shufRoomPwd.length; i += 16) {
            chunks.push(shufRoomPwd.substring(i, i + 16));
        }
        return 'OG-' + chunks.join('-').toUpperCase();
    }

    function exportPrivateKey(roomId, button) {
        const userRooms = JSON.parse(getCookie('userRooms') || '[]');
        const room = userRooms.find(r => r.id === roomId);
        if (!room || !room.sessionPwd) return;
        copyToClipboard(encodeRoomPwd(room.sessionPwd), button);
    }

    function decodeRoomPwd(secretRoomPwd) {
        const cleanRoomPwd = secretRoomPwd.replace('OG-', '').replace(/-/g, '').toLowerCase();
        const hexRoomPwd = cleanRoomPwd.split('').reverse().join('');
        let result = '';
        for (let i = 0; i < hexRoomPwd.length; i += 2) {
            result += String.fromCharCode(parseInt(hexRoomPwd.substr(i, 2), 16));
        }
        return result;
    }

    function insertAdminKey(roomId) {
        const key = prompt('Paste the Admin Key for this room:');
        if (!key) return;

        const trimmedKey = key.trim();
        if (!trimmedKey.startsWith('OG-')) {
            alert('Invalid key format. The key must start with "OG-".');
            return;
        }

        try {
            const decodedPwd = decodeRoomPwd(trimmedKey);
            const derivedRoomId = CryptoJS.SHA256(decodedPwd).toString().substring(0, 24);

            if (derivedRoomId !== roomId) {
                alert('Invalid key: this key does not match the selected room.');
                return;
            }

            let userRooms = JSON.parse(getCookie('userRooms') || '[]');
            const roomIndex = userRooms.findIndex(r => r.id === roomId);
            if (roomIndex === -1) return;

            userRooms[roomIndex].sessionPwd = decodedPwd;
            setCookie('userRooms', JSON.stringify(userRooms), 365);
            loadModalRooms();
            alert('Admin key verified! You now have admin access to this room.');
        } catch (e) {
            alert('Invalid key format.');
        }
    }

    function copyToClipboard(text, button) {
        navigator.clipboard.writeText(text).then(function() {
            const originalHTML = button.innerHTML;
            button.innerHTML = '<span>✓</span> <span class="modal-room-btn-text">Copied!</span>';
            button.style.background = '#4caf50';

            setTimeout(function() {
                button.innerHTML = originalHTML;
                button.style.background = '';
            }, 2000);
        }).catch(function(err) {
            console.error('Failed to copy:', err);
            alert('Failed to copy link to clipboard');
        });
    }
})();
</script>
