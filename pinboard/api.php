<?php
/**
 * Pinboard API
 *
 * All sensitive fields (title, text) are encrypted at rest with AES-256-CBC
 * using a per-pin key derived from a local secret + pin id. The on-disk
 * pins.json therefore cannot be used to leak content or infrastructure info
 * even if it is exfiltrated.
 */

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (!empty($origin) && !empty($config['allowed_origins']) && in_array($origin, $config['allowed_origins'])) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

class Crypto {
    private static $secret = null;

    public static function getSecretKey() {
        if (self::$secret !== null) return self::$secret;

        $file = __DIR__ . '/data/.secret_key';
        if (!is_dir(__DIR__ . '/data')) {
            mkdir(__DIR__ . '/data', 0700, true);
        }

        if (file_exists($file)) {
            self::$secret = trim(file_get_contents($file));
        } else {
            self::$secret = bin2hex(random_bytes(32));
            file_put_contents($file, self::$secret, LOCK_EX);
            @chmod($file, 0600);
        }

        if (empty(self::$secret) || strlen(self::$secret) < 32) {
            throw new Exception('Invalid secret key');
        }
        return self::$secret;
    }

    public static function deriveKey($pinId) {
        return hash('sha256', $pinId . self::getSecretKey(), true);
    }

    public static function encrypt($plain, $key) {
        $iv = random_bytes(16);
        $enc = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($enc === false) throw new Exception('Encryption failed');
        return base64_encode($iv . $enc);
    }

    public static function decrypt($encoded, $key) {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) return false;
        $iv = substr($raw, 0, 16);
        $ct = substr($raw, 16);
        return openssl_decrypt($ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    }

    public static function randomId($bytes = 16) {
        return bin2hex(random_bytes($bytes));
    }
}

class RateLimiter {
    private $dir;
    private $perMin;
    private $perHour;

    public function __construct($config) {
        $this->dir     = __DIR__ . '/data/ratelimit/';
        $this->perMin  = $config['rate_limit']['max_requests_per_minute'];
        $this->perHour = $config['rate_limit']['max_requests_per_hour'];
        if (!is_dir($this->dir)) mkdir($this->dir, 0700, true);
    }

    public function check() {
        $ip   = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        $file = $this->dir . hash('sha256', $ip) . '.json';
        $now  = time();

        $reqs = [];
        if (file_exists($file)) {
            $raw = json_decode(file_get_contents($file), true);
            if (is_array($raw) && isset($raw['r'])) $reqs = $raw['r'];
        }

        $reqs = array_values(array_filter($reqs, function($t) use ($now) {
            return ($now - $t) < 3600;
        }));

        $lastMin = count(array_filter($reqs, function($t) use ($now) {
            return ($now - $t) < 60;
        }));

        if ($lastMin >= $this->perMin)      return false;
        if (count($reqs) >= $this->perHour) return false;

        $reqs[] = $now;
        file_put_contents($file, json_encode(['r' => $reqs]), LOCK_EX);
        return true;
    }
}

class PinStore {
    private $file;
    private $lockFile;

    public function __construct() {
        $dir = __DIR__ . '/data/';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $this->file     = $dir . 'pins.json';
        $this->lockFile = $dir . '.pins.lock';
    }

    private function openLock() {
        $fp = fopen($this->lockFile, 'c');
        if (!$fp) throw new Exception('Cannot open lock');
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new Exception('Cannot lock');
        }
        return $fp;
    }

    private function releaseLock($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function readAll() {
        if (!file_exists($this->file)) return [];
        $raw  = file_get_contents($this->file);
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['pins']) || !is_array($data['pins'])) return [];
        return $data['pins'];
    }

    private function writeAll($pins) {
        $tmp = $this->file . '.tmp';
        $ok  = file_put_contents($tmp, json_encode(['pins' => array_values($pins)]), LOCK_EX);
        if ($ok === false) return false;
        return rename($tmp, $this->file);
    }

    public function cleanupExpired() {
        $fp      = $this->openLock();
        $now     = time();
        $pins    = $this->readAll();
        $kept    = [];
        $removed = 0;
        foreach ($pins as $p) {
            if (isset($p['expires']) && $p['expires'] > $now) {
                $kept[] = $p;
            } else {
                $removed++;
            }
        }
        if ($removed > 0) {
            $this->writeAll($kept);
        }
        $this->releaseLock($fp);
        return $removed;
    }

    public function listActive($limit) {
        $fp   = $this->openLock();
        $now  = time();
        $pins = $this->readAll();
        $out  = [];
        foreach ($pins as $p) {
            if (!isset($p['expires']) || $p['expires'] <= $now) continue;
            $key   = Crypto::deriveKey($p['id']);
            $title = Crypto::decrypt($p['title_enc'], $key);
            $text  = Crypto::decrypt($p['text_enc'],  $key);
            if ($title === false || $text === false) continue;
            $out[] = [
                'id'      => $p['id'],
                'title'   => $title,
                'text'    => $text,
                'created' => $p['created'],
                'expires' => $p['expires'],
            ];
        }
        $this->releaseLock($fp);

        usort($out, function($a, $b) { return $b['created'] - $a['created']; });
        if ($limit > 0 && count($out) > $limit) {
            $out = array_slice($out, 0, $limit);
        }
        return $out;
    }

    public function count() {
        $fp   = $this->openLock();
        $pins = $this->readAll();
        $c    = count($pins);
        $this->releaseLock($fp);
        return $c;
    }

    public function addPin($title, $text, $ttlSeconds) {
        $fp   = $this->openLock();
        $pins = $this->readAll();

        $now  = time();
        // Purge expired on write as a second safety layer.
        $pins = array_values(array_filter($pins, function($p) use ($now) {
            return isset($p['expires']) && $p['expires'] > $now;
        }));

        $id  = Crypto::randomId(16);
        $key = Crypto::deriveKey($id);

        $pins[] = [
            'id'        => $id,
            'title_enc' => Crypto::encrypt($title, $key),
            'text_enc'  => Crypto::encrypt($text,  $key),
            'created'   => $now,
            'expires'   => $now + (int)$ttlSeconds,
        ];

        $ok = $this->writeAll($pins);
        $this->releaseLock($fp);

        return $ok ? $id : false;
    }
}

class API {
    private $config;
    private $store;
    private $rl;

    public function __construct($config) {
        $this->config = $config;
        $this->store  = new PinStore();
        if (!empty($config['rate_limit']['enabled'])) {
            $this->rl = new RateLimiter($config);
        }
    }

    public function handle() {
        if ($this->rl && !$this->rl->check()) {
            http_response_code(429);
            $this->out(false, 'Too many requests. Try again later.');
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['action'])) {
            $this->out(false, 'Invalid request');
        }

        switch ($input['action']) {
            case 'list':   $this->doList();         return;
            case 'create': $this->doCreate($input); return;
            default:       $this->out(false, 'Unknown action');
        }
    }

    private function doList() {
        // Requirement: expired pins are destroyed on the first access of a new
        // user, BEFORE returning the still-valid ones.
        $removed = $this->store->cleanupExpired();
        $pins    = $this->store->listActive($this->config['default_list_limit']);
        $this->out(true, 'ok', [
            'pins'    => $pins,
            'removed' => $removed,
            'now'     => time(),
        ]);
    }

    private function doCreate($input) {
        $title = isset($input['title']) ? trim((string)$input['title']) : '';
        $text  = isset($input['text'])  ? trim((string)$input['text'])  : '';
        $ttl   = isset($input['expiration']) ? (int)$input['expiration'] : 0;

        if ($title === '') $this->out(false, 'Title is required');
        if ($text  === '') $this->out(false, 'Text is required');

        $titleLen = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
        $textLen  = function_exists('mb_strlen') ? mb_strlen($text,  'UTF-8') : strlen($text);

        if ($titleLen > $this->config['max_title_length']) {
            $this->out(false, 'Title too long (max ' . $this->config['max_title_length'] . ')');
        }
        if ($textLen > $this->config['max_text_length']) {
            $this->out(false, 'Text too long (max ' . $this->config['max_text_length'] . ')');
        }
        if (!in_array($ttl, $this->config['allowed_expirations'], true)) {
            $this->out(false, 'Invalid expiration');
        }

        if ($this->store->count() >= $this->config['max_active_pins']) {
            $this->store->cleanupExpired();
            if ($this->store->count() >= $this->config['max_active_pins']) {
                $this->out(false, 'Pinboard is full, try again later.');
            }
        }

        $id = $this->store->addPin($title, $text, $ttl);
        if ($id === false) {
            $this->out(false, 'Unable to save pin');
        }
        $this->out(true, 'ok', ['id' => $id]);
    }

    private function out($ok, $msg, $extra = null) {
        $resp = ['success' => (bool)$ok, 'message' => $msg];
        if (is_array($extra)) $resp = array_merge($resp, $extra);
        echo json_encode($resp);
        exit;
    }
}

try {
    (new API($config))->handle();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
