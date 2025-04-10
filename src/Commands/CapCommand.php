<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class CapCommand extends CommandBase {
    // Liste der unterstützten Capabilities
    private $supportedCapabilities = [
        'sasl',           // SASL-Authentifizierung
        'multi-prefix',   // Mehrere Statuszeichen bei Benutzer in Kanälen
        'away-notify',    // Benachrichtigungen über Away-Status-Änderungen
        'extended-join',  // Erweitertes JOIN-Format mit Account und Realname
        'account-notify', // Benachrichtigungen über Account-Änderungen
        'tls'             // TLS-Unterstützung
    ];
    
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
                // List supported capabilities
                $caps = implode(' ', $this->supportedCapabilities);
                $user->send(":{$config['name']} CAP {$nick} LS :{$caps}");
                break;
                
            case 'LIST':
                // List active capabilities for this client
                $activeCapabilities = $user->getCapabilities();
                $caps = !empty($activeCapabilities) ? implode(' ', $activeCapabilities) : '';
                $user->send(":{$config['name']} CAP {$nick} LIST :{$caps}");
                break;
                
            case 'REQ':
                // Client requesting capabilities
                if (isset($args[2])) {
                    $requestedCaps = explode(' ', $args[2]);
                    
                    // Check if all requested capabilities are supported
                    $unsupportedCaps = array_diff($requestedCaps, $this->supportedCapabilities);
                    
                    if (empty($unsupportedCaps)) {
                        // All requested capabilities are supported
                        foreach ($requestedCaps as $cap) {
                            $user->addCapability(strtolower($cap));
                        }
                        
                        $user->send(":{$config['name']} CAP {$nick} ACK :{$args[2]}");
                        
                        // If SASL requested, inform client
                        if (in_array('sasl', $requestedCaps)) {
                            $this->server->getLogger()->info("User {$nick} ({$user->getIp()}) requested SASL authentication");
                        }
                    } else {
                        // Some capabilities are not supported
                        $user->send(":{$config['name']} CAP {$nick} NAK :{$args[2]}");
                    }
                }
                break;
                
            case 'END':
                // End of capability negotiation
                // If user has SASL capability but hasn't authenticated, 
                // they might be stalled during registration
                if ($user->hasCapability('sasl') && !$user->isSaslAuthenticated() && !$user->isSaslInProgress()) {
                    // Auto-complete registration if needed
                    if ($user->getNick() !== null && $user->getIdent() !== null) {
                        $this->server->getLogger()->info("User {$nick} ({$user->getIp()}) ended capability negotiation without SASL auth");
                    }
                }
                break;
                
            case 'CLEAR':
                // Clear all active capabilities
                $user->send(":{$config['name']} CAP {$nick} ACK :*");
                break;
                
            default:
                // Unknown subcommand
                $user->send(":{$config['name']} CAP {$nick} NAK :{$subcommand}");
                break;
        }
    }
}