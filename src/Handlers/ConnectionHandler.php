<?php

namespace PhpIrcd\Handlers;

use PhpIrcd\Core\Server;
use PhpIrcd\Models\User;
use PhpIrcd\Models\Channel;

class ConnectionHandler {
    private $server;
    private $commandHandlers = [];
    private $commandCounts = [];
    private $inactivityTimeout = 240; // 4 minutes inactivity timeout
    
    /**
     * Constructor
     * 
     * @param Server $server The server instance
     */
    public function __construct(Server $server) {
        $this->server = $server;
        $this->initCommandHandlers();
    }
    
    /**
     * Initializes the command handlers
     */
    private function initCommandHandlers(): void {
        // Register command handlers
        $this->registerCommandHandler('PASS', new \PhpIrcd\Commands\PassCommand($this->server));
        $this->registerCommandHandler('NICK', new \PhpIrcd\Commands\NickCommand($this->server));
        $this->registerCommandHandler('USER', new \PhpIrcd\Commands\UserCommand($this->server));
        $this->registerCommandHandler('QUIT', new \PhpIrcd\Commands\QuitCommand($this->server));
        $this->registerCommandHandler('JOIN', new \PhpIrcd\Commands\JoinCommand($this->server));
        $this->registerCommandHandler('PART', new \PhpIrcd\Commands\PartCommand($this->server));
        $this->registerCommandHandler('PRIVMSG', new \PhpIrcd\Commands\PrivmsgCommand($this->server));
        $this->registerCommandHandler('NOTICE', new \PhpIrcd\Commands\NoticeCommand($this->server));
        $this->registerCommandHandler('MODE', new \PhpIrcd\Commands\ModeCommand($this->server));
        $this->registerCommandHandler('TOPIC', new \PhpIrcd\Commands\TopicCommand($this->server));
        $this->registerCommandHandler('INVITE', new \PhpIrcd\Commands\InviteCommand($this->server));
        $this->registerCommandHandler('KICK', new \PhpIrcd\Commands\KickCommand($this->server));
        $this->registerCommandHandler('WHOIS', new \PhpIrcd\Commands\WhoisCommand($this->server));
        $this->registerCommandHandler('PING', new \PhpIrcd\Commands\PingCommand($this->server));
        $this->registerCommandHandler('PONG', new \PhpIrcd\Commands\PongCommand($this->server));
        $this->registerCommandHandler('OPER', new \PhpIrcd\Commands\OperCommand($this->server));
        $this->registerCommandHandler('AWAY', new \PhpIrcd\Commands\AwayCommand($this->server));
        $this->registerCommandHandler('LIST', new \PhpIrcd\Commands\ListCommand($this->server));
        $this->registerCommandHandler('NAMES', new \PhpIrcd\Commands\NamesCommand($this->server));
        $this->registerCommandHandler('WHO', new \PhpIrcd\Commands\WhoCommand($this->server));
        $this->registerCommandHandler('MOTD', new \PhpIrcd\Commands\MotdCommand($this->server));
        $this->registerCommandHandler('CAP', new \PhpIrcd\Commands\CapCommand($this->server));
        $this->registerCommandHandler('VERSION', new \PhpIrcd\Commands\VersionCommand($this->server));
        $this->registerCommandHandler('TIME', new \PhpIrcd\Commands\TimeCommand($this->server));
        $this->registerCommandHandler('STATS', new \PhpIrcd\Commands\StatsCommand($this->server));
        $this->registerCommandHandler('REHASH', new \PhpIrcd\Commands\RehashCommand($this->server));
        $this->registerCommandHandler('REGISTER', new \PhpIrcd\Commands\RegisterCommand($this->server));
        $this->registerCommandHandler('UNREGISTER', new \PhpIrcd\Commands\UnregisterCommand($this->server));
        $this->registerCommandHandler('SASL', new \PhpIrcd\Commands\SaslCommand($this->server));
        $this->registerCommandHandler('AUTHENTICATE', new \PhpIrcd\Commands\SaslCommand($this->server));
        $this->registerCommandHandler('KILL', new \PhpIrcd\Commands\KillCommand($this->server));
        
        // Neue Befehle registrieren
        $this->registerCommandHandler('ADMIN', new \PhpIrcd\Commands\AdminCommand($this->server));
        $this->registerCommandHandler('INFO', new \PhpIrcd\Commands\InfoCommand($this->server));
        $this->registerCommandHandler('WALLOPS', new \PhpIrcd\Commands\WallopsCommand($this->server));
        $this->registerCommandHandler('USERHOST', new \PhpIrcd\Commands\UserhostCommand($this->server));
        $this->registerCommandHandler('ISON', new \PhpIrcd\Commands\IsonCommand($this->server));
        $this->registerCommandHandler('WHOWAS', new \PhpIrcd\Commands\WhowasCommand($this->server));
        $this->registerCommandHandler('LINKS', new \PhpIrcd\Commands\LinksCommand($this->server));
        $this->registerCommandHandler('LUSERS', new \PhpIrcd\Commands\LusersCommand($this->server));
        $this->registerCommandHandler('SILENCE', new \PhpIrcd\Commands\SilenceCommand($this->server));
        $this->registerCommandHandler('KNOCK', new \PhpIrcd\Commands\KnockCommand($this->server));
        $this->registerCommandHandler('SERVICE', new \PhpIrcd\Commands\ServiceCommand($this->server));
        $this->registerCommandHandler('SQUIT', new \PhpIrcd\Commands\SquitCommand($this->server));
        $this->registerCommandHandler('WATCH', new \PhpIrcd\Commands\WatchCommand($this->server));
    }
    
    /**
     * Registers a command handler
     * 
     * @param string $command The command name
     * @param object $handler The handler
     */
    public function registerCommandHandler(string $command, $handler): void {
        $this->commandHandlers[strtoupper($command)] = $handler;
    }
    
    /**
     * Accepts new connections
     * 
     * @param mixed $serverSocket The server socket (can be a Socket resource or a stream resource)
     */
    public function acceptNewConnections($serverSocket): void {
        // Check if we're dealing with a stream socket (SSL) or regular socket
        $isStreamSocket = !($serverSocket instanceof \Socket);
        
        if ($isStreamSocket) {
            // Handle stream socket (SSL)
            $newSocket = @stream_socket_accept($serverSocket, 0); // Non-blocking accept
            
            if ($newSocket !== false) {
                try {
                    // Get the peer name for the stream socket
                    $peerName = stream_socket_get_name($newSocket, true);
                    $ip = parse_url($peerName, PHP_URL_HOST) ?: explode(':', $peerName)[0];
                    
                    // Prüfen, ob die IP-Adresse zugreifen darf
                    if (!$this->isIpAllowed($ip)) {
                        $this->server->getLogger()->info("Verbindung von blockierter IP abgelehnt: {$ip}");
                        fwrite($newSocket, "ERROR :Your IP address is not allowed to connect to this server\r\n");
                        fclose($newSocket);
                        return;
                    }
                    
                    // Create a new user
                    $user = new User($newSocket, $ip, true); // Pass true to indicate it's a stream socket
                    
                    // Send welcome messages
                    $this->sendWelcomeMessages($user);
                } catch (\Exception $e) {
                    $this->server->getLogger()->error("Error accepting stream connection: " . $e->getMessage());
                    if (is_resource($newSocket)) {
                        fclose($newSocket);
                    }
                }
            }
        } else {
            // Handle regular socket
            $newSocket = socket_accept($serverSocket);
            if ($newSocket !== false) {
                try {
                    // Determine the IP address of the new user
                    socket_getpeername($newSocket, $ip);
                    
                    // Prüfen, ob die IP-Adresse zugreifen darf
                    if (!$this->isIpAllowed($ip)) {
                        $this->server->getLogger()->info("Verbindung von blockierter IP abgelehnt: {$ip}");
                        socket_write($newSocket, "ERROR :Your IP address is not allowed to connect to this server\r\n");
                        socket_close($newSocket);
                        return;
                    }
                    
                    // Create a new user
                    $user = new User($newSocket, $ip);
                    
                    // Send welcome messages
                    $this->sendWelcomeMessages($user);
                } catch (\Exception $e) {
                    $this->server->getLogger()->error("Error accepting connection: " . $e->getMessage());
                    if ($newSocket instanceof \Socket) {
                        socket_close($newSocket);
                    }
                }
            }
        }
    }
    
    /**
     * Prüft, ob eine IP-Adresse erlaubt ist
     * 
     * @param string $ip Die zu überprüfende IP-Adresse
     * @return bool True, wenn die IP-Adresse erlaubt ist
     */
    private function isIpAllowed(string $ip): bool {
        $config = $this->server->getConfig();
        
        // IP-Filterung deaktiviert, alle IPs erlauben
        if (!isset($config['ip_filtering_enabled']) || $config['ip_filtering_enabled'] !== true) {
            return true;
        }
        
        $mode = $config['ip_filter_mode'] ?? 'blacklist';
        
        // Blacklist-Modus: Prüfen, ob die IP in der Blacklist steht
        if ($mode === 'blacklist') {
            $blacklist = $config['ip_blacklist'] ?? [];
            
            foreach ($blacklist as $blockedIp) {
                // Exakte IP-Übereinstimmung
                if ($blockedIp === $ip) {
                    return false;
                }
                
                // CIDR-Notation überprüfen (z.B. 192.168.1.0/24)
                if (strpos($blockedIp, '/') !== false) {
                    if ($this->isIpInCidrRange($ip, $blockedIp)) {
                        return false;
                    }
                }
                
                // Wildcard-Notation überprüfen (z.B. 192.168.1.*)
                if (strpos($blockedIp, '*') !== false) {
                    $pattern = '/^' . str_replace(['.', '*'], ['\.', '.*'], $blockedIp) . '$/i';
                    if (preg_match($pattern, $ip)) {
                        return false;
                    }
                }
            }
            
            // IP ist nicht in der Blacklist
            return true;
        }
        
        // Whitelist-Modus: Prüfen, ob die IP in der Whitelist steht
        $whitelist = $config['ip_whitelist'] ?? [];
        
        // Keine Einträge in der Whitelist bedeutet, dass keine IP erlaubt ist
        if (empty($whitelist)) {
            return false;
        }
        
        foreach ($whitelist as $allowedIp) {
            // Exakte IP-Übereinstimmung
            if ($allowedIp === $ip) {
                return true;
            }
            
            // CIDR-Notation überprüfen
            if (strpos($allowedIp, '/') !== false) {
                if ($this->isIpInCidrRange($ip, $allowedIp)) {
                    return true;
                }
            }
            
            // Wildcard-Notation überprüfen
            if (strpos($allowedIp, '*') !== false) {
                $pattern = '/^' . str_replace(['.', '*'], ['\.', '.*'], $allowedIp) . '$/i';
                if (preg_match($pattern, $ip)) {
                    return true;
                }
            }
        }
        
        // IP ist nicht in der Whitelist
        return false;
    }
    
    /**
     * Prüft, ob eine IP-Adresse innerhalb eines CIDR-Bereichs liegt
     * Unterstützt sowohl IPv4 als auch IPv6
     * 
     * @param string $ip Die zu überprüfende IP-Adresse
     * @param string $cidr Der CIDR-Bereich (z.B. "192.168.1.0/24" oder "2001:db8::/32")
     * @return bool True, wenn die IP im Bereich liegt
     */
    private function isIpInCidrRange(string $ip, string $cidr): bool {
        // CIDR aufteilen in Netzwerk-Teil und Präfix-Länge
        if (strpos($cidr, '/') === false) {
            return false;
        }
        
        list($subnet, $bits) = explode('/', $cidr);
        $bits = (int)$bits;
        
        // IPv6-Format prüfen
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && 
            filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            
            // Prüfen, ob die Präfix-Länge gültig ist (0-128 für IPv6)
            if ($bits < 0 || $bits > 128) {
                return false;
            }
            
            // IPv6-Adresse in binäre Form umwandeln
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }
            
            // Byteweise vergleichen
            $ipLen = strlen($ipBin);
            $fullBytes = (int)($bits / 8);
            $partialBits = $bits % 8;
            
            // Vollständige Bytes vergleichen
            for ($i = 0; $i < $fullBytes && $i < $ipLen; $i++) {
                if ($ipBin[$i] !== $subnetBin[$i]) {
                    return false;
                }
            }
            
            // Wenn es noch übrige Bits gibt und wir nicht am Ende sind
            if ($partialBits > 0 && $fullBytes < $ipLen) {
                $mask = 0xFF & (0xFF << (8 - $partialBits));
                if (($ipBin[$fullBytes] & chr($mask)) !== ($subnetBin[$fullBytes] & chr($mask))) {
                    return false;
                }
            }
            
            return true;
        } 
        // IPv4-Format prüfen
        else if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && 
                filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            
            // Prüfen, ob die Präfix-Länge gültig ist (0-32 für IPv4)
            if ($bits < 0 || $bits > 32) {
                return false;
            }
            
            // IPv4-Adressen in Binärform umwandeln
            $ipBinary = ip2long($ip);
            $subnetBinary = ip2long($subnet);
            
            if ($ipBinary === false || $subnetBinary === false) {
                return false;
            }
            
            // Maske aus den Bits erstellen
            $mask = -1 << (32 - $bits);
            
            // Prüfen, ob die IP im Subnetz liegt
            return ($ipBinary & $mask) === ($subnetBinary & $mask);
        }
        
        // Wenn die IP-Adressen weder IPv4 noch IPv6 sind
        return false;
    }
    
    /**
     * Sends welcome messages to a user
     * 
     * @param User $user The user
     */
    private function sendWelcomeMessages(User $user): void {
        $config = $this->server->getConfig();
        $user->send(":{$config['name']} NOTICE AUTH :*** Looking up your hostname...");
        $user->send(":{$config['name']} NOTICE AUTH :*** Found your hostname");
        
        // Add user to the server
        $this->server->addUser($user);
    }
    
    /**
     * Handles existing connections
     */
    public function handleExistingConnections(): void {
        $users = $this->server->getUsers();
        $currentTime = time();
        
        foreach ($users as $user) {
            // Check and disconnect inactive connections
            if ($user->isInactive($this->inactivityTimeout)) {
                $this->disconnectUser($user, "Ping timeout: {$this->inactivityTimeout} seconds");
                continue;
            }
            
            // Read data from the user
            $data = $user->read();
            
            // Connection closed or error
            if ($data === false) {
                $this->disconnectUser($user, "Connection closed");
                continue;
            }
            
            // No complete command available
            if ($data === '') {
                continue;
            }
            
            // Register activity
            $user->updateActivity();
            
            // Process command
            $this->processCommand($user, $data);
        }
        
        // Send ping to users who need it
        $this->pingUsers();
    }
    
    /**
     * Processes a received command
     * 
     * @param User $user The sending user
     * @param string $data The received data
     */
    private function processCommand(User $user, string $data): void {
        // Parse command
        $parts = explode(' ', $data);
        $command = strtoupper($parts[0]);
        
        // Track command usage
        if (!isset($this->commandCounts[$command])) {
            $this->commandCounts[$command] = 0;
        }
        $this->commandCounts[$command]++;
        
        // Handle command
        if (isset($this->commandHandlers[$command])) {
            $this->commandHandlers[$command]->execute($user, $parts);
        } else {
            // Unknown command
            $config = $this->server->getConfig();
            $nick = $user->getNick() ?? '*';
            $user->send(":{$config['name']} 421 {$nick} {$command} :Unknown command");
        }
    }
    
    /**
     * Sends pings to users who need them
     */
    private function pingUsers(): void {
        $users = $this->server->getUsers();
        $config = $this->server->getConfig();
        $currentTime = time();
        
        foreach ($users as $user) {
            // If the user has been inactive for 90 seconds, send a ping
            if ($currentTime - $user->getLastActivity() > 90) {
                $user->send(":{$config['name']} PING :{$config['name']}");
            }
        }
    }
    
    /**
     * Disconnects a user
     * 
     * @param User $user The user to disconnect
     * @param string $reason The reason for disconnection
     */
    public function disconnectUser(User $user, string $reason): void {
        // Benutzer zur WHOWAS-Historie hinzufügen, wenn er registriert war
        if ($user->isRegistered()) {
            $this->server->addToWhowasHistory($user);
            
            // Send WATCH notifications that the user is now offline before removing from server
            if ($user->getNick() !== null) {
                $this->server->broadcastWatchNotifications($user, false);
            }
        }
        
        // Notify all channels the user is in
        $channels = $this->server->getChannels();
        foreach ($channels as $channel) {
            if ($channel->hasUser($user)) {
                // Send message to all other users in the channel
                $nick = $user->getNick() ?? '*';
                $ident = $user->getIdent() ?? 'unknown';
                $quitMessage = ":{$nick}!{$ident}@{$user->getCloak()} QUIT :{$reason}";
                
                foreach ($channel->getUsers() as $channelUser) {
                    if ($channelUser !== $user) {
                        $channelUser->send($quitMessage);
                    }
                }
                
                // Remove user from the channel
                $channel->removeUser($user);
                
                // If the channel is empty and not permanent, remove it
                if (count($channel->getUsers()) === 0 && !$channel->isPermanent()) {
                    $this->server->removeChannel($channel->getName());
                }
            }
        }
        
        // Close user socket
        $user->disconnect();
        
        // Remove user from the server
        $this->server->removeUser($user);
    }
    
    /**
     * Get command usage statistics
     * 
     * @return array Command usage counts
     */
    public function getCommandCounts(): array {
        return $this->commandCounts;
    }
}