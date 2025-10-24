<?php
// config.php - Secure Configuration Manager
class Config {
    private static $env = null;
    
    public static function loadEnv() {
        if (self::$env === null) {
            self::$env = [];
            
            // Load .env file
            if (file_exists('.env')) {
                $lines = file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || strpos($line, '#') === 0) continue;
                    
                    if (strpos($line, '=') !== false) {
                        list($name, $value) = explode('=', $line, 2);
                        $name = trim($name);
                        $value = trim($value);
                        
                        // Remove quotes if present
                        $value = trim($value, '"\'');
                        
                        self::$env[$name] = $value;
                    }
                }
            }
            
            // Also load from environment variables (for Render.com)
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'BOT_') === 0 || in_array($key, ['CHANNEL_ID', 'APP_API_HASH', 'MAINTENANCE_MODE'])) {
                    self::$env[$key] = $value;
                }
            }
        }
    }
    
    public static function get($key, $default = null) {
        self::loadEnv();
        return self::$env[$key] ?? $default;
    }
    
    public static function getAll() {
        self::loadEnv();
        return self::$env;
    }
}

// Auto-load config
Config::loadEnv();
?>
