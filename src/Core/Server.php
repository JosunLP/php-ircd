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
    }
    
    /**
     * Start the server
     */
    public function start(): void {
        if ($this->isWebMode) {
            // No socket loop in web mode
            $this->logger->info("Server running in web mode");
            return;
        }
        
        $this->createSocket();
        
        // Automatische Server-Verbindungen herstellen
        $this->establishAutoConnections();
        
        $this->mainLoop();
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
     * Create and configure the socket
     */
    private function createSocket(): void {
        $this->logger->info("Creating socket...");
        
        // Check if SSL is enabled
        $useSSL = !empty($this->config['ssl_enabled']) && $this->config['ssl_enabled'] === true;
        
        if ($useSSL) {
            // Create SSL context if SSL is enabled
            if (empty($this->config['ssl_cert']) || empty($this->config['ssl_key'])) {
                $this->logger->error("SSL is enabled but certificate or key is missing");
                die("SSL is enabled but certificate or key is missing");
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
                $this->logger->error("Failed to create SSL socket: {$errno} - {$errstr}");
                die("Failed to create SSL socket: {$errno} - {$errstr}");
            }
            
            // Convert to socket resource
            $this->socket = $socket;
            
            // Set socket to non-blocking mode
            stream_set_blocking($this->socket, false);
            
            $this->logger->info("SSL Server running on {$this->config['bind_ip']}:{$this->config['port']}");
        } else {
            // Create a regular socket (non-SSL)
            $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($this->socket === false) {
                $errorCode = socket_last_error();
                $errorMsg = socket_strerror($errorCode);
                $this->logger->error("Failed to create socket: {$errorCode} - {$errorMsg}");
                die("Failed to create socket: {$errorCode} - {$errorMsg}");
            }
            
            // Set socket options
            socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
            
            // Bind socket
            if (!socket_bind($this->socket, $this->config['bind_ip'], $this->config['port'])) {
                $errorCode = socket_last_error();
                $errorMsg = socket_strerror($errorCode);
                $this->logger->error("Failed to bind socket: {$errorCode} - {$errorMsg}");
                die("Failed to bind socket: {$errorCode} - {$errorMsg}");
            }
            
            // Set socket to listen
            if (!socket_listen($this->socket, $this->config['max_users'])) {
                $errorCode = socket_last_error();
                $errorMsg = socket_strerror($errorCode);
                $this->logger->error("Failed to set socket to listen: {$errorCode} - {$errorMsg}");
                die("Failed to set socket to listen: {$errorCode} - {$errorMsg}");
            }
            
            // Set non-blocking mode
            socket_set_nonblock($this->socket);
            
            $this->logger->info("Server running on {$this->config['bind_ip']}:{$this->config['port']}");
        }
    }
    
    /**
     * The main loop of the server
     */
    private function mainLoop(): void {
        $this->logger->info("Server main loop started");
        
        while (true) {
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
            
            // Small pause to reduce CPU load
            usleep(10000); // 10ms
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
     * Update the server configuration
     * 
     * @param array $newConfig The new configuration
     */
    public function updateConfig(array $newConfig): void {
        // Speichere die Originalwerte für Einstellungen, die nicht während der Laufzeit
        // geändert werden sollten oder die spezifisch für diese Server-Instanz sind
        $preservedSettings = [
            'bind_ip' => $this->config['bind_ip'] ?? '127.0.0.1', 
            'port' => $this->config['port'] ?? 6667,
            'ssl_enabled' => $this->config['ssl_enabled'] ?? false,
            'ssl_cert' => $this->config['ssl_cert'] ?? '',
            'ssl_key' => $this->config['ssl_key'] ?? '',
            'storage_dir' => $this->config['storage_dir'] ?? $this->storageDir,
            'log_file' => $this->config['log_file'] ?? 'ircd.log',
            'log_to_console' => $this->config['log_to_console'] ?? true,
        ];
        
        // Aktualisiere die Konfiguration, aber behalte die geschützten Einstellungen bei
        $this->config = array_merge($newConfig, $preservedSettings);
        
        // Aktualisiere Log-Level, wenn sich dieser geändert hat
        if (isset($newConfig['log_level']) && $this->logger) {
            $this->logger->setLogLevel($newConfig['log_level']);
        }
        
        $this->logger->info("Server configuration updated");
    }
    
    /**
     * Registriert einen Kanal für permanente Speicherung
     * 
     * @param string $channelName Der Name des Kanals
     * @param User $user Der Benutzer, der den Kanal registriert
     * @return bool Erfolg der Registrierung
     */
    public function registerPermanentChannel(string $channelName, User $user): bool {
        // Überprüfe, ob der Kanal existiert
        $channel = $this->getChannel($channelName);
        if ($channel === null) {
            return false;
        }
        
        // Überprüfe, ob der Benutzer Operator im Kanal ist
        if (!$user->isOper() && !$channel->isOperator($user)) {
            return false;
        }
        
        // Markiere den Kanal als permanent
        $channel->setPermanent(true);
        
        // Speichere den Kanalzustand
        $this->saveChannelState($channel);
        
        // Logge die Registrierung
        $this->logger->info("Channel {$channelName} registered as permanent by {$user->getNick()}");
        
        return true;
    }
    
    /**
     * Deregistriert einen permanenten Kanal
     * 
     * @param string $channelName Der Name des Kanals
     * @param User $user Der Benutzer, der den Kanal deregistriert
     * @return bool Erfolg der Deregistrierung
     */
    public function unregisterPermanentChannel(string $channelName, User $user): bool {
        // Überprüfe, ob der Kanal existiert
        $channel = $this->getChannel($channelName);
        if ($channel === null) {
            return false;
        }
        
        // Überprüfe, ob der Benutzer Operator im Kanal ist
        if (!$user->isOper() && !$channel->isOperator($user)) {
            return false;
        }
        
        // Markiere den Kanal als nicht permanent
        $channel->setPermanent(false);
        
        // Lösche den Kanalzustand, wenn der Kanal leer ist
        if (count($channel->getUsers()) === 0) {
            $this->removeChannel($channelName);
            $this->deleteChannelState($channelName);
        } else {
            // Sonst aktualisiere den Zustand
            $this->saveChannelState($channel);
        }
        
        // Logge die Deregistrierung
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
     * Sends WATCH notifications to all users who have this user on their watch list
     * when a user connects or changes nickname
     * 
     * @param User $user The user whose status changed
     * @param bool $isOnline Whether the user is now online (true) or offline (false)
     * @param string|null $oldNick The previous nickname, if this is a nickname change
     */
    public function broadcastWatchNotifications(User $user, bool $isOnline = true, ?string $oldNick = null): void {
        $config = $this->getConfig();
        $nick = $user->getNick();
        
        // Keine Benachrichtigungen, wenn kein Nickname gesetzt ist
        if ($nick === null) {
            return;
        }
        
        // Wenn es ein Nicknamen-Wechsel ist, Benachrichtigungen für den alten Namen senden
        if ($oldNick !== null) {
            $this->sendOfflineWatchNotifications($oldNick);
        }
        
        // Für alle Benutzer auf dem Server prüfen
        foreach ($this->users as $watcher) {
            $watchList = $watcher->getWatchList();
            
            // Prüfen, ob der aktuelle Benutzer in der Watch-Liste ist
            if (in_array(strtolower($nick), $watchList)) {
                if ($isOnline) {
                    // Online-Benachrichtigung senden
                    $watcher->send(":{$config['name']} 604 {$watcher->getNick()} {$nick} {$user->getIdent()} {$user->getHost()} {$user->getLastActivity()} :is online");
                } else {
                    // Offline-Benachrichtigung senden
                    $watcher->send(":{$config['name']} 605 {$watcher->getNick()} {$nick} :is offline");
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
}