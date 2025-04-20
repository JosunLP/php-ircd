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
    private $connectTime;  // Neu: Verbindungszeit speichern
    private $modes = [];
    private $away = null;
    private $isStreamSocket = false; // Flag für Stream-Socket (SSL)
    private $password = null; // Neu: Passwort für spätere Auth speichern
    private $saslInProgress = false; // Neu: SASL-Authentifizierung läuft
    private $saslAuthenticated = false; // Neu: SASL-Authentifizierung erfolgreich
    private $capabilities = []; // Neu: Aktivierte IRCv3 Capabilities
    private $silencedMasks = []; // Neu: Liste von ignorierten User-Masken (SILENCE)
    private $watchList = []; // Neu: Liste von beobachteten Nicknames (WATCH)
    private $saslMechanism = null; // Speichert den SASL-Mechanismus während der Authentifizierung
    private $capabilityNegotiationInProgress = false; // Ob CAP-Verhandlung gerade läuft
    private $isRemoteUser = false; // Neu: Flag für Remote-Benutzer
    private $remoteServer = null; // Neu: Name des Remote-Servers
    private $server = null; // Neu: Referenz auf den Server, in dem der Benutzer registriert ist
    private $undergoing302Negotiation = false; // Flag für IRCv3.2 (302) CAP-Verhandlung
    
    /**
     * Constructor
     * 
     * @param mixed $socket The user's connection (Socket or stream resource)
     * @param string $ip The user's IP address
     * @param bool $isStreamSocket Whether the socket is a stream socket (SSL)
     */
    public function __construct($socket, string $ip, bool $isStreamSocket = false) {
        $this->socket = $socket;
        $this->ip = $ip;
        $this->host = $this->lookupHostname($ip);
        $this->cloak = $this->host; // Initial value, can be changed later
        $this->lastActivity = time();
        $this->connectTime = time();  // Neu: Zeitpunkt der Verbindung setzen
        $this->isStreamSocket = $isStreamSocket;
        
        // Set socket to non-blocking
        if ($isStreamSocket) {
            stream_set_blocking($this->socket, false);
        } else {
            socket_set_nonblock($this->socket);
        }
    }
    
    /**
     * Hostname lookup with timeout
     * 
     * @param string $ip The IP address
     * @return string The hostname or the IP if the lookup fails
     */
    private function lookupHostname(string $ip): string {
        // Set a short timeout for DNS lookup to avoid hanging
        $origTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '3'); // 3 seconds timeout
        
        try {
            // Try to use non-blocking hostname lookup if possible
            if (function_exists('gethostbyaddr_async')) {
                $hostname = gethostbyaddr_async($ip, 3); // 3 seconds timeout
            } else {
                $hostname = gethostbyaddr($ip);
            }
            
            // Validate hostname format
            if ($hostname && $hostname !== $ip && preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $hostname)) {
                return $hostname;
            }
        } catch (\Exception $e) {
            // Fallback to IP on any error
        } finally {
            // Reset original timeout
            ini_set('default_socket_timeout', $origTimeout);
        }
        
        return $ip;
    }
    
    /**
     * Send data to the user
     * 
     * @param string $data The data to send
     * @return bool Success of sending
     */
    public function send(string $data): bool {
        if (!$this->isSocketValid()) {
            return false;
        }
        
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
     * Read data from the user
     * 
     * @param int $maxLen The maximum read size
     * @return string|false The read data or false on error/connection loss
     */
    public function read(int $maxLen = 512) {
        if (!$this->isSocketValid()) {
            return false;
        }
        
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
                // Manche IRC-Clients senden nur \r als Zeilenende
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
     * Validate if the socket is still valid
     * 
     * @return bool Whether the socket is valid
     */
    public function isSocketValid(): bool {
        if ($this->isStreamSocket) {
            return is_resource($this->socket) && !feof($this->socket);
        } else {
            // Check if the socket is still a valid socket resource
            return $this->socket instanceof \Socket && @socket_get_option($this->socket, SOL_SOCKET, SO_ERROR) !== false;
        }
    }
    
    /**
     * Close connection
     */
    public function disconnect(): void {
        try {
            if ($this->isStreamSocket) {
                if (is_resource($this->socket)) {
                    @fclose($this->socket);
                }
            } else {
                if ($this->socket instanceof \Socket) {
                    @socket_close($this->socket);
                }
            }
        } catch (\Exception $e) {
            // Ignore exceptions during disconnect
        } finally {
            // Reset the socket reference to prevent further use
            $this->socket = null;
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
     * And validate the registration data
     */
    private function checkRegistration(): void {
        if (!$this->registered && 
            $this->nick !== null && 
            $this->ident !== null && 
            $this->realname !== null) {
            
            // Basic validation of user info
            if ($this->validateUserInfo()) {
                $this->registered = true;
                $this->updateActivity(); // Update activity timestamp on registration
            }
        }
    }
    
    /**
     * Validate user information
     * 
     * @return bool Whether the user information is valid
     */
    private function validateUserInfo(): bool {
        // Nickname format validation - alphanumeric plus some special chars
        if (!preg_match('/^[a-zA-Z\[\]\\`_\^{|}][a-zA-Z0-9\[\]\\`_\^{|}-]{0,29}$/', $this->nick)) {
            return false;
        }
        
        // Ident format validation
        if (!preg_match('/^[a-zA-Z0-9~._-]{1,12}$/', $this->ident)) {
            return false;
        }
        
        // Realname only length validation (can contain spaces and special chars)
        if (strlen($this->realname) > 50) {
            return false;
        }
        
        return true;
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

    /**
     * Set the user's password
     * 
     * @param string $password The password
     */
    public function setPassword(string $password): void {
        $this->password = $password;
    }
    
    /**
     * Get the user's password
     * 
     * @return string|null The password or null if not set
     */
    public function getPassword(): ?string {
        return $this->password;
    }

    /**
     * Returns the timestamp when the user connected
     * 
     * @return int The Unix timestamp
     */
    public function getConnectTime(): int {
        return $this->connectTime;
    }

    /**
     * Checks if SASL authentication is in progress
     * 
     * @return bool Whether SASL authentication is in progress
     */
    public function isSaslInProgress(): bool {
        return $this->saslInProgress;
    }
    
    /**
     * Sets the SASL authentication progress state
     * 
     * @param bool $inProgress Whether SASL authentication is in progress
     */
    public function setSaslInProgress(bool $inProgress): void {
        $this->saslInProgress = $inProgress;
    }
    
    /**
     * Checks if the user is authenticated via SASL
     * 
     * @return bool Whether the user is authenticated via SASL
     */
    public function isSaslAuthenticated(): bool {
        return $this->saslAuthenticated;
    }
    
    /**
     * Sets the SASL authentication state
     * 
     * @param bool $authenticated Whether the user is authenticated via SASL
     */
    public function setSaslAuthenticated(bool $authenticated): void {
        $this->saslAuthenticated = $authenticated;
    }
    
    /**
     * Adds a capability to the user's active capabilities
     * 
     * @param string $capability The capability to add
     */
    public function addCapability(string $capability): void {
        if (!in_array($capability, $this->capabilities)) {
            $this->capabilities[] = $capability;
        }
    }
    
    /**
     * Removes a capability from the user's active capabilities
     * 
     * @param string $capability The capability to remove
     */
    public function removeCapability(string $capability): void {
        $key = array_search($capability, $this->capabilities);
        if ($key !== false) {
            unset($this->capabilities[$key]);
            $this->capabilities = array_values($this->capabilities);
        }
    }
    
    /**
     * Gets the user's active capabilities
     * 
     * @return array The active capabilities
     */
    public function getCapabilities(): array {
        return $this->capabilities;
    }
    
    /**
     * Checks if the user has a specific capability enabled
     * 
     * @param string $capability The capability to check
     * @return bool Whether the user has the capability
     */
    public function hasCapability(string $capability): bool {
        return in_array($capability, $this->capabilities);
    }

    /**
     * Setzt oder entfernt den CAP-Verhandlungs-Status
     * 
     * @param bool $inProgress Ob CAP-Verhandlung im Gange ist
     */
    public function setCapabilityNegotiationInProgress(bool $inProgress): void {
        $this->capabilityNegotiationInProgress = $inProgress;
    }
    
    /**
     * Prüft, ob die CAP-Verhandlung im Gange ist
     * 
     * @return bool
     */
    public function isCapabilityNegotiationInProgress(): bool {
        return $this->capabilityNegotiationInProgress;
    }
    
    /**
     * Entfernt alle aktivierten Capabilities
     */
    public function clearCapabilities(): void {
        $this->capabilities = [];
    }

    /**
     * Get the list of silenced masks
     * 
     * @return array The list of silenced masks
     */
    public function getSilencedMasks(): array {
        return $this->silencedMasks;
    }
    
    /**
     * Add a mask to the silence list
     * 
     * @param string $mask The mask to add
     * @return bool Success (false if already at maximum entries)
     */
    public function addSilencedMask(string $mask): bool {
        // Prüfen, ob die Maske bereits existiert
        if (in_array($mask, $this->silencedMasks)) {
            return true; // Maske bereits vorhanden
        }
        
        // Maximale Anzahl von SILENCE-Einträgen (15 nach RFC)
        if (count($this->silencedMasks) >= 15) {
            return false;
        }
        
        // Maske hinzufügen
        $this->silencedMasks[] = $mask;
        return true;
    }
    
    /**
     * Remove a mask from the silence list
     * 
     * @param string $mask The mask to remove
     * @return bool Whether the mask was removed
     */
    public function removeSilencedMask(string $mask): bool {
        $key = array_search($mask, $this->silencedMasks);
        if ($key !== false) {
            unset($this->silencedMasks[$key]);
            $this->silencedMasks = array_values($this->silencedMasks);
            return true;
        }
        return false;
    }
    
    /**
     * Check if a user matches any of the silenced masks
     * 
     * @param User $sender The user to check
     * @return bool Whether the user is silenced
     */
    public function isSilenced(User $sender): bool {
        if (empty($this->silencedMasks)) {
            return false;
        }
        
        $fullMask = $sender->getNick() . "!" . $sender->getIdent() . "@" . $sender->getHost();
        
        foreach ($this->silencedMasks as $mask) {
            // Einfacher Mask-Vergleich mit Wildcards (* und ?)
            if ($this->matchesMask($fullMask, $mask)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Helper to match a string against an IRC mask with wildcards
     * 
     * @param string $string The string to check
     * @param string $mask The mask with wildcards
     * @return bool Whether the string matches the mask
     */
    private function matchesMask(string $string, string $mask): bool {
        // Escape all regex special chars except * and ?
        $mask = preg_quote($mask, '/');
        
        // Convert IRC wildcards to regex wildcards
        $mask = str_replace(['\\*', '\\?'], ['.*', '.'], $mask);
        
        // Check if the string matches the mask
        return (bool) preg_match('/^' . $mask . '$/i', $string);
    }

    /**
     * Get the watch list
     * 
     * @return array The list of watched nicknames
     */
    public function getWatchList(): array {
        return $this->watchList;
    }
    
    /**
     * Add a nickname to the watch list
     * 
     * @param string $nickname The nickname to watch
     * @return bool Success
     */
    public function addToWatchList(string $nickname): bool {
        $nickname = strtolower($nickname); // Case-insensitive storage
        
        // Check if already watching this nickname
        if (in_array($nickname, $this->watchList)) {
            return true;
        }
        
        // Check for maximum watch list size
        if (count($this->watchList) >= 128) { // Maximum specified in ISUPPORT
            return false;
        }
        
        $this->watchList[] = $nickname;
        return true;
    }
    
    /**
     * Remove a nickname from the watch list
     * 
     * @param string $nickname The nickname to remove
     * @return bool Whether the nickname was removed
     */
    public function removeFromWatchList(string $nickname): bool {
        $nickname = strtolower($nickname); // Case-insensitive search
        
        $key = array_search($nickname, $this->watchList);
        if ($key !== false) {
            unset($this->watchList[$key]);
            $this->watchList = array_values($this->watchList); // Reindex array
            return true;
        }
        return false;
    }
    
    /**
     * Clear the watch list
     */
    public function clearWatchList(): void {
        $this->watchList = [];
    }
    
    /**
     * Setter und Getter für SASL-Mechanismus
     */
    public function setSaslMechanism(string $mechanism): void {
        $this->saslMechanism = $mechanism;
    }
    
    public function getSaslMechanism(): ?string {
        return $this->saslMechanism;
    }
    
    /**
     * Prüft, ob die Verbindung über SSL/TLS gesichert ist
     */
    public function isSecureConnection(): bool {
        return $this->isStreamSocket;
    }

    /**
     * Set the remote user flag
     * 
     * @param bool $isRemoteUser Whether the user is remote
     */
    public function setRemoteUser(bool $isRemoteUser): void {
        $this->isRemoteUser = $isRemoteUser;
    }

    /**
     * Check if the user is remote
     * 
     * @return bool Whether the user is remote
     */
    public function isRemoteUser(): bool {
        return $this->isRemoteUser;
    }

    /**
     * Set the remote server name
     * 
     * @param string|null $remoteServer The name of the remote server
     */
    public function setRemoteServer(?string $remoteServer): void {
        $this->remoteServer = $remoteServer;
    }

    /**
     * Get the remote server name
     * 
     * @return string|null The name of the remote server
     */
    public function getRemoteServer(): ?string {
        return $this->remoteServer;
    }

    /**
     * Set the server instance that created this user
     * 
     * @param mixed $server The server instance
     */
    public function setServer($server): void {
        $this->server = $server;
    }

    /**
     * Get the server instance that created this user
     * 
     * @return mixed The server instance
     */
    public function getServer() {
        return $this->server;
    }

    /**
     * Get the full IRC mask of the user in standard format
     * 
     * @return string The IRC mask in format nick!ident@host
     */
    public function getMask(): string {
        return $this->nick . '!' . $this->ident . '@' . $this->cloak;
    }

    /**
     * Gibt einen eindeutigen Identifikator für den Benutzer zurück
     * Verwendet eine stabile ID für den Benutzer, nicht nur den Nickname
     * 
     * @return string Der eindeutige Identifikator
     */
    public function getId(): string {
        // Falls kein Nickname vorhanden, verwenden wir eine Kombination aus IP und Verbindungszeit
        if ($this->nick === null) {
            return 'user_' . md5($this->ip . '_' . $this->connectTime);
        }
        
        // Ansonsten verwenden wir eine Kombination aus Nickname und Verbindungszeit
        // So bleibt die ID auch bei Nickname-Änderungen konsistent
        return 'user_' . md5($this->nick . '_' . $this->connectTime);
    }

    /**
     * Authenticate user with SASL
     * 
     * @param string $mechanism SASL mechanism
     * @param string $data Authentication data
     * @return bool Success of authentication
     */
    public function authenticateWithSasl(string $mechanism, string $data): bool {
        if (!$this->saslInProgress) {
            return false;
        }
        
        // Get server configuration
        if (!$this->server || !method_exists($this->server, 'getConfig')) {
            return false;
        }
        
        $config = $this->server->getConfig();
        if (!isset($config['sasl_enabled']) || !$config['sasl_enabled']) {
            return false;
        }
        
        // Check if the mechanism is supported
        if (!isset($config['sasl_mechanisms']) || 
            !is_array($config['sasl_mechanisms']) || 
            !in_array($mechanism, $config['sasl_mechanisms'])) {
            return false;
        }
        
        // Process by mechanism
        switch (strtoupper($mechanism)) {
            case 'PLAIN':
                return $this->authenticateWithSaslPlain($data, $config);
                
            case 'EXTERNAL':
                return $this->authenticateWithSaslExternal($config);
                
            default:
                return false;
        }
    }
    
    /**
     * Authenticate with PLAIN SASL mechanism
     * 
     * @param string $data Base64 encoded authentication string
     * @param array $config Server configuration
     * @return bool Success of authentication
     */
    private function authenticateWithSaslPlain(string $data, array $config): bool {
        // Decode base64 data
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return false;
        }
        
        // PLAIN format: \0username\0password
        $parts = explode("\0", $decoded);
        if (count($parts) < 3) {
            return false;
        }
        
        // Extract username and password (ignore the first part, which is usually empty)
        $username = $parts[1];
        $password = $parts[2];
        
        // Check against SASL user database
        if (!isset($config['sasl_users']) || !is_array($config['sasl_users'])) {
            return false;
        }
        
        foreach ($config['sasl_users'] as $saslUser) {
            if (isset($saslUser['username']) && isset($saslUser['password']) && 
                $saslUser['username'] === $username && $saslUser['password'] === $password) {
                
                $this->saslAuthenticated = true;
                $this->setMode('r', true); // Set registered user mode
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Authenticate with EXTERNAL SASL mechanism (using SSL certificates)
     * 
     * @param array $config Server configuration
     * @return bool Success of authentication
     */
    private function authenticateWithSaslExternal(array $config): bool {
        // Check if the connection is secured
        if (!$this->isStreamSocket) {
            return false;
        }
        
        // In a real implementation, we would check the client certificate here
        // This is a simplified version that just checks if SSL is being used
        if ($this->isSecureConnection()) {
            $this->saslAuthenticated = true;
            $this->setMode('r', true); // Set registered user mode
            return true;
        }
        
        return false;
    }
    
    /**
     * Get the raw socket status
     * 
     * @return array|false Socket status or false if not available
     */
    public function getSocketStatus() {
        if (!$this->isSocketValid()) {
            return false;
        }
        
        if ($this->isStreamSocket) {
            return stream_get_meta_data($this->socket);
        } else {
            // Für normale Sockets gibt es kein direktes Äquivalent zu stream_get_meta_data
            // Stattdessen geben wir ein minimales Status-Array zurück
            return [
                'timed_out' => false,
                'blocked' => false,
                'eof' => false,
                'type' => 'socket'
            ];
        }
    }
    
    /**
     * Set socket timeout
     * 
     * @param int $seconds Timeout in seconds
     * @return bool Success of setting timeout
     */
    public function setSocketTimeout(int $seconds): bool {
        if (!$this->isSocketValid()) {
            return false;
        }
        
        try {
            if ($this->isStreamSocket) {
                return stream_set_timeout($this->socket, $seconds);
            } else {
                $timeout = ['sec' => $seconds, 'usec' => 0];
                return socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, $timeout) && 
                       socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, $timeout);
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Setzt den Status der IRCv3.2 CAP-Verhandlung
     * 
     * @param bool $isNegotiating Ob die 302-Verhandlung läuft
     */
    public function setUndergoing302Negotiation(bool $isNegotiating): void {
        $this->undergoing302Negotiation = $isNegotiating;
    }
    
    /**
     * Prüft, ob eine IRCv3.2 CAP-Verhandlung im Gange ist
     * 
     * @return bool Ob die 302-Verhandlung läuft
     */
    public function isUndergoing302Negotiation(): bool {
        return $this->undergoing302Negotiation;
    }
}