<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class InfoCommand extends CommandBase {
    /**
     * Executes the INFO command
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
        
        // Header für INFO-Antwort
        $user->send(":{$config['name']} 371 {$nick} :Server Info:");
        
        // Allgemeine Server-Informationen senden
        if (isset($config['server_info']) && is_array($config['server_info'])) {
            foreach ($config['server_info'] as $info) {
                $user->send(":{$config['name']} 371 {$nick} :{$info}");
            }
        }
        
        // Informationen über PHP-Version hinzufügen
        $user->send(":{$config['name']} 371 {$nick} :Läuft auf PHP " . phpversion());
        $user->send(":{$config['name']} 371 {$nick} :Betriebssystem: " . php_uname());
        
        // End of INFO
        $user->send(":{$config['name']} 374 {$nick} :End of INFO list");
    }
}