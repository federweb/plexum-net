<?php
require_once __DIR__ . '/../auth_gate.php';
$uploadDir = __DIR__ . '/uploads/';
$maxFileSize = 900 * 1024 * 1024;
$timeout = 1800;

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

function formatBytes($bytes) {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

function safeName($name) {
    $name = basename($name);
    $name = preg_replace('/[^\w\.\-]/', '_', $name);
    return (!empty($name) && $name !== '.' && $name !== '..') ? $name : 'file_' . time();
}

function uniquePath($dir, $name) {
    $dest = $dir . $name;
    if (!file_exists($dest)) return [$name, $dest];
    $info = pathinfo($name);
    $base = $info['filename'];
    $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
    $i = 1;
    while (file_exists($dir . $base . "_$i" . $ext)) $i++;
    $name = $base . "_$i" . $ext;
    return [$name, $dir . $name];
}

// ============================================================
// API: Progress poll
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'progress' && isset($_GET['file'])) {
    header('Content-Type: application/json');
    $f = $uploadDir . safeName($_GET['file']);
    echo json_encode(file_exists($f) ? ['size' => filesize($f), 'exists' => true] : ['size' => 0, 'exists' => false]);
    exit;
}

// ============================================================
// API: Download from URL (cURL)
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    header('Content-Type: application/json');

    $url = $_POST['url'] ?? '';
    $customName = trim($_POST['custom_name'] ?? '');

    if (empty($url)) { echo json_encode(['error' => 'URL is required']); exit; }

    $parsed = parse_url($url);
    $scheme = strtolower($parsed['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https', 'ftp'])) {
        echo json_encode(['error' => 'Invalid URL']);
        exit;
    }

    $rawName = !empty($customName) ? $customName : basename($parsed['path'] ?? 'download_' . time());
    [$fileName, $dest] = uniquePath($uploadDir, safeName($rawName));

    set_time_limit($timeout + 60);

    $ch = curl_init($url);
    $fp = fopen($dest, 'wb');
    if (!$fp) { echo json_encode(['error' => 'Cannot create file']); exit; }

    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_BUFFERSIZE => 262144,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_LOW_SPEED_LIMIT => 1024,
        CURLOPT_LOW_SPEED_TIME => 60,
    ]);

    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $code >= 400) {
        @unlink($dest);
        echo json_encode(['error' => 'Download failed: ' . ($err ?: "HTTP $code")]);
        exit;
    }

    $size = filesize($dest);
    if ($size === 0) {
        @unlink($dest);
        echo json_encode(['error' => 'Downloaded file is empty']);
        exit;
    }

    echo json_encode(['success' => true, 'file' => $fileName, 'size' => $size, 'sizeFormatted' => formatBytes($size)]);
    exit;
}

// ============================================================
// API: Upload chunk
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'upload_chunk') {
    header('Content-Type: application/json');
    set_time_limit(300);

    $chunkIndex = intval($_POST['chunkIndex'] ?? 0);
    $totalChunks = intval($_POST['totalChunks'] ?? 1);
    $fileName = safeName($_POST['fileName'] ?? 'upload_' . time());
    $uploadId = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['uploadId'] ?? uniqid());

    if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => "Chunk $chunkIndex failed"]);
        exit;
    }

    $tmpDir = $uploadDir . '.tmp_' . $uploadId . '/';
    if (!file_exists($tmpDir)) mkdir($tmpDir, 0777, true);

    $chunkPath = $tmpDir . 'chunk_' . str_pad($chunkIndex, 6, '0', STR_PAD_LEFT);
    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkPath)) {
        echo json_encode(['error' => "Cannot save chunk $chunkIndex"]);
        exit;
    }

    echo json_encode(['success' => true, 'chunk' => $chunkIndex]);
    exit;
}

// ============================================================
// API: Upload complete - reassemble
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'upload_complete') {
    header('Content-Type: application/json');
    set_time_limit(600);

    $fileName = safeName($_POST['fileName'] ?? 'upload_' . time());
    $totalChunks = intval($_POST['totalChunks'] ?? 1);
    $uploadId = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['uploadId'] ?? '');

    $tmpDir = $uploadDir . '.tmp_' . $uploadId . '/';
    [$fileName, $dest] = uniquePath($uploadDir, $fileName);

    $fp = fopen($dest, 'wb');
    if (!$fp) { echo json_encode(['error' => 'Cannot create file']); exit; }

    $missing = [];
    for ($c = 0; $c < $totalChunks; $c++) {
        $cp = $tmpDir . 'chunk_' . str_pad($c, 6, '0', STR_PAD_LEFT);
        if (!file_exists($cp)) { $missing[] = $c; continue; }
        fwrite($fp, file_get_contents($cp));
    }
    fclose($fp);

    // Cleanup
    if (is_dir($tmpDir)) {
        foreach (glob($tmpDir . '*') as $tf) @unlink($tf);
        @rmdir($tmpDir);
    }

    if (!empty($missing)) {
        @unlink($dest);
        echo json_encode(['error' => 'Missing chunks: ' . implode(', ', $missing)]);
        exit;
    }

    $size = filesize($dest);
    echo json_encode(['success' => true, 'file' => $fileName, 'size' => $size, 'sizeFormatted' => formatBytes($size)]);
    exit;
}

// ============================================================
// API: Delete file
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['file'])) {
    header('Content-Type: application/json');
    $path = $uploadDir . safeName($_GET['file']);
    if (file_exists($path)) { @unlink($path); echo json_encode(['success' => true]); }
    else echo json_encode(['error' => 'File not found']);
    exit;
}

// ============================================================
// Page data
// ============================================================
$files = [];
if (is_dir($uploadDir)) {
    foreach (scandir($uploadDir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $uploadDir . $item;
        if (is_file($p)) $files[] = ['name' => $item, 'size' => filesize($p), 'date' => filemtime($p)];
    }
    usort($files, fn($a, $b) => $b['date'] - $a['date']);
}
$diskFree = @disk_free_space($uploadDir) ?: 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>File Manager</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0f1923;color:#e0e0e0;min-height:100vh;padding:1.5rem}
.w{max-width:900px;margin:0 auto}
h1{color:#4fc3f7;text-align:center;margin-bottom:.3rem;font-size:1.8rem}
.sub{text-align:center;color:#78909c;font-size:.85rem;margin-bottom:1.5rem}
.card{background:#1a2733;border:1px solid #263545;border-radius:10px;padding:1.5rem;margin-bottom:1.5rem}
.card h2{color:#4fc3f7;font-size:1.1rem;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid #263545}
.row{display:flex;gap:.8rem;margin-bottom:.8rem}
.row input[type=text],.row input[type=url]{flex:1}
input[type=url],input[type=text],select{width:100%;padding:.7rem 1rem;background:#0f1923;border:1px solid #37474f;border-radius:6px;color:#e0e0e0;font-size:.95rem}
input:focus,select:focus{outline:none;border-color:#4fc3f7}
.btn{padding:.7rem 1.5rem;border:none;border-radius:6px;font-size:.95rem;cursor:pointer;font-weight:600;white-space:nowrap;transition:all .2s}
.btn-p{background:#1565c0;color:#fff;width:100%}.btn-p:hover{background:#1976d2}
.btn-s{padding:.4rem .8rem;font-size:.8rem}
.btn-dl{background:#2e7d32;color:#fff}.btn-dl:hover{background:#388e3c}
.btn-rm{background:#c62828;color:#fff}.btn-rm:hover{background:#d32f2f}
.btn-up{background:#2e7d32;color:#fff;width:100%}.btn-up:hover{background:#388e3c}
.btn-x{background:#37474f;color:#b0bec5;margin-left:.5rem;padding:.3rem .6rem;font-size:.75rem;border:none;border-radius:6px;cursor:pointer}.btn-x:hover{background:#546e7a}
.pw{display:none;margin-top:1rem}
.po{background:#0f1923;border-radius:8px;height:28px;overflow:hidden;position:relative;border:1px solid #263545}
.pi{height:100%;border-radius:8px;transition:width .3s;width:0}
.pi-bl{background:linear-gradient(90deg,#1565c0,#4fc3f7)}
.pi-gr{background:linear-gradient(90deg,#2e7d32,#66bb6a)}
.pt{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:.8rem;font-weight:600;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.5)}
.pd{display:flex;justify-content:space-between;margin-top:.4rem;font-size:.78rem;color:#78909c}
.msg{padding:.8rem 1rem;border-radius:6px;margin-top:1rem;font-size:.9rem}
.msg-ok{background:#1b5e20;color:#a5d6a7;border:1px solid #2e7d32}
.msg-err{background:#b71c1c;color:#ef9a9a;border:1px solid #c62828}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:.6rem .8rem;color:#78909c;font-size:.78rem;text-transform:uppercase;border-bottom:1px solid #263545}
td{padding:.6rem .8rem;border-bottom:1px solid #1e3040;font-size:.88rem}
tr:hover td{background:#1e3040}
.fn{color:#4fc3f7;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fs{color:#b0bec5;white-space:nowrap}
.fd{color:#78909c;white-space:nowrap}
.fa{display:flex;gap:.4rem;white-space:nowrap}
.empty{text-align:center;padding:2rem;color:#546e7a}
.info{display:flex;gap:1rem;flex-wrap:wrap;font-size:.78rem;color:#78909c;margin-bottom:1rem}
.tabs{display:flex;margin-bottom:0}
.tab{flex:1;padding:.7rem;text-align:center;cursor:pointer;background:#0f1923;color:#78909c;border:1px solid #263545;font-weight:600;font-size:.9rem;transition:all .2s}
.tab:first-child{border-radius:10px 0 0 0;border-right:none}
.tab:last-child{border-radius:0 10px 0 0;border-left:none}
.tab.on{background:#1a2733;color:#4fc3f7;border-bottom-color:#1a2733}
.tc{display:none}.tc.on{display:block}
.ct{border-radius:0 0 10px 10px;border-top:none}
.dz{border:2px dashed #37474f;border-radius:8px;padding:2rem;text-align:center;cursor:pointer;transition:all .3s;background:#0f1923;margin-bottom:.8rem}
.dz:hover,.dz.over{border-color:#4fc3f7;background:#1e3040}
.dz-i{font-size:2.5rem;margin-bottom:.5rem;color:#37474f;transition:color .3s}
.dz:hover .dz-i,.dz.over .dz-i{color:#4fc3f7}
.dz-t{color:#78909c;font-size:.9rem}.dz-t strong{color:#4fc3f7}
.dz-h{color:#546e7a;font-size:.75rem;margin-top:.3rem}
.ufi{display:flex;align-items:center;justify-content:space-between;background:#0f1923;border:1px solid #263545;border-radius:6px;padding:.6rem 1rem;margin-bottom:.8rem;font-size:.88rem}
.ufn{color:#4fc3f7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;margin-right:1rem}
.ufs{color:#78909c;white-space:nowrap}
@media(max-width:600px){.row{flex-direction:column}.fd{display:none}.dash-back{display:none!important}}
</style>
</head>
<body>
<a href="/" class="dash-back" style="position:absolute;top:16px;left:16px;color:#78909c;text-decoration:none;font-size:.85rem;transition:color .2s;z-index:10"
   onmouseover="this.style.color='#4fc3f7'" onmouseout="this.style.color='#78909c'">&larr; Dashboard</a>
<div class="w">
<h1>Cloud</h1>
<div class="sub">download &amp; upload &mdash; files up to 900 MB</div>
<div class="info">
<span>Disk: <?= $diskFree > 0 ? formatBytes($diskFree) : 'N/A' ?></span>
<span>Files: <?= count($files) ?></span>
</div>

<div class="tabs">
<div class="tab on" onclick="stab('ul')">Upload from local device</div>
<div class="tab" onclick="stab('dl')">Upload from a web url</div>
</div>

<div class="card ct tc on" id="t-ul">
<div class="dz" id="dz">
<div class="dz-i">&#8682;</div>
<div class="dz-t">Drag files here or <strong>click to browse</strong></div>
<div class="dz-h">Max 900 MB per file &mdash; any file type &mdash; multiple selection</div>
<input type="file" id="fI" style="display:none" multiple>
</div>
<div id="uList"></div>
<button class="btn btn-up" id="uB" onclick="doUp()" style="display:none">Upload Files</button>
<div class="pw" id="uPW"><div class="po"><div class="pi pi-gr" id="uPI"></div><div class="pt" id="uPT">0%</div></div>
<div class="pd"><span id="uPS"></span><span id="uPC"></span><span id="uPV"></span></div></div>
<div id="uM"></div>
</div>

<div class="card ct tc" id="t-dl">
<form id="dlF">
<div class="row"><input type="url" id="dlU" name="url" placeholder="https://example.com/file.zip" required></div>
<div class="row"><input type="text" id="dlN" name="custom_name" placeholder="File name (optional)"></div>
<button type="submit" class="btn btn-p" id="dlB">Start Download</button>
</form>
<div class="pw" id="dlPW"><div class="po"><div class="pi pi-bl" id="dlPI"></div><div class="pt" id="dlPT">0%</div></div>
<div class="pd"><span id="dlPS"></span><span id="dlPV"></span></div></div>
<div id="dlM"></div>
</div>

<div class="card">
<h2>Files (<?= count($files) ?>)</h2>
<?php if (empty($files)): ?>
<div class="empty">No files yet</div>
<?php else: ?>
<table><thead><tr><th>Name</th><th>Size</th><th>Date</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($files as $f): ?>
<tr id="r-<?= md5($f['name']) ?>">
<td class="fn" title="<?= htmlspecialchars($f['name']) ?>"><?= htmlspecialchars($f['name']) ?></td>
<td class="fs"><?= formatBytes($f['size']) ?></td>
<td class="fd"><?= date('Y-m-d H:i', $f['date']) ?></td>
<td class="fa">
<a href="uploads/<?= rawurlencode($f['name']) ?>" download class="btn btn-s btn-dl">Download</a>
<button class="btn btn-s btn-rm" onclick="del('<?= htmlspecialchars($f['name'], ENT_QUOTES) ?>','<?= md5($f['name']) ?>')">Delete</button>
</td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div>
</div>

<script>
function fmt(b){if(b<=0)return'0 B';const u=['B','KB','MB','GB'];const i=Math.floor(Math.log(b)/Math.log(1024));return(b/Math.pow(1024,i)).toFixed(1)+' '+u[i]}
function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML}
function $(id){return document.getElementById(id)}

// Tabs
function stab(t){
document.querySelectorAll('.tab').forEach((e,i)=>e.classList.toggle('on',t==='ul'?i===0:i===1));
$('t-ul').classList.toggle('on',t==='ul');
$('t-dl').classList.toggle('on',t==='dl');
}

// Download
$('dlF').addEventListener('submit',async function(e){
e.preventDefault();
const url=$('dlU').value.trim();if(!url)return;
const b=$('dlB');b.disabled=true;b.textContent='Downloading...';
$('dlM').innerHTML='';$('dlPW').style.display='block';
$('dlPI').style.width='0%';$('dlPT').textContent='Starting...';$('dlPS').textContent='';$('dlPV').textContent='';

const fd=new FormData(this);
const fn=$('dlN').value.trim()||url.split('/').pop().split('?')[0]||'download';
let ls=0,lt=Date.now();
const poll=setInterval(async()=>{try{const r=await fetch('?action=progress&file='+encodeURIComponent(fn));const d=await r.json();if(d.exists&&d.size>0){const n=Date.now(),el=(n-lt)/1000;if(el>0&&ls>0)$('dlPV').textContent=fmt((d.size-ls)/el)+'/s';ls=d.size;lt=n;$('dlPS').textContent=fmt(d.size);$('dlPI').style.width='50%';$('dlPT').textContent=fmt(d.size)+' downloaded...';}}catch(e){}},1500);

try{
const r=await fetch('?action=download',{method:'POST',body:fd});const res=await r.json();clearInterval(poll);
if(res.success){$('dlPI').style.width='100%';$('dlPT').textContent='100% - Complete!';$('dlPS').textContent=res.sizeFormatted;$('dlPV').textContent='';
$('dlM').innerHTML='<div class="msg msg-ok">Downloaded: <strong>'+esc(res.file)+'</strong> ('+res.sizeFormatted+')</div>';
setTimeout(()=>location.reload(),2000);
}else{$('dlPI').style.width='0%';$('dlPT').textContent='Error';$('dlM').innerHTML='<div class="msg msg-err">'+esc(res.error)+'</div>';}
}catch(err){clearInterval(poll);$('dlM').innerHTML='<div class="msg msg-err">Network error: '+esc(err.message)+'</div>';}
b.disabled=false;b.textContent='Start Download';
});

// Delete
async function del(name,hash){
if(!confirm('Delete '+name+'?'))return;
try{const r=await fetch('?action=delete&file='+encodeURIComponent(name));const d=await r.json();
if(d.success){const row=$('r-'+hash);if(row)row.remove();}else alert(d.error||'Error');
}catch(e){alert(e.message);}
}

// Upload (multi-file)
const CS=5*1024*1024;let files=[];
const dz=$('dz'),fI=$('fI');
dz.addEventListener('click',()=>fI.click());
dz.addEventListener('dragover',e=>{e.preventDefault();dz.classList.add('over')});
dz.addEventListener('dragleave',()=>dz.classList.remove('over'));
dz.addEventListener('drop',e=>{e.preventDefault();dz.classList.remove('over');if(e.dataTransfer.files.length>0)addFiles(e.dataTransfer.files)});
fI.addEventListener('change',()=>{if(fI.files.length>0)addFiles(fI.files)});

function addFiles(fl){
let err=[];
for(let i=0;i<fl.length;i++){
if(fl[i].size>900*1024*1024){err.push(fl[i].name);continue}
if(!files.some(f=>f.name===fl[i].name&&f.size===fl[i].size))files.push(fl[i]);
}
if(err.length)$('uM').innerHTML='<div class="msg msg-err">Too large (max 900 MB): '+err.map(esc).join(', ')+'</div>';
else $('uM').innerHTML='';
renderList();
}
function removeFile(idx){files.splice(idx,1);renderList();}
function renderList(){
const el=$('uList');
if(files.length===0){el.innerHTML='';$('uB').style.display='none';dz.style.display='block';return}
let h='';const total=files.reduce((s,f)=>s+f.size,0);
for(let i=0;i<files.length;i++)h+='<div class="ufi"><span class="ufn" title="'+esc(files[i].name)+'">'+esc(files[i].name)+'</span><span class="ufs">'+fmt(files[i].size)+'</span><button class="btn-x" onclick="removeFile('+i+')">&#10005;</button></div>';
h+='<div style="text-align:right;font-size:.78rem;color:#78909c;margin-bottom:.5rem">'+files.length+' file'+(files.length>1?'s':'')+' &mdash; '+fmt(total)+' total</div>';
el.innerHTML=h;
$('uB').style.display='block';$('uB').textContent='Upload '+files.length+' File'+(files.length>1?'s':'');
}

async function doUp(){
if(!files.length)return;
const b=$('uB');b.disabled=true;
$('uPW').style.display='block';$('uM').innerHTML='';
const totalSize=files.reduce((s,f)=>s+f.size,0);
let globalUp=0,ok=0,fail=[];const st=Date.now();

for(let fi=0;fi<files.length;fi++){
const f=files[fi],tc=Math.ceil(f.size/CS),uid=Date.now().toString(36)+Math.random().toString(36).substr(2,6);
b.textContent='Uploading '+(fi+1)+'/'+files.length+'...';
let fUp=0;
try{
for(let i=0;i<tc;i++){
const s=i*CS,e=Math.min(s+CS,f.size),ch=f.slice(s,e);
const fd=new FormData();fd.append('chunk',ch);fd.append('chunkIndex',i);fd.append('totalChunks',tc);fd.append('fileName',f.name);fd.append('uploadId',uid);
const r=await fetch('?action=upload_chunk',{method:'POST',body:fd});const res=await r.json();
if(res.error)throw new Error(res.error);
fUp+=(e-s);globalUp+=(e-s);
const pct=Math.round(globalUp/totalSize*100),el=(Date.now()-st)/1000,sp=el>0?globalUp/el:0;
$('uPI').style.width=pct+'%';$('uPT').textContent=pct+'%';
$('uPS').textContent=fmt(globalUp)+' / '+fmt(totalSize);$('uPC').textContent='File '+(fi+1)+'/'+files.length;$('uPV').textContent=fmt(sp)+'/s';
}
$('uPT').textContent='Assembling '+(fi+1)+'/'+files.length+'...';
const cd=new FormData();cd.append('fileName',f.name);cd.append('totalChunks',tc);cd.append('uploadId',uid);
const r=await fetch('?action=upload_complete',{method:'POST',body:cd});const res=await r.json();
if(res.success)ok++;else throw new Error(res.error);
}catch(err){fail.push(f.name+': '+err.message);globalUp+=(f.size-fUp);}
}

$('uPI').style.width='100%';$('uPT').textContent='Done';$('uPC').textContent='';$('uPV').textContent='';
let msg='';
if(ok>0)msg+='<div class="msg msg-ok">Uploaded '+ok+' file'+(ok>1?'s':'')+'</div>';
if(fail.length)msg+='<div class="msg msg-err">Failed: '+fail.map(esc).join('<br>')+'</div>';
$('uM').innerHTML=msg;
b.disabled=false;b.textContent='Upload Files';
if(ok>0)setTimeout(()=>location.reload(),2000);
}
</script>
<script src="/nodepulse-sw.js"></script>
<?php include __DIR__ . '/../menu_panel.php'; ?>
</body>
</html>
