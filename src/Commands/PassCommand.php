<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Core\Server;
use PhpIrcd\Models\User;

/**
 * PASS command handler
 * 
 * Processes password authentication before registration
 */
class PassCommand extends CommandBase {
    /**
     * Executes the PASS command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Befehl ignorieren, wenn keine Argumente vorhanden sind
        if (count($args) < 1) {
            $this->sendError($user, 'PASS', 'Not enough parameters', 461);
            return;
        }

        // Passwort verarbeiten (ignorieren, da wir es derzeit nicht verwenden)
        // Hier wird das Passwort nur akzeptiert, aber nicht geprüft
        // In einer Produktionsumgebung würden Sie hier eine Passwortverfizierung implementieren
        
        // Setzt keine Flags, da der PASS-Befehl nur entgegengenommen wird
        $user->updateActivity();
    }
}