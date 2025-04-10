<?php

namespace PhpIrcd\Handlers;

use PhpIrcd\Core\Server;
use PhpIrcd\Models\ServerLink;
use PhpIrcd\Models\Channel;
use PhpIrcd\Models\User;

class ServerLinkHandler {
    private $server;
    private $links = [];
    private $pingTimeout = 240; // 4 minutes timeout for server links
    
    /**
     * Constructor
     * 
     * @param Server $server The server instance
     */
    public function __construct(Server $server) {
        $this->server = $server;
    }
    
    /**
     * Accepts new server connections
     * 
     * @param mixed $serverSocket The server socket
     */
    public function acceptServerConnections($serverSocket): void {
        // Check if server-to-server connections are enabled
        $config = $this->server->getConfig();
        if (empty($config['enable_server_links']) || $config['enable_server_links'] !== true) {
            return;
        }
        
        // Check if it is a stream socket (SSL) or a regular socket
        $isStreamSocket = !($serverSocket instanceof \Socket);
        
        if ($isStreamSocket) {
            // Process stream socket (SSL)
            $newSocket = @stream_socket_accept($serverSocket, 0); // Non-blocking accept
            
            if ($newSocket !== false) {
                try {
                    // Determine peer name for the stream socket
                    $peerName = stream_socket_get_name($newSocket, true);
                    $ip = parse_url($peerName, PHP_URL_HOST) ?: explode(':', $peerName)[0];
                    
                    // Create new server link
                    $serverLink = new ServerLink($newSocket, 'unknown.server', '', true);
                    
                    // Add link to the server
                    $this->addServerLink($serverLink);
                    
                    // Send welcome message
                    $this->sendWelcomeToServer($serverLink);
                } catch (\Exception $e) {
                    $this->server->getLogger()->error("Error accepting stream socket connection: " . $e->getMessage());
                    if (is_resource($newSocket)) {
                        fclose($newSocket);
                    }
                }
            }
        } else {
            // Process regular socket
            $newSocket = socket_accept($serverSocket);
            if ($newSocket !== false) {
                try {
                    // Determine IP address of the new server
                    socket_getpeername($newSocket, $ip);
                    
                    // Create new server link
                    $serverLink = new ServerLink($newSocket, 'unknown.server', '');
                    
                    // Add link to the server
                    $this->addServerLink($serverLink);
                    
                    // Send welcome message
                    $this->sendWelcomeToServer($serverLink);
                } catch (\Exception $e) {
                    $this->server->getLogger()->error("Error accepting socket connection: " . $e->getMessage());
                    if ($newSocket instanceof \Socket) {
                        socket_close($newSocket);
                    }
                }
            }
        }
    }
    
    /**
     * Sends a welcome message to a new server
     * 
     * @param ServerLink $serverLink The new server link
     */
    private function sendWelcomeToServer(ServerLink $serverLink): void {
        $config = $this->server->getConfig();
        $serverLink->send("NOTICE AUTH :*** Server-to-server connection initiated");
        
        // In a production environment, we would perform the PASS and SERVER handshake here
    }
    
    /**
     * Adds a new server link
     * 
     * @param ServerLink $serverLink The new server link
     */
    public function addServerLink(ServerLink $serverLink): void {
        $this->links[] = $serverLink;
        $this->server->getLogger()->info("New server-to-server connection established: " . $serverLink->getName());
    }
    
    /**
     * Processes existing server links
     */
    public function handleExistingServerLinks(): void {
        $currentTime = time();
        
        foreach ($this->links as $key => $serverLink) {
            // Check for timeout and disconnect inactive connections
            if ($serverLink->isInactive($this->pingTimeout)) {
                $this->disconnectServerLink($serverLink, "Ping timeout: {$this->pingTimeout} seconds");
                unset($this->links[$key]);
                continue;
            }
            
            // Read data from the server
            $command = $serverLink->readCommand();
            
            // Detect connection loss
            if ($command === false) {
                $this->disconnectServerLink($serverLink, "Connection closed");
                unset($this->links[$key]);
                continue;
            }
            
            // Process if a complete command is received
            if (!empty($command)) {
                $this->processServerCommand($serverLink, $command);
            }
        }
        
        // Reindex array
        $this->links = array_values($this->links);
        
        // Send pings to servers
        static $lastPingTime = 0;
        if ($currentTime - $lastPingTime > 90) { // Every 90 seconds
            $this->pingServers();
            $lastPingTime = $currentTime;
        }
    }
    
    /**
     * Processes a command from a server
     * 
     * @param ServerLink $serverLink The server link
     * @param string $command The received command
     */
    private function processServerCommand(ServerLink $serverLink, string $command): void {
        // Update activity timestamp
        $serverLink->updateActivity();
        
        // Split command into parts
        $parts = explode(' ', $command);
        $prefix = '';
        
        // If the command starts with a prefix, extract it
        if ($parts[0][0] === ':') {
            $prefix = substr(array_shift($parts), 1);
        }
        
        if (empty($parts)) {
            return; // Empty command
        }
        
        $commandType = strtoupper($parts[0]);
        
        // Process server commands
        switch ($commandType) {
            case 'PING':
                // Respond with PONG
                if (isset($parts[1])) {
                    $target = $parts[1];
                    $serverLink->send("PONG {$this->server->getConfig()['name']} {$target}");
                }
                break;
                
            case 'PONG':
                // PONG received, nothing to do
                break;
                
            case 'PASS':
                // Password authentication for server
                $this->handlePassCommand($serverLink, $parts);
                break;
                
            case 'SERVER':
                // SERVER command for server registration
                $this->handleServerCommand($serverLink, $parts);
                break;
                
            case 'SQUIT':
                // Server disconnection
                $this->handleSquitCommand($serverLink, $prefix, $parts);
                break;
                
            case 'NICK':
                // Nickname change or registration
                $this->handleNickCommand($serverLink, $prefix, $parts);
                break;
                
            case 'JOIN':
                // Channel join
                $this->handleJoinCommand($serverLink, $prefix, $parts);
                break;
                
            case 'PART':
                // Channel leave
                $this->handlePartCommand($serverLink, $prefix, $parts);
                break;
                
            case 'QUIT':
                // User disconnection
                $this->handleQuitCommand($serverLink, $prefix, $parts);
                break;
                
            case 'MODE':
                // Mode change
                $this->handleModeCommand($serverLink, $prefix, $parts);
                break;
                
            case 'TOPIC':
                // Topic change
                $this->handleTopicCommand($serverLink, $prefix, $parts);
                break;
                
            case 'PRIVMSG':
            case 'NOTICE':
                // Private message or notice
                $this->handleMessageCommand($serverLink, $prefix, $commandType, $parts);
                break;
                
            default:
                // Unknown or unimplemented command
                $this->server->getLogger()->debug("Unknown server command received: {$commandType}");
                break;
        }
    }
    
    /**
     * Processes the PASS command from a server
     * 
     * @param ServerLink $serverLink The server link
     * @param array $parts The command parts
     */
    private function handlePassCommand(ServerLink $serverLink, array $parts): void {
        // Check if enough parameters are present
        if (!isset($parts[1])) {
            return;
        }
        
        // Extract password
        $password = $parts[1];
        
        // Store password in the ServerLink
        $serverLink->setPassword($password);
    }
    
    /**
     * Processes the SERVER command from a server
     * 
     * @param ServerLink $serverLink The server link
     * @param array $parts The command parts
     */
    private function handleServerCommand(ServerLink $serverLink, array $parts): void {
        // Check if enough parameters are present
        if (!isset($parts[1]) || !isset($parts[2]) || !isset($parts[3])) {
            return;
        }
        
        // Extract server parameters
        $name = $parts[1];
        $hopCount = (int)$parts[2];
        
        // Extract info from the rest of the command (starts with :)
        $info = '';
        for ($i = 3; $i < count($parts); $i++) {
            if ($parts[$i][0] === ':') {
                $info = substr(implode(' ', array_slice($parts, $i)), 1);
                break;
            }
        }
        
        // Check authentication
        $config = $this->server->getConfig();
        $expectedPassword = $config['server_password'] ?? '';
        
        if ($serverLink->getPassword() !== $expectedPassword) {
            // Authentication failed
            $this->server->getLogger()->warning("Server authentication failed for {$name}");
            $this->disconnectServerLink($serverLink, "Authentication failed");
            return;
        }
        
        // Update server link
        $serverLink->setName($name);
        $serverLink->setDescription($info);
        $serverLink->setHopCount($hopCount);
        $serverLink->setConnected(true);
        
        // Send confirmation
        $serverLink->send(":{$config['name']} NOTICE {$name} :Server authentication successful");
        
        // Log
        $this->server->getLogger()->info("Server {$name} successfully authenticated");
    }
    
    /**
     * Processes the SQUIT command from a server
     * 
     * @param ServerLink $serverLink The server link
     * @param string $prefix The command prefix
     * @param array $parts The command parts
     */
    private function handleSquitCommand(ServerLink $serverLink, string $prefix, array $parts): void {
        // Check if enough parameters are present
        if (!isset($parts[1])) {
            return;
        }
        
        // Extract server name
        $targetServer = $parts[1];
        
        // Extract reason from the rest of the command (starts with :)
        $reason = 'No reason';
        for ($i = 2; $i < count($parts); $i++) {
            if ($parts[$i][0] === ':') {
                $reason = substr(implode(' ', array_slice($parts, $i)), 1);
                break;
            }
        }
        
        // If the target server is this server, disconnect the connection
        $config = $this->server->getConfig();
        if ($targetServer === $config['name']) {
            $this->disconnectServerLink($serverLink, "Remote SQUIT: {$reason}");
        }
    }
    
    /**
     * Processes the NICK command from a server
     * 
     * @param ServerLink $serverLink The server link
     * @param string $prefix The command prefix
     * @param array $parts The command parts
     */
    private function handleNickCommand(ServerLink $serverLink, string $prefix, array $parts): void {
        // NICK command from the server (user registration or name change)
        // Would perform synchronization of users between servers in a complete implementation
    }
    
    /**
     * Processes the JOIN command from a server
     * 
     * @param ServerLink $serverLink The server link
     * @param string $prefix The command prefix
     * @param array $parts The command parts
     */
    private function handleJoinCommand(ServerLink $serverLink, string $prefix, array $parts): void {
        // Check if enough parameters are present
        if (!isset($parts[1])) {
            return;
        }
        
        // If no prefix is present, we cannot do anything
        if (empty($prefix)) {
            return;
        }
        
        // Extract user information from the prefix (nick!user@host)
        $nickInfo = explode('!', $prefix, 2);
        $nick = $nickInfo[0];
        
        // Extract ident and host (if available)
        $identHost = '';
        if (isset($nickInfo[1])) {
            $identHost = $nickInfo[1];
        }
        
        // Extract channel name(s)
        $channels = explode(',', $parts[1]);
        
        // Get server configuration
        $config = $this->server->getConfig();
        
        // For each channel, forward the join message to local users
        foreach ($channels as $channelName) {
            // Check if the channel is valid
            if (!Channel::isValidChannelName($channelName)) {
                continue;
            }
            
            // Create or get the channel
            $channel = $this->server->getChannel($channelName);
            if ($channel === null) {
                $channel = new Channel($channelName);
                $this->server->addChannel($channel);
            }
            
            // Add remote user to the channel
            $remoteUser = $this->getOrCreateRemoteUser($nick, $identHost, $serverLink);
            if ($remoteUser !== null) {
                $channel->addUser($remoteUser);
                
                // Send JOIN notification to all users in the channel
                $joinMessage = ":{$prefix} JOIN {$channelName}";
                foreach ($channel->getUsers() as $user) {
                    // Do not send to the remote user itself
                    if ($user !== $remoteUser) {
                        $user->send($joinMessage);
                    }
                }
            }
            
            // Forward JOIN message to all other servers (except origin server)
            $this->server->propagateToServers(":{$prefix} JOIN {$channelName}", $serverLink->getName());
        }
    }
    
    /**
     * Gets or creates a remote user
     * 
     * @param string $nick The user's nickname
     * @param string $identHost The ident/host information (user@host)
     * @param ServerLink $serverLink The server link the user comes from
     * @return User|null The remote user or null on error
     */
    private function getOrCreateRemoteUser(string $nick, string $identHost, ServerLink $serverLink): ?User {
        // Search if the user is already known locally
        foreach ($this->server->getUsers() as $user) {
            if ($user->getNick() === $nick) {
                return $user;
            }
        }
        
        // If not, create a new remote user
        $identHostParts = explode('@', $identHost, 2);
        $ident = $identHostParts[0] ?? 'unknown';
        $host = $identHostParts[1] ?? 'unknown.host';
        
        // Create new remote user with dummy socket
        $user = new User(null, $serverLink->getName() . ".remote");
        $user->setNick($nick);
        $user->setIdent($ident);
        $user->setHost($host);
        $user->setRealname("Remote user from " . $serverLink->getName());
        $user->setRemoteUser(true);
        $user->setRemoteServer($serverLink->getName());
        
        // Add user to the server
        $this->server->addUser($user);
        
        return $user;
    }
    
    /**
     * Processes the PART command from a server
     * 
     * @param ServerLink $serverLink The server link
     * @param string $prefix The command prefix
     * @param array $parts The command parts
     */
    private function handlePartCommand(ServerLink $serverLink, string $prefix, array $parts): void {
        // Check if enough parameters are present
        if (!isset($parts[1])) {
            return;
        }
        
        // If no prefix is present, we cannot do anything
        if (empty($prefix)) {
            return;
        }
        
        // Extract user information from the prefix (nick!user@host)
        $nickInfo = explode('!', $prefix, 2);
        $nick = $nickInfo[0];
        
        // Extract channel name(s)
        $channels = explode(',', $parts[1]);
        
        // Extract part message, if present
        $partMessage = '';
        for ($i = 2; $i < count($parts); $i++) {
            if ($parts[$i][0] === ':') {
                $partMessage = substr(implode(' ', array_slice($parts, $i)), 1);
                break;
            }
        }
        
        // Process the part message for each channel
        foreach ($channels as $channelName) {
            // Get the channel
            $channel = $this->server->getChannel($channelName);
            if ($channel === null) {
                continue; // Channel does not exist
            }
            
            // Find user in the channel
            $userFound = false;
            foreach ($channel->getUsers() as $user) {
                if ($user->getNick() === $nick) {
                    // Remove user from the channel
                    $channel->removeUser($user);
                    $userFound = true;
                    
                    // Send PART notification to all remaining users in the channel
                    $partCommand = ":{$prefix} PART {$channelName}";
                    if (!empty($partMessage)) {
                        $partCommand .= " :{$partMessage}";
                    }
                    
                    foreach ($channel->getUsers() as $remainingUser) {
                        $remainingUser->send($partCommand);
                    }
                    
                    break;
                }
            }
            
            // Delete channel if it is empty and not permanent
            if ($channel->isEmpty() && !$channel->isPermanent()) {
                $this->server->removeChannel($channelName);
            }
            
            // Only if we found the user, forward the message to other servers
            if ($userFound) {
                // Forward PART message to all other servers (except origin server)
                $partCommand = ":{$prefix} PART {$channelName}";
                if (!empty($partMessage)) {
                    $partCommand .= " :{$partMessage}";
                }
                $this->server->propagateToServers($partCommand, $serverLink->getName());
            }
        }
    }
    
    /**
     * Processes the QUIT command from a server
     * 
     * @param ServerLink $serverLink The server link
     * @param string $prefix The command prefix
     * @param array $parts The command parts
     */
    private function handleQuitCommand(ServerLink $serverLink, string $prefix, array $parts): void {
        // If no prefix is present, we cannot do anything
        if (empty($prefix)) {
            return;
        }
        
        // Extract user information from the prefix (nick!user@host)
        $nickInfo = explode('!', $prefix, 2);
        $nick = $nickInfo[0];
        
        // Extract quit message, if present
        $quitMessage = '';
        for ($i = 1; $i < count($parts); $i++) {
            if ($parts[$i][0] === ':') {
                $quitMessage = substr(implode(' ', array_slice($parts, $i)), 1);
                break;
            }
        }
        
        // Find remote user
        $remoteUser = null;
        foreach ($this->server->getUsers() as $user) {
            if ($user->getNick() === $nick && $user->isRemoteUser()) {
                $remoteUser = $user;
                break;
            }
        }
        
        if ($remoteUser === null) {
            return; // User not found
        }
        
        // Remove user from all channels and distribute quit message
        $channels = [];
        foreach ($this->server->getChannels() as $channel) {
            if ($channel->hasUser($remoteUser)) {
                $channels[] = $channel;
            }
        }
        
        // Create quit message
        $quitCommand = ":{$prefix} QUIT";
        if (!empty($quitMessage)) {
            $quitCommand .= " :{$quitMessage}";
        }
        
        // Remove user from each channel and send message to members
        foreach ($channels as $channel) {
            // Send notification to all users in the channel (except the one leaving)
            foreach ($channel->getUsers() as $user) {
                if ($user !== $remoteUser) {
                    $user->send($quitCommand);
                }
            }
            
            // Remove user from the channel
            $channel->removeUser($remoteUser);
            
            // Delete channel if it is empty and not permanent
            if ($channel->isEmpty() && !$channel->isPermanent()) {
                $this->server->removeChannel($channel->getName());
            }
        }
        
        // Remove user from the server's user list
        $this->server->removeUser($remoteUser);
        
        // Forward quit message to all other servers (except origin server)
        $this->server->propagateToServers($quitCommand, $serverLink->getName());
    }
    
    /**
     * Processes the MODE command from a server
     * 
     * @param ServerLink $serverLink The server link
     * @param string $prefix The command prefix
     * @param array $parts The command parts
     */
    private function handleModeCommand(ServerLink $serverLink, string $prefix, array $parts): void {
        // Check if enough parameters are present
        if (!isset($parts[1]) || !isset($parts[2])) {
            return;
        }
        
        // If no prefix is present, we cannot do anything
        if (empty($prefix)) {
            return;
        }
        
        // Extract target of the mode change (channel or user)
        $target = $parts[1];
        $modes = $parts[2];
        
        // Create the full MODE command for forwarding
        $modeCommand = ":{$prefix} MODE {$target} {$modes}";
        
        // Add parameters for the mode change, if present
        for ($i = 3; $i < count($parts); $i++) {
            $modeCommand .= " " . $parts[$i];
        }
        
        // Check if the target is a channel
        if ($target[0] === '#' || $target[0] === '&') {
            // Mode change for a channel
            $channel = $this->server->getChannel($target);
            
            if ($channel !== null) {
                // Extract user information from the prefix (nick!user@host)
                $nickInfo = explode('!', $prefix, 2);
                $nick = $nickInfo[0];
                
                // Find remote user in the channel
                $sourceUser = null;
                foreach ($channel->getUsers() as $user) {
                    if ($user->getNick() === $nick) {
                        $sourceUser = $user;
                        break;
                    }
                }
                
                // Process the mode change
                if ($sourceUser !== null) {
                    // Create an array for the parameters (after the mode)
                    $modeParams = array_slice($parts, 3);
                    
                    // Process the mode change locally
                    $this->processChannelModeChange($channel, $sourceUser, $modes, $modeParams);
                    
                    // Send mode change to all local users in the channel
                    foreach ($channel->getUsers() as $user) {
                        if (!$user->isRemoteUser()) {
                            $user->send($modeCommand);
                        }
                    }
                }
                
                // Forward MODE command to all other servers (except the origin server)
                $this->server->propagateToServers($modeCommand, $serverLink->getName());
            }
        } else {
            // Mode change for a user
            foreach ($this->server->getUsers() as $user) {
                if ($user->getNick() === $target && !$user->isRemoteUser()) {
                    // Extract user information from the prefix (nick!user@host)
                    $nickInfo = explode('!', $prefix, 2);
                    $sourceNick = $nickInfo[0];
                    
                    // Only IRC operators can change user modes
                    $sourceUser = null;
                    foreach ($this->server->getUsers() as $u) {
                        if ($u->getNick() === $sourceNick && ($u->isOper() || $sourceNick === $target)) {
                            $sourceUser = $u;
                            break;
                        }
                    }
                    
                    if ($sourceUser !== null) {
                        // Process the user mode change locally
                        $this->processUserModeChange($user, $modes);
                        
                        // Send mode change to the affected user
                        $user->send($modeCommand);
                    }
                    
                    // Forward MODE command to all other servers (except the origin server)
                    $this->server->propagateToServers($modeCommand, $serverLink->getName());
                    return;
                }
            }
            
            // User is not local, forward to other servers
            $this->server->propagateToServers($modeCommand, $serverLink->getName());
        }
    }
    
    /**
     * Processes a channel mode change locally
     * 
     * @param \PhpIrcd\Models\Channel $channel The affected channel
     * @param \PhpIrcd\Models\User $sourceUser The user performing the change
     * @param string $modes The modes to change
     * @param array $params Parameters for the mode change
     */
    private function processChannelModeChange(\PhpIrcd\Models\Channel $channel, \PhpIrcd\Models\User $sourceUser, string $modes, array $params): void {
        $paramIndex = 0;
        $addMode = true;  // Default to adding modes
        
        for ($i = 0; $i < strlen($modes); $i++) {
            $mode = $modes[$i];
            
            if ($mode === '+') {
                $addMode = true;
                continue;
            } elseif ($mode === '-') {
                $addMode = false;
                continue;
            }
            
            switch ($mode) {
                case 'o': // Operator status
                    if (isset($params[$paramIndex])) {
                        $targetNick = $params[$paramIndex++];
                        foreach ($channel->getUsers() as $user) {
                            if ($user->getNick() === $targetNick) {
                                $channel->setOperator($user, $addMode);
                                break;
                            }
                        }
                    }
                    break;
                    
                case 'v': // Voice status
                    if (isset($params[$paramIndex])) {
                        $targetNick = $params[$paramIndex++];
                        foreach ($channel->getUsers() as $user) {
                            if ($user->getNick() === $targetNick) {
                                $channel->setVoiced($user, $addMode);
                                break;
                            }
                        }
                    }
                    break;
                    
                case 'i': // Invite-Only
                    $channel->setMode('i', $addMode);
                    break;
                    
                case 'm': // Moderated
                    $channel->setMode('m', $addMode);
                    break;
                    
                case 's': // Secret
                    $channel->setMode('s', $addMode);
                    break;
                    
                case 't': // Topic-Protection
                    $channel->setMode('t', $addMode);
                    break;
                    
                case 'k': // Key (Password)
                    if ($addMode) {
                        if (isset($params[$paramIndex])) {
                            $channel->setMode('k', true, $params[$paramIndex++]);
                        }
                    } else {
                        $channel->setMode('k', false);
                        $paramIndex++; // Even when removing, a parameter is consumed
                    }
                    break;
                    
                case 'l': // Limit
                    if ($addMode) {
                        if (isset($params[$paramIndex])) {
                            $channel->setMode('l', true, (int)$params[$paramIndex++]);
                        }
                    } else {
                        $channel->setMode('l', false);
                    }
                    break;
                    
                case 'b': // Ban
                    if (isset($params[$paramIndex])) {
                        $banMask = $params[$paramIndex++];
                        if ($addMode) {
                            $channel->addBan($banMask, $sourceUser->getNick());
                        } else {
                            $channel->removeBan($banMask);
                        }
                    }
                    break;
                    
                case 'e': // Ban-Exception
                    if (isset($params[$paramIndex])) {
                        $exceptionMask = $params[$paramIndex++];
                        if ($addMode) {
                            $channel->addBanException($exceptionMask, $sourceUser->getNick());
                        } else {
                            $channel->removeBanException($exceptionMask);
                        }
                    }
                    break;
                    
                case 'I': // Invite-Exception
                    if (isset($params[$paramIndex])) {
                        $inviteExceptionMask = $params[$paramIndex++];
                        if ($addMode) {
                            $channel->invite($inviteExceptionMask, $sourceUser->getNick());
                        } else {
                            $channel->removeInviteException($inviteExceptionMask);
                        }
                    }
                    break;
            }
        }
    }
    
    /**
     * Processes a user mode change locally
     * 
     * @param \PhpIrcd\Models\User $user The affected user
     * @param string $modes The modes to change
     */
    private function processUserModeChange(\PhpIrcd\Models\User $user, string $modes): void {
        $addMode = true;  // Default to adding modes
        
        for ($i = 0; $i < strlen($modes); $i++) {
            $mode = $modes[$i];
            
            if ($mode === '+') {
                $addMode = true;
                continue;
            } elseif ($mode === '-') {
                $addMode = false;
                continue;
            }
            
            switch ($mode) {
                case 'i': // Invisible
                    $user->setMode('i', $addMode);
                    break;
                    
                case 'w': // Wallops
                    $user->setMode('w', $addMode);
                    break;
                    
                case 'o': // Operator (can only be removed)
                    if (!$addMode) {
                        $user->setMode('o', false);
                    }
                    break;
                    
                case 'r': // Registered nick (can only be added)
                    if ($addMode) {
                        $user->setMode('r', true);
                    }
                    break;
            }
        }
    }
    
    /**
     * Processes the TOPIC command from a server
     * 
     * @param ServerLink $serverLink The server link
     * @param string $prefix The command prefix
     * @param array $parts The command parts
     */
    private function handleTopicCommand(ServerLink $serverLink, string $prefix, array $parts): void {
        // Check if enough parameters are present
        if (!isset($parts[1])) {
            return;
        }
        
        // If no prefix is present, we cannot do anything
        if (empty($prefix)) {
            return;
        }
        
        // Extract channel name
        $channelName = $parts[1];
        
        // Find channel
        $channel = $this->server->getChannel($channelName);
        if ($channel === null) {
            return; // Channel does not exist
        }
        
        // Extract user information from the prefix (nick!user@host)
        $nickInfo = explode('!', $prefix, 2);
        $nick = $nickInfo[0];
        
        // Check if the command sets or queries a topic
        if (count($parts) > 2) {
            // Set topic
            $topic = '';
            for ($i = 2; $i < count($parts); $i++) {
                if ($parts[$i][0] === ':') {
                    $topic = substr(implode(' ', array_slice($parts, $i)), 1);
                    break;
                }
            }
            
            // Set topic in the channel (uses the correct method with both parameters)
            $channel->setTopic($topic, $nick);
            
            // Create TOPIC command
            $topicCommand = ":{$prefix} TOPIC {$channelName} :{$topic}";
            
            // Send topic change to all local users in the channel
            foreach ($channel->getUsers() as $user) {
                if (!$user->isRemoteUser()) {
                    $user->send($topicCommand);
                }
            }
            
            // Forward topic change to all other servers (except the origin server)
            $this->server->propagateToServers($topicCommand, $serverLink->getName());
        } else {
            // Query topic - usually answered directly by the server, not forwarded
        }
    }
    
    /**
     * Processes PRIVMSG and NOTICE commands from a server
     * 
     * @param ServerLink $serverLink The server link
     * @param string $prefix The command prefix
     * @param string $commandType The command type (PRIVMSG or NOTICE)
     * @param array $parts The command parts
     */
    private function handleMessageCommand(ServerLink $serverLink, string $prefix, string $commandType, array $parts): void {
        // Check if enough parameters are present
        if (!isset($parts[1]) || !isset($parts[2])) {
            return;
        }
        
        // If no prefix is present, we cannot do anything
        if (empty($prefix)) {
            return;
        }
        
        // Extract target of the message
        $target = $parts[1];
        
        // Extract message content (starts with :)
        $message = '';
        for ($i = 2; $i < count($parts); $i++) {
            if ($parts[$i][0] === ':') {
                $message = substr(implode(' ', array_slice($parts, $i)), 1);
                break;
            }
        }
        
        if (empty($message)) {
            return;
        }
        
        // Extract user information from the prefix (nick!user@host)
        $nickInfo = explode('!', $prefix, 2);
        $nick = $nickInfo[0];
        
        // Create the full message for forwarding
        $fullCommand = ":{$prefix} {$commandType} {$target} :{$message}";
        
        // Check if the target is a channel
        if ($target[0] === '#' || $target[0] === '&') {
            // Message to a channel
            $channel = $this->server->getChannel($target);
            
            if ($channel !== null) {
                // Send message to all local users in the channel
                foreach ($channel->getUsers() as $user) {
                    // Do not send to remote users or the sender itself
                    if (!$user->isRemoteUser() && $user->getNick() !== $nick) {
                        $user->send($fullCommand);
                    }
                }
                
                // Forward message to all other servers (except the origin server)
                $this->server->propagateToServers($fullCommand, $serverLink->getName());
            }
        } else {
            // Message to a user
            foreach ($this->server->getUsers() as $user) {
                if ($user->getNick() === $target && !$user->isRemoteUser()) {
                    // If the user is local, deliver the message
                    $user->send($fullCommand);
                    return;
                }
            }
            
            // User is not local, forward to other servers (except the origin server)
            $this->server->propagateToServers($fullCommand, $serverLink->getName());
        }
    }
    
    /**
     * Disconnects a server link
     * 
     * @param ServerLink $serverLink The server link to disconnect
     * @param string $reason The reason for disconnection
     */
    private function disconnectServerLink(ServerLink $serverLink, string $reason): void {
        // Create log entry
        $this->server->getLogger()->info("Server link to {$serverLink->getName()} disconnected: {$reason}");
        
        // Close connection
        $serverLink->disconnect();
    }
    
    /**
     * Disconnects a server (public method for SQUIT)
     * 
     * @param \PhpIrcd\Models\ServerLink $serverLink The server link to disconnect
     * @param string $reason The reason for disconnection
     */
    public function disconnectServer(\PhpIrcd\Models\ServerLink $serverLink, string $reason = "Server disconnected"): void {
        $this->disconnectServerLink($serverLink, $reason);
        
        // Remove server link from internal list
        $key = array_search($serverLink, $this->links, true);
        if ($key !== false) {
            unset($this->links[$key]);
            $this->links = array_values($this->links); // Reindex array
        }
    }
    
    /**
     * Sends pings to all connected servers
     */
    private function pingServers(): void {
        $config = $this->server->getConfig();
        
        foreach ($this->links as $serverLink) {
            if ($serverLink->isConnected()) {
                $serverLink->send("PING :{$config['name']}");
            }
        }
    }
    
    /**
     * Returns all server links
     * 
     * @return array All server links
     */
    public function getServerLinks(): array {
        return $this->links;
    }
    
    /**
     * Establishes an outgoing connection to another server
     * 
     * @param string $host The hostname or IP address of the target server
     * @param int $port The port of the target server
     * @param string $password The password for the connection
     * @param bool $useSSL Whether to use SSL for the connection
     * @return bool Whether the connection was successfully established
     */
    public function connectToServer(string $host, int $port, string $password, bool $useSSL = false): bool {
        $config = $this->server->getConfig();
        
        try {
            if ($useSSL) {
                // Create SSL context
                $context = stream_context_create([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ]);
                
                // Establish secure connection
                $socket = stream_socket_client(
                    "ssl://{$host}:{$port}", 
                    $errno, 
                    $errstr, 
                    30, 
                    STREAM_CLIENT_CONNECT,
                    $context
                );
                
                if (!$socket) {
                    $this->server->getLogger()->error("Error connecting to server {$host}:{$port}: {$errstr} ({$errno})");
                    return false;
                }
                
                $serverLink = new ServerLink($socket, $host, $password, true);
            } else {
                // Create regular socket
                $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                if (!$socket) {
                    $errorCode = socket_last_error();
                    $errorMsg = socket_strerror($errorCode);
                    $this->server->getLogger()->error("Error creating socket: {$errorCode} - {$errorMsg}");
                    return false;
                }
                
                // Establish connection
                $result = socket_connect($socket, $host, $port);
                if (!$result) {
                    $errorCode = socket_last_error($socket);
                    $errorMsg = socket_strerror($errorCode);
                    $this->server->getLogger()->error("Error connecting to server {$host}:{$port}: {$errorCode} - {$errorMsg}");
                    socket_close($socket);
                    return false;
                }
                
                $serverLink = new ServerLink($socket, $host, $password);
            }
            
            // Add server link
            $this->addServerLink($serverLink);
            
            // Send PASS and SERVER commands
            $serverLink->send("PASS {$password}");
            $serverLink->send("SERVER {$config['name']} 1 :{$config['description']}");
            
            return true;
        } catch (\Exception $e) {
            $this->server->getLogger()->error("Exception connecting to server {$host}:{$port}: " . $e->getMessage());
            return false;
        }
    }
}