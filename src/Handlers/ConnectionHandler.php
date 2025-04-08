<?php

namespace PhpIrcd\Handlers;

use PhpIrcd\Core\Server;
use PhpIrcd\Models\User;
use PhpIrcd\Models\Channel;

class ConnectionHandler {
    private $server;
    private $commandHandlers = [];
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
     * @param \Socket $serverSocket The server socket
     */
    public function acceptNewConnections(\Socket $serverSocket): void {
        $newSocket = socket_accept($serverSocket);
        if ($newSocket !== false) {
            try {
                // Determine the IP address of the new user
                socket_getpeername($newSocket, $ip);
                
                // Create a new user
                $user = new User($newSocket, $ip);
                
                // Send welcome messages
                $this->sendWelcomeMessages($user);
            } catch (\Exception $e) {
                $this->server->getLogger()->error("Error accepting connection: " . $e->getMessage());
                if (is_resource($newSocket)) {
                    socket_close($newSocket);
                }
            }
        }
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
        
        foreach ($users as $user) {
            // Timestamp of the last activity check
            $lastActivityCheck = time();
            
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
                
                // If the channel is empty, remove it
                if (count($channel->getUsers()) === 0) {
                    $this->server->removeChannel($channel->getName());
                }
            }
        }
        
        // Close user socket
        $user->disconnect();
        
        // Remove user from the server
        $this->server->removeUser($user);
    }
}