<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class AwayCommand extends CommandBase {
    /**
     * Executes the AWAY command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure that the user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // If no parameter is provided, reset AWAY status
        if (!isset($args[1])) {
            $user->setAway(null);
            $user->send(":{$config['name']} 305 {$nick} :You are no longer marked as being away");
            return;
        }
        
        // Extract AWAY message
        $message = $this->getMessagePart($args, 1);
        
        // If the message starts with :, remove the character
        if (isset($message[0]) && $message[0] === ':') {
            $message = substr($message, 1);
        }
        
        // Set AWAY status
        $user->setAway($message);
        $user->send(":{$config['name']} 306 {$nick} :You have been marked as being away");
    }
}