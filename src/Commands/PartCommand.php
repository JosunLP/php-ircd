<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class PartCommand extends CommandBase {
    /**
     * Executes the PART command (leave a channel)
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
            $this->sendError($user, 'PART', 'Not enough parameters', 461);
            return;
        }
        
        // Get the channel(s) to leave
        $channels = explode(',', $args[1]);
        
        // Find part message, if any
        $partMessage = '';
        for ($i = 2; $i < count($args); $i++) {
            if ($args[$i][0] === ':') {
                $partMessage = substr(implode(' ', array_slice($args, $i)), 1);
                break;
            }
        }
        
        foreach ($channels as $channelName) {
            // Get the channel
            $channel = $this->server->getChannel($channelName);
            
            // Check if the channel exists
            if ($channel === null) {
                $user->send(":{$config['name']} 403 {$nick} {$channelName} :No such channel");
                continue;
            }
            
            // Check if the user is on the channel
            if (!$channel->hasUser($user)) {
                $user->send(":{$config['name']} 442 {$nick} {$channelName} :You're not on that channel");
                continue;
            }
            
            // Create part message
            $prefix = "{$nick}!{$user->getIdent()}@{$user->getCloak()}";
            $partCmd = ":{$prefix} PART {$channelName}";
            if (!empty($partMessage)) {
                $partCmd .= " :{$partMessage}";
            }
            
            // Send part message to all users in the channel (including the user leaving)
            foreach ($channel->getUsers() as $channelUser) {
                $channelUser->send($partCmd);
            }
            
            // Remove user from channel
            $channel->removeUser($user);
            
            // Check if the channel is now empty and not permanent
            if ($channel->isEmpty() && !$channel->isPermanent()) {
                $this->server->removeChannel($channelName);
                $this->server->getLogger()->info("Channel {$channelName} removed because it is empty");
            }
            
            // Propagate PART message to other servers
            $this->server->propagateToServers($partCmd);
        }
    }
}