<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class CapCommand extends CommandBase {
    // Liste der unterstützten Capabilities
    private $supportedCapabilities = [
        'sasl',                  // SASL-Authentifizierung
        'multi-prefix',          // Mehrere Statuszeichen bei Benutzer in Kanälen
        'away-notify',           // Benachrichtigungen über Away-Status-Änderungen
        'extended-join',         // Erweitertes JOIN-Format mit Account und Realname
        'account-notify',        // Benachrichtigungen über Account-Änderungen
        'tls',                   // TLS-Unterstützung
        'server-time',           // Server-Zeitstempel für Nachrichten
        'batch',                 // Nachrichten-Batching
        'echo-message',          // Client erhält eigene Nachrichten zurück
        'cap-notify',            // Benachrichtigungen über Capability-Änderungen
        'invite-notify',         // Benachrichtigungen über Einladungen
        'chghost',               // Hostname-Änderungen
        'message-tags',          // Nachrichtenmarkierungen für Client-only Metadaten
        'userhost-in-names'      // Hostname in NAMES-Listen
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
                $capabilitiesWithValues = [
                    'sasl' => 'PLAIN,EXTERNAL',
                    'server-time' => '',
                    'batch' => '',
                    'echo-message' => '',
                    'cap-notify' => '',
                    'invite-notify' => '',
                    'chghost' => '',
                    'message-tags' => '',
                    'multi-prefix' => '',
                    'away-notify' => '',
                    'extended-join' => '',
                    'account-notify' => '',
                    'tls' => '',
                    'userhost-in-names' => ''
                ];
                
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
            // Prüfen, ob das Hinzufügen dieser Capability den Block zu lang macht
            if (strlen($currentBlock) + strlen($cap) + 1 > 400) { // +1 für das Leerzeichen
                $blocks[] = trim($currentBlock);
                $currentBlock = $cap;
            } else {
                $currentBlock .= (empty($currentBlock) ? '' : ' ') . $cap;
            }
        }
        
        // Den letzten Block hinzufügen, wenn er nicht leer ist
        if (!empty($currentBlock)) {
            $blocks[] = trim($currentBlock);
        }
        
        return $blocks;
    }
}