<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class AwayCommand extends CommandBase {
    /**
     * Führt den AWAY-Befehl aus
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
        
        // Wenn kein Parameter angegeben ist, AWAY-Status zurücksetzen
        if (!isset($args[1])) {
            $user->setAway(null);
            $user->send(":{$config['name']} 305 {$nick} :You are no longer marked as being away");
            return;
        }
        
        // AWAY-Nachricht extrahieren
        $message = $this->getMessagePart($args, 1);
        
        // Wenn die Nachricht mit : beginnt, das Zeichen entfernen
        if (isset($message[0]) && $message[0] === ':') {
            $message = substr($message, 1);
        }
        
        // AWAY-Status setzen
        $user->setAway($message);
        $user->send(":{$config['name']} 306 {$nick} :You have been marked as being away");
    }
}