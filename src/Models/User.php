<?php

namespace PhpIrcd\Models;

class User {
    private $socket;
    private $nick = null;
    private $ident = null;
    private $realname = null;
    private $ip;
    private $host;
    private $buffer = '';
    private $isOper = false;
    private $cloak;
    private $registered = false;
    private $lastActivity;
    private $modes = [];
    private $away = null;
    
    /**
     * Konstruktor
     * 
     * @param resource $socket Die Verbindung des Benutzers
     * @param string $ip Die IP-Adresse des Benutzers
     */
    public function __construct($socket, string $ip) {
        $this->socket = $socket;
        $this->ip = $ip;
        $this->host = $this->lookupHostname($ip);
        $this->cloak = $this->host; // Initialwert, kann später geändert werden
        $this->lastActivity = time();
        
        // Socket auf non-blocking setzen
        socket_set_nonblock($this->socket);
    }
    
    /**
     * Hostname-Lookup mit Timeout
     * 
     * @param string $ip Die IP-Adresse
     * @return string Der Hostname oder die IP, wenn der Lookup fehlschlägt
     */
    private function lookupHostname(string $ip): string {
        $hostname = gethostbyaddr($ip);
        return $hostname ?: $ip;
    }
    
    /**
     * Daten an den Benutzer senden
     * 
     * @param string $data Die zu sendenden Daten
     * @return bool Erfolg des Sendens
     */
    public function send(string $data): bool {
        try {
            $result = socket_write($this->socket, $data . "\r\n");
            return $result !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Daten vom Benutzer lesen
     * 
     * @param int $maxLen Die maximale Lesegröße
     * @return string|false Die gelesenen Daten oder false bei Fehler/Verbindungsabbruch
     */
    public function read(int $maxLen = 512) {
        try {
            // Daten vom Socket lesen
            $data = @socket_read($this->socket, $maxLen);
            
            // Bei false oder leerem String ist die Verbindung wahrscheinlich geschlossen
            if ($data === false || $data === '') {
                return false;
            }
            
            // Daten zum Puffer hinzufügen
            $this->buffer .= $data;
            
            // Wenn der Puffer einen Zeilenumbruch enthält, ersten Befehl zurückgeben
            $pos = strpos($this->buffer, "\n");
            if ($pos !== false) {
                $command = substr($this->buffer, 0, $pos);
                $this->buffer = substr($this->buffer, $pos + 1);
                return trim($command); // Steuerzeichen entfernen
            }
            
            // Kein vollständiger Befehl vorhanden
            return '';
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Verbindung schließen
     */
    public function disconnect(): void {
        socket_close($this->socket);
    }
    
    /**
     * Getter für das Socket-Objekt
     */
    public function getSocket() {
        return $this->socket;
    }
    
    /**
     * Getter und Setter für den Nicknamen
     */
    public function getNick(): ?string {
        return $this->nick;
    }
    
    public function setNick(string $nick): void {
        $this->nick = $nick;
        $this->checkRegistration();
    }
    
    /**
     * Getter und Setter für Ident
     */
    public function getIdent(): ?string {
        return $this->ident;
    }
    
    public function setIdent(string $ident): void {
        $this->ident = $ident;
        $this->checkRegistration();
    }
    
    /**
     * Getter und Setter für Realname
     */
    public function getRealname(): ?string {
        return $this->realname;
    }
    
    public function setRealname(string $realname): void {
        $this->realname = $realname;
        $this->checkRegistration();
    }
    
    /**
     * Getter für IP-Adresse
     */
    public function getIp(): string {
        return $this->ip;
    }
    
    /**
     * Getter und Setter für Host
     */
    public function getHost(): string {
        return $this->host;
    }
    
    public function setHost(string $host): void {
        $this->host = $host;
    }
    
    /**
     * Getter und Setter für Cloak (virtuelle Hostmaske)
     */
    public function getCloak(): string {
        return $this->cloak;
    }
    
    public function setCloak(string $cloak): void {
        $this->cloak = $cloak;
    }
    
    /**
     * Oper-Status setzen und abfragen
     */
    public function isOper(): bool {
        return $this->isOper;
    }
    
    public function setOper(bool $status): void {
        $this->isOper = $status;
    }
    
    /**
     * Prüft, ob alle notwendigen Daten für die Registration vorhanden sind
     */
    private function checkRegistration(): void {
        if (!$this->registered && $this->nick !== null && $this->ident !== null && $this->realname !== null) {
            $this->registered = true;
        }
    }
    
    /**
     * Prüft, ob der Benutzer vollständig registriert ist
     */
    public function isRegistered(): bool {
        return $this->registered;
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
     * Gibt zurück, ob der Benutzer inaktiv ist (Timeout)
     * 
     * @param int $timeout Die Zeitspanne in Sekunden, nach der ein Benutzer als inaktiv gilt
     * @return bool Ob der Benutzer inaktiv ist
     */
    public function isInactive(int $timeout): bool {
        return (time() - $this->lastActivity) > $timeout;
    }
    
    /**
     * Setzt oder entfernt einen Benutzer-Mode
     * 
     * @param string $mode Der Mode-Buchstabe
     * @param bool $value True zum Setzen, False zum Entfernen
     */
    public function setMode(string $mode, bool $value): void {
        if ($value) {
            $this->modes[$mode] = true;
        } else {
            unset($this->modes[$mode]);
        }
    }
    
    /**
     * Prüft, ob ein bestimmter Mode gesetzt ist
     * 
     * @param string $mode Der zu prüfende Mode-Buchstabe
     * @return bool Ob der Mode gesetzt ist
     */
    public function hasMode(string $mode): bool {
        return isset($this->modes[$mode]);
    }
    
    /**
     * Gibt alle gesetzten Modes als String zurück
     * 
     * @return string Die Modes als String
     */
    public function getModes(): string {
        return implode('', array_keys($this->modes));
    }
    
    /**
     * Setzt den Away-Status
     * 
     * @param string|null $message Die Away-Message oder null, wenn nicht away
     */
    public function setAway(?string $message): void {
        $this->away = $message;
    }
    
    /**
     * Prüft, ob der Benutzer away ist
     * 
     * @return bool Ob der Benutzer away ist
     */
    public function isAway(): bool {
        return $this->away !== null;
    }
    
    /**
     * Gibt die Away-Message zurück
     * 
     * @return string|null Die Away-Message oder null, wenn nicht away
     */
    public function getAwayMessage(): ?string {
        return $this->away;
    }
}