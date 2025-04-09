<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class CapCommand extends CommandBase {
    /**
     * Executes the CAP command (IRCv3 capability negotiation)
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     */
    public function execute(User $user, array $args): void {
        // CAP must have at least one subcommand
        if (!isset($args[1])) {
            $this->sendError($user, 'CAP', 'Not enough parameters', 461);
            return;
        }
        
        $config = $this->server->getConfig();
        $nick = $user->getNick() ?? '*';
        $subcommand = strtoupper($args[1]);
        
        switch ($subcommand) {
            case 'LS':
                // List supported capabilities (we currently support none)
                $user->send(":{$config['name']} CAP {$nick} LS :");
                break;
                
            case 'LIST':
                // List active capabilities for this client (none)
                $user->send(":{$config['name']} CAP {$nick} LIST :");
                break;
                
            case 'REQ':
                // Client requesting capabilities - deny all requests since we don't support any
                if (isset($args[2])) {
                    $requestedCaps = $args[2];
                    $user->send(":{$config['name']} CAP {$nick} NAK :{$requestedCaps}");
                }
                break;
                
            case 'END':
                // End of capability negotiation - nothing to do
                break;
                
            default:
                // Unknown subcommand
                $user->send(":{$config['name']} CAP {$nick} NAK :{$subcommand}");
                break;
        }
    }
}