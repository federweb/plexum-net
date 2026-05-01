<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NodePulse Monitor</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<a href="/" style="position:absolute;top:16px;left:16px;color:#8b949e;text-decoration:none;font-size:.85rem;transition:color .2s;z-index:10"
   onmouseover="this.style.color='#58a6ff'" onmouseout="this.style.color='#8b949e'">&larr; Dashboard</a>

<div id="app">
  <header>
    <div class="header-left">
      <span class="logo">◉</span>
      <h1>NodePulse Monitor</h1>
    </div>
    <div class="header-right">
      <span id="generated-at"></span>
      <button id="btn-refresh">↻ Refresh</button>
    </div>
  </header>

  <main id="main-content">
    <div class="loading-msg">Loading network...</div>
  </main>
</div>

<div id="modal-overlay" class="hidden">
  <div id="modal">
    <div id="modal-header">
      <div>
        <div id="modal-title">Detail</div>
        <div id="modal-subtitle"></div>
      </div>
      <div class="modal-header-actions">
        <button id="modal-close">✕</button>
      </div>
    </div>
    <div id="modal-body"></div>
  </div>
</div>

<script src="/nodepulse-sw.js"></script>
<script src="style.js"></script>
<script src="app.js"></script>

<?php include __DIR__ . '/../menu_panel.php'; ?>
</body>
</html>
