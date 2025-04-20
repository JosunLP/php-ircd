<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class CapCommand extends CommandBase {
    // Liste der unterstützten Capabilities
    private $supportedCapabilities = [
        'multi-prefix' => true,      // Mehrere Präfixe für Benutzer im Kanal
        'away-notify' => true,       // Benachrichtigung wenn Benutzer away-Status ändert
        'server-time' => true,       // Zeitstempel für Nachrichten
        'batch' => true,             // Nachrichtenbündelung
        'message-tags' => true,      // Tags in Nachrichten
        'echo-message' => true,      // Echo der eigenen Nachrichten
        'invite-notify' => true,     // Benachrichtigungen über Einladungen
        'extended-join' => true,     // Erweiterte JOIN-Befehle mit Realname
        'userhost-in-names' => true, // Vollständige Hostmasken in NAMES-Liste
        'chathistory' => true,       // Abruf der Kanalhistorie
        'account-notify' => true,    // Kontoauthentifizierungsänderungen
        'account-tag' => true,       // Account-Tags in Nachrichten
        'cap-notify' => true,        // Benachrichtigungen über CAP-Änderungen
        'chghost' => true,           // Host-Änderungsbenachrichtigungen
        'sasl' => true               // SASL-Authentifizierung
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
        $subcommand = strtoupper($args[1]);
        
        switch ($subcommand) {
            case 'LS':
                $this->handleLS($user, $args, $config);
                break;
                
            case 'LIST':
                $this->handleLIST($user, $args, $config);
                break;
                
            case 'REQ':
                $this->handleREQ($user, $args, $config);
                break;
                
            case 'END':
                $this->handleEND($user, $args, $config);
                break;
                
            case 'CLEAR':
                // Clear all active capabilities
                $oldCaps = $user->getCapabilities();
                $user->clearCapabilities();
                $capsString = implode(' ', $oldCaps);
                
                if (!empty($capsString)) {
                    $user->send(":{$config['name']} CAP {$nick} ACK :-{$capsString}");
                } else {
                    $user->send(":{$config['name']} CAP {$nick} ACK :*");
                }
                break;
                
            default:
                // Unknown subcommand
                $user->send(":{$config['name']} CAP {$nick} NAK :{$subcommand}");
                break;
        }
    }
    
    /**
     * Processes the CAP LS command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     * @param Config $config The server configuration
     */
    private function handleLS(User $user, array $args, array $config): void {
        try {
            $nick = $user->getNick() ?? '*';
            $version = isset($args[1]) ? (int)$args[1] : 301;
            
            // Send available capabilities
            $capabilities = $this->getAvailableCapabilities($config, $version);
            
            if ($version >= 302) {
                // IRCv3.2 behavior: Send multi-line CAP LS if needed
                $maxLineLength = 450; // Leave room for command prefix
                $currentLine = '';
                
                foreach ($capabilities as $cap) {
                    // Check if adding this cap would exceed the line length
                    if (strlen($currentLine) + strlen($cap) + 1 > $maxLineLength) {
                        // Send current line and start a new one
                        $user->send(":{$config['name']} CAP {$nick} LS * :{$currentLine}");
                        $currentLine = $cap;
                    } else {
                        // Append to current line
                        if ($currentLine !== '') {
                            $currentLine .= ' ' . $cap;
                        } else {
                            $currentLine = $cap;
                        }
                    }
                }
                
                // Send final line, with * replaced by empty string to indicate end of list
                $user->send(":{$config['name']} CAP {$nick} LS :{$currentLine}");
            } else {
                // IRCv3.1 behavior: Send single line
                $capsString = implode(' ', $capabilities);
                $user->send(":{$config['name']} CAP {$nick} LS :{$capsString}");
            }
            
            // Mark that capability negotiation is in progress
            $user->setCapabilityNegotiationInProgress(true);
        } catch (\Exception $e) {
            $this->server->getLogger()->error("Error in CAP LS command: " . $e->getMessage());
            $user->send(":{$config['name']} CAP {$nick} LS :error"); // Sende dem Client eine Fehlermeldung
        }
    }
    
    /**
     * Processes the CAP LIST command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     * @param Config $config The server configuration
     */
    private function handleLIST(User $user, array $args, array $config): void {
        try {
            $nick = $user->getNick() ?? '*';
            
            // Get user's active capabilities
            $activeCaps = $user->getCapabilities();
            $capsString = implode(' ', $activeCaps);
            
            // Send list of active capabilities
            $user->send(":{$config['name']} CAP {$nick} LIST :{$capsString}");
        } catch (\Exception $e) {
            $this->server->getLogger()->error("Error in CAP LIST command: " . $e->getMessage());
            $user->send(":{$config['name']} CAP {$nick} LIST :error");
        }
    }
    
    /**
     * Processes the CAP REQ command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     * @param Config $config The server configuration
     */
    private function handleREQ(User $user, array $args, array $config): void {
        try {
            $nick = $user->getNick() ?? '*';
            
            // CAP REQ must have parameters
            if (!isset($args[2])) {
                $user->send(":{$config['name']} CAP {$nick} NAK :No capabilities requested");
                return;
            }
            
            // Extract requested capabilities
            $requestedCapsStr = substr(implode(' ', array_slice($args, 2)), 1); // Remove leading colon
            $requestedCaps = explode(' ', $requestedCapsStr);
            
            // Check if all requested capabilities are available
            $availableCaps = $this->getAvailableCapabilities($config);
            $allAvailable = true;
            
            foreach ($requestedCaps as $cap) {
                // Ignore empty entries
                if (empty($cap)) continue;
                
                // Remove -/+ prefix for checking availability
                $cleanCap = $cap;
                if ($cap[0] === '-' || $cap[0] === '+') {
                    $cleanCap = substr($cap, 1);
                }
                
                // Check if capability is available
                if (!in_array($cleanCap, array_map(function($c) { 
                    // If a cap contains '=', just check the part before =
                    $parts = explode('=', $c);
                    return $parts[0]; 
                }, $availableCaps))) {
                    $allAvailable = false;
                    break;
                }
            }
            
            // If all capabilities are available, add/remove them and acknowledge
            if ($allAvailable) {
                foreach ($requestedCaps as $cap) {
                    // Skip empty entries
                    if (empty($cap)) continue;
                    
                    $remove = false;
                    $add = true;
                    
                    // Check for remove/add prefix
                    if ($cap[0] === '-') {
                        $remove = true;
                        $add = false;
                        $cap = substr($cap, 1);
                    } else if ($cap[0] === '+') {
                        $cap = substr($cap, 1);
                    }
                    
                    // Extract base capability without value part
                    $baseCap = explode('=', $cap)[0];
                    
                    // Add or remove capability
                    if ($add) {
                        $user->addCapability($baseCap);
                    } else if ($remove) {
                        $user->removeCapability($baseCap);
                    }
                }
                
                $user->send(":{$config['name']} CAP {$nick} ACK :{$requestedCapsStr}");
                
                // If SASL requested, inform client and set the flag
                if (in_array('sasl', $requestedCaps)) {
                    $this->server->getLogger()->info("User {$nick} ({$user->getIp()}) requested SASL authentication");
                    
                    // Markiere, dass SASL-Authentifizierung möglich ist
                    if (!$user->isSaslAuthenticated()) {
                        // Der User kann nun AUTHENTICATE verwenden
                        $user->setUndergoing302Negotiation(true);
                    }
                }
            } else {
                // Some capabilities are not supported
                $user->send(":{$config['name']} CAP {$nick} NAK :{$requestedCapsStr}");
            }
        } catch (\Exception $e) {
            $this->server->getLogger()->error("Error in CAP REQ command: " . $e->getMessage());
            $user->send(":{$config['name']} CAP {$nick} NAK :{$requestedCapsStr}");
        }
    }
    
    /**
     * Processes the CAP END command
     * 
     * @param User $user The executing user
     * @param array $args The command arguments
     * @param Config $config The server configuration
     */
    private function handleEND(User $user, array $args, array $config): void {
        try {
            $nick = $user->getNick() ?? '*';
            
            // End capability negotiation
            $user->setCapabilityNegotiationInProgress(false);
            
            // If the user has registered with the server, they can now receive server messages
            if ($user->isRegistered()) {
                // Send welcome messages if not already sent
                // This would be implemented in the server registration process
            }
        } catch (\Exception $e) {
            $this->server->getLogger()->error("Error in CAP END command: " . $e->getMessage());
        }
    }
    
    /**
     * Teilt eine Liste von Capabilities in Blöcke auf, die nicht länger als 400 Zeichen sind
     * Dies ist notwendig, um IRC-Protokoll-Limits zu respektieren
     * 
     * @param array $capabilities Die zu teilende Capability-Liste
     * @return array Ein Array von Capability-Listen-Strings
     */
    private function splitCapabilityList(array $capabilities): array {
        $blocks = [];
        $currentBlock = '';
        
        foreach ($capabilities as $cap) {
            if (strlen($currentBlock . ' ' . $cap) > 400) {
                $blocks[] = trim($currentBlock);
                $currentBlock = $cap;
            } else {
                $currentBlock .= (empty($currentBlock) ? '' : ' ') . $cap;
            }
        }
        
        if (!empty($currentBlock)) {
            $blocks[] = trim($currentBlock);
        }
        
        return $blocks;
    }
}