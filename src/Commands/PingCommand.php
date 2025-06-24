<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class PingCommand extends CommandBase {
    /**
     * Executes the PING command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // At least one parameter must be present for PING
        if (!isset($args[1])) {
            $this->sendError($user, 'PING', 'Not enough parameters', 461);
            return;
        }
        
        // Send PING response with PONG
        $token = $args[1];
        $server = $this->server->getConfig()['name'];
        
        // Standard IRC PONG response format
        $user->send(":{$server} PONG {$server} :{$token}");
        
        // Update user activity timestamp
        $user->updateActivity();
    }
}