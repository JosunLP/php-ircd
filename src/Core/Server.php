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
     * @param array $config Die Server-Konfiguration
     * @param bool $webMode Ob der Server im Web-Modus läuft
     */
    public function __construct(array $config, bool $webMode = false) {
        $this->config = $config;
        $this->isWebMode = $webMode;
        
        // Logger initialisieren
        $logFile = $config['log_file'] ?? 'ircd.log';
        $logLevel = $config['log_level'] ?? 2;
        $logToConsole = $config['log_to_console'] ?? true;
        $this->logger = new Logger($logFile, $logLevel, $logToConsole);
        
        $this->logger->info("Server wird initialisiert...");
        $this->connectionHandler = new ConnectionHandler($this);
        
        // Persistenten Speicher initialisieren
        $this->storageDir = $config['storage_dir'] ?? sys_get_temp_dir() . '/php-ircd-storage';
        if (!file_exists($this->storageDir)) {
            mkdir($this->storageDir, 0777, true);
        }
        
        // Im Web-Modus Server-Zustand aus Speicher laden
        if ($webMode) {
            $this->loadState();
        }
    }
    
    /**
     * Server starten
     */
    public function start(): void {
        if ($this->isWebMode) {
            // Im Web-Modus gibt es keine Socket-Schleife
            $this->logger->info("Server läuft im Web-Modus");
            return;
        }
        
        $this->createSocket();
        $this->mainLoop();
    }
    
    /**
     * Socket erstellen und konfigurieren
     */
    private function createSocket(): void {
        $this->logger->info("Socket wird erstellt...");
        
        // Socket erstellen
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            $errorCode = socket_last_error();
            $errorMsg = socket_strerror($errorCode);
            $this->logger->error("Socket konnte nicht erstellt werden: {$errorCode} - {$errorMsg}");
            die("Socket konnte nicht erstellt werden: {$errorCode} - {$errorMsg}");
        }
        
        // Socket-Optionen setzen
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        
        // Socket binden
        if (!socket_bind($this->socket, $this->config['bind_ip'], $this->config['port'])) {
            $errorCode = socket_last_error();
            $errorMsg = socket_strerror($errorCode);
            $this->logger->error("Socket konnte nicht gebunden werden: {$errorCode} - {$errorMsg}");
            die("Socket konnte nicht gebunden werden: {$errorCode} - {$errorMsg}");
        }
        
        // Socket auf Listen setzen
        if (!socket_listen($this->socket, $this->config['max_users'])) {
            $errorCode = socket_last_error();
            $errorMsg = socket_strerror($errorCode);
            $this->logger->error("Socket konnte nicht auf Listen gesetzt werden: {$errorCode} - {$errorMsg}");
            die("Socket konnte nicht auf Listen gesetzt werden: {$errorCode} - {$errorMsg}");
        }
        
        // Non-blocking setzen
        socket_set_nonblock($this->socket);
        
        $this->logger->info("Server läuft auf {$this->config['bind_ip']}:{$this->config['port']}");
    }
    
    /**
     * Die Hauptschleife des Servers
     */
    private function mainLoop(): void {
        $this->logger->info("Server-Hauptschleife gestartet");
        
        while (true) {
            // Neue Verbindungen akzeptieren
            $this->connectionHandler->acceptNewConnections($this->socket);
            
            // Bestehende Verbindungen verarbeiten
            $this->connectionHandler->handleExistingConnections();
            
            // Serverzustand regelmäßig speichern
            static $lastSaveTime = 0;
            if (time() - $lastSaveTime > 60) { // Alle 60 Sekunden speichern
                $this->saveState();
                $lastSaveTime = time();
            }
            
            // Kleine Pause um CPU-Last zu reduzieren
            usleep(10000); // 10ms
        }
    }
    
    /**
     * Benutzer zum Server hinzufügen
     * 
     * @param User $user Der hinzuzufügende Benutzer
     */
    public function addUser(User $user): void {
        $this->users[] = $user;
        $this->logger->info("Neuer Benutzer verbunden: {$user->getIp()}");
        
        if ($this->isWebMode) {
            $this->saveState();
        }
    }
    
    /**
     * Benutzer vom Server entfernen
     * 
     * @param User $user Der zu entfernende Benutzer
     */
    public function removeUser(User $user): void {
        $key = array_search($user, $this->users, true);
        if ($key !== false) {
            unset($this->users[$key]);
            $this->users = array_values($this->users); // Array reindexieren
            $this->logger->info("Benutzer getrennt: {$user->getNick()}");
            
            if ($this->isWebMode) {
                $this->saveState();
            }
        }
    }
    
    /**
     * Gibt alle Benutzer zurück
     * 
     * @return array Alle Benutzer
     */
    public function getUsers(): array {
        return $this->users;
    }
    
    /**
     * Fügt einen neuen Channel hinzu
     * 
     * @param Channel $channel Der hinzuzufügende Channel
     */
    public function addChannel(Channel $channel): void {
        $this->channels[strtolower($channel->getName())] = $channel;
        $this->logger->info("Neuer Channel erstellt: {$channel->getName()}");
        
        if ($this->isWebMode) {
            $this->saveChannelState($channel);
        }
    }
    
    /**
     * Gibt den Channel mit dem angegebenen Namen zurück
     * 
     * @param string $name Der Name des Channels
     * @return Channel|null Der Channel oder null, wenn nicht gefunden
     */
    public function getChannel(string $name): ?Channel {
        $lowerName = strtolower($name);
        
        // Zuerst im internen Speicher suchen
        if (isset($this->channels[$lowerName])) {
            return $this->channels[$lowerName];
        }
        
        // Im Web-Modus aus persistentem Speicher laden, falls vorhanden
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
     * Gibt alle Channels zurück
     * 
     * @return array Alle Channels
     */
    public function getChannels(): array {
        // Im Web-Modus sicherstellen, dass alle Channels aus dem persistenten Speicher geladen sind
        if ($this->isWebMode) {
            $this->loadAllChannels();
        }
        
        return $this->channels;
    }
    
    /**
     * Entfernt einen Channel
     * 
     * @param string $name Der Name des zu entfernenden Channels
     */
    public function removeChannel(string $name): void {
        $lowerName = strtolower($name);
        if (isset($this->channels[$lowerName])) {
            unset($this->channels[$lowerName]);
            $this->logger->info("Channel entfernt: {$name}");
            
            if ($this->isWebMode) {
                $this->deleteChannelState($name);
                $this->saveState();
            }
        }
    }
    
    /**
     * Gibt die Konfiguration zurück
     * 
     * @return array Die Konfiguration
     */
    public function getConfig(): array {
        return $this->config;
    }
    
    /**
     * Gibt den ConnectionHandler zurück
     * 
     * @return ConnectionHandler Der ConnectionHandler
     */
    public function getConnectionHandler(): ConnectionHandler {
        return $this->connectionHandler;
    }
    
    /**
     * Speichert den Zustand eines Channels in einer Datei
     * 
     * @param Channel $channel Der zu speichernde Channel
     */
    public function saveChannelState(Channel $channel): void {
        // Serialisierung des Kanalobjects
        $serialized = serialize($channel);
        $filename = $this->getChannelFilename($channel->getName());
        
        try {
            file_put_contents($filename, $serialized);
            $this->logger->debug("Channel-Zustand gespeichert: {$channel->getName()}");
        } catch (\Exception $e) {
            $this->logger->error("Fehler beim Speichern des Channel-Zustands: {$e->getMessage()}");
        }
    }
    
    /**
     * Lädt den Zustand eines Channels aus einer Datei
     * 
     * @param string $channelName Der Name des zu ladenden Channels
     * @return Channel|null Der geladene Channel oder null bei Fehler
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
                $this->logger->debug("Channel-Zustand geladen: {$channelName}");
                return $channel;
            }
        } catch (\Exception $e) {
            $this->logger->error("Fehler beim Laden des Channel-Zustands: {$e->getMessage()}");
        }
        
        return null;
    }
    
    /**
     * Löscht den gespeicherten Zustand eines Channels
     * 
     * @param string $channelName Der Name des Channels
     */
    private function deleteChannelState(string $channelName): void {
        $filename = $this->getChannelFilename($channelName);
        
        if (file_exists($filename)) {
            unlink($filename);
            $this->logger->debug("Channel-Zustand gelöscht: {$channelName}");
        }
    }
    
    /**
     * Lädt alle Channels aus dem persistenten Speicher
     */
    private function loadAllChannels(): void {
        $files = glob($this->storageDir . '/channel_*.dat');
        
        foreach ($files as $file) {
            $basename = basename($file);
            // channel_name.dat -> name extrahieren
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
     * Speichert den Serverzustand in eine Datei
     */
    private function saveState(): void {
        // Server-Konfiguration und Channel-Liste speichern
        $state = [
            'timestamp' => time(),
            'config' => $this->config,
            'channelList' => array_keys($this->channels),
        ];
        
        $filename = $this->storageDir . '/server_state.json';
        
        try {
            file_put_contents($filename, json_encode($state));
            $this->logger->debug("Server-Zustand gespeichert");
        } catch (\Exception $e) {
            $this->logger->error("Fehler beim Speichern des Server-Zustands: {$e->getMessage()}");
        }
        
        // Alle Channel einzeln speichern
        foreach ($this->channels as $channel) {
            $this->saveChannelState($channel);
        }
    }
    
    /**
     * Lädt den Serverzustand aus einer Datei
     */
    private function loadState(): void {
        $filename = $this->storageDir . '/server_state.json';
        
        if (!file_exists($filename)) {
            $this->logger->debug("Keine Server-Zustandsdatei gefunden, Server wird neu initialisiert");
            return;
        }
        
        try {
            $json = file_get_contents($filename);
            $state = json_decode($json, true);
            
            if (is_array($state)) {
                // Konfiguration aktualisieren (aber Webserver-spezifische Einstellungen nicht überschreiben)
                if (isset($state['config']) && is_array($state['config'])) {
                    $this->config = array_merge($state['config'], [
                        'storage_dir' => $this->config['storage_dir'] ?? $this->storageDir,
                        'log_file' => $this->config['log_file'] ?? 'ircd.log',
                        'log_level' => $this->config['log_level'] ?? 2,
                        'log_to_console' => $this->config['log_to_console'] ?? true,
                    ]);
                }
                
                $this->logger->debug("Server-Zustand geladen von " . date('Y-m-d H:i:s', $state['timestamp'] ?? 0));
            }
        } catch (\Exception $e) {
            $this->logger->error("Fehler beim Laden des Server-Zustands: {$e->getMessage()}");
        }
    }
    
    /**
     * Gibt den Dateinamen für den Channel-Zustand zurück
     * 
     * @param string $channelName Der Name des Channels
     * @return string Der Dateiname
     */
    private function getChannelFilename(string $channelName): string {
        // Sicherstellen, dass der Dateiname kein Verzeichniswechsel enthält und gültig ist
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $channelName);
        return $this->storageDir . '/channel_' . $safeName . '.dat';
    }
    
    /**
     * Prüft, ob der Server im Web-Modus läuft
     * 
     * @return bool Ob der Server im Web-Modus läuft
     */
    public function isWebMode(): bool {
        return $this->isWebMode;
    }
}