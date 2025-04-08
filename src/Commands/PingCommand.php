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
        $target = $args[1];
        $server = $this->server->getConfig()['name'];
        
        // The second parameter is optional
        $origin = isset($args[2]) ? $args[2] : $server;
        
        // Send PONG message (RFC 1459)
        $user->send(":{$server} PONG {$server} :{$target}");
    }
}