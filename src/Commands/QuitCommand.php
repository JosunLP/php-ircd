<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class QuitCommand extends CommandBase {
    /**
     * Führt den QUIT-Befehl aus
     * 
     * @param User $user Der ausführende Benutzer
     * @param array $args Die Befehlsargumente
     */
    public function execute(User $user, array $args): void {
        // Abschiedsnachricht extrahieren oder Standardnachricht setzen
        $message = isset($args[1]) ? $this->getMessagePart($args, 1) : "Client Quit";
        
        // Verbindungshandler holen
        $connectionHandler = $this->server->getConnectionHandler();
        
        // Benutzer trennen
        $connectionHandler->disconnectUser($user, $message);
    }
}