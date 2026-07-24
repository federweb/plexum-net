<?php
/**
 * AV STREAM — main UI.
 *
 * Role is auto-detected (see av_is_local in common.php):
 *   - direct localhost request  → SERVER view (capture console / setup)
 *   - request through the tunnel → CLIENT view (remote viewer)
 * Override for testing with ?view=server|client.
 */

require_once __DIR__ . '/common.php';

av_session_attach();

$isLocal    = av_is_local();
$config     = av_config();
$configured = !empty($config['configured']);

$view = $_GET['view'] ?? '';
if ($view !== 'server' && $view !== 'client') $view = $isLocal ? 'server' : 'client';

/* ---- authentication --------------------------------------------------------
 * Full auth (auth_gate session) unlocks everything.
 * Guest auth (optional stream-only password) unlocks the VIEWER only:
 * it never sets gate_auth, so guests can never reach other apps, the capture
 * console or the configuration endpoints. */

$isFullAuth = !empty($_SESSION['gate_auth']);

// Guest logout
if (isset($_GET['guest_logout'])) {
    unset($_SESSION['av_guest_auth']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Guest login attempt
$guestError = '';
if (!$isFullAuth && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['av_action'] ?? '') === 'guest_login' && av_guest_enabled()) {
    $rl = av_guest_rl_check();
    if ($rl['blocked']) {
        $guestError = 'Too many failed attempts. Wait ' . (int)ceil($rl['remaining'] / 60) . ' minute(s).';
    } else {
        $hash = trim((string)@file_get_contents(av_guest_hash_file()));
        if ($hash !== '' && password_verify((string)($_POST['guest_password'] ?? ''), $hash)) {
            av_guest_rl_clear();
            $_SESSION['av_guest_auth'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        av_guest_rl_record();
        $guestError = 'Wrong password';
    }
}

$isGuest = !$isFullAuth && !empty($_SESSION['av_guest_auth']);

if (!$isFullAuth && !$isGuest) {
    if ($view === 'client' && $configured && av_guest_enabled()) {
        // Stream-only guest login page (never exposes the auth_gate form)
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AV Stream — Guest access</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, monospace;
           background: #0a0a0a; color: #e0e0e0; min-height: 100vh;
           display: flex; align-items: center; justify-content: center; }
    .box { background: #111; border: 1px solid #2a2a2a; border-radius: 8px;
           padding: 40px 36px 32px; width: 340px; text-align: center; }
    .box h1 { font-size: 20px; font-weight: 600; color: #00ff88; margin-bottom: 4px; }
    .box .sub { font-size: 12px; color: #666; margin-bottom: 24px; }
    .box input[type=password] { width: 100%; padding: 10px 14px; background: #1a1a1a;
           border: 1px solid #333; border-radius: 4px; color: #e0e0e0; font-size: 14px;
           margin-bottom: 12px; outline: none; }
    .box input[type=password]:focus { border-color: #00ff88; }
    .box button { width: 100%; padding: 10px; background: #00ff88; color: #000; border: none;
           border-radius: 4px; font-size: 14px; font-weight: 600; cursor: pointer; }
    .box button:hover { background: #00cc6a; }
    .err { background: #2a0a0a; color: #ff4444; padding: 8px 12px; border-radius: 4px;
           font-size: 12px; margin-bottom: 14px; text-align: left; }
    .note { font-size: 11px; color: #555; margin-top: 18px; line-height: 1.6; }
</style>
</head>
<body>
<div class="box">
    <h1>AV STREAM</h1>
    <div class="sub">Guest access — live stream only</div>
    <?php if ($guestError): ?><div class="err"><?= htmlspecialchars($guestError) ?></div><?php endif; ?>
    <form method="POST">
        <input type="hidden" name="av_action" value="guest_login">
        <input type="password" name="guest_password" placeholder="Guest password" required autofocus>
        <button type="submit">Watch stream</button>
    </form>
    <div class="note">This password grants the audio/video stream only.<br>It gives no access to the rest of the node.</div>
</div>
</body>
</html>
        <?php
        exit;
    }
    // Everything else (capture console, setup, no guest password set) needs full auth
    require __DIR__ . '/../auth_gate.php';
    $isFullAuth = true;
}

// A guest must never land on the server/setup views
if ($isGuest && ($view !== 'client' || !$configured)) {
    require __DIR__ . '/../auth_gate.php';
    $isFullAuth = true;
    $isGuest = false;
}

$mediaLink = '/filemanager/index.php?p=' . rawurlencode('av_stream/media');

// defaults used to prefill the settings form when not yet configured
$cv = function (string $key, $default) use ($config) {
    return $config[$key] ?? $default;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AV Stream — NodePulse</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        background: #0a0a0a;
        color: #e0e0e0;
        min-height: 100vh;
        padding: 20px 14px 40px;
        max-width: 860px;
        margin: 0 auto;
    }
    a { color: #00c9a7; }
    header { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 22px; }
    header h1 { font-size: 1.35rem; letter-spacing: .08em; font-weight: 800; color: #00ff88; }
    .badge {
        font-size: .62rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
        padding: 4px 10px; border-radius: 20px; border: 1px solid #2a2a2a; color: #888;
    }
    .badge.role { border-color: #00c9a7; color: #00c9a7; }
    header .home { margin-left: auto; font-size: .75rem; text-decoration: none; color: #64748b; }
    header .home:hover { color: #00ff88; }

    .panel {
        background: #111; border: 1px solid #2a2a2a; border-radius: 10px;
        padding: 20px; margin-bottom: 18px;
    }
    .panel h2 { font-size: .95rem; color: #00ff88; margin-bottom: 12px; letter-spacing: .04em; }
    .panel h3 { font-size: .82rem; color: #00c9a7; margin: 14px 0 6px; }
    .panel p, .panel li { font-size: .82rem; line-height: 1.65; color: #b8bcc4; }
    .panel ul, .panel ol { padding-left: 20px; margin: 6px 0; }
    .panel code {
        background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 4px;
        padding: 1px 6px; font-size: .78rem; color: #00ff88; font-family: Consolas, monospace;
        word-break: break-all;
    }
    details.panel summary { cursor: pointer; font-size: .9rem; color: #00ff88; font-weight: 600; user-select: none; }
    details.panel[open] summary { margin-bottom: 12px; }

    .notice { border-left: 3px solid #f59e0b; }
    .notice h2 { color: #f59e0b; }

    /* chips */
    .chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
    .chip {
        font-size: .68rem; font-weight: 600; letter-spacing: .04em;
        padding: 5px 12px; border-radius: 16px; border: 1px solid #2a2a2a; color: #666;
        background: #141414;
    }
    .chip.on  { border-color: #00ff88; color: #00ff88; }
    .chip.warn { border-color: #f59e0b; color: #f59e0b; }
    .chip.err { border-color: #ff4444; color: #ff4444; }

    /* buttons */
    button {
        background: #00ff88; color: #000; border: none; border-radius: 6px;
        padding: 10px 18px; font-size: .85rem; font-weight: 700; cursor: pointer;
    }
    button:hover { background: #00cc6a; }
    button.secondary { background: #1a1a1a; color: #e0e0e0; border: 1px solid #333; }
    button.secondary:hover { border-color: #00ff88; color: #00ff88; }
    button.danger { background: #2a0a0a; color: #ff6666; border: 1px solid #5a1a1a; }
    button.danger:hover { border-color: #ff4444; }
    button:disabled { background: #222; color: #555; cursor: not-allowed; border: 1px solid #2a2a2a; }
    .btnrow { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }

    /* viewer control row: 4 buttons on one line, 2×2 on mobile */
    .btnrow4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 10px 0; }
    @media (max-width: 560px) { .btnrow4 { grid-template-columns: repeat(2, 1fr); } }
    .btnrow4 button { padding: 12px 6px; }
    button.toggle { background: #1a1a1a; color: #e0e0e0; border: 1px solid #333; }
    button.toggle:hover { border-color: #00ff88; color: #00ff88; }
    button.toggle.active,
    button.toggle.active:disabled {
        background: #00ff88; color: #000; border-color: #00ff88;
        box-shadow: 0 0 14px rgba(0,255,136,.35);
    }
    button.toggle.danger-style { border-color: #5a1a1a; color: #ff6666; background: #1a0d0d; }
    button.toggle.danger-style:hover { border-color: #ff4444; }
    button.toggle.danger-style.active { background: #ff4444; color: #000; border-color: #ff4444;
        box-shadow: 0 0 14px rgba(255,68,68,.35); }

    /* forms */
    .grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; }
    label.field { display: block; font-size: .7rem; color: #888; letter-spacing: .05em; text-transform: uppercase; }
    label.field select {
        display: block; width: 100%; margin-top: 5px; padding: 8px 10px;
        background: #1a1a1a; border: 1px solid #333; border-radius: 5px; color: #e0e0e0;
        font-size: .85rem; outline: none;
    }
    label.field select:focus { border-color: #00ff88; }

    /* capture preview + viewer image */
    .stage {
        background: #000; border: 1px solid #2a2a2a; border-radius: 8px;
        min-height: 180px; display: flex; align-items: center; justify-content: center;
        overflow: hidden; margin-bottom: 12px; position: relative;
    }
    .stage canvas, .stage img { max-width: 100%; height: auto; display: block; }
    .stage .placeholder { color: #444; font-size: .85rem; letter-spacing: .1em; padding: 60px 0; }

    /* fullscreen button (bottom-right of the video) */
    .fsbtn {
        position: absolute; right: 10px; bottom: 10px; z-index: 5;
        width: 40px; height: 40px; padding: 0;
        background: rgba(0,0,0,.55); color: #00ff88;
        border: 1px solid #2a2a2a; border-radius: 8px;
        font-size: 18px; line-height: 1;
    }
    .fsbtn:hover { background: rgba(0,0,0,.85); }

    /* native fullscreen — object-fit:contain adapts to portrait AND landscape */
    .stage:fullscreen { width: 100vw; height: 100vh; border: none; border-radius: 0; }
    .stage:fullscreen img { width: 100%; height: 100%; max-width: none; object-fit: contain; }

    /* CSS fallback for browsers without element fullscreen (e.g. iOS Safari) */
    .stage.fsfake {
        position: fixed; inset: 0; z-index: 1000; margin: 0;
        border: none; border-radius: 0; background: #000; min-height: 100vh;
    }
    .stage.fsfake img { width: 100vw; height: 100vh; max-width: none; object-fit: contain; }

    /* zoom */
    .zoomrow { display: flex; align-items: center; gap: 12px; margin: 10px 0; }
    .zoomrow input[type=range] { flex: 1; accent-color: #00ff88; }
    .zoomrow .zval { font-family: Consolas, monospace; font-size: .85rem; color: #00ff88; width: 46px; }

    /* log */
    #log {
        background: #0d0d0d; border: 1px solid #222; border-radius: 6px;
        font-family: Consolas, monospace; font-size: .72rem; color: #7a8; padding: 10px;
        height: 150px; overflow-y: auto; white-space: pre-wrap; margin-top: 12px;
    }
    .mediarow { font-size: .78rem; color: #888; margin-top: 10px; }

    /* screen power-save veil (capture console): pure #000 keeps OLED pixels
       physically off so an always-on device barely draws current, while the
       capture agent keeps running underneath. First tap wakes it. */
    #dimVeil {
        position: fixed; inset: 0; z-index: 99999; background: #000;
        display: none; align-items: center; justify-content: center;
        cursor: pointer; -webkit-tap-highlight-color: transparent;
    }
    #dimVeil.on { display: flex; }
    #dimVeil .hint {
        color: #000; font-size: .72rem; letter-spacing: .18em;
        user-select: none; transition: color 1.6s ease;
    }
</style>
</head>
<body>

<header>
    <h1>AV STREAM</h1>
    <span class="badge role"><?= $view === 'server' ? 'CAPTURE DEVICE (SERVER)' : 'REMOTE VIEWER (CLIENT)' ?></span>
    <span class="badge"><?= $configured ? 'CONFIGURED' : 'NOT CONFIGURED' ?></span>
    <?php if ($isGuest): ?>
    <span class="badge" style="border-color:#f59e0b;color:#f59e0b">GUEST</span>
    <a class="home" href="?guest_logout=1">Logout</a>
    <?php else: ?>
    <a class="home" href="/">&larr; Dashboard</a>
    <?php endif; ?>
</header>

<?php /* ============================ ABOUT / INSTRUCTIONS ============================ */ ?>
<?php if (!$configured): ?>
<div class="panel">
    <h2>What is AV Stream?</h2>
    <p>
        AV Stream turns the device running your NodePulse node into a <strong>low-bandwidth
        audio/video surveillance source</strong> that you control remotely through your
        Cloudflare tunnel. It streams video at 1&ndash;3 frames per second and low-bitrate
        audio, so it stays light on the tunnel and on the device battery. Captured frames
        and audio chunks are saved in
        <a href="<?= htmlspecialchars($mediaLink) ?>">av_stream/media</a>
        (browsable with the File Manager) with automatic retention pruning.
    </p>

    <h3>How the two roles work</h3>
    <ul>
        <li><strong>Server (capture)</strong> — this page opened in a browser <em>on the node
            device itself</em> via <code>http://127.0.0.1:8080/av_stream/</code>. The browser is
            the hardware bridge: it grabs camera and microphone with <code>getUserMedia</code> and
            pushes JPEG frames + audio chunks to the local PHP API. It sits in standby (camera off)
            until a viewer requests a stream.</li>
        <li><strong>Client (viewer)</strong> — the same URL reached <em>through the tunnel</em>.
            From here you start/stop video and audio independently, control zoom, and watch the
            stream. The role is auto-detected: tunnel requests carry Cloudflare headers, direct
            localhost requests do not.</li>
        <li><strong>Auto-stop watchdog</strong> — every viewer poll is a heartbeat. If no viewer
            polls for the configured timeout, the server flips both streams off and the capture
            page releases the camera (LED off) and returns to standby. Closing the viewer tab is
            enough to stop everything.</li>
    </ul>

    <h3>Setup on Termux (Android)</h3>
    <ol>
        <li>On the phone, open Chrome at <code>http://127.0.0.1:8080/av_stream/</code> and log in.</li>
        <li>Fill in the settings below, use <em>Test permissions</em> to grant camera + microphone, then save.</li>
        <li>Press <em>Start capture agent</em> and keep the tab <strong>visible with the screen on</strong>:
            Android suspends camera access for backgrounded apps, so the page holds a screen wake
            lock for you. On a dedicated device just leave the page open at minimum brightness.</li>
    </ol>

    <h3>Setup on WSL2 (Windows)</h3>
    <ol>
        <li>The node runs inside the Ubuntu distro, which has <strong>no camera access</strong> —
            and it does not need any: the Windows browser does the capture.</li>
        <li>Open Chrome/Edge <em>on Windows</em> at <code>http://localhost:8080/av_stream/</code>
            (WSL2 localhost forwarding reaches the node) and configure + start the agent there.
            The browser bridges the PC webcam/microphone.</li>
        <li>Desktop browsers keep capturing in a background tab, but timers get throttled: the
            agent uses a Web Worker clock to mitigate this. For a steady frame rate keep the tab visible.</li>
    </ol>

    <h3>Setup on macOS</h3>
    <ol>
        <li>Open Safari or Chrome at <code>http://localhost:8080/av_stream/</code>.</li>
        <li>Grant camera/microphone when prompted (System Settings &rarr; Privacy &amp; Security
            if previously denied), configure and start the agent.</li>
    </ol>

    <h3>Watching from anywhere</h3>
    <p>
        Open your node's tunnel URL followed by <code>/av_stream/</code> from any browser, log in,
        and use the Start/Stop controls. Video-only, audio-only or both. Expect ~1&ndash;2 s of
        latency (short HTTP polling — no WebSocket, no web-server configuration required).
    </p>
</div>
<?php endif; ?>

<?php /* ============================ NOT CONFIGURED ============================ */ ?>
<?php if (!$configured && $view === 'client'): ?>
<div class="panel notice">
    <h2>Service not configured yet</h2>
    <p>
        Configuration can only be done <strong>locally on the node device</strong> (the capture
        endpoints reject tunnel requests by design). Go to the device running NodePulse, open
        <code>http://127.0.0.1:8080/av_stream/</code> in its browser and complete the setup form.
        Then come back here to start streaming.
    </p>
</div>

<?php elseif ($view === 'server' && !$isLocal): ?>
<div class="panel notice">
    <h2>Capture console requires a local connection</h2>
    <p>
        You are connected through the tunnel, so the capture endpoints are locked. Open
        <code>http://127.0.0.1:8080/av_stream/</code> directly on the node device to use the
        capture console, or switch to the <a href="?view=client">viewer</a>.
    </p>
</div>

<?php /* ============================ SERVER: SETUP + CONSOLE ============================ */ ?>
<?php elseif ($view === 'server'): ?>

    <?php if ($configured): ?>
    <div class="panel">
        <h2>Capture console</h2>
        <div class="chips">
            <span class="chip" id="chipAgent">AGENT OFF</span>
            <span class="chip" id="chipVideo">VIDEO —</span>
            <span class="chip" id="chipAudio">AUDIO —</span>
            <span class="chip" id="chipViewers">VIEWERS 0</span>
            <span class="chip" id="chipWake">WAKE LOCK —</span>
        </div>
        <div class="stage">
            <canvas id="cvs" style="display:none"></canvas>
            <div class="placeholder" id="cvsPh">STANDBY — CAMERA OFF</div>
        </div>
        <div class="btnrow">
            <button id="btnAgent">Start capture agent</button>
            <button class="secondary" id="btnDim">Screen off (power save)</button>
        </div>
        <p style="font-size:.75rem;color:#888;line-height:1.6">
            Keep this tab <strong>visible with the screen on</strong> while the agent runs
            (mandatory on Android; recommended on desktop for a steady frame rate). The camera
            stays off until a remote viewer starts a stream; it is released automatically when
            the last viewer disconnects.
        </p>
        <div class="mediarow">Captured media: <a href="<?= htmlspecialchars($mediaLink) ?>">av_stream/media</a></div>
        <div id="log"></div>
    </div>
    <?php endif; ?>

    <?php if ($configured): ?><details class="panel"><summary>Settings</summary><?php else: ?><div class="panel"><h2>Setup</h2><?php endif; ?>
        <form id="cfgForm" onsubmit="return false">
            <h3 style="margin-top:0">Video</h3>
            <div class="grid2">
                <label class="field">Frame rate
                    <select name="fps">
                        <?php foreach ([1, 2, 3, 5] as $v): ?>
                        <option value="<?= $v ?>" <?= (int)$cv('fps', 2) === $v ? 'selected' : '' ?>><?= $v ?> fps</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">Resolution (width)
                    <select name="width">
                        <?php foreach ([480, 640, 800, 960, 1280] as $v): ?>
                        <option value="<?= $v ?>" <?= (int)$cv('width', 640) === $v ? 'selected' : '' ?>><?= $v ?> px</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">JPEG quality
                    <?php $q = (float)$cv('jpeg_quality', 0.6); ?>
                    <select name="jpeg_quality">
                        <option value="0.4"  <?= $q < 0.5 ? 'selected' : '' ?>>Low (0.4)</option>
                        <option value="0.6"  <?= $q >= 0.5 && $q < 0.7 ? 'selected' : '' ?>>Medium (0.6)</option>
                        <option value="0.75" <?= $q >= 0.7 ? 'selected' : '' ?>>High (0.75)</option>
                    </select>
                </label>
                <label class="field">Camera
                    <select name="facing">
                        <option value="environment" <?= $cv('facing', 'environment') === 'environment' ? 'selected' : '' ?>>Rear / default</option>
                        <option value="user" <?= $cv('facing', 'environment') === 'user' ? 'selected' : '' ?>>Front / selfie</option>
                    </select>
                </label>
                <label class="field">Orientation (rotate output)
                    <select name="rotation">
                        <?php foreach ([0, 90, 180, 270] as $v): ?>
                        <option value="<?= $v ?>" <?= (int)$cv('rotation', 0) === $v ? 'selected' : '' ?>><?= $v ?>&deg;</option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <h3>Audio</h3>
            <div class="grid2">
                <label class="field">Audio quality
                    <?php $ab = (int)$cv('audio_bitrate', 16000); ?>
                    <select name="audio_bitrate">
                        <option value="12000" <?= $ab <= 12000 ? 'selected' : '' ?>>Very low (12 kbps)</option>
                        <option value="16000" <?= $ab === 16000 ? 'selected' : '' ?>>Low (16 kbps)</option>
                        <option value="24000" <?= $ab === 24000 ? 'selected' : '' ?>>Medium (24 kbps)</option>
                        <option value="48000" <?= $ab >= 48000 ? 'selected' : '' ?>>High (48 kbps)</option>
                    </select>
                </label>
                <label class="field">Audio chunk length
                    <select name="chunk_seconds">
                        <?php foreach ([2, 4, 6, 8] as $v): ?>
                        <option value="<?= $v ?>" <?= (int)$cv('chunk_seconds', 4) === $v ? 'selected' : '' ?>><?= $v ?> s</option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <h3>Guest access</h3>
            <p style="font-size:.75rem;color:#888;line-height:1.6;margin-bottom:8px">
                Optional stream-only password. A guest logging in with it sees the remote viewer
                and nothing else: no dashboard, no apps, no settings, no media archive — the main
                auth_gate credentials are never involved. Status:
                <strong style="color:<?= av_guest_enabled() ? '#00ff88' : '#666' ?>">
                    <?= av_guest_enabled() ? 'ENABLED' : 'DISABLED' ?></strong>
            </p>
            <div class="grid2">
                <label class="field"><?= av_guest_enabled() ? 'Change guest password' : 'Set guest password' ?>
                    <input type="password" name="guest_password" placeholder="Leave blank to keep unchanged"
                           style="display:block;width:100%;margin-top:5px;padding:8px 10px;background:#1a1a1a;border:1px solid #333;border-radius:5px;color:#e0e0e0;font-size:.85rem;outline:none">
                </label>
                <?php if (av_guest_enabled()): ?>
                <label class="field" style="display:flex;align-items:center;gap:8px;text-transform:none;margin-top:22px">
                    <input type="checkbox" name="guest_remove" value="1" style="accent-color:#ff4444">
                    <span style="color:#ff6666;font-size:.78rem">Remove guest access</span>
                </label>
                <?php endif; ?>
            </div>
            <h3>Retention &amp; watchdog</h3>
            <div class="grid2">
                <label class="field">Keep last frames
                    <select name="keep_frames">
                        <?php foreach ([100, 300, 1000, 3000] as $v): ?>
                        <option value="<?= $v ?>" <?= (int)$cv('keep_frames', 300) === $v ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">Keep last audio chunks
                    <select name="keep_audio">
                        <?php foreach ([30, 60, 120, 300] as $v): ?>
                        <option value="<?= $v ?>" <?= (int)$cv('keep_audio', 60) === $v ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">Auto-stop after (no viewer)
                    <select name="watchdog_seconds">
                        <?php foreach ([10, 20, 30, 60] as $v): ?>
                        <option value="<?= $v ?>" <?= (int)$cv('watchdog_seconds', 20) === $v ? 'selected' : '' ?>><?= $v ?> s</option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="btnrow" style="margin-top:16px">
                <button class="secondary" id="btnPerms" type="button">Test permissions (camera + mic)</button>
                <button id="btnSave" type="button"><?= $configured ? 'Save settings' : 'Save &amp; activate service' ?></button>
            </div>
            <p id="cfgMsg" style="font-size:.78rem;color:#888"></p>
        </form>
    <?php if ($configured): ?></details><?php else: ?></div><?php endif; ?>

    <?php if ($configured): ?>
    <div id="dimVeil"><span class="hint">TAP TO WAKE</span></div>
    <?php endif; ?>

<?php /* ============================ CLIENT: VIEWER ============================ */ ?>
<?php elseif ($configured && $view === 'client'): ?>

<div class="panel">
    <h2>Remote viewer</h2>
    <div class="chips">
        <span class="chip" id="chipCap">CAPTURE DEVICE —</span>
        <span class="chip" id="chipVideo">VIDEO —</span>
        <span class="chip" id="chipAudio">AUDIO —</span>
        <span class="chip" id="chipViewers">VIEWERS 0</span>
    </div>
    <div class="stage" id="stage">
        <img id="frame" style="display:none" alt="live frame">
        <div class="placeholder" id="framePh">NO SIGNAL</div>
        <button class="fsbtn" id="btnFs" title="Fullscreen">&#x26F6;</button>
    </div>
    <div class="btnrow4">
        <button class="toggle" id="btnStartAll">Start all</button>
        <button class="toggle danger-style" id="btnStopAll">Stop all</button>
        <button class="toggle" id="btnAudio">Start audio</button>
        <button class="toggle" id="btnVideo">Start video</button>
    </div>
    <div class="zoomrow">
        <span style="font-size:.7rem;color:#888;letter-spacing:.05em">ZOOM</span>
        <input type="range" id="zoom" min="1" max="5" step="0.1" value="1" disabled>
        <span class="zval" id="zval">1.0&times;</span>
    </div>
    <p style="font-size:.75rem;color:#888;line-height:1.6">
        Video and audio can be started independently. Streams stop automatically a few seconds
        after the last viewer closes this page (server-side watchdog). Expected latency: ~1&ndash;2 s.
    </p>
    <?php if (!$isGuest): ?>
    <div class="mediarow">Captured media: <a href="<?= htmlspecialchars($mediaLink) ?>">av_stream/media</a></div>
    <?php endif; ?>
    <audio id="player" style="display:none"></audio>
</div>

<details class="panel"><summary>About this service</summary>
    <p>
        AV Stream is a low-bandwidth surveillance stream served by the node itself. A browser page
        open on the node device captures camera/microphone (server role); this page (client role)
        controls it through the tunnel with short HTTP polling. If the capture device chip shows
        OFFLINE, the capture page on the device is not running: open
        <code>http://127.0.0.1:8080/av_stream/</code> on the node device and press
        <em>Start capture agent</em>.
    </p>
</details>

<?php endif; ?>

<script>
/* ---------------------------------------------------------------- shared */
const CFG_URL = 'api.php';
const IS_SERVER = <?= json_encode($view === 'server' && $isLocal && $configured) ?>;
const IS_CLIENT = <?= json_encode($view === 'client' && $configured) ?>;
// Config is embedded only for the capture console: the viewer never needs it
// and it must not leak media_token to guest sessions.
let CFG = <?= json_encode($view === 'server' && $isLocal ? ($config ?: new stdClass()) : new stdClass()) ?>;

// Web Worker based clock: unlike page timers it is not throttled when the tab
// goes to background on desktop browsers.
function makeTicker(ms, fn) {
    const src = 'let t;onmessage=e=>{clearInterval(t);if(e.data>0)t=setInterval(()=>postMessage(1),e.data)};';
    const w = new Worker(URL.createObjectURL(new Blob([src], { type: 'text/javascript' })));
    w.onmessage = fn;
    w.postMessage(ms);
    return { stop() { try { w.postMessage(0); w.terminate(); } catch (e) {} } };
}

function setChip(el, text, cls) {
    el.textContent = text;
    el.className = 'chip' + (cls ? ' ' + cls : '');
}

/* ---------------------------------------------------------------- settings form (server view) */
const cfgForm = document.getElementById('cfgForm');
if (cfgForm) {
    const msg = document.getElementById('cfgMsg');

    document.getElementById('btnPerms').addEventListener('click', async () => {
        msg.textContent = 'Requesting camera + microphone…';
        try {
            const s = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
            s.getTracks().forEach(t => t.stop());
            msg.textContent = '✔ Permissions granted. The capture agent will start streams without further prompts.';
        } catch (e) {
            msg.textContent = '✘ Permission error: ' + e.name + ' — grant camera/microphone access to this site and retry.';
        }
    });

    document.getElementById('btnSave').addEventListener('click', async () => {
        const f = new FormData(cfgForm);
        const payload = {};
        for (const [k, v] of f.entries()) payload[k] = v;
        msg.textContent = 'Saving…';
        try {
            const r = await fetch(CFG_URL + '?a=config_set', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const d = await r.json();
            if (d.ok) { msg.textContent = '✔ Saved. Reloading…'; location.reload(); }
            else msg.textContent = '✘ ' + (d.error || 'save failed');
        } catch (e) {
            msg.textContent = '✘ Network error while saving';
        }
    });
}

/* ---------------------------------------------------------------- capture agent (server view) */
if (IS_SERVER) {
    const cvs = document.getElementById('cvs'), cvsPh = document.getElementById('cvsPh');
    const logEl = document.getElementById('log');
    const chips = {
        agent: document.getElementById('chipAgent'),
        video: document.getElementById('chipVideo'),
        audio: document.getElementById('chipAudio'),
        viewers: document.getElementById('chipViewers'),
        wake: document.getElementById('chipWake')
    };
    const btnAgent = document.getElementById('btnAgent');

    let running = false, statePoll = null, wakeLock = null, failCount = 0;
    let zoomTarget = 1, zoomApplied = 0, digitalZoom = 1, fBusy = false;
    const vid = { stream: null, track: null, el: null, ticker: null, zoomCaps: null };
    const aud = { stream: null, rec: null, active: false };

    function log(m) {
        const line = new Date().toISOString().substr(11, 8) + '  ' + m;
        logEl.textContent += line + '\n';
        if (logEl.textContent.length > 20000) logEl.textContent = logEl.textContent.slice(-15000);
        logEl.scrollTop = logEl.scrollHeight;
    }

    async function acquireWakeLock() {
        if (!('wakeLock' in navigator)) { setChip(chips.wake, 'WAKE LOCK N/A', 'warn'); return; }
        try {
            wakeLock = await navigator.wakeLock.request('screen');
            setChip(chips.wake, 'WAKE LOCK ON', 'on');
            wakeLock.addEventListener('release', () => setChip(chips.wake, 'WAKE LOCK OFF', 'warn'));
        } catch (e) {
            setChip(chips.wake, 'WAKE LOCK OFF', 'warn');
        }
    }
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && running) acquireWakeLock();
        if (document.visibilityState === 'hidden' && running && vid.stream) {
            log('Tab hidden — on mobile the OS may suspend the camera. Keep the page visible.');
        }
    });

    function drawFrame() {
        const v = vid.el;
        if (!v || !v.videoWidth) return false;
        const vw = v.videoWidth, vh = v.videoHeight;
        const z = Math.max(1, digitalZoom);
        const sw = vw / z, sh = vh / z, sx = (vw - sw) / 2, sy = (vh - sh) / 2;
        const rot = (parseInt(CFG.rotation, 10) || 0) % 360;
        const outW = parseInt(CFG.width, 10) || 640;
        const outH = Math.round(outW * vh / vw);
        const swap = rot === 90 || rot === 270;
        cvs.width = swap ? outH : outW;
        cvs.height = swap ? outW : outH;
        const ctx = cvs.getContext('2d');
        ctx.save();
        ctx.translate(cvs.width / 2, cvs.height / 2);
        ctx.rotate(rot * Math.PI / 180);
        ctx.drawImage(v, sx, sy, sw, sh, -outW / 2, -outH / 2, outW, outH);
        ctx.restore();
        return true;
    }

    function sendFrame() {
        if (fBusy || !drawFrame()) return;
        fBusy = true;
        cvs.toBlob(b => {
            if (!b) { fBusy = false; return; }
            fetch(CFG_URL + '?a=frame_up', { method: 'POST', body: b })
                .catch(() => {})
                .finally(() => { fBusy = false; });
        }, 'image/jpeg', parseFloat(CFG.jpeg_quality) || 0.6);
    }

    function applyZoom() {
        if (!vid.track) return;
        if (vid.zoomCaps) {
            // native (optical/sensor) zoom — mostly available on Android Chrome
            const z = Math.min(vid.zoomCaps.max, Math.max(vid.zoomCaps.min, zoomTarget));
            if (Math.abs(z - zoomApplied) > 0.05) {
                vid.track.applyConstraints({ advanced: [{ zoom: z }] }).catch(() => {});
                zoomApplied = z;
            }
            digitalZoom = 1;
        } else {
            // digital fallback: center-crop on the canvas — works everywhere
            digitalZoom = zoomTarget;
        }
    }

    async function ensureVideo(on) {
        if (on && !vid.stream) {
            try {
                vid.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: CFG.facing || 'environment', width: { ideal: parseInt(CFG.width, 10) || 640 } }
                });
            } catch (e) { log('Video error: ' + e.name); return; }
            vid.track = vid.stream.getVideoTracks()[0];
            const caps = vid.track.getCapabilities ? vid.track.getCapabilities() : {};
            vid.zoomCaps = ('zoom' in caps && caps.zoom && caps.zoom.max) ? caps.zoom : null;
            zoomApplied = 0;
            vid.el = document.createElement('video');
            vid.el.srcObject = vid.stream;
            vid.el.muted = true;
            vid.el.playsInline = true;
            await vid.el.play().catch(() => {});
            const fps = parseInt(CFG.fps, 10) || 2;
            vid.ticker = makeTicker(Math.round(1000 / fps), sendFrame);
            cvs.style.display = 'block';
            cvsPh.style.display = 'none';
            log('Video capture ON (' + fps + ' fps, zoom: ' + (vid.zoomCaps ? 'native' : 'digital') + ')');
        } else if (!on && vid.stream) {
            if (vid.ticker) vid.ticker.stop();
            vid.stream.getTracks().forEach(t => t.stop());
            vid.stream = vid.track = vid.el = vid.ticker = null;
            cvs.style.display = 'none';
            cvsPh.style.display = 'block';
            log('Video capture OFF — camera released (standby)');
        }
    }

    function recLoop() {
        if (!aud.active || !aud.stream) return;
        // A fresh MediaRecorder per chunk: every uploaded file is a complete,
        // independently playable webm (chunks from a single long recording
        // lack the container header after the first one).
        const mime = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : '';
        let rec;
        try {
            rec = new MediaRecorder(aud.stream, {
                mimeType: mime || undefined,
                audioBitsPerSecond: parseInt(CFG.audio_bitrate, 10) || 16000
            });
        } catch (e) { log('Audio recorder error: ' + e.name); return; }
        aud.rec = rec;
        const parts = [];
        rec.ondataavailable = e => { if (e.data && e.data.size) parts.push(e.data); };
        rec.onstop = () => {
            const b = new Blob(parts, { type: mime || 'audio/webm' });
            if (b.size) fetch(CFG_URL + '?a=audio_up', { method: 'POST', body: b }).catch(() => {});
            recLoop();
        };
        rec.start();
        setTimeout(() => { if (rec.state !== 'inactive') rec.stop(); }, (parseInt(CFG.chunk_seconds, 10) || 4) * 1000);
    }

    async function ensureAudio(on) {
        if (on && !aud.active) {
            try {
                aud.stream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1 } });
            } catch (e) { log('Audio error: ' + e.name); return; }
            aud.active = true;
            recLoop();
            log('Audio capture ON (' + Math.round((parseInt(CFG.audio_bitrate, 10) || 16000) / 1000) + ' kbps)');
        } else if (!on && aud.active) {
            aud.active = false;
            if (aud.rec && aud.rec.state !== 'inactive') { aud.rec.onstop = null; aud.rec.stop(); }
            if (aud.stream) aud.stream.getTracks().forEach(t => t.stop());
            aud.stream = aud.rec = null;
            log('Audio capture OFF — microphone released');
        }
    }

    async function pollState() {
        let d;
        try {
            const r = await fetch(CFG_URL + '?a=state', { cache: 'no-store' });
            if (r.status === 401) { log('Session expired — reload the page and log in again.'); stopAgent(); return; }
            d = await r.json();
            failCount = 0;
        } catch (e) {
            if (++failCount === 4) {
                log('API unreachable — releasing camera/microphone until it comes back.');
                await ensureVideo(false);
                await ensureAudio(false);
            }
            return;
        }
        if (d.config) CFG = d.config;
        zoomTarget = parseFloat(d.state.zoom) || 1;
        await ensureVideo(!!d.state.video_on);
        await ensureAudio(!!d.state.audio_on);
        applyZoom();
        setChip(chips.video, d.state.video_on ? 'VIDEO ON' : 'VIDEO STANDBY', d.state.video_on ? 'on' : '');
        setChip(chips.audio, d.state.audio_on ? 'AUDIO ON' : 'AUDIO STANDBY', d.state.audio_on ? 'on' : '');
        setChip(chips.viewers, 'VIEWERS ' + d.state.viewers, d.state.viewers > 0 ? 'on' : '');
    }

    async function startAgent() {
        running = true;
        btnAgent.textContent = 'Stop capture agent';
        setChip(chips.agent, 'AGENT STANDBY', 'on');
        log('Capture agent started — waiting for a viewer to request a stream.');
        await acquireWakeLock();
        // Pre-flight permission request inside the click gesture, so later
        // remote starts never hit a permission prompt.
        try {
            const s = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
            s.getTracks().forEach(t => t.stop());
            log('Camera + microphone permissions OK.');
        } catch (e) {
            log('Permission problem: ' + e.name + ' — streams may fail. Use "Test permissions" in Settings.');
        }
        statePoll = makeTicker(1500, pollState);
    }

    async function stopAgent() {
        running = false;
        if (statePoll) { statePoll.stop(); statePoll = null; }
        await ensureVideo(false);
        await ensureAudio(false);
        if (wakeLock) { try { await wakeLock.release(); } catch (e) {} wakeLock = null; }
        btnAgent.textContent = 'Start capture agent';
        setChip(chips.agent, 'AGENT OFF', '');
        setChip(chips.video, 'VIDEO —', '');
        setChip(chips.audio, 'AUDIO —', '');
        setChip(chips.wake, 'WAKE LOCK —', '');
        log('Capture agent stopped.');
    }

    btnAgent.addEventListener('click', () => running ? stopAgent() : startAgent());
    window.addEventListener('beforeunload', () => { if (running) stopAgent(); });

    /* ---- screen power-save veil -------------------------------------------
     * Drops a true-black overlay over the console after some idle time (and on
     * demand via the button). The page, the wake lock and the capture agent all
     * keep running underneath — only the display goes dark, so an always-on
     * OLED barely draws power and the device visually "looks" backgrounded.
     * The first tap/key just wakes the screen (it never reaches the buttons). */
    (function () {
        const veil = document.getElementById('dimVeil');
        if (!veil) return;
        const hint = veil.querySelector('.hint');
        const IDLE_MS = 45000;
        let idleTimer = null, hintTimer = null;

        function isOn() { return veil.classList.contains('on'); }

        function dim() {
            if (isOn()) return;
            veil.classList.add('on');
            hint.style.color = '#242424';                                 // brief cue…
            clearTimeout(hintTimer);
            hintTimer = setTimeout(() => { hint.style.color = '#000'; }, 2500); // …fades to pure black
        }
        function wake(e) {
            if (!isOn()) return;
            if (e) { e.preventDefault(); e.stopPropagation(); }           // swallow the waking tap
            veil.classList.remove('on');
            arm();
        }
        function arm() {
            clearTimeout(idleTimer);
            idleTimer = setTimeout(dim, IDLE_MS);
        }

        veil.addEventListener('pointerdown', wake, true);
        veil.addEventListener('touchstart', wake, { capture: true, passive: false });
        window.addEventListener('keydown', e => { if (isOn()) wake(e); });
        document.getElementById('btnDim').addEventListener('click', dim);

        // any real activity re-arms the idle countdown
        ['pointermove', 'pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(ev =>
            document.addEventListener(ev, () => { if (!isOn()) arm(); }, true));
        arm();
    })();
}

/* ---------------------------------------------------------------- remote viewer (client view) */
if (IS_CLIENT) {
    const viewerId = (() => {
        let id = sessionStorage.getItem('av_viewer_id');
        if (!id) {
            id = Array.from(crypto.getRandomValues(new Uint8Array(8)), b => b.toString(16).padStart(2, '0')).join('');
            sessionStorage.setItem('av_viewer_id', id);
        }
        return id.substr(0, 16);
    })();

    const chips = {
        cap: document.getElementById('chipCap'),
        video: document.getElementById('chipVideo'),
        audio: document.getElementById('chipAudio'),
        viewers: document.getElementById('chipViewers')
    };
    const frameImg = document.getElementById('frame'), framePh = document.getElementById('framePh');
    const btnVideo = document.getElementById('btnVideo'), btnAudio = document.getElementById('btnAudio');
    const btnStartAll = document.getElementById('btnStartAll'), btnStopAll = document.getElementById('btnStopAll');
    const stage = document.getElementById('stage'), btnFs = document.getElementById('btnFs');
    const zoomEl = document.getElementById('zoom'), zvalEl = document.getElementById('zval');
    const player = document.getElementById('player');

    let st = { video_on: false, audio_on: false, zoom: 1, capture_online: false, seq_frame: 0, seq_audio: 0 };
    let seqF = 0, seqA = 0, fBusy = false, aBusy = false, lastUrl = null;
    let audioQueue = [], playing = false, zoomDebounce = null, zoomDragging = false;

    async function control(fields) {
        fields.id = viewerId;
        try {
            const r = await fetch(CFG_URL + '?a=control', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(fields)
            });
            if (r.ok) { const d = await r.json(); st.video_on = d.video_on; st.audio_on = d.audio_on; st.zoom = d.zoom; render(); }
        } catch (e) {}
    }

    function render() {
        setChip(chips.cap, st.capture_online ? 'CAPTURE DEVICE ONLINE' : 'CAPTURE DEVICE OFFLINE', st.capture_online ? 'on' : 'err');
        setChip(chips.video, st.video_on ? 'VIDEO ON' : 'VIDEO OFF', st.video_on ? 'on' : '');
        setChip(chips.audio, st.audio_on ? 'AUDIO ON' : 'AUDIO OFF', st.audio_on ? 'on' : '');
        setChip(chips.viewers, 'VIEWERS ' + (st.viewers || 0), (st.viewers || 0) > 0 ? 'on' : '');
        btnVideo.textContent = st.video_on ? 'Stop video' : 'Start video';
        btnAudio.textContent = st.audio_on ? 'Stop audio' : 'Start audio';
        btnVideo.classList.toggle('active', !!st.video_on);
        btnAudio.classList.toggle('active', !!st.audio_on);
        // Start all / Stop all are mutually exclusive: only the applicable one is enabled
        const allOn = st.video_on && st.audio_on, allOff = !st.video_on && !st.audio_on;
        btnStartAll.disabled = allOn;
        btnStopAll.disabled = allOff;
        btnStartAll.classList.toggle('active', allOn);
        btnStopAll.classList.toggle('active', allOff && st.capture_online);
        zoomEl.disabled = !st.video_on;
        if (!zoomDragging) { zoomEl.value = st.zoom; zvalEl.textContent = Number(st.zoom).toFixed(1) + '×'; }
        if (!st.video_on) { frameImg.style.display = 'none'; framePh.style.display = 'block'; framePh.textContent = 'NO SIGNAL'; }
        else if (!st.capture_online) { framePh.textContent = 'CAPTURE DEVICE OFFLINE'; }
    }

    async function pollStatus() {
        try {
            const r = await fetch(CFG_URL + '?a=status&id=' + viewerId, { cache: 'no-store' });
            if (r.status === 401) { framePh.textContent = 'SESSION EXPIRED — RELOAD PAGE'; return; }
            const d = await r.json();
            st = d;
            render();
        } catch (e) {
            setChip(chips.cap, 'NODE UNREACHABLE', 'err');
        }
    }

    async function frameTick() {
        if (!st.video_on || fBusy) return;
        fBusy = true;
        try {
            const r = await fetch(CFG_URL + '?a=frame&since=' + seqF + '&id=' + viewerId, { cache: 'no-store' });
            if (r.status === 200) {
                seqF = parseInt(r.headers.get('X-Seq'), 10) || seqF;
                const b = await r.blob();
                if (lastUrl) URL.revokeObjectURL(lastUrl);
                lastUrl = URL.createObjectURL(b);
                frameImg.src = lastUrl;
                frameImg.style.display = 'block';
                framePh.style.display = 'none';
            }
        } catch (e) {} finally { fBusy = false; }
    }

    function playNext() {
        if (playing || !audioQueue.length) return;
        playing = true;
        const url = URL.createObjectURL(audioQueue.shift());
        player.src = url;
        player.onended = () => { URL.revokeObjectURL(url); playing = false; playNext(); };
        player.onerror = () => { URL.revokeObjectURL(url); playing = false; playNext(); };
        player.play().catch(() => { playing = false; });
    }

    async function audioTick() {
        if (!st.audio_on || aBusy) return;
        aBusy = true;
        try {
            const r = await fetch(CFG_URL + '?a=audio&after=' + seqA + '&id=' + viewerId, { cache: 'no-store' });
            if (r.status === 200) {
                seqA = parseInt(r.headers.get('X-Seq'), 10) || seqA;
                audioQueue.push(await r.blob());
                if (audioQueue.length > 4) audioQueue = audioQueue.slice(-2); // drop backlog, stay near-live
                playNext();
            }
        } catch (e) {} finally { aBusy = false; }
    }

    btnVideo.addEventListener('click', () => {
        if (!st.video_on) seqF = st.seq_frame || 0; // skip stale frames from a previous session
        control({ video: !st.video_on });
    });
    btnAudio.addEventListener('click', () => {
        if (!st.audio_on) { seqA = st.seq_audio || 0; audioQueue = []; }
        control({ audio: !st.audio_on });
    });
    btnStartAll.addEventListener('click', () => {
        if (!st.video_on) seqF = st.seq_frame || 0;
        if (!st.audio_on) { seqA = st.seq_audio || 0; audioQueue = []; }
        control({ video: true, audio: true });
    });
    btnStopAll.addEventListener('click', () => { audioQueue = []; control({ video: false, audio: false }); });

    /* ---- fullscreen (native where available, CSS fallback elsewhere) ----
     * object-fit:contain keeps the frame correct in both portrait and
     * landscape on mobile; no orientation lock is applied. */
    function fsActive() {
        return document.fullscreenElement === stage || stage.classList.contains('fsfake');
    }
    function fsSync() {
        btnFs.innerHTML = fsActive() ? '&#x2715;' : '&#x26F6;';
        btnFs.title = fsActive() ? 'Exit fullscreen' : 'Fullscreen';
    }
    btnFs.addEventListener('click', async () => {
        if (fsActive()) {
            if (document.fullscreenElement) { try { await document.exitFullscreen(); } catch (e) {} }
            stage.classList.remove('fsfake');
        } else if (stage.requestFullscreen) {
            try { await stage.requestFullscreen(); } catch (e) { stage.classList.add('fsfake'); }
        } else if (stage.webkitRequestFullscreen) {
            stage.webkitRequestFullscreen();
        } else {
            stage.classList.add('fsfake'); // e.g. iOS Safari: fixed-position overlay
        }
        fsSync();
    });
    document.addEventListener('fullscreenchange', fsSync);
    document.addEventListener('webkitfullscreenchange', fsSync);

    zoomEl.addEventListener('input', () => {
        zoomDragging = true;
        zvalEl.textContent = Number(zoomEl.value).toFixed(1) + '×';
        clearTimeout(zoomDebounce);
        zoomDebounce = setTimeout(() => { zoomDragging = false; control({ zoom: parseFloat(zoomEl.value) }); }, 300);
    });

    makeTicker(2000, pollStatus);
    makeTicker(500, frameTick);
    makeTicker(1000, audioTick);
    pollStatus();
}
</script>
<script>window.NODEPULSE_PING_INTERVAL = 30000;</script>
<script src="/nodepulse-sw.js"></script>
</body>
</html>
