<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class NickCommand extends CommandBase {
    /**
     * Executes the NICK command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Check if a nickname was provided
        if (!isset($args[1])) {
            $this->sendError($user, 'NICK', 'No nickname given', 431);
            return;
        }
        
        $newNick = $args[1];
        
        // Sometimes clients send :nick instead of nick
        if (strpos($newNick, ':') === 0) {
            $newNick = substr($newNick, 1);
        }
        
        // Validate the nickname
        if (!$this->validateNick($newNick)) {
            $this->sendError($user, $newNick, 'Erroneous Nickname: You fail.', 432);
            return;
        }
        
        // Check if the nickname is already in use
        $users = $this->server->getUsers();
        foreach ($users as $existingUser) {
            if ($existingUser !== $user && 
                $existingUser->getNick() !== null && 
                strtolower($existingUser->getNick()) === strtolower($newNick)) {
                $currentNick = $user->getNick() ?? '*';
                $user->send(":{$this->server->getConfig()['name']} 433 {$currentNick} {$newNick} :Nickname is already in use.");
                return;
            }
        }
        
        $oldNick = $user->getNick();
        
        // If this is the first NICK command (registration)
        if ($oldNick === null) {
            $user->setNick($newNick);
            
            // If the user is fully registered, send a PING request
            if ($user->isRegistered()) {
                $user->send("PING :{$this->server->getConfig()['name']}");
            }
        } else {
            // Notify all relevant channels about the nickname change
            $user->setNick($newNick);
            
            $notifiedUsers = [$user]; // Users already notified
            
            // Iterate through all channels the user is in
            foreach ($this->server->getChannels() as $channel) {
                if ($channel->hasUser($user)) {
                    // Notify all users in the channel
                    foreach ($channel->getUsers() as $channelUser) {
                        if (!in_array($channelUser, $notifiedUsers, true)) {
                            $channelUser->send(":{$oldNick}!{$user->getIdent()}@{$user->getCloak()} NICK {$newNick}");
                            $notifiedUsers[] = $channelUser;
                        }
                    }
                }
            }
            
            // Send WATCH notifications about the nickname change
            $this->server->broadcastWatchNotifications($user, true, $oldNick);
        }
    }
    
    /**
     * Validates a nickname according to IRC rules
     * 
     * @param string $nick The nickname to validate
     * @return bool Whether the nickname is valid
     */
    private function validateNick(string $nick): bool {
        // IRC nickname rules: letters, numbers, special characters, max. 30 characters
        return preg_match('/^[a-zA-Z\[\]_|`^][a-zA-Z0-9\[\]_|`^]{0,29}$/', $nick) === 1;
    }
}