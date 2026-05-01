<?php
/**
 * NodePulse — Downloads directory index
 * Lists available downloads with their signatures.
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$files = array();
$dir = __DIR__;

if (file_exists($dir . '/apps.zip')) {
    $entry = array(
        'file' => 'apps.zip',
        'size' => filesize($dir . '/apps.zip'),
        'modified' => date('Y-m-d\TH:i:s\Z', filemtime($dir . '/apps.zip')),
    );
    if (file_exists($dir . '/apps.zip.sig')) {
        $entry['signature_file'] = 'apps.zip.sig';
    }
    $files[] = $entry;
}

echo json_encode(array('files' => $files), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
