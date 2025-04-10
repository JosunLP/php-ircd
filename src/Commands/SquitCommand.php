<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class SquitCommand extends CommandBase {
    /**
     * Executes the SQUIT command (disconnects a server from the network)
     * According to RFC 2812 Section 3.1.8
     * 
     * @param User $user The executing user (must be an operator)
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure the user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        // Only IRC operators can use the SQUIT command
        if (!$this->ensureOper($user)) {
            return;
        }
        
        // Check if enough parameters are provided
        if (!isset($args[1])) {
            $this->sendError($user, 'SQUIT', 'Not enough parameters', 461);
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        $server = $args[1];
        
        // Extract reason/comment
        $reason = isset($args[2]) ? $this->getMessagePart($args, 2) : "No reason given";
        
        // In this implementation, we only have a single server
        // So we'll only allow SQUITing our own server name for consistency
        if (strtolower($server) !== strtolower($config['name'])) {
            $user->send(":{$config['name']} 402 {$nick} {$server} :No such server");
            return;
        }
        
        // Since we're only running a single server, we'll just inform the user
        // that the command is recognized but the action is limited
        $user->send(":{$config['name']} NOTICE {$nick} :SQUIT command recognized, but this is a standalone server");
        
        // Log the SQUIT attempt
        $this->server->getLogger()->info("SQUIT attempt by operator {$nick}: {$reason}");
        
        // Notify other operators
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->isOper() && $serverUser !== $user) {
                $serverUser->send(":{$config['name']} NOTICE {$serverUser->getNick()} :*** Notice -- SQUIT attempted by {$nick}: {$reason}");
            }
        }
    }
}