<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class PingCommand extends CommandBase {
    /**
     * Führt den PING-Befehl aus
     * 
     * @param User $user Der ausführende Benutzer
     * @param array $args Die Befehlsargumente
     */
    public function execute(User $user, array $args): void {
        // Bei PING muss mindestens ein Parameter vorhanden sein
        if (!isset($args[1])) {
            $this->sendError($user, 'PING', 'Not enough parameters', 461);
            return;
        }
        
        // PING-Antwort mit PONG senden
        $target = $args[1];
        $server = $this->server->getConfig()['name'];
        
        // Der zweite Parameter ist optional
        $origin = isset($args[2]) ? $args[2] : $server;
        
        // PONG-Nachricht senden (RFC 1459)
        $user->send(":{$server} PONG {$server} :{$target}");
    }
}