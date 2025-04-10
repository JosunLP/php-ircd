<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class OperCommand extends CommandBase {
    /**
     * Executes the OPER command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure the user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        // Check if enough parameters are provided
        if (!isset($args[1]) || !isset($args[2])) {
            $this->sendError($user, 'OPER', 'Not enough parameters', 461);
            return;
        }
        
        $username = $args[1];
        $password = $args[2];
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Überprüfe, ob die Operator-Einstellungen in der Konfiguration vorhanden sind
        if (!isset($config['opers']) || !is_array($config['opers'])) {
            $user->send(":{$config['name']} 491 {$nick} :No O-lines available for your host");
            return;
        }
        
        // Überprüfe, ob der Benutzername und das Passwort korrekt sind
        if (!isset($config['opers'][$username]) || $config['opers'][$username] !== $password) {
            $this->server->getLogger()->warning("Failed OPER attempt from {$user->getIp()} ({$nick}): Invalid credentials");
            $user->send(":{$config['name']} 464 {$nick} :Password incorrect");
            return;
        }
        
        // Setze den Operator-Status
        $user->setOper(true);
        $user->setMode('o', true);
        
        // Sende eine Erfolgsmeldung
        $user->send(":{$config['name']} 381 {$nick} :You are now an IRC operator");
        $this->server->getLogger()->info("User {$nick} ({$user->getIp()}) has become an IRC operator");
        
        // Benachrichtige alle Benutzer mit +s Modus
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->hasMode('s')) {
                $serverUser->send(":{$config['name']} NOTICE {$serverUser->getNick()} :*** Notice -- {$nick} is now an IRC operator");
            }
        }
    }
}