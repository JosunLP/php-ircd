<?php

namespace PhpIrcd\Core;

/**
 * Configuration class for the IRC server
 */
class Config {
    private $config = [];
    private $defaultConfig = [
        'name' => 'localhost',                   // Server name
        'net' => 'Lokaler-IRC',                  // Network name
        'max_len' => 512,                        // Maximum packet length
        'max_users' => 50,                       // Maximum number of users
        'port' => 6667,                          // Default IRC port
        'version' => 1.0,                        // Server version
        'bind_ip' => '127.0.0.1',                // IP address for binding
        'line_ending' => "\n",                   // Line ending for socket communication
        'line_ending_conf' => "\n",              // Line ending for MOTD, etc.
        'ping_interval' => 90,                   // Ping interval in seconds
        'ping_timeout' => 240,                   // Ping timeout in seconds
        'ssl_enabled' => false,                  // SSL support
        'ssl_cert' => '',                        // SSL certificate
        'ssl_key' => '',                         // SSL key
        'debug_mode' => true,                    // Debug mode
        'log_level' => 0,                        // 0=Debug, 1=Info, 2=Warn, 3=Error
        'log_file' => 'ircd.log',                // Path to log file
        'motd' => "Willkommen bei deinem lokalen IRC-Testserver!\n\nDieser Server läuft auf localhost und ist zum Testen gedacht.\n\nDu kannst IRC-Operator werden mit folgendem Befehl:\n/OPER admin test123\n\nViel Spaß beim Testen!",
        'opers' => [
            'admin' => 'test123'
        ],
        'operator_passwords' => [                // Passwörter für die Authentifizierung (Neu)
            'admin' => 'test123'
        ],
        'storage_dir' => 'storage',              // Verzeichnis für Datenspeicherung
        'log_to_console' => true,                // Logs in Konsole anzeigen
        
        // Admin-Informationen für den ADMIN-Befehl
        'admin_name' => 'PHP-IRCd Administrator',  // Name des Administrators
        'admin_email' => 'admin@example.com',      // E-Mail des Administrators
        'admin_location' => 'Lokal',               // Standort des Servers
        
        // Server-Informationen für den INFO-Befehl
        'server_info' => [
            'PHP-IRCd Server basierend auf Danoserv',
            'Läuft auf PHP 8.0+',
            'Erstellt im April 2025',
            'Ursprünglich erstellt von Daniel Danopia (2008)',
            'Mit Web-Schnittstelle für einfache Bedienung'
        ],
    ];
    
    /**
     * Constructor
     * 
     * @param string|null $configPath Path to configuration file
     */
    public function __construct(?string $configPath = null) {
        // Initialize with default configuration
        $this->config = $this->defaultConfig;
        
        // Load configuration from file if provided
        if ($configPath !== null) {
            $this->loadFromFile($configPath);
        }
    }
    
    /**
     * Load configuration from a PHP file
     * 
     * @param string $configPath Path to configuration file
     * @return bool Success of loading
     */
    public function loadFromFile(string $configPath): bool {
        // Check if file exists
        if (!file_exists($configPath)) {
            return false;
        }
        
        // Include configuration file
        $config = [];
        include $configPath;
        
        // Merge with default configuration
        if (isset($config) && is_array($config)) {
            $this->config = array_merge($this->defaultConfig, $config);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed Configuration value
     */
    public function get(string $key, $default = null) {
        if (isset($this->config[$key])) {
            return $this->config[$key];
        }
        
        return $default;
    }
    
    /**
     * Set configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     */
    public function set(string $key, $value): void {
        $this->config[$key] = $value;
    }
    
    /**
     * Check if configuration key exists
     * 
     * @param string $key Configuration key
     * @return bool Whether the key exists
     */
    public function has(string $key): bool {
        return isset($this->config[$key]);
    }
    
    /**
     * Returns all configuration values
     * 
     * @return array All configuration values
     */
    public function getAll(): array {
        return $this->config;
    }
    
    /**
     * Saves the configuration to a file
     * 
     * @param string $filePath The path to the configuration file
     * @return bool Success of saving
     */
    public function saveToFile(string $filePath): bool {
        $configContent = "<?php\n\n";
        $configContent .= "//\n";
        $configContent .= "// PHP-IRCd configuration file\n";
        $configContent .= "// Automatically generated by the Config class\n";
        $configContent .= "//\n\n";
        $configContent .= "\$config = " . var_export($this->config, true) . ";\n";
        
        return file_put_contents($filePath, $configContent) !== false;
    }
    
    /**
     * Magic getter method for easy access to configuration values
     */
    public function __get(string $name) {
        return $this->get($name);
    }
    
    /**
     * Magic setter method for easy access to configuration values
     */
    public function __set(string $name, $value): void {
        $this->set($name, $value);
    }
}