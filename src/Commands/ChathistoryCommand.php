<?php

namespace PhpIrcd\Commands;

use PhpIrcd\Models\User;
use PhpIrcd\Utils\IRCv3Helper;

/**
 * Implementierung des IRCv3 CHATHISTORY-Befehls
 * Ermöglicht es Clients, vergangene Nachrichten aus Kanälen abzurufen
 * 
 * Syntax: CHATHISTORY LATEST <target> <limit>
 */
class ChathistoryCommand extends CommandBase {
    /**
     * Führt den CHATHISTORY-Befehl aus
     * 
     * @param User $user Der ausführende Benutzer
     * @param array $args Die Befehlsargumente
     */
    public function execute(User $user, array $args): void {
        // Überprüfen, ob der Benutzer registriert ist
        if (!$this->ensureRegistered($user)) {
            return;
        }
        
        // Überprüfen, ob der Benutzer die chathistory-Capability hat
        if (!$user->hasCapability('chathistory')) {
            $this->sendError($user, 'CHATHISTORY', 'CHATHISTORY capability not enabled', 410);
            return;
        }
        
        // Überprüfen, ob genügend Parameter angegeben wurden
        if (!isset($args[3])) {
            $this->sendError($user, 'CHATHISTORY', 'Not enough parameters', 461);
            return;
        }
        
        $subcommand = strtoupper($args[1]);
        $target = $args[2];
        $limit = isset($args[3]) ? (int)$args[3] : 50;
        
        // Maximales Limit festlegen
        $limit = min($limit, 100);
        
        // Überprüfen, ob der Subcommand unterstützt wird
        if ($subcommand !== 'LATEST') {
            $this->sendError($user, 'CHATHISTORY', 'Unsupported subcommand', 421);
            return;
        }
        
        // Überprüfen, ob das Ziel ein Kanal ist
        if ($target[0] !== '#') {
            $this->sendError($user, 'CHATHISTORY', 'Only channel history is supported', 403);
            return;
        }
        
        // Nach Kanal suchen
        $channel = $this->server->getChannel($target);
        
        // Wenn Kanal nicht gefunden, Fehler senden
        if ($channel === null) {
            $config = $this->server->getConfig();
            $nick = $user->getNick();
            $user->send(":{$config['name']} 403 {$nick} {$target} :No such channel");
            return;
        }
        
        // Überprüfen, ob der Benutzer im Kanal ist
        if (!$channel->hasUser($user)) {
            $config = $this->server->getConfig();
            $nick = $user->getNick();
            $user->send(":{$config['name']} 442 {$nick} {$target} :You're not on that channel");
            return;
        }
        
        // Nachrichtenhistorie abrufen
        $history = $channel->getMessageHistory($limit);
        
        // Batch starten, wenn der Benutzer die batch-Capability hat
        $batchId = '';
        if ($user->hasCapability('batch')) {
            $batchId = IRCv3Helper::startBatch($user, 'chathistory', ['target' => $target]);
            if ($batchId) {
                $tags = ['batch' => $batchId];
                $batchCommand = "BATCH +{$batchId} chathistory {$target}";
                $taggedBatchCommand = IRCv3Helper::addMessageTags($batchCommand, $tags);
                $user->send($taggedBatchCommand);
            }
        }
        
        // Nachrichten senden
        foreach ($history as $entry) {
            $message = $entry['message'];
            
            // Batch-Tag hinzufügen, wenn Batch aktiv ist
            if ($batchId) {
                $message = IRCv3Helper::addMessageTags($message, ['batch' => $batchId]);
            }
            
            // Server-Time-Tag hinzufügen, wenn der Benutzer die Capability hat
            if ($user->hasCapability('server-time')) {
                $message = IRCv3Helper::addMessageTags($message, ['time' => IRCv3Helper::formatServerTime($entry['timestamp'])]);
            }
            
            $user->send($message);
        }
        
        // Batch beenden, wenn es gestartet wurde
        if ($batchId) {
            $endBatchCommand = "BATCH -{$batchId}";
            $user->send($endBatchCommand);
            IRCv3Helper::endBatch($user, $batchId);
        }
        
        // Bestätigung senden
        $config = $this->server->getConfig();
        $nick = $user->getNick();
        $count = count($history);
        $user->send(":{$config['name']} 900 {$nick} {$target} :CHATHISTORY LATEST {$count} items");
    }
}