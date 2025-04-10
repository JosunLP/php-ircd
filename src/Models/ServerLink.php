<?php

namespace PhpIrcd\Models;

/**
 * ServerLink Class
 * 
 * Repräsentiert eine Verbindung zu einem anderen IRC-Server im Netzwerk.
 * Implementiert die Server-zu-Server-Kommunikation nach RFC 2813.
 */
class ServerLink {
    private $socket;
    private $name;
    private $description = '';
    private $password;
    private $isConnected = false;
    private $lastActivity;
    private $hopCount = 1;
    private $token = null;
    private $isStreamSocket = false;
    private $buffer = '';
    
    /**
     * Constructor
     * 
     * @param mixed $socket Der Socket (Stream oder Socket-Ressource)
     * @param string $name Der Name des entfernten Servers
     * @param string $password Das Verbindungspasswort
     * @param bool $isStreamSocket Ob es sich um einen Stream-Socket handelt
     */
    public function __construct($socket, string $name, string $password, bool $isStreamSocket = false) {
        $this->socket = $socket;
        $this->name = $name;
        $this->password = $password;
        $this->isStreamSocket = $isStreamSocket;
        $this->lastActivity = time();
        
        // Socket auf nicht-blockierend setzen
        if ($isStreamSocket) {
            stream_set_blocking($socket, false);
        } else {
            socket_set_nonblock($socket);
        }
    }
    
    /**
     * Sendet Daten an den entfernten Server
     * 
     * @param string $data Die zu sendenden Daten
     * @return bool Erfolg des Sendens
     */
    public function send(string $data): bool {
        try {
            if ($this->isStreamSocket) {
                // Stream-Sockets (SSL) verwenden fwrite
                $result = @fwrite($this->socket, $data . "\r\n");
                return $result !== false;
            } else {
                // Normale Sockets verwenden socket_write
                $result = @socket_write($this->socket, $data . "\r\n");
                return $result !== false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Liest Daten vom entfernten Server
     * 
     * @param int $maxLen Die maximale Lesegröße
     * @return string|false Die gelesenen Daten oder false bei Fehler/Verbindungsabbruch
     */
    public function readCommand(int $maxLen = 512) {
        try {
            if ($this->isStreamSocket) {
                // Stream-Sockets (SSL) lesen
                $data = @fread($this->socket, $maxLen);
            } else {
                // Normale Sockets lesen
                $data = @socket_read($this->socket, $maxLen);
            }
            
            // Wenn false, ist die Verbindung wahrscheinlich geschlossen
            if ($data === false) {
                return false;
            }
            
            // Daten zum Buffer hinzufügen (auch leere Strings)
            $this->buffer .= $data;
            
            // Wenn der Buffer eine neue Zeile enthält, den ersten Befehl zurückgeben
            $pos = strpos($this->buffer, "\n");
            if ($pos !== false) {
                $command = substr($this->buffer, 0, $pos);
                $this->buffer = substr($this->buffer, $pos + 1);
                return trim($command); // Steuerzeichen entfernen
            } elseif ($pos = strpos($this->buffer, "\r")) {
                // Manche IRC-Server senden nur \r als Zeilenende
                $command = substr($this->buffer, 0, $pos);
                $this->buffer = substr($this->buffer, $pos + 1);
                return trim($command);
            }
            
            // Kein vollständiger Befehl verfügbar
            return '';
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Trennt die Verbindung
     */
    public function disconnect(): void {
        if ($this->isStreamSocket) {
            if (is_resource($this->socket)) {
                @fclose($this->socket);
            }
        } else {
            if ($this->socket instanceof \Socket) {
                @socket_close($this->socket);
            }
        }
    }
    
    /**
     * Aktualisiert den Zeitstempel der letzten Aktivität
     */
    public function updateActivity(): void {
        $this->lastActivity = time();
    }
    
    /**
     * Gibt den Zeitstempel der letzten Aktivität zurück
     */
    public function getLastActivity(): int {
        return $this->lastActivity;
    }
    
    /**
     * Prüft, ob der Server inaktiv ist (Timeout)
     * 
     * @param int $timeout Der Zeitraum in Sekunden, nach dem ein Server als inaktiv gilt
     * @return bool Ob der Server inaktiv ist
     */
    public function isInactive(int $timeout): bool {
        return (time() - $this->lastActivity) > $timeout;
    }
    
    /**
     * Getter und Setter für den Namen des Servers
     */
    public function getName(): string {
        return $this->name;
    }
    
    public function setName(string $name): void {
        $this->name = $name;
    }
    
    /**
     * Getter und Setter für die Beschreibung des Servers
     */
    public function getDescription(): string {
        return $this->description;
    }
    
    public function setDescription(string $description): void {
        $this->description = $description;
    }
    
    /**
     * Getter und Setter für den Hop-Count
     */
    public function getHopCount(): int {
        return $this->hopCount;
    }
    
    public function setHopCount(int $hopCount): void {
        $this->hopCount = $hopCount;
    }
    
    /**
     * Getter und Setter für den Token
     */
    public function getToken(): ?string {
        return $this->token;
    }
    
    public function setToken(?string $token): void {
        $this->token = $token;
    }
    
    /**
     * Getter und Setter für den Verbindungsstatus
     */
    public function isConnected(): bool {
        return $this->isConnected;
    }
    
    public function setConnected(bool $connected): void {
        $this->isConnected = $connected;
    }
    
    /**
     * Getter für das Passwort
     */
    public function getPassword(): string {
        return $this->password;
    }
}