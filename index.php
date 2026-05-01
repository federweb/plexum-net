<?php
// Attach to the shared auth-gate session (read-only check — no gate enforced here)
if (PHP_OS_FAMILY !== 'Windows') {
    $npHome = getenv('HOME') ?: '/data/data/com.termux/files/home';
} else {
    $npHome = str_replace('\\', '/', (getenv('HOME') ?: getenv('USERPROFILE') ?: dirname(dirname(__DIR__))));
}
$sessDir = $npHome . '/tmp/.sessions';
if (session_status() === PHP_SESSION_NONE) {
    if (is_dir($sessDir)) session_save_path($sessDir);
    session_start();
}
$np_is_logged_in = !empty($_SESSION['gate_auth']);

$np_identity_file  = __DIR__ . '/nodepulse/node_identity.json';
$np_directory_file = __DIR__ . '/nodepulse/directory.json';

$np_node_id  = null;
$np_node_url = null;

if (file_exists($np_identity_file)) {
    $np_identity = json_decode(file_get_contents($np_identity_file), true);
    if (isset($np_identity['node_id'])) {
        $np_node_id = $np_identity['node_id'];
    }
}

if ($np_node_id && file_exists($np_directory_file)) {
    $np_directory = json_decode(file_get_contents($np_directory_file), true);
    if (isset($np_directory['entries']) && is_array($np_directory['entries'])) {
        foreach ($np_directory['entries'] as $entry) {
            if (isset($entry['node_id']) && $entry['node_id'] === $np_node_id) {
                $np_node_url = isset($entry['url']) ? $entry['url'] : null;
                break;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>NODEPULSE</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:       #0d0f14;
            --surface:  #151820;
            --border:   #1e2330;
            --accent:   #00c9a7;
            --accent2:  #0a7cff;
            --accent3:  #ff6b35;
            --accent4:  #a855f7;
            --text:     #e2e8f0;
            --muted:    #64748b;
            --radius:   16px;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px 16px 40px;
        }

        /* ── Header ─────────────────────────────────────────── */
        header {
            text-align: center;
            margin-bottom: 36px;
            user-select: none;
        }

        header .logo {
            font-size: clamp(1.6rem, 5vw, 2.4rem);
            font-weight: 800;
            letter-spacing: 0.12em;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        header .pulse-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            background: var(--accent);
            border-radius: 50%;
            margin-left: 8px;
            vertical-align: middle;
            position: relative;
            top: -2px;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(0,201,167,.6); }
            50%       { box-shadow: 0 0 0 8px rgba(0,201,167,0); }
        }

        header .subtitle {
            margin-top: 6px;
            font-size: .85rem;
            color: var(--muted);
            letter-spacing: .06em;
        }

        /* Row that holds the subtitle plus the (conditional) logout button */
        header .subtitle-row {
            margin-top: 6px;
            display: inline-flex;
            align-items: center;
            gap: 12px;
        }
        header .subtitle-row .subtitle { margin-top: 0; }

        /* Small Logout button — shown only to logged-in users */
        .btn-logout {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            font-size: .55rem;
            font-weight: 600;
            color: #b0b3b6;
            background: transparent;
            border: 1px solid #2a3040;
            border-radius: 7px;
            text-decoration: none;
            letter-spacing: .05em;
            text-transform: uppercase;
            transition: border-color .18s, color .18s, background .18s;
        }
        .btn-logout:hover {
            border-color: var(--accent3);
            color: var(--accent3);
            background: rgba(255,107,53,.08);
        }
        .btn-logout svg { flex-shrink: 0; }

        /* ── Grid ────────────────────────────────────────────── */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 18px;
            width: 100%;
            max-width: 900px;
        }

        /* ── Card ────────────────────────────────────────────── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 28px 24px 22px;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
            overflow: hidden;
            transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at top left, var(--card-accent, var(--accent)) 0%, transparent 60%);
            opacity: 0;
            transition: opacity .25s ease;
        }

        .card:hover,
        .card:focus-visible {
            transform: translateY(-3px);
            border-color: var(--card-accent, var(--accent));
            box-shadow: 0 8px 32px rgba(0,0,0,.35), 0 0 0 1px var(--card-accent, var(--accent)) inset;
        }

        .card:hover::before,
        .card:focus-visible::before {
            opacity: .06;
        }

        .card:active { transform: translateY(0); }

        /* colour overrides per card */
        .card.terminal  { --card-accent: var(--accent);  }
        .card.files     { --card-accent: var(--accent2); }
        .card.wireog    { --card-accent: var(--accent3); }
        .card.desktop   { --card-accent: var(--accent4); }
        .card.upload    { --card-accent: #f59e0b; }
        .card.bookmarks { --card-accent: #8b5cf6; }
        .card.secureshare { --card-accent: #e11d48; }
        .card.keychain  { --card-accent: #06b6d4; }
        .card.domainseed { --card-accent: #10b981; }
        .card.monitor    { --card-accent: #ec4899; }
        .card.blog       { --card-accent: #22d3ee; }
        .card.meet       { --card-accent: #f472b6; }
        .card.twofa      { --card-accent: #fbbf24; }
        .card.pinboard   { --card-accent: #84cc16; }

        /* ── Node info bar ───────────────────────────────────── */
        .node-info {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px 24px;
            margin-top: 14px;
        }

        .node-info-item {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: .78rem;
            color: var(--muted);
        }

        .node-info-item .label {
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            font-size: .68rem;
            color: var(--accent);
        }

        .node-info-item .value {
            font-family: 'Consolas', 'Courier New', monospace;
            color: var(--text);
            opacity: .85;
        }

        .node-info-item .value a {
            color: var(--accent2);
            text-decoration: none;
        }

        .node-info-item .value a:hover { text-decoration: underline; }

        /* ── Card icon ───────────────────────────────────────── */
        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--card-accent, var(--accent));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
            opacity: .9;
        }

        /* ── Card text ───────────────────────────────────────── */
        .card-title {
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: .04em;
            color: var(--text);
        }

        .card-desc {
            font-size: .82rem;
            color: var(--muted);
            line-height: 1.55;
        }

        .card-arrow {
            margin-top: auto;
            font-size: .78rem;
            color: var(--card-accent, var(--accent));
            font-weight: 600;
            letter-spacing: .05em;
            display: flex;
            align-items: center;
            gap: 5px;
            opacity: .8;
            transition: gap .15s ease, opacity .15s ease;
        }

        .card:hover .card-arrow { gap: 9px; opacity: 1; }

        /* ── Footer ──────────────────────────────────────────── */
        footer {
            margin-top: 44px;
            font-size: .75rem;
            color: var(--muted);
            text-align: center;
            letter-spacing: .04em;
            opacity: .6;
        }

        /* ── Mobile tweaks ───────────────────────────────────── */
        @media (max-width: 480px) {
            body { padding: 18px 12px 32px; }
            .grid { grid-template-columns: 1fr; gap: 14px; }
            .card { padding: 22px 18px 18px; }
            header { margin-bottom: 28px; }
            .btn-chpw { top: 10px; right: 10px; padding: 6px; font-size: .7rem; gap: 0; }
            .btn-chpw .btn-chpw-label { display: none; }
        }

        /* ── Change-password button ─────────────────────────── */
        .btn-chpw {
            position: fixed;
            top: 16px;
            right: 18px;
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 7px 14px;
            background: transparent;
            border: 1px solid #2a3040;
            border-radius: 6px;
            color: var(--muted);
            font-size: .75rem;
            font-weight: 600;
            text-decoration: none;
            letter-spacing: .04em;
            transition: border-color .18s, color .18s, background .18s;
            z-index: 100;
        }
        .btn-chpw:hover {
            border-color: var(--accent);
            color: var(--accent);
            background: rgba(0,201,167,.06);
        }
        .btn-chpw svg { flex-shrink: 0; }
    </style>
</head>
<body>

    <a href="/change-password.php" class="btn-chpw" title="Change access password">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
        <span class="btn-chpw-label">Change Password</span>
    </a>

    <header>
        <div class="logo">NODEPULSE<span class="pulse-dot"></span></div>
        <div class="subtitle-row">
            <p class="subtitle">n e t w o r k</p>
            <?php if ($np_is_logged_in): ?>
            <a href="/logout.php" class="btn-logout" title="Sign out of NodePulse">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                Logout
            </a>
            <?php endif; ?>
        </div>
        <div class="node-info">
            <?php if ($np_node_id || $np_node_url): ?>
                <?php if ($np_node_id): ?>
                <div class="node-info-item">
                    <span class="label">Node ID</span>
                    <span class="value"><?php echo htmlspecialchars($np_node_id); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($np_node_url): ?>
                <div class="node-info-item">
                    <span class="label">URL</span>
                    <span class="value"><a href="<?php echo htmlspecialchars($np_node_url); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($np_node_url); ?></a></span>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="node-info-item">
                    <span class="value" style="color: var(--muted); font-style: italic;">Network is currently in discovery phase...</span>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <main class="grid">

        <?php if (is_dir(__DIR__ . '/cli')): ?>
        <!-- Shell -->
        <a href="cli/" class="card terminal" aria-label="Open Terminal">
            <div class="card-icon" style="font-family:monospace;font-size:20px;font-weight:700">&gt;_</div>
            <div>
                <div class="card-title">Shell</div>
                <div class="card-desc">Run commands, manage processes, and monitor your Termux environment in real-time through a sleek web interface.</div>
            </div>
            <div class="card-arrow">Open &rarr;</div>
        </a>
        <?php endif; ?>

        <?php if (is_dir(__DIR__ . '/filemanager')): ?>
        <!-- File Manager -->
        <a href="filemanager/" class="card files" aria-label="Open File Manager">
            <div class="card-icon">&#x1F4C2;</div>
            <div>
                <div class="card-title">File Manager</div>
                <div class="card-desc">Browse, upload, edit and delete files on the server. Full filesystem access from any browser.</div>
            </div>
            <div class="card-arrow">Open &rarr;</div>
        </a>
        <?php endif; ?>

        <?php if (is_dir(__DIR__ . '/cloud')): ?>
        <!-- Cloud -->
        <a href="cloud/" class="card upload" aria-label="Upload from URL">
            <div class="card-icon">&#x2B06;</div>
            <div>
                <div class="card-title">Cloud</div>
                <div class="card-desc">Transfer any file from your device or the web directly to your storage.</div>
            </div>
            <div class="card-arrow">Open &rarr;</div>
        </a>
        <?php endif; ?>

        <?php if (is_dir(__DIR__ . '/monitor')): ?>
        <!-- Node Monitor -->
        <a href="monitor/" class="card monitor" aria-label="Node Monitor">
            <div class="card-icon">&#x1F4CA;</div>
            <div>
                <div class="card-title">Node Monitor</div>
                <div class="card-desc">Real-time view of your node status, peers, announcements and network health metrics.</div>
            </div>
            <div class="card-arrow">Open &rarr;</div>
        </a>
        <?php endif; ?>

        <?php if (is_dir(__DIR__ . '/bookmarks')): ?>
        <!-- Bookmarks -->
        <a href="bookmarks/" class="card bookmarks" aria-label="Open Bookmarks">
            <div class="card-icon">&#x2B50;</div>
            <div>
                <div class="card-title">Bookmarks</div>
                <div class="card-desc">Manage your favourite links. Search, organize by folders and quickly access your saved bookmarks.</div>
            </div>
            <div class="card-arrow">Open &rarr;</div>
        </a>
        <?php endif; ?>

        <?php if (is_dir(__DIR__ . '/domainseed')): ?>
        <!-- Domain Seed -->
        <a href="domainseed/" class="card domainseed" aria-label="Domain Seed Registration">
            <div class="card-icon">&#x1F331;</div>
            <div>
                <div class="card-title">Domain Seed</div>
                <div class="card-desc">Register your domain as a NodePulse seed. Generate identity and deploy package to contribute to the network.</div>
            </div>
            <div class="card-arrow">Open &rarr;</div>
        </a>
        <?php endif; ?>

        <?php if (is_dir(__DIR__ . '/secureshare')): ?>
        <!-- Secure Share -->
        <a href="secureshare/" class="card secureshare" aria-label="Open Secure Share">
            <div class="card-icon">&#x1F512;</div>
            <div>
                <div class="card-title">Secure Share</div>
                <div class="card-desc">Share URLs securely with encryption. Generate protected links that only intended recipients can access.</div>
            </div>
            <div class="card-arrow">Open &rarr;</div>
        </a>
        <?php endif; ?>

        <?php if (is_dir(__DIR__ . '/keychain')): ?>
        <!-- Keychain -->
        <a href="keychain/" class="card keychain" aria-label="Open Keychain">
            <div class="card-icon">&#x1F511;</div>
            <div>
                <div class="card-title">Keychain</div>
                <div class="card-desc">Your personal URL keychain. Store, organize and quickly access your most important links in one secure place.</div>
            </div>
            <div class="card-arrow">Open &rarr;</div>
        </a>
        <?php endif; ?>

        <?php if (is_dir(__DIR__ . '/2fa')): ?>
        <!-- 2FA -->
        <a href="2fa/" target="_blank" rel="noopener" class="card twofa" aria-label="Open 2FA">
            <div class="card-icon">&#x1F510;</div>
            <div>
                <div class="card-title">2FA</div>
                <div class="card-desc">Two-factor authentication codes. Generate time-based one-time passwords for your accounts directly from your node.</div>
            </div>
            <div class="card-arrow">Open &rarr;</div>
        </a>
        <?php endif; ?>

        <?php if (is_dir(__DIR__ . '/desktop')): ?>
        <!-- PulseDesktop -->
        <a href="desktop/" target="_blank" rel="noopener" class="card desktop" aria-label="Open PulseDesktop">
            <div class="card-icon">&#x1F310;</div>
            <div>
                <div class="card-title">PulseDesktop</div>
                <div class="card-desc">A server-side browsing solution powered by Openbox and Xvnc. Run a real desktop environment on your server and access it via any web browser</div>
            </div>
            <div class="card-arrow">Open &rarr;</div>
        </a>
        <?php endif; ?>

        <?php if (is_dir(__DIR__ . '/wireog')): ?>
        <!-- Wireog -->
        <a href="wireog/" target="_blank" rel="noopener" class="card wireog" aria-label="Open Wireog">
            <div class="card-icon" style="background:transparent"><img src="wireog/img/favicon.png" alt="Wireog" style="width:36px;height:36px;object-fit:contain"></div>
            <div>
                <div class="card-title">Wireog</div>
                <div class="card-desc">Encrypted peer-to-peer video &amp; audio rooms. Create or join a secure conference with no third-party servers.</div>
            </div>
            <div class="card-arrow">Open &rarr;</div>
        </a>
        <?php endif; ?>

        <?php if (is_dir(__DIR__ . '/meet')): ?>
        <!-- Meet -->
        <a href="meet/"  target="_blank" class="card meet" aria-label="Open Meet">
            <div class="card-icon">&#x1F3A5;</div>
            <div>
                <div class="card-title">Meet</div>
                <div class="card-desc">Video and voice meetings. Create or join a room and connect face-to-face directly from your browser.</div>
            </div>
            <div class="card-arrow">Open &rarr;</div>
        </a>
        <?php endif; ?>

        <?php if (is_dir(__DIR__ . '/blog')): ?>
        <!-- Blog -->
        <a href="blog/" class="card blog" aria-label="Open Blog">
            <div class="card-icon">&#x270D;</div>
            <div>
                <div class="card-title">Blog</div>
                <div class="card-desc">Flat-file microblog with markdown, tags, image embedding and public comments. Zero dependencies.</div>
            </div>
            <div class="card-arrow">Open &rarr;</div>
        </a>
        <?php endif; ?>

        <?php if (is_dir(__DIR__ . '/pinboard')): ?>
        <!-- PinBoard -->
        <a href="pinboard/" class="card pinboard" aria-label="Open PinBoard">
            <div class="card-icon">&#x1F4CC;</div>
            <div>
                <div class="card-title">PinBoard</div>
                <div class="card-desc">Visual pinboard for notes, ideas and snippets. Organize your thoughts with drag-and-drop cards in a flexible canvas.</div>
            </div>
            <div class="card-arrow">Open &rarr;</div>
        </a>
        <?php endif; ?>

    </main>

    <footer>
        &copy; <?php echo date('Y'); ?> NODEPULSE &bull; Running on <?php echo php_uname('n'); ?>
    </footer>

<script src="/nodepulse-sw.js"></script>
</body>
</html>
