<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/room_auth.php';

function getUserInfo() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    $browser = 'Unknown';
    $os = 'Unknown';

    if (strpos($userAgent, 'Firefox') !== false) {
        $browser = 'Firefox';
    } elseif (strpos($userAgent, 'Chrome') !== false) {
        $browser = 'Chrome';
    } elseif (strpos($userAgent, 'Safari') !== false) {
        $browser = 'Safari';
    } elseif (strpos($userAgent, 'Opera') !== false || strpos($userAgent, 'OPR') !== false) {
        $browser = 'Opera';
    } elseif (strpos($userAgent, 'MSIE') !== false || strpos($userAgent, 'Trident/7') !== false) {
        $browser = 'Internet Explorer';
    }

    if (strpos($userAgent, 'Win') !== false) {
        $os = 'Windows';
    } elseif (strpos($userAgent, 'Mac') !== false) {
        $os = 'MacOS';
    } elseif (strpos($userAgent, 'Linux') !== false) {
        $os = 'Linux';
    }

    return ['browser' => $browser, 'os' => $os];
}


function logRoomCreation($roomId) {
    $logFile = __DIR__ . '/json/log_room.json';
    $userInfo = getUserInfo();
    $logEntry = [
        'roomId' => $roomId,
        'ip' => $_SERVER['REMOTE_ADDR'],
        'timestamp' => time(),
        'browser' => $userInfo['browser'],
        'os' => $userInfo['os']
    ];

    if (file_exists($logFile)) {
        $logData = json_decode(file_get_contents($logFile), true);
    } else {
        $logData = [];
    }

    $logData[] = $logEntry;
    file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT));
}

function checkRateLimit() {
    $rateFile = __DIR__ . '/json/rate_limit.json';
    $ip = $_SERVER['REMOTE_ADDR'];
    $currentTime = time();

    if (file_exists($rateFile)) {
        $rateData = json_decode(file_get_contents($rateFile), true);
    } else {
        $rateData = [];
    }

    if (!isset($rateData[$ip])) {
        $rateData[$ip] = ['hourly' => [], 'daily' => []];
    }

    $rateData[$ip]['hourly'] = array_filter($rateData[$ip]['hourly'], function($time) use ($currentTime) {
        return $currentTime - $time < 3600;
    });
    $rateData[$ip]['daily'] = array_filter($rateData[$ip]['daily'], function($time) use ($currentTime) {
        return $currentTime - $time < 86400;
    });

    if (count($rateData[$ip]['hourly']) >= 5 || count($rateData[$ip]['daily']) >= 15) {
        return false;
    }

    $rateData[$ip]['hourly'][] = $currentTime;
    $rateData[$ip]['daily'][] = $currentTime;

    file_put_contents($rateFile, json_encode($rateData));
    return true;
}

function createRoom(string $sessionPwd, array $messages): array {
    if (!checkRateLimit()) {
        return ['success' => false, 'error' => 'exceed limit creation rooms'];
    }

    $roomId = substr(hash('sha256', $sessionPwd), 0, 8);

    $folderPath = __DIR__ . "/rooms/$roomId";

    if (file_exists($folderPath)) {
        return ['success' => false, 'error' => 'Room ID already exists'];
    }

    if (!mkdir($folderPath, 0777, true)) {
        return ['success' => false, 'error' => 'Error create room folder'];
    }

    $zipFile = __DIR__ . '/CORE/core.zip';
    $destination = "$folderPath/core.zip";

    if (!copy($zipFile, $destination)) {
        return ['success' => false, 'error' => 'Error copy zip file'];
    }

    $zip = new ZipArchive;
    if ($zip->open($destination) === TRUE) {
        $zip->extractTo($folderPath);
        $zip->close();
        unlink($destination);

        file_put_contents("$folderPath/messages.json", json_encode($messages, JSON_PRETTY_PRINT));

    } else {
        return ['success' => false, 'error' => 'Error extract zip file'];
    }

    logRoomCreation($roomId);

    return ['success' => true, 'roomId' => $roomId];
}


$inputData = json_decode(file_get_contents('php://input'), true);

if (!$inputData || !isset($inputData['sessionPwd']) || !isset($inputData['messages']) || !is_array($inputData['messages'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$result = createRoom($inputData['sessionPwd'], $inputData['messages']);

if ($result['success']) {
    if (!isset($_SESSION['owned_rooms'])) {
        $_SESSION['owned_rooms'] = [];
    }
    $_SESSION['owned_rooms'][$result['roomId']] = $inputData['sessionPwd'];
}

echo json_encode($result);
