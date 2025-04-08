<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class WhoisCommand extends CommandBase {
    /**
     * Executes the WHOIS command
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
            $this->sendError($user, 'WHOIS', 'Not enough parameters', 461);
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Target nickname(s)
        $targetNicks = explode(',', $args[1]);
        
        foreach ($targetNicks as $targetNick) {
            // Search for target user
            $targetUser = null;
            foreach ($this->server->getUsers() as $serverUser) {
                if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === strtolower($targetNick)) {
                    $targetUser = $serverUser;
                    break;
                }
            }
            
            // If user not found, skip
            if ($targetUser === null) {
                $user->send(":{$config['name']} 401 {$nick} {$targetNick} :No such nick/channel");
                continue;
            }
            
            // Send WHOIS information
            $this->sendWhoisInfo($user, $targetUser);
        }
        
        // End of WHOIS
        $user->send(":{$config['name']} 318 {$nick} {$args[1]} :End of /WHOIS list");
    }
    
    /**
     * Sends WHOIS information about a user
     * 
     * @param User $user The requesting user
     * @param User $targetUser The target user
     */
    private function sendWhoisInfo(User $user, User $targetUser): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        $targetNick = $targetUser->getNick();
        
        // Basic user info
        $user->send(":{$config['name']} 311 {$nick} {$targetNick} {$targetUser->getIdent()} {$targetUser->getHost()} * :{$targetUser->getRealname()}");
        
        // Server info
        $user->send(":{$config['name']} 312 {$nick} {$targetNick} {$config['name']} :PHP IRCd Server");
        
        // Channels the user is in
        $channels = [];
        foreach ($this->server->getChannels() as $channel) {
            if ($channel->hasUser($targetUser)) {
                $prefix = '';
                if ($channel->isOperator($targetUser)) {
                    $prefix = '@';
                } else if ($channel->isVoiced($targetUser)) {
                    $prefix = '+';
                }
                $channels[] = $prefix . $channel->getName();
            }
        }
        
        if (count($channels) > 0) {
            $user->send(":{$config['name']} 319 {$nick} {$targetNick} :" . implode(' ', $channels));
        }
        
        // Operator status
        if ($targetUser->isOper()) {
            $user->send(":{$config['name']} 313 {$nick} {$targetNick} :is an IRC operator");
        }
        
        // Away status
        if ($targetUser->isAway()) {
            $user->send(":{$config['name']} 301 {$nick} {$targetNick} :{$targetUser->getAwayMessage()}");
        }
        
        // Idle time
        $idleTime = time() - $targetUser->getLastActivity();
        $user->send(":{$config['name']} 317 {$nick} {$targetNick} {$idleTime} {$targetUser->getConnectTime()} :seconds idle, signon time");
    }
}