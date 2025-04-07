<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class OperCommand extends CommandBase {
    /**
     * Führt den OPER-Befehl aus
     * 
     * @param User $user Der ausführende Benutzer
     * @param array $args Die Befehlsargumente
     */
    public function execute(User $user, array $args): void {
        // Sicherstellen, dass der Benutzer registriert ist
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        // Prüfen, ob genügend Parameter vorhanden sind
        if (!isset($args[1]) || !isset($args[2])) {
            $this->sendError($user, 'OPER', 'Not enough parameters', 461);
            return;
        }
        
        $username = $args[1];
        $password = $args[2];
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Prüfen, ob der Benutzername und das Passwort stimmen
        if (!isset($config['opers'][$username]) || $config['opers'][$username] !== $password) {
            $user->send(":{$config['name']} 464 {$nick} :Password incorrect");
            return;
        }
        
        // Oper-Status setzen
        $user->setOper(true);
        $user->setMode('o', true);
        
        // Erfolgsbenachrichtigung senden
        $user->send(":{$config['name']} 381 {$nick} :You are now an IRC operator");
        
        // Benachrichtigung an alle Benutzer mit +s-Mode senden
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->hasMode('s')) {
                $serverUser->send(":{$config['name']} NOTICE {$serverUser->getNick()} :*** Notice -- {$nick} is now an IRC operator");
            }
        }
    }
}