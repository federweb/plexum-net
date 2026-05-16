<?php
/**
 * NodePulse MicroCMS v1.0
 * Single-file flat-file CMS — JSON storage, markdown, zero dependencies
 * Public: index.php | Admin: index.php?admin (via auth_gate)
 */

// ── Platform & Session ─────────────────────────────────
if (PHP_OS_FAMILY !== 'Windows') {
    $npHome = getenv('HOME') ?: '/data/data/com.termux/files/home';
} else {
    $npHome = str_replace('\\', '/', (getenv('HOME') ?: getenv('USERPROFILE')));
}
$sessDir = $npHome . '/tmp/.sessions';
if (!is_dir($sessDir)) mkdir($sessDir, 0700, true);
if (session_status() === PHP_SESSION_NONE) {
    session_save_path($sessDir);
    session_start();
}

// ── Config ──────────────────────────────────────────────
define('SITE_NAME', 'NodePulse Blog');
define('SITE_DESC', 'Decentralized thoughts');
define('DATA_DIR', __DIR__ . '/data');
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('PER_PAGE', 10);
define('MAX_UPLOAD', 2 * 1024 * 1024);

foreach ([DATA_DIR, UPLOAD_DIR] as $d) if (!is_dir($d)) mkdir($d, 0755, true);

// ── Auth (admin only, via shared auth_gate) ─────────────
$is_admin = !empty($_SESSION['gate_auth']);
if (isset($_GET['admin']) && !$is_admin) {
    require __DIR__ . '/../auth_gate.php';
    $is_admin = true;
}

// ── Helpers ─────────────────────────────────────────────
function np_read(string $f): array {
    if (!file_exists($f) || filesize($f) === 0) return [];
    $fp = fopen($f, 'r');
    flock($fp, LOCK_SH);
    $d = json_decode(fread($fp, max(filesize($f), 1)), true) ?: [];
    flock($fp, LOCK_UN);
    fclose($fp);
    return $d;
}

function np_write(string $f, array $d): void {
    $fp = fopen($f, 'c');
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function uid(): string { return bin2hex(random_bytes(6)); }
function slugify(string $s): string {
    return trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($s)), '-') ?: uid();
}

function csrf(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    return '<input type="hidden" name="_csrf" value="' . $_SESSION['_csrf'] . '">';
}
function csrf_ok(): bool {
    return isset($_POST['_csrf'], $_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $_POST['_csrf']);
}

function excerpt(string $body, int $len = 200): string {
    $t = strip_tags(md_render($body));
    return mb_strlen($t) > $len ? mb_substr($t, 0, $len) . '...' : $t;
}

function list_uploads(): array {
    $files = [];
    if (!is_dir(UPLOAD_DIR)) return $files;
    foreach (scandir(UPLOAD_DIR) as $f) {
        if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg)$/i', $f)) $files[] = $f;
    }
    return $files;
}

function time_ago(string $iso): string {
    $diff = time() - strtotime($iso);
    if ($diff < 60) return 'now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return substr($iso, 0, 10);
}

// Data file paths
function pf(): string { return DATA_DIR . '/posts.json'; }
function cf(): string { return DATA_DIR . '/comments.json'; }
function tf(): string { return DATA_DIR . '/tags.json'; }

// Tag list = derived from posts. Always rebuild from current post tags
// to purge orphans (tags removed from posts but lingering in tags.json).
function rebuild_tags(): void {
    $posts = np_read(pf());
    $tags = [];
    foreach ($posts as $p) {
        foreach ($p['tags'] ?? [] as $t) {
            $t = trim($t);
            if ($t !== '' && !in_array($t, $tags, true)) $tags[] = $t;
        }
    }
    sort($tags, SORT_NATURAL | SORT_FLAG_CASE);
    np_write(tf(), $tags);
}

// ── Markdown ────────────────────────────────────────────
function md_render(string $t): string {
    $t = h($t);
    $t = preg_replace('/```(\w*)\n(.*?)```/s', '<pre><code>$2</code></pre>', $t);
    $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
    $t = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1" loading="lazy">', $t);
    $t = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $t);
    $t = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $t);
    $t = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $t);
    $t = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $t);
    $t = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $t);
    $t = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
    $t = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $t);
    $t = preg_replace('/^> (.+)$/m', '<blockquote>$1</blockquote>', $t);
    $t = preg_replace('/^[\-\*] (.+)$/m', '<li>$1</li>', $t);
    $t = preg_replace('/((?:<li>[^<]*<\/li>\s*)+)/s', '<ul>$1</ul>', $t);
    $t = preg_replace('/^---+$/m', '<hr>', $t);
    $t = preg_replace('/\n{2,}/', '</p><p>', $t);
    $t = '<p>' . $t . '</p>';
    $t = preg_replace('/<p>\s*(<(?:h[1-4]|ul|pre|blockquote|hr)[^>]*>)/s', '$1', $t);
    $t = preg_replace('/<\/(h[1-4]|ul|pre|blockquote)>\s*<\/p>/s', '</$1>', $t);
    $t = str_replace(['<p></p>', '<p><hr></p>', '<p><hr>'], ['', '<hr>', '<hr>'], $t);
    return $t;
}

// ── POST Actions ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    // Public: add comment (no auth needed)
    if ($act === 'comment') {
        if (!empty($_POST['website'])) { header('Location: ?'); exit; }
        $pid = $_POST['post_id'] ?? '';
        $author = mb_substr(trim($_POST['author'] ?? ''), 0, 50) ?: 'Anonymous';
        $body = mb_substr(trim($_POST['body'] ?? ''), 0, 2000);
        if ($pid && $body) {
            $cc = np_read(cf());
            $cc[$pid][] = [
                'id' => uid(),
                'author' => $author,
                'body' => $body,
                'date' => gmdate('Y-m-d\TH:i:s\Z')
            ];
            np_write(cf(), $cc);
        }
        header('Location: ?post=' . urlencode($pid) . '#comments'); exit;
    }

    // Admin actions require auth + CSRF
    if (!$is_admin || !csrf_ok()) { header('Location: ?admin'); exit; }

    switch ($act) {
        case 'save_post':
            $pp = np_read(pf());
            $id = $_POST['id'] ?: uid();
            $title = trim($_POST['title'] ?? '') ?: 'Untitled';
            // Split on whitespace OR comma; strip leading "#"; dedup.
            $rawParts = preg_split('/[\s,]+/', $_POST['tags'] ?? '');
            $ptags = [];
            foreach ($rawParts as $rt) {
                $rt = ltrim(trim($rt), '#');
                if ($rt !== '' && !in_array($rt, $ptags, true)) $ptags[] = $rt;
            }
            $pp[$id] = [
                'id' => $id, 'title' => $title, 'slug' => slugify($title),
                'body' => $_POST['body'] ?? '', 'tags' => $ptags,
                'visible' => isset($_POST['visible']),
                'created' => $pp[$id]['created'] ?? gmdate('Y-m-d\TH:i:s\Z'),
                'updated' => gmdate('Y-m-d\TH:i:s\Z')
            ];
            np_write(pf(), $pp);
            rebuild_tags();
            header('Location: ?admin'); break;

        case 'del_post':
            $pp = np_read(pf()); unset($pp[$_POST['id'] ?? '']); np_write(pf(), $pp);
            $cc = np_read(cf()); unset($cc[$_POST['id'] ?? '']); np_write(cf(), $cc);
            rebuild_tags();
            header('Location: ?admin'); break;

        case 'toggle':
            $pp = np_read(pf()); $id = $_POST['id'] ?? '';
            if (isset($pp[$id])) { $pp[$id]['visible'] = !$pp[$id]['visible']; np_write(pf(), $pp); }
            header('Location: ?admin'); break;

        case 'del_comment':
            $cc = np_read(cf()); $pid = $_POST['pid'] ?? ''; $cid = $_POST['cid'] ?? '';
            if (isset($cc[$pid])) {
                $cc[$pid] = array_values(array_filter($cc[$pid], fn($c) => $c['id'] !== $cid));
                if (!$cc[$pid]) unset($cc[$pid]);
                np_write(cf(), $cc);
            }
            header('Location: ?admin=comments'); break;

        case 'add_tag':
            $tg = ltrim(trim($_POST['tag'] ?? ''), '#');
            if ($tg !== '') { $at = np_read(tf()); if (!in_array($tg, $at, true)) { $at[] = $tg; np_write(tf(), $at); } }
            header('Location: ?admin=tags'); break;

        case 'del_tag':
            $tg = $_POST['tag'] ?? '';
            if ($tg !== '') {
                $pp = np_read(pf());
                $changed = false;
                foreach ($pp as $pid => $post) {
                    if (in_array($tg, $post['tags'] ?? [], true)) {
                        $pp[$pid]['tags'] = array_values(array_filter(
                            $post['tags'],
                            function($t) use ($tg) { return $t !== $tg; }
                        ));
                        $changed = true;
                    }
                }
                if ($changed) np_write(pf(), $pp);
                rebuild_tags();
            }
            header('Location: ?admin=tags'); break;

        case 'clean_tags':
            rebuild_tags();
            header('Location: ?admin=tags'); break;

        case 'upload':
            if (isset($_FILES['img']) && $_FILES['img']['error'] === 0 && $_FILES['img']['size'] <= MAX_UPLOAD) {
                $ext = strtolower(pathinfo($_FILES['img']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) {
                    move_uploaded_file($_FILES['img']['tmp_name'], UPLOAD_DIR . '/' . uid() . '.' . $ext);
                }
            }
            header('Location: ?admin=media'); break;

        case 'del_file':
            $fn = basename($_POST['file'] ?? '');
            if ($fn && file_exists(UPLOAD_DIR . '/' . $fn)) unlink(UPLOAD_DIR . '/' . $fn);
            header('Location: ?admin=media'); break;
    }
    exit;
}

// ── Route ───────────────────────────────────────────────
$post_id = $_GET['post'] ?? null;
$tag_filter = $_GET['tag'] ?? null;
$pg = max(1, (int)($_GET['p'] ?? 1));
$adm = isset($_GET['admin']) ? ($_GET['admin'] ?: 'dashboard') : null;

// Load data
$posts = np_read(pf());
$tags = np_read(tf());
$comments = np_read(cf());

// Sort posts newest first
uasort($posts, fn($a, $b) => ($b['created'] ?? '') <=> ($a['created'] ?? ''));

// ── HTML ────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $adm ? 'Admin — ' : '' ?><?= h(SITE_NAME) ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0a0a0a;--card:#111;--border:#1e1e1e;--text:#d0d0d0;--muted:#666;--accent:#00ff88;--accent2:#00cc6a;--danger:#ff4444}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);line-height:1.7}
a{color:var(--accent);text-decoration:none}a:hover{color:var(--accent2)}

/* Header */
.site-header{border-bottom:1px solid var(--border);padding:20px 0;margin-bottom:32px}
.site-header .wrap{display:flex;justify-content:space-between;align-items:center}
.site-header h1{font-size:20px;font-weight:700}.site-header h1 a{color:var(--accent)}
.site-header .desc{font-size:12px;color:var(--muted);margin-top:2px}
.site-header nav a{margin-left:16px;font-size:13px;color:var(--muted)}
.site-header nav a:hover{color:var(--accent)}
.site-header nav a.active{color:var(--accent)}
.admin-header{background:var(--card)}

/* Layout */
.wrap{max-width:780px;margin:0 auto;padding:0 20px}

/* Articles */
article{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:28px;margin-bottom:20px;transition:border-color .2s}
article:hover{border-color:#333}
article h2{font-size:20px;margin-bottom:6px}
article h2 a{color:var(--text)}article h2 a:hover{color:var(--accent)}
.meta{font-size:13px;color:var(--muted);margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.tag{display:inline-block;background:#0d1f0d;color:var(--accent);padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;letter-spacing:.3px}
a.tag:hover{background:#1a3a1a}

/* Content */
.content{line-height:1.8;font-size:15px}
.content img{max-width:100%;border-radius:6px;margin:16px 0;display:block}
.content pre{background:#161616;padding:16px;border-radius:6px;overflow-x:auto;margin:16px 0;border:1px solid var(--border)}
.content code{font-family:'SF Mono',Consolas,'Courier New',monospace;font-size:13px}
.content p code{background:#1a1a1a;padding:2px 6px;border-radius:3px}
.content blockquote{border-left:3px solid var(--accent);padding-left:16px;color:var(--muted);margin:16px 0}
.content h1,.content h2,.content h3,.content h4{margin:24px 0 12px;color:#fff}
.content ul{margin:12px 0;padding-left:24px}.content li{margin:4px 0}
.content hr{border:none;border-top:1px solid var(--border);margin:24px 0}
.content a{text-decoration:underline;text-underline-offset:2px}

/* Forms */
input[type="text"],input[type="password"],textarea,select{width:100%;padding:10px 14px;background:#0d0d0d;border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:14px;outline:none;margin-bottom:12px;transition:border-color .2s}
input:focus,textarea:focus{border-color:var(--accent)}
textarea{font-family:'SF Mono',Consolas,monospace;min-height:300px;resize:vertical;line-height:1.6}
label.check{display:flex;align-items:center;gap:8px;font-size:14px;margin-bottom:12px;cursor:pointer}
label.check input{width:auto;margin:0}

/* Buttons */
.btn{display:inline-block;padding:8px 16px;background:var(--accent);color:#000;border-radius:6px;font-weight:600;font-size:13px;border:none;cursor:pointer;transition:background .2s}
.btn:hover{background:var(--accent2);color:#000}
.btn-sm{padding:5px 10px;font-size:11px}
.btn-danger{background:var(--danger);color:#fff}.btn-danger:hover{background:#cc3333;color:#fff}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--text)}
.btn-ghost:hover{border-color:var(--accent);color:var(--accent)}

/* Table */
table{width:100%;border-collapse:collapse}
th,td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border);font-size:13px}
th{color:var(--muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px}
td a{color:var(--text)}td a:hover{color:var(--accent)}

/* Comments */
.comment{background:var(--card);border:1px solid var(--border);border-radius:6px;padding:16px;margin-top:12px}
.comment .c-author{font-weight:600;color:var(--accent);font-size:14px}
.comment .c-date{font-size:11px;color:var(--muted)}
.comment .c-body{margin-top:6px;font-size:14px;line-height:1.6}

/* Media gallery */
.gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
.gallery-item{background:var(--card);border:1px solid var(--border);border-radius:6px;overflow:hidden}
.gallery-item img{width:100%;height:120px;object-fit:cover;display:block}
.gallery-item .gi-bar{padding:8px;display:flex;gap:4px}

/* Pagination */
.pagination{text-align:center;margin:32px 0}
.pagination a,.pagination span{display:inline-block;padding:6px 12px;margin:0 2px;border-radius:4px;font-size:13px}
.pagination .cur{background:var(--accent);color:#000;font-weight:600}

/* Badges */
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;letter-spacing:.3px;text-transform:uppercase}
.badge-pub{background:#0d1f0d;color:var(--accent)}
.badge-draft{background:#1f1f0d;color:#ffaa00}

/* Tag chips (admin editor) */
.tag-chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;background:#0d0d0d;border:1px solid var(--border);border-radius:6px;padding:8px;margin-bottom:12px;transition:border-color .2s;cursor:text}
.tag-chips:focus-within{border-color:var(--accent)}
.tag-chips input{flex:1;min-width:160px;background:transparent;border:none;padding:4px 6px;color:var(--text);font-size:14px;outline:none;margin:0}
.tag-chip{display:inline-flex;align-items:center;gap:4px;background:#0d1f0d;color:var(--accent);padding:3px 4px 3px 8px;border-radius:4px;font-size:12px;font-weight:600;letter-spacing:.3px}
.tag-chip button{background:none;border:none;color:var(--accent);cursor:pointer;font-size:14px;padding:0 4px;line-height:1;border-radius:3px}
.tag-chip button:hover{color:var(--danger);background:#1a3a1a}

/* Site footer (public) */
.site-footer{border-top:1px solid var(--border);padding:28px 0;margin-top:48px;background:var(--card)}
.footer-tags{display:flex;flex-wrap:wrap;gap:8px;justify-content:center}
.footer-tags a.tag.active{background:var(--accent);color:#000}

/* Misc */
.hp{position:absolute;left:-9999px}
.empty{color:var(--muted);text-align:center;padding:40px 0}
.back{display:inline-block;margin-top:24px;font-size:14px}
.toolbar{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap}
.sect-title{font-size:18px;font-weight:700;margin-bottom:16px;color:#fff}
.row-actions{display:flex;gap:6px;align-items:center}

/* Responsive */
@media(max-width:600px){
    .dash-back{display:none!important}
    .site-header .wrap{flex-direction:column;gap:8px;text-align:center}
    .site-header nav a{margin:0 6px}
    .gallery{grid-template-columns:repeat(auto-fill,minmax(110px,1fr))}
    table{font-size:12px}th,td{padding:8px 6px}
    article{padding:20px}
}
</style>
</head>
<body>

<a href="/" class="dash-back" style="position:absolute;top:16px;left:16px;color:var(--muted);text-decoration:none;font-size:.85rem;transition:color .2s;z-index:10"
   onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--muted)'">&larr; Dashboard</a>

<?php if ($adm !== null): // ═══════════ ADMIN ═══════════ ?>

<header class="site-header admin-header">
<div class="wrap">
    <h1><a href="?admin">Admin</a></h1>
    <nav>
        <a href="?admin" class="<?= $adm === 'dashboard' ? 'active' : '' ?>">Post</a>
        <a href="?admin=tags" class="<?= $adm === 'tags' ? 'active' : '' ?>">Tag</a>
        <a href="?admin=media" class="<?= $adm === 'media' ? 'active' : '' ?>">Media</a>
        <a href="?admin=comments" class="<?= $adm === 'comments' ? 'active' : '' ?>">Comments</a>
        <a href="?" target="_blank">Blog</a>
        <a href="?gate_logout" style="color:var(--danger)">Logout</a>
    </nav>
</div>
</header>
<div class="wrap">

<?php if ($adm === 'edit'): // ── Edit/New Post ── ?>
<?php
    $edit_id = $_GET['id'] ?? '';
    $p = $posts[$edit_id] ?? null;
    $is_new = !$p;
    if ($is_new) $p = ['id'=>'','title'=>'','body'=>'','tags'=>[],'visible'=>true];
?>
<div class="sect-title"><?= $is_new ? 'New Post' : 'Edit Post' ?></div>
<form method="POST">
    <?= csrf() ?>
    <input type="hidden" name="action" value="save_post">
    <input type="hidden" name="id" value="<?= h($edit_id) ?>">

    <input type="text" name="title" value="<?= h($p['title']) ?>" placeholder="Post title" required autofocus>

    <div id="tagChips" class="tag-chips">
        <input type="text" id="tagInputNew" placeholder="type tag and press space &middot; backspace removes the last chip" autocomplete="off">
    </div>
    <input type="hidden" name="tags" id="tagsHidden" value="<?= h(implode(',', $p['tags'] ?? [])) ?>">
    <?php if ($tags): ?>
    <div style="margin:-4px 0 12px;display:flex;flex-wrap:wrap;gap:4px;align-items:center">
        <span style="font-size:11px;color:var(--muted);margin-right:4px">Existing:</span>
        <?php foreach ($tags as $t): ?>
        <span class="tag" style="cursor:pointer" onclick="addTag('<?= h($t) ?>')"><?= h($t) ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <label class="check">
        <input type="checkbox" name="visible" <?= ($p['visible'] ?? true) ? 'checked' : '' ?>>
        Published (visible to public)
    </label>

    <div class="toolbar">
        <button type="button" class="btn btn-ghost btn-sm" onclick="togglePreview()">Preview</button>
    </div>
    <textarea name="body" id="editor" placeholder="Write in Markdown..."><?= h($p['body'] ?? '') ?></textarea>
    <div id="preview" class="content" style="display:none;background:var(--card);border:1px solid var(--border);border-radius:6px;padding:24px;margin-bottom:12px"></div>

    <?php $imgs = list_uploads(); if ($imgs): ?>
    <div style="margin-bottom:16px">
        <div style="font-size:12px;color:var(--muted);margin-bottom:8px">Click image to insert in post:</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
            <?php foreach ($imgs as $fn): ?>
            <div style="cursor:pointer;border:1px solid var(--border);border-radius:4px;overflow:hidden;width:70px;transition:border-color .2s" onclick="insertImg('uploads/<?= h($fn) ?>')" title="<?= h($fn) ?>">
                <img src="uploads/<?= h($fn) ?>" style="width:70px;height:52px;object-fit:cover;display:block">
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:12px;align-items:center">
        <button type="submit" class="btn">Save</button>
        <a href="?admin">Cancel</a>
        <?php if (!$is_new): ?>
        <a href="?post=<?= h($edit_id) ?>" target="_blank" style="font-size:13px;color:var(--muted)">View post</a>
        <?php endif; ?>
    </div>
</form>

<?php elseif ($adm === 'tags'): // ── Tags ── ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <div class="sect-title" style="margin:0">Tag</div>
    <form method="POST" onsubmit="return confirm('Rimuovere i tag non usati da nessun post?')">
        <?= csrf() ?>
        <input type="hidden" name="action" value="clean_tags">
        <button type="submit" class="btn btn-ghost btn-sm">Clean unused</button>
    </form>
</div>
<form method="POST" style="display:flex;gap:8px;margin-bottom:20px">
    <?= csrf() ?>
    <input type="hidden" name="action" value="add_tag">
    <input type="text" name="tag" placeholder="New tag" style="margin:0;flex:1" required>
    <button type="submit" class="btn">Add</button>
</form>
<?php if ($tags): ?>
<div style="display:flex;flex-wrap:wrap;gap:8px">
    <?php foreach ($tags as $t):
        $count = 0;
        foreach ($posts as $p) if (in_array($t, $p['tags'] ?? [])) $count++;
    ?>
    <div style="display:flex;align-items:center;gap:6px;background:var(--card);border:1px solid var(--border);border-radius:6px;padding:8px 12px">
        <span class="tag"><?= h($t) ?></span>
        <span style="font-size:11px;color:var(--muted)"><?= $count ?> post</span>
        <form method="POST" style="display:inline" onsubmit="return confirm('Delete tag \'<?= h($t) ?>\'?')">
            <?= csrf() ?>
            <input type="hidden" name="action" value="del_tag">
            <input type="hidden" name="tag" value="<?= h($t) ?>">
            <button type="submit" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:16px;padding:0 2px;line-height:1">&times;</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<p class="empty">No tags. They're created automatically when you add them to posts.</p>
<?php endif; ?>

<?php elseif ($adm === 'media'): // ── Media ── ?>
<div class="sect-title">Media</div>
<form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;margin-bottom:8px;align-items:center">
    <?= csrf() ?>
    <input type="hidden" name="action" value="upload">
    <input type="file" name="img" accept="image/*" required style="flex:1;margin:0">
    <button type="submit" class="btn">Upload</button>
</form>
<p style="font-size:11px;color:var(--muted);margin-bottom:20px">Max 2 MB — JPG, PNG, GIF, WebP, SVG</p>
<?php $imgs = list_uploads(); if ($imgs): ?>
<div class="gallery">
    <?php foreach (array_reverse($imgs) as $fn): ?>
    <div class="gallery-item">
        <img src="uploads/<?= h($fn) ?>" alt="<?= h($fn) ?>">
        <div class="gi-bar">
            <button class="btn btn-ghost btn-sm" style="flex:1" onclick="copyMd('uploads/<?= h($fn) ?>')">Copy MD</button>
            <form method="POST" onsubmit="return confirm('Delete?')">
                <?= csrf() ?>
                <input type="hidden" name="action" value="del_file">
                <input type="hidden" name="file" value="<?= h($fn) ?>">
                <button type="submit" class="btn btn-danger btn-sm">&times;</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<p class="empty">No images loaded.</p>
<?php endif; ?>

<?php elseif ($adm === 'comments'): // ── Comments ── ?>
<div class="sect-title">Comments</div>
<?php
$has_comments = false;
foreach ($comments as $pid => $clist):
    $ptitle = $posts[$pid]['title'] ?? '[Deleted post]';
    foreach ($clist as $c):
        $has_comments = true;
?>
<div class="comment" style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
    <div style="flex:1;min-width:0">
        <div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap">
            <span class="c-author"><?= h($c['author']) ?></span>
            <span style="font-size:12px;color:var(--muted)">on</span>
            <a href="?post=<?= h($pid) ?>" style="font-size:13px"><?= h($ptitle) ?></a>
            <span class="c-date"><?= time_ago($c['date']) ?></span>
        </div>
        <div class="c-body"><?= h($c['body']) ?></div>
    </div>
    <form method="POST" onsubmit="return confirm('Delete?')">
        <?= csrf() ?>
        <input type="hidden" name="action" value="del_comment">
        <input type="hidden" name="pid" value="<?= h($pid) ?>">
        <input type="hidden" name="cid" value="<?= h($c['id']) ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
    </form>
</div>
<?php endforeach; endforeach;
if (!$has_comments): ?>
<p class="empty">No Comment</p>
<?php endif; ?>

<?php else: // ── Dashboard (post list) ── ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
    <div class="sect-title" style="margin:0">Post (<?= count($posts) ?>)</div>
    <a href="?admin=edit" class="btn">+ New post</a>
</div>
<?php if ($posts): ?>
<table>
    <tr><th>Title</th><th>Date</th><th>Tag</th><th>Status</th><th>Action</th></tr>
    <?php foreach ($posts as $p): ?>
    <tr>
        <td><a href="?admin=edit&id=<?= h($p['id']) ?>" style="font-weight:600"><?= h($p['title']) ?></a></td>
        <td style="color:var(--muted);white-space:nowrap"><?= substr($p['created'] ?? '', 0, 10) ?></td>
        <td><?php foreach ($p['tags'] ?? [] as $t): ?><span class="tag" style="margin-right:3px"><?= h($t) ?></span><?php endforeach; ?></td>
        <td>
            <form method="POST" style="display:inline">
                <?= csrf() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= h($p['id']) ?>">
                <button type="submit" class="badge <?= $p['visible'] ? 'badge-pub' : 'badge-draft' ?>" style="border:none;cursor:pointer">
                    <?= $p['visible'] ? 'Public' : 'Draft' ?>
                </button>
            </form>
        </td>
        <td>
            <div class="row-actions">
                <a href="?admin=edit&id=<?= h($p['id']) ?>" class="btn btn-ghost btn-sm">Edit</a>
                <a href="?post=<?= h($p['id']) ?>" target="_blank" class="btn btn-ghost btn-sm">View</a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete \'<?= h($p['title']) ?>\'?')">
                    <?= csrf() ?>
                    <input type="hidden" name="action" value="del_post">
                    <input type="hidden" name="id" value="<?= h($p['id']) ?>">
                    <button type="submit" class="btn btn-danger btn-sm">&times;</button>
                </form>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php else: ?>
<p class="empty">No posts yet. <a href="?admin=edit">Create the first post</a>.</p>
<?php endif; ?>
<?php endif; ?>

</div>

<?php else: // ═══════════ PUBLIC ═══════════ ?>

<header class="site-header">
<div class="wrap">
    <div>
        <h1><a href="?"><?= h(SITE_NAME) ?></a></h1>
        <div class="desc"><?= h(SITE_DESC) ?></div>
    </div>
    <nav>
        <a href="?" class="<?= !$tag_filter && !$post_id ? 'active' : '' ?>">Home</a>
        <a href="?admin" style="color:var(--accent)">Admin</a>
    </nav>
</div>
</header>
<div class="wrap">

<?php if ($post_id): // ── Single post ── ?>
<?php
    $p = $posts[$post_id] ?? null;
    if (!$p || (!$p['visible'] && !$is_admin)):
?>
<p class="empty">Post not found.</p>
<a href="?" class="back">&larr; Home</a>
<?php else: ?>
<article style="border:none;background:transparent;padding:0">
    <?php if (!$p['visible']): ?><span class="badge badge-draft" style="margin-bottom:12px">Draft</span><?php endif; ?>
    <h1 style="font-size:28px;margin-bottom:8px;color:#fff"><?= h($p['title']) ?></h1>
    <div class="meta">
        <span><?= time_ago($p['created'] ?? '') ?></span>
        <?php foreach ($p['tags'] ?? [] as $t): ?>
        <a href="?tag=<?= urlencode($t) ?>" class="tag">#<?= h($t) ?></a>
        <?php endforeach; ?>
        <?php if ($is_admin): ?>
        <a href="?admin=edit&id=<?= h($p['id']) ?>" style="font-size:12px">Edit</a>
        <?php endif; ?>
    </div>
    <div class="content"><?= md_render($p['body'] ?? '') ?></div>
</article>

<hr style="border:none;border-top:1px solid var(--border);margin:32px 0">

<!-- Comments -->
<section id="comments">
    <div class="sect-title">Comments (<?= count($comments[$post_id] ?? []) ?>)</div>

    <?php foreach ($comments[$post_id] ?? [] as $c): ?>
    <div class="comment">
        <div style="display:flex;justify-content:space-between;align-items:baseline">
            <span class="c-author"><?= h($c['author']) ?></span>
            <span class="c-date"><?= time_ago($c['date']) ?></span>
        </div>
        <div class="c-body"><?= nl2br(h($c['body'])) ?></div>
    </div>
    <?php endforeach; ?>

    <form method="POST" style="margin-top:20px">
        <input type="hidden" name="action" value="comment">
        <input type="hidden" name="post_id" value="<?= h($post_id) ?>">
        <div class="hp"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
        <input type="text" name="author" placeholder="Name (optional)" maxlength="50">
        <textarea name="body" placeholder="Write a comment..." rows="4" required maxlength="2000" style="min-height:100px"></textarea>
        <button type="submit" class="btn">Comment</button>
    </form>
</section>

<a href="?" class="back">&larr; All posts</a>
<?php endif; ?>

<?php else: // ── Post list (home / tag filter) ── ?>
<?php
    $visible = array_filter($posts, fn($p) => $p['visible']);
    if ($tag_filter) {
        $visible = array_filter($visible, fn($p) => in_array($tag_filter, $p['tags'] ?? []));
    }
    $total = count($visible);
    $pages = max(1, (int)ceil($total / PER_PAGE));
    $pg = min($pg, $pages);
    $visible = array_slice($visible, ($pg - 1) * PER_PAGE, PER_PAGE);
?>

<?php if ($tag_filter): ?>
<div style="margin-bottom:20px;display:flex;align-items:center;gap:12px">
    <div class="sect-title" style="margin:0">#<?= h($tag_filter) ?></div>
    <span style="color:var(--muted);font-size:13px"><?= $total ?> post</span>
    <a href="?" style="font-size:13px">&times; remove filter</a>
</div>
<?php endif; ?>

<?php if ($visible): ?>
    <?php foreach ($visible as $p): $cc = count($comments[$p['id']] ?? []); ?>
    <article>
        <h2><a href="?post=<?= h($p['id']) ?>"><?= h($p['title']) ?></a></h2>
        <div class="meta">
            <span><?= time_ago($p['created'] ?? '') ?></span>
            <?php foreach ($p['tags'] ?? [] as $t): ?>
            <a href="?tag=<?= urlencode($t) ?>" class="tag">#<?= h($t) ?></a>
            <?php endforeach; ?>
            <?php if ($cc): ?><span><?= $cc ?> comment<?= $cc > 1 ? 's' : '' ?></span><?php endif; ?>
        </div>
        <p style="color:var(--muted);font-size:14px"><?= excerpt($p['body'] ?? '') ?></p>
    </article>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
    <div class="pagination">
        <?php if ($pg > 1): ?>
        <a href="?<?= $tag_filter ? 'tag=' . urlencode($tag_filter) . '&' : '' ?>p=<?= $pg - 1 ?>">&laquo;</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $pages; $i++):
            $url = '?' . ($tag_filter ? 'tag=' . urlencode($tag_filter) . '&' : '') . 'p=' . $i;
        ?>
            <?php if ($i === $pg): ?>
                <span class="cur"><?= $i ?></span>
            <?php else: ?>
                <a href="<?= $url ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($pg < $pages): ?>
        <a href="?<?= $tag_filter ? 'tag=' . urlencode($tag_filter) . '&' : '' ?>p=<?= $pg + 1 ?>">&raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php else: ?>
<p class="empty"><?= $tag_filter ? 'No posts with this tag.' : 'No posts published.' ?></p>
<?php endif; ?>

<?php endif; ?>
</div>

<?php if ($tags): ?>
<footer class="site-footer">
<div class="wrap">
    <div class="footer-tags">
        <?php foreach ($tags as $t): ?>
        <a href="?tag=<?= urlencode($t) ?>" class="tag <?= $tag_filter === $t ? 'active' : '' ?>">#<?= h($t) ?></a>
        <?php endforeach; ?>
    </div>
</div>
</footer>
<?php endif; ?>

<?php endif; ?>

<script>
function togglePreview(){
    var ta=document.getElementById('editor'),pv=document.getElementById('preview');
    if(!pv||!ta)return;
    if(pv.style.display==='none'){pv.innerHTML=mdToHtml(ta.value);pv.style.display='block'}
    else{pv.style.display='none'}
}
function mdToHtml(t){
    t=t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    t=t.replace(/```(\w*)\n([\s\S]*?)```/g,'<pre><code>$2</code></pre>');
    t=t.replace(/`([^`]+)`/g,'<code>$1</code>');
    t=t.replace(/!\[([^\]]*)\]\(([^)]+)\)/g,'<img src="$2" alt="$1" style="max-width:100%;border-radius:6px">');
    t=t.replace(/\[([^\]]+)\]\(([^)]+)\)/g,'<a href="$2">$1</a>');
    t=t.replace(/^#### (.+)$/gm,'<h4>$1</h4>');
    t=t.replace(/^### (.+)$/gm,'<h3>$1</h3>');
    t=t.replace(/^## (.+)$/gm,'<h2>$1</h2>');
    t=t.replace(/^# (.+)$/gm,'<h1>$1</h1>');
    t=t.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>');
    t=t.replace(/\*(.+?)\*/g,'<em>$1</em>');
    t=t.replace(/^> (.+)$/gm,'<blockquote>$1</blockquote>');
    t=t.replace(/^[\-\*] (.+)$/gm,'<li>$1</li>');
    t=t.replace(/^---+$/gm,'<hr>');
    t=t.replace(/\n{2,}/g,'</p><p>');
    return '<p>'+t+'</p>';
}
function insertImg(path){
    var ta=document.getElementById('editor');
    if(!ta)return;
    var md='![]('+path+')';
    var pos=ta.selectionStart;
    ta.value=ta.value.slice(0,pos)+md+ta.value.slice(pos);
    ta.focus();
    ta.selectionStart=ta.selectionEnd=pos+md.length;
}
function copyMd(path){
    var md='![]('+path+')';
    navigator.clipboard.writeText(md);
    var el=event.target;
    var orig=el.textContent;
    el.textContent='Copied!';
    setTimeout(function(){el.textContent=orig},1500);
}
/* Chip-based tag input.
   Space/Enter/comma  → finalize current word as a chip.
   Backspace/Delete   → remove the last chip when input is empty.
   Existing-tag click → window.addTag(name) inserts a chip. */
(function(){
    var container=document.getElementById('tagChips');
    var input=document.getElementById('tagInputNew');
    var hidden=document.getElementById('tagsHidden');
    if(!container||!input||!hidden){window.addTag=function(){};return}

    // Parse incoming value tolerantly: split on whitespace OR comma, strip "#".
    var raw=(hidden.value||'').split(/[\s,]+/);
    var tags=[];
    for(var i=0;i<raw.length;i++){
        var t=raw[i].trim().replace(/^#+/,'');
        if(t&&tags.indexOf(t)===-1)tags.push(t);
    }

    function render(){
        var chips=container.querySelectorAll('.tag-chip');
        for(var j=0;j<chips.length;j++)chips[j].remove();
        tags.forEach(function(tag,idx){
            var chip=document.createElement('span');
            chip.className='tag-chip';
            chip.textContent=tag;
            var btn=document.createElement('button');
            btn.type='button';
            btn.textContent='×';
            btn.setAttribute('aria-label','remove '+tag);
            btn.addEventListener('click',function(){removeTag(idx)});
            chip.appendChild(btn);
            container.insertBefore(chip,input);
        });
        hidden.value=tags.join(',');
    }

    function addTag(t){
        t=(t||'').trim().replace(/^#+/,'').replace(/[\s,]+/g,'');
        if(!t)return;
        if(tags.indexOf(t)!==-1)return;
        tags.push(t);
        render();
    }

    function removeTag(i){
        if(i<0||i>=tags.length)return;
        tags.splice(i,1);
        render();
    }

    input.addEventListener('keydown',function(e){
        if(e.key===' '||e.key==='Enter'||e.key===','){
            e.preventDefault();
            if(input.value.trim()){addTag(input.value);input.value=''}
        }else if((e.key==='Backspace'||e.key==='Delete')&&!input.value&&tags.length){
            e.preventDefault();
            removeTag(tags.length-1);
        }
    });

    input.addEventListener('blur',function(){
        if(input.value.trim()){addTag(input.value);input.value=''}
    });

    container.addEventListener('click',function(e){
        if(e.target===container)input.focus();
    });

    window.addTag=addTag;
    render();
})();
</script>
<script src="/nodepulse-sw.js"></script>
</body>
</html>
