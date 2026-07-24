<?php
/**
 * AV STREAM — API (control plane + data plane).
 *
 * All actions require an authenticated auth_gate session (401 JSON otherwise —
 * the gate HTML is never emitted here so fetch() callers get a clean error).
 *
 * Capture-side actions (config_set, state, frame_up, audio_up) are additionally
 * restricted to direct loopback requests: nobody can inject frames or
 * reconfigure the service through the tunnel.
 *
 * Actions:
 *   status      GET   ?id=       viewer heartbeat + service status
 *   state       GET              capture agent poll (local only)
 *   control     POST  JSON       {video?,audio?,zoom?,id?} start/stop/zoom
 *   config_get  GET              current configuration
 *   config_set  POST  JSON       save configuration (local only)
 *   frame_up    POST  raw jpeg   store one frame (local only)
 *   frame       GET   ?since,id  latest frame if newer than 'since' (204 otherwise)
 *   audio_up    POST  raw webm   store one audio chunk (local only)
 *   audio       GET   ?after,id  next audio chunk after seq 'after' (204 otherwise)
 */

require_once __DIR__ . '/common.php';

av_session_attach();

function av_json(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function av_require_local(): void {
    if (!av_is_local()) av_json(403, ['error' => 'capture endpoints accept loopback requests only']);
}

$fullAuth  = !empty($_SESSION['gate_auth']);
$guestAuth = !empty($_SESSION['av_guest_auth']);

if (!$fullAuth && !$guestAuth) {
    av_json(401, ['error' => 'authentication required — open the app page to log in']);
}

$action = $_REQUEST['a'] ?? '';

// Guests get the live stream only: no config, no capture endpoints.
$guestAllowed = ['status', 'control', 'frame', 'audio'];
if (!$fullAuth && !in_array($action, $guestAllowed, true)) {
    av_json(403, ['error' => 'guest access is limited to the live stream']);
}
$cfg    = av_config();
$now    = time();

switch ($action) {

case 'status': {
    $id = av_sanitize_id($_GET['id'] ?? '');
    $state = av_state_update(function (&$s) use ($id, $cfg, $now) {
        if ($id !== '') $s['viewers'][$id] = $now;
        av_prune_viewers($s, $cfg, $now);
    });
    av_json(200, [
        'configured'     => !empty($cfg['configured']),
        'capture_online' => ($now - (int)$state['last_capture_ping']) <= AV_CAPTURE_ONLINE_S,
        'video_on'       => $state['video_on'],
        'audio_on'       => $state['audio_on'],
        'zoom'           => $state['zoom'],
        'viewers'        => count($state['viewers']),
        'seq_frame'      => $state['seq_frame'],
        'seq_audio'      => $state['seq_audio'],
    ]);
}

case 'state': {
    av_require_local();
    if (empty($cfg['configured'])) av_json(409, ['error' => 'service not configured']);
    $state = av_state_update(function (&$s) use ($cfg, $now) {
        $s['last_capture_ping'] = $now;
        av_prune_viewers($s, $cfg, $now);
    });
    av_json(200, [
        'state' => [
            'video_on' => $state['video_on'],
            'audio_on' => $state['audio_on'],
            'zoom'     => $state['zoom'],
            'viewers'  => count($state['viewers']),
        ],
        'config' => $cfg,
    ]);
}

case 'control': {
    if (empty($cfg['configured'])) av_json(409, ['error' => 'service not configured']);
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) av_json(400, ['error' => 'invalid JSON body']);
    $id = av_sanitize_id((string)($in['id'] ?? ''));
    $state = av_state_update(function (&$s) use ($in, $id, $cfg, $now) {
        if ($id !== '') $s['viewers'][$id] = $now;
        if (array_key_exists('video', $in)) $s['video_on'] = (bool)$in['video'];
        if (array_key_exists('audio', $in)) $s['audio_on'] = (bool)$in['audio'];
        if (isset($in['zoom'])) $s['zoom'] = max(1.0, min(5.0, round((float)$in['zoom'], 2)));
        av_prune_viewers($s, $cfg, $now);
    });
    av_json(200, [
        'ok'       => true,
        'video_on' => $state['video_on'],
        'audio_on' => $state['audio_on'],
        'zoom'     => $state['zoom'],
    ]);
}

case 'config_get': {
    av_json(200, ['config' => $cfg]);
}

case 'config_set': {
    av_require_local();
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) av_json(400, ['error' => 'invalid JSON body']);
    $old = $cfg ?: [];
    $new = [
        'configured'       => true,
        // video
        'fps'              => max(1, min(5, (int)($in['fps'] ?? 2))),
        'width'            => max(320, min(1280, (int)($in['width'] ?? 640))),
        'jpeg_quality'     => max(0.3, min(0.9, (float)($in['jpeg_quality'] ?? 0.6))),
        'facing'           => in_array($in['facing'] ?? '', ['user', 'environment'], true) ? $in['facing'] : 'environment',
        'rotation'         => in_array((int)($in['rotation'] ?? 0), [0, 90, 180, 270], true) ? (int)$in['rotation'] : 0,
        // audio
        'audio_bitrate'    => max(8000, min(64000, (int)($in['audio_bitrate'] ?? 16000))),
        'chunk_seconds'    => max(2, min(10, (int)($in['chunk_seconds'] ?? 4))),
        // retention + watchdog
        'keep_frames'      => max(20, min(5000, (int)($in['keep_frames'] ?? 300))),
        'keep_audio'       => max(10, min(500, (int)($in['keep_audio'] ?? 60))),
        'watchdog_seconds' => max(5, min(120, (int)($in['watchdog_seconds'] ?? 20))),
        // random token embedded in media filenames: direct static URLs are
        // unguessable even though the web server serves media/ without PHP
        'media_token'      => $old['media_token'] ?? bin2hex(random_bytes(4)),
        'configured_at'    => $old['configured_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
        'updated_at'       => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    foreach ([AV_DATA, AV_MEDIA, AV_FRAMES, AV_AUDIO] as $d) {
        if (!is_dir($d)) mkdir($d, 0700, true);
    }
    // block accidental directory listing where autoindex is enabled
    foreach ([AV_MEDIA, AV_FRAMES, AV_AUDIO] as $d) {
        $guard = $d . '/index.php';
        if (!file_exists($guard)) file_put_contents($guard, "<?php http_response_code(403);\n");
    }
    // Guest access management (stream-only credential — never touches auth_gate).
    // Validated before persisting anything so a bad password rejects the whole save.
    $guestRemove = !empty($in['guest_remove']);
    $guestPw     = trim((string)($in['guest_password'] ?? ''));
    if (!$guestRemove && $guestPw !== '' && strlen($guestPw) < 4) {
        av_json(400, ['error' => 'guest password too short (minimum 4 characters)']);
    }

    av_write_json(AV_CONFIG_FILE, $new);

    if ($guestRemove) {
        @unlink(av_guest_hash_file());
        av_guest_rl_clear();
    } elseif ($guestPw !== '') {
        file_put_contents(av_guest_hash_file(), password_hash($guestPw, PASSWORD_BCRYPT));
        @chmod(av_guest_hash_file(), 0600);
        av_guest_rl_clear();
    }

    av_json(200, ['ok' => true, 'config' => $new, 'guest_enabled' => av_guest_enabled()]);
}

case 'frame_up': {
    av_require_local();
    if (empty($cfg['configured'])) av_json(409, ['error' => 'service not configured']);
    $raw = file_get_contents('php://input');
    if ($raw === '' || strlen($raw) > 3 * 1024 * 1024) av_json(400, ['error' => 'bad frame payload']);
    $fname = '';
    $state = av_state_update(function (&$s) use ($cfg, &$fname) {
        $s['seq_frame']++;
        $fname = sprintf('f_%s_%08d_%s.jpg', $cfg['media_token'], $s['seq_frame'], gmdate('Ymd_His'));
        $s['frame_file'] = $fname;
    });
    file_put_contents(AV_FRAMES . '/' . $fname, $raw);
    if ($state['seq_frame'] % 20 === 0) av_prune_dir(AV_FRAMES, 'f_', (int)$cfg['keep_frames']);
    av_json(200, ['ok' => true, 'seq' => $state['seq_frame']]);
}

case 'frame': {
    $since = (int)($_GET['since'] ?? 0);
    $id    = av_sanitize_id($_GET['id'] ?? '');
    $state = av_state_update(function (&$s) use ($id, $cfg, $now) {
        if ($id !== '') $s['viewers'][$id] = $now;
        av_prune_viewers($s, $cfg, $now);
    });
    if ($state['frame_file'] === '' || $state['seq_frame'] <= $since) {
        http_response_code(204);
        exit;
    }
    $path = AV_FRAMES . '/' . basename($state['frame_file']);
    if (!is_file($path)) {
        http_response_code(204);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-store');
    header('X-Seq: ' . $state['seq_frame']);
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

case 'audio_up': {
    av_require_local();
    if (empty($cfg['configured'])) av_json(409, ['error' => 'service not configured']);
    $raw = file_get_contents('php://input');
    if ($raw === '' || strlen($raw) > 4 * 1024 * 1024) av_json(400, ['error' => 'bad audio payload']);
    $fname = '';
    $state = av_state_update(function (&$s) use ($cfg, &$fname) {
        $s['seq_audio']++;
        $fname = sprintf('a_%s_%08d_%s.webm', $cfg['media_token'], $s['seq_audio'], gmdate('Ymd_His'));
        $s['audio_files'][] = ['seq' => $s['seq_audio'], 'file' => $fname];
        $s['audio_files'] = array_slice($s['audio_files'], -40);
    });
    file_put_contents(AV_AUDIO . '/' . $fname, $raw);
    if ($state['seq_audio'] % 5 === 0) av_prune_dir(AV_AUDIO, 'a_', (int)$cfg['keep_audio']);
    av_json(200, ['ok' => true, 'seq' => $state['seq_audio']]);
}

case 'audio': {
    $after = (int)($_GET['after'] ?? 0);
    $id    = av_sanitize_id($_GET['id'] ?? '');
    $state = av_state_update(function (&$s) use ($id, $cfg, $now) {
        if ($id !== '') $s['viewers'][$id] = $now;
        av_prune_viewers($s, $cfg, $now);
    });
    $next = null;
    foreach ($state['audio_files'] as $entry) {
        if ((int)$entry['seq'] > $after) { $next = $entry; break; }
    }
    if ($next === null) {
        http_response_code(204);
        exit;
    }
    $path = AV_AUDIO . '/' . basename($next['file']);
    if (!is_file($path)) {
        http_response_code(204);
        exit;
    }
    header('Content-Type: audio/webm');
    header('Cache-Control: no-store');
    header('X-Seq: ' . (int)$next['seq']);
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

default:
    av_json(400, ['error' => 'unknown action']);
}
