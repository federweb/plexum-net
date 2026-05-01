<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/room_auth.php';

function deleteDirectory(string $path): bool {
    if (!is_dir($path)) return false;
    foreach (array_diff(scandir($path), ['.', '..']) as $item) {
        $full = "$path/$item";
        is_dir($full) ? deleteDirectory($full) : unlink($full);
    }
    return rmdir($path);
}

$inputData = json_decode(file_get_contents('php://input'), true);
$roomId    = $inputData['roomId'] ?? '';

if (!preg_match('/^[0-9a-f]{8}$/', $roomId)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$inputSessionPwd = $inputData['sessionPwd'] ?? '';
$sessionPwd = preg_match('/^[0-9a-f]{32}$/', $inputSessionPwd)
    ? $inputSessionPwd
    : ($_SESSION['owned_rooms'][$roomId] ?? '');

if (!preg_match('/^[0-9a-f]{32}$/', $sessionPwd) || !verifyRoomOwnership($roomId, $sessionPwd)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$folderPath = __DIR__ . "/rooms/$roomId";

if (!is_dir($folderPath)) {
    echo json_encode(['success' => false, 'error' => 'Room not found']);
    exit;
}

echo json_encode(deleteDirectory($folderPath)
    ? ['success' => true]
    : ['success' => false, 'error' => 'Failed to delete room']
);
