<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../room_auth.php';

$messagesFile = 'messages.json';
$usersFile = 'users.json';
$roomAccessFile = 'room_access.json';
$audioDirectory = 'audio_messages/';
$uploadDirectory = 'upload_files/';

$currentDate = date('d/m/Y, H:i');

if (!file_exists($uploadDirectory)) {
    mkdir($uploadDirectory, 0777, true);
    file_put_contents($uploadDirectory . '/index.php', 'Vi veri universum vivus vici');
}
if (!file_exists($audioDirectory)) {
    mkdir($audioDirectory, 0777, true);
    file_put_contents($audioDirectory . '/index.php', 'Vi veri universum vivus vici');
}


function getCurrentRoomId() {
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    $pathParts = explode('/', trim($scriptPath, '/'));
    array_pop($pathParts);
    return end($pathParts) ?: 'default';
}


$roomId = getCurrentRoomId();



function loadMessages() {
    global $messagesFile;
    
    if (!file_exists($messagesFile)) {
        return [];
    }
    
    $fp = fopen($messagesFile, 'r');
    if (!$fp) {
        return [];
    }
    
    if (flock($fp, LOCK_SH)) {
        $content = '';
        while (!feof($fp)) {
            $content .= fread($fp, 8192);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        
        $decoded = json_decode($content, true);
        return $decoded ? $decoded : [];
    } else {
        fclose($fp);
        return [];
    }
}

function saveMessages($messages) {
    global $messagesFile;
    
    $tempFile = $messagesFile . '.tmp.' . uniqid();
    $content = json_encode($messages);
    
    $fp = fopen($tempFile, 'w');
    if (!$fp) {
        return false;
    }
    
    if (flock($fp, LOCK_EX)) {
        $bytesWritten = fwrite($fp, $content);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        
        if ($bytesWritten === strlen($content)) {
            if (rename($tempFile, $messagesFile)) {
                return true;
            }
        }
    } else {
        fclose($fp);
    }
    
    if (file_exists($tempFile)) {
        unlink($tempFile);
    }
    return false;
}

function loadUsers() {
    global $usersFile;
    if (!file_exists($usersFile)) {
        return [];
    }

    $fp = fopen($usersFile, 'r');
    if (!$fp) {
        return [];
    }

    if (flock($fp, LOCK_SH)) {
        $content = '';
        while (!feof($fp)) {
            $content .= fread($fp, 8192);
        }
        flock($fp, LOCK_UN);
        fclose($fp);

        $decoded = json_decode($content, true);
        return $decoded ? $decoded : [];
    } else {
        fclose($fp);
        return [];
    }
}

function saveUsers($users) {
    global $usersFile;

    $tempFile = $usersFile . '.tmp.' . uniqid();
    $content = json_encode($users);

    $fp = fopen($tempFile, 'w');
    if (!$fp) {
        return false;
    }

    if (flock($fp, LOCK_EX)) {
        $bytesWritten = fwrite($fp, $content);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($bytesWritten === strlen($content)) {
            if (rename($tempFile, $usersFile)) {
                return true;
            }
        }
    } else {
        fclose($fp);
    }

    if (file_exists($tempFile)) {
        unlink($tempFile);
    }
    return false;
}

function loadRoomAccess() {
    global $roomAccessFile;
    if (!file_exists($roomAccessFile)) {
        return ['blocked' => false];
    }

    $fp = fopen($roomAccessFile, 'r');
    if (!$fp) {
        return ['blocked' => false];
    }

    if (flock($fp, LOCK_SH)) {
        $content = '';
        while (!feof($fp)) {
            $content .= fread($fp, 8192);
        }
        flock($fp, LOCK_UN);
        fclose($fp);

        $decoded = json_decode($content, true);
        return $decoded ? $decoded : ['blocked' => false];
    } else {
        fclose($fp);
        return ['blocked' => false];
    }
}

function saveRoomAccess($data) {
    global $roomAccessFile;

    $tempFile = $roomAccessFile . '.tmp.' . uniqid();
    $content = json_encode($data);

    $fp = fopen($tempFile, 'w');
    if (!$fp) {
        return false;
    }

    if (flock($fp, LOCK_EX)) {
        $bytesWritten = fwrite($fp, $content);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($bytesWritten === strlen($content)) {
            if (rename($tempFile, $roomAccessFile)) {
                return true;
            }
        }
    } else {
        fclose($fp);
    }

    if (file_exists($tempFile)) {
        unlink($tempFile);
    }
    return false;
}

function isRoomBlocked() {
    $roomData = loadRoomAccess();
    return $roomData['blocked'] ?? false;
}

function safeAddMessage($user, $message, $type = 'text', $mimeType = null) {
    $maxRetries = 5;
    $baseDelay = 100000;
    
    for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
        $messages = loadMessages();


        $newId = !empty($messages) ? max(array_column($messages, 'id')) + 1 : 1;
        
        $newMessage = [
            'id' => $newId,
            'user' => $user,
            'message' => $message,
            'type' => $type,
            'timestamp' => time()
        ];
        
        if ($mimeType) {
            $newMessage['mimeType'] = $mimeType;
        }
        
        $messages[] = $newMessage;
        
        if (saveMessages($messages)) {
            return ['success' => true, 'id' => $newId];
        }
        
        $delay = $baseDelay * pow(2, $attempt);
        usleep($delay + rand(0, $delay / 2));
    }
    
    return ['success' => false, 'error' => 'Failed to save message after retries'];
}

function addSystemMessage($message) {
    safeAddMessage('Sistema', $message, 'system');
}


$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'addUser':
        $user = $_POST['user'] ?? '';
        $user = trim($user);
        $user = preg_replace('/[\x00-\x1F\x7F<>&\/\\\\]/', '', $user);
        $user = Normalizer::normalize($user, Normalizer::FORM_C);
        $user = mb_substr($user, 0, 50, 'UTF-8');

        if (!empty($user)) {

            if (strtolower($user) === 'admin' || strtolower($user) === 'system') {
                echo json_encode(['error' => 'Username not allowed. Please choose a different name.']);
                break;
            }

            $users = loadUsers();


            if (isRoomBlocked()) {
                echo json_encode(['error' => 'Room access is currently blocked. No new participants allowed.']);
                break;
            }

            $baseUser = $user;
            $suffix = 1;


            while (in_array($user, $users)) {
                $user = $baseUser . '_' . $suffix;
                $suffix++;
            }

            $users[] = $user;
            saveUsers($users);
            addSystemMessage("$currentDate: $user has joined the room");

            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['error' => 'Not Valid: User name']);
        }
        break;

    case 'getUsers':
        echo json_encode(loadUsers());
        break;

    case 'sendMessage':
        $user = $_POST['user'] ?? '';
        $message = $_POST['message'] ?? '';
        $command = $_POST['command'] ?? '';
        $postSessionPwd = $_POST['sessionPwd'] ?? '';
        $sessionPwd = preg_match('/^[0-9a-f]{32}$/', $postSessionPwd)
            ? $postSessionPwd
            : ($_SESSION['owned_rooms'][$roomId] ?? '');

        if ($user && $message) {
            if ($command === 'deleteall') {
                if (!verifyRoomOwnership($roomId, $sessionPwd)) {
                    echo json_encode(['error' => 'Only the room creator can use /deleteall']);
                    break;
                }
                $lockFile = 'chat.lock';
                $lockFp = fopen($lockFile, 'w');
                if (flock($lockFp, LOCK_EX)) {
                    // Load current messages and keep only the first 2 (welcome messages)
                    $currentMessages = loadMessages();
                    $welcomeMessages = array_slice($currentMessages, 0, 2);
                    saveMessages($welcomeMessages);
                    array_map('unlink', glob($audioDirectory . "*"));
                    array_map('unlink', glob($uploadDirectory . "*"));
                    addSystemMessage("The chat was deleted by $user");
                    flock($lockFp, LOCK_UN);
                    fclose($lockFp);
                    unlink($lockFile);
                }
                echo json_encode(['success' => true, 'action' => 'cleared']);
            } else {
                $result = safeAddMessage($user, $message, 'text');
                echo json_encode($result);
            }
        } else {
            echo json_encode(['error' => 'Missing user or message']);
        }
        break;

    case 'sendAudioMessage':
        $user = $_POST['user'] ?? '';
        $encryptedContent = $_POST['encryptedAudio'] ?? '';
        $encryptedMeta = $_POST['encryptedMeta'] ?? '';
        $audioFileName = preg_replace('/[^a-zA-Z0-9_\-.]/', '', basename($_POST['audioFileName'] ?? ''));

        if (!preg_match('/^[a-zA-Z0-9_\-]+\.enc$/', $audioFileName)) {
            echo json_encode(['error' => 'Invalid audio file name format']);
            break;
        }

        if ($user && $encryptedContent && $encryptedMeta && $audioFileName) {
            $filePath = $audioDirectory . $audioFileName;
            if (file_put_contents($filePath, $encryptedContent) !== false) {
                $result = safeAddMessage($user, $encryptedMeta, 'audio');
                echo json_encode($result);
            } else {
                echo json_encode(['error' => 'Failed to save audio']);
            }
        } else {
            echo json_encode(['error' => 'Missing audio data']);
        }
        break;

    case 'getMessages':
        echo json_encode(loadMessages());
        break;

    case 'getNewMessages':
        $lastId = intval($_GET['lastId'] ?? 0);
        $messages = loadMessages();

        // Check if chat was reset (lastId > all message IDs means chat was cleared)
        $maxCurrentId = !empty($messages) ? max(array_column($messages, 'id')) : 0;

        // If lastId is greater than max current ID, chat was cleared - send all messages
        if ($lastId > $maxCurrentId && count($messages) > 0) {
            $newMessages = $messages;
        } else {
            // Normal case: filter messages after lastId
            $newMessages = array_filter($messages, function($msg) use ($lastId) {
                return $msg['id'] > $lastId;
            });
        }

        echo json_encode(array_values($newMessages));
        break;
        
    case 'sendFile':
        $user = $_POST['user'] ?? '';
        $mimeType = $_POST['mimeType'] ?? 'application/octet-stream';
        $encryptedContent = $_POST['encryptedFile'] ?? '';
        $encryptedMeta = $_POST['encryptedMeta'] ?? '';
        $uploadFileName = preg_replace('/[^a-zA-Z0-9_\-.]/', '', basename($_POST['uploadFileName'] ?? ''));

        if (!preg_match('/^[a-zA-Z0-9_\-]+\.enc$/', $uploadFileName)) {
            echo json_encode(['error' => 'Invalid upload file name format']);
            break;
        }

        if ($user && $encryptedContent && $encryptedMeta && $uploadFileName) {
            $filePath = $uploadDirectory . $uploadFileName;
            if (file_put_contents($filePath, $encryptedContent) !== false) {
                $isImage = strpos($mimeType, 'image') === 0;
                $isVideo = strpos($mimeType, 'video') === 0;
                $type = $isImage ? 'image' : ($isVideo ? 'video' : 'file');
                $result = safeAddMessage($user, $encryptedMeta, $type, $mimeType);
                echo json_encode($result['success'] ? ['success' => true] : ['error' => 'Failed to save file message']);
            } else {
                echo json_encode(['error' => 'Failed to save file']);
            }
        } else {
            echo json_encode(['error' => 'Missing upload data']);
        }
        break;

    case 'setRoomAccess':
        $user = $_POST['user'] ?? '';
        $blocked = $_POST['blocked'] ?? 'false';
        $blocked = ($blocked === 'true' || $blocked === '1');
        
        if ($user) {
            $users = loadUsers();

            if (in_array($user, $users)) {
                $roomData = loadRoomAccess();
                $roomData['blocked'] = $blocked;
                $roomData['blocked_by'] = $user;
                $roomData['timestamp'] = time();
                saveRoomAccess($roomData);
                
                $statusMessage = $blocked ? "Room access blocked by $user" : "Room access unblocked by $user";
                addSystemMessage("$currentDate: $statusMessage");
                
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'User not authorized']);
            }
        } else {
            echo json_encode(['error' => 'Missing user']);
        }
        break;
        
    case 'getRoomAccess':
        $roomData = loadRoomAccess();
        echo json_encode(['success' => true, 'blocked' => $roomData['blocked']]);
        break;

    case 'removeUser':
        $user = $_POST['user'] ?? '';
        if ($user) {
            $users = loadUsers();
            $index = array_search($user, $users);
            if ($index !== false) {
                array_splice($users, $index, 1);
                saveUsers($users);
                addSystemMessage("$user has left the room");
            }
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Missing user']);
        }
        break;

    default:
        echo json_encode(['error' => 'Invalid Action']);
}
?>