<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class PongCommand extends CommandBase {
    /**
     * Führt den PONG-Befehl aus
     * 
     * @param User $user Der ausführende Benutzer
     * @param array $args Die Befehlsargumente
     */
    public function execute(User $user, array $args): void {
        // Bei PONG muss mindestens ein Parameter vorhanden sein
        if (!isset($args[1])) {
            // In der Praxis senden wir hier keinen Fehler, da manche Clients
            // nicht standardkonforme PONG-Antworten senden
            return;
        }
        
        // Aktualisiere die letzte Aktivitätszeit des Benutzers
        $user->updateActivity();
    }
}