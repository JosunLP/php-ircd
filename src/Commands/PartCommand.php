<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class PartCommand extends CommandBase {
    /**
     * Executes the PART command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure the user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        // Check if enough parameters are provided
        if (!isset($args[1])) {
            $this->sendError($user, 'PART', 'Not enough parameters', 461);
            return;
        }
        
        // Extract channel names
        $channelNames = explode(',', $args[1]);
        
        // Extract part reason (optional)
        $reason = $this->getMessagePart($args, 2);
        if (empty($reason)) {
            $reason = "Leaving";
        }
        
        foreach ($channelNames as $channelName) {
            $this->partChannel($user, $channelName, $reason);
        }
    }
    
    /**
     * Allows a user to leave a channel
     * 
     * @param User $user The user
     * @param string $channelName The channel name
     * @param string $reason The reason for leaving
     */
    private function partChannel(User $user, string $channelName, string $reason): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Check if the channel exists
        $channel = $this->server->getChannel($channelName);
        if ($channel === null) {
            $user->send(":{$config['name']} 403 {$nick} {$channelName} :No such channel");
            return;
        }
        
        // Check if the user is in the channel
        if (!$channel->hasUser($user)) {
            $user->send(":{$config['name']} 442 {$nick} {$channelName} :You're not on that channel");
            return;
        }
        
        // Send PART message to all users in the channel
        $partMessage = ":{$nick}!{$user->getIdent()}@{$user->getCloak()} PART {$channelName} :{$reason}";
        foreach ($channel->getUsers() as $channelUser) {
            $channelUser->send($partMessage);
        }
        
        // Remove user from the channel
        $channel->removeUser($user);
        
        // If the channel is empty, remove it
        if (count($channel->getUsers()) === 0) {
            $this->server->removeChannel($channelName);
        }
    }
}