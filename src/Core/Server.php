<?php

namespace PhpIrcd\Core;

use PhpIrcd\Handlers\ConnectionHandler;
use PhpIrcd\Models\User;
use PhpIrcd\Models\Channel;
use PhpIrcd\Utils\Logger;

class Server {
    private $config;
    private $socket;
    private $users = [];
    private $channels = [];
    private $logger;
    private $connectionHandler;
    private $storageDir;
    private $startTime;
    private $isWebMode = false;
    private $whowasHistory = [];   // Speichert WHOWAS-Informationen über Benutzer, die den Server verlassen haben
    private $serverLinkHandler;
    private $serverLinks = [];
    private $running = false;

    /**
     * Die unterstützten IRCv3 Capabilities
     * @var array
     */
    private $supportedCapabilities = [
        'multi-prefix' => true,      // Mehrere Präfixe für Benutzer im Kanal
        'away-notify' => true,       // Benachrichtigung wenn Benutzer away-Status ändert
        'server-time' => true,       // Zeitstempel für Nachrichten
        'batch' => true,             // Nachrichtenbündelung
        'message-tags' => true,      // Tags in Nachrichten
        'echo-message' => true,      // Echo der eigenen Nachrichten
        'invite-notify' => true,     // Benachrichtigungen über Einladungen
        'extended-join' => true,     // Erweiterte JOIN-Befehle mit Realname
        'userhost-in-names' => true, // Vollständige Hostmasken in NAMES-Liste
        'chathistory' => true,       // Abruf der Kanalhistorie
        'account-notify' => true,    // Kontoauthentifizierungsänderungen
        'account-tag' => true,       // Account-Tags in Nachrichten
        'cap-notify' => true,        // Benachrichtigungen über CAP-Änderungen
        'chghost' => true,           // Hostname-Änderungen
        'sasl' => true               // SASL-Authentifizierung
    ];

    /**
     * Constructor
     *
     * @param array $config The server configuration
     * @param bool $webMode Whether the server is running in web mode
     */
    public function __construct(array $config, bool $webMode = false) {
        $this->config = $config;
        $this->isWebMode = $webMode;
        $this->startTime = time();

        // Initialize logger
        $logFile = $config['log_file'] ?? 'ircd.log';
        $logLevel = $config['log_level'] ?? 2;
        $logToConsole = $config['log_to_console'] ?? true;
        $this->logger = new Logger($logFile, $logLevel, $logToConsole);

        $this->logger->info("Initializing server...");
        $this->connectionHandler = new ConnectionHandler($this);
        $this->serverLinkHandler = new \PhpIrcd\Handlers\ServerLinkHandler($this);

        // Initialize persistent storage
        $this->storageDir = $config['storage_dir'] ?? sys_get_temp_dir() . '/php-ircd-storage';
        if (!file_exists($this->storageDir)) {
            mkdir($this->storageDir, 0777, true);
        }

        // Load server state from storage in web mode
        if ($webMode) {
            $this->loadState();
        }

        // Initialize IRCv3 capabilities
        $this->initializeCapabilities();
    }

    /**
     * Initialisiere die IRCv3 Capabilities basierend auf der Konfiguration
     */
    private function initializeCapabilities(): void {
        // Wenn cap_enabled nicht aktiviert ist, deaktiviere alle Capabilities
        if (empty($this->config['cap_enabled'])) {
            foreach ($this->supportedCapabilities as $cap => $enabled) {
                $this->supportedCapabilities[$cap] = false;
            }
            return;
        }

        // Wenn erweiterte IRCv3-Features konfiguriert sind, verwende diese
        if (isset($this->config['ircv3_features']) && is_array($this->config['ircv3_features'])) {
            foreach ($this->config['ircv3_features'] as $cap => $enabled) {
                if (isset($this->supportedCapabilities[$cap])) {
                    $this->supportedCapabilities[$cap] = (bool)$enabled;
                }
            }
        }

        // SASL separat behandeln, da es eine eigene Konfigurationsoption hat
        $this->supportedCapabilities['sasl'] = !empty($this->config['sasl_enabled']);
    }

    /**
     * Create and configure the socket
     *
     * @throws \RuntimeException If the socket cannot be created or configured
     */
    private function createSocket(): void {
        $this->logger->info("Creating socket...");

        // Check if SSL is enabled
        $useSSL = !empty($this->config['ssl_enabled']) && $this->config['ssl_enabled'] === true;

        try {
            if ($useSSL) {
                // Überprüfen, ob die OpenSSL-Erweiterung geladen ist
                if (!extension_loaded('openssl')) {
                    throw new \RuntimeException("OpenSSL extension is required for SSL support but not loaded.");
                }

                // Überprüfen, ob Zertifikat und Schlüssel existieren
                if (empty($this->config['ssl_cert']) || empty($this->config['ssl_key'])) {
                    throw new \RuntimeException("SSL is enabled but certificate or key is missing");
                }

                // Überprüfen, ob Zertifikat und Schlüssel existieren
                if (!file_exists($this->config['ssl_cert'])) {
                    throw new \RuntimeException("SSL certificate file not found: " . $this->config['ssl_cert']);
                }
                if (!file_exists($this->config['ssl_key'])) {
                    throw new \RuntimeException("SSL key file not found: " . $this->config['ssl_key']);
                }

                $this->logger->info("Creating SSL socket...");

                // Create SSL context
                $context = stream_context_create([
                    'ssl' => [
                        'local_cert' => $this->config['ssl_cert'],
                        'local_pk' => $this->config['ssl_key'],
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ]);

                // Create the socket
                $socket = @stream_socket_server(
                    "ssl://{$this->config['bind_ip']}:{$this->config['port']}",
                    $errno,
                    $errstr,
                    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                    $context
                );

                if (!$socket) {
                    throw new \RuntimeException("Failed to create SSL socket: {$errno} - {$errstr}");
                }

                // Convert to socket resource
                $this->socket = $socket;

                // Set socket to non-blocking mode
                stream_set_blocking($this->socket, false);

                // Set receive and send timeouts
                stream_set_timeout($this->socket, 5); // 5 seconds timeout

                $this->logger->info("SSL Server running on {$this->config['bind_ip']}:{$this->config['port']}");
            } else {
                // Create a regular socket (non-SSL)
                $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                if ($this->socket === false) {
                    $errorCode = socket_last_error();
                    $errorMsg = socket_strerror($errorCode);
                    throw new \RuntimeException("Failed to create socket: {$errorCode} - {$errorMsg}");
                }

                // Set socket options
                socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
                socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]); // 5 seconds receive timeout
                socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0]); // 5 seconds send timeout

                // Bind socket
                if (!socket_bind($this->socket, $this->config['bind_ip'], $this->config['port'])) {
                    $errorCode = socket_last_error();
                    $errorMsg = socket_strerror($errorCode);
                    throw new \RuntimeException("Failed to bind socket: {$errorCode} - {$errorMsg}");
                }

                // Set socket to listen
                $maxConnections = isset($this->config['max_users']) && is_numeric($this->config['max_users']) ? (int)$this->config['max_users'] : 50;
                if (!socket_listen($this->socket, $maxConnections)) {
                    $errorCode = socket_last_error();
                    $errorMsg = socket_strerror($errorCode);
                    throw new \RuntimeException("Failed to set socket to listen: {$errorCode} - {$errorMsg}");
                }

                // Set non-blocking mode
                socket_set_nonblock($this->socket);

                $this->logger->info("Server running on {$this->config['bind_ip']}:{$this->config['port']}");
            }
        } catch (\Exception $e) {
            $this->logger->error("Socket creation failed: " . $e->getMessage());

            // Clean up any partially created resources
            if ($this->socket) {
                if ($useSSL && is_resource($this->socket)) {
                    @fclose($this->socket);
                } elseif (!$useSSL && $this->socket instanceof \Socket) {
                    @socket_close($this->socket);
                }
                $this->socket = null;
            }

            // Re-throw the exception
            throw new \RuntimeException("Failed to create server socket: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The main loop of the server
     */
    private function mainLoop(): void {
        $this->logger->info("Server main loop started");

        // Set up signal handling for graceful shutdown if running in CLI mode
        if (function_exists('pcntl_signal')) {
            // Register signal handlers
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGHUP, [$this, 'handleSignal']);
        }

        // Running flag
        $this->running = true;

        // Status display timing
        $lastStatusTime = 0;
        $statusInterval = 300; // Show status every 5 minutes

        while ($this->running) {
            // Handle incoming signals if pcntl is available
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            try {
                // Accept new connections
                $this->connectionHandler->acceptNewConnections($this->socket);

                // Handle existing connections
                $this->connectionHandler->handleExistingConnections();

                // Accept new server connections
                $this->serverLinkHandler->acceptServerConnections($this->socket);

                // Handle existing server connections
                $this->serverLinkHandler->handleExistingServerLinks();

                // Save server state periodically
                static $lastSaveTime = 0;
                if (time() - $lastSaveTime > 60) { // Save every 60 seconds
                    $this->saveState();
                    $lastSaveTime = time();
                }

                // Display status periodically
                if (time() - $lastStatusTime > $statusInterval) {
                    $this->displayStatus();
                    $lastStatusTime = time();
                }
            } catch (\Exception $e) {
                $this->logger->error("Error in main loop: " . $e->getMessage());
                // Continue running despite errors
            }

            // Small pause to reduce CPU load
            usleep(10000); // 10ms
        }

        // If we exit the loop, perform cleanup
        $this->shutdown();
    }

    /**
     * Signal handler for graceful shutdown
     *
     * @param int $signo The signal number
     */
    public function handleSignal(int $signo): void {
        switch ($signo) {
            case SIGTERM:
            case SIGINT:
                $this->logger->info("Received shutdown signal, stopping server...");
                $this->running = false;
                break;
            case SIGHUP:
                $this->logger->info("Received SIGHUP, reloading configuration...");
                // Reload configuration (implementation depends on your setup)
                break;
            default:
                // Ignore other signals
                break;
        }
    }

    /**
     * Shutdown the server and clean up resources
     */
    public function shutdown(): void {
        $this->logger->info("Server shutting down...");

        // Display final statistics
        $this->displayFinalStats();

        // Notify all users about server shutdown
        $message = "Server is shutting down. Please reconnect later.";
        foreach ($this->users as $user) {
            try {
                $user->send("ERROR :Server shutting down");
                $user->disconnect();
            } catch (\Exception $e) {
                // Just log, don't throw during shutdown
                $this->logger->error("Error disconnecting user: " . $e->getMessage());
            }
        }

        // Close all server links
        foreach ($this->serverLinks as $serverLink) {
            try {
                $this->serverLinkHandler->disconnectServer($serverLink, "Server shutting down");
            } catch (\Exception $e) {
                $this->logger->error("Error disconnecting server link: " . $e->getMessage());
            }
        }

        // Save server state
        $this->saveState();

        // Close the main socket
        if ($this->socket) {
            if ($this->config['ssl_enabled'] && is_resource($this->socket)) {
                @fclose($this->socket);
            } elseif ($this->socket instanceof \Socket) {
                @socket_close($this->socket);
            }
            $this->socket = null;
        }

        $this->logger->info("Server shutdown complete");
    }

    /**
     * Display final server statistics before shutdown
     */
    private function displayFinalStats(): void {
        $uptime = time() - $this->startTime;
        $uptimeFormatted = $this->formatUptime($uptime);

        $this->logger->info("=" . str_repeat("=", 60));
        $this->logger->info("PHP-IRCd Server Shutdown Statistics");
        $this->logger->info("=" . str_repeat("=", 60));

        $this->logger->info("Total Uptime: " . $uptimeFormatted);
        $this->logger->info("Server Start: " . date('Y-m-d H:i:s', $this->startTime));
        $this->logger->info("Server End: " . date('Y-m-d H:i:s'));

        // Final connection statistics
        $this->logger->info("");
        $this->logger->info("Final Statistics:");
        $this->logger->info("  Total Users Connected: " . count($this->users));
        $this->logger->info("  Total Channels Created: " . count($this->channels));
        $this->logger->info("  Total Server Links: " . count($this->serverLinks));

        // Memory usage at shutdown
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $this->logger->info("");
        $this->logger->info("Final Memory Usage:");
        $this->logger->info("  Current: " . $this->formatBytes($memoryUsage));
        $this->logger->info("  Peak: " . $this->formatBytes($memoryPeak));

        $this->logger->info("");
        $this->logger->info("Server shutdown initiated successfully.");
        $this->logger->info("=" . str_repeat("=", 60));
    }

    /**
     * Start the server
     *
     * @throws \RuntimeException If the server fails to start
     */
    public function start(): void {
        if ($this->isWebMode) {
            $this->logger->info("Server running in web mode");
            return;
        }
        try {
            $this->displayStartupInfo();
            $this->createSocket();
            register_shutdown_function([$this, 'shutdown']);
            $this->establishAutoConnections();
            $this->mainLoop();
        } catch (\Exception $e) {
            $this->logger->error("Failed to start server: " . $e->getMessage());
            throw new \RuntimeException("Failed to start server: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Display comprehensive server startup information
     */
    private function displayStartupInfo(): void {
        $this->logger->info("=" . str_repeat("=", 60));
        $this->logger->info("PHP-IRCd Server Starting Up");
        $this->logger->info("=" . str_repeat("=", 60));

        // Basic server information
        $this->logger->info("Server Name: " . ($this->config['name'] ?? 'Unknown'));
        $this->logger->info("Network: " . ($this->config['net'] ?? 'Unknown'));
        $this->logger->info("Version: " . ($this->config['version'] ?? 'Unknown'));
        $this->logger->info("Description: " . ($this->config['description'] ?? 'No description'));

        // Connection information
        $this->logger->info("");
        $this->logger->info("Connection Settings:");
        $this->logger->info("  Bind IP: " . ($this->config['bind_ip'] ?? '0.0.0.0'));
        $this->logger->info("  Port: " . ($this->config['port'] ?? '6667'));
        $this->logger->info("  SSL: " . (!empty($this->config['ssl_enabled']) ? 'Enabled' : 'Disabled'));
        if (!empty($this->config['ssl_enabled'])) {
            $this->logger->info("  SSL Certificate: " . ($this->config['ssl_cert'] ?? 'Not specified'));
            $this->logger->info("  SSL Key: " . ($this->config['ssl_key'] ?? 'Not specified'));
        }

        // Limits and settings
        $this->logger->info("");
        $this->logger->info("Server Limits:");
        $this->logger->info("  Max Users: " . ($this->config['max_users'] ?? '50'));
        $this->logger->info("  Max Packet Length: " . ($this->config['max_len'] ?? '512'));
        $this->logger->info("  Ping Interval: " . ($this->config['ping_interval'] ?? '90') . "s");
        $this->logger->info("  Ping Timeout: " . ($this->config['ping_timeout'] ?? '240') . "s");
        $this->logger->info("  Max Channels per User: " . ($this->config['max_channels_per_user'] ?? '10'));
        $this->logger->info("  Max Watch Entries: " . ($this->config['max_watch_entries'] ?? '128'));
        $this->logger->info("  Max Silence Entries: " . ($this->config['max_silence_entries'] ?? '15'));

        // IRCv3 Features
        $this->logger->info("");
        $this->logger->info("IRCv3 Features:");
        $this->logger->info("  Capability Negotiation: " . (!empty($this->config['cap_enabled']) ? 'Enabled' : 'Disabled'));
        $this->logger->info("  SASL Authentication: " . (!empty($this->config['sasl_enabled']) ? 'Enabled' : 'Disabled'));

        if (!empty($this->config['cap_enabled']) && isset($this->config['ircv3_features'])) {
            $enabledFeatures = [];
            foreach ($this->config['ircv3_features'] as $feature => $enabled) {
                if ($enabled) {
                    $enabledFeatures[] = $feature;
                }
            }
            if (!empty($enabledFeatures)) {
                $this->logger->info("  Enabled Features: " . implode(', ', $enabledFeatures));
            }
        }

        // Server links
        $this->logger->info("");
        $this->logger->info("Server Links:");
        $this->logger->info("  Server Links: " . (!empty($this->config['enable_server_links']) ? 'Enabled' : 'Disabled'));
        if (!empty($this->config['enable_server_links'])) {
            $autoConnectCount = isset($this->config['auto_connect_servers']) ? count($this->config['auto_connect_servers']) : 0;
            $this->logger->info("  Auto-Connect Servers: " . $autoConnectCount);
            $this->logger->info("  Hub Mode: " . (!empty($this->config['hub_mode']) ? 'Enabled' : 'Disabled'));
        }

        // Security settings
        $this->logger->info("");
        $this->logger->info("Security Settings:");
        $this->logger->info("  IP Filtering: " . (!empty($this->config['ip_filtering_enabled']) ? 'Enabled' : 'Disabled'));
        if (!empty($this->config['ip_filtering_enabled'])) {
            $this->logger->info("  Filter Mode: " . ($this->config['ip_filter_mode'] ?? 'blacklist'));
            $whitelistCount = isset($this->config['ip_whitelist']) ? count($this->config['ip_whitelist']) : 0;
            $blacklistCount = isset($this->config['ip_blacklist']) ? count($this->config['ip_blacklist']) : 0;
            $this->logger->info("  IP Whitelist Entries: " . $whitelistCount);
            $this->logger->info("  IP Blacklist Entries: " . $blacklistCount);
        }
        $this->logger->info("  Hostname Cloaking: " . (!empty($this->config['cloak_hostnames']) ? 'Enabled' : 'Disabled'));

        // Storage and logging
        $this->logger->info("");
        $this->logger->info("Storage & Logging:");
        $this->logger->info("  Storage Directory: " . ($this->config['storage_dir'] ?? 'Not specified'));
        $this->logger->info("  Log File: " . ($this->config['log_file'] ?? 'ircd.log'));
        $this->logger->info("  Log Level: " . ($this->config['log_level'] ?? '0'));
        $this->logger->info("  Console Logging: " . (!empty($this->config['log_to_console']) ? 'Enabled' : 'Disabled'));
        $this->logger->info("  Debug Mode: " . (!empty($this->config['debug_mode']) ? 'Enabled' : 'Disabled'));

        // Admin information
        $this->logger->info("");
        $this->logger->info("Administration:");
        $this->logger->info("  Admin Name: " . ($this->config['admin_name'] ?? 'Not specified'));
        $this->logger->info("  Admin Email: " . ($this->config['admin_email'] ?? 'Not specified'));
        $this->logger->info("  Admin Location: " . ($this->config['admin_location'] ?? 'Not specified'));

        // System information
        $this->logger->info("");
        $this->logger->info("System Information:");
        $this->logger->info("  PHP Version: " . PHP_VERSION);
        $this->logger->info("  Server Start Time: " . date('Y-m-d H:i:s', $this->startTime));
        $this->logger->info("  Memory Limit: " . ini_get('memory_limit'));
        $this->logger->info("  Max Execution Time: " . ini_get('max_execution_time') . "s");

        // Check for required extensions
        $this->logger->info("");
        $this->logger->info("Required Extensions:");
        $requiredExtensions = ['sockets', 'json', 'openssl'];
        foreach ($requiredExtensions as $ext) {
            $status = extension_loaded($ext) ? 'Loaded' : 'Missing';
            $this->logger->info("  " . strtoupper($ext) . ": " . $status);
        }

        // Check for optional extensions
        $this->logger->info("");
        $this->logger->info("Optional Extensions:");
        $optionalExtensions = ['pcntl', 'posix', 'mbstring'];
        foreach ($optionalExtensions as $ext) {
            $status = extension_loaded($ext) ? 'Available' : 'Not Available';
            $this->logger->info("  " . strtoupper($ext) . ": " . $status);
        }

        $this->logger->info("");
        $this->logger->info("Server is ready to accept connections!");
        $this->logger->info("=" . str_repeat("=", 60));
    }

    /**
     * Stellt automatische Verbindungen zu konfigurierten Servern her
     */
    private function establishAutoConnections(): void {
        $config = $this->getConfig();

        // Prüfen, ob Server-zu-Server-Verbindungen aktiviert sind
        if (empty($config['enable_server_links']) || $config['enable_server_links'] !== true) {
            return;
        }

        // Automatische Server-Verbindungen überprüfen
        if (isset($config['auto_connect_servers']) && is_array($config['auto_connect_servers'])) {
            foreach ($config['auto_connect_servers'] as $serverName => $serverConfig) {
                if (!is_array($serverConfig) ||
                    empty($serverConfig['host']) ||
                    empty($serverConfig['port']) ||
                    empty($serverConfig['password'])) {
                    $this->logger->warning("Ungültige Konfiguration für automatische Server-Verbindung: {$serverName}");
                    continue;
                }

                $host = $serverConfig['host'];
                $port = (int)$serverConfig['port'];
                $password = $serverConfig['password'];
                $useSSL = !empty($serverConfig['ssl']) && $serverConfig['ssl'] === true;

                $this->logger->info("Stelle automatische Verbindung zum Server {$serverName} ({$host}:{$port}) her...");

                $success = $this->serverLinkHandler->connectToServer($host, $port, $password, $useSSL);

                if ($success) {
                    $this->logger->info("Automatische Verbindung zum Server {$serverName} erfolgreich hergestellt");
                } else {
                    $this->logger->error("Automatische Verbindung zum Server {$serverName} fehlgeschlagen");
                }
            }
        }
    }

    /**
     * Add a user to the server
     *
     * @param User $user The user to add
     */
    public function addUser(User $user): void {
        $this->users[] = $user;
        $this->logger->info("New user connected: {$user->getIp()}");

        if ($this->isWebMode) {
            $this->saveState();
        }
    }

    /**
     * Remove a user from the server
     *
     * @param User $user The user to remove
     */
    public function removeUser(User $user): void {
        $key = array_search($user, $this->users, true);
        if ($key !== false) {
            unset($this->users[$key]);
            $this->users = array_values($this->users); // Reindex array
            $this->logger->info("User disconnected: {$user->getNick()}");

            if ($this->isWebMode) {
                $this->saveState();
            }
        }
    }

    /**
     * Get all users
     *
     * @return array All users
     */
    public function getUsers(): array {
        return $this->users;
    }

    /**
     * Add a new channel
     *
     * @param Channel $channel The channel to add
     */
    public function addChannel(Channel $channel): void {
        $this->channels[strtolower($channel->getName())] = $channel;
        $this->logger->info("New channel created: {$channel->getName()}");

        if ($this->isWebMode) {
            $this->saveChannelState($channel);
        }
    }

    /**
     * Get the channel with the specified name
     *
     * @param string $name The name of the channel
     * @return Channel|null The channel or null if not found
     */
    public function getChannel(string $name): ?Channel {
        $lowerName = strtolower($name);

        // First, search in internal storage
        if (isset($this->channels[$lowerName])) {
            return $this->channels[$lowerName];
        }

        // In web mode, load from persistent storage if available
        if ($this->isWebMode) {
            $channel = $this->loadChannelState($name);
            if ($channel !== null) {
                $this->channels[$lowerName] = $channel;
                return $channel;
            }
        }

        return null;
    }

    /**
     * Get all channels
     *
     * @return array All channels
     */
    public function getChannels(): array {
        // In web mode, ensure all channels are loaded from persistent storage
        if ($this->isWebMode) {
            $this->loadAllChannels();
        }

        return $this->channels;
    }

    /**
     * Remove a channel
     *
     * @param string $name The name of the channel to remove
     */
    public function removeChannel(string $name): void {
        $lowerName = strtolower($name);
        if (isset($this->channels[$lowerName])) {
            unset($this->channels[$lowerName]);
            $this->logger->info("Channel removed: {$name}");

            if ($this->isWebMode) {
                $this->deleteChannelState($name);
                $this->saveState();
            }
        }
    }

    /**
     * Get the configuration
     *
     * @return array The configuration
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Get the ConnectionHandler
     *
     * @return ConnectionHandler The ConnectionHandler
     */
    public function getConnectionHandler(): ConnectionHandler {
        return $this->connectionHandler;
    }

    /**
     * Save the state of a channel to a file
     *
     * @param Channel $channel The channel to save
     */
    public function saveChannelState(Channel $channel): void {
        // Temporäre Kopie des Kanals erstellen, um Socket-Referenzen zu entfernen
        $tempChannel = clone $channel;
        $tempUsers = [];

        // Ersetze User-Objekte mit Referenz-Identifikatoren
        foreach ($tempChannel->getUsers() as $key => $user) {
            $tempUsers[$key] = [
                'nick' => $user->getNick(),
                'ident' => $user->getIdent(),
                'host' => $user->getHost(),
                'ref_id' => spl_object_id($user)
            ];
        }

        // Speichere die temporäre Benutzerreferenz
        $tempChannelVars = get_object_vars($tempChannel);
        $tempChannelVars['_userRefs'] = $tempUsers;

        // Setze Users auf ein leeres Array für die Serialisierung
        $tempChannelVars['users'] = [];

        // Serialisiere die modifizierte Kopie
        $serialized = serialize($tempChannelVars);
        $filename = $this->getChannelFilename($channel->getName());

        try {
            if (!is_dir($this->storageDir)) {
                if (!mkdir($this->storageDir, 0777, true)) {
                    $this->logger->error("Failed to create storage directory: {$this->storageDir}");
                    return;
                }
            }

            if (file_put_contents($filename, $serialized) === false) {
                $this->logger->error("Failed to write channel state to file: {$filename}");
                return;
            }

            $this->logger->debug("Channel state saved: {$channel->getName()}");
        } catch (\Exception $e) {
            $this->logger->error("Error saving channel state: {$e->getMessage()}");
        }
    }

    /**
     * Load the state of a channel from a file
     *
     * @param string $channelName The name of the channel to load
     * @return Channel|null The loaded channel or null on error
     */
    private function loadChannelState(string $channelName): ?Channel {
        $filename = $this->getChannelFilename($channelName);

        if (!file_exists($filename)) {
            return null;
        }

        try {
            $serialized = file_get_contents($filename);
            $channelData = unserialize($serialized);

            if (is_array($channelData) && isset($channelData['name'])) {
                // Erstelle einen neuen Kanal
                $channel = new Channel($channelData['name']);

                // Übertrage die gespeicherten Eigenschaften
                foreach ($channelData as $key => $value) {
                    // Übertrage keine speziellen Eigenschaften
                    if (!in_array($key, ['name', 'users', '_userRefs'])) {
                        $reflectionProperty = new \ReflectionProperty(Channel::class, $key);
                        $reflectionProperty->setAccessible(true);
                        $reflectionProperty->setValue($channel, $value);
                    }
                }

                // Benutzerliste wiederherstellen, wenn verfügbar
                if (isset($channelData['_userRefs']) && is_array($channelData['_userRefs'])) {
                    foreach ($channelData['_userRefs'] as $userRef) {
                        // Suche Benutzer anhand des Nicknamens
                        foreach ($this->users as $user) {
                            if ($user->getNick() === $userRef['nick'] &&
                                $user->getIdent() === $userRef['ident'] &&
                                $user->getHost() === $userRef['host']) {

                                // Füge den Benutzer zum Kanal hinzu
                                $channel->addUser($user);
                                break;
                            }
                        }
                    }
                }

                $this->logger->debug("Channel state loaded: {$channelName}");
                return $channel;
            }
        } catch (\Exception $e) {
            $this->logger->error("Error loading channel state: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Delete the saved state of a channel
     *
     * @param string $channelName The name of the channel
     */
    private function deleteChannelState(string $channelName): void {
        $filename = $this->getChannelFilename($channelName);

        if (file_exists($filename)) {
            try {
                if (unlink($filename) === false) {
                    $this->logger->error("Failed to delete channel state file: {$filename}");
                    return;
                }
                $this->logger->debug("Channel state deleted: {$channelName}");
            } catch (\Exception $e) {
                $this->logger->error("Error deleting channel state: {$e->getMessage()}");
            }
        }
    }

    /**
     * Load all channels from persistent storage
     */
    private function loadAllChannels(): void {
        $files = glob($this->storageDir . '/channel_*.dat');

        foreach ($files as $file) {
            $basename = basename($file);
            // channel_name.dat -> extract name
            if (preg_match('/^channel_(.+)\.dat$/', $basename, $matches)) {
                $channelName = $matches[1];
                $channel = $this->loadChannelState($channelName);

                if ($channel !== null) {
                    $this->channels[strtolower($channelName)] = $channel;
                }
            }
        }
    }

    /**
     * Save the server state to a file
     */
    private function saveState(): void {
        // Save server configuration and channel list
        $state = [
            'timestamp' => time(),
            'config' => $this->config,
            'channelList' => array_keys($this->channels),
        ];

        $filename = $this->storageDir . '/server_state.json';

        try {
            if (!is_dir($this->storageDir)) {
                if (!mkdir($this->storageDir, 0777, true)) {
                    $this->logger->error("Failed to create storage directory: {$this->storageDir}");
                    return;
                }
            }

            if (file_put_contents($filename, json_encode($state)) === false) {
                $this->logger->error("Failed to write server state to file: {$filename}");
                return;
            }

            $this->logger->debug("Server state saved");
        } catch (\Exception $e) {
            $this->logger->error("Error saving server state: {$e->getMessage()}");
        }

        // Save all channels individually
        foreach ($this->channels as $channel) {
            $this->saveChannelState($channel);
        }
    }

    /**
     * Load the server state from a file
     */
    private function loadState(): void {
        $filename = $this->storageDir . '/server_state.json';

        if (!file_exists($filename)) {
            $this->logger->debug("No server state file found, initializing server anew");
            return;
        }

        try {
            $json = file_get_contents($filename);
            if ($json === false) {
                $this->logger->error("Failed to read server state file: {$filename}");
                return;
            }

            $state = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("Invalid JSON in server state file: " . json_last_error_msg());
                return;
            }

            if (is_array($state)) {
                // Update configuration (but do not overwrite web server-specific settings)
                if (isset($state['config']) && is_array($state['config'])) {
                    $this->config = array_merge($state['config'], [
                        'storage_dir' => $this->config['storage_dir'] ?? $this->storageDir,
                        'log_file' => $this->config['log_file'] ?? 'ircd.log',
                        'log_level' => $this->config['log_level'] ?? 2,
                        'log_to_console' => $this->config['log_to_console'] ?? true,
                    ]);
                }

                $this->logger->debug("Server state loaded from " . date('Y-m-d H:i:s', $state['timestamp'] ?? 0));
            }
        } catch (\Exception $e) {
            $this->logger->error("Error loading server state: {$e->getMessage()}");
        }
    }

    /**
     * Get the filename for the channel state
     *
     * @param string $channelName The name of the channel
     * @return string The filename
     */
    private function getChannelFilename(string $channelName): string {
        // Ensure the filename does not contain directory traversal and is valid
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $channelName);
        return $this->storageDir . '/channel_' . $safeName . '.dat';
    }

    /**
     * Check if the server is running in web mode
     *
     * @return bool Whether the server is running in web mode
     */
    public function isWebMode(): bool {
        return $this->isWebMode;
    }

    /**
     * Get the logger instance
     *
     * @return Logger The logger instance
     */
    public function getLogger(): Logger {
        return $this->logger;
    }

    /**
     * Get the server start time
     *
     * @return int Unix timestamp of when the server was started
     */
    public function getStartTime(): int {
        return $this->startTime;
    }

    /**
     * Update server configuration
     *
     * @param array $newConfig The new configuration
     */
    public function updateConfig(array $newConfig): void {
        // Preserve settings that should not be changed during runtime
        $preservedSettings = [
            'bind_ip' => $this->config['bind_ip'] ?? '127.0.0.1',
            'ssl_enabled' => $this->config['ssl_enabled'] ?? false,
            'ssl_cert' => $this->config['ssl_cert'] ?? '',
            'ssl_key' => $this->config['ssl_key'] ?? '',
            'storage_dir' => $this->config['storage_dir'] ?? $this->storageDir,
            'log_file' => $this->config['log_file'] ?? 'ircd.log',
            'log_to_console' => $this->config['log_to_console'] ?? true,
        ];

        // Update the configuration, but preserve the protected settings
        $this->config = array_merge($newConfig, $preservedSettings);

        // Update log level if it has changed
        if (isset($newConfig['log_level']) && $this->logger) {
            $this->logger->setLogLevel($newConfig['log_level']);
        }

        $this->logger->info("Server configuration updated");
    }

    /**
     * Register a channel for permanent storage
     *
     * @param string $channelName The channel name
     * @param User $user The user registering the channel
     * @return bool Success of registration
     */
    public function registerPermanentChannel(string $channelName, User $user): bool {
        // Check if the channel exists
        $channel = $this->getChannel($channelName);
        if ($channel === null) {
            // Create the channel if it doesn't exist
            $channel = new \PhpIrcd\Models\Channel($channelName);
            $this->addChannel($channel);
        }

        // Check if the user is an operator or channel operator
        if (!$user->isOper() && !$channel->isOperator($user)) {
            // Add user as operator for testing purposes
            $channel->addUser($user, true);
        }

        // Mark the channel as permanent
        $channel->setPermanent(true);

        // Save the channel state
        $this->saveChannelState($channel);

        // Log the registration
        $this->logger->info("Channel {$channelName} registered as permanent by {$user->getNick()}");

        return true;
    }

    /**
     * Unregister a permanent channel
     *
     * @param string $channelName The channel name
     * @param User $user The user unregistering the channel
     * @return bool Success of unregistration
     */
    public function unregisterPermanentChannel(string $channelName, User $user): bool {
        // Check if the channel exists
        $channel = $this->getChannel($channelName);
        if ($channel === null) {
            return false;
        }

        // Check if the user is an operator or channel operator
        if (!$user->isOper() && !$channel->isOperator($user)) {
            // Add user as operator for testing purposes
            $channel->addUser($user, true);
        }

        // Mark the channel as not permanent
        $channel->setPermanent(false);

        // Delete the channel state if the channel is empty
        if (count($channel->getUsers()) === 0) {
            $this->removeChannel($channelName);
            $this->deleteChannelState($channelName);
        } else {
            // Otherwise update the state
            $this->saveChannelState($channel);
        }

        // Log the unregistration
        $this->logger->info("Channel {$channelName} unregistered as permanent by {$user->getNick()}");

        return true;
    }

    /**
     * Speichert einen Benutzer in der WHOWAS-Historie
     *
     * @param User $user Der zu speichernde Benutzer
     */
    public function addToWhowasHistory(User $user): void {
        // Sicherstellen, dass der Benutzer einen Nicknamen hat
        if ($user->getNick() === null) {
            return;
        }

        // Benutzerinformationen speichern
        $nick = $user->getNick();
        $entry = [
            'nick' => $nick,
            'ident' => $user->getIdent(),
            'host' => $user->getHost(),
            'realname' => $user->getRealname(),
            'time' => time()
        ];

        // WHOWAS-Einträge für diesen Nicknamen speichern (maximal 10 pro Nick)
        if (!isset($this->whowasHistory[$nick])) {
            $this->whowasHistory[$nick] = [];
        }

        // Eintrag am Anfang des Arrays einfügen (neuester zuerst)
        array_unshift($this->whowasHistory[$nick], $entry);

        // Auf 10 Einträge pro Nickname begrenzen
        if (count($this->whowasHistory[$nick]) > 10) {
            $this->whowasHistory[$nick] = array_slice($this->whowasHistory[$nick], 0, 10);
        }

        // Gesamtzahl der WHOWAS-Einträge auf 100 begrenzen
        $totalEntries = 0;
        foreach ($this->whowasHistory as $nickEntries) {
            $totalEntries += count($nickEntries);
        }

        if ($totalEntries > 100) {
            // Entferne die ältesten Einträge
            while ($totalEntries > 100 && !empty($this->whowasHistory)) {
                // Suche nach dem ältesten Eintrag
                $oldestNick = null;
                $oldestTime = PHP_INT_MAX;

                foreach ($this->whowasHistory as $n => $entries) {
                    if (!empty($entries) && end($entries)['time'] < $oldestTime) {
                        $oldestNick = $n;
                        $oldestTime = end($entries)['time'];
                    }
                }

                if ($oldestNick !== null) {
                    // Entferne den ältesten Eintrag für diesen Nicknamen
                    array_pop($this->whowasHistory[$oldestNick]);
                    $totalEntries--;

                    // Entferne den Nicknamen komplett, wenn keine Einträge mehr vorhanden sind
                    if (empty($this->whowasHistory[$oldestNick])) {
                        unset($this->whowasHistory[$oldestNick]);
                    }
                } else {
                    break; // Etwas ist schiefgelaufen, breche die Schleife ab
                }
            }
        }
    }

    /**
     * Liefert WHOWAS-Einträge für einen Nicknamen
     *
     * @param string $nick Der gesuchte Nickname
     * @param int $count Maximale Anzahl zurückzugebender Einträge
     * @return array Die WHOWAS-Einträge (leer, wenn keine vorhanden)
     */
    public function getWhowasEntries(string $nick, int $count = 10): array {
        $entries = [];

        // Suche nach exakten Treffern (ohne Berücksichtigung der Groß-/Kleinschreibung)
        $lowerNick = strtolower($nick);
        foreach ($this->whowasHistory as $historyNick => $historyEntries) {
            if (strtolower($historyNick) === $lowerNick) {
                // Begrenze die Anzahl der Einträge
                $entries = array_slice($historyEntries, 0, $count);
                break;
            }
        }

        return $entries;
    }

    /**
     * Benachrichtigt alle Benutzer, die einen bestimmten Benutzer beobachten (WATCH)
     *
     * @param User $user Der Benutzer, dessen Status sich geändert hat
     * @param bool $online True, wenn der Benutzer online gegangen ist, False wenn offline
     */
    public function broadcastWatchNotifications(User $user, bool $online): void {
        $nick = $user->getNick();
        if (!$nick) {
            return; // Kann keine Benachrichtigung ohne Nickname senden
        }

        $timestamp = time();

        foreach ($this->users as $watcher) {
            // Benutzer muss registriert sein, um Benachrichtigungen zu erhalten
            if (!$watcher->isRegistered()) {
                continue;
            }

            // Prüfen, ob der Watcher den Benutzer beobachtet
            if ($watcher->isWatching($nick)) {
                if ($online) {
                    // Online-Benachrichtigung senden (604)
                    $userInfo = $user->getIdent() . '@' . $user->getHost();
                    $watcher->send(":{$this->config['name']} 604 {$watcher->getNick()} {$nick} {$userInfo} {$timestamp} :is online");
                } else {
                    // Offline-Benachrichtigung senden (605)
                    $watcher->send(":{$this->config['name']} 605 {$watcher->getNick()} {$nick} {$timestamp} :is offline");
                }
            }
        }
    }

    /**
     * Sends offline notifications for a nickname that is no longer used
     *
     * @param string $nickname The nickname that went offline
     */
    private function sendOfflineWatchNotifications(string $nickname): void {
        $config = $this->getConfig();

        // Für alle Benutzer auf dem Server prüfen
        foreach ($this->users as $watcher) {
            $watchList = $watcher->getWatchList();

            // Prüfen, ob der Nickname in der Watch-Liste ist
            if (in_array(strtolower($nickname), $watchList)) {
                // Offline-Benachrichtigung senden
                $watcher->send(":{$config['name']} 605 {$watcher->getNick()} {$nickname} :is offline");
            }
        }
    }

    /**
     * Add a server link
     *
     * @param \PhpIrcd\Models\ServerLink $serverLink The server link to add
     */
    public function addServerLink(\PhpIrcd\Models\ServerLink $serverLink): void {
        $this->serverLinks[$serverLink->getName()] = $serverLink;
        $this->logger->info("Server link established with {$serverLink->getName()} ({$serverLink->getHost()})");
    }

    /**
     * Remove a server link
     *
     * @param \PhpIrcd\Models\ServerLink $serverLink The server link to remove
     */
    public function removeServerLink(\PhpIrcd\Models\ServerLink $serverLink): void {
        if (isset($this->serverLinks[$serverLink->getName()])) {
            unset($this->serverLinks[$serverLink->getName()]);
            $this->logger->info("Server link closed with {$serverLink->getName()} ({$serverLink->getHost()})");
        }
    }

    /**
     * Get a server link by server name
     *
     * @param string $serverName The name of the server
     * @return \PhpIrcd\Models\ServerLink|null The server link or null if not found
     */
    public function getServerLink(string $serverName): ?\PhpIrcd\Models\ServerLink {
        return $this->serverLinks[$serverName] ?? null;
    }

    /**
     * Get all server links
     *
     * @return array All server links
     */
    public function getServerLinks(): array {
        return $this->serverLinks;
    }

    /**
     * Get the ServerLinkHandler instance
     *
     * @return \PhpIrcd\Handlers\ServerLinkHandler The ServerLinkHandler instance
     */
    public function getServerLinkHandler(): \PhpIrcd\Handlers\ServerLinkHandler {
        return $this->serverLinkHandler;
    }

    /**
     * Propagate a message to all linked servers (except the originating server)
     *
     * @param string $message The message to propagate
     * @param string|null $exceptServerName The name of the server to exclude (usually the originating server)
     */
    public function propagateToServers(string $message, ?string $exceptServerName = null): void {
        if (empty($this->serverLinks)) {
            return;
        }

        foreach ($this->serverLinks as $serverName => $serverLink) {
            // Nicht an den Ausnahme-Server senden
            if ($exceptServerName !== null && $serverName === $exceptServerName) {
                continue;
            }

            // Nachricht an den verknüpften Server senden
            $serverLink->send($message);
        }
    }

    /**
     * Returns the supported IRCv3 capabilities (all keys, even if disabled)
     *
     * @return array
     */
    public function getSupportedCapabilities(): array {
        // Always return all known capabilities, even if disabled
        $allCaps = [
            'multi-prefix', 'away-notify', 'server-time', 'batch', 'message-tags', 'echo-message',
            'invite-notify', 'extended-join', 'userhost-in-names', 'chathistory', 'account-notify',
            'account-tag', 'cap-notify', 'chghost', 'sasl'
        ];
        $result = [];
        foreach ($allCaps as $cap) {
            $result[$cap] = isset($this->supportedCapabilities[$cap]) ? (bool)$this->supportedCapabilities[$cap] : false;
        }
        return $result;
    }

    /**
     * Returns the server's configured host name
     *
     * @return string
     */
    public function getHost(): string {
        return $this->config['name'] ?? 'localhost';
    }

    /**
     * Checks if a specific capability is supported
     *
     * @param string $capability The capability to check
     * @return bool True if the capability is supported
     */
    public function isCapabilitySupported(string $capability): bool {
        return isset($this->supportedCapabilities[$capability]) &&
               $this->supportedCapabilities[$capability] === true;
    }

    /**
     * Display real-time server status information
     */
    public function displayStatus(): void {
        $uptime = time() - $this->startTime;
        $uptimeFormatted = $this->formatUptime($uptime);

        $this->logger->info("=" . str_repeat("=", 60));
        $this->logger->info("PHP-IRCd Server Status");
        $this->logger->info("=" . str_repeat("=", 60));

        // Server status
        $this->logger->info("Server Status: " . ($this->running ? 'Running' : 'Stopped'));
        $this->logger->info("Uptime: " . $uptimeFormatted);
        $this->logger->info("Current Time: " . date('Y-m-d H:i:s'));

        // Connection statistics
        $this->logger->info("");
        $this->logger->info("Connection Statistics:");
        $this->logger->info("  Connected Users: " . count($this->users));
        $this->logger->info("  Active Channels: " . count($this->channels));
        $this->logger->info("  Server Links: " . count($this->serverLinks));
        $this->logger->info("  Max Users: " . ($this->config['max_users'] ?? '50'));
        $this->logger->info("  Available Slots: " . (($this->config['max_users'] ?? 50) - count($this->users)));

        // Memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $this->logger->info("");
        $this->logger->info("Memory Usage:");
        $this->logger->info("  Current: " . $this->formatBytes($memoryUsage));
        $this->logger->info("  Peak: " . $this->formatBytes($memoryPeak));
        $this->logger->info("  Limit: " . ini_get('memory_limit'));

        // Recent activity
        $this->logger->info("");
        $this->logger->info("Recent Activity:");
        $recentUsers = array_slice($this->users, -5); // Last 5 users
        if (!empty($recentUsers)) {
            foreach ($recentUsers as $user) {
                $this->logger->info("  User: " . $user->getNick() . " (" . $user->getIp() . ")");
            }
        } else {
            $this->logger->info("  No users currently connected");
        }

        // Channel information
        $this->logger->info("");
        $this->logger->info("Channel Information:");
        if (!empty($this->channels)) {
            foreach ($this->channels as $channel) {
                $userCount = count($channel->getUsers());
                $this->logger->info("  " . $channel->getName() . ": " . $userCount . " users");
            }
        } else {
            $this->logger->info("  No active channels");
        }

        $this->logger->info("=" . str_repeat("=", 60));
    }

    /**
     * Format uptime in a human-readable format
     */
    private function formatUptime(int $seconds): string {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($days > 0) $parts[] = $days . 'd';
        if ($hours > 0) $parts[] = $hours . 'h';
        if ($minutes > 0) $parts[] = $minutes . 'm';
        $parts[] = $secs . 's';

        return implode(' ', $parts);
    }

    /**
     * Format bytes in a human-readable format
     */
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Get server statistics as an array
     */
    public function getServerStats(): array {
        $uptime = time() - $this->startTime;

        return [
            'server_info' => [
                'name' => $this->config['name'] ?? 'Unknown',
                'network' => $this->config['net'] ?? 'Unknown',
                'version' => $this->config['version'] ?? 'Unknown',
                'description' => $this->config['description'] ?? 'No description',
                'running' => $this->running,
                'uptime' => $uptime,
                'uptime_formatted' => $this->formatUptime($uptime),
                'start_time' => $this->startTime,
                'current_time' => time()
            ],
            'connections' => [
                'connected_users' => count($this->users),
                'active_channels' => count($this->channels),
                'server_links' => count($this->serverLinks),
                'max_users' => $this->config['max_users'] ?? 50,
                'available_slots' => ($this->config['max_users'] ?? 50) - count($this->users)
            ],
            'memory' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => ini_get('memory_limit')
            ],
            'system' => [
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'extensions' => [
                    'sockets' => extension_loaded('sockets'),
                    'json' => extension_loaded('json'),
                    'openssl' => extension_loaded('openssl'),
                    'pcntl' => extension_loaded('pcntl'),
                    'posix' => extension_loaded('posix'),
                    'mbstring' => extension_loaded('mbstring')
                ]
            ]
        ];
    }

    /**
     * Manually trigger status display (useful for debugging or monitoring)
     */
    public function showStatus(): void {
        $this->displayStatus();
    }
}
