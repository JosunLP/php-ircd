<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class UserhostCommand extends CommandBase {
    /**
     * Executes the USERHOST command
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
            $this->sendError($user, 'USERHOST', 'Not enough parameters', 461);
            return;
        }
        
        // Sammle die Nicknamen (maximal 5 gemäß RFC 2812)
        $nicknames = array_slice($args, 1, 5);
        $responses = [];
        
        foreach ($nicknames as $targetNick) {
            // Suche den Benutzer
            $targetUser = null;
            foreach ($this->server->getUsers() as $serverUser) {
                if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === strtolower($targetNick)) {
                    $targetUser = $serverUser;
                    break;
                }
            }
            
            // Wenn Benutzer gefunden wurde, füge Informationen hinzu
            if ($targetUser !== null) {
                $response = $targetUser->getNick();
                
                // Markiere Operatoren mit einem "*"
                if ($targetUser->isOper()) {
                    $response .= '*';
                }
                
                // Füge "+" für verfügbare Benutzer oder "-" für abwesende Benutzer hinzu
                $response .= $targetUser->isAway() ? '-' : '+';
                
                // Füge Ident und Host hinzu
                $response .= $targetUser->getIdent() . '@' . $targetUser->getHost();
                
                $responses[] = $response;
            }
        }
        
        // Sende die Antwort
        if (!empty($responses)) {
            $user->send(":{$config['name']} 302 {$nick} :" . implode(' ', $responses));
        } else {
            $user->send(":{$config['name']} 302 {$nick} :");
        }
    }
}