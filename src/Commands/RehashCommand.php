<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;
use PhpIrcd\Core\Config;

class RehashCommand extends CommandBase {
    /**
     * Executes the REHASH command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure the user is registered
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        // Only IRC operators can use the REHASH command
        if (!$this->ensureOper($user)) {
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        try {
            // Load configuration from file
            $configPath = dirname(dirname(__DIR__)) . '/config.php';
            $newConfig = new Config($configPath);
            
            // Update server configuration
            $this->server->updateConfig($newConfig->getAll());
            
            // Notify the user
            $user->send(":{$config['name']} 382 {$nick} {$configPath} :Rehashing server configuration");
            
            // Log the rehash
            $this->server->getLogger()->info("Server configuration rehashed by operator {$nick}");
            
            // Notify all users with server notice mode
            foreach ($this->server->getUsers() as $serverUser) {
                if ($serverUser->hasMode('s')) {
                    $serverUser->send(":{$config['name']} NOTICE {$serverUser->getNick()} :*** Notice -- {$nick} is rehashing server configuration");
                }
            }
        } catch (\Exception $e) {
            // Notify the user of the error
            $user->send(":{$config['name']} 382 {$nick} {$configPath} :Rehash failed: {$e->getMessage()}");
            
            // Log the error
            $this->server->getLogger()->error("Rehash failed: {$e->getMessage()}");
        }
    }
}