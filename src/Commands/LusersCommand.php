<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class LusersCommand extends CommandBase {
    /**
     * Executes the LUSERS command
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
        
        // Benutzerstatistiken berechnen
        $userCount = count($this->server->getUsers());
        $channelCount = count($this->server->getChannels());
        $operCount = 0;
        
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->isOper()) {
                $operCount++;
            }
        }
        
        // Standard LUSERS Antwort senden
        $user->send(":{$config['name']} 251 {$nick} :There are {$userCount} users and 0 invisible on 1 servers");
        $user->send(":{$config['name']} 252 {$nick} {$operCount} :operator(s) online");
        $user->send(":{$config['name']} 254 {$nick} {$channelCount} :channels formed");
        $user->send(":{$config['name']} 255 {$nick} :I have {$userCount} clients and 0 servers");
        $user->send(":{$config['name']} 265 {$nick} :Current Local Users: {$userCount}  Max: {$userCount}");
        $user->send(":{$config['name']} 266 {$nick} :Current Global Users: {$userCount}  Max: {$userCount}");
    }
}