<?php
/**
 * NodePulse — Network Seed Status Page
 * PHP 8.*
 */
$home = $_SERVER['HOME'] ?? (($_SERVER['HOMEDRIVE'] ?? '') . ($_SERVER['HOMEPATH'] ?? ''));
$raw = @file_get_contents("$home/.nodepulse/node_id");
$node_id = $raw !== false ? htmlspecialchars(trim($raw)) : 'unknown';
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NodePulse Seed</title>
<style>body{background:#0d0f14;color:#e2e8f0;font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{text-align:center;padding:40px}h1{color:#10b981;font-size:1.4rem;margin-bottom:16px;letter-spacing:.08em}
.nid{font-family:monospace;color:#10b981;background:#151820;padding:6px 12px;border-radius:6px;font-size:1rem;display:inline-block;margin:8px 0}
p{color:#64748b;font-size:.85rem;margin:6px 0}</style>
</head><body><div class="box">
<h1>NODEPULSE SEED</h1>
<div class="nid"><?php echo $node_id; ?></div>
<p>Type: Network Seed</p>
<p id="countdown" style="color:#10b981;margin-top:20px;font-size:1rem"></p>
</div>
<script>
(function(){
  var root = location.protocol + '//' + location.hostname;
  var sec = 5;
  var el = document.getElementById('countdown');
  el.textContent = 'Redirecting in ' + sec + ' seconds...';
  var t = setInterval(function(){
    sec--;
    if(sec <= 0){ clearInterval(t); location.href = root; }
    else { el.textContent = 'Redirecting in ' + sec + ' second' + (sec === 1 ? '' : 's') + '...'; }
  }, 1000);
})();
</script>
</body></html>
