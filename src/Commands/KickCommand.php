<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class KickCommand extends CommandBase {
    /**
     * Executes the KICK command
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
        if (!isset($args[1]) || !isset($args[2])) {
            $this->sendError($user, 'KICK', 'Not enough parameters', 461);
            return;
        }
        
        $channelName = $args[1];
        $targetNick = $args[2];
        $reason = isset($args[3]) ? $this->getMessagePart($args, 3) : "No reason given";
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Search for channel
        $channel = $this->server->getChannel($channelName);
        
        // If channel not found, send error
        if ($channel === null) {
            $user->send(":{$config['name']} 403 {$nick} {$channelName} :No such channel");
            return;
        }
        
        // Check if the user is in the channel
        if (!$channel->hasUser($user)) {
            $user->send(":{$config['name']} 442 {$nick} {$channelName} :You're not on that channel");
            return;
        }
        
        // Check if the user has operator privileges
        if (!$channel->isOperator($user) && !$user->isOper()) {
            $user->send(":{$config['name']} 482 {$nick} {$channelName} :You're not channel operator");
            return;
        }
        
        // Search for target user in channel
        $targetUser = null;
        foreach ($channel->getUsers() as $channelUser) {
            if (strtolower($channelUser->getNick()) === strtolower($targetNick)) {
                $targetUser = $channelUser;
                break;
            }
        }
        
        // If target user not found in channel, send error
        if ($targetUser === null) {
            $user->send(":{$config['name']} 441 {$nick} {$targetNick} {$channelName} :They aren't on that channel");
            return;
        }
        
        // Send kick message to all users in the channel
        $kickMessage = ":{$nick}!{$user->getIdent()}@{$user->getCloak()} KICK {$channelName} {$targetNick} :{$reason}";
        foreach ($channel->getUsers() as $channelUser) {
            $channelUser->send($kickMessage);
        }
        
        // Remove user from channel
        $channel->removeUser($targetUser);
    }
}