<?php
/**
 * AV STREAM — shared helpers.
 *
 * Low-bandwidth audio/video surveillance app for NodePulse.
 * Two roles served by the same folder:
 *   - SERVER (capture): the page opened in a browser ON the node device itself
 *     (http://127.0.0.1:8080/av_stream/). The browser is the hardware bridge:
 *     getUserMedia grabs camera/mic and pushes frames + audio chunks to api.php.
 *   - CLIENT (viewer): the page reached through the cloudflared tunnel.
 *     It starts/stops the streams and polls frames/audio.
 *
 * No web-server configuration is touched: plain PHP + short HTTP polling only.
 */

define('AV_DIR',    __DIR__);
define('AV_MEDIA',  __DIR__ . '/media');
define('AV_FRAMES', AV_MEDIA . '/frames');
define('AV_AUDIO',  AV_MEDIA . '/audio');

/* Data dir lives OUTSIDE the webroot (~/.nodepulse/av_stream), like the
 * auth_gate password hash. state.json (live frame names), config.json
 * (media_token) and guest_password.hash must never be fetchable as static
 * URLs — the web server serves anything under av_stream/ without PHP. */
if (PHP_OS_FAMILY !== 'Windows') {
    $avHome = getenv('HOME') ?: '/data/data/com.termux/files/home';
} else {
    $avHome = str_replace('\\', '/', (getenv('HOME') ?: getenv('USERPROFILE') ?: dirname(__DIR__)));
}
define('AV_DATA', $avHome . '/.nodepulse/av_stream');
define('AV_CONFIG_FILE', AV_DATA . '/config.json');
define('AV_STATE_FILE',  AV_DATA . '/state.json');

// Capture agent polls every ~1.5 s; consider it online within this window.
define('AV_CAPTURE_ONLINE_S', 8);

/**
 * Attach to the shared auth-gate session without rendering the gate UI.
 * Same read-only pattern used by the root dashboard.
 */
function av_session_attach(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    if (PHP_OS_FAMILY !== 'Windows') {
        $home = getenv('HOME') ?: '/data/data/com.termux/files/home';
    } else {
        $home = str_replace('\\', '/', (getenv('HOME') ?: getenv('USERPROFILE') ?: dirname(dirname(__DIR__))));
    }
    $sessDir = $home . '/tmp/.sessions';
    if (is_dir($sessDir)) session_save_path($sessDir);
    session_start();
}

/**
 * True when the request comes straight from the loopback interface.
 * REMOTE_ADDR is useless here: cloudflared runs on the same machine and also
 * connects from 127.0.0.1. Tunnel requests always carry Cloudflare headers and
 * a public Host, so their absence + a localhost Host means a direct request.
 */
function av_is_local(): bool {
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])
        || isset($_SERVER['HTTP_CF_RAY'])
        || isset($_SERVER['HTTP_CF_VISITOR'])) {
        return false;
    }
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    return in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true);
}

function av_read_json(string $file, $default = null) {
    if (!file_exists($file)) return $default;
    $fp = @fopen($file, 'r');
    if (!$fp) return $default;
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function av_write_json(string $file, array $data): void {
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function av_default_state(): array {
    return [
        'video_on'          => false,
        'audio_on'          => false,
        'zoom'              => 1.0,
        'seq_frame'         => 0,
        'seq_audio'         => 0,
        'frame_file'        => '',
        'audio_files'       => [],   // [['seq' => n, 'file' => name], ...] most recent last
        'viewers'           => [],   // viewer_id => last ping unix ts
        'last_capture_ping' => 0,
        'updated'           => '',
    ];
}

/**
 * Read-modify-write of state.json under a single exclusive lock so that
 * concurrent viewers and the capture agent never clobber each other.
 */
function av_state_update(callable $fn): array {
    if (!is_dir(AV_DATA)) mkdir(AV_DATA, 0700, true);
    $fp = fopen(AV_STATE_FILE, 'c+');
    flock($fp, LOCK_EX);
    $raw   = stream_get_contents($fp);
    $state = json_decode($raw, true);
    if (!is_array($state)) $state = [];
    $state = array_merge(av_default_state(), $state);
    $fn($state);
    $state['updated'] = gmdate('Y-m-d\TH:i:s\Z');
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($state, JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $state;
}

/**
 * Drop viewers that stopped polling and, when none are left, flip both
 * streams off. This is the server-side half of the auto-stop watchdog: the
 * capture agent sees the OFF state on its next poll and releases the camera.
 */
function av_prune_viewers(array &$state, ?array $cfg, int $now): void {
    $watchdog = (int)($cfg['watchdog_seconds'] ?? 20);
    foreach ($state['viewers'] as $id => $ts) {
        if ($now - (int)$ts > $watchdog) unset($state['viewers'][$id]);
    }
    if (!$state['viewers'] && ($state['video_on'] || $state['audio_on'])) {
        $state['video_on'] = false;
        $state['audio_on'] = false;
    }
}

/** Delete the oldest files beyond $keep (names are zero-padded, so sortable). */
function av_prune_dir(string $dir, string $prefix, int $keep): void {
    $files = glob($dir . '/' . $prefix . '*');
    if (!$files || count($files) <= $keep) return;
    sort($files);
    foreach (array_slice($files, 0, count($files) - $keep) as $f) @unlink($f);
}

function av_config(): ?array {
    return av_read_json(AV_CONFIG_FILE);
}

function av_configured(): bool {
    $cfg = av_config();
    return !empty($cfg['configured']);
}

function av_sanitize_id(string $id): string {
    return substr(preg_replace('/[^a-f0-9]/i', '', $id), 0, 16);
}

/* ------------------------------------------------------------------ guest access
 * Optional guest password: grants the live stream (viewer + its API actions)
 * ONLY. It never sets $_SESSION['gate_auth'], so a guest can never reach the
 * other NodePulse apps or the capture/config endpoints — infrastructure
 * security always wins over av_stream convenience.
 * Stored as a bcrypt hash, same approach as auth_gate. */

function av_guest_hash_file(): string {
    return AV_DATA . '/guest_password.hash';
}

function av_guest_enabled(): bool {
    return file_exists(av_guest_hash_file());
}

define('AV_GUEST_RL_FILE', AV_DATA . '/guest_ratelimit.json');
define('AV_GUEST_RL_MAX', 5);      // failed attempts before lockout
define('AV_GUEST_RL_WINDOW', 900); // lockout window (15 min)

function av_guest_rl_check(): array {
    $now  = time();
    $data = av_read_json(AV_GUEST_RL_FILE, ['attempts' => [], 'blocked_until' => 0]);
    if ((int)$data['blocked_until'] > $now) {
        return ['blocked' => true, 'remaining' => (int)$data['blocked_until'] - $now];
    }
    $data['attempts'] = array_values(array_filter($data['attempts'], function ($t) use ($now) {
        return (int)$t > $now - AV_GUEST_RL_WINDOW;
    }));
    if (count($data['attempts']) >= AV_GUEST_RL_MAX) {
        $data['blocked_until'] = $now + AV_GUEST_RL_WINDOW;
        av_write_json(AV_GUEST_RL_FILE, $data);
        return ['blocked' => true, 'remaining' => AV_GUEST_RL_WINDOW];
    }
    return ['blocked' => false];
}

function av_guest_rl_record(): void {
    $data = av_read_json(AV_GUEST_RL_FILE, ['attempts' => [], 'blocked_until' => 0]);
    $data['attempts'][] = time();
    av_write_json(AV_GUEST_RL_FILE, $data);
}

function av_guest_rl_clear(): void {
    av_write_json(AV_GUEST_RL_FILE, ['attempts' => [], 'blocked_until' => 0]);
}
