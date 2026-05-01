<?php
header('Content-Type: application/json');

const JSON_FILE = 'bookmarks.json';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'load':
        if (file_exists(JSON_FILE)) {
            echo json_encode(['bookmarks' => json_decode(file_get_contents(JSON_FILE), true) ?: []]);
        } else {
            echo json_encode(['bookmarks' => []]);
        }
        break;

    case 'save':
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['bookmarks']) && is_array($data['bookmarks'])) {
            file_put_contents(JSON_FILE, json_encode($data['bookmarks'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid data']);
        }
        break;

    case 'info':
        $data = json_decode(file_get_contents('php://input'), true);
        $url = trim($data['url'] ?? '');
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid URL']);
            exit;
        }

        $parsed = parse_url($url);
        $domain = $parsed['host'] ?? 'Website';
        $base = $parsed['scheme'] . '://' . $domain;
        $title = $domain;
        $image = 'placeholder.png';

        // Fetch page and extract title from <head>
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Bookmarks/1.0)',
        ]);
        $html = curl_exec($ch);
        $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
        curl_close($ch);

        if ($html && $ok) {
            // Extract title
            if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)) {
                $t = trim(strip_tags(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')));
                if ($t) $title = $t;
            }

        }

        $image = $base . '/favicon.ico';

        echo json_encode(['title' => $title, 'image' => $image]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
