<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class OperCommand extends CommandBase {
    /**
     * Executes the OPER command
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
            $this->sendError($user, 'OPER', 'Not enough parameters', 461);
            return;
        }
        
        $username = $args[1];
        $password = $args[2];
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Check if the username and password are correct
        if (!isset($config['opers'][$username]) || $config['opers'][$username] !== $password) {
            $user->send(":{$config['name']} 464 {$nick} :Password incorrect");
            return;
        }
        
        // Set oper status
        $user->setOper(true);
        $user->setMode('o', true);
        
        // Send success notification
        $user->send(":{$config['name']} 381 {$nick} :You are now an IRC operator");
        
        // Notify all users with +s mode
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->hasMode('s')) {
                $serverUser->send(":{$config['name']} NOTICE {$serverUser->getNick()} :*** Notice -- {$nick} is now an IRC operator");
            }
        }
    }
}