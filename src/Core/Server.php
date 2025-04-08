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
    private $isWebMode = false;
    
    /**
     * Constructor
     * 
     * @param array $config The server configuration
     * @param bool $webMode Whether the server is running in web mode
     */
    public function __construct(array $config, bool $webMode = false) {
        $this->config = $config;
        $this->isWebMode = $webMode;
        
        // Initialize logger
        $logFile = $config['log_file'] ?? 'ircd.log';
        $logLevel = $config['log_level'] ?? 2;
        $logToConsole = $config['log_to_console'] ?? true;
        $this->logger = new Logger($logFile, $logLevel, $logToConsole);
        
        $this->logger->info("Initializing server...");
        $this->connectionHandler = new ConnectionHandler($this);
        
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
        $this->mainLoop();
    }
    
    /**
     * Create and configure the socket
     */
    private function createSocket(): void {
        $this->logger->info("Creating socket...");
        
        // Create socket
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
        // Serialize the channel object
        $serialized = serialize($channel);
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
            $channel = unserialize($serialized);
            
            if ($channel instanceof Channel) {
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
}