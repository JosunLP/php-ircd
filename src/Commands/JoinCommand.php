<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;
use PhpIrcd\Models\Channel;

class JoinCommand extends CommandBase {
    /**
     * Executes the JOIN command
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
            $this->sendError($user, 'JOIN', 'Not enough parameters', 461);
            return;
        }
        
        // Extract channels and keys
        $channelNames = explode(',', $args[1]);
        $keys = isset($args[2]) ? explode(',', $args[2]) : [];
        
        foreach ($channelNames as $index => $channelName) {
            // Validate channel name
            if (!$this->validateChannelName($channelName)) {
                $user->send(":{$this->server->getConfig()['name']} 403 {$user->getNick()} {$channelName} :No such channel");
                continue;
            }
            
            // Determine the key for the channel
            $key = isset($keys[$index]) ? $keys[$index] : null;
            
            // Join the channel
            $this->joinChannel($user, $channelName, $key);
        }
    }
    
    /**
     * Allows a user to join a channel
     * 
     * @param User $user The user
     * @param string $channelName The channel name
     * @param string|null $key The key for the channel
     */
    private function joinChannel(User $user, string $channelName, ?string $key): void {
        $config = $this->server->getConfig();
        
        // Get or create the channel
        $channel = $this->server->getChannel($channelName);
        $isNewChannel = $channel === null;
        
        if ($isNewChannel) {
            $channel = new Channel($channelName);
            $this->server->addChannel($channel);
            
            // Performance optimization for web servers:
            // When creating a channel, save the data in a file or database
            // to preserve the state between web server requests
            $this->server->saveChannelState($channel);
        }
        
        // Check if the user can join the channel
        if (!$isNewChannel && !$channel->canJoin($user, $key)) {
            // Error messages depending on the reason
            if ($channel->isBanned($user)) {
                $user->send(":{$config['name']} 474 {$user->getNick()} {$channelName} :Cannot join channel (+b)");
            } else if ($channel->hasMode('i') && !$channel->isInvited($user)) {
                $user->send(":{$config['name']} 473 {$user->getNick()} {$channelName} :Cannot join channel (+i)");
            } else if ($channel->hasMode('k') && $key !== $channel->getKey()) {
                $user->send(":{$config['name']} 475 {$user->getNick()} {$channelName} :Cannot join channel (+k)");
            } else if ($channel->hasMode('l') && count($channel->getUsers()) >= $channel->getLimit()) {
                $user->send(":{$config['name']} 471 {$user->getNick()} {$channelName} :Cannot join channel (+l)");
            } else {
                $user->send(":{$config['name']} 471 {$user->getNick()} {$channelName} :Cannot join channel");
            }
            return;
        }
        
        // Add user to the channel
        $channel->addUser($user, $isNewChannel);
        
        // Save channel state after user joins
        $this->server->saveChannelState($channel);
        
        // Send JOIN message to all users in the channel
        $joinMessage = ":{$user->getNick()}!{$user->getIdent()}@{$user->getCloak()} JOIN :{$channelName}";
        foreach ($channel->getUsers() as $channelUser) {
            $channelUser->send($joinMessage);
        }
        
        // Send topic if available
        $topic = $channel->getTopic();
        if ($topic !== null) {
            $user->send(":{$config['name']} 332 {$user->getNick()} {$channelName} :{$topic}");
            $user->send(":{$config['name']} 333 {$user->getNick()} {$channelName} {$channel->getTopicSetBy()} {$channel->getTopicSetTime()}");
        } else {
            $user->send(":{$config['name']} 331 {$user->getNick()} {$channelName} :No topic is set");
        }
        
        // Send user list
        $this->sendNamesList($user, $channel);
    }
    
    /**
     * Sends the NAMES list to a user
     * 
     * @param User $user The user
     * @param Channel $channel The channel
     */
    private function sendNamesList(User $user, Channel $channel): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        $channelName = $channel->getName();
        
        // Create user list
        $userNames = [];
        foreach ($channel->getUsers() as $channelUser) {
            $prefix = '';
            
            // Add prefixes
            if ($channel->isOwner($channelUser)) {
                $prefix = '~';
            } else if ($channel->isProtected($channelUser)) {
                $prefix = '&';
            } else if ($channel->isOperator($channelUser)) {
                $prefix = '@';
            } else if ($channel->isHalfop($channelUser)) {
                $prefix = '%';
            } else if ($channel->isVoiced($channelUser)) {
                $prefix = '+';
            }
            
            $userNames[] = $prefix . $channelUser->getNick();
        }
        
        // Split user list into parts (max. 512 bytes per message)
        $maxNamesPerLine = 30; // Approximate value
        $nameChunks = array_chunk($userNames, $maxNamesPerLine);
        
        foreach ($nameChunks as $nameChunk) {
            $names = implode(' ', $nameChunk);
            $user->send(":{$config['name']} 353 {$nick} = {$channelName} :{$names}");
        }
        
        $user->send(":{$config['name']} 366 {$nick} {$channelName} :End of /NAMES list");
    }
    
    /**
     * Validates a channel name according to IRC rules
     * 
     * @param string $channelName The channel name to validate
     * @return bool Whether the channel name is valid
     */
    private function validateChannelName(string $channelName): bool {
        // Channel name must start with #, &, + or ! (We support only # for simplicity)
        // Must be between 2-50 characters
        // Cannot contain spaces, commas, control characters, or other special characters
        if (strlen($channelName) < 2 || strlen($channelName) > 50) {
            return false;
        }
        
        // Check first character - must be #
        if ($channelName[0] !== '#') {
            return false;
        }
        
        // Check for invalid characters
        $invalidChars = [' ', ',', "\x07", "\x00", "\r", "\n", "\t", "\v", "\f"];
        foreach ($invalidChars as $char) {
            if (strpos($channelName, $char) !== false) {
                return false;
            }
        }
        
        return true;
    }
}