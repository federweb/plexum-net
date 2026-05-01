<?php
function verifyRoomOwnership(string $roomId, string $sessionPwd): bool {
    if (!preg_match('/^[0-9a-f]{8}$/', $roomId)) return false;
    if (!preg_match('/^[0-9a-f]{32}$/', $sessionPwd)) return false;
    $expected = substr(hash('sha256', $sessionPwd), 0, 8);
    return hash_equals($expected, $roomId);
}
