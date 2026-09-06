<?php
// NodePulse Desktop — redirect to noVNC.
// Uses an absolute Location header (PHP only rewrites relative URLs to http://host:8080/...).
// X-Forwarded-Proto from Cloudflare Tunnel gives the real scheme (https).
// HTTP_HOST is the public hostname without port; strtok strips it if present.
$proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'https';
$host  = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$host  = strtok($host, ':'); // strip :8080 (nginx backend port)
$qs    = 'path=desktop/websockify&autoconnect=1&resize=scale&reconnect=1';
header("Location: {$proto}://{$host}/desktop/vnc.html?{$qs}", true, 302);
exit;
