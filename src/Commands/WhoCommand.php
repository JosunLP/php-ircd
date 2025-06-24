<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class WhoCommand extends CommandBase {
    /**
     * Executes the WHO command
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
        
        // Mask for filtering users
        $mask = isset($args[1]) ? $args[1] : '*';
        
        // If the mask is a channel name
        if ($mask[0] === '#') {
            $channel = $this->server->getChannel($mask);
            if ($channel !== null) {
                $this->whoChannel($user, $channel);
            }
        } else {
            // Otherwise, filter by user mask
            $this->whoUsers($user, $mask);
        }
        
        // End of WHO list
        $user->send(":{$config['name']} 315 {$nick} {$mask} :End of /WHO list");
    }
    
    /**
     * Sends WHO information about users in a channel
     * 
     * @param User $user The requesting user
     * @param Channel $channel The channel
     */
    private function whoChannel(User $user, $channel): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        $channelName = $channel->getName();
        
        // Skip secret channels the user is not in
        if ($channel->hasMode('s') && !$channel->hasUser($user)) {
            return;
        }
        
        foreach ($channel->getUsers() as $channelUser) {
            $this->sendWhoReply($user, $channelUser, $channelName);
        }
    }
    
    /**
     * Sends WHO information about users matching a mask
     * 
     * @param User $user The requesting user
     * @param string $mask The filter mask
     */
    private function whoUsers(User $user, string $mask): void {
        // Convert mask to regex pattern
        $pattern = '/^' . str_replace(['*', '?'], ['.*', '.'], $mask) . '$/i';
        
        foreach ($this->server->getUsers() as $targetUser) {
            // If mask is * or matches the user's nick, host, or realname
            if ($mask === '*' || 
                preg_match($pattern, $targetUser->getNick()) || 
                preg_match($pattern, $targetUser->getHost()) ||
                preg_match($pattern, $targetUser->getRealname())) {
                
                // Find the first channel the user is in
                $channelName = '*';
                foreach ($this->server->getChannels() as $channel) {
                    if ($channel->hasUser($targetUser) && 
                        (!$channel->hasMode('s') || $channel->hasUser($user))) {
                        $channelName = $channel->getName();
                        break;
                    }
                }
                
                $this->sendWhoReply($user, $targetUser, $channelName);
            }
        }
    }
    
    /**
     * Sends a WHO reply for a user
     * 
     * @param User $user The requesting user
     * @param User $targetUser The target user
     * @param string $channelName The channel name
     */
    private function sendWhoReply(User $user, User $targetUser, string $channelName): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        $targetNick = $targetUser->getNick();
        
        // User flags: H for here, G for gone (away), * for IRC operators, @ for channel operator, + for voiced
        $flags = $targetUser->isAway() ? 'G' : 'H';
        
        if ($targetUser->isOper()) {
            $flags .= '*';
        }
        
        // Add channel-specific flags
        if ($channelName !== '*') {
            $channel = $this->server->getChannel($channelName);
            if ($channel !== null) {
                if ($channel->isOperator($targetUser)) {
                    $flags .= '@';
                } else if ($channel->isVoiced($targetUser)) {
                    $flags .= '+';
                }
            }
        }
        
        // Format: <channel> <user> <host> <server> <nick> <H|G>[*][@|+] :<hopcount> <real name>
        $user->send(":{$config['name']} 352 {$nick} {$channelName} {$targetUser->getIdent()} {$targetUser->getHost()} {$config['name']} {$targetNick} {$flags} :0 {$targetUser->getRealname()}");
    }
}