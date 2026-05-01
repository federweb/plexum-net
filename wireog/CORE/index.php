<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>WIREOG CHAT</title>
    <link rel="icon" type="image/png" href="<?php echo preg_replace('#/rooms/.*$#', '', dirname($_SERVER['SCRIPT_NAME'])); ?>/img/favicon.png">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
        }

        #chat-container {
            display: none;
        }

        #message-list {
            height: 300px;
            overflow-y: scroll;
            border: 1px solid #ccc;
            padding: 10px;
            margin-bottom: 10px;
        }

        #user-list {
            float: right;
            width: 150px;
            border: 1px solid #ccc;
            padding: 10px;
        }
    </style>

    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

    <link rel="stylesheet" href="style.css">
    <link rel="manifest" href="<?php echo preg_replace('#/rooms/.*$#', '', dirname($_SERVER['SCRIPT_NAME'])); ?>/manifest.json">

    
    <script src="../../assets/crypto-js.min.js"></script>
    
    
</head>

<body>
    <div id="login">
        <h2>Login</h2>
        <input type="text" id="username" placeholder="Enter your name">
        <button onclick="login()">Submit</button>
    </div>

    <div id="chat-container">
        <div class="controls-bar">
            <div>
                <button id="myRoomsButton" class="my-rooms-btn">My Rooms</button>
            </div>
            <div>
                <label class="switch">
                    <input type="checkbox" id="muteAudioSwitch">
                    <span class="slider"></span>
                </label>
                <span class="ml-2">Mute Audio</span>
            </div>
            <div>
                <label class="switch">
                    <input type="checkbox" id="enterSendSwitch">
                    <span class="slider"></span>
                </label>
                <span class="ml-2">Enter=Send</span>
            </div>
        <div>

        </div>
        <div>
                <label class="switch">
                    <input type="checkbox" id="preventAccessSwitch">
                    <span class="slider"></span>
                </label>
        <span class="ml-2">Lock this room</span>
        </div>
                <div>
                <button id="shareButton" class="share">Share</button>
                <button id="favoriteButton" class="favorite">Favorite</button>
                </div>
        </div>

        <div id="user-list">
            <h3>Connected users</h3>
        </div>

        <div id="chat-area">
            <div id="message-list"></div>
            <div id="input-area">
                <textarea id="message" placeholder="Write a message..." rows="3"></textarea>
                <button onclick="sendMessage()">Send</button>
                <button id="voice-record-btn">Audio</button>
                <input type="file" id="file-input" style="display: none;">
                <button id="file-upload-btn">File</button>
            </div>
            <div id="upload-progress" style="display: none;">
                <progress id="upload-progress-bar" value="0" max="100"></progress>
                <span id="upload-progress-text">0%</span>
            </div>
        </div>
    </div>
    
    <script src="../../assets/RecordRTC.min.js"></script>
    
    <script>

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

        function saveUserRoom(roomId) {
            let userRooms = JSON.parse(getCookie('userRooms') || '[]');


            const existingRoom = userRooms.find(room => room.id === roomId);
            if (existingRoom) {
                alert('This room is already in your favorites!');
                return;
            }


            const roomName = prompt('Enter a name for this room (optional):');

            userRooms.push({
                id: roomId,
                name: roomName || '',
                creationTime: new Date().toISOString()
            });

            setCookie('userRooms', JSON.stringify(userRooms), 365);
            alert('Room saved to favorites!');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const shareButton = document.getElementById('shareButton');
            const favoriteButton = document.getElementById('favoriteButton');

            shareButton.addEventListener('click', function() {
                const url = window.location.href;

                if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
                    if (navigator.share) {
                        navigator.share({
                            title: 'WireOG Share',
                            url: url
                        }).then(() => {
                            console.log('Thanks for sharing!');
                        }).catch(console.error);
                    } else {
                        copyToClipboard(url);
                    }
                } else {
                    copyToClipboard(url);
                }
            });

            favoriteButton.addEventListener('click', function() {
                const roomId = getCurrentRoomId();
                saveUserRoom(roomId);
            });

            function copyToClipboard(text) {
                const cleanUrl = text.replace(/\/index\.php$/, '/');
                navigator.clipboard.writeText(cleanUrl).then(() => {
                    shareButton.textContent = 'Copied';
                    setTimeout(() => {
                        shareButton.textContent = 'Share';
                    }, 2000);
                }).catch((err) => {
                    console.error('Failed to copy: ', err);
                });
            }
        });
    </script>
    
    <script>
        let currentUser = '';
        let lastMessageId = 0;
        let isRecording = false;
        let recorder;
        let audioChunks = [];
        let stream;
        let displayedMessageIds = new Set();

        const roomId = getCurrentRoomId();

        function getCurrentRoomId() {
            const path = window.location.pathname;
            const folders = path.split('/').filter(folder => folder);
            if (folders.length > 0 && folders[folders.length - 1].includes('.')) {
                folders.pop();
            }
            const roomId = folders[folders.length - 1] || 'default';
            return roomId;
        }


        let passwordHash = '';

        function generateUserKey(username, passwordHash = '') {
            const pass = passwordHash || CryptoJS.SHA256('').toString();
            return CryptoJS.SHA256(roomId + pass + CryptoJS.SHA256(username).toString()).toString().substring(0, 32);
        }

        function encryptMessage(message, username, password = '') {
            try {
                const userKey = generateUserKey(username, password);
                const ivBytes = new Uint8Array(16);
                crypto.getRandomValues(ivBytes);
                const ivHex = Array.from(ivBytes).map(b => b.toString(16).padStart(2, '0')).join('');
                const iv = CryptoJS.enc.Hex.parse(ivHex);

                const encrypted = CryptoJS.AES.encrypt(message, CryptoJS.enc.Utf8.parse(userKey), {
                    iv: iv,
                    mode: CryptoJS.mode.CBC,
                    padding: CryptoJS.pad.Pkcs7
                });

                return ivHex + encrypted.toString();
            } catch (error) {
                console.error('Encryption error:', error);
                return message;
            }
        }

        function decryptMessage(encryptedMessage, username, password = '') {
            try {
                const userKey = generateUserKey(username, password);
                const ivHex = encryptedMessage.substring(0, 32);
                const ciphertext = encryptedMessage.substring(32);
                const iv = CryptoJS.enc.Hex.parse(ivHex);

                const decrypted = CryptoJS.AES.decrypt(ciphertext, CryptoJS.enc.Utf8.parse(userKey), {
                    iv: iv,
                    mode: CryptoJS.mode.CBC,
                    padding: CryptoJS.pad.Pkcs7
                });

                return decrypted.toString(CryptoJS.enc.Utf8);
            } catch (error) {
                console.error('Decryption error:', error);
                return encryptedMessage;
            }
        }

        function wordArrayToUint8Array(wordArray) {
            const words = wordArray.words;
            const sigBytes = wordArray.sigBytes;
            const u8 = new Uint8Array(sigBytes);
            for (let i = 0; i < sigBytes; i++)
                u8[i] = (words[i >>> 2] >>> (24 - (i % 4) * 8)) & 0xff;
            return u8;
        }

        async function encryptFile(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    try {
                        const wa = CryptoJS.lib.WordArray.create(e.target.result);
                        const key = generateUserKey(currentUser, passwordHash);
                        const ivBytes = new Uint8Array(16);
                        crypto.getRandomValues(ivBytes);
                        const ivHex = Array.from(ivBytes).map(b => b.toString(16).padStart(2, '0')).join('');
                        const iv = CryptoJS.enc.Hex.parse(ivHex);
                        const enc = CryptoJS.AES.encrypt(wa, CryptoJS.enc.Utf8.parse(key), {
                            iv: iv,
                            mode: CryptoJS.mode.CBC,
                            padding: CryptoJS.pad.Pkcs7
                        });
                        resolve(ivHex + enc.toString());
                    } catch(err) { reject(err); }
                };
                reader.onerror = reject;
                reader.readAsArrayBuffer(file);
            });
        }

        async function fetchAndDecryptFile(url, senderUsername, mimeType) {
            const res = await fetch(url);
            const raw = await res.text();
            const ivHex = raw.substring(0, 32);
            const ciphertext = raw.substring(32);
            const key = generateUserKey(senderUsername, passwordHash);
            const iv = CryptoJS.enc.Hex.parse(ivHex);
            const dec = CryptoJS.AES.decrypt(ciphertext, CryptoJS.enc.Utf8.parse(key), {
                iv: iv,
                mode: CryptoJS.mode.CBC,
                padding: CryptoJS.pad.Pkcs7
            });
            return new Blob([wordArrayToUint8Array(dec)], { type: mimeType });
        }
        
        function validateUsername(username) {
            const regex = /^[a-zA-Z0-9_]{3,20}$/;
            if (!regex.test(username)) {
                return false;
            }

            const reservedNames = ['admin', 'system'];
            if (reservedNames.includes(username.toLowerCase())) {
                return false;
            }
            return true;
        }
        

        async function verifyPassword(password) {
            try {
                const response = await fetch('chat.php?action=getMessages');
                const messages = await response.json();

                if (messages.length >= 2 && messages[1].user === 'admin') {
                    const pwdHash = CryptoJS.SHA256(password).toString();
                    const decrypted = decryptMessage(messages[1].message, 'admin', pwdHash);
                    return decrypted.startsWith('<h2 style="color: #2c3e50; margin-top: 0;">Welcome to Your Secure Chat!</h2>');
                }
                return false;
            } catch (error) {
                console.error('Password verification error:', error);
                return false;
            }
        }

        async function login() {
            const username = document.getElementById('username').value.trim();
            const password = prompt('Enter room password (leave empty if none):') || '';

            if (!validateUsername(username)) {
                alert('Username must contain only letters and numbers (min 3 max 20). Reserved names (admin, system) are not allowed.');
                return;
            }


            const passwordValid = await verifyPassword(password);
            if (!passwordValid) {
                alert('Incorrect password! Access denied.');
                return;
            }

            passwordHash = CryptoJS.SHA256(password).toString();

            if (username) {
                addUser(username)
                    .then(() => {
                        document.getElementById('login').style.display = 'none';
                        document.getElementById('chat-container').style.display = 'flex';
                        getMessages();
                        checkRoomAccess();
                        setInterval(getMessages, 2000);
                        setInterval(getUsers, 5000);
                        setInterval(checkRoomAccess, 10000);
                    })
                    .catch(error => {
                        alert('Access denied: ' + error.message);
                        console.error('Login failed:', error);
                    });
            }
        }

        function addUser(user) {
            return fetch('chat.php?action=addUser', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `user=${encodeURIComponent(user)}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.user) {
                        currentUser = data.user;
                        console.log('User added successfully:', currentUser);
                        return currentUser;
                    } else {
                        throw new Error(data.error || 'Failed to add user');
                    }
                });
        }

        function getUsers() {
            fetch('chat.php?action=getUsers')
                .then(response => response.json())
                .then(users => {
                    const userList = document.getElementById('user-list');
                    userList.innerHTML = '<h3>Connected users</h3>';

                    users.reverse();

                    users.slice(0, 30).forEach(user => {
                        const userElement = document.createElement('p');
                        userElement.textContent = user;
                        
                        if (user === currentUser) {
                            userElement.classList.add('current-user-name');
                        }
                        
                        userList.appendChild(userElement);
                    });
                });
        }

        function checkRoomAccess() {
            fetch('chat.php?action=getRoomAccess')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const preventAccessSwitch = document.getElementById('preventAccessSwitch');
                        preventAccessSwitch.checked = data.blocked || false;
                    }
                })
                .catch(error => console.error('Error checking room access:', error));
        }

        function isMessageAlreadyDisplayed(messageId) {
            return displayedMessageIds.has(messageId);
        }

        function markMessageAsDisplayed(messageId) {
            displayedMessageIds.add(messageId);
        }

        function getSessionPwd() {
            const userRooms = JSON.parse(getCookie('userRooms') || '[]');
            const room = userRooms.find(r => r.id === roomId);
            return (room && room.sessionPwd) ? room.sessionPwd : '';
        }

        function sendMessage() {
            const messageInput = document.getElementById('message');
            const message = messageInput.value;
            if (message) {
                const isDeleteAll = message.trim() === '/deleteall';

                const encryptedMessage = encryptMessage(message, currentUser, passwordHash);

                let body = `user=${encodeURIComponent(currentUser)}&message=${encodeURIComponent(encryptedMessage)}`;
                if (isDeleteAll) {
                    body += '&command=deleteall';
                    const sessionPwd = getSessionPwd();
                    if (sessionPwd) {
                        body += '&sessionPwd=' + encodeURIComponent(sessionPwd);
                    }
                }

                fetch('chat.php?action=sendMessage', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: body
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.error && isDeleteAll) {
                            alert(data.error);
                            return;
                        }
                        if (data.success) {
                            messageInput.value = '';

                            if (data.action === 'cleared') {

                                const messageList = document.getElementById('message-list');
                                const allMessages = messageList.querySelectorAll('.message');
                                allMessages.forEach((msgElement, index) => {
                                    if (index >= 2) {
                                        msgElement.remove();
                                    }
                                });
                                lastMessageId = 2;
                                const newDisplayedIds = new Set();
                                newDisplayedIds.add(1);
                                newDisplayedIds.add(2);
                                displayedMessageIds = newDisplayedIds;
                            }
                        }
                    });
            }
        }

        function getMessages() {
            fetch(`chat.php?action=getNewMessages&lastId=${lastMessageId}`)
                .then(response => response.json())
                .then(messages => {
                    if (!messages || messages.length === 0) {
                        return;
                    }

                    const messageList = document.getElementById('message-list');
                    const isAtBottom = messageList.scrollHeight - messageList.clientHeight <= messageList.scrollTop + 1;

                    let shouldPlaySound = false;
                    let maxMessageId = lastMessageId;

                    messages.forEach(msg => {

                        if (msg.type === 'system' && msg.message.includes('The chat was deleted by')) {

                            const allMessages = messageList.querySelectorAll('.message');
                            allMessages.forEach((msgElement, index) => {
                                if (index >= 2) {
                                    msgElement.remove();
                                }
                            });

                            maxMessageId = 2;
                            const newDisplayedIds = new Set();
                            newDisplayedIds.add(1);
                            newDisplayedIds.add(2);
                            displayedMessageIds = newDisplayedIds;
                        }


                        if (isMessageAlreadyDisplayed(msg.id)) {
                            if (msg.id > maxMessageId) {
                                maxMessageId = msg.id;
                            }
                            return;
                        }

                        const messageElement = document.createElement('div');
                        messageElement.className = 'message';
                        messageElement.setAttribute('data-message-id', msg.id);

                        if (msg.timestamp) {
                            const timestampSpan = document.createElement('span');
                            timestampSpan.className = 'timestamp';
                            const date = new Date(msg.timestamp * 1000);
                            timestampSpan.textContent = date.toLocaleString('it-IT', {
                                hour: '2-digit',
                                minute: '2-digit',
                                day: '2-digit',
                                month: '2-digit',
                                year: 'numeric'
                            });
                            messageElement.appendChild(timestampSpan);
                        }

                    if (msg.type === 'system') {
                        messageElement.classList.add('system');
                        if (msg.highlightedUser) {
                            const parts = msg.message.split('%s');
                            messageElement.innerHTML += parts[0] + '<b>' + msg.highlightedUser + '</b>' + parts[1];
                        } else {
                            messageElement.innerHTML += msg.message;
                        }
                        shouldPlaySound = true;

                        const messageText = msg.message;

                        if (messageText.includes('Room access blocked by') || messageText.includes('Room access unblocked by')) {
                            const preventAccessSwitch = document.getElementById('preventAccessSwitch');
                            if (preventAccessSwitch) {
                                if (messageText.includes('Room access blocked by')) {
                                    preventAccessSwitch.checked = true;
                                } else if (messageText.includes('Room access unblocked by')) {
                                    preventAccessSwitch.checked = false;
                                }
                            }
                        }
                    } else {
                            if (msg.user === currentUser) {
                                messageElement.classList.add('current-user');
                            } else {
                                shouldPlaySound = true;
                            }
                            const userSpan = document.createElement('span');
                            userSpan.className = 'user';
                            userSpan.textContent = `${msg.user}: `;
                            messageElement.appendChild(userSpan);

                            switch (msg.type) {
                                case 'text': {
                                    const textSpan = document.createElement('span');
                                    textSpan.className = 'text';
                                    const decryptedMessage = decryptMessage(msg.message, msg.user, passwordHash);
                                    textSpan.innerHTML = decryptedMessage.replace(/\n/g, '<br>');
                                    messageElement.appendChild(textSpan);
                                    break;
                                }
                                case 'audio': {
                                    const audio = document.createElement('audio');
                                    audio.controls = true;
                                    try {
                                        const audioMeta = JSON.parse(decryptMessage(msg.message, msg.user, passwordHash));
                                        fetchAndDecryptFile(audioMeta.file, msg.user, audioMeta.mime || 'audio/mp4')
                                            .then(blob => { audio.src = URL.createObjectURL(blob); })
                                            .catch(e => console.error('Audio decrypt error:', e));
                                    } catch(e) { console.error('Audio meta parse error:', e); }
                                    messageElement.appendChild(audio);
                                    break;
                                }
                                case 'image': {
                                    const img = document.createElement('img');
                                    img.style.maxWidth = '100%';
                                    img.style.maxHeight = '370px';
                                    try {
                                        const imageMeta = JSON.parse(decryptMessage(msg.message, msg.user, passwordHash));
                                        fetchAndDecryptFile(imageMeta.file, msg.user, imageMeta.mime || 'image/jpeg')
                                            .then(blob => { img.src = URL.createObjectURL(blob); })
                                            .catch(e => console.error('Image decrypt error:', e));
                                    } catch(e) { console.error('Image meta parse error:', e); }
                                    messageElement.appendChild(img);
                                    break;
                                }
                                case 'video': {
                                    const video = document.createElement('video');
                                    video.controls = true;
                                    video.style.maxWidth = '100%';
                                    video.style.maxHeight = '370px';
                                    try {
                                        const videoMeta = JSON.parse(decryptMessage(msg.message, msg.user, passwordHash));
                                        fetchAndDecryptFile(videoMeta.file, msg.user, videoMeta.mime || 'video/mp4')
                                            .then(blob => { video.src = URL.createObjectURL(blob); })
                                            .catch(e => console.error('Video decrypt error:', e));
                                    } catch(e) { console.error('Video meta parse error:', e); }
                                    messageElement.appendChild(video);
                                    break;
                                }
                                case 'file': {
                                    const fileLabel = document.createTextNode('File: ');
                                    messageElement.appendChild(fileLabel);
                                    const link = document.createElement('a');
                                    try {
                                        const fileMeta = JSON.parse(decryptMessage(msg.message, msg.user, passwordHash));
                                        link.textContent = fileMeta.name || fileMeta.file;
                                        link.href = '#';
                                        link.addEventListener('click', async function(e) {
                                            e.preventDefault();
                                            try {
                                                const blob = await fetchAndDecryptFile(fileMeta.file, msg.user, fileMeta.mime || 'application/octet-stream');
                                                const blobUrl = URL.createObjectURL(blob);
                                                const a = document.createElement('a');
                                                a.href = blobUrl;
                                                a.download = fileMeta.name || fileMeta.file;
                                                document.body.appendChild(a);
                                                a.click();
                                                document.body.removeChild(a);
                                                URL.revokeObjectURL(blobUrl);
                                            } catch(err) { console.error('Download error:', err); }
                                        });
                                    } catch(e) { console.error('File meta parse error:', e); link.textContent = '[file]'; }
                                    messageElement.appendChild(link);
                                    break;
                                }
                            }
                        }

                        markMessageAsDisplayed(msg.id);
                        messageList.appendChild(messageElement);

                        if (msg.id > maxMessageId) {
                            maxMessageId = msg.id;
                        }
                    });

                    lastMessageId = maxMessageId;

                    if (shouldPlaySound && !document.getElementById('muteAudioSwitch').checked) {
                        document.getElementById('message-sound').play().catch(e => console.log("Error in sound playback:", e));
                    }

                    if (isAtBottom) {
                        messageList.scrollTop = messageList.scrollHeight;
                    }
                })
                .catch(error => {
                    console.error('Error fetching messages:', error);
                });
        }

        function clearChat() {
            fetch('chat.php?action=clearChat')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('message-list').innerHTML = '';
                        lastMessageId = 0;
                        displayedMessageIds.clear();
                        location.reload();
                    }
                });
        }

        function handleRecordButtonClick() {
            if (!isRecording) {
                startRecording();
            } else {
                stopRecording();
            }
        }

        function startRecording() {
            navigator.mediaDevices.getUserMedia({ audio: true })
                .then(audioStream => {
                    stream = audioStream;
                    audioChunks = [];
                    recorder = new RecordRTC(stream, {
                        type: 'audio',
                        mimeType: 'audio/mp4'
                    });
                    recorder.startRecording();
                    isRecording = true;
                    updateButtonState(true);
                })
                .catch(e => console.error('Error accessing the microphone:', e));
        }

        function stopRecording() {
            if (recorder) {
                recorder.stopRecording(() => {
                    let blob = recorder.getBlob();
                    isRecording = false;
                    updateButtonState(false);
                    checkAudioDurationAndSend(blob);

                    if (stream) {
                        stream.getTracks().forEach(track => track.stop());
                    }
                });
            }
        }

        function updateButtonState(recording) {
            const button = document.getElementById('voice-record-btn');
            if (recording) {
                button.textContent = 'REC...';
                button.classList.add('recording');
            } else {
                button.textContent = 'Audio';
                button.classList.remove('recording');
            }
        }

        function checkAudioDurationAndSend(blob) {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const reader = new FileReader();

            reader.onloadend = () => {
                const arrayBuffer = reader.result;
                audioContext.decodeAudioData(arrayBuffer, (audioBuffer) => {
                    const duration = audioBuffer.duration;
                    if (duration >= 1) {
                        sendAudioMessage(blob);
                    } else {
                        console.warn('Recording discarded: duration less than 1 second.');
                    }
                });
            };
            reader.readAsArrayBuffer(blob);
        }

        async function sendAudioMessage(blob) {
            const progressBar = document.getElementById('upload-progress-bar');
            const progressText = document.getElementById('upload-progress-text');
            document.getElementById('upload-progress').style.display = 'block';
            progressText.textContent = 'Encrypting...';
            progressBar.value = 0;

            try {
                const encryptedContent = await encryptFile(blob);

                progressText.textContent = 'Uploading... 0%';

                const audioFileName = 'audio_' + crypto.randomUUID() + '.enc';
                const audioMeta = JSON.stringify({ file: 'audio_messages/' + audioFileName, name: 'audio.mp4', mime: 'audio/mp4' });
                const encryptedMeta = encryptMessage(audioMeta, currentUser, passwordHash);

                const formData = new FormData();
                formData.append('encryptedAudio', encryptedContent);
                formData.append('audioFileName', audioFileName);
                formData.append('encryptedMeta', encryptedMeta);
                formData.append('user', currentUser);
                formData.append('action', 'sendAudioMessage');

                const xhr = new XMLHttpRequest();

                xhr.upload.onprogress = function(e) {
                    if (e.lengthComputable) {
                        const pct = (e.loaded / e.total) * 100;
                        progressBar.value = pct;
                        progressText.textContent = pct.toFixed(2) + '%';
                    }
                };

                xhr.onload = function() {
                    document.getElementById('upload-progress').style.display = 'none';
                    if (xhr.status === 200) {
                        const data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            document.getElementById('message-sound').play().catch(() => {});
                        } else {
                            console.error('Audio send error:', data.error);
                        }
                    }
                };

                xhr.onerror = function() {
                    document.getElementById('upload-progress').style.display = 'none';
                    console.error('Error sending audio message');
                };

                xhr.open('POST', 'chat.php', true);
                xhr.send(formData);
            } catch(err) {
                console.error('Error encrypting audio:', err);
                document.getElementById('upload-progress').style.display = 'none';
            }
        }

        document.getElementById('file-upload-btn').addEventListener('click', function () {
            document.getElementById('file-input').click();
        });

        document.getElementById('file-input').addEventListener('change', handleFileUpload);

        async function handleFileUpload(event) {
            const file = event.target.files[0];
            if (!file) return;
            event.target.value = '';

            const progressBar = document.getElementById('upload-progress-bar');
            const progressText = document.getElementById('upload-progress-text');
            document.getElementById('upload-progress').style.display = 'block';
            progressText.textContent = 'Encrypting...';
            progressBar.value = 0;

            try {
                const encryptedContent = await encryptFile(file);

                progressText.textContent = 'Uploading... 0%';

                const uploadFileName = 'f_' + crypto.randomUUID() + '.enc';
                const fileMeta = JSON.stringify({ file: 'upload_files/' + uploadFileName, name: file.name, mime: file.type || 'application/octet-stream' });
                const encryptedFileMeta = encryptMessage(fileMeta, currentUser, passwordHash);

                const formData = new FormData();
                formData.append('encryptedFile', encryptedContent);
                formData.append('uploadFileName', uploadFileName);
                formData.append('encryptedMeta', encryptedFileMeta);
                formData.append('mimeType', file.type || 'application/octet-stream');
                formData.append('user', currentUser);
                formData.append('action', 'sendFile');

                const xhr = new XMLHttpRequest();

                xhr.upload.onprogress = function(e) {
                    if (e.lengthComputable) {
                        const pct = (e.loaded / e.total) * 100;
                        progressBar.value = pct;
                        progressText.textContent = pct.toFixed(2) + '%';
                    }
                };

                xhr.onload = function() {
                    document.getElementById('upload-progress').style.display = 'none';
                    if (xhr.status === 200) {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            document.getElementById('message-sound').play().catch(e => console.log('Error in sound playback:', e));
                        } else {
                            console.error('Error uploading the file:', response.error);
                        }
                    } else {
                        console.error('Error in the request:', xhr.status);
                    }
                };

                xhr.onerror = function() {
                    console.error('Error in the request');
                    document.getElementById('upload-progress').style.display = 'none';
                };

                xhr.open('POST', 'chat.php', true);
                xhr.send(formData);
            } catch(err) {
                console.error('Error encrypting file:', err);
                document.getElementById('upload-progress').style.display = 'none';
            }
        }

        const enterSendSwitch = document.getElementById('enterSendSwitch');
        const muteAudioSwitch = document.getElementById('muteAudioSwitch');
        const preventAccessSwitch = document.getElementById('preventAccessSwitch');
        const messageInput = document.getElementById('message');


        setTimeout(function() {
            if (!muteAudioSwitch.checked) {
                muteAudioSwitch.checked = true;
                muteAudioSwitch.dispatchEvent(new Event('change'));
            }
            if (!enterSendSwitch.checked) {
                enterSendSwitch.checked = true;
                enterSendSwitch.dispatchEvent(new Event('change'));
            }
        }, 500);

        enterSendSwitch.addEventListener('change', function () {
            if (this.checked) {
                messageInput.addEventListener('keypress', handleEnterKey);
            } else {
                messageInput.removeEventListener('keypress', handleEnterKey);
            }
        });

        function handleEnterKey(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const messageInput = document.getElementById('message');
                if (messageInput.value.trim()) {
                    sendMessage();
                }
            }
        }

        muteAudioSwitch.addEventListener('change', function () {
            const messageSound = document.getElementById('message-sound');
            if (messageSound) {
                messageSound.muted = this.checked;
            }
        });

        preventAccessSwitch.addEventListener('change', function () {
            const isBlocked = this.checked;
            fetch('chat.php?action=setRoomAccess', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `user=${encodeURIComponent(currentUser)}&blocked=${isBlocked}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Room access status updated:', isBlocked);
                } else {
                    console.error('Failed to update room access status');
                    this.checked = !isBlocked;
                }
            })
            .catch(error => {
                console.error('Error updating room access:', error);
                this.checked = !isBlocked;
            });
        });

        document.getElementById('voice-record-btn').addEventListener('click', handleRecordButtonClick);

        window.addEventListener('beforeunload', function(e) {
            handleUserLeaving();
        });

        window.addEventListener('pagehide', function(e) {
            handleUserLeaving();
        });

        function handleUserLeaving() {
            if (currentUser) {
                navigator.sendBeacon('chat.php', new URLSearchParams({
                    action: 'removeUser',
                    user: currentUser
                }));
            }
        }
    </script>

    <audio id="message-sound" src="<?php echo preg_replace('#/rooms/.*$#', '', dirname($_SERVER['SCRIPT_NAME'])); ?>/audio/message_sent.mp3"></audio>

    <?php include '../../room_modal.php'; ?>

    <script>
        document.getElementById('myRoomsButton').addEventListener('click', function() {
            openRoomModal();
        });
    </script>
</body>

</html>