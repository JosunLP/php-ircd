<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class LinksCommand extends CommandBase {
    /**
     * Executes the LINKS command
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
        
        // Da dies ein Einzelserver ist, gibt es nur diesen Server
        // In einem Netzwerk würden hier Informationen über andere Server angezeigt werden
        $user->send(":{$config['name']} 364 {$nick} {$config['name']} {$config['name']} :0 {$config['description']}");
        $user->send(":{$config['name']} 365 {$nick} * :End of /LINKS list");
    }
}