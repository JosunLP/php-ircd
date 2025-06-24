<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class WhowasCommand extends CommandBase {
    /**
     * Executes the WHOWAS command
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
        
        // Überprüfen, ob Nicknamen angegeben wurden
        if (count($args) < 2) {
            $this->sendError($user, 'WHOWAS', 'Not enough parameters', 461);
            return;
        }
        
        // Extrahiere den Nicknamen und optional die Anzahl der Ergebnisse
        $targetNick = $args[1];
        $count = isset($args[2]) && is_numeric($args[2]) ? (int)$args[2] : 10;
        
        // Hole die WHOWAS-Historie aus dem Server
        $whowasEntries = $this->server->getWhowasEntries($targetNick, $count);
        
        if (empty($whowasEntries)) {
            // Keine Einträge gefunden
            $user->send(":{$config['name']} 406 {$nick} {$targetNick} :There was no such nickname");
        } else {
            // Sende Informationen für jeden gefundenen Eintrag
            foreach ($whowasEntries as $entry) {
                $user->send(":{$config['name']} 314 {$nick} {$entry['nick']} {$entry['ident']} {$entry['host']} * :{$entry['realname']}");
                $user->send(":{$config['name']} 330 {$nick} {$entry['nick']} :Last seen: " . date('Y-m-d H:i:s', $entry['time']));
            }
        }
        
        // Ende der WHOWAS-Liste
        $user->send(":{$config['name']} 369 {$nick} {$targetNick} :End of WHOWAS");
    }
}