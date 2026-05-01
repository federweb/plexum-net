<?php
// Secure Keychain API - Zero-Knowledge Architecture
// The server ONLY stores encrypted blobs. It never sees passwords or plaintext.
// Even with full server access, encrypted notes cannot be decrypted without the password.

$keychainDir = __DIR__ . '/keychain/';
$maxDataSize = 5 * 1024 * 1024; // 5 MB max per note

// Create storage directory if it doesn't exist
if (!file_exists($keychainDir)) {
    mkdir($keychainDir, 0755, true);
    // Prevent direct web access (Apache)
    file_put_contents($keychainDir . '.htaccess', "Deny from all\n");
    // Prevent directory listing
    file_put_contents($keychainDir . 'index.html', '');
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? '';

// --- Save encrypted note ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    $rawInput = file_get_contents('php://input');

    if (strlen($rawInput) > $maxDataSize) {
        http_response_code(413);
        echo json_encode(['error' => 'Data too large']);
        exit;
    }

    $input = json_decode($rawInput, true);

    if (!$input || !isset($input['encryptedData'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request body']);
        exit;
    }

    $data = $input['encryptedData'];
    if (!isset($data['salt']) || !isset($data['iv']) || !isset($data['data']) ||
        !is_array($data['salt']) || !is_array($data['iv']) || !is_array($data['data'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid encrypted data structure']);
        exit;
    }

    // Generate cryptographically secure random ID (32 hex chars = 128 bits of entropy)
    $noteId = bin2hex(random_bytes(16));
    $filePath = $keychainDir . $noteId . '.json';

    // Handle collision (practically impossible with 128 bits)
    while (file_exists($filePath)) {
        $noteId = bin2hex(random_bytes(16));
        $filePath = $keychainDir . $noteId . '.json';
    }

    $stored = json_encode([
        'v' => 1,
        'encryptedData' => $data
    ]);

    if (file_put_contents($filePath, $stored) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save note']);
        exit;
    }

    echo json_encode(['success' => true, 'id' => $noteId]);
    exit;
}

// --- Load encrypted note ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'load') {
    $noteId = $_GET['id'] ?? '';

    // Strict validation: only lowercase hex, exactly 32 chars (prevents path traversal)
    if (!preg_match('/^[a-f0-9]{32}$/', $noteId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid note ID format']);
        exit;
    }

    $filePath = $keychainDir . $noteId . '.json';

    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Note not found']);
        exit;
    }

    $content = file_get_contents($filePath);
    $data = json_decode($content, true);

    if (!$data || !isset($data['encryptedData'])) {
        http_response_code(500);
        echo json_encode(['error' => 'Corrupted note data']);
        exit;
    }

    echo json_encode(['success' => true, 'encryptedData' => $data['encryptedData']]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
