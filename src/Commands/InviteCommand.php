<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class InviteCommand extends CommandBase {
    /**
     * Executes the INVITE command
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
            $this->sendError($user, 'INVITE', 'Not enough parameters', 461);
            return;
        }
        
        $nickname = $args[1];
        $channelName = $args[2];
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Search for target user
        $targetUser = null;
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === strtolower($nickname)) {
                $targetUser = $serverUser;
                break;
            }
        }
        
        // If user not found, send error
        if ($targetUser === null) {
            $user->send(":{$config['name']} 401 {$nick} {$nickname} :No such nick/channel");
            return;
        }
        
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
        
        // If the channel is invite-only, the user must be a channel operator
        if ($channel->hasMode('i') && !$channel->isOperator($user) && !$user->isOper()) {
            $user->send(":{$config['name']} 482 {$nick} {$channelName} :You're not channel operator");
            return;
        }
        
        // Check if the target user is already in the channel
        if ($channel->hasUser($targetUser)) {
            $user->send(":{$config['name']} 443 {$nick} {$nickname} {$channelName} :is already on channel");
            return;
        }
        
        // Add user to invite list
        $channel->invite($targetUser->getNick() . '!' . $targetUser->getIdent() . '@' . $targetUser->getCloak(), $nick);
        
        // Send invite notification to target user
        $targetUser->send(":{$nick}!{$user->getIdent()}@{$user->getCloak()} INVITE {$nickname} {$channelName}");
        
        // Send confirmation to the inviting user
        $user->send(":{$config['name']} 341 {$nick} {$nickname} {$channelName}");
        
        // IRCv3 invite-notify: Notify all users in the channel with the capability
        $inviteNotify = ":{$nick}!{$user->getIdent()}@{$user->getCloak()} INVITE {$channelName} {$nickname}";
        foreach ($channel->getUsers() as $channelUser) {
            if ($channelUser !== $user && $channelUser !== $targetUser && $channelUser->hasCapability('invite-notify')) {
                $channelUser->send($inviteNotify);
            }
        }
    }
}