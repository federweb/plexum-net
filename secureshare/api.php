<?php
// Load configuration
$config = require __DIR__ . '/config.php';

// Security headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

// CORS headers from config whitelist
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (!empty($origin) && !empty($config['allowed_origins']) && in_array($origin, $config['allowed_origins'])) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Security utility class
class SecurityUtils {
    private static $secretKey = null;

    public static function generateSecureId($length = 32) {
        return bin2hex(random_bytes($length));
    }

    /**
     * Load or generate the secret key from a protected file.
     * The file is stored in data/ which is blocked by .htaccess.
     */
    public static function getSecretKey() {
        if (self::$secretKey !== null) {
            return self::$secretKey;
        }

        $secretFile = __DIR__ . '/data/.secret_key';

        if (file_exists($secretFile)) {
            self::$secretKey = trim(file_get_contents($secretFile));
        } else {
            // Auto-generate a cryptographically secure key on first run
            self::$secretKey = bin2hex(random_bytes(32));
            file_put_contents($secretFile, self::$secretKey, LOCK_EX);
            @chmod($secretFile, 0600);
        }

        if (empty(self::$secretKey) || strlen(self::$secretKey) < 32) {
            throw new Exception('Invalid or missing secret key');
        }

        return self::$secretKey;
    }

    public static function encryptContent($content, $key) {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($content, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new Exception('Encryption failed');
        }
        return base64_encode($iv . $encrypted);
    }

    public static function decryptContent($encryptedContent, $key) {
        $data = base64_decode($encryptedContent, true);
        if ($data === false || strlen($data) < 17) {
            return false;
        }
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    }

    public static function generateEncryptionKey($shareId) {
        $secret = self::getSecretKey();
        // true = raw binary 32 bytes, proper AES-256 key
        return hash('sha256', $shareId . $secret, true);
    }
}

// File-based rate limiter
class RateLimiter {
    private $dir;
    private $maxPerMinute;
    private $maxPerHour;

    public function __construct($config) {
        $this->dir = __DIR__ . '/data/ratelimit/';
        $this->maxPerMinute = $config['rate_limit']['max_requests_per_minute'];
        $this->maxPerHour = $config['rate_limit']['max_requests_per_hour'];

        if (!file_exists($this->dir)) {
            mkdir($this->dir, 0700, true);
        }
    }

    public function check() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        $hash = hash('sha256', $ip);
        $file = $this->dir . $hash . '.json';

        $now = time();
        $requests = [];

        if (file_exists($file)) {
            $raw = json_decode(file_get_contents($file), true);
            if (is_array($raw) && isset($raw['r'])) {
                $requests = $raw['r'];
            }
        }

        // Remove entries older than 1 hour
        $requests = array_values(array_filter($requests, function($t) use ($now) {
            return ($now - $t) < 3600;
        }));

        // Check per-minute limit
        $lastMinuteCount = count(array_filter($requests, function($t) use ($now) {
            return ($now - $t) < 60;
        }));

        if ($lastMinuteCount >= $this->maxPerMinute) {
            return false;
        }

        // Check per-hour limit
        if (count($requests) >= $this->maxPerHour) {
            return false;
        }

        // Record this request
        $requests[] = $now;
        file_put_contents($file, json_encode(['r' => $requests]), LOCK_EX);

        return true;
    }
}

// Data storage class
class DataStorage {
    private $dataDir;
    private $lockDir;

    public function __construct() {
        $this->dataDir = __DIR__ . '/data/';
        $this->lockDir = __DIR__ . '/data/locks/';

        if (!file_exists($this->dataDir)) {
            mkdir($this->dataDir, 0700, true);
        }
        if (!file_exists($this->lockDir)) {
            mkdir($this->lockDir, 0700, true);
        }
    }

    public function saveShare($shareId, $content, $maxViews, $expiration) {
        $lockFile = $this->lockDir . $shareId . '.lock';
        $fp = fopen($lockFile, 'w');

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        try {
            $encryptionKey = SecurityUtils::generateEncryptionKey($shareId);
            $encryptedContent = SecurityUtils::encryptContent($content, $encryptionKey);

            $data = [
                'content' => $encryptedContent,
                'maxViews' => (int)$maxViews,
                'currentViews' => 0,
                'created' => time(),
                'expires' => time() + $expiration
            ];

            $result = file_put_contents(
                $this->dataDir . $shareId . '.json',
                json_encode($data),
                LOCK_EX
            );

            flock($fp, LOCK_UN);
            fclose($fp);

            return $result !== false;
        } catch (Exception $e) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }
    }

    public function getShare($shareId) {
        $lockFile = $this->lockDir . $shareId . '.lock';
        $fp = fopen($lockFile, 'w');

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return null;
        }

        try {
            $filePath = $this->dataDir . $shareId . '.json';

            if (!file_exists($filePath)) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return null;
            }

            $jsonData = file_get_contents($filePath);
            $data = json_decode($jsonData, true);

            if (!$data) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return null;
            }

            // Check if expired
            if (time() > $data['expires']) {
                unlink($filePath);
                flock($fp, LOCK_UN);
                fclose($fp);
                return null;
            }

            // Check if max views reached
            if ($data['currentViews'] >= $data['maxViews']) {
                unlink($filePath);
                flock($fp, LOCK_UN);
                fclose($fp);
                return ['error' => 'Maximum views reached'];
            }

            // Increment view count
            $data['currentViews']++;

            // Decrypt content
            $encryptionKey = SecurityUtils::generateEncryptionKey($shareId);
            $content = SecurityUtils::decryptContent($data['content'], $encryptionKey);

            if ($content === false) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return null;
            }

            // Delete file if max views reached, otherwise save updated count
            if ($data['currentViews'] >= $data['maxViews']) {
                unlink($filePath);
            } else {
                file_put_contents($filePath, json_encode($data), LOCK_EX);
            }

            flock($fp, LOCK_UN);
            fclose($fp);

            return [
                'content' => $content,
                'viewsRemaining' => $data['maxViews'] - $data['currentViews']
            ];
        } catch (Exception $e) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return null;
        }
    }

    public function cleanup() {
        $files = glob($this->dataDir . '*.json');
        $now = time();

        foreach ($files as $file) {
            $shareId = basename($file, '.json');
            $lockFile = $this->lockDir . $shareId . '.lock';
            $fp = fopen($lockFile, 'w');

            // Non-blocking lock: skip files currently in use
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                fclose($fp);
                continue;
            }

            $data = json_decode(file_get_contents($file), true);
            if ($data && $now > $data['expires']) {
                unlink($file);
            }

            flock($fp, LOCK_UN);
            fclose($fp);
        }

        // Clean old rate limit files (older than 2 hours)
        $rateLimitDir = $this->dataDir . 'ratelimit/';
        if (is_dir($rateLimitDir)) {
            $rlFiles = glob($rateLimitDir . '*.json');
            foreach ($rlFiles as $rlFile) {
                if (filemtime($rlFile) < $now - 7200) {
                    @unlink($rlFile);
                }
            }
        }
    }

    public function countShares() {
        return count(glob($this->dataDir . '*.json'));
    }
}

// API handler
class APIHandler {
    private $storage;
    private $config;
    private $rateLimiter;

    public function __construct($config) {
        $this->storage = new DataStorage();
        $this->config = $config;

        if ($config['rate_limit']['enabled']) {
            $this->rateLimiter = new RateLimiter($config);
        }
    }

    public function handleRequest() {
        // Rate limiting check
        if ($this->rateLimiter && !$this->rateLimiter->check()) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
            exit;
        }

        // Probabilistic cleanup
        if (mt_rand(1, 100) <= $this->config['cleanup_probability']) {
            $this->storage->cleanup();
        }

        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || !isset($input['action'])) {
            return $this->sendResponse(false, 'Invalid request');
        }

        switch ($input['action']) {
            case 'create':
                return $this->createShare($input);
            case 'get':
                return $this->getShare($input);
            default:
                return $this->sendResponse(false, 'Invalid action');
        }
    }

    private function createShare($input) {
        if (!isset($input['content']) || !isset($input['maxViews'])) {
            return $this->sendResponse(false, 'Missing required fields');
        }

        // Store raw content - JS handles display sanitization via textContent
        $content = trim($input['content']);
        $maxViews = (int)$input['maxViews'];

        if (empty($content)) {
            return $this->sendResponse(false, 'Content cannot be empty');
        }

        if ($maxViews < 1 || $maxViews > $this->config['max_views_limit']) {
            return $this->sendResponse(false, 'Maximum views must be between 1 and ' . $this->config['max_views_limit']);
        }

        if (mb_strlen($content, 'UTF-8') > $this->config['max_content_length']) {
            return $this->sendResponse(false, 'Content too long (max ' . $this->config['max_content_length'] . ' characters)');
        }

        // Prevent storage exhaustion
        if ($this->storage->countShares() > $this->config['max_active_shares']) {
            return $this->sendResponse(false, 'Service temporarily at capacity. Please try again later.');
        }

        $shareId = SecurityUtils::generateSecureId();

        if ($this->storage->saveShare($shareId, $content, $maxViews, $this->config['share_expiration'])) {
            return $this->sendResponse(true, 'Share created successfully', ['shareId' => $shareId]);
        } else {
            return $this->sendResponse(false, 'Failed to create share');
        }
    }

    private function getShare($input) {
        if (!isset($input['shareId'])) {
            return $this->sendResponse(false, 'Missing share ID');
        }

        // Whitelist validation: only hex characters, exactly 64 chars
        $shareId = preg_replace('/[^a-f0-9]/', '', $input['shareId']);

        if (strlen($shareId) !== 64) {
            return $this->sendResponse(false, 'Invalid share ID');
        }

        $result = $this->storage->getShare($shareId);

        if ($result === null) {
            return $this->sendResponse(false, 'Share not found or expired');
        }

        if (isset($result['error'])) {
            return $this->sendResponse(false, $result['error']);
        }

        return $this->sendResponse(true, 'Share retrieved successfully', $result);
    }

    private function sendResponse($success, $message, $data = null) {
        $response = [
            'success' => $success,
            'message' => $message
        ];

        if ($data) {
            $response = array_merge($response, $data);
        }

        echo json_encode($response);
        exit;
    }
}

// Initialize and handle request
try {
    $api = new APIHandler($config);
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
}