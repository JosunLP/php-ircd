<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class ModeCommand extends CommandBase {
    /**
     * Executes the MODE command
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
            $this->sendError($user, 'MODE', 'Not enough parameters', 461);
            return;
        }
        
        $target = $args[1];
        
        // Channel name starts with #
        if ($target[0] === '#') {
            $this->handleChannelMode($user, $args);
        } else {
            $this->handleUserMode($user, $args);
        }
    }
    
    /**
     * Handles user modes
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    private function handleUserMode(User $user, array $args): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        $target = $args[1];
        
        // Search for target user
        $targetUser = null;
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === strtolower($target)) {
                $targetUser = $serverUser;
                break;
            }
        }
        
        // If user not found, send error
        if ($targetUser === null) {
            $user->send(":{$config['name']} 401 {$nick} {$target} :No such nick/channel");
            return;
        }
        
        // Other users cannot change modes
        if ($targetUser !== $user && !$user->isOper()) {
            $user->send(":{$config['name']} 502 {$nick} :Can't change mode for other users");
            return;
        }
        
        // If no mode is specified, show current mode
        if (!isset($args[2])) {
            $modes = $targetUser->getModes();
            if (!empty($modes)) {
                $user->send(":{$config['name']} 221 {$nick} +{$modes}");
            } else {
                $user->send(":{$config['name']} 221 {$nick}");
            }
            return;
        }
        
        // Change modes
        $modes = $args[2];
        $adding = true;
        
        for ($i = 0; $i < strlen($modes); $i++) {
            $char = $modes[$i];
            
            if ($char === '+') {
                $adding = true;
                continue;
            }
            
            if ($char === '-') {
                $adding = false;
                continue;
            }
            
            // Only certain modes are allowed
            switch ($char) {
                case 'i': // Invisible
                case 'w': // Wallops
                case 's': // Server notices
                    $targetUser->setMode($char, $adding);
                    break;
                
                case 'o': // Oper
                    // Oper status can only be set by a server
                    if (!$adding && $user === $targetUser) {
                        $targetUser->setOper(false);
                        $targetUser->setMode('o', false);
                    }
                    break;
                
                default:
                    // Unknown mode, ignore
                    break;
            }
        }
        
        // Send mode change to all users
        $newModes = $targetUser->getModes();
        if (!empty($newModes)) {
            $modeMessage = ":{$nick}!{$user->getIdent()}@{$user->getCloak()} MODE {$target} :+{$newModes}";
            $targetUser->send($modeMessage);
        }
    }
    
    /**
     * Handles channel modes
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    private function handleChannelMode(User $user, array $args): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        $channelName = $args[1];
        
        // Search for channel
        $channel = $this->server->getChannel($channelName);
        
        // If channel not found, send error
        if ($channel === null) {
            $user->send(":{$config['name']} 403 {$nick} {$channelName} :No such channel");
            return;
        }
        
        // If no mode is specified, show current mode
        if (!isset($args[2])) {
            $modeStr = $channel->getModeString();
            $modeParams = $channel->getModeParams();
            $paramStr = !empty($modeParams) ? ' ' . implode(' ', $modeParams) : '';
            $user->send(":{$config['name']} 324 {$nick} {$channelName} +{$modeStr}{$paramStr}");
            $user->send(":{$config['name']} 329 {$nick} {$channelName} {$channel->getCreationTime()}");
            return;
        }
        
        // Check if the user is an operator in the channel
        if (!$channel->isOperator($user) && !$user->isOper()) {
            $user->send(":{$config['name']} 482 {$nick} {$channelName} :You're not channel operator");
            return;
        }
        
        // Change modes
        $modes = $args[2];
        $adding = true;
        $modeIndex = 3; // Index for mode parameters
        $modeChanges = ['+' => '', '-' => '']; // Changes for announcement
        $modeParams = []; // Parameters for announcement
        
        for ($i = 0; $i < strlen($modes); $i++) {
            $char = $modes[$i];
            
            if ($char === '+') {
                $adding = true;
                continue;
            }
            
            if ($char === '-') {
                $adding = false;
                continue;
            }
            
            // Manage modes
            switch ($char) {
                // Modes without parameters
                case 'n': // No external messages
                case 'm': // Moderated
                case 't': // Topic protection
                case 's': // Secret
                case 'i': // Invite-only
                case 'p': // Private
                    $channel->setMode($char, $adding);
                    $modeChanges[$adding ? '+' : '-'] .= $char;
                    break;
                
                // Modes with parameters (only when adding)
                case 'k': // Key
                    if ($adding) {
                        if (isset($args[$modeIndex])) {
                            $key = $args[$modeIndex++];
                            $channel->setMode($char, true, $key);
                            $modeChanges['+'] .= $char;
                            $modeParams[] = $key;
                        }
                    } else {
                        $channel->setMode($char, false);
                        $modeChanges['-'] .= $char;
                    }
                    break;
                
                case 'l': // Limit
                    if ($adding) {
                        if (isset($args[$modeIndex]) && is_numeric($args[$modeIndex])) {
                            $limit = (int)$args[$modeIndex++];
                            $channel->setMode($char, true, $limit);
                            $modeChanges['+'] .= $char;
                            $modeParams[] = $limit;
                        }
                    } else {
                        $channel->setMode($char, false);
                        $modeChanges['-'] .= $char;
                    }
                    break;
                
                // User modes
                case 'o': // Operator
                case 'v': // Voice
                    if (isset($args[$modeIndex])) {
                        $targetNick = $args[$modeIndex++];
                        $targetUser = null;
                        
                        // Search for target user
                        foreach ($channel->getUsers() as $channelUser) {
                            if (strtolower($channelUser->getNick()) === strtolower($targetNick)) {
                                $targetUser = $channelUser;
                                break;
                            }
                        }
                        
                        if ($targetUser !== null) {
                            if ($char === 'o') {
                                $channel->setOperator($targetUser, $adding);
                            } else if ($char === 'v') {
                                $channel->setVoiced($targetUser, $adding);
                            }
                            
                            $modeChanges[$adding ? '+' : '-'] .= $char;
                            $modeParams[] = $targetNick;
                        }
                    }
                    break;
                
                default:
                    // Unknown mode, ignore
                    break;
            }
        }
        
        // Send mode change to all users in the channel
        $modeString = '';
        if (!empty($modeChanges['+'])) {
            $modeString .= '+' . $modeChanges['+'];
        }
        if (!empty($modeChanges['-'])) {
            $modeString .= '-' . $modeChanges['-'];
        }
        
        if (!empty($modeString)) {
            $paramString = !empty($modeParams) ? ' ' . implode(' ', $modeParams) : '';
            $modeMessage = ":{$nick}!{$user->getIdent()}@{$user->getCloak()} MODE {$channelName} {$modeString}{$paramString}";
            
            foreach ($channel->getUsers() as $channelUser) {
                $channelUser->send($modeMessage);
            }
        }
    }
}