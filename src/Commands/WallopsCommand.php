<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class WallopsCommand extends CommandBase {
    /**
     * Executes the WALLOPS command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure the user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        // Überprüfen ob der Benutzer ein Operator ist
        if (!$this->ensureOper($user)) {
            return;
        }
        
        // Überprüfen ob genügend Parameter übergeben wurden
        if (!isset($args[1])) {
            $this->sendError($user, 'WALLOPS', 'Not enough parameters', 461);
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Nachricht extrahieren
        $message = $this->getMessagePart($args, 1);
        
        // Wenn Nachricht leer ist, Fehler senden
        if (empty($message)) {
            $this->sendError($user, 'WALLOPS', 'No message specified', 412);
            return;
        }
        
        // WALLOPS-Nachricht an alle Operatoren senden
        $formattedMessage = ":{$nick}!{$user->getIdent()}@{$user->getCloak()} WALLOPS :{$message}";
        foreach ($this->server->getUsers() as $targetUser) {
            // Sende nur an Operatoren und Benutzer mit dem 'w' Modus
            if ($targetUser->isOper() || $targetUser->hasMode('w')) {
                $targetUser->send($formattedMessage);
            }
        }
        
        // Logge die WALLOPS-Nachricht
        $this->server->getLogger()->info("WALLOPS from {$nick}: {$message}");
    }
}