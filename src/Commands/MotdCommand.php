<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class MotdCommand extends CommandBase {
    /**
     * Führt den MOTD-Befehl aus
     * 
     * @param User $user Der ausführende Benutzer
     * @param array $args Die Befehlsargumente
     */
    public function execute(User $user, array $args): void {
        // Sicherstellen, dass der Benutzer registriert ist
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // MOTD-Header senden
        $user->send(":{$config['name']} 375 {$nick} :- {$config['name']} Message of the Day -");
        
        // MOTD-Inhalt in Zeilen aufteilen und senden
        $motdLines = explode($config['line_ending_conf'], $config['motd']);
        foreach ($motdLines as $line) {
            $user->send(":{$config['name']} 372 {$nick} :- {$line}");
        }
        
        // MOTD-Footer senden
        $user->send(":{$config['name']} 376 {$nick} :End of /MOTD command");
    }
}