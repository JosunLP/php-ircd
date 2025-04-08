<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class PongCommand extends CommandBase {
    /**
     * Executes the PONG command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // PONG must have at least one parameter
        if (!isset($args[1])) {
            // In practice, we do not send an error here because some clients
            // send non-standard PONG responses
            return;
        }
        
        // Update the user's last activity time
        $user->updateActivity();
    }
}