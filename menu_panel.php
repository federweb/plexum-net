<!-- Menu Panel -->
<div id="menu-panel">
  <a href="/" title="Home" class="mp-btn<?php if(basename(dirname($_SERVER['SCRIPT_NAME'])) === basename(dirname(__DIR__)) || $_SERVER['SCRIPT_NAME'] === '/index.php') echo ' mp-active'; ?>">&#x1F3E0;</a>
  <a href="/cli/" title="Shell" class="mp-btn<?php if(strpos($_SERVER['SCRIPT_NAME'], '/terminal/') !== false) echo ' mp-active'; ?>"><span style="font-family:monospace;font-size:13px;font-weight:700">&gt;_</span></a>
  <a href="/filemanager/" title="File Manager" class="mp-btn<?php if(strpos($_SERVER['SCRIPT_NAME'], '/filemanager/') !== false) echo ' mp-active'; ?>">&#x1F4C2;</a>
  <a href="/cloud/" title="Cloud" class="mp-btn<?php if(strpos($_SERVER['SCRIPT_NAME'], '/cloud/') !== false) echo ' mp-active'; ?>">&#x2B06;</a>
  <a href="/monitor/" title="Monitor" class="mp-btn<?php if(strpos($_SERVER['SCRIPT_NAME'], '/monitor/') !== false) echo ' mp-active'; ?>">&#x1F4CA;</a>
  <a href="/bookmarks/" title="Bookmarks" class="mp-btn<?php if(strpos($_SERVER['SCRIPT_NAME'], '/bookmarks/') !== false) echo ' mp-active'; ?>">&#x2B50;</a>
</div>
<style>
@media(max-width:768px){#menu-panel{display:none!important}}
#menu-panel{position:fixed;right:0;top:50%;transform:translateY(-50%);width:40px;display:flex;flex-direction:column;gap:2px;z-index:9999;background:#151820;border:1px solid #1e2330;border-right:none;border-radius:8px 0 0 8px;padding:4px 0;box-shadow:-2px 0 12px rgba(0,0,0,.4)}
.mp-btn{display:flex;align-items:center;justify-content:center;width:40px;height:40px;text-decoration:none;font-size:18px;color:#64748b;transition:color .2s,background .2s;border-radius:6px 0 0 6px}
.mp-btn:hover{color:#e2e8f0;background:rgba(255,255,255,.06);text-decoration:none!important}
.mp-btn.mp-active{color:#00c9a7;background:rgba(0,201,167,.1)}
</style>
