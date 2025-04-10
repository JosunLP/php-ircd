<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class RegisterCommand extends CommandBase {
    /**
     * Executes the REGISTER command
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
            $this->sendError($user, 'REGISTER', 'Not enough parameters', 461);
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
        
        // Register the channel
        if ($this->server->registerPermanentChannel($channelName, $user)) {
            $user->send(":{$config['name']} NOTICE {$nick} :Channel {$channelName} is now registered and will persist after restart");
            
            // Notify all users in the channel
            foreach ($channel->getUsers() as $channelUser) {
                if ($channelUser !== $user) {
                    $channelUser->send(":{$config['name']} NOTICE {$channelUser->getNick()} :Channel {$channelName} has been registered by {$nick}");
                }
            }
        } else {
            $user->send(":{$config['name']} NOTICE {$nick} :Failed to register channel {$channelName}");
        }
    }
}