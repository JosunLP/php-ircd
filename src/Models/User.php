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
     * Constructor
     * 
     * @param \Socket $socket The user's connection
     * @param string $ip The user's IP address
     */
    public function __construct($socket, string $ip) {
        $this->socket = $socket;
        $this->ip = $ip;
        $this->host = $this->lookupHostname($ip);
        $this->cloak = $this->host; // Initial value, can be changed later
        $this->lastActivity = time();
        
        // Set socket to non-blocking
        socket_set_nonblock($this->socket);
    }
    
    /**
     * Hostname lookup with timeout
     * 
     * @param string $ip The IP address
     * @return string The hostname or the IP if the lookup fails
     */
    private function lookupHostname(string $ip): string {
        $hostname = gethostbyaddr($ip);
        return $hostname ?: $ip;
    }
    
    /**
     * Send data to the user
     * 
     * @param string $data The data to send
     * @return bool Success of sending
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
     * Read data from the user
     * 
     * @param int $maxLen The maximum read size
     * @return string|false The read data or false on error/connection loss
     */
    public function read(int $maxLen = 512) {
        try {
            // Read data from the socket
            $data = @socket_read($this->socket, $maxLen);
            
            // If false or empty string, the connection is likely closed
            if ($data === false || $data === '') {
                return false;
            }
            
            // Add data to the buffer
            $this->buffer .= $data;
            
            // If the buffer contains a newline, return the first command
            $pos = strpos($this->buffer, "\n");
            if ($pos !== false) {
                $command = substr($this->buffer, 0, $pos);
                $this->buffer = substr($this->buffer, $pos + 1);
                return trim($command); // Remove control characters
            }
            
            // No complete command available
            return '';
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Close connection
     */
    public function disconnect(): void {
        if ($this->socket instanceof \Socket) {
            socket_close($this->socket);
        }
    }
    
    /**
     * Getter for the socket object
     */
    public function getSocket() {
        return $this->socket;
    }
    
    /**
     * Getter and setter for the nickname
     */
    public function getNick(): ?string {
        return $this->nick;
    }
    
    public function setNick(string $nick): void {
        $this->nick = $nick;
        $this->checkRegistration();
    }
    
    /**
     * Getter and setter for ident
     */
    public function getIdent(): ?string {
        return $this->ident;
    }
    
    public function setIdent(string $ident): void {
        $this->ident = $ident;
        $this->checkRegistration();
    }
    
    /**
     * Getter and setter for realname
     */
    public function getRealname(): ?string {
        return $this->realname;
    }
    
    public function setRealname(string $realname): void {
        $this->realname = $realname;
        $this->checkRegistration();
    }
    
    /**
     * Getter for IP address
     */
    public function getIp(): string {
        return $this->ip;
    }
    
    /**
     * Getter and setter for host
     */
    public function getHost(): string {
        return $this->host;
    }
    
    public function setHost(string $host): void {
        $this->host = $host;
    }
    
    /**
     * Getter and setter for cloak (virtual host mask)
     */
    public function getCloak(): string {
        return $this->cloak;
    }
    
    public function setCloak(string $cloak): void {
        $this->cloak = $cloak;
    }
    
    /**
     * Set and check oper status
     */
    public function isOper(): bool {
        return $this->isOper;
    }
    
    public function setOper(bool $status): void {
        $this->isOper = $status;
    }
    
    /**
     * Check if all necessary data for registration is available
     */
    private function checkRegistration(): void {
        if (!$this->registered && $this->nick !== null && $this->ident !== null && $this->realname !== null) {
            $this->registered = true;
        }
    }
    
    /**
     * Check if the user is fully registered
     */
    public function isRegistered(): bool {
        return $this->registered;
    }
    
    /**
     * Update the timestamp of the last activity
     */
    public function updateActivity(): void {
        $this->lastActivity = time();
    }
    
    /**
     * Return the timestamp of the last activity
     */
    public function getLastActivity(): int {
        return $this->lastActivity;
    }
    
    /**
     * Return whether the user is inactive (timeout)
     * 
     * @param int $timeout The time span in seconds after which a user is considered inactive
     * @return bool Whether the user is inactive
     */
    public function isInactive(int $timeout): bool {
        return (time() - $this->lastActivity) > $timeout;
    }
    
    /**
     * Set or remove a user mode
     * 
     * @param string $mode The mode letter
     * @param bool $value True to set, False to remove
     */
    public function setMode(string $mode, bool $value): void {
        if ($value) {
            $this->modes[$mode] = true;
        } else {
            unset($this->modes[$mode]);
        }
    }
    
    /**
     * Check if a specific mode is set
     * 
     * @param string $mode The mode letter to check
     * @return bool Whether the mode is set
     */
    public function hasMode(string $mode): bool {
        return isset($this->modes[$mode]);
    }
    
    /**
     * Return all set modes as a string
     * 
     * @return string The modes as a string
     */
    public function getModes(): string {
        return implode('', array_keys($this->modes));
    }
    
    /**
     * Set the away status
     * 
     * @param string|null $message The away message or null if not away
     */
    public function setAway(?string $message): void {
        $this->away = $message;
    }
    
    /**
     * Check if the user is away
     * 
     * @return bool Whether the user is away
     */
    public function isAway(): bool {
        return $this->away !== null;
    }
    
    /**
     * Return the away message
     * 
     * @return string|null The away message or null if not away
     */
    public function getAwayMessage(): ?string {
        return $this->away;
    }
}