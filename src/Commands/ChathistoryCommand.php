<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;
use PhpIrcd\Models\Channel;

class ChathistoryCommand extends CommandBase {
    /**
     * Führt den CHATHISTORY-Befehl aus
     * 
     * @param User $user Der ausführende Benutzer
     * @param array $args Die Befehlsargumente
     */
    public function execute(User $user, array $args): void {
        // Sicherstellen, dass der Benutzer registriert ist
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        // Überprüfen, ob ChatHistory aktiviert ist
        $config = $this->server->getConfig();
        if (!isset($config['ircv3_features']) || 
            !isset($config['ircv3_features']['chathistory']) || 
            !$config['ircv3_features']['chathistory']) {
            $this->sendError($user, 'CHATHISTORY', 'CHATHISTORY capability not enabled', 410);
            return;
        }
        
        // Überprüfen, ob der Benutzer die ChatHistory-Capability aktiviert hat
        if (!$user->hasCapability('chathistory')) {
            $this->sendError($user, 'CHATHISTORY', 'CHATHISTORY capability not enabled', 410);
            return;
        }
        
        // Überprüfen, ob genügend Parameter angegeben wurden
        if (count($args) < 4) {
            $this->sendError($user, 'CHATHISTORY', 'Not enough parameters', 461);
            return;
        }
        
        $subcommand = strtoupper($args[1]);
        $target = $args[2];
        $limit = isset($args[3]) ? (int)$args[3] : 50;
        
        // Maximales Limit festlegen
        $maxLimit = $config['chathistory_max_messages'] ?? 100;
        $limit = min($limit, $maxLimit);
        
        // Prüfen auf unterstützte Subcommands
        switch ($subcommand) {
            case 'LATEST':
                $this->handleLatest($user, $target, $limit);
                break;
                
            case 'BEFORE':
                if (count($args) < 5) {
                    $this->sendError($user, 'CHATHISTORY', 'Not enough parameters for BEFORE', 461);
                    return;
                }
                $timestamp = strtotime($args[4]);
                if ($timestamp === false) {
                    $this->sendError($user, 'CHATHISTORY', 'Invalid timestamp format', 461);
                    return;
                }
                $this->handleBefore($user, $target, $limit, $timestamp);
                break;
                
            case 'AFTER':
                if (count($args) < 5) {
                    $this->sendError($user, 'CHATHISTORY', 'Not enough parameters for AFTER', 461);
                    return;
                }
                $timestamp = strtotime($args[4]);
                if ($timestamp === false) {
                    $this->sendError($user, 'CHATHISTORY', 'Invalid timestamp format', 461);
                    return;
                }
                $this->handleAfter($user, $target, $limit, $timestamp);
                break;
                
            default:
                $this->sendError($user, 'CHATHISTORY', 'Unsupported subcommand', 421);
                return;
        }
    }
    
    /**
     * Verarbeitet den CHATHISTORY LATEST Befehl
     * 
     * @param User $user Der ausführende Benutzer
     * @param string $target Das Ziel (Kanal oder Benutzer)
     * @param int $limit Das Limit der zurückzugebenden Nachrichten
     */
    private function handleLatest(User $user, string $target, int $limit): void {
        // Überprüfen, ob das Ziel ein Kanal ist
        if ($target[0] === '#' || $target[0] === '&') {
            $channel = $this->server->getChannel($target);
            if ($channel === null) {
                $this->sendError($user, 'CHATHISTORY', 'No such channel', 403);
                return;
            }
            
            // Prüfen, ob der Benutzer im Kanal ist
            if (!$channel->hasUser($user)) {
                $this->sendError($user, 'CHATHISTORY', 'You are not on that channel', 442);
                return;
            }
            
            // Kanalhistorie abrufen
            $this->sendChannelHistory($user, $channel, $limit);
        } else {
            // PM-Historie wird noch nicht unterstützt
            $this->sendError($user, 'CHATHISTORY', 'Private message history not yet implemented', 421);
        }
    }
    
    /**
     * Verarbeitet den CHATHISTORY BEFORE Befehl
     * 
     * @param User $user Der ausführende Benutzer
     * @param string $target Das Ziel (Kanal oder Benutzer)
     * @param int $limit Das Limit der zurückzugebenden Nachrichten
     * @param int $timestamp Der Zeitstempel, vor dem Nachrichten abgerufen werden sollen
     */
    private function handleBefore(User $user, string $target, int $limit, int $timestamp): void {
        // Überprüfen, ob das Ziel ein Kanal ist
        if ($target[0] === '#' || $target[0] === '&') {
            $channel = $this->server->getChannel($target);
            if ($channel === null) {
                $this->sendError($user, 'CHATHISTORY', 'No such channel', 403);
                return;
            }
            
            // Prüfen, ob der Benutzer im Kanal ist
            if (!$channel->hasUser($user)) {
                $this->sendError($user, 'CHATHISTORY', 'You are not on that channel', 442);
                return;
            }
            
            // Kanalhistorie vor dem Zeitstempel abrufen
            $history = $channel->getMessageHistory($limit * 2); // Mehr abrufen, um zu filtern
            $filteredHistory = array_filter($history, function($msg) use ($timestamp) {
                return $msg['timestamp'] < $timestamp;
            });
            
            // Begrenzen und sortieren
            $limitedHistory = array_slice($filteredHistory, -$limit);
            
            // History senden
            $this->sendMessages($user, $limitedHistory);
        } else {
            // PM-Historie wird noch nicht unterstützt
            $this->sendError($user, 'CHATHISTORY', 'Private message history not yet implemented', 421);
        }
    }
    
    /**
     * Verarbeitet den CHATHISTORY AFTER Befehl
     * 
     * @param User $user Der ausführende Benutzer
     * @param string $target Das Ziel (Kanal oder Benutzer)
     * @param int $limit Das Limit der zurückzugebenden Nachrichten
     * @param int $timestamp Der Zeitstempel, nach dem Nachrichten abgerufen werden sollen
     */
    private function handleAfter(User $user, string $target, int $limit, int $timestamp): void {
        // Überprüfen, ob das Ziel ein Kanal ist
        if ($target[0] === '#' || $target[0] === '&') {
            $channel = $this->server->getChannel($target);
            if ($channel === null) {
                $this->sendError($user, 'CHATHISTORY', 'No such channel', 403);
                return;
            }
            
            // Prüfen, ob der Benutzer im Kanal ist
            if (!$channel->hasUser($user)) {
                $this->sendError($user, 'CHATHISTORY', 'You are not on that channel', 442);
                return;
            }
            
            // Kanalhistorie nach dem Zeitstempel abrufen
            $history = $channel->getMessageHistory($limit * 2); // Mehr abrufen, um zu filtern
            $filteredHistory = array_filter($history, function($msg) use ($timestamp) {
                return $msg['timestamp'] > $timestamp;
            });
            
            // Begrenzen und sortieren
            $limitedHistory = array_slice($filteredHistory, 0, $limit);
            
            // History senden
            $this->sendMessages($user, $limitedHistory);
        } else {
            // PM-Historie wird noch nicht unterstützt
            $this->sendError($user, 'CHATHISTORY', 'Private message history not yet implemented', 421);
        }
    }
    
    /**
     * Sendet die Kanalhistorie an einen Benutzer
     * 
     * @param User $user Der Benutzer, der die Historie angefordert hat
     * @param Channel $channel Der Kanal, dessen Historie angefordert wurde
     * @param int $limit Die maximale Anzahl an Nachrichten
     */
    private function sendChannelHistory(User $user, Channel $channel, int $limit): void {
        // Kanalhistorie abrufen
        $history = $channel->getMessageHistory($limit);
        
        // Historische Nachrichten an den Benutzer senden
        $this->sendMessages($user, $history);
    }
    
    /**
     * Sendet eine Liste von Nachrichten an einen Benutzer
     * 
     * @param User $user Der Benutzer
     * @param array $messages Die zu sendenden Nachrichten
     */
    private function sendMessages(User $user, array $messages): void {
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        
        // Wenn die Nachrichten in einer Gruppe gesendet werden sollen, verwende BATCH
        $useBatch = $user->hasCapability('batch');
        $batchId = '';
        
        if ($useBatch) {
            $batchId = 'chathistory-' . uniqid();
            $user->send(":{$config['name']} BATCH +{$batchId} chathistory");
        }
        
        // Nachrichten senden
        foreach ($messages as $msg) {
            $message = $msg['message'];
            
            // Wenn der Benutzer server-time unterstützt, füge Timestamp hinzu
            if ($user->hasCapability('server-time')) {
                $timestamp = gmdate('Y-m-d\TH:i:s.000\Z', $msg['timestamp']);
                $message = "@time={$timestamp} " . $message;
            }
            
            // Wenn BATCH aktiv ist, Batch-Tag hinzufügen
            if ($useBatch) {
                $message = "@batch={$batchId} " . $message;
            }
            
            $user->send($message);
        }
        
        // Batch beenden, falls verwendet
        if ($useBatch) {
            $user->send(":{$config['name']} BATCH -{$batchId}");
        }
        
        // End of history marker
        $user->send(":{$config['name']} 718 {$nick} :End of CHATHISTORY");
    }
}