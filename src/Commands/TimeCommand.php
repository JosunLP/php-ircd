<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class TimeCommand extends CommandBase {
    /**
     * Executes the TIME command
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
        
        // Current time in RFC 2822 format
        $date = date('r');
        
        // Send the time reply - format: <server name> :<time string>
        $user->send(":{$config['name']} 391 {$nick} {$config['name']} :{$date}");
    }
}