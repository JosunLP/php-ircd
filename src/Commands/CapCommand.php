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
        $nick = $user->getNick() ?? '*';
        $subcommand = strtoupper($args[1]);
        
        switch ($subcommand) {
            case 'LS':
                // Überprüfen auf IRCv3.2 LS mit Parameter für paginierte Antworten
                $version = 302; // IRCv3.2
                
                // Prüfen, ob "CAP LS 302" (Version 3.2) verwendet wurde
                if (isset($args[2]) && is_numeric($args[2])) {
                    $version = (int)$args[2];
                    
                    // Client-Capability-Verhandlung markieren
                    $user->setCapabilityNegotiationInProgress(true);
                }
                
                // Capabilities mit Werten (für IRCv3.2+)
                $capabilitiesWithValues = [];
                
                // Füge nur unterstützte Capabilities hinzu
                $supportedCaps = $this->server->getSupportedCapabilities();
                foreach ($supportedCaps as $cap) {
                    switch ($cap) {
                        case 'sasl':
                            // Hole unterstützte Mechanismen aus der Konfiguration
                            $mechanisms = $config['sasl_mechanisms'] ?? ['PLAIN', 'EXTERNAL'];
                            $capabilitiesWithValues[$cap] = implode(',', $mechanisms);
                            break;
                        default:
                            // Für andere Capabilities keinen Wert angeben
                            $capabilitiesWithValues[$cap] = '';
                            break;
                    }
                }
                
                // Formatierte Capabilities ausgeben
                $formattedCaps = [];
                foreach ($capabilitiesWithValues as $cap => $value) {
                    $formattedCaps[] = empty($value) ? $cap : "{$cap}={$value}";
                }
                
                // Caps in Blöcken von maximal 400 Zeichen senden (um IRC Protokoll-Limits zu respektieren)
                $capsBlocks = $this->splitCapabilityList($formattedCaps);
                $isLast = false;
                
                foreach ($capsBlocks as $index => $block) {
                    $isLast = ($index == count($capsBlocks) - 1);
                    $marker = $isLast ? ' ' : ' * ';
                    $user->send(":{$config['name']} CAP {$nick} LS{$marker}:{$block}");
                }
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
                    $unsupportedCaps = array_diff($requestedCaps, $this->server->getSupportedCapabilities());
                    
                    if (empty($unsupportedCaps)) {
                        // All requested capabilities are supported
                        foreach ($requestedCaps as $cap) {
                            $user->addCapability(strtolower($cap));
                        }
                        
                        $user->send(":{$config['name']} CAP {$nick} ACK :{$args[2]}");
                        
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
                        $user->send(":{$config['name']} CAP {$nick} NAK :{$args[2]}");
                    }
                }
                break;
                
            case 'END':
                // End of capability negotiation
                $user->setCapabilityNegotiationInProgress(false);
                
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