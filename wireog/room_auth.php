<?php
function verifyRoomOwnership(string $roomId, string $sessionPwd): bool {
    if (!preg_match('/^[0-9a-f]{24}$/', $roomId)) return false;
    if (!preg_match('/^[0-9a-f]{32}$/', $sessionPwd)) return false;
    $expected = substr(hash('sha256', $sessionPwd), 0, 24);
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

// Per-user chat session tokens (anti-impersonation). Independent of the
// sessionPwd/verifyRoomOwnership mechanism above, which only proves room
// *ownership*. These prove "this browser is the one that logged in as
// this username", so chat.php can stop trusting a client-supplied user=
// field on writes. Lives in room_auth.php (not chat.php) so the fix
// applies to already-deployed rooms without redeploying their copy of
// chat.php/index.php.

function loadSessions(): array {
    if (!file_exists('sessions.json')) {
        return [];
    }
    $fp = fopen('sessions.json', 'r');
    if (!$fp) {
        return [];
    }
    $sessions = [];
    if (flock($fp, LOCK_SH)) {
        $content = '';
        while (!feof($fp)) {
            $content .= fread($fp, 8192);
        }
        flock($fp, LOCK_UN);
        $decoded = json_decode($content, true);
        $sessions = is_array($decoded) ? $decoded : [];
    }
    fclose($fp);
    return $sessions;
}

function saveSessions(array $sessions): bool {
    $file = 'sessions.json';
    $tempFile = $file . '.tmp.' . uniqid();
    $content = json_encode($sessions);

    $fp = fopen($tempFile, 'w');
    if (!$fp) {
        return false;
    }

    $ok = false;
    if (flock($fp, LOCK_EX)) {
        $bytesWritten = fwrite($fp, $content);
        fflush($fp);
        flock($fp, LOCK_UN);
        $ok = ($bytesWritten === strlen($content));
    }
    fclose($fp);

    if ($ok && rename($tempFile, $file)) {
        return true;
    }
    if (file_exists($tempFile)) {
        unlink($tempFile);
    }
    return false;
}

// Resolves a client-held session token to the username it was bound to
// at addUser time. Returns null if the token is malformed, unknown, or
// expired/pruned. $touch refreshes lastSeen (for the inactivity TTL) but
// only rewrites sessions.json when it's already >1h stale, so an active
// chatting tab doesn't rewrite the file on every single message.
function resolveUserFromToken(?string $token, bool $touch = true, int $touchThreshold = 3600): ?string {
    if (!$token || !preg_match('/^[0-9a-f]{32}$/', $token)) {
        return null;
    }
    $sessions = loadSessions();
    $hash = hash('sha256', $token);
    if (!isset($sessions[$hash]['user'])) {
        return null;
    }
    if ($touch && (time() - ($sessions[$hash]['lastSeen'] ?? 0)) > $touchThreshold) {
        $sessions[$hash]['lastSeen'] = time();
        saveSessions($sessions);
    }
    return $sessions[$hash]['user'];
}
