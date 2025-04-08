<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class QuitCommand extends CommandBase {
    /**
     * Executes the QUIT command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // Extract farewell message or set default message
        $message = isset($args[1]) ? $this->getMessagePart($args, 1) : "Client Quit";
        
        // Get connection handler
        $connectionHandler = $this->server->getConnectionHandler();
        
        // Disconnect user
        $connectionHandler->disconnectUser($user, $message);
    }
}