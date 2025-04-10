<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class IsonCommand extends CommandBase {
    /**
     * Executes the ISON command
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
            $this->sendError($user, 'ISON', 'Not enough parameters', 461);
            return;
        }
        
        // Nicknamen zum Überprüfen (alle Parameter nach dem Befehl)
        $checkNicks = array_slice($args, 1);
        $onlineNicks = [];
        
        // Überprüfe jeden angegebenen Nicknamen
        foreach ($checkNicks as $checkNick) {
            foreach ($this->server->getUsers() as $serverUser) {
                if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === strtolower($checkNick)) {
                    $onlineNicks[] = $serverUser->getNick();
                    break;
                }
            }
        }
        
        // Sende die Antwort mit allen gefundenen Online-Nutzern
        $user->send(":{$config['name']} 303 {$nick} :" . implode(' ', $onlineNicks));
    }
}