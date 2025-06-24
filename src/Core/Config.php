<?php

namespace PhpIrcd\Core;

/**
 * Configuration class for the IRC server
 */
class Config {
    private $config = [];
    private $defaultConfig = [
        'name' => 'localhost',                   // Server name
        'net' => 'Local-IRC',                    // Network name
        'max_len' => 512,                        // Maximum packet length
        'max_users' => 50,                       // Maximum number of users
        'port' => 6667,                          // Default IRC port
        'version' => 1.0,                        // Server version
        'bind_ip' => '127.0.0.1',                // IP address for binding
        'line_ending' => "\r\n",                 // Line ending for socket communication
        'line_ending_conf' => "\n",              // Line ending for MOTD, etc.
        'ping_interval' => 90,                   // Ping interval in seconds
        'ping_timeout' => 240,                   // Ping timeout in seconds
        'ssl_enabled' => false,                  // SSL support
        'ssl_cert' => '',                        // SSL certificate
        'ssl_key' => '',                         // SSL key
        'debug_mode' => true,                    // Debug mode
        'log_level' => 0,                        // 0=Debug, 1=Info, 2=Warn, 3=Error
        'log_file' => 'ircd.log',                // Path to log file
        'motd' => "Welcome to your local IRC test server!\n\nThis server runs on localhost and is intended for testing.\n\nYou can become an IRC operator with the following command:\n/OPER admin password\n\nHave fun testing!",
        'description' => 'PHP-IRCd test server',  // Server description for LINKS command
        'opers' => [
            // Default operator credentials should be set in configuration file
            // Format: 'username' => 'password'
        ],
        'operator_passwords' => [                // Passwords for authentication
            // Format: 'username' => 'password'
        ],
        'storage_dir' => 'storage',              // Directory for data storage
        'log_to_console' => true,                // Show logs in console

        // Admin information for the ADMIN command
        'admin_name' => 'PHP-IRCd Administrator',  // Administrator name
        'admin_email' => 'admin@example.com',      // Administrator email
        'admin_location' => 'Local',               // Server location

        // Server information for the INFO command
        'server_info' => [
            'PHP-IRCd Server based on Danoserv',
            'Running on PHP 8.0+',
            'Created in April 2025',
            'Originally created by Daniel Danopia (2008)',
            'With web interface for easy usage'
        ],

        // Server-to-server communication
        'enable_server_links' => false,           // Enable server-to-server connections
        'server_password' => '',                  // Password for server connections
        'hub_mode' => false,                      // Run server as hub (mediates between servers)
        'auto_connect_servers' => [               // Automatically connect to these servers
            // Format: 'server_name' => ['host' => 'hostname', 'port' => port, 'password' => 'pass', 'ssl' => true/false]
        ],

        // IRCv3 features
        'cap_enabled' => true,                    // Enable IRCv3 Capability Negotiation
        'sasl_enabled' => true,                   // Enable SASL authentication
        'sasl_mechanisms' => ['PLAIN', 'EXTERNAL', 'SCRAM-SHA-1', 'SCRAM-SHA-256'], // Supported SASL mechanisms
        'sasl_users' => [                         // SASL user accounts
            // Format: 'id' => ['username' => 'user', 'password' => 'pass']
        ],

        // IRCv3 extended features
        'ircv3_features' => [                      // IRCv3 feature set
            'multi-prefix' => true,                // Multiple prefixes for users in channel
            'away-notify' => true,                 // Notify when user changes away status
            'server-time' => true,                 // Timestamps for messages
            'batch' => true,                       // Message batching
            'message-tags' => true,                // Tags in messages
            'echo-message' => true,                // Echo own messages
            'invite-notify' => true,               // Invitation notifications
            'extended-join' => true,               // Extended JOIN commands with realname
            'userhost-in-names' => true,           // Full hostmasks in NAMES list
            'chathistory' => true,                 // Retrieve channel history
            'account-notify' => true,              // Account authentication changes
            'account-tag' => true,                 // Account tags in messages
            'cap-notify' => true,                  // CAP change notifications
            'chghost' => true,                     // Host change notifications
        ],

        'chathistory_max_messages' => 100,         // Maximum number of messages in chat history

        // IP filtering settings
        'ip_filtering_enabled' => false,          // Enable/disable IP filtering
        'ip_whitelist' => [],                     // Whitelist of allowed IP addresses
        'ip_blacklist' => [],                     // Blacklist of forbidden IP addresses
        'ip_filter_mode' => 'blacklist',          // Filter mode: 'blacklist' or 'whitelist'

        // Advanced features
        'cloak_hostnames' => true,                // Cloak hostnames
        'max_watch_entries' => 128,               // Maximum number of WATCH entries
        'max_silence_entries' => 15,              // Maximum number of SILENCE entries
        'default_user_modes' => '',               // Default user modes
        'default_channel_modes' => 'nt',          // Default channel modes
        'max_channels_per_user' => 10,            // Maximum number of channels per user
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
            $this->validateConfig();
            return true;
        }

        return false;
    }

    /**
     * Validate the configuration values
     *
     * Makes sure that critical configuration values are valid
     */
    private function validateConfig(): void {
        // Ensure port is in valid range
        if (!is_numeric($this->config['port']) || $this->config['port'] < 1 || $this->config['port'] > 65535) {
            $this->config['port'] = 6667;
        }

        // Ensure SSL is properly configured
        if ($this->config['ssl_enabled'] && (empty($this->config['ssl_cert']) || empty($this->config['ssl_key']))) {
            $this->config['ssl_enabled'] = false;
        }

        // Ensure log level is valid
        if (!is_numeric($this->config['log_level']) || $this->config['log_level'] < 0 || $this->config['log_level'] > 3) {
            $this->config['log_level'] = 1;
        }

        // Ensure max_users is reasonable
        if (!is_numeric($this->config['max_users']) || $this->config['max_users'] < 1) {
            $this->config['max_users'] = 50;
        }

        // Ensure server-to-server is properly configured
        if ($this->config['enable_server_links'] && empty($this->config['server_password'])) {
            $this->config['enable_server_links'] = false;
        }
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
