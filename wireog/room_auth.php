<?php
function verifyRoomOwnership(string $roomId, string $sessionPwd): bool {
    if (!preg_match('/^[0-9a-f]{8}$/', $roomId)) return false;
    if (!preg_match('/^[0-9a-f]{32}$/', $sessionPwd)) return false;
    $expected = substr(hash('sha256', $sessionPwd), 0, 8);
    return hash_equals($expected, $roomId);
}

function isValidCiphertext($str) {
    if (!is_string($str) || strlen($str) < 32) {
        return false;
    }
    $ivHex = substr($str, 0, 32);
    $b64 = substr($str, 32);
    if (!preg_match('/^[0-9a-f]{32}$/', $ivHex)) {
        return false;
    }
    if ($b64 === '' || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $b64)) {
        return false;
    }
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) === 0 || strlen($raw) % 16 !== 0) {
        return false;
    }
    return true;
}
