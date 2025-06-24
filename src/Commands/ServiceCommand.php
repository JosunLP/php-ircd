<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class ServiceCommand extends CommandBase {
    /**
     * Executes the SERVICE command (registers a new service)
     * According to RFC 2812 Section 3.1.6
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Ensure we have enough parameters
        // SERVICE requires: <nickname> <reserved> <distribution> <type> <reserved> <info>
        if (count($args) < 7) {
            $this->sendError($user, 'SERVICE', 'Not enough parameters', 461);
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        
        // Service registrations are typically restricted 
        // Only allow if from a trusted source or operator
        if (!$user->isOper()) {
            $user->send(":{$config['name']} 481 {$nick} :Permission Denied - Services registration requires operator privileges");
            return;
        }
        
        $serviceName = $args[1];
        // $reserved1 = $args[2]; // Reserved parameter
        $distribution = $args[3];
        $type = $args[4];
        // $reserved2 = $args[5]; // Reserved parameter
        $info = $this->getMessagePart($args, 6);
        
        // Check if service name is valid (similar to nickname validation)
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9\-\[\]\\`^{}]*$/', $serviceName)) {
            $user->send(":{$config['name']} 432 {$nick} {$serviceName} :Erroneous Service Name");
            return;
        }
        
        // Check if the service name is already in use
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === strtolower($serviceName)) {
                $user->send(":{$config['name']} 433 {$nick} {$serviceName} :Service name already in use");
                return;
            }
        }
        
        // At this point, we would typically register the service
        // For this implementation, we'll just acknowledge it
        $this->server->getLogger()->info("Service {$serviceName} of type {$type} registered by {$nick}");
        
        // Send confirmation to the user
        $user->send(":{$config['name']} 383 {$nick} {$serviceName} :Service registered");
        
        // Broadcast to operators
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->isOper() && $serverUser !== $user) {
                $serverUser->send(":{$config['name']} NOTICE {$serverUser->getNick()} :*** Service {$serviceName} ({$type}) registered by {$nick}");
            }
        }
    }
}