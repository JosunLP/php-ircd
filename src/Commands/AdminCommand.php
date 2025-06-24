<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class AdminCommand extends CommandBase {
    /**
     * Executes the ADMIN command
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
        
        // Administratorinformationen senden (RFC 2812 Format)
        $user->send(":{$config['name']} 256 {$nick} {$config['name']} :Administrative info");
        $user->send(":{$config['name']} 257 {$nick} :{$config['admin_location']}");
        $user->send(":{$config['name']} 258 {$nick} :{$config['admin_name']}");
        $user->send(":{$config['name']} 259 {$nick} :{$config['admin_email']}");
    }
}