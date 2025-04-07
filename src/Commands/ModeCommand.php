<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;

class ModeCommand extends CommandBase {
    /**
     * Führt den MODE-Befehl aus
     * 
     * @param User $user Der ausführende Benutzer
     * @param array $args Die Befehlsargumente
     */
    public function execute(User $user, array $args): void {
        // Sicherstellen, dass der Benutzer registriert ist
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        // Prüfen, ob genügend Parameter vorhanden sind
        if (!isset($args[1])) {
            $this->sendError($user, 'MODE', 'Not enough parameters', 461);
            return;
        }
        
        $target = $args[1];
        
        // Kanalname beginnt mit #
        if ($target[0] === '#') {
            $this->handleChannelMode($user, $args);
        } else {
            $this->handleUserMode($user, $args);
        }
    }
    
    /**
     * Behandelt Benutzermodi
     * 
     * @param User $user Der ausführende Benutzer
     * @param array $args Die Befehlsargumente
     */
    private function handleUserMode(User $user, array $args): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        $target = $args[1];
        
        // Zielbenutzer suchen
        $targetUser = null;
        foreach ($this->server->getUsers() as $serverUser) {
            if ($serverUser->getNick() !== null && strtolower($serverUser->getNick()) === strtolower($target)) {
                $targetUser = $serverUser;
                break;
            }
        }
        
        // Wenn Benutzer nicht gefunden wurde, Fehler senden
        if ($targetUser === null) {
            $user->send(":{$config['name']} 401 {$nick} {$target} :No such nick/channel");
            return;
        }
        
        // Andere Benutzer dürfen die Modi nicht ändern
        if ($targetUser !== $user && !$user->isOper()) {
            $user->send(":{$config['name']} 502 {$nick} :Can't change mode for other users");
            return;
        }
        
        // Wenn kein Modus angegeben ist, aktuellen Modus anzeigen
        if (!isset($args[2])) {
            $modes = $targetUser->getModes();
            if (!empty($modes)) {
                $user->send(":{$config['name']} 221 {$nick} +{$modes}");
            } else {
                $user->send(":{$config['name']} 221 {$nick}");
            }
            return;
        }
        
        // Modi ändern
        $modes = $args[2];
        $adding = true;
        
        for ($i = 0; $i < strlen($modes); $i++) {
            $char = $modes[$i];
            
            if ($char === '+') {
                $adding = true;
                continue;
            }
            
            if ($char === '-') {
                $adding = false;
                continue;
            }
            
            // Nur bestimmte Modi sind erlaubt
            switch ($char) {
                case 'i': // Invisible
                case 'w': // Wallops
                case 's': // Server notices
                    $targetUser->setMode($char, $adding);
                    break;
                
                case 'o': // Oper
                    // Oper-Status kann nur von einem Server gesetzt werden
                    if (!$adding && $user === $targetUser) {
                        $targetUser->setOper(false);
                        $targetUser->setMode('o', false);
                    }
                    break;
                
                default:
                    // Unbekannter Modus, ignorieren
                    break;
            }
        }
        
        // Modus-Änderung an alle Benutzer senden
        $newModes = $targetUser->getModes();
        if (!empty($newModes)) {
            $modeMessage = ":{$nick}!{$user->getIdent()}@{$user->getCloak()} MODE {$target} :+{$newModes}";
            $targetUser->send($modeMessage);
        }
    }
    
    /**
     * Behandelt Kanalmodi
     * 
     * @param User $user Der ausführende Benutzer
     * @param array $args Die Befehlsargumente
     */
    private function handleChannelMode(User $user, array $args): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        $channelName = $args[1];
        
        // Kanal suchen
        $channel = $this->server->getChannel($channelName);
        
        // Wenn Kanal nicht gefunden wurde, Fehler senden
        if ($channel === null) {
            $user->send(":{$config['name']} 403 {$nick} {$channelName} :No such channel");
            return;
        }
        
        // Wenn kein Modus angegeben ist, aktuellen Modus anzeigen
        if (!isset($args[2])) {
            $modeStr = $channel->getModeString();
            $modeParams = $channel->getModeParams();
            $paramStr = !empty($modeParams) ? ' ' . implode(' ', $modeParams) : '';
            $user->send(":{$config['name']} 324 {$nick} {$channelName} +{$modeStr}{$paramStr}");
            $user->send(":{$config['name']} 329 {$nick} {$channelName} {$channel->getCreationTime()}");
            return;
        }
        
        // Prüfen, ob der Benutzer Operator im Kanal ist
        if (!$channel->isOperator($user) && !$user->isOper()) {
            $user->send(":{$config['name']} 482 {$nick} {$channelName} :You're not channel operator");
            return;
        }
        
        // Modi ändern
        $modes = $args[2];
        $adding = true;
        $modeIndex = 3; // Index für Mode-Parameter
        $modeChanges = ['+' => '', '-' => '']; // Änderungen für die Ankündigung
        $modeParams = []; // Parameter für die Ankündigung
        
        for ($i = 0; $i < strlen($modes); $i++) {
            $char = $modes[$i];
            
            if ($char === '+') {
                $adding = true;
                continue;
            }
            
            if ($char === '-') {
                $adding = false;
                continue;
            }
            
            // Modi verwalten
            switch ($char) {
                // Modi ohne Parameter
                case 'n': // No external messages
                case 'm': // Moderated
                case 't': // Topic protection
                case 's': // Secret
                case 'i': // Invite-only
                case 'p': // Private
                    $channel->setMode($char, $adding);
                    $modeChanges[$adding ? '+' : '-'] .= $char;
                    break;
                
                // Modi mit Parameter (nur beim Hinzufügen)
                case 'k': // Key
                    if ($adding) {
                        if (isset($args[$modeIndex])) {
                            $key = $args[$modeIndex++];
                            $channel->setMode($char, true, $key);
                            $modeChanges['+'] .= $char;
                            $modeParams[] = $key;
                        }
                    } else {
                        $channel->setMode($char, false);
                        $modeChanges['-'] .= $char;
                    }
                    break;
                
                case 'l': // Limit
                    if ($adding) {
                        if (isset($args[$modeIndex]) && is_numeric($args[$modeIndex])) {
                            $limit = (int)$args[$modeIndex++];
                            $channel->setMode($char, true, $limit);
                            $modeChanges['+'] .= $char;
                            $modeParams[] = $limit;
                        }
                    } else {
                        $channel->setMode($char, false);
                        $modeChanges['-'] .= $char;
                    }
                    break;
                
                // Benutzer-Modi
                case 'o': // Operator
                case 'v': // Voice
                    if (isset($args[$modeIndex])) {
                        $targetNick = $args[$modeIndex++];
                        $targetUser = null;
                        
                        // Zielbenutzer suchen
                        foreach ($channel->getUsers() as $channelUser) {
                            if (strtolower($channelUser->getNick()) === strtolower($targetNick)) {
                                $targetUser = $channelUser;
                                break;
                            }
                        }
                        
                        if ($targetUser !== null) {
                            if ($char === 'o') {
                                $channel->setOperator($targetUser, $adding);
                            } else if ($char === 'v') {
                                $channel->setVoiced($targetUser, $adding);
                            }
                            
                            $modeChanges[$adding ? '+' : '-'] .= $char;
                            $modeParams[] = $targetNick;
                        }
                    }
                    break;
                
                default:
                    // Unbekannter Modus, ignorieren
                    break;
            }
        }
        
        // Modus-Änderung an alle Benutzer im Kanal senden
        $modeString = '';
        if (!empty($modeChanges['+'])) {
            $modeString .= '+' . $modeChanges['+'];
        }
        if (!empty($modeChanges['-'])) {
            $modeString .= '-' . $modeChanges['-'];
        }
        
        if (!empty($modeString)) {
            $paramString = !empty($modeParams) ? ' ' . implode(' ', $modeParams) : '';
            $modeMessage = ":{$nick}!{$user->getIdent()}@{$user->getCloak()} MODE {$channelName} {$modeString}{$paramString}";
            
            foreach ($channel->getUsers() as $channelUser) {
                $channelUser->send($modeMessage);
            }
        }
    }
}