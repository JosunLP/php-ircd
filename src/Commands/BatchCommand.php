<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;
use PhpIrcd\Utils\IRCv3Helper;

class BatchCommand extends CommandBase {
    /**
     * Executes the BATCH command (IRCv3 batched messages)
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure the user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        // Überprüfen, ob der Benutzer die batch-Capability aktiviert hat
        if (!$user->hasCapability('batch')) {
            $this->sendError($user, 'BATCH', 'Capability not enabled', 410);
            return;
        }
        
        // Überprüfen, ob genügend Parameter vorhanden sind
        if (!isset($args[1])) {
            $this->sendError($user, 'BATCH', 'Not enough parameters', 461);
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Ein + am Anfang des ersten Parameters bedeutet, dass ein neuer Batch gestartet wird
        if (substr($args[1], 0, 1) === '+') {
            // Einen neuen Batch starten
            $batchReference = substr($args[1], 1);
            
            // Überprüfen, ob ein Batch-Typ angegeben ist
            if (!isset($args[2])) {
                $this->sendError($user, 'BATCH', 'No batch type specified', 461);
                return;
            }
            
            $batchType = $args[2];
            
            // Parameter für den Batch
            $parameters = array_slice($args, 3);
            
            // Batch-Start an den Client senden
            $paramString = !empty($parameters) ? ' ' . implode(' ', $parameters) : '';
            $user->send(":{$config['name']} BATCH +{$batchReference} {$batchType}{$paramString}");
        } 
        // Ein - am Anfang des ersten Parameters bedeutet, dass ein Batch beendet wird
        else if (substr($args[1], 0, 1) === '-') {
            // Einen existierenden Batch beenden
            $batchReference = substr($args[1], 1);
            
            // Batch-Ende an den Client senden
            $user->send(":{$config['name']} BATCH -{$batchReference}");
        }
        else {
            // Ungültiges Format
            $this->sendError($user, 'BATCH', 'Invalid format', 461);
        }
    }
}