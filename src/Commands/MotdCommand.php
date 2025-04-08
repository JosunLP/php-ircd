<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class MotdCommand extends CommandBase {
    /**
     * Executes the MOTD command
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
        
        // Send MOTD header
        $user->send(":{$config['name']} 375 {$nick} :- {$config['name']} Message of the Day -");
        
        // Split MOTD content into lines and send
        $motdLines = explode($config['line_ending_conf'], $config['motd']);
        foreach ($motdLines as $line) {
            $user->send(":{$config['name']} 372 {$nick} :- {$line}");
        }
        
        // Send MOTD footer
        $user->send(":{$config['name']} 376 {$nick} :End of /MOTD command");
    }
}