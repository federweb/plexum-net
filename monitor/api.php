<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$base = dirname(__FILE__) . '/../nodepulse/';

function np_read($path) {
    if (!file_exists($path)) return null;
    $raw = file_get_contents($path);
    if ($raw === false) return null;
    return json_decode($raw, true);
}

echo json_encode(array(
    'seeds_origin'  => np_read($base . 'seeds_origin.json'),
    'seeds_domain'  => np_read($base . 'seeds_domain.json'),
    'seeds_network' => np_read($base . 'seeds_network.json'),
    'peers_online'  => np_read($base . 'peers_online.json'),
    'directory'     => np_read($base . 'directory.json'),
    'node_config'   => np_read($base . 'node_config.json'),
    'generated_at'  => gmdate('Y-m-d\TH:i:s\Z'),
));
