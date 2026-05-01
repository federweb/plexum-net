<?php
/**
 * Cleanup script for Secure Link Share
 * Run this periodically via cron job to clean up expired files
 * Usage: php cleanup.php
 */

// Include configuration
$config = include 'config.php';

class CleanupManager {
    private $dataDir;
    private $lockDir;
    
    public function __construct() {
        $this->dataDir = __DIR__ . '/data/';
        $this->lockDir = __DIR__ . '/data/locks/';
    }
    
    public function runCleanup() {
        $cleaned = 0;
        $errors = 0;
        
        echo "Starting cleanup process...\n";
        
        if (!is_dir($this->dataDir)) {
            echo "Data directory not found: {$this->dataDir}\n";
            return;
        }
        
        $files = glob($this->dataDir . '*.json');
        $now = time();
        
        foreach ($files as $file) {
            try {
                $data = json_decode(file_get_contents($file), true);
                
                if ($data && isset($data['expires']) && $now > $data['expires']) {
                    if (unlink($file)) {
                        $cleaned++;
                        echo "Cleaned: " . basename($file) . "\n";
                    } else {
                        $errors++;
                        echo "Error cleaning: " . basename($file) . "\n";
                    }
                } elseif ($data && isset($data['currentViews'], $data['maxViews']) && 
                         $data['currentViews'] >= $data['maxViews']) {
                    if (unlink($file)) {
                        $cleaned++;
                        echo "Cleaned (max views reached): " . basename($file) . "\n";
                    } else {
                        $errors++;
                        echo "Error cleaning: " . basename($file) . "\n";
                    }
                }
            } catch (Exception $e) {
                $errors++;
                echo "Error processing file " . basename($file) . ": " . $e->getMessage() . "\n";
            }
        }
        
        // Clean up empty lock files
        $lockFiles = glob($this->lockDir . '*.lock');
        foreach ($lockFiles as $lockFile) {
            $shareId = basename($lockFile, '.lock');
            if (!file_exists($this->dataDir . $shareId . '.json')) {
                unlink($lockFile);
                echo "Cleaned orphaned lock: " . basename($lockFile) . "\n";
            }
        }
        
        echo "Cleanup completed. Files cleaned: $cleaned, Errors: $errors\n";
        
        // Statistics
        $remaining = count(glob($this->dataDir . '*.json'));
        echo "Remaining active shares: $remaining\n";
    }
    
    public function getStatistics() {
        $files = glob($this->dataDir . '*.json');
        $stats = [
            'total_shares' => 0,
            'expired_shares' => 0,
            'maxed_out_shares' => 0,
            'active_shares' => 0,
            'total_views' => 0
        ];
        
        $now = time();
        
        foreach ($files as $file) {
            try {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    $stats['total_shares']++;
                    $stats['total_views'] += $data['currentViews'];
                    
                    if ($now > $data['expires']) {
                        $stats['expired_shares']++;
                    } elseif ($data['currentViews'] >= $data['maxViews']) {
                        $stats['maxed_out_shares']++;
                    } else {
                        $stats['active_shares']++;
                    }
                }
            } catch (Exception $e) {
                // Skip corrupted files
            }
        }
        
        return $stats;
    }
}

// Command line interface
if (php_sapi_name() === 'cli') {
    $cleanup = new CleanupManager();
    
    if (isset($argv[1]) && $argv[1] === 'stats') {
        $stats = $cleanup->getStatistics();
        echo "=== Secure Link Share Statistics ===\n";
        echo "Total shares: {$stats['total_shares']}\n";
        echo "Active shares: {$stats['active_shares']}\n";
        echo "Expired shares: {$stats['expired_shares']}\n";
        echo "Maxed out shares: {$stats['maxed_out_shares']}\n";
        echo "Total views served: {$stats['total_views']}\n";
    } else {
        $cleanup->runCleanup();
    }
} else {
    // Web interface (basic)
    header('Content-Type: text/plain');
    echo "This script should be run from command line.\n";
    echo "Usage: php cleanup.php [stats]\n";
}
?>