<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class NoticeCommand extends CommandBase {
    /**
     * Executes the NOTICE command
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
            // NOTICE does not send error messages
            return;
        }
        
        // Extract targets
        $targets = explode(',', $args[1]);
        $message = $this->getMessagePart($args, 2);
        
        // Send message to all targets
        foreach ($targets as $target) {
            $this->sendNotice($user, $target, $message);
        }
    }
    
    /**
     * Sends a notice to a target
     * 
     * @param User $user The sending user
     * @param string $target The target (user or channel)
     * @param string $message The message
     */
    private function sendNotice(User $user, string $target, string $message): void {
        // Channel name starts with #
        if ($target[0] === '#') {
            $this->sendChannelNotice($user, $target, $message);
            return;
        }
        
        // Otherwise send to a user
        $targetUser = null;
        
        // Search for the user
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === strtolower($target)) {
                $targetUser = $serverUser;
                break;
            }
        }
        
        // If user not found, do nothing (NOTICE does not send errors)
        if ($targetUser === null) {
            return;
        }
        
        // Send message to the target user
        $targetUser->send(":{$user->getNick()}!{$user->getIdent()}@{$user->getCloak()} NOTICE {$target} :{$message}");
    }
    
    /**
     * Sends a notice to a channel
     * 
     * @param User $user The sending user
     * @param string $channelName The channel name
     * @param string $message The message
     */
    private function sendChannelNotice(User $user, string $channelName, string $message): void {
        // Search for the channel
        $channel = $this->server->getChannel($channelName);
        
        // If channel not found, do nothing (NOTICE does not send errors)
        if ($channel === null) {
            return;
        }
        
        // Check if the user is in the channel
        if (!$channel->hasUser($user)) {
            return;
        }
        
        // Check if the channel is in no-external-messages mode
        if ($channel->hasMode('n') && !$channel->hasUser($user)) {
            return;
        }
        
        // Check if the channel is in moderated mode and the user has no voice
        if ($channel->hasMode('m') && !$channel->isVoiced($user) && !$channel->isOperator($user)) {
            return;
        }
        
        // Send message to all users in the channel (except the sender)
        $message = ":{$user->getNick()}!{$user->getIdent()}@{$user->getCloak()} NOTICE {$channelName} :{$message}";
        foreach ($channel->getUsers() as $channelUser) {
            if ($channelUser !== $user) {
                $channelUser->send($message);
            }
        }
    }
}