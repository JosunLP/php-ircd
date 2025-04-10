<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class UnregisterCommand extends CommandBase {
    /**
     * Executes the UNREGISTER command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure the user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Check if enough parameters are provided
        if (!isset($args[1])) {
            $this->sendError($user, 'UNREGISTER', 'Not enough parameters', 461);
            return;
        }
        
        // Extract channel name
        $channelName = $args[1];
        
        // Check if the channel exists
        $channel = $this->server->getChannel($channelName);
        if ($channel === null) {
            $user->send(":{$config['name']} 403 {$nick} {$channelName} :No such channel");
            return;
        }
        
        // Check if the user is channel operator
        if (!$channel->isOperator($user) && !$user->isOper()) {
            $user->send(":{$config['name']} 482 {$nick} {$channelName} :You're not channel operator");
            return;
        }
        
        // Check if the channel is registered
        if (!$channel->isPermanent()) {
            $user->send(":{$config['name']} NOTICE {$nick} :Channel {$channelName} is not registered");
            return;
        }
        
        // Unregister the channel
        if ($this->server->unregisterPermanentChannel($channelName, $user)) {
            $user->send(":{$config['name']} NOTICE {$nick} :Channel {$channelName} has been unregistered");
            
            // Notify all users in the channel
            foreach ($channel->getUsers() as $channelUser) {
                if ($channelUser !== $user) {
                    $channelUser->send(":{$config['name']} NOTICE {$channelUser->getNick()} :Channel {$channelName} has been unregistered by {$nick}");
                }
            }
        } else {
            $user->send(":{$config['name']} NOTICE {$nick} :Failed to unregister channel {$channelName}");
        }
    }
}